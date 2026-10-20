<?php
declare(strict_types=1);

namespace App;

/**
 * WebSocket-сервер (RFC 6455) на чистом PHP: stream_select + неблокирующие сокеты, без зависимостей.
 *
 * Как это работает:
 *  1. Браузер получает тикет (api/chat.php?action=ticket) и подключается: ws://host/ws?ticket=…
 *  2. Сервер проверяет подпись тикета и Origin, привязывает соединение к пользователю.
 *  3. PHP-приложение после записи сообщения в БД делает pg_notify('chat_events', …);
 *     сервер слушает канал (LISTEN) и рассылает события нужным пользователям:
 *       message — новое сообщение (+ строка диалога для списка), unread — счётчик непрочитанных,
 *       read    — собеседник прочитал ваши сообщения, typing — собеседник печатает.
 *  Клиент → сервер приходят {"t":"ping"}, {"t":"typing","c":<id диалога>} и {"t":"call",…} (сигнализация звонков, см. CallManager);
 *  отправка сообщений — обычным HTTP.
 */
final class WebSocketServer
{
    private const GUID            = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    private const MAX_FRAME       = 65536;
    private const MAX_HEADER      = 8192;
    private const MAX_CLIENTS     = 2000;
    private const MAX_PER_USER    = 10;
    private const MAX_WBUF        = 1048576;
    private const HANDSHAKE_SECS  = 10;
    private const PING_EVERY      = 25;
    private const IDLE_TIMEOUT    = 75;

    /** @var resource */
    private $server;
    /** @var array<int,array<string,mixed>> */
    private array $clients = [];
    /** @var array<int,array<int,true>> userId → [clientId => true] */
    private array $byUser = [];
    /** @var string[] */
    private array $allowedOrigins;
    private float $lastHousekeeping = 0;
    private float $lastDbCheck = 0;
    private bool $listening = false;
    /** @var array<int,array{0:array,1:int}> кэш участников диалога: convId → [строка, время] */
    private array $convCache = [];
    private CallManager $calls;

    public function __construct(private string $host, private int $port)
    {
        $this->calls = new CallManager(
            fn(int $cid, array $m) => $this->sendTo($cid, $m),
            fn(int $uid, array $m) => $this->sendToUser($uid, $m),
            fn(int $uid): array => array_keys($this->byUser[$uid] ?? []),
            fn(string $m) => $this->log($m),
        );
        $origins = (string) getenv('WS_ALLOWED_ORIGINS');
        if ($origins === '' && (string) getenv('APP_URL') !== '') {
            $origins = (string) getenv('APP_URL');
        }
        $this->allowedOrigins = array_values(array_filter(array_map(
            static fn(string $o) => rtrim(trim($o), '/'),
            explode(',', $origins)
        )));
    }

    public function run(): never
    {
        $this->server = @stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $errstr);
        if (!$this->server) {
            fwrite(STDERR, "[ws] Не удалось открыть порт {$this->port}: $errstr\n");
            exit(1);
        }
        stream_set_blocking($this->server, false);
        $this->log("слушаю {$this->host}:{$this->port}" . ($this->allowedOrigins ? ' (Origin: ' . implode(', ', $this->allowedOrigins) . ')' : ' (Origin не ограничен)'));

        while (true) {
            $read = [$this->server];
            $write = [];
            foreach ($this->clients as $c) {
                $read[] = $c['sock'];
                if ($c['wbuf'] !== '') {
                    $write[] = $c['sock'];
                }
            }
            $except = null;
            $n = @stream_select($read, $write, $except, 0, 100_000);
            if ($n === false) {
                usleep(20_000);
                continue;
            }
            foreach ($read as $sock) {
                if ($sock === $this->server) {
                    $this->accept();
                } else {
                    $this->onReadable($sock);
                }
            }
            foreach ($write as $sock) {
                $id = (int) $sock;
                if (isset($this->clients[$id])) {
                    $this->flush($id);
                }
            }
            $this->pollDatabase();
            $this->housekeeping();
        }
    }

    private function log(string $msg): void
    {
        fwrite(STDERR, '[ws ' . date('H:i:s') . '] ' . $msg . "\n");
    }

    // --------------------------------------------------------------- соединения

    private function accept(): void
    {
        $conn = @stream_socket_accept($this->server, 0);
        if (!$conn) {
            return;
        }
        if (count($this->clients) >= self::MAX_CLIENTS) {
            fclose($conn);
            return;
        }
        stream_set_blocking($conn, false);
        $id = (int) $conn;
        $this->clients[$id] = [
            'sock' => $conn, 'id' => $id, 'rbuf' => '', 'wbuf' => '', 'ws' => false, 'user' => null,
            'opened' => time(), 'last' => time(), 'pinged' => time(), 'frag' => null,
            'msgs' => [], 'sigs' => [], 'typingAt' => 0,
        ];
    }

    private function close(int $id, int $code = 1000, bool $sendFrame = true): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $c = $this->clients[$id];
        if ($sendFrame && $c['ws']) {
            @fwrite($c['sock'], self::frame(pack('n', $code), 0x8));
        }
        if ($c['user'] !== null) {
            unset($this->byUser[$c['user']][$id]);
            if (empty($this->byUser[$c['user']])) {
                unset($this->byUser[$c['user']]);
            }
        }
        @fclose($c['sock']);
        unset($this->clients[$id]);
        if ($c['user'] !== null) {
            $this->calls->onClose($id, (int) $c['user']);
        }
    }

    private function onReadable($sock): void
    {
        $id = (int) $sock;
        if (!isset($this->clients[$id])) {
            return;
        }
        $data = @fread($sock, 65536);
        if ($data === false || ($data === '' && feof($sock))) {
            $this->close($id, 1001, false);
            return;
        }
        if ($data === '') {
            return;
        }
        $this->clients[$id]['rbuf'] .= $data;
        $this->clients[$id]['last'] = time();

        if (!$this->clients[$id]['ws']) {
            $this->handshake($id);
        }
        if (isset($this->clients[$id]) && $this->clients[$id]['ws']) {
            $this->parseFrames($id);
        }
    }

    // -------------------------------------------------------------- рукопожатие

    private function reject(int $id, string $status, string $body = ''): void
    {
        $resp = "HTTP/1.1 $status\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n$body";
        @fwrite($this->clients[$id]['sock'], $resp);
        $this->close($id, 1000, false);
    }

    private function originAllowed(string $origin): bool
    {
        if ($this->allowedOrigins === [] || $origin === '') {
            return true;
        }
        return in_array(rtrim($origin, '/'), $this->allowedOrigins, true);
    }

    private function handshake(int $id): void
    {
        $buf = $this->clients[$id]['rbuf'];
        $end = strpos($buf, "\r\n\r\n");
        if ($end === false) {
            if (strlen($buf) > self::MAX_HEADER) {
                $this->reject($id, '431 Request Header Fields Too Large');
            }
            return;
        }
        $head = substr($buf, 0, $end);
        $this->clients[$id]['rbuf'] = substr($buf, $end + 4);

        $lines = explode("\r\n", $head);
        $request = array_shift($lines);
        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        if (!preg_match('#^GET (\S+) HTTP/1\.[01]$#', (string) $request, $m)) {
            $this->reject($id, '400 Bad Request');
            return;
        }
        $path  = (string) parse_url($m[1], PHP_URL_PATH);
        parse_str((string) parse_url($m[1], PHP_URL_QUERY), $query);

        // Обычный HTTP: проверка живости для docker healthcheck
        if (strtolower($headers['upgrade'] ?? '') !== 'websocket') {
            if ($path === '/health') {
                $this->reject($id, '200 OK', 'ok');
            } else {
                $this->reject($id, '426 Upgrade Required', 'WebSocket endpoint');
            }
            return;
        }
        if (!in_array($path, ['/', '/ws'], true) || ($headers['sec-websocket-version'] ?? '') !== '13' || empty($headers['sec-websocket-key'])) {
            $this->reject($id, '400 Bad Request');
            return;
        }
        if (!$this->originAllowed($headers['origin'] ?? '')) {
            $this->log('отклонён Origin: ' . ($headers['origin'] ?? ''));
            $this->reject($id, '403 Forbidden', 'Origin not allowed');
            return;
        }
        $uid = Realtime::verifyTicket((string) ($query['ticket'] ?? ''));
        if ($uid === null) {
            $this->reject($id, '401 Unauthorized', 'Invalid or expired ticket');
            return;
        }
        if (count($this->byUser[$uid] ?? []) >= self::MAX_PER_USER) {
            $this->reject($id, '429 Too Many Requests', 'Too many connections');
            return;
        }

        $accept = base64_encode(sha1($headers['sec-websocket-key'] . self::GUID, true));
        $this->clients[$id]['wbuf'] .= "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n";
        $this->clients[$id]['ws'] = true;
        $this->clients[$id]['user'] = $uid;
        $this->byUser[$uid][$id] = true;
        $this->flush($id);
        if (isset($this->clients[$id])) {
            try {
                $this->sendTo($id, ['t' => 'hello', 'unread' => Chat::unreadTotal($uid)]);
            } catch (\Throwable $e) {
                $this->log('hello: ' . $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------- фреймы

    private static function frame(string $payload, int $opcode = 0x1): string
    {
        $len = strlen($payload);
        $head = chr(0x80 | $opcode);
        if ($len < 126) {
            $head .= chr($len);
        } elseif ($len < 65536) {
            $head .= chr(126) . pack('n', $len);
        } else {
            $head .= chr(127) . pack('J', $len);
        }
        return $head . $payload;
    }

    private function parseFrames(int $id): void
    {
        while (isset($this->clients[$id])) {
            $buf = $this->clients[$id]['rbuf'];
            $len = strlen($buf);
            if ($len < 2) {
                return;
            }
            $b0 = ord($buf[0]);
            $b1 = ord($buf[1]);
            $fin    = ($b0 & 0x80) !== 0;
            $op     = $b0 & 0x0F;
            $masked = ($b1 & 0x80) !== 0;
            $plen   = $b1 & 0x7F;
            $off    = 2;
            if ($plen === 126) {
                if ($len < 4) {
                    return;
                }
                $plen = unpack('n', substr($buf, 2, 2))[1];
                $off = 4;
            } elseif ($plen === 127) {
                if ($len < 10) {
                    return;
                }
                $hi = unpack('N', substr($buf, 2, 4))[1];
                $lo = unpack('N', substr($buf, 6, 4))[1];
                if ($hi !== 0) {
                    $this->close($id, 1009);
                    return;
                }
                $plen = $lo;
                $off = 10;
            }
            if (!$masked) {                         // клиент обязан маскировать кадры
                $this->close($id, 1002);
                return;
            }
            if ($plen > self::MAX_FRAME || ($op >= 0x8 && ($plen > 125 || !$fin))) {
                $this->close($id, 1009);
                return;
            }
            if ($len < $off + 4 + $plen) {
                return;                              // кадр пришёл не целиком
            }
            $mask    = substr($buf, $off, 4);
            $payload = substr($buf, $off + 4, $plen);
            $this->clients[$id]['rbuf'] = substr($buf, $off + 4 + $plen);
            if ($plen > 0) {
                $payload ^= substr(str_repeat($mask, intdiv($plen, 4) + 1), 0, $plen);
            }

            switch ($op) {
                case 0x8:                            // close
                    $this->close($id, 1000);
                    return;
                case 0x9:                            // ping → pong
                    $this->clients[$id]['wbuf'] .= self::frame($payload, 0xA);
                    $this->flush($id);
                    break;
                case 0xA:                            // pong
                    break;
                case 0x0:                            // continuation
                case 0x1:                            // text
                case 0x2:                            // binary
                    $frag = $this->clients[$id]['frag'];
                    if ($op !== 0x0) {
                        $frag = ['op' => $op, 'data' => ''];
                    } elseif ($frag === null) {
                        $this->close($id, 1002);
                        return;
                    }
                    $frag['data'] .= $payload;
                    if (strlen($frag['data']) > self::MAX_FRAME) {
                        $this->close($id, 1009);
                        return;
                    }
                    if ($fin) {
                        $this->clients[$id]['frag'] = null;
                        if ($frag['op'] === 0x1) {
                            $this->onMessage($id, $frag['data']);
                        }
                    } else {
                        $this->clients[$id]['frag'] = $frag;
                    }
                    break;
                default:
                    $this->close($id, 1002);
                    return;
            }
        }
    }

    private function flush(int $id): void
    {
        $c = &$this->clients[$id];
        if ($c['wbuf'] === '') {
            return;
        }
        $n = @fwrite($c['sock'], $c['wbuf']);
        if ($n === false) {
            unset($c);
            $this->close($id, 1006, false);
            return;
        }
        $c['wbuf'] = (string) substr($c['wbuf'], $n);
        if (strlen($c['wbuf']) > self::MAX_WBUF) {   // медленный клиент не должен съедать память
            unset($c);
            $this->close($id, 1008);
        }
    }

    /** @param array<string,mixed> $data */
    private function sendTo(int $id, array $data): void
    {
        if (!isset($this->clients[$id]) || !$this->clients[$id]['ws']) {
            return;
        }
        $this->clients[$id]['wbuf'] .= self::frame(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->flush($id);
    }

    /** @param array<string,mixed> $data */
    private function sendToUser(int $userId, array $data): void
    {
        foreach (array_keys($this->byUser[$userId] ?? []) as $cid) {
            $this->sendTo($cid, $data);
        }
    }

    // ------------------------------------------------------- сообщения от клиента

    private function onMessage(int $id, string $raw): void
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }
        // Защита от флуда. Обычные сообщения: не больше 30 за 10 секунд на соединение;
        // служебные данные звонка (ICE-кандидатов бывает много) считаются отдельно и лимит у них выше.
        $now = time();
        $bucket = ($data['t'] ?? '') === 'call' && ($data['k'] ?? '') === 'sig' ? 'sigs' : 'msgs';
        $limit = $bucket === 'sigs' ? 200 : 30;
        $list = array_filter($this->clients[$id][$bucket], static fn(int $t) => $t > $now - 10);
        $list[] = $now;
        $this->clients[$id][$bucket] = $list;
        if (count($list) > $limit) {
            $this->close($id, 1008);
            return;
        }

        switch ($data['t'] ?? '') {
            case 'ping':
                $this->sendTo($id, ['t' => 'pong']);
                break;
            case 'call':
                $this->calls->handle($id, (int) $this->clients[$id]['user'], $data);
                break;
            case 'typing':
                $conv = (int) ($data['c'] ?? 0);
                $me = (int) $this->clients[$id]['user'];
                if ($conv < 1 || microtime(true) - $this->clients[$id]['typingAt'] < 1.5) {
                    return;
                }
                $this->clients[$id]['typingAt'] = microtime(true);
                try {
                    $row = $this->conversation($conv);
                    if ($row && in_array($me, [(int) $row['buyer_id'], (int) $row['seller_id']], true)) {
                        $peer = (int) $row['buyer_id'] === $me ? (int) $row['seller_id'] : (int) $row['buyer_id'];
                        $this->sendToUser($peer, ['t' => 'typing', 'c' => $conv, 'u' => $me]);
                    }
                } catch (\Throwable $e) {
                    $this->log('typing: ' . $e->getMessage());
                }
                break;
        }
    }

    private function conversation(int $convId): ?array
    {
        $hit = $this->convCache[$convId] ?? null;
        if ($hit !== null && time() - $hit[1] < 60) {
            return $hit[0];
        }
        $row = Chat::participants($convId);
        if (count($this->convCache) > 5000) {
            $this->convCache = [];
        }
        $this->convCache[$convId] = [$row ?? [], time()];
        return $row;
    }

    // --------------------------------------------------- события из PostgreSQL

    private function listen(): void
    {
        Database::pdo()->exec('LISTEN ' . Realtime::CHANNEL);
        $this->listening = true;
    }

    private function pollDatabase(): void
    {
        try {
            if (!$this->listening) {
                $this->listen();
                $this->log('подписан на канал ' . Realtime::CHANNEL);
            }
            $pdo = Database::pdo();
            $guard = 0;
            while ($guard++ < 200 && ($note = $pdo->pgsqlGetNotify(\PDO::FETCH_ASSOC, 0)) !== false) {
                $this->onNotify((string) ($note['payload'] ?? ''));
            }
            // Раз в 30 секунд проверяем соединение с БД — после рестарта PostgreSQL подключимся заново
            if (microtime(true) - $this->lastDbCheck > 30) {
                $this->lastDbCheck = microtime(true);
                Database::value('SELECT 1');
            }
        } catch (\Throwable $e) {
            $this->log('БД недоступна, переподключаюсь: ' . $e->getMessage());
            Database::reset();
            $this->listening = false;
            usleep(500_000);
        }
    }

    private function onNotify(string $payload): void
    {
        $e = json_decode($payload, true);
        if (!is_array($e)) {
            return;
        }
        try {
            switch ($e['type'] ?? '') {
                case 'message':
                    $conv = (int) $e['conv'];
                    $msg  = Chat::messageById((int) $e['msg']);
                    if ($msg === null) {
                        return;
                    }
                    foreach ([(int) $e['from'], (int) $e['to']] as $uid) {
                        if (empty($this->byUser[$uid])) {
                            continue;
                        }
                        $this->sendToUser($uid, ['t' => 'message', 'conversation' => Chat::summary($conv, $uid), 'message' => $msg]);
                        if ($uid === (int) $e['to']) {
                            $this->sendToUser($uid, ['t' => 'unread', 'count' => Chat::unreadTotal($uid)]);
                        }
                    }
                    break;
                case 'kick':
                    // Пользователя заблокировали: рвём звонок и закрываем его соединения с чатом
                    $uid = (int) $e['user'];
                    $this->calls->kick($uid);
                    foreach (array_keys($this->byUser[$uid] ?? []) as $cid) {
                        $this->close($cid, 1008);
                    }
                    break;
                case 'read':
                    $reader = (int) $e['reader'];
                    $peer   = (int) $e['peer'];
                    $this->sendToUser($peer, ['t' => 'read', 'c' => (int) $e['conv'], 'upto' => (int) $e['upto']]);
                    if (!empty($this->byUser[$reader])) {
                        $this->sendToUser($reader, ['t' => 'unread', 'count' => Chat::unreadTotal($reader)]);
                        $this->sendToUser($reader, ['t' => 'read_self', 'c' => (int) $e['conv']]);
                    }
                    break;
            }
        } catch (\Throwable $ex) {
            $this->log('событие ' . ($e['type'] ?? '?') . ': ' . $ex->getMessage());
        }
    }

    // ------------------------------------------------------------------ служебное

    private function housekeeping(): void
    {
        $now = microtime(true);
        if ($now - $this->lastHousekeeping < 1) {
            return;
        }
        $this->lastHousekeeping = $now;
        $this->calls->tick();
        $t = time();
        foreach ($this->clients as $id => $c) {
            if (!$c['ws']) {
                if ($t - $c['opened'] > self::HANDSHAKE_SECS) {
                    $this->close($id, 1008, false);
                }
                continue;
            }
            if ($t - $c['last'] > self::IDLE_TIMEOUT) {
                $this->close($id, 1001);
                continue;
            }
            if ($t - $c['pinged'] >= self::PING_EVERY) {
                $this->clients[$id]['pinged'] = $t;
                $this->clients[$id]['wbuf'] .= self::frame('', 0x9);
                $this->flush($id);
            }
        }
    }
}

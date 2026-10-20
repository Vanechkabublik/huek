<?php
declare(strict_types=1);

namespace App;

/**
 * Сигнализация звонков внутри WebSocket-сервера. Сам разговор (аудио/видео) сервер не видит:
 * он только знакомит двух браузеров — передаёт «звоню», «беру трубку», «отбой» и служебные данные WebRTC
 * (SDP-описания и ICE-кандидаты), следит за таймаутами и в конце записывает итог в переписку.
 *
 * Сообщения клиент → сервер ({"t":"call","k":…}):
 *   start {c|ad, v}   позвонить (c — id диалога или ad — id объявления; v — видео)
 *   accept {id}       взять трубку        decline {id}   отклонить        end {id}   положить трубку / отменить вызов
 *   sig {id, d}       служебные данные WebRTC собеседнику (offer/answer/cand)
 *   media {id, mic, cam}  микрофон/камера включены или выключены
 *   resume {id}       после обрыва WebSocket снова привязать эту вкладку к идущему звонку
 *   sync              страница только что открылась: подтянуть входящий вызов или закрыть «осиротевший» звонок
 *   failed {id, ok}   не удалось установить (ok=1 — потеряли) соединение
 * Сообщения сервер → клиент ({"t":"call","k":…}): ringing, incoming, accepted, sig, media, peer, taken, resumed, denied, ended.
 *
 * Состояние хранится в памяти процесса: звонки короткие, а при перезапуске WS-сервера браузеры сами завершат их по таймауту.
 */
final class CallManager
{
    private const GRACE_RING     = 12;    // секунд без связи у звонящего — вызов отменяется
    private const GRACE_ACTIVE   = 25;    // секунд без WebSocket у участника разговора — разговор завершается
    private const QUEUE_MAX      = 80;
    private const STARTS_PER_MIN = 6;

    /** @var array<string,array<string,mixed>> */
    private array $calls = [];
    /** @var array<int,string> userId → id звонка (звонит или разговаривает) */
    private array $byUser = [];
    /** @var array<int,float[]> */
    private array $starts = [];

    /**
     * @param \Closure(int,array):void $sendClient  отправить сообщение одному соединению
     * @param \Closure(int,array):void $sendUser    отправить всем соединениям пользователя
     * @param \Closure(int):int[]      $clientsOf   id соединений пользователя
     * @param \Closure(string):void    $log
     */
    public function __construct(
        private \Closure $sendClient,
        private \Closure $sendUser,
        private \Closure $clientsOf,
        private \Closure $log,
    ) {
    }

    public function activeCount(): int
    {
        return count($this->calls);
    }

    // ------------------------------------------------------------------ вход

    /** @param array<string,mixed> $d */
    public function handle(int $cid, int $uid, array $d): void
    {
        $k = (string) ($d['k'] ?? '');
        try {
            switch ($k) {
                case 'start':
                    $this->start($cid, $uid, $d);
                    break;
                case 'accept':
                    $this->accept($cid, $uid, (string) ($d['id'] ?? ''));
                    break;
                case 'decline':
                    if (($c = $this->mine($uid, (string) ($d['id'] ?? ''))) && $c['callee'] === $uid && $c['state'] === 'ringing') {
                        $this->finish($c['id'], 'declined');
                    }
                    break;
                case 'end':
                    $this->hangup($uid, (string) ($d['id'] ?? ''));
                    break;
                case 'sig':
                    $this->signal($uid, (string) ($d['id'] ?? ''), $d['d'] ?? null);
                    break;
                case 'media':
                    if (($c = $this->mine($uid, (string) ($d['id'] ?? ''))) && $c['state'] === 'active') {
                        $this->relay($c['id'], $this->other($c, $uid), ['t' => 'call', 'k' => 'media', 'id' => $c['id'], 'mic' => !empty($d['mic']), 'cam' => !empty($d['cam'])]);
                    }
                    break;
                case 'resume':
                    $this->resume($cid, $uid, (string) ($d['id'] ?? ''));
                    break;
                case 'sync':
                    $this->sync($cid, $uid);
                    break;
                case 'failed':
                    if ($c = $this->mine($uid, (string) ($d['id'] ?? ''))) {
                        $this->finish($c['id'], !empty($d['ok']) ? 'ended' : 'failed', 'ice');
                    }
                    break;
            }
        } catch (\Throwable $e) {
            ($this->log)("звонок ($k): " . $e->getMessage());
        }
    }

    /** Соединение закрылось: если оно держало звонок — запускаем отсчёт «связи нет». */
    public function onClose(int $cid, int $uid): void
    {
        $id = $this->byUser[$uid] ?? null;
        if ($id === null || !isset($this->calls[$id])) {
            return;
        }
        $key = $this->side($this->calls[$id], $uid) . 'Client';
        if ($this->calls[$id][$key] === $cid) {
            $this->calls[$id][$key] = null;
            $this->calls[$id]['dropped'][$uid] = microtime(true);
            $this->relay($id, $this->other($this->calls[$id], $uid), ['t' => 'call', 'k' => 'peer', 'id' => $id, 'on' => false]);
        }
    }

    /** Раз в секунду: таймаут вызова и обрывы связи. */
    public function tick(): void
    {
        $now = microtime(true);
        foreach (array_keys($this->calls) as $id) {
            $c = $this->calls[$id] ?? null;
            if ($c === null) {
                continue;
            }
            if ($c['state'] === 'ringing' && $now > $c['ringUntil']) {
                $this->finish($id, 'missed', 'timeout');
                continue;
            }
            $grace = $c['state'] === 'ringing' ? self::GRACE_RING : self::GRACE_ACTIVE;
            foreach ($c['dropped'] as $u => $since) {
                if ($now - $since > $grace) {
                    $this->finish($id, $c['state'] === 'ringing' ? 'cancelled' : 'ended', 'lost');
                    break;
                }
            }
        }
    }

    /** Пользователя заблокировали: звонок обрывается. */
    public function kick(int $uid): void
    {
        $id = $this->byUser[$uid] ?? null;
        if ($id !== null && isset($this->calls[$id])) {
            $this->finish($id, $this->calls[$id]['state'] === 'active' ? 'ended' : 'cancelled', 'banned');
        }
    }

    // ------------------------------------------------------------ действия

    /** @param array<string,mixed> $d */
    private function start(int $cid, int $uid, array $d): void
    {
        $now = microtime(true);
        $this->starts[$uid] = array_values(array_filter($this->starts[$uid] ?? [], static fn(float $t) => $t > $now - 60));
        if (count($this->starts[$uid]) >= self::STARTS_PER_MIN) {
            $this->deny($cid, 'rate', 'Слишком много вызовов подряд. Подождите минуту.');
            return;
        }
        if (isset($this->byUser[$uid])) {
            $this->deny($cid, 'busy_self', 'Вы уже разговариваете или вам звонят.');
            return;
        }
        $video = !empty($d['v']);
        try {
            $ctx = Calls::prepare($uid, (int) ($d['c'] ?? 0), (int) ($d['ad'] ?? 0));
        } catch (HttpException $e) {
            $this->deny($cid, 'forbidden', $e->getMessage());
            return;
        } catch (\Throwable $e) {
            ($this->log)('звонок: ' . $e->getMessage());
            $this->deny($cid, 'error', 'Не удалось начать звонок. Попробуйте ещё раз.');
            return;
        }
        $this->starts[$uid][] = $now;

        $callee = (int) $ctx['peer']['id'];
        $convId = Calls::ensureConversation($ctx['conv']);
        if (isset($this->byUser[$callee])) {
            Calls::log($convId, $uid, 'missed', 0, $video, 'busy');
            $this->deny($cid, 'busy', 'Собеседник сейчас разговаривает. Он увидит пропущенный звонок в чате.');
            return;
        }
        if (!($this->clientsOf)($callee)) {
            Calls::log($convId, $uid, 'missed', 0, $video, 'offline');
            $this->deny($cid, 'offline', 'Собеседник сейчас не в сети. Он увидит пропущенный звонок в чате.');
            return;
        }

        $id = bin2hex(random_bytes(8));
        $ring = Calls::ringSeconds();
        $this->calls[$id] = [
            'id' => $id, 'conv' => $convId, 'video' => $video, 'caller' => $uid, 'callee' => $callee,
            'callerClient' => $cid, 'calleeClient' => null, 'state' => 'ringing',
            'created' => $now, 'answered' => null, 'ringUntil' => $now + $ring,
            'dropped' => [], 'queue' => [],
            'from' => Calls::person($ctx['me']), 'to' => Calls::person($ctx['peer']),
            'ad' => ['id' => (int) $ctx['ad']['id'], 'title' => $ctx['ad']['title']],
        ];
        $this->byUser[$uid] = $this->byUser[$callee] = $id;

        $this->toClient($cid, ['k' => 'ringing', 'id' => $id, 'conv' => $convId, 'v' => $video, 'peer' => $this->calls[$id]['to'], 'ad' => $this->calls[$id]['ad'], 'left' => $ring]);
        ($this->sendUser)($callee, $this->incoming($this->calls[$id], $ring));
    }

    private function accept(int $cid, int $uid, string $id): void
    {
        $c = $this->mine($uid, $id);
        if ($c === null || $c['callee'] !== $uid || $c['state'] !== 'ringing') {
            $this->toClient($cid, ['k' => 'ended', 'id' => $id, 'reason' => 'gone', 'dur' => 0]);
            return;
        }
        $this->calls[$id]['state'] = 'active';
        $this->calls[$id]['answered'] = microtime(true);
        $this->calls[$id]['calleeClient'] = $cid;
        foreach (($this->clientsOf)($uid) as $other) {
            if ($other !== $cid) {
                $this->toClient($other, ['k' => 'taken', 'id' => $id]);
            }
        }
        $this->relay($id, $c['caller'], ['t' => 'call', 'k' => 'accepted', 'id' => $id]);
    }

    private function hangup(int $uid, string $id): void
    {
        $c = $this->mine($uid, $id);
        if ($c === null) {
            return;
        }
        if ($c['state'] === 'ringing') {
            $this->finish($id, $uid === $c['caller'] ? 'cancelled' : 'declined');
        } else {
            $this->finish($id, 'ended');
        }
    }

    private function signal(int $uid, string $id, mixed $raw): void
    {
        $c = $this->mine($uid, $id);
        $d = $this->cleanSignal($raw);
        if ($c === null || $c['state'] !== 'active' || $d === null) {
            return;
        }
        $this->relay($id, $this->other($c, $uid), ['t' => 'call', 'k' => 'sig', 'id' => $id, 'd' => $d]);
    }

    private function resume(int $cid, int $uid, string $id): void
    {
        $c = $this->mine($uid, $id);
        if ($c === null) {
            $this->toClient($cid, ['k' => 'ended', 'id' => $id, 'reason' => 'gone', 'dur' => 0]);
            return;
        }
        $key = $this->side($c, $uid) . 'Client';
        $this->calls[$id][$key] = $cid;
        unset($this->calls[$id]['dropped'][$uid]);
        $this->toClient($cid, ['k' => 'resumed', 'id' => $id, 'state' => $c['state']]);
        foreach ($this->calls[$id]['queue'][$uid] ?? [] as $msg) {
            ($this->sendClient)($cid, $msg);
        }
        $this->calls[$id]['queue'][$uid] = [];
        $this->relay($id, $this->other($c, $uid), ['t' => 'call', 'k' => 'peer', 'id' => $id, 'on' => true]);
    }

    private function sync(int $cid, int $uid): void
    {
        $id = $this->byUser[$uid] ?? null;
        if ($id === null || !isset($this->calls[$id])) {
            return;
        }
        $c = $this->calls[$id];
        if ($c['state'] === 'ringing' && $c['callee'] === $uid) {
            $this->toClient($cid, $this->incoming($c, max(1, (int) ($c['ringUntil'] - microtime(true)))));
            return;
        }
        // Страница открылась заново, а звонок «держала» старая — значит, вкладку закрыли или обновили
        $key = $this->side($c, $uid) . 'Client';
        if ($c[$key] === null || !in_array($c[$key], ($this->clientsOf)($uid), true)) {
            $this->finish($id, $c['state'] === 'ringing' ? 'cancelled' : 'ended', 'lost');
        }
    }

    // ------------------------------------------------------------- служебное

    /** Завершает звонок, оповещает обоих и записывает итог в переписку. */
    private function finish(string $id, string $state, ?string $reason = null): void
    {
        $c = $this->calls[$id] ?? null;
        if ($c === null) {
            return;
        }
        $dur = $c['answered'] !== null ? max(0, (int) round(microtime(true) - $c['answered'])) : 0;
        if ($state === 'ended' && $c['answered'] === null) {
            $state = 'cancelled';
        }
        unset($this->calls[$id], $this->byUser[$c['caller']], $this->byUser[$c['callee']]);
        foreach ([$c['caller'], $c['callee']] as $u) {
            ($this->sendUser)($u, ['t' => 'call', 'k' => 'ended', 'id' => $id, 'reason' => $reason ?? $state, 'state' => $state, 'dur' => $dur]);
        }
        try {
            Calls::log((int) $c['conv'], (int) $c['caller'], $state, $dur, (bool) $c['video'], $reason);
        } catch (\Throwable $e) {
            ($this->log)('запись звонка в чат: ' . $e->getMessage());
        }
    }

    /** @return array<string,mixed>|null */
    private function mine(int $uid, string $id): ?array
    {
        $c = $this->calls[$id] ?? null;
        return $c !== null && ($c['caller'] === $uid || $c['callee'] === $uid) ? $c : null;
    }

    private function side(array $c, int $uid): string
    {
        return $c['caller'] === $uid ? 'caller' : 'callee';
    }

    private function other(array $c, int $uid): int
    {
        return $c['caller'] === $uid ? $c['callee'] : $c['caller'];
    }

    /** Доставка собеседнику: если его вкладка сейчас без связи — сообщение ждёт в очереди до resume. */
    private function relay(string $id, int $toUid, array $msg): void
    {
        $c = $this->calls[$id] ?? null;
        if ($c === null) {
            return;
        }
        $cid = $c[$this->side($c, $toUid) . 'Client'];
        if ($cid !== null && in_array($cid, ($this->clientsOf)($toUid), true)) {
            ($this->sendClient)($cid, $msg);
            return;
        }
        $q = $this->calls[$id]['queue'][$toUid] ?? [];
        $q[] = $msg;
        $this->calls[$id]['queue'][$toUid] = array_slice($q, -self::QUEUE_MAX);
    }

    /** @param array<string,mixed> $msg */
    private function toClient(int $cid, array $msg): void
    {
        ($this->sendClient)($cid, ['t' => 'call'] + $msg);
    }

    private function deny(int $cid, string $reason, string $message): void
    {
        $this->toClient($cid, ['k' => 'denied', 'reason' => $reason, 'message' => $message]);
    }

    /** @param array<string,mixed> $c */
    private function incoming(array $c, int $left): array
    {
        return ['t' => 'call', 'k' => 'incoming', 'id' => $c['id'], 'conv' => $c['conv'], 'v' => $c['video'], 'from' => $c['from'], 'ad' => $c['ad'], 'left' => $left];
    }

    /**
     * Пропускаем только то, что нужно WebRTC, и ограничиваем размеры: клиент не может протащить через сигнализацию произвольные данные.
     * @return array<string,mixed>|null
     */
    private function cleanSignal(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        if (isset($raw['type'])) {
            if (!in_array($raw['type'], ['offer', 'answer'], true) || !is_string($raw['sdp'] ?? null) || strlen($raw['sdp']) > 40000) {
                return null;
            }
            return ['type' => $raw['type'], 'sdp' => $raw['sdp']];
        }
        if (isset($raw['cand']) && is_array($raw['cand'])) {
            $c = $raw['cand'];
            $cand = (string) ($c['candidate'] ?? '');
            if (strlen($cand) > 2000) {
                return null;
            }
            return ['cand' => [
                'candidate'        => $cand,
                'sdpMid'           => isset($c['sdpMid']) ? substr((string) $c['sdpMid'], 0, 64) : null,
                'sdpMLineIndex'    => isset($c['sdpMLineIndex']) ? (int) $c['sdpMLineIndex'] : null,
                'usernameFragment' => isset($c['usernameFragment']) ? substr((string) $c['usernameFragment'], 0, 64) : null,
            ]];
        }
        return null;
    }
}

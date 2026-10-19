<?php
declare(strict_types=1);

namespace App;

/**
 * Загрузка фото: проверка MIME/размера/разрешения, перекодирование через GD
 * (заодно вычищает EXIF и любой «хвост» внутри файла) и миниатюра для карточек.
 *
 * Файлы, загруженные пользователем, запоминаются в его сессии — прикрепить к
 * объявлению можно только то, что он сам загрузил.
 */
final class Upload
{
    public const MAX_BYTES  = 5 * 1024 * 1024;   // 5 МБ
    public const MAX_PIXELS = 25_000_000;        // ~5000×5000: защита от «бомб» с огромным разрешением
    public const MAX_SIDE   = 1600;              // длинная сторона основного фото
    public const THUMB_SIDE = 640;               // длинная сторона миниатюры
    public const AVATAR_SIDE = 256;              // сторона квадратного аватара
    public const MAX_VIDEO_BYTES = 50 * 1024 * 1024;   // видеообзор: до 50 МБ
    public const MAX_VIDEO_SECONDS = 180;              // и до 3 минут (проверяем у MP4/MOV по заголовку файла)

    private const ALLOWED = [
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/png'  => IMAGETYPE_PNG,
        'image/gif'  => IMAGETYPE_GIF,
        'image/webp' => IMAGETYPE_WEBP,
    ];

    /**
     * Обрабатывает один загруженный файл ($_FILES['file']).
     * @return array{filename:string,url:string,thumb:string}
     */
    public static function store(array $file): array
    {
        $src = self::loadValidated($file);
        self::ensureDir();

        $name = bin2hex(random_bytes(12)) . '.jpg';
        $ok = self::saveResized($src, UPLOAD_DIR . '/' . $name, self::MAX_SIDE, 85)
            && self::saveResized($src, UPLOAD_DIR . '/t_' . $name, self::THUMB_SIDE, 80);
        imagedestroy($src);
        if (!$ok) {
            self::remove($name);
            throw new HttpException('Не удалось сохранить файл', 500);
        }

        $_SESSION['pending_uploads'][$name] = time();
        return ['filename' => $name, 'url' => self::url($name), 'thumb' => self::thumbUrl($name)];
    }

    /**
     * Аватар: квадрат 256×256 по центру кадра. Возвращает URL вида /uploads/av_<hex>.jpg.
     */
    public static function storeAvatar(array $file): string
    {
        $src = self::loadValidated($file);
        self::ensureDir();

        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $canvas = imagecreatetruecolor(self::AVATAR_SIDE, self::AVATAR_SIDE);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::AVATAR_SIDE, self::AVATAR_SIDE, $side, $side);
        imagedestroy($src);

        $name = 'av_' . bin2hex(random_bytes(12)) . '.jpg';
        imageinterlace($canvas, true);
        $ok = imagejpeg($canvas, UPLOAD_DIR . '/' . $name, 88);
        imagedestroy($canvas);
        if (!$ok) {
            throw new HttpException('Не удалось сохранить файл', 500);
        }
        @chmod(UPLOAD_DIR . '/' . $name, 0644);
        return self::url($name);
    }

    /**
     * Видеообзор объявления: MP4/MOV (H.264/HEVC) или WebM, до 50 МБ и 3 минут. Файл не перекодируется (на сервере нет ffmpeg),
     * поэтому тип проверяется по сигнатуре содержимого, а не по имени и заголовку от клиента; имя генерирует сервер.
     * @return array{filename:string,url:string}
     */
    public static function storeVideo(array $file): array
    {
        self::assertUploaded($file, 'Видео слишком большое: максимум ' . (self::MAX_VIDEO_BYTES / 1048576) . ' МБ');
        $tmp = (string) $file['tmp_name'];
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_VIDEO_BYTES) {
            throw new HttpException('Видео слишком большое: максимум ' . (self::MAX_VIDEO_BYTES / 1048576) . ' МБ', 422);
        }
        $ext = self::detectVideo($tmp);
        if ($ext === null) {
            throw new HttpException('Допустимы видео MP4, MOV или WebM', 422);
        }
        if ($ext !== 'webm') {
            $seconds = self::mp4Duration($tmp);
            if ($seconds !== null && $seconds > self::MAX_VIDEO_SECONDS) {
                throw new HttpException('Видео длиннее ' . (self::MAX_VIDEO_SECONDS / 60) . ' минут — сократите его', 422);
            }
        }
        self::ensureDir();
        $name = 'v_' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (!@move_uploaded_file($tmp, UPLOAD_DIR . '/' . $name) && !(!is_uploaded_file($tmp) && @rename($tmp, UPLOAD_DIR . '/' . $name))) {
            throw new HttpException('Не удалось сохранить видео', 500);
        }
        @chmod(UPLOAD_DIR . '/' . $name, 0644);
        $_SESSION['pending_uploads'][$name] = time();
        return ['filename' => $name, 'url' => self::url($name)];
    }

    /** Расширение по сигнатуре: mp4 | mov | webm, иначе null. */
    private static function detectVideo(string $path): ?string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $head = (string) fread($fh, 64);
        fclose($fh);
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
            return str_starts_with(substr($head, 8, 4), 'qt') ? 'mov' : 'mp4';
        }
        if (strncmp($head, "\x1A\x45\xDF\xA3", 4) === 0 && str_contains($head, 'webm')) {
            return 'webm';
        }
        return null;
    }

    /** Длительность MP4/MOV в секундах по боксу mvhd (быстро: читаем только заголовки боксов) или null, если не удалось. */
    private static function mp4Duration(string $path): ?float
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            $end = (int) filesize($path);
            $pos = 0;
            for ($i = 0; $i < 64 && $pos + 8 <= $end; $i++) {
                fseek($fh, $pos);
                $h = (string) fread($fh, 16);
                if (strlen($h) < 8) {
                    return null;
                }
                $len = (int) unpack('N', substr($h, 0, 4))[1];
                $type = substr($h, 4, 4);
                $hdr = 8;
                if ($len === 1 && strlen($h) >= 16) {          // 64-битный размер
                    $len = (int) unpack('J', substr($h, 8, 8))[1];
                    $hdr = 16;
                } elseif ($len === 0) {
                    $len = $end - $pos;
                }
                if ($len < $hdr) {
                    return null;
                }
                if ($type === 'moov') {
                    return self::mvhdSeconds($fh, $pos + $hdr, $pos + $len);
                }
                $pos += $len;
            }
        } finally {
            fclose($fh);
        }
        return null;
    }

    private static function mvhdSeconds($fh, int $from, int $to): ?float
    {
        $pos = $from;
        for ($i = 0; $i < 32 && $pos + 8 <= $to; $i++) {
            fseek($fh, $pos);
            $h = (string) fread($fh, 8);
            if (strlen($h) < 8) {
                return null;
            }
            $len = (int) unpack('N', substr($h, 0, 4))[1];
            if ($len < 8) {
                return null;
            }
            if (substr($h, 4, 4) === 'mvhd') {
                $b = (string) fread($fh, 32);
                $version = ord($b[0] ?? "\0");
                if ($version === 1) {
                    $ts = (int) unpack('N', substr($b, 20, 4))[1];
                    $dur = (int) unpack('J', substr($b, 24, 8))[1];
                } else {
                    $ts = (int) unpack('N', substr($b, 12, 4))[1];
                    $dur = (int) unpack('N', substr($b, 16, 4))[1];
                }
                return $ts > 0 ? $dur / $ts : null;
            }
            $pos += $len;
        }
        return null;
    }

    /** Имя видеофайла, созданного нами. */
    public static function isVideoName(string $filename): bool
    {
        return (bool) preg_match('/^v_[a-f0-9]{24}\.(mp4|mov|webm)$/', $filename);
    }

    /**
     * Скачивает аватар с сервиса входа (Google/Яндекс) и сохраняет у себя — так мы не светим IP посетителей
     * чужим серверам и не зависим от их ссылок. Только https и только известные хосты картинок.
     * Любая неудача → null (аккаунт просто останется с буквой вместо фото).
     */
    public static function storeAvatarFromUrl(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || !self::avatarHostAllowed($host, (string) parse_url($url, PHP_URL_SCHEME))) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'avt');
        if ($tmp === false) {
            return null;
        }
        try {
            $size = Http::download($url, $tmp, self::MAX_BYTES);
            if ($size === null) {
                return null;
            }
            return self::storeAvatar(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => $size, '_local' => true]);
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tmp);
        }
    }

    private static function avatarHostAllowed(string $host, string $scheme): bool
    {
        // Только тесты с заглушкой OAuth (OAUTH_MOCK_BASE) разрешают её хост по http
        $mock = (string) getenv('OAUTH_MOCK_BASE');
        if ($mock !== '' && $host === strtolower((string) parse_url($mock, PHP_URL_HOST))) {
            return true;
        }
        return $scheme === 'https'
            && ($host === 'avatars.yandex.net' || str_ends_with($host, '.googleusercontent.com'));
    }

    /** Удаляет файл аватара, если он наш (а не внешний URL). */
    public static function removeAvatarUrl(?string $url): void
    {
        if ($url !== null && str_starts_with($url, UPLOAD_URL . '/av_')) {
            self::remove(basename($url));
        }
    }

    /** Общая проверка загрузки: ошибки PHP, размер, MIME по содержимому, разрешение, EXIF-поворот. */
    private static function loadValidated(array $file): \GdImage
    {
        self::assertUploaded($file);

        $tmp = $file['tmp_name'];
        if ($file['size'] > self::MAX_BYTES) {
            throw new HttpException('Файл слишком большой (максимум 5 МБ)', 422);
        }

        // MIME определяем по содержимому, а не по расширению/заголовку от клиента
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::ALLOWED[$mime])) {
            throw new HttpException('Допустимы только фото JPG, PNG, WEBP или GIF', 422);
        }
        $info = @getimagesize($tmp);
        if ($info === false || $info[2] !== self::ALLOWED[$mime]) {
            throw new HttpException('Файл повреждён или не является изображением', 422);
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new HttpException('Слишком большое разрешение фото (максимум 25 Мп)', 422);
        }

        $src = self::load($tmp, $mime);
        if ($src === null) {
            throw new HttpException('Не удалось обработать изображение', 422);
        }
        if ($mime === 'image/jpeg') {
            $src = self::applyExifOrientation($src, $tmp);
        }
        return $src;
    }

    private static function ensureDir(): void
    {
        if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true) && !is_dir(UPLOAD_DIR)) {
            throw new HttpException('Не удалось сохранить файл', 500);
        }
    }

    /** Файл загружен пользователем в текущей сессии и ещё не привязан к объявлению. */
    public static function isPending(string $filename): bool
    {
        return isset($_SESSION['pending_uploads'][$filename]);
    }

    /** Убирает файл из списка «ожидающих» (после привязки к объявлению). */
    public static function release(string $filename): void
    {
        unset($_SESSION['pending_uploads'][$filename]);
    }

    /** Удаляет оригинал и миниатюру. Файлы вне формата «24 hex + .jpg» (демо seed_*) не трогаем. */
    public static function remove(string $filename): void
    {
        if (self::isVideoName($filename)) {
            @unlink(UPLOAD_DIR . '/' . $filename);
            unset($_SESSION['pending_uploads'][$filename]);
            return;
        }
        if (!self::isManaged($filename)) {
            return;
        }
        foreach ([$filename, 't_' . $filename] as $f) {
            $path = UPLOAD_DIR . '/' . $f;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        unset($_SESSION['pending_uploads'][$filename]);
    }

    /** Имя сгенерировано нами (защита от path traversal и от удаления чужих файлов): фото или аватар. */
    public static function isManaged(string $filename): bool
    {
        return (bool) preg_match('/^(av_)?[a-f0-9]{24}\.jpg$/', $filename);
    }

    public static function url(string $filename): string
    {
        return UPLOAD_URL . '/' . rawurlencode($filename);
    }

    /** Миниатюра для карточек; если её нет (демо-картинки) — исходный файл. */
    public static function thumbUrl(string $filename): string
    {
        return is_file(UPLOAD_DIR . '/t_' . $filename)
            ? UPLOAD_URL . '/t_' . rawurlencode($filename)
            : self::url($filename);
    }

    private static function assertUploaded(array $file, string $tooBig = 'Файл слишком большой (максимум 5 МБ)'): void
    {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        // _local — файл, который скачали мы сами (аватар из Google/Яндекс), а не загрузил браузер
        if ($err === UPLOAD_ERR_OK && (!empty($file['_local']) || is_uploaded_file($file['tmp_name'] ?? ''))) {
            return;
        }
        throw new HttpException(match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $tooBig,
            UPLOAD_ERR_NO_FILE                        => 'Выберите файл',
            default                                   => 'Не удалось загрузить файл',
        }, 422);
    }

    private static function load(string $path, string $mime): ?\GdImage
    {
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/gif'  => @imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default      => false,
        };
        return $img instanceof \GdImage ? $img : null;
    }

    /** Поворачивает фото согласно EXIF (снимки с телефона часто «лежат на боку»). */
    private static function applyExifOrientation(\GdImage $img, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $img;
        }
        $rotated = imagerotate($img, $angle, 0);
        if ($rotated !== false) {
            imagedestroy($img);
            return $rotated;
        }
        return $img;
    }

    /** Масштабирует так, чтобы длинная сторона ≤ $maxSide, и сохраняет как JPEG на белом фоне. */
    private static function saveResized(\GdImage $src, string $dest, int $maxSide, int $quality): bool
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $canvas = imagecreatetruecolor($nw, $nh);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255)); // прозрачность PNG → белый
        imagecopyresampled($canvas, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imageinterlace($canvas, true);
        $ok = imagejpeg($canvas, $dest, $quality);
        imagedestroy($canvas);
        if ($ok) {
            @chmod($dest, 0644);
        }
        return $ok;
    }
}

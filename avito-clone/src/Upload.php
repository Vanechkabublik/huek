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

        if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true) && !is_dir(UPLOAD_DIR)) {
            throw new HttpException('Не удалось сохранить файл', 500);
        }

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

    /** Имя сгенерировано нами (защита от path traversal и от удаления чужих файлов). */
    public static function isManaged(string $filename): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}\.jpg$/', $filename);
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

    private static function assertUploaded(array $file): void
    {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'] ?? '')) {
            return;
        }
        throw new HttpException(match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой (максимум 5 МБ)',
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

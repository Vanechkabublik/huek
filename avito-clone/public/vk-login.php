<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\HttpException;
use App\Response;
use App\VkAuth;

/**
 * Старт входа через VK: перенаправляет на страницу авторизации VK ID.
 *   /vk-login.php?next=/путь        — войти / зарегистрироваться
 *   /vk-login.php?link=1            — привязать VK к текущему аккаунту
 */
$link = ($_GET['link'] ?? '') === '1';
$next = (string) ($_GET['next'] ?? '/index.php');
$next = str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/index.php';

try {
    if ($link) {
        Auth::requireLogin();
    } elseif (Auth::check()) {
        Response::redirect($next);
    }
    Response::redirect(VkAuth::start($next, $link));
} catch (HttpException $e) {
    $_SESSION['flash_error'] = $e->getMessage();
    Response::redirect($link ? '/profile.php' : '/login.php');
}

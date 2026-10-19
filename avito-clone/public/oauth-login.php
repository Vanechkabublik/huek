<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\HttpException;
use App\OAuth;
use App\Response;

/**
 * Старт входа через Google / Яндекс: перенаправляет на страницу авторизации провайдера.
 *   /oauth-login.php?provider=google&next=/путь   — войти / зарегистрироваться
 *   /oauth-login.php?provider=yandex&link=1       — привязать аккаунт к текущему профилю
 */
$provider = (string) ($_GET['provider'] ?? '');
$link = ($_GET['link'] ?? '') === '1';
$next = (string) ($_GET['next'] ?? '/index.php');
$next = str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/index.php';

try {
    if ($link) {
        Auth::requireLogin();
    } elseif (Auth::check()) {
        Response::redirect($next);
    }
    Response::redirect(OAuth::start($provider, $next, $link, ($_GET['consent'] ?? '') === '1'));
} catch (HttpException $e) {
    $_SESSION[$e->getCode() === 403 ? 'flash_ban' : 'flash_error'] = $e->getMessage();
    Response::redirect($link ? '/profile.php' : '/login.php');
}

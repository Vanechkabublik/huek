<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\HttpException;
use App\Response;
use App\VkAuth;

/**
 * Возврат из VK ID. Этот адрес (https://ваш-домен/vk-callback.php) нужно указать в кабинете VK
 * как доверенный Redirect URL.
 */
try {
    $res = VkAuth::handleCallback($_GET);
    if ($res['linked']) {
        $_SESSION['flash_ok'] = 'Аккаунт VK привязан';
    } else {
        Auth::open($res['user_id']);
        if ($res['created']) {
            $_SESSION['flash_ok'] = 'Добро пожаловать! Аккаунт создан через VK';
        }
    }
    Response::redirect($res['next']);
} catch (HttpException $e) {
    $_SESSION['flash_error'] = $e->getMessage();
    Response::redirect(Auth::check() ? '/profile.php' : '/login.php');
}

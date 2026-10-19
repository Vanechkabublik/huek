<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\HttpException;
use App\Legal;
use App\OAuth;
use App\Response;

/**
 * Возврат от Google / Яндекса. Этот адрес (https://ваш-домен/oauth-callback.php) нужно указать у обоих
 * провайдеров как Redirect URI.
 */
try {
    $res = OAuth::handleCallback($_GET);
    $name = OAuth::name($res['provider']);
    if ($res['linked']) {
        $_SESSION['flash_ok'] = "Аккаунт $name привязан";
    } else {
        Auth::open($res['user_id'], $res['provider'], $res['created']);
        if ($res['consent']) {
            Legal::recordConsent($res['user_id']);                // согласие с политикой, отмеченное на странице входа
        }
        if ($res['created']) {
            $_SESSION['flash_ok'] = "Добро пожаловать! Аккаунт создан через $name";
        }
    }
    Response::redirect($res['next']);
} catch (HttpException $e) {
    // Блокировку (403) показываем отдельной плашкой на странице входа, остальное — всплывающим сообщением
    $_SESSION[$e->getCode() === 403 ? 'flash_ban' : 'flash_error'] = $e->getMessage();
    Response::redirect(Auth::check() ? '/profile.php' : '/login.php');
}

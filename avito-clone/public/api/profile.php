<?php
declare(strict_types=1);

/**
 * Эндпоинт профиля текущего пользователя (все действия — POST, нужен вход).
 *   ?action=update       name, phone, about, email
 *   ?action=avatar       multipart: file
 *   ?action=avatar_delete
 *   ?action=password     current?, password, password2
 *   ?action=identity_unlink  provider (google | yandex)
 */

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\HttpException;
use App\Request;
use App\Response;
use App\OAuth;
use App\Upload;
use App\User;
use App\Validator;

Response::handleExceptions();
Request::requirePost();
$me = Auth::requireLogin(json: true);
$id = (int) $me['id'];

switch (Request::action()) {
    case 'update':
        User::updateProfile($id, Request::input());
        Response::ok(['user' => User::forClient(User::find($id)), 'message' => 'Профиль сохранён']);

    case 'avatar':
        $url = Upload::storeAvatar($_FILES['file'] ?? []);
        Upload::removeAvatarUrl(User::setAvatar($id, $url));
        Response::ok(['avatar' => $url, 'message' => 'Фото профиля обновлено']);

    case 'avatar_delete':
        Upload::removeAvatarUrl(User::setAvatar($id, null));
        Response::ok(['message' => 'Фото удалено']);

    case 'password':
        $in = Request::input();
        $v = Validator::make($in)
            ->required('password', 'Введите новый пароль')
            ->length('password', 6, 72, 'Пароль')
            ->required('password2', 'Повторите пароль')
            ->matches('password2', 'password', 'Пароли не совпадают');
        $errors = $v->errors();
        if ($me['has_password']) {
            // Сменить существующий пароль можно, только зная текущий
            $row = App\Database::one('SELECT password_hash FROM users WHERE id = ?', [$id]);
            if (!password_verify((string) ($in['current'] ?? ''), (string) $row['password_hash'])) {
                $errors['current'] = 'Неверный текущий пароль';
            }
        }
        if ($errors) {
            throw new HttpException('Проверьте правильность заполнения полей', 422, $errors);
        }
        if (empty($me['email'])) {
            throw new HttpException('Сначала укажите email — по нему вы будете входить с паролем', 422, ['email' => 'Укажите email и сохраните профиль']);
        }
        User::setPassword($id, (string) $in['password']);
        Response::ok(['message' => 'Пароль сохранён']);

    case 'identity_unlink':
        $provider = (string) (Request::input()['provider'] ?? '');
        if (!isset(OAuth::PROVIDERS[$provider])) {
            throw new HttpException('Неизвестный провайдер', 422);
        }
        User::unlinkIdentity($id, $provider);
        Response::ok(['message' => OAuth::name($provider) . ' отвязан']);

    default:
        throw new HttpException('Неизвестное действие', 404);
}

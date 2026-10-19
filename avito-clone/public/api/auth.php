<?php
declare(strict_types=1);

/**
 * Эндпоинт аутентификации.
 *   POST ?action=login     {email, password}
 *   POST ?action=register  {name, email, phone?, password, password2}
 *   POST ?action=logout
 *   GET  ?action=me
 */

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\HttpException;
use App\Request;
use App\Response;
use App\User;
use App\Validator;

Response::handleExceptions();

switch (Request::action('me')) {
    case 'me':
        Response::ok(['user' => User::forClient(Auth::user())]);

    case 'login':
        Request::requirePost();
        Validator::make(Request::input())
            ->required('email', 'Введите email')
            ->email('email')
            ->required('password', 'Введите пароль')
            ->validate();
        $user = Auth::login(Request::str('email'), (string) (Request::input()['password'] ?? ''));
        Response::ok(['user' => User::forClient($user), 'csrf' => App\Csrf::token()]);

    case 'register':
        Request::requirePost();
        $in = Request::input();
        Validator::make($in)
            ->required('name', 'Введите имя')
            ->length('name', 2, 100, 'Имя')
            ->required('email', 'Введите email')
            ->email('email')
            ->phone('phone')
            ->required('password', 'Придумайте пароль')
            ->length('password', 6, 72, 'Пароль')
            ->required('password2', 'Повторите пароль')
            ->matches('password2', 'password', 'Пароли не совпадают')
            ->validate();

        $id = User::create([
            'email'    => Request::str('email'),
            'password' => (string) $in['password'],
            'name'     => Request::str('name'),
            'phone'    => Request::str('phone'),
        ]);
        Auth::open($id);
        Response::ok(['user' => User::forClient(Auth::user()), 'csrf' => App\Csrf::token()], 201);

    case 'logout':
        Request::requirePost();
        Auth::logout();
        Response::ok();

    default:
        throw new HttpException('Неизвестное действие', 404);
}

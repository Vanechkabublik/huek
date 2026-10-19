<?php
declare(strict_types=1);

/**
 * Эндпоинт аутентификации.
 *   POST ?action=login     {email, password, consent}
 *   POST ?action=register  {name, email, phone?, password, password2, consent}
 * consent — галочка «принимаю политику обработки данных и соглашение»; без неё вход и регистрация закрыты.
 *   POST ?action=logout
 *   GET  ?action=me
 */

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\Ban;
use App\HttpException;
use App\Legal;
use App\Request;
use App\Response;
use App\User;
use App\Validator;

Response::handleExceptions();

/** Без отмеченного согласия с политикой и соглашением вход и регистрация закрыты. */
function requireConsent(array $in): void
{
    $v = $in['consent'] ?? false;
    if ($v !== true && $v !== 1 && $v !== '1' && $v !== 'true' && $v !== 'on') {
        throw new HttpException(
            'Для продолжения примите Политику обработки персональных данных и Пользовательское соглашение',
            422,
            ['consent' => 'Нужно ваше согласие']
        );
    }
}

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
        requireConsent(Request::input());
        $user = Auth::login(Request::str('email'), (string) (Request::input()['password'] ?? ''));
        Legal::recordConsent((int) $user['id']);
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
        requireConsent($in);
        Ban::assertIpAllowed(Request::str('email'));

        $id = User::create([
            'email'    => Request::str('email'),
            'password' => (string) $in['password'],
            'name'     => Request::str('name'),
            'phone'    => Request::str('phone'),
        ]);
        Auth::open($id, 'email', true);
        Legal::recordConsent($id);
        Response::ok(['user' => User::forClient(Auth::user()), 'csrf' => App\Csrf::token()], 201);

    case 'logout':
        Request::requirePost();
        Auth::logout();
        Response::ok();

    default:
        throw new HttpException('Неизвестное действие', 404);
}

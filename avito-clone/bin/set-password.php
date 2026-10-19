<?php
declare(strict_types=1);

/**
 * Смена пароля пользователя из консоли (например, пароля админа после первого запуска):
 *   docker compose exec app php bin/set-password.php admin@example.com 'НовыйНадёжныйПароль'
 */

require __DIR__ . '/../src/bootstrap.php';

use App\User;

[$script, $email, $password] = $argv + [null, null, null];
if (!$email || !$password) {
    fwrite(STDERR, "Использование: php bin/set-password.php email новый_пароль\n");
    exit(1);
}
if (mb_strlen($password) < 8) {
    fwrite(STDERR, "Пароль слишком короткий: нужно минимум 8 символов.\n");
    exit(1);
}

$user = User::findForLogin($email);
if ($user === null) {
    fwrite(STDERR, "Пользователь $email не найден.\n");
    exit(1);
}

User::setPassword((int) $user['id'], $password);
echo "Пароль для {$user['email']} изменён.\n";

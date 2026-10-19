<?php
declare(strict_types=1);

/**
 * Создаёт администратора (или делает администратором существующего пользователя) из консоли.
 * Нужен на боевом сервере, где демо-данные (APP_DEMO=0) не заливаются и админа ещё нет:
 *   docker compose -f docker-compose.prod.yml exec app php bin/create-admin.php you@example.com 'НадёжныйПароль' 'Имя'
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\User;

[$script, $email, $password, $name] = $argv + [null, null, null, 'Администратор'];
if (!$email || !$password) {
    fwrite(STDERR, "Использование: php bin/create-admin.php email пароль [имя]\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Некорректный email: $email\n");
    exit(1);
}
if (mb_strlen($password) < 8) {
    fwrite(STDERR, "Пароль слишком короткий: нужно минимум 8 символов.\n");
    exit(1);
}

$user = User::findForLogin($email);
if ($user === null) {
    $id = User::create(['email' => $email, 'password' => $password, 'name' => $name]);
    Database::run("UPDATE users SET role = 'admin' WHERE id = ?", [$id]);
    echo "Администратор $email создан (id $id).\n";
} else {
    User::setPassword((int) $user['id'], $password);
    Database::run("UPDATE users SET role = 'admin', is_blocked = 0 WHERE id = ?", [(int) $user['id']]);
    echo "Пользователь {$user['email']} теперь администратор, пароль обновлён.\n";
}

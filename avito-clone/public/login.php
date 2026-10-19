<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\View;

// Уже вошли — возвращаем на главную
if (Auth::check()) {
    App\Response::redirect('/index.php');
}

View::header(['title' => 'Вход', 'page' => 'login', 'categories' => false, 'bodyClass' => 'body-soft']);
?>
<main class="container auth">
    <div class="auth__card">
        <h1>Вход</h1>
        <p class="auth__sub">Войдите, чтобы размещать объявления и писать продавцам</p>

        <form id="loginForm" novalidate>
            <div class="field">
                <label class="field__label" for="email">Email</label>
                <input class="input" id="email" name="email" type="email" autocomplete="email" placeholder="you@example.com" required>
                <p class="field__error" data-error-for="email"></p>
            </div>
            <div class="field">
                <label class="field__label" for="password">Пароль</label>
                <input class="input" id="password" name="password" type="password" autocomplete="current-password" placeholder="Ваш пароль" required>
                <p class="field__error" data-error-for="password"></p>
            </div>
            <button class="btn btn--primary btn--lg btn--block" type="submit">Войти</button>
        </form>

        <?php if (App\VkAuth::enabled()): ?>
            <div class="auth__or"><span>или</span></div>
            <a class="btn btn--vk btn--lg btn--block" href="/vk-login.php?next=<?= e(rawurlencode((string) ($_GET['next'] ?? '/index.php'))) ?>"><span class="vk-mark">VK</span>Войти через VK</a>
        <?php endif; ?>

        <p class="auth__switch">Нет аккаунта? <a href="/register.php">Зарегистрироваться</a></p>
        <?php if (getenv('APP_DEMO') === '1'): /* демо-доступ с паролями показываем только в учебном режиме */ ?>
        <div class="auth__demo">
            <span>Демо-доступ:</span>
            <button type="button" class="chip" data-fill="admin@example.com|admin123">Админ</button>
            <button type="button" class="chip" data-fill="ivan@example.com|user123">Иван</button>
            <button type="button" class="chip" data-fill="maria@example.com|user123">Мария</button>
        </div>
        <?php endif; ?>
    </div>
</main>
<?php View::footer(); ?>

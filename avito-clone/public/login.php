<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\View;

// Уже вошли — возвращаем на главную
if (Auth::check()) {
    App\Response::redirect('/index.php');
}

// Причина блокировки (из сессии: заблокированного пользователя выбросило на вход, либо отказал вход через соцсеть)
$ban = $_SESSION['flash_ban'] ?? null;
unset($_SESSION['flash_ban']);

View::header(['title' => 'Вход', 'page' => 'login', 'categories' => false, 'bodyClass' => 'body-soft']);
?>
<main id="main" class="container auth">
    <div class="auth__card">
        <h1>Вход</h1>
        <p class="auth__sub">Войдите, чтобы размещать объявления и писать продавцам</p>

        <div class="alert alert--ban" id="banBox" role="alert"<?= $ban ? '' : ' hidden' ?>>
            <strong>Доступ ограничен</strong>
            <p><?= e((string) $ban) ?></p>
        </div>

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
            <?= View::consentField() ?>
            <button class="btn btn--primary btn--lg btn--block" type="submit">Войти</button>
        </form>

        <?php $oauth = View::oauthButtons('/oauth-login.php?next=' . rawurlencode((string) ($_GET['next'] ?? '/index.php'))); ?>
        <?php if ($oauth !== ''): ?>
            <div class="auth__or"><span>или</span></div>
            <div class="oauth-list"><?= $oauth ?></div>
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

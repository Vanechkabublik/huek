<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\View;

if (Auth::check()) {
    App\Response::redirect('/index.php');
}

View::header(['title' => 'Регистрация', 'page' => 'register', 'categories' => false, 'bodyClass' => 'body-soft']);
?>
<main id="main" class="container auth">
    <div class="auth__card">
        <h1>Регистрация</h1>
        <p class="auth__sub">Это займёт меньше минуты</p>

        <div class="alert alert--ban" id="banBox" role="alert" hidden><strong>Доступ ограничен</strong><p></p></div>

        <form id="registerForm" novalidate>
            <div class="field">
                <label class="field__label" for="name">Имя</label>
                <input class="input" id="name" name="name" type="text" autocomplete="name" placeholder="Как к вам обращаться" maxlength="100" required>
                <p class="field__error" data-error-for="name"></p>
            </div>
            <div class="field">
                <label class="field__label" for="email">Email</label>
                <input class="input" id="email" name="email" type="email" autocomplete="email" placeholder="you@example.com" required>
                <p class="field__error" data-error-for="email"></p>
            </div>
            <div class="field">
                <label class="field__label" for="phone">Телефон <span class="muted">(необязательно)</span></label>
                <input class="input" id="phone" name="phone" type="tel" autocomplete="tel" placeholder="+7 900 000-00-00">
                <p class="field__hint">Покажем покупателям по кнопке «Показать телефон»</p>
                <p class="field__error" data-error-for="phone"></p>
            </div>
            <div class="field">
                <label class="field__label" for="password">Пароль</label>
                <input class="input" id="password" name="password" type="password" autocomplete="new-password" placeholder="Не короче 6 символов" required>
                <p class="field__error" data-error-for="password"></p>
            </div>
            <div class="field">
                <label class="field__label" for="password2">Повторите пароль</label>
                <input class="input" id="password2" name="password2" type="password" autocomplete="new-password" placeholder="Ещё раз" required>
                <p class="field__error" data-error-for="password2"></p>
            </div>
            <?= View::consentField() ?>
            <button class="btn btn--primary btn--lg btn--block" type="submit">Создать аккаунт</button>
        </form>

        <?php $oauth = View::oauthButtons('/oauth-login.php', 'Продолжить'); ?>
        <?php if ($oauth !== ''): ?>
            <div class="auth__or"><span>или</span></div>
            <div class="oauth-list"><?= $oauth ?></div>
        <?php endif; ?>

        <p class="auth__switch">Уже есть аккаунт? <a href="/login.php">Войти</a></p>
    </div>
</main>
<?php View::footer(); ?>

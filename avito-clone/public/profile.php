<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\View;
use App\VkAuth;

$user = Auth::requireLogin();

View::header(['title' => 'Профиль и настройки', 'page' => 'profile', 'categories' => false, 'bodyClass' => 'body-soft']);
$vkEnabled = VkAuth::enabled();
?>
<main class="container form-page">
    <a class="back-link" href="/seller.php?id=<?= (int) $user['id'] ?>"><?= View::icon('arrow-left') ?>Мой публичный профиль</a>

    <div class="form-card">
        <h1>Профиль и настройки</h1>
        <p class="form-card__sub">Так вас видят покупатели: фото, имя и «О себе» показываются на странице продавца.</p>

        <div class="avatar-edit">
            <div id="avatarPreview"><?= View::avatar($user, 'avatar--xl') ?></div>
            <div class="avatar-edit__actions">
                <button class="btn btn--outline" type="button" id="avatarPick"><?= View::icon('camera') ?>Загрузить фото</button>
                <button class="btn btn--text" type="button" id="avatarRemove"<?= empty($user['avatar_url']) ? ' hidden' : '' ?>>Удалить</button>
                <input type="file" id="avatarFile" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                <p class="field__hint">JPG, PNG или WEBP до 5 МБ — фото будет обрезано до квадрата</p>
            </div>
        </div>

        <form id="profileForm" novalidate>
            <div class="field">
                <label class="field__label" for="pName">Имя</label>
                <input class="input" id="pName" name="name" type="text" maxlength="100" value="<?= e($user['name']) ?>" required>
                <p class="field__error" data-error-for="name"></p>
            </div>
            <div class="field-row">
                <div class="field">
                    <label class="field__label" for="pEmail">Email</label>
                    <input class="input" id="pEmail" name="email" type="email" value="<?= e($user['email'] ?? '') ?>" placeholder="you@example.com">
                    <p class="field__error" data-error-for="email"></p>
                </div>
                <div class="field">
                    <label class="field__label" for="pPhone">Телефон</label>
                    <input class="input" id="pPhone" name="phone" type="tel" value="<?= e($user['phone'] ?? '') ?>" placeholder="+7 900 000-00-00">
                    <p class="field__error" data-error-for="phone"></p>
                </div>
            </div>
            <div class="field">
                <label class="field__label" for="pAbout">О себе</label>
                <textarea class="textarea" id="pAbout" name="about" rows="4" maxlength="500" placeholder="Расскажите, чем занимаетесь и как с вами удобнее связаться"><?= e($user['about'] ?? '') ?></textarea>
                <div class="field__meta"><p class="field__error" data-error-for="about"></p><span class="counter" data-counter-for="about"></span></div>
            </div>
            <button class="btn btn--primary btn--lg" type="submit">Сохранить</button>
        </form>
    </div>

    <div class="form-card form-card--stack">
        <h2>Пароль</h2>
        <p class="form-card__sub"><?= $user['has_password'] ? 'Чтобы сменить пароль, введите текущий.' : 'Вы вошли через VK и пока не задали пароль. Задайте его, чтобы входить и по email.' ?></p>
        <form id="passwordForm" novalidate>
            <?php if ($user['has_password']): ?>
                <div class="field">
                    <label class="field__label" for="pCur">Текущий пароль</label>
                    <input class="input" id="pCur" name="current" type="password" autocomplete="current-password">
                    <p class="field__error" data-error-for="current"></p>
                </div>
            <?php endif; ?>
            <div class="field-row">
                <div class="field">
                    <label class="field__label" for="pNew">Новый пароль</label>
                    <input class="input" id="pNew" name="password" type="password" autocomplete="new-password" placeholder="Не короче 6 символов">
                    <p class="field__error" data-error-for="password"></p>
                </div>
                <div class="field">
                    <label class="field__label" for="pNew2">Повторите пароль</label>
                    <input class="input" id="pNew2" name="password2" type="password" autocomplete="new-password">
                    <p class="field__error" data-error-for="password2"></p>
                </div>
            </div>
            <button class="btn btn--outline btn--lg" type="submit">Сохранить пароль</button>
        </form>
    </div>

    <?php if ($vkEnabled || $user['vk_id']): ?>
        <div class="form-card form-card--stack">
            <h2>Вход через VK</h2>
            <?php if ($user['vk_id']): ?>
                <p class="form-card__sub"><?= View::icon('shield-check', 'icon--inline') ?> Аккаунт VK привязан (id <?= e((string) $user['vk_id']) ?>). Вы можете входить одним нажатием.</p>
                <button class="btn btn--outline" type="button" id="vkUnlink">Отвязать VK</button>
            <?php else: ?>
                <p class="form-card__sub">Привяжите VK, чтобы входить без пароля.</p>
                <a class="btn btn--vk btn--lg" href="/vk-login.php?link=1"><span class="vk-mark">VK</span>Привязать VK</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>
<?php View::footer(); ?>

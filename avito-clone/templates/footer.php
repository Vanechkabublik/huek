<?php
/** Подвал страницы: футер сайта, нижняя навигация на мобильных, контейнеры тостов/модалок, скрипты. */
use App\View;
?>
<footer class="footer">
    <div class="container footer__inner">
        <div>
            <a class="logo logo--sm" href="/index.php"><span class="logo__mark">А<i></i></span><span class="logo__text">Авито<b>-клон</b></span></a>
            <p class="footer__note">Учебный проект: монолит на PHP + PostgreSQL, без фреймворков.</p>
        </div>
        <nav class="footer__links" aria-label="Ссылки">
            <a href="/index.php">Объявления</a>
            <a href="/create-ad.php">Разместить объявление</a>
            <a href="/favorites.php">Избранное</a>
            <a href="/my-ads.php">Мои объявления</a>
        </nav>
    </div>
</footer>

<nav class="bottom-nav" aria-label="Мобильная навигация">
    <a href="/index.php" class="<?= $page === 'index' ? 'is-active' : '' ?>"><?= View::icon('home') ?><span>Главная</span></a>
    <a href="/favorites.php" class="<?= $page === 'favorites' ? 'is-active' : '' ?>"><?= View::icon('heart') ?><span>Избранное</span></a>
    <a href="/create-ad.php" class="bottom-nav__create" aria-label="Разместить объявление"><span><?= View::icon('plus') ?></span></a>
    <a href="/chat.php" class="bottom-nav__chat <?= $page === 'chat' ? 'is-active' : '' ?>"><?= View::icon('message') ?><span>Чаты</span><span class="dot-badge" id="chatBadgeMobile"<?= $unread > 0 ? '' : ' hidden' ?>><?= $unread > 9 ? '9+' : $unread ?></span></a>
    <a href="<?= $user ? '/profile.php' : '/login.php' ?>" class="<?= $page === 'profile' ? 'is-active' : '' ?>"><?= View::icon('user') ?><span><?= $user ? 'Профиль' : 'Войти' ?></span></a>
</nav>

<div class="toasts" id="toasts" aria-live="polite"></div>
<div id="modalRoot"></div>

<script src="<?= e(View::asset('/assets/js/app.js')) ?>" defer></script>
<?php foreach ($scripts as $src): ?>
<script src="<?= e(View::asset($src)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>

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
            <a href="/my-ads.php">Мои объявления</a>
        </nav>
    </div>
</footer>

<nav class="bottom-nav" aria-label="Мобильная навигация">
    <a href="/index.php" class="<?= $page === 'index' ? 'is-active' : '' ?>"><?= View::icon('home') ?><span>Главная</span></a>
    <a href="/my-ads.php" class="<?= $page === 'my-ads' ? 'is-active' : '' ?>"><?= View::icon('list') ?><span>Мои</span></a>
    <a href="/create-ad.php" class="bottom-nav__create" aria-label="Разместить объявление"><span><?= View::icon('plus') ?></span></a>
    <a href="/messages.php" class="<?= $page === 'messages' ? 'is-active' : '' ?>"><?= View::icon('message') ?><span>Чаты</span></a>
    <a href="<?= $user ? '/my-ads.php' : '/login.php' ?>"><?= View::icon('user') ?><span><?= $user ? 'Профиль' : 'Войти' ?></span></a>
</nav>

<div class="toasts" id="toasts" aria-live="polite"></div>
<div id="modalRoot"></div>

<script src="<?= e(View::asset('/assets/js/app.js')) ?>" defer></script>
</body>
</html>

<?php
/**
 * Шапка страницы. Переменные приходят из View::header():
 * $o (опции), $user, $unread, $client (данные для JS), $cats, $site (название сайта), $city (выбранный город).
 */
use App\View;

$isIndex = $o['page'] === 'index';
$q = $isIndex ? (string) ($_GET['q'] ?? '') : '';
$cityLabel = $city === null ? 'Ваш город' : ($city === 'all' ? 'Все города' : $city);
$origin = App\OAuth::appUrl();
$ogImage = $o['image'] !== '' ? $o['image'] : '/icon-512.png';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($o['title']) ?><?= $o['title'] === $site ? '' : ' — ' . e($site) ?></title>
    <meta name="description" content="<?= e($o['description']) ?>">
    <meta name="theme-color" content="#1d4ed8" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0c1017" media="(prefers-color-scheme: dark)">
    <meta name="color-scheme" content="light dark">
    <meta name="csrf-token" content="<?= e($client['csrf']) ?>">
<?php if ($o['noindex']): ?>
    <meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<?php if ($o['canonical'] !== ''): ?>
    <link rel="canonical" href="<?= e($origin . $o['canonical']) ?>">
    <meta property="og:url" content="<?= e($origin . $o['canonical']) ?>">
<?php endif; ?>
    <meta property="og:site_name" content="<?= e($site) ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= e($o['title']) ?>">
    <meta property="og:description" content="<?= e($o['description']) ?>">
    <meta property="og:image" content="<?= e(str_starts_with($ogImage, 'http') ? $ogImage : $origin . $ogImage) ?>">
    <meta name="twitter:card" content="summary">
    <?= View::themeScript() ?>

    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="preload" href="/assets/fonts/montserrat-cyrillic.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/assets/fonts/montserrat-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(View::asset('/assets/css/style.css')) ?>">
</head>
<body class="<?= e($o['bodyClass']) ?>" data-page="<?= e($o['page']) ?>">
<?php require BASE_PATH . '/templates/icons.php'; ?>
<a class="skip-link" href="#main">Перейти к содержимому</a>

<header class="header" id="header">
    <div class="container header__inner">
        <?= View::logo() ?>

        <button class="city-btn" type="button" id="cityBtn" aria-haspopup="dialog" title="Выбрать город">
            <?= View::icon('map-pin') ?><span class="city-btn__label" id="cityLabel"><?= e($cityLabel) ?></span><?= View::icon('chevron-down', 'city-btn__chev') ?>
        </button>

        <form class="search" id="searchForm" action="/index.php" method="get" role="search">
            <?= View::icon('search', 'search__icon') ?>
            <input class="search__input" id="searchInput" type="search" name="q" value="<?= e($q) ?>"
                   placeholder="Поиск объявлений" autocomplete="off" aria-label="Поиск объявлений" maxlength="100">
            <button class="btn btn--primary search__btn" type="submit" aria-label="Найти"><span class="search__btn-text">Найти</span><?= View::icon('search', 'search__btn-icon') ?></button>
        </form>

        <nav class="header__actions" aria-label="Основная навигация">
            <a class="icon-link header__fav" href="/favorites.php" title="Избранное" aria-label="Избранное"><?= View::icon('heart') ?></a>
            <a class="icon-link header__chat" href="/chat.php" title="Сообщения" aria-label="Сообщения">
                <?= View::icon('message') ?><span class="dot-badge" id="chatBadge"<?= $unread > 0 ? '' : ' hidden' ?>><?= $unread > 9 ? '9+' : $unread ?></span>
            </a>
            <button class="icon-link theme-toggle" type="button" id="themeToggle" title="Сменить тему" aria-label="Сменить тему">
                <?= View::icon('moon', 'theme-toggle__moon') ?><?= View::icon('sun', 'theme-toggle__sun') ?>
            </button>
            <a class="btn btn--primary header__create" href="/create-ad.php">
                <?= View::icon('plus') ?><span>Разместить<span class="header__create-more"> объявление</span></span>
            </a>

            <?php if ($user): ?>
                <div class="user-menu" id="userMenu">
                    <button class="user-menu__btn" type="button" aria-haspopup="true" aria-expanded="false" aria-label="Меню профиля">
                        <?= View::avatar($user) ?>
                    </button>
                    <div class="user-menu__dropdown" role="menu">
                        <div class="user-menu__head">
                            <strong><?= e($user['name']) ?></strong>
                            <span><?= e($user['email'] ?? 'Вход через соцсеть') ?></span>
                        </div>
                        <a href="/profile.php" role="menuitem"><?= View::icon('settings') ?>Профиль и настройки</a>
                        <a href="/my-ads.php" role="menuitem"><?= View::icon('list') ?>Мои объявления</a>
                        <a href="/favorites.php" role="menuitem"><?= View::icon('heart') ?>Избранное</a>
                        <a href="/chat.php" role="menuitem"><?= View::icon('message') ?>Сообщения<span class="pill-count" id="menuChatCount"<?= $unread > 0 ? '' : ' hidden' ?>><?= $unread ?></span></a>
                        <a href="/seller.php?id=<?= (int) $user['id'] ?>" role="menuitem"><?= View::icon('user') ?>Мой публичный профиль</a>
                        <?php if ($user['role'] === 'admin'): ?>
                            <a href="/admin.php" role="menuitem"><?= View::icon('shield') ?>Админ-панель</a>
                        <?php endif; ?>
                        <button type="button" data-action="logout" role="menuitem"><?= View::icon('log-out') ?>Выйти</button>
                    </div>
                </div>
            <?php else: ?>
                <a class="header__link header__login" href="/login.php?next=<?= e(rawurlencode($_SERVER['REQUEST_URI'] ?? '/')) ?>">
                    <?= View::icon('user') ?><span>Войти</span>
                </a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<?php if ($o['categories']): ?>
        <div class="cat-bar">
            <div class="container">
                <nav class="cat-bar__scroll" id="catBar" aria-label="Категории">
                    <a class="cat-chip<?= $o['category'] === 0 && $isIndex && empty($_GET['category']) ? ' is-active' : '' ?>"
                       href="/index.php" data-category="0">
                        <?= View::icon('grid', 'cat-chip__icon') ?><span>Все категории</span>
                    </a>
                    <?php foreach ($cats as $c): ?>
                        <a class="cat-chip<?= $o['category'] === $c['id'] ? ' is-active' : '' ?>"
                           href="/index.php?category=<?= $c['id'] ?>" data-category="<?= $c['id'] ?>">
                            <?= View::icon(View::categoryIcon($c['name']), 'cat-chip__icon') ?><span><?= e($c['name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </div>
<?php endif; ?>

<script>window.__APP__ = <?= View::json($client) ?>;</script>

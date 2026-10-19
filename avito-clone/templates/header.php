<?php
/**
 * Шапка страницы. Переменные приходят из View::header():
 * $o (опции), $user, $unread, $client (данные для JS), $cats.
 */
use App\View;

$isIndex = $o['page'] === 'index';
$q = $isIndex ? (string) ($_GET['q'] ?? '') : '';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($o['title']) ?> — <?= e(APP_NAME) ?></title>
    <meta name="description" content="<?= e($o['description']) ?>">
    <meta name="theme-color" content="#00aaff">
    <meta name="csrf-token" content="<?= e($client['csrf']) ?>">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='9' fill='%2300aaff'/%3E%3Ctext x='16' y='23' font-family='Arial' font-size='20' font-weight='700' text-anchor='middle' fill='white'%3E%D0%90%3C/text%3E%3Ccircle cx='26' cy='7' r='4' fill='%23ffc700'/%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;display=swap&amp;subset=cyrillic">
    <link rel="stylesheet" href="<?= e(View::asset('/assets/css/style.css')) ?>">
</head>
<body class="<?= e($o['bodyClass']) ?>" data-page="<?= e($o['page']) ?>">
<?php require BASE_PATH . '/templates/icons.php'; ?>

<header class="header" id="header">
    <div class="container header__inner">
        <a class="logo" href="/index.php" aria-label="<?= e(APP_NAME) ?> — на главную">
            <span class="logo__mark">А<i></i></span>
            <span class="logo__text">Авито<b>-клон</b></span>
        </a>

        <form class="search" id="searchForm" action="/index.php" method="get" role="search">
            <?= View::icon('search', 'search__icon') ?>
            <input class="search__input" id="searchInput" type="search" name="q" value="<?= e($q) ?>"
                   placeholder="Что ищете?" autocomplete="off" aria-label="Поиск объявлений" maxlength="100">
            <button class="btn btn--primary search__btn" type="submit">Найти</button>
        </form>

        <nav class="header__actions" aria-label="Основная навигация">
            <a class="header__link header__link--text" href="/my-ads.php">Мои объявления</a>
            <a class="btn btn--primary header__create" href="/create-ad.php">
                <?= View::icon('plus') ?><span>Разместить объявление</span>
            </a>

            <?php if ($user): ?>
                <div class="user-menu" id="userMenu">
                    <button class="user-menu__btn" type="button" aria-haspopup="true" aria-expanded="false" aria-label="Меню профиля">
                        <span class="avatar"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
                        <?php if ($unread > 0): ?><span class="dot-badge"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?>
                    </button>
                    <div class="user-menu__dropdown" role="menu">
                        <div class="user-menu__head">
                            <strong><?= e($user['name']) ?></strong>
                            <span><?= e($user['email']) ?></span>
                        </div>
                        <a href="/my-ads.php" role="menuitem"><?= View::icon('list') ?>Мои объявления</a>
                        <a href="/messages.php" role="menuitem"><?= View::icon('message') ?>Сообщения
                            <?php if ($unread > 0): ?><span class="pill-count"><?= $unread ?></span><?php endif; ?></a>
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
                        <span class="cat-chip__icon">🔥</span><span>Все</span>
                    </a>
                    <?php foreach ($cats as $c): ?>
                        <a class="cat-chip<?= $o['category'] === $c['id'] ? ' is-active' : '' ?>"
                           href="/index.php?category=<?= $c['id'] ?>" data-category="<?= $c['id'] ?>">
                            <span class="cat-chip__icon"><?= e($c['icon']) ?></span><span><?= e($c['name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </div>
<?php endif; ?>

<script>window.__APP__ = <?= View::json($client) ?>;</script>

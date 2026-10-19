<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\View;

$admin = Auth::requireAdmin();
$client = View::clientData($admin);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Админ-панель — <?= e(APP_NAME) ?></title>
    <meta name="csrf-token" content="<?= e($client['csrf']) ?>">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex">
    <?= View::themeScript() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;display=swap&amp;subset=cyrillic">
    <link rel="stylesheet" href="<?= e(View::asset('/assets/css/style.css')) ?>">
</head>
<body class="admin-body" data-page="admin">
<?php require BASE_PATH . '/templates/icons.php'; ?>

<div class="admin">
    <aside class="admin__sidebar" id="adminSidebar">
        <a class="admin__brand" href="/admin.php">
            <span class="logo__mark">А<i></i></span>
            <span><b>Админ-панель</b><small><?= e(APP_NAME) ?></small></span>
        </a>
        <nav class="admin__nav" aria-label="Разделы админки">
            <a href="#/stats" data-section="stats"><?= View::icon('bar-chart') ?>Дашборд</a>
            <a href="#/users" data-section="users"><?= View::icon('users') ?>Пользователи</a>
            <a href="#/ads" data-section="ads"><?= View::icon('list') ?>Объявления<span class="nav-badge" id="navPending" hidden></span></a>
            <a href="#/categories" data-section="categories"><?= View::icon('tag') ?>Категории</a>
            <a href="#/reviews" data-section="reviews"><?= View::icon('star') ?>Отзывы</a>
            <a href="#/audit" data-section="audit"><?= View::icon('file-text') ?>Журнал</a>
        </nav>
        <div class="admin__foot">
            <div class="admin__me">
                <span class="avatar"><?= e(mb_strtoupper(mb_substr($admin['name'], 0, 1))) ?></span>
                <div><strong><?= e($admin['name']) ?></strong><small><?= e($admin['email']) ?></small></div>
            </div>
            <button type="button" id="themeToggle" class="theme-toggle theme-toggle--admin"><?= View::icon('moon', 'theme-toggle__moon') ?><?= View::icon('sun', 'theme-toggle__sun') ?><span>Сменить тему</span></button>
            <a href="/index.php"><?= View::icon('home') ?>На сайт</a>
            <button type="button" data-action="logout"><?= View::icon('log-out') ?>Выход</button>
        </div>
    </aside>

    <main class="admin__main">
        <div class="admin__content" id="adminContent" aria-live="polite"></div>
    </main>
</div>

<div class="toasts" id="toasts" aria-live="polite"></div>
<div id="modalRoot"></div>
<script>window.__APP__ = <?= View::json($client) ?>;</script>
<script src="<?= e(View::asset('/assets/js/app.js')) ?>" defer></script>
<script src="<?= e(View::asset('/assets/js/admin.js')) ?>" defer></script>
</body>
</html>

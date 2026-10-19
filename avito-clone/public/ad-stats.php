<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Ad;
use App\Auth;
use App\View;

$user = Auth::requireLogin();
$id   = (int) ($_GET['id'] ?? 0);
$ad   = $id > 0 ? Ad::get($id, $user) : null;
if ($ad === null || (!$ad['is_owner'] && $user['role'] !== 'admin')) {
    View::notFound('Статистика доступна только автору объявления.');
}

View::header(['title' => 'Статистика: ' . $ad['title'], 'page' => 'ad-stats', 'categories' => false, 'bodyClass' => 'body-soft']);
?>
<main class="container stats-page" data-ad="<?= $ad['id'] ?>">
    <a class="back-link" href="<?= $ad['is_owner'] ? '/my-ads.php' : '/admin.php#/ads' ?>"><?= View::icon('arrow-left') ?>Назад</a>

    <div class="stats-head">
        <a class="stats-head__ad" href="/ad.php?id=<?= $ad['id'] ?>">
            <span class="stats-head__img"><?php if ($ad['cover']): ?><img src="<?= e($ad['cover']) ?>" alt=""><?php endif; ?></span>
            <span>
                <strong><?= e($ad['title']) ?></strong>
                <span class="muted"><?= e($ad['price_text']) ?> · <?= e($ad['city']) ?></span>
            </span>
        </a>
        <span class="badge badge--<?= e($ad['status']) ?>"><?= e($ad['status_label']) ?></span>
    </div>

    <h1>Статистика объявления</h1>
    <p class="muted stats-note">Считаются <b>уникальные посетители</b>: обновление страницы и повторные заходы одного человека не увеличивают счётчик.
        Не учитываются ваши заходы, администраторы и боты.</p>

    <div class="tiles" id="statTiles"></div>

    <div class="panel-card">
        <div class="panel-card__head"><h2>Просмотры по дням</h2><span class="muted">последние 14 дней</span></div>
        <div id="chartViews" class="chart"></div>
    </div>
    <div class="panel-card">
        <div class="panel-card__head"><h2>Интерес покупателей</h2>
            <span class="legend"><i class="legend__dot legend__dot--yellow"></i>Контакты (телефон + чаты)<i class="legend__dot legend__dot--red"></i>В избранное</span></div>
        <div id="chartInterest" class="chart"></div>
    </div>
</main>
<?php View::footer(); ?>

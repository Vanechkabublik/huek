<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Ad;
use App\Auth;
use App\Stats;
use App\View;

$id   = (int) ($_GET['id'] ?? 0);
$user = Auth::user();
$ad   = $id > 0 ? Ad::get($id, $user) : null;
if ($ad === null) {
    View::notFound('Объявление не найдено, снято с публикации или ещё не прошло модерацию.');
}

// Считаем людей, а не загрузки страницы: повторный заход и обновление страницы просмотр не увеличивают
if ($ad['status'] === 'approved' && Stats::trackView($id, (int) $ad['user_id'], $user)) {
    $ad['views']++;
}

$isAdmin  = $user !== null && $user['role'] === 'admin';
$seller   = $ad['seller'];
$images   = $ad['images'];
$notices  = [
    'pending'  => ['На модерации', 'Объявление проверяется модератором и пока не видно другим пользователям.'],
    'rejected' => ['Отклонено модератором', 'Исправьте объявление согласно замечанию и отправьте его на проверку повторно.'],
    'blocked'  => ['Заблокировано', 'Объявление нарушает правила сайта и не будет опубликовано.'],
];

View::header([
    'title'       => $ad['title'],
    'page'        => 'ad',
    'category'    => (int) $ad['category_id'],
    'description' => mb_substr(preg_replace('/\s+/u', ' ', $ad['description']), 0, 160),
]);
?>
<main class="container ad-page" data-ad-id="<?= $ad['id'] ?>" data-category="<?= (int) $ad['category_id'] ?>">
    <nav class="breadcrumbs" aria-label="Хлебные крошки">
        <a href="/index.php">Главная</a>
        <?= View::icon('chevron-right') ?>
        <?php if ($ad['category_id']): ?>
            <a href="/index.php?category=<?= $ad['category_id'] ?>"><?= e($ad['category_name']) ?></a>
            <?= View::icon('chevron-right') ?>
        <?php endif; ?>
        <span><?= e($ad['title']) ?></span>
    </nav>

    <?php if (isset($notices[$ad['status']]) && ($ad['is_owner'] || $isAdmin)): ?>
        <div class="notice notice--<?= e($ad['status']) ?>">
            <?= View::icon($ad['status'] === 'pending' ? 'clock' : 'alert-circle') ?>
            <div>
                <strong><?= e($notices[$ad['status']][0]) ?></strong>
                <p><?= e($notices[$ad['status']][1]) ?></p>
                <?php if (!empty($ad['rejection_reason'])): ?>
                    <p class="notice__reason"><b>Причина:</b> <?= e($ad['rejection_reason']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="ad-grid">
        <h1 class="ad-title"><?= e($ad['title']) ?></h1>

        <!-- Галерея: горизонтальный scroll-snap — на телефоне это свайп-карусель -->
        <div class="gallery" id="gallery" data-count="<?= count($images) ?>">
            <?php if ($images): ?>
                <div class="gallery__main">
                    <div class="gallery__track" id="galleryTrack">
                        <?php foreach ($images as $i => $img): ?>
                            <div class="gallery__slide">
                                <img src="<?= e($img['url']) ?>" alt="<?= e($ad['title']) ?> — фото <?= $i + 1 ?>"
                                     <?= $i > 0 ? 'loading="lazy"' : '' ?> draggable="false">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($images) > 1): ?>
                        <button class="gallery__nav gallery__nav--prev" type="button" aria-label="Предыдущее фото"><?= View::icon('chevron-left') ?></button>
                        <button class="gallery__nav gallery__nav--next" type="button" aria-label="Следующее фото"><?= View::icon('chevron-right') ?></button>
                        <span class="gallery__counter" id="galleryCounter">1 / <?= count($images) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (count($images) > 1): ?>
                    <div class="gallery__thumbs" id="galleryThumbs">
                        <?php foreach ($images as $i => $img): ?>
                            <button class="gallery__thumb<?= $i === 0 ? ' is-active' : '' ?>" type="button" data-index="<?= $i ?>" aria-label="Фото <?= $i + 1 ?>">
                                <img src="<?= e($img['thumb']) ?>" alt="" loading="lazy">
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="gallery__main gallery__main--empty"><?= View::icon('image') ?><span>Нет фото</span></div>
            <?php endif; ?>
        </div>

        <aside class="ad-side">
            <div class="buy-card">
                <div class="buy-card__top">
                    <div class="price-big"><?= e($ad['price_text']) ?></div>
                    <?php if (!$ad['is_owner'] && $ad['status'] === 'approved'): ?>
                        <button class="fav-btn fav-btn--lg<?= $ad['is_favorite'] ? ' is-active' : '' ?>" type="button" data-fav-ad="<?= $ad['id'] ?>"
                                aria-pressed="<?= $ad['is_favorite'] ? 'true' : 'false' ?>" title="В избранное" aria-label="В избранное"><?= View::icon('heart') ?></button>
                    <?php endif; ?>
                </div>

                <?php if ($ad['is_owner']): ?>
                    <div class="buy-card__actions">
                        <?php if ($ad['can_edit']): ?>
                            <a class="btn btn--primary btn--lg btn--block" href="/create-ad.php?id=<?= $ad['id'] ?>"><?= View::icon('edit') ?>Редактировать</a>
                        <?php endif; ?>
                        <a class="btn btn--outline btn--lg btn--block" href="/ad-stats.php?id=<?= $ad['id'] ?>"><?= View::icon('trending') ?>Статистика</a>
                        <button class="btn btn--outline btn--lg btn--block btn--danger-text" type="button" id="deleteAdBtn"><?= View::icon('trash') ?>Удалить</button>
                    </div>
                <?php else: ?>
                    <div class="buy-card__actions">
                        <button class="btn btn--primary btn--lg btn--block" type="button" id="showPhoneBtn" data-ad="<?= $ad['id'] ?>">
                            <?= View::icon('phone') ?><span>Показать телефон</span>
                        </button>
                        <a class="btn btn--yellow btn--lg btn--block" id="writeBtn" href="/chat.php?ad=<?= $ad['id'] ?>">
                            <?= View::icon('message') ?>Написать сообщение
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ($isAdmin && !$ad['is_owner']): ?>
                    <a class="btn btn--text btn--block" href="/admin.php#/ads?user=<?= (int) $ad['user_id'] ?>"><?= View::icon('shield') ?>Открыть в админ-панели</a>
                <?php endif; ?>

                <div class="seller">
                    <a href="/seller.php?id=<?= $seller['id'] ?>" aria-label="Профиль продавца"><?= View::avatar(['name' => $seller['name'], 'avatar' => $seller['avatar']], 'avatar--lg') ?></a>
                    <div class="seller__info">
                        <a class="seller__name" href="/seller.php?id=<?= $seller['id'] ?>"><?= e($seller['name']) ?></a>
                        <?php if ($seller['reviews'] > 0): ?>
                            <span class="rating"><?= View::icon('star') ?><b><?= number_format($seller['rating'], 1, ',', '') ?></b><span class="muted">· <?= $seller['reviews'] ?> <?= App\Validator::plural($seller['reviews'], 'отзыв', 'отзыва', 'отзывов') ?></span></span>
                        <?php else: ?>
                            <span class="seller__since">Пока нет отзывов</span>
                        <?php endif; ?>
                        <span class="seller__since"><?= e($seller['member_since']) ?><?= $seller['vk_verified'] ? ' · ' . View::icon('shield-check', 'icon--inline') . ' через VK' : '' ?></span>
                        <a class="seller__link" href="/seller.php?id=<?= $seller['id'] ?>">Профиль и объявления (<?= $seller['ads_count'] ?>)</a>
                    </div>
                </div>

                <button class="btn btn--text btn--block share-btn" type="button" id="shareBtn"><?= View::icon('share') ?>Поделиться</button>
            </div>
        </aside>

        <div class="ad-info">
            <ul class="facts">
                <li><?= View::icon('map-pin') ?><span>Город</span><b><?= e($ad['city']) ?></b></li>
                <li><?= View::icon('tag') ?><span>Категория</span><b><?= e($ad['category_icon'] . ' ' . $ad['category_name']) ?></b></li>
                <li><?= View::icon('calendar') ?><span>Опубликовано</span><b><?= e($ad['date_text']) ?></b></li>
                <li><?= View::icon('eye') ?><span>Просмотры</span><b><?= number_format($ad['views'], 0, '', "\u{00A0}") ?></b></li>
            </ul>

            <?php if ($ad['attribute_list']): ?>
                <h2 class="section-title">Характеристики</h2>
                <dl class="specs">
                    <?php foreach ($ad['attribute_list'] as $a): ?>
                        <div class="specs__row"><dt><?= e($a['label']) ?></dt><dd><?= e($a['value']) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>

            <h2 class="section-title">Описание</h2>
            <div class="ad-description"><?= e($ad['description']) ?></div>
            <p class="ad-number">Объявление № <?= $ad['id'] ?></p>
        </div>
    </div>

    <?php if ($ad['status'] === 'approved'): ?>
        <section class="related" id="related" hidden>
            <h2 class="section-title">Похожие объявления</h2>
            <div class="grid" id="relatedGrid"></div>
        </section>
    <?php endif; ?>
</main>
<?php View::footer(); ?>

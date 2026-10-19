<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\User;
use App\Validator;
use App\View;

$id     = (int) ($_GET['id'] ?? 0);
$seller = $id > 0 ? User::sellerProfile($id) : null;
if ($seller === null || $seller['is_blocked']) {
    View::notFound('Профиль не найден или недоступен.');
}
$me    = Auth::user();
$isMe  = $me !== null && (int) $me['id'] === $id;

View::header(['title' => $seller['name'], 'page' => 'seller', 'categories' => false, 'description' => 'Профиль продавца ' . $seller['name'] . ': объявления и отзывы']);
?>
<main class="container seller-page" data-seller="<?= $id ?>" data-me="<?= $me ? (int) $me['id'] : 0 ?>">
    <section class="profile-head">
        <?= View::avatar(['name' => $seller['name'], 'avatar' => $seller['avatar']], 'avatar--xl') ?>
        <div class="profile-head__info">
            <h1><?= e($seller['name']) ?></h1>
            <div class="profile-head__meta">
                <?php if ($seller['reviews'] > 0): ?>
                    <span class="rating rating--lg"><?= View::icon('star') ?><b><?= number_format($seller['rating'], 1, ',', '') ?></b>
                        <a href="#reviews" data-tab-link="reviews"><?= $seller['reviews'] ?> <?= Validator::plural($seller['reviews'], 'отзыв', 'отзыва', 'отзывов') ?></a></span>
                <?php else: ?>
                    <span class="muted">Пока нет отзывов</span>
                <?php endif; ?>
                <span class="muted"><?= e($seller['member_since']) ?></span>
                <?php if ($seller['vk_verified']): ?><span class="badge-soft"><?= View::icon('shield-check') ?>Профиль подтверждён через VK</span><?php endif; ?>
            </div>
            <?php if ($seller['about']): ?><p class="profile-head__about"><?= e($seller['about']) ?></p><?php endif; ?>
        </div>
        <?php if ($isMe): ?>
            <a class="btn btn--outline" href="/profile.php"><?= View::icon('settings') ?>Редактировать профиль</a>
        <?php endif; ?>
    </section>

    <div class="tabs" id="sellerTabs" role="tablist">
        <button class="tab is-active" type="button" data-tab="ads">Объявления <span class="tab__count"><?= $seller['ads_count'] ?></span></button>
        <button class="tab" type="button" data-tab="reviews">Отзывы <span class="tab__count"><?= $seller['reviews'] ?></span></button>
    </div>

    <section id="tabAds">
        <div class="grid" id="sellerGrid"></div>
        <div class="load-more" id="sellerMoreBox" hidden><button class="btn btn--outline btn--lg" type="button" id="sellerMore">Показать ещё</button></div>
    </section>

    <section id="tabReviews" hidden>
        <div class="reviews-layout">
            <aside class="reviews-summary" id="reviewsSummary"></aside>
            <div>
                <div id="reviewForm"></div>
                <div class="reviews" id="reviewsList"></div>
                <div class="load-more" id="reviewsMoreBox" hidden><button class="btn btn--outline" type="button" id="reviewsMore">Показать ещё</button></div>
            </div>
        </div>
    </section>
</main>
<?php View::footer(); ?>

<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Ad;
use App\User;
use App\View;

$categories = View::categories();
$cities     = Ad::cities();

// Состояние фильтров из URL — чтобы ссылку можно было отправить другу, а обновление страницы не сбрасывало выбор
$f = [
    'category'  => (int) ($_GET['category'] ?? 0),
    'city'      => trim((string) ($_GET['city'] ?? '')),
    'price_min' => trim((string) ($_GET['price_min'] ?? '')),
    'price_max' => trim((string) ($_GET['price_max'] ?? '')),
    'sort'      => in_array($_GET['sort'] ?? '', ['price_asc', 'price_desc'], true) ? $_GET['sort'] : 'date',
    'user'      => (int) ($_GET['user'] ?? 0),
];
$seller = $f['user'] > 0 ? User::sellerProfile($f['user']) : null;

View::header([
    'title'    => 'Объявления',
    'page'     => 'index',
    'category' => $f['category'],
]);
?>
<main class="container page-index">
    <div class="layout">
        <aside class="filters" id="filters" aria-label="Фильтры">
            <form class="filters__card" id="filtersForm" autocomplete="off">
                <div class="filters__head">
                    <h2>Фильтры</h2>
                    <button class="icon-btn filters__close" type="button" id="filtersClose" aria-label="Закрыть"><?= View::icon('x') ?></button>
                </div>

                <div class="field">
                    <label class="field__label" for="fCity">Город</label>
                    <select class="select" id="fCity" name="city">
                        <option value="">Все города</option>
                        <?php foreach ($cities as $city): ?>
                            <option value="<?= e($city) ?>"<?= $f['city'] === $city ? ' selected' : '' ?>><?= e($city) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label class="field__label" for="fCategory">Категория</label>
                    <select class="select" id="fCategory" name="category">
                        <option value="0">Все категории</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"<?= $f['category'] === $c['id'] ? ' selected' : '' ?>><?= e($c['icon'] . ' ' . $c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="attrFilters" class="attr-filters"></div>

                <div class="field">
                    <span class="field__label">Цена, ₽</span>
                    <div class="range">
                        <input class="input" type="number" min="0" inputmode="numeric" name="price_min" placeholder="от" value="<?= e($f['price_min']) ?>" aria-label="Цена от">
                        <span class="range__dash">—</span>
                        <input class="input" type="number" min="0" inputmode="numeric" name="price_max" placeholder="до" value="<?= e($f['price_max']) ?>" aria-label="Цена до">
                    </div>
                </div>

                <fieldset class="field field--radios">
                    <legend class="field__label">Сортировка</legend>
                    <?php foreach (['date' => 'По дате (новые)', 'price_asc' => 'По цене ↑', 'price_desc' => 'По цене ↓'] as $val => $label): ?>
                        <label class="radio">
                            <input type="radio" name="sort" value="<?= $val ?>"<?= $f['sort'] === $val ? ' checked' : '' ?>>
                            <span class="radio__mark"></span><span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <input type="hidden" name="user" value="<?= $f['user'] ?: '' ?>">

                <div class="filters__actions">
                    <button class="btn btn--primary btn--block" type="submit">Применить фильтры</button>
                    <button class="btn btn--text btn--block" type="button" id="filtersReset">Сбросить</button>
                </div>
            </form>
        </aside>

        <section class="results" aria-live="polite">
            <?php if ($seller): ?>
                <div class="seller-banner" id="sellerBanner">
                    <span class="avatar avatar--lg"><?= e($seller['initial']) ?></span>
                    <div>
                        <strong>Объявления продавца: <?= e($seller['name']) ?></strong>
                        <span><?= e($seller['member_since']) ?></span>
                    </div>
                    <a class="btn btn--outline btn--sm" href="/index.php">Показать все объявления</a>
                </div>
            <?php endif; ?>

            <div class="results__head">
                <div>
                    <h1 class="results__title" id="resultsTitle">Объявления</h1>
                    <p class="results__count" id="resultsCount">&nbsp;</p>
                </div>
                <button class="btn btn--outline filters-toggle" type="button" id="filtersOpen">
                    <?= View::icon('sliders') ?>Фильтры
                </button>
            </div>

            <div class="grid" id="adsGrid"></div>

            <div class="load-more" id="loadMoreBox" hidden>
                <button class="btn btn--outline btn--lg" type="button" id="loadMore">Показать ещё</button>
                <p class="load-more__info" id="shownInfo"></p>
            </div>
        </section>
    </div>
    <div class="sheet-overlay" id="sheetOverlay"></div>
</main>
<?php View::footer(); ?>

<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Ad;
use App\Auth;
use App\View;

$user = Auth::requireLogin();

// ?id=N — режим редактирования своего объявления
$editId = (int) ($_GET['id'] ?? 0);
$edit   = null;
if ($editId > 0) {
    $edit = Ad::get($editId, $user);
    if ($edit === null || !$edit['is_owner']) {
        View::notFound('Объявление не найдено или принадлежит другому пользователю.');
    }
    if (!$edit['can_edit']) {
        View::notFound('Заблокированное объявление нельзя редактировать.');
    }
}

$categories = View::categories();
$cities     = Ad::cities();
$form = [
    'id'          => $edit['id'] ?? 0,
    'title'       => $edit['title'] ?? '',
    'category_id' => $edit['category_id'] ?? 0,
    'price'       => $edit ? (string) $edit['price'] : '',
    'city'        => $edit['city'] ?? '',
    'description' => $edit['description'] ?? '',
    'images'      => array_map(static fn(array $i) => ['filename' => $i['filename'], 'url' => $i['thumb']], $edit['images'] ?? []),
];

View::header([
    'title'      => $edit ? 'Редактирование объявления' : 'Новое объявление',
    'page'       => 'create-ad',
    'categories' => false,
    'bodyClass'  => 'body-soft',
]);
?>
<main class="container form-page">
    <a class="back-link" href="<?= $edit ? '/my-ads.php' : '/index.php' ?>"><?= View::icon('arrow-left') ?>Назад</a>

    <div class="form-card" id="adFormCard">
        <h1><?= $edit ? 'Редактирование объявления' : 'Новое объявление' ?></h1>
        <?php if ($edit): ?>
            <p class="form-card__sub">После сохранения объявление снова пройдёт модерацию.</p>
        <?php else: ?>
            <p class="form-card__sub">Заполните информацию — после проверки модератором объявление появится в ленте.</p>
        <?php endif; ?>

        <form id="adForm" novalidate data-form="<?= e(View::json($form)) ?>">
            <div class="field">
                <label class="field__label" for="title">Заголовок</label>
                <input class="input" id="title" name="title" type="text" maxlength="100" placeholder="Например, iPhone 14 Pro 256 ГБ" required>
                <div class="field__meta"><p class="field__error" data-error-for="title"></p><span class="counter" data-counter-for="title"></span></div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label class="field__label" for="category_id">Категория</label>
                    <select class="select" id="category_id" name="category_id" required>
                        <option value="">Выберите категорию</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['icon'] . ' ' . $c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field__error" data-error-for="category_id"></p>
                </div>
                <div class="field">
                    <label class="field__label" for="price">Цена</label>
                    <div class="input-suffix">
                        <input class="input" id="price" name="price" type="text" inputmode="numeric" placeholder="0" autocomplete="off" required>
                        <span>₽</span>
                    </div>
                    <p class="field__hint">0 — отдаю бесплатно</p>
                    <p class="field__error" data-error-for="price"></p>
                </div>
            </div>

            <div class="field">
                <label class="field__label" for="city">Город</label>
                <input class="input" id="city" name="city" type="text" list="cityList" maxlength="80" placeholder="Например, Москва" autocomplete="off" required>
                <datalist id="cityList">
                    <?php foreach ($cities as $city): ?><option value="<?= e($city) ?>"><?php endforeach; ?>
                </datalist>
                <p class="field__error" data-error-for="city"></p>
            </div>

            <div class="field">
                <label class="field__label" for="description">Описание</label>
                <textarea class="textarea" id="description" name="description" rows="7" maxlength="5000"
                          placeholder="Расскажите о состоянии, комплектации, причине продажи — подробные объявления продаются быстрее" required></textarea>
                <div class="field__meta"><p class="field__error" data-error-for="description"></p><span class="counter" data-counter-for="description"></span></div>
            </div>

            <div class="field">
                <span class="field__label">Фотографии <span class="muted">(до <?= Ad::MAX_IMAGES ?>, первое — главное)</span></span>
                <div class="dropzone" id="dropzone" tabindex="0" role="button" aria-label="Загрузить фото">
                    <?= View::icon('camera', 'dropzone__icon') ?>
                    <strong>Перетащите фото сюда</strong>
                    <span>или <u>выберите файлы</u> · JPG, PNG, WEBP · до 5 МБ</span>
                    <input type="file" id="fileInput" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden>
                </div>
                <div class="previews" id="previews"></div>
                <p class="field__error" data-error-for="images"></p>
            </div>

            <div class="form-actions">
                <button class="btn btn--primary btn--lg" type="submit" id="submitBtn"><?= $edit ? 'Сохранить и отправить на модерацию' : 'Опубликовать' ?></button>
                <a class="btn btn--text btn--lg" href="<?= $edit ? '/my-ads.php' : '/index.php' ?>">Отмена</a>
            </div>
        </form>
    </div>
</main>
<?php View::footer(); ?>

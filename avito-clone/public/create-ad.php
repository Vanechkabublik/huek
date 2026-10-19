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
    'contact_method' => $edit['contact_method'] ?? 'any',
    'contact_hours'  => $edit['contact_hours'] ?? '',
    'video'       => !empty($edit['video']) ? ['filename' => $edit['video'], 'url' => $edit['video_url']] : null,
    'has_phone'   => !empty($user['phone']),
    'default_city' => $user['city'] ?? '',
    'attributes'  => $edit['attributes'] ?? new stdClass(),
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
                            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
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

            <div class="attrs" id="attrBox" hidden>
                <h2 class="attrs__title" id="attrTitle">Характеристики</h2>
                <div class="attrs__grid" id="attrFields"></div>
            </div>

            <div class="field">
                <label class="field__label" for="city">Город</label>
                <input class="input" id="city" name="city" type="text" maxlength="80" placeholder="Начните вводить: Москва, Казань…" autocomplete="off" required
                       data-city-input data-known-cities="<?= e(View::json($cities)) ?>">
                <p class="field__error" data-error-for="city"></p>
            </div>

            <div class="field">
                <label class="field__label" for="description">Описание</label>
                <textarea class="textarea" id="description" name="description" rows="7" maxlength="5000"
                          placeholder="Расскажите о состоянии, комплектации, причине продажи — подробные объявления продаются быстрее" required></textarea>
                <div class="field__meta"><p class="field__error" data-error-for="description"></p><span class="counter" data-counter-for="description"></span></div>
            </div>

            <fieldset class="field contact-method">
                <legend class="field__label">Предпочтительный способ связи</legend>
                <div class="choice-grid">
                    <label class="choice"><input type="radio" name="contact_method" value="any" checked>
                        <span class="choice__body"><?= View::icon('phone') ?><span><b>Звонок и сообщения</b><small>Покупатель выберет сам</small></span></span></label>
                    <label class="choice"><input type="radio" name="contact_method" value="chat">
                        <span class="choice__body"><?= View::icon('message') ?><span><b>Только сообщения</b><small>Чат на сайте, телефон скрыт</small></span></span></label>
                    <label class="choice"><input type="radio" name="contact_method" value="phone">
                        <span class="choice__body"><?= View::icon('phone') ?><span><b>Только звонок</b><small>Чат для этого объявления выключен</small></span></span></label>
                </div>
                <p class="field__error" data-error-for="contact_method"></p>
                <div class="field contact-hours">
                    <label class="field__label" for="contact_hours">Удобное время для связи <span class="muted">(необязательно)</span></label>
                    <input class="input" id="contact_hours" name="contact_hours" type="text" maxlength="60" placeholder="Например, с 9 до 21 по Москве">
                    <p class="field__error" data-error-for="contact_hours"></p>
                </div>
            </fieldset>

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

            <!-- Видеообзор — самый последний пункт формы, после фотографий -->
            <div class="field video-field" id="videoField">
                <span class="field__label">Видеообзор <span class="muted">(необязательно)</span></span>
                <div class="video-drop" id="videoDrop" tabindex="0" role="button" aria-label="Загрузить видеообзор">
                    <?= View::icon('video', 'dropzone__icon') ?>
                    <strong>Добавьте видео товара</strong>
                    <span>MP4, MOV или WebM · до 50 МБ · до 3 минут</span>
                    <input type="file" id="videoInput" accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm" hidden>
                </div>
                <div class="video-preview" id="videoPreview" hidden></div>
                <p class="field__error" data-error-for="video"></p>
            </div>

            <div class="form-actions">
                <button class="btn btn--primary btn--lg" type="submit" id="submitBtn"><?= $edit ? 'Сохранить и отправить на модерацию' : 'Опубликовать' ?></button>
                <a class="btn btn--text btn--lg" href="<?= $edit ? '/my-ads.php' : '/index.php' ?>">Отмена</a>
            </div>
        </form>
    </div>
</main>
<?php View::footer(); ?>

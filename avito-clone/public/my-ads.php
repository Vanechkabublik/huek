<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\View;

Auth::requireLogin();

View::header(['title' => 'Мои объявления', 'page' => 'my-ads', 'categories' => false]);
?>
<main id="main" class="container page-my">
    <div class="page-head">
        <div>
            <h1>Мои объявления</h1>
            <p class="muted" id="myCount">&nbsp;</p>
        </div>
        <a class="btn btn--primary" href="/create-ad.php"><?= View::icon('plus') ?>Разместить объявление</a>
    </div>

    <div class="tabs" id="myTabs" role="tablist">
        <button class="tab is-active" type="button" data-status="">Все <span class="tab__count">0</span></button>
        <button class="tab" type="button" data-status="pending">На модерации <span class="tab__count">0</span></button>
        <button class="tab" type="button" data-status="approved">Одобрены <span class="tab__count">0</span></button>
        <button class="tab" type="button" data-status="rejected">Отклонены <span class="tab__count">0</span></button>
        <button class="tab" type="button" data-status="blocked">Заблокированы <span class="tab__count">0</span></button>
    </div>

    <div class="grid grid--my" id="myGrid"></div>
</main>
<?php View::footer(); ?>

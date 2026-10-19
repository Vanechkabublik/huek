<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\View;

Auth::requireLogin();

View::header(['title' => 'Сообщения', 'page' => 'messages', 'categories' => false]);
?>
<main class="container page-messages">
    <div class="page-head">
        <h1>Сообщения</h1>
    </div>

    <div class="tabs" id="msgTabs" role="tablist">
        <button class="tab is-active" type="button" data-box="in">Входящие</button>
        <button class="tab" type="button" data-box="sent">Отправленные</button>
    </div>

    <div class="messages" id="msgList"></div>
</main>
<?php View::footer(); ?>

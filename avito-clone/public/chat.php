<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\View;

$user = Auth::requireLogin();

View::header(['title' => 'Сообщения', 'page' => 'chat', 'categories' => false, 'bodyClass' => 'body-chat']);
?>
<main class="container chat-page">
    <div class="chat" id="chat" data-open-ad="<?= (int) ($_GET['ad'] ?? 0) ?>" data-open-conv="<?= (int) ($_GET['c'] ?? 0) ?>">
        <aside class="chat__list" id="chatList" aria-label="Диалоги">
            <div class="chat__list-head"><h1>Сообщения</h1></div>
            <div class="chat__items" id="chatItems"></div>
        </aside>
        <section class="chat__thread" id="chatThread" aria-label="Переписка">
            <div class="chat__empty" id="chatEmpty">
                <div class="empty__icon"><?= View::icon('message') ?></div>
                <h2>Выберите диалог</h2>
                <p class="muted">Здесь появится переписка с покупателями и продавцами</p>
            </div>
        </section>
    </div>
</main>
<?php View::footer(['/assets/js/chat.js']); ?>

<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\View;

Auth::requireLogin();

View::header(['title' => 'Избранное', 'page' => 'favorites', 'categories' => false]);
?>
<main id="main" class="container page-my">
    <div class="page-head">
        <div>
            <h1>Избранное</h1>
            <p class="muted" id="favCount">&nbsp;</p>
        </div>
    </div>
    <div class="grid" id="favGrid"></div>
    <div class="load-more" id="favMoreBox" hidden><button class="btn btn--outline btn--lg" type="button" id="favMore">Показать ещё</button></div>
</main>
<?php View::footer(); ?>

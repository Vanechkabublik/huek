<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Legal;
use App\View;

/**
 * Юридические документы: /privacy.php (политика) и /terms.php (соглашение). Тексты редактируются в админке → «Документы».
 */
$slug = (string) ($slug ?? ($_GET['doc'] ?? ''));
$doc = Legal::get($slug);
if ($doc === null) {
    View::notFound('Такого документа нет.');
}

View::header(['title' => $doc['title'], 'page' => 'legal', 'categories' => false, 'bodyClass' => 'body-soft']);
?>
<main class="container legal">
    <article class="legal__card">
        <nav class="legal__tabs" aria-label="Документы">
            <?php foreach (Legal::SLUGS as $s => $title): ?>
                <a href="/<?= $s ?>.php"<?= $s === $slug ? ' class="is-active" aria-current="page"' : '' ?>><?= e($title) ?></a>
            <?php endforeach; ?>
        </nav>
        <h1><?= e($doc['title']) ?></h1>
        <p class="legal__meta">Редакция от <?= e(date('d.m.Y', (int) strtotime($doc['updated_at']))) ?></p>
        <div class="legal__body"><?= Legal::html($doc) ?></div>
    </article>
</main>
<?php View::footer(); ?>

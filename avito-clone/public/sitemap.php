<?php
declare(strict_types=1);

/** /sitemap.xml: главная, категории, документы и опубликованные объявления (до 5000 свежих). */
require __DIR__ . '/../src/bootstrap.php';

use App\Category;
use App\Database;
use App\OAuth;

$base = OAuth::appUrl();
$urls = [['/index.php', null], ['/privacy.php', null], ['/terms.php', null]];
foreach (Category::all() as $c) {
    $urls[] = ['/index.php?category=' . (int) $c['id'], null];
}
$rows = Database::all("SELECT id, updated_at FROM ads WHERE status = 'approved' ORDER BY id DESC LIMIT 5000");
foreach ($rows as $r) {
    $urls[] = ['/ad.php?id=' . (int) $r['id'], substr((string) $r['updated_at'], 0, 10)];
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach ($urls as [$path, $date]) {
    echo '  <url><loc>', htmlspecialchars($base . $path, ENT_XML1 | ENT_QUOTES, 'UTF-8'), '</loc>',
        $date ? '<lastmod>' . $date . '</lastmod>' : '', "</url>\n";
}
echo "</urlset>\n";

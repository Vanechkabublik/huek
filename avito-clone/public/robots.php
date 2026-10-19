<?php
declare(strict_types=1);

/** /robots.txt: личные страницы и API закрыты, карта сайта — /sitemap.xml. */
require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo "User-agent: *\n",
    "Disallow: /admin.php\n",
    "Disallow: /api/\n",
    "Disallow: /login.php\n",
    "Disallow: /register.php\n",
    "Disallow: /profile.php\n",
    "Disallow: /my-ads.php\n",
    "Disallow: /favorites.php\n",
    "Disallow: /chat.php\n",
    "Disallow: /create-ad.php\n",
    "Disallow: /ad-stats.php\n",
    "Disallow: /oauth-login.php\n",
    "Disallow: /oauth-callback.php\n",
    "\nSitemap: ", App\OAuth::appUrl(), "/sitemap.xml\n";

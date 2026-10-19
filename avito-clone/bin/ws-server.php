<?php
declare(strict_types=1);

/**
 * Запуск WebSocket-сервера чата:  php bin/ws-server.php
 * Переменные окружения: WS_BIND (по умолчанию 0.0.0.0), WS_PORT (8081), APP_SECRET, APP_URL / WS_ALLOWED_ORIGINS, DB_*.
 */

require __DIR__ . '/../src/bootstrap.php';

(new App\WebSocketServer((string) (getenv('WS_BIND') ?: '0.0.0.0'), (int) (getenv('WS_PORT') ?: 8081)))->run();

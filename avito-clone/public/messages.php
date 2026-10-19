<?php
declare(strict_types=1);

// Старый адрес раздела «Сообщения» — теперь это чат
require __DIR__ . '/../src/bootstrap.php';
App\Response::redirect('/chat.php');

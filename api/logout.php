<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

methode('POST');
$_SESSION = [];
$p = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires'  => time() - 3600,
    'path'     => $p['path'],
    'secure'   => $p['secure'],
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_destroy();
json_out(['ok' => true]);

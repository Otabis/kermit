<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

methode('GET');
if (empty($_SESSION['benutzer'])) {
    fehler('Nicht angemeldet', 401);
}
json_out(['ok' => true, 'benutzer' => $_SESSION['benutzer'], 'csrf' => csrf_token()]);

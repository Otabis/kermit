<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

methode('POST');
$in = eingabe();
$email = mb_strtolower(trim((string)($in['email'] ?? '')));
$pass  = (string)($in['passwort'] ?? '');

if ($email === '' || $pass === '') {
    fehler('E-Mail und Passwort eingeben');
}

$st = db()->prepare('SELECT id, email, name, passwort_hash, rolle, aktiv, fehlversuche, gesperrt_bis FROM benutzer WHERE email = ?');
$st->execute([$email]);
$u = $st->fetch();

$falsch = 'E-Mail oder Passwort falsch';

if (!$u || !$u['aktiv']) {
    // gleiche Rechenzeit wie bei echtem Benutzer, damit man nicht erraten kann, welche E-Mails existieren
    password_verify($pass, password_hash('kermit-dummy', PASSWORD_DEFAULT));
    fehler($falsch, 401);
}

if ($u['gesperrt_bis'] !== null && strtotime($u['gesperrt_bis']) > time()) {
    fehler('Zu viele Fehlversuche. Bitte später erneut versuchen.', 429);
}

if (!password_verify($pass, $u['passwort_hash'])) {
    $versuche = (int)$u['fehlversuche'] + 1;
    $sperre = null;
    if ($versuche >= $CONFIG['app']['max_versuche']) {
        $sperre = date('Y-m-d H:i:s', time() + $CONFIG['app']['sperre_min'] * 60);
        $versuche = 0;
    }
    db()->prepare('UPDATE benutzer SET fehlversuche = ?, gesperrt_bis = ? WHERE id = ?')
        ->execute([$versuche, $sperre, $u['id']]);
    fehler($falsch, 401);
}

// Hash bei Bedarf auf aktuellen Standard bringen
if (password_needs_rehash($u['passwort_hash'], PASSWORD_DEFAULT)) {
    db()->prepare('UPDATE benutzer SET passwort_hash = ? WHERE id = ?')
        ->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
}

db()->prepare('UPDATE benutzer SET fehlversuche = 0, gesperrt_bis = NULL, letzter_login = NOW() WHERE id = ?')
    ->execute([$u['id']]);

session_regenerate_id(true);
$_SESSION['benutzer'] = [
    'id'    => (int)$u['id'],
    'email' => $u['email'],
    'name'  => $u['name'],
    'rolle' => $u['rolle'],
];
unset($_SESSION['csrf']);

json_out(['ok' => true, 'benutzer' => $_SESSION['benutzer'], 'csrf' => csrf_token()]);

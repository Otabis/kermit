<?php
/**
 * KERMIT – Einmalige Einrichtung: ersten Admin anlegen
 *
 * Funktioniert nur, solange
 *   1. in config.local.php ein 'setup_token' gesetzt ist und
 *   2. noch KEIN Benutzer in der Datenbank existiert.
 * Danach sperrt sich die Seite selbst. Trotzdem nach Gebrauch löschen.
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$token  = (string)($CONFIG['setup_token'] ?? '');
$anzahl = (int)db()->query('SELECT COUNT(*) FROM benutzer')->fetchColumn();
$meldung = '';
$fertig = false;

if ($token === '' || strlen($token) < 16) {
    $gesperrt = 'Setup ist deaktiviert. In app/config.local.php einen setup_token (mind. 16 Zeichen) eintragen.';
} elseif ($anzahl > 0) {
    $gesperrt = 'Es existiert bereits ein Benutzer. Setup ist gesperrt – bitte setup.php löschen.';
} else {
    $gesperrt = '';
}

if ($gesperrt === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $name  = trim((string)($_POST['name'] ?? ''));
    $p1    = (string)($_POST['p1'] ?? '');
    $p2    = (string)($_POST['p2'] ?? '');

    if (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        $meldung = 'Setup-Token falsch.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
        $meldung = 'E-Mail und Name prüfen.';
    } elseif ($p1 !== $p2) {
        $meldung = 'Passwörter stimmen nicht überein.';
    } elseif (mb_strlen($p1) < 12) {
        $meldung = 'Passwort mind. 12 Zeichen.';
    } else {
        db()->prepare("INSERT INTO benutzer (email, name, passwort_hash, rolle) VALUES (?, ?, ?, 'admin')")
            ->execute([$email, $name, password_hash($p1, PASSWORD_DEFAULT)]);
        $fertig = true;
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de-CH">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>KERMIT – Einrichtung</title>
  <link rel="stylesheet" href="css/kermit-farben.css">
  <link rel="stylesheet" href="css/kermit-app.css">
</head>
<body>
  <main class="k-start">
    <div class="k-start-box">
      <h1 class="k-start-logo">KERMIT</h1>
      <p class="k-start-claim">Einrichtung · ersten Admin anlegen</p>

      <?php if ($fertig): ?>
        <p class="k-msg ok">Admin angelegt. Jetzt <b>setup.php löschen</b> und den setup_token aus config.local.php entfernen.</p>
        <p><a href="index.html" style="color:#fff">Zur Anmeldung</a></p>
      <?php elseif ($gesperrt !== ''): ?>
        <p class="k-msg"><?= $h($gesperrt) ?></p>
      <?php else: ?>
        <form class="k-login" method="post" autocomplete="off">
          <?php if ($meldung): ?><p class="k-login-err"><?= $h($meldung) ?></p><?php endif; ?>
          <label>Setup-Token <input type="password" name="token" required></label>
          <label>E-Mail <input type="email" name="email" required value="<?= $h((string)($_POST['email'] ?? '')) ?>"></label>
          <label>Name <input type="text" name="name" required value="<?= $h((string)($_POST['name'] ?? '')) ?>"></label>
          <label>Passwort (mind. 12 Zeichen) <input type="password" name="p1" required minlength="12" autocomplete="new-password"></label>
          <label>Passwort wiederholen <input type="password" name="p2" required minlength="12" autocomplete="new-password"></label>
          <button class="k-btn" type="submit">Admin anlegen</button>
        </form>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>

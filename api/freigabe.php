<?php
/**
 * POST   api/freigabe.php  {datum}   Tag freigeben
 * DELETE api/freigabe.php?datum=…   Freigabe zurücknehmen (nur solange nicht exportiert)
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$m = methode('POST', 'DELETE');
$u = login_pflicht();

if ($m === 'POST') {
    $datum = datum_param(eingabe()['datum'] ?? null);
    $st = db()->prepare('SELECT COUNT(*) FROM eintraege WHERE benutzer_id = ? AND ende IS NULL AND DATE(beginn) = ?');
    $st->execute([$u['id'], $datum]);
    if ((int)$st->fetchColumn() > 0) {
        fehler('Zuerst laufenden Timer stoppen', 409);
    }
    db()->prepare('INSERT IGNORE INTO tagesfreigaben (benutzer_id, datum) VALUES (?, ?)')
        ->execute([$u['id'], $datum]);
    json_out(['ok' => true]);
}

$datum = datum_param($_GET['datum'] ?? null);
$st = db()->prepare('DELETE FROM tagesfreigaben WHERE benutzer_id = ? AND datum = ? AND exportiert_am IS NULL');
$st->execute([$u['id'], $datum]);
if ($st->rowCount() === 0) {
    fehler('Freigabe nicht vorhanden oder bereits exportiert', 409);
}
json_out(['ok' => true]);

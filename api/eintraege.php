<?php
/**
 * POST   api/eintraege.php   {aktion:"start", zeitkonto, kunde_id, projekt_id, leistungsart_id, notiz}
 * POST   api/eintraege.php   {aktion:"stop",  zeitkonto, notiz?}
 * POST   api/eintraege.php   {aktion:"neu",   zeitkonto, kunde_id, projekt_id, leistungsart_id, beginn, ende, km?, notiz?}
 * DELETE api/eintraege.php?id=123
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$m = methode('POST', 'DELETE');
$u = login_pflicht();
$pdo = db();

function konto(array $in): string
{
    return ($in['zeitkonto'] ?? 'standard') === 'pikett' ? 'pikett' : 'standard';
}

function zuordnung_pruefen(array $in): array
{
    $leistung = (int)($in['leistungsart_id'] ?? 0);
    $kunde    = isset($in['kunde_id'])   && $in['kunde_id']   !== '' ? (int)$in['kunde_id']   : null;
    $projekt  = isset($in['projekt_id']) && $in['projekt_id'] !== '' ? (int)$in['projekt_id'] : null;

    $st = db()->prepare('SELECT typ FROM leistungsarten WHERE id = ? AND aktiv = 1');
    $st->execute([$leistung]);
    $typ = $st->fetchColumn();
    if ($typ === false) {
        fehler('Leistung wählen');
    }
    if ($typ === 'pause') {
        $kunde = null;     // Pause gehört keinem Kunden
        $projekt = null;
    } elseif ($projekt === null) {
        fehler('Projekt wählen');
    }
    if ($projekt !== null) {
        $st = db()->prepare('SELECT kunde_id FROM projekte WHERE id = ? AND aktiv = 1');
        $st->execute([$projekt]);
        $k = $st->fetchColumn();
        if ($k === false || ($kunde !== null && (int)$k !== $kunde)) {
            fehler('Projekt passt nicht zum Kunden');
        }
        $kunde = (int)$k;
    }
    $notiz = mb_substr(trim((string)($in['notiz'] ?? '')), 0, 500);
    return [$kunde, $projekt, $leistung, $notiz === '' ? null : $notiz];
}

function zeit(string $wert): string
{
    $t = strtotime($wert);
    if ($t === false) {
        fehler('Ungültige Zeit');
    }
    return date('Y-m-d H:i:s', $t);
}

// ---------- Löschen ----------
if ($m === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT DATE(beginn) FROM eintraege WHERE id = ? AND benutzer_id = ?');
    $st->execute([$id, $u['id']]);
    $tag = $st->fetchColumn();
    if ($tag === false) {
        fehler('Eintrag nicht gefunden', 404);
    }
    if (tag_freigegeben($u['id'], $tag)) {
        fehler('Tag ist freigegeben und gesperrt', 409);
    }
    $pdo->prepare('DELETE FROM eintraege WHERE id = ? AND benutzer_id = ?')->execute([$id, $u['id']]);
    json_out(['ok' => true]);
}

// ---------- Anlegen / Timer ----------
$in = eingabe();
$konto = konto($in);
$aktion = $in['aktion'] ?? '';

if (tag_freigegeben($u['id'], date('Y-m-d')) && $aktion !== 'neu') {
    fehler('Heute ist bereits freigegeben', 409);
}

switch ($aktion) {
    case 'start':
        [$kunde, $projekt, $leistung, $notiz] = zuordnung_pruefen($in);
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT id FROM eintraege WHERE benutzer_id = ? AND zeitkonto = ? AND ende IS NULL FOR UPDATE');
        $st->execute([$u['id'], $konto]);
        if ($st->fetchColumn()) {
            $pdo->rollBack();
            fehler('Es läuft bereits ein Timer', 409);
        }
        $pdo->prepare(
            "INSERT INTO eintraege (benutzer_id, zeitkonto, kunde_id, projekt_id, leistungsart_id, beginn, quelle, notiz)
             VALUES (?, ?, ?, ?, ?, NOW(), 'timer', ?)"
        )->execute([$u['id'], $konto, $kunde, $projekt, $leistung, $notiz]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        json_out(['ok' => true, 'id' => $id]);

    case 'stop':
        $notiz = trim((string)($in['notiz'] ?? ''));
        $st = $pdo->prepare(
            'UPDATE eintraege
                SET ende = NOW(), notiz = COALESCE(NULLIF(?, \'\'), notiz)
              WHERE benutzer_id = ? AND zeitkonto = ? AND ende IS NULL'
        );
        $st->execute([mb_substr($notiz, 0, 500), $u['id'], $konto]);
        if ($st->rowCount() === 0) {
            fehler('Kein laufender Timer', 409);
        }
        json_out(['ok' => true]);

    case 'neu':
        [$kunde, $projekt, $leistung, $notiz] = zuordnung_pruefen($in);
        $beginn = zeit((string)($in['beginn'] ?? ''));
        $ende   = zeit((string)($in['ende'] ?? ''));
        if ($ende <= $beginn) {
            fehler('Ende muss nach Beginn liegen');
        }
        if (substr($beginn, 0, 10) !== substr($ende, 0, 10)) {
            fehler('Eintrag muss am selben Tag enden');
        }
        if (tag_freigegeben($u['id'], substr($beginn, 0, 10))) {
            fehler('Tag ist freigegeben und gesperrt', 409);
        }
        $km = max(0, min(9999, (float)($in['km'] ?? 0)));
        $vm = in_array($in['verkehrsmittel'] ?? null, ['auto', 'oev'], true) ? $in['verkehrsmittel'] : null;
        $pdo->prepare(
            "INSERT INTO eintraege (benutzer_id, zeitkonto, kunde_id, projekt_id, leistungsart_id, beginn, ende, km, verkehrsmittel, quelle, notiz)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'manuell', ?)"
        )->execute([$u['id'], $konto, $kunde, $projekt, $leistung, $beginn, $ende, $km, $vm, $notiz]);
        json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);

    default:
        fehler('Unbekannte Aktion');
}

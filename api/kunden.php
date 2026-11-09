<?php
/**
 * KERMIT – Kunden, Aufträge und Zeitarten verwalten
 *
 * GET  api/kunden.php                    alles (inkl. ausgeblendete) für die Verwaltungsseite
 * POST api/kunden.php {aktion: ...}      Änderungen (nur Rolle admin)
 *
 *   kunde_speichern     {id?, name, kundennr, adresse, comatic_nr, aktiv}
 *   kunde_loeschen      {id}            nur ohne Aufträge und Buchungen
 *   projekt_speichern   {id?, kunde_id, name, comatic_nr, aktiv}
 *   projekt_loeschen    {id}            nur ohne Buchungen
 *   zeitart_speichern   {id, aktiv, verrechenbar, kunden: [kunde_id, ...]}
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$m = methode('GET', 'POST');
$u = login_pflicht();
$pdo = db();

if ($m === 'GET') {
    $kunden = $pdo->query(
        'SELECT k.id, k.name, k.kundennr, k.adresse, k.comatic_nr, k.aktiv,
                (SELECT COUNT(*) FROM eintraege e WHERE e.kunde_id = k.id) AS buchungen
           FROM kunden k ORDER BY k.name'
    )->fetchAll();
    $projekte = $pdo->query(
        'SELECT p.id, p.kunde_id, p.name, p.comatic_nr, p.aktiv,
                (SELECT COUNT(*) FROM eintraege e WHERE e.projekt_id = p.id) AS buchungen,
                (SELECT MAX(e.beginn) FROM eintraege e WHERE e.projekt_id = p.id) AS letzte_buchung
           FROM projekte p ORDER BY p.name'
    )->fetchAll();
    $zeitarten = $pdo->query(
        'SELECT id, name, typ, comatic_code, verrechenbar, preis_std, abrechnungsart, aktiv
           FROM leistungsarten ORDER BY sortierung, name'
    )->fetchAll();
    $zuordnung = $pdo->query('SELECT leistungsart_id, kunde_id FROM leistungsart_kunden')->fetchAll();

    $proKunde = [];
    foreach ($projekte as $p) {
        $proKunde[(int)$p['kunde_id']][] = [
            'id' => (int)$p['id'], 'name' => $p['name'], 'comatic_nr' => $p['comatic_nr'],
            'aktiv' => (bool)$p['aktiv'], 'buchungen' => (int)$p['buchungen'],
            'letzte_buchung' => $p['letzte_buchung'],
        ];
    }
    foreach ($kunden as &$k) {
        $k['id'] = (int)$k['id'];
        $k['aktiv'] = (bool)$k['aktiv'];
        $k['buchungen'] = (int)$k['buchungen'];
        $k['projekte'] = $proKunde[$k['id']] ?? [];
    }
    unset($k);

    $nurFuer = [];
    foreach ($zuordnung as $z) {
        $nurFuer[(int)$z['leistungsart_id']][] = (int)$z['kunde_id'];
    }
    foreach ($zeitarten as &$z) {
        $z['id'] = (int)$z['id'];
        $z['aktiv'] = (bool)$z['aktiv'];
        $z['verrechenbar'] = (bool)$z['verrechenbar'];
        $z['preis_std'] = $z['preis_std'] === null ? null : (float)$z['preis_std'];
        $z['kunden'] = $nurFuer[$z['id']] ?? [];
    }
    unset($z);

    json_out([
        'ok' => true,
        'darf_bearbeiten' => $u['rolle'] === 'admin',
        'kunden' => $kunden,
        'zeitarten' => $zeitarten,
    ]);
}

// ---------- Änderungen: nur Admin ----------
if ($u['rolle'] !== 'admin') {
    fehler('Nur Administratoren dürfen Stammdaten ändern', 403);
}

$in = eingabe();
$aktion = (string)($in['aktion'] ?? '');

function text(array $in, string $feld, int $max, bool $pflicht = false): ?string
{
    $v = trim((string)($in[$feld] ?? ''));
    $v = preg_replace('/\s+/u', ' ', $v) ?? '';
    if ($v === '') {
        if ($pflicht) {
            fehler(ucfirst($feld) . ' fehlt');
        }
        return null;
    }
    return mb_substr($v, 0, $max);
}

function eindeutig(string $tabelle, string $spalte, ?string $wert, int $id, string $meldung): void
{
    if ($wert === null) {
        return;
    }
    $st = db()->prepare("SELECT 1 FROM $tabelle WHERE $spalte = ? AND id <> ?");
    $st->execute([$wert, $id]);
    if ($st->fetchColumn()) {
        fehler($meldung, 409);
    }
}

switch ($aktion) {
    case 'kunde_speichern':
        $id      = (int)($in['id'] ?? 0);
        $name    = text($in, 'name', 160, true);
        $nr      = text($in, 'kundennr', 20);
        $adresse = text($in, 'adresse', 255);
        $comatic = text($in, 'comatic_nr', 40);
        $aktiv   = !empty($in['aktiv']) ? 1 : 0;
        eindeutig('kunden', 'comatic_nr', $comatic, $id, 'Diese Comatic-Adress_ID ist schon einem anderen Kunden zugeordnet');
        eindeutig('kunden', 'kundennr', $nr, $id, 'Diese Kundennummer gibt es schon');
        if ($id > 0) {
            $pdo->prepare('UPDATE kunden SET name = ?, kundennr = ?, adresse = ?, comatic_nr = ?, aktiv = ? WHERE id = ?')
                ->execute([$name, $nr, $adresse, $comatic, $aktiv, $id]);
        } else {
            $pdo->prepare('INSERT INTO kunden (name, kundennr, adresse, comatic_nr, aktiv) VALUES (?, ?, ?, ?, ?)')
                ->execute([$name, $nr, $adresse, $comatic, $aktiv]);
            $id = (int)$pdo->lastInsertId();
        }
        json_out(['ok' => true, 'id' => $id]);

    case 'kunde_loeschen':
        $id = (int)($in['id'] ?? 0);
        $st = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM projekte WHERE kunde_id = ?)
                  + (SELECT COUNT(*) FROM eintraege WHERE kunde_id = ?)
                  + (SELECT COUNT(*) FROM spesen WHERE kunde_id = ?)'
        );
        $st->execute([$id, $id, $id]);
        if ((int)$st->fetchColumn() > 0) {
            fehler('Kunde hat noch Aufträge oder Buchungen – bitte ausblenden statt löschen', 409);
        }
        $pdo->prepare('DELETE FROM kunden WHERE id = ?')->execute([$id]);
        json_out(['ok' => true]);

    case 'projekt_speichern':
        $id      = (int)($in['id'] ?? 0);
        $kunde   = (int)($in['kunde_id'] ?? 0);
        $name    = text($in, 'name', 160, true);
        $comatic = text($in, 'comatic_nr', 40);
        $aktiv   = !empty($in['aktiv']) ? 1 : 0;
        $st = $pdo->prepare('SELECT 1 FROM kunden WHERE id = ?');
        $st->execute([$kunde]);
        if (!$st->fetchColumn()) {
            fehler('Kunde nicht gefunden', 404);
        }
        eindeutig('projekte', 'comatic_nr', $comatic, $id, 'Diese Comatic-Auftrags-Nr. gibt es schon');
        if ($id > 0) {
            $pdo->prepare('UPDATE projekte SET kunde_id = ?, name = ?, comatic_nr = ?, aktiv = ? WHERE id = ?')
                ->execute([$kunde, $name, $comatic, $aktiv, $id]);
        } else {
            $pdo->prepare('INSERT INTO projekte (kunde_id, name, comatic_nr, aktiv) VALUES (?, ?, ?, ?)')
                ->execute([$kunde, $name, $comatic, $aktiv]);
            $id = (int)$pdo->lastInsertId();
        }
        json_out(['ok' => true, 'id' => $id]);

    case 'projekt_loeschen':
        $id = (int)($in['id'] ?? 0);
        $st = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM eintraege WHERE projekt_id = ?)
                  + (SELECT COUNT(*) FROM spesen WHERE projekt_id = ?)'
        );
        $st->execute([$id, $id]);
        if ((int)$st->fetchColumn() > 0) {
            fehler('Auf diesen Auftrag wurde schon gebucht – bitte ausblenden statt löschen', 409);
        }
        $pdo->prepare('DELETE FROM projekte WHERE id = ?')->execute([$id]);
        json_out(['ok' => true]);

    case 'zeitart_speichern':
        $id = (int)($in['id'] ?? 0);
        $st = $pdo->prepare('SELECT typ FROM leistungsarten WHERE id = ?');
        $st->execute([$id]);
        $typ = $st->fetchColumn();
        if ($typ === false) {
            fehler('Zeitart nicht gefunden', 404);
        }
        $kunden = array_values(array_unique(array_map('intval', (array)($in['kunden'] ?? []))));
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE leistungsarten SET aktiv = ?, verrechenbar = ? WHERE id = ?')
            ->execute([!empty($in['aktiv']) ? 1 : 0, !empty($in['verrechenbar']) ? 1 : 0, $id]);
        $pdo->prepare('DELETE FROM leistungsart_kunden WHERE leistungsart_id = ?')->execute([$id]);
        if ($kunden && in_array($typ, ['pause', 'abwesenheit'], true)) {
            $pdo->rollBack();
            fehler('Pausen und Abwesenheiten können keinem Kunden zugeordnet werden');
        }
        $ins = $pdo->prepare('INSERT INTO leistungsart_kunden (leistungsart_id, kunde_id) SELECT ?, id FROM kunden WHERE id = ?');
        foreach ($kunden as $k) {
            $ins->execute([$id, $k]);
        }
        $pdo->commit();
        json_out(['ok' => true]);

    default:
        fehler('Unbekannte Aktion');
}

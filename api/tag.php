<?php
/**
 * GET api/tag.php?datum=YYYY-MM-DD&zeitkonto=standard|pikett
 * Liefert alle Einträge, Kennzahlen, Freigabestatus und einen laufenden Timer.
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

methode('GET');
$u = login_pflicht();
$datum = datum_param($_GET['datum'] ?? null);
$konto = ($_GET['zeitkonto'] ?? 'standard') === 'pikett' ? 'pikett' : 'standard';

$st = db()->prepare(
    "SELECT e.id, e.beginn, e.ende, e.km, e.verkehrsmittel, e.quelle, e.notiz,
            e.kunde_id, k.name AS kunde, e.projekt_id, p.name AS projekt,
            e.leistungsart_id, l.name AS leistung, l.typ
       FROM eintraege e
       JOIN leistungsarten l ON l.id = e.leistungsart_id
  LEFT JOIN kunden k   ON k.id = e.kunde_id
  LEFT JOIN projekte p ON p.id = e.projekt_id
      WHERE e.benutzer_id = ? AND e.zeitkonto = ?
        AND e.beginn >= ? AND e.beginn < ?
   ORDER BY e.beginn"
);
$bis = (new DateTime($datum))->modify('+1 day')->format('Y-m-d');
$st->execute([$u['id'], $konto, $datum . ' 00:00:00', $bis . ' 00:00:00']);
$rows = $st->fetchAll();

$arbeit = 0; $tel = 0; $km = 0.0; $laufend = null;
foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['km'] = (float)$r['km'];
    $ende = $r['ende'] ?? date('Y-m-d H:i:s');
    $r['minuten'] = max(0, intdiv(strtotime($ende) - strtotime($r['beginn']), 60));
    if ($r['ende'] === null) {
        $laufend = $r;
        continue;
    }
    if ($r['typ'] !== 'pause') { $arbeit += $r['minuten']; }
    if ($r['typ'] === 'telefon') { $tel += $r['minuten']; }
    $km += $r['km'];
}
unset($r);

$sp = db()->prepare('SELECT COALESCE(SUM(betrag_chf),0) FROM spesen WHERE benutzer_id = ? AND datum = ?');
$sp->execute([$u['id'], $datum]);

json_out([
    'ok'           => true,
    'datum'        => $datum,
    'zeitkonto'    => $konto,
    'eintraege'    => $rows,
    'laufend'      => $laufend,
    'summen'       => [
        'arbeit_min'  => $arbeit,
        'telefon_min' => $tel,
        'km'          => round($km, 1),
        'spesen_chf'  => (float)$sp->fetchColumn(),
    ],
    'freigegeben'  => tag_freigegeben($u['id'], $datum),
    'server_zeit'  => date('c'),
]);

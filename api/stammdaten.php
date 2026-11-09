<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

methode('GET');
login_pflicht();

$kunden = db()->query('SELECT id, name, kundennr FROM kunden WHERE aktiv = 1 ORDER BY name')->fetchAll();
$projekte = db()->query('SELECT id, kunde_id, name FROM projekte WHERE aktiv = 1 ORDER BY name')->fetchAll();
$leistungen = db()->query('SELECT id, name, typ FROM leistungsarten WHERE aktiv = 1 ORDER BY sortierung, name')->fetchAll();

// Zeitarten, die nur für bestimmte Kunden gelten (Tabelle ab kunden-projekte-v1.sql)
$nurFuer = [];
try {
    foreach (db()->query('SELECT leistungsart_id, kunde_id FROM leistungsart_kunden') as $z) {
        $nurFuer[(int)$z['leistungsart_id']][] = (int)$z['kunde_id'];
    }
} catch (PDOException) {
    // Tabelle noch nicht angelegt: alle Zeitarten gelten für alle Kunden
}

$proKunde = [];
foreach ($projekte as $p) {
    $proKunde[(int)$p['kunde_id']][] = ['id' => (int)$p['id'], 'name' => $p['name']];
}
foreach ($kunden as &$k) {
    $k['id'] = (int)$k['id'];
    $k['projekte'] = $proKunde[$k['id']] ?? [];
}
unset($k);
foreach ($leistungen as &$l) {
    $l['id'] = (int)$l['id'];
    $l['kunden'] = $nurFuer[$l['id']] ?? [];   // leer = für alle Kunden
}
unset($l);

json_out(['ok' => true, 'kunden' => $kunden, 'leistungsarten' => $leistungen]);

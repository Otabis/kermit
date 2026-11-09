<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

methode('GET');
login_pflicht();

$kunden = db()->query('SELECT id, name FROM kunden WHERE aktiv = 1 ORDER BY name')->fetchAll();
$projekte = db()->query('SELECT id, kunde_id, name FROM projekte WHERE aktiv = 1 ORDER BY name')->fetchAll();
$leistungen = db()->query('SELECT id, name, typ FROM leistungsarten WHERE aktiv = 1 ORDER BY sortierung, name')->fetchAll();

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
}
unset($l);

json_out(['ok' => true, 'kunden' => $kunden, 'leistungsarten' => $leistungen]);

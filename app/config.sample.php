<?php
/**
 * KERMIT – Konfiguration (Vorlage)
 *
 * Kopieren nach  app/config.local.php  und Werte eintragen.
 * config.local.php ist in .gitignore und landet NIE auf GitHub.
 */
return [
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'kermit',
        'user'    => 'kermit_user',
        'pass'    => 'HIER-PASSWORT',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'timezone'      => 'Europe/Zurich',
        'session_name'  => 'KERMITSESS',
        'session_hours' => 12,     // Arbeitstag
        'max_versuche'  => 5,      // Fehlversuche bis Sperre
        'sperre_min'    => 15,     // Sperrdauer in Minuten
        'debug'         => false,  // nur lokal auf true
    ],
];

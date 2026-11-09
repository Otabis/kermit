<?php
/**
 * KERMIT – Grundgerüst für alle API-Aufrufe
 * PHP 8.2+ (empfohlen: neueste Version im Plesk), MariaDB 10.6+
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('KERMIT benötigt PHP 8.2 oder neuer.');
}

$configFile = __DIR__ . '/config.local.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Konfiguration fehlt: app/config.local.php (Vorlage: config.sample.php)');
}
$CONFIG = require $configFile;

date_default_timezone_set($CONFIG['app']['timezone']);
error_reporting(E_ALL);
ini_set('display_errors', $CONFIG['app']['debug'] ? '1' : '0');

// ---------- Session (sicher konfiguriert) ----------
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name($CONFIG['app']['session_name']);
session_set_cookie_params([
    'lifetime' => $CONFIG['app']['session_hours'] * 3600,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', (string)($CONFIG['app']['session_hours'] * 3600));
session_start();

// ---------- Datenbank ----------
function db(): PDO
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $c = $CONFIG['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Zeitzone der Verbindung an PHP angleichen
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    }
    return $pdo;
}

// ---------- Antworten ----------
function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fehler(string $meldung, int $status = 400): never
{
    json_out(['ok' => false, 'fehler' => $meldung], $status);
}

function eingabe(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fehler('Ungültige Eingabe');
    }
    return $data;
}

// ---------- Sicherheit ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_pruefen(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        return;
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        fehler('Sitzung abgelaufen, bitte neu laden', 419);
    }
}

function login_pflicht(): array
{
    if (empty($_SESSION['benutzer'])) {
        fehler('Nicht angemeldet', 401);
    }
    csrf_pruefen();
    return $_SESSION['benutzer'];
}

function methode(string ...$erlaubt): string
{
    $m = $_SERVER['REQUEST_METHOD'];
    if (!in_array($m, $erlaubt, true)) {
        header('Allow: ' . implode(', ', $erlaubt));
        fehler('Methode nicht erlaubt', 405);
    }
    return $m;
}

// ---------- Hilfen ----------
function datum_param(?string $wert): string
{
    $wert ??= date('Y-m-d');
    $d = DateTime::createFromFormat('!Y-m-d', $wert);
    if (!$d || $d->format('Y-m-d') !== $wert) {
        fehler('Ungültiges Datum');
    }
    return $wert;
}

function tag_freigegeben(int $benutzerId, string $datum): bool
{
    $st = db()->prepare('SELECT 1 FROM tagesfreigaben WHERE benutzer_id = ? AND datum = ?');
    $st->execute([$benutzerId, $datum]);
    return (bool)$st->fetchColumn();
}

// Unerwartete Fehler sauber als JSON zurückgeben
set_exception_handler(function (Throwable $e) use ($CONFIG): void {
    error_log('[KERMIT] ' . $e);
    $msg = $CONFIG['app']['debug'] ? $e->getMessage() : 'Interner Fehler';
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'fehler' => $msg], JSON_UNESCAPED_UNICODE);
});

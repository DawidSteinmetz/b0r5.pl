<?php
declare(strict_types=1);

function polaczenie_bazy(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = getenv('HOLOLINK_DB_DSN') ?: '';
    $uzytkownik = getenv('HOLOLINK_DB_USER') ?: '';
    $haslo = getenv('HOLOLINK_DB_PASSWORD') ?: '';
    if ($dsn === '' && is_file(__DIR__ . '/konfiguracja-bazy.php')) {
        $konfiguracja = require __DIR__ . '/konfiguracja-bazy.php';
        if (is_array($konfiguracja)) {
            $dsn = (string) ($konfiguracja['dsn'] ?? '');
            $uzytkownik = (string) ($konfiguracja['uzytkownik'] ?? '');
            $haslo = (string) ($konfiguracja['haslo'] ?? '');
        }
    }
    if ($dsn === '') throw new RuntimeException('Brak konfiguracji bazy danych.');

    $pdo = new PDO($dsn, $uzytkownik, $haslo, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

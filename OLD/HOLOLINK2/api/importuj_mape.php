<?php
// Import/aktualizacja publicznej mapy galaktyki do tabel SQL.
// Uruchom z katalogu repo: php api/importuj_mape.php
declare(strict_types=1);

require_once __DIR__ . '/polaczenie.php';
$pdo = polaczenie_bazy();
$seed = file(__DIR__ . '/mapa_seed.sql', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$pdo->beginTransaction();
try {
    $pdo->exec('DELETE FROM mapa_planety');
    $pdo->exec('DELETE FROM mapa_szlaki');
    foreach ($seed as $sql) $pdo->exec($sql);
    $pdo->commit();
    echo "Dane mapy zaimportowano z " . count($seed) . " poleceń SQL.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

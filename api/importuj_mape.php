<?php
// Uzupełnia publiczną mapę galaktyki w SQL danymi z api/mapa_seed.sql.
// Uruchom z katalogu repo: php api/importuj_mape.php
//
// Skrypt NIGDY nie kasuje ani nie nadpisuje danych w bazie:
//  - planety z pliku są dopisywane tylko wtedy, gdy ich brakuje (INSERT IGNORE po id i nazwie),
//    więc planety dodane przez MG, migracje i prywatne paczki zostają nietknięte;
//  - szlaki są wstawiane tylko do pustej tabeli mapa_szlaki (szlaki nie mają klucza naturalnego).
declare(strict_types=1);

require_once __DIR__ . '/polaczenie.php';
$pdo = polaczenie_bazy();
$seed = file(__DIR__ . '/mapa_seed.sql', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$szlakiPuste = (int) $pdo->query('SELECT COUNT(*) FROM mapa_szlaki')->fetchColumn() === 0;

$planety = 0;
$szlaki = 0;
$pdo->beginTransaction();
try {
    foreach ($seed as $sql) {
        if (str_starts_with($sql, 'INSERT INTO mapa_planety ')) {
            $planety += $pdo->exec('INSERT IGNORE INTO mapa_planety ' . substr($sql, strlen('INSERT INTO mapa_planety ')));
        } elseif (str_starts_with($sql, 'INSERT INTO mapa_szlaki ')) {
            if ($szlakiPuste) $szlaki += $pdo->exec($sql);
        } else {
            throw new RuntimeException('Nieoczekiwane polecenie w mapa_seed.sql: ' . substr($sql, 0, 60));
        }
    }
    $pdo->commit();
    echo "Dopisano planet: {$planety}. Dopisano szlaków: {$szlaki}"
        . ($szlakiPuste ? '' : ' (tabela szlaków nie była pusta — pominięto)') . ".\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

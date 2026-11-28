<?php
// Jednorazowy import istniejących dokumentów danych z api/dane/ do SQL.
// Istniejących rekordów nie nadpisuje; najpierw wykonaj kopię bazy i plików.
declare(strict_types=1);

require_once __DIR__ . '/polaczenie.php';
$pdo = polaczenie_bazy();
$q = $pdo->prepare('INSERT IGNORE INTO datapad_dane (nazwa,payload) VALUES (?,?)');
$ile = 0;
foreach (glob(__DIR__ . '/dane/*.php') ?: [] as $plik) {
    $nazwa = basename($plik, '.php');
    $tekst = (string) file_get_contents($plik);
    $str = "<?php http_response_code(404); exit; ?>\n";
    if (str_starts_with($tekst, $str)) $tekst = substr($tekst, strlen($str));
    $dane = json_decode($tekst, true, 512, JSON_THROW_ON_ERROR);
    $q->execute([$nazwa, json_encode($dane, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    $ile += $q->rowCount();
}
echo "Zaimportowano " . $ile . " rekordów; istniejące rekordy pominięto.\n";

<?php
// Radio (GET): szufla, czyli lista utworów, losowa kolejność i czas serwera.
// Przeglądarka sama liczy, który utwór i od której sekundy gra teraz (patrz assets/js/rdzen.js).
require __DIR__ . '/_radio.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ust = array_merge(['wlaczone' => true, 'tytul' => true, 'glosnosc' => 0.6], is_array(postac()['radio'] ?? null) ? postac()['radio'] : []);
try {
    $s = $ust['wlaczone'] ? radio_szufla() : null;
} catch (Throwable $e) {
    $s = null;
}
$wyj = ['czas' => (int)round(microtime(true) * 1000), 'ustawienia' => ['tytul' => (bool)$ust['tytul'], 'glosnosc' => (float)$ust['glosnosc']]];
if (!$s || empty($s['utwory'])) {
    echo json_encode($wyj + ['utwory' => [], 'kolejnosc' => []]);
    exit;
}
echo json_encode($wyj + [
    'sciezka' => radio_sciezka() . '/', 'wersja' => $s['wersja'], 'epoka' => $s['epoka'], 'razem' => $s['razem'],
    'utwory' => $s['utwory'], 'kolejnosc' => $s['kolejnosc'], 'wydarzenia' => $s['wydarzenia'] ?? [],
], JSON_UNESCAPED_UNICODE);

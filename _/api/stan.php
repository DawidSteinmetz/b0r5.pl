<?php
// Wspólny stan świata. GET: stan widoczny dla wszystkich (odpytywany co kilka sekund).
// POST: {akcja: "otworz", id: "pokoj/folder/nazwa", gracz} albo {akcja: "przenies", id: plik "on", do: plik docelowy, gracz}.
require __DIR__ . '/_baza.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function wyjscie($d, $kod = 200) {
    http_response_code($kod);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $s = stan();
} catch (Throwable $e) {
    wyjscie(['blad' => 'brak bazy'], 503);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wyjscie([
        'czas' => (int)round(microtime(true) * 1000), 'strefa' => postac()['strefa'],
        'przeczytane' => $s['przeczytane'], 'miejsca' => $s['miejsca'], 'listy' => skrzynka_licznik(),
    ]);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) wyjscie(['blad' => 'zle zadanie'], 400);
$akcja = $in['akcja'] ?? '';
$gracz = substr(preg_replace('/[^a-z0-9]/', '', strtolower((string)($in['gracz'] ?? ''))), 0, 12);
$kid = is_string($in['id'] ?? null) ? $in['id'] : '';
try {
    if (za_szybko()) wyjscie(['blad' => 'zbyt szybko', 'tekst' => 'Spokojnie, nic tu nie dzieje się tak szybko.'], 429);

    if ($akcja === 'otworz') {
        if (sciezka_elementu($kid) && !in_array($kid, $s['przeczytane'], true)) dodaj_zdarzenie('otwarto', $kid, $gracz);
        wyjscie(['ok' => true]);
    }

    if ($akcja === 'przenies') {
        $grupa = przenosne_grupa($kid);
        $do = is_string($in['do'] ?? null) ? $in['do'] : '';
        if ($grupa === null || !in_array($do, przenosne_grupy()[$grupa]['miejsca'], true)) wyjscie(['ok' => false, 'tekst' => 'Tego nie da się tam przenieść.']);
        if (($s['miejsca'][$grupa] ?? '') !== $kid) wyjscie(['ok' => false, 'tekst' => 'Tego już tu nie ma.']);
        if ($do === $kid) wyjscie(['ok' => false, 'tekst' => 'To już tu leży.']);
        dodaj_zdarzenie('przeniesiono', $grupa, $gracz, ['do' => $do]);
        wyjscie(['ok' => true, 'tekst' => 'Przeniesione. Leży teraz ' . pokoj_gdzie(explode('/', $do)[0]) . '.']);
    }
    wyjscie(['blad' => 'nieznana akcja'], 400);
} catch (Throwable $e) {
    wyjscie(['blad' => 'blad serwera'], 500);
}

<?php
// Ogród. GET: pogoda, działka, grządki, skrzynka, sklep. POST {akcja, gracz, ...}:
// czar {czar, miejsce? albo id?} | posadz {id, paczka} | podlej {id} | plej {id} | zbierz {id} | wyrwij {id} | kup {dostawca, towary: {id: n}}
require __DIR__ . '/_ogrod.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function wyj($d, $kod = 200) {
    http_response_code($kod);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') wyj(ogr_pod_blokada(function () { ogr_dokoncz(); return ogr_widok(); }));
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) wyj(['blad' => 'zle zadanie'], 400);
    if (za_szybko()) wyj(['blad' => 'zbyt szybko', 'tekst' => 'Spokojnie, w ogrodzie nic nie dzieje się tak szybko.'], 429);
    $akcja = is_string($in['akcja'] ?? null) ? $in['akcja'] : '';
    $in['gracz'] = substr(preg_replace('/[^a-z0-9]/', '', strtolower(is_scalar($in['gracz'] ?? null) ? (string)$in['gracz'] : '')), 0, 12);
    $wynik = ogr_pod_blokada(function () use ($akcja, $in) {
        ogr_dokoncz();
        $ok = true;
        try { $tekst = ogr_wykonaj($akcja, $in); dodaj_zdarzenie('ogrod', $akcja, $in['gracz']); }
        catch (RuntimeException $e) { $tekst = $e->getMessage(); $ok = false; }
        ogr_dostawy();
        return ['ok' => $ok, 'tekst' => $tekst, 'stan' => ogr_widok()];
    });
    wyj($wynik);
} catch (Throwable $e) {
    wyj(['blad' => 'blad serwera'], 500);
}

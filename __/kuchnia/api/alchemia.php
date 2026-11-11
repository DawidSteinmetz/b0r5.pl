<?php
// Alchemia piwnicy. GET: Spiżarnia, stół, waga, garnek. POST {akcja, gracz, ...}:
// na_stol {id} | ze_stolu | czar {czar, ulamek} | do_kociolka {id, mol? albo gramy?} | zbadaj {id} | zdejmij | zagotuj {katalizator} | wylej | sprzedaj {id} | wyrzuc {id}
// rzuc_przepis {przepis, mnoznik} | wsyp_przepis | zgas_przepis | poloz_stos {przepis, ile} | odlej {id, gramy}
require __DIR__ . '/_alchemia.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function wyj($d, $kod = 200) {
    http_response_code($kod);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') wyj(alch_pod_blokada(function () { alch_dokoncz(); return alch_widok(); }));
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) wyj(['blad' => 'zle zadanie'], 400);
    if (za_szybko()) wyj(['blad' => 'zbyt szybko', 'tekst' => 'Spokojnie, nic tu nie dzieje się tak szybko.'], 429);
    $akcja = is_string($in['akcja'] ?? null) ? $in['akcja'] : '';
    $in['gracz'] = substr(preg_replace('/[^a-z0-9]/', '', strtolower(is_scalar($in['gracz'] ?? null) ? (string)$in['gracz'] : '')), 0, 12);
    $wynik = alch_pod_blokada(function () use ($akcja, $in) {
        alch_dokoncz();
        $ok = true;
        try { $tekst = alch_wykonaj($akcja, $in); dodaj_zdarzenie('alch', $akcja, $in['gracz']); }
        catch (RuntimeException $e) { $tekst = $e->getMessage(); $ok = false; }
        return ['ok' => $ok, 'tekst' => $tekst, 'stan' => alch_widok()];
    });
    wyj($wynik);
} catch (Throwable $e) {
    wyj(['blad' => 'blad serwera'], 500);
}

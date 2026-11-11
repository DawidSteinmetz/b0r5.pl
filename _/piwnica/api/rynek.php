<?php
// Księga rachunkowa Henryka. GET: saldo, ceny, w drodze, historia. POST: {akcja: "kup", towary: {id: n}, gracz}.
// Kupione surowce trafiają po zwłoce do Składu, a produkty sprzedaje się w oknie Składu (api/alchemia.php).
require __DIR__ . '/_rynek.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function wyj($d, $kod = 200) {
    http_response_code($kod);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!rynek_cfg()) wyj(['blad' => 'brak rynku'], 404);
try { $o = rynek_stan(); } catch (Throwable $e) { wyj(['blad' => 'brak bazy'], 503); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') wyj(rynek_publiczny($o));

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) wyj(['blad' => 'zle zadanie'], 400);
$akcja = (string)($in['akcja'] ?? '');
$gracz = substr(preg_replace('/[^a-z0-9]/', '', strtolower((string)($in['gracz'] ?? ''))), 0, 12);
$odp = function ($tekst, $ok = true) { wyj(['ok' => $ok, 'tekst' => $tekst, 'ksiega' => rynek_publiczny(rynek_stan())]); };
$c = $o['cfg'];

try {
    if (za_szybko()) wyj(['blad' => 'zbyt szybko'], 429);

    if ($akcja === 'kup') {
        $zam = []; $razem = 0;
        foreach ((is_array($in['towary'] ?? null) ? $in['towary'] : []) as $slug => $n) {
            $n = (int)$n;
            if ($n < 1) continue;
            if (!isset($c['towary'][$slug])) $odp('Nie ma takiego towaru w księdze.', false);
            if ($n > (int)$c['zakup']['max_pozycja']) $odp('Tyle jednej rzeczy naraz nikt Henrykowi nie przyniesie.', false);
            $zam[$slug] = $n; $razem += $n;
        }
        if ($razem < 1) $odp('Zamówienie jest puste.', false);
        if ($razem > (int)$c['zakup']['max_razem']) $odp('Za dużo na raz. Księga nie przyjmie tak długiej listy.', false);
        $db = baza(); $db->exec('BEGIN IMMEDIATE');
        try {
            $o = rynek_odczytaj(); $suma = 0; $poz = [];                 // ceny liczymy w transakcji, na świeżym stanie
            foreach ($zam as $slug => $n) { $w = rynek_wycena($o, $slug, $n); $poz[$slug] = $w; $suma += $w['suma']; }
            if ($suma > $o['saldo']) { $db->exec('ROLLBACK'); $odp("Nie starczy pieniędzy: zamówienie wyszłoby na " . rynek_kwota_txt($suma) . ", a w kasie jest " . rynek_kwota_txt($o['saldo']) . ".", false); }
            foreach ($poz as $slug => $w) dodaj_zdarzenie('zakup', $slug, $gracz, ['n' => $zam[$slug], 'koszt' => $w['suma'], 'dom' => 'henryk']);
            $db->exec('COMMIT');
        } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
        $odp("Zapłacone " . rynek_kwota_txt($suma) . ". Towar przyjdzie do Składu za jakiś czas.");
    }
    wyj(['blad' => 'nieznana akcja'], 400);
} catch (Throwable $e) {
    wyj(['blad' => 'blad serwera'], 500);
}

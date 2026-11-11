<?php
// Sekretarzyk do listów. GET: stan widoczny dla graczy.
// POST {akcja, adresat, gracz, ...}: szkic {tresc, wersja} | pieczec {sygnatura} | wyslij
// Pieczęć łamie się raz: kto znajdzie sygnaturę, otwiera drogę do tego adresata wszystkim graczom.
require __DIR__ . '/_poczta.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function wyj($d, $kod = 200) {
    http_response_code($kod);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try { $w = poczta_stan(); } catch (Throwable $e) { wyj(['blad' => 'brak bazy'], 503); }
if (!$w) wyj(['blad' => 'brak poczty'], 404);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') wyj(poczta_publiczna($w));

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) wyj(['blad' => 'zle zadanie'], 400);
$akcja = is_string($in['akcja'] ?? null) ? $in['akcja'] : '';
$gracz = poczta_gracz(is_scalar($in['gracz'] ?? null) ? $in['gracz'] : '');
$adr = poczta_adresat(is_string($in['adresat'] ?? null) ? $in['adresat'] : '');
if (!$adr) wyj(['blad' => 'nie ma takiego adresata'], 404);
$id = $adr['id'];
$odp = function ($t, $ok = true, $extra = []) { wyj(array_merge(['ok' => $ok, 'tekst' => $t, 'stan' => poczta_publiczna(poczta_stan())], $extra)); };

try {
    if (za_szybko()) wyj(['blad' => 'zbyt szybko', 'tekst' => 'Spokojnie, atrament jeszcze nie wysechł.'], 429);

    switch ($akcja) {
        case 'szkic':
            $t = trim(str_replace("\r", '', is_string($in['tresc'] ?? null) ? $in['tresc'] : ''));
            if (mb_strlen($t, 'UTF-8') > POCZTA_MAX) $odp('Tyle nie napisze naraz. Skróćcie.', false);
            if ((int)($in['wersja'] ?? 0) !== (int)($w['szk'][$id]['wersja'] ?? 0))
                $odp('Ktoś podszepnął coś w międzyczasie. Wczytaj nowszą wersję i dopisz swoje.', false, ['konflikt' => true]);
            dodaj_zdarzenie('szkic', $id, $gracz, ['tresc' => $t]);
            $odp('Zapisane na brudno.');

        case 'pieczec':
            if (isset($w['piec'][$id])) $odp('Pieczęć jest już złamana.');
            $st = baza()->prepare("SELECT COUNT(*) FROM zdarzenia WHERE typ='pieczec_proba' AND ip=? AND ts>?");
            $st->execute([ip_hash(), time() - 600]);
            if ((int)$st->fetchColumn() >= 10) $odp('Wosk jeszcze stygnie. Spróbuj za kilka minut.', false);
            $s = poczta_norm(is_scalar($in['sygnatura'] ?? null) ? $in['sygnatura'] : '');
            if ($s !== '' && in_array($s, array_map('poczta_norm', $adr['odpowiedzi'] ?? []), true)) {
                dodaj_zdarzenie('pieczec', $id, $gracz);
                $odp('Wosk pęka z cichym trzaskiem. Droga do tego adresata jest otwarta.');
            }
            dodaj_zdarzenie('pieczec_proba', $id, $gracz);
            $odp('Wosk nie przyjmuje takiej sygnatury.', false);

        case 'wyslij':
            if (!isset($w['piec'][$id])) $odp('Najpierw trzeba złamać pieczęć.', false);
            if (!poczta_gotowa($adr)) $odp('Sowa nie zna jeszcze drogi do tego adresata.', false);
            $dir = KORZEN . '/_dane';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $f = fopen($dir . '/poczta.lock', 'c');
            if (!$f || !flock($f, LOCK_EX)) $odp('Sowa jest zajęta. Spróbuj za chwilę.', false);
            $w = poczta_stan();                                  // po zablokowaniu: stan mógł się zmienić
            $t = (string)($w['szk'][$id]['tresc'] ?? '');
            if (mb_strlen(trim($t), 'UTF-8') < POCZTA_MIN) $odp('Na kartce jest za mało, by sowa miała co zanieść. Zapiszcie najpierw szkic.', false);
            $wynik = poczta_wyslij($adr, $w['cfg']['nadawca'], $t);
            if ($wynik === 'ok') {
                dodaj_zdarzenie('list_wyslany', $id, $gracz, ['tresc' => $t]);
                $odp('Sowa wzięła list i poleciała do adresata: ' . ($adr['nazwa'] ?? $id) . '.');
            }
            dodaj_zdarzenie('list_blad', $id, $gracz, ['blad' => $wynik]);
            if ($wynik === 'limit dzienny') $odp('Sowa jest dziś zmęczona, adresat dostał już dość listów. Spróbujcie jutro.', false);
            $odp('Sowa wróciła z listem: adresat nie odpowiada. Spróbujcie za chwilę.', false);
    }
    wyj(['blad' => 'nieznana akcja'], 400);
} catch (Throwable $e) {
    wyj(['blad' => 'blad serwera'], 500);
}

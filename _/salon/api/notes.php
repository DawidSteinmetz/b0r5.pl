<?php
// Notes do gry w salonie. Jedna wspólna partia dla wszystkich gości, stan w _logika/stan/notes.json (pod blokadą).
// Dwa formularze: "mille" (tysiąc: kolumny graczy, kolejne wpisy, cel) i "alea" (kości: tabela kategorii jak w Generale).
// GET: partia i kilka ostatnich zakończonych. POST {akcja, gracz, ...}:
//   wpisz {imie, pkt, kat?, glos?} | bomba {imie} | cofnij | nowa {typ?: mille|alea, gracze?: [imiona], cel?} | gracz_nr {nr, imie} | dodaj_gracza {imie}
// Zaklęcia głosowe rozpoznaje przeglądarka (pokoj.js); tu przychodzą już gotowe działania.
require dirname(__DIR__, 2) . '/api/_baza.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
define('NOTES_STAN', dirname(__DIR__) . '/_logika/stan');
const NOTES_MAX_GRACZY = 8;
const NOTES_MAX_WPISOW = 600;
const NOTES_ARCHIWUM = 8;
// Kategorie kości: 'oczko' to liczba oczek w górnej części (wynik musi być jej wielokrotnością),
// 'stale' to wartość stała (wpis bez liczby), 'max' to największy możliwy wynik.
const NOTES_KATEGORIE = [
    'jedynki' => ['nazwa' => 'Jedynki', 'czesc' => 'gora', 'oczko' => 1],
    'dwojki' => ['nazwa' => 'Dwójki', 'czesc' => 'gora', 'oczko' => 2],
    'trojki' => ['nazwa' => 'Trójki', 'czesc' => 'gora', 'oczko' => 3],
    'czworki' => ['nazwa' => 'Czwórki', 'czesc' => 'gora', 'oczko' => 4],
    'piatki' => ['nazwa' => 'Piątki', 'czesc' => 'gora', 'oczko' => 5],
    'szostki' => ['nazwa' => 'Szóstki', 'czesc' => 'gora', 'oczko' => 6],
    'trzy' => ['nazwa' => 'Trzy jednakowe', 'czesc' => 'dol', 'max' => 30],
    'cztery' => ['nazwa' => 'Cztery jednakowe', 'czesc' => 'dol', 'max' => 30],
    'full' => ['nazwa' => 'Full', 'czesc' => 'dol', 'stale' => 25],
    'maly' => ['nazwa' => 'Mały strit', 'czesc' => 'dol', 'stale' => 30],
    'duzy' => ['nazwa' => 'Duży strit', 'czesc' => 'dol', 'stale' => 40],
    'general' => ['nazwa' => 'Generał', 'czesc' => 'dol', 'stale' => 50],
    'szansa' => ['nazwa' => 'Szansa', 'czesc' => 'dol', 'max' => 30],
];
const NOTES_PREMIA_PROG = 63;
const NOTES_PREMIA = 35;
const NOTES_TYPY = ['mille' => ['nazwa' => 'Tysiąc', 'cel' => 1000], 'alea' => ['nazwa' => 'Kości', 'cel' => 0]];

function wyj($d, $kod = 200) {
    http_response_code($kod);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function notes_blad($t) { throw new RuntimeException($t); }
function notes_imie($s) {
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    $s = preg_replace('/[^\p{L}\p{N} \-]/u', '', $s);
    $s = mb_substr($s, 0, 20, 'UTF-8');
    return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
}
function notes_czytaj() {
    $t = @file_get_contents(NOTES_STAN . '/notes.json');
    $d = $t === false ? null : json_decode($t, true);
    if (!is_array($d)) $d = ['gra' => null, 'archiwum' => [], 'n' => 0];
    if (!$d['gra']) $d['gra'] = notes_nowa_gra($d, 'mille', ['Henryk'], 1000);
    if (empty($d['gra']['typ'])) $d['gra']['typ'] = 'mille';
    return $d;
}
function notes_zapisz($d) {
    $p = NOTES_STAN . '/notes.json'; $t = $p . '.tmp' . getmypid();
    file_put_contents($t, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($t, $p);
}
function notes_pod_blokada($f) {
    if (!is_dir(NOTES_STAN)) @mkdir(NOTES_STAN, 0775, true);
    $h = @fopen(NOTES_STAN . '/.blokada', 'c');
    if (!$h || !is_writable(NOTES_STAN)) wyj(['blad' => 'brak zapisu', 'tekst' => 'Notes nie może pisać: serwer nie ma prawa zapisu w salon/_logika/stan/.'], 503);
    flock($h, LOCK_EX);
    try { return $f(); } finally { flock($h, LOCK_UN); fclose($h); }
}
function notes_nowa_gra(&$d, $typ, $gracze, $cel) {
    $d['n'] = (int)($d['n'] ?? 0) + 1;
    return ['id' => $d['n'], 'typ' => $typ, 'nazwa' => NOTES_TYPY[$typ]['nazwa'], 'cel' => $cel, 'gracze' => array_values($gracze), 'wpisy' => [],
        'start' => time(), 'koniec' => null];
}
// Wyniki kości jednego gracza: kategoria => punkty, sumy części, premia, razem.
function notes_kosci($g, $imie) {
    $k = [];
    foreach ($g['wpisy'] as $w) if ($w['imie'] === $imie && isset($w['kat'])) $k[$w['kat']] = (int)$w['pkt'];
    $gora = 0; $dol = 0;
    foreach ($k as $kat => $p) { if (NOTES_KATEGORIE[$kat]['czesc'] === 'gora') $gora += $p; else $dol += $p; }
    $premia = $gora >= NOTES_PREMIA_PROG ? NOTES_PREMIA : 0;
    return ['kat' => $k, 'gora' => $gora, 'premia' => $premia, 'dol' => $dol, 'razem' => $gora + $premia + $dol];
}
function notes_sumy($g) {
    $s = array_fill_keys($g['gracze'], 0);
    if ($g['typ'] === 'alea') { foreach ($g['gracze'] as $im) $s[$im] = notes_kosci($g, $im)['razem']; return $s; }
    foreach ($g['wpisy'] as $w) if (isset($s[$w['imie']])) $s[$w['imie']] += (int)$w['pkt'];
    return $s;
}
// Koniec: w tysiącu pierwszy, kto osiągnął cel; w kościach, kiedy wszyscy wypełnią wszystkie kategorie.
function notes_sprawdz_koniec(&$g) {
    if ($g['koniec']) return;
    $s = notes_sumy($g); arsort($s); $imie = array_key_first($s);
    if ($imie === null) return;
    if ($g['typ'] === 'alea') {
        foreach ($g['gracze'] as $im) if (count(notes_kosci($g, $im)['kat']) < count(NOTES_KATEGORIE)) return;
    } elseif ((int)$g['cel'] <= 0 || $s[$imie] < (int)$g['cel']) return;
    $g['koniec'] = ['zwyciezca' => $imie, 'suma' => $s[$imie], 'ts' => time()];
}
function notes_widok($d) {
    $g = $d['gra']; $s = notes_sumy($g); $max = $s ? max($s) : 0; $gracze = [];
    foreach ($g['gracze'] as $im) {
        $x = ['imie' => $im, 'suma' => $s[$im], 'prowadzi' => $max > 0 && $s[$im] === $max, 'wpisy' => []];
        if ($g['typ'] === 'alea') $x['kosci'] = notes_kosci($g, $im);
        $gracze[] = $x;
    }
    $poz = array_flip($g['gracze']);
    foreach ($g['wpisy'] as $i => $w) if (isset($poz[$w['imie']])) $gracze[$poz[$w['imie']]]['wpisy'][] = ['pkt' => (int)$w['pkt'], 'nr' => $i, 'kat' => $w['kat'] ?? null, 'bomba' => !empty($w['bomba']), 'grupa' => $w['grupa'] ?? null];
    foreach ($gracze as &$x) {                                       // ile bomb zostało: jedna przed 500, jedna po
        $x['bomby'] = ['przed' => true, 'po' => true];
        foreach ($g['wpisy'] as $w) if (!empty($w['bomba']) && $w['imie'] === $x['imie']) $x['bomby'][$w['faza'] ?? 'przed'] = false;
    }
    unset($x);
    $kat = [];
    foreach (NOTES_KATEGORIE as $id => $k) $kat[] = ['id' => $id, 'nazwa' => $k['nazwa'], 'czesc' => $k['czesc'], 'stale' => $k['stale'] ?? null];
    $ost = $g['wpisy'] ? end($g['wpisy']) : null;
    return ['id' => $g['id'], 'typ' => $g['typ'], 'nazwa' => $g['nazwa'], 'cel' => (int)$g['cel'], 'gracze' => $gracze, 'wpisow' => count($g['wpisy']),
        'ostatni' => $g['wpisy'] ? count($g['wpisy']) - 1 : -1, 'ostatni_wpis' => $ost ? ['imie' => $ost['imie'], 'kat' => $ost['kat'] ?? null] : null,
        'koniec' => $g['koniec'], 'kategorie' => $kat, 'premia' => ['prog' => NOTES_PREMIA_PROG, 'pkt' => NOTES_PREMIA], 'archiwum' => $d['archiwum']];
}
function notes_archiwizuj(&$d) {
    $g = $d['gra'];
    if (!$g['wpisy']) return;
    $s = notes_sumy($g); arsort($s);
    array_unshift($d['archiwum'], ['id' => $g['id'], 'nazwa' => $g['nazwa'], 'start' => $g['start'], 'sumy' => $s, 'zwyciezca' => $g['koniec']['zwyciezca'] ?? null]);
    $d['archiwum'] = array_slice($d['archiwum'], 0, NOTES_ARCHIWUM);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') wyj(notes_pod_blokada(function () { return notes_widok(notes_czytaj()); }));
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) wyj(['blad' => 'zle zadanie'], 400);
    if (za_szybko()) wyj(['blad' => 'zbyt szybko', 'tekst' => 'Pióro nie nadąża.'], 429);
    $akcja = is_string($in['akcja'] ?? null) ? $in['akcja'] : '';
    $kto = substr(preg_replace('/[^a-z0-9]/', '', strtolower(is_scalar($in['gracz'] ?? null) ? (string)$in['gracz'] : '')), 0, 12);
    wyj(notes_pod_blokada(function () use ($akcja, $in, $kto) {
        $d = notes_czytaj(); $ok = true;
        try {
            $g = $d['gra'];
            switch ($akcja) {
                case 'wpisz':
                    $imie = notes_imie($in['imie'] ?? '');
                    if (!in_array($imie, $g['gracze'], true)) notes_blad('W notesie nie ma takiego gracza.');
                    if ($g['koniec']) notes_blad('Ta partia jest skończona. Zacznij nową.');
                    if (count($g['wpisy']) >= NOTES_MAX_WPISOW) notes_blad('Notes jest zapisany do ostatniej strony.');
                    $w = ['imie' => $imie, 'ts' => time(), 'glos' => !empty($in['glos'])];
                    if ($g['typ'] === 'alea') {
                        $kat = (string)($in['kat'] ?? '');
                        $k = NOTES_KATEGORIE[$kat] ?? null;
                        if (!$k) notes_blad('Którą kategorię wpisać? Np. „Kacper trójki 9” albo „Maja full”.');
                        if (isset(notes_kosci($g, $imie)['kat'][$kat])) notes_blad($imie . ' ma już wpisane: ' . mb_strtolower($k['nazwa'], 'UTF-8') . '.');
                        $skresl = !empty($in['skresl']);
                        if ($skresl) $pkt = 0;
                        elseif (isset($k['stale'])) $pkt = is_numeric($in['pkt'] ?? null) && (int)$in['pkt'] === 0 ? 0 : $k['stale'];
                        else {
                            if (!is_numeric($in['pkt'] ?? null)) notes_blad('Ile punktów za ' . mb_strtolower($k['nazwa'], 'UTF-8') . '?');
                            $pkt = (int)$in['pkt'];
                            $max = isset($k['oczko']) ? 5 * $k['oczko'] : $k['max'];
                            if ($pkt < 0 || $pkt > $max) notes_blad('Za ' . mb_strtolower($k['nazwa'], 'UTF-8') . ' można dostać od 0 do ' . $max . '.');
                            if (isset($k['oczko']) && $pkt % $k['oczko']) notes_blad(ucfirst($k['nazwa']) . ' to wielokrotność ' . $k['oczko'] . '.');
                            if (!isset($k['oczko']) && $pkt > 0 && $pkt < 5) notes_blad('Pięć kości daje co najmniej 5 oczek.');
                        }
                        $w['kat'] = $kat; $w['pkt'] = $pkt;
                        $t = $imie . ', ' . mb_strtolower($k['nazwa'], 'UTF-8') . ': ' . ($skresl || $pkt === 0 ? 'skreślone' : $pkt) . '.';
                    } else {
                        if (!is_numeric($in['pkt'] ?? null)) notes_blad('Pióro nie zrozumiało liczby.');
                        $pkt = (int)round((float)$in['pkt']);
                        if (abs($pkt) > 10000) notes_blad('Tyle punktów w jednym rozdaniu? Pióro się waha.');
                        $w['pkt'] = $pkt;
                        $t = $imie . ': ' . ($pkt > 0 ? '+' : '') . $pkt . '.';
                    }
                    $g['wpisy'][] = $w;
                    notes_sprawdz_koniec($g);
                    if ($g['koniec']) $t .= ' ' . $g['koniec']['zwyciezca'] . ' wygrywa!';
                    break;
                case 'bomba':                                     // tysiąc: rozgrywający rezygnuje, pozostali dostają po 60 (przy 4 graczach po 40)
                    $imie = notes_imie($in['imie'] ?? '');
                    if ($g['typ'] !== 'mille') notes_blad('Bomba jest tylko w tysiącu.');
                    if (!in_array($imie, $g['gracze'], true)) notes_blad('W notesie nie ma takiego gracza.');
                    if ($g['koniec']) notes_blad('Ta partia jest skończona. Zacznij nową.');
                    if (count($g['gracze']) < 2) notes_blad('Bomba potrzebuje przeciwników.');
                    $suma = notes_sumy($g)[$imie]; $faza = $suma < 500 ? 'przed' : 'po';
                    foreach ($g['wpisy'] as $x) if (!empty($x['bomba']) && $x['imie'] === $imie && ($x['faza'] ?? '') === $faza)
                        notes_blad($imie . ' zużył już bombę ' . ($faza === 'przed' ? 'przed 500' : 'po 500') . '.');
                    $ile = count($g['gracze']) >= 4 ? 40 : 60;
                    $grupa = 'b' . (count($g['wpisy']) + 1) . '-' . time();
                    foreach ($g['gracze'] as $im)
                        $g['wpisy'][] = $im === $imie ? ['imie' => $im, 'pkt' => 0, 'bomba' => true, 'faza' => $faza, 'grupa' => $grupa, 'ts' => time(), 'glos' => !empty($in['glos'])]
                            : ['imie' => $im, 'pkt' => $ile, 'grupa' => $grupa, 'ts' => time(), 'glos' => !empty($in['glos'])];
                    notes_sprawdz_koniec($g);
                    $t = 'Bomba! ' . $imie . ' rezygnuje, pozostali dostają po ' . $ile . '.' . ($g['koniec'] ? ' ' . $g['koniec']['zwyciezca'] . ' wygrywa!' : '');
                    break;
                case 'cofnij':
                    if (!$g['wpisy']) notes_blad('Nie ma czego wykreślić.');
                    $w = array_pop($g['wpisy']); $g['koniec'] = null;
                    if (!empty($w['grupa'])) {                    // bomba wykreśla się w całości
                        while ($g['wpisy'] && (end($g['wpisy'])['grupa'] ?? '') === $w['grupa']) array_pop($g['wpisy']);
                        $t = 'Wykreślona bomba.';
                        break;
                    }
                    $t = 'Wykreślone: ' . $w['imie'] . (isset($w['kat']) ? ', ' . mb_strtolower(NOTES_KATEGORIE[$w['kat']]['nazwa'], 'UTF-8') : '') . ' ' . $w['pkt'] . '.';
                    break;
                case 'nowa':
                    $typ = isset(NOTES_TYPY[$in['typ'] ?? '']) ? $in['typ'] : $g['typ'];
                    $gr = [];
                    foreach ((is_array($in['gracze'] ?? null) ? $in['gracze'] : $g['gracze']) as $im) {
                        $im = notes_imie($im);
                        if ($im !== '' && !in_array($im, $gr, true)) $gr[] = $im;
                    }
                    if (!$gr) notes_blad('Kto gra? Notes potrzebuje choć jednego imienia.');
                    if (count($gr) > NOTES_MAX_GRACZY) notes_blad('Przy stoliku zmieści się najwyżej ' . NOTES_MAX_GRACZY . ' graczy.');
                    $cel = $typ === 'alea' ? 0 : (isset($in['cel']) && is_numeric($in['cel']) ? max(0, min(100000, (int)$in['cel'])) : ($g['typ'] === 'mille' ? (int)$g['cel'] : NOTES_TYPY['mille']['cel']));
                    notes_archiwizuj($d);
                    $g = notes_nowa_gra($d, $typ, $gr, $cel);
                    $t = ($typ === 'alea' ? 'Formularz do kości' : 'Formularz do tysiąca') . ': ' . implode(', ', $gr) . '.';
                    break;
                case 'gracz_nr':                                  // Lusor Primus/Secundus/…: gracz na danym miejscu
                    $nr = (int)($in['nr'] ?? 0); $imie = notes_imie($in['imie'] ?? '');
                    if ($nr < 1 || $nr > NOTES_MAX_GRACZY) notes_blad('Nie ma takiego miejsca przy stoliku.');
                    if ($imie === '') notes_blad('Jak ma na imię?');
                    $n = count($g['gracze']);
                    if ($nr > $n + 1) notes_blad('Najpierw trzeba posadzić gracza numer ' . ($n + 1) . '.');
                    $inny = array_search($imie, $g['gracze'], true);
                    if ($inny !== false && $inny !== $nr - 1) notes_blad($imie . ' już siedzi przy stoliku.');
                    if ($nr === $n + 1) { $g['gracze'][] = $imie; $t = 'Przy stoliku siada ' . $imie . '.'; break; }
                    $stare = $g['gracze'][$nr - 1];
                    $g['gracze'][$nr - 1] = $imie;
                    foreach ($g['wpisy'] as &$w) if ($w['imie'] === $stare) $w['imie'] = $imie;
                    unset($w);
                    if ($g['koniec'] && $g['koniec']['zwyciezca'] === $stare) $g['koniec']['zwyciezca'] = $imie;
                    $t = $stare === $imie ? $imie . ' już tu siedzi.' : 'Na miejscu ' . $nr . ': ' . $imie . ' (było: ' . $stare . ').';
                    break;
                case 'dodaj_gracza':
                    $imie = notes_imie($in['imie'] ?? '');
                    if ($imie === '') notes_blad('Jak ma na imię?');
                    if (in_array($imie, $g['gracze'], true)) notes_blad($imie . ' już jest w notesie.');
                    if (count($g['gracze']) >= NOTES_MAX_GRACZY) notes_blad('Przy stoliku nie ma już miejsca.');
                    $g['gracze'][] = $imie;
                    $t = 'Dosiada się ' . $imie . '.';
                    break;
                default:
                    notes_blad('Pióro nie wie, co zrobić.');
            }
            $d['gra'] = $g;
            notes_zapisz($d);
            dodaj_zdarzenie('notes', $akcja, $kto);
        } catch (RuntimeException $e) { $t = $e->getMessage(); $ok = false; }
        return ['ok' => $ok, 'tekst' => $t, 'stan' => notes_widok($d)];
    }));
} catch (Throwable $e) {
    wyj(['blad' => 'blad serwera'], 500);
}

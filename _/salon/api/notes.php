<?php
// Notes do gier w salonie. Jedna wspólna partia dla wszystkich gości, stan w _logika/stan/notes.json (pod blokadą).
// Partia idzie krokami ('etap'): 'wybor' (jaka gra) -> 'gracze' (kto gra) -> 'gra' (punkty).
// Dwie gry: 'mille' (tysiąc: kolumny graczy, kolejne wpisy, bomba) i 'alea' (kości: tabela układów).
// GET: stan notesu. POST {akcja, gracz, ...}:
//   wybierz {typ}            krok 1: nowa partia danej gry (poprzednia idzie do archiwum), przejście do kroku 2
//   gracz_nr {nr, imie}      gracz na miejscu nr (Lusor Primus…), nowy albo zmiana imienia
//   usun_gracza {nr}         tylko gracz bez wpisów
//   zacznij                  krok 2 -> 3 (Incipiāmus)
//   wpisz {imie, pkt, kat?}  wpis punktów (w kościach z układem 'kat'); w kroku 2 zaczyna grę
//   popraw {nr, pkt}         zmiana wpisu (edycja komórki)
//   bomba {imie}             tysiąc: bomba
//   cofnij                   wykreślenie ostatniego wpisu (bomby w całości)
//   od_nowa                  powrót do kroku 1 (Nota hoc nova)
// Zaklęcia głosowe rozpoznaje przeglądarka (pokoj.js); tu przychodzą już gotowe działania.
require dirname(__DIR__, 2) . '/api/_baza.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
define('NOTES_STAN', dirname(__DIR__) . '/_logika/stan');
const NOTES_MAX_GRACZY = 8;
const NOTES_MAX_WPISOW = 600;
const NOTES_ARCHIWUM = 8;
const NOTES_TYPY = ['mille' => ['nazwa' => 'Tysiąc', 'cel' => 1000], 'alea' => ['nazwa' => 'Kości', 'cel' => 0]];
// Układy w kościach. 'lac': nazwa w czarze; 'oczko': górna część (wynik to wielokrotność oczka, najwyżej 5 kości);
// 'stale': wartość stała; 'krok'/'min'/'max': dozwolone wyniki (suma kości tworzących układ).
const NOTES_UKLADY = [
    'singuli' => ['nazwa' => 'Jedynki', 'lac' => 'Singulī', 'czesc' => 'gora', 'oczko' => 1],
    'bini' => ['nazwa' => 'Dwójki', 'lac' => 'Bīnī', 'czesc' => 'gora', 'oczko' => 2],
    'terni' => ['nazwa' => 'Trójki', 'lac' => 'Ternī', 'czesc' => 'gora', 'oczko' => 3],
    'quaterni' => ['nazwa' => 'Czwórki', 'lac' => 'Quaternī', 'czesc' => 'gora', 'oczko' => 4],
    'quini' => ['nazwa' => 'Piątki', 'lac' => 'Quīnī', 'czesc' => 'gora', 'oczko' => 5],
    'seni' => ['nazwa' => 'Szóstki', 'lac' => 'Sēnī', 'czesc' => 'gora', 'oczko' => 6],
    'par' => ['nazwa' => 'Para', 'lac' => 'Pār', 'czesc' => 'dol', 'krok' => 2, 'min' => 2, 'max' => 12],
    'paria' => ['nazwa' => 'Dwie pary', 'lac' => 'Duo paria', 'czesc' => 'dol', 'krok' => 2, 'min' => 6, 'max' => 22],
    'tres' => ['nazwa' => 'Trójka', 'lac' => 'Trēs', 'czesc' => 'dol', 'krok' => 3, 'min' => 3, 'max' => 18],
    'quattuor' => ['nazwa' => 'Kareta', 'lac' => 'Quattuor', 'czesc' => 'dol', 'krok' => 4, 'min' => 4, 'max' => 24],
    'plenus' => ['nazwa' => 'Full', 'lac' => 'Plēnus', 'czesc' => 'dol', 'min' => 7, 'max' => 28],
    'minor' => ['nazwa' => 'Mały strit', 'lac' => 'Ōrdō minor', 'czesc' => 'dol', 'stale' => 15],
    'maior' => ['nazwa' => 'Duży strit', 'lac' => 'Ōrdō maior', 'czesc' => 'dol', 'stale' => 20],
    'fortuna' => ['nazwa' => 'Szansa', 'lac' => 'Fortūna', 'czesc' => 'dol', 'min' => 5, 'max' => 30],
    'imperator' => ['nazwa' => 'Generał', 'lac' => 'Imperātor', 'czesc' => 'dol', 'stale' => 50],
];
const NOTES_PREMIA_PROG = 63;
const NOTES_PREMIA = 50;

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
function notes_male($s) { return mb_strtolower($s, 'UTF-8'); }
function notes_czytaj() {
    $t = @file_get_contents(NOTES_STAN . '/notes.json');
    $d = $t === false ? null : json_decode($t, true);
    if (!is_array($d)) $d = ['gra' => null, 'archiwum' => [], 'n' => 0];
    if (!$d['gra']) $d['gra'] = ['id' => 0, 'etap' => 'wybor', 'typ' => '', 'gracze' => [], 'wpisy' => [], 'koniec' => null, 'start' => time()];
    if (empty($d['gra']['etap'])) $d['gra']['etap'] = $d['gra']['wpisy'] ? 'gra' : 'wybor';   // stan sprzed kroków
    if (($d['gra']['typ'] ?? '') === 'alea') foreach ($d['gra']['wpisy'] as $w) if (isset($w['kat']) && !isset(NOTES_UKLADY[$w['kat']])) { $d['gra']['etap'] = 'wybor'; $d['gra']['wpisy'] = []; break; }
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
// Wyniki kości jednego gracza: układ => [punkty, nr wpisu], sumy części, premia, razem.
function notes_kosci($g, $imie) {
    $k = []; $gora = 0; $dol = 0;
    foreach ($g['wpisy'] as $i => $w) if ($w['imie'] === $imie && isset($w['kat'], NOTES_UKLADY[$w['kat']])) $k[$w['kat']] = ['pkt' => (int)$w['pkt'], 'nr' => $i];
    foreach ($k as $kat => $x) { if (NOTES_UKLADY[$kat]['czesc'] === 'gora') $gora += $x['pkt']; else $dol += $x['pkt']; }
    $premia = $gora >= NOTES_PREMIA_PROG ? NOTES_PREMIA : 0;
    return ['uklady' => $k, 'gora' => $gora, 'premia' => $premia, 'dol' => $dol, 'razem' => $gora + $premia + $dol];
}
function notes_sumy($g) {
    $s = array_fill_keys($g['gracze'], 0);
    if ($g['typ'] === 'alea') { foreach ($g['gracze'] as $im) $s[$im] = notes_kosci($g, $im)['razem']; return $s; }
    foreach ($g['wpisy'] as $w) if (isset($s[$w['imie']])) $s[$w['imie']] += (int)$w['pkt'];
    return $s;
}
function notes_sprawdz_koniec(&$g) {
    $g['koniec'] = null;
    $s = notes_sumy($g); arsort($s); $imie = array_key_first($s);
    if ($imie === null) return;
    if ($g['typ'] === 'alea') {
        foreach ($g['gracze'] as $im) if (count(notes_kosci($g, $im)['uklady']) < count(NOTES_UKLADY)) return;
    } elseif ($s[$imie] < (int)$g['cel']) return;
    $g['koniec'] = ['zwyciezca' => $imie, 'suma' => $s[$imie]];
}
// Sprawdza wynik układu w kościach i zwraca punkty.
function notes_punkty_ukladu($kat, $pkt, $skresl) {
    $u = NOTES_UKLADY[$kat] ?? null;
    if (!$u) notes_blad('Pióro nie zna takiego układu.');
    if ($skresl) return 0;
    if (isset($u['stale'])) return is_numeric($pkt) && (int)$pkt === 0 ? 0 : $u['stale'];
    if (!is_numeric($pkt)) notes_blad('Ile punktów za ' . $u['lac'] . ' (' . notes_male($u['nazwa']) . ')?');
    $p = (int)$pkt;
    if ($p === 0) return 0;
    if (isset($u['oczko'])) {
        if ($p < 0 || $p > 5 * $u['oczko'] || $p % $u['oczko']) notes_blad($u['lac'] . ' (' . notes_male($u['nazwa']) . '): wielokrotność ' . $u['oczko'] . ', najwyżej ' . 5 * $u['oczko'] . '.');
        return $p;
    }
    if ($p < $u['min'] || $p > $u['max'] || (isset($u['krok']) && $p % $u['krok'])) notes_blad($u['lac'] . ' (' . notes_male($u['nazwa']) . '): od ' . $u['min'] . ' do ' . $u['max'] . (isset($u['krok']) ? ', wielokrotność ' . $u['krok'] : '') . '.');
    return $p;
}
function notes_widok($d) {
    $g = $d['gra']; $s = notes_sumy($g); $max = $s ? max($s) : 0; $gracze = [];
    foreach ($g['gracze'] as $im) {
        $x = ['imie' => $im, 'suma' => $s[$im], 'prowadzi' => $g['etap'] === 'gra' && $max > 0 && $s[$im] === $max, 'wpisy' => []];
        if ($g['typ'] === 'alea') $x['kosci'] = notes_kosci($g, $im);
        $gracze[] = $x;
    }
    $poz = array_flip($g['gracze']);
    foreach ($g['wpisy'] as $i => $w) if (isset($poz[$w['imie']])) $gracze[$poz[$w['imie']]]['wpisy'][] = ['pkt' => (int)$w['pkt'], 'nr' => $i, 'bomba' => !empty($w['bomba']), 'grupa' => $w['grupa'] ?? null];
    foreach ($gracze as &$x) {                                       // bomby: jedna przed 500, jedna po
        $x['bomby'] = ['przed' => true, 'po' => true];
        foreach ($g['wpisy'] as $w) if (!empty($w['bomba']) && $w['imie'] === $x['imie']) $x['bomby'][$w['faza'] ?? 'przed'] = false;
        $x['ma_wpisy'] = (bool)$x['wpisy'];
    }
    unset($x);
    $uklady = [];
    foreach (NOTES_UKLADY as $id => $u) $uklady[] = ['id' => $id, 'nazwa' => $u['nazwa'], 'lac' => $u['lac'], 'czesc' => $u['czesc'], 'stale' => $u['stale'] ?? null];
    return ['id' => $g['id'], 'etap' => $g['etap'], 'typ' => $g['typ'], 'nazwa' => NOTES_TYPY[$g['typ']]['nazwa'] ?? '', 'cel' => (int)($g['cel'] ?? 0),
        'gracze' => $gracze, 'ostatni' => $g['wpisy'] ? count($g['wpisy']) - 1 : -1, 'koniec' => $g['koniec'],
        'uklady' => $uklady, 'premia' => ['prog' => NOTES_PREMIA_PROG, 'pkt' => NOTES_PREMIA], 'archiwum' => $d['archiwum']];
}
function notes_archiwizuj(&$d) {
    $g = $d['gra'];
    if (empty($g['wpisy'])) return;
    $s = notes_sumy($g); arsort($s);
    array_unshift($d['archiwum'], ['id' => $g['id'], 'nazwa' => NOTES_TYPY[$g['typ']]['nazwa'] ?? '', 'start' => $g['start'], 'sumy' => $s, 'zwyciezca' => $g['koniec']['zwyciezca'] ?? null]);
    $d['archiwum'] = array_slice($d['archiwum'], 0, NOTES_ARCHIWUM);
}
function notes_w_grze(&$g) {
    if ($g['etap'] === 'wybor') notes_blad('Najpierw wybierz grę: Mille albo Alea.');
    if (!$g['gracze']) notes_blad('Najpierw posadź graczy: Lusor Primus i imię.');
    $g['etap'] = 'gra';
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
                case 'wybierz':
                    $typ = (string)($in['typ'] ?? '');
                    if (!isset(NOTES_TYPY[$typ])) notes_blad('Pióro nie zna takiej gry.');
                    notes_archiwizuj($d);
                    $d['n'] = (int)($d['n'] ?? 0) + 1;
                    $g = ['id' => $d['n'], 'etap' => 'gracze', 'typ' => $typ, 'cel' => NOTES_TYPY[$typ]['cel'], 'gracze' => $g['gracze'] ?? [], 'wpisy' => [], 'koniec' => null, 'start' => time()];
                    $t = ($typ === 'mille' ? 'Mille: notes do tysiąca.' : 'Alea: notes do kości.') . ' Kto gra?';
                    break;
                case 'gracz_nr':
                    if ($g['etap'] === 'wybor') notes_blad('Najpierw wybierz grę: Mille albo Alea.');
                    $nr = (int)($in['nr'] ?? 0); $imie = notes_imie($in['imie'] ?? '');
                    if ($nr < 1 || $nr > NOTES_MAX_GRACZY) notes_blad('Nie ma takiego miejsca przy stoliku.');
                    if ($imie === '') notes_blad('Jak ma na imię?');
                    $n = count($g['gracze']);
                    if ($nr > $n + 1) notes_blad('Najpierw trzeba posadzić gracza numer ' . ($n + 1) . '.');
                    $inny = array_search($imie, $g['gracze'], true);
                    if ($inny !== false && $inny !== $nr - 1) notes_blad($imie . ' już siedzi przy stoliku.');
                    if ($nr === $n + 1) { $g['gracze'][] = $imie; $t = 'Gracz ' . $nr . ': ' . $imie . '.'; break; }
                    $stare = $g['gracze'][$nr - 1];
                    $g['gracze'][$nr - 1] = $imie;
                    foreach ($g['wpisy'] as &$w) if ($w['imie'] === $stare) $w['imie'] = $imie;
                    unset($w);
                    notes_sprawdz_koniec($g);
                    $t = 'Gracz ' . $nr . ': ' . $imie . '.';
                    break;
                case 'usun_gracza':
                    $nr = (int)($in['nr'] ?? 0);
                    if (!isset($g['gracze'][$nr - 1])) notes_blad('Nie ma takiego gracza.');
                    $imie = $g['gracze'][$nr - 1];
                    foreach ($g['wpisy'] as $w) if ($w['imie'] === $imie) notes_blad($imie . ' ma już punkty w tej partii.');
                    array_splice($g['gracze'], $nr - 1, 1);
                    $t = $imie . ' odchodzi od stolika.';
                    break;
                case 'zacznij':
                    notes_w_grze($g);
                    $t = 'Incipiāmus! ' . implode(', ', $g['gracze']) . '.';
                    break;
                case 'wpisz':
                    notes_w_grze($g);
                    $imie = notes_imie($in['imie'] ?? '');
                    if (!in_array($imie, $g['gracze'], true)) notes_blad('W notesie nie ma takiego gracza.');
                    if (count($g['wpisy']) >= NOTES_MAX_WPISOW) notes_blad('Notes jest zapisany do ostatniej strony.');
                    $w = ['imie' => $imie, 'ts' => time(), 'glos' => !empty($in['glos'])];
                    if ($g['typ'] === 'alea') {
                        $kat = (string)($in['kat'] ?? '');
                        if (!isset(NOTES_UKLADY[$kat])) notes_blad('Który układ? Np. „Nota hoc ' . $imie . ' trēs 18”.');
                        $u = NOTES_UKLADY[$kat];
                        if (isset(notes_kosci($g, $imie)['uklady'][$kat])) notes_blad($imie . ' ma już ' . $u['lac'] . ' (' . notes_male($u['nazwa']) . '). Popraw w tabeli.');
                        $w['kat'] = $kat; $w['pkt'] = notes_punkty_ukladu($kat, $in['pkt'] ?? null, !empty($in['skresl']));
                        $t = $imie . ': ' . $u['lac'] . ' (' . notes_male($u['nazwa']) . ') ' . ($w['pkt'] ?: 'skreślone') . '.';
                    } else {
                        if (!is_numeric($in['pkt'] ?? null)) notes_blad('Pióro nie zrozumiało liczby.');
                        $w['pkt'] = (int)round((float)$in['pkt']);
                        if (abs($w['pkt']) > 10000) notes_blad('Tyle punktów w jednym rozdaniu? Pióro się waha.');
                        $t = $imie . ': ' . ($w['pkt'] > 0 ? '+' : '') . $w['pkt'] . '.';
                    }
                    $g['wpisy'][] = $w;
                    notes_sprawdz_koniec($g);
                    if ($g['koniec']) $t .= ' ' . $g['koniec']['zwyciezca'] . ' wygrywa!';
                    break;
                case 'popraw':
                    $nr = (int)($in['nr'] ?? -1);
                    if (!isset($g['wpisy'][$nr])) notes_blad('Nie ma takiego wpisu.');
                    $w =& $g['wpisy'][$nr];
                    if (!empty($w['grupa'])) notes_blad('Bomby się nie poprawia. Można ją wykreślić.');
                    if (isset($w['kat'])) $w['pkt'] = notes_punkty_ukladu($w['kat'], $in['pkt'] ?? null, !empty($in['skresl']));
                    else {
                        if (!is_numeric($in['pkt'] ?? null)) notes_blad('Pióro nie zrozumiało liczby.');
                        $w['pkt'] = (int)round((float)$in['pkt']);
                    }
                    $t = 'Poprawione: ' . $w['imie'] . ' ' . $w['pkt'] . '.';
                    unset($w);
                    notes_sprawdz_koniec($g);
                    break;
                case 'bomba':
                    notes_w_grze($g);
                    $imie = notes_imie($in['imie'] ?? '');
                    if ($g['typ'] !== 'mille') notes_blad('Bomba jest tylko w tysiącu.');
                    if (!in_array($imie, $g['gracze'], true)) notes_blad('W notesie nie ma takiego gracza.');
                    if (count($g['gracze']) < 2) notes_blad('Bomba potrzebuje przeciwników.');
                    $faza = notes_sumy($g)[$imie] < 500 ? 'przed' : 'po';
                    foreach ($g['wpisy'] as $x) if (!empty($x['bomba']) && $x['imie'] === $imie && ($x['faza'] ?? '') === $faza)
                        notes_blad($imie . ' zużył już bombę ' . ($faza === 'przed' ? 'przed 500' : 'po 500') . '.');
                    $ile = count($g['gracze']) >= 4 ? 40 : 60;
                    $grupa = 'b' . (count($g['wpisy']) + 1) . '-' . time();
                    foreach ($g['gracze'] as $im)
                        $g['wpisy'][] = $im === $imie ? ['imie' => $im, 'pkt' => 0, 'bomba' => true, 'faza' => $faza, 'grupa' => $grupa, 'ts' => time()]
                            : ['imie' => $im, 'pkt' => $ile, 'grupa' => $grupa, 'ts' => time()];
                    notes_sprawdz_koniec($g);
                    $t = 'Bomba! ' . $imie . ' rezygnuje, pozostali dostają po ' . $ile . '.' . ($g['koniec'] ? ' ' . $g['koniec']['zwyciezca'] . ' wygrywa!' : '');
                    break;
                case 'cofnij':
                    if (!$g['wpisy']) notes_blad('Nie ma czego wykreślić.');
                    $w = array_pop($g['wpisy']);
                    if (!empty($w['grupa'])) {
                        while ($g['wpisy'] && (end($g['wpisy'])['grupa'] ?? '') === $w['grupa']) array_pop($g['wpisy']);
                        $t = 'Wykreślona bomba.';
                    } else $t = 'Wykreślone: ' . $w['imie'] . (isset($w['kat']) ? ', ' . NOTES_UKLADY[$w['kat']]['lac'] : '') . ' ' . $w['pkt'] . '.';
                    notes_sprawdz_koniec($g);
                    break;
                case 'od_nowa':
                    notes_archiwizuj($d);
                    $g = ['id' => $g['id'], 'etap' => 'wybor', 'typ' => '', 'gracze' => $g['gracze'], 'wpisy' => [], 'koniec' => null, 'start' => time()];
                    $t = 'Nowa partia. W co gramy?';
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

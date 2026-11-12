<?php
// Notes do gry w salonie. Jedna wspólna partia dla wszystkich gości, stan w _logika/stan/notes.json (pod blokadą).
// GET: partia i kilka ostatnich zakończonych. POST {akcja, gracz, ...}:
//   wpisz {imie, pkt, glos?} | cofnij | nowa {gracze?: [imiona], cel?, nazwa?} | dodaj_gracza {imie}
// Zaklęcia głosowe rozpoznaje przeglądarka (pokoj.js); tu przychodzą już gotowe działania.
require dirname(__DIR__, 2) . '/api/_baza.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
define('NOTES_STAN', dirname(__DIR__) . '/_logika/stan');
const NOTES_MAX_GRACZY = 8;
const NOTES_MAX_WPISOW = 600;
const NOTES_ARCHIWUM = 8;

function wyj($d, $kod = 200) {
    http_response_code($kod);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function notes_blad($t) { throw new RuntimeException($t); }
function notes_imie($s) {
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    $s = preg_replace('/[^\p{L}\p{N} \-]/u', '', $s);
    return mb_substr($s, 0, 20, 'UTF-8');
}
function notes_czytaj() {
    $t = @file_get_contents(NOTES_STAN . '/notes.json');
    $d = $t === false ? null : json_decode($t, true);
    if (!is_array($d)) $d = ['gra' => null, 'archiwum' => [], 'n' => 0];
    if (!$d['gra']) $d['gra'] = notes_nowa_gra($d, ['Henryk'], 1000, 'Tysiąc');
    return $d;
}
function notes_zapisz($d) {
    $p = NOTES_STAN . '/notes.json'; $t = $p . '.tmp' . getmypid();
    file_put_contents($t, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($t, $p);
}
function notes_pod_blokada($f) {
    if (!is_dir(NOTES_STAN)) mkdir(NOTES_STAN, 0775, true);
    $h = fopen(NOTES_STAN . '/.blokada', 'c'); flock($h, LOCK_EX);
    try { return $f(); } finally { flock($h, LOCK_UN); fclose($h); }
}
function notes_nowa_gra(&$d, $gracze, $cel, $nazwa) {
    $d['n'] = (int)($d['n'] ?? 0) + 1;
    return ['id' => $d['n'], 'nazwa' => $nazwa, 'cel' => $cel, 'gracze' => array_values($gracze), 'wpisy' => [], 'start' => time(), 'koniec' => null];
}
function notes_sumy($g) {
    $s = array_fill_keys($g['gracze'], 0);
    foreach ($g['wpisy'] as $w) if (isset($s[$w['imie']])) $s[$w['imie']] += (int)$w['pkt'];
    return $s;
}
// Zwycięzca: pierwszy, kto osiągnął cel (przy remisie w tym samym wpisie decyduje wyższa suma).
function notes_sprawdz_koniec(&$g) {
    if ($g['koniec'] || (int)$g['cel'] <= 0) return;
    $s = notes_sumy($g); arsort($s);
    $imie = array_key_first($s);
    if ($imie !== null && $s[$imie] >= (int)$g['cel']) $g['koniec'] = ['zwyciezca' => $imie, 'suma' => $s[$imie], 'ts' => time()];
}
function notes_widok($d) {
    $g = $d['gra']; $s = notes_sumy($g);
    $kol = [];
    foreach ($g['gracze'] as $im) $kol[$im] = [];
    foreach ($g['wpisy'] as $i => $w) if (isset($kol[$w['imie']])) $kol[$w['imie']][] = ['pkt' => (int)$w['pkt'], 'nr' => $i, 'glos' => !empty($w['glos'])];
    $max = $s ? max($s) : 0;
    $gracze = [];
    foreach ($g['gracze'] as $im) $gracze[] = ['imie' => $im, 'suma' => $s[$im], 'wpisy' => $kol[$im], 'prowadzi' => $max > 0 && $s[$im] === $max];
    return ['id' => $g['id'], 'nazwa' => $g['nazwa'], 'cel' => (int)$g['cel'], 'gracze' => $gracze, 'wpisow' => count($g['wpisy']),
        'ostatni' => $g['wpisy'] ? count($g['wpisy']) - 1 : -1, 'koniec' => $g['koniec'], 'archiwum' => $d['archiwum']];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') wyj(notes_pod_blokada(function () { return notes_widok(notes_czytaj()); }));
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) wyj(['blad' => 'zle zadanie'], 400);
    if (za_szybko()) wyj(['blad' => 'zbyt szybko', 'tekst' => 'Pióro nie nadąża.'], 429);
    $akcja = is_string($in['akcja'] ?? null) ? $in['akcja'] : '';
    $kto = substr(preg_replace('/[^a-z0-9]/', '', strtolower(is_scalar($in['gracz'] ?? null) ? (string)$in['gracz'] : '')), 0, 12);
    wyj(notes_pod_blokada(function () use ($akcja, $in, $kto) {
        $d = notes_czytaj(); $g =& $d['gra']; $ok = true;
        try {
            switch ($akcja) {
                case 'wpisz':
                    $imie = notes_imie($in['imie'] ?? '');
                    if (!in_array($imie, $g['gracze'], true)) notes_blad('W notesie nie ma takiego gracza.');
                    if ($g['koniec']) notes_blad('Ta partia jest skończona. Zacznij nową.');
                    if (!is_numeric($in['pkt'] ?? null)) notes_blad('Pióro nie zrozumiało liczby.');
                    $pkt = (int)round((float)$in['pkt']);
                    if (abs($pkt) > 10000) notes_blad('Tyle punktów w jednym rozdaniu? Pióro się waha.');
                    if (count($g['wpisy']) >= NOTES_MAX_WPISOW) notes_blad('Notes jest zapisany do ostatniej strony.');
                    $g['wpisy'][] = ['imie' => $imie, 'pkt' => $pkt, 'ts' => time(), 'glos' => !empty($in['glos'])];
                    notes_sprawdz_koniec($g);
                    $t = $imie . ': ' . ($pkt > 0 ? '+' : '') . $pkt . '.' . ($g['koniec'] ? ' ' . $g['koniec']['zwyciezca'] . ' wygrywa!' : '');
                    break;
                case 'cofnij':
                    if (!$g['wpisy']) notes_blad('Nie ma czego wykreślić.');
                    $w = array_pop($g['wpisy']); $g['koniec'] = null;
                    $t = 'Wykreślone: ' . $w['imie'] . ' ' . $w['pkt'] . '.';
                    break;
                case 'nowa':
                    $gr = [];
                    foreach ((is_array($in['gracze'] ?? null) ? $in['gracze'] : $g['gracze']) as $im) {
                        $im = notes_imie($im);
                        if ($im !== '' && !in_array($im, $gr, true)) $gr[] = $im;
                    }
                    if (!$gr) notes_blad('Kto gra? Notes potrzebuje choć jednego imienia.');
                    if (count($gr) > NOTES_MAX_GRACZY) notes_blad('Przy stoliku zmieści się najwyżej ' . NOTES_MAX_GRACZY . ' graczy.');
                    $cel = isset($in['cel']) && is_numeric($in['cel']) ? max(0, min(100000, (int)$in['cel'])) : (int)$g['cel'];
                    $nazwa = mb_substr(trim((string)($in['nazwa'] ?? $g['nazwa'])), 0, 30, 'UTF-8') ?: 'Partia';
                    if ($g['wpisy']) {                                  // stara partia idzie do archiwum
                        $s = notes_sumy($g); arsort($s);
                        array_unshift($d['archiwum'], ['id' => $g['id'], 'nazwa' => $g['nazwa'], 'start' => $g['start'], 'sumy' => $s,
                            'zwyciezca' => $g['koniec']['zwyciezca'] ?? null]);
                        $d['archiwum'] = array_slice($d['archiwum'], 0, NOTES_ARCHIWUM);
                    }
                    $g = notes_nowa_gra($d, $gr, $cel, $nazwa);
                    $t = 'Nowa partia: ' . implode(', ', $gr) . '.';
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
            unset($g);
            notes_zapisz($d);
            dodaj_zdarzenie('notes', $akcja, $kto);
        } catch (RuntimeException $e) { $t = $e->getMessage(); $ok = false; }
        return ['ok' => $ok, 'tekst' => $t, 'stan' => notes_widok($d)];
    }));
} catch (Throwable $e) {
    wyj(['blad' => 'blad serwera'], 500);
}

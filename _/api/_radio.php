<?php
// Radio: jedna wspólna playlista dla całego serwera.
// Pliki MP3 leżą w folderze radio/. Z nich powstaje "szufla": plik _dane/radio.json z losową
// kolejnością odtwarzania i długością każdego utworu. Wszystkie przeglądarki liczą z niego, co gra
// w tej chwili (czas serwera minus "epoka"), więc wszyscy słyszą ten sam utwór, z różnicą rzędu sekundy.
//
// Kolejność: kolejne "tury" po wszystkich utworach, każda tura potasowana na nowo. Początek nowej tury
// nie zawiera żadnego z ostatnich floor(n/2) utworów poprzedniej, więc ten sam utwór wraca najwcześniej
// po floor(n/2) innych. Szufla starcza na ok. 3 doby, potem zaczyna się od początku.
// Szufla buduje się sama, gdy zmieni się zestaw plików w radio/ (dodany, usunięty lub podmieniony MP3).
//
// Utwór na godzinę: plik "DD-MM-RRRR GG-MM.mp3" (data w świecie gry) nie wchodzi do szufli. Gra raz o tej godzinie,
// a szufla czeka w tym czasie i po nim rusza od miejsca, w którym przerwała.
//
// Postacie korzystające z tego samego folderu radio ("radio": {"folder": ...}) czytają tę samą szuflę, więc słyszą to samo.
require_once __DIR__ . '/_baza.php';

const RADIO_SEKUNDY = 3 * 24 * 3600;
const RADIO_TUR_MAX = 200;

// Folder z muzyką względem katalogu postaci: domyślnie radio, a w postac.json ("radio": {"folder": "../_/radio"}) może wskazywać cudzy.
function radio_sciezka() { $f = trim((string)(postac()['radio']['folder'] ?? 'radio'), '/'); return $f === '' ? 'radio' : $f; }
function radio_folder() { return KORZEN . '/' . radio_sciezka(); }
function radio_plik_szufli() { return dirname(radio_folder()) . '/_dane/radio.json'; }   // szufla należy do folderu z muzyką, nie do postaci

// Chwila startu utworu na godzinę (ms) albo null, gdy to zwykły utwór. Kalendarz gry to dzisiejszy dzień i miesiąc z rokiem z postac.json.
function radio_start($plik) {
    if (!preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4}) (\d{1,2})-(\d{2})\.mp3$/i', $plik, $m)) return null;
    $strefa = new DateTimeZone((string)(postac()['strefa'] ?? 'Europe/Warsaw'));
    $rok = (int)(new DateTime('now', $strefa))->format('Y') + (int)$m[3] - (int)(postac()['rok'] ?? 1914);
    return (new DateTime(sprintf('%04d-%02d-%02d %02d:%02d:00', $rok, $m[2], $m[1], $m[4], $m[5]), $strefa))->getTimestamp() * 1000;
}

function radio_pliki() {
    $r = [];
    foreach (scandir(radio_folder()) ?: [] as $n) {
        if ($n[0] !== '.' && preg_match('/\.mp3$/i', $n) && is_file(radio_folder() . '/' . $n)) $r[] = $n;
    }
    sort($r, SORT_NATURAL | SORT_FLAG_CASE);
    return $r;
}

function radio_sygnatura($pliki) {
    $s = [];
    foreach ($pliki as $n) { $p = radio_folder() . '/' . $n; $s[] = $n . ':' . filesize($p) . ':' . filemtime($p); }
    return sha1('v2|' . implode('|', $s));
}

// Długość MP3 w sekundach: przejście po wszystkich ramkach (dokładne także dla VBR). null, gdy to nie MP3.
function radio_czas_mp3($sciezka) {
    $d = @file_get_contents($sciezka);
    if ($d === false || strlen($d) < 4) return null;
    $n = strlen($d); $i = 0;
    if (substr($d, 0, 3) === 'ID3' && $n > 10) {
        $i = 10 + ((ord($d[6]) & 127) << 21 | (ord($d[7]) & 127) << 14 | (ord($d[8]) & 127) << 7 | (ord($d[9]) & 127));
        if (ord($d[5]) & 0x10) $i += 10;
    }
    static $br = [
        '1:1' => [0,32,64,96,128,160,192,224,256,288,320,352,384,416,448],
        '1:2' => [0,32,48,56,64,80,96,112,128,160,192,224,256,320,384],
        '1:3' => [0,32,40,48,56,64,80,96,112,128,160,192,224,256,320],
        '2:1' => [0,32,48,56,64,80,96,112,128,144,160,176,192,224,256],
        '2:2' => [0,8,16,24,32,40,48,56,64,80,96,112,128,144,160],
    ];
    static $sr = [3 => [44100, 48000, 32000], 2 => [22050, 24000, 16000], 0 => [11025, 12000, 8000]];
    $sek = 0.0; $ramek = 0;
    while ($i + 4 <= $n) {
        if (ord($d[$i]) !== 0xFF || (ord($d[$i + 1]) & 0xE0) !== 0xE0) {
            $i = strpos($d, "\xFF", $i + 1);
            if ($i === false) break;
            continue;
        }
        $h = (ord($d[$i]) << 24) | (ord($d[$i + 1]) << 16) | (ord($d[$i + 2]) << 8) | ord($d[$i + 3]);
        $wer = ($h >> 19) & 3; $warstwa = 4 - (($h >> 17) & 3);
        $bi = ($h >> 12) & 15; $si = ($h >> 10) & 3; $pad = ($h >> 9) & 1;
        if ($wer === 1 || $warstwa === 4 || $bi === 0 || $bi === 15 || $si === 3) { $i++; continue; }
        $mp1 = ($wer === 3);
        $tab = $mp1 ? $br['1:' . $warstwa] : $br['2:' . ($warstwa === 1 ? 1 : 2)];
        $rate = $sr[$wer][$si]; $kbps = $tab[$bi];
        if ($warstwa === 1) { $dl = (intdiv(12 * $kbps * 1000, $rate) + $pad) * 4; $probek = 384; }
        else { $dl = intdiv(($mp1 || $warstwa === 2 ? 144 : 72) * $kbps * 1000, $rate) + $pad; $probek = ($mp1 || $warstwa === 2) ? 1152 : 576; }
        if ($i + $dl > $n) break;
        $sek += $probek / $rate; $ramek++; $i += $dl;
    }
    return $ramek >= 3 ? $sek : null;
}

function radio_tytul($plik) {
    $t = preg_replace('/\.mp3$/i', '', $plik);
    $t = preg_replace('/^\d+\s*[-_.)]\s*/', '', $t);
    return trim(str_replace('_', ' ', $t)) ?: $plik;
}

function radio_los($a, $b) { return random_int($a, $b); }
function radio_tasuj($t) {
    for ($i = count($t) - 1; $i > 0; $i--) { $j = radio_los(0, $i); [$t[$i], $t[$j]] = [$t[$j], $t[$i]]; }
    return $t;
}

// $stara to poprzednia szufla: utwór na godzinę zachowuje raz wyznaczony start, więc nie zagra drugi raz za rok.
function radio_zbuduj($pliki, $sygnatura, $stara = null) {
    $utwory = []; $wydarzenia = []; $znane = [];
    foreach (($stara['wydarzenia'] ?? []) as $z) $znane[$z['plik']] = $z['start'];
    foreach ($pliki as $n) {
        $c = radio_czas_mp3(radio_folder() . '/' . $n);
        if ($c === null || $c < 1) continue;
        $start = radio_start($n);
        if ($start === null) $utwory[] = ['plik' => $n, 'tytul' => radio_tytul($n), 'czas' => round($c, 3)];
        else $wydarzenia[] = ['plik' => $n, 'tytul' => preg_replace('/\.mp3$/i', '', $n), 'czas' => round($c, 3), 'start' => $znane[$n] ?? $start];
    }
    usort($wydarzenia, function ($a, $b) { return $a['start'] <=> $b['start']; });
    $koniec = 0;   // gdyby dwa nachodziły na siebie, drugi poczeka na koniec pierwszego
    foreach ($wydarzenia as &$z) { $z['start'] = max($z['start'], $koniec); $koniec = $z['start'] + (int)ceil($z['czas'] * 1000); }
    unset($z);
    $n = count($utwory);
    $kolejnosc = []; $razem = 0.0;
    if ($n > 0) {
        $tura = array_sum(array_column($utwory, 'czas'));
        $tur = max(1, min(RADIO_TUR_MAX, (int)ceil(RADIO_SEKUNDY / $tura)));
        $k = intdiv($n, 2);
        $wszystkie = range(0, $n - 1);
        $poprzednia = [];
        for ($c = 0; $c < $tur; $c++) {
            $zakaz = $k > 0 ? array_slice($poprzednia, -$k) : [];
            $wolne = radio_tasuj(array_values(array_diff($wszystkie, $zakaz)));
            $glowa = array_slice($wolne, 0, $k);
            $reszta = radio_tasuj(array_values(array_diff($wszystkie, $glowa)));
            $poprzednia = array_merge($glowa, $reszta);
            foreach ($poprzednia as $x) { $kolejnosc[] = $x; $razem += $utwory[$x]['czas']; }
        }
    }
    return [
        'wersja' => bin2hex(random_bytes(4)), 'sygnatura' => $sygnatura,
        'epoka' => (int)round(microtime(true) * 1000),   // od tej chwili (czas serwera, ms) liczy się pozycja w szufli
        'utwory' => $utwory, 'kolejnosc' => $kolejnosc, 'razem' => round($razem, 3), 'wydarzenia' => $wydarzenia,
    ];
}

// Zwraca aktualną szuflę; buduje ją, gdy jej brak, gdy zmienił się zestaw MP3 albo gdy $wymus.
function radio_szufla($wymus = false) {
    if (!is_dir(radio_folder())) return ['utwory' => [], 'kolejnosc' => [], 'razem' => 0, 'epoka' => 0, 'wersja' => '0'];
    $pliki = radio_pliki();
    $sygn = radio_sygnatura($pliki);
    $plik = radio_plik_szufli();
    $s = wczytaj_json($plik);
    if (!$wymus && $s && ($s['sygnatura'] ?? '') === $sygn) return $s;

    $dir = dirname($plik);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $blokada = @fopen($dir . '/radio.lock', 'c');
    if ($blokada) flock($blokada, LOCK_EX);
    try {
        $s2 = wczytaj_json($plik);                         // ktoś mógł zbudować ją w międzyczasie
        if (!$wymus && $s2 && ($s2['sygnatura'] ?? '') === $sygn) return $s2;
        $nowa = radio_zbuduj($pliki, $sygn, $s2 ?: $s);
        $tmp = $plik . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($nowa, JSON_UNESCAPED_UNICODE)) !== false) @rename($tmp, $plik);
        return $nowa;
    } finally {
        if ($blokada) { flock($blokada, LOCK_UN); fclose($blokada); }
    }
}

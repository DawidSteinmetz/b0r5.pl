<?php
// Pogoda w ogrodzie: symulacja klimatu z _logika/tabele/klimat.json. Nic się nie zapisuje.
// Każdy dzień wynika z daty (ziarno) i z poprzednich dni, bo pogoda ma pamięć: ciepło i deszcz lubią trwać.
// Dzięki temu wszyscy goście widzą tę samą pogodę, a serwer nie musi niczego liczyć w tle.
require_once dirname(__DIR__, 2) . '/api/_baza.php';
if (!defined('OGR_KAT')) define('OGR_KAT', dirname(__DIR__));

function pog_klimat() {
    static $k = null;
    if ($k === null) $k = wczytaj_json(OGR_KAT . '/_logika/tabele/klimat.json') ?: [];
    return $k;
}
function pog_strefa() { static $z = null; return $z ?? ($z = new DateTimeZone(postac()['strefa'] ?? 'Europe/Warsaw')); }
function pog_dzis() { return (new DateTime('now', pog_strefa()))->format('Y-m-d'); }
function pog_przesun($d, $n) { return (new DateTime($d . ' 12:00', pog_strefa()))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d'); }

// Liczba z przedziału (0, 1), zawsze ta sama dla tej samej daty i klucza.
function pog_los($d, $k) {
    $h = sha1((pog_klimat()['ziarno'] ?? 'pogoda') . '|' . $d . '|' . $k);
    return (hexdec(substr($h, 0, 12)) + 0.5) / 281474976710656;
}
function pog_normalny($d, $k) { return sqrt(-2 * log(pog_los($d, $k . 'a'))) * cos(2 * M_PI * pog_los($d, $k . 'b')); }

// Norma klimatyczna na dany dzień: wartości miesięczne leżą w połowie miesiąca, między nimi płynne przejście.
function pog_norma($d) {
    $m = pog_klimat()['miesiace'] ?? [];
    if (count($m) !== 12) return ['t' => 8, 'amp' => 8, 'sd' => 3.5, 'opad' => 45, 'dni' => 9, 'chmury' => 0.65, 'wiatr' => 3.5];
    $dt = new DateTime($d . ' 12:00', pog_strefa());
    $mi = (int)$dt->format('n') - 1; $dz = (int)$dt->format('j'); $dl = (int)$dt->format('t');
    $x = ($dz - 0.5) / $dl - 0.5;                  // -0,5..0,5 względem środka miesiąca
    $j = $x < 0 ? ($mi + 11) % 12 : ($mi + 1) % 12;
    $w = abs($x);
    $o = [];
    foreach ($m[$mi] as $pole => $v) $o[$pole] = $v * (1 - $w) + (float)($m[$j][$pole] ?? $v) * $w;
    $o['dni_w_mies'] = $dl;
    return $o;
}

// Wschód i zachód słońca (godziny czasu lokalnego, z czasem letnim, jeśli strefa go ma).
function pog_slonce($d) {
    $k = pog_klimat(); $fi = deg2rad((float)($k['szerokosc'] ?? 53.1)); $lon = (float)($k['dlugosc'] ?? 18.0);
    $dt = new DateTime($d . ' 12:00', pog_strefa());
    $N = (int)$dt->format('z') + 1;
    $dek = deg2rad(23.44) * sin(2 * M_PI * (284 + $N) / 365);
    $B = 2 * M_PI * ($N - 81) / 364;
    $rownanie = 9.87 * sin(2 * $B) - 7.53 * cos($B) - 1.5 * sin($B);          // równanie czasu, minuty
    $poludnie = 12 - $lon / 15 - $rownanie / 60 + $dt->getOffset() / 3600;
    $c = (sin(deg2rad(-0.83)) - sin($fi) * sin($dek)) / (cos($fi) * cos($dek));
    $c = max(-1, min(1, $c));
    $pol = rad2deg(acos($c)) / 15;
    return ['wschod' => $poludnie - $pol, 'zachod' => $poludnie + $pol, 'poludnie' => $poludnie, 'dlugosc' => 2 * $pol];
}

// Rozbieg zaczyna się zawsze w tym samym miejscu: na początku lipca przed sezonem, w którym leży $od.
// Wtedy nie ma śniegu, a do jesieni pamięć pogody zdąży się ustalić, więc dany dzień wychodzi zawsze tak samo,
// niezależnie od tego, od kiedy liczy zapytanie.
function pog_kotwica($od) {
    $y = (int)substr($od, 0, 4); $m = (int)substr($od, 5, 2);
    return pog_przesun(sprintf('%04d-09-01', $m >= 9 ? $y : $y - 1), -60);
}
// Kolejne dni od $od do $do włącznie. Wynik zapamiętuje się na czas zapytania.
function pog_seria($od, $do) {
    static $pam = [];
    $start = pog_kotwica($od);
    $k = pog_klimat(); $fi = (float)($k['pamiec_temp'] ?? 0.72); $pm = (float)($k['pamiec_opadu'] ?? 0.3);
    $a = 0.0; $mokro = false; $snieg = 0.0; $gleba = null; $wyn = [];
    for ($d = $start; $d <= $do; $d = pog_przesun($d, 1)) {
        if (isset($pam[$d])) { $z = $pam[$d]; $a = $z['_a']; $mokro = $z['mokro']; $snieg = $z['snieg_mm']; $gleba = $z['gleba']; if ($d >= $od) $wyn[$d] = $z; continue; }
        $n = pog_norma($d);
        $p = min(0.9, $n['dni'] / $n['dni_w_mies']);
        $p11 = $p + $pm * (1 - $p);                               // po dniu z opadem łatwiej o kolejny
        $p01 = $p * (1 - $p11) / max(0.01, 1 - $p);
        $mokro = pog_los($d, 'opad?') < ($mokro ? $p11 : $p01);
        $a = $fi * $a + sqrt(1 - $fi * $fi) * $n['sd'] * pog_normalny($d, 'temp');
        $mies = (int)substr($d, 5, 2); $lato = $mies >= 4 && $mies <= 9;
        $opad = 0.0;
        if ($mokro) $opad = round(max(0.3, -($n['opad'] / max(1, $n['dni'])) * log(pog_los($d, 'ile'))), 1);
        $chmury = $mokro ? 0.75 + 0.25 * pog_los($d, 'chm') : max(0.0, min(1.0, $n['chmury'] - 0.3 + 0.55 * pog_los($d, 'chm')));
        $t = $n['t'] + $a + ($mokro ? ($lato ? -1.2 : 0.6) : 0) + ($lato ? 1.5 : -1.0) * (0.55 - $chmury);
        $amp = $n['amp'] * (1.25 - 0.7 * $chmury);
        $tmin = $t - $amp / 2; $tmax = $t + $amp / 2;
        // Rodzaj opadu i śnieg (w mm wody). Śnieg topnieje w cieple i w deszczu.
        $rodzaj = '';
        $woda = 0.0;                                              // to, co tego dnia wsiąka w ziemię
        if ($opad > 0) {
            if ($tmax < 1.5 || $t < 0) $rodzaj = 'snieg';
            elseif ($t < 2) $rodzaj = 'deszcz ze śniegiem';
            elseif ($lato && $opad >= 9 && $tmax >= 22) $rodzaj = 'burza';
            elseif ($opad < 1) $rodzaj = 'mżawka';
            else $rodzaj = 'deszcz';
            if ($rodzaj === 'snieg') $snieg += $opad;
            elseif ($rodzaj === 'deszcz ze śniegiem') { $snieg += $opad / 2; $woda += $opad / 2; }
            else $woda += $opad;
        }
        if ($snieg > 0) {
            $top = min($snieg, max(0.0, $t) * 2.5 + ($rodzaj !== 'snieg' ? $woda * 0.15 : 0));
            $snieg -= $top; $woda += $top;
            if ($snieg < 0.2) $snieg = 0.0;
        }
        // Ziemia na głębokości kilku centymetrów: idzie za powietrzem z opóźnieniem, a pod śniegiem nie zamarza mocno.
        $gleba = $gleba === null ? $t : $gleba + 0.22 * ($t - $gleba);
        if ($snieg >= 15) $gleba = max($gleba, -0.8);
        $wiatr = round(($n['wiatr'] / 0.886) * sqrt(-log(pog_los($d, 'wiatr'))) * ($mokro ? 1.15 : 0.95), 1);
        $kier = pog_los($d, 'kier');
        $kierunek = $kier < 0.30 ? 'zachodni' : ($kier < 0.48 ? 'południowo-zachodni' : ($kier < 0.60 ? 'północno-zachodni' : ($kier < 0.72 ? 'południowy' : ($kier < 0.82 ? 'wschodni' : ($kier < 0.91 ? 'północny' : ($kier < 0.96 ? 'południowo-wschodni' : 'północno-wschodni'))))));
        $mgla = !$mokro && $wiatr < 2.5 && pog_los($d, 'mgla') < (in_array($mies, [10, 11, 12, 1, 2, 3], true) ? 0.35 : 0.12);
        $godz = [];                                              // godziny, w których pada
        if ($opad > 0) {
            if ($rodzaj === 'burza') { $s = 14 + (int)floor(pog_los($d, 'g0') * 5); $godz = [$s, $s + 1]; }
            else {
                $dl = max(1, min(18, (int)round(1 + $opad * 0.9 + pog_los($d, 'g1') * 3)));
                $s = (int)floor(pog_los($d, 'g0') * 24);
                for ($i = 0; $i < $dl; $i++) $godz[] = ($s + $i) % 24;
            }
        }
        $z = ['data' => $d, 'mokro' => $mokro, 't' => round($t, 1), 'tmin' => round($tmin, 1), 'tmax' => round($tmax, 1), 'opad' => $opad, 'rodzaj' => $rodzaj,
            'woda' => round($woda, 1), 'snieg_mm' => round($snieg, 1), 'gleba' => round($gleba, 1), 'chmury' => round($chmury, 2), 'wiatr' => $wiatr, 'kierunek' => $kierunek,
            'mgla' => $mgla, 'godziny' => $godz, '_a' => $a];
        $pam[$d] = $z;
        if ($d >= $od) $wyn[$d] = $z;
    }
    return $wyn;
}
function pog_dzien($d) { return pog_seria($d, $d)[$d]; }

// --- opis słowami --------------------------------------------------------------------------------
function pog_st($x) { $x = round($x); return ($x < 0 ? '−' : '') . abs($x) . '°C'; }
function pog_niebo($chmury) {
    return $chmury < 0.2 ? 'bezchmurnie' : ($chmury < 0.45 ? 'pogodnie' : ($chmury < 0.7 ? 'zmienne zachmurzenie' : ($chmury < 0.88 ? 'pochmurno' : 'szaro i nisko')));
}
function pog_wiatr_txt($v) {
    return $v < 1 ? 'cisza' : ($v < 3 ? 'słaby wiatr' : ($v < 6 ? 'umiarkowany wiatr' : ($v < 10 ? 'silny wiatr' : 'wichura')));
}
function pog_opis_dnia($z) {
    $c = [];
    $c[] = $z['rodzaj'] !== '' ? ($z['rodzaj'] === 'snieg' ? 'śnieg' : $z['rodzaj']) . ' (' . str_replace('.', ',', (string)$z['opad']) . ' mm)' : pog_niebo($z['chmury']);
    if ($z['mgla']) $c[] = 'rano mgła';
    if ($z['tmin'] < 0 && $z['tmax'] > 0) $c[] = 'przymrozek';
    elseif ($z['tmax'] <= 0) $c[] = 'mróz cały dzień';
    return implode(', ', $c);
}

// Temperatura o godzinie $h (0–24) dnia $d: minimum tuż przed wschodem, maksimum po południu,
// a średnia płynnie przechodzi w średnią sąsiedniego dnia.
function pog_temp_o($d, $h) {
    $s = pog_seria(pog_przesun($d, -1), pog_przesun($d, 1));
    $wcz = $s[pog_przesun($d, -1)]; $dzis = $s[$d]; $jut = $s[pog_przesun($d, 1)];
    $mix = function ($a, $b, $w) { return $a + ($b - $a) * $w; };
    if ($h < 12) { $sr = $mix($wcz['t'], $dzis['t'], ($h + 12) / 24); $amp = $mix($wcz['tmax'] - $wcz['tmin'], $dzis['tmax'] - $dzis['tmin'], ($h + 12) / 24); }
    else { $sr = $mix($dzis['t'], $jut['t'], ($h - 12) / 24); $amp = $mix($dzis['tmax'] - $dzis['tmin'], $jut['tmax'] - $jut['tmin'], ($h - 12) / 24); }
    $sl = pog_slonce($d); $min = $sl['wschod']; $max = $sl['poludnie'] + 2.5;
    // krzywa: od minimum do maksimum w górę, potem w dół do minimum następnego dnia
    if ($h >= $min && $h <= $max) $f = -cos(M_PI * ($h - $min) / ($max - $min));
    else { $dl = 24 - ($max - $min); $x = $h > $max ? $h - $max : $h + 24 - $max; $f = cos(M_PI * $x / $dl); }
    return $sr + $amp / 2 * $f;
}

// Pogoda "teraz" dla okna termometru: bieżąca godzina, ostatnie dni i przepowiednia barometru na jutro.
function pog_widok($dni_wstecz = 7) {
    $tz = pog_strefa(); $teraz = new DateTime('now', $tz);
    $d = $teraz->format('Y-m-d'); $h = (int)$teraz->format('G') + (int)$teraz->format('i') / 60;
    $s = pog_seria(pog_przesun($d, -$dni_wstecz), pog_przesun($d, 1));
    $z = $s[$d]; $jutro = $s[pog_przesun($d, 1)];
    $sl = pog_slonce($d);
    $pada = in_array((int)floor($h), $z['godziny'], true);
    $mgla = $z['mgla'] && $h >= $sl['wschod'] - 2 && $h < $sl['wschod'] + 3.5;
    $temp = pog_temp_o($d, $h);
    $noc = $h < $sl['wschod'] || $h > $sl['zachod'];
    $teraz_txt = $pada ? ($z['rodzaj'] === 'snieg' ? 'pada śnieg' : ($z['rodzaj'] === 'burza' ? 'burza' : ($z['rodzaj'] === 'mżawka' ? 'mży' : ($z['rodzaj'] === 'deszcz ze śniegiem' ? 'pada deszcz ze śniegiem' : 'pada deszcz'))))
        : ($mgla ? 'mgła' : ($noc ? ($z['chmury'] < 0.45 ? 'gwiaździście' : 'ciemno i ' . ($z['chmury'] < 0.88 ? 'pochmurno' : 'nisko')) : pog_niebo($z['chmury'])));
    // Barometr myli się mniej więcej raz na pięć dni.
    $traf = pog_los($d, 'barometr') < 0.8;
    $deszcz_jutro = $traf ? $jutro['mokro'] : !$jutro['mokro'];
    $zapowiedz = $deszcz_jutro ? ($jutro['tmax'] < 1 ? 'Barometr spada. Zanosi się na śnieg.' : 'Barometr spada. Jutro raczej popada.')
        : ($jutro['tmin'] < 0 ? 'Barometr stoi wysoko. Jutro sucho, w nocy przymrozek.' : 'Barometr stoi wysoko. Jutro raczej sucho.');
    $ziemia = $z['snieg_mm'] >= 10 ? 'pod śniegiem' : ($z['gleba'] < -0.5 ? 'zmarznięta' : ($z['gleba'] < 5 ? 'zimna' : ($z['gleba'] < 10 ? 'chłodna' : 'ciepła')));
    $ost = [];
    foreach ($s as $data => $x) if ($data < $d) $ost[] = ['data' => $data, 'opis' => pog_opis_dnia($x), 'tmin' => $x['tmin'], 'tmax' => $x['tmax'], 'opad' => $x['opad']];
    $hm = function ($x) { $x = fmod($x + 24, 24); return sprintf('%d:%02d', (int)floor($x), (int)round(($x - floor($x)) * 60) % 60); };
    return [
        'miejsce' => pog_klimat()['miejsce'] ?? '',
        'teraz' => ['temp' => round($temp, 1), 'temp_t' => pog_st($temp), 'opis' => $teraz_txt, 'pada' => $pada ? $z['rodzaj'] : '', 'mgla' => $mgla, 'noc' => $noc,
            'wiatr' => $z['wiatr'], 'wiatr_t' => pog_wiatr_txt($z['wiatr']) . ($z['wiatr'] >= 1 ? ', ' . $z['kierunek'] : ''), 'chmury' => $z['chmury']],
        'dzis' => ['tmin' => $z['tmin'], 'tmax' => $z['tmax'], 'opis' => pog_opis_dnia($z), 'wschod' => $hm($sl['wschod']), 'zachod' => $hm($sl['zachod']),
            'snieg_cm' => (int)round($z['snieg_mm'] / 3), 'ziemia' => $ziemia, 'gleba' => $z['gleba']],
        'zapowiedz' => $zapowiedz,
        'ostatnie' => array_reverse($ost),
    ];
}

<?php
// Alchemia piwnicy. Każda rzecz to osobny plik w _logika/stan/rzeczy/, a skład liczy się w molach atomów.
// Działania trwające (czar, waga, gotowanie) zapisują czas końca i kończą się przy pierwszym zapytaniu po jego upływie.
require_once __DIR__ . '/_rynek.php';
define('ALCH_TAB', KAT_POKOJU . '/_logika/tabele');
define('ALCH_STAN', KAT_POKOJU . '/_logika/stan');
const ALCH_MAX_RZECZY = 400;
const ALCH_MALO = 1e-7;

function alch_blad($t) { throw new RuntimeException($t); }
function alch_tabela($n) {
    static $c = [];
    if (!isset($c[$n])) { $j = wczytaj_json(ALCH_TAB . "/$n.json") ?: []; unset($j['_opis']); $c[$n] = $j; }
    return $c[$n];
}
function alch_cfg() { return alch_tabela('alchemia'); }
function alch_surowce() { return alch_tabela('surowce') + (alch_cfg()['surowce_reczne'] ?? []); }
function alch_M($e) { return (float)(alch_tabela('pierwiastki')[$e]['masa'] ?? 0); }
function alch_nazwa_el($e) { return mb_strtolower((string)(alch_tabela('pierwiastki')[$e]['nazwa'] ?? $e), 'UTF-8'); }
function alch_masa($sklad) { $m = 0.0; foreach ($sklad as $e => $mol) $m += $mol * alch_M($e); return $m; }
function alch_f($x, $d = 2) { return str_replace('.', ',', (string)round($x, $d)); }

// --- pliki stanu ---------------------------------------------------------------------------------
function alch_czytaj($n) { $t = @file_get_contents(ALCH_STAN . "/$n.json"); return $t === false ? null : json_decode($t, true); }
function alch_zapisz($n, $d) {
    $p = ALCH_STAN . "/$n.json"; $t = $p . '.tmp' . getmypid();
    file_put_contents($t, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($t, $p);
}
function alch_rzecz($id) { return preg_match('/^p\d{4,}$/', (string)$id) ? alch_czytaj("rzeczy/$id") : null; }
function alch_zapisz_rzecz($r) { alch_zapisz('rzeczy/' . $r['id'], $r); }
function alch_usun_rzecz($id) { @unlink(ALCH_STAN . "/rzeczy/$id.json"); }
function alch_rzeczy() {
    $o = [];
    foreach (glob(ALCH_STAN . '/rzeczy/p*.json') ?: [] as $f) { $r = json_decode((string)@file_get_contents($f), true); if ($r) $o[$r['id']] = $r; }
    ksort($o);
    return $o;
}
function alch_nowe_id() { $n = (int)((alch_czytaj('licznik') ?: [])['n'] ?? 0) + 1; alch_zapisz('licznik', ['n' => $n]); return sprintf('p%04d', $n); }
function alch_pod_blokada($f) {
    if (!is_dir(ALCH_STAN . '/rzeczy')) mkdir(ALCH_STAN . '/rzeczy', 0775, true);
    $h = fopen(ALCH_STAN . '/.blokada', 'c'); flock($h, LOCK_EX);
    try { return $f(); } finally { flock($h, LOCK_UN); fclose($h); }
}
const ALCH_GAZY = ['H', 'O', 'N', 'Cl', 'F'];        // pierwiastki, które same łączą się w pary (H₂, O₂, N₂, Cl₂, F₂) i ulatują
function alch_ulatnij(&$r, $e, $czar, $teraz) {
    $mol = (float)$r['sklad'][$e]; unset($r['sklad'][$e]);
    $r['ulot'] = ['e' => $e, 'mol' => round($mol, 6), 'g' => round($mol * alch_M($e), 3), 'czar' => $czar, 'ts' => $teraz];
}
const ALCH_ULOT_S = 8;                              // ile sekund gaz ulatuje po wyssaniu partnera (wtedy da się go złapać)
function alch_kociolek() { return alch_czytaj('kociolek') ?: ['sklad' => [], 'pochodzenie' => [], 'zawartosc' => [], 'koniec' => null, 'katalizator' => null, 'wynik' => '']; }

// --- rzeczy --------------------------------------------------------------------------------------
// $porcja_g i $nazwa pozwalają wydać ten sam surowiec w innej porcji (dzban zamiast kubka).
// Skład jednej sztuki: 'widelki' losują wskazane pierwiastki, a reszta dopełnia do 100%.
// Skład jednej sztuki w % masy. 'widelki' surowca losują wskazane pierwiastki (reszta dopełnia do 100%).
function alch_losuj_widelki($s) {
    if (empty($s['widelki'])) return $s['masowo'];
    $o = []; $suma = 0.0; $reszta = 0.0;
    foreach ($s['widelki'] as $e => $z) { $o[$e] = $z[0] + mt_rand() / mt_getrandmax() * ($z[1] - $z[0]); $suma += $o[$e]; }
    foreach ($s['masowo'] as $e => $p) if (!isset($o[$e])) $reszta += $p;
    foreach ($s['masowo'] as $e => $p) if (!isset($s['widelki'][$e])) $o[$e] = $p * (100 - $suma) / $reszta;
    return $o;
}
// Naturalna zmienność każdej porcji (alchemia.json: 'zmiennosc'). Dwie części: wilgotność losowana z zakresu 'woda' (%),
// a sucha część dostaje rozrzut 'rozrzut' (% względnie, jak odchylenie) i wraca do swojej masy. Woda to H 11,19% i O 88,81%.
function alch_los() { return mt_rand(1, mt_getrandmax()) / mt_getrandmax(); }
function alch_losuj_sklad($s, $sid = '') {
    $o = alch_losuj_widelki($s);
    $zm = alch_cfg()['zmiennosc'] ?? [];
    $z = $zm[$sid] ?? ($zm['_domyslnie'] ?? null);
    if (!is_array($z)) return $o;
    $wH = 0.1119; $wO = 0.8881;
    $w0 = 0.0; $w = 0.0;
    if (!empty($z['woda'])) {                         // ile wody jest we wzorcu: środek zakresu, ale nie więcej, niż pozwala wodór i tlen
        $w0 = min(($z['woda'][0] + $z['woda'][1]) / 2, ($o['H'] ?? 0) / $wH, ($o['O'] ?? 0) / $wO);
        $w = $z['woda'][0] + alch_los() * ($z['woda'][1] - $z['woda'][0]);
    }
    $sucha = $o;
    if ($w0 > 0) { $sucha['H'] = max(0, $sucha['H'] - $w0 * $wH); $sucha['O'] = max(0, $sucha['O'] - $w0 * $wO); }
    $r = (float)($z['rozrzut'] ?? 0) / 100;
    if ($r > 0) foreach ($sucha as $e => $p) $sucha[$e] = $p * max(.2, 1 + $r * sqrt(-2 * log(alch_los())) * cos(2 * M_PI * alch_los()));
    $suma = array_sum($sucha);
    if ($suma <= 0) return $o;
    $cel = 100 - (empty($z['woda']) ? 0 : $w);       // sucha część zajmuje to, czego nie zajęła woda
    $wyn = [];
    foreach ($sucha as $e => $p) $wyn[$e] = $p * $cel / $suma;
    if (!empty($z['woda'])) { $wyn['H'] = ($wyn['H'] ?? 0) + $w * $wH; $wyn['O'] = ($wyn['O'] ?? 0) + $w * $wO; }
    return $wyn;
}
function alch_nowy_surowiec($sid, $porcja_g = null, $nazwa = null) {
    $s = alch_surowce()[$sid] ?? null;
    if (!$s) return null;
    $cfg = alch_cfg();
    $proc = alch_losuj_sklad($s, $sid);
    if ($porcja_g !== null) $masa = (float)$porcja_g;
    elseif (isset($cfg['mol_staly'][$sid])) {           // np. węgiel aktywny: zawsze 1 mol węgla, a masa wynika z reszty porcji
        $e = array_key_first($cfg['mol_staly'][$sid]);
        $masa = round((float)$cfg['mol_staly'][$sid][$e] * alch_M($e) / max(1e-6, ($proc[$e] ?? 100) / 100), 2);
    }
    else $masa = isset($cfg['masa_stala'][$sid]) ? (float)$cfg['masa_stala'][$sid] : round($s['masa_od'] + mt_rand() / mt_getrandmax() * ($s['masa_do'] - $s['masa_od']), 1);
    $sklad = [];
    foreach ($proc as $e => $p) { $M = alch_M($e); if ($M > 0 && $p > 0) $sklad[$e] = round($masa * $p / 100 / $M, 6); }
    if (isset($cfg['mol_staly'][$sid])) foreach ($cfg['mol_staly'][$sid] as $e => $mol) $sklad[$e] = (float)$mol;
    $r = ['id' => alch_nowe_id(), 'typ' => 'surowiec', 'surowiec' => $sid, 'nazwa' => $nazwa ?? $s['nazwa'], 'gdzie' => 'sklad', 'sklad' => $sklad, 'odessany' => false,
          'pochodzenie' => [$sid => $masa], 'zbadany' => false, 'czar' => null];
    alch_zapisz_rzecz($r);
    return $r;
}
function alch_z_czego($sid) {
    $cfg = alch_cfg();
    if (isset($cfg['przymiotniki'][$sid]['z'])) return $cfg['przymiotniki'][$sid]['z'];
    if (isset($cfg['zrodla_neutralne'][$sid])) return $cfg['zrodla_neutralne'][$sid];
    return '(' . mb_strtolower((string)(alch_surowce()[$sid]['nazwa'] ?? $sid), 'UTF-8') . ')';
}
// Wyssany pierwiastek: kopczyki łączą się tylko przy tym samym pierwiastku i pochodzeniu.
function alch_dosyp_kopczyk($e, $mol, $sid) {
    foreach (alch_rzeczy() as $r) {
        if ($r['typ'] === 'kopczyk' && $r['pierwiastek'] === $e && $r['zrodlo'] === $sid) {
            $r['sklad'][$e] = round($r['sklad'][$e] + $mol, 6);
            $r['pochodzenie'][$sid] = alch_masa($r['sklad']);
            alch_zapisz_rzecz($r);
            return;
        }
    }
    alch_zapisz_rzecz(['id' => alch_nowe_id(), 'typ' => 'kopczyk', 'pierwiastek' => $e, 'zrodlo' => $sid, 'gdzie' => 'sklad',
        'nazwa' => mb_convert_case(alch_nazwa_el($e), MB_CASE_TITLE, 'UTF-8') . ' ' . alch_z_czego($sid),
        'sklad' => [$e => round($mol, 6)], 'pochodzenie' => [$sid => $mol * alch_M($e)], 'zbadany' => true, 'czar' => null]);
}

// --- katalizatory i produkt ----------------------------------------------------------------------
// Ukryty katalizator działa, gdy jego przedmiot przenośny leży w tym pokoju.
function alch_katalizatory() {
    $wyn = []; $miejsca = stan()['miejsca']; $grupy = przenosne_grupy();
    foreach (alch_tabela('katalizatory') as $id => $k) {
        $g = $k['ukryty'] ?? null;
        if ($g !== null && isset($grupy[$g]) && explode('/', (string)($miejsca[$g] ?? ''))[0] !== basename(KAT_POKOJU)) continue;
        if (isset($k['wymaga']) && !alch_jest_surowiec($k['wymaga'])) continue;
        $wyn[$id] = $k;
    }
    return $wyn;
}
function alch_jest_surowiec($sid) {
    foreach (alch_rzeczy() as $r) if ($r['typ'] === 'surowiec' && $r['surowiec'] === $sid) return true;
    return false;
}
function alch_przymiotnik($pochodzenie, $rodzaj) {
    $cfg = alch_cfg(); $naj = null; $najm = 0.0;
    foreach ($pochodzenie as $sid => $m) if (isset($cfg['przymiotniki'][$sid]) && $m > $najm) { $naj = $sid; $najm = $m; }
    return $naj === null ? '' : $cfg['przymiotniki'][$naj][$rodzaj];
}
function alch_stopien($czystosc, $rodzaj) {
    foreach (alch_cfg()['progi_czystosci'] as $p) if ($czystosc >= (float)$p['od']) return $p[$rodzaj];
    return '';
}
// Czystość to masa produktu, jaki da się ułożyć ze wzoru, podzielona przez masę całej zawartości.
function alch_uwarz($sklad, $k) {
    $n = INF;
    foreach ($k['wzor'] as $e => $ile) $n = min($n, (float)($sklad[$e] ?? 0) / $ile);
    $masa = alch_masa($sklad);
    if ($n <= ALCH_MALO || $masa <= 0) return null;
    $m1 = 0.0;
    foreach ($k['wzor'] as $e => $ile) $m1 += $ile * alch_M($e);
    return ['masa' => $masa, 'czystosc' => min(1.0, $n * $m1 / $masa)];
}
// Ilość produktu do pokazania: ciecze w litrach (1 g liczy się jak 1 ml, tak jak pojemność kociołka).
function alch_ile($masa, $kat) { return !empty($kat['ciecz']) ? alch_f($masa / 1000, 3) . ' l' : alch_f($masa, 2) . ' g'; }
function alch_kategoria($sid) {
    foreach (alch_cfg()['kategorie'] ?? [] as $k) if (in_array($sid, $k['surowce'], true)) return $k['id'];
    return 'inne';
}
function alch_cena($r, $o) {
    $k = alch_tabela('katalizatory')[$r['katalizator']] ?? null;
    if (!$k) return 1;
    return max(1, (int)round(alch_masa($r['sklad']) / 10 * (float)$k['cena_10g'] * rynek_mnoznik_skupu($o) * pow((float)$r['czystosc'], 2)));
}

// --- czary-przepisy: projektowane ręcznie w tabele/przepisy.json, mechanizm jest wspólny -----------
// Przepisy z wariantami: wpis z 'warianty' rozkłada się na osobne przepisy o id "karta~wariant" (ta sama kartka, nazwa i katalizator,
// inne składniki). Przepisy bez wariantów idą pierwsze, żeby stare rzeczy bez 'cel_dla' trafiały tam, gdzie trafiały wcześniej.
function alch_przepisy() {
    static $wyn = null;
    if ($wyn !== null) return $wyn;
    $proste = []; $warianty = [];
    foreach (alch_tabela('przepisy') as $id => $x) {
        if (empty($x['warianty'])) { $proste[$id] = $x + ['karta' => $id, 'wariant' => '']; continue; }
        foreach ($x['warianty'] as $w) $warianty[$id . '~' . $w['id']] = ['nazwa' => $x['nazwa'], 'katalizator' => $w['katalizator'] ?? $x['katalizator'],
            'skladniki' => $w['skladniki'], 'karta' => $id, 'wariant' => $w['nazwa']];
    }
    return $wyn = $proste + $warianty;
}
// Przepis po id; stare id kartki bez wariantu (np. z zapisanego zaznaczenia) prowadzi do jej pierwszego wariantu.
function alch_przepis($id) {
    $P = alch_przepisy();
    if (isset($P[$id])) return $P[$id];
    foreach ($P as $p) if ($p['karta'] === $id) return $p;
    return null;
}
function alch_przepis_id($id) {
    $P = alch_przepisy();
    if (isset($P[$id])) return $id;
    foreach ($P as $i => $p) if ($p['karta'] === $id) return $i;
    return '';
}
function alch_pojemnosc() { return (float)(alch_cfg()['pojemnosc_g'] ?? 12000); }
function alch_miejsce($masa) {
    if (alch_masa(alch_kociolek()['sklad']) + $masa > alch_pojemnosc() + 0.01)
        alch_blad('Tyle się nie zmieści. Kociołek mieści ' . alch_f(alch_pojemnosc() / 1000, 0) . ' l.');
}
// Czy surowiec ma nadal skład z tablicy (nic z niego nie wyssano). Część surowca zachowuje proporcje, więc też się liczy.
function alch_nienaruszony($r) {
    if (array_key_exists('odessany', $r)) return !$r['odessany'];   // rzeczy starsze od tego pola porównuje się z tablicą
    $s = alch_surowce()[$r['surowiec'] ?? ''] ?? null;
    if (!$s) return false;
    $wzor = null; $wzorP = 0.0;
    foreach ($s['masowo'] as $e => $p) if (alch_M($e) > 0 && $p > $wzorP) { $wzor = $e; $wzorP = $p; }
    if ($wzor === null || empty($r['sklad'][$wzor])) return false;
    $k = $r['sklad'][$wzor] / ($wzorP / alch_M($wzor));
    foreach ($s['masowo'] as $e => $p) {
        if (alch_M($e) <= 0) continue;
        $spod = $k * $p / alch_M($e);
        if (abs((float)($r['sklad'][$e] ?? 0) - $spod) > max(0.0001, 0.01 * $spod)) return false;
    }
    return true;
}
// Co czar może wziąć, najpierw najmniejsze resztki. Pod kluczem surowca leżą całe, nieobrabiane sztuki, a pod 'surowiec#o' odessane.
function alch_zapasy() {
    $w = alch_czytaj('waga'); $idw = $w ? $w['id'] : ''; $z = [];
    foreach (alch_rzeczy() as $r) {
        if ($r['typ'] !== 'surowiec' || $r['gdzie'] !== 'sklad' || $r['id'] === $idw || !empty($r['czar'])) continue;
        if (alch_nienaruszony($r)) { $z[$r['surowiec']][] = $r; continue; }
        $c = alch_cel_dla($r);
        $z[$r['surowiec'] . '#o@' . ($c['przepis_id'] ?? '')][] = $r;
    }
    foreach ($z as &$l) usort($l, function ($a, $b) { return alch_masa($a['sklad']) <=> alch_masa($b['sklad']); });
    return $z;
}
// Klucz zapasu: surowiec nienaruszony albo odessany pod konkretny przepis (burak pod cukier to nie burak pod eliksir).
function alch_klucz_zapasu($x, $pid) { return $x['surowiec'] . (isset($x['cel']) ? '#o@' . $pid : ''); }
// Składnik, który czar każe najpierw odessać, jeśli ten surowiec jest w jakimś przepisie.
// Cel odsysania dla rzeczy: przepis, pod który leży na stole ('cel_dla'), a dla starszych rzeczy pierwszy przepis z celem dla tego surowca.
function alch_cel_dla($r) {
    $sid = is_array($r) ? ($r['surowiec'] ?? '') : (string)$r;
    $P = alch_przepisy();
    $pid = is_array($r) ? alch_przepis_id((string)($r['cel_dla'] ?? '')) : '';
    $kolej = $pid !== '' ? [$pid => $P[$pid]] + $P : $P;
    foreach ($kolej as $id => $p) foreach ($p['skladniki'] as $x) if ($x['surowiec'] === $sid && isset($x['cel']))
        return $x + ['przepis' => $p['nazwa'] . ($p['wariant'] !== '' ? ' (' . $p['wariant'] . ')' : ''), 'przepis_id' => $id];
    return null;
}
// Ile moli każdego pierwiastka jest ponad proporcję z 'cel'. Różnica do 1% nie liczy się jako nadmiar.
function alch_k($sklad, $cel) {
    $k = INF;
    foreach ($cel as $e => $c) $k = min($k, (float)($sklad[$e] ?? 0) / $c);
    return $k;
}
function alch_masa_celu($cel) { $m = 0.0; foreach ($cel as $e => $c) $m += $c * alch_M($e); return $m; }
// Najlepsze sztuki na początek: najmniej domieszek na jednostkę tego, co da się wykorzystać.
function alch_najlepsze($lista, $cel) {
    $ocena = function ($r) use ($cel) {
        $k = alch_k($r['sklad'], $cel); if ($k <= 0) return INF;
        $s = 0.0; foreach ($r['sklad'] as $e => $m) if (!isset($cel[$e])) $s += $m * alch_M($e);
        return $s / $k;
    };
    usort($lista, function ($a, $b) use ($ocena) { return $ocena($a) <=> $ocena($b); });
    return $lista;
}
function alch_nadmiar($sklad, $cel) {
    $k = alch_k($sklad, $cel); $n = [];
    foreach ($cel as $e => $c) { $x = (float)($sklad[$e] ?? 0) - $k * $c; if ($x > max(0.0001, 0.01 * $k * $c)) $n[$e] = round($x, 4); }
    return $n;
}
function alch_nazwa_gotowego($x, $r) { return $x['cel_nazwa'] . ((int)($r['szt'] ?? 1) > 1 ? ' ×' . (int)$r['szt'] : ''); }
// Kilka sztuk tego samego surowca leży na stole jako jeden stos, więc czary działają na wszystkie naraz.
function alch_stos($lista, $pid = '') {
    $czesci = $lista;
    $a = array_shift($lista); $n = 1;
    $a['czesci'] = $czesci;
    foreach ($lista as $r) {
        foreach ($r['sklad'] as $e => $m) $a['sklad'][$e] = round((float)($a['sklad'][$e] ?? 0) + $m, 6);
        foreach ($r['pochodzenie'] as $k => $m) $a['pochodzenie'][$k] = (float)($a['pochodzenie'][$k] ?? 0) + $m;
        alch_usun_rzecz($r['id']); $n++;
    }
    if ($n > 1) $a['nazwa'] .= ' ×' . $n;
    $a['szt'] = $n; $a['gdzie'] = 'stol'; $a['zbadany'] = true;
    if ($pid !== '') $a['cel_dla'] = $pid;                // pamięta, pod który przepis jest odsysany
    alch_zapisz_rzecz($a); alch_zapisz('stol', ['id' => $a['id']]);
    return $a;
}
// Stos, z którego nic nie wyssano, wraca do Składu jako te same sztuki (stary stos bez zapisanych sztuk: po równo).
function alch_rozloz_stosy() {
    foreach (alch_rzeczy() as $r) {
        $n = (int)($r['szt'] ?? 1);
        if ($r['typ'] !== 'surowiec' || $n < 2 || $r['gdzie'] !== 'sklad' || !alch_nienaruszony($r)) continue;
        if (!empty($r['czesci'])) { foreach ($r['czesci'] as $c) alch_zapisz_rzecz($c); continue; }
        $sid = $r['surowiec'];
        foreach ($r['sklad'] as $e => $mol) $r['sklad'][$e] = round($mol / $n, 6);
        $r['nazwa'] = alch_surowce()[$sid]['nazwa']; $r['pochodzenie'] = [$sid => alch_masa($r['sklad'])];
        unset($r['szt']);
        alch_zapisz_rzecz($r);
        for ($i = 1; $i < $n; $i++) { $r['id'] = alch_nowe_id(); alch_zapisz_rzecz($r); }
    }
}
function alch_przepis_widok($id, $p, $zapasy, $kat, $poj) {
    $sur = alch_surowce(); $skl = []; $min = INF; $max = 0.0; $suma = 0.0; $M = []; $inne = INF; $brakOdessanego = false;
    foreach ($p['skladniki'] as $x) {
        $sid = $x['surowiec']; $g = (float)$x['g']; $mam = 0.0; $na = []; $cel = $x['cel'] ?? null;
        $lista = $zapasy[alch_klucz_zapasu($x, $id)] ?? [];
        foreach ($lista as $r) $mam += alch_masa($r['sklad']);
        if ($cel && $mam > 0) {
            // Prawdziwa masa porcji: atomy z 'cel' plus domieszki, jakie akurat mają te sztuki.
            $at = [];
            foreach ($lista as $r) foreach ($r['sklad'] as $e => $mol) { $na[$e] = ($na[$e] ?? 0) + $mol / $mam; $at[$e] = ($at[$e] ?? 0) + $mol; }
            $kc = alch_k($at, $cel);
            if ($kc > 0) $g *= $mam / ($kc * alch_masa_celu($cel));
        } elseif ($cel) {
            $m1 = alch_masa_celu($cel);
            foreach ($cel as $e => $c) $na[$e] = $c / $m1;
        } else {
            $sumaP = 0.0; foreach (($sur[$sid]['masowo'] ?? []) as $e => $pr) if (alch_M($e) > 0) $sumaP += $pr;
            foreach (($sur[$sid]['masowo'] ?? []) as $e => $pr) if (alch_M($e) > 0) $na[$e] = $pr / alch_M($e) / $sumaP;
        }
        foreach ($na as $e => $v) $M[$e] = alch_M($e);
        $w = ['surowiec' => $sid, 'nazwa' => $x['cel_nazwa'] ?? ($sur[$sid]['nazwa'] ?? $sid), 'g' => $g, 'mam_g' => round($mam, 3), 'na_g' => $na];
        if ($cel) { $w['surowy'] = ['nazwa' => $sur[$sid]['nazwa'] ?? $sid]; if ($mam <= 0) $brakOdessanego = true; }
        else $inne = min($inne, $mam / $g);
        $skl[] = $w;
        $min = min($min, $mam / $g); $max = max($max, $mam / $g); $suma += $g;
    }
    $k = alch_tabela('katalizatory')[$p['katalizator']] ?? [];
    foreach (($k['wzor'] ?? []) as $e => $ile) $M[$e] = alch_M($e);
    $gr = $suma > 0 ? $poj / $suma : 0;
    // Podpowiedź z prawdziwych atomów każdej sztuki: najlepsze na początek i tyle, ile pozwolą pozostałe składniki i pojemność kociołka.
    $m2 = 0.0; foreach (($k['wzor'] ?? []) as $e => $ile) $m2 += $ile * alch_M($e);
    foreach ($skl as $i => &$w) {
        if (!isset($w['surowy'])) continue;
        $x = $p['skladniki'][$i]; $cel = $x['cel']; $m1 = alch_masa_celu($cel); $g0 = (float)$x['g'];
        $juz = $w['mam_g'] / $w['g'];
        $limit = max(0.0, min($inne, $gr) - $juz); $wolne = max(0.0, $poj - $juz * $suma);
        $szt = []; $sumP = 0.0; $sumM = 0.0; $surowa = 0.0; $rada = 0; $ciag = true;
        foreach (alch_najlepsze($zapasy[$w['surowiec']] ?? [], $cel) as $r) {
            $surowa += alch_masa($r['sklad']);
            $kk = alch_k($r['sklad'], $cel); $porcje = $kk * $m1 / $g0;
            $at = $r['sklad']; foreach ($cel as $e => $c) $at[$e] = $kk * $c;
            $masa = alch_masa($at);
            foreach ($skl as $j => $v) {
                if ($j === $i) continue;
                $masa += $porcje * $v['g'];
                foreach ($v['na_g'] as $e => $nag) $at[$e] = ($at[$e] ?? 0) + $porcje * $v['g'] * $nag;
            }
            $n = INF; foreach (($k['wzor'] ?? []) as $e => $ile) $n = min($n, (float)($at[$e] ?? 0) / $ile);
            if ($ciag && $sumP + $porcje <= $limit && $sumM + $masa <= $wolne) { $sumP += $porcje; $sumM += $masa; $rada++; } else $ciag = false;
            $szt[] = ['p' => round($porcje, 4), 'm' => round($masa, 2), 'w' => round($n === INF ? 0 : $n * $m2, 2)];
        }
        $w['surowy'] += ['szt' => count($szt), 'masa_g' => round($surowa, 1), 'rada' => $rada, 'sztuki' => $szt, 'limit' => round($limit, 4), 'wolne_g' => round($wolne, 1)];
    }
    unset($w);
    if ($brakOdessanego) { $min = 0.0; $max = 0.0; }
    return ['id' => $id, 'karta' => $p['karta'] ?? $id, 'wariant' => $p['wariant'] ?? '', 'nazwa' => $p['nazwa'], 'opis' => $p['opis'] ?? '', 'skladniki' => $skl, 'suma_g' => $suma, 'M' => $M,
        'czysty' => round(min($min === INF ? 0 : $min, $gr), 4), 'gorny' => round(min($max, $gr), 4),
        'katalizator' => $k['nazwa'] ?? '', 'dostepny' => isset($kat[$p['katalizator']]), 'produkt' => $k['produkt'] ?? '', 'wzor' => $k['wzor'] ?? [], 'wzor_tekst' => $k['wzor_tekst'] ?? ''];
}
// Zaznaczenia gasną, gdy Skład zmienił się tak, że rzeczy nie ma albo jest jej za mało.
function alch_zaznaczenie_sprawdz() {
    $z = alch_czytaj('zaznaczenie'); if (!$z) return;
    $w = alch_czytaj('waga');
    foreach ($z['pozycje'] as $p) {
        $r = alch_rzecz($p['id']);
        if (!$r || $r['gdzie'] !== 'sklad' || ($w && $w['id'] === $p['id']) || alch_masa($r['sklad']) < $p['g'] - 0.01) { alch_zapisz('zaznaczenie', null); return; }
    }
}

// --- upływ czasu ---------------------------------------------------------------------------------
function alch_start() {
    if (is_file(ALCH_STAN . '/licznik.json')) return;
    alch_zapisz('licznik', ['n' => 0]);
    foreach (alch_cfg()['start'] ?? [] as $s) for ($i = 0; $i < (int)$s['ile']; $i++) alch_nowy_surowiec($s['surowiec']);
}
// Dostawy jednorazowe z alchemia.json: pozwalają dołożyć surowce do Składu, który już działa.
function alch_dosypki() {
    $lista = alch_cfg()['dosypki'] ?? []; if (!$lista) return;
    $zrobione = alch_czytaj('dosypki') ?: []; $zmiana = false;
    foreach ($lista as $d) {
        if (in_array($d['id'], $zrobione, true)) continue;
        foreach (alch_rzeczy() as $r) {
            if ($r['typ'] !== 'surowiec' || !in_array($r['surowiec'], $d['usun'] ?? [], true) || !alch_nienaruszony($r)) continue;
            alch_usun_rzecz($r['id']);
            foreach (['stol', 'waga'] as $m) { $x = alch_czytaj($m); if ($x && $x['id'] === $r['id']) alch_zapisz($m, null); }
        }
        foreach ($d['surowce'] as $s) for ($i = 0; $i < (int)$s['ile']; $i++) alch_nowy_surowiec($s['surowiec'], $s['masa'] ?? null, $s['nazwa'] ?? null);
        $zrobione[] = $d['id']; $zmiana = true;
    }
    if ($zmiana) alch_zapisz('dosypki', $zrobione);
}
// Zakupy Henryka, którym minęła zwłoka, stają się rzeczami w Składzie.
function alch_dostawy() {
    $c = rynek_cfg(); if (!$c) return;
    $ost = (int)((alch_czytaj('dostawy') ?: [])['id'] ?? 0); $zw = (int)$c['zakup']['zwloka_s']; $nowy = $ost;
    $st = baza()->prepare("SELECT id,ts,cel,dane FROM zdarzenia WHERE typ='zakup' AND id>? AND ts<=? ORDER BY id");
    $st->execute([$ost, time() - $zw]);
    foreach ($st->fetchAll() as $z) {
        $d = json_decode((string)$z['dane'], true) ?: [];
        $nowy = (int)$z['id'];
        if (($d['dom'] ?? 'henryk') !== 'henryk') continue;
        $szt = max(1, (int)($c['towary'][(string)$z['cel']]['sztuk'] ?? 1)); // porcja może mieć kilka sztuk (worek buraków)
        for ($i = 0; $i < (int)($d['n'] ?? 0) * $szt; $i++) alch_nowy_surowiec((string)$z['cel']);
    }
    if ($nowy !== $ost) alch_zapisz('dostawy', ['id' => $nowy]);
}
// Dostawy z innych pokoi (np. zbiory z ogrodu): zdarzenia 'do_skladu' z celem w tym pokoju, każde ze swoim czasem 'gotowe'
// (ogród suszy zioła różnie długo), więc 'kursor' to ostatnie zdarzenie, do którego wszystko jest odebrane, a 'ids' to odebrane później.
function alch_dostawy_obce() {
    $st = alch_czytaj('dostawy_obce') ?: ['kursor' => 0, 'ids' => []];
    $kur = (int)$st['kursor']; $ids = array_map('intval', $st['ids']); $zm = false; $ciagle = true;
    $q = baza()->prepare("SELECT id,dane FROM zdarzenia WHERE typ='do_skladu' AND cel=? AND id>? ORDER BY id");
    $q->execute([basename(KAT_POKOJU), $kur]);
    foreach ($q->fetchAll() as $z) {
        $id = (int)$z['id'];
        if (!in_array($id, $ids, true)) {
            $d = json_decode((string)$z['dane'], true) ?: [];
            if ((int)($d['gotowe'] ?? 0) > time()) { $ciagle = false; continue; }
            foreach (($d['porcje'] ?? []) as $g) {
                if (count(alch_rzeczy()) >= ALCH_MAX_RZECZY) break;
                alch_nowy_surowiec((string)($d['surowiec'] ?? ''), (float)$g, isset($d['nazwa']) ? (string)$d['nazwa'] : null);
            }
            $ids[] = $id; $zm = true;
        }
        if ($ciagle) { $kur = $id; $ids = array_values(array_diff($ids, [$id])); $zm = true; }
    }
    if ($zm) alch_zapisz('dostawy_obce', ['kursor' => $kur, 'ids' => $ids]);
}
function alch_dokoncz() {
    alch_start();
    alch_dosypki();
    alch_rozloz_stosy();
    alch_dostawy();
    alch_dostawy_obce();
    alch_zaznaczenie_sprawdz();
    $teraz = microtime(true);

    $st = alch_czytaj('stol');
    $r = $st ? alch_rzecz($st['id']) : null;
    if ($r && !empty($r['czar']) && $r['czar']['koniec'] <= $teraz) {
        $cz = alch_tabela('czary')[$r['czar']['id']] ?? null;
        $sid = array_key_first($r['pochodzenie']);
        foreach (($cz['usuwa'] ?? []) as $e) {
            $mam = (float)($r['sklad'][$e] ?? 0);
            $x = $mam * (float)$r['czar']['ulamek'];
            if ($x <= ALCH_MALO) continue;
            $zostaje = round($mam - $x, 6);
            if ($zostaje <= ALCH_MALO) unset($r['sklad'][$e]); else $r['sklad'][$e] = $zostaje;
            alch_dosyp_kopczyk($e, $x, $sid);
        }
        // Wodór i tlen trzymają się siebie: gdy jednego wyssie się do zera, drugi łączy się w pary (O₂ albo H₂) i ulatuje.
        // Ulotniony gaz przepada w powietrzu, nie trafia na kopczyk.
        $r['ulot'] = null;
        foreach (($cz['usuwa'] ?? []) as $e) {
            $p = ['H' => 'O', 'O' => 'H'][$e] ?? null;
            if ($p === null || !empty($r['sklad'][$e]) || empty($r['sklad'][$p])) continue;
            alch_ulatnij($r, $p, $r['czar']['id'], $teraz);
        }
        $r['czar'] = null;
        if ($r['typ'] === 'produkt') {
            // Wyrób (np. brudna sól, czyli solanka) pamięta pełny skład z brudem: po czarze czystość liczy się od nowa z tego, co zostało.
            $m0 = array_sum($r['pochodzenie'] ?? []); $m1 = alch_masa($r['sklad']);
            if ($m0 > 0) foreach ($r['pochodzenie'] as $q => $m) $r['pochodzenie'][$q] = $m * $m1 / $m0;
            $kat = alch_tabela('katalizatory')[$r['katalizator']] ?? null;
            $wyn = $kat ? alch_uwarz($r['sklad'], $kat) : null;
            $r['czystosc'] = $wyn ? round($wyn['czystosc'], 4) : 0.0;
            $r['stopien'] = alch_stopien($r['czystosc'], $kat['rodzaj'] ?? 'm');
            alch_zapisz_rzecz($r);
        } else {
        $r['odessany'] = true; unset($r['czesci']);
        $r['pochodzenie'] = [$sid => alch_masa($r['sklad'])];
        $cel = alch_cel_dla($r);
        if ($cel && !alch_nadmiar($r['sklad'], $cel['cel'])) $r['nazwa'] = alch_nazwa_gotowego($cel, $r);
        alch_zapisz_rzecz($r);
        }
    }

    // Sam gaz na stole: gdy w próbce został jeden gazowy pierwiastek, łączy się w pary i ulatuje,
    // ale dopiero gdy poprzedni ulatujący gaz został złapany albo przepadł (wtedy kolbę podstawia się drugi raz).
    $st = alch_czytaj('stol');
    $r = $st ? alch_rzecz($st['id']) : null;
    if ($r && empty($r['czar']) && count($r['sklad']) === 1) {
        $e = (string)array_key_first($r['sklad']); $u = $r['ulot'] ?? null;
        if (in_array($e, ALCH_GAZY, true) && (!$u || !empty($u['zlapany']) || $teraz - (float)$u['ts'] >= ALCH_ULOT_S)) {
            alch_ulatnij($r, $e, null, $teraz);
            if ($r['typ'] === 'produkt') { $r['czystosc'] = 0.0; $r['stopien'] = alch_stopien(0.0, alch_tabela('katalizatory')[$r['katalizator']]['rodzaj'] ?? 'm'); }
            alch_zapisz_rzecz($r);
        }
    }

    $w = alch_czytaj('waga');
    if ($w && empty($w['gotowe']) && $w['koniec'] <= $teraz) {
        $rz = alch_rzecz($w['id']);
        if ($rz) { $rz['zbadany'] = true; alch_zapisz_rzecz($rz); $w['gotowe'] = true; alch_zapisz('waga', $w); }
        else alch_zapisz('waga', null);
    }

    $k = alch_kociolek();
    if (!empty($k['koniec']) && $k['koniec'] <= $teraz) {
        $kat = alch_tabela('katalizatory')[$k['katalizator']] ?? null;
        $wyn = $kat ? alch_uwarz($k['sklad'], $kat) : null;
        if (!$wyn) $tekst = 'Nic się nie związało. W kociołku brakowało czegoś, co jest we wzorze.';
        else {
            $ro = $kat['rodzaj']; $prz = alch_przymiotnik($k['pochodzenie'], $ro);
            $nazwa = $kat['produkt'] . ($prz !== '' ? ' ' . $prz : '');
            $czyst = round($wyn['czystosc'], 4);
            alch_zapisz_rzecz(['id' => alch_nowe_id(), 'typ' => 'produkt', 'nazwa' => $nazwa, 'gdzie' => 'sklad', 'sklad' => $k['sklad'], 'pochodzenie' => $k['pochodzenie'],
                'katalizator' => $k['katalizator'], 'czystosc' => $czyst, 'stopien' => alch_stopien($czyst, $ro), 'zbadany' => true, 'czar' => null]);
            $tekst = 'Powstało: ' . $nazwa . ', ' . alch_ile($wyn['masa'], $kat) . ', czystość ' . alch_f($czyst * 100, 1) . '%. Leży w Składzie.';
        }
        alch_zapisz('kociolek', ['sklad' => [], 'pochodzenie' => [], 'zawartosc' => [], 'koniec' => null, 'katalizator' => null, 'wynik' => $tekst, 'wynik_id' => (string)$teraz]);
    }
}

// --- działania gracza ----------------------------------------------------------------------------
function alch_dodaj_do_kociolka($sklad, $pochodzenie, $nazwa) {
    $k = alch_kociolek();
    $masa = alch_masa($sklad);
    foreach ($sklad as $e => $m) $k['sklad'][$e] = round((float)($k['sklad'][$e] ?? 0) + $m, 6);
    $suma = array_sum($pochodzenie) ?: 1.0;
    foreach ($pochodzenie as $sid => $m) $k['pochodzenie'][$sid] = (float)($k['pochodzenie'][$sid] ?? 0) + $masa * $m / $suma;
    $k['zawartosc'][] = ['nazwa' => $nazwa, 'masa_g' => round($masa, 2)];
    $k['wynik'] = '';
    alch_zapisz('kociolek', $k);
    return $masa;
}
function alch_do_kociolka($r, $ile_mol) {
    if (!empty(alch_kociolek()['koniec'])) alch_blad('W kociołku właśnie się gotuje. Trzeba poczekać.');
    $sklad = $r['sklad']; $zostaje = null;
    if ($r['typ'] === 'kopczyk' && $ile_mol !== null) {
        $e = $r['pierwiastek']; $mam = (float)$r['sklad'][$e];
        if ($ile_mol <= 0) alch_blad('Trzeba podać, ile wsypać.');
        $x = min($mam, $ile_mol);
        if (($mam - $x) * alch_M($e) < 0.01) $x = $mam;   // bez pyłu: resztka poniżej 0,01 g idzie razem z porcją
        $sklad = [$e => round($x, 6)];
        if ($mam - $x > ALCH_MALO) $zostaje = $mam - $x;
    }
    alch_miejsce(alch_masa($sklad));
    if ($zostaje !== null) { $r['sklad'][$r['pierwiastek']] = round($zostaje, 6); $r['pochodzenie'][$r['zrodlo']] = alch_masa($r['sklad']); alch_zapisz_rzecz($r); }
    else alch_usun_rzecz($r['id']);
    return alch_f(alch_dodaj_do_kociolka($sklad, $r['pochodzenie'], $r['nazwa']), 2) . ' g ląduje w kociołku.';
}
// Część surowca o zadanej masie: zachowuje proporcje, a reszta zostaje w Składzie.
function alch_czesc_do_kociolka($r, $g) {
    $m = alch_masa($r['sklad']);
    if ($g >= $m - 0.01) { alch_usun_rzecz($r['id']); alch_dodaj_do_kociolka($r['sklad'], $r['pochodzenie'], $r['nazwa']); return; }
    $f = $g / $m; $czesc = [];
    foreach ($r['sklad'] as $e => $mol) { $czesc[$e] = $mol * $f; $r['sklad'][$e] = round($mol * (1 - $f), 6); }
    $sid = array_key_first($r['pochodzenie']);
    $r['pochodzenie'] = [$sid => alch_masa($r['sklad'])];
    alch_zapisz_rzecz($r);
    alch_dodaj_do_kociolka($czesc, [$sid => $g], $r['nazwa']);
}

function alch_wykonaj($akcja, $in) {
    $s = function ($k) use ($in) { return isset($in[$k]) && is_scalar($in[$k]) ? (string)$in[$k] : ''; };
    $cfg = alch_cfg(); $teraz = microtime(true);
    $r = $s('id') !== '' ? alch_rzecz($s('id')) : null;
    $w = alch_czytaj('waga');
    $naWadze = $r && $w && $w['id'] === $r['id'];
    switch ($akcja) {
        case 'na_stol':
            if (!$r || !in_array($r['typ'], ['surowiec', 'produkt'], true) || $r['gdzie'] !== 'sklad') alch_blad('Tego nie da się położyć na stole.');
            if ($naWadze) alch_blad('To leży na wadze. Najpierw zdejmij.');
            if (alch_czytaj('stol')) alch_blad('Na stole coś już leży.');
            $r['gdzie'] = 'stol'; alch_zapisz_rzecz($r); alch_zapisz('stol', ['id' => $r['id']]);
            return $r['nazwa'] . ' leży na stole.';
        case 'ze_stolu':
            $st = alch_czytaj('stol'); $x = $st ? alch_rzecz($st['id']) : null;
            if (!$x) alch_blad('Na stole nic nie ma.');
            if (!empty($x['czar'])) alch_blad('Czar jeszcze działa.');
            if (alch_masa($x['sklad']) <= ALCH_MALO) { alch_usun_rzecz($x['id']); alch_zapisz('stol', null); return 'Na stole nic nie zostało.'; }   // wszystko wyssane albo uleciało
            $x['gdzie'] = 'sklad'; unset($x['ulot']); alch_zapisz_rzecz($x); alch_zapisz('stol', null);
            alch_rozloz_stosy();
            return $x['nazwa'] . ' wraca do Składu.';
        case 'czar':
            $st = alch_czytaj('stol'); $x = $st ? alch_rzecz($st['id']) : null;
            if (!$x) alch_blad('Na stole nic nie ma.');
            if (!empty($x['czar'])) alch_blad('Czar jeszcze działa.');
            $cz = alch_tabela('czary')[$s('czar')] ?? null;
            if (!$cz) alch_blad('Nie ma takiego czaru.');
            // Łapanie ulatującego gazu: czar na ten pierwiastek rzucony, póki gaz jeszcze ulatuje, podstawia swój przyrząd i gaz trafia na kopczyk.
            $u = $x['ulot'] ?? null;
            if ($u && empty($u['zlapany']) && $teraz - (float)$u['ts'] < ALCH_ULOT_S + 1.5 && in_array($u['e'], $cz['usuwa'], true)) {
                alch_dosyp_kopczyk($u['e'], (float)$u['mol'], (string)array_key_first($x['pochodzenie'] ?? []));
                $x['ulot']['zlapany'] = true; alch_zapisz_rzecz($x);
                $prz = alch_tabela('przyrzady')[$cz['przyrzad']]['nazwa'] ?? 'Przyrząd';
                return $prz . ' w ostatniej chwili chwyta ulatujący ' . alch_nazwa_el($u['e']) . ': ' . alch_f((float)$u['g'], 2) . ' g trafia na kopczyk.';
            }                // czar działa, nawet jeśli postać go nie zna (nie ma go tylko w jej notatkach)
            $jest = false; foreach ($cz['usuwa'] as $e) if (!empty($x['sklad'][$e])) $jest = true;
            if (!$jest) alch_blad('W tej próbce nie ma czego wyssać.');
            $u = (float)str_replace(',', '.', $s('ulamek'));
            if (!($u > 0)) alch_blad('Suwak stoi na zerze.');
            $x['czar'] = ['id' => $s('czar'), 'ulamek' => min(1.0, $u), 'koniec' => $teraz + (float)$cfg['czas_czaru_s']];
            alch_zapisz_rzecz($x);
            return $cz['nazwa'] . '. Próbka zaczyna drżeć.';
        case 'poloz_stos':
            $pid = alch_przepis_id($s('przepis')); $p = alch_przepis($pid); $x = null;
            foreach (($p['skladniki'] ?? []) as $q) if (isset($q['cel'])) $x = $q;
            if (!$x) alch_blad('Ten czar niczego nie układa na stole.');
            if (alch_czytaj('stol')) alch_blad('Na stole coś już leży.');
            $lista = alch_najlepsze(alch_zapasy()[$x['surowiec']] ?? [], $x['cel']);
            $ile = min((int)$s('ile'), count($lista));
            if ($ile < 1) alch_blad('Nie ma czego położyć.');
            return alch_stos(array_slice($lista, 0, $ile), $pid)['nazwa'] . ' leży na stole.';
        case 'do_kociolka':
            if (!$r || !in_array($r['typ'], ['surowiec', 'kopczyk'], true)) alch_blad('Tego nie wsypie się do kociołka.');
            if ($naWadze) alch_blad('To leży na wadze. Najpierw zdejmij.');
            if (!empty($r['czar'])) alch_blad('Czar jeszcze działa.');
            $naStole = $r['gdzie'] === 'stol';
            $ile = null;
            if ($r['typ'] === 'kopczyk' && $s('gramy') !== '') $ile = (float)str_replace(',', '.', $s('gramy')) / alch_M($r['pierwiastek']);
            elseif ($r['typ'] === 'kopczyk' && $s('mol') !== '') $ile = (float)str_replace(',', '.', $s('mol'));
            $tekst = alch_do_kociolka($r, $ile);
            if ($naStole) alch_zapisz('stol', null);
            return $tekst;
        case 'zbadaj':
            if (!$r || $r['typ'] !== 'surowiec' || $r['gdzie'] !== 'sklad') alch_blad('Tego nie da się teraz zważyć.');
            if ($w) alch_blad('Na wadze coś już leży.');
            alch_zapisz('waga', ['id' => $r['id'], 'koniec' => $teraz + (float)$cfg['czas_wagi_s'], 'gotowe' => false]);
            return $cfg['czar_wagi'] . '. Szalki zaczynają drżeć.';
        case 'zdejmij':
            if (!$w) alch_blad('Szalka jest pusta.');
            if (empty($w['gotowe'])) alch_blad('Waga jeszcze liczy.');
            alch_zapisz('waga', null);
            return 'Rzecz wraca do Składu.';
        case 'zagotuj':
            $k = alch_kociolek();
            if (!empty($k['koniec'])) alch_blad('W kociołku już się gotuje.');
            if (!$k['sklad']) alch_blad('Kociołek jest pusty.');
            if (!isset(alch_katalizatory()[$s('katalizator')])) alch_blad('Bez katalizatora to tylko mieszanina.');
            $k['katalizator'] = $s('katalizator'); $k['koniec'] = $teraz + (float)$cfg['czas_gotowania_s']; $k['wynik'] = '';
            alch_zapisz('kociolek', $k);
            return 'Kociołek zaczyna bulgotać.';
        case 'rzuc_przepis':
            $pid = alch_przepis_id($s('przepis')); $p = alch_przepis($pid);
            if (!$p) alch_blad('Tego czaru nikt tu nie zna.');
            if (alch_czytaj('zaznaczenie')) alch_blad('Inny czar czeka już na wsypanie. Wsyp albo zgaś zaznaczenia.');
            $k = alch_kociolek();
            if (!empty($k['koniec']) || $k['sklad']) alch_blad('Kociołek musi być pusty.');
            $kat = alch_katalizatory();
            if (!isset($kat[$p['katalizator']])) alch_blad('Brakuje katalizatora do tego czaru.');
            $zap = alch_zapasy();
            $v = alch_przepis_widok($pid, $p, $zap, $kat, alch_pojemnosc());
            $mn = min((float)str_replace(',', '.', $s('mnoznik')), $v['gorny']);
            if (!($mn > 0)) alch_blad('W Składzie nie ma z czego tego zrobić.');
            $poz = [];
            foreach ($p['skladniki'] as $i => $x) {
                $brak = $mn * (float)$v['skladniki'][$i]['g'];
                foreach (($zap[alch_klucz_zapasu($x, $pid)] ?? []) as $r2) {
                    if ($brak <= 0.0005) break;
                    $g = min($brak, alch_masa($r2['sklad']));
                    $poz[] = ['id' => $r2['id'], 'nazwa' => $r2['nazwa'], 'g' => round($g, 3)];
                    $brak -= $g;
                }
            }
            if (!$poz) alch_blad('W Składzie nie ma z czego tego zrobić.');
            alch_zapisz('zaznaczenie', ['przepis' => $pid, 'mnoznik' => $mn, 'pozycje' => $poz, 'koniec' => $teraz + (float)$cfg['czas_czaru_s']]);
            return $p['nazwa'] . '. Czar przelicza Skład.';
        case 'wsyp_przepis':
            $z = alch_czytaj('zaznaczenie');
            if (!$z) alch_blad('Nic nie jest zaznaczone.');
            if ($z['koniec'] > $teraz) alch_blad('Czar jeszcze liczy.');
            $p = alch_przepis($z['przepis']);
            $k = alch_kociolek();
            if (!$p || !empty($k['koniec']) || $k['sklad']) alch_blad('Kociołek musi być pusty.');
            if (!isset(alch_katalizatory()[$p['katalizator']])) alch_blad('Brakuje katalizatora do tego czaru.');
            foreach ($z['pozycje'] as $x) { $r2 = alch_rzecz($x['id']); if ($r2) alch_czesc_do_kociolka($r2, (float)$x['g']); }
            alch_zapisz('zaznaczenie', null);
            $k = alch_kociolek();
            $k['katalizator'] = $p['katalizator']; $k['koniec'] = $teraz + (float)$cfg['czas_gotowania_s']; $k['wynik'] = '';
            alch_zapisz('kociolek', $k);
            return 'Wsypane. Kociołek zaczyna bulgotać.';
        case 'zgas_przepis':
            alch_zapisz('zaznaczenie', null);
            return 'Zaznaczenia zgasły.';
        case 'wylej':
            $k = alch_kociolek();
            if (!empty($k['koniec'])) alch_blad('W kociołku właśnie się gotuje.');
            if (!$k['sklad']) alch_blad('Kociołek jest pusty.');
            alch_zapisz('kociolek', null);
            return 'Wylewasz wszystko. Mieszanina przepadła.';
        case 'odlej':
            if (!$r || $r['typ'] !== 'produkt' || $r['gdzie'] !== 'sklad') alch_blad('Tego nie da się podzielić.');
            if (count(alch_rzeczy()) >= ALCH_MAX_RZECZY) alch_blad('W Składzie nie ma już miejsca.');
            $g = (float)str_replace(',', '.', $s('gramy')); $m = alch_masa($r['sklad']);
            if ($g < 0.01) alch_blad('Trzeba podać, ile oddzielić.');
            if ($g >= $m - 0.01) alch_blad('Porcja musi być mniejsza niż całość.');
            $f = $g / $m; $czesc = $r; $czesc['id'] = alch_nowe_id();
            foreach ($r['sklad'] as $e => $mol) { $czesc['sklad'][$e] = round($mol * $f, 6); $r['sklad'][$e] = round($mol * (1 - $f), 6); }
            foreach ($r['pochodzenie'] as $k => $mp) { $czesc['pochodzenie'][$k] = $mp * $f; $r['pochodzenie'][$k] = $mp * (1 - $f); }
            alch_zapisz_rzecz($r); alch_zapisz_rzecz($czesc);
            return 'Oddzielona porcja: ' . alch_ile($g, alch_tabela('katalizatory')[$r['katalizator']] ?? []) . '.';
        case 'sprzedaj':
            if (!$r || $r['typ'] !== 'produkt' || $r['gdzie'] !== 'sklad') alch_blad('Tego zielarz nie kupi.');
            $cena = alch_cena($r, rynek_stan());
            dodaj_zdarzenie('sprzedano', $r['nazwa'], (string)($in['gracz'] ?? ''), ['cena' => $cena]);
            alch_usun_rzecz($r['id']);
            return 'Zielarz kupił: ' . $r['nazwa'] . '. Zapłacił ' . rynek_kwota_txt($cena) . '.';
        case 'polacz':                                   // dwa takie same wyroby (ten sam produkt z tego samego czaru); czystość: średnia ważona masą
            $x = $s('z') !== '' ? alch_rzecz($s('z')) : null;
            if (!$r || !$x || $r['id'] === $x['id'] || $r['typ'] !== 'produkt' || $x['typ'] !== 'produkt' || $r['gdzie'] !== 'sklad' || $x['gdzie'] !== 'sklad') alch_blad('Tych rzeczy nie da się połączyć.');
            if ($r['nazwa'] !== $x['nazwa'] || ($r['katalizator'] ?? '') !== ($x['katalizator'] ?? ''))
                alch_blad('Połączyć można tylko ten sam wyrób.');
            $ma = alch_masa($r['sklad']); $mx = alch_masa($x['sklad']);
            foreach ($x['sklad'] as $e => $mol) $r['sklad'][$e] = round((float)($r['sklad'][$e] ?? 0) + $mol, 6);
            foreach (($x['pochodzenie'] ?? []) as $q => $m) $r['pochodzenie'][$q] = (float)($r['pochodzenie'][$q] ?? 0) + $m;
            $r['czystosc'] = round(($r['czystosc'] * $ma + $x['czystosc'] * $mx) / max(1e-9, $ma + $mx), 4);
            $r['stopien'] = alch_stopien($r['czystosc'], alch_tabela('katalizatory')[$r['katalizator']]['rodzaj'] ?? '');
            alch_zapisz_rzecz($r); alch_usun_rzecz($x['id']);
            return 'Połączone: ' . $r['nazwa'] . ', razem ' . alch_f($ma + $mx, 2) . ' g.';
        case 'wyrzuc':
            if (!$r || $r['gdzie'] !== 'sklad') alch_blad('Tego nie da się wyrzucić.');
            if ($naWadze) alch_blad('To leży na wadze. Najpierw zdejmij.');
            alch_usun_rzecz($r['id']);
            return 'Wyrzucone.';
    }
    alch_blad('Nieznana akcja.');
}

// --- widok dla przeglądarki ----------------------------------------------------------------------
function alch_tabela_skladu($sklad) {
    $w = [];
    foreach ($sklad as $e => $mol) $w[$e] = ['nazwa' => alch_nazwa_el($e), 'mol' => round($mol, 4), 'g' => round($mol * alch_M($e), 3), 'M' => alch_M($e)];
    uasort($w, function ($a, $b) { return $b['g'] <=> $a['g']; });
    return $w;
}
function alch_widok_rzeczy($r, $o) {
    $v = ['id' => $r['id'], 'typ' => $r['typ'], 'nazwa' => $r['nazwa'], 'gdzie' => $r['gdzie'], 'masa_g' => round(alch_masa($r['sklad']), 2), 'zbadany' => !empty($r['zbadany'])];
    if (!empty($r['zbadany'])) $v['sklad'] = alch_tabela_skladu($r['sklad']);
    if ($r['typ'] === 'kopczyk') $v['mol'] = round($r['sklad'][$r['pierwiastek']], 4);
    if ($r['typ'] === 'surowiec') $v['kat'] = alch_kategoria($r['surowiec']);
    if ($r['typ'] === 'produkt') { $v['czystosc'] = $r['czystosc']; $v['stopien'] = $r['stopien']; $v['cena_t'] = rynek_kwota_txt(alch_cena($r, $o)); $v['ciecz'] = !empty(alch_tabela('katalizatory')[$r['katalizator']]['ciecz']); $v['kat'] = (string)($r['katalizator'] ?? ''); }
    return $v;
}
function alch_widok() {
    $o = rynek_stan(); $tczary = alch_tabela('czary'); $tprz = alch_tabela('przyrzady');
    $czary = [];
    foreach ($tczary as $id => $c) {                       // wszystkie czary; nieznane nie trafiają na listy, ale da się je rzucić głosem
        $wyt = [];
        foreach ($tczary as $d) if (!empty($d['znany']) && $d['przyrzad'] === $c['przyrzad']) foreach ($d['usuwa'] as $e) $wyt[] = alch_nazwa_el($e);
        $p = $tprz[$c['przyrzad']] ?? [];
        $czary[] = ['id' => $id, 'nazwa' => $c['nazwa'], 'pl' => $c['pl'], 'usuwa' => $c['usuwa'], 'znany' => !empty($c['znany']),
            'przyrzad' => ['nazwa' => $p['nazwa'] ?? '', 'opis' => $p['opis'] ?? '', 'ikona' => $p['ikona'] ?? '', 'wytraca' => array_values(array_unique($wyt))]];
    }
    $sklad = [];
    foreach (alch_rzeczy() as $r) $sklad[] = alch_widok_rzeczy($r, $o);
    $stol = null; $st = alch_czytaj('stol');
    if ($st && ($r = alch_rzecz($st['id']))) {
        $stol = ['id' => $r['id'], 'nazwa' => $r['nazwa'], 'masa_g' => round(alch_masa($r['sklad']), 2), 'sklad' => alch_tabela_skladu($r['sklad']), 'czar' => null];
        if (!empty($r['ulot'])) $stol['ulot'] = $r['ulot'] + ['nazwa' => alch_nazwa_el($r['ulot']['e']), 'swiezy' => empty($r['ulot']['zlapany']) && microtime(true) - (float)$r['ulot']['ts'] < ALCH_ULOT_S];
        if ($r['typ'] === 'produkt') { $stol['wyrob'] = true; $stol['czystosc'] = $r['czystosc']; $stol['stopien'] = $r['stopien']; }
        if (!empty($r['czar'])) { $c = $tczary[$r['czar']['id']]; $stol['czar'] = ['nazwa' => $c['nazwa'], 'przyrzad' => $tprz[$c['przyrzad']] ?? []]; }
        $cel = alch_cel_dla($r);
        if ($cel) $stol['podpowiedz'] = ['przepis' => $cel['przepis'], 'cel_nazwa' => $cel['cel_nazwa'], 'mol' => (object)alch_nadmiar($r['sklad'], $cel['cel'])];
    }
    $waga = null; $w = alch_czytaj('waga');
    if ($w && ($r = alch_rzecz($w['id']))) {
        $waga = ['id' => $r['id'], 'nazwa' => $r['nazwa'], 'gotowe' => !empty($w['gotowe'])];
        if (!empty($w['gotowe'])) { $waga['masa_g'] = round(alch_masa($r['sklad']), 2); $waga['sklad'] = alch_tabela_skladu($r['sklad']); }
    }
    $k = alch_kociolek(); $kat = [];
    foreach (alch_katalizatory() as $id => $x) $kat[] = ['id' => $id, 'nazwa' => $x['nazwa'], 'produkt' => $x['produkt'], 'wzor' => $x['wzor_tekst']];
    $zap = alch_zapasy(); $katDost = alch_katalizatory(); $przepisy = [];
    foreach (alch_przepisy() as $id => $p) $przepisy[] = alch_przepis_widok($id, $p, $zap, $katDost, alch_pojemnosc());
    $z = alch_czytaj('zaznaczenie'); $zaznaczenie = null;
    if ($z) {
        $zaznaczenie = ['przepis' => alch_przepis_id($z['przepis']), 'mnoznik' => $z['mnoznik'], 'gotowe' => $z['koniec'] <= microtime(true), 'pozycje' => $z['pozycje']];
        foreach ($sklad as &$v) foreach ($z['pozycje'] as $x) if ($x['id'] === $v['id']) $v['zaznaczone_g'] = $x['g'];
        unset($v);
    }
    $kategorie = array_map(function ($k) { return ['id' => $k['id'], 'nazwa' => $k['nazwa']]; }, alch_cfg()['kategorie'] ?? []);
    $kategorie[] = ['id' => 'inne', 'nazwa' => 'Inne'];
    return ['czar_wagi' => alch_cfg()['czar_wagi'], 'kategorie' => $kategorie, 'pojemnosc_g' => alch_pojemnosc(), 'przepisy' => $przepisy, 'zaznaczenie' => $zaznaczenie, 'czary' => $czary, 'sklad' => $sklad, 'stol' => $stol, 'waga' => $waga, 'katalizatory' => $kat,
        'kociolek' => ['zawartosc' => $k['zawartosc'], 'sklad' => alch_tabela_skladu($k['sklad']), 'masa_g' => round(alch_masa($k['sklad']), 2), 'gotuje' => !empty($k['koniec']), 'wynik' => (string)$k['wynik'], 'wynik_id' => (string)($k['wynik_id'] ?? '')]];
}

<?php
// Ogród: grządki, rośliny, skrzynka z nasionami i zakupy u dostawców.
// Stan ogrodu leży w _logika/stan/ (pliki JSON pod blokadą), a zakupy i zbiory w dzienniku zdarzeń:
//   wydatek  {cel: "ogrod", dane: {koszt, opis, dostawca, towary, gotowe}}  : płaci kasa z księgi (ogrod.json: "kasa"), a paczka trafia do skrzynki
//   do_skladu {cel: pokój, dane: {surowiec, porcje, nazwa, gotowe}}        : zbiór, który po wyschnięciu trafia do spiżarni (Składu) tego pokoju
// Dni liczą się leniwie: pierwsze zapytanie po północy przeżywa za ogród wszystkie dni, które minęły, z pogodą z _pogoda.php.
require_once __DIR__ . '/_pogoda.php';
define('OGR_STAN', OGR_KAT . '/_logika/stan');
define('OGR_TAB', OGR_KAT . '/_logika/tabele');
define('OGR_ID', basename(OGR_KAT));
const OGR_MAX_DNI = 400;          // po dłuższej przerwie ogród nadrabia tylko tyle ostatnich dni

function ogr_blad($t) { throw new RuntimeException($t); }
function ogr_tabela($n) {
    static $c = [];
    if (!isset($c[$n])) { $j = wczytaj_json(OGR_TAB . "/$n.json") ?: []; unset($j['_opis']); $c[$n] = $j; }
    return $c[$n];
}
function ogr_cfg() { return ogr_tabela('ogrod'); }
function ogr_rosliny() { return ogr_tabela('rosliny'); }
function ogr_pojemnosc() { return (float)(ogr_cfg()['pojemnosc_mm'] ?? 50); }
function ogr_f($x, $d = 1) { return str_replace('.', ',', (string)round($x, $d)); }
// Polska liczba mnoga: [jeden, dwa, pięć]
function ogr_forma($n, $f) {
    if ($n == 1) return $f[0];
    return ($n % 10 >= 2 && $n % 10 <= 4 && !($n % 100 >= 12 && $n % 100 <= 14)) ? $f[1] : $f[2];
}
function ogr_masa_txt($g) { return $g >= 1000 ? ogr_f($g / 1000, 1) . ' kg' : (int)round($g) . ' g'; }
function ogr_za_ile($s) {
    $s = max(0, (int)$s);
    if ($s < 90) return 'za chwilę';
    if ($s < 5400) return 'za ' . (int)round($s / 60) . ' min';
    if ($s < 129600) { $h = (int)round($s / 3600); return 'za ' . $h . ' ' . ogr_forma($h, ['godzinę', 'godziny', 'godzin']); }
    $d = (int)round($s / 86400); return 'za ' . $d . ' ' . ogr_forma($d, ['dzień', 'dni', 'dni']);
}

// --- pliki stanu ---------------------------------------------------------------------------------
function ogr_czytaj($n) { $t = @file_get_contents(OGR_STAN . "/$n.json"); return $t === false ? null : json_decode($t, true); }
function ogr_zapisz($n, $d) {
    $p = OGR_STAN . "/$n.json"; $t = $p . '.tmp' . getmypid();
    file_put_contents($t, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($t, $p);
}
function ogr_pod_blokada($f) {
    if (!is_dir(OGR_STAN)) mkdir(OGR_STAN, 0775, true);
    $h = fopen(OGR_STAN . '/.blokada', 'c'); flock($h, LOCK_EX);
    try { return $f(); } finally { flock($h, LOCK_UN); fclose($h); }
}
function ogr_skrzynka() { return ogr_czytaj('skrzynka') ?: ['paczki' => [], 'odebrane' => ['kursor' => 0, 'ids' => []]]; }

// --- działka -------------------------------------------------------------------------------------
// Miejsca na grządki: rzędy grządek na działce albo tylko w jej części (ogrod.json: "obszar_grzadek"),
// bez tych, które wchodzą na zabudowę (z pół metra zapasu).
function ogr_miejsca() {
    static $m = null;
    if ($m !== null) return $m;
    $c = ogr_cfg(); $g = $c['grzadka']; $mg = (float)$g['margines']; $zap = 0.5; $m = [];
    $D = $c['obszar_grzadek'] ?? ['x' => 0, 'y' => 0, 'dl' => $c['dzialka']['dl'], 'szer' => $c['dzialka']['szer']];
    for ($y = $D['y'] + $mg; $y + $g['szer'] <= $D['y'] + $D['szer'] - $mg + 1e-9; $y += $g['szer'] + $g['miedzy_rzedami']) {
        for ($x = $D['x'] + $mg; $x + $g['dl'] <= $D['x'] + $D['dl'] - $mg + 1e-9; $x += $g['dl'] + $g['odstep']) {
            $wolne = true;
            foreach ($c['zabudowa'] ?? [] as $z) {
                if ($x < $z['x'] + $z['dl'] + $zap && $x + $g['dl'] > $z['x'] - $zap && $y < $z['y'] + $z['szer'] + $zap && $y + $g['szer'] > $z['y'] - $zap) { $wolne = false; break; }
            }
            if ($wolne) $m[] = ['x' => round($x, 2), 'y' => round($y, 2), 'dl' => (float)$g['dl'], 'szer' => (float)$g['szer']];
        }
    }
    return $m;
}
function ogr_m2() { $g = ogr_cfg()['grzadka']; return (float)$g['dl'] * (float)$g['szer']; }
// Czy dziś da się kopać: '' albo powód.
function ogr_zmarznieta() {
    $z = pog_dzien(pog_dzis());
    if ($z['snieg_mm'] >= 10) return 'Grządki leżą pod śniegiem.';
    if ($z['gleba'] < -0.5) return 'Ziemia jest zmarznięta na kamień.';
    return '';
}

// --- start ---------------------------------------------------------------------------------------
// Suma ciepła od początku roku do wczoraj: tyle zebrałaby roślina wieloletnia, która rośnie tu od lat.
function ogr_suma_od_stycznia($s, $do) {
    $suma = 0.0;
    foreach (pog_seria(substr($do, 0, 4) . '-01-01', $do) as $z) if ($z['t'] > $s['baza']) $suma += min(15, $z['t'] - $s['baza']);
    return round($suma, 1);
}
function ogr_nowa_grzadka(&$o, $miejsce, $czar = null) {
    $id = 'g' . (++$o['n']);
    $o['grzadki'][$id] = ['id' => $id, 'miejsce' => (int)$miejsce, 'wilgoc' => round(ogr_pojemnosc() * 0.7, 1), 'chwasty' => 0.0,
        'roslina' => null, 'czar' => $czar, 'notatka' => ''];
    return $id;
}
function ogr_start() {
    if (is_file(OGR_STAN . '/ogrod.json')) return;
    $wczoraj = pog_przesun(pog_dzis(), -1); $rok = (int)substr($wczoraj, 0, 4);
    $o = ['dzien' => $wczoraj, 'n' => 0, 'grzadki' => [], 'oslona_do' => null];
    $R = ogr_rosliny(); $ile = count(ogr_miejsca());
    foreach (ogr_cfg()['start'] ?? [] as $s) {
        if ((int)$s['miejsce'] < 0 || (int)$s['miejsce'] >= $ile) continue;
        $id = ogr_nowa_grzadka($o, (int)$s['miejsce']);
        $g =& $o['grzadki'][$id];
        $g['chwasty'] = (float)($s['chwasty'] ?? 0); $g['notatka'] = (string)($s['notatka'] ?? '');
        $sp = $R[$s['gatunek'] ?? ''] ?? null;
        if ($sp) $g['roslina'] = ['gatunek' => $s['gatunek'], 'forma' => 'sadzonki', 'od' => $wczoraj, 'faza' => 'rosnie', 'kielki' => 0.0,
            'suma' => !empty($sp['wieloletnia']) ? ogr_suma_od_stycznia($sp, $wczoraj) : 0.0, 'zdrowie' => 0.9, 'jakosc' => 0.95,
            'wiek' => (int)($s['wiek'] ?? 0), 'sezon' => $rok, 'zebrane' => !empty($s['zebrane']) ? $rok : 0, 'martwa' => '', 'przyczyna' => ''];
        unset($g);
    }
    ogr_zapisz('ogrod', $o);
}

// --- jeden dzień w ogrodzie ----------------------------------------------------------------------
function ogr_przezyj_dzien(&$o, $z) {
    $cap = ogr_pojemnosc(); $R = ogr_rosliny(); $rok = (int)substr($z['data'], 0, 4);
    $czary = ogr_tabela('czary');
    $oslona = (!empty($o['oslona_do']) && $z['data'] <= $o['oslona_do']) ? (float)($czary['tegumen-pruinae']['stopnie'] ?? 5) : 0.0;
    foreach ($o['grzadki'] as &$g) {
        $r =& $g['roslina'];
        $s = $r ? ($R[$r['gatunek']] ?? null) : null;
        $zyje = $r && $s && $r['martwa'] === '';
        $rosnie = $zyje && $r['faza'] === 'rosnie' && $z['t'] > $s['baza'];
        // woda: deszcz i roztopy wsiąkają, a parowanie rośnie z ciepłem, słońcem i rosnącą rośliną
        // (sucha ziemia paruje coraz wolniej, więc woda nigdy nie znika do zera w jeden upalny tydzień)
        $et = max(0, 0.17 * $z['t']) * (1.15 - 0.5 * $z['chmury']) * ($rosnie ? 1.3 : 1.0) * min(1.0, 0.25 + 1.5 * $g['wilgoc'] / $cap);
        $g['wilgoc'] = round(max(0, min($cap, $g['wilgoc'] + $z['woda'] - $et)), 1);
        // chwasty rosną same, kiedy jest ciepło; gęsta bylina trochę je tłumi
        if ($z['t'] > 5) $g['chwasty'] = round(min(1, $g['chwasty'] + 0.009 * min(2, $z['t'] / 12) * ($zyje && !empty($s['wieloletnia']) ? 0.6 : 1)), 3);
        if (!$zyje) { unset($r); continue; }
        if ($rok > (int)$r['sezon']) {                        // nowy rok: byliny zaczynają liczyć ciepło od nowa
            $r['sezon'] = $rok;
            if (!empty($s['wieloletnia'])) { $r['suma'] = 0.0; $r['wiek']++; }
        }
        $wil = $g['wilgoc'] / $cap;
        // mróz: nasiona w ziemi go nie czują, rośliny w zimowym spoczynku znoszą najwięcej, siewki najmniej
        if ($r['faza'] === 'rosnie') {
            // byliny od października do marca są zahartowane: marznie im najwyżej to, co nad ziemią
            $spoczynek = $z['gleba'] < $s['baza'] || (!empty($s['wieloletnia']) && in_array((int)substr($z['data'], 5, 2), [10, 11, 12, 1, 2, 3], true));
            $mlode = empty($s['wieloletnia']) && $r['forma'] === 'nasiona' && $r['suma'] < 2 * $s['kielki'];
            $prog = $spoczynek ? $s['mroz_spoczynek'] : ($mlode ? $s['mroz_mlode'] : $s['mroz']);
            if ($z['snieg_mm'] >= 10) $prog -= 8;
            $prog -= $oslona;
            if ($z['tmin'] < $prog) { $r['zdrowie'] -= 0.15 + 0.12 * ($prog - $z['tmin']); $r['przyczyna'] = 'mroz'; }
        }
        if ($r['faza'] === 'posiane') {
            $r['dni'] = (int)($r['dni'] ?? 0) + 1;
            if ($wil >= 0.2 && $z['gleba'] > $s['baza']) $r['kielki'] += $z['gleba'] - $s['baza'];
            if ($r['kielki'] >= $s['kielki']) { $r['faza'] = 'rosnie'; $r['wzeszlo'] = $z['data']; }
            elseif ($r['dni'] > 80) { $r['martwa'] = 'Nasiona nie wzeszły i zgniły w ziemi.'; }
        } elseif ($rosnie) {
            $r['suma'] = round($r['suma'] + min(15, $z['t'] - $s['baza']), 1);
            $korzenie = !empty($s['wieloletnia']) && $r['wiek'] >= 1 ? 0.35 : 1.0;      // zadomowiona bylina sięga głębiej po wodę
            if ($wil < 0.12) { $r['zdrowie'] -= 0.035 * $korzenie; $r['przyczyna'] = 'susza'; }
            elseif ($wil < 0.25) { $r['zdrowie'] -= 0.01 * $korzenie; $r['przyczyna'] = 'susza'; }
            if ($g['chwasty'] > 0.4) { $r['zdrowie'] -= 0.02 * ($g['chwasty'] - 0.3); if ($wil >= 0.25) $r['przyczyna'] = 'chwasty'; }
            if ($wil >= 0.25 && $g['chwasty'] < 0.4) $r['zdrowie'] += 0.012;
            if (empty($s['wieloletnia']) && $r['suma'] > $s['zbior'] + 1.4 * $s['okno']) $r['martwa'] = 'Przekwitło i zaschło. Na zbiór było za późno.';
        }
        $r['zdrowie'] = round(min(1.0, (float)$r['jakosc'] + 0.05, $r['zdrowie']), 3);
        if ($r['zdrowie'] <= 0 && $r['martwa'] === '') {
            $r['zdrowie'] = 0.0;
            $r['martwa'] = $r['przyczyna'] === 'mroz' ? 'Zmarzło.' : ($r['przyczyna'] === 'susza' ? 'Uschło.' : 'Zmarniało, zagłuszone chwastem.');
        }
        unset($r);
    }
    unset($g);
}

// Wszystko, co się wydarzyło od ostatniego razu: czary, które się skończyły, minione dni, dostawy.
function ogr_dokoncz() {
    ogr_start();
    $o = ogr_czytaj('ogrod'); $zm = false; $teraz = microtime(true);
    foreach ($o['grzadki'] as &$g) if (!empty($g['czar']) && $g['czar']['koniec'] <= $teraz) { $g['czar'] = null; $zm = true; }
    unset($g);
    $wczoraj = pog_przesun(pog_dzis(), -1);
    if ($o['dzien'] < $wczoraj) {
        $od = pog_przesun($o['dzien'], 1);
        $najw = pog_przesun($wczoraj, -(OGR_MAX_DNI - 1));
        if ($od < $najw) $od = $najw;
        foreach (pog_seria($od, $wczoraj) as $z) ogr_przezyj_dzien($o, $z);
        $o['dzien'] = $wczoraj; $zm = true;
    }
    if ($zm) ogr_zapisz('ogrod', $o);
    if (ogr_kasa()) rynek_miesieczne();               // miesięczny dodatek do kasy, jak przy wejściu do księgi
    ogr_dostawy();
    return $o;
}

// --- kasa i sklep --------------------------------------------------------------------------------
// Pieniądze są w księdze rachunkowej innego pokoju (ogrod.json: "kasa"), więc ogród korzysta z jego rynku.
function ogr_kasa() {
    static $k = null;
    if ($k !== null) return $k;
    $p = (string)(ogr_cfg()['kasa'] ?? '');
    $k = bezpieczne($p) && is_file(KORZEN . "/$p/api/_rynek.php");
    if ($k) { require_once KORZEN . "/$p/api/_rynek.php"; $k = function_exists('rynek_cfg') && rynek_cfg() !== null; }
    return $k;
}
function ogr_kwota_txt($pkt) { return ogr_kasa() ? rynek_kwota_txt($pkt) : $pkt . ' pkt'; }
function ogr_sklep() { return ogr_tabela('sklep'); }
function ogr_mies() { return (int)(new DateTime('now', pog_strefa()))->format('n'); }
const OGR_DNI_TYG = ['', 'poniedziałki', 'wtorki', 'środy', 'czwartki', 'piątki', 'soboty', 'niedziele'];
// Czy u dostawcy można teraz kupić: [tak/nie, powód]
function ogr_otwarte($d) {
    if (empty($d['dni'])) return [true, ''];
    $t = new DateTime('now', pog_strefa()); $dz = (int)$t->format('N'); $h = (int)$t->format('G');
    $g = $d['godziny'] ?? [0, 24];
    if (in_array($dz, $d['dni'], true) && $h >= $g[0] && $h < $g[1]) return [true, ''];
    $dni = array_map(function ($x) { return OGR_DNI_TYG[$x] ?? ''; }, $d['dni']);
    $lista = count($dni) > 1 ? implode(', ', array_slice($dni, 0, -1)) . ' i ' . end($dni) : $dni[0];
    return [false, 'Targ jest w ' . $lista . ', od ' . $g[0] . ' do ' . $g[1] . '.'];
}
// Popyt na towary ogrodu: każda kupiona porcja podnosi cenę u tego dostawcy, a z czasem to wygasa.
function ogr_popyt() {
    $c = ogr_sklep()['cena']; $teraz = time(); $p = [];
    $q = baza()->prepare("SELECT ts,dane FROM zdarzenia WHERE typ='wydatek' AND cel=?"); $q->execute([OGR_ID]);
    foreach ($q as $z) {
        $d = json_decode((string)$z['dane'], true) ?: [];
        $zanik = pow(0.5, max(0, $teraz - (int)$z['ts']) / (max(1, (float)$c['polokres_h']) * 3600));
        foreach (($d['towary'] ?? []) as $id => $n) $p[($d['dostawca'] ?? '') . '/' . $id] = ($p[($d['dostawca'] ?? '') . '/' . $id] ?? 0) + (int)$n * $zanik;
    }
    return $p;
}
function ogr_cena($baza, $D) {
    $c = ogr_sklep()['cena']; $K = max(0.01, (float)$c['K']);
    return max(1, (int)round($baza * ((float)$c['min'] + ((float)$c['max'] - (float)$c['min']) * ($D / ($D + $K)))));
}
function ogr_kup($dost, $towary, $gracz) {
    if (!ogr_kasa()) ogr_blad('Nie ma z czego zapłacić: brak księgi rachunkowej.');
    $sk = ogr_sklep(); $d = $sk['dostawcy'][$dost] ?? null;
    if (!$d) ogr_blad('Nie ma takiego dostawcy.');
    [$otw, $pow] = ogr_otwarte($d); if (!$otw) ogr_blad($pow);
    $lim = $sk['zakup']; $zam = []; $razem = 0; $m = ogr_mies();
    foreach (is_array($towary) ? $towary : [] as $id => $n) {
        $n = (int)$n; if ($n < 1) continue;
        $t = $d['towary'][$id] ?? null;
        if (!$t) ogr_blad('Tego ' . $d['nazwa'] . ' nie ma w ofercie.');
        if (!in_array($m, $t['miesiace'] ?? [], true)) ogr_blad($t['nazwa'] . ': teraz nie pora, nikt tego nie sprzedaje.');
        if ($n > (int)$lim['max_pozycja']) ogr_blad('Tyle jednej rzeczy naraz nikt nie przyniesie.');
        $zam[$id] = $n; $razem += $n;
    }
    if ($razem < 1) ogr_blad('Zamówienie jest puste.');
    if ($razem > (int)$lim['max_razem']) ogr_blad('Za dużo na raz.');
    $db = baza(); $db->exec('BEGIN IMMEDIATE'); $blad = '';
    try {
        $P = ogr_popyt(); $suma = 0; $opis = [];
        foreach ($zam as $id => $n) {
            $t = $d['towary'][$id]; $D = (float)($P["$dost/$id"] ?? 0);
            for ($i = 0; $i < $n; $i++) $suma += ogr_cena((int)$t['baza'], $D + $i);
            $opis[] = mb_strtolower($t['nazwa'], 'UTF-8') . ' ×' . $n;
        }
        $saldo = rynek_odczytaj()['saldo'];
        if ($suma > $saldo) $blad = 'Nie starczy pieniędzy: wyszłoby ' . ogr_kwota_txt($suma) . ', a w kasie jest ' . ($saldo > 0 ? ogr_kwota_txt($saldo) : 'pusto') . '.';
        else dodaj_zdarzenie('wydatek', OGR_ID, $gracz, ['koszt' => $suma, 'opis' => 'do ogrodu (' . $d['nazwa'] . '): ' . implode(', ', $opis),
            'dostawca' => $dost, 'towary' => $zam, 'gotowe' => time() + (int)$d['zwloka_s']]);
        $db->exec($blad === '' ? 'COMMIT' : 'ROLLBACK');
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    if ($blad !== '') ogr_blad($blad);
    return 'Zapłacone ' . ogr_kwota_txt($suma) . '. Paczka będzie w skrzynce ' . ogr_za_ile((int)$d['zwloka_s']) . '.';
}
// Zakupy, którym minęła zwłoka, trafiają do skrzynki. Dostawcy mają różne zwłoki, więc kolejność bywa inna niż w dzienniku:
// 'kursor' to ostatnie zdarzenie, do którego wszystko jest odebrane, a 'ids' to odebrane już później.
function ogr_dostawy() {
    $sk = ogr_skrzynka(); $kur = (int)$sk['odebrane']['kursor']; $ids = array_map('intval', $sk['odebrane']['ids']); $zm = false;
    $q = baza()->prepare("SELECT id,dane FROM zdarzenia WHERE typ='wydatek' AND cel=? AND id>? ORDER BY id"); $q->execute([OGR_ID, $kur]);
    $sklep = ogr_sklep()['dostawcy']; $ciagle = true;
    foreach ($q->fetchAll() as $z) {
        $id = (int)$z['id'];
        if (!in_array($id, $ids, true)) {
            $d = json_decode((string)$z['dane'], true) ?: [];
            if ((int)($d['gotowe'] ?? 0) > time()) { $ciagle = false; continue; }
            foreach (($d['towary'] ?? []) as $tid => $n) {
                $t = $sklep[$d['dostawca'] ?? '']['towary'][$tid] ?? null; if (!$t) continue;
                $k = $d['dostawca'] . '/' . $tid;
                if (!isset($sk['paczki'][$k])) $sk['paczki'][$k] = ['dostawca' => $d['dostawca'], 'towar' => $tid, 'gatunek' => $t['gatunek'], 'forma' => $t['forma'],
                    'nazwa' => $t['nazwa'], 'jakosc' => (float)$t['jakosc'], 'n' => 0];
                $sk['paczki'][$k]['n'] += (int)$n;
            }
            $ids[] = $id; $zm = true;
        }
        if ($ciagle) { $kur = $id; $ids = array_values(array_diff($ids, [$id])); $zm = true; }
    }
    if ($zm) { $sk['odebrane'] = ['kursor' => $kur, 'ids' => $ids]; ogr_zapisz('skrzynka', $sk); }
}

// --- zbiory --------------------------------------------------------------------------------------
// Czy da się zebrać: [tak/nie, powód]
function ogr_zbior_mozliwy($r, $s) {
    if (!$r || $r['martwa'] !== '' || $r['faza'] !== 'rosnie') return [false, ''];
    if (!empty($s['wieloletnia']) && (int)$r['zebrane'] === (int)$r['sezon']) return [false, 'W tym roku już zebrane. Odrośnie na wiosnę.'];
    if (!empty($s['min_wiek']) && $r['wiek'] < $s['min_wiek']) return [false, 'Za młode. Zbiera się najwcześniej w drugim roku.'];
    if (!empty($s['miesiace_zbioru']) && !in_array(ogr_mies(), $s['miesiace_zbioru'], true)) return [false, 'Nie pora na zbiór.'];
    if ($r['suma'] < $s['zbior']) return [false, 'Jeszcze nie dojrzało.'];
    return [true, ''];
}
function ogr_jakosc_zbioru($r, $s) {
    $po = $r['suma'] - $s['zbior'] - $s['okno'];
    return $po <= 0 ? 1.0 : max(0.3, 1 - $po / max(1, $s['okno']));
}
function ogr_zbierz(&$g, $gracz) {
    $r = $g['roslina']; $s = ogr_rosliny()[$r['gatunek']];
    [$ok, $pow] = ogr_zbior_mozliwy($r, $s);
    if (!$ok) ogr_blad($pow !== '' ? $pow : 'Nie ma czego zbierać.');
    $los = 0.85 + 0.3 * pog_los(pog_dzis(), 'plon' . $g['id']);
    $plon = $s['plon_g_m2'] * ogr_m2() * $r['zdrowie'] * ogr_jakosc_zbioru($r, $s) * $los;
    $pg = (float)$s['porcja_g'];
    $n = max(1, (int)round($plon / $pg)); $porcje = [];
    if ($plon >= 5) for ($i = 0; $i < $n; $i++) $porcje[] = round($plon / $n, 1);
    if (!empty($s['wieloletnia']) && $s['po_zbiorze'] === 'zostaje') $g['roslina']['zebrane'] = $r['sezon'];
    else $g['roslina'] = null;
    if (!$porcje) return 'Prawie nic z tego nie było. Na kompost.';
    $cel = (string)(ogr_cfg()['do_skladu'] ?? '');
    $nazwa = $s['nazwa'] . ', ' . $s['porcja'][0] . ' z ogrodu';
    dodaj_zdarzenie('do_skladu', $cel, $gracz, ['surowiec' => $s['surowiec'], 'porcje' => $porcje, 'nazwa' => $nazwa,
        'gotowe' => time() + (int)round((float)$s['suszenie_h'] * 3600), 'skad' => OGR_ID]);
    $t = 'Zebrane: ' . count($porcje) . ' ' . ogr_forma(count($porcje), $s['porcja']) . ' (' . ogr_masa_txt($plon) . ').';
    if (!bezpieczne($cel) || !is_dir(KORZEN . "/$cel")) return $t . ' Leży w koszu przy furtce.';
    $wiele = count($porcje) > 1;
    return $t . ((float)$s['suszenie_h'] > 0 ? ($wiele ? ' Schną' : ' Schnie') . ' w szopie; do spiżarni ' . ($wiele ? 'trafią ' : 'trafi ') . ogr_za_ile($s['suszenie_h'] * 3600) . '.'
        : ($wiele ? ' Poszły' : ' Poszło') . ' prosto do spiżarni.');
}

// --- działania gracza ----------------------------------------------------------------------------
function ogr_wykonaj($akcja, $in) {
    $s = function ($k) use ($in) { return isset($in[$k]) && is_scalar($in[$k]) ? (string)$in[$k] : ''; };
    $gracz = $s('gracz');
    if ($akcja === 'kup') return ogr_kup($s('dostawca'), $in['towary'] ?? [], $gracz);
    $o = ogr_czytaj('ogrod'); $cap = ogr_pojemnosc(); $R = ogr_rosliny();
    $g = null;
    if ($s('id') !== '') { if (!isset($o['grzadki'][$s('id')])) ogr_blad('Nie ma takiej grządki.'); $g =& $o['grzadki'][$s('id')]; }
    $gotowa = function () use (&$g) { if (!$g) ogr_blad('Nie wiadomo, o którą grządkę chodzi.'); if (!empty($g['czar'])) ogr_blad('Ta grządka jeszcze się przygotowuje.'); };
    $zyje = function () use (&$g) { return $g['roslina'] && $g['roslina']['martwa'] === ''; };
    switch ($akcja) {
        case 'czar':
            $cz = ogr_tabela('czary')[$s('czar')] ?? null;
            if (!$cz || empty($cz['znany'])) ogr_blad('Tego czaru tu nikt nie zna.');
            switch ($cz['efekt']) {
                case 'nowa_grzadka':
                    $m = (int)$s('miejsce');
                    if ($s('miejsce') === '' || $m < 0 || $m >= count(ogr_miejsca())) ogr_blad('Tam grządka się nie zmieści.');
                    foreach ($o['grzadki'] as $x) if ($x['miejsce'] === $m) ogr_blad('Tu już jest grządka.');
                    if ($pow = ogr_zmarznieta()) ogr_blad($pow . ' Nawet czar jej teraz nie ruszy.');
                    ogr_nowa_grzadka($o, $m, ['id' => $s('czar'), 'koniec' => microtime(true) + (float)($cz['czas_s'] ?? 0)]);
                    $t = $cz['nazwa'] . '. Darń sama się zwija, a ziemia pod nią pulchnieje.';
                    break;
                case 'likwidacja':
                    $gotowa();
                    if ($zyje()) ogr_blad('Na tej grządce coś rośnie. Najpierw trzeba to zebrać albo wyrwać.');
                    unset($o['grzadki'][$g['id']]);
                    $t = $cz['nazwa'] . '. Ziemia wyrównuje się i zarasta trawą.';
                    break;
                case 'podlewa':
                    foreach ($o['grzadki'] as &$x) $x['wilgoc'] = min($cap, $x['wilgoc'] + (float)($cz['mm'] ?? 10));
                    unset($x);
                    $t = $cz['nazwa'] . '. Nad grządkami zbiera się mała chmura i cicho pada.';
                    break;
                case 'pleje':
                    foreach ($o['grzadki'] as &$x) $x['chwasty'] = 0.03;
                    unset($x);
                    $t = $cz['nazwa'] . '. Chwasty same wychodzą z ziemi i układają się w kopczyk przy płocie.';
                    break;
                case 'oslona':
                    $o['oslona_do'] = pog_przesun(pog_dzis(), (int)($cz['dni'] ?? 3) - 1);
                    $t = $cz['nazwa'] . '. Nad grządkami drga ciepłe powietrze.';
                    break;
                default: ogr_blad('Czar nie działa.');
            }
            break;
        case 'posadz':
            $gotowa();
            if ($g['roslina']) ogr_blad($zyje() ? 'Tu już coś rośnie.' : 'Najpierw trzeba wyrwać to, co zostało.');
            if ($pow = ogr_zmarznieta()) ogr_blad($pow);
            $sk = ogr_skrzynka(); $k = $s('paczka'); $p = $sk['paczki'][$k] ?? null;
            if (!$p || $p['n'] < 1) ogr_blad('Takiej paczki w skrzynce nie ma.');
            $sp = $R[$p['gatunek']] ?? null; if (!$sp) ogr_blad('Nie wiadomo, co to za roślina.');
            $sk['paczki'][$k]['n']--; if ($sk['paczki'][$k]['n'] < 1) unset($sk['paczki'][$k]);
            $sadz = $p['forma'] === 'sadzonki';
            $g['roslina'] = ['gatunek' => $p['gatunek'], 'forma' => $p['forma'], 'od' => pog_dzis(), 'faza' => $sadz ? 'rosnie' : 'posiane', 'kielki' => 0.0, 'dni' => 0,
                'suma' => 0.0, 'zdrowie' => round($p['jakosc'] * ($sadz ? 0.95 : 1), 3), 'jakosc' => (float)$p['jakosc'], 'wiek' => 0, 'sezon' => (int)substr(pog_dzis(), 0, 4),
                'zebrane' => 0, 'martwa' => '', 'przyczyna' => ''];
            $g['notatka'] = '';
            ogr_zapisz('skrzynka', $sk);
            $t = ($sadz ? 'Posadzone' : 'Posiane') . ': ' . mb_strtolower($sp['nazwa'], 'UTF-8') . '.';
            $z = pog_dzien(pog_dzis());
            if (!$sadz && $z['gleba'] < $sp['baza']) $t .= ' Ziemia jest jeszcze zimna, nasiona poczekają na ciepło.';
            if ($g['chwasty'] > 0.4) $t .= ' Chwasty od razu zaczną się z nimi kłócić.';
            break;
        case 'podlej':
            $gotowa();
            if (ogr_zmarznieta()) ogr_blad('Woda zamarza na grudach. Teraz nie ma po co podlewać.');
            if ($g['wilgoc'] >= 0.9 * $cap) ogr_blad('Ziemia i tak jest mokra.');
            $g['wilgoc'] = min($cap, $g['wilgoc'] + (float)(ogr_cfg()['podlewanie_mm'] ?? 10));
            $t = 'Podlane. Kilka konewek ze studni.';
            break;
        case 'plej':
            $gotowa();
            if ($pow = ogr_zmarznieta()) ogr_blad($pow);
            if ($g['chwasty'] < 0.1) ogr_blad('Tu nie ma czego pleć.');
            $g['chwasty'] = 0.03; $g['notatka'] = '';
            $t = 'Wyplewione. Chwasty lądują na kompoście.';
            break;
        case 'zbierz':
            $gotowa();
            if (!$zyje()) ogr_blad('Nie ma czego zbierać.');
            if (ogr_zmarznieta()) ogr_blad('Spod zmarzniętej ziemi i śniegu nic się nie wyciągnie.');
            $t = ogr_zbierz($g, $gracz);
            break;
        case 'wyrwij':
            $gotowa();
            if (!$g['roslina']) ogr_blad('Tu nic nie rośnie.');
            $g['roslina'] = null;
            $t = 'Wyrwane. Grządka jest pusta.';
            break;
        default:
            ogr_blad('Nie wiadomo, co zrobić.');
    }
    unset($g);
    ogr_zapisz('ogrod', $o);
    return $t;
}

// --- widok ---------------------------------------------------------------------------------------
function ogr_slowo($x, $progi) { foreach ($progi as $p => $t) if ($x < $p) return $t; return end($progi); }
function ogr_widok_grzadki($g, $zmarzl) {
    $cap = ogr_pojemnosc(); $R = ogr_rosliny(); $teraz = microtime(true);
    $w = ['id' => $g['id'], 'miejsce' => $g['miejsce'], 'notatka' => $g['notatka']];
    if (!empty($g['czar'])) return $w + ['czar' => true, 'za' => ogr_za_ile($g['czar']['koniec'] - $teraz), 'stan' => 'grządka się przygotowuje'];
    $wil = $g['wilgoc'] / $cap;
    $w['wilgoc'] = round($wil, 2);
    $w['wilgoc_t'] = $zmarzl !== '' ? 'zmarznięta' : ogr_slowo($wil, ['0.12' => 'sucha na pieprz', '0.28' => 'przesuszona', '0.7' => 'wilgotna', '9' => 'mokra']);
    $w['chwasty'] = round($g['chwasty'], 2);
    $w['chwasty_t'] = ogr_slowo($g['chwasty'], ['0.15' => 'czysto', '0.4' => 'trochę chwastów', '0.7' => 'zachwaszczona', '9' => 'zarośnięta']);
    $r = $g['roslina'];
    if (!$r) return $w + ['stan' => 'pusta'];
    $s = $R[$r['gatunek']] ?? ['nazwa' => $r['gatunek'], 'baza' => 5, 'zbior' => 1, 'okno' => 1, 'porcja' => ['', '', '']];
    $x = ['gatunek' => $r['gatunek'], 'nazwa' => $s['nazwa'], 'forma' => $r['forma'], 'od' => $r['od'], 'wieloletnia' => !empty($s['wieloletnia']), 'wiek' => (int)$r['wiek']];
    if ($r['martwa'] !== '') return $w + ['roslina' => $x + ['martwa' => $r['martwa'], 'faza_t' => $r['martwa']], 'stan' => 'martwa'];
    [$ok, $pow] = ogr_zbior_mozliwy($r, $s);
    $z = pog_dzien(pog_dzis());
    if ($r['faza'] === 'posiane') $faza = 'posiane, czeka na wschody';
    elseif ($ok) $faza = ogr_jakosc_zbioru($r, $s) < 1 ? 'do zbioru, ale już przerasta' : 'gotowe do zbioru';
    elseif (!empty($s['wieloletnia']) && (int)$r['zebrane'] === (int)$r['sezon']) $faza = $z['gleba'] < $s['baza'] ? 'w zimowym spoczynku' : 'odrasta po zbiorze';
    elseif ($z['gleba'] < $s['baza']) $faza = in_array(ogr_mies(), [11, 12, 1, 2, 3], true) ? 'w zimowym spoczynku' : 'czeka na ciepło';
    else $faza = 'rośnie';
    $x += ['faza' => $r['faza'], 'faza_t' => $faza, 'postep' => round(min(1, $r['suma'] / max(1, $s['zbior'])), 2), 'do_zbioru' => $ok, 'powod' => $pow,
        'zdrowie' => round($r['zdrowie'], 2), 'zdrowie_t' => ogr_slowo($r['zdrowie'], ['0.4' => 'ledwo żywa', '0.65' => 'słabowita', '0.85' => 'zdrowa', '9' => 'bujna']),
        'siew' => $s['siew'] ?? ''];
    return $w + ['roslina' => $x, 'stan' => $ok ? 'do_zbioru' : 'rosnie'];
}
function ogr_widok() {
    $o = ogr_czytaj('ogrod'); $sk = ogr_skrzynka(); $c = ogr_cfg(); $R = ogr_rosliny(); $zmarzl = ogr_zmarznieta(); $teraz = time();
    $grz = [];
    foreach ($o['grzadki'] as $g) $grz[] = ogr_widok_grzadki($g, $zmarzl);
    usort($grz, function ($a, $b) { return $a['miejsce'] <=> $b['miejsce']; });
    $paczki = [];
    foreach ($sk['paczki'] as $k => $p) {
        $sp = $R[$p['gatunek']] ?? [];
        $paczki[] = ['klucz' => $k, 'nazwa' => $p['nazwa'], 'gatunek' => $p['gatunek'], 'gatunek_t' => $sp['nazwa'] ?? $p['gatunek'], 'forma' => $p['forma'], 'n' => (int)$p['n'],
            'jakosc_t' => ogr_slowo($p['jakosc'], ['0.8' => 'różnie się przyjmuje', '0.92' => 'dobra', '9' => 'pewna']), 'siew' => $sp['siew'] ?? ''];
    }
    usort($paczki, function ($a, $b) { return strcmp($a['nazwa'], $b['nazwa']); });
    $wdrodze = [];
    $q = baza()->prepare("SELECT dane FROM zdarzenia WHERE typ='wydatek' AND cel=? AND id>? ORDER BY id"); $q->execute([OGR_ID, (int)$sk['odebrane']['kursor']]);
    foreach ($q as $z) { $d = json_decode((string)$z['dane'], true) ?: []; if ((int)($d['gotowe'] ?? 0) > $teraz) $wdrodze[] = ['opis' => preg_replace('/^do ogrodu /u', '', (string)$d['opis']), 'za' => ogr_za_ile($d['gotowe'] - $teraz)]; }
    $suszy = [];
    $q = baza()->prepare("SELECT dane FROM zdarzenia WHERE typ='do_skladu' AND ts>? ORDER BY id"); $q->execute([$teraz - 30 * 86400]);
    foreach ($q as $z) {
        $d = json_decode((string)$z['dane'], true) ?: [];
        if (($d['skad'] ?? '') === OGR_ID && (int)($d['gotowe'] ?? 0) > $teraz) $suszy[] = ['nazwa' => $d['nazwa'], 'n' => count($d['porcje'] ?? []), 'za' => ogr_za_ile($d['gotowe'] - $teraz)];
    }
    $czary = [];
    foreach (ogr_tabela('czary') as $id => $cz) if (!empty($cz['znany'])) $czary[] = ['id' => $id, 'nazwa' => $cz['nazwa'], 'pl' => $cz['pl'], 'efekt' => $cz['efekt']];
    $sklep = ogr_sklep(); $P = ogr_popyt(); $m = ogr_mies(); $dost = [];
    foreach ($sklep['dostawcy'] as $id => $d) {
        [$otw, $pow] = ogr_otwarte($d); $tow = [];
        foreach ($d['towary'] as $tid => $t) {
            $cena = ogr_cena((int)$t['baza'], (float)($P["$id/$tid"] ?? 0));
            $tow[] = ['id' => $tid, 'nazwa' => $t['nazwa'], 'forma' => $t['forma'], 'gatunek_t' => $R[$t['gatunek']]['nazwa'] ?? $t['gatunek'], 'cena' => $cena, 'cena_t' => ogr_kwota_txt($cena),
                'jest' => in_array($m, $t['miesiace'] ?? [], true)];
        }
        $dost[] = ['id' => $id, 'nazwa' => $d['nazwa'], 'opis' => $d['opis'] ?? '', 'otwarte' => $otw, 'powod' => $pow, 'dostawa' => ogr_za_ile((int)$d['zwloka_s']), 'towary' => $tow];
    }
    $saldo = ogr_kasa() ? rynek_odczytaj()['saldo'] : null;
    $mz = count(ogr_miejsca()); $m2 = ogr_m2();
    return [
        'pogoda' => pog_widok(),
        'oslona_do' => (!empty($o['oslona_do']) && $o['oslona_do'] >= pog_dzis()) ? $o['oslona_do'] : null,
        'zmarznieta' => $zmarzl,
        'dzialka' => ['dl' => $c['dzialka']['dl'], 'szer' => $c['dzialka']['szer'], 'zabudowa' => $c['zabudowa'] ?? [], 'obszar' => $c['obszar_grzadek'] ?? null, 'miejsca' => ogr_miejsca(),
            'm2_grzadki' => $m2, 'grzadek' => count($grz), 'miejsc' => $mz],
        'grzadki' => $grz,
        'skrzynka' => $paczki, 'w_drodze' => $wdrodze, 'suszy_sie' => $suszy,
        'czary' => $czary,
        'sklep' => ['kasa' => ogr_kasa(), 'saldo_t' => $saldo === null ? '' : ($saldo > 0 ? ogr_kwota_txt($saldo) : 'pusto'), 'limity' => $sklep['zakup'], 'dostawcy' => $dost],
    ];
}

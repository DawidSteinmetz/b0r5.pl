<?php
// Rynek surowców i budżet Henryka. Wszystko liczy się z dziennika zdarzeń; to sandbox, który pamięta.
//   zakup {cel: towar, dane: {n, koszt, dom}}  : popyt rośnie; jeśli dom = henryk, kosztuje budżet, a po zwłoce towar trafia do Składu
//   sprzedano {cel: nazwa produktu, dane: {cena}} : przychód, a kolejne sprzedaże są tańsze (podaż)
//   budzet_miesiac {cel: "RRRR-MM", dane: {kwota}} : miesięczne dopełnienie do poziomu 'baza' (nie kumuluje się)
//   wydatek {cel: pokój, dane: {koszt, opis}}      : zakup z innego pokoju (np. nasiona do ogrodu) płacony z tej samej kasy
require_once dirname(__DIR__, 2) . '/api/_baza.php';
if (!defined('KAT_POKOJU')) define('KAT_POKOJU', dirname(__DIR__));

// Dodatkowe towary trzymane w repozytorium (bez kluczy). Pole "sztuk": ile sztuk surowca przychodzi w jednej porcji (np. worek buraków).
function rynek_towary_jawne() {
    $t = wczytaj_json(KAT_POKOJU . '/_logika/tabele/rynek_towary.json');
    return is_array($t['towary'] ?? null) ? $t['towary'] : [];
}
function rynek_cfg() {
    static $c = false;
    if ($c === false) {
        $r = wczytaj_json(KAT_POKOJU . '/_logika/rynek.json');
        if (!is_array($r)) { $c = null; return $c; }
        $c = [
            'budzet' => array_merge(['baza' => 40, 'jednostka' => 'pkt'], is_array($r['budzet'] ?? null) ? $r['budzet'] : []),
            'waluta' => array_merge(['fenigi_za_pkt' => 5], is_array($r['waluta'] ?? null) ? $r['waluta'] : []),
            'zakup' => array_merge(['zwloka_s' => 180, 'max_pozycja' => 4, 'max_razem' => 12], is_array($r['zakup'] ?? null) ? $r['zakup'] : []),
            'cena' => array_merge(['min' => 0.5, 'max' => 2.5, 'K' => 6, 'polokres_h' => 72], is_array($r['cena'] ?? null) ? $r['cena'] : []),
            // towary z rynek.json (tajny, tylko na serwerze) + jawne z tabele/rynek_towary.json; przy tym samym towarze wygrywa rynek.json
            'towary' => (is_array($r['towary'] ?? null) ? $r['towary'] : []) + rynek_towary_jawne(),
            'skup' => array_merge(['min' => 0.4, 'max' => 1.3, 'K' => 2, 'polokres_h' => 72], is_array($r['skup'] ?? null) ? $r['skup'] : []),
            'domy' => is_array($r['domy'] ?? null) ? $r['domy'] : [],
        ];
    }
    return $c;
}
function rynek_zanik($dt, $polokres_h) { return pow(0.5, max(0, $dt) / (max(1, (float)$polokres_h) * 3600)); }
// popyt D >= 0 -> mnożnik ceny z przedziału [min, max]
function rynek_mnoznik($D, $c) {
    $K = max(0.01, (float)$c['K']);
    return (float)$c['min'] + ((float)$c['max'] - (float)$c['min']) * ($D / ($D + $K));
}
function rynek_cena_jedn($baza, $D, $c) { return max(1, (int)round($baza * rynek_mnoznik($D, $c))); }

// Pieniądze w oczach gracza: marki i fenigi (1 marka = 100 fenigów), a nie punkty. Liczy się po polsku:
// "1 marka 20 fenigów", "2 marki", "45 fenigów", "nic".
function rynek_forma($n, $jeden, $dwa, $piec) {
    if ($n == 1) return $jeden;
    return ($n % 10 >= 2 && $n % 10 <= 4 && !($n % 100 >= 12 && $n % 100 <= 14)) ? $dwa : $piec;
}
function rynek_kwota_txt($pkt) {
    $c = rynek_cfg(); $fen = (int)round(abs((float)$pkt) * (float)$c['waluta']['fenigi_za_pkt']);
    $m = intdiv($fen, 100); $f = $fen % 100; $cz = [];
    if ($m > 0) $cz[] = $m . ' ' . rynek_forma($m, 'marka', 'marki', 'marek');
    if ($f > 0) $cz[] = $f . ' ' . rynek_forma($f, 'fenig', 'fenigi', 'fenigów');
    return $cz ? implode(' ', $cz) : 'nic';
}

function rynek_miesiac() {
    $tz = new DateTimeZone(postac()['strefa'] ?? 'Europe/Warsaw');
    return (new DateTime('now', $tz))->format('Y-m');
}

// Czyste odczytanie stanu z dziennika (bez zapisów).
function rynek_odczytaj() {
    $c = rynek_cfg(); $teraz = time();
    $st = baza()->query("SELECT id,ts,typ,cel,gracz,dane FROM zdarzenia WHERE typ IN ('zakup','sprzedano','budzet_miesiac','wydatek') ORDER BY id");
    $popyt = []; $podaz = 0.0; $saldo = 0; $hist = [];
    foreach ($st as $z) {
        $d = $z['dane'] ? json_decode($z['dane'], true) : [];
        if (!is_array($d)) $d = [];
        $ts = (int)$z['ts'];
        switch ($z['typ']) {
            case 'zakup':
                $slug = (string)$z['cel']; $n = (int)($d['n'] ?? 0); $dom = (string)($d['dom'] ?? 'henryk');
                $popyt[$slug] = ($popyt[$slug] ?? 0) + $n * rynek_zanik($teraz - $ts, $c['cena']['polokres_h']);
                if ($dom === 'henryk') { $saldo -= (int)($d['koszt'] ?? 0); $hist[] = ['ts' => $ts, 'typ' => 'zakup', 'cel' => $slug, 'n' => $n, 'kwota' => -(int)($d['koszt'] ?? 0), 'gracz' => (string)$z['gracz']]; }
                else $hist[] = ['ts' => $ts, 'typ' => 'popyt', 'cel' => $slug, 'n' => $n, 'kwota' => 0, 'dom' => $dom];
                break;
            case 'sprzedano':
                $k = (int)($d['cena'] ?? 0); $saldo += $k;
                $podaz += rynek_zanik($teraz - $ts, $c['skup']['polokres_h']);
                $hist[] = ['ts' => $ts, 'typ' => 'sprzedaz', 'cel' => (string)$z['cel'], 'n' => 1, 'kwota' => $k, 'gracz' => (string)$z['gracz']];
                break;
            case 'wydatek':
                $k = (int)($d['koszt'] ?? 0); $saldo -= $k;
                $hist[] = ['ts' => $ts, 'typ' => 'wydatek', 'cel' => (string)$z['cel'], 'n' => 0, 'kwota' => -$k, 'opis' => (string)($d['opis'] ?? ''), 'gracz' => (string)$z['gracz']];
                break;
            case 'budzet_miesiac':
                $k = (int)($d['kwota'] ?? 0); $saldo += $k;
                $hist[] = ['ts' => $ts, 'typ' => 'miesiac', 'cel' => (string)$z['cel'], 'n' => 0, 'kwota' => $k];
                break;
        }
    }
    return ['cfg' => $c, 'popyt' => $popyt, 'podaz' => $podaz, 'saldo' => $saldo, 'hist' => $hist, 'teraz' => $teraz];
}

// Miesięczne dopełnienie do 'baza' (tylko w górę). Raz na miesiąc kalendarzowy w strefie świata; idempotentne.
function rynek_miesieczne() {
    $c = rynek_cfg(); if (!$c) return;
    $m = rynek_miesiac() . '@' . (int)$c['budzet']['baza']; $db = baza();   // zmiana 'baza' w rynek.json dopełnia kasę jeszcze raz (tylko w górę)
    $jest = function () use ($db, $m) {
        $q = $db->prepare("SELECT COUNT(*) FROM zdarzenia WHERE typ='budzet_miesiac' AND cel=?"); $q->execute([$m]);
        return (int)$q->fetchColumn() > 0;
    };
    if ($jest()) return;
    $db->exec('BEGIN IMMEDIATE');
    try {
        if (!$jest()) {
            $o = rynek_odczytaj();
            dodaj_zdarzenie('budzet_miesiac', $m, 'system', ['kwota' => max(0, (int)$c['budzet']['baza'] - $o['saldo'])]);
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}

function rynek_stan() { rynek_miesieczne(); return rynek_odczytaj(); }

// Cena $n sztuk po kolei: każda kupiona sztuka podnosi popyt o 1, więc kolejna jest droższa.
function rynek_wycena($o, $slug, $n) {
    $t = $o['cfg']['towary'][$slug] ?? null; if (!$t) return null;
    $D = (float)($o['popyt'][$slug] ?? 0); $suma = 0; $ceny = [];
    for ($i = 0; $i < $n; $i++) { $p = rynek_cena_jedn((int)$t['baza'], $D + $i, $o['cfg']['cena']); $ceny[] = $p; $suma += $p; }
    return ['suma' => $suma, 'ceny' => $ceny];
}

// Mnożnik skupu: spada z liczbą ostatnich sprzedaży, wraca z czasem. Cenę liczy _alchemia.php z masy, czystości i katalizatora.
function rynek_mnoznik_skupu($o) {
    $e = $o['cfg']['skup']; $S = (float)$o['podaz']; $K = max(0.01, (float)$e['K']);
    return (float)$e['max'] - ((float)$e['max'] - (float)$e['min']) * ($S / ($S + $K));
}

// Poziom ceny słowami (do księgi): względem ceny "spokojnej" i bazowej.
function rynek_poziom($mn, $c) {
    $zakres = (float)$c['max'] - (float)$c['min'];
    $x = $zakres > 0 ? ($mn - (float)$c['min']) / $zakres : 0;
    return $x < 0.12 ? 'tanio' : ($x < 0.35 ? 'niska' : ($x < 0.6 ? 'zwykła' : ($x < 0.85 ? 'wysoka' : 'bardzo wysoka')));
}

function rynek_nazwa($slug) {
    $c = rynek_cfg();
    return !empty($c['towary'][$slug]['nazwa']) ? $c['towary'][$slug]['nazwa'] : ucfirst(str_replace('-', ' ', $slug));
}

// Widok dla gracza i panelu: ceny, saldo, w drodze, historia.
// $pelny = widok mistrza gry i innych domów (z poziomami cen i popytem); gracz dostaje tylko kwoty w markach i fenigach.
function rynek_publiczny($o, $pelny = false) {
    $c = $o['cfg']; $teraz = $o['teraz']; $zw = (int)$c['zakup']['zwloka_s']; $drodze = [];
    $q = baza()->query("SELECT ts,cel,dane FROM zdarzenia WHERE typ='zakup' AND ts>" . ($teraz - $zw - 1) . " ORDER BY id");
    foreach ($q as $z) { $d = json_decode((string)$z['dane'], true) ?: []; if (($d['dom'] ?? 'henryk') === 'henryk') $drodze[$z['cel']] = ($drodze[$z['cel']] ?? 0) + (int)($d['n'] ?? 0); }
    $t = [];
    foreach ($c['towary'] as $slug => $x) {
        $D = (float)($o['popyt'][$slug] ?? 0); $mn = rynek_mnoznik($D, $c['cena']);
        $cena = rynek_cena_jedn((int)$x['baza'], $D, $c['cena']);
        $w = ['id' => $slug, 'nazwa' => rynek_nazwa($slug), 'cena' => $cena, 'cena_t' => rynek_kwota_txt($cena), 'w_drodze' => (int)($drodze[$slug] ?? 0)];
        if ($pelny) $w += ['baza' => (int)$x['baza'], 'poziom' => rynek_poziom($mn, $c['cena']), 'popyt' => round($D, 2)];
        $t[] = $w;
    }
    $hist = array_slice(array_reverse($o['hist']), 0, 12);
    foreach ($hist as &$h) $h['kwota_t'] = rynek_kwota_txt($h['kwota'] ?? 0);
    unset($h);
    $wyj = ['saldo' => $o['saldo'], 'saldo_t' => $o['saldo'] < 0 ? 'na minusie' : rynek_kwota_txt($o['saldo']), 'towary' => $t,
        'limity' => ['max_pozycja' => (int)$c['zakup']['max_pozycja'], 'max_razem' => (int)$c['zakup']['max_razem']],
        'zwloka_s' => $zw, 'historia' => $hist];
    if ($pelny) $wyj += ['jednostka' => $c['budzet']['jednostka'], 'baza_budzetu' => (int)$c['budzet']['baza'], 'fenigi_za_pkt' => (float)$c['waluta']['fenigi_za_pkt']];
    return $wyj;
}

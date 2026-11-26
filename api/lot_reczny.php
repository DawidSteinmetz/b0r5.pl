<?php
// Lot ręczny okrętu w realnej grze (narzedzia/mostek/MECHANIKA.md 11.5). Te same zasady napędu co w symulacji
// (silnik.py ster_okretu, mnoznik_skretu): ciąg silników głównych burt i środkowego z zakresami, różnica ciągu burt
// skręca okręt po łuku, trym odchylenia, dysze manewrowe (przesuw pod skosem), prędkość kątowa skrętu z silników
// manewrowych, przechył, wzmocnienia, blokada Anchor/Lock — ale w skali prędkości przelotowej układu (pełny ciąg =
// prędkość autopilota w układzie, rozpęd do niej w ok. minutę, skręt z napędu jednostki, ISD 30°/min).
//
// Stan: okrety_nastawy (nastawy mostka okrętu, wspólne dla załogi) i okrety_lot_reczny (prędkość, prędkość i kurs
// zadane, chwila ostatniego przeliczenia) — migracja 079. Pozycja w układzie: mapa_obiekty.uklad_kx/uklad_ky (km od
// słońca, oś y w górę), kurs: mapa_obiekty.kurs_deg (matematyczny, od osi x przeciwnie do zegara). Konsole używają
// kursu nawigacyjnego (od północy, zgodnie z zegarem): kurs_nav = 90 − kurs_deg.
// Ręczne przejmuje: ciąg z mostka przerywa lot autopilota i schodzi z orbity; nowy lot albo kurs z mapy przejmuje
// z powrotem (lot — zeruje ciąg, kurs — staje się kursem zadanym). Przeliczanie leniwe, przy każdym odczycie ruchu.

declare(strict_types=1);

const LOT_RECZNY_ROZPED_S = 60.0;   // [R] od zera do pełnego ciągu
const LOT_RECZNY_KROK_S = 1.0;
const LOT_RECZNY_MAKS_KROKOW = 3000;

function lot_reczny_dostepny(): bool
{
    static $jest = null;
    if ($jest === null) {
        try {
            polaczenie_bazy()->query('SELECT obiekt_id FROM okrety_lot_reczny LIMIT 0');
            polaczenie_bazy()->query('SELECT kod FROM okrety_nastawy LIMIT 0');
            polaczenie_bazy()->query('SELECT uklad_kx FROM mapa_obiekty LIMIT 0');
            $jest = true;
        } catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// Nastawy mostka okrętu: kod => wartość (bez wpisów — wartości domyślne silnika, BITWA_NASTAWY).
function okret_nastawy(int $obiektId): array
{
    if (!lot_reczny_dostepny()) return [];
    $q = polaczenie_bazy()->prepare('SELECT kod, wartosc FROM okrety_nastawy WHERE obiekt_id=?');
    $q->execute([$obiektId]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['kod']] = (float) $r['wartosc'];
    return $out;
}

function okret_nastawa_ustaw(int $obiektId, string $kod, float $v): void
{
    polaczenie_bazy()->prepare('INSERT INTO okrety_nastawy (obiekt_id, kod, wartosc) VALUES (?,?,?) ON DUPLICATE KEY UPDATE wartosc=VALUES(wartosc)')
        ->execute([$obiektId, $kod, $v]);
}

// Stan „jak w symulacji” do funkcji silnika (bitwa_nast, bitwa_wzm, bitwa_mnoznik_skretu): jednostka nr 0
// z nastawami okrętu; wzmocnienia działają do chwili zapisanej w nastawie wzm_<cel> (czas uniksowy).
function lot_reczny_stan(int $obiektId, int $teraz): array
{
    $n = okret_nastawy($obiektId);
    $dziala = [];
    foreach ($n as $k => $v) if (str_starts_with($k, 'wzm_') && $v > $teraz) $dziala['0:' . $k] = true;
    $nastawy = array_filter($n, static fn($k) => isset(BITWA_NASTAWY[$k]), ARRAY_FILTER_USE_KEY);
    return [['_dziala' => $dziala, 'tik' => $teraz], ['nr' => 0, 'nastawy' => $nastawy], $n];
}

// Prędkość przelotowa w układzie (km/s) — jak autopilot (floty_czas_lotu_w_ukladzie_s).
function lot_reczny_v_maks(float $pcDoba): float
{
    return max(0.01, $pcDoba) * (defined('LOT_UKLAD_KMS_NA_PC_DOBA') ? LOT_UKLAD_KMS_NA_PC_DOBA : 2833.0);
}

function lot_reczny_wiersz(int $obiektId): ?array
{
    $q = polaczenie_bazy()->prepare('SELECT r.v_kms, r.v_cel_kms, r.kurs_cel_deg, r.ostatnio_utc, o.kurs_deg, o.uklad_kx, o.uklad_ky, o.planeta_id, o.x, o.y,
            o.lot_do_utc, o.manewr_od_utc, o.manewr_do_utc, o.kurs_cel_deg AS manewr_cel, COALESCE(n.obrot_deg_na_min, 90) AS obrot,
            COALESCE(n.podswietlna_pc_doba, 20) AS g
        FROM mapa_obiekty o LEFT JOIN okrety_lot_reczny r ON r.obiekt_id = o.id LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id = o.id WHERE o.id=?');
    $q->execute([$obiektId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Przelicza ruch okrętów w locie ręcznym do „teraz” (wszystkich albo jednego).
function lot_reczny_aktualizuj(?int $tylko = null): void
{
    if (!lot_reczny_dostepny()) return;
    $db = polaczenie_bazy();
    $q = $db->query('SELECT obiekt_id FROM okrety_lot_reczny' . ($tylko ? ' WHERE obiekt_id=' . (int) $tylko : ''));
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) lot_reczny_przelicz((int) $id);
}

function lot_reczny_przelicz(int $id): void
{
    $db = polaczenie_bazy();
    $r = lot_reczny_wiersz($id);
    if (!$r || $r['ostatnio_utc'] === null) return;
    $teraz = czas_teraz();
    $od = czas_z_utc((string) $r['ostatnio_utc']);
    $zeruj = static fn() => $db->prepare('UPDATE okrety_lot_reczny SET v_kms=0, v_cel_kms=0, kurs_cel_deg=NULL, ostatnio_utc=? WHERE obiekt_id=?')
        ->execute([czas_utc($teraz), $id]);
    // Autopilot przejął (nowy lot podświetlny): ciąg ręczny wyzerowany.
    if ($r['lot_do_utc'] !== null) { $zeruj(); return; }
    // Kurs wydany z mapy po ostatnim przeliczeniu staje się kursem zadanym lotu ręcznego.
    $kursCel = $r['kurs_cel_deg'] !== null ? (float) $r['kurs_cel_deg'] : null;
    if ($r['manewr_do_utc'] !== null && $r['manewr_cel'] !== null && czas_z_utc((string) $r['manewr_od_utc']) >= $od) $kursCel = 90 - (float) $r['manewr_cel'];
    // Bez ciągu i prędkości okręt zostaje tam, gdzie jest (na orbicie albo w punkcie układu) — obraca się tylko do
    // kursu zadanego i trymem; przesuw dyszami tylko poza orbitą. Bez kursu zadanego i trymów — tylko chwila przeliczenia.
    $ruch = (float) $r['v_kms'] > 0 || (float) $r['v_cel_kms'] > 0;
    $n0 = okret_nastawy($id);
    $trymOdch = abs(($n0['trym_odch'] ?? 0.5) - 0.5) > 1e-9;
    $dysze = array_sum(array_map(static fn($k) => abs(($n0[$k] ?? 0.5) - 0.5), ['dysza_pg', 'dysza_pd', 'katdysz_p', 'dysza_lg', 'dysza_ld', 'katdysz_l'])) > 1e-9;
    $naOrbicie = $r['planeta_id'] !== null && !$ruch;
    if (!$ruch && $kursCel === null && !$trymOdch && ($naOrbicie || !$dysze)) {
        $db->prepare('UPDATE okrety_lot_reczny SET kurs_cel_deg=NULL, ostatnio_utc=? WHERE obiekt_id=?')->execute([czas_utc($teraz), $id]);
        return;
    }
    $poprzednio = ['planeta_id' => $r['planeta_id'] !== null ? (int) $r['planeta_id'] : null,
        'kx' => $r['uklad_kx'] !== null ? (float) $r['uklad_kx'] : null, 'ky' => $r['uklad_ky'] !== null ? (float) $r['uklad_ky'] : null, 'x' => $r['x'], 'y' => $r['y']];
    // Zejście z orbity (tylko z ciągiem): punkt planety w układzie (km od słońca).
    $kx = $r['uklad_kx'] !== null ? (float) $r['uklad_kx'] : ($naOrbicie ? 0.0 : null);
    $ky = $r['uklad_ky'] !== null ? (float) $r['uklad_ky'] : ($naOrbicie ? 0.0 : null);
    if ($r['planeta_id'] !== null && !$naOrbicie) {
        $k = function_exists('floty_planeta_km') ? floty_planeta_km((int) $r['planeta_id'], $teraz) : null;
        if (!$k) { $zeruj(); return; }
        [$kx, $ky] = $k;
        $db->prepare('UPDATE mapa_obiekty SET planeta_id=NULL' . (function_exists('floty_orbita_dostepna') && floty_orbita_dostepna() ? ', orbita_deg=NULL' : '')
            . (function_exists('floty_nawigacja_dostepna') && floty_nawigacja_dostepna() ? ', podejscie=NULL' : '') . ' WHERE id=?')->execute([$id]);
    }
    if ($kx === null || $ky === null) { $zeruj(); return; }   // pusta przestrzeń między układami — tylko autopilot
    [$S, $j] = lot_reczny_stan($id, $teraz);
    $n = static fn(string $k): float => bitwa_nast($j, $k);
    $w = static fn(string $c): float => bitwa_wzm($S, $j, $c);
    $vMaks = lot_reczny_v_maks((float) $r['g']);
    $kurs = 90 - (float) $r['kurs_deg'];        // nawigacyjny
    $v = (float) $r['v_kms'];
    $vCel = (float) $r['v_cel_kms'];
    $obrot = (float) $r['obrot'] ?: 90.0;
    $ciag = ($n('sil_max_l') + $n('sil_max_p') + $n('sil_srodek')) / 3;
    $wzmV = 1 + 0.1 * ($w('gl_l') + $w('gl_p') + $w('gl_s'));
    $a = $vMaks / LOT_RECZNY_ROZPED_S * $ciag * $wzmV * (1 + 0.1 * ($n('sync_gl_l_s') + $n('sync_gl_p_s')));
    $vGora = $vMaks * $ciag * $wzmV * (1 - 0.2 * abs($n('trym_poch') - 0.5));
    $cel = max(0.0, min($vGora, $vCel));
    $cel = max($cel, min($vGora, $vMaks * ($n('sil_min_l') + $n('sil_min_p')) / 3));
    $blokada = $n('blokada') >= 0.5;
    if ($blokada || $naOrbicie) $cel = 0.0;
    $roznica = $n('sil_max_l') - $n('sil_max_p');
    $trym = ($n('trym_odch') - 0.5) * 0.4;      // °/s
    $vb = 0.02 / 0.27 * $vMaks * (($n('dysza_pg') + $n('dysza_pd') + $n('katdysz_p')) - ($n('dysza_lg') + $n('dysza_ld') + $n('katdysz_l')));
    if ($blokada || $naOrbicie) $vb = 0.0;
    $T = max(0, $teraz - $od);
    $t = 0.0;
    $kroki = 0;
    while ($t < $T && $kroki < LOT_RECZNY_MAKS_KROKOW) {
        $ustalony = abs($cel - $v) < 1e-9 && ($kursCel === null || abs(bitwa_norm180($kursCel - $kurs)) < 1e-6);
        if ($ustalony) break;
        $dt = min($kroki < 600 ? LOT_RECZNY_KROK_S : 10.0, $T - $t);
        if ($kursCel !== null && !$blokada) {
            $d = bitwa_norm180($kursCel - $kurs);
            $zw = $obrot / 60 * bitwa_mnoznik_skretu($S, $j, $d < 0 ? 'l' : 'p') * $dt;
            $kurs = bitwa_norm180($kurs + max(-$zw, min($zw, $d)));
            if (abs(bitwa_norm180($kursCel - $kurs)) < 1e-6) { $kurs = $kursCel; $kursCel = null; }
        }
        $v = $v + max(-$a * $dt, min($a * $dt, $cel - $v));
        $omega = ($v > 0 ? $roznica * 0.3 * $v / $vMaks : 0.0) + $trym;
        $kurs = bitwa_norm180($kurs + $omega * $dt);
        $kr = bitwa_radiany($kurs);
        $kx += ($v * sin($kr) + $vb * cos($kr)) * $dt;
        $ky += ($v * cos($kr) - $vb * sin($kr)) * $dt;
        $t += $dt;
        $kroki++;
    }
    // Ustalony ruch (prędkość osiągnięta, kurs zadany osiągnięty): łuk o stałej prędkości kątowej albo prosta.
    $R = $T - $t;
    if ($R > 0) {
        $omega = ($v > 0 ? $roznica * 0.3 * $v / $vMaks : 0.0) + $trym;
        $k0 = bitwa_radiany($kurs);
        if (abs($omega) < 1e-9) {
            $kx += ($v * sin($k0) + $vb * cos($k0)) * $R;
            $ky += ($v * cos($k0) - $vb * sin($k0)) * $R;
        } else {
            $wr = bitwa_radiany($omega);
            $k1 = $k0 + $wr * $R;
            $kx += $v / $wr * (cos($k0) - cos($k1)) + $vb / $wr * (sin($k1) - sin($k0));
            $ky += $v / $wr * (sin($k1) - sin($k0)) + $vb / $wr * (cos($k1) - cos($k0));
            $kurs = bitwa_norm180($kurs + $omega * $R);
        }
    }
    $kursMapy = round(fmod(fmod(90 - $kurs, 360) + 360, 360), 3);
    if ($naOrbicie) {
        // Na orbicie: tylko obrót dziobu (miejsce na orbicie bez zmian).
        $db->prepare('UPDATE mapa_obiekty SET kurs_deg=?, kurs_start_deg=NULL, kurs_cel_deg=NULL, manewr_od_utc=NULL, manewr_do_utc=NULL WHERE id=?')
            ->execute([$kursMapy, $id]);
    } else {
        $db->prepare('UPDATE mapa_obiekty SET uklad_kx=?, uklad_ky=?, kurs_deg=?, kurs_start_deg=NULL, kurs_cel_deg=NULL, manewr_od_utc=NULL, manewr_do_utc=NULL WHERE id=?')
            ->execute([round($kx, 1), round($ky, 1), $kursMapy, $id]);
    }
    $db->prepare('UPDATE okrety_lot_reczny SET v_kms=?, kurs_cel_deg=?, ostatnio_utc=? WHERE obiekt_id=?')
        ->execute([$v, $kursCel, czas_utc($teraz), $id]);
    lot_reczny_flota_za_okretem($id, $poprzednio, $naOrbicie ? null : [round($kx, 1), round($ky, 1)], $kursMapy);
}

// Flota za okrętem: okręty tej samej floty, które stały w tym samym miejscu (ta sama orbita albo ten sam punkt
// układu) i nie lecą same (autopilot, własny lot ręczny), lecą za okrętem z mostka — ta sama pozycja i kurs; mapa
// rozstawia je w szyku floty. Na orbicie (bez ciągu) tylko obracają się razem z nim.
function lot_reczny_flota_za_okretem(int $id, array $poprzednio, ?array $k, float $kursMapy): void
{
    if (!tabela_sql_dostepna('mapa_floty_jednostki')) return;
    $db = polaczenie_bazy();
    try {
        $q = $db->prepare('SELECT o.id, o.planeta_id, o.uklad_kx, o.uklad_ky, o.x, o.y, o.lot_do_utc, r.v_kms, r.v_cel_kms
            FROM mapa_floty_jednostki f1 JOIN mapa_floty_jednostki f2 ON f2.flota_id = f1.flota_id AND f2.obiekt_id <> f1.obiekt_id
            JOIN mapa_obiekty o ON o.id = f2.obiekt_id LEFT JOIN okrety_lot_reczny r ON r.obiekt_id = o.id
            WHERE f1.obiekt_id = ? AND o.aktywny = 1');
        $q->execute([$id]);
        $wiersze = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return; }
    foreach ($wiersze as $o) {
        if ($o['lot_do_utc'] !== null || (float) ($o['v_kms'] ?? 0) > 0 || (float) ($o['v_cel_kms'] ?? 0) > 0) continue;
        if ((string) $o['x'] !== (string) $poprzednio['x'] || (string) $o['y'] !== (string) $poprzednio['y']) continue;
        $tuSamo = $poprzednio['planeta_id'] !== null
            ? (int) $o['planeta_id'] === $poprzednio['planeta_id']
            : $o['planeta_id'] === null && $o['uklad_kx'] !== null && $poprzednio['kx'] !== null
                && hypot((float) $o['uklad_kx'] - $poprzednio['kx'], (float) $o['uklad_ky'] - $poprzednio['ky']) < 50000;
        if (!$tuSamo) continue;
        if ($k === null) {
            $db->prepare('UPDATE mapa_obiekty SET kurs_deg=?, kurs_start_deg=NULL, kurs_cel_deg=NULL, manewr_od_utc=NULL, manewr_do_utc=NULL WHERE id=?')
                ->execute([$kursMapy, $o['id']]);
            continue;
        }
        $db->prepare('UPDATE mapa_obiekty SET planeta_id=NULL, uklad_kx=?, uklad_ky=?, kurs_deg=?, kurs_start_deg=NULL, kurs_cel_deg=NULL,
            manewr_od_utc=NULL, manewr_do_utc=NULL' . (function_exists('floty_orbita_dostepna') && floty_orbita_dostepna() ? ', orbita_deg=NULL' : '')
            . (function_exists('floty_nawigacja_dostepna') && floty_nawigacja_dostepna() ? ', podejscie=NULL' : '') . ' WHERE id=?')
            ->execute([$k[0], $k[1], $kursMapy, $o['id']]);
    }
}

// Widok mostka bez symulacji (mostek_widok): ruch prawdziwego okrętu i jego nastawy zamiast stanu w spoczynku.
function lot_reczny_do_widoku(array $widok, int $obiektId): array
{
    if (!lot_reczny_dostepny() || empty($widok['jednostki'][0])) return $widok;
    lot_reczny_przelicz($obiektId);
    $r = lot_reczny_wiersz($obiektId);
    if (!$r) return $widok;
    $teraz = czas_teraz();
    [, , $wszystkie] = lot_reczny_stan($obiektId, $teraz);
    $j = &$widok['jednostki'][0];
    $vMaks = lot_reczny_v_maks((float) $r['g']);
    $j['v'] = (float) ($r['v_kms'] ?? 0);
    $j['v_cel'] = $r['v_cel_kms'] !== null ? (float) $r['v_cel_kms'] : null;
    $j['kurs'] = round(fmod(fmod(90 - (float) $r['kurs_deg'], 360) + 360, 360), 2);
    $j['kurs_cel'] = $r['kurs_cel_deg'] !== null ? (float) $r['kurs_cel_deg'] : null;
    $j['v_maks'] = $vMaks;
    $j['obrot_deg_min'] = (float) $r['obrot'];
    $j['ster'] = true;
    $j['nastawy_mozliwe'] = true;
    $j['nastawy'] = array_filter($wszystkie, static fn($k) => isset(BITWA_NASTAWY[$k]), ARRAY_FILTER_USE_KEY) + BITWA_NASTAWY;
    $wzm = [];
    foreach ($wszystkie as $k => $v) {
        if (!str_starts_with($k, 'wzm_')) continue;
        $cel = substr($k, 4);
        if ($v > $teraz) $wzm[$cel] = ['stan' => 'dziala', 'za_s' => $v - $teraz];
        elseif (($wszystkie['wzo_' . $cel] ?? 0) > $teraz) $wzm[$cel] = ['stan' => 'odnowienie', 'za_s' => $wszystkie['wzo_' . $cel] - $teraz];
    }
    $j['wzmocnienia'] = $wzm;
    $j['lot'] = ['autopilot' => $r['lot_do_utc'] !== null, 'orbita' => $r['planeta_id'] !== null,
        'uklad_km' => $r['uklad_kx'] !== null ? [(float) $r['uklad_kx'], (float) $r['uklad_ky']] : null];
    unset($j);
    $widok['realny'] = true;
    return $widok;
}

// Rozkaz z konsoli mostka bez symulacji: ster (kurs nawigacyjny, prędkość km/s), nastawy, wyrównania, wzmocnienia.
// Pozostałe rozkazy (osłony, baterie, klucze) działają tylko w symulacji bitwy.
function lot_reczny_rozkaz(array $u, int $obiektId, array $w): array
{
    if (!lot_reczny_dostepny()) return ['odmowa' => 'Sterowanie okrętem z mostka wymaga migracji 079.'];
    $o = mostek_okret($obiektId);
    $d = mostek_dostep($u, $o);
    $pulpit = (string) ($w['pulpit'] ?? '');
    if (!$d['pelny'] && !in_array($pulpit, $d['moje'], true)) return ['odmowa' => "Pulpit {$pulpit} nie jest Twoim stanowiskiem."];
    $db = polaczenie_bazy();
    $teraz = czas_teraz();
    $kod = (string) ($w['kod'] ?? '');
    lot_reczny_przelicz($obiektId);
    if ($kod === 'ster') {
        $r = lot_reczny_wiersz($obiektId);
        if (!$r) return ['odmowa' => 'Brak okrętu.'];
        $vMaks = lot_reczny_v_maks((float) $r['g']);
        $kurs = isset($w['kurs']) ? bitwa_norm180((float) $w['kurs']) : null;
        $v = isset($w['v']) ? max(0.0, min($vMaks, (float) $w['v'])) : null;
        // Ręczne przejmuje od autopilota: przerwany lot podświetlny, zejście z orbity przy pierwszym przeliczeniu.
        if ($r['lot_do_utc'] !== null && function_exists('floty_lot_przerwij')) { try { floty_lot_przerwij([$obiektId]); } catch (Throwable $e) {} }
        $db->prepare('INSERT INTO okrety_lot_reczny (obiekt_id, v_kms, v_cel_kms, kurs_cel_deg, ostatnio_utc) VALUES (?,0,?,?,?)
            ON DUPLICATE KEY UPDATE v_cel_kms=COALESCE(?, v_cel_kms), kurs_cel_deg=COALESCE(?, kurs_cel_deg), ostatnio_utc=ostatnio_utc')
            ->execute([$obiektId, $v ?? 0.0, $kurs, czas_utc($teraz), $v, $kurs]);
        return ['ok' => true, 'dziala_za_s' => 0];
    }
    if (in_array($kod, ['nastawa', 'synchronizuj', 'przelacz'], true)) {
        $k = (string) ($w['nastawa'] ?? '');
        if (!isset(BITWA_NASTAWY[$k]) || str_starts_with($k, 'zapor_do_') || $k === 'blokada_od') return ['odmowa' => 'Nieznana nastawa.'];
        $v = max(0.0, min(1.0, (float) ($w['wartosc'] ?? 0)));
        if (str_starts_with($k, 'sync_') || str_starts_with($k, 'przel_')) $v = $v >= 0.5 ? 1.0 : 0.0;
        okret_nastawa_ustaw($obiektId, $k, round($v, 4));
        if ($k === 'blokada' && $v >= 0.5) okret_nastawa_ustaw($obiektId, 'blokada_od', (float) $teraz);
        return ['ok' => true, 'dziala_za_s' => 0];
    }
    if ($kod === 'wyrownaj') {
        $g = (string) ($w['grupa'] ?? '');
        if (!isset(BITWA_GRUPY_WYROWNANIA[$g])) return ['odmowa' => 'Nieznana grupa wyrównania.'];
        foreach (BITWA_GRUPY_WYROWNANIA[$g] as $k) okret_nastawa_ustaw($obiektId, $k, BITWA_NASTAWY[$k]);
        return ['ok' => true, 'dziala_za_s' => 0];
    }
    if ($kod === 'wzmocnij') {
        $cel = (string) ($w['cel'] ?? '');
        if (!in_array($cel, BITWA_WZMOCNIENIA, true)) return ['odmowa' => 'Nieznany cel wzmocnienia.'];
        $n = okret_nastawy($obiektId);
        if (($n['wzo_' . $cel] ?? 0) > $teraz) return ['odmowa' => 'wzmocnij: odnowienie jeszcze ' . (int) (($n['wzo_' . $cel]) - $teraz) . ' s'];
        okret_nastawa_ustaw($obiektId, 'wzm_' . $cel, (float) ($teraz + 12));
        okret_nastawa_ustaw($obiektId, 'wzo_' . $cel, (float) ($teraz + 36));
        return ['ok' => true, 'dziala_za_s' => 0];
    }
    return ['odmowa' => 'Ten rozkaz działa tylko w symulacji bitwy — poza nią pulpit ustawia napęd, nastawy i wzmocnienia.'];
}

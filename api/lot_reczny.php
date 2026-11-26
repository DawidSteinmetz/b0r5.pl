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

// Parametry ruchu z nastaw (jak silnik.py ster_okretu): prędkość maks., obrót, przyspieszenie, prędkość docelowa,
// różnica ciągu burt, trym odchylenia (°/s), przesuw dyszami (km/s). Wspólne dla przeliczenia i mapy (lot_reczny_mapa).
function lot_reczny_parametry(array $S, array $j, array $r, bool $naOrbicie): array
{
    $n = static fn(string $k): float => bitwa_nast($j, $k);
    $w = static fn(string $c): float => bitwa_wzm($S, $j, $c);
    $vMaks = lot_reczny_v_maks((float) $r['g']);
    $ciag = ($n('sil_max_l') + $n('sil_max_p') + $n('sil_srodek')) / 3;
    $wzmV = 1 + 0.1 * ($w('gl_l') + $w('gl_p') + $w('gl_s'));
    $a = $vMaks / LOT_RECZNY_ROZPED_S * $ciag * $wzmV * (1 + 0.1 * ($n('sync_gl_l_s') + $n('sync_gl_p_s')));
    $vGora = $vMaks * $ciag * $wzmV * (1 - 0.2 * abs($n('trym_poch') - 0.5));
    $cel = max(0.0, min($vGora, (float) $r['v_cel_kms']));
    $cel = max($cel, min($vGora, $vMaks * ($n('sil_min_l') + $n('sil_min_p')) / 3));
    $blokada = $n('blokada') >= 0.5;
    if ($blokada || $naOrbicie) $cel = 0.0;
    $vb = 0.02 / 0.27 * $vMaks * (($n('dysza_pg') + $n('dysza_pd') + $n('katdysz_p')) - ($n('dysza_lg') + $n('dysza_ld') + $n('katdysz_l')));
    return ['v_maks' => $vMaks, 'obrot' => (float) $r['obrot'] ?: 90.0, 'a' => $a, 'cel' => $cel, 'blokada' => $blokada,
        'roznica' => $n('sil_max_l') - $n('sil_max_p'), 'trym' => ($n('trym_odch') - 0.5) * 0.4, 'vb' => $blokada || $naOrbicie ? 0.0 : $vb,
        'ml' => bitwa_mnoznik_skretu($S, $j, 'l'), 'mp' => bitwa_mnoznik_skretu($S, $j, 'p')];
}

// Lot ręczny dla mapy (api/ruch.php mapa_kurs_pola): stan z chwili ostatniego przeliczenia i parametry ruchu, żeby
// tablet przesuwał i obracał znacznik płynnie między odczytami (te same wzory, js/aplikacje/mapa.js recznyTeraz).
// Okręty floty lecące za okrętem z mostka (ta sama pozycja, bez własnego lotu) dostają jego wpis.
function lot_reczny_mapa(int $obiektId): ?array
{
    static $wpisy = null;
    if ($wpisy === null) {
        $wpisy = [];
        if (!lot_reczny_dostepny()) return null;
        try {
            $ids = polaczenie_bazy()->query('SELECT obiekt_id FROM okrety_lot_reczny WHERE v_kms > 0 OR v_cel_kms > 0 OR kurs_cel_deg IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { return null; }
        $teraz = czas_teraz();
        foreach ($ids as $id) {
            $id = (int) $id;
            $r = lot_reczny_wiersz($id);
            if (!$r || $r['ostatnio_utc'] === null || $r['lot_do_utc'] !== null) continue;
            $naOrbicie = $r['planeta_id'] !== null;
            if (!$naOrbicie && $r['uklad_kx'] === null) continue;
            [$S, $j] = lot_reczny_stan($id, $teraz);
            $p = lot_reczny_parametry($S, $j, $r, $naOrbicie);
            $wpis = ['t0' => czas_z_utc((string) $r['ostatnio_utc']), 'kurs' => 90 - (float) $r['kurs_deg'], 'v' => (float) $r['v_kms'],
                'kurs_cel' => $r['kurs_cel_deg'] !== null ? (float) $r['kurs_cel_deg'] : null, 'orbita' => $naOrbicie,
                'k' => $naOrbicie ? null : [(float) $r['uklad_kx'], (float) $r['uklad_ky']]] + $p;
            unset($wpis['blokada']);
            $wpisy[$id] = $wpis;
            if (tabela_sql_dostepna('mapa_floty_jednostki')) {
                $q = polaczenie_bazy()->prepare('SELECT o.id FROM mapa_floty_jednostki f1 JOIN mapa_floty_jednostki f2 ON f2.flota_id = f1.flota_id AND f2.obiekt_id <> f1.obiekt_id
                    JOIN mapa_obiekty o ON o.id = f2.obiekt_id LEFT JOIN okrety_lot_reczny r ON r.obiekt_id = o.id
                    WHERE f1.obiekt_id = ? AND o.aktywny = 1 AND o.lot_do_utc IS NULL AND COALESCE(r.v_kms, 0) = 0 AND COALESCE(r.v_cel_kms, 0) = 0
                      AND o.x <=> ? AND o.y <=> ? AND o.planeta_id <=> ? AND o.uklad_kx <=> ? AND o.uklad_ky <=> ?');
                $q->execute([$id, $r['x'], $r['y'], $r['planeta_id'], $r['uklad_kx'], $r['uklad_ky']]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $f) $wpisy[(int) $f] ??= $wpis;
            }
        }
    }
    return $wpisy[$obiektId] ?? null;
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
    $kurs = 90 - (float) $r['kurs_deg'];        // nawigacyjny
    $v = (float) $r['v_kms'];
    ['v_maks' => $vMaks, 'obrot' => $obrot, 'a' => $a, 'cel' => $cel, 'blokada' => $blokada, 'roznica' => $roznica, 'trym' => $trym, 'vb' => $vb]
        = lot_reczny_parametry($S, $j, $r, $naOrbicie);
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
    // Etap 4 (MECHANIKA 13.4): klucze w locie i kontakty w układzie względem okrętu (okręt w środku, km, oś y na północ).
    $tu = $r['planeta_id'] === null && $r['uklad_kx'] !== null ? [(float) $r['uklad_kx'], (float) $r['uklad_ky']]
        : (function_exists('klucze_obiekt_km') ? klucze_obiekt_km($obiektId, $teraz) : null);
    // Błąd albo nieliczbowa pozycja w kluczach / kontaktach nie może położyć mostka (pusta odpowiedź JSON): mostek
    // działa dalej bez nich, a przyczyna idzie do widoku (blad_swiata).
    if ($tu && lot_reczny_skonczone($tu)) {
        foreach (['lot_reczny_klucze_do_widoku', 'lot_reczny_kontakty'] as $f) {
            try {
                $nowy = $f($widok, $obiektId, $tu, $teraz);
                if (json_encode($nowy, JSON_UNESCAPED_UNICODE) === false) throw new RuntimeException(json_last_error_msg());
                $widok = $nowy;
            } catch (Throwable $e) { $widok['blad_swiata'][] = $f . ': ' . $e->getMessage(); }
        }
    }
    return $widok;
}

// Ustawienia mostka zapisane przy okręcie (okrety_nastawy) w stanie silnika: nastawy pulpitów, Hz generatorów osłon
// (g<n>_hz, g<n>_auto, g<n>_min, g<n>_max — n od 1), zasięg sensorów (sens_km). Mostek na co dzień i bitwa ze starcia
// startują z tym, co załoga ustawiła.
function okret_ustawienia_do_stanu(array &$S, int $ji, int $obiektId): void
{
    $n = okret_nastawy($obiektId);
    if (!$n) return;
    $j = &$S['jednostki'][$ji];
    $j['nastawy'] = array_filter($n, static fn($k) => isset(BITWA_NASTAWY[$k]) && !str_starts_with($k, 'zapor_do_') && $k !== 'blokada_od', ARRAY_FILTER_USE_KEY)
        + ($j['nastawy'] ?? []);
    foreach ($j['generatory'] as $gi => &$g) {
        $p = 'g' . ($gi + 1) . '_';
        $zyly = (int) $g['zyly']['n'];
        $ok = static fn($hz) => $hz >= 1 && $zyly % (int) $hz === 0;
        if (isset($n[$p . 'hz']) && $ok($n[$p . 'hz'])) { $g['hz_zadane'] = (int) $n[$p . 'hz']; $g['hz_osiagane'] = (int) $n[$p . 'hz']; }
        if (isset($n[$p . 'auto'])) $g['auto'] = $n[$p . 'auto'] >= 0.5;
        if (isset($n[$p . 'min'], $n[$p . 'max']) && $ok($n[$p . 'min']) && $ok($n[$p . 'max']) && $n[$p . 'min'] <= $n[$p . 'max']) {
            $g['hz_min'] = (int) $n[$p . 'min']; $g['hz_max'] = (int) $n[$p . 'max'];
        }
    }
    unset($g);
    if (isset($n['sens_km']) && in_array((int) $n['sens_km'], BITWA_ZASIEGI_SENSOROW, true)) $j['zasieg_sensorow'] = (int) $n['sens_km'];
    unset($j);
}

// Frakcja jednostki z listy celów (klucze_cele: id, wlasciciel) — kolumna frakcja albo opis właściciela (starcie_frakcja).
function lot_reczny_frakcja(array $c, ?string $frakcja = null): ?string
{
    if ($frakcja === null && !empty($c['id']) && (int) $c['id'] > 0) {
        try {
            $q = polaczenie_bazy()->prepare('SELECT frakcja FROM mapa_obiekty WHERE id=?');
            $q->execute([(int) $c['id']]);
            $frakcja = $q->fetchColumn() ?: null;
        } catch (Throwable $e) { $frakcja = null; }
    }
    return starcie_frakcja(['frakcja' => $frakcja, 'wlasciciel' => $c['wlasciciel'] ?? '']);
}

function lot_reczny_skonczone(?array $p): bool
{
    return $p !== null && isset($p[0], $p[1]) && is_finite((float) $p[0]) && is_finite((float) $p[1]);
}

// Kurs nawigacyjny (od północy, zgodnie z zegarem) i prędkość z dwóch pozycji w układzie.
function lot_reczny_wektor(?array $a, ?array $b, float $dt): array
{
    if (!lot_reczny_skonczone($a) || !lot_reczny_skonczone($b)) return [0.0, 0.0];
    $dx = $b[0] - $a[0]; $dy = $b[1] - $a[1];
    $d = hypot($dx, $dy);
    return [$d > 1e-6 ? round(fmod(rad2deg(atan2($dx, $dy)) + 360, 360), 2) : 0.0, round($d / max(1e-6, $dt), 3)];
}

// Klucze myśliwców okrętu w świecie (mapa_klucze, api/klucze.php) na pulpitach: klucz N mostka = klucz N okrętu
// (rodzaj TIE). W locie — pozycja z trasy klucza względem okrętu, kurs i prędkość z ruchu; przed startem — odliczanie.
function lot_reczny_klucze_do_widoku(array $widok, int $obiektId, array $tu, int $teraz): array
{
    if (!function_exists('klucze_dostepne') || !klucze_dostepne() || empty($widok['klucze'])) return $widok;
    $wiersze = [];
    foreach (klucze_wiersze($obiektId) as $k) if ($k['rodzaj'] === 'tie') $wiersze[(int) $k['numer']] = $k;
    foreach ($widok['klucze'] as &$kl) {
        $k = $wiersze[(int) $kl['nr']] ?? null;
        if (!$k || $kl['z_zewnatrz']) continue;
        $start = czas_z_utc((string) $k['start_utc']);
        $kl['mapa_id'] = (int) $k['id'];
        $kl['maszyny'] = (int) $k['maszyny'];
        $kl['rozkaz'] = $k['rozkaz'];
        $kl['rozkaz_nazwa'] = KLUCZ_ROZKAZY[$k['rozkaz']][0] ?? $k['rozkaz'];
        $kl['cel'] = $k['cel'] ?? null;
        if ($start > $teraz) { $kl['stan'] = 'hangar'; $kl['start_za_s'] = $start - $teraz; continue; }
        $p = klucze_km($k, $teraz);
        if (!lot_reczny_skonczone($p)) continue;
        [$kurs, $v] = lot_reczny_wektor(klucze_km($k, $teraz - 2), $p, 2.0);
        $kl['stan'] = $k['rozkaz'] === 'powrot' ? 'powrot' : 'w_locie';
        $kl['start_za_s'] = null;
        $kl['x'] = round($p[0] - $tu[0], 3);
        $kl['y'] = round($p[1] - $tu[1], 3);
        $kl['kurs'] = $kurs;
        $kl['v'] = $v;
    }
    unset($kl);
    return $widok;
}

// Kontakty sensorów i radaru: inne jednostki w układzie (bez tych w hangarach i w nadprzestrzeni, z ruchem NPC) do
// trzykrotności największego zasięgu sensorów, najbliższe 40. Strona = frakcja (bez frakcji — „nieznana”, obca dla
// każdego); profil klasy, jeśli jest w danych mechaniki (sylwetka i długość na ekranach).
function lot_reczny_kontakty(array $widok, int $obiektId, array $tu, int $teraz): array
{
    if (!function_exists('klucze_cele')) return $widok;
    $sl = bitwa_slowniki();
    $maks = 3 * max(BITWA_ZASIEGI_SENSOROW);
    $cele = [];
    foreach (klucze_cele($obiektId) as $c) {
        $p = klucze_obiekt_km((int) $c['id'], $teraz);
        if (!lot_reczny_skonczone($p)) continue;
        $d = hypot($p[0] - $tu[0], $p[1] - $tu[1]);
        if ($d <= $maks) $cele[] = $c + ['p' => $p, 'd' => $d];
    }
    usort($cele, static fn($a, $b) => $a['d'] <=> $b['d']);
    $cele = array_slice($cele, 0, 40);
    $dane = [];
    $ids = array_values(array_filter(array_map(static fn($c) => (int) $c['id'], $cele), static fn($i) => $i > 0));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $q = polaczenie_bazy()->prepare("SELECT id, frakcja, profil_klasy FROM mapa_obiekty WHERE id IN ($in)");
            $q->execute($ids);
        } catch (Throwable $e) {
            $q = polaczenie_bazy()->prepare("SELECT id, NULL AS frakcja, profil_klasy FROM mapa_obiekty WHERE id IN ($in)");
            $q->execute($ids);
        }
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $dane[(int) $r['id']] = $r;
    }
    foreach ($cele as $c) {
        $id = (int) $c['id'];
        $r = $dane[$id] ?? [];
        $profil = isset($r['profil_klasy'], $sl['profile'][$r['profil_klasy']]) ? $r['profil_klasy'] : null;
        [$kurs, $v] = lot_reczny_wektor(klucze_obiekt_km($id, $teraz - 2), $c['p'], 2.0);
        $nr = $id > 0 ? 1000000 + $id : 2000000 - $id;
        $widok['jednostki'][] = ['id' => $nr, 'nr_jednostki' => $nr, 'obiekt_id' => $id > 0 ? $id : null, 'kontakt' => true,
            'nazwa' => mb_convert_encoding((string) $c['nazwa'], 'UTF-8', 'UTF-8'), 'strona' => ($id > 0 ? lot_reczny_frakcja($c, $r['frakcja'] ?? null) : null) ?: 'nieznana', 'profil' => $profil ?? 'nieznany',
            'x' => round($c['p'][0] - $tu[0], 3), 'y' => round($c['p'][1] - $tu[1], 3), 'kurs' => $kurs, 'v' => $v,
            'dlugosc_km' => $profil ? $sl['profile'][$profil]['dlugosc_m'] / 1000 : 0.1, 'stan' => 'w_bitwie', 'ai' => true,
            'nastawy' => [], 'generatory' => [], 'sekcje' => [], 'kadlub' => null, 'wzmocnienia' => [], 'cel_ognia' => null];
    }
    return $widok;
}

// Rozkaz z konsoli mostka bez symulacji: ster (kurs nawigacyjny, prędkość km/s), nastawy, wyrównania, wzmocnienia.
// Osłony, sensory i klucze myśliwców — na stanie ze świata (etap 4); ogień i figury kluczy tylko w bitwie.
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
    // Osłony na co dzień: Hz generatora (stopień natywny), tryb automatyczny, zakres automatu — od razu, bez czasu
    // przestrajania (to liczy dopiero bitwa). Generator: id z widoku = numer od 1.
    if (isset($w['generator_id'])) {
        $sl = bitwa_slowniki();
        $p = $sl['profile'][$o['profil_klasy']] ?? null;
        $gn = (int) $w['generator_id'];
        if (!$p || $gn < 1 || $gn > (int) $p['generatory']) return ['odmowa' => 'Nie ma takiego generatora.'];
        $zyly = (int) $p['zyly'];
        $pre = "g{$gn}_";
        if ($kod === 'auto') { okret_nastawa_ustaw($obiektId, $pre . 'auto', 1.0); return ['ok' => true, 'dziala_za_s' => 0]; }
        if ($kod === 'zakres') {
            $min = (int) ($w['hz_min'] ?? 1);
            $max = (int) ($w['hz_max'] ?? $zyly);
            if ($min < 1 || $max < $min || $zyly % $min !== 0 || $zyly % $max !== 0) return ['odmowa' => 'Zakres musi być parą stopni natywnych, MIN ≤ MAX.'];
            okret_nastawa_ustaw($obiektId, $pre . 'min', (float) $min);
            okret_nastawa_ustaw($obiektId, $pre . 'max', (float) $max);
            return ['ok' => true, 'dziala_za_s' => 0];
        }
        $hz = (int) ($w['hz'] ?? 0);
        if ($hz < 1 || $zyly % $hz !== 0) return ['odmowa' => 'To nie jest stopień natywny generatora.'];
        okret_nastawa_ustaw($obiektId, $pre . 'hz', (float) $hz);
        okret_nastawa_ustaw($obiektId, $pre . 'auto', 0.0);
        return ['ok' => true, 'dziala_za_s' => 0];
    }
    if ($kod === 'sensory_zasieg') {
        $km = (int) ($w['zasieg_km'] ?? 0);
        if (!in_array($km, BITWA_ZASIEGI_SENSOROW, true)) return ['odmowa' => 'Nieznany poziom zasięgu sensorów.'];
        okret_nastawa_ustaw($obiektId, 'sens_km', (float) $km);
        return ['ok' => true, 'dziala_za_s' => 0];
    }
    // Myśliwce na co dzień: klucz N pulpitu = klucz N okrętu w świecie (api/klucze.php). Start z hangaru (taśmociąg),
    // rozkazy patrol / ochrona / atak / skan / powrót; atak i skan na cel wskazany na pulpicie albo najbliższy obcy.
    if (isset($w['klucz_id'])) {
        if (!function_exists('klucze_dostepne') || !klucze_dostepne()) return ['odmowa' => 'Klucze myśliwców wymagają migracji 066.'];
        $nr = (int) $w['klucz_id'];
        $q = $db->prepare("SELECT id, start_utc FROM mapa_klucze WHERE okret_id=? AND rodzaj='tie' AND numer=?");
        $q->execute([$obiektId, $nr]);
        $k = $q->fetch(PDO::FETCH_ASSOC);
        if ($kod === 'start') {
            if ($k) return ['odmowa' => "Klucz {$nr} jest już w locie."];
            if ($nr < 1 || $nr > klucze_hangar($obiektId)['tie']['razem']) return ['odmowa' => 'Okręt nie ma takiego klucza w hangarze.'];
            $wynik = klucze_start($u, $obiektId, 'tie', 'automat', true, $nr);
            return ['ok' => true, 'dziala_za_s' => max(0, (int) $wynik['start'] - $teraz), 'nazwa' => $wynik['nazwa']];
        }
        if (!$k) return ['odmowa' => "Klucz {$nr} jest w hangarze — najpierw START."];
        $mapa = ['patrol' => 'patrol', 'ochrona' => 'ochrona', 'atak' => 'atak', 'skan' => 'skan', 'powrot' => 'powrot', 'przechwyc' => 'przechwyc'];
        if (!isset($mapa[$kod])) return ['odmowa' => 'Figury i manewry klucza działają tylko w bitwie — poza nią: patrol, ochrona, atak, skan, powrót.'];
        $cel = null;
        if (in_array($kod, ['atak', 'skan', 'przechwyc'], true)) {
            $cel = (int) ($w['cel_obiekt_id'] ?? 0) ?: null;
            if (!$cel) {
                $tu = klucze_obiekt_km($obiektId, $teraz);
                $moja = starcie_frakcja($o);
                $najblizej = null;
                foreach (klucze_cele($obiektId) as $c) {
                    if ($kod !== 'skan' && empty($c['npc']) && $moja !== null && lot_reczny_frakcja($c) === $moja) continue;
                    $p = klucze_obiekt_km((int) $c['id'], $teraz);
                    if (!$p || !$tu) continue;
                    $d = hypot($p[0] - $tu[0], $p[1] - $tu[1]);
                    if ($najblizej === null || $d < $najblizej[1]) $najblizej = [(int) $c['id'], $d];
                }
                $cel = $najblizej[0] ?? null;
            }
            if (!$cel) return ['odmowa' => 'W układzie nie ma celu dla tego rozkazu.'];
        }
        $wynik = klucze_rozkaz_wydaj($u, [(int) $k['id']], $mapa[$kod], $cel, true);
        return ['ok' => true, 'dziala_za_s' => 0, 'dolot_s' => $wynik['dolot']];
    }
    return ['odmowa' => 'Ten rozkaz działa tylko w bitwie — poza nią pulpity ustawiają napęd, osłony, sensory, nastawy, wzmocnienia i klucze myśliwców.'];
}

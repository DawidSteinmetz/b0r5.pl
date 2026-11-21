<?php
// GOSPODARKA (migracja 048): towary, produkcja planet, obiekty tła (huty, fabryki, porty, składy) i linie dostaw.
//
// Linia dostaw łączy producenta (planetę) z obiektem i ma ładunek jednego transportu oraz odstęp między
// transportami (ładowność / zużycie obiektu na dobę). Statków linii nie ma w bazie: serwer liczy z czasu, ilu
// jest w drodze (cykl / odstęp) i w jakiej fazie jest każdy — załadunek u producenta, start, lot do punktu skoku,
// skok, dolot, lądowanie przy obiekcie (powierzchnia) albo przelot do jego doku na pierścieniu, rozładunek
// i powrót tą samą drogą. Na radarze pokazują się te, które są teraz przy planetach w zasięgu.

declare(strict_types=1);

function gosp_dostepna(): bool
{
    static $jest = null;
    if ($jest === null) $jest = tabela_sql_dostepna('gosp_linie') && tabela_sql_dostepna('gosp_obiekty') && tabela_sql_dostepna('gosp_towary');
    return $jest;
}

// Planety, których ruch w tle pochodzi z gospodarki (mają obiekty tła) — stare trasy NPC ich nie obsługują.
function gosp_planety(): array
{
    static $w = null;
    if ($w !== null) return $w;
    $w = [];
    if (gosp_dostepna()) foreach (polaczenie_bazy()->query('SELECT DISTINCT planeta FROM gosp_obiekty')->fetchAll(PDO::FETCH_COLUMN) as $p) $w[mb_strtolower($p)] = true;
    return $w;
}

// Statki linii dostaw przy planetach z $wZasiegu (nazwa => true). Format jak radar_tlo (kontakty radaru).
function gosp_statki(array $wZasiegu, array $ident, array $trwa, bool $analityk, int $limit): array
{
    if (!gosp_dostepna() || $limit <= 0) return [];
    $db = polaczenie_bazy();
    $nazwy = array_keys($wZasiegu);
    if (!$nazwy) return [];
    $ph = implode(',', array_fill(0, count($nazwy), '?'));
    $q = $db->prepare("SELECT l.*, o.planeta, o.nazwa AS obiekt, o.miejsce, o.szer, o.dl, o.kat_deg, t.nazwa AS towar_nazwa,
            pa.x AS ax, pa.y AS ay, pb.x AS bx, pb.y AS by_
        FROM gosp_linie l JOIN gosp_obiekty o ON o.id = l.obiekt_id JOIN gosp_towary t ON t.kod = l.towar
        JOIN mapa_planety pa ON pa.nazwa = l.producent JOIN mapa_planety pb ON pb.nazwa = o.planeta
        WHERE o.planeta IN ($ph) OR l.producent IN ($ph)");
    $q->execute([...$nazwy, ...$nazwy]);
    $teraz = czas_teraz();
    $wynik = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $plan = gosp_plan($l);
        $ile = max(1, (int) ceil($plan['cykl'] / max(60, (int) $l['odstep_s'])));
        for ($k = 0; $k < $ile && count($wynik) < $limit; $k++) {
            $tc = fmod($teraz + (float) $l['faza'] * $plan['cykl'] + $k * (int) $l['odstep_s'], $plan['cykl']);
            // Szybki odsiew: tylko statki przy planecie w zasięgu i poza nadprzestrzenią.
            $e = gosp_etap($plan, $tc);
            if (!$e || $e['etap'][1] === 'skok' || !isset($wZasiegu[$e['etap'][2]['nazwa']])) continue;
            $f = gosp_faza($l, $plan, $e, $teraz, $k);
            $id = -(10000000 + (int) $l['id'] * 1000 + $k);
            $idn = $ident[$id] ?? null;
            $nazwa = $idn ? $idn['nazwa'] : $f['nazwa'];
            $o = ['id' => $id, 'typ' => 'jednostka', 'nazwa' => $nazwa, 'klasa' => $l['klasa'], 'planeta' => $f['planeta'],
                'x' => $f['x'], 'y' => $f['y'], 'znacznik' => 'trojkat', 'strona' => 'neutralny', 'frakcja' => null, 'kurs' => $f['kurs'],
                'radar' => ['transponder' => $nazwa, 'frakcja' => 'brak', 'udostepniony' => false, 'zidentyfikowany' => (bool) $idn, 'poszukiwany' => null,
                    'analiza_do' => isset($trwa[$id]) ? czas_z_utc((string) $trwa[$id]) : null, 'mozna_analizowac' => $analityk && !$idn && !isset($trwa[$id]),
                    'trasa' => "{$l['producent']} → {$l['obiekt']}", 'ladunek' => $f['ladunek'], 'faza' => $f['opis'], 'faza_do' => $f['do']]];
            foreach (['podswietlny' => 'lot', 'uklad_k' => 'k', 'atmosfera' => 'atmosfera', 'przelot' => 'przelot', 'dok' => 'dok', 'geo' => 'geo'] as $pole => $zr) {
                if ($f[$zr] !== null) $o[$pole] = $f[$zr];
            }
            if ($f['ladowisko'] !== null) $o['na_ladowisku'] = $f['ladowisko'];
            if ($f['planeta'] !== null) $o['podejscie'] = 'bliska';
            $wynik[] = $o;
        }
    }
    return $wynik;
}

// Obiekty tła do narysowania na mapie (planeta, nazwa, rodzaj, miejsce, geo albo kąt na pierścieniu).
function gosp_obiekty_mapy(): array
{
    if (!gosp_dostepna()) return [];
    return array_map(fn($o) => ['planeta' => $o['planeta'], 'nazwa' => $o['nazwa'], 'rodzaj' => $o['rodzaj'], 'miejsce' => $o['miejsce'],
        'szer' => $o['szer'] !== null ? (float) $o['szer'] : null, 'dl' => $o['dl'] !== null ? (float) $o['dl'] : null,
        'kat' => $o['kat_deg'] !== null ? (float) $o['kat_deg'] : null],
        polaczenie_bazy()->query('SELECT planeta, nazwa, rodzaj, miejsce, szer, dl, kat_deg FROM gosp_obiekty ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
}

// Plan cyklu linii: etapy [długość s, rodzaj, port, strona, kierunek] i ich suma.
function gosp_plan(array $l): array
{
    static $cache = [];
    if (isset($cache[$l['id']])) return $cache[$l['id']];
    $h = crc32('gosp|' . $l['id']);
    $a = ['nazwa' => $l['producent'], 'x' => (float) $l['ax'], 'y' => (float) $l['ay']];
    $b = ['nazwa' => $l['planeta'], 'x' => (float) $l['bx'], 'y' => (float) $l['by_']];
    $pc = max(1.0, hypot($b['x'] - $a['x'], $b['y'] - $a['y']));
    $v = max(0.5, (float) $l['predkosc_au_h']) * 2.5;
    $dj = 1.5 + ($h % 150) / 100;
    $lot = (int) round($dj / $v * 3600);
    $skok = (int) round(min(40, 3 + $pc / 150) * 3600);
    $zaladunek = (int) round(max(1.0, (float) $l['postoj_h']) * 3600);
    $rozladunek = (int) round($zaladunek * 0.7);
    $pierscien = $l['miejsce'] === 'pierscien';
    $przylotB = $pierscien ? 1200 + $h % 1200 : ATM_WEJSCIE_S + 600 + $h % 1500;   // przelot do doku / zejście i lot w atmosferze
    $odlotB = $pierscien ? 900 + $h % 900 : START_WZNOSZENIE_S;
    $etapy = [[$zaladunek, 'postoj', $a, 'A', 1], [START_WZNOSZENIE_S, 'start', $a, 'A', 1], [$lot, 'wylot', $a, 'A', 1], [$skok, 'skok', $b, '', 1],
              [$lot, 'dolot', $b, 'B', 1], [$przylotB, 'przylot', $b, 'B', 1], [$rozladunek, 'postoj', $b, 'B', 1], [$odlotB, 'start', $b, 'B', -1],
              [$lot, 'wylot', $b, 'B', -1], [$skok, 'skok', $a, '', -1], [$lot, 'dolot', $a, 'A', -1], [ATM_WEJSCIE_S + 900 + $h % 1200, 'przylot', $a, 'A', -1]];
    return $cache[$l['id']] = ['etapy' => $etapy, 'cykl' => array_sum(array_column($etapy, 0)), 'a' => $a, 'b' => $b,
        'u' => [($b['x'] - $a['x']) / $pc, ($b['y'] - $a['y']) / $pc], 'dj' => $dj, 'h' => $h, 'pierscien' => $pierscien];
}

function gosp_etap(array $plan, float $tc): ?array
{
    foreach ($plan['etapy'] as $e) {
        if ($tc < $e[0]) return ['etap' => $e, 'w' => $tc];
        $tc -= $e[0];
    }
    return null;
}

// Szczegóły fazy statku: pozycja w formacie mapy (lot podświetlny, atmosfera, przelot, dok, lądowisko).
function gosp_faza(array $l, array $plan, array $e, int $teraz, int $k): array
{
    [$dlugosc, $rodzaj, $port, $strona, $kier] = $e['etap'];
    $h = $plan['h'];
    $od = $teraz - (int) $e['w'];
    $mx = $plan['u'][0] * $kier; $my = $plan['u'][1] * $kier;
    $hs = crc32("gosp|{$l['id']}|{$k}");                                   // stałe oznaczenie statku linii
    $kod = strtoupper(base_convert((string) ($hs % 46656), 10, 36)) . '-' . ($hs % 90 + 10);
    $typ = mb_convert_case(explode(' ', (string) $l['klasa'])[0], MB_CASE_TITLE);
    $f = ['nazwa' => "{$typ} {$kod}", 'planeta' => null, 'x' => $port['x'], 'y' => $port['y'], 'kurs' => fmod(rad2deg(atan2($my, $mx)) + 360, 360),
        'lot' => null, 'k' => null, 'atmosfera' => null, 'przelot' => null, 'dok' => null, 'geo' => null, 'ladowisko' => null, 'do' => $od + $dlugosc,
        'ladunek' => $kier > 0 ? $l['towar_nazwa'] . ' · ' . number_format((float) $l['ladownosc_t'], 0, ',', ' ') . ' t' : 'pusty (powrót po ładunek)'];
    $cykl = astro_cykl($port['nazwa']);
    $rp = (float) $cykl['srednica_km'] / 2;
    // Miejsca: u producenta — lądowisko kopalni/zakładu (stałe dla linii), przy obiekcie — jego lądowisko albo dok.
    $uProd = ['Zakład ' . $l['towar_nazwa'] . ' — ' . $l['producent'], rad2deg(asin(((($h >> 3) % 20001) / 10000 - 1) * 0.9)), (($h >> 9) % 36000) / 100 - 180];
    $obiektGeo = [$l['obiekt'], (float) $l['szer'], (float) $l['dl']];
    $ring = null;
    if ($strona === 'B' && $plan['pierscien'] && function_exists('doki_pierscienie')) {
        foreach (doki_pierscienie() as $p) if (mb_strtolower($p['planeta']) === mb_strtolower($port['nazwa'])) $ring = $p;
    }
    $lad = $strona === 'A' ? $uProd : $obiektGeo;
    if ($rodzaj === 'postoj') {
        $f['planeta'] = $port['nazwa'];
        if ($ring) {
            $f['dok'] = ['oznaczenie' => $l['obiekt'], 'kat' => (float) $l['kat_deg'], 'wysokosc_km' => $ring['wysokosc_km'], 'od' => null, 'do' => null, 'kat0' => null, 'r0' => null];
            $f['opis'] = ($kier > 0 ? 'rozładunek w doku: ' : 'załadunek: ') . $l['obiekt'];
        } else {
            $f['ladowisko'] = $lad[0];
            $f['geo'] = ['szer' => round($lad[1], 4), 'dl' => round($lad[2], 4)];
            $f['opis'] = ($strona === 'A' ? 'załadunek: ' : 'rozładunek: ') . $lad[0];
        }
        return $f;
    }
    if ($rodzaj === 'przylot' || $rodzaj === 'start') {
        $f['planeta'] = $port['nazwa'];
        if ($ring) {
            // Przelot między orbitą (od strony punktu skoku) a dokiem obiektu na pierścieniu.
            $katSkoku = fmod(rad2deg(atan2($rodzaj === 'przylot' ? -$my : $my, $rodzaj === 'przylot' ? -$mx : $mx)) + 360, 360);
            [$k0, $r0, $k1, $r1] = $rodzaj === 'przylot' ? [$katSkoku, $rp * 4, (float) $l['kat_deg'], $ring['promien_km']] : [(float) $l['kat_deg'], $ring['promien_km'], $katSkoku, $rp * 4];
            $f['przelot'] = ['cel' => 'dok', 'od' => $od, 'do' => $od + $dlugosc, 'kat0' => $k0, 'r0' => $r0, 'kat1' => $k1, 'r1' => $r1];
            $f['opis'] = $rodzaj === 'przylot' ? 'podejście do doku: ' . $l['obiekt'] : 'odlot z doku: ' . $l['obiekt'];
            return $f;
        }
        if ($rodzaj === 'start') {
            $f['atmosfera'] = ['rodzaj' => 'start', 'od' => $od, 'do' => $od + $dlugosc, 'szer0' => $lad[1], 'dl0' => $lad[2], 'szer1' => $lad[1], 'dl1' => $lad[2],
                'faza_s' => $dlugosc, 'orbita' => $f['kurs']];
            $f['opis'] = 'start z: ' . $lad[0];
        } else {
            $odsun = 4 + $h % 11;
            $f['atmosfera'] = ['rodzaj' => 'ladowanie', 'od' => $od, 'do' => $od + $dlugosc, 'szer0' => $lad[1] * 0.85, 'dl0' => $lad[2] - $odsun,
                'szer1' => $lad[1], 'dl1' => $lad[2], 'faza_s' => ATM_WEJSCIE_S, 'orbita' => null];
            $f['opis'] = 'wejście w atmosferę, lądowanie: ' . $lad[0];
        }
        return $f;
    }
    $pk = astro_pozycja_km($cykl, $teraz);
    $djKm = $plan['dj'] * ASTRO_KM_NA_AU;
    if ($rodzaj === 'wylot') { $k0 = $pk; $k1 = [$pk[0] + $mx * $djKm, $pk[1] + $my * $djKm]; $f['opis'] = 'lot do punktu skoku'; }
    else { $k0 = [$pk[0] - $mx * $djKm, $pk[1] - $my * $djKm]; $k1 = $pk; $f['opis'] = 'dolot z punktu skoku do ' . $port['nazwa']; }
    $f['lot'] = ['od' => $od, 'do' => $od + $dlugosc, 'x0' => $port['x'], 'y0' => $port['y'], 'x1' => $port['x'], 'y1' => $port['y'],
        'planeta' => null, 'km' => $djKm, 'k0' => $k0, 'k1' => $k1];
    $f['k'] = $k1;
    return $f;
}

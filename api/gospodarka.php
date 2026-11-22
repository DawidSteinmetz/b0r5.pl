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
    static $lad = null;
    if ($lad === null) { try { polaczenie_bazy()->query('SELECT lokalizacja_id FROM gosp_obiekty LIMIT 0'); $lad = true; } catch (Throwable $e) { $lad = false; } }
    return array_map(fn($o) => ['id' => (int) $o['id'], 'planeta' => $o['planeta'], 'nazwa' => $o['nazwa'], 'rodzaj' => $o['rodzaj'], 'miejsce' => $o['miejsce'],
        'ladowisko_id' => isset($o['lokalizacja_id']) && $o['lokalizacja_id'] !== null ? (int) $o['lokalizacja_id'] : null,
        'szer' => $o['szer'] !== null ? (float) $o['szer'] : null, 'dl' => $o['dl'] !== null ? (float) $o['dl'] : null,
        'kat' => $o['kat_deg'] !== null ? (float) $o['kat_deg'] : null],
        polaczenie_bazy()->query('SELECT id, planeta, nazwa, rodzaj, miejsce, szer, dl, kat_deg' . ($lad ? ', lokalizacja_id' : '') . ' FROM gosp_obiekty ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
}

// Karta obiektu tła: opis, zużycie na dobę i linie dostaw z czasem najbliższej dostawy (z cyklu statków linii).
function gosp_obiekt_karta(int $id): array
{
    if (!gosp_dostepna()) blad('Zaimportuj api/migracje/048_gospodarka_kuat.sql.');
    $db = polaczenie_bazy();
    $q = $db->prepare('SELECT * FROM gosp_obiekty WHERE id = ?');
    $q->execute([$id]);
    $o = $q->fetch(PDO::FETCH_ASSOC) ?: blad('Nie ma takiego obiektu.');
    $q = $db->prepare('SELECT z.towar, z.t_doba, t.nazwa FROM gosp_zuzycie z JOIN gosp_towary t ON t.kod = z.towar WHERE z.obiekt_id = ? ORDER BY z.t_doba DESC');
    $q->execute([$id]);
    $zuzycie = array_map(fn($r) => ['towar' => $r['nazwa'], 't_doba' => (float) $r['t_doba']], $q->fetchAll(PDO::FETCH_ASSOC));
    $q = $db->prepare("SELECT l.*, o.planeta, o.nazwa AS obiekt, o.miejsce, o.szer, o.dl, o.kat_deg, t.nazwa AS towar_nazwa,
            pa.x AS ax, pa.y AS ay, pb.x AS bx, pb.y AS by_
        FROM gosp_linie l JOIN gosp_obiekty o ON o.id = l.obiekt_id JOIN gosp_towary t ON t.kod = l.towar
        JOIN mapa_planety pa ON pa.nazwa = l.producent JOIN mapa_planety pb ON pb.nazwa = o.planeta WHERE l.obiekt_id = ? ORDER BY l.t_doba DESC");
    $q->execute([$id]);
    $teraz = czas_teraz();
    $linie = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $plan = gosp_plan($l);
        // Najbliższy przylot: początek etapu „przylot” po stronie obiektu (B) dla któregokolwiek statku linii.
        $doPrzylotu = 0;
        foreach ($plan['etapy'] as $e) { if ($e[1] === 'postoj' && $e[3] === 'B') break; $doPrzylotu += $e[0]; }
        $ile = max(1, (int) ceil($plan['cykl'] / max(60, (int) $l['odstep_s'])));
        $najblizej = null;
        for ($k = 0; $k < $ile; $k++) {
            $tc = fmod($teraz + (float) $l['faza'] * $plan['cykl'] + $k * (int) $l['odstep_s'], $plan['cykl']);
            $za = fmod($doPrzylotu - $tc + 2 * $plan['cykl'], $plan['cykl']);
            if ($najblizej === null || $za < $najblizej) $najblizej = $za;
        }
        $linie[] = ['towar' => $l['towar_nazwa'], 'producent' => $l['producent'] . ($plan['lokalna'] ? ' (dostawa lokalna)' : ''), 'ladownosc_t' => (int) $l['ladownosc_t'], 't_doba' => (float) $l['t_doba'],
            'odstep_s' => (int) $l['odstep_s'], 'w_drodze' => $ile, 'przylot' => $teraz + (int) round((float) $najblizej), 'klasa' => $l['klasa']];
    }
    return ['obiekt' => ['id' => (int) $o['id'], 'planeta' => $o['planeta'], 'nazwa' => $o['nazwa'], 'rodzaj' => $o['rodzaj'], 'miejsce' => $o['miejsce'],
        'opis' => $o['opis'], 'szer' => $o['szer'] !== null ? (float) $o['szer'] : null, 'dl' => $o['dl'] !== null ? (float) $o['dl'] : null,
        'kat' => $o['kat_deg'] !== null ? (float) $o['kat_deg'] : null], 'zuzycie' => $zuzycie, 'linie' => $linie];
}

// Minibaza gospodarki dla promptu kolejnej planety (MG): planety (bez danych prywatnych), cykle i typy planet,
// wszystkie tabele gosp_*, a dla planet z ?planety= (po przecinku) — ich lokacje. Tekst SQL do pobrania.
function gosp_minibaza(string $planety): string
{
    $db = polaczenie_bazy();
    $q = fn($v) => $v === null ? 'NULL' : (is_numeric($v) && !preg_match('/^0\d/', (string) $v) ? (string) $v : $db->quote((string) $v));
    $blok = function (string $tabela, string $sql, array $par = []) use ($db, $q): string {
        try { $s = $db->prepare($sql); $s->execute($par); $r = $s->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return "-- {$tabela}: brak ({$e->getMessage()})\n"; }
        if (!$r) return "-- {$tabela}: brak wierszy\n";
        $kol = array_keys($r[0]);
        $w = "-- {$tabela}: " . count($r) . " wierszy\n";
        foreach (array_chunk($r, 300) as $paczka) {
            $w .= "INSERT INTO {$tabela} (" . implode(',', $kol) . ") VALUES\n"
                . implode(",\n", array_map(fn($x) => '(' . implode(',', array_map($q, array_values($x))) . ')', $paczka)) . ";\n";
        }
        return $w . "\n";
    };
    $lista = array_values(array_filter(array_map('trim', explode(',', $planety))));
    $w = "-- MINIBAZA GOSPODARKI HOLOLINK — " . gmdate('Y-m-d H:i') . " UTC\n-- Dane referencyjne dla promptu planety (bez kont i postaci). Planety szczegółowe: " . ($lista ? implode(', ', $lista) : '—') . "\n\n";
    foreach (['gosp_towary', 'gosp_produkcja', 'gosp_obiekty', 'gosp_zuzycie', 'gosp_linie', 'gosp_rynek'] as $t) {
        try { $w .= $db->query("SHOW CREATE TABLE {$t}")->fetch(PDO::FETCH_NUM)[1] . ";\n\n"; } catch (Throwable $e) { }
    }
    $w .= $blok('mapa_planety', 'SELECT id, nazwa, region, x, y, srednica, doba, rok, ksiezyce FROM mapa_planety ORDER BY id');
    $w .= $blok('planety_cykle', 'SELECT c.*, p.nazwa AS planeta FROM planety_cykle c JOIN mapa_planety p ON p.id = c.planeta_id');
    $w .= $blok('planety_gry', 'SELECT g.planeta_id, p.nazwa AS planeta, g.typ_planety, g.urbanizacja FROM planety_gry g JOIN mapa_planety p ON p.id = g.planeta_id WHERE g.typ_planety IS NOT NULL');
    foreach (['gosp_towary', 'gosp_produkcja', 'gosp_obiekty', 'gosp_zuzycie', 'gosp_linie', 'gosp_rynek'] as $t) $w .= $blok($t, "SELECT * FROM {$t}");
    if ($lista) {
        $ph = implode(',', array_fill(0, count($lista), '?'));
        $w .= $blok('lokalizacje', "SELECT l.id, l.typ, l.rodzaj, l.rodzic_id, l.planeta_id, p.nazwa AS planeta, l.nazwa, l.x_km, l.y_km, l.publiczna, l.status, LEFT(l.opis, 300) AS opis
            FROM lokalizacje l JOIN mapa_planety p ON p.id = l.planeta_id WHERE p.nazwa IN ($ph) ORDER BY l.id", $lista);
    }
    return $w;
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
    // Miejsce u producenta (zakład / kopalnia), stałe dla linii: szerokość i długość geograficzna.
    $uProd = ['Zakład ' . $l['towar_nazwa'] . ' — ' . $l['producent'], rad2deg(asin(((($h >> 3) % 20001) / 10000 - 1) * 0.9)), (($h >> 9) % 36000) / 100 - 180];
    if (!$pierscien && mb_strtolower((string) $l['producent']) === mb_strtolower((string) $l['planeta'])) {
        // Dostawa lokalna (producent na tej samej planecie): bez skoku — lot w atmosferze od zakładu do obiektu i z powrotem.
        $cyklP = astro_cykl((string) $l['planeta']);
        $km = function_exists('atm_luk_km') ? atm_luk_km((float) $cyklP['srednica_km'] / 2, $uProd[1], $uProd[2], (float) $l['szer'], (float) $l['dl']) : 2000;
        $atm = 2 * START_WZNOSZENIE_S + (int) round($km / (700 + $h % 500) * 3600);   // wznoszenie, przelot, zejście
        $etapy = [[$zaladunek, 'postoj', $a, 'A', 1], [$atm, 'lokalny', $b, 'B', 1], [$rozladunek, 'postoj', $b, 'B', 1], [$atm, 'lokalny', $a, 'A', -1]];
        return $cache[$l['id']] = ['etapy' => $etapy, 'cykl' => array_sum(array_column($etapy, 0)), 'a' => $a, 'b' => $b,
            'u' => [1.0, 0.0], 'dj' => $dj, 'h' => $h, 'pierscien' => false, 'uProd' => $uProd, 'lokalna' => true];
    }
    $przylotB = $pierscien ? 1200 + $h % 1200 : ATM_WEJSCIE_S + 600 + $h % 1500;   // przelot do doku / zejście i lot w atmosferze
    $odlotB = $pierscien ? 900 + $h % 900 : START_WZNOSZENIE_S;
    $etapy = [[$zaladunek, 'postoj', $a, 'A', 1], [START_WZNOSZENIE_S, 'start', $a, 'A', 1], [$lot, 'wylot', $a, 'A', 1], [$skok, 'skok', $b, '', 1],
              [$lot, 'dolot', $b, 'B', 1], [$przylotB, 'przylot', $b, 'B', 1], [$rozladunek, 'postoj', $b, 'B', 1], [$odlotB, 'start', $b, 'B', -1],
              [$lot, 'wylot', $b, 'B', -1], [$skok, 'skok', $a, '', -1], [$lot, 'dolot', $a, 'A', -1], [ATM_WEJSCIE_S + 900 + $h % 1200, 'przylot', $a, 'A', -1]];
    return $cache[$l['id']] = ['etapy' => $etapy, 'cykl' => array_sum(array_column($etapy, 0)), 'a' => $a, 'b' => $b,
        'u' => [($b['x'] - $a['x']) / $pc, ($b['y'] - $a['y']) / $pc], 'dj' => $dj, 'h' => $h, 'pierscien' => $pierscien, 'uProd' => $uProd, 'lokalna' => false];
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
    $uProd = $plan['uProd'];
    $obiektGeo = [$l['obiekt'], (float) $l['szer'], (float) $l['dl']];
    if ($rodzaj === 'lokalny') {
        // Lot w atmosferze między zakładem producenta a obiektem (ta sama planeta) — po łuku wielkiego koła.
        [$z, $do] = $kier > 0 ? [$uProd, $obiektGeo] : [$obiektGeo, $uProd];
        $f['planeta'] = $port['nazwa'];
        $f['atmosfera'] = ['rodzaj' => 'lokalny', 'od' => $od, 'do' => $od + $dlugosc, 'szer0' => $z[1], 'dl0' => $z[2], 'szer1' => $do[1], 'dl1' => $do[2],
            'faza_s' => 0, 'orbita' => null];
        $f['opis'] = 'lot w atmosferze: ' . $z[0] . ' → ' . $do[0];
        return $f;
    }
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

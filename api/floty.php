<?php
// SQL-owy system dowodzenia flotami i automatycznych skoków jednostek mapy.

declare(strict_types=1);

function floty_gotowe(): void
{
    if (!tabela_sql_dostepna('mapa_floty') || !tabela_sql_dostepna('mapa_floty_jednostki')
        || !tabela_sql_dostepna('mapa_naped_jednostki') || !tabela_sql_dostepna('mapa_rozkazy_skokow')) {
        blad('Najpierw zaimportuj api/migracje/006_floty_i_nawigacja.sql.');
    }
    try { polaczenie_bazy()->query('SELECT kolejnosc FROM mapa_floty_jednostki LIMIT 0'); }
    catch (Throwable $e) { blad('Zaimportuj jeszcze api/migracje/007_kolejnosc_okretow_floty.sql.'); }
    try { polaczenie_bazy()->query('SELECT dowodca_osoba_id FROM mapa_floty LIMIT 0'); }
    catch (Throwable $e) { blad('Zaimportuj jeszcze api/migracje/009_flota_dromund_kaas.sql.'); }
    if (!floty_kurs_dostepny()) blad('Zaimportuj jeszcze api/migracje/015_kurs_manewry_i_cykle_planet.sql.');
    if (!floty_orbita_dostepna()) blad('Zaimportuj jeszcze api/migracje/016_orbita_przylot_rozbicie.sql.');
    if (!floty_nawigacja_dostepna()) blad('Zaimportuj jeszcze api/migracje/017_komputer_nawigacyjny_i_dolot.sql.');
}

// ---------- komputer nawigacyjny, warianty celu i dolot ----------
// Skok na planetę liczy automat (komputer nawigacyjny okrętu flagowego): warianty bliska / średnia /
// daleka orbita albo krawędź układu. Klasa komputera (1–5) zmniejsza rozrzut wyjścia i czas obliczeń.
// Bez sprawnego komputera (przejęty, brak) mostek liczy sam: tylko ręczne współrzędne, 20 min obliczeń,
// większy rozrzut. Komputer zhakowany działa, ale po cichu daje duży rozrzut.
// Dolot: zmiana orbity (krawędź → daleka → średnia → bliska i z powrotem) napędem podświetlnym.
const NAV_WARIANTY = ['bliska', 'srednia', 'daleka', 'krawedz'];
const NAV_RECZNE_OBLICZENIA_S = 1200;
const DOLOT_ETAPY_MIN = [60, 15, 8]; // krawędź↔daleka, daleka↔średnia, średnia↔bliska

function floty_nawigacja_dostepna(): bool
{
    static $jest = null;
    if ($jest === null) {
        try {
            polaczenie_bazy()->query('SELECT podejscie, podlot_do_utc FROM mapa_obiekty LIMIT 0');
            polaczenie_bazy()->query('SELECT komputer_klasa, komputer_stan FROM mapa_naped_jednostki LIMIT 0');
            polaczenie_bazy()->query('SELECT wariant, tryb FROM mapa_rozkazy_skokow LIMIT 0');
            $jest = true;
        } catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// Czas dolotu między wariantami orbity (minuty świata); duże okręty wolniej (tempo obrotu jako miara zwrotności).
function floty_dolot_minuty(string $z, string $do, float $obrot): float
{
    $i = array_search($z, ['krawedz', 'daleka', 'srednia', 'bliska'], true);
    $j = array_search($do, ['krawedz', 'daleka', 'srednia', 'bliska'], true);
    if ($i === false || $j === false || $i === $j) return 0;
    $suma = array_sum(array_slice(DOLOT_ETAPY_MIN, min($i, $j), abs($i - $j)));
    return $suma * max(0.5, min(1.5, 45 / max(1.0, $obrot)));
}

// Gdzie w układzie jest okręt teraz (z trwającym dolotem: etap osiągnięty do tej chwili).
function floty_podejscie_teraz(array $o, int $teraz): ?string
{
    if (!empty($o['podlot_do_utc']) && !empty($o['podlot_cel']) && !empty($o['podlot_start'])) {
        $od = czas_z_utc((string) $o['podlot_od_utc']);
        $do = czas_z_utc((string) $o['podlot_do_utc']);
        if ($teraz >= $do) return $o['podlot_cel'];
        $kolej = ['krawedz', 'daleka', 'srednia', 'bliska'];
        $i = array_search($o['podlot_start'], $kolej, true);
        $j = array_search($o['podlot_cel'], $kolej, true);
        $krok = $j > $i ? 1 : -1;
        $czasy = [];
        for ($k = $i; $k !== $j; $k += $krok) $czasy[] = DOLOT_ETAPY_MIN[min($k, $k + $krok)];
        $calosc = array_sum($czasy) ?: 1;
        $ulamek = ($teraz - $od) / max(1, $do - $od);
        $stan = $o['podlot_start'];
        $suma = 0;
        foreach ($czasy as $n => $c) {
            $suma += $c;
            if ($ulamek * $calosc >= $suma) $stan = $kolej[$i + ($n + 1) * $krok];
        }
        return $stan;
    }
    return $o['podejscie'] ?? null;
}

// Komputer okrętu flagowego wśród wybranych jednostek: [klasa, stan, automat dostępny].
function floty_komputer(array $odbiorcy): array
{
    $marks = implode(',', array_fill(0, count($odbiorcy), '?'));
    $q = polaczenie_bazy()->prepare("SELECT COALESCE(n.komputer_klasa,3) AS klasa, COALESCE(n.komputer_stan,'sprawny') AS stan
        FROM mapa_obiekty o LEFT JOIN mapa_floty_jednostki j ON j.obiekt_id=o.id LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id=o.id
        WHERE o.id IN ($marks) ORDER BY COALESCE(j.kolejnosc, 999), o.id LIMIT 1");
    $q->execute($odbiorcy);
    $k = $q->fetch(PDO::FETCH_ASSOC) ?: ['klasa' => 3, 'stan' => 'sprawny'];
    $klasa = max(1, min(5, (int) $k['klasa']));
    return [$klasa, (string) $k['stan'], in_array($k['stan'], ['sprawny', 'zhakowany'], true)];
}

// Dolot na inny wariant orbity (napęd podświetlny).
function floty_dolot(array $odbiorcy, string $cel): array
{
    if (!in_array($cel, NAV_WARIANTY, true)) blad('Nieznany wariant orbity.');
    floty_aktualizuj_ruch();
    if ($cel === 'krawedz' && floty_uklad_dostepny()) {
        // Krawędź całego układu: lot podświetlny z planety prosto od słońca na brzeg układu.
        $q = polaczenie_bazy()->prepare('SELECT planeta_id FROM mapa_obiekty WHERE id = ?');
        $q->execute([$odbiorcy[0]]);
        $pl = (int) $q->fetchColumn();
        if (!$pl) blad('Jednostka nie stoi przy planecie. Na krawędź układu leć podświetlnie z mapy.');
        [$sx, $sy] = floty_wsp_planety($pl);
        $kp = floty_planeta_km($pl, czas_teraz());
        $r = (float) (astro_cykl((string) ruch_planeta((string) $pl)['nazwa'])['krawedz_au'] ?? ASTRO_KRAWEDZ_AU) * ASTRO_KM_NA_AU;
        $d = max(1.0, hypot($kp[0], $kp[1]));
        $w = floty_lot($odbiorcy, ['x' => $sx, 'y' => $sy, 'kx' => $kp[0] / $d * $r, 'ky' => $kp[1] / $d * $r]);
        return ['ok' => true, 'cel' => 'krawedz', 'koniec' => $w['eta'], 'lot' => $w];
    }
    $db = polaczenie_bazy();
    $marks = implode(',', array_fill(0, count($odbiorcy), '?'));
    $q = $db->prepare("SELECT 1 FROM mapa_rozkazy_skokow_jednostki WHERE obiekt_id IN ($marks) AND status='w_nadprzestrzeni' LIMIT 1");
    $q->execute($odbiorcy);
    if ($q->fetchColumn()) blad('Jednostki wykonujące skok nie zmienią teraz orbity.');
    floty_blokada_ladowiska($odbiorcy);
    floty_blokada_lotu($odbiorcy);
    $q = $db->prepare("SELECT o.id,o.nazwa,o.planeta_id,o.rozbity,o.podejscie,o.podlot_start,o.podlot_cel,o.podlot_od_utc,o.podlot_do_utc,
            COALESCE(n.obrot_deg_na_min,90) AS obrot FROM mapa_obiekty o LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id=o.id
        WHERE o.id IN ($marks) AND o.aktywny=1");
    $q->execute($odbiorcy);
    $jednostki = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$jednostki) blad('Brak jednostek.');
    $teraz = czas_teraz();
    $obrot = min(array_map(fn($j) => (float) $j['obrot'] ?: 90.0, $jednostki));
    $koniec = $teraz;
    $db->beginTransaction();
    try {
        foreach ($jednostki as $j) {
            if (!$j['planeta_id']) blad('Jednostka „' . $j['nazwa'] . '” nie stoi przy planecie. Do planety leć podświetlnie.');
            if (!empty($j['rozbity'])) blad('Jednostka „' . $j['nazwa'] . '” jest rozbita.');
            $start = floty_podejscie_teraz($j, $teraz) ?: 'bliska';
            $minuty = floty_dolot_minuty($start, $cel, $obrot);
            if ($minuty <= 0) {
                $db->prepare('UPDATE mapa_obiekty SET podejscie=?, podlot_start=NULL, podlot_cel=NULL, podlot_od_utc=NULL, podlot_do_utc=NULL WHERE id=?')->execute([$cel, $j['id']]);
                continue;
            }
            $do = $teraz + czas_trwania($minuty);
            $db->prepare('UPDATE mapa_obiekty SET podlot_start=?, podlot_cel=?, podlot_od_utc=?, podlot_do_utc=? WHERE id=?')
                ->execute([$start, $cel, czas_utc($teraz), czas_utc($do), $j['id']]);
            $koniec = max($koniec, $do);
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return ['ok' => true, 'cel' => $cel, 'koniec' => $koniec];
}

// ---------- kurs, orbita i manewry ----------
// Kurs (kurs_deg) to kierunek dziobu w układzie mapy: 0° = wschód, 90° = północ. Miejsce na orbicie
// (orbita_deg) to osobny kąt: po której stronie planety stoi jednostka. Manewr to płynny obrót
// dziobu od kurs_start_deg do kurs_cel_deg w czasie manewr_od_utc → manewr_do_utc, z tempem
// obrot_deg_na_min najwolniejszej jednostki. Przed skokiem flota najpierw obraca się na kurs skoku.
// Po wyjściu z nadprzestrzeni przy planecie: orbita po stronie przylotu, dziób ku planecie,
// przylot_utc = chwila wyjścia (podejście: planeta z mostka najpierw mała, potem coraz większa).

function floty_kurs_dostepny(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT kurs_deg, manewr_do_utc FROM mapa_obiekty LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

function floty_orbita_dostepna(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT orbita_deg, przylot_utc, rozbity FROM mapa_obiekty LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// ---------- lądowanie i start małych jednostek ----------
// Jednostka z moze_ladowac (Kom'rk, Gozanti…) z bliskiej orbity ląduje na lądowisku planety
// (lokalizacje: typ ladowisko albo rodzaj hangar / port / platforma); stoi wtedy z ladowisko_id.
// Start: wznoszenie (scena „start” u załogi), potem bliska orbita. Na lądowisku nie ma skoku, obrotu ani dolotu.
const START_WZNOSZENIE_S = 300;

function floty_ladowanie_dostepne(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT ladowisko_id, start_utc FROM mapa_obiekty LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// ---------- lot do punktu skoku ----------
// Skok przy planecie zaczyna się dopiero w punkcie skoku (poza studnią grawitacyjną): okręt leci po
// orbicie na stronę zwróconą ku celowi (łuk, ok. 1/15 prędkości podświetlnej), potem prosto do
// odległości PUNKT_SKOKU_KM od planety. Z krawędzi układu i z pustej przestrzeni tego etapu nie ma.
// Przykład (Kom'rk, 600 km/s, planeta jak Ziemia): start o 00:00 — ok. 9 min z wznoszeniem,
// o 12:00 — pół orbity więcej, ok. 18 min.
const PUNKT_SKOKU_KM = 150000;
const NISKA_ORBITA_KM = 300;

function floty_punkt_dostepny(): bool
{
    static $jest = null;
    if ($jest === null) {
        try {
            polaczenie_bazy()->query('SELECT predkosc_podswietlna_kms FROM mapa_naped_jednostki LIMIT 0');
            polaczenie_bazy()->query('SELECT punkt_od_utc FROM mapa_rozkazy_skokow LIMIT 0');
            $jest = true;
        } catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// Promień orbity (km) dla wariantu orbity; krawędź układu = poza studnią (null).
function floty_promien_orbity_km(float $promienPlanety, ?string $podejscie): ?float
{
    return match ($podejscie ?? 'bliska') {
        'srednia' => $promienPlanety * 4,
        'daleka' => $promienPlanety * 15,
        'krawedz' => null,
        default => $promienPlanety + NISKA_ORBITA_KM,
    };
}

// Czas (s) lotu jednostki do punktu skoku w kierunku $kierunek (stopnie, układ mapy).
function floty_czas_do_punktu(array $j, float $kierunek, int $teraz): int
{
    if (!floty_punkt_dostepny() || empty($j['planeta_startu'])) return 0;
    static $promienie = [];
    $pid = (int) $j['planeta_startu'];
    if (!isset($promienie[$pid])) {
        $q = polaczenie_bazy()->prepare('SELECT nazwa FROM mapa_planety WHERE id = ?');
        $q->execute([$pid]);
        $nazwa = (string) $q->fetchColumn();
        $promienie[$pid] = $nazwa !== '' ? astro_cykl($nazwa)['srednica_km'] / 2 : 6371.0;
    }
    $R = floty_promien_orbity_km($promienie[$pid], floty_podejscie_teraz($j, $teraz));
    if ($R === null) return 0;
    $orbita = $j['orbita_deg'] !== null ? (float) $j['orbita_deg'] : floty_kat(floty_kurs_teraz($j, $teraz) + 180);
    $luk = $R * deg2rad(abs(floty_kat_roznica($orbita, $kierunek)));
    if (isset($j['podswietlna_pc_doba'])) return (int) ceil(floty_czas_lotu_s($luk + max(0.0, PUNKT_SKOKU_KM - $R), (float) $j['podswietlna_pc_doba']));
    $v = max(10.0, (float) ($j['predkosc_podswietlna_kms'] ?? 300));
    return (int) ceil($luk / max(1.0, $v / 15) + max(0.0, PUNKT_SKOKU_KM - $R) / $v);
}

// ---------- lot podświetlny ----------
// Zwykłe silniki: czas gry 1:1 (MG może przyspieszyć resynchronizacją). Trasa w km: między planetą
// a jej księżycem z promienia obiegu, w układzie — w km od słońca, poza tym z odległości na mapie.
// Prędkość przelotowa w pc na dobę (migracja 039; ISD 22 — sąsiedni układ w kilka dni, lot w układzie
// ok. 2 minut na rozpęd i hamowanie). Flota leci razem tempem najwolniejszej jednostki:
// obrót na kurs, rozpędzanie, przelot, hamowanie. Po przylocie przy planecie: daleka orbita.
const KM_NA_PC = 30856775814913.7;
const LOT_ROZPED_S = 120;        // rozpędzanie i hamowanie razem

// Silniki podświetlne (migracja 039): szybki rozpęd i hamowanie (LOT_ROZPED_S), potem stała prędkość
// przelotowa w pc na dobę. Skala: Sokół Millennium z Hoth na Bespin (132,9 pc) w ok. 3 dni = 45 pc/doba.
function floty_przyspieszenie_dostepne(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT podswietlna_pc_doba FROM mapa_naped_jednostki LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// Prędkość przelotowa (km/s) z pc na dobę.
function floty_kms_z_pc_doba(float $pcDoba): float
{
    return max(0.01, $pcDoba) * KM_NA_PC / 86400;
}

// Czas lotu (s): rozpęd i hamowanie + droga / prędkość przelotowa.
function floty_czas_lotu_s(float $km, float $pcDoba): float
{
    return LOT_ROZPED_S + max(0.0, $km) / floty_kms_z_pc_doba($pcDoba);
}

// Wewnątrz układu (lot od punktu do punktu tego samego układu) silniki pracują ostrożnie: prędkość
// proporcjonalna do przelotowej, ISD (22 pc/doba) — 1 j.a. w ok. 40 min, Sokół (45) — w ok. 20 min.
const LOT_UKLAD_KMS_NA_PC_DOBA = 2833.0;
function floty_czas_lotu_w_ukladzie_s(float $km, float $pcDoba): float
{
    return LOT_ROZPED_S + max(0.0, $km) / (max(0.01, $pcDoba) * LOT_UKLAD_KMS_NA_PC_DOBA);
}

// Ułamek drogi po ułamku czasu u (rozpęd i hamowanie są krótkie — równe tempo).
function floty_lot_ulamek(float $u): float
{
    return max(0.0, min(1.0, $u));
}
// Strefa grawitacyjna układu (studnia): w tym promieniu od planety jednostka jest w jej układzie. Komputer
// nawigacyjny nie pozwala w niej wyjść z nadprzestrzeni (poza skokiem do tego układu) ani przez nią przeskoczyć
// czy przelecieć. Pół kroku mapy (pozycje co 0,1 pc).
const STREFA_UKLADU_PC = 0.05;
const LOT_W_UKLADZIE_PC = STREFA_UKLADU_PC;
// Skok automatu do układu: wyjście z rozrzutem do tylu pc od celu kończy się w tym układzie.
const TOLERANCJA_AUTOMATU_PC = 2.0;

// Pierwsza studnia grawitacyjna na odcinku trasy (układ inny niż startu i celu): opis albo null.
function floty_trasa_kolizja(float $x0, float $y0, float $x1, float $y1): ?string
{
    static $pamiec = [];
    $klucz = "$x0|$y0|$x1|$y1";
    if (array_key_exists($klucz, $pamiec)) return $pamiec[$klucz]; // flota startuje z jednego punktu
    $s = STREFA_UKLADU_PC;
    $q = polaczenie_bazy()->prepare('SELECT id, nazwa, x, y FROM mapa_planety WHERE x BETWEEN ? AND ? AND y BETWEEN ? AND ?');
    $q->execute([min($x0, $x1) - $s, max($x0, $x1) + $s, min($y0, $y1) - $s, max($y0, $y1) + $s]);
    $dx = $x1 - $x0; $dy = $y1 - $y0; $l2 = $dx * $dx + $dy * $dy;
    $najblizej = null; $tMin = 2.0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $px = (float) $p['x']; $py = (float) $p['y'];
        if (hypot($px - $x0, $py - $y0) < $s || hypot($px - $x1, $py - $y1) < $s) continue; // układ startu albo celu
        $t = $l2 > 0 ? max(0.0, min(1.0, (($px - $x0) * $dx + ($py - $y0) * $dy) / $l2)) : 0.0;
        if (hypot($px - ($x0 + $dx * $t), $py - ($y0 + $dy * $t)) < $s && $t < $tMin) { $tMin = $t; $najblizej = $p; }
    }
    if (!$najblizej) return $pamiec[$klucz] = null;
    return $pamiec[$klucz] = mapa_planeta_widoczna((int) $najblizej['id']) ? 'układu „' . $najblizej['nazwa'] . '”' : 'nieznanego układu';
}


// ---------- układ planetarny: pozycje w km od słońca i studnie wokół ciał ----------
// Jednostka w układzie stoi przy planecie (planeta_id) albo w punkcie układu (uklad_kx/ky: km od słońca,
// osie mapy; x, y = współrzędne układu). Studnie grawitacyjne otaczają ciała: słońce (20 jego promieni)
// i planety (100 ich promieni). Komputer nie pozwala wyjść z nadprzestrzeni w studni ani przez nią
// przeskoczyć czy przelecieć — poza skokiem automatem na orbitę planety (cel) i odlotem od planety (start).
const STUDNIA_SLONCA_R = 20;
const STUDNIA_PLANETY_R = 100;
const DALEKO_KM = 1.0e14; // „z nieskończoności”: kierunek nadejścia skoku w układzie celu

function floty_uklad_dostepny(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT uklad_kx, lot_cel_kx FROM mapa_obiekty LIMIT 0');
              polaczenie_bazy()->query('SELECT wyjscie_kx FROM mapa_rozkazy_skokow_jednostki LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// Planety układu w punkcie mapy (te same współrzędne), główna pierwsza.
function floty_planety_ukladu(float $x, float $y): array
{
    $q = polaczenie_bazy()->prepare('SELECT id, nazwa FROM mapa_planety WHERE ABS(x-?) < 0.001 AND ABS(y-?) < 0.001 ORDER BY id');
    $q->execute([$x, $y]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function floty_planeta_km(int $id, int $t): array
{
    $q = polaczenie_bazy()->prepare('SELECT nazwa FROM mapa_planety WHERE id = ?');
    $q->execute([$id]);
    return astro_pozycja_km(astro_cykl((string) $q->fetchColumn()), $t);
}

// Studnie układu: [kx, ky, r, nazwa, grupa] (grupa: planeta razem z księżycami — wspólne ciało nawigacji).
function floty_studnie(float $x, float $y, int $t): array
{
    $planety = floty_planety_ukladu($x, $y);
    if (!$planety) return [];
    $glowny = astro_cykl((string) $planety[0]['nazwa']);
    $rs = max(array_map(fn($g) => (float) ($g['promien_km'] ?? ASTRO_PROMIEN_SLONCA_KM), $glowny['gwiazdy'] ?? [[]]));
    $studnie = [[0.0, 0.0, $rs * STUDNIA_SLONCA_R, 'słońca układu', '']];
    foreach ($planety as $p) {
        $c = astro_cykl((string) $p['nazwa']);
        [$kx, $ky] = astro_pozycja_km($c, $t);
        $grupa = mb_strtolower((string) ($c['macierz']['nazwa'] ?? $p['nazwa']));
        $studnie[] = [$kx, $ky, (float) $c['srednica_km'] / 2 * STUDNIA_PLANETY_R, '„' . $p['nazwa'] . '”', $grupa];
    }
    return $studnie;
}

function floty_grupa_planety(?int $id): string
{
    if (!$id) return '#';
    $q = polaczenie_bazy()->prepare('SELECT nazwa FROM mapa_planety WHERE id = ?');
    $q->execute([$id]);
    $n = (string) $q->fetchColumn();
    return mb_strtolower((string) (astro_cykl($n)['macierz']['nazwa'] ?? $n));
}

// Pierwsza studnia na odcinku (km, układ studni) poza pominiętymi grupami: opis albo null.
function floty_odcinek_studnia(array $studnie, array $a, array $b, array $pomin = []): ?string
{
    $dx = $b[0] - $a[0]; $dy = $b[1] - $a[1]; $l2 = $dx * $dx + $dy * $dy;
    foreach ($studnie as [$sx, $sy, $r, $nazwa, $grupa]) {
        if ($grupa !== '' && in_array($grupa, $pomin, true)) continue;
        $t = $l2 > 0 ? max(0.0, min(1.0, (($sx - $a[0]) * $dx + ($sy - $a[1]) * $dy) / $l2)) : 0.0;
        if (hypot($sx - ($a[0] + $dx * $t), $sy - ($a[1] + $dy * $t)) < $r) return $grupa === '' ? 'słońca układu' : 'planety ' . $nazwa;
    }
    return null;
}

// Punkt w studni (opis) albo null.
function floty_punkt_w_studni(float $x, float $y, array $k, int $t): ?string
{
    return floty_odcinek_studnia(floty_studnie($x, $y, $t), $k, $k);
}

// Brzeg całego układu (km od słońca) w kierunku punktu mapy (od, pc) — np. tam, skąd nadleciał skok.
function floty_krawedz_km(float $x, float $y, float $odX, float $odY): ?array
{
    $planety = floty_planety_ukladu($x, $y);
    if (!$planety) return null;
    $r = (float) (astro_cykl((string) $planety[0]['nazwa'])['krawedz_au'] ?? ASTRO_KRAWEDZ_AU) * ASTRO_KM_NA_AU;
    $dx = $odX - $x; $dy = $odY - $y; $d = hypot($dx, $dy);
    return $d > 1e-9 ? [$dx / $d * $r, $dy / $d * $r] : [$r, 0.0];
}

// Pozycja jednostki w jej układzie (km od słońca) albo null (pusta przestrzeń).
function floty_poz_km(array $o, int $t): ?array
{
    if (!empty($o['planeta_id'])) return floty_planeta_km((int) $o['planeta_id'], $t);
    if (isset($o['uklad_kx'], $o['uklad_ky']) && $o['uklad_kx'] !== null) return [(float) $o['uklad_kx'], (float) $o['uklad_ky']];
    return null;
}

function floty_ustaw_k(PDO $db, int $id, ?array $k): void
{
    if (!floty_uklad_dostepny()) return;
    $db->prepare('UPDATE mapa_obiekty SET uklad_kx=?, uklad_ky=? WHERE id=?')->execute([$k ? round($k[0], 1) : null, $k ? round($k[1], 1) : null, $id]);
}

// Trasa skoku przez studnie: w układzie startu (od miejsca startu w stronę celu) i w układzie celu
// (od strony nadejścia do miejsca wyjścia). Planeta startu i planeta celu (automat na orbitę) — pominięte.
function floty_trasa_skoku(array $j, float $x, float $y, ?array $celK, ?int $celPlaneta, int $t): ?string
{
    $x0 = (float) $j['x']; $y0 = (float) $j['y'];
    $startK = floty_poz_km($j, $t);
    $startGrupa = floty_grupa_planety(((int) ($j['planeta_id'] ?? 0)) ?: null);
    $celGrupa = floty_grupa_planety($celPlaneta);
    $celK ??= $celPlaneta ? floty_planeta_km($celPlaneta, $t) : null;
    $dx = $x - $x0; $dy = $y - $y0; $d = hypot($dx, $dy);
    if ($d < LOT_W_UKLADZIE_PC) {
        if ($startK && $celK) return floty_odcinek_studnia(floty_studnie($x, $y, $t), $startK, $celK, [$startGrupa, $celGrupa]);
        return null;
    }
    $ux = $dx / $d; $uy = $dy / $d;
    if ($startK && ($k = floty_odcinek_studnia(floty_studnie($x0, $y0, $t), $startK, [$startK[0] + $ux * DALEKO_KM, $startK[1] + $uy * DALEKO_KM], [$startGrupa]))) return $k . ' w układzie startu';
    if ($celK && ($k = floty_odcinek_studnia(floty_studnie($x, $y, $t), [$celK[0] - $ux * DALEKO_KM, $celK[1] - $uy * DALEKO_KM], $celK, [$celGrupa]))) return $k;
    return null;
}

// Współrzędne planety (układu) po id.
function floty_wsp_planety(int $id): ?array
{
    $q = polaczenie_bazy()->prepare('SELECT x, y FROM mapa_planety WHERE id = ?');
    $q->execute([$id]);
    $p = $q->fetch(PDO::FETCH_ASSOC);
    return $p ? [(float) $p['x'], (float) $p['y']] : null;
}

function floty_lot_dostepny(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT lot_do_utc, lot_cel_planeta_id, rola_na_pokladzie FROM mapa_obiekty LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

function floty_blokada_lotu(array $odbiorcy): void
{
    if (!floty_lot_dostepny() || !$odbiorcy) return;
    $marks = implode(',', array_fill(0, count($odbiorcy), '?'));
    $q = polaczenie_bazy()->prepare("SELECT nazwa FROM mapa_obiekty WHERE id IN ($marks) AND lot_do_utc IS NOT NULL LIMIT 1");
    $q->execute($odbiorcy);
    $n = $q->fetchColumn();
    if ($n) blad('„' . $n . '” jest w locie podświetlnym. Najpierw przerwij lot.');
}

// Planeta w punkcie mapy (ten sam układ); przy równej odległości planeta przed księżycem (niższe id).
function floty_planeta_w_punkcie(float $x, float $y): ?int
{
    $q = polaczenie_bazy()->prepare('SELECT id FROM mapa_planety WHERE ABS(x-?) < ? AND ABS(y-?) < ? ORDER BY POW(x-?,2)+POW(y-?,2), id LIMIT 1');
    $q->execute([$x, LOT_W_UKLADZIE_PC, $y, LOT_W_UKLADZIE_PC, $x, $y]);
    $id = $q->fetchColumn();
    return $id ? (int) $id : null;
}

// Długość trasy (km). W układzie: planeta ↔ jej księżyc (promień obiegu), dwa księżyce jednej planety
// (przez planetę macierzystą), inne ciała układu — umownie 1 mln km.
function floty_lot_km(?int $odPlanety, float $x0, float $y0, ?int $doPlanety, float $x1, float $y1): float
{
    $pc = hypot($x1 - $x0, $y1 - $y0);
    if ($pc >= LOT_W_UKLADZIE_PC || !$odPlanety || !$doPlanety) return $pc * KM_NA_PC;
    if ($odPlanety === $doPlanety) return 0.0;
    $obiegi = [];
    if (tabela_sql_dostepna('planety_cykle')) {
        try {
            $q = polaczenie_bazy()->prepare('SELECT planeta_id, macierz_planeta_id, promien_wokol_km FROM planety_cykle WHERE planeta_id IN (?,?)');
            $q->execute([$odPlanety, $doPlanety]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $obiegi[(int) $r['planeta_id']] = $r;
        } catch (Throwable $e) { $obiegi = []; }
    }
    $r = fn(int $id) => (float) ($obiegi[$id]['promien_wokol_km'] ?? 0);
    $m = fn(int $id) => (int) ($obiegi[$id]['macierz_planeta_id'] ?? 0);
    if ($m($doPlanety) === $odPlanety) return $r($doPlanety);
    if ($m($odPlanety) === $doPlanety) return $r($odPlanety);
    if ($m($odPlanety) && $m($odPlanety) === $m($doPlanety)) return hypot($r($odPlanety), $r($doPlanety));
    return 1000000.0;
}

// Rozkaz lotu (albo sam plan): $w['cel_planeta'] (planeta lub księżyc) albo $w['x'], $w['y'].
function floty_lot(array $odbiorcy, array $w): array
{
    if (!floty_lot_dostepny()) blad('Zaimportuj jeszcze api/migracje/028_lot_podswietlny_poklad.sql.');
    floty_aktualizuj_ruch();
    floty_blokada_skoku($odbiorcy);
    floty_blokada_ladowiska($odbiorcy);
    $db = polaczenie_bazy();
    $celPlaneta = trim((string) ($w['cel_planeta'] ?? ''));
    $celId = null;
    if ($celPlaneta !== '') {
        $q = $db->prepare('SELECT id,x,y FROM mapa_planety WHERE nazwa=? LIMIT 1');
        $q->execute([$celPlaneta]);
        $pl = $q->fetch(PDO::FETCH_ASSOC);
        if (!$pl || !mapa_planeta_widoczna((int) $pl['id'], ja())) blad('Nie znasz takiego celu.');
        $celId = (int) $pl['id']; $x = (float) $pl['x']; $y = (float) $pl['y'];
    } else {
        $x = $w['x'] ?? null; $y = $w['y'] ?? null;
        if (!is_numeric($x) || !is_numeric($y)) blad('Podaj punkt docelowy.');
        $x = round((float) $x, 1); $y = round((float) $y, 1);
        if (abs($x) > 1000000 || abs($y) > 1000000) blad('Współrzędne wykraczają poza zakres mapy.');
        // Punkt w układzie: z km od słońca (kx, ky) — dokładnie tam; bez nich — do planety układu.
        $celId = floty_planeta_w_punkcie($x, $y);
        if ($celId && !mapa_planeta_widoczna($celId, ja())) blad('Nie masz dostępu do wskazanego obszaru.', 403);
        if ($celId) [$x, $y] = floty_wsp_planety($celId);
        if ($celId && floty_uklad_dostepny() && is_numeric($w['kx'] ?? null) && is_numeric($w['ky'] ?? null)) {
            $celK = [(float) $w['kx'], (float) $w['ky']];
            if ($s = floty_punkt_w_studni($x, $y, $celK, czas_teraz())) blad('Ten punkt leży w studni grawitacyjnej ' . $s . '.');
            $celId = null;
        }
    }
    $celK ??= null;
    $marks = implode(',', array_fill(0, count($odbiorcy), '?'));
    $q = $db->prepare("SELECT o.id,o.nazwa,o.x,o.y,o.planeta_id,o.kurs_deg,o.kurs_start_deg,o.kurs_cel_deg,o.manewr_od_utc,o.manewr_do_utc,
            o.lot_do_utc,COALESCE(n.obrot_deg_na_min,90) AS obrot,COALESCE(n.predkosc_podswietlna_kms,300) AS v" . (floty_uklad_dostepny() ? ',o.uklad_kx,o.uklad_ky' : '')
            . (floty_przyspieszenie_dostepne() ? ',COALESCE(n.podswietlna_pc_doba,20) AS g' : '') . "
        FROM mapa_obiekty o LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id=o.id WHERE o.id IN ($marks) AND o.aktywny=1");
    $q->execute($odbiorcy);
    $jednostki = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($jednostki) !== count($odbiorcy)) blad('Brak jednej z jednostek.');
    $teraz = czas_teraz();
    $obrot = min(array_map(fn($j) => (float) $j['obrot'] ?: 90.0, $jednostki));
    $v = max(10.0, min(array_map(fn($j) => (float) $j['v'], $jednostki)));
    $g = floty_przyspieszenie_dostepne() ? max(0.01, min(array_map(fn($j) => (float) $j['g'], $jednostki))) : null; // pc na dobę
    $wyliczone = [];
    foreach ($jednostki as $j) {
        if ($j['x'] === null || $j['y'] === null) blad('Jednostka „' . $j['nazwa'] . '” nie ma pozycji mapowej.');
        // Trwający lot: nowy kurs od miejsca, w którym jednostka jest teraz.
        $poz = floty_lot_pozycja($j['id'], $teraz);
        $x0 = $poz ? $poz[0] : (float) $j['x']; $y0 = $poz ? $poz[1] : (float) $j['y'];
        $odPl = $poz ? ($poz[2] === null ? floty_planeta_w_punkcie($x0, $y0) : null) : ((int) $j['planeta_id'] ?: null);
        // Miejsce w układzie (km od słońca): przy planecie, w punkcie układu albo w trwającym locie.
        $k0 = $poz ? ($poz[2] !== null ? [$poz[2], $poz[3]] : null) : (floty_uklad_dostepny() ? floty_poz_km($j, $teraz) : null);
        $pc = hypot($x - $x0, $y - $y0);
        $wUkladzie = $pc < LOT_W_UKLADZIE_PC && $k0 !== null && ($celK !== null || $celId);
        $k1 = null;
        if ($wUkladzie) {
            $k1 = $celK ?? floty_planeta_km($celId, $teraz);
            $km = hypot($k1[0] - $k0[0], $k1[1] - $k0[1]);
            if ($s = floty_odcinek_studnia(floty_studnie($x, $y, $teraz), $k0, $k1, [floty_grupa_planety($odPl), floty_grupa_planety($celId)])) {
                blad('Komputer nawigacyjny odrzuca trasę: lot jednostki „' . $j['nazwa'] . '” przecina studnię grawitacyjną ' . $s . '.');
            }
        } else {
            $km = floty_lot_km($odPl, $x0, $y0, $celId, $x, $y);
            if ($pc >= LOT_W_UKLADZIE_PC && floty_uklad_dostepny() && ($s = floty_trasa_skoku($j + ['x' => $x0, 'y' => $y0], $x, $y, $celK, $celId, $teraz))) {
                blad('Komputer nawigacyjny odrzuca trasę: lot jednostki „' . $j['nazwa'] . '” przecina studnię grawitacyjną ' . $s . '.');
            }
        }
        if ($km < 1) blad('Jednostka „' . $j['nazwa'] . '” już jest w tym miejscu.');
        $kursTeraz = floty_kurs_teraz($j, $teraz);
        $kurs = $wUkladzie ? floty_kat(rad2deg(atan2($k1[1] - $k0[1], $k1[0] - $k0[0])))
            : ($pc >= 0.05 ? floty_kat(rad2deg(atan2($y - $y0, $x - $x0))) : $kursTeraz);
        $wyliczone[] = ['id' => (int) $j['id'], 'nazwa' => $j['nazwa'], 'x0' => $x0, 'y0' => $y0, 'km' => $km, 'k0' => $wUkladzie ? $k0 : null, 'k1' => $k1,
            'kurs0' => $kursTeraz, 'kurs' => $kurs, 'manewr' => floty_czas_manewru(floty_kat_roznica($kursTeraz, $kurs), $obrot)];
    }
    $km = max(array_column($wyliczone, 'km'));
    // Rozpęd i hamowanie (2 min) + przelot z prędkością przelotową najwolniejszej jednostki.
    $wewnatrz = $wyliczone[0]['k0'] !== null; // lot w obrębie jednego układu: ograniczona prędkość
    $lotS = $g ? czas_trwania(($wewnatrz ? floty_czas_lotu_w_ukladzie_s($km, $g) : floty_czas_lotu_s($km, $g)) / 60)
        : czas_trwania(($km / $v + LOT_ROZPED_S) / 60);
    $vMaks = $g ? ($wewnatrz ? $g * LOT_UKLAD_KMS_NA_PC_DOBA : floty_kms_z_pc_doba($g)) : $v;
    $od = $teraz + max(array_column($wyliczone, 'manewr'));
    $do = $od + $lotS;
    $wynik = ['ok' => true, 'km' => round($km), 'predkosc_kms' => round($vMaks), 'pc_doba' => $wewnatrz ? null : $g, 'w_ukladzie' => $wewnatrz, 'manewr_s' => $od - $teraz, 'lot_s' => $lotS, 'start' => $od, 'eta' => $do,
        'cel' => ['x' => $x, 'y' => $y, 'k' => $celK, 'planeta' => $celId ? ruch_planeta((string) $celId)['nazwa'] ?? null : null]];
    if (!empty($w['plan'])) return $wynik + ['plan' => true];
    $db->beginTransaction();
    try {
        $u = $db->prepare('UPDATE mapa_obiekty SET lot_start_x=?, lot_start_y=?, lot_cel_x=?, lot_cel_y=?, lot_cel_planeta_id=?, lot_km=?, lot_od_utc=?, lot_do_utc=?'
            . (floty_nawigacja_dostepna() ? ', podlot_start=NULL, podlot_cel=NULL, podlot_od_utc=NULL, podlot_do_utc=NULL' : '') . ' WHERE id=?');
        $uk = floty_uklad_dostepny() ? $db->prepare('UPDATE mapa_obiekty SET lot_start_kx=?, lot_start_ky=?, lot_cel_kx=?, lot_cel_ky=? WHERE id=?') : null;
        foreach ($wyliczone as $j) {
            floty_ustaw_manewr($db, $j['id'], $j['kurs0'], $j['kurs'], $teraz, $teraz + $j['manewr']);
            $u->execute([$j['x0'], $j['y0'], $x, $y, $celId, round($j['km'], 1), czas_utc($od), czas_utc($do), $j['id']]);
            // Lot w układzie: początek i cel w km od słońca (cel bez planety = punkt układu).
            $uk?->execute([$j['k0'][0] ?? null, $j['k0'][1] ?? null, $j['k1'][0] ?? null, $j['k1'][1] ?? null, $j['id']]);
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return $wynik;
}

// Pozycja (x, y) jednostki w trwającym locie podświetlnym albo null.
function floty_lot_pozycja(int|string $id, int $teraz): ?array
{
    if (!floty_lot_dostepny()) return null;
    $q = polaczenie_bazy()->prepare('SELECT lot_start_x,lot_start_y,lot_cel_x,lot_cel_y,lot_od_utc,lot_do_utc'
        . (floty_uklad_dostepny() ? ',lot_start_kx,lot_start_ky,lot_cel_kx,lot_cel_ky' : '') . ' FROM mapa_obiekty WHERE id=? AND lot_do_utc IS NOT NULL');
    $q->execute([(int) $id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $od = czas_z_utc($r['lot_od_utc']); $do = czas_z_utc($r['lot_do_utc']);
    $f = floty_lot_ulamek($do > $od ? ($teraz - $od) / ($do - $od) : 1);
    // [x, y, kx, ky]: w locie w obrębie układu także km od słońca (inaczej null).
    $wUkladzie = ($r['lot_start_kx'] ?? null) !== null && ($r['lot_cel_kx'] ?? null) !== null;
    return [(float) $r['lot_start_x'] + ((float) $r['lot_cel_x'] - (float) $r['lot_start_x']) * $f,
        (float) $r['lot_start_y'] + ((float) $r['lot_cel_y'] - (float) $r['lot_start_y']) * $f,
        $wUkladzie ? (float) $r['lot_start_kx'] + ((float) $r['lot_cel_kx'] - (float) $r['lot_start_kx']) * $f : null,
        $wUkladzie ? (float) $r['lot_start_ky'] + ((float) $r['lot_cel_ky'] - (float) $r['lot_start_ky']) * $f : null];
}

// Przerwanie lotu: jednostka zatrzymuje się tam, gdzie jest (w układzie: na dalekiej orbicie bliższego ciała).
function floty_lot_przerwij(array $odbiorcy): array
{
    if (!floty_lot_dostepny()) blad('Zaimportuj jeszcze api/migracje/028_lot_podswietlny_poklad.sql.');
    $db = polaczenie_bazy();
    $teraz = czas_teraz();
    $ile = 0;
    foreach ($odbiorcy as $id) {
        $poz = floty_lot_pozycja($id, $teraz);
        if (!$poz) continue;
        $q = $db->prepare('SELECT lot_start_x,lot_start_y,lot_cel_planeta_id,lot_od_utc,lot_do_utc FROM mapa_obiekty WHERE id=?');
        $q->execute([(int) $id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($poz[2] !== null) {
            // Lot w obrębie układu: zatrzymanie w punkcie układu, w którym jest jednostka.
            $sys = floty_planeta_w_punkcie(round($poz[0], 1), round($poz[1], 1));
            [$sx, $sy] = $sys ? floty_wsp_planety($sys) : [round($poz[0], 1), round($poz[1], 1)];
            floty_lot_zakoncz($db, (int) $id, $sx, $sy, null, $teraz, false, [$poz[2], $poz[3]]);
        } else {
            $pl = floty_planeta_w_punkcie(round($poz[0], 1), round($poz[1], 1));
            $k = $pl && floty_uklad_dostepny() ? floty_krawedz_km(...[...floty_wsp_planety($pl), (float) $r['lot_start_x'], (float) $r['lot_start_y']]) : null;
            // Zatrzymanie w studni innego układu: na jego krawędzi (od strony nadlotu).
            if ($k) { [$sx, $sy] = floty_wsp_planety($pl); floty_lot_zakoncz($db, (int) $id, $sx, $sy, null, $teraz, false, $k); }
            else floty_lot_zakoncz($db, (int) $id, round($poz[0], 1), round($poz[1], 1), $pl, $teraz, false);
        }
        $k = $db->prepare('SELECT kurs_deg,kurs_start_deg,kurs_cel_deg,manewr_od_utc,manewr_do_utc FROM mapa_obiekty WHERE id=?');
        $k->execute([(int) $id]);
        $kurs = floty_kurs_teraz($k->fetch(PDO::FETCH_ASSOC), $teraz);
        floty_ustaw_manewr($db, (int) $id, $kurs, $kurs, 0, 0);
        $ile++;
    }
    if (!$ile) blad('Żadna z jednostek nie jest w locie.');
    return ['ok' => true];
}

function floty_lot_zakoncz(PDO $db, int $id, float $x, float $y, ?int $planeta, int $teraz, bool $przylot, ?array $k = null): void
{
    $db->prepare('UPDATE mapa_obiekty SET x=?, y=?, planeta_id=?, lot_start_x=NULL, lot_start_y=NULL, lot_cel_x=NULL, lot_cel_y=NULL,
        lot_cel_planeta_id=NULL, lot_km=NULL, lot_od_utc=NULL, lot_do_utc=NULL WHERE id=?')->execute([$x, $y, $planeta, $id]);
    if (floty_uklad_dostepny()) {
        $db->prepare('UPDATE mapa_obiekty SET lot_start_kx=NULL, lot_start_ky=NULL, lot_cel_kx=NULL, lot_cel_ky=NULL WHERE id=?')->execute([$id]);
        floty_ustaw_k($db, $id, $planeta ? null : $k);
    }
    if (floty_orbita_dostepna()) {
        // Przy planecie: daleka orbita po stronie przylotu (rufa ku planecie); bez animacji wyjścia ze skoku.
        $q = $db->prepare('SELECT kurs_deg FROM mapa_obiekty WHERE id=?');
        $q->execute([$id]);
        $kurs = (float) $q->fetchColumn();
        $db->prepare('UPDATE mapa_obiekty SET orbita_deg=?, przylot_utc=?, rozbity=0 WHERE id=?')
            ->execute([$planeta ? round(floty_kat($kurs + 180), 2) : null, $planeta ? czas_utc($teraz - 3600) : null, $id]);
        if (floty_nawigacja_dostepna()) $db->prepare('UPDATE mapa_obiekty SET podejscie=? WHERE id=?')->execute([$planeta ? 'daleka' : null, $id]);
    }
}

// Postęp lotów: przylot po czasie; w drodze między układami pozycja na mapie przesuwa się po linii lotu.
function floty_aktualizuj_loty(): void
{
    if (!floty_lot_dostepny()) return;
    $db = polaczenie_bazy();
    $teraz = czas_teraz();
    $uk = floty_uklad_dostepny();
    $q = $db->query('SELECT id,lot_start_x,lot_start_y,lot_cel_x,lot_cel_y,lot_cel_planeta_id,lot_od_utc,lot_do_utc'
        . ($uk ? ',lot_start_kx,lot_cel_kx,lot_cel_ky' : '') . ' FROM mapa_obiekty WHERE lot_do_utc IS NOT NULL');
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $od = czas_z_utc($r['lot_od_utc']); $do = czas_z_utc($r['lot_do_utc']);
        if ($teraz < $od) continue;
        $x1 = (float) $r['lot_cel_x']; $y1 = (float) $r['lot_cel_y'];
        $celK = ($r['lot_cel_kx'] ?? null) !== null && !$r['lot_cel_planeta_id'] ? [(float) $r['lot_cel_kx'], (float) $r['lot_cel_ky']] : null;
        if ($teraz >= $do) {
            // Przylot: do planety, do punktu układu (km od słońca) albo w pustą przestrzeń.
            $pl = $celK ? null : (((int) $r['lot_cel_planeta_id']) ?: floty_planeta_w_punkcie($x1, $y1));
            floty_lot_zakoncz($db, (int) $r['id'], $x1, $y1, $pl, $teraz, true, $celK);
            continue;
        }
        $wUkladzie = hypot($x1 - (float) $r['lot_start_x'], $y1 - (float) $r['lot_start_y']) < LOT_W_UKLADZIE_PC;
        if ($wUkladzie && ($r['lot_start_kx'] ?? null) === null) continue;
        $poz = floty_lot_pozycja($r['id'], $teraz);
        // W locie jednostka nie stoi przy planecie; w obrębie układu jej miejsce to punkt układu (km).
        $db->prepare('UPDATE mapa_obiekty SET x=?, y=?, planeta_id=NULL' . (floty_orbita_dostepna() ? ', orbita_deg=NULL' : '')
            . (floty_nawigacja_dostepna() ? ', podejscie=NULL' : '') . ' WHERE id=?')
            ->execute([$wUkladzie ? (float) $r['lot_start_x'] : round($poz[0], 1), $wUkladzie ? (float) $r['lot_start_y'] : round($poz[1], 1), $r['id']]);
        if ($uk) floty_ustaw_k($db, (int) $r['id'], $poz[2] !== null ? [$poz[2], $poz[3]] : null);
    }
}

// ---------- osoby na pokładzie ----------
// Kto jest na statku, gracz widzi dopiero w karcie statku (pasażerowie i więźniowie): osoby na tym
// pokładzie, dowódca lub właściciel statku i MG.
function floty_poklad(array $u, int $statekId): array
{
    $db = polaczenie_bazy();
    $mg = ($u['rola'] ?? '') === 'mg';
    $mozna = $mg || floty_jednostka_moze((string) $u['login'], $statekId);
    if (!$mozna) {
        $q = $db->prepare("SELECT 1 FROM mapa_obiekty WHERE legacy_uid=? AND typ='postac' AND na_pokladzie_id=?");
        $q->execute([(string) $u['id'], $statekId]);
        $mozna = (bool) $q->fetchColumn();
    }
    if (!$mozna && function_exists('ruch_lokalizator_dostepny') && ruch_lokalizator_dostepny()) {
        $q = $db->prepare('SELECT 1 FROM mapa_obiekty WHERE id=? AND LOWER(wlasciciel_login)=LOWER(?)');
        $q->execute([$statekId, (string) $u['login']]);
        $mozna = (bool) $q->fetchColumn();
    }
    if (!$mozna) blad('Nie masz wglądu w pokład tej jednostki.', 403);
    $rola = floty_lot_dostepny() ? 'rola_na_pokladzie' : "'pasazer'";
    $q = $db->prepare("SELECT id, nazwa, status, $rola AS rola FROM mapa_obiekty WHERE typ='postac' AND aktywny=1 AND na_pokladzie_id=? ORDER BY nazwa");
    $q->execute([$statekId]);
    $osoby = array_map(fn($r) => ['id' => (int) $r['id'], 'nazwa' => $r['nazwa'], 'status' => $r['status'], 'rola' => $r['rola']], $q->fetchAll(PDO::FETCH_ASSOC));
    // Hangar: promy tego okrętu (w hangarze albo poza nim).
    $hangar = [];
    if (floty_hangar_dostepny()) {
        $q = $db->prepare('SELECT nazwa, klasa, w_hangarze FROM mapa_obiekty WHERE okret_macierzysty_id = ? AND aktywny = 1 ORDER BY nazwa');
        $q->execute([$statekId]);
        $hangar = array_map(fn($r) => ['nazwa' => $r['nazwa'], 'klasa' => $r['klasa'], 'w_hangarze' => (bool) $r['w_hangarze']], $q->fetchAll(PDO::FETCH_ASSOC));
    }
    return ['ok' => true, 'mg' => $mg, 'hangar' => $hangar,
        'pasazerowie' => array_values(array_filter($osoby, fn($o) => $o['rola'] !== 'wiezien')),
        'wiezniowie' => array_values(array_filter($osoby, fn($o) => $o['rola'] === 'wiezien'))];
}

function floty_blokada_ladowiska(array $odbiorcy): void
{
    if (!floty_ladowanie_dostepne() || !$odbiorcy) return;
    $marks = implode(',', array_fill(0, count($odbiorcy), '?'));
    $q = polaczenie_bazy()->prepare("SELECT nazwa FROM mapa_obiekty WHERE id IN ($marks) AND ladowisko_id IS NOT NULL LIMIT 1");
    $q->execute($odbiorcy);
    $n = $q->fetchColumn();
    if ($n) blad('„' . $n . '” stoi na lądowisku. Najpierw start.');
}

// Lądowiska planety (po id planety z mapy). Niepubliczne (ukryte, serwisowe) widzi MG i postać,
// która je zna (znane_lokacje); pozostali — tylko publiczne.
function floty_ladowiska(int $planetaId, ?array $u = null): array
{
    if (!tabela_sql_dostepna('lokalizacje')) return [];
    $u ??= ja();
    $warunek = 'publiczna = 1';
    $parametry = [$planetaId];
    if (($u['rola'] ?? '') === 'mg') $warunek = '1';
    elseif (tabela_sql_dostepna('znane_lokacje')) {
        $warunek = '(publiczna = 1 OR EXISTS (SELECT 1 FROM znane_lokacje z JOIN postacie p ON p.podmiot_id = z.postac_id
            WHERE z.lokalizacja_id = lokalizacje.id AND p.konto_id = ?))';
        $parametry[] = (int) ($u['id'] ?? 0);
    }
    try {
        $q = polaczenie_bazy()->prepare("SELECT id, nazwa, COALESCE(rodzaj, typ) AS rodzaj FROM lokalizacje
            WHERE planeta_id = ? AND status = 'aktywna' AND (typ = 'ladowisko' OR rodzaj IN ('hangar','port','platforma')) AND $warunek ORDER BY nazwa");
        $q->execute($parametry);
    } catch (Throwable $e) {
        $q = polaczenie_bazy()->prepare("SELECT id, nazwa, COALESCE(rodzaj, typ) AS rodzaj FROM lokalizacje
            WHERE planeta_id = ? AND status = 'aktywna' AND (typ = 'ladowisko' OR rodzaj IN ('hangar','port','platforma')) AND publiczna = 1 ORDER BY nazwa");
        $q->execute([$planetaId]);
    }
    return array_map(fn($l) => ['id' => (int) $l['id'], 'nazwa' => $l['nazwa'], 'rodzaj' => $l['rodzaj']], $q->fetchAll(PDO::FETCH_ASSOC));
}

function floty_start(array $odbiorcy): array
{
    if (!floty_ladowanie_dostepne()) blad('Zaimportuj jeszcze api/migracje/024_ladowanie_okretow_tracyn.sql.');
    $db = polaczenie_bazy();
    $marks = implode(',', array_fill(0, count($odbiorcy), '?'));
    $q = $db->prepare("SELECT id, nazwa FROM mapa_obiekty WHERE id IN ($marks) AND ladowisko_id IS NOT NULL");
    $q->execute($odbiorcy);
    $ids = array_map('intval', array_column($q->fetchAll(PDO::FETCH_ASSOC), 'id'));
    if (!$ids) blad('Żadna z jednostek nie stoi na lądowisku.');
    $teraz = czas_teraz();
    // Miejsce na orbicie po starcie = kierunek lądowiska w chwili startu: w lokalne południe ku słońcu,
    // o północy od słońca (z cyklu planety i długości geograficznej lądowiska). Dziób skierowany na zewnątrz.
    $q = $db->prepare('SELECT o.id, p.nazwa AS planeta, l.x_km, l.y_km FROM mapa_obiekty o JOIN mapa_planety p ON p.id = o.planeta_id
        LEFT JOIN lokalizacje l ON l.id = o.ladowisko_id WHERE o.id = ?');
    $u = $db->prepare("UPDATE mapa_obiekty SET ladowisko_id = NULL, start_utc = ?, podejscie = 'bliska', status = 'na orbicie',
        orbita_deg = ?, kurs_deg = ?, kurs_start_deg = NULL, kurs_cel_deg = NULL, manewr_od_utc = NULL, manewr_do_utc = NULL WHERE id = ?");
    foreach ($ids as $id) {
        $q->execute([$id]);
        $o = $q->fetch(PDO::FETCH_ASSOC);
        $cykl = astro_cykl((string) $o['planeta']);
        [, $dl] = astro_wsp_lokacji(['x' => (float) ($o['x_km'] ?? 0), 'y' => (float) ($o['y_km'] ?? 0)], $cykl);
        $orbita = floty_kat(astro_kat_slonca($cykl, $teraz) + astro_kat_godzinny($cykl, $dl, $teraz));
        $u->execute([czas_utc($teraz), round($orbita, 2), round($orbita, 2), $id]);
    }
    return ['ok' => true, 'orbita' => $teraz + START_WZNOSZENIE_S];
}

function floty_ladowanie(array $odbiorcy, int $lokacjaId): array
{
    if (!floty_ladowanie_dostepne()) blad('Zaimportuj jeszcze api/migracje/024_ladowanie_okretow_tracyn.sql.');
    floty_aktualizuj_ruch();
    $db = polaczenie_bazy();
    $marks = implode(',', array_fill(0, count($odbiorcy), '?'));
    $q = $db->prepare("SELECT o.id, o.nazwa, o.planeta_id, o.ladowisko_id, o.podejscie, o.podlot_do_utc, o.start_utc, COALESCE(n.moze_ladowac, 0) AS moze
        FROM mapa_obiekty o LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id = o.id WHERE o.id IN ($marks)");
    $q->execute($odbiorcy);
    $jednostki = $q->fetchAll(PDO::FETCH_ASSOC);
    $q = $db->prepare('SELECT id, planeta_id, nazwa FROM lokalizacje WHERE id = ?');
    $q->execute([$lokacjaId]);
    $lok = $q->fetch(PDO::FETCH_ASSOC);
    if (!$lok) blad('Nie ma takiego lądowiska.');
    if (!in_array((int) $lok['id'], array_column(floty_ladowiska((int) $lok['planeta_id']), 'id'), true)) blad('Nie ma takiego lądowiska.');
    $teraz = czas_teraz();
    foreach ($jednostki as $j) {
        if (!(int) $j['moze']) blad('„' . $j['nazwa'] . '” jest za duża, żeby lądować.');
        if ($j['ladowisko_id']) blad('„' . $j['nazwa'] . '” już stoi na lądowisku.');
        if ((int) $j['planeta_id'] !== (int) $lok['planeta_id']) blad('To lądowisko leży na innej planecie.');
        if (($j['podejscie'] ?? 'bliska') !== 'bliska' || czas_z_utc($j['podlot_do_utc'] ?? null) > $teraz) blad('Do lądowania potrzebna jest bliska orbita.');
        if (czas_z_utc($j['start_utc'] ?? null) + START_WZNOSZENIE_S > $teraz) blad('„' . $j['nazwa'] . '” jeszcze się wznosi.');
    }
    floty_blokada_skoku($odbiorcy);
    $db->prepare("UPDATE mapa_obiekty SET ladowisko_id = ?, przylot_utc = ?, status = 'na lądowisku' WHERE id IN ($marks)")
        ->execute([$lokacjaId, czas_utc($teraz), ...$odbiorcy]);
    return ['ok' => true, 'ladowisko' => $lok['nazwa']];
}

function floty_blokada_skoku(array $odbiorcy): void
{
    $marks = implode(',', array_fill(0, count($odbiorcy), '?'));
    $q = polaczenie_bazy()->prepare("SELECT 1 FROM mapa_rozkazy_skokow_jednostki WHERE obiekt_id IN ($marks) AND status='w_nadprzestrzeni' LIMIT 1");
    $q->execute($odbiorcy);
    if ($q->fetchColumn()) blad('Jednostka wykonuje skok.');
}

function floty_kat(float $k): float
{
    return fmod(fmod($k, 360) + 360, 360);
}

// Najkrótsza zmiana kąta od $od do $do, w zakresie (−180, 180].
function floty_kat_roznica(float $od, float $do): float
{
    $r = floty_kat($do - $od);
    return $r > 180 ? $r - 360 : $r;
}

// Bieżący kurs jednostki (z uwzględnieniem trwającego manewru).
function floty_kurs_teraz(array $o, int $teraz): float
{
    if (!empty($o['manewr_do_utc']) && ($o['kurs_cel_deg'] ?? null) !== null && ($o['kurs_start_deg'] ?? null) !== null) {
        $od = czas_z_utc((string) $o['manewr_od_utc']);
        $do = czas_z_utc((string) $o['manewr_do_utc']);
        if ($teraz >= $do) return floty_kat((float) $o['kurs_cel_deg']);
        $p = max(0, min(1, ($teraz - $od) / max(1, $do - $od)));
        $start = (float) $o['kurs_start_deg'];
        return floty_kat($start + floty_kat_roznica($start, (float) $o['kurs_cel_deg']) * $p);
    }
    return floty_kat((float) ($o['kurs_deg'] ?? 90));
}

// Czas manewru w sekundach dla zmiany kursu o $roznica stopni przy tempie $obrot °/min.
function floty_czas_manewru(float $roznica, float $obrot): int
{
    return (int) ceil(abs($roznica) / max(1.0, $obrot) * 60);
}

// Ustawia manewr jednostki (albo od razu kurs, gdy nie ma czego obracać).
function floty_ustaw_manewr(PDO $db, int $id, float $kursStart, float $kursCel, int $od, int $do): void
{
    if ($do <= $od) {
        $db->prepare('UPDATE mapa_obiekty SET kurs_deg=?, kurs_start_deg=NULL, kurs_cel_deg=NULL, manewr_od_utc=NULL, manewr_do_utc=NULL WHERE id=?')
            ->execute([round(floty_kat($kursCel), 2), $id]);
        return;
    }
    $db->prepare('UPDATE mapa_obiekty SET kurs_start_deg=?, kurs_cel_deg=?, manewr_od_utc=?, manewr_do_utc=? WHERE id=?')
        ->execute([round(floty_kat($kursStart), 2), round(floty_kat($kursCel), 2), czas_utc($od), czas_utc($do), $id]);
}

// Kończy manewry, których czas minął.
function floty_domknij_manewry(): void
{
    if (!floty_kurs_dostepny()) return;
    polaczenie_bazy()->prepare('UPDATE mapa_obiekty SET kurs_deg=kurs_cel_deg, kurs_start_deg=NULL, kurs_cel_deg=NULL, manewr_od_utc=NULL, manewr_do_utc=NULL
        WHERE manewr_do_utc IS NOT NULL AND manewr_do_utc <= ?')->execute([czas_utc(czas_teraz())]);
    if (floty_nawigacja_dostepna()) {
        polaczenie_bazy()->prepare('UPDATE mapa_obiekty SET podejscie=podlot_cel, podlot_start=NULL, podlot_cel=NULL, podlot_od_utc=NULL, podlot_do_utc=NULL
            WHERE podlot_do_utc IS NOT NULL AND podlot_do_utc <= ?')->execute([czas_utc(czas_teraz())]);
    }
}

// Jednostki, którym gracz może wydać rozkaz: [id floty, lista id obiektów].
function floty_odbiorcy(string $login, array $w): array
{
    $db = polaczenie_bazy();
    $flotaId = (int) ($w['flota_id'] ?? 0);
    $obiektId = (int) ($w['obiekt_id'] ?? 0);
    $odbiorcy = [];
    if (isset($w['jednostki']) && is_array($w['jednostki'])) {
        $odbiorcy = array_values(array_unique(array_filter(array_map('intval', $w['jednostki']))));
        if (!$flotaId || !floty_czy_dowodca($flotaId, $login)) blad('Nie dowodzisz tą flotą.', 403);
        if (!$odbiorcy) blad('Zaznacz jednostki do rozkazu.');
        $marks = implode(',', array_fill(0, count($odbiorcy), '?'));
        $q = $db->prepare("SELECT obiekt_id,flota_id FROM mapa_floty_jednostki WHERE obiekt_id IN ($marks)");
        $q->execute($odbiorcy);
        $przypisania = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($przypisania) !== count($odbiorcy)) blad('Jedna z wybranych jednostek nie ma przypisanej floty.');
        foreach ($przypisania as $fid) if (!floty_czy_dowodca((int) $fid, $login)) blad('Nie dowodzisz wszystkimi wybranymi jednostkami.', 403);
    } elseif ($obiektId) {
        $m = floty_jednostka_moze($login, $obiektId);
        if (!$m) blad('Nie dowodzisz tą jednostką.', 403);
        $flotaId = $m['flota_id'];
        $odbiorcy = [$obiektId];
    } elseif ($flotaId && floty_czy_dowodca($flotaId, $login)) {
        $q = $db->prepare('SELECT obiekt_id FROM mapa_floty_jednostki WHERE flota_id=?');
        $q->execute([$flotaId]);
        $odbiorcy = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    } else blad('Nie dowodzisz tą flotą.', 403);
    if (!$odbiorcy) blad('Ta grupa nie ma jednostek.');
    floty_blokada_hangaru($odbiorcy);
    return [$flotaId, $odbiorcy];
}

// Zmiana kursu bez skoku (na orbicie: przelot na inną stronę planety). $mg: od razu, bez manewru.
function floty_kurs(array $odbiorcy, float $kurs, bool $natychmiast = false): array
{
    floty_aktualizuj_ruch();
    $kurs = floty_kat($kurs);
    $db = polaczenie_bazy();
    $marks = implode(',', array_fill(0, count($odbiorcy), '?'));
    $q = $db->prepare("SELECT 1 FROM mapa_rozkazy_skokow_jednostki WHERE obiekt_id IN ($marks) AND status='w_nadprzestrzeni' LIMIT 1");
    $q->execute($odbiorcy);
    if ($q->fetchColumn() && !$natychmiast) blad('Jednostki wykonujące skok nie zmienią teraz kursu.');
    if (!$natychmiast) { floty_blokada_ladowiska($odbiorcy); floty_blokada_lotu($odbiorcy); }
    $q = $db->prepare("SELECT o.id,o.nazwa,o.kurs_deg,o.kurs_start_deg,o.kurs_cel_deg,o.manewr_od_utc,o.manewr_do_utc,
            COALESCE(n.obrot_deg_na_min,90) AS obrot FROM mapa_obiekty o LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id=o.id
        WHERE o.id IN ($marks) AND o.aktywny=1");
    $q->execute($odbiorcy);
    $jednostki = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$jednostki) blad('Brak jednostek.');
    $teraz = czas_teraz();
    $obrot = min(array_map(fn($j) => (float) $j['obrot'] ?: 90.0, $jednostki));
    $koniec = $teraz;
    $db->beginTransaction();
    try {
        foreach ($jednostki as $j) {
            $start = floty_kurs_teraz($j, $teraz);
            $do = $natychmiast ? $teraz : $teraz + floty_czas_manewru(floty_kat_roznica($start, $kurs), $obrot);
            floty_ustaw_manewr($db, (int) $j['id'], $start, $kurs, $teraz, $do);
            $koniec = max($koniec, $do);
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return ['ok' => true, 'kurs' => $kurs, 'koniec' => $koniec];
}

function floty_dowodca(int $flotaId): ?string
{
    $db = polaczenie_bazy();
    $seen = [];
    while ($flotaId > 0 && !isset($seen[$flotaId])) {
        $seen[$flotaId] = true;
        $q = $db->prepare('SELECT f.dowodca_login,f.dowodca_osoba_id,f.rodzic_id,f.status,p.nazwa AS npc_nazwa FROM mapa_floty f LEFT JOIN osoby o ON o.podmiot_id=f.dowodca_osoba_id LEFT JOIN podmioty p ON p.id=o.podmiot_id WHERE f.id=?');
        $q->execute([$flotaId]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        if (($r['status'] ?? '') === 'nieaktywna') return null;
        if (trim((string) ($r['dowodca_login'] ?? '')) !== '') return (string) $r['dowodca_login'];
        if (trim((string) ($r['npc_nazwa'] ?? '')) !== '') return (string) $r['npc_nazwa'] . ' (NPC)';
        $flotaId = (int) ($r['rodzic_id'] ?? 0);
    }
    return null;
}

function floty_czy_dowodca(int $flotaId, string $login): bool
{
    $dowodca = floty_dowodca($flotaId);
    return $dowodca !== null && mb_strtolower($dowodca) === mb_strtolower($login);
}

function floty_moje_jednostki(string $login): array
{
    $q = polaczenie_bazy()->query('SELECT id,rodzic_id,dowodca_login FROM mapa_floty');
    $dozwolone = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $f) {
        if (floty_czy_dowodca((int) $f['id'], $login)) $dozwolone[(int) $f['id']] = true;
    }
    if (!$dozwolone) return [];
    $ids = array_keys($dozwolone);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $q = polaczenie_bazy()->prepare("SELECT j.obiekt_id,j.flota_id,j.rola,j.tie_liczba FROM mapa_floty_jednostki j WHERE j.flota_id IN ($marks)");
    $q->execute($ids);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function floty_aktualizuj_ruch(): void
{
    if (!tabela_sql_dostepna('mapa_rozkazy_skokow_jednostki')) return;
    floty_domknij_manewry();
    floty_aktualizuj_loty();
    // Jednostka przypisana do planety stoi na współrzędnych jej układu (porządkuje dawne przesunięcia,
    // przez które komputer brał „obok planety” za inny punkt i pozwalał skoczyć w jej studnię).
    polaczenie_bazy()->exec("UPDATE mapa_obiekty o JOIN mapa_planety p ON p.id = o.planeta_id SET o.x = p.x, o.y = p.y
        WHERE o.typ <> 'postac' AND (o.x <> p.x OR o.y <> p.y)" . (floty_lot_dostepny() ? ' AND o.lot_do_utc IS NULL' : ''));
    $db = polaczenie_bazy();
    $kurs = floty_kurs_dostepny();
    $uk = floty_uklad_dostepny();
    $q = $db->query("SELECT j.rozkaz_id,j.obiekt_id,j.start_x,j.start_y,j.wyjscie_x,j.wyjscie_y,j.eta_utc,j.paliwo_zuzyte,j.planeta_id,r.wydano_utc"
        . ($kurs ? ',r.wejscie_utc' : '') . (floty_nawigacja_dostepna() ? ',r.wariant' : '') . ($uk ? ',j.wyjscie_kx,j.wyjscie_ky' : '') . "
        FROM mapa_rozkazy_skokow_jednostki j JOIN mapa_rozkazy_skokow r ON r.id=j.rozkaz_id
        WHERE j.status='w_nadprzestrzeni' AND r.status='w_nadprzestrzeni'");
    $teraz = time();
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // Lot zaczyna się po manewrze (wejście w nadprzestrzeń); do tej pory jednostka stoi.
        $start = strtotime((string) (($r['wejscie_utc'] ?? null) ?: $r['wydano_utc']) . ' UTC');
        if ($start && $teraz < $start) continue;
        $eta = strtotime((string) $r['eta_utc'] . ' UTC');
        $koniecX = (float) $r['wyjscie_x'];
        $koniecY = (float) $r['wyjscie_y'];
        if (!$start || !$eta || $eta <= $start || $teraz >= $eta) {
            // Skok do układu: wyjście z rozrzutem w tolerancji automatu → w tym układzie. Inaczej, jeśli rozrzut
            // wyrzucił jednostkę w studni innego układu → na jego krawędzi. Poza strefami: pusta przestrzeń.
            // Wyjście w układzie celu: na orbicie planety (automat), w punkcie układu (km od słońca; rozrzut
            // do 5% promienia układu) albo — wariant „krawędź układu” — na brzegu całego układu od strony
            // nadlotu. Rozrzut, który wyrzucił jednostkę w innym układzie: na jego brzegu.
            $planeta = (int) ($r['planeta_id'] ?? 0) ?: null;
            $k = null;
            $sx0 = (float) $r['start_x']; $sy0 = (float) $r['start_y'];
            if ($planeta !== null) {
                $wsp = floty_wsp_planety($planeta);
                $chybienie = $wsp ? hypot($koniecX - $wsp[0], $koniecY - $wsp[1]) : INF;
                if ($chybienie <= TOLERANCJA_AUTOMATU_PC) {
                    if ($uk && ($r['wyjscie_kx'] ?? null) !== null) {
                        $kraw = (float) (astro_cykl((string) ruch_planeta((string) $planeta)['nazwa'])['krawedz_au'] ?? ASTRO_KRAWEDZ_AU) * ASTRO_KM_NA_AU;
                        $odchyl = min($chybienie * KM_NA_PC, 0.05 * $kraw);
                        $a = atan2($koniecY - $wsp[1], $koniecX - $wsp[0]);
                        $k = [(float) $r['wyjscie_kx'] + cos($a) * $odchyl, (float) $r['wyjscie_ky'] + sin($a) * $odchyl];
                    } elseif ($uk && ($r['wariant'] ?? null) === 'krawedz') {
                        $k = floty_krawedz_km($wsp[0], $wsp[1], $sx0, $sy0);
                    }
                    [$koniecX, $koniecY] = $wsp;
                    if ($k) $planeta = null;
                } else $planeta = null;
            }
            if ($planeta === null && !$k && ($inny = floty_planeta_w_punkcie($koniecX, $koniecY))) {
                [$koniecX, $koniecY] = floty_wsp_planety($inny);
                if ($uk) $k = floty_krawedz_km($koniecX, $koniecY, $sx0, $sy0);
                else $planeta = $inny;
            }
            $db->prepare('UPDATE mapa_obiekty SET x=?,y=?,planeta_id=? WHERE id=?')->execute([$koniecX, $koniecY, $planeta, $r['obiekt_id']]);
            if ($uk) floty_ustaw_k($db, (int) $r['obiekt_id'], $planeta ? null : $k);
            $krawedz = false;
            if ($kurs) {
                // Po wyjściu z nadprzestrzeni: w przestrzeni dziób w kierunku lotu; przy planecie
                // jednostka staje na orbicie po stronie, z której przyleciała (rufa ku planecie).
                // Skok w obrębie układu (te same współrzędne mapy): kurs ustawiony przed skokiem zostaje.
                $daleki = hypot($koniecX - $sx0, $koniecY - $sy0) > 1e-6;
                $kierunek = $daleki ? floty_kat(rad2deg(atan2($koniecY - $sy0, $koniecX - $sx0))) : 0.0;
                if ($daleki) $db->prepare('UPDATE mapa_obiekty SET kurs_deg=?, kurs_start_deg=NULL, kurs_cel_deg=NULL, manewr_od_utc=NULL, manewr_do_utc=NULL WHERE id=?')
                    ->execute([round($kierunek, 2), $r['obiekt_id']]);
                if (floty_orbita_dostepna()) {
                    // Przy planecie: orbita po stronie przylotu, dziób (kurs lotu) ku planecie.
                    $db->prepare('UPDATE mapa_obiekty SET orbita_deg=?, przylot_utc=?, rozbity=0 WHERE id=?')
                        ->execute([$planeta ? round(floty_kat($kierunek + 180), 2) : null, $planeta || $k ? czas_utc(min($eta ?: $teraz, $teraz)) : null, $r['obiekt_id']]);
                    if (floty_nawigacja_dostepna()) {
                        $db->prepare('UPDATE mapa_obiekty SET podejscie=? WHERE id=?')
                            ->execute([$planeta ? ($krawedz ? 'krawedz' : (($r['wariant'] ?? null) ?: 'daleka')) : null, $r['obiekt_id']]);
                    }
                }
            }
            $db->prepare('UPDATE mapa_naped_jednostki SET paliwo=GREATEST(0,paliwo-?) WHERE obiekt_id=?')->execute([$r['paliwo_zuzyte'], $r['obiekt_id']]);
            $db->prepare("UPDATE mapa_rozkazy_skokow_jednostki SET status='dotarl' WHERE rozkaz_id=? AND obiekt_id=?")->execute([$r['rozkaz_id'], $r['obiekt_id']]);
            continue;
        }
        $postep = max(0, min(1, ($teraz - $start) / ($eta - $start)));
        $x = (float) $r['start_x'] + ($koniecX - (float) $r['start_x']) * $postep;
        $y = (float) $r['start_y'] + ($koniecY - (float) $r['start_y']) * $postep;
        $db->prepare('UPDATE mapa_obiekty SET x=?,y=?,planeta_id=NULL WHERE id=?')->execute([$x, $y, $r['obiekt_id']]);
    }
    $db->exec("UPDATE mapa_rozkazy_skokow r SET r.status='dotarl',r.zakonczono_utc=UTC_TIMESTAMP()
        WHERE r.status='w_nadprzestrzeni' AND NOT EXISTS (
          SELECT 1 FROM mapa_rozkazy_skokow_jednostki j WHERE j.rozkaz_id=r.id AND j.status='w_nadprzestrzeni'
        )");
    floty_aktualizuj_hangary();
}

function floty_lista(string $login, bool $mg = false): array
{
    floty_aktualizuj_ruch();
    $db = polaczenie_bazy();
    $all = $db->query("SELECT f.id,f.kod,f.nazwa,f.dowodca_login,f.dowodca_osoba_id,p.nazwa AS dowodca_npc_nazwa,f.rodzic_id,f.status,f.opis FROM mapa_floty f LEFT JOIN osoby o ON o.podmiot_id=f.dowodca_osoba_id LEFT JOIN podmioty p ON p.id=o.podmiot_id WHERE f.status<>'nieaktywna' ORDER BY f.nazwa")->fetchAll(PDO::FETCH_ASSOC);
    $dozwolone = [];
    foreach ($all as $f) if ($mg || floty_czy_dowodca((int) $f['id'], $login)) $dozwolone[(int) $f['id']] = true;
    $out = [];
    $q = $db->prepare("SELECT o.id AS obiekt_id,o.kod AS obiekt_kod,o.nazwa AS obiekt_nazwa,o.typ,o.rodzaj,o.klasa,o.status AS stan,o.planeta_id,p.nazwa AS planeta,o.x,o.y,
        j.flota_id,j.rola,j.kolejnosc,j.tie_liczba,
        COALESCE(n.moc_reaktora,100) AS moc_reaktora,COALESCE(n.moc_hipernapedu,80) AS moc_hipernapedu,COALESCE(n.klasa_hipernapedu,1) AS klasa_hipernapedu,
        COALESCE(n.predkosc_pc_na_godzine,1000) AS predkosc_pc_na_godzine,COALESCE(n.paliwo,100000) AS paliwo,COALESCE(n.paliwo_maks,100000) AS paliwo_maks,
        COALESCE(n.paliwo_na_parsek,0.01) AS paliwo_na_parsek,COALESCE(n.sprawnosc_reaktora,100) AS sprawnosc_reaktora,COALESCE(n.sprawnosc_hipernapedu,100) AS sprawnosc_hipernapedu,COALESCE(n.szum_bazowy,0.05) AS szum_bazowy,
        COALESCE(n.obrot_deg_na_min,90) AS obrot_deg_na_min,COALESCE(n.komputer_klasa,3) AS komputer_klasa,COALESCE(n.komputer_stan,'sprawny') AS komputer_stan," . (liczniki_dostepne() ? 'COALESCE(n.oslony,100) AS oslony,COALESCE(n.kadlub,100) AS kadlub,' : '') . "
        o.podejscie,o.podlot_start,o.podlot_cel,o.podlot_od_utc,o.podlot_do_utc,o.orbita_deg,o.rozbity,"
        . (floty_ladowanie_dostepne() ? 'o.ladowisko_id,o.start_utc,COALESCE(n.moze_ladowac,0) AS moze_ladowac,(SELECT nazwa FROM lokalizacje WHERE id=o.ladowisko_id) AS ladowisko,' : '')
        . (mapa_frakcja_dostepna() ? 'o.frakcja,' : '')
        . (floty_hangar_dostepny() ? 'o.w_hangarze,(SELECT nazwa FROM mapa_obiekty WHERE id=o.okret_macierzysty_id) AS okret_macierzysty,(SELECT nazwa FROM lokalizacje WHERE id=o.ladowanie_cel_id) AS cel_ladowania,' : '')
        . (function_exists('ruch_lokalizator_dostepny') && ruch_lokalizator_dostepny() ? 'o.wlasciciel_login,o.lokalizator_zasieg_pc,o.lokalizator_aktywny,' : '')
        . (floty_punkt_dostepny() ? 'COALESCE(n.predkosc_podswietlna_kms,300) AS predkosc_podswietlna_kms,' : '')
        . (floty_przyspieszenie_dostepne() ? 'COALESCE(n.podswietlna_pc_doba,20) AS podswietlna_pc_doba,' : '')
        . (floty_lot_dostepny() ? 'o.lot_cel_x,o.lot_cel_y,o.lot_od_utc,o.lot_do_utc,(SELECT nazwa FROM mapa_planety WHERE id=o.lot_cel_planeta_id) AS lot_cel_planeta,' : '') . "o.kurs_deg,o.kurs_start_deg,o.kurs_cel_deg,o.manewr_od_utc,o.manewr_do_utc,
        r.id AS rozkaz_id,r.status AS rozkaz_status,r.cel_x,r.cel_y,r.eta_utc,r.wejscie_utc,r.wariant
        FROM mapa_floty_jednostki j JOIN mapa_obiekty o ON o.id=j.obiekt_id
        LEFT JOIN mapa_planety p ON p.id=o.planeta_id LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id=o.id
        LEFT JOIN mapa_rozkazy_skokow_jednostki rj ON rj.obiekt_id=o.id AND rj.status='w_nadprzestrzeni'
        LEFT JOIN mapa_rozkazy_skokow r ON r.id=rj.rozkaz_id AND r.status='w_nadprzestrzeni'
        WHERE j.flota_id=? ORDER BY j.kolejnosc,o.nazwa");
    foreach ($all as $f) {
        if (!isset($dozwolone[(int) $f['id']])) continue;
        $q->execute([(int) $f['id']]);
        $units = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($units as &$u) {
            foreach (['obiekt_id','flota_id','tie_liczba','kolejnosc','planeta_id','rozkaz_id'] as $k) if ($u[$k] !== null) $u[$k] = (int) $u[$k];
            foreach (['x','y','moc_reaktora','moc_hipernapedu','klasa_hipernapedu','predkosc_pc_na_godzine','paliwo','paliwo_maks','szum_bazowy','cel_x','cel_y'] as $k) if ($u[$k] !== null) $u[$k] = (float) $u[$k];
            if ($u['eta_utc']) $u['eta'] = strtotime($u['eta_utc'] . ' UTC');
            if ($u['wejscie_utc']) $u['wejscie'] = strtotime($u['wejscie_utc'] . ' UTC');
            $u['kurs'] = floty_kurs_teraz($u, czas_teraz());
            if ($u['manewr_do_utc']) { $u['manewr_do'] = strtotime($u['manewr_do_utc'] . ' UTC'); $u['kurs_cel'] = (float) $u['kurs_cel_deg']; }
            $u['obrot_deg_na_min'] = (float) $u['obrot_deg_na_min'];
            $u['podejscie_teraz'] = floty_podejscie_teraz($u, czas_teraz());
            if ($u['podlot_do_utc']) $u['podlot_do'] = strtotime($u['podlot_do_utc'] . ' UTC');
            if (!empty($u['lot_do_utc'])) $u['lot'] = ['od' => czas_z_utc($u['lot_od_utc']), 'do' => czas_z_utc($u['lot_do_utc']),
                'x' => (float) $u['lot_cel_x'], 'y' => (float) $u['lot_cel_y'], 'planeta' => $u['lot_cel_planeta']];
            // Gracz widzi tylko, czy automat odpowiada (zhakowany wygląda jak sprawny).
            $u['automat'] = in_array($u['komputer_stan'], ['sprawny', 'zhakowany'], true);
            if (isset($u['w_hangarze'])) $u['w_hangarze'] = (bool) (int) $u['w_hangarze'];
            if (!$mg) unset($u['komputer_stan']);
        }
        unset($u);
        $out[] = $f + ['dowodca_efektywny' => floty_dowodca((int) $f['id']), 'jednostki' => $units];
    }
    return $out;
}

function floty_jednostka_moze(string $login, int $obiektId): ?array
{
    $q = polaczenie_bazy()->prepare('SELECT j.flota_id FROM mapa_floty_jednostki j WHERE j.obiekt_id=?');
    $q->execute([$obiektId]);
    $flotaId = $q->fetchColumn();
    if (!$flotaId || !floty_czy_dowodca((int) $flotaId, $login)) return null;
    return ['flota_id' => (int) $flotaId];
}

function floty_odbiorca_widoczny(string $login, int $obiektId): bool
{
    if (floty_jednostka_moze($login, $obiektId)) return true;
    $q = polaczenie_bazy()->prepare('SELECT 1 FROM mapa_obiekty_dostep WHERE obiekt_id=? AND LOWER(konto_login)=LOWER(?)');
    $q->execute([$obiektId, $login]);
    return (bool) $q->fetchColumn();
}

function floty_rozkaz(string $login, array $w): array
{
    $db = polaczenie_bazy();
    floty_aktualizuj_ruch();
    [$flotaId, $odbiorcy] = floty_odbiorcy($login, $w);
    floty_blokada_ladowiska($odbiorcy);
    floty_blokada_lotu($odbiorcy);
    [$klasaKomputera, $stanKomputera, $automat] = floty_komputer($odbiorcy);
    $celObiekt = (int) ($w['cel_obiekt_id'] ?? 0);
    $celPlaneta = trim((string) ($w['cel_planeta'] ?? ''));
    $wariant = null;
    $celPlanetaId = null;
    $tryb = $automat ? 'automat' : 'reczny';
    if ($celPlaneta !== '') {
        // Automat: skok na planetę z wybranym wariantem orbity.
        if (!$automat) blad('Komputer nawigacyjny nie odpowiada. Mostek musi wyliczyć skok ręcznie: podaj współrzędne.');
        $wariant = in_array($w['wariant'] ?? '', NAV_WARIANTY, true) ? $w['wariant'] : 'bliska';
        $q = $db->prepare('SELECT id,x,y FROM mapa_planety WHERE nazwa=? LIMIT 1');
        $q->execute([$celPlaneta]);
        $pl = $q->fetch(PDO::FETCH_ASSOC);
        if (!$pl || !mapa_planeta_widoczna((int) $pl['id'], ja())) blad('Komputer nawigacyjny nie zna tej planety.');
        $x = (float) $pl['x']; $y = (float) $pl['y'];
        $celPlanetaId = (int) $pl['id'];
    } elseif ($celObiekt) {
        if (!floty_odbiorca_widoczny($login, $celObiekt)) blad('Nie masz dostępu do pozycji wskazanej jednostki.', 403);
        $q = $db->prepare('SELECT x,y,planeta_id FROM mapa_obiekty WHERE id=? AND aktywny=1');
        $q->execute([$celObiekt]);
        $cel = $q->fetch(PDO::FETCH_ASSOC);
        if (!$cel || $cel['x'] === null || $cel['y'] === null) blad('Wskazana jednostka nie ma pozycji.');
        if ($cel['planeta_id'] !== null && !mapa_planeta_widoczna((int) $cel['planeta_id'], ja())) blad('Nie masz dostępu do wskazanej pozycji.', 403);
        $x = (float) $cel['x']; $y = (float) $cel['y'];
        $celPlanetaId = $cel['planeta_id'] !== null ? (int) $cel['planeta_id'] : floty_planeta_w_punkcie($x, $y);
        if ($celPlanetaId && ($wsp = floty_wsp_planety($celPlanetaId))) [$x, $y] = $wsp;
    } else {
        $x = $w['x'] ?? null; $y = $w['y'] ?? null;
        if (!is_numeric($x) || !is_numeric($y)) blad('Podaj współrzędne X i Y albo wskaż inną jednostkę.');
        $x = (float) $x; $y = (float) $y;
        if (abs($x) > 1000000 || abs($y) > 1000000) blad('Współrzędne wykraczają poza zakres mapy.');
        // Punkt w studni grawitacyjnej układu: komputer prowadzi skok do tego układu (nie w głąb studni).
        $planetaCel = floty_planeta_w_punkcie($x, $y);
        if ($planetaCel && !mapa_planeta_widoczna($planetaCel, ja())) blad('Nie masz dostępu do wskazanego obszaru.', 403);
        if ($planetaCel) { $celPlanetaId = $planetaCel; [$x, $y] = floty_wsp_planety($planetaCel); }
        // Punkt w układzie (km od słońca): wyjście dokładnie tam (poza studniami ciał i w granicach układu).
        if ($planetaCel && floty_uklad_dostepny() && is_numeric($w['kx'] ?? null) && is_numeric($w['ky'] ?? null)) {
            $celK = [(float) $w['kx'], (float) $w['ky']];
            if ($s = floty_punkt_w_studni($x, $y, $celK, czas_teraz())) blad('Komputer nawigacyjny nie wyprowadzi skoku w studni grawitacyjnej ' . $s . '.');
            $kraw = (float) (astro_cykl((string) ruch_planeta((string) $planetaCel)['nazwa'])['krawedz_au'] ?? ASTRO_KRAWEDZ_AU) * ASTRO_KM_NA_AU;
            if (hypot($celK[0], $celK[1]) > $kraw * 1.5) blad('Ten punkt leży poza układem. Wskaż punkt w pustej przestrzeni.');
        }
    }
    $celK ??= null;
    $ids = implode(',', array_fill(0, count($odbiorcy), '?'));
    $q = $db->prepare("SELECT o.id,o.nazwa,o.x,o.y,o.planeta_id AS planeta_startu,o.planeta_id,o.kurs_deg,o.kurs_start_deg,o.kurs_cel_deg,o.manewr_od_utc,o.manewr_do_utc,
            o.orbita_deg,o.podejscie,o.podlot_start,o.podlot_cel,o.podlot_od_utc,o.podlot_do_utc," . (floty_uklad_dostepny() ? 'o.uklad_kx,o.uklad_ky,' : '') . "n.* FROM mapa_obiekty o LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id=o.id WHERE o.id IN ($ids) AND o.aktywny=1");
    $q->execute($odbiorcy);
    $jednostki = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($jednostki) !== count($odbiorcy)) blad('Brak pozycji lub parametrów napędu jednej z jednostek.');
    $teraz = czas_teraz(); $wyliczone = [];
    // Flota manewruje razem: tempem obrotu najwolniejszej jednostki.
    $obrotFloty = min(array_map(fn($j) => (float) ($j['obrot_deg_na_min'] ?? 90) ?: 90.0, $jednostki));
    foreach ($jednostki as $j) {
        if ($j['x'] === null || $j['y'] === null) blad('Jednostka „' . $j['nazwa'] . '” nie ma pozycji mapowej.');
        $aktywny = $db->prepare("SELECT 1 FROM mapa_rozkazy_skokow_jednostki WHERE obiekt_id=? AND status='w_nadprzestrzeni' LIMIT 1");
        $aktywny->execute([$j['id']]);
        if ($aktywny->fetchColumn()) blad('Jednostka „' . $j['nazwa'] . '” już wykonuje skok.');
        if ($j['moc_reaktora'] === null) blad('Jednostka „' . $j['nazwa'] . '” nie ma profilu napędu.');
        $dx = $x - (float) $j['x']; $dy = $y - (float) $j['y']; $dystans = hypot($dx, $dy);
        if ($dystans < STREFA_UKLADU_PC) {
            // Skok w obrębie układu: tylko do punktu układu, z miejsca poza studniami (długość w km).
            $k0 = floty_uklad_dostepny() ? floty_poz_km($j, $teraz) : null;
            // Mikroskok do planety tego układu (automat): wyjście na jej orbicie w wybranym wariancie.
            if ($celPlanetaId && !$celK && (int) ($j['planeta_id'] ?? 0) === $celPlanetaId) blad('Jednostka „' . $j['nazwa'] . '” jest już na orbicie tej planety. Orbitę zmień dolotem.');
            if ($celPlanetaId && !$celK && $wariant === 'krawedz') blad('Jednostka „' . $j['nazwa'] . '” jest już w tym układzie. Na jego brzeg leć dolotem albo wskaż punkt brzegu na mapie.');
            $kCel = $celK ?? ($celPlanetaId && $k0 ? floty_planeta_km($celPlanetaId, $teraz) : null);
            if (!$kCel || !$k0) blad('Jednostka „' . $j['nazwa'] . '” jest już w tym układzie. Do planet i księżyców układu leć podświetlnie, orbitę zmień dolotem.');
            $dx = $kCel[0] - $k0[0]; $dy = $kCel[1] - $k0[1];
            $dystans = hypot($dx, $dy) / KM_NA_PC;
        }
        // Krawędź układu: wyjście na brzegu, przed ciałami układu — sprawdza się tylko układ startu.
        $doKrawedzi = $wariant === 'krawedz';
        $kolizja = floty_uklad_dostepny()
            ? floty_trasa_skoku($j, $x, $y, $doKrawedzi ? null : $celK, $celK || $doKrawedzi ? null : $celPlanetaId, $teraz)
            : floty_trasa_kolizja((float) $j['x'], (float) $j['y'], $x, $y);
        if ($kolizja) {
            blad('Komputer nawigacyjny odrzuca trasę: skok jednostki „' . $j['nazwa'] . '” przecina studnię grawitacyjną ' . $kolizja . '. Wybierz inny cel albo skacz etapami.');
        }
        $stanReaktora = max(0, min(100, (int) $j['sprawnosc_reaktora'])) / 100;
        $stanNapedu = max(0, min(100, (int) $j['sprawnosc_hipernapedu'])) / 100;
        $mocWzgl = ((float) $j['moc_reaktora'] * $stanReaktora) / max(0.01, (float) $j['moc_hipernapedu']);
        if ($mocWzgl < 0.25) blad('Reaktor jednostki „' . $j['nazwa'] . '” nie zapewnia mocy do skoku.');
        $mnoznikMocy = min(1, $mocWzgl);
        $szybkosc = max(0.01, (float) $j['predkosc_pc_na_godzine']) / max(0.1, (float) $j['klasa_hipernapedu']) * $mnoznikMocy * max(0.1, $stanNapedu);
        $czasGryGodz = $dystans / $szybkosc;
        $czasLotu = czas_trwania($czasGryGodz * 60);
        // Przed skokiem jednostka staje na kursie skoku (na orbicie: przelatuje na stronę planety
        // zwróconą ku celowi, dziobem od planety).
        $kursSkoku = floty_kat(rad2deg(atan2($dy, $dx)));
        $kursTeraz = floty_kurs_teraz($j, $teraz);
        $manewr = floty_czas_manewru(floty_kat_roznica($kursTeraz, $kursSkoku), $obrotFloty);
        $punkt = floty_czas_do_punktu($j, $kursSkoku, $teraz);
        $paliwoZuzyte = $dystans * (float) $j['paliwo_na_parsek'];
        if ((float) $j['paliwo'] < $paliwoZuzyte) blad('Jednostka „' . $j['nazwa'] . '” ma za mało paliwa.');
        $bladMax = max((float) $j['szum_bazowy'], 0) + (100 - min((int) $j['sprawnosc_reaktora'], (int) $j['sprawnosc_hipernapedu'])) * 0.3 + (1 - $mnoznikMocy) * 10;
        // Komputer: klasa 5 dzieli rozrzut przez 3, klasa 1 zwiększa go o 2/3; obliczenia ręczne ×2,5;
        // komputer zhakowany po cichu ×6 (gra tego nie tłumaczy).
        $bladMax *= $tryb === 'reczny' ? 2.5 : (6 - $klasaKomputera) / 3;
        if ($stanKomputera === 'zhakowany') $bladMax *= 6;
        $kat = random_int((int) round(-$bladMax * 1000), (int) round($bladMax * 1000)) / 1000;
        $kierunek = atan2($dy, $dx) + deg2rad($kat);
        // Skok w obrębie układu: rozrzut liczy się w km od celu (wyjście tam, gdzie wskazano, ± błąd).
        $wUkladzie = hypot($x - (float) $j['x'], $y - (float) $j['y']) < STREFA_UKLADU_PC;
        $koniecX = $wUkladzie ? $x + $dystans * (cos($kierunek) - cos(atan2($dy, $dx))) : (float) $j['x'] + $dystans * cos($kierunek);
        $koniecY = $wUkladzie ? $y + $dystans * (sin($kierunek) - sin(atan2($dy, $dx))) : (float) $j['y'] + $dystans * sin($kierunek);
        $wyliczone[] = ['id' => (int) $j['id'], 'nazwa' => $j['nazwa'], 'x0' => (float) $j['x'], 'y0' => (float) $j['y'],
            'x1' => $koniecX, 'y1' => $koniecY, 'kat' => $kat, 'moc' => $mnoznikMocy, 'paliwo' => $paliwoZuzyte, 'lot' => $czasLotu,
            'planeta' => (int) ($j['planeta_startu'] ?? 0), 'kurs0' => $kursTeraz, 'kurs' => $kursSkoku, 'manewr' => $manewr, 'punkt' => $punkt];
    }
    // Obliczenia skoku (automat: sekundy zależne od klasy komputera; mostek ręcznie: 20 min),
    // potem obrót na kurs; wejście w nadprzestrzeń, gdy ostatnia jednostka stanie na kursie.
    $obliczenia = $tryb === 'reczny' ? NAV_RECZNE_OBLICZENIA_S : (6 - $klasaKomputera) * 30;
    $start = $teraz + $obliczenia;
    $punktOd = $start + max(array_column($wyliczone, 'manewr'));
    // Wejście, gdy ostatnia jednostka obróci się i doleci do punktu skoku.
    $wejscie = $start + max(array_map(fn($j) => $j['manewr'] + $j['punkt'], $wyliczone));
    foreach ($wyliczone as &$wj) $wj['eta'] = $wejscie + $wj['lot'];
    unset($wj);
    $najpozniej = max(array_column($wyliczone, 'eta'));
    if (!empty($w['plan'])) {
        // Tylko plan (bez rozkazu): co pokaże automat albo mostek przed potwierdzeniem.
        return ['ok' => true, 'plan' => true, 'tryb' => $tryb, 'wariant' => $wariant, 'obliczenia_s' => $obliczenia,
            'manewr_s' => $punktOd - $start, 'punkt_s' => $wejscie - $punktOd, 'lot_s' => $najpozniej - $wejscie, 'wejscie' => $wejscie, 'eta' => $najpozniej,
            'jednostki' => array_map(static fn($j) => ['nazwa' => $j['nazwa'], 'paliwo' => round($j['paliwo'], 1), 'eta' => $j['eta']], $wyliczone)];
    }
    $db->beginTransaction();
    try {
        $kod = 'skok-' . bin2hex(random_bytes(8));
        $q = $db->prepare("INSERT INTO mapa_rozkazy_skokow (kod,wydajacy_login,flota_id,cel_obiekt_id,cel_x,cel_y,kurs_deg,wariant,tryb,status,wydano_utc,wejscie_utc,eta_utc,opis)
            VALUES (?,?,?,?,?,?,?,?,?,'w_nadprzestrzeni',?,?,?,?)");
        $q->execute([$kod, $login, $flotaId ?: null, $celObiekt ?: null, $x, $y, round($wyliczone[0]['kurs'], 2), $wariant, $tryb, czas_utc($teraz), czas_utc($wejscie), czas_utc($najpozniej), 'Automatyczny skok: reaktor, moc hipernapędu i odchylenie nawigacyjne.']);
        $rozkazId = (int) $db->lastInsertId();
        if (floty_punkt_dostepny()) $db->prepare('UPDATE mapa_rozkazy_skokow SET punkt_od_utc=? WHERE id=?')->execute([czas_utc($punktOd), $rozkazId]);
        // ETA z planu (szacunek komputera); resynchronizacja zmienia tylko eta_utc.
        if (function_exists('liczniki_dostepne') && liczniki_dostepne()) {
            $db->prepare('UPDATE mapa_rozkazy_skokow SET eta_plan_utc=eta_utc WHERE id=?')->execute([$rozkazId]);
        }
        $q = $db->prepare("INSERT INTO mapa_rozkazy_skokow_jednostki (rozkaz_id,obiekt_id,start_x,start_y,wyjscie_x,wyjscie_y,planeta_id,kat_bledu,moc_wzgl,paliwo_zuzyte,eta_utc,status)
            VALUES (?,?,?,?,?,?,?, ?,?,?,?,'w_nadprzestrzeni')");
        foreach ($wyliczone as $j) floty_ustaw_manewr($db, $j['id'], $j['kurs0'], $j['kurs'], $start, $start + $j['manewr']);
        // Wylot z układu przerywa dolot.
        $db->prepare('UPDATE mapa_obiekty SET podlot_start=NULL, podlot_cel=NULL, podlot_od_utc=NULL, podlot_do_utc=NULL WHERE id IN (' . implode(',', array_fill(0, count($wyliczone), '?')) . ')')
            ->execute(array_column($wyliczone, 'id'));
        foreach ($wyliczone as $j) $q->execute([$rozkazId,$j['id'],$j['x0'],$j['y0'],$j['x1'],$j['y1'],$celPlanetaId ?: null,$j['kat'],$j['moc'],$j['paliwo'],czas_utc($j['eta'])]);
        if ($celK) $db->prepare('UPDATE mapa_rozkazy_skokow_jednostki SET wyjscie_kx=?, wyjscie_ky=? WHERE rozkaz_id=?')->execute([round($celK[0], 1), round($celK[1], 1), $rozkazId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    // Radar: jednostki przy planecie startu widzą skok całego zgrupowania jako jedno wykrycie.
    $pierwsza = $wyliczone[0];
    $ile = count($wyliczone);
    radar_wykryj_skok($pierwsza['planeta'], $pierwsza['x0'], $pierwsza['y0'],
        array_sum(array_column($wyliczone, 'x1')) / $ile, array_sum(array_column($wyliczone, 'y1')) / $ile, $najpozniej,
        'flota', (string) $rozkazId, $ile === 1 ? 'Sygnatura: ' . $pierwsza['nazwa'] : "Zgrupowanie: {$ile} jednostek",
        array_column($wyliczone, 'id'), $wejscie);
    return ['ok' => true, 'rozkaz_id' => $rozkazId, 'cel' => ['x' => $x, 'y' => $y], 'eta' => $najpozniej, 'wejscie' => $wejscie,
        'manewr_s' => $punktOd - $teraz, 'punkt_s' => $wejscie - $punktOd, 'tryb' => $tryb, 'wariant' => $wariant,
        'jednostki' => array_map(static fn($j) => ['id' => $j['id'],'nazwa' => $j['nazwa'],'szum_stopnie' => $j['kat'],'eta' => $j['eta']], $wyliczone)];
}

// ---------- przerwanie skoku ----------
// Przed wejściem w nadprzestrzeń (obliczenia, obrót, lot do punktu skoku): bez skutków. W nadprzestrzeni:
// gwałtowne wyjście tam, dokąd jednostka doleciała (w studni układu — na jego krawędzi), część paliwa
// zużyta, uszkodzenia reaktora, hipernapędu, osłon i kadłuba. Mniejsze przy lepszych podzespołach
// (jakosc_podzespolow 1–5) i przy zapasie mocy reaktora nad hipernapędem. Gra nie podaje liczb.
const PRZERWANIE_USZKODZENIA = ['sprawnosc_reaktora' => [10, 30], 'sprawnosc_hipernapedu' => [15, 40], 'oslony' => [5, 15], 'kadlub' => [2, 8]];
const PRZERWANIE_JAKOSC = [1 => 1.6, 2 => 1.3, 3 => 1.0, 4 => 0.7, 5 => 0.4];

function floty_przerwij_skok(array $u, int $id): array
{
    $db = polaczenie_bazy();
    $q = $db->prepare("SELECT id, flota_id, wydajacy_login, COALESCE(wejscie_utc, wydano_utc) AS wejscie FROM mapa_rozkazy_skokow WHERE id=? AND status='w_nadprzestrzeni'");
    $q->execute([$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) blad('Ten skok już się zakończył.');
    $mozna = ($u['rola'] ?? '') === 'mg' || mb_strtolower((string) $r['wydajacy_login']) === mb_strtolower((string) $u['login'])
        || ($r['flota_id'] && floty_czy_dowodca((int) $r['flota_id'], (string) $u['login']));
    if (!$mozna) blad('Nie możesz przerwać tego skoku.', 403);
    floty_aktualizuj_ruch(); // pozycje w nadprzestrzeni na tę chwilę
    $teraz = czas_teraz();
    $wNadprzestrzeni = $teraz >= czas_z_utc((string) $r['wejscie']);
    $jakosc = false;
    try { $db->query('SELECT jakosc_podzespolow FROM mapa_naped_jednostki LIMIT 0'); $jakosc = true; } catch (Throwable $e) {}
    $q = $db->prepare("SELECT o.id, o.x, o.y, o.kurs_deg, o.kurs_start_deg, o.kurs_cel_deg, o.manewr_od_utc, o.manewr_do_utc,
            j.start_x, j.start_y, j.wyjscie_x, j.wyjscie_y, j.paliwo_zuzyte, j.eta_utc,
            COALESCE(n.moc_reaktora,100) AS moc_reaktora, COALESCE(n.moc_hipernapedu,80) AS moc_hipernapedu,
            COALESCE(n.sprawnosc_reaktora,100) AS sprawnosc_reaktora" . ($jakosc ? ', COALESCE(n.jakosc_podzespolow,3) AS jakosc' : '') . "
        FROM mapa_obiekty o JOIN mapa_rozkazy_skokow_jednostki j ON j.obiekt_id=o.id LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id=o.id
        WHERE j.rozkaz_id=? AND j.status='w_nadprzestrzeni'");
    $q->execute([$id]);
    $jednostki = $q->fetchAll(PDO::FETCH_ASSOC);
    $kolumny = ['sprawnosc_reaktora', 'sprawnosc_hipernapedu'];
    if (function_exists('liczniki_dostepne') && liczniki_dostepne()) array_push($kolumny, 'oslony', 'kadlub');
    $db->beginTransaction();
    try {
        foreach ($jednostki as $o) {
            $k = floty_kurs_teraz($o, $teraz);
            floty_ustaw_manewr($db, (int) $o['id'], $k, $k, 0, 0);
            if (!$wNadprzestrzeni) continue;
            // Wyjście w miejscu, do którego jednostka doleciała; w układzie — na brzegu całego układu
            // (od strony nadlotu), bez migracji 037 — przy planecie na krawędzi.
            $x = round((float) $o['x'], 1); $y = round((float) $o['y'], 1);
            $planeta = floty_planeta_w_punkcie($x, $y);
            if ($planeta) [$x, $y] = floty_wsp_planety($planeta);
            $kBrzeg = $planeta && floty_uklad_dostepny() ? floty_krawedz_km($x, $y, (float) $o['start_x'], (float) $o['start_y']) : null;
            if ($kBrzeg) $planeta = null;
            $db->prepare('UPDATE mapa_obiekty SET x=?, y=?, planeta_id=? WHERE id=?')->execute([$x, $y, $planeta, $o['id']]);
            floty_ustaw_k($db, (int) $o['id'], $kBrzeg);
            if (floty_orbita_dostepna()) {
                $db->prepare('UPDATE mapa_obiekty SET orbita_deg=?, przylot_utc=? WHERE id=?')
                    ->execute([$planeta ? round(floty_kat($k + 180), 2) : null, $planeta ? czas_utc($teraz) : null, $o['id']]);
                if (floty_nawigacja_dostepna()) $db->prepare('UPDATE mapa_obiekty SET podejscie=? WHERE id=?')->execute([$planeta ? 'krawedz' : null, $o['id']]);
            }
            // Paliwo: zużyte proporcjonalnie do przebytej drogi.
            $cala = hypot((float) $o['wyjscie_x'] - (float) $o['start_x'], (float) $o['wyjscie_y'] - (float) $o['start_y']);
            $przebyta = hypot((float) $o['x'] - (float) $o['start_x'], (float) $o['y'] - (float) $o['start_y']);
            $paliwo = (float) $o['paliwo_zuzyte'] * ($cala > 0 ? min(1, $przebyta / $cala) : 1);
            // Uszkodzenia: losowo w granicach, × jakość podzespołów, × zapas mocy (do −25%).
            $czynnik = PRZERWANIE_JAKOSC[max(1, min(5, (int) ($o['jakosc'] ?? 3)))];
            $zapas = ((float) $o['moc_reaktora'] * max(0.1, (int) $o['sprawnosc_reaktora'] / 100)) / max(1.0, (float) $o['moc_hipernapedu']);
            $czynnik *= 1 - min(0.25, max(0.0, ($zapas - 1) * 0.25));
            $set = ['paliwo=GREATEST(0,paliwo-?)']; $wart = [round($paliwo, 2)];
            foreach ($kolumny as $kol) {
                [$od, $do] = PRZERWANIE_USZKODZENIA[$kol];
                $set[] = "$kol=GREATEST(0, CAST($kol AS SIGNED) - ?)";
                $wart[] = (int) round(random_int($od * 10, $do * 10) / 10 * $czynnik);
            }
            $wart[] = $o['id'];
            $db->prepare('UPDATE mapa_naped_jednostki SET ' . implode(', ', $set) . ' WHERE obiekt_id=?')->execute($wart);
        }
        $db->prepare("UPDATE mapa_rozkazy_skokow_jednostki SET status='przerwany' WHERE rozkaz_id=? AND status='w_nadprzestrzeni'")->execute([$id]);
        $db->prepare("UPDATE mapa_rozkazy_skokow SET status='przerwany', zakonczono_utc=UTC_TIMESTAMP() WHERE id=?")->execute([$id]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return ['ok' => true, 'w_nadprzestrzeni' => $wNadprzestrzeni,
        'komunikat' => $wNadprzestrzeni ? 'Gwałtowne wyjście z nadprzestrzeni. Reaktor i hipernapęd uszkodzone.' : 'Skok odwołany przed wejściem w nadprzestrzeń.'];
}

// ---------- promy w hangarach ----------
// Prom (np. Lambda) należy do hangaru okrętu macierzystego (okret_macierzysty_id). W hangarze
// (w_hangarze = 1) jego pozycja idzie za okrętem, nie ma znacznika na mapie i nie przyjmuje rozkazów.
// Dowodzi nim dowódca jego grupy (podgrupa floty okrętu: dziedziczy dowódcę). Wylot: prom staje na
// orbicie obok okrętu; z wybranym lądowiskiem sam leci na bliską orbitę i ląduje (ladowanie_cel_id).
// Powrót: z orbity tej samej planety (albo z tego samego punktu przestrzeni) z powrotem do hangaru.
function floty_hangar_dostepny(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT okret_macierzysty_id, w_hangarze, ladowanie_cel_id FROM mapa_obiekty LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

function floty_blokada_hangaru(array $odbiorcy): void
{
    if (!floty_hangar_dostepny() || !$odbiorcy) return;
    $marks = implode(',', array_fill(0, count($odbiorcy), '?'));
    $q = polaczenie_bazy()->prepare("SELECT nazwa FROM mapa_obiekty WHERE id IN ($marks) AND w_hangarze = 1 LIMIT 1");
    $q->execute($odbiorcy);
    $n = $q->fetchColumn();
    if ($n) blad('„' . $n . '” stoi w hangarze. Najpierw wylot z hangaru.');
}

// Promy w hangarze stoją razem z okrętem; promy w drodze na lądowisko lądują po dotarciu na bliską orbitę.
function floty_aktualizuj_hangary(): void
{
    if (!floty_hangar_dostepny()) return;
    $db = polaczenie_bazy();
    $db->exec("UPDATE mapa_obiekty p JOIN mapa_obiekty o ON o.id = p.okret_macierzysty_id
        SET p.planeta_id = o.planeta_id, p.x = o.x, p.y = o.y, p.kurs_deg = o.kurs_deg, p.orbita_deg = o.orbita_deg," . (floty_uklad_dostepny() ? 'p.uklad_kx = o.uklad_kx, p.uklad_ky = o.uklad_ky,' : '') . "
           
            p.podejscie = o.podejscie, p.podlot_start = NULL, p.podlot_cel = NULL, p.podlot_od_utc = NULL, p.podlot_do_utc = NULL
        WHERE p.w_hangarze = 1");
    $teraz = czas_teraz();
    $q = $db->query('SELECT id, planeta_id, podejscie, podlot_start, podlot_cel, podlot_od_utc, podlot_do_utc, ladowanie_cel_id, ladowisko_id
        FROM mapa_obiekty WHERE ladowanie_cel_id IS NOT NULL');
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) {
        if ($o['ladowisko_id'] || !$o['planeta_id']) {
            $db->prepare('UPDATE mapa_obiekty SET ladowanie_cel_id = NULL WHERE id = ?')->execute([$o['id']]);
            continue;
        }
        if (czas_z_utc($o['podlot_do_utc'] ?? null) > $teraz || floty_podejscie_teraz($o, $teraz) !== 'bliska') continue;
        $db->prepare("UPDATE mapa_obiekty SET ladowisko_id = ?, przylot_utc = ?, status = 'na lądowisku', podejscie = 'bliska', ladowanie_cel_id = NULL,
            podlot_start = NULL, podlot_cel = NULL, podlot_od_utc = NULL, podlot_do_utc = NULL WHERE id = ?")
            ->execute([(int) $o['ladowanie_cel_id'], czas_utc($teraz), $o['id']]);
    }
}

// Prom i jego okręt macierzysty (do kontroli przy wylocie i powrocie).
function floty_prom(int $id): array
{
    if (!floty_hangar_dostepny()) blad('Zaimportuj jeszcze api/migracje/035_promy_w_hangarach.sql.');
    $lot = floty_lot_dostepny();
    $q = polaczenie_bazy()->prepare('SELECT p.id, p.nazwa, p.w_hangarze, p.okret_macierzysty_id, p.planeta_id, p.ladowisko_id, p.x, p.y,
            o.nazwa AS okret, o.planeta_id AS okret_planeta_id, o.x AS okret_x, o.y AS okret_y,
            o.kurs_deg AS okret_kurs, o.orbita_deg AS okret_orbita, o.podejscie AS okret_podejscie, o.podlot_start AS okret_podlot_start,
            o.podlot_cel AS okret_podlot_cel, o.podlot_od_utc AS okret_podlot_od_utc, o.podlot_do_utc AS okret_podlot_do_utc,'
        . ($lot ? ' p.lot_do_utc, o.lot_do_utc AS okret_lot_do_utc,' : '')
        . (floty_uklad_dostepny() ? ' p.uklad_kx, p.uklad_ky, o.uklad_kx AS okret_kx, o.uklad_ky AS okret_ky,' : '') . '
            COALESCE(n.moze_ladowac, 0) AS moze_ladowac
        FROM mapa_obiekty p JOIN mapa_obiekty o ON o.id = p.okret_macierzysty_id LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id = p.id
        WHERE p.id = ? AND p.aktywny = 1');
    $q->execute([$id]);
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p) blad('Nie ma takiego promu.');
    return $p;
}

function floty_w_skoku(int $id): bool
{
    $q = polaczenie_bazy()->prepare("SELECT 1 FROM mapa_rozkazy_skokow_jednostki WHERE obiekt_id = ? AND status = 'w_nadprzestrzeni' LIMIT 1");
    $q->execute([$id]);
    return (bool) $q->fetchColumn();
}

// Wylot z hangaru; z lądowiskiem — lot na planetę: dolot na bliską orbitę i lądowanie bez kolejnych rozkazów.
function floty_prom_wylot(string $login, int $id, int $ladowiskoId = 0): array
{
    if (!floty_jednostka_moze($login, $id)) blad('Nie dowodzisz tym promem.', 403);
    floty_aktualizuj_ruch();
    $p = floty_prom($id);
    if (!(int) $p['w_hangarze']) blad('„' . $p['nazwa'] . '” nie stoi w hangarze.');
    $okret = (int) $p['okret_macierzysty_id'];
    if (floty_w_skoku($okret)) blad('„' . $p['okret'] . '” jest w nadprzestrzeni — wrota hangaru zostają zamknięte.');
    if (!empty($p['okret_lot_do_utc'])) blad('„' . $p['okret'] . '” jest w locie — poczekaj, aż się zatrzyma.');
    $planeta = $p['okret_planeta_id'] ? (int) $p['okret_planeta_id'] : null;
    if ($ladowiskoId) {
        if (!$planeta) blad('Okręt nie stoi przy żadnej planecie.');
        if (!(int) $p['moze_ladowac']) blad('„' . $p['nazwa'] . '” nie może lądować.');
        if (!in_array($ladowiskoId, array_column(floty_ladowiska($planeta), 'id'), true)) blad('Nie ma takiego lądowiska.');
    }
    $teraz = czas_teraz();
    // Prom wychodzi tam, gdzie okręt jest teraz (także w trakcie dolotu okrętu: etap osiągnięty do tej chwili).
    $podejscie = $planeta ? (floty_podejscie_teraz(['podejscie' => $p['okret_podejscie'], 'podlot_start' => $p['okret_podlot_start'],
        'podlot_cel' => $p['okret_podlot_cel'], 'podlot_od_utc' => $p['okret_podlot_od_utc'], 'podlot_do_utc' => $p['okret_podlot_do_utc']], $teraz) ?: 'daleka') : null;
    $db = polaczenie_bazy();
    $db->prepare("UPDATE mapa_obiekty SET w_hangarze = 0, planeta_id = ?, x = ?, y = ?, kurs_deg = ?, kurs_start_deg = NULL, kurs_cel_deg = NULL,
        manewr_od_utc = NULL, manewr_do_utc = NULL, orbita_deg = ?, podejscie = ?, przylot_utc = ?, rozbity = 0,
        podlot_start = NULL, podlot_cel = NULL, podlot_od_utc = NULL, podlot_do_utc = NULL, ladowanie_cel_id = ?, status = 'w locie' WHERE id = ?")
        ->execute([$planeta, $p['okret_x'], $p['okret_y'], round((float) ($p['okret_kurs'] ?? 90), 2), $p['okret_orbita'], $podejscie,
            $planeta ? czas_utc($teraz - 3600) : null, $ladowiskoId ?: null, $id]);
    if (!$ladowiskoId) return ['ok' => true, 'komunikat' => '„' . $p['nazwa'] . '” opuścił hangar.'];
    if ($podejscie === 'bliska') {
        floty_aktualizuj_hangary();
        return ['ok' => true, 'komunikat' => '„' . $p['nazwa'] . '” wylądował.', 'koniec' => $teraz];
    }
    $w = floty_dolot([$id], 'bliska');
    return ['ok' => true, 'komunikat' => '„' . $p['nazwa'] . '” opuścił hangar i leci na lądowisko.', 'koniec' => $w['koniec']];
}

// Powrót do hangaru okrętu macierzystego: z orbity tej samej planety albo z tego samego punktu przestrzeni.
function floty_prom_powrot(string $login, int $id): array
{
    if (!floty_jednostka_moze($login, $id)) blad('Nie dowodzisz tym promem.', 403);
    floty_aktualizuj_ruch();
    $p = floty_prom($id);
    if ((int) $p['w_hangarze']) blad('„' . $p['nazwa'] . '” już stoi w hangarze.');
    if ($p['ladowisko_id']) blad('„' . $p['nazwa'] . '” stoi na lądowisku. Najpierw start.');
    if (floty_w_skoku($id) || !empty($p['lot_do_utc'])) blad('„' . $p['nazwa'] . '” jest w locie.');
    if (floty_w_skoku((int) $p['okret_macierzysty_id']) || !empty($p['okret_lot_do_utc'])) blad('„' . $p['okret'] . '” jest w locie — nie przyjmie promu.');
    $razem = $p['planeta_id'] && $p['okret_planeta_id']
        ? (int) $p['planeta_id'] === (int) $p['okret_planeta_id']
        : (!$p['planeta_id'] && !$p['okret_planeta_id'] && hypot((float) $p['x'] - (float) $p['okret_x'], (float) $p['y'] - (float) $p['okret_y']) < 0.05
            && (($p['uklad_kx'] ?? null) === null) === (($p['okret_kx'] ?? null) === null)
            && hypot((float) ($p['uklad_kx'] ?? 0) - (float) ($p['okret_kx'] ?? 0), (float) ($p['uklad_ky'] ?? 0) - (float) ($p['okret_ky'] ?? 0)) < 50000);
    if (!$razem) blad('„' . $p['okret'] . '” jest gdzie indziej. Najpierw dolećcie do niego.');
    polaczenie_bazy()->prepare("UPDATE mapa_obiekty SET w_hangarze = 1, ladowanie_cel_id = NULL, status = 'w hangarze' WHERE id = ?")->execute([$id]);
    floty_aktualizuj_hangary();
    return ['ok' => true, 'komunikat' => '„' . $p['nazwa'] . '” wrócił do hangaru ' . $p['okret'] . '.'];
}

// Panel promów w Nawigacji: na okręcie z hangarem — promy w hangarze; na promie — jego stan.
function floty_prom_info(array $u, int $okretId): ?array
{
    if (!floty_hangar_dostepny() || !$okretId) return null;
    $db = polaczenie_bazy();
    $login = (string) $u['login'];
    $q = $db->prepare('SELECT p.id, p.nazwa, p.klasa, p.w_hangarze, p.planeta_id, p.ladowisko_id, o.nazwa AS okret, o.planeta_id AS okret_planeta_id,
            (SELECT nazwa FROM lokalizacje WHERE id = p.ladowanie_cel_id) AS cel_ladowania
        FROM mapa_obiekty p JOIN mapa_obiekty o ON o.id = p.okret_macierzysty_id WHERE p.aktywny = 1 AND (p.okret_macierzysty_id = ? OR p.id = ?) ORDER BY p.nazwa');
    $q->execute([$okretId, $okretId]);
    $promy = [];
    $ten = null;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $wpis = ['id' => (int) $p['id'], 'nazwa' => $p['nazwa'], 'klasa' => $p['klasa'], 'w_hangarze' => (bool) $p['w_hangarze'],
            'okret' => $p['okret'], 'kapitan' => (bool) floty_jednostka_moze($login, (int) $p['id']),
            'cel_ladowania' => $p['cel_ladowania'], 'na_ladowisku' => (bool) $p['ladowisko_id'],
            'przy_okrecie' => !$p['ladowisko_id'] && $p['planeta_id'] && (int) $p['planeta_id'] === (int) $p['okret_planeta_id']];
        if ((int) $p['id'] === $okretId) $ten = $wpis; else $promy[] = $wpis;
    }
    if (!$ten && !$promy) return null;
    // Lądowiska planety, przy której stoi okręt (lot na planetę prosto z hangaru).
    $q = $db->prepare('SELECT planeta_id FROM mapa_obiekty WHERE id = ?');
    $q->execute([$okretId]);
    $planeta = (int) $q->fetchColumn();
    $ladowiska = $planeta && ($ten ? $ten['w_hangarze'] && $ten['kapitan'] : true) ? floty_ladowiska($planeta, $u) : [];
    return $ten ? ['prom' => $ten, 'ladowiska' => $ladowiska] : ['promy' => $promy, 'ladowiska' => $ladowiska];
}

function obsluz_floty(string $akcja, bool $post): void
{
    $akcje = ['mapa_floty_gracza','mapa_flota_podziel','mapa_flota_rozkaz','mapa_rozkaz_anuluj','mapa_flota_kurs','mapa_flota_dolot',
        'mapa_flota_start','mapa_flota_ladowanie','mapa_ladowiska','mapa_flota_lot','mapa_lot_przerwij','mapa_poklad','mg_poklad_rola',
        'mg_floty','mg_flota_zapisz','mg_flota_jednostki','mg_flota_usun','mg_naped_zapisz','mg_mapa_teleport','mg_flota_kurs',
        'prom_wylot','prom_powrot'];
    if (!in_array($akcja, $akcje, true)) return;
    floty_gotowe();
    switch ($akcja) {
        case 'mapa_floty_gracza':
            $u = wymagaj_logowania();
            odpowiedz(['floty' => floty_lista((string) $u['login'])]);

        case 'mapa_flota_podziel':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania(); $w = wejscie();
            $rodzic = (int) ($w['flota_id'] ?? 0);
            if (!floty_czy_dowodca($rodzic, (string) $u['login'])) blad('Nie dowodzisz tą flotą.', 403);
            $nazwa = mb_substr(trim((string) ($w['nazwa'] ?? '')), 0, 160);
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($w['jednostki'] ?? [])))));
            if ($nazwa === '' || !$ids) blad('Podaj nazwę nowej grupy i wybierz jej jednostki.');
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $q = polaczenie_bazy()->prepare("SELECT obiekt_id FROM mapa_floty_jednostki WHERE flota_id=? AND obiekt_id IN ($marks)");
            $q->execute([$rodzic, ...$ids]);
            if (count($q->fetchAll(PDO::FETCH_COLUMN)) !== count($ids)) blad('Możesz przenosić tylko jednostki z wybranej grupy.');
            $busy = polaczenie_bazy()->prepare("SELECT COUNT(*) FROM mapa_rozkazy_skokow_jednostki WHERE obiekt_id IN ($marks) AND status='w_nadprzestrzeni'");
            $busy->execute($ids);
            if ((int) $busy->fetchColumn()) blad('Najpierw poczekaj na zakończenie skoku wybranych jednostek.');
            $db = polaczenie_bazy(); $db->beginTransaction();
            try {
                $kod = 'grupa-' . bin2hex(random_bytes(8));
                $db->prepare('INSERT INTO mapa_floty (kod,nazwa,rodzic_id,status) VALUES (?,?,?,\'aktywna\')')->execute([$kod,$nazwa,$rodzic]);
                $nowa = (int) $db->lastInsertId();
                $q = $db->prepare("UPDATE mapa_floty_jednostki SET flota_id=? WHERE flota_id=? AND obiekt_id IN ($marks)");
                $q->execute([$nowa,$rodzic,...$ids]);
                $db->commit();
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            odpowiedz(['ok' => true, 'flota_id' => $nowa]);

        case 'mapa_flota_rozkaz':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            odpowiedz(floty_rozkaz((string) $u['login'], wejscie()));

        case 'mapa_flota_kurs':
            // Dowódca ustawia kurs (na orbicie: stronę planety). Manewr trwa zależnie od tempa obrotu.
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania(); $w = wejscie();
            [, $odbiorcy] = floty_odbiorcy((string) $u['login'], $w);
            if (!isset($w['kurs']) || !is_numeric($w['kurs'])) blad('Podaj kurs w stopniach (0–359).');
            odpowiedz(floty_kurs($odbiorcy, (float) $w['kurs']));

        case 'mapa_flota_start':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            [, $odbiorcy] = floty_odbiorcy((string) $u['login'], wejscie());
            odpowiedz(floty_start($odbiorcy));

        case 'mapa_flota_ladowanie':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania(); $w = wejscie();
            [, $odbiorcy] = floty_odbiorcy((string) $u['login'], $w);
            odpowiedz(floty_ladowanie($odbiorcy, (int) ($w['lokacja_id'] ?? 0)));

        case 'mapa_ladowiska':
            wymagaj_logowania();
            $q = polaczenie_bazy()->prepare('SELECT id FROM mapa_planety WHERE nazwa = ? LIMIT 1');
            $q->execute([(string) ($_GET['planeta'] ?? '')]);
            $pid = (int) $q->fetchColumn();
            odpowiedz(['ladowiska' => $pid && mapa_planeta_widoczna($pid) ? floty_ladowiska($pid) : []]);

        case 'mapa_flota_dolot':
            // Dowódca zmienia orbitę: krawędź układu ↔ daleka ↔ średnia ↔ bliska (napęd podświetlny).
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania(); $w = wejscie();
            [, $odbiorcy] = floty_odbiorcy((string) $u['login'], $w);
            odpowiedz(floty_dolot($odbiorcy, (string) ($w['cel'] ?? '')));

        case 'mapa_flota_lot':
            // Lot podświetlny do planety/księżyca albo punktu mapy (z `plan`: tylko wyliczenie).
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania(); $w = wejscie();
            [, $odbiorcy] = floty_odbiorcy((string) $u['login'], $w);
            odpowiedz(floty_lot($odbiorcy, $w));

        case 'mapa_lot_przerwij':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania(); $w = wejscie();
            [, $odbiorcy] = floty_odbiorcy((string) $u['login'], $w);
            odpowiedz(floty_lot_przerwij($odbiorcy));

        case 'prom_wylot':
            // Wylot promu z hangaru; z lądowiskiem: lot na planetę (dolot i lądowanie jednym rozkazem).
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania(); $w = wejscie();
            odpowiedz(floty_prom_wylot((string) $u['login'], (int) ($w['obiekt_id'] ?? 0), (int) ($w['ladowisko_id'] ?? 0)));

        case 'prom_powrot':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            odpowiedz(floty_prom_powrot((string) $u['login'], (int) (wejscie()['obiekt_id'] ?? 0)));

        case 'mapa_poklad':
            $u = wymagaj_logowania();
            odpowiedz(floty_poklad($u, (int) ($_GET['obiekt'] ?? 0)));

        case 'mg_poklad_rola':
            // MG: osoba na pokładzie jest pasażerem albo więźniem.
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg(); $w = wejscie();
            if (!floty_lot_dostepny()) blad('Zaimportuj jeszcze api/migracje/028_lot_podswietlny_poklad.sql.');
            $rola = ($w['rola'] ?? '') === 'wiezien' ? 'wiezien' : 'pasazer';
            polaczenie_bazy()->prepare("UPDATE mapa_obiekty SET rola_na_pokladzie=? WHERE id=? AND typ='postac'")->execute([$rola, (int) ($w['id'] ?? 0)]);
            odpowiedz(['ok' => true]);

        case 'mg_flota_kurs':
            // MG ustawia kurs od razu, bez manewru (flota albo pojedyncza jednostka).
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg(); $w = wejscie();
            $ids = [];
            if (!empty($w['obiekt_id'])) $ids = [(int) $w['obiekt_id']];
            elseif (!empty($w['flota_id'])) {
                $q = polaczenie_bazy()->prepare('SELECT obiekt_id FROM mapa_floty_jednostki WHERE flota_id=?');
                $q->execute([(int) $w['flota_id']]);
                $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            }
            if (!$ids) blad('Wybierz jednostkę lub flotę.');
            // Opcjonalnie: miejsce na orbicie i rozbicie (z mostka widać wtedy powierzchnię planety).
            $marks = implode(',', array_fill(0, count($ids), '?'));
            if (isset($w['orbita']) && is_numeric($w['orbita'])) {
                polaczenie_bazy()->prepare("UPDATE mapa_obiekty SET orbita_deg=? WHERE id IN ($marks)")->execute([round(floty_kat((float) $w['orbita']), 2), ...$ids]);
            }
            if (array_key_exists('frakcja', $w) && mapa_frakcja_dostepna()) {
                // Frakcja floty (kolor na mapie: sojusznik / wróg / neutralny); pusta = wyliczana automatycznie.
                $fr = in_array($w['frakcja'], [...FRAKCJE, 'neutralna'], true) ? $w['frakcja'] : null;
                polaczenie_bazy()->prepare("UPDATE mapa_obiekty SET frakcja=? WHERE id IN ($marks)")->execute([$fr, ...$ids]);
            }
            if (function_exists('ruch_lokalizator_dostepny') && ruch_lokalizator_dostepny()) {
                // Prywatny statek: konto właściciela i lokalizator (zasięg pc, włączony / wyłączony np. przez złodzieja).
                if (array_key_exists('wlasciciel_login', $w)) {
                    $wl = mb_substr(trim((string) $w['wlasciciel_login']), 0, 64);
                    polaczenie_bazy()->prepare("UPDATE mapa_obiekty SET wlasciciel_login=? WHERE id IN ($marks)")->execute([$wl !== '' ? $wl : null, ...$ids]);
                }
                if (isset($w['lokalizator_zasieg']) && is_numeric($w['lokalizator_zasieg'])) {
                    polaczenie_bazy()->prepare("UPDATE mapa_obiekty SET lokalizator_zasieg_pc=? WHERE id IN ($marks)")->execute([max(0, min(100000, (float) $w['lokalizator_zasieg'])), ...$ids]);
                }
                if (isset($w['lokalizator_aktywny'])) {
                    polaczenie_bazy()->prepare("UPDATE mapa_obiekty SET lokalizator_aktywny=? WHERE id IN ($marks)")->execute([empty($w['lokalizator_aktywny']) ? 0 : 1, ...$ids]);
                }
            }
            if (isset($w['rozbity'])) {
                polaczenie_bazy()->prepare("UPDATE mapa_obiekty SET rozbity=? WHERE id IN ($marks)")->execute([empty($w['rozbity']) ? 0 : 1, ...$ids]);
            }
            if (isset($w['kurs']) && is_numeric($w['kurs'])) odpowiedz(floty_kurs($ids, (float) $w['kurs'], true));
            odpowiedz(['ok' => true]);

        case 'mapa_rozkaz_anuluj':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            odpowiedz(floty_przerwij_skok($u, (int) (wejscie()['id'] ?? 0)));

        case 'mg_floty':
            wymagaj_mg();
            $jednostki = polaczenie_bazy()->query("SELECT o.id,o.kod,o.nazwa,o.typ,o.rodzaj,o.klasa,o.status,o.x,o.y,j.flota_id,j.rola,j.kolejnosc,j.tie_liczba,n.moc_reaktora,n.moc_hipernapedu,n.klasa_hipernapedu,n.predkosc_pc_na_godzine,n.paliwo,n.paliwo_maks,n.sprawnosc_reaktora,n.sprawnosc_hipernapedu,n.szum_bazowy,n.paliwo_na_parsek
                FROM mapa_obiekty o LEFT JOIN mapa_floty_jednostki j ON j.obiekt_id=o.id LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id=o.id
                WHERE o.aktywny=1 AND o.typ IN ('statek','jednostka','pojazd') ORDER BY o.nazwa")->fetchAll(PDO::FETCH_ASSOC);
            $npc = polaczenie_bazy()->query("SELECT o.podmiot_id AS id,p.nazwa,o.rola FROM osoby o JOIN podmioty p ON p.id=o.podmiot_id WHERE p.status='aktywny' AND o.stan<>'polegly' ORDER BY p.nazwa")->fetchAll(PDO::FETCH_ASSOC);
            odpowiedz(['floty' => floty_lista('', true), 'jednostki' => $jednostki, 'npc' => $npc,
                'konta' => array_values(array_map(static fn($x) => ['login' => $x['login'], 'postac' => $x['postac']], array_filter(uzytkownicy(), static fn($x) => ($x['rola'] ?? '') !== 'mg')))]);

        case 'mg_flota_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg(); $w = wejscie();
            $id = (int) ($w['id'] ?? 0); $nazwa = mb_substr(trim((string) ($w['nazwa'] ?? '')), 0, 160);
            $login = trim((string) ($w['dowodca_login'] ?? ''));
            $npcId = (int) ($w['dowodca_osoba_id'] ?? 0);
            if ($nazwa === '') blad('Nazwa floty jest wymagana.');
            if ($login !== '' && $npcId) blad('Wybierz dowódcę gracza albo kapitana NPC.');
            if ($login !== '' && !in_array(mb_strtolower($login), array_map('mb_strtolower', array_column(uzytkownicy(), 'login')), true)) blad('Nie ma takiego konta dowódcy.');
            $db = polaczenie_bazy();
            if ($npcId) { $q=$db->prepare("SELECT COUNT(*) FROM osoby o JOIN podmioty p ON p.id=o.podmiot_id WHERE o.podmiot_id=? AND p.status='aktywny' AND o.stan<>'polegly'"); $q->execute([$npcId]); if (!(int)$q->fetchColumn()) blad('Nie ma takiego aktywnego NPC.'); }
            if ($id) $db->prepare('UPDATE mapa_floty SET nazwa=?,dowodca_login=?,dowodca_osoba_id=? WHERE id=?')->execute([$nazwa, $login ?: null, $npcId ?: null, $id]);
            else { $db->prepare('INSERT INTO mapa_floty (kod,nazwa,dowodca_login,dowodca_osoba_id,opis) VALUES (?,?,?,?,?)')->execute(['flota-' . bin2hex(random_bytes(8)),$nazwa,$login ?: null,$npcId ?: null,mb_substr(trim((string) ($w['opis'] ?? '')),0,4000) ?: null]); $id = (int) $db->lastInsertId(); }
            odpowiedz(['ok' => true, 'flota_id' => $id]);

        case 'mg_flota_usun':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg(); $id = (int) (wejscie()['id'] ?? 0); $db = polaczenie_bazy();
            $q = $db->prepare("SELECT id FROM mapa_floty WHERE id=? AND status<>'nieaktywna'"); $q->execute([$id]);
            if (!$q->fetchColumn()) blad('Nie ma takiej aktywnej floty.', 404);
            // Likwidujemy także podgrupy. Okręty pozostają w mapie, ale stają się wolne.
            $ids = [$id];
            for ($i = 0; $i < count($ids); $i++) {
                $q = $db->prepare('SELECT id FROM mapa_floty WHERE rodzic_id=?'); $q->execute([$ids[$i]]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $child) if (!in_array((int) $child, $ids, true)) $ids[] = (int) $child;
            }
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $db->beginTransaction();
            try {
                $db->prepare("DELETE FROM mapa_floty_jednostki WHERE flota_id IN ($marks)")->execute($ids);
                $db->prepare("UPDATE mapa_floty SET status='nieaktywna',dowodca_login=NULL WHERE id IN ($marks)")->execute($ids);
                $db->commit();
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            odpowiedz(['ok' => true, 'zwolniono_flot' => count($ids)]);

        case 'mg_flota_jednostki':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg(); $w = wejscie();
            $flota = (int) ($w['flota_id'] ?? 0); $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($w['jednostki'] ?? [])))));
            $db = polaczenie_bazy(); $q = $db->prepare('SELECT id FROM mapa_floty WHERE id=?'); $q->execute([$flota]);
            if (!$q->fetchColumn()) blad('Nie ma takiej floty.', 404);
            $busy = [];
            if ($ids) {
                $marks = implode(',', array_fill(0,count($ids),'?'));
                $q = $db->prepare("SELECT COUNT(*) FROM mapa_rozkazy_skokow_jednostki WHERE obiekt_id IN ($marks) AND status='w_nadprzestrzeni'"); $q->execute($ids);
                if ((int) $q->fetchColumn()) blad('Nie można zmieniać składu podczas skoku.');
                $q = $db->prepare("SELECT COUNT(*) FROM mapa_obiekty WHERE id IN ($marks) AND typ IN ('statek','jednostka','pojazd')"); $q->execute($ids);
                if ((int) $q->fetchColumn() !== count($ids)) blad('Wybierz poprawne obiekty mapy.');
                $q = $db->prepare("SELECT j.obiekt_id,f.nazwa FROM mapa_floty_jednostki j JOIN mapa_floty f ON f.id=j.flota_id WHERE j.obiekt_id IN ($marks) AND j.flota_id<>?");
                $q->execute([...$ids,$flota]);
                if ($zajete = $q->fetch(PDO::FETCH_ASSOC)) blad('Ten okręt jest już przypisany do floty „' . $zajete['nazwa'] . '”. Najpierw usuń go z tamtego składu.');
            }
            $db->beginTransaction();
            try {
                $db->prepare('DELETE FROM mapa_floty_jednostki WHERE flota_id=?')->execute([$flota]);
                if ($ids) {
                    $q = $db->prepare('INSERT INTO mapa_floty_jednostki (obiekt_id,flota_id,rola,kolejnosc,tie_liczba) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE flota_id=VALUES(flota_id),rola=VALUES(rola),kolejnosc=VALUES(kolejnosc),tie_liczba=VALUES(tie_liczba)');
                    foreach ($ids as $index => $objectId) {
                        $tie = max(0, min(99, (int) (($w['tie'][$objectId] ?? '') === '' ? 0 : $w['tie'][$objectId])));
                        $q->execute([$objectId,$flota,mb_substr(trim((string) ($w['rola'][$objectId] ?? '')),0,80),$index,$tie ?: null]);
                    }
                    $q = $db->prepare('SELECT dowodca_login FROM mapa_floty WHERE id=?'); $q->execute([$flota]); $dowodca = $q->fetchColumn();
                    if ($dowodca) {
                        $q = $db->prepare('INSERT IGNORE INTO mapa_obiekty_dostep (obiekt_id,konto_login) VALUES (?,?)');
                        foreach ($ids as $objectId) $q->execute([$objectId,$dowodca]);
                    }
                }
                if ($ids && !empty($w['nazwy']) && is_array($w['nazwy'])) {
                    $rename = $db->prepare('UPDATE mapa_obiekty SET nazwa=? WHERE id=? AND aktywny=1');
                    foreach ($ids as $objectId) {
                        if (!array_key_exists((string)$objectId, $w['nazwy']) && !array_key_exists($objectId, $w['nazwy'])) continue;
                        $shipName = mb_substr(trim((string)($w['nazwy'][$objectId] ?? $w['nazwy'][(string)$objectId] ?? '')),0,160);
                        if ($shipName === '') blad('Każdy przydzielony statek musi mieć nazwę.');
                        $rename->execute([$shipName,$objectId]);
                    }
                }
                $db->commit();
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            odpowiedz(['ok' => true]);

        case 'mg_naped_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg(); $w = wejscie(); $id = (int) ($w['obiekt_id'] ?? 0);
            $params = [(float) ($w['moc_reaktora'] ?? 0),(float) ($w['moc_hipernapedu'] ?? 0),(float) ($w['klasa_hipernapedu'] ?? 1),
                (float) ($w['predkosc_pc_na_godzine'] ?? 0),(float) ($w['paliwo'] ?? 0),(float) ($w['paliwo_maks'] ?? 0),(float) ($w['paliwo_na_parsek'] ?? 0),
                max(0,min(100,(int) ($w['sprawnosc_reaktora'] ?? 100))),max(0,min(100,(int) ($w['sprawnosc_hipernapedu'] ?? 100))),max(0,min(30,(float) ($w['szum_bazowy'] ?? 0.05))),
                max(1,min(720,(float) ($w['obrot_deg_na_min'] ?? 90))),
                max(1,min(5,(int) ($w['komputer_klasa'] ?? 3))),
                in_array($w['komputer_stan'] ?? '', ['sprawny','zhakowany','przejety','brak'], true) ? $w['komputer_stan'] : 'sprawny'];
            if (!$id || min($params[0],$params[1],$params[2],$params[3],$params[5]) <= 0 || $params[4] < 0 || $params[4] > $params[5] || $params[6] < 0) blad('Sprawdź dodatnie parametry napędu i poziom paliwa.');
            $q = polaczenie_bazy()->prepare('INSERT INTO mapa_naped_jednostki (obiekt_id,moc_reaktora,moc_hipernapedu,klasa_hipernapedu,predkosc_pc_na_godzine,paliwo,paliwo_maks,paliwo_na_parsek,sprawnosc_reaktora,sprawnosc_hipernapedu,szum_bazowy,obrot_deg_na_min,komputer_klasa,komputer_stan) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE obrot_deg_na_min=VALUES(obrot_deg_na_min),komputer_klasa=VALUES(komputer_klasa),komputer_stan=VALUES(komputer_stan), moc_reaktora=VALUES(moc_reaktora),moc_hipernapedu=VALUES(moc_hipernapedu),klasa_hipernapedu=VALUES(klasa_hipernapedu),predkosc_pc_na_godzine=VALUES(predkosc_pc_na_godzine),paliwo=VALUES(paliwo),paliwo_maks=VALUES(paliwo_maks),paliwo_na_parsek=VALUES(paliwo_na_parsek),sprawnosc_reaktora=VALUES(sprawnosc_reaktora),sprawnosc_hipernapedu=VALUES(sprawnosc_hipernapedu),szum_bazowy=VALUES(szum_bazowy)');
            $q->execute([$id,...$params]);
            if (floty_przyspieszenie_dostepne() && isset($w['podswietlna_pc_doba'])) {
                polaczenie_bazy()->prepare('UPDATE mapa_naped_jednostki SET podswietlna_pc_doba=? WHERE obiekt_id=?')
                    ->execute([max(0.1, min(1000, (float) $w['podswietlna_pc_doba'])), $id]);
            }
            if (floty_punkt_dostepny() && isset($w['predkosc_podswietlna_kms'])) {
                polaczenie_bazy()->prepare('UPDATE mapa_naped_jednostki SET predkosc_podswietlna_kms=? WHERE obiekt_id=?')
                    ->execute([max(10, min(5000, (float) $w['predkosc_podswietlna_kms'])), $id]);
            }
            if (liczniki_dostepne()) {
                polaczenie_bazy()->prepare('UPDATE mapa_naped_jednostki SET oslony=?, kadlub=? WHERE obiekt_id=?')
                    ->execute([max(0, min(100, (int) ($w['oslony'] ?? 100))), max(0, min(100, (int) ($w['kadlub'] ?? 100))), $id]);
            }
            odpowiedz(['ok' => true]);

        case 'mg_mapa_teleport':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_mg(); $w = wejscie();
            if (!is_numeric($w['x'] ?? null) || !is_numeric($w['y'] ?? null)) blad('Podaj współrzędne X i Y.');
            $x = (float) $w['x']; $y = (float) $w['y'];
            if (abs($x)>1000000 || abs($y)>1000000) blad('Współrzędne wykraczają poza zakres mapy.');
            floty_aktualizuj_ruch();
            $ids = [];
            if (!empty($w['obiekt_id'])) $ids[] = (int) $w['obiekt_id'];
            elseif (!empty($w['flota_id'])) {
                $q = polaczenie_bazy()->prepare('SELECT obiekt_id FROM mapa_floty_jednostki WHERE flota_id=?'); $q->execute([(int) $w['flota_id']]); $ids = array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
            }
            if (!$ids) blad('Wybierz jednostkę lub flotę.');
            $db = polaczenie_bazy(); $marks=implode(',',array_fill(0,count($ids),'?')); $db->beginTransaction();
            try {
                $q=$db->prepare("SELECT DISTINCT rozkaz_id FROM mapa_rozkazy_skokow_jednostki WHERE obiekt_id IN ($marks) AND status='w_nadprzestrzeni'"); $q->execute($ids);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                    $db->prepare("UPDATE mapa_rozkazy_skokow_jednostki SET status='przerwany' WHERE rozkaz_id=? AND status='w_nadprzestrzeni'")->execute([$rid]);
                    $db->prepare("UPDATE mapa_rozkazy_skokow SET status='przerwany',zakonczono_utc=UTC_TIMESTAMP(),opis=CONCAT(COALESCE(opis,''),' | Przerwano teleportem MG.') WHERE id=?")->execute([$rid]);
                }
                $q=$db->prepare('UPDATE mapa_obiekty SET x=?,y=?,planeta_id=(SELECT id FROM mapa_planety WHERE ABS(x-?)<0.05 AND ABS(y-?)<0.05 ORDER BY POW(x-?,2)+POW(y-?,2), id LIMIT 1) WHERE id IN (' . $marks . ')');
                $q->execute([$x,$y,$x,$y,$x,$y,...$ids]);
                $reason=mb_substr(trim((string) ($w['powod'] ?? '')),0,500);
                $q=$db->prepare('INSERT INTO mapa_teleporty_mg (obiekt_id,autor_login,x,y,powod) VALUES (?,?,?,?,?)');
                foreach ($ids as $objectId) $q->execute([$objectId,$u['login'],$x,$y,$reason]);
                $db->commit();
            } catch (Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
            odpowiedz(['ok'=>true,'ile'=>count($ids)]);
    }
}

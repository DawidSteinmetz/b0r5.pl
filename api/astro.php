<?php
// ASTRONOMIA GRY: cykle planet (doba, rok, pory roku), położenie wokół słońca i księżyce.
//
// Współrzędne planety na mapie galaktyki to położenie jej słońca (układu). Wszystko inne liczy się
// od epoki 0 = 1|1|0 00:00 (1.01.2010 00:00 czasu polskiego). Serwer podaje tylko parametry
// („cykl”), a obliczenia na bieżąco robi js/rdzen/astro.js (dzień/noc, widok układu).
//
// Parametry: wiersz w planety_cykle (NULL = domyślne) i planety_ksiezyce; reszta to powtarzalne
// wartości wyliczane z nazwy planety, żeby każda z 2500+ planet miała swój własny rytm.

declare(strict_types=1);

const ASTRO_EPOKA = 1262300400; // 2010-01-01 00:00 Europe/Warsaw = 2009-12-31 23:00 UTC

// Rodzaje gwiazd: nazwa, udział w losowaniu (%), kolor, wielkość tarczy, siła łuny i jasność
// względem Słońca (L; do widoczności z daleka). Udziały zbliżone do prawdziwych.
const ASTRO_GWIAZDY = [
    'O'  => ['błękitny olbrzym',  1, [155, 176, 255], 2.4, 3.0, 30.0],
    'B'  => ['błękitna',          3, [170, 191, 255], 1.8, 2.2, 10.0],
    'A'  => ['biała',             5, [202, 215, 255], 1.4, 1.6, 4.0],
    'F'  => ['żółtobiała',        8, [248, 247, 255], 1.15, 1.25, 2.0],
    'G'  => ['żółta',            15, [255, 244, 225], 1.0, 1.0, 1.0],
    'K'  => ['pomarańczowa',     25, [255, 210, 161], 0.85, 0.75, 0.5],
    'M'  => ['czerwony karzeł',  40, [255, 170, 120], 0.6, 0.45, 0.15],
    'WD' => ['biały karzeł',      2, [235, 240, 255], 0.35, 0.6, 0.1],
    'RG' => ['czerwony olbrzym',  1, [255, 140, 90], 3.0, 1.4, 6.0],
];

// Typ gwiazdy wylosowany z nazwy (powtarzalnie), według udziałów.
function astro_typ_gwiazdy(string $nazwa, int $sol = 7): string
{
    $los = astro_hash($nazwa, $sol) % 100;
    foreach (ASTRO_GWIAZDY as $typ => $g) {
        if ($los < $g[1]) return $typ;
        $los -= $g[1];
    }
    return 'G';
}

function astro_gwiazda(string $typ, int $i = 0): array
{
    $g = ASTRO_GWIAZDY[$typ] ?? ASTRO_GWIAZDY['G'];
    // Towarzysze w układach wielokrotnych: lekko obok głównej gwiazdy (kąt i wysokość w oknie).
    $obok = [[0, 0], [2.6, -0.05], [-3.4, 0.06]][$i] ?? [0, 0];
    return ['typ' => $typ, 'nazwa' => $g[0], 'rgb' => $g[2], 'rozmiar' => $g[3], 'jasnosc' => $g[4], 'L' => $g[5],
        'przesuniecie' => $obok[0], 'wysokosc' => $obok[1]];
}

// Kierunek od planety do słońca (stopnie, układ mapy) w chwili $t.
function astro_kat_slonca(array $cykl, int $t): float
{
    $d = ($t - $cykl['epoka']) / 86400;
    return fmod($cykl['faza_orbity'] + 360 * $d / $cykl['rok_dni'] + 180 + 3600, 360);
}

// Kąt godzinny słońca w lokacji (stopnie, −180…180; 0 = lokalne południe, ±180 = północ).
function astro_kat_godzinny(array $cykl, float $dl, int $t): float
{
    $H = fmod($cykl['faza_obrotu'] + 360 * ($t - $cykl['epoka']) / ($cykl['doba_h'] * 3600) + $dl + 180, 360);
    return ($H < 0 ? $H + 360 : $H) - 180;
}

// Wysokość słońca nad horyzontem w lokacji (stopnie) — to samo co js/rdzen/astro.js.
function astro_wysokosc_slonca(array $cykl, float $szer, float $dl, int $t): float
{
    $d = ($t - $cykl['epoka']) / 86400;
    $dek = deg2rad($cykl['nachylenie'] * sin(deg2rad(360 * $d / $cykl['rok_dni'] + $cykl['faza_roku'])));
    $H = fmod($cykl['faza_obrotu'] + 360 * ($t - $cykl['epoka']) / ($cykl['doba_h'] * 3600) + $dl + 180, 360);
    $H = deg2rad(($H < 0 ? $H + 360 : $H) - 180);
    $fi = deg2rad($szer);
    return rad2deg(asin(sin($fi) * sin($dek) + cos($fi) * cos($dek) * cos($H)));
}

function astro_hash(string $nazwa, int $sol): int
{
    return crc32($sol . '|' . mb_strtolower(trim($nazwa)));
}

// Cykl planety po nazwie (planeta z mapy albo z katalogu MG).
function astro_cykl(string $nazwa): array
{
    static $cache = [];
    $klucz = mb_strtolower(trim($nazwa));
    if (isset($cache[$klucz])) return $cache[$klucz];

    $mapa = null;
    $nadpisy = [];
    $ksiezyce = [];
    if (tabela_sql_dostepna('mapa_planety')) {
        $q = polaczenie_bazy()->prepare("SELECT id, doba, rok, ksiezyce, srednica, slonca FROM mapa_planety WHERE REPLACE(nazwa, ' ', '') = ? LIMIT 1");
        $q->execute([str_replace(' ', '', trim($nazwa))]);
        $mapa = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($mapa && tabela_sql_dostepna('planety_cykle')) {
        $q = polaczenie_bazy()->prepare('SELECT * FROM planety_cykle WHERE planeta_id = ?');
        $q->execute([(int) $mapa['id']]);
        $nadpisy = array_filter($q->fetch(PDO::FETCH_ASSOC) ?: [], fn($v) => $v !== null);
        $q = polaczenie_bazy()->prepare('SELECT nazwa, promien_orbity_km, okres_dni, faza_deg, srednica_km FROM planety_ksiezyce WHERE planeta_id = ? ORDER BY promien_orbity_km');
        $q->execute([(int) $mapa['id']]);
        $ksiezyce = array_map(fn($k) => ['nazwa' => $k['nazwa'], 'promien_km' => (float) $k['promien_orbity_km'],
            'okres_dni' => (float) $k['okres_dni'], 'faza' => (float) $k['faza_deg'],
            'srednica_km' => $k['srednica_km'] === null ? null : (float) $k['srednica_km']], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    $h = fn(int $sol, int $zakres) => astro_hash($nazwa, $sol) % $zakres;
    $dobaMapy = (float) ($mapa['doba'] ?? 0);
    $rokMapy = (float) ($mapa['rok'] ?? 0);
    $srednica = (float) ($nadpisy['srednica_km'] ?? 0) ?: ((float) ($mapa['srednica'] ?? 0) ?: 8000 + $h(1, 9000));
    $cykl = [
        'nazwa' => $nazwa,
        'doba_h' => (float) ($nadpisy['doba_h'] ?? ($dobaMapy > 0 ? $dobaMapy : 24)),
        'rok_dni' => (float) ($nadpisy['rok_dni'] ?? ($rokMapy > 0 ? $rokMapy : 365)),
        'nachylenie' => (float) ($nadpisy['nachylenie_osi_deg'] ?? $h(2, 31)),
        'faza_obrotu' => (float) ($nadpisy['faza_obrotu_deg'] ?? $h(3, 360)),
        'faza_roku' => (float) ($nadpisy['faza_roku_deg'] ?? $h(4, 360)),
        'faza_orbity' => (float) ($nadpisy['faza_orbity_deg'] ?? $h(5, 360)),
        'promien_orbity_au' => (float) ($nadpisy['promien_orbity_au'] ?? (0.5 + $h(6, 150) / 100)),
        'srednica_km' => $srednica,
        'epoka' => ASTRO_EPOKA,
    ];
    if (!$ksiezyce) {
        // Księżyce wygenerowane z liczby na mapie (do 6 na widoku): orbity 15–60 promieni planety.
        $ile = min(6, max(0, (int) ($mapa['ksiezyce'] ?? 0)));
        $rzymskie = ['I', 'II', 'III', 'IV', 'V', 'VI'];
        for ($i = 0; $i < $ile; $i++) {
            $promien = $srednica / 2 * (15 + $i * 9 + $h(10 + $i, 6));
            $ksiezyce[] = ['nazwa' => $nazwa . ' ' . $rzymskie[$i], 'promien_km' => $promien,
                // Kepler przy gęstości jak Ziemia: okres ∝ (r/R)^1,5, skalowany do Księżyca.
                'okres_dni' => max(0.2, round(27.32 * pow($promien / 384400, 1.5) * pow(12742 / $srednica, 1.5), 3)),
                'faza' => (float) $h(20 + $i, 360), 'srednica_km' => null];
        }
    }
    $cykl['ksiezyce'] = $ksiezyce;
    // Słońca układu: główna gwiazda (nadpis MG albo losowanie z nazwy) i towarzysze (do 3 słońc).
    $ile = max(1, min(3, (int) ($mapa['slonca'] ?? 1)));
    $glowna = $nadpisy['gwiazda_typ'] ?? astro_typ_gwiazdy($nazwa);
    $cykl['gwiazdy'] = [astro_gwiazda($glowna, 0)];
    for ($i = 1; $i < $ile; $i++) $cykl['gwiazdy'][] = astro_gwiazda(astro_typ_gwiazdy($nazwa, 30 + $i), $i);
    $cykl['mapa_id'] = (int) ($mapa['id'] ?? 0);
    // Księżyc z planetą macierzystą (np. Nar Shaddaa → Nal Hutta): słońce, rok i pory roku z orbity
    // planety macierzystej; na niebie księżyca widać planetę macierzystą (`macierz`).
    if (!empty($nadpisy['macierz_planeta_id'])) {
        $q = polaczenie_bazy()->prepare('SELECT p.nazwa' . (tabela_sql_dostepna('planety_gry') ? ', g.typ_planety' : '') . ' FROM mapa_planety p'
            . (tabela_sql_dostepna('planety_gry') ? ' LEFT JOIN planety_gry g ON g.planeta_id = p.id' : '') . ' WHERE p.id = ?');
        $q->execute([(int) $nadpisy['macierz_planeta_id']]);
        if ($m = $q->fetch(PDO::FETCH_ASSOC)) {
            $cache[$klucz] = $cykl; // ochrona przed zapętleniem
            $rodzic = astro_cykl((string) $m['nazwa']);
            foreach (['faza_orbity', 'rok_dni', 'promien_orbity_au', 'gwiazdy'] as $pole) $cykl[$pole] = $rodzic[$pole];
            $cykl['macierz'] = ['nazwa' => $m['nazwa'], 'typ' => ($m['typ_planety'] ?? '') ?: 'skalista',
                'srednica_km' => $rodzic['srednica_km'], 'promien_km' => (float) ($nadpisy['promien_wokol_km'] ?? 400000),
                'okres_dni' => (float) ($nadpisy['okres_wokol_dni'] ?? 20), 'faza' => (float) ($nadpisy['faza_wokol_deg'] ?? 0)];
        }
    }
    return $cache[$klucz] = $cykl;
}

// Szerokość i długość geograficzna lokacji: pola szer/dl albo przeliczenie x/y (km od punktu 0/0
// na równiku) przez obwód planety.
function astro_wsp_lokacji(array $lok, array $cykl): array
{
    if (isset($lok['szer'], $lok['dl']) && is_numeric($lok['szer']) && is_numeric($lok['dl'])) {
        return [max(-90.0, min(90.0, (float) $lok['szer'])), (float) $lok['dl']];
    }
    $obwod = M_PI * max(1.0, $cykl['srednica_km']);
    $dl = fmod((float) ($lok['x'] ?? 0) / $obwod * 360, 360);
    $szer = max(-89.9, min(89.9, (float) ($lok['y'] ?? 0) / $obwod * 360));
    return [$szer, $dl];
}

// Światło w lokacji dla sceny (parametry do liczenia na tablecie).
function astro_swiatlo(string $planeta, array $lok): array
{
    $cykl = astro_cykl($planeta);
    [$szer, $dl] = astro_wsp_lokacji($lok, $cykl);
    return ['cykl' => $cykl, 'szer' => $szer, 'dl' => $dl];
}

function obsluz_astro(string $akcja, bool $post): void
{
    if ($akcja !== 'uklad') return;
    $u = wymagaj_logowania();
    $nazwa = trim((string) ($_GET['planeta'] ?? ''));
    if ($nazwa === '') blad('Brak planety.');
    if (tabela_sql_dostepna('mapa_planety')) {
        $q = polaczenie_bazy()->prepare('SELECT id FROM mapa_planety WHERE nazwa = ? LIMIT 1');
        $q->execute([$nazwa]);
        $id = (int) $q->fetchColumn();
        if ($id && !mapa_planeta_widoczna($id, $u)) blad('Brak danych.', 404);
    }
    odpowiedz(['cykl' => astro_cykl($nazwa), 'teraz' => czas_teraz()]);
}

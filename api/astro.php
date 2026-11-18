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
        $q = polaczenie_bazy()->prepare("SELECT id, doba, rok, ksiezyce, srednica FROM mapa_planety WHERE REPLACE(nazwa, ' ', '') = ? LIMIT 1");
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

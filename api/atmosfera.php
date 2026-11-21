<?php
// LOT W ATMOSFERZE (migracja 046): trzeci parametr silnika — prędkość atmosferyczna (km/h).
//
// Lądowanie: wejście w atmosferę pod miejscem jednostki na orbicie (orbita równikowa: szerokość 0°), potem lot po
// łuku wielkiego koła do lądowiska — nawet na drugą stronę planety. Start: lot w atmosferze pod okręt macierzysty
// (prom) albo prosto w górę, potem wznoszenie na orbitę. Współrzędne geograficzne lądowiska: z lokacji
// (x/y km → szerokość i długość, astro_wsp_lokacji; lokacje bez siatki — współrzędne zastępcze z Nawigacji).
// Kierunek punktu geograficznego na mapie (widok z góry): kierunek słońca + kąt godzinny długości — planeta się
// obraca, więc lądowisko krąży razem z nią (tak samo liczy start: floty_start).

declare(strict_types=1);

const ATM_WEJSCIE_S = 180;          // zejście z orbity w atmosferę

function atm_dostepna(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT atm_rodzaj, atm_do_utc, atm_szer0 FROM mapa_obiekty LIMIT 0');
              polaczenie_bazy()->query('SELECT atmosferyczna_kmh FROM mapa_naped_jednostki LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// Szerokość i długość geograficzna lokacji (id z lokalizacje).
function atm_geo_lokacji(int $lokId, array $cykl): array
{
    static $lok = null;
    if ($lok === null) $lok = function_exists('ruch_lokacje') ? ruch_lokacje() : [];
    $l = $lok['sql:' . $lokId] ?? null;
    $x = $l['x'] ?? null; $y = $l['y'] ?? null;
    if ($x === null || $y === null) {
        $q = polaczenie_bazy()->prepare('SELECT x_km, y_km FROM lokalizacje WHERE id = ?');
        $q->execute([$lokId]);
        $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $x = $r['x_km'] ?? 0; $y = $r['y_km'] ?? 0;
    }
    return astro_wsp_lokacji(['x' => (float) $x, 'y' => (float) $y], $cykl);
}

// Kierunek punktu o długości $dl na mapie (stopnie) w chwili $t i odwrotnie: długość pod kierunkiem.
function atm_kierunek(array $cykl, float $dl, int $t): float
{
    return fmod(astro_kat_slonca($cykl, $t) + astro_kat_godzinny($cykl, $dl, $t) + 720, 360);
}

function atm_dl_pod(array $cykl, float $kier, int $t): float
{
    $obrot = $cykl['faza_obrotu'] + 360 * ($t - $cykl['epoka']) / ($cykl['doba_h'] * 3600);
    $dl = fmod($kier - astro_kat_slonca($cykl, $t) - $obrot, 360);
    if ($dl > 180) $dl -= 360;
    if ($dl < -180) $dl += 360;
    return $dl;
}

// Długość łuku wielkiego koła (km) na planecie o promieniu $r.
function atm_luk_km(float $r, float $s0, float $d0, float $s1, float $d1): float
{
    [$f0, $f1, $dl] = [deg2rad($s0), deg2rad($s1), deg2rad($d1 - $d0)];
    $a = sin(($f1 - $f0) / 2) ** 2 + cos($f0) * cos($f1) * sin($dl / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

function atm_predkosc(int $id): float
{
    $q = polaczenie_bazy()->prepare('SELECT atmosferyczna_kmh FROM mapa_naped_jednostki WHERE obiekt_id = ?');
    $q->execute([$id]);
    return max(50.0, (float) ($q->fetchColumn() ?: 1000));
}

function atm_planeta(int $id): ?array
{
    $q = polaczenie_bazy()->prepare('SELECT o.planeta_id, p.nazwa, o.orbita_deg, o.kurs_deg FROM mapa_obiekty o JOIN mapa_planety p ON p.id = o.planeta_id WHERE o.id = ?');
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Rozpoczyna lądowanie: wejście w atmosferę pod jednostką i lot nad powierzchnią do lądowiska. Zwraca koniec (s).
function atm_ladowanie_start(int $id, int $lokId, int $teraz): int
{
    $o = atm_planeta($id);
    if (!$o) return $teraz;
    $cykl = astro_cykl((string) $o['nazwa']);
    $kier = (float) ($o['orbita_deg'] ?? fmod((float) ($o['kurs_deg'] ?? 90) + 180, 360));
    $d0 = atm_dl_pod($cykl, $kier, $teraz);
    [$s1, $d1] = atm_geo_lokacji($lokId, $cykl);
    $km = atm_luk_km((float) $cykl['srednica_km'] / 2, 0.0, $d0, $s1, $d1);
    $koniec = $teraz + ATM_WEJSCIE_S + (int) round($km / atm_predkosc($id) * 3600);
    polaczenie_bazy()->prepare("UPDATE mapa_obiekty SET atm_rodzaj = 'ladowanie', atm_od_utc = ?, atm_do_utc = ?, atm_szer0 = 0, atm_dl0 = ?,
            atm_szer1 = ?, atm_dl1 = ?, atm_orbita_deg = NULL, ladowanie_cel_id = ?, status = 'w atmosferze' WHERE id = ?")
        ->execute([czas_utc($teraz), czas_utc($koniec), round($d0, 4), round($s1, 4), round($d1, 4), $lokId, $id]);
    return $koniec;
}

// Start: lot w atmosferze z lądowiska pod docelowe miejsce na orbicie ($kierOrbity; null — prosto w górę),
// potem wznoszenie. Zwraca [koniec lotu w atmosferze, miejsce na orbicie].
function atm_start(int $id, int $lokId, ?float $kierOrbity, int $teraz): array
{
    $o = atm_planeta($id);
    $cykl = astro_cykl((string) $o['nazwa']);
    [$s0, $d0] = atm_geo_lokacji($lokId, $cykl);
    if ($kierOrbity === null) {
        $kierOrbity = atm_kierunek($cykl, $d0, $teraz);
        $s1 = $s0; $d1 = $d0; $km = 0.0; // prosto w górę
    } else {
        $d1 = atm_dl_pod($cykl, $kierOrbity, $teraz);
        $s1 = 0.0;
        $km = atm_luk_km((float) $cykl['srednica_km'] / 2, $s0, $d0, $s1, $d1);
    }
    $lot = (int) round($km / atm_predkosc($id) * 3600);
    polaczenie_bazy()->prepare("UPDATE mapa_obiekty SET atm_rodzaj = 'start', atm_od_utc = ?, atm_do_utc = ?, atm_szer0 = ?, atm_dl0 = ?,
            atm_szer1 = ?, atm_dl1 = ?, atm_orbita_deg = ? WHERE id = ?")
        ->execute([czas_utc($teraz), czas_utc($teraz + $lot + START_WZNOSZENIE_S), round($s0, 4), round($d0, 4), round($s1, 4), round($d1, 4), round($kierOrbity, 3), $id]);
    return [$teraz + $lot, $kierOrbity];
}

// Pola dla mapy: trwający lot w atmosferze i miejsce lądowiska (szerokość, długość).
function atm_pola(array $r): array
{
    $w = [];
    if (!empty($r['atm_do_utc']) && $r['atm_szer0'] !== null) {
        $do = czas_z_utc((string) $r['atm_do_utc']);
        if ($do > czas_teraz()) {
            $w['atmosfera'] = ['rodzaj' => $r['atm_rodzaj'], 'od' => czas_z_utc((string) $r['atm_od_utc']), 'do' => $do,
                'szer0' => (float) $r['atm_szer0'], 'dl0' => (float) $r['atm_dl0'], 'szer1' => (float) $r['atm_szer1'], 'dl1' => (float) $r['atm_dl1'],
                'faza_s' => $r['atm_rodzaj'] === 'start' ? START_WZNOSZENIE_S : ATM_WEJSCIE_S,
                'orbita' => $r['atm_orbita_deg'] !== null ? (float) $r['atm_orbita_deg'] : null];
        }
    }
    if (!empty($r['ladowisko_id']) && !empty($r['planeta'])) {
        [$s, $d] = atm_geo_lokacji((int) $r['ladowisko_id'], astro_cykl((string) $r['planeta']));
        $w['geo'] = ['szer' => round($s, 4), 'dl' => round($d, 4)];
    }
    return $w;
}

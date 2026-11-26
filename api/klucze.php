<?php
declare(strict_types=1);

// Klucze myśliwców i promów (migracja 066): klucz = 4 maszyny z hangaru okrętu, jeden mały trójkąt na mapie.
// Serwer planuje ruch klucza w chwili rozkazu: trasa w km od słońca układu ([t, kx, ky] — punkty w czasie gry),
// potem faza „przy celu”: krąży wokół celu (okręt macierzysty, chroniony, atakowany, skanowany) w promieniu
// po_promien_km. Pozycja w dowolnej chwili wynika z trasy i pozycji celu (ten sam wzór na serwerze i w mapie).
//
// Rozkazy: patrol (krąży wokół okrętu macierzystego), ochrona (wybranego okrętu w zasięgu), przechwyc (pościg —
// co 20 s kurs na bieżące miejsce celu), kurs (kurs przechwytujący — komputer liczy, gdzie cel będzie, i prowadzi
// prosto tam), atak (kurs przechwytujący i krążenie tuż przy celu), skan (kurs przechwytujący i krążenie
// w promieniu 40 km — wynik skanu w kolejnym etapie), powrot (do hangaru; po dolocie klucz znika z mapy).
// Prędkość: napęd podświetlny okrętu macierzystego × 1,7 (TIE) albo × 0,85 (Lambda). Zasięg operacyjny 1 mln km
// od okrętu macierzystego. Taśmociąg: jeden klucz na minutę.

const KLUCZ_ZASIEG_KM = 1000000.0;
const KLUCZ_OKRES_S = 120;          // jedno okrążenie celu
const KLUCZ_TASMA_S = 60;           // start kolejnego klucza z tego samego okrętu
const KLUCZ_MASZYNY = 4;
const KLUCZ_ROZKAZY = [
    'patrol' => ['patrol wokół okrętu', 150.0],
    'ochrona' => ['ochrona okrętu', 25.0],
    'przechwyc' => ['przechwycenie (pościg)', 10.0],
    'kurs' => ['kurs przechwytujący', 10.0],
    'atak' => ['atak', 3.0],
    'skan' => ['skan z bliska', 40.0],
    'powrot' => ['powrót do hangaru', 0.0],
];
const KLUCZ_RODZAJE = ['tie' => ['TIE', 1.7], 'lambda' => ['Lambda', 0.85]];

function klucze_dostepne(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT id, trasa_json FROM mapa_klucze LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

function klucze_lambda_dostepne(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT lambda_liczba FROM mapa_floty_jednostki LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// Piloci i skan z bliska (migracja 067): mapa_piloci, mapa_klucze.rozkaz_login / skan_stan / skan_opis.
function klucze_piloci_dostepni(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT p.id, k.skan_stan, k.rozkaz_login FROM mapa_piloci p, mapa_klucze k LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// Piloci: asy (ok. 5%), standardowi (60%), kadeci (35%). Doświadczenie globalne dla pilota; mnożnik zdobywania
// as ×0,5, standardowy ×1, kadet ×1,5. Poziom = 1 + ⌊√(xp / 40)⌋. Na pokładzie 2 pilotów na maszynę.
const PILOT_RANGI = ['as' => ['as', 0.5], 'standard' => ['standardowy', 1.0], 'kadet' => ['kadet', 1.5]];
const PILOT_IMIONA = ['Arven', 'Bael', 'Corin', 'Dace', 'Erel', 'Feyn', 'Garos', 'Hal', 'Ilan', 'Jorek', 'Kael', 'Lorn', 'Maris', 'Nyle', 'Oren',
    'Pell', 'Quinn', 'Rhys', 'Sela', 'Tarn', 'Ulric', 'Vala', 'Wes', 'Yara', 'Zev', 'Ansa', 'Brisa', 'Cade', 'Dren', 'Ezra', 'Fen', 'Ivra', 'Jace', 'Kira', 'Lira', 'Mako'];
const PILOT_NAZWISKA = ['Thrass', 'Veers', 'Kallus', 'Dorran', 'Vex', 'Sorne', 'Halcyn', 'Marek', 'Tolan', 'Crane', 'Draven', 'Ostrak', 'Pellaeon', 'Rennic',
    'Sarkis', 'Tavor', 'Ulmer', 'Vosk', 'Wexley', 'Yularen', 'Zorrin', 'Brand', 'Castell', 'Dalor', 'Erso', 'Fenn', 'Gorran', 'Holt', 'Iden', 'Jarrus'];
const PILOT_ZALOGA = ['tie' => 4, 'lambda' => 8]; // klucz TIE: 4 pilotów; Lambdy: pilot i drugi pilot na maszynę
const SKAN_S = 300;          // skan z bliska: 5 minut przy celu
const SKAN_XP = [40, 10];    // doświadczenie: udany, nieudany (× mnożnik rangi)

function klucze_poziom(int $xp): int { return 1 + (int) floor(sqrt(max(0, $xp) / 40)); }

// Uzupełnia pilotów okrętu do 2 na maszynę (TIE i Lambdy z hangaru). Imiona i rangi stałe z numeru pilota.
function klucze_piloci_zapewnij(int $okret): void
{
    if (!klucze_piloci_dostepni()) return;
    $db = polaczenie_bazy();
    $q = $db->prepare('SELECT tie_liczba' . (klucze_lambda_dostepne() ? ',lambda_liczba' : ',0 AS lambda_liczba') . ' FROM mapa_floty_jednostki WHERE obiekt_id=?');
    $q->execute([$okret]);
    $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $potrzeba = 2 * ((int) ($r['tie_liczba'] ?? 0) + (int) ($r['lambda_liczba'] ?? 0));
    $q = $db->prepare('SELECT COUNT(*) FROM mapa_piloci WHERE okret_id=?');
    $q->execute([$okret]);
    $jest = (int) $q->fetchColumn();
    if ($jest >= $potrzeba) return;
    $ins = $db->prepare('INSERT INTO mapa_piloci (okret_id, numer, imie, ranga, xp) VALUES (?,?,?,?,?)');
    for ($i = $jest + 1; $i <= $potrzeba; $i++) {
        $h = crc32("pilot|{$okret}|{$i}");
        $imie = PILOT_IMIONA[$h % count(PILOT_IMIONA)] . ' ' . PILOT_NAZWISKA[intdiv($h, 97) % count(PILOT_NAZWISKA)];
        $los = intdiv($h, 7) % 100;
        $ranga = $los < 5 ? 'as' : ($los < 65 ? 'standard' : 'kadet');
        $xp = match ($ranga) { 'as' => 2200 + $h % 1800, 'standard' => 400 + $h % 800, default => $h % 120 };
        $ins->execute([$okret, $i, $imie, $ranga, $xp]);
    }
}

// Przydział załogi do klucza: tryb as / standard / kadet (pierwszeństwo tej rangi, braki uzupełnia reszta) albo automat.
function klucze_zaloga_przydziel(int $okret, int $klucz, string $rodzaj, string $tryb): array
{
    if (!klucze_piloci_dostepni()) return [];
    klucze_piloci_zapewnij($okret);
    $kolejnosc = match ($tryb) { 'as' => ['as', 'standard', 'kadet'], 'kadet' => ['kadet', 'standard', 'as'], default => ['standard', 'kadet', 'as'] };
    $q = polaczenie_bazy()->prepare("SELECT id, ranga FROM mapa_piloci WHERE okret_id=? AND klucz_id IS NULL
        ORDER BY FIELD(ranga, ?, ?, ?), xp " . ($tryb === 'as' ? 'DESC' : 'ASC') . ', id LIMIT ' . (int) PILOT_ZALOGA[$rodzaj]);
    $q->execute([$okret, ...$kolejnosc]);
    $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    if (count($ids) < PILOT_ZALOGA[$rodzaj]) blad('Za mało wolnych pilotów na pokładzie — poczekaj na powrót innych kluczy.');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    polaczenie_bazy()->prepare("UPDATE mapa_piloci SET klucz_id=? WHERE id IN ($marks)")->execute([$klucz, ...$ids]);
    return $ids;
}

function klucze_zaloga(int $klucz): array
{
    if (!klucze_piloci_dostepni()) return [];
    $q = polaczenie_bazy()->prepare('SELECT id, imie, ranga, xp FROM mapa_piloci WHERE klucz_id=? ORDER BY FIELD(ranga,\'as\',\'standard\',\'kadet\'), xp DESC');
    $q->execute([$klucz]);
    return array_map(static fn($p) => ['id' => (int) $p['id'], 'imie' => $p['imie'], 'ranga' => $p['ranga'], 'ranga_opis' => PILOT_RANGI[$p['ranga']][0] ?? $p['ranga'],
        'xp' => (int) $p['xp'], 'poziom' => klucze_poziom((int) $p['xp'])], $q->fetchAll(PDO::FETCH_ASSOC));
}

// Ruch w tle (kontakty NPC) w układzie punktu mapy: id → kontakt (jak na radarze, bez identyfikacji).
function klucze_npc_uklad(float $x, float $y): array
{
    static $pamiec = [];
    $klucz = round($x, 1) . '|' . round($y, 1);
    if (!isset($pamiec[$klucz])) {
        $pamiec[$klucz] = [];
        if (function_exists('radar_tlo')) {
            try { foreach (radar_tlo([['x' => $x, 'y' => $y, 'zasieg' => 0.5]], [], [], false) as $c) $pamiec[$klucz][(int) $c['id']] = $c; }
            catch (Throwable $e) { $pamiec[$klucz] = []; }
        }
        klucze_npc_rejestr($pamiec[$klucz]);
    }
    return $pamiec[$klucz];
}
function klucze_npc_rejestr(?array $dodaj = null): array
{
    static $wszystkie = [];
    if ($dodaj) $wszystkie = $dodaj + $wszystkie;
    return $wszystkie;
}

// Pozycja kontaktu NPC (km od słońca) w chwili $t z jego bieżącej fazy: lot podświetlny (k0 → k1 w czasie od–do),
// postój przy planecie (planeta krąży), punkt układu. Skok w nadprzestrzeń — poza zasięgiem kluczy.
function klucze_npc_km(int $id, int $t): ?array
{
    $c = klucze_npc_rejestr()[$id] ?? null;
    if (!$c) return null;
    $l = $c['podswietlny'] ?? null;
    if ($l && isset($l['k0'], $l['k1'])) {
        $f = max(0.0, min(1.0, ($t - (int) $l['od']) / max(1, (int) $l['do'] - (int) $l['od'])));
        return [$l['k0'][0] + ($l['k1'][0] - $l['k0'][0]) * $f, $l['k0'][1] + ($l['k1'][1] - $l['k0'][1]) * $f];
    }
    if (!empty($c['planeta'])) {
        $cy = astro_cykl((string) $c['planeta']);
        [$kx, $ky] = astro_pozycja_km($cy, $t);
        return [$kx + (float) ($cy['srednica_km'] ?? 12000) / 2 + 300, $ky];
    }
    if (!empty($c['uklad_k'])) return [(float) $c['uklad_k'][0], (float) $c['uklad_k'][1]];
    return null;
}

// Wiersz jednostki mapy potrzebny do pozycji w układzie (pamięć na czas żądania).
function klucze_obiekt(int $id): ?array
{
    static $pamiec = [];
    if (!array_key_exists($id, $pamiec)) {
        $q = polaczenie_bazy()->prepare('SELECT id,nazwa,typ,x,y,planeta_id,kurs_deg,orbita_deg,podejscie,kurs_start_deg,kurs_cel_deg,manewr_od_utc,manewr_do_utc'
            . (floty_uklad_dostepny() ? ',uklad_kx,uklad_ky' : '') . (floty_lot_dostepny() ? ',lot_do_utc' : '')
            . ' FROM mapa_obiekty WHERE id=? AND aktywny=1');
        $q->execute([$id]);
        $pamiec[$id] = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return $pamiec[$id];
}

// Pozycja jednostki w jej układzie (km od słońca) w chwili $t: lot podświetlny, miejsce na orbicie planety
// (promień z podejścia, kąt orbita_deg) albo punkt układu. Null — poza układem / brak danych.
function klucze_obiekt_km(int $id, int $t): ?array
{
    if ($id < 0) return klucze_npc_km($id, $t);
    $o = klucze_obiekt($id);
    if (!$o) return null;
    if (!empty($o['lot_do_utc'])) {
        $poz = floty_lot_pozycja($id, $t);
        if ($poz && ($poz[2] ?? null) !== null) return [(float) $poz[2], (float) $poz[3]];
    }
    if (!empty($o['planeta_id'])) {
        static $planety = [];
        $pid = (int) $o['planeta_id'];
        $planety[$pid] ??= astro_cykl((string) (ruch_planeta((string) $pid)['nazwa'] ?? ''));
        $c = $planety[$pid];
        [$kx, $ky] = astro_pozycja_km($c, $t);
        $r = (float) ($c['srednica_km'] ?? 12000) / 2;
        $ro = floty_promien_orbity_km($r, ($o['podejscie'] ?? null) ?: 'daleka') ?? $r * 15;
        $kat = deg2rad($o['orbita_deg'] !== null ? (float) $o['orbita_deg'] : floty_kat(floty_kurs_teraz($o, $t) + 180));
        return [$kx + cos($kat) * $ro, $ky + sin($kat) * $ro];
    }
    if (isset($o['uklad_kx']) && $o['uklad_kx'] !== null) return [(float) $o['uklad_kx'], (float) $o['uklad_ky']];
    return null;
}

function klucze_trasa(array $k): array
{
    return is_array($k['trasa'] ?? null) ? $k['trasa'] : (json_decode((string) ($k['trasa_json'] ?? '[]'), true) ?: []);
}

// Miejsce na okręgu wokół celu w fazie „przy celu” (kąt od chwili dolotu).
function klucze_okrag(float $r, int $tDolot, int $t): array
{
    $a = 2 * M_PI * ($t - $tDolot) / KLUCZ_OKRES_S;
    return [cos($a) * $r, sin($a) * $r];
}

// Pozycja klucza (km od słońca) w chwili $t.
function klucze_km(array $k, int $t): ?array
{
    $tr = klucze_trasa($k);
    if (!$tr) return null;
    $n = count($tr) - 1;
    if ($t <= $tr[0][0]) return [(float) $tr[0][1], (float) $tr[0][2]];
    if ($t < $tr[$n][0]) {
        for ($i = 0; $i < $n; $i++) {
            if ($t <= $tr[$i + 1][0]) {
                $f = ($t - $tr[$i][0]) / max(1, $tr[$i + 1][0] - $tr[$i][0]);
                return [$tr[$i][1] + ($tr[$i + 1][1] - $tr[$i][1]) * $f, $tr[$i][2] + ($tr[$i + 1][2] - $tr[$i][2]) * $f];
            }
        }
    }
    if (!empty($k['po_cel_id'])) {
        if ((int) $k['po_cel_id'] < 0) klucze_npc_uklad((float) $k['uklad_x'], (float) $k['uklad_y']);
        $c = klucze_obiekt_km((int) $k['po_cel_id'], $t);
        if ($c) { [$ox, $oy] = klucze_okrag((float) $k['po_promien_km'], (int) $tr[$n][0], $t); return [$c[0] + $ox, $c[1] + $oy]; }
    }
    return [(float) $tr[$n][1], (float) $tr[$n][2]];
}

// Najwcześniejsza chwila, w której klucz z $k0 (w chwili $t0, prędkość $v km/s) dosięga celu $cel(t) — kurs
// przechwytujący na przewidywane miejsce celu. Null — cel poza zasięgiem czasu/układu.
function klucze_przechwyt(array $k0, int $t0, callable $cel, float $v, int $tMax): ?int
{
    $krok = 30; $poprz = 0;
    for ($dt = 0; $dt <= $tMax; $dt += $krok) {
        $p = $cel($t0 + $dt);
        if (!$p) return null;
        if (hypot($p[0] - $k0[0], $p[1] - $k0[1]) <= $v * $dt) {
            $a = $poprz; $b = $dt;
            for ($i = 0; $i < 8; $i++) {
                $m = ($a + $b) / 2; $q = $cel($t0 + (int) round($m));
                if ($q && hypot($q[0] - $k0[0], $q[1] - $k0[1]) <= $v * $m) $b = $m; else $a = $m;
            }
            return $t0 + (int) ceil($b);
        }
        $poprz = $dt;
    }
    return null;
}

// Plan ruchu dla rozkazu: trasa [[t, kx, ky], …] i faza przy celu. $start — miejsce i chwila początku.
function klucze_plan(array $klucz, string $rozkaz, ?int $celId, int $t0, array $k0): array
{
    $okret = (int) $klucz['okret_id'];
    $v = (float) $klucz['predkosc_kms'];
    $po = in_array($rozkaz, ['patrol', 'powrot'], true) ? $okret : $celId;
    if (!$po) blad('Wskaż cel rozkazu.');
    $r = KLUCZ_ROZKAZY[$rozkaz][1];
    if ($po < 0) { $m = klucze_obiekt($okret); if ($m) klucze_npc_uklad((float) $m['x'], (float) $m['y']); }
    $cel = static fn(int $t) => klucze_obiekt_km($po, $t);
    if (!$cel($t0)) blad('Cel jest poza układem klucza albo w nadprzestrzeni.');
    $tMax = (int) min(6 * 3600, 2 * KLUCZ_ZASIEG_KM / max(1.0, $v) + 900);
    $trasa = [[$t0, round($k0[0], 1), round($k0[1], 1)]];
    if ($rozkaz === 'przechwyc') {
        // Pościg: co 20 s kurs na miejsce, w którym cel jest teraz (krzywa pościgowa — dłuższa niż kurs przechwytujący).
        $p = $k0; $t = $t0; $dt = 20;
        for ($i = 0; $i < 1080; $i++) {
            $c = $cel($t);
            if (!$c) blad('Cel wyszedł poza układ — pościg przerwany.');
            $d = hypot($c[0] - $p[0], $c[1] - $p[1]);
            if ($d <= $v * $dt) {
                $t += (int) ceil($d / $v);
                $c = $cel($t) ?? $c;
                $trasa[] = [$t, round($c[0] + $r, 1), round($c[1], 1)];
                break;
            }
            $p = [$p[0] + ($c[0] - $p[0]) / $d * $v * $dt, $p[1] + ($c[1] - $p[1]) / $d * $v * $dt];
            $t += $dt;
            if ($i % 3 === 2) $trasa[] = [$t, round($p[0], 1), round($p[1], 1)];
        }
        if (count($trasa) < 2 || $i >= 1080) blad('Klucz nie dogoni celu — użyj kursu przechwytującego.');
        $tDolot = (int) end($trasa)[0];
    } else {
        $tDolot = klucze_przechwyt($k0, $t0, $cel, $v, $tMax);
        if ($tDolot === null) blad('Komputer nie wyznaczył kursu przechwytującego — cel za szybki albo za daleko.');
        $c = $cel($tDolot);
        $trasa[] = [max($tDolot, $t0 + 1), round($c[0] + $r, 1), round($c[1], 1)];
    }
    // Zasięg operacyjny: miejsce dolotu nie dalej niż 1 mln km od okrętu macierzystego (w tej chwili).
    if ($po !== $okret) {
        $m = klucze_obiekt_km($okret, $tDolot);
        $e = end($trasa);
        if ($m && hypot($e[1] - $m[0], $e[2] - $m[1]) > KLUCZ_ZASIEG_KM) blad('Cel poza zasięgiem operacyjnym (1 mln km od okrętu macierzystego).');
    }
    return ['trasa' => $trasa, 'po_cel_id' => $po, 'po_promien_km' => $r, 'dolot' => $tDolot];
}

// Prędkość klucza: napęd podświetlny okrętu × współczynnik rodzaju.
function klucze_predkosc(int $okret, string $rodzaj): float
{
    $q = polaczenie_bazy()->prepare('SELECT ' . (floty_przyspieszenie_dostepne() ? 'COALESCE(podswietlna_pc_doba,20) AS g,' : '')
        . 'COALESCE(predkosc_podswietlna_kms,300) AS v FROM mapa_naped_jednostki WHERE obiekt_id=?');
    $q->execute([$okret]);
    $n = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $baza = isset($n['g']) ? (float) $n['g'] * LOT_UKLAD_KMS_NA_PC_DOBA : (float) ($n['v'] ?? (floty_przyspieszenie_dostepne() ? 20 * LOT_UKLAD_KMS_NA_PC_DOBA : 300));
    return round(max(10.0, $baza) * KLUCZ_RODZAJE[$rodzaj][1], 1);
}

function klucze_moze(array $u, int $okret): bool
{
    if (($u['rola'] ?? '') === 'mg') return true;
    $login = (string) ($u['login'] ?? '');
    if (floty_jednostka_moze($login, $okret)) return true;
    if (function_exists('wskazniki_kapitan') && wskazniki_kapitan('okret:' . $okret, $u)) return true;
    $q = polaczenie_bazy()->prepare('SELECT 1 FROM mapa_obiekty_dostep WHERE obiekt_id=? AND LOWER(konto_login)=LOWER(?) LIMIT 1');
    $q->execute([$okret, $login]);
    return (bool) $q->fetchColumn();
}

// Hangar okrętu: ile kluczy TIE i Lambda mieści, ile jest w locie.
function klucze_hangar(int $okret): array
{
    $q = polaczenie_bazy()->prepare('SELECT tie_liczba' . (klucze_lambda_dostepne() ? ',lambda_liczba' : ',NULL AS lambda_liczba') . ' FROM mapa_floty_jednostki WHERE obiekt_id=?');
    $q->execute([$okret]);
    $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $q = polaczenie_bazy()->prepare('SELECT rodzaj, COUNT(*) FROM mapa_klucze WHERE okret_id=? GROUP BY rodzaj');
    $q->execute([$okret]);
    $wLocie = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = [];
    foreach (['tie' => 'tie_liczba', 'lambda' => 'lambda_liczba'] as $rodzaj => $kol) {
        $razem = intdiv((int) ($r[$kol] ?? 0), KLUCZ_MASZYNY);
        $out[$rodzaj] = ['razem' => $razem, 'w_locie' => (int) ($wLocie[$rodzaj] ?? 0), 'w_hangarze' => max(0, $razem - (int) ($wLocie[$rodzaj] ?? 0))];
    }
    return $out;
}

// Klucz dla mapy: trasa, faza przy celu, opis rozkazu. Klucze po powrocie do hangaru są usuwane.
function klucze_dla_mapy(array $k): array
{
    $tr = klucze_trasa($k);
    return ['id' => (int) $k['id'], 'okret_id' => (int) $k['okret_id'], 'okret' => $k['okret'] ?? null,
        'nazwa' => ($k['rodzaj'] === 'lambda' ? 'Lambda ' : 'Klucz ') . (int) $k['numer'],
        'rodzaj' => $k['rodzaj'], 'maszyny' => (int) $k['maszyny'], 'rozkaz' => $k['rozkaz'], 'rozkaz_opis' => KLUCZ_ROZKAZY[$k['rozkaz']][0] ?? $k['rozkaz'],
        'cel_id' => $k['cel_obiekt_id'] === null ? null : (int) $k['cel_obiekt_id'], 'cel' => $k['cel'] ?? null,
        'uklad' => [(float) $k['uklad_x'], (float) $k['uklad_y']], 'trasa' => $tr, 'start' => czas_z_utc((string) $k['start_utc']),
        'po' => $k['po_cel_id'] === null ? null : ['id' => (int) $k['po_cel_id'], 'r' => (float) $k['po_promien_km'], 'okres' => KLUCZ_OKRES_S],
        'predkosc_kms' => (float) $k['predkosc_kms'],
        'skan' => empty($k['skan_stan']) ? null : ['stan' => $k['skan_stan'], 'opis' => $k['skan_opis'] ?? null,
            'koniec' => $k['rozkaz'] === 'skan' && $tr ? (int) end($tr)[0] + SKAN_S : null]];
}

function klucze_wiersze(?int $okret = null): array
{
    $teraz = czas_teraz();
    $db = polaczenie_bazy();
    // Powrót zakończony: klucz wylądował w hangarze.
    foreach ($db->query("SELECT id, trasa_json FROM mapa_klucze WHERE rozkaz='powrot'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tr = json_decode((string) $r['trasa_json'], true) ?: [];
        if (!$tr || $teraz >= (int) end($tr)[0]) {
            $db->prepare('DELETE FROM mapa_klucze WHERE id=?')->execute([(int) $r['id']]);
            if (klucze_piloci_dostepni()) $db->prepare('UPDATE mapa_piloci SET klucz_id=NULL WHERE klucz_id=?')->execute([(int) $r['id']]);
        }
    }
    klucze_skany_rozstrzygnij();
    $q = $db->prepare('SELECT k.*, o.nazwa AS okret, c.nazwa AS cel FROM mapa_klucze k JOIN mapa_obiekty o ON o.id=k.okret_id
        LEFT JOIN mapa_obiekty c ON c.id=k.cel_obiekt_id' . ($okret ? ' WHERE k.okret_id=?' : '') . ' ORDER BY k.okret_id, k.rodzaj, k.numer');
    $q->execute($okret ? [$okret] : []);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

// Jednostki układu okrętu (cele rozkazów): poza nim samym, bez postaci i jednostek w nadprzestrzeni.
function klucze_cele(int $okret): array
{
    $o = klucze_obiekt($okret);
    if (!$o || $o['x'] === null) return [];
    $q = polaczenie_bazy()->prepare("SELECT o.id, o.nazwa, o.klasa, o.wlasciciel FROM mapa_obiekty o
        WHERE o.aktywny=1 AND o.typ IN ('statek','jednostka') AND o.id<>? AND ABS(o.x-?)<? AND ABS(o.y-?)<?"
        . (floty_hangar_dostepny() ? ' AND COALESCE(o.w_hangarze,0)=0' : '')
        . " AND NOT EXISTS (SELECT 1 FROM mapa_rozkazy_skokow_jednostki r WHERE r.obiekt_id=o.id AND r.status='w_nadprzestrzeni') ORDER BY o.nazwa LIMIT 200");
    $q->execute([$okret, (float) $o['x'], LOT_W_UKLADZIE_PC, (float) $o['y'], LOT_W_UKLADZIE_PC]);
    $cele = array_map(static fn($r) => ['id' => (int) $r['id'], 'nazwa' => $r['nazwa'], 'klasa' => $r['klasa'], 'wlasciciel' => $r['wlasciciel']], $q->fetchAll(PDO::FETCH_ASSOC));
    // Ruch w tle (statki NPC) w tym układzie — także bez transpondera (cel skanu z bliska).
    foreach (klucze_npc_uklad((float) $o['x'], (float) $o['y']) as $id => $c) {
        if (klucze_npc_km($id, czas_teraz()) === null) continue;
        $cele[] = ['id' => $id, 'nazwa' => (string) $c['nazwa'], 'klasa' => (string) ($c['klasa'] ?? ''), 'wlasciciel' => null, 'npc' => true];
    }
    return $cele;
}

// Skan z bliska: klucz z rozkazem „skan” krąży 40 km od celu; po 5 minutach przy celu wynik zależy od poziomu
// załogi (50% + 6% za średni poziom, najwyżej 95%). Udany — prawdziwa nazwa, ładunek i trasa trafiają do identyfikacji
// radaru konta, które wydało rozkaz (jak analiza sygnatury); piloci dostają doświadczenie (× mnożnik rangi).
function klucze_skany_rozstrzygnij(): void
{
    if (!klucze_piloci_dostepni()) return;
    $db = polaczenie_bazy();
    $teraz = czas_teraz();
    foreach ($db->query("SELECT * FROM mapa_klucze WHERE rozkaz='skan' AND skan_stan='trwa'")->fetchAll(PDO::FETCH_ASSOC) as $k) {
        $tr = klucze_trasa($k);
        if (!$tr || $teraz < (int) end($tr)[0] + SKAN_S) continue;
        $cel = (int) $k['cel_obiekt_id'];
        $zaloga = klucze_zaloga((int) $k['id']);
        $poziom = $zaloga ? array_sum(array_column($zaloga, 'poziom')) / count($zaloga) : 1;
        $szansa = min(95, 50 + 6 * $poziom);
        $udany = (crc32("skan|{$k['id']}|{$k['rozkaz_utc']}") % 100) < $szansa;
        $dane = $udany ? klucze_skan_dane($cel, (float) $k['uklad_x'], (float) $k['uklad_y']) : null;
        $opis = 'Skan nieudany — cel zagłuszył sensory.';
        if ($udany && !$dane) { $udany = false; $opis = 'Cel opuścił układ przed końcem skanu.'; }
        elseif ($udany) {
            $opis = trim($dane['nazwa'] . ($dane['ladunek'] ? " · ładunek: {$dane['ladunek']}" : '') . ($dane['trasa'] ? " · trasa: {$dane['trasa']}" : ''));
            if (!empty($k['rozkaz_login']) && function_exists('radar_dostepny') && radar_dostepny()) {
                $db->prepare("INSERT INTO radar_identyfikacje (konto_login,cel_id,nazwa,frakcja,poszukiwany,nagroda,kiedy) VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE nazwa=VALUES(nazwa), frakcja=VALUES(frakcja), poszukiwany=VALUES(poszukiwany), nagroda=VALUES(nagroda), kiedy=VALUES(kiedy)")
                    ->execute([$k['rozkaz_login'], $cel, mb_substr($dane['nazwa'], 0, 160), $dane['frakcja'], $dane['poszukiwany'] ? 1 : 0, $dane['nagroda'], czas_utc($teraz)]);
            }
        }
        $xp = SKAN_XP[$udany ? 0 : 1];
        foreach ($zaloga as $p) {
            $db->prepare('UPDATE mapa_piloci SET xp = xp + ? WHERE id=?')->execute([(int) round($xp * PILOT_RANGI[$p['ranga']][1]), $p['id']]);
        }
        $db->prepare('UPDATE mapa_klucze SET skan_stan=?, skan_opis=? WHERE id=?')->execute([$udany ? 'udany' : 'nieudany', mb_substr($opis, 0, 250), (int) $k['id']]);
    }
}

// Prawdziwe dane celu skanu: nazwa, frakcja, ładunek, trasa, poszukiwany (baza sygnatur). Null — celu nie ma w układzie.
function klucze_skan_dane(int $cel, float $x, float $y): ?array
{
    $db = polaczenie_bazy();
    if ($cel < 0) {
        $c = klucze_npc_uklad($x, $y)[$cel] ?? null;
        if (!$c) return null;
        $nazwa = (string) $c['nazwa']; $ladunek = $c['radar']['ladunek'] ?? null; $trasa = $c['radar']['trasa'] ?? null; $frakcja = $c['frakcja'] ?? null;
        if ($cel > -10000000 && tabela_sql_dostepna('npc_trasy')) { // stara trasa NPC: dane z rejestru tras
            $q = $db->prepare('SELECT nazwa, frakcja, ladunek, port_a, port_b FROM npc_trasy WHERE id=?');
            $q->execute([-$cel]);
            if ($t = $q->fetch(PDO::FETCH_ASSOC)) { $nazwa = $t['nazwa']; $frakcja = $t['frakcja'] ?: null; $ladunek = $t['ladunek']; $trasa = "{$t['port_a']} ⇄ {$t['port_b']}"; }
        }
        return ['nazwa' => $nazwa, 'frakcja' => $frakcja, 'ladunek' => $ladunek, 'trasa' => $trasa, 'poszukiwany' => false, 'nagroda' => 0];
    }
    $radar = function_exists('radar_dostepny') && radar_dostepny();
    $q = $db->prepare('SELECT m.nazwa, m.frakcja, m.wlasciciel, m.x, m.y' . ($radar ? ', b.nagroda, b.obiekt_id AS w_bazie' : ', 0 AS nagroda, NULL AS w_bazie')
        . ' FROM mapa_obiekty m' . ($radar ? ' LEFT JOIN radar_baza_sygnatur b ON b.obiekt_id=m.id' : '') . ' WHERE m.id=? AND m.aktywny=1');
    $q->execute([$cel]);
    $c = $q->fetch(PDO::FETCH_ASSOC);
    if (!$c || hypot((float) $c['x'] - $x, (float) $c['y'] - $y) > LOT_W_UKLADZIE_PC) return null;
    return ['nazwa' => (string) $c['nazwa'], 'frakcja' => $c['frakcja'] ?: (function_exists('mapa_frakcja_z_opisu') ? mapa_frakcja_z_opisu((string) $c['wlasciciel']) : null),
        'ladunek' => null, 'trasa' => null, 'poszukiwany' => (bool) $c['w_bazie'], 'nagroda' => (int) ($c['nagroda'] ?? 0)];
}

// Wypuszczenie klucza z hangaru: start z taśmociągu (jeden klucz na minutę), rozkaz początkowy — patrol.
// $sprawdzony — uprawnienie sprawdził już wołający (stanowisko Myśliwce na mostku, api/lot_reczny.php); $numerChciany —
// klucz wskazany na pulpicie (wolny numer), inaczej najniższy wolny.
function klucze_start(array $u, int $okret, string $rodzaj, string $tryb = 'automat', bool $sprawdzony = false, ?int $numerChciany = null): array
{
    if (!isset(KLUCZ_RODZAJE[$rodzaj])) blad('Nieznany rodzaj maszyn.');
    if (!$sprawdzony && !klucze_moze($u, $okret)) blad('Nie dowodzisz tym okrętem.', 403);
    $db = polaczenie_bazy();
    $teraz = czas_teraz();
    $o = klucze_obiekt($okret);
    if (!$o || $o['x'] === null) blad('Okręt nie ma pozycji na mapie.');
    $q = $db->prepare("SELECT 1 FROM mapa_rozkazy_skokow_jednostki WHERE obiekt_id=? AND status='w_nadprzestrzeni' LIMIT 1");
    $q->execute([$okret]);
    if ($q->fetchColumn()) blad('Okręt jest w nadprzestrzeni — hangar zamknięty.');
    $hangar = klucze_hangar($okret);
    if ($hangar[$rodzaj]['w_hangarze'] < 1) blad($hangar[$rodzaj]['razem'] ? 'Wszystkie klucze tego rodzaju są już w locie.' : 'Okręt nie ma w hangarze maszyn tego rodzaju.');
    $q = $db->prepare('SELECT MAX(start_utc) FROM mapa_klucze WHERE okret_id=?');
    $q->execute([$okret]);
    $ostatni = $q->fetchColumn();
    $start = max($teraz + 10, $ostatni ? czas_z_utc((string) $ostatni) + KLUCZ_TASMA_S : 0);
    $k0 = klucze_obiekt_km($okret, $start);
    if (!$k0) blad('Okręt jest poza układem planetarnym — klucze startują w układzie.');
    $q = $db->prepare('SELECT numer FROM mapa_klucze WHERE okret_id=? AND rodzaj=?');
    $q->execute([$okret, $rodzaj]);
    $zajete = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    $numer = 1; while (in_array($numer, $zajete, true)) $numer++;
    if ($numerChciany !== null && $numerChciany >= 1 && !in_array($numerChciany, $zajete, true)) $numer = $numerChciany;
    $klucz = ['okret_id' => $okret, 'predkosc_kms' => klucze_predkosc($okret, $rodzaj)];
    $plan = klucze_plan($klucz, 'patrol', null, $start, $k0);
    if (!in_array($tryb, ['automat', 'as', 'standard', 'kadet'], true)) blad('Nieznany przydział załogi.');
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO mapa_klucze (okret_id,numer,rodzaj,maszyny,uklad_x,uklad_y,rozkaz,cel_obiekt_id,po_cel_id,po_promien_km,trasa_json,predkosc_kms,start_utc,rozkaz_utc)
            VALUES (?,?,?,?,?,?,\'patrol\',NULL,?,?,?,?,?,?)')
            ->execute([$okret, $numer, $rodzaj, KLUCZ_MASZYNY, (float) $o['x'], (float) $o['y'], $plan['po_cel_id'], $plan['po_promien_km'],
                json_encode($plan['trasa']), $klucz['predkosc_kms'], czas_utc($start), czas_utc($teraz)]);
        $nowy = (int) $db->lastInsertId();
        klucze_zaloga_przydziel($okret, $nowy, $rodzaj, $tryb);
        if (klucze_piloci_dostepni()) $db->prepare('UPDATE mapa_klucze SET rozkaz_login=? WHERE id=?')->execute([(string) $u['login'], $nowy]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return ['ok' => true, 'start' => $start, 'nazwa' => ($rodzaj === 'lambda' ? 'Lambda ' : 'Klucz ') . $numer, 'hangar' => klucze_hangar($okret),
        'zaloga' => klucze_zaloga($nowy), 'id' => $nowy];
}

// Rozkaz dla zaznaczonych kluczy: nowa trasa od miejsca, w którym każdy klucz jest teraz.
function klucze_rozkaz_wydaj(array $u, array $ids, string $rozkaz, ?int $celId, bool $sprawdzony = false): array
{
    if (!isset(KLUCZ_ROZKAZY[$rozkaz])) blad('Nieznany rozkaz.');
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) blad('Zaznacz klucze.');
    $db = polaczenie_bazy();
    $teraz = czas_teraz();
    $wyniki = [];
    foreach ($ids as $id) {
        $q = $db->prepare('SELECT * FROM mapa_klucze WHERE id=?');
        $q->execute([$id]);
        $k = $q->fetch(PDO::FETCH_ASSOC);
        if (!$k) blad('Brak klucza.');
        if (!$sprawdzony && !klucze_moze($u, (int) $k['okret_id'])) blad('Nie dowodzisz tym kluczem.', 403);
        if ($celId && $celId === (int) $k['okret_id'] && !in_array($rozkaz, ['ochrona', 'patrol', 'powrot'], true)) blad('Okręt macierzysty nie może być celem tego rozkazu.');
        $t0 = max($teraz, czas_z_utc((string) $k['start_utc']));
        $k0 = klucze_km($k, $t0);
        if (!$k0) blad('Brak pozycji klucza.');
        $plan = klucze_plan($k, $rozkaz, $celId, $t0, $k0);
        $db->prepare('UPDATE mapa_klucze SET rozkaz=?, cel_obiekt_id=?, po_cel_id=?, po_promien_km=?, trasa_json=?, rozkaz_utc=? WHERE id=?')
            ->execute([$rozkaz, in_array($rozkaz, ['patrol', 'powrot'], true) ? null : $celId, $plan['po_cel_id'], $plan['po_promien_km'], json_encode($plan['trasa']), czas_utc($teraz), $id]);
        if (klucze_piloci_dostepni()) $db->prepare('UPDATE mapa_klucze SET rozkaz_login=?, skan_stan=?, skan_opis=NULL WHERE id=?')
            ->execute([(string) $u['login'], $rozkaz === 'skan' ? 'trwa' : null, $id]);
        $wyniki[] = ['id' => $id, 'dolot' => $plan['dolot']];
    }
    return ['ok' => true, 'klucze' => $wyniki, 'dolot' => max(array_column($wyniki, 'dolot'))];
}

function obsluz_klucze(string $akcja, bool $post): void
{
    if (!in_array($akcja, ['mapa_klucze', 'mapa_klucze_okretu', 'mapa_klucz_start', 'mapa_klucz_rozkaz'], true)) return;
    $u = wymagaj_logowania();
    if (!klucze_dostepne()) {
        if (!$post) odpowiedz(['klucze' => [], 'brak_migracji' => true]);
        blad('Klucze myśliwców wymagają migracji 066.');
    }
    floty_aktualizuj_ruch();
    $db = polaczenie_bazy();
    $teraz = czas_teraz();
    switch ($akcja) {
        case 'mapa_klucze':
            // Klucze okrętów, którymi konto może dowodzić (MG — wszystkie).
            $moze = [];
            $out = [];
            foreach (klucze_wiersze() as $k) {
                $id = (int) $k['okret_id'];
                $moze[$id] ??= klucze_moze($u, $id);
                if ($moze[$id]) $out[] = klucze_dla_mapy($k);
            }
            odpowiedz(['klucze' => $out, 'teraz' => $teraz]);

        case 'mapa_klucze_okretu':
            $okret = (int) ($_GET['obiekt'] ?? 0);
            if (!klucze_moze($u, $okret)) odpowiedz(['mozna' => false]);
            $hangar = klucze_hangar($okret);
            if (!$hangar['tie']['razem'] && !$hangar['lambda']['razem']) odpowiedz(['mozna' => true, 'hangar' => $hangar, 'klucze' => []]);
            $piloci = null;
            if (klucze_piloci_dostepni()) {
                klucze_piloci_zapewnij($okret);
                $q = $db->prepare('SELECT ranga, COUNT(*) AS ile, SUM(klucz_id IS NOT NULL) AS w_locie FROM mapa_piloci WHERE okret_id=? GROUP BY ranga');
                $q->execute([$okret]);
                $piloci = ['rangi' => [], 'razem' => 0, 'w_locie' => 0];
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $piloci['rangi'][$r['ranga']] = ['ile' => (int) $r['ile'], 'w_locie' => (int) $r['w_locie'], 'opis' => PILOT_RANGI[$r['ranga']][0] ?? $r['ranga'], 'mnoznik' => PILOT_RANGI[$r['ranga']][1] ?? 1];
                    $piloci['razem'] += (int) $r['ile']; $piloci['w_locie'] += (int) $r['w_locie'];
                }
                $q = $db->prepare('SELECT imie, ranga, xp, klucz_id FROM mapa_piloci WHERE okret_id=? ORDER BY xp DESC LIMIT 8');
                $q->execute([$okret]);
                $piloci['najlepsi'] = array_map(static fn($p) => ['imie' => $p['imie'], 'ranga' => PILOT_RANGI[$p['ranga']][0] ?? $p['ranga'], 'xp' => (int) $p['xp'],
                    'poziom' => klucze_poziom((int) $p['xp']), 'w_locie' => $p['klucz_id'] !== null], $q->fetchAll(PDO::FETCH_ASSOC));
            }
            $lista = array_map(static fn($k) => klucze_dla_mapy($k) + ['zaloga' => klucze_zaloga((int) $k['id'])], klucze_wiersze($okret));
            odpowiedz(['mozna' => true, 'hangar' => $hangar, 'klucze' => $lista, 'piloci' => $piloci,
                'cele' => klucze_cele($okret), 'rozkazy' => array_map(static fn($r) => $r[0], KLUCZ_ROZKAZY), 'zasieg_km' => KLUCZ_ZASIEG_KM]);

        case 'mapa_klucz_start':
            if (!$post) blad('Zła metoda.', 405);
            $w = wejscie();
            odpowiedz(klucze_start($u, (int) ($w['obiekt_id'] ?? 0), (string) ($w['rodzaj'] ?? 'tie'), (string) ($w['zaloga'] ?? 'automat')));

        case 'mapa_klucz_rozkaz':
            if (!$post) blad('Zła metoda.', 405);
            $w = wejscie();
            odpowiedz(klucze_rozkaz_wydaj($u, (array) ($w['klucze'] ?? []), (string) ($w['rozkaz'] ?? ''), (int) ($w['cel_obiekt_id'] ?? 0) ?: null));
    }
}

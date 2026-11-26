<?php
declare(strict_types=1);

// Bitwy i symulacje bitew (migracje 068–070). Mechanika: narzedzia/mostek/MECHANIKA.md, wdrożenie: WDROZENIE.md.
//
// Rdzeń przeniesiony 1:1 z silnika wzorcowego (narzedzia/mostek/silnik.py): losowość z ziarna (crc32 — ta sama w Pythonie
// i PHP), zegar bitwy z pauzą, czas wykonania rozkazów, stopnie natywne, filtr energii, żyły jako mapa bitowa.
// Pętla tików: api/bitwa_silnik.php. Zgodność z wzorcem: api/?a=bitwa_test (MG) na wektorach z api/bitwa_wektory.php.
//
// Symulacja: scenariusz → mapa_bitwy (rodzaj 'symulacja', planeta NULL — nie ma jej na radarze) + tabele bitwy_*.
// Silnik dolicza tiki leniwie, przy każdym odczycie, pod blokadą wiersza bitwy (SELECT … FOR UPDATE), najwyżej 600
// tików na zapytanie. Rozkaz liczy się od chwili odebrania przez serwer (komputer centralny okrętu); uid nadaje konsola,
// więc ponowiona wysyłka nie tworzy drugiego rozkazu. Po zakończeniu: XP pilotów z mapa_piloci (0,5 za akcję × ranga)
// czeka na zatwierdzenie MG; stan świata (kadłuby, maszyny, piloci) się nie zmienia.
//
// Akcje: bitwa_dostep, bitwa_scenariusze, bitwa_symulacje, bitwa_stan, bitwa_utworz, bitwa_start, bitwa_pauza,
// bitwa_rozkaz, bitwa_zakoncz, bitwa_xp_zatwierdz (MG), bitwa_test (MG), bitwa_stanowisko (MG i kapitan);
// mostek: mostek_stan, mostek_gracze, mostek_stanowisko (MG i kapitan).
// Mostek bez symulacji: te same pulpity na okręcie w spoczynku (bez wroga i zegara, rozkazy bez skutku) — kapitan
// podchodzi do każdego pulpitu, gracz ze stałym stanowiskiem (okrety_stanowiska, migracja 077) do swoich.
// Stanowiska (bitwy_stanowiska): MG przypisuje dowolnego gracza — jego postać zostaje teleportowana na mostek okrętu;
// kapitan (dowódca floty okrętu) przypisuje graczy, którzy są na pokładzie, i sam widzi wszystkie pulpity. Gracz
// z przypisaniem widzi w symulacji tylko swoje pulpity. Upoważnieni: MG i konta, które mogą dowodzić
// okrętem z profilem klasy (mapa_obiekty.profil_klasy) — ten sam warunek co rozkazy dla kluczy (klucze_moze).

require_once __DIR__ . '/bitwa_wektory.php';
require_once __DIR__ . '/bitwa_silnik.php';

const BITWA_RANGA_PRZYGOTOWANIE = ['kadet' => 1, 'standard' => 0, 'as' => -1];

function bitwa_dostepna(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT kod FROM rozkazy_profile LIMIT 0'); polaczenie_bazy()->query('SELECT kod FROM bitwy_scenariusze LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// [0, 1) z crc32 tekstu „ziarno:tik:klucz…” — identycznie jak silnik.py los().
function bitwa_los(int $ziarno, int $tik, string|int ...$klucze): float
{
    return crc32(implode(':', array_merge([(string) $ziarno, (string) $tik], array_map('strval', $klucze)))) / 4294967296;
}

// Sekundy bitwy od startu, bez pauz MG; trwająca pauza zatrzymuje zegar.
function bitwa_czas(float $teraz, ?float $start, float $pauzy = 0.0, ?float $pauzaOd = null): float
{
    if ($start === null) return 0.0;
    $t = $teraz - $start - $pauzy;
    if ($pauzaOd !== null) $t -= $teraz - $pauzaOd;
    return max(0.0, $t);
}

function bitwa_rozkazy_profile(): array
{
    static $p = null;
    if ($p === null) {
        $p = [];
        foreach (polaczenie_bazy()->query('SELECT * FROM rozkazy_profile')->fetchAll(PDO::FETCH_ASSOC) as $w) $p[$w['kod']] = $w;
    }
    return $p;
}

// Przygotowanie rozkazu w tikach: profil ± ranga (kadet +1 s, as −1 s, min. 1 s), jeśli ranga dotyczy rozkazu.
function bitwa_przygotowanie(string $kod, string $ranga): int
{
    $r = bitwa_rozkazy_profile()[$kod] ?? null;
    if (!$r) throw new InvalidArgumentException("Nieznany rozkaz: $kod");
    $c = (float) $r['przygotowanie_s'];
    if ((int) $r['ranga_skraca']) $c += BITWA_RANGA_PRZYGOTOWANIE[$ranga] ?? 0;
    return max(1, (int) ceil($c));
}

function bitwa_stopnie(int $n): array
{
    $s = [];
    for ($d = 1; $d <= $n; $d++) if ($n % $d === 0) $s[] = $d;
    return $s;
}

// Najbliższy stopień natywny ≥ Hz lasera; null — laser powyżej N, osłona zawsze przebita.
function bitwa_stopien_w_gore(int $n, float $hz): ?int
{
    foreach (bitwa_stopnie($n) as $s) if ($s >= $hz) return $s;
    return null;
}

// [do kadłuba, do generatora] dla energii trafienia (MECHANIKA 3.2).
function bitwa_filtr(float $e, float $hzO, float $hzL): array
{
    $r = $hzO / $hzL;
    return [$e * max(0.0, 1 - $r), $e * min(1.0, $r)];
}

// Żyły: martwe — mapa bitowa (bit i = żyła i+1), temperatury — 1 bajt na żyłę (kolumny VARBINARY w bitwy_zyly).
function bitwa_zyly_nowe(int $n): array
{
    return ['n' => $n, 'martwe' => str_repeat("\0", intdiv($n + 7, 8)), 'temperatury' => str_repeat("\0", $n)];
}

function bitwa_zyla_martwa(array $z, int $i): bool
{
    return (bool) ((ord($z['martwe'][$i >> 3]) >> ($i & 7)) & 1);
}

function bitwa_zyla_zabij(array &$z, int $i): void
{
    $z['martwe'][$i >> 3] = chr(ord($z['martwe'][$i >> 3]) | (1 << ($i & 7)));
}

// Hz skuteczne: świecą żyły k·(N/f), k = 1…f; martwe nie świecą (MECHANIKA 3.1).
function bitwa_hz_skuteczne(array $z, int $f): int
{
    $krok = intdiv($z['n'], $f);
    $ile = 0;
    for ($k = 1; $k <= $f; $k++) if (!bitwa_zyla_martwa($z, $k * $krok - 1)) $ile++;
    return $ile;
}


// ------------------------------------------------------------------------------------------------------- test

// Porównanie z wektorami silnika wzorcowego. Zwraca listę wyników [nazwa, oczekiwane, otrzymane, ok].
function bitwa_test(): array
{
    $w = [];
    $rowne = static fn($a, $b): bool => is_float($b) ? abs($a - $b) <= 1e-12 * max(1.0, abs($b)) : $a === $b;
    foreach (BITWA_WEKTORY['los'] as [$z, $t, $k, $ocz]) {
        $o = bitwa_los($z, $t, $k);
        $w[] = ["los($z, $t, $k)", $ocz, $o, $rowne($o, $ocz)];
    }
    foreach (BITWA_WEKTORY['przygotowanie'] as [$kod, $r, $ocz]) {
        $o = bitwa_przygotowanie($kod, $r);
        $w[] = ["przygotowanie($kod, $r)", $ocz, $o, $o === $ocz];
    }
    foreach (BITWA_WEKTORY['hz_skuteczne'] as [$n, $f, $martwe, $ocz]) {
        $z = bitwa_zyly_nowe($n);
        foreach ($martwe as $i) bitwa_zyla_zabij($z, $i);
        $o = bitwa_hz_skuteczne($z, $f);
        $w[] = ["hz_skuteczne($n, $f)", $ocz, $o, $o === $ocz];
    }
    foreach (BITWA_WEKTORY['filtr'] as [$e, $hzO, $hzL, $ocz]) {
        $o = bitwa_filtr((float) $e, (float) $hzO, (float) $hzL)[0];
        $w[] = ["filtr($hzO vs $hzL)", $ocz, $o, $rowne($o, (float) $ocz)];
    }
    $w[] = ['stopień ISD wobec 2187', 3600, bitwa_stopien_w_gore(3600, 2187), bitwa_stopien_w_gore(3600, 2187) === 3600];
    $w[] = ['stopnie natywne MC80', 8, count(bitwa_stopnie(2187)), count(bitwa_stopnie(2187)) === 8];
    $w[] = ['zegar z trwającą pauzą', 3.0, bitwa_czas(110.0, 100.0, 2.0, 105.0), bitwa_czas(110.0, 100.0, 2.0, 105.0) === 3.0];
    // Całe przebiegi scenariusza: ta sama pętla tików co w Pythonie (pozycje z tolerancją 1e-6 — różnice bibliotek sin/cos).
    foreach (BITWA_WEKTORY['przebiegi'] ?? [] as $pb) {
        $nazwa = "przebieg {$pb['kod']}/{$pb['ziarno']}" . ($pb['auto'] ? '' : ' (osłony ręczne)') . (!empty($pb['rozkazy']) ? ' + nastawy' : '');
        $S = bitwa_stan_ze_scenariusza(bitwa_scenariusz_dane($pb['kod']), (int) $pb['ziarno']);
        if (!$pb['auto']) foreach ($S['jednostki'] as $ji => $j) foreach (array_keys($j['generatory']) as $gi) $S['jednostki'][$ji]['generatory'][$gi]['auto'] = false;
        for ($i = 0; $i < $pb['tiki']; $i++) {
            foreach ($pb['rozkazy'] ?? [] as [$tk, $nrJ, $kanal, $kodR, $wart]) {
                if ($tk === $S['tik']) bitwa_przyjmij($S, "$nrJ:$kanal", $kodR, (float) $tk, 'standard', null, ['wartosc' => $wart]);
            }
            bitwa_krok($S);
        }
        $roznica = null;
        foreach ($pb['klucze'] as $i => [$st, $nr, $stan, $ranga, $x, $y, $akcje]) {
            $k = $S['klucze'][$i] ?? null;
            if (!$k || $k['strona'] !== $st || $k['nr'] !== $nr || $k['stan'] !== $stan || $k['ranga'] !== $ranga || $k['akcje'] !== $akcje
                || abs($k['x'] - $x) > 1e-6 * max(1.0, abs($x)) || abs($k['y'] - $y) > 1e-6 * max(1.0, abs($y))) {
                $roznica = "klucz $st $nr: oczekiwane $stan/$ranga/$akcje ($x, $y), jest " . ($k ? "{$k['stan']}/{$k['ranga']}/{$k['akcje']} ({$k['x']}, {$k['y']})" : 'brak');
                break;
            }
        }
        $w[] = ["$nazwa: klucze", count($pb['klucze']), $roznica ?? count($S['klucze']), $roznica === null && count($S['klucze']) === count($pb['klucze'])];
        $gen = [];
        foreach ($S['jednostki'] as $j) foreach ($j['generatory'] as $g) $gen[] = [$g['hz_osiagane'], bitwa_martwe($g['zyly']), $g['obciazenie'], $g['rykoszet']];
        $ok = count($gen) === count($pb['generatory']);
        foreach ($pb['generatory'] as $i => $g) {
            $ok = $ok && $gen[$i][0] === $g[0] && $gen[$i][1] === $g[1] && abs($gen[$i][2] - $g[2]) <= 1e-9 * max(1.0, abs($g[2])) && abs($gen[$i][3] - $g[3]) <= 1e-9 * max(1.0, abs($g[3]));
        }
        $w[] = ["$nazwa: osłony", json_encode($pb['generatory']), json_encode($gen), $ok];
        $sek = [];
        foreach ($S['jednostki'] as $j) foreach ($j['sekcje'] as $v) $sek[] = $v;
        $ok = count($sek) === count($pb['sekcje']);
        foreach ($pb['sekcje'] as $i => $v) $ok = $ok && abs($sek[$i] - $v) <= 1e-9;
        $w[] = ["$nazwa: kadłub", json_encode($pb['sekcje']), json_encode($sek), $ok];
        $zd = [];
        foreach ($S['zdarzenia'] as $z) $zd[$z['rodzaj']] = ($zd[$z['rodzaj']] ?? 0) + 1;
        ksort($zd, SORT_STRING);
        $w[] = ["$nazwa: zdarzenia", json_encode($pb['zdarzenia']), json_encode($zd), $zd == $pb['zdarzenia']];
        if (isset($pb['zasilanie'])) {
            $zs = [];
            foreach ($S['jednostki'] as $j) $zs[] = [$j['cieplo'], $j['dlawienie'], bitwa_zas($j, 'tl_l'), bitwa_zas($j, 'oslona_p'), bitwa_zas($j, 'v')];
            $ok = count($zs) === count($pb['zasilanie']);
            foreach ($pb['zasilanie'] as $i => $z) {
                $ok = $ok && $zs[$i][1] === $z[1];
                foreach ([0, 2, 3, 4] as $k) $ok = $ok && abs($zs[$i][$k] - $z[$k]) <= 1e-9 * max(1.0, abs($z[$k]));
            }
            $w[] = ["$nazwa: zasilanie", json_encode($pb['zasilanie']), json_encode($zs), $ok];
        }
        if (isset($pb['ruch'])) {
            $ru = [];
            foreach ($S['jednostki'] as $j) $ru[] = [$j['x'], $j['y'], $j['kurs'], $j['v']];
            $ok = count($ru) === count($pb['ruch']);
            foreach ($pb['ruch'] as $i => $r) foreach ([0, 1, 2, 3] as $k) $ok = $ok && abs($ru[$i][$k] - $r[$k]) <= 1e-6 * max(1.0, abs($r[$k]));
            $w[] = ["$nazwa: ruch okrętów", json_encode($pb['ruch']), json_encode($ru), $ok];
        }
    }
    return $w;
}

// ------------------------------------------------------------------------------------------------------- dostęp

// Okręty z profilem klasy, którymi konto może dowodzić (MG — wszystkie).
function bitwa_okrety_konta(array $u): array
{
    $okrety = polaczenie_bazy()->query("SELECT id, nazwa, profil_klasy FROM mapa_obiekty WHERE profil_klasy IS NOT NULL ORDER BY nazwa")->fetchAll(PDO::FETCH_ASSOC);
    if (($u['rola'] ?? '') === 'mg') return $okrety;
    if (!function_exists('klucze_moze')) return [];
    return array_values(array_filter($okrety, static fn($o) => klucze_moze($u, (int) $o['id'])));
}

function bitwa_upowazniony(array $u): bool
{
    if (($u['rola'] ?? '') === 'mg') return true;
    if (!bitwa_dostepna()) return false;
    try {
        if (bitwa_okrety_konta($u)) return true;
        if (mostek_moje_stale($u)) return true;
        if (!bitwa_silnik_gotowy()) return false;
        $q = polaczenie_bazy()->prepare("SELECT 1 FROM bitwy_stanowiska s JOIN bitwy_jednostki j ON j.id = s.bitwa_jednostka_id
            JOIN mapa_bitwy b ON b.id = j.bitwa_id WHERE LOWER(s.login) = LOWER(?) AND b.status IN ('przygotowanie','aktywna') LIMIT 1");
        $q->execute([(string) ($u['login'] ?? '')]);
        return (bool) $q->fetchColumn();
    } catch (Throwable $e) { return false; }
}

// Okręt graczy w symulacji (z mapy) — do niego należą stanowiska.
function bitwa_okret_graczy(int $bitwaId): ?array
{
    $q = polaczenie_bazy()->prepare("SELECT j.id, j.obiekt_id, j.nazwa, j.profil_kod FROM bitwy_jednostki j
        WHERE j.bitwa_id = ? AND j.obiekt_id IS NOT NULL ORDER BY j.id LIMIT 1");
    $q->execute([$bitwaId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Kapitan okrętu graczy: dowódca jego floty (wskazniki_kapitan).
function bitwa_kapitan(array $u, ?array $okret): bool
{
    return $okret && function_exists('wskazniki_kapitan') && wskazniki_kapitan('okret:' . (int) $okret['obiekt_id'], $u);
}

// Okręt, na którego pokładzie jest postać konta (0 — nie na okręcie).
function bitwa_na_pokladzie(array $u): int
{
    if (!function_exists('poklad_okret_postaci')) return 0;
    try { return poklad_okret_postaci($u); } catch (Throwable $e) { return 0; }
}

// Teleport postaci przez MG na mostek okrętu (ten sam zapis pozycji co przestawienie postaci w panelu MG).
function bitwa_teleport_na_okret(array $gracz, int $okretId): void
{
    $uid = (string) $gracz['id'];
    $lok = ruch_lokacje();
    $klucz = 'okret:' . $okretId;
    if (!isset($lok[$klucz])) blad('Okręt nie ma lokacji na mapie — nie można przenieść postaci.');
    $poz = ['planeta' => $lok[$klucz]['planeta'] ?? '', 'wspolrzedne' => '', 'miejsce' => $lok[$klucz]['nazwa'] ?? '', 'budynek' => '',
        'lokacja' => $klucz, 'zmieniono' => teraz_ms()];
    zmien('karty', function (&$k) use ($uid, $poz) {
        $k[$uid]['pozycja'] = $poz;
        unset($k[$uid]['podroz']);
    });
    pozycja_sql_zapisz($uid, $poz);
    if (function_exists('wnetrze_plan_okretu') && ($plan = wnetrze_plan_okretu($okretId))) {
        $mostek = wnetrze_szukaj(wnetrze_wezly($plan), 'Mostek główny');
        if ($mostek) wnetrze_zapisz($uid, $okretId, $mostek);
    }
}

// Zasięg sensorów (migracja 073, opcjonalna): poziomy i koszt w porcjach — k · (zasięg / 1000 km)^1,618.
const BITWA_ZASIEGI_SENSOROW = [1000, 3000, 10000, 30000];
function bitwa_koszt_sensorow(int $km): float
{
    return round(0.245 * ($km / 1000) ** 1.618, 2);
}

// Migracja 076 (ster okrętu gracza, cel wskazany baterii).
function bitwa_ster_dostepny(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT kurs_cel_deg FROM bitwy_jednostki LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// Migracja 078 (nastawy konsol Osłony i Reaktory, ciepło rdzenia).
function bitwa_nastawy_dostepne(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT kod FROM bitwy_nastawy LIMIT 0'); polaczenie_bazy()->query('SELECT cieplo_reaktora, dlawienie_rdzenia FROM bitwy_jednostki LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

function bitwa_sensory_dostepne(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT zasieg_sensorow_km FROM bitwy_jednostki LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// Migracje 070 i 072 (stan silnika, taśmociąg) — bez niej scenariusze i test działają, symulacje nie.
function bitwa_silnik_gotowy(): bool
{
    static $jest = null;
    if ($jest === null) {
        try {
            polaczenie_bazy()->query('SELECT ranga, ladunek, ai FROM bitwy_klucze LIMIT 0');
            polaczenie_bazy()->query('SELECT kanal FROM bitwy_rozkazy LIMIT 0');
            polaczenie_bazy()->query('SELECT tasma_tik FROM bitwy_jednostki LIMIT 0');
            polaczenie_bazy()->query('SELECT oslona_pkt, kurs_zadany FROM bitwy_klucze LIMIT 0');
            polaczenie_bazy()->query('SELECT zwrot_deg_s FROM typy_maszyn LIMIT 0');
            polaczenie_bazy()->query('SELECT nr FROM profile_klas_uzbrojenie LIMIT 0');
            $jest = true;
        }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

function bitwa_mg(array $u): bool
{
    return ($u['rola'] ?? '') === 'mg';
}

// Kto widzi symulację: MG, prowadzący, dowodzący okrętem biorącym w niej udział.
function bitwa_moze(array $u, array $b): bool
{
    if (bitwa_mg($u) || mb_strtolower((string) $b['prowadzacy_login']) === mb_strtolower((string) ($u['login'] ?? ''))) return true;
    if (bitwa_silnik_gotowy()) {
        $q = polaczenie_bazy()->prepare('SELECT 1 FROM bitwy_stanowiska s JOIN bitwy_jednostki j ON j.id = s.bitwa_jednostka_id
            WHERE j.bitwa_id = ? AND LOWER(s.login) = LOWER(?) LIMIT 1');
        $q->execute([(int) $b['id'], (string) ($u['login'] ?? '')]);
        if ($q->fetchColumn()) return true;
    }
    $ids = array_map(static fn($o) => (int) $o['id'], bitwa_okrety_konta($u));
    if (!$ids) return false;
    $q = polaczenie_bazy()->prepare('SELECT obiekt_id FROM bitwy_jednostki WHERE bitwa_id=? AND obiekt_id IS NOT NULL');
    $q->execute([(int) $b['id']]);
    return (bool) array_intersect($ids, array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)));
}

// Stanowisko konta w trwającej symulacji (przydział MG albo kapitana): po zalogowaniu gracz widzi tylko ten pulpit.
// MG i prowadzący nie są stanowiskami — dla nich null.
function bitwa_moje_stanowisko(array $u): ?array
{
    if (bitwa_mg($u) || !bitwa_dostepna()) return null;
    try {
        if (bitwa_okrety_konta($u)) return null;   // dowodzący okrętem (kapitan) widzi wszystko, nie jest stanowiskiem
        $q = polaczenie_bazy()->prepare("SELECT b.id, s.pulpit FROM bitwy_stanowiska s JOIN bitwy_jednostki j ON j.id = s.bitwa_jednostka_id
            JOIN mapa_bitwy b ON b.id = j.bitwa_id WHERE LOWER(s.login) = LOWER(?) AND b.status IN ('przygotowanie','aktywna')
            AND LOWER(COALESCE(b.prowadzacy_login, '')) <> LOWER(?) ORDER BY b.id DESC, s.pulpit");
        $q->execute([(string) ($u['login'] ?? ''), (string) ($u['login'] ?? '')]);
        $w = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return null; }
    if (!$w) return null;
    $id = (int) $w[0]['id'];
    return ['bitwa_id' => $id, 'pulpity' => array_values(array_map(static fn($r) => $r['pulpit'], array_filter($w, static fn($r) => (int) $r['id'] === $id)))];
}

// ------------------------------------------------------------------------------------------------------- mostek

// Migracja 077 (stałe stanowiska).
function bitwa_stale_dostepne(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT pulpit FROM okrety_stanowiska LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// Myśliwce w hangarze okrętu na mostku bez symulacji (profile_klas.py: typ_mysliwcow; w SQL nie ma tej kolumny).
const MOSTEK_TYP_MYSLIWCOW = ['ISD_I' => 'TIE_LN', 'MC80' => 'X_WING'];

function mostek_okret(int $obiektId): array
{
    $db = polaczenie_bazy();
    try {
        $q = $db->prepare('SELECT id, nazwa, profil_klasy, frakcja FROM mapa_obiekty WHERE id=? AND profil_klasy IS NOT NULL');
        $q->execute([$obiektId]);
    } catch (Throwable $e) {
        $q = $db->prepare('SELECT id, nazwa, profil_klasy, NULL AS frakcja FROM mapa_obiekty WHERE id=? AND profil_klasy IS NOT NULL');
        $q->execute([$obiektId]);
    }
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o) blad('Nie ma takiego okrętu z profilem klasy.', 404);
    return $o;
}

// Stałe przydziały okrętu: [['pulpit' => …, 'login' => …], …].
function mostek_stale_stanowiska(int $obiektId): array
{
    if (!bitwa_stale_dostepne()) return [];
    $q = polaczenie_bazy()->prepare('SELECT pulpit, login FROM okrety_stanowiska WHERE obiekt_id=? ORDER BY pulpit');
    $q->execute([$obiektId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

// Stałe stanowiska konta: [['obiekt_id', 'nazwa', 'profil', 'pulpity' => [...]], …].
function mostek_moje_stale(array $u): array
{
    if (!bitwa_stale_dostepne()) return [];
    try {
        $q = polaczenie_bazy()->prepare('SELECT s.obiekt_id, o.nazwa, o.profil_klasy, s.pulpit FROM okrety_stanowiska s
            JOIN mapa_obiekty o ON o.id = s.obiekt_id WHERE LOWER(s.login) = LOWER(?) ORDER BY o.nazwa, s.pulpit');
        $q->execute([(string) ($u['login'] ?? '')]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['obiekt_id'];
            $out[$id] ??= ['obiekt_id' => $id, 'nazwa' => $r['nazwa'], 'profil' => $r['profil_klasy'], 'pulpity' => []];
            $out[$id]['pulpity'][] = $r['pulpit'];
        }
        return array_values($out);
    } catch (Throwable $e) { return []; }
}

// Kto i jak widzi mostek okrętu: pełny (MG, dowodzący okrętem, kapitan floty) albo tylko swoje stałe pulpity.
function mostek_dostep(array $u, array $okret): array
{
    $id = (int) $okret['id'];
    $mg = bitwa_mg($u);
    $kapitan = bitwa_kapitan($u, ['obiekt_id' => $id]);
    $dowodzi = in_array($id, array_map(static fn($o) => (int) $o['id'], bitwa_okrety_konta($u)), true);
    $login = mb_strtolower((string) ($u['login'] ?? ''));
    $przypisane = mostek_stale_stanowiska($id);
    $moje = array_values(array_map(static fn($s) => $s['pulpit'], array_filter($przypisane, static fn($s) => mb_strtolower((string) $s['login']) === $login)));
    return ['mg' => $mg, 'pelny' => $mg || $kapitan || $dowodzi, 'przydziela' => $mg || $kapitan || $dowodzi, 'moje' => $moje, 'przypisane' => $przypisane];
}

// Okręt w spoczynku jako stan symulacji (bez wroga, zegara i zapisu): ten sam widok co bitwa_stan, żeby konsole
// wyglądały tak samo. Hangar pełny (profil: mysliwce / 4 klucze), osłony na domyślnych Hz, kadłub 100%.
function mostek_widok(array $u, int $obiektId): array
{
    $o = mostek_okret($obiektId);
    $d = mostek_dostep($u, $o);
    if (!$d['pelny'] && !$d['moje']) blad('Nie masz stanowiska na mostku tego okrętu.', 403);
    $sl = bitwa_slowniki();
    $profil = (string) $o['profil_klasy'];
    if (!isset($sl['profile'][$profil])) blad("Profil klasy $profil nie ma danych mechaniki (migracja 069).");
    $strona = in_array($o['frakcja'], ['Imperium', 'Rebelia'], true) ? $o['frakcja'] : ($profil === 'MC80' ? 'Rebelia' : 'Imperium');
    $typ = MOSTEK_TYP_MYSLIWCOW[$profil] ?? null;
    $klucze = intdiv($sl['profile'][$profil]['mysliwce'], 4);
    $sc = ['strony' => [['strona' => $strona, 'dowodzenie' => 'gracze', 'asy_proc' => 0, 'kadeci_proc' => 0]],
        'jednostki' => [['nr' => 1, 'strona' => $strona, 'profil_kod' => $profil, 'nazwa' => $o['nazwa'], 'x_km' => 0, 'y_km' => 0, 'kurs_deg' => 0,
            'predkosc_km_s' => 0, 'wejscie_s' => 0, 'wejscie_rozrzut_s' => 0, 'obiekt_id' => (int) $o['id']]],
        'klucze' => $typ && isset($sl['maszyny'][$typ]) && $klucze > 0
            ? [['strona' => $strona, 'typ_maszyny' => $typ, 'liczba_kluczy' => $klucze, 'jednostka_nr' => 1,
                'start_s' => 0, 'start_rozrzut_s' => 0, 'x_km' => null, 'y_km' => null, 'rozkaz' => 'ochrona', 'fala' => 1]] : []];
    $S = bitwa_stan_ze_scenariusza($sc, 1);
    // identyfikatory jak po zapisie (konsole wskazują nimi generatory i klucze); klucze czekają w hangarze bez startu
    $S['jednostki'][0]['id'] = 0;
    foreach ($S['jednostki'][0]['generatory'] as $gi => $g) $S['jednostki'][0]['generatory'][$gi]['id'] = $gi + 1;
    foreach ($S['klucze'] as $ki => $k) { $S['klucze'][$ki]['id'] = $ki + 1; $S['klucze'][$ki]['start'] = null; }
    $b = ['id' => 0, 'nazwa' => 'Mostek · ' . $o['nazwa'], 'status' => 'mostek', 'pauza_od_utc' => null, 'zegar_start_utc' => null, 'okno_s' => 0,
        'prowadzacy_login' => null];
    $postacie = [];
    foreach (uzytkownicy() as $x) $postacie[mb_strtolower((string) $x['login'])] = $x;
    return bitwa_widok($b, $S) + ['mostek' => true, 'obiekt_id' => (int) $o['id'], 'moge_prowadzic' => false, 'mg' => $d['mg'],
        'stanowiska' => ['okret' => ['nazwa' => $o['nazwa'], 'profil' => $profil], 'pelny' => $d['pelny'], 'mg' => $d['mg'],
            'kapitan' => $d['przydziela'] && !$d['mg'], 'moje' => $d['moje'], 'stale' => true, 'migracja' => bitwa_stale_dostepne(),
            'przypisane' => array_map(static fn($s) => ['pulpit' => $s['pulpit'], 'login' => $s['login'],
                'postac' => $postacie[mb_strtolower((string) $s['login'])]['postac'] ?? $s['login']], $d['przypisane'])]];
}

// Stały przydział (login pusty — zwolnienie); przechodzi też na trwające symulacje okrętu.
function mostek_przydziel(array $u, int $obiektId, string $pulpit, string $login): array
{
    $db = polaczenie_bazy();
    if (!bitwa_stale_dostepne()) blad('Stałe stanowiska wymagają migracji 077.');
    $o = mostek_okret($obiektId);
    if (!mostek_dostep($u, $o)['przydziela']) blad('Stanowiska przydziela MG albo kapitan okrętu.', 403);
    $pulpit = mb_substr(trim($pulpit), 0, 40);
    if ($pulpit === '') blad('Wybierz pulpit.');
    $trwajace = [];
    if (bitwa_silnik_gotowy()) {
        $q = $db->prepare("SELECT j.id FROM bitwy_jednostki j JOIN mapa_bitwy b ON b.id = j.bitwa_id
            WHERE j.obiekt_id = ? AND b.status IN ('przygotowanie','aktywna')");
        $q->execute([$obiektId]);
        $trwajace = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }
    if (trim($login) === '') {
        $db->prepare('DELETE FROM okrety_stanowiska WHERE obiekt_id=? AND pulpit=?')->execute([$obiektId, $pulpit]);
        foreach ($trwajace as $jid) $db->prepare('DELETE FROM bitwy_stanowiska WHERE bitwa_jednostka_id=? AND pulpit=?')->execute([$jid, $pulpit]);
        return ['ok' => true];
    }
    $gracz = null;
    foreach (uzytkownicy() as $x) if (mb_strtolower((string) $x['login']) === mb_strtolower(trim($login))) $gracz = $x;
    if (!$gracz || ($gracz['rola'] ?? '') === 'mg') blad('Nie ma takiego gracza.');
    $teleport = false;
    if (bitwa_na_pokladzie($gracz) !== $obiektId) {
        if (!bitwa_mg($u)) blad('Kapitan przydziela stanowiska tylko załodze na pokładzie.');
        bitwa_teleport_na_okret($gracz, $obiektId);
        $teleport = true;
    }
    $db->prepare('INSERT INTO okrety_stanowiska (obiekt_id, pulpit, login, przydzielil) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE login = VALUES(login), przydzielil = VALUES(przydzielil)')
        ->execute([$obiektId, $pulpit, (string) $gracz['login'], (string) ($u['login'] ?? '')]);
    foreach ($trwajace as $jid) {
        $db->prepare('INSERT INTO bitwy_stanowiska (bitwa_jednostka_id, pulpit, login) VALUES (?,?,?) ON DUPLICATE KEY UPDATE login = VALUES(login)')
            ->execute([$jid, $pulpit, (string) $gracz['login']]);
    }
    return ['ok' => true, 'teleport' => $teleport, 'postac' => $gracz['postac'] ?? $gracz['login']];
}

// Prowadzi (start, pauza, koniec): MG albo prowadzący.
function bitwa_prowadzi(array $u, array $b): bool
{
    return bitwa_mg($u) || mb_strtolower((string) $b['prowadzacy_login']) === mb_strtolower((string) ($u['login'] ?? ''));
}

// ------------------------------------------------------------------------------------------------------- czas

function bitwa_utc(float $t): string
{
    $s = (int) floor($t);
    return gmdate('Y-m-d H:i:s', $s) . sprintf('.%03d', min(999, (int) floor(($t - $s) * 1000)));
}

function bitwa_z_utc(?string $s): ?float
{
    if ($s === null || $s === '') return null;
    $d = DateTime::createFromFormat('Y-m-d H:i:s.u', str_contains($s, '.') ? $s : $s . '.000', new DateTimeZone('UTC'));
    return $d ? (float) $d->format('U.u') : null;
}

function bitwa_czas_teraz(array $b): float
{
    return bitwa_czas(microtime(true), bitwa_z_utc($b['zegar_start_utc']), (float) $b['zegar_pauzy_s'], bitwa_z_utc($b['pauza_od_utc']));
}

// ------------------------------------------------------------------------------------------------------- scenariusz

function bitwa_scenariusz_dane(string $kod): array
{
    $db = polaczenie_bazy();
    $q = $db->prepare('SELECT * FROM bitwy_scenariusze WHERE kod=?');
    $q->execute([$kod]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new InvalidArgumentException("Nieznany scenariusz: $kod");
    foreach (['strony' => 'bitwy_scenariusze_strony', 'jednostki' => 'bitwy_scenariusze_jednostki', 'klucze' => 'bitwy_scenariusze_klucze'] as $k => $tab) {
        $q = $db->prepare("SELECT * FROM $tab WHERE scenariusz_kod=?" . ($k === 'jednostki' ? ' ORDER BY nr' : ''));
        $q->execute([$kod]);
        $s[$k] = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    return $s;
}

// ------------------------------------------------------------------------------------------------------- zapis i odczyt

function bitwa_utworz(array $u, string $scenariusz, ?int $obiektId): int
{
    $db = polaczenie_bazy();
    $sc = bitwa_scenariusz_dane($scenariusz);
    $okrety = bitwa_okrety_konta($u);
    foreach ($sc['jednostki'] as &$j) {
        $j['obiekt_id'] = null;
        if (!(int) $j['z_mapy']) continue;
        $pasujace = array_values(array_filter($okrety, static fn($o) => $o['profil_klasy'] === $j['profil_kod']));
        $wybrany = null;
        foreach ($pasujace as $o) if ($obiektId !== null && (int) $o['id'] === $obiektId) $wybrany = $o;
        $wybrany ??= $pasujace[0] ?? null;
        if (!$wybrany) blad("Do tego scenariusza potrzebny jest okręt klasy {$j['profil_kod']}, którym dowodzisz.");
        $j['obiekt_id'] = (int) $wybrany['id'];
        $j['nazwa'] = $wybrany['nazwa'];
    }
    unset($j);
    $ziarno = random_int(1, 2147483646);
    $S = bitwa_stan_ze_scenariusza($sc, $ziarno);
    $sl = bitwa_slowniki();

    // Piloci z pokładu (mapa_piloci) w kluczach stron prowadzonych przez graczy: 4 na klucz, ranga klucza = prowadzący.
    $strony = [];
    foreach ($sc['strony'] as $s) $strony[$s['strona']] = $s;
    $pule = [];
    $piloci = [];
    foreach ($S['klucze'] as $ki => $k) {
        $piloci[$ki] = [];
        $mac = null;
        foreach ($S['jednostki'] as $jj) if ($jj['nr'] === $k['macierzysta']) $mac = $jj;
        if ($k['ai'] || !$mac || !$mac['obiekt_id'] || !function_exists('klucze_piloci_dostepni') || !klucze_piloci_dostepni()) continue;
        $okret = (int) $mac['obiekt_id'];
        if (!isset($pule[$okret])) {
            if (function_exists('klucze_piloci_zapewnij')) klucze_piloci_zapewnij($okret);
            $q = $db->prepare('SELECT id, imie, ranga FROM mapa_piloci WHERE okret_id=? ORDER BY id');
            $q->execute([$okret]);
            $pule[$okret] = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        $piloci[$ki] = array_splice($pule[$okret], 0, 4);
        if ($piloci[$ki]) $S['klucze'][$ki]['ranga'] = $piloci[$ki][0]['ranga'];
    }

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO mapa_bitwy (kod, nazwa, rodzaj, scenariusz_kod, ziarno, status, planeta_id, prowadzacy_login, opis)
            VALUES (?, ?, 'symulacja', ?, ?, 'przygotowanie', NULL, ?, ?)")
            ->execute(['sym-' . bin2hex(random_bytes(6)), 'Symulacja: ' . $sc['nazwa'], $scenariusz, $ziarno, (string) ($u['login'] ?? ''), $sc['odprawa']]);
        $id = (int) $db->lastInsertId();
        $insJ = $db->prepare('INSERT INTO bitwy_jednostki (bitwa_id, obiekt_id, profil_kod, nazwa, strona, scenariusz_nr, stan, wejscie_tik, tasma_tik, x_km, y_km,
            vx_km_s, vy_km_s, v_km_s, zwrot_deg, kadlub_start) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,100)');
        $insS = $db->prepare('INSERT INTO bitwy_sekcje (bitwa_jednostka_id, sekcja, integralnosc) VALUES (?,?,?)');
        $insG = $db->prepare("INSERT INTO bitwy_systemy (bitwa_jednostka_id, kod, stan, hz_zadane, hz_osiagane, hz_min, hz_max, auto) VALUES (?,?,'gotowy',?,?,?,?,1)");
        $insZ = $db->prepare('INSERT INTO bitwy_zyly (system_id, liczba, martwe, temperatury) VALUES (?,?,?,?)');
        $idJednostki = [];
        foreach ($S['jednostki'] as $j) {
            $rad = bitwa_radiany($j['kurs']);
            $insJ->execute([$id, $j['obiekt_id'], $j['profil'], $j['nazwa'], $j['strona'], $j['nr'], $j['stan'], $j['wejscie'], $S['tasma'][$j['nr']] ?? null, $j['x'], $j['y'],
                $j['v'] * sin($rad), $j['v'] * cos($rad), $j['v'], $j['kurs']]);
            $jid = (int) $db->lastInsertId();
            $idJednostki[$j['nr']] = $jid;
            if ($j['obiekt_id'] && bitwa_stale_dostepne()) {
                $db->prepare('INSERT INTO bitwy_stanowiska (bitwa_jednostka_id, pulpit, login) SELECT ?, pulpit, login FROM okrety_stanowiska WHERE obiekt_id = ?')
                    ->execute([$jid, (int) $j['obiekt_id']]);
            }
            foreach ($j['sekcje'] as $sek => $v) $insS->execute([$jid, $sek, $v]);
            foreach ($j['generatory'] as $g) {
                $insG->execute([$jid, $g['kod'], $g['hz_zadane'], $g['hz_osiagane'], $g['hz_min'], $g['hz_max']]);
                $insZ->execute([(int) $db->lastInsertId(), $g['zyly']['n'], $g['zyly']['martwe'], $g['zyly']['temperatury']]);
            }
        }
        $insK = $db->prepare("INSERT INTO bitwy_klucze (bitwa_id, strona, macierzysta_id, numer, typ_maszyny, ranga, maszyny, stan, start_tik, hz_bazowe, moc,
            rozkaz, x_km, y_km, kurs_deg, ladunek, akcje, ai) VALUES (?,?,?,?,?,?,?,'hangar',?,?,?,?,?,?,?,0,0,?)");
        $insP = $db->prepare('INSERT INTO bitwy_piloci (bitwa_klucz_id, pilot_id, imie, ranga) VALUES (?,?,?,?)');
        foreach ($S['klucze'] as $ki => $k) {
            $insK->execute([$id, $k['strona'], $k['macierzysta'] !== null ? ($idJednostki[$k['macierzysta']] ?? null) : null, $k['nr'], $k['typ'], $k['ranga'],
                $k['maszyny'], $k['start'], $sl['maszyny'][$k['typ']]['hz'], $k['moc'], $k['rozkaz'], $k['x'], $k['y'], $k['kurs'], $k['ai'] ? 1 : 0]);
            $kid = (int) $db->lastInsertId();
            for ($i = 0; $i < $k['maszyny']; $i++) {
                $p = $piloci[$ki][$i] ?? null;
                $insP->execute([$kid, $p ? (int) $p['id'] : null, $p ? $p['imie'] : "pilot {$k['strona']} {$k['nr']}-" . ($i + 1), $p ? $p['ranga'] : $k['ranga']]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $id;
}

function bitwa_wczytaj_stan(array $b): array
{
    $db = polaczenie_bazy();
    $id = (int) $b['id'];
    $tik = (int) $b['tik_policzony'];
    $S = ['id' => $id, 'ziarno' => (int) $b['ziarno'], 'tik' => $tik, 'rozkazy' => [], 'po_kanale' => [], 'aktywne' => [], 'zdarzenia' => [], 'jednostki' => [], 'klucze' => [], 'tasma' => []];
    $nrJednostki = [];
    $q = $db->prepare('SELECT strona, dowodzenie FROM bitwy_scenariusze_strony WHERE scenariusz_kod=?');
    $q->execute([(string) $b['scenariusz_kod']]);
    $dowodzenie = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    $S['dowodcy'] = $dowodzenie;
    $q = $db->prepare('SELECT * FROM bitwy_jednostki WHERE bitwa_id=? ORDER BY id');
    $q->execute([$id]);
    $qg = $db->prepare("SELECT s.*, z.liczba, z.martwe, z.temperatury FROM bitwy_systemy s JOIN bitwy_zyly z ON z.system_id = s.id
        WHERE s.bitwa_jednostka_id=? AND s.kod LIKE 'oslona\\_%' ORDER BY s.id");
    $qs = $db->prepare('SELECT sekcja, integralnosc FROM bitwy_sekcje WHERE bitwa_jednostka_id=?');
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $jid = (int) $r['id'];
        $qg->execute([$jid]);
        $gen = [];
        foreach ($qg->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $gen[] = ['id' => (int) $g['id'], 'kod' => $g['kod'], 'hz_zadane' => (int) $g['hz_zadane'], 'hz_osiagane' => (int) $g['hz_osiagane'],
                'hz_min' => (int) $g['hz_min'], 'hz_max' => (int) $g['hz_max'], 'obciazenie' => (float) $g['obciazenie_j'], 'rykoszet' => (float) $g['rykoszet_j'],
                'auto' => (bool) (int) $g['auto'], 'zyly' => ['n' => (int) $g['liczba'], 'martwe' => (string) $g['martwe'], 'temperatury' => (string) $g['temperatury']],
                'zyly_org' => (string) $g['martwe']];
        }
        $qs->execute([$jid]);
        $sek = [];
        foreach ($qs->fetchAll(PDO::FETCH_ASSOC) as $s) $sek[$s['sekcja']] = (float) $s['integralnosc'];
        ksort($sek, SORT_STRING);
        $nrJednostki[$jid] = (int) $r['scenariusz_nr'];
        if ($r['tasma_tik'] !== null) $S['tasma'][(int) $r['scenariusz_nr']] = (int) $r['tasma_tik'];
        $S['jednostki'][] = ['id' => $jid, 'nr' => (int) $r['scenariusz_nr'], 'strona' => $r['strona'], 'profil' => $r['profil_kod'], 'nazwa' => $r['nazwa'],
            'x' => (float) $r['x_km'], 'y' => (float) $r['y_km'], 'kurs' => (float) $r['zwrot_deg'], 'v' => (float) $r['v_km_s'],
            'wejscie' => (int) $r['wejscie_tik'], 'generatory' => $gen, 'sekcje' => $sek, 'stan' => $r['stan'], 'obiekt_id' => $r['obiekt_id'],
            'zasieg_sensorow' => (int) ($r['zasieg_sensorow_km'] ?? 1000), 'ai' => ($dowodzenie[$r['strona']] ?? 'gracze') !== 'gracze',
            'kurs_cel' => isset($r['kurs_cel_deg']) ? (float) $r['kurs_cel_deg'] : null, 'v_cel' => isset($r['v_cel_km_s']) ? (float) $r['v_cel_km_s'] : null,
            'cel_ognia' => isset($r['cel_ognia_nr']) ? (int) $r['cel_ognia_nr'] : null,
            'nastawy' => [], 'cieplo' => (float) ($r['cieplo_reaktora'] ?? 0), 'dlawienie' => (bool) (int) ($r['dlawienie_rdzenia'] ?? 0)];
    }
    if (bitwa_nastawy_dostepne()) {
        $q = $db->prepare('SELECT n.bitwa_jednostka_id, n.kod, n.wartosc FROM bitwy_nastawy n JOIN bitwy_jednostki j ON j.id = n.bitwa_jednostka_id WHERE j.bitwa_id=?');
        $q->execute([$id]);
        $poId = [];
        foreach ($S['jednostki'] as $ji => $j) $poId[$j['id']] = $ji;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($poId[(int) $r['bitwa_jednostka_id']]) && isset(BITWA_NASTAWY[$r['kod']])) $S['jednostki'][$poId[(int) $r['bitwa_jednostka_id']]]['nastawy'][$r['kod']] = (float) $r['wartosc'];
        }
    }
    $indeksKlucza = [];
    $q = $db->prepare('SELECT * FROM bitwy_klucze WHERE bitwa_id=? ORDER BY id');
    $q->execute([$id]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $indeksKlucza[(int) $r['id']] = count($S['klucze']);
        $S['klucze'][] = ['id' => (int) $r['id'], 'strona' => $r['strona'], 'nr' => (int) $r['numer'], 'typ' => $r['typ_maszyny'], 'maszyny' => (int) $r['maszyny'],
            'ranga' => $r['ranga'], 'moc' => (float) $r['moc'], 'stan' => $r['stan'], 'start' => $r['start_tik'] !== null ? (int) $r['start_tik'] : null, 'x' => (float) $r['x_km'], 'y' => (float) $r['y_km'],
            'kurs' => (float) $r['kurs_deg'], 'rozkaz' => $r['rozkaz'], 'macierzysta' => $r['macierzysta_id'] !== null ? ($nrJednostki[(int) $r['macierzysta_id']] ?? null) : null,
            'ladunek' => (int) $r['ladunek'], 'akcje' => (int) $r['akcje'], 'ai' => (bool) (int) $r['ai'],
            'oslona_pkt' => (float) ($r['oslona_pkt'] ?? 0), 'kurs_zadany' => isset($r['kurs_zadany']) ? (float) $r['kurs_zadany'] : null];
    }
    // Rozkazy potrzebne silnikowi: w przygotowaniu i działające, z bieżącego cyklu (limit zmian) i w odnowieniu.
    $q = $db->prepare("SELECT * FROM bitwy_rozkazy WHERE bitwa_id=? AND (stan IN ('przygotowanie','dziala') OR tik >= ? OR odnowienie_do_tik > ?) ORDER BY id");
    $q->execute([$id, intdiv($tik, BITWA_CYKL) * BITWA_CYKL, $tik]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['rozkaz_kod'] === null) continue;
        $S['rozkazy'][] = ['id' => (int) $r['id'], 'kanal' => $r['kanal'], 'kod' => $r['rozkaz_kod'], 'tik' => (int) $r['tik'], 'czas_s' => (float) $r['czas_s'],
            'stan' => $r['stan'], 'ranga' => $r['ranga'], 'dziala_od' => (int) $r['dziala_od_tik'],
            'dziala_do' => $r['dziala_do_tik'] !== null ? (int) $r['dziala_do_tik'] : null, 'odnowienie_do' => $r['odnowienie_do_tik'] !== null ? (int) $r['odnowienie_do_tik'] : null,
            'ki' => $r['bitwa_klucz_id'] !== null ? ($indeksKlucza[(int) $r['bitwa_klucz_id']] ?? null) : null, 'zmieniony' => false,
            'wartosc' => $r['wartosc_po']];
        $i = array_key_last($S['rozkazy']);
        $S['po_kanale'][$r['kanal']][] = $i;
        if ($r['stan'] === 'przygotowanie' || $r['stan'] === 'dziala') $S['aktywne'][$i] = true;
    }
    return $S;
}

function bitwa_zapisz_stan(array $S): void
{
    $db = polaczenie_bazy();
    $id = $S['id'];
    $updJ = $db->prepare('UPDATE bitwy_jednostki SET x_km=?, y_km=?, zwrot_deg=?, v_km_s=?, stan=?, tasma_tik=? WHERE id=?');
    $updG = $db->prepare('UPDATE bitwy_systemy SET hz_zadane=?, hz_osiagane=?, hz_min=?, hz_max=?, auto=?, obciazenie_j=?, rykoszet_j=? WHERE id=?');
    $updZ = $db->prepare('UPDATE bitwy_zyly SET martwe=?, temperatury=? WHERE system_id=?');
    $updS = $db->prepare('UPDATE bitwy_sekcje SET integralnosc=? WHERE bitwa_jednostka_id=? AND sekcja=?');
    $idJednostki = [];
    $updSens = bitwa_sensory_dostepne() ? $db->prepare('UPDATE bitwy_jednostki SET zasieg_sensorow_km=? WHERE id=?') : null;
    $updSter = bitwa_ster_dostepny() ? $db->prepare('UPDATE bitwy_jednostki SET kurs_cel_deg=?, v_cel_km_s=?, cel_ognia_nr=? WHERE id=?') : null;
    $nastawy = bitwa_nastawy_dostepne();
    $updCieplo = $nastawy ? $db->prepare('UPDATE bitwy_jednostki SET cieplo_reaktora=?, dlawienie_rdzenia=? WHERE id=?') : null;
    $insN = $nastawy ? $db->prepare('INSERT INTO bitwy_nastawy (bitwa_jednostka_id, kod, wartosc) VALUES (?,?,?) ON DUPLICATE KEY UPDATE wartosc = VALUES(wartosc)') : null;
    foreach ($S['jednostki'] as $j) {
        if ($updCieplo) {
            $updCieplo->execute([$j['cieplo'] ?? 0.0, !empty($j['dlawienie']) ? 1 : 0, $j['id']]);
            foreach ($j['nastawy'] ?? [] as $k => $v) $insN->execute([$j['id'], $k, $v]);
        }
        $idJednostki[$j['nr']] = $j['id'];
        if ($updSens) $updSens->execute([(int) ($j['zasieg_sensorow'] ?? 1000), $j['id']]);
        if ($updSter) $updSter->execute([$j['kurs_cel'] ?? null, $j['v_cel'] ?? null, $j['cel_ognia'] ?? null, $j['id']]);
        $updJ->execute([$j['x'], $j['y'], $j['kurs'], $j['v'], $j['stan'], $S['tasma'][$j['nr']] ?? null, $j['id']]);
        foreach ($j['generatory'] as $g) {
            $updG->execute([$g['hz_zadane'], $g['hz_osiagane'], $g['hz_min'], $g['hz_max'], $g['auto'] ? 1 : 0, $g['obciazenie'], $g['rykoszet'], $g['id']]);
            if ($g['zyly']['martwe'] !== $g['zyly_org']) $updZ->execute([$g['zyly']['martwe'], $g['zyly']['temperatury'], $g['id']]);
        }
        foreach ($j['sekcje'] as $sek => $v) $updS->execute([$v, $j['id'], $sek]);
    }
    $updK = $db->prepare('UPDATE bitwy_klucze SET stan=?, maszyny=?, start_tik=?, rozkaz=?, x_km=?, y_km=?, kurs_deg=?, ladunek=?, akcje=?, figura=?, oslona_pkt=?, kurs_zadany=? WHERE id=?');
    foreach ($S['klucze'] as $k) {
        $updK->execute([$k['stan'], max(0, $k['maszyny']), $k['start'], $k['rozkaz'], $k['x'], $k['y'], $k['kurs'], $k['ladunek'], $k['akcje'],
            bitwa_figura($S, bitwa_kanal_klucza($k)), $k['oslona_pkt'], $k['kurs_zadany'], $k['id']]);
    }
    $insR = $db->prepare('INSERT INTO bitwy_rozkazy (bitwa_id, uid, odebrano_utc, tik, kanal, czas_s, ranga, login, ai, bitwa_jednostka_id, bitwa_klucz_id,
        pulpit, system_kod, rozkaz_kod, wartosc_po, przyczyna, stan, dziala_od_tik, dziala_do_tik, odnowienie_do_tik) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $updR = $db->prepare('UPDATE bitwy_rozkazy SET stan=? WHERE id=?');
    foreach ($S['rozkazy'] as $w) {
        if (!$w['zmieniony']) continue;
        if ($w['id'] !== null) { $updR->execute([$w['stan'], $w['id']]); continue; }
        $jid = null;
        $sys = null;
        if (!str_starts_with($w['kanal'], 'klucz:')) {
            [$nr, $sys] = explode(':', $w['kanal'], 2);
            $jid = $idJednostki[(int) $nr] ?? null;
        }
        $kid = $w['ki'] !== null ? $S['klucze'][$w['ki']]['id'] : null;
        $insR->execute([$id, $w['uid'] ?? bin2hex(random_bytes(16)), $w['odebrano'] ?? bitwa_utc(microtime(true)), $w['tik'], $w['kanal'], $w['czas_s'], $w['ranga'],
            $w['login'] ?? null, !empty($w['ai']) ? 1 : 0, $jid, $kid, $w['pulpit'] ?? null, $sys, $w['kod'], $w['wartosc'] ?? null, $w['przyczyna'] ?? null,
            $w['stan'], $w['dziala_od'], $w['dziala_do'], $w['odnowienie_do']]);
    }
    $insZd = $db->prepare('INSERT INTO bitwy_zdarzenia (bitwa_id, tik, rodzaj, wartosc, opis) VALUES (?,?,?,?,?)');
    foreach ($S['zdarzenia'] as $z) $insZd->execute([$id, $z['tik'], $z['rodzaj'], $z['wartosc'], mb_substr($z['opis'], 0, 255)]);
    $db->prepare('UPDATE mapa_bitwy SET tik_policzony=? WHERE id=?')->execute([$S['tik'], $id]);
}

// Doliczenie tików do „teraz” pod blokadą bitwy; $dzialanie (opcjonalne) dostaje stan po doliczeniu (np. nowy rozkaz).
// Zwraca [bitwa, stan, wynik działania].
function bitwa_dolicz(int $id, ?callable $dzialanie = null): array
{
    $db = polaczenie_bazy();
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT * FROM mapa_bitwy WHERE id=? FOR UPDATE');
        $q->execute([$id]);
        $b = $q->fetch(PDO::FETCH_ASSOC);
        if (!$b) throw new RuntimeException('Nie ma takiej symulacji.');
        $q = $db->prepare('SELECT okno_s FROM bitwy_scenariusze WHERE kod=?');
        $q->execute([$b['scenariusz_kod']]);
        $b['okno_s'] = (int) $q->fetchColumn();
        $S = bitwa_wczytaj_stan($b);
        if ($b['status'] === 'aktywna' && $b['zegar_start_utc'] !== null) {
            // Doliczanie do „teraz”, najwyżej 600 tików i ok. 5 s pracy na zapytanie (reszta w kolejnych odświeżeniach).
            $cel = min((int) floor(bitwa_czas_teraz($b)), (int) $b['okno_s']);
            $t0 = microtime(true);
            for ($n = min(max(0, $cel - $S['tik']), 600); $n > 0; $n--) {
                bitwa_krok($S);
                if (microtime(true) - $t0 > 5.0) break;
            }
        }
        $wynik = $dzialanie ? $dzialanie($S, $b) : null;
        bitwa_zapisz_stan($S);
        // Okno scenariusza minęło i wszystko doliczone: symulacja kończy się sama (XP liczone jak przy „Zakończ”).
        if ($b['status'] === 'aktywna' && $S['tik'] >= (int) $b['okno_s']) {
            $db->prepare("UPDATE mapa_bitwy SET status='zakonczona', koniec_utc=UTC_TIMESTAMP() WHERE id=?")->execute([$id]);
            $b['status'] = 'zakonczona';
            bitwa_policz_xp($id);
            $S['zdarzenia'] = [];
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    $b['tik_policzony'] = $S['tik'];
    return [$b, $S, $wynik];
}

// Widok stanu dla aplikacji Symulacje (konsole dostaną własne widoki stanowisk).
function bitwa_widok(array $b, array $S): array
{
    $db = polaczenie_bazy();
    $sl = bitwa_slowniki();
    $prof = bitwa_rozkazy_profile();
    $czas = $b['zegar_start_utc'] !== null ? min(bitwa_czas_teraz($b), (float) $b['okno_s']) : 0.0;
    $jednostki = [];
    foreach ($S['jednostki'] as $ji => $j) {
        // Bilans mocy liczy silnik w każdym tiku; bez doliczonego tiku (pauza, mostek) — z kopii stanu, bez zapisu.
        if (!isset($j['zasil']) && $j['stan'] === 'w_bitwie') {
            $T = $S;
            $T['_dziala'] = [];
            foreach (array_keys($T['aktywne']) as $i) if ($T['rozkazy'][$i]['stan'] === 'dziala') $T['_dziala'][$T['rozkazy'][$i]['kanal']] = true;
            bitwa_zasilanie($T, (int) $S['tik'], $ji);
            $j['zasil'] = $T['jednostki'][$ji]['zasil'];
        }
        $p = $sl['profile'][$j['profil']];
        $kadlub = 0.0;
        foreach ($j['sekcje'] as $sek => $v) $kadlub += $v * ($p['kadlub'][$sek] ?? 0);
        $c = bitwa_pojemnosc($p);
        $pobor = 0.0;
        foreach ($j['generatory'] as $g) $pobor += ($g['hz_osiagane'] ? bitwa_hz_skuteczne($g['zyly'], $g['hz_osiagane']) : 0) * bitwa_moc_zyly($p);
        $zasieg = (int) ($j['zasieg_sensorow'] ?? 1000);
        $jednostki[] = ['pobor_oslon_proc' => round(100 * $pobor / $p['moc_reaktora_w'], 2), 'stan' => $j['stan'],
            'wejscie_za_s' => $j['stan'] === 'oczekuje' ? round(max(0, $j['wejscie'] - $czas), 1) : null,
            'zasieg_sensorow_km' => $zasieg, 'pobor_sensorow_proc' => bitwa_koszt_sensorow($zasieg), 'zasiegi_sensorow' => BITWA_ZASIEGI_SENSOROW,'id' => $j['id'], 'nazwa' => $j['nazwa'], 'strona' => $j['strona'], 'profil' => $j['profil'], 'x' => round($j['x'], 3), 'y' => round($j['y'], 3),
            'kurs' => round($j['kurs'], 2), 'v' => $j['v'], 'dlugosc_km' => $p['dlugosc_m'] / 1000, 'nr_jednostki' => $j['nr'], 'ai' => !empty($j['ai']),
            'kurs_cel' => $j['kurs_cel'] ?? null, 'v_cel' => $j['v_cel'] ?? null, 'cel_ognia' => $j['cel_ognia'] ?? null,
            'v_maks' => BITWA_STALE['v_maks_okretu'][$j['profil']] ?? 0.3, 'obrot_deg_min' => BITWA_STALE['obrot_okretu'][$j['profil']] ?? 60,
            'ster' => bitwa_ster_dostepny(), 'nastawy_mozliwe' => bitwa_nastawy_dostepne(),
            'nastawy' => ($j['nastawy'] ?? []) + BITWA_NASTAWY, 'zasil' => $j['zasil'] ?? null, 'cieplo' => round((float) ($j['cieplo'] ?? 0), 2),
            'dlawienie' => !empty($j['dlawienie']), 'wzmocnienia' => bitwa_wzmocnienia_widok($S, $j, $czas),
            'kadlub' => round($kadlub, 3), 'sekcje' => array_map(static fn($v) => round($v, 3), $j['sekcje']),
            'stopnie' => array_values(array_filter(bitwa_stopnie($p['zyly']), static fn($s) => $s >= 100)),
            'generatory' => array_map(static fn($g) => $p ? ['id' => $g['id'], 'kod' => $g['kod'], 'hz_zadane' => $g['hz_zadane'], 'hz_osiagane' => $g['hz_osiagane'],
                'hz_skuteczne' => $g['hz_osiagane'] ? bitwa_hz_skuteczne($g['zyly'], $g['hz_osiagane']) : 0, 'auto' => $g['auto'],
                'n' => $g['zyly']['n'], 'siatka_k' => $p['siatka'] ?? (int) ceil(sqrt($g['zyly']['n'])), 'hz_min' => $g['hz_min'], 'hz_max' => $g['hz_max'],
                'martwe_b64' => base64_encode($g['zyly']['martwe']), 'temperatury_b64' => base64_encode($g['zyly']['temperatury']),
                'obciazenie_proc' => round(100 * $g['obciazenie'] / $c, 1), 'rykoszet_j' => $g['rykoszet'], 'martwe' => bitwa_martwe($g['zyly'])] : [], $j['generatory'])];
    }
    $klucze = [];
    foreach ($S['klucze'] as $k) {
        $kan = bitwa_kanal_klucza($k);
        $przyg = null;
        foreach ([$kan, bitwa_kanal_rozkazu($k)] as $kanal) {
            foreach (array_reverse($S['po_kanale'][$kanal] ?? []) as $i) {
                $w = $S['rozkazy'][$i];
                if ($w['stan'] === 'przygotowanie') { $przyg = ['kod' => $w['kod'], 'nazwa' => $prof[$w['kod']]['nazwa'], 'dziala_za_s' => round(max(0, $w['dziala_od'] - $czas), 1)]; break 2; }
            }
        }
        $fig = bitwa_figura($S, $kan);
        $vKl = $sl['maszyny'][$k['typ']]['v'] * ($fig && $prof[$fig]['predkosc_mn'] !== null ? (float) $prof[$fig]['predkosc_mn'] : 1);
        $klucze[] = ['z_zewnatrz' => $k['macierzysta'] === null, 'hz' => (int) round(bitwa_hz_klucza($k)), 'kurs' => round($k['kurs'], 2), 'v' => round($vKl, 3), 'theta' => $fig && $prof[$fig]['theta_mn'] !== null ? (float) $prof[$fig]['theta_mn'] : 1.0,'id' => $k['id'], 'strona' => $k['strona'], 'nr' => $k['nr'], 'typ' => $k['typ'], 'maszyna' => $sl['maszyny'][$k['typ']]['nazwa'] ?? $k['typ'],
            'maszyny' => $k['maszyny'], 'stan' => $k['stan'], 'ranga' => $k['ranga'], 'ai' => $k['ai'], 'akcje' => $k['akcje'],
            'x' => round($k['x'], 3), 'y' => round($k['y'], 3), 'figura' => $fig ? $prof[$fig]['nazwa'] : null, 'przygotowanie' => $przyg,
            'rozkaz' => $k['rozkaz'], 'rozkaz_nazwa' => $prof[$k['rozkaz']]['nazwa'] ?? $k['rozkaz'],
            'start_za_s' => $k['stan'] === 'hangar' && $k['start'] !== null ? round(max(0, $k['start'] - $czas), 1) : null];
    }
    $q = $db->prepare('SELECT tik, rodzaj, opis FROM bitwy_zdarzenia WHERE bitwa_id=? ORDER BY id DESC LIMIT 25');
    $q->execute([(int) $b['id']]);
    $figury = [];
    foreach ($prof as $kod => $r) {
        if ($r['grupa'] === 'okret') continue;
        $figury[] = ['kod' => $kod, 'nazwa' => $r['nazwa'], 'grupa' => $r['grupa'], 'ranga_min' => $r['ranga_min'], 'przygotowanie_s' => (float) $r['przygotowanie_s']];
    }
    return ['id' => (int) $b['id'], 'nazwa' => $b['nazwa'], 'status' => $b['status'], 'pauza' => $b['pauza_od_utc'] !== null, 'czas_s' => round($czas, 2),
        'tik' => $S['tik'], 'okno_s' => (int) $b['okno_s'], 'prowadzacy' => $b['prowadzacy_login'], 'jednostki' => $jednostki, 'klucze' => $klucze,
        'zdarzenia' => $q->fetchAll(PDO::FETCH_ASSOC), 'figury' => $figury];
}

// Wzmocnienia okrętu do widoku: cel → stan (przygotowanie / dziala / odnowienie) i sekundy do zmiany.
function bitwa_wzmocnienia_widok(array $S, array $j, float $czas): array
{
    $out = [];
    foreach (BITWA_WZMOCNIENIA as $cel) {
        $idx = $S['po_kanale']["{$j['nr']}:wzm_$cel"] ?? [];
        if (!$idx) continue;
        $w = $S['rozkazy'][end($idx)];
        if ($w['stan'] === 'przygotowanie') $out[$cel] = ['stan' => 'przygotowanie', 'za_s' => round(max(0, $w['dziala_od'] - $czas), 1)];
        elseif ($w['stan'] === 'dziala') $out[$cel] = ['stan' => 'dziala', 'za_s' => round(max(0, ($w['dziala_do'] ?? 0) - $czas), 1)];
        elseif ($w['odnowienie_do'] && $czas < $w['odnowienie_do'] && $w['stan'] !== 'przerwany') $out[$cel] = ['stan' => 'odnowienie', 'za_s' => round($w['odnowienie_do'] - $czas, 1)];
    }
    return $out;
}

// Stanowiska symulacji i to, co widzi konto: pełny podgląd (MG, prowadzący, kapitan) albo tylko swoje pulpity.
function bitwa_stanowiska_widok(array $u, array $b): array
{
    $db = polaczenie_bazy();
    $okret = bitwa_okret_graczy((int) $b['id']);
    $mg = bitwa_mg($u);
    $kapitan = bitwa_kapitan($u, $okret);
    $pelny = $mg || $kapitan || bitwa_prowadzi($u, $b);
    $login = mb_strtolower((string) ($u['login'] ?? ''));
    $przypisane = [];
    if ($okret) {
        $q = $db->prepare('SELECT pulpit, login FROM bitwy_stanowiska WHERE bitwa_jednostka_id = ? ORDER BY pulpit');
        $q->execute([(int) $okret['id']]);
        $przypisane = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    $postacie = [];
    foreach (uzytkownicy() as $x) $postacie[mb_strtolower((string) $x['login'])] = $x;
    $out = ['okret' => $okret ? ['nazwa' => $okret['nazwa'], 'profil' => $okret['profil_kod'], 'obiekt_id' => (int) $okret['obiekt_id']] : null,
        'stale_migracja' => bitwa_stale_dostepne(), 'pelny' => $pelny, 'mg' => $mg, 'kapitan' => $kapitan,
        'moje' => array_values(array_map(static fn($s) => $s['pulpit'], array_filter($przypisane, static fn($s) => mb_strtolower((string) $s['login']) === $login))),
        'przypisane' => array_map(static fn($s) => ['pulpit' => $s['pulpit'], 'login' => $s['login'],
            'postac' => $postacie[mb_strtolower((string) $s['login'])]['postac'] ?? $s['login']], $przypisane)];
    return $out;
}

// Gracze do przydziału (osobna akcja — sprawdzanie pokładu jest kosztowne): MG — wszyscy (spoza pokładu zostaną
// teleportowani), kapitan — tylko obecni na pokładzie.
function bitwa_gracze_do_przydzialu(array $u, array $okret): array
{
    $mg = bitwa_mg($u);
    $gracze = [];
    foreach (uzytkownicy() as $x) {
        if (($x['rola'] ?? '') === 'mg') continue;
        $naPokladzie = bitwa_na_pokladzie($x) === (int) $okret['obiekt_id'];
        if (!$mg && !$naPokladzie) continue;
        $gracze[] = ['login' => $x['login'], 'postac' => $x['postac'] ?? $x['login'], 'na_pokladzie' => $naPokladzie];
    }
    usort($gracze, static fn($a, $c) => [$c['na_pokladzie'], $a['postac']] <=> [$a['na_pokladzie'], $c['postac']]);
    return $gracze;
}

function bitwa_wiersz(int $id): array
{
    $q = polaczenie_bazy()->prepare("SELECT b.*, s.okno_s FROM mapa_bitwy b JOIN bitwy_scenariusze s ON s.kod = b.scenariusz_kod WHERE b.id=? AND b.rodzaj='symulacja'");
    $q->execute([$id]);
    $b = $q->fetch(PDO::FETCH_ASSOC);
    if (!$b) blad('Nie ma takiej symulacji.', 404);
    return $b;
}

// XP pilotów z mapa_piloci za symulację: akcje klucza × 0,5 × mnożnik rangi pilota.
function bitwa_policz_xp(int $id): array
{
    $db = polaczenie_bazy();
    $q = $db->prepare('SELECT p.pilot_id, p.imie, p.ranga, k.akcje FROM bitwy_piloci p JOIN bitwy_klucze k ON k.id = p.bitwa_klucz_id
        WHERE k.bitwa_id=? AND p.pilot_id IS NOT NULL');
    $q->execute([$id]);
    $ins = $db->prepare('INSERT INTO bitwy_xp (bitwa_id, pilot_id, akcje, xp_surowe, mnoznik, xp) VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE akcje=VALUES(akcje), xp_surowe=VALUES(xp_surowe), mnoznik=VALUES(mnoznik), xp=VALUES(xp)');
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $akcje = (int) $p['akcje'];
        $sur = $akcje * BITWA_STALE['xp_akcja'];
        $mn = BITWA_STALE['xp_mnoznik'][$p['ranga']] ?? 1.0;
        $xp = round($sur * $mn, 1);
        $ins->execute([$id, (int) $p['pilot_id'], $akcje, $sur, $mn, $xp]);
        $out[] = ['pilot' => $p['imie'], 'ranga' => $p['ranga'], 'akcje' => $akcje, 'xp' => $xp];
    }
    return $out;
}

function obsluz_bitwa(string $akcja, bool $post): void
{
    $akcje = ['bitwa_dostep', 'bitwa_test', 'bitwa_scenariusze', 'bitwa_symulacje', 'bitwa_stan', 'bitwa_utworz', 'bitwa_start', 'bitwa_pauza',
        'bitwa_rozkaz', 'bitwa_zakoncz', 'bitwa_xp_zatwierdz', 'bitwa_stanowisko', 'bitwa_gracze', 'mostek_stan', 'mostek_gracze', 'mostek_stanowisko'];
    if (!in_array($akcja, $akcje, true)) return;
    $u = wymagaj_logowania();
    // Bitwa nic nie zapisuje w sesji: zwolnienie blokady sesji pozwala konsolom odpytywać co sekundę równolegle
    // z rozkazami (PHP inaczej szereguje wszystkie zapytania jednej przeglądarki).
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if ($akcja === 'bitwa_dostep') odpowiedz(['upowazniony' => bitwa_upowazniony($u), 'mg' => bitwa_mg($u), 'migracja' => bitwa_dostepna(),
        'stanowisko' => bitwa_moje_stanowisko($u)]);
    if (!bitwa_dostepna()) blad('Symulacje bitew wymagają migracji 068 i 069.');
    if (!bitwa_upowazniony($u)) blad('Brak uprawnień do symulacji bitew.', 403);
    if (in_array($akcja, ['bitwa_test', 'bitwa_xp_zatwierdz'], true) && !bitwa_mg($u)) blad('Tylko dla MG.', 403);
    $db = polaczenie_bazy();
    $w = $post ? wejscie() : [];
    $silnik = static function () { if (!bitwa_silnik_gotowy()) blad('Symulacje wymagają migracji 070, 072, 074 i 075.'); };
    switch ($akcja) {
        case 'bitwa_test':
            $wyniki = bitwa_test();
            $bledy = array_values(array_filter($wyniki, static fn($x) => !$x[3]));
            odpowiedz(['ok' => !$bledy, 'sprawdzono' => count($wyniki), 'bledy' => $bledy,
                'wyniki' => array_map(static fn($x) => ['test' => $x[0], 'oczekiwane' => $x[1], 'otrzymane' => $x[2], 'ok' => $x[3]], $wyniki)]);
        case 'bitwa_scenariusze':
            $out = [];
            foreach ($db->query('SELECT kod, nazwa, odprawa, okno_s, zwyciestwo, porazka, status FROM bitwy_scenariusze ORDER BY nazwa')->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $q = $db->prepare('SELECT strona, dowodzenie, rola FROM bitwy_scenariusze_strony WHERE scenariusz_kod=?');
                $q->execute([$s['kod']]);
                $s['strony'] = $q->fetchAll(PDO::FETCH_ASSOC);
                $q = $db->prepare('SELECT strona, nazwa, profil_kod, z_mapy FROM bitwy_scenariusze_jednostki WHERE scenariusz_kod=? ORDER BY nr');
                $q->execute([$s['kod']]);
                $s['jednostki'] = $q->fetchAll(PDO::FETCH_ASSOC);
                $q = $db->prepare('SELECT strona, typ_maszyny, SUM(liczba_kluczy) AS klucze FROM bitwy_scenariusze_klucze WHERE scenariusz_kod=? GROUP BY strona, typ_maszyny');
                $q->execute([$s['kod']]);
                $s['klucze'] = $q->fetchAll(PDO::FETCH_ASSOC);
                $out[] = $s;
            }
            odpowiedz(['scenariusze' => $out, 'okrety' => bitwa_okrety_konta($u), 'mg' => bitwa_mg($u), 'silnik' => bitwa_silnik_gotowy(),
                'stale' => mostek_moje_stale($u), 'stale_migracja' => bitwa_stale_dostepne()]);
        case 'bitwa_symulacje':
            $silnik();
            $out = [];
            foreach ($db->query("SELECT b.*, s.okno_s FROM mapa_bitwy b JOIN bitwy_scenariusze s ON s.kod = b.scenariusz_kod
                WHERE b.rodzaj='symulacja' ORDER BY b.id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC) as $b) {
                if (!bitwa_moze($u, $b)) continue;
                $out[] = ['id' => (int) $b['id'], 'nazwa' => $b['nazwa'], 'status' => $b['status'], 'pauza' => $b['pauza_od_utc'] !== null,
                    'prowadzacy' => $b['prowadzacy_login'], 'czas_s' => $b['zegar_start_utc'] !== null ? round(bitwa_czas_teraz($b), 1) : 0, 'okno_s' => (int) $b['okno_s'],
                    'utworzono' => $b['rozpoczecie_utc']];
            }
            odpowiedz(['symulacje' => $out]);
        case 'bitwa_stan':
            $silnik();
            $b = bitwa_wiersz((int) ($_GET['id'] ?? 0));
            if (!bitwa_moze($u, $b)) blad('Brak dostępu do tej symulacji.', 403);
            [$b, $S] = bitwa_dolicz((int) $b['id']);
            odpowiedz(['stan' => bitwa_widok($b, $S) + ['moge_prowadzic' => bitwa_prowadzi($u, $b), 'mg' => bitwa_mg($u),
                'stanowiska' => bitwa_stanowiska_widok($u, $b)]]);
        case 'bitwa_utworz':
            $silnik();
            if (!$post) blad('Zła metoda.', 405);
            $id = bitwa_utworz($u, (string) ($w['scenariusz'] ?? ''), isset($w['obiekt_id']) ? (int) $w['obiekt_id'] : null);
            odpowiedz(['id' => $id]);
        case 'bitwa_start':
        case 'bitwa_pauza':
        case 'bitwa_zakoncz':
            $silnik();
            if (!$post) blad('Zła metoda.', 405);
            $b = bitwa_wiersz((int) ($w['id'] ?? 0));
            if (!bitwa_prowadzi($u, $b)) blad('Symulację prowadzi MG albo jej prowadzący.', 403);
            $teraz = microtime(true);
            if ($akcja === 'bitwa_start') {
                if ($b['status'] !== 'przygotowanie') blad('Symulacja już ruszyła.');
                $db->prepare("UPDATE mapa_bitwy SET status='aktywna', zegar_start_utc=? WHERE id=?")->execute([bitwa_utc($teraz), (int) $b['id']]);
            } elseif ($akcja === 'bitwa_pauza') {
                if ($b['status'] !== 'aktywna') blad('Symulacja nie trwa.');
                [$b] = bitwa_dolicz((int) $b['id']);
                if (!empty($w['pauza'])) {
                    if ($b['pauza_od_utc'] === null) $db->prepare('UPDATE mapa_bitwy SET pauza_od_utc=? WHERE id=?')->execute([bitwa_utc($teraz), (int) $b['id']]);
                } elseif ($b['pauza_od_utc'] !== null) {
                    $db->prepare('UPDATE mapa_bitwy SET zegar_pauzy_s = zegar_pauzy_s + ?, pauza_od_utc=NULL WHERE id=?')
                        ->execute([max(0.0, $teraz - (float) bitwa_z_utc($b['pauza_od_utc'])), (int) $b['id']]);
                }
            } else {
                if (in_array($b['status'], ['zakonczona', 'anulowana'], true)) blad('Symulacja jest już zakończona.');
                if ($b['status'] === 'aktywna') bitwa_dolicz((int) $b['id']);
                $db->prepare("UPDATE mapa_bitwy SET status=?, koniec_utc=UTC_TIMESTAMP() WHERE id=?")
                    ->execute([$b['status'] === 'aktywna' ? 'zakonczona' : 'anulowana', (int) $b['id']]);
                odpowiedz(['ok' => true, 'xp' => $b['status'] === 'aktywna' ? bitwa_policz_xp((int) $b['id']) : []]);
            }
            odpowiedz(['ok' => true]);
        case 'bitwa_rozkaz':
            $silnik();
            if (!$post) blad('Zła metoda.', 405);
            $b = bitwa_wiersz((int) ($w['id'] ?? 0));
            if (!bitwa_moze($u, $b)) blad('Brak dostępu do tej symulacji.', 403);
            if ($b['status'] !== 'aktywna' || $b['pauza_od_utc'] !== null) blad('Symulacja nie trwa — rozkazy czekają na start lub koniec pauzy.');
            $st = bitwa_stanowiska_widok($u, $b);
            if (!$st['pelny']) {
                $pulpitRozkazu = (string) ($w['pulpit'] ?? (isset($w['klucz_id']) ? 'Myśliwce' : (isset($w['jednostka_id']) ? 'Sensory' : 'Osłony')));
                $wolno = in_array($pulpitRozkazu, $st['moje'], true) && !isset($w['klucz_id']);
                if (isset($w['klucz_id'])) {
                    // Klucz: pulpit Myśliwce albo przydział „Klucz N” / „Eskadra N” (3 klucze: 1–3, 4–6, …) obejmujący ten klucz.
                    $q = $db->prepare('SELECT nr FROM bitwy_klucze WHERE id=? AND bitwa_id=?');
                    $q->execute([(int) $w['klucz_id'], (int) $b['id']]);
                    $nrK = (int) $q->fetchColumn();
                    foreach ($st['moje'] as $p) {
                        if ($p === 'Myśliwce' || $p === "Klucz $nrK" || $p === 'Eskadra ' . (int) ceil($nrK / 3)) $wolno = true;
                    }
                }
                if (!$wolno) blad("Ten rozkaz wydaje się z pulpitu {$pulpitRozkazu} — nie masz go przydzielonego.", 403);
            }
            $uid = substr(preg_replace('/[^A-Za-z0-9-]/', '', (string) ($w['uid'] ?? '')), 0, 36) ?: bin2hex(random_bytes(16));
            $q = $db->prepare('SELECT 1 FROM bitwy_rozkazy WHERE bitwa_id=? AND uid=?');
            $q->execute([(int) $b['id'], $uid]);
            if ($q->fetchColumn()) odpowiedz(['ok' => true, 'powtorka' => true]);
            $odebrano = microtime(true);
            $kod = (string) ($w['kod'] ?? '');
            [, , $wynik] = bitwa_dolicz((int) $b['id'], static function (array &$S, array $b) use ($w, $kod, $uid, $odebrano, $u) {
                $czas = bitwa_czas_teraz($b);
                $meta = ['uid' => $uid, 'odebrano' => bitwa_utc($odebrano), 'login' => (string) ($u['login'] ?? '')];
                if (isset($w['klucz_id'])) {
                    foreach ($S['klucze'] as $ki => $k) {
                        if ($k['id'] !== (int) $w['klucz_id']) continue;
                        if ($k['ai']) return ['odmowa' => 'Ten klucz prowadzi AI strony przeciwnej.'];
                        $grupa = bitwa_rozkazy_profile()[$kod]['grupa'] ?? null;
                        if ($grupa === null || $grupa === 'okret') return ['odmowa' => 'To nie jest rozkaz dla klucza.'];
                        if ($kod === 'start') {
                            if ($k['stan'] !== 'hangar' || $k['start'] !== null) return ['odmowa' => 'Start tylko dla klucza w hangarze, który nie czeka już na taśmociągu.'];
                        } elseif ($k['stan'] !== 'w_locie' && !($grupa === 'rozkaz_klucza' && $k['stan'] === 'powrot')) {
                            return ['odmowa' => $k['stan'] === 'hangar' ? 'Klucz jest w hangarze — najpierw Start.' : 'Klucz nie jest w locie.'];
                        }
                        $kanal = $grupa === 'rozkaz_klucza' ? bitwa_kanal_rozkazu($k) : bitwa_kanal_klucza($k);
                        $r = bitwa_przyjmij($S, $kanal, $kod, $czas, $k['ranga'], $ki, $meta);
                        return is_array($r) ? $r : ['dziala_za_s' => round($S['rozkazy'][$r]['dziala_od'] - $czas, 1)];
                    }
                    return ['odmowa' => 'Nie ma takiego klucza.'];
                }
                if (isset($w['jednostka_id']) && ($kod === 'ster' || $kod === 'cel_ognia')) {
                    if (!bitwa_ster_dostepny()) return ['odmowa' => 'Ster i wskazanie celu wymagają migracji 076.'];
                    foreach ($S['jednostki'] as $j) {
                        if ($j['id'] !== (int) $w['jednostka_id']) continue;
                        if (!empty($j['ai'])) return ['odmowa' => 'Tym okrętem dowodzi AI.'];
                        if ($kod === 'ster') {
                            $kurs = bitwa_norm180((float) ($w['kurs'] ?? $j['kurs_cel'] ?? $j['kurs']));
                            $v = max(0.0, min(BITWA_STALE['v_maks_okretu'][$j['profil']] ?? 0.3, (float) ($w['v'] ?? $j['v_cel'] ?? $j['v'])));
                            $wart = round($kurs, 2) . ';' . round($v, 4);
                        } else {
                            $wart = isset($w['cel_nr']) && $w['cel_nr'] !== null && $w['cel_nr'] !== '' ? (string) (int) $w['cel_nr'] : '';
                        }
                        $r = bitwa_przyjmij($S, "{$j['nr']}:$kod", $kod, $czas, 'standard', null,
                            $meta + ['wartosc' => $wart, 'pulpit' => mb_substr((string) ($w['pulpit'] ?? 'Silniki'), 0, 40)]);
                        return is_array($r) ? $r : ['dziala_za_s' => round($S['rozkazy'][$r]['dziala_od'] - $czas, 1)];
                    }
                    return ['odmowa' => 'Nie ma takiej jednostki.'];
                }
                if (isset($w['jednostka_id']) && in_array($kod, ['nastawa', 'synchronizuj', 'przelacz', 'wyrownaj', 'wzmocnij'], true)) {
                    // Konsole Osłony i Reaktory (MECHANIKA 11.4): kanał „nr:n_kod” (nastawa), „nr:wzm_cel”, „nr:wyrownaj_grupa”.
                    if (!bitwa_nastawy_dostepne()) return ['odmowa' => 'Nastawy osłon i reaktorów wymagają migracji 078.'];
                    foreach ($S['jednostki'] as $j) {
                        if ($j['id'] !== (int) $w['jednostka_id']) continue;
                        if (!empty($j['ai'])) return ['odmowa' => 'Tym okrętem dowodzi AI.'];
                        $pulpitR = mb_substr((string) ($w['pulpit'] ?? 'Reaktory'), 0, 40);
                        if ($kod === 'wzmocnij') {
                            $cel = (string) ($w['cel'] ?? '');
                            if (!in_array($cel, BITWA_WZMOCNIENIA, true)) return ['odmowa' => 'Nieznany cel wzmocnienia.'];
                            $kanal = "{$j['nr']}:wzm_$cel";
                            $wart = null;
                        } elseif ($kod === 'wyrownaj') {
                            $grupa = (string) ($w['grupa'] ?? '');
                            if (!isset(BITWA_GRUPY_WYROWNANIA[$grupa])) return ['odmowa' => 'Nieznana grupa wyrównania.'];
                            $kanal = "{$j['nr']}:wyrownaj_$grupa";
                            $wart = $grupa;
                        } else {
                            $k = (string) ($w['nastawa'] ?? '');
                            if (!isset(BITWA_NASTAWY[$k]) || str_starts_with($k, 'zapor_do_')) return ['odmowa' => 'Nieznana nastawa.'];
                            $czyPrzel = str_starts_with($k, 'przel_');
                            $czySync = str_starts_with($k, 'sync_');
                            $kod = $czyPrzel ? 'przelacz' : ($czySync ? 'synchronizuj' : 'nastawa');
                            $v = max(0.0, min(1.0, (float) ($w['wartosc'] ?? 0)));
                            if ($czyPrzel || $czySync) $v = $v >= 0.5 ? 1.0 : 0.0;
                            $kanal = "{$j['nr']}:n_$k";
                            $wart = $k . '=' . round($v, 4);
                        }
                        $r = bitwa_przyjmij($S, $kanal, $kod, $czas, 'standard', null, $meta + ['wartosc' => $wart, 'pulpit' => $pulpitR]);
                        return is_array($r) ? $r : ['dziala_za_s' => round($S['rozkazy'][$r]['dziala_od'] - $czas, 1)];
                    }
                    return ['odmowa' => 'Nie ma takiej jednostki.'];
                }
                if (isset($w['jednostka_id']) && $kod === 'sensory_zasieg') {
                    if (!bitwa_sensory_dostepne()) return ['odmowa' => 'Zasięg sensorów wymaga migracji 073.'];
                    $km = (int) ($w['zasieg_km'] ?? 0);
                    if (!in_array($km, BITWA_ZASIEGI_SENSOROW, true)) return ['odmowa' => 'Nieznany poziom zasięgu sensorów.'];
                    foreach ($S['jednostki'] as $ji => $j) {
                        if ($j['id'] !== (int) $w['jednostka_id']) continue;
                        $S['jednostki'][$ji]['zasieg_sensorow'] = $km;
                        return ['ok' => true, 'koszt_porcje' => bitwa_koszt_sensorow($km)];
                    }
                    return ['odmowa' => 'Nie ma takiej jednostki.'];
                }
                foreach ($S['jednostki'] as $ji => $j) {
                    foreach ($j['generatory'] as $gi => $g) {
                        if ($g['id'] !== (int) ($w['generator_id'] ?? 0)) continue;
                        if ($kod === 'auto') { $S['jednostki'][$ji]['generatory'][$gi]['auto'] = true; return ['ok' => true]; }
                        if ($kod === 'zakres') {
                            // Zakres trybu automatycznego (szare suwaki MIN/MAX pulpitu osłon): stopnie natywne, min ≤ max.
                            $min = (int) ($w['hz_min'] ?? $g['hz_min']);
                            $max = (int) ($w['hz_max'] ?? $g['hz_max']);
                            $n = $g['zyly']['n'];
                            if ($min < 1 || $max < $min || $n % $min !== 0 || $n % $max !== 0) return ['odmowa' => 'Zakres musi być parą stopni natywnych, MIN ≤ MAX.'];
                            $S['jednostki'][$ji]['generatory'][$gi]['hz_min'] = $min;
                            $S['jednostki'][$ji]['generatory'][$gi]['hz_max'] = $max;
                            return ['ok' => true];
                        }
                        $hz = (int) ($w['hz'] ?? 0);
                        if ($hz < 1 || $g['zyly']['n'] % $hz !== 0) return ['odmowa' => 'To nie jest stopień natywny generatora.'];
                        // ręcznie z pulpitu osłon (12 s) albo preset z sensorów / radaru („wyślij Hz do osłon”, 3 s)
                        $kodP = in_array($kod, ['przestrojenie_reczne', 'przestrojenie_preset'], true) ? $kod : 'przestrojenie_reczne';
                        $pulpit = mb_substr((string) ($w['pulpit'] ?? 'Osłony'), 0, 40);
                        $S['jednostki'][$ji]['generatory'][$gi]['auto'] = false;
                        $S['jednostki'][$ji]['generatory'][$gi]['hz_zadane'] = $hz;
                        $r = bitwa_przyjmij($S, "{$j['nr']}:{$g['kod']}", $kodP, $czas, 'standard', null, $meta + ['wartosc' => (string) $hz, 'pulpit' => $pulpit]);
                        return is_array($r) ? $r : ['dziala_za_s' => round($S['rozkazy'][$r]['dziala_od'] - $czas, 1)];
                    }
                }
                return ['odmowa' => 'Nieznany cel rozkazu.'];
            });
            if (isset($wynik['odmowa'])) odpowiedz(['ok' => false, 'odmowa' => $wynik['odmowa']]);
            odpowiedz(['ok' => true] + $wynik);
        case 'bitwa_gracze':
            $silnik();
            $b = bitwa_wiersz((int) ($_GET['id'] ?? 0));
            $okret = bitwa_okret_graczy((int) $b['id']);
            if (!$okret) blad('Ta symulacja nie ma okrętu z mapy.');
            if (!bitwa_mg($u) && !bitwa_kapitan($u, $okret)) blad('Stanowiska przydziela MG albo kapitan okrętu.', 403);
            odpowiedz(['gracze' => bitwa_gracze_do_przydzialu($u, $okret)]);
        case 'bitwa_stanowisko':
            // Przypisanie pulpitu graczowi (login) albo zwolnienie (login pusty). MG: każdy gracz, postać spoza pokładu
            // zostaje teleportowana na mostek. Kapitan: tylko gracze na pokładzie.
            $silnik();
            if (!$post) blad('Zła metoda.', 405);
            $b = bitwa_wiersz((int) ($w['id'] ?? 0));
            $okret = bitwa_okret_graczy((int) $b['id']);
            if (!$okret) blad('Ta symulacja nie ma okrętu z mapy.');
            $mg = bitwa_mg($u);
            if (!$mg && !bitwa_kapitan($u, $okret)) blad('Stanowiska przydziela MG albo kapitan okrętu.', 403);
            $pulpit = mb_substr(trim((string) ($w['pulpit'] ?? '')), 0, 40);
            if ($pulpit === '') blad('Wybierz pulpit.');
            $login = trim((string) ($w['login'] ?? ''));
            if ($login === '') {
                $db->prepare('DELETE FROM bitwy_stanowiska WHERE bitwa_jednostka_id = ? AND pulpit = ?')->execute([(int) $okret['id'], $pulpit]);
                odpowiedz(['ok' => true]);
            }
            $gracz = null;
            foreach (uzytkownicy() as $x) if (mb_strtolower((string) $x['login']) === mb_strtolower($login)) $gracz = $x;
            if (!$gracz || ($gracz['rola'] ?? '') === 'mg') blad('Nie ma takiego gracza.');
            $teleport = false;
            if (bitwa_na_pokladzie($gracz) !== (int) $okret['obiekt_id']) {
                if (!$mg) blad('Kapitan przydziela stanowiska tylko załodze na pokładzie.');
                bitwa_teleport_na_okret($gracz, (int) $okret['obiekt_id']);
                $teleport = true;
            }
            $db->prepare('INSERT INTO bitwy_stanowiska (bitwa_jednostka_id, pulpit, login) VALUES (?,?,?) ON DUPLICATE KEY UPDATE login = VALUES(login)')
                ->execute([(int) $okret['id'], $pulpit, (string) $gracz['login']]);
            odpowiedz(['ok' => true, 'teleport' => $teleport, 'postac' => $gracz['postac'] ?? $gracz['login']]);
        case 'mostek_stan':
            odpowiedz(['stan' => mostek_widok($u, (int) ($_GET['okret'] ?? 0))]);
        case 'mostek_gracze':
            $o = mostek_okret((int) ($_GET['okret'] ?? 0));
            if (!mostek_dostep($u, $o)['przydziela']) blad('Stanowiska przydziela MG albo kapitan okrętu.', 403);
            odpowiedz(['gracze' => bitwa_gracze_do_przydzialu($u, ['obiekt_id' => (int) $o['id']])]);
        case 'mostek_stanowisko':
            if (!$post) blad('Zła metoda.', 405);
            odpowiedz(mostek_przydziel($u, (int) ($w['okret'] ?? 0), (string) ($w['pulpit'] ?? ''), (string) ($w['login'] ?? '')));
        case 'bitwa_xp_zatwierdz':
            $silnik();
            if (!$post) blad('Zła metoda.', 405);
            $b = bitwa_wiersz((int) ($w['id'] ?? 0));
            if ($b['status'] !== 'zakonczona') blad('XP zatwierdza się po zakończeniu symulacji.');
            $db->beginTransaction();
            try {
                $q = $db->prepare('SELECT pilot_id, xp FROM bitwy_xp WHERE bitwa_id=? AND zatwierdzono_utc IS NULL FOR UPDATE');
                $q->execute([(int) $b['id']]);
                $upd = $db->prepare('UPDATE mapa_piloci SET xp = xp + ? WHERE id=?');
                $ile = 0;
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) { $upd->execute([(float) $x['xp'], (int) $x['pilot_id']]); $ile++; }
                $db->prepare('UPDATE bitwy_xp SET zatwierdzono_utc=UTC_TIMESTAMP(), zatwierdzil_login=? WHERE bitwa_id=? AND zatwierdzono_utc IS NULL')
                    ->execute([(string) ($u['login'] ?? ''), (int) $b['id']]);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            odpowiedz(['ok' => true, 'piloci' => $ile]);
    }
}

<?php
declare(strict_types=1);

// Bitwy i symulacje bitew (migracje 068, 069). Mechanika: narzedzia/mostek/MECHANIKA.md, wdrożenie: WDROZENIE.md.
//
// Ten plik to rdzeń silnika przeniesiony 1:1 z silnika wzorcowego (narzedzia/mostek/silnik.py): losowość z ziarna
// (crc32 — ta sama w Pythonie i PHP), zegar bitwy z pauzą MG, czas wykonania rozkazów (przygotowanie zależne od rangi),
// stopnie natywne, filtr energii osłon, żyły jako mapa bitowa (martwe) i Hz skuteczne.
// Zgodność z wzorcem sprawdza akcja api/?a=bitwa_test (MG) na wektorach z api/bitwa_wektory.php.
// Pętla tików, tworzenie symulacji ze scenariusza i konsole — kolejne etapy (WDROZENIE.md, rozdz. 6).
//
// Akcje: bitwa_test (MG), bitwa_scenariusze (MG) — lista scenariuszy z odprawą i składem.

require_once __DIR__ . '/bitwa_wektory.php';

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
    return $w;
}

function obsluz_bitwa(string $akcja, bool $post): void
{
    if (!in_array($akcja, ['bitwa_test', 'bitwa_scenariusze'], true)) return;
    wymagaj_mg();
    if (!bitwa_dostepna()) blad('Bitwy wymagają migracji 068 i 069.');
    $db = polaczenie_bazy();
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
            odpowiedz(['scenariusze' => $out]);
    }
}

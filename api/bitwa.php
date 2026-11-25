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
// bitwa_rozkaz, bitwa_zakoncz, bitwa_xp_zatwierdz (MG), bitwa_test (MG). Upoważnieni: MG i konta, które mogą dowodzić
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
        $nazwa = "przebieg {$pb['kod']}/{$pb['ziarno']}" . ($pb['auto'] ? '' : ' (osłony ręczne)');
        $S = bitwa_stan_ze_scenariusza(bitwa_scenariusz_dane($pb['kod']), (int) $pb['ziarno']);
        if (!$pb['auto']) foreach ($S['jednostki'] as $ji => $j) foreach (array_keys($j['generatory']) as $gi) $S['jednostki'][$ji]['generatory'][$gi]['auto'] = false;
        for ($i = 0; $i < $pb['tiki']; $i++) bitwa_krok($S);
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
    try { return (bool) bitwa_okrety_konta($u); } catch (Throwable $e) { return false; }
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
    $ids = array_map(static fn($o) => (int) $o['id'], bitwa_okrety_konta($u));
    if (!$ids) return false;
    $q = polaczenie_bazy()->prepare('SELECT obiekt_id FROM bitwy_jednostki WHERE bitwa_id=? AND obiekt_id IS NOT NULL');
    $q->execute([(int) $b['id']]);
    return (bool) array_intersect($ids, array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)));
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
            'wejscie' => (int) $r['wejscie_tik'], 'generatory' => $gen, 'sekcje' => $sek, 'stan' => $r['stan'], 'obiekt_id' => $r['obiekt_id']];
    }
    $indeksKlucza = [];
    $q = $db->prepare('SELECT * FROM bitwy_klucze WHERE bitwa_id=? ORDER BY id');
    $q->execute([$id]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $indeksKlucza[(int) $r['id']] = count($S['klucze']);
        $S['klucze'][] = ['id' => (int) $r['id'], 'strona' => $r['strona'], 'nr' => (int) $r['numer'], 'typ' => $r['typ_maszyny'], 'maszyny' => (int) $r['maszyny'],
            'ranga' => $r['ranga'], 'moc' => (float) $r['moc'], 'stan' => $r['stan'], 'start' => $r['start_tik'] !== null ? (int) $r['start_tik'] : null, 'x' => (float) $r['x_km'], 'y' => (float) $r['y_km'],
            'kurs' => (float) $r['kurs_deg'], 'rozkaz' => $r['rozkaz'], 'macierzysta' => $r['macierzysta_id'] !== null ? ($nrJednostki[(int) $r['macierzysta_id']] ?? null) : null,
            'ladunek' => (int) $r['ladunek'], 'akcje' => (int) $r['akcje'], 'ai' => (bool) (int) $r['ai']];
    }
    // Rozkazy potrzebne silnikowi: w przygotowaniu i działające, z bieżącego cyklu (limit zmian) i w odnowieniu.
    $q = $db->prepare("SELECT * FROM bitwy_rozkazy WHERE bitwa_id=? AND (stan IN ('przygotowanie','dziala') OR tik >= ? OR odnowienie_do_tik > ?) ORDER BY id");
    $q->execute([$id, intdiv($tik, BITWA_CYKL) * BITWA_CYKL, $tik]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['rozkaz_kod'] === null) continue;
        $S['rozkazy'][] = ['id' => (int) $r['id'], 'kanal' => $r['kanal'], 'kod' => $r['rozkaz_kod'], 'tik' => (int) $r['tik'], 'czas_s' => (float) $r['czas_s'],
            'stan' => $r['stan'], 'ranga' => $r['ranga'], 'dziala_od' => (int) $r['dziala_od_tik'],
            'dziala_do' => $r['dziala_do_tik'] !== null ? (int) $r['dziala_do_tik'] : null, 'odnowienie_do' => $r['odnowienie_do_tik'] !== null ? (int) $r['odnowienie_do_tik'] : null,
            'ki' => $r['bitwa_klucz_id'] !== null ? ($indeksKlucza[(int) $r['bitwa_klucz_id']] ?? null) : null, 'zmieniony' => false];
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
    $updG = $db->prepare('UPDATE bitwy_systemy SET hz_zadane=?, hz_osiagane=?, auto=?, obciazenie_j=?, rykoszet_j=? WHERE id=?');
    $updZ = $db->prepare('UPDATE bitwy_zyly SET martwe=?, temperatury=? WHERE system_id=?');
    $updS = $db->prepare('UPDATE bitwy_sekcje SET integralnosc=? WHERE bitwa_jednostka_id=? AND sekcja=?');
    $idJednostki = [];
    foreach ($S['jednostki'] as $j) {
        $idJednostki[$j['nr']] = $j['id'];
        $updJ->execute([$j['x'], $j['y'], $j['kurs'], $j['v'], $j['stan'], $S['tasma'][$j['nr']] ?? null, $j['id']]);
        foreach ($j['generatory'] as $g) {
            $updG->execute([$g['hz_zadane'], $g['hz_osiagane'], $g['auto'] ? 1 : 0, $g['obciazenie'], $g['rykoszet'], $g['id']]);
            if ($g['zyly']['martwe'] !== $g['zyly_org']) $updZ->execute([$g['zyly']['martwe'], $g['zyly']['temperatury'], $g['id']]);
        }
        foreach ($j['sekcje'] as $sek => $v) $updS->execute([$v, $j['id'], $sek]);
    }
    $updK = $db->prepare('UPDATE bitwy_klucze SET stan=?, start_tik=?, rozkaz=?, x_km=?, y_km=?, kurs_deg=?, ladunek=?, akcje=?, figura=? WHERE id=?');
    foreach ($S['klucze'] as $k) {
        $updK->execute([$k['stan'], $k['start'], $k['rozkaz'], $k['x'], $k['y'], $k['kurs'], $k['ladunek'], $k['akcje'], bitwa_figura($S, bitwa_kanal_klucza($k)), $k['id']]);
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
            $cel = min((int) floor(bitwa_czas_teraz($b)), (int) $b['okno_s']);
            for ($n = min(max(0, $cel - $S['tik']), 600); $n > 0; $n--) bitwa_krok($S);
        }
        $wynik = $dzialanie ? $dzialanie($S, $b) : null;
        bitwa_zapisz_stan($S);
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
    $czas = $b['zegar_start_utc'] !== null ? bitwa_czas_teraz($b) : 0.0;
    $jednostki = [];
    foreach ($S['jednostki'] as $j) {
        $p = $sl['profile'][$j['profil']];
        $kadlub = 0.0;
        foreach ($j['sekcje'] as $sek => $v) $kadlub += $v * ($p['kadlub'][$sek] ?? 0);
        $c = bitwa_pojemnosc($p);
        $jednostki[] = ['id' => $j['id'], 'nazwa' => $j['nazwa'], 'strona' => $j['strona'], 'profil' => $j['profil'], 'x' => round($j['x'], 3), 'y' => round($j['y'], 3),
            'kurs' => round($j['kurs'], 2), 'v' => $j['v'],
            'kadlub' => round($kadlub, 3), 'sekcje' => array_map(static fn($v) => round($v, 3), $j['sekcje']),
            'stopnie' => array_values(array_filter(bitwa_stopnie($p['zyly']), static fn($s) => $s >= 100)),
            'generatory' => array_map(static fn($g) => ['id' => $g['id'], 'kod' => $g['kod'], 'hz_zadane' => $g['hz_zadane'], 'hz_osiagane' => $g['hz_osiagane'],
                'hz_skuteczne' => $g['hz_osiagane'] ? bitwa_hz_skuteczne($g['zyly'], $g['hz_osiagane']) : 0, 'auto' => $g['auto'],
                'obciazenie_proc' => round(100 * $g['obciazenie'] / $c, 1), 'rykoszet_j' => $g['rykoszet'], 'martwe' => bitwa_martwe($g['zyly'])], $j['generatory'])];
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
        $klucze[] = ['hz' => (int) round(bitwa_hz_klucza($k)), 'kurs' => round($k['kurs'], 2), 'v' => round($vKl, 3), 'theta' => $fig && $prof[$fig]['theta_mn'] !== null ? (float) $prof[$fig]['theta_mn'] : 1.0,'id' => $k['id'], 'strona' => $k['strona'], 'nr' => $k['nr'], 'typ' => $k['typ'], 'maszyna' => $sl['maszyny'][$k['typ']]['nazwa'] ?? $k['typ'],
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
        'bitwa_rozkaz', 'bitwa_zakoncz', 'bitwa_xp_zatwierdz'];
    if (!in_array($akcja, $akcje, true)) return;
    $u = wymagaj_logowania();
    if ($akcja === 'bitwa_dostep') odpowiedz(['upowazniony' => bitwa_upowazniony($u), 'mg' => bitwa_mg($u), 'migracja' => bitwa_dostepna()]);
    if (!bitwa_dostepna()) blad('Symulacje bitew wymagają migracji 068 i 069.');
    if (!bitwa_upowazniony($u)) blad('Brak uprawnień do symulacji bitew.', 403);
    if (in_array($akcja, ['bitwa_test', 'bitwa_xp_zatwierdz'], true) && !bitwa_mg($u)) blad('Tylko dla MG.', 403);
    $db = polaczenie_bazy();
    $w = $post ? wejscie() : [];
    $silnik = static function () { if (!bitwa_silnik_gotowy()) blad('Symulacje wymagają migracji 070 i 072.'); };
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
            odpowiedz(['scenariusze' => $out, 'okrety' => bitwa_okrety_konta($u), 'mg' => bitwa_mg($u), 'silnik' => bitwa_silnik_gotowy()]);
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
            odpowiedz(['stan' => bitwa_widok($b, $S) + ['moge_prowadzic' => bitwa_prowadzi($u, $b), 'mg' => bitwa_mg($u)]]);
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
                foreach ($S['jednostki'] as $ji => $j) {
                    foreach ($j['generatory'] as $gi => $g) {
                        if ($g['id'] !== (int) ($w['generator_id'] ?? 0)) continue;
                        if ($kod === 'auto') { $S['jednostki'][$ji]['generatory'][$gi]['auto'] = true; return ['ok' => true]; }
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

<?php
// WNĘTRZA OKRĘTÓW: pomieszczenie postaci na pokładzie, przejścia między pomieszczeniami i okna.
// Plan wnętrza to drzewo w `lokalizacje` (szablon klasy, np. ISD I: sekcja → pokład → pomieszczenie),
// wskazane przez mapa_obiekty.plan_wnetrza_id (migracja 036). Postać pamięta pomieszczenie w karcie
// (`wnetrze`: okręt, pomieszczenie, trwające przejście) — przy innym okręcie zapis jest nieaktualny.

declare(strict_types=1);

// Minuty na krok w drzewie: między pomieszczeniami pokładu, turbowinda między pokładami, między sekcjami.
const WNETRZE_KROK_MIN = [3 => 0.75, 2 => 1.25, 1 => 1.75];
const WNETRZE_ZAPAS_MIN = 0.5;
const WNETRZE_SKALA = 1.2;

function wnetrze_dostepne(): bool
{
    static $jest = null;
    if ($jest === null) {
        try {
            polaczenie_bazy()->query('SELECT plan_wnetrza_id FROM mapa_obiekty LIMIT 0');
            polaczenie_bazy()->query('SELECT okno FROM lokalizacje LIMIT 0');
            $jest = true;
        } catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

function wnetrze_plan_okretu(int $okretId): ?int
{
    if (!wnetrze_dostepne() || !$okretId) return null;
    $q = polaczenie_bazy()->prepare('SELECT plan_wnetrza_id FROM mapa_obiekty WHERE id = ?');
    $q->execute([$okretId]);
    $plan = (int) $q->fetchColumn();
    return $plan ?: null;
}

// Wszystkie węzły planu: [id => [id, rodzic, nazwa, krotka, typ, rodzaj, okno, glebokosc, poklad]].
function wnetrze_wezly(int $plan): array
{
    static $cache = [];
    if (isset($cache[$plan])) return $cache[$plan];
    $q = polaczenie_bazy()->prepare('WITH RECURSIVE drzewo AS (
            SELECT id, rodzic_id, nazwa, typ, rodzaj, okno, 0 AS glebokosc FROM lokalizacje WHERE id = ?
            UNION ALL
            SELECT c.id, c.rodzic_id, c.nazwa, c.typ, c.rodzaj, c.okno, d.glebokosc + 1 FROM lokalizacje c JOIN drzewo d ON c.rodzic_id = d.id
        ) SELECT * FROM drzewo');
    $q->execute([$plan]);
    $w = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // „ISD-I – Mostek główny” → „Mostek główny”.
        $krotka = preg_replace('/^[^–]*–\s*/u', '', (string) $r['nazwa']) ?: (string) $r['nazwa'];
        $w[(int) $r['id']] = ['id' => (int) $r['id'], 'rodzic' => $r['rodzic_id'] === null ? null : (int) $r['rodzic_id'],
            'nazwa' => (string) $r['nazwa'], 'krotka' => $krotka, 'typ' => $r['typ'], 'rodzaj' => $r['rodzaj'],
            'okno' => $r['okno'] ?: 'brak', 'glebokosc' => (int) $r['glebokosc']];
    }
    // Grupa na liście: pokład (przodek na głębokości 2 o nazwie „Pokład …”), inaczej sekcja (głębokość 1).
    foreach ($w as $id => $x) {
        $g = $x;
        $sekcja = '';
        $poklad = '';
        while (isset($w[$g['id']])) {
            if ($g['glebokosc'] === 2 && mb_stripos($g['krotka'], 'Pokład') !== false) $poklad = $g['krotka'];
            if ($g['glebokosc'] === 1) $sekcja = $g['krotka'];
            if ($g['rodzic'] === null || !isset($w[$g['rodzic']])) break;
            $g = $w[$g['rodzic']];
        }
        $w[$id]['grupa'] = $poklad ?: $sekcja;
    }
    return $cache[$plan] = $w;
}

function wnetrze_szukaj(array $w, string $koncowka): ?int
{
    foreach ($w as $id => $x) if (mb_strtolower($x['krotka']) === mb_strtolower($koncowka)) return $id;
    return null;
}

// Hangar promów: stanowisko obsługi promów, inaczej pierwszy hangar planu.
function wnetrze_hangar_promow(int $plan): ?int
{
    $w = wnetrze_wezly($plan);
    foreach ($w as $id => $x) if ($x['rodzaj'] === 'hangar' && mb_stripos($x['krotka'], 'prom') !== false) return $id;
    foreach ($w as $id => $x) if ($x['rodzaj'] === 'hangar') return $id;
    return null;
}

// Czas przejścia (s) między węzłami: kroki w górę do wspólnego przodka i w dół, ważone głębokością.
function wnetrze_czas_s(array $w, int $a, int $b): int
{
    if ($a === $b) return 0;
    $sciezka = function (int $id) use ($w): array {
        $s = [];
        while ($id && isset($w[$id])) { $s[] = $id; $id = (int) ($w[$id]['rodzic'] ?? 0); }
        return $s;
    };
    $pa = $sciezka($a); $pb = $sciezka($b);
    $wspolny = null;
    foreach ($pa as $id) if (in_array($id, $pb, true)) { $wspolny = $id; break; }
    $min = WNETRZE_ZAPAS_MIN;
    foreach ([$pa, $pb] as $s) {
        foreach ($s as $id) {
            if ($id === $wspolny) break;
            $min += WNETRZE_KROK_MIN[min(3, max(1, $w[$id]['glebokosc']))];
        }
    }
    return (int) round($min * WNETRZE_SKALA * 60);
}

// Pomieszczenie postaci teraz: [plan, pomieszczenie, przejście|null]. Przejście, które minęło, kończy się
// w karcie. Bez zapisu: kapitan (dowódca floty okrętu) na Mostku głównym, pozostali w korytarzu centralnym.
function wnetrze_postaci(string $uid, int $okretId, ?array $u = null): ?array
{
    $plan = wnetrze_plan_okretu($okretId);
    if (!$plan) return null;
    $w = wnetrze_wezly($plan);
    $zapis = wczytaj('karty', [])[$uid]['wnetrze'] ?? null;
    if ($zapis && (int) ($zapis['okret'] ?? 0) === $okretId && isset($w[(int) ($zapis['pomieszczenie'] ?? 0)])) {
        $p = $zapis['przejscie'] ?? null;
        if ($p && time() >= (int) $p['do'] && isset($w[(int) $p['cel']])) {
            $zapis = wnetrze_zapisz($uid, $okretId, (int) $p['cel']);
            $p = null;
        }
        return ['plan' => $plan, 'pomieszczenie' => (int) $zapis['pomieszczenie'], 'przejscie' => $p ?: null];
    }
    $u ??= array_values(array_filter(uzytkownicy(), fn($k) => (string) $k['id'] === $uid))[0] ?? null;
    $kapitan = $u && function_exists('wskazniki_kapitan') && wskazniki_kapitan('okret:' . $okretId, $u);
    $start = ($kapitan ? wnetrze_szukaj($w, 'Mostek główny') : wnetrze_szukaj($w, 'Główny korytarz centralny'))
        ?? array_key_first(array_filter($w, fn($x) => $x['glebokosc'] >= 2)) ?? $plan;
    return ['plan' => $plan, 'pomieszczenie' => $start, 'przejscie' => null];
}

function wnetrze_zapisz(string $uid, int $okretId, int $pomieszczenie, ?array $przejscie = null): array
{
    $zapis = ['okret' => $okretId, 'pomieszczenie' => $pomieszczenie, 'przejscie' => $przejscie];
    zmien('karty', function (&$k) use ($uid, $zapis) { $k[$uid]['wnetrze'] = $zapis; });
    return $zapis;
}

// Wyjście z promu do hangaru: postać staje w hangarze promów okrętu.
function wnetrze_do_hangaru(string $uid, int $okretId): void
{
    $plan = wnetrze_plan_okretu($okretId);
    if (!$plan) return;
    $h = wnetrze_hangar_promow($plan);
    if ($h) wnetrze_zapisz($uid, $okretId, $h);
}

// Postać może wsiąść do promu: okręt bez planu wnętrza — z całego pokładu; z planem — tylko z hangaru promów.
function wnetrze_przy_promie(string $uid, int $okretId): bool
{
    $st = wnetrze_postaci($uid, $okretId);
    if (!$st) return true;
    return !$st['przejscie'] && $st['pomieszczenie'] === wnetrze_hangar_promow($st['plan']);
}

// Dane do Nawigacji: gdzie jestem, trwające przejście, lista pomieszczeń (z czasem dojścia) i skróty.
function wnetrze_info(array $u, int $okretId): ?array
{
    $uid = (string) $u['id'];
    $st = wnetrze_postaci($uid, $okretId, $u);
    if (!$st) return null;
    $w = wnetrze_wezly($st['plan']);
    $tu = $w[$st['pomieszczenie']];
    $lista = [];
    foreach ($w as $id => $x) {
        if ($x['glebokosc'] < 2 || $id === $st['pomieszczenie']) continue;
        $lista[] = ['id' => $id, 'nazwa' => $x['krotka'], 'grupa' => $x['grupa'], 'okno' => $x['okno'],
            'min' => round(wnetrze_czas_s($w, $st['pomieszczenie'], $id) / 60, 1)];
    }
    $skroty = [];
    $hangar = wnetrze_hangar_promow($st['plan']);
    $mostek = wnetrze_szukaj($w, 'Mostek główny');
    if ($hangar && $hangar !== $st['pomieszczenie']) $skroty[] = ['id' => $hangar, 'nazwa' => 'Hangar promów', 'min' => round(wnetrze_czas_s($w, $st['pomieszczenie'], $hangar) / 60, 1)];
    if ($mostek && $mostek !== $st['pomieszczenie']) $skroty[] = ['id' => $mostek, 'nazwa' => 'Mostek', 'min' => round(wnetrze_czas_s($w, $st['pomieszczenie'], $mostek) / 60, 1)];
    $p = $st['przejscie'];
    return ['tu' => ['id' => $tu['id'], 'nazwa' => $tu['krotka'], 'grupa' => $tu['grupa'], 'okno' => $tu['okno']],
        'przejscie' => $p ? ['cel' => (int) $p['cel'], 'cel_nazwa' => $w[(int) $p['cel']]['krotka'] ?? '?', 'od' => (int) $p['od'], 'do' => (int) $p['do']] : null,
        'hangar_promow' => $hangar, 'pomieszczenia' => $lista, 'skroty' => $skroty];
}

// Widok z pomieszczenia (scena na pulpicie): rodzaj okna i nazwa pomieszczenia (w trakcie przejścia — korytarz).
function wnetrze_widok(string $uid, int $okretId): ?array
{
    $st = wnetrze_postaci($uid, $okretId);
    if (!$st) return null;
    $w = wnetrze_wezly($st['plan']);
    if ($st['przejscie']) return ['okno' => 'brak', 'nazwa' => 'w drodze: ' . ($w[(int) $st['przejscie']['cel']]['krotka'] ?? '?')];
    $x = $w[$st['pomieszczenie']];
    return ['okno' => $x['okno'], 'nazwa' => $x['krotka']];
}

function obsluz_poklad(string $akcja, bool $post): void
{
    if ($akcja !== 'poklad_idz') return;
    // Przejście do innego pomieszczenia okrętu (czas z planu wnętrza; odliczanie w Nawigacji).
    if (!$post) blad('Zła metoda.', 405);
    $u = wymagaj_logowania();
    $uid = (string) $u['id'];
    $gdzie = ruch_gdzie(karta($uid), ruch_lokacje());
    $okret = (int) ($gdzie['okret_id'] ?? 0);
    $st = $okret ? wnetrze_postaci($uid, $okret, $u) : null;
    if (!$st) blad('Ten okręt nie ma planu wnętrza.');
    $w = wnetrze_wezly($st['plan']);
    $cel = (int) (wejscie()['cel'] ?? 0);
    if (!isset($w[$cel]) || $w[$cel]['glebokosc'] < 2) blad('Nie ma takiego pomieszczenia.');
    // W trakcie przejścia zmiana celu liczy się od miejsca, z którego się wyszło.
    $od = $st['pomieszczenie'];
    if ($cel === $od) { wnetrze_zapisz($uid, $okret, $od); odpowiedz(['ok' => true, 'koniec' => time()]); }
    $czas = wnetrze_czas_s($w, $od, $cel);
    $teraz = time();
    wnetrze_zapisz($uid, $okret, $od, ['cel' => $cel, 'od' => $teraz, 'do' => $teraz + $czas]);
    odpowiedz(['ok' => true, 'koniec' => $teraz + $czas, 'cel' => $w[$cel]['krotka'], 'min' => round($czas / 60, 1)]);
}

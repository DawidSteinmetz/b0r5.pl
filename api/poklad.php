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

// ---------- dostęp według stopnia ----------
// Na okręcie wolno wejść wszędzie poza prywatnymi kwaterami wyższych stopni. Stopień z rangi konta (tekst),
// kwatery rozpoznawane po nazwie pomieszczenia. Kapitan okrętu i MG — bez ograniczeń.
const STOPNIE = [
    ['wielki admirał', 15], ['admirał floty', 14], ['wiceadmirał', 13], ['kontradmirał', 12], ['admirał', 13], ['moff', 14], ['komodor', 11],
    ['kapitan-porucznik', 7], ['komandor porucznik', 8], ['komandor podporucznik', 7], ['komandor', 9], ['kapitan', 10],
    ['starszy porucznik', 7], ['podporucznik', 5], ['porucznik', 6], ['chorąży', 4], ['starszy sierżant', 3], ['sierżant', 3], ['bosman', 3],
    ['kapral', 2], ['starszy marynarz', 1], ['marynarz', 1], ['szeregowy', 1], ['szturmowiec', 1],
];
const KWATERY_STOPNI = [
    ['kwatera kapitana', 10, 'kapitana'], ['kwatera dowódcy', 10, 'kapitana'], ['kwatera admirała', 12, 'admirała'], ['apartament admirała', 12, 'admirała'],
    ['kwatera adiutanta', 9, 'komandora'], ['kwatery oficerów sztabowych', 8, 'komandora porucznika'], ['kwatery starszych oficerów', 8, 'komandora porucznika'],
];

function stopien_rangi(string $ranga): int
{
    $r = mb_strtolower(trim($ranga));
    if ($r === '') return 0;
    foreach (STOPNIE as [$nazwa, $s]) if (str_contains($r, $nazwa)) return $s;
    return 0;
}

// [wymagany stopień, opis] dla pomieszczenia albo null (wstęp wolny).
function wnetrze_wymagany_stopien(string $nazwa): ?array
{
    $n = mb_strtolower($nazwa);
    foreach (KWATERY_STOPNI as [$wzor, $s, $kto]) if (str_contains($n, $wzor)) return [$s, $kto];
    return null;
}

// Czy postać może wejść do pomieszczenia (null — tak; tekst — powód odmowy).
function wnetrze_odmowa(array $u, int $okretId, array $wezel): ?string
{
    $wym = wnetrze_wymagany_stopien((string) $wezel['krotka']);
    if (!$wym || ($u['rola'] ?? '') === 'mg') return null;
    if (function_exists('wskazniki_kapitan') && wskazniki_kapitan('okret:' . $okretId, $u)) return null;
    if (stopien_rangi((string) ($u['ranga'] ?? '')) >= $wym[0]) return null;
    return $wezel['krotka'] . ' — prywatna kwatera, wstęp od stopnia ' . $wym[1] . '.';
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
        $odm = wnetrze_odmowa($u, $okretId, $x);
        $lista[] = ['id' => $id, 'nazwa' => $x['krotka'], 'grupa' => $x['grupa'], 'okno' => $x['okno'],
            'min' => round(wnetrze_czas_s($w, $st['pomieszczenie'], $id) / 60, 1)] + ($odm ? ['zamkniete' => $odm] : []);
    }
    $skroty = [];
    $hangar = wnetrze_hangar_promow($st['plan']);
    $mostek = wnetrze_szukaj($w, 'Mostek główny');
    if ($hangar && $hangar !== $st['pomieszczenie']) $skroty[] = ['id' => $hangar, 'nazwa' => 'Hangar promów', 'min' => round(wnetrze_czas_s($w, $st['pomieszczenie'], $hangar) / 60, 1)];
    if ($mostek && $mostek !== $st['pomieszczenie']) $skroty[] = ['id' => $mostek, 'nazwa' => 'Mostek', 'min' => round(wnetrze_czas_s($w, $st['pomieszczenie'], $mostek) / 60, 1)];
    $p = $st['przejscie'];
    return ['tu' => ['id' => $tu['id'], 'nazwa' => $tu['krotka'], 'grupa' => $tu['grupa'], 'okno' => $tu['okno']],
        'przejscie' => $p ? ['cel' => (int) $p['cel'], 'cel_nazwa' => $w[(int) $p['cel']]['krotka'] ?? '?', 'od' => (int) $p['od'], 'do' => (int) $p['do']] : null,
        'hangar_promow' => $hangar, 'pomieszczenia' => $lista, 'skroty' => $skroty,
        'inspekcja' => inspekcja_info($u, $okretId, $p ? null : $tu)];
}

// ---------- inspekcja załogi ----------
// Oficer (od chorążego; kapitan okrętu i MG zawsze) rozmawia z grupą załogi w pomieszczeniu: ok. 15 min, potem test
// Przywództwa (pula z karty EotE: Prezencja i ranga Przywództwa; bez karty — średnia). Wynik: 1–3 PD (do „PD zdobytych”
// karty EotE) i zmiana morale grupy na tym okręcie (sukces w górę, porażka w dół). Ta sama grupa raz na 24 h.
// Dane: datapad_dane → morale (okręt → grupa → 0–100), karta → inspekcja / inspekcja_wynik / inspekcje_ostatnie.
const INSPEKCJA_S = 900;
const INSPEKCJA_PRZERWA_S = 86400;
const INSPEKCJA_MIN_STOPIEN = 4;
const GRUPY_ZALOGI = [
    ['piloci', 'Piloci i obsługa hangaru', ['hangar', 'pilot', 'myśliwc', 'tie', 'promów', 'zatoka']],
    ['traktor', 'Operatorzy promieni ściągających', ['traktor', 'ściągając']],
    ['artylerzysci', 'Artylerzyści', ['uzbrojen', 'dział', 'turbolaser', 'torped', 'kierowania ogniem', 'baterii', 'wież']],
    ['technicy', 'Technicy maszynowni', ['maszynown', 'silnik', 'napęd', 'reaktor', 'energ', 'chłodz', 'rozdzieln', 'generator']],
    ['mostek', 'Załoga mostka', ['mostek', 'centrum', 'nawigac', 'łączno', 'sala odpraw', 'kontroli']],
    ['szturmowcy', 'Szturmowcy', ['szturmow', 'koszar', 'zbrojown']],
    ['medycy', 'Personel medyczny', ['medycz', 'szpital', 'lekarz', 'zabieg', 'operac', 'izba przyjęć']],
    ['straz', 'Straż więzienna', ['więzien', 'cele', 'zatrzyma', 'przesłuch']],
    ['marynarze', 'Marynarze i obsługa okrętu', ['mesa', 'stołów', 'kwater', 'sypial', 'magazyn', 'warsztat', 'korytarz']],
];
const INSPEKCJA_OPISY = [
    'sukces' => ['Załoga prostuje się, gdy wchodzisz. Kilka konkretnych pytań, krótka pochwała — widać, że to docenili.',
        'Wysłuchujesz meldunków, poprawiasz drobiazg w procedurze i zostawiasz ich z poczuciem, że ktoś na górze o nich pamięta.',
        'Rozmowa idzie gładko; najmłodszy z grupy odważa się zadać pytanie, a ty odpowiadasz bez wahania.'],
    'porazka' => ['Rozmowa się nie klei. Odpowiedzi są zdawkowe, a po twoim wyjściu słychać szepty.',
        'Twoje uwagi brzmią jak reprymenda, choć nie miały. Ktoś zaciska szczęki i patrzy w podłogę.',
        'Nie masz odpowiedzi na proste pytanie o przydziały — grupa zauważa to od razu.'],
];

function grupa_zalogi(string $nazwa): ?array
{
    $n = mb_strtolower($nazwa);
    foreach (GRUPY_ZALOGI as [$k, $opis, $wzory]) foreach ($wzory as $w) if (str_contains($n, $w)) return ['klucz' => $k, 'nazwa' => $opis];
    return null;
}

function morale_grupy(int $okret, string $grupa): int
{
    return (int) (wczytaj('morale', [])[(string) $okret][$grupa] ?? 60);
}

function inspekcja_uprawniona(array $u, int $okret): bool
{
    if (($u['rola'] ?? '') === 'mg') return true;
    if (function_exists('wskazniki_kapitan') && wskazniki_kapitan('okret:' . $okret, $u)) return true;
    return stopien_rangi((string) ($u['ranga'] ?? '')) >= INSPEKCJA_MIN_STOPIEN;
}

// Kości EotE (uproszczone ściany): zdolność (zielona), biegłość (żółta), trudność (fioletowa). [sukcesy, przewagi].
function kosc_rzut(string $rodzaj): array
{
    $sciany = [
        'z' => [[0, 0], [1, 0], [1, 0], [2, 0], [0, 1], [0, 1], [1, 1], [0, 2]],
        'b' => [[0, 0], [1, 0], [1, 0], [2, 0], [2, 0], [0, 1], [1, 1], [1, 1], [1, 1], [0, 2], [0, 2], [1, 0]],
        't' => [[0, 0], [-1, 0], [-2, 0], [0, -1], [0, -1], [0, -1], [0, -2], [-1, -1]],
    ][$rodzaj];
    return $sciany[random_int(0, count($sciany) - 1)];
}

function inspekcja_test(array $karta, int $trudnosc): array
{
    $e = $karta['eote'] ?? null;
    $cecha = (int) ($e['cechy']['prezencja'] ?? 2);
    $ranga = (int) ($e['umiejetnosci']['przywodztwo']['ranga'] ?? ($e ? 0 : 1));
    $b = min($cecha, $ranga); $z = max($cecha, $ranga) - $b;
    $s = 0; $p = 0;
    foreach (array_merge(array_fill(0, $z, 'z'), array_fill(0, $b, 'b'), array_fill(0, $trudnosc, 't')) as $k) {
        [$ds, $dp] = kosc_rzut($k); $s += $ds; $p += $dp;
    }
    return ['sukcesy' => $s, 'przewagi' => $p, 'pula' => "{$z} zdolności, {$b} biegłości, {$trudnosc} trudności"];
}

// Zakończenie inspekcji, która minęła: wynik, PD i morale (raz).
function inspekcja_dokoncz(string $uid): void
{
    $k = karta($uid);
    $i = $k['inspekcja'] ?? null;
    if (!$i || time() < (int) $i['do']) return;
    $morale = morale_grupy((int) $i['okret'], (string) $i['grupa']);
    $test = inspekcja_test($k, $morale < 30 ? 3 : 2);
    $ok = $test['sukcesy'] > 0;
    $zmiana = $ok ? min(20, 4 + 3 * $test['sukcesy'] + max(0, $test['przewagi'])) : -min(20, 4 + 3 * abs($test['sukcesy']) + max(0, -$test['przewagi']));
    $pd = $ok ? ($test['sukcesy'] >= 3 ? 3 : 2) : 1;
    zmien('morale', function (&$m) use ($i, $zmiana) {
        $o = (string) $i['okret']; $g = (string) $i['grupa'];
        $m[$o][$g] = max(0, min(100, (int) ($m[$o][$g] ?? 60) + $zmiana));
    });
    $opisy = INSPEKCJA_OPISY[$ok ? 'sukces' : 'porazka'];
    $wynik = ['grupa' => $i['grupa_nazwa'], 'pomieszczenie' => $i['pomieszczenie_nazwa'], 'sukces' => $ok, 'opis' => $opisy[random_int(0, count($opisy) - 1)],
        'morale_zmiana' => $zmiana, 'morale' => max(0, min(100, $morale + $zmiana)), 'pd' => $pd, 'test' => $test, 'czas' => (int) $i['do']];
    $doKarty = eote_gracz_dodaj_pd($uid, $pd);
    zmien('karty', function (&$karty) use ($uid, $i, $wynik, $pd, $doKarty) {
        unset($karty[$uid]['inspekcja']);
        $karty[$uid]['inspekcja_wynik'] = $wynik;
        $karty[$uid]['inspekcje_ostatnie'][$i['okret'] . ':' . $i['grupa']] = (int) $i['do'];
        if (!$doKarty) $karty[$uid]['pd_bez_karty'] = (int) ($karty[$uid]['pd_bez_karty'] ?? 0) + $pd;
    });
}

// PD do „PD zdobytych” karty EotE gracza: karta w tabelach SQL (eote_sql_*), a bez nich — w karcie postaci.
// Zwraca false, gdy postać nie ma karty EotE (PD trafiają wtedy na bok: pd_bez_karty).
function eote_gracz_dodaj_pd(string $uid, int $pd): bool
{
    if (function_exists('eote_gracz_odczytaj') && function_exists('eote_sql_zapisz')
        && tabela_sql_dostepna('eote_cv_karty') && tabela_sql_dostepna('eote_cv_wartosci')) {
        $legacy = wczytaj('karty', [])[$uid]['eote'] ?? null;
        $e = eote_gracz_odczytaj($uid, is_array($legacy) ? ['eote' => $legacy] : null);
        if (!is_array($e)) return false;
        $pdKarty = (array) ($e['pd'] ?? []);
        $pdKarty['zdobyte'] = (int) ($pdKarty['zdobyte'] ?? 0) + $pd;
        $e['pd'] = $pdKarty;
        eote_sql_zapisz('legacy_uid', $uid, $e);
        return true;
    }
    $jest = false;
    zmien('karty', function (&$karty) use ($uid, $pd, &$jest) {
        if (!isset($karty[$uid]['eote']) || !is_array($karty[$uid]['eote'])) return;
        $karty[$uid]['eote']['pd']['zdobyte'] = (int) ($karty[$uid]['eote']['pd']['zdobyte'] ?? 0) + $pd;
        $jest = true;
    });
    return $jest;
}

// Stan inspekcji do Nawigacji: trwa / wynik ostatniej / czy można w obecnym pomieszczeniu (grupa, morale, przerwa).
function inspekcja_info(array $u, int $okret, ?array $tu): ?array
{
    $uid = (string) $u['id'];
    inspekcja_dokoncz($uid);
    $k = karta($uid);
    $info = ['trwa' => null, 'wynik' => $k['inspekcja_wynik'] ?? null, 'mozna' => false];
    if (!empty($k['inspekcja'])) {
        $i = $k['inspekcja'];
        $info['trwa'] = ['grupa' => $i['grupa_nazwa'], 'od' => (int) $i['od'], 'do' => (int) $i['do']];
        return $info;
    }
    $g = $tu ? grupa_zalogi((string) $tu['krotka']) : null;
    if (!$g) return $info + ['powod' => $tu ? 'W tym pomieszczeniu nie ma załogi do inspekcji.' : null];
    $info['grupa'] = $g['nazwa'];
    if (!inspekcja_uprawniona($u, $okret)) return $info + ['powod' => 'Inspekcję prowadzi oficer (od chorążego).'];
    $info['morale'] = morale_grupy($okret, $g['klucz']);
    $ostatnio = (int) ($k['inspekcje_ostatnie'][$okret . ':' . $g['klucz']] ?? 0);
    if ($ostatnio && time() - $ostatnio < INSPEKCJA_PRZERWA_S) return $info + ['powod' => 'Z tą grupą rozmawiałeś w ciągu ostatniej doby.', 'znowu' => $ostatnio + INSPEKCJA_PRZERWA_S];
    $info['mozna'] = true;
    return $info;
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
    if ($akcja === 'inspekcja_start' || $akcja === 'inspekcja_zamknij') { obsluz_inspekcje($akcja, $post); return; }
    if ($akcja === 'mg_morale') { wymagaj_mg(); odpowiedz(['morale' => morale_lista()]); }
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
    if ($cel !== $st['pomieszczenie'] && ($odm = wnetrze_odmowa($u, $okret, $w[$cel]))) blad($odm, 403);
    // W trakcie przejścia zmiana celu liczy się od miejsca, z którego się wyszło.
    $od = $st['pomieszczenie'];
    if ($cel === $od) { wnetrze_zapisz($uid, $okret, $od); odpowiedz(['ok' => true, 'koniec' => time()]); }
    $czas = wnetrze_czas_s($w, $od, $cel);
    $teraz = time();
    // Wyjście z pomieszczenia przerywa trwającą inspekcję.
    zmien('karty', function (&$k) use ($uid) { unset($k[$uid]['inspekcja']); });
    wnetrze_zapisz($uid, $okret, $od, ['cel' => $cel, 'od' => $teraz, 'do' => $teraz + $czas]);
    odpowiedz(['ok' => true, 'koniec' => $teraz + $czas, 'cel' => $w[$cel]['krotka'], 'min' => round($czas / 60, 1)]);
}

// Rozpoczęcie inspekcji w obecnym pomieszczeniu; „zamknij” — schowanie wyniku ostatniej.
function obsluz_inspekcje(string $akcja, bool $post): void
{
    if (!$post) blad('Zła metoda.', 405);
    $u = wymagaj_logowania();
    $uid = (string) $u['id'];
    if ($akcja === 'inspekcja_zamknij') {
        zmien('karty', function (&$k) use ($uid) { unset($k[$uid]['inspekcja_wynik']); });
        odpowiedz(['ok' => true]);
    }
    $gdzie = ruch_gdzie(karta($uid), ruch_lokacje());
    $okret = (int) ($gdzie['okret_id'] ?? 0);
    $st = $okret ? wnetrze_postaci($uid, $okret, $u) : null;
    if (!$st) blad('Inspekcję prowadzi się na pokładzie okrętu z planem wnętrza.');
    if ($st['przejscie']) blad('Najpierw dojdź na miejsce.');
    $w = wnetrze_wezly($st['plan']);
    $tu = $w[$st['pomieszczenie']];
    $info = inspekcja_info($u, $okret, $tu);
    if ($info['trwa']) blad('Inspekcja już trwa.');
    if (!$info['mozna']) blad($info['powod'] ?? 'Tu nie ma kogo wizytować.');
    $g = grupa_zalogi((string) $tu['krotka']);
    $teraz = time();
    $i = ['okret' => $okret, 'grupa' => $g['klucz'], 'grupa_nazwa' => $g['nazwa'], 'pomieszczenie' => $st['pomieszczenie'],
        'pomieszczenie_nazwa' => $tu['krotka'], 'od' => $teraz, 'do' => $teraz + INSPEKCJA_S];
    zmien('karty', function (&$k) use ($uid, $i) { $k[$uid]['inspekcja'] = $i; unset($k[$uid]['inspekcja_wynik']); });
    odpowiedz(['ok' => true, 'koniec' => $i['do'], 'grupa' => $g['nazwa']]);
}

// Morale załóg wszystkich okrętów (panel MG).
function morale_lista(): array
{
    $m = wczytaj('morale', []);
    $nazwy = [];
    if ($m && tabela_sql_dostepna('mapa_obiekty')) {
        $ids = array_map('intval', array_keys($m));
        $q = polaczenie_bazy()->prepare('SELECT id, nazwa FROM mapa_obiekty WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $q->execute($ids);
        $nazwy = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $grupy = array_column(GRUPY_ZALOGI, 1, 0);
    $wynik = [];
    foreach ($m as $okret => $g) foreach ($g as $k => $v) $wynik[] = ['okret' => $nazwy[$okret] ?? "#{$okret}", 'grupa' => $grupy[$k] ?? $k, 'morale' => (int) $v];
    return $wynik;
}

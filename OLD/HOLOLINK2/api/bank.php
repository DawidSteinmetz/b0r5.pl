<?php
// Bank: konta postaci, przelewy, stali odbiorcy, stałe miesięczne dochody (ustawiane przez MG).
//
// Wszystko leży w jednym pliku danych „bank”, zmienianym pod jedną blokadą. Dzięki temu przelew
// (obciążenie jednego konta i uznanie drugiego) nie może się wykonać tylko w połowie.
//
// Stałe dochody wypłacamy „leniwie”: przy każdym wejściu do banku sprawdzamy, czy minął dzień
// wypłaty w miesiącu, i dopisujemy zaległe wypłaty. Serwer nie potrzebuje crona.

declare(strict_types=1);

const BANK_MAX_KWOTA = 1000000000;
const BANK_HISTORIA = 60;

function bank_pusty(): array
{
    return ['konta' => [], 'transakcje' => [], 'odbiorcy' => [], 'dochody' => []];
}

function bank_nowy_numer(array $bank): string
{
    $zajete = array_column($bank['konta'], 'numer');
    do {
        $n = sprintf('%04d-%04d-%04d', random_int(1000, 9999), random_int(0, 9999), random_int(0, 9999));
    } while (in_array($n, $zajete, true));
    return $n;
}

// Konto zakładamy przy pierwszym wejściu postaci do banku.
function bank_konto(array &$bank, string $uid): array
{
    if (!isset($bank['konta'][$uid])) {
        $bank['konta'][$uid] = ['numer' => bank_nowy_numer($bank), 'saldo' => 0, 'zalozono' => date('c')];
    }
    return $bank['konta'][$uid];
}

function bank_uid_po_numerze(array $bank, string $numer): ?string
{
    foreach ($bank['konta'] as $uid => $k) {
        if ($k['numer'] === $numer) {
            return $uid;
        }
    }
    return null;
}

function bank_postacie(): array
{
    $p = [];
    foreach (uzytkownicy() as $u) {
        $p[$u['id']] = $u['postac'];
    }
    return $p;
}

// Właściciele rachunków: postacie (klucz = id użytkownika) i firmy (klucz = „f:” + id firmy).
function bank_wlasciciele(): array
{
    $w = bank_postacie();
    foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
        $w['f:' . $f['id']] = $f['nazwa'];
    }
    return $w;
}

// Przelew między rachunkami. Zwraca 'ok', 'brak' (nie ma odbiorcy), 'wlasne' albo 'srodki'.
function bank_przelej(array &$bank, string $od, string $numer, int $kwota, string $tytul, array $nazwy): string
{
    $nadawca = bank_konto($bank, $od);
    $do = bank_uid_po_numerze($bank, $numer);
    if (!$do || !isset($nazwy[$do])) return 'brak';
    if ($do === $od) return 'wlasne';
    if ($nadawca['saldo'] < $kwota) return 'srodki';
    $bank['konta'][$od]['saldo'] -= $kwota;
    $bank['konta'][$do]['saldo'] += $kwota;
    bank_zapisz_transakcje($bank, [
        'typ' => 'przelew',
        'od' => $od, 'od_nazwa' => $nazwy[$od] ?? '?', 'od_numer' => $nadawca['numer'],
        'do' => $do, 'do_nazwa' => $nazwy[$do], 'do_numer' => $numer,
        'kwota' => $kwota,
        'tytul' => $tytul,
    ]);
    return 'ok';
}

// Wypłata z rachunku „na zewnątrz” (zakup, gotówka dla kogoś bez rachunku). Zwraca 'ok' albo 'srodki'.
function bank_obciaz(array &$bank, string $od, int $kwota, string $odbiorca, string $tytul, array $nazwy): string
{
    $konto = bank_konto($bank, $od);
    if ($konto['saldo'] < $kwota) return 'srodki';
    $bank['konta'][$od]['saldo'] -= $kwota;
    bank_zapisz_transakcje($bank, [
        'typ' => 'wyplata',
        'od' => $od, 'od_nazwa' => $nazwy[$od] ?? '?', 'od_numer' => $konto['numer'],
        'do' => null, 'do_nazwa' => $odbiorca, 'do_numer' => '',
        'kwota' => $kwota,
        'tytul' => $tytul,
    ]);
    return 'ok';
}

// Wpływ „z zewnątrz” (np. sprzedaż na giełdzie portowej).
function bank_uznaj(array &$bank, string $do, int $kwota, string $nadawca, string $tytul, array $nazwy): void
{
    $konto = bank_konto($bank, $do);
    $bank['konta'][$do]['saldo'] += $kwota;
    bank_zapisz_transakcje($bank, [
        'typ' => 'wplata',
        'od' => null, 'od_nazwa' => $nadawca, 'od_numer' => '',
        'do' => $do, 'do_nazwa' => $nazwy[$do] ?? '?', 'do_numer' => $konto['numer'],
        'kwota' => $kwota,
        'tytul' => $tytul,
    ]);
}

// Saldo, numer i historia jednego rachunku (np. firmy).
function bank_wyciag(array &$bank, string $klucz, int $ile = BANK_HISTORIA): array
{
    $konto = bank_konto($bank, $klucz);
    $t = array_values(array_filter($bank['transakcje'], fn($x) => $x['od'] === $klucz || $x['do'] === $klucz));
    usort($t, fn($a, $b) => strtotime($b['czas']) <=> strtotime($a['czas']));
    $t = array_map(fn($x) => $x + ['kierunek' => $x['do'] === $klucz ? 'wplyw' : 'wyplyw'], array_slice($t, 0, $ile));
    return ['konto' => $konto, 'transakcje' => $t];
}

function bank_blad_przelewu(string $wynik): void
{
    if ($wynik === 'brak') blad('Rachunek o tym numerze nie istnieje.', 404);
    if ($wynik === 'wlasne') blad('Nie można wysłać przelewu na ten sam rachunek.');
    if ($wynik === 'srodki') blad('Niewystarczające środki na rachunku.');
}

function bank_zapisz_transakcje(array &$bank, array $t): void
{
    // Podany „czas” (np. dzień należnej wypłaty) ma pierwszeństwo przed chwilą zapisu.
    $bank['transakcje'][] = $t + ['id' => id(), 'czas' => date('c')];
}

// Wypłata zaległych stałych dochodów, aż do bieżącego miesiąca.
function bank_rozlicz_dochody(array &$bank): void
{
    $postacie = bank_wlasciciele();
    $teraz = new DateTimeImmutable('now');
    foreach ($bank['dochody'] as &$d) {
        if (!isset($postacie[$d['uid']])) {
            continue; // konto postaci usunięte
        }
        $dzien = max(1, min(28, (int) $d['dzien']));
        $miesiac = DateTimeImmutable::createFromFormat('!Y-m', $d['ostatnio'])->modify('+1 month');
        // Bezpiecznik: najwyżej 24 zaległe wypłaty naraz.
        for ($i = 0; $i < 24; $i++) {
            $termin = $miesiac->setDate((int) $miesiac->format('Y'), (int) $miesiac->format('m'), $dzien);
            if ($termin > $teraz) {
                break;
            }
            $konto = bank_konto($bank, $d['uid']);
            $bank['konta'][$d['uid']]['saldo'] += (int) $d['kwota'];
            bank_zapisz_transakcje($bank, [
                'typ' => 'dochod',
                'od' => null, 'od_nazwa' => $d['zrodlo'] ?: 'Wpłata cykliczna', 'od_numer' => '',
                'do' => $d['uid'], 'do_nazwa' => $postacie[$d['uid']], 'do_numer' => $konto['numer'],
                'kwota' => (int) $d['kwota'],
                'tytul' => $d['tytul'],
                'czas' => $termin->format('c'),
            ]);
            $d['ostatnio'] = $miesiac->format('Y-m');
            $miesiac = $miesiac->modify('+1 month');
        }
    }
    unset($d);
    // Historia nie rośnie bez końca: trzymamy ostatnie 5000 operacji w całym banku.
    if (count($bank['transakcje']) > 5000) {
        $bank['transakcje'] = array_slice($bank['transakcje'], -5000);
    }
}

function bank_kwota($v): int
{
    $k = filter_var($v, FILTER_VALIDATE_INT);
    if ($k === false || abs($k) > BANK_MAX_KWOTA) {
        blad('Nieprawidłowa kwota.');
    }
    return $k;
}

function bank_numer(string $v): string
{
    $cyfry = preg_replace('/\D/', '', $v);
    if (strlen($cyfry) !== 12) {
        blad('Numer rachunku ma 12 cyfr.');
    }
    return substr($cyfry, 0, 4) . '-' . substr($cyfry, 4, 4) . '-' . substr($cyfry, 8, 4);
}

// Obsługuje akcje banku (i kończy żądanie). Dla innych akcji nic nie robi.
function obsluz_bank(string $akcja, bool $post): void
{
    switch ($akcja) {

        case 'bank':
            $u = wymagaj_logowania();
            $wynik = zmien('bank', function (&$bank) use ($u) {
                bank_rozlicz_dochody($bank);
                $konto = bank_konto($bank, $u['id']);
                $moje = array_values(array_filter($bank['transakcje'], fn($t) => $t['od'] === $u['id'] || $t['do'] === $u['id']));
                usort($moje, fn($a, $b) => strtotime($b['czas']) <=> strtotime($a['czas']));
                $moje = array_slice($moje, 0, BANK_HISTORIA);
                $moje = array_map(fn($t) => $t + ['kierunek' => $t['do'] === $u['id'] ? 'wplyw' : 'wyplyw'], $moje);
                return ['konto' => $konto, 'transakcje' => $moje, 'odbiorcy' => $bank['odbiorcy'][$u['id']] ?? []];
            }, bank_pusty());
            odpowiedz($wynik);

        case 'bank_sprawdz':
            $u = wymagaj_logowania();
            $numer = bank_numer((string) ($_GET['n'] ?? ''));
            $bank = wczytaj('bank', bank_pusty());
            $uid = bank_uid_po_numerze($bank, $numer);
            $nazwy = bank_wlasciciele();
            if (!$uid || !isset($nazwy[$uid])) blad('Rachunek o tym numerze nie istnieje.', 404);
            odpowiedz(['numer' => $numer, 'postac' => $nazwy[$uid], 'wlasny' => $uid === $u['id']]);

        case 'bank_przelew':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $w = wejscie();
            $numer = bank_numer((string) ($w['numer'] ?? ''));
            $kwota = bank_kwota($w['kwota'] ?? null);
            $tytul = pole('tytul', 140);
            if ($kwota <= 0) blad('Kwota musi być większa od zera.');
            $postacie = bank_wlasciciele();
            $wynik = zmien('bank', function (&$bank) use ($u, $numer, $kwota, $tytul, $postacie, $w) {
                bank_rozlicz_dochody($bank);
                $wynik = bank_przelej($bank, $u['id'], $numer, $kwota, $tytul, $postacie);
                if ($wynik !== 'ok') return $wynik;
                $uid = bank_uid_po_numerze($bank, $numer);
                // Opcjonalnie od razu zapisz odbiorcę na liście stałych.
                if (!empty($w['zapisz'])) {
                    $lista = $bank['odbiorcy'][$u['id']] ?? [];
                    if (!in_array($numer, array_column($lista, 'numer'), true)) {
                        $nazwa = mb_substr(trim((string) ($w['nazwa'] ?? '')), 0, 60) ?: $postacie[$uid];
                        $lista[] = ['numer' => $numer, 'nazwa' => $nazwa];
                        $bank['odbiorcy'][$u['id']] = $lista;
                    }
                }
                return 'ok';
            }, bank_pusty());
            if ($wynik === 'brak') blad('Rachunek o tym numerze nie istnieje.', 404);
            if ($wynik === 'wlasne') blad('Nie można wysłać przelewu na własny rachunek.');
            if ($wynik === 'srodki') blad('Niewystarczające środki na rachunku.');
            odpowiedz(['ok' => true]);

        case 'bank_odbiorca_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $numer = bank_numer(pole('numer', 20));
            $nazwa = pole('nazwa', 60);
            $postacie = bank_wlasciciele();
            $wynik = zmien('bank', function (&$bank) use ($u, $numer, $nazwa, $postacie) {
                $uid = bank_uid_po_numerze($bank, $numer);
                if (!$uid || !isset($postacie[$uid])) return 'brak';
                if ($uid === $u['id']) return 'wlasne';
                $lista = array_values(array_filter($bank['odbiorcy'][$u['id']] ?? [], fn($o) => $o['numer'] !== $numer));
                $lista[] = ['numer' => $numer, 'nazwa' => $nazwa !== '' ? $nazwa : $postacie[$uid]];
                usort($lista, fn($a, $b) => strcasecmp($a['nazwa'], $b['nazwa']));
                $bank['odbiorcy'][$u['id']] = $lista;
                return 'ok';
            }, bank_pusty());
            if ($wynik === 'brak') blad('Rachunek o tym numerze nie istnieje.', 404);
            if ($wynik === 'wlasne') blad('To Twój własny rachunek.');
            odpowiedz(['ok' => true]);

        case 'bank_odbiorca_usun':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $numer = pole('numer', 20);
            zmien('bank', function (&$bank) use ($u, $numer) {
                $bank['odbiorcy'][$u['id']] = array_values(array_filter($bank['odbiorcy'][$u['id']] ?? [], fn($o) => $o['numer'] !== $numer));
            }, bank_pusty());
            odpowiedz(['ok' => true]);

        // ----- panel MG -----

        case 'mg_bank':
            wymagaj_mg();
            $us = uzytkownicy();
            $wynik = zmien('bank', function (&$bank) use ($us) {
                bank_rozlicz_dochody($bank);
                $konta = [];
                foreach ($us as $x) {
                    if (($x['rola'] ?? '') === 'mg') continue;
                    $k = bank_konto($bank, $x['id']);
                    $konta[] = ['uid' => $x['id'], 'postac' => $x['postac'], 'frakcja' => $x['frakcja'], 'numer' => $k['numer'], 'saldo' => $k['saldo']];
                }
                // Rachunki firm (klucz „f:” + id firmy).
                foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
                    $k = bank_konto($bank, 'f:' . $f['id']);
                    $konta[] = ['uid' => 'f:' . $f['id'], 'postac' => $f['nazwa'], 'frakcja' => 'firma', 'numer' => $k['numer'], 'saldo' => $k['saldo']];
                }
                return [
                    'konta' => $konta,
                    'dochody' => $bank['dochody'],
                    'transakcje' => (function ($t) {
                        usort($t, fn($a, $b) => strtotime($b['czas']) <=> strtotime($a['czas']));
                        return array_slice($t, 0, 200);
                    })($bank['transakcje']),
                ];
            }, bank_pusty());
            odpowiedz($wynik);

        case 'mg_bank_korekta':
            // Jednorazowa wpłata lub obciążenie (nagroda, kara, łapówka od NPC...).
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $uid = pole('uid', 32);
            $kwota = bank_kwota($w['kwota'] ?? null);
            if ($kwota === 0) blad('Kwota nie może być zerem.');
            $postacie = bank_wlasciciele();
            if (!isset($postacie[$uid])) blad('Nie ma takiego rachunku.', 404);
            $zrodlo = pole('zrodlo', 80);
            $tytul = pole('tytul', 140);
            zmien('bank', function (&$bank) use ($uid, $kwota, $zrodlo, $tytul, $postacie) {
                $konto = bank_konto($bank, $uid);
                $bank['konta'][$uid]['saldo'] += $kwota;
                bank_zapisz_transakcje($bank, [
                    'typ' => 'korekta',
                    'od' => $kwota > 0 ? null : $uid, 'od_nazwa' => $kwota > 0 ? ($zrodlo ?: 'Operacja bankowa') : $postacie[$uid], 'od_numer' => $kwota > 0 ? '' : $konto['numer'],
                    'do' => $kwota > 0 ? $uid : null, 'do_nazwa' => $kwota > 0 ? $postacie[$uid] : ($zrodlo ?: 'Operacja bankowa'), 'do_numer' => $kwota > 0 ? $konto['numer'] : '',
                    'kwota' => abs($kwota),
                    'tytul' => $tytul,
                ]);
            }, bank_pusty());
            odpowiedz(['ok' => true]);

        case 'mg_bank_dochod_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $uid = pole('uid', 32);
            if (!isset(bank_wlasciciele()[$uid])) blad('Nie ma takiego rachunku.', 404);
            $kwota = bank_kwota($w['kwota'] ?? null);
            if ($kwota === 0) blad('Kwota nie może być zerem.');
            $dzien = max(1, min(28, (int) ($w['dzien'] ?? 1)));
            $did = pole('id', 32);
            $d = [
                'uid' => $uid,
                'kwota' => $kwota,
                'dzien' => $dzien,
                'zrodlo' => pole('zrodlo', 80),
                'tytul' => pole('tytul', 140),
            ];
            zmien('bank', function (&$bank) use ($d, $did) {
                foreach ($bank['dochody'] as &$x) {
                    if ($x['id'] === $did) {
                        $x = array_merge($x, $d);
                        return;
                    }
                }
                unset($x);
                // Nowy dochód: pierwsza wypłata w najbliższym dniu wypłaty (także w tym miesiącu,
                // jeśli ten dzień jeszcze nie minął).
                $teraz = new DateTimeImmutable('now');
                $ostatnio = (int) $teraz->format('j') >= $d['dzien']
                    ? $teraz->format('Y-m')
                    : $teraz->modify('first day of last month')->format('Y-m');
                $bank['dochody'][] = ['id' => id(), 'ostatnio' => $ostatnio] + $d;
            }, bank_pusty());
            odpowiedz(['ok' => true]);

        case 'mg_bank_dochod_usun':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $did = pole('id', 32);
            zmien('bank', function (&$bank) use ($did) {
                $bank['dochody'] = array_values(array_filter($bank['dochody'], fn($x) => $x['id'] !== $did));
            }, bank_pusty());
            odpowiedz(['ok' => true]);
    }
}

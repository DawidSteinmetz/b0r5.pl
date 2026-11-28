<?php
// Profil „komórka” (np. komórka Rebelii). Ma wszystko, co firma przewozowa (flota, załoga,
// magazyn, legalne kursy), a do tego:
//   - misje: wysłanie statków i ludzi na akcję; jedna misja = jedna sesja z MG,
//     rozliczana ręcznie przez MG (straty statków i ludzi, łup, raport);
// Budynki, testy ukrytych statystyk i rekrutacja są wspólne dla wszystkich profili (firmy.php).

declare(strict_types=1);

const STATUSY_MISJI = ['szkic', 'zgloszona', 'zatwierdzona', 'rozliczona', 'odrzucona'];

function komorka_oczysc_misje(array $w, array $dane): array
{
    $statki = array_column($dane['flota'], 'id');
    $osoby = array_column($dane['zaloga'], 'id');
    $termin = (string) ($w['termin'] ?? '');
    return [
        'id' => mb_substr((string) ($w['id'] ?? ''), 0, 32),
        'nazwa' => mb_substr(trim((string) ($w['nazwa'] ?? '')), 0, 80) ?: 'Misja bez nazwy',
        'cel' => mb_substr(trim((string) ($w['cel'] ?? '')), 0, 2000),
        'przykrywka' => mb_substr(trim((string) ($w['przykrywka'] ?? '')), 0, 1000),
        'planeta' => mb_substr((string) ($w['planeta'] ?? ''), 0, 32),
        'termin' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $termin) ? $termin : '',
        'statki' => array_values(array_intersect(array_unique((array) ($w['statki'] ?? [])), $statki)),
        'zaloga' => array_values(array_intersect(array_unique((array) ($w['zaloga'] ?? [])), $osoby)),
    ];
}

function komorka_sprawdz_misje(array $m, array $dane): array
{
    $p = [];
    if (!$m['statki'] && !$m['zaloga']) $p[] = 'Wybierz statki albo ludzi, których wysyłasz.';
    if ($m['termin'] === '') $p[] = 'Podaj termin akcji.';
    if (trim($m['cel']) === '') $p[] = 'Opisz cel misji.';
    $planety = array_column(katalog()['planety'], 'id');
    if ($m['planeta'] !== '' && !in_array($m['planeta'], $planety, true)) $p[] = 'Nieznana planeta.';
    foreach ($dane['flota'] as $s) {
        if (in_array($s['id'], $m['statki'], true) && ($s['stan'] ?? 'sprawny') !== 'sprawny') {
            $p[] = "Statek {$s['nazwa']} nie jest gotowy ({$s['stan']}).";
        }
    }
    foreach ($dane['zaloga'] as $z) {
        if (in_array($z['id'], $m['zaloga'], true) && ($z['stan'] ?? 'zdrowy') !== 'zdrowy') {
            $p[] = "{$z['imie']} nie może lecieć ({$z['stan']}).";
        }
    }
    foreach ($dane['szlaki'] as $sz) {
        if (in_array($sz['statek'], $m['statki'], true) && in_array($sz['status'], ['zgloszony', 'w_realizacji'], true)) {
            $p[] = 'Jeden ze statków ma zgłoszony lub trwający kurs handlowy.';
        }
    }
    foreach ($dane['misje'] as $x) {
        if ($x['id'] === $m['id'] || !in_array($x['status'], ['zgloszona', 'zatwierdzona'], true)) continue;
        if (array_intersect($x['statki'], $m['statki']) || array_intersect($x['zaloga'], $m['zaloga'])) {
            $p[] = "Część sił jest już przydzielona do misji „{$x['nazwa']}”.";
        }
    }
    return $p;
}

// Ustawia stan statków i ludzi biorących udział w misji.
function komorka_stan_sil(array &$dane, array $misja, string $stan_statku, string $stan_osoby): void
{
    foreach ($dane['flota'] as &$s) {
        if (in_array($s['id'], $misja['statki'], true)) $s['stan'] = $stan_statku;
    }
    unset($s);
    foreach ($dane['zaloga'] as &$z) {
        if (in_array($z['id'], $misja['zaloga'], true)) $z['stan'] = $stan_osoby;
    }
}

function komorka_operacja(array &$firma, string $op, array $w, array $u, bool $moze): array
{
    $dane = &$firma['dane'];
    if (!$moze) blad('Twoje uprawnienia pozwalają tylko na podgląd.', 403);

    switch ($op) {
        case 'misja_zapisz':
        case 'misja_zglos':
            $m = komorka_oczysc_misje($w['misja'] ?? [], $dane);
            $stara = null;
            foreach ($dane['misje'] as $x) {
                if ($x['id'] === $m['id']) $stara = $x;
            }
            if ($stara && !in_array($stara['status'], ['szkic', 'odrzucona'], true)) blad('Zgłoszonej misji nie można już zmieniać.');
            $problemy = komorka_sprawdz_misje($m, $dane);
            if ($op === 'misja_zglos' && $problemy) odpowiedz(['blad' => 'Rozkaz misji jest niekompletny.', 'problemy' => $problemy], 400);
            $m['id'] = $m['id'] ?: id();
            $m['status'] = $op === 'misja_zglos' ? 'zgloszona' : 'szkic';
            $m['autor'] = $u['postac'];
            $m['zmieniono'] = date('c');
            $m['uwagi'] = $stara['uwagi'] ?? '';
            $dane['misje'] = array_values(array_filter($dane['misje'], fn($x) => $x['id'] !== $m['id']));
            $dane['misje'][] = $m;
            return ['ok' => true, 'id' => $m['id'], 'problemy' => $problemy];

        case 'misja_usun':
            $mid = (string) ($w['id'] ?? '');
            foreach ($dane['misje'] as $x) {
                if ($x['id'] === $mid && in_array($x['status'], ['zgloszona', 'zatwierdzona'], true)) blad('Misji w toku nie można usunąć.');
            }
            $dane['misje'] = array_values(array_filter($dane['misje'], fn($x) => $x['id'] !== $mid));
            return ['ok' => true];

    }
    // Reszta (statki, przydziały, kursy handlowe) działa jak w firmie przewozowej.
    return transport_operacja($firma, $op, $w, $u, $moze);
}

function obsluz_komorka(string $akcja, bool $post): void
{
    switch ($akcja) {

        // MG: zatwierdzenie misji (siły „na misji”) albo odrzucenie z uwagą.
        case 'mg_misja_status':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $status = (string) ($w['status'] ?? '');
            if (!in_array($status, ['zatwierdzona', 'odrzucona'], true)) blad('Nieprawidłowy status.');
            zmien('firmy', function (&$wszystko) use ($w, $status) {
                foreach ($wszystko['firmy'] as &$f) {
                    if ($f['id'] !== ($w['firma'] ?? '')) continue;
                    foreach ($f['dane']['misje'] as &$m) {
                        if ($m['id'] !== ($w['misja'] ?? '')) continue;
                        $dozwolone = ['zgloszona' => ['zatwierdzona', 'odrzucona'], 'zatwierdzona' => ['odrzucona']];
                        if (!in_array($status, $dozwolone[$m['status']] ?? [], true)) blad('Tej zmiany statusu nie można wykonać.');
                        if ($status === 'zatwierdzona') {
                            $problemy = komorka_sprawdz_misje($m, $f['dane']);
                            if ($problemy) blad('Misja nie zgadza się z obecnym stanem: ' . implode(' ', $problemy));
                            komorka_stan_sil($f['dane'], $m, 'na misji', 'na misji');
                        } elseif ($m['status'] === 'zatwierdzona') {
                            komorka_stan_sil($f['dane'], $m, 'sprawny', 'zdrowy'); // odwołana przed akcją
                        }
                        $m['status'] = $status;
                        $m['uwagi'] = mb_substr(trim((string) ($w['uwagi'] ?? '')), 0, 500);
                        $m['zmieniono'] = date('c');
                        return;
                    }
                }
                blad('Nie ma takiej misji.', 404);
            }, firmy_puste());
            odpowiedz(['ok' => true]);

        // MG: ręczne rozliczenie po sesji.
        //   statki: { id: 'sprawny' | 'uszkodzony' | 'zniszczony' | 'przejety' }
        //   zaloga: { id: 'zdrowy' | 'ranny' | 'zginal' | 'spalony' | 'zatrzymany' }
        //   lup:    [ { planeta, towar, ilosc } ]   ilość ujemna = strata w magazynie
        //   wraca_na: planeta, na której statki kończą misję (opcjonalnie)
        //   raport: tekst widoczny dla graczy
        case 'mg_misja_rozlicz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $kat = katalog();
            $lup = [];
            foreach ((array) ($w['lup'] ?? []) as $l) {
                $il = (int) ($l['ilosc'] ?? 0);
                if ($il === 0) continue;
                $lup[] = [
                    'planeta' => katalog_id($kat['planety'], (string) ($l['planeta'] ?? ''), 'planeta'),
                    'towar' => katalog_id($kat['towary'], (string) ($l['towar'] ?? ''), 'towar'),
                    'ilosc' => $il,
                ];
            }
            // Gdzie statki kończą misję (puste = tam, skąd wyleciały).
            $wraca_na = ($w['wraca_na'] ?? '') === '' ? '' : katalog_id($kat['planety'], (string) $w['wraca_na'], 'planeta');
            zmien('firmy', function (&$wszystko) use ($w, $lup, $wraca_na) {
                foreach ($wszystko['firmy'] as &$f) {
                    if ($f['id'] !== ($w['firma'] ?? '')) continue;
                    $d = &$f['dane'];
                    foreach ($d['misje'] as &$m) {
                        if ($m['id'] !== ($w['misja'] ?? '')) continue;
                        if ($m['status'] !== 'zatwierdzona') blad('Rozliczyć można tylko zatwierdzoną misję.');
                        $straty = ['statki' => [], 'zaloga' => []];
                        // Statki: zniszczone znikają z floty, reszta wraca z nowym stanem.
                        $flota = [];
                        foreach ($d['flota'] as $s) {
                            if (in_array($s['id'], $m['statki'], true)) {
                                $wynik = (string) ($w['statki'][$s['id']] ?? 'sprawny');
                                if (!in_array($wynik, ['sprawny', 'uszkodzony', 'zniszczony', 'przejety'], true)) $wynik = 'sprawny';
                                $straty['statki'][] = ['nazwa' => $s['nazwa'], 'wynik' => $wynik];
                                if (in_array($wynik, ['zniszczony', 'przejety'], true)) continue;
                                $s['stan'] = $wynik;
                                if ($wraca_na !== '') $s['lokalizacja'] = $wraca_na;
                            }
                            $flota[] = $s;
                        }
                        $d['flota'] = $flota;
                        $ids_statkow = array_column($flota, 'id');
                        // Ludzie: polegli znikają z załogi, ranni zostają z oznaczeniem.
                        $zaloga = [];
                        foreach ($d['zaloga'] as $z) {
                            if (in_array($z['id'], $m['zaloga'], true)) {
                                $wynik = (string) ($w['zaloga'][$z['id']] ?? 'zdrowy');
                                // spalony = zdemaskowany, zatrzymany = w rękach przeciwnika; obaj zostają na liście.
                                if (!in_array($wynik, ['zdrowy', 'ranny', 'zginal', 'spalony', 'zatrzymany'], true)) $wynik = 'zdrowy';
                                $straty['zaloga'][] = ['imie' => $z['imie'], 'wynik' => $wynik];
                                if ($wynik === 'zginal') continue;
                                $z['stan'] = $wynik;
                            }
                            if ($z['statek'] !== '' && !in_array($z['statek'], $ids_statkow, true)) $z['statek'] = '';
                            $zaloga[] = $z;
                        }
                        $d['zaloga'] = $zaloga;
                        $ids_osob = array_column($zaloga, 'id');
                        foreach ($d['flota'] as &$s) {
                            if (($s['kapitan'] ?? '') !== '' && !in_array($s['kapitan'], $ids_osob, true)) $s['kapitan'] = '';
                        }
                        unset($s);
                        foreach ($d['budynki'] as &$b) {
                            if (($b['menedzer'] ?? '') !== '' && !in_array($b['menedzer'], $ids_osob, true)) $b['menedzer'] = '';
                        }
                        unset($b);
                        // Łup / straty w magazynach.
                        foreach ($lup as $l) {
                            $jest = false;
                            foreach ($d['magazyn'] as &$poz) {
                                if ($poz['planeta'] === $l['planeta'] && $poz['towar'] === $l['towar']) {
                                    $poz['ilosc'] = max(0, $poz['ilosc'] + $l['ilosc']);
                                    $jest = true;
                                }
                            }
                            unset($poz);
                            if (!$jest && $l['ilosc'] > 0) $d['magazyn'][] = $l;
                        }
                        $d['magazyn'] = array_values(array_filter($d['magazyn'], fn($x) => $x['ilosc'] > 0));
                        $m['status'] = 'rozliczona';
                        $m['raport'] = mb_substr(trim((string) ($w['raport'] ?? '')), 0, 5000);
                        $m['straty'] = $straty;
                        $m['lup'] = $lup;
                        $m['zmieniono'] = date('c');
                        return;
                    }
                }
                blad('Nie ma takiej misji.', 404);
            }, firmy_puste());
            odpowiedz(['ok' => true]);
    }
}

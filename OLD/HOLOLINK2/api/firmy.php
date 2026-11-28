<?php
// Firmy i wspólny katalog (towary, planety).
//
// Katalog towarów jest jeden dla całej gry: korzysta z niego firma transportowa,
// a później giełda. Planety to na razie lista nazw; mapa dołączy do nich współrzędne.
//
// Firma ma profil (na razie tylko „transport”). Profil decyduje, jakie dane firma trzyma
// i jakie operacje wolno na nich wykonywać. Interfejs profilu jest w js/aplikacje/firma/.

declare(strict_types=1);

const PROFILE_FIRM = ['transport', 'komorka', 'agencja', 'wydobycie', 'produkcja', 'finanse'];

// Ukryte cechy osób (1–10). Klucz => nazwa wyświetlana. Gracz poznaje je tylko testem.
const STATYSTYKI = [
    'pilotaz' => 'Pilotaż',
    'dowodzenie' => 'Dowodzenie',
    'taktyka' => 'Taktyka',
    'zarzadzanie' => 'Zarządzanie',
    'logistyka' => 'Logistyka',
    'lojalnosc' => 'Lojalność',
    'konspiracja' => 'Konspiracja',
    'handel' => 'Handel',
];
const STANY_OSOBY = ['zdrowy', 'ranny', 'na misji', 'spalony', 'zatrzymany'];
const TEST_CO_ILE_DNI = 30;
// Z której puli kandydatów rekrutuje dany profil.
const PULA_PROFILU = ['transport' => 'ogolna', 'komorka' => 'ogolna', 'agencja' => 'agencja',
    'wydobycie' => 'ogolna', 'produkcja' => 'ogolna', 'finanse' => 'ogolna'];
const ROLE_FIRMY = ['wlasciciel', 'menedzer', 'pracownik'];
const STANY_STATKU = ['sprawny', 'uszkodzony', 'w naprawie', 'w trasie', 'na misji'];
const STATUSY_SZLAKU = ['szkic', 'zgloszony', 'w_realizacji', 'wstrzymany', 'zakonczony', 'odrzucony'];
const SZLAK_AKTYWNY = ['zgloszony', 'w_realizacji', 'wstrzymany'];
const OPERACJE_SZLAKU = ['zaladunek', 'rozladunek', 'kup', 'sprzedaj'];

function katalog(): array
{
    $k = wczytaj('katalog', []);
    return ['towary' => $k['towary'] ?? [], 'planety' => $k['planety'] ?? [], 'oferty' => $k['oferty'] ?? [],
        'lokacje' => $k['lokacje'] ?? [], 'spolki' => $k['spolki'] ?? [], 'npc' => $k['npc'] ?? [],
        'ustawienia' => $k['ustawienia'] ?? ['tempo' => 1]];
}

// Katalog wysyłany do datapada: bez położenia na mapie i bez listy, kto widzi planetę.
// Mapa dostaje te dane osobno (api/?a=mapa), tylko gdy postać jest upoważniona.
function katalog_dla_gracza(): array
{
    $k = katalog();
    unset($k['spolki'], $k['npc']);
    $k['planety'] = array_map(fn($p) => array_diff_key($p, ['mapa' => 1, 'widoczna_dla' => 1]), $k['planety']);
    return $k;
}

// Test ukrytych cech: wartość ±2, opisana słownie. Najwyżej raz na TEST_CO_ILE_DNI dni.
function test_ocena(array $statystyki, ?array $poprzednia, string $tester): array
{
    if (!$statystyki) blad('Tej osoby nie da się ocenić testem.');
    $ostatni = isset($poprzednia['data']) ? strtotime($poprzednia['data']) : 0;
    $nastepny = $ostatni + TEST_CO_ILE_DNI * 86400;
    if ($ostatni && time() < $nastepny) blad('Kolejny test tej osoby będzie możliwy ' . date('d.m.Y', $nastepny) . '.');
    $wyniki = [];
    foreach ($statystyki as $k => $v) {
        $z = max(1, min(10, (int) $v + random_int(-2, 2)));
        $wyniki[STATYSTYKI[$k] ?? $k] = match (true) {
            $z <= 2 => 'bardzo słabo',
            $z <= 4 => 'słabo',
            $z <= 6 => 'przeciętnie',
            $z <= 8 => 'dobrze',
            default => 'wybitnie',
        };
    }
    return ['data' => date('c'), 'wyniki' => $wyniki, 'tester' => $tester];
}

function oczysc_statystyki($st): array
{
    $wynik = [];
    foreach ((array) $st as $klucz => $v) {
        if (isset(STATYSTYKI[$klucz])) $wynik[$klucz] = max(1, min(10, (int) $v));
    }
    return $wynik;
}

function kandydaci_puste(): array
{
    return ['lista' => []];
}

// Kandydaci z puli widoczni dla firmy: bez statystyk, z wynikiem testu tej firmy.
function kandydaci_dla_firmy(array $firma): array
{
    $pula = PULA_PROFILU[$firma['profil']] ?? 'ogolna';
    $wynik = [];
    foreach (wczytaj('kandydaci', kandydaci_puste())['lista'] as $k) {
        if (($k['pula'] ?? 'ogolna') !== $pula) continue;
        $wynik[] = [
            'id' => $k['id'], 'imie' => $k['imie'], 'funkcja' => $k['funkcja'] ?? '', 'pensja' => $k['pensja'] ?? 0,
            'opis' => $k['opis'] ?? '', 'testowalny' => !empty($k['statystyki']), 'ocena' => $k['testy'][$firma['id']] ?? null,
        ];
    }
    return $wynik;
}

// Pozycja katalogu po id albo po nazwie (bez względu na wielkość liter).
function katalog_id(array $lista, string $wartosc, string $co): string
{
    foreach ($lista as $x) {
        if ($x['id'] === $wartosc || mb_strtolower($x['nazwa']) === mb_strtolower(trim($wartosc))) {
            return $x['id'];
        }
    }
    blad("Nieznana $co: „{$wartosc}”. Dodaj ją najpierw w katalogu.");
}

// Wymiary budynku: współrzędne (tekst), powierzchnia w m², piętra nad ziemią,
// poziomy pod ziemią (szyby kopalni, piwnice, bunkry).
function budynek_wymiary(array &$b): void
{
    $b['wspolrzedne'] = mb_substr(trim((string) ($b['wspolrzedne'] ?? '')), 0, 80);
    $b['powierzchnia'] = max(0, (int) ($b['powierzchnia'] ?? 0));
    $b['pietra'] = max(0, min(500, (int) ($b['pietra'] ?? 0)));
    $b['szyby'] = max(0, min(500, (int) ($b['szyby'] ?? 0)));
    // Pozycja na planecie (km) — wtedy budynek jest też lokacją do przemieszczania się.
    foreach (['x', 'y'] as $os) {
        if (isset($b[$os]) && is_numeric($b[$os])) $b[$os] = round((float) $b[$os], 2); else unset($b[$os]);
    }
    if (isset($b['jakosc'])) $b['jakosc'] = max(1, min(5, (int) $b['jakosc']));
    if (isset($b['paliwo'])) $b['paliwo'] = max(0, (int) $b['paliwo']);
    if (isset($b['cena_paliwa'])) $b['cena_paliwa'] = max(0, (int) $b['cena_paliwa']);
}

function firmy_puste(): array
{
    return ['firmy' => []];
}

function firma_dane_puste(string $profil): array
{
    return match ($profil) {
        'transport' => ['flota' => [], 'zaloga' => [], 'magazyn' => [], 'szlaki' => [], 'budynki' => [], 'pojazdy' => []],
        'komorka' => ['flota' => [], 'zaloga' => [], 'magazyn' => [], 'szlaki' => [], 'budynki' => [], 'pojazdy' => [], 'misje' => []],
        'agencja' => ['flota' => [], 'zaloga' => [], 'magazyn' => [], 'szlaki' => [], 'budynki' => [], 'pojazdy' => [], 'misje' => [], 'cele' => []],
        'wydobycie', 'produkcja', 'finanse' => ['flota' => [], 'zaloga' => [], 'magazyn' => [], 'szlaki' => [], 'budynki' => [], 'pojazdy' => [], 'zaklady' => []],
    };
}

// Listy, które MG ustawia w edytorze JSON (reszta, np. kursy i misje, zmienia się tylko w grze).
function firma_edytowalne(string $profil): array
{
    return match ($profil) {
        'transport', 'komorka', 'agencja' => ['flota', 'zaloga', 'magazyn', 'budynki', 'pojazdy'],
        'wydobycie', 'produkcja', 'finanse' => ['flota', 'zaloga', 'magazyn', 'budynki', 'pojazdy', 'zaklady'],
    };
}

// Dane firmy dla datapada: bez ukrytych statystyk osób (widzi je tylko MG).
function firma_dla_gracza(array $f): array
{
    $f['dane'] += firma_dane_puste($f['profil']);
    foreach ($f['dane']['zaloga'] as &$z) {
        $z['testowalny'] = !empty($z['statystyki']);
        unset($z['statystyki']);
    }
    return $f;
}

function firma_rola(array $firma, string $uid): ?string
{
    foreach ($firma['czlonkowie'] as $c) {
        if ($c['uid'] === $uid) {
            return $c['rola'];
        }
    }
    return null;
}

function nadaj_id(array $lista): array
{
    foreach ($lista as &$x) {
        if (!is_array($x)) {
            blad('Każda pozycja listy musi być obiektem.');
        }
        if (empty($x['id'])) {
            $x['id'] = id();
        }
    }
    return $lista;
}

function firma_profil_dla_spolki(array $s): string
{
    if (in_array($s['profil'] ?? '', PROFILE_FIRM, true)) return $s['profil'];
    $symbol = mb_strtoupper((string) ($s['symbol'] ?? ''));
    if (in_array($symbol, ['MGU', 'BES'], true)) return 'wydobycie';
    if ($symbol === 'IBC' || str_contains(mb_strtolower($s['nazwa'] ?? ''), 'bank')) return 'finanse';
    if (in_array($symbol, ['XTS', 'HCL'], true) || str_contains(mb_strtolower($s['nazwa'] ?? ''), 'transport')) return 'transport';
    if (in_array($symbol, ['VELT'], true) || preg_match('/fuel|mining|górn|wydoby|tibanna|consortium/i', ($s['nazwa'] ?? '') . ' ' . ($s['branza'] ?? ''))) return 'wydobycie';
    if (preg_match('/industrial|processing|stoczni|uzbrojen|droid|elektronik|żywno|manufactur|production/i', ($s['nazwa'] ?? '') . ' ' . ($s['branza'] ?? ''))) return 'produkcja';
    return 'produkcja';
}

function firma_skala_z_wartosci(float $wartosc): string
{
    return $wartosc >= 250 ? 'duza' : ($wartosc >= 120 ? 'srednia' : 'mala');
}

function firma_kapital_skali(string $skala): int
{
    return match ($skala) { 'duza' => 8400000, 'srednia' => 3200000, default => 1150000 };
}

function firma_npc_domyslny(string $symbol): array
{
    $domyslni = [
        'KDY' => ['Daro Venn', 'Dyrektor stoczni'], 'SFS' => ['Nera Voss', 'Dyrektorka operacyjna'],
        'CEC' => ['Jalen Corda', 'Prezes zarządu'], 'INC' => ['Vera Noll', 'Dyrektorka produkcji'],
        'NDC' => ['Davin Oru', 'Dyrektor projektów'], 'RSD' => ['Kora Venn', 'Dyrektorka floty'],
        'BLT' => ['Taris Konn', 'Dyrektor uzbrojenia'], 'MSM' => ['Brel Tann', 'Dyrektor handlowy'],
        'CZK' => ['Mira Daal', 'Dyrektorka operacyjna'], 'CYB' => ['Tillo Renn', 'Dyrektor konstrukcji'],
        'IAU' => ['Olan Merek', 'Dyrektor droidów'], 'SRS' => ['Sella Varn', 'Dyrektorka zarządu'],
        'LOR' => ['Gerrit Vale', 'Dyrektor regionalny'], 'MGU' => ['Daro Luv', 'Nadzorca wydobycia'],
        'BES' => ['Tavin Gree', 'Dyrektor wydobycia'], 'XTS' => ['Nali Tress', 'Dyrektorka przewozów'],
        'HCL' => ['Nek Vos', 'Koordynator logistyki'], 'IBC' => ['Muun Rell', 'Dyrektor rachunków'],
        'GIZ' => ['Ressa Varo', 'Dyrektorka handlowa'], 'VELT' => ['Tarok Venn', 'Dyrektor wydobycia'],
        'ORDE' => ['Jora Pell', 'Dyrektorka zakładów'], 'DELT' => ['Savin Korr', 'Dyrektor przetwórstwa'],
    ];
    return $domyslni[$symbol] ?? ['Neri ' . $symbol, 'Dyrektor zarządzający'];
}

// Pierwsze wejście MG do Katalogu tworzy brakujące firmy giełdowe, NPC-menadżerów
// i rachunki. Sygnatura spółki sprawia, że operacja jest bezpieczna przy każdym kolejnym wejściu.
function firma_uzgodnij_spolki(): array
{
    $k = wczytaj('katalog', []);
    $wszystko = wczytaj('firmy', firmy_puste());
    $bank = wczytaj('bank', bank_pusty());
    $spolki = $k['spolki'] ?? [];
    foreach ($wszystko['firmy'] as $f) {
        if (!in_array($f['profil'] ?? '', ['transport', 'wydobycie', 'produkcja', 'finanse'], true)) continue;
        $jest = false;
        foreach ($spolki as $s) {
            if (($s['firma_id'] ?? '') === $f['id'] || (($s['symbol'] ?? '') !== '' && ($s['symbol'] ?? '') === ($f['spolka_symbol'] ?? ''))
                || gielda_klucz_nazwy($s['nazwa'] ?? '') === gielda_klucz_nazwy($f['nazwa'] ?? '')) { $jest = true; break; }
        }
        if ($jest) continue;
        $saldo = (int) ($bank['konta']['f:' . $f['id']]['saldo'] ?? 0);
        $symbol = match ($f['nazwa']) {
            'Veltris Fuel Consortium' => 'VELT', 'Ordel Industrial Holdings' => 'ORDE', 'Deltara Processing Authority' => 'DELT',
            default => '',
        };
        $spolki[] = ['symbol' => $symbol, 'nazwa' => $f['nazwa'], 'branza' => '', 'status' => 'poza_gielda',
            'profil' => firma_profil_dla_spolki(['nazwa' => $f['nazwa']]),
            'skala' => $saldo >= 5000000 ? 'duza' : ($saldo >= 2000000 ? 'srednia' : 'mala'),
            'kapital_startowy' => $saldo > 0 ? $saldo : 250000, 'firma_id' => $f['id']];
    }
    $zajete = array_column(gielda_definicje()['instrumenty'], 'symbol');
    $spolki = gielda_spolki_z_katalogu($spolki, $zajete);
    $npc = $k['npc'] ?? [];
    $npcBySymbol = array_column($npc, null, 'spolka_symbol');
    $doZasilenia = [];

    foreach ($spolki as &$s) {
        $symbol = $s['symbol'];
        $profil = firma_profil_dla_spolki($s);
        $s['profil'] = $profil;
        $s['skala'] = in_array($s['skala'] ?? '', ['mala', 'srednia', 'duza'], true)
            ? $s['skala'] : firma_skala_z_wartosci((float) ($s['cena'] ?? 1));
        $s['kapital_startowy'] = max(250000, (int) ($s['kapital_startowy'] ?? firma_kapital_skali($s['skala'])));
        $menedzer = $npcBySymbol[$symbol] ?? null;
        if (!$menedzer) {
            [$imie, $funkcja] = firma_npc_domyslny($symbol);
            $menedzer = ['id' => 'npc_' . $symbol, 'spolka_symbol' => $symbol, 'imie' => $imie, 'funkcja' => $funkcja,
                'typ' => 'menedzer', 'npc' => true];
            $npc[] = $menedzer;
        }
        $s['menedzer_id'] = $menedzer['id'];
        $s['menedzer'] = $menedzer['imie'];
        $s['funkcja_menedzera'] = $menedzer['funkcja'];
    }
    unset($s);

    $idsFirm = zmien('firmy', function (&$d) use (&$spolki, &$doZasilenia) {
        foreach ($spolki as &$s) {
            $idFirmy = null;
            $f = null;
            foreach ($d['firmy'] as &$kandydat) {
                if (($kandydat['id'] ?? '') === ($s['firma_id'] ?? '') || ($kandydat['spolka_symbol'] ?? '') === $s['symbol']
                    || gielda_klucz_nazwy($kandydat['nazwa'] ?? '') === gielda_klucz_nazwy($s['nazwa'])) { $f = &$kandydat; break; }
            }
            unset($kandydat);
            if ($f === null) {
                $idFirmy = id();
                $f = ['id' => $idFirmy, 'nazwa' => $s['nazwa'], 'profil' => $s['profil'], 'czlonkowie' => [],
                    'dane' => firma_dane_puste($s['profil'])];
                $d['firmy'][] = $f;
                $idx = array_key_last($d['firmy']);
                $f = &$d['firmy'][$idx];
            }
            $f['nazwa'] = $s['nazwa'];
            $f['profil'] = $s['profil'];
            $f['spolka_symbol'] = $s['symbol'];
            $f['skala'] = $s['skala'];
            $f['menedzer_id'] = $s['menedzer_id'];
            $f['dane'] = array_merge(firma_dane_puste($s['profil']), $f['dane'] ?? []);
            $f['czlonkowie'] = $f['czlonkowie'] ?? [];
            $zaloga = &$f['dane']['zaloga'];
            $npcId = $s['menedzer_id'];
            $osoba = null;
            foreach ($zaloga as &$z) {
                if (($z['npc_id'] ?? '') === $npcId) { $osoba = &$z; break; }
            }
            unset($z);
            if ($osoba === null) {
                $osoba = ['id' => id(), 'npc_id' => $npcId, 'imie' => $s['menedzer'], 'funkcja' => $s['funkcja_menedzera'],
                    'pensja' => 0, 'statek' => '', 'budynek' => '', 'stan' => 'zdrowy', 'statystyki' => ['zarzadzanie' => 7, 'logistyka' => 6, 'lojalnosc' => 6],
                    'kryptonim' => '', 'gracz' => ''];
                $zaloga[] = $osoba;
            } else {
                $osoba['imie'] = $s['menedzer'];
                $osoba['funkcja'] = $s['funkcja_menedzera'];
            }
            unset($osoba, $zaloga, $f);
            $s['firma_id'] = $idFirmy ?? ($s['firma_id'] ?? '');
            if ($s['firma_id'] === '') {
                foreach ($d['firmy'] as $utworzona) if (($utworzona['spolka_symbol'] ?? '') === $s['symbol']) { $s['firma_id'] = $utworzona['id']; break; }
            }
            $konto = 'f:' . $s['firma_id'];
            if ($s['firma_id'] !== '' && !isset($doZasilenia[$konto])) $doZasilenia[$konto] = ['nazwa' => $s['nazwa'], 'kwota' => $s['kapital_startowy']];
            $idFirmy = null;
        }
        unset($s);
        return true;
    }, firmy_puste());

    // Powiązania firma_id są uzupełniane po zapisaniu listy firm.
    $zapisaneFirmy = wczytaj('firmy', firmy_puste())['firmy'];
    foreach ($spolki as &$s) {
        if (empty($s['firma_id'])) {
            foreach ($zapisaneFirmy as $f) if (($f['spolka_symbol'] ?? '') === $s['symbol']) { $s['firma_id'] = $f['id']; break; }
        }
    }
    unset($s);
    $k['spolki'] = $spolki;
    $k['npc'] = $npc;
    $staryKatalog = wczytaj('katalog', []);
    if (($staryKatalog['spolki'] ?? []) !== $spolki || ($staryKatalog['npc'] ?? []) !== $npc) {
        zmien('katalog', function (&$d) use ($spolki, $npc) { $d['spolki'] = $spolki; $d['npc'] = $npc; }, []);
    }
    foreach ($zapisaneFirmy as $f) {
        $uid = 'f:' . $f['id'];
        if (isset($bank['konta'][$uid]) || isset($doZasilenia[$uid])) continue;
        $kwota = in_array($f['profil'] ?? '', ['komorka', 'agencja'], true)
            ? 250000 : firma_kapital_skali($f['skala'] ?? 'mala');
        $doZasilenia[$uid] = ['nazwa' => $f['nazwa'], 'kwota' => $kwota];
    }
    zmien('bank', function (&$b) use ($doZasilenia) {
        foreach ($doZasilenia as $uid => $w) {
            if (isset($b['konta'][$uid])) continue;
            bank_konto($b, $uid);
            bank_uznaj($b, $uid, (int) $w['kwota'], 'Kapitał założycielski', 'Rejestracja spółki ' . $w['nazwa'], [$uid => $w['nazwa']]);
        }
    }, bank_pusty());
    return $zapisaneFirmy;
}

// ---------- transport: sprawdzanie i rozliczanie szlaku ----------
// Cała logika (magazyny, ładowność, handel) jest w handel.php.

function transport_sprawdz_szlak(array $szlak, array $dane, array $katalog, bool $w_trasie = false): array
{
    return handel_symuluj($szlak, $dane, $katalog, date('Y-m-d'), null, $w_trasie)['problemy'];
}

function transport_ustaw_stan_statku(array &$dane, string $statek, string $stan): void
{
    foreach ($dane['flota'] as &$s) {
        if ($s['id'] === $statek) {
            $s['stan'] = $stan;
        }
    }
}

// Czyszczenie szlaku przysłanego przez gracza: tylko znane pola, liczby całkowite.
function transport_oczysc_szlak(array $w): array
{
    $przystanki = [];
    foreach (array_slice((array) ($w['przystanki'] ?? []), 0, 30) as $p) {
        $operacje = [];
        foreach (array_slice((array) ($p['operacje'] ?? []), 0, 30) as $o) {
            $operacje[] = [
                'typ' => in_array($o['typ'] ?? '', OPERACJE_SZLAKU, true) ? $o['typ'] : 'zaladunek',
                'towar' => mb_substr((string) ($o['towar'] ?? ''), 0, 32),
                'ilosc' => max(0, (int) ($o['ilosc'] ?? 0)),
            ];
        }
        $przystanki[] = ['planeta' => mb_substr((string) ($p['planeta'] ?? ''), 0, 32), 'operacje' => $operacje];
    }
    return [
        'id' => mb_substr((string) ($w['id'] ?? ''), 0, 32),
        'nazwa' => mb_substr(trim((string) ($w['nazwa'] ?? '')), 0, 80) ?: 'Kurs bez nazwy',
        'statek' => mb_substr((string) ($w['statek'] ?? ''), 0, 32),
        'przystanki' => $przystanki,
        'notatka' => mb_substr(trim((string) ($w['notatka'] ?? '')), 0, 500),
        'auto' => !empty($w['auto']),
        'co_dni' => max(1, min(30, (int) ($w['co_dni'] ?? 7))),
    ];
}

// Operacje gracza w firmie transportowej. $moze_zmieniac = właściciel lub menedżer.
function transport_operacja(array &$firma, string $op, array $w, array $u, bool $moze_zmieniac): array
{
    $dane = &$firma['dane'];
    if (!$moze_zmieniac) {
        blad('Twoje uprawnienia w firmie pozwalają tylko na podgląd.', 403);
    }
    switch ($op) {
        case 'statek_nazwa':
            $nazwa = mb_substr(trim((string) ($w['nazwa'] ?? '')), 0, 60);
            if ($nazwa === '') blad('Podaj nazwę statku.');
            foreach ($dane['flota'] as &$s) {
                if ($s['id'] === ($w['statek'] ?? '')) {
                    $s['nazwa'] = $nazwa;
                    return ['ok' => true];
                }
            }
            blad('Nie ma takiego statku.', 404);

        case 'przydziel':
            // Osoba na statek albo (w profilach z budynkami) do budynku, albo do bazy (oba puste).
            // Opcjonalnie jako kapitan statku / kierownik budynku.
            $statek = (string) ($w['statek'] ?? '');
            $budynek = (string) ($w['budynek'] ?? '');
            if ($statek !== '' && $budynek !== '') blad('Osoba może być albo na statku, albo w budynku.');
            if ($statek !== '' && !in_array($statek, array_column($dane['flota'], 'id'), true)) blad('Nie ma takiego statku.', 404);
            if ($budynek !== '' && !in_array($budynek, array_column($dane['budynki'] ?? [], 'id'), true)) blad('Nie ma takiego budynku.', 404);
            $znaleziony = false;
            foreach ($dane['zaloga'] as &$z) {
                if ($z['id'] === ($w['czlonek'] ?? '')) {
                    if (($z['stan'] ?? '') === 'na misji') blad('Ta osoba jest na misji.');
                    $z['statek'] = $statek;
                    $z['budynek'] = $budynek;
                    $znaleziony = true;
                }
            }
            unset($z);
            if (!$znaleziony) blad('Nie ma takiej osoby w załodze.', 404);
            if (isset($dane['budynki'])) {
                foreach ($dane['budynki'] as &$b) {
                    if (!empty($w['menedzer']) && $b['id'] === $budynek) {
                        $b['menedzer'] = $w['czlonek'];
                    } elseif (($b['menedzer'] ?? '') === $w['czlonek'] && $b['id'] !== $budynek) {
                        $b['menedzer'] = '';
                    }
                }
                unset($b);
            }
            // Kapitan musi być na swoim statku.
            foreach ($dane['flota'] as &$s) {
                if (!empty($w['kapitan']) && $s['id'] === $statek) {
                    $s['kapitan'] = $w['czlonek'];
                } elseif (($s['kapitan'] ?? '') === $w['czlonek'] && $s['id'] !== $statek) {
                    $s['kapitan'] = '';
                }
            }
            return ['ok' => true];

        case 'budynek_pracownicy':
            // Prosta obsada: liczba pracowników od 0 do pojemności budynku.
            foreach ($dane['budynki'] as &$b) {
                if ($b['id'] === ($w['budynek'] ?? '')) {
                    $b['pracownicy'] = max(0, min((int) $b['pojemnosc'], (int) ($w['pracownicy'] ?? 0)));
                    return ['ok' => true];
                }
            }
            blad('Nie ma takiego budynku.', 404);

        case 'budynek_kup':
            // Zakup z rynku nieruchomości: płaci rachunek firmy, oferta znika z rynku.
            $oid = (string) ($w['oferta'] ?? '');
            $fid = $firma['id'];
            $budynek = zmien('katalog', function (&$kat) use ($oid, $fid, $firma) {
                foreach ($kat['oferty'] ?? [] as $i => $o) {
                    if ($o['id'] !== $oid) continue;
                    $wynik = zmien('bank', fn(&$bank) => bank_obciaz($bank, 'f:' . $fid, (int) $o['cena'], 'Pośrednik nieruchomości', 'Zakup: ' . $o['nazwa'], bank_wlasciciele() + ['f:' . $fid => $firma['nazwa']]), bank_pusty());
                    if ($wynik !== 'ok') blad('Na rachunku firmy brakuje środków (cena: ' . $o['cena'] . ' kr).');
                    array_splice($kat['oferty'], $i, 1);
                    return ['id' => id(), 'nazwa' => $o['nazwa'], 'typ' => $o['typ'] ?? '', 'planeta' => $o['planeta'] ?? '',
                        'wspolrzedne' => $o['wspolrzedne'] ?? '', 'powierzchnia' => (int) ($o['powierzchnia'] ?? 0),
                        'pietra' => (int) ($o['pietra'] ?? 0), 'szyby' => (int) ($o['szyby'] ?? 0),
                        'pojemnosc' => (int) ($o['pojemnosc'] ?? 0), 'pracownicy' => 0, 'pensja' => (int) ($o['pensja'] ?? 0),
                        'menedzer' => '', 'opis' => $o['opis'] ?? ''];
                }
                blad('Ta oferta jest już nieaktualna.', 404);
            });
            $dane['budynki'][] = $budynek;
            return ['ok' => true];

        case 'test':
            foreach ($dane['zaloga'] as &$z) {
                if ($z['id'] !== ($w['czlonek'] ?? '')) continue;
                $z['ocena'] = test_ocena($z['statystyki'] ?? [], $z['ocena'] ?? null, $u['postac']);
                return ['ok' => true, 'ocena' => $z['ocena']];
            }
            blad('Nie ma takiej osoby.', 404);

        case 'kandydat_test':
            $fid = $firma['id'];
            $pula = PULA_PROFILU[$firma['profil']] ?? 'ogolna';
            $ocena = zmien('kandydaci', function (&$k) use ($w, $fid, $pula, $u) {
                foreach ($k['lista'] as &$x) {
                    if ($x['id'] !== ($w['kandydat'] ?? '') || ($x['pula'] ?? 'ogolna') !== $pula) continue;
                    $x['testy'][$fid] = test_ocena($x['statystyki'] ?? [], $x['testy'][$fid] ?? null, $u['postac']);
                    return $x['testy'][$fid];
                }
                blad('Ten kandydat nie jest już dostępny.', 404);
            }, kandydaci_puste());
            return ['ok' => true, 'ocena' => $ocena];

        case 'kandydat_zatrudnij':
            $fid = $firma['id'];
            $pula = PULA_PROFILU[$firma['profil']] ?? 'ogolna';
            $k = zmien('kandydaci', function (&$kk) use ($w, $pula) {
                foreach ($kk['lista'] as $i => $x) {
                    if ($x['id'] !== ($w['kandydat'] ?? '') || ($x['pula'] ?? 'ogolna') !== $pula) continue;
                    array_splice($kk['lista'], $i, 1);
                    return $x;
                }
                blad('Ten kandydat nie jest już dostępny.', 404);
            }, kandydaci_puste());
            $dane['zaloga'][] = [
                'id' => id(), 'imie' => $k['imie'], 'funkcja' => $k['funkcja'] ?? '', 'pensja' => (int) ($k['pensja'] ?? 0),
                'statek' => '', 'budynek' => '', 'stan' => 'zdrowy', 'statystyki' => $k['statystyki'] ?? [],
                'ocena' => $k['testy'][$fid] ?? null, 'kryptonim' => $k['kryptonim'] ?? '', 'gracz' => '',
            ];
            return ['ok' => true];

        case 'przelew':
            // Przelew z rachunku firmy na dowolny rachunek.
            $numer = bank_numer((string) ($w['numer'] ?? ''));
            $kwota = bank_kwota($w['kwota'] ?? null);
            if ($kwota <= 0) blad('Kwota musi być większa od zera.');
            $tytul = mb_substr(trim((string) ($w['tytul'] ?? '')), 0, 140);
            $klucz = 'f:' . $firma['id'];
            $wynik = zmien('bank', fn(&$bank) => bank_przelej($bank, $klucz, $numer, $kwota, $tytul, bank_wlasciciele() + [$klucz => $firma['nazwa']]), bank_pusty());
            bank_blad_przelewu($wynik);
            return ['ok' => true];

        case 'szlak_zapisz':
        case 'szlak_zglos':
            $s = transport_oczysc_szlak($w['szlak'] ?? []);
            $stary = null;
            foreach ($dane['szlaki'] as $x) {
                if ($x['id'] === $s['id']) $stary = $x;
            }
            if ($stary && !in_array($stary['status'], ['szkic', 'odrzucony'], true)) blad('Zgłoszonego kursu nie można już zmieniać.');
            $problemy = transport_sprawdz_szlak($s, $dane, katalog());
            if ($op === 'szlak_zglos') {
                if ($problemy) odpowiedz(['blad' => 'Plan kursu zawiera błędy.', 'problemy' => $problemy], 400);
                foreach ($dane['szlaki'] as $x) {
                    if ($x['statek'] === $s['statek'] && $x['id'] !== $s['id'] && in_array($x['status'], SZLAK_AKTYWNY, true)) {
                        blad('Ten statek ma już zgłoszony lub trwający kurs.');
                    }
                }
            }
            $s['id'] = $s['id'] ?: id();
            $s['status'] = $op === 'szlak_zglos' ? 'zgloszony' : 'szkic';
            $s['autor'] = $u['postac'];
            $s['zmieniono'] = date('c');
            $s['uwagi'] = $stary['uwagi'] ?? '';
            $s['dziennik'] = $stary['dziennik'] ?? [];
            $dane['szlaki'] = array_values(array_filter($dane['szlaki'], fn($x) => $x['id'] !== $s['id']));
            $dane['szlaki'][] = $s;
            return ['ok' => true, 'id' => $s['id'], 'problemy' => $problemy];

        case 'szlak_zatrzymaj':
            // Gracz kończy kurs automatyczny (po bieżącym obiegu statek zostaje w porcie startowym).
            foreach ($dane['szlaki'] as &$x) {
                if ($x['id'] !== ($w['id'] ?? '')) continue;
                if (empty($x['auto']) || !in_array($x['status'], ['w_realizacji', 'wstrzymany'], true)) blad('Ten kurs nie jest automatyczny albo już nie działa.');
                $x['status'] = 'zakonczony';
                $x['zmieniono'] = date('c');
                transport_ustaw_stan_statku($dane, $x['statek'], 'sprawny');
                return ['ok' => true];
            }
            blad('Nie ma takiego kursu.', 404);

        case 'szlak_wznow':
            // Wznowienie wstrzymanego automatu (np. po dopłacie na rachunek albo uzupełnieniu magazynu).
            foreach ($dane['szlaki'] as &$x) {
                if ($x['id'] !== ($w['id'] ?? '')) continue;
                if ($x['status'] !== 'wstrzymany') blad('Ten kurs nie jest wstrzymany.');
                $x['status'] = 'w_realizacji';
                $x['uwagi'] = '';
                $x['nastepny'] = date('c', time() + $x['co_dni'] * 86400);
                return ['ok' => true];
            }
            blad('Nie ma takiego kursu.', 404);

        case 'szlak_usun':
            $sid = (string) ($w['id'] ?? '');
            foreach ($dane['szlaki'] as $x) {
                if ($x['id'] === $sid && !in_array($x['status'], ['szkic', 'odrzucony', 'zakonczony'], true)) {
                    blad('Kursu w realizacji nie można usunąć.');
                }
            }
            $dane['szlaki'] = array_values(array_filter($dane['szlaki'], fn($x) => $x['id'] !== $sid));
            return ['ok' => true];
    }
    blad('Nieznana operacja.', 404);
}

function firma_biznes_operacja(array &$firma, string $op, array $w, array $u, bool $moze_zmieniac): array
{
    if (!in_array($op, ['przydziel', 'budynek_pracownicy', 'budynek_kup', 'test', 'kandydat_test', 'kandydat_zatrudnij', 'przelew'], true)) {
        blad('Ta firma nie prowadzi przewozów ani kursów handlowych.', 403);
    }
    return transport_operacja($firma, $op, $w, $u, $moze_zmieniac);
}

function obsluz_firmy(string $akcja, bool $post): void
{
    switch ($akcja) {

        case 'firmy':
            $u = wymagaj_logowania();
            handel_automaty();
            $lista = [];
            foreach (wczytaj('firmy', firmy_puste())['firmy'] as $f) {
                $rola = firma_rola($f, $u['id']);
                if ($rola) $lista[] = ['id' => $f['id'], 'nazwa' => $f['nazwa'], 'profil' => $f['profil'], 'rola' => $rola];
            }
            odpowiedz(['firmy' => $lista]);

        case 'firma':
            $u = wymagaj_logowania();
            handel_automaty();
            $fid = (string) ($_GET['id'] ?? '');
            $postacie = bank_postacie();
            foreach (wczytaj('firmy', firmy_puste())['firmy'] as $f) {
                if ($f['id'] !== $fid) continue;
                $rola = firma_rola($f, $u['id']);
                if (!$rola && $u['rola'] !== 'mg') blad('Brak dostępu do danych tej firmy.', 403);
                $f['czlonkowie'] = array_map(fn($c) => $c + ['postac' => $postacie[$c['uid']] ?? '?'], $f['czlonkowie']);
                $klucz = 'f:' . $f['id'];
                $finanse = zmien('bank', fn(&$bank) => bank_wyciag($bank, $klucz, 40), bank_pusty());
                odpowiedz([
                    'firma' => firma_dla_gracza($f),
                    'rola' => $rola ?? 'wlasciciel',
                    'katalog' => katalog_dla_gracza(),
                    'finanse' => $finanse,
                    'kandydaci' => kandydaci_dla_firmy($f),
                    'ceny' => handel_ceny_dnia(katalog(), date('Y-m-d')),
                ]);
            }
            blad('Nie ma takiej firmy.', 404);

        case 'firma_operacja':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $w = wejscie();
            $fid = (string) ($w['firma'] ?? '');
            $op = (string) ($w['op'] ?? '');
            $wynik = zmien('firmy', function (&$wszystko) use ($fid, $op, $w, $u) {
                foreach ($wszystko['firmy'] as &$f) {
                    if ($f['id'] !== $fid) continue;
                    $rola = firma_rola($f, $u['id']) ?? ($u['rola'] === 'mg' ? 'wlasciciel' : null);
                    if (!$rola) blad('Brak dostępu do danych tej firmy.', 403);
                    $f['dane'] += firma_dane_puste($f['profil']); // starsze firmy mogą nie mieć nowych list
                    $moze = in_array($rola, ['wlasciciel', 'menedzer'], true);
                    return match ($f['profil']) {
                        'transport' => transport_operacja($f, $op, $w, $u, $moze),
                        'komorka' => komorka_operacja($f, $op, $w, $u, $moze),
                        'agencja' => agencja_operacja($f, $op, $w, $u, $moze),
                        'wydobycie', 'produkcja', 'finanse' => firma_biznes_operacja($f, $op, $w, $u, $moze),
                    };
                }
                blad('Nie ma takiej firmy.', 404);
            }, firmy_puste());
            odpowiedz($wynik);

        // Podgląd poprawności szlaku bez zapisywania (gracz widzi problemy na bieżąco).
        case 'firma_sprawdz_szlak':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $w = wejscie();
            foreach (wczytaj('firmy', firmy_puste())['firmy'] as $f) {
                if ($f['id'] !== ($w['firma'] ?? '')) continue;
                if (!firma_rola($f, $u['id']) && $u['rola'] !== 'mg') blad('Brak dostępu.', 403);
                // Problemy planu i wycena po dzisiejszym kursie giełdowym (bez wpływu kapitana).
                $sym = handel_symuluj(transport_oczysc_szlak($w['szlak'] ?? []), $f['dane'] + firma_dane_puste($f['profil']), katalog(), date('Y-m-d'));
                odpowiedz(['problemy' => $sym['problemy'], 'wycena' => ['koszt' => $sym['koszt'], 'przychod' => $sym['przychod'], 'zysk' => $sym['zysk'], 'pozycje' => $sym['pozycje']]]);
            }
            blad('Nie ma takiej firmy.', 404);

        // ----- panel MG -----

        case 'mg_katalog':
            wymagaj_mg();
            gielda_uzgodnij_katalog();
            $firmy = firma_uzgodnij_spolki();
            odpowiedz(['katalog' => katalog(), 'gieldy' => gielda_definicje()['gieldy'], 'firmy' => $firmy]);

        case 'mg_katalog_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $towary = nadaj_id((array) ($w['towary'] ?? []));
            $gielda = gielda_definicje();
            $spolki = gielda_spolki_z_katalogu((array) ($w['spolki'] ?? []), array_column($gielda['instrumenty'], 'symbol'));
            $npc = [];
            foreach ((array) ($w['npc'] ?? []) as $n) {
                if (!is_array($n)) blad('Każdy NPC musi być obiektem.');
                $imie = mb_substr(trim((string) ($n['imie'] ?? '')), 0, 80);
                if ($imie === '') continue;
                $npc[] = ['id' => mb_substr(trim((string) ($n['id'] ?? '')) ?: id(), 0, 32),
                    'spolka_symbol' => mb_strtoupper(trim((string) ($n['spolka_symbol'] ?? ''))),
                    'imie' => $imie, 'funkcja' => mb_substr(trim((string) ($n['funkcja'] ?? 'Menedżer')), 0, 80),
                    'typ' => 'menedzer', 'npc' => true];
            }
            foreach ($spolki as &$s) {
                foreach ($npc as $n) {
                    if ($n['spolka_symbol'] !== $s['symbol']) continue;
                    $s['menedzer_id'] = $n['id'];
                    $s['menedzer'] = $n['imie'];
                    $s['funkcja_menedzera'] = $n['funkcja'];
                    break;
                }
            }
            unset($s);
            $planety = nadaj_id((array) ($w['planety'] ?? []));
            foreach ($towary as &$t) {
                if (trim((string) ($t['nazwa'] ?? '')) === '') blad('Każdy towar musi mieć nazwę.');
                $t['masa'] = max(1, (int) ($t['masa'] ?? 1));
                $t['jednostka'] = (string) ($t['jednostka'] ?? 't');
                $t['cena'] = max(1, (int) ($t['cena'] ?? 10));
                $t['kategoria'] = (string) ($t['kategoria'] ?? '');
            }
            unset($t);
            // Każdy towar z giełdy jest też dostępny w katalogu firm. Zachowujemy ID towarów
            // i symbole giełdowe, aby magazyny oraz portfele nie straciły powiązań.
            foreach ($gielda['instrumenty'] as $i) {
                if (($i['rodzaj'] ?? '') !== 'towar') continue;
                $znaleziony = false;
                foreach ($towary as &$t) {
                    if (($t['gielda_symbol'] ?? '') === $i['symbol'] || ($i['katalog_id'] ?? '') === ($t['id'] ?? '')
                        || mb_strtolower(trim($t['nazwa'] ?? '')) === mb_strtolower(trim($i['nazwa'] ?? ''))) {
                        $t['gielda_symbol'] = $i['symbol'];
                        $znaleziony = true;
                        break;
                    }
                }
                unset($t);
                if (!$znaleziony) $towary[] = ['id' => $i['katalog_id'] ?? id(), 'gielda_symbol' => $i['symbol'],
                    'nazwa' => $i['nazwa'], 'kategoria' => $i['kategoria'] ?? '', 'masa' => 1,
                    'jednostka' => $i['jednostka'] ?? 'szt.', 'cena' => max(1, (int) round($i['cena']))];
            }
            $towary = nadaj_id($towary);
            foreach ($towary as &$t) {
                $t['masa'] = max(1, (int) ($t['masa'] ?? 1));
                $t['jednostka'] = (string) ($t['jednostka'] ?? 't');
                $t['cena'] = max(1, (int) ($t['cena'] ?? 10));
                $t['kategoria'] = (string) ($t['kategoria'] ?? '');
            }
            unset($t);
            // Spółki giełdowe zawsze mają wpis w katalogu. W katalogu można też oznaczyć
            // podmiot jako nienotowany, bez usuwania akcji posiadanych przez graczy.
            foreach ($gielda['instrumenty'] as $i) {
                if (($i['rodzaj'] ?? '') !== 'spolka') continue;
                $jest = false;
                foreach ($spolki as &$s) {
                    if ($s['symbol'] === $i['symbol'] || mb_strtolower($s['nazwa']) === mb_strtolower($i['nazwa'])) { $jest = true; break; }
                }
                unset($s);
                if (!$jest) $spolki[] = ['symbol' => $i['symbol'], 'nazwa' => $i['nazwa'], 'branza' => $i['kategoria'] ?? '',
                    'status' => ($i['notowana'] ?? true) ? 'notowana' : 'poza_gielda', 'gielda' => $i['gielda'], 'cena' => $i['cena'], 'pochodzenie' => $i['pochodzenie'] ?? ''];
            }
            $spolki = gielda_spolki_z_katalogu($spolki, array_column($gielda['instrumenty'], 'symbol'));
            // Specyfika planety: { "nazwa towaru albo kategorii": mnożnik ceny }.
            // Zapisujemy jako t:<id towaru> albo k:<kategoria>; w edytorze MG wraca do nazw.
            $kategorie = array_unique(array_map(fn($t) => mb_strtolower($t['kategoria']), $towary));
            foreach ($planety as &$p) {
                if (trim((string) ($p['nazwa'] ?? '')) === '') blad('Każda planeta musi mieć nazwę.');
                $sp = [];
                foreach ((array) ($p['specyfika'] ?? []) as $klucz => $mn) {
                    $mn = round(max(0.1, min(10, (float) $mn)), 2);
                    if (str_starts_with((string) $klucz, 't:') || str_starts_with((string) $klucz, 'k:')) {
                        $sp[$klucz] = $mn;
                        continue;
                    }
                    $towar = null;
                    foreach ($towary as $t) {
                        if (mb_strtolower($t['nazwa']) === mb_strtolower(trim((string) $klucz))) $towar = $t;
                    }
                    if ($towar) {
                        $sp['t:' . $towar['id']] = $mn;
                    } elseif (in_array(mb_strtolower(trim((string) $klucz)), $kategorie, true)) {
                        $sp['k:' . mb_strtolower(trim((string) $klucz))] = $mn;
                    } else {
                        blad("Specyfika planety {$p['nazwa']}: nie ma towaru ani kategorii „{$klucz}”.");
                    }
                }
                $p['specyfika'] = $sp;
            }
            unset($p);
            // Rynek nieruchomości: oferty budynków do kupienia przez firmy (każda oferta = jeden budynek).
            $oferty = nadaj_id((array) ($w['oferty'] ?? []));
            foreach ($oferty as &$o) {
                if (trim((string) ($o['nazwa'] ?? '')) === '') blad('Każda oferta musi mieć nazwę.');
                $o['cena'] = max(0, (int) ($o['cena'] ?? 0));
                $o['pojemnosc'] = max(0, (int) ($o['pojemnosc'] ?? 0));
                $o['pensja'] = max(0, (int) ($o['pensja'] ?? 0));
                $o['planeta'] = ($o['planeta'] ?? '') === '' ? '' : katalog_id($planety, (string) $o['planeta'], 'planeta');
                budynek_wymiary($o);
            }
            unset($o);
            foreach ($planety as &$p) {
                $p['urbanizacja'] = in_array($p['urbanizacja'] ?? '', ['brak', 'niska', 'wysoka'], true) ? $p['urbanizacja'] : 'brak';
                // Typ planety (widok z powierzchni, chmury przy starcie) i współrzędne skoku.
                $p['typ'] = in_array($p['typ'] ?? '', TYPY_PLANET, true) ? $p['typ'] : 'skalista';
                $p['wspolrzedne'] = mb_substr(trim((string) ($p['wspolrzedne'] ?? '')), 0, 40);
                // Kontrola lotów (nazwa, np. „Imperialna Kontrola Lotów Tatooine”); pusta = planeta bez kontroli.
                $p['kontrola'] = mb_substr(trim((string) ($p['kontrola'] ?? '')), 0, 80);
                if (isset($p['taksowka'])) $p['taksowka'] = max(1, (int) $p['taksowka']);
                // Planeta spoza bazy mapy (assets/mapa): położenie na mapie galaktyki i opcjonalnie
                // lista loginów, które ją widzą (pusta = wszyscy). Zobacz mapa_dodatkowe() w ruch.php.
                if (isset($p['mapa'])) {
                    $m = (array) $p['mapa'];
                    if (!is_numeric($m['x'] ?? null) || !is_numeric($m['y'] ?? null)) blad("Planeta {$p['nazwa']}: „mapa” potrzebuje liczb x i y.");
                    $p['mapa'] = ['x' => round((float) $m['x'], 1), 'y' => round((float) $m['y'], 1)]
                        + array_filter(['kwadrat' => mb_substr(trim((string) ($m['kwadrat'] ?? '')), 0, 12), 'region' => mb_substr(trim((string) ($m['region'] ?? '')), 0, 40)], fn($v) => $v !== '');
                }
                if (isset($p['widoczna_dla'])) {
                    $p['widoczna_dla'] = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 64), (array) $p['widoczna_dla']), fn($x) => $x !== ''));
                    if (!$p['widoczna_dla']) unset($p['widoczna_dla']);
                }
            }
            unset($p);
            // Lokacje: miejsca na planetach z współrzędnymi w km.
            $lokacje = nadaj_id((array) ($w['lokacje'] ?? []));
            foreach ($lokacje as &$l) {
                if (trim((string) ($l['nazwa'] ?? '')) === '') blad('Każda lokacja musi mieć nazwę.');
                $l['planeta'] = katalog_id($planety, (string) ($l['planeta'] ?? ''), 'planeta');
                $l['typ'] = in_array($l['typ'] ?? '', TYPY_LOKACJI, true) ? $l['typ'] : 'inne';
                $l['x'] = round((float) ($l['x'] ?? 0), 2);
                $l['y'] = round((float) ($l['y'] ?? 0), 2);
                $l['publiczna'] = (bool) ($l['publiczna'] ?? true);
                if (isset($l['jakosc'])) $l['jakosc'] = max(1, min(5, (int) $l['jakosc']));
                if (isset($l['paliwo'])) $l['paliwo'] = max(0, (int) $l['paliwo']);
                if (isset($l['cena_paliwa'])) $l['cena_paliwa'] = max(0, (int) $l['cena_paliwa']);
                $l['oferta'] = array_values(array_map(fn($o) => ['nazwa' => mb_substr((string) ($o['nazwa'] ?? '?'), 0, 80), 'cena' => max(0, (int) ($o['cena'] ?? 0))], (array) ($l['oferta'] ?? [])));
            }
            unset($l);
            $tempo = max(0.05, min(60, (float) ($w['tempo'] ?? 1)));
            $instrumenty = gielda_towary_z_katalogu($towary, $gielda['instrumenty'], $gielda['gieldy']);
            $instrumenty = gielda_spolki_do_instrumentow($spolki, $instrumenty, $gielda['gieldy']);
            $nowaGielda = gielda_z_wejscia(['gieldy' => $gielda['gieldy'], 'instrumenty' => $instrumenty, 'ustawienia' => $gielda['ustawienia']]);
            zmien('gielda', function (&$g) use ($nowaGielda) { $g = $nowaGielda; }, []);
            zmien('katalog', function (&$k) use ($towary, $planety, $oferty, $lokacje, $spolki, $npc, $tempo) {
                $k = ['towary' => $towary, 'planety' => $planety, 'oferty' => $oferty, 'lokacje' => $lokacje,
                    'spolki' => $spolki, 'npc' => $npc, 'ustawienia' => ['tempo' => $tempo]];
            });
            firma_uzgodnij_spolki();
            odpowiedz(['ok' => true]);

        case 'mg_kandydaci':
            wymagaj_mg();
            odpowiedz(['kandydaci' => wczytaj('kandydaci', kandydaci_puste())['lista']]);

        case 'mg_kandydaci_zapisz':
            // Pula do zatrudnienia. Wyniki testów („testy”) zostają, edytor ich nie rusza.
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $lista = nadaj_id((array) (wejscie()['kandydaci'] ?? []));
            foreach ($lista as &$k) {
                if (trim((string) ($k['imie'] ?? '')) === '') blad('Każdy kandydat musi mieć imię.');
                $k['pula'] = ($k['pula'] ?? 'ogolna') === 'agencja' ? 'agencja' : 'ogolna';
                $k['pensja'] = max(0, (int) ($k['pensja'] ?? 0));
                $k['statystyki'] = oczysc_statystyki($k['statystyki'] ?? []);
                unset($k['testy']);
            }
            unset($k);
            zmien('kandydaci', function (&$kk) use ($lista) {
                $testy = array_column($kk['lista'], 'testy', 'id');
                foreach ($lista as &$k) {
                    $k['testy'] = $testy[$k['id']] ?? [];
                }
                $kk['lista'] = $lista;
            }, kandydaci_puste());
            odpowiedz(['ok' => true]);

        case 'mg_firmy':
            wymagaj_mg();
            handel_automaty();
            $firmy = firma_uzgodnij_spolki();
            odpowiedz(['firmy' => $firmy, 'katalog' => katalog()]);

        case 'mg_firma_zapisz':
            // Nazwa, profil, członkowie oraz (opcjonalnie) całe dane profilu z edytora JSON.
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $profil = (string) ($w['profil'] ?? '');
            if (!in_array($profil, PROFILE_FIRM, true)) blad('Nieznany profil firmy.');
            $nazwa = mb_substr(trim((string) ($w['nazwa'] ?? '')), 0, 80);
            if ($nazwa === '') blad('Podaj nazwę firmy.');
            $postacie = bank_postacie();
            $czlonkowie = [];
            foreach ((array) ($w['czlonkowie'] ?? []) as $c) {
                if (isset($postacie[$c['uid'] ?? '']) && in_array($c['rola'] ?? '', ROLE_FIRMY, true)) {
                    $czlonkowie[$c['uid']] = ['uid' => $c['uid'], 'rola' => $c['rola']];
                }
            }
            // Dane profilu z edytora MG. Kursy (szlaki) zostają nietknięte: zmienia je tylko gra.
            // Planety i towary można podać po nazwie albo po id.
            $dane = null;
            $fid_zapis = (string) ($w['id'] ?? '');
            if (isset($w['dane'])) {
                $kat = katalog();
                $dane = [];
                foreach (firma_edytowalne($profil) as $k) {
                    if (!isset($w['dane'][$k]) || !is_array($w['dane'][$k])) blad("Brak listy: $k");
                    $dane[$k] = $k === 'magazyn' ? array_values($w['dane'][$k]) : nadaj_id(array_values($w['dane'][$k]));
                }
                foreach ($dane['flota'] as &$st) {
                    $st['nazwa'] = (string) ($st['nazwa'] ?? '') ?: 'Statek bez nazwy';
                    $st['ladownosc'] = max(0, (int) ($st['ladownosc'] ?? 0));
                    $st['stan'] = in_array($st['stan'] ?? '', STANY_STATKU, true) ? $st['stan'] : 'sprawny';
                    $st['lokalizacja'] = ($st['lokalizacja'] ?? '') === '' ? '' : katalog_id($kat['planety'], (string) $st['lokalizacja'], 'planeta');
                    $st['kapitan'] = (string) ($st['kapitan'] ?? '');
                }
                unset($st);
                foreach ($dane['zaloga'] as &$z) {
                    $z['imie'] = (string) ($z['imie'] ?? '') ?: 'Bez imienia';
                    $z['pensja'] = max(0, (int) ($z['pensja'] ?? 0));
                    $z['statek'] = (string) ($z['statek'] ?? '');
                    $z['budynek'] = (string) ($z['budynek'] ?? '');
                    $z['stan'] = in_array($z['stan'] ?? '', STANY_OSOBY, true) ? $z['stan'] : 'zdrowy';
                    $z['statystyki'] = oczysc_statystyki($z['statystyki'] ?? []);
                    $z['kryptonim'] = (string) ($z['kryptonim'] ?? '');
                    // „gracz”: postać gracza, którą ta osoba jest (login albo nazwa postaci) — wtedy
                    // wypłaty trafiają na jej rachunek w banku.
                    $z['gracz'] = (string) ($z['gracz'] ?? '');
                    if ($z['gracz'] !== '') {
                        $znaleziony = '';
                        foreach (uzytkownicy() as $us) {
                            if (in_array(mb_strtolower($z['gracz']), [mb_strtolower($us['login']), mb_strtolower($us['postac']), $us['id']], true)) $znaleziony = $us['id'];
                        }
                        if ($znaleziony === '') blad("Nie ma gracza „{$z['gracz']}”.");
                        $z['gracz'] = $znaleziony;
                    }
                }
                unset($z);
                // Przydział załogi po nazwie statku, kapitan po imieniu.
                foreach ($dane['zaloga'] as &$z) {
                    if ($z['statek'] === '') continue;
                    $z['statek'] = katalog_id($dane['flota'], $z['statek'], 'statek');
                }
                unset($z);
                $osoby = array_map(fn($z) => ['id' => $z['id'], 'nazwa' => $z['imie']], $dane['zaloga']);
                if (isset($dane['budynki'])) {
                    $dane['budynki'] = nadaj_id($dane['budynki']);
                    foreach ($dane['budynki'] as &$b) {
                        $b['nazwa'] = (string) ($b['nazwa'] ?? '') ?: 'Budynek';
                        $b['typ'] = (string) ($b['typ'] ?? '');
                        $b['planeta'] = ($b['planeta'] ?? '') === '' ? '' : katalog_id($kat['planety'], (string) $b['planeta'], 'planeta');
                        $b['pojemnosc'] = max(0, (int) ($b['pojemnosc'] ?? 0));
                        $b['pracownicy'] = max(0, min($b['pojemnosc'], (int) ($b['pracownicy'] ?? 0)));
                        $b['pensja'] = max(0, (int) ($b['pensja'] ?? 0));
                        $b['menedzer'] = ($b['menedzer'] ?? '') === '' ? '' : katalog_id($osoby, (string) $b['menedzer'], 'osoba w załodze');
                        budynek_wymiary($b);
                    }
                    unset($b);
                    foreach ($dane['zaloga'] as &$z) {
                        if ($z['budynek'] !== '') $z['budynek'] = katalog_id($dane['budynki'], $z['budynek'], 'budynek');
                        foreach ($dane['budynki'] as $b) {
                            if ($b['menedzer'] === $z['id']) { $z['budynek'] = $b['id']; $z['statek'] = ''; }
                        }
                    }
                    unset($z);
                }
                foreach ($dane['flota'] as &$st) {
                    if ($st['kapitan'] === '') continue;
                    $st['kapitan'] = katalog_id($osoby, $st['kapitan'], 'osoba w załodze');
                    foreach ($dane['zaloga'] as &$z) {
                        if ($z['id'] === $st['kapitan']) $z['statek'] = $st['id'];
                    }
                    unset($z);
                }
                unset($st);
                // Statki: hangar (lokacja po nazwie) i paliwo w zbiorniku (%). Pojazdy firmy: lokacja, kierowca.
                $lok = ruch_lokacje();
                foreach ($dane['budynki'] ?? [] as $b) {
                    if (isset($b['x'], $b['y']) && $fid_zapis !== '') $lok['b:' . $fid_zapis . ':' . $b['id']] = ['nazwa' => $b['nazwa'], 'planeta' => $b['planeta']];
                }
                foreach ($dane['flota'] as &$st) {
                    $st['hangar'] = ruch_klucz($lok, (string) ($st['hangar'] ?? ''));
                    $st['paliwo'] = max(0, min(100, (int) ($st['paliwo'] ?? 100)));
                }
                unset($st);
                $dane['pojazdy'] = nadaj_id(array_values((array) ($dane['pojazdy'] ?? [])));
                foreach ($dane['pojazdy'] as &$po) {
                    $po['nazwa'] = (string) ($po['nazwa'] ?? '') ?: 'Pojazd';
                    $po['typ'] = (string) ($po['typ'] ?? 'spider');
                    $po['predkosc'] = max(10, min(2000, (int) ($po['predkosc'] ?? 200)));
                    $po['lokacja'] = ruch_klucz($lok, (string) ($po['lokacja'] ?? ''));
                    $po['kierowca'] = ($po['kierowca'] ?? '') === '' ? '' : katalog_id($osoby, (string) $po['kierowca'], 'osoba w załodze (kierowca)');
                }
                unset($po);
                foreach ($dane['magazyn'] as &$m) {
                    $m = [
                        'planeta' => katalog_id($kat['planety'], (string) ($m['planeta'] ?? ''), 'planeta'),
                        'towar' => katalog_id($kat['towary'], (string) ($m['towar'] ?? ''), 'towar'),
                        'ilosc' => max(0, (int) ($m['ilosc'] ?? 0)),
                    ];
                }
                unset($m);
                if (isset($dane['zaklady'])) {
                    $dane['zaklady'] = nadaj_id($dane['zaklady']);
                    foreach ($dane['zaklady'] as &$z) {
                        $z['nazwa'] = mb_substr(trim((string) ($z['nazwa'] ?? '')), 0, 80);
                        if ($z['nazwa'] === '') blad('Każdy zakład musi mieć nazwę.');
                        $z['typ'] = mb_substr(trim((string) ($z['typ'] ?? '')), 0, 40);
                        $z['planeta'] = ($z['planeta'] ?? '') === '' ? '' : katalog_id($kat['planety'], (string) $z['planeta'], 'planeta');
                        $z['towar'] = ($z['towar'] ?? '') === '' ? '' : katalog_id($kat['towary'], (string) $z['towar'], 'towar');
                        $z['wydajnosc'] = max(0, (int) ($z['wydajnosc'] ?? 0));
                        $z['stan'] = in_array($z['stan'] ?? '', ['czynny', 'wstrzymany', 'zamkniety'], true) ? $z['stan'] : 'czynny';
                        $z['opis'] = mb_substr(trim((string) ($z['opis'] ?? '')), 0, 500);
                    }
                    unset($z);
                }
            }
            $fid = (string) ($w['id'] ?? '');
            $wynik = zmien('firmy', function (&$wszystko) use ($fid, $profil, $nazwa, $czlonkowie, $dane) {
                foreach ($wszystko['firmy'] as &$f) {
                    if ($f['id'] === $fid) {
                        if ($f['profil'] !== $profil) blad('Profilu istniejącej firmy nie można zmienić.');
                        $f['nazwa'] = $nazwa;
                        $f['czlonkowie'] = array_values($czlonkowie);
                        if ($dane !== null) {
                            // Trwająca obsługa naziemna statków nie jest w edytorze MG — zachowujemy ją.
                            $stare = array_column($f['dane']['flota'] ?? [], 'przygotowanie', 'id');
                            foreach ($dane['flota'] as &$st) {
                                if (!isset($st['przygotowanie']) && isset($stare[$st['id']])) $st['przygotowanie'] = $stare[$st['id']];
                            }
                            unset($st);
                            $f['dane'] = array_merge($f['dane'], $dane);
                        }
                        return $f['id'];
                    }
                }
                unset($f);
                $nowa = ['id' => id(), 'nazwa' => $nazwa, 'profil' => $profil, 'czlonkowie' => array_values($czlonkowie),
                    'dane' => array_merge(firma_dane_puste($profil), $dane ?? [])];
                $wszystko['firmy'][] = $nowa;
                return $nowa['id'];
            }, firmy_puste());
            odpowiedz(['ok' => true, 'id' => $wynik]);

        case 'mg_firma_usun':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $fid = pole('id', 32);
            zmien('firmy', function (&$wszystko) use ($fid) {
                $wszystko['firmy'] = array_values(array_filter($wszystko['firmy'], fn($f) => $f['id'] !== $fid));
            }, firmy_puste());
            odpowiedz(['ok' => true]);

        // Decyzja MG o zgłoszonym kursie: przyjęcie (w realizacji), zakończenie (rozliczenie magazynów
        // i położenia statku) albo odrzucenie z uwagą dla graczy.
        case 'mg_szlak_status':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $status = (string) ($w['status'] ?? '');
            if (!in_array($status, ['w_realizacji', 'zakonczony', 'odrzucony'], true)) blad('Nieprawidłowy status.');
            $uwagi = mb_substr(trim((string) ($w['uwagi'] ?? '')), 0, 500);
            zmien('firmy', function (&$wszystko) use ($w, $status, $uwagi) {
                foreach ($wszystko['firmy'] as &$f) {
                    if ($f['id'] !== ($w['firma'] ?? '')) continue;
                    foreach ($f['dane']['szlaki'] as &$s) {
                        if ($s['id'] !== ($w['szlak'] ?? '')) continue;
                        $dozwolone = ['zgloszony' => ['w_realizacji', 'odrzucony'], 'w_realizacji' => ['zakonczony', 'odrzucony'], 'wstrzymany' => ['zakonczony', 'odrzucony']];
                        if (!in_array($status, $dozwolone[$s['status']] ?? [], true)) blad('Tej zmiany statusu nie można wykonać.');
                        // Magazyny mogły się zmienić od zgłoszenia (np. inny kurs zabrał towar),
                        // więc przed przyjęciem i przed rozliczeniem plan jest sprawdzany jeszcze raz.
                        // (Zatrzymanie automatu niczego nie rozlicza, więc go nie sprawdzamy.)
                        if ($status === 'w_realizacji' || ($status === 'zakonczony' && empty($s['auto']))) {
                            $problemy = transport_sprawdz_szlak($s, $f['dane'], katalog(), $s['status'] === 'w_realizacji');
                            if ($problemy) blad('Plan nie zgadza się z obecnym stanem: ' . implode(' ', $problemy) . ' Odrzuć kurs z uwagą albo popraw magazyn.');
                        }
                        if ($status === 'w_realizacji') {
                            transport_ustaw_stan_statku($f['dane'], $s['statek'], 'w trasie');
                            if (!empty($s['auto'])) $s['nastepny'] = date('c', time() + $s['co_dni'] * 86400);
                        }
                        if ($status === 'odrzucony' && $s['status'] === 'w_realizacji') transport_ustaw_stan_statku($f['dane'], $s['statek'], 'sprawny');
                        if ($status === 'zakonczony' && !empty($s['auto'])) {
                            transport_ustaw_stan_statku($f['dane'], $s['statek'], 'sprawny'); // zatrzymanie automatu
                        } elseif ($status === 'zakonczony') {
                            $wpis = handel_wykonaj($f, $s, date('Y-m-d'), true);
                            if (!$wpis['ok']) blad('Nie można rozliczyć kursu: ' . $wpis['opis']);
                            $s['dziennik'][] = $wpis;
                        }
                        $s['status'] = $status;
                        $s['uwagi'] = $uwagi;
                        $s['zmieniono'] = date('c');
                        return;
                    }
                }
                blad('Nie ma takiego kursu.', 404);
            }, firmy_puste());
            odpowiedz(['ok' => true]);
    }
}

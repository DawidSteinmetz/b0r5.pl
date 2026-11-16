<?php
// Karta postaci: tożsamość, pozycja, statystyki, reputacja, kontakty, ekwipunek, notatnik.
//
// Wszystko poza notatnikiem ustawia MG. Struktura statystyk jest celowo otwarta (MG wpisuje
// własne nazwy cech, umiejętności i liczników), bo system gry może się jeszcze zmienić.
//
// Pozycja: planeta (z katalogu), współrzędne (tekst, np. siatka galaktyczna — mapa doda
// później współrzędne liczbowe), miejsce (np. „pokój 14, hotel Pod Bantą”) i opcjonalnie
// budynek z dowolnej firmy (np. biuro przy kopalni) — wtedy karta pokazuje dane budynku.

declare(strict_types=1);

const STATUSY_PRAWNE = ['czysty', 'notowany', 'poszukiwany'];

function karta_pusta(): array
{
    return [
        'statystyki' => ['atrybuty' => [], 'umiejetnosci' => [], 'liczniki' => []],
        'reputacja' => [],
        'status' => ['stan' => 'czysty', 'opis' => '', 'nagroda' => 0, 'zlecajacy' => ''],
        'dokumenty' => [],
        'kontakty' => [],
        'ekwipunek' => [],
        'pozycja' => ['planeta' => '', 'wspolrzedne' => '', 'miejsce' => '', 'budynek' => '', 'lokacja' => ''],
        'notatki' => '',
        'gotowka' => 0,
        'pojazdy' => [],   // prywatne: { nazwa, typ, predkosc (km/h), lokacja (klucz) }
        'znane' => [],     // nazwy albo klucze lokacji, które postać zna (poza publicznymi)
    ];
}

// Pojazdy prywatne z edytora MG: lokacja po nazwie albo kluczu.
function karta_pojazdy(array $lista): array
{
    $lok = ruch_lokacje();
    $wynik = [];
    foreach (array_slice($lista, 0, 20) as $p) {
        if (!is_array($p) || trim((string) ($p['nazwa'] ?? '')) === '') continue;
        $wynik[] = [
            'nazwa' => mb_substr(trim($p['nazwa']), 0, 60),
            'typ' => mb_substr(trim((string) ($p['typ'] ?? 'spider')), 0, 40),
            'predkosc' => max(10, min(2000, (int) ($p['predkosc'] ?? 200))),
            'lokacja' => ruch_klucz($lok, (string) ($p['lokacja'] ?? '')),
        ];
    }
    return $wynik;
}

function karta(string $uid): array
{
    $k = wczytaj('karty', [])[$uid] ?? [];
    return array_replace_recursive(karta_pusta(), $k);
}

// Wszystkie budynki wszystkich firm: klucz „idFirmy:idBudynku”.
function wszystkie_budynki(): array
{
    $b = [];
    foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
        foreach ($f['dane']['budynki'] ?? [] as $x) {
            $b[$f['id'] . ':' . $x['id']] = $x + ['firma' => $f['nazwa']];
        }
    }
    return $b;
}

// Tekst „klucz = wartość” (po jednym w linii) na listę par — format edytora MG.
function pary_z_tekstu(string $tekst, int $limit = 100): array
{
    $wynik = [];
    foreach (preg_split('/\R/u', $tekst) as $linia) {
        $linia = trim($linia);
        if ($linia === '') continue;
        [$a, $b] = array_pad(array_map('trim', explode('=', $linia, 2)), 2, '');
        $wynik[] = ['nazwa' => mb_substr($a, 0, 80), 'opis' => mb_substr($b, 0, 300)];
    }
    return array_slice($wynik, 0, $limit);
}

function obsluz_karty(string $akcja, bool $post): void
{
    switch ($akcja) {

        case 'karta':
            $u = wymagaj_logowania();
            ruch_dokoncz($u['id']);
            $k = karta($u['id']);
            // Pozycja w czytelnej postaci.
            $kat = katalog();
            $planeta = null;
            foreach ($kat['planety'] as $p) {
                if ($p['id'] === $k['pozycja']['planeta']) $planeta = ['nazwa' => $p['nazwa'], 'sektor' => $p['sektor'] ?? ''];
            }
            $budynek = $k['pozycja']['budynek'] !== '' ? (wszystkie_budynki()[$k['pozycja']['budynek']] ?? null) : null;
            if ($budynek) {
                $pl = $budynek['planeta'] ?? '';
                $budynek['planeta'] = '';
                foreach ($kat['planety'] as $p) {
                    if ($p['id'] === $pl) $budynek['planeta'] = $p['nazwa'];
                }
                unset($budynek['menedzer'], $budynek['id']);
            }
            // Szybki podgląd: saldo i firmy postaci.
            $bank = wczytaj('bank', ['konta' => []]);
            $firmy = [];
            foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
                foreach ($f['czlonkowie'] as $c) {
                    if ($c['uid'] === $u['id']) $firmy[] = ['nazwa' => $f['nazwa'], 'rola' => $c['rola']];
                }
            }
            odpowiedz([
                'karta' => $k,
                'planeta' => $planeta,
                'budynek' => $budynek,
                'saldo' => $bank['konta'][$u['id']]['saldo'] ?? 0,
                'firmy' => $firmy,
                'scena' => (wczytaj('sceny', [])[$u['id']] ?? []) + ['scena' => 'kosmos'],
            ]);

        case 'karta_notatki':
            // Notatnik: jedyna część karty, którą pisze sam gracz.
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $tekst = mb_substr((string) (wejscie()['notatki'] ?? ''), 0, 20000);
            zmien('karty', function (&$k) use ($u, $tekst) {
                $k[$u['id']]['notatki'] = $tekst;
            });
            odpowiedz(['ok' => true]);

        // ----- panel MG -----

        case 'mg_karty':
            wymagaj_mg();
            $lista = [];
            foreach (uzytkownicy() as $us) {
                if (($us['rola'] ?? '') === 'mg') continue;
                $lista[] = ['uid' => $us['id'], 'postac' => $us['postac'], 'karta' => karta($us['id'])];
            }
            $budynki = [];
            foreach (wszystkie_budynki() as $klucz => $b) {
                $budynki[] = ['klucz' => $klucz, 'nazwa' => $b['nazwa'], 'firma' => $b['firma']];
            }
            $nazwyPlanet = array_column(katalog()['planety'], 'nazwa', 'id');
            $lokacje = [];
            foreach (ruch_lokacje() as $klucz => $l) {
                $lokacje[] = ['klucz' => $klucz, 'nazwa' => $l['nazwa'], 'planeta' => $nazwyPlanet[$l['planeta']] ?? '?', 'typ' => $l['typ']];
            }
            $nazwyLok = array_column($lokacje, 'nazwa', 'klucz');
            foreach ($lista as &$x) {
                $x['karta']['pojazdy'] = array_map(fn($p) => ['lokacja' => $nazwyLok[$p['lokacja'] ?? ''] ?? ''] + $p, $x['karta']['pojazdy']);
                $x['karta']['znane'] = array_map(fn($z) => $nazwyLok[$z] ?? $z, $x['karta']['znane'] ?? []);
            }
            unset($x);
            odpowiedz(['postacie' => $lista, 'budynki' => $budynki, 'lokacje' => $lokacje]);

        case 'mg_karta_zapisz':
            // Wszystko poza pozycją i notatnikiem (pozycję ustawia się razem ze sceną).
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $uid = (string) ($w['uid'] ?? '');
            if (!in_array($uid, array_column(uzytkownicy(), 'id'), true)) blad('Nie ma takiej postaci.', 404);
            $liczby = function ($mapa) {
                $wynik = [];
                foreach ((array) $mapa as $n => $v) {
                    $wynik[mb_substr((string) $n, 0, 40)] = max(-99, min(99, (int) $v));
                }
                return array_slice($wynik, 0, 60, true);
            };
            $liczniki = [];
            foreach ((array) ($w['statystyki']['liczniki'] ?? []) as $n => $v) {
                $v = array_values((array) $v);
                $liczniki[mb_substr((string) $n, 0, 40)] = [max(0, (int) ($v[0] ?? 0)), max(1, (int) ($v[1] ?? 1))];
            }
            $reputacja = [];
            foreach ((array) ($w['reputacja'] ?? []) as $n => $v) {
                $reputacja[mb_substr((string) $n, 0, 60)] = max(-100, min(100, (int) $v));
            }
            $stan = (string) ($w['status']['stan'] ?? 'czysty');
            $dane = [
                'statystyki' => ['atrybuty' => $liczby($w['statystyki']['atrybuty'] ?? []), 'umiejetnosci' => $liczby($w['statystyki']['umiejetnosci'] ?? []), 'liczniki' => $liczniki],
                'reputacja' => $reputacja,
                'status' => [
                    'stan' => in_array($stan, STATUSY_PRAWNE, true) ? $stan : 'czysty',
                    'opis' => mb_substr(trim((string) ($w['status']['opis'] ?? '')), 0, 500),
                    'nagroda' => max(0, (int) ($w['status']['nagroda'] ?? 0)),
                    'zlecajacy' => mb_substr(trim((string) ($w['status']['zlecajacy'] ?? '')), 0, 80),
                ],
                'dokumenty' => pary_z_tekstu((string) ($w['dokumenty'] ?? '')),
                'kontakty' => pary_z_tekstu((string) ($w['kontakty'] ?? '')),
                'ekwipunek' => pary_z_tekstu((string) ($w['ekwipunek'] ?? ''), 200),
                'gotowka' => max(0, (int) ($w['gotowka'] ?? 0)),
                'znane' => array_values(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 80), preg_split('/\R/u', (string) ($w['znane'] ?? ''))))),
                'pojazdy' => karta_pojazdy((array) ($w['pojazdy'] ?? [])),
            ];
            zmien('karty', function (&$k) use ($uid, $dane) {
                $k[$uid] = array_merge($k[$uid] ?? [], $dane);
            });
            odpowiedz(['ok' => true]);

        case 'mg_pozycja_ustaw':
            // Pozycja dla wielu postaci naraz (jak scena). Pusta planeta = bez zmian planety.
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $uids = array_values(array_intersect((array) ($w['uids'] ?? []), array_column(uzytkownicy(), 'id')));
            if (!$uids) blad('Wybierz co najmniej jedną postać.');
            $planeta = trim((string) ($w['planeta'] ?? ''));
            $planeta = $planeta === '' ? '' : katalog_id(katalog()['planety'], $planeta, 'planeta');
            $budynek = (string) ($w['budynek'] ?? '');
            if ($budynek !== '' && !isset(wszystkie_budynki()[$budynek])) blad('Nie ma takiego budynku.');
            // Lokacja (miejsce z katalogu albo budynek z x, y): ustala też planetę i budynek.
            $lokacja = (string) ($w['lokacja'] ?? '');
            $lok = ruch_lokacje();
            if ($lokacja !== '' && !isset($lok[$lokacja])) blad('Nie ma takiej lokacji.');
            if ($lokacja !== '') {
                $planeta = $lok[$lokacja]['planeta'];
                if (str_starts_with($lokacja, 'b:')) $budynek = substr($lokacja, 2);
            }
            $poz = [
                'planeta' => $planeta,
                'wspolrzedne' => pole('wspolrzedne', 80),
                'miejsce' => pole('miejsce', 120) ?: ($lokacja !== '' ? $lok[$lokacja]['nazwa'] : ''),
                'budynek' => $budynek,
                'lokacja' => $lokacja,
                'zmieniono' => teraz_ms(),
            ];
            zmien('karty', function (&$k) use ($uids, $poz) {
                foreach ($uids as $uid) {
                    $k[$uid]['pozycja'] = $poz;
                    unset($k[$uid]['podroz']); // MG przestawia postać: przerwana podróż
                }
            });
            odpowiedz(['ok' => true]);
    }
}

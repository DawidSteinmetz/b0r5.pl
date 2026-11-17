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

// Pozycja ma już zapis relacyjny; stary UID tekstowy pozostaje jako most do czasu
// migracji kont i postaci do kluczy numerycznych.
function pozycja_sql_zapisz(string $uid, array $poz): bool
{
    if (!tabela_sql_dostepna('datapad_pozycje_legacy')) return false;
    $db = polaczenie_bazy();
    $planetaLegacy = trim((string) ($poz['planeta'] ?? ''));
    $planetaId = null;
    if ($planetaLegacy !== '') {
        if (ctype_digit($planetaLegacy)) {
            $q = $db->prepare('SELECT id FROM mapa_planety WHERE id = ?');
            $q->execute([(int) $planetaLegacy]);
            $planetaId = $q->fetchColumn();
        }
        if (!$planetaId) {
            foreach (katalog()['planety'] ?? [] as $p) {
                if (($p['id'] ?? '') === $planetaLegacy) {
                    $q = $db->prepare('SELECT id FROM mapa_planety WHERE nazwa = ?');
                    $q->execute([(string) ($p['nazwa'] ?? '')]);
                    $planetaId = $q->fetchColumn();
                    break;
                }
            }
        }
        if (!$planetaId) {
            $q = $db->prepare('SELECT id FROM mapa_planety WHERE nazwa = ?');
            $q->execute([$planetaLegacy]);
            $planetaId = $q->fetchColumn();
        }
    }
    $lokacja = trim((string) ($poz['lokacja'] ?? ''));
    $lokacjaId = null;
    if (preg_match('/^sql:(\d+)$/', $lokacja, $m)) $lokacjaId = (int) $m[1];
    elseif (str_starts_with($lokacja, 'l:')) {
        $legacy = substr($lokacja, 2);
        $q = $db->prepare('SELECT id FROM lokalizacje WHERE nazwa = ? AND planeta_id <=> ? LIMIT 1');
        $q->execute([$legacy, $planetaId ?: null]);
        $lokacjaId = $q->fetchColumn() ?: null;
    }
    $q = $db->prepare('INSERT INTO datapad_pozycje_legacy (legacy_uid, planeta_id, planeta_legacy_id, lokalizacja_id, lokalizacja_legacy, wspolrzedne_tekst, miejsce_opis, budynek_legacy, zmieniono_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE planeta_id=VALUES(planeta_id), planeta_legacy_id=VALUES(planeta_legacy_id), lokalizacja_id=VALUES(lokalizacja_id), lokalizacja_legacy=VALUES(lokalizacja_legacy), wspolrzedne_tekst=VALUES(wspolrzedne_tekst), miejsce_opis=VALUES(miejsce_opis), budynek_legacy=VALUES(budynek_legacy), zmieniono_ms=VALUES(zmieniono_ms)');
    $q->execute([$uid, $planetaId ?: null, $planetaLegacy ?: null, $lokacjaId, $lokacjaId ? null : ($lokacja ?: null), (string) ($poz['wspolrzedne'] ?? ''), (string) ($poz['miejsce'] ?? ''), (string) ($poz['budynek'] ?? ''), max(0, (int) ($poz['zmieniono'] ?? teraz_ms()))]);
    return true;
}

function pozycja_sql_odczytaj(string $uid): ?array
{
    if (!tabela_sql_dostepna('datapad_pozycje_legacy')) return null;
    $q = polaczenie_bazy()->prepare('SELECT planeta_id, planeta_legacy_id, lokalizacja_id, lokalizacja_legacy, wspolrzedne_tekst, miejsce_opis, budynek_legacy, zmieniono_ms FROM datapad_pozycje_legacy WHERE legacy_uid = ?');
    $q->execute([$uid]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    return [
        'planeta' => (string) ($r['planeta_legacy_id'] ?? $r['planeta_id'] ?? ''),
        'wspolrzedne' => (string) $r['wspolrzedne_tekst'],
        'miejsce' => (string) $r['miejsce_opis'],
        'budynek' => (string) $r['budynek_legacy'],
        'lokacja' => $r['lokalizacja_id'] ? 'sql:' . $r['lokalizacja_id'] : (string) ($r['lokalizacja_legacy'] ?? ''),
        'zmieniono' => (int) $r['zmieniono_ms'],
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
    $k = array_replace_recursive(karta_pusta(), $k);
    $sql = pozycja_sql_odczytaj($uid);
    if ($sql !== null) {
        $k['pozycja'] = array_replace($k['pozycja'], $sql);
    } else {
        pozycja_sql_zapisz($uid, $k['pozycja']);
    }
    return $k;
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
    if (tabela_sql_dostepna('budynki') && tabela_sql_dostepna('lokalizacje')) {
        $sql = polaczenie_bazy()->query('SELECT b.lokalizacja_id, l.nazwa, l.planeta_id, p.nazwa AS planeta_nazwa, l.x_km, l.y_km, l.opis FROM budynki b JOIN lokalizacje l ON l.id=b.lokalizacja_id LEFT JOIN mapa_planety p ON p.id=l.planeta_id ORDER BY l.id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sql as $x) {
            $key = 'sql:' . $x['lokalizacja_id'];
            $b[$key] = ['id' => $key, 'nazwa' => $x['nazwa'], 'firma' => 'Administracja planety', 'planeta' => (string) ($x['planeta_id'] ?? ''), 'planeta_nazwa' => $x['planeta_nazwa'] ?? '', 'x' => $x['x_km'], 'y' => $x['y_km'], 'opis' => $x['opis'] ?? ''];
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
            if (!$planeta && ctype_digit((string) $k['pozycja']['planeta'])) {
                $q = polaczenie_bazy()->prepare('SELECT nazwa, region FROM mapa_planety WHERE id=?');
                $q->execute([(int) $k['pozycja']['planeta']]);
                if ($p = $q->fetch(PDO::FETCH_ASSOC)) $planeta = ['nazwa' => $p['nazwa'], 'sektor' => 'Region ' . $p['region']];
            }
            $budynek = $k['pozycja']['budynek'] !== '' ? (wszystkie_budynki()[$k['pozycja']['budynek']] ?? null) : null;
            if ($budynek) {
                $pl = $budynek['planeta'] ?? '';
                $budynek['planeta'] = $budynek['planeta_nazwa'] ?? '';
                foreach ($kat['planety'] as $p) if ($p['id'] === $pl) $budynek['planeta'] = $p['nazwa'];
                unset($budynek['planeta_nazwa']);
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
            if (tabela_sql_dostepna('mapa_planety')) {
                foreach (polaczenie_bazy()->query('SELECT id,nazwa FROM mapa_planety')->fetchAll(PDO::FETCH_ASSOC) as $p) $nazwyPlanet[(string) $p['id']] = $p['nazwa'];
            }
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
            if ($planeta !== '') {
                $found = null;
                foreach (katalog()['planety'] as $p) if (($p['id'] ?? '') === $planeta || mb_strtolower($p['nazwa']) === mb_strtolower($planeta)) { $found = $p['id']; break; }
                if ($found !== null) $planeta = $found;
                elseif (tabela_sql_dostepna('mapa_planety')) {
                    $q = polaczenie_bazy()->prepare('SELECT id FROM mapa_planety WHERE CAST(id AS CHAR)=? OR LOWER(nazwa)=LOWER(?)');
                    $q->execute([$planeta, $planeta]);
                    $found = $q->fetchColumn();
                    if ($found === false) blad('Nie ma takiej planety.');
                    $planeta = (string) $found;
                } else $planeta = katalog_id(katalog()['planety'], $planeta, 'planeta');
            }
            $budynek = (string) ($w['budynek'] ?? '');
            if ($budynek !== '' && !isset(wszystkie_budynki()[$budynek])) blad('Nie ma takiego budynku.');
            // Lokacja (miejsce z katalogu albo budynek z x, y): ustala też planetę i budynek.
            $lokacja = (string) ($w['lokacja'] ?? '');
            $lok = ruch_lokacje();
            if ($lokacja !== '' && !isset($lok[$lokacja])) blad('Nie ma takiej lokacji.');
            if ($lokacja !== '') {
                $planeta = $lok[$lokacja]['planeta'];
                if (str_starts_with($lokacja, 'b:')) $budynek = substr($lokacja, 2);
                elseif (str_starts_with($lokacja, 'sql:') && !empty($lok[$lokacja]['jest_budynkiem'])) $budynek = $lokacja;
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
            foreach ($uids as $uid) pozycja_sql_zapisz($uid, $poz);
            odpowiedz(['ok' => true]);
    }
}

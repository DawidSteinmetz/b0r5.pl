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

function mapa_obiekt_postac_zapisz(string $uid, array $poz, ?int $planetaId): void
{
    if (!tabela_sql_dostepna('mapa_obiekty')) return;
    $u = null;
    foreach (uzytkownicy() as $x) if (($x['id'] ?? '') === $uid) { $u = $x; break; }
    if (!$u || ($u['rola'] ?? '') === 'mg') return;
    $lokacja = trim((string) ($poz['lokacja'] ?? ''));
    $okretId = preg_match('/^okret:(\d+)$/', $lokacja, $m) ? (int) $m[1] : null;
    $x = $y = null;
    if ($okretId) {
        $q = polaczenie_bazy()->prepare("SELECT planeta_id,x,y FROM mapa_obiekty WHERE id=? AND typ='statek' AND aktywny=1");
        $q->execute([$okretId]);
        if ($r = $q->fetch(PDO::FETCH_ASSOC)) { $planetaId = $r['planeta_id'] ? (int)$r['planeta_id'] : null; $x=$r['x']; $y=$r['y']; }
        else $okretId = null;
    }
    if ($x === null && $planetaId) {
        $q = polaczenie_bazy()->prepare('SELECT x,y FROM mapa_planety WHERE id=?');
        $q->execute([$planetaId]);
        if ($r = $q->fetch(PDO::FETCH_ASSOC)) { $x = $r['x']; $y = $r['y']; }
    }
    if ($x === null) {
        foreach (katalog()['planety'] as $p) {
            if (($p['id'] ?? '') !== (string) ($poz['planeta'] ?? '') || !isset($p['mapa']['x'], $p['mapa']['y'])) continue;
            $x = (float) $p['mapa']['x']; $y = (float) $p['mapa']['y']; break;
        }
    }
    $aktywny = $x !== null && $y !== null;
    $q = polaczenie_bazy()->prepare("INSERT INTO mapa_obiekty (kod,typ,legacy_uid,nazwa,rodzaj,wlasciciel,status,planeta_id,na_pokladzie_id,x,y,kolor,znacznik,opis,aktywny) VALUES (?, 'postac', ?, ?, 'postać gracza', ?, ?, ?, ?, ?, ?, '#4de2c9', 'kolo', ?, ?) ON DUPLICATE KEY UPDATE nazwa=VALUES(nazwa), wlasciciel=VALUES(wlasciciel), status=VALUES(status), planeta_id=VALUES(planeta_id), na_pokladzie_id=VALUES(na_pokladzie_id), x=VALUES(x), y=VALUES(y), opis=VALUES(opis), aktywny=VALUES(aktywny)");
    $q->execute(['postac-' . $uid, $uid, (string) ($u['postac'] ?? $u['login']), (string) ($u['frakcja'] ?? ''), trim((string) (($u['oddzial'] ?? '') . ' ' . ($u['ranga'] ?? ''))), $planetaId, $okretId, $x, $y, trim((string) ($poz['miejsce'] ?? '')), $aktywny ? 1 : 0]);
}

// Pozycja ma już zapis relacyjny; stary UID tekstowy pozostaje jako most do czasu
// migracji kont i postaci do kluczy numerycznych.
function pozycja_sql_zapisz(string $uid, array $poz): bool
{
    if (!tabela_sql_dostepna('datapad_pozycje_legacy')) return false;
    $db = polaczenie_bazy();
    $lokacja = trim((string) ($poz['lokacja'] ?? ''));
    $planetaLegacy = trim((string) ($poz['planeta'] ?? ''));
    $planetaId = null;
    if (preg_match('/^okret:(\d+)$/', $lokacja, $m)) {
        $q = $db->prepare("SELECT planeta_id FROM mapa_obiekty WHERE id=? AND typ='statek' AND aktywny=1");
        $q->execute([(int)$m[1]]);
        $planetaId = $q->fetchColumn();
        $planetaLegacy = $planetaId ? (string)$planetaId : '';
    }
    if (!$planetaId && $planetaLegacy !== '') {
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
    mapa_obiekt_postac_zapisz($uid, $poz, $planetaId ? (int) $planetaId : null);
    return true;
}

function mapa_obiekt_npc_pozycja_zapisz(int $npcId, string $lokacja, string $planeta, string $miejsce): void
{
    if (!tabela_sql_dostepna('mapa_obiekty')) return;
    $db = polaczenie_bazy();
    $q = $db->prepare("SELECT p.nazwa,o.rola,o.stan,COALESCE(f.nazwa,'') AS frakcja
        FROM podmioty p JOIN osoby o ON o.podmiot_id=p.id LEFT JOIN podmioty f ON f.id=o.frakcja_id WHERE p.id=? AND o.postac_id IS NULL");
    $q->execute([$npcId]);
    $npc = $q->fetch(PDO::FETCH_ASSOC);
    if (!$npc) blad('Nie znaleziono NPC.', 404);
    $okretId = preg_match('/^okret:(\d+)$/', $lokacja, $m) ? (int)$m[1] : null;
    $planetaId = null;
    if ($okretId) {
        $q = $db->prepare("SELECT planeta_id,x,y FROM mapa_obiekty WHERE id=? AND typ='statek' AND aktywny=1");
        $q->execute([$okretId]); $ship = $q->fetch(PDO::FETCH_ASSOC);
        if (!$ship) blad('Wybrany okręt nie jest dostępny.', 404);
        $planetaId = $ship['planeta_id'] ?: null; $x = $ship['x']; $y = $ship['y'];
    } else {
        $q = $db->prepare('SELECT id,x,y FROM mapa_planety WHERE CAST(id AS CHAR)=? OR LOWER(nazwa)=LOWER(?) LIMIT 1');
        $q->execute([$planeta,$planeta]); $pl=$q->fetch(PDO::FETCH_ASSOC);
        $planetaId=$pl ? (int)$pl['id'] : null; $x=$pl['x'] ?? null; $y=$pl['y'] ?? null;
    }
    $q = $db->prepare("INSERT INTO mapa_obiekty (kod,typ,npc_podmiot_id,nazwa,rodzaj,wlasciciel,status,planeta_id,na_pokladzie_id,x,y,kolor,znacznik,opis,aktywny)
        VALUES (?,'postac',?,?, 'NPC',?,?,?,?,?,?, '#f2c14e','kolo',?,1)
        ON DUPLICATE KEY UPDATE nazwa=VALUES(nazwa),wlasciciel=VALUES(wlasciciel),status=VALUES(status),planeta_id=VALUES(planeta_id),na_pokladzie_id=VALUES(na_pokladzie_id),x=VALUES(x),y=VALUES(y),opis=VALUES(opis),aktywny=1");
    $q->execute(['npc-'.$npcId,$npcId,$npc['nazwa'],$npc['frakcja'],$npc['stan'].' · '.$npc['rola'],$planetaId,$okretId,$x,$y,$miejsce]);
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
    }
    // Równoległe utrzymanie indeksu mapy obejmuje też pozycje zapisane przed migracją 005.
    pozycja_sql_zapisz($uid, $k['pozycja']);
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
                $lokacje[] = ['klucz' => $klucz, 'nazwa' => $l['nazwa'], 'planeta' => $nazwyPlanet[$l['planeta']] ?? ($l['planeta_nazwa'] ?? '?'), 'typ' => $l['typ']];
            }
            $nazwyLok = array_column($lokacje, 'nazwa', 'klucz');
            foreach ($lista as &$x) {
                $x['karta']['pojazdy'] = array_map(fn($p) => ['lokacja' => $nazwyLok[$p['lokacja'] ?? ''] ?? ''] + $p, $x['karta']['pojazdy']);
                $x['karta']['znane'] = array_map(fn($z) => $nazwyLok[$z] ?? $z, $x['karta']['znane'] ?? []);
            }
            unset($x);
            $npc = [];
            if (tabela_sql_dostepna('osoby') && tabela_sql_dostepna('podmioty')) {
                $npc = polaczenie_bazy()->query("SELECT p.id,p.nazwa,o.rola,o.stan,COALESCE(f.nazwa,'') AS frakcja
                    FROM osoby o JOIN podmioty p ON p.id=o.podmiot_id LEFT JOIN podmioty f ON f.id=o.frakcja_id
                    WHERE o.postac_id IS NULL AND p.status='aktywny' ORDER BY p.nazwa")->fetchAll(PDO::FETCH_ASSOC);
            }
            odpowiedz(['postacie' => $lista, 'npc' => $npc, 'budynki' => $budynki, 'lokacje' => $lokacje]);

        case 'mg_mapa_obiekty':
            wymagaj_mg();
            foreach (uzytkownicy() as $konto) if (($konto['rola'] ?? '') !== 'mg') karta((string) $konto['id']);
            $odbiorcy = array_values(array_map(static fn($x) => ['login' => $x['login'], 'postac' => $x['postac']], array_filter(uzytkownicy(), static fn($x) => ($x['rola'] ?? '') !== 'mg')));
            odpowiedz(['obiekty' => mapa_obiekty_mg(), 'konta' => $odbiorcy]);

        case 'mg_postac_swoboda':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $stan = (string) ($w['stan'] ?? '');
            if (!in_array($stan, ['swobodna','uwieziona','nieprzytomna'], true)) blad('Nieprawidłowy stan postaci.');
            $q = polaczenie_bazy()->prepare("UPDATE mapa_obiekty SET stan_swobody=? WHERE id=? AND typ='postac'");
            $q->execute([$stan, (int) ($w['id'] ?? 0)]);
            if (!$q->rowCount()) {
                $q = polaczenie_bazy()->prepare("SELECT id FROM mapa_obiekty WHERE id=? AND typ='postac'");
                $q->execute([(int) ($w['id'] ?? 0)]);
                if (!$q->fetchColumn()) blad('Nie znaleziono postaci na mapie.', 404);
            }
            odpowiedz(['ok' => true]);

        case 'mg_bitwy':
            wymagaj_mg();
            $db = polaczenie_bazy();
            $planety = $db->query('SELECT id,nazwa FROM mapa_planety ORDER BY nazwa')->fetchAll(PDO::FETCH_ASSOC);
            $bitwy = $db->query("SELECT b.id,b.kod,b.nazwa,b.planeta_id,p.nazwa AS planeta,b.status,b.opis,b.rozpoczecie_utc,b.koniec_utc
                FROM mapa_bitwy b JOIN mapa_planety p ON p.id=b.planeta_id WHERE b.status='aktywna' ORDER BY b.rozpoczecie_utc DESC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($bitwy as &$b) {
                $q = $db->prepare("SELECT u.obiekt_id,m.nazwa,m.typ,m.klasa,u.strona,u.kolor FROM mapa_bitwy_uczestnicy u JOIN mapa_obiekty m ON m.id=u.obiekt_id WHERE u.bitwa_id=? ORDER BY u.strona,m.nazwa");
                $q->execute([(int) $b['id']]);
                $b['uczestnicy'] = $q->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($b);
            $obiekty = $db->query("SELECT id,nazwa,typ,klasa,planeta_id FROM mapa_obiekty WHERE aktywny=1 AND typ IN ('statek','jednostka','pojazd','postac') ORDER BY typ,nazwa")->fetchAll(PDO::FETCH_ASSOC);
            odpowiedz(['bitwy' => $bitwy, 'planety' => $planety, 'obiekty' => $obiekty]);

        case 'mg_bitwa_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $nazwa = mb_substr(trim((string) ($w['nazwa'] ?? '')), 0, 160);
            $planeta = (int) ($w['planeta_id'] ?? 0);
            if ($nazwa === '' || !$planeta) blad('Podaj nazwę bitwy i planetę.');
            $db = polaczenie_bazy();
            $q = $db->prepare('SELECT id FROM mapa_planety WHERE id=?');
            $q->execute([$planeta]);
            if (!$q->fetchColumn()) blad('Nie znaleziono planety.', 404);
            $db->prepare("INSERT INTO mapa_bitwy (kod,nazwa,planeta_id,status,opis) VALUES (?,?,?,'aktywna',?)")
                ->execute(['bitwa-' . bin2hex(random_bytes(8)), $nazwa, $planeta, mb_substr(trim((string) ($w['opis'] ?? '')), 0, 3000) ?: null]);
            odpowiedz(['ok' => true, 'id' => (int) $db->lastInsertId()]);

        case 'mg_bitwa_uczestnicy_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $bitwaId = (int) ($w['bitwa_id'] ?? 0);
            $uczestnicy = array_values((array) ($w['uczestnicy'] ?? []));
            if (count($uczestnicy) > 100) blad('Bitwa może mieć maksymalnie 100 pozycji.');
            $db = polaczenie_bazy();
            $q = $db->prepare("SELECT id FROM mapa_bitwy WHERE id=? AND status='aktywna'");
            $q->execute([$bitwaId]);
            if (!$q->fetchColumn()) blad('Nie znaleziono aktywnej bitwy.', 404);
            $db->beginTransaction();
            try {
                $db->prepare('DELETE FROM mapa_bitwy_uczestnicy WHERE bitwa_id=?')->execute([$bitwaId]);
                $ins = $db->prepare('INSERT INTO mapa_bitwy_uczestnicy (bitwa_id,obiekt_id,strona,kolor) SELECT ?,id,?,? FROM mapa_obiekty WHERE id=? AND aktywny=1');
                foreach ($uczestnicy as $x) {
                    $oid = (int) ($x['obiekt_id'] ?? 0);
                    $strona = mb_substr(trim((string) ($x['strona'] ?? '')), 0, 120);
                    $kolor = (string) ($x['kolor'] ?? '#ff6b55');
                    if (!$oid || $strona === '' || !preg_match('/^#[0-9a-fA-F]{6}$/', $kolor)) throw new RuntimeException('Każdy uczestnik musi mieć obiekt, stronę i poprawny kolor.');
                    $ins->execute([$bitwaId, $strona, $kolor, $oid]);
                    if (!$ins->rowCount()) throw new RuntimeException('Nie znaleziono aktywnego obiektu uczestniczącego w bitwie.');
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            odpowiedz(['ok' => true]);

        case 'mg_bitwa_zakoncz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $q = polaczenie_bazy()->prepare("UPDATE mapa_bitwy SET status='zakonczona',koniec_utc=UTC_TIMESTAMP() WHERE id=? AND status='aktywna'");
            $q->execute([(int) (wejscie()['bitwa_id'] ?? 0)]);
            odpowiedz(['ok' => true]);

        case 'mg_mapa_dostep_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            if (!tabela_sql_dostepna('mapa_obiekty_dostep')) blad('Najpierw zaimportuj migrację 005 uniwersalnej mapy.');
            $w = wejscie();
            $idObiektu = (int) ($w['id'] ?? 0);
            $q = polaczenie_bazy()->prepare('SELECT id FROM mapa_obiekty WHERE id=?');
            $q->execute([$idObiektu]);
            if (!$q->fetchColumn()) blad('Nie ma takiego obiektu mapy.', 404);
            $odbiorcy = array_values(array_unique(array_map('strval', (array) ($w['odbiorcy'] ?? []))));
            $loginy = array_column(array_filter(uzytkownicy(), static fn($x) => ($x['rola'] ?? '') !== 'mg'), 'login');
            if (array_diff($odbiorcy, $loginy)) blad('Lista odbiorców zawiera nieznane konto.');
            $db = polaczenie_bazy();
            $db->beginTransaction();
            try {
                $db->prepare('DELETE FROM mapa_obiekty_dostep WHERE obiekt_id=?')->execute([$idObiektu]);
                $q = $db->prepare('INSERT INTO mapa_obiekty_dostep (obiekt_id,konto_login) VALUES (?,?)');
                foreach ($odbiorcy as $login) $q->execute([$idObiektu, $login]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            odpowiedz(['ok' => true]);

        case 'mg_mapa_obiekt_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            if (!tabela_sql_dostepna('mapa_obiekty')) blad('Najpierw zaimportuj migrację 005 uniwersalnej mapy.');
            $w = wejscie();
            $idObiektu = (int) ($w['id'] ?? 0);
            $typ = (string) ($w['typ'] ?? 'jednostka');
            $rodzaj = mb_substr(trim((string) ($w['rodzaj'] ?? '')), 0, 80);
            $nazwa = mb_substr(trim((string) ($w['nazwa'] ?? '')), 0, 160);
            $x = $w['x'] ?? null;
            $y = $w['y'] ?? null;
            if (!in_array($typ, ['jednostka','statek','pojazd','instalacja','inne'], true)) blad('Nieprawidłowy typ obiektu.');
            if ($nazwa === '' || !is_numeric($x) || !is_numeric($y)) blad('Podaj nazwę oraz współrzędne X i Y.');
            $kolor = (string) ($w['kolor'] ?? '#00ff66');
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $kolor)) blad('Kolor musi mieć format #RRGGBB.');
            $znacznik = (string) ($w['znacznik'] ?? 'trojkat');
            if (!in_array($znacznik, ['kolo','trojkat','kwadrat','romb'], true)) blad('Nieprawidłowy kształt znacznika.');
            $odbiorcyLoginy = array_values(array_unique(array_map('strval', (array) ($w['odbiorcy'] ?? []))));
            $konta = array_column(array_filter(uzytkownicy(), static fn($x) => ($x['rola'] ?? '') !== 'mg'), 'login');
            if (array_diff($odbiorcyLoginy, $konta)) blad('Lista odbiorców zawiera nieznane konto.');
            $dane = [$typ, $nazwa, $rodzaj ?: null, mb_substr(trim((string) ($w['klasa'] ?? '')), 0, 120) ?: null,
                mb_substr(trim((string) ($w['wlasciciel'] ?? '')), 0, 160) ?: null, mb_substr(trim((string) ($w['status'] ?? '')), 0, 80) ?: null,
                (float) $x, (float) $y, $kolor, $znacznik, mb_substr(trim((string) ($w['opis'] ?? '')), 0, 5000) ?: null];
            $db = polaczenie_bazy();
            $db->beginTransaction();
            try {
                if ($idObiektu) {
                    $q = $db->prepare("UPDATE mapa_obiekty SET typ=?,nazwa=?,rodzaj=?,klasa=?,wlasciciel=?,status=?,x=?,y=?,kolor=?,znacznik=?,opis=?,aktywny=1 WHERE id=? AND typ<>'postac'");
                    $q->execute([...$dane, $idObiektu]);
                    if (!$q->rowCount()) {
                        $check = $db->prepare("SELECT id FROM mapa_obiekty WHERE id=? AND typ<>'postac'");
                        $check->execute([$idObiektu]);
                        if (!$check->fetchColumn()) throw new RuntimeException('Nie ma takiego obiektu do edycji.');
                    }
                } else {
                    $kod = 'obiekt-' . id();
                    $q = $db->prepare('INSERT INTO mapa_obiekty (kod,typ,nazwa,rodzaj,klasa,wlasciciel,status,x,y,kolor,znacznik,opis,aktywny) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)');
                    $q->execute([$kod, ...array_slice($dane, 0, 8), ...array_slice($dane, 8)]);
                    $idObiektu = (int) $db->lastInsertId();
                }
                $db->prepare('DELETE FROM mapa_obiekty_dostep WHERE obiekt_id=?')->execute([$idObiektu]);
                $q = $db->prepare('INSERT INTO mapa_obiekty_dostep (obiekt_id,konto_login) VALUES (?,?)');
                foreach ($odbiorcyLoginy as $login) $q->execute([$idObiektu, $login]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            odpowiedz(['ok' => true]);

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
            $npcIds = array_values(array_unique(array_filter(array_map('intval', (array) ($w['npc_ids'] ?? [])))));
            if (!$uids && !$npcIds) blad('Wybierz co najmniej jedną postać lub NPC.');
            if (count($npcIds) > 100) blad('Można jednocześnie ustawić pozycję maksymalnie 100 NPC.');
            $planeta = trim((string) ($w['planeta'] ?? ''));
            $lokacjaWejsciowa = trim((string) ($w['lokacja'] ?? ''));
            $lokacjaUstalaPlanete = $lokacjaWejsciowa !== '';
            if ($planeta !== '' && !$lokacjaUstalaPlanete) {
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
            $lokacja = $lokacjaWejsciowa;
            $lok = ruch_lokacje();
            if ($lokacja !== '' && !isset($lok[$lokacja])) blad('Nie ma takiej lokacji.');
            if ($lokacja !== '') {
                if (str_starts_with($lokacja, 'flota:')) $lokacja = 'okret:' . (int) ($lok[$lokacja]['okret_id'] ?? 0);
                if (!isset($lok[$lokacja])) {
                    $alias = (string) ($w['lokacja'] ?? '');
                    $lokacja = 'okret:' . (int) ($lok[$alias]['okret_id'] ?? 0);
                }
                $planeta = $lok[$lokacja]['planeta'];
                if (($lok[$lokacja]['typ'] ?? '') === 'poklad') $budynek = '';
                elseif (str_starts_with($lokacja, 'b:')) $budynek = substr($lokacja, 2);
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
            foreach ($npcIds as $npcId) mapa_obiekt_npc_pozycja_zapisz($npcId, $lokacja, $planeta, $poz['miejsce']);
            odpowiedz(['ok' => true]);
    }
}

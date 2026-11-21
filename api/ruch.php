<?php
// Przemieszczanie się po planecie, przygotowanie statku do lotu, gotówka (bankomat, sklep).
//
// LOKACJE. Katalog „lokacje”: miejsca na planetach z lokalnymi współrzędnymi w km (x, y),
// typem (hangar, hotel, bankomat, sklep, kantyna, biuro, garaz, port, inne), opcjonalnie
// jakością obsługi (hangar 1–5), zapasem paliwa, ofertą sklepu. Budynki firm z x, y też są
// lokacjami. Gracz zna: lokacje publiczne, budynki swoich firm i „znane miejsca” z karty.
//
// CZAS. Podróże liczone są w minutach świata gry, a czas świata płynie 1:1 z rzeczywistym
// (api/czas.php). MG przyspiesza pojedyncze procesy przez resynchronizację, nie globalnym tempem.
//
// KLUCZE LOKACJI: l:<id> (katalog), b:<id firmy>:<id budynku>, s:<id firmy>:<id statku> (pokład).

declare(strict_types=1);

const PIESZO_KMH = 5;
const TAKSOWKI = [
    'wysoka' => ['czekanie' => 3, 'kmh' => 120, 'stawka' => 4],
    'niska' => ['czekanie' => 20, 'kmh' => 90, 'stawka' => 6],
];
const TYPY_LOKACJI = ['hangar', 'platforma', 'port', 'hotel', 'bankomat', 'sklep', 'kantyna', 'biuro', 'garaz', 'inne'];
const LADOWISKA = ['hangar', 'platforma', 'port'];
const TOLERANCJA_KM = 0.25; // ręczne lądowanie: jak blisko lądowiska muszą być wpisane współrzędne

// Klucz lokacji z nazwy albo klucza (dla edytorów MG). Pusty tekst = brak lokacji.
function ruch_klucz(array $lokacje, string $wartosc): string
{
    $wartosc = trim($wartosc);
    if ($wartosc === '') return '';
    if (isset($lokacje[$wartosc])) return $wartosc;
    foreach ($lokacje as $k => $l) {
        if (mb_strtolower($l['nazwa']) === mb_strtolower($wartosc)) return $k;
    }
    blad("Nieznana lokacja: „{$wartosc}”. Dodaj ją w katalogu (lokacje) albo nadaj budynkowi x i y.");
}

// Statek, na którego pokładzie jest postać (klucz s:<firma>:<statek>), albo null.
function ruch_statek(string $klucz): ?array
{
    if (!str_starts_with($klucz, 's:')) return null;
    [, $fid, $sid] = explode(':', $klucz) + ['', '', ''];
    foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
        if ($f['id'] !== $fid) continue;
        foreach ($f['dane']['flota'] as $s) {
            if ($s['id'] === $sid) return $s + ['firma' => $fid];
        }
    }
    return null;
}

function ruch_w_locie(string $klucz): bool
{
    return !empty(ruch_statek($klucz)['w_locie']);
}

// Zmiana pola statku (np. w_locie) pod blokadą.
function ruch_zmien_statek(string $klucz, callable $fn)
{
    [, $fid, $sid] = explode(':', $klucz) + ['', '', ''];
    return zmien('firmy', function (&$wsz) use ($fid, $sid, $fn) {
        foreach ($wsz['firmy'] as &$f) {
            if ($f['id'] !== $fid) continue;
            foreach ($f['dane']['flota'] as &$s) {
                if ($s['id'] === $sid) return $fn($s);
            }
        }
        blad('Nie ma takiego statku.', 404);
    }, ['firmy' => []]);
}

// Postacie na pokładzie danego statku.
function ruch_na_pokladzie(string $klucz): array
{
    $w = [];
    foreach (wczytaj('karty', []) as $uid => $k) {
        if (($k['pozycja']['lokacja'] ?? '') === $klucz) $w[] = $uid;
    }
    return $w;
}

// Lądowiska, które autopilot zna przy planecie statku: znane postaci (publiczne, firmowe, z karty)
// oraz przydzielone przez kontrolę lotów.
function ruch_ladowiska(string $uid, array $karta, array $statek, array $lok): array
{
    $planeta = $statek['lokalizacja'] ?? '';
    $w = array_filter(ruch_znane($uid, $karta, $lok, $planeta), fn($l) => in_array($l['typ'], LADOWISKA, true));
    $przydzial = wczytaj('lacznosc', [])['s:' . $statek['firma'] . ':' . $statek['id']]['przydzial'] ?? '';
    if ($przydzial !== '' && isset($lok[$przydzial]) && $lok[$przydzial]['planeta'] === $planeta) $w[$przydzial] = $lok[$przydzial] + ['przydzial' => true];
    return $w;
}

// ----- łączność z kontrolą lotów -----
// Wątek radiowy statku przy danej planecie. Na razie odpowiada MG (panel). Odpowiedzi postaci
// niezależnych z modelu językowego można później podpiąć w jednym miejscu: lacznosc_odpowiedz_npc().

function lacznosc_nowa(string $statek, array $planeta): void
{
    $kontrola = trim((string) ($planeta['kontrola'] ?? ''));
    zmien('lacznosc', function (&$l) use ($statek, $planeta, $kontrola) {
        $l[$statek] = ['planeta' => $planeta['id'], 'kontrola' => $kontrola, 'przydzial' => '', 'wiadomosci' => [], 'czeka' => $kontrola !== ''];
        if ($kontrola !== '') {
            $l[$statek]['wiadomosci'][] = ['od' => 'kontrola', 'autor' => $kontrola, 'czas' => date('c'),
                'tresc' => 'Tu ' . $kontrola . '. Wykryliśmy wasz statek. Podajcie kod identyfikacyjny i cel wizyty, czekajcie na przydział lądowiska.'];
        }
    });
}

function lacznosc_zamknij(string $statek): void
{
    zmien('lacznosc', function (&$l) use ($statek) {
        unset($l[$statek]);
    });
}

// Miejsce na przyszłe odpowiedzi NPC (model językowy). Dziś nic nie robi: odpowiada MG.
function lacznosc_odpowiedz_npc(string $statek, array $watek): ?array
{
    return null;
}

// Planety z katalogu, których nie ma w bazie mapy (pole „mapa”: x, y, kwadrat, region).
// Planeta z „widoczna_dla” (lista loginów) trafia tylko do tych kont i do MG. Reszta nawet
// nie dowiaduje się, że istnieje.
function mapa_dodatkowe(array $u): array
{
    $w = [];
    foreach (katalog_dla_konta(katalog(), $u)['planety'] as $p) {
        if (!isset($p['mapa']['x'], $p['mapa']['y'])) continue;
        $lista = array_map('mb_strtolower', (array) ($p['widoczna_dla'] ?? []));
        $w[] = ['nazwa' => $p['nazwa'], 'x' => (float) $p['mapa']['x'], 'y' => (float) $p['mapa']['y'],
            'kwadrat' => $p['mapa']['kwadrat'] ?? '', 'region' => $p['mapa']['region'] ?? '', 'sektor' => $p['sektor'] ?? '',
            'zastrzezona' => (bool) $lista];
    }
    return $w;
}

// Warunek SQL: bez jednostek stojących w hangarze okrętu (migracja 035). $uid: postać na takim promie
// widzi go dalej (znacznik „Jesteś tutaj” stoi wtedy na okręcie).
function mapa_bez_hangaru(?string $uid = null): string
{
    if (!function_exists('floty_hangar_dostepny') || !floty_hangar_dostepny()) return '';
    if ($uid === null) return ' AND m.w_hangarze=0';
    $uid = polaczenie_bazy()->quote($uid);
    return " AND (m.w_hangarze=0 OR m.id=(SELECT c.na_pokladzie_id FROM mapa_obiekty c WHERE c.legacy_uid=$uid AND c.typ='postac' LIMIT 1))";
}

function mapa_obiekty_poklad_sync(): void
{
    if (tabela_sql_dostepna('mapa_obiekty')) polaczenie_bazy()->exec("UPDATE mapa_obiekty postac JOIN mapa_obiekty okret ON okret.id=postac.na_pokladzie_id
        SET postac.planeta_id=okret.planeta_id,postac.x=okret.x,postac.y=okret.y WHERE postac.typ='postac' AND okret.aktywny=1");
}

// STRONY NA MAPIE: kolor jednostki z punktu widzenia gracza — sojusznik (zielony), wróg (czerwony),
// neutralny (biały). Frakcja jednostki: kolumna `frakcja` (ustawia MG), inaczej z pola `wlasciciel`,
// inaczej frakcja konta dowódcy floty. Relacje: ta sama frakcja = sojusznik, Imperium ↔ Rebelia = wróg.
const MAPA_WROGOWIE = [['Imperium', 'Rebelia']];

function mapa_frakcja_dostepna(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT frakcja FROM mapa_obiekty LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

function mapa_frakcja_z_opisu(string $tekst): ?string
{
    $t = mb_strtolower($tekst);
    if ($t === '') return null;
    if (preg_match('/imperi|\bisb\b|moff|imperialn/u', $t)) return 'Imperium';
    if (preg_match('/rebel|sojusz/u', $t)) return 'Rebelia';
    if (preg_match('/syndykat|hutt|czarne słońce|przemyt/u', $t)) return 'Syndykat';
    return null;
}

function mapa_relacja(?string $ja, ?string $oni): string
{
    if (!$ja || !$oni || $oni === 'neutralna') return 'neutralny';
    if ($ja === $oni) return 'sojusznik';
    foreach (MAPA_WROGOWIE as [$a, $b]) {
        if (($ja === $a && $oni === $b) || ($ja === $b && $oni === $a)) return 'wrog';
    }
    return 'neutralny';
}

// Dopisuje `strona` (sojusznik / wrog / neutralny) jednostkom widzianym przez gracza (MG: bez stron).
function mapa_strony(array $obiekty, array $u): array
{
    if (($u['rola'] ?? '') === 'mg') return $obiekty;
    $ja = in_array($u['frakcja'] ?? '', FRAKCJE, true) ? $u['frakcja'] : null;
    $konta = [];
    foreach (uzytkownicy() as $k) $konta[mb_strtolower((string) ($k['login'] ?? ''))] = $k['frakcja'] ?? null;
    foreach ($obiekty as &$o) {
        if (!in_array($o['typ'] ?? '', ['statek', 'jednostka'], true)) continue;
        $frakcja = $o['frakcja'] ?? null
            ?: mapa_frakcja_z_opisu((string) ($o['wlasciciel'] ?? ''))
            ?: ($konta[mb_strtolower((string) ($o['flota']['dowodca_login'] ?? ''))] ?? null);
        $o['strona'] = mapa_relacja($ja, $frakcja);
    }
    unset($o);
    return $obiekty;
}

// Kolumny kursu do zapytań mapy (puste, dopóki nie ma migracji 015).
// Długość jednostek (migracja 033) — mapa w rzeczywistej skali.
function mapa_dlugosc_dostepna(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT dlugosc_m FROM mapa_obiekty LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

function mapa_kurs_sql(): string
{
    if (!function_exists('floty_kurs_dostepny') || !floty_kurs_dostepny()) return '';
    return ',m.kurs_deg,m.kurs_start_deg,m.kurs_cel_deg,m.manewr_od_utc,m.manewr_do_utc'
        . (floty_orbita_dostepna() ? ',m.orbita_deg,m.rozbity' : '')
        . (mapa_frakcja_dostepna() ? ',m.frakcja' : '')
        . (floty_nawigacja_dostepna() ? ',m.podejscie' : '')
        . (mapa_dlugosc_dostepna() ? ',m.dlugosc_m' : '')
        . (function_exists('floty_lot_dostepny') && floty_lot_dostepny()
            ? ',m.lot_start_x,m.lot_start_y,m.lot_cel_x,m.lot_cel_y,m.lot_cel_planeta_id,m.lot_km,m.lot_od_utc,m.lot_do_utc,(SELECT nazwa FROM mapa_planety WHERE id=m.lot_cel_planeta_id) AS lot_cel_planeta' : '')
        . (function_exists('floty_uklad_dostepny') && floty_uklad_dostepny() ? ',m.uklad_kx,m.uklad_ky,m.lot_start_kx,m.lot_start_ky,m.lot_cel_kx,m.lot_cel_ky' : '')
        // Lądowisko: statek stoi na powierzchni (mapa rysuje go na planecie, nie na orbicie).
        . (function_exists('floty_ladowanie_dostepne') && floty_ladowanie_dostepne() ? ',m.ladowisko_id,(SELECT nazwa FROM lokalizacje WHERE id=m.ladowisko_id) AS ladowisko_nazwa' : '')
        // Lot w atmosferze (lądowanie, start): współrzędne geograficzne początku i końca.
        . (function_exists('atm_dostepna') && atm_dostepna() ? ',m.atm_rodzaj,m.atm_od_utc,m.atm_do_utc,m.atm_szer0,m.atm_dl0,m.atm_szer1,m.atm_dl1,m.atm_orbita_deg' : '')
        // Dok orbitalny: stanowisko, jego kąt i wysokość pierścienia, lot łukiem do doku (start: kąt i promień).
        . (function_exists('doki_dostepne') && doki_dostepne() ? ',m.dok_stanowisko_id,m.dok_od_utc,m.dok_do_utc,m.dok_kat_start,m.dok_r_start_km,'
            . '(SELECT CONCAT(s.oznaczenie,\'|\',s.kat_deg,\'|\',l.orbita_wysokosc_km) FROM stocznia_stanowiska s JOIN lokalizacje l ON l.id=s.lokalizacja_id WHERE s.id=m.dok_stanowisko_id) AS dok_dane' : '');
}

// Kurs i trwający manewr dla mapy (tablet animuje obrót / przelot po orbicie).
function mapa_kurs_pola(array $r): array
{
    if (!array_key_exists('kurs_deg', $r)) return [];
    $w = ['kurs' => (float) $r['kurs_deg']];
    if (isset($r['orbita_deg'])) $w['orbita'] = (float) $r['orbita_deg'];
    if (!empty($r['rozbity'])) $w['rozbity'] = true;
    if (!empty($r['ladowisko_id'])) $w['na_ladowisku'] = (string) ($r['ladowisko_nazwa'] ?? '') ?: 'lądowisko';
    if (function_exists('atm_pola') && array_key_exists('atm_do_utc', $r)) $w += atm_pola($r);
    if (!empty($r['dok_stanowisko_id']) && !empty($r['dok_dane'])) {
        [$ozn, $kat, $wys] = explode('|', (string) $r['dok_dane']) + ['', 0, 0];
        $w['dok'] = ['oznaczenie' => $ozn, 'kat' => (float) $kat, 'wysokosc_km' => (float) $wys,
            'od' => $r['dok_od_utc'] ? czas_z_utc((string) $r['dok_od_utc']) : null, 'do' => $r['dok_do_utc'] ? czas_z_utc((string) $r['dok_do_utc']) : null,
            'kat0' => $r['dok_kat_start'] !== null ? (float) $r['dok_kat_start'] : null, 'r0' => $r['dok_r_start_km'] !== null ? (float) $r['dok_r_start_km'] : null];
    }
    if (!empty($r['frakcja'])) $w['frakcja'] = $r['frakcja'];
    if (!empty($r['podejscie'])) $w['podejscie'] = $r['podejscie'];
    if (!empty($r['dlugosc_m'])) $w['dlugosc_m'] = (float) $r['dlugosc_m'];
    if (!empty($r['lot_do_utc'])) {
        // Lot podświetlny: tablet przesuwa znacznik po linii lotu (w układzie: między planetą a księżycem).
        $w['podswietlny'] = ['od' => czas_z_utc((string) $r['lot_od_utc']), 'do' => czas_z_utc((string) $r['lot_do_utc']),
            'x0' => (float) $r['lot_start_x'], 'y0' => (float) $r['lot_start_y'], 'x1' => (float) $r['lot_cel_x'], 'y1' => (float) $r['lot_cel_y'],
            'planeta' => $r['lot_cel_planeta'] ?? null, 'km' => (float) $r['lot_km']];
        // Lot w obrębie układu: początek i cel w km od słońca.
        if (($r['lot_start_kx'] ?? null) !== null && ($r['lot_cel_kx'] ?? null) !== null) {
            $w['podswietlny'] += ['k0' => [(float) $r['lot_start_kx'], (float) $r['lot_start_ky']], 'k1' => [(float) $r['lot_cel_kx'], (float) $r['lot_cel_ky']]];
        }
    }
    // Punkt w układzie (km od słońca), gdy jednostka nie stoi przy planecie.
    if (($r['uklad_kx'] ?? null) !== null) $w['uklad_k'] = [(float) $r['uklad_kx'], (float) $r['uklad_ky']];
    if (!empty($r['manewr_do_utc']) && $r['kurs_cel_deg'] !== null) {
        $w['manewr'] = ['od' => czas_z_utc((string) $r['manewr_od_utc']), 'do' => czas_z_utc((string) $r['manewr_do_utc']),
            'start' => (float) $r['kurs_start_deg'], 'cel' => (float) $r['kurs_cel_deg']];
    }
    return $w;
}

function mapa_obiekty_widoczne(array $u): array
{
    if (!tabela_sql_dostepna('mapa_obiekty') || !tabela_sql_dostepna('mapa_obiekty_dostep')) return [];
    if (function_exists('floty_aktualizuj_ruch')) floty_aktualizuj_ruch();
    mapa_obiekty_poklad_sync();
    if (($u['rola'] ?? '') === 'mg') {
        foreach (uzytkownicy() as $konto) {
            if (($konto['rola'] ?? '') !== 'mg') karta((string) $konto['id']);
        }
        $q = polaczenie_bazy()->query("SELECT m.id,m.kod,m.typ,m.nazwa,m.rodzaj,m.klasa,m.wlasciciel,m.status,m.planeta_id,m.na_pokladzie_id,p.nazwa AS planeta,m.x,m.y,m.kolor,m.znacznik,m.opis" . mapa_kurs_sql() . " FROM mapa_obiekty m LEFT JOIN mapa_planety p ON p.id=m.planeta_id WHERE m.aktywny=1 AND m.x IS NOT NULL AND m.y IS NOT NULL" . mapa_bez_hangaru() . " ORDER BY m.typ,m.nazwa");
    } else {
        karta((string) $u['id']);
        $q = polaczenie_bazy()->prepare("SELECT m.id,m.kod,m.typ,m.nazwa,m.rodzaj,m.klasa,m.wlasciciel,m.status,m.planeta_id,m.na_pokladzie_id,p.nazwa AS planeta,m.x,m.y,m.kolor,m.znacznik,m.opis" . mapa_kurs_sql() . " FROM mapa_obiekty m LEFT JOIN mapa_planety p ON p.id=m.planeta_id WHERE m.aktywny=1 AND m.x IS NOT NULL AND m.y IS NOT NULL" . mapa_bez_hangaru((string) $u['id']) . " AND (m.legacy_uid=? OR EXISTS (SELECT 1 FROM mapa_obiekty_dostep d WHERE d.obiekt_id=m.id AND d.konto_login=?) OR m.id=(SELECT c.na_pokladzie_id FROM mapa_obiekty c WHERE c.legacy_uid=? AND c.typ='postac' LIMIT 1)) ORDER BY m.typ,m.nazwa");
        $q->execute([(string) $u['id'], (string) $u['login'], (string) $u['id']]);
    }
    $obiekty = array_map(static fn($r) => [
        'id' => (int) $r['id'], 'kod' => $r['kod'], 'typ' => $r['typ'], 'nazwa' => $r['nazwa'], 'rodzaj' => $r['rodzaj'],
        'klasa' => $r['klasa'], 'wlasciciel' => $r['wlasciciel'], 'status' => $r['status'], 'planeta' => $r['planeta'],
        'x' => (float) $r['x'], 'y' => (float) $r['y'], 'kolor' => $r['kolor'], 'znacznik' => $r['znacznik'], 'opis' => $r['opis'],
        'na_pokladzie_id' => empty($r['na_pokladzie_id']) ? null : (int)$r['na_pokladzie_id'],
    ] + mapa_kurs_pola($r), $q->fetchAll(PDO::FETCH_ASSOC));
    if ($obiekty && tabela_sql_dostepna('mapa_floty_jednostki') && tabela_sql_dostepna('mapa_floty')) {
        $ids = array_column($obiekty, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $q = polaczenie_bazy()->prepare("SELECT j.obiekt_id,j.rola,j.tie_liczba,f.kod AS flota_kod,f.nazwa AS flota_nazwa,
                rodzic.nazwa AS flota_glowna,COALESCE(NULLIF(f.dowodca_login,''),NULLIF(rodzic.dowodca_login,'')) AS dowodca_login
            FROM mapa_floty_jednostki j JOIN mapa_floty f ON f.id=j.flota_id
            LEFT JOIN mapa_floty rodzic ON rodzic.id=f.rodzic_id WHERE j.obiekt_id IN ($marks)");
        $q->execute($ids);
        $konta = [];
        foreach (uzytkownicy() as $konto) $konta[mb_strtolower((string) ($konto['login'] ?? ''))] = $konto;
        $indeks = array_column($obiekty, null, 'id');
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $id = (int) $f['obiekt_id'];
            if (!isset($indeks[$id])) continue;
            $dowodca = $konta[mb_strtolower((string) $f['dowodca_login'])] ?? [];
            $indeks[$id]['flota'] = [
                'kod' => $f['flota_kod'], 'nazwa' => $f['flota_glowna'] ?: $f['flota_nazwa'],
                'grupa' => $f['flota_glowna'] ? $f['flota_nazwa'] : null,
                'dowodca' => (string) ($dowodca['postac'] ?? $f['dowodca_login'] ?? ''),
                'dowodca_login' => (string) ($f['dowodca_login'] ?? ''), 'rola' => $f['rola'],
                'tie_liczba' => $f['tie_liczba'] === null ? null : (int) $f['tie_liczba'],
            ];
        }
        $obiekty = array_values($indeks);
    }
    if ($obiekty && tabela_sql_dostepna('mapa_rozkazy_skokow_jednostki')) {
        $ids = array_column($obiekty, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $q = polaczenie_bazy()->prepare("SELECT j.obiekt_id,j.wyjscie_x,j.wyjscie_y," . (function_exists('floty_uklad_dostepny') && floty_uklad_dostepny() ? 'j.wyjscie_kx,j.wyjscie_ky,' : '') . "(SELECT nazwa FROM mapa_planety WHERE id=j.planeta_id) AS cel_planeta,j.eta_utc,j.kat_bledu,COALESCE(" . (mapa_kurs_sql() ? 'r.wejscie_utc,' : '') . "r.wydano_utc) AS wejscie_utc FROM mapa_rozkazy_skokow_jednostki j JOIN mapa_rozkazy_skokow r ON r.id=j.rozkaz_id WHERE j.status='w_nadprzestrzeni' AND r.status='w_nadprzestrzeni' AND j.obiekt_id IN ($marks)");
        $q->execute($ids);
        $indeks = array_column($obiekty, null, 'id');
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $lot) {
            $id = (int) $lot['obiekt_id'];
            if (isset($indeks[$id]) && ($lot['wyjscie_kx'] ?? null) !== null) $indeks[$id]['lot_k'] = [(float) $lot['wyjscie_kx'], (float) $lot['wyjscie_ky']];
            if (isset($indeks[$id])) $indeks[$id]['lot'] = ['x' => (float) $lot['wyjscie_x'], 'y' => (float) $lot['wyjscie_y'], 'eta' => strtotime($lot['eta_utc'] . ' UTC'),
                'wejscie' => strtotime($lot['wejscie_utc'] . ' UTC'), 'szum_stopnie' => (float) $lot['kat_bledu'], 'planeta' => $lot['cel_planeta']];
        }
        $obiekty = array_values($indeks);
    }
    return $obiekty;
}

// Dodatkowy radar lokalny: swobodny mieszkaniec widzi okręty na orbicie oraz
// wszystkich uczestników aktywnych bitew przypisanych do planety. Udostępnienia
// mapy pozostają bez zmian poza tym lokalnym zasięgiem.
function mapa_radar_lokalny(array $u, string $nazwaPlanety, array $obiekty): array
{
    $pusty = ['obiekty' => $obiekty, 'bitwy' => []];
    if (($u['rola'] ?? '') === 'mg' || $nazwaPlanety === '' || !tabela_sql_dostepna('mapa_bitwy')
        || !tabela_sql_dostepna('mapa_bitwy_uczestnicy') || !tabela_sql_dostepna('mapa_obiekty')) return $pusty;
    $db = polaczenie_bazy();
    $q = $db->prepare('SELECT id FROM mapa_planety WHERE nazwa=? LIMIT 1');
    $q->execute([$nazwaPlanety]);
    $planetaId = (int) $q->fetchColumn();
    if (!$planetaId) return $pusty;
    $q = $db->prepare("SELECT stan_swobody FROM mapa_obiekty WHERE legacy_uid=? AND typ='postac' LIMIT 1");
    $q->execute([(string) $u['id']]);
    if ((string) $q->fetchColumn() !== 'swobodna') return $pusty;

    $sql = "SELECT m.id,m.kod,m.typ,m.nazwa,m.rodzaj,m.klasa,m.wlasciciel,m.status,m.planeta_id,m.na_pokladzie_id,p.nazwa AS planeta,m.x,m.y,m.kolor,m.znacznik,m.opis" . mapa_kurs_sql() . ",
                   b.id AS bitwa_id,b.nazwa AS bitwa_nazwa,bu.strona AS bitwa_strona,bu.kolor AS bitwa_kolor
            FROM mapa_obiekty m LEFT JOIN mapa_planety p ON p.id=m.planeta_id
            LEFT JOIN mapa_bitwy_uczestnicy bu ON bu.obiekt_id=m.id
            LEFT JOIN mapa_bitwy b ON b.id=bu.bitwa_id AND b.status='aktywna' AND b.planeta_id=?
            WHERE m.aktywny=1 AND m.x IS NOT NULL AND m.y IS NOT NULL" . mapa_bez_hangaru() . "
              AND ((m.planeta_id=? AND m.typ IN ('statek','jednostka')) OR b.id IS NOT NULL)
            ORDER BY b.id,m.nazwa";
    $q = $db->prepare($sql);
    $q->execute([$planetaId, $planetaId]);
    $indeks = array_column($obiekty, null, 'id');
    $bitwy = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int) $r['id'];
        if (!isset($indeks[$id])) $indeks[$id] = [
            'id' => $id, 'kod' => $r['kod'], 'typ' => $r['typ'], 'nazwa' => $r['nazwa'], 'rodzaj' => $r['rodzaj'],
            'klasa' => $r['klasa'], 'wlasciciel' => $r['wlasciciel'], 'status' => $r['status'], 'planeta' => $r['planeta'],
            'x' => (float) $r['x'], 'y' => (float) $r['y'], 'kolor' => $r['kolor'], 'znacznik' => $r['znacznik'], 'opis' => $r['opis'],
            'na_pokladzie_id' => empty($r['na_pokladzie_id']) ? null : (int)$r['na_pokladzie_id'],
        ] + mapa_kurs_pola($r);
        if (in_array($r['typ'], ['statek','jednostka'], true) && (int) $r['planeta_id'] === $planetaId) $indeks[$id]['orbita'] = true;
        if ($r['bitwa_id'] !== null) {
            $indeks[$id]['bitwa'] = ['nazwa' => $r['bitwa_nazwa'], 'strona' => $r['bitwa_strona'], 'kolor' => $r['bitwa_kolor']];
            $bid = (int) $r['bitwa_id'];
            if (!isset($bitwy[$bid])) $bitwy[$bid] = ['nazwa' => $r['bitwa_nazwa'], 'strony' => []];
            $bitwy[$bid]['strony'][$r['bitwa_strona']] = true;
        }
    }
    foreach ($bitwy as &$bitwa) $bitwa['strony'] = array_keys($bitwa['strony']);
    unset($bitwa);
    return ['obiekty' => array_values($indeks), 'bitwy' => array_values($bitwy)];
}

function mapa_obiekty_mg(): array
{
    if (!tabela_sql_dostepna('mapa_obiekty') || !tabela_sql_dostepna('mapa_obiekty_dostep')) return [];
    mapa_obiekty_poklad_sync();
    $rows = polaczenie_bazy()->query('SELECT id,kod,typ,nazwa,rodzaj,klasa,wlasciciel,status,stan_swobody,planeta_id,na_pokladzie_id,npc_podmiot_id,x,y,kolor,znacznik,opis,aktywny FROM mapa_obiekty ORDER BY typ,nazwa')->fetchAll(PDO::FETCH_ASSOC);
    $q = polaczenie_bazy()->query('SELECT obiekt_id,konto_login FROM mapa_obiekty_dostep ORDER BY konto_login');
    $acl = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $acl[(int) $r['obiekt_id']][] = $r['konto_login'];
    foreach ($rows as &$r) $r['odbiorcy'] = $acl[(int) $r['id']] ?? [];
    unset($r);
    return $rows;
}


// LOKALIZATOR PRYWATNYCH STATKÓW (mapa_obiekty.wlasciciel_login): Nawigacja właściciela pokazuje statek,
// jeśli lokalizator działa i statek jest w zasięgu (pc od pozycji postaci na mapie); inaczej ostatnią
// znaną pozycję. Na lądowisku tej samej planety: trasa i wejście na pokład.
function ruch_lokalizator_dostepny(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT wlasciciel_login, lokalizator_zasieg_pc, ostatnia_pozycja FROM mapa_obiekty LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// Czy konto może wejść na pokład statku z SQL: właściciel, kapitan, dowódca (także prom okrętu macierzystego —
// floty_jednostka_moze), konto z dostępem albo ostatni statek, z którego postać zeszła.
function ruch_moze_wejsc(array $u, int $id, ?string $wlasciciel = null): bool
{
    if (($u['rola'] ?? '') === 'mg') return true;
    if ($wlasciciel !== null && $wlasciciel !== '' && mb_strtolower($wlasciciel) === mb_strtolower((string) $u['login'])) return true;
    if (function_exists('wskazniki_kapitan') && wskazniki_kapitan('okret:' . $id, $u)) return true;
    if (function_exists('floty_jednostka_moze') && floty_jednostka_moze((string) $u['login'], $id)) return true;
    if ((int) (karta((string) $u['id'])['pozycja']['ostatni_statek'] ?? 0) === $id) return true;
    $q = polaczenie_bazy()->prepare('SELECT 1 FROM mapa_obiekty_dostep WHERE obiekt_id = ? AND LOWER(konto_login) = LOWER(?) LIMIT 1');
    $q->execute([$id, (string) $u['login']]);
    return (bool) $q->fetchColumn();
}

// Statki stojące na lądowisku, na którym jest postać (z prawem wejścia), i awaryjny powrót na ostatni statek.
function ruch_statki_tutaj(array $u, ?array $gdzie): array
{
    $wynik = ['statki_tutaj' => [], 'powrot' => null];
    if (!function_exists('floty_ladowanie_dostepne') || !floty_ladowanie_dostepne() || !tabela_sql_dostepna('mapa_obiekty')) return $wynik;
    $db = polaczenie_bazy();
    $klucz = (string) ($gdzie['klucz'] ?? '');
    if (preg_match('/^sql:(\d+)$/', $klucz, $m)) {
        $q = $db->prepare("SELECT id, nazwa, klasa, wlasciciel_login FROM mapa_obiekty WHERE aktywny = 1 AND ladowisko_id = ? AND typ IN ('statek','jednostka') ORDER BY nazwa");
        $q->execute([(int) $m[1]]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $wynik['statki_tutaj'][] = ['id' => (int) $s['id'], 'nazwa' => $s['nazwa'], 'klasa' => $s['klasa'],
                'mozna' => ruch_moze_wejsc($u, (int) $s['id'], (string) $s['wlasciciel_login'])];
        }
    }
    $ost = (int) (karta((string) $u['id'])['pozycja']['ostatni_statek'] ?? 0);
    if ($ost && !str_starts_with($klucz, 'okret:') && !in_array($ost, array_column($wynik['statki_tutaj'], 'id'), true)) {
        $q = $db->prepare('SELECT o.nazwa, o.ladowisko_id, o.planeta_id, l.nazwa AS ladowisko FROM mapa_obiekty o LEFT JOIN lokalizacje l ON l.id = o.ladowisko_id WHERE o.id = ? AND o.aktywny = 1');
        $q->execute([$ost]);
        if ($s = $q->fetch(PDO::FETCH_ASSOC)) {
            $powod = !$s['ladowisko_id'] ? 'Statek nie stoi na lądowisku.'
                : ((string) ($gdzie['planeta'] ?? '') !== (string) $s['planeta_id'] ? 'Statek stoi na innej planecie.' : null);
            $wynik['powrot'] = ['id' => $ost, 'nazwa' => $s['nazwa'], 'ladowisko' => $s['ladowisko'], 'powod' => $powod];
        }
    }
    return $wynik;
}

function ruch_moje_statki_sql(array $u, ?array $gdzie): array
{
    if (!ruch_lokalizator_dostepny() || !function_exists('floty_ladowanie_dostepne') || !floty_ladowanie_dostepne()) return [];
    $db = polaczenie_bazy();
    $q = $db->prepare("SELECT o.id, o.nazwa, o.klasa, o.x, o.y, o.planeta_id, p.nazwa AS planeta, o.ladowisko_id, l.nazwa AS ladowisko,
            o.lokalizator_zasieg_pc, o.lokalizator_aktywny, o.ostatnia_pozycja, o.ostatnia_pozycja_utc
        FROM mapa_obiekty o LEFT JOIN mapa_planety p ON p.id = o.planeta_id LEFT JOIN lokalizacje l ON l.id = o.ladowisko_id
        WHERE o.aktywny = 1 AND LOWER(o.wlasciciel_login) = LOWER(?) ORDER BY o.nazwa");
    $q->execute([(string) $u['login']]);
    $statki = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$statki) return [];
    // pozycja postaci na mapie (znacznik postaci, synchronizowany z kartą)
    $q = $db->prepare("SELECT x, y FROM mapa_obiekty WHERE legacy_uid = ? AND typ = 'postac' LIMIT 1");
    $q->execute([(string) $u['id']]);
    $ja = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $teraz = czas_teraz();
    $wynik = [];
    foreach ($statki as $s) {
        $skok = $db->prepare("SELECT 1 FROM mapa_rozkazy_skokow_jednostki j JOIN mapa_rozkazy_skokow r ON r.id = j.rozkaz_id
            WHERE j.obiekt_id = ? AND j.status = 'w_nadprzestrzeni' AND COALESCE(r.wejscie_utc, r.wydano_utc) <= ? LIMIT 1");
        $skok->execute([(int) $s['id'], czas_utc($teraz)]);
        $wSkoku = (bool) $skok->fetchColumn();
        $opis = $wSkoku ? 'w nadprzestrzeni'
            : ($s['ladowisko_id'] ? 'lądowisko: ' . $s['ladowisko'] . ' (' . $s['planeta'] . ')'
                : ($s['planeta'] ? 'orbita: ' . $s['planeta'] : 'przestrzeń'));
        $odl = $ja && $s['x'] !== null ? hypot((float) $s['x'] - (float) $ja['x'], (float) $s['y'] - (float) $ja['y']) : null;
        $zasieg = (float) ($s['lokalizator_zasieg_pc'] ?? 0);
        $wZasiegu = (int) $s['lokalizator_aktywny'] === 1 && $zasieg > 0 && $odl !== null && $odl <= $zasieg;
        $w = ['id' => (int) $s['id'], 'nazwa' => $s['nazwa'], 'klasa' => $s['klasa'], 'zasieg' => $zasieg];
        if ($wZasiegu) {
            $db->prepare('UPDATE mapa_obiekty SET ostatnia_pozycja = ?, ostatnia_pozycja_utc = ? WHERE id = ?')->execute([$opis, czas_utc($teraz), (int) $s['id']]);
            $w += ['sygnal' => 'aktywny', 'pozycja' => $opis];
            $klucz = 'sql:' . (int) $s['ladowisko_id'];
            if ($s['ladowisko_id'] && $gdzie && (string) ($gdzie['planeta'] ?? '') === (string) $s['planeta_id']) {
                $lok = ruch_lokacje()[$klucz] ?? null;
                $w['ladowisko'] = ['klucz' => $klucz, 'nazwa' => $s['ladowisko'],
                    'km' => $lok && is_numeric($gdzie['x'] ?? null) && is_numeric($lok['x'] ?? null) ? round(ruch_odleglosc($gdzie, $lok), 1) : null,
                    'na_miejscu' => ($gdzie['klucz'] ?? '') === $klucz];
            }
        } else {
            $w += ['sygnal' => (int) $s['lokalizator_aktywny'] === 1 ? 'poza_zasiegiem' : 'brak',
                'ostatnia' => $s['ostatnia_pozycja'], 'ostatnia_czas' => czas_z_utc($s['ostatnia_pozycja_utc'] ?? null)];
        }
        $wynik[] = $w;
    }
    return $wynik;
}

// Wszystkie lokacje (katalog + budynki firm), klucz => dane.
// Lokacje bez siatki powierzchni (np. import Kuat ze współrzędnymi NULL): stałe współrzędne zastępcze z hierarchii —
// każde miejsce wokół rodzica, coraz bliżej z każdym poziomem (regiony setki km, miasta dziesiątki, dzielnice
// kilometry, budynki setki metrów). Gałąź orbity zostaje bez współrzędnych (tam dolatuje się statkiem).
function ruch_wspolrzedne_zastepcze(array $rows): array
{
    $po = [];
    foreach ($rows as $i => $r) $po[(int) $r['id']] = $i;
    $promienie = [[400, 1500], [20, 150], [1, 15], [0.05, 1], [0.01, 0.2]];
    $licz = function (int $id, int $glebia = 0) use (&$licz, &$rows, $po, $promienie): ?array {
        if (!isset($po[$id]) || $glebia > 12) return null;
        $r = &$rows[$po[$id]];
        if ($r['x_km'] !== null && $r['y_km'] !== null) return [(float) $r['x_km'], (float) $r['y_km'], $r['_poziom'] ?? 0];
        if (($r['typ_sql'] ?? '') === 'orbita') return null;
        $rodzic = $r['rodzic_id'] !== null ? (int) $r['rodzic_id'] : 0;
        if (!$rodzic || !isset($po[$rodzic])) { $r['x_km'] = 0.0; $r['y_km'] = 0.0; $r['_poziom'] = 0; return [0.0, 0.0, 0]; }
        $p = $licz($rodzic, $glebia + 1);
        if ($p === null) return null;
        $poziom = min(count($promienie) - 1, $p[2]);
        [$od, $do] = $promienie[$poziom];
        $h = crc32('lok|' . $id);
        $kat = ($h % 3600) / 10 * M_PI / 180;
        $odl = $od + ($do - $od) * (($h >> 12) % 1000) / 1000;
        $r['x_km'] = round($p[0] + cos($kat) * $odl, 3);
        $r['y_km'] = round($p[1] + sin($kat) * $odl, 3);
        $r['_poziom'] = $p[2] + 1;
        return [$r['x_km'], $r['y_km'], $r['_poziom']];
    };
    foreach ($rows as $r) if ($r['x_km'] === null || $r['y_km'] === null) $licz((int) $r['id']);
    return $rows;
}

function ruch_lokacje(): array
{
    $l = [];
    $u = ja();
    $kat = katalog_dla_konta(wczytaj('katalog', []), $u);
    foreach ($kat['lokacje'] ?? [] as $x) {
        $l['l:' . $x['id']] = $x + ['klucz' => 'l:' . $x['id']];
    }
    foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
        foreach ($f['dane']['budynki'] ?? [] as $b) {
            if (!isset($b['x'], $b['y']) || ($b['planeta'] ?? '') === '') continue;
            $typ = mb_strtolower($b['typ'] ?? '');
            $l['b:' . $f['id'] . ':' . $b['id']] = [
                'klucz' => 'b:' . $f['id'] . ':' . $b['id'], 'nazwa' => $b['nazwa'], 'planeta' => $b['planeta'],
                'typ' => in_array($typ, TYPY_LOKACJI, true) ? $typ : 'biuro', 'x' => (float) $b['x'], 'y' => (float) $b['y'],
                'publiczna' => false, 'firma' => $f['id'], 'jakosc' => (int) ($b['jakosc'] ?? 3), 'paliwo' => $b['paliwo'] ?? null,
                'opis' => $b['opis'] ?? '',
            ];
        }
    }
    if (tabela_sql_dostepna('lokalizacje')) {
        $rows = polaczenie_bazy()->query("SELECT l.id, l.typ AS typ_sql, l.rodzic_id, l.rodzaj, l.planeta_id, p.nazwa AS planeta_nazwa, l.nazwa, l.x_km, l.y_km, l.publiczna, l.opis, (b.lokalizacja_id IS NOT NULL) AS jest_budynkiem FROM lokalizacje l LEFT JOIN mapa_planety p ON p.id=l.planeta_id LEFT JOIN budynki b ON b.lokalizacja_id=l.id WHERE l.status='aktywna' ORDER BY l.id")->fetchAll(PDO::FETCH_ASSOC);
        $rows = ruch_wspolrzedne_zastepcze($rows);
        foreach ($rows as $x) {
            if ($x['planeta_id'] !== null && !mapa_planeta_widoczna((int) $x['planeta_id'], $u)) continue;
            $key = 'sql:' . $x['id'];
            $l[$key] = ['klucz' => $key, 'sql_id' => (int) $x['id'], 'nazwa' => $x['nazwa'], 'planeta' => (string) ($x['planeta_id'] ?? ''),
                'planeta_nazwa' => $x['planeta_nazwa'] ?? '', 'typ' => $x['rodzaj'] ?: ($x['jest_budynkiem'] ? 'biuro' : 'inne'),
                'x' => $x['x_km'] === null ? null : (float) $x['x_km'], 'y' => $x['y_km'] === null ? null : (float) $x['y_km'],
                'publiczna' => (bool) $x['publiczna'], 'opis' => $x['opis'] ?? '', 'jest_budynkiem' => (bool) $x['jest_budynkiem']];
        }
    }
    // Okręty są też lokacjami pokładowymi. Alias flota:<id> prowadzi na okręt
    // o najniższej kolejności w składzie (domyślnie okręt flagowy).
    if (tabela_sql_dostepna('mapa_obiekty') && tabela_sql_dostepna('mapa_floty_jednostki') && tabela_sql_dostepna('mapa_floty')) {
        $rows = polaczenie_bazy()->query("SELECT m.id,m.nazwa,m.planeta_id,p.nazwa AS planeta_nazwa,m.x,m.y,m.klasa
            FROM mapa_obiekty m LEFT JOIN mapa_planety p ON p.id=m.planeta_id
            WHERE m.aktywny=1 AND m.typ='statek' AND m.x IS NOT NULL AND m.y IS NOT NULL ORDER BY m.nazwa")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $x) {
            $key = 'okret:' . (int) $x['id'];
            $l[$key] = ['klucz'=>$key,'okret_id'=>(int)$x['id'],'nazwa'=>'Pokład: '.$x['nazwa'],'planeta'=>(string)($x['planeta_id'] ?? ''),
                'planeta_nazwa'=>$x['planeta_nazwa'] ?? 'przestrzeń','typ'=>'poklad','x'=>(float)$x['x'],'y'=>(float)$x['y'],
                'publiczna'=>false,'opis'=>'Postać znajduje się na pokładzie okrętu.','klasa'=>$x['klasa'] ?? ''];
        }
        $floty = polaczenie_bazy()->query("SELECT f.id AS flota_id,f.nazwa AS flota_nazwa,m.id AS okret_id,m.nazwa AS okret_nazwa,
                m.planeta_id,p.nazwa AS planeta_nazwa,m.x,m.y,m.klasa
            FROM mapa_floty f JOIN mapa_floty_jednostki j ON j.flota_id=f.id
            JOIN mapa_obiekty m ON m.id=j.obiekt_id LEFT JOIN mapa_planety p ON p.id=m.planeta_id
            WHERE f.status<>'nieaktywna' AND m.aktywny=1 AND m.typ='statek' AND m.x IS NOT NULL AND m.y IS NOT NULL
              AND j.kolejnosc=(SELECT MIN(j2.kolejnosc) FROM mapa_floty_jednostki j2 JOIN mapa_obiekty m2 ON m2.id=j2.obiekt_id WHERE j2.flota_id=f.id AND m2.aktywny=1)
            ORDER BY f.nazwa")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($floty as $x) {
            $key = 'flota:' . (int) $x['flota_id'];
            $l[$key] = ['klucz'=>$key,'okret_id'=>(int)$x['okret_id'],'nazwa'=>'Flota '.$x['flota_nazwa'].' · okręt flagowy: '.$x['okret_nazwa'],
                'planeta'=>(string)($x['planeta_id'] ?? ''),'planeta_nazwa'=>$x['planeta_nazwa'] ?? 'przestrzeń','typ'=>'poklad',
                'x'=>(float)$x['x'],'y'=>(float)$x['y'],'publiczna'=>false,'opis'=>'Pozycja na okręcie flagowym floty.','klasa'=>$x['klasa'] ?? ''];
        }
    }
    return $l;
}

function ruch_odleglosc(array $a, array $b): float
{
    return hypot((float) $a['x'] - (float) $b['x'], (float) $a['y'] - (float) $b['y']);
}

// Firmy, w których postać jest członkiem: id => firma.
function ruch_moje_firmy(string $uid): array
{
    $w = [];
    foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
        foreach ($f['czlonkowie'] as $c) {
            if ($c['uid'] === $uid) $w[$f['id']] = $f;
        }
    }
    return $w;
}

// Gdzie jest postać: lokacja (jeśli znana) i współrzędne.
function ruch_gdzie(array $karta, array $lokacje): ?array
{
    $p = $karta['pozycja'];
    $klucz = $p['lokacja'] ?? '';
    if (str_starts_with($klucz, 's:')) {
        // Na pokładzie statku: pozycja = hangar statku.
        [, $fid, $sid] = explode(':', $klucz) + ['', '', ''];
        foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
            if ($f['id'] !== $fid) continue;
            foreach ($f['dane']['flota'] as $s) {
                if ($s['id'] !== $sid) continue;
                $baza = ['klucz' => $klucz, 'nazwa' => 'Pokład: ' . $s['nazwa'], 'typ' => 'poklad'];
                // Na ziemi: pozycja hangaru. W locie (albo bez lądowiska): tylko planeta, przy której jest statek.
                if (empty($s['w_locie']) && isset($lokacje[$s['hangar'] ?? ''])) {
                    return $baza + array_intersect_key($lokacje[$s['hangar']], ['x' => 1, 'y' => 1, 'planeta' => 1]);
                }
                if (($s['lokalizacja'] ?? '') !== '') return $baza + ['x' => 0.0, 'y' => 0.0, 'planeta' => $s['lokalizacja']];
            }
        }
        return null;
    }
    if ($klucz !== '' && isset($lokacje[$klucz])) return $lokacje[$klucz];
    if (($p['planeta'] ?? '') !== '' && is_numeric($p['x'] ?? null) && is_numeric($p['y'] ?? null)) {
        return ['klucz' => '', 'nazwa' => $p['miejsce'] ?: 'Nieoznaczone miejsce', 'typ' => 'inne', 'x' => (float) $p['x'], 'y' => (float) $p['y'], 'planeta' => $p['planeta']];
    }
    return null;
}

// Miejsca, które postać zna na danej planecie.
function ruch_znane(string $uid, array $karta, array $lokacje, string $planeta): array
{
    $firmy = array_keys(ruch_moje_firmy($uid));
    $znane = array_map('mb_strtolower', $karta['znane'] ?? []);
    return array_filter($lokacje, fn($l) => ($l['planeta'] ?? '') === $planeta && (
        !empty($l['publiczna'])
        || (isset($l['firma']) && in_array($l['firma'], $firmy, true))
        || in_array(mb_strtolower($l['nazwa']), $znane, true)
        || in_array($l['klucz'], $karta['znane'] ?? [], true)
    ));
}

// Pojazdy dostępne dla postaci: prywatne (karta) i firmowe (każdy członek może ich użyć).
function ruch_pojazdy(string $uid, array $karta, array $lokacje): array
{
    $w = [];
    foreach ($karta['pojazdy'] ?? [] as $i => $p) {
        $w['k:' . $i] = $p + ['klucz' => 'k:' . $i, 'wlasciciel' => 'prywatny', 'kierowca_staty' => null];
    }
    foreach (ruch_moje_firmy($uid) as $f) {
        $zaloga = array_column($f['dane']['zaloga'] ?? [], null, 'id');
        foreach ($f['dane']['pojazdy'] ?? [] as $p) {
            $k = $zaloga[$p['kierowca'] ?? ''] ?? null;
            $w['f:' . $f['id'] . ':' . $p['id']] = $p + ['klucz' => 'f:' . $f['id'] . ':' . $p['id'], 'wlasciciel' => $f['nazwa'],
                'kierowca_imie' => $k['imie'] ?? '', 'kierowca_staty' => $k ? (int) ($k['statystyki']['pilotaz'] ?? 5) : null];
        }
    }
    foreach ($w as &$p) {
        $p['gdzie'] = $lokacje[$p['lokacja'] ?? ''] ?? null;
    }
    return $w;
}

// Pilotaż postaci z karty (umiejętność zawierająca „pilot”), 0–5.
function ruch_pilotaz_gracza(array $karta): int
{
    foreach ($karta['statystyki']['umiejetnosci'] ?? [] as $n => $v) {
        if (str_contains(mb_strtolower((string) $n), 'pilot')) return max(0, min(5, (int) $v));
    }
    return 0;
}

// Opcje dotarcia do celu. Czasy w minutach świata gry.
function ruch_opcje(string $uid, array $karta, array $skad, array $cel, array $planeta, array $lokacje): array
{
    $d = ruch_odleglosc($skad, $cel);
    $op = [];
    $op[] = ['srodek' => 'pieszo', 'nazwa' => 'Pieszo', 'minuty' => (int) ceil($d / PIESZO_KMH * 60), 'koszt' => 0, 'opis' => sprintf('%.1f km', $d)];
    $urb = $planeta['urbanizacja'] ?? 'brak';
    if (isset(TAKSOWKI[$urb])) {
        $t = TAKSOWKI[$urb];
        $stawka = (int) ($planeta['taksowka'] ?? $t['stawka']);
        $op[] = ['srodek' => 'taksowka', 'nazwa' => 'Taksówka', 'minuty' => (int) ceil($t['czekanie'] + $d / $t['kmh'] * 60),
            'koszt' => (int) ceil(10 + $stawka * $d), 'opis' => "podstawienie ok. {$t['czekanie']} min"];
    }
    foreach (ruch_pojazdy($uid, $karta, $lokacje) as $p) {
        if (!$p['gdzie'] || ($p['gdzie']['planeta'] ?? '') !== ($cel['planeta'] ?? '')) continue;
        $dojscie = ruch_odleglosc($skad, $p['gdzie']);
        $kmh = max(10, (int) ($p['predkosc'] ?? 200));
        $nazwa = $p['nazwa'] . ($p['wlasciciel'] !== 'prywatny' ? ' (' . $p['wlasciciel'] . ')' : '');
        $dojscieMin = (int) ceil($dojscie / PIESZO_KMH * 60);
        $opisDojscia = $dojscie > 0.05 ? sprintf('najpierw %d min pieszo do: %s · ', $dojscieMin, $p['gdzie']['nazwa']) : '';
        // Sam: Pilotaż z karty (0 → wolno i ostrożnie, 5 → szybko).
        $sam = 1.3 - 0.1 * ruch_pilotaz_gracza($karta);
        $op[] = ['srodek' => 'pojazd', 'pojazd' => $p['klucz'], 'kierowca' => false, 'nazwa' => $nazwa . ' — prowadzisz sam',
            'minuty' => $dojscieMin + (int) ceil(ruch_odleglosc($p['gdzie'], $cel) / $kmh * 60 * $sam), 'koszt' => 0, 'opis' => $opisDojscia . "$kmh km/h"];
        if ($p['kierowca_staty'] !== null) {
            // Z kierowcą: jego ukryty pilotaż (1–10).
            $mod = 1.25 - 0.05 * $p['kierowca_staty'];
            $op[] = ['srodek' => 'pojazd', 'pojazd' => $p['klucz'], 'kierowca' => true, 'nazwa' => $nazwa . ' — kierowca: ' . $p['kierowca_imie'],
                'minuty' => $dojscieMin + (int) ceil(ruch_odleglosc($p['gdzie'], $cel) / $kmh * 60 * $mod), 'koszt' => 0, 'opis' => $opisDojscia . "$kmh km/h"];
        }
    }
    usort($op, fn($a, $b) => $a['minuty'] <=> $b['minuty']);
    return $op;
}

// Kończy podróż, jeśli minął czas. Wywoływane przy każdym odczycie nawigacji i karty.
function ruch_dokoncz(string $uid): void
{
    $k = wczytaj('karty', [])[$uid]['podroz'] ?? null;
    if (!$k || time() < (int) $k['koniec']) return;
    zmien('karty', function (&$karty) use ($uid) {
        $p = $karty[$uid]['podroz'] ?? null;
        if (!$p || time() < (int) $p['koniec']) return;
        $karty[$uid]['pozycja'] = array_merge($karty[$uid]['pozycja'] ?? [], [
            'lokacja' => $p['cel'], 'miejsce' => $p['cel_nazwa'], 'planeta' => $p['planeta'],
            'budynek' => str_starts_with($p['cel'], 'b:') ? substr($p['cel'], 2) : '',
            'zmieniono' => (int) $p['koniec'] * 1000,
        ]);
        unset($karty[$uid]['podroz']);
    });
    $pozycja = wczytaj('karty', [])[$uid]['pozycja'] ?? null;
    if (is_array($pozycja)) pozycja_sql_zapisz($uid, $pozycja);
}

function ruch_planeta(string $id): array
{
    foreach (katalog_dla_konta(katalog(), ja())['planety'] as $p) {
        if ($p['id'] === $id) return $p;
    }
    if (ctype_digit($id) && tabela_sql_dostepna('mapa_planety')) {
        $q = polaczenie_bazy()->prepare('SELECT p.id, p.nazwa, p.x, p.y, p.region, p.grawitacja, p.slonca, p.ksiezyce, pg.typ_planety, pg.kontrola_lotow FROM mapa_planety p LEFT JOIN planety_gry pg ON pg.planeta_id=p.id WHERE p.id=?');
        $q->execute([(int) $id]);
        if ($p = $q->fetch(PDO::FETCH_ASSOC)) {
            if (!mapa_planeta_widoczna((int) $p['id'], ja())) return ['id' => '', 'nazwa' => '?'];
            return ['id' => (string) $p['id'], 'nazwa' => $p['nazwa'], 'typ' => $p['typ_planety'] ?: 'skalista', 'x' => $p['x'], 'y' => $p['y'], 'region' => $p['region'], 'kontrola' => $p['kontrola_lotow'] ?? ''];
        }
    }
    return ['id' => '', 'nazwa' => '?'];
}

// Statki z firm postaci, które stoją na tej planecie w znanym hangarze.
function ruch_statki(string $uid, string $planeta, array $lokacje): array
{
    $w = [];
    foreach (ruch_moje_firmy($uid) as $f) {
        foreach ($f['dane']['flota'] ?? [] as $s) {
            if (($s['lokalizacja'] ?? '') !== $planeta || !isset($lokacje[$s['hangar'] ?? '']) || !empty($s['w_locie'])) continue;
            $w[] = ['klucz' => $f['id'] . ':' . $s['id'], 'firma' => $f['nazwa'], 'nazwa' => $s['nazwa'], 'klasa' => $s['klasa'] ?? '',
                'stan' => $s['stan'] ?? 'sprawny', 'paliwo' => (int) ($s['paliwo'] ?? 100), 'hangar' => $lokacje[$s['hangar']],
                'przygotowanie' => $s['przygotowanie'] ?? null];
        }
    }
    return $w;
}

// Czas obsługi naziemnej (minuty świata): jakość hangaru + tankowanie + ewentualny dowóz paliwa.
function ruch_obsluga(array $statek, array $hangar): array
{
    $jakosc = max(1, min(5, (int) ($hangar['jakosc'] ?? 3)));
    $baza = [1 => 180, 2 => 120, 3 => 90, 4 => 60, 5 => 40][$jakosc];
    $brak = max(0, 100 - (int) ($statek['paliwo'] ?? 100));
    $tankowanie = (int) ceil($brak * 0.6);
    $zapas = $hangar['paliwo'] ?? null; // null = bez ograniczeń
    $dowoz = $zapas !== null && (int) $zapas < $brak ? 120 : 0;
    $cena = (int) ($hangar['cena_paliwa'] ?? 15);
    return ['minuty' => $baza + $tankowanie + $dowoz, 'baza' => $baza, 'tankowanie' => $tankowanie, 'dowoz' => $dowoz,
        'brak' => $brak, 'koszt' => $brak * $cena, 'jakosc' => $jakosc];
}

const AKCJE_RUCHU = ['mapa', 'nawigacja','trasa', 'podroz', 'bankomat', 'sklep_kup', 'statek_przygotuj', 'na_poklad', 'zejdz_z_pokladu', 'start', 'skok', 'ladowanie', 'lacznosc_wyslij'];
const CZAS_WZNOSZENIA_MS = 300000;

// SKOK STATKU POSTACI. Czas i paliwo z odległości na mapie galaktyki (pc) i napędu statku.
// Pola statku w edytorze firmy (opcjonalne): hipernaped (klasa, mniejsza = szybciej; domyślnie 2),
// predkosc_pc_h (domyślnie 1000), paliwo_na_pc (% zbiornika na parsek), transponder (jak widzi go radar).
const SKOK_KLASA_DOMYSLNA = 2.0;
const SKOK_PREDKOSC_PC_H = 1000.0;
const SKOK_PALIWO_NA_PC = 0.005;
const SKOK_MIN_MINUT = 5;
const SKOK_BEZ_MAPY_MINUT = 60;   // planeta bez współrzędnych mapy: stały czas i paliwo
const SKOK_BEZ_MAPY_PALIWO = 20;

// Wylicza skok między planetami katalogu. Zwraca minuty, paliwo (%) i punkty na mapie (albo null).
function ruch_skok_wylicz(array $statek, array $z, array $do): array
{
    $a = radar_planeta_mapy($z);
    $b = radar_planeta_mapy($do);
    if (!$a || !$b) return ['minuty' => SKOK_BEZ_MAPY_MINUT, 'paliwo' => SKOK_BEZ_MAPY_PALIWO, 'z' => $a, 'do' => $b, 'pc' => null];
    $pc = hypot($b['x'] - $a['x'], $b['y'] - $a['y']);
    $klasa = max(0.5, (float) ($statek['hipernaped'] ?? SKOK_KLASA_DOMYSLNA));
    $predkosc = max(1.0, (float) ($statek['predkosc_pc_h'] ?? SKOK_PREDKOSC_PC_H)) / $klasa;
    $minuty = max(SKOK_MIN_MINUT, $pc / $predkosc * 60);
    $paliwo = (int) ceil($pc * max(0.0, (float) ($statek['paliwo_na_pc'] ?? SKOK_PALIWO_NA_PC)));
    return ['minuty' => $minuty, 'paliwo' => $paliwo, 'z' => $a, 'do' => $b, 'pc' => $pc];
}

// Wyjście z nadprzestrzeni przy celu: statek „jest” przy nowej planecie bez lądowiska,
// załoga widzi wyjście z nadprzestrzeni. $kiedy: chwila wyjścia (sekundy UTC).
function ruch_wyjscie_ze_skoku(string $klucz, int $kiedy): void
{
    $statek = ruch_statek($klucz);
    if (!$statek || empty($statek['cel'])) return;
    $cel = ruch_planeta($statek['cel']);
    ruch_zmien_statek($klucz, function (&$s) use ($cel) {
        if (empty($s['cel'])) return;
        $s['lokalizacja'] = $cel['id'];
        $s['hangar'] = ''; // lądowisko trzeba wybrać, wpisać albo dostać od kontroli lotów
        $s['paliwo'] = max(0, (int) ($s['paliwo'] ?? 100) - (int) ($s['skok']['paliwo'] ?? SKOK_BEZ_MAPY_PALIWO));
        unset($s['cel'], $s['skok']);
    });
    lacznosc_nowa($klucz, $cel);
    $zaloga = ruch_na_pokladzie($klucz);
    $scena = ['scena' => 'wyjscie', 'planeta' => $cel['typ'] ?? 'skalista', 'odleglosc' => 'srednio',
        'miejsce' => $cel['nazwa'], 'opis' => 'Wyjście z nadprzestrzeni', 'wnetrze' => '', 'zmieniono' => $kiedy * 1000];
    zmien('sceny', function (&$s) use ($zaloga, $scena) {
        foreach ($zaloga as $uid) $s[$uid] = $scena;
    });
    // Pozycja postaci na pokładzie przenosi się na nową planetę.
    zmien('karty', function (&$k) use ($zaloga, $cel) {
        foreach ($zaloga as $uid) $k[$uid]['pozycja']['planeta'] = $cel['id'];
    });
    foreach ($zaloga as $uid) pozycja_sql_zapisz($uid, wczytaj('karty', [])[$uid]['pozycja'] ?? []);
}

// Domyka skoki, których termin minął (bez crona: przy odczytach nawigacji, mapy i sceny).
function ruch_skoki_dokoncz(): void
{
    $teraz = czas_teraz();
    foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
        foreach ($f['dane']['flota'] ?? [] as $s) {
            $eta = (int) ($s['skok']['eta'] ?? 0);
            if (!empty($s['cel']) && $eta > 0 && $eta <= $teraz) ruch_wyjscie_ze_skoku('s:' . $f['id'] . ':' . $s['id'], $eta);
        }
    }
}

function obsluz_ruch(string $akcja, bool $post): void
{
    // Każda akcja najpierw domyka podróż, która w międzyczasie dobiegła końca.
    if (in_array($akcja, AKCJE_RUCHU, true) && ($ja = ja())) ruch_dokoncz($ja['id']);
    if (in_array($akcja, AKCJE_RUCHU, true) || $akcja === 'mg_statki_w_locie') ruch_skoki_dokoncz();
    switch ($akcja) {

        // Mapa galaktyki: gdzie jest postać, dokąd leci i gdzie stoją statki jej firm.
        // Planety podajemy po nazwie, a datapad dopasowuje je do mapy galaktyki z SQL.
        case 'mapa':
            $u = wymagaj_logowania();
            $karta = karta($u['id']);
            $nazwy = array_column(katalog_dla_konta(katalog(), $u)['planety'], 'nazwa', 'id');
            if (($u['rola'] ?? '') === 'mg') {
                $planetySql = polaczenie_bazy()->query('SELECT id,nazwa FROM mapa_planety ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            } elseif (tabela_sql_dostepna('planety_gry') && tabela_sql_dostepna('planety_widocznosc_konta')) {
                $q = polaczenie_bazy()->prepare('SELECT p.id,p.nazwa FROM mapa_planety p LEFT JOIN planety_gry g ON g.planeta_id=p.id
                    WHERE COALESCE(g.zastrzezona,0)=0 OR EXISTS (SELECT 1 FROM planety_widocznosc_konta d WHERE d.planeta_id=p.id AND LOWER(d.konto_login)=LOWER(?)) ORDER BY p.id');
                $q->execute([$u['login']]);
                $planetySql = $q->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $planetySql = polaczenie_bazy()->query('SELECT p.id,p.nazwa FROM mapa_planety p LEFT JOIN planety_gry g ON g.planeta_id=p.id WHERE COALESCE(g.zastrzezona,0)=0 ORDER BY p.id')
                    ->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($planetySql as $p) $nazwy[(string) $p['id']] = $p['nazwa'];
            $gdzie = ruch_gdzie($karta, ruch_lokacje());
            $tu = (string) ($gdzie['planeta'] ?? $karta['pozycja']['planeta'] ?? '');
            $wynik = ['tu' => $nazwy[$tu] ?? '', 'stan' => $tu !== '' ? 'powierzchnia' : '', 'cel' => '', 'statki' => []];
            if ($wynik['tu'] === '' && ctype_digit($tu)) $wynik['tu'] = ruch_planeta($tu)['nazwa'] ?? '';
            if ($st = ruch_statek($karta['pozycja']['lokacja'] ?? '')) {
                if (!empty($st['w_locie'])) $wynik['stan'] = ($st['cel'] ?? '') !== '' ? 'nadprzestrzen' : 'orbita';
                $wynik['cel'] = $nazwy[$st['cel'] ?? ''] ?? '';
            }
            foreach (ruch_moje_firmy($u['id']) as $f) {
                foreach ($f['dane']['flota'] ?? [] as $s) {
                    $wynik['statki'][] = ['nazwa' => $s['nazwa'] ?? '?', 'firma' => $f['nazwa'], 'planeta' => $nazwy[$s['lokalizacja'] ?? ''] ?? '',
                        'cel' => $nazwy[$s['cel'] ?? ''] ?? '', 'w_locie' => !empty($s['w_locie'])];
                }
            }
            $wynik['dodatkowe'] = mapa_dodatkowe($u);
            $wynik['obiekty'] = mapa_obiekty_widoczne($u);
            $radar = mapa_radar_lokalny($u, $wynik['tu'], $wynik['obiekty']);
            $wynik['obiekty'] = $radar['obiekty'];
            $wynik['bitwy_radar'] = $radar['bitwy'];
            $wynik['obiekty'] = mapa_strony($wynik['obiekty'], $u);
            // Lokacje orbitalne ze stanowiskami doków (pierścień stoczniowy Kuat) — rysowane przy dużym zbliżeniu.
            if (function_exists('doki_dla_mapy')) $wynik['orbitalne'] = doki_dla_mapy();
            // Kontakty radaru: jednostki w zasięgu moich okrętów (kolor z transpondera) i ruch w tle.
            if (function_exists('radar_kontakty')) $wynik['obiekty'] = array_merge($wynik['obiekty'], radar_kontakty($u, $wynik['obiekty']));
            // Znacznik własnej postaci: mapa pokazuje przy nim pulsujące „Jesteś tutaj”.
            if (tabela_sql_dostepna('mapa_obiekty')) {
                $q = polaczenie_bazy()->prepare("SELECT id FROM mapa_obiekty WHERE legacy_uid = ? AND typ = 'postac' LIMIT 1");
                $q->execute([(string) $u['id']]);
                $mojId = (int) $q->fetchColumn();
                foreach ($wynik['obiekty'] as &$o) if ((int) $o['id'] === $mojId) $o['ja'] = true;
                unset($o);
            }
            $wynik['wykrycia'] = radar_wykrycia($u, array_column(array_filter($wynik['obiekty'],
                fn($o) => in_array($o['typ'], ['statek', 'jednostka'], true)), 'id'));
            $wynik['gm'] = ($u['rola'] ?? '') === 'mg';
            odpowiedz($wynik);

        case 'nawigacja':
            $u = wymagaj_logowania();
            ruch_dokoncz($u['id']);
            $karta = karta($u['id']);
            $lok = ruch_lokacje();
            $gdzie = ruch_gdzie($karta, $lok);
            $bank = wczytaj('bank', ['konta' => []]);
            $wynik = [
                'gotowka' => (int) ($karta['gotowka'] ?? 0), 'saldo' => $bank['konta'][$u['id']]['saldo'] ?? 0,
                'podroz' => $karta['podroz'] ?? null, 'teraz' => time(), 'gdzie' => $gdzie, 'planeta' => null, 'miejsca' => [], 'statki' => [], 'pojazdy' => [],
            ];
            if ($gdzie) {
                $pl = ruch_planeta($gdzie['planeta']);
                $wynik['planeta'] = ['nazwa' => $pl['nazwa'], 'urbanizacja' => $pl['urbanizacja'] ?? 'brak'];
                $maKoordynaty = is_numeric($gdzie['x'] ?? null) && is_numeric($gdzie['y'] ?? null);
                if ($maKoordynaty) foreach (ruch_znane($u['id'], $karta, $lok, $gdzie['planeta']) as $l) {
                    if ($l['klucz'] === ($gdzie['klucz'] ?? '') || !is_numeric($l['x'] ?? null) || !is_numeric($l['y'] ?? null)) continue;
                    $wynik['miejsca'][] = ['klucz' => $l['klucz'], 'nazwa' => $l['nazwa'], 'typ' => $l['typ'], 'km' => round(ruch_odleglosc($gdzie, $l), 1), 'opis' => $l['opis'] ?? ''];
                }
                usort($wynik['miejsca'], fn($a, $b) => $a['km'] <=> $b['km']);
                foreach (ruch_statki($u['id'], $gdzie['planeta'], $lok) as $s) {
                    $s['obsluga'] = ruch_obsluga(['paliwo' => $s['paliwo']], $s['hangar']);
                    $s['km'] = $maKoordynaty && is_numeric($s['hangar']['x'] ?? null) && is_numeric($s['hangar']['y'] ?? null) ? round(ruch_odleglosc($gdzie, $s['hangar']), 1) : null;
                    $s['na_miejscu'] = ($gdzie['klucz'] ?? '') === $s['hangar']['klucz'];
                    $s['hangar'] = ['klucz' => $s['hangar']['klucz'], 'nazwa' => $s['hangar']['nazwa'], 'jakosc' => (int) ($s['hangar']['jakosc'] ?? 3)];
                    $wynik['statki'][] = $s;
                }
                foreach (ruch_pojazdy($u['id'], $karta, $lok) as $p) {
                    $wynik['pojazdy'][] = ['nazwa' => $p['nazwa'], 'typ' => $p['typ'] ?? '', 'predkosc' => $p['predkosc'] ?? 0, 'wlasciciel' => $p['wlasciciel'],
                        'kierowca' => $p['kierowca_imie'] ?? '', 'gdzie' => $p['gdzie']['nazwa'] ?? 'nieznane', 'km' => $maKoordynaty && $p['gdzie'] && is_numeric($p['gdzie']['x'] ?? null) && is_numeric($p['gdzie']['y'] ?? null) && $p['gdzie']['planeta'] === $gdzie['planeta'] ? round(ruch_odleglosc($gdzie, $p['gdzie']), 1) : null];
                }
                // Na pokładzie: stan statku (start, wznoszenie, orbita, skok).
                if (str_starts_with($gdzie['klucz'] ?? '', 's:')) {
                    $st = ruch_statek($gdzie['klucz']);
                    $sc = wczytaj('sceny', [])[$u['id']] ?? [];
                    $pl = ruch_planeta($st['lokalizacja'] ?? '');
                    $watek = wczytaj('lacznosc', [])[$gdzie['klucz']] ?? null;
                    $wynik['poklad'] = [
                        'ladowiska' => array_values(array_map(fn($l) => ['klucz' => $l['klucz'], 'nazwa' => $l['nazwa'], 'typ' => $l['typ'], 'przydzial' => !empty($l['przydzial'])],
                            !empty($st['w_locie']) ? ruch_ladowiska($u['id'], $karta, $st, $lok) : [])),
                        'kontrola' => trim((string) ($pl['kontrola'] ?? '')),
                        'watek' => $watek ? ['wiadomosci' => array_slice($watek['wiadomosci'], -12), 'czeka' => !empty($watek['czeka'])] : null,
                        'nazwa' => $st['nazwa'] ?? '?', 'w_locie' => !empty($st['w_locie']), 'cel' => $st['cel'] ?? '',
                        'eta' => (int) ($st['skok']['eta_plan'] ?? 0),
                        'gotowy' => !empty($st['przygotowanie']) && time() >= $st['przygotowanie']['gotowy'], 'paliwo' => (int) ($st['paliwo'] ?? 0),
                        'scena' => $sc['scena'] ?? '', 'od_zmiany_ms' => teraz_ms() - (int) ($sc['zmieniono'] ?? 0), 'wznoszenie_ms' => CZAS_WZNOSZENIA_MS,
                    ];
                }
                // W locie nie ma dokąd pójść ani czym pojechać.
                if (!empty($wynik['poklad']['w_locie'])) {
                    $wynik['miejsca'] = [];
                    $wynik['pojazdy'] = [];
                }
                // Na pokładzie okrętu z SQL: panel pokładu (zejście na lądowisko, start); bez chodzenia po galaktyce.
                if (str_starts_with($gdzie['klucz'] ?? '', 'okret:')) {
                    $wynik['miejsca'] = [];
                    $wynik['pojazdy'] = [];
                    $maKoordynaty = false;
                    if (function_exists('floty_ladowanie_dostepne') && floty_ladowanie_dostepne()) {
                        $q = polaczenie_bazy()->prepare('SELECT o.id, o.nazwa, o.ladowisko_id, l.nazwa AS ladowisko FROM mapa_obiekty o
                            LEFT JOIN lokalizacje l ON l.id = o.ladowisko_id WHERE o.id = ?');
                        $q->execute([(int) ($gdzie['okret_id'] ?? 0)]);
                        if ($ok = $q->fetch(PDO::FETCH_ASSOC)) {
                            $wynik['poklad_okret'] = ['id' => (int) $ok['id'], 'nazwa' => $ok['nazwa'], 'ladowisko' => $ok['ladowisko'],
                                'na_ladowisku' => (bool) $ok['ladowisko_id'], 'kapitan' => wskazniki_kapitan('okret:' . (int) $ok['id'], $u)];
                            if (function_exists('floty_prom_info') && ($pr = floty_prom_info($u, (int) $ok['id']))) $wynik['poklad_okret'] += $pr;
                            if (function_exists('wnetrze_info') && ($wn = wnetrze_info($u, (int) $ok['id']))) $wynik['poklad_okret']['wnetrze'] = $wn;
                        }
                    }
                }
                // Pojazdy z innych planet nie są tu przydatne.
                $wynik['pojazdy'] = array_values(array_filter($wynik['pojazdy'], fn($p) => $p['km'] !== null));
                $wynik['ruch_mozliwy'] = $maKoordynaty;
                // Akcje w miejscu: bankomat, sklep.
                if (($gdzie['typ'] ?? '') === 'sklep') $wynik['sklep'] = $gdzie['oferta'] ?? [];
                $wynik['bankomat'] = ($gdzie['typ'] ?? '') === 'bankomat';
            }
            $wynik['moje_statki'] = ruch_moje_statki_sql($u, $gdzie);
            $wynik += ruch_statki_tutaj($u, $gdzie);
            // Własny statek na lądowisku tej planety jest też na liście „Dokąd?” (trasa na jego lądowisko).
            if (empty($wynik['poklad']['w_locie'])) {
                foreach ($wynik['moje_statki'] as $s) {
                    $l = $s['ladowisko'] ?? null;
                    if (!$l || $l['na_miejscu'] || $l['km'] === null) continue;
                    $wynik['miejsca'][] = ['klucz' => $l['klucz'], 'nazwa' => $s['nazwa'] . ' · ' . $l['nazwa'] . ' (twój statek)',
                        'typ' => 'statek', 'km' => $l['km'], 'opis' => ''];
                }
                usort($wynik['miejsca'], fn($a, $b) => $a['km'] <=> $b['km']);
            }
            odpowiedz($wynik);

        case 'statek_wejdz':
            // Wejście na pokład prywatnego statku z SQL, gdy postać stoi na jego lądowisku.
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $id = (int) (wejscie()['obiekt_id'] ?? 0);
            $karta = karta($u['id']);
            $gdzie = ruch_gdzie($karta, ruch_lokacje());
            $q = polaczenie_bazy()->prepare('SELECT ladowisko_id, planeta_id, wlasciciel_login FROM mapa_obiekty WHERE id = ? AND aktywny = 1');
            $q->execute([$id]);
            $s = $q->fetch(PDO::FETCH_ASSOC);
            if (!$s || !$s['ladowisko_id']) blad('Statek nie stoi na lądowisku.');
            if (!ruch_moze_wejsc($u, $id, (string) $s['wlasciciel_login'])) blad('Właz pozostaje zamknięty.', 403);
            // Powrót na ostatni statek (awaryjnie): wystarczy ta sama planeta — postać wraca na jego lądowisko.
            $powrot = !empty(wejscie()['powrot']) && (int) ($karta['pozycja']['ostatni_statek'] ?? 0) === $id
                && (string) ($gdzie['planeta'] ?? '') === (string) $s['planeta_id'];
            if (!$powrot && ($gdzie['klucz'] ?? '') !== 'sql:' . (int) $s['ladowisko_id']) blad('Najpierw dojdź na lądowisko.');
            zmien('karty', function (&$k) use ($u, $id) {
                $k[$u['id']]['pozycja']['lokacja'] = 'okret:' . $id;
                $k[$u['id']]['pozycja']['zmieniono'] = teraz_ms();
            });
            pozycja_sql_zapisz($u['id'], wczytaj('karty', [])[$u['id']]['pozycja'] ?? []);
            odpowiedz(['ok' => true]);

        case 'prom_wejdz':
            // Z pokładu okrętu do promu stojącego w jego hangarze.
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            if (!function_exists('floty_hangar_dostepny') || !floty_hangar_dostepny()) blad('Zaimportuj jeszcze api/migracje/035_promy_w_hangarach.sql.');
            $id = (int) (wejscie()['obiekt_id'] ?? 0);
            $gdzie = ruch_gdzie(karta($u['id']), ruch_lokacje());
            $q = polaczenie_bazy()->prepare('SELECT okret_macierzysty_id, w_hangarze FROM mapa_obiekty WHERE id = ? AND aktywny = 1');
            $q->execute([$id]);
            $p = $q->fetch(PDO::FETCH_ASSOC);
            if (!$p || !$p['okret_macierzysty_id']) blad('Nie ma takiego promu.');
            if (!(int) $p['w_hangarze']) blad('Promu nie ma w hangarze.');
            if ((int) ($gdzie['okret_id'] ?? 0) !== (int) $p['okret_macierzysty_id']) blad('Prom stoi w hangarze innego okrętu.');
            if (function_exists('wnetrze_przy_promie') && !wnetrze_przy_promie((string) $u['id'], (int) $p['okret_macierzysty_id'])) blad('Prom stoi w hangarze promów. Najpierw tam dojdź.');
            zmien('karty', function (&$k) use ($u, $id) {
                $k[$u['id']]['pozycja']['lokacja'] = 'okret:' . $id;
                $k[$u['id']]['pozycja']['zmieniono'] = teraz_ms();
            });
            pozycja_sql_zapisz($u['id'], wczytaj('karty', [])[$u['id']]['pozycja'] ?? []);
            odpowiedz(['ok' => true]);

        case 'prom_wyjdz':
            // Z promu stojącego w hangarze z powrotem na pokład okrętu.
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            if (!function_exists('floty_hangar_dostepny') || !floty_hangar_dostepny()) blad('Zaimportuj jeszcze api/migracje/035_promy_w_hangarach.sql.');
            $gdzie = ruch_gdzie(karta($u['id']), ruch_lokacje());
            $q = polaczenie_bazy()->prepare('SELECT okret_macierzysty_id, w_hangarze FROM mapa_obiekty WHERE id = ?');
            $q->execute([(int) ($gdzie['okret_id'] ?? 0)]);
            $p = $q->fetch(PDO::FETCH_ASSOC);
            if (!$p || !$p['okret_macierzysty_id']) blad('Nie jesteś na promie.');
            if (!(int) $p['w_hangarze']) blad('Prom nie stoi w hangarze — nie da się wyjść.');
            zmien('karty', function (&$k) use ($u, $p) {
                $k[$u['id']]['pozycja']['lokacja'] = 'okret:' . (int) $p['okret_macierzysty_id'];
                $k[$u['id']]['pozycja']['zmieniono'] = teraz_ms();
            });
            pozycja_sql_zapisz($u['id'], wczytaj('karty', [])[$u['id']]['pozycja'] ?? []);
            if (function_exists('wnetrze_do_hangaru')) wnetrze_do_hangaru((string) $u['id'], (int) $p['okret_macierzysty_id']);
            odpowiedz(['ok' => true]);

        case 'statek_zejdz':
            // Zejście z pokładu okrętu z SQL na jego lądowisko.
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $gdzie = ruch_gdzie(karta($u['id']), ruch_lokacje());
            $id = (int) ($gdzie['okret_id'] ?? 0);
            $q = polaczenie_bazy()->prepare('SELECT ladowisko_id, planeta_id FROM mapa_obiekty WHERE id = ?');
            $q->execute([$id]);
            $s = $q->fetch(PDO::FETCH_ASSOC);
            if (!$s || !$s['ladowisko_id']) blad('Statek nie stoi na lądowisku — nie da się zejść.');
            zmien('karty', function (&$k) use ($u, $s, $id) {
                $k[$u['id']]['pozycja']['lokacja'] = 'sql:' . (int) $s['ladowisko_id'];
                $k[$u['id']]['pozycja']['ostatni_statek'] = $id; // do „Wejdź na pokład” i awaryjnego powrotu
                $k[$u['id']]['pozycja']['zmieniono'] = teraz_ms();
            });
            pozycja_sql_zapisz($u['id'], wczytaj('karty', [])[$u['id']]['pozycja'] ?? []);
            odpowiedz(['ok' => true]);

        case 'trasa':
            $u = wymagaj_logowania();
            $karta = karta($u['id']);
            $lok = ruch_lokacje();
            $gdzie = ruch_gdzie($karta, $lok);
            if (ruch_w_locie($karta['pozycja']['lokacja'] ?? '')) blad('Statek jest w locie.');
            $cel = $lok[(string) ($_GET['cel'] ?? '')] ?? null;
            if (!$gdzie || !$cel || $cel['planeta'] !== $gdzie['planeta']) blad('Nie można wyznaczyć trasy.', 404);
            odpowiedz(['opcje' => ruch_opcje($u['id'], $karta, $gdzie, $cel, ruch_planeta($gdzie['planeta']), $lok)]);

        case 'podroz':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            ruch_dokoncz($u['id']);
            $w = wejscie();
            $karta = karta($u['id']);
            if (!empty($karta['podroz'])) blad('Jesteś w drodze.');
            if (ruch_w_locie($karta['pozycja']['lokacja'] ?? '')) blad('Statek jest w locie.');
            $lok = ruch_lokacje();
            $gdzie = ruch_gdzie($karta, $lok);
            $cel = $lok[(string) ($w['cel'] ?? '')] ?? null;
            if (!$gdzie || !$cel || $cel['planeta'] !== $gdzie['planeta']) blad('Nie można wyznaczyć trasy.');
            if (!isset(ruch_znane($u['id'], $karta, $lok, $gdzie['planeta'])[$cel['klucz']])) blad('Nie znasz tego miejsca.');
            $opcje = ruch_opcje($u['id'], $karta, $gdzie, $cel, ruch_planeta($gdzie['planeta']), $lok);
            $wybor = null;
            foreach ($opcje as $o) {
                if ($o['srodek'] === ($w['srodek'] ?? '') && ($o['pojazd'] ?? '') === ($w['pojazd'] ?? '') && (bool) ($o['kierowca'] ?? false) === !empty($w['kierowca'])) $wybor = $o;
            }
            if (!$wybor) blad('Ten środek transportu jest niedostępny.');
            // Opłata (taksówka): kartą albo gotówką.
            if ($wybor['koszt'] > 0) {
                if (($w['platnosc'] ?? 'karta') === 'gotowka') {
                    $ok = zmien('karty', function (&$k) use ($u, $wybor) {
                        if ((int) ($k[$u['id']]['gotowka'] ?? 0) < $wybor['koszt']) return false;
                        $k[$u['id']]['gotowka'] -= $wybor['koszt'];
                        return true;
                    });
                    if (!$ok) blad('Za mało gotówki.');
                } else {
                    $wyn = zmien('bank', fn(&$bank) => bank_obciaz($bank, $u['id'], $wybor['koszt'], 'Korporacja Przewozowa', 'Przejazd: ' . $cel['nazwa'], bank_wlasciciele()), bank_pusty());
                    if ($wyn !== 'ok') blad('Karta odrzucona: brak środków.');
                }
            }
            $sekundy = czas_trwania($wybor['minuty']);
            zmien('karty', function (&$k) use ($u, $cel, $wybor, $sekundy) {
                $k[$u['id']]['podroz'] = [
                    'cel' => $cel['klucz'], 'cel_nazwa' => $cel['nazwa'], 'planeta' => $cel['planeta'],
                    'srodek' => $wybor['nazwa'], 'minuty' => $wybor['minuty'], 'start' => time(), 'koniec' => time() + $sekundy,
                ];
            });
            // Pojazd jedzie razem z postacią.
            if (($wybor['pojazd'] ?? '') !== '') {
                $pk = $wybor['pojazd'];
                if (str_starts_with($pk, 'k:')) {
                    zmien('karty', function (&$k) use ($u, $pk, $cel) {
                        $k[$u['id']]['pojazdy'][(int) substr($pk, 2)]['lokacja'] = $cel['klucz'];
                    });
                } else {
                    [, $fid, $pid] = explode(':', $pk);
                    zmien('firmy', function (&$wsz) use ($fid, $pid, $cel) {
                        foreach ($wsz['firmy'] as &$f) {
                            if ($f['id'] !== $fid) continue;
                            foreach ($f['dane']['pojazdy'] as &$p) {
                                if ($p['id'] === $pid) $p['lokacja'] = $cel['klucz'];
                            }
                        }
                    }, ['firmy' => []]);
                }
            }
            odpowiedz(['ok' => true, 'sekundy' => $sekundy, 'minuty' => $wybor['minuty']]);

        case 'bankomat':
            // Wypłata (kwota > 0) albo wpłata (kwota < 0) gotówki. Tylko przy bankomacie.
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $karta = karta($u['id']);
            $gdzie = ruch_gdzie($karta, ruch_lokacje());
            if (($gdzie['typ'] ?? '') !== 'bankomat') blad('W pobliżu nie ma bankomatu.');
            $kwota = bank_kwota(wejscie()['kwota'] ?? null);
            if ($kwota === 0) blad('Podaj kwotę.');
            if ($kwota > 0) {
                $wyn = zmien('bank', fn(&$bank) => bank_obciaz($bank, $u['id'], $kwota, 'Bankomat: ' . $gdzie['nazwa'], 'Wypłata gotówki', bank_wlasciciele()), bank_pusty());
                if ($wyn !== 'ok') blad('Niewystarczające środki na rachunku.');
                zmien('karty', function (&$k) use ($u, $kwota) { $k[$u['id']]['gotowka'] = (int) ($k[$u['id']]['gotowka'] ?? 0) + $kwota; });
            } else {
                $ok = zmien('karty', function (&$k) use ($u, $kwota) {
                    if ((int) ($k[$u['id']]['gotowka'] ?? 0) < -$kwota) return false;
                    $k[$u['id']]['gotowka'] += $kwota;
                    return true;
                });
                if (!$ok) blad('Nie masz tyle gotówki.');
                zmien('bank', fn(&$bank) => bank_uznaj($bank, $u['id'], -$kwota, 'Bankomat: ' . $gdzie['nazwa'], 'Wpłata gotówki', bank_wlasciciele()), bank_pusty());
            }
            odpowiedz(['ok' => true]);

        case 'sklep_kup':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $w = wejscie();
            $karta = karta($u['id']);
            $gdzie = ruch_gdzie($karta, ruch_lokacje());
            $towar = ($gdzie['oferta'] ?? [])[(int) ($w['pozycja'] ?? -1)] ?? null;
            if (($gdzie['typ'] ?? '') !== 'sklep' || !$towar) blad('Tego tu nie sprzedają.');
            $cena = max(0, (int) $towar['cena']);
            if (($w['platnosc'] ?? 'karta') === 'gotowka') {
                $ok = zmien('karty', function (&$k) use ($u, $cena) {
                    if ((int) ($k[$u['id']]['gotowka'] ?? 0) < $cena) return false;
                    $k[$u['id']]['gotowka'] -= $cena;
                    return true;
                });
                if (!$ok) blad('Za mało gotówki.');
            } else {
                $wyn = zmien('bank', fn(&$bank) => bank_obciaz($bank, $u['id'], $cena, $gdzie['nazwa'], 'Zakup: ' . $towar['nazwa'], bank_wlasciciele()), bank_pusty());
                if ($wyn !== 'ok') blad('Karta odrzucona: brak środków.');
            }
            zmien('karty', function (&$k) use ($u, $towar, $gdzie) {
                $k[$u['id']]['ekwipunek'][] = ['nazwa' => $towar['nazwa'], 'opis' => 'kupione: ' . $gdzie['nazwa']];
            });
            odpowiedz(['ok' => true]);

        case 'statek_przygotuj':
            // Obsługa naziemna: firma płaci za paliwo, hangar tankuje i sprawdza statek.
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            [$fid, $sid] = explode(':', (string) (wejscie()['statek'] ?? '')) + ['', ''];
            $firma = ruch_moje_firmy($u['id'])[$fid] ?? null;
            if (!$firma) blad('To nie jest statek twojej firmy.', 403);
            $lok = ruch_lokacje();
            $wynik = zmien('firmy', function (&$wsz) use ($fid, $sid, $lok, $firma) {
                foreach ($wsz['firmy'] as &$f) {
                    if ($f['id'] !== $fid) continue;
                    foreach ($f['dane']['flota'] as &$s) {
                        if ($s['id'] !== $sid) continue;
                        if (($s['stan'] ?? 'sprawny') !== 'sprawny') blad("Statek nie jest sprawny ({$s['stan']}).");
                        if (!empty($s['przygotowanie']) && time() < $s['przygotowanie']['gotowy']) blad('Obsługa naziemna już trwa.');
                        $hangar = $lok[$s['hangar'] ?? ''] ?? null;
                        if (!$hangar) blad('Statek nie stoi w żadnym hangarze.');
                        $o = ruch_obsluga($s, $hangar);
                        if ($o['koszt'] > 0) {
                            $klucz = 'f:' . $fid;
                            $wyn = zmien('bank', fn(&$bank) => bank_obciaz($bank, $klucz, $o['koszt'], $hangar['nazwa'], 'Paliwo i obsługa: ' . $s['nazwa'], bank_wlasciciele() + [$klucz => $firma['nazwa']]), bank_pusty());
                            if ($wyn !== 'ok') blad('Rachunek firmy nie pokrywa kosztu paliwa (' . $o['koszt'] . ' kr).');
                        }
                        $s['paliwo'] = 100;
                        $s['przygotowanie'] = ['start' => time(), 'gotowy' => time() + czas_trwania($o['minuty']), 'minuty' => $o['minuty']];
                        return $o + ['gotowy' => $s['przygotowanie']['gotowy']];
                    }
                }
                blad('Nie ma takiego statku.', 404);
            }, ['firmy' => []]);
            odpowiedz(['ok' => true] + $wynik);

        case 'na_poklad':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            ruch_dokoncz($u['id']);
            $karta = karta($u['id']);
            $lok = ruch_lokacje();
            $gdzie = ruch_gdzie($karta, $lok);
            $klucz = (string) (wejscie()['statek'] ?? '');
            foreach (ruch_statki($u['id'], $gdzie['planeta'] ?? '', $lok) as $s) {
                if ($s['klucz'] !== $klucz) continue;
                if (($gdzie['klucz'] ?? '') !== $s['hangar']['klucz']) blad('Najpierw dotrzyj do hangaru: ' . $s['hangar']['nazwa'] . '.');
                if (empty($s['przygotowanie']) || time() < $s['przygotowanie']['gotowy']) blad('Obsługa naziemna jeszcze trwa.');
                zmien('karty', function (&$k) use ($u, $s) {
                    $k[$u['id']]['pozycja']['lokacja'] = 's:' . $s['klucz'];
                    $k[$u['id']]['pozycja']['miejsce'] = 'Pokład: ' . $s['nazwa'];
                    $k[$u['id']]['pozycja']['zmieniono'] = teraz_ms();
                });
                pozycja_sql_zapisz($u['id'], wczytaj('karty', [])[$u['id']]['pozycja'] ?? []);
                odpowiedz(['ok' => true]);
            }
            blad('Nie ma takiego statku w pobliżu.', 404);

        case 'zejdz_z_pokladu':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $karta = karta($u['id']);
            $lok = ruch_lokacje();
            $p = $karta['pozycja']['lokacja'] ?? '';
            if (!str_starts_with($p, 's:')) blad('Nie jesteś na pokładzie.');
            if (ruch_w_locie($p)) blad('Statek jest w locie.');
            [, $fid, $sid] = explode(':', $p);
            foreach (ruch_statki($u['id'], ruch_gdzie($karta, $lok)['planeta'] ?? '', $lok) as $s) {
                if ($s['klucz'] === "$fid:$sid") {
                    zmien('karty', function (&$k) use ($u, $s) {
                        $k[$u['id']]['pozycja']['lokacja'] = $s['hangar']['klucz'];
                        $k[$u['id']]['pozycja']['miejsce'] = $s['hangar']['nazwa'];
                        $k[$u['id']]['pozycja']['zmieniono'] = teraz_ms();
                    });
                    pozycja_sql_zapisz($u['id'], wczytaj('karty', [])[$u['id']]['pozycja'] ?? []);
                    odpowiedz(['ok' => true]);
                }
            }
            blad('Statek nie stoi w hangarze.');

        case 'start':
            // Start z hangaru: wszyscy na pokładzie widzą wznoszenie (chmury → kosmos → orbita).
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $klucz = karta($u['id'])['pozycja']['lokacja'] ?? '';
            $statek = ruch_statek($klucz);
            if (!$statek) blad('Nie jesteś na pokładzie statku.');
            if (!empty($statek['w_locie'])) blad('Statek już jest w powietrzu.');
            if (empty($statek['przygotowanie']) || time() < $statek['przygotowanie']['gotowy']) blad('Statek nie przeszedł obsługi naziemnej.');
            $planeta = ruch_planeta($statek['lokalizacja'] ?? '');
            $hangar = ruch_lokacje()[$statek['hangar'] ?? '']['nazwa'] ?? 'hangar';
            ruch_zmien_statek($klucz, function (&$s) {
                $s['w_locie'] = true;
                $s['paliwo'] = max(0, (int) ($s['paliwo'] ?? 100) - 5); // wznoszenie z atmosfery
                unset($s['przygotowanie'], $s['cel']);
            });
            $scena = ['scena' => 'start', 'planeta' => $planeta['typ'] ?? 'skalista', 'odleglosc' => 'blisko',
                'miejsce' => $planeta['nazwa'], 'opis' => 'Start: ' . $hangar, 'wnetrze' => '', 'zmieniono' => teraz_ms()];
            $zaloga = ruch_na_pokladzie($klucz);
            zmien('sceny', function (&$s) use ($zaloga, $scena) {
                foreach ($zaloga as $uid) $s[$uid] = $scena;
            });
            odpowiedz(['ok' => true, 'na_pokladzie' => count($zaloga)]);

        case 'skok':
            // Współrzędne skoku: współrzędne planet z katalogu (pole „wspolrzedne”). Czas i paliwo
            // liczone z odległości na mapie (ruch_skok_wylicz); wyjście następuje samo w terminie.
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $klucz = karta($u['id'])['pozycja']['lokacja'] ?? '';
            $statek = ruch_statek($klucz);
            if (!$statek || empty($statek['w_locie'])) blad('Skok możliwy tylko w locie, poza atmosferą.');
            $sc = (wczytaj('sceny', [])[$u['id']] ?? []);
            if (($sc['scena'] ?? '') === 'start' && teraz_ms() - (int) $sc['zmieniono'] < CZAS_WZNOSZENIA_MS) blad('Statek wciąż jest w atmosferze. Poczekaj na wyjście na orbitę.');
            if (in_array($sc['scena'] ?? '', ['wejscie', 'nadswietlna'], true)) blad('Statek jest już w nadprzestrzeni.');
            $norm = fn($t) => preg_replace('/[^0-9A-Z.\-]/u', '', mb_strtoupper((string) $t));
            $wsp = $norm(wejscie()['wspolrzedne'] ?? '');
            if ($wsp === '') blad('Wprowadź współrzędne.');
            $cel = null;
            foreach (katalog()['planety'] as $p) {
                if (($p['wspolrzedne'] ?? '') !== '' && $norm($p['wspolrzedne']) === $wsp) $cel = $p;
            }
            if (!$cel) blad('Komputer nawigacyjny nie rozpoznaje tych współrzędnych.');
            if ($cel['id'] === ($statek['lokalizacja'] ?? '')) blad('Jesteś już przy tej planecie.');
            $z = ruch_planeta($statek['lokalizacja'] ?? '');
            $obl = ruch_skok_wylicz($statek, $z, $cel);
            if ((int) ($statek['paliwo'] ?? 100) < $obl['paliwo']) blad('Za mało paliwa na ten skok (potrzeba ' . $obl['paliwo'] . '%).');
            $start = czas_teraz();
            $eta = $start + czas_trwania($obl['minuty']);
            ruch_zmien_statek($klucz, function (&$s) use ($cel, $start, $eta, $obl) {
                $s['cel'] = $cel['id'];
                // eta_plan: szacunek komputera pokładowego (resynchronizacja zmienia tylko eta).
                $s['skok'] = ['start' => $start, 'eta' => $eta, 'eta_plan' => $eta, 'paliwo' => $obl['paliwo']];
            });
            if ($obl['z'] && $obl['do']) {
                radar_wykryj_skok($obl['z']['id'], $obl['z']['x'], $obl['z']['y'], $obl['do']['x'], $obl['do']['y'], $eta,
                    'statek_postaci', $klucz, trim((string) ($statek['transponder'] ?? '')) ?: 'Niezidentyfikowany statek');
            }
            lacznosc_zamknij($klucz);
            $scena = ['scena' => 'wejscie', 'planeta' => $cel['typ'] ?? 'skalista', 'odleglosc' => 'srednio',
                'miejsce' => $cel['nazwa'], 'opis' => 'Kurs: ' . $cel['nazwa'], 'wnetrze' => '', 'zmieniono' => teraz_ms()];
            $zaloga = ruch_na_pokladzie($klucz);
            zmien('sceny', function (&$s) use ($zaloga, $scena) {
                foreach ($zaloga as $uid) $s[$uid] = $scena;
            });
            odpowiedz(['ok' => true, 'cel' => $cel['nazwa'], 'eta' => $eta]);

        case 'lacznosc_wyslij':
            // Gracz wywołuje kontrolę lotów planety, przy której jest (jeśli planeta ją ma).
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $klucz = karta($u['id'])['pozycja']['lokacja'] ?? '';
            $statek = ruch_statek($klucz);
            if (!$statek || empty($statek['w_locie']) || !empty($statek['cel'])) blad('Łączność z kontrolą lotów jest możliwa tylko na orbicie.');
            $pl = ruch_planeta($statek['lokalizacja'] ?? '');
            if (trim((string) ($pl['kontrola'] ?? '')) === '') blad('Brak odpowiedzi. Ta planeta nie ma kontroli lotów.');
            $tresc = mb_substr(trim((string) (wejscie()['tresc'] ?? '')), 0, 500) ?: 'Prosimy o przydział lądowiska.';
            $watek = zmien('lacznosc', function (&$l) use ($klucz, $pl, $tresc, $u, $statek) {
                if (($l[$klucz]['planeta'] ?? '') !== $pl['id']) {
                    $l[$klucz] = ['planeta' => $pl['id'], 'kontrola' => trim($pl['kontrola']), 'przydzial' => '', 'wiadomosci' => []];
                }
                $l[$klucz]['wiadomosci'][] = ['od' => 'statek', 'autor' => $u['postac'] . ' (' . $statek['nazwa'] . ')', 'tresc' => $tresc, 'czas' => date('c')];
                $l[$klucz]['czeka'] = true;
                return $l[$klucz];
            });
            // Przyszłe NPC: jeśli odpowie, dopisujemy od razu.
            $npc = lacznosc_odpowiedz_npc($klucz, $watek);
            if ($npc) {
                zmien('lacznosc', function (&$l) use ($klucz, $npc) {
                    $l[$klucz]['wiadomosci'][] = $npc + ['od' => 'kontrola', 'czas' => date('c')];
                    $l[$klucz]['czeka'] = false;
                });
            }
            odpowiedz(['ok' => true]);

        // ----- panel MG -----

        case 'mg_lacznosc_odpowiedz':
            // MG odpowiada jako kontrola lotów i opcjonalnie przydziela lądowisko.
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $klucz = (string) ($w['statek'] ?? '');
            $statek = ruch_statek($klucz);
            if (!$statek || empty($statek['w_locie'])) blad('Ten statek nie jest w locie.');
            $pl = ruch_planeta($statek['lokalizacja'] ?? '');
            $przydzial = (string) ($w['przydzial'] ?? '');
            $lok = ruch_lokacje();
            if ($przydzial !== '' && (!isset($lok[$przydzial]) || $lok[$przydzial]['planeta'] !== $pl['id'])) blad('To lądowisko nie leży na tej planecie.');
            $tresc = mb_substr(trim((string) ($w['tresc'] ?? '')), 0, 800);
            if ($tresc === '' && $przydzial !== '') $tresc = 'Przydział: ' . $lok[$przydzial]['nazwa'] . '. Lądowanie dozwolone.';
            if ($tresc === '') blad('Wiadomość jest pusta.');
            zmien('lacznosc', function (&$l) use ($klucz, $pl, $tresc, $przydzial) {
                if (($l[$klucz]['planeta'] ?? '') !== $pl['id']) {
                    $l[$klucz] = ['planeta' => $pl['id'], 'kontrola' => trim((string) ($pl['kontrola'] ?? '')), 'przydzial' => '', 'wiadomosci' => []];
                }
                $l[$klucz]['wiadomosci'][] = ['od' => 'kontrola', 'autor' => $l[$klucz]['kontrola'] ?: 'Kontrola lotów', 'tresc' => $tresc, 'czas' => date('c')];
                if ($przydzial !== '') $l[$klucz]['przydzial'] = $przydzial;
                $l[$klucz]['czeka'] = false;
            });
            odpowiedz(['ok' => true]);

        case 'mg_statki_w_locie':
            wymagaj_mg();
            $pl = array_column(katalog()['planety'], 'nazwa', 'id');
            $postacie = bank_postacie();
            $lokacje = ruch_lokacje();
            $lacznosc = wczytaj('lacznosc', []);
            $lista = [];
            foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
                foreach ($f['dane']['flota'] ?? [] as $s) {
                    if (empty($s['w_locie'])) continue;
                    $klucz = 's:' . $f['id'] . ':' . $s['id'];
                    $planeta = ruch_planeta($s['lokalizacja'] ?? '');
                    $lad = [];
                    foreach ($lokacje as $k => $l) {
                        if ($l['planeta'] === ($s['lokalizacja'] ?? '') && in_array($l['typ'], LADOWISKA, true)) $lad[] = ['klucz' => $k, 'nazwa' => $l['nazwa'], 'typ' => $l['typ'], 'x' => $l['x'], 'y' => $l['y']];
                    }
                    $watek = $lacznosc[$klucz] ?? null;
                    $lista[] = ['klucz' => $klucz, 'nazwa' => $s['nazwa'], 'firma' => $f['nazwa'], 'przy' => $pl[$s['lokalizacja'] ?? ''] ?? '?',
                        'cel' => $pl[$s['cel'] ?? ''] ?? '', 'eta' => (int) ($s['skok']['eta'] ?? 0), 'zaloga' => array_map(fn($uid) => $postacie[$uid] ?? '?', ruch_na_pokladzie($klucz)),
                        'kontrola' => trim((string) ($planeta['kontrola'] ?? '')), 'ladowiska' => $lad,
                        'watek' => $watek ? ['wiadomosci' => $watek['wiadomosci'], 'czeka' => !empty($watek['czeka']), 'przydzial' => $watek['przydzial']] : null];
                }
            }
            odpowiedz(['statki' => $lista]);

        case 'mg_skok_koniec':
            // Natychmiastowe wyjście z nadprzestrzeni (to samo, co resynchronizacja „Zakończ teraz”).
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $klucz = (string) (wejscie()['statek'] ?? '');
            $statek = ruch_statek($klucz);
            if (!$statek || empty($statek['cel'])) blad('Ten statek nie jest w skoku.');
            ruch_wyjscie_ze_skoku($klucz, czas_teraz());
            odpowiedz(['ok' => true]);

        case 'ladowanie':
            // Automat: lądowisko z listy znanych (albo przydzielone przez kontrolę lotów).
            // Ręcznie: współrzędne „x / y” lądowiska na tej planecie (nie musi być znane).
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $karta = karta($u['id']);
            $klucz = $karta['pozycja']['lokacja'] ?? '';
            $statek = ruch_statek($klucz);
            if (!$statek || empty($statek['w_locie'])) blad('Statek nie jest w locie.');
            if (!empty($statek['cel'])) blad('Statek jest w drodze do innego systemu.');
            $sc = wczytaj('sceny', [])[$u['id']] ?? [];
            if (($sc['scena'] ?? '') === 'start' && teraz_ms() - (int) $sc['zmieniono'] < CZAS_WZNOSZENIA_MS) blad('Statek wciąż się wznosi.');
            $w = wejscie();
            $lok = ruch_lokacje();
            $planeta = $statek['lokalizacja'] ?? '';
            if (($w['tryb'] ?? 'auto') === 'reczne') {
                if (!preg_match_all('/-?\d+(?:[.,]\d+)?/', (string) ($w['wspolrzedne'] ?? ''), $m) || count($m[0]) < 2) blad('Podaj współrzędne w postaci: x / y.');
                [$x, $y] = array_map(fn($v) => (float) str_replace(',', '.', $v), array_slice($m[0], 0, 2));
                $cel = null;
                foreach ($lok as $l) {
                    if ($l['planeta'] === $planeta && in_array($l['typ'], LADOWISKA, true) && ruch_odleglosc($l, ['x' => $x, 'y' => $y]) <= TOLERANCJA_KM) $cel = $l;
                }
                if (!$cel) blad('Pod tymi współrzędnymi nie ma lądowiska. Podejście przerwane.');
            } else {
                $cel = ruch_ladowiska($u['id'], $karta, $statek, $lok)[(string) ($w['cel'] ?? '')] ?? null;
                if (!$cel) blad('Autopilot nie zna tego lądowiska.');
            }
            ruch_zmien_statek($klucz, function (&$s) use ($cel) {
                $s['w_locie'] = false;
                $s['hangar'] = $cel['klucz'];
            });
            lacznosc_zamknij($klucz);
            // Załoga zapamiętuje lądowisko (autopilot zna je przy następnej wizycie).
            $zaloga = ruch_na_pokladzie($klucz);
            zmien('karty', function (&$k) use ($zaloga, $cel) {
                foreach ($zaloga as $uid) {
                    $znane = $k[$uid]['znane'] ?? [];
                    if (!in_array($cel['klucz'], $znane, true)) $k[$uid]['znane'][] = $cel['klucz'];
                }
            });
            $zaloga = ruch_na_pokladzie($klucz);
            zmien('karty', function (&$k) use ($zaloga) {
                foreach ($zaloga as $uid) $k[$uid]['pozycja']['zmieniono'] = teraz_ms();
            });
            foreach ($zaloga as $uid) pozycja_sql_zapisz($uid, wczytaj('karty', [])[$uid]['pozycja'] ?? []);
            odpowiedz(['ok' => true]);
    }
}

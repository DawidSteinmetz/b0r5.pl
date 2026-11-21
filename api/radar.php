<?php
// RADAR: wykrywanie skoków w nadprzestrzeń.
//
// Gdy statek albo flota wchodzi w nadprzestrzeń przy planecie, każda aktywna jednostka na mapie
// (typ statek/jednostka) stojąca przy tej samej planecie zapisuje wykrycie: skąd, szacowany punkt
// wyjścia i szacowany czas przylotu. Szacunek ma błąd — radar może się mylić. Gdy MG
// resynchronizuje skok, wykrycie zostaje bez zmian (dla graczy: radar źle odczytał skok).
//
// Wykrycia widzą ci, którzy widzą jednostkę wykrywającą (MG widzi wszystkie).

declare(strict_types=1);

const RADAR_WIDOCZNE_H = 24;   // jak długo wykrycie wisi na mapie po szacowanym przylocie

// Zapisuje wykrycia skoku. $pomin: id obiektów mapy, które same skaczą (nie wykrywają siebie).
function radar_wykryj_skok(int $planetaId, float $x0, float $y0, float $x1, float $y1, int $eta,
                           string $rodzaj, string $klucz, string $nazwa, array $pomin = [], ?int $wejscie = null): int
{
    if ($planetaId <= 0 || !tabela_sql_dostepna('mapa_wykrycia_skokow') || !tabela_sql_dostepna('mapa_obiekty')) return 0;
    $db = polaczenie_bazy();
    $q = $db->prepare("SELECT id FROM mapa_obiekty WHERE aktywny=1 AND planeta_id=? AND typ IN ('statek','jednostka')");
    $q->execute([$planetaId]);
    $wykrywajace = array_diff(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)), array_map('intval', $pomin));
    if (!$wykrywajace) return 0;

    // Skok floty zaczyna się po manewrze: radar zobaczy go dopiero w chwili wejścia w nadprzestrzeń.
    $teraz = $wejscie ?? czas_teraz();
    $dystans = hypot($x1 - $x0, $y1 - $y0);
    $ins = $db->prepare('INSERT INTO mapa_wykrycia_skokow (obiekt_id, cel_rodzaj, cel_klucz, cel_nazwa, start_x, start_y,
        szac_x, szac_y, margines_pc, szac_eta_utc, wiarygodnosc, wykryto_utc) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($wykrywajace as $id) {
        // Każda jednostka mierzy osobno: błąd 5–20% odległości, czas ±25%.
        $blad = 0.05 + random_int(0, 150) / 1000;
        $margines = max(1.0, $dystans * $blad);
        $kat = random_int(0, 6283) / 1000;
        $r = $margines * random_int(0, 1000) / 1000;
        $szacEta = $teraz + (int) round(($eta - $teraz) * random_int(800, 1250) / 1000);
        $wiarygodnosc = max(1, min(10, (int) round(10 - $blad * 45)));
        $ins->execute([$id, $rodzaj, mb_substr($klucz, 0, 120), mb_substr($nazwa, 0, 160), $x0, $y0,
            $x1 + cos($kat) * $r, $y1 + sin($kat) * $r, round($margines, 2), czas_utc($szacEta), $wiarygodnosc, czas_utc($teraz)]);
    }
    return count($wykrywajace);
}

// Wykrycia do pokazania na mapie. $obiektyWidoczne: id jednostek, które użytkownik widzi.
function radar_wykrycia(array $u, array $obiektyWidoczne): array
{
    if (!tabela_sql_dostepna('mapa_wykrycia_skokow')) return [];
    $mg = ($u['rola'] ?? '') === 'mg';
    if (!$mg && !$obiektyWidoczne) return [];
    $warunek = $mg ? '' : ' AND w.obiekt_id IN (' . implode(',', array_map('intval', $obiektyWidoczne)) . ')';
    $q = polaczenie_bazy()->prepare("SELECT w.id, w.cel_nazwa, w.start_x, w.start_y, w.szac_x, w.szac_y, w.margines_pc,
            w.szac_eta_utc, w.wiarygodnosc, w.wykryto_utc, o.nazwa AS wykryl
        FROM mapa_wykrycia_skokow w JOIN mapa_obiekty o ON o.id = w.obiekt_id
        WHERE w.szac_eta_utc > ? AND w.wykryto_utc <= ? $warunek ORDER BY w.wykryto_utc DESC LIMIT 50");
    $q->execute([czas_utc(czas_teraz() - RADAR_WIDOCZNE_H * 3600), czas_utc(czas_teraz())]);
    return array_map(fn($w) => [
        'id' => (int) $w['id'], 'nazwa' => $w['cel_nazwa'], 'wykryl' => $w['wykryl'],
        'x0' => (float) $w['start_x'], 'y0' => (float) $w['start_y'], 'x' => (float) $w['szac_x'], 'y' => (float) $w['szac_y'],
        'margines' => (float) $w['margines_pc'], 'eta' => czas_z_utc($w['szac_eta_utc']),
        'wykryto' => czas_z_utc($w['wykryto_utc']), 'wiarygodnosc' => (int) $w['wiarygodnosc'],
    ], $q->fetchAll(PDO::FETCH_ASSOC));
}

// Id planety na mapie dla planety z katalogu (po polu „mapa” albo po nazwie).
function radar_planeta_mapy(array $planeta): ?array
{
    if (isset($planeta['mapa']['x'], $planeta['mapa']['y'])) {
        $id = 0;
        if (tabela_sql_dostepna('mapa_planety')) {
            $q = polaczenie_bazy()->prepare('SELECT id FROM mapa_planety WHERE nazwa = ? LIMIT 1');
            $q->execute([(string) ($planeta['nazwa'] ?? '')]);
            $id = (int) $q->fetchColumn();
        }
        return ['id' => $id, 'x' => (float) $planeta['mapa']['x'], 'y' => (float) $planeta['mapa']['y']];
    }
    if (!tabela_sql_dostepna('mapa_planety')) return null;
    // Kolacja utf8mb4_unicode_ci ignoruje wielkość liter i ogonki; spacje usuwamy po obu stronach.
    $q = polaczenie_bazy()->prepare("SELECT id, x, y FROM mapa_planety WHERE REPLACE(nazwa, ' ', '') = ? LIMIT 1");
    $q->execute([str_replace(' ', '', (string) ($planeta['nazwa'] ?? ''))]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ? ['id' => (int) $r['id'], 'x' => (float) $r['x'], 'y' => (float) $r['y']] : null;
}


// ================================================================
// RADAR KONTAKTÓW I TRANSPONDERY (migracja 043)
// ================================================================
// Radar okrętów gracza (na którym jest, którymi dowodzi, do których ma dostęp) widzi jednostki w swoim zasięgu (pc).
// Kontakt pokazuje się tak, jak nadaje jego transponder (nazwa i frakcja; fałszywy — dopóki analiza sygnatury
// nie ujawni prawdy). Kolor: przyjazny — zielony, neutralny — szary, wrogi (wroga frakcja, poszukiwany z bazy
// sygnatur Imperium) — czerwony. Do tego niezarejestrowany ruch w tle zależny od regionu (bogate Jądro — tłok,
// Hune — pustka) i jednostki, których pozycję ktoś udostępnił graczowi (zawsze widoczne, zielone).
const RADAR_ANALIZA_S = 6 * 3600;
const TRANSPONDER_KOSZT = 5000;
const TRANSPONDER_PRZESTAWIENIE_S = 1800;
const RADAR_TLO_GESTOSC = [30, 24, 16, 11, 8, 6, 4, 5, 1, 0]; // jednostek tła na układ według regionu mapy
const RADAR_TLO_PUSTE_SEKTORY = ['hune'];
const RADAR_TLO_MAKS = 400;
const RADAR_TLO_NAZWY = ['Frachtowiec bez rejestracji', 'Kurier', 'Lekki transportowiec', 'Holownik', 'Barka towarowa', 'Prom pasażerski', 'Jacht'];

function radar_dostepny(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT transponder_nazwa, radar_zasieg_pc FROM mapa_obiekty LIMIT 0'); $jest = tabela_sql_dostepna('radar_analizy'); }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// Zasięg radaru jednostki (pc): ręczny albo z długości kadłuba.
function radar_zasieg(array $o): float
{
    if (($o['radar_zasieg_pc'] ?? null) !== null && $o['radar_zasieg_pc'] !== '') return (float) $o['radar_zasieg_pc'];
    $dl = (float) ($o['dlugosc_m'] ?? 0);
    return $dl >= 900 ? 3.0 : ($dl >= 250 ? 1.5 : 0.5);
}

// Okręty, których radar służy graczowi: ten, na którym jest, jednostki jego flot i z dostępem (mapa_obiekty_dostep).
function radar_moje_okrety(array $u): array
{
    $db = polaczenie_bazy();
    $dl = function_exists('mapa_dlugosc_dostepna') && mapa_dlugosc_dostepna() ? ',m.dlugosc_m' : '';
    $q = $db->prepare("SELECT m.id,m.nazwa,m.x,m.y,m.radar_zasieg_pc{$dl},COALESCE(n.komputer_klasa,3) AS komputer FROM mapa_obiekty m
        LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id=m.id
        WHERE m.aktywny=1 AND m.typ IN ('statek','jednostka') AND m.x IS NOT NULL AND (
            m.id=(SELECT c.na_pokladzie_id FROM mapa_obiekty c WHERE c.legacy_uid=? AND c.typ='postac' LIMIT 1)
            OR EXISTS (SELECT 1 FROM mapa_obiekty_dostep d WHERE d.obiekt_id=m.id AND d.konto_login=?)
            OR EXISTS (SELECT 1 FROM mapa_floty_jednostki j JOIN mapa_floty f ON f.id=j.flota_id WHERE j.obiekt_id=m.id AND LOWER(f.dowodca_login)=LOWER(?)))");
    $q->execute([(string) $u['id'], (string) $u['login'], (string) $u['login']]);
    $w = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) $w[(int) $o['id']] = $o + ['zasieg' => radar_zasieg($o)];
    return $w;
}

// Domknięcie analiz, których czas minął: cel w zasięgu analizującego → identyfikacja, inaczej przerwana.
function radar_analizy_dokoncz(): void
{
    $db = polaczenie_bazy();
    $q = $db->prepare("SELECT a.*, m.x AS ax, m.y AS ay FROM radar_analizy a JOIN mapa_obiekty m ON m.id=a.analizujacy_id WHERE a.stan='trwa' AND a.do_utc <= ?");
    $q->execute([czas_utc(czas_teraz())]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $cel = (int) $a['cel_id'];
        if ($cel < 0) { // ruch w tle: cywilna jednostka bez rejestru
            $db->prepare("INSERT INTO radar_identyfikacje (konto_login,cel_id,nazwa,frakcja,poszukiwany,nagroda,kiedy) VALUES (?,?,?,NULL,0,0,?)
                ON DUPLICATE KEY UPDATE nazwa=VALUES(nazwa), kiedy=VALUES(kiedy)")->execute([$a['konto_login'], $cel, 'Cywilna jednostka bez rejestru', $a['do_utc']]);
            $db->prepare("UPDATE radar_analizy SET stan='gotowa' WHERE id=?")->execute([$a['id']]);
            continue;
        }
        $o = $db->prepare('SELECT m.nazwa, m.x, m.y, m.frakcja, m.wlasciciel, m.radar_zasieg_pc, b.nagroda, b.obiekt_id AS w_bazie FROM mapa_obiekty m
            LEFT JOIN radar_baza_sygnatur b ON b.obiekt_id=m.id WHERE m.id=?');
        $o->execute([$cel]);
        $c = $o->fetch(PDO::FETCH_ASSOC);
        $zasieg = radar_zasieg(['radar_zasieg_pc' => null, 'dlugosc_m' => 1600]);
        if (!$c || hypot((float) $c['x'] - (float) $a['ax'], (float) $c['y'] - (float) $a['ay']) > $zasieg) {
            $db->prepare("UPDATE radar_analizy SET stan='przerwana' WHERE id=?")->execute([$a['id']]);
            continue;
        }
        $frakcja = $c['frakcja'] ?: mapa_frakcja_z_opisu((string) $c['wlasciciel']);
        $db->prepare("INSERT INTO radar_identyfikacje (konto_login,cel_id,nazwa,frakcja,poszukiwany,nagroda,kiedy) VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE nazwa=VALUES(nazwa), frakcja=VALUES(frakcja), poszukiwany=VALUES(poszukiwany), nagroda=VALUES(nagroda), kiedy=VALUES(kiedy)")
            ->execute([$a['konto_login'], $cel, $c['nazwa'], $frakcja, $c['w_bazie'] ? 1 : 0, (int) ($c['nagroda'] ?? 0), $a['do_utc']]);
        $db->prepare("UPDATE radar_analizy SET stan='gotowa' WHERE id=?")->execute([$a['id']]);
    }
}

// Kontakty radaru do mapy (dopisywane do obiektów, które gracz i tak widzi). Ze `strona` i `radar` (opis kontaktu).
function radar_kontakty(array $u, array $widoczne): array
{
    if (($u['rola'] ?? '') === 'mg' || !radar_dostepny()) return [];
    radar_analizy_dokoncz();
    $db = polaczenie_bazy();
    $moje = radar_moje_okrety($u);
    $znane = array_flip(array_map(fn($o) => (int) $o['id'], $widoczne));
    $login = (string) $u['login'];
    $ja = in_array($u['frakcja'] ?? '', FRAKCJE, true) ? $u['frakcja'] : null;
    $teraz = czas_teraz();
    // udostępnienia dla mnie
    $q = $db->prepare('SELECT obiekt_id FROM mapa_udostepnienia WHERE LOWER(konto_login)=LOWER(?) AND (do_utc IS NULL OR do_utc > ?)');
    $q->execute([$login, czas_utc($teraz)]);
    $udost = array_flip(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)));
    if (!$moje && !$udost) return [];
    // identyfikacje i analizy w toku
    $q = $db->prepare('SELECT * FROM radar_identyfikacje WHERE konto_login=?');
    $q->execute([$login]);
    $ident = array_column($q->fetchAll(PDO::FETCH_ASSOC), null, 'cel_id');
    $q = $db->prepare("SELECT cel_id, do_utc FROM radar_analizy WHERE konto_login=? AND stan='trwa'");
    $q->execute([$login]);
    $trwa = array_column($q->fetchAll(PDO::FETCH_ASSOC), 'do_utc', 'cel_id');
    $analityk = (bool) array_filter($moje, fn($o) => (int) $o['komputer'] >= 4 || (float) ($o['dlugosc_m'] ?? 0) >= 900);
    // jednostki w zasięgu któregokolwiek mojego okrętu (bez tych w nadprzestrzeni i w hangarach)
    $dl = function_exists('mapa_dlugosc_dostepna') && mapa_dlugosc_dostepna() ? ',m.dlugosc_m' : '';
    $q = $db->query("SELECT m.id,m.typ,m.nazwa,m.rodzaj,m.klasa,m.wlasciciel,m.frakcja AS frakcja_db,m.planeta_id,p.nazwa AS planeta,m.x,m.y,m.znacznik,
            m.transponder_nazwa,m.transponder_frakcja,m.transponder_od,b.nagroda,b.obiekt_id AS w_bazie{$dl}" . mapa_kurs_sql() . "
        FROM mapa_obiekty m LEFT JOIN mapa_planety p ON p.id=m.planeta_id LEFT JOIN radar_baza_sygnatur b ON b.obiekt_id=m.id
        WHERE m.aktywny=1 AND m.typ IN ('statek','jednostka') AND m.x IS NOT NULL" . mapa_bez_hangaru() . "
          AND NOT EXISTS (SELECT 1 FROM mapa_rozkazy_skokow_jednostki j JOIN mapa_rozkazy_skokow r ON r.id=j.rozkaz_id
              WHERE j.obiekt_id=m.id AND j.status='w_nadprzestrzeni' AND COALESCE(r.wejscie_utc, r.wydano_utc) <= UTC_TIMESTAMP())");
    $wynik = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int) $r['id'];
        if (isset($znane[$id]) || isset($moje[$id])) continue;
        $w = isset($udost[$id]);
        if (!$w) foreach ($moje as $m) if (hypot((float) $r['x'] - (float) $m['x'], (float) $r['y'] - (float) $m['y']) <= $m['zasieg']) { $w = true; break; }
        if (!$w) continue;
        $falszywy = $r['transponder_nazwa'] !== null && $r['transponder_od'] !== null && czas_z_utc((string) $r['transponder_od']) <= $teraz;
        $prawdziwaFrakcja = $r['frakcja_db'] ?: mapa_frakcja_z_opisu((string) $r['wlasciciel']);
        $id_ = $ident[$id] ?? null;
        if ($id_) { $nazwa = $id_['nazwa']; $frakcja = $id_['frakcja']; }
        elseif ($falszywy) { $nazwa = $r['transponder_nazwa']; $frakcja = $r['transponder_frakcja'] ?: null; }
        else { $nazwa = $r['nazwa']; $frakcja = $prawdziwaFrakcja; }
        // Poszukiwany: baza sygnatur rozpoznaje go po prawdziwym transponderze albo po analizie sygnatury.
        $poszukiwany = $r['w_bazie'] && (!$falszywy || ($id_ && (int) $id_['poszukiwany']));
        $strona = isset($udost[$id]) ? 'sojusznik' : ($poszukiwany ? 'wrog' : mapa_relacja($ja, $frakcja ?: null));
        $wynik[] = ['id' => $id, 'typ' => $r['typ'], 'nazwa' => $nazwa, 'klasa' => $r['klasa'], 'planeta' => $r['planeta'],
            'x' => (float) $r['x'], 'y' => (float) $r['y'], 'znacznik' => $r['znacznik'] ?: 'trojkat', 'strona' => $strona, 'frakcja' => $frakcja,
            'radar' => ['transponder' => $nazwa, 'frakcja' => $frakcja ?: 'brak', 'udostepniony' => isset($udost[$id]), 'zidentyfikowany' => (bool) $id_,
                'poszukiwany' => $poszukiwany ? (int) ($r['nagroda'] ?? 0) : null, 'analiza_do' => isset($trwa[$id]) ? czas_z_utc((string) $trwa[$id]) : null,
                'mozna_analizowac' => $analityk && !$id_ && !isset($trwa[$id])],
        ] + mapa_kurs_pola($r);
    }
    return array_merge($wynik, radar_tlo($moje, $ident, $trwa, $analityk));
}

// Niezarejestrowany ruch w tle: jednostki cywilne przy układach w zasięgu radaru. Liczba z regionu (sektory
// z RADAR_TLO_PUSTE_SEKTORY — pusto), stała dla układu; krążą powoli po układzie. Ujemne id (nie ma ich w bazie).
function radar_tlo(array $moje, array $ident, array $trwa, bool $analityk): array
{
    if (!$moje) return [];
    $db = polaczenie_bazy();
    $puste = [];
    foreach (katalog()['planety'] ?? [] as $p) {
        foreach (RADAR_TLO_PUSTE_SEKTORY as $s) if (str_contains(mb_strtolower((string) ($p['sektor'] ?? '')), $s)) $puste[mb_strtolower($p['nazwa'])] = true;
    }
    $teraz = czas_teraz();
    $wynik = [];
    $uklady = [];
    foreach ($moje as $m) {
        $r = $m['zasieg'];
        $q = $db->prepare('SELECT id, nazwa, region, x, y FROM mapa_planety WHERE x BETWEEN ? AND ? AND y BETWEEN ? AND ?');
        $q->execute([(float) $m['x'] - $r, (float) $m['x'] + $r, (float) $m['y'] - $r, (float) $m['y'] + $r]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (hypot((float) $p['x'] - (float) $m['x'], (float) $p['y'] - (float) $m['y']) > $r) continue;
            $klucz = round((float) $p['x'], 1) . ':' . round((float) $p['y'], 1); // układ = wspólne współrzędne
            $uklady[$klucz] ??= $p;
        }
    }
    foreach ($uklady as $p) {
        if (isset($puste[mb_strtolower($p['nazwa'])])) continue;
        $baza = RADAR_TLO_GESTOSC[(int) $p['region']] ?? 4;
        $h = crc32('tlo|' . $p['nazwa']);
        $ile = (int) round($baza * (0.6 + ($h % 80) / 100));
        for ($i = 0; $i < $ile && count($wynik) < RADAR_TLO_MAKS; $i++) {
            $s = crc32("tlo|{$p['nazwa']}|{$i}");
            $id = -(($s % 2000000000) + 1);
            $R = (0.4 + ($s % 1000) / 1000 * 5.5) * 149597870.7;             // 0,4–5,9 j.a. od słońca
            $okres = 20 + ($s >> 10) % 200;                                  // dni na pełne okrążenie
            $kat = fmod(($s % 360) + 360 * ($teraz / 86400) / $okres, 360);
            $nazwaTyp = RADAR_TLO_NAZWY[($s >> 4) % count(RADAR_TLO_NAZWY)];
            $znak = strtoupper(base_convert((string) ($s % 46656), 10, 36));
            $idn = $ident[$id] ?? null;
            $wynik[] = ['id' => $id, 'typ' => 'jednostka', 'nazwa' => $idn ? $idn['nazwa'] : "{$nazwaTyp} {$znak}", 'klasa' => '', 'planeta' => null,
                'x' => (float) $p['x'], 'y' => (float) $p['y'], 'znacznik' => 'trojkat', 'strona' => 'neutralny', 'frakcja' => null,
                'uklad_k' => [$R * cos(deg2rad($kat)), $R * sin(deg2rad($kat))], 'kurs' => fmod($kat + 90, 360),
                'radar' => ['transponder' => $idn ? $idn['nazwa'] : "{$nazwaTyp} {$znak}", 'frakcja' => 'brak', 'udostepniony' => false, 'zidentyfikowany' => (bool) $idn,
                    'poszukiwany' => null, 'analiza_do' => isset($trwa[$id]) ? czas_z_utc((string) $trwa[$id]) : null, 'mozna_analizowac' => $analityk && !$idn && !isset($trwa[$id])]];
        }
    }
    return $wynik;
}

// Okręt, na którym gracz może zarządzać transponderem i udostępnieniami: jest na pokładzie albo nim dowodzi.
function radar_moj_okret(array $u, int $id): bool
{
    if (($u['rola'] ?? '') === 'mg') return true;
    return isset(radar_moje_okrety($u)[$id]);
}

// Czy postać może przeprogramować transponder: przemytnik albo łowca nagród z Krętactwem albo Komputerami 3+.
function radar_moze_zmienic_transponder(array $u): ?string
{
    if (($u['rola'] ?? '') === 'mg') return null;
    $legacy = wczytaj('karty', [])[$u['id']]['eote'] ?? null;
    $e = function_exists('eote_gracz_odczytaj') ? eote_gracz_odczytaj((string) $u['id'], is_array($legacy) ? ['eote' => $legacy] : null) : (karta((string) $u['id'])['eote'] ?? null);
    $e = $e ? json_decode(json_encode($e), true) : null;
    if (!$e) return 'Przeprogramowanie transpondera wymaga karty postaci (EotE).';
    if (!in_array($e['kariera'] ?? '', ['przemytnik', 'lowca'], true)) return 'Tylko doświadczeni przemytnicy i łowcy nagród wiedzą, jak to zrobić.';
    $kr = (int) ($e['umiejetnosci']['kretactwo']['ranga'] ?? 0);
    $ko = (int) ($e['umiejetnosci']['komputery']['ranga'] ?? 0);
    if (max($kr, $ko) < 3) return 'Potrzebne Krętactwo albo Komputery co najmniej 3.';
    return null;
}

function obsluz_radar(string $akcja, bool $post): void
{
    $u = wymagaj_logowania();
    if (!radar_dostepny()) blad('Zaimportuj api/migracje/043_radar_transpondery.sql.');
    $db = polaczenie_bazy();
    $w = $post ? wejscie() : $_GET;
    $teraz = czas_teraz();
    switch ($akcja) {
        case 'radar_okret':
            // Stan radaru i transpondera okrętu oraz udostępnienia pozycji.
            $id = (int) ($w['obiekt'] ?? 0);
            if (!radar_moj_okret($u, $id)) blad('To nie twój okręt.', 403);
            $dl = function_exists('mapa_dlugosc_dostepna') && mapa_dlugosc_dostepna() ? ',dlugosc_m' : '';
            $q = $db->prepare("SELECT nazwa, transponder_nazwa, transponder_frakcja, transponder_od, radar_zasieg_pc{$dl} FROM mapa_obiekty WHERE id=?");
            $q->execute([$id]);
            $o = $q->fetch(PDO::FETCH_ASSOC) ?: blad('Nie ma takiego okrętu.');
            $q = $db->prepare('SELECT konto_login, do_utc FROM mapa_udostepnienia WHERE obiekt_id=? AND (do_utc IS NULL OR do_utc > ?)');
            $q->execute([$id, czas_utc($teraz)]);
            $postacie = array_column(array_filter(uzytkownicy(), fn($x) => ($x['rola'] ?? '') !== 'mg'), 'postac', 'login');
            odpowiedz(['zasieg' => radar_zasieg($o),
                'transponder' => ['nazwa' => $o['transponder_nazwa'] ?? $o['nazwa'], 'frakcja' => $o['transponder_frakcja'], 'falszywy' => $o['transponder_nazwa'] !== null,
                    'od' => $o['transponder_od'] ? czas_z_utc((string) $o['transponder_od']) : null],
                'zmiana_powod' => radar_moze_zmienic_transponder($u), 'koszt' => TRANSPONDER_KOSZT,
                'udostepnienia' => array_map(fn($r) => ['login' => $r['konto_login'], 'postac' => $postacie[$r['konto_login']] ?? $r['konto_login'],
                    'do' => $r['do_utc'] ? czas_z_utc((string) $r['do_utc']) : null], $q->fetchAll(PDO::FETCH_ASSOC)),
                'gracze' => array_values(array_map(fn($l, $p) => ['login' => $l, 'postac' => $p], array_keys($postacie), $postacie))]);

        case 'radar_udostepnij':
            if (!$post) blad('Zła metoda.', 405);
            $id = (int) ($w['obiekt_id'] ?? 0);
            if (!radar_moj_okret($u, $id)) blad('To nie twój okręt.', 403);
            $login = (string) ($w['login'] ?? '');
            if (!in_array(mb_strtolower($login), array_map(fn($x) => mb_strtolower((string) $x['login']), uzytkownicy()), true)) blad('Nie ma takiego gracza.');
            $godz = max(0, (int) ($w['godziny'] ?? 0));
            $db->prepare('INSERT INTO mapa_udostepnienia (obiekt_id,konto_login,od_konta,od_utc,do_utc) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE od_konta=VALUES(od_konta), od_utc=VALUES(od_utc), do_utc=VALUES(do_utc)')
                ->execute([$id, $login, (string) $u['login'], czas_utc($teraz), $godz ? czas_utc($teraz + $godz * 3600) : null]);
            odpowiedz(['ok' => true]);

        case 'radar_cofnij':
            if (!$post) blad('Zła metoda.', 405);
            $id = (int) ($w['obiekt_id'] ?? 0);
            if (!radar_moj_okret($u, $id)) blad('To nie twój okręt.', 403);
            $db->prepare('DELETE FROM mapa_udostepnienia WHERE obiekt_id=? AND LOWER(konto_login)=LOWER(?)')->execute([$id, (string) ($w['login'] ?? '')]);
            odpowiedz(['ok' => true]);

        case 'radar_transponder':
            // Fałszywy transponder: koszt z rachunku, zużycie hipernapędu, działa po 30 min przestawiania.
            if (!$post) blad('Zła metoda.', 405);
            $id = (int) ($w['obiekt_id'] ?? 0);
            if (!radar_moj_okret($u, $id)) blad('To nie twój okręt.', 403);
            if ($p = radar_moze_zmienic_transponder($u)) blad($p, 403);
            $nazwa = mb_substr(trim((string) ($w['nazwa'] ?? '')), 0, 120);
            if ($nazwa === '') blad('Podaj nazwę, jaką ma nadawać transponder.');
            $frakcja = (string) ($w['frakcja'] ?? '');
            if ($frakcja !== '' && $frakcja !== 'neutralna' && !in_array($frakcja, FRAKCJE, true)) blad('Nieznana frakcja.');
            if (($u['rola'] ?? '') !== 'mg') {
                $wynik = zmien('bank', function (&$b) use ($u) {
                    return bank_obciaz($b, (string) $u['id'], TRANSPONDER_KOSZT, 'Warsztat elektroniki', 'Przeprogramowanie transpondera', bank_postacie());
                }, bank_pusty());
                if ($wynik !== 'ok') blad('Za mało środków na rachunku (' . TRANSPONDER_KOSZT . ' kr).');
                $db->prepare('UPDATE mapa_naped_jednostki SET sprawnosc_hipernapedu = GREATEST(0, CAST(sprawnosc_hipernapedu AS SIGNED) - 10) WHERE obiekt_id=?')->execute([$id]);
            }
            $db->prepare('UPDATE mapa_obiekty SET transponder_nazwa=?, transponder_frakcja=?, transponder_od=? WHERE id=?')
                ->execute([$nazwa, $frakcja ?: null, czas_utc($teraz + TRANSPONDER_PRZESTAWIENIE_S), $id]);
            odpowiedz(['ok' => true, 'od' => $teraz + TRANSPONDER_PRZESTAWIENIE_S]);

        case 'radar_transponder_przywroc':
            if (!$post) blad('Zła metoda.', 405);
            $id = (int) ($w['obiekt_id'] ?? 0);
            if (!radar_moj_okret($u, $id)) blad('To nie twój okręt.', 403);
            $db->prepare('UPDATE mapa_obiekty SET transponder_nazwa=NULL, transponder_frakcja=NULL, transponder_od=NULL WHERE id=?')->execute([$id]);
            odpowiedz(['ok' => true]);

        case 'radar_analiza':
            // Analiza sygnatury silnika i osłon: duży okręt z mocnym komputerem, cel w zasięgu przez cały czas.
            if (!$post) blad('Zła metoda.', 405);
            $cel = (int) ($w['cel'] ?? 0);
            $moje = radar_moje_okrety($u);
            $kandydaci = array_filter($moje, fn($o) => (int) $o['komputer'] >= 4 || (float) ($o['dlugosc_m'] ?? 0) >= 900);
            if (!$kandydaci) blad('Analizę sygnatur prowadzą tylko duże okręty z mocnym komputerem (np. gwiezdny niszczyciel).');
            $q = $db->prepare("SELECT 1 FROM radar_analizy WHERE konto_login=? AND cel_id=? AND stan='trwa'");
            $q->execute([(string) $u['login'], $cel]);
            if ($q->fetchColumn()) blad('Analiza tego kontaktu już trwa.');
            $analizujacy = array_key_first($kandydaci);
            if ($cel > 0) {
                $q = $db->prepare('SELECT x, y FROM mapa_obiekty WHERE id=?');
                $q->execute([$cel]);
                $c = $q->fetch(PDO::FETCH_ASSOC) ?: blad('Nie ma takiego kontaktu.');
                $analizujacy = null;
                foreach ($kandydaci as $k) if (hypot((float) $c['x'] - (float) $k['x'], (float) $c['y'] - (float) $k['y']) <= $k['zasieg']) { $analizujacy = (int) $k['id']; break; }
                if (!$analizujacy) blad('Kontakt jest poza zasięgiem radaru okrętu, który może prowadzić analizę.');
            }
            $db->prepare("INSERT INTO radar_analizy (konto_login,analizujacy_id,cel_id,od_utc,do_utc,stan) VALUES (?,?,?,?,?,'trwa')")
                ->execute([(string) $u['login'], $analizujacy, $cel, czas_utc($teraz), czas_utc($teraz + RADAR_ANALIZA_S)]);
            odpowiedz(['ok' => true, 'koniec' => $teraz + RADAR_ANALIZA_S]);

        case 'mg_radar_baza':
            wymagaj_mg();
            $baza = $db->query('SELECT b.obiekt_id, b.nagroda, b.opis, m.nazwa, m.klasa FROM radar_baza_sygnatur b LEFT JOIN mapa_obiekty m ON m.id=b.obiekt_id ORDER BY b.nagroda DESC')->fetchAll(PDO::FETCH_ASSOC);
            $statki = $db->query("SELECT id, nazwa, klasa, transponder_nazwa FROM mapa_obiekty WHERE aktywny=1 AND typ IN ('statek','jednostka') ORDER BY nazwa")->fetchAll(PDO::FETCH_ASSOC);
            odpowiedz(['baza' => $baza, 'statki' => $statki]);

        case 'mg_radar_baza_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $id = (int) ($w['obiekt_id'] ?? 0);
            if (!empty($w['usun'])) { $db->prepare('DELETE FROM radar_baza_sygnatur WHERE obiekt_id=?')->execute([$id]); odpowiedz(['ok' => true]); }
            $db->prepare('INSERT INTO radar_baza_sygnatur (obiekt_id,nagroda,opis) VALUES (?,?,?) ON DUPLICATE KEY UPDATE nagroda=VALUES(nagroda), opis=VALUES(opis)')
                ->execute([$id, max(0, (int) ($w['nagroda'] ?? 0)), mb_substr((string) ($w['opis'] ?? ''), 0, 500)]);
            odpowiedz(['ok' => true]);
    }
}

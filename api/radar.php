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
    // Bez skoków własnych i sojuszniczych okrętów (ta sama frakcja, moja flota, okręt, na którym jestem) — to szum.
    if (!$mg && tabela_sql_dostepna('mapa_rozkazy_skokow_jednostki')) {
        $db = polaczenie_bazy();
        $frakcja = $db->quote((string) ($u['frakcja'] ?? ''));
        $login = $db->quote((string) ($u['login'] ?? ''));
        $uid = $db->quote((string) ($u['id'] ?? ''));
        $warunek .= " AND NOT (w.cel_rodzaj='flota' AND EXISTS (SELECT 1 FROM mapa_rozkazy_skokow_jednostki j JOIN mapa_obiekty m ON m.id=j.obiekt_id
            WHERE j.rozkaz_id=w.cel_klucz AND ((m.frakcja IS NOT NULL AND m.frakcja<>'' AND m.frakcja=$frakcja)
              OR m.id=(SELECT c.na_pokladzie_id FROM mapa_obiekty c WHERE c.legacy_uid=$uid AND c.typ='postac' LIMIT 1)
              OR EXISTS (SELECT 1 FROM mapa_floty_jednostki fj JOIN mapa_floty f ON f.id=fj.flota_id WHERE fj.obiekt_id=m.id AND LOWER(f.dowodca_login)=LOWER($login)))))";
    }
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
const RADAR_TLO_PUSTE_SEKTORY = ['hune'];
const RADAR_TLO_MAKS = 400;

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
            // Jednostka NPC ze stałej trasy: prawdziwa nazwa (także tej bez transpondera).
            $nazwaNpc = 'Cywilna jednostka bez rejestru';
            if (tabela_sql_dostepna('npc_trasy')) {
                $qn = $db->prepare('SELECT nazwa FROM npc_trasy WHERE id = ?');
                $qn->execute([-$cel]);
                $nazwaNpc = (string) ($qn->fetchColumn() ?: $nazwaNpc);
            }
            $db->prepare("INSERT INTO radar_identyfikacje (konto_login,cel_id,nazwa,frakcja,poszukiwany,nagroda,kiedy) VALUES (?,?,?,NULL,0,0,?)
                ON DUPLICATE KEY UPDATE nazwa=VALUES(nazwa), kiedy=VALUES(kiedy)")->execute([$a['konto_login'], $cel, $nazwaNpc, $a['do_utc']]);
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

// Ruch w tle: jednostki NPC ze stałych tras (npc_trasy, migracja 044) w układach w zasięgu radaru — każda w innej
// fazie lotu (postój, lot do punktu skoku, dolot); w nadprzestrzeni niewidoczne. Sektor Hune pusty. Id ujemne (−id trasy).
function radar_tlo(array $moje, array $ident, array $trwa, bool $analityk): array
{
    if (!$moje || !tabela_sql_dostepna('npc_trasy')) return [];
    $db = polaczenie_bazy();
    $puste = [];
    foreach (katalog()['planety'] ?? [] as $p) {
        foreach (RADAR_TLO_PUSTE_SEKTORY as $s) if (str_contains(mb_strtolower((string) ($p['sektor'] ?? '')), $s)) $puste[mb_strtolower($p['nazwa'])] = true;
    }
    // Układy w zasięgu radarów (współrzędne mapy) → nazwy planet tych układów.
    $wZasiegu = [];
    foreach ($moje as $m) {
        $r = $m['zasieg'];
        $q = $db->prepare('SELECT nazwa, x, y FROM mapa_planety WHERE x BETWEEN ? AND ? AND y BETWEEN ? AND ?');
        $q->execute([(float) $m['x'] - $r, (float) $m['x'] + $r, (float) $m['y'] - $r, (float) $m['y'] + $r]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (hypot((float) $p['x'] - (float) $m['x'], (float) $p['y'] - (float) $m['y']) <= $r) $wZasiegu[$p['nazwa']] = true;
        }
    }
    if (!$wZasiegu) return [];
    $nazwy = array_keys($wZasiegu);
    $ph = implode(',', array_fill(0, count($nazwy), '?'));
    $q = $db->prepare("SELECT t.*, pa.x AS ax, pa.y AS ay, pb.x AS bx, pb.y AS by_ FROM npc_trasy t
        JOIN mapa_planety pa ON pa.nazwa = t.port_a JOIN mapa_planety pb ON pb.nazwa = t.port_b
        WHERE t.aktywna = 1 AND (t.port_a IN ($ph) OR t.port_b IN ($ph))");
    $q->execute([...$nazwy, ...$nazwy]);
    $teraz = czas_teraz();
    $wynik = [];
    $miejsca = radar_npc_miejsca($nazwy);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) {
        if (count($wynik) >= RADAR_TLO_MAKS) break;
        if (isset($puste[mb_strtolower($t['port_a'])]) || isset($puste[mb_strtolower($t['port_b'])])) continue;
        $f = radar_npc_faza($t, $teraz, $miejsca);
        if (!$f || !isset($wZasiegu[$f['port']])) continue; // w nadprzestrzeni albo poza zasięgiem
        $id = -(int) $t['id'];
        $idn = $ident[$id] ?? null;
        $jawny = (int) $t['rejestr'] === 1 || $idn;
        $nazwa = $jawny ? $t['nazwa'] : 'Niezidentyfikowany statek';
        $frakcja = $jawny ? ($t['frakcja'] ?: null) : null;
        $o = ['id' => $id, 'typ' => 'jednostka', 'nazwa' => $nazwa, 'klasa' => $jawny ? $t['klasa'] : '', 'planeta' => null,
            'x' => $f['x'], 'y' => $f['y'], 'znacznik' => 'trojkat', 'strona' => 'neutralny', 'frakcja' => $frakcja, 'kurs' => $f['kurs'],
            'radar' => ['transponder' => $nazwa, 'frakcja' => $frakcja ?: 'brak', 'udostepniony' => false, 'zidentyfikowany' => (bool) $idn,
                'poszukiwany' => null, 'analiza_do' => isset($trwa[$id]) ? czas_z_utc((string) $trwa[$id]) : null,
                'mozna_analizowac' => $analityk && !$idn && !isset($trwa[$id]),
                'trasa' => $jawny ? "{$t['port_a']} ⇄ {$t['port_b']}" : null, 'ladunek' => $jawny ? $t['ladunek'] : null, 'faza' => $f['opis'], 'faza_do' => $f['do']]];
        // Przy planecie portu: na lądowisku (geo) albo w atmosferze (start, lądowanie). Przypisanie wprost — „+=”
        // nie nadpisałby 'planeta' => null.
        if ($f['planeta'] !== null) { $o['planeta'] = $f['planeta']; $o['podejscie'] = 'bliska'; }
        if ($f['ladowisko'] !== null) { $o['na_ladowisku'] = $f['ladowisko']; $o['geo'] = $f['geo']; }
        if ($f['atmosfera'] !== null) $o['atmosfera'] = $f['atmosfera'];
        if ($f['lot'] !== null) $o['podswietlny'] = $f['lot'];
        if ($f['k'] !== null) $o['uklad_k'] = $f['k'];
        $wynik[] = $o;
    }
    return $wynik;
}

// Miejsca lądowania NPC na planetach portów: lądowiska, porty, hangary, magazyny i bazy z lokalizacji
// (nazwa i współrzędne geograficzne). planeta => [[nazwa, szer, dl], …].
function radar_npc_miejsca(array $planety): array
{
    $w = [];
    if (!$planety || !tabela_sql_dostepna('lokalizacje')) return $w;
    $ph = implode(',', array_fill(0, count($planety), '?'));
    try {
        $q = polaczenie_bazy()->prepare("SELECT l.id, l.nazwa, p.nazwa AS planeta FROM lokalizacje l JOIN mapa_planety p ON p.id = l.planeta_id
            WHERE p.nazwa IN ($ph) AND l.status = 'aktywna' AND (l.typ = 'ladowisko' OR l.rodzaj IN ('hangar','port','platforma','magazyn','baza'))
              AND l.orbita_wysokosc_km IS NULL ORDER BY l.id");
        $q->execute($planety);
    } catch (Throwable $e) {
        $q = polaczenie_bazy()->prepare("SELECT l.id, l.nazwa, p.nazwa AS planeta FROM lokalizacje l JOIN mapa_planety p ON p.id = l.planeta_id
            WHERE p.nazwa IN ($ph) AND l.status = 'aktywna' AND (l.typ = 'ladowisko' OR l.rodzaj IN ('hangar','port','platforma','magazyn','baza')) ORDER BY l.id");
        $q->execute($planety);
    }
    $szablony = function_exists('wnetrze_pomieszczenia_szablonow') ? wnetrze_pomieszczenia_szablonow() : [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $l) {
        if (isset($szablony[(int) $l['id']])) continue;
        $cykl = astro_cykl((string) $l['planeta']);
        [$s, $d] = function_exists('atm_geo_lokacji') ? atm_geo_lokacji((int) $l['id'], $cykl) : [0.0, 0.0];
        $w[$l['planeta']][] = [(string) $l['nazwa'], $s, $d];
    }
    return $w;
}

// Miejsce lądowania trasy w porcie (stałe dla trasy i strony). Planeta ma setki lądowisk: kopalnie, rafinerie,
// składy, porty — każda trasa dostaje własne, rozrzucone po całej planecie (równomiernie na kuli). Prawdziwe
// lokacje z bazy (lądowiska, porty, magazyny) biorą tylko część tras — inaczej wszyscy lądowaliby w jednym miejscu.
function radar_npc_ladowisko(array $t, string $port, array $miejsca, string $strona): array
{
    $h = crc32("npc|{$t['id']}|{$strona}");
    $lista = $miejsca[$port] ?? [];
    if ($lista && ($h % 100) < min(60, 3 * count($lista))) return $lista[($h >> 7) % count($lista)];
    $l = mb_strtolower((string) $t['ladunek']);
    $rodzaj = str_contains($l, 'ruda') ? 'Kopalnia rudy' : (str_contains($l, 'gaz') || str_contains($l, 'paliw') ? 'Rafineria'
        : (str_contains($l, 'żywno') || str_contains($l, 'zboże') || str_contains($l, 'racje') ? 'Skład rolniczy'
        : (str_contains($l, 'pasaż') || str_contains($l, 'pielgrzym') || str_contains($l, 'robotni') ? 'Port pasażerski'
        : (str_contains($l, 'durastal') || str_contains($l, 'części') || str_contains($l, 'płyty') ? 'Zakłady przemysłowe' : 'Lądowisko towarowe'))));
    $g = crc32("lad|{$port}|{$t['id']}|{$strona}");
    $szer = rad2deg(asin(((($g % 20001) / 10000) - 1) * 0.9));       // równomiernie na kuli, bez biegunów
    $dl = (($g >> 9) % 36000) / 100 - 180;
    $oznaczenie = strtoupper(base_convert((string) ($g % 1296), 10, 36)) . '-' . (($g >> 5) % 90 + 10);
    return ["{$rodzaj} {$oznaczenie}", $szer, $dl];
}

// Faza cyklu trasy NPC w chwili $t (po każdej stronie): postój na lądowisku (2–3 h załadunku) → start i wznoszenie
// → szybki lot do punktu skoku → nadprzestrzeń (niewidoczna) → dolot z punktu wyjścia → wejście w atmosferę i lot
// nad powierzchnią → lądowanie przy kopalni, porcie czy magazynie → … i z powrotem tą samą procedurą.
// Zwraca null w nadprzestrzeni, inaczej: port (układ), x/y mapy, planeta, lot podświetlny (km od słońca),
// atmosfera (jak u jednostek graczy), lądowisko z geo, kurs i opis.
function radar_npc_faza(array $t, int $teraz, array $miejsca = []): ?array
{
    $h = crc32('npc|' . $t['id']);
    $a = ['nazwa' => $t['port_a'], 'x' => (float) $t['ax'], 'y' => (float) $t['ay']];
    $b = ['nazwa' => $t['port_b'], 'x' => (float) $t['bx'], 'y' => (float) $t['by_']];
    $dx = $b['x'] - $a['x']; $dy = $b['y'] - $a['y'];
    $pc = max(1.0, hypot($dx, $dy));
    $ux = $dx / $pc; $uy = $dy / $pc;
    $v = max(0.5, (float) $t['predkosc_au_h']) * 2.5;                 // j.a./h — w miarę szybko do punktu skoku
    $dj = 1.5 + ($h % 150) / 100;                                     // punkt skoku 1,5–3 j.a. od planety
    $lot = (int) round($dj / $v * 3600);
    $skok = (int) round(min(40, 3 + $pc / 150) * 3600);
    $postoj = (int) round(max(2.0, min(3.0, (float) $t['postoj_h'])) * 3600);
    $wzn = START_WZNOSZENIE_S;
    $vAtm = 800 + $h % 500;                                           // km/h w atmosferze
    $lad = ['A' => radar_npc_ladowisko($t, $a['nazwa'], $miejsca, 'A'), 'B' => radar_npc_ladowisko($t, $b['nazwa'], $miejsca, 'B')];
    $cyklA = astro_cykl($a['nazwa']); $cyklB = astro_cykl($b['nazwa']);
    // Wejście w atmosferę 4–14° od lądowiska (pilot planuje zejście blisko celu): czas lotu nad powierzchnią.
    $atmS = function (array $cykl, array $l) use ($h, $vAtm): array {
        $odsun = 4 + $h % 11;
        $s0 = $l[1] * 0.85; $d0 = $l[2] - $odsun;
        $km = function_exists('atm_luk_km') ? atm_luk_km((float) $cykl['srednica_km'] / 2, $s0, $d0, $l[1], $l[2]) : 1000;
        return [$s0, $d0, ATM_WEJSCIE_S + (int) round($km / $vAtm * 3600)];
    };
    $atmA = $atmS($cyklA, $lad['A']); $atmB = $atmS($cyklB, $lad['B']);
    // [długość, rodzaj, port, strona, kierunek ruchu]
    $etapy = [[$postoj, 'postoj', $a, 'A', 1], [$wzn, 'start', $a, 'A', 1], [$lot, 'wylot', $a, 'A', 1], [$skok, 'skok', null, '', 1],
              [$lot, 'dolot', $b, 'B', 1], [$atmB[2], 'ladowanie', $b, 'B', 1],
              [$postoj, 'postoj', $b, 'B', -1], [$wzn, 'start', $b, 'B', -1], [$lot, 'wylot', $b, 'B', -1], [$skok, 'skok', null, '', -1],
              [$lot, 'dolot', $a, 'A', -1], [$atmA[2], 'ladowanie', $a, 'A', -1]];
    $cykl = array_sum(array_column($etapy, 0));
    $tc = fmod($teraz + (float) $t['faza'] * $cykl, $cykl);
    foreach ($etapy as $e) {
        if ($tc >= $e[0]) { $tc -= $e[0]; continue; }
        if ($e[1] === 'skok') return null;
        [, $rodzaj, $port, $strona, $kier] = $e;
        $l = $lad[$strona];
        $cyklP = $strona === 'A' ? $cyklA : $cyklB;
        $mx = $ux * $kier; $my = $uy * $kier;                        // kierunek ruchu w galaktyce
        $od = $teraz - (int) $tc;
        $w = ['port' => $port['nazwa'], 'x' => $port['x'], 'y' => $port['y'], 'planeta' => null, 'lot' => null, 'k' => null, 'do' => $od + $e[0],
            'atmosfera' => null, 'ladowisko' => null, 'geo' => null, 'kurs' => fmod(rad2deg(atan2($my, $mx)) + 360, 360)];
        if ($rodzaj === 'postoj') {
            $w['planeta'] = $port['nazwa'];
            $w['ladowisko'] = $l[0];
            $w['geo'] = ['szer' => round($l[1], 4), 'dl' => round($l[2], 4)];
            $w['opis'] = 'na lądowisku: ' . $l[0] . ' — załadunek';
            return $w;
        }
        if ($rodzaj === 'start') {
            // Prosto w górę i na orbitę w stronę punktu skoku.
            $w['planeta'] = $port['nazwa'];
            $w['atmosfera'] = ['rodzaj' => 'start', 'od' => $od, 'do' => $od + $e[0], 'szer0' => $l[1], 'dl0' => $l[2], 'szer1' => $l[1], 'dl1' => $l[2],
                'faza_s' => $e[0], 'orbita' => $w['kurs']];
            $w['opis'] = 'start z: ' . $l[0];
            return $w;
        }
        if ($rodzaj === 'ladowanie') {
            $atm = $strona === 'A' ? $atmA : $atmB;
            $w['planeta'] = $port['nazwa'];
            $w['atmosfera'] = ['rodzaj' => 'ladowanie', 'od' => $od, 'do' => $od + $e[0], 'szer0' => $atm[0], 'dl0' => $atm[1], 'szer1' => $l[1], 'dl1' => $l[2],
                'faza_s' => ATM_WEJSCIE_S, 'orbita' => null];
            $w['opis'] = 'wejście w atmosferę, lądowanie: ' . $l[0];
            return $w;
        }
        $pk = astro_pozycja_km($cyklP, $teraz);
        $djKm = $dj * ASTRO_KM_NA_AU;
        if ($rodzaj === 'wylot') { $k0 = $pk; $k1 = [$pk[0] + $mx * $djKm, $pk[1] + $my * $djKm]; $w['opis'] = 'lot do punktu skoku'; }
        else { $k0 = [$pk[0] - $mx * $djKm, $pk[1] - $my * $djKm]; $k1 = $pk; $w['opis'] = 'dolot z punktu skoku do ' . $port['nazwa']; }
        $w['lot'] = ['od' => $od, 'do' => $od + $e[0], 'x0' => $port['x'], 'y0' => $port['y'], 'x1' => $port['x'], 'y1' => $port['y'],
            'planeta' => null, 'km' => $djKm, 'k0' => $k0, 'k1' => $k1];
        $w['k'] = $k1;
        return $w;
    }
    return null;
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

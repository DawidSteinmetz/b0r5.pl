<?php
// „Stan faktyczny” postaci: gdzie jest i co widzi w tle datapada (scena ustawiana przez MG).
//   scena:     kosmos | wejscie | nadswietlna | wyjscie | orbita
//   planeta:   typ planety (pustynna, lodowa, oceaniczna, lesna, wulkaniczna, gazowa, miasto, bagienna, skalista)
//   odleglosc: blisko | srednio | daleko
//   miejsce:   nazwa miejsca (np. planety z katalogu) — wpływa też na wygląd powierzchni
//   opis:      krótki tekst na pulpicie, np. „Kurs: Kashyyyk”
// Datapady pytają o scenę co kilka sekund, więc zmiana MG pojawia się na tabletach w trakcie sesji.

declare(strict_types=1);

const SCENY = ['kosmos', 'wejscie', 'nadswietlna', 'wyjscie', 'orbita', 'powierzchnia', 'start'];
const WNETRZA = ['', 'biuro', 'hotel', 'kantyna', 'sklep', 'garaz', 'hangar', 'port', 'inne'];
const TYPY_PLANET = ['pustynna', 'lodowa', 'oceaniczna', 'lesna', 'wulkaniczna', 'gazowa', 'miasto', 'bagienna', 'skalista'];
const ODLEGLOSCI = ['blisko', 'srednio', 'daleko'];

function scena_domyslna(): array
{
    return ['scena' => 'kosmos', 'planeta' => 'oceaniczna', 'odleglosc' => 'srednio', 'miejsce' => '', 'opis' => '', 'wnetrze' => '', 'zmieniono' => 0];
}

// Co widzi postać: krajobraz planety, na której stoi (z okna, z hangaru), albo scenę MG / lotu.
// Wygrywa nowsza zmiana: dotarcie na miejsce (pozycja) albo ustawienie sceny (MG, start, skok).
// SCENA NA POKŁADZIE OKRĘTU (SQL) — liczona automatycznie ze stanu okrętu, bez kliknięć MG:
//   manewr przed skokiem → widok z mostka obraca się (kurs z manewrem),
//   wejście w nadprzestrzeń (wejscie_utc) → animacja skoku, potem tunel,
//   wyjście (przylot_utc) → animacja wyjścia, podejście: planeta mała (do 5 min), średnia (do 15 min),
//   potem orbita z dużą planetą; rozbity okręt → powierzchnia planety.
// ISD ma okno mostka (`okno` = 'isd'). `widok` (kurs, orbita, cykl planety) pozwala tabletowi
// liczyć na bieżąco, gdzie w oknie jest planeta i słońce.
const PODEJSCIE_DALEKO_MIN = 5;
const PODEJSCIE_SREDNIO_MIN = 15;
const PODEJSCIE_KONIEC_MIN = 25;

// Planeta, w której układzie leżą współrzędne (do 2 jednostek mapy), widoczna dla konta.
function scena_planeta_przy(float $x, float $y): ?array
{
    if (!tabela_sql_dostepna('mapa_planety')) return null;
    $gry = tabela_sql_dostepna('planety_gry');
    $q = polaczenie_bazy()->prepare('SELECT p.id, p.nazwa' . ($gry ? ', g.typ_planety' : '') . ' FROM mapa_planety p'
        . ($gry ? ' LEFT JOIN planety_gry g ON g.planeta_id=p.id' : '')
        . ' WHERE ABS(p.x-?) < ? AND ABS(p.y-?) < ? ORDER BY POW(p.x-?,2)+POW(p.y-?,2), p.id LIMIT 1');
    // Planeta za oknem tylko w strefie grawitacyjnej jej układu.
    $s = defined('STREFA_UKLADU_PC') ? STREFA_UKLADU_PC : 0.05;
    $q->execute([$x, $s, $y, $s, $x, $y]);
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p || !mapa_planeta_widoczna((int) $p['id'])) return null;
    return ['nazwa' => (string) $p['nazwa'], 'typ' => (string) (($p['typ_planety'] ?? '') ?: 'skalista')];
}

// Gwiazdy sąsiednich układów widoczne przez okno: kierunek (układ mapy), odległość w pc,
// stała wysokość w oknie i odcień z nazwy (mapa jest płaska). Tablet dobiera jasność do odległości:
// do 50 pc jasna z poświatą, 50–300 pc jasny punkt, 300–1000 pc słaby punkt, dalej — tło.
const GWIAZDY_ZASIEG_PC = 1000;
const GWIAZDY_MAKS = 40;

function scena_gwiazdy(float $x, float $y): array
{
    if (!tabela_sql_dostepna('mapa_planety')) return [];
    $z = GWIAZDY_ZASIEG_PC;
    $q = polaczenie_bazy()->prepare('SELECT id, nazwa, x, y FROM mapa_planety
        WHERE x BETWEEN ? AND ? AND y BETWEEN ? AND ? ORDER BY POW(x-?,2)+POW(y-?,2) LIMIT ' . (GWIAZDY_MAKS + 20));
    $q->execute([$x - $z, $x + $z, $y - $z, $y + $z, $x, $y]);
    $wynik = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $dx = (float) $p['x'] - $x; $dy = (float) $p['y'] - $y;
        $d = hypot($dx, $dy);
        if ($d < 2 || $d > $z) continue; // własny układ (słońce rysuje się osobno) i poza zasięgiem
        $h = crc32(mb_strtolower((string) $p['nazwa']));
        // Typ gwiazdy: kolor i jasność; „de” = odległość pozorna (jasny olbrzym wygląda na bliższy).
        $typ = astro_typ_gwiazdy((string) $p['nazwa']);
        $g = astro_gwiazda($typ);
        $gw = ['k' => round(fmod(rad2deg(atan2($dy, $dx)) + 360, 360), 2), 'd' => round($d, 1),
            'de' => round($d / sqrt($g['L']), 1), 'rgb' => $g['rgb'],
            'w' => round(($h % 2000) / 1000 - 1, 3), 'o' => ($h >> 11) % 3,
            'typ' => $typ . (astro_hash((string) $p['nazwa'], 77) % 10)];
        // Układ znany graczowi: nazwa. Nieznany (zastrzeżony): sama gwiazda i znacznik do skanu —
        // dla gracza to jedna z milionów gwiazd.
        if (mapa_planeta_widoczna((int) $p['id'])) $gw['n'] = (string) $p['nazwa'];
        else $gw['t'] = scena_znacznik_gwiazdy((int) $p['id']);
        $wynik[] = $gw;
        if (count($wynik) >= GWIAZDY_MAKS) break;
    }
    return $wynik;
}

// Znacznik nieznanej gwiazdy (do skanu): id układu podpisane kluczem sesji — gracz nie zgadnie innych.
function scena_znacznik_gwiazdy(int $id): string
{
    if (empty($_SESSION['gwiazdy_sol'])) $_SESSION['gwiazdy_sol'] = bin2hex(random_bytes(16));
    return $id . '.' . substr(hash_hmac('sha256', (string) $id, (string) $_SESSION['gwiazdy_sol']), 0, 12);
}

// Skan gwiazdy z okna: ile planet ma układ (bez nazw i bez śladów cywilizacji).
function scena_skan_gwiazdy(string $znacznik): array
{
    [$id] = explode('.', $znacznik . '.');
    $id = (int) $id;
    if ($id <= 0 || !hash_equals(scena_znacznik_gwiazdy($id), $znacznik)) blad('Skaner nie namierza tej gwiazdy.');
    $q = polaczenie_bazy()->prepare('SELECT nazwa, x, y FROM mapa_planety WHERE id=?');
    $q->execute([$id]);
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p) blad('Skaner nie namierza tej gwiazdy.');
    $s = defined('STREFA_UKLADU_PC') ? STREFA_UKLADU_PC : 0.05;
    $q = polaczenie_bazy()->prepare('SELECT COUNT(*) FROM mapa_planety WHERE ABS(x-?) < ? AND ABS(y-?) < ?');
    $q->execute([(float) $p['x'], $s, (float) $p['y'], $s]);
    // Ciała z mapy (planeta i księżyce) plus planety, których mapa nie notuje (stałe dla układu).
    $planety = (int) $q->fetchColumn() + astro_hash((string) $p['nazwa'], 78) % 6;
    return ['planety' => $planety];
}

// Okręt to ISD (okno mostka), jeśli klasa albo nazwa na to wskazuje.
function scena_jest_isd(string $tekst): bool
{
    return (bool) preg_match('/imperial|\bisd\b|niszczyciel/i', $tekst);
}

// Rodzaj okna z kokpitu/mostka według klasy: 'isd', 'komrk' albo '' (pełny ekran).
function scena_okno(string $tekst): string
{
    if (scena_jest_isd($tekst)) return 'isd';
    if (preg_match("/kom'?rk/iu", $tekst)) return 'komrk';
    return '';
}

// Widok z punktu układu (km od słońca): planeta i gwiazda w kierunkach i rozmiarach z prawdziwych odległości.
function scena_z_punktu_ukladu(array $o, array $gwiazdy, string $planeta, float $kx, float $ky, int $teraz, bool $wyjscie): array
{
    $cykl = astro_cykl($planeta);
    [$px, $py] = astro_pozycja_km($cykl, $teraz);
    $d = max(1.0, hypot($kx - $px, $ky - $py));
    $widok = mapa_kurs_pola($o) + ['gwiazdy' => $gwiazdy, 'cykl' => $cykl,
        'orbita' => round(floty_kat(rad2deg(atan2($ky - $py, $kx - $px))), 2),
        'wysokosc_km' => max(NISKA_ORBITA_KM, $d - (float) $cykl['srednica_km'] / 2),
        'slonce' => ['kat' => round(floty_kat(rad2deg(atan2(-$ky, -$kx))), 2), 'km' => round(max(1.0, hypot($kx, $ky)))]];
    $kraw = (float) ($cykl['krawedz_au'] ?? 50) * ASTRO_KM_NA_AU;
    $typ = 'skalista'; // typ planety: z katalogu gry, gdy dostępny
    if (tabela_sql_dostepna('planety_gry')) {
        $q = polaczenie_bazy()->prepare('SELECT g.typ_planety FROM planety_gry g JOIN mapa_planety p ON p.id = g.planeta_id WHERE p.nazwa = ?');
        $q->execute([$planeta]);
        $typ = (string) ($q->fetchColumn() ?: $typ);
    }
    return ['scena' => $wyjscie ? 'wyjscie' : 'orbita', 'planeta' => $typ,
        'odleglosc' => hypot($kx, $ky) > $kraw * 0.8 ? 'krawedz' : 'daleko', 'miejsce' => $planeta, 'widok' => $widok];
}

// ---------- grafiki miejsc (panel MG → Grafiki lokacji) ----------
// Przypisanie: klucz lokacji (pomieszczenie okrętu i lokacja SQL: „sql:<id>”, inne: klucz z ruch_lokacje) → plik
// w assets/ (albo „brak”: bez grafiki, także bez reguł). Reguły: fragment nazwy → plik, pierwsza pasująca wygrywa.
function grafiki_dane(): array
{
    $g = wczytaj('grafiki_lokacji', []);
    return ['przypisania' => (array) ($g['przypisania'] ?? []), 'reguly' => array_values((array) ($g['reguly'] ?? []))];
}

function grafika_poprawna(string $plik): bool
{
    return (bool) preg_match('#^assets/wnetrza/(wlasne/)?[a-z0-9_-]+\.(svg|png|jpg|webp)$#', $plik) && is_file(dirname(__DIR__) . '/' . $plik);
}

// Biblioteka: grafiki z listy (nazwy, reguły domyślne) i własne pliki MG.
function grafiki_lista(): array
{
    $baza = dirname(__DIR__) . '/assets/wnetrza';
    $lista = is_file($baza . '/lista.json') ? (json_decode((string) file_get_contents($baza . '/lista.json'), true) ?: []) : [];
    $znane = array_column($lista, 'plik');
    $pliki = array_filter(array_merge(glob($baza . '/*') ?: [], glob($baza . '/wlasne/*') ?: []), fn($f) => preg_match('/\.(svg|png|jpg|webp)$/', $f));
    foreach ($pliki as $f) {
        $p = 'assets/wnetrza/' . (str_contains($f, '/wlasne/') ? 'wlasne/' : '') . basename($f);
        if (!in_array($p, $znane, true)) $lista[] = ['plik' => $p, 'nazwa' => pathinfo($f, PATHINFO_FILENAME), 'reguly' => [], 'wlasna' => true];
    }
    return $lista;
}

// Grafika miejsca, w którym jest postać: pomieszczenie okrętu (w drodze — korytarz) albo lokacja z pozycji.
function grafika_miejsca(string $uid): ?string
{
    $g = grafiki_dane();
    if (!$g['przypisania'] && !$g['reguly']) return null;
    $karta = karta($uid);
    $gdzie = ruch_gdzie($karta, ruch_lokacje());
    $klucz = null; $nazwa = '';
    if ($gdzie && !empty($gdzie['okret_id']) && function_exists('wnetrze_postaci') && ($st = wnetrze_postaci($uid, (int) $gdzie['okret_id']))) {
        if ($st['przejscie']) { $nazwa = 'korytarz'; }
        else {
            $w = wnetrze_wezly($st['plan']);
            $klucz = 'sql:' . $st['pomieszczenie'];
            $nazwa = (string) ($w[$st['pomieszczenie']]['krotka'] ?? '');
        }
    } elseif ($gdzie) {
        $klucz = (string) ($karta['pozycja']['lokacja'] ?? '') ?: null;
        $nazwa = (string) ($gdzie['nazwa'] ?? '');
    }
    if ($klucz && isset($g['przypisania'][$klucz])) {
        $p = $g['przypisania'][$klucz];
        return $p !== 'brak' && grafika_poprawna($p) ? $p : null;
    }
    $n = mb_strtolower($nazwa);
    if ($n === '') return null;
    foreach ($g['reguly'] as $r) if (mb_strpos($n, (string) $r['tekst']) !== false && grafika_poprawna((string) $r['plik'])) return (string) $r['plik'];
    return null;
}

function scena_okretu(int $okretId, int $zmianaPozycjiMs): ?array
{
    if (!function_exists('floty_orbita_dostepna') || !tabela_sql_dostepna('mapa_obiekty')
        || !floty_kurs_dostepny() || !floty_orbita_dostepna()) return null;
    if (tabela_sql_dostepna('mapa_rozkazy_skokow_jednostki')) floty_aktualizuj_ruch();
    $db = polaczenie_bazy();
    // Prom w hangarze okrętu: za oknem to samo co z okrętu, ale przez wrota hangaru (bez okna mostka).
    if (function_exists('floty_hangar_dostepny') && floty_hangar_dostepny()) {
        $q = $db->prepare('SELECT p.nazwa, p.okret_macierzysty_id, o.nazwa AS okret FROM mapa_obiekty p JOIN mapa_obiekty o ON o.id = p.okret_macierzysty_id
            WHERE p.id = ? AND p.w_hangarze = 1');
        $q->execute([$okretId]);
        if (($prom = $q->fetch(PDO::FETCH_ASSOC)) && (int) $prom['okret_macierzysty_id'] !== $okretId) {
            $s = scena_okretu((int) $prom['okret_macierzysty_id'], $zmianaPozycjiMs);
            if ($s) return ['okno' => '', 'wnetrze' => 'hangar', 'opis' => $prom['nazwa'] . ' · hangar ' . $prom['okret']] + $s;
        }
    }
    $gry = tabela_sql_dostepna('planety_gry');
    $nav = floty_nawigacja_dostepna();
    $lad = function_exists('floty_ladowanie_dostepne') && floty_ladowanie_dostepne();
    $q = $db->prepare('SELECT m.id,m.nazwa,m.klasa,m.planeta_id,m.x,m.y,m.przylot_utc,p.nazwa AS planeta'
        . ($lad ? ',m.ladowisko_id,m.start_utc,(SELECT nazwa FROM lokalizacje WHERE id=m.ladowisko_id) AS ladowisko,'
            . '(SELECT x_km FROM lokalizacje WHERE id=m.ladowisko_id) AS lad_x,(SELECT y_km FROM lokalizacje WHERE id=m.ladowisko_id) AS lad_y' : '')
        . ($nav ? ',m.podejscie,m.podlot_start,m.podlot_cel,m.podlot_od_utc,m.podlot_do_utc' : '') . ($gry ? ',g.typ_planety' : '') . mapa_kurs_sql() . '
        FROM mapa_obiekty m LEFT JOIN mapa_planety p ON p.id=m.planeta_id'
        . ($gry ? ' LEFT JOIN planety_gry g ON g.planeta_id=m.planeta_id' : '') . ' WHERE m.id=?');
    $q->execute([$okretId]);
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o) return null;
    // Okręt bez przypisanej planety, ale stojący w jej układzie (te same współrzędne): to ta planeta.
    if (empty($o['planeta']) && empty($o['planeta_id']) && ($o['uklad_kx'] ?? null) === null) {
        $pl = scena_planeta_przy((float) ($o['x'] ?? 0), (float) ($o['y'] ?? 0));
        if ($pl) { $o['planeta'] = $pl['nazwa']; $o['typ_planety'] = $pl['typ']; }
    }

    $teraz = czas_teraz();
    $baza = ['okno' => scena_okno($o['klasa'] . ' ' . $o['nazwa']), 'wnetrze' => '', 'opis' => (string) $o['nazwa']];
    $typ = (string) (($o['typ_planety'] ?? '') ?: 'skalista');
    $planeta = (string) ($o['planeta'] ?? '');
    $przylot = czas_z_utc($o['przylot_utc'] ?? null);
    $zmieniono = max($przylot * 1000, $zmianaPozycjiMs);

    // Skok w toku: po wejściu w nadprzestrzeń tunel (animacja gra na tabletach włączonych w tej chwili).
    $q = $db->prepare("SELECT COALESCE(r.wejscie_utc, r.wydano_utc) AS wejscie, j.start_x, j.start_y FROM mapa_rozkazy_skokow_jednostki j
        JOIN mapa_rozkazy_skokow r ON r.id=j.rozkaz_id WHERE j.obiekt_id=? AND j.status='w_nadprzestrzeni' AND r.status='w_nadprzestrzeni' LIMIT 1");
    $q->execute([$okretId]);
    $skok = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $wejscie = czas_z_utc($skok['wejscie'] ?? null);
    $podejscie = $nav ? floty_podejscie_teraz($o, $teraz) : null;
    $odlWariantu = ['bliska' => 'blisko', 'srednia' => 'srednio', 'daleka' => 'daleko', 'krawedz' => 'krawedz'];
    if ($wejscie && $wejscie <= $teraz) {
        // Wejście w nadprzestrzeń z widokiem sprzed skoku (słońce, planeta, gwiazdy w miejscu startu):
        // tablet wygasza je płynnie, zanim smugi zgęstnieją. W locie okręt nie ma już planety,
        // więc układ startu bierzemy z punktu startu skoku.
        $start = scena_planeta_przy((float) $skok['start_x'], (float) $skok['start_y']);
        $widok = mapa_kurs_pola($o) + ['gwiazdy' => scena_gwiazdy((float) $skok['start_x'], (float) $skok['start_y'])];
        if ($start) $widok['cykl'] = astro_cykl($start['nazwa']);
        return $baza + ['scena' => 'wejscie', 'planeta' => $start['typ'] ?? $typ,
            'odleglosc' => $odlWariantu[$podejscie ?? ''] ?? 'srednio', 'miejsce' => 'Nadprzestrzeń',
            'zmieniono' => max($wejscie * 1000, $zmianaPozycjiMs), 'widok' => $widok + ($start ? [] : ['bez_planety' => true])];
    }
    $gwiazdy = scena_gwiazdy((float) ($o['x'] ?? 0), (float) ($o['y'] ?? 0));
    $lotOd = czas_z_utc($o['lot_od_utc'] ?? null); $lotDo = czas_z_utc($o['lot_do_utc'] ?? null);
    if ($lotDo && $teraz >= $lotOd && $teraz < $lotDo && ($o['lot_start_kx'] ?? null) !== null && ($o['lot_cel_kx'] ?? null) !== null
        && function_exists('floty_planety_ukladu')) {
        // Lot podświetlny w obrębie układu: okręt naprawdę się przemieszcza (km od słońca), więc gwiazda i planety
        // rosną albo maleją zgodnie z odległością. Pokazujemy planetę celu albo najbliższą planetę układu.
        $f = floty_lot_ulamek(($teraz - $lotOd) / max(1, $lotDo - $lotOd));
        $kx = (float) $o['lot_start_kx'] + ((float) $o['lot_cel_kx'] - (float) $o['lot_start_kx']) * $f;
        $ky = (float) $o['lot_start_ky'] + ((float) $o['lot_cel_ky'] - (float) $o['lot_start_ky']) * $f;
        $planety = floty_planety_ukladu((float) $o['lot_start_x'], (float) $o['lot_start_y']);
        $pl = null; $min = INF;
        foreach ($planety as $p) {
            if (!empty($o['lot_cel_planeta_id']) && (int) $p['id'] === (int) $o['lot_cel_planeta_id']) { $pl = $p; break; }
            [$px, $py] = astro_pozycja_km(astro_cykl((string) $p['nazwa']), $teraz);
            if (($d = hypot($kx - $px, $ky - $py)) < $min) { $min = $d; $pl = $p; }
        }
        if ($pl) return $baza + scena_z_punktu_ukladu($o, $gwiazdy, (string) $pl['nazwa'], $kx, $ky, $teraz, false)
            + ['zmieniono' => max($lotOd * 1000, $zmianaPozycjiMs)];
    }
    if ($lotDo && $teraz >= $lotOd && $teraz < $lotDo) {
        // Lot podświetlny: cel przed dziobem rośnie w miarę zbliżania (rozmiar z kąta na tablecie).
        $cel = !empty($o['lot_cel_planeta_id']) ? ruch_planeta((string) $o['lot_cel_planeta_id']) : [];
        $widok = mapa_kurs_pola($o) + ['gwiazdy' => $gwiazdy];
        if (!empty($cel['nazwa'])) {
            $cykl = astro_cykl((string) $cel['nazwa']);
            $zostalo = (float) ($o['lot_km'] ?? 0) * (1 - floty_lot_ulamek(($teraz - $lotOd) / max(1, $lotDo - $lotOd)));
            $widok['orbita'] = floty_kat((float) ($widok['kurs'] ?? 0) + 180);
            $widok['cykl'] = $cykl;
            $widok['wysokosc_km'] = max(NISKA_ORBITA_KM, $zostalo + (float) $cykl['srednica_km'] * 15 / 2);
            return $baza + ['scena' => 'orbita', 'planeta' => (string) (($cel['typ_planety'] ?? '') ?: 'skalista'), 'odleglosc' => 'daleko',
                'miejsce' => (string) $cel['nazwa'], 'zmieniono' => max($lotOd * 1000, $zmianaPozycjiMs), 'widok' => $widok];
        }
        return $baza + ['scena' => 'kosmos', 'planeta' => 'skalista', 'odleglosc' => 'srednio', 'miejsce' => 'Przestrzeń',
            'zmieniono' => max($lotOd * 1000, $zmianaPozycjiMs), 'widok' => $widok];
    }
    if ($planeta === '' && ($o['uklad_kx'] ?? null) !== null && ($pl = scena_planeta_przy((float) $o['x'], (float) $o['y']))) {
        // Punkt w układzie (np. między planetą a słońcem, krawędź układu): planeta i słońce widziane
        // z tego miejsca — kierunek i rozmiar z odległości (km od słońca i od planety).
        // Świeże wyjście ze skoku w tym punkcie: animacja wyjścia z nadprzestrzeni.
        $swieze = $przylot && $teraz - $przylot < 120;
        return $baza + scena_z_punktu_ukladu($o, $gwiazdy, (string) $pl['nazwa'], (float) $o['uklad_kx'], (float) $o['uklad_ky'], $teraz, $swieze)
            + ['zmieniono' => $zmieniono];
    }
    if ($planeta === '') {
        // Świeże wyjście ze skoku w pustej przestrzeni (także awaryjne): animacja wyjścia bez planety.
        $swieze = $przylot && $teraz - $przylot < 120;
        return $baza + ['scena' => $swieze ? 'wyjscie' : 'kosmos', 'planeta' => 'skalista', 'odleglosc' => 'srednio', 'miejsce' => 'Przestrzeń', 'zmieniono' => $zmieniono,
            'widok' => mapa_kurs_pola($o) + ['gwiazdy' => $gwiazdy] + ($swieze ? ['bez_planety' => true] : [])];
    }
    if (!empty($o['ladowisko_id'])) {
        // Na lądowisku: za oknem kokpitu powierzchnia planety (dzień/noc z cyklu planety i miejsca).
        return $baza + ['scena' => 'powierzchnia', 'planeta' => $typ, 'odleglosc' => 'srednio', 'miejsce' => $planeta,
            'opis' => (string) ($o['ladowisko'] ?? 'Lądowisko'), 'zmieniono' => $zmieniono,
            'swiatlo' => astro_swiatlo($planeta, ['x' => (float) ($o['lad_x'] ?? 0), 'y' => (float) ($o['lad_y'] ?? 0)])];
    }
    $start = czas_z_utc($o['start_utc'] ?? null);
    if ($start && $teraz - $start < START_WZNOSZENIE_S) {
        // Start z lądowiska: wznoszenie przez atmosferę (animacja na tablecie, potem orbita).
        return $baza + ['scena' => 'start', 'planeta' => $typ, 'odleglosc' => 'blisko', 'miejsce' => $planeta,
            'opis' => 'Start', 'zmieniono' => max($start * 1000, $zmianaPozycjiMs)];
    }
    if (!empty($o['rozbity'])) {
        return $baza + ['scena' => 'powierzchnia', 'planeta' => $typ, 'odleglosc' => 'srednio', 'miejsce' => $planeta,
            'opis' => 'Wrak: ' . $o['nazwa'], 'zmieniono' => $zmieniono, 'swiatlo' => astro_swiatlo($planeta, ['x' => 0, 'y' => 0])];
    }
    $minuty = $przylot ? ($teraz - $przylot) / 60 : PODEJSCIE_KONIEC_MIN;
    // Wariant orbity (po skoku z automatu albo dolocie): bliska → duża planeta, krawędź układu → punkt.
    $odleglosc = $podejscie !== null
        ? $odlWariantu[$podejscie] ?? 'blisko'
        : ($minuty < PODEJSCIE_DALEKO_MIN ? 'daleko' : ($minuty < PODEJSCIE_SREDNIO_MIN ? 'srednio' : 'blisko'));
    return $baza + [
        'scena' => $minuty < ($podejscie !== null ? 2 : PODEJSCIE_KONIEC_MIN) ? 'wyjscie' : 'orbita',
        'planeta' => $typ,
        'odleglosc' => $odleglosc,
        'miejsce' => $planeta,
        'zmieniono' => $zmieniono,
        'widok' => mapa_kurs_pola($o) + ['cykl' => astro_cykl($planeta), 'gwiazdy' => $gwiazdy],
    ];
}

// LICZNIKI POKŁADOWE na pulpicie (tylko na pokładzie): odliczanie bieżącego procesu, paliwo,
// obciążenie reaktora, osłony i kadłub (okręty z SQL); statki firm graczy: odliczanie i paliwo.
function liczniki_dostepne(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT oslony, kadlub FROM mapa_naped_jednostki LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

function liczniki_okretu(int $id): ?array
{
    if (!function_exists('floty_kurs_dostepny') || !floty_kurs_dostepny()) return null;
    $db = polaczenie_bazy();
    $nav = floty_nawigacja_dostepna();
    $l = liczniki_dostepne();
    $q = $db->prepare('SELECT o.kurs_cel_deg,o.manewr_od_utc,o.manewr_do_utc' . ($nav ? ',o.podlot_cel,o.podlot_do_utc' : '')
        . (function_exists('floty_ladowanie_dostepne') && floty_ladowanie_dostepne() ? ',o.ladowisko_id,o.start_utc' : '')
        . (function_exists('floty_lot_dostepny') && floty_lot_dostepny() ? ',o.lot_od_utc,o.lot_do_utc,(SELECT nazwa FROM mapa_planety WHERE id=o.lot_cel_planeta_id) AS lot_cel' : '') . ',
            n.paliwo,n.paliwo_maks,n.moc_reaktora,n.moc_hipernapedu,n.sprawnosc_reaktora' . ($l ? ',n.oslony,n.kadlub' : '') . '
        FROM mapa_obiekty o LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id=o.id WHERE o.id=?');
    $q->execute([$id]);
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o) return null;
    $teraz = czas_teraz();
    $pk = function_exists('floty_punkt_dostepny') && floty_punkt_dostepny();
    $q = $db->prepare('SELECT COALESCE(r.wejscie_utc, r.wydano_utc) AS wejscie, ' . ($pk ? 'r.punkt_od_utc, ' : '') . ($l ? 'COALESCE(r.eta_plan_utc, j.eta_utc)' : 'j.eta_utc') . " AS eta
        FROM mapa_rozkazy_skokow_jednostki j JOIN mapa_rozkazy_skokow r ON r.id=j.rozkaz_id
        WHERE j.obiekt_id=? AND j.status='w_nadprzestrzeni' AND r.status='w_nadprzestrzeni' LIMIT 1");
    $q->execute([$id]);
    $skok = $q->fetch(PDO::FETCH_ASSOC) ?: null;

    $odliczanie = null;
    $reaktor = 15; // postój
    $manewrOd = czas_z_utc($o['manewr_od_utc'] ?? null);
    $manewrDo = czas_z_utc($o['manewr_do_utc'] ?? null);
    $wariantNazwy = ['bliska' => 'bliską orbitę', 'srednia' => 'średnią orbitę', 'daleka' => 'daleką orbitę', 'krawedz' => 'krawędź układu'];
    if ($skok) {
        $wejscie = czas_z_utc($skok['wejscie']);
        if ($teraz >= $wejscie) {
            $odliczanie = ['etykieta' => 'Wyjście z nadprzestrzeni', 'koniec' => czas_z_utc($skok['eta']), 'od' => $wejscie];
            $wzgl = (float) ($o['moc_hipernapedu'] ?? 80) / max(1, (float) ($o['moc_reaktora'] ?? 100) * max(0.1, (int) ($o['sprawnosc_reaktora'] ?? 100) / 100));
            $reaktor = (int) round(70 + 25 * min(1, $wzgl));
        } elseif ($manewrOd && $teraz < $manewrOd) {
            $odliczanie = ['etykieta' => 'Obliczenia skoku', 'koniec' => $manewrOd];
            $reaktor = 25;
        } elseif (($punktOd = czas_z_utc($skok['punkt_od_utc'] ?? null)) && $teraz >= $punktOd && $punktOd < $wejscie) {
            $odliczanie = ['etykieta' => 'Lot do punktu skoku', 'koniec' => $wejscie, 'od' => $punktOd];
            $reaktor = 60;
        } else {
            $odliczanie = ['etykieta' => 'Obrót na kurs skoku', 'koniec' => ($punktOd ?? 0) ?: $wejscie, 'od' => $manewrOd];
            $reaktor = 40;
        }
    } elseif (czas_z_utc($o['lot_do_utc'] ?? null) > $teraz && czas_z_utc($o['lot_od_utc'] ?? null) <= $teraz) {
        $odliczanie = ['etykieta' => 'Lot podświetlny' . (!empty($o['lot_cel']) ? ' → ' . $o['lot_cel'] : ''), 'koniec' => czas_z_utc($o['lot_do_utc']), 'od' => czas_z_utc($o['lot_od_utc'])];
        $reaktor = 65;
    } elseif (!empty($o['ladowisko_id'])) {
        $reaktor = 5; // na lądowisku: zasilanie postojowe
    } elseif (czas_z_utc($o['start_utc'] ?? null) + START_WZNOSZENIE_S > $teraz) {
        $odliczanie = ['etykieta' => 'Wznoszenie', 'koniec' => czas_z_utc($o['start_utc']) + START_WZNOSZENIE_S, 'od' => czas_z_utc($o['start_utc'])];
        $reaktor = 80;
    } elseif ($manewrDo > $teraz) {
        $odliczanie = ['etykieta' => 'Obrót na kurs ' . round((float) $o['kurs_cel_deg']) . '°', 'koniec' => $manewrDo];
        $reaktor = 40;
    } elseif ($nav && czas_z_utc($o['podlot_do_utc'] ?? null) > $teraz) {
        $odliczanie = ['etykieta' => 'Dolot na ' . ($wariantNazwy[$o['podlot_cel']] ?? 'orbitę'), 'koniec' => czas_z_utc($o['podlot_do_utc'])];
        $reaktor = 55;
    }
    $maks = (float) ($o['paliwo_maks'] ?? 0);
    return array_filter([
        'odliczanie' => $odliczanie,
        'paliwo' => $maks > 0 ? (int) round(100 * (float) $o['paliwo'] / $maks) : null,
        'reaktor' => $reaktor,
        'oslony' => $l ? (int) $o['oslony'] : null,
        'kadlub' => $l ? (int) $o['kadlub'] : null,
        // Naprawy w stoczni: osobny kafelek na każdą pracę w toku (prognoza) i łączna prognoza okrętu.
        'naprawa' => function_exists('floty_naprawy_okretu') ? floty_naprawy_okretu($id) : null,
    ], fn($v) => $v !== null);
}

function liczniki_statku(string $klucz): ?array
{
    $s = ruch_statek($klucz);
    if (!$s) return null;
    $teraz = czas_teraz();
    $odliczanie = null;
    if (!empty($s['cel']) && !empty($s['skok'])) {
        $odliczanie = ['etykieta' => 'Wyjście z nadprzestrzeni', 'koniec' => (int) ($s['skok']['eta_plan'] ?? $s['skok']['eta']), 'od' => (int) ($s['skok']['start'] ?? 0)];
    } elseif (!empty($s['przygotowanie']) && (int) $s['przygotowanie']['gotowy'] > $teraz) {
        $odliczanie = ['etykieta' => 'Obsługa naziemna', 'koniec' => (int) $s['przygotowanie']['gotowy'], 'od' => (int) ($s['przygotowanie']['start'] ?? 0)];
    }
    return array_filter(['odliczanie' => $odliczanie, 'paliwo' => (int) ($s['paliwo'] ?? 100)], fn($v) => $v !== null);
}

// ZGODY NA WSKAŹNIKI: liczniki widzi kapitan swojego okrętu (dowódca floty okrętu; na statku firmy
// właściciel albo menedżer). Gość na pokładzie — nawet wyższy stopniem — tylko za zgodą kapitana
// albo MG (tabela wskazniki_zgody). Bez zgody serwer w ogóle nie wysyła liczników.

// Okręt, na którego pokładzie jest postać: klucz okret:<id> albo s:<firma>:<statek>.
function wskazniki_klucz_postaci(string $uid, ?array $lokacje = null): ?string
{
    $karta = karta($uid);
    $klucz = (string) ($karta['pozycja']['lokacja'] ?? '');
    if (str_starts_with($klucz, 's:')) return $klucz;
    $gdzie = ruch_gdzie($karta, $lokacje ?? ruch_lokacje());
    return $gdzie && !empty($gdzie['okret_id']) ? 'okret:' . (int) $gdzie['okret_id'] : null;
}

function wskazniki_kapitan(string $klucz, array $u): bool
{
    if (str_starts_with($klucz, 'okret:')) {
        if (!tabela_sql_dostepna('mapa_obiekty') || !function_exists('floty_czy_dowodca')) return false;
        $q = polaczenie_bazy()->prepare('SELECT flota_id FROM mapa_floty_jednostki WHERE obiekt_id=?');
        $q->execute([(int) substr($klucz, 6)]);
        $fid = (int) $q->fetchColumn();
        return $fid > 0 && floty_czy_dowodca($fid, (string) $u['login']);
    }
    if (str_starts_with($klucz, 's:')) {
        [, $fid] = explode(':', $klucz) + ['', ''];
        foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
            if ($f['id'] === $fid) return in_array(firma_rola($f, (string) $u['id']), ['wlasciciel', 'menedzer'], true);
        }
    }
    return false;
}

function wskazniki_zgoda(string $klucz, string $login): bool
{
    if (!tabela_sql_dostepna('wskazniki_zgody')) return false;
    $q = polaczenie_bazy()->prepare('SELECT 1 FROM wskazniki_zgody WHERE klucz=? AND LOWER(konto_login)=LOWER(?)');
    $q->execute([$klucz, $login]);
    return (bool) $q->fetchColumn();
}

function wskazniki_nazwa(string $klucz): string
{
    if (str_starts_with($klucz, 'okret:')) {
        $q = polaczenie_bazy()->prepare('SELECT nazwa FROM mapa_obiekty WHERE id=?');
        $q->execute([(int) substr($klucz, 6)]);
        return (string) ($q->fetchColumn() ?: 'Okręt');
    }
    return (string) (ruch_statek($klucz)['nazwa'] ?? 'Statek');
}

function liczniki_postaci(string $uid): ?array
{
    $klucz = wskazniki_klucz_postaci($uid);
    if ($klucz === null) return null;
    $u = null;
    foreach (uzytkownicy() as $k) if ($k['id'] === $uid) $u = $k;
    if (!$u || (!wskazniki_kapitan($klucz, $u) && !wskazniki_zgoda($klucz, (string) $u['login']))) return null;
    return str_starts_with($klucz, 's:') ? liczniki_statku($klucz) : liczniki_okretu((int) substr($klucz, 6));
}

// Okręty z gośćmi na pokładzie, którymi zarządza konto (MG: wszystkie): [{klucz, nazwa, goscie}].
function wskazniki_poklad(array $u): array
{
    $mg = ($u['rola'] ?? '') === 'mg';
    $lokacje = ruch_lokacje();
    $okrety = [];
    foreach (uzytkownicy() as $k) {
        if (($k['rola'] ?? '') === 'mg') continue;
        $klucz = wskazniki_klucz_postaci((string) $k['id'], $lokacje);
        if ($klucz !== null) $okrety[$klucz][] = $k;
    }
    $wynik = [];
    foreach ($okrety as $klucz => $osoby) {
        if (!$mg && !wskazniki_kapitan($klucz, $u)) continue;
        $goscie = [];
        foreach ($osoby as $k) {
            if (wskazniki_kapitan($klucz, $k)) continue;
            $goscie[] = ['login' => $k['login'], 'postac' => $k['postac'] ?? $k['login'], 'zgoda' => wskazniki_zgoda($klucz, (string) $k['login'])];
        }
        if ($goscie) $wynik[] = ['klucz' => $klucz, 'nazwa' => wskazniki_nazwa($klucz), 'goscie' => $goscie];
    }
    return $wynik;
}

function scena_efektywna(string $uid): array
{
    $zapisana = (wczytaj('sceny', [])[$uid] ?? []) + scena_domyslna();
    $karta = karta($uid);
    $gdzie = ruch_gdzie($karta, ruch_lokacje());
    // Na pokładzie okrętu z SQL: scena ze stanu okrętu. Ręczna scena MG wygrywa, jeśli jest nowsza.
    if ($gdzie && !empty($gdzie['okret_id'])) {
        $zmianaPozycji = (int) ($karta['pozycja']['zmieniono'] ?? 0);
        $okret = scena_okretu((int) $gdzie['okret_id'], $zmianaPozycji);
        if (!$okret) {
            // Bez migracji 015/016: pokład nigdy nie pokazuje powierzchni ani hangaru, tylko przestrzeń
            // albo orbitę planety, przy której stoi okręt.
            $pl = ($gdzie['planeta'] ?? '') !== '' ? ruch_planeta((string) $gdzie['planeta']) : null;
            if (!$pl || ($pl['id'] ?? '') === '') {
                $przy = scena_planeta_przy((float) ($gdzie['x'] ?? 0), (float) ($gdzie['y'] ?? 0));
                $pl = $przy ? ['nazwa' => $przy['nazwa'], 'typ' => $przy['typ']] : null;
            }
            $okret = ['scena' => $pl ? 'orbita' : 'kosmos', 'planeta' => $pl['typ'] ?? 'skalista', 'odleglosc' => 'blisko',
                'miejsce' => $pl['nazwa'] ?? 'Przestrzeń', 'opis' => (string) ($gdzie['nazwa'] ?? ''), 'wnetrze' => '',
                'okno' => scena_okno(($gdzie['klasa'] ?? '') . ' ' . ($gdzie['nazwa'] ?? '')), 'zmieniono' => $zmianaPozycji];
        }
        // Pomieszczenie na okręcie z planem wnętrza: okna mostka, boczne iluminatory, wrota hangaru albo brak okien.
        if (function_exists('wnetrze_widok') && ($wid = wnetrze_widok($uid, (int) $gdzie['okret_id']))) {
            $okret['opis'] = $wid['nazwa'];
            // Kajuty i pomieszczenia burtowe są zamknięte (pełne ściany, bez widoku na zewnątrz); okna ma tylko mostek.
            if ($wid['okno'] === 'lewa' || $wid['okno'] === 'prawa') $okret = ['okno' => 'brak'] + $okret;
            // Hangar: wrota z widokiem; w ISD — szyb startowy w brzuchu okrętu (widok w dół, poszycie dookoła).
            elseif ($wid['okno'] === 'hangar') $okret = ['okno' => '', 'wnetrze' => 'hangar',
                'kadlub' => scena_okno(($gdzie['klasa'] ?? '') . ' ' . ($gdzie['nazwa'] ?? '')) === 'isd' ? 'isd' : ''] + $okret;
            elseif ($wid['okno'] === 'brak') $okret = ['okno' => 'brak'] + $okret;
        }
        return $okret['zmieniono'] >= (int) $zapisana['zmieniono'] ? $okret : $zapisana;
    }
    if ($gdzie && !ruch_w_locie($karta['pozycja']['lokacja'] ?? '')) {
        $pl = ruch_planeta($gdzie['planeta']);
        $ziemia = [
            'scena' => 'powierzchnia', 'planeta' => $pl['typ'] ?? 'skalista', 'odleglosc' => 'srednio',
            'miejsce' => $pl['nazwa'], 'opis' => $gdzie['nazwa'], 'wnetrze' => $gdzie['typ'],
            'zmieniono' => (int) ($karta['pozycja']['zmieniono'] ?? 0),
        ];
        // Dzień i noc liczone z cyklu planety i współrzędnych lokacji (js/rdzen/astro.js).
        $nazwaPlanety = (string) ($pl['nazwa'] ?? ($gdzie['planeta_nazwa'] ?? ''));
        if ($nazwaPlanety !== '') {
            try { $ziemia['swiatlo'] = astro_swiatlo($nazwaPlanety, $gdzie); } catch (Throwable $e) {}
        }
        if ($ziemia['zmieniono'] >= (int) $zapisana['zmieniono']) return $ziemia;
    }
    return $zapisana;
}

function teraz_ms(): int
{
    return (int) round(microtime(true) * 1000);
}

function obsluz_sceny(string $akcja, bool $post): void
{
    switch ($akcja) {

        case 'scena':
            $u = wymagaj_logowania();
            ruch_dokoncz($u['id']);
            ruch_skoki_dokoncz();
            $liczniki = null;
            try { $liczniki = liczniki_postaci($u['id']); } catch (Throwable $e) {}
            $scena = scena_efektywna($u['id']);
            // Grafika miejsca (pomieszczenie okrętu albo lokacja), przypisana przez MG: tło pulpitu zamiast rysunku.
            try { $scena['grafika'] = grafika_miejsca((string) $u['id']); } catch (Throwable $e) {}
            odpowiedz(['scena' => $scena, 'teraz' => teraz_ms(), 'liczniki' => $liczniki]);

        case 'mg_grafiki':
            wymagaj_mg();
            odpowiedz(grafiki_dane() + ['lista' => grafiki_lista()]);

        case 'mg_grafiki_zapisz':
            // Przypisania (klucz lokacji → plik) i reguły po nazwie ([{tekst, plik}], w kolejności).
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $przyp = [];
            foreach ((array) ($w['przypisania'] ?? []) as $k => $plik) {
                $k = mb_substr((string) $k, 0, 80);
                if ($k === '' || !preg_match('/^(sql|l|b|okret|flota):/', $k)) continue;
                if ($plik === 'brak') { $przyp[$k] = 'brak'; continue; }
                if (grafika_poprawna((string) $plik)) $przyp[$k] = (string) $plik;
            }
            $reguly = [];
            foreach ((array) ($w['reguly'] ?? []) as $r) {
                $t = mb_strtolower(trim((string) ($r['tekst'] ?? '')));
                if ($t !== '' && grafika_poprawna((string) ($r['plik'] ?? ''))) $reguly[] = ['tekst' => mb_substr($t, 0, 60), 'plik' => (string) $r['plik']];
            }
            zmien('grafiki_lokacji', function (&$g) use ($przyp, $reguly) { $g = ['przypisania' => $przyp, 'reguly' => array_slice($reguly, 0, 200)]; });
            odpowiedz(['ok' => true]);

        case 'mg_grafika_wgraj':
            // Własny obraz (PNG/JPG/WebP do 3 MB) do assets/wnetrza/wlasne/ — od razu dostępny w bibliotece.
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $dane = base64_decode((string) preg_replace('/^data:[^,]*,/', '', (string) ($w['dane'] ?? '')), true);
            if ($dane === false || strlen($dane) < 100) blad('Nieprawidłowy plik.');
            if (strlen($dane) > 3 * 1024 * 1024) blad('Plik większy niż 3 MB.');
            $info = @getimagesizefromstring($dane);
            $roz = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
            if (!$roz) blad('Dozwolone są obrazy PNG, JPG albo WebP.');
            $nazwa = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', (string) ($w['nazwa'] ?? 'grafika')) ?: 'grafika')), '-') ?: 'grafika';
            $katalog = dirname(__DIR__) . '/assets/wnetrza/wlasne';
            if (!is_dir($katalog) && !@mkdir($katalog, 0775, true)) blad('Serwer nie pozwala utworzyć katalogu assets/wnetrza/wlasne.');
            $plik = mb_substr($nazwa, 0, 40) . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $roz;
            if (@file_put_contents($katalog . '/' . $plik, $dane) === false) blad('Nie udało się zapisać pliku na serwerze (uprawnienia katalogu).');
            odpowiedz(['ok' => true, 'plik' => 'assets/wnetrza/wlasne/' . $plik]);

        case 'gwiazda_skan':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_logowania();
            odpowiedz(scena_skan_gwiazdy((string) (wejscie()['t'] ?? '')));

        case 'wskazniki_poklad':
            $u = wymagaj_logowania();
            odpowiedz(['okrety' => wskazniki_poklad($u), 'dostepne' => tabela_sql_dostepna('wskazniki_zgody')]);

        case 'wskazniki_zgoda':
            // Kapitan (albo MG) udziela albo cofa gościowi zgodę na wskaźniki pokładowe.
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            if (!tabela_sql_dostepna('wskazniki_zgody')) blad('Brak tabeli wskazniki_zgody — zaimportuj migrację 020.');
            $w = wejscie();
            $klucz = mb_substr(trim((string) ($w['klucz'] ?? '')), 0, 120);
            $login = mb_substr(trim((string) ($w['login'] ?? '')), 0, 64);
            if ($klucz === '' || $login === '') blad('Brak okrętu albo osoby.');
            if (($u['rola'] ?? '') !== 'mg' && !wskazniki_kapitan($klucz, $u)) blad('Tylko kapitan tego okrętu może udostępniać wskaźniki.', 403);
            if (!empty($w['zgoda'])) {
                polaczenie_bazy()->prepare('INSERT INTO wskazniki_zgody (klucz, konto_login, nadal_login, nadano_utc) VALUES (?, ?, ?, UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE nadal_login = ?, nadano_utc = UTC_TIMESTAMP()')->execute([$klucz, $login, $u['login'], $u['login']]);
            } else {
                polaczenie_bazy()->prepare('DELETE FROM wskazniki_zgody WHERE klucz=? AND LOWER(konto_login)=LOWER(?)')->execute([$klucz, $login]);
            }
            odpowiedz(['ok' => true]);

        case 'mg_sceny':
            wymagaj_mg();
            $sceny = wczytaj('sceny', []);
            $lista = [];
            foreach (uzytkownicy() as $us) {
                if (($us['rola'] ?? '') === 'mg') continue;
                $lista[] = ['uid' => $us['id'], 'postac' => $us['postac'], 'frakcja' => $us['frakcja'], 'scena' => scena_efektywna($us['id'])];
            }
            odpowiedz(['postacie' => $lista]);

        case 'mg_scena_ustaw':
            // Jedna zmiana dla wielu postaci naraz (np. cała drużyna na pokładzie jednego statku).
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $scena = (string) ($w['scena'] ?? '');
            if (!in_array($scena, SCENY, true)) blad('Nieznana scena.');
            $nowa = [
                'scena' => $scena,
                'planeta' => in_array($w['planeta'] ?? '', TYPY_PLANET, true) ? $w['planeta'] : 'oceaniczna',
                'odleglosc' => in_array($w['odleglosc'] ?? '', ODLEGLOSCI, true) ? $w['odleglosc'] : 'srednio',
                'miejsce' => pole('miejsce', 80),
                'opis' => pole('opis', 120),
                'wnetrze' => in_array($w['wnetrze'] ?? '', WNETRZA, true) ? $w['wnetrze'] : '',
                'zmieniono' => teraz_ms(),
            ];
            $uids = array_values(array_intersect((array) ($w['uids'] ?? []), array_column(uzytkownicy(), 'id')));
            if (!$uids) blad('Wybierz co najmniej jedną postać.');
            zmien('sceny', function (&$s) use ($uids, $nowa) {
                foreach ($uids as $uid) {
                    $s[$uid] = $nowa;
                }
            });
            odpowiedz(['ok' => true, 'ile' => count($uids)]);
    }
}

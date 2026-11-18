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
        . ' WHERE ABS(p.x-?) < 2 AND ABS(p.y-?) < 2 ORDER BY POW(p.x-?,2)+POW(p.y-?,2) LIMIT 1');
    $q->execute([$x, $y, $x, $y]);
    $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p || !mapa_planeta_widoczna((int) $p['id'])) return null;
    return ['nazwa' => (string) $p['nazwa'], 'typ' => (string) (($p['typ_planety'] ?? '') ?: 'skalista')];
}

// Okręt to ISD (okno mostka), jeśli klasa albo nazwa na to wskazuje.
function scena_jest_isd(string $tekst): bool
{
    return (bool) preg_match('/imperial|\bisd\b|niszczyciel/i', $tekst);
}

function scena_okretu(int $okretId, int $zmianaPozycjiMs): ?array
{
    if (!function_exists('floty_orbita_dostepna') || !tabela_sql_dostepna('mapa_obiekty')
        || !floty_kurs_dostepny() || !floty_orbita_dostepna()) return null;
    if (tabela_sql_dostepna('mapa_rozkazy_skokow_jednostki')) floty_aktualizuj_ruch();
    $db = polaczenie_bazy();
    $gry = tabela_sql_dostepna('planety_gry');
    $q = $db->prepare('SELECT m.id,m.nazwa,m.klasa,m.planeta_id,m.x,m.y,m.przylot_utc,p.nazwa AS planeta' . ($gry ? ',g.typ_planety' : '') . mapa_kurs_sql() . '
        FROM mapa_obiekty m LEFT JOIN mapa_planety p ON p.id=m.planeta_id'
        . ($gry ? ' LEFT JOIN planety_gry g ON g.planeta_id=m.planeta_id' : '') . ' WHERE m.id=?');
    $q->execute([$okretId]);
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o) return null;
    // Okręt bez przypisanej planety, ale stojący w jej układzie (te same współrzędne): to ta planeta.
    if (empty($o['planeta']) && empty($o['planeta_id'])) {
        $pl = scena_planeta_przy((float) ($o['x'] ?? 0), (float) ($o['y'] ?? 0));
        if ($pl) { $o['planeta'] = $pl['nazwa']; $o['typ_planety'] = $pl['typ']; }
    }

    $teraz = czas_teraz();
    $isd = scena_jest_isd($o['klasa'] . ' ' . $o['nazwa']);
    $baza = ['okno' => $isd ? 'isd' : '', 'wnetrze' => '', 'opis' => (string) $o['nazwa']];
    $typ = (string) (($o['typ_planety'] ?? '') ?: 'skalista');
    $planeta = (string) ($o['planeta'] ?? '');
    $przylot = czas_z_utc($o['przylot_utc'] ?? null);
    $zmieniono = max($przylot * 1000, $zmianaPozycjiMs);

    // Skok w toku: po wejściu w nadprzestrzeń tunel (animacja gra na tabletach włączonych w tej chwili).
    $q = $db->prepare("SELECT COALESCE(r.wejscie_utc, r.wydano_utc) AS wejscie FROM mapa_rozkazy_skokow_jednostki j
        JOIN mapa_rozkazy_skokow r ON r.id=j.rozkaz_id WHERE j.obiekt_id=? AND j.status='w_nadprzestrzeni' AND r.status='w_nadprzestrzeni' LIMIT 1");
    $q->execute([$okretId]);
    $wejscie = czas_z_utc($q->fetchColumn() ?: null);
    if ($wejscie && $wejscie <= $teraz) {
        return $baza + ['scena' => 'wejscie', 'planeta' => $typ, 'odleglosc' => 'srednio', 'miejsce' => 'Nadprzestrzeń',
            'zmieniono' => max($wejscie * 1000, $zmianaPozycjiMs)];
    }
    if ($planeta === '') {
        return $baza + ['scena' => 'kosmos', 'planeta' => 'skalista', 'odleglosc' => 'srednio', 'miejsce' => 'Przestrzeń', 'zmieniono' => $zmieniono];
    }
    if (!empty($o['rozbity'])) {
        return $baza + ['scena' => 'powierzchnia', 'planeta' => $typ, 'odleglosc' => 'srednio', 'miejsce' => $planeta,
            'opis' => 'Wrak: ' . $o['nazwa'], 'zmieniono' => $zmieniono, 'swiatlo' => astro_swiatlo($planeta, ['x' => 0, 'y' => 0])];
    }
    $minuty = $przylot ? ($teraz - $przylot) / 60 : PODEJSCIE_KONIEC_MIN;
    return $baza + [
        'scena' => $minuty < PODEJSCIE_KONIEC_MIN ? 'wyjscie' : 'orbita',
        'planeta' => $typ,
        'odleglosc' => $minuty < PODEJSCIE_DALEKO_MIN ? 'daleko' : ($minuty < PODEJSCIE_SREDNIO_MIN ? 'srednio' : 'blisko'),
        'miejsce' => $planeta,
        'zmieniono' => $zmieniono,
        'widok' => mapa_kurs_pola($o) + ['cykl' => astro_cykl($planeta)],
    ];
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
                'okno' => scena_jest_isd(($gdzie['klasa'] ?? '') . ' ' . ($gdzie['nazwa'] ?? '')) ? 'isd' : '', 'zmieniono' => $zmianaPozycji];
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
            odpowiedz(['scena' => scena_efektywna($u['id']), 'teraz' => teraz_ms()]);

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

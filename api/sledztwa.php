<?php
// ŚLEDZTWA DROIDA — krok 1: generator szkieletu (narzedzia/sledztwa/PROJEKT.md, rozdz. 3).
//
// Wszystko, co czyni śledztwo wyjątkowym, losuje serwer z ziarna (seed) i pul w bazie — bez AI:
// typ (zdarzenie × relacja) spośród niezdobytych, jawność sprawy, warianty 12 faz zgodne z tagami, jawność faz,
// kolejność faz 4–10, skupisko na Nar Shaddaa, miejsca fabularne, postacie z kartami EotE, ekwipunkiem i kredytami.
// Wynik: stan 'pisanie' (treść dopisze model Autor w kroku 2). Ten sam seed + te same pule = ten sam szkielet.
//
// Akcje:
//   POST sledztwo_nowe          detektyw dla siebie; MG dla dowolnego detektywa ({login}); opcje MG: seed,
//                               zdarzenie_id, relacja_id, jawnosc ('jawna'/'ukryta'), proba (true = bez zapisu)
//   GET  mg_sledztwa            lista śledztw (MG)
//   GET  mg_sledztwo_podglad    pełny szkielet jednego śledztwa (MG), ?id=

declare(strict_types=1);

const SLEDZTWO_SZANSA_UKRYTEJ_SPRAWY = 35;   // % spraw, w których zgłoszenie zasłania prawdziwe zdarzenie
const SLEDZTWO_SZANSA_UKRYTEJ_FAZY = 50;     // % faz 1, 3–12 losowanych jako ukryte
const SLEDZTWO_PRZESTAWNE = [4, 5, 6, 7, 8, 9, 10];
const SLEDZTWO_TAG_POSTACI = ['zleceniodawca' => 'Z', 'sledzacy' => 'S', 'swiadek' => 'W'];
const SLEDZTWO_NAZWY_KODOW = ['A' => 'strona A', 'B' => 'strona B', 'Z' => 'zleceniodawca', 'S' => 'śledzący', 'W' => 'świadek'];

// Bronie archetypów (nazwy z gen_archetypy.bronie) → format karty EotE: nazwa, umiejętność, obrażenia, krytyk, zasięg, cechy.
const SLEDZTWO_BRONIE = [
    'Pistolet blasterowy' => ['bron_lekka', 6, 3, 'średni', 'Ogłuszanie'],
    'Pistolet blasterowy (lekki)' => ['bron_lekka', 5, 4, 'średni', 'Ogłuszanie'],
    'Ciężki pistolet blasterowy' => ['bron_lekka', 7, 3, 'średni', 'Ogłuszanie'],
    'Pistolet kieszonkowy' => ['bron_lekka', 5, 4, 'krótki', 'Ogłuszanie'],
    'Karabin blasterowy' => ['bron_ciezka', 9, 3, 'daleki', 'Ogłuszanie'],
    'Pałka' => ['walka_wrecz', 5, 5, 'przylegający', 'Dezorientacja 1'],
    'Wibronóż' => ['walka_wrecz', 4, 2, 'przylegający', 'Penetracja 1'],
    'Nóż wibracyjny' => ['walka_wrecz', 4, 2, 'przylegający', 'Penetracja 1'],
    'Klucz hydrauliczny' => ['walka_wrecz', 4, 5, 'przylegający', 'Dezorientacja 1'],
];

// Rodzaj budynku z lokalizacje.rodzaj / typ → kod z gen_rodzaje_miejsc.
const SLEDZTWO_RODZAJ_BUDYNKU = ['hangar' => 'hangar', 'garaz' => 'hangar', 'hotel' => 'hotel', 'sklep' => 'sklep',
    'kantyna' => 'kantyna', 'biuro' => 'biuro', 'baza' => 'biuro', 'magazyn' => 'magazyn', 'platforma' => 'ladowisko',
    'port' => 'ladowisko'];

// Słowa w nazwie / szkicu wariantu podpowiadające rodzaj miejsca fazy (pierwsze trafienie wygrywa).
const SLEDZTWO_SLOWA_MIEJSC = [
    'nora ' => 'nora_slicera', 'norze' => 'nora_slicera', 'slicer' => 'nora_slicera', 'hotel' => 'hotel', 'recepcj' => 'hotel', 'kasyn' => 'kasyno',
    'hazard' => 'kasyno', 'kantyn' => 'kantyna', 'barman' => 'kantyna', 'hangar' => 'hangar', 'mechanik' => 'hangar',
    'lądowisk' => 'ladowisko', 'statek' => 'ladowisko', 'statk' => 'ladowisko', 'frachtow' => 'ladowisko', 'odlot' => 'ladowisko',
    'lombard' => 'sklep', 'paser' => 'sklep', 'fałszer' => 'nora_slicera', 'lichwiar' => 'biuro', 'straż' => 'biuro',
    'urzęd' => 'biuro', 'urząd' => 'biuro', 'magazyn' => 'magazyn', 'targ' => 'targ', 'mieszka' => 'mieszkanie',
    'sektor' => 'korytarz', 'wind' => 'korytarz',
];

final class SledztwoBlad extends RuntimeException {}

// ---------- losowanie z ziarna ----------

function sl_los(int $min, int $max): int
{
    return $min >= $max ? $min : mt_rand($min, $max);
}

function sl_procent(int $szansa): bool
{
    return mt_rand(1, 100) <= $szansa;
}

function sl_jeden(array $lista)
{
    if (!$lista) return null;
    $lista = array_values($lista);
    return $lista[mt_rand(0, count($lista) - 1)];
}

// $lista: elementy z kluczem $kluczWagi (waga względna, ≤ 0 = pomijany).
function sl_wazony(array $lista, string $kluczWagi = 'waga')
{
    $suma = 0;
    foreach ($lista as $e) $suma += max(0, (int) ($e[$kluczWagi] ?? 0));
    if ($suma <= 0) return sl_jeden($lista);
    $r = mt_rand(1, $suma);
    foreach ($lista as $e) {
        $r -= max(0, (int) ($e[$kluczWagi] ?? 0));
        if ($r <= 0) return $e;
    }
    return end($lista);
}

function sl_tasuj(array $lista): array
{
    $lista = array_values($lista);
    for ($i = count($lista) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$lista[$i], $lista[$j]] = [$lista[$j], $lista[$i]];
    }
    return $lista;
}

function sl_tagi(string $csv): array
{
    return array_values(array_filter(array_map('trim', explode(',', $csv)), fn($t) => $t !== ''));
}

// ---------- pule z bazy ----------

function sl_pule(PDO $db): array
{
    $p = [];
    $p['zdarzenia'] = $db->query('SELECT id, nazwa FROM sledztwo_zdarzenia ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $p['relacje'] = [];
    foreach ($db->query('SELECT id, nazwa, rola_a, rola_b FROM sledztwo_relacje ORDER BY id')->fetchAll() as $r) $p['relacje'][(int) $r['id']] = $r;
    $p['warianty'] = [];
    foreach ($db->query('SELECT faza_nr, nr, nazwa, szkic, wymaga, daje, jawnosc FROM sledztwo_warianty ORDER BY faza_nr, nr')->fetchAll() as $w) {
        $w['wymaga'] = sl_tagi((string) $w['wymaga']);
        $w['daje'] = sl_tagi((string) $w['daje']);
        $p['warianty'][(int) $w['faza_nr']][] = $w;
    }
    $p['fazy'] = $db->query('SELECT nr, nazwa FROM sledztwo_fazy_szablon ORDER BY nr')->fetchAll(PDO::FETCH_KEY_PAIR);
    $p['gatunki'] = [];
    foreach ($db->query('SELECT * FROM gen_gatunki')->fetchAll() as $g) $p['gatunki'][$g['kod']] = $g;
    $p['planeta_id'] = (int) ($db->query("SELECT id FROM mapa_planety WHERE nazwa = 'Nar Shaddaa' LIMIT 1")->fetchColumn() ?: 0);
    $q = $db->prepare('SELECT gatunek_kod AS kod, waga FROM gen_populacja WHERE planeta_id = ?');
    $q->execute([$p['planeta_id']]);
    $p['populacja'] = $q->fetchAll();
    if (!$p['populacja']) $p['populacja'] = array_map(fn($k) => ['kod' => $k, 'waga' => 1], array_keys($p['gatunki']));
    $p['imiona'] = $db->query('SELECT gatunek_kod, plec, rodzaj, wartosc FROM gen_imiona')->fetchAll();
    $p['archetypy'] = [];
    foreach ($db->query('SELECT * FROM gen_archetypy')->fetchAll() as $a) $p['archetypy'][$a['kod']] = $a;
    $p['charakter'] = [];
    foreach ($db->query('SELECT rodzaj, wartosc FROM gen_charakter')->fetchAll() as $c) $p['charakter'][$c['rodzaj']][] = $c['wartosc'];
    $p['przedmioty'] = $db->query('SELECT id, nazwa, kategoria, wartosc_kr FROM gen_przedmioty WHERE moze_byc_sladem = 1 ORDER BY id')->fetchAll();
    $p['rodzaje'] = [];
    foreach ($db->query('SELECT kod, nazwa, poziom_min, poziom_max, waga, opis_szumu FROM gen_rodzaje_miejsc')->fetchAll() as $r) $p['rodzaje'][$r['kod']] = $r;

    if (count($p['zdarzenia']) < 1 || count($p['relacje']) < 1) throw new SledztwoBlad('Brak słowników zdarzeń i relacji (migracja 082).');
    for ($f = 1; $f <= 12; $f++) {
        if (empty($p['warianty'][$f])) throw new SledztwoBlad("Brak wariantów fazy {$f} (migracja 084).");
    }
    if (!$p['gatunki'] || !$p['archetypy'] || !$p['rodzaje']) throw new SledztwoBlad('Puste pule losowania gen_* (migracja 082).');
    return $p;
}

// Budynki skupiska: dzieci kompleksu typu budynek / lądowisko. Skupisko = kompleks z co najmniej 2 takimi budynkami.
function sl_skupiska(PDO $db, int $planetaId): array
{
    if ($planetaId <= 0 || !tabela_sql_dostepna('lokalizacje')) return [];
    $q = $db->prepare("SELECT b.id, b.nazwa, b.typ, b.rodzaj, b.rodzic_id, r.nazwa AS skupisko
        FROM lokalizacje b JOIN lokalizacje r ON r.id = b.rodzic_id
        WHERE b.planeta_id = ? AND b.status = 'aktywna' AND b.typ IN ('budynek','ladowisko') ORDER BY b.id");
    $q->execute([$planetaId]);
    $skupiska = [];
    foreach ($q->fetchAll() as $b) {
        $id = (int) $b['rodzic_id'];
        $skupiska[$id]['id'] = $id;
        $skupiska[$id]['nazwa'] = $b['skupisko'];
        $skupiska[$id]['budynki'][] = $b;
    }
    return array_values(array_filter($skupiska, fn($s) => count($s['budynki']) >= 2));
}

// ---------- krok 1: szkielet ----------

// Zwraca pełny plan śledztwa (bez zapisu). $opcje: seed, zdarzenie_id, relacja_id, jawnosc.
function sledztwo_losuj(PDO $db, string $login, array $opcje = []): array
{
    $pule = sl_pule($db);
    $seed = isset($opcje['seed']) && (int) $opcje['seed'] > 0 ? (int) $opcje['seed'] : random_int(1, 2147483647);
    mt_srand($seed, MT_RAND_MT19937);

    // 1. Typ spośród niezdobytych (porzucone też zajmują typ).
    $q = $db->prepare('SELECT zdarzenie_id, relacja_id FROM sledztwa WHERE konto_login = ?');
    $q->execute([$login]);
    $zajete = [];
    foreach ($q->fetchAll() as $r) $zajete[$r['zdarzenie_id'] . 'x' . $r['relacja_id']] = true;
    $wolne = [];
    foreach (array_keys($pule['zdarzenia']) as $z) {
        foreach (array_keys($pule['relacje']) as $r) {
            if (isset($zajete[$z . 'x' . $r])) continue;
            if (!empty($opcje['zdarzenie_id']) && (int) $opcje['zdarzenie_id'] !== (int) $z) continue;
            if (!empty($opcje['relacja_id']) && (int) $opcje['relacja_id'] !== (int) $r) continue;
            $wolne[] = [(int) $z, (int) $r];
        }
    }
    if (!$wolne) {
        throw new SledztwoBlad(empty($opcje['zdarzenie_id']) && empty($opcje['relacja_id'])
            ? 'Ten detektyw ma już komplet typów śledztw.' : 'Ten typ śledztwa jest już zajęty.');
    }
    [$zdarzenieId, $relacjaId] = sl_jeden($wolne);

    // 2. Jawność sprawy; przy ukrytej — zdarzenie zgłoszone (inne niż prawdziwe).
    $jawnosc = in_array($opcje['jawnosc'] ?? '', ['jawna', 'ukryta'], true)
        ? $opcje['jawnosc'] : (sl_procent(SLEDZTWO_SZANSA_UKRYTEJ_SPRAWY) ? 'ukryta' : 'jawna');
    $zgloszone = null;
    if ($jawnosc === 'ukryta') {
        $inne = array_values(array_filter(array_keys($pule['zdarzenia']), fn($z) => (int) $z !== $zdarzenieId));
        $zgloszone = $inne ? (int) sl_jeden($inne) : null;
        if ($zgloszone === null) $jawnosc = 'jawna';
    }

    // 3. Fazy: 1, 2, 3, potem 4–10 w losowej kolejności, 11, 12. Najpierw jawność fazy, potem k12 spośród zgodnych.
    $kolejnosc = array_merge([1, 2, 3], sl_tasuj(SLEDZTWO_PRZESTAWNE), [11, 12]);
    $tagi = [];
    $fazy = [];
    foreach ($kolejnosc as $poz => $f) {
        $jaw = $f === 2 ? $jawnosc : (sl_procent(SLEDZTWO_SZANSA_UKRYTEJ_FAZY) ? 'ukryta' : 'jawna');
        $zgodne = fn(string $j) => array_values(array_filter($pule['warianty'][$f], fn($w) =>
            ($w['jawnosc'] === 'dowolna' || $w['jawnosc'] === $j) && !array_diff($w['wymaga'], array_keys($tagi))));
        $ok = $zgodne($jaw);
        if (!$ok && $f !== 2) { $jaw = $jaw === 'jawna' ? 'ukryta' : 'jawna'; $ok = $zgodne($jaw); }
        if (!$ok) throw new SledztwoBlad("Faza {$f}: żaden wariant nie pasuje do tagów (sprawdź warianty skryptem sprawdz_warianty.pl).");
        $w = sl_jeden($ok);
        foreach ($w['daje'] as $t) $tagi[$t] = true;
        $stan = array_keys($tagi);
        sort($stan);
        $fazy[$f] = ['faza_nr' => $f, 'nazwa_fazy' => $pule['fazy'][$f] ?? '', 'pozycja' => $poz + 1, 'wariant_nr' => (int) $w['nr'],
            'wariant' => $w['nazwa'], 'szkic' => $w['szkic'], 'jawnosc' => $jaw, 'tagi' => implode(',', $stan)];
    }

    // 4. Skupisko i miejsca fabularne: osobne miejsce dla każdej fazy, której szkic ma {M}.
    $skupisko = sl_jeden(sl_skupiska($db, $pule['planeta_id']));
    $miejsca = [];
    $zajetePokoje = [];
    foreach ($kolejnosc as $f) {
        if (strpos($fazy[$f]['szkic'], '{M}') === false) continue;
        $miejsca[] = sl_miejsce($pule, $skupisko, $fazy[$f], $zajetePokoje);
    }
    // Kryjówka (faza 7 z tagiem kryjowka) bez {M} w szkicu i tak dostaje miejsce — tam przebywa A.
    if (strpos($fazy[7]['szkic'], '{M}') === false && in_array('kryjowka', sl_tagi($fazy[7]['tagi']), true)) {
        $miejsca[] = sl_miejsce($pule, $skupisko, $fazy[7], $zajetePokoje);
    }
    if (!$miejsca) $miejsca[] = sl_miejsce($pule, $skupisko, $fazy[1], $zajetePokoje);

    // 5. Postacie kluczowe: A, B zawsze; Z / S / W, gdy końcowe tagi ich wymagają. Poboczni: 1–2 na miejsce.
    $kody = ['A', 'B'];
    foreach (SLEDZTWO_TAG_POSTACI as $tag => $kod) if (isset($tagi[$tag])) $kody[] = $kod;
    $postacie = [];
    $uzyteNazwy = [];
    foreach ($kody as $kod) $postacie[] = sl_postac($pule, $kod, 'kluczowy', $miejsca, $tagi, $uzyteNazwy, null);
    foreach ($miejsca as $i => $m) {
        for ($k = sl_los(1, 2); $k > 0; $k--) $postacie[] = sl_postac($pule, null, 'poboczny', $miejsca, $tagi, $uzyteNazwy, $i);
    }
    // Kto ukrywa prawdę w sprawie ukrytej: A albo B, czasem zleceniodawca.
    $ukrywa = $jawnosc === 'ukryta' ? sl_jeden(array_values(array_intersect(['A', 'B', 'Z'], $kody))) : null;

    $a = $postacie[0];
    $tytulZdarzenie = $pule['zdarzenia'][$zgloszone ?? $zdarzenieId];
    $tytul = $tytulZdarzenie . ' — sprawa ' . trim($a['imie'] . ' ' . ($a['nazwisko'] ?? ''));

    return [
        'seed' => $seed, 'konto_login' => $login, 'stan' => 'pisanie', 'tytul' => $tytul,
        'zdarzenie_id' => $zdarzenieId, 'zdarzenie' => $pule['zdarzenia'][$zdarzenieId],
        'relacja_id' => $relacjaId, 'relacja' => $pule['relacje'][$relacjaId]['nazwa'],
        'role' => ['A' => $pule['relacje'][$relacjaId]['rola_a'], 'B' => $pule['relacje'][$relacjaId]['rola_b']],
        'jawnosc' => $jawnosc, 'zdarzenie_zgloszone_id' => $zgloszone,
        'zdarzenie_zgloszone' => $zgloszone ? $pule['zdarzenia'][$zgloszone] : null, 'ukrywa_kod' => $ukrywa,
        'kolejnosc' => implode(',', $kolejnosc), 'tagi' => implode(',', array_keys($tagi)),
        'planeta_id' => $pule['planeta_id'] ?: null,
        'skupisko' => $skupisko ? ['id' => $skupisko['id'], 'nazwa' => $skupisko['nazwa']] : null,
        'fazy' => array_values($fazy), 'miejsca' => $miejsca, 'postacie' => $postacie,
    ];
}

function sl_miejsce(array $pule, ?array $skupisko, array $faza, array &$zajetePokoje): array
{
    // Rodzaj: podpowiedź ze słów wariantu, inaczej losowanie wg wag.
    $tekst = mb_strtolower($faza['wariant'] . ' ' . $faza['szkic']);
    $rodzaj = null;
    foreach (SLEDZTWO_SLOWA_MIEJSC as $slowo => $kod) {
        if (isset($pule['rodzaje'][$kod]) && mb_strpos($tekst, $slowo) !== false) { $rodzaj = $kod; break; }
    }
    $r = $rodzaj ? $pule['rodzaje'][$rodzaj] : sl_wazony(array_values($pule['rodzaje']));
    // Budynek skupiska: najpierw ten o zgodnym rodzaju.
    $budynek = null;
    if ($skupisko) {
        $zgodne = array_filter($skupisko['budynki'], fn($b) =>
            (SLEDZTWO_RODZAJ_BUDYNKU[(string) $b['rodzaj']] ?? ($b['typ'] === 'ladowisko' ? 'ladowisko' : null)) === $r['kod']);
        $budynek = sl_jeden($zgodne ?: $skupisko['budynki']);
    }
    do {
        $poziom = sl_los((int) $r['poziom_min'], (int) $r['poziom_max']);
        $pokoj = in_array($r['kod'], ['ladowisko', 'korytarz', 'targ'], true)
            ? sprintf('%s-%02d', strtoupper(mb_substr($r['kod'], 0, 1)), sl_los(1, 40))
            : sprintf('%d%02d', $poziom, sl_los(1, 60));
        $klucz = ($budynek['id'] ?? 0) . '/' . $poziom . '/' . $pokoj;
    } while (isset($zajetePokoje[$klucz]));
    $zajetePokoje[$klucz] = true;

    // Nazwa lokalu: budynek o zgodnym rodzaju daje swoją nazwę; inaczej rodzaj + przydomek właściciela albo numer.
    $wlasny = $budynek && (SLEDZTWO_RODZAJ_BUDYNKU[(string) $budynek['rodzaj']] ?? '') === $r['kod'];
    if ($wlasny) {
        $nazwa = $budynek['nazwa'];
    } elseif (in_array($r['kod'], ['kantyna', 'sklep', 'kasyno', 'hotel', 'biuro', 'hangar'], true)) {
        $przydomki = array_values(array_filter($pule['imiona'], fn($i) => $i['rodzaj'] === 'przydomek'));
        $p = sl_jeden($przydomki);
        $nazwa = $r['nazwa'] . ($p ? ' „' . $p['wartosc'] . '”' : ' ' . $pokoj);
    } else {
        $nazwa = $r['nazwa'] . ' ' . $pokoj;
    }
    if ($budynek && !$wlasny) $nazwa .= ' (' . $budynek['nazwa'] . ')';

    return ['faza_nr' => $faza['faza_nr'], 'lokalizacja_id' => $budynek ? (int) $budynek['id'] : null,
        'budynek' => $budynek['nazwa'] ?? null, 'rodzaj_kod' => $r['kod'], 'rodzaj' => $r['nazwa'],
        'poziom' => $poziom, 'pokoj' => $pokoj, 'nazwa' => mb_substr($nazwa, 0, 160)];
}

// $miejsceIdx: indeks miejsca (poboczni) albo null (kluczowi — dobierani do miejsca wg archetypu).
function sl_postac(array $pule, ?string $kod, string $waga, array $miejsca, array $tagi, array &$uzyteNazwy, ?int $miejsceIdx): array
{
    // Archetyp: S przy nagrodzie — łowca; poboczni — bywalcy miejsca; reszta wg wag.
    $archetypy = array_values($pule['archetypy']);
    if ($kod === 'S' && isset($tagi['nagroda']) && isset($pule['archetypy']['lowca'])) {
        $arch = $pule['archetypy']['lowca'];
    } elseif ($miejsceIdx !== null) {
        $rodzaj = $miejsca[$miejsceIdx]['rodzaj_kod'];
        $bywalcy = array_values(array_filter($archetypy, fn($a) => in_array($rodzaj, sl_tagi((string) $a['rodzaje_miejsc']), true)));
        $arch = sl_wazony($bywalcy ?: $archetypy);
    } else {
        $arch = sl_wazony($archetypy);
    }
    $tryb = $arch['tryb'];
    if ($waga === 'kluczowy' && $tryb === 'slugus') $tryb = 'rywal';
    if ($waga === 'poboczny') $tryb = 'slugus';

    $gat = sl_wazony($pule['populacja'])['kod'];
    if (!isset($pule['gatunki'][$gat])) $gat = array_key_first($pule['gatunki']);
    $plec = $gat === 'droid' ? 'n' : (sl_procent(50) ? 'm' : 'k');
    $wiek = $gat === 'droid' ? null : sl_los(18, 70);

    // Imię i nazwisko z puli gatunku (płeć zgodna albo 'n'); brak — pula człowieka. Unikamy powtórzeń w sprawie.
    $wybierz = function (string $rodzaj) use ($pule, $gat, $plec): ?string {
        foreach ([$gat, 'czlowiek'] as $g) {
            $l = array_values(array_filter($pule['imiona'], fn($i) => $i['rodzaj'] === $rodzaj && $i['gatunek_kod'] === $g
                && ($i['plec'] === $plec || $i['plec'] === 'n' || $plec === 'n')));
            if ($l) return sl_jeden($l)['wartosc'];
        }
        return null;
    };
    for ($proba = 0; $proba < 8; $proba++) {
        $imie = $wybierz('imie') ?? 'Bezimienny';
        $nazwisko = $gat === 'droid' ? null : $wybierz('nazwisko');
        if (!isset($uzyteNazwy[$imie . '|' . $nazwisko])) break;
    }
    $uzyteNazwy[$imie . '|' . $nazwisko] = true;
    $przydomki = array_values(array_filter($pule['imiona'], fn($i) => $i['rodzaj'] === 'przydomek'));
    $przydomek = $przydomki && sl_procent($waga === 'kluczowy' ? 35 : 15) ? sl_jeden($przydomki)['wartosc'] : null;

    // Charakter: cecha + sposób mówienia, czasem nawyk.
    $ch = [];
    if (!empty($pule['charakter']['cecha'])) $ch[] = 'cecha: ' . sl_jeden($pule['charakter']['cecha']);
    if (!empty($pule['charakter']['nawyk']) && sl_procent(60)) $ch[] = 'nawyk: ' . sl_jeden($pule['charakter']['nawyk']);
    if (!empty($pule['charakter']['mowa'])) $ch[] = 'mowa: ' . sl_jeden($pule['charakter']['mowa']);

    // Ekwipunek archetypu; kluczowi dostają jeszcze przedmiot, który może stać się dowodem.
    $ekw = sl_tagi((string) $arch['ekwipunek']);
    $dowod = null;
    if ($waga === 'kluczowy' && $pule['przedmioty']) {
        $dowod = sl_jeden($pule['przedmioty']);
        $ekw[] = $dowod['nazwa'];
    }
    [$kmin, $kmax] = ['slugus' => [20, 200], 'rywal' => [100, 1500], 'nemezis' => [500, 5000]][$tryb];
    $kredyty = sl_los($kmin, $kmax);
    if ($kod === 'A' && isset($tagi['dlug'])) $kredyty = intdiv($kredyty, 5);

    // Miejsce: poboczny — swoje; kluczowy — A w kryjówce (faza 7), inni tam, gdzie bywa ich archetyp, albo losowo.
    if ($miejsceIdx === null) {
        $bywa = sl_tagi((string) $arch['rodzaje_miejsc']);
        $kandydaci = array_keys(array_filter($miejsca, fn($m) => in_array($m['rodzaj_kod'], $bywa, true)));
        if ($kod === 'A') {
            $kryj = array_keys(array_filter($miejsca, fn($m) => $m['faza_nr'] === 7));
            if ($kryj) $kandydaci = $kryj;
        }
        $miejsceIdx = $kandydaci ? (int) sl_jeden($kandydaci) : mt_rand(0, count($miejsca) - 1);
    }

    $pelne = trim($imie . ' ' . ($nazwisko ?? ''));
    $karta = sl_karta_eote($pule['gatunki'][$gat], $arch, $tryb, $pelne . ($przydomek ? " „{$przydomek}”" : ''), $plec, $wiek,
        implode(', ', $ekw), $kredyty, implode('; ', $ch));

    return ['kod' => $kod, 'waga' => $waga, 'imie' => $imie, 'nazwisko' => $nazwisko, 'przydomek' => $przydomek,
        'gatunek_kod' => $gat, 'gatunek' => $pule['gatunki'][$gat]['nazwa'], 'plec' => $plec, 'wiek' => $wiek,
        'archetyp_kod' => $arch['kod'], 'archetyp' => $arch['nazwa'], 'tryb' => $tryb, 'charakter' => implode('; ', $ch),
        'ekwipunek' => implode(', ', $ekw), 'dowod_id' => $dowod ? (int) $dowod['id'] : null, 'kredyty' => $kredyty,
        'miejsce_idx' => $miejsceIdx, 'karta_eote' => $karta];
}

// Karta w formacie nowaKarta() z js/rdzen/eote.js (tryb NPC: bez kariery i zobowiązania).
function sl_karta_eote(array $g, array $arch, string $tryb, string $imie, string $plec, ?int $wiek, string $ekwipunek, int $kredyty, string $charakter): array
{
    $cechy = [];
    foreach (['krzepa', 'zwinnosc', 'intelekt', 'spryt', 'wola', 'prezencja'] as $c) $cechy[$c] = (int) $g[$c];
    foreach (sl_tagi((string) $arch['premie_cech']) as $p) {
        if (preg_match('/^([a-z]+)\s*([+-]\d+)$/', $p, $m) && isset($cechy[$m[1]])) $cechy[$m[1]] = max(1, min(5, $cechy[$m[1]] + (int) $m[2]));
    }
    $um = [];
    foreach (sl_tagi((string) $arch['umiejetnosci']) as $u) {
        [$k, $r] = array_pad(explode(':', $u, 2), 2, '1');
        $um[trim($k)] = ['ranga' => max(1, min(5, (int) $r))];
    }
    $bronie = [];
    foreach (sl_tagi((string) $arch['bronie']) as $n) {
        $b = SLEDZTWO_BRONIE[$n] ?? ['walka_wrecz', 3, 5, 'przylegający', ''];
        $bronie[] = ['nazwa' => $n, 'umiejetnosc' => $b[0], 'obr' => $b[1], 'kryt' => $b[2], 'zasieg' => $b[3], 'cechy' => $b[4]];
    }
    return ['wersja' => 1, 'tryb' => $tryb, 'imie' => $imie, 'gatunek' => $g['kod'], 'kariera' => '', 'specjalizacje' => [],
        'cechy' => $cechy, 'umiejetnosci' => (object) $um, 'talenty' => [], 'grupa' => 3, 'pd' => ['zdobyte' => 0],
        'kredyty' => $kredyty, 'zobowiazanie' => null, 'motywacja' => ['typ' => '', 'szczegol' => '', 'opis' => ''],
        'bronie' => $bronie, 'pancerz' => ['nazwa' => 'Ciężkie ubranie', 'pancerz' => 1, 'obrona' => 0], 'ekwipunek' => $ekwipunek,
        'wyglad' => ['wiek' => $wiek === null ? '' : (string) $wiek, 'plec' => ['m' => 'mężczyzna', 'k' => 'kobieta', 'n' => ''][$plec], 'wzrost' => '', 'opis' => ''],
        'planeta' => 'Nar Shaddaa', 'historia' => ['pochodzenie' => '', 'historia' => '', 'charakter' => $charakter, 'cele' => '', 'kontakty' => ''],
        'pochodne' => ['rany' => null, 'wysilek' => null, 'pancerz' => null, 'obrona_w' => null, 'obrona_d' => null],
        'notatki_mg' => '', 'rola' => $arch['nazwa']];
}

// ---------- zapis ----------

function sledztwo_zapisz(PDO $db, array $plan): int
{
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO sledztwa (konto_login, zdarzenie_id, relacja_id, zdarzenie_zgloszone_id, ukrywa_kod, seed, stan,
            kolejnosc, tytul, planeta_id, skupisko_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$plan['konto_login'], $plan['zdarzenie_id'], $plan['relacja_id'], $plan['zdarzenie_zgloszone_id'],
                $plan['ukrywa_kod'], $plan['seed'], 'losowanie', $plan['kolejnosc'], $plan['tytul'], $plan['planeta_id'],
                $plan['skupisko']['id'] ?? null]);
        $id = (int) $db->lastInsertId();

        $q = $db->prepare('INSERT INTO sledztwo_fazy (sledztwo_id, faza_nr, pozycja, wariant_nr, jawnosc, tagi) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($plan['fazy'] as $f) $q->execute([$id, $f['faza_nr'], $f['pozycja'], $f['wariant_nr'], $f['jawnosc'], $f['tagi']]);

        $idMiejsc = [];
        $q = $db->prepare('INSERT INTO sledztwo_miejsca (sledztwo_id, faza_nr, lokalizacja_id, rodzaj_kod, poziom, pokoj, nazwa) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($plan['miejsca'] as $i => $m) {
            $q->execute([$id, $m['faza_nr'], $m['lokalizacja_id'], $m['rodzaj_kod'], $m['poziom'], $m['pokoj'], $m['nazwa']]);
            $idMiejsc[$i] = (int) $db->lastInsertId();
        }

        $q = $db->prepare('INSERT INTO sledztwo_postacie (sledztwo_id, kod, waga, imie, nazwisko, przydomek, gatunek_kod, plec, wiek,
            archetyp_kod, charakter, karta_eote, ekwipunek, kredyty, miejsce_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($plan['postacie'] as $p) {
            $q->execute([$id, $p['kod'], $p['waga'], $p['imie'], $p['nazwisko'], $p['przydomek'], $p['gatunek_kod'], $p['plec'], $p['wiek'],
                $p['archetyp_kod'], mb_substr($p['charakter'], 0, 500), json_encode($p['karta_eote'], JSON_UNESCAPED_UNICODE),
                mb_substr($p['ekwipunek'], 0, 500), $p['kredyty'], $idMiejsc[$p['miejsce_idx']] ?? null]);
        }

        // Droid startuje w skupisku, na poziomie miejsca fazy 2 (zgłoszenie), a gdy go nie ma — pierwszego miejsca.
        $start = $plan['miejsca'][0];
        foreach ($plan['miejsca'] as $m) if ($m['faza_nr'] === 2) { $start = $m; break; }
        $db->prepare('INSERT INTO sledztwo_droid (sledztwo_id, lokalizacja_id, poziom) VALUES (?, ?, ?)')
            ->execute([$id, $start['lokalizacja_id'], $start['poziom']]);

        $db->prepare("INSERT INTO sledztwo_dziennik (sledztwo_id, rodzaj, kto, tresc) VALUES (?, 'system', 'system', ?)")
            ->execute([$id, "Szkielet wylosowany (seed {$plan['seed']}). Czeka na treść od modelu Autor."]);
        $db->prepare("UPDATE sledztwa SET stan = 'pisanie' WHERE id = ?")->execute([$id]);
        $db->commit();
        return $id;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// Szkic z wstawkami zastąpionymi imionami postaci i nazwą miejsca fazy (podgląd MG).
function sl_szkic_czytelny(string $szkic, array $postacie, ?string $miejsce): string
{
    $zam = ['{M}' => $miejsce ?? '[miejsce]'];
    foreach (SLEDZTWO_NAZWY_KODOW as $k => $opis) $zam['{' . $k . '}'] = "[{$opis}]";
    foreach ($postacie as $p) {
        if (($p['kod'] ?? null) && isset(SLEDZTWO_NAZWY_KODOW[$p['kod']])) $zam['{' . $p['kod'] . '}'] = trim($p['imie'] . ' ' . ($p['nazwisko'] ?? ''));
    }
    return strtr($szkic, $zam);
}

function sledztwo_podglad(PDO $db, int $id): array
{
    $q = $db->prepare('SELECT s.*, z.nazwa AS zdarzenie, r.nazwa AS relacja, r.rola_a, r.rola_b, zg.nazwa AS zdarzenie_zgloszone,
            l.nazwa AS skupisko
        FROM sledztwa s JOIN sledztwo_zdarzenia z ON z.id = s.zdarzenie_id JOIN sledztwo_relacje r ON r.id = s.relacja_id
        LEFT JOIN sledztwo_zdarzenia zg ON zg.id = s.zdarzenie_zgloszone_id LEFT JOIN lokalizacje l ON l.id = s.skupisko_id
        WHERE s.id = ?');
    $q->execute([$id]);
    $s = $q->fetch();
    if (!$s) throw new SledztwoBlad('Nie ma takiego śledztwa.');

    $q = $db->prepare('SELECT m.*, l.nazwa AS budynek, g.nazwa AS rodzaj FROM sledztwo_miejsca m
        LEFT JOIN lokalizacje l ON l.id = m.lokalizacja_id JOIN gen_rodzaje_miejsc g ON g.kod = m.rodzaj_kod
        WHERE m.sledztwo_id = ? ORDER BY m.id');
    $q->execute([$id]);
    $miejsca = $q->fetchAll();

    $q = $db->prepare('SELECT p.*, g.nazwa AS gatunek, a.nazwa AS archetyp FROM sledztwo_postacie p
        JOIN gen_gatunki g ON g.kod = p.gatunek_kod LEFT JOIN gen_archetypy a ON a.kod = p.archetyp_kod
        WHERE p.sledztwo_id = ? ORDER BY p.waga, p.kod IS NULL, p.kod, p.id');
    $q->execute([$id]);
    $postacie = $q->fetchAll();
    foreach ($postacie as &$p) $p['karta_eote'] = json_decode((string) $p['karta_eote'], true);
    unset($p);

    $q = $db->prepare('SELECT f.faza_nr, f.pozycja, f.wariant_nr, f.jawnosc, f.tagi, f.tresc, t.nazwa AS nazwa_fazy, w.nazwa AS wariant, w.szkic
        FROM sledztwo_fazy f JOIN sledztwo_fazy_szablon t ON t.nr = f.faza_nr
        JOIN sledztwo_warianty w ON w.faza_nr = f.faza_nr AND w.nr = f.wariant_nr
        WHERE f.sledztwo_id = ? ORDER BY f.pozycja');
    $q->execute([$id]);
    $fazy = $q->fetchAll();
    foreach ($fazy as &$f) {
        $m = null;
        foreach ($miejsca as $mm) if ((int) $mm['faza_nr'] === (int) $f['faza_nr']) { $m = $mm['nazwa']; break; }
        $f['szkic_czytelny'] = sl_szkic_czytelny((string) $f['szkic'], $postacie, $m);
    }
    unset($f);

    $q = $db->prepare('SELECT * FROM sledztwo_droid WHERE sledztwo_id = ?');
    $q->execute([$id]);
    return ['sledztwo' => $s, 'fazy' => $fazy, 'miejsca' => $miejsca, 'postacie' => $postacie, 'droid' => $q->fetch() ?: null];
}

// Plan (próba bez zapisu) w kształcie podobnym do podglądu.
function sledztwo_plan_czytelny(array $plan): array
{
    foreach ($plan['fazy'] as &$f) {
        $m = null;
        foreach ($plan['miejsca'] as $mm) if ($mm['faza_nr'] === $f['faza_nr']) { $m = $mm['nazwa']; break; }
        $f['szkic_czytelny'] = sl_szkic_czytelny($f['szkic'], $plan['postacie'], $m);
    }
    unset($f);
    usort($plan['fazy'], fn($a, $b) => $a['pozycja'] <=> $b['pozycja']);
    foreach ($plan['postacie'] as &$p) $p['miejsce'] = $plan['miejsca'][$p['miejsce_idx']]['nazwa'] ?? null;
    unset($p);
    return $plan;
}

function sl_detektyw(PDO $db, string $login): bool
{
    $q = $db->prepare('SELECT 1 FROM sledztwo_detektywi WHERE konto_login = ?');
    $q->execute([$login]);
    return (bool) $q->fetchColumn();
}

// ---------- ruch i skan droida (sterowanie na żywo, czas 1:1) ----------

const SLEDZTWO_PREDKOSC_MS = 1.4;      // chód droida, m/s
const SLEDZTWO_WINDA_S_POZIOM = 20;    // jazda windą na poziom
const SLEDZTWO_WINDA_CZEKANIE_S = 30;  // czekanie na windę
const SLEDZTWO_ZASIEG = 230;           // zasięg skanu w jednostkach planu (pokój = 60 j.)
const SLEDZTWO_POKOJ_J = 60;
const SLEDZTWO_POZIOM_MAX = 120;
const SLEDZTWO_STANY_RUCHU = ['pisanie', 'trwa'];

function sl_teraz(): int { return time(); }
function sl_utc(int $t): string { return gmdate('Y-m-d H:i:s', $t); }
function sl_z_utc(?string $s): ?int { return $s ? (int) strtotime($s . ' UTC') : null; }

// Deterministyczne losowanie dla miejsc i ludzi (ten sam klucz = ten sam wynik).
function sl_ziarno(int $seed, string ...$czesci): void
{
    mt_srand(crc32($seed . '|' . implode('|', $czesci)), MT_RAND_MT19937);
}

function sl_biezace(PDO $db, string $login): ?array
{
    $q = $db->prepare("SELECT * FROM sledztwa WHERE konto_login = ? AND stan IN ('losowanie','pisanie','trwa','wznowienie_prosba','blad')
        ORDER BY id DESC LIMIT 1");
    $q->execute([$login]);
    return $q->fetch() ?: null;
}

// Budynki i lądowiska skupiska śledztwa (cele ruchu), z kodem rodzaju miejsca.
function sl_budynki(PDO $db, array $s): array
{
    if (empty($s['skupisko_id'])) return [];
    $q = $db->prepare("SELECT id, nazwa, typ, rodzaj, x_km, y_km FROM lokalizacje
        WHERE rodzic_id = ? AND status = 'aktywna' AND typ IN ('budynek','ladowisko') ORDER BY nazwa");
    $q->execute([(int) $s['skupisko_id']]);
    $wynik = [];
    foreach ($q->fetchAll() as $b) {
        $b['id'] = (int) $b['id'];
        $b['rodzaj_kod'] = SLEDZTWO_RODZAJ_BUDYNKU[(string) $b['rodzaj']] ?? ($b['typ'] === 'ladowisko' ? 'ladowisko' : null);
        $wynik[$b['id']] = $b;
    }
    return $wynik;
}

// Plan piętra: pokoje po obu stronach korytarza (winda po lewej, x = 0). Deterministyczny; pokoje fabuły zawsze są.
function sl_plan(PDO $db, array $s, array $pule, ?array $budynek, int $poziom): array
{
    $bid = $budynek['id'] ?? 0;
    $q = $db->prepare('SELECT id, pokoj, rodzaj_kod, nazwa, opis, zamkniete FROM sledztwo_miejsca
        WHERE sledztwo_id = ? AND poziom = ? AND ' . ($budynek ? 'lokalizacja_id = ?' : 'lokalizacja_id IS NULL'));
    $q->execute($budynek ? [(int) $s['id'], $poziom, $bid] : [(int) $s['id'], $poziom]);
    $fabula = [];
    foreach ($q->fetchAll() as $m) $fabula[(string) $m['pokoj']] = $m;

    sl_ziarno((int) $s['seed'], 'plan', (string) $bid, (string) $poziom);
    $numery = [];
    for ($i = 1, $n = sl_los(10, 18); $i <= $n; $i++) $numery[] = sprintf('%d%02d', $poziom, $i);
    foreach (array_keys($fabula) as $p) if (!in_array((string) $p, $numery, true)) $numery[] = (string) $p;

    $naPoziomie = array_values(array_filter($pule['rodzaje'], fn($r) => $poziom >= (int) $r['poziom_min'] && $poziom <= (int) $r['poziom_max']));
    if (!$naPoziomie) $naPoziomie = array_values($pule['rodzaje']);
    $wlasny = $budynek['rodzaj_kod'] ?? null;
    $wlasnyPasuje = $wlasny && isset($pule['rodzaje'][$wlasny])
        && $poziom >= (int) $pule['rodzaje'][$wlasny]['poziom_min'] && $poziom <= (int) $pule['rodzaje'][$wlasny]['poziom_max'];

    $pokoje = [];
    $terminal = 0;
    foreach ($numery as $i => $nr) {
        $m = $fabula[$nr] ?? null;
        $rodzaj = $m ? $m['rodzaj_kod'] : (($wlasnyPasuje && sl_procent(50)) ? $wlasny : sl_wazony($naPoziomie)['kod']);
        $zamkniete = $m ? (bool) $m['zamkniete'] : sl_procent($rodzaj === 'mieszkanie' ? 45 : 15);
        $maTerminal = sl_procent(in_array($rodzaj, ['biuro', 'hotel', 'nora_slicera', 'kasyno'], true) ? 45 : 12);
        $pokoje[] = ['nr' => $nr, 'drzwi' => $i + 1, 'strona' => $i % 2 ? 'dol' : 'gora', 'x' => intdiv($i, 2) * SLEDZTWO_POKOJ_J,
            'rodzaj_kod' => $rodzaj, 'rodzaj' => $pule['rodzaje'][$rodzaj]['nazwa'] ?? $rodzaj, 'zamkniete' => $zamkniete,
            'terminal' => $maTerminal ? 'T' . (++$terminal) : null, 'miejsce_id' => $m ? (int) $m['id'] : null,
            'nazwa' => $m['nazwa'] ?? null, 'opis' => $m['opis'] ?? null];
    }
    $korytarzTerminal = sl_procent(50) ? 'T' . (++$terminal) : null;
    return ['budynek_id' => $budynek['id'] ?? null, 'budynek' => $budynek['nazwa'] ?? 'Kompleks', 'poziom' => $poziom,
        'szerokosc' => (int) ceil(count($pokoje) / 2) * SLEDZTWO_POKOJ_J, 'pokoje' => $pokoje,
        'terminal_korytarz' => $korytarzTerminal];
}

// Środek pokoju (albo windy, gdy droid stoi na korytarzu) na osi korytarza.
function sl_x_droida(array $plan, string $pokoj): int
{
    foreach ($plan['pokoje'] as $p) if ($p['nr'] === $pokoj) return $p['x'] + intdiv(SLEDZTWO_POKOJ_J, 2);
    return 0;
}

// Kontakty w zasięgu: postacie fabuły (z bazy) albo przechodnie szumu (deterministycznie, zmieniają się co godzinę).
function sl_kontakty(PDO $db, array $s, array $pule, array $plan, int $xDroida): array
{
    $kontakty = [];
    $godzina = gmdate('YmdH');
    $q = $db->prepare('SELECT p.id, p.gatunek_kod, p.plec, a.nazwa AS archetyp FROM sledztwo_postacie p
        LEFT JOIN gen_archetypy a ON a.kod = p.archetyp_kod WHERE p.sledztwo_id = ? AND p.miejsce_id = ? ORDER BY p.id');
    foreach ($plan['pokoje'] as $p) {
        $srodek = $p['x'] + intdiv(SLEDZTWO_POKOJ_J, 2);
        if (abs($srodek - $xDroida) > SLEDZTWO_ZASIEG) continue;
        $osoby = [];
        if ($p['miejsce_id']) {
            $q->execute([(int) $s['id'], $p['miejsce_id']]);
            foreach ($q->fetchAll() as $o) $osoby[] = $o + ['postac_id' => (int) $o['id'], 'token' => sl_token((int) $s['seed'], 'p', (string) $o['id'])];
        } else {
            sl_ziarno((int) $s['seed'], 'ludzie', (string) $plan['budynek_id'], (string) $plan['poziom'], $p['nr'], $godzina);
            $ile = sl_los(0, in_array($p['rodzaj_kod'], ['kantyna', 'kasyno', 'targ'], true) ? 3 : 1);
            $bywalcy = array_values(array_filter($pule['archetypy'], fn($a) => in_array($p['rodzaj_kod'], sl_tagi((string) $a['rodzaje_miejsc']), true)));
            for ($k = 0; $k < $ile; $k++) {
                $g = sl_wazony($pule['populacja'])['kod'];
                $a = $bywalcy ? sl_wazony($bywalcy) : null;
                $osoby[] = ['gatunek_kod' => $g, 'plec' => $g === 'droid' ? 'n' : (sl_procent(50) ? 'm' : 'k'), 'archetyp' => $a['nazwa'] ?? null,
                    'postac_id' => null, 'token' => sl_token((int) $s['seed'], 's', (string) $plan['budynek_id'], (string) $plan['poziom'], $p['nr'], $godzina, (string) $k)];
            }
        }
        foreach ($osoby as $j => $o) {
            $kontakty[] = ['pokoj' => $p['nr'], 'x' => $p['x'] + 15 + ($j % 3) * 15, 'strona' => $p['strona'],
                'gatunek' => $pule['gatunki'][$o['gatunek_kod']]['nazwa'] ?? $o['gatunek_kod'], 'plec' => $o['plec'],
                'wyglad' => $o['archetyp'] ? mb_strtolower((string) $o['archetyp']) : 'przechodzień',
                'token' => $o['token'], 'postac_id' => $o['postac_id']];
        }
    }
    usort($kontakty, fn($a, $b) => $a['x'] <=> $b['x']);
    foreach ($kontakty as $i => &$k) $k['kod'] = 'K-' . ($i + 1);
    unset($k);
    return $kontakty;
}

// Czas podróży w sekundach między pozycjami [budynek_id|null, poziom, pokoj].
function sl_czas_drogi(array $budynki, array $z, array $do): int
{
    [$bz, $pz, $kz] = $z;
    [$bd, $pd, $kd] = $do;
    if ($bz === $bd && $pz === $pd) return $kz === $kd ? 0 : 30;
    $t = 20.0;
    if ($bz !== $bd && isset($budynki[$bz], $budynki[$bd])) {
        $m = hypot(((float) $budynki[$bz]['x_km'] - (float) $budynki[$bd]['x_km']) * 1000,
            ((float) $budynki[$bz]['y_km'] - (float) $budynki[$bd]['y_km']) * 1000);
        $t += $m / SLEDZTWO_PREDKOSC_MS;
    }
    if ($pz !== $pd) $t += SLEDZTWO_WINDA_CZEKANIE_S + SLEDZTWO_WINDA_S_POZIOM * abs($pz - $pd);
    if ($kd !== 'korytarz') $t += 20;
    return (int) round($t);
}

function sl_dziennik(PDO $db, int $id, string $rodzaj, string $kto, string $tresc): void
{
    $db->prepare('INSERT INTO sledztwo_dziennik (sledztwo_id, rodzaj, kto, tresc) VALUES (?, ?, ?, ?)')->execute([$id, $rodzaj, $kto, $tresc]);
}

// Rozlicza dotarcie droida (wywoływane przy każdym odczycie; bez crona). Zwraca wiersz droida po rozliczeniu.
function sl_rozlicz_droida(PDO $db, array $s, ?array $pule = null): ?array
{
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT * FROM sledztwo_droid WHERE sledztwo_id = ? FOR UPDATE');
        $q->execute([(int) $s['id']]);
        $d = $q->fetch();
        if (!$d || $d['stan'] !== 'w_drodze' || sl_z_utc($d['dotrze_utc']) > sl_teraz()) { $db->commit(); return $d ?: null; }

        $pule = $pule ?? sl_pule($db);
        $budynki = sl_budynki($db, $s);
        $bid = $d['cel_lokalizacja_id'] !== null ? (int) $d['cel_lokalizacja_id'] : null;
        $poziom = (int) $d['cel_poziom'];
        $pokoj = (string) $d['cel_pokoj'];
        $plan = sl_plan($db, $s, $pule, $bid !== null ? ($budynki[$bid] ?? null) : null, $poziom);
        $miejsce = "{$plan['budynek']}, poziom {$poziom}";

        $lokal = null;
        foreach ($plan['pokoje'] as $p) if ($p['nr'] === $pokoj) { $lokal = $p; break; }
        if ($pokoj === 'korytarz' || !$lokal) {
            $pokoj = 'korytarz';
            $meldunek = "{$miejsce}, korytarz. Na piętrze " . count($plan['pokoje']) . ' lokali.';
        } elseif ($lokal['zamkniete']) {
            $meldunek = "{$miejsce}: lokal {$pokoj} zamknięty. Czekam na korytarzu przed drzwiami {$lokal['drzwi']}.";
            $pokoj = 'korytarz';
        } elseif ($lokal['miejsce_id']) {
            $meldunek = "{$miejsce}, lokal {$pokoj}: {$lokal['nazwa']}." . ($lokal['opis'] ? ' ' . $lokal['opis'] : " ({$lokal['rodzaj']})");
        } else {
            $opis = (string) ($pule['rodzaje'][$lokal['rodzaj_kod']]['opis_szumu'] ?? '');
            $meldunek = "{$miejsce}, lokal {$pokoj}: {$lokal['rodzaj']}." . ($opis !== '' ? ' ' . $opis : '');
            if ($bid !== null) {
                $db->prepare('INSERT IGNORE INTO sledztwo_szum (sledztwo_id, lokalizacja_id, poziom, pokoj, rodzaj_kod, opis) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([(int) $s['id'], $bid, $poziom, $pokoj, $lokal['rodzaj_kod'], $opis]);
            }
        }
        $kontakty = sl_kontakty($db, $s, $pule, $plan, sl_x_droida($plan, $pokoj));
        $meldunek .= $kontakty ? ' W zasięgu kontaktów: ' . count($kontakty) . '.' : ' W zasięgu brak kontaktów.';

        $db->prepare("UPDATE sledztwo_droid SET lokalizacja_id = ?, poziom = ?, pokoj = ?, cel_lokalizacja_id = NULL, cel_poziom = NULL,
            cel_pokoj = NULL, wyruszyl_utc = NULL, dotrze_utc = NULL, stan = 'czeka' WHERE sledztwo_id = ?")
            ->execute([$bid, $poziom, $pokoj, (int) $s['id']]);
        $db->prepare("INSERT INTO sledztwo_dziennik (sledztwo_id, kiedy_utc, rodzaj, kto, tresc) VALUES (?, ?, 'meldunek', 'droid', ?)")
            ->execute([(int) $s['id'], $d['dotrze_utc'], $meldunek]);
        $db->commit();
        $q->execute([(int) $s['id']]);
        return $q->fetch() ?: null;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// Rozkaz ruchu. Nowy rozkaz w drodze: przed połową trasy droid zawraca ze startu, po połowie rusza z poprzedniego celu.
function sl_rozkaz_ruchu(PDO $db, array $s, ?int $bid, int $poziom, string $pokoj): array
{
    if (!in_array($s['stan'], SLEDZTWO_STANY_RUCHU, true)) throw new SledztwoBlad('Droid nie przyjmuje teraz rozkazów.');
    $pule = sl_pule($db);
    $budynki = sl_budynki($db, $s);
    if ($budynki && ($bid === null || !isset($budynki[$bid]))) throw new SledztwoBlad('Ten budynek jest poza rejonem sprawy.');
    if (!$budynki) $bid = null;
    if ($poziom < 1 || $poziom > SLEDZTWO_POZIOM_MAX) throw new SledztwoBlad('Nie ma takiego poziomu (1–' . SLEDZTWO_POZIOM_MAX . ').');
    $plan = sl_plan($db, $s, $pule, $bid !== null ? $budynki[$bid] : null, $poziom);
    if ($pokoj !== 'korytarz' && !in_array($pokoj, array_column($plan['pokoje'], 'nr'), true)) throw new SledztwoBlad("Na tym poziomie nie ma lokalu {$pokoj}.");

    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT * FROM sledztwo_droid WHERE sledztwo_id = ? FOR UPDATE');
        $q->execute([(int) $s['id']]);
        $d = $q->fetch();
        if (!$d) throw new SledztwoBlad('Droid nie ma przydziału.');
        $teraz = sl_teraz();
        $start = [$d['lokalizacja_id'] !== null ? (int) $d['lokalizacja_id'] : null, (int) $d['poziom'], (string) $d['pokoj']];
        if ($d['stan'] === 'w_drodze') {
            $od = sl_z_utc($d['wyruszyl_utc']) ?? $teraz;
            $do = sl_z_utc($d['dotrze_utc']) ?? $teraz;
            if ($do <= $od || ($teraz - $od) * 2 >= ($do - $od)) {
                $start = [$d['cel_lokalizacja_id'] !== null ? (int) $d['cel_lokalizacja_id'] : null, (int) $d['cel_poziom'], (string) $d['cel_pokoj']];
            }
        }
        $cel = [$bid, $poziom, $pokoj];
        $czas = max(1, sl_czas_drogi($budynki, $start, $cel));
        $db->prepare("UPDATE sledztwo_droid SET lokalizacja_id = ?, poziom = ?, pokoj = ?, cel_lokalizacja_id = ?, cel_poziom = ?, cel_pokoj = ?,
            wyruszyl_utc = ?, dotrze_utc = ?, stan = 'w_drodze' WHERE sledztwo_id = ?")
            ->execute([$start[0], $start[1], $start[2], $bid, $poziom, $pokoj, sl_utc($teraz), sl_utc($teraz + $czas), (int) $s['id']]);
        $cel = $plan['budynek'] . ", poziom {$poziom}, " . ($pokoj === 'korytarz' ? 'korytarz' : "lokal {$pokoj}");
        sl_dziennik($db, (int) $s['id'], 'polecenie', 'gracz', "Idź: {$cel}.");
        sl_dziennik($db, (int) $s['id'], 'meldunek', 'droid', 'Przyjąłem. Szacowany czas: ' . sl_czas_tekst($czas) . '.');
        $db->commit();
        return ['czas_s' => $czas, 'dotrze_utc' => sl_utc($teraz + $czas)];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function sl_czas_tekst(int $s): string
{
    $g = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    return $g ? "{$g} h {$m} min" : ($m ? sprintf('%d min %02d s', $m, $s % 60) : "{$s} s");
}

// Skan dla ekranu DT-4: pozycja, plan bieżącego piętra, kontakty w zasięgu, cele ruchu, ostatnie meldunki.
function sl_skan(PDO $db, array $s): array
{
    $pule = sl_pule($db);
    $d = sl_rozlicz_droida($db, $s, $pule);
    if (!$d) throw new SledztwoBlad('Droid nie ma przydziału.');
    $budynki = sl_budynki($db, $s);
    $bid = $d['lokalizacja_id'] !== null ? (int) $d['lokalizacja_id'] : null;
    $plan = sl_plan($db, $s, $pule, $bid !== null ? ($budynki[$bid] ?? null) : null, (int) $d['poziom']);
    $teraz = sl_teraz();
    $xDroida = sl_x_droida($plan, (string) $d['pokoj']);
    $wDrodze = $d['stan'] === 'w_drodze';
    // W drodze droid nie skanuje (idzie korytarzami); kontakty — dopiero po dotarciu.
    $kontakty = $wDrodze ? [] : sl_kontakty($db, $s, $pule, $plan, $xDroida);
    // Graczowi nie pokazujemy, który lokal jest fabularny.
    $plan['pokoje'] = array_map(fn($p) => array_diff_key($p, ['miejsce_id' => 1, 'nazwa' => 1, 'opis' => 1]), $plan['pokoje']);

    $droid = ['budynek_id' => $bid, 'budynek' => $plan['budynek'], 'poziom' => (int) $d['poziom'], 'pokoj' => (string) $d['pokoj'],
        'stan' => $d['stan'], 'x' => $xDroida];
    if ($wDrodze) {
        $od = sl_z_utc($d['wyruszyl_utc']) ?? $teraz;
        $do = sl_z_utc($d['dotrze_utc']) ?? $teraz;
        $cb = $d['cel_lokalizacja_id'] !== null ? (int) $d['cel_lokalizacja_id'] : null;
        $droid['cel'] = ['budynek_id' => $cb, 'budynek' => $cb !== null ? ($budynki[$cb]['nazwa'] ?? '') : 'Kompleks',
            'poziom' => (int) $d['cel_poziom'], 'pokoj' => (string) $d['cel_pokoj']];
        $droid['zostalo_s'] = max(0, $do - $teraz);
        $droid['postep'] = $do > $od ? min(1, max(0, ($teraz - $od) / ($do - $od))) : 1;
    }
    $q = $db->prepare("SELECT kiedy_utc, rodzaj, kto, tresc FROM sledztwo_dziennik WHERE sledztwo_id = ? AND rodzaj <> 'system' ORDER BY id DESC LIMIT 12");
    $q->execute([(int) $s['id']]);
    $rozmowa = null;
    try {
        $r = sl_rozmowa_aktywna($db, (int) $s['id']);
        if ($r) $rozmowa = ['id' => (int) $r['id'], 'kontakt' => $r['kontakt'], 'stan' => $r['stan']];
    } catch (PDOException $e) { /* brak migracji 085 — bez rozmów */ }
    $kontakty = array_map(fn($k) => array_diff_key($k, ['postac_id' => 1]), $kontakty);
    return ['droid' => $droid, 'plan' => $plan, 'kontakty' => $kontakty, 'zasieg' => SLEDZTWO_ZASIEG, 'rozmowa' => $rozmowa,
        'budynki' => array_values(array_map(fn($b) => ['id' => $b['id'], 'nazwa' => $b['nazwa'], 'rodzaj' => $b['rodzaj_kod']], $budynki)),
        'poziom_max' => SLEDZTWO_POZIOM_MAX, 'meldunki' => $q->fetchAll(), 'teraz' => $teraz];
}

// ---------- rozmowy droida z NPC (migracja 085) ----------

const SLEDZTWO_PODEJSCIE_S = 3;              // droid podchodzi na 2–3 m
const SLEDZTWO_AI_DLA_POBOCZNYCH = false;    // true = poboczni z bazy też mówią przez AI (więcej wywołań)
const SLEDZTWO_NASTAWIENIE = ['uprzejmy' => 'uprzejme, choć ostrożne', 'obojetny' => 'obojętne, lekko nieufne',
    'wrogi' => 'wrogie', 'przestraszony' => 'przestraszone'];

function sl_token(int $seed, string ...$czesci): string
{
    return substr(md5($seed . '|' . implode('|', $czesci)), 0, 12);
}

function sl_rozmowa_aktywna(PDO $db, int $sledztwoId): ?array
{
    $q = $db->prepare("SELECT * FROM sledztwo_rozmowy WHERE sledztwo_id = ? AND stan <> 'zakonczona' ORDER BY id DESC LIMIT 1");
    $q->execute([$sledztwoId]);
    return $q->fetch() ?: null;
}

function sl_wpis(PDO $db, int $rozmowaId, string $kto, string $tresc, bool $ai = false): void
{
    $db->prepare('INSERT INTO sledztwo_rozmowy_wpisy (rozmowa_id, kiedy_utc, kto, tresc, ai) VALUES (?, ?, ?, ?, ?)')
        ->execute([$rozmowaId, sl_utc(sl_teraz()), $kto, $tresc, $ai ? 1 : 0]);
}

function sl_opis_rozmowcy(array $r): string
{
    $o = json_decode((string) $r['opis_json'], true) ?: [];
    return trim(($o['gatunek'] ?? 'rozmówca') . ', ' . ($o['wyglad'] ?? ''), ', ');
}

function sl_rozmowa_zakoncz(PDO $db, array $r, string $powod): void
{
    $db->prepare("UPDATE sledztwo_rozmowy SET stan = 'zakonczona', zakonczono_utc = ?, tura_od = NULL WHERE id = ? AND stan <> 'zakonczona'")
        ->execute([sl_utc(sl_teraz()), (int) $r['id']]);
    sl_wpis($db, (int) $r['id'], 'system', $powod);
    $q = $db->prepare("SELECT COUNT(*) FROM sledztwo_rozmowy_wpisy WHERE rozmowa_id = ? AND kto IN ('droid','npc')");
    $q->execute([(int) $r['id']]);
    sl_dziennik($db, (int) $r['sledztwo_id'], 'rozmowa', 'droid',
        'Rozmowa zakończona (' . sl_opis_rozmowcy($r) . ', lokal ' . $r['pokoj'] . '): ' . (int) $q->fetchColumn() . ' wypowiedzi. ' . $powod);
    $db->prepare("UPDATE sledztwo_droid SET stan = 'czeka' WHERE sledztwo_id = ? AND stan = 'rozmowa'")->execute([(int) $r['sledztwo_id']]);
}

function sl_rozmowa_widok(PDO $db, array $r): array
{
    $q = $db->prepare('SELECT id, kiedy_utc, kto, tresc FROM sledztwo_rozmowy_wpisy WHERE rozmowa_id = ? ORDER BY id');
    $q->execute([(int) $r['id']]);
    $o = json_decode((string) $r['opis_json'], true) ?: [];
    return ['id' => (int) $r['id'], 'kontakt' => $r['kontakt'], 'stan' => $r['stan'], 'rozmowca' => sl_opis_rozmowcy($r), 'kod' => $o['kod'] ?? null,
        'pokoj' => $r['pokoj'], 'gotowa_za_s' => max(0, (sl_z_utc($r['gotowa_utc']) ?? 0) - sl_teraz()),
        'tura_trwa' => $r['tura_od'] !== null, 'wpisy' => $q->fetchAll()];
}

// Klik w kontakt: droid podchodzi (wchodzi do lokalu, jeśli trzeba) i zaczyna rozmowę.
function sl_rozmowa_start(PDO $db, array $s, string $token): array
{
    $pule = sl_pule($db);
    $d = sl_rozlicz_droida($db, $s, $pule);
    if (!$d || $d['stan'] === 'w_drodze') throw new SledztwoBlad('Droid jest w drodze. Poczekaj, aż dotrze.');
    $budynki = sl_budynki($db, $s);
    $bid = $d['lokalizacja_id'] !== null ? (int) $d['lokalizacja_id'] : null;
    $plan = sl_plan($db, $s, $pule, $bid !== null ? ($budynki[$bid] ?? null) : null, (int) $d['poziom']);
    $kontakt = null;
    foreach (sl_kontakty($db, $s, $pule, $plan, sl_x_droida($plan, (string) $d['pokoj'])) as $k) if ($k['token'] === $token) { $kontakt = $k; break; }
    if (!$kontakt) throw new SledztwoBlad('Kontakt poza zasięgiem albo już odszedł.');
    $lokal = null;
    foreach ($plan['pokoje'] as $p) if ($p['nr'] === $kontakt['pokoj']) { $lokal = $p; break; }
    if ($lokal && $lokal['zamkniete'] && $d['pokoj'] !== $lokal['nr']) throw new SledztwoBlad("Lokal {$lokal['nr']} jest zamknięty — droid nie wejdzie.");

    $stara = sl_rozmowa_aktywna($db, (int) $s['id']);
    if ($stara) sl_rozmowa_zakoncz($db, $stara, 'Droid przerwał rozmowę i podszedł do kogoś innego.');
    $teraz = sl_teraz();
    $gotowa = $teraz + SLEDZTWO_PODEJSCIE_S;
    if ($d['pokoj'] !== $kontakt['pokoj']) {
        $ruch = sl_rozkaz_ruchu($db, $s, $bid, (int) $d['poziom'], $kontakt['pokoj']);
        $gotowa = $teraz + $ruch['czas_s'] + SLEDZTWO_PODEJSCIE_S;
    }
    $opis = ['gatunek' => $kontakt['gatunek'], 'plec' => $kontakt['plec'], 'wyglad' => $kontakt['wyglad'], 'kod' => $kontakt['kod']];
    $db->prepare('INSERT INTO sledztwo_rozmowy (sledztwo_id, kontakt, postac_id, opis_json, lokalizacja_id, poziom, pokoj, gotowa_utc, rozpoczeto_utc)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int) $s['id'], $token, $kontakt['postac_id'], json_encode($opis, JSON_UNESCAPED_UNICODE), $bid, (int) $d['poziom'],
            $kontakt['pokoj'], sl_utc($gotowa), sl_utc($teraz)]);
    $r = sl_rozmowa_aktywna($db, (int) $s['id']);
    $txt = "{$kontakt['kod']}: {$kontakt['gatunek']}, {$kontakt['wyglad']} (lokal {$kontakt['pokoj']})";
    sl_wpis($db, (int) $r['id'], 'system', "DT-4 podchodzi do rozmówcy ({$txt}) na 2–3 m.");
    sl_dziennik($db, (int) $s['id'], 'rozmowa', 'droid', "Podchodzę do kontaktu {$txt}.");
    return sl_rozmowa_widok($db, $r);
}

// Formy grzecznościowe wg płci rozmówcy.
function sl_formy(string $plec): array
{
    return $plec === 'k'
        ? ['wolacz' => 'obywatelko', 'pan' => 'Pani', 'panu' => 'Pani', 'pana' => 'Panią', 'a' => 'a', 'opis' => 'Pani (kobieta)']
        : ['wolacz' => 'obywatelu', 'pan' => 'Pan', 'panu' => 'Panu', 'pana' => 'Pana', 'a' => '', 'opis' => $plec === 'm' ? 'Pan (mężczyzna)' : 'Pan (płeć nieokreślona)'];
}

// Wypowiedź droida bez AI (przechodnie, poboczni, awaria łącza).
function sl_droid_zastepczo(bool $pierwsza, array $f, string $oznaczenie, string $operator, string $polecenie, int $nr): string
{
    if ($pierwsza) {
        $t = "Dzień dobry, {$f['wolacz']}. Jestem droid detektywistyczny {$oznaczenie}, operowany przez: {$operator}. "
            . "Mam prawo zadać {$f['panu']} kilka pytań, ponieważ trwa śledztwo, w które jest {$f['pan']} ewidentnie zaangażowan" . ($f['a'] ?: 'y') . '.';
        return $t . ($polecenie !== '' ? " Operator pyta: {$polecenie}" : ' Co stało się tutaj ubiegłej nocy?');
    }
    if ($polecenie !== '') return "Operator pyta: {$polecenie}";
    $pytania = ["Kogo {$f['pan']} tu widział{$f['a']} w ciągu ostatniej doby?", "Czy ktoś pytał już {$f['pana']} o tę sprawę?",
        'Kto jeszcze bywa w tym miejscu regularnie?', 'Czy coś zginęło albo zmieniło się tutaj w ostatnich dniach?',
        "Gdzie {$f['pan']} był{$f['a']} ubiegłej nocy?"];
    return $pytania[$nr % count($pytania)];
}

function sl_kwestia_szumu(PDO $db, string $nastroj): string
{
    $q = $db->prepare('SELECT tresc FROM gen_kwestie_szumu WHERE nastroj = ?');
    $q->execute([$nastroj]);
    $l = $q->fetchAll(PDO::FETCH_COLUMN);
    if (!$l) $l = $db->query('SELECT tresc FROM gen_kwestie_szumu')->fetchAll(PDO::FETCH_COLUMN);
    return $l ? (string) $l[random_int(0, count($l) - 1)] : 'Nie wiem.';
}

// Dane kluczowego NPC do szablonu npc_wypowiedz. Dopóki model Autor nie napisze historii i wiedzy (krok 2),
// wiedza powstaje ze szkieletu: fazy, w których szkicu występuje kod postaci, z zachowaniem wg jawności fazy.
function sl_npc_dane(PDO $db, array $s, array $p, array $pule, array $r): array
{
    $q = $db->prepare('SELECT id, kod, imie, nazwisko FROM sledztwo_postacie WHERE sledztwo_id = ? AND kod IS NOT NULL');
    $q->execute([(int) $s['id']]);
    $kluczowi = $q->fetchAll();
    $q = $db->prepare('SELECT f.faza_nr, f.jawnosc, w.szkic, t.nazwa, t.npc_jawna, t.npc_ukryta FROM sledztwo_fazy f
        JOIN sledztwo_warianty w ON w.faza_nr = f.faza_nr AND w.nr = f.wariant_nr JOIN sledztwo_fazy_szablon t ON t.nr = f.faza_nr
        WHERE f.sledztwo_id = ? ORDER BY f.pozycja');
    $q->execute([(int) $s['id']]);
    $fazy = $q->fetchAll();
    $q = $db->prepare('SELECT faza_nr, nazwa FROM sledztwo_miejsca WHERE sledztwo_id = ?');
    $q->execute([(int) $s['id']]);
    $miejscaFaz = [];
    foreach ($q->fetchAll() as $m) $miejscaFaz[(int) $m['faza_nr']] = $m['nazwa'];
    $q = $db->prepare('SELECT s.ukrywa_kod, z.nazwa AS prawdziwe, z.wskazowki_autora, COALESCE(zg.nazwa, z.nazwa) AS zgloszone,
            r.nazwa AS relacja, r.rola_a, r.rola_b
        FROM sledztwa s JOIN sledztwo_zdarzenia z ON z.id = s.zdarzenie_id LEFT JOIN sledztwo_zdarzenia zg ON zg.id = s.zdarzenie_zgloszone_id
        JOIN sledztwo_relacje r ON r.id = s.relacja_id WHERE s.id = ?');
    $q->execute([(int) $s['id']]);
    $sp = $q->fetch();

    $kod = $p['kod'];
    $imieKodu = [];
    foreach ($kluczowi as $k) $imieKodu[$k['kod']] = trim($k['imie'] . ' ' . ($k['nazwisko'] ?? ''));
    $archetyp = $pule['archetypy'][$p['archetyp_kod']]['nazwa'] ?? 'mieszkaniec Nar Shaddaa';
    $rola = match ($kod) {
        'A' => "{$sp['rola_a']} w relacji „{$sp['relacja']}”; drugą stroną jest " . ($imieKodu['B'] ?? 'ktoś bliski') . " ({$sp['rola_b']})",
        'B' => "{$sp['rola_b']} w relacji „{$sp['relacja']}”; drugą stroną jest " . ($imieKodu['A'] ?? 'ktoś bliski') . " ({$sp['rola_a']})",
        'Z' => 'zleceniodawca / mocodawca w tej sprawie',
        'S' => 'ktoś, kto sam szuka w tej sprawie ludzi i rzeczy (łowca, agent albo cichy tropiciel)',
        'W' => 'świadek — widziałeś coś ważnego',
        default => 'osoba z otoczenia sprawy',
    };

    $wiedza = [];
    $zachowanie = [];
    foreach ($fazy as $f) {
        if (!$kod || strpos((string) $f['szkic'], '{' . $kod . '}') === false) continue;
        $tekst = sl_szkic_czytelny((string) $f['szkic'], $kluczowi, $miejscaFaz[(int) $f['faza_nr']] ?? null);
        $wiedza[] = "- {$f['nazwa']}: {$tekst}" . ($f['jawnosc'] === 'ukryta'
            ? ' [TO UKRYWASZ: zaprzeczasz albo zbywasz, dopóki pytanie nie trafi dokładnie w sedno; wtedy kłamiesz albo milkniesz]'
            : ' [o tym możesz mówić, jeśli ktoś wprost zapyta]');
        $zachowanie[] = "- {$f['nazwa']}: " . ($f['jawnosc'] === 'ukryta' ? $f['npc_ukryta'] : $f['npc_jawna']);
    }
    if ($kod && $kod === $sp['ukrywa_kod']) {
        $wiedza[] = "- NAJWAŻNIEJSZE: naprawdę chodzi o: {$sp['prawdziwe']}. Ukrywasz to i trzymasz się wersji, że chodzi o: {$sp['zgloszone']}.";
    }
    if (!$wiedza) $wiedza[] = "- O samej sprawie nic konkretnego nie wiesz. Słyszałeś tylko plotki, że coś się stało ({$sp['zgloszone']}).";
    if (!$zachowanie) $zachowanie[] = '- Zachowujesz się zgodnie z charakterem; o sprawie mówisz niechętnie, bo cię nie dotyczy.';
    if ($sp['wskazowki_autora']) $zachowanie[] = '- Granice treści: ' . $sp['wskazowki_autora'];

    $ch = ['cecha' => '', 'nawyk' => '', 'mowa' => ''];
    foreach (explode(';', (string) $p['charakter']) as $c) {
        [$k, $v] = array_pad(array_map('trim', explode(':', $c, 2)), 2, '');
        if (isset($ch[$k])) $ch[$k] = $v;
    }
    $miejsce = ['nazwa' => 'lokal ' . $r['pokoj'], 'poziom' => (string) $r['poziom'], 'opis' => 'Zwykły lokal na Nar Shaddaa.'];
    if ($p['miejsce_id']) {
        $q = $db->prepare('SELECT m.nazwa, m.opis, g.opis_szumu FROM sledztwo_miejsca m JOIN gen_rodzaje_miejsc g ON g.kod = m.rodzaj_kod WHERE m.id = ?');
        $q->execute([(int) $p['miejsce_id']]);
        if ($m = $q->fetch()) $miejsce = ['nazwa' => $m['nazwa'], 'poziom' => (string) $r['poziom'], 'opis' => $m['opis'] ?: ($m['opis_szumu'] ?: 'Zwykły lokal na Nar Shaddaa.')];
    }
    $pelne = trim($p['imie'] . ' ' . ($p['nazwisko'] ?? '') . ($p['przydomek'] ? " „{$p['przydomek']}”" : ''));
    return [
        'npc' => ['pelne_imie' => $pelne, 'gatunek' => $pule['gatunki'][$p['gatunek_kod']]['nazwa'] ?? $p['gatunek_kod'],
            'plec' => ['m' => 'mężczyzna', 'k' => 'kobieta', 'n' => 'nieokreślona'][$p['plec']] ?? 'nieokreślona',
            'wiek' => $p['wiek'] !== null ? (string) $p['wiek'] : 'nieznany', 'archetyp' => $archetyp,
            'charakter' => $ch['cecha'] ?: 'zwyczajny', 'nawyk' => $ch['nawyk'] ?: 'brak szczególnych nawyków', 'mowa' => $ch['mowa'] ?: 'zwyczajnie',
            'historia' => $p['historia'] ?: "Jesteś {$archetyp} na Nar Shaddaa. W sprawie jesteś: {$rola}.",
            'wiedza' => $p['wiedza'] ?: implode("\n", $wiedza), 'nastawienie' => SLEDZTWO_NASTAWIENIE[$r['nastroj']] ?? 'obojętne'],
        'miejsce' => $miejsce,
        'faza' => ['zachowanie' => implode("\n", $zachowanie)],
    ];
}

// Jedna tura: (polecenie operatora) → pytanie droida → odpowiedź rozmówcy. Kluczowi NPC — AI; reszta — bez AI.
function sl_rozmowa_tura(PDO $db, array $s, array $u, int $rid, string $polecenie): array
{
    $q = $db->prepare('SELECT * FROM sledztwo_rozmowy WHERE id = ? AND sledztwo_id = ?');
    $q->execute([$rid, (int) $s['id']]);
    $r = $q->fetch();
    if (!$r || $r['stan'] === 'zakonczona') throw new SledztwoBlad('Ta rozmowa jest już zakończona.');
    if ((sl_z_utc($r['gotowa_utc']) ?? 0) > sl_teraz()) throw new SledztwoBlad('Droid jeszcze podchodzi do rozmówcy.');
    $pule = sl_pule($db);
    $d = sl_rozlicz_droida($db, $s, $pule);
    $naMiejscu = $d && $d['stan'] !== 'w_drodze' && (string) $d['pokoj'] === (string) $r['pokoj'] && (int) $d['poziom'] === (int) $r['poziom']
        && (($d['lokalizacja_id'] === null && $r['lokalizacja_id'] === null) || (int) $d['lokalizacja_id'] === (int) $r['lokalizacja_id']);
    if (!$naMiejscu) {
        sl_rozmowa_zakoncz($db, $r, 'Droid odszedł od rozmówcy.');
        throw new SledztwoBlad('Droid nie stoi już przy rozmówcy — rozmowa zakończona.');
    }
    $teraz = sl_teraz();
    $zajmij = $db->prepare('UPDATE sledztwo_rozmowy SET tura_od = ? WHERE id = ? AND (tura_od IS NULL OR tura_od < ?)');
    $zajmij->execute([sl_utc($teraz), $rid, sl_utc($teraz - 120)]);
    if (!$zajmij->rowCount()) throw new SledztwoBlad('Droid jeszcze rozmawia — poczekaj na odpowiedź.');
    @set_time_limit(180);

    try {
        if ($r['stan'] === 'podejscie') {
            $db->prepare("UPDATE sledztwo_rozmowy SET stan = 'trwa' WHERE id = ?")->execute([$rid]);
            $db->prepare("UPDATE sledztwo_droid SET stan = 'rozmowa' WHERE sledztwo_id = ?")->execute([(int) $s['id']]);
        }
        $polecenie = trim(mb_substr($polecenie, 0, 300));
        if ($polecenie !== '') sl_wpis($db, $rid, 'operator', $polecenie);

        $q = $db->prepare("SELECT kto, tresc FROM sledztwo_rozmowy_wpisy WHERE rozmowa_id = ? AND kto IN ('droid','npc') ORDER BY id");
        $q->execute([$rid]);
        $dotad = $q->fetchAll();
        $pytan = count(array_filter($dotad, fn($w) => $w['kto'] === 'droid'));
        $ostatnie = array_slice($dotad, -12);

        $o = json_decode((string) $r['opis_json'], true) ?: [];
        $f = sl_formy((string) ($o['plec'] ?? 'n'));
        $q = $db->prepare('SELECT droid_oznaczenie FROM sledztwo_detektywi WHERE konto_login = ?');
        $q->execute([(string) $s['konto_login']]);
        $oznaczenie = (string) ($q->fetchColumn() ?: 'DT-4');
        $operator = trim(($u['ranga'] ?? '') . ' ' . ($u['postac'] ?? '')) ?: (string) $u['login'];
        $postac = null;
        if ($r['postac_id']) {
            $q = $db->prepare('SELECT * FROM sledztwo_postacie WHERE id = ?');
            $q->execute([(int) $r['postac_id']]);
            $postac = $q->fetch() ?: null;
        }
        $ai = $postac && ($postac['waga'] === 'kluczowy' || SLEDZTWO_AI_DLA_POBOCZNYCH);
        $q = $db->prepare('SELECT COALESCE(zg.nazwa, z.nazwa) FROM sledztwa s JOIN sledztwo_zdarzenia z ON z.id = s.zdarzenie_id
            LEFT JOIN sledztwo_zdarzenia zg ON zg.id = s.zdarzenie_zgloszone_id WHERE s.id = ?');
        $q->execute([(int) $s['id']]);
        $zgloszenie = (string) $q->fetchColumn();
        $awaria = false;

        // 1. Droid pyta.
        $przebiegDroida = $ostatnie ? implode("\n", array_map(fn($w) => ($w['kto'] === 'droid' ? "{$oznaczenie}: " : 'Rozmówca: ') . $w['tresc'], $ostatnie))
            : '(rozmowa dopiero się zaczyna)';
        $pytanie = null;
        $pytanieAi = false;
        if ($ai) {
            try {
                $pytanie = ai_wywolaj('droid_wypowiedz', [
                    'droid' => ['oznaczenie' => $oznaczenie], 'operator' => ['opis' => $operator],
                    'sprawa' => ['zgloszenie' => $zgloszenie],
                    'miejsce' => ['rejon' => 'Nar Shaddaa', 'nazwa' => 'lokal ' . $r['pokoj'] . ', poziom ' . $r['poziom']],
                    'rozmowca' => ['opis' => sl_opis_rozmowcy($r), 'forma' => $f['opis']],
                    'rozmowa' => ['etap' => $pytan ? 'Rozmowa trwa — nie przedstawiaj się ponownie.' : 'To Twoja pierwsza wypowiedź — przedstaw się zgodnie z zasadą 2.',
                        'polecenie' => $polecenie !== '' ? $polecenie : 'Brak — zadaj kolejne pytanie zgodnie z zasadą 4.',
                        'przebieg' => $przebiegDroida],
                ], (int) $s['id'])['wypowiedz'];
                $pytanieAi = true;
            } catch (AiBlad $e) { $awaria = true; }
        }
        $pytanie = $pytanie ?? sl_droid_zastepczo($pytan === 0, $f, $oznaczenie, $operator, $polecenie, $pytan);
        sl_wpis($db, $rid, 'droid', $pytanie, $pytanieAi);

        // 2. Rozmówca odpowiada.
        $odp = null;
        $nastroj = (string) $r['nastroj'];
        $konczy = false;
        if ($ai && !$awaria) {
            try {
                $dane = sl_npc_dane($db, $s, $postac, $pule, $r);
                $imie = $dane['npc']['pelne_imie'];
                $dane['droid'] = ['oznaczenie' => $oznaczenie];
                $dane['rozmowa'] = ['pytanie' => $pytanie, 'przebieg' => $ostatnie
                    ? implode("\n", array_map(fn($w) => ($w['kto'] === 'droid' ? "Droid {$oznaczenie}: " : "Ty ({$imie}): ") . $w['tresc'], $ostatnie))
                    : '(to początek rozmowy)'];
                $w = ai_wywolaj('npc_wypowiedz', $dane, (int) $s['id']);
                $odp = $w['wypowiedz'];
                $nastroj = $w['nastroj'];
                $konczy = (bool) $w['konczy_rozmowe'];
                sl_wpis($db, $rid, 'npc', $odp, true);
            } catch (AiBlad $e) { $awaria = true; }
        }
        if ($odp === null) {
            if (!$ai || $awaria) {
                // Bez AI: zdawkowa kwestia; przechodzień po kilku pytaniach odchodzi.
                if ($pytan === 0) $nastroj = ['uprzejmy', 'obojetny', 'obojetny', 'wrogi', 'przestraszony'][random_int(0, 4)];
                $odp = sl_kwestia_szumu($db, $nastroj);
                $konczy = $pytan >= 2 && random_int(1, 100) <= 50;
                sl_wpis($db, $rid, 'npc', $odp);
            }
        }
        if ($awaria) sl_wpis($db, $rid, 'system', 'Łącze z modułem językowym niedostępne — wypowiedź zastępcza.');
        $db->prepare('UPDATE sledztwo_rozmowy SET nastroj = ? WHERE id = ?')->execute([$nastroj, $rid]);
        if ($konczy) {
            $q = $db->prepare('SELECT * FROM sledztwo_rozmowy WHERE id = ?');
            $q->execute([$rid]);
            sl_rozmowa_zakoncz($db, $q->fetch(), 'Rozmówca kończy rozmowę.');
        }
    } finally {
        $db->prepare('UPDATE sledztwo_rozmowy SET tura_od = NULL WHERE id = ?')->execute([$rid]);
    }
    $q = $db->prepare('SELECT * FROM sledztwo_rozmowy WHERE id = ?');
    $q->execute([$rid]);
    return sl_rozmowa_widok($db, $q->fetch());
}

function sl_rozmowy_dostepne(PDO $db): void
{
    try {
        $db->query('SELECT 1 FROM sledztwo_rozmowy LIMIT 1');
    } catch (PDOException $e) {
        throw new SledztwoBlad('Rozmowy nie działają. (Brak migracji 085.)');
    }
}

// ---------- akcje ----------

function obsluz_sledztwa(string $akcja, bool $post): void
{
    $akcje = ['sledztwo_moje', 'sledztwo_skan', 'sledztwo_idz', 'sledztwo_rozmowa', 'sledztwo_rozmowa_start', 'sledztwo_rozmowa_tura',
        'sledztwo_rozmowa_koniec', 'sledztwo_nowe', 'mg_sledztwa', 'mg_sledztwo_podglad', 'mg_detektywi', 'mg_detektyw_ustaw'];
    if (!in_array($akcja, $akcje, true)) return;
    $u = wymagaj_logowania();
    $mg = ($u['rola'] ?? '') === 'mg';
    $db = polaczenie_bazy();
    try {
        $db->query('SELECT 1 FROM sledztwo_warianty LIMIT 1');
    } catch (Throwable $e) {
        // Pulpit pyta o to każdego gracza — bez migracji po prostu nikt nie jest detektywem.
        if ($akcja === 'sledztwo_moje') odpowiedz(['detektyw' => false]);
        blad('Moduł śledztw nie odpowiada. (Brak migracji 082.)');
    }

    try {
        switch ($akcja) {
            // Widok gracza (aplikacja „Droid śledczy”). Tylko to, co detektyw może wiedzieć: zgłoszenie (nigdy
            // prawdziwe zdarzenie ani relacja), zaczyn, pozycja droida, dziennik. Pulpit pyta o to po zalogowaniu.
            case 'sledztwo_moje':
                $q = $db->prepare('SELECT droid_oznaczenie FROM sledztwo_detektywi WHERE konto_login = ?');
                $q->execute([(string) $u['login']]);
                $droid = $q->fetchColumn();
                if ($droid === false) odpowiedz(['detektyw' => false, 'mg' => $mg]);
                $q = $db->prepare("SELECT s.id, s.tytul, s.stan, s.zaczyn, s.utworzono_utc, s.zakonczono_utc, s.ocena,
                        COALESCE(zg.nazwa, z.nazwa) AS zgloszenie, l.nazwa AS skupisko
                    FROM sledztwa s JOIN sledztwo_zdarzenia z ON z.id = s.zdarzenie_id
                    LEFT JOIN sledztwo_zdarzenia zg ON zg.id = s.zdarzenie_zgloszone_id LEFT JOIN lokalizacje l ON l.id = s.skupisko_id
                    WHERE s.konto_login = ? ORDER BY s.id DESC");
                $q->execute([(string) $u['login']]);
                $lista = $q->fetchAll();
                $biezace = null;
                foreach ($lista as $s) {
                    if (in_array($s['stan'], ['losowanie', 'pisanie', 'trwa', 'wznowienie_prosba', 'blad'], true)) { $biezace = $s; break; }
                }
                $droidStan = null;
                $dziennik = [];
                if ($biezace) {
                    sl_rozlicz_droida($db, sl_biezace($db, (string) $u['login']) ?? $biezace);
                    $q = $db->prepare('SELECT d.poziom, d.pokoj, d.stan, d.cel_poziom, d.cel_pokoj, d.dotrze_utc, l.nazwa AS lokalizacja, c.nazwa AS cel
                        FROM sledztwo_droid d LEFT JOIN lokalizacje l ON l.id = d.lokalizacja_id LEFT JOIN lokalizacje c ON c.id = d.cel_lokalizacja_id
                        WHERE d.sledztwo_id = ?');
                    $q->execute([(int) $biezace['id']]);
                    $droidStan = $q->fetch() ?: null;
                    $q = $db->prepare("SELECT kiedy_utc, rodzaj, kto, tresc FROM sledztwo_dziennik
                        WHERE sledztwo_id = ? AND rodzaj <> 'system' ORDER BY id DESC LIMIT 50");
                    $q->execute([(int) $biezace['id']]);
                    $dziennik = $q->fetchAll();
                }
                $rozwiazane = count(array_filter($lista, fn($s) => $s['stan'] === 'rozwiazane'));
                odpowiedz(['detektyw' => true, 'mg' => $mg, 'droid' => $droid, 'biezace' => $biezace, 'droid_stan' => $droidStan,
                    'dziennik' => $dziennik, 'archiwum' => array_values(array_filter($lista, fn($s) => $s !== $biezace)),
                    'rozwiazane' => $rozwiazane, 'wszystkie_typy' => 144]);

            // Ekran DT-4: skan piętra i pozycja droida (rozlicza też dotarcie do celu).
            case 'sledztwo_skan':
                if (!sl_detektyw($db, (string) $u['login'])) blad('Brak uprawnień.', 403);
                $s = sl_biezace($db, (string) $u['login']);
                if (!$s) blad('Brak otwartej sprawy.');
                odpowiedz(sl_skan($db, $s));

            // Rozkaz ruchu: {lokalizacja_id, poziom, pokoj ('korytarz' albo numer lokalu)}.
            case 'sledztwo_idz':
                if (!$post) blad('Zła metoda.', 405);
                if (!sl_detektyw($db, (string) $u['login'])) blad('Brak uprawnień.', 403);
                $s = sl_biezace($db, (string) $u['login']);
                if (!$s) blad('Brak otwartej sprawy.');
                $w = wejscie();
                $bid = isset($w['lokalizacja_id']) && $w['lokalizacja_id'] !== '' && $w['lokalizacja_id'] !== null ? (int) $w['lokalizacja_id'] : null;
                $pokoj = mb_substr(trim((string) ($w['pokoj'] ?? 'korytarz')), 0, 12) ?: 'korytarz';
                $wynik = sl_rozkaz_ruchu($db, $s, $bid, (int) ($w['poziom'] ?? 0), $pokoj);
                // Odejście kończy rozmowę.
                try {
                    $r = sl_rozmowa_aktywna($db, (int) $s['id']);
                    if ($r) sl_rozmowa_zakoncz($db, $r, 'Droid odszedł na polecenie operatora.');
                } catch (PDOException $e) { /* brak migracji 085 */ }
                odpowiedz(['ok' => true] + $wynik);

            // Rozmowa: bieżąca (albo ?id=) z całym zapisem.
            case 'sledztwo_rozmowa':
                if (!sl_detektyw($db, (string) $u['login'])) blad('Brak uprawnień.', 403);
                sl_rozmowy_dostepne($db);
                $s = sl_biezace($db, (string) $u['login']);
                if (!$s) blad('Brak otwartej sprawy.');
                if (!empty($_GET['id'])) {
                    $q = $db->prepare('SELECT * FROM sledztwo_rozmowy WHERE id = ? AND sledztwo_id = ?');
                    $q->execute([(int) $_GET['id'], (int) $s['id']]);
                    $r = $q->fetch() ?: null;
                } else {
                    $r = sl_rozmowa_aktywna($db, (int) $s['id']);
                }
                odpowiedz(['rozmowa' => $r ? sl_rozmowa_widok($db, $r) : null]);

            // Klik w kontakt na planie: {kontakt: znacznik z sledztwo_skan}.
            case 'sledztwo_rozmowa_start':
                if (!$post) blad('Zła metoda.', 405);
                if (!sl_detektyw($db, (string) $u['login'])) blad('Brak uprawnień.', 403);
                sl_rozmowy_dostepne($db);
                $s = sl_biezace($db, (string) $u['login']);
                if (!$s) blad('Brak otwartej sprawy.');
                odpowiedz(['rozmowa' => sl_rozmowa_start($db, $s, (string) (wejscie()['kontakt'] ?? ''))]);

            // Tura rozmowy: {rozmowa_id, polecenie?} — polecenie operatora dla droida (opcjonalne).
            case 'sledztwo_rozmowa_tura':
                if (!$post) blad('Zła metoda.', 405);
                if (!sl_detektyw($db, (string) $u['login'])) blad('Brak uprawnień.', 403);
                sl_rozmowy_dostepne($db);
                $s = sl_biezace($db, (string) $u['login']);
                if (!$s) blad('Brak otwartej sprawy.');
                $w = wejscie();
                odpowiedz(['rozmowa' => sl_rozmowa_tura($db, $s, $u, (int) ($w['rozmowa_id'] ?? 0), (string) ($w['polecenie'] ?? ''))]);

            case 'sledztwo_rozmowa_koniec':
                if (!$post) blad('Zła metoda.', 405);
                if (!sl_detektyw($db, (string) $u['login'])) blad('Brak uprawnień.', 403);
                sl_rozmowy_dostepne($db);
                $s = sl_biezace($db, (string) $u['login']);
                if (!$s) blad('Brak otwartej sprawy.');
                $q = $db->prepare("SELECT * FROM sledztwo_rozmowy WHERE id = ? AND sledztwo_id = ? AND stan <> 'zakonczona'");
                $q->execute([(int) (wejscie()['rozmowa_id'] ?? 0), (int) $s['id']]);
                if ($r = $q->fetch()) {
                    sl_wpis($db, (int) $r['id'], 'droid', 'Dziękuję za współpracę. Życzę spokojnego cyklu.');
                    sl_rozmowa_zakoncz($db, $r, 'Operator zakończył rozmowę.');
                }
                odpowiedz(['ok' => true]);

            case 'sledztwo_nowe':
                if (!$post) blad('Zła metoda.', 405);
                $w = wejscie();
                $login = $mg && trim((string) ($w['login'] ?? '')) !== '' ? trim((string) $w['login']) : (string) $u['login'];
                if (!sl_detektyw($db, $login)) blad($mg ? 'To konto nie jest detektywem (sledztwo_detektywi).' : 'Brak uprawnień.', $mg ? 400 : 403);
                // Gracz: jedno otwarte śledztwo naraz. MG może to obejść.
                if (!$mg) {
                    $q = $db->prepare("SELECT COUNT(*) FROM sledztwa WHERE konto_login = ? AND stan IN ('losowanie','pisanie','trwa','wznowienie_prosba','blad')");
                    $q->execute([$login]);
                    if ((int) $q->fetchColumn() > 0) blad('Najpierw zakończ albo porzuć bieżące śledztwo.');
                }
                $opcje = $mg ? array_intersect_key($w, array_flip(['seed', 'zdarzenie_id', 'relacja_id', 'jawnosc'])) : [];
                $plan = sledztwo_losuj($db, $login, $opcje);
                if ($mg && !empty($w['proba'])) odpowiedz(['ok' => true, 'proba' => true, 'plan' => sledztwo_plan_czytelny($plan)]);
                try {
                    $id = sledztwo_zapisz($db, $plan);
                } catch (PDOException $e) {
                    // Równoległe losowanie tego samego typu: UNIQUE (konto, zdarzenie, relacja).
                    if ($e->getCode() === '23000') blad('Ten typ śledztwa właśnie został zajęty. Spróbuj ponownie.');
                    throw $e;
                }
                odpowiedz($mg ? ['ok' => true, 'id' => $id, 'podglad' => sledztwo_podglad($db, $id)]
                    : ['ok' => true, 'id' => $id, 'stan' => 'pisanie']);

            case 'mg_sledztwa':
                if (!$mg) blad('Brak uprawnień.', 403);
                $lista = $db->query('SELECT s.id, s.konto_login, s.stan, s.tytul, s.seed, s.utworzono_utc, z.nazwa AS zdarzenie, r.nazwa AS relacja,
                        zg.nazwa AS zdarzenie_zgloszone
                    FROM sledztwa s JOIN sledztwo_zdarzenia z ON z.id = s.zdarzenie_id JOIN sledztwo_relacje r ON r.id = s.relacja_id
                    LEFT JOIN sledztwo_zdarzenia zg ON zg.id = s.zdarzenie_zgloszone_id ORDER BY s.id DESC LIMIT 200')->fetchAll();
                odpowiedz(['sledztwa' => $lista]);

            case 'mg_sledztwo_podglad':
                if (!$mg) blad('Brak uprawnień.', 403);
                odpowiedz(sledztwo_podglad($db, (int) ($_GET['id'] ?? 0)));

            // Lista detektywów (zakładka Konta w panelu MG).
            case 'mg_detektywi':
                if (!$mg) blad('Brak uprawnień.', 403);
                $q = $db->query('SELECT d.konto_login, d.droid_oznaczenie, COUNT(s.id) AS sledztwa
                    FROM sledztwo_detektywi d LEFT JOIN sledztwa s ON s.konto_login = d.konto_login
                    GROUP BY d.konto_login, d.droid_oznaczenie ORDER BY d.konto_login');
                odpowiedz(['detektywi' => $q->fetchAll()]);

            // Nadanie / odebranie roli detektywa: {login, detektyw: true|false}. Konto musi istnieć w JSON-ie kont
            // (tylko odczyt). Detektywa z zapisanymi śledztwami nie da się odebrać (klucz obcy) — najpierw MG usuwa śledztwa.
            case 'mg_detektyw_ustaw':
                if (!$mg) blad('Brak uprawnień.', 403);
                if (!$post) blad('Zła metoda.', 405);
                $w = wejscie();
                $login = trim((string) ($w['login'] ?? ''));
                $konto = null;
                foreach (uzytkownicy() as $x) if (mb_strtolower((string) $x['login']) === mb_strtolower($login)) { $konto = $x; break; }
                if (!$konto) blad('Nie ma takiego konta.', 404);
                if (!empty($w['detektyw'])) {
                    $db->prepare('INSERT INTO sledztwo_detektywi (konto_login) VALUES (?) ON DUPLICATE KEY UPDATE konto_login = konto_login')
                        ->execute([(string) $konto['login']]);
                } else {
                    try {
                        $db->prepare('DELETE FROM sledztwo_detektywi WHERE konto_login = ?')->execute([(string) $konto['login']]);
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23000') blad('To konto ma zapisane śledztwa — nie można odebrać roli detektywa.');
                        throw $e;
                    }
                }
                odpowiedz(['ok' => true]);
        }
    } catch (SledztwoBlad $e) {
        blad($e->getMessage());
    }
}

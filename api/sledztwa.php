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
    foreach ($db->query('SELECT kod, nazwa, poziom_min, poziom_max, waga FROM gen_rodzaje_miejsc')->fetchAll() as $r) $p['rodzaje'][$r['kod']] = $r;

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

// ---------- akcje ----------

function obsluz_sledztwa(string $akcja, bool $post): void
{
    $akcje = ['sledztwo_nowe', 'mg_sledztwa', 'mg_sledztwo_podglad'];
    if (!in_array($akcja, $akcje, true)) return;
    $u = wymagaj_logowania();
    $mg = ($u['rola'] ?? '') === 'mg';
    $db = polaczenie_bazy();
    try {
        $db->query('SELECT 1 FROM sledztwo_warianty LIMIT 1');
    } catch (Throwable $e) {
        blad('Moduł śledztw nie odpowiada. (Brak migracji 082.)');
    }

    try {
        switch ($akcja) {
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
        }
    } catch (SledztwoBlad $e) {
        blad($e->getMessage());
    }
}

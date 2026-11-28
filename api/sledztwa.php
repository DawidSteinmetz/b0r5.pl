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
const SLEDZTWO_NAZWY_KODOW = ['A' => 'strona A', 'B' => 'strona B', 'Z' => 'zleceniodawca', 'S' => 'śledzący', 'W' => 'świadek', 'V' => 'zgłaszający'];

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

    try {
        $p['presety'] = [];
        foreach ($db->query('SELECT * FROM gen_presety_budynkow')->fetchAll() as $b) $p['presety'][$b['kod']] = $b;
        $p['lokale'] = [];
        foreach ($db->query('SELECT * FROM gen_presety_lokali ORDER BY budynek, strefa, kolejnosc')->fetchAll() as $l) $p['lokale'][$l['budynek']][$l['strefa']][] = $l;
    } catch (PDOException $e) {
        throw new SledztwoBlad('Brak presetów budynków (migracja 086).');
    }
    if (!isset($p['presety']['ogolny'], $p['presety']['techniczny'])) throw new SledztwoBlad('Brak presetów budynków (migracja 086).');

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
    $q = $db->prepare("SELECT b.id, b.nazwa, b.typ, b.rodzaj, b.rodzic_id, r.nazwa AS skupisko, r.x_km AS sx, r.y_km AS sy
        FROM lokalizacje b JOIN lokalizacje r ON r.id = b.rodzic_id
        WHERE b.planeta_id = ? AND b.status = 'aktywna' AND b.typ IN ('budynek','ladowisko') ORDER BY b.id");
    $q->execute([$planetaId]);
    $skupiska = [];
    foreach ($q->fetchAll() as $b) {
        $id = (int) $b['rodzic_id'];
        $skupiska[$id]['id'] = $id;
        $skupiska[$id]['nazwa'] = $b['skupisko'];
        $skupiska[$id]['x_km'] = $b['sx'];
        $skupiska[$id]['y_km'] = $b['sy'];
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
        $miejsca[] = sl_miejsce($pule, $seed, $skupisko, $fazy[$f], $zajetePokoje);
    }
    // Kryjówka (faza 7 z tagiem kryjowka) bez {M} w szkicu i tak dostaje miejsce — tam przebywa A.
    if (strpos($fazy[7]['szkic'], '{M}') === false && in_array('kryjowka', sl_tagi($fazy[7]['tagi']), true)) {
        $miejsca[] = sl_miejsce($pule, $seed, $skupisko, $fazy[7], $zajetePokoje);
    }
    // Miejsce startowe (faza 2: zgłoszenie) jest zawsze — tam droid zaczyna i tam czeka zgłaszający (VIP).
    $startIdx = null;
    foreach ($miejsca as $i => $m) if ($m['faza_nr'] === 2) { $startIdx = $i; break; }
    if ($startIdx === null) {
        $miejsca[] = sl_miejsce($pule, $seed, $skupisko, $fazy[2], $zajetePokoje);
        $startIdx = count($miejsca) - 1;
    }

    // 5. Postacie kluczowe: A, B zawsze; Z / S / W, gdy końcowe tagi ich wymagają. Poboczni: 1–2 na miejsce.
    $kody = ['A', 'B'];
    foreach (SLEDZTWO_TAG_POSTACI as $tag => $kod) if (isset($tagi[$tag])) $kody[] = $kod;
    $postacie = [];
    $uzyteNazwy = [];
    // A i B muszą pasować do relacji (rodzic nie jest droidem, „dziecko” jest młode, rodzina ma wspólne nazwisko).
    foreach ($kody as $kod) {
        $wzor = sl_wiezy_relacji((int) $relacjaId, $kod, $postacie[0] ?? null);
        $postacie[] = sl_postac($pule, $kod, 'kluczowy', $miejsca, $tagi, $uzyteNazwy, null, $wzor);
    }
    // VIP: postać, którą szkic fazy 2 wskazuje jako zgłaszającego (B, Z, W, S, A), albo nowa postać „V”.
    $vip = null;
    foreach (['B', 'Z', 'W', 'S', 'A'] as $k) {
        if (in_array($k, $kody, true) && strpos($fazy[2]['szkic'], '{' . $k . '}') !== false) { $vip = $k; break; }
    }
    if ($vip) {
        foreach ($postacie as &$p) if ($p['kod'] === $vip) $p['miejsce_idx'] = $startIdx;
        unset($p);
    } else {
        $postacie[] = sl_postac($pule, 'V', 'kluczowy', $miejsca, $tagi, $uzyteNazwy, $startIdx);
    }
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
        'nowe_budynki' => $skupisko ? array_values(array_filter($skupisko['budynki'], fn($b) => !empty($b['nowy']))) : [],
        'fazy' => array_values($fazy), 'miejsca' => $miejsca, 'postacie' => $postacie,
    ];
}

// Budynek, którego kompleks nie ma, a sprawa go potrzebuje: [typ, rodzaj w lokalizacje, wzór nazwy]. Powstaje przy
// zapisie sprawy jako prawdziwy wpis w `lokalizacje` (wspólna mapa) — lokacje „precyzują się” dopiero w kontekście.
const SLEDZTWO_NOWY_BUDYNEK = [
    'hotel' => ['budynek', 'hotel', 'Hotel „%s”'], 'kantyna' => ['budynek', 'kantyna', 'Kantyna „%s”'],
    'sklep' => ['budynek', 'sklep', 'Sklep „%s”'], 'targ' => ['budynek', 'inne', 'Targ „%s”'],
    'hangar' => ['budynek', 'hangar', 'Hangar %s'], 'ladowisko' => ['ladowisko', 'platforma', 'Lądowisko %s'],
    'kasyno' => ['budynek', 'inne', 'Kasyno „%s”'], 'biuro' => ['budynek', 'biuro', 'Biurowiec „%s”'],
    'magazyn' => ['budynek', 'magazyn', 'Magazyn %s'], 'mieszkalny' => ['budynek', 'inne', 'Blok mieszkalny %s'],
];

function sl_nowy_budynek(array $pule, array &$skupisko, string $preset): ?array
{
    if (!isset(SLEDZTWO_NOWY_BUDYNEK[$preset], $pule['presety'][$preset])) return null;
    [$typ, $rodzaj, $wzor] = SLEDZTWO_NOWY_BUDYNEK[$preset];
    $nazwy = array_values(array_filter($pule['imiona'], fn($i) => in_array($i['rodzaj'], ['przydomek', 'nazwisko'], true)));
    $zajete = array_map(fn($b) => mb_strtolower((string) $b['nazwa']), $skupisko['budynki']);
    for ($proba = 0; $proba < 20; $proba++) {
        $cecha = in_array($preset, ['hangar', 'ladowisko', 'magazyn', 'mieszkalny'], true) || !$nazwy
            ? strtoupper(chr(65 + sl_los(0, 25))) . '-' . sprintf('%02d', sl_los(1, 99))
            : sl_jeden($nazwy)['wartosc'];
        $nazwa = sprintf($wzor, $cecha);
        if (!in_array(mb_strtolower($nazwa), $zajete, true)) break;
    }
    $b = ['id' => null, 'nazwa' => $nazwa, 'typ' => $typ, 'rodzaj' => $rodzaj, 'nowy' => true, 'preset' => $preset,
        'preset_nazwa' => $pule['presety'][$preset]['nazwa'],
        'x_km' => round((float) ($skupisko['x_km'] ?? 0) + sl_los(-30, 30) / 100, 3),
        'y_km' => round((float) ($skupisko['y_km'] ?? 0) + sl_los(-30, 30) / 100, 3)];
    $skupisko['budynki'][] = $b;
    return $b;
}

function sl_miejsce(array $pule, int $seed, ?array &$skupisko, array $faza, array &$zajetePokoje): array
{
    // Rodzaj: podpowiedź ze słów wariantu, inaczej losowanie wg wag fabularnych (lokale publiczne).
    $tekst = mb_strtolower($faza['wariant'] . ' ' . $faza['szkic']);
    $rodzaj = null;
    foreach (SLEDZTWO_SLOWA_MIEJSC as $slowo => $kod) {
        if (isset($pule['rodzaje'][$kod]) && mb_strpos($tekst, $slowo) !== false) { $rodzaj = $kod; break; }
    }
    if (!$rodzaj) {
        $wagi = array_map(fn($r) => ['kod' => $r['kod'], 'waga' => SLEDZTWO_WAGI_FABULY[$r['kod']] ?? 0], array_values($pule['rodzaje']));
        $rodzaj = sl_wazony($wagi)['kod'];
    }
    $r = $pule['rodzaje'][$rodzaj];
    // Budynek skupiska o zgodnym presecie (kantyna w kantynie, hangar w hangarze); poziom — w obrębie jego pięter.
    $budynek = null;
    $wlasny = false;
    if ($skupisko) {
        $preset = SLEDZTWO_RODZAJ_PRESET[$r['kod']] ?? null;
        $zgodne = array_filter($skupisko['budynki'], fn($b) => sl_preset_kod($pule, $b) === $preset);
        // Brak takiego budynku w kompleksie → nowy budynek na wspólnej mapie (powstanie przy zapisie sprawy).
        if (!$zgodne && $preset && ($nowy = sl_nowy_budynek($pule, $skupisko, $preset))) $zgodne = [$nowy];
        $wlasny = (bool) $zgodne;
        $budynek = sl_jeden($zgodne ?: $skupisko['budynki']);
    }
    [$pmin, $pmax] = [(int) $r['poziom_min'], (int) $r['poziom_max']];
    if ($wlasny) {
        $prof = sl_profil($pule, $budynek);
        // Lokal fabularny presetu bywa tylko na parterze (sala kantyny) albo na piętrach (pokój gościnny).
        $naParterze = false;
        foreach ($pule['lokale'][$prof['kod']]['parter'] ?? [] as $w) if ($w['lokal'] === $pule['presety'][$prof['kod']]['lokal_fabularny']) $naParterze = true;
        [$pmin, $pmax] = $naParterze ? [$prof['parter'], $prof['parter']] : [min($prof['gora'], $prof['parter'] + 1), $prof['gora']];
    }
    do {
        $poziom = sl_los($pmin, $pmax);
        $pokoj = sprintf('%d%02d', $poziom, sl_los(1, 40));
        $klucz = ($budynek['nazwa'] ?? '') . '/' . $poziom . '/' . $pokoj;
    } while (isset($zajetePokoje[$klucz]));
    $zajetePokoje[$klucz] = true;

    // Nazwa lokalu (do akt MG i dla Autora): budynek o zgodnym presecie daje swoją nazwę; inaczej rodzaj + przydomek.
    if ($wlasny) {
        $nazwa = in_array(SLEDZTWO_RODZAJ_PRESET[$r['kod']] ?? '', ['hotel', 'biuro', 'mieszkalny', 'magazyn', 'targ'], true)
            ? $r['nazwa'] . ' ' . $pokoj . ', ' . $budynek['nazwa'] : $budynek['nazwa'];
    } elseif (in_array($r['kod'], ['kantyna', 'sklep', 'kasyno', 'hotel', 'biuro', 'hangar'], true)) {
        $przydomki = array_values(array_filter($pule['imiona'], fn($i) => $i['rodzaj'] === 'przydomek'));
        $p = sl_jeden($przydomki);
        $nazwa = $r['nazwa'] . ($p ? ' „' . $p['wartosc'] . '”' : ' ' . $pokoj);
    } else {
        $nazwa = $r['nazwa'] . ' ' . $pokoj;
    }
    if ($budynek && !$wlasny) $nazwa .= ' (' . $budynek['nazwa'] . ')';

    return ['faza_nr' => $faza['faza_nr'], 'lokalizacja_id' => $budynek && $budynek['id'] !== null ? (int) $budynek['id'] : null,
        'budynek' => $budynek['nazwa'] ?? null, 'nowy_budynek' => !empty($budynek['nowy']), 'rodzaj_kod' => $r['kod'], 'rodzaj' => $r['nazwa'],
        'poziom' => $poziom, 'pokoj' => $pokoj, 'nazwa' => mb_substr($nazwa, 0, 160)];
}

// $miejsceIdx: indeks miejsca (poboczni) albo null (kluczowi — dobierani do miejsca wg archetypu).
// Więzy, jakie relacja A–B (sledztwo_relacje, migracja 082) nakłada na postać $kod; $a — wylosowany już A.
// Rodzina i para nie są droidami; „dziecko” jest nastolatkiem, rodzic starszy o pokolenie, rodzina ma ten sam
// gatunek i nazwisko; uczeń młodszy od mistrza; dawni towarzysze broni w zbliżonym, dojrzałym wieku.
function sl_wiezy_relacji(int $relacjaId, string $kod, ?array $a): array
{
    if ($kod === 'A') {
        return [
            1 => ['bez_droida' => true, 'wiek' => [18, 55]],
            5 => ['bez_droida' => true, 'wiek' => [12, 19]],
            6 => ['bez_droida' => true, 'wiek' => [14, 40]],
            7 => ['wiek' => [15, 28]],
            11 => ['bez_droida' => true, 'wiek' => [35, 70]],
        ][$relacjaId] ?? [];
    }
    if ($kod !== 'B' || !$a) return [];
    $w = $a['wiek'] === null ? null : (int) $a['wiek'];
    $rodzina = ['gatunek' => $a['gatunek_kod'], 'bez_droida' => true] + ($a['nazwisko'] ? ['nazwisko' => $a['nazwisko']] : []);
    switch ($relacjaId) {
        case 1: return ['bez_droida' => true] + ($w ? ['wiek' => [max(18, $w - 8), $w + 10]] : []);
        case 5: return $rodzina + ($w ? ['wiek' => [$w + 18, $w + 35]] : []);
        case 6: return $rodzina + ($w ? ['wiek' => [max(10, $w - 8), $w + 8]] : []);
        case 7: return $w ? ['wiek' => [$w + 10, $w + 40]] : [];
        case 11: return ['bez_droida' => true] + ($w ? ['wiek' => [max(30, $w - 10), $w + 10]] : []);
    }
    return [];
}

// $wzor (więzy z relacji A–B, patrz sl_wiezy_relacji): gatunek, bez_droida, wiek [min, max], nazwisko.
function sl_postac(array $pule, ?string $kod, string $waga, array $miejsca, array $tagi, array &$uzyteNazwy, ?int $miejsceIdx, array $wzor = []): array
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

    $populacja = $pule['populacja'];
    if (!empty($wzor['bez_droida'])) $populacja = array_values(array_filter($populacja, fn($x) => $x['kod'] !== 'droid')) ?: $populacja;
    $gat = !empty($wzor['gatunek']) && isset($pule['gatunki'][$wzor['gatunek']]) ? $wzor['gatunek'] : sl_wazony($populacja)['kod'];
    if (!isset($pule['gatunki'][$gat])) $gat = array_key_first($pule['gatunki']);
    $plec = $gat === 'droid' ? 'n' : (sl_procent(50) ? 'm' : 'k');
    $wiek = $gat === 'droid' ? null : (isset($wzor['wiek']) ? sl_los(max(1, $wzor['wiek'][0]), max(1, $wzor['wiek'][0], $wzor['wiek'][1])) : sl_los(18, 70));

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
        $nazwisko = $gat === 'droid' ? null : ($wzor['nazwisko'] ?? $wybierz('nazwisko'));
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
            kolejnosc, tytul, planeta_id, skupisko_id, utworzono_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$plan['konto_login'], $plan['zdarzenie_id'], $plan['relacja_id'], $plan['zdarzenie_zgloszone_id'],
                $plan['ukrywa_kod'], $plan['seed'], 'losowanie', $plan['kolejnosc'], $plan['tytul'], $plan['planeta_id'],
                $plan['skupisko']['id'] ?? null, sl_utc(sl_teraz())]);
        $id = (int) $db->lastInsertId();

        $q = $db->prepare('INSERT INTO sledztwo_fazy (sledztwo_id, faza_nr, pozycja, wariant_nr, jawnosc, tagi) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($plan['fazy'] as $f) $q->execute([$id, $f['faza_nr'], $f['pozycja'], $f['wariant_nr'], $f['jawnosc'], $f['tagi']]);

        // Nowe budynki na wspólnej mapie (kompleks nie miał budynku tego rodzaju). Nazwa zajęta na planecie → numer.
        $idNowych = [];
        if (!empty($plan['nowe_budynki']) && !empty($plan['skupisko']['id'])) {
            $jest = $db->prepare('SELECT id, rodzic_id FROM lokalizacje WHERE planeta_id = ? AND nazwa = ?');
            $wstaw = $db->prepare("INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
                VALUES (?, ?, ?, NULL, ?, ?, ?, ?, 1, 'aktywna', ?)");
            foreach ($plan['nowe_budynki'] as $b) {
                $nazwa = $b['nazwa'];
                for ($n = 2; ; $n++) {
                    $jest->execute([$plan['planeta_id'], $nazwa]);
                    $w = $jest->fetch();
                    if (!$w) break;
                    // Ten sam budynek dopisała już inna sprawa w tym kompleksie — używamy go.
                    if ((int) $w['rodzic_id'] === (int) $plan['skupisko']['id']) { $idNowych[$b['nazwa']] = (int) $w['id']; continue 2; }
                    $nazwa = $b['nazwa'] . ' ' . $n;
                }
                $wstaw->execute([$b['typ'], $b['rodzaj'], (int) $plan['skupisko']['id'], $plan['planeta_id'], $nazwa, $b['x_km'], $b['y_km'],
                    '[LORE: AUTORSKI] ' . ($b['preset_nazwa'] ?? 'Budynek') . ' w kompleksie ' . $plan['skupisko']['nazwa'] . '.']);
                $idNowych[$b['nazwa']] = (int) $db->lastInsertId();
            }
        }

        $idMiejsc = [];
        $q = $db->prepare('INSERT INTO sledztwo_miejsca (sledztwo_id, faza_nr, lokalizacja_id, rodzaj_kod, poziom, pokoj, nazwa) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($plan['miejsca'] as $i => $m) {
            if (!empty($m['nowy_budynek'])) $plan['miejsca'][$i]['lokalizacja_id'] = $m['lokalizacja_id'] = $idNowych[$m['budynek']] ?? null;
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

        // Droid startuje W lokalu startowym (faza 2: zgłoszenie), przy zgłaszającym.
        $start = $plan['miejsca'][0];
        foreach ($plan['miejsca'] as $m) if ($m['faza_nr'] === 2) { $start = $m; break; }
        $db->prepare('INSERT INTO sledztwo_droid (sledztwo_id, lokalizacja_id, poziom, pokoj) VALUES (?, ?, ?, ?)')
            ->execute([$id, $start['lokalizacja_id'], $start['poziom'], $start['pokoj']]);
        $db->prepare("INSERT INTO sledztwo_dziennik (sledztwo_id, kiedy_utc, rodzaj, kto, tresc) VALUES (?, ?, 'meldunek', 'droid', ?)")
            ->execute([$id, sl_utc(sl_teraz()), "Jestem na miejscu zgłoszenia: {$start['nazwa']}, poziom {$start['poziom']}, lokal {$start['pokoj']}. "
                . 'Zgłaszający czeka tutaj. Teczka sprawy jest w ekwipunku.']);

        $db->prepare("INSERT INTO sledztwo_dziennik (sledztwo_id, kiedy_utc, rodzaj, kto, tresc) VALUES (?, ?, 'system', 'system', ?)")
            ->execute([$id, sl_utc(sl_teraz()), "Szkielet wylosowany (seed {$plan['seed']}). Czeka na treść od modelu Autor."]);
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

const SLEDZTWO_PREDKOSC_MS = 5 / 3.6;  // chód droida: 5 km/h
const SLEDZTWO_WINDA_S_POZIOM = 1.5;   // jazda windą na poziom (szybkie windy turbo Nar Shaddaa)
const SLEDZTWO_WINDA_CZEKANIE_S = 10;  // czekanie na windę
const SLEDZTWO_ZASIEG = 230;           // zasięg skanu w jednostkach planu (pokój = 60 j.)
const SLEDZTWO_POKOJ_J = 60;
const SLEDZTWO_POZIOM_MAX = 120;
const SLEDZTWO_STANY_RUCHU = ['pisanie', 'trwa'];

function sl_teraz(): int { return time(); }
function sl_utc(int $t): string { return gmdate('Y-m-d H:i:s', $t); }
function sl_z_utc(?string $s): ?int { return $s ? (int) strtotime($s . ' UTC') : null; }
// Data gry jak czasGry() w js/rdzen/narzedzia.js: DD|MM|RR GG:MM, rok gry = rok realny − 2010, czas polski.
function sl_data_gry(int $t, bool $godz = true): string
{
    return date('d|m|', $t) . sprintf('%02d', (int) date('Y', $t) - 2010) . ($godz ? date(' H:i', $t) : '');
}

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

// ---------- presety budynków (migracja 086) ----------

// Słowa w nazwie budynku → preset (wygrywają z lokalizacje.rodzaj, bo wiele budynków ma rodzaj „inne”).
const SLEDZTWO_SLOWA_BUDYNKOW = ['kasyno' => 'kasyno', 'klub' => 'kantyna', 'kantyna' => 'kantyna', 'hotel' => 'hotel',
    'gospoda' => 'hotel', 'blok mieszkalny' => 'mieszkalny', 'hala towarowa' => 'targ', 'targ' => 'targ', 'sklep' => 'sklep',
    'magazyn' => 'magazyn', 'skład' => 'magazyn', 'rafineria' => 'magazyn', 'zakład' => 'magazyn', 'lądowisko' => 'ladowisko',
    'hangar' => 'hangar', 'warsztat' => 'hangar', 'biuro' => 'biuro', 'dom maklerski' => 'biuro', 'giełda' => 'biuro',
    'kantor' => 'biuro', 'posterunek' => 'biuro', 'kwatera' => 'biuro'];
// Rodzaj miejsca fabularnego (gen_rodzaje_miejsc) → preset budynku, w którym naturalnie leży.
const SLEDZTWO_RODZAJ_PRESET = ['kantyna' => 'kantyna', 'sklep' => 'sklep', 'targ' => 'targ', 'hangar' => 'hangar',
    'ladowisko' => 'ladowisko', 'kasyno' => 'kasyno', 'hotel' => 'hotel', 'biuro' => 'biuro', 'magazyn' => 'magazyn',
    'mieszkanie' => 'mieszkalny', 'nora_slicera' => 'techniczny', 'korytarz' => 'techniczny'];
// Wagi rodzajów miejsc fabularnych (szum ma własne wagi w gen_rodzaje_miejsc): sprawy toczą się w lokalach publicznych.
const SLEDZTWO_WAGI_FABULY = ['kantyna' => 14, 'sklep' => 10, 'targ' => 6, 'hangar' => 9, 'ladowisko' => 9, 'kasyno' => 8,
    'hotel' => 10, 'biuro' => 6, 'magazyn' => 5, 'mieszkanie' => 3, 'nora_slicera' => 2, 'korytarz' => 0];

// Ziarno świata: budynki, plany pięter, strażnicy i przechodnie są wspólni dla wszystkich spraw i detektywów
// (wspólna mapa; docelowo wiele droidów naraz). Ziarno sprawy losuje tylko to, co należy do sprawy.
const SLEDZTWO_SWIAT = 1977;

// Liczba z zakresu z skrótu (bez ruszania mt_rand).
function sl_hash_int(int $seed, int $min, int $max, string ...$czesci): int
{
    if ($max <= $min) return $min;
    return $min + (crc32($seed . '|' . implode('|', $czesci)) % ($max - $min + 1));
}

function sl_preset_kod(array $pule, ?array $b): string
{
    if (!$b) return 'ogolny';
    $n = mb_strtolower((string) $b['nazwa']);
    foreach (SLEDZTWO_SLOWA_BUDYNKOW as $slowo => $kod) if (str_contains($n, $slowo)) return isset($pule['presety'][$kod]) ? $kod : 'ogolny';
    $k = SLEDZTWO_RODZAJ_BUDYNKU[(string) ($b['rodzaj'] ?? '')] ?? (($b['typ'] ?? '') === 'ladowisko' ? 'ladowisko' : null);
    return $k && isset($pule['presety'][$k]) ? $k : 'ogolny';
}

// Profil budynku: preset, poziom parteru i najwyższy poziom (deterministycznie z ziarna sprawy).
function sl_profil(array $pule, ?array $b): array
{
    $kod = sl_preset_kod($pule, $b);
    $p = $pule['presety'][$kod];
    $bid = (string) ($b['nazwa'] ?? 'kompleks');
    $parter = sl_hash_int(SLEDZTWO_SWIAT, (int) $p['parter_min'], (int) $p['parter_max'], 'parter', $bid);
    $pieter = sl_hash_int(SLEDZTWO_SWIAT, (int) $p['pieter_min'], (int) $p['pieter_max'], 'pieter', $bid);
    return ['kod' => $kod, 'nazwa' => $p['nazwa'], 'parter' => $parter, 'gora' => min(SLEDZTWO_POZIOM_MAX, $parter + $pieter - 1)];
}

function sl_lokale_presetu(array $pule, string $kod, string $strefa): array
{
    $l = $pule['lokale'];
    return $l[$kod][$strefa] ?? ($strefa === 'gora' ? ($l[$kod]['pietro'] ?? null) : null)
        ?? $l['ogolny'][$strefa === 'gora' ? 'pietro' : $strefa] ?? $l['ogolny']['pietro'] ?? [];
}

// Plan piętra z presetu budynku: lokale po obu stronach korytarza (winda po lewej, x = 0), kolejność od windy.
// Pokoje fabuły zawsze są na planie (zastępują lokal tego samego typu); poziomy fabuły poszerzają budynek.
function sl_plan(PDO $db, array $s, array $pule, ?array $budynek, int $poziom): array
{
    $bid = $budynek['id'] ?? 0;
    $warunek = $budynek ? 'lokalizacja_id = ?' : 'lokalizacja_id IS NULL';
    $arg = $budynek ? [(int) $s['id'], $bid] : [(int) $s['id']];
    $q = $db->prepare("SELECT id, faza_nr, poziom, pokoj, rodzaj_kod, nazwa, opis, zamkniete FROM sledztwo_miejsca WHERE sledztwo_id = ? AND {$warunek}");
    $q->execute($arg);
    $fabula = [];
    $prof = sl_profil($pule, $budynek);
    foreach ($q->fetchAll() as $m) {
        $prof['parter'] = min($prof['parter'], (int) $m['poziom']);
        $prof['gora'] = max($prof['gora'], (int) $m['poziom']);
        if ((int) $m['poziom'] === $poziom) $fabula[] = $m;
    }
    $strefa = $poziom === $prof['parter'] ? 'parter' : ($poziom === $prof['gora'] ? 'gora'
        : ($poziom > $prof['parter'] && $poziom < $prof['gora'] ? 'pietro' : 'techniczny'));
    $preset = $strefa === 'techniczny' ? 'techniczny' : $prof['kod'];

    sl_ziarno(SLEDZTWO_SWIAT, 'plan', (string) $bid, (string) $poziom);
    $pokoje = [];
    foreach (sl_lokale_presetu($pule, $preset, $strefa) as $w) {
        for ($k = 0, $n = sl_los((int) $w['ile_min'], (int) $w['ile_max']); $k < $n; $k++) {
            $pokoje[] = ['nr' => null, 'lokal' => $w['lokal'], 'rodzaj' => $w['nazwa'], 'rodzaj_kod' => $w['bywalcy'] ?: 'korytarz',
                'dostep' => $w['dostep'], 'zamkniete' => $w['dostep'] === 'wolny' && sl_procent((int) $w['zamek_proc']),
                'szer' => max(1, (int) $w['szer']), 'terminal' => sl_procent((int) $w['terminal_proc']), 'ludzie_max' => (int) $w['ludzie_max'],
                'opis_szumu' => $w['opis'], 'miejsce_id' => null, 'nazwa' => null, 'opis' => null];
        }
    }
    // Pokoje fabuły: w budynku zgodnego rodzaju są lokalem „fabularnym” presetu (z jego regułą wstępu).
    $fabLokal = $pule['presety'][$prof['kod']]['lokal_fabularny'];
    $wzor = null;
    foreach ($pule['lokale'][$prof['kod']] ?? [] as $wiersze) foreach ($wiersze as $w) if ($w['lokal'] === $fabLokal) { $wzor = $w; break 2; }
    foreach ($fabula as $m) {
        $zgodny = $wzor && (SLEDZTWO_RODZAJ_PRESET[$m['rodzaj_kod']] ?? '') === $prof['kod'];
        $pokoj = ['nr' => (string) $m['pokoj'], 'lokal' => $zgodny ? $wzor['lokal'] : $m['rodzaj_kod'],
            'rodzaj' => $zgodny ? $wzor['nazwa'] : ($pule['rodzaje'][$m['rodzaj_kod']]['nazwa'] ?? $m['rodzaj_kod']),
            'rodzaj_kod' => $m['rodzaj_kod'], 'dostep' => $zgodny && (int) $m['faza_nr'] !== 2 ? $wzor['dostep'] : 'wolny',
            'zamkniete' => (int) $m['faza_nr'] !== 2 && (bool) $m['zamkniete'],
            'szer' => $zgodny ? max(1, (int) $wzor['szer']) : 1, 'terminal' => $zgodny && (int) $wzor['terminal_proc'] >= 50,
            'ludzie_max' => 0, 'opis_szumu' => $zgodny ? $wzor['opis'] : (string) ($pule['rodzaje'][$m['rodzaj_kod']]['opis_szumu'] ?? ''),
            'miejsce_id' => (int) $m['id'], 'nazwa' => $m['nazwa'], 'opis' => $m['opis']];
        $gdzie = null;
        foreach ($pokoje as $i => $p) if ($p['miejsce_id'] === null && $p['lokal'] === $pokoj['lokal']) { $gdzie = $i; break; }
        if ($gdzie === null) $pokoje[] = $pokoj; else $pokoje[$gdzie] = $pokoj;
    }
    // Numeracja (pomija numery pokoi fabuły), strony korytarza, szerokości, drzwi, terminale.
    $zajete = array_filter(array_column($pokoje, 'nr'));
    $i = 1;
    $xs = ['gora' => 0, 'dol' => 0];
    $terminal = 0;
    foreach ($pokoje as $j => &$p) {
        if ($p['nr'] === null) {
            do { $nr = sprintf('%d%02d', $poziom, $i++); } while (in_array($nr, $zajete, true));
            $p['nr'] = $nr;
        }
        $strona = $xs['gora'] <= $xs['dol'] ? 'gora' : 'dol';
        $p['strona'] = $strona;
        $p['x'] = $xs[$strona];
        $xs[$strona] += $p['szer'] * SLEDZTWO_POKOJ_J;
        $p['drzwi'] = $j + 1;
        $p['terminal'] = $p['terminal'] ? 'T' . (++$terminal) : null;
    }
    unset($p);
    $korytarzTerminal = sl_procent($strefa === 'techniczny' ? 20 : 50) ? 'T' . (++$terminal) : null;
    return ['budynek_id' => $budynek['id'] ?? null, 'budynek' => $budynek['nazwa'] ?? 'Kompleks', 'poziom' => $poziom,
        'preset' => $prof['kod'], 'preset_nazwa' => $prof['nazwa'], 'strefa' => $strefa, 'parter' => $prof['parter'], 'gora' => $prof['gora'],
        'szerokosc' => max($xs['gora'], $xs['dol']), 'pokoje' => $pokoje, 'terminal_korytarz' => $korytarzTerminal];
}

function sl_srodek(array $p): int
{
    return $p['x'] + intdiv($p['szer'] * SLEDZTWO_POKOJ_J, 2);
}

// Środek pokoju (albo windy, gdy droid stoi na korytarzu) na osi korytarza.
function sl_x_droida(array $plan, string $pokoj): int
{
    foreach ($plan['pokoje'] as $p) if ($p['nr'] === $pokoj) return sl_srodek($p);
    return 0;
}

// ---------- geometria planu: swobodny ruch (10 j. = 1 m), drzwi, terminale ----------
// Rysunek w js/aplikacje/sledztwa.js używa tych samych wymiarów (P, GLEB, KOR, WINDA).

const SLEDZTWO_GLEB = 70;
const SLEDZTWO_KOR = 36;
const SLEDZTWO_WINDA = 44;
const SLEDZTWO_J_NA_M = 10;
const SLEDZTWO_ZASIEG_TERMINALA = 18;   // droid podłącza się z ok. 1,5 m
const SLEDZTWO_ZASIEG_ROZMOWY = 32;     // rozmowa z 2–3 m
const SLEDZTWO_ODSTEP_ROZMOWY = 24;     // tyle droid zostawia między sobą a rozmówcą
const SLEDZTWO_STANIE_W_DRZWIACH_S = 45; // po tylu sekundach ktoś prosi droida o przejście

function sl_dl_planu(array $plan): int { return max((int) $plan['szerokosc'], 4 * SLEDZTWO_POKOJ_J); }
function sl_y_pokoju(string $strona): int { return $strona === 'gora' ? 0 : SLEDZTWO_GLEB + SLEDZTWO_KOR; }
function sl_drzwi_xy(array $p): array { return [sl_srodek($p), $p['strona'] === 'gora' ? SLEDZTWO_GLEB : SLEDZTWO_GLEB + SLEDZTWO_KOR]; }
// Punkt na korytarzu tuż przed drzwiami lokalu i tuż za nimi (w lokalu).
function sl_przed_drzwiami(array $p): array { return [sl_srodek($p), $p['strona'] === 'gora' ? SLEDZTWO_GLEB + 9 : SLEDZTWO_GLEB + SLEDZTWO_KOR - 9]; }
function sl_za_drzwiami(array $p): array { return [sl_srodek($p), $p['strona'] === 'gora' ? SLEDZTWO_GLEB - 12 : SLEDZTWO_GLEB + SLEDZTWO_KOR + 12]; }
function sl_winda_xy(): array { return [-intdiv(SLEDZTWO_WINDA, 2), SLEDZTWO_GLEB + intdiv(SLEDZTWO_KOR, 2)]; }
function sl_srodek_xy(array $plan, string $pokoj): array
{
    $p = sl_lokal($plan, $pokoj);
    return $p ? [sl_srodek($p), sl_y_pokoju($p['strona']) + intdiv(SLEDZTWO_GLEB, 2)] : [12, SLEDZTWO_GLEB + intdiv(SLEDZTWO_KOR, 2)];
}

function sl_lokal(array $plan, string $nr): ?array
{
    foreach ($plan['pokoje'] as $p) if ($p['nr'] === $nr) return $p;
    return null;
}

// Punkt kliknięty na planie → [x, y, lokal] w granicach ścian (lokal albo korytarz z windą).
function sl_punkt(array $plan, int $x, int $y): array
{
    $m = 6;
    $k0 = SLEDZTWO_GLEB;
    $k1 = SLEDZTWO_GLEB + SLEDZTWO_KOR;
    if ($y < $k0 || $y > $k1) {
        $strona = $y < $k0 ? 'gora' : 'dol';
        foreach ($plan['pokoje'] as $p) {
            $wl = $p['szer'] * SLEDZTWO_POKOJ_J;
            if ($p['strona'] !== $strona || $x < $p['x'] || $x > $p['x'] + $wl) continue;
            $y0 = sl_y_pokoju($strona);
            return [max($p['x'] + $m, min($p['x'] + $wl - $m, $x)), max($y0 + $m, min($y0 + SLEDZTWO_GLEB - $m, $y)), $p['nr']];
        }
    }
    return [max(-SLEDZTWO_WINDA + $m, min(sl_dl_planu($plan) - $m, $x)), max($k0 + 2, min($k1 - 2, $y)), 'korytarz'];
}

// Trasa po piętrze bez przechodzenia przez ściany: z lokalu przez jego drzwi na korytarz, korytarzem do drzwi celu.
// Zwraca punkty i drzwi po drodze: [['lokal' => …, 'odl' => odległość od startu do progu, 'wyjscie' => bool]].
function sl_trasa(array $plan, array $a, array $b): array
{
    $pkt = [[$a[0], $a[1]]];
    $drzwi = [];
    $dl = 0.0;
    $dodaj = function (array $p) use (&$pkt, &$dl) {
        $o = end($pkt);
        $dl += hypot($p[0] - $o[0], $p[1] - $o[1]);
        $pkt[] = $p;
    };
    if ($a[2] !== $b[2]) {
        if ($a[2] !== 'korytarz' && ($p = sl_lokal($plan, $a[2]))) {
            $dodaj(sl_za_drzwiami($p));
            $dodaj(sl_drzwi_xy($p));
            $drzwi[] = ['lokal' => $p, 'odl' => $dl, 'wyjscie' => true];
            $dodaj(sl_przed_drzwiami($p));
        }
        if ($b[2] !== 'korytarz' && ($p = sl_lokal($plan, $b[2]))) {
            $dodaj(sl_przed_drzwiami($p));
            $dodaj(sl_drzwi_xy($p));
            $drzwi[] = ['lokal' => $p, 'odl' => $dl, 'wyjscie' => false];
            $dodaj(sl_za_drzwiami($p));
        }
    }
    $dodaj([$b[0], $b[1]]);
    return ['punkty' => $pkt, 'dl' => $dl, 'drzwi' => $drzwi];
}

function sl_sekundy_marszu(float $j): float { return $j / SLEDZTWO_J_NA_M / SLEDZTWO_PREDKOSC_MS; }

// Terminale z położeniem: [etykieta => ['x', 'y', 'pokoj', 'podejscie' => [x, y]]].
function sl_terminale(array $plan): array
{
    $t = [];
    foreach ($plan['pokoje'] as $p) {
        if (!$p['terminal']) continue;
        $x = $p['x'] + $p['szer'] * SLEDZTWO_POKOJ_J - 10;
        $y = sl_y_pokoju($p['strona']) + 12;
        $t[$p['terminal']] = ['x' => $x, 'y' => $y, 'pokoj' => $p['nr'], 'podejscie' => [$x - 6, $y + 14]];
    }
    if ($plan['terminal_korytarz']) {
        $x = sl_dl_planu($plan) - 10;
        $y = SLEDZTWO_GLEB + 18;
        $t[$plan['terminal_korytarz']] = ['x' => $x, 'y' => $y, 'pokoj' => 'korytarz', 'podejscie' => [$x - 14, $y]];
    }
    return $t;
}

// Pozycja droida [x, y, lokal]: z sledztwo_pozycje (migracja 088), a gdy nieaktualna — środek lokalu.
function sl_pozycja(PDO $db, array $s, array $d, array $plan, bool $cel = false): array
{
    $pokoj = (string) ($cel ? $d['cel_pokoj'] : $d['pokoj']);
    try {
        $q = $db->prepare('SELECT x, y, pokoj, cel_x, cel_y FROM sledztwo_pozycje WHERE sledztwo_id = ?');
        $q->execute([(int) $s['id']]);
        $r = $q->fetch();
    } catch (PDOException $e) { $r = null; }
    if ($r && !$cel && $r['pokoj'] === $pokoj) return sl_punkt($plan, (int) $r['x'], (int) $r['y']);
    if ($r && $cel && $r['cel_x'] !== null) return sl_punkt($plan, (int) $r['cel_x'], (int) $r['cel_y']);
    [$x, $y] = sl_srodek_xy($plan, $pokoj);
    return [$x, $y, sl_lokal($plan, $pokoj) ? $pokoj : 'korytarz'];
}

function sl_zapisz_pozycje(PDO $db, int $sid, array $poz, ?array $cel = null, ?string $zatrzymanie = null): void
{
    try {
        $db->prepare('INSERT INTO sledztwo_pozycje (sledztwo_id, x, y, pokoj, cel_x, cel_y, stoi_od_utc, zatrzymanie) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE x = VALUES(x), y = VALUES(y), pokoj = VALUES(pokoj), cel_x = VALUES(cel_x), cel_y = VALUES(cel_y),
            stoi_od_utc = VALUES(stoi_od_utc), zatrzymanie = VALUES(zatrzymanie)')
            ->execute([$sid, (int) round($poz[0]), (int) round($poz[1]), $poz[2], $cel ? (int) round($cel[0]) : null, $cel ? (int) round($cel[1]) : null,
                $cel ? null : sl_utc(sl_teraz()), $zatrzymanie]);
    } catch (PDOException $e) { /* brak migracji 088 — droid stoi na środku lokalu */ }
}

// Uprzejme prośby droida i odpowiedzi ludzi w drzwiach (losowane bez stałego ziarna).
const SLEDZTWO_PROSBY_DROIDA = [
    'Przepraszam najmocniej, czy {pan} się odsunie? Muszę przejść.',
    'Bardzo przepraszam za kłopot — czy pozwoli {pan}, że przejdę?',
    'Wybaczy {pan}, prowadzę czynności służbowe. Czy mogę przejść?',
    'Przepraszam, jeśli to nie kłopot — przepuści mnie {pan}?',
];
const SLEDZTWO_PROSBY_PONOWNE = [
    'Ponownie uprzejmie proszę o umożliwienie przejścia. To zajmie tylko chwilę.',
    'Przepraszam, że znów niepokoję. Czy teraz mogę przejść?',
];
const SLEDZTWO_ODSUWA_SIE = ['No już, już. Przechodź.', 'Dobra, idź.', 'Co? A, jasne.', 'Proszę bardzo.', 'Nie ma sprawy, puszka.',
    'Uważaj, gdzie stawiasz te swoje serwa.', '*odsuwa się bez słowa*', 'Grzeczny droid. Rzadkość. Przechodź.'];
const SLEDZTWO_NIE_ODSUWA = ['Stoję, gdzie chcę.', 'Poczekasz. Rozmawiam.', 'Nakaz masz? Nie? To czekaj.', 'Zaraz, zaraz. Nie widzisz, że zajęte?',
    'Obejdź sobie.', '*udaje, że nie słyszy*'];
const SLEDZTWO_ODSUWA_NIECHETNIE = ['Ech, uparty złom. Dobra, przechodź.', 'No dobra, dobra. Nie stój nade mną.', '*wzdycha ciężko i robi pół kroku w bok*'];
// Gdy to droid stoi w drzwiach: ktoś prosi o przejście, droid uprzejmie ustępuje.
const SLEDZTWO_PROSBA_NPC = ['Przepraszam, przejdę?', 'Ej, puszka, stoisz w drzwiach.', 'Można? Spieszę się.', 'Z drogi, droidzie.',
    'Przepuścisz mnie?'];
const SLEDZTWO_USTEPUJE = ['Oczywiście, przepraszam za utrudnienie.', 'Najmocniej przepraszam. Proszę przechodzić.', 'Proszę bardzo. Moja wina.'];

function sl_kwestia(array $lista): string { return $lista[random_int(0, count($lista) - 1)]; }

function sl_dziennik_o(PDO $db, int $id, int $kiedy, string $rodzaj, string $kto, string $tresc): void
{
    $db->prepare('INSERT INTO sledztwo_dziennik (sledztwo_id, kiedy_utc, rodzaj, kto, tresc) VALUES (?, ?, ?, ?, ?)')
        ->execute([$id, sl_utc($kiedy), $rodzaj, $kto, $tresc]);
}

function sl_klucz_przejscia(array $plan, string $nr): string
{
    return ($plan['budynek_id'] ?? 0) . '|' . $plan['poziom'] . '|' . $nr . '|' . gmdate('YmdH');
}

function sl_przejscie(PDO $db, int $sid, string $klucz): ?string
{
    try {
        $q = $db->prepare('SELECT stan FROM sledztwo_przejscia WHERE sledztwo_id = ? AND klucz = ?');
        $q->execute([$sid, $klucz]);
        $v = $q->fetchColumn();
        return $v === false ? null : (string) $v;
    } catch (PDOException $e) { return 'odsunal'; } // bez migracji 088 nikt nie blokuje drzwi
}

// Ktoś stoi w drzwiach lokalu $nr i nie zgodził się jeszcze przejść? Zwraca kontakt albo null.
function sl_w_drzwiach(PDO $db, array $s, array $pule, array $plan, string $nr): ?array
{
    foreach (sl_kontakty($db, $s, $pule, $plan, null) as $k) if (($k['w_drzwiach'] ?? null) === $nr) return $k;
    return null;
}

// Droid dochodzi do drzwi, w których ktoś stoi. Zwraca [zatrzymany?, dodatkowe sekundy]. Rozmowa trafia do dziennika
// z czasem dojścia do progu (wpisy z przyszłości pokazują się dopiero wtedy).
function sl_prosba_o_przejscie(PDO $db, array $s, array $plan, array $k, int $kiedy): array
{
    $sid = (int) $s['id'];
    $klucz = sl_klucz_przejscia($plan, $k['w_drzwiach']);
    $stan = sl_przejscie($db, $sid, $klucz);
    if ($stan === 'odsunal') return [false, 0];
    $f = sl_formy((string) $k['plec']);
    $prosba = strtr(sl_kwestia($stan === 'odmowil' ? SLEDZTWO_PROSBY_PONOWNE : SLEDZTWO_PROSBY_DROIDA), ['{pan}' => $f['pan']]);
    $kto = "{$k['gatunek']}, {$k['wyglad']} (w drzwiach {$k['w_drzwiach']})";
    sl_dziennik_o($db, $sid, $kiedy, 'rozmowa', 'droid', $prosba);
    // Pierwsza prośba: zwykle się odsuwa; po odmowie druga prośba zawsze skutkuje.
    $odsuwa = $stan === 'odmowil' || random_int(1, 100) <= 80;
    $odp = sl_kwestia($stan === 'odmowil' ? SLEDZTWO_ODSUWA_NIECHETNIE : ($odsuwa ? SLEDZTWO_ODSUWA_SIE : SLEDZTWO_NIE_ODSUWA));
    sl_dziennik_o($db, $sid, $kiedy + 3, 'rozmowa', 'npc', "{$kto}: {$odp}");
    $db->prepare('INSERT INTO sledztwo_przejscia (sledztwo_id, klucz, stan, kiedy_utc) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE stan = VALUES(stan), kiedy_utc = VALUES(kiedy_utc)')
        ->execute([$sid, $klucz, $odsuwa ? 'odsunal' : 'odmowil', sl_utc($kiedy)]);
    return [!$odsuwa, $odsuwa ? random_int(4, 9) : 5];
}

// Strażnik strzeżonego lokalu: deterministyczny, z kartą EotE (archetyp z presetu budynku). Intelekt często niski.
function sl_straznik(array $pule, array $s, array $plan, array $p): array
{
    sl_ziarno(SLEDZTWO_SWIAT, 'straz', (string) $plan['budynek_id'], (string) $plan['poziom'], $p['nr']);
    $archKod = $pule['presety'][$plan['preset']]['straz_archetyp'] ?? 'ochroniarz';
    $arch = $pule['archetypy'][$archKod] ?? $pule['archetypy']['ochroniarz'] ?? reset($pule['archetypy']);
    $g = sl_wazony(array_values(array_filter($pule['populacja'], fn($x) => $x['kod'] !== 'droid')) ?: $pule['populacja'])['kod'];
    $plec = $g === 'droid' ? 'n' : (sl_procent(70) ? 'm' : 'k');
    $karta = sl_karta_eote($pule['gatunki'][$g], $arch, 'slugus', 'Strażnik', $plec, null, '', 0, '');
    $karta['cechy']['intelekt'] = [1, 1, 2, 2, 3][sl_los(0, 4)];
    $int = $karta['cechy']['intelekt'];
    return ['gatunek_kod' => $g, 'plec' => $plec, 'archetyp' => $arch['nazwa'], 'karta' => $karta,
        'wyglad' => 'strażnik przy drzwiach ' . $p['nr'] . ($int <= 1 ? ', wygląda na mało rozgarniętego' : ($int >= 3 ? ', bystre spojrzenie' : ''))];
}

// Kontakty w zasięgu: postacie fabuły (z bazy), przechodnie szumu (wg presetu lokalu, zmieniają się co godzinę)
// i strażnicy przy drzwiach strzeżonych lokali (na korytarzu).
// $xDroida null — wszystkie kontakty piętra (np. kto stoi w drzwiach na trasie). Przechodzień może stać w drzwiach
// lokalu (w_drzwiach = numer); gdy się odsunął (sledztwo_przejscia), stoi obok, w lokalu.
function sl_kontakty(PDO $db, array $s, array $pule, array $plan, ?int $xDroida): array
{
    $kontakty = [];
    $godzina = gmdate('YmdH');
    $q = $db->prepare('SELECT p.id, p.gatunek_kod, p.plec, p.archetyp_kod, a.nazwa AS archetyp FROM sledztwo_postacie p
        LEFT JOIN gen_archetypy a ON a.kod = p.archetyp_kod WHERE p.sledztwo_id = ? AND p.miejsce_id = ? ORDER BY p.id');
    foreach ($plan['pokoje'] as $p) {
        $srodek = sl_srodek($p);
        if ($xDroida !== null && abs($srodek - $xDroida) > SLEDZTWO_ZASIEG) continue;
        $wDrzwiach = false;
        $osoby = [];
        if ($p['miejsce_id']) {
            $q->execute([(int) $s['id'], $p['miejsce_id']]);
            foreach ($q->fetchAll() as $o) $osoby[] = $o + ['postac_id' => (int) $o['id'], 'token' => sl_token((int) $s['seed'], 'p', (string) $o['id'])];
        } elseif ($p['ludzie_max'] > 0) {
            sl_ziarno(SLEDZTWO_SWIAT, 'ludzie', (string) $plan['budynek_id'], (string) $plan['poziom'], $p['nr'], $godzina);
            $ile = sl_los(0, $p['ludzie_max']);
            $bywalcy = array_values(array_filter($pule['archetypy'], fn($a) => in_array($p['rodzaj_kod'], sl_tagi((string) $a['rodzaje_miejsc']), true)));
            for ($k = 0; $k < $ile; $k++) {
                $g = sl_wazony($pule['populacja'])['kod'];
                $a = $bywalcy ? sl_wazony($bywalcy) : null;
                $osoby[] = ['gatunek_kod' => $g, 'plec' => $g === 'droid' ? 'n' : (sl_procent(50) ? 'm' : 'k'), 'archetyp' => $a['nazwa'] ?? null,
                    'archetyp_kod' => $a['kod'] ?? null,
                    'postac_id' => null, 'token' => sl_token((int) $s['seed'], 's', (string) $plan['budynek_id'], (string) $plan['poziom'], $p['nr'], $godzina, (string) $k)];
            }
            // Co trzeci zaludniony lokal: ktoś stoi w samych drzwiach (nie przy strzeżonych — tam jest strażnik).
            $wDrzwiach = $ile > 0 && $p['dostep'] !== 'straz' && !$p['zamkniete'] && sl_procent(33);
        }
        $miejsc = max(1, 3 * $p['szer']);
        $yLok = sl_y_pokoju($p['strona']) + 46;
        foreach ($osoby as $j => $o) {
            $x = $p['x'] + 12 + ($j % $miejsc) * 15;
            $y = $yLok;
            $drzwi = null;
            if ($wDrzwiach && $j === 0) {
                [$dx, $dy] = sl_drzwi_xy($p);
                if (sl_przejscie($db, (int) $s['id'], sl_klucz_przejscia($plan, $p['nr'])) === 'odsunal') {
                    [$x, $y] = [$dx + 16, $p['strona'] === 'gora' ? $dy - 10 : $dy + 10];
                } else {
                    [$x, $y, $drzwi] = [$dx, $dy, $p['nr']];
                }
            }
            $kontakty[] = ['pokoj' => $p['nr'], 'x' => $x, 'y' => $y, 'strona' => $p['strona'],
                'gatunek' => $pule['gatunki'][$o['gatunek_kod']]['nazwa'] ?? $o['gatunek_kod'], 'plec' => $o['plec'],
                'wyglad' => $o['archetyp'] ? mb_strtolower((string) $o['archetyp']) : 'przechodzień',
                'token' => $o['token'], 'postac_id' => $o['postac_id'], 'archetyp_kod' => $o['archetyp_kod'] ?? null] + ($drzwi ? ['w_drzwiach' => $drzwi] : []);
        }
        if ($p['dostep'] === 'straz') {
            $st = sl_straznik($pule, $s, $plan, $p);
            $kontakty[] = ['pokoj' => $p['nr'], 'x' => $srodek + 16, 'y' => $p['strona'] === 'gora' ? SLEDZTWO_GLEB + 9 : SLEDZTWO_GLEB + SLEDZTWO_KOR - 9,
                'strona' => 'korytarz', 'przy' => $p['strona'],
                'gatunek' => $pule['gatunki'][$st['gatunek_kod']]['nazwa'] ?? $st['gatunek_kod'], 'plec' => $st['plec'],
                'wyglad' => $st['wyglad'], 'token' => sl_token((int) $s['seed'], 'g', (string) $plan['budynek_id'], (string) $plan['poziom'], $p['nr']),
                'postac_id' => null, 'straznik' => true];
        }
    }
    usort($kontakty, fn($a, $b) => $a['x'] <=> $b['x']);
    foreach ($kontakty as $i => &$k) $k['kod'] = 'K-' . ($i + 1);
    unset($k);
    return $kontakty;
}

// ---------- dostęp: zamki, nakazy, strażnicy ----------

function sl_ma_nakaz(PDO $db, int $sledztwoId, ?int $bid): bool
{
    $q = $db->prepare("SELECT 1 FROM sledztwo_nakazy WHERE sledztwo_id = ? AND stan = 'wydany' AND (lokalizacja_id IS NULL OR lokalizacja_id = ?) LIMIT 1");
    $q->execute([$sledztwoId, $bid]);
    return (bool) $q->fetchColumn();
}

function sl_przepustka(PDO $db, int $sledztwoId, ?int $bid, int $poziom, string $pokoj): bool
{
    $q = $db->prepare('SELECT 1 FROM sledztwo_straz WHERE sledztwo_id = ? AND lokalizacja_id <=> ? AND poziom = ? AND pokoj = ? AND udana = 1
        AND kiedy_utc > ? LIMIT 1');
    $q->execute([$sledztwoId, $bid, $poziom, $pokoj, sl_utc(sl_teraz() - 86400)]);
    return (bool) $q->fetchColumn();
}

// [czy wolno wejść, komunikat droida]
function sl_dostep(PDO $db, array $s, ?int $bid, int $poziom, array $p): array
{
    $nazwa = "Lokal {$p['nr']} ({$p['rodzaj']})";
    if ($p['zamkniete']) return [false, "{$nazwa} jest zamknięty."];
    if ($p['dostep'] === 'nakaz') {
        return sl_ma_nakaz($db, (int) $s['id'], $bid) ? [true, 'Okazałem nakaz.'] : [false, "{$nazwa}: wstęp tylko z nakazem."];
    }
    if ($p['dostep'] === 'straz') {
        if (sl_ma_nakaz($db, (int) $s['id'], $bid)) return [true, 'Okazałem strażnikowi nakaz.'];
        if (sl_przepustka($db, (int) $s['id'], $bid, $poziom, $p['nr'])) return [true, 'Strażnik mnie pamięta i wpuszcza.'];
        return [false, "{$nazwa}: strażnik przy drzwiach nie wpuszcza bez nakazu. Można spróbować go przekonać."];
    }
    return [true, ''];
}

// Kości EotE: [sukces, przewaga, triumf, rozpacz] na ścianę. z — zdolność, b — biegłość, t — trudność, w — wyzwanie.
const SLEDZTWO_KOSCI = [
    'z' => [[0, 0, 0, 0], [1, 0, 0, 0], [1, 0, 0, 0], [2, 0, 0, 0], [0, 1, 0, 0], [0, 1, 0, 0], [1, 1, 0, 0], [0, 2, 0, 0]],
    'b' => [[0, 0, 0, 0], [1, 0, 0, 0], [1, 0, 0, 0], [2, 0, 0, 0], [2, 0, 0, 0], [0, 1, 0, 0], [1, 1, 0, 0], [1, 1, 0, 0], [1, 1, 0, 0],
        [0, 2, 0, 0], [0, 2, 0, 0], [1, 0, 1, 0]],
    't' => [[0, 0, 0, 0], [-1, 0, 0, 0], [-2, 0, 0, 0], [0, -1, 0, 0], [0, -1, 0, 0], [0, -1, 0, 0], [0, -2, 0, 0], [-1, -1, 0, 0]],
    'w' => [[0, 0, 0, 0], [-1, 0, 0, 0], [-1, 0, 0, 0], [-2, 0, 0, 0], [-2, 0, 0, 0], [0, -1, 0, 0], [0, -1, 0, 0], [-1, -1, 0, 0],
        [-1, -1, 0, 0], [0, -2, 0, 0], [0, -2, 0, 0], [-1, 0, 0, 1]],
];

// Test przeciwstawny EotE: pula = wyższa z (cecha, ranga), z czego niższa to kości ulepszone.
function sl_test_eote(int $cecha, int $ranga, int $cechaObr, int $rangaObr, int $plus = 0): array
{
    $b = min($cecha, $ranga); $z = max($cecha, $ranga) - $b;
    $w = min($cechaObr, $rangaObr); $t = max($cechaObr, $rangaObr) - $w;
    if ($w + $t === 0) $t = 1;
    $t += max(0, $plus);
    $wynik = [0, 0, 0, 0];
    foreach (['z' => $z, 'b' => $b, 't' => $t, 'w' => $w] as $rodzaj => $ile) {
        for ($i = 0; $i < $ile; $i++) {
            $sciana = SLEDZTWO_KOSCI[$rodzaj][random_int(0, count(SLEDZTWO_KOSCI[$rodzaj]) - 1)];
            foreach ($sciana as $k => $v) $wynik[$k] += $v;
        }
    }
    return ['pula' => "{$z} zdolności, {$b} biegłości przeciw {$t} trudności, {$w} wyzwania",
        'sukcesy' => $wynik[0], 'przewagi' => $wynik[1], 'triumf' => $wynik[2], 'rozpacz' => $wynik[3], 'udana' => $wynik[0] > 0];
}

// Karta droida: sledztwo_detektywi.droid_karta_eote albo domyślny tani droid śledczy.
function sl_karta_droida(PDO $db, string $login): array
{
    $q = $db->prepare('SELECT droid_karta_eote FROM sledztwo_detektywi WHERE konto_login = ?');
    $q->execute([$login]);
    $k = json_decode((string) $q->fetchColumn(), true);
    if (is_array($k) && isset($k['cechy'])) return $k;
    return ['cechy' => ['krzepa' => 1, 'zwinnosc' => 1, 'intelekt' => 3, 'spryt' => 2, 'wola' => 2, 'prezencja' => 1],
        'umiejetnosci' => ['oszustwo' => ['ranga' => 1], 'urok' => ['ranga' => 1], 'zastraszanie' => ['ranga' => 1],
            'percepcja' => ['ranga' => 2], 'czujnosc' => ['ranga' => 2], 'komputery' => ['ranga' => 2],
            'negocjacje' => ['ranga' => 1], 'przywodztwo' => ['ranga' => 1]]];
}

// Podejścia do strażnika: [cecha i umiejętność droida, cecha i umiejętność strażnika, nazwa].
// Blef (Oszustwo) idzie przeciw Intelektowi i Czujności — strażnika z niskim Intelektem łatwo nabrać.
const SLEDZTWO_PODEJSCIA = [
    'oszustwo' => ['spryt', 'oszustwo', 'intelekt', 'czujnosc', 'blef (Oszustwo)'],
    'urok' => ['prezencja', 'urok', 'prezencja', 'opanowanie', 'Urok'],
    'zastraszanie' => ['wola', 'zastraszanie', 'wola', 'dyscyplina', 'Zastraszanie'],
];

function sl_straz_proba(PDO $db, array $s, string $pokoj, string $podejscie): array
{
    if (!isset(SLEDZTWO_PODEJSCIA[$podejscie])) throw new SledztwoBlad('Nieznany sposób.');
    $pule = sl_pule($db);
    $d = sl_rozlicz_droida($db, $s, $pule);
    if (!$d || $d['stan'] === 'w_drodze') throw new SledztwoBlad('Droid jest w drodze.');
    $budynki = sl_budynki($db, $s);
    $bid = $d['lokalizacja_id'] !== null ? (int) $d['lokalizacja_id'] : null;
    $plan = sl_plan($db, $s, $pule, $bid !== null ? ($budynki[$bid] ?? null) : null, (int) $d['poziom']);
    $p = null;
    foreach ($plan['pokoje'] as $x) if ($x['nr'] === $pokoj) { $p = $x; break; }
    if (!$p || $p['dostep'] !== 'straz') throw new SledztwoBlad('Przy tym lokalu nie ma strażnika.');
    if (abs(sl_srodek($p) - sl_x_droida($plan, (string) $d['pokoj'])) > SLEDZTWO_ZASIEG) throw new SledztwoBlad('Strażnik jest poza zasięgiem droida.');
    [$ok] = sl_dostep($db, $s, $bid, (int) $d['poziom'], $p);
    if ($ok) {
        $ruch = sl_rozkaz_ruchu($db, $s, $bid, (int) $d['poziom'], $pokoj);
        return ['udana' => true, 'juz' => true, 'czas_s' => $ruch['czas_s']];
    }
    $teraz = sl_teraz();
    $q = $db->prepare('SELECT podejscie, udana, rozpacz, kiedy_utc FROM sledztwo_straz WHERE sledztwo_id = ? AND lokalizacja_id <=> ? AND poziom = ?
        AND pokoj = ? AND kiedy_utc > ? ORDER BY id DESC');
    $q->execute([(int) $s['id'], $bid, (int) $d['poziom'], $pokoj, sl_utc($teraz - 86400)]);
    $proby = $q->fetchAll();
    $godzina = array_filter($proby, fn($x) => !$x['udana'] && (sl_z_utc($x['kiedy_utc']) ?? 0) > $teraz - 3600);
    if (array_filter($proby, fn($x) => (int) $x['rozpacz'] > 0)) throw new SledztwoBlad('Strażnik wezwał przełożonego — droid ma tu zakaz wstępu do jutra.');
    if (count($godzina) >= 2) throw new SledztwoBlad('Strażnik nie chce już rozmawiać. Wróć za godzinę.');
    if (array_filter($godzina, fn($x) => $x['podejscie'] === $podejscie)) throw new SledztwoBlad('Strażnik już to słyszał. Spróbuj inaczej albo wróć za godzinę.');

    [$cD, $uD, $cS, $uS, $nazwa] = SLEDZTWO_PODEJSCIA[$podejscie];
    $droid = sl_karta_droida($db, (string) $s['konto_login']);
    $st = sl_straznik($pule, $s, $plan, $p);
    $test = sl_test_eote((int) ($droid['cechy'][$cD] ?? 1), (int) ($droid['umiejetnosci'][$uD]['ranga'] ?? 0),
        (int) ($st['karta']['cechy'][$cS] ?? 2), (int) (((array) $st['karta']['umiejetnosci'])[$uS]['ranga'] ?? 0));
    $db->prepare('INSERT INTO sledztwo_straz (sledztwo_id, lokalizacja_id, poziom, pokoj, podejscie, sukcesy, przewagi, triumf, rozpacz, udana, kiedy_utc)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int) $s['id'], $bid, (int) $d['poziom'], $pokoj, $podejscie, $test['sukcesy'], $test['przewagi'], $test['triumf'],
            $test['rozpacz'], $test['udana'] ? 1 : 0, sl_utc($teraz)]);
    $opis = "{$nazwa}: {$test['pula']} → sukcesy netto {$test['sukcesy']}, przewagi {$test['przewagi']}"
        . ($test['triumf'] ? ', TRIUMF' : '') . ($test['rozpacz'] ? ', ROZPACZ' : '') . '.';
    sl_dziennik($db, (int) $s['id'], 'polecenie', 'gracz', "Przekonaj strażnika lokalu {$pokoj} — {$nazwa}.");
    sl_dziennik($db, (int) $s['id'], 'meldunek', 'droid', $test['udana']
        ? "Strażnik lokalu {$pokoj} mnie wpuszcza. ({$opis})"
        : ($test['rozpacz'] ? "Strażnik lokalu {$pokoj} wzywa przełożonego. Zakaz wstępu do jutra. ({$opis})"
            : "Strażnik lokalu {$pokoj} odmawia. ({$opis})"));
    $wynik = ['udana' => $test['udana'], 'test' => $test, 'opis' => $opis];
    if ($test['udana']) $wynik['czas_s'] = sl_rozkaz_ruchu($db, $s, $bid, (int) $d['poziom'], $pokoj)['czas_s'];
    return $wynik;
}

// Czas podróży w sekundach między pozycjami [budynek_id|null, poziom, pokoj].
function sl_czas_drogi(array $budynki, array $z, array $do): int
{
    [$bz, $pz, $kz] = $z;
    [$bd, $pd, $kd] = $do;
    if ($bz === $bd && $pz === $pd) return $kz === $kd ? 0 : 30;
    $t = $bz !== $bd ? 20.0 : 0.0; // wyjście z budynku — tylko przy zmianie budynku
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
    $db->prepare('INSERT INTO sledztwo_dziennik (sledztwo_id, kiedy_utc, rodzaj, kto, tresc) VALUES (?, ?, ?, ?, ?)')
        ->execute([$id, sl_utc(sl_teraz()), $rodzaj, $kto, $tresc]);
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
        } else {
            [$wolno, $powod] = sl_dostep($db, $s, $bid, $poziom, $lokal);
            if (!$wolno) {
                $meldunek = "{$miejsce}: {$powod} Czekam na korytarzu przed drzwiami {$lokal['drzwi']}.";
                $pokoj = 'korytarz';
            } else {
                // Meldunek wygląda tak samo w lokalu fabularnym i w szumie — o fabule świadczą dopiero ludzie i ślady.
                $opis = (string) ($lokal['opis'] ?: $lokal['opis_szumu']);
                $meldunek = trim("{$powod} {$miejsce}, lokal {$pokoj}: {$lokal['rodzaj']}." . ($opis !== '' ? ' ' . $opis : ''));
                if ($bid !== null && !$lokal['miejsce_id']) {
                    $db->prepare('INSERT IGNORE INTO sledztwo_szum (sledztwo_id, lokalizacja_id, poziom, pokoj, rodzaj_kod, opis) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([(int) $s['id'], $bid, $poziom, $pokoj, $lokal['lokal'], $opis]);
                }
            }
        }
        // Dokładne miejsce: cel z rozkazu; odprawiony od drzwi — na korytarzu przed nimi.
        $poz = $pokoj === (string) $d['cel_pokoj'] ? sl_pozycja($db, $s, $d, $plan, true)
            : ($lokal ? [...sl_przed_drzwiami($lokal), 'korytarz'] : sl_pozycja($db, $s, ['pokoj' => 'korytarz'] + $d, $plan));
        try {
            $z = $db->prepare('SELECT zatrzymanie FROM sledztwo_pozycje WHERE sledztwo_id = ?');
            $z->execute([(int) $s['id']]);
            $zatrzymanie = $z->fetchColumn();
            if ($zatrzymanie) $meldunek = $zatrzymanie;
        } catch (PDOException $e) { /* brak migracji 088 */ }
        sl_zapisz_pozycje($db, (int) $s['id'], $poz);
        $kontakty = sl_kontakty($db, $s, $pule, $plan, (int) $poz[0]);
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

// Rozkaz ruchu do punktu planu ($x, $y) albo do lokalu ($pokoj; wtedy na jego środek). Droid idzie przez drzwi
// i korytarz (nie przez ściany) z prędkością 5 km/h; na inne piętro / do innego budynku — przez windę.
// Nowy rozkaz w drodze: przed połową trasy droid zawraca ze startu, po połowie rusza z poprzedniego celu.
// Ktoś w drzwiach na trasie: droid uprzejmie prosi o przejście; przy odmowie staje przed drzwiami.
function sl_rozkaz_ruchu(PDO $db, array $s, ?int $bid, int $poziom, string $pokoj, ?int $x = null, ?int $y = null): array
{
    if (!in_array($s['stan'], SLEDZTWO_STANY_RUCHU, true)) throw new SledztwoBlad('Droid nie przyjmuje teraz rozkazów.');
    $pule = sl_pule($db);
    $budynki = sl_budynki($db, $s);
    if ($budynki && ($bid === null || !isset($budynki[$bid]))) throw new SledztwoBlad('Ten budynek jest poza rejonem sprawy.');
    if (!$budynki) $bid = null;
    if ($poziom < 1 || $poziom > SLEDZTWO_POZIOM_MAX) throw new SledztwoBlad('Nie ma takiego poziomu (1–' . SLEDZTWO_POZIOM_MAX . ').');
    $planCel = sl_plan($db, $s, $pule, $bid !== null ? $budynki[$bid] : null, $poziom);
    if ($x !== null && $y !== null) {
        $cel = sl_punkt($planCel, $x, $y);
    } else {
        if ($pokoj !== 'korytarz' && !sl_lokal($planCel, $pokoj)) throw new SledztwoBlad("Na tym poziomie nie ma lokalu {$pokoj}.");
        [$cx, $cy] = $pokoj === 'korytarz' ? [12, SLEDZTWO_GLEB + intdiv(SLEDZTWO_KOR, 2)] : sl_srodek_xy($planCel, $pokoj);
        $cel = [$cx, $cy, $pokoj];
    }

    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT * FROM sledztwo_droid WHERE sledztwo_id = ? FOR UPDATE');
        $q->execute([(int) $s['id']]);
        $d = $q->fetch();
        if (!$d) throw new SledztwoBlad('Droid nie ma przydziału.');
        $teraz = sl_teraz();
        // Nowy rozkaz w drodze: droid rusza z miejsca, w którym właśnie jest (punkt na trasie, w windzie — przy windzie).
        if ($d['stan'] === 'w_drodze') {
            [$sb, $sp, $start, $planStart, $wWindzie] = array_pad(sl_pozycja_w_drodze($db, $s, $d, $pule, $budynki), 5, false);
            if ($wWindzie) throw new SledztwoBlad('Droid jedzie windą — drzwi otworzą się dopiero na wybranym poziomie.');
        } else {
            $sb = $d['lokalizacja_id'] !== null ? (int) $d['lokalizacja_id'] : null;
            $sp = (int) $d['poziom'];
            $planStart = $sb === $bid && $sp === $poziom ? $planCel : sl_plan($db, $s, $pule, $sb !== null ? ($budynki[$sb] ?? null) : null, $sp);
            $start = sl_pozycja($db, $s, $d, $planStart);
        }

        // Odcinki trasy: [plan, trasa, sekundy od wyjścia do początku odcinka].
        if ($sb === $bid && $sp === $poziom) {
            $odcinki = [[$planCel, sl_trasa($planCel, $start, $cel), 0.0]];
            $winda = 0;
        } else {
            $w = sl_winda_xy();
            $t1 = sl_trasa($planStart, $start, [$w[0], $w[1], 'korytarz']);
            $winda = sl_czas_drogi($budynki, [$sb, $sp, 'korytarz'], [$bid, $poziom, 'korytarz']);
            $odcinki = [[$planStart, $t1, 0.0], [$planCel, sl_trasa($planCel, [$w[0], $w[1], 'korytarz'], $cel), sl_sekundy_marszu($t1['dl']) + $winda]];
        }

        // Lokal celu zamknięty / tylko z nakazem / strzeżony: droid podchodzi pod drzwi i tam czeka.
        $zatrzymanie = null;
        $lokalCelu = $cel[2] !== 'korytarz' && $cel[2] !== $start[2] ? sl_lokal($planCel, $cel[2]) : null;
        if ($lokalCelu) {
            [$wolno, $powod] = sl_dostep($db, $s, $bid, $poziom, $lokalCelu);
            if (!$wolno) {
                $cel = [...sl_przed_drzwiami($lokalCelu), 'korytarz'];
                $odcinki[count($odcinki) - 1][1] = sl_trasa($planCel, count($odcinki) > 1 ? [...sl_winda_xy(), 'korytarz'] : $start, $cel);
                $zatrzymanie = "{$powod} Czekam na korytarzu przed drzwiami {$lokalCelu['drzwi']}.";
            }
        }

        // Drzwi po drodze: ktoś w nich stoi? Prośba; przy odmowie droid kończy trasę przed progiem.
        $dodatek = 0;
        $czas = 0.0;
        foreach ($odcinki as $i => [$plan, $trasa, $poczatek]) {
            $czas = $poczatek + sl_sekundy_marszu($trasa['dl']) + $dodatek;
            foreach ($trasa['drzwi'] as $dr) {
                $k = sl_w_drzwiach($db, $s, $pule, $plan, $dr['lokal']['nr']);
                if (!$k) continue;
                $prog = $poczatek + sl_sekundy_marszu(max(0, $dr['odl'] - 12)) + $dodatek;
                [$stop, $ile] = sl_prosba_o_przejscie($db, $s, $plan, $k, $teraz + (int) round($prog));
                $dodatek += $ile;
                $czas += $ile;
                if (!$stop) continue;
                // Staje po swojej stronie progu: wychodząc — w lokalu, wchodząc — na korytarzu.
                [$px, $py] = $dr['wyjscie'] ? sl_za_drzwiami($dr['lokal']) : sl_przed_drzwiami($dr['lokal']);
                $cel = [$px, $py, $dr['wyjscie'] ? $dr['lokal']['nr'] : 'korytarz'];
                if ($plan !== $planCel) { $bid = $sb; $poziom = $sp; $planCel = $plan; }
                $czas = $prog + $ile;
                $zatrzymanie = "Stoję przed drzwiami {$dr['lokal']['drzwi']} (lokal {$dr['lokal']['nr']}). {$k['gatunek']} stoi w przejściu "
                    . 'i nie chce się odsunąć. Mogę poprosić ponownie — wystarczy wydać ten sam rozkaz.';
                break 2;
            }
        }
        $czas = max(1, (int) round($czas));
        $pokoj = $cel[2];

        $db->prepare("UPDATE sledztwo_droid SET lokalizacja_id = ?, poziom = ?, pokoj = ?, cel_lokalizacja_id = ?, cel_poziom = ?, cel_pokoj = ?,
            wyruszyl_utc = ?, dotrze_utc = ?, stan = 'w_drodze' WHERE sledztwo_id = ?")
            ->execute([$sb, $sp, $start[2], $bid, $poziom, $pokoj, sl_utc($teraz), sl_utc($teraz + $czas), (int) $s['id']]);
        sl_zapisz_pozycje($db, (int) $s['id'], $start, $cel, $zatrzymanie);
        $opisCelu = $planCel['budynek'] . ", poziom {$poziom}, " . ($pokoj === 'korytarz' ? 'korytarz' : "lokal {$pokoj}");
        $metry = 0.0;
        foreach ($odcinki as [, $t]) $metry += $t['dl'] / SLEDZTWO_J_NA_M;
        sl_dziennik($db, (int) $s['id'], 'polecenie', 'gracz', "Idź: {$opisCelu}.");
        sl_dziennik($db, (int) $s['id'], 'meldunek', 'droid', 'Przyjąłem. Trasa ' . (int) round($metry) . ' m' . ($winda ? ' i winda' : '')
            . ', szacowany czas: ' . sl_czas_tekst($czas) . '.');
        $db->commit();
        return ['czas_s' => $czas, 'dotrze_utc' => sl_utc($teraz + $czas), 'zatrzymany' => $zatrzymanie !== null];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// Punkt na łamanej trasy po przejściu ułamka $u długości; [x, y, lokal] w granicach ścian.
function sl_punkt_na_trasie(array $plan, array $pkt, float $u): array
{
    $u = max(0.0, min(1.0, $u));
    $dl = [0.0];
    for ($i = 1, $n = count($pkt); $i < $n; $i++) $dl[] = $dl[$i - 1] + hypot($pkt[$i][0] - $pkt[$i - 1][0], $pkt[$i][1] - $pkt[$i - 1][1]);
    $cel = end($dl) * $u;
    for ($i = 1, $n = count($pkt); $i < $n; $i++) {
        if ($dl[$i] < $cel) continue;
        $k = ($cel - $dl[$i - 1]) / max(0.0001, $dl[$i] - $dl[$i - 1]);
        return sl_punkt($plan, (int) round($pkt[$i - 1][0] + ($pkt[$i][0] - $pkt[$i - 1][0]) * $k), (int) round($pkt[$i - 1][1] + ($pkt[$i][1] - $pkt[$i - 1][1]) * $k));
    }
    $o = end($pkt);
    return sl_punkt($plan, (int) round($o[0]), (int) round($o[1]));
}

// Gdzie droid jest teraz, w drodze: [budynek, poziom, [x, y, lokal], plan]. Ten sam piętro — punkt na trasie wg czasu;
// inne piętro — marsz do windy, jazda (pierwsza połowa: przy windzie startu, druga: przy windzie celu), marsz od windy.
function sl_pozycja_w_drodze(PDO $db, array $s, array $d, array $pule, array $budynki): array
{
    $teraz = sl_teraz();
    $od = sl_z_utc($d['wyruszyl_utc']) ?? $teraz;
    $do = sl_z_utc($d['dotrze_utc']) ?? $teraz;
    $b0 = $d['lokalizacja_id'] !== null ? (int) $d['lokalizacja_id'] : null;
    $b1 = $d['cel_lokalizacja_id'] !== null ? (int) $d['cel_lokalizacja_id'] : null;
    $p0 = (int) $d['poziom'];
    $p1 = (int) $d['cel_poziom'];
    $plan0 = sl_plan($db, $s, $pule, $b0 !== null ? ($budynki[$b0] ?? null) : null, $p0);
    $start = sl_pozycja($db, $s, $d, $plan0);
    $minelo = max(0, $teraz - $od);
    $calosc = max(1, $do - $od);
    if ($b0 === $b1 && $p0 === $p1) {
        $t = sl_trasa($plan0, $start, sl_pozycja($db, $s, $d, $plan0, true));
        return [$b0, $p0, sl_punkt_na_trasie($plan0, $t['punkty'], $minelo / $calosc), $plan0];
    }
    $plan1 = sl_plan($db, $s, $pule, $b1 !== null ? ($budynki[$b1] ?? null) : null, $p1);
    $w = sl_winda_xy();
    $winda = [$w[0], $w[1], 'korytarz'];
    $t1 = sl_trasa($plan0, $start, $winda);
    $t2 = sl_trasa($plan1, $winda, sl_pozycja($db, $s, $d, $plan1, true));
    $s1 = sl_sekundy_marszu($t1['dl']);
    $s2 = sl_sekundy_marszu($t2['dl']);
    if ($minelo < $s1) return [$b0, $p0, sl_punkt_na_trasie($plan0, $t1['punkty'], $minelo / max(1, $s1)), $plan0];
    $wysiada = max($s1, $calosc - $s2);
    if ($minelo < $wysiada) {
        return ($minelo - $s1) * 2 < ($wysiada - $s1) ? [$b0, $p0, $winda, $plan0, true] : [$b1, $p1, $winda, $plan1, true];
    }
    return [$b1, $p1, sl_punkt_na_trasie($plan1, $t2['punkty'], ($minelo - $wysiada) / max(1, $s2)), $plan1];
}

// Trasa dla animacji na planie piętra, na którym droid jest (start): punkty i ułamek czasu, w którym droid je kończy
// (na inne piętro — do windy). Null, gdy nie da się jej odtworzyć.
function sl_trasa_animacji(PDO $db, array $s, array $d, array $plan, array $budynki): ?array
{
    $od = sl_z_utc($d['wyruszyl_utc']);
    $do = sl_z_utc($d['dotrze_utc']);
    if (!$od || !$do || $do <= $od) return null;
    $start = sl_pozycja($db, $s, $d, $plan);
    $tenSam = ($d['cel_lokalizacja_id'] !== null ? (int) $d['cel_lokalizacja_id'] : null) === ($d['lokalizacja_id'] !== null ? (int) $d['lokalizacja_id'] : null)
        && (int) $d['cel_poziom'] === (int) $d['poziom'];
    if ($tenSam) {
        $cel = sl_pozycja($db, $s, $d, $plan, true);
    } else {
        $w = sl_winda_xy();
        $cel = [$w[0], $w[1], 'korytarz'];
    }
    $t = sl_trasa($plan, $start, $cel);
    return ['punkty' => $t['punkty'], 'do' => $tenSam ? 1 : min(1, sl_sekundy_marszu($t['dl']) / ($do - $od))];
}

function sl_czas_tekst(int $s): string
{
    $g = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    return $g ? "{$g} h {$m} min" : ($m ? sprintf('%d min %02d s', $m, $s % 60) : "{$s} s");
}

// Droid stoi w drzwiach dłużej niż SLEDZTWO_STANIE_W_DRZWIACH_S, a lokal jest zaludniony: ktoś prosi o przejście,
// droid uprzejmie ustępuje (przesuwa się o 1,5 m wzdłuż ściany po swojej stronie progu).
function sl_droid_ustepuje(PDO $db, array $s, array $d, array $plan): void
{
    if ($d['stan'] !== 'czeka') return;
    try {
        $q = $db->prepare('SELECT x, y, pokoj, stoi_od_utc FROM sledztwo_pozycje WHERE sledztwo_id = ?');
        $q->execute([(int) $s['id']]);
        $r = $q->fetch();
    } catch (PDOException $e) { return; }
    if (!$r || $r['pokoj'] !== (string) $d['pokoj'] || !$r['stoi_od_utc']) return;
    $od = (int) sl_z_utc($r['stoi_od_utc']);
    if (sl_teraz() - $od < SLEDZTWO_STANIE_W_DRZWIACH_S) return;
    foreach ($plan['pokoje'] as $p) {
        [$dx, $dy] = sl_drzwi_xy($p);
        if (abs((int) $r['x'] - $dx) > 10 || abs((int) $r['y'] - $dy) > 10) continue;
        if (!$p['ludzie_max'] && !$p['miejsce_id']) return;
        $kiedy = $od + SLEDZTWO_STANIE_W_DRZWIACH_S;
        sl_dziennik_o($db, (int) $s['id'], $kiedy, 'rozmowa', 'npc', 'Ktoś przy drzwiach ' . $p['drzwi'] . ': ' . sl_kwestia(SLEDZTWO_PROSBA_NPC));
        sl_dziennik_o($db, (int) $s['id'], $kiedy + 2, 'rozmowa', 'droid', sl_kwestia(SLEDZTWO_USTEPUJE));
        $wLokalu = (string) $d['pokoj'] === $p['nr'];
        $y = $wLokalu ? sl_za_drzwiami($p)[1] : sl_przed_drzwiami($p)[1];
        $x = $dx + ($dx + 30 < $p['x'] + $p['szer'] * SLEDZTWO_POKOJ_J || !$wLokalu ? 18 : -18);
        sl_zapisz_pozycje($db, (int) $s['id'], sl_punkt($plan, $x, $y));
        return;
    }
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
    $wDrodze = $d['stan'] === 'w_drodze';
    if (!$wDrodze) sl_droid_ustepuje($db, $s, $d, $plan);
    $poz = sl_pozycja($db, $s, $d, $plan);
    $xDroida = (int) $poz[0];
    // Kontakty liczone od miejsca, z którego droid wyszedł (w drodze — przybliżenie).
    $kontakty = sl_kontakty($db, $s, $pule, $plan, $xDroida);
    $plan['dl'] = sl_dl_planu($plan);
    $plan['terminale'] = array_map(fn($t) => array_diff_key($t, ['podejscie' => 1]) + ['podejscie_x' => $t['podejscie'][0], 'podejscie_y' => $t['podejscie'][1]],
        sl_terminale($plan));
    // Graczowi nie pokazujemy, który lokal jest fabularny.
    $plan['pokoje'] = array_map(fn($p) => array_diff_key($p, ['miejsce_id' => 1, 'nazwa' => 1, 'opis' => 1, 'opis_szumu' => 1, 'ludzie_max' => 1, 'rodzaj_kod' => 1]), $plan['pokoje']);

    $droid = ['budynek_id' => $bid, 'budynek' => $plan['budynek'], 'poziom' => (int) $d['poziom'], 'pokoj' => (string) $d['pokoj'],
        'stan' => $d['stan'], 'x' => $xDroida, 'y' => (int) $poz[1]];
    if ($wDrodze) {
        $od = sl_z_utc($d['wyruszyl_utc']) ?? $teraz;
        $do = sl_z_utc($d['dotrze_utc']) ?? $teraz;
        $cb = $d['cel_lokalizacja_id'] !== null ? (int) $d['cel_lokalizacja_id'] : null;
        $droid['cel'] = ['budynek_id' => $cb, 'budynek' => $cb !== null ? ($budynki[$cb]['nazwa'] ?? '') : 'Kompleks',
            'poziom' => (int) $d['cel_poziom'], 'pokoj' => (string) $d['cel_pokoj']];
        $droid['zostalo_s'] = max(0, $do - $teraz);
        $droid['postep'] = $do > $od ? min(1, max(0, ($teraz - $od) / ($do - $od))) : 1;
        $droid['trasa'] = sl_trasa_animacji($db, $s, $d, $plan, $budynki);
    }
    // Wpisy z przyszłości (rozmowa w drzwiach po drodze) pokazują się dopiero, gdy droid tam dojdzie.
    $q = $db->prepare("SELECT kiedy_utc, rodzaj, kto, tresc FROM sledztwo_dziennik WHERE sledztwo_id = ? AND rodzaj <> 'system' AND kiedy_utc <= ?
        ORDER BY kiedy_utc DESC, id DESC LIMIT 12");
    $q->execute([(int) $s['id'], sl_utc($teraz)]);
    $rozmowa = null;
    try {
        $r = sl_rozmowa_aktywna($db, (int) $s['id']);
        if ($r) $rozmowa = ['id' => (int) $r['id'], 'kontakt' => $r['kontakt'], 'stan' => $r['stan']];
    } catch (PDOException $e) { /* brak migracji 085 — bez rozmów */ }
    $kontakty = array_map(fn($k) => array_diff_key($k, ['postac_id' => 1]), $kontakty);
    return ['droid' => $droid, 'plan' => $plan, 'kontakty' => $kontakty, 'zasieg' => SLEDZTWO_ZASIEG, 'rozmowa' => $rozmowa,
        'budynki' => array_values(array_map(fn($b) => ['id' => $b['id'], 'nazwa' => $b['nazwa'], 'rodzaj' => $b['rodzaj_kod']], $budynki)),
        'poziom_max' => SLEDZTWO_POZIOM_MAX, 'meldunki' => $q->fetchAll(), 'teraz' => $teraz, 'nakazy' => sl_nakazy($db, $s, $budynki),
        'namiary' => sl_namiary_lista($db, $s)]
        + sl_skan_przedmioty($db, $s, $d);
}

// Przedmioty w lokalu i ekwipunek droida (pusto bez migracji 087).
function sl_skan_przedmioty(PDO $db, array $s, array $d): array
{
    try {
        sl_przedmioty_zapewnij($db, $s);
        sl_teczka_zapewnij($db, $s);
        return ['przedmioty' => sl_przedmioty_tutaj($db, $s, $d), 'ekwipunek' => sl_ekwipunek($db, $s), 'przedmioty_dostepne' => true];
    } catch (PDOException $e) {
        return ['przedmioty' => [], 'ekwipunek' => [], 'przedmioty_dostepne' => false];
    }
}

// Nakazy sprawy dla ekranu DT-4 (budynek NULL = cały kompleks).
function sl_nakazy(PDO $db, array $s, array $budynki): array
{
    try {
        $q = $db->prepare('SELECT id, lokalizacja_id, stan, decyzja, zlozono_utc FROM sledztwo_nakazy WHERE sledztwo_id = ? ORDER BY id DESC LIMIT 20');
        $q->execute([(int) $s['id']]);
    } catch (PDOException $e) { return []; }
    return array_map(fn($n) => ['id' => (int) $n['id'], 'lokalizacja_id' => $n['lokalizacja_id'] !== null ? (int) $n['lokalizacja_id'] : null,
        'budynek' => $n['lokalizacja_id'] !== null ? ($budynki[(int) $n['lokalizacja_id']]['nazwa'] ?? 'budynek') : 'cały kompleks',
        'stan' => $n['stan'], 'decyzja' => $n['decyzja'], 'zlozono_utc' => $n['zlozono_utc']], $q->fetchAll());
}

function sl_nakaz_wniosek(PDO $db, array $s, ?int $bid, string $uzasadnienie): void
{
    $uzasadnienie = trim(mb_substr($uzasadnienie, 0, 1000));
    if (mb_strlen($uzasadnienie) < 10) throw new SledztwoBlad('Uzasadnij wniosek (co najmniej 10 znaków).');
    $budynki = sl_budynki($db, $s);
    if ($bid !== null && !isset($budynki[$bid])) throw new SledztwoBlad('Ten budynek jest poza rejonem sprawy.');
    $q = $db->prepare("SELECT stan FROM sledztwo_nakazy WHERE sledztwo_id = ? AND lokalizacja_id <=> ? AND stan IN ('wniosek','wydany') LIMIT 1");
    $q->execute([(int) $s['id'], $bid]);
    $jest = $q->fetchColumn();
    if ($jest === 'wydany') throw new SledztwoBlad('Nakaz na ten budynek jest już wydany.');
    if ($jest === 'wniosek') throw new SledztwoBlad('Wniosek na ten budynek czeka już na decyzję.');
    $db->prepare('INSERT INTO sledztwo_nakazy (sledztwo_id, lokalizacja_id, uzasadnienie, zlozono_utc) VALUES (?, ?, ?, ?)')
        ->execute([(int) $s['id'], $bid, $uzasadnienie, sl_utc(sl_teraz())]);
    $gdzie = $bid !== null ? $budynki[$bid]['nazwa'] : 'cały kompleks';
    sl_dziennik($db, (int) $s['id'], 'polecenie', 'gracz', "Wniosek o nakaz: {$gdzie}. Uzasadnienie: {$uzasadnienie}");
}

// ---------- przedmioty, komputery, terminale (migracja 087) ----------

// Lokale, w których terminal jest publiczny (każdy może się podłączyć; dekodowanie zostawia ślad).
const SLEDZTWO_TERMINALE_PUBLICZNE = ['recepcja', 'hol', 'terminal_odpraw', 'poczekalnia', 'przejscie', 'hol_kasyna',
    'kontrola_wejscia', 'galeria'];
const SLEDZTWO_HOLONET = [
    'Huttowie podnoszą opłaty za korzystanie z wind towarowych na poziomach 20–40.',
    'Imperialny patrol widziany nad Sektorem Portowym. Kapitanat: „rutynowa kontrola”.',
    'Pożar w rafinerii przyprawy opanowany. Trzech rannych, przyczyna nieznana.',
    'Wyścigi śmigaczy na niższych poziomach odwołane po interwencji ochrony Huttów.',
    'Kurs kredytu republikańskiego znów spada; kantory przyjmują tylko wartość Imperium.',
    'Zaginął transport części do hangarów Corellian Sector. Nagroda za informacje.',
    'Awaria zasilania na poziomie 12: windy stoją, oświetlenie awaryjne do odwołania.',
    'Kasyno „Ostatnia Szansa” ogłasza turniej sabaka. Wpisowe: 500 kredytów.',
    'Ostrzeżenie kapitanatu: fałszywe identyfikatory w obiegu. Sprawdzajcie kontrahentów.',
    'Łowcy nagród proszeni o rejestrację w Biurze Zleceń. Nowe stawki od pierwszego cyklu.',
];
const SLEDZTWO_OGLOSZENIA = [
    'Pokój do wynajęcia, poziom 9, tanio, bez pytań.', 'Kupię części do hipernapędu. Gotówka.',
    'Naprawa droidów — szybko, dyskretnie, z gwarancją do wyjścia z warsztatu.', 'Zgubiono holozdjęcie. Dla znalazcy nagroda.',
    'Szukam pilota z własnym statkiem. Kurs jednorazowy.', 'Lekcje huttese dla przyjezdnych.',
];

function sl_pliki(array ...$pary): string
{
    return json_encode(array_map(fn($p) => ['nazwa' => $p[0], 'tresc' => $p[1]], array_values(array_filter($pary, fn($p) => $p[1] !== ''))),
        JSON_UNESCAPED_UNICODE);
}

// Przedmioty sprawy na start (raz; także dla spraw sprzed migracji 087). Treść ze szkieletu sprawy — dopóki model
// Autor jej nie napisze: fragmenty, z których gracz łączy kropki (wiadomości, kalendarz, kontakty, notatki).
function sl_przedmioty_zapewnij(PDO $db, array $s): void
{
    $q = $db->prepare('SELECT COUNT(*) FROM sledztwo_przedmioty WHERE sledztwo_id = ? AND miejsce_id IS NOT NULL');
    $q->execute([(int) $s['id']]);
    if ((int) $q->fetchColumn() > 0) return;
    $q = $db->prepare('SELECT * FROM sledztwo_miejsca WHERE sledztwo_id = ? ORDER BY id');
    $q->execute([(int) $s['id']]);
    $miejsca = $q->fetchAll();
    if (!$miejsca) return;
    $mf = [];
    foreach ($miejsca as $m) $mf[(int) $m['faza_nr']] ??= $m;
    $q = $db->prepare('SELECT * FROM sledztwo_postacie WHERE sledztwo_id = ? AND kod IS NOT NULL');
    $q->execute([(int) $s['id']]);
    $os = [];
    foreach ($q->fetchAll() as $p) $os[$p['kod']] = $p;
    if (!isset($os['A'])) return;
    $q = $db->prepare('SELECT faza_nr, pozycja, tagi FROM sledztwo_fazy WHERE sledztwo_id = ? ORDER BY pozycja');
    $q->execute([(int) $s['id']]);
    $fazy = $q->fetchAll();
    $poz = [];
    foreach ($fazy as $f) $poz[(int) $f['faza_nr']] = (int) $f['pozycja'];
    $tagi = $fazy ? sl_tagi((string) end($fazy)['tagi']) : [];
    $q = $db->prepare('SELECT r.rola_b, l.nazwa AS skupisko FROM sledztwa s JOIN sledztwo_relacje r ON r.id = s.relacja_id
        LEFT JOIN lokalizacje l ON l.id = s.skupisko_id WHERE s.id = ?');
    $q->execute([(int) $s['id']]);
    $sp = $q->fetch() ?: ['rola_b' => 'znajomy', 'skupisko' => 'kompleksu'];

    sl_ziarno((int) $s['seed'], 'przedmioty');
    $imie = fn(?array $p) => $p ? trim($p['imie'] . ' ' . ($p['nazwisko'] ?? '')) : '';
    $ksywa = fn(?array $p) => $p ? ($p['przydomek'] ?: $p['imie']) : '';
    $baza = sl_z_utc($s['utworzono_utc'] ?? null) ?? sl_teraz();
    $dni = fn(int $faza) => max(1, 13 - ($poz[$faza] ?? 6));
    $kiedy = fn(int $d) => sl_data_gry($baza - $d * 86400 - sl_los(1800, 60000));
    $dzien = fn(int $d) => sl_data_gry($baza - $d * 86400, false);
    $godz = fn() => sprintf('%02d:%02d', sl_los(0, 23), sl_los(0, 59));
    $gdzie = fn(array $m) => "{$m['nazwa']}, poziom {$m['poziom']}, lokal {$m['pokoj']}";
    $A = $os['A']; $B = $os['B'] ?? null; $Z = $os['Z'] ?? null; $S = $os['S'] ?? null;
    $kwota = sl_los(8, 90) * 100;

    $pierwsze = $miejsca[0];
    $kryj = $mf[7] ?? $mf[3] ?? $pierwsze;
    $praca = $mf[1] ?? $mf[3] ?? $pierwsze;
    $przed = [];

    // 1. Zabezpieczony datapad zaginionego — schowany (trzeba przeszukać lokal).
    $kal = [];
    foreach ([1, 3, 5, 7] as $f) if (isset($mf[$f])) $kal[] = '[' . $kiedy($dni($f)) . '] ' . ($f === 7 ? 'Rezerwacja: ' : 'Spotkanie: ') . $gdzie($mf[$f]);
    $wiad = [];
    if ($B) $wiad[] = '[' . $kiedy($dni(3)) . "] {$ksywa($B)}: Musimy porozmawiać o tym, co ustaliliśmy. Nie przez holonet.";
    if ($Z) $wiad[] = '[' . $kiedy($dni(4)) . '] nadawca zastrzeżony: Termin się zbliża. Pamiętaj, kto ci zapłacił.';
    if (in_array('dlug', $tagi, true)) $wiad[] = '[' . $kiedy(max(1, $dni(5) - 1)) . '] Kantor: Rata przeterminowana. Odsetki naliczane od ' . $dzien($dni(5)) . '.';
    if ($S) $wiad[] = '[' . $kiedy(1) . '] nieznany: Ktoś o ciebie pytał. Nie stąd. Uważaj.';
    $wiad[] = '[' . $kiedy(1) . "] {$imie($A)} (szkic, niewysłane): Jeśli to czytasz, to znaczy, że mnie nie ma.";
    $kontakty = array_filter([$B ? "{$imie($B)} — {$sp['rola_b']}" : '', $Z ? "{$ksywa($Z)} — interesy, nie dzwonić po zmroku" : '',
        $S ? 'numer bez nazwy (zapisany ' . $dzien(2) . ')' : '']);
    $przed[] = ['m' => $kryj, 'wl' => $A['id'], 'rodzaj' => 'datapad', 'nazwa' => "Datapad osobisty — {$imie($A)}",
        'opis' => 'Porysowany datapad z blokadą biometryczną; ostatnio używany przez zaginionego.', 'przenosny' => 1, 'ukryty' => 1, 'zab' => 3,
        'pliki' => sl_pliki(['Wiadomości', implode("\n", $wiad)], ['Kalendarz', implode("\n", $kal)], ['Kontakty', implode("\n", $kontakty)],
            ['Notatka', isset($mf[7]) ? "Gdyby coś poszło nie tak: {$gdzie($mf[7])}. Nikomu nie mówić." : "Nie ufać nikomu z {$sp['skupisko']}."])];

    // 2. Stanowisko pracy zaginionego (terminal, nie do zabrania).
    $ostatni = isset($mf[3]) ? "trasa: {$gdzie($mf[3])}" : 'trasa: (plik uszkodzony)';
    $przed[] = ['m' => $praca, 'wl' => $A['id'], 'rodzaj' => 'stanowisko', 'nazwa' => "Stanowisko pracy — {$imie($A)}",
        'opis' => 'Terminal z otwartą, przerwaną sesją. Na blacie kubek z zaschniętą kafą.', 'przenosny' => 0, 'ukryty' => 0, 'zab' => 2,
        'pliki' => sl_pliki(['Dziennik logowań', 'Ostatnie logowanie: ' . $kiedy($dni(3)) . ".\nWylogowanie: brak — sesja przerwana."],
            ['Poczta służbowa', "Od: {$imie($A)}\nDo: przełożony\nTemat: wolne\nBiorę kilka cykli wolnego. Sprawy rodzinne. Wrócę.\n\nOd: przełożony\nDo: {$imie($A)}\nTemat: RE: wolne\nGdzie jesteś? Nikt cię nie widział od " . $dzien($dni(3)) . '.'],
            ['Ostatnie pliki', "rachunek_{$kwota}kr.dat\n{$ostatni}\nzdjecie_" . sl_los(100, 999) . '.holo'])];

    // 3. Kostka danych (gdy sprawa ma tag „dane”) — mocno zabezpieczona, schowana.
    if (in_array('dane', $tagi, true)) {
        $przed[] = ['m' => $mf[3] ?? $mf[5] ?? $pierwsze, 'wl' => $A['id'], 'rodzaj' => 'kostka', 'nazwa' => 'Kostka danych bez etykiety',
            'opis' => 'Kostka danych z zatartym numerem seryjnym.', 'przenosny' => 1, 'ukryty' => 1, 'zab' => 4,
            'pliki' => sl_pliki(['Fragment zapisu', "Transakcja: {$kwota} kr\nOdbiorca: " . ($Z ? $ksywa($Z) : ($B ? $ksywa($B) : 'ukryty')) . "\nZnacznik: {$sp['skupisko']}\nPotwierdzenie: BRAK"])];
    }
    // 4. Kwit lombardowy (dług albo łup) — leży na wierzchu.
    if (in_array('dlug', $tagi, true) || in_array('lup', $tagi, true)) {
        $przed[] = ['m' => $mf[5] ?? $praca, 'wl' => $A['id'], 'rodzaj' => 'dokument', 'nazwa' => 'Kwit lombardowy',
            'opis' => 'Zmięty kwit z pieczątką lombardu.', 'przenosny' => 1, 'ukryty' => 0, 'zab' => 0,
            'pliki' => sl_pliki(['Kwit', "Zastaw: " . ($A['ekwipunek'] ? explode(',', $A['ekwipunek'])[0] : 'przedmiot osobisty') . "\nWartość: " . intdiv($kwota, 3) . " kr\nNa nazwisko: {$imie($A)}\nTermin wykupu: " . sl_los(3, 12) . ' cykli'])];
    }
    // 5. Holozdjęcie — kto z kim i gdzie.
    if ($B) {
        $przed[] = ['m' => $mf[1] ?? $pierwsze, 'wl' => $A['id'], 'rodzaj' => 'osobisty', 'nazwa' => 'Holozdjęcie',
            'opis' => 'Wytarte holozdjęcie w plastikowej ramce.', 'przenosny' => 1, 'ukryty' => 0, 'zab' => 0,
            'pliki' => sl_pliki(['Holozdjęcie', "{$imie($A)} i {$imie($B)}, uśmiechnięci. W tle szyld: " . ($mf[1] ?? $pierwsze)['nazwa'] . '.'])];
    }

    $wstaw = $db->prepare('INSERT INTO sledztwo_przedmioty (sledztwo_id, miejsce_id, lokalizacja_id, poziom, pokoj, wlasciciel_id, rodzaj, nazwa, opis,
        przenosny, ukryty, zabezpieczenie, zawartosc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($przed as $p) {
        $wstaw->execute([(int) $s['id'], (int) $p['m']['id'], $p['m']['lokalizacja_id'], (int) $p['m']['poziom'], (string) $p['m']['pokoj'],
            (int) $p['wl'], $p['rodzaj'], mb_substr($p['nazwa'], 0, 160), $p['opis'], $p['przenosny'], $p['ukryty'], $p['zab'], $p['pliki']]);
    }
}

// Miejsce startowe (faza 2) i VIP — kluczowa postać, która w nim czeka i wprowadza w sprawę.
function sl_start(PDO $db, array $s): array
{
    $q = $db->prepare('SELECT * FROM sledztwo_miejsca WHERE sledztwo_id = ? AND faza_nr = 2 ORDER BY id LIMIT 1');
    $q->execute([(int) $s['id']]);
    $m = $q->fetch() ?: null;
    $vip = null;
    if ($m) {
        $q = $db->prepare("SELECT * FROM sledztwo_postacie WHERE sledztwo_id = ? AND miejsce_id = ? AND waga = 'kluczowy' ORDER BY kod = 'V' DESC, id LIMIT 1");
        $q->execute([(int) $s['id'], (int) $m['id']]);
        $vip = $q->fetch() ?: null;
    }
    return [$m, $vip];
}

// Opis wyglądu i charakteru z karty EotE (do akt osobowych i profilu psychologicznego).
function sl_profil_postaci(array $p, array $pule): array
{
    $k = json_decode((string) $p['karta_eote'], true) ?: [];
    $c = $k['cechy'] ?? [];
    $cechy = [];
    if (($c['krzepa'] ?? 2) >= 3) $cechy[] = 'silny fizycznie';
    if (($c['krzepa'] ?? 2) <= 1) $cechy[] = 'wątłej budowy';
    if (($c['zwinnosc'] ?? 2) >= 3) $cechy[] = 'zręczny, szybki';
    if (($c['intelekt'] ?? 2) >= 3) $cechy[] = 'bystry, wykształcony';
    if (($c['spryt'] ?? 2) >= 3) $cechy[] = 'cwany, dobrze zna ulicę';
    if (($c['wola'] ?? 2) >= 3) $cechy[] = 'uparty, trudno go złamać';
    if (($c['wola'] ?? 2) <= 1) $cechy[] = 'łatwo ulega presji';
    if (($c['prezencja'] ?? 2) >= 3) $cechy[] = 'charyzmatyczny, łatwo zjednuje ludzi';
    if (($c['prezencja'] ?? 2) <= 1) $cechy[] = 'zamknięty w sobie';
    $ch = ['cecha' => '', 'nawyk' => '', 'mowa' => ''];
    foreach (explode(';', (string) $p['charakter']) as $x) {
        [$a, $b] = array_pad(array_map('trim', explode(':', $x, 2)), 2, '');
        if (isset($ch[$a])) $ch[$a] = $b;
    }
    $um = array_keys((array) ($k['umiejetnosci'] ?? []));
    return ['cechy' => $cechy, 'ch' => $ch, 'umiejetnosci' => $um,
        'archetyp' => $pule['archetypy'][$p['archetyp_kod']]['nazwa'] ?? 'nieustalone',
        'gatunek' => $pule['gatunki'][$p['gatunek_kod']]['nazwa'] ?? $p['gatunek_kod'],
        'pelne' => trim($p['imie'] . ' ' . ($p['nazwisko'] ?? '')) . ($p['przydomek'] ? " „{$p['przydomek']}”" : '')];
}

// Teczka sprawy od biura — w ekwipunku droida od startu: zgłoszenie, akta osobowe, profil psychologiczny,
// znane powiązania, zalecenia. Tylko wersja zgłoszona (przy sprawie ukrytej — zasłona, nie prawda).
function sl_teczka_zapewnij(PDO $db, array $s): void
{
    $q = $db->prepare("SELECT 1 FROM sledztwo_przedmioty WHERE sledztwo_id = ? AND rodzaj = 'dokument' AND nazwa LIKE 'Teczka sprawy%' LIMIT 1");
    $q->execute([(int) $s['id']]);
    if ($q->fetchColumn()) return;
    $pule = sl_pule($db);
    $q = $db->prepare('SELECT * FROM sledztwo_postacie WHERE sledztwo_id = ? AND kod IS NOT NULL');
    $q->execute([(int) $s['id']]);
    $os = [];
    foreach ($q->fetchAll() as $p) $os[$p['kod']] = $p;
    if (!isset($os['A'])) return;
    [$start, $vip] = sl_start($db, $s);
    $q = $db->prepare('SELECT COALESCE(zg.nazwa, z.nazwa) AS zgloszone, r.nazwa AS relacja, r.rola_a, r.rola_b, l.nazwa AS skupisko
        FROM sledztwa s JOIN sledztwo_zdarzenia z ON z.id = s.zdarzenie_id LEFT JOIN sledztwo_zdarzenia zg ON zg.id = s.zdarzenie_zgloszone_id
        JOIN sledztwo_relacje r ON r.id = s.relacja_id LEFT JOIN lokalizacje l ON l.id = s.skupisko_id WHERE s.id = ?');
    $q->execute([(int) $s['id']]);
    $sp = $q->fetch();
    $q = $db->prepare('SELECT w.szkic FROM sledztwo_fazy f JOIN sledztwo_warianty w ON w.faza_nr = f.faza_nr AND w.nr = f.wariant_nr
        WHERE f.sledztwo_id = ? AND f.faza_nr = 2');
    $q->execute([(int) $s['id']]);
    $szkic2 = (string) $q->fetchColumn();
    $q = $db->prepare('SELECT faza_nr, nazwa, poziom, pokoj FROM sledztwo_miejsca WHERE sledztwo_id = ? ORDER BY id');
    $q->execute([(int) $s['id']]);
    $mf = [];
    foreach ($q->fetchAll() as $m) $mf[(int) $m['faza_nr']] ??= $m;
    $q = $db->prepare('SELECT tagi FROM sledztwo_fazy WHERE sledztwo_id = ? ORDER BY pozycja DESC LIMIT 1');
    $q->execute([(int) $s['id']]);
    $tagi = sl_tagi((string) $q->fetchColumn());

    $A = $os['A'];
    $pa = sl_profil_postaci($A, $pule);
    $gdzie = fn(?array $m) => $m ? "{$m['nazwa']}, poziom {$m['poziom']}, lokal {$m['pokoj']}" : 'nieustalone';
    $pv = $vip ? sl_profil_postaci($vip, $pule) : null;
    $zgl = "Sprawa #{$s['id']} — {$sp['zgloszone']}.\nDotyczy: {$pa['pelne']} ({$sp['rola_a']}).\n"
        . ($vip ? "Zgłaszający: {$pv['pelne']}, {$pv['gatunek']}, {$pv['archetyp']}.\nCzeka na droida: {$gdzie($start)}.\n" : "Zgłoszenie anonimowe.\n")
        . "\nTreść zgłoszenia (wg zgłaszającego):\n" . ($szkic2 ? sl_szkic_czytelny($szkic2, array_values($os), $start['nazwa'] ?? null) : '—');
    $akta = "Imię i nazwisko: {$pa['pelne']}\nGatunek: {$pa['gatunek']}\nPłeć: " . (['m' => 'mężczyzna', 'k' => 'kobieta', 'n' => '—'][$A['plec']] ?? '—')
        . "\nWiek: " . ($A['wiek'] ?? 'nieznany') . "\nZajęcie: {$pa['archetyp']}\nRejon: " . ($sp['skupisko'] ?? 'Nar Shaddaa')
        . "\nOstatnio widziany: {$gdzie($mf[1] ?? $mf[3] ?? null)}\nPrzy sobie zwykle: " . ($A['ekwipunek'] ?: 'nieustalone')
        . "\nZnaki szczególne: " . ($pa['ch']['nawyk'] ?: 'brak w aktach')
        . "\nSytuacja finansowa: " . (in_array('dlug', $tagi, true) ? 'zła — zaległości w spłatach' : 'bez zastrzeżeń w rejestrach')
        . "\nKartoteka: " . (in_array('wladze', $tagi, true) ? 'wpis w rejestrze straży portowej' : 'czysta');
    $profil = "Ocena wstępna (na podstawie akt i relacji otoczenia — nie jest pewnikiem):\n"
        . '- ' . ($pa['ch']['cecha'] ?: 'brak wyraźnych cech') . "\n"
        . ($pa['cechy'] ? '- ' . implode("\n- ", $pa['cechy']) . "\n" : '')
        . '- sposób mówienia: ' . ($pa['ch']['mowa'] ?: 'zwyczajny') . "\n"
        . '- zawodowo: ' . ($pa['umiejetnosci'] ? implode(', ', array_slice($pa['umiejetnosci'], 0, 4)) : 'brak danych') . "\n"
        . (in_array('sekret', $tagi, true) ? "- otoczenie twierdzi, że „coś ukrywał w ostatnich tygodniach”\n" : '')
        . (in_array('konflikt', $tagi, true) ? "- ostatnio skłócony z kimś bliskim\n" : '')
        . "\nZalecenie: nie zakładać, że jest ofiarą. Nie zakładać, że jest sprawcą.";
    $pow = [];
    if (isset($os['B'])) $pow[] = trim($os['B']['imie'] . ' ' . ($os['B']['nazwisko'] ?? '')) . " — {$sp['rola_b']} (relacja: {$sp['relacja']})";
    if ($vip && !in_array($vip['kod'], ['A', 'B'], true)) $pow[] = "{$pv['pelne']} — zgłaszający";
    $pow[] = 'pozostałe kontakty — nieustalone';
    $zal = '1. Rozmowa ze zgłaszającym' . ($vip ? " ({$pv['pelne']})" : '') . " w miejscu zgłoszenia: {$gdzie($start)}.\n"
        . "2. Ustalić, gdzie i kiedy {$pa['pelne']} był widziany po raz ostatni.\n"
        . "3. Przedmioty osobiste i stanowisko pracy mogą być zabezpieczone — łamać ostrożnie; terminal publiczny zostawia ślad.\n"
        . '4. Bez nakazu nie wchodzić do lokali oznaczonych NAKAZ. Wnioski składać przez panel nakazów.';
    $db->prepare("INSERT INTO sledztwo_przedmioty (sledztwo_id, lokalizacja_id, poziom, pokoj, wlasciciel_id, rodzaj, nazwa, opis, przenosny, ukryty,
            zabezpieczenie, zawartosc, znaleziony_utc, w_ekwipunku, odblokowany, sposob_odblokowania)
        VALUES (?, NULL, 0, 'teczka', NULL, 'dokument', ?, ?, 1, 0, 0, ?, ?, 1, 1, 'biuro')")
        ->execute([(int) $s['id'], "Teczka sprawy #{$s['id']}", 'Teczka od biura: zgłoszenie, akta osobowe, profil psychologiczny, powiązania, zalecenia.',
            sl_pliki(['Zgłoszenie', $zgl], ["Akta osobowe — {$pa['pelne']}", $akta], ['Profil psychologiczny', $profil],
                ['Znane powiązania', implode("\n", $pow)], ['Zalecenia biura', $zal]), sl_utc(sl_teraz())]);
}

// Przedmioty widoczne w lokalu, w którym stoi droid (bez schowanych, których jeszcze nie znalazł; bez zabranych).
function sl_przedmioty_tutaj(PDO $db, array $s, array $d): array
{
    if ((string) $d['pokoj'] === 'korytarz' || $d['stan'] === 'w_drodze') return [];
    $q = $db->prepare('SELECT id, rodzaj, nazwa, opis, przenosny, zabezpieczenie, odblokowany FROM sledztwo_przedmioty
        WHERE sledztwo_id = ? AND lokalizacja_id <=> ? AND poziom = ? AND pokoj = ? AND w_ekwipunku = 0 AND (ukryty = 0 OR znaleziony_utc IS NOT NULL)
        ORDER BY id');
    $q->execute([(int) $s['id'], $d['lokalizacja_id'] !== null ? (int) $d['lokalizacja_id'] : null, (int) $d['poziom'], (string) $d['pokoj']]);
    return $q->fetchAll();
}

function sl_ekwipunek(PDO $db, array $s): array
{
    $q = $db->prepare('SELECT id, rodzaj, nazwa, opis, zabezpieczenie, odblokowany, sposob_odblokowania FROM sledztwo_przedmioty
        WHERE sledztwo_id = ? AND w_ekwipunku = 1 ORDER BY id');
    $q->execute([(int) $s['id']]);
    return $q->fetchAll();
}

// Przedmiot dostępny dla droida: w ekwipunku albo widoczny w lokalu, w którym stoi.
function sl_przedmiot_dostepny(PDO $db, array $s, array $d, int $id): array
{
    $q = $db->prepare('SELECT * FROM sledztwo_przedmioty WHERE id = ? AND sledztwo_id = ?');
    $q->execute([$id, (int) $s['id']]);
    $p = $q->fetch();
    if (!$p) throw new SledztwoBlad('Nie ma takiego przedmiotu.');
    if ($p['w_ekwipunku']) return $p;
    $tutaj = $d['stan'] !== 'w_drodze' && (string) $d['pokoj'] === (string) $p['pokoj'] && (int) $d['poziom'] === (int) $p['poziom']
        && ((int) $d['lokalizacja_id'] === (int) $p['lokalizacja_id']) && (!$p['ukryty'] || $p['znaleziony_utc'] !== null);
    if (!$tutaj) throw new SledztwoBlad('Droid nie ma dostępu do tego przedmiotu.');
    return $p;
}

function sl_przeszukaj(PDO $db, array $s): array
{
    $d = sl_rozlicz_droida($db, $s);
    if (!$d || $d['stan'] === 'w_drodze' || (string) $d['pokoj'] === 'korytarz') throw new SledztwoBlad('Droid musi stać w lokalu.');
    $bid = $d['lokalizacja_id'] !== null ? (int) $d['lokalizacja_id'] : null;
    $teraz = sl_teraz();
    $q = $db->prepare('SELECT 1 FROM sledztwo_przeszukania WHERE sledztwo_id = ? AND lokalizacja_id <=> ? AND poziom = ? AND pokoj = ? AND kiedy_utc > ? LIMIT 1');
    $q->execute([(int) $s['id'], $bid, (int) $d['poziom'], (string) $d['pokoj'], sl_utc($teraz - 600)]);
    if ($q->fetchColumn()) throw new SledztwoBlad('Droid przeszukał ten lokal przed chwilą. Spróbuj za kilka minut.');
    $k = sl_karta_droida($db, (string) $s['konto_login']);
    $test = sl_test_eote((int) ($k['cechy']['spryt'] ?? 2), (int) ($k['umiejetnosci']['percepcja']['ranga'] ?? 0), 2, 0);
    $db->prepare('INSERT INTO sledztwo_przeszukania (sledztwo_id, lokalizacja_id, poziom, pokoj, udane, kiedy_utc) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([(int) $s['id'], $bid, (int) $d['poziom'], (string) $d['pokoj'], $test['udana'] ? 1 : 0, sl_utc($teraz)]);
    $znalezione = [];
    if ($test['udana']) {
        $q = $db->prepare('SELECT id, nazwa FROM sledztwo_przedmioty WHERE sledztwo_id = ? AND lokalizacja_id <=> ? AND poziom = ? AND pokoj = ?
            AND ukryty = 1 AND znaleziony_utc IS NULL AND w_ekwipunku = 0');
        $q->execute([(int) $s['id'], $bid, (int) $d['poziom'], (string) $d['pokoj']]);
        $znalezione = $q->fetchAll();
        if ($znalezione) {
            $db->prepare('UPDATE sledztwo_przedmioty SET znaleziony_utc = ? WHERE id IN (' . implode(',', array_map(fn($x) => (int) $x['id'], $znalezione)) . ')')
                ->execute([sl_utc($teraz)]);
        }
    }
    $opis = "Percepcja: {$test['pula']} → sukcesy netto {$test['sukcesy']}.";
    sl_dziennik($db, (int) $s['id'], 'meldunek', 'droid', !$test['udana'] ? "Przeszukałem lokal {$d['pokoj']}. Niczego nie zauważyłem. ({$opis})"
        : ($znalezione ? "Przeszukałem lokal {$d['pokoj']}. Znalazłem: " . implode(', ', array_column($znalezione, 'nazwa')) . ". ({$opis})"
            : "Przeszukałem lokal {$d['pokoj']} dokładnie. Nic tu nie ma. ({$opis})"));
    return ['udane' => $test['udana'], 'znalezione' => array_column($znalezione, 'nazwa'), 'opis' => $opis];
}

function sl_zabierz(PDO $db, array $s, int $id): array
{
    $d = sl_rozlicz_droida($db, $s);
    $p = sl_przedmiot_dostepny($db, $s, $d, $id);
    if ($p['w_ekwipunku']) throw new SledztwoBlad('Droid już to ma.');
    if (!$p['przenosny']) throw new SledztwoBlad('Tego nie da się zabrać — można się tylko podłączyć.');
    $db->prepare('UPDATE sledztwo_przedmioty SET w_ekwipunku = 1, znaleziony_utc = COALESCE(znaleziony_utc, ?) WHERE id = ?')->execute([sl_utc(sl_teraz()), $id]);
    sl_dziennik($db, (int) $s['id'], 'meldunek', 'droid', "Zabezpieczyłem przedmiot: {$p['nazwa']}.");
    return ['ok' => true];
}

// Łamanie zabezpieczenia: Intelekt + Komputery droida przeciw kościom trudności = poziom zabezpieczenia.
function sl_zlam(PDO $db, array $s, int $id): array
{
    $d = sl_rozlicz_droida($db, $s);
    $p = sl_przedmiot_dostepny($db, $s, $d, $id);
    if ((int) $p['zabezpieczenie'] === 0 || $p['odblokowany']) throw new SledztwoBlad('Ten nośnik nie jest zablokowany.');
    $teraz = sl_teraz();
    if ($p['blokada_do'] && (sl_z_utc($p['blokada_do']) ?? 0) > $teraz) {
        throw new SledztwoBlad('Zabezpieczenie jest w trybie blokady. Spróbuj za ' . sl_czas_tekst((sl_z_utc($p['blokada_do']) ?? 0) - $teraz) . '.');
    }
    $k = sl_karta_droida($db, (string) $s['konto_login']);
    $test = sl_test_eote((int) ($k['cechy']['intelekt'] ?? 2), (int) ($k['umiejetnosci']['komputery']['ranga'] ?? 0), (int) $p['zabezpieczenie'], 0);
    $opis = "Komputery: {$test['pula']} → sukcesy netto {$test['sukcesy']}" . ($test['rozpacz'] ? ', ROZPACZ' : '') . '.';
    if ($test['udana']) {
        $db->prepare("UPDATE sledztwo_przedmioty SET odblokowany = 1, sposob_odblokowania = 'sam' WHERE id = ?")->execute([$id]);
        sl_dziennik($db, (int) $s['id'], 'meldunek', 'droid', "Złamałem zabezpieczenie: {$p['nazwa']}. ({$opis})");
    } else {
        $db->prepare('UPDATE sledztwo_przedmioty SET blokada_do = ? WHERE id = ?')->execute([sl_utc($teraz + ($test['rozpacz'] ? 86400 : 600)), $id]);
        sl_dziennik($db, (int) $s['id'], 'meldunek', 'droid', ($test['rozpacz']
            ? "Zabezpieczenie nośnika {$p['nazwa']} przeszło w blokadę na dobę."
            : "Nie złamałem zabezpieczenia: {$p['nazwa']}. Blokada 10 minut.") . " ({$opis})");
    }
    return ['udana' => $test['udana'], 'opis' => $opis];
}

// Terminal w zasięgu droida: korytarzowy (droid na korytarzu) albo w lokalu, w którym stoi. [plan, lokal|null, publiczny]
function sl_terminal_tutaj(PDO $db, array $s, array $d, string $etykieta): array
{
    if ($d['stan'] === 'w_drodze') throw new SledztwoBlad('Droid jest w drodze.');
    $pule = sl_pule($db);
    $budynki = sl_budynki($db, $s);
    $bid = $d['lokalizacja_id'] !== null ? (int) $d['lokalizacja_id'] : null;
    $plan = sl_plan($db, $s, $pule, $bid !== null ? ($budynki[$bid] ?? null) : null, (int) $d['poziom']);
    // Do terminala trzeba podejść (ok. 1,5 m).
    $term = sl_terminale($plan)[$etykieta] ?? null;
    if ($term) {
        $poz = sl_pozycja($db, $s, $d, $plan);
        if ($poz[2] === $term['pokoj'] && hypot($poz[0] - $term['x'], $poz[1] - $term['y']) > SLEDZTWO_ZASIEG_TERMINALA) {
            throw new SledztwoBlad("Droid jest za daleko od terminala {$etykieta} — podejdź do niego.");
        }
    }
    if ((string) $d['pokoj'] === 'korytarz') {
        if ($plan['terminal_korytarz'] !== $etykieta) throw new SledztwoBlad('Ten terminal nie jest w zasięgu droida — podejdź do niego.');
        return [$plan, null, true];
    }
    foreach ($plan['pokoje'] as $p) {
        if ($p['nr'] === (string) $d['pokoj'] && $p['terminal'] === $etykieta) return [$plan, $p, in_array($p['lokal'], SLEDZTWO_TERMINALE_PUBLICZNE, true)];
    }
    throw new SledztwoBlad('Ten terminal nie jest w zasięgu droida — wejdź do lokalu, w którym stoi.');
}

// Ekran komputera: przedmiot (datapad, stanowisko, kostka…) albo terminal z planu (T1, T2…).
function sl_komputer(PDO $db, array $s, ?int $przedmiotId, string $terminal): array
{
    $d = sl_rozlicz_droida($db, $s);
    if (!$d) throw new SledztwoBlad('Droid nie ma przydziału.');
    $zablokowane = array_values(array_filter(sl_ekwipunek($db, $s), fn($x) => (int) $x['zabezpieczenie'] > 0 && !$x['odblokowany']));
    if ($przedmiotId) {
        $p = sl_przedmiot_dostepny($db, $s, $d, $przedmiotId);
        $zamek = (int) $p['zabezpieczenie'] > 0 && !$p['odblokowany'];
        return ['nazwa' => $p['nazwa'], 'opis' => $p['opis'], 'przedmiot_id' => (int) $p['id'], 'publiczny' => false,
            'zablokowany' => $zamek, 'zabezpieczenie' => (int) $p['zabezpieczenie'], 'blokada_do' => $p['blokada_do'],
            'pliki' => $zamek ? [] : (json_decode((string) $p['zawartosc'], true) ?: [])];
    }
    [$plan, $lokal, $publiczny] = sl_terminal_tutaj($db, $s, $d, $terminal);
    $bid = $d['lokalizacja_id'] !== null ? (int) $d['lokalizacja_id'] : null;
    if ($publiczny) {
        // Spis lokali budynku (z presetu, piętro po piętrze), holonet i ogłoszenia. Dekodowanie nośników zostawia ślad.
        $pule = sl_pule($db);
        $budynki = sl_budynki($db, $s);
        $b = $bid !== null ? ($budynki[$bid] ?? null) : null;
        $spis = [];
        for ($pz = $plan['parter'], $ile = 0; $pz <= $plan['gora'] && $ile < 25; $pz++, $ile++) {
            $pp = sl_plan($db, $s, $pule, $b, $pz);
            $nazwy = array_count_values(array_column($pp['pokoje'], 'rodzaj'));
            $spis[] = "Poziom {$pz}: " . implode(', ', array_map(fn($n, $c) => $c > 1 ? "{$n} ×{$c}" : $n, array_keys($nazwy), $nazwy));
        }
        $h = crc32(gmdate('YmdH') . '|' . $bid);
        $news = [];
        for ($i = 0; $i < 4; $i++) $news[] = '• ' . SLEDZTWO_HOLONET[($h + $i * 3) % count(SLEDZTWO_HOLONET)];
        $ogl = [];
        for ($i = 0; $i < 3; $i++) $ogl[] = '• ' . SLEDZTWO_OGLOSZENIA[($h + $i * 2) % count(SLEDZTWO_OGLOSZENIA)];
        return ['nazwa' => "Terminal publiczny {$terminal} — {$plan['budynek']}, poziom {$plan['poziom']}", 'opis' => 'Terminal ogólnodostępny.',
            'publiczny' => true, 'terminal' => $terminal, 'zablokowany' => false,
            'pliki' => [['nazwa' => 'Spis lokali', 'tresc' => implode("\n", $spis)], ['nazwa' => 'Holonet', 'tresc' => implode("\n", $news)],
                ['nazwa' => 'Ogłoszenia', 'tresc' => implode("\n", $ogl)]],
            'do_dekodowania' => array_map(fn($x) => ['id' => (int) $x['id'], 'nazwa' => $x['nazwa']], $zablokowane)];
    }
    // Prywatny terminal w lokalu: powstaje jako przedmiot przy pierwszym podłączeniu (zabezpieczony, treść z szumu).
    $nazwa = "Terminal {$terminal} — {$lokal['rodzaj']} {$lokal['nr']}";
    $q = $db->prepare('SELECT id FROM sledztwo_przedmioty WHERE sledztwo_id = ? AND lokalizacja_id <=> ? AND poziom = ? AND pokoj = ? AND nazwa = ?');
    $q->execute([(int) $s['id'], $bid, (int) $d['poziom'], $lokal['nr'], $nazwa]);
    $id = $q->fetchColumn();
    if (!$id) {
        $pule = sl_pule($db);
        sl_ziarno(SLEDZTWO_SWIAT, 'terminal', (string) $bid, (string) $d['poziom'], $lokal['nr']);
        $ludzie = [];
        for ($i = 0; $i < 4; $i++) {
            $im = array_values(array_filter($pule['imiona'], fn($x) => $x['rodzaj'] === 'imie'));
            $ludzie[] = $im ? sl_jeden($im)['wartosc'] : 'pracownik';
        }
        $pliki = sl_pliki(['Grafik zmian', implode("\n", array_map(fn($n, $i) => sprintf('Zmiana %d: %s, %02d:00–%02d:00', $i + 1, $n, 6 + 8 * $i % 24, (14 + 8 * $i) % 24), $ludzie, array_keys($ludzie)))],
            ['Rejestr wejść', implode("\n", array_map(fn($n) => sprintf('%02d:%02d — %s', sl_los(0, 23), sl_los(0, 59), $n), $ludzie))],
            ['Notatki', ['Przypomnieć o płatności za wodę.', 'Zamek w drzwiach znów się zacina.', 'Nie wpuszczać droidów po zmroku.', 'Dostawa przesunięta o cykl.'][sl_los(0, 3)]]);
        $zab = in_array($lokal['lokal'], ['biuro_ochrony', 'serwerownia', 'gabinet_dyrektora', 'biuro_zarzadu', 'wieza', 'kasa', 'archiwum'], true) ? 3 : 2;
        $db->prepare("INSERT INTO sledztwo_przedmioty (sledztwo_id, lokalizacja_id, poziom, pokoj, rodzaj, nazwa, opis, przenosny, ukryty, zabezpieczenie, zawartosc)
            VALUES (?, ?, ?, ?, 'terminal', ?, ?, 0, 0, ?, ?)")
            ->execute([(int) $s['id'], $bid, (int) $d['poziom'], $lokal['nr'], $nazwa, 'Terminal personelu. „Dostęp tylko dla upoważnionych.”', $zab, $pliki]);
        $id = (int) $db->lastInsertId();
    }
    return sl_komputer($db, $s, (int) $id, '');
}

// Dekodowanie nośnika z ekwipunku na publicznym terminalu: od razu, ale właściciel danych dowiaduje się, gdzie i kiedy.
function sl_dekoduj_publicznie(PDO $db, array $s, int $id, string $terminal): array
{
    $d = sl_rozlicz_droida($db, $s);
    [$plan, , $publiczny] = sl_terminal_tutaj($db, $s, $d, $terminal);
    if (!$publiczny) throw new SledztwoBlad('To nie jest terminal publiczny.');
    $p = sl_przedmiot_dostepny($db, $s, $d, $id);
    if (!$p['w_ekwipunku']) throw new SledztwoBlad('Najpierw zabierz nośnik.');
    if ((int) $p['zabezpieczenie'] === 0 || $p['odblokowany']) throw new SledztwoBlad('Ten nośnik nie jest zablokowany.');
    $db->prepare("UPDATE sledztwo_przedmioty SET odblokowany = 1, sposob_odblokowania = 'terminal' WHERE id = ?")->execute([$id]);
    $gdzie = "{$plan['budynek']}, poziom {$plan['poziom']}, terminal {$terminal}";
    $db->prepare("INSERT INTO sledztwo_alarmy (sledztwo_id, kiedy_utc, rodzaj, przedmiot_id, lokalizacja_id, poziom, pokoj, opis)
        VALUES (?, ?, 'terminal_publiczny', ?, ?, ?, ?, ?)")
        ->execute([(int) $s['id'], sl_utc(sl_teraz()), $id, $d['lokalizacja_id'], (int) $d['poziom'], (string) $d['pokoj'],
            "Odszyfrowano {$p['nazwa']} na terminalu publicznym: {$gdzie}."]);
    sl_dziennik($db, (int) $s['id'], 'meldunek', 'droid', "Terminal publiczny {$terminal} odszyfrował: {$p['nazwa']}. Uwaga: sieć publiczna zapisuje identyfikator nośnika — właściciel danych może wiedzieć, gdzie i kiedy.");
    return ['ok' => true];
}

// ---------- rozmowy droida z NPC (migracja 085) ----------

const SLEDZTWO_PODEJSCIE_S = 3;              // droid podchodzi na 2–3 m
const SLEDZTWO_AI_DLA_POBOCZNYCH = false;    // true = poboczni z bazy też mówią przez AI (więcej wywołań)
const SLEDZTWO_AI_DLA_WSZYSTKICH = true;     // true = każdy rozmówca z metryką (089) mówi przez AI; false = tylko kluczowi
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
    if ($kto === 'npc') sl_archiwizuj($db, (int) $db->lastInsertId(), $rozmowaId, $tresc);
}

// Archiwum sformułowań (migracja 093): kto, gdzie i kiedy co powiedział w sprawie.
function sl_archiwizuj(PDO $db, int $wpisId, int $rozmowaId, string $tresc): void
{
    try {
        $q = $db->prepare('SELECT * FROM sledztwo_rozmowy WHERE id = ?');
        $q->execute([$rozmowaId]);
        $r = $q->fetch();
        if (!$r) return;
        $o = json_decode((string) $r['opis_json'], true) ?: [];
        $kto = mb_substr(trim(($o['kod'] ?? '') . ': ' . sl_opis_rozmowcy($r), ': '), 0, 200);
        $db->prepare('INSERT IGNORE INTO sledztwo_sformulowania (wpis_id, sledztwo_id, rozmowa_id, kontakt, kto, lokalizacja_id, poziom, pokoj, tresc, kiedy_utc)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$wpisId, (int) $r['sledztwo_id'], $rozmowaId, (string) $r['kontakt'], $kto, $r['lokalizacja_id'], (int) $r['poziom'],
                (string) $r['pokoj'], $tresc, sl_utc(sl_teraz())]);
    } catch (PDOException $e) { /* brak migracji 093 */ }
}

// Archiwum dla rozmówcy $r: najnowsze wypowiedzi w sprawie z oznaczeniem, kto i skąd mówił —
// TY (ten sam rozmówca), OBOK (ten sam lokal / korytarz piętra, ostatnie 30 min — słyszał to), W TYM BUDYNKU, GDZIE INDZIEJ.
// Zwraca [wiersze z polem 'relacja', tekst do promptu]. Bez migracji 093 — z zapisów rozmów.
function sl_archiwum_dla(PDO $db, array $r, int $limit = 40): array
{
    try {
        $q = $db->prepare('SELECT kontakt, kto, lokalizacja_id, poziom, pokoj, tresc, kiedy_utc FROM sledztwo_sformulowania
            WHERE sledztwo_id = ? ORDER BY id DESC LIMIT ' . (int) $limit);
        $q->execute([(int) $r['sledztwo_id']]);
        $wiersze = $q->fetchAll();
    } catch (PDOException $e) {
        $q = $db->prepare("SELECT r2.kontakt, '' AS kto, r2.lokalizacja_id, r2.poziom, r2.pokoj, w.tresc, w.kiedy_utc FROM sledztwo_rozmowy_wpisy w
            JOIN sledztwo_rozmowy r2 ON r2.id = w.rozmowa_id WHERE r2.sledztwo_id = ? AND w.kto = 'npc' ORDER BY w.id DESC LIMIT " . (int) $limit);
        $q->execute([(int) $r['sledztwo_id']]);
        $wiersze = $q->fetchAll();
    }
    $teraz = sl_teraz();
    foreach ($wiersze as &$w) {
        $tenBudynek = ($w['lokalizacja_id'] === null && $r['lokalizacja_id'] === null) || (int) $w['lokalizacja_id'] === (int) $r['lokalizacja_id'];
        $w['relacja'] = $w['kontakt'] === $r['kontakt'] ? 'TY'
            : ($tenBudynek && (int) $w['poziom'] === (int) $r['poziom'] && $w['pokoj'] === $r['pokoj'] && (sl_z_utc($w['kiedy_utc']) ?? 0) > $teraz - 1800 ? 'OBOK'
                : ($tenBudynek ? 'W TYM BUDYNKU' : 'GDZIE INDZIEJ'));
    }
    unset($w);
    $tekst = $wiersze ? implode("\n", array_map(fn($w) => "- „{$w['tresc']}” — " . ($w['relacja'] === 'TY' ? 'TY (powiedziałeś to wcześniej)'
        : $w['relacja'] . ($w['kto'] !== '' ? ": {$w['kto']}" : '')), $wiersze)) : '(archiwum puste — nikt jeszcze nic nie powiedział)';
    return [$wiersze, $tekst];
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
    $poz = sl_pozycja($db, $s, $d, $plan);
    foreach (sl_kontakty($db, $s, $pule, $plan, (int) $poz[0]) as $k) if ($k['token'] === $token) { $kontakt = $k; break; }
    if (!$kontakt) throw new SledztwoBlad('Kontakt poza zasięgiem albo już odszedł.');
    // Dalej niż 3 m: droid podchodzi na 2–3 m (po swojej stronie; do stojącego w drzwiach — od strony, z której idzie).
    $cel = null;
    [$kx, $ky] = [(int) $kontakt['x'], (int) $kontakt['y']];
    if (hypot($poz[0] - $kx, $poz[1] - $ky) > SLEDZTWO_ZASIEG_ROZMOWY) {
        $znak = $poz[0] < $kx ? -1 : 1;
        if (!empty($kontakt['w_drzwiach'])) {
            $gora = $kontakt['strona'] === 'gora';
            $wLokalu = $poz[2] === $kontakt['pokoj'];
            $cel = sl_punkt($plan, $kx, $wLokalu === $gora ? $ky - 22 : $ky + 22);
        } else {
            $chce = !empty($kontakt['straznik']) ? 'korytarz' : $kontakt['pokoj'];
            $cel = sl_punkt($plan, $kx + $znak * SLEDZTWO_ODSTEP_ROZMOWY, $ky);
            if ($cel[2] !== $chce) $cel = sl_punkt($plan, $kx - $znak * SLEDZTWO_ODSTEP_ROZMOWY, $ky);
        }
        $lokal = $cel[2] !== 'korytarz' && $cel[2] !== $poz[2] ? sl_lokal($plan, $cel[2]) : null;
        if ($lokal) {
            [$wolno, $powod] = sl_dostep($db, $s, $bid, (int) $d['poziom'], $lokal);
            if (!$wolno) throw new SledztwoBlad($powod . ' Droid nie wejdzie.');
        }
    }

    // Metryka rozmówcy (kim jest, co wie o osobie ze sprawy) — powstaje raz, przy pierwszej rozmowie.
    sl_metryka_zapewnij($db, $s, $pule, $plan, $kontakt);
    $stara = sl_rozmowa_aktywna($db, (int) $s['id']);
    if ($stara) sl_rozmowa_zakoncz($db, $stara, 'Droid przerwał rozmowę i podszedł do kogoś innego.');
    $teraz = sl_teraz();
    $gotowa = $teraz + SLEDZTWO_PODEJSCIE_S;
    if ($cel) {
        $ruch = sl_rozkaz_ruchu($db, $s, $bid, (int) $d['poziom'], $cel[2], $cel[0], $cel[1]);
        $gotowa = $teraz + $ruch['czas_s'] + SLEDZTWO_PODEJSCIE_S;
    }
    $cel = $cel ? $cel[2] : $poz[2];
    $opis = ['gatunek' => $kontakt['gatunek'], 'plec' => $kontakt['plec'], 'wyglad' => $kontakt['wyglad'], 'kod' => $kontakt['kod'],
        'x' => (int) $kontakt['x'], 'y' => (int) ($kontakt['y'] ?? 0)];
    $db->prepare('INSERT INTO sledztwo_rozmowy (sledztwo_id, kontakt, postac_id, opis_json, lokalizacja_id, poziom, pokoj, gotowa_utc, rozpoczeto_utc)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int) $s['id'], $token, $kontakt['postac_id'], json_encode($opis, JSON_UNESCAPED_UNICODE), $bid, (int) $d['poziom'],
            $cel, sl_utc($gotowa), sl_utc($teraz)]);
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
const SLEDZTWO_PROCEDURY = [
    'wlasna' => 'Procedura: pytania operatora zadajesz we własnym imieniu, jak swoje. Nie wspominasz rozmówcy o operatorze ani o tym, że ktoś ci podpowiada.',
    'jawna' => 'Procedura jawna: możesz zaznaczyć, że pytanie zadaje twój operator („Mój operator pyta…”), jeśli to brzmi naturalnie; nie musisz robić tego za każdym razem.',
];

// Pytanie operatora jako wypowiedź droida: uprzejmie, z pytajnikiem; przy procedurze jawnej — „mój operator pyta”.
// Czy dwa pytania są „tym samym” (po normalizacji: małe litery, bez interpunkcji; albo podobieństwo ≥ 85%).
function sl_to_samo(string $a, string $b): bool
{
    $n = fn(string $x) => trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}\s]/u', '', mb_strtolower(
        (string) preg_replace('/\s*\[Podejście:.*\]$/us', '', $x)))));
    [$a, $b] = [$n($a), $n($b)];
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    similar_text($a, $b, $proc);
    return $proc >= 85;
}

// Wcześniejsze wypowiedzi z tą samą osobą (wszystkie rozmowy w sprawie): kto — 'operator' albo 'droid'.
function sl_juz_padlo(PDO $db, int $sledztwoId, string $kontakt, string $kto): array
{
    $q = $db->prepare("SELECT w.tresc FROM sledztwo_rozmowy_wpisy w JOIN sledztwo_rozmowy r ON r.id = w.rozmowa_id
        WHERE r.sledztwo_id = ? AND r.kontakt = ? AND w.kto = ? ORDER BY w.id");
    $q->execute([$sledztwoId, $kontakt, $kto]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

// Ktoś stał obok, gdy droid przed chwilą (30 min) rozmawiał z inną osobą w tym samym lokalu — albo na korytarzu
// w promieniu 6 m. Zwraca kod tamtego rozmówcy (słyszał przywitanie i pytania) albo null. Sam w lokalu — nie dotyczy.
function sl_slyszal_wczesniej(PDO $db, array $r): ?string
{
    $o = json_decode((string) $r['opis_json'], true) ?: [];
    $q = $db->prepare("SELECT r.opis_json FROM sledztwo_rozmowy r
        WHERE r.sledztwo_id = ? AND r.id <> ? AND r.kontakt <> ? AND r.lokalizacja_id <=> ? AND r.poziom = ? AND r.pokoj = ? AND r.rozpoczeto_utc > ?
        AND EXISTS (SELECT 1 FROM sledztwo_rozmowy_wpisy w WHERE w.rozmowa_id = r.id AND w.kto = 'droid') ORDER BY r.id DESC LIMIT 5");
    $q->execute([(int) $r['sledztwo_id'], (int) $r['id'], (string) $r['kontakt'], $r['lokalizacja_id'], (int) $r['poziom'], (string) $r['pokoj'],
        sl_utc(sl_teraz() - 1800)]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $j) {
        $p = json_decode((string) $j, true) ?: [];
        if ($r['pokoj'] === 'korytarz' && (!isset($o['x'], $p['x']) || hypot($o['x'] - $p['x'], ($o['y'] ?? 0) - ($p['y'] ?? 0)) > 60)) continue;
        return (string) ($p['kod'] ?? 'ktoś obok');
    }
    return null;
}

// Bez AI: polecenie operatora jako wypowiedź droida. Pytanie („gdzie…?”, „zapytaj o…”) — pytanie; reszta
// („grzeczniej proszę…”, „powiedz mu, że…”) — droid mówi to jako swoje zdanie.
function sl_pytanie_operatora(string $polecenie, bool $jawna, array $f = []): string
{
    $p = trim($polecenie);
    $duza = fn(string $x) => mb_strtoupper(mb_substr($x, 0, 1)) . mb_substr($x, 1);
    $pan = $f['pan'] ?? 'Pan';
    if (preg_match('/^(za|s)?pytaj( (go|ją|jego|jej|ich|rozmówcę))?,?\s*/iu', $p, $m)) {
        $reszta = trim(mb_substr($p, mb_strlen($m[0])));
        if (preg_match('/^o\s+(.+)$/iu', $reszta, $o)) $p = "Co może mi {$pan} powiedzieć o " . rtrim($o[1], ' .?!') . '?';
        else $p = $duza(rtrim($reszta, ' .?!')) . '?';
        return $jawna ? 'Mój operator pyta: ' . $p : $p;
    }
    if (preg_match('/^(powiedz|przekaż|oznajmij)( (mu|jej|im|rozmówcy))?,?\s*(że\s+)?/iu', $p, $m)) $p = trim(mb_substr($p, mb_strlen($m[0])));
    $pytanie = str_ends_with($p, '?') || preg_match('/^(kto|co|gdzie|kiedy|jak|dlaczego|czemu|ile|czy|któr|komu|kogo|czym|skąd|dokąd|po co|z kim)\b/iu', $p);
    $p = $duza(rtrim($p, ' .!?')) . ($pytanie ? '?' : '.');
    if ($jawna) return ($pytanie ? 'Mój operator pyta: ' : 'Mój operator prosi przekazać: ') . $p;
    return $p;
}

// $slyszal — kod rozmówcy, z którym droid rozmawiał obok (ten słyszał przywitanie); $juz — pytania już zadane tej osobie.
// Pusty wynik: droidowi skończyły się pytania.
function sl_droid_zastepczo(bool $pierwsza, array $f, string $oznaczenie, string $operator, string $polecenie, int $nr, bool $jawna = false,
    ?string $slyszal = null, array $juz = []): string
{
    if ($pierwsza && $slyszal) {
        return "Przepraszam, {$f['wolacz']} — słyszał{$f['a']} {$f['pan']} zapewne, o co pytałem przed chwilą. {$oznaczenie}, biuro detektywistyczne. "
            . ($polecenie !== '' ? sl_pytanie_operatora($polecenie, $jawna, $f) : "Czy może {$f['pan']} coś do tego dodać?");
    }
    if ($pierwsza) {
        $t = "Dzień dobry, {$f['wolacz']}. Jestem droid detektywistyczny {$oznaczenie}, operowany przez: {$operator}. "
            . "Mam prawo zadać {$f['panu']} kilka pytań, ponieważ trwa śledztwo, w które jest {$f['pan']} ewidentnie zaangażowan" . ($f['a'] ?: 'y') . '.';
        return $t . ($polecenie !== '' ? ' ' . sl_pytanie_operatora($polecenie, $jawna, $f) : ' Co stało się tutaj ubiegłej nocy?');
    }
    if ($polecenie !== '') return sl_pytanie_operatora($polecenie, $jawna, $f);
    $pytania = ["Kogo {$f['pan']} tu widział{$f['a']} w ciągu ostatniej doby?", "Czy ktoś pytał już {$f['pana']} o tę sprawę?",
        'Kto jeszcze bywa w tym miejscu regularnie?', 'Czy coś zginęło albo zmieniło się tutaj w ostatnich dniach?',
        "Gdzie {$f['pan']} był{$f['a']} ubiegłej nocy?", "Od jak dawna {$f['pan']} tu bywa?", 'Kto tu rządzi i z kim powinienem porozmawiać?'];
    foreach ($pytania as $p) if (!array_filter($juz, fn($x) => sl_to_samo($x, $p))) return $p;
    return '';
}

// Odpowiedź bez AI na proste pytania: imię, pochodzenie, gdzie co jest w budynku (z planu). Null — nie pasuje.
function sl_odpowiedz_zastepcza(PDO $db, array $s, array $pule, array $r, ?array $m, string $pytanie, string $nastroj): ?string
{
    $p = mb_strtolower($pytanie);
    if ($nastroj === 'wrogi') return null;
    if ($m && preg_match('/nazywa|imi[eę]|kim (pan|pani|jeste)|przedstaw/u', $p)) {
        return $m['chce_mowic'] === 'nie' && random_int(1, 100) <= 40 ? 'A co cię to obchodzi, puszko?'
            : 'Jestem ' . trim($m['imie'] . ' ' . ($m['nazwisko'] ?? '')) . '.' . ($m['rola_w_miejscu'] === 'pracownik' ? " Pracuję tu jako {$m['zajecie']}." : '');
    }
    if ($m && preg_match('/skąd|pochodz|urodzi/u', $p)) {
        return (int) $m['urodzony_tutaj'] ? 'Stąd, z Nar Shaddaa. Całe życie na tych poziomach.'
            : "Pochodzę z planety {$m['planeta_urodzenia']}. Tutaj jestem od " . (int) $m['lat_na_planecie'] . ' lat.';
    }
    if (!preg_match('/gdzie|znajd|szuka|jak trafi|jak dojś|namiar|adres|jak tam|którędy/u', $p)) return null;
    // Budynek rejonu z pytania albo — przy „namiary / tam” bez nazwy — z tego, co rozmówca sam wcześniej powiedział.
    $okolica = sl_okolica($db, $s, $pule);
    $bid = $r['lokalizacja_id'] !== null ? (int) $r['lokalizacja_id'] : null;
    $cel = sl_budynek_z_tekstu($okolica, $p, $bid);
    if (!$cel && preg_match('/namiar|adres|tam|to miejsce|tamt/u', $p)) {
        $q = $db->prepare("SELECT tresc FROM sledztwo_rozmowy_wpisy WHERE rozmowa_id = ? AND kto = 'npc' ORDER BY id DESC");
        $q->execute([(int) $r['id']]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $było) if ($cel = sl_budynek_z_tekstu($okolica, (string) $było, $bid)) break;
    }
    if ($cel && $cel['id'] !== $bid) {
        return sl_jeden(["{$cel['nazwa']}. Wejście z poziomu {$cel['parter']}.", "Idź do {$cel['nazwa']} — wejście na poziomie {$cel['parter']}.",
            "{$cel['nazwa']}, {$cel['rodzaj']}. Wchodzi się z poziomu {$cel['parter']}, trafisz."]);
    }
    if (preg_match('/wind/u', $p)) return 'Winda? Na końcu korytarza, nie da się przeoczyć.';
    $plan = sl_plan_rozmowy($db, $s, $pule, $r);
    $plany = [$plan];
    if ($plan['poziom'] !== $plan['parter']) {
        $budynki = sl_budynki($db, $s);
        $plany[] = sl_plan($db, $s, $pule, $plan['budynek_id'] !== null ? ($budynki[$plan['budynek_id']] ?? null) : null, (int) $plan['parter']);
    }
    $ochrona = (bool) preg_match('/ochron|straż|strażnik|bezpiecze/u', $p);
    foreach ($plany as $pl) {
        foreach ($pl['pokoje'] as $lok) {
            $nazwa = mb_strtolower($lok['rodzaj']);
            $trafia = $ochrona ? (str_contains($nazwa, 'ochron') || $lok['dostep'] === 'straz') : false;
            if (!$ochrona) {
                foreach (preg_split('/\s+/u', $nazwa) as $slowo) {
                    if (mb_strlen($slowo) >= 4 && str_contains($p, mb_substr($slowo, 0, 5))) { $trafia = true; break; }
                }
            }
            if (!$trafia) continue;
            $gdzie = $pl['poziom'] === $plan['poziom'] ? 'tu, na tym poziomie' : "na parterze, poziom {$pl['poziom']}";
            return $ochrona && $lok['dostep'] === 'straz' && !str_contains($nazwa, 'ochron')
                ? "Strażnik stoi przy drzwiach lokalu {$lok['nr']}, {$gdzie}. Do niego idź."
                : "{$lok['rodzaj']}? Lokal {$lok['nr']}, {$gdzie}.";
        }
    }
    return $ochrona ? "Ochrona siedzi zwykle przy wejściu, na parterze — poziom {$plan['parter']}." : null;
}

// Zdawkowa kwestia bez powtórzeń: pomija to, co ten rozmówca już powiedział, i to, co w tej sprawie padło
// w ostatnich 6 godzinach; gdy wszystko zużyte — najdawniej użyta.
// Odpowiedzi bez AI składane z części: [wstępy, sedna, zakończenia]. Klucze: podejście_nie (nieudany test),
// nastroje (dopiski do zdawkowych kwestii). Pusty wstęp / zakończenie — część pominięta.
const SLEDZTWO_SKLADANKI = [
    'grozba_nie' => [
        ['', 'Grozisz mi?', 'Ty mi grozisz, puszko?', 'Hah. Straszysz?', 'Słuchaj no, blaszaku.', 'Ej, ej, spokojnie.', 'No proszę, groźny droid.'],
        ['Nakaz? Przynieś, to pogadamy.', 'Ale wiesz, że tu rządzą wielkie ślimaki, a nie twoje idealistyczne biuro?',
            'Huttowie mają tu więcej do powiedzenia niż jakiś droid z biura.', 'Ochrona tego budynku nie pracuje dla ciebie.',
            'Straszyć to możesz dzieciaki na dolnych poziomach.', 'Widział{em|am} groźniejsze tostery.',
            'Za takie gadanie ktoś tu cię rozbierze na części.', 'Twoje biuro nie ma tu żadnej władzy i dobrze o tym wiesz.'],
        ['', 'Do tego czasu spadaj.', 'Zjeżdżaj.', 'A teraz won.', 'Nie zabieraj mi czasu.', 'Następny proszę.', 'Wracaj, skąd przyszedłeś.']],
    'negocjacje_nie' => [
        ['', 'Wymiana?', 'Hm.', 'Ciekawe.', 'Słuchaj.', 'Interes, mówisz?'],
        ['Za mało.', 'Dyskrecja biura? Dobre sobie.', 'Nie robię interesów z droidami.', 'Za to nawet szczur z szybu by nie pisnął.',
            'Na Nar Shaddaa obietnice biura są warte tyle, co żetony z zamkniętego kasyna.', 'Twoja przysługa nie zapłaci mi czynszu.'],
        ['', 'Wróć z czymś lepszym.', 'Pogadamy, jak będziesz mieć kredyty.', 'Nie tak się robi interesy.', 'Szukaj frajera gdzie indziej.']],
    'urok_nie' => [
        ['', 'Ojej.', 'Słodko.', 'Daruj sobie.', 'Aha.'],
        ['Miły droid. Dalej nic nie wiem.', 'Słodzisz jak sprzedawca przyprawy.', 'Uprzejmości zostaw dla Huttów, oni to lubią.',
            'Komplementy od droida? Teraz to już widział{em|am} wszystko.', 'Nie kupię tego, choćbyś się świecił jak neon.'],
        ['', 'Następnym razem przynieś kredyty, nie komplementy.', 'Idź czarować kogoś innego.', 'Nie działa.']],
    'blef_nie' => [
        ['', 'Serio?', 'Hah.', 'Dobre.', 'No proszę.'],
        ['Nic nie wiesz. Gdybyś wiedział, nie pytałbyś.', 'Blefujesz. I to słabo.', 'To czemu pytasz, skoro wiesz?',
            'Tym blefem nie wygrałbyś nawet partyjki sabaka z dzieciakiem.', 'Twoje biuro wie tyle, co ja o waszych procedurach — nic.'],
        ['', 'Spróbuj z kimś głupszym.', 'Dobra próba, puszko.', 'Następnym razem lepiej się przygotuj.']],
    'autorytet_nie' => [
        ['', 'Procedura?', 'W imieniu biura, tak?', 'Hah.', 'Ojej, urzędnik.'],
        ['Biuro? Tu rządzą Huttowie, nie twoje biuro.', 'Procedura… Na Nar Shaddaa? Zabawne.', 'Wezwij sobie, kogo chcesz.',
            'Twoje papiery są tu warte mniej niż mokry kredyt.', 'Tu prawo pisze ten, kto płaci ochronie.'],
        ['', 'A teraz zjeżdżaj.', 'Nie mam czasu na urzędników.', 'Idź z tym do Huttów, może cię wysłuchają.']],
    'wrogi' => [['', 'Słuchaj, puszko.', 'Znowu ty?', 'No i czego?', 'Ech.'], [], ['', 'I spadaj.', 'Nie wracaj.', 'Koniec gadania.']],
    'obojetny' => [['', 'Hm.', 'No.', 'Wiesz co…', 'Słuchaj.'], [], ['', 'Tyle.', 'Nic więcej.', 'Idź pytać kogoś innego.']],
    'przestraszony' => [['', 'Ja… ', 'Słuchaj…', 'Ciszej!'], [], ['', 'Proszę, zostaw mnie.', 'Nie chcę kłopotów.']],
    'uprzejmy' => [['', 'Wybacz.', 'Przykro mi.', 'Hm.'], [], ['', 'Powodzenia.', 'Mam nadzieję, że go znajdziesz.']],
];

// Składa kwestię z części ($sedno — gotowe zdanie zamiast losowanego); unika dosłownych powtórzeń w rozmowie.
function sl_skladanka(PDO $db, string $klucz, int $rid, string $plec = 'm', ?string $sedno = null): string
{
    $c = SLEDZTWO_SKLADANKI[$klucz] ?? null;
    if (!$c) return (string) $sedno;
    // Archiwum sprawy: żadna kwestia nie pada drugi raz dosłownie; sedno, które już ktoś powiedział, dostaje inny wstęp.
    $q = $db->prepare('SELECT * FROM sledztwo_rozmowy WHERE id = ?');
    $q->execute([$rid]);
    $r = $q->fetch() ?: null;
    [$arch] = $r ? sl_archiwum_dla($db, $r, 200) : [[]];
    $bylo = [];
    foreach ($arch as $w) $bylo[$w['tresc']] = $w;
    $wynik = '';
    $rdzen = '';
    for ($proba = 0; $proba < 12; $proba++) {
        $rdzen = $sedno ?? ($c[1] ? $c[1][random_int(0, count($c[1]) - 1)] : '');
        $wstep = $c[0][random_int(0, count($c[0]) - 1)];
        $koniec = $c[2][random_int(0, count($c[2]) - 1)];
        $wynik = trim(preg_replace('/\s+/u', ' ', ($wstep !== '' ? $wstep . ' ' : '') . $rdzen . ($koniec !== '' ? ' ' . $koniec : '')));
        if (!isset($bylo[$wynik])) break;
    }
    // Nadal powtórka: jeśli to sedno mówił przed chwilą ktoś obok — rozmówca się do niego odwołuje.
    if (isset($bylo[$wynik]) || array_filter($arch, fn($w) => $w['relacja'] === 'OBOK' && $rdzen !== '' && str_contains($w['tresc'], $rdzen))) {
        $obok = array_values(array_filter($arch, fn($w) => $w['relacja'] === 'OBOK'));
        if ($obok && $rdzen !== '') {
            $wynik = ['Powiem to samo, co ten obok: ', 'Jak już słyszałeś od sąsiada: ', 'Kolega dobrze ci powiedział: ', 'Mówili ci już przecież: '][random_int(0, 3)]
                . mb_strtolower(mb_substr($rdzen, 0, 1)) . mb_substr($rdzen, 1);
        }
    }
    return sl_rodzaj($wynik, $plec === 'k' ? 'k' : 'm');
}

// Czy kwestia odsyła do rodzaju miejsca („spróbuj w kantynie”).
function sl_wskazuje_miejsce(string $k): bool
{
    $k = ' ' . mb_strtolower($k) . ' ';
    foreach (array_keys(SLEDZTWO_SLOWA_OKOLICY) as $slowo) if (str_contains($k, $slowo)) return true;
    return false;
}

// Kwestia odsyłająca do rodzaju miejsca dostaje prawdziwy budynek z rejonu (i trafia przez to do namiarów).
function sl_wskazowka_okolicy(PDO $db, array $s, array $pule, array $r, string $k): string
{
    if (!sl_wskazuje_miejsce($k)) return $k;
    $b = sl_budynek_z_tekstu(sl_okolica($db, $s, $pule), $k, $r['lokalizacja_id'] !== null ? (int) $r['lokalizacja_id'] : null);
    if (!$b) return $k;
    return rtrim($k) . ' ' . sl_jeden(["Mówię o {$b['nazwa']}, wejście z poziomu {$b['parter']}.", "Chodzi o {$b['nazwa']} — wejście na poziomie {$b['parter']}.",
        "{$b['nazwa']}, poziom {$b['parter']}, jakbyś pytał."]);
}

// $sito — opcjonalny filtr kwestii (np. odrzuca te, które wskazują miejsce nieistniejące w rejonie).
function sl_kwestia_szumu(PDO $db, string $nastroj, int $rozmowaId = 0, int $sledztwoId = 0, ?callable $sito = null): string
{
    $q = $db->prepare('SELECT tresc FROM gen_kwestie_szumu WHERE nastroj = ?');
    $q->execute([$nastroj]);
    $l = $q->fetchAll(PDO::FETCH_COLUMN);
    if ($sito) $l = array_values(array_filter($l, $sito));
    if (!$l) $l = $db->query('SELECT tresc FROM gen_kwestie_szumu')->fetchAll(PDO::FETCH_COLUMN);
    if (!$l) return 'Nie wiem.';
    $uzyte = [];
    if ($rozmowaId || $sledztwoId) {
        $q = $db->prepare("SELECT w.tresc, MAX(w.id) AS ost FROM sledztwo_rozmowy_wpisy w JOIN sledztwo_rozmowy r ON r.id = w.rozmowa_id
            WHERE w.kto = 'npc' AND (w.rozmowa_id = ? OR (r.sledztwo_id = ? AND w.kiedy_utc > ?)) GROUP BY w.tresc");
        $q->execute([$rozmowaId, $sledztwoId, sl_utc(sl_teraz() - 6 * 3600)]);
        foreach ($q->fetchAll() as $x) $uzyte[$x['tresc']] = (int) $x['ost'];
    }
    $wolne = array_values(array_filter($l, fn($t) => !isset($uzyte[$t])));
    if ($wolne) $sedno = (string) $wolne[random_int(0, count($wolne) - 1)];
    else { usort($l, fn($a, $b) => ($uzyte[$a] ?? 0) <=> ($uzyte[$b] ?? 0)); $sedno = (string) $l[0]; }
    return $rozmowaId && random_int(1, 100) <= 60 ? sl_skladanka($db, $nastroj, $rozmowaId, 'm', $sedno) : $sedno;
}

// ---------- wiedza NPC o otoczeniu: budynek, piętro, parter, ochrona, pora ----------

// Plan piętra, na którym toczy się rozmowa (z rozmowy: budynek, poziom).
function sl_plan_rozmowy(PDO $db, array $s, array $pule, array $r): array
{
    $budynki = sl_budynki($db, $s);
    $bid = $r['lokalizacja_id'] !== null ? (int) $r['lokalizacja_id'] : null;
    return sl_plan($db, $s, $pule, $bid !== null ? ($budynki[$bid] ?? null) : null, (int) $r['poziom']);
}

// Lokale piętra w skrócie: „4101 Recepcja, 4102 Hol (strzeżony), …”.
function sl_lokale_tekst(array $plan, int $limit = 16): string
{
    $l = [];
    foreach (array_slice($plan['pokoje'], 0, $limit) as $p) {
        $l[] = "{$p['nr']} {$p['rodzaj']}" . ($p['dostep'] === 'straz' ? ' (strażnik przy drzwiach)' : ($p['dostep'] === 'nakaz' ? ' (wstęp służbowy)' : ''));
    }
    if (count($plan['pokoje']) > $limit) $l[] = 'i ' . (count($plan['pokoje']) - $limit) . ' innych';
    return implode(', ', $l);
}

// Słowa w pytaniu / kwestii → preset budynku (kantyna, hangar…).
const SLEDZTWO_SLOWA_OKOLICY = ['kantyn' => 'kantyna', 'knajp' => 'kantyna', 'bar ' => 'kantyna', 'barze' => 'kantyna', 'klub' => 'kantyna',
    'hangar' => 'hangar', 'warsztat' => 'hangar', 'lądowisk' => 'ladowisko', 'port' => 'ladowisko', 'kasyn' => 'kasyno', 'hotel' => 'hotel',
    'sklep' => 'sklep', 'targ' => 'targ', 'bazar' => 'targ', 'biur' => 'biuro', 'magazyn' => 'magazyn', 'skład' => 'magazyn', 'blok' => 'mieszkalny'];

// Budynki rejonu (skupiska) z presetem i poziomem wejścia — każdy mieszkaniec zna je z widzenia.
function sl_okolica(PDO $db, array $s, array $pule): array
{
    $l = [];
    foreach (sl_budynki($db, $s) as $b) {
        $p = sl_profil($pule, $b);
        $l[] = ['id' => (int) $b['id'], 'nazwa' => (string) $b['nazwa'], 'kod' => $p['kod'], 'rodzaj' => $p['nazwa'], 'parter' => (int) $p['parter']];
    }
    return $l;
}

// Budynek rejonu, o który chodzi w tekście: po nazwie albo po słowie rodzaju (kantyna, hangar…). Pomija $pomin (bieżący budynek),
// chyba że to jedyny pasujący. Null — nie ma takiego w rejonie.
function sl_budynek_z_tekstu(array $okolica, string $tekst, ?int $pomin = null): ?array
{
    $t = ' ' . mb_strtolower($tekst) . ' ';
    foreach ($okolica as $b) {
        $nazwa = mb_strtolower(trim((string) preg_replace('/[„”"]/u', '', $b['nazwa'])));
        foreach (preg_split('/\s+/u', $nazwa) as $slowo) {
            if (mb_strlen($slowo) >= 6 && !in_array($slowo, ['kantyna', 'hangar', 'hotel', 'sklep', 'magazyn', 'warsztat', 'droidów'], true)
                && str_contains($t, mb_substr($slowo, 0, 5))) return $b;
        }
    }
    foreach (SLEDZTWO_SLOWA_OKOLICY as $slowo => $kod) {
        if (!str_contains($t, $slowo)) continue;
        $pas = array_values(array_filter($okolica, fn($b) => $b['kod'] === $kod));
        $inne = array_values(array_filter($pas, fn($b) => $b['id'] !== $pomin));
        if ($inne || $pas) return ($inne ?: $pas)[0];
    }
    return null;
}

// Tekst „CO WIESZ O OTOCZENIU” dla NPC: każdy, kto tu jest, zna budynek, swoje piętro, parter i ochronę.
function sl_scena(PDO $db, array $s, array $pule, array $plan, ?array $m, ?string $pokoj): string
{
    $t = [];
    $t[] = "- Budynek: {$plan['budynek']} ({$plan['preset_nazwa']}); parter na poziomie {$plan['parter']}, najwyższy poziom {$plan['gora']}."
        . " Rozmowa toczy się na poziomie {$plan['poziom']}" . ($pokoj && $pokoj !== 'korytarz' ? ", w lokalu {$pokoj}." : ', na korytarzu przy windzie.');
    $t[] = "- Na tym poziomie są lokale: " . sl_lokale_tekst($plan) . '. Między lokalami biegnie korytarz z windą.';
    $term = array_keys(sl_terminale($plan));
    if ($term) $t[] = '- Terminale na tym poziomie: ' . implode(', ', $term) . ($plan['terminal_korytarz'] ? " (publiczny {$plan['terminal_korytarz']} na korytarzu)" : '') . '.';
    if ($plan['poziom'] !== $plan['parter']) {
        $budynki = sl_budynki($db, $s);
        $parter = sl_plan($db, $s, $pule, $plan['budynek_id'] !== null ? ($budynki[$plan['budynek_id']] ?? null) : null, (int) $plan['parter']);
        $t[] = "- Na parterze (poziom {$plan['parter']}): " . sl_lokale_tekst($parter, 10) . '.';
    }
    $strzez = array_values(array_filter($plan['pokoje'], fn($p) => $p['dostep'] === 'straz'));
    $t[] = $strzez ? '- Ochrona: strażnicy stoją przy drzwiach lokali ' . implode(', ', array_column($strzez, 'nr')) . '; szefostwo ochrony siedzi zwykle w biurze ochrony albo przy wejściu na parterze.'
        : '- Ochrona budynku: zwykle przy wejściu na parterze albo w biurze ochrony, jeśli budynek je ma.';
    $t[] = '- Teraz jest ' . sl_data_gry(sl_teraz()) . ' (czas gry, dzień|miesiąc|rok).';
    if ($m && $m['rola_w_miejscu'] === 'pracownik') {
        $lok = $pokoj ? sl_lokal($plan, $pokoj) : null;
        $t[] = "- Twoje miejsce pracy znasz na wylot: {$m['miejsce']}." . ($lok && $lok['opis_szumu'] ? " Na co dzień: {$lok['opis_szumu']}" : '')
            . ' Wiesz, kto tu zwykle bywa, kiedy są zmiany i do kogo iść z problemem (przełożony, ochrona) — ogólnikami; nazwisk nie wymyślasz.';
    }
    $okolica = sl_okolica($db, $s, $pule);
    if ($okolica) {
        $t[] = '- Okolica (budynki w tym rejonie — znasz je z widzenia; innych nie wymyślasz): '
            . implode('; ', array_map(fn($b) => "{$b['nazwa']} ({$b['rodzaj']}, wejście na poziomie {$b['parter']})", $okolica)) . '.';
    }
    $t[] = '- O okolicy (budynek, piętro, ochrona, gdzie co jest, jakie lokale są w rejonie) mówisz swobodnie, jak każdy, kto tu bywa —'
        . ' to nie są tajemnice. Odsyłasz tylko do miejsc z tej listy.';
    return implode("\n", $t);
}

// ---------- metryki NPC (migracja 089): kim jest rozmówca i co wie o osobie ze sprawy ----------

// {m|k} → forma męska (też dla 'n') albo żeńska.
function sl_rodzaj(string $t, string $plec): string
{
    return preg_replace_callback('/\{([^{}|]*)\|([^{}|]*)\}/u', fn($m) => $plec === 'k' ? $m[2] : $m[1], $t);
}

// „Udajesz <biernik>” — gatunek w bierniku wg płci.
const SLEDZTWO_BIERNIK = [
    'czlowiek' => ['człowieka', 'kobietę'], 'twilek' => ['Twi\'leka', 'Twi\'lekankę'], 'rodianin' => ['Rodianina', 'Rodiankę'],
    'bothanin' => ['Bothanina', 'Bothankę'], 'trandoshanin' => ['Trandoshanina', 'Trandoshankę'], 'wookiee' => ['Wookieego', 'Wookieego (kobietę)'],
    'gand' => ['Ganda', 'Gandkę'], 'droid' => ['droida', 'droida'],
];

// Status osoby ze sprawy (wg zgłoszenia — tak o niej mówi okolica), w bierniku: „Znał{eś|aś} <status> <imię>”.
const SLEDZTWO_STATUS_A = [
    1 => 'zaginion{ego|ą} po rozstaniu', 2 => 'zamordowan{ego|ą}', 3 => 'porwan{ego|ą}', 4 => 'poszkodowan{ego|ą}',
    5 => 'okradzion{ego|ą}', 6 => 'szantażowan{ego|ą}', 7 => 'podejrzewan{ego|ą} o donos', 8 => 'oszukan{ego|ą}',
    9 => 'zamieszan{ego|ą} w nieudany przemyt', 10 => 'zadłużon{ego|ą}', 11 => 'zamieszan{ego|ą} w porachunki', 12 => 'zaginion{ego|ą}',
];
const SLEDZTWO_POZIOM_ZNAJOMOSCI = [0 => 'wcale', 1 => 'słabo', 2 => 'dobrze', 3 => 'bardzo dobrze'];
// Archetypy, które w miejscu spotkania pracują (reszta tylko bywa).
const SLEDZTWO_ARCHETYPY_PRACUJA = ['barman', 'krupier', 'ochroniarz', 'mechanik', 'handlarz', 'straznik'];

function sl_slownik(PDO $db, string $rodzaj): string
{
    static $cache = [];
    if (!isset($cache[$rodzaj])) {
        $q = $db->prepare('SELECT wartosc FROM gen_metryka WHERE rodzaj = ? ORDER BY id');
        $q->execute([$rodzaj]);
        $cache[$rodzaj] = $q->fetchAll(PDO::FETCH_COLUMN) ?: ['—'];
    }
    return (string) sl_jeden($cache[$rodzaj]);
}

// Osoba, której dotyczy sprawa (A), i fakty o niej z bazy: [postać A, status, fakty wg poziomu 1–3].
// Fakt: ['ty' => do promptu (2. osoba), 'ja' => do odpowiedzi bez AI (1. osoba), 'temat' => wyglad|okolica|gdzie|kto|nawyk|klopoty].
// {m|k} w tekstach — płeć rozmówcy (sl_rodzaj); formy osoby ze sprawy są już wstawione.
function sl_fakty_o_osobie(PDO $db, array $s, array $pule): array
{
    $q = $db->prepare("SELECT * FROM sledztwo_postacie WHERE sledztwo_id = ? AND kod IN ('A','B') ORDER BY kod");
    $q->execute([(int) $s['id']]);
    $os = [];
    foreach ($q->fetchAll() as $p) $os[$p['kod']] = $p;
    $a = $os['A'] ?? null;
    if (!$a) return [null, 'zaginion{ego|ą}', [1 => [], 2 => [], 3 => []]];
    $q = $db->prepare('SELECT COALESCE(s.zdarzenie_zgloszone_id, s.zdarzenie_id) AS zg,
        (SELECT f.tagi FROM sledztwo_fazy f WHERE f.sledztwo_id = s.id ORDER BY f.pozycja DESC LIMIT 1) AS tagi FROM sledztwa s WHERE s.id = ?');
    $q->execute([(int) $s['id']]);
    $sp = $q->fetch() ?: [];
    $q = $db->prepare('SELECT faza_nr, nazwa FROM sledztwo_miejsca WHERE sledztwo_id = ? ORDER BY faza_nr');
    $q->execute([(int) $s['id']]);
    $miejsca = [];
    foreach ($q->fetchAll() as $m) $miejsca[(int) $m['faza_nr']] = (string) $m['nazwa'];

    $imie = trim($a['imie'] . ' ' . ($a['nazwisko'] ?? ''));
    $k = $a['plec'] === 'k';
    [$go, $jego, $byl, $mial] = $k ? ['ją', 'jej', 'była', 'miała'] : ['go', 'jego', 'był', 'miał'];
    $gat = $pule['gatunki'][$a['gatunek_kod']]['nazwa'] ?? $a['gatunek_kod'];
    $arch = mb_strtolower($pule['archetypy'][$a['archetyp_kod']]['nazwa'] ?? 'ktoś z dolnych poziomów');
    $w = $a['wiek'] !== null ? (int) $a['wiek'] : null;
    $wiek = $w === null ? 'trudno powiedzieć, ile ma lat' : ($w < 20 ? 'na oko ' . ($k ? 'nastolatka' : 'nastolatek')
        : ($w >= 60 ? 'na oko po sześćdziesiątce' : 'na oko ' . ['dwudziestka', 'trzydziestka', 'czterdziestka', 'pięćdziesiątka'][min(3, intdiv($w, 10) - 2)]));
    $nawyk = '';
    foreach (explode(';', (string) $a['charakter']) as $c) if (str_starts_with(trim($c), 'nawyk:')) $nawyk = trim(substr(trim($c), 6));
    $tagi = sl_tagi((string) ($sp['tagi'] ?? ''));
    $klopoty = array_values(array_intersect_key(['dlug' => 'długi u niewłaściwych ludzi', 'lup' => 'coś cennego, czego nie powinien' . ($k ? 'a' : '') . ' mieć',
        'nagroda' => 'list gończy albo nagrodę za głowę', 'przemyt' => 'nieudany kurs przemytniczy', 'szantaz' => 'kogoś, kto trzymał ' . $go . ' w szachu'], array_flip($tagi)));

    $f = [1 => [], 2 => [], 3 => []];
    $f[1][] = ['temat' => 'wyglad', 'ty' => "{$imie} to {$gat}, {$wiek}; z wyglądu {$arch}.", 'ja' => "{$imie}? {$gat}, {$wiek}. Z wyglądu {$arch}."];
    $f[1][] = ['temat' => 'okolica', 'ty' => "{$imie} bywał" . ($k ? 'a' : '') . " w tej okolicy; kojarzysz {$go} z widzenia.",
        'ja' => "Kojarzę {$go} z widzenia — bywał" . ($k ? 'a' : '') . ' w tej okolicy.'];
    if (!empty($miejsca[1])) $f[2][] = ['temat' => 'gdzie', 'ty' => "Często bywał" . ($k ? 'a' : '') . " tu: {$miejsca[1]}.",
        'ja' => "Często {$go} widywano tu: {$miejsca[1]}."];
    if (!empty($miejsca[3])) $f[2][] = ['temat' => 'gdzie', 'ty' => "Ostatnio kręcił" . ($k ? 'a' : '') . " się koło: {$miejsca[3]}.",
        'ja' => "Ostatnio kręcił" . ($k ? 'a' : '') . " się koło: {$miejsca[3]}."];
    if (isset($os['B'])) {
        $b = trim($os['B']['imie'] . ' ' . ($os['B']['nazwisko'] ?? ''));
        $f[2][] = ['temat' => 'kto', 'ty' => "Często widywał{eś|aś} {$go} w towarzystwie: {$b}.", 'ja' => "Często widywał{em|am} {$go} w towarzystwie: {$b}."];
    }
    if ($nawyk !== '') $f[2][] = ['temat' => 'nawyk', 'ty' => "Pamiętasz {$jego} nawyk: {$nawyk}.", 'ja' => "Pamiętam {$jego} nawyk: {$nawyk}."];
    if ($klopoty) $f[3][] = ['temat' => 'klopoty', 'ty' => "Wiesz, że {$mial} kłopoty: " . implode('; ', $klopoty) . '.',
        'ja' => ucfirst($mial) . ' kłopoty: ' . implode('; ', $klopoty) . '.'];
    if (!empty($miejsca[7])) $f[3][] = ['temat' => 'klopoty', 'ty' => "Wiesz, że {$mial} swoje miejsce na „przeczekanie”: {$miejsca[7]}.",
        'ja' => "Miał" . ($k ? 'a' : '') . " swoje miejsce na przeczekanie: {$miejsca[7]}."];
    if ($w !== null) $f[3][] = ['temat' => 'wyglad', 'ty' => "Wiesz, że ma dokładnie {$w} lat.", 'ja' => "Ma dokładnie {$w} lat, wiem na pewno."];
    return [$a, SLEDZTWO_STATUS_A[(int) ($sp['zg'] ?? 12)] ?? 'zaginion{ego|ą}', $f];
}

// Oś czasu sprawy (deterministyczna, z faz i miejsc): kiedy i gdzie widziano osobę ze sprawy przed zgłoszeniem.
// Dni przed zgłoszeniem jak w datach przedmiotów (13 − pozycja fazy). Faza 7 (kryjówka) i fazy ukryte — „ukryte”.
function sl_os_czasu(PDO $db, array $s): array
{
    static $cache = [];
    if (isset($cache[(int) $s['id']])) return $cache[(int) $s['id']];
    $q = $db->prepare('SELECT utworzono_utc FROM sledztwa WHERE id = ?');
    $q->execute([(int) $s['id']]);
    $baza = sl_z_utc((string) $q->fetchColumn()) ?? sl_teraz();
    $q = $db->prepare('SELECT faza_nr, pozycja, jawnosc FROM sledztwo_fazy WHERE sledztwo_id = ?');
    $q->execute([(int) $s['id']]);
    $fazy = [];
    foreach ($q->fetchAll() as $f) $fazy[(int) $f['faza_nr']] = $f;
    $q = $db->prepare('SELECT id, faza_nr, lokalizacja_id, poziom, pokoj, nazwa FROM sledztwo_miejsca WHERE sledztwo_id = ? ORDER BY faza_nr');
    $q->execute([(int) $s['id']]);
    $os = [];
    foreach ($q->fetchAll() as $m) {
        $nr = (int) $m['faza_nr'];
        if ($nr === 2) continue; // miejsce zgłoszenia
        $dni = max(1, 13 - (int) ($fazy[$nr]['pozycja'] ?? 6));
        $t = $baza - $dni * 86400 - sl_hash_int((int) $s['seed'], 1800, 50000, 'os', (string) $m['id']);
        $os[] = ['t' => $t, 'miejsce' => (string) $m['nazwa'], 'lokalizacja_id' => $m['lokalizacja_id'] !== null ? (int) $m['lokalizacja_id'] : null,
            'poziom' => (int) $m['poziom'], 'ukryte' => $nr === 7 || ($fazy[$nr]['jawnosc'] ?? 'jawna') === 'ukryta', 'id' => (int) $m['id']];
    }
    usort($os, fn($x, $y) => $y['t'] <=> $x['t']);
    return $cache[(int) $s['id']] = $os;
}

// Data dla rozmówcy: „24|11|16 około 21:00”.
function sl_kiedy_mowione(int $t): string
{
    return sl_data_gry($t, false) . ' około ' . date('H', $t) . ':00';
}

// Co rozmówca widział na osi czasu: zna dobrze (2) — dwa ostatnie jawne wydarzenia; bardzo dobrze (3) — wszystkie;
// w swoim budynku — jawne wydarzenia z szansą (pracownik / bywalec 70 %, reszta 30 %). Fakty jak w sl_fakty_o_osobie.
function sl_widzial(PDO $db, array $s, array $m, array $r): array
{
    [$a] = sl_fakty_o_osobie($db, $s, sl_pule($db));
    if (!$a || (int) ($m['postac_id'] ?? 0) === (int) $a['id']) return [];
    $go = $a['plec'] === 'k' ? 'ją' : 'go';
    $poz = (int) $m['znajomosc_poziom'];
    $bid = $r['lokalizacja_id'] !== null ? (int) $r['lokalizacja_id'] : null;
    $szansa = in_array($m['rola_w_miejscu'], ['pracownik', 'bywalec'], true) ? 70 : 30;
    $jawne = 0;
    $wynik = [];
    foreach (sl_os_czasu($db, $s) as $e) {
        $widzial = $poz === 3
            || (!$e['ukryte'] && $poz === 2 && $jawne < 2)
            || (!$e['ukryte'] && $bid !== null && $e['lokalizacja_id'] === $bid && sl_hash_int((int) $s['seed'], 1, 100, 'widzial', (string) $m['token'], (string) $e['id']) <= $szansa);
        if (!$e['ukryte']) $jawne++;
        if (!$widzial) continue;
        $kiedy = sl_kiedy_mowione($e['t']);
        $wynik[] = ['temat' => 'kiedy', 't' => $e['t'], 'ty' => "Widział{eś|aś} {$go} {$kiedy} — {$e['miejsce']}, poziom {$e['poziom']}.",
            'ja' => "Widział{em|am} {$go} {$kiedy} — {$e['miejsce']}, poziom {$e['poziom']}."];
    }
    return $wynik;
}

// Metryka kontaktu: z bazy albo nowa (deterministyczna dla tokenu). Null bez migracji 089.
// $kontakt — z sl_kontakty; $plan — piętro, na którym droid go spotkał.
function sl_metryka_zapewnij(PDO $db, array $s, array $pule, array $plan, array $kontakt): ?array
{
    try {
        $q = $db->prepare('SELECT * FROM sledztwo_metryki WHERE sledztwo_id = ? AND token = ?');
        $q->execute([(int) $s['id'], $kontakt['token']]);
        if ($m = $q->fetch()) return $m;
    } catch (PDOException $e) { return null; }

    $postac = null;
    if ($kontakt['postac_id']) {
        $q = $db->prepare('SELECT * FROM sledztwo_postacie WHERE id = ?');
        $q->execute([(int) $kontakt['postac_id']]);
        $postac = $q->fetch() ?: null;
    }
    [$a, , $fakty] = sl_fakty_o_osobie($db, $s, $pule);
    sl_ziarno((int) $s['seed'], 'metryka', $kontakt['token']);

    // Tożsamość: postać fabuły — z bazy; przechodzień — losowana z pul gatunku.
    if ($postac) {
        [$imie, $nazwisko, $przydomek, $gat, $plec, $wiek] = [$postac['imie'], $postac['nazwisko'], $postac['przydomek'], $postac['gatunek_kod'], $postac['plec'], $postac['wiek']];
        $archKod = $postac['archetyp_kod'];
    } else {
        $gat = 'czlowiek';
        foreach ($pule['gatunki'] as $k => $g) if ($g['nazwa'] === $kontakt['gatunek']) $gat = $k;
        $plec = (string) $kontakt['plec'];
        $wiek = $gat === 'droid' ? null : sl_los(18, 72);
        $wybierz = function (string $rodzaj) use ($pule, $gat, $plec): ?string {
            foreach ([$gat, 'czlowiek'] as $g) {
                $l = array_values(array_filter($pule['imiona'], fn($i) => $i['rodzaj'] === $rodzaj && $i['gatunek_kod'] === $g
                    && ($i['plec'] === $plec || $i['plec'] === 'n' || $plec === 'n')));
                if ($l) return sl_jeden($l)['wartosc'];
            }
            return null;
        };
        $imie = $wybierz('imie') ?? 'Bezimienny';
        $nazwisko = $gat === 'droid' ? null : $wybierz('nazwisko');
        $przydomek = null;
        $archKod = $kontakt['archetyp_kod'] ?? null;
    }
    $plecR = $plec === 'k' ? 'k' : 'm';
    $planeta = 'Nar Shaddaa';
    $lok = sl_lokal($plan, (string) $kontakt['pokoj']);

    // Pochodzenie: część urodzona na miejscu, reszta przybyła X lat temu.
    $q = $db->prepare('SELECT planeta, waga FROM gen_pochodzenie WHERE gatunek_kod = ? OR gatunek_kod IS NULL');
    $q->execute([$gat]);
    $pochodzenie = $q->fetchAll();
    $tutaj = $gat !== 'droid' && sl_procent(35);
    $planetaUr = $tutaj || !$pochodzenie ? $planeta : (string) sl_wazony($pochodzenie)['planeta'];
    $lat = $tutaj ? null : max(1, sl_los(1, max(1, ($wiek ?? 20) - 14)));

    // Rola w miejscu i zajęcie.
    $archNazwa = $archKod ? ($pule['archetypy'][$archKod]['nazwa'] ?? null) : null;
    if (!empty($kontakt['straznik'])) { $rola = 'pracownik'; $zajecie = 'strażnik przy drzwiach lokalu ' . $kontakt['pokoj']; }
    elseif ($archKod && in_array($archKod, SLEDZTWO_ARCHETYPY_PRACUJA, true)) { $rola = 'pracownik'; $zajecie = mb_strtolower((string) $archNazwa); }
    elseif ($archNazwa) { $rola = 'bywalec'; $zajecie = mb_strtolower((string) $archNazwa); }
    else { $rola = sl_jeden(['przechodzien', 'gosc', 'mieszkaniec']); $zajecie = sl_slownik($db, 'zajecie'); }
    $staz = sl_los(1, max(1, min(25, $lat ?? (($wiek ?? 30) - 16))));
    $miejsce = trim(($plan['budynek'] ?? 'Kompleks') . ', poziom ' . $plan['poziom'] . ($lok ? ", lokal {$lok['nr']} ({$lok['rodzaj']})" : ', korytarz'));

    // Znajomość z osobą ze sprawy: kluczowi wiedzą dużo, ludzie z miejsc fabuły — trochę, szum — zwykle nic.
    $kod = $postac['kod'] ?? null;
    $fabularny = $lok && !empty($lok['miejsce_id']);
    $budynekFabuly = false;
    if (!$fabularny && $plan['budynek_id'] !== null) {
        $q = $db->prepare('SELECT 1 FROM sledztwo_miejsca WHERE sledztwo_id = ? AND lokalizacja_id = ? LIMIT 1');
        $q->execute([(int) $s['id'], (int) $plan['budynek_id']]);
        $budynekFabuly = (bool) $q->fetchColumn();
    }
    $poziom = match (true) {
        $kod === 'A' => 3,
        in_array($kod, ['B', 'V', 'Z'], true) => 3,
        in_array($kod, ['W', 'S'], true) => 2,
        $fabularny => (int) sl_wazony([['p' => 0, 'waga' => 30], ['p' => 1, 'waga' => 40], ['p' => 2, 'waga' => 25], ['p' => 3, 'waga' => 5]])['p'],
        $budynekFabuly => (int) sl_wazony([['p' => 0, 'waga' => 60], ['p' => 1, 'waga' => 30], ['p' => 2, 'waga' => 10]])['p'],
        default => sl_procent(12) ? 1 : 0,
    };
    if (!$a) $poziom = 0;
    $jak = null;
    if ($poziom > 0 && $kod !== 'A') {
        $q = $db->prepare('SELECT opis FROM gen_znajomosci WHERE poziom_min <= ? AND poziom_max >= ? ORDER BY id');
        $q->execute([$poziom, $poziom]);
        $l = $q->fetchAll(PDO::FETCH_COLUMN);
        $jak = $l ? sl_rodzaj((string) sl_jeden($l), $plecR) : null;
        if ($kod === 'B' || $kod === 'V') $jak = 'to bliska ci osoba (wasza relacja jest w sprawie)';
    }
    $chce = $poziom === 0 ? 'nie' : (in_array($kod, ['V'], true) ? 'chetnie'
        : (string) sl_wazony([['c' => 'chetnie', 'waga' => 30], ['c' => 'niechetnie', 'waga' => 50], ['c' => 'nie', 'waga' => 20]])['c']);
    $wiedza = [];
    for ($i = 1; $i <= min(3, $poziom); $i++) foreach ($fakty[$i] as $f) $wiedza[] = '- ' . sl_rodzaj($f['ty'], $plecR);
    $charakter = $postac ? (string) $postac['charakter'] : 'cecha: ' . sl_slownik($db, 'cecha') . '; mowa: ' . sl_slownik($db, 'mowa');

    $w = ['sledztwo_id' => (int) $s['id'], 'token' => $kontakt['token'], 'postac_id' => $postac ? (int) $postac['id'] : null,
        'imie' => $imie, 'nazwisko' => $nazwisko, 'przydomek' => $przydomek, 'gatunek_kod' => $gat, 'plec' => $plec, 'wiek' => $wiek,
        'planeta_urodzenia' => $planetaUr, 'urodzony_tutaj' => $tutaj ? 1 : 0, 'lat_na_planecie' => $lat, 'miejsce' => mb_substr($miejsce, 0, 200),
        'rola_w_miejscu' => $rola, 'zajecie' => mb_substr($zajecie, 0, 120), 'staz_lat' => $staz,
        'mieszka' => 'poziom ' . sl_los(5, 110) . ', ' . sl_slownik($db, 'mieszkanie'),
        'rodzina' => sl_rodzaj(sl_slownik($db, 'rodzina'), $plecR), 'codziennosc' => sl_rodzaj(sl_slownik($db, 'codziennosc'), $plecR),
        'charakter' => sl_rodzaj(mb_substr($charakter, 0, 255), $plecR), 'stosunek_droidy' => sl_slownik($db, 'droidy'),
        'opinia_imperium' => sl_slownik($db, 'imperium'), 'opinia_huttowie' => sl_rodzaj(sl_slownik($db, 'huttowie'), $plecR),
        'ulubione' => sl_rodzaj(sl_slownik($db, 'ulubione'), $plecR), 'zmartwienie' => sl_slownik($db, 'zmartwienie'),
        'sekret' => sl_rodzaj(sl_slownik($db, 'sekret'), $plecR), 'plotka' => sl_rodzaj(sl_slownik($db, 'plotka'), $plecR),
        'znajomosc_poziom' => $poziom, 'znajomosc_jak' => $jak, 'chce_mowic' => $chce,
        'wiedza_o_osobie' => implode("\n", $wiedza), 'utworzono_utc' => sl_utc(sl_teraz())];
    $kol = array_keys($w);
    $db->prepare('INSERT IGNORE INTO sledztwo_metryki (' . implode(', ', $kol) . ') VALUES (' . implode(', ', array_fill(0, count($kol), '?')) . ')')
        ->execute(array_values($w));
    $q = $db->prepare('SELECT * FROM sledztwo_metryki WHERE sledztwo_id = ? AND token = ?');
    $q->execute([(int) $s['id'], $kontakt['token']]);
    return $q->fetch() ?: null;
}

// Pola promptu z metryki: kim (biernik), życiorys (opcje z pochodzeniem i pracą), metryka, znajomość z osobą ze sprawy.
// Karta EotE rozmówcy opisana słowami dla modelu: jak cechy i umiejętności przekładają się na zachowanie w rozmowie.
const SLEDZTWO_OPIS_CECH = [
    'krzepa' => ['wątł{y|a}, unikasz siłowych rozwiązań', 'przeciętnej postury', 'silnie zbudowan{y|a}, nie boisz się przepychanek', 'potężn{y|a} — inni schodzą ci z drogi'],
    'zwinnosc' => ['niezgrabn{y|a}', 'zwyczajnie sprawn{y|a}', 'zwinn{y|a} i szybk{i|a}', 'niezwykle zręczn{y|a}'],
    'intelekt' => ['niezbyt bystr{y|a} — łatwo cię zagadać i nabrać', 'przeciętnie bystr{y|a}', 'inteligentn{y|a}, szybko łapiesz, o co chodzi', 'wybitnie inteligentn{y|a} — trudno cię oszukać'],
    'spryt' => ['naiwn{y|a}, słabo czytasz ludzi', 'masz zwykły uliczny rozum', 'cwan{y|a} — od razu wyczuwasz, kto czego chce', 'wyjątkowo przebiegł{y|a}'],
    'wola' => ['łatwo ulegasz presji i groźbom', 'masz przeciętnie silną wolę', 'tward{y|a} — groźby słabo na ciebie działają', 'nie do złamania'],
    'prezencja' => ['bez charyzmy, w rozmowie niepewn{y|a}', 'zwyczajnie towarzysk{i|a}', 'masz charyzmę, umiesz przekonywać', 'masz ogromną charyzmę — ludzie cię słuchają'],
];
const SLEDZTWO_OPIS_UMIEJETNOSCI = [
    'czujnosc' => 'zauważasz kłamstwa i blef', 'dyscyplina' => 'trzymasz nerwy na wodzy pod presją', 'opanowanie' => 'trudno wyprowadzić cię z równowagi',
    'oszustwo' => 'sam{|a} kłamiesz gładko', 'negocjacje' => 'umiesz się targować i nic nie dajesz za darmo', 'urok' => 'umiesz być czarując{y|a}',
    'zastraszanie' => 'umiesz grozić i lubisz to', 'ulica' => 'znasz półświatek Nar Shaddaa', 'percepcja' => 'dużo widzisz i zapamiętujesz',
    'komputery' => 'znasz się na komputerach', 'mechanika' => 'znasz się na maszynach', 'bijatyka' => 'umiesz się bić', 'przywodztwo' => 'umiesz rozkazywać',
];
function sl_karta_opis(array $k, string $plec): string
{
    $c = [];
    foreach (SLEDZTWO_OPIS_CECH as $cecha => $opisy) {
        $v = (int) ($k['cechy'][$cecha] ?? 2);
        $c[] = sl_rodzaj($opisy[max(0, min(3, $v - 1))], $plec);
    }
    $u = [];
    foreach ((array) ($k['umiejetnosci'] ?? []) as $nazwa => $x) {
        $r = (int) (((array) $x)['ranga'] ?? 0);
        if ($r >= 2 && isset(SLEDZTWO_OPIS_UMIEJETNOSCI[$nazwa])) $u[] = sl_rodzaj(SLEDZTWO_OPIS_UMIEJETNOSCI[$nazwa], $plec) . ($r >= 3 ? ' (bardzo dobrze)' : '');
    }
    return implode('; ', $c) . '.' . ($u ? ' Mocne strony: ' . implode('; ', $u) . '.' : '');
}

// $karta — karta EotE rozmówcy (sl_karta_rozmowcy); jej opis trafia do metryki.
function sl_metryka_pola(PDO $db, array $s, array $pule, array $m, ?array $karta = null, ?array $rozm = null): array
{
    $plec = $m['plec'] === 'k' ? 'k' : 'm';
    $r = fn(string $t) => sl_rodzaj($t, $plec);
    $planeta = 'Nar Shaddaa';
    $kim = (SLEDZTWO_BIERNIK[$m['gatunek_kod']][$plec === 'k' ? 1 : 0] ?? ('postać: ' . ($pule['gatunki'][$m['gatunek_kod']]['nazwa'] ?? $m['gatunek_kod'])))
        . ' o imieniu ' . trim($m['imie'] . ' ' . ($m['nazwisko'] ?? ''));
    $lata = fn(int $n) => $n === 1 ? '1 rok' : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20) ? "{$n} lata" : "{$n} lat");
    $z = [];
    if ($m['gatunek_kod'] === 'droid') {
        $z[] = "Zbudowano cię: {$m['planeta_urodzenia']}." . ($m['lat_na_planecie'] ? " Na {$planeta} trafiłeś " . $lata((int) $m['lat_na_planecie']) . ' temu.' : '');
    } elseif ((int) $m['urodzony_tutaj']) {
        $z[] = $r('Urodził{eś|aś} się na ') . $planeta . ($m['wiek'] ? ' ' . $lata((int) $m['wiek']) . ' temu.' : '.');
    } else {
        $z[] = $r('Urodził{eś|aś} się na ') . $m['planeta_urodzenia'] . ", a na {$planeta} " . $r('przybył{eś|aś} ') . $lata((int) $m['lat_na_planecie']) . ' temu.';
    }
    $staz = $m['staz_lat'] ? $lata((int) $m['staz_lat']) : 'niedawno';
    $z[] = match ($m['rola_w_miejscu']) {
        'pracownik' => $r('Zatrudnił{eś|aś} się tutaj') . " ({$m['miejsce']}) {$staz} temu; pracujesz jako {$m['zajecie']}.",
        'bywalec' => "Bywasz tutaj ({$m['miejsce']}) od {$staz}; zajmujesz się tym: {$m['zajecie']}.",
        'mieszkaniec' => "Mieszkasz w tym budynku od {$staz}; zarabiasz jako {$m['zajecie']}.",
        'gosc' => $r('Jesteś tu (') . $m['miejsce'] . ") tylko w swojej sprawie; na życie zarabiasz jako {$m['zajecie']}.",
        default => "Przechodzisz tędy ({$m['miejsce']}); na życie zarabiasz jako {$m['zajecie']}.",
    };
    $z[] = $m['codziennosc'];

    $met = [
        'Imię i nazwisko' => trim($m['imie'] . ' ' . ($m['nazwisko'] ?? '')) . ($m['przydomek'] ? " (przydomek „{$m['przydomek']}”)" : ''),
        'Gatunek' => $pule['gatunki'][$m['gatunek_kod']]['nazwa'] ?? $m['gatunek_kod'],
        'Płeć' => ['m' => 'mężczyzna', 'k' => 'kobieta'][$m['plec']] ?? 'nieokreślona',
        'Wiek' => $m['wiek'] !== null ? $lata((int) $m['wiek']) : 'nie dotyczy (droid)',
        'Pochodzenie' => (int) $m['urodzony_tutaj'] ? 'urodzon' . $r('{y|a}') . " na {$planeta}" : "{$m['planeta_urodzenia']}; na {$planeta} od " . $lata((int) $m['lat_na_planecie']),
        'Zajęcie' => $m['zajecie'] . ($m['staz_lat'] ? ' (od ' . $lata((int) $m['staz_lat']) . ')' : ''),
        'Mieszkasz' => $m['mieszka'], 'Rodzina' => $m['rodzina'], 'Charakter i sposób mówienia' => $m['charakter'],
        'Stosunek do droidów' => $m['stosunek_droidy'], 'O Imperium' => $m['opinia_imperium'], 'O Huttach' => $m['opinia_huttowie'],
        'Lubisz' => $m['ulubione'], 'Martwi cię' => $m['zmartwienie'],
        'Twój sekret (ukrywasz go)' => $m['sekret'], 'Plotka, którą chętnie powtarzasz' => $m['plotka'],
    ];
    if ($karta) $met['Z karty postaci (tak się zachowujesz)'] = sl_karta_opis($karta, $plec);
    $metryka = implode("\n", array_map(fn($k, $v) => "- {$k}: {$v}", array_keys($met), $met));

    [$a, $status] = sl_fakty_o_osobie($db, $s, $pule);
    $poz = (int) $m['znajomosc_poziom'];
    $osoba = $a ? sl_rodzaj($status, $a['plec'] === 'k' ? 'k' : 'm') . ' ' . trim($a['imie'] . ' ' . ($a['nazwisko'] ?? '')) : 'osobę, o którą pyta droid';
    $chce = ['chetnie' => 'Chcesz o tym mówić — ale tylko na pytania.', 'niechetnie' => 'Mówisz o tym niechętnie: trzeba cię przekonać, uspokoić albo zapłacić.',
        'nie' => 'Nie chcesz o tym mówić: zbywasz, wykręcasz się, a przyciśnięt' . $r('{y|a}') . ' — kłamiesz albo kończysz rozmowę.'][$m['chce_mowic']] ?? '';
    // Oś czasu: tylko to, co ten rozmówca widział; innych dat nie zna. Metryki sprzed osi czasu miały losową datę — wycięta.
    $widzial = $rozm ? sl_widzial($db, $s, $m, $rozm) : [];
    $wiedzaOs = trim((string) preg_replace('/^- Ostatni raz widział.*$/mu', '', (string) $m['wiedza_o_osobie']));
    $kiedyTekst = "\nKiedy i gdzie widział" . $r('{eś|aś}') . ' tę osobę (pamiętasz tylko to — innych dat i miejsc nie znasz; dziś jest '
        . sl_data_gry(sl_teraz(), false) . '):' . "\n" . ($widzial ? implode("\n", array_map(fn($w) => '- ' . $r($w['ty']), $widzial))
            : '- nie pamiętasz, kiedy ostatnio ' . ($a && $a['plec'] === 'k' ? 'ją' : 'go') . ' widział' . $r('{eś|aś}') . ' — i tak mówisz, jeśli ktoś pyta');
    if ($a && (int) ($m['postac_id'] ?? 0) === (int) $a['id']) {
        $znaj = "SPRAWA DOTYCZY CIEBIE\n- To ty jesteś osobą, o którą chodzi w sprawie. Trzymasz się tego, co wiesz o sprawie (niżej), i swojego zachowania.";
    } elseif ($poz === 0) {
        $znaj = $widzial
            ? "ZNAJOMOŚĆ Z OSOBĄ ZE SPRAWY: z imienia nie znasz " . $osoba . ', ale widział' . $r('{eś|aś}') . ' kogoś takiego.' . $kiedyTekst
                . "\n- O samej sprawie nic nie wiesz. Mówisz niechętnie, bez szczegółów ponad to, co wyżej."
            : "ZNAJOMOŚĆ Z OSOBĄ ZE SPRAWY: brak\n- Nie znasz " . $osoba . ' i nic o tej sprawie nie wiesz.'
            . "\n- Nie chcesz gadać z droidem: zbywasz go krótko, kreatywnie i w swoim stylu (np. „nie mam z tym nic wspólnego”,"
            . " „nakaz jest? jak nie, to spadaj”). Po 2–3 pytaniach kończysz rozmowę.";
    } else {
        $znaj = $r('ZNAJOMOŚĆ Z OSOBĄ ZE SPRAWY: znał{eś|aś} ') . $osoba . ' ' . SLEDZTWO_POZIOM_ZNAJOMOSCI[$poz]
            . ($m['znajomosc_jak'] ? " ({$m['znajomosc_jak']})" : '') . ".\nCo wiesz o tej osobie:\n" . ($wiedzaOs ?: '- niewiele ponad to, że istnieje')
            . $kiedyTekst . "\n- " . $chce . ($poz === 3 ? ' To, że wiesz dużo, nie znaczy, że chcesz się z tym ujawniać.' : '');
    }
    return ['kim' => $kim, 'zyciorys' => implode("\n", array_map(fn($x) => "- {$x}", $z)), 'metryka' => $metryka, 'znajomosc' => $znaj,
        'pelne_imie' => trim($m['imie'] . ' ' . ($m['nazwisko'] ?? '') . ($m['przydomek'] ? " „{$m['przydomek']}”" : ''))];
}

// Dane kluczowego NPC do szablonu npc_wypowiedz. Dopóki model Autor nie napisze historii i wiedzy (krok 2),
// wiedza powstaje ze szkieletu: fazy, w których szkicu występuje kod postaci, z zachowaniem wg jawności fazy.
// $m — metryka rozmówcy (migracja 089); bez niej pola metryki dostają ogólniki.
function sl_npc_dane(PDO $db, array $s, array $p, array $pule, array $r, ?array $m = null): array
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
        'V' => 'osoba, która zgłosiła sprawę biuru i wezwała droida',
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
    // VIP (zgłaszający, czeka w miejscu startowym): wprowadza droida w sprawę.
    [$start, $vip] = sl_start($db, $s);
    if ($vip && (int) $vip['id'] === (int) $p['id']) {
        $ost = $miejscaFaz[1] ?? $miejscaFaz[3] ?? null;
        $wiedza[] = "- ZGŁOSIŁEŚ TĘ SPRAWĘ biuru: {$sp['zgloszone']}. Dotyczy: " . ($imieKodu['A'] ?? 'osoby, którą znasz')
            . ". Wezwałeś droida tutaj (" . ($start['nazwa'] ?? 'miejsce zgłoszenia') . "). Ostatnio widziałeś tę osobę: " . ($ost ?? 'kilka cykli temu w okolicy')
            . '. [TO MÓWISZ CHĘTNIE: gdy droid pyta, co się stało, sam streszczasz w 2–4 zdaniach, kogo dotyczy sprawa,'
            . ' co według ciebie się stało, kiedy i gdzie ostatnio widziałeś tę osobę]';
        $zachowanie[] = '- Jesteś zgłaszającym: chcesz, żeby droid się tym zajął, i wprowadzasz go w sprawę'
            . ($kod && $kod === $sp['ukrywa_kod'] ? ' — ale trzymasz się wersji ze zgłoszenia i ukrywasz prawdę.' : '.');
    }
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
    $gatunek = $pule['gatunki'][$p['gatunek_kod']]['nazwa'] ?? $p['gatunek_kod'];
    $mp = $m ? sl_metryka_pola($db, $s, $pule, $m, sl_karta_rozmowcy($pule, $s, $r, $p['id'] ? $p : null, $m), $r) : ['kim' => "postać: {$gatunek} o imieniu {$pelne}", 'zyciorys' => "- Jesteś {$archetyp} na Nar Shaddaa.",
        'metryka' => "- Imię i nazwisko: {$pelne}\n- Gatunek: {$gatunek}", 'znajomosc' => 'ZNAJOMOŚĆ Z OSOBĄ ZE SPRAWY: patrz „CO WIESZ O SPRAWIE”.', 'pelne_imie' => $pelne];
    $pelne = $mp['pelne_imie'];
    if (!$kod && $m) $miejsce['nazwa'] = $m['miejsce'];
    return [
        'swiat' => ['planeta' => 'Nar Shaddaa'],
        'scena' => ['opis' => sl_scena($db, $s, $pule, sl_plan_rozmowy($db, $s, $pule, $r), $m, (string) $r['pokoj'])],
        'npc' => ['kim' => $mp['kim'], 'zyciorys' => $mp['zyciorys'], 'metryka' => $mp['metryka'], 'znajomosc' => $mp['znajomosc'],
            'pelne_imie' => $pelne, 'gatunek' => $pule['gatunki'][$p['gatunek_kod']]['nazwa'] ?? $p['gatunek_kod'],
            'plec' => ['m' => 'mężczyzna', 'k' => 'kobieta', 'n' => 'nieokreślona'][$p['plec']] ?? 'nieokreślona',
            'wiek' => $p['wiek'] !== null ? (string) $p['wiek'] : 'nieznany', 'archetyp' => $archetyp,
            'charakter' => $ch['cecha'] ?: 'zwyczajny', 'nawyk' => $ch['nawyk'] ?: 'brak szczególnych nawyków', 'mowa' => $ch['mowa'] ?: 'zwyczajnie',
            'historia' => $p['historia'] ?: "Jesteś {$archetyp} na Nar Shaddaa. W sprawie jesteś: {$rola}.",
            'wiedza' => $p['wiedza'] ?: implode("\n", $wiedza), 'nastawienie' => SLEDZTWO_NASTAWIENIE[$r['nastroj']] ?? 'obojętne'],
        'miejsce' => $miejsce,
        'faza' => ['zachowanie' => implode("\n", $zachowanie)],
    ];
}

// Odprawa VIP-a bez AI (gdy łącze z modułem językowym nie działa): pierwsza odpowiedź streszcza zgłoszenie.
function sl_vip_odprawa(PDO $db, array $s, array $postac, int $pytan): ?string
{
    [$start, $vip] = sl_start($db, $s);
    if (!$vip || (int) $vip['id'] !== (int) $postac['id']) return null;
    if ($pytan > 0) return ['Powiedziałem już wszystko, co wiem. Resztę masz w teczce.', 'Nie wiem więcej, naprawdę. Znajdź go.',
        'Sprawdź miejsca, o których mówiłem. Ja tu poczekam.'][$pytan % 3];
    $q = $db->prepare("SELECT p.imie, p.nazwisko, p.plec, COALESCE(zg.nazwa, z.nazwa) AS zgloszone FROM sledztwo_postacie p
        JOIN sledztwa s ON s.id = p.sledztwo_id JOIN sledztwo_zdarzenia z ON z.id = s.zdarzenie_id
        LEFT JOIN sledztwo_zdarzenia zg ON zg.id = s.zdarzenie_zgloszone_id WHERE p.sledztwo_id = ? AND p.kod = 'A'");
    $q->execute([(int) $s['id']]);
    $a = $q->fetch();
    if (!$a) return null;
    $q = $db->prepare('SELECT nazwa, poziom FROM sledztwo_miejsca WHERE sledztwo_id = ? AND faza_nr IN (1, 3) ORDER BY faza_nr LIMIT 1');
    $q->execute([(int) $s['id']]);
    $m = $q->fetch();
    $kto = trim($a['imie'] . ' ' . ($a['nazwisko'] ?? ''));
    $go = $a['plec'] === 'k' ? 'ją' : 'go';
    return "Wreszcie. Chodzi o {$kto}. Zgłosiłem to jako: " . mb_strtolower((string) $a['zgloszone']) . '. '
        . ($m ? "Ostatni raz widziałem {$go} w {$m['nazwa']}, poziom {$m['poziom']}. " : '')
        . 'Resztę masz w teczce od biura.';
}

// Jedna tura: (polecenie operatora) → pytanie droida → odpowiedź rozmówcy. Kluczowi NPC — AI; reszta — bez AI.
// ---------- podejścia w rozmowie: groźba, negocjacje, urok, blef, autorytet (test EotE ze skutkami) ----------

// [cecha droida, umiejętność droida, cecha rozmówcy, umiejętność rozmówcy, nazwa,
//  co droid wplata w wypowiedź, kwestia droida bez AI, nastrój po udanym, nastrój po nieudanym,
//  zachowanie NPC po udanym, po nieudanym, kwestie NPC bez AI po nieudanym]
const SLEDZTWO_PODEJSCIA_ROZMOWY = [
    'grozba' => ['wola', 'zastraszanie', 'wola', 'dyscyplina', 'Groźba (Zastraszanie)',
        'wpleć uprzejmą, ale stanowczą groźbę konsekwencji (wniosek o nakaz, zatrzymanie, wezwanie ochrony) — bez wulgaryzmów',
        'Uprzedzam uprzejmie: odmowa współpracy z droidem śledczym skończy się wnioskiem o nakaz i wizytą ochrony.',
        'przestraszony', 'wrogi',
        'groźba podziałała: boisz się konsekwencji, mówisz więcej niż zwykle — zdradzasz jeden konkretny fakt z tego, co wiesz o osobie ze sprawy',
        'groźba cię nie rusza albo rozzłościła: odpyskowujesz, nie ustępujesz',
        ['Grozisz mi, puszko? Spróbuj.', 'Nakaz? Przynieś, to pogadamy. Do tego czasu spadaj.', 'Straszyć to możesz dzieci na dolnych poziomach.']],
    'negocjacje' => ['prezencja', 'negocjacje', 'prezencja', 'negocjacje', 'Negocjacje',
        'zaproponuj uczciwą wymianę: przysługę biura, dyskrecję albo pominięcie rozmówcy w raporcie — w zamian za informację',
        'Proponuję wymianę: informacja w zamian za dyskrecję biura i pominięcie Pana nazwiska w raporcie.',
        'uprzejmy', 'obojetny',
        'propozycja ci odpowiada: dobijacie targu — zdradzasz jeden konkretny fakt z tego, co wiesz o osobie ze sprawy',
        'propozycja cię nie przekonuje: chcesz więcej albo nie ufasz biuru',
        ['Za mało. Wróć z czymś lepszym.', 'Dyskrecja biura? Dobre sobie.', 'Nie robię interesów z droidami.']],
    'urok' => ['prezencja', 'urok', 'prezencja', 'opanowanie', 'Urok',
        'bądź wyjątkowo życzliwy i ciepły: okaż zrozumienie, pochwal rozmówcę, zjednaj go sobie',
        'Rozumiem, że to dla Pana trudne. Bardzo doceniam każdą pomoc — nawet drobiazg może uratować komuś życie.',
        'uprzejmy', 'obojetny',
        'droid cię zjednał: rozluźniasz się i chętniej mówisz — zdradzasz jeden konkretny fakt z tego, co wiesz o osobie ze sprawy',
        'uprzejmości droida cię nie ruszają, a nawet trochę irytują',
        ['Daruj sobie te uprzejmości.', 'Miły droid. Dalej nic nie wiem.', 'Słodzisz jak sprzedawca przyprawy.']],
    'blef' => ['spryt', 'oszustwo', 'intelekt', 'czujnosc', 'Blef (Oszustwo)',
        'zablefuj: daj do zrozumienia, że biuro już sporo wie i rozmówca tylko potwierdza (nie podawaj konkretów, których nie znasz)',
        'Biuro i tak już to wie. Proszę tylko potwierdzić — oszczędzi to nam obu czasu.',
        'przestraszony', 'wrogi',
        'uwierzyłeś, że droid już wie: potwierdzasz jeden konkretny fakt z tego, co wiesz o osobie ze sprawy',
        'przejrzałeś blef: wiesz, że droid nic nie ma, i dajesz mu to odczuć',
        ['Nic nie wiesz. Gdybyś wiedział, nie pytałbyś.', 'Blefujesz. Słabo.', 'To czemu pytasz, skoro wiesz?']],
    'autorytet' => ['prezencja', 'przywodztwo', 'wola', 'dyscyplina', 'Autorytet (Przywództwo)',
        'powołaj się na autorytet biura i procedurę śledczą: urzędowo, stanowczo, ale grzecznie wezwij do współpracy',
        'W imieniu biura detektywistycznego wzywam do współpracy. To standardowa procedura — proszę odpowiedzieć na pytanie.',
        'obojetny', 'wrogi',
        'urzędowy ton działa: uznajesz, że lepiej współpracować — zdradzasz jeden konkretny fakt z tego, co wiesz o osobie ze sprawy',
        'biuro detektywistyczne nie ma tu żadnej władzy i dobrze o tym wiesz',
        ['Biuro? Tu rządzą Huttowie, nie twoje biuro.', 'Procedura… Na Nar Shaddaa? Zabawne.', 'Wezwij sobie kogo chcesz.']],
];
const SLEDZTWO_CHEC = ['nie' => 0, 'niechetnie' => 1, 'chetnie' => 2];

// Karta rozmówcy do testów: postać fabuły — jej karta EotE; przechodzień — cechy gatunku ± 1 i losowe rangi (stałe dla tokenu).
function sl_karta_rozmowcy(array $pule, array $s, array $r, ?array $postac, ?array $m): array
{
    if ($postac && ($k = json_decode((string) ($postac['karta_eote'] ?? ''), true)) && isset($k['cechy'])) return $k;
    $gat = $m['gatunek_kod'] ?? 'czlowiek';
    $g = $pule['gatunki'][$gat] ?? ['krzepa' => 2, 'zwinnosc' => 2, 'intelekt' => 2, 'spryt' => 2, 'wola' => 2, 'prezencja' => 2];
    $tok = (string) $r['kontakt'];
    $cechy = [];
    foreach (['krzepa', 'zwinnosc', 'intelekt', 'spryt', 'wola', 'prezencja'] as $c) {
        $cechy[$c] = max(1, min(5, (int) ($g[$c] ?? 2) + sl_hash_int((int) $s['seed'], -1, 1, 'cecha', $tok, $c)));
    }
    $um = [];
    foreach (['dyscyplina', 'negocjacje', 'opanowanie', 'czujnosc'] as $u) $um[$u] = ['ranga' => sl_hash_int((int) $s['seed'], 0, 2, 'um', $tok, $u)];
    return ['cechy' => $cechy, 'umiejetnosci' => $um];
}

// Wszystko, co rozmówca może powiedzieć o osobie ze sprawy, w 1. osobie: fakty wg poziomu znajomości + to, co widział.
function sl_fakty_rozmowcy(PDO $db, array $s, array $pule, array $r, ?array $m): array
{
    if (!$m) return [];
    [$a, , $fakty] = sl_fakty_o_osobie($db, $s, $pule);
    if (!$a || (int) ($m['postac_id'] ?? 0) === (int) $a['id']) return [];
    $plec = $m['plec'] === 'k' ? 'k' : 'm';
    $l = [];
    foreach (sl_widzial($db, $s, $m, $r) as $w) $l[] = ['temat' => 'kiedy', 'ja' => sl_rodzaj($w['ja'], $plec)];
    for ($i = 1; $i <= min(3, (int) $m['znajomosc_poziom']); $i++) foreach ($fakty[$i] as $f) $l[] = ['temat' => $f['temat'], 'ja' => sl_rodzaj($f['ja'], $plec)];
    return $l;
}

// Fakt do ujawnienia (po udanym teście) — pierwszy, który jeszcze nie padł w tej rozmowie.
function sl_fakt_do_ujawnienia(PDO $db, array $s, array $pule, array $r, ?array $m): ?string
{
    $q = $db->prepare("SELECT tresc FROM sledztwo_rozmowy_wpisy WHERE rozmowa_id = ? AND kto = 'npc'");
    $q->execute([(int) $r['id']]);
    $padlo = implode("\n", $q->fetchAll(PDO::FETCH_COLUMN));
    foreach (sl_fakty_rozmowcy($db, $s, $pule, $r, $m) as $f) if (!str_contains($padlo, $f['ja'])) return $f['ja'];
    return null;
}

// Bez AI: pytanie o osobę ze sprawy → odpowiedź z faktów rozmówcy wg tematu (kiedy widział, gdzie bywała, z kim,
// jak wygląda, kłopoty). Null — pytanie nie dotyczy osoby. Rozmówca, który nie chce mówić, zbywa.
function sl_odpowiedz_o_osobie(PDO $db, array $s, array $pule, array $r, ?array $m, string $pytanie): ?string
{
    if (!$m) return null;
    $p = mb_strtolower($pytanie);
    [$a] = sl_fakty_o_osobie($db, $s, $pule);
    if (!$a) return null;
    $imie = mb_strtolower((string) $a['imie']);
    $oOsobie = (bool) preg_match('/\b(go|ją|jego|jej|on|ona|niego|niej|nim|zagin|ofiar|porwan|zamordowan)\b/u', $p) || ($imie !== '' && str_contains($p, $imie));
    $temat = match (true) {
        (bool) preg_match('/kiedy|ostatni|ostatnio|widział|widziała|widziałeś|widziałaś/u', $p) => 'kiedy',
        (bool) preg_match('/z kim|kto (z nim|z nią)|znajom|przyjaci|towarzyst|spotyka/u', $p) => 'kto',
        (bool) preg_match('/kłopot|dług|wrog|ukryw|kryjówk|przeczek|bał się|bała się/u', $p) => 'klopoty',
        (bool) preg_match('/jak wygląd|wygląda|ile ma lat|kim (on|ona) jest|kim jest|co to za/u', $p) => 'wyglad',
        (bool) preg_match('/gdzie|bywał|bywała|chodził|chodziła|podziewa|szukać/u', $p) => 'gdzie',
        (bool) preg_match('/znasz|znał|znała|wiesz coś|co wiesz/u', $p) => 'wyglad',
        default => null,
    };
    if (!$temat || (!$oOsobie && $temat !== 'kiedy')) return null;
    $go = $a['plec'] === 'k' ? 'ją' : 'go';
    $plec = $m['plec'] === 'k' ? 'k' : 'm';
    if ($m['chce_mowic'] === 'nie') return sl_rodzaj(sl_jeden(['Nie będę o tym gadać.', 'Nic ci nie powiem, puszko.', 'A skąd mam wiedzieć? Spadaj.']), $plec);
    $fakty = array_values(array_filter(sl_fakty_rozmowcy($db, $s, $pule, $r, $m), fn($f) => $f['temat'] === $temat
        || ($temat === 'gdzie' && $f['temat'] === 'kiedy')));
    if (!$fakty) {
        return sl_rodzaj(match ($temat) {
            'kiedy' => "Nie pamiętam, kiedy {$go} ostatnio widział{em|am}. Nie pilnuję ludzi.",
            'kto' => 'Nie wiem, z kim się zadawał' . ($a['plec'] === 'k' ? 'a' : '') . '.',
            'klopoty' => 'O jakichś kłopotach nic nie wiem.',
            'gdzie' => 'Nie wiem, gdzie bywał' . ($a['plec'] === 'k' ? 'a' : '') . '. Nie moja sprawa.',
            default => "Nie znam {$go} za dobrze.",
        }, $plec);
    }
    $q = $db->prepare("SELECT tresc FROM sledztwo_rozmowy_wpisy WHERE rozmowa_id = ? AND kto = 'npc'");
    $q->execute([(int) $r['id']]);
    $padlo = implode("\n", $q->fetchAll(PDO::FETCH_COLUMN));
    foreach ($fakty as $f) if (!str_contains($padlo, $f['ja'])) return ($m['chce_mowic'] === 'niechetnie' ? sl_jeden(['No… ', 'Hm. ', 'Dobra. ']) : '') . $f['ja'];
    return 'Już mówił' . ($plec === 'k' ? 'am' : 'em') . ', co wiem.';
}

// $procedura: 'wlasna' — pytania operatora droid zadaje jako swoje; 'jawna' — może mówić, że pyta operator.
// ---------- namiary (migracja 091): miejsca wskazane przez rozmówców → pole wyboru celu ----------

// Szuka w wypowiedzi rozmówcy wskazówek do miejsc, które naprawdę istnieją: numer lokalu w tym budynku
// („lokal 2001”), nazwa lokalu z tego piętra albo parteru przy słowach kierunku („recepcja jest na parterze”),
// nazwa miejsca ze sprawy. Wymyślone miejsca się nie liczą. Zwraca dopisane namiary (nazwy).
function sl_namiary(PDO $db, array $s, array $pule, array $r, string $tekst): array
{
    $budynki = sl_budynki($db, $s);
    $bid = $r['lokalizacja_id'] !== null ? (int) $r['lokalizacja_id'] : null;
    $budynek = $bid !== null ? ($budynki[$bid] ?? null) : null;
    $plan = sl_plan($db, $s, $pule, $budynek, (int) $r['poziom']);
    $male = mb_strtolower($tekst);
    $znalezione = [];
    $dodaj = function (?int $b, int $poziom, array $lok, ?string $nazwa = null) use (&$znalezione, $budynki) {
        $bud = $b !== null ? ($budynki[$b]['nazwa'] ?? 'budynek') : 'Kompleks';
        $znalezione[($b ?? 0) . "|{$poziom}|{$lok['nr']}"] = [$b, $poziom, (string) $lok['nr'],
            ($nazwa ?? $lok['rodzaj']) . " — {$bud}, poziom {$poziom}, lokal {$lok['nr']}"];
    };
    // 1. Numery lokali w tym budynku (numer = poziom + dwie cyfry).
    if (preg_match_all('/\b(\d{3,5})\b/u', $tekst, $m)) {
        foreach (array_slice(array_unique($m[1]), 0, 4) as $nr) {
            $poz = intdiv((int) $nr, 100);
            if ($poz < 1 || $poz > SLEDZTWO_POZIOM_MAX) continue;
            $pl = $poz === (int) $plan['poziom'] ? $plan : sl_plan($db, $s, $pule, $budynek, $poz);
            if ($lok = sl_lokal($pl, (string) $nr)) $dodaj($bid, $poz, $lok);
        }
    }
    // 2. Nazwy lokali z tego piętra i parteru — tylko gdy rozmówca mówi, jak trafić.
    if (preg_match('/poziom|piętr|parter|lokal|końcu|obok|naprzeciw|wind|korytarz|idź|znajdziesz|na dole|na górze/u', $male)) {
        $plany = [$plan];
        if ($plan['poziom'] !== $plan['parter']) $plany[] = sl_plan($db, $s, $pule, $budynek, (int) $plan['parter']);
        foreach ($plany as $pl) {
            foreach ($pl['pokoje'] as $lok) {
                $nazwa = mb_strtolower($lok['rodzaj']);
                if (in_array($nazwa, ['korytarz', 'pokój gościnny', 'stragan', 'mieszkanie', 'gabinet', 'lokal'], true)) continue;
                $pierwsze = preg_split('/\s+/u', $nazwa)[0];
                if (mb_strlen($pierwsze) >= 4 && str_contains($male, mb_substr($pierwsze, 0, max(4, mb_strlen($pierwsze) - 2)))) {
                    $dodaj($bid, (int) $pl['poziom'], $lok);
                    break;
                }
            }
        }
    }
    // 3a. Budynki rejonu wymienione z nazwy (wejście = korytarz parteru).
    foreach (sl_okolica($db, $s, $pule) as $b) {
        $nazwa = mb_strtolower(trim((string) preg_replace('/[„”"]/u', '', $b['nazwa'])));
        $czysty = (string) preg_replace('/[„”"]/u', '', $male);
        if ($b['id'] === $bid || mb_strlen($nazwa) < 5 || !str_contains($czysty, $nazwa)) continue;
        $znalezione["{$b['id']}|{$b['parter']}|korytarz"] = [$b['id'], $b['parter'], 'korytarz', "{$b['nazwa']} — wejście, poziom {$b['parter']}"];
    }
    // 3. Miejsca ze sprawy wymienione z nazwy.
    $q = $db->prepare('SELECT nazwa, lokalizacja_id, poziom, pokoj FROM sledztwo_miejsca WHERE sledztwo_id = ?');
    $q->execute([(int) $s['id']]);
    foreach ($q->fetchAll() as $mj) {
        $nazwa = trim((string) preg_replace('/\s*\(.*\)$/u', '', (string) $mj['nazwa']));
        if (mb_strlen($nazwa) < 6 || !str_contains($male, mb_strtolower($nazwa))) continue;
        $b = $mj['lokalizacja_id'] !== null ? (int) $mj['lokalizacja_id'] : null;
        if ($b !== null && !isset($budynki[$b])) continue;
        $dodaj($b, (int) $mj['poziom'], ['nr' => (string) $mj['pokoj'], 'rodzaj' => $nazwa], $nazwa);
    }
    if (!$znalezione) return [];
    $skad = sl_opis_rozmowcy($r) . ', ' . sl_data_gry(sl_teraz());
    $wstaw = $db->prepare('INSERT IGNORE INTO sledztwo_namiary (sledztwo_id, klucz, nazwa, lokalizacja_id, poziom, pokoj, skad, kiedy_utc)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $nowe = [];
    foreach ($znalezione as $klucz => [$b, $poz, $nr, $opis]) {
        $wstaw->execute([(int) $s['id'], $klucz, mb_substr($opis, 0, 160), $b, $poz, $nr, mb_substr($skad, 0, 255), sl_utc(sl_teraz())]);
        if ($wstaw->rowCount()) $nowe[] = $opis;
    }
    return $nowe;
}

function sl_namiary_lista(PDO $db, array $s): array
{
    try {
        $q = $db->prepare('SELECT id, nazwa, lokalizacja_id, poziom, pokoj, skad FROM sledztwo_namiary WHERE sledztwo_id = ? ORDER BY id');
        $q->execute([(int) $s['id']]);
        return array_map(fn($n) => ['id' => (int) $n['id'], 'nazwa' => $n['nazwa'], 'lokalizacja_id' => $n['lokalizacja_id'] !== null ? (int) $n['lokalizacja_id'] : null,
            'poziom' => (int) $n['poziom'], 'pokoj' => $n['pokoj'], 'skad' => $n['skad']], $q->fetchAll());
    } catch (PDOException $e) { return []; }
}

// Komunikaty dla gracza, gdy rozmowa idzie bez modułu językowego (lag, zerwanie) — po fabularnemu.
// Techniczny powód trafia do dziennika jako wpis systemowy (widzi go MG, nie gracz).
const SLEDZTWO_ZAKLOCENIA = [
    'Zakłócenia na łączu — część transmisji odtworzona z bufora, zapis może być niepełny.',
    'Awaria nadajnika DT-4 — droid przeszedł na tryb awaryjny, odpowiedzi rozmówcy streszcza moduł zapasowy.',
    'Moduł transkrypcji przestał nadawać — zapis uzupełniany fragmentami z pamięci podręcznej.',
    'Spadek mocy na głównej baterii — droid ogranicza przetwarzanie mowy do minimum.',
    'Interferencja z ekranowania budynku — sygnał rwie się, transkrypcja zgrubna.',
    'Przeciążenie przekaźnika sektorowego — opóźnienie łącza, odpowiedzi w uproszczonej transkrypcji.',
    'Restart modułu rozpoznawania mowy — chwilowo działa tylko słownik podstawowy.',
];

// $podejscie: klucz SLEDZTWO_PODEJSCIA_ROZMOWY (groźba, negocjacje…) — test EotE przed wypowiedzią droida.
function sl_rozmowa_tura(PDO $db, array $s, array $u, int $rid, string $polecenie, string $procedura = 'wlasna', string $podejscie = ''): array
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
    // To samo pytanie do tej samej osoby drugi raz — nie (także z wcześniejszych rozmów z nią).
    $nowe = trim(mb_substr($polecenie, 0, 300));
    if ($nowe !== '') {
        foreach (sl_juz_padlo($db, (int) $s['id'], (string) $r['kontakt'], 'operator') as $było) {
            if (sl_to_samo($nowe, (string) $było)) throw new SledztwoBlad('Ten rozmówca już słyszał to pytanie — odpowiedź jest w zapisie. Zapytaj o coś innego.');
        }
    }
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
        // Metryka (089): z nią przez AI mówi każdy rozmówca — także przechodzień i strażnik.
        $metryka = null;
        try {
            $q = $db->prepare('SELECT * FROM sledztwo_metryki WHERE sledztwo_id = ? AND token = ?');
            $q->execute([(int) $s['id'], (string) $r['kontakt']]);
            $metryka = $q->fetch() ?: null;
        } catch (PDOException $e) { /* brak migracji 089 */ }
        $ai = ($postac && ($postac['waga'] === 'kluczowy' || SLEDZTWO_AI_DLA_POBOCZNYCH)) || ($metryka && SLEDZTWO_AI_DLA_WSZYSTKICH);
        // Przechodzień bez wiersza w sledztwo_postacie: „postać” z metryki.
        $npc = $postac ?? ($metryka ? ['id' => 0, 'kod' => null, 'waga' => 'poboczny', 'imie' => $metryka['imie'], 'nazwisko' => $metryka['nazwisko'],
            'przydomek' => $metryka['przydomek'], 'gatunek_kod' => $metryka['gatunek_kod'], 'plec' => $metryka['plec'], 'wiek' => $metryka['wiek'],
            'archetyp_kod' => null, 'charakter' => $metryka['charakter'], 'miejsce_id' => null, 'historia' => null, 'wiedza' => null] : null);
        $q = $db->prepare('SELECT COALESCE(zg.nazwa, z.nazwa) FROM sledztwa s JOIN sledztwo_zdarzenia z ON z.id = s.zdarzenie_id
            LEFT JOIN sledztwo_zdarzenia zg ON zg.id = s.zdarzenie_zgloszone_id WHERE s.id = ?');
        $q->execute([(int) $s['id']]);
        $zgloszenie = (string) $q->fetchColumn();
        $awaria = false;
        $powodAwarii = '';

        // 0. Podejście: test droida przeciw rozmówcy (każda kolejna próba w tej rozmowie +1 kość trudności, maks. +2).
        $pd = SLEDZTWO_PODEJSCIA_ROZMOWY[$podejscie] ?? null;
        $test = null;
        if ($pd) {
            $q = $db->prepare("SELECT COUNT(*) FROM sledztwo_rozmowy_wpisy WHERE rozmowa_id = ? AND kto = 'system' AND tresc LIKE 'Test:%'");
            $q->execute([$rid]);
            $plus = min(2, (int) $q->fetchColumn());
            $dk = sl_karta_droida($db, (string) $s['konto_login']);
            $nk = sl_karta_rozmowcy($pule, $s, $r, $postac, $metryka);
            $test = sl_test_eote((int) ($dk['cechy'][$pd[0]] ?? 1), (int) (((array) ($dk['umiejetnosci'] ?? []))[$pd[1]]['ranga'] ?? 0),
                (int) ($nk['cechy'][$pd[2]] ?? 2), (int) (((array) ($nk['umiejetnosci'] ?? []))[$pd[3]]['ranga'] ?? 0), $plus);
            sl_wpis($db, $rid, 'system', "Test: {$pd[4]} — " . ($test['udana'] ? 'UDANY' : 'NIEUDANY') . " ({$test['pula']}"
                . ($plus ? ", +{$plus} za wcześniejsze próby" : '') . "; sukcesy netto {$test['sukcesy']}, przewagi {$test['przewagi']}"
                . ($test['triumf'] ? ', TRIUMF' : '') . ($test['rozpacz'] ? ', ROZPACZ' : '') . ').');
            // Skutek trwały: udany test podnosi chęć rozmówcy do mówienia (triumf — od razu „chętnie”).
            if ($metryka && $test['udana']) {
                $nowa = $test['triumf'] ? 'chetnie' : array_search(min(2, (SLEDZTWO_CHEC[$metryka['chce_mowic']] ?? 1) + 1), SLEDZTWO_CHEC, true);
                $db->prepare('UPDATE sledztwo_metryki SET chce_mowic = ? WHERE id = ?')->execute([$nowa, (int) $metryka['id']]);
                $metryka['chce_mowic'] = $nowa;
            }
            $polecenie = $polecenie !== ''
                ? $polecenie . " [Podejście: {$pd[4]} — słowa operatora SĄ tym podejściem: przekaż je rozmówcy (możesz wygładzić wulgaryzmy do tonu droida), nie dodawaj własnej formuły.]"
                : '[Podejście: ' . $pd[5] . '.]';
        }

        $slyszal = $pytan === 0 ? sl_slyszal_wczesniej($db, $r) : null;
        $juzDroid = sl_juz_padlo($db, (int) $s['id'], (string) $r['kontakt'], 'droid');
        $koniecPytan = false;

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
                    'rozmowa' => ['etap' => ($pytan ? 'Rozmowa trwa — nie przedstawiaj się ponownie.'
                        : ($slyszal ? "To Twoja pierwsza wypowiedź do tej osoby, ale stała obok i słyszała, jak przed chwilą przedstawiałeś się i wypytywałeś {$slyszal}."
                            . ' Nie powtarzaj pełnej formuły z zasady 2: krótko nawiąż (np. „Słyszał Pan zapewne, o co pytałem…”), podaj tylko swoje oznaczenie i zadaj pytanie.'
                            : 'To Twoja pierwsza wypowiedź — przedstaw się zgodnie z zasadą 2.'))
                        . ($juzDroid ? ' Tych pytań już tej osobie zadałeś (także we wcześniejszych rozmowach) — nie powtarzaj ich ani ich sensu: '
                            . implode(' | ', array_slice($juzDroid, -8)) : ''),
                        'polecenie' => $polecenie !== '' ? $polecenie : 'Brak — zadaj kolejne pytanie zgodnie z zasadą 4.',
                        'procedura' => SLEDZTWO_PROCEDURY[$procedura] ?? SLEDZTWO_PROCEDURY['wlasna'],
                        'przebieg' => $przebiegDroida],
                ], (int) $s['id'])['wypowiedz'];
                $pytanieAi = true;
            } catch (AiBlad $e) { $awaria = true; $powodAwarii = $e->getMessage(); }
        }
        if ($pytanie === null) {
            $bezPodejscia = $pd ? trim((string) preg_replace('/\s*\[Podejście:.*\]$/us', '', $polecenie)) : $polecenie;
            $pytanie = $pd && $bezPodejscia === '' && $pytan > 0 ? $pd[6]
                : trim(sl_droid_zastepczo($pytan === 0, $f, $oznaczenie, $operator, $bezPodejscia, $pytan, $procedura === 'jawna', $slyszal, $juzDroid)
                    . ($pd && $bezPodejscia === '' ? ' ' . $pd[6] : ''));
            if ($pytanie === '') { $pytanie = "Dziękuję, {$f['wolacz']}. Nie mam więcej pytań."; $koniecPytan = true; }
        }
        sl_wpis($db, $rid, 'droid', $pytanie, $pytanieAi);

        // 2. Rozmówca odpowiada.
        $odp = null;
        $nastroj = (string) $r['nastroj'];
        $konczy = false;
        if ($ai && !$awaria) {
            try {
                $dane = sl_npc_dane($db, $s, $npc, $pule, $r, $metryka);
                $imie = $dane['npc']['pelne_imie'];
                $dane['droid'] = ['oznaczenie' => $oznaczenie];
                [, $unikaj] = sl_archiwum_dla($db, $r);
                $dane['rozmowa'] = ['pytanie' => $pytanie, 'przebieg' => $ostatnie
                    ? implode("\n", array_map(fn($w) => ($w['kto'] === 'droid' ? "Droid {$oznaczenie}: " : "Ty ({$imie}): ") . $w['tresc'], $ostatnie))
                    : '(to początek rozmowy)',
                    'unikaj' => $unikaj];
                if ($slyszal) $dane['faza']['zachowanie'] .= "\n- " . sl_rodzaj('Przed chwilą stał{eś|aś} obok i słyszał{eś|aś}', (string) ($o['plec'] ?? 'm')) . ", jak ten droid przedstawiał się i wypytywał kogoś ({$slyszal}) o sprawę: {$zgloszenie}. Wiesz już, czego chce.";
                if ($test) {
                    $dane['faza']['zachowanie'] .= "\n- TERAZ droid zastosował wobec ciebie: {$pd[4]}. Wynik testu: "
                        . ($test['udana'] ? 'UDANY — ' . $pd[9] : 'NIEUDANY — ' . $pd[10]) . '.'
                        . ($test['triumf'] ? ' TRIUMF: ustępujesz całkowicie i mówisz wszystko, co wiesz o osobie ze sprawy.' : '')
                        . ($test['rozpacz'] ? ' ROZPACZ: obrażasz się i kończysz rozmowę (konczy_rozmowe = true), możesz zawołać ochronę.' : '')
                        . ' Zastosuj to w tej odpowiedzi.';
                }
                $w = ai_wywolaj('npc_wypowiedz', $dane, (int) $s['id']);
                $odp = $w['wypowiedz'];
                $nastroj = $w['nastroj'];
                $konczy = (bool) $w['konczy_rozmowe'];
                if ($test) {
                    $nastroj = $test['udana'] ? $pd[7] : $pd[8];
                    if ($test['rozpacz']) $konczy = true;
                }
                sl_wpis($db, $rid, 'npc', $odp, true);
            } catch (AiBlad $e) { $awaria = true; $powodAwarii = $e->getMessage(); }
        }
        if ($odp === null) {
            $odprawa = $postac && (!$ai || $awaria) ? sl_vip_odprawa($db, $s, $postac, $pytan) : null;
            if ($odprawa !== null) {
                // VIP bez AI: odprawa zastępcza zamiast zdawkowej kwestii.
                $nastroj = 'uprzejmy';
                $odp = $odprawa;
                sl_wpis($db, $rid, 'npc', $odp);
            } elseif (!$ai || $awaria) {
                // Bez AI: zdawkowa kwestia; przechodzień po kilku pytaniach odchodzi.
                // Domyślnie neutralni; zniecierpliwienie rośnie z liczbą pytań.
                if ($pytan === 0) {
                    $r100 = random_int(1, 100);
                    $nastroj = $r100 <= 78 ? 'obojetny' : ($r100 <= 88 ? 'uprzejmy' : ($r100 <= 95 ? 'przestraszony' : 'wrogi'));
                } elseif ($pytan >= 3 && $nastroj !== 'przestraszony' && random_int(1, 100) <= 25) {
                    $nastroj = 'wrogi';
                }
                if ($test) {
                    $nastroj = $test['udana'] ? $pd[7] : $pd[8];
                    $fakt = $test['udana'] ? sl_fakt_do_ujawnienia($db, $s, $pule, $r, $metryka) : null;
                    $odp = $test['udana']
                        ? ($fakt ? ['No dobra, dobra. ', 'Niech ci będzie. ', 'Dobra, powiem jedno: ', 'Ciszej… '][random_int(0, 3)] . $fakt
                            : 'Naprawdę nic nie wiem! Nie znam nikogo takiego, przysięgam.')
                        : sl_skladanka($db, $podejscie . '_nie', $rid, (string) ($metryka['plec'] ?? 'm'));
                    if ($test['rozpacz']) { $odp .= ' Ochrona! Ten droid mnie nęka!'; $konczy = true; }
                } else {
                    $pyt = $polecenie !== '' ? $polecenie : $pytanie;
                    $odp = sl_odpowiedz_zastepcza($db, $s, $pule, $r, $metryka, $pyt, $nastroj)
                        ?? sl_odpowiedz_o_osobie($db, $s, $pule, $r, $metryka, $pyt)
                        ?? sl_wskazowka_okolicy($db, $s, $pule, $r, sl_kwestia_szumu($db, $nastroj, $rid, (int) $s['id'],
                            fn($k) => !sl_wskazuje_miejsce($k) || sl_budynek_z_tekstu(sl_okolica($db, $s, $pule), $k, $r['lokalizacja_id'] !== null ? (int) $r['lokalizacja_id'] : null)));
                }
                $konczy = $konczy || (!$test && $pytan >= 2 && random_int(1, 100) <= 50);
                sl_wpis($db, $rid, 'npc', $odp);
            }
        }
        if ($koniecPytan) $konczy = true;
        if ($odp !== null) {
            try {
                $nowe = sl_namiary($db, $s, $pule, $r, $odp);
                if ($nowe) {
                    sl_wpis($db, $rid, 'system', 'Namiary zapisane w nawigacji: ' . implode('; ', $nowe) . '.');
                    sl_dziennik($db, (int) $s['id'], 'meldunek', 'droid', 'Nowe namiary od rozmówcy: ' . implode('; ', $nowe) . '.');
                }
            } catch (PDOException $e) { /* brak migracji 091 — bez namiarów */ }
        }
        if ($awaria) {
            sl_wpis($db, $rid, 'system', sl_kwestia(SLEDZTWO_ZAKLOCENIA));
            sl_dziennik($db, (int) $s['id'], 'system', 'system', 'Moduł językowy: ' . ($powodAwarii !== '' ? $powodAwarii : 'błąd bez opisu') . " (rozmowa #{$rid}).");
        } elseif (!$ai && $pytan === 0) {
            sl_wpis($db, $rid, 'system', sl_kwestia(SLEDZTWO_ZAKLOCENIA));
            if (!$metryka && SLEDZTWO_AI_DLA_WSZYSTKICH) sl_dziennik($db, (int) $s['id'], 'system', 'system', "Rozmówca bez metryki (migracja 089?) — odpowiedzi zastępcze (rozmowa #{$rid}).");
        }
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
        'sledztwo_rozmowa_koniec', 'sledztwo_porzuc', 'sledztwo_przeszukaj', 'sledztwo_zabierz', 'sledztwo_zlam', 'sledztwo_komputer', 'sledztwo_dekoduj', 'sledztwo_straz', 'sledztwo_nakaz_wniosek', 'mg_sledztwo_nakazy', 'mg_sledztwo_nakaz_decyzja', 'sledztwo_nowe', 'mg_sledztwa', 'mg_sledztwo_podglad', 'mg_detektywi', 'mg_detektyw_ustaw'];
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
                        WHERE sledztwo_id = ? AND rodzaj <> 'system' AND kiedy_utc <= ? ORDER BY kiedy_utc DESC, id DESC LIMIT 50");
                    $q->execute([(int) $biezace['id'], sl_utc(sl_teraz())]);
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
            // Opcjonalnie {x, y}: punkt planu (10 j. = 1 m) — droid idzie dokładnie tam.
            case 'sledztwo_idz':
                if (!$post) blad('Zła metoda.', 405);
                if (!sl_detektyw($db, (string) $u['login'])) blad('Brak uprawnień.', 403);
                $s = sl_biezace($db, (string) $u['login']);
                if (!$s) blad('Brak otwartej sprawy.');
                $w = wejscie();
                $bid = isset($w['lokalizacja_id']) && $w['lokalizacja_id'] !== '' && $w['lokalizacja_id'] !== null ? (int) $w['lokalizacja_id'] : null;
                $pokoj = mb_substr(trim((string) ($w['pokoj'] ?? 'korytarz')), 0, 12) ?: 'korytarz';
                $px = isset($w["x"]) && is_numeric($w["x"]) ? (int) $w["x"] : null;
                $py = isset($w["y"]) && is_numeric($w["y"]) ? (int) $w["y"] : null;
                $wynik = sl_rozkaz_ruchu($db, $s, $bid, (int) ($w['poziom'] ?? 0), $pokoj, $px, $py);
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
                odpowiedz(['rozmowa' => sl_rozmowa_tura($db, $s, $u, (int) ($w['rozmowa_id'] ?? 0), (string) ($w['polecenie'] ?? ''),
                    ($w['procedura'] ?? '') === 'jawna' ? 'jawna' : 'wlasna', (string) ($w['podejscie'] ?? ''))]);

            // Porzucenie: sprawa trafia do MG (stan porzucone), typ zostaje zajęty; detektyw może przyjąć nową.
            // Powrót — po uzasadnieniu i decyzji MG (PROJEKT.md, rozdz. 1).
            case 'sledztwo_porzuc':
                if (!$post) blad('Zła metoda.', 405);
                if (!sl_detektyw($db, (string) $u['login'])) blad('Brak uprawnień.', 403);
                $s = sl_biezace($db, (string) $u['login']);
                if (!$s) blad('Brak otwartej sprawy.');
                $powod = trim(mb_substr((string) (wejscie()['uzasadnienie'] ?? ''), 0, 1000));
                try {
                    $r = sl_rozmowa_aktywna($db, (int) $s['id']);
                    if ($r) sl_rozmowa_zakoncz($db, $r, 'Sprawa porzucona.');
                } catch (PDOException $e) { /* brak migracji 085 */ }
                $db->prepare("UPDATE sledztwa SET stan = 'porzucone', zakonczono_utc = ? WHERE id = ?")->execute([sl_utc(sl_teraz()), (int) $s['id']]);
                sl_dziennik($db, (int) $s['id'], 'polecenie', 'gracz', 'Sprawa porzucona.' . ($powod !== '' ? " Powód: {$powod}" : ''));
                odpowiedz(['ok' => true]);

            // Przedmioty i komputery (migracja 087): przeszukanie lokalu (Percepcja), zabranie, łamanie (Komputery),
            // ekran komputera ({przedmiot_id} albo {terminal: 'T1'}), dekodowanie na terminalu publicznym (zostawia ślad).
            case 'sledztwo_przeszukaj':
            case 'sledztwo_zabierz':
            case 'sledztwo_zlam':
            case 'sledztwo_komputer':
            case 'sledztwo_dekoduj':
                if (!$post) blad('Zła metoda.', 405);
                if (!sl_detektyw($db, (string) $u['login'])) blad('Brak uprawnień.', 403);
                $s = sl_biezace($db, (string) $u['login']);
                if (!$s) blad('Brak otwartej sprawy.');
                try {
                    $db->query('SELECT 1 FROM sledztwo_przedmioty LIMIT 1');
                } catch (PDOException $e) { blad('Przedmioty nie działają. (Brak migracji 087.)'); }
                $w = wejscie();
                $pid = (int) ($w['przedmiot_id'] ?? 0);
                $term = mb_substr((string) ($w['terminal'] ?? ''), 0, 6);
                odpowiedz(match ($akcja) {
                    'sledztwo_przeszukaj' => sl_przeszukaj($db, $s),
                    'sledztwo_zabierz' => sl_zabierz($db, $s, $pid),
                    'sledztwo_zlam' => sl_zlam($db, $s, $pid),
                    'sledztwo_komputer' => ['komputer' => sl_komputer($db, $s, $pid ?: null, $term)],
                    'sledztwo_dekoduj' => sl_dekoduj_publicznie($db, $s, $pid, $term),
                });

            // Próba przekonania strażnika: {pokoj, podejscie: oszustwo|urok|zastraszanie} — test EotE.
            case 'sledztwo_straz':
                if (!$post) blad('Zła metoda.', 405);
                if (!sl_detektyw($db, (string) $u['login'])) blad('Brak uprawnień.', 403);
                $s = sl_biezace($db, (string) $u['login']);
                if (!$s) blad('Brak otwartej sprawy.');
                $w = wejscie();
                odpowiedz(['ok' => true] + sl_straz_proba($db, $s, mb_substr((string) ($w['pokoj'] ?? ''), 0, 12), (string) ($w['podejscie'] ?? '')));

            // Wniosek o nakaz: {lokalizacja_id (null = cały kompleks), uzasadnienie}. Decyduje MG.
            case 'sledztwo_nakaz_wniosek':
                if (!$post) blad('Zła metoda.', 405);
                if (!sl_detektyw($db, (string) $u['login'])) blad('Brak uprawnień.', 403);
                $s = sl_biezace($db, (string) $u['login']);
                if (!$s) blad('Brak otwartej sprawy.');
                $w = wejscie();
                $bid = isset($w['lokalizacja_id']) && $w['lokalizacja_id'] !== '' && $w['lokalizacja_id'] !== null ? (int) $w['lokalizacja_id'] : null;
                sl_nakaz_wniosek($db, $s, $bid, (string) ($w['uzasadnienie'] ?? ''));
                odpowiedz(['ok' => true]);

            // MG: wnioski o nakaz (czekające i ostatnio rozpatrzone).
            case 'mg_sledztwo_nakazy':
                if (!$mg) blad('Brak uprawnień.', 403);
                try {
                    $lista = $db->query("SELECT n.id, n.stan, n.uzasadnienie, n.decyzja, n.zlozono_utc, n.rozpatrzono_utc, s.id AS sledztwo_id,
                            s.konto_login, s.tytul, COALESCE(l.nazwa, 'cały kompleks') AS budynek
                        FROM sledztwo_nakazy n JOIN sledztwa s ON s.id = n.sledztwo_id LEFT JOIN lokalizacje l ON l.id = n.lokalizacja_id
                        ORDER BY n.stan = 'wniosek' DESC, n.id DESC LIMIT 50")->fetchAll();
                } catch (PDOException $e) { blad('Brak migracji 086.'); }
                odpowiedz(['nakazy' => $lista]);

            // MG: {id, wydany: true|false, decyzja}. Detektyw dostaje meldunek biura w dzienniku.
            case 'mg_sledztwo_nakaz_decyzja':
                if (!$mg) blad('Brak uprawnień.', 403);
                if (!$post) blad('Zła metoda.', 405);
                $w = wejscie();
                $q = $db->prepare("SELECT n.*, COALESCE(l.nazwa, 'cały kompleks') AS budynek FROM sledztwo_nakazy n
                    LEFT JOIN lokalizacje l ON l.id = n.lokalizacja_id WHERE n.id = ? AND n.stan = 'wniosek'");
                $q->execute([(int) ($w['id'] ?? 0)]);
                $n = $q->fetch();
                if (!$n) blad('Nie ma takiego wniosku albo już go rozpatrzono.');
                $wydany = !empty($w['wydany']);
                $decyzja = trim(mb_substr((string) ($w['decyzja'] ?? ''), 0, 500));
                $db->prepare('UPDATE sledztwo_nakazy SET stan = ?, decyzja = ?, rozpatrzono_utc = ? WHERE id = ?')
                    ->execute([$wydany ? 'wydany' : 'odrzucony', $decyzja !== '' ? $decyzja : null, sl_utc(sl_teraz()), (int) $n['id']]);
                sl_dziennik($db, (int) $n['sledztwo_id'], 'meldunek', 'system', 'Biuro: nakaz na ' . $n['budynek'] . ($wydany ? ' wydany.' : ' odrzucony.')
                    . ($decyzja !== '' ? ' ' . $decyzja : ''));
                odpowiedz(['ok' => true]);

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

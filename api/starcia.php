<?php
// Starcia w realnej grze (narzedzia/mostek/MECHANIKA.md 13, migracja 080). Skok agresora do układu, w którym są
// wrogie mu okręty, otwiera starcie: w hiperprzestrzeni „staje czas” — skok trwa, dopóki strony nie umówią terminu
// walki (gracze obu stron dostają powiadomienie i dogadują termin w aplikacji Starcia; MG może termin narzucić).
// Wyjście ze skoku następuje w umówionym terminie. Obrońca może w tym czasie uciec (wszystkie wrogie okręty opuszczą
// układ) — starcie się kończy, a czas wraca do normy: zatrzymane skoki kończą się po swoim czasie, wydłużonym o pauzę.
// Każdy skok do układu z otwartym starciem (posiłki obu stron, drugi kierunek ataku) też jest zatrzymany: wyskoczy
// o tyle po terminie, o ile miał wyskoczyć po początku pauzy (posiłki „1 h po ataku” — 1 h po umówionym terminie).
// W terminie starcie przechodzi w bitwę (mapa_bitwy, rodzaj rzeczywista) — rozgrywaną dalej silnikiem bitwy.

declare(strict_types=1);

const STARCIE_ZAMROZENIE_S = 30 * 86400;    // „czas stoi”: wyjście ze skoku odsunięte, dopóki nie ma terminu
const STARCIE_MIN_WYPRZEDZENIE_S = 600;      // termin walki co najmniej 10 min od propozycji
const STARCIE_STATUSY_OTWARTE = ['negocjacja', 'umowione'];

function starcia_dostepne(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT id FROM starcia LIMIT 0'); polaczenie_bazy()->query('SELECT starcie_id FROM starcia_skoki LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// Frakcja jednostki: zapisana, z właściciela albo z frakcji konta dowódcy floty (jak mapa_strony).
function starcie_frakcja(array $o): ?string
{
    static $konta = null;
    if ($konta === null) { $konta = []; foreach (uzytkownicy() as $k) $konta[mb_strtolower((string) ($k['login'] ?? ''))] = $k['frakcja'] ?? null; }
    return ($o['frakcja'] ?? null) ?: (function_exists('mapa_frakcja_z_opisu') ? mapa_frakcja_z_opisu((string) ($o['wlasciciel'] ?? '')) : null)
        ?: ($konta[mb_strtolower((string) ($o['dowodca'] ?? ''))] ?? null);
}

// Okręty w układzie (współrzędne układu na mapie) z flotą i dowódcą; bez tych, które już z niego wyskakują.
function starcie_okrety_ukladu(float $x, float $y): array
{
    $db = polaczenie_bazy();
    $frak = function_exists('mapa_frakcja_dostepna') && mapa_frakcja_dostepna() ? 'o.frakcja' : 'NULL AS frakcja';
    $q = $db->prepare("SELECT o.id, o.nazwa, o.wlasciciel, $frak, fj.flota_id FROM mapa_obiekty o
        LEFT JOIN mapa_floty_jednostki fj ON fj.obiekt_id = o.id
        WHERE o.typ IN ('statek','jednostka') AND o.aktywny = 1 AND ABS(o.x - ?) < ? AND ABS(o.y - ?) < ?
          AND NOT EXISTS (SELECT 1 FROM mapa_rozkazy_skokow_jednostki s WHERE s.obiekt_id = o.id AND s.status = 'w_nadprzestrzeni')");
    $q->execute([$x, STREFA_UKLADU_PC, $y, STREFA_UKLADU_PC]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $o['dowodca'] = $o['flota_id'] ? floty_dowodca((int) $o['flota_id']) : null;
        $o['frakcja_strony'] = starcie_frakcja($o);
        $out[] = $o;
    }
    return $out;
}

function starcie_wiersz(int $id): ?array
{
    $q = polaczenie_bazy()->prepare('SELECT * FROM starcia WHERE id=?');
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function starcie_dodaj_uczestnika(int $starcie, ?string $login, string $strona): void
{
    if (!$login || str_ends_with($login, '(NPC)')) return;
    polaczenie_bazy()->prepare('INSERT IGNORE INTO starcia_uczestnicy (starcie_id, login, strona) VALUES (?,?,?)')->execute([$starcie, $login, $strona]);
}

// Zatrzymanie skoku w starciu: zapis pierwotnego wyjścia ze skoku i odsunięcie go (czas stoi).
function starcie_zamroz_skok(int $starcie, int $rozkazId, string $strona): void
{
    $db = polaczenie_bazy();
    $q = $db->prepare('SELECT eta_utc FROM mapa_rozkazy_skokow WHERE id=?');
    $q->execute([$rozkazId]);
    $eta = $q->fetchColumn();
    if ($eta === false) return;
    $db->prepare('INSERT IGNORE INTO starcia_skoki (starcie_id, rozkaz_id, obiekt_id, strona, eta_org_utc) VALUES (?,?,0,?,?)')
        ->execute([$starcie, $rozkazId, $strona, $eta]);
    $q = $db->prepare("SELECT obiekt_id, eta_utc FROM mapa_rozkazy_skokow_jednostki WHERE rozkaz_id=? AND status='w_nadprzestrzeni'");
    $q->execute([$rozkazId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $j) {
        $db->prepare('INSERT IGNORE INTO starcia_skoki (starcie_id, rozkaz_id, obiekt_id, strona, eta_org_utc) VALUES (?,?,?,?,?)')
            ->execute([$starcie, $rozkazId, (int) $j['obiekt_id'], $strona, $j['eta_utc']]);
    }
    $daleko = czas_utc(czas_teraz() + STARCIE_ZAMROZENIE_S);
    $db->prepare('UPDATE mapa_rozkazy_skokow SET eta_utc=? WHERE id=?')->execute([$daleko, $rozkazId]);
    $db->prepare("UPDATE mapa_rozkazy_skokow_jednostki SET eta_utc=? WHERE rozkaz_id=? AND status='w_nadprzestrzeni'")->execute([$daleko, $rozkazId]);
}

// Zwolnienie zatrzymanych skoków: w terminie walki (wyjście = termin + opóźnienie wobec początku pauzy) albo po
// anulowaniu (czas wraca do normy: pierwotne wyjście wydłużone o czas pauzy).
function starcie_zwolnij_skoki(array $s, ?int $termin): void
{
    $db = polaczenie_bazy();
    $pauza = czas_z_utc((string) $s['pauza_od_utc']);
    $teraz = czas_teraz();
    $q = $db->prepare('SELECT rozkaz_id, obiekt_id, eta_org_utc FROM starcia_skoki WHERE starcie_id=?');
    $q->execute([(int) $s['id']]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $org = czas_z_utc((string) $r['eta_org_utc']);
        $eta = $termin !== null ? $termin + max(0, $org - $pauza) : $org + max(0, $teraz - $pauza);
        if ((int) $r['obiekt_id'] === 0) $db->prepare("UPDATE mapa_rozkazy_skokow SET eta_utc=? WHERE id=? AND status='w_nadprzestrzeni'")->execute([czas_utc($eta), $r['rozkaz_id']]);
        else $db->prepare("UPDATE mapa_rozkazy_skokow_jednostki SET eta_utc=? WHERE rozkaz_id=? AND obiekt_id=? AND status='w_nadprzestrzeni'")
            ->execute([czas_utc($eta), $r['rozkaz_id'], $r['obiekt_id']]);
    }
}

// Po wydaniu skoku (floty_rozkaz): skok do układu z wrogimi okrętami otwiera starcie; skok do układu z otwartym
// starciem dołącza do niego (posiłki obu stron, atak z drugiej strony). Zwraca opis starcia albo null.
function starcie_po_skoku(int $rozkazId): ?array
{
    if (!starcia_dostepne()) return null;
    $db = polaczenie_bazy();
    $q = $db->prepare("SELECT r.id, r.cel_x, r.cel_y, r.wydajacy_login, r.flota_id FROM mapa_rozkazy_skokow r WHERE r.id=? AND r.status='w_nadprzestrzeni'");
    $q->execute([$rozkazId]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $planeta = floty_planeta_w_punkcie((float) $r['cel_x'], (float) $r['cel_y']);
    if (!$planeta) return null;                                   // skok w pustą przestrzeń — bez starcia
    [$ux, $uy] = floty_wsp_planety($planeta);
    // strona skaczących: frakcja ich jednostek (pierwsza znana) albo konta wydającego rozkaz
    $q = $db->prepare('SELECT o.id, o.nazwa, o.wlasciciel, ' . (function_exists('mapa_frakcja_dostepna') && mapa_frakcja_dostepna() ? 'o.frakcja' : 'NULL AS frakcja')
        . ', fj.flota_id FROM mapa_rozkazy_skokow_jednostki s JOIN mapa_obiekty o ON o.id = s.obiekt_id LEFT JOIN mapa_floty_jednostki fj ON fj.obiekt_id = o.id WHERE s.rozkaz_id=?');
    $q->execute([$rozkazId]);
    $skaczace = $q->fetchAll(PDO::FETCH_ASSOC);
    $frakcja = null;
    foreach ($skaczace as &$o) { $o['dowodca'] = $o['flota_id'] ? floty_dowodca((int) $o['flota_id']) : null; $frakcja ??= starcie_frakcja($o); }
    unset($o);
    if (!$frakcja) foreach (uzytkownicy() as $k) if (mb_strtolower((string) $k['login']) === mb_strtolower((string) $r['wydajacy_login'])) $frakcja = $k['frakcja'] ?? null;
    // otwarte starcie w tym układzie — dołączenie (strona według relacji z agresorem)
    $q = $db->prepare("SELECT * FROM starcia WHERE planeta_id=? AND status IN ('negocjacja','umowione') ORDER BY id DESC LIMIT 1");
    $q->execute([$planeta]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if ($s) {
        $strona = $frakcja && mapa_relacja($frakcja, $s['agresor_frakcja']) === 'sojusznik' ? 'agresor' : 'obronca';
        starcie_zamroz_skok((int) $s['id'], $rozkazId, $strona);
        if ($s['status'] === 'umowione') starcie_zwolnij_skoki($s, czas_z_utc((string) $s['termin_utc']));
        starcie_dodaj_uczestnika((int) $s['id'], (string) $r['wydajacy_login'], $strona);
        foreach ($skaczace as $o) starcie_dodaj_uczestnika((int) $s['id'], $o['dowodca'], $strona);
        return ['id' => (int) $s['id'], 'status' => $s['status'], 'strona' => $strona, 'dolaczono' => true];
    }
    if (!$frakcja) return null;
    $wrogowie = array_values(array_filter(starcie_okrety_ukladu($ux, $uy), static fn($o) => mapa_relacja($frakcja, $o['frakcja_strony']) === 'wrog'));
    if (!$wrogowie) return null;
    $nazwa = (string) ($db->query('SELECT nazwa FROM mapa_planety WHERE id=' . (int) $planeta)->fetchColumn() ?: 'układ');
    $obronca = $wrogowie[0]['frakcja_strony'];
    $db->prepare("INSERT INTO starcia (planeta_id, uklad_x, uklad_y, nazwa, status, agresor_frakcja, obronca_frakcja, pauza_od_utc)
        VALUES (?,?,?,?, 'negocjacja', ?,?,?)")->execute([$planeta, $ux, $uy, "Starcie w układzie $nazwa", $frakcja, $obronca, czas_utc(czas_teraz())]);
    $id = (int) $db->lastInsertId();
    starcie_zamroz_skok($id, $rozkazId, 'agresor');
    starcie_dodaj_uczestnika($id, (string) $r['wydajacy_login'], 'agresor');
    foreach ($skaczace as $o) starcie_dodaj_uczestnika($id, $o['dowodca'], 'agresor');
    foreach ($wrogowie as $o) {
        starcie_dodaj_uczestnika($id, $o['dowodca'], 'obronca');
        $db->prepare('INSERT IGNORE INTO starcia_obroncy (starcie_id, obiekt_id) VALUES (?,?)')->execute([$id, (int) $o['id']]);
    }
    return ['id' => $id, 'status' => 'negocjacja', 'strona' => 'agresor', 'dolaczono' => false, 'uklad' => $nazwa];
}

// Przy każdym przeliczeniu ruchu: ucieczka obrońcy (żaden wrogi agresorowi okręt nie został w układzie) kończy
// starcie i przywraca bieg czasu; umówiony termin, który minął, otwiera bitwę.
function starcia_aktualizuj(): void
{
    if (!starcia_dostepne()) return;
    static $raz = false;
    if ($raz) return;
    $raz = true;
    $db = polaczenie_bazy();
    $teraz = czas_teraz();
    foreach ($db->query("SELECT * FROM starcia WHERE status IN ('negocjacja','umowione')")->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $zostali = array_filter(starcie_okrety_ukladu((float) $s['uklad_x'], (float) $s['uklad_y']),
            static fn($o) => mapa_relacja($s['agresor_frakcja'], $o['frakcja_strony']) === 'wrog');
        if (!$zostali) {
            starcie_zwolnij_skoki($s, null);
            $db->prepare("UPDATE starcia SET status='obronca_uciekl', zakonczono_utc=? WHERE id=?")->execute([czas_utc($teraz), $s['id']]);
            continue;
        }
        if ($s['status'] === 'umowione' && czas_z_utc((string) $s['termin_utc']) <= $teraz) starcie_otworz_bitwe($s);
    }
    $db->exec("UPDATE starcia s JOIN mapa_bitwy b ON b.id = s.bitwa_id SET s.status='zakonczone', s.zakonczono_utc=UTC_TIMESTAMP()
        WHERE s.status='bitwa' AND b.status IN ('zakonczona','anulowana')");
}

// Termin walki: bitwa rozgrywana silnikiem (jak symulacja) na prawdziwych okrętach starcia — okręty obu stron w układzie
// i te w zatrzymanych skokach (atakujący wychodzą ze skoku w terminie, posiłki później: wejście = opóźnienie wobec
// pauzy). Obrońcy w środku pola, atakujący 40 km od nich od strony, z której nadlecieli (posiłki tak samo). Klucze
// z hangarów (profil: myśliwce / 4), piloci z pokładu (mapa_piloci), stanowiska ze stałych przydziałów okrętów.
// Strona z graczami prowadzi się sama (klucze czekają na START); strona bez graczy — SI dowódcy (domyślnie Calrissian).
// Bitwa startuje od razu (zegar od terminu); okno 4 h, koniec — MG. Na mapie: znacznik bitwy z uczestnikami.
const STARCIE_OKNO_S = 4 * 3600;
const STARCIE_ODLEGLOSC_ATAKU_KM = 40.0;
const STARCIE_SI_DOMYSLNA = 'calrissian';

function starcie_otworz_bitwe(array $s): void
{
    $db = polaczenie_bazy();
    $id = (int) $s['id'];
    $termin = czas_z_utc((string) $s['termin_utc']);
    $pauza = czas_z_utc((string) $s['pauza_od_utc']);
    // okręty: w układzie (obie strony) i w zatrzymanych skokach (z kierunkiem nadlotu i opóźnieniem wejścia)
    $okrety = [];
    foreach (starcie_okrety_ukladu((float) $s['uklad_x'], (float) $s['uklad_y']) as $o) {
        $okrety[(int) $o['id']] = ['o' => $o, 'atak' => mapa_relacja($s['agresor_frakcja'], $o['frakcja_strony']) !== 'wrog', 'wejscie' => 0, 'kat' => null];
    }
    $q = $db->prepare('SELECT k.obiekt_id, k.strona, k.eta_org_utc, j.start_x, j.start_y FROM starcia_skoki k
        JOIN mapa_rozkazy_skokow_jednostki j ON j.rozkaz_id = k.rozkaz_id AND j.obiekt_id = k.obiekt_id WHERE k.starcie_id=? AND k.obiekt_id > 0');
    $q->execute([$id]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qo = $db->prepare('SELECT o.id, o.nazwa, o.wlasciciel, ' . (function_exists('mapa_frakcja_dostepna') && mapa_frakcja_dostepna() ? 'o.frakcja' : 'NULL AS frakcja')
            . ', fj.flota_id FROM mapa_obiekty o LEFT JOIN mapa_floty_jednostki fj ON fj.obiekt_id = o.id WHERE o.id=?');
        $qo->execute([(int) $r['obiekt_id']]);
        $o = $qo->fetch(PDO::FETCH_ASSOC);
        if (!$o) continue;
        $o['dowodca'] = $o['flota_id'] ? floty_dowodca((int) $o['flota_id']) : null;
        $o['frakcja_strony'] = starcie_frakcja($o);
        $kat = atan2((float) $r['start_x'] - (float) $s['uklad_x'], (float) $r['start_y'] - (float) $s['uklad_y']);   // namiar nadlotu (od północy)
        $okrety[(int) $o['id']] = ['o' => $o, 'atak' => $r['strona'] === 'agresor', 'wejscie' => max(0, czas_z_utc((string) $r['eta_org_utc']) - $pauza), 'kat' => $kat];
    }
    // tylko okręty z profilem klasy walczą w silniku
    $profile = [];
    if ($okrety) {
        $q = $db->query('SELECT id, profil_klasy FROM mapa_obiekty WHERE id IN (' . implode(',', array_keys($okrety)) . ') AND profil_klasy IS NOT NULL');
        $profile = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $sl = bitwa_slowniki();
    $ucz = $db->prepare('SELECT strona, COUNT(*) FROM starcia_uczestnicy WHERE starcie_id=? GROUP BY strona');
    $ucz->execute([$id]);
    $gracze = $ucz->fetchAll(PDO::FETCH_KEY_PAIR);
    $strony = [['strona' => (string) $s['agresor_frakcja'], 'rola' => 'atak', 'gracze' => !empty($gracze['agresor'])],
        ['strona' => (string) ($s['obronca_frakcja'] ?: 'Obrona'), 'rola' => 'obrona', 'gracze' => !empty($gracze['obronca'])]];
    $sc = ['kod' => 'starcie-' . $id, 'nazwa' => $s['nazwa'], 'odprawa' => "{$s['agresor_frakcja']} atakuje {$s['obronca_frakcja']} — termin umówiony przez strony.",
        'strony' => [], 'jednostki' => [], 'klucze' => []];
    foreach ($strony as $st) {
        $sc['strony'][] = ['strona' => $st['strona'], 'dowodzenie' => $st['gracze'] ? 'gracze' : STARCIE_SI_DOMYSLNA, 'rola' => $st['rola'], 'asy_proc' => 5, 'kadeci_proc' => 35,
            'kolor' => $st['strona'] === 'Imperium' ? '#9fb4c8' : '#ff6b55'];
    }
    $nr = 0;
    $obrona = 0;
    foreach ($okrety as $oid => $x) {
        $profil = $profile[$oid] ?? null;
        if (!$profil || !isset($sl['profile'][$profil])) continue;
        $nr++;
        $strona = $x['atak'] ? $strony[0]['strona'] : $strony[1]['strona'];
        if ($x['atak'] || $x['kat'] !== null) {
            $kat = $x['kat'] ?? 0.0;
            $px = sin($kat) * STARCIE_ODLEGLOSC_ATAKU_KM + ($nr % 3 - 1) * 3; $py = cos($kat) * STARCIE_ODLEGLOSC_ATAKU_KM;
            $kurs = fmod(rad2deg($kat) + 180 + 360, 360);   // dziobem ku środkowi pola
        } else {
            $px = ($obrona % 4) * 4 - 6; $py = intdiv($obrona, 4) * 4; $kurs = 0.0;
            $obrona++;
        }
        $sc['jednostki'][] = ['nr' => $nr, 'strona' => $strona, 'profil_kod' => $profil, 'nazwa' => $x['o']['nazwa'], 'x_km' => round($px, 2), 'y_km' => round($py, 2),
            'kurs_deg' => round($kurs, 2), 'predkosc_km_s' => 0, 'wejscie_s' => (int) $x['wejscie'], 'wejscie_rozrzut_s' => 0, 'obiekt_id' => $oid, 'z_mapy' => 1];
        $typ = MOSTEK_TYP_MYSLIWCOW[$profil] ?? null;
        $ile = intdiv((int) $sl['profile'][$profil]['mysliwce'], 4);
        $qt = $db->prepare('SELECT tie_liczba FROM mapa_floty_jednostki WHERE obiekt_id=? AND tie_liczba IS NOT NULL');
        $qt->execute([$oid]);
        $tie = $qt->fetchColumn();
        if ($tie !== false) $ile = intdiv((int) $tie, 4);      // maszyny w hangarze okrętu w świecie gry
        if ($typ && isset($sl['maszyny'][$typ]) && $ile > 0) {
            $sc['klucze'][] = ['strona' => $strona, 'typ_maszyny' => $typ, 'liczba_kluczy' => $ile, 'jednostka_nr' => $nr, 'start_s' => (int) $x['wejscie'] + 30,
                'start_rozrzut_s' => 20, 'x_km' => null, 'y_km' => null, 'rozkaz' => 'ochrona', 'fala' => $nr];
        }
    }
    $bitwa = null;
    if ($sc['jednostki']) {
        // scenariusz starcia (strony, okno) — potrzebny wczytywaniu stanu bitwy; nie trafia na listę symulacji
        $db->prepare("INSERT INTO bitwy_scenariusze (kod, nazwa, odprawa, okno_s, zwyciestwo, porazka, status) VALUES (?,?,?,?,?,?, 'wycofany')
            ON DUPLICATE KEY UPDATE nazwa=VALUES(nazwa)")->execute([$sc['kod'], $sc['nazwa'], $sc['odprawa'], STARCIE_OKNO_S, 'utrzymanie układu / zniszczenie obrony', 'odwrót']);
        foreach ($sc['strony'] as $st) {
            $db->prepare('INSERT INTO bitwy_scenariusze_strony (scenariusz_kod, strona, kolor, dowodzenie, rola, asy_proc, kadeci_proc) VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE dowodzenie=VALUES(dowodzenie)')->execute([$sc['kod'], $st['strona'], $st['kolor'], $st['dowodzenie'], $st['rola'], 5, 35]);
        }
        $bitwa = bitwa_utworz_z_danych($sc, $sc['kod'], $s['nazwa'], 'rzeczywista', null, (int) $s['planeta_id'], $sc['odprawa'], true, 'aktywna',
            static fn(array &$S) => starcie_stan_ze_swiata($S));
        // zegar od terminu walki
        $db->prepare('UPDATE mapa_bitwy SET zegar_start_utc=? WHERE id=?')->execute([bitwa_utc((float) $termin), $bitwa]);
    } else {
        // bez okrętów z profilem klasy — sam znacznik bitwy na mapie (rozstrzyga MG)
        $rodzaj = function_exists('bitwa_dostepna') && bitwa_dostepna();
        $db->prepare('INSERT INTO mapa_bitwy (kod, nazwa, planeta_id, status, opis' . ($rodzaj ? ', rodzaj' : '') . ') VALUES (?,?,?,\'aktywna\',?' . ($rodzaj ? ",'rzeczywista'" : '') . ')')
            ->execute(['starcie-' . $id, $s['nazwa'], $s['planeta_id'], $sc['odprawa']]);
        $bitwa = (int) $db->lastInsertId();
    }
    foreach ($okrety as $oid => $x) {
        $strona = $x['atak'] ? $strony[0]['strona'] : $strony[1]['strona'];
        $db->prepare('INSERT IGNORE INTO mapa_bitwy_uczestnicy (bitwa_id, obiekt_id, strona, kolor) VALUES (?,?,?,?)')
            ->execute([$bitwa, $oid, $strona, $strona === 'Imperium' ? '#9fb4c8' : '#ff6b55']);
    }
    $db->prepare("UPDATE starcia SET status='bitwa', bitwa_id=? WHERE id=?")->execute([$bitwa, $id]);
}

// ------------------------------------------------------------------------------------------------ skutki (etap 3)
// Bitwa zaczyna się ze stanem okrętów ze świata: kadłub (mapa_obiekty.kadlub, %) we wszystkich sekcjach, martwe żyły
// osłon zapisane po poprzedniej bitwie (okrety_zyly — tylko gdy osłony < 100%, stocznia przywraca 100%). Po bitwie
// MG zatwierdza skutki: kadłub i osłony (% żywych żył), martwe żyły, utracone maszyny (tie_liczba floty), polegli
// piloci (zestrzeleni — usunięci z pokładu; pokład uzupełnia potem nowych). Migracja 081.

function starcia_skutki_dostepne(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT skutki_utc FROM starcia LIMIT 0'); polaczenie_bazy()->query('SELECT martwe FROM okrety_zyly LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

function starcie_stan_ze_swiata(array &$S): void
{
    $db = polaczenie_bazy();
    foreach ($S['jednostki'] as $ji => $j) {
        if (empty($j['obiekt_id'])) continue;
        $q = $db->prepare('SELECT kadlub, oslony FROM mapa_obiekty WHERE id=?');
        try { $q->execute([(int) $j['obiekt_id']]); $o = $q->fetch(PDO::FETCH_ASSOC); } catch (Throwable $e) { $o = null; }
        if (!$o) continue;
        if (function_exists('okret_ustawienia_do_stanu')) okret_ustawienia_do_stanu($S, $ji, (int) $j['obiekt_id']);
        $kadlub = max(1.0, min(100.0, (float) $o['kadlub']));
        foreach (array_keys($j['sekcje']) as $sek) $S['jednostki'][$ji]['sekcje'][$sek] = $kadlub;
        if (!starcia_skutki_dostepne() || (int) $o['oslony'] >= 100) continue;
        $qz = $db->prepare('SELECT kod, liczba, martwe FROM okrety_zyly WHERE obiekt_id=?');
        $qz->execute([(int) $j['obiekt_id']]);
        $zapis = [];
        foreach ($qz->fetchAll(PDO::FETCH_ASSOC) as $z) $zapis[$z['kod']] = $z;
        foreach ($j['generatory'] as $gi => $g) {
            $z = $zapis[$g['kod']] ?? null;
            if ($z && (int) $z['liczba'] === (int) $g['zyly']['n'] && strlen((string) $z['martwe']) === strlen($g['zyly']['martwe'])) {
                $S['jednostki'][$ji]['generatory'][$gi]['zyly']['martwe'] = (string) $z['martwe'];
            }
        }
    }
}

// Skutki bitwy dla każdego okrętu ze świata: kadłub przed i po (%), osłony po (% żywych żył), utracone maszyny, polegli.
function starcie_skutki(int $bitwaId): array
{
    $db = polaczenie_bazy();
    $sl = bitwa_slowniki();
    $q = $db->prepare('SELECT j.id, j.obiekt_id, j.nazwa, j.profil_kod, j.stan, o.kadlub AS kadlub_przed, o.oslony AS oslony_przed FROM bitwy_jednostki j
        JOIN mapa_obiekty o ON o.id = j.obiekt_id WHERE j.bitwa_id=? AND j.obiekt_id IS NOT NULL');
    $q->execute([$bitwaId]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $j) {
        $p = $sl['profile'][$j['profil_kod']] ?? null;
        $qs = $db->prepare('SELECT sekcja, integralnosc FROM bitwy_sekcje WHERE bitwa_jednostka_id=?');
        $qs->execute([(int) $j['id']]);
        $kadlub = 0.0;
        foreach ($qs->fetchAll(PDO::FETCH_KEY_PAIR) as $sek => $v) $kadlub += (float) $v * ($p['kadlub'][$sek] ?? 0);
        $qg = $db->prepare('SELECT s.kod, z.liczba, z.martwe FROM bitwy_systemy s JOIN bitwy_zyly z ON z.system_id = s.id WHERE s.bitwa_jednostka_id=?');
        $qg->execute([(int) $j['id']]);
        $zyly = $qg->fetchAll(PDO::FETCH_ASSOC);
        $wszystkie = 0; $martwe = 0;
        foreach ($zyly as $z) { $wszystkie += (int) $z['liczba']; $martwe += bitwa_martwe(['martwe' => (string) $z['martwe']]); }
        $qk = $db->prepare('SELECT COALESCE(SUM(4 - GREATEST(0, maszyny)), 0) FROM bitwy_klucze WHERE bitwa_id=? AND macierzysta_id=?');
        $qk->execute([$bitwaId, (int) $j['id']]);
        $qp = $db->prepare("SELECT p.pilot_id, p.imie FROM bitwy_piloci p JOIN bitwy_klucze k ON k.id = p.bitwa_klucz_id
            WHERE k.bitwa_id=? AND k.macierzysta_id=? AND p.stan='zestrzelony' AND p.pilot_id IS NOT NULL");
        $qp->execute([$bitwaId, (int) $j['id']]);
        $out[] = ['obiekt_id' => (int) $j['obiekt_id'], 'nazwa' => $j['nazwa'], 'stan' => $j['stan'],
            'kadlub_przed' => (int) $j['kadlub_przed'], 'kadlub_po' => (int) round($kadlub), 'oslony_przed' => (int) $j['oslony_przed'],
            'oslony_po' => $wszystkie ? (int) floor(100 * ($wszystkie - $martwe) / $wszystkie) : (int) $j['oslony_przed'],
            'maszyny_utracone' => (int) $qk->fetchColumn(), 'polegli' => $qp->fetchAll(PDO::FETCH_ASSOC), 'zyly' => $zyly];
    }
    return $out;
}

function starcie_zatwierdz_skutki(array $s): array
{
    if (!starcia_skutki_dostepne()) blad('Skutki bitew wymagają migracji 081.');
    if (!$s['bitwa_id']) blad('To starcie nie miało bitwy silnikiem.');
    if ($s['skutki_utc'] !== null) blad('Skutki tej bitwy są już zatwierdzone.');
    $db = polaczenie_bazy();
    $skutki = starcie_skutki((int) $s['bitwa_id']);
    $db->beginTransaction();
    try {
        foreach ($skutki as $o) {
            $db->prepare('UPDATE mapa_obiekty SET kadlub=?, oslony=? WHERE id=?')->execute([max(0, min(100, $o['kadlub_po'])), max(0, min(100, $o['oslony_po'])), $o['obiekt_id']]);
            foreach ($o['zyly'] as $z) {
                $db->prepare('INSERT INTO okrety_zyly (obiekt_id, kod, liczba, martwe) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE liczba=VALUES(liczba), martwe=VALUES(martwe)')
                    ->execute([$o['obiekt_id'], $z['kod'], (int) $z['liczba'], (string) $z['martwe']]);
            }
            if ($o['maszyny_utracone'] > 0) {
                $db->prepare('UPDATE mapa_floty_jednostki SET tie_liczba = GREATEST(0, tie_liczba - ?) WHERE obiekt_id=? AND tie_liczba IS NOT NULL')
                    ->execute([$o['maszyny_utracone'], $o['obiekt_id']]);
            }
            foreach ($o['polegli'] as $p) $db->prepare('DELETE FROM mapa_piloci WHERE id=?')->execute([(int) $p['pilot_id']]);
        }
        $db->prepare('UPDATE starcia SET skutki_utc=? WHERE id=?')->execute([czas_utc(czas_teraz()), $s['id']]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return array_map(static fn($o) => array_diff_key($o, ['zyly' => 1]), $skutki);
}

// ------------------------------------------------------------------------------------------------ widok i rozkazy

function starcie_moja_strona(array $u, int $id): ?string
{
    $q = polaczenie_bazy()->prepare('SELECT strona FROM starcia_uczestnicy WHERE starcie_id=? AND LOWER(login)=LOWER(?)');
    $q->execute([$id, (string) ($u['login'] ?? '')]);
    return $q->fetchColumn() ?: null;
}

// Starcia konta (MG — wszystkie otwarte i ostatnie): stan, termin, propozycje, uczestnicy.
function starcia_moje(array $u): array
{
    if (!starcia_dostepne()) return [];
    $db = polaczenie_bazy();
    $mg = ($u['rola'] ?? '') === 'mg';
    if ($mg) $q = $db->query("SELECT * FROM starcia WHERE status IN ('negocjacja','umowione','bitwa') OR zakonczono_utc > UTC_TIMESTAMP() - INTERVAL 1 DAY ORDER BY id DESC LIMIT 30");
    else {
        $q = $db->prepare("SELECT s.* FROM starcia s JOIN starcia_uczestnicy u ON u.starcie_id = s.id WHERE LOWER(u.login)=LOWER(?)
            AND (s.status IN ('negocjacja','umowione','bitwa') OR s.zakonczono_utc > UTC_TIMESTAMP() - INTERVAL 1 DAY) ORDER BY s.id DESC");
        $q->execute([(string) ($u['login'] ?? '')]);
    }
    $postacie = [];
    foreach (uzytkownicy() as $k) $postacie[mb_strtolower((string) $k['login'])] = $k['postac'] ?? $k['login'];
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $id = (int) $s['id'];
        $qu = $db->prepare('SELECT login, strona FROM starcia_uczestnicy WHERE starcie_id=?');
        $qu->execute([$id]);
        $ucz = array_map(static fn($x) => ['login' => $x['login'], 'postac' => $postacie[mb_strtolower($x['login'])] ?? $x['login'], 'strona' => $x['strona']], $qu->fetchAll(PDO::FETCH_ASSOC));
        $qp = $db->prepare('SELECT id, login, strona, termin_utc, utworzono_utc, status FROM starcia_propozycje WHERE starcie_id=? ORDER BY id DESC LIMIT 10');
        $qp->execute([$id]);
        $prop = array_map(static fn($p) => ['id' => (int) $p['id'], 'postac' => $postacie[mb_strtolower($p['login'])] ?? $p['login'], 'strona' => $p['strona'],
            'termin' => czas_z_utc((string) $p['termin_utc']), 'status' => $p['status']], $qp->fetchAll(PDO::FETCH_ASSOC));
        $qk = $db->prepare('SELECT strona, COUNT(DISTINCT rozkaz_id) AS skoki FROM starcia_skoki WHERE starcie_id=? AND obiekt_id=0 GROUP BY strona');
        $qk->execute([$id]);
        $out[] = ['id' => $id, 'nazwa' => $s['nazwa'], 'status' => $s['status'], 'agresor' => $s['agresor_frakcja'], 'obronca' => $s['obronca_frakcja'],
            'pauza_od' => czas_z_utc((string) $s['pauza_od_utc']), 'termin' => $s['termin_utc'] ? czas_z_utc((string) $s['termin_utc']) : null,
            'bitwa_id' => $s['bitwa_id'] !== null ? (int) $s['bitwa_id'] : null, 'moja_strona' => $mg ? 'mg' : starcie_moja_strona($u, $id),
            'uczestnicy' => $ucz, 'propozycje' => $prop, 'skoki' => $qk->fetchAll(PDO::FETCH_KEY_PAIR),
            'skutki' => $s['bitwa_id'] && starcia_skutki_dostepne() && in_array($s['status'], ['bitwa', 'zakonczone'], true)
                ? array_map(static fn($o) => array_diff_key($o, ['zyly' => 1]) + ['polegli' => array_column($o['polegli'], 'imie')], starcie_skutki((int) $s['bitwa_id'])) : null,
            'skutki_zatwierdzone' => starcia_skutki_dostepne() && ($s['skutki_utc'] ?? null) !== null];
    }
    return $out;
}

function obsluz_starcia(string $akcja, bool $post, array $u): void
{
    if (!starcia_dostepne()) blad('Starcia wymagają migracji 080.');
    if (function_exists('floty_aktualizuj_ruch')) floty_aktualizuj_ruch();
    $db = polaczenie_bazy();
    $mg = ($u['rola'] ?? '') === 'mg';
    $w = $post ? wejscie() : [];
    $id = (int) ($w['id'] ?? $_GET['id'] ?? 0);
    if ($akcja === 'starcia_lista') odpowiedz(['starcia' => starcia_moje($u), 'teraz' => czas_teraz(), 'mg' => $mg]);
    if (!$post) blad('Zła metoda.', 405);
    $s = starcie_wiersz($id);
    if (!$s) blad('Nie ma takiego starcia.', 404);
    $strona = $mg ? 'mg' : starcie_moja_strona($u, $id);
    if (!$strona) blad('Nie uczestniczysz w tym starciu.', 403);
    $otwarte = in_array($s['status'], STARCIE_STATUSY_OTWARTE, true);
    switch ($akcja) {
        case 'starcie_propozycja':
            // Propozycja terminu walki (czas uniksowy); druga strona ją przyjmuje. Nowa propozycja tej samej strony
            // zastępuje poprzednią.
            if (!$otwarte) blad('Starcie jest już rozstrzygnięte.');
            $termin = (int) ($w['termin'] ?? 0);
            if ($termin < czas_teraz() + STARCIE_MIN_WYPRZEDZENIE_S) blad('Termin musi być co najmniej 10 minut od teraz.');
            if ($strona === 'mg') {
                // MG ustala termin od razu.
                $db->prepare("UPDATE starcia SET status='umowione', termin_utc=? WHERE id=?")->execute([czas_utc($termin), $id]);
                starcie_zwolnij_skoki(starcie_wiersz($id), $termin);
                odpowiedz(['ok' => true, 'umowione' => true]);
            }
            $db->prepare("UPDATE starcia_propozycje SET status='zastapiona' WHERE starcie_id=? AND strona=? AND status='otwarta'")->execute([$id, $strona]);
            $db->prepare("INSERT INTO starcia_propozycje (starcie_id, login, strona, termin_utc, status) VALUES (?,?,?,?, 'otwarta')")
                ->execute([$id, (string) $u['login'], $strona, czas_utc($termin)]);
            odpowiedz(['ok' => true]);
        case 'starcie_akceptuj':
            if (!$otwarte) blad('Starcie jest już rozstrzygnięte.');
            $q = $db->prepare("SELECT * FROM starcia_propozycje WHERE id=? AND starcie_id=? AND status='otwarta'");
            $q->execute([(int) ($w['propozycja'] ?? 0), $id]);
            $p = $q->fetch(PDO::FETCH_ASSOC);
            if (!$p) blad('Ta propozycja jest już nieaktualna.');
            if ($strona !== 'mg' && $p['strona'] === $strona) blad('Termin przyjmuje druga strona.');
            $termin = czas_z_utc((string) $p['termin_utc']);
            if ($termin <= czas_teraz()) blad('Ten termin już minął — zaproponujcie nowy.');
            $db->prepare("UPDATE starcia_propozycje SET status='przyjeta' WHERE id=?")->execute([$p['id']]);
            $db->prepare("UPDATE starcia SET status='umowione', termin_utc=? WHERE id=?")->execute([czas_utc($termin), $id]);
            starcie_zwolnij_skoki(starcie_wiersz($id), $termin);
            odpowiedz(['ok' => true, 'umowione' => true]);
        case 'starcie_anuluj':
            // MG: odwołanie starcia (czas wraca do normy, skoki kończą się po pauzie).
            if (!$mg) blad('Starcie odwołuje MG.', 403);
            if (!$otwarte) blad('Starcie jest już rozstrzygnięte.');
            starcie_zwolnij_skoki($s, null);
            $db->prepare("UPDATE starcia SET status='anulowane', zakonczono_utc=? WHERE id=?")->execute([czas_utc(czas_teraz()), $id]);
            odpowiedz(['ok' => true]);
        case 'starcie_skutki':
            // MG: skutki bitwy wchodzą do świata (kadłub, osłony, żyły, maszyny, polegli).
            if (!$mg) blad('Skutki zatwierdza MG.', 403);
            if ($s['bitwa_id']) {
                $qb = $db->prepare('SELECT status FROM mapa_bitwy WHERE id=?');
                $qb->execute([$s['bitwa_id']]);
                if ($qb->fetchColumn() === 'aktywna') blad('Najpierw zakończ bitwę.');
            }
            odpowiedz(['ok' => true, 'skutki' => starcie_zatwierdz_skutki($s)]);
        case 'starcie_zakoncz':
            // MG: koniec bitwy (znacznik na mapie znika z radaru).
            if (!$mg) blad('Bitwę kończy MG.', 403);
            if ($s['bitwa_id']) $db->prepare("UPDATE mapa_bitwy SET status='zakonczona', koniec_utc=UTC_TIMESTAMP() WHERE id=? AND status <> 'zakonczona'")->execute([$s['bitwa_id']]);
            $db->prepare("UPDATE starcia SET status='zakonczone', zakonczono_utc=? WHERE id=?")->execute([czas_utc(czas_teraz()), $id]);
            odpowiedz(['ok' => true]);
    }
    blad('Nieznana akcja starcia.');
}

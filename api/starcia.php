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
}

// Termin walki: bitwa na mapie (rodzaj rzeczywista) w układzie starcia, uczestnicy — okręty obu stron w układzie.
function starcie_otworz_bitwe(array $s): void
{
    $db = polaczenie_bazy();
    $kod = 'starcie-' . (int) $s['id'];
    $q = $db->prepare('SELECT id FROM mapa_bitwy WHERE kod=?');
    $q->execute([$kod]);
    $bitwa = (int) ($q->fetchColumn() ?: 0);
    if (!$bitwa) {
        $rodzaj = function_exists('bitwa_dostepna') && bitwa_dostepna();
        $db->prepare('INSERT INTO mapa_bitwy (kod, nazwa, planeta_id, status, opis' . ($rodzaj ? ', rodzaj' : '') . ') VALUES (?,?,?,\'aktywna\',?' . ($rodzaj ? ",'rzeczywista'" : '') . ')')
            ->execute([$kod, $s['nazwa'], $s['planeta_id'], "{$s['agresor_frakcja']} atakuje {$s['obronca_frakcja']}. Termin umówiony przez strony."]);
        $bitwa = (int) $db->lastInsertId();
    }
    foreach (starcie_okrety_ukladu((float) $s['uklad_x'], (float) $s['uklad_y']) as $o) {
        $strona = mapa_relacja($s['agresor_frakcja'], $o['frakcja_strony']) === 'wrog' ? (string) $s['obronca_frakcja'] : (string) $s['agresor_frakcja'];
        $db->prepare('INSERT IGNORE INTO mapa_bitwy_uczestnicy (bitwa_id, obiekt_id, strona, kolor) VALUES (?,?,?,?)')
            ->execute([$bitwa, $o['id'], $strona, $strona === 'Imperium' ? '#9fb4c8' : '#ff6b55']);
    }
    $db->prepare("UPDATE starcia SET status='bitwa', bitwa_id=? WHERE id=?")->execute([$bitwa, $s['id']]);
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
            'uczestnicy' => $ucz, 'propozycje' => $prop, 'skoki' => $qk->fetchAll(PDO::FETCH_KEY_PAIR)];
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
        case 'starcie_zakoncz':
            // MG: koniec bitwy (znacznik na mapie znika z radaru).
            if (!$mg) blad('Bitwę kończy MG.', 403);
            if ($s['bitwa_id']) $db->prepare("UPDATE mapa_bitwy SET status='zakonczona', koniec_utc=UTC_TIMESTAMP() WHERE id=?")->execute([$s['bitwa_id']]);
            $db->prepare("UPDATE starcia SET status='zakonczone', zakonczono_utc=? WHERE id=?")->execute([czas_utc(czas_teraz()), $id]);
            odpowiedz(['ok' => true]);
    }
    blad('Nieznana akcja starcia.');
}

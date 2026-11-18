<?php
// WYCZYN „FOTOGRAF GWIAZD”: zdjęcia słońc odwiedzonych układów.
//
// Postać robi zdjęcie (przycisk na pulpicie), gdy słońce układu jest widoczne: na mostku słońce
// w polu widzenia okna (kurs dziobu), na planecie — dzień w lokacji. Serwer sprawdza, w jakim układzie
// postać jest naprawdę i czy słońce jest widoczne, a potem zapisuje miniaturę (JPEG) w zdjecia_gwiazd.
// Każdy układ liczy się dla konta raz. Postęp = sfotografowane układy / układy widoczne dla konta;
// progi i ich nazwy w wyczyny_progi (MG może je zmieniać).

declare(strict_types=1);

const ZDJECIE_MAKS_B = 160000;
const ZDJECIE_POLE_WIDZENIA = 55; // ± stopnie od dziobu (okno mostka ma ok. 100°)

// Układ, w którym jest postać, i czy słońce jest teraz widoczne: [planeta (nazwa), miejsce] albo błąd.
function gwiazdy_gdzie_slonce(array $u): array
{
    $teraz = czas_teraz();
    $klucz = wskazniki_klucz_postaci((string) $u['id']);
    if ($klucz !== null && str_starts_with($klucz, 'okret:')) {
        $id = (int) substr($klucz, 6);
        floty_aktualizuj_ruch();
        $q = polaczenie_bazy()->prepare("SELECT 1 FROM mapa_rozkazy_skokow_jednostki j JOIN mapa_rozkazy_skokow r ON r.id=j.rozkaz_id
            WHERE j.obiekt_id=? AND j.status='w_nadprzestrzeni' AND COALESCE(r.wejscie_utc, r.wydano_utc) <= ? LIMIT 1");
        $q->execute([$id, czas_utc($teraz)]);
        if ($q->fetchColumn()) blad('W nadprzestrzeni nie ma słońca do sfotografowania.');
        $q = polaczenie_bazy()->prepare('SELECT m.x, m.y, m.kurs_deg, m.kurs_start_deg, m.kurs_cel_deg, m.manewr_od_utc, m.manewr_do_utc, p.nazwa AS planeta
            FROM mapa_obiekty m LEFT JOIN mapa_planety p ON p.id=m.planeta_id WHERE m.id=?');
        $q->execute([$id]);
        $o = $q->fetch(PDO::FETCH_ASSOC);
        $planeta = (string) ($o['planeta'] ?? '');
        if ($planeta === '' && $o) $planeta = (string) (scena_planeta_przy((float) $o['x'], (float) $o['y'])['nazwa'] ?? '');
        if ($planeta === '') blad('W pustej przestrzeni nie ma słońca układu do sfotografowania.');
        $roznica = abs(fmod(astro_kat_slonca(astro_cykl($planeta), $teraz) - floty_kurs_teraz($o, $teraz) + 540, 360) - 180);
        if ($roznica > ZDJECIE_POLE_WIDZENIA) blad('Słońca nie widać przez okno. Obróć okręt dziobem w jego stronę.');
        return [$planeta, 'mostek'];
    }
    if ($klucz !== null && str_starts_with($klucz, 's:')) {
        $s = ruch_statek($klucz);
        if (!empty($s['cel'])) blad('W nadprzestrzeni nie ma słońca do sfotografowania.');
        $planeta = (string) (ruch_planeta((string) ($s['lokalizacja'] ?? ''))['nazwa'] ?? '');
        if ($planeta === '' || $planeta === '?') blad('Nie wiadomo, w jakim układzie jest statek.');
        if (!empty($s['w_locie'])) return [$planeta, 'mostek'];
    }
    // Na planecie (albo w statku w hangarze): słońce musi być nad horyzontem.
    $karta = karta((string) $u['id']);
    $gdzie = ruch_gdzie($karta, ruch_lokacje());
    if (!$gdzie) blad('Nie wiadomo, gdzie jesteś.');
    $pl = ruch_planeta((string) ($gdzie['planeta'] ?? ''));
    $planeta = (string) ($pl['nazwa'] ?? ($gdzie['planeta_nazwa'] ?? ''));
    if ($planeta === '' || $planeta === '?') blad('Nie wiadomo, w jakim układzie jesteś.');
    $sw = astro_swiatlo($planeta, $gdzie);
    if (astro_wysokosc_slonca($sw['cykl'], $sw['szer'], $sw['dl'], $teraz) < -0.833) blad('Słońce jest pod horyzontem. Poczekaj na dzień.');
    return [$planeta, 'powierzchnia'];
}

// Liczba układów widocznych dla konta (mianownik postępu).
function gwiazdy_liczba_ukladow(array $u): int
{
    $db = polaczenie_bazy();
    if (!tabela_sql_dostepna('planety_gry')) return (int) $db->query('SELECT COUNT(*) FROM mapa_planety')->fetchColumn();
    $dostep = tabela_sql_dostepna('planety_widocznosc_konta')
        ? ' OR EXISTS (SELECT 1 FROM planety_widocznosc_konta d WHERE d.planeta_id=p.id AND LOWER(d.konto_login)=LOWER(?))' : '';
    $q = $db->prepare('SELECT COUNT(*) FROM mapa_planety p LEFT JOIN planety_gry g ON g.planeta_id=p.id WHERE COALESCE(g.zastrzezona,0)=0' . $dostep);
    $q->execute($dostep ? [(string) $u['login']] : []);
    return (int) $q->fetchColumn();
}

function gwiazdy_progi(): array
{
    if (!tabela_sql_dostepna('wyczyny_progi')) return [];
    return array_map(fn($r) => ['procent' => (float) $r['procent'], 'nazwa' => $r['nazwa']],
        polaczenie_bazy()->query('SELECT procent, nazwa FROM wyczyny_progi ORDER BY procent')->fetchAll(PDO::FETCH_ASSOC));
}

function gwiazdy_postep(array $u, int $liczba): array
{
    $wszystkie = max(1, gwiazdy_liczba_ukladow($u));
    $procent = round(100 * $liczba / $wszystkie, 2);
    $prog = null; $nastepny = null;
    foreach (gwiazdy_progi() as $p) {
        if ($procent >= $p['procent']) $prog = $p;
        elseif ($nastepny === null) $nastepny = $p;
    }
    return ['liczba' => $liczba, 'wszystkie' => $wszystkie, 'procent' => $procent, 'prog' => $prog, 'nastepny' => $nastepny];
}

function obsluz_gwiazdy(string $akcja, bool $post): void
{
    $akcje = ['zdjecie_slonca', 'album_gwiazd', 'zdjecie_slonca_obraz', 'mg_wyczyny', 'mg_wyczyny_progi_zapisz'];
    if (!in_array($akcja, $akcje, true)) return;
    if (!tabela_sql_dostepna('zdjecia_gwiazd')) blad('Archiwum zdjęć nie odpowiada. (Brak migracji 021.)');
    $db = polaczenie_bazy();
    switch ($akcja) {
        case 'zdjecie_slonca':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            [$planeta, $miejsce] = gwiazdy_gdzie_slonce($u);
            $cykl = astro_cykl($planeta);
            if (empty($cykl['mapa_id']) || !mapa_planeta_widoczna((int) $cykl['mapa_id'], $u)) blad('Tego układu nie ma w rejestrze.');
            $obraz = (string) (wejscie()['obraz'] ?? '');
            if (!str_starts_with($obraz, 'data:image/jpeg;base64,')) blad('Aparat nie zapisał zdjęcia.');
            $dane = base64_decode(substr($obraz, 23), true);
            if ($dane === false || strlen($dane) > ZDJECIE_MAKS_B || !str_starts_with($dane, "\xFF\xD8")) blad('Zdjęcie jest uszkodzone albo za duże.');
            $q = $db->prepare('INSERT IGNORE INTO zdjecia_gwiazd (konto_login, planeta_id, gwiazda_typ, miejsce, zrobiono_utc, miniatura) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?)');
            $q->bindValue(1, (string) $u['login']);
            $q->bindValue(2, (int) $cykl['mapa_id'], PDO::PARAM_INT);
            $q->bindValue(3, (string) ($cykl['gwiazdy'][0]['typ'] ?? 'G'));
            $q->bindValue(4, $miejsce);
            $q->bindValue(5, $dane, PDO::PARAM_LOB);
            $q->execute();
            $nowe = $q->rowCount() === 1;
            $q = $db->prepare('SELECT COUNT(*) FROM zdjecia_gwiazd WHERE konto_login=?');
            $q->execute([(string) $u['login']]);
            odpowiedz(['ok' => true, 'nowe' => $nowe, 'uklad' => $planeta, 'gwiazda' => $cykl['gwiazdy'][0]['nazwa'] ?? '',
                'postep' => gwiazdy_postep($u, (int) $q->fetchColumn())]);

        case 'album_gwiazd':
            $u = wymagaj_logowania();
            $q = $db->prepare('SELECT z.id, z.gwiazda_typ, z.miejsce, z.zrobiono_utc, p.nazwa AS uklad FROM zdjecia_gwiazd z
                JOIN mapa_planety p ON p.id=z.planeta_id WHERE z.konto_login=? ORDER BY z.zrobiono_utc DESC');
            $q->execute([(string) $u['login']]);
            $zdjecia = array_map(fn($z) => ['id' => (int) $z['id'], 'uklad' => $z['uklad'], 'typ' => $z['gwiazda_typ'],
                'gwiazda' => ASTRO_GWIAZDY[$z['gwiazda_typ']][0] ?? $z['gwiazda_typ'], 'miejsce' => $z['miejsce'],
                'zrobiono' => czas_z_utc($z['zrobiono_utc'])], $q->fetchAll(PDO::FETCH_ASSOC));
            odpowiedz(['zdjecia' => $zdjecia, 'postep' => gwiazdy_postep($u, count($zdjecia)), 'progi' => gwiazdy_progi()]);

        case 'zdjecie_slonca_obraz':
            $u = wymagaj_logowania();
            $q = $db->prepare('SELECT konto_login, miniatura FROM zdjecia_gwiazd WHERE id=?');
            $q->execute([(int) ($_GET['id'] ?? 0)]);
            $z = $q->fetch(PDO::FETCH_ASSOC);
            if (!$z || (mb_strtolower($z['konto_login']) !== mb_strtolower((string) $u['login']) && ($u['rola'] ?? '') !== 'mg')) blad('Brak zdjęcia.', 404);
            header('Content-Type: image/jpeg');
            header('Cache-Control: private, max-age=86400');
            echo $z['miniatura'];
            exit;

        case 'mg_wyczyny':
            wymagaj_mg();
            $liczby = $db->query('SELECT konto_login, COUNT(*) AS ile, MAX(zrobiono_utc) AS ostatnie FROM zdjecia_gwiazd GROUP BY konto_login')->fetchAll(PDO::FETCH_ASSOC);
            $wg = array_column($liczby, null, 'konto_login');
            $gracze = [];
            foreach (uzytkownicy() as $k) {
                if (($k['rola'] ?? '') === 'mg') continue;
                $l = $wg[$k['login']] ?? null;
                $gracze[] = ['login' => $k['login'], 'postac' => $k['postac'] ?? $k['login'], 'ostatnie' => $l ? czas_z_utc($l['ostatnie']) : 0]
                    + gwiazdy_postep($k, (int) ($l['ile'] ?? 0));
            }
            odpowiedz(['gracze' => $gracze, 'progi' => gwiazdy_progi()]);

        case 'mg_wyczyny_progi_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $progi = [];
            foreach ((array) (wejscie()['progi'] ?? []) as $p) {
                $procent = round((float) ($p['procent'] ?? 0), 2);
                $nazwa = mb_substr(trim((string) ($p['nazwa'] ?? '')), 0, 80);
                if ($procent > 0 && $procent <= 100 && $nazwa !== '') $progi[(string) $procent] = $nazwa;
            }
            if (!$progi) blad('Podaj co najmniej jeden próg (procent 0–100 i nazwa).');
            $db->beginTransaction();
            try {
                $db->exec('DELETE FROM wyczyny_progi');
                $q = $db->prepare('INSERT INTO wyczyny_progi (procent, nazwa) VALUES (?, ?)');
                foreach ($progi as $procent => $nazwa) $q->execute([$procent, $nazwa]);
                $db->commit();
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            odpowiedz(['ok' => true]);
    }
}

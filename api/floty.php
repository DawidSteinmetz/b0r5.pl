<?php
// SQL-owy system dowodzenia flotami i automatycznych skoków jednostek mapy.

declare(strict_types=1);

function floty_gotowe(): void
{
    if (!tabela_sql_dostepna('mapa_floty') || !tabela_sql_dostepna('mapa_floty_jednostki')
        || !tabela_sql_dostepna('mapa_naped_jednostki') || !tabela_sql_dostepna('mapa_rozkazy_skokow')) {
        blad('Najpierw zaimportuj api/migracje/006_floty_i_nawigacja.sql.');
    }
    try { polaczenie_bazy()->query('SELECT kolejnosc FROM mapa_floty_jednostki LIMIT 0'); }
    catch (Throwable $e) { blad('Zaimportuj jeszcze api/migracje/007_kolejnosc_okretow_floty.sql.'); }
    try { polaczenie_bazy()->query('SELECT dowodca_osoba_id FROM mapa_floty LIMIT 0'); }
    catch (Throwable $e) { blad('Zaimportuj jeszcze api/migracje/009_flota_dromund_kaas.sql.'); }
}

function floty_dowodca(int $flotaId): ?string
{
    $db = polaczenie_bazy();
    $seen = [];
    while ($flotaId > 0 && !isset($seen[$flotaId])) {
        $seen[$flotaId] = true;
        $q = $db->prepare('SELECT f.dowodca_login,f.dowodca_osoba_id,f.rodzic_id,f.status,p.nazwa AS npc_nazwa FROM mapa_floty f LEFT JOIN osoby o ON o.podmiot_id=f.dowodca_osoba_id LEFT JOIN podmioty p ON p.id=o.podmiot_id WHERE f.id=?');
        $q->execute([$flotaId]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        if (($r['status'] ?? '') === 'nieaktywna') return null;
        if (trim((string) ($r['dowodca_login'] ?? '')) !== '') return (string) $r['dowodca_login'];
        if (trim((string) ($r['npc_nazwa'] ?? '')) !== '') return (string) $r['npc_nazwa'] . ' (NPC)';
        $flotaId = (int) ($r['rodzic_id'] ?? 0);
    }
    return null;
}

function floty_czy_dowodca(int $flotaId, string $login): bool
{
    $dowodca = floty_dowodca($flotaId);
    return $dowodca !== null && mb_strtolower($dowodca) === mb_strtolower($login);
}

function floty_moje_jednostki(string $login): array
{
    $q = polaczenie_bazy()->query('SELECT id,rodzic_id,dowodca_login FROM mapa_floty');
    $dozwolone = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $f) {
        if (floty_czy_dowodca((int) $f['id'], $login)) $dozwolone[(int) $f['id']] = true;
    }
    if (!$dozwolone) return [];
    $ids = array_keys($dozwolone);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $q = polaczenie_bazy()->prepare("SELECT j.obiekt_id,j.flota_id,j.rola,j.tie_liczba FROM mapa_floty_jednostki j WHERE j.flota_id IN ($marks)");
    $q->execute($ids);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function floty_aktualizuj_ruch(): void
{
    if (!tabela_sql_dostepna('mapa_rozkazy_skokow_jednostki')) return;
    $db = polaczenie_bazy();
    $q = $db->query("SELECT j.rozkaz_id,j.obiekt_id,j.start_x,j.start_y,j.wyjscie_x,j.wyjscie_y,j.eta_utc,j.paliwo_zuzyte,j.planeta_id,r.wydano_utc
        FROM mapa_rozkazy_skokow_jednostki j JOIN mapa_rozkazy_skokow r ON r.id=j.rozkaz_id
        WHERE j.status='w_nadprzestrzeni' AND r.status='w_nadprzestrzeni'");
    $teraz = time();
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $start = strtotime((string) $r['wydano_utc'] . ' UTC');
        $eta = strtotime((string) $r['eta_utc'] . ' UTC');
        $koniecX = (float) $r['wyjscie_x'];
        $koniecY = (float) $r['wyjscie_y'];
        if (!$start || !$eta || $eta <= $start || $teraz >= $eta) {
            $planeta = (int) ($r['planeta_id'] ?? 0) ?: null;
            if ($planeta === null) {
                $p = $db->prepare('SELECT id FROM mapa_planety WHERE ABS(x-?) < 2 AND ABS(y-?) < 2 ORDER BY POW(x-?,2)+POW(y-?,2) LIMIT 1');
                $p->execute([$koniecX, $koniecY, $koniecX, $koniecY]);
                $planeta = $p->fetchColumn() ?: null;
            }
            $db->prepare('UPDATE mapa_obiekty SET x=?,y=?,planeta_id=? WHERE id=?')->execute([$koniecX, $koniecY, $planeta, $r['obiekt_id']]);
            $db->prepare('UPDATE mapa_naped_jednostki SET paliwo=GREATEST(0,paliwo-?) WHERE obiekt_id=?')->execute([$r['paliwo_zuzyte'], $r['obiekt_id']]);
            $db->prepare("UPDATE mapa_rozkazy_skokow_jednostki SET status='dotarl' WHERE rozkaz_id=? AND obiekt_id=?")->execute([$r['rozkaz_id'], $r['obiekt_id']]);
            continue;
        }
        $postep = max(0, min(1, ($teraz - $start) / ($eta - $start)));
        $x = (float) $r['start_x'] + ($koniecX - (float) $r['start_x']) * $postep;
        $y = (float) $r['start_y'] + ($koniecY - (float) $r['start_y']) * $postep;
        $db->prepare('UPDATE mapa_obiekty SET x=?,y=?,planeta_id=NULL WHERE id=?')->execute([$x, $y, $r['obiekt_id']]);
    }
    $db->exec("UPDATE mapa_rozkazy_skokow r SET r.status='dotarl',r.zakonczono_utc=UTC_TIMESTAMP()
        WHERE r.status='w_nadprzestrzeni' AND NOT EXISTS (
          SELECT 1 FROM mapa_rozkazy_skokow_jednostki j WHERE j.rozkaz_id=r.id AND j.status='w_nadprzestrzeni'
        )");
}

function floty_lista(string $login, bool $mg = false): array
{
    floty_aktualizuj_ruch();
    $db = polaczenie_bazy();
    $all = $db->query("SELECT f.id,f.kod,f.nazwa,f.dowodca_login,f.dowodca_osoba_id,p.nazwa AS dowodca_npc_nazwa,f.rodzic_id,f.status,f.opis FROM mapa_floty f LEFT JOIN osoby o ON o.podmiot_id=f.dowodca_osoba_id LEFT JOIN podmioty p ON p.id=o.podmiot_id WHERE f.status<>'nieaktywna' ORDER BY f.nazwa")->fetchAll(PDO::FETCH_ASSOC);
    $dozwolone = [];
    foreach ($all as $f) if ($mg || floty_czy_dowodca((int) $f['id'], $login)) $dozwolone[(int) $f['id']] = true;
    $out = [];
    $q = $db->prepare("SELECT o.id AS obiekt_id,o.kod AS obiekt_kod,o.nazwa AS obiekt_nazwa,o.typ,o.rodzaj,o.klasa,o.status AS stan,o.planeta_id,p.nazwa AS planeta,o.x,o.y,
        j.flota_id,j.rola,j.kolejnosc,j.tie_liczba,
        COALESCE(n.moc_reaktora,100) AS moc_reaktora,COALESCE(n.moc_hipernapedu,80) AS moc_hipernapedu,COALESCE(n.klasa_hipernapedu,1) AS klasa_hipernapedu,
        COALESCE(n.predkosc_pc_na_godzine,1000) AS predkosc_pc_na_godzine,COALESCE(n.paliwo,100000) AS paliwo,COALESCE(n.paliwo_maks,100000) AS paliwo_maks,
        COALESCE(n.paliwo_na_parsek,0.01) AS paliwo_na_parsek,COALESCE(n.sprawnosc_reaktora,100) AS sprawnosc_reaktora,COALESCE(n.sprawnosc_hipernapedu,100) AS sprawnosc_hipernapedu,COALESCE(n.szum_bazowy,0.05) AS szum_bazowy,
        r.id AS rozkaz_id,r.status AS rozkaz_status,r.cel_x,r.cel_y,r.eta_utc
        FROM mapa_floty_jednostki j JOIN mapa_obiekty o ON o.id=j.obiekt_id
        LEFT JOIN mapa_planety p ON p.id=o.planeta_id LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id=o.id
        LEFT JOIN mapa_rozkazy_skokow_jednostki rj ON rj.obiekt_id=o.id AND rj.status='w_nadprzestrzeni'
        LEFT JOIN mapa_rozkazy_skokow r ON r.id=rj.rozkaz_id AND r.status='w_nadprzestrzeni'
        WHERE j.flota_id=? ORDER BY j.kolejnosc,o.nazwa");
    foreach ($all as $f) {
        if (!isset($dozwolone[(int) $f['id']])) continue;
        $q->execute([(int) $f['id']]);
        $units = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($units as &$u) {
            foreach (['obiekt_id','flota_id','tie_liczba','kolejnosc','planeta_id','rozkaz_id'] as $k) if ($u[$k] !== null) $u[$k] = (int) $u[$k];
            foreach (['x','y','moc_reaktora','moc_hipernapedu','klasa_hipernapedu','predkosc_pc_na_godzine','paliwo','paliwo_maks','szum_bazowy','cel_x','cel_y'] as $k) if ($u[$k] !== null) $u[$k] = (float) $u[$k];
            if ($u['eta_utc']) $u['eta'] = strtotime($u['eta_utc'] . ' UTC');
        }
        unset($u);
        $out[] = $f + ['dowodca_efektywny' => floty_dowodca((int) $f['id']), 'jednostki' => $units];
    }
    return $out;
}

function floty_jednostka_moze(string $login, int $obiektId): ?array
{
    $q = polaczenie_bazy()->prepare('SELECT j.flota_id FROM mapa_floty_jednostki j WHERE j.obiekt_id=?');
    $q->execute([$obiektId]);
    $flotaId = $q->fetchColumn();
    if (!$flotaId || !floty_czy_dowodca((int) $flotaId, $login)) return null;
    return ['flota_id' => (int) $flotaId];
}

function floty_odbiorca_widoczny(string $login, int $obiektId): bool
{
    if (floty_jednostka_moze($login, $obiektId)) return true;
    $q = polaczenie_bazy()->prepare('SELECT 1 FROM mapa_obiekty_dostep WHERE obiekt_id=? AND LOWER(konto_login)=LOWER(?)');
    $q->execute([$obiektId, $login]);
    return (bool) $q->fetchColumn();
}

function floty_rozkaz(string $login, array $w): array
{
    $db = polaczenie_bazy();
    floty_aktualizuj_ruch();
    $flotaId = (int) ($w['flota_id'] ?? 0);
    $obiektId = (int) ($w['obiekt_id'] ?? 0);
    $odbiorcy = [];
    if (isset($w['jednostki']) && is_array($w['jednostki'])) {
        $odbiorcy = array_values(array_unique(array_filter(array_map('intval', $w['jednostki']))));
        if (!$flotaId || !floty_czy_dowodca($flotaId, $login)) blad('Nie dowodzisz tą flotą.', 403);
        if (!$odbiorcy) blad('Zaznacz jednostki do rozkazu.');
        $marks = implode(',', array_fill(0, count($odbiorcy), '?'));
        $q = $db->prepare("SELECT obiekt_id,flota_id FROM mapa_floty_jednostki WHERE obiekt_id IN ($marks)");
        $q->execute($odbiorcy);
        $przypisania = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($przypisania) !== count($odbiorcy)) blad('Jedna z wybranych jednostek nie ma przypisanej floty.');
        foreach ($przypisania as $fid) if (!floty_czy_dowodca((int) $fid, $login)) blad('Nie dowodzisz wszystkimi wybranymi jednostkami.', 403);
    } elseif ($obiektId) {
        $m = floty_jednostka_moze($login, $obiektId);
        if (!$m) blad('Nie dowodzisz tą jednostką.', 403);
        $flotaId = $m['flota_id'];
        $odbiorcy = [$obiektId];
    } elseif ($flotaId && floty_czy_dowodca($flotaId, $login)) {
        $q = $db->prepare('SELECT obiekt_id FROM mapa_floty_jednostki WHERE flota_id=?');
        $q->execute([$flotaId]);
        $odbiorcy = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    } else blad('Nie dowodzisz tą flotą.', 403);
    if (!$odbiorcy) blad('Ta grupa nie ma jednostek.');
    $celObiekt = (int) ($w['cel_obiekt_id'] ?? 0);
    if ($celObiekt) {
        if (!floty_odbiorca_widoczny($login, $celObiekt)) blad('Nie masz dostępu do pozycji wskazanej jednostki.', 403);
        $q = $db->prepare('SELECT x,y,planeta_id FROM mapa_obiekty WHERE id=? AND aktywny=1');
        $q->execute([$celObiekt]);
        $cel = $q->fetch(PDO::FETCH_ASSOC);
        if (!$cel || $cel['x'] === null || $cel['y'] === null) blad('Wskazana jednostka nie ma pozycji.');
        if ($cel['planeta_id'] !== null && !mapa_planeta_widoczna((int) $cel['planeta_id'], ja())) blad('Nie masz dostępu do wskazanej pozycji.', 403);
        $x = (float) $cel['x']; $y = (float) $cel['y'];
    } else {
        $x = $w['x'] ?? null; $y = $w['y'] ?? null;
        if (!is_numeric($x) || !is_numeric($y)) blad('Podaj współrzędne X i Y albo wskaż inną jednostkę.');
        $x = (float) $x; $y = (float) $y;
        if (abs($x) > 1000000 || abs($y) > 1000000) blad('Współrzędne wykraczają poza zakres mapy.');
        $q = $db->prepare('SELECT id FROM mapa_planety WHERE ABS(x-?)<2 AND ABS(y-?)<2 ORDER BY POW(x-?,2)+POW(y-?,2) LIMIT 1');
        $q->execute([$x,$y,$x,$y]);
        $planetaCel = $q->fetchColumn();
        if ($planetaCel && !mapa_planeta_widoczna((int) $planetaCel, ja())) blad('Nie masz dostępu do wskazanego obszaru.', 403);
    }
    $ids = implode(',', array_fill(0, count($odbiorcy), '?'));
    $q = $db->prepare("SELECT o.id,o.nazwa,o.x,o.y,n.* FROM mapa_obiekty o LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id=o.id WHERE o.id IN ($ids) AND o.aktywny=1");
    $q->execute($odbiorcy);
    $jednostki = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($jednostki) !== count($odbiorcy)) blad('Brak pozycji lub parametrów napędu jednej z jednostek.');
    $teraz = czas_teraz(); $wyliczone = []; $najpozniej = $teraz;
    foreach ($jednostki as $j) {
        if ($j['x'] === null || $j['y'] === null) blad('Jednostka „' . $j['nazwa'] . '” nie ma pozycji mapowej.');
        $aktywny = $db->prepare("SELECT 1 FROM mapa_rozkazy_skokow_jednostki WHERE obiekt_id=? AND status='w_nadprzestrzeni' LIMIT 1");
        $aktywny->execute([$j['id']]);
        if ($aktywny->fetchColumn()) blad('Jednostka „' . $j['nazwa'] . '” już wykonuje skok.');
        if ($j['moc_reaktora'] === null) blad('Jednostka „' . $j['nazwa'] . '” nie ma profilu napędu.');
        $dx = $x - (float) $j['x']; $dy = $y - (float) $j['y']; $dystans = hypot($dx, $dy);
        if ($dystans < 0.01) blad('Jednostka „' . $j['nazwa'] . '” już jest w punkcie docelowym.');
        $stanReaktora = max(0, min(100, (int) $j['sprawnosc_reaktora'])) / 100;
        $stanNapedu = max(0, min(100, (int) $j['sprawnosc_hipernapedu'])) / 100;
        $mocWzgl = ((float) $j['moc_reaktora'] * $stanReaktora) / max(0.01, (float) $j['moc_hipernapedu']);
        if ($mocWzgl < 0.25) blad('Reaktor jednostki „' . $j['nazwa'] . '” nie zapewnia mocy do skoku.');
        $mnoznikMocy = min(1, $mocWzgl);
        $szybkosc = max(0.01, (float) $j['predkosc_pc_na_godzine']) / max(0.1, (float) $j['klasa_hipernapedu']) * $mnoznikMocy * max(0.1, $stanNapedu);
        $czasGryGodz = $dystans / $szybkosc;
        $eta = $teraz + czas_trwania($czasGryGodz * 60);
        $paliwoZuzyte = $dystans * (float) $j['paliwo_na_parsek'];
        if ((float) $j['paliwo'] < $paliwoZuzyte) blad('Jednostka „' . $j['nazwa'] . '” ma za mało paliwa.');
        $bladMax = max((float) $j['szum_bazowy'], 0) + (100 - min((int) $j['sprawnosc_reaktora'], (int) $j['sprawnosc_hipernapedu'])) * 0.3 + (1 - $mnoznikMocy) * 10;
        $kat = random_int((int) round(-$bladMax * 1000), (int) round($bladMax * 1000)) / 1000;
        $kierunek = atan2($dy, $dx) + deg2rad($kat);
        $koniecX = (float) $j['x'] + $dystans * cos($kierunek);
        $koniecY = (float) $j['y'] + $dystans * sin($kierunek);
        $najpozniej = max($najpozniej, $eta);
        $wyliczone[] = ['id' => (int) $j['id'], 'nazwa' => $j['nazwa'], 'x0' => (float) $j['x'], 'y0' => (float) $j['y'],
            'x1' => $koniecX, 'y1' => $koniecY, 'kat' => $kat, 'moc' => $mnoznikMocy, 'paliwo' => $paliwoZuzyte, 'eta' => $eta];
    }
    $db->beginTransaction();
    try {
        $kod = 'skok-' . bin2hex(random_bytes(8));
        $q = $db->prepare("INSERT INTO mapa_rozkazy_skokow (kod,wydajacy_login,flota_id,cel_obiekt_id,cel_x,cel_y,status,wydano_utc,eta_utc,opis)
            VALUES (?,?,?,?,?,?,'w_nadprzestrzeni',?,?,?)");
        $q->execute([$kod, $login, $flotaId ?: null, $celObiekt ?: null, $x, $y, czas_utc($teraz), czas_utc($najpozniej), 'Automatyczny skok: reaktor, moc hipernapędu i odchylenie nawigacyjne.']);
        $rozkazId = (int) $db->lastInsertId();
        $q = $db->prepare("INSERT INTO mapa_rozkazy_skokow_jednostki (rozkaz_id,obiekt_id,start_x,start_y,wyjscie_x,wyjscie_y,planeta_id,kat_bledu,moc_wzgl,paliwo_zuzyte,eta_utc,status)
            VALUES (?,?,?,?,?,?,?, ?,?,?,?,'w_nadprzestrzeni')");
        foreach ($wyliczone as $j) $q->execute([$rozkazId,$j['id'],$j['x0'],$j['y0'],$j['x1'],$j['y1'],$celObiekt ? ($cel['planeta_id'] ?: null) : null,$j['kat'],$j['moc'],$j['paliwo'],czas_utc($j['eta'])]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return ['ok' => true, 'rozkaz_id' => $rozkazId, 'cel' => ['x' => $x, 'y' => $y], 'eta' => $najpozniej,
        'jednostki' => array_map(static fn($j) => ['id' => $j['id'],'nazwa' => $j['nazwa'],'szum_stopnie' => $j['kat'],'eta' => $j['eta']], $wyliczone)];
}

function obsluz_floty(string $akcja, bool $post): void
{
    $akcje = ['mapa_floty_gracza','mapa_flota_podziel','mapa_flota_rozkaz','mapa_rozkaz_anuluj',
        'mg_floty','mg_flota_zapisz','mg_flota_jednostki','mg_flota_usun','mg_naped_zapisz','mg_mapa_teleport'];
    if (!in_array($akcja, $akcje, true)) return;
    floty_gotowe();
    switch ($akcja) {
        case 'mapa_floty_gracza':
            $u = wymagaj_logowania();
            odpowiedz(['floty' => floty_lista((string) $u['login'])]);

        case 'mapa_flota_podziel':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania(); $w = wejscie();
            $rodzic = (int) ($w['flota_id'] ?? 0);
            if (!floty_czy_dowodca($rodzic, (string) $u['login'])) blad('Nie dowodzisz tą flotą.', 403);
            $nazwa = mb_substr(trim((string) ($w['nazwa'] ?? '')), 0, 160);
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($w['jednostki'] ?? [])))));
            if ($nazwa === '' || !$ids) blad('Podaj nazwę nowej grupy i wybierz jej jednostki.');
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $q = polaczenie_bazy()->prepare("SELECT obiekt_id FROM mapa_floty_jednostki WHERE flota_id=? AND obiekt_id IN ($marks)");
            $q->execute([$rodzic, ...$ids]);
            if (count($q->fetchAll(PDO::FETCH_COLUMN)) !== count($ids)) blad('Możesz przenosić tylko jednostki z wybranej grupy.');
            $busy = polaczenie_bazy()->prepare("SELECT COUNT(*) FROM mapa_rozkazy_skokow_jednostki WHERE obiekt_id IN ($marks) AND status='w_nadprzestrzeni'");
            $busy->execute($ids);
            if ((int) $busy->fetchColumn()) blad('Najpierw poczekaj na zakończenie skoku wybranych jednostek.');
            $db = polaczenie_bazy(); $db->beginTransaction();
            try {
                $kod = 'grupa-' . bin2hex(random_bytes(8));
                $db->prepare('INSERT INTO mapa_floty (kod,nazwa,rodzic_id,status) VALUES (?,?,?,\'aktywna\')')->execute([$kod,$nazwa,$rodzic]);
                $nowa = (int) $db->lastInsertId();
                $q = $db->prepare("UPDATE mapa_floty_jednostki SET flota_id=? WHERE flota_id=? AND obiekt_id IN ($marks)");
                $q->execute([$nowa,$rodzic,...$ids]);
                $db->commit();
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            odpowiedz(['ok' => true, 'flota_id' => $nowa]);

        case 'mapa_flota_rozkaz':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            odpowiedz(floty_rozkaz((string) $u['login'], wejscie()));

        case 'mapa_rozkaz_anuluj':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania(); $id = (int) (wejscie()['id'] ?? 0);
            $db = polaczenie_bazy();
            $q = $db->prepare('SELECT id,flota_id,wydajacy_login FROM mapa_rozkazy_skokow WHERE id=? AND status=\'w_nadprzestrzeni\'');
            $q->execute([$id]); $r = $q->fetch(PDO::FETCH_ASSOC);
            if (!$r || (mb_strtolower($r['wydajacy_login']) !== mb_strtolower($u['login']) && (!($u['rola'] === 'mg')))) blad('Nie możesz anulować tego rozkazu.', 403);
            floty_aktualizuj_ruch();
            $db->prepare("UPDATE mapa_rozkazy_skokow_jednostki SET status='przerwany' WHERE rozkaz_id=? AND status='w_nadprzestrzeni'")->execute([$id]);
            $db->prepare("UPDATE mapa_rozkazy_skokow SET status='przerwany',zakonczono_utc=UTC_TIMESTAMP() WHERE id=?")->execute([$id]);
            odpowiedz(['ok' => true]);

        case 'mg_floty':
            wymagaj_mg();
            $jednostki = polaczenie_bazy()->query("SELECT o.id,o.kod,o.nazwa,o.typ,o.rodzaj,o.klasa,o.status,o.x,o.y,j.flota_id,j.rola,j.kolejnosc,j.tie_liczba,n.moc_reaktora,n.moc_hipernapedu,n.klasa_hipernapedu,n.predkosc_pc_na_godzine,n.paliwo,n.paliwo_maks,n.sprawnosc_reaktora,n.sprawnosc_hipernapedu,n.szum_bazowy,n.paliwo_na_parsek
                FROM mapa_obiekty o LEFT JOIN mapa_floty_jednostki j ON j.obiekt_id=o.id LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id=o.id
                WHERE o.aktywny=1 AND o.typ IN ('statek','jednostka','pojazd') ORDER BY o.nazwa")->fetchAll(PDO::FETCH_ASSOC);
            $npc = polaczenie_bazy()->query("SELECT o.podmiot_id AS id,p.nazwa,o.rola FROM osoby o JOIN podmioty p ON p.id=o.podmiot_id WHERE p.status='aktywny' AND o.stan<>'polegly' ORDER BY p.nazwa")->fetchAll(PDO::FETCH_ASSOC);
            odpowiedz(['floty' => floty_lista('', true), 'jednostki' => $jednostki, 'npc' => $npc,
                'konta' => array_values(array_map(static fn($x) => ['login' => $x['login'], 'postac' => $x['postac']], array_filter(uzytkownicy(), static fn($x) => ($x['rola'] ?? '') !== 'mg')))]);

        case 'mg_flota_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg(); $w = wejscie();
            $id = (int) ($w['id'] ?? 0); $nazwa = mb_substr(trim((string) ($w['nazwa'] ?? '')), 0, 160);
            $login = trim((string) ($w['dowodca_login'] ?? ''));
            $npcId = (int) ($w['dowodca_osoba_id'] ?? 0);
            if ($nazwa === '') blad('Nazwa floty jest wymagana.');
            if ($login !== '' && $npcId) blad('Wybierz dowódcę gracza albo kapitana NPC.');
            if ($login !== '' && !in_array(mb_strtolower($login), array_map('mb_strtolower', array_column(uzytkownicy(), 'login')), true)) blad('Nie ma takiego konta dowódcy.');
            $db = polaczenie_bazy();
            if ($npcId) { $q=$db->prepare("SELECT COUNT(*) FROM osoby o JOIN podmioty p ON p.id=o.podmiot_id WHERE o.podmiot_id=? AND p.status='aktywny' AND o.stan<>'polegly'"); $q->execute([$npcId]); if (!(int)$q->fetchColumn()) blad('Nie ma takiego aktywnego NPC.'); }
            if ($id) $db->prepare('UPDATE mapa_floty SET nazwa=?,dowodca_login=?,dowodca_osoba_id=? WHERE id=?')->execute([$nazwa, $login ?: null, $npcId ?: null, $id]);
            else { $db->prepare('INSERT INTO mapa_floty (kod,nazwa,dowodca_login,dowodca_osoba_id,opis) VALUES (?,?,?,?,?)')->execute(['flota-' . bin2hex(random_bytes(8)),$nazwa,$login ?: null,$npcId ?: null,mb_substr(trim((string) ($w['opis'] ?? '')),0,4000) ?: null]); $id = (int) $db->lastInsertId(); }
            odpowiedz(['ok' => true, 'flota_id' => $id]);

        case 'mg_flota_usun':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg(); $id = (int) (wejscie()['id'] ?? 0); $db = polaczenie_bazy();
            $q = $db->prepare("SELECT id FROM mapa_floty WHERE id=? AND status<>'nieaktywna'"); $q->execute([$id]);
            if (!$q->fetchColumn()) blad('Nie ma takiej aktywnej floty.', 404);
            // Likwidujemy także podgrupy. Okręty pozostają w mapie, ale stają się wolne.
            $ids = [$id];
            for ($i = 0; $i < count($ids); $i++) {
                $q = $db->prepare('SELECT id FROM mapa_floty WHERE rodzic_id=?'); $q->execute([$ids[$i]]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $child) if (!in_array((int) $child, $ids, true)) $ids[] = (int) $child;
            }
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $db->beginTransaction();
            try {
                $db->prepare("DELETE FROM mapa_floty_jednostki WHERE flota_id IN ($marks)")->execute($ids);
                $db->prepare("UPDATE mapa_floty SET status='nieaktywna',dowodca_login=NULL WHERE id IN ($marks)")->execute($ids);
                $db->commit();
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            odpowiedz(['ok' => true, 'zwolniono_flot' => count($ids)]);

        case 'mg_flota_jednostki':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg(); $w = wejscie();
            $flota = (int) ($w['flota_id'] ?? 0); $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($w['jednostki'] ?? [])))));
            $db = polaczenie_bazy(); $q = $db->prepare('SELECT id FROM mapa_floty WHERE id=?'); $q->execute([$flota]);
            if (!$q->fetchColumn()) blad('Nie ma takiej floty.', 404);
            $busy = [];
            if ($ids) {
                $marks = implode(',', array_fill(0,count($ids),'?'));
                $q = $db->prepare("SELECT COUNT(*) FROM mapa_rozkazy_skokow_jednostki WHERE obiekt_id IN ($marks) AND status='w_nadprzestrzeni'"); $q->execute($ids);
                if ((int) $q->fetchColumn()) blad('Nie można zmieniać składu podczas skoku.');
                $q = $db->prepare("SELECT COUNT(*) FROM mapa_obiekty WHERE id IN ($marks) AND typ IN ('statek','jednostka','pojazd')"); $q->execute($ids);
                if ((int) $q->fetchColumn() !== count($ids)) blad('Wybierz poprawne obiekty mapy.');
                $q = $db->prepare("SELECT j.obiekt_id,f.nazwa FROM mapa_floty_jednostki j JOIN mapa_floty f ON f.id=j.flota_id WHERE j.obiekt_id IN ($marks) AND j.flota_id<>?");
                $q->execute([...$ids,$flota]);
                if ($zajete = $q->fetch(PDO::FETCH_ASSOC)) blad('Ten okręt jest już przypisany do floty „' . $zajete['nazwa'] . '”. Najpierw usuń go z tamtego składu.');
            }
            $db->beginTransaction();
            try {
                $db->prepare('DELETE FROM mapa_floty_jednostki WHERE flota_id=?')->execute([$flota]);
                if ($ids) {
                    $q = $db->prepare('INSERT INTO mapa_floty_jednostki (obiekt_id,flota_id,rola,kolejnosc,tie_liczba) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE flota_id=VALUES(flota_id),rola=VALUES(rola),kolejnosc=VALUES(kolejnosc),tie_liczba=VALUES(tie_liczba)');
                    foreach ($ids as $index => $objectId) {
                        $tie = max(0, min(99, (int) (($w['tie'][$objectId] ?? '') === '' ? 0 : $w['tie'][$objectId])));
                        $q->execute([$objectId,$flota,mb_substr(trim((string) ($w['rola'][$objectId] ?? '')),0,80),$index,$tie ?: null]);
                    }
                    $q = $db->prepare('SELECT dowodca_login FROM mapa_floty WHERE id=?'); $q->execute([$flota]); $dowodca = $q->fetchColumn();
                    if ($dowodca) {
                        $q = $db->prepare('INSERT IGNORE INTO mapa_obiekty_dostep (obiekt_id,konto_login) VALUES (?,?)');
                        foreach ($ids as $objectId) $q->execute([$objectId,$dowodca]);
                    }
                }
                if ($ids && !empty($w['nazwy']) && is_array($w['nazwy'])) {
                    $rename = $db->prepare('UPDATE mapa_obiekty SET nazwa=? WHERE id=? AND aktywny=1');
                    foreach ($ids as $objectId) {
                        if (!array_key_exists((string)$objectId, $w['nazwy']) && !array_key_exists($objectId, $w['nazwy'])) continue;
                        $shipName = mb_substr(trim((string)($w['nazwy'][$objectId] ?? $w['nazwy'][(string)$objectId] ?? '')),0,160);
                        if ($shipName === '') blad('Każdy przydzielony statek musi mieć nazwę.');
                        $rename->execute([$shipName,$objectId]);
                    }
                }
                $db->commit();
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            odpowiedz(['ok' => true]);

        case 'mg_naped_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg(); $w = wejscie(); $id = (int) ($w['obiekt_id'] ?? 0);
            $params = [(float) ($w['moc_reaktora'] ?? 0),(float) ($w['moc_hipernapedu'] ?? 0),(float) ($w['klasa_hipernapedu'] ?? 1),
                (float) ($w['predkosc_pc_na_godzine'] ?? 0),(float) ($w['paliwo'] ?? 0),(float) ($w['paliwo_maks'] ?? 0),(float) ($w['paliwo_na_parsek'] ?? 0),
                max(0,min(100,(int) ($w['sprawnosc_reaktora'] ?? 100))),max(0,min(100,(int) ($w['sprawnosc_hipernapedu'] ?? 100))),max(0,min(30,(float) ($w['szum_bazowy'] ?? 0.05)))];
            if (!$id || min($params[0],$params[1],$params[2],$params[3],$params[5]) <= 0 || $params[4] < 0 || $params[4] > $params[5] || $params[6] < 0) blad('Sprawdź dodatnie parametry napędu i poziom paliwa.');
            $q = polaczenie_bazy()->prepare('INSERT INTO mapa_naped_jednostki (obiekt_id,moc_reaktora,moc_hipernapedu,klasa_hipernapedu,predkosc_pc_na_godzine,paliwo,paliwo_maks,paliwo_na_parsek,sprawnosc_reaktora,sprawnosc_hipernapedu,szum_bazowy) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE moc_reaktora=VALUES(moc_reaktora),moc_hipernapedu=VALUES(moc_hipernapedu),klasa_hipernapedu=VALUES(klasa_hipernapedu),predkosc_pc_na_godzine=VALUES(predkosc_pc_na_godzine),paliwo=VALUES(paliwo),paliwo_maks=VALUES(paliwo_maks),paliwo_na_parsek=VALUES(paliwo_na_parsek),sprawnosc_reaktora=VALUES(sprawnosc_reaktora),sprawnosc_hipernapedu=VALUES(sprawnosc_hipernapedu),szum_bazowy=VALUES(szum_bazowy)');
            $q->execute([$id,...$params]); odpowiedz(['ok' => true]);

        case 'mg_mapa_teleport':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_mg(); $w = wejscie();
            if (!is_numeric($w['x'] ?? null) || !is_numeric($w['y'] ?? null)) blad('Podaj współrzędne X i Y.');
            $x = (float) $w['x']; $y = (float) $w['y'];
            if (abs($x)>1000000 || abs($y)>1000000) blad('Współrzędne wykraczają poza zakres mapy.');
            floty_aktualizuj_ruch();
            $ids = [];
            if (!empty($w['obiekt_id'])) $ids[] = (int) $w['obiekt_id'];
            elseif (!empty($w['flota_id'])) {
                $q = polaczenie_bazy()->prepare('SELECT obiekt_id FROM mapa_floty_jednostki WHERE flota_id=?'); $q->execute([(int) $w['flota_id']]); $ids = array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
            }
            if (!$ids) blad('Wybierz jednostkę lub flotę.');
            $db = polaczenie_bazy(); $marks=implode(',',array_fill(0,count($ids),'?')); $db->beginTransaction();
            try {
                $q=$db->prepare("SELECT DISTINCT rozkaz_id FROM mapa_rozkazy_skokow_jednostki WHERE obiekt_id IN ($marks) AND status='w_nadprzestrzeni'"); $q->execute($ids);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                    $db->prepare("UPDATE mapa_rozkazy_skokow_jednostki SET status='przerwany' WHERE rozkaz_id=? AND status='w_nadprzestrzeni'")->execute([$rid]);
                    $db->prepare("UPDATE mapa_rozkazy_skokow SET status='przerwany',zakonczono_utc=UTC_TIMESTAMP(),opis=CONCAT(COALESCE(opis,''),' | Przerwano teleportem MG.') WHERE id=?")->execute([$rid]);
                }
                $q=$db->prepare('UPDATE mapa_obiekty SET x=?,y=?,planeta_id=(SELECT id FROM mapa_planety WHERE ABS(x-?)<2 AND ABS(y-?)<2 ORDER BY POW(x-?,2)+POW(y-?,2) LIMIT 1) WHERE id IN (' . $marks . ')');
                $q->execute([$x,$y,$x,$y,$x,$y,...$ids]);
                $reason=mb_substr(trim((string) ($w['powod'] ?? '')),0,500);
                $q=$db->prepare('INSERT INTO mapa_teleporty_mg (obiekt_id,autor_login,x,y,powod) VALUES (?,?,?,?,?)');
                foreach ($ids as $objectId) $q->execute([$objectId,$u['login'],$x,$y,$reason]);
                $db->commit();
            } catch (Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
            odpowiedz(['ok'=>true,'ile'=>count($ids)]);
    }
}

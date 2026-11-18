<?php
// CZAS ŚWIATA i RESYNCHRONIZACJA.
//
// Czas w grze płynie 1:1 z rzeczywistym: minuta świata = 60 sekund. Nie ma globalnego „tempa”.
// Każdy proces w czasie (skok floty, podróż, obsługa naziemna statku) ma swój koniec liczony
// z reguł gry. MG może ten koniec przesunąć — to resynchronizacja (ingerencja mocy). Gracze
// widzą tylko skutek, bez wyjaśnienia; zapis trafia do dziennika `resynchronizacje` dla MG.
//
// Rodzaje: skok_floty, manewr, skok_statku, podroz, obsluga_statku. Nowy rodzaj procesu: dopisz go w czas_procesy() (lista dla MG) i czas_przestaw() (zmiana końca),
// a w migracji do ENUM `resynchronizacje.rodzaj`.

declare(strict_types=1);

const CZAS_SEKUNDY_NA_MINUTE = 60;

// Jedyne źródło „teraz” dla mechanik gry (UTC, sekundy).
function czas_teraz(): int
{
    return time();
}

// Czas trwania procesu w sekundach rzeczywistych dla podanej liczby minut świata.
function czas_trwania(float $minutySwiata): int
{
    return max(1, (int) round($minutySwiata * CZAS_SEKUNDY_NA_MINUTE));
}

function czas_utc(int $t): string
{
    return gmdate('Y-m-d H:i:s', $t);
}

function czas_z_utc(?string $s): int
{
    return $s ? (int) strtotime($s . ' UTC') : 0;
}

// Procesy w toku, które MG może resynchronizować.
function czas_procesy(): array
{
    $teraz = czas_teraz();
    $lista = [];

    if (tabela_sql_dostepna('mapa_rozkazy_skokow')) {
        floty_aktualizuj_ruch();
        $q = polaczenie_bazy()->query("SELECT r.id, r.wydano_utc, r.eta_utc, f.nazwa AS flota,
                GROUP_CONCAT(o.nazwa ORDER BY o.nazwa SEPARATOR ', ') AS jednostki
            FROM mapa_rozkazy_skokow r
            LEFT JOIN mapa_floty f ON f.id = r.flota_id
            LEFT JOIN mapa_rozkazy_skokow_jednostki j ON j.rozkaz_id = r.id AND j.status = 'w_nadprzestrzeni'
            LEFT JOIN mapa_obiekty o ON o.id = j.obiekt_id
            WHERE r.status = 'w_nadprzestrzeni'
            GROUP BY r.id, r.wydano_utc, r.eta_utc, f.nazwa
            ORDER BY r.eta_utc");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lista[] = ['rodzaj' => 'skok_floty', 'obiekt' => (string) $r['id'],
                'opis' => 'Skok: ' . ($r['flota'] ?: ($r['jednostki'] ?: 'jednostka')),
                'start' => czas_z_utc($r['wydano_utc']), 'koniec' => czas_z_utc($r['eta_utc'])];
        }
    }

    if (tabela_sql_dostepna('mapa_obiekty') && function_exists('floty_kurs_dostepny') && floty_kurs_dostepny()) {
        // Manewry poza skokiem (zmiana kursu / strony orbity). Manewr przed skokiem jest częścią skoku.
        $q = polaczenie_bazy()->prepare("SELECT o.id, o.nazwa, o.manewr_od_utc, o.manewr_do_utc, o.kurs_cel_deg, f.id AS flota_id, f.nazwa AS flota
            FROM mapa_obiekty o LEFT JOIN mapa_floty_jednostki j ON j.obiekt_id = o.id LEFT JOIN mapa_floty f ON f.id = j.flota_id
            WHERE o.manewr_do_utc > ? AND NOT EXISTS (SELECT 1 FROM mapa_rozkazy_skokow_jednostki s
                WHERE s.obiekt_id = o.id AND s.status = 'w_nadprzestrzeni')");
        $q->execute([czas_utc($teraz)]);
        $grupy = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $k = $o['flota_id'] ? 'f:' . $o['flota_id'] : 'o:' . $o['id'];
            $g = $grupy[$k] ?? ['rodzaj' => 'manewr', 'obiekt' => $k,
                'opis' => 'Manewr na kurs ' . round((float) $o['kurs_cel_deg']) . '°: ' . ($o['flota'] ?: $o['nazwa']),
                'start' => czas_z_utc($o['manewr_od_utc']), 'koniec' => 0];
            $g['koniec'] = max($g['koniec'], czas_z_utc($o['manewr_do_utc']));
            $grupy[$k] = $g;
        }
        array_push($lista, ...array_values($grupy));
    }

    if (function_exists('floty_nawigacja_dostepna') && floty_nawigacja_dostepna()) {
        // Dolot na inną orbitę (napęd podświetlny), po flocie albo pojedynczej jednostce.
        $q = polaczenie_bazy()->prepare("SELECT o.id, o.nazwa, o.podlot_cel, o.podlot_od_utc, o.podlot_do_utc, f.id AS flota_id, f.nazwa AS flota
            FROM mapa_obiekty o LEFT JOIN mapa_floty_jednostki j ON j.obiekt_id = o.id LEFT JOIN mapa_floty f ON f.id = j.flota_id
            WHERE o.podlot_do_utc > ?");
        $q->execute([czas_utc($teraz)]);
        $grupy = [];
        $nazwy = ['bliska' => 'bliską orbitę', 'srednia' => 'średnią orbitę', 'daleka' => 'daleką orbitę', 'krawedz' => 'krawędź układu'];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $k = $o['flota_id'] ? 'f:' . $o['flota_id'] : 'o:' . $o['id'];
            $g = $grupy[$k] ?? ['rodzaj' => 'dolot', 'obiekt' => $k,
                'opis' => 'Dolot na ' . ($nazwy[$o['podlot_cel']] ?? $o['podlot_cel']) . ': ' . ($o['flota'] ?: $o['nazwa']),
                'start' => czas_z_utc($o['podlot_od_utc']), 'koniec' => 0];
            $g['koniec'] = max($g['koniec'], czas_z_utc($o['podlot_do_utc']));
            $grupy[$k] = $g;
        }
        array_push($lista, ...array_values($grupy));
    }

    foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
        foreach ($f['dane']['flota'] ?? [] as $s) {
            $p = $s['skok'] ?? null;
            if (empty($s['cel']) || !$p || (int) $p['eta'] <= $teraz) continue;
            $lista[] = ['rodzaj' => 'skok_statku', 'obiekt' => 's:' . $f['id'] . ':' . $s['id'],
                'opis' => 'Skok: ' . $s['nazwa'] . ' (' . $f['nazwa'] . ') → ' . (ruch_planeta((string) $s['cel'])['nazwa'] ?? '?'),
                'start' => (int) $p['start'], 'koniec' => (int) $p['eta']];
        }
    }

    $postacie = bank_postacie();
    foreach (wczytaj('karty', []) as $uid => $k) {
        $p = $k['podroz'] ?? null;
        if (!$p || (int) $p['koniec'] <= $teraz) continue;
        $lista[] = ['rodzaj' => 'podroz', 'obiekt' => (string) $uid,
            'opis' => ($postacie[$uid] ?? '?') . ' → ' . ($p['cel_nazwa'] ?? '?') . ' (' . ($p['srodek'] ?? '') . ')',
            'start' => (int) $p['start'], 'koniec' => (int) $p['koniec']];
    }

    foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
        foreach ($f['dane']['flota'] ?? [] as $s) {
            $p = $s['przygotowanie'] ?? null;
            if (!$p || (int) $p['gotowy'] <= $teraz) continue;
            $lista[] = ['rodzaj' => 'obsluga_statku', 'obiekt' => $f['id'] . ':' . $s['id'],
                'opis' => 'Obsługa naziemna: ' . $s['nazwa'] . ' (' . $f['nazwa'] . ')',
                'start' => (int) $p['start'], 'koniec' => (int) $p['gotowy']];
        }
    }

    usort($lista, fn($a, $b) => $a['koniec'] <=> $b['koniec']);
    return $lista;
}

// Ustawia nowy koniec procesu. Zwraca [koniec_przed, opis]. Proces zakończony lub nieznany → błąd.
function czas_przestaw(string $rodzaj, string $obiekt, int $nowyKoniec): array
{
    $teraz = czas_teraz();
    switch ($rodzaj) {
        case 'skok_floty':
            $db = polaczenie_bazy();
            $db->beginTransaction();
            try {
                $q = $db->prepare("SELECT * FROM mapa_rozkazy_skokow WHERE id = ? AND status = 'w_nadprzestrzeni' FOR UPDATE");
                $q->execute([(int) $obiekt]);
                $r = $q->fetch(PDO::FETCH_ASSOC);
                if (!$r) blad('Ten skok już się zakończył.');
                $przed = czas_z_utc((string) $r['eta_utc']);
                // Manewr przed skokiem też się skraca, jeśli nowy koniec wypada przed wejściem w nadprzestrzeń.
                $wejscie = czas_z_utc((string) (($r['wejscie_utc'] ?? null) ?: $r['wydano_utc']));
                if ($nowyKoniec < $wejscie) {
                    $wejscie = max(czas_z_utc((string) $r['wydano_utc']), $nowyKoniec);
                    $db->prepare('UPDATE mapa_rozkazy_skokow SET wejscie_utc = ? WHERE id = ?')->execute([czas_utc($wejscie), (int) $obiekt]);
                    $db->prepare("UPDATE mapa_obiekty o JOIN mapa_rozkazy_skokow_jednostki j ON j.obiekt_id = o.id
                        SET o.manewr_do_utc = LEAST(o.manewr_do_utc, ?) WHERE j.rozkaz_id = ? AND o.manewr_do_utc IS NOT NULL")
                        ->execute([czas_utc($wejscie), (int) $obiekt]);
                }
                // Jednostki wychodzą z nadprzestrzeni w nowym terminie, w tej samej proporcji co dotąd
                // (ta, która miała dotrzeć wcześniej, nadal dotrze wcześniej).
                $q = $db->prepare("SELECT obiekt_id, eta_utc FROM mapa_rozkazy_skokow_jednostki WHERE rozkaz_id = ? AND status = 'w_nadprzestrzeni'");
                $q->execute([(int) $obiekt]);
                $u = $db->prepare('UPDATE mapa_rozkazy_skokow_jednostki SET eta_utc = ? WHERE rozkaz_id = ? AND obiekt_id = ?');
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $jd) {
                    $etaJ = czas_z_utc($jd['eta_utc']);
                    $udzial = $przed > $wejscie ? max(0, min(1, ($etaJ - $wejscie) / ($przed - $wejscie))) : 1;
                    $u->execute([czas_utc((int) max($teraz, $wejscie, round($wejscie + ($nowyKoniec - $wejscie) * $udzial))), (int) $obiekt, $jd['obiekt_id']]);
                }
                $db->prepare('UPDATE mapa_rozkazy_skokow SET eta_utc = ? WHERE id = ?')->execute([czas_utc(max($nowyKoniec, $wejscie)), (int) $obiekt]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            return [$przed, 'Skok rozkazu #' . $obiekt];

        case 'manewr':
            [$typ, $id] = explode(':', $obiekt) + ['', '0'];
            $db = polaczenie_bazy();
            $warunek = $typ === 'f' ? 'id IN (SELECT obiekt_id FROM mapa_floty_jednostki WHERE flota_id = ?)' : 'id = ?';
            $q = $db->prepare("SELECT MAX(manewr_do_utc) FROM mapa_obiekty WHERE manewr_do_utc > ? AND $warunek");
            $q->execute([czas_utc($teraz), (int) $id]);
            $do = $q->fetchColumn();
            if (!$do) blad('Ten manewr już się zakończył.');
            $db->prepare("UPDATE mapa_obiekty SET manewr_do_utc = GREATEST(manewr_od_utc, ?) WHERE manewr_do_utc > ? AND $warunek")
                ->execute([czas_utc($nowyKoniec), czas_utc($teraz), (int) $id]);
            if ($nowyKoniec <= $teraz) floty_domknij_manewry();
            return [czas_z_utc((string) $do), 'Manewr ' . ($typ === 'f' ? 'floty #' : 'jednostki #') . $id];

        case 'dolot':
            [$typ, $id] = explode(':', $obiekt) + ['', '0'];
            $db = polaczenie_bazy();
            $warunek = $typ === 'f' ? 'id IN (SELECT obiekt_id FROM mapa_floty_jednostki WHERE flota_id = ?)' : 'id = ?';
            $q = $db->prepare("SELECT MAX(podlot_do_utc) FROM mapa_obiekty WHERE podlot_do_utc > ? AND $warunek");
            $q->execute([czas_utc($teraz), (int) $id]);
            $do = $q->fetchColumn();
            if (!$do) blad('Ten dolot już się zakończył.');
            $db->prepare("UPDATE mapa_obiekty SET podlot_do_utc = GREATEST(podlot_od_utc, ?) WHERE podlot_do_utc > ? AND $warunek")
                ->execute([czas_utc($nowyKoniec), czas_utc($teraz), (int) $id]);
            if ($nowyKoniec <= $teraz) floty_domknij_manewry();
            return [czas_z_utc((string) $do), 'Dolot ' . ($typ === 'f' ? 'floty #' : 'jednostki #') . $id];

        case 'skok_statku':
            $statek = ruch_statek($obiekt);
            if (!$statek || empty($statek['cel']) || empty($statek['skok'])) blad('Ten statek nie jest w skoku z wyliczonym czasem.');
            $przed = (int) $statek['skok']['eta'];
            ruch_zmien_statek($obiekt, function (&$s) use ($nowyKoniec) { $s['skok']['eta'] = $nowyKoniec; });
            if ($nowyKoniec <= $teraz) ruch_wyjscie_ze_skoku($obiekt, $teraz);
            return [$przed, 'Skok statku: ' . $statek['nazwa']];

        case 'podroz':
            return zmien('karty', function (&$k) use ($obiekt, $nowyKoniec, $teraz) {
                $p = $k[$obiekt]['podroz'] ?? null;
                if (!$p || (int) $p['koniec'] <= $teraz) blad('Ta podróż już się zakończyła.');
                $k[$obiekt]['podroz']['koniec'] = $nowyKoniec;
                return [(int) $p['koniec'], 'Podróż do: ' . ($p['cel_nazwa'] ?? '?')];
            });

        case 'obsluga_statku':
            [$fid, $sid] = explode(':', $obiekt) + ['', ''];
            return zmien('firmy', function (&$wsz) use ($fid, $sid, $nowyKoniec, $teraz) {
                foreach ($wsz['firmy'] as &$f) {
                    if ($f['id'] !== $fid) continue;
                    foreach ($f['dane']['flota'] as &$s) {
                        if ($s['id'] !== $sid) continue;
                        $p = $s['przygotowanie'] ?? null;
                        if (!$p || (int) $p['gotowy'] <= $teraz) blad('Obsługa tego statku już się zakończyła.');
                        $s['przygotowanie']['gotowy'] = $nowyKoniec;
                        return [(int) $p['gotowy'], 'Obsługa naziemna: ' . $s['nazwa']];
                    }
                }
                blad('Nie ma takiego statku.', 404);
            }, ['firmy' => []]);
    }
    blad('Nieznany rodzaj procesu.');
}

function obsluz_czas(string $akcja, bool $post): void
{
    switch ($akcja) {
        case 'mg_procesy':
            wymagaj_mg();
            $dziennik = [];
            if (tabela_sql_dostepna('resynchronizacje')) {
                $dziennik = polaczenie_bazy()->query('SELECT rodzaj, opis, koniec_przed_utc, koniec_po_utc, mg_login, notatka, utworzono_utc
                    FROM resynchronizacje ORDER BY id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
            }
            // Brakujące migracje: bez nich część mechanik (sceny okrętów, kurs, radar) się nie włącza.
            $ostrzezenia = [];
            if (!tabela_sql_dostepna('mapa_wykrycia_skokow')) $ostrzezenia[] = 'Brak migracji 014 (radar, skok statku postaci).';
            if (function_exists('floty_kurs_dostepny') && !floty_kurs_dostepny()) $ostrzezenia[] = 'Brak migracji 015 (kurs, manewry, cykle planet): floty i sceny okrętów nie działają.';
            if (function_exists('floty_orbita_dostepna') && !floty_orbita_dostepna()) $ostrzezenia[] = 'Brak migracji 016 (orbita, przylot, rozbicie): sceny okrętów i okno ISD nie działają.';
            if (!tabela_sql_dostepna('zdjecia_gwiazd')) $ostrzezenia[] = 'Brak migracji 021 (rodzaje gwiazd, zdjęcia, wyczyny): aparat i album nie działają.';
            if (!tabela_sql_dostepna('wskazniki_zgody')) $ostrzezenia[] = 'Brak migracji 020 (zgody na wskaźniki): goście na pokładzie nie dostaną wskaźników.';
            if (function_exists('liczniki_dostepne') && !liczniki_dostepne()) $ostrzezenia[] = 'Brak migracji 019 (osłony, kadłub, ETA z planu): liczniki pokładowe bez osłon i kadłuba.';
            if (function_exists('mapa_frakcja_dostepna') && !mapa_frakcja_dostepna()) $ostrzezenia[] = 'Brak migracji 018 (frakcja jednostek): kolory stron liczone tylko z opisu właściciela i dowódcy.';
            if (function_exists('floty_nawigacja_dostepna') && !floty_nawigacja_dostepna()) $ostrzezenia[] = 'Brak migracji 017 (komputer nawigacyjny, warianty orbity, dolot): floty nie przyjmą rozkazów.';
            odpowiedz(['teraz' => czas_teraz(), 'procesy' => czas_procesy(), 'dziennik' => $dziennik,
                'dziennik_dostepny' => tabela_sql_dostepna('resynchronizacje'), 'ostrzezenia' => $ostrzezenia]);

        case 'mg_resynchronizuj':
            if (!$post) blad('Zła metoda.', 405);
            $mg = wymagaj_mg();
            if (!tabela_sql_dostepna('resynchronizacje')) blad('Brak tabeli resynchronizacje — zaimportuj migrację 013.');
            $w = wejscie();
            $minuty = (float) ($w['minuty'] ?? -1);
            if ($minuty < 0 || $minuty > 60 * 24 * 365) blad('Podaj, za ile minut proces ma się zakończyć (0 = teraz).');
            $rodzaj = (string) ($w['rodzaj'] ?? '');
            $obiekt = (string) ($w['obiekt'] ?? '');
            $nowy = czas_teraz() + (int) round($minuty * 60);
            [$przed, $opis] = czas_przestaw($rodzaj, $obiekt, $nowy);
            polaczenie_bazy()->prepare('INSERT INTO resynchronizacje (rodzaj, obiekt, opis, koniec_przed_utc, koniec_po_utc, mg_login, notatka, utworzono_utc)
                VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                ->execute([$rodzaj, $obiekt, mb_substr($opis, 0, 255), czas_utc($przed), czas_utc($nowy), $mg['login'], pole('notatka', 500)]);
            if ($rodzaj === 'skok_floty' && $minuty == 0) floty_aktualizuj_ruch();
            odpowiedz(['ok' => true, 'koniec' => $nowy]);
    }
}

<?php
// CZAS ŚWIATA i RESYNCHRONIZACJA.
//
// Czas w grze płynie 1:1 z rzeczywistym: minuta świata = 60 sekund. Nie ma globalnego „tempa”.
// Każdy proces w czasie (skok floty, podróż, obsługa naziemna statku) ma swój koniec liczony
// z reguł gry. MG może ten koniec przesunąć — to resynchronizacja (ingerencja mocy). Gracze
// widzą tylko skutek, bez wyjaśnienia; zapis trafia do dziennika `resynchronizacje` dla MG.
//
// Nowy rodzaj procesu: dopisz go w czas_procesy() (lista dla MG) i czas_przestaw() (zmiana końca),
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
                $q = $db->prepare("SELECT eta_utc FROM mapa_rozkazy_skokow WHERE id = ? AND status = 'w_nadprzestrzeni' FOR UPDATE");
                $q->execute([(int) $obiekt]);
                $eta = $q->fetchColumn();
                if ($eta === false) blad('Ten skok już się zakończył.');
                // Wszystkie jednostki rozkazu wychodzą z nadprzestrzeni w nowym terminie, w tej samej
                // proporcji co dotąd (ta, która miała dotrzeć wcześniej, nadal dotrze wcześniej).
                $przed = czas_z_utc((string) $eta);
                $q = $db->prepare("SELECT obiekt_id, eta_utc FROM mapa_rozkazy_skokow_jednostki WHERE rozkaz_id = ? AND status = 'w_nadprzestrzeni'");
                $q->execute([(int) $obiekt]);
                $start = czas_z_utc((string) $db->query('SELECT wydano_utc FROM mapa_rozkazy_skokow WHERE id = ' . (int) $obiekt)->fetchColumn());
                $u = $db->prepare('UPDATE mapa_rozkazy_skokow_jednostki SET eta_utc = ? WHERE rozkaz_id = ? AND obiekt_id = ?');
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $j) {
                    $etaJ = czas_z_utc($j['eta_utc']);
                    $udzial = $przed > $start ? max(0, min(1, ($etaJ - $start) / ($przed - $start))) : 1;
                    $u->execute([czas_utc((int) max($teraz, round($start + ($nowyKoniec - $start) * $udzial))), (int) $obiekt, $j['obiekt_id']]);
                }
                $db->prepare('UPDATE mapa_rozkazy_skokow SET eta_utc = ? WHERE id = ?')->execute([czas_utc($nowyKoniec), (int) $obiekt]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            return [$przed, 'Skok rozkazu #' . $obiekt];

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
            odpowiedz(['teraz' => czas_teraz(), 'procesy' => czas_procesy(), 'dziennik' => $dziennik,
                'dziennik_dostepny' => tabela_sql_dostepna('resynchronizacje')]);

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

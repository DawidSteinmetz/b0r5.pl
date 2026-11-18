<?php
// RADAR: wykrywanie skoków w nadprzestrzeń.
//
// Gdy statek albo flota wchodzi w nadprzestrzeń przy planecie, każda aktywna jednostka na mapie
// (typ statek/jednostka) stojąca przy tej samej planecie zapisuje wykrycie: skąd, szacowany punkt
// wyjścia i szacowany czas przylotu. Szacunek ma błąd — radar może się mylić. Gdy MG
// resynchronizuje skok, wykrycie zostaje bez zmian (dla graczy: radar źle odczytał skok).
//
// Wykrycia widzą ci, którzy widzą jednostkę wykrywającą (MG widzi wszystkie).

declare(strict_types=1);

const RADAR_WIDOCZNE_H = 24;   // jak długo wykrycie wisi na mapie po szacowanym przylocie

// Zapisuje wykrycia skoku. $pomin: id obiektów mapy, które same skaczą (nie wykrywają siebie).
function radar_wykryj_skok(int $planetaId, float $x0, float $y0, float $x1, float $y1, int $eta,
                           string $rodzaj, string $klucz, string $nazwa, array $pomin = []): int
{
    if ($planetaId <= 0 || !tabela_sql_dostepna('mapa_wykrycia_skokow') || !tabela_sql_dostepna('mapa_obiekty')) return 0;
    $db = polaczenie_bazy();
    $q = $db->prepare("SELECT id FROM mapa_obiekty WHERE aktywny=1 AND planeta_id=? AND typ IN ('statek','jednostka')");
    $q->execute([$planetaId]);
    $wykrywajace = array_diff(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)), array_map('intval', $pomin));
    if (!$wykrywajace) return 0;

    $teraz = czas_teraz();
    $dystans = hypot($x1 - $x0, $y1 - $y0);
    $ins = $db->prepare('INSERT INTO mapa_wykrycia_skokow (obiekt_id, cel_rodzaj, cel_klucz, cel_nazwa, start_x, start_y,
        szac_x, szac_y, margines_pc, szac_eta_utc, wiarygodnosc, wykryto_utc) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($wykrywajace as $id) {
        // Każda jednostka mierzy osobno: błąd 5–20% odległości, czas ±25%.
        $blad = 0.05 + random_int(0, 150) / 1000;
        $margines = max(1.0, $dystans * $blad);
        $kat = random_int(0, 6283) / 1000;
        $r = $margines * random_int(0, 1000) / 1000;
        $szacEta = $teraz + (int) round(($eta - $teraz) * random_int(800, 1250) / 1000);
        $wiarygodnosc = max(1, min(10, (int) round(10 - $blad * 45)));
        $ins->execute([$id, $rodzaj, mb_substr($klucz, 0, 120), mb_substr($nazwa, 0, 160), $x0, $y0,
            $x1 + cos($kat) * $r, $y1 + sin($kat) * $r, round($margines, 2), czas_utc($szacEta), $wiarygodnosc, czas_utc($teraz)]);
    }
    return count($wykrywajace);
}

// Wykrycia do pokazania na mapie. $obiektyWidoczne: id jednostek, które użytkownik widzi.
function radar_wykrycia(array $u, array $obiektyWidoczne): array
{
    if (!tabela_sql_dostepna('mapa_wykrycia_skokow')) return [];
    $mg = ($u['rola'] ?? '') === 'mg';
    if (!$mg && !$obiektyWidoczne) return [];
    $warunek = $mg ? '' : ' AND w.obiekt_id IN (' . implode(',', array_map('intval', $obiektyWidoczne)) . ')';
    $q = polaczenie_bazy()->prepare("SELECT w.id, w.cel_nazwa, w.start_x, w.start_y, w.szac_x, w.szac_y, w.margines_pc,
            w.szac_eta_utc, w.wiarygodnosc, w.wykryto_utc, o.nazwa AS wykryl
        FROM mapa_wykrycia_skokow w JOIN mapa_obiekty o ON o.id = w.obiekt_id
        WHERE w.szac_eta_utc > ? $warunek ORDER BY w.wykryto_utc DESC LIMIT 50");
    $q->execute([czas_utc(czas_teraz() - RADAR_WIDOCZNE_H * 3600)]);
    return array_map(fn($w) => [
        'id' => (int) $w['id'], 'nazwa' => $w['cel_nazwa'], 'wykryl' => $w['wykryl'],
        'x0' => (float) $w['start_x'], 'y0' => (float) $w['start_y'], 'x' => (float) $w['szac_x'], 'y' => (float) $w['szac_y'],
        'margines' => (float) $w['margines_pc'], 'eta' => czas_z_utc($w['szac_eta_utc']),
        'wykryto' => czas_z_utc($w['wykryto_utc']), 'wiarygodnosc' => (int) $w['wiarygodnosc'],
    ], $q->fetchAll(PDO::FETCH_ASSOC));
}

// Id planety na mapie dla planety z katalogu (po polu „mapa” albo po nazwie).
function radar_planeta_mapy(array $planeta): ?array
{
    if (isset($planeta['mapa']['x'], $planeta['mapa']['y'])) {
        $id = 0;
        if (tabela_sql_dostepna('mapa_planety')) {
            $q = polaczenie_bazy()->prepare('SELECT id FROM mapa_planety WHERE nazwa = ? LIMIT 1');
            $q->execute([(string) ($planeta['nazwa'] ?? '')]);
            $id = (int) $q->fetchColumn();
        }
        return ['id' => $id, 'x' => (float) $planeta['mapa']['x'], 'y' => (float) $planeta['mapa']['y']];
    }
    if (!tabela_sql_dostepna('mapa_planety')) return null;
    // Kolacja utf8mb4_unicode_ci ignoruje wielkość liter i ogonki; spacje usuwamy po obu stronach.
    $q = polaczenie_bazy()->prepare("SELECT id, x, y FROM mapa_planety WHERE REPLACE(nazwa, ' ', '') = ? LIMIT 1");
    $q->execute([str_replace(' ', '', (string) ($planeta['nazwa'] ?? ''))]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ? ['id' => (int) $r['id'], 'x' => (float) $r['x'], 'y' => (float) $r['y']] : null;
}

<?php
// DANE PUBLICZNE (migracja 096): to, co każdy — także gość bez konta — może sprawdzić na mapie.
//
// • publiczne_planeta   — rynek planety (produkcja, zużycie, cena lokalna każdego towaru), legalne PKB, stałe
//                         kontrakty przychodzące i wychodzące, linie turystyczne z najbliższymi odjazdami;
// • publiczne_towary    — lista towarów (do wyszukiwarki);
// • publiczne_towar     — kto towar produkuje, kto go kupuje i za ile, kontrakty na ten towar;
// • publiczne_ranking   — planety według legalnego PKB;
// • publiczne_linie     — linie turystyczne: trasy (ze współrzędnymi do rysowania) i najbliższe kursy.
//
// Legalne = zarejestrowane w systemie B0R5 (obieg oficjalny). Szarej strefy nikt nie umie oszacować, więc jej tu
// nie ma. Kontrakty są jawne tylko co do stron, towaru, wielkości i częstotliwości — bez godzin startu i lądowania,
// tras, punktów skoku i konkretnych statków. Planety zastrzeżone (planety_gry.zastrzezona) są pomijane.

declare(strict_types=1);

const PUBL_DNI_ROKU = 368; // rok standardowy galaktyki

function publ_zastrzezone(): array
{
    static $z = null;
    if ($z !== null) return $z;
    $z = [];
    if (!tabela_sql_dostepna('planety_gry')) return $z;
    $q = polaczenie_bazy()->query('SELECT p.nazwa FROM planety_gry g JOIN mapa_planety p ON p.id = g.planeta_id WHERE g.zastrzezona = 1');
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $n) $z[mb_strtolower((string) $n)] = true;
    return $z;
}

function publ_jawna(string $planeta): bool
{
    return !isset(publ_zastrzezone()[mb_strtolower($planeta)]);
}

function publ_planeta_param(): string
{
    $p = trim((string) ($_GET['planeta'] ?? ''));
    if ($p === '' || mb_strlen($p) > 160) blad('Podaj planetę.');
    if (!publ_jawna($p)) blad('Brak danych publicznych dla tej planety.', 404);
    return $p;
}

function publ_kontrakt(array $r): array
{
    return [
        'odbiorca_planeta' => $r['odbiorca_planeta'], 'odbiorca' => $r['odbiorca'], 'odbiorca_rodzaj' => $r['odbiorca_rodzaj'],
        'producent' => $r['producent'], 'towar' => $r['towar'], 'towar_nazwa' => $r['towar_nazwa'],
        't_doba' => (float) $r['t_doba'], 'ladownosc_t' => (int) $r['ladownosc_t'], 'klasa' => $r['klasa'],
        'kursow_doba' => round(86400 / max(1, (int) $r['odstep_s']), 1),
    ];
}

function publ_rynek(?string $planeta = null, ?string $towar = null): array
{
    if (!tabela_sql_dostepna('publiczny_rynek')) return [];
    $war = [];
    $par = [];
    if ($planeta !== null) { $war[] = 'LOWER(planeta) = LOWER(?)'; $par[] = $planeta; }
    if ($towar !== null) { $war[] = 'towar = ?'; $par[] = $towar; }
    $q = polaczenie_bazy()->prepare('SELECT * FROM publiczny_rynek' . ($war ? ' WHERE ' . implode(' AND ', $war) : '') . ' ORDER BY planeta, towar_nazwa');
    $q->execute($par);
    $w = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!publ_jawna((string) $r['planeta'])) continue;
        $prod = (float) $r['produkcja_t_doba'];
        $cena = (float) $r['cena_kr_t'];
        $w[] = ['planeta' => $r['planeta'], 'towar' => $r['towar'], 'towar_nazwa' => $r['towar_nazwa'], 'kategoria' => $r['kategoria'],
            'produkcja_t_doba' => $prod, 'zuzycie_t_doba' => (float) $r['zuzycie_t_doba'], 'cena_kr_t' => $cena,
            'cena_indeks' => (float) $r['cena_indeks'], 'cena_bazowa_kr_t' => (float) $r['cena_bazowa_kr_t'],
            'szacunek' => $r['szczegolowosc'] === 'szacunek', 'wartosc_produkcji_doba' => round($prod * $cena)];
    }
    return $w;
}

// Legalne PKB planety: wartość produkcji zarejestrowanej w B0R5 (produkcja × cena lokalna).
function publ_pkb(array $rynek): array
{
    $doba = 0.0;
    foreach ($rynek as $r) $doba += $r['wartosc_produkcji_doba'];
    return ['doba_kr' => round($doba), 'rok_kr' => round($doba * PUBL_DNI_ROKU), 'dni_roku' => PUBL_DNI_ROKU];
}

// Linie turystyczne z przystankami (współrzędne planet), czasami przelotu i najbliższymi kursami w obie strony.
// Przelot między przystankami jak w liniach dostaw: wznoszenie i lot do punktu skoku, skok, dolot (godziny).
function publ_linie(int $ileKursow = 3, ?string $planeta = null): array
{
    if (!tabela_sql_dostepna('turystyka_linie') || !tabela_sql_dostepna('turystyka_przystanki')) return [];
    $db = polaczenie_bazy();
    $linie = $db->query('SELECT * FROM turystyka_linie WHERE aktywna = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $q = $db->query('SELECT s.linia_id, s.kolejnosc, s.planeta, s.postoj_h, p.x, p.y FROM turystyka_przystanki s
        JOIN mapa_planety p ON LOWER(p.nazwa) = LOWER(s.planeta) ORDER BY s.linia_id, s.kolejnosc');
    $przystanki = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) $przystanki[(int) $s['linia_id']][] = $s;
    $teraz = czas_teraz();
    $w = [];
    foreach ($linie as $l) {
        $st = $przystanki[(int) $l['id']] ?? [];
        if (count($st) < 2) continue;
        $jawna = true;
        foreach ($st as $s) if (!publ_jawna((string) $s['planeta'])) $jawna = false;
        if (!$jawna) continue;
        if ($planeta !== null && !in_array(mb_strtolower($planeta), array_map(fn($s) => mb_strtolower((string) $s['planeta']), $st), true)) continue;
        $odstep = max(1, (int) round((float) $l['odstep_h'] * 3600));
        $faza = (int) round((float) $l['faza_h'] * 3600);
        $kursy = [];
        foreach (['tam' => $st, 'powrot' => array_reverse($st)] as $kier => $lista) {
            $przes = $kier === 'tam' ? $faza : $faza + intdiv($odstep, 2);
            $n = (int) ceil(($teraz - $przes) / $odstep);
            for ($i = 0; $i < $ileKursow; $i++) {
                $t = $przes + ($n + $i) * $odstep; // odjazd z pierwszego przystanku
                $etapy = [];
                foreach ($lista as $j => $s) {
                    if ($j > 0) {
                        $a = $lista[$j - 1];
                        $pc = hypot((float) $s['x'] - (float) $a['x'], (float) $s['y'] - (float) $a['y']);
                        $t += (int) round((1.5 + min(40, 3 + $pc / 150) + 1.5) * 3600);
                    }
                    $przylot = $j === 0 ? null : $t;
                    $postoj = ($j === 0 || $j === count($lista) - 1) ? 0 : (int) round((float) $s['postoj_h'] * 3600);
                    $odlot = $j === count($lista) - 1 ? null : ($j === 0 ? $t : $t + $postoj);
                    $etapy[] = ['planeta' => $s['planeta'], 'przylot' => $przylot, 'odlot' => $odlot];
                    if ($odlot !== null) $t = $odlot;
                }
                $kursy[] = ['kierunek' => $kier, 'etapy' => $etapy];
            }
        }
        usort($kursy, fn($a, $b) => $a['etapy'][0]['odlot'] <=> $b['etapy'][0]['odlot']);
        $w[] = ['kod' => $l['kod'], 'nazwa' => $l['nazwa'], 'operator' => $l['operator'], 'statek_klasa' => $l['statek_klasa'],
            'pasazerow' => (int) $l['pasazerow'], 'odstep_h' => (float) $l['odstep_h'], 'cena_kr' => (int) $l['cena_kr'], 'opis' => $l['opis'],
            'przystanki' => array_map(fn($s) => ['planeta' => $s['planeta'], 'x' => (float) $s['x'], 'y' => (float) $s['y'], 'postoj_h' => (float) $s['postoj_h']], $st),
            'kursy' => $kursy];
    }
    return $w;
}

function obsluz_publiczne(string $akcja, bool $post): void
{
    $akcje = ['publiczne_planeta', 'publiczne_towary', 'publiczne_towar', 'publiczne_ranking', 'publiczne_linie'];
    if (!in_array($akcja, $akcje, true)) return;
    if ($post) blad('Zła metoda.', 405);
    $db = polaczenie_bazy();
    try {
        switch ($akcja) {
            case 'publiczne_planeta':
                $p = publ_planeta_param();
                $rynek = publ_rynek($p);
                $przych = $wych = [];
                if (tabela_sql_dostepna('publiczne_kontrakty')) {
                    $q = $db->prepare('SELECT * FROM publiczne_kontrakty WHERE LOWER(odbiorca_planeta) = LOWER(?) OR LOWER(producent) = LOWER(?) ORDER BY t_doba DESC');
                    $q->execute([$p, $p]);
                    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        if (!publ_jawna((string) $r['odbiorca_planeta']) || !publ_jawna((string) $r['producent'])) continue;
                        if (mb_strtolower((string) $r['odbiorca_planeta']) === mb_strtolower($p)) $przych[] = publ_kontrakt($r);
                        else $wych[] = publ_kontrakt($r);
                    }
                }
                odpowiedz(['planeta' => $p, 'rynek' => $rynek, 'pkb' => publ_pkb($rynek), 'kontrakty_przychodzace' => array_slice($przych, 0, 60),
                    'kontrakty_wychodzace' => array_slice($wych, 0, 60), 'linie' => publ_linie(2, $p), 'czas' => czas_teraz()]);

            case 'publiczne_towary':
                if (!tabela_sql_dostepna('gosp_towary')) odpowiedz(['towary' => []]);
                $q = $db->query('SELECT kod, nazwa, kategoria, cena_bazowa_kr_t FROM gosp_towary ORDER BY kategoria, nazwa');
                odpowiedz(['towary' => array_map(fn($r) => ['kod' => $r['kod'], 'nazwa' => $r['nazwa'], 'kategoria' => $r['kategoria'],
                    'cena_bazowa_kr_t' => (float) $r['cena_bazowa_kr_t']], $q->fetchAll(PDO::FETCH_ASSOC))]);

            case 'publiczne_towar':
                $t = trim((string) ($_GET['towar'] ?? ''));
                if ($t === '' || strlen($t) > 40) blad('Podaj towar.');
                $rynek = publ_rynek(null, $t);
                $producenci = array_values(array_filter($rynek, fn($r) => $r['produkcja_t_doba'] > 0));
                $odbiorcy = array_values(array_filter($rynek, fn($r) => $r['zuzycie_t_doba'] > 0));
                usort($producenci, fn($a, $b) => $a['cena_kr_t'] <=> $b['cena_kr_t']);
                usort($odbiorcy, fn($a, $b) => $b['cena_kr_t'] <=> $a['cena_kr_t']);
                $kontrakty = [];
                if (tabela_sql_dostepna('publiczne_kontrakty')) {
                    $q = $db->prepare('SELECT * FROM publiczne_kontrakty WHERE towar = ? ORDER BY t_doba DESC LIMIT 200');
                    $q->execute([$t]);
                    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        if (publ_jawna((string) $r['odbiorca_planeta']) && publ_jawna((string) $r['producent'])) $kontrakty[] = publ_kontrakt($r);
                    }
                }
                odpowiedz(['towar' => $t, 'producenci' => $producenci, 'odbiorcy' => $odbiorcy, 'kontrakty' => $kontrakty]);

            case 'publiczne_ranking':
                $pkb = [];
                foreach (publ_rynek() as $r) {
                    $k = mb_strtolower($r['planeta']);
                    $pkb[$k] ??= ['planeta' => $r['planeta'], 'doba_kr' => 0.0, 'szacunek' => false];
                    $pkb[$k]['doba_kr'] += $r['wartosc_produkcji_doba'];
                    $pkb[$k]['szacunek'] = $pkb[$k]['szacunek'] || $r['szacunek'];
                }
                $lista = array_values(array_filter($pkb, fn($x) => $x['doba_kr'] > 0));
                usort($lista, fn($a, $b) => $b['doba_kr'] <=> $a['doba_kr']);
                foreach ($lista as &$x) { $x['doba_kr'] = round($x['doba_kr']); $x['rok_kr'] = round($x['doba_kr'] * PUBL_DNI_ROKU); }
                unset($x);
                odpowiedz(['ranking' => array_slice($lista, 0, 50), 'dni_roku' => PUBL_DNI_ROKU]);

            case 'publiczne_linie':
                odpowiedz(['linie' => publ_linie(3), 'czas' => czas_teraz()]);
        }
    } catch (Throwable $e) {
        blad('Dane publiczne są chwilowo niedostępne.', 503);
    }
}

<?php
// DOKI ORBITALNE: lokacje na orbicie (pierścień stoczniowy Kuat) i stanowiska doków ciężkich (migracja 045).
//
// Pierścień to lokacja z orbita_wysokosc_km nad planetą; stanowiska (stocznia_stanowiska) mają kąt na pierścieniu
// i stan: budowa (ISD w konstrukcji), naprawa (zajęte), wolne. „Dokuj” prowadzi okręt łukiem po orbicie — krótszą
// stroną planety, kąt i promień zmieniają się płynnie od miejsca na orbicie do stanowiska — więc okręt nigdy nie
// przelatuje przez planetę. Prędkość manewru przy planecie: z prędkości podświetlnej (ostrożnie), plus 5 min
// samego podejścia do doku. Okręt w doku może oddać się stoczni do naprawy; „Oddokuj” zwalnia stanowisko.

declare(strict_types=1);

const DOK_PODEJSCIE_S = 300;        // samo wejście w dok
const DOK_LITERY = 'ABCDEFGHIJKLMNOPQRSTUVWX';

function doki_dostepne(): bool
{
    static $jest = null;
    if ($jest === null) {
        $jest = false;
        if (tabela_sql_dostepna('stocznia_stanowiska')) {
            try { polaczenie_bazy()->query('SELECT dok_stanowisko_id, dok_do_utc FROM mapa_obiekty LIMIT 0');
                  polaczenie_bazy()->query('SELECT orbita_wysokosc_km FROM lokalizacje LIMIT 0'); $jest = true; }
            catch (Throwable $e) { $jest = false; }
        }
    }
    return $jest;
}

// Pierścienie (lokacje orbitalne ze stanowiskami): id => [id, nazwa, planeta, planeta_id, wysokosc_km, promien_km].
function doki_pierscienie(): array
{
    static $w = null;
    if ($w !== null) return $w;
    $w = [];
    if (!doki_dostepne()) return $w;
    $rows = polaczenie_bazy()->query("SELECT l.id, l.nazwa, l.planeta_id, p.nazwa AS planeta, l.orbita_wysokosc_km FROM lokalizacje l
        JOIN mapa_planety p ON p.id = l.planeta_id WHERE l.orbita_wysokosc_km IS NOT NULL AND l.status = 'aktywna'
          AND EXISTS (SELECT 1 FROM stocznia_stanowiska s WHERE s.lokalizacja_id = l.id)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $rp = (float) (astro_cykl((string) $r['planeta'])['srednica_km'] ?? 12000) / 2;
        $w[(int) $r['id']] = ['id' => (int) $r['id'], 'nazwa' => $r['nazwa'], 'planeta' => $r['planeta'], 'planeta_id' => (int) $r['planeta_id'],
            'wysokosc_km' => (float) $r['orbita_wysokosc_km'], 'promien_km' => $rp + (float) $r['orbita_wysokosc_km']];
    }
    return $w;
}

function doki_pierscien_planety(int $planetaId): ?array
{
    foreach (doki_pierscienie() as $p) if ($p['planeta_id'] === $planetaId) return $p;
    return null;
}

// Dla mapy: pierścienie ze stanowiskami w skrócie [id, kąt, stan 0 budowa / 1 naprawa / 2 wolne, oznaczenie, postęp].
function doki_dla_mapy(): array
{
    $w = [];
    if (!doki_dostepne()) return $w;
    $kody = ['budowa' => 0, 'naprawa' => 1, 'wolne' => 2];
    foreach (doki_pierscienie() as $p) {
        $q = polaczenie_bazy()->prepare('SELECT id, kat_deg, stan, oznaczenie, postep FROM stocznia_stanowiska WHERE lokalizacja_id = ? ORDER BY sektor, numer');
        $q->execute([$p['id']]);
        $p['stanowiska'] = array_map(fn($s) => [(int) $s['id'], (float) $s['kat_deg'], $kody[$s['stan']] ?? 2, $s['oznaczenie'], (int) $s['postep']],
            $q->fetchAll(PDO::FETCH_ASSOC));
        $w[] = $p;
    }
    return $w;
}

// Ile napraw stocznia prowadzi naraz: z pierścieniem każdy zadokowany okręt ma własne stanowisko.
function doki_stanowiska_stoczni(string $stocznia, int $domyslnie): int
{
    if (!doki_dostepne()) return $domyslnie;
    foreach (doki_pierscienie() as $p) if (mb_strtolower($p['planeta']) === $stocznia) return 1000;
    return $domyslnie;
}

// Warunek naprawy w stoczni z pierścieniem: okręt stoi w doku (null — można; tekst — powód).
function doki_wymog_naprawy(int $id): ?string
{
    if (!doki_dostepne()) return null;
    $q = polaczenie_bazy()->prepare('SELECT planeta_id, dok_stanowisko_id, dok_do_utc FROM mapa_obiekty WHERE id = ?');
    $q->execute([$id]);
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o || !doki_pierscien_planety((int) $o['planeta_id'])) return null;
    if (!$o['dok_stanowisko_id']) return 'Najpierw zadokuj okręt na wolnym stanowisku pierścienia stoczniowego.';
    if ($o['dok_do_utc'] && czas_z_utc((string) $o['dok_do_utc']) > czas_teraz()) return 'Okręt jeszcze leci do doku.';
    return null;
}

function doki_moze(array $u, int $id): bool
{
    if (($u['rola'] ?? '') === 'mg') return true;
    if (function_exists('floty_jednostka_moze') && floty_jednostka_moze((string) $u['login'], $id)) return true;
    return function_exists('wskazniki_kapitan') && wskazniki_kapitan('okret:' . $id, $u);
}

function doki_okret(int $id): array
{
    $orb = function_exists('floty_orbita_dostepna') && floty_orbita_dostepna() ? 'o.orbita_deg, o.rozbity,' : 'NULL AS orbita_deg, 0 AS rozbity,';
    $lot = function_exists('floty_lot_dostepny') && floty_lot_dostepny() ? 'o.lot_do_utc,' : 'NULL AS lot_do_utc,';
    $prz = przelot_dostepny() ? 'o.przelot_cel, o.przelot_od_utc, o.przelot_do_utc, o.przelot_kat0, o.przelot_r0_km, o.przelot_kat1, o.przelot_r1_km,' : '';
    $q = polaczenie_bazy()->prepare("SELECT o.id, o.nazwa, o.planeta_id, p.nazwa AS planeta, o.ladowisko_id, {$orb} {$lot} {$prz} s.kat_deg AS dok_kat, o.dok_kat_start, o.dok_r_start_km, o.kurs_deg, o.podejscie, o.podlot_start, o.podlot_cel,
            o.podlot_od_utc, o.podlot_do_utc, o.dok_stanowisko_id, o.dok_do_utc, COALESCE(n.podswietlna_pc_doba, 20) AS pc_doba
        FROM mapa_obiekty o LEFT JOIN stocznia_stanowiska s ON s.id=o.dok_stanowisko_id LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id = o.id LEFT JOIN mapa_planety p ON p.id = o.planeta_id WHERE o.id = ? AND o.aktywny = 1");
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: blad('Nie ma takiego okrętu.');
}

function obsluz_doki(string $akcja, bool $post): void
{
    $u = wymagaj_logowania();
    if (!doki_dostepne()) blad('Zaimportuj api/migracje/045_orbitalne_doki_kuat.sql.');
    $db = polaczenie_bazy();
    $w = $post ? wejscie() : $_GET;
    $id = (int) ($w['obiekt_id'] ?? $w['obiekt'] ?? 0);
    $teraz = czas_teraz();
    switch ($akcja) {
        case 'doki':
            // Pierścień przy planecie okrętu: sektory i stanowiska, stan dokowania okrętu.
            $o = doki_okret($id);
            $p = doki_pierscien_planety((int) $o['planeta_id']);
            if (!$p) odpowiedz(['pierscien' => null]);
            $q = $db->prepare('SELECT id, sektor, numer, oznaczenie, kat_deg, stan, opis, postep, obiekt_id FROM stocznia_stanowiska WHERE lokalizacja_id = ? ORDER BY sektor, numer');
            $q->execute([$p['id']]);
            $sektory = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $k = (int) $s['sektor'];
                $sektory[$k] ??= ['sektor' => $k, 'litera' => DOK_LITERY[$k] ?? (string) $k, 'kat' => (float) $s['kat_deg'], 'budowa' => 0, 'naprawa' => 0, 'wolne' => 0, 'stanowiska' => []];
                $sektory[$k][$s['stan']]++;
                $sektory[$k]['stanowiska'][] = ['id' => (int) $s['id'], 'oznaczenie' => $s['oznaczenie'], 'kat' => (float) $s['kat_deg'], 'stan' => $s['stan'],
                    'opis' => $s['opis'], 'postep' => (int) $s['postep'], 'moj' => (int) $s['obiekt_id'] === $id];
            }
            $dok = null;
            if ($o['dok_stanowisko_id']) {
                $q = $db->prepare('SELECT oznaczenie FROM stocznia_stanowiska WHERE id = ?');
                $q->execute([(int) $o['dok_stanowisko_id']]);
                $dok = ['oznaczenie' => (string) $q->fetchColumn(), 'do' => $o['dok_do_utc'] ? czas_z_utc((string) $o['dok_do_utc']) : null];
            }
            odpowiedz(['pierscien' => $p, 'sektory' => array_values($sektory), 'dok' => $dok, 'moze' => doki_moze($u, $id),
                'kat_okretu' => (float) ($o['orbita_deg'] ?? fmod((float) ($o['kurs_deg'] ?? 90) + 180, 360))]);

        case 'dokuj':
            if (!$post) blad('Zła metoda.', 405);
            if (!doki_moze($u, $id)) blad('Nie dowodzisz tym okrętem.', 403);
            $o = doki_okret($id);
            $p = doki_pierscien_planety((int) $o['planeta_id']) ?? blad('Przy tej planecie nie ma pierścienia stoczniowego.');
            if ($o['ladowisko_id']) blad('Okręt stoi na lądowisku.');
            if (!empty($o['rozbity'])) blad('Okręt jest rozbity.');
            if ($o['lot_do_utc'] && czas_z_utc((string) $o['lot_do_utc']) > $teraz) blad('Okręt jest w locie podświetlnym.');
            $q = $db->prepare("SELECT 1 FROM mapa_rozkazy_skokow_jednostki WHERE obiekt_id = ? AND status = 'w_nadprzestrzeni' LIMIT 1");
            $q->execute([$id]);
            if ($q->fetchColumn()) blad('Okręt wykonuje skok.');
            // Start: sztywne miejsce jednostki teraz (także w trakcie przelotu albo w innym doku).
            $start = przelot_dostepny() ? przelot_miejsce(przelot_jednostka($id), $teraz) : null;
            if (!$start) {
                $rp = $p['promien_km'] - $p['wysokosc_km'];
                $r0 = floty_promien_orbity_km($rp, floty_podejscie_teraz($o, $teraz) ?: ($o['podejscie'] ?: 'bliska'));
                if ($r0 === null) blad('Okręt jest na krawędzi układu. Najpierw zejdź na orbitę planety.');
                $start = [(float) ($o['orbita_deg'] ?? fmod((float) ($o['kurs_deg'] ?? 90) + 180, 360)), $r0];
            }
            [$kat0, $r0] = $start;
            $wybor = (int) ($w['stanowisko_id'] ?? 0);
            $db->beginTransaction();
            try {
                if ($wybor) {
                    $q = $db->prepare('SELECT id, kat_deg, oznaczenie, stan, obiekt_id FROM stocznia_stanowiska WHERE id = ? AND lokalizacja_id = ? FOR UPDATE');
                    $q->execute([$wybor, $p['id']]);
                    $s = $q->fetch(PDO::FETCH_ASSOC) ?: blad('Nie ma takiego stanowiska.');
                    if ($s['stan'] !== 'wolne' && (int) $s['obiekt_id'] !== $id) blad("Stanowisko {$s['oznaczenie']} jest zajęte.");
                } else {
                    // Automat: najbliższe wolne stanowisko (najmniejszy łuk od miejsca okrętu na orbicie).
                    $q = $db->prepare("SELECT id, kat_deg, oznaczenie, stan, obiekt_id FROM stocznia_stanowiska WHERE lokalizacja_id = ? AND stan = 'wolne' FOR UPDATE");
                    $q->execute([$p['id']]);
                    $wolne = $q->fetchAll(PDO::FETCH_ASSOC);
                    if (!$wolne) blad('Na pierścieniu nie ma wolnych stanowisk.');
                    usort($wolne, fn($a, $b) => abs(doki_luk($kat0, (float) $a['kat_deg'])) <=> abs(doki_luk($kat0, (float) $b['kat_deg'])));
                    $s = $wolne[0];
                }
                $kat1 = (float) $s['kat_deg'];
                if (przelot_dostepny()) {
                    $pr = przelot_ustaw(przelot_jednostka($id), [$kat0, $r0], [$kat1, $p['promien_km']], 'dok', $teraz);
                    [$delta, $km, $czas] = [$pr['luk'], $pr['km'], $pr['koniec'] - $teraz];
                } else {
                    $delta = doki_luk($kat0, $kat1);
                    $km = abs($delta) * M_PI / 180 * ($r0 + $p['promien_km']) / 2 + abs($p['promien_km'] - $r0);
                    $czas = DOK_PODEJSCIE_S + (int) round($km / max(10.0, min(200.0, (float) $o['pc_doba'] * 2)));
                }
                if ($o['dok_stanowisko_id'] && (int) $o['dok_stanowisko_id'] !== (int) $s['id']) {
                    $db->prepare("UPDATE stocznia_stanowiska SET stan = 'wolne', obiekt_id = NULL, opis = 'Wolne stanowisko naprawcze', postep = 0 WHERE id = ?")->execute([(int) $o['dok_stanowisko_id']]);
                }
                $db->prepare("UPDATE stocznia_stanowiska SET stan = 'naprawa', obiekt_id = ?, opis = ?, postep = 0 WHERE id = ?")
                    ->execute([$id, 'W doku: ' . $o['nazwa'], (int) $s['id']]);
                $kurs = fmod($kat1 + ($delta >= 0 ? 90 : 270), 360); // dziób wzdłuż pierścienia
                $db->prepare('UPDATE mapa_obiekty SET dok_stanowisko_id = ?, dok_od_utc = ?, dok_do_utc = ?, dok_kat_start = ?, dok_r_start_km = ?,
                        podejscie = ?, podlot_start = NULL, podlot_cel = NULL, podlot_od_utc = NULL, podlot_do_utc = NULL, kurs_deg = ?'
                        . (function_exists('floty_orbita_dostepna') && floty_orbita_dostepna() ? ', orbita_deg = ' . round($kat1, 3) : '') . ' WHERE id = ?')
                    ->execute([(int) $s['id'], czas_utc($teraz), czas_utc($teraz + $czas), round($kat0, 3), round($r0, 1), 'bliska', round($kurs, 2), $id]);
                $db->commit();
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            odpowiedz(['ok' => true, 'stanowisko' => $s['oznaczenie'], 'koniec' => $teraz + $czas, 'luk_deg' => round($delta, 1), 'km' => round($km),
                'kierunek' => $delta >= 0 ? 'przeciwnie do ruchu wskazówek zegara' : 'zgodnie z ruchem wskazówek zegara']);

        case 'oddokuj':
            if (!$post) blad('Zła metoda.', 405);
            if (!doki_moze($u, $id)) blad('Nie dowodzisz tym okrętem.', 403);
            $o = doki_okret($id);
            if (!$o['dok_stanowisko_id']) blad('Okręt nie stoi w doku.');
            $p = doki_pierscien_planety((int) $o['planeta_id']) ?? blad('Brak pierścienia orbitalnego dla tej planety.');
            // Zablokuj okręt i stanowisko: zwolnienie doku i start animacji muszą być jednym atomowym ruchem.
            $db->beginTransaction();
            try {
                $db->prepare('SELECT id FROM stocznia_stanowiska WHERE id=? FOR UPDATE')->execute([(int) $o['dok_stanowisko_id']]);
                $db->prepare('SELECT id FROM mapa_obiekty WHERE id=? FOR UPDATE')->execute([$id]);
                $o = doki_okret($id);
                if (!$o['dok_stanowisko_id']) blad('Okręt nie stoi już w doku.');

                // Po zakończonym dokowaniu pozycją źródłową jest dokładny kąt stanowiska. W trakcie
                // podejścia bierzemy punkt interpolowany. W obu przypadkach cofamy ten sam łuk do zapisanego
                // miejsca startu dokowania, zamiast kasować przelot i przestawiać orbitę pod statkiem.
                $start = null;
                if (przelot_dostepny()) {
                    if ($o['dok_do_utc'] && czas_z_utc((string) $o['dok_do_utc']) > $teraz) {
                        $start = przelot_miejsce(przelot_jednostka($id), $teraz);
                    }
                    if (!$start) $start = [(float) ($o['dok_kat'] ?? $o['orbita_deg'] ?? 0), (float) $p['promien_km']];
                }
                $celKat = $o['dok_kat_start'] !== null ? (float) $o['dok_kat_start'] : (float) ($start[0] ?? $o['orbita_deg'] ?? 0);
                $celPromien = $o['dok_r_start_km'] !== null ? (float) $o['dok_r_start_km'] : null;
                $podejscie = 'bliska';
                if ($start) {
                    $rp = (float) (astro_cykl((string) ($o['planeta'] ?? ''))['srednica_km'] ?? 12000) / 2;
                    $promienie = ['bliska' => floty_promien_orbity_km($rp, 'bliska'), 'srednia' => floty_promien_orbity_km($rp, 'srednia'), 'daleka' => floty_promien_orbity_km($rp, 'daleka')];
                    if ($celPromien === null) $celPromien = (float) ($promienie['bliska'] ?? $rp + 300);
                    uasort($promienie, fn($a, $b) => abs((float) $a - $celPromien) <=> abs((float) $b - $celPromien));
                    $podejscie = (string) array_key_first($promienie);
                }
                if (function_exists('floty_naprawy_dostepne') && floty_naprawy_dostepne()) {
                    $q = $db->prepare("SELECT 1 FROM stocznia_naprawy WHERE obiekt_id = ? AND stan IN ('kolejka','w_toku') LIMIT 1");
                    $q->execute([$id]);
                    if ($q->fetchColumn()) floty_naprawy_przerwij([$id]);
                }
                if ($start && $celPromien !== null) {
                    przelot_ustaw(przelot_jednostka($id), $start, [$celKat, $celPromien], 'orbita', $teraz);
                }
                $db->prepare("UPDATE stocznia_stanowiska SET stan = 'wolne', obiekt_id = NULL, opis = 'Wolne stanowisko naprawcze', postep = 0 WHERE id = ?")->execute([(int) $o['dok_stanowisko_id']]);
                $db->prepare('UPDATE mapa_obiekty SET dok_stanowisko_id = NULL, dok_od_utc = NULL, dok_do_utc = NULL, dok_kat_start = NULL, dok_r_start_km = NULL, podejscie = ? WHERE id = ?')
                    ->execute([$podejscie, $id]);
                $db->commit();
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            odpowiedz(['ok' => true]);
    }
}

// Najkrótszy łuk (stopnie, ze znakiem: + przeciwnie do ruchu wskazówek zegara) z kąta $od do $do.
function doki_luk(float $od, float $do): float
{
    return fmod(fmod($do - $od, 360) + 540, 360) - 180;
}

// ── Przelot orbitalny (migracja 047) ──────────────────────────────────────────────────────────────────────────────
// Jedna mechanika dla dokowania i powrotu promu do hangaru: sztywne miejsce jednostki przy planecie (kąt na orbicie
// i promień od środka planety) → łuk po orbicie krótszą stroną planety do miejsca celu (stanowisko doku albo okręt
// macierzysty) → na końcu dok albo hangar. Przyciski „Dokuj” i „Powrót do hangaru” to tylko skróty.

const PRZELOT_KONCOWKA_S = ['dok' => 300, 'hangar' => 120, 'orbita' => 0]; // wejście w dok / do hangaru

function przelot_dostepny(): bool
{
    static $jest = null;
    if ($jest === null) {
        try { polaczenie_bazy()->query('SELECT przelot_cel, przelot_do_utc, przelot_kat1 FROM mapa_obiekty LIMIT 0'); $jest = true; }
        catch (Throwable $e) { $jest = false; }
    }
    return $jest;
}

// Jednostka z danymi do liczenia miejsca na orbicie.
function przelot_jednostka(int $id): array
{
    $dok = doki_dostepne() ? 'o.dok_stanowisko_id, (SELECT kat_deg FROM stocznia_stanowiska WHERE id = o.dok_stanowisko_id) AS dok_kat,' : 'NULL AS dok_stanowisko_id, NULL AS dok_kat,';
    $prz = przelot_dostepny() ? 'o.przelot_cel, o.przelot_od_utc, o.przelot_do_utc, o.przelot_kat0, o.przelot_r0_km, o.przelot_kat1, o.przelot_r1_km,' : '';
    $q = polaczenie_bazy()->prepare("SELECT o.id, o.nazwa, o.planeta_id, p.nazwa AS planeta, o.orbita_deg, o.kurs_deg, o.podejscie, o.podlot_start, o.podlot_cel,
            o.podlot_od_utc, o.podlot_do_utc, {$dok} {$prz} COALESCE(n.podswietlna_pc_doba, 20) AS pc_doba
        FROM mapa_obiekty o LEFT JOIN mapa_planety p ON p.id = o.planeta_id LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id = o.id WHERE o.id = ?");
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: blad('Nie ma takiej jednostki.');
}

// [kąt °, promień km od środka planety] jednostki w chwili $t: w trakcie przelotu — na łuku, w doku — stanowisko,
// inaczej sztywne miejsce na orbicie (orbita_deg) i promień z podejścia.
function przelot_miejsce(array $o, int $t): ?array
{
    if (!$o['planeta']) return null;
    $rp = (float) (astro_cykl((string) $o['planeta'])['srednica_km'] ?? 12000) / 2;
    if (!empty($o['przelot_do_utc']) && $o['przelot_kat0'] !== null) {
        $od = czas_z_utc((string) $o['przelot_od_utc']); $do = czas_z_utc((string) $o['przelot_do_utc']);
        $f = $t >= $do ? 1.0 : max(0.0, ($t - $od) / max(1, $do - $od));
        $k0 = (float) $o['przelot_kat0'];
        return [fmod($k0 + doki_luk($k0, (float) $o['przelot_kat1']) * $f + 360, 360),
            (float) $o['przelot_r0_km'] + ((float) $o['przelot_r1_km'] - (float) $o['przelot_r0_km']) * $f];
    }
    if (!empty($o['dok_stanowisko_id']) && $o['dok_kat'] !== null && ($p = doki_pierscien_planety((int) $o['planeta_id']))) {
        return [(float) $o['dok_kat'], $p['promien_km']];
    }
    $podejscie = (floty_podejscie_teraz($o, $t) ?: ($o['podejscie'] ?: 'bliska'));
    $r = floty_promien_orbity_km($rp, $podejscie) ?? $rp * 15;
    return [(float) ($o['orbita_deg'] ?? fmod((float) ($o['kurs_deg'] ?? 90) + 180, 360)), $r];
}

// Ustawia przelot jednostki z miejsca $start do $cel ([kąt, promień]); zwraca czas końca (s).
function przelot_ustaw(array $o, array $start, array $cel, string $rodzaj, int $teraz): array
{
    $delta = doki_luk($start[0], $cel[0]);
    $km = abs($delta) * M_PI / 180 * ($start[1] + $cel[1]) / 2 + abs($cel[1] - $start[1]);
    $kms = max(10.0, min(200.0, (float) $o['pc_doba'] * 2));      // manewr przy planecie: ostrożnie
    $koniec = $teraz + (PRZELOT_KONCOWKA_S[$rodzaj] ?? 0) + (int) round($km / $kms);
    $kurs = fmod($cel[0] + ($delta >= 0 ? 90 : 270) + 360, 360);  // dziób wzdłuż orbity
    polaczenie_bazy()->prepare("UPDATE mapa_obiekty SET przelot_cel = ?, przelot_od_utc = ?, przelot_do_utc = ?, przelot_kat0 = ?, przelot_r0_km = ?,
            przelot_kat1 = ?, przelot_r1_km = ?, orbita_deg = ?, kurs_deg = ?, kurs_start_deg = NULL, kurs_cel_deg = NULL, manewr_od_utc = NULL, manewr_do_utc = NULL,
            podlot_start = NULL, podlot_cel = NULL, podlot_od_utc = NULL, podlot_do_utc = NULL WHERE id = ?")
        ->execute([$rodzaj, czas_utc($teraz), czas_utc($koniec), round($start[0], 3), round($start[1], 1), round($cel[0], 3), round($cel[1], 1),
            round($cel[0], 3), round($kurs, 2), (int) $o['id']]);
    return ['koniec' => $koniec, 'km' => $km, 'luk' => $delta];
}

// Domyka przeloty, których czas minął: prom wchodzi do hangaru, reszta zostaje na miejscu celu.
function przelot_domknij(): void
{
    if (!przelot_dostepny()) return;
    $db = polaczenie_bazy();
    $teraz = czas_utc(czas_teraz());
    $q = $db->prepare("SELECT id FROM mapa_obiekty WHERE przelot_cel = 'hangar' AND przelot_do_utc <= ?");
    $q->execute([$teraz]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $db->prepare("UPDATE mapa_obiekty SET w_hangarze = 1, ladowanie_cel_id = NULL, status = 'w hangarze' WHERE id = ?")->execute([(int) $id]);
    }
    $db->prepare('UPDATE mapa_obiekty SET przelot_cel = NULL, przelot_od_utc = NULL, przelot_do_utc = NULL, przelot_kat0 = NULL, przelot_r0_km = NULL,
        przelot_kat1 = NULL, przelot_r1_km = NULL WHERE przelot_do_utc IS NOT NULL AND przelot_do_utc <= ?')->execute([$teraz]);
}

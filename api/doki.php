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
    $q = polaczenie_bazy()->prepare("SELECT o.id, o.nazwa, o.planeta_id, o.ladowisko_id, {$orb} {$lot} o.kurs_deg, o.podejscie, o.podlot_start, o.podlot_cel,
            o.podlot_od_utc, o.podlot_do_utc, o.dok_stanowisko_id, o.dok_do_utc, COALESCE(n.podswietlna_pc_doba, 20) AS pc_doba
        FROM mapa_obiekty o LEFT JOIN mapa_naped_jednostki n ON n.obiekt_id = o.id WHERE o.id = ? AND o.aktywny = 1");
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
            $podejscie = function_exists('floty_podejscie_teraz') ? (floty_podejscie_teraz($o, $teraz) ?: ($o['podejscie'] ?: 'bliska')) : 'bliska';
            $rp = $p['promien_km'] - $p['wysokosc_km'];
            if ($o['dok_stanowisko_id'] && (!$o['dok_do_utc'] || czas_z_utc((string) $o['dok_do_utc']) <= $teraz)) {
                $r0 = $p['promien_km']; // przestawienie z doku do doku: start z pierścienia
            } else {
                $r0 = function_exists('floty_promien_orbity_km') ? floty_promien_orbity_km($rp, $podejscie) : $rp + 300;
                if ($r0 === null) blad('Okręt jest na krawędzi układu. Najpierw zejdź na orbitę planety.');
            }
            $kat0 = (float) ($o['orbita_deg'] ?? fmod((float) ($o['kurs_deg'] ?? 90) + 180, 360));
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
                $delta = doki_luk($kat0, $kat1);
                $km = abs($delta) * M_PI / 180 * ($r0 + $p['promien_km']) / 2 + abs($p['promien_km'] - $r0);
                $kms = max(10.0, min(200.0, (float) $o['pc_doba'] * 2));
                $czas = DOK_PODEJSCIE_S + (int) round($km / $kms);
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
            if (function_exists('floty_naprawy_dostepne') && floty_naprawy_dostepne()) {
                $q = $db->prepare("SELECT 1 FROM stocznia_naprawy WHERE obiekt_id = ? AND stan IN ('kolejka','w_toku') LIMIT 1");
                $q->execute([$id]);
                if ($q->fetchColumn()) floty_naprawy_przerwij([$id]);
            }
            $db->prepare("UPDATE stocznia_stanowiska SET stan = 'wolne', obiekt_id = NULL, opis = 'Wolne stanowisko naprawcze', postep = 0 WHERE id = ?")->execute([(int) $o['dok_stanowisko_id']]);
            $db->prepare('UPDATE mapa_obiekty SET dok_stanowisko_id = NULL, dok_od_utc = NULL, dok_do_utc = NULL, dok_kat_start = NULL, dok_r_start_km = NULL, podejscie = ? WHERE id = ?')
                ->execute(['bliska', $id]);
            odpowiedz(['ok' => true]);
    }
}

// Najkrótszy łuk (stopnie, ze znakiem: + przeciwnie do ruchu wskazówek zegara) z kąta $od do $do.
function doki_luk(float $od, float $do): float
{
    return fmod(fmod($do - $od, 360) + 540, 360) - 180;
}

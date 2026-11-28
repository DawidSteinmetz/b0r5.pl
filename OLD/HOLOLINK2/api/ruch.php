<?php
// Przemieszczanie się po planecie, przygotowanie statku do lotu, gotówka (bankomat, sklep).
//
// LOKACJE. Katalog „lokacje”: miejsca na planetach z lokalnymi współrzędnymi w km (x, y),
// typem (hangar, hotel, bankomat, sklep, kantyna, biuro, garaz, port, inne), opcjonalnie
// jakością obsługi (hangar 1–5), zapasem paliwa, ofertą sklepu. Budynki firm z x, y też są
// lokacjami. Gracz zna: lokacje publiczne, budynki swoich firm i „znane miejsca” z karty.
//
// CZAS. Podróże liczone są w minutach świata gry. Licznik na tablecie biegnie szybciej:
// „tempo” (ustawienie MG) to liczba sekund rzeczywistych na minutę świata (domyślnie 1).
//
// KLUCZE LOKACJI: l:<id> (katalog), b:<id firmy>:<id budynku>, s:<id firmy>:<id statku> (pokład).

declare(strict_types=1);

const PIESZO_KMH = 5;
const TAKSOWKI = [
    'wysoka' => ['czekanie' => 3, 'kmh' => 120, 'stawka' => 4],
    'niska' => ['czekanie' => 20, 'kmh' => 90, 'stawka' => 6],
];
const TYPY_LOKACJI = ['hangar', 'platforma', 'port', 'hotel', 'bankomat', 'sklep', 'kantyna', 'biuro', 'garaz', 'inne'];
const LADOWISKA = ['hangar', 'platforma', 'port'];
const TOLERANCJA_KM = 0.25; // ręczne lądowanie: jak blisko lądowiska muszą być wpisane współrzędne

// Klucz lokacji z nazwy albo klucza (dla edytorów MG). Pusty tekst = brak lokacji.
function ruch_klucz(array $lokacje, string $wartosc): string
{
    $wartosc = trim($wartosc);
    if ($wartosc === '') return '';
    if (isset($lokacje[$wartosc])) return $wartosc;
    foreach ($lokacje as $k => $l) {
        if (mb_strtolower($l['nazwa']) === mb_strtolower($wartosc)) return $k;
    }
    blad("Nieznana lokacja: „{$wartosc}”. Dodaj ją w katalogu (lokacje) albo nadaj budynkowi x i y.");
}

// Statek, na którego pokładzie jest postać (klucz s:<firma>:<statek>), albo null.
function ruch_statek(string $klucz): ?array
{
    if (!str_starts_with($klucz, 's:')) return null;
    [, $fid, $sid] = explode(':', $klucz) + ['', '', ''];
    foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
        if ($f['id'] !== $fid) continue;
        foreach ($f['dane']['flota'] as $s) {
            if ($s['id'] === $sid) return $s + ['firma' => $fid];
        }
    }
    return null;
}

function ruch_w_locie(string $klucz): bool
{
    return !empty(ruch_statek($klucz)['w_locie']);
}

// Zmiana pola statku (np. w_locie) pod blokadą.
function ruch_zmien_statek(string $klucz, callable $fn)
{
    [, $fid, $sid] = explode(':', $klucz) + ['', '', ''];
    return zmien('firmy', function (&$wsz) use ($fid, $sid, $fn) {
        foreach ($wsz['firmy'] as &$f) {
            if ($f['id'] !== $fid) continue;
            foreach ($f['dane']['flota'] as &$s) {
                if ($s['id'] === $sid) return $fn($s);
            }
        }
        blad('Nie ma takiego statku.', 404);
    }, ['firmy' => []]);
}

// Postacie na pokładzie danego statku.
function ruch_na_pokladzie(string $klucz): array
{
    $w = [];
    foreach (wczytaj('karty', []) as $uid => $k) {
        if (($k['pozycja']['lokacja'] ?? '') === $klucz) $w[] = $uid;
    }
    return $w;
}

// Lądowiska, które autopilot zna przy planecie statku: znane postaci (publiczne, firmowe, z karty)
// oraz przydzielone przez kontrolę lotów.
function ruch_ladowiska(string $uid, array $karta, array $statek, array $lok): array
{
    $planeta = $statek['lokalizacja'] ?? '';
    $w = array_filter(ruch_znane($uid, $karta, $lok, $planeta), fn($l) => in_array($l['typ'], LADOWISKA, true));
    $przydzial = wczytaj('lacznosc', [])['s:' . $statek['firma'] . ':' . $statek['id']]['przydzial'] ?? '';
    if ($przydzial !== '' && isset($lok[$przydzial]) && $lok[$przydzial]['planeta'] === $planeta) $w[$przydzial] = $lok[$przydzial] + ['przydzial' => true];
    return $w;
}

// ----- łączność z kontrolą lotów -----
// Wątek radiowy statku przy danej planecie. Na razie odpowiada MG (panel). Odpowiedzi postaci
// niezależnych z modelu językowego można później podpiąć w jednym miejscu: lacznosc_odpowiedz_npc().

function lacznosc_nowa(string $statek, array $planeta): void
{
    $kontrola = trim((string) ($planeta['kontrola'] ?? ''));
    zmien('lacznosc', function (&$l) use ($statek, $planeta, $kontrola) {
        $l[$statek] = ['planeta' => $planeta['id'], 'kontrola' => $kontrola, 'przydzial' => '', 'wiadomosci' => [], 'czeka' => $kontrola !== ''];
        if ($kontrola !== '') {
            $l[$statek]['wiadomosci'][] = ['od' => 'kontrola', 'autor' => $kontrola, 'czas' => date('c'),
                'tresc' => 'Tu ' . $kontrola . '. Wykryliśmy wasz statek. Podajcie kod identyfikacyjny i cel wizyty, czekajcie na przydział lądowiska.'];
        }
    });
}

function lacznosc_zamknij(string $statek): void
{
    zmien('lacznosc', function (&$l) use ($statek) {
        unset($l[$statek]);
    });
}

// Miejsce na przyszłe odpowiedzi NPC (model językowy). Dziś nic nie robi: odpowiada MG.
function lacznosc_odpowiedz_npc(string $statek, array $watek): ?array
{
    return null;
}

// Planety z katalogu, których nie ma w bazie mapy (pole „mapa”: x, y, kwadrat, region).
// Planeta z „widoczna_dla” (lista loginów) trafia tylko do tych kont i do MG. Reszta nawet
// nie dowiaduje się, że istnieje.
function mapa_dodatkowe(array $u): array
{
    $w = [];
    foreach (katalog()['planety'] as $p) {
        if (!isset($p['mapa']['x'], $p['mapa']['y'])) continue;
        $lista = array_map('mb_strtolower', (array) ($p['widoczna_dla'] ?? []));
        if ($lista && ($u['rola'] ?? '') !== 'mg' && !in_array(mb_strtolower($u['login']), $lista, true)) continue;
        $w[] = ['nazwa' => $p['nazwa'], 'x' => (float) $p['mapa']['x'], 'y' => (float) $p['mapa']['y'],
            'kwadrat' => $p['mapa']['kwadrat'] ?? '', 'region' => $p['mapa']['region'] ?? '', 'sektor' => $p['sektor'] ?? '',
            'zastrzezona' => (bool) $lista];
    }
    return $w;
}

function ruch_tempo(): float
{
    return max(0.05, min(60, (float) (wczytaj('katalog', [])['ustawienia']['tempo'] ?? 1)));
}

// Wszystkie lokacje (katalog + budynki firm), klucz => dane.
function ruch_lokacje(): array
{
    $l = [];
    foreach (wczytaj('katalog', [])['lokacje'] ?? [] as $x) {
        $l['l:' . $x['id']] = $x + ['klucz' => 'l:' . $x['id']];
    }
    foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
        foreach ($f['dane']['budynki'] ?? [] as $b) {
            if (!isset($b['x'], $b['y']) || ($b['planeta'] ?? '') === '') continue;
            $typ = mb_strtolower($b['typ'] ?? '');
            $l['b:' . $f['id'] . ':' . $b['id']] = [
                'klucz' => 'b:' . $f['id'] . ':' . $b['id'], 'nazwa' => $b['nazwa'], 'planeta' => $b['planeta'],
                'typ' => in_array($typ, TYPY_LOKACJI, true) ? $typ : 'biuro', 'x' => (float) $b['x'], 'y' => (float) $b['y'],
                'publiczna' => false, 'firma' => $f['id'], 'jakosc' => (int) ($b['jakosc'] ?? 3), 'paliwo' => $b['paliwo'] ?? null,
                'opis' => $b['opis'] ?? '',
            ];
        }
    }
    return $l;
}

function ruch_odleglosc(array $a, array $b): float
{
    return hypot((float) $a['x'] - (float) $b['x'], (float) $a['y'] - (float) $b['y']);
}

// Firmy, w których postać jest członkiem: id => firma.
function ruch_moje_firmy(string $uid): array
{
    $w = [];
    foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
        foreach ($f['czlonkowie'] as $c) {
            if ($c['uid'] === $uid) $w[$f['id']] = $f;
        }
    }
    return $w;
}

// Gdzie jest postać: lokacja (jeśli znana) i współrzędne.
function ruch_gdzie(array $karta, array $lokacje): ?array
{
    $p = $karta['pozycja'];
    $klucz = $p['lokacja'] ?? '';
    if (str_starts_with($klucz, 's:')) {
        // Na pokładzie statku: pozycja = hangar statku.
        [, $fid, $sid] = explode(':', $klucz) + ['', '', ''];
        foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
            if ($f['id'] !== $fid) continue;
            foreach ($f['dane']['flota'] as $s) {
                if ($s['id'] !== $sid) continue;
                $baza = ['klucz' => $klucz, 'nazwa' => 'Pokład: ' . $s['nazwa'], 'typ' => 'poklad'];
                // Na ziemi: pozycja hangaru. W locie (albo bez lądowiska): tylko planeta, przy której jest statek.
                if (empty($s['w_locie']) && isset($lokacje[$s['hangar'] ?? ''])) {
                    return $baza + array_intersect_key($lokacje[$s['hangar']], ['x' => 1, 'y' => 1, 'planeta' => 1]);
                }
                if (($s['lokalizacja'] ?? '') !== '') return $baza + ['x' => 0.0, 'y' => 0.0, 'planeta' => $s['lokalizacja']];
            }
        }
        return null;
    }
    if ($klucz !== '' && isset($lokacje[$klucz])) return $lokacje[$klucz];
    if (($p['planeta'] ?? '') !== '' && is_numeric($p['x'] ?? null) && is_numeric($p['y'] ?? null)) {
        return ['klucz' => '', 'nazwa' => $p['miejsce'] ?: 'Nieoznaczone miejsce', 'typ' => 'inne', 'x' => (float) $p['x'], 'y' => (float) $p['y'], 'planeta' => $p['planeta']];
    }
    return null;
}

// Miejsca, które postać zna na danej planecie.
function ruch_znane(string $uid, array $karta, array $lokacje, string $planeta): array
{
    $firmy = array_keys(ruch_moje_firmy($uid));
    $znane = array_map('mb_strtolower', $karta['znane'] ?? []);
    return array_filter($lokacje, fn($l) => ($l['planeta'] ?? '') === $planeta && (
        !empty($l['publiczna'])
        || (isset($l['firma']) && in_array($l['firma'], $firmy, true))
        || in_array(mb_strtolower($l['nazwa']), $znane, true)
        || in_array($l['klucz'], $karta['znane'] ?? [], true)
    ));
}

// Pojazdy dostępne dla postaci: prywatne (karta) i firmowe (każdy członek może ich użyć).
function ruch_pojazdy(string $uid, array $karta, array $lokacje): array
{
    $w = [];
    foreach ($karta['pojazdy'] ?? [] as $i => $p) {
        $w['k:' . $i] = $p + ['klucz' => 'k:' . $i, 'wlasciciel' => 'prywatny', 'kierowca_staty' => null];
    }
    foreach (ruch_moje_firmy($uid) as $f) {
        $zaloga = array_column($f['dane']['zaloga'] ?? [], null, 'id');
        foreach ($f['dane']['pojazdy'] ?? [] as $p) {
            $k = $zaloga[$p['kierowca'] ?? ''] ?? null;
            $w['f:' . $f['id'] . ':' . $p['id']] = $p + ['klucz' => 'f:' . $f['id'] . ':' . $p['id'], 'wlasciciel' => $f['nazwa'],
                'kierowca_imie' => $k['imie'] ?? '', 'kierowca_staty' => $k ? (int) ($k['statystyki']['pilotaz'] ?? 5) : null];
        }
    }
    foreach ($w as &$p) {
        $p['gdzie'] = $lokacje[$p['lokacja'] ?? ''] ?? null;
    }
    return $w;
}

// Pilotaż postaci z karty (umiejętność zawierająca „pilot”), 0–5.
function ruch_pilotaz_gracza(array $karta): int
{
    foreach ($karta['statystyki']['umiejetnosci'] ?? [] as $n => $v) {
        if (str_contains(mb_strtolower((string) $n), 'pilot')) return max(0, min(5, (int) $v));
    }
    return 0;
}

// Opcje dotarcia do celu. Czasy w minutach świata gry.
function ruch_opcje(string $uid, array $karta, array $skad, array $cel, array $planeta, array $lokacje): array
{
    $d = ruch_odleglosc($skad, $cel);
    $op = [];
    $op[] = ['srodek' => 'pieszo', 'nazwa' => 'Pieszo', 'minuty' => (int) ceil($d / PIESZO_KMH * 60), 'koszt' => 0, 'opis' => sprintf('%.1f km', $d)];
    $urb = $planeta['urbanizacja'] ?? 'brak';
    if (isset(TAKSOWKI[$urb])) {
        $t = TAKSOWKI[$urb];
        $stawka = (int) ($planeta['taksowka'] ?? $t['stawka']);
        $op[] = ['srodek' => 'taksowka', 'nazwa' => 'Taksówka', 'minuty' => (int) ceil($t['czekanie'] + $d / $t['kmh'] * 60),
            'koszt' => (int) ceil(10 + $stawka * $d), 'opis' => "podstawienie ok. {$t['czekanie']} min"];
    }
    foreach (ruch_pojazdy($uid, $karta, $lokacje) as $p) {
        if (!$p['gdzie'] || ($p['gdzie']['planeta'] ?? '') !== ($cel['planeta'] ?? '')) continue;
        $dojscie = ruch_odleglosc($skad, $p['gdzie']);
        $kmh = max(10, (int) ($p['predkosc'] ?? 200));
        $nazwa = $p['nazwa'] . ($p['wlasciciel'] !== 'prywatny' ? ' (' . $p['wlasciciel'] . ')' : '');
        $dojscieMin = (int) ceil($dojscie / PIESZO_KMH * 60);
        $opisDojscia = $dojscie > 0.05 ? sprintf('najpierw %d min pieszo do: %s · ', $dojscieMin, $p['gdzie']['nazwa']) : '';
        // Sam: Pilotaż z karty (0 → wolno i ostrożnie, 5 → szybko).
        $sam = 1.3 - 0.1 * ruch_pilotaz_gracza($karta);
        $op[] = ['srodek' => 'pojazd', 'pojazd' => $p['klucz'], 'kierowca' => false, 'nazwa' => $nazwa . ' — prowadzisz sam',
            'minuty' => $dojscieMin + (int) ceil(ruch_odleglosc($p['gdzie'], $cel) / $kmh * 60 * $sam), 'koszt' => 0, 'opis' => $opisDojscia . "$kmh km/h"];
        if ($p['kierowca_staty'] !== null) {
            // Z kierowcą: jego ukryty pilotaż (1–10).
            $mod = 1.25 - 0.05 * $p['kierowca_staty'];
            $op[] = ['srodek' => 'pojazd', 'pojazd' => $p['klucz'], 'kierowca' => true, 'nazwa' => $nazwa . ' — kierowca: ' . $p['kierowca_imie'],
                'minuty' => $dojscieMin + (int) ceil(ruch_odleglosc($p['gdzie'], $cel) / $kmh * 60 * $mod), 'koszt' => 0, 'opis' => $opisDojscia . "$kmh km/h"];
        }
    }
    usort($op, fn($a, $b) => $a['minuty'] <=> $b['minuty']);
    return $op;
}

// Kończy podróż, jeśli minął czas. Wywoływane przy każdym odczycie nawigacji i karty.
function ruch_dokoncz(string $uid): void
{
    $k = wczytaj('karty', [])[$uid]['podroz'] ?? null;
    if (!$k || time() < (int) $k['koniec']) return;
    zmien('karty', function (&$karty) use ($uid) {
        $p = $karty[$uid]['podroz'] ?? null;
        if (!$p || time() < (int) $p['koniec']) return;
        $karty[$uid]['pozycja'] = array_merge($karty[$uid]['pozycja'] ?? [], [
            'lokacja' => $p['cel'], 'miejsce' => $p['cel_nazwa'], 'planeta' => $p['planeta'],
            'budynek' => str_starts_with($p['cel'], 'b:') ? substr($p['cel'], 2) : '',
            'zmieniono' => (int) $p['koniec'] * 1000,
        ]);
        unset($karty[$uid]['podroz']);
    });
}

function ruch_planeta(string $id): array
{
    foreach (katalog()['planety'] as $p) {
        if ($p['id'] === $id) return $p;
    }
    return ['id' => '', 'nazwa' => '?'];
}

// Statki z firm postaci, które stoją na tej planecie w znanym hangarze.
function ruch_statki(string $uid, string $planeta, array $lokacje): array
{
    $w = [];
    foreach (ruch_moje_firmy($uid) as $f) {
        foreach ($f['dane']['flota'] ?? [] as $s) {
            if (($s['lokalizacja'] ?? '') !== $planeta || !isset($lokacje[$s['hangar'] ?? '']) || !empty($s['w_locie'])) continue;
            $w[] = ['klucz' => $f['id'] . ':' . $s['id'], 'firma' => $f['nazwa'], 'nazwa' => $s['nazwa'], 'klasa' => $s['klasa'] ?? '',
                'stan' => $s['stan'] ?? 'sprawny', 'paliwo' => (int) ($s['paliwo'] ?? 100), 'hangar' => $lokacje[$s['hangar']],
                'przygotowanie' => $s['przygotowanie'] ?? null];
        }
    }
    return $w;
}

// Czas obsługi naziemnej (minuty świata): jakość hangaru + tankowanie + ewentualny dowóz paliwa.
function ruch_obsluga(array $statek, array $hangar): array
{
    $jakosc = max(1, min(5, (int) ($hangar['jakosc'] ?? 3)));
    $baza = [1 => 180, 2 => 120, 3 => 90, 4 => 60, 5 => 40][$jakosc];
    $brak = max(0, 100 - (int) ($statek['paliwo'] ?? 100));
    $tankowanie = (int) ceil($brak * 0.6);
    $zapas = $hangar['paliwo'] ?? null; // null = bez ograniczeń
    $dowoz = $zapas !== null && (int) $zapas < $brak ? 120 : 0;
    $cena = (int) ($hangar['cena_paliwa'] ?? 15);
    return ['minuty' => $baza + $tankowanie + $dowoz, 'baza' => $baza, 'tankowanie' => $tankowanie, 'dowoz' => $dowoz,
        'brak' => $brak, 'koszt' => $brak * $cena, 'jakosc' => $jakosc];
}

const AKCJE_RUCHU = ['mapa', 'nawigacja','trasa', 'podroz', 'bankomat', 'sklep_kup', 'statek_przygotuj', 'na_poklad', 'zejdz_z_pokladu', 'start', 'skok', 'ladowanie', 'lacznosc_wyslij'];
const CZAS_WZNOSZENIA_MS = 300000;

function obsluz_ruch(string $akcja, bool $post): void
{
    // Każda akcja najpierw domyka podróż, która w międzyczasie dobiegła końca.
    if (in_array($akcja, AKCJE_RUCHU, true) && ($ja = ja())) ruch_dokoncz($ja['id']);
    switch ($akcja) {

        // Mapa galaktyki: gdzie jest postać, dokąd leci i gdzie stoją statki jej firm.
        // Planety podajemy po nazwie, a datapad dopasowuje je do mapy galaktyki z SQL.
        case 'mapa':
            $u = wymagaj_logowania();
            $karta = karta($u['id']);
            $nazwy = array_column(katalog()['planety'], 'nazwa', 'id');
            $gdzie = ruch_gdzie($karta, ruch_lokacje());
            $tu = (string) ($gdzie['planeta'] ?? $karta['pozycja']['planeta'] ?? '');
            $wynik = ['tu' => $nazwy[$tu] ?? '', 'stan' => $tu !== '' ? 'powierzchnia' : '', 'cel' => '', 'statki' => []];
            if ($st = ruch_statek($karta['pozycja']['lokacja'] ?? '')) {
                if (!empty($st['w_locie'])) $wynik['stan'] = ($st['cel'] ?? '') !== '' ? 'nadprzestrzen' : 'orbita';
                $wynik['cel'] = $nazwy[$st['cel'] ?? ''] ?? '';
            }
            foreach (ruch_moje_firmy($u['id']) as $f) {
                foreach ($f['dane']['flota'] ?? [] as $s) {
                    $wynik['statki'][] = ['nazwa' => $s['nazwa'] ?? '?', 'firma' => $f['nazwa'], 'planeta' => $nazwy[$s['lokalizacja'] ?? ''] ?? '',
                        'cel' => $nazwy[$s['cel'] ?? ''] ?? '', 'w_locie' => !empty($s['w_locie'])];
                }
            }
            $wynik['dodatkowe'] = mapa_dodatkowe($u);
            odpowiedz($wynik);

        case 'nawigacja':
            $u = wymagaj_logowania();
            ruch_dokoncz($u['id']);
            $karta = karta($u['id']);
            $lok = ruch_lokacje();
            $gdzie = ruch_gdzie($karta, $lok);
            $bank = wczytaj('bank', ['konta' => []]);
            $wynik = [
                'tempo' => ruch_tempo(), 'gotowka' => (int) ($karta['gotowka'] ?? 0), 'saldo' => $bank['konta'][$u['id']]['saldo'] ?? 0,
                'podroz' => $karta['podroz'] ?? null, 'teraz' => time(), 'gdzie' => $gdzie, 'planeta' => null, 'miejsca' => [], 'statki' => [], 'pojazdy' => [],
            ];
            if ($gdzie) {
                $pl = ruch_planeta($gdzie['planeta']);
                $wynik['planeta'] = ['nazwa' => $pl['nazwa'], 'urbanizacja' => $pl['urbanizacja'] ?? 'brak'];
                foreach (ruch_znane($u['id'], $karta, $lok, $gdzie['planeta']) as $l) {
                    if ($l['klucz'] === ($gdzie['klucz'] ?? '')) continue;
                    $wynik['miejsca'][] = ['klucz' => $l['klucz'], 'nazwa' => $l['nazwa'], 'typ' => $l['typ'], 'km' => round(ruch_odleglosc($gdzie, $l), 1), 'opis' => $l['opis'] ?? ''];
                }
                usort($wynik['miejsca'], fn($a, $b) => $a['km'] <=> $b['km']);
                foreach (ruch_statki($u['id'], $gdzie['planeta'], $lok) as $s) {
                    $s['obsluga'] = ruch_obsluga(['paliwo' => $s['paliwo']], $s['hangar']);
                    $s['km'] = round(ruch_odleglosc($gdzie, $s['hangar']), 1);
                    $s['na_miejscu'] = ($gdzie['klucz'] ?? '') === $s['hangar']['klucz'];
                    $s['hangar'] = ['klucz' => $s['hangar']['klucz'], 'nazwa' => $s['hangar']['nazwa'], 'jakosc' => (int) ($s['hangar']['jakosc'] ?? 3)];
                    $wynik['statki'][] = $s;
                }
                foreach (ruch_pojazdy($u['id'], $karta, $lok) as $p) {
                    $wynik['pojazdy'][] = ['nazwa' => $p['nazwa'], 'typ' => $p['typ'] ?? '', 'predkosc' => $p['predkosc'] ?? 0, 'wlasciciel' => $p['wlasciciel'],
                        'kierowca' => $p['kierowca_imie'] ?? '', 'gdzie' => $p['gdzie']['nazwa'] ?? 'nieznane', 'km' => $p['gdzie'] && $p['gdzie']['planeta'] === $gdzie['planeta'] ? round(ruch_odleglosc($gdzie, $p['gdzie']), 1) : null];
                }
                // Na pokładzie: stan statku (start, wznoszenie, orbita, skok).
                if (str_starts_with($gdzie['klucz'] ?? '', 's:')) {
                    $st = ruch_statek($gdzie['klucz']);
                    $sc = wczytaj('sceny', [])[$u['id']] ?? [];
                    $pl = ruch_planeta($st['lokalizacja'] ?? '');
                    $watek = wczytaj('lacznosc', [])[$gdzie['klucz']] ?? null;
                    $wynik['poklad'] = [
                        'ladowiska' => array_values(array_map(fn($l) => ['klucz' => $l['klucz'], 'nazwa' => $l['nazwa'], 'typ' => $l['typ'], 'przydzial' => !empty($l['przydzial'])],
                            !empty($st['w_locie']) ? ruch_ladowiska($u['id'], $karta, $st, $lok) : [])),
                        'kontrola' => trim((string) ($pl['kontrola'] ?? '')),
                        'watek' => $watek ? ['wiadomosci' => array_slice($watek['wiadomosci'], -12), 'czeka' => !empty($watek['czeka'])] : null,
                        'nazwa' => $st['nazwa'] ?? '?', 'w_locie' => !empty($st['w_locie']), 'cel' => $st['cel'] ?? '',
                        'gotowy' => !empty($st['przygotowanie']) && time() >= $st['przygotowanie']['gotowy'], 'paliwo' => (int) ($st['paliwo'] ?? 0),
                        'scena' => $sc['scena'] ?? '', 'od_zmiany_ms' => teraz_ms() - (int) ($sc['zmieniono'] ?? 0), 'wznoszenie_ms' => CZAS_WZNOSZENIA_MS,
                    ];
                }
                // W locie nie ma dokąd pójść ani czym pojechać.
                if (!empty($wynik['poklad']['w_locie'])) {
                    $wynik['miejsca'] = [];
                    $wynik['pojazdy'] = [];
                }
                // Pojazdy z innych planet nie są tu przydatne.
                $wynik['pojazdy'] = array_values(array_filter($wynik['pojazdy'], fn($p) => $p['km'] !== null));
                // Akcje w miejscu: bankomat, sklep.
                if (($gdzie['typ'] ?? '') === 'sklep') $wynik['sklep'] = $gdzie['oferta'] ?? [];
                $wynik['bankomat'] = ($gdzie['typ'] ?? '') === 'bankomat';
            }
            odpowiedz($wynik);

        case 'trasa':
            $u = wymagaj_logowania();
            $karta = karta($u['id']);
            $lok = ruch_lokacje();
            $gdzie = ruch_gdzie($karta, $lok);
            if (ruch_w_locie($karta['pozycja']['lokacja'] ?? '')) blad('Statek jest w locie.');
            $cel = $lok[(string) ($_GET['cel'] ?? '')] ?? null;
            if (!$gdzie || !$cel || $cel['planeta'] !== $gdzie['planeta']) blad('Nie można wyznaczyć trasy.', 404);
            odpowiedz(['opcje' => ruch_opcje($u['id'], $karta, $gdzie, $cel, ruch_planeta($gdzie['planeta']), $lok)]);

        case 'podroz':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            ruch_dokoncz($u['id']);
            $w = wejscie();
            $karta = karta($u['id']);
            if (!empty($karta['podroz'])) blad('Jesteś w drodze.');
            if (ruch_w_locie($karta['pozycja']['lokacja'] ?? '')) blad('Statek jest w locie.');
            $lok = ruch_lokacje();
            $gdzie = ruch_gdzie($karta, $lok);
            $cel = $lok[(string) ($w['cel'] ?? '')] ?? null;
            if (!$gdzie || !$cel || $cel['planeta'] !== $gdzie['planeta']) blad('Nie można wyznaczyć trasy.');
            if (!isset(ruch_znane($u['id'], $karta, $lok, $gdzie['planeta'])[$cel['klucz']])) blad('Nie znasz tego miejsca.');
            $opcje = ruch_opcje($u['id'], $karta, $gdzie, $cel, ruch_planeta($gdzie['planeta']), $lok);
            $wybor = null;
            foreach ($opcje as $o) {
                if ($o['srodek'] === ($w['srodek'] ?? '') && ($o['pojazd'] ?? '') === ($w['pojazd'] ?? '') && (bool) ($o['kierowca'] ?? false) === !empty($w['kierowca'])) $wybor = $o;
            }
            if (!$wybor) blad('Ten środek transportu jest niedostępny.');
            // Opłata (taksówka): kartą albo gotówką.
            if ($wybor['koszt'] > 0) {
                if (($w['platnosc'] ?? 'karta') === 'gotowka') {
                    $ok = zmien('karty', function (&$k) use ($u, $wybor) {
                        if ((int) ($k[$u['id']]['gotowka'] ?? 0) < $wybor['koszt']) return false;
                        $k[$u['id']]['gotowka'] -= $wybor['koszt'];
                        return true;
                    });
                    if (!$ok) blad('Za mało gotówki.');
                } else {
                    $wyn = zmien('bank', fn(&$bank) => bank_obciaz($bank, $u['id'], $wybor['koszt'], 'Korporacja Przewozowa', 'Przejazd: ' . $cel['nazwa'], bank_wlasciciele()), bank_pusty());
                    if ($wyn !== 'ok') blad('Karta odrzucona: brak środków.');
                }
            }
            $sekundy = max(3, (int) round($wybor['minuty'] * ruch_tempo()));
            zmien('karty', function (&$k) use ($u, $cel, $wybor, $sekundy) {
                $k[$u['id']]['podroz'] = [
                    'cel' => $cel['klucz'], 'cel_nazwa' => $cel['nazwa'], 'planeta' => $cel['planeta'],
                    'srodek' => $wybor['nazwa'], 'minuty' => $wybor['minuty'], 'start' => time(), 'koniec' => time() + $sekundy,
                ];
            });
            // Pojazd jedzie razem z postacią.
            if (($wybor['pojazd'] ?? '') !== '') {
                $pk = $wybor['pojazd'];
                if (str_starts_with($pk, 'k:')) {
                    zmien('karty', function (&$k) use ($u, $pk, $cel) {
                        $k[$u['id']]['pojazdy'][(int) substr($pk, 2)]['lokacja'] = $cel['klucz'];
                    });
                } else {
                    [, $fid, $pid] = explode(':', $pk);
                    zmien('firmy', function (&$wsz) use ($fid, $pid, $cel) {
                        foreach ($wsz['firmy'] as &$f) {
                            if ($f['id'] !== $fid) continue;
                            foreach ($f['dane']['pojazdy'] as &$p) {
                                if ($p['id'] === $pid) $p['lokacja'] = $cel['klucz'];
                            }
                        }
                    }, ['firmy' => []]);
                }
            }
            odpowiedz(['ok' => true, 'sekundy' => $sekundy, 'minuty' => $wybor['minuty']]);

        case 'bankomat':
            // Wypłata (kwota > 0) albo wpłata (kwota < 0) gotówki. Tylko przy bankomacie.
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $karta = karta($u['id']);
            $gdzie = ruch_gdzie($karta, ruch_lokacje());
            if (($gdzie['typ'] ?? '') !== 'bankomat') blad('W pobliżu nie ma bankomatu.');
            $kwota = bank_kwota(wejscie()['kwota'] ?? null);
            if ($kwota === 0) blad('Podaj kwotę.');
            if ($kwota > 0) {
                $wyn = zmien('bank', fn(&$bank) => bank_obciaz($bank, $u['id'], $kwota, 'Bankomat: ' . $gdzie['nazwa'], 'Wypłata gotówki', bank_wlasciciele()), bank_pusty());
                if ($wyn !== 'ok') blad('Niewystarczające środki na rachunku.');
                zmien('karty', function (&$k) use ($u, $kwota) { $k[$u['id']]['gotowka'] = (int) ($k[$u['id']]['gotowka'] ?? 0) + $kwota; });
            } else {
                $ok = zmien('karty', function (&$k) use ($u, $kwota) {
                    if ((int) ($k[$u['id']]['gotowka'] ?? 0) < -$kwota) return false;
                    $k[$u['id']]['gotowka'] += $kwota;
                    return true;
                });
                if (!$ok) blad('Nie masz tyle gotówki.');
                zmien('bank', fn(&$bank) => bank_uznaj($bank, $u['id'], -$kwota, 'Bankomat: ' . $gdzie['nazwa'], 'Wpłata gotówki', bank_wlasciciele()), bank_pusty());
            }
            odpowiedz(['ok' => true]);

        case 'sklep_kup':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $w = wejscie();
            $karta = karta($u['id']);
            $gdzie = ruch_gdzie($karta, ruch_lokacje());
            $towar = ($gdzie['oferta'] ?? [])[(int) ($w['pozycja'] ?? -1)] ?? null;
            if (($gdzie['typ'] ?? '') !== 'sklep' || !$towar) blad('Tego tu nie sprzedają.');
            $cena = max(0, (int) $towar['cena']);
            if (($w['platnosc'] ?? 'karta') === 'gotowka') {
                $ok = zmien('karty', function (&$k) use ($u, $cena) {
                    if ((int) ($k[$u['id']]['gotowka'] ?? 0) < $cena) return false;
                    $k[$u['id']]['gotowka'] -= $cena;
                    return true;
                });
                if (!$ok) blad('Za mało gotówki.');
            } else {
                $wyn = zmien('bank', fn(&$bank) => bank_obciaz($bank, $u['id'], $cena, $gdzie['nazwa'], 'Zakup: ' . $towar['nazwa'], bank_wlasciciele()), bank_pusty());
                if ($wyn !== 'ok') blad('Karta odrzucona: brak środków.');
            }
            zmien('karty', function (&$k) use ($u, $towar, $gdzie) {
                $k[$u['id']]['ekwipunek'][] = ['nazwa' => $towar['nazwa'], 'opis' => 'kupione: ' . $gdzie['nazwa']];
            });
            odpowiedz(['ok' => true]);

        case 'statek_przygotuj':
            // Obsługa naziemna: firma płaci za paliwo, hangar tankuje i sprawdza statek.
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            [$fid, $sid] = explode(':', (string) (wejscie()['statek'] ?? '')) + ['', ''];
            $firma = ruch_moje_firmy($u['id'])[$fid] ?? null;
            if (!$firma) blad('To nie jest statek twojej firmy.', 403);
            $lok = ruch_lokacje();
            $wynik = zmien('firmy', function (&$wsz) use ($fid, $sid, $lok, $firma) {
                foreach ($wsz['firmy'] as &$f) {
                    if ($f['id'] !== $fid) continue;
                    foreach ($f['dane']['flota'] as &$s) {
                        if ($s['id'] !== $sid) continue;
                        if (($s['stan'] ?? 'sprawny') !== 'sprawny') blad("Statek nie jest sprawny ({$s['stan']}).");
                        if (!empty($s['przygotowanie']) && time() < $s['przygotowanie']['gotowy']) blad('Obsługa naziemna już trwa.');
                        $hangar = $lok[$s['hangar'] ?? ''] ?? null;
                        if (!$hangar) blad('Statek nie stoi w żadnym hangarze.');
                        $o = ruch_obsluga($s, $hangar);
                        if ($o['koszt'] > 0) {
                            $klucz = 'f:' . $fid;
                            $wyn = zmien('bank', fn(&$bank) => bank_obciaz($bank, $klucz, $o['koszt'], $hangar['nazwa'], 'Paliwo i obsługa: ' . $s['nazwa'], bank_wlasciciele() + [$klucz => $firma['nazwa']]), bank_pusty());
                            if ($wyn !== 'ok') blad('Rachunek firmy nie pokrywa kosztu paliwa (' . $o['koszt'] . ' kr).');
                        }
                        $s['paliwo'] = 100;
                        $s['przygotowanie'] = ['start' => time(), 'gotowy' => time() + max(3, (int) round($o['minuty'] * ruch_tempo())), 'minuty' => $o['minuty']];
                        return $o + ['gotowy' => $s['przygotowanie']['gotowy']];
                    }
                }
                blad('Nie ma takiego statku.', 404);
            }, ['firmy' => []]);
            odpowiedz(['ok' => true] + $wynik);

        case 'na_poklad':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            ruch_dokoncz($u['id']);
            $karta = karta($u['id']);
            $lok = ruch_lokacje();
            $gdzie = ruch_gdzie($karta, $lok);
            $klucz = (string) (wejscie()['statek'] ?? '');
            foreach (ruch_statki($u['id'], $gdzie['planeta'] ?? '', $lok) as $s) {
                if ($s['klucz'] !== $klucz) continue;
                if (($gdzie['klucz'] ?? '') !== $s['hangar']['klucz']) blad('Najpierw dotrzyj do hangaru: ' . $s['hangar']['nazwa'] . '.');
                if (empty($s['przygotowanie']) || time() < $s['przygotowanie']['gotowy']) blad('Obsługa naziemna jeszcze trwa.');
                zmien('karty', function (&$k) use ($u, $s) {
                    $k[$u['id']]['pozycja']['lokacja'] = 's:' . $s['klucz'];
                    $k[$u['id']]['pozycja']['miejsce'] = 'Pokład: ' . $s['nazwa'];
                    $k[$u['id']]['pozycja']['zmieniono'] = teraz_ms();
                });
                odpowiedz(['ok' => true]);
            }
            blad('Nie ma takiego statku w pobliżu.', 404);

        case 'zejdz_z_pokladu':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $karta = karta($u['id']);
            $lok = ruch_lokacje();
            $p = $karta['pozycja']['lokacja'] ?? '';
            if (!str_starts_with($p, 's:')) blad('Nie jesteś na pokładzie.');
            if (ruch_w_locie($p)) blad('Statek jest w locie.');
            [, $fid, $sid] = explode(':', $p);
            foreach (ruch_statki($u['id'], ruch_gdzie($karta, $lok)['planeta'] ?? '', $lok) as $s) {
                if ($s['klucz'] === "$fid:$sid") {
                    zmien('karty', function (&$k) use ($u, $s) {
                        $k[$u['id']]['pozycja']['lokacja'] = $s['hangar']['klucz'];
                        $k[$u['id']]['pozycja']['miejsce'] = $s['hangar']['nazwa'];
                        $k[$u['id']]['pozycja']['zmieniono'] = teraz_ms();
                    });
                    odpowiedz(['ok' => true]);
                }
            }
            blad('Statek nie stoi w hangarze.');

        case 'start':
            // Start z hangaru: wszyscy na pokładzie widzą wznoszenie (chmury → kosmos → orbita).
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $klucz = karta($u['id'])['pozycja']['lokacja'] ?? '';
            $statek = ruch_statek($klucz);
            if (!$statek) blad('Nie jesteś na pokładzie statku.');
            if (!empty($statek['w_locie'])) blad('Statek już jest w powietrzu.');
            if (empty($statek['przygotowanie']) || time() < $statek['przygotowanie']['gotowy']) blad('Statek nie przeszedł obsługi naziemnej.');
            $planeta = ruch_planeta($statek['lokalizacja'] ?? '');
            $hangar = ruch_lokacje()[$statek['hangar'] ?? '']['nazwa'] ?? 'hangar';
            ruch_zmien_statek($klucz, function (&$s) {
                $s['w_locie'] = true;
                $s['paliwo'] = max(0, (int) ($s['paliwo'] ?? 100) - 5); // wznoszenie z atmosfery
                unset($s['przygotowanie'], $s['cel']);
            });
            $scena = ['scena' => 'start', 'planeta' => $planeta['typ'] ?? 'skalista', 'odleglosc' => 'blisko',
                'miejsce' => $planeta['nazwa'], 'opis' => 'Start: ' . $hangar, 'wnetrze' => '', 'zmieniono' => teraz_ms()];
            $zaloga = ruch_na_pokladzie($klucz);
            zmien('sceny', function (&$s) use ($zaloga, $scena) {
                foreach ($zaloga as $uid) $s[$uid] = $scena;
            });
            odpowiedz(['ok' => true, 'na_pokladzie' => count($zaloga)]);

        case 'skok':
            // Współrzędne skoku. Na razie: współrzędne planet z katalogu (pole „wspolrzedne”).
            // Pełne astronawigacyjne zasady dopiszemy razem z mapą.
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $klucz = karta($u['id'])['pozycja']['lokacja'] ?? '';
            $statek = ruch_statek($klucz);
            if (!$statek || empty($statek['w_locie'])) blad('Skok możliwy tylko w locie, poza atmosferą.');
            $sc = (wczytaj('sceny', [])[$u['id']] ?? []);
            if (($sc['scena'] ?? '') === 'start' && teraz_ms() - (int) $sc['zmieniono'] < CZAS_WZNOSZENIA_MS) blad('Statek wciąż jest w atmosferze. Poczekaj na wyjście na orbitę.');
            if (in_array($sc['scena'] ?? '', ['wejscie', 'nadswietlna'], true)) blad('Statek jest już w nadprzestrzeni.');
            $norm = fn($t) => preg_replace('/[^0-9A-Z.\-]/u', '', mb_strtoupper((string) $t));
            $wsp = $norm(wejscie()['wspolrzedne'] ?? '');
            if ($wsp === '') blad('Wprowadź współrzędne.');
            $cel = null;
            foreach (katalog()['planety'] as $p) {
                if (($p['wspolrzedne'] ?? '') !== '' && $norm($p['wspolrzedne']) === $wsp) $cel = $p;
            }
            if (!$cel) blad('Komputer nawigacyjny nie rozpoznaje tych współrzędnych.');
            if ($cel['id'] === ($statek['lokalizacja'] ?? '')) blad('Jesteś już przy tej planecie.');
            ruch_zmien_statek($klucz, function (&$s) use ($cel) {
                $s['cel'] = $cel['id'];
            });
            lacznosc_zamknij($klucz);
            $scena = ['scena' => 'wejscie', 'planeta' => $cel['typ'] ?? 'skalista', 'odleglosc' => 'srednio',
                'miejsce' => $cel['nazwa'], 'opis' => 'Kurs: ' . $cel['nazwa'], 'wnetrze' => '', 'zmieniono' => teraz_ms()];
            $zaloga = ruch_na_pokladzie($klucz);
            zmien('sceny', function (&$s) use ($zaloga, $scena) {
                foreach ($zaloga as $uid) $s[$uid] = $scena;
            });
            odpowiedz(['ok' => true, 'cel' => $cel['nazwa']]);

        case 'lacznosc_wyslij':
            // Gracz wywołuje kontrolę lotów planety, przy której jest (jeśli planeta ją ma).
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $klucz = karta($u['id'])['pozycja']['lokacja'] ?? '';
            $statek = ruch_statek($klucz);
            if (!$statek || empty($statek['w_locie']) || !empty($statek['cel'])) blad('Łączność z kontrolą lotów jest możliwa tylko na orbicie.');
            $pl = ruch_planeta($statek['lokalizacja'] ?? '');
            if (trim((string) ($pl['kontrola'] ?? '')) === '') blad('Brak odpowiedzi. Ta planeta nie ma kontroli lotów.');
            $tresc = mb_substr(trim((string) (wejscie()['tresc'] ?? '')), 0, 500) ?: 'Prosimy o przydział lądowiska.';
            $watek = zmien('lacznosc', function (&$l) use ($klucz, $pl, $tresc, $u, $statek) {
                if (($l[$klucz]['planeta'] ?? '') !== $pl['id']) {
                    $l[$klucz] = ['planeta' => $pl['id'], 'kontrola' => trim($pl['kontrola']), 'przydzial' => '', 'wiadomosci' => []];
                }
                $l[$klucz]['wiadomosci'][] = ['od' => 'statek', 'autor' => $u['postac'] . ' (' . $statek['nazwa'] . ')', 'tresc' => $tresc, 'czas' => date('c')];
                $l[$klucz]['czeka'] = true;
                return $l[$klucz];
            });
            // Przyszłe NPC: jeśli odpowie, dopisujemy od razu.
            $npc = lacznosc_odpowiedz_npc($klucz, $watek);
            if ($npc) {
                zmien('lacznosc', function (&$l) use ($klucz, $npc) {
                    $l[$klucz]['wiadomosci'][] = $npc + ['od' => 'kontrola', 'czas' => date('c')];
                    $l[$klucz]['czeka'] = false;
                });
            }
            odpowiedz(['ok' => true]);

        // ----- panel MG -----

        case 'mg_lacznosc_odpowiedz':
            // MG odpowiada jako kontrola lotów i opcjonalnie przydziela lądowisko.
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $klucz = (string) ($w['statek'] ?? '');
            $statek = ruch_statek($klucz);
            if (!$statek || empty($statek['w_locie'])) blad('Ten statek nie jest w locie.');
            $pl = ruch_planeta($statek['lokalizacja'] ?? '');
            $przydzial = (string) ($w['przydzial'] ?? '');
            $lok = ruch_lokacje();
            if ($przydzial !== '' && (!isset($lok[$przydzial]) || $lok[$przydzial]['planeta'] !== $pl['id'])) blad('To lądowisko nie leży na tej planecie.');
            $tresc = mb_substr(trim((string) ($w['tresc'] ?? '')), 0, 800);
            if ($tresc === '' && $przydzial !== '') $tresc = 'Przydział: ' . $lok[$przydzial]['nazwa'] . '. Lądowanie dozwolone.';
            if ($tresc === '') blad('Wiadomość jest pusta.');
            zmien('lacznosc', function (&$l) use ($klucz, $pl, $tresc, $przydzial) {
                if (($l[$klucz]['planeta'] ?? '') !== $pl['id']) {
                    $l[$klucz] = ['planeta' => $pl['id'], 'kontrola' => trim((string) ($pl['kontrola'] ?? '')), 'przydzial' => '', 'wiadomosci' => []];
                }
                $l[$klucz]['wiadomosci'][] = ['od' => 'kontrola', 'autor' => $l[$klucz]['kontrola'] ?: 'Kontrola lotów', 'tresc' => $tresc, 'czas' => date('c')];
                if ($przydzial !== '') $l[$klucz]['przydzial'] = $przydzial;
                $l[$klucz]['czeka'] = false;
            });
            odpowiedz(['ok' => true]);

        case 'mg_statki_w_locie':
            wymagaj_mg();
            $pl = array_column(katalog()['planety'], 'nazwa', 'id');
            $postacie = bank_postacie();
            $lokacje = ruch_lokacje();
            $lacznosc = wczytaj('lacznosc', []);
            $lista = [];
            foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
                foreach ($f['dane']['flota'] ?? [] as $s) {
                    if (empty($s['w_locie'])) continue;
                    $klucz = 's:' . $f['id'] . ':' . $s['id'];
                    $planeta = ruch_planeta($s['lokalizacja'] ?? '');
                    $lad = [];
                    foreach ($lokacje as $k => $l) {
                        if ($l['planeta'] === ($s['lokalizacja'] ?? '') && in_array($l['typ'], LADOWISKA, true)) $lad[] = ['klucz' => $k, 'nazwa' => $l['nazwa'], 'typ' => $l['typ'], 'x' => $l['x'], 'y' => $l['y']];
                    }
                    $watek = $lacznosc[$klucz] ?? null;
                    $lista[] = ['klucz' => $klucz, 'nazwa' => $s['nazwa'], 'firma' => $f['nazwa'], 'przy' => $pl[$s['lokalizacja'] ?? ''] ?? '?',
                        'cel' => $pl[$s['cel'] ?? ''] ?? '', 'zaloga' => array_map(fn($uid) => $postacie[$uid] ?? '?', ruch_na_pokladzie($klucz)),
                        'kontrola' => trim((string) ($planeta['kontrola'] ?? '')), 'ladowiska' => $lad,
                        'watek' => $watek ? ['wiadomosci' => $watek['wiadomosci'], 'czeka' => !empty($watek['czeka']), 'przydzial' => $watek['przydzial']] : null];
                }
            }
            odpowiedz(['statki' => $lista]);

        case 'mg_skok_koniec':
            // Wyjście z nadprzestrzeni przy celu: statek „jest” przy nowej planecie (hangar: pierwszy
            // hangar/port tej planety), załoga widzi wyjście z nadprzestrzeni z planetą.
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $klucz = (string) (wejscie()['statek'] ?? '');
            $statek = ruch_statek($klucz);
            if (!$statek || empty($statek['cel'])) blad('Ten statek nie jest w skoku.');
            $cel = ruch_planeta($statek['cel']);
            ruch_zmien_statek($klucz, function (&$s) use ($cel) {
                $s['lokalizacja'] = $cel['id'];
                $s['hangar'] = ''; // lądowisko trzeba wybrać, wpisać albo dostać od kontroli lotów
                $s['paliwo'] = max(0, (int) ($s['paliwo'] ?? 100) - 20);
                unset($s['cel']);
            });
            lacznosc_nowa($klucz, $cel);
            $hangar = true;
            $zaloga = ruch_na_pokladzie($klucz);
            $scena = ['scena' => 'wyjscie', 'planeta' => $cel['typ'] ?? 'skalista', 'odleglosc' => 'srednio',
                'miejsce' => $cel['nazwa'], 'opis' => 'Wyjście z nadprzestrzeni', 'wnetrze' => '', 'zmieniono' => teraz_ms()];
            zmien('sceny', function (&$s) use ($zaloga, $scena) {
                foreach ($zaloga as $uid) $s[$uid] = $scena;
            });
            // Pozycja postaci na pokładzie przenosi się na nową planetę.
            zmien('karty', function (&$k) use ($zaloga, $cel) {
                foreach ($zaloga as $uid) $k[$uid]['pozycja']['planeta'] = $cel['id'];
            });
            odpowiedz(['ok' => true, 'hangar' => $hangar !== '']);

        case 'ladowanie':
            // Automat: lądowisko z listy znanych (albo przydzielone przez kontrolę lotów).
            // Ręcznie: współrzędne „x / y” lądowiska na tej planecie (nie musi być znane).
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $karta = karta($u['id']);
            $klucz = $karta['pozycja']['lokacja'] ?? '';
            $statek = ruch_statek($klucz);
            if (!$statek || empty($statek['w_locie'])) blad('Statek nie jest w locie.');
            if (!empty($statek['cel'])) blad('Statek jest w drodze do innego systemu.');
            $sc = wczytaj('sceny', [])[$u['id']] ?? [];
            if (($sc['scena'] ?? '') === 'start' && teraz_ms() - (int) $sc['zmieniono'] < CZAS_WZNOSZENIA_MS) blad('Statek wciąż się wznosi.');
            $w = wejscie();
            $lok = ruch_lokacje();
            $planeta = $statek['lokalizacja'] ?? '';
            if (($w['tryb'] ?? 'auto') === 'reczne') {
                if (!preg_match_all('/-?\d+(?:[.,]\d+)?/', (string) ($w['wspolrzedne'] ?? ''), $m) || count($m[0]) < 2) blad('Podaj współrzędne w postaci: x / y.');
                [$x, $y] = array_map(fn($v) => (float) str_replace(',', '.', $v), array_slice($m[0], 0, 2));
                $cel = null;
                foreach ($lok as $l) {
                    if ($l['planeta'] === $planeta && in_array($l['typ'], LADOWISKA, true) && ruch_odleglosc($l, ['x' => $x, 'y' => $y]) <= TOLERANCJA_KM) $cel = $l;
                }
                if (!$cel) blad('Pod tymi współrzędnymi nie ma lądowiska. Podejście przerwane.');
            } else {
                $cel = ruch_ladowiska($u['id'], $karta, $statek, $lok)[(string) ($w['cel'] ?? '')] ?? null;
                if (!$cel) blad('Autopilot nie zna tego lądowiska.');
            }
            ruch_zmien_statek($klucz, function (&$s) use ($cel) {
                $s['w_locie'] = false;
                $s['hangar'] = $cel['klucz'];
            });
            lacznosc_zamknij($klucz);
            // Załoga zapamiętuje lądowisko (autopilot zna je przy następnej wizycie).
            $zaloga = ruch_na_pokladzie($klucz);
            zmien('karty', function (&$k) use ($zaloga, $cel) {
                foreach ($zaloga as $uid) {
                    $znane = $k[$uid]['znane'] ?? [];
                    if (!in_array($cel['klucz'], $znane, true)) $k[$uid]['znane'][] = $cel['klucz'];
                }
            });
            $zaloga = ruch_na_pokladzie($klucz);
            zmien('karty', function (&$k) use ($zaloga) {
                foreach ($zaloga as $uid) $k[$uid]['pozycja']['zmieniono'] = teraz_ms();
            });
            odpowiedz(['ok' => true]);
    }
}

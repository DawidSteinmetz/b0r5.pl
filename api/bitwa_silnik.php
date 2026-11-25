<?php
declare(strict_types=1);

// Pętla tików bitwy — przepisana 1:1 z silnika wzorcowego narzedzia/mostek/silnik.py (krok, utworz, rozkazy, AI).
// Bez bazy danych: działa na tablicy stanu $S; zapis i odczyt robi api/bitwa.php. Zmiana tu = zmiana w silnik.py
// (i odwrotnie), a test zgodności api/?a=bitwa_test porównuje przebieg z wektorami z Pythona.
//
// $S: ziarno, tik, rozkazy (lista), po_kanale (kanał → indeksy rozkazów), aktywne (indeksy w przygotowaniu/działaniu),
//     zdarzenia (nowe w tym doliczeniu), jednostki, klucze, profile, maszyny.

const BITWA_STALE = [
    'odplyw_obciazenia' => 0.98,
    'energia_zabicia_zyly_s' => 1.0,
    'promien_trafienia' => 7,
    'zasieg_ognia_klucza_km' => 3.0,
    'dystans_ataku_km' => 2.0,
    'zasieg_sensorow_km' => 200.0,
    'tasma_s' => 60,
    'promien_ochrony_km' => 3.0,
    'promien_patrolu_km' => 8.0,
    'promien_skanu_km' => 10.0,
    'ladowanie_km' => 0.5,
    'kadlub_j' => ['ISD_I' => 5e23, 'MC80' => 4e23, 'ZBIEG' => 2e21],
    'xp_akcja' => 0.5,
    'xp_mnoznik' => ['as' => 0.5, 'standard' => 1.0, 'kadet' => 1.5],
    'dryf_k' => ['kadet' => 0.25, 'standard' => 0.25, 'as' => 1 / 3],
    'zmiany_cykl' => ['kadet' => 2, 'standard' => 2, 'as' => 4],
];
const BITWA_CYKL = 12;
const BITWA_RANGI = ['kadet' => 0, 'standard' => 1, 'as' => 2];

function bitwa_stopnie_kat(float $x): float { return $x * 57.29577951308232; }
function bitwa_radiany(float $x): float { return $x * 0.017453292519943295; }
function bitwa_zaokr(float $x): int { return (int) floor($x + 0.5); }

function bitwa_rozrzut(int $ziarno, int $s, string|int ...$klucze): int
{
    return $s ? (int) (bitwa_los($ziarno, 0, 'rozrzut', ...$klucze) * ($s + 1)) : 0;
}

// Słowniki z SQL: profile klas (z sekcjami kadłuba), typy maszyn, stałe skali żył.
function bitwa_slowniki(): array
{
    static $s = null;
    if ($s !== null) return $s;
    $db = polaczenie_bazy();
    $stale = $db->query('SELECT kod, wartosc FROM mechanika_stale')->fetchAll(PDO::FETCH_KEY_PAIR);
    $profile = [];
    foreach ($db->query('SELECT * FROM profile_klas')->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $profile[$p['kod']] = [
            'nazwa' => $p['nazwa'], 'generatory' => (int) $p['generatory_oslon'], 'zyly' => (int) $p['zyly_oslon'],
            'siatka' => $p['siatka_kolumny'] !== null ? (int) $p['siatka_kolumny'] : null,
            'U0' => (float) $p['zyla_u0_v'], 'R0' => (float) $p['zyla_r0_ohm'], 'domyslnie_hz' => (int) $p['oslony_domyslnie_hz'],
            'mysliwce' => (int) $p['mysliwce'], 'dlugosc_m' => (float) $p['dlugosc_m'], 'kadlub' => [],
        ];
    }
    foreach ($db->query('SELECT profil_kod, sekcja, udzial FROM profile_klas_kadlub')->fetchAll(PDO::FETCH_ASSOC) as $k) {
        $profile[$k['profil_kod']]['kadlub'][$k['sekcja']] = (float) $k['udzial'];
    }
    foreach ($profile as &$p) ksort($p['kadlub'], SORT_STRING);
    unset($p);
    $maszyny = [];
    foreach ($db->query('SELECT * FROM typy_maszyn')->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $maszyny[$m['kod']] = ['nazwa' => $m['nazwa'], 'hz' => (int) $m['hz_bazowe'], 'v' => (float) $m['predkosc_km_s'],
            'energia' => (float) $m['energia_strzalu_j'], 'ladowanie' => (int) $m['ladowanie_tiki']];
    }
    return $s = ['profile' => $profile, 'maszyny' => $maszyny,
        'skala_zyl' => (float) ($stale['skala_zyl'] ?? 1e17), 'tau' => (float) ($stale['tau_pojemnosci_s'] ?? 0.2508)];
}

function bitwa_moc_zyly(array $p): float
{
    return $p['U0'] ** 2 / $p['R0'] * bitwa_slowniki()['skala_zyl'];
}

function bitwa_pojemnosc(array $p): float
{
    return bitwa_slowniki()['tau'] * $p['zyly'] * bitwa_moc_zyly($p);
}

// ------------------------------------------------------------------------------------------------------- rozkazy

// Rozkaz odebrany przez serwer w chwili $czas bitwy; zwraca indeks wpisu albo ['odmowa' => …]. Jak silnik.py przyjmij_rozkaz.
function bitwa_przyjmij(array &$S, string $kanal, string $kod, float $czas, string $ranga = 'standard', ?int $ki = null, array $meta = []): array|int
{
    $r = bitwa_rozkazy_profile()[$kod] ?? null;
    if (!$r) return ['odmowa' => "nieznany rozkaz $kod"];
    $tik = (int) $czas;
    if (BITWA_RANGI[$ranga] < BITWA_RANGI[$r['ranga_min']]) return ['odmowa' => "{$r['nazwa']}: tylko {$r['ranga_min']}"];
    $ten = $S['po_kanale'][$kanal] ?? [];
    foreach ($ten as $i) {
        $w = $S['rozkazy'][$i];
        if ($w['kod'] === $kod && $w['stan'] !== 'przerwany' && $w['odnowienie_do'] && $tik < $w['odnowienie_do']) {
            return ['odmowa' => "{$r['nazwa']}: odnowienie jeszcze " . ($w['odnowienie_do'] - $tik) . ' s'];
        }
    }
    if ($ki !== null && $r['grupa'] !== 'okret') {
        $cykl = intdiv($tik, BITWA_CYKL);
        $zmiany = 0;
        foreach ($ten as $i) if (intdiv($S['rozkazy'][$i]['tik'], BITWA_CYKL) === $cykl) $zmiany++;
        if ($zmiany >= BITWA_STALE['zmiany_cykl'][$ranga]) return ['odmowa' => 'limit zmian w tym cyklu'];
    }
    foreach ($ten as $i) {
        if ($S['rozkazy'][$i]['stan'] === 'przygotowanie') {
            $S['rozkazy'][$i]['stan'] = 'przerwany';
            $S['rozkazy'][$i]['zmieniony'] = true;
            unset($S['aktywne'][$i]);
        }
    }
    $c = bitwa_przygotowanie($kod, $ranga);
    $d = $r['dzialanie_s'] !== null ? (int) $r['dzialanie_s'] : null;
    $o = (int) $r['odnowienie_s'];
    $S['rozkazy'][] = $meta + [
        'id' => null, 'kanal' => $kanal, 'kod' => $kod, 'tik' => $tik, 'czas_s' => $czas, 'stan' => 'przygotowanie', 'ranga' => $ranga,
        'dziala_od' => $tik + $c, 'dziala_do' => $d !== null ? $tik + $c + $d : null,
        'odnowienie_do' => $o ? $tik + $c + ($d ?? 0) + $o : null, 'ki' => $ki, 'zmieniony' => true,
    ];
    $idx = array_key_last($S['rozkazy']);
    $S['po_kanale'][$kanal][] = $idx;
    $S['aktywne'][$idx] = true;
    return $idx;
}

function bitwa_aktywuj(array &$S, int $tik): array
{
    $nowe = [];
    ksort($S['aktywne']);
    foreach (array_keys($S['aktywne']) as $i) {
        if (!isset($S['aktywne'][$i])) continue;
        $w = $S['rozkazy'][$i];
        if ($w['stan'] === 'przygotowanie' && $tik >= $w['dziala_od']) {
            foreach ($S['po_kanale'][$w['kanal']] as $j) {
                if ($j !== $i && $S['rozkazy'][$j]['stan'] === 'dziala') {
                    $S['rozkazy'][$j]['stan'] = 'zakonczony';
                    $S['rozkazy'][$j]['zmieniony'] = true;
                    unset($S['aktywne'][$j]);
                }
            }
            $S['rozkazy'][$i]['stan'] = 'dziala';
            $S['rozkazy'][$i]['zmieniony'] = true;
            $nowe[] = $i;
        } elseif ($w['stan'] === 'dziala' && $w['dziala_do'] !== null && $tik >= $w['dziala_do']) {
            $S['rozkazy'][$i]['stan'] = 'zakonczony';
            $S['rozkazy'][$i]['zmieniony'] = true;
            unset($S['aktywne'][$i]);
        }
    }
    return $nowe;
}

function bitwa_figura(array $S, string $kanal): ?string
{
    $prof = bitwa_rozkazy_profile();
    foreach (array_reverse($S['po_kanale'][$kanal] ?? []) as $i) {
        $w = $S['rozkazy'][$i];
        if ($w['stan'] === 'dziala' && $prof[$w['kod']]['grupa'] !== 'okret') return $w['kod'];
    }
    return null;
}

// ------------------------------------------------------------------------------------------------------- stan

// Stan początkowy ze scenariusza (jak silnik.py utworz). $sc: wiersze scenariusza z SQL (strony, jednostki, klucze).
function bitwa_stan_ze_scenariusza(array $sc, int $ziarno): array
{
    $sl = bitwa_slowniki();
    $S = ['ziarno' => $ziarno, 'tik' => 0, 'rozkazy' => [], 'po_kanale' => [], 'aktywne' => [], 'zdarzenia' => [], 'jednostki' => [], 'klucze' => []];
    foreach ($sc['jednostki'] as $j) {
        $p = $sl['profile'][$j['profil_kod']];
        $gen = [];
        for ($g = 0; $g < $p['generatory']; $g++) {
            $gen[] = ['kod' => 'oslona_' . ($g + 1), 'hz_zadane' => $p['domyslnie_hz'], 'hz_osiagane' => $p['domyslnie_hz'], 'hz_min' => 1,
                'hz_max' => $p['zyly'], 'obciazenie' => 0.0, 'rykoszet' => 0.0, 'zyly' => bitwa_zyly_nowe($p['zyly']), 'auto' => true];
        }
        $nr = (int) $j['nr'];
        $S['jednostki'][] = ['nr' => $nr, 'strona' => $j['strona'], 'profil' => $j['profil_kod'], 'nazwa' => $j['nazwa'],
            'x' => (float) $j['x_km'], 'y' => (float) $j['y_km'], 'kurs' => (float) $j['kurs_deg'], 'v' => (float) $j['predkosc_km_s'],
            'wejscie' => (int) $j['wejscie_s'] + bitwa_rozrzut($ziarno, (int) $j['wejscie_rozrzut_s'], 'j', $nr),
            'generatory' => $gen, 'sekcje' => array_fill_keys(array_keys($p['kadlub']), 100.0), 'stan' => 'w_bitwie',
            'obiekt_id' => $j['obiekt_id'] ?? null];
    }
    $strony = [];
    foreach ($sc['strony'] as $s) $strony[$s['strona']] = $s;
    $klucze = $sc['klucze'];
    usort($klucze, static fn($a, $b) => strcmp($a['strona'], $b['strona']) ?: ((int) $a['fala'] <=> (int) $b['fala']) ?: strcmp($a['typ_maszyny'], $b['typ_maszyny']));
    $numery = [];
    foreach ($klucze as $kl) {
        $st = $strony[$kl['strona']];
        for ($i = 0; $i < (int) $kl['liczba_kluczy']; $i++) {
            $numery[$kl['strona']] = ($numery[$kl['strona']] ?? 0) + 1;
            $nr = $numery[$kl['strona']];
            $mac = null;
            foreach ($S['jednostki'] as $j) if ($kl['jednostka_nr'] !== null && $j['nr'] === (int) $kl['jednostka_nr']) { $mac = $j; break; }
            $l = bitwa_los($ziarno, 0, 'ranga', $kl['strona'], $nr);
            $ranga = $l < (int) $st['asy_proc'] / 100 ? 'as' : ($l < ((int) $st['asy_proc'] + (int) $st['kadeci_proc']) / 100 ? 'kadet' : 'standard');
            $S['klucze'][] = ['strona' => $kl['strona'], 'nr' => $nr, 'typ' => $kl['typ_maszyny'], 'maszyny' => 4, 'ranga' => $ranga, 'moc' => 1.0,
                'stan' => 'hangar', 'start' => (int) $kl['start_s'] + bitwa_rozrzut($ziarno, (int) $kl['start_rozrzut_s'], 'k', $kl['strona'], $nr),
                'x' => (float) ($kl['x_km'] ?? $mac['x']), 'y' => (float) ($kl['y_km'] ?? $mac['y']), 'kurs' => 0.0, 'rozkaz' => $kl['rozkaz'],
                'macierzysta' => $kl['jednostka_nr'] !== null ? (int) $kl['jednostka_nr'] : null, 'ladunek' => 0, 'akcje' => 0,
                'ai' => $st['dowodzenie'] !== 'gracze'];
        }
    }
    bitwa_tasmociag($S);
    return $S;
}

// Taśmociąg okrętu: klucze z jednego okrętu startują w kolejności planu, co najmniej tasma_s sekund po sobie.
function bitwa_tasmociag(array &$S): void
{
    $S['tasma'] = [];
    $kol = [];
    foreach ($S['klucze'] as $i => $k) if ($k['macierzysta'] !== null && $k['start'] !== null) $kol[] = $i;
    $kl = $S['klucze'];
    usort($kol, static fn($a, $b) => ($kl[$a]['start'] <=> $kl[$b]['start']) ?: ($a <=> $b));
    foreach ($kol as $i) {
        $m = $S['klucze'][$i]['macierzysta'];
        if (isset($S['tasma'][$m])) $S['klucze'][$i]['start'] = max($S['klucze'][$i]['start'], $S['tasma'][$m] + BITWA_STALE['tasma_s']);
        $S['tasma'][$m] = $S['klucze'][$i]['start'];
    }
}

// Kanał figur klucza (manewry).
function bitwa_kanal_klucza(array $k): string
{
    return "klucz:{$k['strona']}:{$k['nr']}";
}

// Kanał rozkazów klucza (ochrona, patrol, atak, skan, powrót, start) — osobny od figur.
function bitwa_kanal_rozkazu(array $k): string
{
    return "rozkaz:{$k['strona']}:{$k['nr']}";
}

function bitwa_macierzysta(array $S, array $k): ?int
{
    foreach ($S['jednostki'] as $i => $j) if ($j['nr'] === $k['macierzysta'] && $j['stan'] === 'w_bitwie') return $i;
    return null;
}

// Dokąd leci klucz i w jakim promieniu krąży (jak silnik.py cel_klucza): [indeks jednostki|null, promień km].
function bitwa_cel_klucza(array $S, array $k): array
{
    $mac = bitwa_macierzysta($S, $k);
    $wrogie = bitwa_wrogowie($S, $k['strona']);
    $r = $k['rozkaz'];
    if ((in_array($r, ['atak', 'skan'], true) || $mac === null) && $wrogie) {
        return [bitwa_najblizsza($S, $k, $wrogie), $r === 'skan' ? BITWA_STALE['promien_skanu_km'] : BITWA_STALE['dystans_ataku_km']];
    }
    if ($mac === null) return [null, 0.0];
    if ($r === 'powrot') return [$mac, 0.0];
    return [$mac, $r === 'patrol' ? BITWA_STALE['promien_patrolu_km'] : BITWA_STALE['promien_ochrony_km']];
}

// Indeksy jednostek wroga w bitwie (kolejność jak w stanie).
function bitwa_wrogowie(array $S, string $strona): array
{
    $w = [];
    foreach ($S['jednostki'] as $i => $j) if ($j['strona'] !== $strona && $j['stan'] === 'w_bitwie') $w[] = $i;
    return $w;
}

function bitwa_odleglosc(array $a, array $b): float
{
    return hypot($a['x'] - $b['x'], $a['y'] - $b['y']);
}

function bitwa_hz_klucza(array $k): float
{
    return bitwa_slowniki()['maszyny'][$k['typ']]['hz'] * (1 + BITWA_STALE['dryf_k'][$k['ranga']] * $k['moc']);
}

// Najbliższa jednostka z listy indeksów (pierwsza przy remisie — jak min() w Pythonie).
function bitwa_najblizsza(array $S, array $k, array $indeksy): int
{
    $naj = $indeksy[0];
    $dn = bitwa_odleglosc($k, $S['jednostki'][$naj]);
    foreach ($indeksy as $i) {
        $d = bitwa_odleglosc($k, $S['jednostki'][$i]);
        if ($d < $dn) { $naj = $i; $dn = $d; }
    }
    return $naj;
}

function bitwa_generator_od_strony(array $j, float $x, float $y): int
{
    $kat = bitwa_radiany($j['kurs']);
    $dx = $x - $j['x'];
    $dy = $y - $j['y'];
    $wzdl = $dx * sin($kat) + $dy * cos($kat);
    $wBok = $dx * cos($kat) - $dy * sin($kat);
    $g = count($j['generatory']);
    $burta = $wBok >= 0 ? 1 : 0;
    if ($g === 1) return 0;
    if ($g === 2) return $burta;
    $naBurte = intdiv($g, 2);
    $odl = hypot($dx, $dy) ?: 1.0;
    $czesc = min($naBurte - 1, (int) ((1 - ($wzdl / $odl + 1) / 2) * $naBurte));
    return $burta * $naBurte + $czesc;
}

function bitwa_trafienie(array &$S, int $tik, int $ji, int $gi, float $energia, float $hzL, string $sprawca, string $ziarnoKlucz): void
{
    $j = &$S['jednostki'][$ji];
    $gen = &$j['generatory'][$gi];
    $p = bitwa_slowniki()['profile'][$j['profil']];
    $hzO = $gen['hz_osiagane'] ? bitwa_hz_skuteczne($gen['zyly'], $gen['hz_osiagane']) : 0;
    [$doKadluba, $doGen] = $hzO ? bitwa_filtr($energia, (float) $hzO, $hzL) : [$energia, 0.0];
    $gen['obciazenie'] += $doGen;
    $c = bitwa_pojemnosc($p);
    if ($gen['obciazenie'] > $c) {
        $gen['rykoszet'] += $gen['obciazenie'] - $c;
        $gen['obciazenie'] = $c;
    }
    if ($doKadluba > 0) {
        $pz = bitwa_moc_zyly($p) * BITWA_STALE['energia_zabicia_zyly_s'];
        $n = $gen['zyly']['n'];
        $bok = $p['siatka'] ?? (int) sqrt($n);
        $z0 = $S['ziarno'];
        $srodek = (int) (bitwa_los($z0, $tik, 'traf', $ziarnoKlucz) * $n);
        $zabite = (int) ($doKadluba / $pz);
        for ($i = 0; $i < $zabite; $i++) {
            $a = bitwa_los($z0, $tik, 'zyla', $ziarnoKlucz, $i) * 2 * M_PI;
            $r = sqrt(-2 * log(max(1e-12, bitwa_los($z0, $tik, 'r', $ziarnoKlucz, $i)))) * BITWA_STALE['promien_trafienia'] / 2;
            $z = $srodek + bitwa_zaokr($r * sin($a)) * $bok + bitwa_zaokr($r * cos($a));
            if ($z >= 0 && $z < $n) bitwa_zyla_zabij($gen['zyly'], $z);
        }
        $sekcje = array_keys($j['sekcje']);
        $sek = $sekcje[(int) (bitwa_los($z0, $tik, 'sekcja', $ziarnoKlucz) * count($sekcje))];
        $ubytek = 100 * $doKadluba / (BITWA_STALE['kadlub_j'][$j['profil']] * $p['kadlub'][$sek]);
        $j['sekcje'][$sek] = max(0.0, $j['sekcje'][$sek] - $ubytek);
        $S['zdarzenia'][] = ['tik' => $tik, 'rodzaj' => 'przebicie', 'opis' => "$sprawca przebija osłonę {$j['nazwa']} ({$gen['kod']})",
            'wartosc' => round($doKadluba / $energia, 4)];
    }
    unset($gen, $j);
}

function bitwa_ai_oslony(array &$S, int $tik, int $ji): void
{
    foreach ($S['jednostki'][$ji]['generatory'] as $gi => $gen) {
        if (!$gen['auto']) continue;
        $j = $S['jednostki'][$ji];
        $hz = [];
        foreach ($S['klucze'] as $k) {
            if ($k['strona'] !== $j['strona'] && $k['stan'] === 'w_locie' && bitwa_odleglosc($k, $j) < BITWA_STALE['zasieg_sensorow_km']) $hz[] = bitwa_hz_klucza($k);
        }
        if (!$hz) continue;
        $cel = bitwa_stopien_w_gore($gen['zyly']['n'], max($hz)) ?: $gen['hz_max'];
        $cel = min(max($cel, $gen['hz_min']), $gen['hz_max']);
        $kanal = "{$j['nr']}:{$gen['kod']}";
        $wPrzyg = false;
        foreach ($S['po_kanale'][$kanal] ?? [] as $i) if ($S['rozkazy'][$i]['stan'] === 'przygotowanie') { $wPrzyg = true; break; }
        if ($cel !== $gen['hz_zadane'] && !$wPrzyg) {
            $S['jednostki'][$ji]['generatory'][$gi]['hz_zadane'] = $cel;
            bitwa_przyjmij($S, $kanal, 'przestrojenie_auto', (float) $tik, 'standard', null, ['ai' => true]);
        }
    }
}

function bitwa_ai_klucza(array &$S, int $tik, int $ki): void
{
    $k = $S['klucze'][$ki];
    $cele = bitwa_wrogowie($S, $k['strona']);
    if (!$cele) return;
    $d = bitwa_odleglosc($k, $S['jednostki'][bitwa_najblizsza($S, $k, $cele)]);
    $kan = bitwa_kanal_klucza($k);
    $fig = bitwa_figura($S, $kan);
    foreach ($S['po_kanale'][$kan] ?? [] as $i) if ($S['rozkazy'][$i]['stan'] === 'przygotowanie') return;
    $z0 = $S['ziarno'];
    if ($d < 8 && !in_array($fig, ['beczka_l', 'beczka_p', 'zygzak', 'przelot'], true) && bitwa_los($z0, $tik, 'ai', $kan) < 0.15) {
        $kod = bitwa_los($z0, $tik, 'ai2', $kan) < 0.5 ? 'beczka_l' : 'zygzak';
        bitwa_przyjmij($S, $kan, $kod, (float) $tik, $k['ranga'], $ki, ['ai' => true]);
    } elseif ($d < BITWA_STALE['zasieg_ognia_klucza_km'] && $fig !== 'przelot' && bitwa_los($z0, $tik, 'ai3', $kan) < 0.2) {
        bitwa_przyjmij($S, $kan, 'przelot', (float) $tik, $k['ranga'], $ki, ['ai' => true]);
    }
}

// Jeden tik: rozkazy → ruch → ogień → straty → zdarzenia (jak silnik.py krok).
function bitwa_krok(array &$S): void
{
    $tik = $S['tik'];
    $prof = bitwa_rozkazy_profile();
    $masz = bitwa_slowniki()['maszyny'];
    foreach (bitwa_aktywuj($S, $tik) as $i) {
        $w = $S['rozkazy'][$i];
        if ($w['ki'] !== null) {
            $ki = $w['ki'];
            $S['klucze'][$ki]['akcje']++;
            if ($prof[$w['kod']]['grupa'] === 'rozkaz_klucza') {
                if ($w['kod'] === 'start') {
                    if ($S['klucze'][$ki]['stan'] === 'hangar' && $S['klucze'][$ki]['start'] === null) {
                        $m = $S['klucze'][$ki]['macierzysta'];
                        $S['klucze'][$ki]['start'] = max($tik, ($S['tasma'][$m] ?? -1000000000) + BITWA_STALE['tasma_s']);
                        $S['tasma'][$m] = $S['klucze'][$ki]['start'];
                    }
                } else {
                    $S['klucze'][$ki]['rozkaz'] = $w['kod'];
                    if ($w['kod'] === 'powrot' && $S['klucze'][$ki]['stan'] === 'w_locie') $S['klucze'][$ki]['stan'] = 'powrot';
                }
            }
        }
        if (str_starts_with($w['kod'], 'przestrojenie')) {
            [$nr, $gk] = explode(':', $w['kanal'], 2);
            foreach ($S['jednostki'] as $ji => $j) {
                if ((string) $j['nr'] !== $nr) continue;
                foreach ($j['generatory'] as $gi => $g) {
                    if ($g['kod'] !== $gk) continue;
                    $S['jednostki'][$ji]['generatory'][$gi]['hz_osiagane'] = $g['hz_zadane'];
                    $S['zdarzenia'][] = ['tik' => $tik, 'rodzaj' => 'przestrojenie', 'opis' => "{$j['nazwa']}: {$gk} na {$g['hz_zadane']} Hz", 'wartosc' => $g['hz_zadane']];
                }
                break;
            }
        }
    }
    // ruch
    foreach ($S['klucze'] as $ki => $k) {
        if ($k['stan'] === 'hangar' && $k['start'] !== null && $tik >= $k['start']) {
            $k['stan'] = 'w_locie';
            if ($k['rozkaz'] === 'powrot') $k['rozkaz'] = 'ochrona';
            $mac = bitwa_macierzysta($S, $k);
            if ($mac !== null) { $k['x'] = $S['jednostki'][$mac]['x']; $k['y'] = $S['jednostki'][$mac]['y']; }
            $S['zdarzenia'][] = ['tik' => $tik, 'rodzaj' => 'start', 'opis' => "{$k['strona']}: klucz {$k['nr']} ({$k['typ']}) startuje", 'wartosc' => null];
        }
        if ($k['stan'] !== 'w_locie' && $k['stan'] !== 'powrot') { $S['klucze'][$ki] = $k; continue; }
        $fig = bitwa_figura($S, bitwa_kanal_klucza($k));
        $v = $masz[$k['typ']]['v'] * ($fig && $prof[$fig]['predkosc_mn'] !== null ? (float) $prof[$fig]['predkosc_mn'] : 1);
        [$celI, $promien] = bitwa_cel_klucza($S, $k);
        if ($celI !== null) {
            $cel = $S['jednostki'][$celI];
            $d = bitwa_odleglosc($k, $cel);
            if ($k['stan'] === 'powrot' && $d <= BITWA_STALE['ladowanie_km']) {
                $k['stan'] = 'hangar';
                $k['start'] = null;
                $k['x'] = $cel['x'];
                $k['y'] = $cel['y'];
                $S['zdarzenia'][] = ['tik' => $tik, 'rodzaj' => 'ladowanie', 'opis' => "{$k['strona']}: klucz {$k['nr']} ({$k['typ']}) ląduje w hangarze", 'wartosc' => null];
                $S['klucze'][$ki] = $k;
                continue;
            }
            $kursDo = bitwa_stopnie_kat(atan2($cel['x'] - $k['x'], $cel['y'] - $k['y']));
            $k['kurs'] = $d > $promien ? $kursDo : $kursDo + 90;
        }
        if ($fig === 'petla' || $fig === 'split_s') $k['kurs'] += 180 / max(1, (int) $prof[$fig]['dzialanie_s']);
        $k['x'] += $v * sin(bitwa_radiany($k['kurs']));
        $k['y'] += $v * cos(bitwa_radiany($k['kurs']));
        $S['klucze'][$ki] = $k;
    }
    foreach ($S['jednostki'] as $ji => $j) {
        $S['jednostki'][$ji]['x'] += $j['v'] * sin(bitwa_radiany($j['kurs']));
        $S['jednostki'][$ji]['y'] += $j['v'] * cos(bitwa_radiany($j['kurs']));
    }
    // ogień kluczy w osłony okrętów
    foreach (array_keys($S['klucze']) as $ki) {
        $k = $S['klucze'][$ki];
        if ($k['stan'] !== 'w_locie') continue;
        $fig = bitwa_figura($S, bitwa_kanal_klucza($k));
        if ($fig && (int) $prof[$fig]['ogien_wylaczony']) continue;
        $S['klucze'][$ki]['ladunek']++;
        $m = $masz[$k['typ']];
        if ($S['klucze'][$ki]['ladunek'] < $m['ladowanie']) continue;
        $cele = [];
        foreach (bitwa_wrogowie($S, $k['strona']) as $ji) if (bitwa_odleglosc($k, $S['jednostki'][$ji]) <= BITWA_STALE['zasieg_ognia_klucza_km']) $cele[] = $ji;
        if (!$cele) continue;
        $S['klucze'][$ki]['ladunek'] = 0;
        $ji = $cele[0];
        $gi = bitwa_generator_od_strony($S['jednostki'][$ji], $k['x'], $k['y']);
        bitwa_trafienie($S, $tik, $ji, $gi, $m['energia'] * $k['maszyny'], bitwa_hz_klucza($k), "{$k['strona']} {$k['nr']}", bitwa_kanal_klucza($k));
    }
    // osłony: odpływ obciążenia, AI osłon
    foreach (array_keys($S['jednostki']) as $ji) {
        foreach (array_keys($S['jednostki'][$ji]['generatory']) as $gi) $S['jednostki'][$ji]['generatory'][$gi]['obciazenie'] *= BITWA_STALE['odplyw_obciazenia'];
        bitwa_ai_oslony($S, $tik, $ji);
    }
    foreach (array_keys($S['klucze']) as $ki) {
        if ($S['klucze'][$ki]['stan'] === 'w_locie' && $S['klucze'][$ki]['ai']) bitwa_ai_klucza($S, $tik, $ki);
    }
    $S['tik'] = $tik + 1;
}

// Liczba martwych żył (do podglądu).
function bitwa_martwe(array $z): int
{
    $ile = 0;
    for ($i = 0, $n = strlen($z['martwe']); $i < $n; $i++) $ile += substr_count(decbin(ord($z['martwe'][$i])), '1');
    return $ile;
}

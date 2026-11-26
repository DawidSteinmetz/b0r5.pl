<?php
declare(strict_types=1);

// Pętla tików bitwy — przepisana 1:1 z silnika wzorcowego narzedzia/mostek/silnik.py (krok, utworz, rozkazy, AI).
// Bez bazy danych: działa na tablicy stanu $S; zapis i odczyt robi api/bitwa.php. Zmiana tu = zmiana w silnik.py
// (i odwrotnie), a test zgodności api/?a=bitwa_test porównuje przebieg z wektorami z Pythona.
//
// $S: ziarno, tik, rozkazy (lista), po_kanale (kanał → indeksy rozkazów), aktywne (indeksy w przygotowaniu/działaniu),
//     zdarzenia (nowe w tym doliczeniu), jednostki, klucze, profile, maszyny.

const BITWA_TORPEDY = ['X_WING', 'Y_WING', 'B_WING'];
// Maszyny bez sensorów dalekiego zasięgu (jak dane/maszyny.py BEZ_SENSOROW): radar własny 5 km, dalej dane okrętu matki (60 km).
const BITWA_BEZ_SENSOROW = ['TIE_LN', 'TIE_IN', 'TIE_SA'];
const BITWA_RADAR_WLASNY_KM = 5.0;
const BITWA_RADAR_MATKI_KM = 60.0;
const BITWA_UNIKI = ['beczka_l', 'beczka_p', 'zygzak', 'spirala', 'skok_cieniowy', 'split_s', 'petla', 'burst'];

const BITWA_STALE = [
    'odplyw_obciazenia' => 0.98,
    'energia_zabicia_zyly_s' => 1.0,
    'promien_trafienia' => 7,
    'zasieg_ognia_klucza_km' => 3.0,
    'dystans_ataku_km' => 2.0,
    'zasieg_sensorow_km' => 200.0,
    'dystans_walki_km' => 10.0,
    'tasma_s' => 60,
    'zasieg_walki_atak_km' => 12.0,
    'zasieg_walki_ochrona_km' => 8.0,
    'zasieg_walki_patrol_km' => 15.0,
    'ogon_km' => 0.3,
    'stozek_okret_deg' => 30.0,
    'zwrot_as' => 1.25,
    'oslona_nasycenie' => 0.25,
    'oslona_regen' => 0.01,
    'p_min' => 0.01, 'p_maks' => 0.95,
    'prog_unieszkodliwienia' => 25.0,
    'przekroj_okretu' => 0.05,
    'jon' => ['ISD_I' => [4e20, 1, 8], 'MC80' => [2e20, 3, 8]],
    'torpedy_energia' => 8.0, 'torpedy_hz' => 1.5,
    'wycofanie_km' => 60.0,
    'v_maks_okretu' => ['ISD_I' => 0.27, 'MC80' => 0.27, 'ZBIEG' => 0.3],
    'przyspieszenie_okretu' => ['ISD_I' => 0.002, 'MC80' => 0.003, 'ZBIEG' => 0.01],
    'obrot_okretu' => ['ISD_I' => 30, 'MC80' => 40],  // °/min jak profile_klas.py silniki.obrot_deg_na_min (brak = 60)
    'dowodcy' => [
        'radus' => ['dystans_km' => 6.0, 'odwrot_proc' => 15.0, 'unik' => 0.10, 'nalot' => 'zawsze'],
        'calrissian' => ['dystans_km' => 10.0, 'odwrot_proc' => 35.0, 'unik' => 0.25, 'nalot' => 'hz'],
        'ackbar' => ['dystans_km' => 18.0, 'odwrot_proc' => 55.0, 'unik' => 0.40, 'nalot' => 'ostroznie'],
    ],
    'promien_ochrony_km' => 3.0,
    'promien_patrolu_km' => 8.0,
    'promien_skanu_km' => 10.0,
    'ladowanie_km' => 0.5,
    'kadlub_j' => ['ISD_I' => 1.2e25, 'MC80' => 8e24, 'ZBIEG' => 2e22],
    'xp_akcja' => 0.5,
    'xp_mnoznik' => ['as' => 0.5, 'standard' => 1.0, 'kadet' => 1.5],
    'dryf_k' => ['kadet' => 0.25, 'standard' => 0.25, 'as' => 1 / 3],
    'zmiany_cykl' => ['kadet' => 2, 'standard' => 2, 'as' => 4],
];
const BITWA_CYKL = 12;
const BITWA_RANGI = ['kadet' => 0, 'standard' => 1, 'as' => 2];
// Figury kursu: zmiana kursu zadanego (°) — jak silnik.py ZMIANA_KURSU.
const BITWA_ZMIANA_KURSU = ['prosto' => 0, 'lagodnie_l' => -30, 'lagodnie_p' => 30, 'ostro_l' => -90, 'ostro_p' => 90, 'petla' => 180, 'split_s' => 180];

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
            'mysliwce' => (int) $p['mysliwce'], 'dlugosc_m' => (float) $p['dlugosc_m'],
            'lufy' => (int) $p['lufy_na_baterie'], 'ladowanie' => (int) $p['ladowanie_tiki'], 'moc_reaktora_w' => (float) $p['moc_reaktora_w'], 'kadlub' => [],
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
            'energia' => (float) $m['energia_strzalu_j'], 'ladowanie' => (int) $m['ladowanie_tiki'],
            'oslona_hz' => (int) $m['oslona_hz'], 'przekroj' => (float) $m['przekroj_m2'],
            'zwrot' => (float) ($m['zwrot_deg_s'] ?? 30), 'zasieg' => (float) ($m['zasieg_ognia_km'] ?? 1.5),
            'stozek' => (float) ($m['stozek_deg'] ?? 15), 'theta' => (float) ($m['theta_mrad'] ?? 4)];
    }
    $pd = $tl = $jon = [];
    try {
        foreach ($db->query("SELECT * FROM profile_klas_uzbrojenie WHERE rodzaj IN ('pd','turbolaser','jon') ORDER BY profil_kod, nr")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $w = [(int) $u['nr'], (float) $u['x'], (float) $u['y'], (float) $u['luk_srodek_deg'], (float) $u['luk_pol_deg'],
                (float) $u['zasieg_km'], (float) $u['theta_mrad'], (int) $u['hz']];
            if ($u['rodzaj'] === 'pd') $pd[$u['profil_kod']][] = $w;
            elseif ($u['rodzaj'] === 'turbolaser') $tl[$u['profil_kod']][] = $w;
            else $jon[$u['profil_kod']][] = $w;
        }
    } catch (Throwable $e) { $pd = $tl = $jon = []; }
    return $s = ['profile' => $profile, 'maszyny' => $maszyny, 'pd' => $pd, 'tl' => $tl, 'jon' => $jon,
        'e_zyly' => (float) ($stale['energia_zyly_lufy_j'] ?? 8.4e20 / 3600),
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
            'generatory' => $gen, 'sekcje' => array_fill_keys(array_keys($p['kadlub']), 100.0),
            'stan' => (int) $j['wejscie_s'] + bitwa_rozrzut($ziarno, (int) $j['wejscie_rozrzut_s'], 'j', $nr) > 0 ? 'oczekuje' : 'w_bitwie',
            'obiekt_id' => $j['obiekt_id'] ?? null, 'ai' => false, 'kurs_cel' => null, 'v_cel' => null, 'cel_ognia' => null,
            'nastawy' => [], 'cieplo' => 0.0, 'dlawienie' => false];
    }
    $strony = [];
    foreach ($sc['strony'] as $s) $strony[$s['strona']] = $s;
    foreach ($S['jednostki'] as $ji => $j) $S['jednostki'][$ji]['ai'] = ($strony[$j['strona']]['dowodzenie'] ?? 'gracze') !== 'gracze';
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
                'ai' => $st['dowodzenie'] !== 'gracze', 'oslona_pkt' => 0.0, 'kurs_zadany' => null];
        }
    }
    $S['dowodcy'] = array_map(static fn($s) => $s['dowodzenie'], $strony);
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
function bitwa_norm180(float $a): float
{
    $r = fmod($a + 180, 360);
    if ($r < 0) $r += 360;
    return $r - 180;
}

function bitwa_namiar(array $a, array $b): float
{
    return bitwa_stopnie_kat(atan2($b['x'] - $a['x'], $b['y'] - $a['y']));
}

// Indeks najbliższego klucza (z listy indeksów) do punktu ref w promieniu rMax — przy remisie pierwszy (jak silnik.py).
function bitwa_najblizszy_klucz(array $S, array $indeksy, array $ref, float $rMax): ?int
{
    $naj = null;
    $dn = null;
    foreach ($indeksy as $i) {
        $d = bitwa_odleglosc($ref, $S['klucze'][$i]);
        if ($d <= $rMax && ($dn === null || $d < $dn)) { $naj = $i; $dn = $d; }
    }
    return $naj;
}

function bitwa_wrogie_klucze(array $S, string $strona): array
{
    $w = [];
    foreach ($S['klucze'] as $i => $k) if ($k['strona'] !== $strona && $k['stan'] === 'w_locie') $w[] = $i;
    return $w;
}

// Czy klucz zna położenie obiektu (jak silnik.py widzi).
function bitwa_widzi(array $S, array $k, array $o): bool
{
    if (!in_array($k['typ'], BITWA_BEZ_SENSOROW, true) || bitwa_odleglosc($k, $o) <= BITWA_RADAR_WLASNY_KM) return true;
    $mac = bitwa_macierzysta($S, $k);
    return $mac !== null && bitwa_odleglosc($S['jednostki'][$mac], $o) <= BITWA_RADAR_MATKI_KM;
}

// Cel klucza z rozkazu (jak silnik.py cel_klucza): ['k'|'j', indeks, promień km, rodzaj] albo [null, null, 0.0, null].
function bitwa_cel_klucza(array $S, array $k): array
{
    $mac = bitwa_macierzysta($S, $k);
    $r = $k['rozkaz'];
    if ($r === 'powrot' && $mac !== null) return ['j', $mac, 0.0, 'powrot'];
    $wk = array_values(array_filter(bitwa_wrogie_klucze($S, $k['strona']), static fn($i) => bitwa_widzi($S, $k, $S['klucze'][$i])));
    $wjw = array_values(array_filter(bitwa_wrogowie($S, $k['strona']), static fn($i) => bitwa_widzi($S, $k, $S['jednostki'][$i])));
    if ($r === 'nalot') {
        $wj = $wjw;
        if ($wj) return ['j', bitwa_najblizsza($S, $k, $wj), BITWA_STALE['dystans_ataku_km'], 'okret'];
    }
    if ($r === 'atak' || $r === 'nalot' || $mac === null) {
        $kt = bitwa_najblizszy_klucz($S, $wk, $k, BITWA_STALE['zasieg_walki_atak_km']);
        if ($kt !== null) return ['k', $kt, BITWA_STALE['ogon_km'], 'klucz'];
        $wj = $wjw;
        if ($wj) return ['j', bitwa_najblizsza($S, $k, $wj), BITWA_STALE['dystans_ataku_km'], 'okret'];
        // nic nie widać: cel domyślny z danych wywiadu (jak silnik.py)
        $wj = bitwa_wrogowie($S, $k['strona']);
        if ($wj) return ['j', bitwa_najblizsza($S, $k, $wj), BITWA_STALE['dystans_ataku_km'], 'okret'];
        $kt = bitwa_najblizszy_klucz($S, bitwa_wrogie_klucze($S, $k['strona']), $k, 1e9);
        if ($kt !== null) return ['k', $kt, BITWA_STALE['ogon_km'], 'klucz'];
        if ($mac !== null) return ['j', $mac, BITWA_STALE['promien_ochrony_km'], 'macierzysta'];
        return [null, null, 0.0, null];
    }
    if ($r === 'skan') {
        $wj = $wjw;
        if ($wj) return ['j', bitwa_najblizsza($S, $k, $wj), BITWA_STALE['promien_skanu_km'], 'okret'];
    }
    $zasieg = $r === 'patrol' ? BITWA_STALE['zasieg_walki_patrol_km'] : BITWA_STALE['zasieg_walki_ochrona_km'];
    $kt = bitwa_najblizszy_klucz($S, $wk, $S['jednostki'][$mac], $zasieg);
    if ($kt !== null) return ['k', $kt, BITWA_STALE['ogon_km'], 'klucz'];
    return ['j', $mac, $r === 'patrol' ? BITWA_STALE['promien_patrolu_km'] : BITWA_STALE['promien_ochrony_km'], 'macierzysta'];
}

function bitwa_obiekt(array $S, array $cel): array
{
    return $cel[0] === 'k' ? $S['klucze'][$cel[1]] : $S['jednostki'][$cel[1]];
}

// MECHANIKA 4.3: p = 1 − exp(−A / (2π σ²)), σ = R · θ_eff, w granicach p_min … p_maks.
function bitwa_celnosc(float $thetaRad, float $rKm, float $przekroj): float
{
    $sigma = max(0.05, $rKm) * 1000 * $thetaRad;
    return min(BITWA_STALE['p_maks'], max(BITWA_STALE['p_min'], 1 - exp(-$przekroj / (2 * M_PI * $sigma * $sigma))));
}

// Trafienie maszyny klucza (jak silnik.py traf_maszyne): osłona przepuszcza 1 − osłona/Hz, zatrzymane nasycają osłonę.
function bitwa_traf_maszyne(array &$S, int $tik, int $ti, float $hz, string $kan, int $i, string $sprawca): void
{
    $t = &$S['klucze'][$ti];
    $osl = bitwa_slowniki()['maszyny'][$t['typ']]['oslona_hz'];
    $frac = $osl === 0 ? 1.0 : max(0.0, 1 - $osl / $hz);
    if ($frac < 1) $t['oslona_pkt'] = min(1.0, $t['oslona_pkt'] + BITWA_STALE['oslona_nasycenie'] * (1 - $frac));
    if (bitwa_los($S['ziarno'], $tik, 'zniszcz', $kan, $i) < $frac + (1 - $frac) * $t['oslona_pkt']) {
        $t['maszyny']--;
        $S['zdarzenia'][] = ['tik' => $tik, 'rodzaj' => 'zestrzelenie', 'opis' => "$sprawca zestrzeliwuje maszynę: {$t['strona']} klucz {$t['nr']} ({$t['maszyny']}/4)", 'wartosc' => $t['maszyny']];
        if ($t['maszyny'] <= 0) {
            $t['stan'] = 'stracony';
            $S['zdarzenia'][] = ['tik' => $tik, 'rodzaj' => 'klucz_stracony', 'opis' => "{$t['strona']}: klucz {$t['nr']} ({$t['typ']}) stracony", 'wartosc' => null];
        }
    }
    unset($t);
}

// Salwa klucza w klucz (jak silnik.py strzal_w_klucz): rozrzut × geometria (ogon 0,5 … przecięcie 2,5) × figury.
function bitwa_strzal_w_klucz(array &$S, int $tik, int $ki, int $ti): void
{
    $sl = bitwa_slowniki();
    $prof = bitwa_rozkazy_profile();
    $k = $S['klucze'][$ki];
    $t = $S['klucze'][$ti];
    $m = $sl['maszyny'][$k['typ']];
    $mt = $sl['maszyny'][$t['typ']];
    $ft = bitwa_figura($S, bitwa_kanal_klucza($t));
    $fk = bitwa_figura($S, bitwa_kanal_klucza($k));
    $geom = 0.5 + 2 * abs(sin(bitwa_radiany($t['kurs'] - $k['kurs'])));
    $theta = $m['theta'] / 1000 * $geom * ($ft && $prof[$ft]['theta_mn'] !== null ? (float) $prof[$ft]['theta_mn'] : 1)
        * ($fk && $prof[$fk]['theta_wlasna_mn'] !== null ? (float) $prof[$fk]['theta_wlasna_mn'] : 1);
    $p = bitwa_celnosc($theta, bitwa_odleglosc($k, $t), $mt['przekroj']);
    $kan = bitwa_kanal_klucza($k);
    for ($i = 0; $i < $k['maszyny']; $i++) {
        if ($S['klucze'][$ti]['maszyny'] <= 0) break;
        if (bitwa_los($S['ziarno'], $tik, 'strzal', $kan, $i) < $p) bitwa_traf_maszyne($S, $tik, $ti, bitwa_hz_klucza($k), $kan, $i, "{$k['strona']} {$k['nr']}");
    }
}

// Położenie punktu kadłuba (x, y w długościach okrętu, dziób −y) w polu bitwy — jak silnik.py punkt_na_kadlubie.
function bitwa_punkt_na_kadlubie(array $j, float $x, float $y): array
{
    $dl = bitwa_slowniki()['profile'][$j['profil']]['dlugosc_m'] / 1000;
    $s = sin(bitwa_radiany($j['kurs']));
    $c = cos(bitwa_radiany($j['kurs']));
    return ['x' => $j['x'] + $s * (-$y) * $dl + $c * $x * $dl, 'y' => $j['y'] + $c * (-$y) * $dl - $s * $x * $dl];
}

// Najbliższy wrogi okręt w zasięgu i łuku baterii: [indeks|null, odległość|null].
function bitwa_cel_baterii(array $S, array $j, array $dzialo, float $lukS, float $lukP, float $zasieg): array
{
    $cel = null;
    $dn = null;
    foreach (bitwa_wrogowie($S, $j['strona']) as $wi) {
        $w = $S['jednostki'][$wi];
        $d = bitwa_odleglosc($dzialo, $w);
        if ($d > $zasieg) continue;
        if (abs(bitwa_norm180(bitwa_norm180(bitwa_namiar($dzialo, $w) - $j['kurs']) - $lukS)) > $lukP) continue;
        if (($j['cel_ognia'] ?? null) !== null && $w['nr'] === $j['cel_ognia']) return [$wi, $d];
        if ($dn !== null && $d >= $dn) continue;
        $cel = $wi;
        $dn = $d;
    }
    return [$cel, $dn];
}

// Baterie okrętu (jak silnik.py ogien_okretu): turbolasery przez filtr osłon celu, jony obciążają generator celu.
function bitwa_ogien_okretu(array &$S, int $tik, int $ji): void
{
    $sl = bitwa_slowniki();
    $j = $S['jednostki'][$ji];
    $p = $sl['profile'][$j['profil']];
    $n = static fn(string $k): float => bitwa_nast($j, $k);
    foreach ($sl['tl'][$j['profil']] ?? [] as [$nr, $x, $y, $lukS, $lukP, $zasieg, $theta, $hz]) {
        $bb = $x < 0 ? 'l' : 'p';
        $tryb = $n('tryb_' . $bb);
        $rate = bitwa_zas($j, 'tl_' . $bb) * bitwa_tempo_ognia($j, $bb) * (bitwa_wzm($S, $j, 'ogien_' . $bb) ? 1.25 : 1.0) * ($tryb >= 0.75 ? 1.5 : 1.0);
        $interwal = max(2, bitwa_zaokr(($p['ladowanie'] ?: 6) / max(0.05, $rate)));
        $faza = $tik + $nr + ($bb === 'p' ? bitwa_zaokr($n('opoznienie') * $interwal) : 0);
        if ($faza % $interwal) continue;
        $dzialo = bitwa_punkt_na_kadlubie($j, $x, $y);
        [$ci, $dn] = bitwa_cel_baterii($S, $j, $dzialo, $lukS, $lukP, $zasieg);
        if ($ci === null) continue;
        $gi = bitwa_generator_od_strony($S['jednostki'][$ci], $dzialo['x'], $dzialo['y']);
        $hzB = bitwa_stopien_baterii($hz, $n('tl_hz_max_' . $bb));
        if ($n('sync_tl_sens') >= 0.5) {
            $hzO = $S['jednostki'][$ci]['generatory'][$gi]['hz_osiagane'];
            $hzB = max(bitwa_stopien_baterii($hz, $n('tl_hz_min_' . $bb)), min($hzB, bitwa_stopien_baterii($hz, min(1.0, 1.5 * $hzO / $hz))));
        }
        $r = $n('rozrzut_' . $bb);
        $th = $theta * (0.5 + 0.5 * $hzB / $hz) * ($S['jednostki'][$ci]['v'] <= 0.05 ? (0.5 + $r) : (1.5 - $r)) * (1 + abs($n('elewacja') - 0.5));
        if ($n('sync_tl_ko_' . $bb) >= 0.5) $th *= 0.85;
        if ($n('sync_ko_sens') >= 0.5) $th *= 0.9;
        if (bitwa_nast($S['jednostki'][$ci], 'zaklocanie') >= 0.5) $th *= 1.25;
        $pojedynczy = $tryb >= 0.25 && $tryb < 0.75;
        if ($pojedynczy) $th *= 0.7;
        if ($tryb >= 0.75) $th *= 1.5;
        $pc = bitwa_celnosc($th / 1000, $dn, $sl['profile'][$S['jednostki'][$ci]['profil']]['dlugosc_m'] ** 2 * BITWA_STALE['przekroj_okretu']);
        $kan = "tl:{$j['nr']}:$nr";
        $lufy = $pojedynczy ? max(1, intdiv($p['lufy'], 4)) : $p['lufy'];
        $trafienia = 0;
        for ($i = 0; $i < $lufy; $i++) if (bitwa_los($S['ziarno'], $tik, 'tl', $kan, $i) < $pc) $trafienia++;
        if ($trafienia) {
            $mn = (bitwa_wzm($S, $j, 'tl_' . $bb) ? 1.25 : 1.0) * (bitwa_wzm($S, $j, 'salwa') ? 1.15 : 1.0) * ($n('sync_tl_os_' . $bb) >= 0.5 ? 1.1 : 1.0);
            bitwa_trafienie($S, $tik, $ci, $gi, $trafienia * $sl['e_zyly'] * $hzB * $mn, (float) $hzB, "{$j['nazwa']} TL $nr", $kan, $dzialo['x'], $dzialo['y']);
        }
    }
    [$energia, $lufy, $ladowanie] = BITWA_STALE['jon'][$j['profil']] ?? [0, 0, 8];
    foreach ($sl['jon'][$j['profil']] ?? [] as [$nr, $x, $y, $lukS, $lukP, $zasieg, $theta, $hz]) {
        if (($tik + $nr) % max(2, bitwa_zaokr($ladowanie / max(0.05, bitwa_zas($j, 'jon') * (bitwa_wzm($S, $j, 'jony') ? 1.5 : 1.0))))) continue;
        $dzialo = bitwa_punkt_na_kadlubie($j, $x, $y);
        [$ci, $dn] = bitwa_cel_baterii($S, $j, $dzialo, $lukS, $lukP, $zasieg);
        if ($ci === null) continue;
        $pc = bitwa_celnosc($theta / 1000, $dn, $sl['profile'][$S['jednostki'][$ci]['profil']]['dlugosc_m'] ** 2 * BITWA_STALE['przekroj_okretu']);
        $kan = "jon:{$j['nr']}:$nr";
        $trafienia = 0;
        for ($i = 0; $i < $lufy; $i++) if (bitwa_los($S['ziarno'], $tik, 'jon', $kan, $i) < $pc) $trafienia++;
        if ($trafienia && $n('jon_obezwl') >= 0.5) {
            $S['jednostki'][$ci]['cieplo'] = min(150.0, $S['jednostki'][$ci]['cieplo'] + $trafienia * 3 * (0.5 + $n('jon_moc')));
        } elseif ($trafienia) {
            $gi = bitwa_generator_od_strony($S['jednostki'][$ci], $dzialo['x'], $dzialo['y']);
            $g = &$S['jednostki'][$ci]['generatory'][$gi];
            $g['obciazenie'] += $trafienia * $energia * (0.5 + $n('jon_moc'));
            $c = bitwa_pojemnosc($sl['profile'][$S['jednostki'][$ci]['profil']]) * bitwa_mnoznik_oslony($S['jednostki'][$ci], $gi);
            if ($g['obciazenie'] > $c) {
                $g['rykoszet'] += $g['obciazenie'] - $c;
                $g['obciazenie'] = $c;
            }
            unset($g);
        }
    }
}

// Obrona punktowa okrętu (jak silnik.py obrona_punktowa): każde działko raz na tik w najbliższy klucz w zasięgu i łuku.
function bitwa_obrona_punktowa(array &$S, int $tik, int $ji): void
{
    $sl = bitwa_slowniki();
    $prof = bitwa_rozkazy_profile();
    $j = $S['jednostki'][$ji];
    $dl = $sl['profile'][$j['profil']]['dlugosc_m'] / 1000;
    $s = sin(bitwa_radiany($j['kurs']));
    $c = cos(bitwa_radiany($j['kurs']));
    foreach ($sl['pd'][$j['profil']] ?? [] as [$nr, $x, $y, $lukS, $lukP, $zasieg, $theta, $hz]) {
        $g = bitwa_grupa_pd($nr, $x, $y);
        $kat = 0.5 + bitwa_nast($j, 'pd_kat_' . $g);
        $lukP = $lukP * $kat;
        $theta = $theta * $kat;
        if (bitwa_nast($j, 'sync_tl_iff_' . ($x < 0 ? 'l' : 'p')) >= 0.5) $theta *= 0.8;
        if (bitwa_nast($j, 'sync_ko_iff') >= 0.5) $theta *= 0.9;
        if (bitwa_nast($j, 'sync_radar_ko') >= 0.5) $theta *= 0.9;
        if (bitwa_nast($j, 'sync_radar_iff') >= 0.5) $theta *= 0.95;
        if (bitwa_wzm($S, $j, 'pd_' . $g)) $theta /= 1.5;
        $dzialko = ['x' => $j['x'] + $s * (-$y) * $dl + $c * $x * $dl, 'y' => $j['y'] + $c * (-$y) * $dl - $s * $x * $dl];
        $cel = null;
        $dn = null;
        foreach (bitwa_wrogie_klucze($S, $j['strona']) as $ti) {
            $d = bitwa_odleglosc($dzialko, $S['klucze'][$ti]);
            if ($d > $zasieg || ($dn !== null && $d >= $dn)) continue;
            if (abs(bitwa_norm180(bitwa_norm180(bitwa_namiar($dzialko, $S['klucze'][$ti]) - $j['kurs']) - $lukS)) > $lukP) continue;
            $cel = $ti;
            $dn = $d;
        }
        if ($cel === null) continue;
        $ft = bitwa_figura($S, bitwa_kanal_klucza($S['klucze'][$cel]));
        $p = bitwa_celnosc($theta / 1000 * ($ft && $prof[$ft]['theta_mn'] !== null ? (float) $prof[$ft]['theta_mn'] : 1), $dn,
            $sl['maszyny'][$S['klucze'][$cel]['typ']]['przekroj']);
        $kan = "pd:{$j['nr']}:$nr";
        if (bitwa_los($S['ziarno'], $tik, 'pd', $kan) < $p) bitwa_traf_maszyne($S, $tik, $cel, (float) $hz, $kan, 0, "{$j['nazwa']} PD");
    }
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

// ------------------------------------------------------------------------------------------------------- zasilanie
// Nastawy konsol Osłony i Reaktory i bilans mocy — port 1:1 silnik.py (MECHANIKA 11.4): NASTAWY, zasilanie,
// sektor_trafienia, mnoznik_oslony. Domyślne nastawy nie zmieniają przebiegu bitwy.
const BITWA_SEKTORY = ['dziob', 'gora_l', 'gora_p', 'dol_l', 'dol_p', 'rufa', 'hangar'];
const BITWA_GRUPY_PD = ['dziob', 'przod_l', 'przod_p', 'bok_l', 'bok_p', 'dol', 'rufa'];
const BITWA_WZM_BRONI = ['tl_l', 'tl_p', 'ogien_l', 'ogien_p', 'salwa', 'jony', 'pd_dziob', 'pd_przod_l', 'pd_przod_p', 'pd_bok_l', 'pd_bok_p', 'pd_dol', 'pd_rufa'];
const BITWA_WZM_SILNIKOW = ['gl_l', 'gl_p', 'gl_s', 'man_lg', 'man_pg', 'man_ld', 'man_pd', 'man_all', 'skret_l', 'skret_p'];
const BITWA_WZM_SENSOROW = ['radar_tx', 'radar_rx', 'skan_nad', 'skan_rozdz'];
const BITWA_WZMOCNIENIA = ['dziob', 'gora_l', 'gora_p', 'dol_l', 'dol_p', 'rufa', 'hangar', 'glowny', 'generator', 'uzbr', 'przedni', 'zapas', 'burta_l', 'burta_p',
    'tl_l', 'tl_p', 'ogien_l', 'ogien_p', 'salwa', 'jony', 'pd_dziob', 'pd_przod_l', 'pd_przod_p', 'pd_bok_l', 'pd_bok_p', 'pd_dol', 'pd_rufa',
    'gl_l', 'gl_p', 'gl_s', 'man_lg', 'man_pg', 'man_ld', 'man_pd', 'man_all', 'skret_l', 'skret_p', 'radar_tx', 'radar_rx', 'skan_nad', 'skan_rozdz'];
const BITWA_SYNC_OSLON = ['sync_os_tl_l', 'sync_os_tl_p', 'sync_os_pr_l', 'sync_os_pr_p', 'sync_rufa_sg_l', 'sync_rufa_sg_p', 'sync_rufa_sm_l', 'sync_rufa_sm_p'];
const BITWA_NASTAWY = [
    'pokr_dziob' => 1.0, 'pokr_gora_l' => 1.0, 'pokr_gora_p' => 1.0, 'pokr_dol_l' => 1.0, 'pokr_dol_p' => 1.0, 'pokr_rufa' => 1.0, 'pokr_hangar' => 1.0,
    'sync_os_tl_l' => 0.0, 'sync_os_tl_p' => 0.0, 'sync_os_pr_l' => 0.0, 'sync_os_pr_p' => 0.0,
    'sync_rufa_sg_l' => 0.0, 'sync_rufa_sg_p' => 0.0, 'sync_rufa_sm_l' => 0.0, 'sync_rufa_sm_p' => 0.0,
    'przydz_tl' => 0.5, 'przydz_jon' => 0.5, 'przydz_sil' => 0.5, 'przydz_os' => 1.0, 'przydz_hiper' => 0.0,
    'moc_przedni' => 0.5, 'moc_zapas' => 0.5, 'czest_gen' => 0.5, 'napiecie_gen' => 0.5, 'gestosc' => 0.5, 'pole_komory' => 0.5,
    'czest_bazowa' => 0.5, 'rez_salwa' => 0.0, 'rez_awaryjna' => 0.0, 'uzbr_min' => 0.0, 'uzbr_max' => 1.0, 'gen_min' => 0.0, 'gen_max' => 1.0,
    'sync_przedni_pr' => 0.0, 'sync_zapas_sil' => 0.0, 'sync_uzbr_tl_l' => 0.0, 'sync_uzbr_tl_p' => 0.0, 'sync_gen_sil' => 0.0, 'sync_gen_os' => 0.0,
    'sync_uzbr_glowny' => 0.0, 'sync_glowny_pom' => 0.0, 'sync_glowny_gen' => 0.0, 'sync_gen_glowny' => 0.0,
    'przel_sil_zapas' => 0.0, 'przel_podst_przedni' => 0.0,
    'tl_hz_max_l' => 1.0, 'tl_hz_min_l' => 0.0, 'tl_hz_max_p' => 1.0, 'tl_hz_min_p' => 0.0,
    'pd_kat_dziob' => 0.5, 'pd_kat_przod_l' => 0.5, 'pd_kat_przod_p' => 0.5, 'pd_kat_bok_l' => 0.5, 'pd_kat_bok_p' => 0.5, 'pd_kat_dol' => 0.5, 'pd_kat_rufa' => 0.5,
    'sync_tl_iff_l' => 0.0, 'sync_tl_iff_p' => 0.0, 'sync_tl_os_l' => 0.0, 'sync_tl_os_p' => 0.0, 'sync_tl_ko_l' => 0.0, 'sync_tl_ko_p' => 0.0,
    'sync_tl_sens' => 0.0,
    'tempo_l' => 0.5, 'tempo_min_l' => 0.0, 'tempo_max_l' => 1.0, 'tempo_p' => 0.5, 'tempo_min_p' => 0.0, 'tempo_max_p' => 1.0,
    'rozrzut_l' => 0.5, 'rozrzut_p' => 0.5, 'tryb_l' => 0.0, 'tryb_p' => 0.0, 'czas_zapor' => 0.5, 'opoznienie' => 0.0, 'elewacja' => 0.5,
    'jon_moc' => 0.5, 'jon_obezwl' => 0.0, 'sync_ko_iff' => 0.0, 'sync_ko_sens' => 0.0,
    'zapor_do_l' => 0.0, 'zapor_do_p' => 0.0,
    'sil_max_l' => 1.0, 'sil_min_l' => 0.0, 'sil_max_p' => 1.0, 'sil_min_p' => 0.0, 'sil_srodek' => 1.0,
    'man_lg' => 0.5, 'man_pg' => 0.5, 'man_ld' => 0.5, 'man_pd' => 0.5, 'dysza_lg' => 0.5, 'dysza_pg' => 0.5, 'dysza_ld' => 0.5, 'dysza_pd' => 0.5,
    'trym_poch' => 0.5, 'trym_odch' => 0.5, 'sync_man_l' => 0.0, 'sync_man_p' => 0.0, 'sync_gl_l_s' => 0.0, 'sync_gl_p_s' => 0.0,
    'skret_max_l' => 1.0, 'skret_min_l' => 0.0, 'skret_max_p' => 1.0, 'skret_min_p' => 0.0, 'nav_man_l' => 0.5, 'nav_man_p' => 0.5,
    'katdysz_l' => 0.5, 'katdysz_p' => 0.5, 'przechyl' => 0.5, 'sync_nav_l' => 0.0, 'sync_nav_p' => 0.0,
    'radar_aktywny' => 1.0, 'sens_aktywny' => 1.0, 'zaklocanie' => 0.0, 'radar_moc' => 0.5, 'sens_nad' => 0.5,
    'sync_radar_ko' => 0.0, 'sync_radar_iff' => 0.0, 'sync_sens_nav' => 0.0,
    'radar_zasieg_max' => 1.0, 'radar_zasieg_min' => 0.0, 'radar_rx' => 0.5, 'radar_czul_max' => 1.0, 'radar_czul_min' => 0.5,
    'prog_zblizenia' => 0.3, 'prog_kolizji' => 0.3333, 'radar_tempo' => 0.3333, 'radar_tlumienie' => 0.0, 'radar_zrodlo' => 0.0,
    'sens_pasmo' => 0.5, 'sens_hz_max' => 1.0, 'sens_hz_min' => 0.0, 'sens_integr' => 0.5, 'sens_probki' => 0.5, 'skan_min' => 0.0, 'skan_poziom' => 0.0,
];
const BITWA_GRUPY_WYROWNANIA = [
    'baterie' => ['przydz_tl', 'przydz_jon', 'rez_salwa'],
    'reaktory' => ['moc_przedni', 'moc_zapas', 'uzbr_min', 'uzbr_max', 'gen_min', 'gen_max'],
    'dystrybucja' => ['przydz_tl', 'przydz_jon', 'przydz_sil', 'przydz_os', 'przydz_hiper', 'rez_salwa', 'rez_awaryjna'],
    'oslony' => ['pokr_dziob', 'pokr_gora_l', 'pokr_gora_p', 'pokr_dol_l', 'pokr_dol_p', 'pokr_rufa', 'pokr_hangar'],
    'turbolasery' => ['tl_hz_max_l', 'tl_hz_min_l', 'tl_hz_max_p', 'tl_hz_min_p', 'pd_kat_dziob', 'pd_kat_przod_l', 'pd_kat_przod_p', 'pd_kat_bok_l',
        'pd_kat_bok_p', 'pd_kat_dol', 'pd_kat_rufa'],
    'ogien' => ['tempo_l', 'tempo_min_l', 'tempo_max_l', 'tempo_p', 'tempo_min_p', 'tempo_max_p', 'rozrzut_l', 'rozrzut_p', 'opoznienie', 'elewacja'],
    'silniki' => ['sil_max_l', 'sil_min_l', 'sil_max_p', 'sil_min_p', 'sil_srodek', 'man_lg', 'man_pg', 'man_ld', 'man_pd',
        'dysza_lg', 'dysza_pg', 'dysza_ld', 'dysza_pd', 'trym_poch', 'trym_odch'],
    'nawigacja' => ['skret_max_l', 'skret_min_l', 'skret_max_p', 'skret_min_p', 'nav_man_l', 'nav_man_p', 'katdysz_l', 'katdysz_p', 'przechyl'],
    'sensory' => ['sens_pasmo', 'sens_hz_max', 'sens_hz_min', 'sens_integr', 'sens_probki', 'sens_nad', 'skan_min'],
];

// Mnożnik prędkości kątowej skrętu w stronę $b (jak silnik.py mnoznik_skretu).
function bitwa_mnoznik_skretu(array $S, array $j, string $b): float
{
    $n = static fn(string $k): float => bitwa_nast($j, $k);
    $f = (0.5 + ($n('man_' . $b . 'g') + $n('man_' . $b . 'd')) / 2) * (0.5 + $n('nav_man_' . $b));
    $f = min(max($f, $n('skret_min_' . $b)), $n('skret_max_' . $b));
    if (bitwa_wzm($S, $j, 'skret_' . $b)) $f *= 1.5;
    if (bitwa_wzm($S, $j, 'man_' . $b . 'g')) $f *= 1.25;
    if (bitwa_wzm($S, $j, 'man_' . $b . 'd')) $f *= 1.25;
    if (bitwa_wzm($S, $j, 'man_all')) $f *= 1.3;
    if ($n('sync_man_' . $b) >= 0.5) $f *= 1.1;
    if ($n('sync_nav_' . $b) >= 0.5) $f *= 1.1;
    $pr = $n('przechyl');
    if ($pr < 0.25) $f *= $b === 'l' ? 1.15 : 0.9;
    elseif ($pr > 0.75) $f *= $b === 'p' ? 1.15 : 0.9;
    return $f;
}

function bitwa_grupa_pd(int $nr, float $x, float $y): string
{
    $b = $x < 0 ? 'l' : 'p';
    if ($y < -0.38) return 'dziob';
    if ($y < -0.1) return 'przod_' . $b;
    if ($y < 0.25) return $nr % 2 === 0 ? 'dol' : 'bok_' . $b;
    return 'rufa';
}

function bitwa_tempo_ognia(array $j, string $b): float
{
    return 0.5 + min(max(bitwa_nast($j, 'tempo_' . $b), bitwa_nast($j, 'tempo_min_' . $b)), bitwa_nast($j, 'tempo_max_' . $b));
}

function bitwa_stopien_baterii(int $n, float $frac): int
{
    return bitwa_stopien_w_gore($n, max(1.0, $frac * $n)) ?? $n;
}

function bitwa_nast(array $j, string $k): float
{
    return (float) ($j['nastawy'][$k] ?? BITWA_NASTAWY[$k]);
}

function bitwa_wzm(array $S, array $j, string $cel): float
{
    return isset($S['_dziala']["{$j['nr']}:wzm_$cel"]) ? 1.0 : 0.0;
}

function bitwa_zas(array $j, string $k): float
{
    return (float) ($j['zasil'][$k] ?? 1.0);
}

function bitwa_koszt_sensorow_silnik(int $km): float
{
    return floor(0.245 * ($km / 1000) ** 1.618 * 100 + 0.5) / 100;
}

function bitwa_zasilanie(array &$S, int $tik, int $ji): void
{
    foreach (['l', 'p'] as $b) {
        $jj = $S['jednostki'][$ji];
        if (bitwa_nast($jj, 'tryb_' . $b) >= 0.75 && $tik >= bitwa_nast($jj, 'zapor_do_' . $b)) {
            $S['jednostki'][$ji]['nastawy']['tryb_' . $b] = 0.0;
            $S['zdarzenia'][] = ['tik' => $tik, 'rodzaj' => 'zaporowy', 'opis' => "{$jj['nazwa']}: koniec ognia zaporowego (" . ($b === 'l' ? 'lewa' : 'prawa') . ' bateria)', 'wartosc' => null];
        }
    }
    $j = $S['jednostki'][$ji];
    $p = bitwa_slowniki()['profile'][$j['profil']];
    $n = static fn(string $k): float => bitwa_nast($j, $k);
    $w = static fn(string $c): float => bitwa_wzm($S, $j, $c);
    $spraw = 1 - 0.2 * (abs($n('czest_gen') - 0.5) + abs($n('napiecie_gen') - 0.5)) + 0.03 * $n('sync_glowny_gen') + 0.03 * $n('sync_gen_glowny');
    $glowny = 100 * (0.8 + 0.4 * $n('gestosc')) * (1 + 0.15 * $w('glowny')) * (1 + 0.1 * $w('generator')) * $spraw * ($j['dlawienie'] ? 0.7 : 1.0);
    $uzbr = 15 * (1 + 0.3 * $w('uzbr'));
    $przedni = 20 * $n('moc_przedni') * (1 + 0.3 * $w('przedni'));
    $zapas = 20 * $n('moc_zapas') * (1 + 0.3 * $w('zapas'));
    $os = 0.0;
    foreach ($j['generatory'] as $g) $os += $g['hz_osiagane'] * bitwa_moc_zyly($p);
    $os = $j['generatory'] ? 100 * $os / $p['moc_reaktora_w'] : 0.0;
    foreach (BITWA_SEKTORY as $s) $os += 4 * $w($s) + 2 * $n('pokr_' . $s);
    foreach (BITWA_SYNC_OSLON as $k) $os += $n($k);
    $osDozw = min($os, 100 * $n('przydz_os'));
    $tl = 20 * $n('przydz_tl') * ((bitwa_tempo_ognia($j, 'l') + bitwa_tempo_ognia($j, 'p')) / 2) + 5 * ($w('burta_l') + $w('burta_p'));
    foreach (BITWA_WZM_BRONI as $cw) $tl += 4 * $w($cw);
    $tl += 2 * ($n('sync_tl_os_l') + $n('sync_tl_os_p'));
    $jon = 10 * $n('przydz_jon') * (0.5 + $n('jon_moc'));
    $sil = 15 * $n('przydz_sil');
    foreach (BITWA_WZM_SILNIKOW as $cw) $sil += 3 * $w($cw);
    $podst = bitwa_koszt_sensorow_silnik((int) ($j['zasieg_sensorow'] ?? 1000)) + 10 * $n('przydz_hiper') + 3 * $n('sync_przedni_pr') + 4 * $n('pole_komory');
    $podst += 4 * ($n('radar_moc') - 0.5) + 4 * ($n('sens_nad') - 0.5) - 2 * (1 - $n('radar_aktywny')) - 2 * (1 - $n('sens_aktywny')) + 6 * $n('zaklocanie');
    foreach (BITWA_WZM_SENSOROW as $cw) $podst += 2 * $w($cw);
    $podst = max(0.0, $podst);
    $przelSil = $n('przel_sil_zapas') >= 0.5;
    $przelPodst = $n('przel_podst_przedni') >= 0.5;
    $bron = $tl + $jon;
    $zUzbr = max(min($bron, $uzbr * $n('uzbr_max')), $uzbr * $n('uzbr_min'));
    $zUzbrBron = min($zUzbr, $bron);
    $c = $j['generatory'] ? bitwa_pojemnosc($p) : 0.0;
    $alarm = false;
    foreach ($j['generatory'] as $g) if ($g['obciazenie'] > 0.9 * $c) $alarm = true;
    $pula = $glowny * $n('gen_max') - ($alarm ? 0.0 : 20 * $n('rez_awaryjna'));
    if (!$przelPodst) $pula += $przedni;
    if (!$przelSil && $n('sync_zapas_sil') >= 0.5) $pula += 0.5 * $zapas;
    if ($n('sync_uzbr_glowny') >= 0.5) $pula += $zUzbr - $zUzbrBron;
    $pula = max(0.0, $pula);
    $pop = ['os' => $osDozw, 'bron' => $bron - $zUzbrBron, 'sil' => $przelSil ? 0.0 : $sil, 'podst' => $przelPodst ? 0.0 : $podst];
    $dost = ['os' => 0.0, 'bron' => 0.0, 'sil' => 0.0, 'podst' => 0.0];
    $reszta = $pula;
    $pierwsze = [];
    foreach ([['os', 'sync_gen_os'], ['sil', 'sync_gen_sil']] as [$k, $s]) if ($n($s) >= 0.5) $pierwsze[] = $k;
    foreach ($pierwsze as $k) {
        $d = min($pop[$k], $reszta);
        $dost[$k] = $d;
        $reszta -= $d;
    }
    $d = min($pop['bron'], 10 * $n('rez_salwa'), $reszta);
    $dost['bron'] += $d;
    $reszta -= $d;
    $pozostale = array_values(array_filter(['os', 'bron', 'sil', 'podst'], static fn($k) => !in_array($k, $pierwsze, true)));
    $potrz = 0.0;
    foreach ($pozostale as $k) $potrz += $pop[$k] - $dost[$k];
    if ($potrz <= $reszta) {
        foreach ($pozostale as $k) $dost[$k] = $pop[$k];
    } else {
        $f = $reszta / $potrz;
        foreach ($pozostale as $k) $dost[$k] += ($pop[$k] - $dost[$k]) * $f;
    }
    $frac = static fn(string $k): float => $pop[$k] > 0 ? $dost[$k] / $pop[$k] : 1.0;
    $fOs = $frac('os') * ($os > 0 ? $osDozw / $os : 1.0);
    $fBron = $bron > 0 ? ($zUzbrBron + $dost['bron']) / $bron : 1.0;
    $fSil = $przelSil ? ($sil > 0 ? min(1.0, $zapas / $sil) : 1.0) : $frac('sil');
    $fPodst = $przelPodst ? ($podst > 0 ? min(1.0, $przedni / $podst) : 1.0) : $frac('podst');
    $zas = ['os' => $fOs, 'bron' => $fBron, 'sil' => $fSil, 'podst' => $fPodst, 'jon' => 10 * $n('przydz_jon') / 5 * $fBron,
        'v' => min(1.2, 15 * $n('przydz_sil') / 7.5 * $fSil),
        'zrodla' => $pula, 'zapotrzebowanie' => $pop['os'] + $pop['bron'] + $pop['sil'] + $pop['podst'],
        'uzyte' => $dost['os'] + $dost['bron'] + $dost['sil'] + $dost['podst'],
        'pobor_os' => $dost['os'], 'pobor_bron' => $dost['bron'] + $zUzbrBron, 'pobor_sil' => $dost['sil'], 'pobor_podst' => $dost['podst']];
    foreach (['l', 'p'] as $b) {
        $zas['tl_' . $b] = 20 * $n('przydz_tl') / 10 * ($n('sync_uzbr_tl_' . $b) >= 0.5 ? 1.0 : $fBron) * (1 + 0.1 * $w('burta_' . $b));
        $zas['oslona_' . $b] = $fOs * (1 + 0.08 * ($n('sync_os_tl_' . $b) + $n('sync_os_pr_' . $b))) * (1 + 0.1 * $w('burta_' . $b));
    }
    $S['jednostki'][$ji]['zasil'] = $zas;
    $wyk = $pula > 0 ? max($n('gen_min'), min(1.0, $zas['uzyte'] / $pula)) : 1.0;
    $przyrost = ($wyk - 0.85) * 2 + 0.5 * $w('glowny') + 0.3 * $w('generator') + 0.2 * ($w('uzbr') + $w('przedni') + $w('zapas'));
    foreach (BITWA_SEKTORY as $s) $przyrost += 0.2 * $w($s);
    foreach (BITWA_WZM_BRONI as $cw) $przyrost += 0.2 * $w($cw);
    foreach (BITWA_WZM_SILNIKOW as $cw) $przyrost += 0.2 * $w($cw);
    foreach (BITWA_WZM_SENSOROW as $cw) $przyrost += 0.2 * $w($cw);
    $przyrost += 0.1 * $n('zaklocanie');
    foreach (['l', 'p'] as $b) if ($n('tryb_' . $b) >= 0.75) $przyrost += 0.3;
    if ($przyrost > 0) $przyrost *= (1.5 - $n('pole_komory')) * ($n('sync_glowny_pom') >= 0.5 ? 0.8 : 1.0);
    $cieplo = min(150.0, max(0.0, $j['cieplo'] + $przyrost));
    $S['jednostki'][$ji]['cieplo'] = $cieplo;
    if (!$j['dlawienie'] && $cieplo >= 100) {
        $S['jednostki'][$ji]['dlawienie'] = true;
        $S['zdarzenia'][] = ['tik' => $tik, 'rodzaj' => 'dlawienie', 'opis' => "{$j['nazwa']}: przegrzany rdzeń — dławienie reaktora", 'wartosc' => round($cieplo, 1)];
    } elseif ($j['dlawienie'] && $cieplo < 60) {
        $S['jednostki'][$ji]['dlawienie'] = false;
        $S['zdarzenia'][] = ['tik' => $tik, 'rodzaj' => 'dlawienie', 'opis' => "{$j['nazwa']}: rdzeń ostygł — koniec dławienia", 'wartosc' => round($cieplo, 1)];
    }
}

// Sektor osłony trafienia z punktu (x, y) i burta: [sektor, 'l'|'p'] (jak silnik.py sektor_trafienia).
function bitwa_sektor_trafienia(array $S, int $tik, array $j, float $x, float $y, string $ziarnoKlucz): array
{
    $kat = bitwa_radiany($j['kurs']);
    $dx = $x - $j['x'];
    $dy = $y - $j['y'];
    $wzdl = $dx * sin($kat) + $dy * cos($kat);
    $wBok = $dx * cos($kat) - $dy * sin($kat);
    $r = $wzdl / (hypot($dx, $dy) ?: 1.0);
    $burta = $wBok >= 0 ? 'p' : 'l';
    if ($r > 0.7) return ['dziob', $burta];
    if ($r < -0.7) return ['rufa', $burta];
    if (bitwa_los($S['ziarno'], $tik, 'pion', $ziarnoKlucz) < 0.5) return ['gora_' . $burta, $burta];
    if (abs($r) < 0.3) return ['hangar', $burta];
    return ['dol_' . $burta, $burta];
}

function bitwa_mnoznik_oslony(array $j, int $gi): float
{
    return bitwa_zas($j, 'oslona_' . ($gi < max(1, intdiv(count($j['generatory']), 2)) ? 'l' : 'p'));
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

function bitwa_trafienie(array &$S, int $tik, int $ji, int $gi, float $energia, float $hzL, string $sprawca, string $ziarnoKlucz, float $zx, float $zy): void
{
    [$sektor, $burta] = bitwa_sektor_trafienia($S, $tik, $S['jednostki'][$ji], $zx, $zy, $ziarnoKlucz);
    $wzmSektora = bitwa_wzm($S, $S['jednostki'][$ji], $sektor);
    $j = &$S['jednostki'][$ji];
    $gen = &$j['generatory'][$gi];
    $p = bitwa_slowniki()['profile'][$j['profil']];
    $hzO = $gen['hz_osiagane'] ? bitwa_hz_skuteczne($gen['zyly'], $gen['hz_osiagane']) : 0;
    [$doKadluba, $doGen] = $hzO ? bitwa_filtr($energia, (float) $hzO, $hzL) : [$energia, 0.0];
    $pokr = bitwa_nast($j, 'pokr_' . $sektor);
    if ($pokr < 1 && $doGen > 0 && bitwa_los($S['ziarno'], $tik, 'granica', $ziarnoKlucz) < 0.2) {
        $przeciek = $doGen * 0.5 * (1 - $pokr);
        $doGen -= $przeciek;
        $doKadluba += $przeciek;
    }
    if ($wzmSektora) $doKadluba *= 0.6;
    if ($sektor === 'rufa') {
        if (bitwa_nast($j, 'sync_rufa_sg_' . $burta) >= 0.5) $doKadluba *= 0.9;
        if (bitwa_nast($j, 'sync_rufa_sm_' . $burta) >= 0.5) $doKadluba *= 0.9;
    }
    $gen['obciazenie'] += $doGen;
    $c = bitwa_pojemnosc($p) * bitwa_mnoznik_oslony($j, $gi);
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
        $kadlub = 0.0;
        foreach ($j['sekcje'] as $s => $v) $kadlub += $v * $p['kadlub'][$s];
        if ($kadlub < BITWA_STALE['prog_unieszkodliwienia'] && $j['stan'] === 'w_bitwie') {
            $j['stan'] = 'unieszkodliwiona';
            $S['zdarzenia'][] = ['tik' => $tik, 'rodzaj' => 'unieszkodliwienie', 'opis' => "{$j['strona']}: {$j['nazwa']} unieszkodliwiony", 'wartosc' => round($kadlub, 2)];
        }
    }
    unset($gen, $j);
}

function bitwa_ai_oslony(array &$S, int $tik, int $ji): void
{
    $zasieg = bitwa_nast($S['jednostki'][$ji], 'sens_aktywny') >= 0.5 ? BITWA_STALE['zasieg_sensorow_km'] : 60.0;
    foreach ($S['jednostki'][$ji]['generatory'] as $gi => $gen) {
        if (!$gen['auto']) continue;
        $j = $S['jednostki'][$ji];
        $hz = [];
        foreach ($S['klucze'] as $k) {
            if ($k['strona'] !== $j['strona'] && $k['stan'] === 'w_locie' && bitwa_odleglosc($k, $j) < $zasieg) $hz[] = bitwa_hz_klucza($k);
        }
        foreach (bitwa_wrogowie($S, $j['strona']) as $wi) {
            $w = $S['jednostki'][$wi];
            $tlw = bitwa_slowniki()['tl'][$w['profil']] ?? [];
            if ($tlw && bitwa_odleglosc($w, $j) < $zasieg) $hz[] = max(array_map(static fn($u) => $u[7], $tlw));
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

function bitwa_dowodca(array $S, string $strona): array
{
    return BITWA_STALE['dowodcy'][$S['dowodcy'][$strona] ?? ''] ?? BITWA_STALE['dowodcy']['calrissian'];
}

function bitwa_w_przygotowaniu(array $S, string $kanal): bool
{
    foreach ($S['po_kanale'][$kanal] ?? [] as $i) if ($S['rozkazy'][$i]['stan'] === 'przygotowanie') return true;
    return false;
}

// SI klucza strony AI (jak silnik.py ai_klucza, MECHANIKA 11): uniki z ogonem wroga, nalot / walka / ochrona według Hz
// osłon okrętu gracza i dowódcy, torpedy w nalocie, uniki i przelot przy okręcie.
function bitwa_ai_klucza(array &$S, int $tik, int $ki): void
{
    $k = $S['klucze'][$ki];
    $kan = bitwa_kanal_klucza($k);
    $kanr = bitwa_kanal_rozkazu($k);
    if (bitwa_w_przygotowaniu($S, $kan) || bitwa_w_przygotowaniu($S, $kanr)) return;
    $cfg = bitwa_dowodca($S, $k['strona']);
    $z0 = $S['ziarno'];
    $fig = bitwa_figura($S, $kan);
    $wk = bitwa_wrogie_klucze($S, $k['strona']);
    foreach ($wk as $ti) {
        $t = $S['klucze'][$ti];
        if (bitwa_odleglosc($k, $t) <= 1.5 && abs(bitwa_norm180(bitwa_namiar($k, $t) - $k['kurs'])) > 135) {
            if (!in_array($fig, BITWA_UNIKI, true) && bitwa_los($z0, $tik, 'ai_ogon', $kan) < 2 * $cfg['unik']) {
                $kod = $k['ranga'] === 'as' ? 'spirala' : (bitwa_los($z0, $tik, 'ai_ogon2', $kan) < 0.35 ? 'split_s'
                    : (bitwa_los($z0, $tik, 'ai_ogon3', $kan) < 0.5 ? 'beczka_l' : 'beczka_p'));
                bitwa_przyjmij($S, $kan, $kod, (float) $tik, $k['ranga'], $ki, ['ai' => true]);
            }
            return;
        }
    }
    $wj = bitwa_wrogowie($S, $k['strona']);
    if (in_array($k['typ'], BITWA_TORPEDY, true) && $wj) {
        $okret = $S['jednostki'][bitwa_najblizsza($S, $k, $wj)];
        $hzO = 0;
        foreach ($okret['generatory'] as $g) $hzO = max($hzO, $g['hz_osiagane']);
        $przebija = bitwa_hz_klucza($k) * BITWA_STALE['torpedy_hz'] > $hzO;
        $mysliwce = bitwa_najblizszy_klucz($S, $wk, $k, 10.0) !== null;
        if ($cfg['nalot'] === 'zawsze') $chce = 'nalot';
        elseif ($cfg['nalot'] === 'hz') $chce = $przebija ? 'nalot' : 'atak';
        else $chce = $przebija && !$mysliwce ? 'nalot' : ($mysliwce ? 'atak' : 'ochrona');
    } else {
        $chce = $cfg['nalot'] !== 'ostroznie' ? 'atak' : 'ochrona';
    }
    if ($chce === 'ochrona' && bitwa_macierzysta($S, $k) === null) $chce = 'atak';
    if ($k['rozkaz'] !== $chce && bitwa_los($z0, $tik, 'ai_rozkaz', $kan) < 0.1) {
        bitwa_przyjmij($S, $kanr, $chce, (float) $tik, $k['ranga'], $ki, ['ai' => true]);
        return;
    }
    $cel = bitwa_cel_klucza($S, $k);
    if ($cel[0] === null || !in_array($cel[3], ['klucz', 'okret'], true)) return;
    $o = bitwa_obiekt($S, $cel);
    $d = bitwa_odleglosc($k, $o);
    if ($cel[3] === 'okret' && in_array($k['typ'], BITWA_TORPEDY, true) && $k['rozkaz'] === 'nalot' && $d <= BITWA_STALE['zasieg_ognia_klucza_km']
        && abs(bitwa_norm180(bitwa_namiar($k, $o) - $k['kurs'])) <= BITWA_STALE['stozek_okret_deg'] && $fig !== 'torpedy'
        && bitwa_los($z0, $tik, 'ai_torp', $kan) < 0.5) {
        bitwa_przyjmij($S, $kan, 'torpedy', (float) $tik, $k['ranga'], $ki, ['ai' => true]);
        return;
    }
    if ($cel[3] === 'okret' && $d < 8 && !in_array($fig, array_merge(BITWA_UNIKI, ['przelot', 'torpedy']), true) && bitwa_los($z0, $tik, 'ai', $kan) < $cfg['unik']) {
        $kod = bitwa_los($z0, $tik, 'ai2', $kan) < 0.5 ? 'beczka_l' : 'zygzak';
        bitwa_przyjmij($S, $kan, $kod, (float) $tik, $k['ranga'], $ki, ['ai' => true]);
    } elseif ($cel[3] === 'okret' && $d < BITWA_STALE['zasieg_ognia_klucza_km'] && !in_array($fig, ['przelot', 'torpedy'], true) && bitwa_los($z0, $tik, 'ai3', $kan) < 0.2) {
        bitwa_przyjmij($S, $kan, 'przelot', (float) $tik, $k['ranga'], $ki, ['ai' => true]);
    }
}

// Okręt gracza: skręt do kursu zadanego i zmiana prędkości do zadanej (jak silnik.py ster_okretu).
function bitwa_ster_okretu(array &$S, int $ji): void
{
    $j = $S['jednostki'][$ji];
    $n = static fn(string $k): float => bitwa_nast($j, $k);
    if (($j['kurs_cel'] ?? null) !== null) {
        $zwrot0 = (BITWA_STALE['obrot_okretu'][$j['profil']] ?? 60) / 60;
        $d = bitwa_norm180($j['kurs_cel'] - $j['kurs']);
        $zwrot = $zwrot0 * bitwa_mnoznik_skretu($S, $j, $d < 0 ? 'l' : 'p');
        $j['kurs'] = bitwa_norm180($j['kurs'] + max(-$zwrot, min($zwrot, $d)));
    }
    $vMaks = BITWA_STALE['v_maks_okretu'][$j['profil']] ?? 0.3;
    $ciag = ($n('sil_max_l') + $n('sil_max_p') + $n('sil_srodek')) / 3;
    $wzmV = 1 + 0.1 * (bitwa_wzm($S, $j, 'gl_l') + bitwa_wzm($S, $j, 'gl_p') + bitwa_wzm($S, $j, 'gl_s'));
    if (($j['v_cel'] ?? null) !== null) {
        $a = (BITWA_STALE['przyspieszenie_okretu'][$j['profil']] ?? 0.005) * $ciag * $wzmV * (1 + 0.1 * ($n('sync_gl_l_s') + $n('sync_gl_p_s')));
        $vGora = $vMaks * bitwa_zas($j, 'v') * $ciag * $wzmV * (1 - 0.2 * abs($n('trym_poch') - 0.5));
        $cel = max(0.0, min($vGora, $j['v_cel']));
        $cel = max($cel, min($vGora, $vMaks * ($n('sil_min_l') + $n('sil_min_p')) / 3));
        $j['v'] = $j['v'] + max(-$a, min($a, $cel - $j['v']));
    }
    $roznica = $n('sil_max_l') - $n('sil_max_p');
    if ($roznica != 0 && $j['v'] > 0) $j['kurs'] = bitwa_norm180($j['kurs'] + $roznica * 0.3 * $j['v'] / $vMaks);
    if ($n('trym_odch') != 0.5) $j['kurs'] = bitwa_norm180($j['kurs'] + ($n('trym_odch') - 0.5) * 0.4);
    $vb = 0.02 * (($n('dysza_pg') + $n('dysza_pd') + $n('katdysz_p')) - ($n('dysza_lg') + $n('dysza_ld') + $n('katdysz_l')));
    if ($vb != 0) {
        $j['x'] += $vb * cos(bitwa_radiany($j['kurs']));
        $j['y'] -= $vb * sin(bitwa_radiany($j['kurs']));
    }
    foreach (['kurs', 'v', 'x', 'y'] as $k) $S['jednostki'][$ji][$k] = $j[$k];
}

// Średnia ważona integralność kadłuba (sekcje alfabetycznie — jak silnik.py kadlub).
function bitwa_kadlub(array $j): float
{
    $u = bitwa_slowniki()['profile'][$j['profil']]['kadlub'];
    $w = 0.0;
    $sek = $j['sekcje'];
    ksort($sek, SORT_STRING);
    foreach ($sek as $s => $v) $w += $v * $u[$s];
    return $w;
}

// SI okrętu strony AI (jak silnik.py ai_okretu): dystans walki dowódcy, burta mniej obciążona do wroga, odwrót.
function bitwa_ai_okretu(array &$S, int $tik, int $ji): void
{
    $j = $S['jednostki'][$ji];
    $cfg = bitwa_dowodca($S, $j['strona']);
    $wj = bitwa_wrogowie($S, $j['strona']);
    $zwrot = (BITWA_STALE['obrot_okretu'][$j['profil']] ?? 60) / 60;
    $chce = $j['kurs'];
    if (bitwa_kadlub($j) < $cfg['odwrot_proc']) {
        $daleko = true;
        foreach ($wj as $wi) if (bitwa_odleglosc($j, $S['jednostki'][$wi]) <= BITWA_STALE['wycofanie_km']) { $daleko = false; break; }
        if ($daleko) {
            $S['jednostki'][$ji]['stan'] = 'wycofana';
            $S['zdarzenia'][] = ['tik' => $tik, 'rodzaj' => 'wycofanie', 'opis' => "{$j['strona']}: {$j['nazwa']} wycofuje się z bitwy", 'wartosc' => null];
            return;
        }
        $chce = bitwa_namiar($S['jednostki'][bitwa_najblizsza($S, $j, $wj)], $j);
    } elseif ($wj) {
        $w = $S['jednostki'][bitwa_najblizsza($S, $j, $wj)];
        if (bitwa_odleglosc($j, $w) > $cfg['dystans_km'] + 2) {
            $chce = bitwa_namiar($j, $w);
        } else {
            $pol = intdiv(count($j['generatory']), 2) ?: 1;
            $lewa = $prawa = 0.0;
            foreach ($j['generatory'] as $gi => $g) {
                if ($gi < $pol) $lewa += $g['obciazenie']; else $prawa += $g['obciazenie'];
            }
            $chce = $lewa > $prawa ? bitwa_namiar($j, $w) - 90 : bitwa_namiar($j, $w) + 90;
        }
    }
    $S['jednostki'][$ji]['kurs'] = bitwa_norm180($j['kurs'] + max(-$zwrot, min($zwrot, bitwa_norm180($chce - $j['kurs']))));
}

// Jeden tik: rozkazy → ruch → ogień → straty → zdarzenia (jak silnik.py krok).
function bitwa_krok(array &$S): void
{
    $tik = $S['tik'];
    $prof = bitwa_rozkazy_profile();
    // wyjście ze skoku (jak silnik.py): jednostka czekająca w nadprzestrzeni wchodzi do bitwy
    foreach ($S['jednostki'] as $ji => $j) {
        if ($j['stan'] === 'oczekuje' && $tik >= $j['wejscie']) {
            $S['jednostki'][$ji]['stan'] = 'w_bitwie';
            $S['zdarzenia'][] = ['tik' => $tik, 'rodzaj' => 'wejscie', 'opis' => "{$j['strona']}: {$j['nazwa']} wychodzi z nadprzestrzeni", 'wartosc' => null];
        }
    }
    $masz = bitwa_slowniki()['maszyny'];
    $nowe = bitwa_aktywuj($S, $tik);
    $S['_dziala'] = [];
    foreach (array_keys($S['aktywne']) as $i) if ($S['rozkazy'][$i]['stan'] === 'dziala') $S['_dziala'][$S['rozkazy'][$i]['kanal']] = true;
    foreach ($nowe as $i) {
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
                    $S['klucze'][$ki]['kurs_zadany'] = null;
                    if ($w['kod'] === 'powrot' && $S['klucze'][$ki]['stan'] === 'w_locie') $S['klucze'][$ki]['stan'] = 'powrot';
                }
            } elseif (isset(BITWA_ZMIANA_KURSU[$w['kod']])) {
                $S['klucze'][$ki]['kurs_zadany'] = $S['klucze'][$ki]['kurs'] + BITWA_ZMIANA_KURSU[$w['kod']];
            }
        }
        if ($w['kod'] === 'ster' || $w['kod'] === 'cel_ognia') {
            $nr = (int) explode(':', $w['kanal'], 2)[0];
            foreach ($S['jednostki'] as $ji => $j) {
                if ($j['nr'] !== $nr) continue;
                if ($w['kod'] === 'ster') {
                    [$kc, $vc] = array_map('floatval', explode(';', (string) ($w['wartosc'] ?? '0;0')) + [0, 0]);
                    $S['jednostki'][$ji]['kurs_cel'] = $kc;
                    $S['jednostki'][$ji]['v_cel'] = $vc;
                } else {
                    $S['jednostki'][$ji]['cel_ognia'] = ($w['wartosc'] ?? '') !== '' ? (int) $w['wartosc'] : null;
                }
                break;
            }
        }
        if (in_array($w['kod'], ['nastawa', 'synchronizuj', 'przelacz', 'wyrownaj'], true)) {
            $nr = (int) explode(':', $w['kanal'], 2)[0];
            foreach ($S['jednostki'] as $ji => $j) {
                if ($j['nr'] !== $nr) continue;
                if ($w['kod'] === 'wyrownaj') {
                    foreach (BITWA_GRUPY_WYROWNANIA[(string) $w['wartosc']] ?? [] as $k) $S['jednostki'][$ji]['nastawy'][$k] = BITWA_NASTAWY[$k];
                } else {
                    [$k, $v] = explode('=', (string) $w['wartosc'], 2) + ['', '0'];
                    if (isset(BITWA_NASTAWY[$k])) $S['jednostki'][$ji]['nastawy'][$k] = (float) $v;
                    if (str_starts_with($k, 'tryb_') && (float) $v >= 0.75) {
                        $S['jednostki'][$ji]['nastawy']['zapor_do_' . substr($k, -1)] = (float) ($tik + 10 + bitwa_zaokr(50 * bitwa_nast($S['jednostki'][$ji], 'czas_zapor')));
                    }
                }
                break;
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
        $m = $masz[$k['typ']];
        $v = $m['v'] * ($fig && $prof[$fig]['predkosc_mn'] !== null ? (float) $prof[$fig]['predkosc_mn'] : 1);
        $celK = bitwa_cel_klucza($S, $k);
        $promien = $celK[2];
        $chce = $k['kurs'];
        if ($celK[0] !== null) {
            $cel = bitwa_obiekt($S, $celK);
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
            $chce = $d > $promien ? bitwa_namiar($k, $cel) : bitwa_namiar($k, $cel) + 90;
        }
        if ($k['kurs_zadany'] !== null && $k['stan'] === 'w_locie') $chce = $k['kurs_zadany'];
        $zwrot = $m['zwrot'] * ($k['ranga'] === 'as' ? BITWA_STALE['zwrot_as'] : 1);
        $k['kurs'] = bitwa_norm180($k['kurs'] + max(-$zwrot, min($zwrot, bitwa_norm180($chce - $k['kurs']))));
        $k['x'] += $v * sin(bitwa_radiany($k['kurs']));
        $k['y'] += $v * cos(bitwa_radiany($k['kurs']));
        $S['klucze'][$ki] = $k;
    }
    foreach (array_keys($S['jednostki']) as $ji) {
        if ($S['jednostki'][$ji]['stan'] === 'w_bitwie') bitwa_zasilanie($S, $tik, $ji);
        else unset($S['jednostki'][$ji]['zasil']);
    }
    foreach ($S['jednostki'] as $ji => $j) {
        if ($j['stan'] !== 'w_bitwie') continue;
        if (!empty($j['ai'])) {
            bitwa_ai_okretu($S, $tik, $ji);
            if ($S['jednostki'][$ji]['stan'] !== 'w_bitwie') continue;
        } else {
            bitwa_ster_okretu($S, $ji);
        }
        $j = $S['jednostki'][$ji];
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
        $celK = bitwa_cel_klucza($S, $k);
        if ($celK[0] === null) continue;
        $cel = bitwa_obiekt($S, $celK);
        $d = bitwa_odleglosc($k, $cel);
        $odchyl = abs(bitwa_norm180(bitwa_namiar($k, $cel) - $k['kurs']));
        if ($celK[3] === 'klucz' && $d <= $m['zasieg'] && $odchyl <= $m['stozek']) {
            $S['klucze'][$ki]['ladunek'] = 0;
            bitwa_strzal_w_klucz($S, $tik, $ki, $celK[1]);
        } elseif ($celK[3] === 'okret' && $d <= BITWA_STALE['zasieg_ognia_klucza_km'] && $odchyl <= BITWA_STALE['stozek_okret_deg']) {
            $S['klucze'][$ki]['ladunek'] = 0;
            $torp = $fig === 'torpedy' && in_array($k['typ'], BITWA_TORPEDY, true);
            $gi = bitwa_generator_od_strony($cel, $k['x'], $k['y']);
            bitwa_trafienie($S, $tik, $celK[1], $gi, $m['energia'] * $k['maszyny'] * ($torp ? BITWA_STALE['torpedy_energia'] : 1),
                bitwa_hz_klucza($k) * ($torp ? BITWA_STALE['torpedy_hz'] : 1), "{$k['strona']} {$k['nr']}" . ($torp ? ' torpedy' : ''), bitwa_kanal_klucza($k),
                $k['x'], $k['y']);
        }
    }
    foreach (array_keys($S['jednostki']) as $ji) {
        if ($S['jednostki'][$ji]['stan'] === 'w_bitwie') bitwa_obrona_punktowa($S, $tik, $ji);
    }
    foreach (array_keys($S['jednostki']) as $ji) {
        if ($S['jednostki'][$ji]['stan'] === 'w_bitwie') bitwa_ogien_okretu($S, $tik, $ji);
    }
    foreach (array_keys($S['klucze']) as $ki) {
        if ($S['klucze'][$ki]['oslona_pkt'] > 0) $S['klucze'][$ki]['oslona_pkt'] = max(0.0, $S['klucze'][$ki]['oslona_pkt'] - BITWA_STALE['oslona_regen']);
    }
    // osłony: odpływ obciążenia, AI osłon
    foreach (array_keys($S['jednostki']) as $ji) {
        foreach (array_keys($S['jednostki'][$ji]['generatory']) as $gi) $S['jednostki'][$ji]['generatory'][$gi]['obciazenie'] *= BITWA_STALE['odplyw_obciazenia'];
        if ($S['jednostki'][$ji]['stan'] === 'w_bitwie') bitwa_ai_oslony($S, $tik, $ji);
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

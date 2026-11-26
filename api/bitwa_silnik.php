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
            'obiekt_id' => $j['obiekt_id'] ?? null, 'ai' => false, 'kurs_cel' => null, 'v_cel' => null, 'cel_ognia' => null];
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
    foreach ($sl['tl'][$j['profil']] ?? [] as [$nr, $x, $y, $lukS, $lukP, $zasieg, $theta, $hz]) {
        if (($tik + $nr) % ($p['ladowanie'] ?: 6)) continue;
        $dzialo = bitwa_punkt_na_kadlubie($j, $x, $y);
        [$ci, $dn] = bitwa_cel_baterii($S, $j, $dzialo, $lukS, $lukP, $zasieg);
        if ($ci === null) continue;
        $pc = bitwa_celnosc($theta / 1000, $dn, $sl['profile'][$S['jednostki'][$ci]['profil']]['dlugosc_m'] ** 2 * BITWA_STALE['przekroj_okretu']);
        $kan = "tl:{$j['nr']}:$nr";
        $trafienia = 0;
        for ($i = 0; $i < $p['lufy']; $i++) if (bitwa_los($S['ziarno'], $tik, 'tl', $kan, $i) < $pc) $trafienia++;
        if ($trafienia) {
            $gi = bitwa_generator_od_strony($S['jednostki'][$ci], $dzialo['x'], $dzialo['y']);
            bitwa_trafienie($S, $tik, $ci, $gi, $trafienia * $sl['e_zyly'] * $hz, (float) $hz, "{$j['nazwa']} TL $nr", $kan);
        }
    }
    [$energia, $lufy, $ladowanie] = BITWA_STALE['jon'][$j['profil']] ?? [0, 0, 8];
    foreach ($sl['jon'][$j['profil']] ?? [] as [$nr, $x, $y, $lukS, $lukP, $zasieg, $theta, $hz]) {
        if (($tik + $nr) % $ladowanie) continue;
        $dzialo = bitwa_punkt_na_kadlubie($j, $x, $y);
        [$ci, $dn] = bitwa_cel_baterii($S, $j, $dzialo, $lukS, $lukP, $zasieg);
        if ($ci === null) continue;
        $pc = bitwa_celnosc($theta / 1000, $dn, $sl['profile'][$S['jednostki'][$ci]['profil']]['dlugosc_m'] ** 2 * BITWA_STALE['przekroj_okretu']);
        $kan = "jon:{$j['nr']}:$nr";
        $trafienia = 0;
        for ($i = 0; $i < $lufy; $i++) if (bitwa_los($S['ziarno'], $tik, 'jon', $kan, $i) < $pc) $trafienia++;
        if ($trafienia) {
            $gi = bitwa_generator_od_strony($S['jednostki'][$ci], $dzialo['x'], $dzialo['y']);
            $g = &$S['jednostki'][$ci]['generatory'][$gi];
            $g['obciazenie'] += $trafienia * $energia;
            $c = bitwa_pojemnosc($sl['profile'][$S['jednostki'][$ci]['profil']]);
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
    foreach ($S['jednostki'][$ji]['generatory'] as $gi => $gen) {
        if (!$gen['auto']) continue;
        $j = $S['jednostki'][$ji];
        $hz = [];
        foreach ($S['klucze'] as $k) {
            if ($k['strona'] !== $j['strona'] && $k['stan'] === 'w_locie' && bitwa_odleglosc($k, $j) < BITWA_STALE['zasieg_sensorow_km']) $hz[] = bitwa_hz_klucza($k);
        }
        foreach (bitwa_wrogowie($S, $j['strona']) as $wi) {
            $w = $S['jednostki'][$wi];
            $tlw = bitwa_slowniki()['tl'][$w['profil']] ?? [];
            if ($tlw && bitwa_odleglosc($w, $j) < BITWA_STALE['zasieg_sensorow_km']) $hz[] = max(array_map(static fn($u) => $u[7], $tlw));
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
    if (($j['kurs_cel'] ?? null) !== null) {
        $zwrot = (BITWA_STALE['obrot_okretu'][$j['profil']] ?? 60) / 60;
        $S['jednostki'][$ji]['kurs'] = bitwa_norm180($j['kurs'] + max(-$zwrot, min($zwrot, bitwa_norm180($j['kurs_cel'] - $j['kurs']))));
    }
    if (($j['v_cel'] ?? null) !== null) {
        $a = BITWA_STALE['przyspieszenie_okretu'][$j['profil']] ?? 0.005;
        $cel = max(0.0, min(BITWA_STALE['v_maks_okretu'][$j['profil']] ?? 0.3, $j['v_cel']));
        $S['jednostki'][$ji]['v'] = $j['v'] + max(-$a, min($a, $cel - $j['v']));
    }
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
                bitwa_hz_klucza($k) * ($torp ? BITWA_STALE['torpedy_hz'] : 1), "{$k['strona']} {$k['nr']}" . ($torp ? ' torpedy' : ''), bitwa_kanal_klucza($k));
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

"""Silnik wzorcowy bitwy (Python) — specyfikacja wykonywalna dla portu PHP (api/bitwa_silnik.php).

Liczy dokładnie to, co jest ustalone w MECHANIKA.md; stałe robocze oznaczone [R] w STALE. Bez bazy danych: stan to
słowniki, wejście to dziennik rozkazów, wyjście to stan i zdarzenia. Deterministyczny: ta sama bitwa (ziarno + rozkazy)
daje ten sam przebieg — w Pythonie i w PHP (losowość z crc32, jak PHP crc32()).

    python silnik.py [scenariusz] [ziarno]    rozgrywa scenariusz bez graczy (AI obu stron) i wypisuje raport
"""
import math, sys, zlib
from mechanika import stopnie_natywne, stopien_w_gore, moc_zyly, pojemnosc, filtr
from profile_klas import PROFILE, WSPOLNE
W_ENERGIA_ZYLY = WSPOLNE['energia_zyly_lufy_J']  # energia bolta na aktywną żyłę baterii
TORPEDY = ('X_WING', 'Y_WING', 'B_WING')          # maszyny z torpedami protonowymi
UNIKI = ('beczka_l', 'beczka_p', 'zygzak', 'spirala', 'skok_cieniowy', 'split_s', 'petla', 'burst')
from dane.maszyny import MASZYNY, WALKA, BEZ_SENSOROW, RADAR_WLASNY_KM, RADAR_MATKI_KM
from dane.uzbrojenie import UZBROJENIE
from dane.rozkazy import ROZKAZY
from dane.scenariusze import SCENARIUSZE

CYKL = WSPOLNE['cykl_tiki']
MASZ = {m[0]: dict(zip(['kod', 'nazwa', 'strona', 'rola', 'hz', 'oslona_hz', 'v', 'przekroj', 'energia', 'ladowanie', 'opis'], m)) for m in MASZYNY}
for _kod, (_zw, _zas, _st, _th) in WALKA.items():
    MASZ[_kod].update({'zwrot': _zw, 'zasieg': _zas, 'stozek': _st, 'theta': _th})
# Obrona punktowa okrętów: działka (nr, x, y, łuk — środek i połowa, zasięg km, θ mrad, Hz) z dane/uzbrojenie.py
PD = {kod: [(u[0], u[2], u[3], u[4], u[5], u[6], u[7], u[8]) for u in lista if u[1] == 'pd'] for kod, lista in UZBROJENIE.items()}
# Baterie okrętów: turbolasery (salwa wszystkich luf) i jony (obciążają generator osłon celu, nie ranią kadłuba)
TL = {kod: [(u[0], u[2], u[3], u[4], u[5], u[6], u[7], u[8]) for u in lista if u[1] == 'turbolaser'] for kod, lista in UZBROJENIE.items()}
JON = {kod: [(u[0], u[2], u[3], u[4], u[5], u[6], u[7], u[8]) for u in lista if u[1] == 'jon'] for kod, lista in UZBROJENIE.items()}
ROZK = {r[0]: dict(zip(['kod', 'nazwa', 'grupa', 'c', 'd', 'o', 'theta', 'theta_wl', 'v', 'ogien_wyl', 'ranga', 'skraca', 'opis'], r)) for r in ROZKAZY}
RANGI = {'kadet': 0, 'standard': 1, 'as': 2}

STALE = {
    'odplyw_obciazenia': 0.98,      # [R] obciążenie generatora × 0,98 na tik (salwa ISD nasyca C w ok. 17 tików)
    'energia_zabicia_zyly_s': 1.0,  # [R] żyła ginie od energii = P_żyły × 1 s skupionej na niej (Hz_o < Hz_l)
    'promien_trafienia': 7,         # [R] promień (w żyłach siatki) rozkładu uszkodzeń trafienia (turbolaser 7)
    'zasieg_ognia_klucza_km': 3.0,  # [R]
    'dystans_ataku_km': 2.0,        # [R] klucz podchodzi do celu na tyle i krąży
    'zasieg_sensorow_km': 200.0,    # [R] pomiar Hz kluczy przez sensory (tryb automatyczny osłon, konsola Sensory)
    'dystans_walki_km': 10.0,       # [R] okręt AI zatrzymuje się w paśmie wymiany ognia (MECHANIKA 4.2) od wrogiego okrętu
    'tasma_s': 60,                  # [U]
    'zasieg_walki_atak_km': 12.0,   # [R] klucz z rozkazem atak szuka kluczy wroga w tym promieniu od siebie
    'zasieg_walki_ochrona_km': 8.0, # [R] ochrona: kluczy wroga w tym promieniu od okrętu matki
    'zasieg_walki_patrol_km': 15.0, # [R] patrol: jw.
    'ogon_km': 0.3,                 # [R] pościg: klucz trzyma się tyle za celem
    'stozek_okret_deg': 30.0,       # [R] stożek ognia klucza do okrętu
    'zwrot_as': 1.25,               # [R] as skręca szybciej
    'oslona_nasycenie': 0.25,       # [R] trafienie zatrzymane przez osłonę maszyny nasyca ją o tyle × (1 − przepuszczone)
    'oslona_regen': 0.01,           # [R] osłona maszyny odzyskuje tyle na tik
    'p_min': 0.01, 'p_maks': 0.95,  # [R] granice celności na strzał (MECHANIKA 4.3)
    'prog_unieszkodliwienia': 25.0, # [?] W13: średnia integralność kadłuba poniżej — okręt unieszkodliwiony, nie walczy
    'przekroj_okretu': 0.05,        # [R] przekrój okrętu do celności baterii: długość² × 0,05 (ISD 128 000 m²)
    'jon': {'ISD_I': (4e20, 1, 8), 'MC80': (2e20, 3, 8)},  # [R] bateria jonowa: energia lufy (J), lufy, ładowanie (tiki)
    'torpedy_energia': 8.0, 'torpedy_hz': 1.5,              # [R] torpedy protonowe: energia ×8, Hz ×1,5
    'wycofanie_km': 60.0,           # [R] okręt w odwrocie opuszcza bitwę dalej niż tyle od wrogów
    # [R] Ster okrętu gracza (konsole Silniki, Nawigacja): prędkość maks. (km/s, ISD 975 km/h) i przyspieszenie (km/s²)
    'v_maks_okretu': {'ISD_I': 0.27, 'MC80': 0.27, 'ZBIEG': 0.3},
    'przyspieszenie_okretu': {'ISD_I': 0.002, 'MC80': 0.003, 'ZBIEG': 0.01},
    # [R] Dowódcy AI (MECHANIKA 11): dystans walki okrętu, próg odwrotu (% kadłuba), skłonność kluczy do uników,
    # nalot bombowców: zawsze / gdy torpedy przebiją osłony (hz) / jw., ale tylko bez myśliwców wroga w pobliżu.
    'dowodcy': {
        'radus': {'dystans_km': 6.0, 'odwrot_proc': 15.0, 'unik': 0.10, 'nalot': 'zawsze'},
        'calrissian': {'dystans_km': 10.0, 'odwrot_proc': 35.0, 'unik': 0.25, 'nalot': 'hz'},
        'ackbar': {'dystans_km': 18.0, 'odwrot_proc': 55.0, 'unik': 0.40, 'nalot': 'ostroznie'},
    },
    'promien_ochrony_km': 3.0,      # [R] ochrona: klucz krąży przy okręcie matce
    'promien_patrolu_km': 8.0,      # [R] patrol: szerszy krąg wokół okrętu matki
    'promien_skanu_km': 10.0,       # [R] skan: krąży przy celu poza zasięgiem jego ognia
    'ladowanie_km': 0.5,            # [R] powrót: klucz ląduje w hangarze bliżej niż tyle
    'skret_lagodny': 3.0, 'skret_ostry': 9.0, 'skret_zawrot': 30.0,  # [R] °/tik
    'kadlub_j': {'ISD_I': 1.2e25, 'MC80': 8e24, 'ZBIEG': 2e22},        # [R] odporność całego kadłuba — wymiana burtami ISD–MC80 ok. 40 min
    'xp_akcja': 0.5,                # [U]
    'xp_mnoznik': {'as': 0.5, 'standard': 1.0, 'kadet': 1.5},          # [U] jak w api/klucze.php
    'dryf_k': {'kadet': 0.25, 'standard': 0.25, 'as': 1 / 3},          # [U]
    'zmiany_cykl': {'kadet': 2, 'standard': 2, 'as': 4},              # [U]
}


# ------------------------------------------------------------------------------------------------------------ przenośność
# Te same operacje w PHP: kąty przez stałe (nie math.degrees / deg2rad — różnią się kolejnością działań w ostatnim bicie),
# zaokrąglenie „pół w górę” (Python round() zaokrągla do parzystej), kolejność kluczy i sekcji ustalona sortowaniem.
def stopnie(x):
    return x * 57.29577951308232


def radiany(x):
    return x * 0.017453292519943295


def zaokr(x):
    return math.floor(x + 0.5)


# ------------------------------------------------------------------------------------------------------------ losowość
def los(ziarno, tik, *klucze):
    """[0, 1) z crc32 tekstu „ziarno:tik:klucz…” — identycznie w PHP: crc32("$ziarno:$tik:…") / 4294967296."""
    return zlib.crc32(':'.join(str(x) for x in (ziarno, tik) + klucze).encode('utf-8')) / 4294967296


def rozrzut(ziarno, s, *klucze):
    """Całkowite przesunięcie 0…s sekund (rozrzut startów i wejść ze scenariusza)."""
    return int(los(ziarno, 0, 'rozrzut', *klucze) * (s + 1)) if s else 0


# ------------------------------------------------------------------------------------------------------------ zegar
def czas_bitwy(teraz, start, pauzy_s=0.0, pauza_od=None):
    """Sekundy bitwy: od startu minus pauzy (MG); pauza trwa — zegar stoi."""
    if start is None: return 0.0
    t = teraz - start - pauzy_s
    if pauza_od is not None: t -= teraz - pauza_od
    return max(0.0, t)


# ------------------------------------------------------------------------------------------------------------ rozkazy
def przygotowanie(kod, ranga):
    r = ROZK[kod]
    c = float(r['c'])
    if r['skraca']:
        c += {'kadet': 1, 'standard': 0, 'as': -1}[ranga]
    return max(1, math.ceil(c))


def przyjmij_rozkaz(stan, kanal, kod, czas_s, ranga='standard', klucz=None, wartosc=None):
    """Rozkaz odebrany przez serwer (komputer centralny) w chwili czas_s bitwy. Zwraca wpis dziennika albo powód odmowy.
    kanal — co wykonuje (klucz lub system okrętu); nowy rozkaz w przygotowaniu tego samego kanału przerywa poprzedni."""
    r = ROZK[kod]
    tik = int(czas_s)
    if RANGI[ranga] < RANGI[r['ranga']]:
        return {'odmowa': f'{r["nazwa"]}: tylko {r["ranga"]}'}
    dziennik = stan['rozkazy']
    ten_kanal = [w for w in dziennik if w['kanal'] == kanal]
    for w in ten_kanal:
        if w['kod'] == kod and w['stan'] != 'przerwany' and w.get('odnowienie_do') and tik < w['odnowienie_do']:
            return {'odmowa': f'{r["nazwa"]}: odnowienie jeszcze {w["odnowienie_do"] - tik} s'}
    if klucz is not None and r['grupa'] != 'okret':
        cykl = tik // CYKL
        zmiany = sum(1 for w in ten_kanal if w['tik'] // CYKL == cykl)
        if zmiany >= STALE['zmiany_cykl'][ranga]:
            return {'odmowa': 'limit zmian w tym cyklu'}
    for w in ten_kanal:
        if w['stan'] == 'przygotowanie':
            w['stan'] = 'przerwany'
            w['przerwany_tik'] = tik
    c = przygotowanie(kod, ranga)
    wpis = {'nr': len(dziennik) + 1, 'kanal': kanal, 'kod': kod, 'tik': tik, 'czas_s': czas_s, 'stan': 'przygotowanie',
            'dziala_od': tik + c, 'dziala_do': (tik + c + int(r['d'])) if r['d'] is not None else None,
            'odnowienie_do': (tik + c + int(r['d'] or 0) + int(r['o'])) if r['o'] else None, 'klucz': klucz, 'wartosc': wartosc}
    dziennik.append(wpis)
    return wpis


def aktywuj_rozkazy(stan, tik):
    """Na początku tiku: rozkazy, którym skończyło się przygotowanie, zaczynają działać (zastępują poprzednie figury
    kanału); działające z czasem — kończą się. Zwraca wpisy, które zaczęły działać w tym tiku."""
    nowe = []
    for w in stan['rozkazy']:
        if w['stan'] == 'przygotowanie' and tik >= w['dziala_od']:
            for inny in stan['rozkazy']:
                if inny is not w and inny['kanal'] == w['kanal'] and inny['stan'] == 'dziala':
                    inny['stan'] = 'zakonczony'
            w['stan'] = 'dziala'
            nowe.append(w)
        elif w['stan'] == 'dziala' and w['dziala_do'] is not None and tik >= w['dziala_do']:
            w['stan'] = 'zakonczony'
    return nowe


def figura(stan, kanal):
    for w in reversed(stan['rozkazy']):
        if w['kanal'] == kanal and w['stan'] == 'dziala' and ROZK[w['kod']]['grupa'] != 'okret':
            return w['kod']
    return None


# ------------------------------------------------------------------------------------------------------------ żyły
def nowe_zyly(n):
    return {'n': n, 'martwe': bytearray((n + 7) // 8), 'temperatury': bytearray(n)}


def martwa(z, i):          # i od 0
    return z['martwe'][i >> 3] >> (i & 7) & 1


def zabij(z, i):
    z['martwe'][i >> 3] |= 1 << (i & 7)


def hz_skuteczne(z, f):
    """Świecą żyły k·(N/f), k = 1…f (MECHANIKA 3.1); martwe nie świecą."""
    krok = z['n'] // f
    return sum(1 for k in range(1, f + 1) if not martwa(z, k * krok - 1))


# ------------------------------------------------------------------------------------------------------------ bitwa
def utworz(scenariusz_kod, ziarno):
    s = next(x for x in SCENARIUSZE if x['kod'] == scenariusz_kod)
    stan = {'scenariusz': s, 'ziarno': ziarno, 'tik': 0, 'rozkazy': [], 'zdarzenia': [], 'jednostki': [], 'klucze': [], 'xp': {}}
    for nr, strona, prof, nazwa, z_mapy, x, y, kurs, v, kadlub, wej, wej_r in s['jednostki']:
        p = PROFILE[prof]
        o = p['oslony']
        gen = []
        for g in range(o['generatory']):
            gen.append({'kod': f'oslona_{g + 1}', 'hz_zadane': o['domyslnie_hz'], 'hz_osiagane': o['domyslnie_hz'],
                        'hz_min': 1, 'hz_max': o['zyly'], 'obciazenie': 0.0, 'rykoszet': 0.0, 'zyly': nowe_zyly(o['zyly']), 'auto': True})
        stan['jednostki'].append({'nr': nr, 'strona': strona, 'profil': prof, 'nazwa': nazwa, 'x': float(x), 'y': float(y),
                                  'kurs': float(kurs), 'v': float(v), 'wejscie': wej + rozrzut(ziarno, wej_r, 'j', nr),
                                  'generatory': gen, 'sekcje': {sk: 100.0 for sk in sorted(x[0] for x in p['kadlub'])}, 'stan': 'w_bitwie',
                                  'kurs_cel': None, 'v_cel': None, 'cel_ognia': None, 'nastawy': {}, 'cieplo': 0.0, 'dlawienie': False})
        # wyjście ze skoku później: jednostka czeka w nadprzestrzeni (sensory widzą jej zmarszczkę), nie walczy, nie leci
        if stan['jednostki'][-1]['wejscie'] > 0: stan['jednostki'][-1]['stan'] = 'oczekuje'
        stan['jednostki'][-1]['ai'] = next(t[2] for t in s['strony'] if t[0] == strona) != 'gracze'
    numery = {}
    # kolejność kluczy: strona, fala, typ maszyny (jak ORDER BY w PHP — tabela nie pamięta kolejności wpisów)
    for fala, strona, jedn_nr, typ, n, start, start_r, x, y, rozkaz in sorted(s['klucze'], key=lambda kl: (kl[1], kl[0], kl[3])):
        st = next(t for t in s['strony'] if t[0] == strona)
        for i in range(n):
            numery[strona] = numery.get(strona, 0) + 1
            nr = numery[strona]
            mac = next((j for j in stan['jednostki'] if j['nr'] == jedn_nr), None)
            # skład rang klucza: los z ziarna według procentów strony (asy, kadeci)
            l = los(ziarno, 0, 'ranga', strona, nr)
            ranga = 'as' if l < st[4] / 100 else 'kadet' if l < (st[4] + st[5]) / 100 else 'standard'
            stan['klucze'].append({'strona': strona, 'nr': nr, 'typ': typ, 'maszyny': 4, 'ranga': ranga, 'moc': 1.0,
                                   'stan': 'hangar', 'start': start + rozrzut(ziarno, start_r, 'k', strona, nr),
                                   'x': float(x if x is not None else mac['x']), 'y': float(y if y is not None else mac['y']),
                                   'kurs': 0.0, 'rozkaz': rozkaz, 'macierzysta': jedn_nr, 'ladunek': 0, 'akcje': 0,
                                   'ai': st[2] != 'gracze', 'oslona_pkt': 0.0, 'kurs_zadany': None})
    stan['dowodcy'] = {st[0]: st[2] for st in s['strony']}
    tasmociag(stan)
    return stan


def tasmociag(stan):
    """Taśmociąg okrętu: klucze z jednego okrętu startują w kolejności planu, co najmniej tasma_s sekund po sobie."""
    stan['tasma'] = {}
    for k in sorted((k for k in stan['klucze'] if k['macierzysta'] is not None and k['start'] is not None),
                    key=lambda k: (k['start'], stan['klucze'].index(k))):
        m = k['macierzysta']
        if m in stan['tasma']:
            k['start'] = max(k['start'], stan['tasma'][m] + STALE['tasma_s'])
        stan['tasma'][m] = k['start']


def kanal_klucza(k):
    """Kanał figur klucza (manewry)."""
    return f'klucz:{k["strona"]}:{k["nr"]}'


def kanal_rozkazu(k):
    """Kanał rozkazów klucza (ochrona, patrol, atak, skan, powrót, start) — osobny od figur."""
    return f'rozkaz:{k["strona"]}:{k["nr"]}'


def macierzysta(stan, k):
    return next((j for j in stan['jednostki'] if j['nr'] == k['macierzysta'] and j['stan'] == 'w_bitwie'), None)


def norm180(a):
    """Kąt do przedziału [−180, 180) — w PHP fmod z poprawką znaku, jak Python %."""
    return (a + 180) % 360 - 180


def namiar(a, b):
    return stopnie(math.atan2(b['x'] - a['x'], b['y'] - a['y']))


def najblizszy(lista, ref, r_max):
    """Najbliższy obiekt do ref w promieniu r_max (przy remisie pierwszy)."""
    naj, dn = None, None
    for o in lista:
        d = odleglosc(ref, o)
        if d <= r_max and (dn is None or d < dn): naj, dn = o, d
    return naj


def wrogie_klucze(stan, strona):
    return [x for x in stan['klucze'] if x['strona'] != strona and x['stan'] == 'w_locie']


def widzi(stan, k, o):
    """Czy klucz zna położenie obiektu: maszyny z własnymi sensorami — tak; TIE — w promieniu radaru krótkiego zasięgu
    albo z danych okrętu matki (w bitwie), gdy obiekt jest w zasięgu jego radaru."""
    if k['typ'] not in BEZ_SENSOROW or odleglosc(k, o) <= RADAR_WLASNY_KM: return True
    mac = macierzysta(stan, k)
    return mac is not None and odleglosc(mac, o) <= RADAR_MATKI_KM


def cel_klucza(stan, k):
    """Cel klucza z rozkazu → (cel, promień km, rodzaj): atak — klucze wroga w 12 km, inaczej najbliższy okręt (2 km);
    skan — okręt (10 km); ochrona / patrol — klucze wroga w 8 / 15 km od okrętu matki, inaczej krąg 3 / 8 km wokół
    niego; powrót — okręt matka (lądowanie). Pościg za kluczem: 0,3 km za celem. Klucz bez okrętu matki atakuje."""
    mac = macierzysta(stan, k)
    r = k['rozkaz']
    if r == 'powrot' and mac is not None: return mac, 0.0, 'powrot'
    wk = [t for t in wrogie_klucze(stan, k['strona']) if widzi(stan, k, t)]
    wjw = [j for j in wrogowie(stan, k['strona']) if widzi(stan, k, j)]
    if r == 'nalot':
        wj = wjw
        if wj: return min(wj, key=lambda j: odleglosc(k, j)), STALE['dystans_ataku_km'], 'okret'
    if r in ('atak', 'nalot') or mac is None:
        kt = najblizszy(wk, k, STALE['zasieg_walki_atak_km'])
        if kt is not None: return kt, STALE['ogon_km'], 'klucz'
        wj = wjw
        if wj: return min(wj, key=lambda j: odleglosc(k, j)), STALE['dystans_ataku_km'], 'okret'
        # nic nie widać: cel domyślny z danych wywiadu — najbliższy okręt wroga, potem klucz wroga, potem okręt matka
        wj = wrogowie(stan, k['strona'])
        if wj: return min(wj, key=lambda j: odleglosc(k, j)), STALE['dystans_ataku_km'], 'okret'
        kt = najblizszy(wrogie_klucze(stan, k['strona']), k, 1e9)
        if kt is not None: return kt, STALE['ogon_km'], 'klucz'
        if mac is not None: return mac, STALE['promien_ochrony_km'], 'macierzysta'
        return None, 0.0, None
    if r == 'skan':
        wj = wjw
        if wj: return min(wj, key=lambda j: odleglosc(k, j)), STALE['promien_skanu_km'], 'okret'
    zasieg = STALE['zasieg_walki_patrol_km'] if r == 'patrol' else STALE['zasieg_walki_ochrona_km']
    kt = najblizszy(wk, mac, zasieg)
    if kt is not None: return kt, STALE['ogon_km'], 'klucz'
    return mac, STALE['promien_patrolu_km'] if r == 'patrol' else STALE['promien_ochrony_km'], 'macierzysta'


def celnosc(theta_rad, r_km, przekroj):
    """MECHANIKA 4.3: p = 1 − exp(−A / (2π σ²)), σ = R · θ_eff, w granicach p_min … p_maks."""
    sigma = max(0.05, r_km) * 1000 * theta_rad
    return min(STALE['p_maks'], max(STALE['p_min'], 1 - math.exp(-przekroj / (2 * math.pi * sigma * sigma))))


def traf_maszyne(stan, tik, t, hz, kan, i, sprawca):
    """Trafienie maszyny klucza t bronią o Hz: osłona maszyny przepuszcza 1 − osłona/Hz (TIE bez osłon — wszystko),
    zatrzymane trafienia nasycają osłonę; maszyna ginie z prawdopodobieństwem przepuszczone + zatrzymane · nasycenie."""
    osl = MASZ[t['typ']]['oslona_hz']
    frac = 1.0 if osl == 0 else max(0.0, 1 - osl / hz)
    if frac < 1: t['oslona_pkt'] = min(1.0, t['oslona_pkt'] + STALE['oslona_nasycenie'] * (1 - frac))
    if los(stan['ziarno'], tik, 'zniszcz', kan, i) < frac + (1 - frac) * t['oslona_pkt']:
        t['maszyny'] -= 1
        stan['zdarzenia'].append((tik, 'zestrzelenie', sprawca, t['strona'], t['nr'], t['maszyny']))
        if t['maszyny'] <= 0:
            t['stan'] = 'stracony'
            stan['zdarzenia'].append((tik, 'klucz_stracony', t['strona'], t['nr'], t['typ']))


def strzal_w_klucz(stan, tik, k, t):
    """Salwa klucza k w klucz t: każda maszyna strzela; rozrzut zależy od geometrii — na ogonie (zgodne kursy) ×0,5,
    w przecięciu (90°) ×2,5 — i od figur obu kluczy (unik celu, celność własna)."""
    m, mt = MASZ[k['typ']], MASZ[t['typ']]
    ft, fk = figura(stan, kanal_klucza(t)), figura(stan, kanal_klucza(k))
    geom = 0.5 + 2 * abs(math.sin(radiany(t['kurs'] - k['kurs'])))
    theta = m['theta'] / 1000 * geom * (ROZK[ft]['theta'] if ft and ROZK[ft]['theta'] else 1) * (ROZK[fk]['theta_wl'] if fk and ROZK[fk]['theta_wl'] else 1)
    mac = next((x for x in stan['jednostki'] if x['nr'] == k['macierzysta']), None)
    if mac is not None and nast(mac, 'sync_mysl_sens') >= 0.5: theta *= 0.9
    p = celnosc(theta, odleglosc(k, t), mt['przekroj'])
    kan = kanal_klucza(k)
    for i in range(k['maszyny']):
        if t['maszyny'] <= 0: break
        if los(stan['ziarno'], tik, 'strzal', kan, i) < p:
            traf_maszyne(stan, tik, t, hz_klucza(k), kan, i, f'{k["strona"]} {k["nr"]}')


def punkt_na_kadlubie(j, x, y):
    """Położenie punktu kadłuba (x, y w długościach okrętu, dziób −y) w polu bitwy."""
    dl = PROFILE[j['profil']]['dlugosc_m'] / 1000
    s, c = math.sin(radiany(j['kurs'])), math.cos(radiany(j['kurs']))
    return {'x': j['x'] + s * (-y) * dl + c * x * dl, 'y': j['y'] + c * (-y) * dl - s * x * dl}


def cel_baterii(stan, j, dzialo, luk_s, luk_p, zasieg):
    """Najbliższy wrogi okręt w zasięgu i łuku baterii (łuk od położenia baterii, względem dziobu okrętu)."""
    cel, dn = None, None
    for w in wrogowie(stan, j['strona']):
        d = odleglosc(dzialo, w)
        if d > zasieg: continue
        if abs(norm180(norm180(namiar(dzialo, w) - j['kurs']) - luk_s)) > luk_p: continue
        if w['nr'] == j.get('cel_ognia'): return w, d          # cel wskazany z Kontroli ognia — pierwszeństwo
        if dn is not None and d >= dn: continue
        cel, dn = w, d
    return cel, dn


def ogien_okretu(stan, tik, j):
    """Baterie okrętu (MECHANIKA 4.1): salwa co ładowanie tików, przesunięta o numer baterii. Turbolaser — każda lufa
    trafia z celnością 4.3 (przekrój okrętu), energia trafionych luf = e_żyły × Hz baterii przez filtr osłon celu.
    Jon — energia trafień obciąża generator osłon celu (brown-out), bez uszkodzeń kadłuba i żył."""
    b = PROFILE[j['profil']].get('baterie') or {}
    def n(k): return nast(j, k)
    for nr, x, y, luk_s, luk_p, zasieg, theta, hz in TL.get(j['profil'], []):
        # tempo: bilans mocy × tempo z Kontroli ognia × wzmocnienie ognia × ogień zaporowy; przy 1 — ładowanie z profilu;
        # prawa burta przesunięta o opóźnienie (ułamek odstępu salw)
        bb = 'l' if x < 0 else 'p'
        tryb = n('tryb_' + bb)
        rate = zas(j, 'tl_' + bb) * tempo_ognia(j, bb) * (1.25 if wzm(stan, j, 'ogien_' + bb) else 1.0) * (1.5 if tryb >= 0.75 else 1.0)
        interwal = max(2, int(zaokr(b.get('ladowanie_tiki', 6) / max(0.05, rate))))
        faza = tik + nr + (int(zaokr(n('opoznienie') * interwal)) if bb == 'p' else 0)
        if faza % interwal: continue
        dzialo = punkt_na_kadlubie(j, x, y)
        cel, dn = cel_baterii(stan, j, dzialo, luk_s, luk_p, zasieg)
        if cel is None: continue
        gen = generator_od_strony(cel, dzialo['x'], dzialo['y'])
        # Hz baterii: maks. zakresu; z synchronizacją sensorów — najniższy stopień ≥ 1,5 × Hz osłony celu (w zakresie)
        hz_b = stopien_baterii(hz, n('tl_hz_max_' + bb))
        if n('sync_tl_sens') >= 0.5:
            hz_b = max(stopien_baterii(hz, n('tl_hz_min_' + bb)), min(hz_b, stopien_baterii(hz, min(1.0, 1.5 * gen['hz_osiagane'] / hz))))
        # celność: niższe Hz — skupiona wiązka; rozrzut (wąski na cel stojący, szeroki na manewrujący), elewacja,
        # synchronizacje, tryb pojedynczy / zaporowy
        r = n('rozrzut_' + bb)
        th = theta * (0.5 + 0.5 * hz_b / hz) * ((0.5 + r) if cel['v'] <= 0.05 else (1.5 - r)) * (1 + abs(n('elewacja') - 0.5))
        if n('sync_tl_ko_' + bb) >= 0.5: th *= 0.85
        if n('sync_ko_sens') >= 0.5: th *= 0.9
        if nast(cel, 'zaklocanie') >= 0.5: th *= 1.25
        th *= mnoznik_platformy(stan, j)
        pojedynczy = 0.25 <= tryb < 0.75
        if pojedynczy: th *= 0.7
        if tryb >= 0.75: th *= 1.5
        p = celnosc(th / 1000, dn, PROFILE[cel['profil']]['dlugosc_m'] ** 2 * STALE['przekroj_okretu'])
        kan = f'tl:{j["nr"]}:{nr}'
        lufy = max(1, b.get('lufy', 0) // 4) if pojedynczy else b.get('lufy', 0)
        trafienia = sum(1 for i in range(lufy) if los(stan['ziarno'], tik, 'tl', kan, i) < p)
        if trafienia:
            mn = (1.25 if wzm(stan, j, 'tl_' + bb) else 1.0) * (1.15 if wzm(stan, j, 'salwa') else 1.0) * (1.1 if n('sync_tl_os_' + bb) >= 0.5 else 1.0)
            trafienie_oslony(stan, tik, cel, gen, trafienia * W_ENERGIA_ZYLY * hz_b * mn, hz_b, f'{j["nazwa"]} TL {nr}', kan, dzialo['x'], dzialo['y'])
    energia, lufy, ladowanie = STALE['jon'].get(j['profil'], (0, 0, 8))
    for nr, x, y, luk_s, luk_p, zasieg, theta, hz in JON.get(j['profil'], []):
        if (tik + nr) % max(2, int(zaokr(ladowanie / max(0.05, zas(j, 'jon') * (1.5 if wzm(stan, j, 'jony') else 1.0))))): continue
        dzialo = punkt_na_kadlubie(j, x, y)
        cel, dn = cel_baterii(stan, j, dzialo, luk_s, luk_p, zasieg)
        if cel is None: continue
        p = celnosc(theta / 1000, dn, PROFILE[cel['profil']]['dlugosc_m'] ** 2 * STALE['przekroj_okretu'])
        kan = f'jon:{j["nr"]}:{nr}'
        trafienia = sum(1 for i in range(lufy) if los(stan['ziarno'], tik, 'jon', kan, i) < p)
        if trafienia and n('jon_obezwl') >= 0.5:
            # obezwładnienie: wiązka w zasilanie celu — ciepło jego rdzenia (dławienie), osłony bez obciążenia
            cel['cieplo'] = min(150.0, cel['cieplo'] + trafienia * 3 * (0.5 + n('jon_moc')))
        elif trafienia:
            gen = generator_od_strony(cel, dzialo['x'], dzialo['y'])
            gen['obciazenie'] += trafienia * energia * (0.5 + n('jon_moc'))
            c = pojemnosc(PROFILE[cel['profil']]) * mnoznik_oslony(cel, gen)
            if gen['obciazenie'] > c:
                gen['rykoszet'] += gen['obciazenie'] - c
                gen['obciazenie'] = c


def obrona_punktowa(stan, tik, j):
    """Działka obrony punktowej okrętu (pozycje i łuki z dane/uzbrojenie.py): każde strzela raz na tik do najbliższego
    klucza wroga w swoim zasięgu i łuku (łuk liczony od położenia działka, względem dziobu okrętu)."""
    dl = PROFILE[j['profil']]['dlugosc_m'] / 1000
    s, c = math.sin(radiany(j['kurs'])), math.cos(radiany(j['kurs']))
    for nr, x, y, luk_s, luk_p, zasieg, theta, hz in PD.get(j['profil'], []):
        # grupa działek (Turbolasery): kąt pokrycia rozszerza łuk kosztem celności, IFF i wzmocnienie poprawiają celność
        g = grupa_pd(nr, x, y)
        kat = 0.5 + nast(j, 'pd_kat_' + g)
        luk_p = luk_p * kat
        theta = theta * kat
        if nast(j, 'sync_tl_iff_' + ('l' if x < 0 else 'p')) >= 0.5: theta *= 0.8
        if nast(j, 'sync_ko_iff') >= 0.5: theta *= 0.9
        if nast(j, 'sync_radar_ko') >= 0.5: theta *= 0.9
        if nast(j, 'sync_radar_iff') >= 0.5: theta *= 0.95
        if wzm(stan, j, 'pd_' + g): theta /= 1.5
        gx, gy = j['x'] + s * (-y) * dl + c * x * dl, j['y'] + c * (-y) * dl - s * x * dl
        dzialko = {'x': gx, 'y': gy}
        cel, dn = None, None
        for t in wrogie_klucze(stan, j['strona']):
            d = odleglosc(dzialko, t)
            if d > zasieg or (dn is not None and d >= dn): continue
            if abs(norm180(norm180(namiar(dzialko, t) - j['kurs']) - luk_s)) > luk_p: continue
            cel, dn = t, d
        if cel is None: continue
        ft = figura(stan, kanal_klucza(cel))
        p = celnosc(theta / 1000 * (ROZK[ft]['theta'] if ft and ROZK[ft]['theta'] else 1), dn, MASZ[cel['typ']]['przekroj'])
        kan = f'pd:{j["nr"]}:{nr}'
        if los(stan['ziarno'], tik, 'pd', kan) < p:
            traf_maszyne(stan, tik, cel, hz, kan, 0, f'{j["nazwa"]} PD')


def wrogowie(stan, strona):
    return [j for j in stan['jednostki'] if j['strona'] != strona and j['stan'] == 'w_bitwie']


def odleglosc(a, b):
    return math.hypot(a['x'] - b['x'], a['y'] - b['y'])


def hz_klucza(k):
    return MASZ[k['typ']]['hz'] * (1 + STALE['dryf_k'][k['ranga']] * k['moc'])


# ------------------------------------------------------------------------------------------------------------ zasilanie
# Nastawy konsol Osłony i Reaktory (MECHANIKA 11.4) — suwaki 0…1 i przełączniki 0/1; wartości domyślne nie zmieniają
# przebiegu bitwy (bilans mocy ma zapas, osłony bez przecieków). Ustawia je rozkaz nastawa / synchronizuj / przelacz
# (wartość „kod=liczba”), grupy wraca do domyślnych rozkaz wyrownaj; wzmocnienia to rozkazy wzmocnij (12 s działania).
SEKTORY = ('dziob', 'gora_l', 'gora_p', 'dol_l', 'dol_p', 'rufa', 'hangar')
GRUPY_PD = ('dziob', 'przod_l', 'przod_p', 'bok_l', 'bok_p', 'dol', 'rufa')
# wzmocnienia uzbrojenia (Turbolasery, Kontrola ognia): +4 do poboru baterii i +0,2 ciepła na tik każde
WZM_BRONI = ('tl_l', 'tl_p', 'ogien_l', 'ogien_p', 'salwa', 'jony') + tuple('pd_' + g for g in GRUPY_PD)
# wzmocnienia napędu (Silniki, Nawigacja): +3 do poboru silników i +0,2 ciepła na tik każde
WZM_SILNIKOW = ('gl_l', 'gl_p', 'gl_s', 'man_lg', 'man_pg', 'man_ld', 'man_pd', 'man_all', 'skret_l', 'skret_p')
# wzmocnienia sensorów i radaru: +2 do poboru systemów podstawowych i +0,2 ciepła na tik każde
WZM_SENSOROW = ('radar_tx', 'radar_rx', 'skan_nad', 'skan_rozdz', 'grawitacja', 'blokada', 'tasma_l', 'tasma_p')
WZMOCNIENIA = SEKTORY + ('glowny', 'generator', 'uzbr', 'przedni', 'zapas', 'burta_l', 'burta_p') + WZM_BRONI + WZM_SILNIKOW + WZM_SENSOROW
SYNC_OSLON = ('sync_os_tl_l', 'sync_os_tl_p', 'sync_os_pr_l', 'sync_os_pr_p', 'sync_rufa_sg_l', 'sync_rufa_sg_p', 'sync_rufa_sm_l', 'sync_rufa_sm_p')
NASTAWY = {
    **{'pokr_' + s: 1.0 for s in SEKTORY},
    **{k: 0.0 for k in SYNC_OSLON},
    'przydz_tl': 0.5, 'przydz_jon': 0.5, 'przydz_sil': 0.5, 'przydz_os': 1.0, 'przydz_hiper': 0.0,
    'moc_przedni': 0.5, 'moc_zapas': 0.5, 'czest_gen': 0.5, 'napiecie_gen': 0.5, 'gestosc': 0.5, 'pole_komory': 0.5,
    'czest_bazowa': 0.5, 'rez_salwa': 0.0, 'rez_awaryjna': 0.0, 'uzbr_min': 0.0, 'uzbr_max': 1.0, 'gen_min': 0.0, 'gen_max': 1.0,
    'sync_przedni_pr': 0.0, 'sync_zapas_sil': 0.0, 'sync_uzbr_tl_l': 0.0, 'sync_uzbr_tl_p': 0.0, 'sync_gen_sil': 0.0, 'sync_gen_os': 0.0,
    'sync_uzbr_glowny': 0.0, 'sync_glowny_pom': 0.0, 'sync_glowny_gen': 0.0, 'sync_gen_glowny': 0.0,
    'przel_sil_zapas': 0.0, 'przel_podst_przedni': 0.0,
    # Turbolasery: Hz baterii (zakres jako ułamek liczby żył baterii), kąt pokrycia grup PDLC, synchronizacje
    'tl_hz_max_l': 1.0, 'tl_hz_min_l': 0.0, 'tl_hz_max_p': 1.0, 'tl_hz_min_p': 0.0,
    **{'pd_kat_' + g: 0.5 for g in GRUPY_PD},
    'sync_tl_iff_l': 0.0, 'sync_tl_iff_p': 0.0, 'sync_tl_os_l': 0.0, 'sync_tl_os_p': 0.0, 'sync_tl_ko_l': 0.0, 'sync_tl_ko_p': 0.0,
    'sync_tl_sens': 0.0,
    # Kontrola ognia: tempo (z zakresem), rozrzut, tryb (0 salwa, 0,5 pojedynczy, 1 zaporowy), czas ognia zaporowego,
    # opóźnienie prawej baterii, elewacja, działa jonowe (natężenie, obezwładnienie), synchronizacje
    'tempo_l': 0.5, 'tempo_min_l': 0.0, 'tempo_max_l': 1.0, 'tempo_p': 0.5, 'tempo_min_p': 0.0, 'tempo_max_p': 1.0,
    'rozrzut_l': 0.5, 'rozrzut_p': 0.5, 'tryb_l': 0.0, 'tryb_p': 0.0, 'czas_zapor': 0.5, 'opoznienie': 0.0, 'elewacja': 0.5,
    'jon_moc': 0.5, 'jon_obezwl': 0.0, 'sync_ko_iff': 0.0, 'sync_ko_sens': 0.0,
    'zapor_do_l': 0.0, 'zapor_do_p': 0.0,   # tik końca ognia zaporowego — ustawia silnik, nie konsola
    # Silniki: zakres ciągu silników głównych burt, ciąg środkowego, ciąg i wychylenie dysz manewrowych, trymy, synchronizacje
    'sil_max_l': 1.0, 'sil_min_l': 0.0, 'sil_max_p': 1.0, 'sil_min_p': 0.0, 'sil_srodek': 1.0,
    'man_lg': 0.5, 'man_pg': 0.5, 'man_ld': 0.5, 'man_pd': 0.5, 'dysza_lg': 0.5, 'dysza_pg': 0.5, 'dysza_ld': 0.5, 'dysza_pd': 0.5,
    'trym_poch': 0.5, 'trym_odch': 0.5, 'sync_man_l': 0.0, 'sync_man_p': 0.0, 'sync_gl_l_s': 0.0, 'sync_gl_p_s': 0.0,
    # Nawigacja: zakres prędkości kątowej skrętu w każdą stronę, moc i kąt dysz manewrowych burt, przechył, synchronizacje
    'skret_max_l': 1.0, 'skret_min_l': 0.0, 'skret_max_p': 1.0, 'skret_min_p': 0.0, 'nav_man_l': 0.5, 'nav_man_p': 0.5,
    'katdysz_l': 0.5, 'katdysz_p': 0.5, 'przechyl': 0.5, 'sync_nav_l': 0.0, 'sync_nav_p': 0.0,
    # Radar i Sensory — w silniku: tryb aktywny (emisja; bierny oszczędza moc, sensory bierne mierzą Hz tylko do 60 km),
    # zakłócanie (θ baterii wroga × 1,25, koszt mocy i ciepła), moc nadajnika i zasięg skanu nadprzestrzeni (pobór),
    # synchronizacje radaru z obroną punktową; reszta to obraz na konsolach (zasięg, czułość, szum, alarmy, pomiar Hz)
    'radar_aktywny': 1.0, 'sens_aktywny': 1.0, 'zaklocanie': 0.0, 'radar_moc': 0.5, 'sens_nad': 0.5,
    'sync_radar_ko': 0.0, 'sync_radar_iff': 0.0, 'sync_sens_nav': 0.0,
    'radar_zasieg_max': 1.0, 'radar_zasieg_min': 0.0, 'radar_rx': 0.5, 'radar_czul_max': 1.0, 'radar_czul_min': 0.5,
    'prog_zblizenia': 0.3, 'prog_kolizji': 0.3333, 'radar_tempo': 0.3333, 'radar_tlumienie': 0.0, 'radar_zrodlo': 0.0,
    'sens_pasmo': 0.5, 'sens_hz_max': 1.0, 'sens_hz_min': 0.0, 'sens_integr': 0.5, 'sens_probki': 0.5, 'skan_min': 0.0, 'skan_poziom': 0.0,
    # Myśliwce: tempo taśmociągu (z zakresem), alarm (0 normalny, 0,5 żółty, 1 czerwony), załoga wezwana, liczba kluczy
    # do poderwania (z zakresem) i skład (kolejność startu: kadeci … asy) — dwa ostatnie tylko konsola; synchronizacja z sensorami
    'tasma_tempo': 0.5, 'tasma_min': 0.0, 'tasma_max': 1.0, 'alarm_mysl': 0.0, 'zaloga': 0.0,
    'ile_kluczy': 0.0, 'ile_min': 0.0, 'ile_max': 1.0, 'sklad': 0.5, 'sync_mysl_sens': 0.0,
    # Grawitacja: pole pokładowe (z zakresem) i jego zasilanie, blokada Anchor/Lock (moc z zakresem, rezerwa), synchronizacje
    'pole': 0.5, 'pole_min': 0.0, 'pole_max': 1.0, 'grav_zasil': 0.5, 'blokada': 0.0, 'blokada_od': 0.0,
    'blokada_moc': 0.5, 'blokada_min': 0.0, 'blokada_max': 1.0, 'blokada_rez': 0.0, 'sync_grav_reak': 0.0, 'sync_grav_nav': 0.0,
}
GRUPY_WYROWNANIA = {
    'baterie': ('przydz_tl', 'przydz_jon', 'rez_salwa'),
    'reaktory': ('moc_przedni', 'moc_zapas', 'uzbr_min', 'uzbr_max', 'gen_min', 'gen_max'),
    'dystrybucja': ('przydz_tl', 'przydz_jon', 'przydz_sil', 'przydz_os', 'przydz_hiper', 'rez_salwa', 'rez_awaryjna'),
    'oslony': tuple('pokr_' + s for s in SEKTORY),
    'turbolasery': ('tl_hz_max_l', 'tl_hz_min_l', 'tl_hz_max_p', 'tl_hz_min_p') + tuple('pd_kat_' + g for g in GRUPY_PD),
    'ogien': ('tempo_l', 'tempo_min_l', 'tempo_max_l', 'tempo_p', 'tempo_min_p', 'tempo_max_p', 'rozrzut_l', 'rozrzut_p', 'opoznienie', 'elewacja'),
    'silniki': ('sil_max_l', 'sil_min_l', 'sil_max_p', 'sil_min_p', 'sil_srodek', 'man_lg', 'man_pg', 'man_ld', 'man_pd',
                'dysza_lg', 'dysza_pg', 'dysza_ld', 'dysza_pd', 'trym_poch', 'trym_odch'),
    'nawigacja': ('skret_max_l', 'skret_min_l', 'skret_max_p', 'skret_min_p', 'nav_man_l', 'nav_man_p', 'katdysz_l', 'katdysz_p', 'przechyl'),
    'sensory': ('sens_pasmo', 'sens_hz_max', 'sens_hz_min', 'sens_integr', 'sens_probki', 'sens_nad', 'skan_min'),
    'grawitacja': ('pole', 'pole_min', 'pole_max', 'grav_zasil'),
}


def interwal_tasmy(stan, k):
    """Odstęp startów z taśmociągu okrętu matki klucza (s): tasma_s / (tempo w zakresie × wzmocnienie pokładu klucza
    (1–15 lewy, dalej prawy) × załoga wezwana × alarm żółty / czerwony)."""
    j = next((x for x in stan['jednostki'] if x['nr'] == k['macierzysta']), None)
    if j is None: return STALE['tasma_s']
    def n(c): return nast(j, c)
    mult = 0.5 + min(max(n('tasma_tempo'), n('tasma_min')), n('tasma_max'))
    if wzm(stan, j, 'tasma_l' if k['nr'] <= 15 else 'tasma_p'): mult *= 2.0
    if n('zaloga') >= 0.5: mult *= 1.25
    a = n('alarm_mysl')
    if a >= 0.75: mult *= 1.5
    elif a >= 0.25: mult *= 1.25
    return max(5, int(zaokr(STALE['tasma_s'] / mult)))


def stan_blokady(stan, j):
    """Blokada Anchor/Lock: 'zwolniona', 'stabilizowanie' (10 / (0,5 + moc) s od założenia) albo 'utrzymana'
    (od razu przy wzmocnionym polu blokady)."""
    if nast(j, 'blokada') < 0.5: return 'zwolniona'
    moc = min(max(nast(j, 'blokada_moc'), nast(j, 'blokada_min')), nast(j, 'blokada_max'))
    if wzm(stan, j, 'blokada') or stan['tik'] - nast(j, 'blokada_od') >= 10 / (0.5 + moc): return 'utrzymana'
    return 'stabilizowanie'


def mnoznik_platformy(stan, j):
    """Celność baterii okrętu od pokładu: blokada utrzymana (stabilna platforma) × 0,9 (× 0,85 wzmocniona), pole
    pokładowe daleko od 1 g × (1 + 0,2 · |pole − 0,5|), słabe zasilanie grawitacji bez synchronizacji z reaktorem × 1,1,
    wzmocniona grawitacja pokładowa × 0,95."""
    def n(c): return nast(j, c)
    f = 1 + 0.2 * abs(min(max(n('pole'), n('pole_min')), n('pole_max')) - 0.5)
    if stan_blokady(stan, j) == 'utrzymana': f *= 0.85 if wzm(stan, j, 'blokada') else 0.9
    if n('grav_zasil') < 0.3 and n('sync_grav_reak') < 0.5: f *= 1.1
    if wzm(stan, j, 'grawitacja'): f *= 0.95
    return f


def mnoznik_skretu(stan, j, b):
    """Prędkość kątowa skrętu w stronę b ('l'/'p') względem profilu: ciąg silników manewrowych burty (Silniki) × moc
    manewrowych burty (Nawigacja), w zakresie suwaków skrętu; wzmocnienia, synchronizacje i przechył w stronę skrętu."""
    def n(k): return nast(j, k)
    f = (0.5 + (n('man_' + b + 'g') + n('man_' + b + 'd')) / 2) * (0.5 + n('nav_man_' + b))
    f = min(max(f, n('skret_min_' + b)), n('skret_max_' + b))
    if wzm(stan, j, 'skret_' + b): f *= 1.5
    if wzm(stan, j, 'man_' + b + 'g'): f *= 1.25
    if wzm(stan, j, 'man_' + b + 'd'): f *= 1.25
    if wzm(stan, j, 'man_all'): f *= 1.3
    if n('sync_man_' + b) >= 0.5: f *= 1.1
    if n('sync_nav_' + b) >= 0.5: f *= 1.1
    pr = n('przechyl')
    if pr < 0.25: f *= 1.15 if b == 'l' else 0.9
    elif pr > 0.75: f *= 1.15 if b == 'p' else 0.9
    return f


def grupa_pd(nr, x, y):
    """Grupa działek obrony punktowej (konsola Turbolasery): dziób, przód i bok burty, dół (co drugie działko śródokręcia), rufa."""
    b = 'l' if x < 0 else 'p'
    if y < -0.38: return 'dziob'
    if y < -0.1: return 'przod_' + b
    if y < 0.25: return 'dol' if nr % 2 == 0 else 'bok_' + b
    return 'rufa'


def tempo_ognia(j, b):
    """Mnożnik tempa salw burty z konsoli Kontrola ognia: 0,5 + tempo (w zakresie min…maks.)."""
    return 0.5 + min(max(nast(j, 'tempo_' + b), nast(j, 'tempo_min_' + b)), nast(j, 'tempo_max_' + b))


def stopien_baterii(n, frac):
    """Stopień natywny baterii (n żył) najbliższy w górę ułamkowi frac · n, co najmniej 1."""
    return stopien_w_gore(n, max(1.0, frac * n)) or n


def nast(j, k):
    return j['nastawy'].get(k, NASTAWY[k])


def wzm(stan, j, cel):
    """1.0, gdy działa wzmocnienie celu (rozkaz wzmocnij na kanale „nr:wzm_cel”), inaczej 0.0."""
    return 1.0 if f'{j["nr"]}:wzm_{cel}' in stan.get('_dziala', ()) else 0.0


def zas(j, k):
    return j.get('zasil', {}).get(k, 1.0)


def koszt_sensorow(km):
    return math.floor(0.245 * (km / 1000) ** 1.618 * 100 + 0.5) / 100


def zasilanie(stan, tik, j):
    """Bilans mocy okrętu na tik (MECHANIKA 11.4; jednostki: % mocy nominalnej rdzenia). Źródła: rdzeń (gęstość
    hipermaterii, sprawność generatora mocy, wzmocnienia, dławienie), reaktor uzbrojenia (najpierw baterie), przedni
    reaktor (do puli albo tylko systemy podstawowe), zapasowy (silniki po przełączeniu, połowa do puli po synchronizacji).
    Niedobór dzielony proporcjonalnie; synchronizacje generatora z osłonami / silnikami dają im pierwszeństwo, rezerwa
    na salwę — bateriom. Wynik (mnożniki) w j['zasil']; ciepło rdzenia i dławienie w j['cieplo'], j['dlawienie']."""
    def n(k): return nast(j, k)
    def w(c): return wzm(stan, j, c)
    p = PROFILE[j['profil']]
    for b in ('l', 'p'):                    # ogień zaporowy trwa czas_zapor (10…60 s), potem wraca salwa
        if n('tryb_' + b) >= 0.75 and tik >= n('zapor_do_' + b):
            j['nastawy']['tryb_' + b] = 0.0
            stan['zdarzenia'].append((tik, 'zaporowy', j['nazwa'], b))
    spraw = 1 - 0.2 * (abs(n('czest_gen') - 0.5) + abs(n('napiecie_gen') - 0.5)) + 0.03 * n('sync_glowny_gen') + 0.03 * n('sync_gen_glowny')
    glowny = 100 * (0.8 + 0.4 * n('gestosc')) * (1 + 0.15 * w('glowny')) * (1 + 0.1 * w('generator')) * spraw * (0.7 if j['dlawienie'] else 1.0)
    uzbr = 15 * (1 + 0.3 * w('uzbr'))
    przedni = 20 * n('moc_przedni') * (1 + 0.3 * w('przedni'))
    zapas = 20 * n('moc_zapas') * (1 + 0.3 * w('zapas'))
    # odbiorcy
    os = 0.0
    for g in j['generatory']: os += g['hz_osiagane'] * moc_zyly(p)
    os = 100 * os / p['reaktor']['moc_W'] if j['generatory'] else 0.0
    for s in SEKTORY: os += 4 * w(s) + 2 * n('pokr_' + s)
    for k in SYNC_OSLON: os += n(k)
    os_dozw = min(os, 100 * n('przydz_os'))
    tl = 20 * n('przydz_tl') * ((tempo_ognia(j, 'l') + tempo_ognia(j, 'p')) / 2) + 5 * (w('burta_l') + w('burta_p'))
    for c in WZM_BRONI: tl += 4 * w(c)
    tl += 2 * (n('sync_tl_os_l') + n('sync_tl_os_p'))
    jon = 10 * n('przydz_jon') * (0.5 + n('jon_moc'))
    sil = 15 * n('przydz_sil')
    for c in WZM_SILNIKOW: sil += 3 * w(c)
    podst = koszt_sensorow(j.get('zasieg_sensorow', 1000)) + 10 * n('przydz_hiper') + 3 * n('sync_przedni_pr') + 4 * n('pole_komory')
    podst += 4 * (n('radar_moc') - 0.5) + 4 * (n('sens_nad') - 0.5) - 2 * (1 - n('radar_aktywny')) - 2 * (1 - n('sens_aktywny')) + 6 * n('zaklocanie')
    for c in WZM_SENSOROW: podst += 2 * w(c)
    moc_bl = min(max(n('blokada_moc'), n('blokada_min')), n('blokada_max'))
    podst += 10 * moc_bl * n('blokada') + 4 * (n('pole') - 0.5) + 4 * (n('grav_zasil') - 0.5) * (0.5 if n('sync_grav_reak') >= 0.5 else 1.0) + 10 * n('blokada_rez')
    podst = max(0.0, podst)
    przel_sil, przel_podst = n('przel_sil_zapas') >= 0.5, n('przel_podst_przedni') >= 0.5
    # reaktor uzbrojenia: najpierw baterie (produkcja w zakresie min…maks), nadwyżka do puli po synchronizacji z rdzeniem
    bron = tl + jon
    z_uzbr = max(min(bron, uzbr * n('uzbr_max')), uzbr * n('uzbr_min'))
    z_uzbr_bron = min(z_uzbr, bron)
    c = pojemnosc(p) if j['generatory'] else 0.0
    alarm = any(g['obciazenie'] > 0.9 * c for g in j['generatory'])
    pula = glowny * n('gen_max') - (0.0 if alarm else 20 * n('rez_awaryjna'))
    if not przel_podst: pula += przedni
    if not przel_sil and n('sync_zapas_sil') >= 0.5: pula += 0.5 * zapas
    if n('sync_uzbr_glowny') >= 0.5: pula += z_uzbr - z_uzbr_bron
    pula = max(0.0, pula)
    pop = {'os': os_dozw, 'bron': bron - z_uzbr_bron, 'sil': 0.0 if przel_sil else sil, 'podst': 0.0 if przel_podst else podst}
    dost = {'os': 0.0, 'bron': 0.0, 'sil': 0.0, 'podst': 0.0}
    reszta = pula
    pierwsze = [k for k, s in (('os', 'sync_gen_os'), ('sil', 'sync_gen_sil')) if n(s) >= 0.5]
    for k in pierwsze:
        d = min(pop[k], reszta)
        dost[k] = d
        reszta -= d
    d = min(pop['bron'], 10 * n('rez_salwa'), reszta)
    dost['bron'] += d
    reszta -= d
    pozostale = [k for k in ('os', 'bron', 'sil', 'podst') if k not in pierwsze]
    potrz = 0.0
    for k in pozostale: potrz += pop[k] - dost[k]
    if potrz <= reszta:
        for k in pozostale: dost[k] = pop[k]
    else:
        f = reszta / potrz
        for k in pozostale: dost[k] += (pop[k] - dost[k]) * f
    def frac(k): return dost[k] / pop[k] if pop[k] > 0 else 1.0
    f_os = frac('os') * (os_dozw / os if os > 0 else 1.0)
    f_bron = (z_uzbr_bron + dost['bron']) / bron if bron > 0 else 1.0
    f_sil = (min(1.0, zapas / sil) if sil > 0 else 1.0) if przel_sil else frac('sil')
    f_podst = (min(1.0, przedni / podst) if podst > 0 else 1.0) if przel_podst else frac('podst')
    zas_ = {'os': f_os, 'bron': f_bron, 'sil': f_sil, 'podst': f_podst, 'jon': 10 * n('przydz_jon') / 5 * f_bron,
            'v': min(1.2, 15 * n('przydz_sil') / 7.5 * f_sil),
            'zrodla': pula, 'zapotrzebowanie': pop['os'] + pop['bron'] + pop['sil'] + pop['podst'], 'uzyte': dost['os'] + dost['bron'] + dost['sil'] + dost['podst'],
            'pobor_os': dost['os'], 'pobor_bron': dost['bron'] + z_uzbr_bron, 'pobor_sil': dost['sil'], 'pobor_podst': dost['podst']}
    for b in ('l', 'p'):
        zas_['tl_' + b] = 20 * n('przydz_tl') / 10 * (1.0 if n('sync_uzbr_tl_' + b) >= 0.5 else f_bron) * (1 + 0.1 * w('burta_' + b))
        zas_['oslona_' + b] = f_os * (1 + 0.08 * (n('sync_os_tl_' + b) + n('sync_os_pr_' + b))) * (1 + 0.1 * w('burta_' + b))
    j['zasil'] = zas_
    # ciepło rdzenia: rośnie przy wykorzystaniu > 85% i we wzmocnieniach, pole komory i synchronizacja z pomocniczymi je tłumią
    wyk = max(n('gen_min'), min(1.0, zas_['uzyte'] / pula)) if pula > 0 else 1.0
    przyrost = (wyk - 0.85) * 2 + 0.5 * w('glowny') + 0.3 * w('generator') + 0.2 * (w('uzbr') + w('przedni') + w('zapas'))
    for s in SEKTORY: przyrost += 0.2 * w(s)
    for c in WZM_BRONI: przyrost += 0.2 * w(c)
    for c in WZM_SILNIKOW: przyrost += 0.2 * w(c)
    for c in WZM_SENSOROW: przyrost += 0.2 * w(c)
    przyrost += 0.1 * n('zaklocanie')
    for b in ('l', 'p'):
        if n('tryb_' + b) >= 0.75: przyrost += 0.3
    if przyrost > 0: przyrost *= (1.5 - n('pole_komory')) * (0.8 if n('sync_glowny_pom') >= 0.5 else 1.0)
    j['cieplo'] = min(150.0, max(0.0, j['cieplo'] + przyrost))
    if not j['dlawienie'] and j['cieplo'] >= 100:
        j['dlawienie'] = True
        stan['zdarzenia'].append((tik, 'dlawienie', j['nazwa'], 'start'))
    elif j['dlawienie'] and j['cieplo'] < 60:
        j['dlawienie'] = False
        stan['zdarzenia'].append((tik, 'dlawienie', j['nazwa'], 'koniec'))


def sektor_trafienia(stan, tik, j, x, y, ziarno_klucz):
    """Sektor osłony, w który trafia strzał z punktu (x, y): dziób, rufa, hangar albo góra / dół burty (wysokość — los)."""
    kat = radiany(j['kurs'])
    dx, dy = x - j['x'], y - j['y']
    wzdl = dx * math.sin(kat) + dy * math.cos(kat)
    w_bok = dx * math.cos(kat) - dy * math.sin(kat)
    r = wzdl / (math.hypot(dx, dy) or 1)
    if r > 0.7: return 'dziob', 'p' if w_bok >= 0 else 'l'
    if r < -0.7: return 'rufa', 'p' if w_bok >= 0 else 'l'
    burta = 'p' if w_bok >= 0 else 'l'
    if los(stan['ziarno'], tik, 'pion', ziarno_klucz) < 0.5: return 'gora_' + burta, burta
    if abs(r) < 0.3: return 'hangar', burta
    return 'dol_' + burta, burta


def mnoznik_oslony(j, gen):
    """Pojemność generatora × bilans mocy osłon (burta generatora: pierwsza połowa listy — lewa)."""
    i = j['generatory'].index(gen)
    return zas(j, 'oslona_' + ('l' if i < max(1, len(j['generatory']) // 2) else 'p'))


def generator_od_strony(j, x, y):
    """Który generator przyjmuje trafienie z punktu (x, y): ISD 2 — burta lewa/prawa; MC80 6 — burta × dziób/śródokręcie/rufa."""
    kat = radiany(j['kurs'])
    dx, dy = x - j['x'], y - j['y']
    wzdl = dx * math.sin(kat) + dy * math.cos(kat)          # + przed dziobem
    w_bok = dx * math.cos(kat) - dy * math.sin(kat)          # + prawa burta
    g = len(j['generatory'])
    burta = 1 if w_bok >= 0 else 0
    if g == 1: return j['generatory'][0]
    if g == 2: return j['generatory'][burta]
    na_burte = g // 2
    odl = math.hypot(dx, dy) or 1
    czesc = min(na_burte - 1, int((1 - (wzdl / odl + 1) / 2) * na_burte))   # 0 = dziób
    return j['generatory'][burta * na_burte + czesc]


def trafienie_oslony(stan, tik, j, gen, energia, hz_l, sprawca, ziarno_klucz, zx, zy):
    """Filtr (3.2), pojemność i rykoszet (3.4), uszkodzenia żył i kadłuba (3.5, W13); sektor osłony (MECHANIKA 11.4):
    przeciek na granicy sektora przy pokryciu < 100%, wzmocnienie sektora, synchronizacje rufy, pojemność z bilansu mocy."""
    p = PROFILE[j['profil']]
    hz_o = hz_skuteczne(gen['zyly'], gen['hz_osiagane']) if gen['hz_osiagane'] else 0
    do_kadluba, do_gen = filtr(energia, hz_o, hz_l) if hz_o else (energia, 0.0)
    sektor, burta = sektor_trafienia(stan, tik, j, zx, zy, ziarno_klucz)
    pokr = nast(j, 'pokr_' + sektor)
    if pokr < 1 and do_gen > 0 and los(stan['ziarno'], tik, 'granica', ziarno_klucz) < 0.2:
        przeciek = do_gen * 0.5 * (1 - pokr)
        do_gen -= przeciek
        do_kadluba += przeciek
    if wzm(stan, j, sektor): do_kadluba *= 0.6
    if sektor == 'rufa':
        if nast(j, 'sync_rufa_sg_' + burta) >= 0.5: do_kadluba *= 0.9
        if nast(j, 'sync_rufa_sm_' + burta) >= 0.5: do_kadluba *= 0.9
    gen['obciazenie'] += do_gen
    c = pojemnosc(p) * mnoznik_oslony(j, gen)
    if gen['obciazenie'] > c:
        gen['rykoszet'] += gen['obciazenie'] - c
        gen['obciazenie'] = c
    if do_kadluba > 0:
        # żyły w promieniu trafienia (Gauss) giną od energii skupionej na nich
        pz = moc_zyly(p) * STALE['energia_zabicia_zyly_s']
        n = gen['zyly']['n']
        bok = p['oslony']['siatka'][0] if p['oslony']['siatka'] else int(math.sqrt(n))
        srodek = int(los(stan['ziarno'], tik, 'traf', ziarno_klucz) * n)
        zabite = int(do_kadluba / pz)
        for i in range(zabite):
            a = los(stan['ziarno'], tik, 'zyla', ziarno_klucz, i) * 2 * math.pi
            r = math.sqrt(-2 * math.log(max(1e-12, los(stan['ziarno'], tik, 'r', ziarno_klucz, i)))) * STALE['promien_trafienia'] / 2
            dx, dy = zaokr(r * math.cos(a)), zaokr(r * math.sin(a))
            z = srodek + dy * bok + dx
            if 0 <= z < n: zabij(gen['zyly'], z)
        sekcje = list(j['sekcje'])
        sek = sekcje[int(los(stan['ziarno'], tik, 'sekcja', ziarno_klucz) * len(sekcje))]
        udzial = dict((s, u) for s, _, u in p['kadlub'])[sek]
        ubytek = 100 * do_kadluba / (STALE['kadlub_j'][j['profil']] * udzial)
        j['sekcje'][sek] = max(0.0, j['sekcje'][sek] - ubytek)
        stan['zdarzenia'].append((tik, 'przebicie', sprawca, j['nazwa'], gen['kod'], round(do_kadluba / energia, 4)))
        udzialy = dict((s, u) for s, _, u in p['kadlub'])
        kadlub = 0.0
        for s in sorted(j['sekcje']): kadlub += j['sekcje'][s] * udzialy[s]
        if kadlub < STALE['prog_unieszkodliwienia'] and j['stan'] == 'w_bitwie':
            j['stan'] = 'unieszkodliwiona'
            stan['zdarzenia'].append((tik, 'unieszkodliwienie', j['strona'], j['nazwa']))
    return do_kadluba, do_gen


def ai_oslony(stan, tik, j):
    """Tryb automatyczny osłon (3.6): po pomiarze Hz wroga stroi generator do najbliższego stopnia natywnego ≥ Hz lasera
    w zakresie suwaków MIN/MAX. Pomiar: klucze wroga w zasięgu sensorów (200 km) — maksimum Hz."""
    zasieg = STALE['zasieg_sensorow_km'] if nast(j, 'sens_aktywny') >= 0.5 else 60.0   # sensory bierne: tylko strefa radaru
    for gen in j['generatory']:
        if not gen['auto']: continue
        hz = [hz_klucza(k) for k in stan['klucze'] if k['strona'] != j['strona'] and k['stan'] == 'w_locie' and odleglosc(k, j) < zasieg]
        hz += [max(u[7] for u in TL[w['profil']]) for w in wrogowie(stan, j['strona']) if TL.get(w['profil']) and odleglosc(w, j) < zasieg]
        if not hz: continue
        cel = stopien_w_gore(gen['zyly']['n'], max(hz)) or gen['hz_max']
        cel = min(max(cel, gen['hz_min']), gen['hz_max'])
        if cel != gen['hz_zadane'] and not any(w['kanal'] == f'{j["nr"]}:{gen["kod"]}' and w['stan'] == 'przygotowanie' for w in stan['rozkazy']):
            gen['hz_zadane'] = cel
            przyjmij_rozkaz(stan, f'{j["nr"]}:{gen["kod"]}', 'przestrojenie_auto', tik)


def dowodca(stan, strona):
    return STALE['dowodcy'].get(stan['dowodcy'].get(strona), STALE['dowodcy']['calrissian'])


def ai_klucza(stan, tik, k):
    """SI klucza strony AI (MECHANIKA 11), rozkazy przez przyjmij_rozkaz — z tym samym czasem wykonania co gracze:
    1) wróg na ogonie (do 1,5 km za rufą) — unik: as spirala, inni split-S albo beczka (skłonność dowódcy);
    2) maszyny z torpedami wybierają nalot na okręt albo walkę z myśliwcami — według Hz osłon okrętu gracza
       (torpedy przebiją osłony → nalot) i dowódcy (Radus zawsze, Ackbar tylko bez myśliwców wroga w 10 km);
    3) w nalocie, okręt do 3 km przed dziobem — torpedy; 4) przy okręcie — uniki i przelot."""
    kan, kanr = kanal_klucza(k), kanal_rozkazu(k)
    if any(w['kanal'] in (kan, kanr) and w['stan'] == 'przygotowanie' for w in stan['rozkazy']): return
    cfg = dowodca(stan, k['strona'])
    z = stan['ziarno']
    fig = figura(stan, kan)
    # 1) ogon
    for t in wrogie_klucze(stan, k['strona']):
        if odleglosc(k, t) <= 1.5 and abs(norm180(namiar(k, t) - k['kurs'])) > 135:
            if fig not in UNIKI and los(z, tik, 'ai_ogon', kan) < 2 * cfg['unik']:
                kod = 'spirala' if k['ranga'] == 'as' else 'split_s' if los(z, tik, 'ai_ogon2', kan) < 0.35 else \
                    'beczka_l' if los(z, tik, 'ai_ogon3', kan) < 0.5 else 'beczka_p'
                przyjmij_rozkaz(stan, kan, kod, tik, k['ranga'], k)
            return
    # 2) rozkaz: nalot, walka z myśliwcami albo ochrona okrętu matki
    wj = wrogowie(stan, k['strona'])
    if k['typ'] in TORPEDY and wj:
        okret = min(wj, key=lambda j: odleglosc(k, j))
        hz_o = max([g['hz_osiagane'] for g in okret['generatory']] or [0])
        przebija = hz_klucza(k) * STALE['torpedy_hz'] > hz_o
        mysliwce = najblizszy(wrogie_klucze(stan, k['strona']), k, 10.0) is not None
        if cfg['nalot'] == 'zawsze': chce = 'nalot'
        elif cfg['nalot'] == 'hz': chce = 'nalot' if przebija else 'atak'
        else: chce = 'nalot' if przebija and not mysliwce else ('atak' if mysliwce else 'ochrona')
    else:
        chce = 'atak' if cfg['nalot'] != 'ostroznie' else 'ochrona'
    if chce == 'ochrona' and macierzysta(stan, k) is None: chce = 'atak'
    if k['rozkaz'] != chce and los(z, tik, 'ai_rozkaz', kan) < 0.1:
        przyjmij_rozkaz(stan, kanr, chce, tik, k['ranga'], k)
        return
    cel, _, rodzaj = cel_klucza(stan, k)
    if cel is None or rodzaj not in ('klucz', 'okret'): return
    d = odleglosc(k, cel)
    # 3) torpedy
    if rodzaj == 'okret' and k['typ'] in TORPEDY and k['rozkaz'] == 'nalot' and d <= STALE['zasieg_ognia_klucza_km'] \
            and abs(norm180(namiar(k, cel) - k['kurs'])) <= STALE['stozek_okret_deg'] and fig != 'torpedy' and los(z, tik, 'ai_torp', kan) < 0.5:
        przyjmij_rozkaz(stan, kan, 'torpedy', tik, k['ranga'], k)
        return
    # 4) przy okręcie: uniki i przelot
    if rodzaj == 'okret' and d < 8 and fig not in UNIKI + ('przelot', 'torpedy') and los(z, tik, 'ai', kan) < cfg['unik']:
        przyjmij_rozkaz(stan, kan, 'beczka_l' if los(z, tik, 'ai2', kan) < 0.5 else 'zygzak', tik, k['ranga'], k)
    elif rodzaj == 'okret' and d < STALE['zasieg_ognia_klucza_km'] and fig not in ('przelot', 'torpedy') and los(z, tik, 'ai3', kan) < 0.2:
        przyjmij_rozkaz(stan, kan, 'przelot', tik, k['ranga'], k)


def ster_okretu(stan, j):
    """Okręt gracza: skręt do kursu zadanego z prędkością obrotu z profilu (× mnoznik_skretu), zmiana prędkości do
    zadanej z przyspieszeniem profilu (rozkaz ster z konsoli Silniki albo Nawigacja). Napęd (MECHANIKA 11.4):
    ciąg silników głównych burt i środkowego, zakresy min./maks., trym pochylenia (sprawność), wzmocnienia,
    różnica ciągu burt (dryf zwrotu), trym odchylenia, wychylenie dysz manewrowych (przesuw pod skosem)."""
    def n(k): return nast(j, k)
    bl = stan_blokady(stan, j)
    if bl != 'zwolniona':
        # Anchor/Lock: okręt hamuje (stabilizowanie) i stoi (utrzymana); skręt tylko z synchronizacją z nawigacją
        j['v'] = 0.0 if bl == 'utrzymana' else max(0.0, j['v'] - 0.01)
        if n('sync_grav_nav') >= 0.5 and j.get('kurs_cel') is not None:
            zw = ((PROFILE[j['profil']].get('silniki') or {}).get('obrot_deg_na_min') or 60) / 60
            j['kurs'] = norm180(j['kurs'] + max(-zw, min(zw, norm180(j['kurs_cel'] - j['kurs']))))
        return
    if j.get('kurs_cel') is not None:
        sil = PROFILE[j['profil']].get('silniki') or {}
        zwrot0 = (sil.get('obrot_deg_na_min') or 60) / 60
        d = norm180(j['kurs_cel'] - j['kurs'])
        zwrot = zwrot0 * mnoznik_skretu(stan, j, 'l' if d < 0 else 'p')
        j['kurs'] = norm180(j['kurs'] + max(-zwrot, min(zwrot, d)))
    v_maks = STALE['v_maks_okretu'].get(j['profil'], 0.3)
    ciag = (n('sil_max_l') + n('sil_max_p') + n('sil_srodek')) / 3
    wzm_v = 1 + 0.1 * (wzm(stan, j, 'gl_l') + wzm(stan, j, 'gl_p') + wzm(stan, j, 'gl_s'))
    if j.get('v_cel') is not None:
        a = STALE['przyspieszenie_okretu'].get(j['profil'], 0.005) * ciag * wzm_v * (1 + 0.1 * (n('sync_gl_l_s') + n('sync_gl_p_s')))
        v_gora = v_maks * zas(j, 'v') * ciag * wzm_v * (1 - 0.2 * abs(n('trym_poch') - 0.5))
        cel = max(0.0, min(v_gora, j['v_cel']))
        cel = max(cel, min(v_gora, v_maks * (n('sil_min_l') + n('sil_min_p')) / 3))
        j['v'] = j['v'] + max(-a, min(a, cel - j['v']))
    # różnica ciągu burt skręca okręt (więcej z lewej — w prawo), trym odchylenia — stały dryf zwrotu
    roznica = n('sil_max_l') - n('sil_max_p')
    if roznica != 0 and j['v'] > 0: j['kurs'] = norm180(j['kurs'] + roznica * 0.3 * j['v'] / v_maks)
    if n('trym_odch') != 0.5: j['kurs'] = norm180(j['kurs'] + (n('trym_odch') - 0.5) * 0.4)
    # przesuw pod skosem: dysze manewrowe wychylone na jedną burtę pchają okręt w bok (km/s)
    vb = 0.02 * ((n('dysza_pg') + n('dysza_pd') + n('katdysz_p')) - (n('dysza_lg') + n('dysza_ld') + n('katdysz_l')))
    if vb != 0:
        j['x'] += vb * math.cos(radiany(j['kurs']))
        j['y'] -= vb * math.sin(radiany(j['kurs']))


def kadlub(j):
    """Średnia ważona integralność kadłuba (sekcje w kolejności alfabetycznej — jak PHP)."""
    udzialy = dict((s, u) for s, _, u in PROFILE[j['profil']]['kadlub'])
    w = 0.0
    for s in sorted(j['sekcje']): w += j['sekcje'][s] * udzialy[s]
    return w


def ai_okretu(stan, tik, j):
    """SI okrętu strony AI: skręca z prędkością obrotu z profilu; podchodzi na dystans walki dowódcy, potem ustawia
    się burtą — tą, której osłony są mniej obciążone; poniżej progu odwrotu kadłuba odchodzi od wroga, a dalej niż
    60 km od wszystkich wrogów — wycofuje się z bitwy."""
    cfg = dowodca(stan, j['strona'])
    wj = wrogowie(stan, j['strona'])
    sil = PROFILE[j['profil']].get('silniki') or {}
    zwrot = (sil.get('obrot_deg_na_min') or 60) / 60
    chce = j['kurs']
    if kadlub(j) < cfg['odwrot_proc']:
        if not wj or all(odleglosc(j, w) > STALE['wycofanie_km'] for w in wj):
            j['stan'] = 'wycofana'
            stan['zdarzenia'].append((tik, 'wycofanie', j['strona'], j['nazwa']))
            return
        chce = namiar(min(wj, key=lambda w: odleglosc(j, w)), j)
    elif wj:
        w = min(wj, key=lambda x: odleglosc(j, x))
        if odleglosc(j, w) > cfg['dystans_km'] + 2:
            chce = namiar(j, w)
        else:
            pol = len(j['generatory']) // 2 or 1
            lewa = sum(g['obciazenie'] for g in j['generatory'][:pol])
            prawa = sum(g['obciazenie'] for g in j['generatory'][pol:])
            chce = namiar(j, w) - 90 if lewa > prawa else namiar(j, w) + 90   # wróg na burcie mniej obciążonej
    j['kurs'] = norm180(j['kurs'] + max(-zwrot, min(zwrot, norm180(chce - j['kurs']))))


# Figury kursu: zmiana kursu zadanego (°); klucz skręca do niego z własną prędkością kątową, aż do kolejnego rozkazu.
ZMIANA_KURSU = {'prosto': 0, 'lagodnie_l': -30, 'lagodnie_p': 30, 'ostro_l': -90, 'ostro_p': 90, 'petla': 180, 'split_s': 180}


def krok(stan):
    """Jeden tik: rozkazy → ruch → ogień → straty → zdarzenia (MECHANIKA 1)."""
    tik = stan['tik']
    for j in stan['jednostki']:
        if j['stan'] == 'oczekuje' and tik >= j['wejscie']:
            j['stan'] = 'w_bitwie'
            stan['zdarzenia'].append((tik, 'wejscie', j['strona'], j['nazwa']))
    nowe = aktywuj_rozkazy(stan, tik)
    stan['_dziala'] = {w['kanal'] for w in stan['rozkazy'] if w['stan'] == 'dziala'}
    for w in nowe:
        if w['klucz'] is not None:                       # akcja NPC: figura lub rozkaz zaczął działać → XP (W7)
            k = w['klucz']
            k['akcje'] += 1
            if ROZK[w['kod']]['grupa'] == 'rozkaz_klucza':
                if w['kod'] == 'start':
                    if k['stan'] == 'hangar' and k['start'] is None:
                        m = k['macierzysta']
                        k['start'] = max(tik, stan['tasma'].get(m, -10 ** 9) + interwal_tasmy(stan, k))
                        stan['tasma'][m] = k['start']
                else:
                    k['rozkaz'] = w['kod']
                    k['kurs_zadany'] = None
                    if w['kod'] == 'powrot' and k['stan'] == 'w_locie': k['stan'] = 'powrot'
            elif w['kod'] in ZMIANA_KURSU:
                k['kurs_zadany'] = k['kurs'] + ZMIANA_KURSU[w['kod']]
        if w['kod'] in ('ster', 'cel_ognia'):
            nr = w['kanal'].split(':')[0]
            j = next(x for x in stan['jednostki'] if str(x['nr']) == nr)
            if w['kod'] == 'ster':
                kurs, v = (float(x) for x in w['wartosc'].split(';'))
                j['kurs_cel'], j['v_cel'] = kurs, v
            else:
                j['cel_ognia'] = int(w['wartosc']) if w['wartosc'] else None
        if w['kod'] in ('nastawa', 'synchronizuj', 'przelacz', 'wyrownaj'):
            nr = w['kanal'].split(':')[0]
            j = next(x for x in stan['jednostki'] if str(x['nr']) == nr)
            if w['kod'] == 'wyrownaj':
                for k in GRUPY_WYROWNANIA[w['wartosc']]: j['nastawy'][k] = NASTAWY[k]
            else:
                k, v = w['wartosc'].split('=')
                j['nastawy'][k] = float(v)
                if k == 'blokada' and float(v) >= 0.5:
                    j['nastawy']['blokada_od'] = float(tik)
                if k.startswith('tryb_') and float(v) >= 0.75:
                    j['nastawy']['zapor_do_' + k[-1]] = float(tik + 10 + int(zaokr(50 * nast(j, 'czas_zapor'))))
        if w['kod'].startswith('przestrojenie'):
            nr, gk = w['kanal'].split(':')
            j = next(x for x in stan['jednostki'] if str(x['nr']) == nr)
            g = next(x for x in j['generatory'] if x['kod'] == gk)
            g['hz_osiagane'] = g['hz_zadane']
            stan['zdarzenia'].append((tik, 'przestrojenie', j['nazwa'], gk, g['hz_zadane']))
    # ruch
    for k in stan['klucze']:
        if k['stan'] == 'hangar' and k['start'] is not None and tik >= k['start']:
            k['stan'] = 'w_locie'
            if k['rozkaz'] == 'powrot': k['rozkaz'] = 'ochrona'
            mac = macierzysta(stan, k)
            if mac: k['x'], k['y'] = mac['x'], mac['y']
            stan['zdarzenia'].append((tik, 'start', k['strona'], k['nr'], k['typ']))
        if k['stan'] not in ('w_locie', 'powrot'): continue
        fig = figura(stan, kanal_klucza(k))
        m = MASZ[k['typ']]
        v = m['v'] * (ROZK[fig]['v'] if fig and ROZK[fig]['v'] else 1)
        cel, promien, rodzaj = cel_klucza(stan, k)
        chce = k['kurs']
        if cel is not None:
            d = odleglosc(k, cel)
            if k['stan'] == 'powrot' and d <= STALE['ladowanie_km']:
                k['stan'], k['start'] = 'hangar', None
                k['x'], k['y'] = cel['x'], cel['y']
                stan['zdarzenia'].append((tik, 'ladowanie', k['strona'], k['nr'], k['typ']))
                continue
            chce = namiar(k, cel) if d > promien else namiar(k, cel) + 90   # krążenie przy celu
        if k['kurs_zadany'] is not None and k['stan'] == 'w_locie': chce = k['kurs_zadany']
        zwrot = m['zwrot'] * (STALE['zwrot_as'] if k['ranga'] == 'as' else 1)
        k['kurs'] = norm180(k['kurs'] + max(-zwrot, min(zwrot, norm180(chce - k['kurs']))))
        k['x'] += v * math.sin(radiany(k['kurs']))
        k['y'] += v * math.cos(radiany(k['kurs']))
    for j in stan['jednostki']:
        if j['stan'] == 'w_bitwie': zasilanie(stan, tik, j)
        else: j.pop('zasil', None)
    for j in stan['jednostki']:
        if j['stan'] != 'w_bitwie': continue
        if j['ai']:
            ai_okretu(stan, tik, j)
            if j['stan'] != 'w_bitwie': continue
        else:
            ster_okretu(stan, j)
        j['x'] += j['v'] * math.sin(radiany(j['kurs']))
        j['y'] += j['v'] * math.cos(radiany(j['kurs']))
    # ogień kluczy w osłony okrętów
    for k in stan['klucze']:
        if k['stan'] != 'w_locie': continue
        fig = figura(stan, kanal_klucza(k))
        if fig and ROZK[fig]['ogien_wyl']: continue
        k['ladunek'] += 1
        m = MASZ[k['typ']]
        if k['ladunek'] < m['ladowanie']: continue
        cel, _, rodzaj = cel_klucza(stan, k)
        if cel is None: continue
        d = odleglosc(k, cel)
        odchyl = abs(norm180(namiar(k, cel) - k['kurs']))
        if rodzaj == 'klucz' and d <= m['zasieg'] and odchyl <= m['stozek']:
            k['ladunek'] = 0
            strzal_w_klucz(stan, tik, k, cel)
        elif rodzaj == 'okret' and d <= STALE['zasieg_ognia_klucza_km'] and odchyl <= STALE['stozek_okret_deg']:
            k['ladunek'] = 0
            torp = fig == 'torpedy' and k['typ'] in TORPEDY
            gen = generator_od_strony(cel, k['x'], k['y'])
            trafienie_oslony(stan, tik, cel, gen, m['energia'] * k['maszyny'] * (STALE['torpedy_energia'] if torp else 1),
                             hz_klucza(k) * (STALE['torpedy_hz'] if torp else 1), f'{k["strona"]} {k["nr"]}' + (' torpedy' if torp else ''), kanal_klucza(k),
                             k['x'], k['y'])
    for j in stan['jednostki']:
        if j['stan'] == 'w_bitwie': obrona_punktowa(stan, tik, j)
    for j in stan['jednostki']:
        if j['stan'] == 'w_bitwie': ogien_okretu(stan, tik, j)
    for k in stan['klucze']:
        if k['oslona_pkt'] > 0: k['oslona_pkt'] = max(0.0, k['oslona_pkt'] - STALE['oslona_regen'])
    # osłony: odpływ obciążenia, AI osłon
    for j in stan['jednostki']:
        for g in j['generatory']:
            g['obciazenie'] *= STALE['odplyw_obciazenia']
        if j['stan'] == 'w_bitwie': ai_oslony(stan, tik, j)
    for k in stan['klucze']:
        if k['stan'] == 'w_locie' and k['ai']: ai_klucza(stan, tik, k)
    stan['tik'] = tik + 1


def xp(stan):
    """XP pilotów (symulacja): akcje × 0,5 × mnożnik rangi — każdy pilot w kluczu (4 na klucz, wspólna ranga klucza)."""
    wynik = {}
    for k in stan['klucze']:
        wynik[kanal_klucza(k)] = (k['ranga'], k['akcje'], k['akcje'] * STALE['xp_akcja'] * STALE['xp_mnoznik'][k['ranga']])
    return wynik


def rozegraj(kod, ziarno, tiki=None):
    stan = utworz(kod, ziarno)
    for _ in range(tiki or stan['scenariusz']['okno_s']):
        krok(stan)
    return stan


if __name__ == '__main__':
    kod = sys.argv[1] if len(sys.argv) > 1 else 'walako_klucze'
    ziarno = int(sys.argv[2]) if len(sys.argv) > 2 else 1
    s = rozegraj(kod, ziarno)
    print(f'== {kod}, ziarno {ziarno}, {s["tik"]} tików')
    for j in s['jednostki']:
        print(f'   {j["nazwa"]}: sekcje ' + ', '.join(f'{k} {v:.2f}%' for k, v in j['sekcje'].items()))
        for g in j['generatory']:
            print(f'     {g["kod"]}: Hz {g["hz_osiagane"]} (skuteczne {hz_skuteczne(g["zyly"], g["hz_osiagane"])}), '
                  f'martwe żyły {sum(bin(b).count("1") for b in g["zyly"]["martwe"])}, rykoszet {g["rykoszet"]:.3g} J')
    rodz = {}
    for z in s['zdarzenia']: rodz[z[1]] = rodz.get(z[1], 0) + 1
    print('   zdarzenia:', rodz)
    print('   pierwsze przestrojenia:', [z for z in s['zdarzenia'] if z[1] == 'przestrojenie'][:4])
    x = xp(s)
    print(f'   XP: {len(x)} kluczy, akcje razem {sum(a for _, a, _ in x.values())}, XP na pilota (średnio) '
          f'{sum(v for _, _, v in x.values()) / max(1, len(x)):.1f}')

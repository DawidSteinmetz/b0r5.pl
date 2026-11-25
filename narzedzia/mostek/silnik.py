"""Silnik wzorcowy bitwy (Python) — specyfikacja wykonywalna dla portu PHP (api/bitwa_silnik.php).

Liczy dokładnie to, co jest ustalone w MECHANIKA.md; stałe robocze oznaczone [R] w STALE. Bez bazy danych: stan to
słowniki, wejście to dziennik rozkazów, wyjście to stan i zdarzenia. Deterministyczny: ta sama bitwa (ziarno + rozkazy)
daje ten sam przebieg — w Pythonie i w PHP (losowość z crc32, jak PHP crc32()).

    python silnik.py [scenariusz] [ziarno]    rozgrywa scenariusz bez graczy (AI obu stron) i wypisuje raport
"""
import math, sys, zlib
from mechanika import stopnie_natywne, stopien_w_gore, moc_zyly, pojemnosc, filtr
from profile_klas import PROFILE, WSPOLNE
from dane.maszyny import MASZYNY
from dane.rozkazy import ROZKAZY
from dane.scenariusze import SCENARIUSZE

CYKL = WSPOLNE['cykl_tiki']
MASZ = {m[0]: dict(zip(['kod', 'nazwa', 'strona', 'rola', 'hz', 'oslona_hz', 'v', 'przekroj', 'energia', 'ladowanie', 'opis'], m)) for m in MASZYNY}
ROZK = {r[0]: dict(zip(['kod', 'nazwa', 'grupa', 'c', 'd', 'o', 'theta', 'theta_wl', 'v', 'ogien_wyl', 'ranga', 'skraca', 'opis'], r)) for r in ROZKAZY}
RANGI = {'kadet': 0, 'standard': 1, 'as': 2}

STALE = {
    'odplyw_obciazenia': 0.98,      # [R] obciążenie generatora × 0,98 na tik (salwa ISD nasyca C w ok. 17 tików)
    'energia_zabicia_zyly_s': 1.0,  # [R] żyła ginie od energii = P_żyły × 1 s skupionej na niej (Hz_o < Hz_l)
    'promien_trafienia': 7,         # [R] promień (w żyłach siatki) rozkładu uszkodzeń trafienia (turbolaser 7)
    'zasieg_ognia_klucza_km': 3.0,  # [R]
    'dystans_ataku_km': 2.0,        # [R] klucz podchodzi do celu na tyle i krąży
    'zasieg_sensorow_km': 200.0,    # [R] pomiar Hz kluczy przez sensory (tryb automatyczny osłon, konsola Sensory)
    'tasma_s': 60,                  # [U] taśmociąg: z jednego okrętu najwyżej 1 klucz na minutę (jak api/klucze.php)
    'promien_ochrony_km': 3.0,      # [R] ochrona: klucz krąży przy okręcie matce
    'promien_patrolu_km': 8.0,      # [R] patrol: szerszy krąg wokół okrętu matki
    'promien_skanu_km': 10.0,       # [R] skan: krąży przy celu poza zasięgiem jego ognia
    'ladowanie_km': 0.5,            # [R] powrót: klucz ląduje w hangarze bliżej niż tyle
    'skret_lagodny': 3.0, 'skret_ostry': 9.0, 'skret_zawrot': 30.0,  # [R] °/tik
    'kadlub_j': {'ISD_I': 5e23, 'MC80': 4e23, 'ZBIEG': 2e21},          # [?] odporność całego kadłuba (100% wszystkich sekcji)
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


def przyjmij_rozkaz(stan, kanal, kod, czas_s, ranga='standard', klucz=None):
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
            'odnowienie_do': (tik + c + int(r['d'] or 0) + int(r['o'])) if r['o'] else None, 'klucz': klucz}
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
                                  'generatory': gen, 'sekcje': {sk: 100.0 for sk in sorted(x[0] for x in p['kadlub'])}, 'stan': 'w_bitwie'})
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
                                   'ai': st[2] != 'gracze'})
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


def cel_klucza(stan, k):
    """Dokąd leci klucz i w jakim promieniu krąży — z rozkazu: atak (najbliższy wróg, 2 km), skan (10 km), powrót
    (okręt matka, lądowanie), ochrona i patrol (okręt matka, 3 / 8 km). Klucz bez okrętu matki atakuje."""
    mac = macierzysta(stan, k)
    wrogie = wrogowie(stan, k['strona'])
    r = k['rozkaz']
    if (r in ('atak', 'skan') or mac is None) and wrogie:
        return min(wrogie, key=lambda j: odleglosc(k, j)), STALE['promien_skanu_km'] if r == 'skan' else STALE['dystans_ataku_km']
    if mac is None: return None, 0.0
    if r == 'powrot': return mac, 0.0
    return mac, STALE['promien_patrolu_km'] if r == 'patrol' else STALE['promien_ochrony_km']


def wrogowie(stan, strona):
    return [j for j in stan['jednostki'] if j['strona'] != strona and j['stan'] == 'w_bitwie']


def odleglosc(a, b):
    return math.hypot(a['x'] - b['x'], a['y'] - b['y'])


def hz_klucza(k):
    return MASZ[k['typ']]['hz'] * (1 + STALE['dryf_k'][k['ranga']] * k['moc'])


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


def trafienie_oslony(stan, tik, j, gen, energia, hz_l, sprawca, ziarno_klucz):
    """Filtr (3.2), pojemność i rykoszet (3.4), uszkodzenia żył i kadłuba (3.5, W13)."""
    p = PROFILE[j['profil']]
    hz_o = hz_skuteczne(gen['zyly'], gen['hz_osiagane']) if gen['hz_osiagane'] else 0
    do_kadluba, do_gen = filtr(energia, hz_o, hz_l) if hz_o else (energia, 0.0)
    gen['obciazenie'] += do_gen
    c = pojemnosc(p)
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
    return do_kadluba, do_gen


def ai_oslony(stan, tik, j):
    """Tryb automatyczny osłon (3.6): po pomiarze Hz wroga stroi generator do najbliższego stopnia natywnego ≥ Hz lasera
    w zakresie suwaków MIN/MAX. Pomiar: klucze wroga w zasięgu sensorów (200 km) — maksimum Hz."""
    for gen in j['generatory']:
        if not gen['auto']: continue
        hz = [hz_klucza(k) for k in stan['klucze'] if k['strona'] != j['strona'] and k['stan'] == 'w_locie' and odleglosc(k, j) < STALE['zasieg_sensorow_km']]
        if not hz: continue
        cel = stopien_w_gore(gen['zyly']['n'], max(hz)) or gen['hz_max']
        cel = min(max(cel, gen['hz_min']), gen['hz_max'])
        if cel != gen['hz_zadane'] and not any(w['kanal'] == f'{j["nr"]}:{gen["kod"]}' and w['stan'] == 'przygotowanie' for w in stan['rozkazy']):
            gen['hz_zadane'] = cel
            przyjmij_rozkaz(stan, f'{j["nr"]}:{gen["kod"]}', 'przestrojenie_auto', tik)


def ai_klucza(stan, tik, k):
    """Prosta AI klucza (dowodzenie stron AI i klucze bez gracza): podejście do celu; w zasięgu ognia wroga — unik
    (beczka albo zygzak), potem przelot. Rozkazy przez przyjmij_rozkaz — z tym samym czasem wykonania co gracze."""
    cele = wrogowie(stan, k['strona'])
    if not cele: return
    cel = min(cele, key=lambda j: odleglosc(k, j))
    d = odleglosc(k, cel)
    kan = kanal_klucza(k)
    fig = figura(stan, kan)
    w_przygot = any(w['kanal'] == kan and w['stan'] == 'przygotowanie' for w in stan['rozkazy'])
    if w_przygot: return
    if d < 8 and fig not in ('beczka_l', 'beczka_p', 'zygzak', 'przelot') and los(stan['ziarno'], tik, 'ai', kan) < 0.15:
        kod = 'beczka_l' if los(stan['ziarno'], tik, 'ai2', kan) < 0.5 else 'zygzak'
        przyjmij_rozkaz(stan, kan, kod, tik, k['ranga'], k)
    elif d < STALE['zasieg_ognia_klucza_km'] and fig != 'przelot' and los(stan['ziarno'], tik, 'ai3', kan) < 0.2:
        przyjmij_rozkaz(stan, kan, 'przelot', tik, k['ranga'], k)


def krok(stan):
    """Jeden tik: rozkazy → ruch → ogień → straty → zdarzenia (MECHANIKA 1)."""
    tik = stan['tik']
    for w in aktywuj_rozkazy(stan, tik):
        if w['klucz'] is not None:                       # akcja NPC: figura lub rozkaz zaczął działać → XP (W7)
            k = w['klucz']
            k['akcje'] += 1
            if ROZK[w['kod']]['grupa'] == 'rozkaz_klucza':
                if w['kod'] == 'start':
                    if k['stan'] == 'hangar' and k['start'] is None:
                        m = k['macierzysta']
                        k['start'] = max(tik, stan['tasma'].get(m, -10 ** 9) + STALE['tasma_s'])
                        stan['tasma'][m] = k['start']
                else:
                    k['rozkaz'] = w['kod']
                    if w['kod'] == 'powrot' and k['stan'] == 'w_locie': k['stan'] = 'powrot'
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
        v = MASZ[k['typ']]['v'] * (ROZK[fig]['v'] if fig and ROZK[fig]['v'] else 1)
        cel, promien = cel_klucza(stan, k)
        if cel is not None:
            d = odleglosc(k, cel)
            if k['stan'] == 'powrot' and d <= STALE['ladowanie_km']:
                k['stan'], k['start'] = 'hangar', None
                k['x'], k['y'] = cel['x'], cel['y']
                stan['zdarzenia'].append((tik, 'ladowanie', k['strona'], k['nr'], k['typ']))
                continue
            kurs_do = stopnie(math.atan2(cel['x'] - k['x'], cel['y'] - k['y']))
            k['kurs'] = kurs_do if d > promien else kurs_do + 90   # krążenie przy celu
        if fig in ('petla', 'split_s'): k['kurs'] += 180 / max(1, ROZK[fig]['d'])
        k['x'] += v * math.sin(radiany(k['kurs']))
        k['y'] += v * math.cos(radiany(k['kurs']))
    for j in stan['jednostki']:
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
        cele = [j for j in wrogowie(stan, k['strona']) if odleglosc(k, j) <= STALE['zasieg_ognia_klucza_km']]
        if not cele: continue
        k['ladunek'] = 0
        cel = cele[0]
        gen = generator_od_strony(cel, k['x'], k['y'])
        trafienie_oslony(stan, tik, cel, gen, m['energia'] * k['maszyny'], hz_klucza(k), f'{k["strona"]} {k["nr"]}', kanal_klucza(k))
    # osłony: odpływ obciążenia, AI osłon
    for j in stan['jednostki']:
        for g in j['generatory']:
            g['obciazenie'] *= STALE['odplyw_obciazenia']
        ai_oslony(stan, tik, j)
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

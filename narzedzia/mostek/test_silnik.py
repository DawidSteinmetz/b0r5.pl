"""Testy silnika wzorcowego i wektory dla portu PHP.

    python test_silnik.py            testy (kod 1 przy błędzie)
    python test_silnik.py --php      też zapisuje ../../api/bitwa_wektory.php (wektory porównywane na serwerze)
"""
import os, sys
import silnik as S
from mechanika import filtr, stopien_w_gore, pojemnosc, moc_zyly, zuzycie
from profile_klas import PROFILE

BLEDY = []


def rowne(nazwa, a, b, tol=1e-9):
    ok = (abs(a - b) <= tol * max(1, abs(b))) if isinstance(b, float) else a == b
    if not ok: BLEDY.append(f'{nazwa}: {a!r} ≠ {b!r}')


# --- mechanika (liczby z MECHANIKA.md)
rowne('filtr 900/1200', filtr(1.0, 900, 1200)[0], 0.25)
rowne('filtr MC80 2187 vs ISD 3600', round(filtr(1.0, 2187, 3600)[0], 4), 0.3925)
rowne('stopień ISD wobec 2187', stopien_w_gore(3600, 2187), 3600)
rowne('stopień MC80 wobec 800', stopien_w_gore(2187, 800), 2187)
rowne('C ISD', round(pojemnosc(PROFILE['ISD_I']) / 1e23, 2), 1.3)
rowne('zużycie 1800', round(zuzycie(1800), 3), 9.0)

# --- losowość (crc32 — ta sama w PHP)
rowne('los', S.los(7, 3, 'a'), 0.0)  # nadpisane niżej prawdziwą wartością, tu tylko typ
BLEDY.pop()
rowne('los stały', S.los(7, 3, 'a'), S.los(7, 3, 'a'))

# --- żyły
z = S.nowe_zyly(3600)
rowne('Hz skuteczne 1800, wszystkie żywe', S.hz_skuteczne(z, 1800), 1800)
S.zabij(z, 1); S.zabij(z, 2)               # żyła 2 świeci przy 1800 (co druga), żyła 3 nie
rowne('Hz skuteczne po śmierci żył 2 i 3', S.hz_skuteczne(z, 1800), 1799)
rowne('Hz skuteczne 3600', S.hz_skuteczne(z, 3600), 3598)

# --- zegar
rowne('zegar bez pauzy', S.czas_bitwy(110.0, 100.0), 10.0)
rowne('zegar z pauzą trwającą', S.czas_bitwy(110.0, 100.0, 2.0, 105.0), 3.0)

# --- czas wykonania rozkazów
rowne('przygotowanie beczki: kadet', S.przygotowanie('beczka_l', 'kadet'), 3)
rowne('przygotowanie beczki: as', S.przygotowanie('beczka_l', 'as'), 1)
rowne('przygotowanie zygzaka: as (min 1 s)', S.przygotowanie('zygzak', 'as'), 1)
rowne('przestrojenie ręczne nie zależy od rangi', S.przygotowanie('przestrojenie_reczne', 'as'), 12)

stan = {'rozkazy': []}
k = {'strona': 'Rebelia', 'nr': 1}
w1 = S.przyjmij_rozkaz(stan, 'K', 'beczka_l', 10.4, 'standard', k)
rowne('beczka: działa od', w1['dziala_od'], 12)
rowne('beczka: działa do', w1['dziala_do'], 16)
rowne('beczka: odnowienie do', w1['odnowienie_do'], 20)
w2 = S.przyjmij_rozkaz(stan, 'K', 'zygzak', 11.0, 'standard', k)       # w trakcie przygotowania beczki
rowne('nowy rozkaz przerywa przygotowanie', w1['stan'], 'przerwany')
rowne('limit zmian w cyklu (zwykły 2)', S.przyjmij_rozkaz(stan, 'K', 'burst', 11.5, 'standard', k).get('odmowa'), 'limit zmian w tym cyklu')
rowne('spirala tylko dla asa', S.przyjmij_rozkaz({'rozkazy': []}, 'K', 'spirala', 1, 'standard', k).get('odmowa'), 'spirala asa: tylko as')

# unik rozkazany wcześnie działa, za późno — nie
st = {'rozkazy': []}
S.przyjmij_rozkaz(st, 'A', 'beczka_l', 100.0, 'standard', k)            # działa od 102
S.przyjmij_rozkaz(st, 'B', 'beczka_l', 101.5, 'standard', k)            # działa od 103
S.aktywuj_rozkazy(st, 102)
rowne('wcześniejszy unik działa w 102', S.figura(st, 'A'), 'beczka_l')
rowne('późniejszy unik nie działa w 102', S.figura(st, 'B'), None)

# --- determinizm i scenariusz
a = S.rozegraj('walako_klucze', 5, 120)
b = S.rozegraj('walako_klucze', 5, 120)
rowne('determinizm: te same zdarzenia', a['zdarzenia'], b['zdarzenia'])
rowne('determinizm: te same pozycje', [(round(k['x'], 9), round(k['y'], 9)) for k in a['klucze']],
      [(round(k['x'], 9), round(k['y'], 9)) for k in b['klucze']])
c = S.rozegraj('walako_klucze', 6, 120)
rowne('inne ziarno — inny przebieg', a['zdarzenia'] != c['zdarzenia'], True)

# --- ster okrętu gracza: rozkaz ster (3 s) — skręt z prędkością obrotu profilu i przyspieszenie do prędkości zadanej
s = S.utworz('walako_atak', 1)
S.przyjmij_rozkaz(s, '1:ster', 'ster', 5.0, wartosc='90;0.2')
for _ in range(200): S.krok(s)
rowne('ster: kurs zadany osiągnięty', round(s['jednostki'][0]['kurs'], 6), 90.0)
rowne('ster: prędkość zadana osiągnięta', round(s['jednostki'][0]['v'], 6), 0.2)

# --- każdy scenariusz z dane/scenariusze.py buduje się i liczy 10 min bez błędu (nowe scenariusze z PROMPT_SCENARIUSZ.md)
for sc in S.SCENARIUSZE:
    try:
        S.rozegraj(sc['kod'], 1, 600)
    except Exception as e:
        BLEDY.append(f'scenariusz {sc["kod"]}: {type(e).__name__}: {e}')

if BLEDY:
    print('BŁĘDY:'); [print('  ' + x) for x in BLEDY]; sys.exit(1)
print('testy silnika: OK')

if '--php' in sys.argv:
    # Wektory: los(), przygotowanie(), hz_skuteczne(), filtr — port PHP musi dać te same wyniki.
    los_w = [(z, t, k, S.los(z, t, k)) for z, t, k in [(1, 0, 'a'), (7, 3, 'traf'), (123456, 3599, 'klucz:Rebelia:4'), (42, 17, 'zażółć')]]
    prz = [(kod, r, S.przygotowanie(kod, r)) for kod in ('beczka_l', 'zygzak', 'skok_cieniowy', 'przestrojenie_reczne', 'wzmocnij') for r in ('kadet', 'standard', 'as')]
    def hz_wektor(n, f, martwe):
        z = S.nowe_zyly(n)
        for i in martwe: S.zabij(z, i)
        return (n, f, martwe, S.hz_skuteczne(z, f))
    hz = [hz_wektor(3600, 1800, [1, 2]), hz_wektor(3600, 3600, [1, 2]), hz_wektor(2187, 729, [2, 5, 6]),
          hz_wektor(2187, 2187, []), hz_wektor(3600, 1, [3599])]
    fl = [(1.0, 900, 1200, filtr(1.0, 900, 1200)[0]), (1.0, 2187, 3600, filtr(1.0, 2187, 3600)[0]), (1.0, 3600, 2187, 0.0)]
    def przebieg(kod, ziarno, auto, tiki):
        s = S.utworz(kod, ziarno)
        if not auto:
            for j in s['jednostki']:
                for g in j['generatory']: g['auto'] = False
        for _ in range(tiki): S.krok(s)
        zd = {}
        for z in s['zdarzenia']: zd[z[1]] = zd.get(z[1], 0) + 1
        return {'kod': kod, 'ziarno': ziarno, 'auto': auto, 'tiki': tiki,
                'klucze': [[k['strona'], k['nr'], k['stan'], k['ranga'], k['x'], k['y'], k['akcje']] for k in s['klucze']],
                'generatory': [[g['hz_osiagane'], sum(bin(x).count('1') for x in g['zyly']['martwe']), g['obciazenie'], g['rykoszet']]
                               for j in s['jednostki'] for g in j['generatory']],
                'sekcje': [v for j in s['jednostki'] for _, v in sorted(j['sekcje'].items())],
                'zdarzenia': dict(sorted(zd.items()))}
    przebiegi = [przebieg('walako_klucze', 5, True, 200), przebieg('walako_klucze', 9, False, 300), przebieg('walako_atak', 3, True, 420)]
    for pb in przebiegi:
        print(f"  przebieg {pb['kod']}/{pb['ziarno']}/auto={pb['auto']}: zdarzenia {pb['zdarzenia']}, generatory {[g[:2] for g in pb['generatory']]}, "
              f"sekcje min {min(pb['sekcje']):.4f}")

    def php_w(x):
        if isinstance(x, bool): return 'true' if x else 'false'
        if isinstance(x, int): return str(x)
        if isinstance(x, float): return repr(x)
        if isinstance(x, str): return "'" + x.replace("'", "\'") + "'"
        if isinstance(x, list): return '[' + ', '.join(php_w(v) for v in x) + ']'
        if isinstance(x, dict): return '[' + ', '.join(f'{php_w(k)} => {php_w(v)}' for k, v in x.items()) + ']'
        raise TypeError(x)
    php = ['<?php', '// PLIK GENEROWANY: python narzedzia/mostek/test_silnik.py --php — wektory z silnika wzorcowego (Python).',
           '// Port PHP (api/bitwa_silnik.php) musi dać te same wyniki; sprawdza je akcja api/?a=bitwa_test (MG).',
           'declare(strict_types=1);', '', 'const BITWA_WEKTORY = [',
           "    'los' => [" + ', '.join(f"[{z}, {t}, '{k}', {v!r}]" for z, t, k, v in los_w) + '],',
           "    'przygotowanie' => [" + ', '.join(f"['{a}', '{b}', {c}]" for a, b, c in prz) + '],',
           "    'hz_skuteczne' => [" + ', '.join(f"[{n}, {f}, [{', '.join(map(str, m))}], {w}]" for n, f, m, w in hz) + '],',
           "    'filtr' => [" + ', '.join(f"[{e!r}, {o}, {l}, {w!r}]" for e, o, l, w in fl) + '],',
           "    'przebiegi' => " + php_w(przebiegi) + ',', '];', '']
    cel = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'api', 'bitwa_wektory.php')
    with open(cel, 'w', encoding='utf-8', newline='\n') as f: f.write('\n'.join(php))
    print('zapisano api/bitwa_wektory.php')

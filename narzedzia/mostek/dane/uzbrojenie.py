"""Rozstawienie uzbrojenia i urządzeń na kadłubie (punkty), z rysunku ISD od użytkownika (25.11.2026): turbolasery
(8 dużych, 4 na burtę), 60 szybkich działek obrony punktowej równomiernie wzdłuż obu burt, 2 baterie jonowe na grzbiecie,
2 generatory osłon (kopuły), radar i sensory, 3 silniki główne. MC80 — propozycja (M5).

Współrzędne jak sylwetka w konsolach: długość okrętu = 1, dziób w (0, −0,5), x > 0 — prawa burta. Łuk ostrzału:
środek (° od dziobu, + w prawo) i połowa szerokości. Zaokrąglenie do 4 miejsc — te same liczby w SQL i w silniku.
"""
import math


def _r(v):
    return round(v, 4)


def _nam(x, y):
    """Namiar wektora (x, y) w układzie okrętu: 0° = dziób (−y), 90° = prawa burta (+x)."""
    return round(math.degrees(math.atan2(x, -y)), 1)


def _krawedz(od, do, n, start_nr, rodzaj, zasieg, theta, hz, strona):
    """n punktów równomiernie na krawędzi od → do; łuk: na zewnątrz krawędzi ±90°."""
    (x0, y0), (x1, y1) = od, do
    dx, dy = x1 - x0, y1 - y0
    nx, ny = (dy, -dx) if strona == 'P' else (-dy, dx)      # normalna na zewnątrz
    out = []
    for i in range(n):
        t = (i + 0.5) / n
        out.append((start_nr + i, rodzaj, _r(x0 + t * dx), _r(y0 + t * dy), _nam(nx, ny), 90.0, zasieg, theta, hz))
    return out


# nr, rodzaj, x, y, luk_srodek_deg, luk_pol_deg, zasieg_km, theta_mrad, hz
def _isd():
    p = []
    # turbolasery (duże czerwone): 4 na burtę wzdłuż nadbudówki; łuk burtowy ±80° wokół trawersu
    for i, (y, x) in enumerate([(0.358, 0.1625), (0.275, 0.158), (0.2, 0.15), (0.125, 0.1375)]):
        p.append((1 + i, 'turbolaser', _r(-x), _r(y), -90.0, 80.0, 40.0, 1.0, 3600))
        p.append((5 + i, 'turbolaser', _r(x), _r(y), 90.0, 80.0, 40.0, 1.0, 3600))
    # baterie jonowe (niebieskie) na grzbiecie — wieże, pełny obrót
    p.append((9, 'jon', -0.03, 0.0375, 0.0, 180.0, 20.0, 1.5, 1800))
    p.append((10, 'jon', 0.03, 0.0375, 0.0, 180.0, 20.0, 1.5, 1800))
    # generatory osłon (żółte), radar i sensory (brązowy), silniki (białe)
    p.append((11, 'oslona', -0.079, 0.4125, 0.0, 0.0, 0.0, 0.0, 0))
    p.append((12, 'oslona', 0.079, 0.4125, 0.0, 0.0, 0.0, 0.0, 0))
    p.append((13, 'sensory', 0.0, 0.4125, 0.0, 0.0, 0.0, 0.0, 0))
    for i, (x, y) in enumerate([(-0.17, 0.4625), (0.0, 0.533), (0.17, 0.4625)]):
        p.append((14 + i, 'silnik', x, y, 180.0, 0.0, 0.0, 0.0, 0))
    # obrona punktowa (czerwona linia): 60 działek, po 30 na krawędziach klina od dziobu do narożników rufy
    p += _krawedz((0.0, -0.5), (-0.29, 0.41), 30, 101, 'pd', 5.0, 8.0, 1500, 'L')
    p += _krawedz((0.0, -0.5), (0.29, 0.41), 30, 131, 'pd', 5.0, 8.0, 1500, 'P')
    return p


def _mc80():
    p = []
    for i, y in enumerate([-0.15, 0.05, 0.25]):
        p.append((1 + i, 'turbolaser', -0.095, y, -90.0, 80.0, 40.0, 1.0, 2187))
        p.append((4 + i, 'turbolaser', 0.095, y, 90.0, 80.0, 40.0, 1.0, 2187))
    for i, y in enumerate([-0.3, 0.0, 0.3]):
        p.append((7 + i, 'jon', -0.05, y, -90.0, 100.0, 20.0, 1.5, 1458))
        p.append((10 + i, 'jon', 0.05, y, 90.0, 100.0, 20.0, 1.5, 1458))
    for i, (x, y) in enumerate([(-0.15, -0.1), (-0.15, 0.1), (-0.15, 0.25), (0.15, -0.1), (0.15, 0.1), (0.15, 0.25)]):
        p.append((13 + i, 'oslona', x, y, 0.0, 0.0, 0.0, 0.0, 0))
    p.append((19, 'sensory', 0.0, -0.27, 0.0, 0.0, 0.0, 0.0, 0))
    for i, x in enumerate([-0.05, 0.0, 0.05]):
        p.append((20 + i, 'silnik', x, 0.5, 180.0, 0.0, 0.0, 0.0, 0))
    p += _krawedz((-0.09, -0.4), (-0.09, 0.42), 14, 101, 'pd', 5.0, 8.0, 1458, 'L')
    p += _krawedz((0.09, -0.4), (0.09, 0.42), 13, 115, 'pd', 5.0, 8.0, 1458, 'P')
    return p


UZBROJENIE = {'ISD_I': _isd(), 'MC80': _mc80()}

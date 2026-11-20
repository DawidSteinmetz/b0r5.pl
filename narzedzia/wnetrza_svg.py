"""Generator schematycznych grafik wnętrz okrętu (assets/wnetrza/*.svg) i ich listy (assets/wnetrza/lista.json).

Wspólny szkielet: pomieszczenie w perspektywie jednopunktowej (sufit z pasami światła, ściany z panelami,
podłoga z kratownicą), na nim wyposażenie charakterystyczne dla pomieszczenia. Paleta imperialna: szarości,
akcent turkusowy (konsole), czerwony (ostrzeżenia), bursztynowy (oznaczenia). Uruchomienie: python narzedzia/wnetrza_svg.py
"""
import json, os

W, H = 1600, 900
VX, VY = 800, 430                      # punkt zbiegu
TYL = (480, 250, 1120, 610)            # tylna ściana: x0, y0, x1, y1
KOL = dict(sufit='#1c2125', sciana='#262c31', sciana2='#21262b', tyl='#2d343a', podloga='#14181b',
           linia='#3d4851', linia2='#56636d', akcent='#4de2c9', czerwony='#ff5a4f', bursztyn='#f2b84b', biel='#d9dee2')


def pkt(*p):
    return ' '.join(f'{x:.0f},{y:.0f}' for x, y in p)


def poly(p, fill, stroke=None, sw=2, op=1):
    s = f' stroke="{stroke}" stroke-width="{sw}"' if stroke else ''
    o = f' opacity="{op}"' if op != 1 else ''
    return f'<polygon points="{pkt(*p)}" fill="{fill}"{s}{o}/>'


def rect(x, y, w, h, fill, stroke=None, sw=2, rx=0, op=1):
    s = f' stroke="{stroke}" stroke-width="{sw}"' if stroke else ''
    r = f' rx="{rx}"' if rx else ''
    o = f' opacity="{op}"' if op != 1 else ''
    return f'<rect x="{x:.0f}" y="{y:.0f}" width="{w:.0f}" height="{h:.0f}" fill="{fill}"{s}{r}{o}/>'


def linia(x1, y1, x2, y2, kolor, sw=2, op=1):
    o = f' opacity="{op}"' if op != 1 else ''
    return f'<line x1="{x1:.0f}" y1="{y1:.0f}" x2="{x2:.0f}" y2="{y2:.0f}" stroke="{kolor}" stroke-width="{sw}"{o}/>'


def elipsa(cx, cy, rx, ry, fill, stroke=None, sw=2, op=1):
    s = f' stroke="{stroke}" stroke-width="{sw}"' if stroke else ''
    o = f' opacity="{op}"' if op != 1 else ''
    return f'<ellipse cx="{cx:.0f}" cy="{cy:.0f}" rx="{rx:.0f}" ry="{ry:.0f}" fill="{fill}"{s}{o}/>'


def mix(a, b, t):
    return a + (b - a) * t


def na_podlodze(u, v):
    """Punkt podłogi: u ∈ [0,1] w poprzek (0 lewa, 1 prawa), v ∈ [0,1] w głąb (0 przód, 1 tylna ściana)."""
    x0, y0, x1, y1 = TYL
    lewo = (mix(0, x0, v), mix(H, y1, v))
    prawo = (mix(W, x1, v), mix(H, y1, v))
    return (mix(lewo[0], prawo[0], u), lewo[1])


def na_scianie(strona, v, h):
    """Punkt ściany bocznej: v w głąb (0 przód, 1 tył), h w pionie (0 podłoga, 1 sufit)."""
    x0, y0, x1, y1 = TYL
    if strona == 'lewa':
        x = mix(0, x0, v); dol = mix(H, y1, v); gora = mix(0, y0, v)
    else:
        x = mix(W, x1, v); dol = mix(H, y1, v); gora = mix(0, y0, v)
    return (x, mix(dol, gora, h))


def szkielet(tyl=None):
    x0, y0, x1, y1 = tyl or TYL
    e = [f'<defs><linearGradient id="sw" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#9fe7ff" stop-opacity=".35"/>'
         f'<stop offset="1" stop-color="#9fe7ff" stop-opacity="0"/></linearGradient>'
         f'<radialGradient id="cien" cx=".5" cy=".5" r=".75"><stop offset=".55" stop-color="#000" stop-opacity="0"/>'
         f'<stop offset="1" stop-color="#000" stop-opacity=".55"/></radialGradient></defs>']
    e.append(rect(0, 0, W, H, KOL['podloga']))
    e.append(poly([(0, 0), (W, 0), (x1, y0), (x0, y0)], KOL['sufit']))
    e.append(poly([(0, 0), (x0, y0), (x0, y1), (0, H)], KOL['sciana']))
    e.append(poly([(W, 0), (x1, y0), (x1, y1), (W, H)], KOL['sciana2']))
    e.append(poly([(0, H), (W, H), (x1, y1), (x0, y1)], KOL['podloga']))
    e.append(rect(x0, y0, x1 - x0, y1 - y0, KOL['tyl'], KOL['linia'], 3))
    # pasy światła w suficie i kratownica podłogi
    for u in (0.3, 0.7):
        a = (mix(0, W, u), 0); b = (mix(x0, x1, u), y0)
        e.append(linia(*a, *b, '#cfefff', 6, 0.55))
    for v in (0.25, 0.5, 0.75):
        l = na_podlodze(0, v); p = na_podlodze(1, v)
        e.append(linia(*l, *p, KOL['linia'], 2, 0.6))
    for u in (0.2, 0.4, 0.6, 0.8):
        a = (mix(0, W, u), H); b = (mix(x0, x1, u), y1)
        e.append(linia(*a, *b, KOL['linia'], 2, 0.5))
    # panele ścian bocznych
    for v in (0.2, 0.45, 0.7):
        for s in ('lewa', 'prawa'):
            a = na_scianie(s, v, 0.05); b = na_scianie(s, v, 0.95)
            e.append(linia(*a, *b, KOL['linia'], 3, 0.8))
    return e


def zakoncz(e):
    e.append(rect(0, 0, W, H, 'url(#cien)'))
    return f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" preserveAspectRatio="xMidYMid slice">' + ''.join(e) + '</svg>'


def konsola(x, y, w, h, kolor=None):
    k = kolor or KOL['akcent']
    e = [rect(x, y, w, h, '#30383e', KOL['linia2'], 2, 4)]
    e.append(rect(x + 8, y + 8, w - 16, h * 0.35, '#0e1a1c', k, 1.5, 2, 0.9))
    for i in range(int(w // 26)):
        e.append(rect(x + 10 + i * 26, y + h * 0.6, 14, 6, [k, KOL['bursztyn'], KOL['czerwony']][i % 3], op=0.8))
    return ''.join(e)


def podloga_obiekt(u0, u1, v0, v1, wys, kolor, krawedz=None):
    """Prostopadłościan stojący na podłodze (stół, łóżko, skrzynia): rzut w perspektywie."""
    a = na_podlodze(u0, v0); b = na_podlodze(u1, v0); c = na_podlodze(u1, v1); d = na_podlodze(u0, v1)
    sk = lambda p, s: (p[0], p[1] - wys * s)
    s0 = 1 - v0 * 0.55; s1 = 1 - v1 * 0.55
    gora = [sk(a, s0), sk(b, s0), sk(c, s1), sk(d, s1)]
    k = krawedz or KOL['linia2']
    return poly([a, b, sk(b, s0), sk(a, s0)], kolor, k, 2) + poly(gora, kolor, k, 2, 1) + poly([b, c, sk(c, s1), sk(b, s0)], kolor, k, 2, 0.85)


# ---------- pomieszczenia ----------

def winda():
    e = szkielet((600, 220, 1000, 660))
    e.append(rect(640, 260, 160, 400, '#3a434a', KOL['linia2'], 3))
    e.append(rect(800, 260, 160, 400, '#363f46', KOL['linia2'], 3))
    e.append(linia(800, 260, 800, 660, '#0b0e10', 6))
    for y in (300, 620):
        e.append(rect(640, y, 320, 8, KOL['bursztyn'], op=0.7))
    e.append(rect(1015, 380, 60, 110, '#30383e', KOL['linia2'], 2, 4))
    for i in range(5):
        e.append(elipsa(1045, 398 + i * 19, 8, 6, KOL['akcent'] if i != 2 else KOL['czerwony'], op=0.9))
    for s in ('lewa', 'prawa'):
        for v in (0.35, 0.65):
            a = na_scianie(s, v, 0.15); b = na_scianie(s, v, 0.9)
            e.append(linia(*a, *b, '#bfe9ff', 5, 0.6))
    e.append(elipsa(800, 780, 360, 70, 'none', KOL['linia2'], 4, 0.8))
    e.append(elipsa(800, 780, 300, 55, 'none', KOL['bursztyn'], 2, 0.5))
    return zakoncz(e)


def stolowka():
    e = szkielet()
    e.append(rect(520, 420, 560, 120, '#3a434a', KOL['linia2'], 3))
    for i in range(6):
        e.append(rect(540 + i * 88, 440, 70, 36, '#0e1a1c', KOL['akcent'], 1.5, 3, 0.8))
    e.append(rect(520, 300, 560, 60, '#1a1f23', KOL['linia'], 2))
    for u0 in (0.12, 0.58):
        e.append(podloga_obiekt(u0, u0 + 0.3, 0.15, 0.62, 70, '#3c464d'))
        e.append(podloga_obiekt(u0 - 0.04, u0 - 0.005, 0.15, 0.62, 38, '#2c343a'))
        e.append(podloga_obiekt(u0 + 0.305, u0 + 0.345, 0.15, 0.62, 38, '#2c343a'))
    for u in (0.2, 0.32, 0.66, 0.78):
        p = na_podlodze(u, 0.35)
        e.append(elipsa(p[0], p[1] - 70 * 0.8, 18, 6, KOL['biel'], op=0.6))
    return zakoncz(e)


def umywalnia():
    e = szkielet()
    for i in range(4):
        x = 500 + i * 155
        e.append(rect(x, 270, 130, 330, '#323a40', KOL['linia2'], 3))
        e.append(rect(x + 15, 290, 100, 40, '#cfefff', op=0.15))
        for j in range(5):
            e.append(linia(x + 30 + j * 18, 340, x + 22 + j * 18, 560, '#9fe7ff', 1.5, 0.35))
    for v in (0.25, 0.5, 0.75):
        a = na_scianie('lewa', v - 0.08, 0.4); b = na_scianie('lewa', v + 0.08, 0.4)
        c = na_scianie('lewa', v + 0.08, 0.32); d = na_scianie('lewa', v - 0.08, 0.32)
        e.append(poly([a, b, c, d], '#cfd6db', KOL['linia2'], 2, 0.85))
        m1 = na_scianie('lewa', v - 0.07, 0.75); m2 = na_scianie('lewa', v + 0.07, 0.75)
        m3 = na_scianie('lewa', v + 0.07, 0.48); m4 = na_scianie('lewa', v - 0.07, 0.48)
        e.append(poly([m1, m2, m3, m4], '#8fb7c4', KOL['linia2'], 2, 0.35))
    for u in (0.35, 0.65):
        p = na_podlodze(u, 0.3)
        e.append(rect(p[0] - 30, p[1] - 6, 60, 12, '#0b0e10', KOL['linia'], 1))
    return zakoncz(e)


def szatnia():
    e = szkielet()
    for s in ('lewa', 'prawa'):
        for i in range(6):
            v0 = 0.08 + i * 0.14; v1 = v0 + 0.12
            a = na_scianie(s, v0, 0.08); b = na_scianie(s, v1, 0.08); c = na_scianie(s, v1, 0.85); d = na_scianie(s, v0, 0.85)
            e.append(poly([a, b, c, d], '#3a444b', KOL['linia2'], 2))
            for h in (0.7, 0.66, 0.62):
                p = na_scianie(s, v0 + 0.03, h); q = na_scianie(s, v1 - 0.03, h)
                e.append(linia(*p, *q, '#1a1f23', 3))
    for i in range(5):
        x = 540 + i * 115
        e.append(rect(x, 330, 90, 8, KOL['linia2']))
        e.append(elipsa(x + 45, 310, 30, 26, KOL['biel'], KOL['linia2'], 2, 0.9))
        e.append(rect(x + 25, 312, 40, 10, '#1a1f23', op=0.9))
    e.append(podloga_obiekt(0.3, 0.7, 0.2, 0.35, 45, '#3c464d'))
    return zakoncz(e)


def reaktor():
    e = szkielet((380, 120, 1220, 700))
    e.append(rect(700, 120, 200, 580, '#0e2a2e', KOL['akcent'], 3))
    e.append(rect(760, 120, 80, 580, '#7ff3e0', op=0.35))
    e.append(rect(785, 120, 30, 580, '#e8fffb', op=0.6))
    for y in range(170, 700, 90):
        e.append(elipsa(800, y, 150, 22, 'none', KOL['linia2'], 6))
    e.append(elipsa(800, 600, 360, 70, '#262c31', KOL['linia2'], 4))
    e.append(elipsa(800, 600, 360, 70, 'none', KOL['bursztyn'], 2, 0.5))
    for x in range(470, 1140, 60):
        e.append(linia(x, 540, x, 600, KOL['linia2'], 3))
    e.append(linia(440, 540, 1160, 540, KOL['linia2'], 4))
    e.append(rect(0, 0, W, H, '#4de2c9', op=0.05))
    return zakoncz(e)


def korytarz():
    e = szkielet((700, 340, 900, 500))
    for v in (0.15, 0.4, 0.62, 0.8):
        a = (mix(0, 700, v) + mix(W, 900, v)) / 2
        y = mix(0, 340, v)
        w = mix(500, 60, v)
        e.append(rect(a - w / 2, y + 4, w, mix(18, 4, v), '#dff6ff', op=0.6))
    for s in ('lewa', 'prawa'):
        for v in (0.3, 0.65):
            a = na_scianie(s, v, 0.0); b = na_scianie(s, v + 0.12, 0.0); c = na_scianie(s, v + 0.12, 0.7); d = na_scianie(s, v, 0.7)
            e.append(poly([a, b, c, d], '#353e45', KOL['linia2'], 2))
            p = na_scianie(s, v + 0.135, 0.42)
            e.append(elipsa(p[0], p[1], 6, 6, KOL['czerwony'], op=0.8))
    a = na_podlodze(0.47, 0.0); b = na_podlodze(0.53, 0.0); c = na_podlodze(0.53, 1); d = na_podlodze(0.47, 1)
    e.append(poly([a, b, c, d], KOL['bursztyn'], op=0.25))
    return zakoncz(e)


def kajuta():
    e = szkielet()
    e.append(podloga_obiekt(0.62, 0.98, 0.25, 0.8, 90, '#3a444b'))
    e.append(podloga_obiekt(0.64, 0.96, 0.6, 0.78, 110, '#8e9aa3'))
    e.append(podloga_obiekt(0.06, 0.34, 0.35, 0.65, 110, '#333c42'))
    p = na_podlodze(0.2, 0.55)
    e.append(rect(p[0] - 70, p[1] - 230, 140, 90, '#0e1a1c', KOL['akcent'], 2, 4))
    e.append(rect(560, 300, 220, 270, '#353e45', KOL['linia2'], 3))
    e.append(rect(820, 330, 260, 120, '#11181b', KOL['linia2'], 3))
    e.append(rect(840, 350, 220, 80, '#0b1a2a', op=0.9))
    for i in range(18):
        e.append(elipsa(850 + (i * 53) % 210, 360 + (i * 29) % 60, 1.5, 1.5, KOL['biel'], op=0.7))
    return zakoncz(e)


def koszary():
    e = szkielet()
    for s, u0, u1 in (('lewa', 0.03, 0.3), ('prawa', 0.7, 0.97)):
        for v0 in (0.08, 0.4, 0.72):
            for wys in (60, 190):
                e.append(podloga_obiekt(u0, u1, v0, v0 + 0.22, wys, '#3a444b'))
                e.append(podloga_obiekt(u0 + 0.01, u1 - 0.01, v0 + 0.01, v0 + 0.21, wys + 14, '#8e9aa3'))
    e.append(rect(560, 300, 480, 40, KOL['czerwony'], op=0.15))
    return zakoncz(e)


def hangar():
    e = szkielet((300, 160, 1300, 640))
    e.append(rect(360, 200, 880, 380, '#0a1220', KOL['linia2'], 4))
    e.append(rect(360, 200, 880, 380, '#4da3ff', op=0.12))
    for i in range(40):
        e.append(elipsa(380 + (i * 97) % 860, 215 + (i * 53) % 350, 1.6, 1.6, KOL['biel'], op=0.7))
    for x in range(380, 1240, 110):
        e.append(rect(x, 140, 60, 40, '#30383e', KOL['linia2'], 2))
        e.append(linia(x + 30, 180, x + 30, 230, KOL['linia2'], 4))
    for u in (0.2, 0.5, 0.8):
        a = na_podlodze(u - 0.01, 0); b = na_podlodze(u + 0.01, 0); c = na_podlodze(u + 0.01, 1); d = na_podlodze(u - 0.01, 1)
        e.append(poly([a, b, c, d], KOL['bursztyn'], op=0.4))
    return zakoncz(e)


def ambulatorium():
    e = szkielet()
    e.append(rect(700, 270, 200, 330, '#0e2a2e', KOL['linia2'], 4, 90))
    e.append(rect(715, 300, 170, 280, '#4de2c9', op=0.25, rx=80))
    for i in range(6):
        e.append(elipsa(760 + (i * 31) % 90, 540 - i * 40, 6, 6, '#e8fffb', op=0.5))
    e.append(podloga_obiekt(0.12, 0.38, 0.2, 0.6, 80, '#9aa6ae'))
    e.append(podloga_obiekt(0.62, 0.88, 0.2, 0.6, 80, '#9aa6ae'))
    for x in (520, 1000):
        e.append(rect(x, 330, 90, 60, '#0e1a1c', KOL['akcent'], 2, 4))
        e.append(f'<polyline points="{x + 8},360 {x + 30},360 {x + 40},340 {x + 52},378 {x + 62},360 {x + 82},360" fill="none" stroke="{KOL["akcent"]}" stroke-width="2"/>')
    return zakoncz(e)


def cela():
    e = szkielet((560, 260, 1040, 600))
    e.append(podloga_obiekt(0.3, 0.7, 0.75, 0.97, 60, '#3a444b'))
    e.append(rect(560, 260, 480, 340, '#2d343a', KOL['linia2'], 3))
    e.append(rect(0, 0, W, H, KOL['czerwony'], op=0.06))
    for x in range(120, 1500, 70):
        e.append(linia(x, 40, x, 860, KOL['czerwony'], 3, 0.35))
    e.append(rect(80, 20, 1440, 24, '#30383e', KOL['linia2'], 2))
    e.append(rect(80, 856, 1440, 24, '#30383e', KOL['linia2'], 2))
    return zakoncz(e)


def maszynownia():
    e = szkielet((420, 200, 1180, 640))
    for x in (480, 700, 920):
        e.append(rect(x, 260, 180, 380, '#323a40', KOL['linia2'], 3, 20))
        e.append(rect(x + 20, 300, 140, 40, KOL['akcent'], op=0.35))
        for y in (380, 450, 520):
            e.append(linia(x, y, x + 180, y, KOL['linia2'], 3))
    for s in ('lewa', 'prawa'):
        for h in (0.65, 0.72, 0.79):
            a = na_scianie(s, 0, h); b = na_scianie(s, 1, h)
            e.append(linia(*a, *b, '#4b565f', 10))
    for i in range(12):
        a = na_podlodze(0.05 + i * 0.075, 0.02); b = na_podlodze(0.09 + i * 0.075, 0.02)
        e.append(linia(a[0], a[1] - 12, b[0], b[1] - 12, KOL['bursztyn'] if i % 2 else '#111', 10, 0.8))
    return zakoncz(e)


def centrum():
    e = szkielet()
    e.append(rect(520, 270, 560, 230, '#0b1a1f', KOL['akcent'], 3, 6))
    e.append(elipsa(800, 385, 200, 80, 'none', KOL['akcent'], 2, 0.6))
    e.append(elipsa(800, 385, 120, 46, 'none', KOL['akcent'], 2, 0.4))
    for i in range(7):
        e.append(elipsa(650 + i * 50, 340 + (i * 37) % 90, 5, 5, KOL['bursztyn'] if i % 3 else KOL['czerwony'], op=0.85))
    for v, us in ((0.25, (0.08, 0.37, 0.63)), (0.6, (0.15, 0.42, 0.66))):
        for u in us:
            e.append(podloga_obiekt(u, u + 0.24, v, v + 0.12, 75, '#323a40'))
            p = na_podlodze(u + 0.12, v + 0.12)
            e.append(rect(p[0] - 60, p[1] - 115, 120, 30, '#0e1a1c', KOL['akcent'], 1.5, 3, 0.8))
    return zakoncz(e)


def zbrojownia():
    e = szkielet()
    for s in ('lewa', 'prawa'):
        for i in range(10):
            v = 0.08 + i * 0.085
            a = na_scianie(s, v, 0.25); b = na_scianie(s, v + 0.02, 0.78)
            e.append(linia(*a, *b, '#15191c', 7))
            e.append(linia(*a, *b, '#4b565f', 3))
        a = na_scianie(s, 0.05, 0.22); b = na_scianie(s, 0.95, 0.22)
        e.append(linia(*a, *b, KOL['linia2'], 6))
    for u, v in ((0.3, 0.3), (0.55, 0.45), (0.42, 0.65)):
        e.append(podloga_obiekt(u, u + 0.14, v, v + 0.12, 60, '#3d4a3f', KOL['bursztyn']))
    e.append(rect(560, 290, 480, 40, KOL['bursztyn'], op=0.25))
    return zakoncz(e)


def magazyn():
    e = szkielet()
    for u, v, wys, k in ((0.05, 0.2, 110, '#3d4a3f'), (0.05, 0.2, 220, '#46514a'), (0.2, 0.5, 90, '#3a444b'),
                         (0.62, 0.25, 120, '#3d4a3f'), (0.78, 0.2, 100, '#3a444b'), (0.78, 0.2, 200, '#46514a'), (0.45, 0.7, 70, '#3d4a3f')):
        e.append(podloga_obiekt(u, u + 0.16, v, v + 0.14, wys, k))
    for x in range(520, 1080, 140):
        e.append(rect(x, 330, 110, 230, '#30383e', KOL['linia2'], 2))
        for y in (380, 450, 520):
            e.append(linia(x, y, x + 110, y, KOL['linia2'], 2))
    return zakoncz(e)


GRAFIKI = [
    ('isd-winda', 'Turbowinda', winda, ['wind']),
    ('isd-stolowka', 'Stołówka / mesa', stolowka, ['stołów', 'mesa', 'kantyn', 'jadal']),
    ('isd-umywalnia', 'Umywalnia', umywalnia, ['umywal', 'łaźni', 'sanitar', 'prysznic']),
    ('isd-szatnia', 'Szatnia / magazyn pancerzy', szatnia, ['szatni', 'pancerzy']),
    ('isd-reaktor', 'Główny reaktor', reaktor, ['komora reaktora', 'reaktor']),
    ('isd-korytarz', 'Korytarz', korytarz, ['korytarz', 'tunel', 'magistral']),
    ('isd-kajuta', 'Kajuta / kwatera oficerska', kajuta, ['kajut', 'kwater', 'apartament']),
    ('isd-koszary', 'Koszary', koszary, ['koszar']),
    ('isd-hangar', 'Hangar', hangar, ['hangar', 'zatoka']),
    ('isd-ambulatorium', 'Ambulatorium / szpital', ambulatorium, ['medycz', 'szpital', 'lekarz', 'zabieg', 'operac', 'izolatk', 'izba przyjęć']),
    ('isd-cela', 'Cela więzienna', cela, ['cele ', 'cele izol', 'zatrzyma', 'przesłuch']),
    ('isd-maszynownia', 'Maszynownia', maszynownia, ['maszynown', 'silnik', 'napęd', 'generator', 'chłodz']),
    ('isd-centrum', 'Centrum dowodzenia / kontroli', centrum, ['centrum', 'kontroli', 'stanowisk', 'sala odpraw']),
    ('isd-zbrojownia', 'Zbrojownia', zbrojownia, ['zbrojown', 'amunic', 'torped']),
    ('isd-magazyn', 'Magazyn', magazyn, ['magazyn', 'ładowni', 'apteka']),
]

if __name__ == '__main__':
    katalog = os.path.join(os.path.dirname(__file__), '..', 'assets', 'wnetrza')
    os.makedirs(katalog, exist_ok=True)
    lista = []
    for plik, nazwa, f, reguly in GRAFIKI:
        svg = f()
        with open(os.path.join(katalog, plik + '.svg'), 'w', encoding='utf-8', newline='\n') as h:
            h.write(svg)
        lista.append({'plik': f'assets/wnetrza/{plik}.svg', 'nazwa': nazwa, 'reguly': reguly})
        print(plik, len(svg))
    with open(os.path.join(katalog, 'lista.json'), 'w', encoding='utf-8', newline='\n') as h:
        json.dump(lista, h, ensure_ascii=False, indent=1)

"""Generator grafik wnętrz okrętu (assets/wnetrza/*.svg) i ich listy (assets/wnetrza/lista.json).

Postać stoi w pomieszczeniu, wyciąga tablet i patrzy na najbliższą ścianę — każda grafika to ściana widziana
na wprost: panele, pas światła pod stropem, posadzka u dołu i wyposażenie typowe dla pomieszczenia.
Ściany z oknem (plik „…-okno.svg”) mają przezroczysty otwór: tablet rysuje za nim prawdziwą scenę
(gwiazdy, planeta, nadprzestrzeń). Paleta imperialna: szarości, turkus (konsole), czerwień, bursztyn.
Uruchomienie: python narzedzia/wnetrza_svg.py
"""
import json, os

W, H = 1600, 900
K = dict(sciana='#2a3036', sciana2='#262c31', strop='#181c20', posadzka='#13171a', linia='#3b454d', linia2='#56626b',
         ciemny='#14181b', akcent='#4de2c9', czerwony='#ff5a4f', bursztyn='#f2b84b', biel='#d4dade', szary='#8e9aa3')


def a(**kw):
    return ''.join(f' {k.replace("_", "-")}="{v}"' for k, v in kw.items() if v is not None)


def rect(x, y, w, h, fill, stroke=None, sw=2, rx=None, op=None):
    return f'<rect x="{x:.0f}" y="{y:.0f}" width="{w:.0f}" height="{h:.0f}"{a(fill=fill, stroke=stroke, stroke_width=sw if stroke else None, rx=rx, opacity=op)}/>'


def linia(x1, y1, x2, y2, kolor, sw=2, op=None):
    return f'<line x1="{x1:.0f}" y1="{y1:.0f}" x2="{x2:.0f}" y2="{y2:.0f}"{a(stroke=kolor, stroke_width=sw, opacity=op)}/>'


def kolo(cx, cy, r, fill, stroke=None, sw=2, op=None):
    return f'<circle cx="{cx:.0f}" cy="{cy:.0f}" r="{r:.0f}"{a(fill=fill, stroke=stroke, stroke_width=sw if stroke else None, opacity=op)}/>'


def elipsa(cx, cy, rx, ry, fill, stroke=None, sw=2, op=None):
    return f'<ellipse cx="{cx:.0f}" cy="{cy:.0f}" rx="{rx:.0f}" ry="{ry:.0f}"{a(fill=fill, stroke=stroke, stroke_width=sw if stroke else None, opacity=op)}/>'


def sciana(otwory=(), panele=True):
    """Ściana na wprost: strop z pasem światła, panele, posadzka. `otwory`: przezroczyste okna ([x, y, w, h, r]
    albo ('kolo', cx, cy, r)) — wycięte maską, więc żaden element ściany ich nie zasłania."""
    maska = ''
    if otwory:
        dziury = ''.join(kolo(o[1], o[2], o[3], 'black') if o[0] == 'kolo' else rect(o[0], o[1], o[2], o[3], 'black', rx=o[4] if len(o) > 4 else None) for o in otwory)
        maska = f'<mask id="m" maskUnits="userSpaceOnUse" x="0" y="0" width="{W}" height="{H}">{rect(0, 0, W, H, "white")}{dziury}</mask>'
    e = [f'<defs>{maska}<linearGradient id="swiatlo" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#cfefff" stop-opacity=".22"/>'
         f'<stop offset="1" stop-color="#cfefff" stop-opacity="0"/></linearGradient>'
         f'<radialGradient id="cien" cx=".5" cy=".45" r=".8"><stop offset=".55" stop-color="#000" stop-opacity="0"/>'
         f'<stop offset="1" stop-color="#000" stop-opacity=".5"/></radialGradient></defs>']
    e.append(f'<g{" mask=\"url(#m)\"" if otwory else ""}>')
    e.append(rect(0, 0, W, H, K['sciana']))
    e.append(rect(0, 0, W, 86, K['strop']))
    e.append(rect(0, 86, W, 10, '#cfefff', op=0.55))
    e.append(rect(0, 96, W, 220, 'url(#swiatlo)'))
    e.append(rect(0, 800, W, 100, K['posadzka']))
    e.append(rect(0, 792, W, 8, K['linia2']))
    if panele:
        for x in range(0, W + 1, 200):
            e.append(linia(x, 96, x, 792, K['ciemny'], 3))
        for y in (330, 600):
            e.append(linia(0, y, W, y, K['ciemny'], 3))
        for x in range(100, W, 200):
            for y in (120, 770):
                e.append(kolo(x, y, 4, K['linia2']))
    return e


def zakoncz(e, otwory=()):
    e.append('</g>')
    for o in otwory:  # rama okna (poza maską, na krawędzi otworu)
        if o[0] == 'kolo':
            e.append(kolo(o[1], o[2], o[3] + 9, 'none', '#11161a', 18) + kolo(o[1], o[2], o[3] + 2, 'none', K['linia2'], 4))
        else:
            e.append(rect(o[0] - 9, o[1] - 9, o[2] + 18, o[3] + 18, 'none', '#11161a', 18, rx=(o[4] + 9) if len(o) > 4 else None))
            e.append(rect(o[0] - 1, o[1] - 1, o[2] + 2, o[3] + 2, 'none', K['linia2'], 4, rx=o[4] if len(o) > 4 else None))
    e.append(rect(0, 0, W, H, 'url(#cien)'))
    return f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" preserveAspectRatio="xMidYMid slice">' + ''.join(e) + '</svg>'


def konsola(x, y, w, h, kolor=None):
    k = kolor or K['akcent']
    e = [rect(x, y, w, h, '#30383e', K['linia2'], 2, 6), rect(x + 10, y + 10, w - 20, h * 0.45, '#0c181b', k, 1.5, 3)]
    for i in range(int((w - 20) // 30)):
        e.append(rect(x + 14 + i * 30, y + h * 0.66, 16, 8, [k, K['bursztyn'], K['czerwony']][i % 3], op=0.85))
    return ''.join(e)


# ---------- pomieszczenia (ściana na wprost) ----------

def winda():
    e = sciana(panele=False)
    e.append(rect(0, 96, W, 696, '#30373d'))
    for x in (260, 1340):
        e.append(rect(x - 6, 140, 12, 620, '#bfe9ff', op=0.55))
    e.append(rect(520, 150, 560, 642, '#1b2126', K['linia2'], 4))
    e.append(rect(540, 170, 258, 622, '#3a434a', K['linia2'], 3))
    e.append(rect(802, 170, 258, 622, '#363f46', K['linia2'], 3))
    for y in (230, 730):
        e.append(rect(540, y, 520, 10, K['bursztyn'], op=0.7))
    e.append(rect(700, 104, 200, 38, '#120808', K['linia2'], 2, 4))
    e.append(f'<text x="800" y="132" fill="{K["czerwony"]}" font-family="monospace" font-size="28" text-anchor="middle" letter-spacing="6">04 ▲</text>')
    e.append(rect(1140, 380, 70, 170, '#30383e', K['linia2'], 2, 6))
    for i in range(6):
        e.append(kolo(1175, 405 + i * 25, 8, K['akcent'] if i != 2 else K['czerwony'], op=0.9))
    return zakoncz(e)


def stolowka():
    e = sciana()
    e.append(rect(260, 140, 1080, 150, '#0c181b', K['akcent'], 2, 6))
    for i in range(5):
        e.append(rect(300 + i * 205, 175, 170, 16, K['akcent'], op=0.45))
        e.append(rect(300 + i * 205, 205, 120, 12, K['akcent'], op=0.25))
        e.append(rect(300 + i * 205, 235, 140, 12, K['bursztyn'], op=0.35))
    for i in range(6):
        x = 170 + i * 215
        e.append(rect(x, 350, 180, 250, '#1d2328', K['linia2'], 3, 8))
        e.append(rect(x + 70, 360, 40, 60, '#3a434a', K['linia2'], 2))
        e.append(rect(x + 85, 420, 10, 30, K['szary']))
        e.append(kolo(x + 150, 380, 7, K['akcent'] if i % 3 else K['bursztyn']))
    e.append(rect(100, 610, 1400, 26, '#3c464d', K['linia2'], 2))
    e.append(rect(100, 636, 1400, 150, '#2c343a'))
    for x in range(140, 1460, 160):
        e.append(rect(x, 590, 110, 22, K['szary'], op=0.5, rx=4))
    return zakoncz(e)


def umywalnia():
    e = sciana(panele=False)
    e.append(rect(0, 330, W, 462, '#b9c2c8', op=0.18))
    for x in range(0, W, 50):
        e.append(linia(x, 330, x, 792, K['ciemny'], 1.5, 0.6))
    for y in range(330, 792, 50):
        e.append(linia(0, y, W, y, K['ciemny'], 1.5, 0.6))
    for i in range(3):
        x = 260 + i * 420
        e.append(rect(x, 150, 260, 170, '#8fb7c4', K['linia2'], 4, 6, op=0.5))
        e.append(linia(x + 30, 170, x + 110, 300, '#e8f6ff', 3, 0.3))
        e.append(elipsa(x + 130, 470, 150, 34, '#cfd6db', K['linia2'], 3))
        e.append(elipsa(x + 130, 466, 110, 20, '#7f8b93'))
        e.append(rect(x + 120, 400, 20, 50, K['szary']))
        e.append(rect(x + 108, 504, 44, 280, '#9aa6ae', op=0.5))
        e.append(rect(x + 280, 360, 50, 80, '#30383e', K['linia2'], 2, 6))
    return zakoncz(e)


def szatnia():
    e = sciana(panele=False)
    for i in range(12):
        x = 40 + i * 128
        e.append(rect(x, 120, 118, 640, '#3a444b', K['linia2'], 2))
        for y in (170, 186, 202):
            e.append(rect(x + 25, y, 68, 7, K['ciemny']))
        e.append(rect(x + 92, 430, 10, 46, K['szary'], rx=3))
        e.append(f'<text x="{x + 59}" y="300" fill="{K["bursztyn"]}" font-family="monospace" font-size="22" text-anchor="middle" opacity=".7">{i + 11:02d}</text>')
    e.append(rect(220, 720, 1160, 40, '#4a555d', K['linia2'], 2, 6))
    for x in (300, 1300):
        e.append(rect(x, 760, 30, 40, '#30383e'))
    return zakoncz(e)


def reaktor():
    e = sciana()
    e.append(rect(330, 130, 940, 470, '#06171a', K['linia2'], 6, 12))
    e.append(rect(700, 130, 200, 470, '#0e2a2e'))
    e.append(rect(745, 130, 110, 470, '#7ff3e0', op=0.4))
    e.append(rect(785, 130, 30, 470, '#ecfffb', op=0.7))
    for y in range(170, 600, 70):
        e.append(elipsa(800, y, 170, 20, 'none', K['linia2'], 7))
    for x in (520, 1080):
        e.append(rect(x - 14, 130, 28, 470, '#1b2126'))
    e.append(rect(330, 130, 940, 470, K['akcent'], op=0.06))
    e.append(konsola(330, 630, 300, 140))
    e.append(konsola(970, 630, 300, 140))
    e.append(rect(660, 650, 280, 110, '#1d2328', K['czerwony'], 3, 6))
    e.append(f'<text x="800" y="718" fill="{K["czerwony"]}" font-family="monospace" font-size="30" text-anchor="middle">⚠ RDZEŃ</text>')
    return zakoncz(e)


def korytarz():
    e = sciana()
    e.append(rect(0, 400, W, 14, '#bfe9ff', op=0.35))
    e.append(rect(1080, 170, 330, 622, '#1b2126', K['linia2'], 4))
    e.append(rect(1100, 190, 145, 602, '#3a434a', K['linia2'], 2))
    e.append(rect(1245, 190, 145, 602, '#363f46', K['linia2'], 2))
    e.append(rect(1440, 430, 50, 90, '#30383e', K['linia2'], 2, 6))
    e.append(kolo(1465, 455, 9, K['czerwony']))
    e.append(kolo(1465, 490, 9, K['akcent']))
    for i, (x, w) in enumerate(((120, 300), (480, 180), (120, 220))):
        e.append(rect(x, 460 + i * 50, w, 26, K['bursztyn'] if i != 1 else K['akcent'], op=0.45, rx=3))
    e.append(f'<polygon points="700,475 760,500 700,525" fill="{K["akcent"]}" opacity=".45"/>')
    return zakoncz(e)


def kajuta():
    e = sciana()
    e.append(rect(80, 520, 820, 70, '#3a444b', K['linia2'], 3, 6))
    e.append(rect(90, 470, 800, 60, '#7e8a92', K['linia2'], 2, 10))
    e.append(rect(100, 455, 170, 50, K['biel'], op=0.6, rx=14))
    e.append(rect(80, 590, 820, 200, '#262d33'))
    e.append(rect(80, 230, 820, 18, '#4a555d'))
    for x in range(110, 880, 90):
        e.append(rect(x, 180, 60, 50, ['#4a555d', '#3d4a3f', '#55606a'][x % 3], K['linia2'], 1))
    e.append(rect(1000, 220, 320, 210, '#11181b', K['linia2'], 4, 6))
    e.append(rect(1020, 240, 280, 170, '#0c1a22'))
    for i in range(6):
        e.append(rect(1040, 260 + i * 24, 120 + (i * 37) % 120, 10, K['akcent'], op=0.45))
    e.append(rect(1000, 520, 380, 270, '#30383e', K['linia2'], 3))
    e.append(rect(1180, 620, 20, 60, K['szary'], rx=4))
    return zakoncz(e)


def koszary():
    e = sciana()
    for i in range(3):
        x = 70 + i * 500
        for y in (250, 520):
            e.append(rect(x, y, 460, 50, '#3a444b', K['linia2'], 3, 6))
            e.append(rect(x + 8, y - 34, 444, 38, '#7e8a92', K['linia2'], 2, 8))
            e.append(rect(x + 16, y - 46, 110, 30, K['biel'], op=0.55, rx=10))
        for xx in (x, x + 450):
            e.append(rect(xx, 140, 10, 652, '#4a555d'))
        e.append(rect(x + 160, 610, 140, 180, '#30383e', K['linia2'], 2))
    return zakoncz(e)


def hangar_sciana():
    e = sciana()
    for x in range(120, 1500, 300):
        e.append(rect(x, 130, 220, 360, '#1d2328', K['linia2'], 3))
        for y in range(160, 470, 50):
            e.append(linia(x + 10, y, x + 210, y, K['linia2'], 3))
            e.append(rect(x + 30 + (y % 3) * 40, y - 30, 30, 26, ['#4a555d', K['bursztyn'], '#3d4a3f'][y % 3], op=0.8))
    for y in (540, 580):
        e.append(rect(0, y, W, 16, '#4b565f'))
    e.append(rect(0, 640, W, 30, K['bursztyn'], op=0.45))
    for x in range(0, W, 60):
        e.append(f'<polygon points="{x},640 {x + 30},640 {x},670" fill="{K["ciemny"]}" opacity=".7"/>')
    e.append(f'<text x="800" y="740" fill="{K["bursztyn"]}" font-family="monospace" font-size="40" text-anchor="middle" letter-spacing="10" opacity=".7">HANGAR 2</text>')
    return zakoncz(e)


def ambulatorium():
    e = sciana()
    e.append(rect(660, 120, 280, 640, '#0e2a2e', K['linia2'], 6, 120))
    e.append(rect(685, 150, 230, 580, '#4de2c9', op=0.28, rx=105))
    for i in range(9):
        e.append(kolo(730 + (i * 47) % 150, 690 - i * 60, 6 + i % 3, '#e8fffb', op=0.45))
    for x in (250, 1080):
        e.append(rect(x, 180, 270, 170, '#0c181b', K['akcent'], 3, 8))
        e.append(f'<polyline points="{x + 20},270 {x + 80},270 {x + 100},220 {x + 125},320 {x + 150},270 {x + 250},270" fill="none" stroke="{K["akcent"]}" stroke-width="3"/>')
        e.append(rect(x, 420, 270, 330, '#cfd6db', K['linia2'], 3, op=0.35))
        for y in (500, 580, 660):
            e.append(linia(x, y, x + 270, y, K['linia2'], 2))
    e.append(rect(395, 460, 20, 20, K['czerwony'], op=0.8))
    e.append(rect(387, 468, 36, 4, K['czerwony'], op=0.8))
    return zakoncz(e)


def cela():
    e = sciana()
    e.append(rect(300, 520, 1000, 60, '#3a444b', K['linia2'], 3, 6))
    e.append(rect(300, 580, 1000, 210, '#262d33'))
    e.append(rect(1380, 150, 140, 200, '#30383e', K['linia2'], 2))
    e.append(rect(0, 0, W, H, K['czerwony'], op=0.07))
    for x in range(40, W, 64):
        e.append(linia(x, 0, x, H, K['czerwony'], 3, 0.32))
    for y in (20, 880):
        e.append(rect(0, y - 14, W, 28, '#30383e', K['linia2'], 2))
    return zakoncz(e)


def maszynownia():
    e = sciana(panele=False)
    e.append(rect(0, 96, W, 696, '#262c31'))
    for y, k in ((150, '#4b565f'), (210, '#55606a'), (690, '#4b565f')):
        e.append(rect(0, y, W, 34, k))
        for x in range(80, W, 260):
            e.append(rect(x, y - 6, 30, 46, '#2b3238'))
    for x in (240, 640, 1040, 1440):
        e.append(rect(x - 20, 96, 40, 696, '#3e4850'))
    for x in (440, 840, 1240):
        e.append(kolo(x, 400, 70, '#11161a', K['linia2'], 6))
        e.append(linia(x, 400, x + 40, 360, K['czerwony'], 5))
        for i in range(8):
            import math
            ang = math.pi * (0.75 + i * 0.21)
            e.append(linia(x + 55 * math.cos(ang), 400 + 55 * math.sin(ang), x + 65 * math.cos(ang), 400 + 65 * math.sin(ang), K['biel'], 3))
        e.append(rect(x - 50, 500, 100, 50, '#30383e', K['linia2'], 2, 6))
        e.append(rect(x - 40, 512, 80, 26, K['akcent'], op=0.4))
    for x in range(0, W, 60):
        e.append(f'<polygon points="{x},760 {x + 30},760 {x},792" fill="{K["bursztyn"]}" opacity=".6"/>')
    return zakoncz(e)


def centrum():
    e = sciana()
    e.append(rect(200, 120, 1200, 460, '#06121a', K['akcent'], 4, 10))
    for x in range(260, 1400, 80):
        e.append(linia(x, 140, x, 560, K['akcent'], 1, 0.2))
    for y in range(160, 580, 70):
        e.append(linia(220, y, 1380, y, K['akcent'], 1, 0.2))
    e.append(elipsa(800, 350, 330, 150, 'none', K['akcent'], 2, 0.6))
    e.append(elipsa(800, 350, 180, 80, 'none', K['akcent'], 2, 0.45))
    for i in range(9):
        e.append(kolo(560 + (i * 113) % 520, 250 + (i * 71) % 200, 8, K['bursztyn'] if i % 3 else K['czerwony'], op=0.9))
    for x in (200, 610, 1020):
        e.append(konsola(x, 620, 380, 160))
    return zakoncz(e)


def zbrojownia():
    e = sciana()
    for rzad, y in enumerate((140, 470)):
        e.append(rect(60, y + 290, 1480, 16, '#4a555d'))
        for i in range(22):
            x = 90 + i * 66
            e.append(rect(x, y + 20, 14, 270, '#1a1f23'))
            e.append(rect(x - 6, y + 160, 26, 40, '#2b3238'))
            e.append(rect(x + 2, y + 30, 6, 60, '#4b565f'))
    e.append(rect(560, 105, 480, 30, K['bursztyn'], op=0.35))
    return zakoncz(e)


def magazyn():
    e = sciana()
    for x in range(60, 1540, 370):
        e.append(rect(x, 120, 340, 672, 'none', K['linia2'], 4))
        for y in (300, 480, 660):
            e.append(rect(x, y, 340, 14, '#4a555d'))
        for j, y in enumerate((300, 480, 660)):
            for k in range(3):
                h = 90 + ((x + j * 7 + k * 13) % 60)
                e.append(rect(x + 14 + k * 108, y - h, 96, h, ['#3d4a3f', '#46514a', '#3a444b'][(j + k) % 3], K['linia2'], 2))
                e.append(rect(x + 30 + k * 108, y - h + 14, 40, 10, K['bursztyn'], op=0.5))
    return zakoncz(e)


def iluminator_okno():
    otw = (('kolo', 520, 430, 190), ('kolo', 1080, 430, 190))
    e = sciana(otw)
    for x in (520, 1080):
        for i in range(8):
            import math
            ang = i * math.pi / 4
            e.append(kolo(x + 214 * math.cos(ang), 430 + 214 * math.sin(ang), 7, K['linia2']))
    e.append(konsola(700, 650, 200, 120))
    return zakoncz(e, otw)


def panorama_okno():
    otw = ((120, 150, 1360, 420, 18),)
    e = sciana(otw)
    e.append(rect(80, 600, 1440, 30, '#3c464d', K['linia2'], 2))
    e.append(rect(80, 630, 1440, 160, '#262d33'))
    e.append('</g>')
    for x in (460, 800, 1140):  # słupki między szybami (na wierzchu szyby)
        e.append(rect(x - 10, 150, 20, 420, '#11161a'))
    e.append(f'<g>')
    return zakoncz(e, otw)


GRAFIKI = [
    ('isd-winda', 'Turbowinda', winda, ['wind']),
    ('isd-stolowka', 'Stołówka / mesa', stolowka, ['stołów', 'mesa', 'kantyn', 'jadal']),
    ('isd-umywalnia', 'Umywalnia', umywalnia, ['umywal', 'łaźni', 'sanitar', 'prysznic']),
    ('isd-szatnia', 'Szatnia / magazyn pancerzy', szatnia, ['szatni', 'pancerzy']),
    ('isd-reaktor', 'Reaktor (okno na rdzeń)', reaktor, ['komora reaktora', 'reaktor']),
    ('isd-korytarz', 'Korytarz', korytarz, ['korytarz', 'tunel', 'magistral']),
    ('isd-kajuta', 'Kajuta / kwatera oficerska', kajuta, ['kajut', 'kwater', 'apartament']),
    ('isd-koszary', 'Koszary', koszary, ['koszar']),
    ('isd-hangar', 'Ściana hangaru', hangar_sciana, ['stanowisko obsługi', 'strefa tankow', 'strefa dozbraj']),
    ('isd-ambulatorium', 'Ambulatorium / szpital', ambulatorium, ['medycz', 'szpital', 'lekarz', 'zabieg', 'operac', 'izolatk', 'izba przyjęć']),
    ('isd-cela', 'Cela więzienna', cela, ['cele ', 'cele izol', 'zatrzyma', 'przesłuch']),
    ('isd-maszynownia', 'Maszynownia', maszynownia, ['maszynown', 'silnik', 'napęd', 'generator', 'chłodz']),
    ('isd-centrum', 'Centrum dowodzenia / kontroli', centrum, ['centrum', 'kontroli', 'sala odpraw']),
    ('isd-zbrojownia', 'Zbrojownia', zbrojownia, ['zbrojown', 'amunic', 'torped']),
    ('isd-magazyn', 'Magazyn', magazyn, ['magazyn', 'ładowni', 'apteka']),
    ('isd-iluminator-okno', 'Iluminatory (okno)', iluminator_okno, ['iluminator']),
    ('isd-panorama-okno', 'Okno obserwacyjne (okno)', panorama_okno, ['obserwac', 'widokow', 'salon']),
]

if __name__ == '__main__':
    katalog = os.path.join(os.path.dirname(__file__), '..', 'assets', 'wnetrza')
    os.makedirs(katalog, exist_ok=True)
    lista = []
    for plik, nazwa, f, reguly in GRAFIKI:
        svg = f()
        with open(os.path.join(katalog, plik + '.svg'), 'w', encoding='utf-8', newline='\n') as h:
            h.write(svg)
        lista.append({'plik': f'assets/wnetrza/{plik}.svg', 'nazwa': nazwa, 'reguly': reguly, **({'okno': True} if '-okno' in plik else {})})
        print(plik, len(svg))
    with open(os.path.join(katalog, 'lista.json'), 'w', encoding='utf-8', newline='\n') as h:
        json.dump(lista, h, ensure_ascii=False, indent=1)

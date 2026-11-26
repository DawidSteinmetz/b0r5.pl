"""Wczytanie układów pulpitów z arkusza ODS (odwrotność pulpity_ods.py) do modułu w `uklady/`.

    python pulpity_z_ods.py ZRODLO.ods MODUL [--wzor MODUL_WZOROWY]

Typ elementu z koloru tła komórki (siatka.KOLORY), rozmiar ze scalenia; szary pionowy pas w kolumnie 1/13 to suwak
zakresu (tekst górnej komórki = maks., dolnej = min.). Wczytywane są tylko arkusze, które mają kolorowe komórki
w siatce pulpitu; FORMATY (telefon) bierze się z modułu wzorowego (domyślnie isd_i), a gdy pulpity mieszczą się
w 7 wierszach, FORMAT = 'szeroki' (13×7). Zapis: uklady/MODUL.py.
Nakładanie lub wyjście poza siatkę przerywa pracę; asymetria burt jest tylko wypisywana.
"""
import argparse, importlib, zipfile
from xml.etree import ElementTree as ET
from siatka import KOLORY, FORMATY, sprawdz

N = {'table': 'urn:oasis:names:tc:opendocument:xmlns:table:1.0', 'style': 'urn:oasis:names:tc:opendocument:xmlns:style:1.0',
     'fo': 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0', 'text': 'urn:oasis:names:tc:opendocument:xmlns:text:1.0'}


def q(p):
    a, b = p.split(':')
    return '{%s}%s' % (N[a], b)


def komorki_arkuszy(sciezka):
    root = ET.fromstring(zipfile.ZipFile(sciezka).read('content.xml'))
    tlo = {}
    for s in root.iter(q('style:style')):
        p = s.find(q('style:table-cell-properties'))
        if p is not None and p.get(q('fo:background-color')): tlo[s.get(q('style:name'))] = p.get(q('fo:background-color')).lower()
    typ_koloru = {v.lower(): k for k, v in KOLORY.items()}
    wynik = {}
    for t in root.iter(q('table:table')):
        kolstyl = []
        for c in t.findall(q('table:table-column')):
            kolstyl += [c.get(q('table:default-cell-style-name'))] * int(c.get(q('table:number-columns-repeated'), 1))
        komorki, r = {}, 0
        for row in t.iter(q('table:table-row')):
            for _ in range(min(int(row.get(q('table:number-rows-repeated'), 1)), 20)):
                r += 1
                c = 0
                for cell in row:
                    for _ in range(min(int(cell.get(q('table:number-columns-repeated'), 1)), 20)):
                        c += 1
                        if cell.tag == q('table:covered-table-cell'): continue
                        st = cell.get(q('table:style-name')) or (kolstyl[c - 1] if c - 1 < len(kolstyl) else None)
                        typ = typ_koloru.get(tlo.get(st))
                        if not typ: continue
                        tekst = ' '.join(''.join(p.itertext()) for p in cell.findall(q('text:p'))).strip()
                        komorki[(c, r)] = (typ, tekst, int(cell.get(q('table:number-columns-spanned'), 1)), int(cell.get(q('table:number-rows-spanned'), 1)))
        if komorki: wynik[t.get(q('table:name'))] = komorki
    return wynik


def elementy(k):
    el = []
    for (c, r), (t, tekst, w, h) in sorted(k.items(), key=lambda x: (x[0][1], x[0][0])):
        if t == 'S':
            if k.get((c, r - 1), ('',))[0] == 'S': continue
            rr = r
            while k.get((c, rr + 1), ('',))[0] == 'S': rr += 1
            el.append(('S', c, r, tekst, 1, rr - r + 1, k[(c, rr)][1]))
        else:
            el.append((t, c, r, tekst) + ((w, h) if (w, h) != (1, 1) else ()))
    return el


def zapisz(zrodlo, modul, wzor='isd_i'):
    formaty = getattr(importlib.import_module('uklady.' + wzor), 'FORMATY', {})
    tabele = komorki_arkuszy(zrodlo)
    wysokosc = max((r for n, k in tabele.items() if formaty.get(n, 'pulpit') == 'pulpit' for (_, r) in k), default=9)
    format_ = 'szeroki' if wysokosc <= 7 else 'pulpit'
    out = [f'"""Układy pulpitów wczytane z arkusza {zrodlo.replace(chr(92), "/").split("/")[-1]} (pulpity_z_ods.py).',
           'Format elementów i zasady siatki: `siatka.py` i `PULPITY.md`. Zmiany najprościej robić w ODS i wczytać ponownie.', '"""',
           '', f'FORMAT = {format_!r}', f'FORMATY = {formaty!r}', '', 'UKLADY = {}', '']
    for nazwa, k in tabele.items():
        el = elementy(k)
        _, bledy, asym = sprawdz(el, *FORMATY[formaty.get(nazwa, format_)])
        if bledy: raise SystemExit(nazwa + ': ' + '; '.join(bledy))
        print(f'{nazwa}: {len(el)} elementów; asymetria: {len(asym)}')
        for a in asym: print('   ', a)
        out += [f'UKLADY[{nazwa!r}] = ['] + [f'    {e!r},' for e in el] + [']', '']
    with open(f'uklady/{modul}.py', 'w', encoding='utf-8', newline='\n') as f:
        f.write('\n'.join(out))
    print(f'zapisano uklady/{modul}.py')


if __name__ == '__main__':
    a = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    a.add_argument('zrodlo'); a.add_argument('modul'); a.add_argument('--wzor', default='isd_i')
    p = a.parse_args()
    zapisz(p.zrodlo, p.modul, p.wzor)

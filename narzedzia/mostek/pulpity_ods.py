"""Arkusz ODS pulpitów klasy z układów (`uklady/`), siatka 13×9 (telefon 5×9) kwadratowych komórek 2,8 cm.

    python pulpity_ods.py KLASA WYJSCIE.ods [--zrodlo ZRODLO.ods]

Z `--zrodlo`: arkusze o nazwach pulpitów zostają podmienione, pozostałe (dokumentacja, reguły) przechodzą bez zmian,
a pulpity, których w źródle nie ma, są dopisywane na końcu. Bez źródła powstaje nowy arkusz z samymi pulpitami.
Wypisuje liczbę elementów i asymetrie burt; nakładanie lub wyjście poza siatkę przerywa pracę.
"""
import argparse, html, re, zipfile
from siatka import FORMATY, KOLORY, KOMORKA_CM, sprawdz, wymiary
from uklady import uklady, format_pulpitu

STYLE = []


def styl_komorki(nazwa, tlo, gora=True, dol=True, pogrubienie=False):
    b = '0.035cm solid #000000'
    gr = ' fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"' if pogrubienie else ''
    STYLE.append(f'<style:style style:name="{nazwa}" style:family="table-cell"><style:table-cell-properties fo:background-color="{tlo}" '
                 f'fo:border-left="{b}" fo:border-right="{b}" fo:border-top="{b if gora else "none"}" fo:border-bottom="{b if dol else "none"}" '
                 f'fo:wrap-option="wrap" style:vertical-align="middle" style:text-align-source="fix" style:repeat-content="false" />'
                 f'<style:paragraph-properties fo:text-align="center" fo:margin-left="0cm" />'
                 f'<style:text-properties fo:color="#000000" style:font-name="Arial1" fo:font-size="9pt" style:font-size-asian="9pt" style:font-size-complex="9pt"'
                 f'{gr} /></style:style>')


for t, kol in KOLORY.items():
    if t != 'S': styl_komorki(f'pk{t}', kol, pogrubienie=(t == 'B'))
styl_komorki('pkSg', KOLORY['S'], True, False); styl_komorki('pkSs', KOLORY['S'], False, False); styl_komorki('pkSd', KOLORY['S'], False, True)
STYLE.append('<style:style style:name="pkO" style:family="table-cell"><style:table-cell-properties style:vertical-align="middle" /></style:style>')
STYLE.append(f'<style:style style:name="pkol" style:family="table-column"><style:table-column-properties style:column-width="{KOMORKA_CM}cm" fo:break-before="auto" /></style:style>')
STYLE.append(f'<style:style style:name="pwie" style:family="table-row"><style:table-row-properties style:row-height="{KOMORKA_CM}cm" style:use-optimal-row-height="false" fo:break-before="auto" /></style:style>')
STYLE.append('<style:style style:name="pta" style:family="table"><style:table-properties table:display="true" /></style:style>')


def komorka(styl, tekst='', sk=1, sw=1):
    a = f' table:style-name="{styl}"'
    if sk > 1: a += f' table:number-columns-spanned="{sk}"'
    if sw > 1: a += f' table:number-rows-spanned="{sw}"'
    if not tekst: return f'<table:table-cell{a} />'
    return f'<table:table-cell{a} office:value-type="string"><text:p>{html.escape(tekst, quote=False)}</text:p></table:table-cell>'


def tabela(nazwa, styl_tabeli, elementy, format_='pulpit'):
    KOL, WIER = FORMATY[format_]
    zajete, bledy, asym = sprawdz(elementy, KOL, WIER)
    if bledy: raise SystemExit(nazwa + ': ' + '; '.join(bledy))
    poczatki = {(e[1], e[2]): e for e in elementy}
    wiersze = []
    for r in range(1, WIER + 1):
        kom = []
        for c in range(1, KOL + 1):
            e = poczatki.get((c, r))
            if e and e[0] == 'S':
                kom.append(komorka('pkSg', e[3]))  # suwak zakresu: maks. u góry
                continue
            sl = [x for x in elementy if x[0] == 'S' and x[1] == c and 1 < r <= 7]
            if sl:
                kom.append(komorka('pkSd' if r == 7 else 'pkSs', sl[0][6] if r == 7 else ''))  # min. na dole
                continue
            if e:
                w, h = wymiary(e)
                kom.append(komorka('pk' + e[0], e[3], w, h))
            elif (c, r) in zajete:
                kom.append('<table:covered-table-cell />')
            else:
                kom.append(komorka('pkO'))
        wiersze.append('<table:table-row table:style-name="pwie">' + ''.join(kom) + '</table:table-row>')
    return (f'<table:table table:name="{html.escape(nazwa)}" table:style-name="{styl_tabeli}"><table:table-column table:style-name="pkol" '
            f'table:number-columns-repeated="{KOL}" table:default-cell-style-name="pkO" />' + ''.join(wiersze) + '</table:table>'), asym


NS = ('xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
      'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
      'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" office:version="1.2"')
MANIFEST = ('<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">'
            '<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>'
            '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/></manifest:manifest>')


def zbuduj(klasa, wyjscie, zrodlo=None):
    UKLADY = uklady(klasa)
    if not UKLADY: raise SystemExit(f'{klasa}: brak układów pulpitów')
    raport, wstawione = [], set()

    def zamien(m):
        nazwa, atr = html.unescape(m.group(1)), m.group(2)
        if nazwa not in UKLADY: return m.group(0)
        st = re.search(r'table:style-name="([^"]+)"', atr).group(1)
        xml, asym = tabela(nazwa, st, UKLADY[nazwa], format_pulpitu(klasa, nazwa))
        raport.append((nazwa, len(UKLADY[nazwa]), asym)); wstawione.add(nazwa)
        return xml

    def dopisz():
        out = ''
        for nazwa in UKLADY:
            if nazwa in wstawione: continue
            xml, asym = tabela(nazwa, 'pta', UKLADY[nazwa], format_pulpitu(klasa, nazwa))
            raport.append((nazwa, len(UKLADY[nazwa]), asym)); out += xml
        return out

    if zrodlo:
        z = zipfile.ZipFile(zrodlo)
        x = z.read('content.xml').decode('utf-8')
        x = x.replace('</office:automatic-styles>', ''.join(STYLE) + '</office:automatic-styles>', 1)
        x = re.sub(r'<table:table table:name="([^"]*)"([^>]*)>.*?</table:table>', zamien, x, flags=re.S)
        x = x.replace('</office:spreadsheet>', dopisz() + '</office:spreadsheet>', 1)
        pliki = [(n, x.encode('utf-8') if n == 'content.xml' else z.read(n)) for n in z.namelist() if n != 'mimetype']
    else:
        x = (f'<?xml version="1.0" encoding="UTF-8"?><office:document-content {NS}><office:automatic-styles>{"".join(STYLE)}'
             f'</office:automatic-styles><office:body><office:spreadsheet>{dopisz()}</office:spreadsheet></office:body></office:document-content>')
        pliki = [('META-INF/manifest.xml', MANIFEST.encode('utf-8')), ('content.xml', x.encode('utf-8'))]
    with zipfile.ZipFile(wyjscie, 'w') as out:
        out.writestr(zipfile.ZipInfo('mimetype'), b'application/vnd.oasis.opendocument.spreadsheet', compress_type=zipfile.ZIP_STORED)
        for n, dane in pliki:
            out.writestr(n, dane, compress_type=zipfile.ZIP_DEFLATED)
    for nazwa, n, asym in raport:
        print(f'{nazwa}: {n} elementów; asymetria: {len(asym)}')
        for a in asym: print('   ', a)


if __name__ == '__main__':
    a = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    a.add_argument('klasa'); a.add_argument('wyjscie'); a.add_argument('--zrodlo')
    p = a.parse_args()
    zbuduj(p.klasa, p.wyjscie, p.zrodlo)

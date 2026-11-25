"""Podgląd HTML pulpitów klasy (te same dane co arkusz ODS).

    python pulpity_podglad.py KLASA WYJSCIE.html [PULPIT ...]
"""
import html, sys
from siatka import KOL, WIER, KOLORY, wymiary
from uklady import uklady

KRATKA = 88  # px


def podglad(klasa, wyjscie, nazwy=None):
    UKLADY = uklady(klasa)
    out = [f'<!doctype html><meta charset="utf-8"><title>Pulpity {klasa}</title><style>body{{font:11px Arial;background:#222;color:#eee;margin:10px}}'
           f'.p{{display:grid;grid-template-columns:repeat({KOL},{KRATKA}px);grid-template-rows:repeat({WIER},{KRATKA}px);background:#ddd;width:{KOL * KRATKA}px;margin:6px 0 24px}}'
           '.c{border:1px solid #000;display:flex;align-items:center;justify-content:center;text-align:center;color:#000;padding:2px;box-sizing:border-box;overflow:hidden;font-size:10px;line-height:1.15}'
           'h2{margin:0}'
           # wydruk: jeden pulpit na stronę A4 poziomo, komórka 20 mm, kolory tła zachowane
           f'@page{{size:A4 landscape;margin:10mm}}'
           f'@media print{{body{{background:#fff;color:#000;margin:0}}.s{{break-after:page;break-inside:avoid}}.s:last-child{{break-after:auto}}'
           f'.p{{grid-template-columns:repeat({KOL},20mm);grid-template-rows:repeat({WIER},20mm);width:{KOL * 20}mm;margin:2mm 0 0}}'
           '.c{font-size:7pt;-webkit-print-color-adjust:exact;print-color-adjust:exact}h2{font-size:12pt}}'
           '</style>']
    for nazwa in nazwy or UKLADY:
        out.append(f'<section class="s"><h2>Pulpit {html.escape(klasa)} · {html.escape(nazwa)}</h2><div class="p">')
        for e in UKLADY[nazwa]:
            t, c, r, txt = e[:4]
            w, h = wymiary(e)
            if t == 'S':
                out.append(f'<div class="c" style="grid-column:{c};grid-row:1/8;background:{KOLORY[t]};flex-direction:column;justify-content:space-between">'
                           f'<span>{html.escape(txt)}</span><span>{html.escape(e[6])}</span></div>')
                continue
            bold = 'font-weight:bold;color:#fff' if t == 'B' else ''
            out.append(f'<div class="c" style="grid-column:{c}/span {w};grid-row:{r}/span {h};background:{KOLORY[t]};{bold}">{html.escape(txt)}</div>')
        out.append('</div></section>')
    with open(wyjscie, 'w', encoding='utf-8') as f:
        f.write(''.join(out))


if __name__ == '__main__':
    if len(sys.argv) < 3: raise SystemExit(__doc__)
    podglad(sys.argv[1], sys.argv[2], sys.argv[3:])

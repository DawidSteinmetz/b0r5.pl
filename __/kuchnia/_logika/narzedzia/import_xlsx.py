#!/usr/bin/env python3
"""Odświeża tabele/pierwiastki.json i tabele/surowce.json z tabele/zrodlo/tablica-1914.xlsx.
Czary i przyrządy (tabele/czary.json, tabele/przyrzady.json) są ręczne i import ich nie rusza.
Użycie: python3 narzedzia/import_xlsx.py   (potrzebny openpyxl)"""
import json, re
from pathlib import Path
import openpyxl

K = Path(__file__).resolve().parent.parent
wb = openpyxl.load_workbook(K / 'tabele' / 'zrodlo' / 'tablica-1914.xlsx', data_only=True)

def slug(t):
    t = t.lower()
    for a, b in zip('ąćęłńóśźż', 'acelnoszz'): t = t.replace(a, b)
    return re.sub(r'[^a-z0-9]+', '-', t).strip('-')

def proc(t):
    return {m.group(1): float(m.group(2)) for m in re.finditer(r'([A-Z][a-z]?) ([\d.]+)%', str(t))}

pierw = {}
for r in wb.worksheets[0].iter_rows(min_row=6, values_only=True):
    try: masa = float(str(r[4]).replace(',', '.'))
    except ValueError: continue                      # pierwiastki nieodkryte w 1914
    if r[1] and r[1] != 'brak':
        pierw[str(r[1]).strip()] = {'z': int(float(r[0])), 'nazwa': r[2], 'lac': r[3], 'masa': masa}

sur = {}
for r in wb['Składniki Alchemiczne'].iter_rows(min_row=6, max_row=40, values_only=True):
    if not r[0]: continue
    zakres = re.findall(r'[\d.]+', str(r[2]))
    sur[slug(r[0])] = {'nazwa': r[0], 'stan': r[1], 'masa_od': float(zakres[0]), 'masa_do': float(zakres[1]), 'masowo': proc(r[3]), 'opis': r[6]}

(K / 'tabele' / 'pierwiastki.json').write_text(json.dumps(pierw, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')
(K / 'tabele' / 'surowce.json').write_text(json.dumps(sur, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')
brak = sorted({e for s in sur.values() for e in s['masowo'] if e not in pierw})
print('pierwiastki: %d, surowce: %d%s' % (len(pierw), len(sur), ('; BRAK masy dla: ' + ', '.join(brak)) if brak else ''))

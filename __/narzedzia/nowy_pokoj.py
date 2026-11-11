#!/usr/bin/env python3
"""Tworzy nowy pokój (podstronę <adres myśloodsiewni>/<id>/).
Użycie: python3 narzedzia/nowy_pokoj.py <id> "Nazwa" ["Opis"]
Przykład: python3 narzedzia/nowy_pokoj.py spizarnia "Spiżarnia" "Chłód, półki i zapach octu."
"""
import shutil
import sys

from _wspolne import ID_RE, KORZEN, nastepna_kolejnosc, zapisz_json

if len(sys.argv) < 3:
    sys.exit(__doc__)
pid, nazwa = sys.argv[1], sys.argv[2]
opis = sys.argv[3] if len(sys.argv) > 3 else ''
if not ID_RE.match(pid) or pid.startswith('_'):
    sys.exit('Identyfikator: tylko małe litery a-z, cyfry, - i _ (bez polskich znaków), np. "spizarnia".')
cel = KORZEN / pid
if cel.exists():
    sys.exit(f'Folder "{pid}" już istnieje.')

kolejnosc = nastepna_kolejnosc()
shutil.copytree(KORZEN / '_szablon_pokoju', cel)
html = cel / 'index.html'
html.write_text(html.read_text(encoding='utf-8').replace('__ID__', pid), encoding='utf-8')
(cel / 'przedmioty').mkdir()
zapisz_json(cel / 'pokoj.json', {
    'nazwa': nazwa,
    'opis': opis,
    'ikona': '<path d="M30 90V20h40v70M22 90h56"/><circle cx="60" cy="56" r="2"/>',
    'kolejnosc': kolejnosc,
    'foldery': [{'nazwa': 'przedmioty', 'rodzaj': 'przedmiot'}],
})
print(f'Utworzono pokój "{nazwa}": {cel}')
print(f'Przedmioty dodawaj jako pliki .md w {cel / "przedmioty"}')

#!/usr/bin/env python3
"""Dodaje książkę albo przedmiot jako nowy plik .md w pokoju.
Użycie: python3 narzedzia/nowy_element.py <pokoj> <folder> "Tytuł" [rodzaj]
Przykłady:
  python3 narzedzia/nowy_element.py biblioteka ksiazki "Księga kropel"
  python3 narzedzia/nowy_element.py gabinet przedmioty "List z 2 listopada"
Rodzaj (ksiazka albo przedmiot) bierze się z pokoj.json; dla nowego folderu domyślnie:
ksiazka dla folderu "ksiazki", w pozostałych przedmiot."""
import re
import sys

from _wspolne import ID_RE, KORZEN, foldery_pokoju, slug, wczytaj_json, zapisz_json

if len(sys.argv) < 4:
    sys.exit(__doc__)
pokoj, folder, tytul = sys.argv[1], sys.argv[2], sys.argv[3]
rodzaj = sys.argv[4] if len(sys.argv) > 4 else ('ksiazka' if folder == 'ksiazki' else 'przedmiot')
if not ID_RE.match(folder):
    sys.exit('Folder: tylko małe litery a-z, cyfry, - i _.')
sciezka_pokoju = KORZEN / pokoj / 'pokoj.json'
dane = wczytaj_json(sciezka_pokoju)
if not isinstance(dane, dict):
    sys.exit(f'Nie ma pokoju "{pokoj}" (brak {sciezka_pokoju}).')

if folder not in [f.get('nazwa') for f in foldery_pokoju(dane)]:
    dane['foldery'] = foldery_pokoju(dane) + [{'nazwa': folder, 'rodzaj': rodzaj}]
    zapisz_json(sciezka_pokoju, dane)
    print(f'Dopisano folder "{folder}" ({rodzaj}) do {sciezka_pokoju.name}.')

katalog = KORZEN / pokoj / folder
katalog.mkdir(exist_ok=True)
numery = [int(m.group(1)) for f in katalog.iterdir() if (m := re.match(r'^(\d+)', f.name))]
plik = katalog / f'{max(numery + [0]) + 1:03d}-{slug(tytul)}.md'
plik.write_text(f'# {tytul}\n\n', encoding='utf-8')
print(f'Utworzono {plik}. Dopisz treść pod tytułem.')

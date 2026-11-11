#!/usr/bin/env python3
"""Tworzy pusty szkielet myśloodsiewni dla nowej postaci: rdzeń bez pokoi, bez stanu i bez muzyki.

  python3 narzedzia/nowa_postac.py <folder-docelowy> "Nazwa myśloodsiewni"

Przykład: python3 narzedzia/nowa_postac.py ../__ "Myśloodsiewnia Artemidy Steinmetz"
Potem w nowym folderze dodawaj pokoje: python3 narzedzia/nowy_pokoj.py salon "Salon" "Opis"."""
import shutil
import sys
from pathlib import Path

from _wspolne import KORZEN, wczytaj_json, zapisz_json

if len(sys.argv) < 3:
    sys.exit(__doc__)
cel = Path(sys.argv[1]).resolve()
if cel.exists():
    sys.exit(f'Folder "{cel}" już istnieje.')

BEZ = shutil.ignore_patterns('__pycache__', '*.pyc')
cel.mkdir(parents=True)
for nazwa in ('index.html', '.htaccess', 'README.md'):
    shutil.copy2(KORZEN / nazwa, cel / nazwa)
for nazwa in ('api', 'assets', 'narzedzia', '_szablon_pokoju'):
    shutil.copytree(KORZEN / nazwa, cel / nazwa, ignore=BEZ)
(cel / '_dane').mkdir()
shutil.copy2(KORZEN / '_dane' / '.htaccess', cel / '_dane' / '.htaccess')
(cel / 'radio').mkdir()

postac = wczytaj_json(KORZEN / 'postac.json') or {}
postac['nazwa'] = sys.argv[2]
zapisz_json(cel / 'postac.json', postac)
print(f'Gotowe: {cel}')

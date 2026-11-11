#!/usr/bin/env python3
"""Dodaje przedmiot przenośny: po jednym pliku w każdym pokoju i wpis "przenosne" w pokoj.json tych pokoi.

  python3 narzedzia/przenosny.py <nazwa-grupy> <pokoj/folder/plik-on> <pokoj/folder/plik-off> [...więcej miejsc]

Pierwszy plik ma na starcie istnienie "on", pozostałe "off". Dla miejsca bez pliku powstaje bliźniak
(kopia opisu z pierwszego pliku do uzupełnienia).
Przykład: python3 narzedzia/przenosny.py latarnia strych/przedmioty/003-latarnia salon/przedmioty/003-latarnia"""
import sys

from _wspolne import KORZEN, wczytaj_json, zapisz_json

if len(sys.argv) < 4:
    sys.exit(__doc__)
nazwa, kidy = sys.argv[1], sys.argv[2:]
zrodlo = KORZEN / (kidy[0] + '.md')
if not zrodlo.exists():
    sys.exit(f'Brak pliku startowego: {zrodlo}')
for i, kid in enumerate(kidy):
    pokoj, plik = kid.split('/', 1)
    sciezka = KORZEN / pokoj / 'pokoj.json'
    dane = wczytaj_json(sciezka)
    if not isinstance(dane, dict):
        sys.exit(f'Nie ma pokoju "{pokoj}" (brak {sciezka}).')
    cel = KORZEN / (kid + '.md')
    if not cel.exists():
        cel.parent.mkdir(parents=True, exist_ok=True)
        cel.write_text(zrodlo.read_text(encoding='utf-8'), encoding='utf-8')
        print('Utworzono bliźniaka (popraw opis pod nowe miejsce):', cel.relative_to(KORZEN))
    dane.setdefault('przenosne', {})[nazwa] = {'plik': plik, 'istnienie': 'on' if i == 0 else 'off'}
    zapisz_json(sciezka, dane)
    print('Zapisano', sciezka.relative_to(KORZEN))

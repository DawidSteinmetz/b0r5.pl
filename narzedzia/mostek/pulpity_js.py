"""Eksport układów pulpitów do modułu JS dla konsol w grze: js/aplikacje/pulpity_uklady.js.

    python pulpity_js.py

Te same dane co arkusz ODS i wydruk dla graczy (uklady/), więc konsola w grze wygląda jak materiał do nauki.
Element: [typ, kolumna, wiersz, tekst, szerokość, wysokość, (tekst_min dla suwaka zakresu)].
"""
import json, os
from siatka import FORMATY, KOLORY
from uklady import KLASY, uklady, format_pulpitu

TU = os.path.dirname(os.path.abspath(__file__))
CEL = os.path.join(TU, '..', '..', 'js', 'aplikacje', 'pulpity_uklady.js')


def eksport():
    dane, formaty = {}, {}
    for klasa in KLASY:
        u = uklady(klasa)
        if not u: continue
        dane[klasa] = {}
        for nazwa, elementy in u.items():
            dane[klasa][nazwa] = [[e[0], e[1], e[2], e[3], *(e[4:6] if len(e) > 5 else (1, 1)), *(e[6:7])] for e in elementy]
            formaty.setdefault(klasa, {})[nazwa] = FORMATY[format_pulpitu(klasa, nazwa)]
    js = ('// PLIK GENEROWANY: python narzedzia/mostek/pulpity_js.py — nie edytuj, zmieniaj narzedzia/mostek/uklady/.\n'
          '// Układy pulpitów (siatka 13×9, telefon 5×9): [typ, kolumna, wiersz, tekst, szer, wys, (tekst_min suwaka)].\n'
          '// Typy: S suwak zakresu, R wzmocnij, Y suwak, G synchronizacja, B urządzenie / ekran, W polecenie.\n\n'
          f'export const KOLORY = {json.dumps(KOLORY, ensure_ascii=False)};\n'
          f'export const FORMATY = {json.dumps(formaty, ensure_ascii=False)};\n'
          f'export const UKLADY = {json.dumps(dane, ensure_ascii=False, indent=1)};\n')
    with open(CEL, 'w', encoding='utf-8', newline='\n') as f:
        f.write(js)
    print(f'zapisano js/aplikacje/pulpity_uklady.js: {sum(len(v) for v in dane.values())} pulpitów, {len(js)} znaków')


if __name__ == '__main__':
    eksport()

"""Wspólny język pulpitów (zasady: `PULPITY.md`).

Siatka 13×9 kwadratowych komórek (arkusz 2,8 × 2,8 cm). Element układu: (typ, kolumna, wiersz, tekst[, szer, wys]),
kolumny i wiersze od 1. Suwak zakresu: ('S', kolumna, 1, tekst_maks, 1, 7, tekst_min).

Typy: S — szary suwak zakresu (kolumny 1 i 13, wiersze 1–7: maks. u góry, min. na dole), R — wzmocnij (czerwony),
Y — suwak / regulacja (żółty), G — synchronizacja (zielony), B — urządzenie / ekran (niebieski), W — przycisk
neutralny: wyrównaj, równaj, przełącz, tryb, polecenie (biały z ramką).
"""
KOL, WIER = 13, 9
FORMATY = {'pulpit': (13, 9), 'telefon': (5, 9)}  # telefon: pionowo, bez szarych suwaków zakresu
KOMORKA_CM = 2.8
KOLORY = {'S': '#999999', 'R': '#ff3333', 'Y': '#ffff99', 'G': '#99ff99', 'B': '#6666ff', 'W': '#ffffff'}


def suwaki(lewy_max, lewy_min, prawy_max, prawy_min):
    """Para szarych suwaków zakresu: kolumna 1 (lewa burta) i 13 (prawa burta), wiersze 1–7."""
    return [('S', 1, 1, lewy_max, 1, 7, lewy_min), ('S', KOL, 1, prawy_max, 1, 7, prawy_min)]


def wymiary(e):
    return (e[4], e[5]) if len(e) > 5 else (1, 1)


def sprawdz(elementy, kol=KOL, wier=WIER):
    """Zajętość komórek; błędy (nakładanie, poza siatką, suwak zakresu nie na miejscu) i asymetria burt
    (brak elementu w pozycji lustrzanej względem kolumny 7 o tej samej szerokości)."""
    zajete, bledy = {}, []
    for e in elementy:
        t, c, r, tekst = e[:4]
        w, h = wymiary(e)
        if t == 'S' and not (kol == KOL and c in (1, KOL) and r == 1 and h == 7):
            bledy.append(f'suwak zakresu poza kolumnami 1/13 lub wierszami 1–7: {tekst[:30]}')
        for dc in range(w):
            for dr in range(h):
                k = (c + dc, r + dr)
                if k in zajete: bledy.append(f'nakładanie {k}: „{zajete[k][3][:30]}” i „{tekst[:30]}”')
                if not (1 <= k[0] <= kol and 1 <= k[1] <= wier): bledy.append(f'poza siatką {k}: {tekst[:30]}')
                zajete[k] = e
    asym = []
    for e in elementy:
        t, c, r = e[:3]
        w = wymiary(e)[0]
        cl = kol + 1 - (c + w - 1)
        if cl == c: continue
        if not [x for x in elementy if x[2] == r and x[1] == cl and wymiary(x)[0] == w]:
            asym.append(f'brak lustra dla ({c},{r}) {t} „{e[3][:40]}”')
    return zajete, bledy, asym

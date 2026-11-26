"""Profile rozkazów: czas wykonania (MECHANIKA 1.1). Wszystko [R] robocze.

theta_mn — mnożnik rozrzutu strzałów W jednostkę wykonującą (unik: >1), theta_wlasna_mn — rozrzut WŁASNYCH strzałów
(<1 = celniej; „celność ×1,5” z tabeli w MECHANIKA = 1/1,5 ≈ 0,67), predkosc_mn — mnożnik prędkości.
dzialanie None = do następnej zmiany. ranga_skraca — ranga zmienia przygotowanie (kadet +1 s, as −1 s, min. 1 s).
"""

# kod, nazwa, grupa, przygotowanie, działanie, odnowienie, theta, theta_wlasna, predkosc, ogien_wyl, ranga_min, ranga_skraca, opis
R = []


def r(kod, nazwa, grupa, c, d, o=0, theta=None, wl=None, v=None, ogien_wyl=0, ranga='kadet', skraca=1, opis=None):
    R.append((kod, nazwa, grupa, c, d, o, theta, wl, v, ogien_wyl, ranga, skraca, opis))


# Figury klucza (ekran Klucz)
r('prosto', 'prosto', 'kurs', 1, None)
r('lagodnie_l', 'łagodnie w lewo', 'kurs', 1, None)
r('lagodnie_p', 'łagodnie w prawo', 'kurs', 1, None)
r('ostro_l', 'ostro w lewo', 'kurs', 2, None, theta=1.1)
r('ostro_p', 'ostro w prawo', 'kurs', 2, None, theta=1.1)
r('zygzak', 'zygzak', 'unik', 1, 12, theta=1.3)
r('beczka_l', 'beczka w lewo', 'unik', 2, 4, 4, theta=1.6)
r('beczka_p', 'beczka w prawo', 'unik', 2, 4, 4, theta=1.6)
r('burst', 'burst', 'unik', 1, 3, 12, theta=1.5, v=2.0)
r('spirala', 'spirala asa', 'as', 2, 6, 12, theta=2.0, ranga='as')
r('skok_cieniowy', 'skok cieniowy', 'as', 3, 2, 24, theta=2.5, ranga='as', opis='chwilowe zgubienie namiaru przez wroga')
r('petla', 'pętla (zawrót górą)', 'zawrot', 3, 6, ogien_wyl=1, opis='odwrócenie kursu')
r('split_s', 'split-S (zawrót dołem)', 'zawrot', 3, 6, ogien_wyl=1, opis='odwrócenie kursu')
r('rozejscie_l', 'rozejście klucza w lewo', 'szyk', 2, None, opis='trafienia obszarowe w klucz ×0,5')
r('rozejscie_p', 'rozejście klucza w prawo', 'szyk', 2, None, opis='trafienia obszarowe w klucz ×0,5')
r('zwarcie', 'zwarcie szyku', 'szyk', 2, None, wl=0.8, opis='ogień skupiony na jednym celu')
r('nurkowanie', 'atak z nurkowania', 'walka', 3, 4, theta=1.2, wl=0.77, opis='okno ognia; klucz bardziej odsłonięty')
r('przelot', 'przelot przez cel', 'walka', 2, 3, wl=0.67, opis='okno ognia; wystawienie na obronę punktową celu')
r('nozyce', 'nożyce (pojedynek)', 'walka', 2, 12, opis='celność obu stron zależy od rangi')
r('ogon', 'ogon (trzymaj się celu)', 'walka', 2, None, wl=0.83, opis='celniej po 3 s utrzymania')
r('eskorta', 'eskorta wskazanej jednostki', 'walka', 2, None, opis='przejmuje część ostrzału chronionej jednostki')
r('torpedy', 'torpedy protonowe', 'walka', 3, 4, 30, wl=0.8, opis='okno ognia w okręt: energia ×8, Hz ×1,5 (X-wing, Y-wing, B-wing)')
# Rozkazy klucza (jak w grze dziś, api/klucze.php)
r('patrol', 'patrol', 'rozkaz_klucza', 3, None)
r('ochrona', 'ochrona okrętu', 'rozkaz_klucza', 3, None)
r('atak', 'atak', 'rozkaz_klucza', 3, None)
r('skan', 'skan z bliska', 'rozkaz_klucza', 3, None)
r('powrot', 'powrót do hangaru', 'rozkaz_klucza', 3, None)
r('nalot', 'nalot na okręt', 'rozkaz_klucza', 3, None, opis='prosto na najbliższy okręt wroga, z pominięciem myśliwców')
r('start', 'start z hangaru', 'rozkaz_klucza', 1, None, opis='tylko gdy klucz gotowy (lampa niebieska)')
# Okręt (pulpity) — ranga pilotów nie dotyczy
r('przestrojenie_reczne', 'przestrojenie Hz ręcznie', 'okret', 12, None, skraca=0)
r('przestrojenie_preset', 'przestrojenie Hz z presetu', 'okret', 3, None, skraca=0)
r('przestrojenie_auto', 'przestrojenie Hz automatyczne', 'okret', 3, None, skraca=0, opis='2–4 s')
r('wzmocnij', 'wzmocnij', 'okret', 3, 12, 24, skraca=0, opis='+1 stopień wydajności')
r('synchronizuj', 'synchronizuj', 'okret', 12, None, skraca=0)
r('rownaj_reaktor', 'równaj z reaktorem', 'okret', 12, None, skraca=0)
r('wyrownaj', 'wyrównaj', 'okret', 12, None, skraca=0, opis='bez kosztu mocy')
r('przelacz', 'przełącz zasilanie', 'okret', 2, None, skraca=0, opis='przerwa zasilania 1–3 s')
r('salwa', 'salwa baterii', 'okret', 6, None, skraca=0, opis='ładowanie; bolt leci 5 km/s')
r('ster', 'ster i ciąg (kurs, prędkość)', 'okret', 3, None, skraca=0, opis='konsola Silniki / Nawigacja: kurs zadany i prędkość zadana')
r('nastawa', 'nastawa konsoli (suwak)', 'okret', 2, None, skraca=0, opis='Osłony, Reaktory: suwak 0…1 (MECHANIKA 12)')
r('cel_ognia', 'wskazanie celu baterii', 'okret', 2, None, skraca=0, opis='Kontrola ognia: baterie biorą wskazany okręt, gdy jest w łuku i zasięgu')

ROZKAZY = R

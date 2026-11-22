# HOLOLINK 3D — plan i stan prac

Gałąź `3d` (osobny katalog roboczy `.3d` w repozytorium; główna wersja działa dalej na `main`). 3D jest **opcją**:
Ustawienia → „Grafika 3D (eksperymentalna)”; domyślnie 2D, słabszy tablet wraca do 2D jednym przełącznikiem.
Dane i serwer bez zmian — 3D korzysta z tych samych pozycji, orbit, przelotów, doków co 2D.

## Zasady

- Three.js z CDN jako moduł ES (bez narzędzi do budowania): `three@0.160.0`. Gdy się nie wczyta — automatycznie 2D.
- Jednostka świata: **1 = 1000 km**. Płaszczyzna równika / orbit = XZ; kąt mapy θ (0° wschód, 90° północ) →
  `(cos θ, 0, −sin θ)`. Oś Y — „w górę” (północ planety).
- Logarytmiczny bufor głębi (od metrów w doku do milionów km do słońca).
- 30 klatek/s, pauza, gdy scena niewidoczna (jak w 2D).
- Okna mostka / kokpitów: rysuje je dalej scena 2D jako nakładkę (przezroczyste tło) — 3D jest pod spodem.
- Modele statków — tylko własne, proste (low-poly, glTF albo z kodu), bez kopiowania modeli z gier i filmów.

## Etapy

1. **[w toku] Prototyp: widok z okna na orbicie** (`js/rdzen/scena3d.js`)
   - gwiazdy, słońce ze światłem i poświatą, planeta jako kula z teksturą typu planety (ta sama co w 2D),
     obrót, dzień i noc od słońca, atmosfera (poświata na krawędzi), księżyce;
   - pierścień orbitalny Kuat (prawdziwa obręcz 120 km, moduły, światła), tunel doku, gdy okręt stoi w doku;
   - miejsce i kierunek kamery z tego samego widoku co 2D (orbita, kurs, dok, przelot);
   - przełącznik w Ustawieniach; inne sceny (skok, powierzchnia, start) — dalej 2D.
2. Widok z okna: pozostałe sceny (wejście / wyjście z nadprzestrzeni, start, powierzchnia z niebem i terenem).
3. Podział `mapa.js`: stan i pozycje / rysowanie / interfejs kart (bez zmian wyglądu) — podstawa mapy 3D.
4. Mapa układu w 3D (planety, pierścień, doki, statki, przeloty, klikanie promieniem); karty i przyciski bez zmian.
5. Galaktyka 3D (opcjonalnie — galaktyka to płaski dysk, zysk najmniejszy).

## Dziennik

- 2026-11-22 — założona gałąź i plan; start prototypu (etap 1).
- 2026-11-22 — prototyp etapu 1 działa (podgląd lokalny na danych testowych): `js/rdzen/scena3d.js` — gwiazdy, słońce
  (światło + tarcza), planeta-kula z teksturą typu i obrotem, atmosfera (fresnel), księżyce, pierścień Kuat (obręcz
  120 km z grubością 20 km, płyty ze światłami, punkty doków), tunel doku (płyty, żebra co 250 m, lampy, wylot);
  `scena.js`: `ustaw3d()` (na orbicie tylko rama okna na przezroczystym płótnie) i `widok3d()` (dane kamery);
  `system.js` wybiera scenę według `pamiec('grafika3d')`; przełącznik w Ustawieniach.
  Do zrobienia w etapie 1: światła miast po nocnej stronie, tekstura planety lepiej dopasowana do kuli (dziś
  pas z 2D — widać rozciągnięte pasy), zaznaczanie gwiazd/ciał kliknięciem w trybie 3D, Three.js lokalnie
  (praca bez sieci), test na tablecie (wydajność), okna Lambdy i Kom'rka (inny horyzont).

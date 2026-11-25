# Pulpity mostka — zasady wspólne dla wszystkich klas

Pulpit (konsola) to widok jednego wspólnego stanu okrętu w SQL ([MECHANIKA.md](MECHANIKA.md), rozdz. 0).
Układy pulpitów są danymi w [`uklady/`](uklady/) — jeden plik na klasę; arkusz ODS i podgląd HTML są z nich
generowane (patrz [README.md](README.md)).

## 1. Zasady bezwzględne [U]

1. **Siatka 13×9 kwadratowych komórek**, w arkuszu 2,8 × 2,8 cm; proporcje 1,44 — między tabletem 4:3 a laptopem
   16:10.
2. **Szare suwaki zakresu** w kolumnach 1 i 13, wiersze 1–7: maksimum u góry, minimum na dole. Lewy suwak dotyczy
   lewej burty (lub pierwszego z pary urządzeń), prawy — prawej.
3. **Kolory:**

   | Kolor | Kod | Typ w danych | Znaczenie |
   |---|---|---|---|
   | czerwony | #ff3333 | R | wzmocnij |
   | żółty | #ffff99 | Y | suwaki (regulacja wartości) |
   | zielony | #99ff99 | G | synchronizacje |
   | niebieski | #6666ff | B | urządzenia i ekrany |
   | biały | #ffffff | W | wyrównaj, przełącz, tryby i polecenia |
   | szary | #999999 | S | suwaki zakresu (ograniczenia) |

4. **Symetria burt** — elementy lewej i prawej burty w pozycjach lustrzanych względem kolumny 7 (kolumna c ↔ 14 − c,
   ta sama szerokość). Generator sprawdza to automatycznie i wypisuje braki lustra.
5. **Pulpity analogiczne, nie tożsame** — wspólny język, ale każdy pulpit ma własną kompozycję zależną od funkcji.
   Dotyczy też porównania klas: pulpit osłon MC80 nie jest pulpitem osłon ISD z inną liczbą.
6. **Ekran żył = blok 3×3 komórek.** ISD: 60×60 lampek (1 lampka = 1 żyła). Dla innych siatek — sposób pokazania
   z profilu klasy (MC80: propozycja w [profile/MC80.md](profile/MC80.md)).
7. **Urządzenia docelowe:** pulpity są dla komputerów i tabletów. Telefony obsługują tylko klucze myśliwców
   (tryb listy, panele jak karta jednostki w mapie).

## 2. Format danych układu

Element: `(typ, kolumna, wiersz, tekst[, szerokość, wysokość])`, kolumny i wiersze od 1.
Suwak zakresu: `('S', 1 lub 13, 1, tekst_maks, 1, 7, tekst_min)` — tworzy go `siatka.suwaki(...)`.
Etykiety zależne od klasy (np. opis ekranu żył) bierz z `profile_klas.py`, nie wpisuj liczb na sztywno.

Generator przerywa pracę przy nakładaniu elementów, wyjściu poza siatkę i suwaku zakresu poza kolumnami 1/13.

## 3. Znaczenie czasowników

Do zatwierdzenia — proponuję jedną definicję dla wszystkich konsol i klas:

| Operacja | Znaczenie | Koszt i czas (propozycja) |
|---|---|---|
| **Wzmocnij** | tymczasowo +1 stopień wydajności systemu (np. pojemność generatora osłon tej strony, gęstość ognia baterii) | +porcje z reaktora i ciepło; trwa 1 cykl; nie częściej niż co 2 cykle |
| **Synchronizuj** | dopasuj parametr dwóch systemów do wspólnego stopnia natywnego (np. Hz osłon do Hz własnej baterii, by nie strzelać przez własną osłonę) | przestrojenie jak ręczne (12 s) albo 2–4 tiki z presetem |
| **Wyrównaj** | rozdziel obciążenie lub moc według algorytmu (równo albo proporcjonalnie do zagrożenia) | bez kosztu mocy; zajmuje 1 cykl |
| **Przełącz** | zmień aktywne źródło zasilania (np. silniki na reaktor zapasowy) | krótka przerwa zasilania (1–3 tiki) |
| **Równaj z reaktorem** | ustaw wartość na wzorzec reaktora (częstotliwość bazowa z konsoli reaktorów) | jak synchronizacja |

„Wzmocnij o 1 stopień” zależy od klasy: ISD — następny dzielnik 3600, MC80 — ×3.

## 4. Uprawnienia i stany

Poziomy przycisku [U]: obligatoryjny, dostępny, zablokowany (przez kapitana). Stany kontrolki [U z audytu]: dostępny,
zablokowany, nieaktywny, uszkodzony — potrzebna legenda.

## 5. Telemetria (z audytu) — ważniejsza niż nowe przyciski

- Osłony: 3 wartości Hz, żywe i martwe żyły, kanały V/Ω/W, obciążenie i pojemność generatora, rykoszet, regeneracja.
- Turbolasery i PD: liczba sprawnych stanowisk, stan, przydział mocy, przeładowanie, priorytety (pociski / klucze).
- Silniki: wektor ruchu, zwrot, prędkość, przeciążenie, temperatura, uszkodzenia dysz, pobór.
- Reaktory: temperatura i margines cieplny, dławienie, moc nominalna i dostępna, obciążenie magistral, ciepło
  z rykoszetu, zasobniki.

## 6. Warstwy interfejsu

| Warstwa | Zawartość | Źródło |
|---|---|---|
| 0 · tło | korpus, metal, cienie | SVG `*-tlo` (bez napisów, bez kliknięć) |
| 1 · kontrolki | przyciski, suwaki, przełączniki, ramy ekranów | SVG `*-kontrolki`, ID grup |
| 2 · tekst | etykiety, wartości, statusy | SVG `*-teksty` + UI; przełącznik czcionki (podstawowa / Aurebesh) zmienia całą warstwę |
| 3 · dane na żywo | siatka żył, temperatury, moc, radar | Canvas/DOM z aktualnego stanu SQL |

## 7. Gniazda SVG

Pliki gniazd ISD z 24.11 (`isd-konsola-*-gniazda.json`, viewBox 1600×900, siatka od (202, 112), komórka 92×74 px)
są sprzed przejścia na kwadraty — do przeliczenia (np. komórka 86×86 px, siatka 1118×774 w kadrze 1600×900).
Każde gniazdo ma id, etykietę, typ, wiersz/kolumnę i prostokąt (suwaki: także tor). SVG są gotowe dla Osłon,
Turbolaserów, Silników i Reaktorów ISD (w Downloads; w repo leżą w `assets/wnetrza/`, niezapisane).
Docelowo gniazda powinny być generowane z `uklady/` (te same dane co arkusz), a nie rysowane osobno [?].

## 8. Błędy w arkuszu użytkownika (poprawione w układach ISD)

- Konsola Turbolasery: przycisk „wzmocnij osłony rufowe” → „wzmocnij rufowe działka PDLC”.
- Synchronizacje silników rufowych i promieni ściągających powielone 1:1 na Osłonach i Turbolaserach — zostawione
  tam, gdzie mają sens (osłony rufowe ↔ silniki; promienie ściągające ↔ przedni reaktor).
- Literówki: „z lewego silnikiem głównym” → „z lewym silnikiem głównym”, „lewą baterie” → „lewą baterię”.
- Nazwa pliku „ISD 2” przy profilach ISD I (Walako to ISD I) — plik użytkownika zostaje bez zmian.

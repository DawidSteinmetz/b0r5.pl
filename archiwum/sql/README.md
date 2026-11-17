# Archiwum importów SQL

Zrzuty SQL są lokalnymi archiwami importu i zawierają prywatne dane rozgrywki, w tym hashe haseł.
Nie są śledzone przez Git. `.htaccess` blokuje ich pobieranie po HTTP, gdyby katalog znalazł się
na hostingu; nie należy jednak umieszczać zrzutów w publicznym katalogu serwera.

## 2026-11-15

- `2026-11-15_import-danych.sql` — dane przeniesione z `UGCC/api/dane/`.
- `2026-11-15_import-danych-i-testow.sql` — ten sam import oraz przykładowe planety, lokacje,
  firmy i budynki do testów. Import jest powtarzalny: istniejące dokumenty pozostawia bez zmian,
  a przykładowe obiekty dopisuje tylko raz.

Definicja przykładowych obiektów jest utrzymywana w śledzonym pliku `api/przykladowe-obiekty.sql`.

## Fundament SQL v3 i migracja lokacji (2026-11-17)

- `schema_v2.sql` — poprzedni relacyjny fundament, wymaga `api/schema.sql`.
- `migracja_v2_v3.sql` — addytywne rozszerzenie istniejącej bazy po imporcie v2. Nie kasuje
  danych. Można uruchamiać ponownie; przed pierwszym importem wykonaj kopię bazy.
- `schema_v3.sql` — kompletny skrypt dla **pustej bazy**: `api/schema.sql` + v2 + migracja v3.
  Nie uruchamiaj go na istniejącej bazie produkcyjnej — użyj migracji v2→v3.
- `api/migracje/004_relacyjne_lokacje_i_pozycje.sql` — migracja istniejącej bazy v2/v3;
  dodaje władzę nad planetą, lokacje i relacyjny zapis pozycji postaci. Wymaga wcześniejszego
  wpisu Havis III w `mapa_planety`.
- `api/schema_v4.sql` — schemat całościowy dla **pustej bazy**, zawiera v3 i migrację 004.

### Zakres v3

CR jest kanoniczną walutą; IC jest tylko aliasem CR. Rachunki zachowują istniejące wartości,
a otrzymują przypisanie do CR. Dodane są rozszerzalne kategorie katalogu, karty, kariery,
specjalizacje i umiejętności Edge of the Empire, izolowane sesje symulacji, zasilanie i osłony
statków, emitery ciągnikowe, hangary i przydziały jednostek, ewidencja zużycia zaopatrzenia,
kwatery, zmiany i posiłki załogi, historia kontaktów czujników oraz rejestr rozkazów mostka.

Runtime używa relacyjnych tabel SQL dla lokacji i pozycji (z zachowaniem tekstowego UID konta jako
mostu). Pozostałe domeny są jeszcze przechowywane jako dokumenty JSON w `datapad_dane` i będą
migrowane modułami. Techniczne formaty JSON aplikacji pozostają potrzebne.

W `towary_kategorie` i `planety_kategorie_ceny` zachowano powiązania wynikające ze starych
kategorii v2. Nowe kategorie dopisuj do `kategorie_towarow`, a następnie przypisuj towarom.
Nowe rachunki muszą dostać wiersz w `rachunki_waluty`; na obecnym etapie używaj kodu `CR`.
Gotówkę starych portfeli skopiowano do `portfele_salda` w CR; nowe saldo wielowalutowe powinno
być prowadzone w tej tabeli po osobnym przełączeniu aplikacji.

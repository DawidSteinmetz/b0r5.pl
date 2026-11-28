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

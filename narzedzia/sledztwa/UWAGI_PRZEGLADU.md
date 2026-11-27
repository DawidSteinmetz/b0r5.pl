# Uwagi z przeglądu gałęzi `claude/sledztwa-droid` (27.11)

Dla B0R5 i jego sesji Claude Code. Przegląd zrobiła sesja Claude Code na głównym repo (gałąź `main`),
na stanie gałęzi `74ec991`, uzupełniony o `c72d0a5` (generator kroku 1). Czytaj razem z `PRZEKAZANIE.md`. Gałąź **nie jest jeszcze scalona** z `main` —
autor zdecyduje o scaleniu później.

## Wynik: bez błędów blokujących

Sprawdzone i w porządku:

- Gałąź wychodzi z aktualnego `main` (`c421ad0`); `git merge-tree` — scala się bez konfliktów.
- Numery migracji 082–084 były w chwili przeglądu wolne (`main` kończy się na 081).
- 23 nowe tabele z 082/083 nie dublują żadnej istniejącej tabeli z `api/migracje` ani `api/schema.sql`.
- Klucze obce: typy zgodne z tabelami gry — `lokalizacje.id` BIGINT UNSIGNED, `mapa_planety.id` INT UNSIGNED.
- 082 i 083 dają się importować ponownie (`ON DUPLICATE KEY`); 084 nadpisuje warianty upsertem (bez DELETE),
  więc nie łamie kluczy obcych z `sledztwo_fazy`.
- `api/ai.php`: składnia poprawna, żadna funkcja ani akcja (`mg_ai_*`) nie koliduje z resztą API.
  `obsluz_ai()` wychodzi od razu dla obcych akcji, więc `wymagaj_mg()` nie blokuje reszty gry — to było
  najważniejsze do sprawdzenia, bo `obsluz_*` wywołuje się przy każdym zapytaniu.
- `api/sledztwa.php` (`c72d0a5`): składnia poprawna, brak duplikatów funkcji w całym `api/`, `obsluz_sledztwa()`
  wychodzi od razu dla obcych akcji. Gałąź z generatorem dalej scala się z `main` bez konfliktów.
- `konfiguracja-ai.php` jest w `.gitignore` i zablokowana w `api/.htaccess` — dobrze.

## Ryzyka — do zrobienia przed scaleniem

1. **Numeracja migracji (najważniejsze).** Na `main` migracje przybywają bardzo szybko (075–081 w dwa dni,
   kilka sesji naraz). Jeśli przed scaleniem na `main` pojawi się inna `082_*`, `083_*` albo `084_*`, numery się
   zdublują. Przed scaleniem: `git fetch` i sprawdź `git ls-tree --name-only origin/main api/migracje/`;
   w razie kolizji przenumeruj swoje migracje na pierwsze wolne numery (nazwa pliku i wpis
   `schema_migracje` na końcu pliku) i zaktualizuj odwołania w `PROJEKT.md`, `PRZEKAZANIE.md`
   i `sprawdz_warianty.pl`. Nie zmieniaj numerów migracji już zaimportowanych na produkcji.
2. **MySQL 8.4, nie MariaDB.** Produkcja to MySQL 8.4 (phpMyAdmin). Testy były na MariaDB, a 084 nie była
   importowana nigdzie. Składni typowo „tylko MariaDB” (`ADD COLUMN IF NOT EXISTS`, `CREATE INDEX IF NOT EXISTS`,
   `RETURNING` itp.) w 082–084 nie znalazłem, ale przy kolejnych migracjach pilnuj tego — w MySQL 8.4 te
   konstrukcje są błędem. Najprościej trzymać się tego, co już robisz: nowe tabele przez
   `CREATE TABLE IF NOT EXISTS`, dane przez `ON DUPLICATE KEY UPDATE`; dodawanie kolumn do istniejących tabel
   (`ALTER TABLE … ADD COLUMN`) w MySQL nie jest powtarzalne — przy ponownym imporcie da błąd.
3. **`VALUES(kolumna)` w `ON DUPLICATE KEY UPDATE`** — w MySQL 8.4 działa, ale jest przestarzałe (ostrzeżenie
   w phpMyAdmin, nie błąd). Można zostawić; reszta gry robi tak samo.
4. **`CHECK` na kolumnach używanych w kluczach obcych** (`sledztwo_zdarzenia.id`, `sledztwo_relacje.id`,
   `sledztwo_fazy_szablon.nr`, `sledztwo_warianty.nr`). Teraz poprawne. MySQL zabrania jednak `CHECK` na kolumnie
   tabeli podrzędnej, której klucz obcy ma `ON DELETE` / `ON UPDATE` (błąd 3823). Nie dodawaj takich akcji do
   kluczy obcych na kolumnach z `CHECK`.
5. **`api/index.php`.** Gałąź dopisuje dwie linie (`require ai.php`, `obsluz_ai()`) na końcu list. Inne sesje na
   `main` dopisują w tych samych miejscach (`bitwa.php` itp.), więc przy scaleniu może być trywialny konflikt —
   zostaw obie strony.
6. **Sprawdzenie PHP przed każdym commitem** (zasada w głównym repo): składnia każdego zmienionego pliku PHP
   i grep duplikatów funkcji w `api/*.php` (`grep -ho "^function \w\+" api/*.php | sort | uniq -d` musi być
   pusty). Duplikat funkcji albo błąd składni w dowolnym pliku `api/` to błąd 500 **całego** API, nie tylko
   śledztw.

## Bez zmian po stronie przeglądu

Pytania otwarte z `PRZEKAZANIE.md` (listy zdarzeń i relacji, czy tylko Nar Shaddaa, dostawca AI) przekazane
autorowi — odpowiedzi jeszcze nie ma. Nic na tej gałęzi poza tym plikiem nie zostało zmienione.

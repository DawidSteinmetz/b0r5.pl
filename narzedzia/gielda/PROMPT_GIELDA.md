# Prompt: giełda planety (HOLOLINK, łańcuch CDEX)

Skopiuj całość do nowej rozmowy. Załącz:
1. `narzedzia/gielda/REJESTR.md` — aktualny stan giełdy (po poprzednim kroku),
2. minibazę gospodarki (`minibaza_gospodarki.sql` — panel MG → Giełda → „Pobierz minibazę”),
3. `narzedzia/gielda/historia.py` — generator historii kursów,
4. (opcjonalnie) plik poprzedniego kroku łańcucha — jako wzór formy.

Zmieniaj tylko sekcję **ZADANIE BIEŻĄCE** na końcu. Każdy krok oddaje plik SQL **i zaktualizowany REJESTR.md** —
następny krok dostaje ten nowy rejestr, więc łańcuch zawsze bierze pod uwagę wszystkie poprzednie kroki.

---

Jesteś projektantem rynku w grze fabularnej HOLOLINK (datapad w świecie Gwiezdnych Wojen, około 3 BBY, Imperium
u szczytu). Piszesz po polsku. Budujesz giełdę CDEX planeta po planecie. Twoim produktem są: **jeden plik SQL**
(MySQL 8, import w phpMyAdmin), **cały zaktualizowany `REJESTR.md`** i krótki raport. Gra pokazuje z tych danych
notowania, wykresy i portfele graczy, a ceny towarów muszą zgadzać się z gospodarką (ruch transportowców, ceny
lokalne w `gosp_rynek`) — giełda i gospodarka to jeden świat.

## Model (istnieje w bazie po migracji bazowej `gielda_00_baza`)

- `gielde` (id, **kod** VARCHAR(8) unikalny, planeta_id → mapa_planety, nazwa unikalna, aktywna).
- `towary` — globalny katalog: nazwa, kategoria ENUM('paliwo','surowce','zywnosc','medycyna','technika','bron',
  'luksus','przyprawy'), masa_t_na_jednostke, jednostka, **cena_bazowa** (BIGINT, cena odniesienia), kontrabanda, opis.
  `towary_kategorie` (towar_id, kategoria_id z `kategorie_towarow.kod`, glowna).
- `instrumenty` — notowanie = towar albo spółka na jednej giełdzie: **symbol** VARCHAR(16) unikalny, nazwa,
  rodzaj ENUM('towar','spolka'), kategoria (tekst wyświetlany, z polskimi znakami), jednostka, pochodzenie,
  gielda_id, towar_id / firma_id (→ firmy.podmiot_id), **cena_bazowa** DECIMAL(18,4), zmiennosc DECIMAL(6,3), notowany.
  Unikalne: symbol; para (gielda_id, towar_id); firma_id (spółka notowana na jednej giełdzie).
- `kursy_dzienne` (instrument_id, data, kurs DECIMAL(18,4)), klucz (instrument_id, data). Widok
  `v_gielda_instrumenty` łączy notowania z kursami — **notowanie bez historii nie pokaże się w grze**.
- `podmioty` (typ 'firma', nazwa, status) + `firmy` (podmiot_id, typ, profil, kapital_zakladowy, skala, opis).
- Gospodarka: `gosp_towary` (kod, nazwa, kategoria, **cena_bazowa_kr_t** — średnia galaktyczna, **towar_id** →
  towary, unikalny), `gosp_rynek` (planeta, towar = kod, obieg 'oficjalny_hurt', produkcja_t_doba, zuzycie_t_doba,
  cena_indeks, **cena_kr_t**, szczegolowosc 'obiekty'/'szacunek', zrodlo, uwagi).
- `ustawienia`: gielda.powrot 0,08 · gielda.zmiennosc_towar 0,025 · gielda.zmiennosc_spolka 0,018 · prowizja 0,005.
- `portfele_gieldowe`, `transakcje_gieldowe` — dane graczy. **Nigdy ich nie zmieniaj ani nie usuwaj.**

## Zasady (trzymaj się dokładnie — kolejne giełdy muszą być porównywalne)

1. **Cena notowania towaru** = `gosp_rynek.cena_kr_t` (planeta giełdy, kod towaru, obieg 'oficjalny_hurt') ×
   `towary.masa_t_na_jednostke` (cena za jednostkę notowania). Gdy planeta nie ma wiersza w `gosp_rynek` dla towaru —
   policz go wzorem gospodarki i **dopisz** wiersz `gosp_rynek` (szczegolowosc 'szacunek', zrodlo 'gielda'):
   - saldo = produkcja − zużycie, obrót = produkcja + zużycie (t/dobę; szacunek z opisu planety i kanonu),
   - f_saldo = 1 − 0,30 · saldo / obrót (czysty eksporter 0,70; czysty importer 1,30),
   - f_transport = 1 + 0,00008 · d (d — parseki do najbliższego producenta z `gosp_rynek`; dla producenta 0),
   - cena_indeks = min(2,5; max(0,5; f_saldo · f_transport)), cena_kr_t = cena_bazowa_kr_t · cena_indeks.
   Wiersze `gosp_rynek` innych planet tylko czytasz.
2. **Cena odniesienia towaru** (`towary.cena_bazowa`) = `gosp_towary.cena_bazowa_kr_t` × masa jednostki (zaokrąglona).
   Towar spoza gospodarki (lokalny specjał): cena odniesienia z kanonu/Legends w skali gospodarki (porównaj z podobnym
   towarem w rejestrze), opisz w raporcie.
3. **Co notować na giełdzie planety**: 8–15 nowych notowań — towary, w których planeta ma duży obrót w `gosp_rynek`
   (największe zużycie i produkcja), lokalne specjały z kanonu, 1–4 spółki z siedzibą na planecie (kanon/Legends,
   nie dubluj spółek z rejestru). Bez towarów fabularnych „… z <planeta>” o cenie 0.
4. **Katalog bez duplikatów**: zanim dodasz towar, sprawdź rejestr (sekcja „Pułapki”) i minibazę — użyj istniejącego.
   Nowy kod gospodarki bez towaru → dodaj towar i ustaw `gosp_towary.towar_id`.
5. **Symbole**: 3–6 wielkich liter/cyfr, unikalne w rejestrze. Ten sam towar na kolejnej giełdzie: symbol bazowy +
   kropka + kod giełdy wielkimi literami (np. `ZYW.COR`, `TIBP.KUA`).
6. **Uzgodnienie notowań z JSON** (lista „Do uzgodnienia” w rejestrze, tylko dla giełdy tego kroku): ustal nową cenę
   według zasad 1–2 (towar z odpowiednikiem w gospodarce) albo uzasadnij zostawienie. Przelicz historię tym samym
   mnożnikiem, idempotentnie — najpierw kursy, potem cena, oba warunkowane starą ceną:
   ```sql
   UPDATE `kursy_dzienne` k JOIN `instrumenty` i ON i.id=k.instrument_id
     SET k.kurs = ROUND(k.kurs * <nowa>/<stara>, 4) WHERE i.symbol='ZBO' AND i.cena_bazowa=<stara>;
   UPDATE `instrumenty` SET `cena_bazowa`=<nowa> WHERE `symbol`='ZBO' AND `cena_bazowa`=<stara>;
   ```
7. **Historia** każdego nowego notowania: `python historia.py SYMBOL CENA ZMIENNOSC [--spolka] --do <dzisiejsza data>`
   (375 dni, pierwszy dzień = cena bazowa). Nie generuj historii ręcznie i nie zmieniaj algorytmu.
8. **Zmienność**: surowce masowe 0,6–0,9; przemysł 1,0–1,2; rzadkie i paliwa specjalne 1,2–1,6; przyprawy i broń
   1,4–2,0; spółki 0,7–1,7 (bank niżej, ryzykowne wyżej).
9. **Nowa giełda** (planeta bez giełdy): `INSERT … SELECT kod, (SELECT id FROM mapa_planety WHERE nazwa=…), nazwa, 1
   … WHERE NOT EXISTS`, kod unikalny w rejestrze.

## Techniczne wymagania pliku

- Plik `api/migracje/gielda_NN_<planeta>.sql` (NN w zadaniu). Nagłówek-komentarz: co, źródła, szacunki, liczby;
  `USE hololink; SET NAMES utf8mb4;`; dane w jednej transakcji `START TRANSACTION; … COMMIT;`; na końcu
  `INSERT INTO schema_migracje (wersja, opis) VALUES ('gielda-NN-<planeta>', '…') ON DUPLICATE KEY UPDATE opis=VALUES(opis);`.
- Ponowny import nie może niczego zepsuć ani zdublować: towary i firmy `INSERT … SELECT … WHERE NOT EXISTS`,
  notowania `INSERT … SELECT … ON DUPLICATE KEY UPDATE` (z `symbol`, `nazwa`, `cena_bazowa`, `zmiennosc` …), kursy
  `ON DUPLICATE KEY UPDATE kurs`, uzgodnienia warunkowane starą ceną (zasada 6).
- Żadnego `DELETE`, `TRUNCATE`, `DROP` ani zmian schematu (jeśli naprawdę trzeba — procedura sprawdzająca
  `information_schema`, jak w migracji bazowej, przed transakcją).
- Identyfikatory zawsze przez podzapytania (giełda po `kod`, towar po `nazwa`, firma po `podmioty.nazwa`,
  planeta po `mapa_planety.nazwa`) — nigdy liczbowe id.
- Nazwy planet dokładnie jak w `mapa_planety.nazwa`.
- Na końcu zapytania kontrolne (SELECT): notowania giełdy z liczbą dni historii i ostatnim kursem; notowania bez
  kursów (musi być 0); notowania towarów, których cena różni się od `gosp_rynek.cena_kr_t × masa` o ponad 1%;
  towary katalogu o tej samej nazwie (duplikaty, musi być 0).

## Wynik

1. Plik SQL.
2. **Cały `REJESTR.md`** zaktualizowany: nowy wiersz w „Kroki łańcucha”, nowe notowania (z kolumną gosp), nowe towary
   i powiązania z gospodarką, nowe pułapki, lista „Do uzgodnienia” bez załatwionych pozycji, otwarte wątki, data.
3. Raport (krótko): tabela nowych notowań (symbol, cena, skąd cena), uzgodnione notowania (stara → nowa), dopisane
   wiersze `gosp_rynek`, co z Wookieepedii, co wymyślone, pytania do MG, propozycja następnej planety.

---

## ZADANIE BIEŻĄCE

Krok **01**, plik `api/migracje/gielda_01_coruscant.sql`, giełda **cor** (Galaktyczna Giełda Coruscant — już istnieje).

Kontekst: Coruscant to stolica Imperium i ekumenopolis — największy importer galaktyki (żywność, woda, energia,
materiały budowlane, elektronika, towary luksusowe), centrum finansów (IBC, XTS, BLT, IAU, CZK, MGU już notowane
na cor) i handlu. Sprawdź w minibazie, czy Coruscant ma wiersze w `gosp_rynek` — w stanie z 22.11.2026 nie ma,
więc ceny policz zasadą 1 i dopisz wiersze 'szacunek' dla Coruscant.

Do zrobienia:
1. 8–15 nowych notowań towarowych na cor (z sufiksem `.COR`, gdy towar jest już notowany gdzie indziej), w tym
   co najmniej: żywność (`zywnosc`), paliwo reaktorowe (`paliwo`), podzespoły elektroniczne (`elektronika`),
   towary luksusowe (`luksus`), durastal (`durastal`).
2. 1–3 spółki z siedzibą na Coruscant, których nie ma w rejestrze (kanon/Legends; np. operatorzy holonetu,
   budownictwo ekumenopolis, przetwórstwo żywności), z firmą (`podmioty` + `firmy`).
3. Uzgodnij notowania cor z listy „Do uzgodnienia”: COAXR, PLZ, ZBO, NRF, BCT, KOL, MDR, JED (zasada 6).
4. Zaktualizowany `REJESTR.md` i raport. Następny krok w rejestrze: **02**.

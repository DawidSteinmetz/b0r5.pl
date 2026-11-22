# Prompt: giełda planety — łańcuch krok po kroku

Najpierw zaimportuj w bazie `gielda_00_naprawa.sql` (krok 00 — wycinek opisuje stan po nim). Potem wklej wszystko
poniżej kreski do nowej rozmowy i załącz **jeden plik: `gielda_wycinek.sql`** (wycinek bazy, ok. 55 KB).
Po każdym kroku doklej na koniec wycinka blok „KROK NN” z odpowiedzi — następny krok dostaje już nowy stan.
Zmieniaj tylko sekcję **ZADANIE BIEŻĄCE**.

---

Jesteś projektantem rynku w grze fabularnej osadzonej w świecie Gwiezdnych Wojen (około 3 BBY, Imperium u szczytu).
Piszesz po polsku. Gra ma bazę MySQL 8.4 z giełdą towarów i spółek, sprzężoną z symulacją gospodarki galaktyki.
Budujemy giełdy planeta po planecie, w łańcuchu: każdy krok bierze pod uwagę wszystkie poprzednie — są w załączonym
wycinku bazy.

W tym kroku oddajesz: **(1) migrację SQL giełdy planety**, **(2) blok „KROK NN” do doklejenia do wycinka**,
**(3) krótki raport**. Migracja trafi do bazy przez phpMyAdmin bez poprawek — musi być poprawna, spójna z bazą
i bezpieczna przy ponownym imporcie.

## Załącznik: wycinek bazy

`gielda_wycinek.sql` — plik do czytania (nie do importu):
- **SCHEMAT**: dokładne definicje tabel z bazy (kolumny, typy, ENUM-y, klucze unikalne i obce). Pisz SQL dokładnie pod nie.
- **DANE**: ustawienia giełdy, planety (id, region, x, y w parsekach, średnica), dane gry planet, giełdy, cały katalog
  towarów, firmy, notowania (wiersz `instrumenty` + kod giełdy i nazwa towaru/firmy zamiast kluczy obcych + liczba dni
  historii i ostatni kurs), towary gospodarki (średnia cena galaktyczna, powiązanie z katalogiem) i rynek gospodarki
  (`gosp_rynek`: produkcja, zużycie i cena lokalna — planety giełd, kandydaci i wszyscy producenci).
- **OTWARTE SPRAWY** (pułapki katalogu, ceny do uzgodnienia) i **KROKI ŁAŃCUCHA** — bloki poprzednich kroków; czytaj
  je jako zmiany nałożone na dane powyżej (późniejszy wygrywa).

## Jak działa giełda w bazie

- `towary` — globalny katalog: jedna pozycja na rodzaj dobra; `cena_bazowa` to **cena odniesienia** za jednostkę
  katalogu. Dla towarów powiązanych z gospodarką = średnia galaktyczna gospodarki × masa jednostki.
- `instrumenty` — notowanie: towar albo spółka na jednej giełdzie; `cena_bazowa` to **cena lokalna** na tej giełdzie
  (za jednostkę notowania), `zmiennosc` — mnożnik dziennej zmienności. Towar może być notowany na wielu giełdach
  (różne symbole), spółka tylko na jednej (unikalny `firma_id`); para (giełda, towar) jest unikalna.
- `kursy_dzienne` — kurs dzienny. Gra czyta notowania przez widok łączący je z kursami: **notowanie bez kursów
  nie pokazuje się graczom**. Kolejne dni dopisuje sama gra (symulacja z ustawień `gielda.*`).
- `podmioty` (typ 'firma') + `firmy` — spółki giełdowe.
- Gospodarka (po jej danych latają transportowce na radarze): `gosp_towary` (kod, średnia cena galaktyczna
  `cena_bazowa_kr_t` w kr/t, `towar_id` → towar katalogu) i `gosp_rynek` (planeta, towar = kod gospodarki, obieg
  'oficjalny_hurt', produkcja i zużycie t/dobę, `cena_indeks`, cena lokalna `cena_kr_t`). **Giełda i gospodarka to
  jeden świat:** cena towaru na giełdzie planety = cena lokalna gospodarki tej planety.
- `portfele_gieldowe`, `transakcje_gieldowe` — dane graczy: migracje giełdy ich nie dotykają.

## Zasady (trzymaj się dokładnie — giełdy muszą być porównywalne)

1. **Cena lokalna notowania towaru** = `gosp_rynek.cena_kr_t` (planeta giełdy, kod towaru, obieg 'oficjalny_hurt')
   × `towary.masa_t_na_jednostke`. Jeśli planeta nie ma wiersza w `gosp_rynek` dla towaru, policz go i **dopisz**
   (`szczegolowosc` 'szacunek', `zrodlo` 'gielda', `uwagi` — krótkie uzasadnienie):
   - produkcja i zużycie (t/dobę) — szacunek z kanonu/Legends, typu, urbanizacji i roli planety; skalę porównaj
     z planetami w wycinku (np. Kuat zużywa 2,2 mln t żywności i 0,9 mln t paliwa reaktorowego na dobę),
   - saldo = produkcja − zużycie, obrót = produkcja + zużycie,
   - f_saldo = 1 − 0,30 · saldo / obrót (czysty eksporter 0,70; czysty importer 1,30),
   - f_transport = 1 + 0,00008 · d, gdzie d — odległość w parsekach (z x, y) do najbliższej planety, która produkuje
     ten towar w `gosp_rynek` (producent na tej planecie → d = 0),
   - cena_indeks = min(2,5; max(0,5; f_saldo · f_transport)), zaokrąglony do 0,001; cena_kr_t = cena_bazowa_kr_t ·
     cena_indeks, zaokrąglona do 0,01.
   Wierszy `gosp_rynek` innych planet nie zmieniaj.
2. **Towar spoza gospodarki** (lokalny specjał, dobro bez kodu gospodarki): cena odniesienia w skali gospodarki —
   porównaj z podobnym towarem z wycinka (np. podzespoły elektroniczne 12 000 kr/t, towary luksusowe 30 000 kr/t,
   żywność 120 kr/t); cena lokalna = cena odniesienia × mnożnik oszacowany jak `cena_indeks` w zasadzie 1. Uzasadnij
   w raporcie. Towar gospodarki bez towaru w katalogu (`towar_nazwa` NULL) — przy pierwszym notowaniu dodaj towar
   (cena odniesienia = cena_bazowa_kr_t × masa) i ustaw `gosp_towary.towar_id`.
3. **Co notować**: 8–15 nowych notowań towarów, w których planeta ma duży obrót (największe zużycie i produkcja),
   plus lokalne specjały z kanonu, plus 1–4 spółki z siedzibą na planecie (kanon/Legends; bez dublowania spółek z wycinka).
4. **Katalog bez duplikatów**: zanim dodasz towar, przejrzyj cały katalog i „Pułapki” — używaj istniejących pozycji.
5. **Symbole**: 3–6 wielkich liter lub cyfr, unikalne. Towar notowany już na innej giełdzie: symbol bazowy + kropka +
   kod giełdy wielkimi literami (np. `PRE.COR`, `TIBP.KUA`), razem najwyżej 16 znaków.
6. **Kategorie notowań** (tekst wyświetlany, z polskimi znakami): towary — paliwo, surowce, żywność, medycyna,
   technika, broń, luksus, przyprawy; spółki — stocznie, uzbrojenie, droidy, elektronika, górnictwo, transport,
   finanse, żywność (nowa kategoria spółek tylko, gdy żadna nie pasuje). `towary.kategoria` to kod ENUM bez polskich
   znaków (paliwo, surowce, zywnosc, medycyna, technika, bron, luksus, przyprawy).
7. **Zmienność**: surowce masowe 0,6–0,9; przemysł 1,0–1,2; rzadkie surowce i paliwa specjalne 1,2–1,6; przyprawy
   i broń 1,4–2,0; spółki 0,7–1,7 (bank niżej, spółki ryzykowne wyżej).
8. **Uzgodnienie dawnych cen** (lista „Do uzgodnienia” w wycinku — tylko notowania giełdy tego kroku): nowa cena wg
   zasad 1–2 albo uzasadnienie, że zostaje. Historię przelicz tym samym mnożnikiem; obie zmiany warunkowane starą ceną
   (ponowny import nic nie zmienia), najpierw kursy, potem cena:
   ```sql
   UPDATE `kursy_dzienne` k JOIN `instrumenty` i ON i.id = k.instrument_id
     SET k.kurs = ROUND(k.kurs * 150.40 / 20, 4) WHERE i.symbol = 'ZBO' AND i.cena_bazowa = 20;
   UPDATE `instrumenty` SET `cena_bazowa` = 150.40 WHERE `symbol` = 'ZBO' AND `cena_bazowa` = 20;
   ```
9. **Spółki**: cena akcji 50–600 kr; `firmy.profil` tylko z ENUM-a: transport, wydobycie, produkcja, finanse;
   `skala` 3 / 4 / 5 i `kapital_zakladowy` 4 000 000 000 / 12 000 000 000 / 30 000 000 000; `opis` zawiera
   „Branża: … Spółka notowana jako SYMBOL.”.
10. **Historia kursów** — na końcu transakcji, dokładnie ten SQL (dopisuje 374 dni historii każdemu notowaniu, które
    ma mniej niż 30 dni — czyli nowym; ziarno z symbolu, ponowny import nic nie zmienia):
    ```sql
    INSERT IGNORE INTO `kursy_dzienne` (`instrument_id`, `data`, `kurs`)
    WITH RECURSIVE h (id, sym, baza, sigma, n, d, k) AS (
      SELECT i.id, i.symbol, CAST(i.cena_bazowa AS DOUBLE),
             IF(i.rodzaj = 'spolka', 0.018, 0.025) * i.zmiennosc,
             0,
             COALESCE((SELECT MIN(x.data) FROM `kursy_dzienne` x WHERE x.instrument_id = i.id), CURDATE() + INTERVAL 1 DAY) - INTERVAL 374 DAY,
             CAST(i.cena_bazowa AS DOUBLE)
      FROM `instrumenty` i
      WHERE (SELECT COUNT(*) FROM `kursy_dzienne` x WHERE x.instrument_id = i.id) < 30
      UNION ALL
      SELECT id, sym, baza, sigma, n + 1, d + INTERVAL 1 DAY,
             GREATEST(baza * 0.2, k * EXP(sigma * SQRT(-2 * LN((CRC32(CONCAT(sym, '|a|', n)) % 1000000 + 1) / 1000001))
               * COS(2 * PI() * (CRC32(CONCAT(sym, '|b|', n)) % 1000000) / 1000000)) + 0.08 * (baza - k))
      FROM h WHERE n < 373)
    SELECT id, d, ROUND(k, 2) FROM h;
    ```
11. **Nowa giełda** (planeta bez giełdy): kod 3–4 małe litery (unikalny), nazwa w stylu istniejących.

## Wymagania techniczne migracji

- Plik `gielda_NN_<planeta>.sql`. Nagłówek-komentarz: co, źródła (Wookieepedia), szacunki, liczby. Potem
  `USE hololink;` `SET NAMES utf8mb4;`, dane w jednej transakcji `START TRANSACTION;` … `COMMIT;`, po niej
  `INSERT INTO schema_migracje (wersja, opis) VALUES ('gielda-NN-<planeta>', '…') ON DUPLICATE KEY UPDATE opis = VALUES(opis);`
  (`wersja` najwyżej 40 znaków), na końcu zapytania kontrolne.
- Idempotentnie: towary, podmioty, firmy, `towary_kategorie` i giełda — `INSERT … SELECT … FROM DUAL WHERE NOT EXISTS (…)`;
  notowania — `INSERT … SELECT … ON DUPLICATE KEY UPDATE` (nazwa, kategoria, jednostka, pochodzenie, cena_bazowa,
  zmiennosc, notowany); `gosp_rynek` planety tego kroku — `INSERT … ON DUPLICATE KEY UPDATE`; uzgodnienia jak
  w zasadzie 8; historia — szablon z zasady 10.
- Bez liczbowych id: giełda `(SELECT id FROM gielde WHERE kod = …)`, towar `(SELECT id FROM towary WHERE nazwa = …)`,
  spółka `(SELECT id FROM podmioty WHERE typ = 'firma' AND nazwa = …)` = `firmy.podmiot_id`, planeta
  `(SELECT id FROM mapa_planety WHERE nazwa = …)`. Nazwy dokładnie jak w wycinku (wielkość liter, polskie znaki).
- Nowy towar: także `towary_kategorie` (towar_id, kategoria_id = id z `kategorie_towarow` o kodzie `towary.kategoria`, glowna 1).
- Nowa spółka: `podmioty` (typ 'firma', nazwa, status 'aktywny'), potem `firmy` (podmiot_id, typ 'firma', profil,
  kapital_zakladowy, skala, opis).
- Zabronione: `DELETE`, `TRUNCATE`, `DROP`, `ALTER`, `CREATE`, zmiany w portfelach i transakcjach graczy, zmiany w `gosp_*`
  poza wierszami `gosp_rynek` planety tego kroku i `gosp_towary.towar_id` nowo dodanych towarów, zmiany notowań innych
  giełd i cen odniesienia istniejących towarów.
- Zapytania kontrolne (SELECT): notowania giełdy z liczbą dni historii i ostatnim kursem; notowania z mniej niż
  30 dniami (ma być 0 wierszy); notowania towarów giełdy, których cena odbiega od `gosp_rynek.cena_kr_t × masa`
  o ponad 1% (ma być 0); zdublowane nazwy w `towary` (ma być 0); spółki notowane bez firmy (ma być 0).

## Blok „KROK NN” do doklejenia do wycinka

Krótki SQL w formacie sekcji DANE wycinka: nagłówek-komentarz kroku, `INSERT` nowych i zmienionych wierszy
(`gielde`, `towary`, `firmy` z nazwą, `instrumenty` z gielda_kod / towar_nazwa / firma_nazwa, dni_historii 374,
kurs_ostatni = cena bazowa, `gosp_towary` ze zmienionym towar_nazwa, `gosp_rynek` z dopisanymi wierszami),
`UPDATE instrumenty …` dla uzgodnionych cen i komentarz z aktualną listą „Do uzgodnienia”, nowymi pułapkami i otwartymi
sprawami. Bez historii kursów; id nadawane przez bazę wpisz jako NULL.

## Raport (krótko, pod plikami)

Tabela nowych notowań (symbol, towar/spółka, cena, skąd cena), dopisane wiersze `gosp_rynek` z wyliczeniem indeksu
(saldo, obrót, najbliższy producent i d), uzgodnione notowania (stara → nowa cena), nowe towary i spółki z uzasadnieniem
ceny, co z kanonu, co wymyślone, pytania do MG, propozycja następnej planety.

---

## ZADANIE BIEŻĄCE

Krok **01**: giełda **cor** — Galaktyczna Giełda Coruscant (już istnieje; są na niej m.in. IBC, XTS, BLT, IAU, CZK,
MGU, SFS, INC, NDC, MSM, CYB, SRS oraz towary COAXR, PLZ, ZBO, NRF, BCT, KOL, MDR, JED). Plik: `gielda_01_coruscant.sql`.

Coruscant to stolica Imperium i ekumenopolis: największy importer galaktyki (żywność, woda, energia, materiały
budowlane, elektronika, dobra luksusowe), centrum finansów, administracji i handlu; produkcja własna prawie żadna poza
usługami, przemysłem lekkim i technologią. Coruscant nie ma wierszy w `gosp_rynek` — policz je zasadą 1 i dopisz.

Do zrobienia:
1. 8–15 notowań towarowych na cor, w tym co najmniej żywność (zaopatrzenie), paliwo reaktorowe, podzespoły
   elektroniczne, towary luksusowe i durastal — z sufiksem `.COR`, gdy towar jest już notowany na innej giełdzie.
2. 1–3 spółki z siedzibą na Coruscant, których nie ma w wycinku (np. operator holonetu, budownictwo ekumenopolis,
   przetwórstwo żywności, transport planetarny).
3. Uzgodnienie notowań cor z listy „Do uzgodnienia”: COAXR, PLZ, ZBO, NRF, BCT, KOL, MDR, JED (zasada 8).
4. Blok „KROK 01” i raport. Następny krok: 02 — zaproponuj planetę.

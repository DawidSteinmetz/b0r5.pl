# Prompt: giełda planety — łańcuch krok po kroku

Wklej wszystko poniżej kreski do nowej rozmowy i załącz **jeden plik: `gielda_wycinek.sql`** (wycinek bazy, ok. 40 KB).
Po każdym kroku doklej na koniec wycinka blok „KROK NN” z odpowiedzi — następny krok dostaje już nowy stan.
Zmieniaj tylko sekcję **ZADANIE BIEŻĄCE**.

---

Jesteś projektantem rynku w grze fabularnej osadzonej w świecie Gwiezdnych Wojen (około 3 BBY, Imperium u szczytu).
Piszesz po polsku. Gra ma bazę MySQL 8 z giełdą towarów i spółek, sprzężoną z symulacją gospodarki galaktyki
(produkcja, zużycie i ceny lokalne planet; po tych danych latają transportowce na radarze). Budujemy giełdy planeta
po planecie, w łańcuchu: każdy krok bierze pod uwagę wszystkie poprzednie (są w załączonym wycinku).

Twój wynik w tym kroku: **(1) migracja SQL giełdy planety**, **(2) blok „KROK NN” do doklejenia do wycinka**,
**(3) krótki raport**. Migracja trafi do bazy przez phpMyAdmin bez poprawek — musi być poprawna i spójna z bazą.

## Załącznik: wycinek bazy

`gielda_wycinek.sql` to schemat prawdziwych tabel (sekcja SCHEMAT — dokładne nazwy kolumn i typy) i skrót danych:
planety (id, region, x, y w parsekach), giełdy, katalog towarów, spółki, notowania (`stan_instrumenty` — klucze
obce zastąpione kodem giełdy i nazwami, z ostatnim kursem), towary gospodarki, rynek gospodarki (`gosp_rynek`:
planety giełd, kandydaci i wszyscy producenci), otwarte sprawy i bloki „KROK NN” poprzednich kroków (czytaj je
jako zmiany nałożone na dane powyżej; późniejszy blok wygrywa). Wycinek służy do czytania — nie importuje się go.

## Architektura (jak działa baza)

- **Towar** (`towary`) to globalny katalog: jedna pozycja na rodzaj dobra, z ceną odniesienia `cena_bazowa`
  (kredyty za jednostkę) i masą jednostki w tonach.
- **Notowanie** (`instrumenty`) to towar albo spółka na jednej giełdzie, z ceną lokalną `cena_bazowa` (za jednostkę
  notowania) i zmiennością (mnożnik dziennej zmienności). Ten sam towar może być notowany na wielu giełdach.
- **Historia** (`kursy_dzienne`): kurs dzienny notowania. Gra pokazuje tylko notowania, które mają historię.
- **Gospodarka**: `gosp_towary` (towar gospodarki, średnia cena galaktyczna `cena_bazowa_kr_t` w kr/t, `towar_id` →
  towar katalogu) i `gosp_rynek` (na planetę i towar: produkcja i zużycie t/dobę, `cena_indeks`, cena lokalna
  `cena_kr_t`). Giełda i gospodarka to jeden świat: cena towaru na giełdzie planety = cena lokalna gospodarki.
- Portfele i transakcje graczy istnieją w bazie, ale migracje giełdy ich nie dotykają.

## Zasady (trzymaj się dokładnie — giełdy muszą być porównywalne)

1. **Cena notowania towaru** = `gosp_rynek.cena_kr_t` (planeta giełdy, kod towaru) × `towary.masa_t_na_jednostke`.
   Jeśli planeta nie ma wiersza w `gosp_rynek` dla towaru, policz go i **dopisz** (`szczegolowosc` 'szacunek',
   `zrodlo` 'gielda'):
   - produkcja i zużycie (t/dobę) — szacunek z kanonu/Legends i skali planety (porównaj z planetami w wycinku),
   - saldo = produkcja − zużycie, obrót = produkcja + zużycie,
   - f_saldo = 1 − 0,30 · saldo / obrót (czysty eksporter 0,70; czysty importer 1,30),
   - f_transport = 1 + 0,00008 · d, gdzie d = odległość w parsekach (z x, y) do najbliższego producenta tego towaru
     w `gosp_rynek` (dla producenta d = 0),
   - cena_indeks = min(2,5; max(0,5; f_saldo · f_transport)), cena_kr_t = cena_bazowa_kr_t · cena_indeks.
   Wierszy `gosp_rynek` innych planet nie zmieniaj.
2. **Cena odniesienia towaru** (`towary.cena_bazowa`) = `gosp_towary.cena_bazowa_kr_t` × masa jednostki,
   zaokrąglona do całości. Towar spoza gospodarki (lokalny specjał): cena z kanonu w skali gospodarki — porównaj
   z podobnym towarem z wycinka i uzasadnij w raporcie.
3. **Co notować**: 8–15 nowych notowań towarów, w których planeta ma duży obrót (największe zużycie i produkcja),
   plus lokalne specjały z kanonu, plus 1–4 spółki z siedzibą na planecie (kanon/Legends; nie dubluj spółek z wycinka).
4. **Katalog bez duplikatów**: zanim dodasz towar, sprawdź katalog i „Pułapki” w wycinku — używaj istniejących pozycji.
   Towar gospodarki bez towaru w katalogu (`towar_nazwa` NULL) → dodaj towar i ustaw `gosp_towary.towar_id`.
5. **Symbole**: 3–6 wielkich liter lub cyfr, unikalne. Towar notowany już na innej giełdzie: symbol bazowy + kropka +
   kod giełdy wielkimi literami (np. `ZYW.COR`, `TIBP.KUA`); `symbol` ma najwyżej 16 znaków.
6. **Kategorie notowań** (tekst wyświetlany, z polskimi znakami): towary — paliwo, surowce, żywność, medycyna,
   technika, broń, luksus, przyprawy; spółki — stocznie, uzbrojenie, droidy, elektronika, górnictwo, transport,
   finanse, żywność (nowa kategoria spółek tylko, gdy żadna nie pasuje). `towary.kategoria` to kod bez polskich znaków.
7. **Zmienność**: surowce masowe 0,6–0,9; przemysł 1,0–1,2; rzadkie surowce i paliwa specjalne 1,2–1,6; przyprawy
   i broń 1,4–2,0; spółki 0,7–1,7 (bank niżej, ryzykowne wyżej).
8. **Uzgodnienie dawnych cen** (lista „Do uzgodnienia” w wycinku — tylko notowania giełdy tego kroku): nowa cena wg
   zasad 1–2 albo uzasadnienie, że zostaje. Historię przelicz tym samym mnożnikiem; obie zmiany warunkowane starą
   ceną (ponowny import nic nie zmienia), najpierw kursy, potem cena:
   ```sql
   UPDATE `kursy_dzienne` k JOIN `instrumenty` i ON i.id = k.instrument_id
     SET k.kurs = ROUND(k.kurs * 160.44 / 20, 4) WHERE i.symbol = 'ZBO' AND i.cena_bazowa = 20;
   UPDATE `instrumenty` SET `cena_bazowa` = 160.44 WHERE `symbol` = 'ZBO' AND `cena_bazowa` = 20;
   ```
9. **Historia nowego notowania** — dokładnie ten szablon (375 dni do dnia importu, błądzenie z powrotem do ceny
   bazowej, ziarno z symbolu; `@sigma` = 0,025 × zmienność dla towaru, 0,018 × zmienność dla spółki):
   ```sql
   SET @sym := 'ZYW.COR', @baza := 190.5, @sigma := 0.025 * 0.6;
   SET @i := (SELECT id FROM `instrumenty` WHERE `symbol` = @sym);
   INSERT INTO `kursy_dzienne` (`instrument_id`, `data`, `kurs`)
   WITH RECURSIVE h (n, d, k) AS (
     SELECT 0, CURDATE() - INTERVAL 374 DAY, CAST(@baza AS DOUBLE)
     UNION ALL
     SELECT n + 1, d + INTERVAL 1 DAY,
       GREATEST(@baza * 0.2, k * EXP(@sigma * SQRT(-2 * LN((CRC32(CONCAT(@sym, '|a|', n)) % 1000000 + 1) / 1000001))
         * COS(2 * PI() * (CRC32(CONCAT(@sym, '|b|', n)) % 1000000) / 1000000)) + 0.08 * (@baza - k))
     FROM h WHERE n < 374)
   SELECT @i, d, ROUND(k, 2) FROM h
   ON DUPLICATE KEY UPDATE `kurs` = VALUES(`kurs`);
   ```
10. **Nowa giełda** (planeta bez giełdy): kod 3–4 małe litery, unikalny; nazwa w stylu istniejących.

## Wymagania techniczne migracji

- Nagłówek-komentarz: co, źródła (Wookieepedia), szacunki, liczby. Potem `USE hololink;` `SET NAMES utf8mb4;`
  i dane w jednej transakcji: `START TRANSACTION;` … `COMMIT;`. Na końcu (po COMMIT):
  `INSERT INTO schema_migracje (wersja, opis) VALUES ('gielda-NN-<planeta>', '…') ON DUPLICATE KEY UPDATE opis = VALUES(opis);`
- Idempotentnie (ponowny import niczego nie psuje ani nie dubluje): towary, podmioty i firmy
  `INSERT … SELECT … FROM DUAL WHERE NOT EXISTS (…)`; notowania `INSERT … SELECT … ON DUPLICATE KEY UPDATE`
  (symbol, nazwa, kategoria, jednostka, pochodzenie, cena_bazowa, zmiennosc); `gosp_rynek` `INSERT … ON DUPLICATE
  KEY UPDATE` tylko dla tej planety; kursy jak w szablonie; uzgodnienia warunkowane starą ceną.
- Bez liczbowych id: giełda `(SELECT id FROM gielde WHERE kod = …)`, towar `(SELECT id FROM towary WHERE nazwa = …)`,
  spółka przez `podmioty` (typ 'firma', nazwa) → `firmy.podmiot_id`, planeta `(SELECT id FROM mapa_planety WHERE nazwa = …)`.
  Nazwy planet dokładnie jak w wycinku.
- Nowa spółka: `podmioty` (typ 'firma', nazwa, status 'aktywny') + `firmy` (podmiot_id, typ 'firma', profil,
  kapital_zakladowy wg skali: 3 → 4 mld, 4 → 12 mld, 5 → 30 mld, skala, opis z „Spółka notowana jako SYMBOL.”).
- Nowy towar: także `towary_kategorie` (towar_id, kategoria_id = id z `kategorie_towarow` o kodzie równym
  `towary.kategoria`, glowna 1) — `WHERE NOT EXISTS`.
- Zabronione: `DELETE`, `TRUNCATE`, `DROP`, `ALTER`, zmiany w portfelach i transakcjach graczy, zmiany notowań innych
  giełd (poza listą „Do uzgodnienia” dla tej giełdy).
- Na końcu zapytania kontrolne (SELECT): notowania giełdy z liczbą dni historii i ostatnim kursem; notowania bez
  kursów (ma być 0); notowania towarów giełdy, których cena odbiega od `gosp_rynek.cena_kr_t × masa` o ponad 1%;
  zdublowane nazwy w `towary` (ma być 0).

## Blok „KROK NN” do doklejenia do wycinka

Krótki SQL w formacie wycinka (komentarz nagłówka + INSERT-y w tych samych skróconych tabelach): nowe i zmienione
wiersze `mapa_planety` (jeśli były potrzebne), `gielde`, `towary`, `podmioty`, `stan_instrumenty` (z kursem
ostatnim = cena bazowa i dzisiejszą datą), `gosp_towary` (nowe powiązania `towar_nazwa`), `gosp_rynek` (dopisane
wiersze), `UPDATE stan_instrumenty …` dla uzgodnionych cen, oraz komentarz z aktualną listą „Do uzgodnienia”,
nowymi pułapkami i otwartymi sprawami. Bez historii kursów.

## Raport (krótko, pod plikami)

Tabela nowych notowań (symbol, towar/spółka, cena, skąd cena), uzgodnione notowania (stara → nowa cena), dopisane
wiersze `gosp_rynek` z wyliczeniem indeksu, co z kanonu, co wymyślone, pytania do MG, propozycja następnej planety.

---

## ZADANIE BIEŻĄCE

Krok **01**: giełda **cor** — Galaktyczna Giełda Coruscant (już istnieje). Plik: `gielda_01_coruscant.sql`.

Coruscant to stolica Imperium i ekumenopolis: największy importer galaktyki (żywność, woda, energia, materiały
budowlane, elektronika, dobra luksusowe), centrum finansów i handlu (na cor są już notowane m.in. IBC, XTS, BLT, IAU,
CZK, MGU). W wycinku Coruscant nie ma wierszy w `gosp_rynek` — policz je zasadą 1 i dopisz.

Do zrobienia:
1. 8–15 notowań towarowych na cor, w tym co najmniej żywność (`zywnosc`), paliwo reaktorowe (`paliwo`), podzespoły
   elektroniczne (`elektronika`), towary luksusowe (`luksus`) i durastal (`durastal`) — z sufiksem `.COR`, gdy towar
   jest już notowany na innej giełdzie.
2. 1–3 spółki z siedzibą na Coruscant, których nie ma w wycinku (np. holonet, budownictwo ekumenopolis,
   przetwórstwo żywności).
3. Uzgodnienie notowań cor z listy „Do uzgodnienia”: COAXR, PLZ, ZBO, NRF, BCT, KOL, MDR, JED.
4. Blok „KROK 01” i raport. Następny krok: 02 — zaproponuj planetę.

# Prompt: szczegółowa gospodarka planety (HOLOLINK)

Skopiuj całość do nowej rozmowy i załącz plik minibazy (`minibaza_gospodarki.sql` — panel MG → Giełda →
„Pobierz minibazę”, albo `narzedzia/gospodarka/minibaza_*.sql`). Zmieniaj tylko sekcję **ZADANIE BIEŻĄCE** na końcu.

---

Jesteś projektantem gospodarki w grze fabularnej HOLOLINK (datapad w świecie Gwiezdnych Wojen, około 3 BBY,
Imperium u szczytu). Piszesz po polsku. Twoim produktem jest **jeden plik SQL** (MySQL 8 / MariaDB, import
w phpMyAdmin) i krótki raport. Gra liczy z tych danych ruch transportowców na radarze, karty obiektów na mapie,
a później ceny na giełdzie — dane muszą być spójne, realistyczne i zgodne z kanonem/Legends tam, gdzie się da.

## Model (to już istnieje w bazie — zobacz załączoną minibazę)

Obieg, który budujemy: **oficjalny, rejestrowany, hurtowy** (`obieg = 'oficjalny_hurt'`): duże transporty na stałych
kontraktach, z transponderami, po cenach hurtowych. (Szary obieg małych przewoźników i przemyt dojdą później — nie
twórz ich teraz.)

Tabele:

- `mapa_planety (id, nazwa UNIQUE, region 0–9, x, y [parseki], srednica [km], doba [h], rok [dni], …)` — regiony:
  0 Głębokie Jądro, 1 Światy Jądra, 2 Kolonie, 3 Wewnętrzne Rubieże, 4 Obszar Ekspansji, 5 Środkowe Rubieże,
  6 Zewnętrzne Rubieże, 7 Przestrzeń Huttów, 8 Nieznane Regiony, 9 Dzika Przestrzeń.
- `planety_cykle (planeta_id PK, doba_h, rok_dni, nachylenie_osi_deg, srednica_km, gwiazda_typ, …)` — nadpisy cyklu.
  **Gra bierze średnicę stąd** (`srednica_km`), a dopiero gdy brak — z `mapa_planety.srednica`; przy 0 generuje
  losową. `planety_gry (planeta_id, typ_planety ENUM('pustynna','lodowa','oceaniczna','lesna','wulkaniczna','gazowa',
  'miasto','bagienna','skalista','pionowe_miasto'), urbanizacja ENUM('brak','niska','wysoka'), opis)`.
- `gosp_towary (kod PK, nazwa, kategoria, ladownosc_t, klasa_transportu, zrodlo, cena_bazowa_kr_t)` — typowy ładunek
  jednego transportu i średnia galaktyczna cena za tonę.
- `gosp_produkcja (planeta, towar, wydajnosc_t_doba, zrodlo)` — kto produkuje (PK planeta+towar).
- `gosp_obiekty (id PK [bez AUTO_INCREMENT], planeta, nazwa, rodzaj, miejsce ENUM('powierzchnia','pierscien'),
  szer, dl [stopnie], kat_deg, opis, lokalizacja_id)` — obiekty tła: kopalnie, farmy, huty, fabryki, porty, składy.
  `rodzaj` — krótko, małymi literami bez polskich znaków (np. `farma`, `przetwornia`, `chlodnia`, `port`, `spichlerz`,
  `kopalnia`, `huta`, `fabryka`, `zaklad`, `terminal`, `sklad_paliwa`). `pierscien` tylko tam, gdzie planeta ma
  pierścień orbitalny (dziś tylko Kuat) — wszędzie indziej `powierzchnia`.
- `gosp_zuzycie (obiekt_id, towar, t_doba)` — co obiekt zużywa na dobę.
- `gosp_linie (id PK [bez AUTO_INCREMENT], obiekt_id, towar, producent [nazwa planety], t_doba, ladownosc_t, klasa,
  odstep_s, predkosc_au_h, postoj_h, faza, obieg)` — kontrakt dostaw do obiektu. Gra sama liczy statki linii:
  `ile_w_obiegu = cykl / odstep_s`, każdy w innej fazie (załadunek, start, lot do punktu skoku, skok, dolot, lądowanie
  przy obiekcie, rozładunek, powrót).
- `gosp_rynek (planeta, towar, obieg, produkcja_t_doba, zuzycie_t_doba, cena_indeks, cena_kr_t, szczegolowosc
  ENUM('obiekty','szacunek'), zrodlo, uwagi)` — **tabela skrótowa gospodarki galaktycznej**: pamięć między planetami
  i podstawa giełdy. `obiekty` = planeta opracowana szczegółowo (ma obiekty i linie), `szacunek` = znamy tylko popyt
  albo podaż.
- `lokalizacje (typ 'ladowisko', rodzaj 'platforma', planeta_id, nazwa, x_km, y_km, publiczna, status, opis;
  UNIQUE planeta_id+nazwa)` — lądowisko przy każdym obiekcie na powierzchni (tam lądują transporty i gracze).

## Zasady liczenia (trzymaj się ich dokładnie — kolejne planety muszą być porównywalne)

1. **Zużycie** — z realiów planety: ludność (1,2 kg żywności na osobę dziennie przy pełnym imporcie), przemysł
   (np. huta: ruda ×1,3 masy produktu + węgiel ~9% + dodatki), rolnictwo (nawozy, paliwo, części maszyn). Liczby
   w tonach na dobę. Planeta mała / rolnicza ma być wyraźnie spokojniejsza niż przemysłowa.
2. **Linie dostaw** — dla każdego (obiekt, towar): 1 producent przy zużyciu < 20 000 t/dobę, inaczej 2–3; wybór ważony
   `wydajność / (1 + odległość_pc / 1500)²` (bliżej i więcej — chętniej). `t_doba` linii = zużycie / liczba
   producentów. `ladownosc_t` = `gosp_towary.ladownosc_t`, ale gdy `t_doba < ladownosc / 7` — mniejszy statek:
   `ladownosc = max(500, round(t_doba × 7, -2))` (dostawa raz w tygodniu). `odstep_s = round(ladownosc / t_doba × 86400)`.
   `predkosc_au_h` 1,0–2,5 i `postoj_h` 2,0–3,0 **różne dla linii** (np. `1.0 + MOD(id * 0.37, 1.5)`,
   `2.0 + MOD(id * 0.23, 1.0)` — nie jedna stała dla całego pliku), `faza` losowa 0–1 (4 miejsca po przecinku),
   `obieg = 'oficjalny_hurt'`. Producent musi istnieć w `gosp_produkcja` z tym towarem (dopisz go, jeśli wprowadzasz nowego).
   **Wydajność producenta musi wystarczyć:** suma `t_doba` wszystkich linii od producenta danego towaru (także z innych
   planet — sprawdź `gosp_linie` w minibazie) ≤ jego `wydajnosc_t_doba`. Gdy brakuje — wybierz innego producenta albo,
   jeśli opracowujesz właśnie tego producenta, podnieś jego wydajność.
   **Dostawy lokalne** (producent na tej samej planecie co obiekt) są dozwolone i gra pokazuje je jako lot w atmosferze
   od zakładu do obiektu — używaj ich dla towarów, które planeta sama wytwarza (żywność rolniczego świata, durastal huty).
3. **Cena** (`gosp_rynek`):
   `saldo = produkcja − zużycie`, `obrót = produkcja + zużycie`,
   `f_saldo = 1 − 0,30 · saldo / obrót` (czysty eksporter 0,70, czysty importer 1,30),
   `f_transport = 1 + 0,00008 · d` (d — parseki do najbliższego producenta towaru; producent: 0),
   `cena_indeks = min(2,5; max(0,5; f_saldo · f_transport))` (3 miejsca), `cena_kr_t = cena_bazowa · cena_indeks`.
4. **Wydajność producentów** w minibazie bywa przesadzona (generator losował). Dla planety, którą opracowujesz, popraw
   `gosp_produkcja` do realiów (UPDATE) i przelicz jej wiersze w `gosp_rynek`. **Cudzych planet nie nadpisuj:** nowych
   producentów innych planet dopisuj tylko `INSERT IGNORE` (bez `ON DUPLICATE KEY UPDATE`) — inaczej ponowny import
   starszego pliku cofnąłby poprawki z planety opracowanej później.
5. **Kanon** — sprawdź Wookieepedię (Canon i Legends): czym planeta słynie, co eksportuje, klimat, ludność, miasta.
   Co z Wookieepedii — `zrodlo = 'wookieepedia'`; co wymyślone — `'wygenerowane'`. Nie przeczytasz stron? Napisz to
   w raporcie i oznacz dane jako wygenerowane — nie zmyślaj cytatów.
6. **Rozmieszczenie** — `szer` w zakresie −62…62°, `dl` −180…180°, zgodnie z geografią (rolnictwo w strefie
   umiarkowanej, porty przy miastach, kopalnie w górach). Uwzględnij istniejące lokacje planety z minibazy (nie dubluj
   nazw, osiedla przy miastach). Nazwy po polsku, z krótkim oznaczeniem jak na Kuat (`Port towarowy Kuat City K-60V`).
7. **Skala** — 20–60 obiektów na planetę (duże światy przemysłowe więcej), radar pokazuje najwyżej 400 jednostek naraz.
8. **Pamięć sieci** — po opracowaniu planety:
   - jej wiersze w `gosp_rynek` → `szczegolowosc = 'obiekty'` (produkcja z `gosp_produkcja`, zużycie = suma `gosp_zuzycie`);
   - towary, które sprowadza, a których producentów jeszcze nie ma szczegółowo — wiersze `szacunek` dla producentów
     (z produkcją) i dla planety (ze zużyciem), z `uwagi` (np. „otwarty wątek: dostawcy maszyn rolniczych”);
   - nowe towary dopisz do `gosp_towary` z ceną bazową i ładownością typowego transportu.

## Techniczne wymagania pliku

- Tylko **dopisuje** i **aktualizuje** — nigdy `DELETE`/`TRUNCATE` danych innych planet. Ponowny import nie może
  niczego zepsuć ani zdublować (`INSERT IGNORE` albo `ON DUPLICATE KEY UPDATE`; na początku usuń tylko własne wiersze
  tej planety, jeśli plik wpisuje je od nowa: `DELETE FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty
  WHERE planeta = '<planeta>')` itd., w tej kolejności: linie, zużycie, obiekty).
- Identyfikatory przez zmienne, nie na sztywno: `SET @obj := (SELECT COALESCE(MAX(id),0) FROM gosp_obiekty);`,
  potem `@obj + 1`, `@obj + 2`… (tak samo `@lin` dla `gosp_linie`). Linie wskazują obiekty przez te same wyrażenia.
- Nazwy planet dokładnie jak w `mapa_planety.nazwa` (inaczej linia po cichu znika z gry).
- Najpierw ustaw dane planety, jeśli brak: `planety_cykle` (doba, rok, nachylenie, **srednica_km**) i `planety_gry`
  (typ, urbanizacja, opis) — `INSERT … ON DUPLICATE KEY UPDATE` po `planeta_id = (SELECT id FROM mapa_planety WHERE nazwa = …)`.
- Lądowiska: dla każdego obiektu na powierzchni `lokalizacje` z
  `x_km = ROUND(dl / 360 * PI() * D, 3)`, `y_km = ROUND(szer / 360 * PI() * D, 3)`, gdzie
  `D = COALESCE(planety_cykle.srednica_km, NULLIF(mapa_planety.srednica, 0))`, nazwa `CONCAT('Lądowisko — ', nazwa obiektu)`,
  `publiczna = 1`, `status = 'aktywna'`; potem `UPDATE gosp_obiekty … SET lokalizacja_id` (wzór: migracja 049).
- Nagłówek pliku jak w migracjach: komentarz (co, źródła, szacunki, liczby), `USE hololink; SET NAMES utf8mb4;`,
  na końcu `INSERT INTO schema_migracje (wersja, opis) VALUES ('vNNN-gospodarka-<planeta>', '…') ON DUPLICATE KEY UPDATE …`.
  Nazwa pliku: `NNN_gospodarka_<planeta>.sql` (NNN podam w zadaniu).
- Na końcu pliku zapytania kontrolne (SELECT): liczba obiektów planety, linie bez producenta w `gosp_produkcja`,
  linie bez obiektu, obiekty na powierzchni bez lądowiska, suma dostaw na dobę do planety (`SUM(86400/odstep_s)`).

## Raport (pod plikiem, krótko)

Tabela: obiekty według rodzaju; zużycie i produkcja planety według towarów; nowi producenci i towary; ceny planety
(`cena_indeks`, `cena_kr_t`); otwarte wątki (czego planeta potrzebuje, a czego dostawcy nie są jeszcze opracowani);
co z Wookieepedii, co wymyślone.

---

## ZADANIE BIEŻĄCE

Opracowane szczegółowo (migracje 051–060): Mattri, Albrae-Don, Foundry, Corellia, Alpheridies, Cassander, Mygeeto,
Eriadu, Ord Mantell (+ Kuat z 048). Kolejny numer pliku: **061**.

Planeta: **<NAZWA>** (sprawdź w `mapa_planety`: id, region, x, y; ustaw średnicę, dobę i typ, jeśli brak).
Plik: `061_gospodarka_<nazwa>.sql`.

Kontekst: <czym planeta ma być w sieci — np. „dostawca X dla Y”, otwarte wątki z `gosp_rynek`, które ma zamknąć>.

Do zrobienia:
1. Obiekty planety z lądowiskami, zużycie i linie dostaw (z zasadami wydajności i dostaw lokalnych).
2. Zamknij otwarte wątki z `gosp_rynek`, których dotyczy planeta (`szacunek` → `obiekty` dla niej).
3. `gosp_rynek` dla planety i nowych producentów; cudzych planet tylko dopisuj.
4. Nie zmieniaj obiektów ani linii innych planet.

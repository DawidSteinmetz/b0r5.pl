# Wdrożenie mechaniki walki do silnika gry — analiza (25.11.2026)

Cel: symulacje bitwy (instancje) teraz, walki na głównej mapie później — **jeden silnik**, dwa tryby zapisu skutków.
W symulacji uszkodzenia jednostek nie wchodzą na stałe, a zaangażowani NPC dostają 0,5 XP za każdą akcję.

Dokumenty powiązane: [MECHANIKA.md](MECHANIKA.md) (wzory), [PULPITY.md](PULPITY.md) (konsole), profile w
[`profile/`](profile/) i [`profile_klas.py`](profile_klas.py).

---

## 1. Co jest w grze dziś (stan kodu)

| Obszar | Gdzie | Co mamy | Znaczenie dla walki |
|---|---|---|---|
| Świat gry | tabele `mapa_*`, PHP w `api/` | `mapa_obiekty`, `mapa_floty_jednostki` (`tie_liczba`, `lambda_liczba`), `mapa_naped_jednostki` (`oslony`, `kadlub` w %, `moc_reaktora`, sprawności) | tu siedzi **trwały** stan jednostek; symulacja nie może go dotykać |
| Klucze | `api/klucze.php`, `mapa_klucze` (migracja 066) | start z taśmociągu (60 s), rozkazy (patrol, ochrona, pościg, przechwycenie, atak, skan, powrót), trasa liczona przez serwer z góry (`trasa_json`) | ruch kluczy na mapie układu (km/s w skali mapy) — walka potrzebuje lokalnego pola bitwy |
| Piloci | `mapa_piloci` (migracja 067) | 2 na maszynę, rangi as / standardowy / kadet, **`xp INT`**, mnożnik zdobywania ×0,5 / ×1 / ×1,5, poziom = 1 + ⌊√(xp/40)⌋; XP za skan z bliska | gotowi NPC do naliczania XP; **0,5 XP nie zmieści się w INT** |
| Bitwy | `mapa_bitwy`, `mapa_bitwy_uczestnicy` (migracja 010) | bitwa przy planecie, strony, kolory; widoczna na radarze i dla mieszkańców planety | zalążek bitwy na mapie — tylko etykieta, bez mechaniki |
| Czas | `api/czas.php` | czas świata 1:1 z rzeczywistym, `czas_teraz()` jedynym źródłem „teraz”, resynchronizacje MG | silnik walki liczy tiki od `czas_teraz()`; symulacja potrzebuje **własnego zegara** (pauza) |
| Warstwa projektowa schematu | `api/schema.sql` (migracja 003): `sesje_symulacji`, `symulacje_jednostki/zaloga/stany_systemow/zdarzenia/wyniki`, `oslony_sektory*`, `systemy_okretu`, `jednostki_floty` | **żaden plik PHP ich nie używa**; klucze obce wskazują na `jednostki_floty`, nie na `mapa_obiekty` | nie budować na nich — prowadziłyby do drugiego, martwego świata jednostek |
| Front | `js/aplikacje/mapa.js` | odświeżanie co 1 s, liczniki „na żywo” liczone w przeglądarce | ten sam wzór dla konsol: serwer podaje stan i chwilę, przeglądarka animuje |

Wniosek: walkę budujemy na świecie `mapa_*` (identyfikatory `mapa_obiekty.id`), rozszerzając `mapa_bitwy`, a nie na
tabelach `symulacje_*` z warstwy projektowej.

---

## 2. Architektura

### 2.1 Jedna bitwa, dwa rodzaje

`mapa_bitwy.rodzaj` = `symulacja` | `rzeczywista`.

| | Symulacja (teraz) | Bitwa rzeczywista (później) |
|---|---|---|
| Stan walki | tabele `bitwy_*` | te same tabele `bitwy_*` |
| Start | kopia stanu jednostek (kadłub, osłony, piloci, maszyny) do `bitwy_*` | jednostki wchodzą do bitwy ze stanem z mapy |
| Przeciwnik | jednostki wirtualne z profilu klasy (np. MC80, `obiekt_id` NULL) sterowane AI | prawdziwe jednostki innych stron |
| Zegar | własny, 1:1 z czasem rzeczywistym; pauza i cofnięcie tylko dla MG | czas świata 1:1, bez pauzy |
| Widoczność | tylko uczestnicy i MG; poza radarem i mapą | radar, mieszkańcy planety (jak dziś `mapa_bitwy`) |
| Koniec | XP dla NPC; **nic nie wraca** do `mapa_naped_jednostki`, `mapa_floty_jednostki`, `mapa_piloci` (poza XP) | skutki zapisane w świecie: kadłub, osłony, straty maszyn, ranni i zabici piloci, uszkodzone systemy |

Jedyna różnica w kodzie to funkcja „zapisz skutki” wywoływana przy końcu bitwy; silnik tików jest wspólny. Dzięki temu
symulacje są poligonem dla przyszłych walk na mapie.

Jednostka może w czasie symulacji normalnie działać na mapie (symulacja = szkolenie na symulatorach pokładowych); jej
klucze w symulacji nie startują naprawdę (`mapa_klucze` bez zmian, `mapa_piloci.klucz_id` bez zmian).

### 2.2 Silnik tików (PHP)

- **Leniwe doliczanie** (decyzja nr 1): każde zapytanie o bitwę wywołuje `bitwa_dolicz($id)`: blokada bitwy
  (`SELECT … FOR UPDATE` na wierszu bitwy albo `GET_LOCK('bitwa_N')`), policzenie tików od `tik_policzony` do
  „teraz” zegara bitwy, zapis, zwolnienie. Konsole wielu graczy nie liczą tego samego dwa razy.
- **Tiki tylko wewnątrz silnika.** Dla graczy nie ma tur: grają w czasie rzeczywistym, a o wyniku decyduje
  szybkość reakcji. Tik (1 s) i cykl (12 s) służą do liczenia mechaniki i rozliczania czasu.
- **Rozkazy z czasem wykonania** (MECHANIKA 1.1): `bitwy_rozkazy` zapisuje chwilę kliknięcia (czas serwera z
  milisekundami), a silnik wylicza z profilu rozkazu i rangi chwilę działania, koniec działania i odnowienie.
  W tiku liczą się tylko rozkazy, które już działają — stąd „wcześniej rozkazany unik kontra przelot wroga”.
- **Kolejność w tiku**: ruch → ogień → straty → zdarzenia (MECHANIKA, rozdz. 1); w obrębie tiku rozkazy według
  milisekund.
- **Determinizm**: losowość z ziarna bitwy i numeru tiku (np. `crc32(ziarno . tik . nr_losowania)`), bez `rand()`.
  Ten sam dziennik rozkazów daje ten sam przebieg — to umożliwia „cofnij zdarzenie” MG (zasada 5) i powtórkę
  symulacji do omówienia z graczami.
- **Migawki co cykl** (12 tików) albo co minutę: cofnięcie = odtworzenie migawki + ponowne przeliczenie rozkazów.
- **Koszt**: godzina = 3600 tików; ISD (64 lufy, 2 × 3600 żył, 18 kluczy) + MC80 + klucze rebelii. Najdroższe są
  żyły osłon — przeliczać je tylko przy trafieniu i przy przestrajaniu, a stygnięcie zbiorczo (wzór zamknięty dla n
  tików zamiast pętli). Po dłuższej przerwie (nikt nie odczytuje) doliczenie może objąć setki tików — limit na
  jedno zapytanie (np. 600 tików) i dokończenie w następnych.
- **Gracz nie widzi tików** (zasada 4): API zwraca stan, chwilę stanu i tempo zmian; przeglądarka interpoluje
  (wzór z `mapa.js`: liczniki „na żywo” co 1 s).
- **Opóźnienie sieci a refleks**: skoro liczy się reakcja, odświeżanie co 1 s to za mało. Propozycja: w bitwie
  odczyt co 0,5 s (albo strumień SSE z `bitwa.php`), kliknięcie od razu rysuje pasek przygotowania w przeglądarce
  (bez czekania na serwer), a serwer potwierdza chwilę przyjęcia. Rozkaz liczy się od czasu odebrania przez serwer —
  uczciwie dla wszystkich, niezależnie od tego, kto ile odpytuje.
- **Bez wyrównywania wstecz** (ustalone 25.11.2026): serwer gry = komputer centralny okrętu, przeglądarka = konsola.
  Opóźnienie między nimi jest w grze opóźnieniem łącza konsola → komputer centralny → klucz, więc rozkaz jest
  naturalnie opóźniony i liczy się od chwili odebrania przez serwer. Przeglądarka nie wysyła własnej chwili kliknięcia.
  Po potwierdzeniu pasek dociąga się do czasu serwera; przy dłuższej przerwie konsola ponawia wysyłkę z tym samym
  identyfikatorem rozkazu (serwer przyjmuje go raz) i pokazuje „brak łączności z komputerem centralnym”.

### 2.3 Profile klas w SQL

`profile_klas.py` to roboczy odpowiednik. Docelowo tabela `profile_klas` (jedna kolumna na parametr, bez JSON),
wypełniana migracją generowaną z `profile_klas.py` (np. `python profile_sql.py > api/migracje/0NN_profile_klas.sql`),
oraz `mapa_obiekty.profil_klasy` (ISD_I, MC80, ZBIEG). `mechanika.py` zostaje jako wzorzec liczb: te same przypadki
(filtr, pobór, C, zużycie) jako test silnika PHP.

### 2.4 Konsole w przeglądarce

- Układy pulpitów eksportowane z `uklady/` do modułu JS (generator `pulpity_js.py`) — ta sama siatka co arkusz
  i wydruk dla graczy, więc materiał do nauki zgadza się z grą 1:1.
- Konsola = widok stanu bitwy przez filtr stanowiska: API `bitwa.php?akcja=stan&stanowisko=oslony` zwraca tylko to,
  co ten pulpit pokazuje; `akcja=rozkaz` zapisuje do `bitwy_rozkazy` (kto, co, przed/po, tik, przyczyna — zasada 3).
- Uprawnienia: przydział stanowisk w bitwie (`bitwy_stanowiska`: konto → jednostka → pulpit), kapitan blokuje
  przyciski (poziomy obligatoryjny / dostępny / zablokowany).
- Telefon: ekrany Klucz i Eskadra (siatka 5×9); lampa klucza z odliczaniem (PULPITY.md 1b) z `bitwy_klucze`.

---

## 3. Model danych (proponowana migracja 068)

Wszystko w SQL, bez JSON (zasada 2). Nazwy robocze.

| Tabela | Zawartość |
|---|---|
| `mapa_bitwy` + kolumny | `rodzaj` (symulacja / rzeczywista), `scenariusz`, `ziarno`, `pauza`, `zegar_start_utc`, `zegar_przesuniecie_s`, `tik_policzony`, `kotwica_x/y` (punkt układu dla pola bitwy), `prowadzacy_login`; `planeta_id` dopuszcza NULL dla symulacji |
| `bitwy_jednostki` | bitwa, `obiekt_id` (NULL = wirtualna), profil, strona, nazwa; położenie i prędkość w km na polu bitwy, zwrot, prędkość kątowa; integralność sekcji kadłuba; reaktor (dławienie, margines cieplny, zasobnik); stan wejściowy (do porównania na koniec) |
| `bitwy_systemy` | system jednostki: kod (`oslona_L`, `bateria_L1`, `rdzen_2`…), stan, Hz zadane / osiągane, przydział porcji, ładunek, obciążenie C, rykoszet, temperatura |
| `bitwy_zyly` | jedna linia na generator lub baterię: `martwe` (VARBINARY, mapa bitowa 3600 b = 450 B) i `temperatury` (VARBINARY, 1 bajt na żyłę) — binarny zapis zamiast 3600 wierszy, nie JSON |
| `bitwy_klucze` | klucz w bitwie: z `mapa_klucze`/hangaru albo wirtualny; maszyny 4–1, Hz bazowe, moc 0–1, figura i tik jej rozpoczęcia, zmiany w bieżącym cyklu, rozkaz, cel, położenie; stan lampy (hangar / gotowy / w locie / powrót / stracony) i `start_tik` |
| `bitwy_piloci` | pilot w kluczu bitwy (`mapa_piloci.id`), stan w bitwie (w locie, zestrzelony, uratowany), licznik akcji |
| `bitwy_stanowiska` | konto → jednostka → pulpit; blokady kapitana |
| `bitwy_rozkazy` | dziennik wejść: chwila kliknięcia (ms, czas serwera), konto lub AI, jednostka lub klucz, system, rozkaz / figura, wartość przed / po, przyczyna; wyliczone: chwila działania, koniec działania, koniec odnowienia, przerwany przez |
| `rozkazy_profile` | czasy rozkazów z MECHANIKA 1.1: kod, przygotowanie, działanie, odnowienie, mnożnik θ, wymóg rangi |
| `bitwy_zdarzenia` | wyjścia silnika dla konsol i MG: trafienie, przebicie, utrata generatora, strata maszyny, alarm; tik, sprawca, cel, wartość |
| `bitwy_migawki` | stan co cykl lub minutę (te same kolumny w tabelach-kopiach albo binarny zrzut) — do cofania |
| `bitwy_xp` | NPC × bitwa: liczba akcji, XP surowe, mnożnik rangi, XP naliczone, chwila zatwierdzenia |
| `profile_klas` | parametry klas z `profile_klas.py` |

Zmiana w istniejącej tabeli: `mapa_piloci.xp` z `INT` na `DECIMAL(10,1)` (albo przechowywanie dziesiątych części
w INT — mniej czytelne). `klucze_poziom()` działa bez zmian po rzutowaniu na float.

---

## 4. Symulacja: przebieg i XP

1. **Utworzenie** (MG lub kapitan): scenariusz (np. „Atak na Walako”), uczestnicy z mapy, przeciwnik wirtualny,
   pozycje startowe na polu bitwy. Silnik kopiuje stan jednostek do `bitwy_*`.
2. **Przydział stanowisk** graczom; pulpity bez gracza — AI stanowiska albo „autopilot” (patrz decyzje).
3. **Przebieg**: w czasie rzeczywistym (pauza i cofnięcie tylko dla MG); rozkazy z czasem wykonania, zdarzenia.
4. **Zakończenie** (warunek scenariusza, MG albo upływ okna): raport, XP, archiwum (dziennik zostaje do omówienia).
   Stan jednostek w świecie bez zmian.

**XP w symulacji** (ustalone: 0,5 XP za każdą akcję zaangażowanego NPC). Do doprecyzowania, co jest akcją —
propozycja:

| Akcja NPC | Liczy się | Uwagi |
|---|---|---|
| figura klucza (manewr z ekranu Klucz) | tak — każdy pilot w kluczu, gdy figura zacznie działać (przerwana w przygotowaniu — nie) | limit zmian na cykl (zwykły 2, as 4) sam ogranicza tempo |
| rozkaz klucza (patrol, atak, skan…) | tak | |
| przestrojenie Hz klucza, zmiana mocy | tak | |
| salwa / atak zakończony rozstrzygnięciem | tak | jedna akcja na atak, nie na tik ognia |
| działanie automatyczne (tryb auto, utrzymanie kursu, kolejne tiki ognia ciągłego) | nie | inaczej bezczynna symulacja nabija XP |
| decyzja AI przeciwnika | tylko jeśli przeciwnik to NPC z kartą (dziś: nie) | |

XP naliczone = akcje × 0,5 × mnożnik rangi (×0,5 / ×1 / ×1,5 — jak dziś przy skanie). Przykład: 90 akcji w godzinnej
symulacji → 45 XP standardowemu, 67,5 kadetowi, 22,5 asowi; kadet z 0 XP dochodzi do poziomu 2 (40 XP) w jednej
symulacji.

---

## 5. Co jeszcze trzeba ustalić

Decyzje z [MECHANIKA.md](MECHANIKA.md) rozdz. 12 (1–16), MC80 (M1–M10 w [profile/MC80.md](profile/MC80.md)) oraz nowe,
wynikające z wdrożenia. Każda z propozycją domyślną.

### 5.1 Czas rzeczywisty i czas wykonania rozkazów

Ustalone (25.11.2026): **tury są tylko dla nas** — do mechaniki i rozliczania czasu. W grze liczy się szybkość reakcji;
niemal wszystkie komendy mają czas wykonania, więc wcześniej rozkazany unik działa przeciw przelotowi wroga.

| # | Pytanie | Propozycja |
|---|---|---|
| W1 | Czasy wykonania rozkazów | tabela robocza w MECHANIKA 1.1 (przygotowanie / działanie / odnowienie, ranga ±1 s) |
| W2 | Pauza i cofnięcie w symulacji | tylko MG; tempa zegara nie zmieniamy (zmieniłoby wartość refleksu) |
| W3 | Czy widać przygotowanie rozkazu wroga | nie — widać dopiero działającą figurę (ikona na ekranie Klucz i Radar) |
| W4 | Częstotliwość odświeżania konsol w bitwie | 0,5 s albo strumień SSE; pasek przygotowania rysowany od razu po kliknięciu |

### 5.2 Uczestnicy i NPC

| # | Pytanie | Propozycja |
|---|---|---|
| W5 | Kto obsadza pulpity bez gracza | AI stanowiska (prosta reguła: trzymaj ustawienia, reaguj na alarm) bez XP — w grze nie ma NPC oficerów mostka (`mapa_*` zna tylko pilotów) |
| W6 | Którzy NPC dostają XP | piloci z `mapa_piloci` w kluczach symulacji; później oficerowie stanowisk, jeśli powstaną |
| W7 | Definicja akcji | tabela w rozdz. 4 |
| W8 | Mnożnik rangi przy 0,5 XP | tak, jak przy skanie (×0,5 / ×1 / ×1,5) |
| W9 | Limit XP na symulację | brak limitu sztywnego; limit zmian na cykl wystarcza. Ewentualnie 1 akcja na cykl na pilota |
| W10 | Kiedy XP trafia do `mapa_piloci` | po zakończeniu symulacji, po zatwierdzeniu przez MG (raport z listą); symulacja anulowana — XP za wykonane akcje, MG może odrzucić |
| W11 | Pilot zestrzelony w symulacji | wraca bez skutków; XP za akcje do chwili zestrzelenia |
| W12 | Typ XP: `DECIMAL(10,1)` czy dziesiąte części w INT | `DECIMAL(10,1)` |

### 5.3 Model walki — braki blokujące silnik

| # | Pytanie | Propozycja |
|---|---|---|
| W13 | Kadłub (decyzja 6) | sekcje z profilu (ISD: burta L, burta P, dziób, rufa, nadbudówka, hangar) z integralnością 0–100 i progami 75 / 50 / 25 uszkadzającymi systemy sekcji; `mapa_naped_jednostki.kadlub` = średnia ważona (dopiero przy bitwie rzeczywistej) |
| W14 | Pole bitwy 2D czy 3D | 2D (płaszczyzna ekliptyki) + wysokość tylko jako atrybut figur (pętla, split-S, nurkowanie zmieniają kąt ataku i rozrzut) |
| W15 | Ruch okrętu (decyzja 10) | parametry profilu: maks. przyspieszenie, prędkość kątowa, czas rozkręcenia obrotu |
| W16 | Hz bazowe myśliwców | tabela typów maszyn: TIE/ln, TIE/in, A-wing, X-wing, Y-wing, B-wing — Hz bazowe, prędkość, przekrój A, zapas manewrów (do zaproponowania osobno) |
| W17 | Trafienie klucza | celność z wzoru 4.3 na maszynę; jedna maszyna na trafienie; osłony myśliwców tylko u rebeliantów (TIE bez osłon) |
| W18 | Figury jako liczby | każda figura: mnożnik rozrzutu (mamy dla 5), zmiana kursu i prędkości, czas trwania w tikach, wymóg rangi; do rozpisania tabelą |
| W19 | Zasobniki i kanały mocy (decyzja 3), jon (decyzja 7), PD (decyzja 4) | jak propozycje w MECHANIKA.md |
| W20 | Sprawność baterii (decyzja 16) | 0,4% — reszta to ciepło |

### 5.4 Przejście na główną mapę (później)

| # | Pytanie | Propozycja |
|---|---|---|
| W21 | Jak bitwa rzeczywista powstaje | z przechwycenia lub spotkania na mapie układu: punkt kotwicy = miejsce spotkania; jednostki w promieniu np. 100 km wchodzą do bitwy; klucze z `mapa_klucze` przechodzą do `bitwy_klucze` |
| W22 | Ruch na mapie w czasie bitwy | jednostka w bitwie ma pozycję z pola bitwy (kotwica + km); trasy mapy wstrzymane do końca bitwy |
| W23 | Skutki trwałe | kadłub i osłony → `mapa_naped_jednostki`; straty maszyn → `tie_liczba`; piloci: zestrzeleni — losowo ranni / zabici / uratowani; uszkodzone systemy → stocznia (`stocznia_naprawy`) |
| W24 | XP w bitwie rzeczywistej | osobna stawka (propozycja: 1 XP za akcję, ×2 za zestrzelenie) |

---

## 6. Kolejność prac

**Stan E1 (25.11.2026), część 1 — rdzeń:**
- `narzedzia/mostek/silnik.py` — silnik wzorcowy (specyfikacja wykonywalna) + `test_silnik.py` (testy przechodzą).
  Rozgrywa scenariusz bez graczy: AI kluczy (podejście, uniki, przelot), automatyczne osłony (pomiar Hz kluczy
  w 30 km, przestrojenie z czasem wykonania), filtr, pojemność, rykoszet, martwe żyły, sekcje kadłuba, XP.
  Wynik „Szkolenia” (ziarna 1–2): osłony Walako przestrajają się na 3600 Hz po wykryciu B-winga (Hz 3000) w 42.–47. s,
  zanim ten dotrze — nic nie przebija. Celność i ogień broni okrętu jeszcze nieliczone (brak liczb θ broni).
- **Uwaga do W9:** AI kluczy wykonuje ok. 60 akcji na klucz w 15 minut → 30–40 XP na pilota. Potrzebny limit
  (proponuję 1 akcja z XP na cykl na klucz, czyli maks. 75 akcji na godzinę → 37,5 XP standardowemu).
- `api/bitwa.php` — port rdzenia do PHP (losowość, zegar, przygotowanie rozkazów z `rozkazy_profile`, stopnie,
  filtr, żyły jako mapa bitowa, Hz skuteczne) i akcje MG `bitwa_test` (porównanie z wektorami z Pythona)
  oraz `bitwa_scenariusze`. Zarejestrowany w `api/index.php`.

**Stan E1, część 2 — symulacje działają w grze (po migracji 070):**
- `api/bitwa_silnik.php` — pętla tików przepisana 1:1 z `silnik.py`; test `bitwa_test` porównuje dwa całe przebiegi
  scenariusza (200 i 300 tików) z wektorami z Pythona (pozycje z tolerancją 1e-6 na różnice bibliotek sin/cos).
- `api/bitwa.php` — tworzenie symulacji ze scenariusza (okręt z mapy, piloci z `mapa_piloci` w kluczach graczy,
  ranga klucza = prowadzący), doliczanie tików pod blokadą wiersza bitwy (maks. 600 na zapytanie), rozkazy z `uid`
  (figury kluczy, Hz osłon ręcznie / auto), start, pauza (MG i prowadzący), zakończenie z XP, zatwierdzenie XP (MG).
- Aplikacja Symulacje: lista symulacji, „Utwórz symulację” przy scenariuszu, podgląd co 1 s (radar 10/30/60 km,
  kadłub i osłony, klucze z lampą stanu i odliczaniem startu, przygotowanie rozkazu, zdarzenia), XP po zakończeniu.
- Migracja 070: pozycje, kąty i integralność sekcji jako DOUBLE (zaokrąglanie przy zapisie zmieniałoby przebieg
  zależnie od częstości odświeżania), ranga / ładunek / akcje / AI klucza, tryb auto osłon, kanał rozkazu.
- Taśmociąg i rozkazy klucza (25.11.2026, migracja 072): z jednego okrętu najwyżej 1 klucz na minutę (kolejka
  `bitwy_jednostki.tasma_tik`); klucze graczy domyślnie „ochrona” — krążą 3 km od okrętu matki; rozkazy klucza (ochrona,
  patrol 8 km, atak, skan 10 km, powrót z lądowaniem, start z hangaru) mają własny kanał i czas wykonania, osobno od figur.
- Ograniczenia: myśliwce strzelają tylko w okręty (walka klucz na klucz i broń okrętu — W17 i liczby θ), więc TIE
  w „Szkoleniu” nie mają celu i lecą prosto; AI nabija dużo akcji (W9).

**Stan (25.11.2026): E0 gotowe w repo, niezaimportowane.**
- [`api/migracje/068_bitwy_symulacje.sql`](../../api/migracje/068_bitwy_symulacje.sql) — struktura: słowniki mechaniki,
  profile klas z sekcjami kadłuba, typy maszyn, `rozkazy_profile`, scenariusze, stan bitwy, `mapa_bitwy` (rodzaj,
  scenariusz, zegar), `mapa_piloci.xp` DECIMAL(10,1), `mapa_obiekty.profil_klasy`. Wymaga 010 i 067.
- [`api/migracje/069_mostek_dane.sql`](../../api/migracje/069_mostek_dane.sql) — dane, generowane przez `dane_sql.py`
  z `profile_klas.py` i `dane/`: stałe, 3 profile, 8 typów maszyn (W16), 36 rozkazów z czasem wykonania (W1, W18),
  scenariusze „Atak na Walako” i „Szkolenie: klucze rebelii nad Walako” (bez MC80).
- `api/schema.sql` nieprzebudowany: w katalogu migracji są dwa pliki 060 (`zbuduj.js` przerywa na duplikacie numeru),
  a plik ma niezapisane przebudowanie 001–063 spoza tej pracy.
- PHP kluczy rzutuje `xp` na int — po zmianie na DECIMAL działa bez zmian (połówki widać dopiero po zmianie
  wyświetlania w E3).


| Etap | Zakres | Gotowe, gdy |
|---|---|---|
| E0 | migracja 068 (rozdz. 3), `profile_klas` z generatora, `mapa_piloci.xp` DECIMAL | migracja wchodzi na czystą bazę i na produkcyjną |
| E1 | `api/bitwa_silnik.php`: zegar bitwy, tiki, rozkazy z czasem wykonania, ruch, osłony (żyły, filtr, C, rykoszet), baterie; test zgodności z `mechanika.py` | symulacja ISD vs wirtualny cel liczy się poprawnie bez konsol |
| E2 | `api/bitwa.php` (utwórz, stan, rozkaz, pauza, zakończ) + konsola **Osłony** (decyzja 15) i podgląd Reaktorów | jeden gracz broni ISD w symulacji |
| E3 | klucze w bitwie: ekrany Klucz i Eskadra (telefon), lampa z odliczaniem, figury, XP | piloci dostają XP po symulacji, stan świata bez zmian |
| E4 | AI przeciwnika (Radus / Ackbar / Calrissian), pozostałe konsole, migawki i cofanie | pełny scenariusz „Atak na Walako” |
| E5 | bitwy rzeczywiste na mapie (W21–W24) | skutki trwałe zapisane w świecie |

Pierwsze do decyzji, bo blokują E0–E1: **W1** (czasy wykonania), **W7/W12** (akcja i typ XP), **W13** (kadłub), **W14**
(2D) oraz M1–M10 dla MC80, jeśli przeciwnikiem w pierwszej symulacji ma być MC80.

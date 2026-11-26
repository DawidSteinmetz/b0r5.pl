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
   (ekrany Klucz i Eskadra, rozdz. 1a).

## 1a. Ekrany telefonu [U, 25.11.2026]

Telefon obsługuje tylko klucze myśliwców. Siatka **5×9** kwadratowych komórek, pionowo; symetria względem kolumny 3;
bez szarych suwaków zakresu (zabrałyby szerokość ekranu); kolory jak na pulpitach. W danych: `FORMATY = {nazwa: 'telefon'}`
w pliku układów klasy. Wydruk: A4 pionowo, komórka 28 mm.

- **Klucz** — zbliżenie pojedynczego klucza, wszystko na jednym ekranie: nagłówek ze stanem, ekran jak w radarze
  (klucz zawsze w środku, okręgi 2 / 10 / 30 km), po bokach skręty, pod spodem figury (manewry) na kursie, moc i rozkazy.
  Wejście: kliknięcie lampki „Klucz N” na pulpicie Myśliwce (na komputerze — nakładka nad pulpitem, powrót do pulpitu)
  albo w ekranie Eskadra. Każdy przycisk figury i rozkazu pokazuje po kliknięciu pasek przygotowania (sekundy do
  działania), potem czas działania i odnowienie (MECHANIKA 1.1); działająca figura wroga — ikona na ekranie.
- **Eskadra** — 3 klucze: ekran ze środkiem eskadry (okręgi 5 / 15 / 40 km), lampki 3 kluczy, szyki i rozkazy.
  Na pulpicie Myśliwce eskadrę tworzy rząd 3 lampek jednej burty (lewa: eskadry 1–3, prawa: 4–6).

Pulpit Myśliwce zostaje bez zmian.

## 1b. Lampa klucza — stan i odliczanie [U zasada, R kolory]

Lampa „Klucz N” (pulpit Myśliwce, Eskadra, nagłówek ekranu Klucz) to wskaźnik dynamiczny: kolor zmienia się ze stanem
klucza, a w hangarze lampa pokazuje **czas do wyjścia z hangaru** (mm:ss, z taśmociągu: 1 klucz/min, poza alarmem
+4 min przygotowania). Odcienie zostają w rodzinie niebieskiej (urządzenie), więc nie mylą się z kolorami poleceń.

| Stan | Lampa | Treść |
|---|---|---|
| w hangarze, przygotowanie | ciemnoniebieska #2a2a80, biały tekst | „start za mm:ss” (odliczanie) |
| gotowy do startu | niebieska #6666ff — **zmiana koloru w chwili, gdy licznik dojdzie do 0**, krótkie mignięcie | „gotowy” |
| w locie | jasnoniebieska #66ccff | liczebność 4/4 … 1/4 |
| straty | jak w locie + czerwona ramka #ff3333 | liczebność, np. 3/4 |
| powrót do hangaru | jasnoniebieska, przerywana ramka | „lądowanie za mm:ss” |
| stracony | szara #999999 | „stracony” (dotknięcie — historia klucza) |

Gracz nie widzi tików (MECHANIKA.md, zasada 4): odliczanie to czas gry w minutach i sekundach.

## 1c. Konsole w grze [U, 25.11.2026]

Konsola w symulacji (aplikacja Symulacje → zakładka z nazwą pulpitu) to ten sam układ co arkusz i wydruk:
`python pulpity_js.py` eksportuje `uklady/` do `js/aplikacje/pulpity_uklady.js`, a `js/aplikacje/konsole.js` rysuje
siatkę układu (13×9; wariant ISD „Walako” — `uklady/isd_i_walako.py` — szerszy i niższy 13×7; telefon 5×9) i podpina elementy pod stan bitwy **po tekście elementu** (zmiana tekstu w układzie = zmiana powiązania).
Element bez mechaniki jest przygaszony (dymek „bez mechaniki”), włączony przełącznik ma ramkę w kolorze akcentu.
Pulpit to dwie siatki o tej samej geometrii: warstwa kontrolek (kolory, ekrany, kliknięcia) i nad nią **warstwa
tekstów** w kroju `var(--czcionka)` — przełącznik alfabetu (łaciński / Aurebesh) zmienia naraz wszystkie napisy pulpitu,
także opisy na ekranach. Każdy pulpit klasy da się otworzyć; bez mechaniki widać sam układ (materiał do nauki).

**Stanowiska** (`bitwy_stanowiska`, panel „STANOWISKA” w przeglądzie symulacji):
- MG przydziela pulpit dowolnemu graczowi — postać spoza pokładu zostaje teleportowana na mostek okrętu
  (ten sam zapis pozycji co przestawienie postaci w panelu MG, pomieszczenie „Mostek główny”);
- kapitan (dowódca floty okrętu) przydziela pulpity graczom obecnym na pokładzie i sam widzi wszystkie pulpity;
- gracz z przydziałem widzi w symulacji tylko swoje pulpity i wydaje rozkazy tylko z nich; bez przydziału czeka.

**Stałe stanowiska i mostek bez symulacji** (26.11.2026, migracja 077, `okrety_stanowiska`): Symulacje → panel
„MOSTEK” → „Podejdź do pulpitów” otwiera pulpity okrętu w spoczynku (bez wroga i zegara; osłony na domyślnych Hz,
hangar pełny, kadłub 100%; rozkazy nie mają skutku). Dowodzący okrętem, kapitan floty i MG widzą każdy pulpit
i pod pulpitem panel „STANOWISKA” — przydział jest **stały**: obowiązuje poza symulacją, kopiuje się do każdej nowej
symulacji okrętu, a jego zmiana przechodzi też na trwające symulacje tego okrętu. Przydział w panelu samej symulacji
zostaje jednorazowy (tylko ta symulacja). Gracz ze stałym stanowiskiem widzi aplikację Symulacje i na mostku tylko
swoje pulpity; w trwającej symulacji jak dotąd — tryb stanowiska po zalogowaniu.

**Obsługa** (26.11.2026): pulpit ma stałe proporcje siatki (kwadratowe komórki) i dopasowuje się do ekranu
szerokością albo wysokością (do dołu okna) — co pierwsze się skończy. Każdy element coś robi: bez mechaniki w symulacji ma stan lokalny konsoli (wzmocnij, synchronizacja,
urządzenia, tryby i filtry — przełączniki świecące w kolorze typu; pozostałe polecenia — przyciski sprężynowe; żółte
suwaki — uchwyt w miejscu dotknięcia; szare — dwa uchwyty zakresu), dymek mówi wtedy „bez wpływu na symulację”.
Wciśnięcie: przycisk i napis zapadają się, sprężynowy wraca, przełącznik zostaje lekko wciśnięty. Szary suwak osłon
to skala 0…3600 Hz (3600 u góry, 1800 w połowie): dotknięcie przesuwa bliższy uchwyt (maks. / min.) na najbliższy stopień
natywny (rozkaz `zakres`). Wiązki radaru i sensorów: pełny obrót w 6 s. Pauza i koniec symulacji zatrzymują wiązki
i ruch na ekranach. Ekran ze środkiem w ruchu (klucz, eskadra, okręt) przesuwa otoczenie płynnie, bez skoku co odczyt.
Reaktor zapala porcje równomiernie po całej siatce 60×60.

**Siatki żył** (26.11.2026): każdy kwadrat urządzenia (generatory osłon, baterie, reaktory i generator mocy — 60×60,
przedni reaktor — prostokąt 60×20, 1200 żył) to same lampki żył na całej powierzchni, bez opisów i przycisków. Hz
urządzenia ustawia suwak z jednym uchwytem, który przeskakuje tylko na stopnie natywne (połowa — N/2, 1/3 — N/3,
1/4 — N/4 …; 75% przy 3600 żyłach nie istnieje): osłony i baterie — szare suwaki po bokach (osłona: przestrojenie
ręczne 12 s, wyłącza tryb auto); Reaktory — uzbrojenie lewym, generator mocy prawym szarym suwakiem, reaktor główny
częstotliwością bazową, zapasowy i przedni swoimi suwakami mocy. Reaktor główny barwi świecące żyły udziałem odbiorców.

**Sylwetki** na ekranach według rzutów z góry: ISD (klin, rufa z dwóch trójkątów do bloku silników, nadbudówka
z kopułami osłon), MC80 (ostry dziób, wąski kadłub, skrzydła z wklęsłą krawędzią natarcia, odchylone do rufy);
własny okręt zawsze w środku, w skali, z minimalnym rozmiarem czytelnym na ekranie.

- **Radar** (25.11.2026) — do **12 km obraz na bieżąco** (kontakty płyną, ogony torów), **12–60 km echo**: kontakt
  widać w pozycji z chwili, gdy nad jego namiarem przeszła wiązka, i odświeża się dopiero przy następnym przejściu;
  dalej radar nie sięga. Skale 12 / 30 / 60 km, granice stref jako przerywane okręgi. Sektory, filtry, listy zagrożeń
  burt (z tego, co wie radar), alarm zbliżenia, oznacz / śledź, kasuj ślady, zamroź obraz; „wyślij namiar zagrożenia
  do osłon” używa Hz zmierzonych wcześniej przez sensory.
- **Sensory** — skan **od 60 km wzwyż** do zasięgu ustawionego mocą sensorów (1000 / 3000 / 10 000 / 30 000 km;
  koszt k · (zasięg / 1000 km)^1,618 porcji, k = 0,245: 0,25 / 1,4 / 10 / 60 porcji — widać go też na Reaktorach),
  odświeżany własną wiązką (obrót 20 s). Wnętrze 60 km to strefa radaru (zaciemnione). **Zmarszczki nadprzestrzeni**:
  jednostka przed wyjściem ze skoku (silnik: stan „oczekuje” do chwili wejścia) widoczna dobrze, z czasem do wyjścia
  i elipsą niepewności malejącą z czasem obserwacji; klucze z zewnątrz — słabo, tylko w połowie zasięgu. Hz celów
  mierzone w skanie zostają w pamięci konsoli (widmo Hz, „wyślij Hz do osłon”, Hz na radarze po synchronizacji).
- **Osłony** — ekrany żył obu generatorów (lampka = żyła: świeci przy n mod (N/f) = 0, martwa ciemnoczerwona), Hz
  osiągane / skuteczne, obciążenie, martwe żyły; pod ekranem ◀ Hz ▶, USTAW (przestrojenie ręczne 12 s) i AUTO; szare
  suwaki = zakres trybu automatycznego (górna połowa — maksimum, dolna — minimum); „wyrównaj osłony” — oba na auto.
- **Reaktory** — ekran 100 porcji z udziałem osłon (pobór G · Hz skuteczne · P_żyły / P_r) i przydział mocy dla osłon.
- **Myśliwce** — 18 lampek kluczy (stan, odliczanie startu); pierwsze dotknięcie wybiera klucz, drugie otwiera
  **zbliżenie** (ekran Klucz) z powrotem do pulpitu; ekran sytuacji kluczy, taśmociągi pokładów (kolejka i ETA),
  start i powrót z każdego pokładu, rozkaz dla wybranego klucza, START.
- **Klucz** (telefon) — nagłówek z lampą i stanem, ekran z kluczem w środku (2 / 10 / 30 km), wszystkie figury
  i rozkazy, Hz klucza; dotknięcie nagłówka — następny klucz. **Eskadra** — 3 klucze, ekran ze środkiem eskadry,
  lampki ze zbliżeniem, rozkazy dla całej eskadry.
- **Nawigacja** — ekran 250 / 500 / 1000 km, wybór celu, odczyty kursu, prędkości, odległości i ETA (sterowanie ruchem
  czeka na model ruchu okrętu w silniku). **Kontrola ognia** — ekran celu (ogień okrętu — kolejny etap).
- **Powiększanie** każdego ekranu sytuacyjnego: przyciski + i − w rogu (×2) oraz kółko myszy (×1,4), od ×1/16
  do ×10 000 (przy skali 50 km ok. 5 m na promień ekranu); opisy okręgów poniżej 1 km w metrach; okręt w skali
  większy od ekranu jest półprzezroczysty, żeby nie zasłaniał kontaktów przy burcie.
- **Ekrany na żywo**: wiązka omiatania obraca się płynnie (animacja SVG, faza z zegara — odświeżanie stanu co 1 s
  jej nie przerywa), za nią rozmyta smuga poświaty (60°); kontakt rozbłyskuje, gdy wiązka nad nim przechodzi,
  i przygasa do następnego obrotu; między odczytami kontakty przesuwają się płynnie po kursie; tory to rozmyte,
  zanikające ogony (ostatnie 16 odczytów).
- **Wygląd** (26.11.2026, wzór `assets/wnetrza/isd-konsola-*.svg`): metalowa płyta z nitami, ciemne przyciski
  z ramką i lampką w kolorze typu (zasady z rozdz. 1 bez zmian), suwaki zakresu z prowadnicą i podziałką, ekrany
  w ramkach. Siatki żył 60×60 to lampki z minimalnymi przerwami: świecąca żyła od turkusu (zimna) do czerwieni
  (gorąca), ciemniejsza przy przeciążeniu generatora; martwa — ciemnoczerwona (prototyp konsoli osłon użytkownika).
  Ekrany generatorów osłon ISD powiększone do 4×4 kratek.
- **Klucz, Eskadra** (myśliwce): bez wiązki radaru. Kontakty po łuku (prędkość kątowa z dwóch odczytów), przy
  dużym powiększeniu ikona typu maszyny (TIE, TIE/in, bombowiec TIE, X-wing, Y-wing, A-wing, B-wing, Lambda),
  przy bardzo dużym — 4 maszyny klucza w szyku.
- **Turbolasery** — baterie z rozstawienia na kadłubie: cel w łuku i zasięgu każdej, lampki baterii (Hz).
  **Silniki** i **Nawigacja** — ster okrętu (rozkaz „ster”: kurs i prędkość zadane, 3 s): ciąg główny, cała naprzód,
  pełne hamowanie, zwroty, kurs na cel / przechwycenia / odejścia. **Kontrola ognia** — wskazanie celu bateriom
  (rozkaz „cel_ognia”, 2 s), stan baterii i jonów. **Grawitacja** — odczyty (blokada Anchor/Lock nie działa w bitwie).
- **Stanowiska pilotów**: MG albo kapitan przydziela graczowi „Klucz N” (jeden klucz) albo „Eskadra N” (klucze
  3N−2…3N) — pionowy pulpit na telefon. Gracz z przydziałem po zalogowaniu widzi tylko swój pulpit (bez paska,
  doku, zegara i ikon pulpitu); po końcu symulacji wraca na pulpit.
- Przygaszone elementy pulpitów nie mają jeszcze mechaniki.
- Pomiar Hz jest na razie natychmiastowy w zasięgu 200 km (MECHANIKA 7: próbki, poziomy wiedzy, tryb bierny/aktywny —
  kolejny etap).

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

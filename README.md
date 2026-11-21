# HOLOLINK — datapad dla b0r5.pl

System operacyjny taniego prywatnego datapada ze świata Gwiezdnych Wojen, z dostępem do holonetu.
Działa w przeglądarce na tabletach, w trybie pełnoekranowym. Zastępuje dawne osobne moduły
(hub, kalendarz, dekoder, portal frakcji) jednym spójnym systemem.

Projekt stoi w korzeniu domeny **b0r5.pl**. Foldery `/_/` i `/__/` na serwerze to **inny projekt**
(repo `b0r5.pl`). Przy wgrywaniu nie wolno ich ruszać.

## Zasady immersji (najważniejsze)

1. **Zero informacji spoza gry w datapadzie.** Żadnych numerów wersji strony, nazw systemów RPG,
   „MG”, „Google”, „zaloguj się przez…”, linków do Facebooka itp. Wszystko, łącznie z komunikatami
   błędów, mówi językiem świata („Węzeł holonetu nie odpowiada”, a nie „Błąd 500”).
2. **Fabuła alfabetu:** fabryczny system mówi tylko w Aurebeshu. Hakerzy z Zephyrii wgrali
   nieautoryzowany moduł **ZPH**, który przepisuje wszystko na alfabet łaciński. Stąd różowe
   (`--zph`) elementy: przełącznik na pasku, wpisy w rozruchu, sekcja w Ustawieniach.
3. **Przełączanie jest pełne:** zmiana alfabetu przełącza krój pisma całego ekranu, także pól
   formularzy i treści dokumentów. Tekst w kodzie zawsze piszemy po polsku, alfabetem łacińskim.
   Aurebesh to tylko czcionka.
4. **Wszystko, co meta, idzie do panelu MG** (`/gm/`), który jest poza światem gry.

## Struktura

```
index.html               powłoka datapada (jedna strona)
manifest.webmanifest     instalacja na tablecie, pełny ekran, poziomo
sw.js                    pliki systemu w pamięci tabletu (działa przy słabym zasięgu)
css/system.css           cały wygląd, kolory w zmiennych na :root
js/system.js             start → rozruch → blokada → pulpit, menedżer aplikacji, alfabet
js/rdzen/narzedzia.js    h() do budowania elementów, czas i daty GST, pamięć urządzenia
js/rdzen/api.js          łączność z api/ (błędy w języku świata)
js/rdzen/urzadzenie.js   producent, model i nazwa systemu (zmieniajcie tutaj)
js/aplikacje/*.js        aplikacje: holonet, dekoder, harmonogram, frakcja, ustawienia
api/index.php            jedno API dla wszystkiego (PHP 8.1+, PDO i SQL)
api/bank.php             logika banku (przelewy, odbiorcy, stałe dochody)
api/firmy.php            firmy (profile), przewozy i wspólny katalog towarów i planet
api/komorka.php          profil komórki: misje
api/agencja.php          profil agencji (ISB): wypłaty dla agentów, teczki i meldunki
api/handel.php           ceny na planetach, kursy handlowe, automaty
api/kanaly.php           czarny rynek informacji: wtyczki, kanały szyfrowane, słowniki
api/sceny.php            stan faktyczny postaci (scena w tle datapada)
api/gielda.php           giełda CDEX: instrumenty, skrypt kursów, portfele, zlecenia
api/wydarzenia.php       wydarzenia świata: skutki dla cen, Holonetu i kanałów szyfrowanych
gm/wydarzenia.js         komponent wydarzeń osadzany w kilku zakładkach panelu MG
api/karty.php            karta postaci: statystyki, reputacja, status, pozycja, notatnik
api/ruch.php             przemieszczanie, przygotowanie statku, gotówka (bankomat, sklep)
js/rdzen/scena.js        animacje tła: gwiazdy, skok, tunel, wyjście, planety
api/schema.sql           pełny schemat dla nowej, pustej bazy — plik WYNIKOWY, nie edytować
api/migracje/            źródło schematu: migracje 001… (001 = podstawa), addytywne
api/floty.php            dowodzenie flotami, podział grup i automatyczne skoki
api/mapa_seed.sql        publiczna mapa: planety i szlaki (wbudowana w api/schema.sql po 001)
api/dane/                źródło importu z wcześniejszych wersji (poza gitem)
gm/                      panel MG: konta, dokumenty, holonet, frakcje, harmonogram
assets/czcionki/         czcionka Aurebesh
narzedzia/czcionka/      generator czcionki Aurebesh
narzedzia/sql/           budowa api/schema.sql z migracji i raport migracji JSON → SQL
```

Bez frameworków i bez budowania. Pliki trafiają na serwer w takiej postaci, w jakiej są w repo.

## Czas świata i resynchronizacja

- Czas w grze płynie **1:1** z rzeczywistym. Jedno źródło czasu dla mechanik: `api/czas.php`
  (`czas_teraz()`, `czas_trwania(minuty_swiata)`). Globalnego „tempa” już nie ma.
- Procesy w czasie mają swój koniec liczony z reguł gry: **skok floty** (`mapa_rozkazy_skokow`),
  **podróż postaci** i **obsługa naziemna statku**. Koniec rozlicza się leniwie, przy pierwszym
  odczycie po terminie (bez crona).
- **Resynchronizacja (ingerencja mocy):** panel MG → **Czas** pokazuje procesy w toku i pozwala
  ustawić „zakończ za N minut” (także 0 = teraz albo dłużej niż wynika z reguł). Gracze widzą tylko
  skutek, gra niczego nie tłumaczy — np. Walako ocenia skok na 3 h, a statek wychodzi po 5 min.
  Każda ingerencja trafia do dziennika `resynchronizacje` (widzi go tylko MG).
- Skok floty: wszystkie jednostki rozkazu dostają nowy termin w tej samej proporcji co wcześniej,
  a pozycja na mapie przesuwa się zgodnie z nowym postępem.
- **Skok statku postaci** (Nawigacja): czas i paliwo z odległości na mapie galaktyki i napędu
  statku (pola w edytorze firmy: `hipernaped` — klasa, domyślnie 2; `predkosc_pc_h` — domyślnie
  1000; `paliwo_na_pc` — % zbiornika na parsek, domyślnie 0,005; minimum 5 minut). Statek sam
  wychodzi z nadprzestrzeni w terminie. Planeta bez współrzędnych mapy: 60 minut i 20% paliwa.
  „Zakończ skok” w Stanie postaci działa jak resynchronizacja „teraz”.
- **Radar:** gdy statek albo flota skacze przy planecie, każda jednostka mapy (statek/jednostka)
  przy tej planecie zapisuje wykrycie w `mapa_wykrycia_skokow`: kierunek, szacowany punkt wyjścia
  z marginesem błędu (5–20% odległości) i szacowany czas przylotu (±25%). Wykrycie widzą ci, którzy
  widzą tę jednostkę (MG wszystkie) — na mapie jako pomarańczowa linia z okręgiem niepewności,
  przez 24 h po szacowanym przylocie. Statek gracza radar opisuje polem `transponder` statku
  (puste = „Niezidentyfikowany statek”). Resynchronizacja nie zmienia wykrycia.
- **Radar kontaktów i transpondery** (migracja 043, `api/radar.php`): okręty gracza (na pokładzie,
  z dostępem albo dowodzone floty) widzą jednostki w zasięgu — `radar_zasieg_pc` albo z długości kadłuba
  (≥900 m ok. 3 pc, ≥250 m ok. 1,5 pc, mniejsze 0,5 pc). Kolor z transpondera: przyjazny zielony, neutralny
  szary, wrogi albo poszukiwany (baza sygnatur MG w „Bitwy / radar”) czerwony. Do tego niezarejestrowany ruch
  cywilny przy układach w zasięgu, gęsty w Jądrze i pusty w sektorze Hune (ujemne id, liczony na serwerze).
  Nawigacja → Statek: udostępnienie pozycji graczowi (1 h, 24 h, do odwołania) i fałszywy transponder —
  tylko przemytnik albo łowca nagród z Krętactwem/Komputerami 3+, 5000 kr, hipernapęd −10%, działa po 30 min.
  Analiza sygnatury silnika i osłon (karta kontaktu na mapie): 6 h, tylko okręty z komputerem 4+ albo ≥900 m,
  cel musi zostać w zasięgu; ujawnia prawdziwą tożsamość i list gończy.
- **Trasy NPC** (migracja 044, tabela `npc_trasy`, generowana skryptem): ok. 6000 jednostek kursuje między
  dwoma portami w stałym cyklu — postój, lot do punktu skoku w stronę celu, nadprzestrzeń (niewidoczna),
  dolot do drugiego portu. Fazę liczy serwer z czasu (`radar_npc_faza`), więc każda jest w innym miejscu
  trasy. Karta kontaktu: trasa, deklarowany ładunek, co robi teraz; jednostki bez transpondera (rejestr 0)
  ujawnia analiza sygnatury. Wykrycia skoków własnych i sojuszniczych okrętów są ukryte, opis — po najechaniu.
- **Pokład statku** (Nawigacja, jedna zakładka z nazwą miejsca): start, zejście, promy, stocznia, radar oraz
  maszynownia (odczyt podzespołów; „Pogrzeb” — naprawa polowa z testem Mechaniki, najwyżej do 80%, raz na 12 h)
  i ładownia (rzeczy z ekwipunku odkładane na statek, magazyn `ladownie` w datapad_dane).
- **Lokacje bez siatki** (np. import Kuat ze współrzędnymi NULL): `ruch_wspolrzedne_zastepcze` nadaje im stałe
  współrzędne z hierarchii (regiony setki km od środka, miasta dziesiątki, dzielnice kilometry), więc da się
  chodzić i jeździć taksówką. Gałąź orbity zostaje bez współrzędnych.
- **Statki na lądowisku** mapa rysuje na tarczy planety (nie na orbicie), karta pokazuje lądowisko.
- **Okno promu Lambda:** klasa albo nazwa z „Lambda” daje widok przez kokpit promu (`okno: 'lambda'`).
- **Wejście na pokład:** na lądowisku Nawigacja pokazuje stojące tam statki z prawem wejścia (właściciel,
  kapitan, dowódca floty okrętu macierzystego promu, dostęp) oraz awaryjny powrót na ostatni statek.
- **Awanse** (Sieć frakcji → Awanse): stopień nie rośnie z PD. MG nadaje dowolny; przełożony z tej samej
  frakcji awansuje o jeden stopień, najwyżej do o jeden niższego niż własny. Awans zmienia rangę konta
  i poziom kariery floty (Kadet … Kapitan) na karcie EotE.
- **Daty gry:** czasy przylotów (ETA) i manewrów datapad i panel MG pokazują jako `DD|MM|RR GG:MM`,
  gdzie rok gry = rok realny − 2010 (np. `18|11|16 17:27`); funkcja `czasGry()` w `js/rdzen/narzedzia.js`.
- Wymaga migracji 013, 014 i 015 z `api/migracje/` (import w phpMyAdmin, w tej kolejności).

## Kurs flot i manewry

- Każda jednostka mapy ma **kurs** (`mapa_obiekty.kurs_deg`): 0° = wschód, 90° = północ, przeciwnie
  do ruchu wskazówek zegara. Strzałka floty na mapie wskazuje kurs.
- **Miejsce na orbicie** (`mapa_obiekty.orbita_deg`) to osobny kąt: po której stronie planety stoi
  jednostka (dzień/noc pod okrętem, czy wisi między planetą a księżycem). Jednostki jednej floty
  ustawiają się wachlarzem wokół niego. Strzałka na mapie pokazuje kurs dziobu.
- **Manewr:** obrót dziobu trwa zależnie od
  różnicy kątów i tempa **Obrót °/min** w profilu napędu (`mapa_naped_jednostki.obrot_deg_na_min`;
  domyślnie ISD 30, Arquitens 60, reszta 90). Flota manewruje tempem najwolniejszej jednostki.
  Dowódca ustawia kurs w panelu „Floty / nawigacja” na mapie; MG w zakładce Floty ustawia go od razu.
- **Przed skokiem** flota najpierw obraca się na kurs skoku (strzałką w stronę celu), dopiero potem
  wchodzi w nadprzestrzeń (`mapa_rozkazy_skokow.wejscie_utc`). ETA = manewr + lot, więc cel
  „po drugiej stronie” przesuwa ETA. Radar widzi skok dopiero w chwili wejścia w nadprzestrzeń.
- Po wyjściu z nadprzestrzeni przy planecie jednostka staje na orbicie po stronie, z której
  przyleciała, dziobem ku planecie (`przylot_utc` = chwila wyjścia); w pustej przestrzeni zostaje
  z dziobem w kierunku lotu. MG w zakładce Floty ustawia od razu kurs, orbitę i rozbicie.

## Skok z karty planety, komputer nawigacyjny i dolot

- **Karta planety → „Nawigacja floty”** (dla dowódcy floty): wybór floty, potem **skok do układu**
  z wariantem: bliska / średnia / daleka orbita albo krawędź układu. „Oblicz skok” pokazuje plan
  (obliczenia, obrót, lot, wejście w nadprzestrzeń, ETA w dacie gry, paliwo), „Wykonaj skok” wydaje
  rozkaz. Gdy flota już jest w układzie: **dolot** na inną orbitę (napęd podświetlny).
- **Komputer nawigacyjny** (`mapa_naped_jednostki.komputer_klasa` 1–5, `komputer_stan`), liczy się
  komputer okrętu flagowego. Klasa zmniejsza rozrzut wyjścia (5: ÷3) i czas obliczeń (5: 30 s).
  ISD = 5, Arquitens = 4, reszta = 3. **Przejęty / brak:** automatu nie ma, mostek liczy sam —
  tylko ręczne współrzędne, 20 min obliczeń, rozrzut ×2,5 (skok może trafić obok układu).
  **Zhakowany:** automat działa, ale po cichu daje rozrzut ×6; gracz tego nie widzi.
- **Wariant orbity** (`mapa_obiekty.podejscie`) decyduje o widoku z mostka: bliska → duża planeta,
  średnia → średnia, daleka → mała, krawędź układu → planeta jako punkt. Skok na współrzędne albo
  do jednostki kończy się na dalekiej orbicie.
- **Dolot** (`podlot_*`): krawędź ↔ daleka 60 min, daleka ↔ średnia 15 min, średnia ↔ bliska 8 min,
  mnożone przez zwrotność (ISD ×1,5 ≈ 2 h z krawędzi na bliską orbitę, małe okręty ×0,5). Dolot jest
  procesem w zakładce Czas (resynchronizacja).
- **Mapa:** przy oddaleniu flota na orbicie to jedna strzałka z liczbą okrętów (po przybliżeniu
  wachlarz); panel dowodzenia po lewej z wyborem floty, karta planety po prawej, radar u góry.
- Wymaga migracji `017_komputer_nawigacyjny_i_dolot.sql`.

## Mapa: zoom, układy, strony, tarcza kursu

- **Zoom** do 3000 px na parsek. Od 3 px/pc flota na orbicie rozkłada się na pojedyncze okręty.
  Od 60 px/pc kropka planety staje się **układem**: słońce w miejscu kropki (współrzędne mapy to
  słońce), planeta na swojej orbicie z dzienną i nocną stroną, księżyce i okręty wokół planety.
  Układ jest schematyczny i rośnie płynnie z przybliżeniem.
- **Kolory stron** z punktu widzenia gracza: sojusznik zielony, wróg czerwony, neutralny biały
  (`mapa_strony()` w `api/ruch.php`). Frakcja jednostki: kolumna `mapa_obiekty.frakcja` (MG,
  zakładka Floty), inaczej z pola `wlasciciel` (Imperium/ISB/Moff, Rebelia/Sojusz, Syndykat/Hutt),
  inaczej frakcja konta dowódcy floty. Relacje: ta sama frakcja = sojusznik, Imperium ↔ Rebelia =
  wróg (`MAPA_WROGOWIE`), reszta neutralna. MG widzi kolory ustawione ręcznie.
- **Tarcza kursu** w panelu dowodzenia: przeciągnij strzałkę albo użyj ±15°/±45°, „Dziobem na
  planetę”, „Rufą do planety”; podgląd kąta i czasu obrotu, „Wykonaj obrót” dla całej floty albo
  zaznaczonych okrętów.
- **Migracje** od 013 zaczynają się od `USE hololink;` i nie używają PREPARE (phpMyAdmin gubił przy
  imporcie wybraną bazę). Importuj raz; ponowny import zatrzyma się na „Duplicate column”.
  `narzedzia/sql/zbuduj.js` pomija linie `USE` w pełnym schemacie. Wymagana migracja 018.

## Rodzaje gwiazd i wyczyn „Fotograf gwiazd”

- **Typ gwiazdy** układu (`ASTRO_GWIAZDY` w `api/astro.php`) losowany z nazwy z udziałami zbliżonymi
  do prawdziwych: czerwony karzeł M 40%, pomarańczowa K 25%, żółta G 15%, żółtobiała F 8%, biała A 5%,
  błękitna B 3%, biały karzeł 2%, błękitny olbrzym O 1%, czerwony olbrzym 1%. MG nadpisuje typ
  w `planety_cykle.gwiazda_typ`; Zephyria = G. Liczba słońc z `mapa_planety.slonca` (do 3).
- Typ zmienia kolor, wielkość tarczy i łunę słońca w oknie mostka, w widokach układu (mapa, karta
  planety), kolor światła na niebie planety oraz kolor i jasność gwiazd sąsiednich układów
  (jasny olbrzym z daleka świeci jak bliższa gwiazda).
- **Aparat:** przycisk „Zdjęcie” w lewym dolnym rogu pulpitu, gdy słońce układu jest w kadrze
  (na mostku w polu widzenia okna, na planecie w dzień). Serwer sprawdza układ i widoczność słońca,
  zapisuje miniaturę JPEG (`zdjecia_gwiazd`). Każdy układ liczy się dla konta raz.
- **Album gwiazd** w Karcie postaci: postęp (sfotografowane / układy widoczne dla konta), tytuł
  według progów (`wyczyny_progi`: 1% Obserwator … 100%), zdjęcia z nazwą układu, typem gwiazdy
  i datą gry. MG: zakładka **Wyczyny** (postępy graczy, edycja progów). Migracja 021.

## Liczniki pokładowe na pulpicie

- Na pokładzie statku albo okrętu pulpit pokazuje dyskretny pasek w prawym dolnym rogu
  (`liczniki_postaci()` w `api/sceny.php`, odświeżany ze sceną co 6 s, odliczanie co sekundę):
  odliczanie bieżącego procesu (obliczenia skoku, obrót, wyjście z nadprzestrzeni, dolot, obsługa
  naziemna), paliwo, obciążenie reaktora (postój ok. 15%, obrót 40%, dolot 55%, skok 70–95%),
  osłony i kadłub. Statki firm graczy: odliczanie i paliwo.
- Wyjście z nadprzestrzeni liczy się do **ETA z planu** (`eta_plan_utc`, szacunek komputera w chwili
  rozkazu) — resynchronizacja zmienia tylko rzeczywiste `eta_utc`, więc licznik jej nie zdradza.
- Kolory: pomarańczowy przy paliwie ≤ 15%, osłonach ≤ 25%, kadłubie ≤ 40%, reaktorze ≥ 85%;
  czerwony przy paliwie ≤ 5%, osłonach ≤ 10%, kadłubie ≤ 20%, reaktorze ≥ 95%.
- Osłony i kadłub ustawia MG w profilu napędu jednostki (migracja 019).
- **Tylko na własnym okręcie:** liczniki widzi kapitan — dowódca floty okrętu (NPC: zgody daje MG),
  na statku firmy właściciel albo menedżer. **Gość** na pokładzie, nawet wyższy stopniem, dostaje je
  tylko za zgodą kapitana (tabela `wskazniki_zgody`, migracja 020); bez zgody serwer ich nie wysyła.
  Zgody: panel dowodzenia na mapie, Nawigacja na pokładzie statku firmy, MG w zakładce Floty.
  Zgoda działa, gdy gość jest na pokładzie tego okrętu, i obowiązuje do cofnięcia.

## Lądowanie, start i Kom'rk „Tracyn”

- Małe jednostki z SQL (`mapa_naped_jednostki.moze_ladowac`: Kom'rk, Gozanti) z **bliskiej orbity**
  lądują na lądowisku planety (karta planety → Nawigacja floty → „Ląduj”; lokalizacje typu
  ladowisko albo hangar/port/platforma) i stoją z `mapa_obiekty.ladowisko_id`. **Start** — wznoszenie
  ok. 5 min (scena „start” u załogi), potem bliska orbita. Na lądowisku nie ma skoku, obrotu ani dolotu.
  ISD nigdy nie ląduje (chyba że rozbity).
- **Kokpit Kom'rka** (`okno: 'komrk'`): kanciasta owiewka podzielona wspornikiem, pod nią konsola;
  w pionie owiewka na całą szerokość. Na lądowisku za szybą powierzchnia planety (dzień/noc).
  Na mapie Kom'rk ma własną sylwetkę (znacznik `komrk`), obróconą według kursu.
- **Tracyn** (migracja 024): Kom'rk Venki Tary (konto `Tara`, Syndykat) w jednoosobowej flocie
  „Tracyn” z dowódcą Tara, na lądowisku „Port kosmiczny Nar Shaddaa”, Venka na pokładzie.
- **Pulpit:** liczniki pokładowe i przycisk „Zdjęcie” są kafelkami w siatce aplikacji (tej samej
  wielkości co ikony), więc nie nachodzą na ikony na telefonie.

## Start, punkt skoku i lokalizator prywatnych statków

- **Start z lądowiska** ustawia miejsce na orbicie według lokalnej godziny lądowiska (kąt godzinny
  z cyklu planety): w południe po stronie słońca, o północy po stronie od słońca; dziób na zewnątrz.
- **Lot do punktu skoku** (poza studnią grawitacyjną, 150 000 km od planety): przed wejściem
  w nadprzestrzeń okręt leci po orbicie na stronę zwróconą ku celowi (łuk z prędkością ok. 1/15
  podświetlnej), potem prosto do punktu skoku. Prędkość podświetlna w profilu napędu
  (`predkosc_podswietlna_kms`: ISD 200, Arquitens 350, Gozanti 300, Kom'rk 600). Średnia orbita = 4,
  daleka = 15 promieni planety; z krawędzi układu bez tego etapu. Plan skoku i liczniki pokazują
  etap „do punktu skoku”. Przykład Kom'rka (planeta jak Ziemia): start o 00:00 — ok. 9 min z wznoszeniem,
  o 06:00 — ok. 13,5 min, o 12:00 — ok. 18 min.
- **Lokalizator** prywatnych statków (`mapa_obiekty.wlasciciel_login`, `lokalizator_zasieg_pc`,
  `lokalizator_aktywny`): Nawigacja właściciela → „Twoje statki”: pozycja w zasięgu (pc od postaci),
  inaczej „poza zasięgiem” / „lokalizator nie odpowiada” z ostatnią znaną pozycją. Na lądowisku tej
  samej planety: trasa (pieszo/taksówka) i „Wejdź na pokład”; na pokładzie: „Zejdź z pokładu” i Start.
  MG ustawia właściciela, zasięg i wyłącza lokalizator (np. złodziej) w zakładce Floty.
  Tracyn: właściciel Tara, zasięg 500 pc (migracja 025).

## Księżyce jako miejsca gry: Nar Shaddaa

- Mapa miała jeden wpis „Nal Hutta & Nar Shaddaa”. Migracja 026 rozdziela go: **Nal Hutta**
  (planeta, bagienna, doba 87 h, rok 413 dni) i **Nar Shaddaa** (księżyc-miasto, typ `pionowe_miasto`)
  w tym samym układzie. Lokacje i postacie z dawnego wpisu przechodzą na Nar Shaddaa.
- `planety_cykle.macierz_planeta_id` (+ promień, okres i faza obiegu): księżyc bierze słońce, rok
  i pory roku z orbity planety macierzystej; na niebie księżyca widać planetę macierzystą z fazą.
  Nal Hutta ma Nar Shaddaa na liście księżyców (widok układu).
- **Pionowe miasto** (widok z okna, hotelu, kokpitu na lądowisku): warstwy wież do góry kadru,
  kładki, pasy okien, neony, mgła i przelatujące światła pojazdów; nad miastem Nal Hutta.
- Migracja 026 dodaje też Tracyna, którego 024 nie utworzyła (szukała planety „Nar Shaddaa”).
- Migracja 027: Nar Shaddaa ma te same współrzędne mapy co Nal Hutta (jedno słońce, jeden układ).
  Na mapie księżyc rysuje się na orbicie Nal Hutty; przy równej odległości kod wybiera planetę.
- Mapa: „Jesteś tutaj” pulsuje przy znaczniku własnej postaci (zamiast imienia), nie w środku układu.
- Pulpit: kafelek stanu (4 paski) jest zawsze kwadratowy; odliczanie etapu (nazwa, czas, pasek
  postępu) ma osobny kafelek obok i znika, gdy nic nie trwa.

## Rzeczywista skala na mapie i przerwanie skoku (migracja 033)

- Zoom do ok. 2·10¹⁶ px/pc. Kamera: środek w parsekach + przesunięcie w km (`camX/camY`, `camKx/camKy`),
  bo sama liczba w parsekach przy takiej skali traciłaby dokładność; km przechodzą do parseków, gdy to
  bezpieczne (`scalKamere`).
- Widok układu: najpierw schemat; gdy prawdziwa orbita planety (`promien_orbity_au`) przerośnie schemat,
  płynne przejście (mieszanie logarytmiczne przez trzy rzędy wielkości) do rzeczywistej skali: średnice
  gwiazd według typu, planet i księżyców z danych, księżyce na prawdziwych promieniach obiegu, okręty na
  orbicie według podejścia (bliska 300 km nad powierzchnią, średnia 4 R, daleka 15 R, krawędź 60 R).
- Okręty: znacznik, dopóki okręt na ekranie jest mniejszy; potem sylwetka w prawdziwej długości
  (`mapa_obiekty.dlugosc_m`: ISD 1600, Arquitens 450, Gozanti 64, Kom'rk 75, reszta 100 m).
- Zoom w ciało (planeta, księżyc, okręt) trzyma je pod kursorem, a kamera dalej za nim leci (planety krążą
  naprawdę, dziesiątki km/s); przeciągnięcie przesuwa kadr, zoom w pusty punkt kończy śledzenie.
- „Przerwij skok” (karta floty, karta planety, karta punktu; `mapa_rozkaz_anuluj`): przed wejściem w
  nadprzestrzeń bez skutków; w nadprzestrzeni wyjście tam, dokąd okręt doleciał (w studni układu — na jego
  krawędzi), paliwo według przebytej drogi i uszkodzenia (sprawność reaktora 10–30, hipernapędu 15–40,
  osłony 5–15, kadłub 2–8 pkt) × jakość podzespołów (`jakosc_podzespolow` 1–5: ×1,6 … ×0,4) × zapas mocy
  reaktora (do −25%). Przerwać może dowódca floty, wydający rozkaz albo MG.

## Strefy grawitacyjne układów

- `STREFA_UKLADU_PC` (0,05 pc, `api/floty.php`; ta sama wartość w mapie i scenie): w tym promieniu od planety
  jednostka jest w jej układzie. Zastępuje dawne 2 pc (wyskok 1,9 pc od układu kończył się „na orbicie”).
- Skok: z własnego układu odmowa (do planet i księżyców układu leci się podświetlnie); cel w strefie układu →
  skok do tego układu; trasa przez studnię trzeciego układu → odmowa z nazwą układu (`floty_trasa_kolizja`).
  Automat celujący w układ: wyjście z rozrzutem do 2 pc (`TOLERANCJA_AUTOMATU_PC`) → w układzie; rozrzut
  w studnię innego układu → na jego krawędzi; poza strefami → pusta przestrzeń.
- Lot podświetlny: punkt w strefie układu → lot do tego układu (nigdy w głąb studni ani w słońce); trasa przez
  studnię innego układu → odmowa. Jednostka przypisana do planety stoi zawsze na współrzędnych układu.
- Mapa: pusty klik w obrębie układu (także w słońce w widoku układu) otwiera kartę planety; skok do układu
  w karcie planety tylko dla jednostek spoza niego (statek przy księżycu leci podświetlnie).

## Lot podświetlny, punkt na mapie i pokład (migracja 028)

- **Lot podświetlny** (`mapa_flota_lot`, z `plan` tylko wyliczenie): zwykłe silniki, czas 1:1, MG może
  przyspieszyć (resynchronizacja „Lot podświetlny”). Trasa w km: planeta ↔ jej księżyc z promienia obiegu,
  poza tym z mapy (1 pc ≈ 30,9 bln km, więc lot do innego układu trwa lata i gra to pokazuje). Prędkości
  (`predkosc_podswietlna_kms`): ISD 1500, Arquitens i Gozanti 2000, Kom'rk 3000 km/s. Flota: obrót na kurs,
  potem lot tempem najwolniejszej jednostki; przylot przy planecie na daleką orbitę. `mapa_lot_przerwij`
  zatrzymuje jednostki tam, gdzie są. W oknie cel rośnie w miarę zbliżania (rozmiar z kąta).
- **Punkt na mapie**: klik w puste miejsce (gdy nic nie jest wybrane) otwiera kartę punktu: skok do punktu
  albo lot podświetlny. Karta planety ma też „Lot podświetlny do …” (np. Nal Hutta → Nar Shaddaa).
- **Pokład**: postacie na pokładzie nie mają znaczników na mapie; „Jesteś tutaj” pulsuje na statku, na którym
  jesteś. Karta statku: zakładki „Pasażerowie” i „Więźniowie” (`mapa_poklad`; widzą osoby na pokładzie,
  dowódca lub właściciel, MG). MG przełącza rolę przyciskiem w zakładce (`mg_poklad_rola`).

## Paczki lokacji (migracje 029–032)

- 029 Tatooine (56), 030 Nar Shaddaa (105, bez sześciu lokacji portu, które już były), 031 Lothal (112),
  032 Naboo (101).
- Wzór paczki: tabela tymczasowa z poziomem w hierarchii, import poziom po poziomie (rodzic po nazwie
  na tej planecie), planeta wyszukiwana po nazwie, `ON DUPLICATE KEY UPDATE id = id` — istniejące
  rekordy bez zmian, ponowny import dokłada tylko brakujące; na końcu kontrolny `SELECT`.
- Lądowiska niepubliczne (`publiczna = 0`, np. przemytnicze P-17) widzi w „Wybierz lądowisko” tylko MG
  i postać, która je zna (`znane_lokacje`); lądowanie na nieznanym lądowisku jest odrzucane.

## Widok z mostka i sceny na okrętach

- Postać na pokładzie okrętu z SQL (lokacja `okret:` albo `flota:`) dostaje scenę **liczoną
  automatycznie** ze stanu okrętu (`scena_okretu()` w `api/sceny.php`), bez klikania MG:
  manewr przed skokiem → widok obraca się; wejście w nadprzestrzeń (`wejscie_utc`) → animacja
  skoku i tunel; wyjście (`przylot_utc`) → animacja wyjścia i podejście: planeta mała do 5 min,
  średnia do 15 min, potem orbita z dużą planetą; okręt rozbity → powierzchnia planety.
  Ręczna scena MG wygrywa, jeśli jest nowsza (do następnego zdarzenia okrętu).
- **ISD** (klasa albo nazwa z „Imperial”, „ISD”, „niszczyciel”) ma tło **okna mostka**: trzy okna
  symetryczne względem środkowego trapezu, z fazowaną ramą. Przez okno widać to, na co patrzy
  dziób (pole widzenia ok. 100°): planetę (z fazą oświetlenia zgodną z położeniem słońca), słońce,
  albo samą przestrzeń, gdy planeta jest za plecami. Kadłuba i dziobu na razie nie widać.
- **Gwiazdy sąsiednich układów** (do 1000 pc, najwyżej 40 najbliższych, z pominięciem planet
  zastrzeżonych dla konta) widać w oknie w ich prawdziwym kierunku względem dziobu: do 50 pc jasne
  z poświatą, 50–300 pc jasne punkty, 300–1000 pc słabe punkty; dalej zlewają się z tłem.
  Wysokość w oknie i odcień są stałe dla każdej gwiazdy (z nazwy), bo mapa galaktyki jest płaska.
  Zakresy: `GWIAZDY_ZASIEG_PC` / `GWIAZDY_MAKS` w `api/sceny.php`, jasność w `js/rdzen/scena.js`.
- Inne okręty dostają te same sceny bez okna (pełny ekran).
- Wymaga migracji `016_orbita_przylot_rozbicie.sql` (przenosi dotychczasowy kąt orbity do
  `orbita_deg` i kieruje dzioby okrętów na orbicie ku planecie).
- Manewry są procesami w zakładce **Czas** (resynchronizacja); resynchronizacja skoku floty
  skraca też manewr, jeśli nowy koniec wypada wcześniej.

## Układy planetarne, dzień i noc

- **Współrzędne planety na mapie to położenie jej słońca.** Planeta krąży wokół słońca, księżyce
  wokół planety, a planeta się obraca. Epoka 0 = `1|1|0 00:00` (1.01.2010 00:00 czasu polskiego).
- Parametry planety (`api/astro.php`, tabela `planety_cykle`, NULL = domyślne): doba (h), rok (dni),
  nachylenie osi (amplituda pór roku), faza obrotu, faza pór roku, faza i promień orbity wokół
  słońca, średnica. Księżyce: `planety_ksiezyce` (promień orbity w km, okres w dniach, faza w epoce).
  Planeta bez wpisów dostaje **powtarzalne wartości z nazwy**: nachylenie 0–30°, fazy, a księżyce
  według liczby z mapy (do 6). Doba i rok — z mapy (brak: 24 h i 365 dni).
- **Zephyria = Ziemia** (migracja 015): doba 24 h, rok 365,2422 dnia, nachylenie 23,44°, Księżyc.
  Wschody i zachody słońca zgadzają się z prawdziwymi z dokładnością do ok. kwadransa (pomijamy
  równanie czasu).
- **Dzień i noc w lokacji** liczy tablet (`js/rdzen/astro.js`): wysokość słońca z deklinacji (pory
  roku), kąta godzinnego (obrót planety) i współrzędnych lokacji. Lokacja może mieć `szer`/`dl`
  (stopnie, np. Warszawa na Zephyrii: 52.23 / 21.01); bez nich liczy się je z `x`/`y` (km od punktu
  0/0 na równiku, 1° = obwód/360). Wynik: dzień, zmierzch (słońce do 6° pod horyzontem), noc.
  Widok za oknem na pulpicie używa tego zamiast zegara tabletu.
- **Widok układu** w karcie planety na mapie: planeta z dzienną i nocną stroną, kierunek słońca,
  księżyce na orbitach, jednostki ze strzałkami oraz opis: strona dzienna/nocna i czy jednostka
  wisi między planetą a księżycem. W ramce: położenie planety na orbicie wokół słońca.
  Odległości księżyców są schematyczne (nie w skali).
- API: `?a=uklad&planeta=<nazwa>` zwraca cykl planety; scena powierzchni zawiera `swiatlo`.

## Baza danych SQL

Docelowo cały stan gry leży w tabelach SQL, a dokumenty JSON w `datapad_dane` znikają moduł
po module. Schemat jest celowo rozległy: tabele bez kodu to fundament pod przyszłe moduły.

- **Bazę na serwerze zmienia się wyłącznie w phpMyAdmin** (Import pliku `.sql`). Pliki SQL
  wgrane na serwer leżą tam tylko archiwalnie; serwer sam niczego z nich nie wykonuje.
- **Źródłem schematu są migracje** w `api/migracje/` (`001_podstawa.sql`, `002_…`, kolejne numery
  bez luk). Każda zmiana schematu to nowa migracja; starych migracji się nie edytuje.
- `api/schema.sql` to **plik wynikowy**: wszystkie migracje po kolei, do importu do nowej, pustej
  bazy. Po dodaniu migracji: `node narzedzia/sql/zbuduj.js` (sprawdzenie: `--sprawdz`).
- **Działającą bazę** aktualizuje się tylko kolejnymi migracjami, po kopii zapasowej.
- **Postęp przenoszenia JSON → SQL:** `node narzedzia/sql/stan.js` tworzy
  `narzedzia/sql/STAN.md` (które dokumenty JSON są jeszcze używane i które tabele już mają kod).
- Pliki `.sql` są zablokowane dla przeglądarki (`api/.htaccess`).
- `api/schema.sql` zawiera też publiczną mapę z `api/mapa_seed.sql` (zaraz po 001), bo kolejne
  migracje odwołują się do planet. Mapa zawiera Havis III (id 2538), więc Dromund Kaas z migracji
  009 dostaje id 2539 — tak jak w bazie na serwerze.
- **Prywatne paczki danych** (np. planety Severiana, Nar Shaddaa) leżą w `archiwum/sql/` poza
  gitem, bo repo jest publiczne. Odtworzenie bazy od zera: `api/schema.sql`, potem te paczki
  w kolejności dat — wszystko importem w phpMyAdmin. Najprościej i najpewniej: import
  najnowszego zrzutu phpMyAdmin z `_kopia/` (też poza gitem), który zawiera schemat i dane.
- Skrypty `api/importuj_mape.php` i `api/importuj_dane.php` to narzędzia lokalne (CLI), nie część
  wdrożenia. `importuj_mape.php` tylko **dopisuje brakujące** planety i niczego nie kasuje.
- Dawne pełne migawki `schema_v3` … `schema_v12` są w historii gita; `api/schema.sql` ma te same
  polecenia co `schema_v12.sql`, plus mapę.

## Schemat SQL v5 — mapa obiektów i udostępnianie pozycji

Stan v5 odpowiada migracjom do 005 (pełny schemat: `api/schema.sql`). Dla istniejącej bazy v4 po kopii zapasowej zaimportuj wyłącznie
`api/migracje/005_uniwersalna_mapa.sql`. Baza ze starszym schematem wymaga wcześniejszych migracji
zgodnie z `archiwum/sql/README.md`. Migracja 004 dodaje relacyjne lokacje i most pozycji; migracja 005 dodaje uniwersalne obiekty mapy,
udostępnienia pozycji oraz ISD I „Walako” dla GOV-5401 i ISB-2137.

## Schemat SQL v7 — skład okrętów flot

Stan v7 odpowiada migracjom do 007 (pełny schemat: `api/schema.sql`). Baza z flotami v6 wymaga
jednorazowego importu `api/migracje/007_kolejnosc_okretow_floty.sql`. Nie importuj pełnego
schematu do działającej bazy.

Dodatek tworzy Flotę Eskortową Havis III (2 Arquitensy i 6 Gozanti; każda Gozanti ma 4 standardowe
TIE) oraz Flotę Zephyria z ISD I „Walako”. Liczba planetarnych myśliwców TIE Moffa Kirry pozostaje
nieokreślona. Profile napędu startują jako sprawne, profesjonalne jednostki o małym szumie; MG może
zmienić ich reaktor, hipernapęd, paliwo, sprawność i szum bazowy.

Dowódca może wybierać jednostki, dzielić flotę na grupy i wysyłać wybrane jednostki na współrzędne
lub pozycję widocznej jednostki. Skok liczy czas (1:1 z rzeczywistym) według dostępnej mocy
reaktora i hipernapędu, sprawności oraz paliwa; uszkodzenia zwiększają odchylenie wyjścia. Mapa
rysuje trasę aktywnego skoku. Panel GM pozwala przenieść jednostkę albo flotę na sesję; każdy taki
skok jest przerywany, a teleport trafia do dziennika `mapa_teleporty_mg`.

Panel GM pozwala budować skład kolejno: okręt flagowy, okręt 2, okręt 3 itd. Selektor pokazuje,
do której floty przypisany jest zajęty okręt, a API blokuje ponowne przydzielenie go do innego
składu. Mapa pokazuje nazwy planet, postaci z rangą oraz flotę i jej dowódcę w krótkiej karcie
po najechaniu kursorem.

Runtime zapisuje i odczytuje pozycje z SQL, a równolegle utrzymuje ich kopię w dotychczasowym
dokumencie `datapad_dane`, aby pozostałe moduły i starsze ścieżki ruchu działały podczas migracji.
Mapa synchronizuje pozycje postaci z SQL i pokazuje MG wszystkie postacie z pozycją; jednostki i
inne obiekty mają własne rekordy w `mapa_obiekty` oraz listę odbiorców w `mapa_obiekty_dostep`.
Pozostałe dokumenty aplikacji są nadal przechowywane jako JSON w kolumnie `payload` tabeli
`datapad_dane`; ich konwersja będzie następować modułami w kolejnych migracjach. Nie usuwaj tej
tabeli ani technicznych plików JSON (`manifest.webmanifest`, transport API, ustawienia lokalne).

Waluta księgowa ma jeden kod kanoniczny `CR`; `IC` jest aliasem tego samego Credit i nie tworzy
drugiego salda. Rachunki istniejące w v2 są przypisywane do `CR`.

## Aplikacje

| Aplikacja | Co robi | Skąd się wzięła |
|---|---|---|
| Nawigacja | gdzie jestem, dokąd i czym (pieszo, taksówka, pojazdy), przygotowanie statku do lotu, start, współrzędne skoku, lądowanie, bankomat, sklep | nowe |
| Mapa galaktyki | 2578 planet, regiony i szlaki nadprzestrzenne, wyszukiwarka, „Jesteś tutaj”, cel skoku, statki firm postaci | dawna osobna strona `Mapa/` |
| Karta postaci | tożsamość, status w rejestrach, dokumenty, pozycja (planeta, współrzędne, miejsce, budynek), statystyki, reputacja, kontakty, ekwipunek, notatnik | nowe |
| Holonet | komunikaty sieci (pisze je MG), transmisja na żywo, przechwycony kanał B0R5, kanały szyfrowane z wtyczek | hub, stream, `/recruitment/` |
| Firma | panel zależny od profilu: przewozy, komórka (plus misje), agencja handlowa (przykrywka ISB) | nowe |
| Giełda | notowania spółek i towarów, wykresy, kupno i sprzedaż przez Bank, portfel | dawne CDEX |
| Bank | saldo i historia, przelewy na numer rachunku (z potwierdzeniem właściciela), stali odbiorcy | nowe |
| Dekoder | odbiór dokumentu po sygnaturze, poziomy uprawnień, katalogi, animacja dekodowania | `/dekoder/gracz/` |
| Kalendarz | kalendarz sesji z pięciodniowym tygodniem, świętami H i sześcioma kulturami | `/calendar/` |
| Sieć frakcji | planety i instalacje, zasoby (gracze zmieniają stany), drzewo dowodzenia | `/frakcja/gracz/` |
| Ustawienia | alfabet (moduł ZPH), jasność, dane postaci, „o urządzeniu”, blokada | nowe |

Nowa aplikacja to plik w `js/aplikacje/`, który eksportuje
`{ id, nazwa, ikona, uruchom(kontener, ctx) }`. Trzeba go jeszcze dopisać do listy `APLIKACJE`
w `js/system.js` i do `PLIKI` w `sw.js`. Wzorem może być `ustawienia.js`, najprostsza z aplikacji.

## Kalendarz sesji

Aplikacja `js/aplikacje/kalendarz.js` zastępuje wcześniejszy Harmonogram. Korzysta z tych samych
akcji API (`harmonogram`, `harmonogram_dodaj`, `harmonogram_usun`) i tych samych dat ISO w bazie.
Zmienił się kalendarz i wygląd aplikacji:

- Miesiąc gry odpowiada miesiącowi realnemu, a rok gry to rok realny minus 2010.
- Tydzień ma pięć dni. Miesiąc zawiera 25 albo 30 zwykłych dni; pozostałe dni są świętami H.
- Sześć kultur zmienia nazwy miesięcy, dni i świąt oraz kolor akcentu. Wybór zapamiętuje tablet.
- Gracz może zgłosić dowolną postać, domyślnie własną. Wpis ma status i notatkę do 120 znaków;
  ten sam użytkownik może zgłosić różne postacie na ten sam dzień.
- Panel MG nadal zarządza wpisami w zakładce **Kalendarz**. Eksport tworzy plik CSV.

Daty gry są też używane w pozostałych widokach systemu, więc pulpit, blokada i aplikacje pokazują
ten sam format daty GST.

## Bank

- Każda postać dostaje rachunek (numer `0000-0000-0000`) przy pierwszym wejściu do banku.
  Waluta to kredyty (`kr`), tylko liczby całkowite.
- Przelew idzie na numer rachunku. Po wpisaniu numeru bank pokazuje właściciela, a przed
  wysłaniem jest ekran potwierdzenia. Nie da się przelać więcej, niż jest na koncie.
- Stałych odbiorców zapisuje się z własną nazwą (np. „Mechanik z doków”), także przy przelewie.
- **MG** (zakładka Bank) widzi salda i wszystkie operacje. Może zaksięgować jednorazową wpłatę
  lub obciążenie i ustawia **stałe miesięczne dochody**: postać, kwota, dzień miesiąca (1–28),
  nadawca i tytuł. Kwota ujemna działa jak stała opłata (np. czynsz za hangar) i może zejść
  z saldem poniżej zera.
- Dochody wypłacają się bez crona: przy każdym wejściu do banku system dopisuje wszystkie
  wypłaty, których termin minął (z datą należnego dnia). Strefa czasowa: Europe/Warsaw.
- Cały bank to jeden plik danych zmieniany pod blokadą, więc przelew wykonuje się w całości
  albo wcale.

## Firmy

- **Wspólny katalog** (panel MG → Katalog): towary (nazwa, kategoria, masa w t na jednostkę,
  jednostka), spółki (notowane i poza giełdą) oraz planety. Każdy towar katalogu ma instrument
  giełdowy, a towary i spółki z giełdy są dopisywane do katalogu przy jego otwarciu przez MG.
  Status spółki zmienia się w Katalogu. Wycofanie spółki ukrywa ją na giełdzie i blokuje nowe
  transakcje, zachowując dotychczasowe akcje, historię i kursy graczy. Do planet dojdzie później
  mapa (współrzędne, odległości).
- **Spółki są firmami w systemie:** przy otwarciu Katalogu MG giełdowe i prywatne spółki
  dostają powiązaną kartę firmy, profil działalności, skalę kapitału, NPC-menadżera (również
  jako członka załogi) i rachunek bankowy. Brakujący rachunek jest jednorazowo zasilany
  kapitałem początkowym; istniejące salda pozostają bez zmian. Profile biznesowe to transport,
  wydobycie, produkcja i finanse. Ustawienia spółek i menadżerów są w Katalogu, a pełny rejestr
  firm MG otwiera z tej samej strony.
- Firma ma **profil**, który decyduje o interfejsie. Profil to para plików:
  `js/aplikacje/firma/<profil>.js` (zakładki) i logika w `api/firmy.php` (dane i operacje).
  Nowy profil: dopisać go do `PROFILE` w `js/aplikacje/firma.js`, `PROFILE_FIRM` w `api/firmy.php`
  (razem z `firma_dane_puste` i operacjami) oraz `PROFILE_FIRM` w `gm/mg.js`.
- Członkowie firmy: **właściciel** i **menedżer** mogą zmieniać dane, **pracownik** tylko podgląda.
  Postać należąca do jednej firmy trafia od razu do jej panelu.

### Wspólne dla wszystkich profili

- **Rachunek firmy** w banku (klucz `f:<id firmy>`): zakładka Finanse pokazuje saldo, numer
  i historię. Właściciel i menedżer robią z niego przelewy. Na rachunek firmy można przelać
  pieniądze zwykłym przelewem z aplikacji Bank, a MG może go zasilić (Bank → operacja
  jednorazowa) albo ustawić mu stały miesięczny dochód.
- **Budynki i rynek nieruchomości:** MG wystawia oferty (Katalog → Rynek nieruchomości),
  firma kupuje je z własnego rachunku, a kupiona oferta znika z rynku. Budynek ma pojemność,
  liczbę pracowników (gracz zatrudnia i zwalnia) i kierownika z załogi.
- **Ukryte statystyki** osób (1–10): pilotaż, dowodzenie, taktyka, zarządzanie, logistyka,
  lojalność, konspiracja. Nigdy nie trafiają do datapada. **Test** daje zgrubną ocenę słowną
  (prawdziwa wartość ±2) i można go powtórzyć **raz na 30 dni** dla tej samej osoby.
- **Pula kandydatów** (panel MG → Rekrutacja): osoby do zatrudnienia z ukrytymi statystykami.
  Firma może je testować (każda firma osobno, raz w miesiącu) i zatrudnić. Zatrudniony znika
  z puli i przechodzi do załogi razem z wynikiem testu. Pule: `ogolna` (przewozy i komórki)
  i `agencja` (agencja handlowa).

### Profil: przewozy

- **Flota:** statki z ładownością (t), stanem, położeniem i kapitanem. Gracz może zmienić nazwę.
- **Magazyn:** towar firmy na poszczególnych planetach.
- **Załoga:** gracz przydziela ludzi do statków i wyznacza kapitana.
- **Szlaki:** plan kursu, czyli statek i przystanki, a na każdym rozładunek lub załadunek.
  Serwer na bieżąco sprawdza plan: start tam, gdzie stoi statek, ładowność po każdym przystanku,
  rozładunek tylko tego, co jest na pokładzie, załadunek tylko tego, co jest w magazynie, i pustą
  ładownię na końcu.
- Gracz składa **plan lotu**, a MG (panel → Firmy → „Kursy do obsłużenia”) przyjmuje go
  („w trasie”), kończy albo odrzuca z uwagą. Zakończenie przenosi towar między magazynami
  i stawia statek na ostatniej planecie. Przy przyjęciu i zakończeniu plan jest sprawdzany
  ponownie, na aktualnych magazynach.
- MG ustawia flotę, załogę i magazyn w edytorze JSON. Planety, towary, statki i osoby wpisuje
  po nazwie. Zapis w edytorze nie rusza kursów graczy.
- Pensje i koszt obsady budynków są na razie tylko informacją (nic nie schodzi automatycznie
  z rachunku), a kurs nie kosztuje paliwa ani czasu zależnego od odległości. To naturalne
  następne kroki razem z giełdą i mapą.

### Profil: komórka

To ten sam panel co w przewozach (flota, magazyn, załoga, legalne kursy handlowe), plus
dodatki (`js/aplikacje/firma/komorka.js`, `api/komorka.php`):

- **Misje:** gracz wysyła statki i ludzi na akcję z kryptonimem, terminem, rejonem i celem.
  **Jedna misja to jedna sesja z MG.** MG ją zatwierdza (siły dostają stan „na misji” i są
  zablokowane dla kursów i innych misji), a po sesji **rozlicza ręcznie**:
  - dla każdego statku: wrócił sprawny, uszkodzony albo zniszczony (zniszczony znika z floty);
  - dla każdej osoby: cała, ranna albo poległa (poległa znika z załogi);
  - zmiany w magazynach (łup na plus, straty na minus);
  - gdzie statki kończą misję;
  - raport, który gracze czytają w zakładce Misje.
- Ranna osoba nie poleci na misję. Wyleczenie: MG zmienia jej `stan` na `zdrowy` w edytorze.

### Profil: agencja handlowa (przykrywka ISB)

Wewnętrzne narzędzie ISB do kontaktu, kontroli i rozliczeń z nielegałami i szpiegami.
W datapadzie wygląda jak zwykła agencja handlowa i nigdzie nie pada słowo „ISB”. Nazwę firmy
wybiera MG (np. „Biuro Analiz Rynkowych Sektora”). Zakładki:

- **Kontrahenci:** agenci z kryptonimami i ukrytymi statystykami (test jak u kapitanów
  i kierowników) oraz kandydaci do współpracy z puli `agencja`. Przycisk **Rozlicz** wypłaca
  pieniądze z rachunku agencji. Jeśli agent jest postacią gracza (pole `gracz` w edytorze MG),
  przelew trafia na jego rachunek z neutralnym tytułem, np. „Prowizja handlowa”. W przeciwnym
  razie jest to wypłata gotówkowa.
- **Statki:** flota agentów.
- **Kontrakty:** misje handlowe. To misje z komórki z dodatkowymi **warunkami oficjalnymi**
  (legendą: ładunek, trasa, odbiorca) i **uwagami poufnymi** (prawdziwe zadanie). MG zatwierdza
  je i rozlicza ręcznie, z dodatkowymi wynikami: agent **spalony** albo **zatrzymany**, statek
  **przejęty**.
- **Monitoring:** teczki obserwowanych obiektów (osoby, statki, lokale). MG dopisuje do nich
  meldunki z terenu (panel MG → Firmy → Teczki w obserwacji).
- **Rozliczenia:** rachunek agencji (to samo co Finanse w innych profilach).

## Handel i kursy automatyczne

- **Ceny** (`api/handel.php`, z nich skorzysta giełda): towar ma cenę bazową, a planeta
  **specyfikę**, czyli mnożniki dla towarów albo kategorii, np. Kessel `{"surowce": 0.6,
  "żywność": 1.6}`. Do tego dzienne wahania ±8% (takie same dla wszystkich graczy danego dnia)
  i różnica kupno/sprzedaż ±5%. Wszystko w pełnych kredytach.
- **Operacje w kursie:** załaduj z magazynu / rozładuj do magazynu (jak wcześniej) oraz
  **kup / sprzedaj na rynku** planety. Przy operacji widać dzisiejszą cenę, a pod planem
  wycenę całego kursu po kursie giełdowym.
- **Kapitan:** ukryta cecha `handel` (1–10) kapitana statku zmienia ceny. Przy 10 kupuje
  o 10% taniej i sprzedaje o 10% drożej, przy 1 odwrotnie o 8%. Bez kapitana statek handluje
  jak „3”. Gracz widzi tylko wycenę giełdową i faktyczne wyniki w dzienniku, więc zdolności
  kapitana poznaje po wynikach albo testem.
- **Kurs automatyczny:** zaznaczenie „Kurs automatyczny — powtarzaj co N dni” wymaga powrotu
  na planetę startową. Po przyjęciu przez MG kurs powtarza się sam. Każdy obieg liczony jest
  po cenach z dnia obiegu i rozliczany na rachunku firmy jedną operacją („Giełdy portowe”),
  a wynik trafia do **dziennika obiegów**. Brak środków na zakupy, brak towaru w magazynie
  albo przeładowanie **wstrzymuje** automat, a gracz może go wznowić albo zatrzymać.
  Zaległe obiegi liczą się przy wejściu do firm (bez crona), najwyżej 12 naraz.
- Zwykły (nieautomatyczny) kurs z kupnem i sprzedażą rozlicza się, gdy MG kliknie
  „Zakończ i rozlicz”.

## Giełda CDEX

- Aplikacja **Giełda** (`js/aplikacje/gielda.js`, `api/gielda.php`): spółki i towary notowane na
  giełdach planetarnych (Coruscant, Corellia, Kuat, Bespin, Nar Shaddaa). Lista z filtrami
  (giełda, spółki/towary, kategoria, szukanie), wykres 30 dni – 2 lata, kupno i sprzedaż
  z potwierdzeniem, portfel z wynikiem i historia transakcji.
- **Rozliczenie w Banku:** zlecenie obciąża albo uznaje rachunek postaci („CDEX — rozliczenia
  giełdowe”), z prowizją (domyślnie 0,5%, min. 1 kr). Nie da się kupić za więcej, niż jest
  na koncie, ani sprzedać więcej, niż jest w portfelu.
- **Dane** (`datapad_dane` w SQL; dokumenty domenowe w kolumnie JSON): `gielda` (giełdy, instrumenty, ustawienia), `gielda_notowania`
  (kurs zamknięcia na każdy dzień, do 2 lat), `gielda_portfele`. Domyślny zestaw jest
  w `gielda_domyslna()`. Kategorie towarów są te same co w katalogu firm: paliwo, surowce,
  żywność, medycyna, technika, broń, luksus, przyprawy.
- **Skrypt kursów:** każdy dzień liczony z poprzedniego: powrót do „wartości godziwej” (cena
  bazowa × mnożniki wydarzeń) plus losowe wahanie. Losowość wynika z symbolu i daty, więc
  wynik jest powtarzalny. Brakujące dni dopisują się same przy wejściu na giełdę (bez crona).
  Nowy instrument dostaje od razu historię wstecz.
- **Panel MG → Giełda:** podgląd kursu z prognozą (z wydarzeniami i bez, także dla szkicu
  wydarzenia z formularza), kursy dnia, portfele graczy, edytor giełd i instrumentów (JSON),
  ustawienia skryptu, „Przelicz całą historię”.
- **Katalog i giełda są uzgadniane automatycznie:** dodanie towaru w Katalogu dodaje go jako
  instrument giełdowy; dodanie towaru na giełdzie dopisuje go do Katalogu. Spółki giełdowe
  trafiają do Katalogu. Każda spółka ma status „Firma notowana na giełdzie” albo „Firma poza
  giełdą”; nienotowane spółki nie pojawiają się w notowaniach i nie przyjmują zleceń.

## Wydarzenia

Osobny moduł (`api/wydarzenia.php`, komponent `gm/wydarzenia.js`), którym MG porusza kilkoma
systemami naraz. Wydarzenie ma termin (od, opcjonalnie do), włącznik i listę skutków:

| Skutek | Co robi |
|---|---|
| Ceny | mnożnik cen na giełdzie **i w handlu firm** (`handel.php`). Zakres: planeta, rodzaj (towary/spółki), kategoria, konkretny instrument lub towar; zmiana w %, narastanie w dniach, mnożnik wahań. Po końcu wydarzenia ceny same wracają. |
| Holonet | komunikat w Holonecie, na start albo dzień po zakończeniu |
| Kanał szyfrowany | wiadomość w kanale czarnego rynku (szyfr słowny, odszyfrowują ją pliki ze słownikiem) |

- Bez wydarzeń są zwykłe wahania. Komunikaty wysyłają się same przy pierwszym żądaniu po
  terminie i tylko raz. Zapis wydarzenia przelicza dzisiejszy kurs, więc działa od razu.
- **Szablony:** blokada planetarna, odkrycie złóż, kryzys paliwowy, embargo, panika na rynkach,
  epidemia, hossa w stoczniach. Szablon wypełnia skutki i teksty, a MG je poprawia.
- Komponent jest jeden, a osadzają go zakładki: Wydarzenia (wszystko), Giełda (z podglądem
  kursu), Holonet i Kanały (tylko wydarzenia z ich komunikatami). Nowy rodzaj skutku to wpis
  w `RODZAJE` (`gm/wydarzenia.js`) i w `api/wydarzenia.php`.

## Czarny rynek informacji

- MG zakłada **kanał** (panel MG → Kanały): nazwa i klucz instalacyjny. W kanale nadaje
  wiadomości szyfrem słownym, np. „Duży ptak opuścił gniazdo na Rykowie. Populacja ptaków
  zmalała tam do oczu.”
- Gracz instaluje **wtyczkę** w Ustawieniach → Wtyczki zewnętrzne, wpisując klucz (zdobyty
  w grze). W Holonecie pojawia się sekcja „Kanały szyfrowane”.
- **Plik deszyfrujący** to dokument Dekodera ze słownikiem (panel MG → Dokumenty → pole
  „Słownik”). Jedna linia: `duży ptak | dużego ptaka = gwiezdny niszczyciel (ISD)`, z odmianami
  oddzielonymi `|`. Gracz pobiera plik po sygnaturze, więc ma go „na dysku”.
- Kanał podmienia znane kody na znaczenia (wyróżnione kolorem, oryginał w podpowiedzi).
  Przycisk „Pokaż szyfr” wraca do oryginału. Bez pliku wiadomości zostają zaszyfrowane.
  Różne pliki mogą znać różne hasła, więc da się rozdawać częściowe klucze.

## Stan faktyczny (animowane tło)

Na ekranie startowym, blokadzie i pulpicie w tle żyje scena pokazująca, gdzie jest postać.
Ustawia ją MG w panelu (zakładka **Stan postaci**, otwiera się jako pierwsza), dla jednej
postaci albo dla wielu naraz (np. cała drużyna na jednym statku). Tablety pytają o scenę co
6 sekund, więc zmiana w trakcie sesji pojawia się sama.

| Scena | Co widać |
|---|---|
| Przestrzeń | gwiazdy powoli dryfują |
| Skok w nadświetlną | gwiazdy przyspieszają i rozciągają się w smugi, błysk, potem tunel |
| Nadświetlna | niebiesko-biały, wirujący tunel nadprzestrzeni |
| Wyjście z nadświetlnej | błysk, smugi gasną i kurczą się w gwiazdy, wyłania się planeta |
| Orbita | planeta z obracającą się powierzchnią, atmosferą i nocną stroną |

- **Typ planety** (kolory i powierzchnia): pustynna, lodowa, oceaniczna, leśna, wulkaniczna
  (lawa), gazowy olbrzym (pasy, burza), miasto-planeta (światła miast po nocnej stronie),
  bagienna, skalista. **Odległość:** blisko (planeta wypełnia dół ekranu), średnio, daleko.
- **Miejsce** (np. „Kashyyyk”) zmienia też rysunek powierzchni: ta sama nazwa daje zawsze tę
  samą planetę. **Opis** (np. „Kurs: Nar Shaddaa · ETA 3 h”) wyświetla się na pulpicie pod datą.
- Animacje skoku i wyjścia grają na tabletach włączonych w chwili zmiany. Tablet włączony
  później od razu pokazuje stan końcowy (tunel albo orbitę).
- Wszystko rysowane kodem na `<canvas>`, bez obrazków. W otwartych aplikacjach i przy
  wygaszonym ekranie animacja stoi, żeby nie drenować baterii. Ustawienie systemowe
  „ogranicz ruch” spowalnia animacje.

## Karta postaci

Aplikacja „Karta postaci” (pierwsza na pulpicie). Wszystko oprócz notatnika ustawia MG.

- **Tożsamość:** imię, numer ID (wyliczany z konta), przynależność, oddział, ranga, poziom
  uprawnień, środki na koncie, firmy postaci. **Status w rejestrach:** czysty, notowany albo
  **poszukiwany** (list gończy: zlecający, nagroda, uwagi). **Dokumenty i licencje**, także
  fałszywe tożsamości.
- **Pozycja:** planeta (z katalogu), współrzędne (tekst — mapa doda później liczby), miejsce
  (np. „pokój 14, hotel Pod Bantą”) i opcjonalnie **budynek** dowolnej firmy (np. biuro przy
  kopalni, hangar). Wtedy karta pokazuje dane budynku i schemat kondygnacji. MG ustawia
  pozycję w zakładce **Stan postaci**, dla wielu postaci naraz, tak jak scenę.
- **Statystyki:** otwarta struktura na system gry. MG wpisuje własne **cechy** i **umiejętności**
  (liczby, wyświetlane jako romby) oraz **liczniki** (np. Rany 3/12, Stres 2/10, jako paski).
  Bez wpisów karta mówi „Brak danych biometrycznych”.
- **Reputacja** u organizacji (−100…100): paski od „wrogi” do „sprzymierzony”.
- **Kontakty** i **ekwipunek:** listy „nazwa = opis”.
- **Notatnik:** prywatny, pisze go gracz i zapisuje się sam. MG ma podgląd.
- Panel MG → **Karty postaci:** edycja w prostym formacie — jedna linia = „nazwa = wartość”.

**Budynki** (w firmach i na rynku nieruchomości) mają teraz: `wspolrzedne`, `powierzchnia` (m²),
`pietra` (kondygnacje nad ziemią) i `szyby` (poziomy pod ziemią: szyby kopalni, piwnice, bunkry).

## Przemieszczanie się (Nawigacja)

- **Lokacje** (panel MG → Katalog → Lokacje): miejsca na planetach z liczbowymi współrzędnymi
  w km (`x`, `y`) i typem: hangar, port, hotel, bankomat, sklep, kantyna, biuro, garaz, inne.
  Budynki firm z `x` i `y` są lokacjami automatycznie. Postać zna lokacje **publiczne**, budynki
  swoich firm i **znane miejsca** wpisane w karcie (np. melina, tajny bankomat).
- **Pozycja:** MG ustawia lokację postaci w zakładce Stan postaci. Od tej chwili postać porusza
  się sama, a po dotarciu jej pozycja zmienia się automatycznie.
- **Środki transportu** (Nawigacja → Dokąd? → Trasa), posortowane od najszybszego:
  - pieszo (5 km/h);
  - **taksówka**: tylko na planetach z `urbanizacja` „wysoka” (szybko) albo „niska” (długo się
    czeka), cena za km z `taksowka`, płatna kartą albo gotówką;
  - **pojazdy** (np. spider) prywatne (karta postaci) i firmowe (edytor firmy), z prędkością
    i pozycją. Jeśli pojazd stoi gdzie indziej, najpierw trzeba do niego dojść, a potem jedzie
    razem z postacią. „Prowadzisz sam” zależy od umiejętności Pilotaż z karty (0 → wolno, 5 → szybko),
    „z kierowcą” od ukrytego pilotażu kierowcy z załogi firmy.
- **Czas:** podróże liczone są w minutach świata, a czas świata płynie 1:1 z rzeczywistym
  (30-minutowy przejazd trwa 30 minut). Szybciej tylko przez resynchronizację MG (sekcja „Czas świata”).
- **Przygotuj statek do lotu:** dla statków firm postaci stojących w hangarze (`hangar`
  w edytorze firmy). Czas obsługi naziemnej = kontrola zależna od **jakości hangaru**
  (`jakosc` 1–5: 3 h … 40 min) + **tankowanie** brakującego paliwa (`paliwo` statku w %)
  + 2 h **dowozu**, gdy hangar ma za mały **zapas** (`paliwo` hangaru). Paliwo (`cena_paliwa`
  za 1%) płaci rachunek firmy. W tym czasie gracz może dojechać do hangaru, a gdy statek jest
  gotowy i postać jest na miejscu, pojawia się **„Wejdź na pokład”**. Start i lot ustala MG
  (scena, pozycja).
- **Gotówka:** postać ma portfel obok rachunku. **Bankomat** (gdy postać przy nim stoi):
  wypłata i wpłata. **Sklep** (oferta w lokacji): zakup kartą albo gotówką, a towar trafia do
  ekwipunku.
- **Szybkie akcje na pulpicie:** podróż w toku z odliczaniem, „Przygotuj [statek] do lotu”
  i stan obsługi, „Wejdź na pokład”, najbliższy bankomat (do 5 km), taksówka.

## Mapa galaktyki

- Aplikacja `js/aplikacje/mapa.js`, rysowana na `<canvas>`: planety, podpisy regionów, szlaki
  nadprzestrzenne (główne widać zawsze, lokalne po przybliżeniu) i siatka. Przesuwanie palcem,
  szczypanie albo kółko myszy, wyszukiwarka planet i szlaków, karta planety (region,
  współrzędne, grawitacja, słońca i księżyce, doba i rok, średnica, odległość od postaci).
- Planety i szlaki leżą w SQL (`mapa_planety`, `mapa_szlaki`). Geometria szlaku jest
  przechowywana jako kolumna JSON. API przesyła dane mapy do tabletu, a service worker buforuje
  je do pracy bez zasięgu.
- Pozycję podaje `api/?a=mapa` (`api/ruch.php`): planeta postaci, stan (powierzchnia, orbita,
  nadprzestrzeń), cel skoku i statki jej firm. **Planety z katalogu MG łączą się z mapą po nazwie**
  (bez wielkości liter, ogonków i spacji), więc planeta w katalogu powinna nazywać się tak jak
  na mapie, np. „Kessel”, „Nar Shaddaa”. Planeta spoza mapy po prostu nie dostaje znacznika.
- **Planety spoza mapy i planety zastrzeżone** dodaje MG w katalogu (Katalog → Planety), nie
  w plikach mapy: repo jest publiczne, a `assets/` może pobrać każdy. Pole
  `"mapa": {"x": 8352, "y": -2990, "kwadrat": "Q-12"}` stawia planetę na mapie (y rośnie na
  północ, `region` opcjonalny), a `"widoczna_dla": ["ISB-5023", "GOV-5401"]` ogranicza ją do tych
  loginów (MG widzi zawsze). Inni gracze nie dostają jej z API w ogóle, a katalog wysyłany do
  datapada (`katalog_dla_gracza()`) nie zawiera tych dwóch pól. Na mapie planeta zastrzeżona
  to różowy romb z podpisem i znaczkiem „Dane zastrzeżone” na karcie.
- **Planety tajne w świecie gry** (zaginione, ukryte, obszary nieznane Republice i Imperium)
  trzyma się w `datapad_dane` po stronie serwera (wpis `planety_ukryte`, poza publiczną mapą,
  w formacie gotowym do katalogu: `nazwa`, `mapa` z x, y i regionem, `dane_mapy`). Odsłonięcie planety to
  wpis w katalogu MG z polami `mapa` i ewentualnie `widoczna_dla`.
- Mapa nie zmienia zasad skoku: Nawigacja dalej porównuje współrzędne z polem `wspolrzedne`
  w katalogu.

## Widok z planety, start i skok

- **Widok za oknem:** postać stojąca w lokacji na planecie widzi na pulpicie krajobraz tej
  planety. Krajobraz zależy od pola `typ` planety w katalogu: pustynna (wydmy), lodowa
  (szczyty), oceaniczna (morze z wyspą), leśna (wzgórza z lasem), wulkaniczna (lawa),
  gazowa (morze chmur z miastem w obłokach), miasto (wieżowce), bagienna, skalista.
  Od 21:00 do 6:00 (zegar tabletu) jest noc z gwiazdami i światłami okien. Rama zależy od
  miejsca: okno ze szprosami (biuro, hotel, kantyna, sklep, garaż), wrota hangaru (hangar,
  port, pokład) albo otwarty widok (bankomat, inne). Scena ustawiona przez MG wygrywa,
  dopóki postać nie zmieni miejsca (liczy się nowsza zmiana).
- **Start:** na pokładzie statku po obsłudze naziemnej Nawigacja pokazuje „Start”. Wszyscy na
  pokładzie widzą wznoszenie: przez 5 minut chmury w kolorze atmosfery planety, potem niebo
  ciemnieje i pojawiają się gwiazdy (planety nie widać, jest za rufą). Start zużywa 5% paliwa.
  Licznik liczy od chwili startu, więc tablet włączony później jest w tym samym miejscu lotu.
- **Orbita:** po wznoszeniu Nawigacja czeka na **współrzędne skoku** albo na lądowanie. Na razie współrzędne porównywane są z polem `wspolrzedne` planet
  w katalogu (wielkość liter, spacje i znaki inne niż cyfry, litery, kropka i minus nie mają
  znaczenia). Pełne zasady astronawigacji dopiszemy razem z mapą.
- **Skok:** dobre współrzędne uruchamiają animację skoku i tunel u wszystkich na pokładzie.
  Wyjście z nadprzestrzeni wywołuje MG (panel → Stan postaci → Statki w locie → **Zakończ
  skok**). Statek pojawia się przy celu (−20% paliwa) bez lądowiska, a załoga widzi wyjście
  z nadprzestrzeni z planetą.
- **Lądowanie** (Nawigacja na orbicie), lądowiska to lokacje typu hangar, platforma, port:
  - **automat:** wybór z listy lądowisk, które postać zna (publiczne, firmowe, ze „znanych
    miejsc”, poprzednio użyte) albo które przydzieliła kontrola lotów (oznaczone ★);
  - **ręcznie:** współrzędne `x / y` lądowiska (tolerancja 0,25 km), także nieznanego.
    Po lądowaniu miejsce trafia do znanych miejsc załogi.
- **Kontrola lotów:** planeta może mieć pole `kontrola` (np. „Imperialna Kontrola Lotów”),
  a pusta oznacza brak kontroli. Po wyjściu z nadprzestrzeni kontrola sama się odzywa, a gracz
  może też nadać pierwszy („Wywołaj kontrolę lotów”). Odpowiada MG w panelu (Stan postaci →
  Statki w locie): tekst i opcjonalny **przydział lądowiska**. Odpowiedzi postaci
  niezależnych z modelu językowego (LLM) da się podpiąć w funkcji `lacznosc_odpowiedz_npc()`
  w `api/ruch.php`. To miejsce jest przygotowane, ale jeszcze puste (wymaga klucza API
  i ustalenia osobowości kontrolerów).
- W locie nie da się zejść z pokładu, jechać ani iść. Pulpit pokazuje szybką akcję
  z odliczaniem wznoszenia i informację o orbicie albo nadprzestrzeni.

## Promy w hangarach okrętów (migracja 035)

- Każdy ISD ma w hangarze prom klasy Lambda (np. „Lambda Walako”): osobna jednostka z napędem
  (hipernapęd, paliwo, może lądować) w podgrupie floty ISD bez własnego dowódcy, więc dowodzi nim
  dowódca floty ISD (dziś kpt. Stingert; kolejni kapitanowie dostają prom razem z okrętem).
- **W hangarze** (`w_hangarze = 1`) prom stoi razem z okrętem (także w skoku), nie ma znacznika na
  mapie (w karcie ISD: „Hangar: …”) i nie przyjmuje rozkazów floty. Z promu w hangarze widać
  kosmos przez wrota hangaru.
- **Nawigacja na pokładzie ISD → Hangar:** „Wejdź do promu” (każdy na pokładzie), dla kapitana
  „Lot na planetę” (wersja krótka: wylot, dolot na bliską orbitę i lądowanie na wybranym lądowisku
  jednym rozkazem) i „Wylot z hangaru” (wersja długa: prom staje na orbicie obok ISD, dalej zwykłe
  rozkazy z panelu floty na mapie — skok, lot podświetlny, lądowanie).
- **Na promie:** w hangarze „Wyjdź do hangaru”; na lądowisku zwykłe „Zejdź z pokładu” i „Start”;
  na orbicie planety, przy której stoi ISD (albo w tym samym punkcie przestrzeni) — „Powrót do
  hangaru”. Wylot i powrót są też w panelu floty na mapie.
- Wrota są zamknięte, gdy ISD jest w nadprzestrzeni albo w locie podświetlnym.
- Do zrobienia po imporcie listy lokacji ISD I: „Wejdź do promu” tylko z lokacji hangaru.

## Układy planetarne w prawdziwej skali (migracja 037)

- **Gwiazdy** (`api/astro.php`, `ASTRO_FIZYKA`): typ ma temperaturę i promień, konkretna gwiazda — rozrzut
  z nazwy układu (promień ±15%, temperatura ±5%). Jasność L = R² · (T / 5772)⁴.
- **Ekosfera:** planeta krąży w odległości √L j.a. (suma jasności słońc); ręczny `promien_orbity_au`
  w `planety_cykle` dalej wygrywa. **Brzeg układu:** 50 · √L j.a.
- **Mapa:** układ zawsze w prawdziwej skali (bez schematu): słońce, orbita, planeta, księżyce i przerywany
  brzeg układu; ciała mniejsze niż kilka pikseli to kropki. Pusty punkt w widoku układu otwiera kartę
  punktu (odległość od słońca i planety) ze skokiem i lotem podświetlnym tam. Zoom przyciąga się do ciała
  tylko, gdy kursor jest na nim. Bez napisu „Jesteś tutaj” — sam pierścień radaru.
- **Silniki podświetlne** (migracja 039, `mapa_naped_jednostki.podswietlna_pc_doba`): szybki rozpęd i hamowanie
  (2 min), potem stała prędkość przelotowa; flota leci tempem najwolniejszej jednostki. Skala: Sokół Millennium
  z Hoth na Bespin (132,9 pc) w ok. 3 dni = 45 pc/doba. Kom'rk 35, Arquitens 28, ISD 22, Gozanti 20, Lambda 15
  (MG: profil napędu). Lot w układzie ok. 2 min. Migracja 038 (przyspieszenie w G) jest zastąpiona.
- **Lot w obrębie układu** ma ograniczoną prędkość (ISD 1 j.a. w ok. 40 min, Sokół ok. 20 min); między układami
  — pełna prędkość przelotowa.
- **Okienka na mapie** (karta szczegółów i panel floty) mają proporcje telefonu w pionie: karta przy prawej,
  panel floty jej lustrzanym odbiciem przy lewej (na telefonie oba na całą szerokość, otwarty jeden naraz).
  Nawigacja w krokach: punkt/planeta → co zrobić (skok, lot, orbita, lądowanie, start) → szczegóły
  z komputera i rozkaz; „‹” wraca. Panel floty: sekcje zwinięte (jednostki, kurs, skok na współrzędne,
  hangar, grupy, wskaźniki).
- **Pozycja w układzie:** `mapa_obiekty.uklad_kx/ky` — km od słońca, gdy jednostka nie stoi przy planecie.
- **Studnie grawitacyjne** wokół ciał: słońce 20 promieni, planeta 100 promieni. Skok i lot nie mogą
  przez nie przejść ani w nich się kończyć — poza skokiem automatem na orbitę planety (wariant B) i odlotem.
- **Krawędź układu:** skok z wariantem „krawędź układu” kończy się na brzegu całego układu od strony
  nadlotu; „dolot” na krawędź z orbity planety to lot podświetlny na brzeg. Przerwany skok w układzie
  i rozrzut w obcym układzie też kończą się na jego brzegu.
- **Scena** z punktu układu: planeta i słońce w kierunku i wielkości kątowej z odległości; słońce
  na mostku zawsze w prawdziwej wielkości (łuna słabnie z odległością).

## Wnętrza okrętów i okna (migracja 036)

- **Plan wnętrza** to drzewo w `lokalizacje` (szablon klasy: sekcja → pokład → pomieszczenie), wskazane
  przez `mapa_obiekty.plan_wnetrza_id`. Wszystkie ISD I korzystają z „Imperial Star Destroyer Type I –
  wnętrze”; inna klasa dostanie własny szablon i inny korzeń (`api/poklad.php`).
- **Pomieszczenie postaci** zapisuje się w karcie (`wnetrze`: okręt, pomieszczenie, przejście). Bez zapisu
  kapitan (dowódca floty okrętu) jest na Mostku głównym, pozostali w Głównym korytarzu centralnym.
- **Nawigacja na pokładzie:** „Jesteś: …”, skróty „→ Hangar promów” / „→ Mostek” i „Idź do…”
  (pomieszczenia pogrupowane po pokładach, z czasem dojścia). Czas z drzewa: krok między
  pomieszczeniami 0,75 min, turbowinda między pokładami 1,25 min, między sekcjami 1,75 min (× 1,2) —
  mostek → hangar promów ok. 10 min. W drodze widok to korytarz bez okien.
- **Prom** wsiada się tylko na Stanowisku obsługi promów Lambda; „Wyjdź do hangaru” stawia tam postać.
- **Okna** (`lokalizacje.okno`, MG zmienia w bazie): `przod` — okna mostka; `lewa` / `prawa` — boczne
  iluminatory (widok prostopadle do kursu; w nadprzestrzeni smugi lecą poziomo ku rufie: przez lewą
  burtę z prawej na lewo, przez prawą z lewej na prawo); `hangar` — wrota hangaru; `brak` — wnętrze bez
  okien. Przydział z migracji: Pokład 01 — przód, hangary — wrota, kwatery i sale oficerskie, mesy,
  kantyny, rekreacja, oddział szpitalny — burty na przemian, reszta bez okien.

## Pulpit, gwiazdy za oknem i skala mapy

- **Dostosuj pulpit** (mała ikonka w prawym dolnym rogu, tuż obok głośnika): × zdejmuje aplikację z pulpitu, „+ Dodaj”
  przywraca zdjęte. Układ zapisuje się w `ustawienia_kont` (klucz `pulpit`, `{ ukryte: [...] }`) i w kopii na urządzeniu.
  **Ustawienia** i **Przewodnik** to małe ikonki w lewym dolnym rogu doku, nie kafelki na pulpicie.
- **Gwiazdy za oknem**: najechanie (mysz) albo dotknięcie zaznacza gwiazdę celownikiem. Układ znany graczowi
  (`mapa_planeta_widoczna`) pokazuje nazwę, klasę i odległość. Układ nieznany (zastrzeżony) i każda gwiazda tła
  pokazuje „Nieznana gwiazda”, klasę, odległość i przycisk **Przeskanuj**, który podaje tylko liczbę planet. Prawdziwe
  układy wyglądają jak gwiazdy tła. Skan prawdziwego układu: `?a=gwiazda_skan` ze znacznikiem podpisanym kluczem
  sesji (`id.hmac`). Skan gwiazdy tła liczy się na tablecie i zawsze daje ten sam wynik. Wyniki zapamiętuje urządzenie.
  Gwiazdy zasłonięte ramą okna albo ikonami nie dają się zaznaczyć.
- **Mapa**: pasek skali w prawym górnym rogu (m, km, j.a., pc i lata świetlne). Przy największym zbliżeniu jest napis
  „zbliżenie maks. · 1:1”. Okręty większe od znacznika mają kształt swojej klasy (ISD, Arquitens, Lambda, Gozanti,
  YT-1300, Kom'rk, prom) w prawdziwej długości. Jednostki stojące w jednym punkcie ustawiają się w klin za prowadzącą,
  z odstępem 2,5 długości.
- Klik w tarczę planety wybiera planetę, nawet gdy na jej orbicie stoi flota. Na karcie planety jest
  **Skok na orbitę**, czyli mikroskok automatu z innego miejsca tego samego układu (bez wariantu „krawędź”).
- Linie kursu są przycinane do ekranu i rysowane także wtedy, gdy jednostka jest poza ekranem. Wcześniej przy części
  przybliżeń znikały.

## Pakiet poprawek z testów (2026-11-20)

- **Nawigacja na pokładzie**: jeden sposób chodzenia — lista pomieszczeń, skróty, ulubione i „Idź do promu” wysyłają ten
  sam rozkaz `poklad_idz`. Nowy cel w trakcie przejścia zastępuje poprzedni („Zmień cel”, „Zawróć”). Licznik nie jest już
  nadpisywany przez zegar kafelków pulpitu (`.kafel-czas[data-koniec]`). **Ulubione** pomieszczenia (★) są na górze listy
  i w skrótach, pełna lista zostaje; zapis w `ustawienia_kont` (klucz `ulubione_poklad`). Listy wyboru mają pełne, ciemne tło.
- **Pomieszczenia burtowe** (kajuty itp.) są zamknięte, bez widoku na zewnątrz. Okna ma tylko mostek, wrota — hangar.
- **Mapa**: flota w jednym miejscu to albo jeden znacznik floty, albo jej jednostki (bez zdublowanych trójkątów).
  W widoku układu gwiazda, planeta i księżyc to osobne cele (karta gwiazdy z celem w bezpiecznej odległości od niej);
  zaznaczenie rysuje się na wybranym ciele. Zoom i kadr mapy pamięta bieżące logowanie (sessionStorage, osobno dla konta;
  wylogowanie czyści). Odległości na kartach i „do celu” w locie liczą się na żywo z pozycji na linii lotu.
- **Mini-mapa układu**: kierunek planu skoku jak na serwerze (w układzie — w km od słońca); „Krawędź układu” pokazuje
  wyjście na brzegu układu, nie przy dalekiej orbicie. Bez zapisanego miejsca na orbicie okręt stoi po stronie
  przeciwnej do dziobu (jak w oknie). Po mikroskoku w układzie miejsce na orbicie wynika z kursu skoku (wcześniej zawsze 180°).
- **Okno**: księżyce planety za oknem w kierunkach z tego samego cyklu co mapa; wysokości orbit jak na mapie.
- **Lot podświetlny w układzie**: dozwolony także w studni grawitacyjnej; zaczyna się z miejsca na orbicie i kończy na
  orbicie celu od strony nadlotu. Trasa przez planetę, księżyc albo gwiazdę (3 promienie) jest odrzucana z komunikatem.
- **Panel MG → Stan postaci**: pozycja wybierana etapami: obszar (sektor z katalogu albo region) → planeta, flota albo
  okręt → lokacja / statek floty → pomieszczenie (`?a=mg_wnetrze`), z podsumowaniem ścieżki przed zapisem.
- **Nowi gracze**: „Dodaj gracza” z miejscem startowym i **zaproszeniem** — jednorazowy kod (`XXXX-XXXX`, 7 dni, w danych
  tylko hash). Gracz na ekranie logowania wybiera „Mam kod zaproszenia” i sam ustawia hasło (`?a=zaproszenie_aktywuj`).
  MG może wydać nowy kod (`mg_zaproszenie_nowe`). Lista kont pokazuje gotowość: konto, pozycja, przewodnik. Hasło w formularzu
  MG jest ukryte. **Przewodnik** pierwszego wejścia (4 kroki, pomiń / wznów) — stan w `ustawienia_kont` (klucz `przewodnik`).

## Skok: punkt skoku, przerwanie, dźwięki (2026-11-20)

- **Lot do punktu skoku** to prawdziwy przelot podświetlny w układzie (pola `lot_*`, km od słońca): po obrocie na kurs
  jednostka leci z miejsca na orbicie do punktu `PUNKT_SKOKU_KM` przed planetą, z bocznym odejściem tak, by ani przelot,
  ani droga skoku nie szły przez planetę (`floty_punkt_skoku_km`). Mapa i okno pokazują ten ruch.
- **Przerwij skok** w nadprzestrzeni: komputer przelicza wyjście awaryjne (`PRZERWANIE_OBLICZENIA_S` = 12 s), jednostka
  leci dalej po linii skoku i wychodzi zwykłym końcem skoku — z animacją i dźwiękiem wyjścia. Uszkodzenia od razu, paliwo
  za przebytą drogę. Przed wejściem w nadprzestrzeń: odwołanie bez skutków (lot do punktu skoku zatrzymuje się).
- **Dźwięki wejścia i wyjścia** grają tylko przy skoku, raz na skok (klucz = czas zmiany sceny). Scena starsza od ostatnio
  przyjętej jest ignorowana. Każdy koniec skoku zapisuje `przylot_utc` (także w pustej przestrzeni: scena „wyjście” bez planety).
- **Okno w locie podświetlnym w układzie**: gwiazda i planeta (celu albo najbliższa) w kierunkach i rozmiarach
  z prawdziwych odległości; tablet przesuwa okręt po linii lotu między odpowiedziami serwera, więc rosną płynnie.

## Karta postaci EotE (CV) i generator postaci

- System **Star Wars: Edge of the Empire**: cechy (Krzepa, Zwinność, Intelekt, Spryt, Siła Woli, Prezencja), umiejętności
  ogólne / walki / wiedzy, 8 gatunków, 6 karier, 18 specjalizacji, talenty (lista z poziomem i rangą, opisy własne — bez
  pełnych drzewek), zobowiązanie, motywacja, sprzęt i historia. Dane i reguły: `js/rdzen/eote.js`, wygląd CV: `css/cv.css`.
- **Panel MG → Generator postaci** (`gm/generator.js`): karta gracza (kreator z rachunkiem PD: koszty cech 10 × wartość,
  rang 5 × ranga, +5 spoza kariery, talentów 5 × poziom, dodatkowych specjalizacji; darmowe rangi kariery, specjalizacji
  i gatunku; ostrzeżenia o regułach — MG może je świadomie złamać) albo NPC: sługus (grupa), rywal, nemezis, z szablonami
  i „Losuj NPC”. Progi, pancerz i obronę liczy sam; wartości wpisane ręcznie wygrywają. „Szkic historii” wypełnia puste pola.
  Podgląd CV obok formularza.
- Zapis: gracz — klucz `eote` w karcie postaci (`?a=mg_eote_zapisz`, `uid`); gracz widzi CV w **Karcie postaci → CV**
  bez notatek MG. NPC — tabela `osoby_karta_eote` (**migracja 040**); „Zapisz jako nowy NPC” zakłada też podmiot i osobę.
  Dotychczasowe statystyki karty i cechy NPC (firmy, rekrutacja) zostają bez zmian.

## Mapa: zbliżenie przyciskami, księżyce, tajne planety (2026-11-20)

- **Zbliżenie przyciskami** (ikonki w doku przy otwartej mapie): +/− (×4) i progi **Galaktyka · Sektor · Układ · Planeta** z płynnym
  przelotem. Próg dotyczy zaznaczonego obiektu, a bez zaznaczenia — twojej pozycji (okręt na orbicie, punkt układu, lot).
- **Księżyce** na mapie mają stronę dzienną i nocną (od kierunku na słońce), jak planety.
- Dane mapy w pamięci tabletu są przypisane do konta: po zmianie konta bez przeładowania strony mapa pobiera planety od nowa
  (wcześniej nowe konto widziało planety zastrzeżone poprzedniego, np. MG).
- **Lot podświetlny** może kończyć się w studni grawitacyjnej; odrzucany jest tylko punkt wewnątrz ciała i trasa przez ciało.
- Przyciski obrotu floty: +15° +45° −15° −45° w jednym rzędzie.

## Stocznia naprawcza (Kuat), celownik ciał za oknem, lot w studni (2026-11-20)

- **Oddaj do naprawy** (migracja **041**, `stocznia_naprawy`): okręt na orbicie Kuat (planety w `STOCZNIE`, api/floty.php)
  — Nawigacja na pokładzie (kapitan) albo karta planety na mapie (dowódca floty). Każdy uszkodzony podzespół (reaktor,
  hipernapęd, osłony, kadłub) dostaje prognozę (losowanie 1) i niezależny czas rzeczywisty (losowanie 2), oba z wielkości
  okrętu i skali uszkodzeń. Stocznia ma 2 stanowiska równolegle, kolejka FIFO; przy pustej kolejce praca rusza od razu.
  Na pulpicie osobny kafelek na każdą pracę w toku (podzespół, prognoza; po jej przekroczeniu „opóźnienie”) i kafelek
  z prognozą całości. Po faktycznym końcu podzespół wraca do 100%. W doku okręt nie skacze, nie leci i nie ląduje;
  „Odbierz z doku” przerywa pozostałe prace (naprawione zostają naprawione).
- **Celownik za oknem** łapie także słońce tego układu, planetę i jej księżyce (cała tarcza): nazwa, rodzaj, odległość.
- **Faza planety** za oknem liczona z kierunku planeta → słońce (z daleka planeta nie wychodzi już cała czarna).
- **Lot podświetlny w studni**: start z orbity po stronie zwróconej ku celowi; księżyce mają na serwerze prawdziwą pozycję
  na orbicie planety macierzystej (wcześniej leżały w jej środku).

## Grafiki lokacji (wnętrza)

- **Panel MG → Grafiki lokacji** (`gm/grafiki.js`): biblioteka grafik z miniaturami i podglądem, wgrywanie własnych
  (PNG/JPG/WebP do 3 MB → `assets/wnetrza/wlasne/`, katalog musi być zapisywalny dla PHP), przypisania do pomieszczeń
  okrętów i lokacji na planetach oraz **reguły po nazwie** (fragment nazwy → grafika; pierwsza pasująca wygrywa,
  „Wczytaj domyślne reguły ISD” dodaje gotowe). Przypisanie do miejsca wygrywa z regułą, „Bez grafiki” wyłącza obie.
- Gracz widzi grafikę jako **tło pulpitu**, gdy postać jest w tym miejscu (w przejściu między pomieszczeniami — reguła
  „korytarz”). Dane: `datapad_dane` → `grafiki_lokacji` (bez migracji); API: `mg_grafiki`, `mg_grafiki_zapisz`,
  `mg_grafika_wgraj`; scena dostaje pole `grafika` (api/sceny.php, `grafika_miejsca`).
- Biblioteka wnętrz ISD (`assets/wnetrza/isd-*.svg`, lista: `lista.json`): postać stoi przy ścianie i patrzy na nią —
  każda grafika to **ściana na wprost** (turbowinda, stołówka, umywalnia, szatnia, reaktor z oknem na rdzeń, korytarz,
  kajuta, koszary, ściana hangaru, ambulatorium, cela, maszynownia, centrum kontroli, zbrojownia, magazyn). Ściany z oknem
  (`…-okno.svg`: iluminatory, okno obserwacyjne) mają przezroczysty otwór — za nim tablet rysuje prawdziwą scenę.
  Generator: `narzedzia/wnetrza_svg.py` (dopisz funkcję i wpis w `GRAFIKI`, uruchom).
- **Hangar** (pomieszczenie z oknem „hangar”): rama i wrota — zamknięte w nadprzestrzeni, otwarte poza nią (ok. 3 s ruchu).
  W ISD to szyb startowy w brzuchu okrętu: pokład dookoła, ściany szybu z poszycia, na dnie przestrzeń albo planeta.
- **Zbliżenie mapy**: małe ikonki w doku obok głośnika (+, −, galaktyka, sektor, układ, planeta), tylko przy
  otwartej mapie (`ctx.dok([...])` — aplikacja może dodać własne ikonki doku; znikają przy jej zamknięciu).

## Dostęp do pomieszczeń według stopnia; zoom wokół gracza

- Na okręcie wolno wejść wszędzie poza **prywatnymi kwaterami wyższych stopni** (kwatera kapitana, adiutanta,
  oficerów sztabowych, starszych oficerów, admirała). Stopień z rangi konta (`STOPNIE` w api/poklad.php: np. starszy
  porucznik 7, komandor porucznik 8, kapitan 10), kwatery z nazwy pomieszczenia (`KWATERY_STOPNI`). Kapitan okrętu
  i MG — bez ograniczeń. W Nawigacji zamknięte kwatery mają 🔒 i nie da się ich wybrać; serwer też odmawia.
- **Zoom**: punkt zbliżenia to zaznaczony obiekt, a bez zaznaczenia — moja postać / okręt (dosuwa się do środka).
  Zbliżanie zatrzymuje się, gdy gwiazda zajmuje ok. 40% ekranu (bez wjeżdżania w słońce); maks. zbliżenie ISD ~500 px.

## Nawigacja (nowy układ) i kariera Kadet

- **Nawigacja**: nagłówek „gdzie jestem” (na okręcie: pomieszczenie, pokład, okno) i zakładki: **Tutaj** (przejście w toku
  z licznikiem, Zmień cel / Zawróć, szybkie cele, bankomat, sklep), **Idź po okręcie** (wyszukiwarka, ulubione, pomieszczenia
  po pokładach — zwijane, dotknięcie = idź, 🔒 kwatery wyższych stopni) albo **Dokąd?** na planecie, **Statek** (start,
  lądowanie, skok, łączność, promy, stocznia) i **Statki i pojazdy**.
- **Karta EotE**: kariera **Kadet** (akademia floty: Przywództwo, Dyscyplina, Opanowanie, Negocjacje, Wiedza: Światy Jądra
  i Edukacja, Astronawigacja, Czujność) ze specjalizacją **Mówca** (Urok, Zastraszanie, Percepcja, Komputery) i talenty
  dowódcze: Natchniona przemowa, Głos dowodzenia, Autorytet, Oficer pokładowy, Dowódca polowy, Zgrana załoga, Taktyk floty,
  Mistrz retoryki.
- **Kariera floty ze stopniami**: postać zaczyna jako **Kadet**; stopień (`poziom_kariery`) nadaje MG albo przełożony —
  PD go nie podnoszą. Kadet → Chorąży → Porucznik (specjalizacja Oficer pokładowy) → Komandor porucznik → Komandor →
  Kapitan (specjalizacja Dowódca okrętu). Każdy poziom dokłada umiejętności kariery. MG może ustawić poziom ręcznie
  (`poziom_kariery`). CV pokazuje bieżący poziom. Generator: **Import / eksport karty (JSON)**.

## Inspekcja załogi i morale

- W Nawigacji (zakładka **Tutaj**) oficer (od chorążego; kapitan okrętu i MG zawsze) widzi załogę pomieszczenia (grupa
  z nazwy pomieszczenia: piloci i obsługa hangaru, operatorzy promieni ściągających, artylerzyści, technicy, załoga mostka,
  szturmowcy, medycy, straż więzienna, marynarze) i jej morale. **Inspekcja** trwa 15 min (wyjście z pomieszczenia
  przerywa), potem test Przywództwa z karty EotE (Prezencja + ranga Przywództwa, trudność 2; przy morale < 30 — 3;
  bez karty — pula średnia). Sukces: morale grupy w górę, 2–3 PD; porażka: morale w dół, 1 PD. PD trafiają do „PD zdobytych”
  karty EotE. Ta sama grupa na okręcie raz na 24 h. Raport z opisem rozmowy widać do zamknięcia.
- Dane: `datapad_dane` → `morale` (okręt → grupa → 0–100, start 60) i karta postaci (bez migracji). Panel MG → Stan postaci:
  karta **Morale załóg**. Grupy i wzorce nazw: `GRUPY_ZALOGI` w api/poklad.php.

## PD od MG; lądowanie promu

- **Generator postaci → Doświadczenie**: MG dodaje (albo odejmuje) PD zdobyte w grze; przy wybranej karcie zapis od razu.
  Widać PD zdobyte, razem ze startowymi, do wydania i stopień. PD z inspekcji trafiają do karty EotE (także w SQL).
- **Prom z hangaru**: rozkazuje mu dowódca floty okrętu macierzystego, kapitan tego okrętu albo konto z dostępem do promu
  lub okrętu (wcześniej tylko dowódca floty, w której prom musiał być). Lista lądowisk obejmuje też lądowiska znane
  z karty postaci (konta graczy mają tekstowe id, więc samo `znane_lokacje` ich nie obejmowało).
- Panel MG → Floty: przy każdej jednostce **Może lądować: Tak/Nie** (zapis z profilem napędu; serwer pilnuje flagi).

## Dźwięk pokładu

`js/rdzen/dzwiek.js` gra według sceny statku (ta sama, co tło):

- **wejście w skok** — `sound/hiper_wejscie.mp3`, potem w pętli `sound/hiper.mp3`; każda kopia startuje
  1 s przed końcem poprzedniej (płynne przenikanie, planowane w Web Audio co do milisekundy);
- **wyjście** (także przerwanie skoku) — pętla urwana, `sound/hiper_wyjscie.mp3`, zaraz po nim tło;
- **poza nadprzestrzenią** — `sound/mostek_isd.mp3` w pętli, odtwarzany strumieniowo (plik ma ~170 MB);
- ekran blokady — cisza. Dźwięk rusza po pierwszym dotknięciu ekranu (wymóg przeglądarek).

Ikonka głośnika w prawym dolnym rogu (na doku) wycisza wszystko; wybór zostaje na tablecie.
Pliki `sound/*.mp3` nie są w repozytorium (rozmiar, licencja) ani w pamięci service workera —
wgrywa się je ręcznie na serwer do `sound/`.

## Konta i bezpieczeństwo

- **Jedno konto** (identyfikator i klucz) działa w całym datapadzie. Zamiast trzech różnych
  logowań jest jedno.
- Hasła są przechowywane tylko jako hash (`password_hash`). MG nie widzi haseł, może je tylko
  ustawić na nowe.
- Dane kont, dokumentów i gry są przechowywane w bazie, poza katalogiem publicznych zasobów.
- Zapisy (harmonogram, zasoby, dekoder) wymagają zalogowania. Każde żądanie POST musi mieć
  nagłówek `X-Datapad: 1`, co chroni przed formularzami wysyłanymi z obcych stron.
- Dokument może wymagać poziomu uprawnień (1–9) i przynależności do frakcji.

## Uruchomienie lokalnie

Najpierw utwórz bazę SQL, wykonaj `api/schema.sql`, skopiuj
`api/konfiguracja-bazy.example.php` do `api/konfiguracja-bazy.php` i wpisz dane bazy.
Mapa jest już w `api/schema.sql`. Lokalną kopię bazy z serwera najłatwiej odtworzyć, importując
zrzut phpMyAdmin z `_kopia/`.

```bash
php -S 127.0.0.1:8080
# datapad: http://127.0.0.1:8080/     panel MG: http://127.0.0.1:8080/gm/
```

Przy pierwszym wejściu panel MG pozwala założyć konto MG. W panelu MG zakłada się konta graczy.

## Wdrożenie na b0r5.pl

1. Wgrać zawartość repo do korzenia domeny, **bez ruszania `/_/` i `/__/`**. Nie wgrywać
   `_kopia/` (zrzuty bazy z hashami haseł).
2. Utworzyć bazę MySQL 8.0+ lub MariaDB 10.6+ i włączyć rozszerzenie PHP PDO MySQL.
3. Utworzyć plik `api/konfiguracja-bazy.php` na serwerze, kopiując
   `api/konfiguracja-bazy.example.php`; wpisać host, nazwę bazy, użytkownika i hasło z panelu.
   Plik jest wykluczony z gita. Nie umieszczać go w publicznym repozytorium.
4. **Tylko dla nowej, pustej bazy:** wykonać `api/schema.sql` (zawiera mapę). Działającej bazy nie
   ruszać — aktualizuje się ją wyłącznie kolejnymi migracjami, po kopii zapasowej.
   Zmiany w działającej bazie: phpMyAdmin → Eksport (kopia do `_kopia/`), potem phpMyAdmin →
   Import pliku nowej migracji z `api/migracje/`. Migracja zapisuje się w tabeli `schema_migracje`.
5. Wejść na `/gm/`, założyć konto MG, potem konta graczy.
6. Na tabletach: Chrome → „Dodaj do ekranu głównego”. Uruchomiony z ikony datapad działa na
   pełnym ekranie, bez paska przeglądarki.
7. Po zmianie plików systemu podbić `WERSJA` w `sw.js`, żeby tablety pobrały nowe pliki.

Stare adresy (`/calendar/`, `/frakcja/`, `/dekoder/`, `/recruitment/`) przestaną być potrzebne.
Warto ustawić przekierowania na `/`.

## Czcionka Aurebesh

`assets/czcionki/aurebesh.otf` jest generowana z kształtów zapisanych w
`narzedzia/czcionka/generuj.js` (`npm install && node generuj.js`). **Kształty są przybliżeniem
Aurebesha i trzeba je sprawdzić z tablicą znaków.** Można też po prostu podmienić plik na inną
czcionkę Aurebesh (z odpowiednią licencją), pod tą samą nazwą. Polskie litery z ogonkami
dostają kształt litery podstawowej.

## Do decyzji

- **CDEX** (`b0r5.beep.pl`, po HTTP): nie trafił do datapada. Ramka po HTTP na stronie HTTPS
  jest blokowana przez przeglądarkę.
- **LARP** (`/larp/`, zapisy na wydarzenie): to strona dla prawdziwych ludzi (dane kontaktowe,
  terminy), więc nie pasuje do datapada. Może zostać osobno albo zniknąć, bo termin minął.
- **Transmisja na żywo** pokazuje odtwarzacz Twitcha z jego logo. To mały wyłom w immersji.
- **Logowanie Google** dla MG zostało zastąpione zwykłym kontem z rolą MG.
- **DataPad (OLD/NEW)**: nie było jak zajrzeć do jego zawartości, więc nie został przeniesiony.

## Schemat SQL v8 — napędy Arquitens i Gozanti

Stan v8 odpowiada migracjom do 008 (pełny schemat: `api/schema.sql`). Do działającej bazy po migracji 007 zaimportuj wyłącznie `api/migracje/008_profile_napedu_arquitens_gozanti.sql`. Migracja dopisuje brakujące profile napędu dla sześciu Gozanti i dwóch Arquitensów; istniejących ustawień napędu nie nadpisuje. Usunięcie floty w panelu MG zwalnia jej okręty i podgrupy, a same jednostki oraz historię ich rozkazów pozostawia w bazie.

## Schemat SQL v9 — flota Dromund Kaas i kapitanowie NPC

Stan v9 odpowiada migracjom do 009 (pełny schemat: `api/schema.sql`). Działającą bazę po migracji 008 zaktualizuj przez import `api/migracje/009_flota_dromund_kaas.sql`. Ta migracja dodaje planetę Dromund Kaas, kapitana NPC Rhalena Vosska i flotę ISD I „Legacy”, dwa Arquitensy oraz sześć Gozanti z czterema standardowymi TIE każda.

## Schemat SQL v10 — radar planetarny i bitwy

Stan v10 odpowiada migracjom do 010 (pełny schemat: `api/schema.sql`). Bazę z wcześniejszym schematem aktualizuj przez import `api/migracje/010_radar_bitwy_i_swoboda.sql`. Migracja dodaje status swobody postaci, rejestr aktywnych bitew wraz z uczestnikami i stronami oraz pole `ukryty_za_ksiezycem` zarezerwowane na później. Panel MG ma zakładkę „Bitwy / radar” do rozpoczynania i kończenia starć oraz przypisywania uczestników i stron. Swoboda postaci jest ustawiana w zakładce „Mapa”. Swobodna postać na planecie widzi jej jednostki orbitalne; podczas bitwy widzi na mapie uczestników wszystkich stron. Więzień i postać nieprzytomna nie otrzymują tych dodatkowych danych. Pole ukrycia za księżycem nie jest jeszcze używane. Mapa odświeża pozycje co 15 sekund.

## Schemat SQL v11 — okręty jako lokacje

Stan v11 odpowiada migracjom do 011 (pełny schemat: `api/schema.sql`). Bazę v10 aktualizuj przez import `api/migracje/011_okrety_jako_lokacje.sql`. W panelu MG, przy ustawianiu pozycji, lokacją można wybrać okręt albo flotę; flota ustawia postać na swoim pierwszym okręcie (domyślnie flagowym). Można też wskazać NPC z SQL. Pozycje graczy i NPC na pokładzie podążają za okrętem na mapie.

## Schemat SQL v12 — dostęp do planet zastrzeżonych

Stan v12 odpowiada migracjom do 012 (pełny schemat: `api/schema.sql`). Bazę v11 aktualizuj migracją `api/migracje/012_planety_widocznosc_konta.sql`. Zastrzeżone planety są zwracane na mapie i w API tylko wskazanym loginom oraz MG.

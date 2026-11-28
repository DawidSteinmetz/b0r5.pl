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
api/schema.sql           schemat bazy danych (MySQL 8 / MariaDB 10.6)
api/mapa_seed.sql        dane startowe planet i szlaków w postaci poleceń SQL
api/dane/                źródło importu z wcześniejszych wersji (poza gitem)
gm/                      panel MG: konta, dokumenty, holonet, frakcje, harmonogram
assets/czcionki/         czcionka Aurebesh
narzedzia/czcionka/      generator czcionki Aurebesh
```

Bez frameworków i bez budowania. Pliki trafiają na serwer w takiej postaci, w jakiej są w repo.

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
- **Czas:** podróże liczone są w minutach świata. Licznik na tablecie biegnie w tempie z katalogu
  (`tempo` = sekundy rzeczywiste na minutę świata; 1 = 30-minutowy przejazd trwa 30 s).
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
Następnie uruchom `php api/importuj_mape.php`. Jeśli przenosisz istniejącą instalację, uruchom również
`php api/importuj_dane.php` przed pierwszym wejściem graczy.

```bash
php -S 127.0.0.1:8080
# datapad: http://127.0.0.1:8080/     panel MG: http://127.0.0.1:8080/gm/
```

Przy pierwszym wejściu panel MG pozwala założyć konto MG. W panelu MG zakłada się konta graczy.

## Wdrożenie na b0r5.pl

1. Wgrać zawartość repo do korzenia domeny, **bez ruszania `/_/` i `/__/`**.
2. Utworzyć bazę MySQL 8.0+ lub MariaDB 10.6+ i włączyć rozszerzenie PHP PDO MySQL.
3. Utworzyć plik `api/konfiguracja-bazy.php` na serwerze, kopiując
   `api/konfiguracja-bazy.example.php`; wpisać host, nazwę bazy, użytkownika i hasło z panelu.
   Plik jest wykluczony z gita. Nie umieszczać go w publicznym repozytorium.
4. Wykonać `api/schema.sql`, a następnie uruchomić `php api/importuj_mape.php` z katalogu repo.
   Przy przenoszeniu istniejącej instalacji uruchomić także `php api/importuj_dane.php`.
   Po sprawdzeniu importu wykonaj kopię zapasową, a potem usuń stare pliki serwerowe.
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

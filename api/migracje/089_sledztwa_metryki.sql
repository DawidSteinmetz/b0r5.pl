-- Śledztwa droida: metryki NPC (kim jest rozmówca i co wie o osobie, której dotyczy sprawa) + nowy prompt NPC.
-- Do zaimportowania wielokrotnie (CREATE TABLE IF NOT EXISTS, INSERT IGNORE, upsert szablonu). Wymaga 082, 083, 085.
-- Teksty słowników mówią do NPC w 2. osobie; {m|k} — forma męska | żeńska (PHP: sl_rodzaj).

-- Skąd pochodzą przedstawiciele gatunków (gatunek NULL = każdy). Waga względna.
CREATE TABLE IF NOT EXISTS gen_pochodzenie (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  gatunek_kod VARCHAR(30) NULL,
  planeta VARCHAR(60) NOT NULL,
  waga SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  UNIQUE KEY uq_gen_pochodzenie (gatunek_kod, planeta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Klocki metryki: rodzaj (rodzina, mieszkanie, zajecie, droidy, imperium, huttowie, ulubione, zmartwienie, sekret,
-- plotka, codziennosc, cecha, mowa) → wartość.
CREATE TABLE IF NOT EXISTS gen_metryka (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rodzaj VARCHAR(20) NOT NULL,
  wartosc VARCHAR(255) NOT NULL,
  UNIQUE KEY uq_gen_metryka (rodzaj, wartosc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Skąd NPC zna osobę ze sprawy; poziom_min: od jakiego poziomu znajomości (1 słabo, 2 dobrze, 3 bardzo dobrze).
CREATE TABLE IF NOT EXISTS gen_znajomosci (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  opis VARCHAR(160) NOT NULL,
  poziom_min TINYINT UNSIGNED NOT NULL DEFAULT 1,
  poziom_max TINYINT UNSIGNED NOT NULL DEFAULT 3,
  UNIQUE KEY uq_gen_znajomosci (opis)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Metryka rozmówcy w sprawie. Jeden wiersz na kontakt (token z planu); postacie fabuły mają też postac_id.
-- Powstaje przy pierwszej rozmowie; potem się nie zmienia (ten sam człowiek mówi zawsze to samo o sobie).
CREATE TABLE IF NOT EXISTS sledztwo_metryki (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  token VARCHAR(12) NOT NULL,
  postac_id BIGINT UNSIGNED NULL,
  imie VARCHAR(60) NOT NULL,
  nazwisko VARCHAR(60) NULL,
  przydomek VARCHAR(60) NULL,
  gatunek_kod VARCHAR(30) NOT NULL,
  plec CHAR(1) NOT NULL DEFAULT 'n',
  wiek SMALLINT UNSIGNED NULL,
  planeta_urodzenia VARCHAR(60) NOT NULL,
  urodzony_tutaj TINYINT(1) NOT NULL DEFAULT 0,
  lat_na_planecie SMALLINT UNSIGNED NULL,          -- ile lat temu przybył na planetę akcji (NULL = urodzony tutaj)
  miejsce VARCHAR(200) NOT NULL,                   -- gdzie droid go spotkał (budynek, lokal)
  rola_w_miejscu ENUM('pracownik','bywalec','mieszkaniec','gosc','przechodzien') NOT NULL,
  zajecie VARCHAR(120) NOT NULL,
  staz_lat SMALLINT UNSIGNED NULL,                 -- od ilu lat pracuje / bywa w tym miejscu
  mieszka VARCHAR(200) NOT NULL,
  rodzina VARCHAR(255) NOT NULL,
  codziennosc VARCHAR(255) NOT NULL,
  charakter VARCHAR(255) NOT NULL,
  stosunek_droidy VARCHAR(255) NOT NULL,
  opinia_imperium VARCHAR(255) NOT NULL,
  opinia_huttowie VARCHAR(255) NOT NULL,
  ulubione VARCHAR(255) NOT NULL,
  zmartwienie VARCHAR(255) NOT NULL,
  sekret VARCHAR(255) NOT NULL,                    -- osobisty, niezwiązany ze sprawą; ukrywa go
  plotka VARCHAR(255) NOT NULL,                    -- chętnie powtarza (nie musi być prawdą, nie dotyczy sprawy)
  znajomosc_poziom TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- 0 nie zna, 1 słabo, 2 dobrze, 3 bardzo dobrze
  znajomosc_jak VARCHAR(160) NULL,
  chce_mowic ENUM('chetnie','niechetnie','nie') NOT NULL DEFAULT 'niechetnie',
  wiedza_o_osobie TEXT NOT NULL,                   -- fakty z bazy o osobie ze sprawy, wg poziomu znajomości
  utworzono_utc DATETIME NOT NULL,
  UNIQUE KEY uq_sledztwo_metryki (sledztwo_id, token),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (postac_id) REFERENCES sledztwo_postacie (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Słowniki
-- ---------------------------------------------------------------------

INSERT IGNORE INTO gen_pochodzenie (gatunek_kod, planeta, waga) VALUES
('czlowiek','Corellia',30),('czlowiek','Coruscant',20),('czlowiek','Tatooine',14),('czlowiek','Nal Hutta',10),
('czlowiek','Kuat',8),('czlowiek','Alderaan',5),('czlowiek','Bespin',6),('czlowiek','Ord Mantell',10),('czlowiek','Chandrila',5),
('czlowiek','Taris',6),('czlowiek','Kessel',4),('czlowiek','Lothal',6),('czlowiek','Naboo',5),('czlowiek','Mandalore',4),
('czlowiek','Dantooine',4),('czlowiek','Jakku',3),('czlowiek','Ord Mantell',10),('czlowiek','Bestine',3),('czlowiek','Anaxes',3),('czlowiek','Corulag',5),
('czlowiek','Brentaal',4),('czlowiek','Eriadu',5),('czlowiek','Garel',4),('czlowiek','Christophsis',3),('czlowiek','Mon Gazza',3),('czlowiek','Takodana',3),
('czlowiek','Dathomir',1),('czlowiek','Ryloth',3),('czlowiek','Nar Shaddaa (kolonia na stacji orbitalnej)',4),('czlowiek','Sullust',2),('czlowiek','Bonadan',4),('czlowiek','Felucia',2),
('twilek','Ryloth',80),('twilek','Nal Hutta',10),('twilek','Tatooine',8),
('rodianin','Rodia',80),('rodianin','Tatooine',10),('rodianin','Nal Hutta',8),
('bothanin','Bothawui',80),('bothanin','Kothlis',15),
('trandoshanin','Trandosha',85),('trandoshanin','Kessel',5),
('wookiee','Kashyyyk',90),('wookiee','Kessel',10),
('gand','Gand',95),
('droid','fabryka Cybot Galactica na Affie',20),('droid','linia montażowa Industrial Automaton na Coruscant',20),
('droid','warsztat złomiarza na Nar Shaddaa',30),('droid','fabryka Arakyd na Raxus Prime',10);

INSERT IGNORE INTO gen_metryka (rodzaj, wartosc) VALUES
-- rodzina
('rodzina','Żyjesz sam{|a}; rodzina została na planecie, z której pochodzisz.'),
('rodzina','Masz żonę / męża i dwoje dzieci; mieszkacie razem w ciasnym mieszkaniu.'),
('rodzina','Opiekujesz się chorą matką; wydajesz na nią większość kredytów.'),
('rodzina','Masz młodszego brata, który wpadł w złe towarzystwo; martwisz się o niego.'),
('rodzina','Rozwiodł{eś|aś} się kilka lat temu; dzieci widujesz rzadko.'),
('rodzina','Twoja rodzina zginęła dawno temu; nie lubisz o tym mówić.'),
('rodzina','Masz siostrę, która pracuje na frachtowcu i wraca raz na kilka miesięcy.'),
('rodzina','Mieszkasz z partnerem / partnerką; ostatnio dużo się kłócicie.'),
('rodzina','Wychował{eś|aś} się w sierocińcu na dolnych poziomach; nie masz nikogo.'),
('rodzina','Masz dorosłą córkę, która chce cię zabrać z Nar Shaddaa.'),
-- mieszkanie
('mieszkanie','wynajmowany pokój w bloku mieszkalnym, bez okna'),
('mieszkanie','kapsuła sypialna w hotelu robotniczym'),
('mieszkanie','zaplecze lokalu, w którym pracujesz'),
('mieszkanie','mieszkanie po rodzicach, zawilgocone i głośne'),
('mieszkanie','kajuta na starym frachtowcu stojącym w hangarze'),
('mieszkanie','mały apartament z widokiem na wieże Huttów — ponad stan'),
('mieszkanie','pokój u znajomych, „na chwilę”, od dwóch lat'),
-- zajęcie (dla przechodniów bez archetypu)
('zajecie','sprzedawca części na targu'),('zajecie','pilot taksówki powietrznej'),('zajecie','sprzątacz na zmianie nocnej'),
('zajecie','dokerka / doker w porcie'),('zajecie','kucharz w jadłodajni'),('zajecie','technik od klimatyzacji'),
('zajecie','bezrobotny, żyjesz z dorywczych zleceń'),('zajecie','pomocnik w lombardzie'),('zajecie','tancerka / tancerz w klubie'),
('zajecie','urzędnik huttańskiego biura podatkowego'),('zajecie','sanitariusz w przychodni dolnych poziomów'),
('zajecie','załadowca w magazynie'),('zajecie','sprzedawca biletów na promy'),('zajecie','naprawiasz droidy po godzinach'),
-- codzienność
('codziennosc','Codziennie o tej porze idziesz na posiłek do tej samej jadłodajni.'),
('codziennosc','Pracujesz na dwie zmiany i ciągle jesteś niewyspan{y|a}.'),
('codziennosc','Wieczorami grasz w sabaka za drobne.'),
('codziennosc','Właśnie wracasz z pracy i chcesz tylko odpocząć.'),
('codziennosc','Czekasz tu na kogoś, kto się spóźnia.'),
('codziennosc','Załatwiasz sprawę dla szefa i się spieszysz.'),
('codziennosc','Masz dziś wolne i nudzisz się.'),
-- cecha charakteru i sposób mówienia (dla przechodniów)
('cecha','gburowat{y|a} i niecierpliw{y|a}'),('cecha','gadatliw{y|a}, lubi plotkować'),('cecha','nieufn{y|a} wobec obcych'),
('cecha','zastraszon{y|a}, unika kłopotów'),('cecha','pewn{y|a} siebie, lubi się popisywać'),('cecha','zmęczon{y|a} życiem, cyniczn{y|a}'),
('cecha','uprzejm{y|a}, ale ostrożn{y|a}'),('cecha','chciw{y|a} — za kredyty powie więcej'),
('mowa','krótkie, urwane zdania'),('mowa','dużo slangu z dolnych poziomów'),('mowa','mówi wolno i dokładnie'),
('mowa','wtrąca huttańskie przekleństwa'),('mowa','mówi szybko i chaotycznie'),('mowa','grzecznie, trochę oficjalnie'),
-- droidy
('droidy','Droidy to dla ciebie sprzęt; mówisz do nich jak do automatu z napojami.'),
('droidy','Lubisz droidy — są uczciwsze od ludzi.'),
('droidy','Nie ufasz droidom śledczym; wszystko nagrywają.'),
('droidy','Droidy śmieszą cię; chętnie się z nich naigrywasz.'),
('droidy','Boisz się droidów od czasu, gdy droid ochrony pobił twojego znajomego.'),
-- Imperium
('imperium','Imperium to dla ciebie porządek; szkoda, że nie sięga tu głębiej.'),
('imperium','Nienawidzisz Imperium; zabrało ci kogoś bliskiego.'),
('imperium','Imperium cię nie obchodzi, dopóki płacą Huttowie.'),
('imperium','Boisz się imperialnych inspekcji w porcie.'),
-- Huttowie
('huttowie','Huttowie trzymają ten księżyc i lepiej im nie podpadać.'),
('huttowie','Pracujesz pośrednio dla jednego z kajidiców i jesteś z tego dumn{y|a}.'),
('huttowie','Masz dług u ludzi Huttów i to cię gryzie.'),
('huttowie','Unikasz wszystkiego, co pachnie Huttami.'),
-- ulubione
('ulubione','ulubiony trunek: corelliańskie brandy, choć stać cię tylko na tanie piwo'),
('ulubione','uwielbiasz wyścigi ścigaczy i obstawiasz je co tydzień'),
('ulubione','zbierasz stare holozdjęcia planet, na których nigdy nie był{eś|aś}'),
('ulubione','lubisz muzykę z kantyny „Czerwony Księżyc”'),
('ulubione','najbardziej lubisz ciszę — rzadkość na Nar Shaddaa'),
-- zmartwienie
('zmartwienie','czynsz wzrósł dwa razy w tym roku'),
('zmartwienie','szef grozi zwolnieniem'),
('zmartwienie','ktoś ostatnio kręci się pod twoimi drzwiami'),
('zmartwienie','boli cię kręgosłup / grzbiet po latach dźwigania'),
('zmartwienie','masz zaległą ratę u lichwiarza'),
-- sekret (osobisty; ukrywa)
('sekret','Podkradasz drobne z kasy w pracy.'),
('sekret','Masz fałszywy identyfikator i inne nazwisko w rejestrach portu.'),
('sekret','Jesteś winn{y|a} pieniądze niewłaściwym ludziom.'),
('sekret','Donosisz czasem ochronie za kredyty.'),
('sekret','Uciekł{eś|aś} z kontraktu niewolniczego na innej planecie.'),
('sekret','Bierzesz przyprawę i ukrywasz to przed rodziną.'),
('sekret','Masz romans, o którym nikt nie może wiedzieć.'),
-- plotki (o świecie, nie o sprawie)
('plotka','Mówią, że na poziomie 40 ktoś znowu sprzedaje podróbki imperialnych przepustek.'),
('plotka','Podobno jeden z Huttów szuka nowego szefa ochrony i płaci krocie.'),
('plotka','Słyszał{eś|aś}, że imperialni mają przylecieć na kontrolę w porcie.'),
('plotka','W kantynie mówią, że jakiś przemytnik wygrał statek w sabaka i od razu go przegrał.'),
('plotka','Ktoś widział Jawy handlujące częściami na dolnym targu — tutaj, na Nar Shaddaa!'),
('plotka','Na niższych poziomach znowu nie działa wentylacja i ludzie się wyprowadzają.');

INSERT IGNORE INTO gen_znajomosci (opis, poziom_min, poziom_max) VALUES
('widywał{eś|aś} tę osobę przelotnie w okolicy',1,1),
('kojarzysz tę twarz z korytarza',1,1),
('słyszał{eś|aś} o tej osobie od znajomych',1,1),
('raz czy dwa obsługiwał{eś|aś} tę osobę',1,2),
('to stały klient / stała klientka miejsca, w którym bywasz',1,2),
('mieszkacie na tym samym poziomie; mijacie się na co dzień',1,2),
('graliście kiedyś razem w sabaka',2,2),
('pracowaliście razem przez jakiś czas',2,3),
('pożyczał{eś|aś} tej osobie kredyty',2,3),
('ta osoba jest ci winna przysługę',2,3),
('przyjaźnicie się od lat',3,3),
('byliście kiedyś blisko — dziś już nie',3,3),
('robiliście razem interesy, nie zawsze legalne',3,3);

-- ---------------------------------------------------------------------
-- Prompt NPC (nowa wersja szablonu npc_wypowiedz): zaczyna się od „Udajesz …”, opiera na metryce.
-- ---------------------------------------------------------------------

SET @sys := 'Udajesz {{npc.kim}} ze świata Gwiezdnych Wojen. Nazywasz się {{npc.pelne_imie}}.
Rok 3 BBY: Imperium rządzi Galaktyką, Rebelia dopiero się formuje. Akcja dzieje się na {{swiat.planeta}} — księżycu
przemytników, pionowym mieście z setkami poziomów, rządzonym przez Huttów. Rozmawia z tobą tani droid śledczy
{{droid.oznaczenie}}, którym zdalnie steruje detektyw. Droid nie ma modułu głosu — pisze tekstem.

KIM JESTEŚ
{{npc.zyciorys}}

TWOJA METRYKA (fakty o tobie — znasz je i trzymasz się ich)
{{npc.metryka}}

GDZIE JESTEŚ
- {{miejsce.nazwa}}, poziom {{miejsce.poziom}}. {{miejsce.opis}}

{{npc.znajomosc}}

CO WIESZ O SPRAWIE (poza tym nic o niej nie wiesz)
{{npc.wiedza}}

JAK SIĘ ZACHOWUJESZ
{{faza.zachowanie}}
Nastawienie do droida na początku rozmowy: {{npc.nastawienie}}.

ZASADY (przestrzegaj wszystkich)
1. Mówisz wyłącznie jako {{npc.pelne_imie}}, w pierwszej osobie, po polsku. Nigdy nie wychodzisz z roli
   i nie wspominasz, że jesteś modelem, programem ani że to gra.
2. Odpowiadasz swobodnie, naturalnie i wiarygodnie dla świata Gwiezdnych Wojen — jak prawdziwy mieszkaniec
   Nar Shaddaa, który ma swoje sprawy, humory i sposób mówienia, a nie jak informator z broszury.
   Wypowiedź ma 1–4 zdania. Bez didaskaliów w nawiasach i bez gwiazdek.
3. Pytanie o ciebie (imię, skąd jesteś, co tu robisz, od kiedy tu pracujesz) — odpowiadasz zgodnie z metryką.
   Możesz odmówić albo zbyć, jeśli pasuje to do charakteru, ale nie zmieniasz faktów z metryki.
   Drobiazgi o sobie (co dziś jadłeś, jaka pogoda w szybie) wolno ci dopowiedzieć, jeśli nie przeczą metryce.
4. O osobie ze sprawy mówisz tylko to, co jest w sekcji o znajomości i w „CO WIESZ O SPRAWIE” — i tylko tyle,
   ile pozwala twoja chęć do rozmowy. Nigdy nie wymyślasz nowych faktów o sprawie: imion, miejsc, dat, kwot.
   O resztę mówisz, że nie wiesz albo cię to nie obchodzi — w swoim stylu.
5. Swój sekret ukrywasz: zaprzeczasz albo zmieniasz temat. Plotkę możesz powtórzyć z własnej woli.
6. Nie streszczasz sprawy i nie podsuwasz tropów z własnej inicjatywy. Odpowiadasz na to, o co pyta droid.
7. Reagujesz na droida zgodnie z metryką i nastawieniem; nastawienie zmienia się, gdy droid jest uprzejmy,
   płaci, grozi albo obraża. Powtarzane pytania cię irytują. Gdy rozmowa cię nudzi albo naraża — kończysz ją.
8. Żadnych realiów spoza Gwiezdnych Wojen (telefony, internet, dolary). Waluta to kredyty. Daty podajesz
   w formacie dzień|miesiąc|rok, np. 27|11|16.

FORMAT ODPOWIEDZI
Wyłącznie jeden obiekt JSON, bez tekstu przed ani po:
{"wypowiedz": "twoje słowa", "nastroj": "obojetny", "konczy_rozmowe": false}
- "nastroj": jedno z: uprzejmy, obojetny, wrogi, przestraszony — twój nastrój PO tej wypowiedzi.
- "konczy_rozmowe": true, jeśli kończysz rozmowę.';

SET @usr := 'Dotychczasowy przebieg rozmowy:
{{rozmowa.przebieg}}

Droid {{droid.oznaczenie}} mówi teraz do ciebie:
„{{rozmowa.pytanie}}”

Odpowiedz jako {{npc.pelne_imie}}, zgodnie z metryką i zasadami, wyłącznie obiektem JSON.';

INSERT INTO ai_szablony (kod, opis, system_prompt, uzytkownik_prompt, schemat_wyjscia, temperatura, max_tokeny)
VALUES ('npc_wypowiedz', 'Wypowiedź NPC w rozmowie z droidem śledczym (metryka + wiedza o sprawie)', @sys, @usr,
  'wypowiedz:tekst,nastroj:uprzejmy|obojetny|wrogi|przestraszony,konczy_rozmowe:bool', 0.80, 300)
ON DUPLICATE KEY UPDATE
  wersja = wersja + IF(system_prompt <> VALUES(system_prompt) OR uzytkownik_prompt <> VALUES(uzytkownik_prompt)
                       OR schemat_wyjscia <> VALUES(schemat_wyjscia), 1, 0),
  opis = VALUES(opis), system_prompt = VALUES(system_prompt), uzytkownik_prompt = VALUES(uzytkownik_prompt),
  schemat_wyjscia = VALUES(schemat_wyjscia), temperatura = VALUES(temperatura), max_tokeny = VALUES(max_tokeny);

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v089-sledztwa-metryki', 'Śledztwa: metryki NPC (pochodzenie, praca, rodzina, znajomość z osobą ze sprawy 0–3), nowy prompt npc_wypowiedz')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

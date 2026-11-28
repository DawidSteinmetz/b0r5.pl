-- Śledztwa droida detektywistycznego (narzedzia/sledztwa/PROJEKT.md). Każde śledztwo jest unikatem na wspólnym
-- szkielecie 12 faz. Typ = prawdziwe zdarzenie × relacja (12 × 12 = 144); gracz zdobywa każdy typ najwyżej raz.
-- Czynnik jawności: sprawa i każda faza są jawne albo ukryte (gracza wezwano do X, a naprawdę zaszło Y; pościg jawny
-- = list gończy, ukryty = ciche śledzenie). Jawność zmienia zachowanie NPC.
-- Kolejność tworzenia: (1) losowanie z bazy — imiona, nazwiska, gatunki, archetypy, karty EotE, ekwipunek, miejsca;
-- (2) model „Autor” pisze treść do wylosowanego szkieletu; (3) w grze model „Droid” przeszukuje treść i rozmawia z NPC.
-- Konta detektywów: sledztwo_detektywi (po loginie). Wymaga 002 (towary), 030 (lokalizacje Nar Shaddaa).
-- Import w phpMyAdmin; ponowny import niczego nie psuje.

USE hololink;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 0. KONTA DETEKTYWÓW
-- ---------------------------------------------------------------------

-- Konto z wpisem tutaj po zalogowaniu dostaje pulpit sterowania droidem zamiast zwykłego pulpitu.
CREATE TABLE IF NOT EXISTS sledztwo_detektywi (
  konto_login VARCHAR(64) NOT NULL PRIMARY KEY,
  droid_oznaczenie VARCHAR(40) NOT NULL DEFAULT 'DT-4',
  droid_karta_eote MEDIUMTEXT NULL,               -- format jak osoby_karta_eote.dane (gatunek „droid”)
  nadano TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. SŁOWNIKI FABUŁY (wspólne; MG może edytować)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sledztwo_zdarzenia (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY CHECK (id BETWEEN 1 AND 12),
  nazwa VARCHAR(60) NOT NULL,
  opis TEXT NULL,
  wskazowki_autora TEXT NULL                      -- dopisek do promptu bazowego (ton, granice treści)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sledztwo_relacje (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY CHECK (id BETWEEN 1 AND 12),
  nazwa VARCHAR(60) NOT NULL,
  opis TEXT NULL,
  rola_a VARCHAR(80) NOT NULL,                    -- kim jest A (osoba, wokół której toczy się sprawa)
  rola_b VARCHAR(80) NOT NULL                     -- kim jest B (druga strona relacji)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Szkielet 12 faz. przestawna = 1 (4–10): generator może zmienić kolejność, jeśli tagi się zgadzają.
-- npc_jawna / npc_ukryta: jak zachowują się NPC, gdy ta faza jest jawna albo ukryta (trafia do promptów NPC).
CREATE TABLE IF NOT EXISTS sledztwo_fazy_szablon (
  nr TINYINT UNSIGNED NOT NULL PRIMARY KEY CHECK (nr BETWEEN 1 AND 12),
  nazwa VARCHAR(80) NOT NULL,
  przestawna TINYINT(1) NOT NULL DEFAULT 0,
  npc_jawna TEXT NULL,
  npc_ukryta TEXT NULL,
  prompt_bazowy MEDIUMTEXT NULL                   -- drobiazgowa instrukcja dla modelu Autor
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12 wariantów każdej fazy (rzut k12) z tagami spójności. Tag w „wymaga” musi być dany przez wcześniejszą fazę.
CREATE TABLE IF NOT EXISTS sledztwo_warianty (
  faza_nr TINYINT UNSIGNED NOT NULL,
  nr TINYINT UNSIGNED NOT NULL CHECK (nr BETWEEN 1 AND 12),
  nazwa VARCHAR(120) NOT NULL,                    -- np. „Nieudana kradzież danych kont”
  szkic TEXT NOT NULL,                            -- 1–2 zdania z {A} {B} {Z} {S} {M} dla Autora
  wymaga VARCHAR(255) NOT NULL DEFAULT '',        -- tagi oddzielone przecinkami
  daje VARCHAR(255) NOT NULL DEFAULT '',
  jawnosc ENUM('dowolna','jawna','ukryta') NOT NULL DEFAULT 'dowolna',
  PRIMARY KEY (faza_nr, nr),
  FOREIGN KEY (faza_nr) REFERENCES sledztwo_fazy_szablon (nr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. PULE DO LOSOWANIA (krok 1: baza danych czyni śledztwo wyjątkowym)
-- ---------------------------------------------------------------------

-- Gatunki: cechy startowe jak w js/rdzen/eote.js (GATUNKI); kod zgodny z kartą EotE.
CREATE TABLE IF NOT EXISTS gen_gatunki (
  kod VARCHAR(30) NOT NULL PRIMARY KEY,
  nazwa VARCHAR(60) NOT NULL,
  krzepa TINYINT NOT NULL, zwinnosc TINYINT NOT NULL, intelekt TINYINT NOT NULL,
  spryt TINYINT NOT NULL, wola TINYINT NOT NULL, prezencja TINYINT NOT NULL,
  rany TINYINT NOT NULL, wysilek TINYINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kto mieszka na planecie i jak często (waga względna).
CREATE TABLE IF NOT EXISTS gen_populacja (
  planeta_id INT UNSIGNED NOT NULL,
  gatunek_kod VARCHAR(30) NOT NULL,
  waga SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (planeta_id, gatunek_kod),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id),
  FOREIGN KEY (gatunek_kod) REFERENCES gen_gatunki (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Imiona, nazwiska, przydomki. gatunek_kod NULL = pasuje do każdego gatunku; plec 'n' = dowolna.
CREATE TABLE IF NOT EXISTS gen_imiona (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  gatunek_kod VARCHAR(30) NULL,
  plec ENUM('m','k','n') NOT NULL DEFAULT 'n',
  rodzaj ENUM('imie','nazwisko','przydomek') NOT NULL,
  wartosc VARCHAR(60) NOT NULL,
  UNIQUE KEY uq_gen_imiona (gatunek_kod, plec, rodzaj, wartosc),
  FOREIGN KEY (gatunek_kod) REFERENCES gen_gatunki (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Archetypy NPC: zawód + szablon EotE + typowy sprzęt + gdzie bywa.
CREATE TABLE IF NOT EXISTS gen_archetypy (
  kod VARCHAR(40) NOT NULL PRIMARY KEY,
  nazwa VARCHAR(80) NOT NULL,
  tryb ENUM('slugus','rywal','nemezis') NOT NULL DEFAULT 'rywal',
  premie_cech VARCHAR(120) NOT NULL DEFAULT '',   -- np. 'spryt+1,intelekt+1'
  umiejetnosci VARCHAR(255) NOT NULL DEFAULT '',  -- np. 'komputery:3,kretactwo:2'
  bronie VARCHAR(255) NOT NULL DEFAULT '',        -- nazwy broni EotE oddzielone przecinkami
  ekwipunek VARCHAR(500) NOT NULL DEFAULT '',     -- przedmioty osobiste (tekst, z gen_przedmioty)
  rodzaje_miejsc VARCHAR(255) NOT NULL DEFAULT '',-- kody z gen_rodzaje_miejsc
  waga SMALLINT UNSIGNED NOT NULL DEFAULT 10
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cechy charakteru i sposób mówienia (losowane po 2–3 na NPC — wyróżniają postać i sterują rozmową AI).
CREATE TABLE IF NOT EXISTS gen_charakter (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rodzaj ENUM('cecha','nawyk','mowa') NOT NULL,
  wartosc VARCHAR(160) NOT NULL,
  UNIQUE KEY uq_gen_charakter (rodzaj, wartosc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Przedmioty osobiste i dowody (mogą stać się nośnikiem śladu).
CREATE TABLE IF NOT EXISTS gen_przedmioty (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nazwa VARCHAR(120) NOT NULL,
  kategoria ENUM('dokument','dane','osobisty','bron','narzedzie','waluta','kontrabanda') NOT NULL,
  wartosc_kr INT UNSIGNED NOT NULL DEFAULT 0,
  moze_byc_sladem TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_gen_przedmioty (nazwa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rodzaje miejsc i na jakich poziomach miasta występują (miejsca fabularne i szum).
CREATE TABLE IF NOT EXISTS gen_rodzaje_miejsc (
  kod VARCHAR(40) NOT NULL PRIMARY KEY,
  nazwa VARCHAR(80) NOT NULL,
  poziom_min SMALLINT NOT NULL,
  poziom_max SMALLINT NOT NULL,
  waga SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  opis_szumu TEXT NULL                            -- szablon krótkiego opisu, gdy to miejsce jest szumem
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zdawkowe kwestie pobocznych NPC (bez wywołań AI).
CREATE TABLE IF NOT EXISTS gen_kwestie_szumu (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nastroj ENUM('uprzejmy','obojetny','wrogi','przestraszony') NOT NULL,
  tresc VARCHAR(200) NOT NULL,
  UNIQUE KEY uq_gen_kwestie (nastroj, tresc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. INSTANCJA ŚLEDZTWA
-- ---------------------------------------------------------------------

-- Jeden wiersz na (konto, typ). Typ liczony po PRAWDZIWYM zdarzeniu. zdarzenie_zgloszone_id: z czym gracza
-- wezwano (NULL = sprawa jawna, zgłoszenie zgodne z prawdą). Porzucone → MG; po uzasadnieniu i zgodzie MG wraca.
CREATE TABLE IF NOT EXISTS sledztwa (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  konto_login VARCHAR(64) NOT NULL,
  zdarzenie_id TINYINT UNSIGNED NOT NULL,
  relacja_id TINYINT UNSIGNED NOT NULL,
  zdarzenie_zgloszone_id TINYINT UNSIGNED NULL,
  ukrywa_kod VARCHAR(8) NULL,                     -- kto ukrywa prawdę (A, B, Z…), gdy sprawa ukryta
  seed INT UNSIGNED NOT NULL,
  stan ENUM('losowanie','pisanie','trwa','porzucone','wznowienie_prosba','rozwiazane','blad') NOT NULL DEFAULT 'losowanie',
  kolejnosc VARCHAR(60) NOT NULL DEFAULT '',      -- np. '1,2,3,6,4,5,7,9,8,10,11,12'
  tytul VARCHAR(160) NULL,
  zaczyn MEDIUMTEXT NULL,                         -- duży wstęp fabularno-pomocniczy na start
  planeta_id INT UNSIGNED NULL,
  skupisko_id BIGINT UNSIGNED NULL,               -- kompleks z lokalizacje, w którym toczy się sprawa
  rozwiazanie_gracza TEXT NULL,
  ocena TINYINT UNSIGNED NULL,                    -- 0–100: ile prawdy gracz odtworzył
  uzasadnienie_wznowienia TEXT NULL,
  decyzja_mg TEXT NULL,
  utworzono_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  zakonczono_utc DATETIME NULL,
  UNIQUE KEY uq_sledztwa_typ (konto_login, zdarzenie_id, relacja_id),
  KEY ix_sledztwa_stan (stan),
  FOREIGN KEY (konto_login) REFERENCES sledztwo_detektywi (konto_login),
  FOREIGN KEY (zdarzenie_id) REFERENCES sledztwo_zdarzenia (id),
  FOREIGN KEY (relacja_id) REFERENCES sledztwo_relacje (id),
  FOREIGN KEY (zdarzenie_zgloszone_id) REFERENCES sledztwo_zdarzenia (id),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id),
  FOREIGN KEY (skupisko_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fazy 1–7: ukryta prawda; 8–12: przebieg. tresc pisze Autor (NULL do czasu kroku 2).
CREATE TABLE IF NOT EXISTS sledztwo_fazy (
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  faza_nr TINYINT UNSIGNED NOT NULL,
  pozycja TINYINT UNSIGNED NOT NULL,
  wariant_nr TINYINT UNSIGNED NOT NULL,
  jawnosc ENUM('jawna','ukryta') NOT NULL,
  tagi VARCHAR(500) NOT NULL DEFAULT '',          -- stan tagów po tej fazie
  tresc MEDIUMTEXT NULL,
  PRIMARY KEY (sledztwo_id, faza_nr),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (faza_nr, wariant_nr) REFERENCES sledztwo_warianty (faza_nr, nr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Miejsca fabularne: budynek w skupisku + poziom + pokój. Reszta to szum.
CREATE TABLE IF NOT EXISTS sledztwo_miejsca (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  faza_nr TINYINT UNSIGNED NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  rodzaj_kod VARCHAR(40) NOT NULL,
  poziom SMALLINT NOT NULL,
  pokoj VARCHAR(12) NOT NULL,
  nazwa VARCHAR(160) NOT NULL,                    -- losowana z bazy (krok 1)
  opis MEDIUMTEXT NULL,                           -- Autor (krok 2)
  plan_json MEDIUMTEXT NULL,                      -- rzut dla skanu: pokoje, drzwi, terminale
  zamkniete TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_sledztwo_miejsca (sledztwo_id, lokalizacja_id, poziom, pokoj),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (rodzaj_kod) REFERENCES gen_rodzaje_miejsc (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NPC. Krok 1 losuje: imię, nazwisko, przydomek, gatunek, płeć, wiek, archetyp, charakter, kartę EotE, ekwipunek.
-- Krok 2 (tylko kluczowi): historia, wiedza, sekrety. Poboczni: bez AI.
CREATE TABLE IF NOT EXISTS sledztwo_postacie (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  kod VARCHAR(8) NULL,                            -- A, B, Z (zleceniodawca), S (śledzący), W (świadek)…
  waga ENUM('kluczowy','poboczny') NOT NULL DEFAULT 'kluczowy',
  imie VARCHAR(60) NOT NULL,
  nazwisko VARCHAR(60) NULL,
  przydomek VARCHAR(60) NULL,
  gatunek_kod VARCHAR(30) NOT NULL,
  plec ENUM('m','k','n') NOT NULL,
  wiek SMALLINT UNSIGNED NULL,
  archetyp_kod VARCHAR(40) NULL,
  charakter VARCHAR(500) NOT NULL DEFAULT '',     -- wylosowane cechy, nawyki, sposób mówienia
  karta_eote MEDIUMTEXT NULL,                     -- format jak osoby_karta_eote.dane
  ekwipunek VARCHAR(500) NOT NULL DEFAULT '',
  kredyty INT UNSIGNED NOT NULL DEFAULT 0,
  miejsce_id BIGINT UNSIGNED NULL,
  historia MEDIUMTEXT NULL,
  wiedza MEDIUMTEXT NULL,                         -- co wie, przy jakiej jawności powie, kiedy kłamie
  KEY ix_sledztwo_postacie (sledztwo_id, kod),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (gatunek_kod) REFERENCES gen_gatunki (kod),
  FOREIGN KEY (archetyp_kod) REFERENCES gen_archetypy (kod),
  FOREIGN KEY (miejsce_id) REFERENCES sledztwo_miejsca (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ślady: fakt + ukryte słowa kluczowe. Serwer uznaje ślad, gdy to, co droid wyłapał, trafia w słowa kluczowe.
CREATE TABLE IF NOT EXISTS sledztwo_slady (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  faza_nr TINYINT UNSIGNED NOT NULL,
  rodzaj ENUM('rozmowa','terminal','skan','przedmiot','zapis','obserwacja') NOT NULL,
  miejsce_id BIGINT UNSIGNED NULL,
  postac_id BIGINT UNSIGNED NULL,
  przedmiot_id INT UNSIGNED NULL,
  fakt TEXT NOT NULL,
  nosnik MEDIUMTEXT NULL,                         -- surowa treść do przeszukania (log, notatka, wypowiedź)
  slowa_kluczowe VARCHAR(1000) NOT NULL,          -- oddzielone przecinkami
  falszywy TINYINT(1) NOT NULL DEFAULT 0,
  odkryty_utc DATETIME NULL,
  KEY ix_sledztwo_slady (sledztwo_id, odkryty_utc),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (miejsce_id) REFERENCES sledztwo_miejsca (id) ON DELETE SET NULL,
  FOREIGN KEY (postac_id) REFERENCES sledztwo_postacie (id) ON DELETE SET NULL,
  FOREIGN KEY (przedmiot_id) REFERENCES gen_przedmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. DROID I PRZEBIEG
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sledztwo_droid (
  sledztwo_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  lokalizacja_id BIGINT UNSIGNED NULL,
  poziom SMALLINT NOT NULL,
  pokoj VARCHAR(12) NOT NULL DEFAULT 'korytarz',
  cel_lokalizacja_id BIGINT UNSIGNED NULL,
  cel_poziom SMALLINT NULL,
  cel_pokoj VARCHAR(12) NULL,
  wyruszyl_utc DATETIME NULL,
  dotrze_utc DATETIME NULL,
  stan ENUM('czeka','w_drodze','rozmowa','skan','dekodowanie','uszkodzony') NOT NULL DEFAULT 'czeka',
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (cel_lokalizacja_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Odwiedzone miejsca z szumu (żeby przy powrocie wyglądały tak samo).
CREATE TABLE IF NOT EXISTS sledztwo_szum (
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  lokalizacja_id BIGINT UNSIGNED NOT NULL,
  poziom SMALLINT NOT NULL,
  pokoj VARCHAR(12) NOT NULL,
  rodzaj_kod VARCHAR(40) NOT NULL,
  opis TEXT NOT NULL,
  PRIMARY KEY (sledztwo_id, lokalizacja_id, poziom, pokoj),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sledztwo_dziennik (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  kiedy_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rodzaj ENUM('meldunek','rozmowa','polecenie','koncentrat','system') NOT NULL,
  kto VARCHAR(20) NOT NULL,                       -- droid, gracz, npc:<id>, system
  tresc MEDIUMTEXT NOT NULL,
  slad_id BIGINT UNSIGNED NULL,
  KEY ix_sledztwo_dziennik (sledztwo_id, id),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (slad_id) REFERENCES sledztwo_slady (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. DANE STARTOWE (robocze — do akceptacji i rozbudowy)
-- ---------------------------------------------------------------------

INSERT INTO sledztwo_zdarzenia (id, nazwa, wskazowki_autora) VALUES
(1,'Rozstanie',NULL),(2,'Morderstwo',NULL),(3,'Porwanie',NULL),
(4,'Gwałt','Czyn wyłącznie poza kadrem, bez opisów przebiegu. Liczą się konsekwencje, motywy, ślady i zachowanie ludzi.'),
(5,'Kradzież',NULL),(6,'Szantaż',NULL),(7,'Zdrada / donos',NULL),(8,'Oszustwo',NULL),
(9,'Nieudany przemyt',NULL),(10,'Dług',NULL),(11,'Zemsta',NULL),(12,'Zaginięcie bez śladu',NULL)
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO sledztwo_relacje (id, nazwa, rola_a, rola_b) VALUES
(1,'Para','partner(ka)','partner(ka)'),(2,'Wspólnicy','wspólnik','wspólnik'),
(3,'W firmie','pracownik','przełożony'),(4,'Szpiedzy','agent','oficer prowadzący'),
(5,'Rodzic i dziecko','dziecko','rodzic'),(6,'Rodzeństwo','rodzeństwo','rodzeństwo'),
(7,'Mistrz i uczeń','uczeń','mistrz'),(8,'Załoga statku','członek załogi','kapitan'),
(9,'Gang','członek gangu','herszt'),(10,'Sąsiedzi','sąsiad','sąsiad'),
(11,'Dawni towarzysze broni','weteran','weteran'),(12,'Klient i usługodawca','usługodawca','klient')
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO sledztwo_fazy_szablon (nr, nazwa, przestawna, npc_jawna, npc_ukryta) VALUES
(1,'Zawiązanie',0,
 'Wydarzenie znane otoczeniu: sąsiedzi, bywalcy, współpracownicy mówią o nim chętnie, plotkują, przesadzają.',
 'O wydarzeniu wiedzą tylko jego uczestnicy. Inni widzieli najwyżej drobne objawy i nie łączą ich ze sprawą.'),
(2,'Powód śledztwa',0,
 'Zgłoszenie odpowiada prawdzie; zgłaszający współpracuje.',
 'Zgłoszenie jest zasłoną: zgłaszający albo ofiara ukrywa prawdziwe zdarzenie; mówi zgodnie z wersją zgłoszenia, unika szczegółów.'),
(3,'Przedsięwzięcie i jego wynik',0,
 'Skutki przedsięwzięcia są widoczne (alarm, zgłoszenie straty, plotka w półświatku).',
 'Przedsięwzięcie nie zostawiło publicznych śladów; wiedzą tylko wspólnicy i poszkodowany, który milczy.'),
(4,'Presja z zewnątrz',1,
 'Zleceniodawca / wierzyciel działa otwarcie: groźby, wyznaczona nagroda, ludzie pytają o uciekinierów.',
 'Presja jest cicha: pojedyncze wiadomości, wizyty nocą; świadkowie boją się mówić i zaprzeczają.'),
(5,'Zasoby',1,
 'Kłopoty finansowe widać: zastawy w lombardzie, długi w kantynie, sprzedany sprzęt.',
 'Kłopoty skrywane: drobne kradzieże, pożyczki pod fałszywym imieniem, zmiana nawyków.'),
(6,'Pościg',1,
 'Jawny: list gończy, nagroda, łowcy pytają otwarcie; NPC znają twarze ściganych z holonetu.',
 'Ukryty: ktoś cicho idzie po tropach; NPC zauważyli tylko „kogoś, kto już o to pytał”.'),
(7,'Kryjówka i motyw',1,
 'Miejsce pobytu zna kilka osób; da się je kupić lub wymusić.',
 'Miejsce zna jedna osoba albo nikt; dojście tylko przez ślady.'),
(8,'Fałszywy trop',1,
 'Fałszywa wersja krąży publicznie (plotka, oficjalny komunikat).',
 'Fałszywy trop jest podrzucony celowo; zostawiający go NPC kłamie spójnie.'),
(9,'Świadek',1,
 'Świadek mówi, ale chce zapłaty albo przysługi.',
 'Świadek się ukrywa albo nie wie, że jest świadkiem; trzeba go znaleźć i skojarzyć.'),
(10,'Przeszkoda',1,
 'Przeszkoda oficjalna: blokada urzędowa, zamknięty sektor, straż.',
 'Przeszkoda ukryta: zatarte logi, podmieniony zapis, przekupiony urzędnik.'),
(11,'Wyścig',0,
 'Konkurencja działa głośno; jej ruchy widać na ulicy.',
 'Konkurencja działa po cichu; o jej postępie świadczą tylko zniknięcia i zmiany.'),
(12,'Finał',0,
 'Rozwiązanie będzie publiczne; wybór gracza ma skutki znane wszystkim.',
 'Rozwiązanie zna tylko gracz; może je ujawnić, sprzedać albo zachować.')
ON DUPLICATE KEY UPDATE nazwa = VALUES(nazwa), przestawna = VALUES(przestawna),
  npc_jawna = VALUES(npc_jawna), npc_ukryta = VALUES(npc_ukryta);

INSERT INTO gen_gatunki (kod, nazwa, krzepa, zwinnosc, intelekt, spryt, wola, prezencja, rany, wysilek) VALUES
('czlowiek','Człowiek',2,2,2,2,2,2,10,10),('bothanin','Bothanin',1,2,2,3,2,2,10,11),
('droid','Droid',1,1,1,1,1,1,10,10),('gand','Gand',2,2,2,2,3,1,10,10),
('rodianin','Rodianin',2,3,2,2,1,2,10,10),('trandoshanin','Trandoshanin',3,1,2,2,2,2,12,9),
('twilek','Twi''lek',1,2,2,2,2,3,10,11),('wookiee','Wookiee',3,2,2,2,1,2,14,8)
ON DUPLICATE KEY UPDATE nazwa = VALUES(nazwa);

SET @ns := (SELECT id FROM mapa_planety WHERE nazwa = 'Nar Shaddaa' LIMIT 1);
INSERT INTO gen_populacja (planeta_id, gatunek_kod, waga)
SELECT @ns, k, w FROM (SELECT 'czlowiek' k, 40 w UNION ALL SELECT 'twilek',14 UNION ALL SELECT 'rodianin',12
  UNION ALL SELECT 'bothanin',6 UNION ALL SELECT 'trandoshanin',6 UNION ALL SELECT 'gand',3
  UNION ALL SELECT 'wookiee',3 UNION ALL SELECT 'droid',8) t
WHERE @ns IS NOT NULL
ON DUPLICATE KEY UPDATE waga = VALUES(waga);

INSERT IGNORE INTO gen_imiona (gatunek_kod, plec, rodzaj, wartosc) VALUES
('czlowiek','m','imie','Dorran'),('czlowiek','m','imie','Kael'),('czlowiek','m','imie','Torvin'),('czlowiek','m','imie','Brask'),
('czlowiek','k','imie','Lysa'),('czlowiek','k','imie','Mira'),('czlowiek','k','imie','Jessa'),('czlowiek','k','imie','Talia'),
('czlowiek','n','nazwisko','Vell'),('czlowiek','n','nazwisko','Orrin'),('czlowiek','n','nazwisko','Daskar'),('czlowiek','n','nazwisko','Fenn'),
('twilek','m','imie','Bib'),('twilek','m','imie','Numa''tor'),('twilek','k','imie','Lyn''ra'),('twilek','k','imie','Seela'),
('rodianin','m','imie','Greeto'),('rodianin','m','imie','Navik'),('rodianin','k','imie','Tsela'),('rodianin','n','nazwisko','Dhuka'),
('bothanin','m','imie','Borsk'),('bothanin','k','imie','Asyr'),('bothanin','n','nazwisko','Fey''lya'),
('trandoshanin','m','imie','Ssorak'),('trandoshanin','k','imie','Kessa'),('wookiee','m','imie','Grrakka'),('wookiee','k','imie','Malla''ra'),
('gand','n','imie','Zuckss-ka'),('droid','n','imie','RX-7'),('droid','n','imie','4-LOM-B');

-- Przydomki dla wszystkich gatunków (gatunek NULL; UNIQUE nie łapie NULL, więc sprawdzenie ręczne).
INSERT INTO gen_imiona (gatunek_kod, plec, rodzaj, wartosc)
SELECT NULL, 'n', 'przydomek', t.w FROM (SELECT 'Szczur' w UNION ALL SELECT 'Cichy' UNION ALL SELECT 'Rękawica'
  UNION ALL SELECT 'Kostka') t
WHERE NOT EXISTS (SELECT 1 FROM gen_imiona g WHERE g.gatunek_kod IS NULL AND g.rodzaj = 'przydomek' AND g.wartosc = t.w);

INSERT INTO gen_archetypy (kod, nazwa, tryb, premie_cech, umiejetnosci, bronie, ekwipunek, rodzaje_miejsc, waga) VALUES
('slicer','Slicer','rywal','intelekt+1','komputery:3,kretactwo:2,ulica:1','Pistolet blasterowy (lekki)','datapad,kostka danych z kluczami','nora_slicera,kantyna',8),
('barman','Barman','slugus','','urok:1,percepcja:1,ulica:2','','szmata,notes długów','kantyna,kasyno',10),
('ochroniarz','Ochroniarz','rywal','krzepa+1','bijatyka:2,bron_lekka:2,czujnosc:1','Pistolet blasterowy,Pałka','komunikator,karta dostępu','kasyno,hangar,ladowisko',10),
('krupier','Krupier','slugus','prezencja+1','oszustwo:2,percepcja:2','','żetony,klucz do kasy','kasyno',6),
('mechanik','Mechanik portowy','slugus','','mechanika:3,pilotaz_plan:1','Klucz hydrauliczny','zestaw narzędzi,log napraw','hangar,ladowisko',10),
('informator','Informator','rywal','spryt+1','ulica:3,oszustwo:2,czujnosc:1','Nóż wibracyjny','kredyty w drobnych,lista kontaktów','kantyna,targ,nora_slicera',8),
('lowca','Łowca nagród','nemezis','zwinnosc+1','bron_ciezka:3,percepcja:2,ulica:2,czujnosc:2','Karabin blasterowy,Wibronóż','list gończy,kajdany,namierzacz','kantyna,ladowisko',4),
('kurier','Kurier','slugus','zwinnosc+1','atletyka:2,koordynacja:1','','paczka,bilet przejazdu','targ,ladowisko',8),
('handlarz','Handlarz','rywal','prezencja+1','negocjacje:3,ulica:1','Pistolet kieszonkowy','księga rachunkowa,kredyty','sklep,targ',10),
('straznik','Strażnik portowy','slugus','','dyscyplina:1,bron_lekka:1,czujnosc:1','Pistolet blasterowy','identyfikator,rejestr wejść','ladowisko,biuro',8)
ON DUPLICATE KEY UPDATE nazwa = VALUES(nazwa);

INSERT IGNORE INTO gen_charakter (rodzaj, wartosc) VALUES
('cecha','nieufny wobec droidów'),('cecha','gadatliwy'),('cecha','chciwy'),('cecha','lojalny do bólu'),('cecha','tchórzliwy'),('cecha','pedantyczny'),
('nawyk','bawi się żetonem'),('nawyk','co chwilę zerka na drzwi'),('nawyk','pije za dużo'),('nawyk','liczy wszystko na głos'),
('mowa','krótkie zdania, bez uprzejmości'),('mowa','wtrąca huttese'),('mowa','mówi o sobie w trzeciej osobie'),('mowa','odpowiada pytaniem na pytanie');

INSERT IGNORE INTO gen_przedmioty (nazwa, kategoria, wartosc_kr, moze_byc_sladem) VALUES
('Kostka danych','dane',20,1),('Datapad z zablokowanym ekranem','dane',150,1),('Bilet na prom orbitalny','dokument',60,1),
('Kwit lombardowy','dokument',0,1),('Żeton kasyna','waluta',10,1),('Holozdjęcie','osobisty',5,1),('Klucz do pokoju hotelowego','osobisty',0,1),
('Fałszywy identyfikator','dokument',500,1),('Pakunek przyprawy','kontrabanda',300,1),('Nadajnik lokalizacyjny','narzedzie',120,1);

INSERT INTO gen_rodzaje_miejsc (kod, nazwa, poziom_min, poziom_max, waga, opis_szumu) VALUES
('ladowisko','Lądowisko',40,120,4,'Platforma lądowania; kilka frachtowców, droid paliwowy, nikt nie zwraca uwagi.'),
('hangar','Hangar',20,100,6,'Hangar z rozebranym statkiem; mechanicy zajęci swoim.'),
('sklep','Sklep',10,100,14,'Mały sklep; sprzedawca nudzi się za ladą.'),
('targ','Targ',5,60,8,'Gwar straganów; wszyscy coś sprzedają, nikt nic nie widział.'),
('kantyna','Kantyna',5,100,12,'Zadymiona kantyna; muzyka, hazard przy stolikach.'),
('kasyno','Kasyno',30,120,5,'Sala gier; ochrona przygląda się droidowi.'),
('hotel','Hotel',20,120,6,'Korytarz z ponumerowanymi drzwiami; recepcja automatyczna.'),
('mieszkanie','Mieszkanie',1,90,20,'Prywatny lokal; drzwi zamknięte, ktoś słucha holonetu.'),
('biuro','Biuro',30,120,6,'Biuro z droidem recepcyjnym; „proszę umówić termin”.'),
('magazyn','Magazyn',1,40,8,'Skrzynie, kurz, droid ładunkowy.'),
('nora_slicera','Nora slicera',1,25,4,'Ciasne pomieszczenie pełne kabli; nikogo, kto by się przyznał.'),
('korytarz','Korytarz techniczny',1,120,10,'Pusty korytarz; rury, kapiący kondensat.')
ON DUPLICATE KEY UPDATE nazwa = VALUES(nazwa);

INSERT IGNORE INTO gen_kwestie_szumu (nastroj, tresc) VALUES
('uprzejmy','Dzień dobry.'),('uprzejmy','Nie wiem, jak mogę pomóc.'),('uprzejmy','Przykro mi, nikogo takiego nie znam.'),
('obojetny','Nie widziałem.'),('obojetny','Nie mój interes.'),('obojetny','Pytaj kogoś innego.'),
('wrogi','Zjeżdżaj, puszko.'),('wrogi','Droidy tu nie wchodzą.'),
('przestraszony','Ja… nic nie wiem, naprawdę.'),('przestraszony','Proszę, idź sobie.');

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v082-sledztwa', 'Śledztwa droida: detektywi, typy 12×12, jawność, pule losowania, fazy, miejsca, NPC, ślady, droid')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

-- Śledztwa: presety budynków (z czego składa się hotel, kantyna, hangar… na parterze, piętrach i na górze)
-- i reguły wstępu do lokali, nakazy oraz próby przekonania strażników (test EotE). Plan piętra w skanie DT-4
-- powstaje z presetu budynku: np. w hotelu na parterze zawsze jest recepcja, między pokojami gości a windą zawsze
-- jest korytarz, do hali hangaru nie wejdzie się bez nakazu, a do strzeżonych lokali — z nakazem albo przekonawszy
-- strażnika (strażnik z niskim Intelektem łatwo da się nabrać). MG może dowolnie zmieniać presety w bazie.
-- Wymaga 082. Import w phpMyAdmin; ponowny import nadpisuje presety treścią z tego pliku.

USE hololink;
SET NAMES utf8mb4;

-- Budynek: na jakich poziomach miasta zwykle stoi (parter), ile ma pięter, jaki lokal jest „fabularny”
-- (tam generator umieszcza miejsce sprawy) i kto pilnuje strzeżonych lokali (kod z gen_archetypy).
CREATE TABLE IF NOT EXISTS gen_presety_budynkow (
  kod VARCHAR(30) NOT NULL PRIMARY KEY,
  nazwa VARCHAR(60) NOT NULL,
  parter_min SMALLINT NOT NULL,
  parter_max SMALLINT NOT NULL,
  pieter_min TINYINT UNSIGNED NOT NULL,
  pieter_max TINYINT UNSIGNED NOT NULL,
  lokal_fabularny VARCHAR(40) NOT NULL,
  straz_archetyp VARCHAR(40) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lokale presetu w strefie budynku: parter (najniższy poziom), pietro, gora (najwyższy), techniczny (poziomy
-- spoza budynku — szyby i rozdzielnie megastruktury). Kolejność = od windy w głąb korytarza.
-- dostep: wolny (zamek_proc = szansa, że drzwi są zamknięte), nakaz (tylko z nakazem), straz (nakaz albo
-- przekonany strażnik). szer: szerokość lokalu na planie (1 = 60 j.). bywalcy: kod z gen_rodzaje_miejsc,
-- wg którego dobierani są przypadkowi ludzie (gen_archetypy.rodzaje_miejsc); ludzie_max: ilu ich bywa naraz.
CREATE TABLE IF NOT EXISTS gen_presety_lokali (
  budynek VARCHAR(30) NOT NULL,
  strefa ENUM('parter','pietro','gora','techniczny') NOT NULL,
  kolejnosc TINYINT UNSIGNED NOT NULL,
  lokal VARCHAR(40) NOT NULL,
  nazwa VARCHAR(80) NOT NULL,
  ile_min TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ile_max TINYINT UNSIGNED NOT NULL DEFAULT 1,
  dostep ENUM('wolny','nakaz','straz') NOT NULL DEFAULT 'wolny',
  zamek_proc TINYINT UNSIGNED NOT NULL DEFAULT 0,
  szer TINYINT UNSIGNED NOT NULL DEFAULT 1,
  terminal_proc TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bywalcy VARCHAR(40) NULL,
  ludzie_max TINYINT UNSIGNED NOT NULL DEFAULT 1,
  opis VARCHAR(300) NOT NULL DEFAULT '',
  PRIMARY KEY (budynek, strefa, kolejnosc),
  FOREIGN KEY (budynek) REFERENCES gen_presety_budynkow (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nakazy: detektyw składa wniosek (budynek albo cały kompleks: lokalizacja_id NULL), MG wydaje albo odrzuca.
CREATE TABLE IF NOT EXISTS sledztwo_nakazy (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  stan ENUM('wniosek','wydany','odrzucony') NOT NULL DEFAULT 'wniosek',
  uzasadnienie TEXT NOT NULL,
  decyzja TEXT NULL,
  zlozono_utc DATETIME NOT NULL,
  rozpatrzono_utc DATETIME NULL,
  KEY ix_sledztwo_nakazy (sledztwo_id, stan),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Próby przekonania strażnika strzeżonego lokalu (test EotE). Udana = przepustka na 24 h.
CREATE TABLE IF NOT EXISTS sledztwo_straz (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  poziom SMALLINT NOT NULL,
  pokoj VARCHAR(12) NOT NULL,
  podejscie ENUM('oszustwo','urok','zastraszanie') NOT NULL,
  sukcesy TINYINT NOT NULL,
  przewagi TINYINT NOT NULL,
  triumf TINYINT UNSIGNED NOT NULL DEFAULT 0,
  rozpacz TINYINT UNSIGNED NOT NULL DEFAULT 0,
  udana TINYINT(1) NOT NULL,
  kiedy_utc DATETIME NOT NULL,
  KEY ix_sledztwo_straz (sledztwo_id, poziom, pokoj, kiedy_utc),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- PRESETY (pierwsza wersja — do poprawek autora)
-- ---------------------------------------------------------------------

INSERT INTO gen_presety_budynkow (kod, nazwa, parter_min, parter_max, pieter_min, pieter_max, lokal_fabularny, straz_archetyp) VALUES
('hotel','Hotel',20,105,6,14,'pokoj_goscinny','ochroniarz'),
('kantyna','Kantyna',5,95,2,3,'sala','ochroniarz'),
('sklep','Sklep',10,95,1,2,'sala_sprzedazy',NULL),
('targ','Targ',5,55,1,2,'stragan',NULL),
('hangar','Hangar',20,95,2,4,'hala','straznik'),
('ladowisko','Lądowisko',40,115,1,2,'plyta','straznik'),
('kasyno','Kasyno',30,115,2,5,'sala_gier','ochroniarz'),
('biuro','Biurowiec',30,110,3,10,'gabinet','straznik'),
('magazyn','Magazyn',1,38,1,3,'hala_skladowa','ochroniarz'),
('mieszkalny','Blok mieszkalny',1,85,5,20,'mieszkanie',NULL),
('ogolny','Budynek',1,110,2,6,'lokal',NULL),
('techniczny','Strefa techniczna',1,120,1,1,'rozdzielnia',NULL)
ON DUPLICATE KEY UPDATE nazwa = VALUES(nazwa), parter_min = VALUES(parter_min), parter_max = VALUES(parter_max),
  pieter_min = VALUES(pieter_min), pieter_max = VALUES(pieter_max), lokal_fabularny = VALUES(lokal_fabularny),
  straz_archetyp = VALUES(straz_archetyp);

INSERT INTO gen_presety_lokali (budynek, strefa, kolejnosc, lokal, nazwa, ile_min, ile_max, dostep, zamek_proc, szer, terminal_proc, bywalcy, ludzie_max, opis) VALUES
-- HOTEL: recepcja przy windzie na parterze; na piętrach pokoje gości wzdłuż korytarza.
('hotel','parter',1,'recepcja','Recepcja',1,1,'wolny',0,2,70,'hotel',2,'Lada recepcji, automat meldunkowy, tablica z kluczami; recepcjonista nie podnosi wzroku.'),
('hotel','parter',2,'hol','Hol',1,1,'wolny',0,2,20,'hotel',3,'Wytarte fotele, roślina z plastiku, ktoś śpi z głową na plecaku.'),
('hotel','parter',3,'bar_hotelowy','Bar hotelowy',0,1,'wolny',0,1,10,'kantyna',3,'Kilka stolików, barman-droid nalewa coś mętnego.'),
('hotel','parter',4,'biuro_ochrony','Biuro ochrony',1,1,'straz',0,1,60,'hotel',1,'Monitory kamer, szafka z bronią, kubek po kafie.'),
('hotel','parter',5,'zaplecze','Zaplecze',1,1,'wolny',80,1,0,'hotel',1,'Wózki z pościelą, środki czystości, drzwi do szybu na śmieci.'),
('hotel','pietro',1,'schowek','Schowek obsługi',1,1,'wolny',50,1,0,'hotel',0,'Mopy, wiadra, zapasowe żarówki.'),
('hotel','pietro',2,'pokoj_goscinny','Pokój gościnny',8,14,'wolny',70,1,5,'hotel',1,'Wąskie łóżko, szafa, okno na neonowy szyb.'),
('hotel','gora',1,'pokoj_goscinny','Pokój gościnny',4,8,'wolny',70,1,5,'hotel',1,'Wąskie łóżko, szafa, okno na neonowy szyb.'),
('hotel','gora',2,'apartament','Apartament',1,2,'straz',0,2,40,'hotel',2,'Miękkie dywany, widok na wieże Huttów, prywatny ochroniarz przy drzwiach.'),
-- KANTYNA
('kantyna','parter',1,'sala','Sala kantyny',1,1,'wolny',0,3,15,'kantyna',5,'Dym, muzyka, hazard przy stolikach; za barem ktoś nalewa.'),
('kantyna','parter',2,'toalety','Toalety',1,1,'wolny',0,1,0,'kantyna',1,'Klejąca się podłoga, lustro pęknięte na pół.'),
('kantyna','parter',3,'loza','Loża dla stałych gości',0,1,'straz',0,1,0,'kantyna',3,'Zasłonięta kotarą loża; tu dobija się interesów.'),
('kantyna','parter',4,'zaplecze','Zaplecze',1,1,'wolny',80,1,0,'kantyna',1,'Skrzynki z trunkami, chłodziarka, tylne wyjście.'),
('kantyna','pietro',1,'sala_prywatna','Sala prywatna',1,3,'wolny',30,1,0,'kantyna',3,'Stół, kilka krzeseł, ślady po karcianych nocach.'),
('kantyna','pietro',2,'magazyn_trunkow','Magazyn trunków',1,1,'wolny',80,1,0,'kantyna',0,'Beczki, butelki, zapach przyprawy.'),
('kantyna','gora',1,'biuro_wlasciciela','Biuro właściciela',1,1,'straz',0,1,60,'kantyna',1,'Biurko, sejf w ścianie, księgi rachunkowe.'),
-- SKLEP
('sklep','parter',1,'sala_sprzedazy','Sala sprzedaży',1,1,'wolny',0,2,30,'sklep',3,'Półki z towarem, lada, sprzedawca nudzi się za ladą.'),
('sklep','parter',2,'zaplecze','Zaplecze',1,1,'wolny',70,1,10,'sklep',1,'Kartony, rejestr dostaw, kurz.'),
('sklep','pietro',1,'magazynek','Magazynek',1,3,'wolny',60,1,0,'sklep',0,'Regały z towarem na wymianę.'),
('sklep','gora',1,'mieszkanie_wlasciciela','Mieszkanie właściciela',1,1,'wolny',85,1,10,'mieszkanie',1,'Prywatne mieszkanie nad sklepem.'),
-- TARG
('targ','parter',1,'przejscie','Przejście targowe',1,2,'wolny',0,2,10,'targ',4,'Gwar, zapachy, ktoś targuje się o kredyta.'),
('targ','parter',2,'stragan','Stragan',4,8,'wolny',0,1,0,'targ',2,'Stragan z towarem z drugiej ręki.'),
('targ','pietro',1,'stragan','Stragan',3,6,'wolny',0,1,0,'targ',2,'Stragan z towarem z drugiej ręki.'),
-- HANGAR: do hali i wieży bez nakazu się nie wejdzie; warsztat pilnuje strażnik.
('hangar','parter',1,'kontrola_wejscia','Kontrola wejścia',1,1,'wolny',0,1,60,'hangar',2,'Bramka, rejestr wejść, znudzony strażnik portowy.'),
('hangar','parter',2,'hala','Hala hangaru',1,1,'nakaz',0,3,20,'hangar',4,'Rozebrany frachtowiec na podporach, iskry spawarek.'),
('hangar','parter',3,'warsztat','Warsztat',1,2,'straz',0,1,30,'hangar',2,'Stoły z narzędziami, części silników, log napraw.'),
('hangar','parter',4,'biuro_kierownika','Biuro kierownika',1,1,'wolny',50,1,50,'biuro',1,'Biurko zawalone fakturami za paliwo.'),
('hangar','pietro',1,'galeria','Galeria techniczna',1,2,'wolny',0,2,10,'hangar',1,'Pomost nad halą; widać wszystko, co się dzieje na dole.'),
('hangar','pietro',2,'magazyn_czesci','Magazyn części',1,2,'straz',0,1,10,'hangar',1,'Skrzynie z częściami, część bez numerów seryjnych.'),
('hangar','gora',1,'wieza','Wieża kontroli',1,1,'nakaz',0,1,80,'hangar',1,'Konsole kontroli startów, log ruchu statków.'),
-- LĄDOWISKO
('ladowisko','parter',1,'terminal_odpraw','Terminal odpraw',1,1,'wolny',0,2,80,'ladowisko',4,'Kolejka do odprawy, tablica odlotów, droid celny.'),
('ladowisko','parter',2,'poczekalnia','Poczekalnia',1,1,'wolny',0,2,10,'ladowisko',4,'Ławki, automaty z napojami, podróżni z bagażem.'),
('ladowisko','parter',3,'kontrola_celna','Kontrola celna',1,1,'nakaz',0,1,60,'ladowisko',2,'Skanery bagażu, rejestr zatrzymanych ładunków.'),
('ladowisko','parter',4,'plyta','Płyta lądowiska',1,2,'straz',0,3,0,'ladowisko',3,'Platforma lądowania; kilka frachtowców, droid paliwowy.'),
('ladowisko','gora',1,'wieza','Wieża kontroli',1,1,'nakaz',0,1,80,'ladowisko',1,'Konsole kontroli lotów, rejestr przylotów i odlotów.'),
-- KASYNO
('kasyno','parter',1,'hol_kasyna','Hol kasyna',1,1,'wolny',0,2,20,'kasyno',3,'Neony, dywan, bramka z ochroną przyglądającą się droidom.'),
('kasyno','parter',2,'sala_gier','Sala gier',1,2,'wolny',0,3,10,'kasyno',6,'Stoły sabaka, automaty, krupierzy w liberii.'),
('kasyno','parter',3,'kasa','Kasa',1,1,'nakaz',0,1,60,'kasyno',1,'Okienko kasy za pancerną szybą.'),
('kasyno','parter',4,'biuro_ochrony','Biuro ochrony',1,1,'straz',0,1,60,'kasyno',1,'Monitory kamer z sali gier.'),
('kasyno','pietro',1,'sala_vip','Sala VIP',1,2,'straz',0,2,10,'kasyno',3,'Wysokie stawki, ciche rozmowy, drogie trunki.'),
('kasyno','pietro',2,'pokoj_prywatny','Pokój prywatny',2,6,'wolny',60,1,0,'kasyno',2,'Pokój do prywatnych rozgrywek.'),
('kasyno','gora',1,'biuro_zarzadu','Biuro zarządu',1,1,'straz',0,2,70,'biuro',1,'Gabinet z widokiem na salę; sejf, terminal księgowy.'),
-- BIUROWIEC
('biuro','parter',1,'recepcja','Recepcja',1,1,'wolny',0,2,60,'biuro',2,'Droid recepcyjny: „proszę umówić termin”.'),
('biuro','parter',2,'poczekalnia','Poczekalnia',1,1,'wolny',0,1,0,'biuro',3,'Krzesła, holoekran z reklamami.'),
('biuro','pietro',1,'gabinet','Gabinet',4,10,'wolny',40,1,40,'biuro',1,'Biurko, terminal, segregatory.'),
('biuro','pietro',2,'archiwum','Archiwum',0,1,'nakaz',0,1,70,'biuro',0,'Regały z kostkami danych, czytnik.'),
('biuro','pietro',3,'serwerownia','Serwerownia',0,1,'straz',0,1,100,'nora_slicera',0,'Szumiące szafy, kable, chłód.'),
('biuro','gora',1,'gabinet_dyrektora','Gabinet dyrektora',1,1,'straz',0,2,70,'biuro',1,'Duże biurko, widok na miasto, sejf.'),
-- MAGAZYN
('magazyn','parter',1,'brama','Brama ładunkowa',1,1,'wolny',0,2,30,'magazyn',2,'Rampa, wózki repulsorowe, rejestr dostaw.'),
('magazyn','parter',2,'hala_skladowa','Hala składowa',1,2,'straz',0,3,10,'magazyn',2,'Skrzynie aż pod sufit, droid ładunkowy.'),
('magazyn','parter',3,'biuro_magazyniera','Biuro magazyniera',1,1,'wolny',40,1,60,'biuro',1,'Listy przewozowe, termos, zdjęcie rodziny.'),
('magazyn','pietro',1,'hala_skladowa','Hala składowa',1,3,'wolny',50,3,0,'magazyn',1,'Skrzynie, kurz, ciemno.'),
-- BLOK MIESZKALNY
('mieszkalny','parter',1,'hol','Hol',1,1,'wolny',0,2,30,'mieszkanie',2,'Skrzynki na wiadomości, tablica ogłoszeń, kapiący kondensat.'),
('mieszkalny','parter',2,'pralnia','Pralnia',0,1,'wolny',0,1,0,'mieszkanie',2,'Pralki, z których połowa nie działa.'),
('mieszkalny','pietro',1,'mieszkanie','Mieszkanie',8,14,'wolny',85,1,5,'mieszkanie',1,'Zamknięte drzwi, ktoś słucha holonetu.'),
('mieszkalny','gora',1,'mieszkanie','Mieszkanie',4,8,'wolny',85,1,5,'mieszkanie',1,'Zamknięte drzwi, ktoś słucha holonetu.'),
-- OGÓLNY (budynek bez presetu)
('ogolny','parter',1,'hol','Hol',1,1,'wolny',0,2,30,'targ',2,'Wejście do budynku, tablica lokatorów.'),
('ogolny','parter',2,'lokal','Lokal',3,8,'wolny',30,1,10,'sklep',2,'Mały lokal usługowy.'),
('ogolny','pietro',1,'lokal','Lokal',6,12,'wolny',40,1,10,'biuro',1,'Lokal biurowy albo mieszkalny.'),
-- STREFA TECHNICZNA (poziomy, na których budynek nie ma lokali)
('techniczny','techniczny',1,'rozdzielnia','Rozdzielnia',1,3,'wolny',60,1,30,'nora_slicera',1,'Szafy energetyczne, buczenie, ostrzeżenia w aurebeshu.'),
('techniczny','techniczny',2,'szyb','Szyb wentylacyjny',1,2,'wolny',0,1,0,'korytarz',0,'Wiatr z dołu niesie zapach spalin.'),
('techniczny','techniczny',3,'magazyn_techniczny','Magazyn techniczny',0,2,'wolny',50,1,0,'magazyn',1,'Rury, kable, zapomniane narzędzia.')
ON DUPLICATE KEY UPDATE lokal = VALUES(lokal), nazwa = VALUES(nazwa), ile_min = VALUES(ile_min), ile_max = VALUES(ile_max),
  dostep = VALUES(dostep), zamek_proc = VALUES(zamek_proc), szer = VALUES(szer), terminal_proc = VALUES(terminal_proc),
  bywalcy = VALUES(bywalcy), ludzie_max = VALUES(ludzie_max), opis = VALUES(opis);

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v086-sledztwa-presety', 'Śledztwa: presety budynków i lokali, reguły wstępu, nakazy, próby przekonania strażników')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

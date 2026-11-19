-- Lokacje Naboo (3 BBY): regiony i geografia, Theed (pałac, dzielnice, port), Keren, Kaadara, Dee'ja Peak,
-- Moenia, Oxon, miasta Gungan z Otoh Gunga, Lake Country z Varykino. Układ: (0,0) = centrum Theed,
-- +X wschód, +Y północ (km). Przepisane z procedury składowanej na zwykły import; „Theed Royal Hangar”
-- ma typ budynek (hangar to rodzaj — typ „hangar” zatrzymywał procedurę). Niepubliczne: regiony
-- i geografia, zaplecza dworu i służb, magazyny, miejsca i infrastruktura Gungan, Varykino; dawny
-- Royal Starport — zamknięty. Import w phpMyAdmin; ponowny import niczego nie psuje.

USE hololink;
SET NAMES utf8mb4;

SET @pl := (SELECT id FROM mapa_planety WHERE nazwa = 'Naboo' LIMIT 1);

DROP TEMPORARY TABLE IF EXISTS tmp_import_lokacji;
CREATE TEMPORARY TABLE tmp_import_lokacji (
  poziom TINYINT UNSIGNED NOT NULL,
  nazwa VARCHAR(200) NOT NULL PRIMARY KEY,
  rodzic_nazwa VARCHAR(200) NULL,
  rodzic_id BIGINT UNSIGNED NULL,
  typ ENUM('uklad','orbita','powierzchnia','lokacja','budynek','ladowisko') NOT NULL,
  rodzaj ENUM('hangar','port','hotel','bankomat','sklep','kantyna','biuro','garaz','baza','magazyn','platforma','inne') NULL,
  x_km DECIMAL(12,3) NOT NULL,
  y_km DECIMAL(12,3) NOT NULL,
  publiczna TINYINT(1) NOT NULL,
  status ENUM('aktywna','zamknieta','zniszczona') NOT NULL,
  opis TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_import_lokacji (poziom, nazwa, rodzic_nazwa, typ, rodzaj, x_km, y_km, publiczna, status, opis) VALUES
(1,'Wielkie Równiny Trawiaste',NULL,'powierzchnia','inne',0.0,230.0,0,'aktywna','Rozległy północny obszar żyznych równin Naboo, obejmujący okolice Theed i tereny rolnicze.'),
(1,'Zachodnie Równiny Naboo',NULL,'powierzchnia','inne',-470.0,100.0,0,'aktywna','Zachodnia część równin Naboo, obejmująca zaplecze Keren i Kaadary.'),
(1,'Wschodnie Równiny Naboo',NULL,'powierzchnia','inne',520.0,180.0,0,'aktywna','Rozległy obszar równin na wschód od Theed, o charakterze rolniczym i pasterskim.'),
(1,'Gallo Mountains',NULL,'powierzchnia','inne',250.0,-430.0,0,'aktywna','Pasmo górskie Naboo, związane z Deeja Peak i południowymi dolinami.'),
(1,'Lake Country',NULL,'powierzchnia','inne',-250.0,-520.0,0,'aktywna','Region jezior, zielonych wzgórz i posiadłości nadwodnych Naboo.'),
(1,'Lianorm Swamp',NULL,'powierzchnia','inne',100.0,-1050.0,0,'aktywna','Rozległy kompleks mokradeł i kanałów tradycyjnie związanych z terytoriami Gungan.'),
(1,'Północne Wybrzeże Naboo',NULL,'powierzchnia','inne',-100.0,850.0,0,'aktywna','Pas wybrzeża północnego oceanu Naboo.'),
(1,'Południowe Mokradła Naboo',NULL,'powierzchnia','inne',180.0,-1330.0,0,'aktywna','Podmokłe niziny południowej części planety, obejmujące obszary Gungan.'),
(2,'Solleu River','Wielkie Równiny Trawiaste','powierzchnia','inne',0.0,15.0,0,'aktywna','Rzeka przepływająca przez Theed i tworząca charakterystyczne wodospady stolicy.'),
(2,'Klify Theed','Wielkie Równiny Trawiaste','powierzchnia','inne',-10.0,0.0,0,'aktywna','Klify nad doliną Solleu, na których rozwinęła się stolica Naboo.'),
(2,'Lake Paonga','Południowe Mokradła Naboo','powierzchnia','inne',120.0,-900.0,0,'aktywna','Wielkie jezioro, pod którego powierzchnią znajduje się Otoh Gunga.'),
(2,'Jeziora Lake Country','Lake Country','powierzchnia','inne',-310.0,-560.0,0,'aktywna','Zespół jezior i zatok regionu Lake Country.'),
(2,'Podziemne Drogi Wodne Naboo','Południowe Mokradła Naboo','powierzchnia','inne',80.0,-760.0,0,'aktywna','System podziemnych kanałów, jaskiń i naturalnych połączeń wodnych.'),
(2,'Jaskinie Plazmowe Naboo','Gallo Mountains','powierzchnia','inne',280.0,-600.0,0,'aktywna','Autorski obszar geologiczny reprezentujący naturalne pustki i kanały związane z plazmą.'),
(2,'Wzgórza Lake Country','Lake Country','powierzchnia','inne',-440.0,-420.0,0,'aktywna','Zielone wzgórza otaczające jeziora i posiadłości regionu.'),
(2,'Wielkie Łąki Shaaków','Wielkie Równiny Trawiaste','powierzchnia','inne',180.0,300.0,0,'aktywna','Trawiaste pastwiska wykorzystywane przez stada shaaków.'),
(2,'Północny Pas Leśny','Wielkie Równiny Trawiaste','powierzchnia','inne',-300.0,400.0,0,'aktywna','Autorski obszar zadrzewień i lasów pomiędzy równinami a wzgórzami.'),
(2,'Górskie Doliny Gallo','Gallo Mountains','powierzchnia','inne',300.0,-300.0,0,'aktywna','Dolina i przełęcze górskie w rejonie Deeja Peak.'),
(2,'Bagna Lianorm – Północny Skraj','Lianorm Swamp','powierzchnia','inne',140.0,-850.0,0,'aktywna','Północna strefa mokradeł przechodząca w podnóża Gallo Mountains.'),
(2,'Delta Solleu','Południowe Mokradła Naboo','powierzchnia','inne',40.0,-480.0,0,'aktywna','Autorski obszar rozlewisk i kanałów rzecznych.'),
(2,'Theed','Wielkie Równiny Trawiaste','lokacja','inne',0.0,0.0,1,'aktywna','Stolica Naboo, ośrodek królewski, administracyjny, kulturalny i transportowy.'),
(2,'Keren','Zachodnie Równiny Naboo','lokacja','inne',-620.0,110.0,1,'aktywna','Miasto Naboo znane w Legends jako ważny ośrodek handlowy.'),
(2,'Kaadara','Zachodnie Równiny Naboo','lokacja','inne',-980.0,430.0,1,'aktywna','Nadmorska osada Naboo związana z rybołówstwem i wypoczynkiem.'),
(2,'Dee''ja Peak','Gallo Mountains','lokacja','inne',320.0,-250.0,1,'aktywna','Historyczna osada Naboo położona w regionie górskim.'),
(2,'Moenia','Lianorm Swamp','lokacja','inne',80.0,-1190.0,1,'aktywna','Osada na południowych mokradłach, znana z materiałów Legends.'),
(2,'Oxon City','Południowe Mokradła Naboo','lokacja','inne',390.0,-980.0,1,'aktywna','Miasto na mokradłach zamieszkane przez Gungan i ludzi.'),
(2,'Otoh Mandassa','Południowe Mokradła Naboo','lokacja','inne',-20.0,-1110.0,1,'aktywna','Miasto Gungan wymieniane w materiałach Legends.'),
(2,'Umberbool City','Lianorm Swamp','lokacja','inne',-180.0,-1020.0,1,'aktywna','Miasto Gungan wymieniane w materiałach Legends.'),
(2,'Otoh Langua','Lianorm Swamp','lokacja','inne',190.0,-1280.0,1,'aktywna','Osada Gungan znana z materiałów Legends.'),
(2,'Jan-Gwa City','Południowe Mokradła Naboo','lokacja','inne',-350.0,-920.0,1,'aktywna','Ośrodek Gungan wymieniany w materiałach Legends.'),
(2,'Otoh Jahai','Południowe Mokradła Naboo','lokacja','inne',250.0,-1120.0,1,'aktywna','Ośrodek osadniczy Gungan znany z Legends.'),
(2,'Otoh Raban','Lianorm Swamp','lokacja','inne',-120.0,-1330.0,1,'aktywna','Miasto Gungan wymieniane w materiałach Legends.'),
(2,'Otoh Sancture','Południowe Mokradła Naboo','lokacja','inne',-450.0,-720.0,1,'aktywna','Osada Gungan znana z materiałów Legends.'),
(2,'Otoh Urs','Zachodnie Równiny Naboo','lokacja','inne',-820.0,-320.0,1,'aktywna','Ośrodek Gungan znany z Legends.'),
(2,'Rellias','Lianorm Swamp','lokacja','inne',-700.0,-1150.0,1,'aktywna','Osada Gungan wymieniana w materiałach Legends.'),
(2,'Shoril','Gallo Mountains','lokacja','inne',470.0,-550.0,1,'aktywna','Ośrodek Gungan wymieniany w materiałach Legends.'),
(2,'Spearhead','Zachodnie Równiny Naboo','lokacja','inne',-530.0,-520.0,1,'aktywna','Osada Gungan wymieniana w materiałach Legends.'),
(2,'Varykino','Lake Country','lokacja','inne',-330.0,-610.0,0,'aktywna','Posiadłość nad jeziorem związana z rodziną Naberrie i Padmé Amidala.'),
(2,'Gungan Swamp Refuge','Lianorm Swamp','lokacja','inne',-80.0,-900.0,0,'aktywna','Autorski obszar mokradeł wykorzystywany jako schronienie i teren przemieszczania się Gungan.'),
(2,'Wodospad Dee''ja Peak','Gallo Mountains','powierzchnia','inne',350.0,-220.0,0,'aktywna','Wodospad znany z materiałów Star Wars Galaxies.'),
(3,'Wodospady Theed','Solleu River','powierzchnia','inne',-25.0,-30.0,0,'aktywna','Naturalne kaskady rzeki Solleu przy stolicy.'),
(3,'Virdugo Plunge','Solleu River','powierzchnia','inne',35.0,-45.0,0,'aktywna','Wodospad i przełom wodny w rejonie klifów Theed.'),
(3,'Otoh Gunga','Lake Paonga','lokacja','inne',120.0,-920.0,1,'aktywna','Podwodne miasto Gungan zbudowane z organicznych kopuł i struktur hydrostatycznych.'),
(3,'Gungan Sacred Place','Bagna Lianorm – Północny Skraj','lokacja','inne',160.0,-790.0,0,'aktywna','Święte miejsce Gungan, gdzie społeczność schroniła się podczas inwazji Naboo.'),
(3,'Dzielnica Pałacowa Theed','Theed','lokacja','inne',-15.0,10.0,1,'aktywna','Strefa ceremonialna i administracyjna skupiona wokół pałacu królewskiego.'),
(3,'Dzielnica Administracyjna Theed','Theed','lokacja','inne',55.0,85.0,1,'aktywna','Autorska strefa urzędów i obsługi administracji miasta.'),
(3,'Dzielnica Handlowa Theed','Theed','lokacja','inne',-100.0,110.0,1,'aktywna','Obszar handlu, targowisk i usług miejskich.'),
(3,'Dzielnica Mieszkalna Theed','Theed','lokacja','inne',100.0,-100.0,1,'aktywna','Obszar zabudowy mieszkalnej mieszkańców stolicy.'),
(3,'Kompleks Portowy Theed','Theed','lokacja','inne',-80.0,-180.0,1,'aktywna','Kompleks infrastruktury transportowej i portowej.'),
(3,'Kompleks Akademicki Theed','Theed','lokacja','inne',-170.0,70.0,1,'aktywna','Obszar instytucji edukacyjnych stolicy.'),
(3,'Bassa Bridge','Theed','lokacja','inne',15.0,45.0,1,'aktywna','Most miejski wymieniany w materiałach Legends.'),
(3,'Pergola''s Bridge','Theed','lokacja','inne',-40.0,-15.0,1,'aktywna','Most i przejście miejskie związane z obszarem Solleu.'),
(3,'Broadberry Meadow','Theed','powierzchnia','inne',140.0,-130.0,0,'aktywna','Miejska łąka i teren zielony wymieniany w materiałach Legends.'),
(3,'Port Handlowy Keren','Keren','ladowisko','port',-650.0,125.0,1,'aktywna','Autorski port lokalny obsługujący handel i transport Keren.'),
(3,'Dzielnica Handlowa Keren','Keren','lokacja','inne',-590.0,140.0,1,'aktywna','Modelowana strefa handlowa miasta Keren.'),
(3,'Nabrzeże Kaadary','Kaadara','lokacja','inne',-1000.0,445.0,1,'aktywna','Nadmorski obszar portowy i rybacki.'),
(3,'Dzielnica Osadnicza Dee''ja Peak','Dee''ja Peak','lokacja','inne',335.0,-240.0,1,'aktywna','Zabudowana część historycznej osady górskiej.'),
(3,'Przystań Moenia','Moenia','ladowisko','port',65.0,-1205.0,1,'aktywna','Autorski punkt transportowy na mokradłach.'),
(3,'Dzielnica Artystyczna Moenia','Moenia','lokacja','inne',95.0,-1170.0,1,'aktywna','Dzielnica osady związana z jej artystycznym charakterem znanym z Legends.'),
(3,'Port Kosmiczny Oxon','Oxon City','ladowisko','port',410.0,-960.0,1,'aktywna','Port kosmiczny miasta Oxon, znany z materiałów Legends.'),
(3,'Posterunek Szlaków Wodnych Gungan','Gungan Swamp Refuge','budynek','baza',-65.0,-910.0,0,'aktywna','Autorski punkt obserwacji szlaków wodnych i kontroli ruchu na mokradłach.'),
(4,'Kompleks Królewskich Sił Bezpieczeństwa','Dzielnica Pałacowa Theed','lokacja','inne',55.0,-20.0,0,'aktywna','Autorski kompleks obsługi ochrony pałacu i królewskich służb bezpieczeństwa.'),
(4,'Theed Royal Palace','Dzielnica Pałacowa Theed','budynek','inne',-10.0,5.0,1,'aktywna','Pałac królewski, tradycyjna siedziba monarchy Naboo.'),
(4,'Palace Plaza','Dzielnica Pałacowa Theed','lokacja','inne',-30.0,30.0,1,'aktywna','Reprezentacyjny plac przed pałacem królewskim.'),
(4,'Theed Royal Hangar','Dzielnica Pałacowa Theed','budynek','hangar',40.0,-35.0,0,'aktywna','Hangar królewskich statków i myśliwców.'),
(4,'Theed Power Generator','Dzielnica Pałacowa Theed','budynek','inne',65.0,-50.0,0,'aktywna','Generator plazmowy zasilający infrastrukturę Theed i hangar królewski.'),
(4,'Theed Spaceport','Kompleks Portowy Theed','ladowisko','port',-85.0,-195.0,1,'aktywna','Główny port kosmiczny stolicy Naboo.'),
(4,'Theed Royal Academy','Kompleks Akademicki Theed','budynek','inne',-180.0,65.0,1,'aktywna','Placówka edukacyjna związana z kształceniem elit Naboo.'),
(4,'Theed University','Kompleks Akademicki Theed','budynek','inne',-155.0,95.0,1,'aktywna','Uczelnia wymieniana w materiałach Legends.'),
(4,'Theed Funeral Temple','Dzielnica Pałacowa Theed','budynek','inne',-55.0,65.0,1,'aktywna','Świątynia związana z królewskimi ceremoniami pogrzebowymi.'),
(4,'Theed Market Square','Dzielnica Handlowa Theed','lokacja','inne',-115.0,120.0,1,'aktywna','Plac targowy i miejsce handlu detalicznego.'),
(4,'Parnelli Museum of Art','Dzielnica Handlowa Theed','budynek','inne',-145.0,145.0,1,'aktywna','Muzeum sztuki wymieniane w materiałach Legends.'),
(4,'Royal Boathouse Theed','Dzielnica Pałacowa Theed','budynek','inne',-25.0,-20.0,0,'aktywna','Przystań i budynek obsługi jednostek pływających dworu.'),
(4,'Livet Tower','Dzielnica Administracyjna Theed','budynek','inne',75.0,115.0,1,'aktywna','Wieża miejska wymieniana w materiałach Legends.'),
(4,'Ellié Arcadium','Dzielnica Handlowa Theed','budynek','inne',-125.0,95.0,1,'aktywna','Obiekt miejski wymieniany w zestawieniach punktów orientacyjnych.'),
(4,'Guido''s Tower','Dzielnica Administracyjna Theed','budynek','inne',95.0,75.0,1,'aktywna','Wieża miejska wymieniana w materiałach Legends.'),
(4,'Hall of Perri-Teeka','Dzielnica Administracyjna Theed','budynek','inne',115.0,90.0,1,'aktywna','Budynek miejski wymieniany w zestawieniach punktów orientacyjnych.'),
(4,'Magazyn Handlowy Keren','Dzielnica Handlowa Keren','budynek','magazyn',-610.0,150.0,0,'aktywna','Magazyn przechowujący towary i produkty handlowe.'),
(4,'Przystań Rybacka Kaadary','Nabrzeże Kaadary','ladowisko','port',-1015.0,430.0,1,'aktywna','Przystań dla jednostek rybackich i transportu przybrzeżnego.'),
(4,'Sektor Rady Otoh Gunga','Otoh Gunga','lokacja','inne',125.0,-918.0,0,'aktywna','Centralny sektor administracyjny podwodnego miasta.'),
(4,'Sektor Mieszkalny Otoh Gunga','Otoh Gunga','lokacja','inne',105.0,-930.0,1,'aktywna','Sektor mieszkalny obejmujący organiczne kopuły i przestrzenie wspólnotowe.'),
(4,'Sektor Transportowy Otoh Gunga','Otoh Gunga','lokacja','inne',140.0,-905.0,1,'aktywna','Sektor doków i połączeń podwodnych.'),
(4,'Komory Hydrostatyczne Otoh Gunga','Otoh Gunga','budynek','inne',118.0,-925.0,0,'aktywna','Kopuły hydrostatyczne utrzymujące atmosferę wewnątrz podwodnych struktur.'),
(4,'Gungan Sacred Monument','Gungan Sacred Place','budynek','inne',162.0,-792.0,0,'aktywna','Święty monument Gungan opisany w materiałach Legends.'),
(4,'Obóz Zgromadzeń Gungan','Gungan Sacred Place','lokacja','baza',175.0,-780.0,0,'aktywna','Autorski punkt reprezentujący czasowe zgromadzenia i narady Gungan.'),
(4,'Centrum Administracji Miejskiej Theed','Dzielnica Administracyjna Theed','budynek','biuro',60.0,100.0,1,'aktywna','Autorski budynek obsługi administracyjnej miasta.'),
(4,'Kantyna Portowa Theed','Kompleks Portowy Theed','budynek','kantyna',-105.0,-205.0,1,'aktywna','Lokal usługowy dla podróżnych i personelu portu.'),
(4,'Warsztat Serwisowy Portu Theed','Kompleks Portowy Theed','budynek','garaz',-65.0,-215.0,1,'aktywna','Warsztat obsługi naziemnej i drobnych napraw statków cywilnych.'),
(4,'Magazyn Zaopatrzenia Theed','Kompleks Portowy Theed','budynek','magazyn',-95.0,-230.0,0,'aktywna','Magazyn zapasów portowych, części zamiennych i towarów.'),
(4,'Centrum Medyczne Theed','Dzielnica Mieszkalna Theed','budynek','inne',125.0,-115.0,1,'aktywna','Autorska placówka opieki medycznej dla mieszkańców stolicy.'),
(4,'Kompleks Mieszkalny Theed – Tarasy Wschodnie','Dzielnica Mieszkalna Theed','lokacja','inne',145.0,-90.0,1,'aktywna','Zespół zabudowy mieszkalnej na wschodnich tarasach miasta.'),
(4,'Plac Zgromadzeń Keren','Dzielnica Handlowa Keren','lokacja','inne',-575.0,165.0,1,'aktywna','Plac miejski przeznaczony na targi i zgromadzenia mieszkańców.'),
(4,'Punkt Kontroli Transportu Oxon','Port Kosmiczny Oxon','budynek','biuro',425.0,-950.0,1,'aktywna','Autorski punkt obsługi i kontroli ruchu portowego.'),
(5,'Palace Courtyard','Theed Royal Palace','lokacja','inne',-5.0,15.0,0,'aktywna','Wewnętrzny dziedziniec pałacowy.'),
(5,'Sala Tronowa Pałacu Theed','Theed Royal Palace','budynek','inne',-2.0,8.0,0,'aktywna','Główna sala audiencyjna monarchy Naboo.'),
(5,'Theed Royal Library','Theed Royal Palace','budynek','inne',-20.0,15.0,0,'aktywna','Biblioteka pałacowa przechowująca zbiory historyczne dworu.'),
(5,'Throne Room Turret','Theed Royal Palace','budynek','inne',-18.0,18.0,0,'aktywna','Wieżowa część pałacu określana w Legends jako Turret Room.'),
(5,'Royal Starport – dawne podziemia','Theed Royal Palace','budynek','hangar',-12.0,-5.0,0,'zamknieta','Dawny prywatny port królewski w jaskiniach pod pałacem; nie należy zakładać jego aktywności w 3 BBY.'),
(5,'Royal Naboo Security Forces – kwatera Theed','Kompleks Królewskich Sił Bezpieczeństwa','budynek','baza',70.0,-15.0,0,'aktywna','Autorska kwatera lokalnych służb bezpieczeństwa przy kompleksie pałacowym.'),
(5,'High Board Room','Sektor Rady Otoh Gunga','budynek','biuro',128.0,-920.0,0,'aktywna','Sala obrad Rady Reprezentantów Gungan.'),
(5,'Doki Bongo Otoh Gunga','Sektor Transportowy Otoh Gunga','ladowisko','port',150.0,-910.0,0,'aktywna','Strefa cumowania i obsługi gungańskich łodzi podwodnych.');

-- Import poziom po poziomie: rodzic odnajdywany po nazwie na tej planecie. Istniejące rekordy
-- (ta sama planeta i nazwa) zostają bez zmian, więc ponowny import tylko dokłada brakujące.
START TRANSACTION;

UPDATE tmp_import_lokacji t JOIN lokalizacje r ON r.planeta_id = @pl AND r.nazwa = t.rodzic_nazwa
SET t.rodzic_id = r.id WHERE t.poziom = 1;
INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
SELECT typ, rodzaj, rodzic_id, NULL, @pl, nazwa, x_km, y_km, publiczna, status, opis FROM tmp_import_lokacji
WHERE poziom = 1 AND @pl IS NOT NULL AND (rodzic_nazwa IS NULL OR rodzic_id IS NOT NULL)
ON DUPLICATE KEY UPDATE id = id;

UPDATE tmp_import_lokacji t JOIN lokalizacje r ON r.planeta_id = @pl AND r.nazwa = t.rodzic_nazwa
SET t.rodzic_id = r.id WHERE t.poziom = 2;
INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
SELECT typ, rodzaj, rodzic_id, NULL, @pl, nazwa, x_km, y_km, publiczna, status, opis FROM tmp_import_lokacji
WHERE poziom = 2 AND @pl IS NOT NULL AND (rodzic_nazwa IS NULL OR rodzic_id IS NOT NULL)
ON DUPLICATE KEY UPDATE id = id;

UPDATE tmp_import_lokacji t JOIN lokalizacje r ON r.planeta_id = @pl AND r.nazwa = t.rodzic_nazwa
SET t.rodzic_id = r.id WHERE t.poziom = 3;
INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
SELECT typ, rodzaj, rodzic_id, NULL, @pl, nazwa, x_km, y_km, publiczna, status, opis FROM tmp_import_lokacji
WHERE poziom = 3 AND @pl IS NOT NULL AND (rodzic_nazwa IS NULL OR rodzic_id IS NOT NULL)
ON DUPLICATE KEY UPDATE id = id;

UPDATE tmp_import_lokacji t JOIN lokalizacje r ON r.planeta_id = @pl AND r.nazwa = t.rodzic_nazwa
SET t.rodzic_id = r.id WHERE t.poziom = 4;
INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
SELECT typ, rodzaj, rodzic_id, NULL, @pl, nazwa, x_km, y_km, publiczna, status, opis FROM tmp_import_lokacji
WHERE poziom = 4 AND @pl IS NOT NULL AND (rodzic_nazwa IS NULL OR rodzic_id IS NOT NULL)
ON DUPLICATE KEY UPDATE id = id;

UPDATE tmp_import_lokacji t JOIN lokalizacje r ON r.planeta_id = @pl AND r.nazwa = t.rodzic_nazwa
SET t.rodzic_id = r.id WHERE t.poziom = 5;
INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
SELECT typ, rodzaj, rodzic_id, NULL, @pl, nazwa, x_km, y_km, publiczna, status, opis FROM tmp_import_lokacji
WHERE poziom = 5 AND @pl IS NOT NULL AND (rodzic_nazwa IS NULL OR rodzic_id IS NOT NULL)
ON DUPLICATE KEY UPDATE id = id;

COMMIT;

-- Kontrola: lokacje z paczki w bazie, brakujące (np. brak planety albo rodzica) i osierocone.
SELECT COUNT(*) AS w_paczce,
  SUM(l.id IS NOT NULL) AS w_bazie,
  SUM(l.id IS NULL) AS brakujace,
  SUM(t.rodzic_nazwa IS NOT NULL AND l.id IS NOT NULL AND l.rodzic_id IS NULL) AS bez_rodzica
FROM tmp_import_lokacji t LEFT JOIN lokalizacje l ON l.planeta_id = @pl AND l.nazwa = t.nazwa;

DROP TEMPORARY TABLE IF EXISTS tmp_import_lokacji;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v32-lokacje-naboo', 'Lokacje: Naboo (101)')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

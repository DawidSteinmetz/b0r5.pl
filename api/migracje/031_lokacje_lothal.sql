-- Lokacje Lothal: regiony, Capital City z dzielnicami i portem, Tarkintown, Jalath z instalacją imperialną,
-- strefa przemysłowa, gospodarstwa, ruiny ze Świątynią Jedi, port Jhothal z Old Jhos Pit Stop.
-- Przepisane z procedury składowanej na zwykły import (hosting współdzielony często nie pozwala tworzyć
-- procedur). Publiczne: miasta, osady, dzielnice, porty, kantyny, sklepy, obiekty cywilne; instalacje
-- imperialne, magazyny i zaplecza — niepubliczne. Import w phpMyAdmin; ponowny import niczego nie psuje.

USE hololink;
SET NAMES utf8mb4;

SET @pl := (SELECT id FROM mapa_planety WHERE nazwa = 'Lothal' LIMIT 1);

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
(1,'Region Stołeczny',NULL,'powierzchnia','inne',0.0,0.0,0,'aktywna','Projektowy region geograficzny obejmujący Capital City.'),
(1,'Region Tarkintown',NULL,'powierzchnia','inne',-18.0,-12.0,0,'aktywna','Projektowy region obejmujący osiedle wysiedlonych mieszkańców.'),
(1,'Region Jalath',NULL,'powierzchnia','inne',42.0,18.0,0,'aktywna','Projektowy region obejmujący miasto Jalath.'),
(1,'Region Przemysłowy',NULL,'powierzchnia','inne',65.0,-24.0,0,'aktywna','Projektowy obszar imperialnej produkcji i infrastruktury przemysłowej.'),
(1,'Region Rolniczy Zachodni',NULL,'powierzchnia','inne',-85.0,35.0,0,'aktywna','Projektowy obszar zachodnich gospodarstw rolnych.'),
(1,'Region Rolniczy Wschodni',NULL,'powierzchnia','inne',105.0,55.0,0,'aktywna','Projektowy obszar wschodnich gospodarstw rolnych.'),
(1,'Region Gór i Kanionów',NULL,'powierzchnia','inne',-145.0,-95.0,0,'aktywna','Projektowy region górski obejmujący obszar starożytnych ruin.'),
(1,'Region Leśnych Wzgórz',NULL,'powierzchnia','inne',155.0,-115.0,0,'aktywna','Projektowy obszar leśnych wzgórz.'),
(1,'Region Południowych Pustkowi',NULL,'powierzchnia','inne',20.0,-190.0,0,'aktywna','Projektowy obszar południowych pustkowi.'),
(1,'Obszar Portowy Jhothal',NULL,'powierzchnia','inne',125.0,12.0,0,'aktywna','Projektowy obszar portowy związany z Old Jhos Pit Stop.'),
(2,'Capital City','Region Stołeczny','powierzchnia','inne',0.0,0.0,1,'aktywna','Główne miasto planety Lothal.'),
(2,'Tarkintown','Region Tarkintown','powierzchnia','inne',-18.0,-12.0,1,'aktywna','Osiedle wysiedlonych mieszkańców Lothal.'),
(2,'Jalath','Region Jalath','powierzchnia','inne',42.0,18.0,1,'aktywna','Miasto na Lothal z imperialną infrastrukturą.'),
(2,'Gospodarstwo Olletów','Region Rolniczy Zachodni','lokacja','inne',-72.0,42.0,0,'aktywna','Gospodarstwo rodziny Olletów.'),
(2,'Osada Rolnicza Zachód','Region Rolniczy Zachodni','powierzchnia','inne',-105.0,48.0,1,'aktywna','Autorska osada rolnicza.'),
(2,'Osada Rolnicza Wschód','Region Rolniczy Wschodni','powierzchnia','inne',121.0,61.0,1,'aktywna','Autorska osada rolnicza.'),
(2,'Osada Leśna','Region Leśnych Wzgórz','powierzchnia','inne',159.0,-112.0,1,'aktywna','Autorska niewielka osada leśna.'),
(2,'Opuszczona Osada Południowa','Region Południowych Pustkowi','powierzchnia','inne',18.0,-187.0,0,'aktywna','Autorska opuszczona osada.'),
(2,'Lothal Imperial Factory','Region Przemysłowy','lokacja','baza',65.0,-24.0,0,'aktywna','Imperialny zakład przemysłowy.'),
(2,'Lothal Fuel Depot','Region Przemysłowy','lokacja','magazyn',69.0,-27.0,0,'aktywna','Imperialny skład paliwa.'),
(2,'Strefa Kopalń','Region Przemysłowy','lokacja','inne',75.0,-32.0,0,'aktywna','Autorska strefa wydobywcza.'),
(2,'Strefa Rafineryjna','Region Przemysłowy','lokacja','inne',81.0,-29.0,0,'aktywna','Autorska strefa rafineryjna.'),
(2,'Bocznica Transportowa','Region Przemysłowy','lokacja','inne',72.0,-20.0,0,'aktywna','Autorska bocznica transportowa.'),
(2,'Obszar Starożytnych Ruin','Region Gór i Kanionów','lokacja','inne',-151.0,-91.0,0,'aktywna','Obszar starożytnych ruin na Lothal.'),
(2,'Szlak Kanionów Północnych','Region Gór i Kanionów','lokacja','inne',-139.0,-88.0,0,'aktywna','Projektowy szlak terenowy.'),
(2,'Szlak Kanionów Południowych','Region Gór i Kanionów','lokacja','inne',-147.0,-107.0,0,'aktywna','Projektowy szlak terenowy.'),
(2,'Wejście do Jaskiń','Region Gór i Kanionów','lokacja','inne',-142.0,-99.0,0,'aktywna','Projektowe wejście do jaskiń.'),
(2,'Gospodarstwo Leśne','Region Leśnych Wzgórz','lokacja','inne',166.0,-118.0,0,'aktywna','Projektowe gospodarstwo leśne.'),
(2,'Punkt Obserwacyjny Wzgórz','Region Leśnych Wzgórz','lokacja','inne',150.0,-109.0,0,'aktywna','Projektowy punkt obserwacyjny.'),
(2,'Stara Droga Południowa','Region Południowych Pustkowi','lokacja','inne',25.0,-198.0,0,'aktywna','Projektowa droga terenowa.'),
(2,'Port Kosmiczny Jhothal','Obszar Portowy Jhothal','lokacja','port',125.5,12.2,1,'aktywna','Port kosmiczny powiązany z Old Jhos Pit Stop.'),
(2,'Lothal Transmitter Tower','Region Stołeczny','budynek','inne',-4.0,4.0,1,'aktywna','Wieża komunikacyjna.'),
(2,'Gospodarstwo Zachodnie Alfa','Region Rolniczy Zachodni','lokacja','inne',-88.0,37.0,0,'aktywna','Autorskie gospodarstwo rolne.'),
(2,'Gospodarstwo Zachodnie Beta','Region Rolniczy Zachodni','lokacja','inne',-94.0,29.0,0,'aktywna','Autorskie gospodarstwo rolne.'),
(2,'Stacja Irygacyjna Zachód','Region Rolniczy Zachodni','budynek','inne',-81.0,34.0,0,'aktywna','Autorska stacja irygacyjna.'),
(2,'Skup Płodów Rolnych Zachód','Region Rolniczy Zachodni','budynek','magazyn',-91.0,38.0,0,'aktywna','Autorski punkt skupu płodów rolnych.'),
(2,'Gospodarstwo Wschodnie Alfa','Region Rolniczy Wschodni','lokacja','inne',98.0,52.0,0,'aktywna','Autorskie gospodarstwo rolne.'),
(2,'Gospodarstwo Wschodnie Beta','Region Rolniczy Wschodni','lokacja','inne',112.0,59.0,0,'aktywna','Autorskie gospodarstwo rolne.'),
(2,'Stacja Irygacyjna Wschód','Region Rolniczy Wschodni','budynek','inne',109.0,50.0,0,'aktywna','Autorska stacja irygacyjna.'),
(2,'Warsztat Maszyn Rolniczych Wschód','Region Rolniczy Wschodni','budynek','garaz',116.0,54.0,0,'aktywna','Autorski warsztat maszyn rolniczych.'),
(3,'Imperialna Instalacja Jalath','Jalath','lokacja','baza',43.0,18.0,0,'aktywna','Imperialna instalacja wojskowa.'),
(3,'Lothal Depot','Jalath','lokacja','port',44.0,16.0,0,'aktywna','Imperialny obiekt transportowy.'),
(3,'Old City','Capital City','lokacja','inne',-1.5,1.2,1,'aktywna','Starsza część Capital City.'),
(3,'Dzielnica Administracyjna','Capital City','lokacja','biuro',0.5,0.8,1,'aktywna','Projektowa dzielnica administracyjna.'),
(3,'Dzielnica Akademicka','Capital City','lokacja','inne',1.8,1.5,1,'aktywna','Projektowa dzielnica akademicka.'),
(3,'Dzielnica Dawnych Instytucji','Capital City','lokacja','inne',-0.8,1.7,1,'aktywna','Projektowa dzielnica dawnych instytucji.'),
(3,'Dzielnica Mieszkalna','Capital City','lokacja','inne',-1.2,-0.5,1,'aktywna','Projektowa dzielnica mieszkalna.'),
(3,'Dzielnica Medyczna','Capital City','lokacja','inne',1.3,-0.8,1,'aktywna','Projektowa dzielnica medyczna.'),
(3,'Dzielnica Handlowa','Capital City','lokacja','inne',-0.5,0.2,1,'aktywna','Projektowa dzielnica handlowa.'),
(3,'Port Kosmiczny Capital City','Capital City','lokacja','port',3.0,-0.5,1,'aktywna','Projektowany port kosmiczny Capital City.'),
(3,'Centrum Jalath','Jalath','lokacja','inne',42.2,18.1,1,'aktywna','Projektowe centrum miasta Jalath.'),
(3,'Główne Targowisko Tarkintown','Tarkintown','lokacja','inne',-18.2,-11.8,1,'aktywna','Projektowe targowisko osiedla.'),
(3,'Osiedle Baraków Północnych','Tarkintown','lokacja','inne',-17.8,-11.5,0,'aktywna','Projektowa część mieszkalna Tarkintown.'),
(3,'Osiedle Baraków Południowych','Tarkintown','lokacja','inne',-18.3,-12.4,0,'aktywna','Projektowa część mieszkalna Tarkintown.'),
(3,'Zbiorniki Paliwowe','Lothal Fuel Depot','lokacja','magazyn',69.2,-27.1,0,'aktywna','Projektowy obszar zbiorników paliwowych.'),
(3,'Terminal Przeładunkowy Paliwa','Lothal Fuel Depot','budynek','magazyn',69.5,-27.3,0,'aktywna','Projektowy terminal przeładunkowy paliwa.'),
(3,'Świątynia Jedi na Lothal','Obszar Starożytnych Ruin','lokacja','inne',-153.0,-94.0,0,'aktywna','Starożytna świątynia Jedi na Lothal.'),
(3,'Punkt Zaopatrzenia Tarkintown','Tarkintown','budynek','sklep',-18.1,-12.1,1,'aktywna','Projektowy punkt zaopatrzenia.'),
(3,'Warsztat Speederów Tarkintown','Tarkintown','budynek','garaz',-17.6,-12.3,1,'aktywna','Projektowy warsztat speederów.'),
(3,'Prowizoryczna Klinika Tarkintown','Tarkintown','budynek','inne',-18.5,-11.9,1,'aktywna','Projektowa klinika.'),
(3,'Kantyna Tarkintown','Tarkintown','budynek','kantyna',-17.9,-12.2,1,'aktywna','Projektowa kantyna.'),
(3,'Plac Zbiórek Tarkintown','Tarkintown','lokacja','inne',-18.4,-11.6,1,'aktywna','Projektowy plac zbiórek.'),
(3,'Magazyn Wspólnoty Tarkintown','Tarkintown','budynek','magazyn',-18.6,-12.5,0,'aktywna','Projektowy magazyn wspólnoty.'),
(3,'Posterunek Kontroli Jalath','Jalath','budynek','baza',41.7,18.3,0,'aktywna','Projektowy posterunek kontroli.'),
(3,'Główny Budynek Produkcyjny','Lothal Imperial Factory','budynek','inne',65.3,-24.1,0,'aktywna','Projektowy główny budynek produkcyjny.'),
(3,'Hala Montażowa','Lothal Imperial Factory','budynek','inne',65.6,-24.0,0,'aktywna','Projektowa hala montażowa.'),
(3,'Magazyn Komponentów','Lothal Imperial Factory','budynek','magazyn',65.8,-24.3,0,'aktywna','Projektowy magazyn komponentów.'),
(3,'Centrum Kontroli Produkcji','Lothal Imperial Factory','budynek','biuro',64.8,-23.8,0,'aktywna','Projektowe centrum kontroli produkcji.'),
(3,'Posterunek Ochrony Fabryki','Lothal Imperial Factory','budynek','baza',64.7,-24.2,0,'aktywna','Projektowy posterunek ochrony.'),
(3,'Posterunek Ochrony Fuel Depot','Lothal Fuel Depot','budynek','baza',68.8,-26.8,0,'aktywna','Projektowy posterunek ochrony składowiska paliwa.'),
(3,'Szyb Wydobywczy Alfa','Strefa Kopalń','lokacja','inne',75.2,-32.3,0,'aktywna','Projektowy szyb wydobywczy.'),
(3,'Magazyn Urobku','Strefa Kopalń','budynek','magazyn',75.5,-32.5,0,'aktywna','Projektowy magazyn urobku.'),
(3,'Rafineria Północna','Strefa Rafineryjna','budynek','inne',81.2,-28.8,0,'aktywna','Projektowa rafineria.'),
(3,'Magazyn Surowców','Bocznica Transportowa','budynek','magazyn',72.2,-20.1,0,'aktywna','Projektowy magazyn surowców.'),
(3,'Dom Olletów','Gospodarstwo Olletów','budynek','inne',-72.1,42.1,0,'aktywna','Dom rodziny Olletów.'),
(3,'Stodoła Olletów','Gospodarstwo Olletów','budynek','magazyn',-72.3,42.0,0,'aktywna','Stodoła gospodarstwa Olletów.'),
(3,'Silos Olletów','Gospodarstwo Olletów','budynek','magazyn',-72.5,42.2,0,'aktywna','Silos gospodarstwa Olletów.'),
(3,'Magazyn Płodów Rolnych Wschód','Osada Rolnicza Wschód','budynek','magazyn',121.3,61.2,0,'aktywna','Autorski magazyn płodów rolnych.'),
(3,'Punkt Skupu Wschód','Osada Rolnicza Wschód','budynek','sklep',121.5,61.1,1,'aktywna','Autorski punkt skupu.'),
(3,'Plac Osady Leśnej','Osada Leśna','lokacja','inne',159.1,-112.1,1,'aktywna','Projektowy plac osady.'),
(3,'Dom Gospodarza Leśnego','Gospodarstwo Leśne','budynek','inne',166.1,-118.1,0,'aktywna','Dom gospodarza.'),
(3,'Magazyn Gospodarstwa Leśnego','Gospodarstwo Leśne','budynek','magazyn',166.3,-118.2,0,'aktywna','Magazyn gospodarstwa leśnego.'),
(3,'Opuszczone Zabudowania Południowe','Opuszczona Osada Południowa','lokacja','inne',18.1,-187.1,0,'aktywna','Projektowe opuszczone zabudowania.'),
(3,'Stary Punkt Zaopatrzeniowy Południe','Opuszczona Osada Południowa','budynek','sklep',17.8,-187.5,0,'aktywna','Projektowy punkt zaopatrzeniowy.'),
(3,'Posterunek Drogowy Południe','Stara Droga Południowa','budynek','baza',25.2,-198.1,0,'aktywna','Projektowy posterunek drogowy.'),
(3,'Old Jhos Pit Stop','Port Kosmiczny Jhothal','budynek','kantyna',125.7,12.3,1,'aktywna','Kantyna Old Jho przy porcie kosmicznym.'),
(3,'Stanowisko Ochrony Portu Jhothal','Port Kosmiczny Jhothal','budynek','baza',126.0,12.1,0,'aktywna','Projektowe stanowisko ochrony portu.'),
(3,'Lądowisko Portu Jhothal','Port Kosmiczny Jhothal','ladowisko','platforma',126.2,12.0,1,'aktywna','Projektowe lądowisko portowe.'),
(4,'Imperial Complex','Dzielnica Administracyjna','budynek','biuro',0.8,1.1,1,'aktywna','Imperialny kompleks administracyjny.'),
(4,'Lothal Imperial Academy','Dzielnica Akademicka','budynek','inne',2.1,1.8,1,'aktywna','Imperialna akademia szkoląca kadetów.'),
(4,'Lothal Old Republic Senate Building','Dzielnica Dawnych Instytucji','budynek','biuro',-1.1,1.9,1,'aktywna','Dawny budynek senatu Starej Republiki.'),
(4,'Bridger Household','Dzielnica Mieszkalna','budynek','inne',-1.4,-0.7,0,'aktywna','Dawny dom rodziny Bridgerów.'),
(4,'Lothal Hospital','Dzielnica Medyczna','budynek','inne',1.5,-1.0,1,'aktywna','Szpital w Capital City.'),
(4,'Stary Rynek','Old City','lokacja','inne',-1.6,1.0,1,'aktywna','Projektowy stary rynek.'),
(4,'Targowisko Old City','Old City','lokacja','inne',-1.8,1.1,1,'aktywna','Projektowe targowisko.'),
(4,'Dzielnica Rzemieślnicza','Old City','lokacja','inne',-1.3,1.5,1,'aktywna','Projektowa dzielnica rzemieślnicza.'),
(4,'Kantyna Starego Miasta','Old City','budynek','kantyna',-1.9,0.8,1,'aktywna','Projektowa kantyna.'),
(4,'Terminal Pasażerski Capital City','Port Kosmiczny Capital City','budynek','inne',3.2,-0.3,1,'aktywna','Projektowy terminal pasażerski.'),
(4,'Hangar Portu Capital City','Port Kosmiczny Capital City','budynek','hangar',3.5,-0.7,1,'aktywna','Projektowy hangar portowy.'),
(4,'Lądowisko Portu Capital City','Port Kosmiczny Capital City','ladowisko','platforma',3.8,-0.2,1,'aktywna','Projektowe lądowisko.'),
(4,'Magazyn Portowy Capital City','Port Kosmiczny Capital City','budynek','magazyn',3.3,-1.0,0,'aktywna','Projektowy magazyn portowy.'),
(4,'Koszary Instalacji Jalath','Imperialna Instalacja Jalath','budynek','inne',43.1,17.9,0,'aktywna','Projektowe koszary imperialne.'),
(4,'Magazyn Instalacji Jalath','Imperialna Instalacja Jalath','budynek','magazyn',43.3,17.7,0,'aktywna','Projektowy magazyn imperialny.'),
(4,'Lądowisko Jalath','Imperialna Instalacja Jalath','ladowisko','platforma',43.5,17.8,0,'aktywna','Projektowe lądowisko instalacji.'),
(4,'Hangar Lothal Depot','Lothal Depot','budynek','hangar',44.2,16.4,0,'aktywna','Projektowy hangar.'),
(4,'Magazyn Lothal Depot','Lothal Depot','budynek','magazyn',44.4,16.6,0,'aktywna','Projektowy magazyn.'),
(4,'Kantyna Jalath','Centrum Jalath','budynek','kantyna',41.8,18.0,1,'aktywna','Projektowa kantyna.'),
(4,'Rynek Jalath','Centrum Jalath','lokacja','inne',42.1,18.3,1,'aktywna','Projektowy rynek.'),
(4,'Opuszczony Magazyn Południowy','Opuszczone Zabudowania Południowe','budynek','magazyn',18.2,-187.2,0,'aktywna','Projektowy opuszczony magazyn.'),
(4,'Zaplecze Kantyny Old Jho','Old Jhos Pit Stop','lokacja','inne',125.8,12.4,0,'aktywna','Projektowe zaplecze kantyny.'),
(5,'Warsztat Speederów Capital City','Dzielnica Rzemieślnicza','budynek','garaz',-1.1,1.6,1,'aktywna','Projektowy warsztat speederów.'),
(5,'Magazyn Kantyny Old Jho','Zaplecze Kantyny Old Jho','budynek','magazyn',125.9,12.5,0,'aktywna','Projektowy magazyn kantyny.');

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
VALUES ('v31-lokacje-lothal', 'Lokacje: Lothal (112)')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

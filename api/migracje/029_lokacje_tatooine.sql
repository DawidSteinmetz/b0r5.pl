-- Lokacje Tatooine (3 BBY): regiony, miasta i osady, infrastruktura miast, stanowiska portu Mos Eisley.
-- Układ współrzędnych gry: +X wschód, +Y północ (km), (0,0) — środek zamieszkałego obszaru.
-- Planeta wyszukiwana po nazwie. Nie zmienia struktury tabel ani istniejących rekordów.
-- Import w phpMyAdmin: zakładka SQL albo Import; ponowny import niczego nie psuje.

USE hololink;
SET NAMES utf8mb4;

SET @pl := (SELECT id FROM mapa_planety WHERE nazwa = 'Tatooine' LIMIT 1);

DROP TEMPORARY TABLE IF EXISTS tmp_import_lokacji;
CREATE TEMPORARY TABLE tmp_import_lokacji (
  poziom TINYINT UNSIGNED NOT NULL,
  nazwa VARCHAR(200) NOT NULL PRIMARY KEY,
  rodzic_nazwa VARCHAR(200) NULL,
  typ ENUM('uklad','orbita','powierzchnia','lokacja','budynek','ladowisko') NOT NULL,
  rodzaj ENUM('hangar','port','hotel','bankomat','sklep','kantyna','biuro','garaz','baza','magazyn','platforma','inne') NULL,
  x_km DECIMAL(12,3) NOT NULL,
  y_km DECIMAL(12,3) NOT NULL,
  publiczna TINYINT(1) NOT NULL,
  status ENUM('aktywna','zamknieta','zniszczona') NOT NULL,
  opis TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_import_lokacji (poziom, nazwa, rodzic_nazwa, typ, rodzaj, x_km, y_km, publiczna, status, opis) VALUES
(1,'Jundland Wastes',NULL,'powierzchnia','inne',-80.0,-180.0,0,'aktywna','Umowny region kartograficzny: skaliste pustkowia, kaniony i rozproszone gospodarstwa. Granice projektowe, nie administracyjne.'),
(1,'Dune Sea',NULL,'powierzchnia','inne',360.0,-420.0,0,'aktywna','Rozległy obszar ruchomych wydm. Region kartograficzny obejmujący między innymi okolice pałacu Jabby i Wielkiego Dołu Carkoon.'),
(1,'Mospic High Range',NULL,'powierzchnia','inne',640.0,520.0,0,'aktywna','Umowny region kartograficzny pasm i terenów wyżynnych związanych w materiałach rozszerzonych z osadami Mos Entha i Mos Taike.'),
(1,'Great Mesra Plateau',NULL,'powierzchnia','inne',-140.0,100.0,0,'aktywna','Umowny region kartograficzny płaskowyżu i terenów otaczających Mos Eisley.'),
(1,'Western Dune Sea',NULL,'powierzchnia','inne',-650.0,-520.0,0,'aktywna','Zachodnia część pustynnego obszaru Tatooine. Granice przyjęte na potrzeby mapy gry.'),
(1,'Northern Dune Sea',NULL,'powierzchnia','inne',220.0,760.0,0,'aktywna','Północny obszar pustynny. Obozowiska i trasy ludów pustyni mają charakter ruchomy.'),
(2,'Lars Homestead','Jundland Wastes','lokacja','inne',-55.0,-115.0,0,'aktywna','Gospodarstwo wilgoci rodziny Larsów. W 3 BBY funkcjonuje; zniszczenie następuje dopiero w 0 BBY. Położenie na mapie jest projektowe.'),
(2,'Ben Kenobi''s Hut','Jundland Wastes','budynek','inne',-115.0,-245.0,0,'aktywna','Pustelnia Obi-Wana Kenobiego. Dokładne współrzędne nie są kanonicznie określone; pozycja projektowa.'),
(2,'Beggar''s Canyon','Jundland Wastes','lokacja','inne',-15.0,-315.0,0,'aktywna','Charakterystyczny kanion Tatooine, znany z kanonu. Współrzędne projektowe.'),
(2,'Tusken Raider Territories – Jundland','Jundland Wastes','lokacja','inne',-180.0,-80.0,0,'aktywna','Umowny obszar występowania grup Tuskenów, nie stała osada ani wyznaczona granica plemienna.'),
(2,'Fort Tusken','Jundland Wastes','lokacja','inne',-260.0,-210.0,0,'aktywna','Lokacja z materiałów Legends. Uwzględniona jako rozszerzenie, nie jako potwierdzona instalacja kanoniczna z 3 BBY.'),
(2,'Jabba''s Palace','Dune Sea','budynek','baza',410.0,-390.0,0,'aktywna','Pałac i główna siedziba Jabby Desilijica Tiure. Istnieje w 3 BBY; szczegółowa pozycja na mapie jest projektowa.'),
(2,'Great Pit of Carkoon','Dune Sea','lokacja','inne',435.0,-445.0,0,'aktywna','Wielki Dół Carkoon, siedlisko sarlacca. Położenie względem pałacu przyjęte umownie dla mapy.'),
(2,'Jawa Territories – Dune Sea','Dune Sea','lokacja','inne',300.0,-560.0,0,'aktywna','Obszar umowny dla przemieszczających się klanów Jawów i ich sandcrawlerów; nie jest stałą osadą.'),
(2,'Tusken Raider Territories – Dune Sea','Dune Sea','lokacja','inne',520.0,-600.0,0,'aktywna','Umowny obszar aktywności Tuskenów na pustyni. Nie oznacza stałego obozu.'),
(2,'Krayt Dragon Territories','Dune Sea','lokacja','inne',620.0,-300.0,0,'aktywna','Umowny obszar występowania smoków kraytów. Granice i współrzędne są projektowe.'),
(2,'Mos Entha','Mospic High Range','lokacja','inne',600.0,520.0,1,'aktywna','Osada Tatooine uwzględniona na podstawie materiałów rozszerzonych. Jej szczegółowa infrastruktura w 3 BBY wymaga ostrożnego traktowania.'),
(2,'Mos Taike','Mospic High Range','lokacja','inne',606.0,514.0,1,'aktywna','Niewielka osada. Współrzędne zaprojektowano tak, by leżała około 8 km na południowy wschód od Mos Entha.'),
(2,'Mos Espa','Mospic High Range','lokacja','inne',620.0,500.0,1,'aktywna','Miasto handlowe i portowe, znane z Wielkiej Areny Podracerów. Pozycja zachowuje umowną relację przestrzenną z Mos Entha i Mos Taike.'),
(2,'Mos Eisley','Great Mesra Plateau','lokacja','inne',-140.0,100.0,1,'aktywna','Duże miasto portowe i ośrodek handlowo-przemytniczy. Ważny punkt komunikacyjny Tatooine.'),
(2,'Anchorhead','Great Mesra Plateau','lokacja','inne',-95.0,45.0,1,'aktywna','Osada w pobliżu Mos Eisley, znana między innymi z Tosche Station. Współrzędne projektowe.'),
(2,'Bestine','Western Dune Sea','lokacja','inne',-620.0,-470.0,1,'aktywna','Miasto Tatooine. Imperialna obecność i szczegóły administracyjne są w dużej mierze rozwijane w Legends; nie zakłada się tu konkretnej wielkości garnizonu.'),
(2,'Mos Elrey','Western Dune Sea','lokacja','inne',-720.0,-590.0,1,'aktywna','Osada uwzględniona w rozszerzonym katalogu lokacji Tatooine. Współrzędne projektowe.'),
(2,'Wayfar','Western Dune Sea','lokacja','inne',-820.0,-650.0,1,'aktywna','Osada z materiałów Legends. Nie jest przedstawiana jako kanoniczna.'),
(2,'Arnthout','Western Dune Sea','lokacja','inne',-570.0,-650.0,1,'aktywna','Osada z materiałów Legends, uwzględniona jako punkt rozszerzonej mapy.'),
(2,'Motesta','Western Dune Sea','lokacja','inne',-500.0,-700.0,1,'aktywna','Osada z materiałów Legends, uwzględniona jako punkt rozszerzonej mapy.'),
(2,'Mos Ila','Western Dune Sea','lokacja','inne',-880.0,-500.0,1,'aktywna','Osada z materiałów Legends. Współrzędne projektowe.'),
(2,'Mos Gamos','Northern Dune Sea','lokacja','inne',260.0,820.0,1,'aktywna','Osada z materiałów Legends. Współrzędne projektowe.'),
(2,'Mos Shuuta','Northern Dune Sea','lokacja','inne',120.0,680.0,1,'aktywna','Osada znana z materiałów Legends i gier. Współrzędne projektowe.'),
(3,'Mos Eisley Spaceport','Mos Eisley','ladowisko','port',-132.0,94.0,1,'aktywna','Port kosmiczny Mos Eisley. Lokalizacja centralna względem miejskiego skupiska infrastruktury.'),
(3,'Chalmun''s Cantina','Mos Eisley','budynek','kantyna',-145.0,105.0,1,'aktywna','Kantyna Chalmunna, znany lokal pilotów, przemytników i podróżnych.'),
(3,'Mos Eisley Commercial District','Mos Eisley','lokacja','inne',-150.0,115.0,1,'aktywna','Projektowa nazwa obszaru handlowego; grupuje sklepy i usługi, nie jest nazwą kanonicznej dzielnicy.'),
(3,'Mos Eisley Residential District','Mos Eisley','lokacja','inne',-125.0,125.0,0,'aktywna','Projektowy węzeł zabudowy mieszkalnej Mos Eisley.'),
(3,'Mos Eisley Underworld District','Mos Eisley','lokacja','inne',-160.0,85.0,0,'aktywna','Projektowy obszar punktów kontaktowych i działalności przestępczej; bez przypisywania konkretnego syndykatu do całej dzielnicy.'),
(3,'Mos Espa Spaceport','Mos Espa','ladowisko','port',626.0,497.0,1,'aktywna','Port kosmiczny Mos Espa; szczegółowy układ stanowisk jest projektowy.'),
(3,'Mos Espa Grand Arena','Mos Espa','budynek','inne',615.0,505.0,1,'aktywna','Wielka Arena Podracerów, znany obiekt miasta.'),
(3,'Mos Espa Commercial District','Mos Espa','lokacja','inne',622.0,508.0,1,'aktywna','Projektowy węzeł handlowy grupujący sklepy i punkty usługowe.'),
(3,'Mos Espa Hutt District','Mos Espa','lokacja','inne',632.0,510.0,0,'aktywna','Projektowy węzeł obiektów pozostających pod wpływem lokalnych organizacji Huttów.'),
(3,'Mos Espa Residential District','Mos Espa','lokacja','inne',610.0,492.0,0,'aktywna','Projektowy węzeł zabudowy mieszkalnej.'),
(3,'Tosche Station','Anchorhead','budynek','inne',-92.0,42.0,1,'aktywna','Elektrownia i warsztat maszynowy w okolicy Anchorhead.'),
(3,'Anchorhead General Store','Anchorhead','budynek','sklep',-99.0,48.0,1,'aktywna','Projektowa nazwa sklepu wielobranżowego; nie jest deklaracją kanonicznej nazwy placówki.'),
(3,'Anchorhead Cantina','Anchorhead','budynek','kantyna',-88.0,50.0,1,'aktywna','Projektowy lokal usługowy dla mieszkańców, przewoźników i podróżnych.'),
(3,'Anchorhead Repair Workshop','Anchorhead','budynek','garaz',-103.0,40.0,1,'aktywna','Projektowy warsztat naprawczy.'),
(3,'Anchorhead Warehouse','Anchorhead','budynek','magazyn',-85.0,38.0,0,'aktywna','Projektowy skład lokalnych towarów i części.'),
(3,'Bestine Administrative Center','Bestine','budynek','biuro',-615.0,-465.0,1,'aktywna','Projektowy obiekt administracji miejskiej.'),
(3,'Bestine Imperial Post','Bestine','budynek','baza',-630.0,-475.0,0,'aktywna','Projektowy posterunek imperialny. Jego obecność w tej konkretnej postaci jest założeniem rozgrywki, nie potwierdzonym kanonicznym obiektem.'),
(3,'Bestine Commercial District','Bestine','lokacja','inne',-605.0,-480.0,1,'aktywna','Projektowy węzeł handlowy miasta.'),
(3,'Bestine Warehouse','Bestine','budynek','magazyn',-625.0,-455.0,0,'aktywna','Projektowy magazyn miejski.'),
(3,'Jabba Palace Courtyard','Jabba''s Palace','lokacja','inne',415.0,-385.0,0,'aktywna','Dziedziniec pałacu; podlokacja projektowa w ramach znanego obiektu.'),
(3,'Jabba Palace Guard Quarters','Jabba''s Palace','budynek','baza',405.0,-395.0,0,'aktywna','Pomieszczenia straży; podlokacja projektowa.'),
(3,'Jabba Palace Storage','Jabba''s Palace','budynek','magazyn',420.0,-400.0,0,'aktywna','Zaplecze magazynowe pałacu; podlokacja projektowa.'),
(3,'Lars Moisture Farm','Lars Homestead','budynek','inne',-52.0,-112.0,0,'aktywna','Instalacje farmy wilgoci; nazwa i punkt reprezentujący zabudowania gospodarstwa, współrzędne projektowe.'),
(4,'Docking Bay 94','Mos Eisley Spaceport','ladowisko','hangar',-130.0,92.0,1,'aktywna','Stanowisko dokujące znane z filmu Nowa nadzieja.'),
(4,'Mos Eisley Port Control','Mos Eisley Spaceport','budynek','biuro',-134.0,96.0,1,'aktywna','Projektowy obiekt kontroli i obsługi ruchu portowego.'),
(4,'Mos Eisley Port Workshop','Mos Eisley Spaceport','budynek','garaz',-128.0,89.0,1,'aktywna','Projektowy warsztat obsługi technicznej statków i sprzętu portowego.'),
(4,'Mos Eisley Port Warehouse','Mos Eisley Spaceport','budynek','magazyn',-136.0,88.0,0,'aktywna','Projektowy magazyn portowy.');

-- Import poziom po poziomie: rodzic odnajdywany po nazwie na tej planecie. Istniejące rekordy
-- (ta sama planeta i nazwa) zostają bez zmian, więc ponowny import tylko dokłada brakujące.
START TRANSACTION;

INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
SELECT t.typ, t.rodzaj, r.id, NULL, @pl, t.nazwa, t.x_km, t.y_km, t.publiczna, t.status, t.opis
FROM tmp_import_lokacji t LEFT JOIN lokalizacje r ON r.planeta_id = @pl AND r.nazwa = t.rodzic_nazwa
WHERE t.poziom = 1 AND @pl IS NOT NULL AND (t.rodzic_nazwa IS NULL OR r.id IS NOT NULL)
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
SELECT t.typ, t.rodzaj, r.id, NULL, @pl, t.nazwa, t.x_km, t.y_km, t.publiczna, t.status, t.opis
FROM tmp_import_lokacji t LEFT JOIN lokalizacje r ON r.planeta_id = @pl AND r.nazwa = t.rodzic_nazwa
WHERE t.poziom = 2 AND @pl IS NOT NULL AND (t.rodzic_nazwa IS NULL OR r.id IS NOT NULL)
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
SELECT t.typ, t.rodzaj, r.id, NULL, @pl, t.nazwa, t.x_km, t.y_km, t.publiczna, t.status, t.opis
FROM tmp_import_lokacji t LEFT JOIN lokalizacje r ON r.planeta_id = @pl AND r.nazwa = t.rodzic_nazwa
WHERE t.poziom = 3 AND @pl IS NOT NULL AND (t.rodzic_nazwa IS NULL OR r.id IS NOT NULL)
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
SELECT t.typ, t.rodzaj, r.id, NULL, @pl, t.nazwa, t.x_km, t.y_km, t.publiczna, t.status, t.opis
FROM tmp_import_lokacji t LEFT JOIN lokalizacje r ON r.planeta_id = @pl AND r.nazwa = t.rodzic_nazwa
WHERE t.poziom = 4 AND @pl IS NOT NULL AND (t.rodzic_nazwa IS NULL OR r.id IS NOT NULL)
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
VALUES ('v29-lokacje-tatooine', 'Lokacje: Tatooine (56)')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

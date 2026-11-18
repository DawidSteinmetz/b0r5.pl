-- Kurs jednostek, manewr przed skokiem, cykle planet (doba, rok, pory roku) i księżyce.
--
-- KURS: kurs_deg to kierunek dziobu w nieruchomym układzie mapy (0° = wschód, 90° = północ,
-- przeciwnie do ruchu wskazówek zegara). Na orbicie ten sam kąt mówi, po której stronie planety
-- stoi jednostka (dziób od planety, rufa ku planecie). Manewr to płynna zmiana kursu (i miejsca
-- na orbicie) od kurs_start_deg do kurs_cel_deg w czasie manewr_od_utc → manewr_do_utc.
--
-- CYKLE PLANET: epoka 0 = 1|1|0 00:00 (1.01.2010 00:00 czasu polskiego). Planeta bez wiersza
-- w planety_cykle dostaje powtarzalne wartości domyślne wyliczane z nazwy (api/astro.php).
-- NULL w kolumnie = wartość domyślna. Księżyce z planety_ksiezyce zastępują wygenerowane.
-- Docelowy, pełny model orbit (uklady_planetarne, planety_orbity) pozostaje fundamentem.
--
-- Wymaga migracji 014. Import w phpMyAdmin: zakładka SQL albo Import. Importuj raz; ponowny import zatrzyma się na „Duplicate column”.

USE hololink;
SET NAMES utf8mb4;

-- ---------- kurs i manewr jednostek mapy ----------

ALTER TABLE mapa_obiekty
  ADD COLUMN kurs_deg DECIMAL(6,2) NOT NULL DEFAULT 90 AFTER y,
  ADD COLUMN kurs_start_deg DECIMAL(6,2) NULL AFTER kurs_deg,
  ADD COLUMN kurs_cel_deg DECIMAL(6,2) NULL AFTER kurs_start_deg,
  ADD COLUMN manewr_od_utc DATETIME NULL AFTER kurs_cel_deg,
  ADD COLUMN manewr_do_utc DATETIME NULL AFTER manewr_od_utc;

-- Tempo obrotu (i przelotu po orbicie) w stopniach na minutę; flota manewruje tempem najwolniejszej jednostki.
ALTER TABLE mapa_naped_jednostki
  ADD COLUMN obrot_deg_na_min DECIMAL(6,2) NOT NULL DEFAULT 90 AFTER szum_bazowy;

-- Domyślne tempa według klasy (tylko tam, gdzie wciąż jest wartość domyślna).
UPDATE mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id
  SET n.obrot_deg_na_min = 30
  WHERE n.obrot_deg_na_min = 90 AND (o.klasa LIKE '%Imperial%' OR o.klasa LIKE '%ISD%' OR o.nazwa LIKE 'ISD%');
UPDATE mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id
  SET n.obrot_deg_na_min = 60
  WHERE n.obrot_deg_na_min = 90 AND (o.klasa LIKE '%Arquitens%' OR o.nazwa LIKE 'Arquitens%');

-- Rozkaz skoku: chwila wejścia w nadprzestrzeń (po manewrze) i kurs skoku.
ALTER TABLE mapa_rozkazy_skokow
  ADD COLUMN wejscie_utc DATETIME NULL AFTER wydano_utc,
  ADD COLUMN kurs_deg DECIMAL(6,2) NULL AFTER cel_y;

-- Resynchronizacja obejmuje też manewry (zmiana kursu / pozycji na orbicie).
ALTER TABLE resynchronizacje
  MODIFY rodzaj ENUM('skok_floty','podroz','obsluga_statku','skok_statku','manewr') NOT NULL;

-- Wykrycie radarowe może dotyczyć skoku, który zacznie się po manewrze (widoczne od wykryto_utc).

-- ---------- cykle planet i księżyce ----------

CREATE TABLE IF NOT EXISTS planety_cykle (
  planeta_id INT UNSIGNED NOT NULL PRIMARY KEY,
  doba_h DECIMAL(10,4) NULL COMMENT 'doba słoneczna w godzinach (NULL = z mapa_planety.doba)',
  rok_dni DECIMAL(12,4) NULL COMMENT 'rok w dniach (NULL = z mapa_planety.rok)',
  nachylenie_osi_deg DECIMAL(6,3) NULL COMMENT 'nachylenie osi: amplituda pór roku',
  faza_obrotu_deg DECIMAL(7,3) NULL COMMENT 'kąt godzinny południka 0 w epoce (0 = południe, 180 = północ)',
  faza_roku_deg DECIMAL(7,3) NULL COMMENT 'faza pór roku w epoce: deklinacja = nachylenie · sin(360·d/rok + faza)',
  faza_orbity_deg DECIMAL(7,3) NULL COMMENT 'położenie planety wokół słońca w epoce (układ mapy)',
  promien_orbity_au DECIMAL(10,4) NULL COMMENT 'promień orbity wokół słońca (widok układu)',
  srednica_km DECIMAL(12,2) NULL COMMENT 'średnica (NULL = z mapa_planety.srednica)',
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS planety_ksiezyce (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  planeta_id INT UNSIGNED NOT NULL,
  nazwa VARCHAR(120) NOT NULL,
  promien_orbity_km DECIMAL(14,1) NOT NULL CHECK (promien_orbity_km > 0),
  okres_dni DECIMAL(12,4) NOT NULL CHECK (okres_dni > 0) COMMENT 'obieg gwiazdowy wokół planety',
  faza_deg DECIMAL(7,3) NOT NULL DEFAULT 0 COMMENT 'położenie w epoce (układ mapy)',
  srednica_km DECIMAL(12,2) NULL,
  UNIQUE KEY uq_planety_ksiezyce (planeta_id, nazwa),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zephyria = Ziemia: doba 24 h, rok zwrotnikowy, nachylenie 23,44°. Faza obrotu: epoka to
-- 31.12.2009 23:00 UTC, czyli 23:00 czasu słonecznego na południku 0 → kąt godzinny 165°.
-- Pory roku: przesilenie zimowe ok. 10,5 dnia przed epoką → faza 280,35°.
-- Pełnia księżyca 31.12.2009 → księżyc po stronie przeciwnej słońcu (faza = faza orbity).
INSERT INTO planety_cykle (planeta_id, doba_h, rok_dni, nachylenie_osi_deg, faza_obrotu_deg, faza_roku_deg,
                           faza_orbity_deg, promien_orbity_au, srednica_km)
SELECT id, 24, 365.2422, 23.44, 165, 280.35, 100, 1, 12742 FROM mapa_planety WHERE nazwa = 'Zephyria'
ON DUPLICATE KEY UPDATE doba_h = VALUES(doba_h), rok_dni = VALUES(rok_dni), nachylenie_osi_deg = VALUES(nachylenie_osi_deg),
  faza_obrotu_deg = VALUES(faza_obrotu_deg), faza_roku_deg = VALUES(faza_roku_deg), faza_orbity_deg = VALUES(faza_orbity_deg),
  promien_orbity_au = VALUES(promien_orbity_au), srednica_km = VALUES(srednica_km);

INSERT INTO planety_ksiezyce (planeta_id, nazwa, promien_orbity_km, okres_dni, faza_deg, srednica_km)
SELECT id, 'Księżyc', 384400, 27.3217, 100, 3474.8 FROM mapa_planety WHERE nazwa = 'Zephyria'
ON DUPLICATE KEY UPDATE promien_orbity_km = VALUES(promien_orbity_km), okres_dni = VALUES(okres_dni),
  faza_deg = VALUES(faza_deg), srednica_km = VALUES(srednica_km);

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v15-kurs-manewry-cykle', 'Kurs i manewry jednostek, wejście w skok po manewrze, cykle planet i księżyce')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

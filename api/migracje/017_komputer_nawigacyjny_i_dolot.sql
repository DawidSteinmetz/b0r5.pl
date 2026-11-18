-- Komputer nawigacyjny okrętu, warianty celu skoku (orbita bliska/średnia/daleka, krawędź układu)
-- i dolot na orbitę napędem podświetlnym.
--
-- komputer_klasa (1–5): jakość automatu; im wyższa, tym mniejszy rozrzut wyjścia z nadprzestrzeni.
-- komputer_stan: sprawny | zhakowany (automat działa, ale po cichu podaje zły wynik) |
--                przejety | brak (automatu nie ma: mostek liczy sam, ręczne współrzędne,
--                dłuższe obliczenia przed manewrem, większy rozrzut).
-- podejscie: gdzie w układzie jest okręt po wyjściu ze skoku: bliska / srednia / daleka orbita
--            albo krawedz układu. Dolot (podlot_*) zmienia go napędem podświetlnym, z czasem.
--
-- Wymaga migracji 016. Import w phpMyAdmin (najpierw kliknij bazę hololink); ponowny import niczego nie psuje.

SET NAMES utf8mb4;

SET @brak := (SELECT COUNT(*) = 0 FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'mapa_naped_jednostki' AND column_name = 'komputer_klasa');
SET @sql := IF(@brak, 'ALTER TABLE mapa_naped_jednostki
  ADD COLUMN komputer_klasa TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER obrot_deg_na_min,
  ADD COLUMN komputer_stan ENUM(''sprawny'',''zhakowany'',''przejety'',''brak'') NOT NULL DEFAULT ''sprawny'' AFTER komputer_klasa', 'SELECT 1');
PREPARE polecenie FROM @sql; EXECUTE polecenie; DEALLOCATE PREPARE polecenie;

-- ISD: fachowy, wydajny komputer (5); Arquitens 4; reszta 3 (tylko tam, gdzie wartość domyślna).
UPDATE mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id
  SET n.komputer_klasa = 5
  WHERE n.komputer_klasa = 3 AND (o.klasa LIKE '%Imperial%' OR o.klasa LIKE '%ISD%' OR o.nazwa LIKE 'ISD%');
UPDATE mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id
  SET n.komputer_klasa = 4
  WHERE n.komputer_klasa = 3 AND (o.klasa LIKE '%Arquitens%' OR o.nazwa LIKE 'Arquitens%');

SET @brak := (SELECT COUNT(*) = 0 FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'mapa_obiekty' AND column_name = 'podejscie');
SET @sql := IF(@brak, 'ALTER TABLE mapa_obiekty
  ADD COLUMN podejscie ENUM(''bliska'',''srednia'',''daleka'',''krawedz'') NULL AFTER przylot_utc,
  ADD COLUMN podlot_start ENUM(''bliska'',''srednia'',''daleka'',''krawedz'') NULL AFTER podejscie,
  ADD COLUMN podlot_cel ENUM(''bliska'',''srednia'',''daleka'',''krawedz'') NULL AFTER podlot_start,
  ADD COLUMN podlot_od_utc DATETIME NULL AFTER podlot_cel,
  ADD COLUMN podlot_do_utc DATETIME NULL AFTER podlot_od_utc', 'SELECT 1');
PREPARE polecenie FROM @sql; EXECUTE polecenie; DEALLOCATE PREPARE polecenie;

SET @brak := (SELECT COUNT(*) = 0 FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'mapa_rozkazy_skokow' AND column_name = 'wariant');
SET @sql := IF(@brak, 'ALTER TABLE mapa_rozkazy_skokow
  ADD COLUMN wariant ENUM(''bliska'',''srednia'',''daleka'',''krawedz'') NULL AFTER kurs_deg,
  ADD COLUMN tryb ENUM(''automat'',''reczny'') NOT NULL DEFAULT ''automat'' AFTER wariant', 'SELECT 1');
PREPARE polecenie FROM @sql; EXECUTE polecenie; DEALLOCATE PREPARE polecenie;

-- Okręty już stojące przy planecie są na bliskiej orbicie.
UPDATE mapa_obiekty SET podejscie = 'bliska'
  WHERE podejscie IS NULL AND planeta_id IS NOT NULL AND typ IN ('statek', 'jednostka');

ALTER TABLE resynchronizacje
  MODIFY rodzaj ENUM('skok_floty','podroz','obsluga_statku','skok_statku','manewr','dolot') NOT NULL;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v17-komputer-nawigacyjny-dolot', 'Komputer nawigacyjny, warianty celu skoku, dolot na orbitę')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

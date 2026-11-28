-- Szacunkowe poparcie polityczne na planetach, stan świata gry: 3 BBY.
-- Wartości są udziałami procentowymi (0–100) i sumują się do 100 dla każdego
-- opisanego świata. Źródła opisują kontrolę, nastroje i aktywność frakcji, nie
-- podają sondaży; liczby są więc modelem HOLOLINK, a nie danymi kanonicznymi.
-- Pozostałe planety pozostają NULL do czasu ustalenia wiarygodnych przesłanek.
-- Frakcje są celowo zagregowane: Imperium, Rebelia, Syndykat.

SET NAMES utf8mb4;

SET @ddl := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mapa_planety' AND COLUMN_NAME='poparcie_imperium_pct'),
  'SELECT 1',
  'ALTER TABLE mapa_planety ADD COLUMN poparcie_imperium_pct DECIMAL(5,2) NULL COMMENT ''Szacunkowe poparcie Imperium w procentach (model gry, 3 BBY)'''
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mapa_planety' AND COLUMN_NAME='poparcie_rebelia_pct'),
  'SELECT 1',
  'ALTER TABLE mapa_planety ADD COLUMN poparcie_rebelia_pct DECIMAL(5,2) NULL COMMENT ''Szacunkowe poparcie Rebelii w procentach (model gry, 3 BBY)'''
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mapa_planety' AND COLUMN_NAME='poparcie_syndykat_pct'),
  'SELECT 1',
  'ALTER TABLE mapa_planety ADD COLUMN poparcie_syndykat_pct DECIMAL(5,2) NULL COMMENT ''Szacunkowe poparcie Syndykatu w procentach (model gry, 3 BBY)'''
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE mapa_planety p
JOIN (
  SELECT 'Alderaan' AS nazwa, 10.00 AS imperium, 85.00 AS rebelia, 5.00 AS syndykat UNION ALL
  SELECT 'Corellia', 35.00, 50.00, 15.00 UNION ALL
  SELECT 'Coruscant', 80.00, 15.00, 5.00 UNION ALL
  SELECT 'Dromund Kaas', 80.00, 15.00, 5.00 UNION ALL
  SELECT 'Eriadu', 70.00, 20.00, 10.00 UNION ALL
  SELECT 'Foundry', 70.00, 20.00, 10.00 UNION ALL
  SELECT 'Havis III', 80.00, 10.00, 10.00 UNION ALL
  SELECT 'Kessel', 25.00, 10.00, 65.00 UNION ALL
  SELECT 'Kuat', 80.00, 15.00, 5.00 UNION ALL
  SELECT 'Lothal', 30.00, 60.00, 10.00 UNION ALL
  SELECT 'Mygeeto', 20.00, 70.00, 10.00 UNION ALL
  SELECT 'Nal Hutta', 5.00, 5.00, 90.00 UNION ALL
  SELECT 'Naboo', 70.00, 25.00, 5.00 UNION ALL
  SELECT 'Nar Shaddaa', 5.00, 10.00, 85.00 UNION ALL
  SELECT 'Ord Mantell', 20.00, 20.00, 60.00 UNION ALL
  SELECT 'Tatooine', 10.00, 10.00, 80.00 UNION ALL
  SELECT 'Zephyria', 80.00, 10.00, 10.00
) s ON s.nazwa=p.nazwa
SET p.poparcie_imperium_pct=s.imperium,
    p.poparcie_rebelia_pct=s.rebelia,
    p.poparcie_syndykat_pct=s.syndykat;

INSERT INTO schema_migracje (wersja,opis)
VALUES ('v62-poparcie-stronnictw-planet','Szacunkowe poparcie Imperium, Rebelii i Syndykatu na planetach (3 BBY)')
ON DUPLICATE KEY UPDATE opis=VALUES(opis);

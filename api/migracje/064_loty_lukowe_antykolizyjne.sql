-- Zapis trasy lotu podświetlnego jako punktów stycznych omijających planety i gwiazdy.
-- Dzięki temu mapa, pokład i przerwanie lotu korzystają z tej samej krzywej.
USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_obiekty
  ADD COLUMN lot_trasa_json MEDIUMTEXT NULL AFTER lot_cel_ky;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v64-loty-lukowe-antykolizyjne', 'Trasy lotów podświetlnych omijają ciała układu łukiem')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

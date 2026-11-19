-- Lot podświetlny i role osób na pokładzie.
--
-- mapa_obiekty.lot_*: trwający lot podświetlny jednostki (czas gry 1:1, MG może go przyspieszyć).
--   lot_start_x/y → lot_cel_x/y (współrzędne mapy, pc), lot_cel_planeta_id (planeta albo księżyc
--   celu w tym samym układzie), lot_km (długość trasy), lot_od_utc (start silników po obrocie)
--   i lot_do_utc (przylot). Po przylocie kolumny wracają do NULL.
-- mapa_obiekty.rola_na_pokladzie: postać na pokładzie statku jest pasażerem albo więźniem
--   (karta statku na mapie: zakładki „Pasażerowie” i „Więźniowie”; MG zmienia rolę).
-- mapa_naped_jednostki.predkosc_podswietlna_kms: nowe prędkości przelotowe (km/s); ta sama prędkość
--   liczy też drogę do punktu skoku, więc ten etap się skraca.
--
-- Import w phpMyAdmin: zakładka SQL albo Import. Ponowny import niczego nie psuje
-- (jeśli kolumny już są, ALTER zgłosi „Duplicate column” — wtedy wystarczy reszta pliku od UPDATE).

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_obiekty
  ADD COLUMN lot_start_x DECIMAL(12,1) NULL,
  ADD COLUMN lot_start_y DECIMAL(12,1) NULL,
  ADD COLUMN lot_cel_x DECIMAL(12,1) NULL,
  ADD COLUMN lot_cel_y DECIMAL(12,1) NULL,
  ADD COLUMN lot_cel_planeta_id INT UNSIGNED NULL,
  ADD COLUMN lot_km DECIMAL(20,1) NULL,
  ADD COLUMN lot_od_utc DATETIME NULL,
  ADD COLUMN lot_do_utc DATETIME NULL,
  ADD COLUMN rola_na_pokladzie ENUM('pasazer','wiezien') NOT NULL DEFAULT 'pasazer';

-- Prędkości podświetlne według klasy (km/s).
UPDATE mapa_naped_jednostki SET predkosc_podswietlna_kms = 1500;
UPDATE mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id SET n.predkosc_podswietlna_kms = 2000
  WHERE o.klasa LIKE '%Arquitens%' OR o.nazwa LIKE 'Arquitens%' OR o.klasa LIKE '%Gozanti%' OR o.nazwa LIKE 'Gozanti%';
UPDATE mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id SET n.predkosc_podswietlna_kms = 3000
  WHERE o.klasa LIKE '%Kom%rk%';

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v28-lot-podswietlny-poklad', 'Lot podświetlny (mapa_obiekty.lot_*), rola na pokładzie, prędkości podświetlne')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

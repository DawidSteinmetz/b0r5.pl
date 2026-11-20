-- Pozycje wewnątrz układu planetarnego.
--
-- Dotąd jednostka w układzie mogła być tylko przy planecie. Teraz może stać w dowolnym punkcie układu:
-- mapa_obiekty.uklad_kx / uklad_ky — przesunięcie od słońca układu w km (osie mapy: x na wschód,
-- y na północ), gdy jednostka nie stoi przy planecie (planeta_id NULL). x, y to dalej współrzędne układu.
--   • skok na „krawędź układu” kończy się na brzegu całego układu (50 · √L j.a. od słońca),
--   • skok i lot podświetlny do punktu między planetą a słońcem,
--   • studnie grawitacyjne są wokół ciał (słońce: 20 jego promieni, planeta: 100 jej promieni), nie wokół
--     całego układu; skok automatem na orbitę planety dalej jest dozwolony.
-- Lot podświetlny i skok pamiętają początek i cel w km (lot_*_kx/ky, wyjscie_kx/ky).
--
-- Dawne pozycje „krawędź układu” (orbita 60 promieni planety) stają się daleką orbitą; na prawdziwy
-- brzeg układu prowadzi teraz skok z wariantem „krawędź układu” albo lot podświetlny.
--
-- Import w phpMyAdmin. Ponowny import: ALTER zgłosi „Duplicate column” — wtedy wystarczy reszta pliku.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_obiekty
  ADD COLUMN uklad_kx DECIMAL(20,1) NULL,
  ADD COLUMN uklad_ky DECIMAL(20,1) NULL,
  ADD COLUMN lot_start_kx DECIMAL(20,1) NULL,
  ADD COLUMN lot_start_ky DECIMAL(20,1) NULL,
  ADD COLUMN lot_cel_kx DECIMAL(20,1) NULL,
  ADD COLUMN lot_cel_ky DECIMAL(20,1) NULL;

ALTER TABLE mapa_rozkazy_skokow_jednostki
  ADD COLUMN wyjscie_kx DECIMAL(20,1) NULL,
  ADD COLUMN wyjscie_ky DECIMAL(20,1) NULL;

-- Dawna krawędź (60 promieni planety) to teraz daleka orbita; na brzeg układu prowadzi lot podświetlny.
UPDATE mapa_obiekty SET podejscie = 'daleka' WHERE podejscie = 'krawedz';

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v37-pozycje-w-ukladzie', 'Pozycje w układzie (km od słońca), krawędź całego układu, studnie wokół ciał')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

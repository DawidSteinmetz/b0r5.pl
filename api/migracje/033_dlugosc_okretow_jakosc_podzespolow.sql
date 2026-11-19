-- Rzeczywista skala na mapie i skutki przerwania skoku.
--
-- mapa_obiekty.dlugosc_m: długość jednostki (m) — mapa w rzeczywistej skali rysuje sylwetkę tej długości.
-- mapa_naped_jednostki.jakosc_podzespolow (1–5, domyślnie 3): jakość reaktora, hipernapędu i osłon.
--   Przerwanie skoku w nadprzestrzeni uszkadza je tym mniej, im wyższa jakość (1: ×1,6 … 5: ×0,4).
--
-- Import w phpMyAdmin: zakładka SQL albo Import. Ponowny import: ALTER zgłosi „Duplicate column” —
-- wtedy wystarczy reszta pliku od UPDATE.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_obiekty
  ADD COLUMN dlugosc_m DECIMAL(8,1) NULL;

ALTER TABLE mapa_naped_jednostki
  ADD COLUMN jakosc_podzespolow TINYINT UNSIGNED NOT NULL DEFAULT 3;

-- Długości według klasy (m); pozostałe jednostki 100 m.
UPDATE mapa_obiekty SET dlugosc_m = 1600
  WHERE dlugosc_m IS NULL AND (klasa LIKE '%Imperial%' OR klasa LIKE '%ISD%' OR nazwa LIKE 'ISD%');
UPDATE mapa_obiekty SET dlugosc_m = 450
  WHERE dlugosc_m IS NULL AND (klasa LIKE '%Arquitens%' OR nazwa LIKE 'Arquitens%');
UPDATE mapa_obiekty SET dlugosc_m = 64
  WHERE dlugosc_m IS NULL AND (klasa LIKE '%Gozanti%' OR nazwa LIKE 'Gozanti%');
UPDATE mapa_obiekty SET dlugosc_m = 75
  WHERE dlugosc_m IS NULL AND klasa LIKE '%Kom%rk%';
UPDATE mapa_obiekty SET dlugosc_m = 100
  WHERE dlugosc_m IS NULL AND typ IN ('statek', 'jednostka', 'pojazd');

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v33-dlugosc-okretow-jakosc', 'Długość jednostek (mapa w rzeczywistej skali), jakość podzespołów (przerwanie skoku)')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

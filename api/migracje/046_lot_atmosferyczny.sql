-- Lot w atmosferze: trzeci parametr silnika (obok hipernapędu i napędu podświetlnego) i trasa nad powierzchnią.
--
-- mapa_naped_jednostki.atmosferyczna_kmh — prędkość w atmosferze (km/h). Lądowanie: wejście w atmosferę pod
-- miejscem okrętu na orbicie, potem lot po łuku wielkiego koła do lądowiska (choćby na drugą stronę planety).
-- Start: lot w atmosferze pod okręt macierzysty (prom) albo prosto w górę, potem wznoszenie na orbitę.
-- mapa_obiekty.atm_*: trwający lot — współrzędne geograficzne początku i końca, czas, rodzaj (ladowanie/start).
-- Mapa rysuje jednostkę na tarczy planety w miejscu lądowiska (widok z góry: półkula północna, południowa tak samo).
--
-- Import w phpMyAdmin. Ponowny import: ALTER zgłosi „Duplicate column” — wtedy wystarczy reszta pliku.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_naped_jednostki
  ADD COLUMN atmosferyczna_kmh DECIMAL(8,1) NOT NULL DEFAULT 1000;

ALTER TABLE mapa_obiekty
  ADD COLUMN atm_rodzaj ENUM('ladowanie','start') NULL,
  ADD COLUMN atm_od_utc DATETIME NULL,
  ADD COLUMN atm_do_utc DATETIME NULL,
  ADD COLUMN atm_szer0 DECIMAL(8,4) NULL,
  ADD COLUMN atm_dl0 DECIMAL(9,4) NULL,
  ADD COLUMN atm_szer1 DECIMAL(8,4) NULL,
  ADD COLUMN atm_dl1 DECIMAL(9,4) NULL,
  ADD COLUMN atm_orbita_deg DECIMAL(8,3) NULL COMMENT 'miejsce na orbicie po starcie';

-- Prędkości atmosferyczne znanych klas (km/h).
UPDATE mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id SET n.atmosferyczna_kmh = 850 WHERE o.klasa LIKE '%Lambda%' OR o.nazwa LIKE '%Lambda%';
UPDATE mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id SET n.atmosferyczna_kmh = 1050 WHERE o.klasa LIKE '%YT-1300%' OR o.klasa LIKE '%YT-2400%';
UPDATE mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id SET n.atmosferyczna_kmh = 800 WHERE o.klasa LIKE '%Gozanti%' OR o.nazwa LIKE '%Gozanti%';
UPDATE mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id SET n.atmosferyczna_kmh = 1100 WHERE o.klasa LIKE '%Kom%rk%' OR o.nazwa LIKE '%Kom%rk%';

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v46-lot-atmosferyczny', 'Lot w atmosferze: prędkość atmosferyczna i trasa nad powierzchnią')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

-- ZASTĄPIONA przez 039 (prędkość przelotowa w pc na dobę): tej migracji nie trzeba importować;
-- jeśli już jest w bazie, kolumna przyspieszenie_g po prostu nie jest używana.
--
-- Silniki podświetlne: przyspieszenie zamiast stałej prędkości.
--
-- mapa_naped_jednostki.przyspieszenie_g — przyspieszenie (w G = 9,81 m/s²). Lot podświetlny to rozpędzanie
-- do połowy drogi i hamowanie: czas = 2 · √(droga / przyspieszenie). Flota leci tempem najsłabszej jednostki.
-- ISD (2300 G): planeta → księżyc ok. 5 min, 1 j.a. ok. 1,4 h, brzeg układu (50 j.a.) → planeta ok. 10 h,
-- 1 pc ok. 27 dni. MG może zmienić każdą jednostkę: UPDATE mapa_naped_jednostki SET przyspieszenie_g = …
--
-- Import w phpMyAdmin. Ponowny import: ALTER zgłosi „Duplicate column” — wtedy wystarczy reszta pliku.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_naped_jednostki
  ADD COLUMN przyspieszenie_g DECIMAL(8,1) NOT NULL DEFAULT 2500;

UPDATE mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id
SET n.przyspieszenie_g = CASE
  WHEN o.klasa LIKE '%Lambda%' THEN 1400
  WHEN o.klasa LIKE '%ISD%' OR o.klasa LIKE '%Imperial%' OR o.nazwa LIKE 'ISD%' THEN 2300
  WHEN o.klasa LIKE '%Arquitens%' OR o.nazwa LIKE 'Arquitens%' THEN 3000
  WHEN o.klasa LIKE '%Gozanti%' OR o.nazwa LIKE 'Gozanti%' THEN 2500
  WHEN o.klasa LIKE '%Kom%rk%' THEN 3500
  ELSE 2500 END;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v38-przyspieszenie-podswietlne', 'Lot podświetlny z przyspieszeniem (G): rozpędzanie i hamowanie')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

SELECT o.nazwa, o.klasa, n.przyspieszenie_g FROM mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id ORDER BY n.przyspieszenie_g, o.nazwa;

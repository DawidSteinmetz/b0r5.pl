-- Wnętrza okrętów: plan pomieszczeń i okna.
--
-- mapa_obiekty.plan_wnetrza_id — korzeń planu wnętrza w `lokalizacje` (szablon klasy okrętu). Wszystkie
--   ISD I korzystają z „Imperial Star Destroyer Type I – wnętrze”; inna klasa dostanie własny szablon.
-- lokalizacje.okno — co widać z pomieszczenia:
--   przod  — okna mostka (wzdłuż kursu),
--   lewa / prawa — boczne iluminatory lewej / prawej burty (prostopadle do kursu; w nadprzestrzeni
--          smugi lecą poziomo od dziobu ku rufie),
--   hangar — kosmos przez wrota hangaru,
--   brak   — pomieszczenie bez okien.
--   Przydział początkowy niżej (pokład mostka: przód; hangary: wrota; kwatery i sale oficerskie,
--   mesy, kantyny: burty na przemian; reszta bez okien). MG może zmienić każde pomieszczenie:
--   UPDATE lokalizacje SET okno = 'lewa' WHERE id = …;
--
-- Pomieszczenie postaci na okręcie zapisuje gra (karta postaci); kapitan zaczyna na Mostku głównym,
-- pozostali w Głównym korytarzu centralnym. Prom wsiada się na Stanowisku obsługi promów Lambda.
--
-- Import w phpMyAdmin. Ponowny import: ALTER zgłosi „Duplicate column” — wtedy wystarczy reszta pliku
-- od SET @plan (przydział okien nadpisze się wartościami z tego pliku).

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_obiekty
  ADD COLUMN plan_wnetrza_id BIGINT UNSIGNED NULL,
  ADD CONSTRAINT fk_mapa_obiekty_plan_wnetrza FOREIGN KEY (plan_wnetrza_id) REFERENCES lokalizacje (id) ON DELETE SET NULL;

ALTER TABLE lokalizacje
  ADD COLUMN okno ENUM('przod','lewa','prawa','hangar','brak') NULL;

SET @plan := (SELECT id FROM lokalizacje WHERE rodzic_id IS NULL AND nazwa LIKE 'Imperial Star Destroyer Type I%wn_trze' ORDER BY id LIMIT 1);

-- Plan wnętrza dla wszystkich ISD I (nie dla promów z ich hangarów).
UPDATE mapa_obiekty SET plan_wnetrza_id = @plan
WHERE @plan IS NOT NULL AND typ = 'statek' AND okret_macierzysty_id IS NULL
  AND (klasa LIKE '%ISD%' OR klasa LIKE '%Imperial%' OR nazwa LIKE 'ISD%');

-- Drzewo planu (tabela robocza: UPDATE nie może czytać aktualizowanej tabeli w podzapytaniu).
DROP TEMPORARY TABLE IF EXISTS tmp_plan_wnetrza;
CREATE TEMPORARY TABLE tmp_plan_wnetrza (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, mostek TINYINT(1) NOT NULL DEFAULT 0);
INSERT INTO tmp_plan_wnetrza (id, mostek)
WITH RECURSIVE drzewo AS (
  SELECT id, 0 AS mostek FROM lokalizacje WHERE id = @plan
  UNION ALL
  SELECT c.id, IF(d.mostek = 1 OR c.nazwa LIKE '%Pokład 01%', 1, 0) FROM lokalizacje c JOIN drzewo d ON c.rodzic_id = d.id
)
SELECT id, mostek FROM drzewo;

-- Okna: najpierw całe wnętrze bez okien, potem pokład mostka (i wszystko pod nim) — okna przednie.
UPDATE lokalizacje l JOIN tmp_plan_wnetrza t ON t.id = l.id SET l.okno = IF(t.mostek = 1, 'przod', 'brak');

-- Hangary: wrota.
UPDATE lokalizacje SET okno = 'hangar' WHERE okno IS NOT NULL AND rodzaj = 'hangar';

-- Burty: kwatery i sale oficerskie, mesy, kantyny, rekreacja, oddział szpitalny (na przemian lewa / prawa).
UPDATE lokalizacje SET okno = IF(id % 2 = 0, 'lewa', 'prawa')
WHERE okno = 'brak' AND (
     nazwa LIKE '%Kwatera kapitana' OR nazwa LIKE '%Gabinet dowódcy' OR nazwa LIKE '%Kwatera adiutanta dowódcy'
  OR nazwa LIKE '%Kwatery oficerów sztabowych' OR nazwa LIKE '%Sala odpraw dowództwa' OR nazwa LIKE '%Sala narad sztabu'
  OR nazwa LIKE '%Sala konferencyjna' OR nazwa LIKE '%Salon oficerski' OR nazwa LIKE '%Mesa oficerska'
  OR nazwa LIKE '%Kwatery starszych oficerów' OR nazwa LIKE '%Kantyna główna' OR nazwa LIKE '%Kantyna pomocnicza'
  OR nazwa LIKE '%Sala rekreacyjna' OR nazwa LIKE '%Pomieszczenie wypoczynkowe załogi' OR nazwa LIKE '%Oddział szpitalny');

DROP TEMPORARY TABLE IF EXISTS tmp_plan_wnetrza;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v36-wnetrza-okretow', 'Plan wnętrza okrętów (szablon ISD I), pomieszczenia postaci, okna przednie, boczne, hangar, brak')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

-- Kontrola: okręty z planem i okna w planie.
SELECT id, nazwa, klasa, plan_wnetrza_id FROM mapa_obiekty WHERE plan_wnetrza_id IS NOT NULL;
SELECT okno, COUNT(*) AS pomieszczenia FROM lokalizacje WHERE okno IS NOT NULL GROUP BY okno;
SELECT id, nazwa, okno FROM lokalizacje WHERE okno IN ('przod', 'lewa', 'prawa', 'hangar') ORDER BY okno, nazwa;

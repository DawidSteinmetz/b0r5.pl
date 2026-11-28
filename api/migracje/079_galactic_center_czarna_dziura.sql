-- Galactic Center (0, -2131.4) jest środkiem galaktyki i centralną czarną dziurą.
-- Wiersz mapa_planety pozostaje kotwicą układu/nawigacji; nie reprezentuje planety.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_planety
  ADD COLUMN typ_ciala ENUM('planeta','czarna_dziura') NOT NULL DEFAULT 'planeta' AFTER nazwa;

ALTER TABLE planety_cykle
  MODIFY COLUMN gwiazda_typ ENUM('O','B','A','F','G','K','M','WD','RG','BH') NULL AFTER srednica_km;

UPDATE mapa_planety
SET typ_ciala='czarna_dziura', grawitacja=0, slonca=0, ksiezyce=0, doba=0, rok=0, srednica=0
WHERE nazwa='Galactic Center' AND x=0 AND y=-2131.4;

INSERT INTO planety_cykle (planeta_id, gwiazda_typ, doba_h, rok_dni, srednica_km, promien_orbity_au)
SELECT id, 'BH', NULL, NULL, NULL, NULL FROM mapa_planety
WHERE nazwa='Galactic Center' AND x=0 AND y=-2131.4
ON DUPLICATE KEY UPDATE gwiazda_typ='BH', doba_h=NULL, rok_dni=NULL, srednica_km=NULL, promien_orbity_au=NULL;

DELETE k FROM planety_ksiezyce k JOIN mapa_planety p ON p.id=k.planeta_id
WHERE p.nazwa='Galactic Center' AND p.x=0 AND p.y=-2131.4;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v079-galactic-center-czarna-dziura', 'Galactic Center jako środek gwiazd tła i czarna dziura bez planet ani księżyców')
ON DUPLICATE KEY UPDATE opis=VALUES(opis);

-- Klucze myśliwców i promów (api/klucze.php): klucz = 4 maszyny z hangaru okrętu, jeden mały trójkąt na mapie.
--
-- mapa_klucze — klucz w locie: okręt macierzysty, numer, rodzaj (tie / lambda), rozkaz i cel, trasa zaplanowana
--   przez serwer (trasa_json: [[czas gry, km od słońca x, y], …]) i faza przy celu (po_cel_id, po_promien_km —
--   krążenie wokół celu po dolocie), prędkość, chwila startu z taśmociągu. Po powrocie do hangaru wiersz znika.
-- mapa_floty_jednostki.lambda_liczba — promy Lambda w hangarze (klucz = 4); ISD: 4. Myśliwce — tie_liczba.
--
-- Import w phpMyAdmin. Ponowny import: ALTER zgłosi „Duplicate column” — wtedy wystarczy reszta pliku.

USE hololink;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mapa_klucze (
  id INT AUTO_INCREMENT PRIMARY KEY,
  okret_id INT NOT NULL,
  numer SMALLINT NOT NULL,
  rodzaj VARCHAR(10) NOT NULL DEFAULT 'tie',
  maszyny TINYINT NOT NULL DEFAULT 4,
  uklad_x DECIMAL(10,1) NOT NULL,
  uklad_y DECIMAL(10,1) NOT NULL,
  rozkaz VARCHAR(20) NOT NULL DEFAULT 'patrol',
  cel_obiekt_id INT NULL,
  po_cel_id INT NULL,
  po_promien_km DECIMAL(10,2) NOT NULL DEFAULT 0,
  trasa_json MEDIUMTEXT NOT NULL,
  predkosc_kms DECIMAL(10,1) NOT NULL,
  start_utc DATETIME NOT NULL,
  rozkaz_utc DATETIME NOT NULL,
  KEY okret (okret_id),
  KEY cel (cel_obiekt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE mapa_floty_jednostki ADD COLUMN lambda_liczba SMALLINT NULL;

UPDATE mapa_floty_jednostki j JOIN mapa_obiekty o ON o.id = j.obiekt_id
SET j.lambda_liczba = 4
WHERE j.lambda_liczba IS NULL AND (o.klasa LIKE 'ISD%' OR o.klasa LIKE '%Star Destroyer%');

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v066-klucze-mysliwcow', 'Klucze myśliwców i promów Lambda: start z hangaru, rozkazy, trasy liczone przez serwer')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

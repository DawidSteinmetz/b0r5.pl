-- Przelot orbitalny: jeden mechanizm dla dokowania do pierścienia i powrotu promu do hangaru okrętu.
--
-- Jednostka ma sztywne miejsce na orbicie (orbita_deg + promień z podejścia). Przelot to lot łukiem po orbicie
-- (krótszą stroną planety) od miejsca startu (kąt, promień) do celu (kąt, promień) w czasie przelot_od → przelot_do;
-- na końcu: dok na stanowisku albo wejście do hangaru okrętu macierzystego. Przyciski to skróty — liczy się tak samo.
--
-- Import w phpMyAdmin. Ponowny import: ALTER zgłosi „Duplicate column” — wtedy wystarczy reszta pliku.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_obiekty
  ADD COLUMN przelot_cel ENUM('dok','hangar','orbita') NULL,
  ADD COLUMN przelot_od_utc DATETIME NULL,
  ADD COLUMN przelot_do_utc DATETIME NULL,
  ADD COLUMN przelot_kat0 DECIMAL(8,3) NULL,
  ADD COLUMN przelot_r0_km DECIMAL(12,1) NULL,
  ADD COLUMN przelot_kat1 DECIMAL(8,3) NULL,
  ADD COLUMN przelot_r1_km DECIMAL(12,1) NULL;

-- Sztywne miejsce na orbicie dla jednostek bez niego (wcześniej mapa liczyła je z kursu i jednostka „skakała”).
UPDATE mapa_obiekty SET orbita_deg = MOD(COALESCE(kurs_deg, 90) + 180, 360)
WHERE orbita_deg IS NULL AND planeta_id IS NOT NULL AND typ IN ('statek','jednostka');

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v47-przelot-orbitalny', 'Przelot orbitalny: dok i powrót promu jednym mechanizmem')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

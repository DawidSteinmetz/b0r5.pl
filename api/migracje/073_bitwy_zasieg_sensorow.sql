-- Zasięg sensorów okrętu w bitwie (konsola Sensory): im większy, tym więcej porcji mocy reaktora — koszt
-- k · (zasięg / 1000 km)^1,618 porcji (MECHANIKA 7: koszt skanu k·J^1,618), k = 0,245: 1000 km — 0,25 porcji,
-- 3000 km — 1,4, 10 000 km — 10, 30 000 km — 60. Wspólny stan okrętu: Sensory ustawiają, Reaktory pokazują pobór.
-- Wymaga 070. Import w phpMyAdmin. Ponowny import: ALTER zgłosi „Duplicate column” — kolumna już jest.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE bitwy_jednostki
  ADD COLUMN zasieg_sensorow_km INT UNSIGNED NOT NULL DEFAULT 1000 AFTER tasma_tik;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v073-bitwy-zasieg-sensorow', 'Zasięg sensorów okrętu w bitwie (koszt w porcjach reaktora)')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

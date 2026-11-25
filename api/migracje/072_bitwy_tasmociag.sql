-- Taśmociąg okrętu w bitwie (api/bitwa_silnik.php): tik ostatniego zaplanowanego startu klucza z tego okrętu.
-- Kolejny klucz startuje co najmniej 60 s później (jak api/klucze.php: jeden klucz na minutę). Wymaga 070.
-- Import w phpMyAdmin. Ponowny import: ALTER zgłosi „Duplicate column” — kolumna już jest.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE bitwy_jednostki
  ADD COLUMN tasma_tik INT NULL AFTER wejscie_tik;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v072-bitwy-tasmociag', 'Taśmociąg okrętu w bitwie: ostatni zaplanowany start klucza')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

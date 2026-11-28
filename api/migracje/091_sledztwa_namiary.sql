-- Śledztwa droida: namiary — miejsca, które rozmówcy wskazali droidowi (lokal, poziom, budynek).
-- Pojawiają się w polu wyboru celu ruchu obok budynków. Do zaimportowania wielokrotnie. Wymaga 082.
CREATE TABLE IF NOT EXISTS sledztwo_namiary (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  klucz VARCHAR(60) NOT NULL,                     -- budynek|poziom|lokal (bez powtórzeń)
  nazwa VARCHAR(160) NOT NULL,                    -- np. „Recepcja (lokal 2001)”
  lokalizacja_id BIGINT UNSIGNED NULL,
  poziom SMALLINT NOT NULL,
  pokoj VARCHAR(12) NOT NULL,
  skad VARCHAR(255) NOT NULL,                     -- kto i kiedy podał namiary
  kiedy_utc DATETIME NOT NULL,
  UNIQUE KEY uq_sledztwo_namiary (sledztwo_id, klucz),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v091-sledztwa-namiary', 'Śledztwa: namiary od rozmówców w polu wyboru celu ruchu')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

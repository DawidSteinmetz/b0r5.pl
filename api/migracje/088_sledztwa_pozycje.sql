-- Śledztwa droida: swobodny ruch po planie piętra i blokowanie drzwi.
-- Do zaimportowania wielokrotnie (CREATE TABLE IF NOT EXISTS). Wymaga 082.
-- Jednostki planu: 10 j. = 1 m (pokój 60 j. szerokości, 70 j. głębokości, korytarz 36 j.).

-- Dokładna pozycja droida na planie. Wiersz jest opcjonalny — bez niego droid stoi na środku lokalu.
CREATE TABLE IF NOT EXISTS sledztwo_pozycje (
  sledztwo_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  x SMALLINT NOT NULL,
  y SMALLINT NOT NULL,
  pokoj VARCHAR(12) NOT NULL DEFAULT 'korytarz',   -- lokal, którego dotyczy (x, y); inny niż w sledztwo_droid = nieaktualny
  cel_x SMALLINT NULL,
  cel_y SMALLINT NULL,
  stoi_od_utc DATETIME NULL,                        -- od kiedy droid stoi w miejscu (prośba NPC o przejście)
  zatrzymanie VARCHAR(500) NULL,                    -- meldunek po dotarciu, gdy droida zatrzymano po drodze
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kto stał w drzwiach i czy się odsunął (klucz: budynek|poziom|lokal|godzina — przechodnie zmieniają się co godzinę).
CREATE TABLE IF NOT EXISTS sledztwo_przejscia (
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  klucz VARCHAR(80) NOT NULL,
  stan ENUM('odsunal','odmowil') NOT NULL,
  kiedy_utc DATETIME NOT NULL,
  PRIMARY KEY (sledztwo_id, klucz),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v088-sledztwa-pozycje', 'Śledztwa: pozycja droida na planie (swobodny ruch) i blokowanie drzwi przez NPC')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

-- Śledztwa droida: tropy (ślady odkryte przez droida), notatnik detektywa, powiązania i rozwiązanie sprawy.
-- Do zaimportowania wielokrotnie (CREATE TABLE IF NOT EXISTS). Wymaga 082, 085.
--
-- Trop powstaje automatycznie, gdy rozmówca powie fakt z bazy (zeznanie / obserwacja z osi czasu), gdy droid
-- dostanie namiary albo odczyta dokument. Gracz w notatniku oznacza tropy (ważny / podejrzany / odrzucony),
-- dopisuje notatki i łączy je w powiązania. Rozwiązanie zapisuje się w sledztwa.rozwiazanie_gracza (JSON)
-- i sledztwa.ocena (0–100), stan → rozwiazane.

CREATE TABLE IF NOT EXISTS sledztwo_tropy (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  klucz VARCHAR(160) NOT NULL,                    -- bez powtórzeń: ten sam fakt od tej samej osoby to jeden trop
  rodzaj ENUM('zeznanie','obserwacja','namiar','dokument') NOT NULL,
  tresc TEXT NOT NULL,                            -- zapis do notatnika (3. osoba, z datą i miejscem)
  zrodlo VARCHAR(255) NOT NULL,                   -- kto / co jest źródłem
  temat VARCHAR(20) NOT NULL DEFAULT '',          -- kiedy, gdzie, kto, wyglad, nawyk, klopoty, miejsce, dokument
  kontakt VARCHAR(12) NULL,                       -- token rozmówcy (zeznania)
  przedmiot_id BIGINT UNSIGNED NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,            -- miejsce, którego trop dotyczy (jeśli wiadomo)
  poziom SMALLINT NULL,
  pokoj VARCHAR(12) NULL,
  czas_zdarzenia_utc DATETIME NULL,               -- kiedy wydarzyło się to, o czym mówi trop (oś czasu)
  czas_lokalny VARCHAR(80) NULL,                  -- lokalna pora zdarzenia na planecie sprawy
  wiarygodnosc TINYINT UNSIGNED NOT NULL DEFAULT 60,
  prawdziwy TINYINT(1) NOT NULL DEFAULT 1,        -- dla MG (gracz tego nie widzi)
  oznaczenie ENUM('','wazny','podejrzany','odrzucony') NOT NULL DEFAULT '',
  notatka VARCHAR(1000) NULL,                     -- dopisek gracza
  kiedy_utc DATETIME NOT NULL,                    -- kiedy droid to ustalił
  kiedy_lokalnie VARCHAR(80) NULL,
  UNIQUE KEY uq_sledztwo_tropy (sledztwo_id, klucz),
  KEY ix_sledztwo_tropy (sledztwo_id, id),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sledztwo_powiazania (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  trop_a BIGINT UNSIGNED NOT NULL,
  trop_b BIGINT UNSIGNED NOT NULL,
  opis VARCHAR(255) NOT NULL DEFAULT '',
  utworzono_utc DATETIME NOT NULL,
  UNIQUE KEY uq_sledztwo_powiazania (sledztwo_id, trop_a, trop_b),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (trop_a) REFERENCES sledztwo_tropy (id) ON DELETE CASCADE,
  FOREIGN KEY (trop_b) REFERENCES sledztwo_tropy (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v094-sledztwa-tropy', 'Śledztwa: tropy, notatnik detektywa, powiązania, rozwiązanie sprawy z oceną')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

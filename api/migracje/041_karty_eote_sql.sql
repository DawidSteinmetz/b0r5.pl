-- CV Edge of the Empire w SQL dla postaci graczy i NPC.
-- Każde pole karty jest osobnym wierszem; nie przechowujemy blobu JSON.
-- Stare CV graczy z datapad_dane i NPC z osoby_karta_eote są przenoszone
-- automatycznie przy pierwszym odczycie po wdrożeniu nowego API.

USE hololink;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS eote_cv_karty (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  legacy_uid VARCHAR(64) NULL,
  osoba_id BIGINT UNSIGNED NULL,
  zmieniono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_eote_cv_uid (legacy_uid),
  UNIQUE KEY uq_eote_cv_osoba (osoba_id),
  CHECK ((legacy_uid IS NOT NULL AND osoba_id IS NULL) OR (legacy_uid IS NULL AND osoba_id IS NOT NULL)),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eote_cv_wartosci (
  karta_id BIGINT UNSIGNED NOT NULL,
  sciezka VARCHAR(512) NOT NULL,
  typ ENUM('obiekt','lista','tekst','liczba','logiczna','null') NOT NULL,
  wartosc MEDIUMTEXT NULL,
  PRIMARY KEY (karta_id, sciezka),
  FOREIGN KEY (karta_id) REFERENCES eote_cv_karty (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uniwersalna mapa obiektów i dostępów do ich położenia.
-- Wymaga schema.sql, migracji v2/v3 oraz 004_relacyjne_lokacje_i_pozycje.sql.
-- Nie usuwa istniejących danych. Pozycje postaci synchronizuje API z datapad_pozycje_legacy.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mapa_obiekty (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kod VARCHAR(80) NOT NULL,
  typ ENUM('postac','jednostka','statek','pojazd','instalacja','inne') NOT NULL DEFAULT 'inne',
  legacy_uid VARCHAR(64) NULL,
  nazwa VARCHAR(160) NOT NULL,
  rodzaj VARCHAR(80) NULL,
  klasa VARCHAR(120) NULL,
  wlasciciel VARCHAR(160) NULL,
  status VARCHAR(80) NULL,
  planeta_id INT UNSIGNED NULL,
  x DECIMAL(12,1) NULL,
  y DECIMAL(12,1) NULL,
  kolor CHAR(7) NOT NULL DEFAULT '#4de2c9',
  znacznik ENUM('kolo','trojkat','kwadrat','romb') NOT NULL DEFAULT 'kolo',
  opis TEXT NULL,
  aktywny TINYINT(1) NOT NULL DEFAULT 1,
  zmieniono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mapa_obiekty_kod (kod),
  UNIQUE KEY uq_mapa_obiekty_uid (legacy_uid),
  KEY ix_mapa_obiekty_pozycja (aktywny, planeta_id, x, y),
  KEY ix_mapa_obiekty_typ (typ, aktywny),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mapa_obiekty_dostep (
  obiekt_id BIGINT UNSIGNED NOT NULL,
  konto_login VARCHAR(64) NOT NULL,
  nadano TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (obiekt_id, konto_login),
  KEY ix_mapa_obiekty_dostep_login (konto_login),
  FOREIGN KEY (obiekt_id) REFERENCES mapa_obiekty (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pierwsza jednostka śledzona: pozycja i dostęp dla wskazanych kont.
INSERT INTO mapa_obiekty
  (kod, typ, nazwa, rodzaj, klasa, wlasciciel, planeta_id, x, y, kolor, znacznik, opis, aktywny)
VALUES
  ('isd-i-walako', 'statek', 'ISD I „Walako”', 'okręt', 'ISD I', 'Imperium', NULL, 8400, 3130, '#00ff66', 'trojkat', NULL, 1)
ON DUPLICATE KEY UPDATE
  typ=VALUES(typ), nazwa=VALUES(nazwa), rodzaj=VALUES(rodzaj), klasa=VALUES(klasa),
  wlasciciel=VALUES(wlasciciel), planeta_id=NULL, x=VALUES(x), y=VALUES(y),
  kolor=VALUES(kolor), znacznik=VALUES(znacznik), aktywny=1;

INSERT IGNORE INTO mapa_obiekty_dostep (obiekt_id, konto_login)
SELECT id, 'GOV-5401' FROM mapa_obiekty WHERE kod='isd-i-walako';
INSERT IGNORE INTO mapa_obiekty_dostep (obiekt_id, konto_login)
SELECT id, 'ISB-2137' FROM mapa_obiekty WHERE kod='isd-i-walako';

INSERT IGNORE INTO schema_migracje (wersja, opis)
VALUES ('v5-mapa-obiekty', 'Uniwersalna mapa obiektów i udostępnianie pozycji; ISD I Walako');

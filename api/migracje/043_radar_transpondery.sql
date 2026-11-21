-- Radar i transpondery.
--
-- Radar okrętu widzi jednostki w swoim zasięgu (pc): domyślnie z długości kadłuba (ISD ~3 pc, krążownik ~1,5 pc,
-- małe statki ~0,5 pc) albo z radar_zasieg_pc. Kolor kontaktu: transponder przyjazny — zielony, neutralny — szary,
-- wrogi (wroga frakcja, poszukiwany z bazy sygnatur Imperium) — czerwony. Do tego niezarejestrowany ruch w tle
-- (liczony na serwerze, zależny od regionu) — tego nie zapisujemy w bazie.
--
-- mapa_obiekty.transponder_*: fałszywy transponder (nazwa, frakcja) działa od transponder_od (przestawienie trwa
-- 30 min). mapa_udostepnienia: kto komu udostępnił pozycję jednostki (do_utc NULL = do odwołania).
-- radar_baza_sygnatur: poszukiwane jednostki (MG). radar_analizy: analiza sygnatury silnika i osłon (6 h, duże okręty).
-- radar_identyfikacje: wynik analizy dla konta — prawdziwa tożsamość kontaktu mimo fałszywego transpondera.
--
-- Import w phpMyAdmin. Ponowny import: ALTER zgłosi „Duplicate column” — wtedy wystarczy reszta pliku.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_obiekty
  ADD COLUMN transponder_nazwa VARCHAR(120) NULL,
  ADD COLUMN transponder_frakcja VARCHAR(40) NULL,
  ADD COLUMN transponder_od DATETIME NULL,
  ADD COLUMN radar_zasieg_pc DECIMAL(6,2) NULL;

CREATE TABLE IF NOT EXISTS mapa_udostepnienia (
  obiekt_id BIGINT UNSIGNED NOT NULL,
  konto_login VARCHAR(64) NOT NULL,
  od_konta VARCHAR(64) NOT NULL DEFAULT '',
  od_utc DATETIME NOT NULL,
  do_utc DATETIME NULL,
  PRIMARY KEY (obiekt_id, konto_login),
  KEY ix_udostepnienia_konto (konto_login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radar_baza_sygnatur (
  obiekt_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  nagroda INT UNSIGNED NOT NULL DEFAULT 0,
  opis VARCHAR(500) NOT NULL DEFAULT '',
  dodano TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radar_analizy (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  konto_login VARCHAR(64) NOT NULL,
  analizujacy_id BIGINT UNSIGNED NOT NULL,
  cel_id BIGINT NOT NULL,
  od_utc DATETIME NOT NULL,
  do_utc DATETIME NOT NULL,
  stan ENUM('trwa','gotowa','przerwana') NOT NULL DEFAULT 'trwa',
  KEY ix_analizy_konto (konto_login, stan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radar_identyfikacje (
  konto_login VARCHAR(64) NOT NULL,
  cel_id BIGINT NOT NULL,
  nazwa VARCHAR(160) NOT NULL,
  frakcja VARCHAR(40) NULL,
  poszukiwany TINYINT(1) NOT NULL DEFAULT 0,
  nagroda INT UNSIGNED NOT NULL DEFAULT 0,
  kiedy DATETIME NOT NULL,
  PRIMARY KEY (konto_login, cel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

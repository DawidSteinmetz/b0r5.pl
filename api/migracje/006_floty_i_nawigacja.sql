-- HOLOLINK: floty, podział zespołów i automatyczne skoki jednostek mapy.
-- Wymaga działającej bazy v5 i migracji 005_uniwersalna_mapa.sql.
-- Dopisuje dane; nie usuwa istniejących rekordów.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mapa_floty (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kod VARCHAR(80) NOT NULL,
  nazwa VARCHAR(160) NOT NULL,
  dowodca_login VARCHAR(64) NULL,
  rodzic_id BIGINT UNSIGNED NULL,
  status ENUM('aktywna','rezerwa','nieaktywna') NOT NULL DEFAULT 'aktywna',
  opis TEXT NULL,
  utworzono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  zmieniono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mapa_floty_kod (kod),
  KEY ix_mapa_floty_dowodca (dowodca_login,status),
  KEY ix_mapa_floty_rodzic (rodzic_id),
  FOREIGN KEY (rodzic_id) REFERENCES mapa_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mapa_floty_jednostki (
  obiekt_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  flota_id BIGINT UNSIGNED NOT NULL,
  rola VARCHAR(80) NOT NULL DEFAULT '',
  tie_liczba SMALLINT UNSIGNED NULL,
  FOREIGN KEY (obiekt_id) REFERENCES mapa_obiekty (id) ON DELETE CASCADE,
  FOREIGN KEY (flota_id) REFERENCES mapa_floty (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parametry w tej tabeli są profilem technicznym prototypu, edytowalnym przez MG.
-- Przy uszkodzeniu sprawność napędu obniża szybkość i zwiększa możliwy błąd wyjścia.
CREATE TABLE IF NOT EXISTS mapa_naped_jednostki (
  obiekt_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  moc_reaktora DECIMAL(12,2) NOT NULL DEFAULT 100,
  moc_hipernapedu DECIMAL(12,2) NOT NULL DEFAULT 80,
  klasa_hipernapedu DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  predkosc_pc_na_godzine DECIMAL(12,2) NOT NULL DEFAULT 1000,
  paliwo DECIMAL(14,2) NOT NULL DEFAULT 100000,
  paliwo_maks DECIMAL(14,2) NOT NULL DEFAULT 100000,
  paliwo_na_parsek DECIMAL(10,4) NOT NULL DEFAULT 0.01,
  sprawnosc_reaktora TINYINT UNSIGNED NOT NULL DEFAULT 100,
  sprawnosc_hipernapedu TINYINT UNSIGNED NOT NULL DEFAULT 100,
  szum_bazowy DECIMAL(5,2) NOT NULL DEFAULT 0.05,
  zmieniono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CHECK (moc_reaktora > 0 AND moc_hipernapedu > 0 AND klasa_hipernapedu > 0),
  CHECK (predkosc_pc_na_godzine > 0 AND paliwo <= paliwo_maks),
  CHECK (sprawnosc_reaktora <= 100 AND sprawnosc_hipernapedu <= 100),
  FOREIGN KEY (obiekt_id) REFERENCES mapa_obiekty (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mapa_rozkazy_skokow (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kod VARCHAR(80) NOT NULL,
  wydajacy_login VARCHAR(64) NOT NULL,
  flota_id BIGINT UNSIGNED NULL,
  cel_obiekt_id BIGINT UNSIGNED NULL,
  cel_x DECIMAL(12,1) NOT NULL,
  cel_y DECIMAL(12,1) NOT NULL,
  status ENUM('w_nadprzestrzeni','dotarl','przerwany','blad') NOT NULL DEFAULT 'w_nadprzestrzeni',
  wydano_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  eta_utc DATETIME NOT NULL,
  zakonczono_utc DATETIME NULL,
  opis TEXT NULL,
  UNIQUE KEY uq_mapa_rozkazy_kod (kod),
  KEY ix_mapa_rozkazy_status_eta (status,eta_utc),
  KEY ix_mapa_rozkazy_flota (flota_id,status),
  FOREIGN KEY (flota_id) REFERENCES mapa_floty (id),
  FOREIGN KEY (cel_obiekt_id) REFERENCES mapa_obiekty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mapa_rozkazy_skokow_jednostki (
  rozkaz_id BIGINT UNSIGNED NOT NULL,
  obiekt_id BIGINT UNSIGNED NOT NULL,
  start_x DECIMAL(12,1) NOT NULL,
  start_y DECIMAL(12,1) NOT NULL,
  wyjscie_x DECIMAL(12,1) NOT NULL,
  wyjscie_y DECIMAL(12,1) NOT NULL,
  planeta_id INT UNSIGNED NULL,
  kat_bledu DECIMAL(7,3) NOT NULL DEFAULT 0,
  moc_wzgl DECIMAL(6,3) NOT NULL DEFAULT 1,
  paliwo_zuzyte DECIMAL(14,2) NOT NULL DEFAULT 0,
  eta_utc DATETIME NOT NULL,
  status ENUM('w_nadprzestrzeni','dotarl','przerwany','blad') NOT NULL DEFAULT 'w_nadprzestrzeni',
  PRIMARY KEY (rozkaz_id,obiekt_id),
  KEY ix_mapa_rozkazy_jednostka (obiekt_id,eta_utc),
  FOREIGN KEY (rozkaz_id) REFERENCES mapa_rozkazy_skokow (id) ON DELETE CASCADE,
  FOREIGN KEY (obiekt_id) REFERENCES mapa_obiekty (id),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mapa_teleporty_mg (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  obiekt_id BIGINT UNSIGNED NOT NULL,
  autor_login VARCHAR(64) NOT NULL,
  x DECIMAL(12,1) NOT NULL,
  y DECIMAL(12,1) NOT NULL,
  powod VARCHAR(500) NOT NULL DEFAULT '',
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_mapa_teleporty_obiekt (obiekt_id,czas_utc),
  FOREIGN KEY (obiekt_id) REFERENCES mapa_obiekty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Przykładowe przydziały dowódców.
INSERT INTO mapa_floty (kod,nazwa,dowodca_login,opis)
VALUES ('havis-iii-eskortowa','Flota Eskortowa Havis III','GOV-5401','Imperialna flota Havis III pod dowództwem Moffa Darniusa Kirry.') AS nowa
ON DUPLICATE KEY UPDATE nazwa=nowa.nazwa,dowodca_login=nowa.dowodca_login,opis=nowa.opis;

INSERT INTO mapa_floty (kod,nazwa,dowodca_login,opis)
VALUES ('zephyria','Flota Zephyria','ISB-2137','Jednostki przydzielone kapitanowi Dave’owi Stingertowi.') AS nowa
ON DUPLICATE KEY UPDATE nazwa=nowa.nazwa,dowodca_login=nowa.dowodca_login,opis=nowa.opis;

-- 2 lekkie krążowniki i 6 Gozanti stacjonują przy Havis III (8270, -3070).
INSERT IGNORE INTO mapa_obiekty
  (kod,typ,nazwa,rodzaj,klasa,wlasciciel,status,planeta_id,x,y,kolor,znacznik,aktywny)
SELECT CONCAT('havis-arquitens-',LPAD(n,2,'0')),'statek',CONCAT('Arquitens ',n),'okręt','Arquitens','Imperium','sprawny',
  (SELECT id FROM mapa_planety WHERE nazwa='Havis III' LIMIT 1),8270,-3070,'#00ff66','trojkat',1
FROM (SELECT 1 AS n UNION ALL SELECT 2) AS numery;

INSERT IGNORE INTO mapa_obiekty
  (kod,typ,nazwa,rodzaj,klasa,wlasciciel,status,planeta_id,x,y,kolor,znacznik,aktywny)
SELECT CONCAT('havis-gozanti-',LPAD(n,2,'0')),'statek',CONCAT('Gozanti ',n),'okręt','Gozanti','Imperium','sprawny',
  (SELECT id FROM mapa_planety WHERE nazwa='Havis III' LIMIT 1),8270,-3070,'#00ff66','trojkat',1
FROM (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) AS numery;

INSERT IGNORE INTO mapa_floty_jednostki (obiekt_id,flota_id,rola,tie_liczba)
SELECT o.id,f.id,'krążownik eskortowy',NULL FROM mapa_obiekty o JOIN mapa_floty f ON f.kod='havis-iii-eskortowa'
WHERE o.kod IN ('havis-arquitens-01','havis-arquitens-02');
INSERT IGNORE INTO mapa_floty_jednostki (obiekt_id,flota_id,rola,tie_liczba)
SELECT o.id,f.id,'transportowiec myśliwców',4 FROM mapa_obiekty o JOIN mapa_floty f ON f.kod='havis-iii-eskortowa'
WHERE o.kod IN ('havis-gozanti-01','havis-gozanti-02','havis-gozanti-03','havis-gozanti-04','havis-gozanti-05','havis-gozanti-06');

-- „Walako” pozostaje widoczny dla Kirry i Stingerta; dowódcą jego floty jest Stingert.
INSERT IGNORE INTO mapa_floty_jednostki (obiekt_id,flota_id,rola,tie_liczba)
SELECT o.id,f.id,'niszczyciel gwiezdny',NULL FROM mapa_obiekty o JOIN mapa_floty f ON f.kod='zephyria'
WHERE o.kod='isd-i-walako';

INSERT IGNORE INTO mapa_naped_jednostki (obiekt_id)
SELECT obiekt_id FROM mapa_floty_jednostki;

INSERT IGNORE INTO mapa_obiekty_dostep (obiekt_id,konto_login)
SELECT j.obiekt_id,'GOV-5401' FROM mapa_floty_jednostki j
JOIN mapa_floty f ON f.id=j.flota_id WHERE f.kod='havis-iii-eskortowa';

INSERT IGNORE INTO schema_migracje (wersja,opis)
VALUES ('v6-floty-nawigacja','Edytor flot, dowódcy, automatyczne skoki i teleport MG');

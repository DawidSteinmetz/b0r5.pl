-- Rodzaje gwiazd i wyczyn „Fotograf gwiazd”.
--
-- planety_cykle.gwiazda_typ: typ głównej gwiazdy układu (NULL = losowany z nazwy, z rozkładem
--   zbliżonym do prawdziwego): O, B, A, F, G, K, M, WD (biały karzeł), RG (czerwony olbrzym).
--   Liczba słońc w układzie pochodzi z mapa_planety.slonca (towarzysze losowani z nazwy).
-- zdjecia_gwiazd: zdjęcie słońca układu zrobione przez postać (miniatura JPEG). Każdy układ liczy
--   się dla konta raz (pierwsze zdjęcie).
-- wyczyny_progi: progi wyczynu w procentach sfotografowanych układów, z nazwami (edytuje MG).
--
-- Wymaga migracji 020. Import w phpMyAdmin: zakładka SQL albo Import. Importuj raz.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE planety_cykle
  ADD COLUMN gwiazda_typ ENUM('O','B','A','F','G','K','M','WD','RG') NULL AFTER srednica_km;

UPDATE planety_cykle c JOIN mapa_planety p ON p.id = c.planeta_id
  SET c.gwiazda_typ = 'G' WHERE p.nazwa = 'Zephyria';

CREATE TABLE IF NOT EXISTS zdjecia_gwiazd (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  konto_login VARCHAR(64) NOT NULL,
  planeta_id INT UNSIGNED NOT NULL,
  gwiazda_typ VARCHAR(4) NOT NULL,
  miejsce ENUM('mostek','powierzchnia') NOT NULL,
  zrobiono_utc DATETIME NOT NULL,
  miniatura MEDIUMBLOB NOT NULL COMMENT 'JPEG, do ok. 150 KB',
  UNIQUE KEY uq_zdjecia_gwiazd (konto_login, planeta_id),
  KEY ix_zdjecia_gwiazd_planeta (planeta_id),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wyczyny_progi (
  procent DECIMAL(5,2) NOT NULL PRIMARY KEY,
  nazwa VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO wyczyny_progi (procent, nazwa) VALUES
  (1, 'Obserwator'), (5, 'Zwiadowca gwiazd'), (10, 'Kartograf'),
  (25, 'Wędrowiec galaktyki'), (50, 'Kronikarz słońc'), (100, 'Ten, który widział wszystkie słońca');

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v21-gwiazdy-zdjecia-wyczyny', 'Rodzaje gwiazd, zdjęcia słońc i wyczyn Fotograf gwiazd')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

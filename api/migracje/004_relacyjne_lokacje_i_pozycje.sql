-- HOLOLINK: etap 1 migracji danych JSON -> SQL
-- Wymaga api/migracje/001_podstawa.sql oraz api/migracje/002_schema_v2.sql.
-- Bezpieczne do ponownego uruchomienia; nie kasuje datapad_dane ani danych postaci.
-- Pozycje zapisuje przejściowo pod dotychczasowym tekstowym UID. Gdy konta zostaną
-- przeniesione do tabel konta/postacie, wpisy przejdą do postacie_pozycja.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS planety_wladza (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  planeta_id INT UNSIGNED NOT NULL,
  zarzadca_id BIGINT UNSIGNED NULL,
  nazwa_zarzadcy VARCHAR(160) NOT NULL,
  funkcja VARCHAR(100) NOT NULL DEFAULT 'zarzadca planety',
  od_utc DATETIME NULL,
  do_utc DATETIME NULL,
  KEY ix_planety_wladza_planeta (planeta_id, do_utc),
  CHECK (do_utc IS NULL OR od_utc IS NULL OR do_utc > od_utc),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id),
  FOREIGN KEY (zarzadca_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS datapad_pozycje_legacy (
  legacy_uid VARCHAR(64) NOT NULL PRIMARY KEY,
  planeta_id INT UNSIGNED NULL,
  planeta_legacy_id VARCHAR(80) NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  lokalizacja_legacy VARCHAR(120) NULL,
  wspolrzedne_tekst VARCHAR(80) NOT NULL DEFAULT '',
  miejsce_opis VARCHAR(160) NOT NULL DEFAULT '',
  budynek_legacy VARCHAR(120) NOT NULL DEFAULT '',
  zmieniono_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
  zmieniono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_datapad_pozycje_planeta (planeta_id),
  KEY ix_datapad_pozycje_planeta_legacy (planeta_legacy_id),
  KEY ix_datapad_pozycje_lokalizacja (lokalizacja_id),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uzupełnij profil Havis III, jeśli planeta została już dodana do mapy.
INSERT INTO planety_gry (planeta_id, typ_planety, urbanizacja, zastrzezona, opis)
SELECT id, 'skalista', 'brak', 0, 'Planeta więzienna.'
FROM mapa_planety
WHERE nazwa = 'Havis III'
ON DUPLICATE KEY UPDATE
  typ_planety = 'skalista',
  opis = COALESCE(opis, 'Planeta więzienna.');

INSERT INTO planety_wladza (planeta_id, zarzadca_id, nazwa_zarzadcy, funkcja)
SELECT id, NULL, 'Moff Darnius Kirra', 'Moff — zarządca planety'
FROM mapa_planety
WHERE nazwa = 'Havis III'
  AND NOT EXISTS (
    SELECT 1 FROM planety_wladza w
    WHERE w.planeta_id = mapa_planety.id
      AND w.nazwa_zarzadcy = 'Moff Darnius Kirra'
      AND w.do_utc IS NULL
  );

INSERT INTO lokalizacje (typ, rodzaj, planeta_id, nazwa, publiczna, opis)
SELECT 'budynek', 'inne', id, 'Pałac Moffa', 1,
       'Siedziba Moffa Darniusa Kirry na Havis III.'
FROM mapa_planety
WHERE nazwa = 'Havis III'
  AND NOT EXISTS (
    SELECT 1 FROM lokalizacje l
    WHERE l.planeta_id = mapa_planety.id AND l.nazwa = 'Pałac Moffa'
  );

INSERT INTO budynki (lokalizacja_id)
SELECT l.id
FROM lokalizacje l
JOIN mapa_planety p ON p.id = l.planeta_id
WHERE p.nazwa = 'Havis III'
  AND l.nazwa = 'Pałac Moffa'
  AND NOT EXISTS (
    SELECT 1 FROM budynki b WHERE b.lokalizacja_id = l.id
  );

INSERT IGNORE INTO schema_migracje (wersja, opis)
VALUES ('v4-lokacje', 'Relacyjne lokacje i pozycje postaci; Havis III i Pałac Moffa');

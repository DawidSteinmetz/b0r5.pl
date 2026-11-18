-- Skok statku postaci z wyliczanym czasem (resynchronizowalny) i radarowe wykrywanie skoków.
-- Wymaga migracji 013. Import w phpMyAdmin; ponowny import niczego nie psuje.

SET NAMES utf8mb4;

-- Resynchronizacja obejmuje teraz także skok statku postaci.
ALTER TABLE resynchronizacje
  MODIFY rodzaj ENUM('skok_floty','podroz','obsluga_statku','skok_statku') NOT NULL;

-- Wykrycia skoków przez jednostki na mapie (warstwa mapa_*). Szacunek z błędem: punkt wyjścia
-- z marginesem w pc i szacowany czas przylotu. Docelowy model sensorów to wykrycia_nadprzestrzenne
-- (jednostki_floty); ta tabela obsługuje jednostki mapy, dopóki obie warstwy się nie połączą.
CREATE TABLE IF NOT EXISTS mapa_wykrycia_skokow (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  obiekt_id BIGINT UNSIGNED NOT NULL COMMENT 'jednostka, która wykryła skok',
  cel_rodzaj ENUM('statek_postaci','flota') NOT NULL,
  cel_klucz VARCHAR(120) NOT NULL COMMENT 's:<firma>:<statek> albo id rozkazu skoku floty',
  cel_nazwa VARCHAR(160) NOT NULL DEFAULT '' COMMENT 'jak radar opisuje cel (transponder albo sygnatura)',
  start_x DECIMAL(12,1) NOT NULL,
  start_y DECIMAL(12,1) NOT NULL,
  szac_x DECIMAL(12,1) NOT NULL,
  szac_y DECIMAL(12,1) NOT NULL,
  margines_pc DECIMAL(12,2) NOT NULL DEFAULT 0,
  szac_eta_utc DATETIME NOT NULL,
  wiarygodnosc TINYINT UNSIGNED NOT NULL DEFAULT 5 CHECK (wiarygodnosc BETWEEN 1 AND 10),
  wykryto_utc DATETIME NOT NULL,
  KEY ix_mapa_wykrycia_obiekt (obiekt_id, szac_eta_utc),
  KEY ix_mapa_wykrycia_cel (cel_rodzaj, cel_klucz),
  FOREIGN KEY (obiekt_id) REFERENCES mapa_obiekty (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v14-skoki-statkow-radar', 'Czas skoku statku postaci, resynchronizacja skoku i radarowe wykrywanie skoków')
ON DUPLICATE KEY UPDATE opis=VALUES(opis);

-- Zgody kapitana na wskaźniki pokładowe dla gości na pokładzie.
-- Kapitan (dowódca floty okrętu; na statku firmy: właściciel albo menedżer) widzi liczniki zawsze.
-- Gość — tylko gdy kapitan (albo MG) udzielił mu zgody. Zgoda działa, dopóki gość jest na pokładzie
-- tego okrętu, i obowiązuje do cofnięcia.
-- klucz: okret:<id mapa_obiekty> albo s:<id firmy>:<id statku>.
--
-- Wymaga migracji 019. Import w phpMyAdmin: zakładka SQL albo Import. Importuj raz.

USE hololink;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS wskazniki_zgody (
  klucz VARCHAR(120) NOT NULL,
  konto_login VARCHAR(64) NOT NULL,
  nadal_login VARCHAR(64) NOT NULL,
  nadano_utc DATETIME NOT NULL,
  PRIMARY KEY (klucz, konto_login),
  KEY ix_wskazniki_zgody_login (konto_login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v20-zgody-wskazniki', 'Zgody kapitana na wskaźniki pokładowe dla gości')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

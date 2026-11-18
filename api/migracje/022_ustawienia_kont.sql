-- Ustawienia interfejsu przypisane do konta (a nie do tabletu), np. kultury wybrane w Kalendarzu.
-- wartosc: JSON (krótkie wartości interfejsu; dane gry mają własne tabele).
--
-- Wymaga migracji 021. Import w phpMyAdmin: zakładka SQL albo Import. Importuj raz.

USE hololink;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ustawienia_kont (
  konto_login VARCHAR(64) NOT NULL,
  klucz VARCHAR(60) NOT NULL,
  wartosc VARCHAR(2000) NOT NULL,
  zmieniono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (konto_login, klucz)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v22-ustawienia-kont', 'Ustawienia interfejsu przypisane do konta (np. kultury w Kalendarzu)')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

-- Piloci okrętów i skan z bliska kluczami myśliwców (api/klucze.php).
--
-- mapa_piloci — piloci na pokładzie okrętu (2 na maszynę z hangaru: TIE i Lambdy; ISD 72 TIE + 4 Lambdy = 152),
--   generowani przy pierwszym otwarciu hangaru: imię, ranga (as ok. 5%, standard 60%, kadet 35%), doświadczenie
--   (globalne dla pilota; mnożnik zdobywania: as ×0,5, standardowy ×1, kadet ×1,5), klucz, w którym leci.
-- mapa_klucze.rozkaz_login — konto, które wydało rozkaz (do niego trafia wynik skanu jako identyfikacja radaru),
--   skan_stan ('trwa' / 'udany' / 'nieudany') i skan_opis (prawdziwa nazwa, ładunek, trasa).
--
-- Wymaga migracji 066. Import w phpMyAdmin. Ponowny import: ALTER zgłosi „Duplicate column” — reszta jest już w bazie.

USE hololink;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mapa_piloci (
  id INT AUTO_INCREMENT PRIMARY KEY,
  okret_id INT NOT NULL,
  numer SMALLINT NOT NULL,
  imie VARCHAR(80) NOT NULL,
  ranga VARCHAR(10) NOT NULL DEFAULT 'standard',
  xp INT NOT NULL DEFAULT 0,
  klucz_id INT NULL,
  UNIQUE KEY okret_numer (okret_id, numer),
  KEY klucz (klucz_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE mapa_klucze
  ADD COLUMN rozkaz_login VARCHAR(64) NULL,
  ADD COLUMN skan_stan VARCHAR(12) NULL,
  ADD COLUMN skan_opis VARCHAR(255) NULL;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v067-piloci-skan', 'Piloci okrętów (asy, standardowi, kadeci, doświadczenie) i skan z bliska kluczami myśliwców')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

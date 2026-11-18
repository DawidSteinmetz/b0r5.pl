-- Frakcja jednostki mapy: od niej zależy kolor na mapie z punktu widzenia gracza
-- (sojusznik — zielony, wróg — czerwony, neutralny — biały).
-- NULL = frakcja wyliczana z pola `wlasciciel` albo frakcji dowódcy floty (api/ruch.php).
-- Relacje: ta sama frakcja = sojusznik, Imperium ↔ Rebelia = wróg, reszta = neutralni.
--
-- Wymaga migracji 017. Import w phpMyAdmin: zakładka SQL albo Import. Importuj raz;
-- ponowny import zatrzyma się na „Duplicate column”.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_obiekty
  ADD COLUMN frakcja ENUM('Imperium','Rebelia','Syndykat','neutralna') NULL AFTER wlasciciel;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v18-frakcja-jednostek', 'Frakcja jednostek mapy: kolory sojusznik/wróg/neutralny')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

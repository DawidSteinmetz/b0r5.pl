-- Nar Shaddaa w układzie Nal Hutty: te same współrzędne mapy (jedno słońce, jeden układ).
-- Księżyc rysowany jest na orbicie Nal Hutty, a nie jako osobny układ. Przy równej odległości
-- kod wybiera planetę (niższe id), więc okręty przylatujące na współrzędne układu trafiają do Nal Hutty.
--
-- Wymaga migracji 026. Import w phpMyAdmin: zakładka SQL albo Import. Ponowny import niczego nie psuje.

USE hololink;
SET NAMES utf8mb4;

SET @nh := (SELECT id FROM mapa_planety WHERE nazwa = 'Nal Hutta' LIMIT 1);
SET @ns := (SELECT id FROM mapa_planety WHERE nazwa = 'Nar Shaddaa' LIMIT 1);
SET @x := (SELECT x FROM mapa_planety WHERE id = @nh);
SET @y := (SELECT y FROM mapa_planety WHERE id = @nh);

UPDATE mapa_planety SET x = @x, y = @y WHERE id = @ns AND @x IS NOT NULL;
UPDATE mapa_obiekty SET x = @x, y = @y WHERE planeta_id = @ns AND @x IS NOT NULL;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v27-nar-shaddaa-wspolny-uklad', 'Nar Shaddaa we współrzędnych układu Nal Hutty (bez osobnego słońca)')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

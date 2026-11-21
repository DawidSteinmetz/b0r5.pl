-- Lądowiska przy obiektach tła gospodarki (kopalnie, zakłady, porty na powierzchni) — można tam wylądować promem.
--
-- Każdy obiekt tła na powierzchni dostaje lokację typu „ladowisko” w swoich współrzędnych geograficznych
-- (x/y km z szerokości i długości: x = dł/360 · π · średnica, y = szer/360 · π · średnica — odwrotnie niż
-- astro_wsp_lokacji), publiczną, i powiązanie gosp_obiekty.lokalizacja_id. Tam też lądują transporty NPC.
--
-- Import w phpMyAdmin (po 048). Ponowny import: ALTER zgłosi „Duplicate column” — wtedy wystarczy reszta pliku.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE gosp_obiekty ADD COLUMN lokalizacja_id BIGINT UNSIGNED NULL;

INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
SELECT 'ladowisko', 'platforma', NULL, NULL, p.id, CONCAT('Lądowisko — ', o.nazwa),
  ROUND(o.dl / 360 * PI() * p.srednica, 3), ROUND(o.szer / 360 * PI() * p.srednica, 3), 1, 'aktywna',
  CONCAT('Lądowisko przy obiekcie: ', o.nazwa, '. ', o.opis)
FROM gosp_obiekty o JOIN mapa_planety p ON p.nazwa = o.planeta
WHERE o.miejsce = 'powierzchnia' AND o.szer IS NOT NULL AND o.dl IS NOT NULL AND p.srednica > 0
ON DUPLICATE KEY UPDATE x_km = VALUES(x_km), y_km = VALUES(y_km), publiczna = 1, status = 'aktywna';

UPDATE gosp_obiekty o JOIN mapa_planety p ON p.nazwa = o.planeta
JOIN lokalizacje l ON l.planeta_id = p.id AND l.nazwa = CONCAT('Lądowisko — ', o.nazwa)
SET o.lokalizacja_id = l.id
WHERE o.miejsce = 'powierzchnia';

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v49-ladowiska-obiektow-tla', 'Lądowiska przy obiektach tła gospodarki')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

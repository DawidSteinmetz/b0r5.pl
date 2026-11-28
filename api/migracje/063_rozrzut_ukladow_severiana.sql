-- Nieregularne pozycje 12 systemów Nowej Rebelii pomiędzy Eriadu i Kabal.
-- Współrzędne wynikają z ustalonego rozrzutu pseudolosowego (ziarno 22112026),
-- zapisanego jako udziały prostokąta Eriadu–Kabal. Import jest powtarzalny:
-- ponowienie wylicza te same pozycje i nie kumuluje przesunięcia obiektów.
-- Każdy rekord jest osobnym systemem z jedną gwiazdą; nie są księżycami Eriadu.

SET NAMES utf8mb4;
DROP TEMPORARY TABLE IF EXISTS tmp_severiana_scatter;
DROP TEMPORARY TABLE IF EXISTS tmp_severiana_positions;

CREATE TEMPORARY TABLE tmp_severiana_scatter (
  nazwa VARCHAR(120) NOT NULL PRIMARY KEY,
  fx DECIMAL(7,4) NOT NULL,
  fy DECIMAL(7,4) NOT NULL
) ENGINE=MEMORY DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO tmp_severiana_scatter (nazwa,fx,fy) VALUES
('Teryphos',0.8055,0.7079),
('Dramith',0.9399,0.4868),
('Kheross',0.6039,0.2447),
('Varnis Minor',0.4084,0.2489),
('Ulgaris',0.1298,0.7597),
('Zorath',0.4790,0.7680),
('Menrath',0.2602,0.8439),
('Feylith',0.1126,0.2587),
('Jorrek',0.6545,0.9422),
('Cindross',0.0082,0.6341),
('Orvas',0.2578,0.1612),
('Lyxar',0.8969,0.0400);

CREATE TEMPORARY TABLE tmp_severiana_positions ENGINE=MEMORY AS
SELECT p.id AS planeta_id,p.x AS stare_x,p.y AS stare_y,
       ROUND(e.x+d.fx*(k.x-e.x),1) AS nowe_x,
       ROUND(e.y+d.fy*(k.y-e.y),1) AS nowe_y
FROM mapa_planety p
JOIN tmp_severiana_scatter d
  ON p.nazwa COLLATE utf8mb4_unicode_ci=d.nazwa COLLATE utf8mb4_unicode_ci
JOIN mapa_planety e
  ON e.nazwa COLLATE utf8mb4_unicode_ci='Eriadu' COLLATE utf8mb4_unicode_ci
JOIN mapa_planety k
  ON k.nazwa COLLATE utf8mb4_unicode_ci='Kabal' COLLATE utf8mb4_unicode_ci;

START TRANSACTION;

-- Przesuń obiekty znajdujące się przy tych światach razem z układami.
UPDATE mapa_obiekty o
JOIN tmp_severiana_positions d ON d.planeta_id=o.planeta_id
SET o.x=o.x+(d.nowe_x-d.stare_x),
    o.y=o.y+(d.nowe_y-d.stare_y),
    o.lot_start_x=IF(o.lot_start_x IS NULL,NULL,o.lot_start_x+(d.nowe_x-d.stare_x)),
    o.lot_start_y=IF(o.lot_start_y IS NULL,NULL,o.lot_start_y+(d.nowe_y-d.stare_y));

-- Jeśli system jest celem trwającego lotu, przesuń także punkt końcowy trajektorii.
UPDATE mapa_obiekty o
JOIN tmp_severiana_positions d ON d.planeta_id=o.lot_cel_planeta_id
SET o.lot_cel_x=o.lot_cel_x+(d.nowe_x-d.stare_x),
    o.lot_cel_y=o.lot_cel_y+(d.nowe_y-d.stare_y);

-- Niezależne, nieregularne systemy gwiezdne w korytarzu pomiędzy Eriadu i Kabal.
UPDATE mapa_planety p
JOIN tmp_severiana_positions d ON d.planeta_id=p.id
SET p.x=d.nowe_x,p.y=d.nowe_y,p.slonca=1;

INSERT INTO schema_migracje (wersja,opis)
VALUES ('v63-rozrzut-ukladow-severiana','Nieregularne pozycje 12 systemów Nowej Rebelii pomiędzy Eriadu i Kabal')
ON DUPLICATE KEY UPDATE opis=VALUES(opis);

COMMIT;
DROP TEMPORARY TABLE IF EXISTS tmp_severiana_positions;
DROP TEMPORARY TABLE IF EXISTS tmp_severiana_scatter;

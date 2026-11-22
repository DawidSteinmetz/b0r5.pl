-- Korekta po planetach 051–059: producenci, którzy nie nadążali za kontraktami, i przesadzona wydajność.
--
-- • Cassander, paliwo: kontrakty ok. 55 300 t/dobę przy wydajności 47 000 → 62 000 t/dobę (świat paliwowy).
-- • Mygeeto, węgiel: kontrakty ok. 117 900 t/dobę przy wydajności 62 000 → linie dla obiektów spoza Mygeeto
--   (Foundry, Corellia, Eriadu, Ord Mantell) przechodzą do najbliższego innego producenta węgla z wydajnością
--   co najmniej 100 000 t/dobę; Mygeeto zostaje ze swoimi kontraktami lokalnymi.
-- • Albrae-Don, towary luksusowe: 240 000 t/dobę przy popycie ok. 2 300 → 9 000 t/dobę.
-- Rynek tych wierszy przeliczony wspólnym wzorem (saldo i odległość do najbliższego producenta).
-- Dostawy lokalne (producent na tej samej planecie co obiekt) gra pokazuje jako lot w atmosferze — bez zmian w danych.
--
-- Import w phpMyAdmin po 051–059. Ponowny import jest bezpieczny.

USE hololink;
SET NAMES utf8mb4;

UPDATE gosp_produkcja SET wydajnosc_t_doba = 62000 WHERE planeta = 'Cassander' AND towar = 'paliwo';
UPDATE gosp_produkcja SET wydajnosc_t_doba = 9000 WHERE planeta = 'Albrae-Don' AND towar = 'luksus';

-- Węgiel dla obiektów spoza Mygeeto: najbliższy inny producent (>= 100 000 t/dobę).
UPDATE gosp_linie l
JOIN gosp_obiekty o ON o.id = l.obiekt_id
JOIN mapa_planety op ON op.nazwa = o.planeta
SET l.producent = (
  SELECT gp.planeta FROM gosp_produkcja gp JOIN mapa_planety pp ON pp.nazwa = gp.planeta
  WHERE gp.towar = 'wegiel' AND gp.planeta <> 'Mygeeto' AND gp.wydajnosc_t_doba >= 100000
  ORDER BY POW(pp.x - op.x, 2) + POW(pp.y - op.y, 2) LIMIT 1)
WHERE l.towar = 'wegiel' AND l.producent = 'Mygeeto' AND o.planeta <> 'Mygeeto';

-- Przeliczenie rynku dla poprawionych producentów (wzór jak w 050–059).
INSERT INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi)
SELECT x.planeta, x.towar, 'oficjalny_hurt', x.prod, x.kons,
       ROUND(LEAST(2.5, GREATEST(0.5, (1 - 0.30 * (x.prod - x.kons) / NULLIF(x.prod + x.kons, 0)))), 3),
       ROUND(x.cena * LEAST(2.5, GREATEST(0.5, (1 - 0.30 * (x.prod - x.kons) / NULLIF(x.prod + x.kons, 0)))), 2),
       x.szcz, 'wygenerowane', 'korekta 060: wydajność dopasowana do kontraktów'
FROM (
  SELECT gp.planeta, gp.towar, gp.wydajnosc_t_doba AS prod, t.cena_bazowa_kr_t AS cena,
         COALESCE((SELECT SUM(z.t_doba) FROM gosp_zuzycie z JOIN gosp_obiekty o ON o.id = z.obiekt_id WHERE o.planeta = gp.planeta AND z.towar = gp.towar), 0) AS kons,
         IF(EXISTS (SELECT 1 FROM gosp_obiekty o WHERE o.planeta = gp.planeta), 'obiekty', 'szacunek') AS szcz
  FROM gosp_produkcja gp JOIN gosp_towary t ON t.kod = gp.towar
  WHERE (gp.planeta, gp.towar) IN (('Cassander','paliwo'), ('Albrae-Don','luksus'), ('Mygeeto','wegiel'))
) x
ON DUPLICATE KEY UPDATE produkcja_t_doba = VALUES(produkcja_t_doba), zuzycie_t_doba = VALUES(zuzycie_t_doba),
  cena_indeks = VALUES(cena_indeks), cena_kr_t = VALUES(cena_kr_t), uwagi = VALUES(uwagi);

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v60-gospodarka-korekta', 'Gospodarka: korekta producentów po planetach 051–059')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

-- Kontrola: producenci z kontraktami powyżej wydajności (powinno być pusto) i nowi dostawcy węgla.
SELECT l.producent, l.towar, ROUND(SUM(l.t_doba)) AS kontrakty, p.wydajnosc_t_doba
FROM gosp_linie l JOIN gosp_produkcja p ON p.planeta = l.producent AND p.towar = l.towar
GROUP BY l.producent, l.towar, p.wydajnosc_t_doba HAVING SUM(l.t_doba) > p.wydajnosc_t_doba;
SELECT o.planeta, l.producent, ROUND(SUM(l.t_doba)) AS wegiel_t_doba FROM gosp_linie l JOIN gosp_obiekty o ON o.id = l.obiekt_id
WHERE l.towar = 'wegiel' AND o.planeta IN ('Foundry','Corellia','Eriadu','Ord Mantell','Mygeeto') GROUP BY o.planeta, l.producent;

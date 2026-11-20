-- Silniki podświetlne: szybki rozpęd i hamowanie, potem stała prędkość przelotowa (pc na dobę).
--
-- mapa_naped_jednostki.podswietlna_pc_doba — prędkość przelotowa. Czas lotu = 2 min (rozpęd i hamowanie)
-- + droga / prędkość. Skala: Sokół Millennium (najlepsze silniki) leci z Hoth na Bespin (132,9 pc na mapie)
-- w ok. 3 dni = 45 pc/doba. Loty w obrębie układu trwają ok. 2 minut (sam przelot to ułamek sekundy).
-- Zastępuje przyspieszenie z migracji 038 (kolumna przyspieszenie_g, jeśli jest, nie jest już używana).
-- MG może zmienić każdą jednostkę (profil napędu w panelu albo UPDATE … SET podswietlna_pc_doba = …).
--
-- Import w phpMyAdmin. Ponowny import: ALTER zgłosi „Duplicate column” — wtedy wystarczy reszta pliku.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_naped_jednostki
  ADD COLUMN podswietlna_pc_doba DECIMAL(8,2) NOT NULL DEFAULT 20;

UPDATE mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id
SET n.podswietlna_pc_doba = CASE
  WHEN o.klasa LIKE '%YT-1300%' OR o.nazwa LIKE '%Sok_ł%' OR o.nazwa LIKE '%Millennium%' THEN 45
  WHEN o.klasa LIKE '%Kom%rk%' THEN 35
  WHEN o.klasa LIKE '%Arquitens%' OR o.nazwa LIKE 'Arquitens%' THEN 28
  WHEN o.klasa LIKE '%Lambda%' THEN 15
  WHEN o.klasa LIKE '%ISD%' OR o.klasa LIKE '%Imperial%' OR o.nazwa LIKE 'ISD%' THEN 22
  WHEN o.klasa LIKE '%Gozanti%' OR o.nazwa LIKE 'Gozanti%' THEN 20
  ELSE 20 END;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v39-predkosc-podswietlna-pc-doba', 'Lot podświetlny: szybki rozpęd, stała prędkość przelotowa w pc na dobę (Sokół 45)')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

SELECT o.nazwa, o.klasa, n.podswietlna_pc_doba FROM mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id ORDER BY n.podswietlna_pc_doba DESC, o.nazwa;

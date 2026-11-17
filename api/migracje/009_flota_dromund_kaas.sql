-- Flota przy Dromund Kaas, kapitan NPC oraz rozszerzenie edytora nazw jednostek.
-- Wymaga migracji 008_profile_napedu_arquitens_gozanti.sql.
-- Importuj raz do działającej bazy; nie zmienia nazw ani przydziałów już istniejących jednostek.

SET NAMES utf8mb4;

ALTER TABLE mapa_floty
  ADD COLUMN dowodca_osoba_id BIGINT UNSIGNED NULL AFTER dowodca_login,
  ADD KEY ix_mapa_floty_dowodca_osoba (dowodca_osoba_id,status),
  ADD CONSTRAINT fk_mapa_floty_dowodca_osoba FOREIGN KEY (dowodca_osoba_id) REFERENCES osoby (podmiot_id);

-- Dromund Kaas dodany obok Korribanu; współrzędne są przybliżone w lokalnej siatce HOLOLINK.
INSERT INTO mapa_planety (id,nazwa,region,x,y,grawitacja,slonca,ksiezyce,doba,rok,srednica)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM mapa_planety), 'Dromund Kaas', 7, 9500, 7250, 1.000, 1, 2, 24, 365, 22450
WHERE NOT EXISTS (SELECT 1 FROM mapa_planety WHERE nazwa='Dromund Kaas');

INSERT INTO podmioty (typ,nazwa)
SELECT 'osoba','Kapitan Rhalen Vossk'
WHERE NOT EXISTS (SELECT 1 FROM podmioty WHERE typ='osoba' AND nazwa='Kapitan Rhalen Vossk');

SET @rhalen_vossk_id = (SELECT id FROM podmioty WHERE typ='osoba' AND nazwa='Kapitan Rhalen Vossk' ORDER BY id LIMIT 1);
INSERT INTO osoby (podmiot_id,rola,stan,pula_kandydatow)
SELECT @rhalen_vossk_id,'kapitan imperialnej floty','zdrowy','brak'
WHERE @rhalen_vossk_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM osoby WHERE podmiot_id=@rhalen_vossk_id);

INSERT INTO mapa_floty (kod,nazwa,dowodca_login,dowodca_osoba_id,opis)
VALUES ('dromund-kaas-imperialna','Flota Dromund Kaas',NULL,@rhalen_vossk_id,'Imperialna grupa eskortowa z okrętem flagowym ISD I „Legacy”, pod dowództwem kapitana Rhalena Vosska.')
AS nowa
ON DUPLICATE KEY UPDATE
  nazwa=nowa.nazwa,
  dowodca_osoba_id=COALESCE(mapa_floty.dowodca_osoba_id,nowa.dowodca_osoba_id),
  status=IF(mapa_floty.status='nieaktywna','aktywna',mapa_floty.status),
  opis=COALESCE(mapa_floty.opis,nowa.opis);

-- Okręty zajmują orbitę nad planetą. Odstęp 10 jednostek oddziela znaczniki od kółka planety.
INSERT IGNORE INTO mapa_obiekty
  (kod,typ,nazwa,rodzaj,klasa,wlasciciel,status,planeta_id,x,y,kolor,znacznik,opis,aktywny)
SELECT 'dromund-kaas-isd-i-legacy','statek','ISD I „Legacy”','okręt','ISD I','Imperium','sprawny',p.id,p.x+10,p.y+10,'#00ff66','trojkat','Okręt flagowy Floty Dromund Kaas.',1
FROM mapa_planety p WHERE p.nazwa='Dromund Kaas';

INSERT IGNORE INTO mapa_obiekty
  (kod,typ,nazwa,rodzaj,klasa,wlasciciel,status,planeta_id,x,y,kolor,znacznik,opis,aktywny)
SELECT CONCAT('dromund-kaas-arquitens-',LPAD(n,2,'0')),'statek',CONCAT('Arquitens DK-',LPAD(n,2,'0')),'okręt','Arquitens','Imperium','sprawny',p.id,p.x+10,p.y+10,'#00ff66','trojkat','Krążownik eskortowy Floty Dromund Kaas.',1
FROM mapa_planety p
CROSS JOIN (SELECT 1 AS n UNION ALL SELECT 2) numery
WHERE p.nazwa='Dromund Kaas';

INSERT IGNORE INTO mapa_obiekty
  (kod,typ,nazwa,rodzaj,klasa,wlasciciel,status,planeta_id,x,y,kolor,znacznik,opis,aktywny)
SELECT CONCAT('dromund-kaas-gozanti-',LPAD(n,2,'0')),'statek',CONCAT('Gozanti DK-',LPAD(n,2,'0')),'okręt','Gozanti','Imperium','sprawny',p.id,p.x+10,p.y+10,'#00ff66','trojkat','Transportowiec czterech standardowych myśliwców TIE.',1
FROM mapa_planety p
CROSS JOIN (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) numery
WHERE p.nazwa='Dromund Kaas';

INSERT IGNORE INTO mapa_floty_jednostki (obiekt_id,flota_id,rola,kolejnosc,tie_liczba)
SELECT o.id,f.id,'okręt flagowy',0,NULL FROM mapa_obiekty o JOIN mapa_floty f ON f.kod='dromund-kaas-imperialna' WHERE o.kod='dromund-kaas-isd-i-legacy';
INSERT IGNORE INTO mapa_floty_jednostki (obiekt_id,flota_id,rola,kolejnosc,tie_liczba)
SELECT o.id,f.id,'krążownik eskortowy',n, NULL
FROM mapa_obiekty o JOIN mapa_floty f ON f.kod='dromund-kaas-imperialna'
JOIN (SELECT 1 AS n UNION ALL SELECT 2) numery ON o.kod=CONCAT('dromund-kaas-arquitens-',LPAD(n,2,'0'));
INSERT IGNORE INTO mapa_floty_jednostki (obiekt_id,flota_id,rola,kolejnosc,tie_liczba)
SELECT o.id,f.id,'transportowiec myśliwców',n+2,4
FROM mapa_obiekty o JOIN mapa_floty f ON f.kod='dromund-kaas-imperialna'
JOIN (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) numery
  ON o.kod=CONCAT('dromund-kaas-gozanti-',LPAD(n,2,'0'));

INSERT IGNORE INTO mapa_naped_jednostki (obiekt_id)
SELECT j.obiekt_id FROM mapa_floty_jednostki j JOIN mapa_floty f ON f.id=j.flota_id
WHERE f.kod='dromund-kaas-imperialna';

INSERT IGNORE INTO schema_migracje (wersja,opis)
VALUES ('v9-flota-dromund-kaas','Flota Dromund Kaas, kapitan NPC oraz edycja nazw okrętów');

-- Eriadu: górzysta twierdza Imperium, z modelowym przemysłem ciężkim i stoczniami.
-- Kanon: górzysty świat Outer Rim, imperialny ośrodek związany z Grand Moffem Tarkinem.
-- Legends: przemysł, prestiżowe stocznie i wydobycie lommite (kod towaru w HOLOLINK: lommit).
-- Konkretne zakłady, ich współrzędne i skala produkcji są modelowe. Mapa projektu ma region 5
-- (Środkowe Rubieże), choć źródła kanoniczne umieszczają Eriadu w Outer Rim; migracja nie zmienia mapy.
USE hololink;
SET NAMES utf8mb4;

INSERT INTO planety_cykle (planeta_id,doba_h,rok_dni,nachylenie_osi_deg,srednica_km)
SELECT id,24.0,360,23.0,13490 FROM mapa_planety WHERE nazwa='Eriadu'
ON DUPLICATE KEY UPDATE doba_h=VALUES(doba_h),rok_dni=VALUES(rok_dni),nachylenie_osi_deg=VALUES(nachylenie_osi_deg),srednica_km=VALUES(srednica_km);
INSERT INTO planety_gry (planeta_id,typ_planety,urbanizacja,opis)
SELECT id,'miasto','wysoka','Górzysty, silnie zurbanizowany świat z imperialnym kompleksem władzy. Wydobycie lommite, przemysł ciężki i stocznie rozbudowano w modelu gry na podstawie Legends.'
FROM mapa_planety WHERE nazwa='Eriadu'
ON DUPLICATE KEY UPDATE typ_planety=VALUES(typ_planety),urbanizacja=VALUES(urbanizacja),opis=VALUES(opis);

-- Towary są już w słowniku: lommit (lommite), durastal, elektronika, komponenty stoczniowe i żywność.
-- Korekta szacunkowej podaży Mygeeto po migracji 057 pozostaje bez zmian; tu aktualizujemy tylko Eriadu.
INSERT INTO gosp_produkcja (planeta,towar,wydajnosc_t_doba,zrodlo) VALUES
('Eriadu','lommit',360000,'wookieepedia (Legends); wydajność wygenerowana'),
('Eriadu','durastal',210000,'wygenerowane; produkcja hutnicza'),
('Eriadu','elektronika',42000,'wookieepedia (Legends: technologia); wolumen wygenerowany'),
('Eriadu','komponenty_stoczniowe',240000,'wookieepedia (Legends: stocznie); wolumen wygenerowany'),
('Eriadu','zywnosc',270000,'wygenerowane; regionalne gospodarstwa i przetwórstwo')
ON DUPLICATE KEY UPDATE wydajnosc_t_doba=VALUES(wydajnosc_t_doba),zrodlo=VALUES(zrodlo);

DELETE FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Eriadu');
DELETE FROM gosp_zuzycie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Eriadu');
DELETE FROM gosp_obiekty WHERE planeta='Eriadu';
SET @obj := (SELECT COALESCE(MAX(id),0) FROM gosp_obiekty);

INSERT INTO gosp_obiekty (id,planeta,nazwa,rodzaj,miejsce,szer,dl,kat_deg,opis) VALUES
(@obj+1,'Eriadu','Kopalnia lommite Phelar P-01','kopalnia','powierzchnia',22.1,34.0,NULL,'Modelowa kopalnia rudy lommite w górskim pasie wydobywczym.'),
(@obj+2,'Eriadu','Kopalnia lommite Wysoki Grzbiet P-04','kopalnia','powierzchnia',27.4,41.3,NULL,'Odkrywka i sortownia rudy; lokalizacja modelowa.'),
(@obj+3,'Eriadu','Kopalnia lommite Kalen P-08','kopalnia','powierzchnia',31.2,48.7,NULL,'Wydobycie w górzystym wnętrzu planety.'),
(@obj+4,'Eriadu','Kopalnia lommite Południowy Mur P-12','kopalnia','powierzchnia',-28.1,54.2,NULL,'Eksploatacja złóż w południowym paśmie górskim.'),
(@obj+5,'Eriadu','Zakład wzbogacania rudy Phelar W-2','zaklad','powierzchnia',20.3,31.8,NULL,'Płukanie, sortowanie i przygotowanie lommite do hut.'),
(@obj+6,'Eriadu','Huta durastali Phelar H-01','huta','powierzchnia',18.8,29.4,NULL,'Wytop i walcowanie blach konstrukcyjnych dla przemysłu i stoczni.'),
(@obj+7,'Eriadu','Huta stopów Imperial Foundry H-07','huta','powierzchnia',24.0,36.6,NULL,'Modelowy kompleks hutniczy zasilany lommite i węglem koksującym.'),
(@obj+8,'Eriadu','Eriadu Manufacturing Shipyards — dok A','fabryka','powierzchnia',15.0,25.1,NULL,'Modelowy dok ciężki inspirowany stoczniami opisywanymi w Legends.'),
(@obj+9,'Eriadu','Eriadu Manufacturing Shipyards — dok B','fabryka','powierzchnia',14.2,24.0,NULL,'Montaż sekcji kadłubów i wyposażenia okrętowego.'),
(@obj+10,'Eriadu','Stocznia Phelar — sekcje napędowe','fabryka','powierzchnia',13.0,22.4,NULL,'Produkcja modułów napędowych i sekcji maszynowni.'),
(@obj+11,'Eriadu','Zakład komponentów stoczniowych S-11','fabryka','powierzchnia',17.1,27.5,NULL,'Podzespoły i konstrukcje dla doków eriaduńskich.'),
(@obj+12,'Eriadu','Fabryka komputerów i sensorów E-3','fabryka','powierzchnia',11.6,20.8,NULL,'Modelowa produkcja elektroniki przemysłowej i systemów kontroli.'),
(@obj+13,'Eriadu','Zakład precyzyjnej optyki O-6','fabryka','powierzchnia',10.8,19.7,NULL,'Optyka i sensory do kontroli jakości oraz nawigacji.'),
(@obj+14,'Eriadu','Park przemysłowy Phelar Zachód','fabryka','powierzchnia',19.1,30.5,NULL,'Warsztaty poddostawców, obróbka i naprawy przemysłowe.'),
(@obj+15,'Eriadu','Park przemysłowy Phelar Wschód','fabryka','powierzchnia',21.8,33.7,NULL,'Zakłady narzędziowe i montażowe przy głównej strefie przemysłu.'),
(@obj+16,'Eriadu','Kompleks administracyjny Tarkina','zaklad','powierzchnia',8.4,15.2,NULL,'Modelowy obiekt administracyjny; nie jest rekonstrukcją konkretnego kanonicznego budynku.'),
(@obj+17,'Eriadu','Garnizon imperialny Phelar','zaklad','powierzchnia',7.1,13.6,NULL,'Zaopatrzenie wojskowego kompleksu; obecność imperialna potwierdzona, skala modelowa.'),
(@obj+18,'Eriadu','Miasto Phelar — sieć komunalna','zaklad','powierzchnia',6.0,12.4,NULL,'Modelowe zaopatrzenie miejskie przy głównej osi przemysłowej.'),
(@obj+19,'Eriadu','Dzielnica stoczniowa Północ','zaklad','powierzchnia',12.0,21.1,NULL,'Mieszkania i usługi dla załóg stoczniowych.'),
(@obj+20,'Eriadu','Osiedle hutnicze Wysoki Grzbiet','zaklad','powierzchnia',25.1,39.0,NULL,'Zaopatrzenie personelu hut i kopalń.'),
(@obj+21,'Eriadu','Elektrownia przemysłowa Phelar E-1','zaklad','powierzchnia',23.4,35.2,NULL,'Zasilanie hut, stoczni i infrastruktury miejskiej.'),
(@obj+22,'Eriadu','Elektrownia górska Kalen E-4','zaklad','powierzchnia',32.4,49.5,NULL,'Modelowa stacja energetyczna przy kopalniach.'),
(@obj+23,'Eriadu','Terminal rudowy Phelar T-01','terminal','powierzchnia',16.0,26.6,NULL,'Ważenie, magazynowanie i odprawa lommite.'),
(@obj+24,'Eriadu','Port towarowy Phelar P-20','port','powierzchnia',4.8,10.1,NULL,'Główny modelowy terminal frachtowy dla przemysłu i miasta.'),
(@obj+25,'Eriadu','Terminal stoczniowy Imperial Docks T-09','terminal','powierzchnia',9.2,17.3,NULL,'Odprawa komponentów, blach i wyposażenia okrętowego.'),
(@obj+26,'Eriadu','Centrum odprawy i kontroli C-4','terminal','powierzchnia',7.4,14.5,NULL,'Modelowy urząd kontroli ładunków przy imperialnym węźle.'),
(@obj+27,'Eriadu','Skład durastali i profili S-2','sklad','powierzchnia',16.9,27.1,NULL,'Bufor blach i profili dla doków.'),
(@obj+28,'Eriadu','Skład paliwa Phelar F-8','sklad_paliwa','powierzchnia',20.7,32.4,NULL,'Paliwo dla hut, floty roboczej i transportu.'),
(@obj+29,'Eriadu','Chłodnia portowa Phelar C-6','chlodnia','powierzchnia',5.4,11.3,NULL,'Bufor żywności dla portu i dzielnic przemysłowych.'),
(@obj+30,'Eriadu','Zakład przetwórstwa żywności R-5','przetwornia','powierzchnia',3.8,9.2,NULL,'Pakowanie i przetwarzanie żywności dla miasta oraz zmian stoczniowych.'),
(@obj+31,'Eriadu','Gospodarstwo równin Phelar R-01','farma','powierzchnia',-4.1,64.0,NULL,'Modelowa produkcja żywności w niżej położonej strefie.'),
(@obj+32,'Eriadu','Spółdzielnia upraw doliny R-04','farma','powierzchnia',-10.2,72.3,NULL,'Uprawy polowe w dolinie; wielkość produkcji zawiera się w szacunku globalnym.'),
(@obj+33,'Eriadu','Kompleks hydroponiczny Phelar S-8','farma','powierzchnia',2.4,7.8,NULL,'Kontrolowana produkcja świeżej żywności dla miasta.'),
(@obj+34,'Eriadu','Warsztat maszyn górniczych M-3','fabryka','powierzchnia',28.1,43.6,NULL,'Serwis transporterów, wiertnic i urządzeń do sortowania rudy.'),
(@obj+35,'Eriadu','Centrum serwisu droidów D-11','fabryka','powierzchnia',10.1,18.9,NULL,'Naprawa robotów przemysłowych i systemów magazynowych.');

INSERT INTO gosp_zuzycie (obiekt_id,towar,t_doba) VALUES
(@obj+1,'paliwo',650),(@obj+1,'elektronika',18),(@obj+1,'durastal',450),
(@obj+2,'paliwo',540),(@obj+2,'elektronika',14),(@obj+2,'durastal',380),
(@obj+3,'paliwo',720),(@obj+3,'elektronika',20),(@obj+3,'durastal',520),
(@obj+4,'paliwo',430),(@obj+4,'elektronika',12),(@obj+4,'durastal',300),
(@obj+5,'paliwo',280),(@obj+5,'elektronika',22),(@obj+6,'lommit',18500),(@obj+6,'wegiel',2400),(@obj+6,'paliwo',780),
(@obj+7,'lommit',14000),(@obj+7,'wegiel',1900),(@obj+7,'durastal',1200),(@obj+8,'durastal',26000),(@obj+8,'elektronika',1800),(@obj+8,'komponenty_stoczniowe',9000),(@obj+8,'paliwo',1200),
(@obj+9,'durastal',22000),(@obj+9,'elektronika',1500),(@obj+9,'komponenty_stoczniowe',7000),(@obj+9,'paliwo',1050),
(@obj+10,'durastal',15000),(@obj+10,'elektronika',950),(@obj+10,'paliwo',800),
(@obj+11,'durastal',10000),(@obj+11,'elektronika',520),(@obj+11,'paliwo',560),
(@obj+12,'durastal',1800),(@obj+12,'elektronika',80),(@obj+12,'paliwo',140),
(@obj+13,'durastal',900),(@obj+13,'elektronika',45),(@obj+14,'durastal',6500),(@obj+14,'elektronika',230),(@obj+14,'paliwo',320),
(@obj+15,'durastal',7200),(@obj+15,'elektronika',280),(@obj+15,'paliwo',360),
(@obj+16,'zywnosc',3500),(@obj+16,'elektronika',45),(@obj+16,'paliwo',180),(@obj+17,'zywnosc',4800),(@obj+17,'elektronika',90),(@obj+17,'paliwo',280),
(@obj+18,'zywnosc',98000),(@obj+18,'paliwo',350),(@obj+18,'luksus',28),
(@obj+19,'zywnosc',46000),(@obj+19,'paliwo',160),(@obj+19,'luksus',15),
(@obj+20,'zywnosc',38000),(@obj+20,'paliwo',130),(@obj+21,'durastal',2400),(@obj+21,'elektronika',48),(@obj+21,'paliwo',1150),
(@obj+22,'durastal',1100),(@obj+22,'elektronika',24),(@obj+22,'paliwo',420),
(@obj+23,'paliwo',240),(@obj+23,'elektronika',36),(@obj+23,'zywnosc',2200),(@obj+24,'durastal',4200),(@obj+24,'elektronika',110),(@obj+24,'paliwo',980),(@obj+24,'zywnosc',12000),
(@obj+25,'durastal',1800),(@obj+25,'elektronika',90),(@obj+25,'paliwo',430),(@obj+26,'elektronika',55),(@obj+26,'paliwo',100),
(@obj+27,'paliwo',60),(@obj+28,'elektronika',6),(@obj+29,'zywnosc',18000),(@obj+29,'paliwo',75),
(@obj+30,'durastal',120),(@obj+30,'paliwo',60),(@obj+30,'zywnosc',8500),
(@obj+31,'nawozy',820),(@obj+31,'paliwo',45),(@obj+32,'nawozy',680),(@obj+32,'paliwo',38),(@obj+33,'nawozy',420),(@obj+33,'paliwo',28),
(@obj+34,'durastal',600),(@obj+34,'elektronika',55),(@obj+34,'paliwo',130),(@obj+35,'durastal',350),(@obj+35,'elektronika',85),(@obj+35,'paliwo',75);

-- Dla dużych odbiorców żywności podziel dostawy między Eriadu, Mattri i Foundry;
-- małe pobory obsługuje lokalna produkcja. Pozostałe towary mają jednego wskazanego producenta.
SET @lin := (SELECT COALESCE(MAX(id),0) FROM gosp_linie);
INSERT INTO gosp_linie (id,obiekt_id,towar,producent,t_doba,ladownosc_t,klasa,odstep_s,predkosc_au_h,postoj_h,faza,obieg)
SELECT @lin+ROW_NUMBER() OVER (ORDER BY q.obiekt_id,q.towar,q.producent),q.obiekt_id,q.towar,q.producent,q.t_doba,
 GREATEST(500,IF(q.t_doba<q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek)),q.klasa,
 ROUND(GREATEST(500,IF(q.t_doba<q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek))/q.t_doba*86400),
 1.8,2.5,ROUND(MOD(q.obiekt_id*0.137+q.t_doba*0.0006,1),4),'oficjalny_hurt'
FROM (
 SELECT z.obiekt_id,z.towar,
  CASE WHEN z.towar='zywnosc' AND z.t_doba<20000 THEN z.t_doba ELSE z.t_doba*s.udzial END AS t_doba,
  s.producent,t.ladownosc_t AS typowy_ladunek,t.klasa_transportu AS klasa
 FROM gosp_zuzycie z JOIN gosp_towary t ON t.kod=z.towar
 JOIN (
  SELECT 'zywnosc' AS towar,'Eriadu' AS producent,0.60 AS udzial UNION ALL
  SELECT 'zywnosc','Mattri',0.25 UNION ALL SELECT 'zywnosc','Foundry',0.15
  UNION ALL SELECT 'nawozy','Foundry',1 UNION ALL SELECT 'durastal','Eriadu',1
  UNION ALL SELECT 'lommit','Eriadu',1 UNION ALL SELECT 'wegiel','Mygeeto',1
  UNION ALL SELECT 'elektronika','Eriadu',1 UNION ALL SELECT 'komponenty_stoczniowe','Eriadu',1
  UNION ALL SELECT 'paliwo','Cassander',1 UNION ALL SELECT 'luksus','Albrae-Don',1
 ) s ON s.towar=z.towar
 WHERE z.obiekt_id BETWEEN @obj+1 AND @obj+35
  AND (z.towar<>'zywnosc' OR z.t_doba>=20000 OR s.producent='Eriadu')
) q;

INSERT INTO lokalizacje (typ,rodzaj,rodzic_id,uklad_id,planeta_id,nazwa,x_km,y_km,publiczna,status,opis)
SELECT 'ladowisko','platforma',NULL,NULL,p.id,CONCAT('Lądowisko — ',o.nazwa),
 ROUND(o.dl/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),
 ROUND(o.szer/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),1,'aktywna',o.opis
FROM gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta LEFT JOIN planety_cykle c ON c.planeta_id=p.id
WHERE o.planeta='Eriadu' AND o.miejsce='powierzchnia'
ON DUPLICATE KEY UPDATE x_km=VALUES(x_km),y_km=VALUES(y_km),publiczna=1,status='aktywna';
UPDATE gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta
JOIN lokalizacje l ON l.planeta_id=p.id AND l.nazwa=CONCAT('Lądowisko — ',o.nazwa)
SET o.lokalizacja_id=l.id WHERE o.planeta='Eriadu' AND o.miejsce='powierzchnia';

-- Rynek Eriadu liczony z produkcji i sum zużycia obiektów, według wspólnego wzoru cenowego.
INSERT INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi)
SELECT 'Eriadu',x.towar,'oficjalny_hurt',x.prod,x.kons,
 ROUND(LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),3),
 ROUND(x.cena*LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),2),
 'obiekty','wygenerowane','model obiektów; lommite i stocznie oparte na Legends, pozostałe wolumeny wygenerowane'
FROM (
 SELECT a.towar,a.prod,a.kons,t.cena_bazowa_kr_t AS cena,
  CASE WHEN a.prod>0 THEN 0 ELSE COALESCE((SELECT MIN(SQRT(POW(mp.x-pp.x,2)+POW(mp.y-pp.y,2)))
   FROM gosp_produkcja gp JOIN mapa_planety pp ON pp.nazwa=gp.planeta JOIN mapa_planety mp ON mp.nazwa='Eriadu'
   WHERE gp.towar=a.towar AND gp.wydajnosc_t_doba>0),3000) END AS odl
 FROM (
  SELECT k.towar,COALESCE(p.wydajnosc_t_doba,0) AS prod,COALESCE(z.kons,0) AS kons
  FROM (SELECT towar FROM gosp_produkcja WHERE planeta='Eriadu'
   UNION SELECT g.towar FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id WHERE o.planeta='Eriadu') k
  LEFT JOIN gosp_produkcja p ON p.planeta='Eriadu' AND p.towar=k.towar
  LEFT JOIN (SELECT g.towar,SUM(g.t_doba) AS kons FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id
   WHERE o.planeta='Eriadu' GROUP BY g.towar) z ON z.towar=k.towar
 ) a JOIN gosp_towary t ON t.kod=a.towar
) x
ON DUPLICATE KEY UPDATE produkcja_t_doba=VALUES(produkcja_t_doba),zuzycie_t_doba=VALUES(zuzycie_t_doba),cena_indeks=VALUES(cena_indeks),cena_kr_t=VALUES(cena_kr_t),szczegolowosc=VALUES(szczegolowosc),zrodlo=VALUES(zrodlo),uwagi=VALUES(uwagi);

-- Pamięć o podaży lommite i o dostawcach importów; szczegółowych rynków nie degraduje.
INSERT INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi) VALUES
('Eriadu','lommit','oficjalny_hurt',360000,0,0.700,154.00,'szacunek','wookieepedia','otwarty wątek: dodatkowy eksporter lommite; wydajność wygenerowana'),
('Mattri','zywnosc','oficjalny_hurt',1200000,0,0.700,84.00,'szacunek','wygenerowane','otwarty wątek: dostawy żywności dla przemysłu Eriadu'),
('Foundry','zywnosc','oficjalny_hurt',1400000,0,0.700,84.00,'szacunek','wygenerowane','otwarty wątek: dostawy żywności dla przemysłu Eriadu'),
('Foundry','nawozy','oficjalny_hurt',80000,0,0.700,315.00,'szacunek','wygenerowane','otwarty wątek: nawozy do gospodarstw Eriadu'),
('Mygeeto','wegiel','oficjalny_hurt',62000,0,0.700,21.00,'szacunek','wygenerowane','otwarty wątek: modelowe paliwo stałe do hut'),
('Cassander','paliwo','oficjalny_hurt',47000,0,0.700,420.00,'szacunek','wygenerowane','otwarty wątek: paliwo do kopalń i stoczni'),
('Albrae-Don','luksus','oficjalny_hurt',240000,0,0.700,21000.00,'szacunek','wygenerowane','otwarty wątek: dostawy dla dzielnic i załóg')
ON DUPLICATE KEY UPDATE
 produkcja_t_doba=IF(szczegolowosc='szacunek',VALUES(produkcja_t_doba),produkcja_t_doba),
 zuzycie_t_doba=IF(szczegolowosc='szacunek',VALUES(zuzycie_t_doba),zuzycie_t_doba),
 cena_indeks=IF(szczegolowosc='szacunek',VALUES(cena_indeks),cena_indeks),
 cena_kr_t=IF(szczegolowosc='szacunek',VALUES(cena_kr_t),cena_kr_t),
 zrodlo=IF(szczegolowosc='szacunek',VALUES(zrodlo),zrodlo),
 uwagi=IF(szczegolowosc='szacunek',VALUES(uwagi),uwagi);

INSERT INTO schema_migracje (wersja,opis) VALUES ('v58-gospodarka-eriadu','Gospodarka Eriadu: lommite, przemysł i stocznie')
ON DUPLICATE KEY UPDATE opis=VALUES(opis);

-- Kontrole po imporcie.
SELECT COUNT(*) AS obiekty_eriadu FROM gosp_obiekty WHERE planeta='Eriadu';
SELECT l.id,l.producent,l.towar FROM gosp_linie l LEFT JOIN gosp_produkcja p ON p.planeta=l.producent AND p.towar=l.towar WHERE l.obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Eriadu') AND p.planeta IS NULL;
SELECT l.id FROM gosp_linie l LEFT JOIN gosp_obiekty o ON o.id=l.obiekt_id WHERE o.id IS NULL;
SELECT o.id,o.nazwa FROM gosp_obiekty o LEFT JOIN lokalizacje l ON l.id=o.lokalizacja_id WHERE o.planeta='Eriadu' AND o.miejsce='powierzchnia' AND l.id IS NULL;
SELECT SUM(86400/odstep_s) AS dostawy_na_dobe FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Eriadu');

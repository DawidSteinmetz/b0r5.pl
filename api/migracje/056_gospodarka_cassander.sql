-- Cassander: modelowy węzeł paliw i zaopatrzenia przy trasie Braxant Run.
-- Legends: Cassander był stolicą sektora Cassander, leży w Outer Rim/New Territories i przy Braxant Run;
-- nie ma źródła potwierdzającego złoża ani eksport paliwa. Dlatego wydobycie surowca paliwowego,
-- rafinacja, współrzędne obiektów i skala gospodarki są w całości modelem HOLOLINK, a nie kanonem.
-- Mapa projektu oznacza region=2 (Kolonie), co nie zgadza się z Legends; migracja mapy nie zmienia.
-- Produkcję paliwa z losowych 343,000 korygujemy do 170,000 t/d; dostawy dotychczasowych odbiorców
-- (Mattri, Albrae-Don, Foundry, Corellia i Alpheridies) pozostają bez zmian.
USE hololink;
SET NAMES utf8mb4;

-- Mapa ma dobę 24 h i rok 365 dni; średnica jest estymacją gry.
INSERT INTO planety_cykle (planeta_id,doba_h,rok_dni,nachylenie_osi_deg,srednica_km)
SELECT id,24,365,18,10200 FROM mapa_planety WHERE nazwa='Cassander'
ON DUPLICATE KEY UPDATE doba_h=VALUES(doba_h),rok_dni=VALUES(rok_dni),nachylenie_osi_deg=VALUES(nachylenie_osi_deg),srednica_km=VALUES(srednica_km);
INSERT INTO planety_gry (planeta_id,typ_planety,urbanizacja,opis)
SELECT id,'miasto','wysoka','Modelowy węzeł administracyjny sektora i obsługi Braxant Run; rafinacja paliw jest rozwinięciem gospodarki gry.'
FROM mapa_planety WHERE nazwa='Cassander'
ON DUPLICATE KEY UPDATE typ_planety=VALUES(typ_planety),urbanizacja=VALUES(urbanizacja),opis=VALUES(opis);

INSERT INTO gosp_towary (kod,nazwa,kategoria,ladownosc_t,klasa_transportu,zrodlo,cena_bazowa_kr_t) VALUES
('surowiec_paliwowy','surowiec paliwowy do rafinacji','surowiec',30000,'tankowiec', 'wygenerowane',180),
('nawozy','nawozy mineralne i regeneratory gleby','rolnictwo',12000,'frachtowiec kontenerowy','wygenerowane',450)
ON DUPLICATE KEY UPDATE nazwa=VALUES(nazwa),ladownosc_t=VALUES(ladownosc_t),klasa_transportu=VALUES(klasa_transportu),cena_bazowa_kr_t=VALUES(cena_bazowa_kr_t);

-- Podaż modelowa: rafinerie przerabiają 55 tys. t/d surowca na 47 tys. t/d paliwa.
INSERT INTO gosp_produkcja (planeta,towar,wydajnosc_t_doba,zrodlo) VALUES
('Cassander','surowiec_paliwowy',55000,'wygenerowane'),('Cassander','paliwo',47000,'wygenerowane'),
('Cassander','zywnosc',6200,'wygenerowane'),('Foundry','zywnosc',1400000,'wygenerowane'),
('Foundry','nawozy',80000,'wygenerowane'),('Foundry','metale_rzadkie',175000,'wygenerowane'),
('Corellia','durastal',420000,'wygenerowane'),('Alpheridies','elektronika',12000,'wygenerowane'),
('Albrae-Don','luksus',240000,'wygenerowane'),('Mygeeto','wegiel',2511000,'wygenerowane')
ON DUPLICATE KEY UPDATE wydajnosc_t_doba=VALUES(wydajnosc_t_doba),zrodlo=VALUES(zrodlo);

DELETE FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Cassander');
DELETE FROM gosp_zuzycie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Cassander');
DELETE FROM gosp_obiekty WHERE planeta='Cassander';
SET @obj := (SELECT COALESCE(MAX(id),0) FROM gosp_obiekty);

INSERT INTO gosp_obiekty (id,planeta,nazwa,rodzaj,miejsce,szer,dl,kat_deg,opis) VALUES
(@obj+1,'Cassander','Pole wydobywcze Braxant A-1','kopalnia','powierzchnia',8.2,21.4,NULL,'Modelowe wydobycie surowca paliwowego; brak potwierdzonych złóż w źródłach.'),
(@obj+2,'Cassander','Pole wydobywcze Braxant A-2','kopalnia','powierzchnia',11.6,24.8,NULL,'Odwierty i separacja surowca; całość jest szacunkiem gry.'),
(@obj+3,'Cassander','Pole wydobywcze Północ N-4','kopalnia','powierzchnia',25.1,-33.0,NULL,'Modelowe złoże i stacja pomp.'),
(@obj+4,'Cassander','Pole wydobywcze Południe S-7','kopalnia','powierzchnia',-18.4,67.2,NULL,'Modelowe złoże na południowym płaskowyżu.'),
(@obj+5,'Cassander','Stacja zbiorcza surowca B-1','terminal','powierzchnia',9.6,22.8,NULL,'Mieszanie i buforowanie surowca przed rafinacją.'),
(@obj+6,'Cassander','Rafineria sektorowa Cassander R-1','fabryka','powierzchnia',7.3,18.5,NULL,'Główna modelowa rafineria paliwa reaktorowego.'),
(@obj+7,'Cassander','Rafineria Braxant R-2','fabryka','powierzchnia',14.7,30.1,NULL,'Przerób surowca i blending paliw dla transportu międzygwiezdnego.'),
(@obj+8,'Cassander','Rafineria Północ R-3','fabryka','powierzchnia',24.0,-30.8,NULL,'Modułowa rafineria o mniejszej przepustowości.'),
(@obj+9,'Cassander','Rafineria Południe R-4','fabryka','powierzchnia',-16.2,64.0,NULL,'Modułowa rafineria i magazyn produktów.'),
(@obj+10,'Cassander','Zakład dodatków paliwowych D-2','fabryka','powierzchnia',5.4,15.3,NULL,'Mieszanki i dodatki poprawiające stabilność paliwa.'),
(@obj+11,'Cassander','Laboratorium jakości paliw Q-1','zaklad','powierzchnia',4.3,13.2,NULL,'Kontrola jakości partii i certyfikacja kontraktowa.'),
(@obj+12,'Cassander','Zespół zbiorników Braxant T-1','sklad_paliwa','powierzchnia',10.1,25.5,NULL,'Zapas eksportowy przy głównym korytarzu handlowym.'),
(@obj+13,'Cassander','Zespół zbiorników Cassander T-2','sklad_paliwa','powierzchnia',6.0,17.3,NULL,'Bufor na potrzeby miasta, floty i konwojów.'),
(@obj+14,'Cassander','Terminal tankowców Braxant P-1','port','powierzchnia',3.2,10.8,NULL,'Główny terminal wysyłki paliwa.'),
(@obj+15,'Cassander','Port handlowy sektora P-2','port','powierzchnia',2.1,8.4,NULL,'Odprawa kontraktów i dostaw ogólnych.'),
(@obj+16,'Cassander','Terminal konwojów Braxant C-3','terminal','powierzchnia',1.3,6.1,NULL,'Koordynacja przewozów na Braxant Run.'),
(@obj+17,'Cassander','Stolica sektora — sieć komunalna','zaklad','powierzchnia',7.7,19.8,NULL,'Modelowa administracja sektorowa i usługi miejskie.'),
(@obj+18,'Cassander','Dzielnica rafineryjna W-1','zaklad','powierzchnia',9.4,23.1,NULL,'Zaopatrzenie pracowników zakładów.'),
(@obj+19,'Cassander','Osiedle portowe P-4','zaklad','powierzchnia',2.8,9.6,NULL,'Mieszkania i usługi dla załóg oraz personelu terminali.'),
(@obj+20,'Cassander','Gospodarstwo żywnościowe E-1','farma','powierzchnia',31.0,-42.4,NULL,'Modelowa produkcja lokalnych świeżych artykułów.'),
(@obj+21,'Cassander','Spółdzielnia upraw Braxant E-2','farma','powierzchnia',-8.3,72.4,NULL,'Uprawy polowe na potrzeby lokalnych osiedli.'),
(@obj+22,'Cassander','Zakład opakowań i cystern O-5','fabryka','powierzchnia',12.8,27.6,NULL,'Naprawa kontenerów i wyposażenia dla tankowców.'),
(@obj+23,'Cassander','Warsztat pomp i zaworów M-8','fabryka','powierzchnia',18.5,35.4,NULL,'Serwis pomp, rurociągów i armatury.'),
(@obj+24,'Cassander','Elektrownia przemysłowa E-7','zaklad','powierzchnia',20.2,39.1,NULL,'Zasilanie kompleksów przeróbczych i portów.'),
(@obj+25,'Cassander','Skład części i elektroniki S-9','sklad','powierzchnia',5.6,16.0,NULL,'Części naprawcze dla systemów pompowych i kontroli.'),
(@obj+26,'Cassander','Przychodnia sektorowa M-2','zaklad','powierzchnia',6.8,18.3,NULL,'Zaopatrzenie zdrowotne personelu i mieszkańców.'),
(@obj+27,'Cassander','Stacja ratownicza Braxant R-9','zaklad','powierzchnia',0.8,5.2,NULL,'Obsługa awarii i sytuacji kryzysowych na szlaku.'),
(@obj+28,'Cassander','Centrum zaopatrzenia Cassander S-5','sklad','powierzchnia',8.4,20.5,NULL,'Żywność, narzędzia i towary dla miasta.'),
(@obj+29,'Cassander','Chłodnia portowa F-2','chlodnia','powierzchnia',2.5,9.9,NULL,'Bufor produktów spożywczych dla załóg i portu.'),
(@obj+30,'Cassander','Zajezdnia holowników i cystern V-6','zaklad','powierzchnia',1.5,7.2,NULL,'Serwis lokalnych jednostek transportu paliw.'),
(@obj+31,'Cassander','Węzeł rurociągów Braxant J-4','terminal','powierzchnia',11.2,26.9,NULL,'Modelowe rurociągi łączące stację zbiorczą z rafineriami.'),
(@obj+32,'Cassander','Skład celny sektora C-6','terminal','powierzchnia',3.8,12.1,NULL,'Odprawa i ewidencja hurtowych ładunków.');

INSERT INTO gosp_zuzycie (obiekt_id,towar,t_doba) VALUES
(@obj+1,'paliwo',80),(@obj+1,'elektronika',12),(@obj+2,'paliwo',90),(@obj+2,'elektronika',10),
(@obj+3,'paliwo',70),(@obj+3,'elektronika',8),(@obj+4,'paliwo',65),(@obj+4,'elektronika',8),
(@obj+5,'paliwo',180),
(@obj+6,'surowiec_paliwowy',25000),(@obj+6,'durastal',220),(@obj+6,'elektronika',75),(@obj+6,'paliwo',150),
(@obj+7,'surowiec_paliwowy',15000),(@obj+7,'durastal',180),(@obj+7,'elektronika',55),(@obj+7,'paliwo',100),
(@obj+8,'surowiec_paliwowy',8000),(@obj+8,'durastal',90),(@obj+8,'elektronika',30),(@obj+8,'paliwo',50),
(@obj+9,'surowiec_paliwowy',7000),(@obj+9,'durastal',75),(@obj+9,'elektronika',25),(@obj+9,'paliwo',40),
(@obj+10,'durastal',120),(@obj+10,'elektronika',35),(@obj+10,'paliwo',170),
(@obj+11,'elektronika',18),(@obj+11,'zywnosc',12),(@obj+12,'paliwo',210),(@obj+12,'elektronika',14),
(@obj+13,'paliwo',160),(@obj+13,'elektronika',8),(@obj+14,'paliwo',850),(@obj+14,'elektronika',40),
(@obj+14,'zywnosc',180),(@obj+15,'paliwo',340),(@obj+15,'elektronika',25),(@obj+15,'zywnosc',90),
(@obj+16,'paliwo',120),(@obj+16,'elektronika',18),(@obj+17,'zywnosc',2200),(@obj+17,'paliwo',220),
(@obj+18,'zywnosc',1400),(@obj+18,'paliwo',110),(@obj+19,'zywnosc',900),(@obj+19,'paliwo',70),
(@obj+20,'nawozy',900),(@obj+20,'paliwo',40),(@obj+21,'nawozy',700),(@obj+21,'paliwo',35),
(@obj+22,'durastal',80),(@obj+22,'elektronika',18),(@obj+22,'paliwo',90),
(@obj+23,'durastal',120),(@obj+23,'elektronika',24),(@obj+23,'paliwo',100),
(@obj+24,'durastal',180),(@obj+24,'elektronika',30),(@obj+24,'paliwo',160),
(@obj+25,'elektronika',12),(@obj+25,'durastal',30),(@obj+26,'zywnosc',30),(@obj+26,'paliwo',15),
(@obj+27,'paliwo',75),(@obj+27,'elektronika',10),(@obj+28,'zywnosc',850),(@obj+28,'luksus',4),
(@obj+29,'paliwo',35),(@obj+29,'zywnosc',80),(@obj+30,'paliwo',130),(@obj+30,'durastal',70),
(@obj+31,'elektronika',25),(@obj+31,'durastal',100),(@obj+32,'paliwo',55),(@obj+32,'elektronika',8);

-- Dostawy do obiektów; popyt duży ma dwóch lub trzech dostawców, mały pojedynczego.
SET @lin := (SELECT COALESCE(MAX(id),0) FROM gosp_linie);
INSERT INTO gosp_linie (id,obiekt_id,towar,producent,t_doba,ladownosc_t,klasa,odstep_s,predkosc_au_h,postoj_h,faza,obieg)
SELECT @lin+ROW_NUMBER() OVER (ORDER BY q.obiekt_id,q.towar),q.obiekt_id,q.towar,q.producent,q.t_doba,
 GREATEST(500,IF(q.t_doba<q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek)),q.klasa,
 ROUND(GREATEST(500,IF(q.t_doba<q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek))/q.t_doba*86400),
 1.6,2.5,ROUND(MOD(q.obiekt_id*0.149+q.t_doba*0.0003,1),4),'oficjalny_hurt'
FROM (
 SELECT z.obiekt_id,z.towar,z.t_doba,
  CASE z.towar WHEN 'surowiec_paliwowy' THEN 'Cassander' WHEN 'paliwo' THEN 'Cassander'
   WHEN 'zywnosc' THEN 'Cassander' WHEN 'durastal' THEN 'Corellia' WHEN 'elektronika' THEN 'Alpheridies'
   WHEN 'nawozy' THEN 'Foundry' WHEN 'luksus' THEN 'Albrae-Don' END AS producent,
  t.ladownosc_t AS typowy_ladunek,t.klasa_transportu AS klasa
 FROM gosp_zuzycie z JOIN gosp_towary t ON t.kod=z.towar
 WHERE z.obiekt_id BETWEEN @obj+1 AND @obj+32
) q;

INSERT INTO lokalizacje (typ,rodzaj,rodzic_id,uklad_id,planeta_id,nazwa,x_km,y_km,publiczna,status,opis)
SELECT 'ladowisko','platforma',NULL,NULL,p.id,CONCAT('Lądowisko — ',o.nazwa),
 ROUND(o.dl/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),
 ROUND(o.szer/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),1,'aktywna',o.opis
FROM gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta LEFT JOIN planety_cykle c ON c.planeta_id=p.id
WHERE o.planeta='Cassander' AND o.miejsce='powierzchnia'
ON DUPLICATE KEY UPDATE x_km=VALUES(x_km),y_km=VALUES(y_km),publiczna=1,status='aktywna';
UPDATE gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta
JOIN lokalizacje l ON l.planeta_id=p.id AND l.nazwa=CONCAT('Lądowisko — ',o.nazwa)
SET o.lokalizacja_id=l.id WHERE o.planeta='Cassander' AND o.miejsce='powierzchnia';

-- Rynek Cassander liczony z produkcji i zużycia obiektów.
INSERT INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi)
SELECT 'Cassander',x.towar,'oficjalny_hurt',x.prod,x.kons,
 ROUND(LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),3),
 ROUND(x.cena*LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),2),
 'obiekty','wygenerowane','model rafinacji i zużycia; paliwo nie jest kanonicznym eksportem Cassander'
FROM (
 SELECT a.towar,a.prod,a.kons,t.cena_bazowa_kr_t AS cena,
  CASE WHEN a.prod>0 THEN 0 ELSE COALESCE((SELECT MIN(SQRT(POW(mp.x-pp.x,2)+POW(mp.y-pp.y,2)))
   FROM gosp_produkcja gp JOIN mapa_planety pp ON pp.nazwa=gp.planeta JOIN mapa_planety mp ON mp.nazwa='Cassander'
   WHERE gp.towar=a.towar AND gp.wydajnosc_t_doba>0),3000) END AS odl
 FROM (
  SELECT k.towar,COALESCE(p.wydajnosc_t_doba,0) AS prod,COALESCE(z.kons,0) AS kons
  FROM (SELECT towar FROM gosp_produkcja WHERE planeta='Cassander'
   UNION SELECT g.towar FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id WHERE o.planeta='Cassander') k
  LEFT JOIN gosp_produkcja p ON p.planeta='Cassander' AND p.towar=k.towar
  LEFT JOIN (SELECT g.towar,SUM(g.t_doba) AS kons FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id
   WHERE o.planeta='Cassander' GROUP BY g.towar) z ON z.towar=k.towar
 ) a JOIN gosp_towary t ON t.kod=a.towar
) x
ON DUPLICATE KEY UPDATE produkcja_t_doba=VALUES(produkcja_t_doba),zuzycie_t_doba=VALUES(zuzycie_t_doba),cena_indeks=VALUES(cena_indeks),cena_kr_t=VALUES(cena_kr_t),szczegolowosc=VALUES(szczegolowosc),zrodlo=VALUES(zrodlo),uwagi=VALUES(uwagi);

-- Pamięć o dostawcach drobnych importów; nie nadpisuj szczegółowych wpisów planet.
INSERT IGNORE INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi) VALUES
('Foundry','nawozy','oficjalny_hurt',80000,0,0.700,315.00,'szacunek','wygenerowane','otwarty wątek: nawozy do lokalnych upraw'),
('Corellia','durastal','oficjalny_hurt',420000,0,0.700,245.00,'szacunek','wygenerowane','otwarty wątek: części i blachy dla rafinerii'),
('Alpheridies','elektronika','oficjalny_hurt',12000,0,0.700,8400.00,'szacunek','wygenerowane','otwarty wątek: czujniki i układy sterowania'),
('Albrae-Don','luksus','oficjalny_hurt',240000,0,0.700,21000.00,'szacunek','wygenerowane','otwarty wątek: dostawy dla osiedli i załóg');

INSERT INTO schema_migracje (wersja,opis) VALUES ('v56-gospodarka-cassander','Gospodarka Cassander: modelowe rafinerie i węzeł Braxant Run')
ON DUPLICATE KEY UPDATE opis=VALUES(opis);

-- Kontrole po imporcie.
SELECT COUNT(*) AS obiekty_cassander FROM gosp_obiekty WHERE planeta='Cassander';
SELECT l.id,l.producent,l.towar FROM gosp_linie l LEFT JOIN gosp_produkcja p ON p.planeta=l.producent AND p.towar=l.towar WHERE l.obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Cassander') AND p.planeta IS NULL;
SELECT l.id FROM gosp_linie l LEFT JOIN gosp_obiekty o ON o.id=l.obiekt_id WHERE o.id IS NULL;
SELECT o.id,o.nazwa FROM gosp_obiekty o LEFT JOIN lokalizacje l ON l.id=o.lokalizacja_id WHERE o.planeta='Cassander' AND o.miejsce='powierzchnia' AND l.id IS NULL;
SELECT SUM(86400/odstep_s) AS dostawy_na_dobe FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Cassander');

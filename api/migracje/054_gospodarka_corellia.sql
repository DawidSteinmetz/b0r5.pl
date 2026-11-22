-- Corellia: przemysł, stocznie, maszyny rolnicze i zaopatrzenie Coronet.
-- Źródła kanoniczne: Coronet City, Coronet Spaceport i przemysł stoczniowy; epoka Imperium
-- przekształciła część stoczni w produkcję wojskową. Dane Legends rozszerzają rolę CEC i układu
-- stoczni. Wykorzystano istniejącą hierarchię lokacji z migracji 034 (bez tworzenia miast na nowo).
-- Wydajności, zużycia, współrzędne powierzchni i szczegóły poszczególnych zakładów są modelowe.
USE hololink;
SET NAMES utf8mb4;

-- Mapa ma już wartości 25 h / 329 dni / 11 000 km; dodaj cykle tylko wtedy, gdy brak wpisu.
INSERT INTO planety_cykle (planeta_id,doba_h,rok_dni,nachylenie_osi_deg,srednica_km)
SELECT id,doba,rok,22.0,NULLIF(srednica,0) FROM mapa_planety WHERE nazwa='Corellia'
ON DUPLICATE KEY UPDATE planeta_id=VALUES(planeta_id);
INSERT INTO planety_gry (planeta_id,typ_planety,urbanizacja,opis)
SELECT id,'miasto','wysoka','Gęsto zaludniony świat przemysłowy ze stoczniami, Coronet City i rozległym zapleczem produkcyjnym.'
FROM mapa_planety WHERE nazwa='Corellia'
ON DUPLICATE KEY UPDATE typ_planety=VALUES(typ_planety),urbanizacja=VALUES(urbanizacja),opis=VALUES(opis);

INSERT INTO gosp_towary (kod,nazwa,kategoria,ladownosc_t,klasa_transportu,zrodlo,cena_bazowa_kr_t) VALUES
('maszyny_rolnicze','maszyny i roboty rolnicze','przemysl',5000,'frachtowiec kontenerowy','wygenerowane',8500),
('czesci_maszyn','części maszynowe','przemysl',8000,'frachtowiec kontenerowy','wygenerowane',1600),
('komponenty_stoczniowe','komponenty i sekcje stoczniowe','przemysl',20000,'transportowiec Action IV','wygenerowane',6200)
ON DUPLICATE KEY UPDATE nazwa=VALUES(nazwa),ladownosc_t=VALUES(ladownosc_t),klasa_transportu=VALUES(klasa_transportu),cena_bazowa_kr_t=VALUES(cena_bazowa_kr_t);

-- Produkcja: duże serie maszyn i stoczniowych podzespołów; żywność obejmuje pasy rolne poza Coronet.
INSERT INTO gosp_produkcja (planeta,towar,wydajnosc_t_doba,zrodlo) VALUES
('Corellia','zywnosc',580000,'wygenerowane'),('Corellia','maszyny_rolnicze',110000,'wygenerowane'),
('Corellia','czesci_maszyn',180000,'wygenerowane'),('Corellia','durastal',420000,'wygenerowane'),
('Corellia','komponenty_stoczniowe',650000,'wygenerowane'),
('Mattri','zywnosc',1200000,'wygenerowane'),('Foundry','zywnosc',1400000,'wygenerowane'),
('Foundry','durastal',320000,'wookieepedia (złoża), wydajność wygenerowana'),
('Foundry','metale_rzadkie',175000,'wookieepedia (złoża), wydajność wygenerowana'),
('Foundry','podzespoly_tie',300000,'wygenerowane'),('Albrae-Don','luksus',240000,'wygenerowane'),
('Cassander','paliwo',343000,'wygenerowane'),('Alpheridies','elektronika',2035000,'wygenerowane'),
('Mygeeto','wegiel',2511000,'wygenerowane')
ON DUPLICATE KEY UPDATE wydajnosc_t_doba=VALUES(wydajnosc_t_doba),zrodlo=VALUES(zrodlo);

-- Odtwarzaj wyłącznie rekordy gospodarcze Corellii. Istniejące lokacje mapy pozostają nienaruszone.
DELETE FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Corellia');
DELETE FROM gosp_zuzycie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Corellia');
DELETE FROM gosp_obiekty WHERE planeta='Corellia';
SET @obj := (SELECT COALESCE(MAX(id),0) FROM gosp_obiekty);

INSERT INTO gosp_obiekty (id,planeta,nazwa,rodzaj,miejsce,szer,dl,kat_deg,opis) VALUES
(@obj+1,'Corellia','Gospodarstwo Równin Koronet R-01','farma','powierzchnia',13.2,18.4,NULL,'Modelowe gospodarstwo na obrzeżu głównego regionu miejskiego.'),
(@obj+2,'Corellia','Spółdzielnia Rolna Wschodnich Równin R-08','farma','powierzchnia',20.1,42.5,NULL,'Uprawy mieszane dla zaplecza żywnościowego Coronet.'),
(@obj+3,'Corellia','Farma Nadbrzeżna Południe R-12','farma','powierzchnia',-18.4,55.2,NULL,'Produkcja żywności w pasie południowego wybrzeża.'),
(@obj+4,'Corellia','Kompleks szklarniowy Lśniący Brzeg S-3','farma','powierzchnia',-7.1,72.8,NULL,'Uprawy pod osłonami i świeże warzywa dla Coronet.'),
(@obj+5,'Corellia','Farma Zachodnich Wzgórz R-21','farma','powierzchnia',31.0,-38.5,NULL,'Modelowa produkcja zbóż i pasz.'),
(@obj+6,'Corellia','Zakład Mączny Coronet M-2','przetwornia','powierzchnia',12.4,17.5,NULL,'Mielenie i pakowanie zbóż z okolicznych gospodarstw.'),
(@obj+7,'Corellia','Zakład Konserwacji Wybrzeża K-4','przetwornia','powierzchnia',-15.0,58.1,NULL,'Przetwory i racje dla załóg stoczniowych.'),
(@obj+8,'Corellia','Chłodnia Coronet C-1','chlodnia','powierzchnia',11.8,16.2,NULL,'Bufor żywności dla miasta i portu.'),
(@obj+9,'Corellia','Spichlerz Równin Północnych S-7','spichlerz','powierzchnia',26.5,-22.4,NULL,'Rezerwy zbożowe dla przemysłu i ludności.'),
(@obj+10,'Corellia','Coronet City — dystrybucja komunalna','zaklad','powierzchnia',10.2,14.1,NULL,'Zaopatrzenie istniejącego miasta; lokalizacja z migracji 034.'),
(@obj+11,'Corellia','Coronet Spaceport — obsługa cargo','port','powierzchnia',9.4,13.0,NULL,'Terminal cargo przy istniejącym Coronet Spaceport; lokalizacja z migracji 034.'),
(@obj+12,'Corellia','Terminal żywności Coronet F-4','terminal','powierzchnia',8.6,12.1,NULL,'Sortowanie, chłodzenie i odprawa dostaw do stolicy.'),
(@obj+13,'Corellia','Santhe Shipyards — montaż kadłubów','fabryka','powierzchnia',4.1,6.0,NULL,'Sekcje kadłubów i moduły okrętowe w istniejącym kompleksie stoczniowym.'),
(@obj+14,'Corellia','CEC — zakład komponentów napędowych','fabryka','powierzchnia',3.0,8.1,NULL,'Produkcja modułów napędowych; wykorzystuje istniejącą lokację CEC.'),
(@obj+15,'Corellia','CEC — precyzyjne części statkowe','fabryka','powierzchnia',2.4,9.0,NULL,'Obróbka części i zespołów dla cywilnych frachtowców.'),
(@obj+16,'Corellia','Zakład maszyn rolniczych R-CEC','fabryka','powierzchnia',18.0,34.2,NULL,'Produkcja kombajnów, pomp i robotów polowych dla Mattri i innych światów.'),
(@obj+17,'Corellia','Fabryka części maszynowych C-17','fabryka','powierzchnia',15.2,28.8,NULL,'Silniki, przekładnie i części zamienne.'),
(@obj+18,'Corellia','Huta durastali Coronet H-2','huta','powierzchnia',6.2,10.5,NULL,'Walcowanie blach i profili dla stoczni.'),
(@obj+19,'Corellia','Huta stopów Diktat H-5','huta','powierzchnia',22.3,45.5,NULL,'Stopy konstrukcyjne dla przemysłu ciężkiego.'),
(@obj+20,'Corellia','Park przemysłowy Wschód P-8','fabryka','powierzchnia',16.0,31.1,NULL,'Warsztaty poddostawców i montaż podzespołów.'),
(@obj+21,'Corellia','Port handlowy Coronet C-22','port','powierzchnia',8.2,11.4,NULL,'Składy i odprawa kontraktów hurtowych.'),
(@obj+22,'Corellia','Centrum zaopatrzenia Lower Coronet','sklad','powierzchnia',9.8,15.7,NULL,'Żywność, paliwo i części dla dzielnicy miejskiej.'),
(@obj+23,'Corellia','Zakład demontażu droidów Separatystów','fabryka','powierzchnia',12.0,19.1,NULL,'Istniejąca lokalizacja z migracji 034; odzysk metali i serwis urządzeń.'),
(@obj+24,'Corellia','Imperialny skład stoczniowy I-3','sklad','powierzchnia',1.1,4.7,NULL,'Magazyn materiałów dla zamówień wojskowych; szczegóły modelowe.'),
(@obj+25,'Corellia','Elektrownia przemysłowa Coronet E-5','zaklad','powierzchnia',19.4,38.2,NULL,'Zasilanie hut, zakładów i infrastruktury cargo.'),
(@obj+26,'Corellia','Terminal orbitalny Blastfield B-1','port','powierzchnia',5.7,7.6,NULL,'Lądowy węzeł obsługi orbitalnych stoczni i transportu.'),
(@obj+27,'Corellia','Dzielnica mieszkaniowa Coronet North','zaklad','powierzchnia',14.5,16.4,NULL,'Modelowa sieć zaopatrzenia dla mieszkańców i robotników.'),
(@obj+28,'Corellia','Dzielnica mieszkaniowa Coronet South','zaklad','powierzchnia',7.2,15.0,NULL,'Modelowa sieć zaopatrzenia dla mieszkańców.'),
(@obj+29,'Corellia','Osiedle robotnicze Santhe','zaklad','powierzchnia',3.5,5.1,NULL,'Zaplecze mieszkaniowe personelu stoczni.'),
(@obj+30,'Corellia','Skład paliwa Coronet P-9','sklad_paliwa','powierzchnia',17.3,32.5,NULL,'Zaopatrzenie maszyn, transportu i stoczni.'),
(@obj+31,'Corellia','Centrum serwisu droidów R-4','fabryka','powierzchnia',13.1,21.6,NULL,'Naprawa robotów przemysłowych, transportowych i rolniczych.'),
(@obj+32,'Corellia','Terminal części i maszyn M-11','terminal','powierzchnia',16.7,29.0,NULL,'Konsolidacja maszyn rolniczych i części na eksport, w tym do Mattri.');

INSERT INTO gosp_zuzycie (obiekt_id,towar,t_doba) VALUES
(@obj+1,'nawozy',5000),(@obj+1,'paliwo',120),(@obj+2,'nawozy',4200),(@obj+2,'paliwo',110),
(@obj+3,'nawozy',3500),(@obj+3,'paliwo',90),(@obj+4,'nawozy',3000),(@obj+4,'elektronika',8),
(@obj+5,'nawozy',2800),(@obj+5,'paliwo',85),(@obj+6,'paliwo',120),(@obj+6,'durastal',60),
(@obj+7,'paliwo',180),(@obj+7,'elektronika',10),(@obj+8,'paliwo',55),(@obj+8,'elektronika',12),
(@obj+9,'paliwo',70),(@obj+9,'elektronika',5),(@obj+10,'zywnosc',170000),(@obj+10,'paliwo',480),
(@obj+11,'zywnosc',18000),(@obj+11,'paliwo',300),(@obj+11,'elektronika',25),(@obj+12,'zywnosc',12000),
(@obj+13,'durastal',22000),(@obj+13,'elektronika',170),(@obj+13,'metale_rzadkie',9000),(@obj+13,'paliwo',450),
(@obj+14,'durastal',18000),(@obj+14,'elektronika',210),(@obj+14,'metale_rzadkie',6000),(@obj+14,'paliwo',390),
(@obj+15,'durastal',10000),(@obj+15,'elektronika',120),(@obj+15,'paliwo',220),
(@obj+16,'durastal',16000),(@obj+16,'elektronika',95),(@obj+16,'paliwo',180),
(@obj+17,'durastal',14000),(@obj+17,'elektronika',115),(@obj+17,'paliwo',210),
(@obj+18,'metale_rzadkie',26000),(@obj+18,'wegiel',8500),(@obj+18,'paliwo',620),
(@obj+19,'metale_rzadkie',18000),(@obj+19,'wegiel',6000),(@obj+19,'paliwo',480),
(@obj+20,'durastal',8000),(@obj+20,'elektronika',60),(@obj+21,'zywnosc',12000),(@obj+21,'paliwo',270),
(@obj+22,'zywnosc',26000),(@obj+22,'luksus',120),(@obj+22,'paliwo',55),
(@obj+23,'paliwo',320),(@obj+23,'elektronika',35),(@obj+23,'metale_rzadkie',4000),
(@obj+24,'durastal',4000),(@obj+24,'elektronika',14),(@obj+25,'paliwo',980),(@obj+25,'durastal',500),
(@obj+26,'paliwo',350),(@obj+26,'elektronika',30),(@obj+26,'zywnosc',8000),
(@obj+27,'zywnosc',150000),(@obj+27,'paliwo',220),(@obj+27,'luksus',80),
(@obj+28,'zywnosc',130000),(@obj+28,'paliwo',190),(@obj+28,'luksus',60),
(@obj+29,'zywnosc',70000),(@obj+29,'paliwo',90),(@obj+30,'elektronika',4),
(@obj+31,'durastal',2400),(@obj+31,'elektronika',55),(@obj+32,'paliwo',150),(@obj+32,'elektronika',12);

-- Dostawcy dla linii: duże zakłady mają 2–3 źródła. Żywność jest dzielona między produkcję
-- lokalną oraz nadwyżki Mattri i Foundry; pozostałe towary mają wskazanego producenta.
SET @lin := (SELECT COALESCE(MAX(id),0) FROM gosp_linie);
INSERT INTO gosp_linie (id,obiekt_id,towar,producent,t_doba,ladownosc_t,klasa,odstep_s,predkosc_au_h,postoj_h,faza,obieg)
SELECT @lin+ROW_NUMBER() OVER (ORDER BY q.obiekt_id,q.towar,q.producent),q.obiekt_id,q.towar,q.producent,q.t_doba,
 GREATEST(500,IF(q.t_doba<q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek)),q.klasa,
 ROUND(GREATEST(500,IF(q.t_doba<q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek))/q.t_doba*86400),
 1.7,2.5,ROUND(MOD(q.obiekt_id*0.131+q.t_doba*0.0009,1),4),'oficjalny_hurt'
FROM (
 SELECT z.obiekt_id,z.towar,
  CASE WHEN z.towar='zywnosc' AND z.t_doba<20000 THEN z.t_doba ELSE z.t_doba*s.udzial END AS t_doba,
  s.producent,t.ladownosc_t AS typowy_ladunek,t.klasa_transportu AS klasa
 FROM gosp_zuzycie z JOIN gosp_towary t ON t.kod=z.towar
 JOIN (
  SELECT 'zywnosc' AS towar,'Corellia' AS producent,0.89 AS udzial UNION ALL
  SELECT 'zywnosc','Mattri',0.07 UNION ALL SELECT 'zywnosc','Foundry',0.04
  UNION ALL SELECT 'nawozy','Foundry',1 UNION ALL SELECT 'paliwo','Cassander',1
  UNION ALL SELECT 'elektronika','Alpheridies',1 UNION ALL SELECT 'durastal','Corellia',1
  UNION ALL SELECT 'metale_rzadkie','Foundry',1 UNION ALL SELECT 'wegiel','Mygeeto',1
  UNION ALL SELECT 'luksus','Albrae-Don',1 UNION ALL SELECT 'maszyny_rolnicze','Corellia',1
  UNION ALL SELECT 'czesci_maszyn','Corellia',1 UNION ALL SELECT 'komponenty_stoczniowe','Corellia',1
 ) s ON s.towar=z.towar
  WHERE z.obiekt_id BETWEEN @obj+1 AND @obj+32
   AND (z.towar<>'zywnosc' OR z.t_doba>=20000 OR s.producent='Corellia')
) q;

-- Powierzchniowe lądowiska. Główne miasta i stocznie pozostają w istniejącej mapie lokacji 034.
INSERT INTO lokalizacje (typ,rodzaj,rodzic_id,uklad_id,planeta_id,nazwa,x_km,y_km,publiczna,status,opis)
SELECT 'ladowisko','platforma',NULL,NULL,p.id,CONCAT('Lądowisko — ',o.nazwa),
 ROUND(o.dl/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),
 ROUND(o.szer/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),1,'aktywna',o.opis
FROM gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta LEFT JOIN planety_cykle c ON c.planeta_id=p.id
WHERE o.planeta='Corellia' AND o.miejsce='powierzchnia'
ON DUPLICATE KEY UPDATE x_km=VALUES(x_km),y_km=VALUES(y_km),publiczna=1,status='aktywna';
UPDATE gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta
JOIN lokalizacje l ON l.planeta_id=p.id AND l.nazwa=CONCAT('Lądowisko — ',o.nazwa)
SET o.lokalizacja_id=l.id WHERE o.planeta='Corellia' AND o.miejsce='powierzchnia';

-- Rynek liczony z sum obiektów, produkcji i najbliższego producenta.
INSERT INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi)
SELECT 'Corellia',x.towar,'oficjalny_hurt',x.prod,x.kons,
 ROUND(LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),3),
 ROUND(x.cena*LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),2),
 'obiekty','wygenerowane','produkcja i zużycie modelowe; shipbuilding based on Corellian shipyard lore'
FROM (
 SELECT a.towar,a.prod,a.kons,t.cena_bazowa_kr_t AS cena,
  CASE WHEN a.prod>0 THEN 0 ELSE COALESCE((SELECT MIN(SQRT(POW(mp.x-pp.x,2)+POW(mp.y-pp.y,2)))
   FROM gosp_produkcja gp JOIN mapa_planety pp ON pp.nazwa=gp.planeta JOIN mapa_planety mp ON mp.nazwa='Corellia'
   WHERE gp.towar=a.towar AND gp.wydajnosc_t_doba>0),3000) END AS odl
 FROM (
  SELECT k.towar,COALESCE(p.wydajnosc_t_doba,0) AS prod,COALESCE(z.kons,0) AS kons
  FROM (SELECT towar FROM gosp_produkcja WHERE planeta='Corellia'
   UNION SELECT g.towar FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id WHERE o.planeta='Corellia') k
  LEFT JOIN gosp_produkcja p ON p.planeta='Corellia' AND p.towar=k.towar
  LEFT JOIN (SELECT g.towar,SUM(g.t_doba) AS kons FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id
   WHERE o.planeta='Corellia' GROUP BY g.towar) z ON z.towar=k.towar
 ) a JOIN gosp_towary t ON t.kod=a.towar
) x
ON DUPLICATE KEY UPDATE produkcja_t_doba=VALUES(produkcja_t_doba),zuzycie_t_doba=VALUES(zuzycie_t_doba),cena_indeks=VALUES(cena_indeks),cena_kr_t=VALUES(cena_kr_t),szczegolowosc=VALUES(szczegolowosc),zrodlo=VALUES(zrodlo),uwagi=VALUES(uwagi);

-- Pamięć sieci o dostawcach wykorzystywanych przez Corellię; INSERT IGNORE nie degraduje opisanych planet.
INSERT IGNORE INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi) VALUES
('Mattri','zywnosc','oficjalny_hurt',1200000,0,0.700,84.00,'szacunek','wygenerowane','otwarty wątek: dostawy żywności do Corellii'),
('Foundry','zywnosc','oficjalny_hurt',1400000,0,0.700,84.00,'szacunek','wygenerowane','otwarty wątek: uzupełniające dostawy żywności do Corellii'),
('Foundry','durastal','oficjalny_hurt',320000,0,0.700,245.00,'szacunek','wygenerowane','otwarty wątek: dostawy stopów i blach do stoczni'),
('Foundry','metale_rzadkie','oficjalny_hurt',175000,0,0.700,1750.00,'szacunek','wygenerowane','otwarty wątek: dostawy kammris i dolovite'),
('Foundry','podzespoly_tie','oficjalny_hurt',300000,0,0.700,3360.00,'szacunek','wygenerowane','otwarty wątek: komponenty imperialne dla łańcucha stoczniowego'),
('Cassander','paliwo','oficjalny_hurt',343000,0,0.700,420.00,'szacunek','wygenerowane','otwarty wątek: paliwo dla transportu i hut'),
('Alpheridies','elektronika','oficjalny_hurt',2035000,0,0.700,8400.00,'szacunek','wygenerowane','otwarty wątek: elektronika dla przemysłu'),
('Mygeeto','wegiel','oficjalny_hurt',2511000,0,0.700,21.00,'szacunek','wygenerowane','otwarty wątek: węgiel koksujący dla hut'),
('Albrae-Don','luksus','oficjalny_hurt',240000,0,0.700,21000.00,'szacunek','wygenerowane','otwarty wątek: towary luksusowe dla dzielnic mieszkalnych');

INSERT INTO schema_migracje (wersja,opis) VALUES ('v54-gospodarka-corellia','Gospodarka Corellii: stocznie, maszyny rolnicze i rynek')
ON DUPLICATE KEY UPDATE opis=VALUES(opis);

-- Kontrole po imporcie.
SELECT COUNT(*) AS obiekty_corellia FROM gosp_obiekty WHERE planeta='Corellia';
SELECT l.id,l.producent,l.towar FROM gosp_linie l LEFT JOIN gosp_produkcja p ON p.planeta=l.producent AND p.towar=l.towar WHERE l.obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Corellia') AND p.planeta IS NULL;
SELECT l.id FROM gosp_linie l LEFT JOIN gosp_obiekty o ON o.id=l.obiekt_id WHERE o.id IS NULL;
SELECT o.id,o.nazwa FROM gosp_obiekty o LEFT JOIN lokalizacje l ON l.id=o.lokalizacja_id WHERE o.planeta='Corellia' AND o.miejsce='powierzchnia' AND l.id IS NULL;
SELECT SUM(86400/odstep_s) AS dostawy_na_dobe FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Corellia');

-- Foundry: przemysłowy świat produkcji, z istniejącą gałęzią rolnictwa przemysłowego i eksportu żywności.
-- Wookieepedia (Legends): Foundry leży w Koloniach, ma przemysłowy teren i stolicę Denus; przed wojnami
-- klonów działała tu produkcja droidów Techno Union, a w epoce Imperium wytwarzano TIE/LN i prototypy
-- okrętów. Planeta jest bogata w hfredium, kammris i dolovite. Wielkości produkcji, lokalizacje obiektów,
-- współrzędne powierzchni i bieżące linie są szacunkami gry. Żywność zmniejszona z losowych 2,118,000
-- do 1,400,000 t/d; istniejące dostawy Kuat od Foundry (~813,000 t/d) pozostają bez zmian.
USE hololink;
SET NAMES utf8mb4;

INSERT INTO planety_cykle (planeta_id,doba_h,rok_dni,nachylenie_osi_deg,srednica_km)
SELECT id,23.7,361,21.0,11200 FROM mapa_planety WHERE nazwa='Foundry'
ON DUPLICATE KEY UPDATE doba_h=VALUES(doba_h),rok_dni=VALUES(rok_dni),nachylenie_osi_deg=VALUES(nachylenie_osi_deg),srednica_km=VALUES(srednica_km);
INSERT INTO planety_gry (planeta_id,typ_planety,urbanizacja,opis)
SELECT id,'miasto','wysoka','Przemysłowy świat z Denus jako centrum; złoża metali, kompleksy montażowe i modelowe strefy upraw pod osłonami.'
FROM mapa_planety WHERE nazwa='Foundry'
ON DUPLICATE KEY UPDATE typ_planety=VALUES(typ_planety),urbanizacja=VALUES(urbanizacja),opis=VALUES(opis);

INSERT INTO gosp_towary (kod,nazwa,kategoria,ladownosc_t,klasa_transportu,zrodlo,cena_bazowa_kr_t) VALUES
('nawozy','nawozy mineralne i regeneratory gleby','rolnictwo',12000,'frachtowiec kontenerowy','wygenerowane',450),
('podzespoly_tie','podzespoły i sekcje myśliwców TIE','przemysl',20000,'transportowiec Action IV', 'wygenerowane',4800)
ON DUPLICATE KEY UPDATE nazwa=VALUES(nazwa),ladownosc_t=VALUES(ladownosc_t),klasa_transportu=VALUES(klasa_transportu),cena_bazowa_kr_t=VALUES(cena_bazowa_kr_t);

-- Podaż modelowa. Złoża metali są oparte na źródłach Legends; wydajności pozostają szacunkami.
INSERT INTO gosp_produkcja (planeta,towar,wydajnosc_t_doba,zrodlo) VALUES
('Foundry','zywnosc',1400000,'wygenerowane'),('Foundry','nawozy',80000,'wygenerowane'),
('Foundry','metale_rzadkie',175000,'wookieepedia (złoża), wydajność wygenerowana'),
('Foundry','durastal',320000,'wookieepedia (złoża), wydajność wygenerowana'),
('Foundry','podzespoly_tie',300000,'wookieepedia (profil przemysłowy), wolumen wygenerowany'),
('Mattri','zywnosc',1200000,'wygenerowane'),('Cassander','paliwo',343000,'wygenerowane'),
('Alpheridies','elektronika',2035000,'wygenerowane'),('Mygeeto','wegiel',2511000,'wygenerowane')
ON DUPLICATE KEY UPDATE wydajnosc_t_doba=VALUES(wydajnosc_t_doba),zrodlo=VALUES(zrodlo);

DELETE FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Foundry');
DELETE FROM gosp_zuzycie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Foundry');
DELETE FROM gosp_obiekty WHERE planeta='Foundry';
SET @obj := (SELECT COALESCE(MAX(id),0) FROM gosp_obiekty);

INSERT INTO gosp_obiekty (id,planeta,nazwa,rodzaj,miejsce,szer,dl,kat_deg,opis) VALUES
(@obj+1,'Foundry','Agrokompleks Denus A-01','farma','powierzchnia',8.2,22.0,NULL,'Uprawy hydroponiczne i żywność dla miasta oraz kontraktów eksportowych.'),
(@obj+2,'Foundry','Agrokompleks Denus A-02','farma','powierzchnia',9.0,23.4,NULL,'Zautomatyzowane szklarnie w strefie przemysłowej.'),
(@obj+3,'Foundry','Farma pod osłonami N-11','farma','powierzchnia',17.1,35.2,NULL,'Modelowa strefa upraw intensywnych; szczegóły klimatu nieustalone.'),
(@obj+4,'Foundry','Farma pod osłonami N-12','farma','powierzchnia',18.0,36.1,NULL,'Warzywa, rośliny oleiste i pasze dla lokalnych zakładów.'),
(@obj+5,'Foundry','Ośrodek nasienny Denus S-3','farma','powierzchnia',7.4,20.6,NULL,'Materiał siewny i odmiany dostosowane do upraw osłoniętych.'),
(@obj+6,'Foundry','Kompleks hydroponiczny Północ H-7','farma','powierzchnia',25.3,-14.2,NULL,'Modułowa produkcja świeżej żywności.'),
(@obj+7,'Foundry','Kompleks hydroponiczny Południe H-8','farma','powierzchnia',-21.2,62.0,NULL,'Uprawy przemysłowe pod szkłem i w halach.'),
(@obj+8,'Foundry','Farma paszowa Kammris F-4','farma','powierzchnia',-12.1,43.8,NULL,'Pasze i surowce biologiczne dla miejskiego zaplecza.'),
(@obj+9,'Foundry','Zakład nawozów i reagentów N-4','fabryka','powierzchnia',10.1,26.0,NULL,'Nawozy dla własnych stref upraw; nadwyżka trafia do kontrahentów, w tym Mattri.'),
(@obj+10,'Foundry','Kompleks montażu TIE Denus T-01','fabryka','powierzchnia',5.0,18.2,NULL,'Montaż sekcji i podzespołów myśliwców w modelowej skali Imperialnej.'),
(@obj+11,'Foundry','Kompleks montażu TIE Denus T-02','fabryka','powierzchnia',5.8,19.5,NULL,'Linia montażu struktur i modułów napędowych.'),
(@obj+12,'Foundry','Zakład precyzyjnych droidów B-3','fabryka','powierzchnia',3.9,16.8,NULL,'Dawna tradycja produkcji droidów Techno Union; obecnie serwisowe i przemysłowe jednostki.'),
(@obj+13,'Foundry','Huta durastali Denus D-5','huta','powierzchnia',12.4,29.0,NULL,'Przetop i walcowanie stopów na potrzeby montowni.'),
(@obj+14,'Foundry','Huta stopów Kammris K-2','huta','powierzchnia',20.3,39.2,NULL,'Przerób metali ze złóż Foundry, w tym kammris i dolovite.'),
(@obj+15,'Foundry','Zakład wzbogacania rud H-6','zaklad','powierzchnia',22.2,40.1,NULL,'Sortowanie i wzbogacanie lokalnych rud metalicznych.'),
(@obj+16,'Foundry','Zakład ceraglassu i osłon C-7','fabryka','powierzchnia',14.2,31.4,NULL,'Komponenty osłon i przeszkleń dla przemysłu.'),
(@obj+17,'Foundry','Park przemysłowy Denus Zachód','fabryka','powierzchnia',2.8,14.4,NULL,'Warsztaty poddostawców i produkcja modułów.'),
(@obj+18,'Foundry','Park przemysłowy Denus Wschód','fabryka','powierzchnia',11.2,27.8,NULL,'Podzespoły, oprzyrządowanie i prace naprawcze.'),
(@obj+19,'Foundry','Terminal eksportowy Denus E-11','port','powierzchnia',6.4,21.0,NULL,'Główny terminal frachtu; obsługuje utrwalone linie żywności do Kuat.'),
(@obj+20,'Foundry','Port przemysłowy Kammris E-22','port','powierzchnia',21.8,41.3,NULL,'Załadunek metali, materiałów i podzespołów.'),
(@obj+21,'Foundry','Skład kontraktowy Denus S-1','sklad','powierzchnia',7.0,22.7,NULL,'Konsolidacja żywności i dostaw przemysłowych.'),
(@obj+22,'Foundry','Stolica Denus — sieć komunalna','zaklad','powierzchnia',8.5,24.8,NULL,'Zaopatrzenie stolicy; Denus jest kanonicznym miastem, rozmiar sieci modelowy.'),
(@obj+23,'Foundry','Osiedle robotników przemysłowych','zaklad','powierzchnia',6.2,17.4,NULL,'Modelowy sektor mieszkaniowy i usługowy.'),
(@obj+24,'Foundry','Elektrownia przemysłowa D-9','zaklad','powierzchnia',15.0,33.1,NULL,'Zasilanie hut, hal montażowych i systemów upraw.'),
(@obj+25,'Foundry','Skład paliwowy Denus P-3','sklad_paliwa','powierzchnia',4.7,15.8,NULL,'Paliwo dla transportu planetarnego i zakładów.'),
(@obj+26,'Foundry','Centrum serwisu droidów D-12','fabryka','powierzchnia',9.8,28.4,NULL,'Naprawa droidów przemysłowych i linii automatycznych.'),
(@obj+27,'Foundry','Chłodnia eksportowa Denus C-3','chlodnia','powierzchnia',7.7,23.1,NULL,'Bufor chłodniczy dla kontraktów spożywczych.'),
(@obj+28,'Foundry','Terminal frachtowy orbitalny V-1','terminal','powierzchnia',5.5,20.0,NULL,'Odprawa ładunków hurtowych i materiałów dla przemysłu.');

INSERT INTO gosp_zuzycie (obiekt_id,towar,t_doba) VALUES
(@obj+1,'nawozy',8500),(@obj+1,'paliwo',250),(@obj+1,'zywnosc',25000),
(@obj+2,'nawozy',7200),(@obj+2,'paliwo',220),(@obj+2,'zywnosc',22000),
(@obj+3,'nawozy',5100),(@obj+3,'paliwo',180),(@obj+3,'zywnosc',18000),
(@obj+4,'nawozy',4800),(@obj+4,'paliwo',170),(@obj+4,'zywnosc',17000),
(@obj+5,'nawozy',900),(@obj+5,'elektronika',8),(@obj+6,'nawozy',4300),(@obj+6,'paliwo',160),
(@obj+7,'nawozy',4200),(@obj+7,'paliwo',150),(@obj+8,'nawozy',3000),(@obj+8,'paliwo',110),
(@obj+9,'metale_rzadkie',5000),(@obj+9,'paliwo',300),(@obj+9,'durastal',200),
(@obj+10,'durastal',32000),(@obj+10,'elektronika',280),(@obj+10,'paliwo',900),
(@obj+11,'durastal',28000),(@obj+11,'elektronika',240),(@obj+11,'paliwo',850),
(@obj+12,'durastal',8000),(@obj+12,'elektronika',160),(@obj+12,'paliwo',420),
(@obj+13,'metale_rzadkie',52000),(@obj+13,'wegiel',18000),(@obj+13,'paliwo',1100),
(@obj+14,'metale_rzadkie',38000),(@obj+14,'wegiel',12000),(@obj+14,'paliwo',850),
(@obj+15,'paliwo',320),(@obj+15,'elektronika',18),(@obj+16,'durastal',6000),(@obj+16,'elektronika',90),
(@obj+17,'durastal',12000),(@obj+17,'elektronika',85),(@obj+18,'durastal',14000),(@obj+18,'elektronika',110),
(@obj+19,'paliwo',700),(@obj+19,'elektronika',38),(@obj+19,'zywnosc',24000),
(@obj+20,'paliwo',550),(@obj+20,'elektronika',26),(@obj+20,'zywnosc',9000),
(@obj+21,'paliwo',180),(@obj+21,'elektronika',14),(@obj+21,'zywnosc',18000),
(@obj+22,'zywnosc',235000),(@obj+22,'paliwo',850),(@obj+23,'zywnosc',82000),(@obj+23,'paliwo',260),
(@obj+24,'paliwo',1750),(@obj+24,'durastal',900),(@obj+25,'elektronika',5),
(@obj+26,'durastal',2400),(@obj+26,'elektronika',75),(@obj+27,'paliwo',230),(@obj+27,'elektronika',12),
(@obj+27,'zywnosc',42000),(@obj+28,'paliwo',400),(@obj+28,'elektronika',22),(@obj+28,'zywnosc',12000);

-- Dostawy do każdej pozycji zużycia. Producent lokalny oznacza dostawę wewnątrz systemu.
SET @lin := (SELECT COALESCE(MAX(id),0) FROM gosp_linie);
INSERT INTO gosp_linie (id,obiekt_id,towar,producent,t_doba,ladownosc_t,klasa,odstep_s,predkosc_au_h,postoj_h,faza,obieg)
SELECT @lin+ROW_NUMBER() OVER (ORDER BY q.obiekt_id,q.towar),q.obiekt_id,q.towar,q.producent,q.t_doba,
 GREATEST(500,IF(q.t_doba<q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek)),q.klasa,
 ROUND(GREATEST(500,IF(q.t_doba<q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek))/q.t_doba*86400),
 1.7,2.4,ROUND(MOD(q.obiekt_id*0.113+q.t_doba*0.0007,1),4),'oficjalny_hurt'
FROM (
 SELECT z.obiekt_id,z.towar,z.t_doba,
  CASE z.towar WHEN 'nawozy' THEN 'Foundry' WHEN 'metale_rzadkie' THEN 'Foundry' WHEN 'durastal' THEN 'Foundry'
   WHEN 'podzespoly_tie' THEN 'Foundry' WHEN 'zywnosc' THEN 'Foundry' WHEN 'elektronika' THEN 'Alpheridies'
   WHEN 'paliwo' THEN 'Cassander' WHEN 'wegiel' THEN 'Mygeeto' END AS producent,
  t.ladownosc_t AS typowy_ladunek,t.klasa_transportu AS klasa
 FROM gosp_zuzycie z JOIN gosp_towary t ON t.kod=z.towar
 WHERE z.obiekt_id BETWEEN @obj+1 AND @obj+28
) q;

INSERT INTO lokalizacje (typ,rodzaj,rodzic_id,uklad_id,planeta_id,nazwa,x_km,y_km,publiczna,status,opis)
SELECT 'ladowisko','platforma',NULL,NULL,p.id,CONCAT('Lądowisko — ',o.nazwa),
 ROUND(o.dl/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),
 ROUND(o.szer/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),1,'aktywna',o.opis
FROM gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta LEFT JOIN planety_cykle c ON c.planeta_id=p.id
WHERE o.planeta='Foundry' AND o.miejsce='powierzchnia'
ON DUPLICATE KEY UPDATE x_km=VALUES(x_km),y_km=VALUES(y_km),publiczna=1,status='aktywna';
UPDATE gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta
JOIN lokalizacje l ON l.planeta_id=p.id AND l.nazwa=CONCAT('Lądowisko — ',o.nazwa)
SET o.lokalizacja_id=l.id WHERE o.planeta='Foundry' AND o.miejsce='powierzchnia';

-- Tabela rynku Foundry jest dokładną sumą produkcji i zużycia obiektów; najbliższy producent wyznacza transport.
INSERT INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi)
SELECT 'Foundry',x.towar,'oficjalny_hurt',x.prod,x.kons,
 ROUND(LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),3),
 ROUND(x.cena*LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),2),
 'obiekty','wygenerowane','sumy z modelowych obiektów; zasoby mineralne inspirowane Legends'
FROM (
 SELECT a.towar,a.prod,a.kons,t.cena_bazowa_kr_t AS cena,
  CASE WHEN a.prod>0 THEN 0 ELSE COALESCE((SELECT MIN(SQRT(POW(mp.x-pp.x,2)+POW(mp.y-pp.y,2)))
   FROM gosp_produkcja gp JOIN mapa_planety pp ON pp.nazwa=gp.planeta JOIN mapa_planety mp ON mp.nazwa='Foundry'
   WHERE gp.towar=a.towar AND gp.wydajnosc_t_doba>0),3000) END AS odl
 FROM (
  SELECT k.towar,COALESCE(p.wydajnosc_t_doba,0) AS prod,COALESCE(z.kons,0) AS kons
  FROM (SELECT towar FROM gosp_produkcja WHERE planeta='Foundry'
   UNION SELECT g.towar FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id WHERE o.planeta='Foundry') k
  LEFT JOIN gosp_produkcja p ON p.planeta='Foundry' AND p.towar=k.towar
  LEFT JOIN (SELECT g.towar,SUM(g.t_doba) AS kons FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id
   WHERE o.planeta='Foundry' GROUP BY g.towar) z ON z.towar=k.towar
 ) a JOIN gosp_towary t ON t.kod=a.towar
) x
ON DUPLICATE KEY UPDATE produkcja_t_doba=VALUES(produkcja_t_doba),zuzycie_t_doba=VALUES(zuzycie_t_doba),cena_indeks=VALUES(cena_indeks),cena_kr_t=VALUES(cena_kr_t),szczegolowosc=VALUES(szczegolowosc),zrodlo=VALUES(zrodlo),uwagi=VALUES(uwagi);

-- Otwarte wątki podaży do Foundry; nie obniżaj wcześniej szczegółowo opisanych planet do szacunków.
INSERT IGNORE INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi) VALUES
('Cassander','paliwo','oficjalny_hurt',343000,0,0.700,420.00,'szacunek','wygenerowane','otwarty wątek: kontrakty paliwowe Foundry'),
('Alpheridies','elektronika','oficjalny_hurt',2035000,0,0.700,8400.00,'szacunek','wygenerowane','otwarty wątek: elektronika dla hal montażowych'),
('Mygeeto','wegiel','oficjalny_hurt',2511000,0,0.700,21.00,'szacunek','wygenerowane','otwarty wątek: paliwo stałe dla hut Foundry');

INSERT INTO schema_migracje (wersja,opis) VALUES ('v53-gospodarka-foundry','Gospodarka Foundry: przemysł, żywność i komponenty TIE')
ON DUPLICATE KEY UPDATE opis=VALUES(opis);

-- Kontrole po imporcie.
SELECT COUNT(*) AS obiekty_foundry FROM gosp_obiekty WHERE planeta='Foundry';
SELECT l.id,l.producent,l.towar FROM gosp_linie l LEFT JOIN gosp_produkcja p ON p.planeta=l.producent AND p.towar=l.towar WHERE l.obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Foundry') AND p.planeta IS NULL;
SELECT l.id FROM gosp_linie l LEFT JOIN gosp_obiekty o ON o.id=l.obiekt_id WHERE o.id IS NULL;
SELECT o.id,o.nazwa FROM gosp_obiekty o LEFT JOIN lokalizacje l ON l.id=o.lokalizacja_id WHERE o.planeta='Foundry' AND o.miejsce='powierzchnia' AND l.id IS NULL;
SELECT SUM(86400/odstep_s) AS dostawy_na_dobe FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Foundry');

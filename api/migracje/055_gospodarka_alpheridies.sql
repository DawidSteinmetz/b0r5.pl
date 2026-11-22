-- Alpheridies: małe osady, centrum lokalne i rzemiosło czujników podczerwieni.
-- Legends: przy czerwonym karle Aber Miraluka rozwinęli percepcję niezależną od światła widzialnego;
-- występują góry i równiny, kilka niewielkich osiedli, a Wookieepedia nie wymienia głównych importów
-- ani eksportów. Z tego powodu elektronika eksportowa HOLOLINK jest małą, całkowicie modelową niszą
-- specjalistycznych przyrządów IR, nie twierdzeniem o kanonicznej gałęzi przemysłu. Historyczna
-- ludność 1 mln dotyczy Wielkiej Wojny Sithów (ok. 4000 BBY), nie jest przenoszona na 3 BBY.
-- Uwaga mapowa: mapa_seed.sql ma region=1, natomiast Legends lokuje Alpheridies w Expansion Region;
-- ta migracja nie zmienia istniejącego rekordu mapy.
-- Produkcję elektroniki z losowych 2,035,000 korygujemy do 12,000 t/d; istniejące linie odbiorców
-- (Kuat i nowe planety sieci) pozostają nietknięte.
USE hololink;
SET NAMES utf8mb4;

-- Mapa ma dobę 21 h i rok 450 dni, ale średnicy brak. Cykle są jawną estymacją gry.
INSERT INTO planety_cykle (planeta_id,doba_h,rok_dni,nachylenie_osi_deg,srednica_km,gwiazda_typ)
SELECT id,21,450,11.0,9400,'M' FROM mapa_planety WHERE nazwa='Alpheridies'
ON DUPLICATE KEY UPDATE doba_h=VALUES(doba_h),rok_dni=VALUES(rok_dni),nachylenie_osi_deg=VALUES(nachylenie_osi_deg),srednica_km=VALUES(srednica_km),gwiazda_typ=VALUES(gwiazda_typ);
INSERT INTO planety_gry (planeta_id,typ_planety,urbanizacja,opis)
SELECT id,'skalista','niska','Góry, równiny i niewielkie osiedla; model HOLOLINK zakłada lokalne warsztaty przyrządów podczerwieni.'
FROM mapa_planety WHERE nazwa='Alpheridies'
ON DUPLICATE KEY UPDATE typ_planety=VALUES(typ_planety),urbanizacja=VALUES(urbanizacja),opis=VALUES(opis);

-- Urealnij losową podaż globalną; nie zmieniaj historycznych linii do odbiorców.
INSERT INTO gosp_produkcja (planeta,towar,wydajnosc_t_doba,zrodlo) VALUES
('Alpheridies','elektronika',12000,'wygenerowane'),('Alpheridies','zywnosc',8000,'wygenerowane'),
('Corellia','durastal',420000,'wygenerowane'),('Cassander','paliwo',343000,'wygenerowane'),
('Albrae-Don','luksus',240000,'wygenerowane')
ON DUPLICATE KEY UPDATE wydajnosc_t_doba=VALUES(wydajnosc_t_doba),zrodlo=VALUES(zrodlo);

DELETE FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Alpheridies');
DELETE FROM gosp_zuzycie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Alpheridies');
DELETE FROM gosp_obiekty WHERE planeta='Alpheridies';
SET @obj := (SELECT COALESCE(MAX(id),0) FROM gosp_obiekty);

INSERT INTO gosp_obiekty (id,planeta,nazwa,rodzaj,miejsce,szer,dl,kat_deg,opis) VALUES
(@obj+1,'Alpheridies','Culu Memorial Center','zaklad','powierzchnia',4.2,18.0,NULL,'Miejsce pamięci wymienione w Legends; obsługa lokalna i archiwum.'),
(@obj+2,'Alpheridies','Osada Równin Abron A-1','zaklad','powierzchnia',8.0,24.5,NULL,'Jedna z modelowych, niewielkich osad na równinach.'),
(@obj+3,'Alpheridies','Osada Górska Vey N-2','zaklad','powierzchnia',22.5,-31.2,NULL,'Mała osada u podnóża gór; nazwa i położenie modelowe.'),
(@obj+4,'Alpheridies','Osada Doliny Sere M-3','zaklad','powierzchnia',-13.8,75.1,NULL,'Rozproszona wspólnota mieszkaniowa i usługowa.'),
(@obj+5,'Alpheridies','Ogród wspólnotowy Abron G-1','farma','powierzchnia',6.8,21.3,NULL,'Lokalna produkcja warzyw i roślin jadalnych.'),
(@obj+6,'Alpheridies','Uprawy tarasowe Vey G-2','farma','powierzchnia',20.1,-29.8,NULL,'Niewielkie tarasy uprawne na łagodnych stokach.'),
(@obj+7,'Alpheridies','Szklarnie osady Sere G-4','farma','powierzchnia',-12.4,73.0,NULL,'Uprawy pod osłonami dla osady i warsztatów.'),
(@obj+8,'Alpheridies','Warsztat optyki IR Abron O-1','fabryka','powierzchnia',5.4,19.2,NULL,'Modelowe przyrządy i filtry dostosowane do widma podczerwonego.'),
(@obj+9,'Alpheridies','Laboratorium kalibracji Vey L-2','zaklad','powierzchnia',19.3,-28.1,NULL,'Kalibracja czujników i narzędzi pomiarowych.'),
(@obj+10,'Alpheridies','Pracownia sensorów Sere S-5','fabryka','powierzchnia',-10.8,70.7,NULL,'Małoseryjne moduły sensorów; wielkość produkcji modelowa.'),
(@obj+11,'Alpheridies','Montażownia elektroniki Abron E-4','fabryka','powierzchnia',7.2,22.6,NULL,'Końcowy montaż podzespołów dla łańcuchów dostaw HOLOLINK.'),
(@obj+12,'Alpheridies','Pracownia filtrów widma Aber F-1','fabryka','powierzchnia',10.1,28.0,NULL,'Filtry i osłony optyczne; nawiązanie do czerwonego karła Aber.'),
(@obj+13,'Alpheridies','Centrum badań percepcji C-3','zaklad','powierzchnia',3.6,16.8,NULL,'Badania i edukacja; nie przypisuje mieszkańcom nowych mocy ani instytucji kanonicznych.'),
(@obj+14,'Alpheridies','Stacja pomiarowa Aber P-7','zaklad','powierzchnia',14.8,36.2,NULL,'Rejestracja widma i warunków lokalnych.'),
(@obj+15,'Alpheridies','Skład kontraktowy Abron S-1','sklad','powierzchnia',6.0,20.4,NULL,'Niewielkie partie elektroniki i dostaw dla osad.'),
(@obj+16,'Alpheridies','Punkt przeładunkowy Abron H-2','port','powierzchnia',5.2,17.2,NULL,'Modelowe lądowisko dla nielicznych, rejestrowanych frachtów.'),
(@obj+17,'Alpheridies','Terminal kontraktowy Vey H-4','terminal','powierzchnia',18.2,-26.5,NULL,'Odprawa małych partii wysyłanych do odbiorców sieci.'),
(@obj+18,'Alpheridies','Przychodnia osady Abron M-1','zaklad','powierzchnia',7.4,23.0,NULL,'Zaopatrzenie zdrowotne mieszkańców.'),
(@obj+19,'Alpheridies','Dom zgromadzeń i nauki R-4','zaklad','powierzchnia',-14.5,77.0,NULL,'Centrum lokalnej wspólnoty i edukacji; obiekt modelowy.'),
(@obj+20,'Alpheridies','Elektrownia osady Aber E-9','zaklad','powierzchnia',12.8,33.7,NULL,'Lokalne źródło energii i zaplecze techniczne.'),
(@obj+21,'Alpheridies','Warsztat napraw precyzyjnych W-3','fabryka','powierzchnia',8.7,26.1,NULL,'Naprawa narzędzi pomiarowych i urządzeń elektronicznych.'),
(@obj+22,'Alpheridies','Spichlerz wspólnotowy Abron S-7','spichlerz','powierzchnia',9.1,27.3,NULL,'Rezerwy żywności na potrzeby pobliskich osad.'),
(@obj+23,'Alpheridies','Targ osadniczy Abron T-2','sklad','powierzchnia',5.8,18.9,NULL,'Lokalna wymiana żywności, narzędzi i wyrobów rzemieślniczych.'),
(@obj+24,'Alpheridies','Skład paliwa stacji pomiarowej P-1','sklad_paliwa','powierzchnia',15.1,38.0,NULL,'Niewielki zapas paliwa technicznego.'),
(@obj+25,'Alpheridies','Punkt obsługi frachtu i załóg A-6','port','powierzchnia',4.7,16.0,NULL,'Podstawowa obsługa rzadkich lotów kontraktowych.'),
(@obj+26,'Alpheridies','Magazyn części i instrumentów I-2','sklad','powierzchnia',7.9,24.0,NULL,'Bufor części Corelliańskich i lokalnych przyrządów.');

INSERT INTO gosp_zuzycie (obiekt_id,towar,t_doba) VALUES
(@obj+1,'zywnosc',25),(@obj+1,'elektronika',2),(@obj+2,'zywnosc',2600),(@obj+2,'paliwo',18),
(@obj+3,'zywnosc',2200),(@obj+3,'paliwo',16),(@obj+4,'zywnosc',1900),(@obj+4,'paliwo',14),
(@obj+5,'paliwo',8),(@obj+6,'paliwo',12),(@obj+7,'paliwo',10),
(@obj+8,'durastal',18),(@obj+8,'elektronika',45),(@obj+8,'zywnosc',8),
(@obj+9,'elektronika',22),(@obj+9,'durastal',6),(@obj+10,'elektronika',85),(@obj+10,'durastal',14),
(@obj+11,'elektronika',120),(@obj+11,'durastal',20),(@obj+11,'zywnosc',12),
(@obj+12,'elektronika',55),(@obj+12,'durastal',8),(@obj+12,'paliwo',8),
(@obj+13,'zywnosc',15),(@obj+13,'elektronika',8),(@obj+14,'paliwo',22),(@obj+14,'elektronika',12),
(@obj+15,'zywnosc',18),(@obj+15,'elektronika',4),(@obj+16,'paliwo',65),(@obj+16,'elektronika',5),
(@obj+17,'paliwo',40),(@obj+17,'elektronika',4),(@obj+18,'zywnosc',10),(@obj+19,'zywnosc',12),
(@obj+20,'paliwo',120),(@obj+20,'durastal',4),(@obj+21,'elektronika',32),(@obj+21,'durastal',10),
(@obj+22,'zywnosc',20),(@obj+23,'zywnosc',35),(@obj+23,'luksus',2),
(@obj+24,'elektronika',2),(@obj+25,'paliwo',32),(@obj+25,'zywnosc',8),
(@obj+26,'durastal',8),(@obj+26,'elektronika',6),(@obj+26,'luksus',1);

-- Linie na każdą pozycję zużycia. Dla małego popytu wybrano jednego producenta.
SET @lin := (SELECT COALESCE(MAX(id),0) FROM gosp_linie);
INSERT INTO gosp_linie (id,obiekt_id,towar,producent,t_doba,ladownosc_t,klasa,odstep_s,predkosc_au_h,postoj_h,faza,obieg)
SELECT @lin+ROW_NUMBER() OVER (ORDER BY q.obiekt_id,q.towar),q.obiekt_id,q.towar,q.producent,q.t_doba,
 GREATEST(500,IF(q.t_doba<q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek)),q.klasa,
 ROUND(GREATEST(500,IF(q.t_doba<q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek))/q.t_doba*86400),
 1.4,2.4,ROUND(MOD(q.obiekt_id*0.157+q.t_doba*0.004,1),4),'oficjalny_hurt'
FROM (
 SELECT z.obiekt_id,z.towar,z.t_doba,
  CASE z.towar WHEN 'zywnosc' THEN 'Alpheridies' WHEN 'elektronika' THEN 'Alpheridies'
   WHEN 'durastal' THEN 'Corellia' WHEN 'paliwo' THEN 'Cassander' WHEN 'luksus' THEN 'Albrae-Don' END AS producent,
  t.ladownosc_t AS typowy_ladunek,t.klasa_transportu AS klasa
 FROM gosp_zuzycie z JOIN gosp_towary t ON t.kod=z.towar
 WHERE z.obiekt_id BETWEEN @obj+1 AND @obj+26
) q;

INSERT INTO lokalizacje (typ,rodzaj,rodzic_id,uklad_id,planeta_id,nazwa,x_km,y_km,publiczna,status,opis)
SELECT 'ladowisko','platforma',NULL,NULL,p.id,CONCAT('Lądowisko — ',o.nazwa),
 ROUND(o.dl/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),
 ROUND(o.szer/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),1,'aktywna',o.opis
FROM gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta LEFT JOIN planety_cykle c ON c.planeta_id=p.id
WHERE o.planeta='Alpheridies' AND o.miejsce='powierzchnia'
ON DUPLICATE KEY UPDATE x_km=VALUES(x_km),y_km=VALUES(y_km),publiczna=1,status='aktywna';
UPDATE gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta
JOIN lokalizacje l ON l.planeta_id=p.id AND l.nazwa=CONCAT('Lądowisko — ',o.nazwa)
SET o.lokalizacja_id=l.id WHERE o.planeta='Alpheridies' AND o.miejsce='powierzchnia';

-- Rynek szczegółowy według sum obiektów i dystansu do najbliższego producenta.
INSERT INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi)
SELECT 'Alpheridies',x.towar,'oficjalny_hurt',x.prod,x.kons,
 ROUND(LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),3),
 ROUND(x.cena*LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),2),
 'obiekty','wygenerowane','lokalne obiekty; eksport przyrządów IR jest modelem gry, nie kanonicznym eksportem'
FROM (
 SELECT a.towar,a.prod,a.kons,t.cena_bazowa_kr_t AS cena,
  CASE WHEN a.prod>0 THEN 0 ELSE COALESCE((SELECT MIN(SQRT(POW(mp.x-pp.x,2)+POW(mp.y-pp.y,2)))
   FROM gosp_produkcja gp JOIN mapa_planety pp ON pp.nazwa=gp.planeta JOIN mapa_planety mp ON mp.nazwa='Alpheridies'
   WHERE gp.towar=a.towar AND gp.wydajnosc_t_doba>0),3000) END AS odl
 FROM (
  SELECT k.towar,COALESCE(p.wydajnosc_t_doba,0) AS prod,COALESCE(z.kons,0) AS kons
  FROM (SELECT towar FROM gosp_produkcja WHERE planeta='Alpheridies'
   UNION SELECT g.towar FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id WHERE o.planeta='Alpheridies') k
  LEFT JOIN gosp_produkcja p ON p.planeta='Alpheridies' AND p.towar=k.towar
  LEFT JOIN (SELECT g.towar,SUM(g.t_doba) AS kons FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id
   WHERE o.planeta='Alpheridies' GROUP BY g.towar) z ON z.towar=k.towar
 ) a JOIN gosp_towary t ON t.kod=a.towar
) x
ON DUPLICATE KEY UPDATE produkcja_t_doba=VALUES(produkcja_t_doba),zuzycie_t_doba=VALUES(zuzycie_t_doba),cena_indeks=VALUES(cena_indeks),cena_kr_t=VALUES(cena_kr_t),szczegolowosc=VALUES(szczegolowosc),zrodlo=VALUES(zrodlo),uwagi=VALUES(uwagi);

-- Zewnętrzni dostawcy dla niewielkiego importu lokalnego.
INSERT IGNORE INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi) VALUES
('Corellia','durastal','oficjalny_hurt',420000,0,0.700,245.00,'szacunek','wygenerowane','otwarty wątek: dostawy stopów do warsztatów Alpheridies'),
('Cassander','paliwo','oficjalny_hurt',343000,0,0.700,420.00,'szacunek','wygenerowane','otwarty wątek: paliwo techniczne dla osad'),
('Albrae-Don','luksus','oficjalny_hurt',240000,0,0.700,21000.00,'szacunek','wygenerowane','otwarty wątek: drobne dostawy towarów luksusowych');

INSERT INTO schema_migracje (wersja,opis) VALUES ('v55-gospodarka-alpheridies','Gospodarka Alpheridies: osady i elektronika podczerwieni')
ON DUPLICATE KEY UPDATE opis=VALUES(opis);

-- Kontrole po imporcie.
SELECT COUNT(*) AS obiekty_alpheridies FROM gosp_obiekty WHERE planeta='Alpheridies';
SELECT l.id,l.producent,l.towar FROM gosp_linie l LEFT JOIN gosp_produkcja p ON p.planeta=l.producent AND p.towar=l.towar WHERE l.obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Alpheridies') AND p.planeta IS NULL;
SELECT l.id FROM gosp_linie l LEFT JOIN gosp_obiekty o ON o.id=l.obiekt_id WHERE o.id IS NULL;
SELECT o.id,o.nazwa FROM gosp_obiekty o LEFT JOIN lokalizacje l ON l.id=o.lokalizacja_id WHERE o.planeta='Alpheridies' AND o.miejsce='powierzchnia' AND l.id IS NULL;
SELECT SUM(86400/odstep_s) AS dostawy_na_dobe FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Alpheridies');

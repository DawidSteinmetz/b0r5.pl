-- Mygeeto: lodowy, krystaliczny świat wydobywczy i węzeł dawnych interesów Banking Clan.
-- Kanon: zimny, zurbanizowany świat i miejsce bitwy o Mygeeto; Legends: bogate złoża kryształów,
-- kopalnie i związki z InterGalactic Banking Clan. Wydobycie nova crystal jest oparte na Legends.
-- Węgiel koksujący nie jest potwierdzonym zasobem planety: pozostaje modelowym paliwem stałym HOLOLINK,
-- aby zasilić istniejące linie do hut Foundry i Corellii (łącznie ok. 44 500 t/d). Wolumeny i obiekty są szacunkami.
USE hololink;
SET NAMES utf8mb4;

INSERT INTO planety_cykle (planeta_id,doba_h,rok_dni,nachylenie_osi_deg,srednica_km)
SELECT id,18.0,312,19.0,11000 FROM mapa_planety WHERE nazwa='Mygeeto'
ON DUPLICATE KEY UPDATE doba_h=VALUES(doba_h),rok_dni=VALUES(rok_dni),nachylenie_osi_deg=VALUES(nachylenie_osi_deg),srednica_km=VALUES(srednica_km);
INSERT INTO planety_gry (planeta_id,typ_planety,urbanizacja,opis)
SELECT id,'lodowa','wysoka','Zimny, zurbanizowany świat kryształów. Kopalnie, zakłady sortowania i infrastruktura handlowa są modelowym rozwinięciem znanych złóż i historii Banking Clan.'
FROM mapa_planety WHERE nazwa='Mygeeto'
ON DUPLICATE KEY UPDATE typ_planety=VALUES(typ_planety),urbanizacja=VALUES(urbanizacja),opis=VALUES(opis);

INSERT INTO gosp_towary (kod,nazwa,kategoria,ladownosc_t,klasa_transportu,zrodlo,cena_bazowa_kr_t) VALUES
('krysztal_nova','kryształy nova','surowiec',5000,'transportowiec Action IV','wookieepedia (Legends)',18000)
ON DUPLICATE KEY UPDATE nazwa=VALUES(nazwa),kategoria=VALUES(kategoria),ladownosc_t=VALUES(ladownosc_t),klasa_transportu=VALUES(klasa_transportu),zrodlo=VALUES(zrodlo),cena_bazowa_kr_t=VALUES(cena_bazowa_kr_t);

-- Węgiel to wcześniejszy szacunek generatora, skorygowany do skali faktycznie opisanych odbiorców.
INSERT INTO gosp_produkcja (planeta,towar,wydajnosc_t_doba,zrodlo) VALUES
('Mygeeto','wegiel',62000,'wygenerowane; modelowe paliwo stałe, niepotwierdzone w źródłach'),
('Mygeeto','krysztal_nova',18000,'wookieepedia (Legends); wydajność wygenerowana'),
('Mygeeto','metale_rzadkie',9000,'wygenerowane; odzysk z urobku krystalicznego'),
('Mygeeto','zywnosc',68000,'wygenerowane; szklarnie i hydroponika')
ON DUPLICATE KEY UPDATE wydajnosc_t_doba=VALUES(wydajnosc_t_doba),zrodlo=VALUES(zrodlo);

DELETE FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Mygeeto');
DELETE FROM gosp_zuzycie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Mygeeto');
DELETE FROM gosp_obiekty WHERE planeta='Mygeeto';
SET @obj := (SELECT COALESCE(MAX(id),0) FROM gosp_obiekty);

INSERT INTO gosp_obiekty (id,planeta,nazwa,rodzaj,miejsce,szer,dl,kat_deg,opis) VALUES
(@obj+1,'Mygeeto','Kopalnia Kryształowej Korony K-01','kopalnia','powierzchnia',48.1,32.4,NULL,'Modelowy odkrywowy kompleks wydobycia kryształów nova.'),
(@obj+2,'Mygeeto','Kopalnia Kryształowej Korony K-02','kopalnia','powierzchnia',50.3,35.8,NULL,'Eksploatacja odsłoniętych żył krystalicznych.'),
(@obj+3,'Mygeeto','Szyb Głębokiego Lodu K-11','kopalnia','powierzchnia',53.0,41.2,NULL,'Podziemne komory i zabezpieczone szyby; lokalizacja modelowa.'),
(@obj+4,'Mygeeto','Kopalnia Błękitnych Iglic K-14','kopalnia','powierzchnia',45.8,27.6,NULL,'Selektywne wydobycie kryształów wysokiej czystości.'),
(@obj+5,'Mygeeto','Kopalnia Pęknięcia Marid K-18','kopalnia','powierzchnia',56.2,48.9,NULL,'Wydobycie w strefie szczelin lodowcowych.'),
(@obj+6,'Mygeeto','Zakład płukania i sortowania urobku P-01','zaklad','powierzchnia',47.2,31.0,NULL,'Separacja kryształów i skały płonnej.'),
(@obj+7,'Mygeeto','Zakład cięcia kryształów C-02','fabryka','powierzchnia',44.7,28.2,NULL,'Cięcie, klasyfikacja i pakowanie kryształów do transportu.'),
(@obj+8,'Mygeeto','Laboratorium właściwości kryształów L-3','zaklad','powierzchnia',43.9,26.8,NULL,'Kontrola jakości i badania materiałowe; zakres działalności modelowy.'),
(@obj+9,'Mygeeto','Zakład odzysku metali R-04','fabryka','powierzchnia',51.1,39.7,NULL,'Odzysk metali rzadkich z produktów ubocznych kopalń.'),
(@obj+10,'Mygeeto','Koksownia i skład paliwa stałego E-1','fabryka','powierzchnia',52.8,43.1,NULL,'Modelowa produkcja paliwa stałego; zasób węgla niepotwierdzony kanonicznie.'),
(@obj+11,'Mygeeto','Huta stopów kriogenicznych H-2','huta','powierzchnia',49.0,37.3,NULL,'Modelowy przerób lokalnych metali i stopów odpornych na zimno.'),
(@obj+12,'Mygeeto','Kompleks energetyczny Lodowa Brama E-7','zaklad','powierzchnia',46.4,30.5,NULL,'Energia dla kopalń, osłon termicznych i miasta.'),
(@obj+13,'Mygeeto','Magazyn kryształów Banking Clan B-01','sklad','powierzchnia',42.3,24.1,NULL,'Modelowy skład handlowy nawiązujący do historycznej obecności Banking Clan.'),
(@obj+14,'Mygeeto','Terminal eksportowy lodowca T-01','terminal','powierzchnia',41.5,22.8,NULL,'Konsolidacja kryształów i paliwa na regularne frachty.'),
(@obj+15,'Mygeeto','Port orbitalny Mygeeto P-01','port','powierzchnia',40.9,21.6,NULL,'Główny terminal cargo; nazwa i obiekt są modelowe.'),
(@obj+16,'Mygeeto','Centrum odprawy celnej C-11','terminal','powierzchnia',40.3,20.9,NULL,'Kontrola i rejestracja oficjalnych kontraktów.'),
(@obj+17,'Mygeeto','Miasto Iglic — sieć komunalna','zaklad','powierzchnia',39.1,18.7,NULL,'Modelowe centrum miejskie na zurbanizowanym, zimnym świecie.'),
(@obj+18,'Mygeeto','Dzielnica górnicza Północ','zaklad','powierzchnia',48.8,33.3,NULL,'Zaopatrzenie mieszkańców i personelu kopalń.'),
(@obj+19,'Mygeeto','Osiedle techników Lodowa Brama','zaklad','powierzchnia',45.3,29.4,NULL,'Mieszkania i usługi dla personelu energetycznego.'),
(@obj+20,'Mygeeto','Stacja ratownicza Szczelina 4','zaklad','powierzchnia',54.0,46.1,NULL,'Ratownictwo górnicze i zabezpieczenie tras lodowych.'),
(@obj+21,'Mygeeto','Warsztat maszyn górniczych M-4','fabryka','powierzchnia',49.8,38.5,NULL,'Naprawa wiertnic, ładowarek i transporterów.'),
(@obj+22,'Mygeeto','Zakład osłon termicznych O-6','fabryka','powierzchnia',44.1,27.5,NULL,'Wytwarzanie i serwis izolacji dla infrastruktury powierzchniowej.'),
(@obj+23,'Mygeeto','Chłodnia i skład racji F-2','chlodnia','powierzchnia',41.8,23.6,NULL,'Rezerwy żywności dla portu i osiedli.'),
(@obj+24,'Mygeeto','Gospodarstwo szklarniowe S-1','farma','powierzchnia',37.2,15.8,NULL,'Modelowa produkcja świeżej żywności pod osłonami.'),
(@obj+25,'Mygeeto','Kompleks hydroponiczny S-2','farma','powierzchnia',36.5,14.4,NULL,'Uprawy hydroponiczne o małej skali w warunkach kontrolowanych.'),
(@obj+26,'Mygeeto','Przetwórnia racji polarnych R-3','przetwornia','powierzchnia',38.0,17.1,NULL,'Pakowanie żywności i racji dla załóg.'),
(@obj+27,'Mygeeto','Port serwisowy Lodowa Brama P-2','port','powierzchnia',43.1,25.0,NULL,'Obsługa holowników, maszyn i transportów zaopatrzeniowych.'),
(@obj+28,'Mygeeto','Skład części i elektroniki S-9','sklad','powierzchnia',46.0,30.1,NULL,'Bufor części zamiennych, sensorów i sterowników.'),
(@obj+29,'Mygeeto','Przychodnia górnicza M-8','zaklad','powierzchnia',47.5,32.1,NULL,'Opieka medyczna dla pracowników kopalń i ich rodzin.'),
(@obj+30,'Mygeeto','Zajezdnia transportu lodowego V-5','zaklad','powierzchnia',42.8,24.5,NULL,'Serwis ciągników, transporterów i pojazdów gąsienicowych.');

INSERT INTO gosp_zuzycie (obiekt_id,towar,t_doba) VALUES
(@obj+1,'paliwo',220),(@obj+1,'elektronika',15),(@obj+1,'wegiel',900),
(@obj+2,'paliwo',210),(@obj+2,'elektronika',14),(@obj+2,'wegiel',850),
(@obj+3,'paliwo',300),(@obj+3,'elektronika',20),(@obj+3,'wegiel',1100),
(@obj+4,'paliwo',180),(@obj+4,'elektronika',16),(@obj+4,'wegiel',700),
(@obj+5,'paliwo',190),(@obj+5,'elektronika',14),(@obj+5,'wegiel',750),
(@obj+6,'durastal',250),(@obj+6,'paliwo',120),(@obj+7,'elektronika',180),(@obj+7,'paliwo',90),
(@obj+8,'elektronika',90),(@obj+8,'zywnosc',35),(@obj+9,'durastal',420),(@obj+9,'elektronika',65),
(@obj+10,'durastal',800),(@obj+10,'paliwo',260),(@obj+11,'metale_rzadkie',4200),(@obj+11,'wegiel',1800),(@obj+11,'durastal',300),
(@obj+12,'durastal',1100),(@obj+12,'elektronika',50),(@obj+13,'elektronika',35),(@obj+13,'paliwo',80),
(@obj+14,'elektronika',45),(@obj+14,'paliwo',220),(@obj+14,'zywnosc',500),(@obj+15,'durastal',600),(@obj+15,'elektronika',90),(@obj+15,'paliwo',750),(@obj+15,'zywnosc',800),
(@obj+16,'elektronika',25),(@obj+16,'paliwo',40),(@obj+17,'zywnosc',42000),(@obj+17,'paliwo',180),(@obj+17,'luksus',35),
(@obj+18,'zywnosc',9500),(@obj+18,'paliwo',100),(@obj+18,'luksus',12),(@obj+19,'zywnosc',5200),(@obj+19,'paliwo',75),
(@obj+20,'zywnosc',250),(@obj+20,'paliwo',65),(@obj+20,'elektronika',18),(@obj+21,'durastal',450),(@obj+21,'elektronika',65),(@obj+21,'paliwo',80),
(@obj+22,'durastal',300),(@obj+22,'elektronika',35),(@obj+23,'zywnosc',2500),(@obj+23,'paliwo',30),
(@obj+24,'nawozy',120),(@obj+24,'paliwo',20),(@obj+25,'nawozy',100),(@obj+25,'paliwo',18),(@obj+26,'zywnosc',900),(@obj+26,'paliwo',20),
(@obj+27,'paliwo',280),(@obj+27,'elektronika',22),(@obj+28,'durastal',90),(@obj+28,'elektronika',30),
(@obj+29,'zywnosc',180),(@obj+29,'elektronika',8),(@obj+30,'durastal',200),(@obj+30,'paliwo',55);

-- Linie dostaw do Mygeeto; producenci są zarejestrowani w gosp_produkcja.
SET @lin := (SELECT COALESCE(MAX(id),0) FROM gosp_linie);
INSERT INTO gosp_linie (id,obiekt_id,towar,producent,t_doba,ladownosc_t,klasa,odstep_s,predkosc_au_h,postoj_h,faza,obieg)
SELECT @lin+ROW_NUMBER() OVER (ORDER BY q.obiekt_id,q.towar),q.obiekt_id,q.towar,q.producent,q.t_doba,
 GREATEST(500,IF(q.t_doba<q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek)),q.klasa,
 ROUND(GREATEST(500,IF(q.t_doba<q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek))/q.t_doba*86400),
 1.7,2.4,ROUND(MOD(q.obiekt_id*0.127+q.t_doba*0.0005,1),4),'oficjalny_hurt'
FROM (
 SELECT z.obiekt_id,z.towar,z.t_doba,
  CASE z.towar WHEN 'nawozy' THEN 'Foundry' WHEN 'metale_rzadkie' THEN 'Foundry'
   WHEN 'durastal' THEN 'Corellia' WHEN 'elektronika' THEN 'Alpheridies'
   WHEN 'paliwo' THEN 'Cassander' WHEN 'wegiel' THEN 'Mygeeto'
   WHEN 'zywnosc' THEN 'Mygeeto' WHEN 'luksus' THEN 'Albrae-Don' END AS producent,
  t.ladownosc_t AS typowy_ladunek,t.klasa_transportu AS klasa
 FROM gosp_zuzycie z JOIN gosp_towary t ON t.kod=z.towar
 WHERE z.obiekt_id BETWEEN @obj+1 AND @obj+30
) q;

INSERT INTO lokalizacje (typ,rodzaj,rodzic_id,uklad_id,planeta_id,nazwa,x_km,y_km,publiczna,status,opis)
SELECT 'ladowisko','platforma',NULL,NULL,p.id,CONCAT('Lądowisko — ',o.nazwa),
 ROUND(o.dl/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),
 ROUND(o.szer/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),1,'aktywna',o.opis
FROM gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta LEFT JOIN planety_cykle c ON c.planeta_id=p.id
WHERE o.planeta='Mygeeto' AND o.miejsce='powierzchnia'
ON DUPLICATE KEY UPDATE x_km=VALUES(x_km),y_km=VALUES(y_km),publiczna=1,status='aktywna';
UPDATE gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta
JOIN lokalizacje l ON l.planeta_id=p.id AND l.nazwa=CONCAT('Lądowisko — ',o.nazwa)
SET o.lokalizacja_id=l.id WHERE o.planeta='Mygeeto' AND o.miejsce='powierzchnia';

-- Rynek: ceny wyliczane wg salda i odległości od najbliższego producenta.
INSERT INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi)
SELECT 'Mygeeto',x.towar,'oficjalny_hurt',x.prod,x.kons,
 ROUND(LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),3),
 ROUND(x.cena*LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),2),
 'obiekty','wygenerowane','model obiektów; wydobycie kryształów oparte na Legends, węgiel modelowy'
FROM (
 SELECT a.towar,a.prod,a.kons,t.cena_bazowa_kr_t AS cena,
  CASE WHEN a.prod>0 THEN 0 ELSE COALESCE((SELECT MIN(SQRT(POW(mp.x-pp.x,2)+POW(mp.y-pp.y,2)))
   FROM gosp_produkcja gp JOIN mapa_planety pp ON pp.nazwa=gp.planeta JOIN mapa_planety mp ON mp.nazwa='Mygeeto'
   WHERE gp.towar=a.towar AND gp.wydajnosc_t_doba>0),3000) END AS odl
 FROM (
  SELECT k.towar,COALESCE(p.wydajnosc_t_doba,0) AS prod,COALESCE(z.kons,0) AS kons
  FROM (SELECT towar FROM gosp_produkcja WHERE planeta='Mygeeto'
   UNION SELECT g.towar FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id WHERE o.planeta='Mygeeto') k
  LEFT JOIN gosp_produkcja p ON p.planeta='Mygeeto' AND p.towar=k.towar
  LEFT JOIN (SELECT g.towar,SUM(g.t_doba) AS kons FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id
   WHERE o.planeta='Mygeeto' GROUP BY g.towar) z ON z.towar=k.towar
 ) a JOIN gosp_towary t ON t.kod=a.towar
) x
ON DUPLICATE KEY UPDATE produkcja_t_doba=VALUES(produkcja_t_doba),zuzycie_t_doba=VALUES(zuzycie_t_doba),cena_indeks=VALUES(cena_indeks),cena_kr_t=VALUES(cena_kr_t),szczegolowosc=VALUES(szczegolowosc),zrodlo=VALUES(zrodlo),uwagi=VALUES(uwagi);

-- Sieć pamięta dostawców. Węgiel występuje już jako odbiorca w Foundry i Corellii.
INSERT INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi) VALUES
('Foundry','wegiel','oficjalny_hurt',62000,0,0.700,21.00,'szacunek','wygenerowane','otwarty wątek: modelowe paliwo stałe dla hut'),
('Corellia','wegiel','oficjalny_hurt',62000,0,0.700,21.00,'szacunek','wygenerowane','otwarty wątek: modelowe paliwo stałe dla hut'),
('Foundry','nawozy','oficjalny_hurt',80000,0,0.700,315.00,'szacunek','wygenerowane','otwarty wątek: nawozy dla szklarni i hydroponiki'),
('Corellia','durastal','oficjalny_hurt',420000,0,0.700,245.00,'szacunek','wygenerowane','otwarty wątek: blachy i części dla infrastruktury'),
('Alpheridies','elektronika','oficjalny_hurt',12000,0,0.700,8400.00,'szacunek','wygenerowane','otwarty wątek: sensory i sterowniki zakładów'),
('Cassander','paliwo','oficjalny_hurt',47000,0,0.700,420.00,'szacunek','wygenerowane','otwarty wątek: paliwo dla kopalń i transportu'),
('Albrae-Don','luksus','oficjalny_hurt',240000,0,0.700,21000.00,'szacunek','wygenerowane','otwarty wątek: zaopatrzenie osiedli i załóg')
ON DUPLICATE KEY UPDATE
 produkcja_t_doba=IF(szczegolowosc='szacunek',VALUES(produkcja_t_doba),produkcja_t_doba),
 zuzycie_t_doba=IF(szczegolowosc='szacunek',VALUES(zuzycie_t_doba),zuzycie_t_doba),
 cena_indeks=IF(szczegolowosc='szacunek',VALUES(cena_indeks),cena_indeks),
 cena_kr_t=IF(szczegolowosc='szacunek',VALUES(cena_kr_t),cena_kr_t),
 uwagi=IF(szczegolowosc='szacunek',VALUES(uwagi),uwagi);

INSERT INTO schema_migracje (wersja,opis) VALUES ('v57-gospodarka-mygeeto','Gospodarka Mygeeto: kopalnie kryształów i dostawy do hut')
ON DUPLICATE KEY UPDATE opis=VALUES(opis);

-- Kontrole po imporcie.
SELECT COUNT(*) AS obiekty_mygeeto FROM gosp_obiekty WHERE planeta='Mygeeto';
SELECT l.id,l.producent,l.towar FROM gosp_linie l LEFT JOIN gosp_produkcja p ON p.planeta=l.producent AND p.towar=l.towar WHERE l.obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Mygeeto') AND p.planeta IS NULL;
SELECT l.id FROM gosp_linie l LEFT JOIN gosp_obiekty o ON o.id=l.obiekt_id WHERE o.id IS NULL;
SELECT o.id,o.nazwa FROM gosp_obiekty o LEFT JOIN lokalizacje l ON l.id=o.lokalizacja_id WHERE o.planeta='Mygeeto' AND o.miejsce='powierzchnia' AND l.id IS NULL;
SELECT SUM(86400/odstep_s) AS dostawy_na_dobe FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Mygeeto');

-- Ord Mantell: przemysł odzysku, złomowiska, naprawy i transport lokalny.
-- Kanon: scrapyard i podziemne tunele górnicze są poświadczone w materiałach Star Wars.
-- Legends: rozległe Scraplands, złomowiska statków, zakład Junk Heap i zależność od importowanej żywności.
-- Konkretne obiekty, przepustowości, towary odzyskane i wielkości produkcji są szacunkami HOLOLINK.
-- Mapa projektu podaje region 1 (Światy Jądra), podczas gdy źródła lokują Ord Mantell w Mid Rim;
-- migracja nie zmienia mapy ani istniejących tras NPC.
USE hololink;
SET NAMES utf8mb4;

INSERT INTO planety_cykle (planeta_id,doba_h,rok_dni,nachylenie_osi_deg,srednica_km)
SELECT id,doba,rok,17.0,NULLIF(srednica,0) FROM mapa_planety WHERE nazwa='Ord Mantell'
ON DUPLICATE KEY UPDATE doba_h=VALUES(doba_h),rok_dni=VALUES(rok_dni),nachylenie_osi_deg=VALUES(nachylenie_osi_deg),srednica_km=VALUES(srednica_km);
INSERT INTO planety_gry (planeta_id,typ_planety,urbanizacja,opis)
SELECT id,'miasto','wysoka','Świat osad i rozległych złomowisk z przemysłem odzysku i napraw. Działalność Scraplands i zakładu Junk Heap jest opisana w Legends; przepustowości w modelu gry.'
FROM mapa_planety WHERE nazwa='Ord Mantell'
ON DUPLICATE KEY UPDATE typ_planety=VALUES(typ_planety),urbanizacja=VALUES(urbanizacja),opis=VALUES(opis);

INSERT INTO gosp_towary (kod,nazwa,kategoria,ladownosc_t,klasa_transportu,zrodlo,cena_bazowa_kr_t) VALUES
('zlom_przemyslowy','złom przemysłowy do odzysku','surowiec',80000,'barka towarowa Action VI','wygenerowane',45),
('czesci_uzywane','odzyskane części maszynowe i okrętowe','przemysl',8000,'frachtowiec kontenerowy','wygenerowane',900)
ON DUPLICATE KEY UPDATE nazwa=VALUES(nazwa),kategoria=VALUES(kategoria),ladownosc_t=VALUES(ladownosc_t),klasa_transportu=VALUES(klasa_transportu),zrodlo=VALUES(zrodlo),cena_bazowa_kr_t=VALUES(cena_bazowa_kr_t);

-- Eksport jest modelem odzysku: złom, metale, używane części i elektronika po sortowaniu oraz naprawie.
INSERT INTO gosp_produkcja (planeta,towar,wydajnosc_t_doba,zrodlo) VALUES
('Ord Mantell','zlom_przemyslowy',125000,'wookieepedia (Legends: złomowiska); wydajność wygenerowana'),
('Ord Mantell','czesci_uzywane',14000,'wygenerowane; odzysk i regeneracja podzespołów'),
('Ord Mantell','czesci_maszyn',22000,'wygenerowane; naprawa i kompletowanie części'),
('Ord Mantell','durastal',85000,'wygenerowane; przetop złomu'),
('Ord Mantell','metale_rzadkie',6500,'wygenerowane; separacja ze złomu elektronicznego'),
('Ord Mantell','elektronika',4200,'wygenerowane; testowane układy z odzysku')
ON DUPLICATE KEY UPDATE wydajnosc_t_doba=VALUES(wydajnosc_t_doba),zrodlo=VALUES(zrodlo);

DELETE FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Ord Mantell');
DELETE FROM gosp_zuzycie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Ord Mantell');
DELETE FROM gosp_obiekty WHERE planeta='Ord Mantell';
SET @obj := (SELECT COALESCE(MAX(id),0) FROM gosp_obiekty);

INSERT INTO gosp_obiekty (id,planeta,nazwa,rodzaj,miejsce,szer,dl,kat_deg,opis) VALUES
(@obj+1,'Ord Mantell','Złomowisko Scraplands Północ S-01','sklad','powierzchnia',18.1,28.0,NULL,'Modelowy sektor sortowania i składowania złomu na obrzeżach Scraplands.'),
(@obj+2,'Ord Mantell','Złomowisko Scraplands Wschód S-04','sklad','powierzchnia',21.4,33.8,NULL,'Rozległy plac przyjęcia wraków, konstrukcji i zużytych maszyn.'),
(@obj+3,'Ord Mantell','Złomowisko Scraplands Południe S-08','sklad','powierzchnia',-22.5,47.1,NULL,'Sortowanie materiałów ze starych pól przemysłowych.'),
(@obj+4,'Ord Mantell','Złomowisko Scraplands Zachód S-12','sklad','powierzchnia',-16.0,61.4,NULL,'Plac składowy i przeładunkowy dla wraków oraz ciężkiego złomu.'),
(@obj+5,'Ord Mantell','Złomowisko wraków orbitalnych W-2','sklad','powierzchnia',14.8,24.2,NULL,'Lądowy skład elementów odzyskanych z wraków; sam demontaż odbywa się poza orbitą.'),
(@obj+6,'Ord Mantell','Zakład demontażu Junk Heap J-01','fabryka','powierzchnia',12.3,20.1,NULL,'Modelowy zakład odzysku inspirowany znanym z Legends zakładem Junk Heap.'),
(@obj+7,'Ord Mantell','Linia cięcia kadłubów J-04','fabryka','powierzchnia',13.1,21.8,NULL,'Cięcie wraków na partie metalu i elementy nadające się do naprawy.'),
(@obj+8,'Ord Mantell','Sortownia stopów i blach M-3','zaklad','powierzchnia',15.2,25.4,NULL,'Separacja stopów według składu i przydatności do ponownego przetopu.'),
(@obj+9,'Ord Mantell','Huta odzyskowa Scraplands H-1','huta','powierzchnia',17.4,28.9,NULL,'Przetop złomu konstrukcyjnego na wtórny durastal.'),
(@obj+10,'Ord Mantell','Huta odzyskowa Scraplands H-2','huta','powierzchnia',19.2,31.2,NULL,'Mniejszy piec odzyskowy dla krótkich serii i stopów.'),
(@obj+11,'Ord Mantell','Warsztat regeneracji części R-01','fabryka','powierzchnia',10.5,17.6,NULL,'Testowanie i naprawa podzespołów maszynowych oraz okrętowych.'),
(@obj+12,'Ord Mantell','Warsztat odzysku napędów R-05','fabryka','powierzchnia',11.7,19.0,NULL,'Regeneracja siłowników, pomp i elementów napędu.'),
(@obj+13,'Ord Mantell','Zakład testowania elektroniki E-2','zaklad','powierzchnia',9.2,15.8,NULL,'Diagnostyka i klasyfikacja odzyskanych układów elektronicznych.'),
(@obj+14,'Ord Mantell','Pracownia droidów przemysłowych D-7','fabryka','powierzchnia',8.4,14.6,NULL,'Naprawa droidów sortujących, holowniczych i magazynowych.'),
(@obj+15,'Ord Mantell','Węzeł kolei poduszkowej Scraplands K-1','terminal','powierzchnia',16.1,26.9,NULL,'Przeładunek na modelowej sieci kolei obsługującej rozległe złomowiska.'),
(@obj+16,'Ord Mantell','Węzeł kolei poduszkowej Scraplands K-2','terminal','powierzchnia',20.3,34.7,NULL,'Łączy place składowe z zakładami demontażu i sortownią.'),
(@obj+17,'Ord Mantell','Skład części używanych Worlport C-1','sklad','powierzchnia',5.8,11.2,NULL,'Konsolidacja sprawdzonych części dla napraw i oficjalnych kontraktów.'),
(@obj+18,'Ord Mantell','Terminal złomowy Worlport T-3','terminal','powierzchnia',4.9,9.8,NULL,'Ważenie, ewidencja i odprawa hurtowych ładunków odzyskanych materiałów.'),
(@obj+19,'Ord Mantell','Port towarowy Worlport P-1','port','powierzchnia',4.1,8.3,NULL,'Główny modelowy terminal oficjalnego importu żywności i eksportu surowców wtórnych.'),
(@obj+20,'Ord Mantell','Port serwisowy wraków P-4','port','powierzchnia',6.2,12.7,NULL,'Obsługa holowników, dźwigów i jednostek naprawczych.'),
(@obj+21,'Ord Mantell','Worlport — sieć komunalna','zaklad','powierzchnia',3.2,7.5,NULL,'Modelowy węzeł usług i zaopatrzenia osiedli Worlport.'),
(@obj+22,'Ord Mantell','Dzielnica mieszkalna Worlport Północ','zaklad','powierzchnia',2.2,6.1,NULL,'Zaopatrzenie mieszkańców i personelu portu.'),
(@obj+23,'Ord Mantell','Osiedle pracowników Scraplands','zaklad','powierzchnia',14.1,23.6,NULL,'Usługi i dystrybucja dla pracowników placów oraz warsztatów.'),
(@obj+24,'Ord Mantell','Osada przy starych tunelach T-8','zaklad','powierzchnia',-5.1,38.2,NULL,'Modelowa osada przy dawnych wyrobiskach; tunele górnicze są znane z kanonu.'),
(@obj+25,'Ord Mantell','Elektrownia odzyskowa Scraplands E-1','zaklad','powierzchnia',18.0,29.8,NULL,'Modelowe źródło energii wykorzystujące odzyskane paliwo i materiały.'),
(@obj+26,'Ord Mantell','Skład paliwa Worlport F-2','sklad_paliwa','powierzchnia',7.2,13.9,NULL,'Bufor paliwa dla portu, holowników i zakładów.'),
(@obj+27,'Ord Mantell','Magazyn żywności Worlport Ż-1','spichlerz','powierzchnia',3.7,8.6,NULL,'Rezerwa i dystrybucja importowanej żywności.'),
(@obj+28,'Ord Mantell','Chłodnia portowa Worlport C-4','chlodnia','powierzchnia',4.6,9.1,NULL,'Chłodniczy bufor świeżych artykułów z importu.'),
(@obj+29,'Ord Mantell','Przetwórnia racji Worlport R-2','przetwornia','powierzchnia',5.3,10.4,NULL,'Pakowanie żywności dla zmian przemysłowych i załóg frachtowców.'),
(@obj+30,'Ord Mantell','Centrum odprawy celnej O-3','terminal','powierzchnia',6.8,12.1,NULL,'Modelowa obsługa jawnych, rejestrowanych kontraktów hurtowych.');

INSERT INTO gosp_zuzycie (obiekt_id,towar,t_doba) VALUES
(@obj+1,'paliwo',180),(@obj+1,'elektronika',8),(@obj+2,'paliwo',210),(@obj+2,'elektronika',10),
(@obj+3,'paliwo',165),(@obj+3,'elektronika',7),(@obj+4,'paliwo',140),(@obj+4,'elektronika',6),
(@obj+5,'paliwo',110),(@obj+5,'durastal',250),(@obj+6,'paliwo',260),(@obj+6,'elektronika',35),(@obj+7,'paliwo',170),(@obj+7,'durastal',700),
(@obj+8,'paliwo',95),(@obj+8,'elektronika',18),(@obj+9,'zlom_przemyslowy',18000),(@obj+9,'wegiel',1800),(@obj+9,'paliwo',360),
(@obj+10,'zlom_przemyslowy',12000),(@obj+10,'wegiel',1200),(@obj+10,'paliwo',240),
(@obj+11,'czesci_uzywane',600),(@obj+11,'elektronika',22),(@obj+11,'paliwo',75),(@obj+12,'czesci_uzywane',420),(@obj+12,'elektronika',14),
(@obj+13,'zlom_przemyslowy',1800),(@obj+13,'elektronika',35),(@obj+14,'czesci_maszyn',180),(@obj+14,'elektronika',22),(@obj+14,'paliwo',55),
(@obj+15,'durastal',120),(@obj+15,'paliwo',95),(@obj+16,'elektronika',8),(@obj+16,'paliwo',80),
(@obj+17,'czesci_maszyn',1800),(@obj+17,'czesci_uzywane',400),(@obj+18,'elektronika',24),(@obj+18,'paliwo',75),
(@obj+19,'zywnosc',1400),(@obj+19,'paliwo',280),(@obj+19,'elektronika',28),(@obj+20,'durastal',850),(@obj+20,'paliwo',240),(@obj+20,'zywnosc',500),
(@obj+21,'zywnosc',2600),(@obj+21,'paliwo',80),(@obj+22,'zywnosc',1800),(@obj+22,'paliwo',45),(@obj+23,'zywnosc',3200),(@obj+23,'paliwo',60),
(@obj+24,'zywnosc',450),(@obj+24,'paliwo',18),(@obj+25,'durastal',500),(@obj+25,'elektronika',18),(@obj+25,'paliwo',300),
(@obj+26,'elektronika',6),(@obj+27,'zywnosc',1300),(@obj+28,'paliwo',18),(@obj+29,'zywnosc',2800),(@obj+29,'durastal',25),
(@obj+30,'elektronika',18),(@obj+30,'paliwo',45),(@obj+30,'zywnosc',280);

-- Linie hurtowe. Ord Mantell sprowadza żywność i paliwo; odzyskane towary zasilają lokalne zakłady.
SET @lin := (SELECT COALESCE(MAX(id),0) FROM gosp_linie);
INSERT INTO gosp_linie (id,obiekt_id,towar,producent,t_doba,ladownosc_t,klasa,odstep_s,predkosc_au_h,postoj_h,faza,obieg)
SELECT @lin+ROW_NUMBER() OVER (ORDER BY q.obiekt_id,q.towar),q.obiekt_id,q.towar,q.producent,q.t_doba,
 GREATEST(500,IF(q.t_doba<q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek)),q.klasa,
 ROUND(GREATEST(500,IF(q.t_doba<q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek))/q.t_doba*86400),
 1.7,2.5,ROUND(MOD(q.obiekt_id*0.157+q.t_doba*0.0008,1),4),'oficjalny_hurt'
FROM (
 SELECT z.obiekt_id,z.towar,z.t_doba,
  CASE z.towar WHEN 'paliwo' THEN 'Cassander' WHEN 'zywnosc' THEN 'Mattri'
   WHEN 'elektronika' THEN 'Alpheridies' WHEN 'durastal' THEN 'Ord Mantell'
   WHEN 'zlom_przemyslowy' THEN 'Ord Mantell' WHEN 'czesci_uzywane' THEN 'Ord Mantell'
   WHEN 'czesci_maszyn' THEN 'Ord Mantell' WHEN 'wegiel' THEN 'Mygeeto' END AS producent,
  t.ladownosc_t AS typowy_ladunek,t.klasa_transportu AS klasa
 FROM gosp_zuzycie z JOIN gosp_towary t ON t.kod=z.towar
 WHERE z.obiekt_id BETWEEN @obj+1 AND @obj+30
) q;

INSERT INTO lokalizacje (typ,rodzaj,rodzic_id,uklad_id,planeta_id,nazwa,x_km,y_km,publiczna,status,opis)
SELECT 'ladowisko','platforma',NULL,NULL,p.id,CONCAT('Lądowisko — ',o.nazwa),
 ROUND(o.dl/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),
 ROUND(o.szer/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),1,'aktywna',o.opis
FROM gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta LEFT JOIN planety_cykle c ON c.planeta_id=p.id
WHERE o.planeta='Ord Mantell' AND o.miejsce='powierzchnia'
ON DUPLICATE KEY UPDATE x_km=VALUES(x_km),y_km=VALUES(y_km),publiczna=1,status='aktywna';
UPDATE gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta
JOIN lokalizacje l ON l.planeta_id=p.id AND l.nazwa=CONCAT('Lądowisko — ',o.nazwa)
SET o.lokalizacja_id=l.id WHERE o.planeta='Ord Mantell' AND o.miejsce='powierzchnia';

-- Rynek planety: saldo, obrót i najkrótsza odległość do aktywnego producenta według wspólnego wzoru cen.
INSERT INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi)
SELECT 'Ord Mantell',x.towar,'oficjalny_hurt',x.prod,x.kons,
 ROUND(LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),3),
 ROUND(x.cena*LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),2),
 'obiekty','wygenerowane','model odzysku i napraw; złomowiska oparte na Legends, wolumeny wygenerowane'
FROM (
 SELECT a.towar,a.prod,a.kons,t.cena_bazowa_kr_t AS cena,
  CASE WHEN a.prod>0 THEN 0 ELSE COALESCE((SELECT MIN(SQRT(POW(mp.x-pp.x,2)+POW(mp.y-pp.y,2)))
   FROM gosp_produkcja gp JOIN mapa_planety pp ON pp.nazwa=gp.planeta JOIN mapa_planety mp ON mp.nazwa='Ord Mantell'
   WHERE gp.towar=a.towar AND gp.wydajnosc_t_doba>0),3000) END AS odl
 FROM (
  SELECT k.towar,COALESCE(p.wydajnosc_t_doba,0) AS prod,COALESCE(z.kons,0) AS kons
  FROM (SELECT towar FROM gosp_produkcja WHERE planeta='Ord Mantell'
   UNION SELECT g.towar FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id WHERE o.planeta='Ord Mantell') k
  LEFT JOIN gosp_produkcja p ON p.planeta='Ord Mantell' AND p.towar=k.towar
  LEFT JOIN (SELECT g.towar,SUM(g.t_doba) AS kons FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id
   WHERE o.planeta='Ord Mantell' GROUP BY g.towar) z ON z.towar=k.towar
 ) a JOIN gosp_towary t ON t.kod=a.towar
) x
ON DUPLICATE KEY UPDATE produkcja_t_doba=VALUES(produkcja_t_doba),zuzycie_t_doba=VALUES(zuzycie_t_doba),cena_indeks=VALUES(cena_indeks),cena_kr_t=VALUES(cena_kr_t),szczegolowosc=VALUES(szczegolowosc),zrodlo=VALUES(zrodlo),uwagi=VALUES(uwagi);

-- Otwarta pamięć dostawców. Wpisy już opracowane szczegółowo pozostają bez zmian.
INSERT INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi) VALUES
('Mattri','zywnosc','oficjalny_hurt',1200000,0,0.700,84.00,'szacunek','wygenerowane','otwarty wątek: dostawy żywności dla osiedli Ord Mantell'),
('Cassander','paliwo','oficjalny_hurt',47000,0,0.700,420.00,'szacunek','wygenerowane','otwarty wątek: paliwo dla placów złomowych i portu'),
('Alpheridies','elektronika','oficjalny_hurt',12000,0,0.700,8400.00,'szacunek','wygenerowane','otwarty wątek: nowe układy do diagnostyki odzyskanych systemów'),
('Mygeeto','wegiel','oficjalny_hurt',62000,0,0.700,21.00,'szacunek','wygenerowane','otwarty wątek: paliwo stałe dla pieców odzyskowych'),
('Eriadu','durastal','oficjalny_hurt',210000,0,0.700,245.00,'szacunek','wygenerowane','otwarty wątek: blachy i profile do napraw ciężkich wraków'),
('Corellia','czesci_maszyn','oficjalny_hurt',180000,0,0.700,1120.00,'szacunek','wygenerowane','otwarty wątek: precyzyjne części do remontów i regeneracji')
ON DUPLICATE KEY UPDATE
 produkcja_t_doba=IF(szczegolowosc='szacunek',VALUES(produkcja_t_doba),produkcja_t_doba),
 zuzycie_t_doba=IF(szczegolowosc='szacunek',VALUES(zuzycie_t_doba),zuzycie_t_doba),
 cena_indeks=IF(szczegolowosc='szacunek',VALUES(cena_indeks),cena_indeks),
 cena_kr_t=IF(szczegolowosc='szacunek',VALUES(cena_kr_t),cena_kr_t),
 zrodlo=IF(szczegolowosc='szacunek',VALUES(zrodlo),zrodlo),
 uwagi=IF(szczegolowosc='szacunek',VALUES(uwagi),uwagi);

INSERT INTO schema_migracje (wersja,opis) VALUES ('v59-gospodarka-ord-mantell','Gospodarka Ord Mantell: odzysk, części i złomowiska')
ON DUPLICATE KEY UPDATE opis=VALUES(opis);

-- Kontrole po imporcie.
SELECT COUNT(*) AS obiekty_ord_mantell FROM gosp_obiekty WHERE planeta='Ord Mantell';
SELECT l.id,l.producent,l.towar FROM gosp_linie l LEFT JOIN gosp_produkcja p ON p.planeta=l.producent AND p.towar=l.towar WHERE l.obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Ord Mantell') AND p.planeta IS NULL;
SELECT l.id FROM gosp_linie l LEFT JOIN gosp_obiekty o ON o.id=l.obiekt_id WHERE o.id IS NULL;
SELECT o.id,o.nazwa FROM gosp_obiekty o LEFT JOIN lokalizacje l ON l.id=o.lokalizacja_id WHERE o.planeta='Ord Mantell' AND o.miejsce='powierzchnia' AND l.id IS NULL;
SELECT SUM(86400/odstep_s) AS dostawy_na_dobe FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Ord Mantell');

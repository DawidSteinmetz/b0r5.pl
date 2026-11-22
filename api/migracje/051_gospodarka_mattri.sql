-- Mattri: szczegółowy model rolniczo-eksportowy. Kanon potwierdza położenie w Inner Rim;
-- brak wiarygodnych danych o klimacie, ludności, cyklach i gospodarce planety. Pozostałe liczby
-- oraz nazwy obiektów są szacunkami do gry. Żywność: 1,200,000 t/d, z czego dotychczasowe
-- kontrakty kuackie (~249,000 t/d) stanowią ok. 21% eksportu.
USE hololink;
SET NAMES utf8mb4;

-- Wartości planetologiczne robocze (niekanoniczne): świat umiarkowany, rolniczy.
INSERT INTO planety_cykle (planeta_id,doba_h,rok_dni,nachylenie_osi_deg,srednica_km,gwiazda_typ)
SELECT 1411,25.2,371,17.4,10400,'G' WHERE EXISTS (SELECT 1 FROM mapa_planety WHERE id=1411)
ON DUPLICATE KEY UPDATE doba_h=VALUES(doba_h),rok_dni=VALUES(rok_dni),nachylenie_osi_deg=VALUES(nachylenie_osi_deg),srednica_km=VALUES(srednica_km);
INSERT INTO planety_gry (planeta_id,typ_planety,urbanizacja,opis)
SELECT 1411,'lesna','niska','Umiarkowany świat rolniczy; wielkie pasy upraw i rozproszone ośrodki przetwórcze (model gry).'
ON DUPLICATE KEY UPDATE typ_planety=VALUES(typ_planety),urbanizacja=VALUES(urbanizacja),opis=VALUES(opis);

INSERT INTO gosp_towary (kod,nazwa,kategoria,ladownosc_t,klasa_transportu,zrodlo,cena_bazowa_kr_t) VALUES
('nawozy','nawozy mineralne i regeneratory gleby','rolnictwo',12000,'frachtowiec kontenerowy','wygenerowane',450),
('maszyny_rolnicze','maszyny i roboty rolnicze','przemysl',5000,'frachtowiec kontenerowy','wygenerowane',8500),
('czesci_maszyn','części maszynowe','przemysl',8000,'frachtowiec kontenerowy','wygenerowane',1600)
ON DUPLICATE KEY UPDATE nazwa=VALUES(nazwa),cena_bazowa_kr_t=VALUES(cena_bazowa_kr_t);

-- Dostawcy referencyjni; źródła planet i wielkości podaży są szacunkiem, nie faktami kanonicznymi.
INSERT INTO gosp_produkcja (planeta,towar,wydajnosc_t_doba,zrodlo) VALUES
('Mattri','zywnosc',1200000,'wygenerowane'),('Foundry','nawozy',80000,'wygenerowane'),
('Corellia','maszyny_rolnicze',35000,'wygenerowane'),('Kuat','czesci_maszyn',90000,'wygenerowane'),
('Cassander','paliwo',343000,'wygenerowane'),('Alpheridies','elektronika',2035000,'wygenerowane'),
('Mattri','durastal',12000,'wygenerowane')
ON DUPLICATE KEY UPDATE wydajnosc_t_doba=VALUES(wydajnosc_t_doba),zrodlo=VALUES(zrodlo);

-- Bezpieczne ponowne uruchomienie: sprzątane są wyłącznie rekordy Mattri.
DELETE FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Mattri');
DELETE FROM gosp_zuzycie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Mattri');
DELETE FROM gosp_obiekty WHERE planeta='Mattri';
SET @obj := (SELECT COALESCE(MAX(id),0) FROM gosp_obiekty);

INSERT INTO gosp_obiekty (id,planeta,nazwa,rodzaj,miejsce,szer,dl,kat_deg,opis) VALUES
(@obj+1,'Mattri','Kombinat Zbożowy Równina Wschodnia A-01','farma','powierzchnia',12.2,24.1,NULL,'Zmechanizowane uprawy zbóż i roślin wysokokalorycznych.'),
(@obj+2,'Mattri','Plantacja Błękitnych Tarasów B-04','farma','powierzchnia',18.4,31.8,NULL,'Plantacje owoców i roślin oleistych.'),
(@obj+3,'Mattri','Spółdzielnia Doliny Saan C-12','farma','powierzchnia',8.1,16.3,NULL,'Rozproszone gospodarstwa mieszane.'),
(@obj+4,'Mattri','Farma Północnych Łąk D-08','farma','powierzchnia',31.5,-42.2,NULL,'Uprawy paszowe i hodowla na chłodniejszej szerokości.'),
(@obj+5,'Mattri','Plantacja Rzeki Vaal E-17','farma','powierzchnia',-9.7,73.1,NULL,'Nawadniane uprawy warzyw i roślin strączkowych.'),
(@obj+6,'Mattri','Farma Zachodniego Pasa F-03','farma','powierzchnia',-16.4,-28.5,NULL,'Wielkoobszarowe uprawy rotacyjne.'),
(@obj+7,'Mattri','Zakład Młynarski Równina 1','przetwornia','powierzchnia',12.0,24.5,NULL,'Mielenie zbóż i pakowanie mąki.'),
(@obj+8,'Mattri','Zakład Konserwacji Vaal 2','przetwornia','powierzchnia',-9.5,73.5,NULL,'Konserwy i racje żywnościowe dla eksportu.'),
(@obj+9,'Mattri','Przetwórnia Olejów Błękitnych 3','przetwornia','powierzchnia',18.1,32.1,NULL,'Tłoczenie olejów spożywczych.'),
(@obj+10,'Mattri','Chłodnia Eksportowa Równina 1','chlodnia','powierzchnia',12.3,24.8,NULL,'Sortowanie i chłodnicze przechowywanie płodów.'),
(@obj+11,'Mattri','Chłodnia Portowa Saan','chlodnia','powierzchnia',7.8,17.1,NULL,'Bufor ładunków przed transportem orbitalnym.'),
(@obj+12,'Mattri','Spichlerz Centralny Aster','spichlerz','powierzchnia',14.0,22.0,NULL,'Rezerwy zbożowe dla regionu stołecznego.'),
(@obj+13,'Mattri','Spichlerz Północny Kest','spichlerz','powierzchnia',30.9,-41.4,NULL,'Magazyn zboża i pasz.'),
(@obj+14,'Mattri','Port Eksportowy Meridia P-11','port','powierzchnia',11.8,25.2,NULL,'Główny port eksportu żywności; obsługuje stałe kontrakty z Kuat.'),
(@obj+15,'Mattri','Port Chłodniczy Vaal P-22','port','powierzchnia',-9.2,74.0,NULL,'Terminal chłodniczy dla produktów świeżych.'),
(@obj+16,'Mattri','Miasto Aster — sieć komunalna','zaklad','powierzchnia',13.1,23.4,NULL,'Zaopatrzenie miejskie i lokalna dystrybucja.'),
(@obj+17,'Mattri','Miasto Saan — sieć komunalna','zaklad','powierzchnia',7.2,16.0,NULL,'Regionalny ośrodek administracji i usług.'),
(@obj+18,'Mattri','Osada Kest — kooperatywa','zaklad','powierzchnia',32.0,-43.0,NULL,'Mała osada rolnicza i punkt skupu.'),
(@obj+19,'Mattri','Warsztat Robotów Polowych A-6','fabryka','powierzchnia',15.1,26.1,NULL,'Naprawy maszyn i robotów rolniczych.'),
(@obj+20,'Mattri','Zakład Nawadniania Vaal W-2','zaklad','powierzchnia',-10.1,72.0,NULL,'Pompy, kanały i serwis infrastruktury wodnej.'),
(@obj+21,'Mattri','Wytwórnia Kompostu Saan N-3','przetwornia','powierzchnia',6.8,18.0,NULL,'Odzysk odpadów organicznych i nawożenie pól.'),
(@obj+22,'Mattri','Skład Paliwa Rolniczego Meridia','sklad_paliwa','powierzchnia',11.4,26.0,NULL,'Zapas paliwa dla maszyn i portu.'),
(@obj+23,'Mattri','Centrum Zaopatrzenia Aster','sklad','powierzchnia',13.8,22.8,NULL,'Elektronika, części i towary codzienne.'),
(@obj+24,'Mattri','Terminal Zbożowy Północny','terminal','powierzchnia',29.8,-40.5,NULL,'Skup i konsolidacja plonów.'),
(@obj+25,'Mattri','Zakład Pakowania Meridia','fabryka','powierzchnia',12.6,25.7,NULL,'Opakowania i przygotowanie eksportowych partii żywności.');

INSERT INTO gosp_zuzycie (obiekt_id,towar,t_doba) VALUES
(@obj+1,'paliwo',2200),(@obj+1,'nawozy',1800),(@obj+1,'maszyny_rolnicze',20),
(@obj+2,'paliwo',1500),(@obj+2,'nawozy',1200),(@obj+3,'paliwo',900),(@obj+3,'nawozy',700),
(@obj+4,'paliwo',1100),(@obj+4,'nawozy',650),(@obj+5,'paliwo',1300),(@obj+5,'nawozy',1000),
(@obj+6,'paliwo',1400),(@obj+6,'nawozy',900),(@obj+7,'paliwo',550),(@obj+7,'zywnosc',300),
(@obj+8,'paliwo',700),(@obj+8,'elektronika',12),(@obj+9,'paliwo',420),(@obj+9,'durastal',80),
(@obj+10,'paliwo',250),(@obj+10,'elektronika',18),(@obj+11,'paliwo',180),(@obj+12,'paliwo',120),
(@obj+13,'paliwo',90),(@obj+14,'paliwo',950),(@obj+14,'elektronika',45),(@obj+14,'zywnosc',120),
(@obj+15,'paliwo',500),(@obj+15,'elektronika',22),(@obj+16,'zywnosc',1800),(@obj+16,'paliwo',160),
(@obj+17,'zywnosc',900),(@obj+17,'paliwo',90),(@obj+18,'zywnosc',90),(@obj+18,'paliwo',12),
(@obj+19,'elektronika',35),(@obj+19,'durastal',120),(@obj+19,'czesci_maszyn',60),
(@obj+20,'elektronika',8),(@obj+20,'czesci_maszyn',40),(@obj+21,'paliwo',100),
(@obj+22,'elektronika',5),(@obj+23,'elektronika',15),(@obj+23,'zywnosc',250),
(@obj+24,'paliwo',140),(@obj+25,'durastal',60),(@obj+25,'elektronika',8);

-- Jedna linia na każdą pozycję zużycia. Pojemność odpowiada typowemu ładunkowi;
-- przy małym popycie zmniejszamy statek tak, by dostawa następowała co około tydzień.
SET @lin := (SELECT COALESCE(MAX(id),0) FROM gosp_linie);
INSERT INTO gosp_linie (id,obiekt_id,towar,producent,t_doba,ladownosc_t,klasa,odstep_s,predkosc_au_h,postoj_h,faza,obieg)
SELECT @lin + ROW_NUMBER() OVER (ORDER BY q.obiekt_id,q.towar),q.obiekt_id,q.towar,q.producent,q.t_doba,
       GREATEST(500,IF(q.t_doba < q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek)),
       q.klasa,ROUND(GREATEST(500,IF(q.t_doba < q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek))/q.t_doba*86400),
       1.6,2.5,ROUND(MOD(q.obiekt_id*0.137+q.t_doba*0.0001,1),4),'oficjalny_hurt'
FROM (
 SELECT z.obiekt_id,z.towar,z.t_doba,
        CASE z.towar WHEN 'zywnosc' THEN 'Mattri' WHEN 'nawozy' THEN 'Foundry'
          WHEN 'maszyny_rolnicze' THEN 'Corellia' WHEN 'czesci_maszyn' THEN 'Kuat'
          WHEN 'paliwo' THEN 'Cassander' WHEN 'elektronika' THEN 'Alpheridies'
          WHEN 'durastal' THEN 'Mattri' END AS producent,
        t.ladownosc_t AS typowy_ladunek,t.klasa_transportu AS klasa
 FROM gosp_zuzycie z JOIN gosp_towary t ON t.kod=z.towar
 WHERE z.obiekt_id BETWEEN @obj+1 AND @obj+25
) q;
-- Lądowiska zgodne z migracją 049, z użyciem średnicy z cykli.
INSERT INTO lokalizacje (typ,rodzaj,rodzic_id,uklad_id,planeta_id,nazwa,x_km,y_km,publiczna,status,opis)
SELECT 'ladowisko','platforma',NULL,NULL,1411,CONCAT('Lądowisko — ',o.nazwa),
 ROUND(o.dl/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),
 ROUND(o.szer/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),1,'aktywna',o.opis
FROM gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta
LEFT JOIN planety_cykle c ON c.planeta_id=p.id WHERE o.planeta='Mattri' AND o.miejsce='powierzchnia'
ON DUPLICATE KEY UPDATE x_km=VALUES(x_km),y_km=VALUES(y_km),publiczna=1,status='aktywna';
UPDATE gosp_obiekty o JOIN lokalizacje l ON l.planeta_id=1411 AND l.nazwa=CONCAT('Lądowisko — ',o.nazwa)
SET o.lokalizacja_id=l.id WHERE o.planeta='Mattri' AND o.miejsce='powierzchnia';

-- Rynek liczony z produkcji i sum zużycia obiektów, według wspólnego wzoru cenowego.
INSERT INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi)
SELECT 'Mattri',x.towar,'oficjalny_hurt',x.prod,x.kons,
       ROUND(LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),3),
       ROUND(x.cena*LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),2),
       'obiekty','wygenerowane','wartości z produkcji i zużycia obiektów; produkcja i dostawcy modelowi'
FROM (
 SELECT a.towar,a.prod,a.kons,t.cena_bazowa_kr_t AS cena,
        CASE WHEN a.prod>0 THEN 0 ELSE COALESCE((
          SELECT MIN(SQRT(POW(mp.x-pp.x,2)+POW(mp.y-pp.y,2)))
          FROM gosp_produkcja gp JOIN mapa_planety pp ON pp.nazwa=gp.planeta
          JOIN mapa_planety mp ON mp.nazwa='Mattri'
          WHERE gp.towar=a.towar AND gp.wydajnosc_t_doba>0
        ),3000) END AS odl
 FROM (
   SELECT k.towar,COALESCE(p.wydajnosc_t_doba,0) AS prod,COALESCE(z.kons,0) AS kons
   FROM (SELECT towar FROM gosp_produkcja WHERE planeta='Mattri'
         UNION SELECT g.towar FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id WHERE o.planeta='Mattri') k
   LEFT JOIN gosp_produkcja p ON p.planeta='Mattri' AND p.towar=k.towar
   LEFT JOIN (SELECT g.towar,SUM(g.t_doba) AS kons FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id WHERE o.planeta='Mattri' GROUP BY g.towar) z ON z.towar=k.towar
 ) a JOIN gosp_towary t ON t.kod=a.towar
) x
ON DUPLICATE KEY UPDATE produkcja_t_doba=VALUES(produkcja_t_doba),zuzycie_t_doba=VALUES(zuzycie_t_doba),cena_indeks=VALUES(cena_indeks),cena_kr_t=VALUES(cena_kr_t),szczegolowosc=VALUES(szczegolowosc),zrodlo=VALUES(zrodlo),uwagi=VALUES(uwagi);

-- Pamięć o dostawcach towarów sprowadzanych przez Mattri; ceny liczone jak wyżej, status szacunkowy.
INSERT IGNORE INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi) VALUES
('Foundry','nawozy','oficjalny_hurt',80000,0,0.700,315.00,'szacunek','wygenerowane','otwarty wątek: produkcja nawozów i kontrakt dostawczy Mattri'),
('Corellia','maszyny_rolnicze','oficjalny_hurt',35000,0,0.700,5950.00,'szacunek','wygenerowane','otwarty wątek: produkcja maszyn rolniczych i dostawy Mattri'),
('Kuat','czesci_maszyn','oficjalny_hurt',90000,0,0.700,1120.00,'szacunek','wygenerowane','otwarty wątek: produkcja części maszynowych'),
('Cassander','paliwo','oficjalny_hurt',343000,0,0.700,420.00,'szacunek','wygenerowane','otwarty wątek: źródła paliwa i kontrakty dla Mattri'),
('Alpheridies','elektronika','oficjalny_hurt',2035000,0,0.700,8400.00,'szacunek','wygenerowane','otwarty wątek: dostawy elektroniki do Mattri');
INSERT INTO schema_migracje (wersja,opis) VALUES ('v51-gospodarka-mattri','Gospodarka Mattri: rolnictwo, eksport i rynek')
ON DUPLICATE KEY UPDATE opis=VALUES(opis);

-- Kontrole po imporcie.
SELECT COUNT(*) AS obiekty_mattri FROM gosp_obiekty WHERE planeta='Mattri';
SELECT l.id,l.producent,l.towar FROM gosp_linie l LEFT JOIN gosp_produkcja p ON p.planeta=l.producent AND p.towar=l.towar WHERE l.obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Mattri') AND p.planeta IS NULL;
SELECT l.id FROM gosp_linie l LEFT JOIN gosp_obiekty o ON o.id=l.obiekt_id WHERE o.id IS NULL;
SELECT o.id,o.nazwa FROM gosp_obiekty o LEFT JOIN lokalizacje l ON l.id=o.lokalizacja_id WHERE o.planeta='Mattri' AND o.miejsce='powierzchnia' AND l.id IS NULL;
SELECT SUM(86400/odstep_s) AS dostawy_na_dobe FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Mattri');

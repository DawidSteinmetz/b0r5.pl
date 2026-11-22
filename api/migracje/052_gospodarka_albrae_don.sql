-- Albrae-Don: szczegółowy model luksusowego rzemiosła, przetwórstwa i tranzytu.
-- Źródła Wookieepedii: Albrae-Don leży w Sern sector w Colonies; Draeo-Kax jest miastem
-- planety, w pobliżu którego przebiega Pica Rim Pipeline. Brak wiarygodnych danych o klimacie,
-- ludności, cyklach i gospodarce; rozmiar świata, urbanizacja, wszystkie liczby i pozostałe
-- obiekty są szacunkami do gry. Produkcję towarów luksusowych korygujemy z 1,818,000 do
-- 240,000 t/d; kontrakt Kuat (900 t/d) to mały, wiarygodny wycinek eksportu.
USE hololink;
SET NAMES utf8mb4;

-- Ustawienia robocze, niekanoniczne. Nazwa planety i ID pobierane są z mapy.
INSERT INTO planety_cykle (planeta_id,doba_h,rok_dni,nachylenie_osi_deg,srednica_km)
SELECT id,24.6,382,19.0,11800 FROM mapa_planety WHERE nazwa='Albrae-Don'
ON DUPLICATE KEY UPDATE doba_h=VALUES(doba_h),rok_dni=VALUES(rok_dni),nachylenie_osi_deg=VALUES(nachylenie_osi_deg),srednica_km=VALUES(srednica_km);
INSERT INTO planety_gry (planeta_id,typ_planety,urbanizacja,opis)
SELECT id,'miasto','wysoka','Świat handlowo-rzemieślniczy; Draeo-Kax i korytarz Pica Rim Pipeline stanowią oś modelowej gospodarki.'
FROM mapa_planety WHERE nazwa='Albrae-Don'
ON DUPLICATE KEY UPDATE typ_planety=VALUES(typ_planety),urbanizacja=VALUES(urbanizacja),opis=VALUES(opis);

-- Korekta wcześniejszego, losowego oszacowania podaży. Towar luksusowy pozostaje kategorią zbiorczą.
INSERT INTO gosp_produkcja (planeta,towar,wydajnosc_t_doba,zrodlo)
VALUES ('Albrae-Don','luksus',240000,'wygenerowane')
ON DUPLICATE KEY UPDATE wydajnosc_t_doba=VALUES(wydajnosc_t_doba),zrodlo=VALUES(zrodlo);
INSERT INTO gosp_produkcja (planeta,towar,wydajnosc_t_doba,zrodlo) VALUES
('Foundry','zywnosc',2118000,'wygenerowane'),('Cassander','paliwo',343000,'wygenerowane'),
('Nubia','durastal',1466000,'wygenerowane'),('Alpheridies','elektronika',2035000,'wygenerowane')
ON DUPLICATE KEY UPDATE wydajnosc_t_doba=VALUES(wydajnosc_t_doba),zrodlo=VALUES(zrodlo);

-- Ponowny import odtwarza tylko obiekty, zużycie i linie Albrae-Don.
DELETE FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Albrae-Don');
DELETE FROM gosp_zuzycie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Albrae-Don');
DELETE FROM gosp_obiekty WHERE planeta='Albrae-Don';
SET @obj := (SELECT COALESCE(MAX(id),0) FROM gosp_obiekty);

INSERT INTO gosp_obiekty (id,planeta,nazwa,rodzaj,miejsce,szer,dl,kat_deg,opis) VALUES
(@obj+1,'Albrae-Don','Warsztaty jubilerskie Draeo-Kax J-01','fabryka','powierzchnia',4.2,12.5,NULL,'Wytwarzanie biżuterii i drobnych wyrobów luksusowych.'),
(@obj+2,'Albrae-Don','Atelier włókiennicze Draeo-Kax T-04','fabryka','powierzchnia',4.8,13.1,NULL,'Projektowanie i produkcja odzieży wysokiej jakości.'),
(@obj+3,'Albrae-Don','Manufaktura szkła artystycznego D-09','fabryka','powierzchnia',3.9,11.7,NULL,'Ręczne i półautomatyczne wyroby dekoracyjne.'),
(@obj+4,'Albrae-Don','Gildia rzemieślników Draeo-Kax','zaklad','powierzchnia',4.5,12.0,NULL,'Cechy, kontrola jakości i kontraktacja zamówień.'),
(@obj+5,'Albrae-Don','Dom Mody Sern — pracownia centralna','fabryka','powierzchnia',5.1,13.7,NULL,'Pracownie krawieckie i showroomy eksportowe.'),
(@obj+6,'Albrae-Don','Zakład precyzyjnych instrumentów A-12','fabryka','powierzchnia',6.4,14.8,NULL,'Przyrządy pomiarowe i instrumenty kolekcjonerskie.'),
(@obj+7,'Albrae-Don','Przetwórnia kamieni ozdobnych K-03','przetwornia','powierzchnia',8.0,17.4,NULL,'Cięcie, szlifowanie i oprawa kamieni; geologia lokalna nieustalona.'),
(@obj+8,'Albrae-Don','Zakład galwaniczny Draeo-Kax G-2','zaklad','powierzchnia',5.8,15.1,NULL,'Powłoki ochronne i wykończeniowe dla rzemiosła.'),
(@obj+9,'Albrae-Don','Centrum wzornictwa Sern','zaklad','powierzchnia',3.6,10.8,NULL,'Projekty, prototypy i archiwum wzorów.'),
(@obj+10,'Albrae-Don','Magazyn kontraktowy Pica R-01','sklad','powierzchnia',2.8,9.4,NULL,'Skonsolidowane ładunki przy szlaku Pica Rim Pipeline.'),
(@obj+11,'Albrae-Don','Terminal towarowy Draeo-Kax P-11','port','powierzchnia',4.0,14.0,NULL,'Terminal eksportu; odprawa stałych kontraktów hurtowych.'),
(@obj+12,'Albrae-Don','Terminal luksusowy Pica P-22','port','powierzchnia',2.1,8.2,NULL,'Fracht kurierski i partie o wysokiej wartości.'),
(@obj+13,'Albrae-Don','Skład celny przy Pica Rim S-5','terminal','powierzchnia',1.8,7.5,NULL,'Przeładunek i magazynowanie przy korytarzu tranzytowym.'),
(@obj+14,'Albrae-Don','Draeo-Kax — sieć komunalna','zaklad','powierzchnia',4.4,12.8,NULL,'Zaopatrzenie i usługi dla miasta; istnienie miasta kanoniczne, skala modelowa.'),
(@obj+15,'Albrae-Don','Draeo-Kax — dzielnica targowa','sklad','powierzchnia',4.6,12.2,NULL,'Rynek hurtowy, sklepy i dystrybucja lokalna.'),
(@obj+16,'Albrae-Don','Osiedle Pica — zaopatrzenie','zaklad','powierzchnia',1.2,6.8,NULL,'Modelowe osiedle obsługi korytarza transportowego.'),
(@obj+17,'Albrae-Don','Centrum napraw droidów Pica','fabryka','powierzchnia',2.6,8.8,NULL,'Serwis robotów magazynowych i urządzeń przeładunkowych.'),
(@obj+18,'Albrae-Don','Zakład opakowań i plomb Sern','fabryka','powierzchnia',3.1,10.1,NULL,'Opakowania ochronne i plomby kontraktowe.'),
(@obj+19,'Albrae-Don','Chłodnia i skład żywności Draeo-Kax','chlodnia','powierzchnia',4.1,13.3,NULL,'Bufor podstawowych artykułów spożywczych.'),
(@obj+20,'Albrae-Don','Skład paliwa Pica Rim','sklad_paliwa','powierzchnia',1.4,7.9,NULL,'Paliwo dla floty lokalnej i terminali.'),
(@obj+21,'Albrae-Don','Elektrownia miejska D-3','zaklad','powierzchnia',6.8,16.2,NULL,'Energia dla miasta i zakładów produkcyjnych.'),
(@obj+22,'Albrae-Don','Centrum łączności szlaku Pica','zaklad','powierzchnia',2.3,8.5,NULL,'Łączność i koordynacja ruchu wokół pipeline; instalacja modelowa.'),
(@obj+23,'Albrae-Don','Dom aukcyjny Sern','zaklad','powierzchnia',4.9,11.2,NULL,'Aukcje i certyfikacja eksportowanych wyrobów.'),
(@obj+24,'Albrae-Don','Port zaopatrzeniowy Draeo-Kax','port','powierzchnia',5.4,15.8,NULL,'Przyjmuje żywność, paliwo, elektronikę i materiały.'),
(@obj+25,'Albrae-Don','Warsztat odzysku i napraw S-8','fabryka','powierzchnia',7.1,18.3,NULL,'Naprawa wyposażenia i odzysk materiałów opakowaniowych.');

INSERT INTO gosp_zuzycie (obiekt_id,towar,t_doba) VALUES
(@obj+1,'durastal',8),(@obj+1,'elektronika',2),(@obj+1,'luksus',15),
(@obj+2,'durastal',12),(@obj+2,'elektronika',3),(@obj+2,'luksus',30),
(@obj+3,'paliwo',15),(@obj+3,'elektronika',4),(@obj+3,'luksus',10),
(@obj+4,'zywnosc',90),(@obj+4,'paliwo',8),(@obj+5,'durastal',18),(@obj+5,'elektronika',6),(@obj+5,'luksus',25),
(@obj+6,'durastal',20),(@obj+6,'elektronika',8),(@obj+7,'paliwo',45),(@obj+7,'elektronika',3),
(@obj+8,'paliwo',35),(@obj+8,'durastal',14),(@obj+9,'zywnosc',35),(@obj+9,'elektronika',7),
(@obj+10,'paliwo',55),(@obj+10,'elektronika',12),(@obj+10,'zywnosc',80),
(@obj+11,'paliwo',220),(@obj+11,'elektronika',18),(@obj+11,'zywnosc',160),
(@obj+12,'paliwo',130),(@obj+12,'elektronika',12),(@obj+12,'zywnosc',80),
(@obj+13,'paliwo',90),(@obj+13,'elektronika',10),(@obj+13,'zywnosc',50),
(@obj+14,'zywnosc',520),(@obj+14,'paliwo',25),(@obj+15,'zywnosc',240),
(@obj+16,'zywnosc',140),(@obj+16,'paliwo',8),(@obj+17,'elektronika',9),(@obj+17,'durastal',22),
(@obj+18,'durastal',10),(@obj+18,'elektronika',3),(@obj+19,'paliwo',20),(@obj+19,'elektronika',6),
(@obj+20,'elektronika',2),(@obj+21,'paliwo',240),(@obj+21,'durastal',35),
(@obj+22,'elektronika',14),(@obj+22,'paliwo',12),(@obj+23,'elektronika',5),(@obj+23,'zywnosc',25),
(@obj+24,'paliwo',110),(@obj+24,'elektronika',9),(@obj+24,'zywnosc',100),
(@obj+25,'durastal',12),(@obj+25,'paliwo',18),(@obj+25,'elektronika',3);

-- Jedna linia dla każdej pozycji zużycia, z mniejszym statkiem przy niskim popycie.
SET @lin := (SELECT COALESCE(MAX(id),0) FROM gosp_linie);
INSERT INTO gosp_linie (id,obiekt_id,towar,producent,t_doba,ladownosc_t,klasa,odstep_s,predkosc_au_h,postoj_h,faza,obieg)
SELECT @lin+ROW_NUMBER() OVER (ORDER BY q.obiekt_id,q.towar),q.obiekt_id,q.towar,q.producent,q.t_doba,
 GREATEST(500,IF(q.t_doba<q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek)),q.klasa,
 ROUND(GREATEST(500,IF(q.t_doba<q.typowy_ladunek/7,ROUND(q.t_doba*7,-2),q.typowy_ladunek))/q.t_doba*86400),
 1.7,2.4,ROUND(MOD(q.obiekt_id*0.173+q.t_doba*0.001,1),4),'oficjalny_hurt'
FROM (
 SELECT z.obiekt_id,z.towar,z.t_doba,
  CASE z.towar WHEN 'zywnosc' THEN 'Foundry' WHEN 'paliwo' THEN 'Cassander'
   WHEN 'durastal' THEN 'Nubia' WHEN 'elektronika' THEN 'Alpheridies' WHEN 'luksus' THEN 'Albrae-Don' END AS producent,
  t.ladownosc_t AS typowy_ladunek,t.klasa_transportu AS klasa
 FROM gosp_zuzycie z JOIN gosp_towary t ON t.kod=z.towar
 WHERE z.obiekt_id BETWEEN @obj+1 AND @obj+25
) q;

-- Lądowiska dla powierzchniowych obiektów, zgodne z migracją 049.
INSERT INTO lokalizacje (typ,rodzaj,rodzic_id,uklad_id,planeta_id,nazwa,x_km,y_km,publiczna,status,opis)
SELECT 'ladowisko','platforma',NULL,NULL,p.id,CONCAT('Lądowisko — ',o.nazwa),
 ROUND(o.dl/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),
 ROUND(o.szer/360*PI()*COALESCE(NULLIF(c.srednica_km,0),NULLIF(p.srednica,0)),3),1,'aktywna',o.opis
FROM gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta LEFT JOIN planety_cykle c ON c.planeta_id=p.id
WHERE o.planeta='Albrae-Don' AND o.miejsce='powierzchnia'
ON DUPLICATE KEY UPDATE x_km=VALUES(x_km),y_km=VALUES(y_km),publiczna=1,status='aktywna';
UPDATE gosp_obiekty o JOIN mapa_planety p ON p.nazwa=o.planeta
JOIN lokalizacje l ON l.planeta_id=p.id AND l.nazwa=CONCAT('Lądowisko — ',o.nazwa)
SET o.lokalizacja_id=l.id WHERE o.planeta='Albrae-Don' AND o.miejsce='powierzchnia';

-- Rynek liczony z produkcji i zużycia obiektów oraz z odległości do najbliższego producenta.
INSERT INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi)
SELECT 'Albrae-Don',x.towar,'oficjalny_hurt',x.prod,x.kons,
 ROUND(LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),3),
 ROUND(x.cena*LEAST(2.5,GREATEST(0.5,(1-0.30*(x.prod-x.kons)/NULLIF(x.prod+x.kons,0))*(1+0.00008*x.odl))),2),
 'obiekty','wygenerowane','sumy obiektowe; producent luksusu lokalny, import pozostaje szacunkiem'
FROM (
 SELECT a.towar,a.prod,a.kons,t.cena_bazowa_kr_t AS cena,
  CASE WHEN a.prod>0 THEN 0 ELSE COALESCE((SELECT MIN(SQRT(POW(mp.x-pp.x,2)+POW(mp.y-pp.y,2)))
   FROM gosp_produkcja gp JOIN mapa_planety pp ON pp.nazwa=gp.planeta JOIN mapa_planety mp ON mp.nazwa='Albrae-Don'
   WHERE gp.towar=a.towar AND gp.wydajnosc_t_doba>0),3000) END AS odl
 FROM (
  SELECT k.towar,COALESCE(p.wydajnosc_t_doba,0) AS prod,COALESCE(z.kons,0) AS kons
  FROM (SELECT towar FROM gosp_produkcja WHERE planeta='Albrae-Don'
   UNION SELECT g.towar FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id WHERE o.planeta='Albrae-Don') k
  LEFT JOIN gosp_produkcja p ON p.planeta='Albrae-Don' AND p.towar=k.towar
  LEFT JOIN (SELECT g.towar,SUM(g.t_doba) AS kons FROM gosp_zuzycie g JOIN gosp_obiekty o ON o.id=g.obiekt_id
   WHERE o.planeta='Albrae-Don' GROUP BY g.towar) z ON z.towar=k.towar
 ) a JOIN gosp_towary t ON t.kod=a.towar
) x
ON DUPLICATE KEY UPDATE produkcja_t_doba=VALUES(produkcja_t_doba),zuzycie_t_doba=VALUES(zuzycie_t_doba),cena_indeks=VALUES(cena_indeks),cena_kr_t=VALUES(cena_kr_t),szczegolowosc=VALUES(szczegolowosc),zrodlo=VALUES(zrodlo),uwagi=VALUES(uwagi);

-- Pamięć o dostawcach zewnętrznych; nie nadpisuj planet już opracowanych szczegółowo.
INSERT IGNORE INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi) VALUES
('Foundry','zywnosc','oficjalny_hurt',2118000,0,0.700,84.00,'szacunek','wygenerowane','otwarty wątek: dostawca żywności Albrae-Don'),
('Cassander','paliwo','oficjalny_hurt',343000,0,0.700,420.00,'szacunek','wygenerowane','otwarty wątek: kontrakty paliwowe Albrae-Don'),
('Nubia','durastal','oficjalny_hurt',1466000,0,0.700,245.00,'szacunek','wygenerowane','otwarty wątek: dostawca materiałów dla rzemiosła'),
('Alpheridies','elektronika','oficjalny_hurt',2035000,0,0.700,8400.00,'szacunek','wygenerowane','otwarty wątek: elektronika i systemy dla Draeo-Kax');

INSERT INTO schema_migracje (wersja,opis) VALUES ('v52-gospodarka-albrae-don','Gospodarka Albrae-Don: rzemiosło luksusowe i tranzyt')
ON DUPLICATE KEY UPDATE opis=VALUES(opis);

-- Kontrole po imporcie.
SELECT COUNT(*) AS obiekty_albrae_don FROM gosp_obiekty WHERE planeta='Albrae-Don';
SELECT l.id,l.producent,l.towar FROM gosp_linie l LEFT JOIN gosp_produkcja p ON p.planeta=l.producent AND p.towar=l.towar WHERE l.obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Albrae-Don') AND p.planeta IS NULL;
SELECT l.id FROM gosp_linie l LEFT JOIN gosp_obiekty o ON o.id=l.obiekt_id WHERE o.id IS NULL;
SELECT o.id,o.nazwa FROM gosp_obiekty o LEFT JOIN lokalizacje l ON l.id=o.lokalizacja_id WHERE o.planeta='Albrae-Don' AND o.miejsce='powierzchnia' AND l.id IS NULL;
SELECT SUM(86400/odstep_s) AS dostawy_na_dobe FROM gosp_linie WHERE obiekt_id IN (SELECT id FROM gosp_obiekty WHERE planeta='Albrae-Don');

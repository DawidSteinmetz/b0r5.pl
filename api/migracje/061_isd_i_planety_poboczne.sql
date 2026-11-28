-- Garnizony imperialne na pobocznych planetach pozostających pod kontrolą Imperium.
-- Wybrane planety: Tatooine, Lothal, Foundry i Mygeeto.
-- Nar Shaddaa i Ord Mantell (światy przemytnicze) są pominięte.
-- Wymaga migracji 009_flota_dromund_kaas.sql oraz tabel mapa_planety, mapa_floty,
-- mapa_obiekty, mapa_floty_jednostki, mapa_naped_jednostki, podmioty i osoby.
-- Można uruchamiać ponownie: stałe kody zapobiegają duplikatom.

SET NAMES utf8mb4;

DROP TEMPORARY TABLE IF EXISTS tmp_isd_garnizony;
CREATE TEMPORARY TABLE tmp_isd_garnizony (
  planeta VARCHAR(120) NOT NULL PRIMARY KEY,
  kod VARCHAR(80) NOT NULL,
  nazwa_okretu VARCHAR(160) NOT NULL,
  kod_floty VARCHAR(80) NOT NULL,
  nazwa_floty VARCHAR(160) NOT NULL,
  kapitan VARCHAR(160) NOT NULL
) ENGINE=MEMORY;

INSERT INTO tmp_isd_garnizony (planeta,kod,nazwa_okretu,kod_floty,nazwa_floty,kapitan) VALUES
('Tatooine','tatooine-isd-i-bezlitosny','ISD I „Bezlitosny”','tatooine-garnizon-imperialny','Garnizon Imperialny Tatooine','Kapitan Varek Solan'),
('Lothal','lothal-isd-i-wlocznia','ISD I „Włócznia Lothal”','lothal-garnizon-imperialny','Garnizon Imperialny Lothal','Kapitan Nira Venn'),
('Foundry','foundry-isd-i-mlot-denus','ISD I „Młot Denus”','foundry-garnizon-imperialny','Garnizon Imperialny Foundry','Kapitan Jorad Kelm'),
('Mygeeto','mygeeto-isd-i-lodowy-bastion','ISD I „Lodowy Bastion”','mygeeto-garnizon-imperialny','Garnizon Imperialny Mygeeto','Kapitan Sera Vaylen');

INSERT INTO podmioty (typ,nazwa)
SELECT 'osoba', g.kapitan
FROM tmp_isd_garnizony g
WHERE NOT EXISTS (
  SELECT 1 FROM podmioty p
  WHERE p.typ='osoba'
    AND p.nazwa COLLATE utf8mb4_unicode_ci = g.kapitan COLLATE utf8mb4_unicode_ci
);

INSERT INTO osoby (podmiot_id,rola,stan,pula_kandydatow)
SELECT p.id,'kapitan imperialnej floty','zdrowy','brak'
FROM tmp_isd_garnizony g
JOIN podmioty p ON p.typ='osoba'
  AND p.nazwa COLLATE utf8mb4_unicode_ci = g.kapitan COLLATE utf8mb4_unicode_ci
LEFT JOIN osoby o ON o.podmiot_id=p.id
WHERE o.podmiot_id IS NULL;

INSERT INTO mapa_floty (kod,nazwa,dowodca_login,dowodca_osoba_id,opis)
SELECT g.kod_floty,g.nazwa_floty,NULL,p.id,
       CONCAT('Imperialny garnizon planetarny z okrętem flagowym ',g.nazwa_okretu,
              ', pod dowództwem ',g.kapitan,'.')
FROM tmp_isd_garnizony g
JOIN podmioty p ON p.typ='osoba'
  AND p.nazwa COLLATE utf8mb4_unicode_ci = g.kapitan COLLATE utf8mb4_unicode_ci
ON DUPLICATE KEY UPDATE
  nazwa=VALUES(nazwa),
  dowodca_osoba_id=COALESCE(mapa_floty.dowodca_osoba_id,VALUES(dowodca_osoba_id)),
  status=IF(mapa_floty.status='nieaktywna','aktywna',mapa_floty.status),
  opis=COALESCE(mapa_floty.opis,VALUES(opis));

INSERT IGNORE INTO mapa_obiekty
  (kod,typ,nazwa,rodzaj,klasa,wlasciciel,status,planeta_id,x,y,kolor,znacznik,opis,aktywny)
SELECT g.kod,'statek',g.nazwa_okretu,'okręt','ISD I','Imperium','sprawny',p.id,
       p.x+10,p.y+10,'#00ff66','trojkat',
       CONCAT('Okręt flagowy imperialnego garnizonu planety ',g.planeta,'.'),1
FROM tmp_isd_garnizony g
JOIN mapa_planety p
  ON p.nazwa COLLATE utf8mb4_unicode_ci = g.planeta COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO mapa_floty_jednostki (obiekt_id,flota_id,rola,kolejnosc,tie_liczba)
SELECT o.id,f.id,'okręt flagowy',0,NULL
FROM tmp_isd_garnizony g
JOIN mapa_obiekty o
  ON o.kod COLLATE utf8mb4_unicode_ci = g.kod COLLATE utf8mb4_unicode_ci
JOIN mapa_floty f
  ON f.kod COLLATE utf8mb4_unicode_ci = g.kod_floty COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO mapa_naped_jednostki (obiekt_id)
SELECT o.id
FROM tmp_isd_garnizony g
JOIN mapa_obiekty o
  ON o.kod COLLATE utf8mb4_unicode_ci = g.kod COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migracje (wersja,opis)
VALUES ('v60-isd-planety-poboczne','Imperialne ISD I z kapitanami NPC na Tatooine, Lothal, Foundry i Mygeeto');

DROP TEMPORARY TABLE IF EXISTS tmp_isd_garnizony;

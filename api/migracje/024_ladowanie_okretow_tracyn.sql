-- Lądowanie i start małych jednostek z SQL oraz myśliwiec-transportowiec Kom'rk „Tracyn” Venki Tary.
--
-- mapa_obiekty.ladowisko_id: jednostka stoi na lądowisku (lokalizacje.id); NULL = w przestrzeni.
-- mapa_obiekty.start_utc: chwila startu (wznoszenie przez atmosferę na pulpicie załogi).
-- mapa_naped_jednostki.moze_ladowac: tylko małe jednostki lądują (ISD nigdy — chyba że się rozbije).
-- Znacznik 'komrk': sylwetka Kom'rka na mapie (obrócona według kursu).
--
-- Tracyn: własna jednoosobowa flota z dowódcą Tara (Venka Tara), stoi w porcie kosmicznym Nar Shaddaa,
-- Venka na pokładzie (kokpit). Wymaga migracji 023 i paczki Nar Shaddaa (lokacja portu).
-- Import w phpMyAdmin: zakładka SQL albo Import. Importuj raz.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_obiekty
  ADD COLUMN ladowisko_id BIGINT UNSIGNED NULL AFTER planeta_id,
  ADD COLUMN start_utc DATETIME NULL AFTER przylot_utc,
  MODIFY znacznik ENUM('kolo','trojkat','kwadrat','romb','komrk') NOT NULL DEFAULT 'kolo';

ALTER TABLE mapa_naped_jednostki
  ADD COLUMN moze_ladowac TINYINT(1) NOT NULL DEFAULT 0 AFTER kadlub;

-- Małe jednostki istniejących flot mogą lądować (Gozanti).
UPDATE mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id
  SET n.moze_ladowac = 1 WHERE o.klasa LIKE '%Gozanti%' OR o.nazwa LIKE 'Gozanti%';

-- ---------- Tracyn ----------
SET @nar := (SELECT id FROM mapa_planety WHERE nazwa = 'Nar Shaddaa' LIMIT 1);
SET @port := (SELECT id FROM lokalizacje WHERE planeta_id = @nar AND nazwa = 'Port kosmiczny Nar Shaddaa' LIMIT 1);

INSERT INTO mapa_obiekty (kod, typ, nazwa, rodzaj, klasa, wlasciciel, frakcja, status, planeta_id, ladowisko_id, x, y,
                          kurs_deg, podejscie, kolor, znacznik, opis, aktywny)
SELECT 'tracyn', 'statek', 'Tracyn', 'myśliwiec-transportowiec', 'Kom''rk', 'Venka Tara', 'Syndykat', 'na lądowisku',
       @nar, @port, p.x, p.y, 90, 'bliska', '#4de2c9', 'komrk', 'Myśliwiec-transportowiec klasy Kom''rk.', 1
FROM mapa_planety p WHERE p.id = @nar
  AND NOT EXISTS (SELECT 1 FROM mapa_obiekty WHERE kod = 'tracyn');

SET @tracyn := (SELECT id FROM mapa_obiekty WHERE kod = 'tracyn' LIMIT 1);

INSERT IGNORE INTO mapa_naped_jednostki (obiekt_id, moc_reaktora, moc_hipernapedu, klasa_hipernapedu, predkosc_pc_na_godzine,
  paliwo, paliwo_maks, paliwo_na_parsek, sprawnosc_reaktora, sprawnosc_hipernapedu, szum_bazowy,
  obrot_deg_na_min, komputer_klasa, komputer_stan, oslony, kadlub, moze_ladowac)
SELECT @tracyn, 100, 60, 1.00, 1000, 5000, 5000, 0.4000, 100, 100, 0.05, 120, 4, 'sprawny', 100, 100, 1
FROM DUAL WHERE @tracyn IS NOT NULL;

INSERT INTO mapa_floty (kod, nazwa, dowodca_login, status, opis)
SELECT 'tracyn', 'Tracyn', 'Tara', 'aktywna', 'Jednostka Venki Tary.' FROM DUAL
WHERE @tracyn IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mapa_floty WHERE kod = 'tracyn');

SET @flota := (SELECT id FROM mapa_floty WHERE kod = 'tracyn' LIMIT 1);
INSERT IGNORE INTO mapa_floty_jednostki (flota_id, obiekt_id, rola, kolejnosc)
SELECT @flota, @tracyn, 'flagowy', 1 FROM DUAL WHERE @flota IS NOT NULL AND @tracyn IS NOT NULL;

INSERT IGNORE INTO mapa_obiekty_dostep (obiekt_id, konto_login)
SELECT @tracyn, 'Tara' FROM DUAL WHERE @tracyn IS NOT NULL;

-- Venka Tara na pokładzie Tracyna (karta postaci: lokacja okret:<id>; znacznik postaci na mapie: na pokładzie).
UPDATE datapad_dane SET payload = JSON_SET(payload,
    '$."482d5fe5bef7".pozycja.lokacja', CONCAT('okret:', @tracyn),
    '$."482d5fe5bef7".pozycja.zmieniono', CAST(UNIX_TIMESTAMP() * 1000 AS UNSIGNED))
WHERE nazwa = 'karty' AND @tracyn IS NOT NULL AND JSON_CONTAINS_PATH(payload, 'one', '$."482d5fe5bef7".pozycja');
UPDATE mapa_obiekty SET na_pokladzie_id = @tracyn, planeta_id = @nar
WHERE legacy_uid = '482d5fe5bef7' AND typ = 'postac' AND @tracyn IS NOT NULL;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v24-ladowanie-tracyn', 'Lądowanie i start małych jednostek, Kom''rk „Tracyn” Venki Tary na Nar Shaddaa')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

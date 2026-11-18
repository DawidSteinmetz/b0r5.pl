-- Nar Shaddaa jako księżyc Nal Hutty, typ „pionowe miasto” i (ponownie) Tracyn Venki Tary.
--
-- Mapa miała jeden wpis „Nal Hutta & Nar Shaddaa” (id 1507). Rozdzielamy go: 1507 → „Nal Hutta”
-- (planeta, bagienna), nowy wpis „Nar Shaddaa” (księżyc-miasto) w tym samym układzie. Wszystkie
-- lokacje i postacie przypisane do 1507 (port, hotel, kantyna… z paczki Nar Shaddaa) przechodzą
-- na Nar Shaddaa. planety_cykle.macierz_planeta_id: księżyc krąży wokół planety macierzystej
-- (słońce i rok z jej orbity; na niebie księżyca widać planetę macierzystą).
-- Migracja 024 nie dodała Tracyna (szukała planety „Nar Shaddaa”, której nie było) — robimy to tutaj,
-- razem z właścicielem i lokalizatorem z migracji 025.
--
-- Wymaga migracji 025. Import w phpMyAdmin: zakładka SQL albo Import. Importuj raz.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE planety_cykle
  ADD COLUMN macierz_planeta_id INT UNSIGNED NULL AFTER planeta_id,
  ADD COLUMN promien_wokol_km DECIMAL(14,1) NULL AFTER macierz_planeta_id,
  ADD COLUMN okres_wokol_dni DECIMAL(12,4) NULL AFTER promien_wokol_km,
  ADD COLUMN faza_wokol_deg DECIMAL(7,3) NULL AFTER okres_wokol_dni;

ALTER TABLE planety_gry
  MODIFY typ_planety ENUM('pustynna','lodowa','oceaniczna','lesna','wulkaniczna','gazowa','miasto','bagienna','skalista','pionowe_miasto') NULL;

-- ---------- rozdzielenie Nal Hutty i Nar Shaddaa ----------
SET @nh := (SELECT id FROM mapa_planety WHERE nazwa IN ('Nal Hutta & Nar Shaddaa', 'Nal Hutta') ORDER BY nazwa = 'Nal Hutta' DESC LIMIT 1);
UPDATE mapa_planety SET nazwa = 'Nal Hutta', doba = 87, rok = 413, ksiezyce = GREATEST(ksiezyce, 1),
  srednica = IF(srednica > 0, srednica, 12150), grawitacja = IF(grawitacja > 0, grawitacja, 1.0), slonca = GREATEST(slonca, 1)
  WHERE id = @nh;

SET @nowy := (SELECT MAX(id) + 1 FROM mapa_planety);
INSERT INTO mapa_planety (id, nazwa, region, x, y, grawitacja, slonca, ksiezyce, doba, rok, srednica)
SELECT @nowy, 'Nar Shaddaa', region, x + 0.4, y - 0.3, 0.9, slonca, 0, 87, 413, 4400 FROM mapa_planety
WHERE id = @nh AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM mapa_planety WHERE nazwa = 'Nar Shaddaa') t);
SET @ns := (SELECT id FROM mapa_planety WHERE nazwa = 'Nar Shaddaa' LIMIT 1);

INSERT INTO planety_gry (planeta_id, typ_planety, urbanizacja, kontrola_lotow, zastrzezona, opis)
VALUES (@ns, 'pionowe_miasto', 'wysoka', 'Kontrola portu Nar Shaddaa', 0, 'Księżyc-miasto Nal Hutty, Przemytniczy Księżyc.')
ON DUPLICATE KEY UPDATE typ_planety = 'pionowe_miasto', urbanizacja = 'wysoka';
INSERT INTO planety_gry (planeta_id, typ_planety, urbanizacja, zastrzezona, opis)
VALUES (@nh, 'bagienna', 'niska', 0, 'Planeta Huttów.')
ON DUPLICATE KEY UPDATE typ_planety = 'bagienna';

-- lokacje i postacie z dawnego wspólnego wpisu są na księżycu
UPDATE lokalizacje SET planeta_id = @ns WHERE planeta_id = @nh AND @ns IS NOT NULL;
UPDATE mapa_obiekty SET planeta_id = @ns WHERE planeta_id = @nh AND typ = 'postac' AND @ns IS NOT NULL;

-- cykle: Nal Hutta (doba 87 h, rok 413 dni) i księżyc Nar Shaddaa na jej orbicie
INSERT INTO planety_cykle (planeta_id, doba_h, rok_dni, srednica_km) VALUES (@nh, 87, 413, 12150)
ON DUPLICATE KEY UPDATE doba_h = 87, rok_dni = 413;
INSERT INTO planety_cykle (planeta_id, macierz_planeta_id, promien_wokol_km, okres_wokol_dni, faza_wokol_deg, doba_h, srednica_km)
VALUES (@ns, @nh, 520000, 20.4, 40, 87, 4400)
ON DUPLICATE KEY UPDATE macierz_planeta_id = @nh, promien_wokol_km = 520000, okres_wokol_dni = 20.4, faza_wokol_deg = 40;
INSERT INTO planety_ksiezyce (planeta_id, nazwa, promien_orbity_km, okres_dni, faza_deg, srednica_km)
VALUES (@nh, 'Nar Shaddaa', 520000, 20.4, 40, 4400)
ON DUPLICATE KEY UPDATE promien_orbity_km = 520000, okres_dni = 20.4, faza_deg = 40, srednica_km = 4400;

-- ---------- Tracyn (prywatny Kom'rk Venki Tary) ----------
SET @port := (SELECT id FROM lokalizacje WHERE planeta_id = @ns AND nazwa = 'Port kosmiczny Nar Shaddaa' LIMIT 1);
INSERT INTO mapa_obiekty (kod, typ, nazwa, rodzaj, klasa, wlasciciel, wlasciciel_login, lokalizator_zasieg_pc, lokalizator_aktywny,
                          frakcja, status, planeta_id, ladowisko_id, x, y, kurs_deg, podejscie, kolor, znacznik, opis, aktywny)
SELECT 'tracyn', 'statek', 'Tracyn', 'myśliwiec-transportowiec', 'Kom''rk', 'Venka Tara', 'Tara', 500, 1,
       'Syndykat', 'na lądowisku', @ns, @port, p.x, p.y, 90, 'bliska', '#4de2c9', 'komrk', 'Myśliwiec-transportowiec klasy Kom''rk.', 1
FROM mapa_planety p WHERE p.id = @ns AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM mapa_obiekty WHERE kod = 'tracyn') t);
UPDATE mapa_obiekty SET wlasciciel_login = 'Tara', lokalizator_zasieg_pc = 500, planeta_id = @ns,
  ladowisko_id = COALESCE(ladowisko_id, @port) WHERE kod = 'tracyn';
SET @tracyn := (SELECT id FROM mapa_obiekty WHERE kod = 'tracyn' LIMIT 1);

INSERT IGNORE INTO mapa_naped_jednostki (obiekt_id, moc_reaktora, moc_hipernapedu, klasa_hipernapedu, predkosc_pc_na_godzine,
  paliwo, paliwo_maks, paliwo_na_parsek, sprawnosc_reaktora, sprawnosc_hipernapedu, szum_bazowy,
  obrot_deg_na_min, komputer_klasa, komputer_stan, oslony, kadlub, moze_ladowac, predkosc_podswietlna_kms)
SELECT @tracyn, 100, 60, 1.00, 1000, 5000, 5000, 0.4000, 100, 100, 0.05, 120, 4, 'sprawny', 100, 100, 1, 600
FROM DUAL WHERE @tracyn IS NOT NULL;

INSERT INTO mapa_floty (kod, nazwa, dowodca_login, status, opis)
SELECT 'tracyn', 'Tracyn', 'Tara', 'aktywna', 'Prywatny statek Venki Tary.' FROM DUAL
WHERE @tracyn IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mapa_floty WHERE kod = 'tracyn');
SET @flota := (SELECT id FROM mapa_floty WHERE kod = 'tracyn' LIMIT 1);
INSERT IGNORE INTO mapa_floty_jednostki (flota_id, obiekt_id, rola, kolejnosc)
SELECT @flota, @tracyn, 'flagowy', 1 FROM DUAL WHERE @flota IS NOT NULL AND @tracyn IS NOT NULL;
INSERT IGNORE INTO mapa_obiekty_dostep (obiekt_id, konto_login) SELECT @tracyn, 'Tara' FROM DUAL WHERE @tracyn IS NOT NULL;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v26-nar-shaddaa-ksiezyc-tracyn', 'Nar Shaddaa jako księżyc Nal Hutty (pionowe miasto) i Tracyn Venki Tary')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

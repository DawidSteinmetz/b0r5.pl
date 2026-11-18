-- Prywatne statki z lokalizatorem oraz lot do punktu skoku (wyjście ze studni grawitacyjnej).
--
-- mapa_obiekty.wlasciciel_login: konto właściciela prywatnego statku (Nawigacja: „Twoje statki”).
-- lokalizator_zasieg_pc / lokalizator_aktywny: lokalizator statku (zasięg od pozycji właściciela);
--   MG wyłącza go np. gdy złodziej go znalazł. ostatnia_pozycja(_utc): ostatni odczyt lokalizatora.
-- mapa_naped_jednostki.predkosc_podswietlna_kms: prędkość podświetlna w drodze do punktu skoku
--   (łuk orbity ok. 1/15 tej prędkości). mapa_rozkazy_skokow.punkt_od_utc: początek lotu do punktu skoku.
--
-- Wymaga migracji 024. Import w phpMyAdmin: zakładka SQL albo Import. Importuj raz.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_obiekty
  ADD COLUMN wlasciciel_login VARCHAR(64) NULL AFTER wlasciciel,
  ADD COLUMN lokalizator_zasieg_pc DECIMAL(10,1) NULL AFTER wlasciciel_login,
  ADD COLUMN lokalizator_aktywny TINYINT(1) NOT NULL DEFAULT 1 AFTER lokalizator_zasieg_pc,
  ADD COLUMN ostatnia_pozycja VARCHAR(255) NULL AFTER lokalizator_aktywny,
  ADD COLUMN ostatnia_pozycja_utc DATETIME NULL AFTER ostatnia_pozycja,
  ADD KEY ix_mapa_obiekty_wlasciciel (wlasciciel_login);

ALTER TABLE mapa_naped_jednostki
  ADD COLUMN predkosc_podswietlna_kms DECIMAL(8,1) NOT NULL DEFAULT 300 AFTER moze_ladowac;

ALTER TABLE mapa_rozkazy_skokow
  ADD COLUMN punkt_od_utc DATETIME NULL AFTER wejscie_utc;

-- Prędkości podświetlne według klasy (km/s).
UPDATE mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id SET n.predkosc_podswietlna_kms = 200
  WHERE o.klasa LIKE '%Imperial%' OR o.klasa LIKE '%ISD%' OR o.nazwa LIKE 'ISD%';
UPDATE mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id SET n.predkosc_podswietlna_kms = 350
  WHERE o.klasa LIKE '%Arquitens%' OR o.nazwa LIKE 'Arquitens%';
UPDATE mapa_naped_jednostki n JOIN mapa_obiekty o ON o.id = n.obiekt_id SET n.predkosc_podswietlna_kms = 600
  WHERE o.klasa LIKE '%Kom%rk%';

-- Tracyn: prywatny statek Venki Tary (konto Tara), lokalizator o zasięgu 500 pc.
UPDATE mapa_obiekty SET wlasciciel_login = 'Tara', lokalizator_zasieg_pc = 500, lokalizator_aktywny = 1
  WHERE kod = 'tracyn';

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v25-lokalizator-punkt-skoku', 'Prywatne statki z lokalizatorem, prędkość podświetlna i lot do punktu skoku')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

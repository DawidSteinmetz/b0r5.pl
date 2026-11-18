-- Miejsce na orbicie osobno od kursu, chwila przylotu (faza podejścia) i rozbicie okrętu.
--
-- orbita_deg: po której stronie planety stoi jednostka (układ mapy: 0° = wschód, 90° = północ);
--             od tego zależy dzień/noc pod okrętem i czy wisi między planetą a księżycem.
-- kurs_deg:   kierunek dziobu, niezależny od miejsca na orbicie (widok z mostka, strzałka na mapie).
-- przylot_utc: wyjście z nadprzestrzeni przy planecie; z niego wynika podejście (planeta w oknie
--             najpierw mała, potem średnia, na orbicie duża).
-- rozbity:    okręt rozbity na planecie (ustawia MG); z mostka widać powierzchnię.
--
-- Wymaga migracji 015. Import w phpMyAdmin: zakładka SQL albo Import. Importuj raz; ponowny import zatrzyma się na „Duplicate column”.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_obiekty
  ADD COLUMN orbita_deg DECIMAL(6,2) NULL AFTER kurs_deg,
  ADD COLUMN przylot_utc DATETIME NULL AFTER manewr_do_utc,
  ADD COLUMN rozbity TINYINT(1) NOT NULL DEFAULT 0 AFTER przylot_utc;

-- Dotąd kurs na orbicie oznaczał stronę planety (dziobem od planety). Przenosimy go do orbita_deg,
-- a dziób kierujemy ku planecie — z mostka widać planetę.
UPDATE mapa_obiekty
  SET orbita_deg = kurs_deg,
      kurs_deg = MOD(kurs_deg + 180, 360)
  WHERE orbita_deg IS NULL AND planeta_id IS NOT NULL AND typ IN ('statek', 'jednostka');

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v16-orbita-przylot-rozbicie', 'Miejsce na orbicie osobno od kursu, faza podejścia po skoku, rozbicie okrętu')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

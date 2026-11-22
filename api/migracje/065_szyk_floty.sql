-- Szyk floty: okręty mają stałe miejsca względem prowadzącego (pierwszy według mapa_floty_jednostki.kolejnosc).
--
-- mapa_floty.szyk — 'obronny' (pierścienie wokół prowadzącego: 6, 12, 18… miejsc co odstęp),
--   'eskortowy' (klin za prowadzącym), 'linia' (szereg w poprzek kursu); NULL — bez szyku (dawny wachlarz).
-- mapa_floty.szyk_odstep_km — odstęp między miejscami szyku (domyślnie 8 km).
-- Na mapie flota jest jednym trójkątem, dopóki odstęp na ekranie nie przekroczy ok. 12 px; potem każdy okręt
-- stoi w swoim miejscu (kierunek szyku = kurs prowadzącego).
--
-- Flota Eskortowa Havis III: średnia orbita Havis III, szyk obronny co 8 km — Arquitens 1 prowadzi,
-- Arquitens 2 i Gozanti 1–5 w pierwszym pierścieniu, Gozanti 6 w drugim.
--
-- Import w phpMyAdmin. Ponowny import: ALTER zgłosi „Duplicate column” — wtedy wystarczy reszta pliku.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_floty
  ADD COLUMN szyk VARCHAR(20) NULL,
  ADD COLUMN szyk_odstep_km DECIMAL(8,2) NULL;

-- Domyślny szyk flot obronnych i eskortowych.
UPDATE mapa_floty SET szyk = 'obronny', szyk_odstep_km = 8
WHERE szyk IS NULL AND (nazwa LIKE '%Eskort%' OR nazwa LIKE '%Obron%');

-- Kolejność w szyku Floty Eskortowej Havis III.
UPDATE mapa_floty_jednostki j
JOIN mapa_obiekty o ON o.id = j.obiekt_id
JOIN mapa_floty f ON f.id = j.flota_id AND f.kod = 'havis-iii-eskortowa'
SET j.kolejnosc = CASE o.kod
    WHEN 'havis-arquitens-01' THEN 0
    WHEN 'havis-arquitens-02' THEN 1
    ELSE 1 + CAST(RIGHT(o.kod, 2) AS UNSIGNED) END;

-- Średnia orbita Havis III, wspólne miejsce na orbicie (bez jednostek w nadprzestrzeni, na lądowisku i w hangarze).
UPDATE mapa_obiekty o
JOIN mapa_floty_jednostki j ON j.obiekt_id = o.id
JOIN mapa_floty f ON f.id = j.flota_id AND f.kod = 'havis-iii-eskortowa'
JOIN mapa_planety p ON p.nazwa = 'Havis III'
LEFT JOIN mapa_rozkazy_skokow_jednostki r ON r.obiekt_id = o.id AND r.status = 'w_nadprzestrzeni'
SET o.planeta_id = p.id, o.x = p.x, o.y = p.y, o.podejscie = 'srednia', o.orbita_deg = 90, o.kurs_deg = 0,
    o.podlot_start = NULL, o.podlot_cel = NULL, o.podlot_od_utc = NULL, o.podlot_do_utc = NULL,
    o.kurs_start_deg = NULL, o.kurs_cel_deg = NULL, o.manewr_od_utc = NULL, o.manewr_do_utc = NULL
WHERE r.obiekt_id IS NULL AND o.ladowisko_id IS NULL AND COALESCE(o.w_hangarze, 0) = 0;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v065-szyk-floty', 'Szyk floty (obronny, eskortowy, linia); Flota Eskortowa Havis III na średniej orbicie')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

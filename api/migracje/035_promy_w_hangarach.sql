-- Promy w hangarach okrętów.
--
-- mapa_obiekty.okret_macierzysty_id — okręt, do którego hangaru należy jednostka (prom).
-- mapa_obiekty.w_hangarze (0/1) — jednostka stoi teraz w hangarze okrętu macierzystego: jej pozycja
--   idzie za okrętem, nie ma osobnego znacznika na mapie, nie wykona rozkazu (najpierw wylot).
-- mapa_obiekty.ladowanie_cel_id — lądowisko, na które jednostka sama wyląduje, gdy dotrze na bliską
--   orbitę (prom: „lot na planetę” = wylot + dolot + lądowanie jednym rozkazem).
--
-- Każdy ISD dostaje prom klasy Lambda (np. „Lambda Walako”) w swoim hangarze. Prom jest w osobnej
-- podgrupie floty ISD bez własnego dowódcy, więc dowodzi nim dowódca floty ISD (dziś kpt. Stingert
-- na Walako; kolejni kapitanowie dostają prom razem z okrętem).
--
-- Import w phpMyAdmin. Ponowny import: ALTER zgłosi „Duplicate column” — wtedy wystarczy reszta pliku
-- od INSERT (promy i grupy nie powielą się).

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_obiekty
  ADD COLUMN okret_macierzysty_id BIGINT UNSIGNED NULL,
  ADD COLUMN w_hangarze TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN ladowanie_cel_id BIGINT UNSIGNED NULL,
  ADD KEY ix_mapa_obiekty_hangar (okret_macierzysty_id, w_hangarze),
  ADD CONSTRAINT fk_mapa_obiekty_macierzysty FOREIGN KEY (okret_macierzysty_id) REFERENCES mapa_obiekty (id) ON DELETE SET NULL;

-- Prom Lambda w hangarze każdego ISD (nazwa: „Lambda ” + nazwa okrętu bez klasy, np. Walako).
INSERT INTO mapa_obiekty (kod, typ, nazwa, rodzaj, klasa, wlasciciel, frakcja, status, planeta_id, x, y, kolor, znacznik, opis, aktywny,
                          okret_macierzysty_id, w_hangarze, kurs_deg, orbita_deg, podejscie, dlugosc_m)
SELECT CONCAT('prom-', i.kod),
       'statek',
       CONCAT('Lambda ', TRIM(REPLACE(REPLACE(REPLACE(REPLACE(i.nazwa, 'ISD I ', ''), '„', ''), '”', ''), '"', ''))),
       'prom', 'Lambda T-4a', i.wlasciciel, i.frakcja, 'w hangarze', i.planeta_id, i.x, i.y, i.kolor, 'trojkat',
       CONCAT('Prom klasy Lambda z hangaru ', i.nazwa, '.'), 1,
       i.id, 1, i.kurs_deg, i.orbita_deg, i.podejscie, 20
FROM mapa_obiekty i
WHERE i.typ = 'statek' AND i.aktywny = 1 AND i.okret_macierzysty_id IS NULL
  AND (i.klasa LIKE '%ISD%' OR i.klasa LIKE '%Imperial%')
  AND NOT EXISTS (SELECT 1 FROM mapa_obiekty p WHERE p.kod = CONCAT('prom-', i.kod));

-- Napęd promu: hipernapęd klasy 1, mały zbiornik, zwrotny, może lądować.
INSERT IGNORE INTO mapa_naped_jednostki (obiekt_id, moc_reaktora, moc_hipernapedu, klasa_hipernapedu, predkosc_pc_na_godzine,
  paliwo, paliwo_maks, paliwo_na_parsek, sprawnosc_reaktora, sprawnosc_hipernapedu, szum_bazowy,
  obrot_deg_na_min, komputer_klasa, komputer_stan, oslony, kadlub, moze_ladowac)
SELECT p.id, 100, 60, 1.00, 1000, 4000, 4000, 0.4000, 100, 100, 0.05, 120, 3, 'sprawny', 100, 100, 1
FROM mapa_obiekty p WHERE p.kod LIKE 'prom-%' AND p.okret_macierzysty_id IS NOT NULL;

-- Podgrupa floty ISD na prom (bez dowódcy: dziedziczy dowódcę floty ISD).
INSERT INTO mapa_floty (kod, nazwa, rodzic_id, status, opis)
SELECT CONCAT('grupa-', p.kod), p.nazwa, j.flota_id, 'aktywna', CONCAT('Prom z hangaru ', i.nazwa, '.')
FROM mapa_obiekty p
JOIN mapa_obiekty i ON i.id = p.okret_macierzysty_id
JOIN mapa_floty_jednostki j ON j.obiekt_id = i.id
WHERE p.kod LIKE 'prom-%'
  AND NOT EXISTS (SELECT 1 FROM mapa_floty f WHERE f.kod = CONCAT('grupa-', p.kod));

INSERT IGNORE INTO mapa_floty_jednostki (flota_id, obiekt_id, rola, kolejnosc)
SELECT f.id, p.id, 'prom', 1
FROM mapa_obiekty p JOIN mapa_floty f ON f.kod = CONCAT('grupa-', p.kod)
WHERE p.kod LIKE 'prom-%';

-- Kto widzi ISD na mapie, widzi też jego prom (po wylocie).
INSERT IGNORE INTO mapa_obiekty_dostep (obiekt_id, konto_login)
SELECT p.id, d.konto_login
FROM mapa_obiekty p JOIN mapa_obiekty_dostep d ON d.obiekt_id = p.okret_macierzysty_id
WHERE p.kod LIKE 'prom-%';

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v35-promy-w-hangarach', 'Promy Lambda w hangarach ISD: wylot, lot na planetę z lądowaniem, powrót do hangaru')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

-- Kontrola: promy i ich okręty.
SELECT p.id, p.nazwa, i.nazwa AS okret, p.w_hangarze, f.nazwa AS grupa
FROM mapa_obiekty p JOIN mapa_obiekty i ON i.id = p.okret_macierzysty_id
LEFT JOIN mapa_floty_jednostki j ON j.obiekt_id = p.id LEFT JOIN mapa_floty f ON f.id = j.flota_id
WHERE p.kod LIKE 'prom-%';

-- Śledztwa droida: drogi między budynkami — zjazd windą na parter i chodnik Nar Shaddaa albo mostek między
-- budynkami na wybranym poziomie. Każdy przejazd jest zapisany: odcinki (winda / chodnik / mostek) z czasami,
-- mijane lokale (także z szumu: parter budynków przy chodniku) i mijani ludzie. Po dotarciu droga trafia do
-- dziennika, a mijane lokale — do spisu „Po drodze” w zakładce Rejon.
-- Do zaimportowania wielokrotnie (CREATE TABLE IF NOT EXISTS; przenumerowanie z warunkiem). Wymaga 082–094.

CREATE TABLE IF NOT EXISTS sledztwo_drogi (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  wyruszyl_utc DATETIME NOT NULL,
  dotrze_utc DATETIME NOT NULL,
  z_lokalizacji BIGINT UNSIGNED NULL,
  do_lokalizacji BIGINT UNSIGNED NULL,
  dane MEDIUMTEXT NOT NULL,                       -- JSON: odcinki, mijane lokale, mijani ludzie
  zapisana TINYINT(1) NOT NULL DEFAULT 0,         -- 1 = trafiła do dziennika (droid dotarł)
  KEY ix_sledztwo_drogi (sledztwo_id, dotrze_utc),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v095-sledztwa-drogi', 'Śledztwa: drogi między budynkami (chodnik / mostek), mijane lokale i ludzie')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

-- ---------------------------------------------------------------------
-- Numeracja lokali: piętro 41 → lokale 4101–4199. Starsze sprawy miały lokale fabularne z literą (np. „K-07”,
-- „L-07”). Dostają numer poziom + 90–99 (z id miejsca); wszystkie odwołania (przedmioty, tropy, rozmowy, namiary,
-- straż, przeszukania, pozycja i cel droida) zmieniają się razem. Ponowny import nic nie zmienia (warunek REGEXP).
-- ---------------------------------------------------------------------

UPDATE sledztwo_przedmioty x JOIN sledztwo_miejsca m ON m.sledztwo_id = x.sledztwo_id AND m.lokalizacja_id <=> x.lokalizacja_id
    AND m.poziom = x.poziom AND m.pokoj = x.pokoj
  SET x.pokoj = CONCAT(m.poziom, LPAD(90 + m.id % 10, 2, '0')) WHERE m.pokoj NOT REGEXP '^[0-9]+$';
UPDATE sledztwo_tropy x JOIN sledztwo_miejsca m ON m.sledztwo_id = x.sledztwo_id AND m.lokalizacja_id <=> x.lokalizacja_id
    AND m.poziom = x.poziom AND m.pokoj = x.pokoj
  SET x.pokoj = CONCAT(m.poziom, LPAD(90 + m.id % 10, 2, '0')) WHERE m.pokoj NOT REGEXP '^[0-9]+$';
UPDATE sledztwo_rozmowy x JOIN sledztwo_miejsca m ON m.sledztwo_id = x.sledztwo_id AND m.lokalizacja_id <=> x.lokalizacja_id
    AND m.poziom = x.poziom AND m.pokoj = x.pokoj
  SET x.pokoj = CONCAT(m.poziom, LPAD(90 + m.id % 10, 2, '0')) WHERE m.pokoj NOT REGEXP '^[0-9]+$';
UPDATE sledztwo_straz x JOIN sledztwo_miejsca m ON m.sledztwo_id = x.sledztwo_id AND m.lokalizacja_id <=> x.lokalizacja_id
    AND m.poziom = x.poziom AND m.pokoj = x.pokoj
  SET x.pokoj = CONCAT(m.poziom, LPAD(90 + m.id % 10, 2, '0')) WHERE m.pokoj NOT REGEXP '^[0-9]+$';
UPDATE sledztwo_przeszukania x JOIN sledztwo_miejsca m ON m.sledztwo_id = x.sledztwo_id AND m.lokalizacja_id <=> x.lokalizacja_id
    AND m.poziom = x.poziom AND m.pokoj = x.pokoj
  SET x.pokoj = CONCAT(m.poziom, LPAD(90 + m.id % 10, 2, '0')) WHERE m.pokoj NOT REGEXP '^[0-9]+$';
UPDATE sledztwo_namiary x JOIN sledztwo_miejsca m ON m.sledztwo_id = x.sledztwo_id AND m.lokalizacja_id <=> x.lokalizacja_id
    AND m.poziom = x.poziom AND m.pokoj = x.pokoj
  SET x.klucz = CONCAT(IFNULL(m.lokalizacja_id, 0), '|', m.poziom, '|', m.poziom, LPAD(90 + m.id % 10, 2, '0')),
      x.nazwa = REPLACE(x.nazwa, m.pokoj, CONCAT(m.poziom, LPAD(90 + m.id % 10, 2, '0'))),
      x.pokoj = CONCAT(m.poziom, LPAD(90 + m.id % 10, 2, '0'))
  WHERE m.pokoj NOT REGEXP '^[0-9]+$';
UPDATE sledztwo_pozycje p JOIN sledztwo_droid d ON d.sledztwo_id = p.sledztwo_id
    JOIN sledztwo_miejsca m ON m.sledztwo_id = p.sledztwo_id AND m.lokalizacja_id <=> d.lokalizacja_id AND m.poziom = d.poziom AND m.pokoj = p.pokoj
  SET p.pokoj = CONCAT(m.poziom, LPAD(90 + m.id % 10, 2, '0')) WHERE m.pokoj NOT REGEXP '^[0-9]+$';
UPDATE sledztwo_droid x JOIN sledztwo_miejsca m ON m.sledztwo_id = x.sledztwo_id AND m.lokalizacja_id <=> x.lokalizacja_id
    AND m.poziom = x.poziom AND m.pokoj = x.pokoj
  SET x.pokoj = CONCAT(m.poziom, LPAD(90 + m.id % 10, 2, '0')) WHERE m.pokoj NOT REGEXP '^[0-9]+$';
UPDATE sledztwo_droid x JOIN sledztwo_miejsca m ON m.sledztwo_id = x.sledztwo_id AND m.lokalizacja_id <=> x.cel_lokalizacja_id
    AND m.poziom = x.cel_poziom AND m.pokoj = x.cel_pokoj
  SET x.cel_pokoj = CONCAT(m.poziom, LPAD(90 + m.id % 10, 2, '0')) WHERE m.pokoj NOT REGEXP '^[0-9]+$';
UPDATE sledztwo_miejsca
  SET nazwa = REPLACE(nazwa, pokoj, CONCAT(poziom, LPAD(90 + id % 10, 2, '0'))), pokoj = CONCAT(poziom, LPAD(90 + id % 10, 2, '0'))
  WHERE pokoj NOT REGEXP '^[0-9]+$';

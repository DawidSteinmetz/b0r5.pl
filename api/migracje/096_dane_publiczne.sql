-- DANE PUBLICZNE (28.11.2026): linie turystyczne z rozkładami i jawne widoki gospodarki dla gości mapy.
--
-- • turystyka_linie / turystyka_przystanki — regularne linie pasażerskie między jawnymi światami. Przystanki mają
--   tylko kolejność i postój; czas przelotu liczy serwer z odległości planet (jak linie dostaw w gospodarce).
--   Rozkład: odjazd z pierwszego przystanku co `odstep_h` godzin (przesunięcie `faza_h`), kurs powrotny w połowie
--   odstępu, w odwrotnej kolejności przystanków.
-- • publiczne_kontrakty — stałe kontrakty dostaw (gosp_linie, obieg oficjalny): kto, komu, co, ile i jak często.
--   Bez godzin startu i lądowania, tras, punktów skoku i konkretnych statków.
-- • publiczny_rynek — produkcja, zużycie i cena lokalna każdego towaru na planecie (gosp_rynek, obieg oficjalny).
-- Planety zastrzeżone (planety_gry.zastrzezona = 1) odfiltrowuje API.
--
-- Import w phpMyAdmin (po 050). Ponowny import jest bezpieczny: tabele IF NOT EXISTS, linie nadpisywane,
-- przystanki wpisywane od nowa, widoki CREATE OR REPLACE.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS turystyka_linie (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  kod VARCHAR(12) NOT NULL,
  nazwa VARCHAR(120) NOT NULL,
  operator VARCHAR(120) NOT NULL,
  statek_klasa VARCHAR(80) NOT NULL,
  pasazerow INT UNSIGNED NOT NULL,
  odstep_h DECIMAL(6,1) NOT NULL COMMENT 'co ile godzin odjazd z pierwszego przystanku',
  faza_h DECIMAL(6,1) NOT NULL DEFAULT 0 COMMENT 'przesunięcie rozkładu',
  cena_kr INT UNSIGNED NOT NULL COMMENT 'bilet na całą trasę (klasa standardowa)',
  opis VARCHAR(300) NOT NULL DEFAULT '',
  aktywna TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_turystyka_linie_kod (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS turystyka_przystanki (
  linia_id INT UNSIGNED NOT NULL,
  kolejnosc TINYINT UNSIGNED NOT NULL,
  planeta VARCHAR(160) NOT NULL,
  postoj_h DECIMAL(5,1) NOT NULL DEFAULT 6 COMMENT 'postój na przystanku (pierwszy i ostatni: obsługa statku)',
  PRIMARY KEY (linia_id, kolejnosc),
  KEY ix_turystyka_przystanki_planeta (planeta),
  FOREIGN KEY (linia_id) REFERENCES turystyka_linie (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO turystyka_linie (id, kod, nazwa, operator, statek_klasa, pasazerow, odstep_h, faza_h, cena_kr, opis) VALUES
(1, 'CR-01', 'Klejnoty Jądra', 'Linie Gwiezdne Coruscant', 'liniowiec pasażerski', 1200, 24, 0, 2400, 'Stolica, ogrody Chandrili, stocznie i bazary Corellii, kurorty Corulagu.'),
(2, 'NB-02', 'Ekspres Jezior Naboo', 'Królewska Żegluga Naboo', 'jacht wycieczkowy', 180, 48, 6, 3900, 'Bezpośredni przelot do Theed i Krainy Jezior.'),
(3, 'BS-03', 'Miasto w Chmurach', 'Przewozy Corelliańskie', 'prom turystyczny', 320, 36, 12, 1800, 'Corellia – Bespin: zachody słońca nad Miastem w Chmurach, kasyna i platformy widokowe.'),
(4, 'CT-04', 'Szlak Kasyn', 'Canto Bight Cruises', 'luksusowy krążownik wycieczkowy', 900, 72, 18, 7600, 'Coruscant, Canto Bight na Cantonice i bulwary Zeltros.'),
(5, 'HP-05', 'Wyspy Hapes', 'Linie Gwiezdne Coruscant', 'jacht wycieczkowy', 150, 96, 30, 8200, 'Chandrila – Gromada Hapes: wyspy, pałace i targi jedwabiu.'),
(6, 'OR-06', 'Zachodnie Rubieże', 'Ord Mantell Transit', 'liniowiec pasażerski', 1000, 48, 4, 2100, 'Ord Mantell, Eriadu i podziemne miasta Sullustu.'),
(7, 'HT-07', 'Pętla Hutta', 'Przewozy Desilijic', 'prom turystyczny', 400, 72, 40, 1500, 'Nar Shaddaa, wyścigi ścigaczy na Tatooine i jaskinie Ryloth. Podróż na własne ryzyko.'),
(8, 'TK-08', 'Zamek nad jeziorem', 'Esseles Starways', 'jacht wycieczkowy', 120, 120, 50, 4300, 'Esseles – Takodana: lasy, jezioro i słynna gospoda.'),
(9, 'OC-09', 'Oceany i ogrody', 'Przewozy Corelliańskie', 'liniowiec pasażerski', 800, 72, 22, 3300, 'Corellia, żywe ogrody Ithoru i podwodne miasta Manaan.'),
(10, 'AB-10', 'Uzdrowiska Kolonii', 'Denon Spaceways', 'prom turystyczny', 450, 48, 16, 1700, 'Denon, wodospady Abregado-rae i wyspy Bestine.'),
(11, 'SF-11', 'Safari Felucji', 'Rodiańskie Linie Myśliwskie', 'prom turystyczny', 200, 96, 60, 2600, 'Rodia – Felucia: wyprawy w dżunglę grzybów.'),
(12, 'PR-12', 'Prerie Zewnętrznych Rubieży', 'Lothal Starlines', 'prom turystyczny', 260, 120, 70, 1400, 'Lothal – Dantooine: trawiaste równiny, spokój z dala od Jądra.')
ON DUPLICATE KEY UPDATE kod = VALUES(kod), nazwa = VALUES(nazwa), operator = VALUES(operator), statek_klasa = VALUES(statek_klasa),
  pasazerow = VALUES(pasazerow), odstep_h = VALUES(odstep_h), faza_h = VALUES(faza_h), cena_kr = VALUES(cena_kr), opis = VALUES(opis);

DELETE FROM turystyka_przystanki WHERE linia_id BETWEEN 1 AND 12;
INSERT INTO turystyka_przystanki (linia_id, kolejnosc, planeta, postoj_h) VALUES
(1, 1, 'Coruscant', 8), (1, 2, 'Chandrila', 12), (1, 3, 'Corellia', 12), (1, 4, 'Corulag', 8),
(2, 1, 'Coruscant', 6), (2, 2, 'Naboo', 6),
(3, 1, 'Corellia', 6), (3, 2, 'Bespin', 6),
(4, 1, 'Coruscant', 10), (4, 2, 'Cantonica', 24), (4, 3, 'Zeltros', 10),
(5, 1, 'Chandrila', 8), (5, 2, 'Hapes', 8),
(6, 1, 'Ord Mantell', 6), (6, 2, 'Eriadu', 10), (6, 3, 'Sullust', 6),
(7, 1, 'Nar Shaddaa', 6), (7, 2, 'Tatooine', 18), (7, 3, 'Ryloth', 6),
(8, 1, 'Esseles', 6), (8, 2, 'Takodana', 6),
(9, 1, 'Corellia', 8), (9, 2, 'Ithor', 18), (9, 3, 'Manaan', 8),
(10, 1, 'Denon', 6), (10, 2, 'Abregado-rae', 12), (10, 3, 'Bestine', 6),
(11, 1, 'Rodia', 6), (11, 2, 'Felucia', 6),
(12, 1, 'Lothal', 6), (12, 2, 'Dantooine', 6);

CREATE OR REPLACE VIEW publiczne_kontrakty AS
SELECT l.id, o.planeta AS odbiorca_planeta, o.nazwa AS odbiorca, o.rodzaj AS odbiorca_rodzaj, l.producent,
  l.towar, t.nazwa AS towar_nazwa, l.t_doba, l.ladownosc_t, l.klasa, l.odstep_s
FROM gosp_linie l
JOIN gosp_obiekty o ON o.id = l.obiekt_id
JOIN gosp_towary t ON t.kod = l.towar
WHERE l.obieg = 'oficjalny_hurt';

CREATE OR REPLACE VIEW publiczny_rynek AS
SELECT r.planeta, r.towar, t.nazwa AS towar_nazwa, t.kategoria, r.produkcja_t_doba, r.zuzycie_t_doba, r.cena_kr_t, r.cena_indeks,
  t.cena_bazowa_kr_t, r.szczegolowosc
FROM gosp_rynek r
JOIN gosp_towary t ON t.kod = r.towar
WHERE r.obieg = 'oficjalny_hurt';

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v096-dane-publiczne', 'Dane publiczne mapy: linie turystyczne z rozkładami, jawne kontrakty dostaw i rynek planet')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

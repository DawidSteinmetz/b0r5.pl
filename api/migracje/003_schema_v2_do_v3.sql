-- HOLOLINK: rozszerzenie fundamentu schema_v2 -> v3
-- Wymaga wcześniejszego importu api/migracje/001_podstawa.sql oraz api/migracje/002_schema_v2.sql.
-- MySQL 8.0.16+ / MariaDB 10.6+. Addytywne, nie usuwa ani nie nadpisuje danych v2.
-- Można uruchamiać ponownie: tabele mają IF NOT EXISTS, słowniki INSERT IGNORE.
-- Po imporcie sprawdź komunikaty phpMyAdmin i wpis schema_migracje.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 10. WALUTA I ROZLICZENIA
-- CR jest jedyną walutą księgową. IC jest aliasem wyświetlania/odczytu,
-- a nie odrębną walutą, saldem ani emisją.
-- Waluta przypisana do rachunku; księgowania dziedziczą ją z rachunku.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS waluty (
  kod CHAR(3) NOT NULL PRIMARY KEY,
  nazwa VARCHAR(80) NOT NULL,
  symbol VARCHAR(12) NOT NULL,
  miejsca_dziesietne TINYINT UNSIGNED NOT NULL DEFAULT 0 CHECK (miejsca_dziesietne <= 6),
  aktywna TINYINT(1) NOT NULL DEFAULT 1,
  utworzono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waluty_aliasy (
  alias CHAR(8) NOT NULL PRIMARY KEY,
  waluta_kod CHAR(3) NOT NULL,
  opis VARCHAR(160) NULL,
  FOREIGN KEY (waluta_kod) REFERENCES waluty (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rachunki_waluty (
  rachunek_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  waluta_kod CHAR(3) NOT NULL DEFAULT 'CR',
  przypisano TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rachunek_id) REFERENCES rachunki (id),
  FOREIGN KEY (waluta_kod) REFERENCES waluty (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portfele_salda (
  podmiot_id BIGINT UNSIGNED NOT NULL,
  waluta_kod CHAR(3) NOT NULL DEFAULT 'CR',
  gotowka BIGINT NOT NULL DEFAULT 0 CHECK (gotowka >= 0),
  PRIMARY KEY (podmiot_id, waluta_kod),
  FOREIGN KEY (podmiot_id) REFERENCES podmioty (id),
  FOREIGN KEY (waluta_kod) REFERENCES waluty (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notowanie relacji między walutami w zadanym przedziale czasu.
-- Nie wykonuje wymiany ani nie tworzy podaży; stanowi źródło kursu dla UI/wyliczeń.
CREATE TABLE IF NOT EXISTS kursy_walut (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  waluta_z CHAR(3) NOT NULL,
  waluta_na CHAR(3) NOT NULL,
  jednostek_na DECIMAL(24,10) NOT NULL CHECK (jednostek_na > 0),
  zrodlo VARCHAR(120) NOT NULL DEFAULT 'rynek gry',
  wazny_od DATETIME NOT NULL,
  wazny_do DATETIME NULL,
  KEY ix_kursy_para_czas (waluta_z, waluta_na, wazny_od),
  CHECK (waluta_z <> waluta_na),
  CHECK (wazny_do IS NULL OR wazny_do > wazny_od),
  FOREIGN KEY (waluta_z) REFERENCES waluty (kod),
  FOREIGN KEY (waluta_na) REFERENCES waluty (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wymiany_walut (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ksiegowanie_id BIGINT UNSIGNED NOT NULL,
  rachunek_z BIGINT UNSIGNED NOT NULL,
  rachunek_na BIGINT UNSIGNED NOT NULL,
  kwota_z BIGINT UNSIGNED NOT NULL CHECK (kwota_z > 0),
  kwota_na BIGINT UNSIGNED NOT NULL CHECK (kwota_na > 0),
  kurs DECIMAL(24,10) NOT NULL CHECK (kurs > 0),
  oplata BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_wymiany_ksiegowanie (ksiegowanie_id),
  CHECK (rachunek_z <> rachunek_na),
  FOREIGN KEY (ksiegowanie_id) REFERENCES ksiegowania (id),
  FOREIGN KEY (rachunek_z) REFERENCES rachunki (id),
  FOREIGN KEY (rachunek_na) REFERENCES rachunki (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO waluty (kod, nazwa, symbol, miejsca_dziesietne)
VALUES ('CR', 'Credit', 'CR', 0);

INSERT IGNORE INTO waluty_aliasy (alias, waluta_kod, opis)
VALUES ('IC', 'CR', 'Historyczny alias Credit; nie jest osobną walutą');

INSERT IGNORE INTO rachunki_waluty (rachunek_id, waluta_kod)
SELECT id, 'CR' FROM rachunki;

INSERT IGNORE INTO portfele_salda (podmiot_id, waluta_kod, gotowka)
SELECT podmiot_id, 'CR', gotowka FROM portfele;

-- ---------------------------------------------------------------------
-- 11. ROZSZERZALNY KATALOG I EKONOMIA
-- Zachowuje stare ENUM-y w v2 dla zgodności aplikacji; nowe kategorie
-- dopisuje się tu bez zmiany schematu. Towar może mieć wiele etykiet.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS kategorie_towarow (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kod VARCHAR(40) NOT NULL,
  nazwa VARCHAR(100) NOT NULL,
  kategoria_nadrzedna_id BIGINT UNSIGNED NULL,
  opis VARCHAR(255) NULL,
  kolejnosc SMALLINT NOT NULL DEFAULT 0,
  aktywna TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_kategorie_towarow_kod (kod),
  FOREIGN KEY (kategoria_nadrzedna_id) REFERENCES kategorie_towarow (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS towary_kategorie (
  towar_id BIGINT UNSIGNED NOT NULL,
  kategoria_id BIGINT UNSIGNED NOT NULL,
  glowna TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (towar_id, kategoria_id),
  KEY ix_towary_kategorie_glowna (kategoria_id, glowna),
  FOREIGN KEY (towar_id) REFERENCES towary (id),
  FOREIGN KEY (kategoria_id) REFERENCES kategorie_towarow (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS planety_kategorie_ceny (
  planeta_id INT UNSIGNED NOT NULL,
  kategoria_id BIGINT UNSIGNED NOT NULL,
  mnoznik DECIMAL(10,4) NOT NULL DEFAULT 1 CHECK (mnoznik > 0),
  wazny_od DATETIME NULL,
  wazny_do DATETIME NULL,
  PRIMARY KEY (planeta_id, kategoria_id),
  CHECK (wazny_do IS NULL OR wazny_od IS NULL OR wazny_do > wazny_od),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id),
  FOREIGN KEY (kategoria_id) REFERENCES kategorie_towarow (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO kategorie_towarow (kod, nazwa, kolejnosc) VALUES
  ('paliwo', 'Paliwo', 10), ('surowce', 'Surowce', 20),
  ('zywnosc', 'Żywność', 30), ('medycyna', 'Medycyna', 40),
  ('technika', 'Technika', 50), ('bron', 'Broń', 60),
  ('luksus', 'Dobra luksusowe', 70), ('przyprawy', 'Przyprawy', 80);

-- Przypisz dotychczasowe towary i planetaryczne mnożniki do nowych słowników.
INSERT IGNORE INTO towary_kategorie (towar_id, kategoria_id, glowna)
SELECT t.id, k.id, 1
FROM towary t
JOIN kategorie_towarow k ON k.kod = t.kategoria;

INSERT IGNORE INTO planety_kategorie_ceny (planeta_id, kategoria_id, mnoznik)
SELECT ps.planeta_id, k.id, ps.mnoznik
FROM planety_specyfika ps
JOIN kategorie_towarow k ON k.kod = ps.kategoria
WHERE ps.kategoria IS NOT NULL;

-- ---------------------------------------------------------------------
-- 12. POSTACIE: PROFIL EDGE OF THE EMPIRE
-- Jeden model dla postaci gracza i NPC; aktualne pola v2 pozostają źródłem
-- tożsamości i wyposażenia, a poniższe tabele przechowują dane mechaniki.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS karty_eote (
  podmiot_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  gatunek VARCHAR(100) NULL,
  kariera VARCHAR(100) NULL,
  specjalizacja VARCHAR(120) NULL,
  sila TINYINT UNSIGNED NOT NULL DEFAULT 2 CHECK (sila BETWEEN 1 AND 6),
  zrecznosc TINYINT UNSIGNED NOT NULL DEFAULT 2 CHECK (zrecznosc BETWEEN 1 AND 6),
  intelekt TINYINT UNSIGNED NOT NULL DEFAULT 2 CHECK (intelekt BETWEEN 1 AND 6),
  przebieglosc TINYINT UNSIGNED NOT NULL DEFAULT 2 CHECK (przebieglosc BETWEEN 1 AND 6),
  sila_woli TINYINT UNSIGNED NOT NULL DEFAULT 2 CHECK (sila_woli BETWEEN 1 AND 6),
  prezencja TINYINT UNSIGNED NOT NULL DEFAULT 2 CHECK (prezencja BETWEEN 1 AND 6),
  rany_aktualne SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  rany_maks SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  napiecie_aktualne SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  napiecie_maks SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  pancerz SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  obrona_melee TINYINT UNSIGNED NOT NULL DEFAULT 0,
  obrona_zasieg TINYINT UNSIGNED NOT NULL DEFAULT 0,
  doswiadczenie_dostepne INT UNSIGNED NOT NULL DEFAULT 0,
  doswiadczenie_laczne INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('aktywna','nieaktywna','archiwalna') NOT NULL DEFAULT 'aktywna',
  notatki TEXT NULL,
  zmieniono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CHECK (rany_aktualne <= rany_maks),
  CHECK (napiecie_aktualne <= napiecie_maks),
  FOREIGN KEY (podmiot_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eote_umiejetnosci (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kod VARCHAR(60) NOT NULL,
  nazwa VARCHAR(100) NOT NULL,
  grupa ENUM('bojowa','ogolna','wiedza','kosmiczna','inne') NOT NULL DEFAULT 'ogolna',
  cecha ENUM('sila','zrecznosc','intelekt','przebieglosc','sila_woli','prezencja') NOT NULL,
  UNIQUE KEY uq_eote_umiejetnosci_kod (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eote_postacie_umiejetnosci (
  podmiot_id BIGINT UNSIGNED NOT NULL,
  umiejetnosc_id SMALLINT UNSIGNED NOT NULL,
  rangi TINYINT UNSIGNED NOT NULL DEFAULT 0 CHECK (rangi <= 5),
  karierowa TINYINT(1) NOT NULL DEFAULT 0,
  zrodlo VARCHAR(120) NULL,
  PRIMARY KEY (podmiot_id, umiejetnosc_id),
  FOREIGN KEY (podmiot_id) REFERENCES karty_eote (podmiot_id),
  FOREIGN KEY (umiejetnosc_id) REFERENCES eote_umiejetnosci (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eote_talenty (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kod VARCHAR(80) NOT NULL,
  nazwa VARCHAR(120) NOT NULL,
  opis TEXT NULL,
  poziom TINYINT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_eote_talenty_kod (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eote_kariery (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kod VARCHAR(60) NOT NULL,
  nazwa VARCHAR(100) NOT NULL,
  opis TEXT NULL,
  UNIQUE KEY uq_eote_kariery_kod (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eote_specjalizacje (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kariera_id SMALLINT UNSIGNED NOT NULL,
  kod VARCHAR(60) NOT NULL,
  nazwa VARCHAR(100) NOT NULL,
  opis TEXT NULL,
  UNIQUE KEY uq_eote_specjalizacji_kariera_kod (kariera_id, kod),
  FOREIGN KEY (kariera_id) REFERENCES eote_kariery (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eote_postacie_specjalizacje (
  podmiot_id BIGINT UNSIGNED NOT NULL,
  specjalizacja_id SMALLINT UNSIGNED NOT NULL,
  aktywna TINYINT(1) NOT NULL DEFAULT 1,
  zdobyto_gst BIGINT NULL,
  PRIMARY KEY (podmiot_id, specjalizacja_id),
  FOREIGN KEY (podmiot_id) REFERENCES karty_eote (podmiot_id),
  FOREIGN KEY (specjalizacja_id) REFERENCES eote_specjalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eote_talenty_wymagania (
  talent_id BIGINT UNSIGNED NOT NULL,
  wymagany_talent_id BIGINT UNSIGNED NOT NULL,
  wymagane_rangi TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (talent_id, wymagany_talent_id),
  CHECK (talent_id <> wymagany_talent_id),
  FOREIGN KEY (talent_id) REFERENCES eote_talenty (id),
  FOREIGN KEY (wymagany_talent_id) REFERENCES eote_talenty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eote_postacie_talenty (
  podmiot_id BIGINT UNSIGNED NOT NULL,
  talent_id BIGINT UNSIGNED NOT NULL,
  rangi TINYINT UNSIGNED NOT NULL DEFAULT 1 CHECK (rangi BETWEEN 1 AND 5),
  nabyto_gst BIGINT NULL,
  PRIMARY KEY (podmiot_id, talent_id),
  FOREIGN KEY (podmiot_id) REFERENCES karty_eote (podmiot_id),
  FOREIGN KEY (talent_id) REFERENCES eote_talenty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eote_obrazenia_historia (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  podmiot_id BIGINT UNSIGNED NOT NULL,
  rodzaj ENUM('rana','napiecie','leczenie_ran','odpoczynek','inne') NOT NULL,
  zmiana SMALLINT NOT NULL,
  wartosc_po SMALLINT UNSIGNED NOT NULL,
  powod VARCHAR(255) NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  autor_id BIGINT UNSIGNED NULL,
  KEY ix_eote_obrazenia_czas (podmiot_id, czas_utc),
  FOREIGN KEY (podmiot_id) REFERENCES karty_eote (podmiot_id),
  FOREIGN KEY (autor_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. IZOLACJA SYMULACJI I SZKOLEŃ
-- Symulacje mają własne migawki i dziennik. Nie zapisują stanu w
-- jednostki_floty / komponenty_egzemplarze świata rzeczywistego.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sesje_symulacji (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nazwa VARCHAR(160) NOT NULL,
  rodzaj ENUM('szkolenie','taktyczna','nawigacyjna','inzynieryjna','inne') NOT NULL DEFAULT 'szkolenie',
  prowadzacy_id BIGINT UNSIGNED NULL,
  status ENUM('przygotowanie','aktywna','zakonczona','anulowana') NOT NULL DEFAULT 'przygotowanie',
  prywatna TINYINT(1) NOT NULL DEFAULT 1,
  opis TEXT NULL,
  start_utc DATETIME NULL,
  koniec_utc DATETIME NULL,
  start_gst BIGINT NULL,
  koniec_gst BIGINT NULL,
  utworzono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_symulacje_status (status, rodzaj),
  FOREIGN KEY (prowadzacy_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS symulacje_jednostki (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  symulacja_id BIGINT UNSIGNED NOT NULL,
  jednostka_id BIGINT UNSIGNED NULL,
  nazwa_symulowana VARCHAR(160) NOT NULL,
  strona VARCHAR(80) NULL,
  wlasciciel_id BIGINT UNSIGNED NULL,
  stan_ruchu VARCHAR(40) NOT NULL DEFAULT 'postoj',
  polozenie_x DECIMAL(16,4) NULL,
  polozenie_y DECIMAL(16,4) NULL,
  polozenie_z DECIMAL(16,4) NULL,
  paliwo DECIMAL(14,2) NOT NULL DEFAULT 0,
  stan_kadlubu TINYINT UNSIGNED NOT NULL DEFAULT 100 CHECK (stan_kadlubu <= 100),
  status VARCHAR(40) NOT NULL DEFAULT 'sprawny',
  UNIQUE KEY uq_symulacje_jednostka (symulacja_id, jednostka_id),
  KEY ix_symulacje_jednostki (symulacja_id, strona),
  FOREIGN KEY (symulacja_id) REFERENCES sesje_symulacji (id),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (wlasciciel_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS symulacje_zaloga (
  symulacja_jednostka_id BIGINT UNSIGNED NOT NULL,
  osoba_id BIGINT UNSIGNED NOT NULL,
  funkcja VARCHAR(80) NOT NULL DEFAULT 'uczestnik',
  gracz TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (symulacja_jednostka_id, osoba_id),
  FOREIGN KEY (symulacja_jednostka_id) REFERENCES symulacje_jednostki (id),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS symulacje_stany_systemow (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  symulacja_jednostka_id BIGINT UNSIGNED NOT NULL,
  kod_systemu VARCHAR(60) NOT NULL,
  komponent_id BIGINT UNSIGNED NULL,
  stan ENUM('wylaczony','gotowy','uszkodzony','przeciazony','zniszczony') NOT NULL DEFAULT 'gotowy',
  moc_przydzielona DECIMAL(14,3) NOT NULL DEFAULT 0,
  wartosc DECIMAL(14,3) NULL,
  KEY ix_symulacje_systemy (symulacja_jednostka_id, kod_systemu),
  FOREIGN KEY (symulacja_jednostka_id) REFERENCES symulacje_jednostki (id),
  FOREIGN KEY (komponent_id) REFERENCES komponenty_egzemplarze (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS symulacje_zdarzenia (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  symulacja_id BIGINT UNSIGNED NOT NULL,
  kolejnosc BIGINT UNSIGNED NOT NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  czas_gst BIGINT NULL,
  rodzaj VARCHAR(60) NOT NULL,
  jednostka_id BIGINT UNSIGNED NULL,
  osoba_id BIGINT UNSIGNED NULL,
  cel_jednostka_id BIGINT UNSIGNED NULL,
  wynik VARCHAR(80) NULL,
  wartosc DECIMAL(16,4) NULL,
  opis TEXT NULL,
  UNIQUE KEY uq_symulacje_zdarzenia_kolejnosc (symulacja_id, kolejnosc),
  KEY ix_symulacje_zdarzenia_czas (symulacja_id, czas_gst),
  FOREIGN KEY (symulacja_id) REFERENCES sesje_symulacji (id),
  FOREIGN KEY (jednostka_id) REFERENCES symulacje_jednostki (id),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id),
  FOREIGN KEY (cel_jednostka_id) REFERENCES symulacje_jednostki (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS symulacje_wyniki (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  symulacja_id BIGINT UNSIGNED NOT NULL,
  uczestnik_id BIGINT UNSIGNED NULL,
  symulacja_jednostka_id BIGINT UNSIGNED NULL,
  kod_wyniku VARCHAR(60) NOT NULL,
  punkty DECIMAL(14,3) NOT NULL DEFAULT 0,
  opis VARCHAR(500) NULL,
  UNIQUE KEY uq_symulacje_wynik (symulacja_id, uczestnik_id, symulacja_jednostka_id, kod_wyniku),
  FOREIGN KEY (symulacja_id) REFERENCES sesje_symulacji (id),
  FOREIGN KEY (uczestnik_id) REFERENCES podmioty (id),
  FOREIGN KEY (symulacja_jednostka_id) REFERENCES symulacje_jednostki (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 14. ZASILANIE, OSŁONY I WIĄZKI TRAKTOROWE
-- Liczba linii nie jest zahardkodowana: model wspiera 12 linii lub inną
-- konfigurację kadłuba. Parametry i obciążenia są instancjami statku.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS jednostki_linie_zasilania (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  numer SMALLINT UNSIGNED NOT NULL,
  nazwa VARCHAR(100) NULL,
  moc_maks DECIMAL(14,3) NOT NULL DEFAULT 0 CHECK (moc_maks >= 0),
  stan ENUM('aktywna','ograniczona','wylaczona','uszkodzona') NOT NULL DEFAULT 'aktywna',
  UNIQUE KEY uq_jednostka_linia_nr (jednostka_id, numer),
  UNIQUE KEY uq_linia_id_jednostka (id, jednostka_id),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS linie_zasilania_montaze (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  linia_id BIGINT UNSIGNED NOT NULL,
  montaz_id BIGINT UNSIGNED NOT NULL,
  czestotliwosc_hz DECIMAL(14,4) NULL,
  dzielnik_mocy DECIMAL(10,6) NOT NULL DEFAULT 1 CHECK (dzielnik_mocy > 0),
  moc_przydzielona DECIMAL(14,3) NOT NULL DEFAULT 0 CHECK (moc_przydzielona >= 0),
  priorytet TINYINT UNSIGNED NOT NULL DEFAULT 5,
  aktywne TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_montaz_linia (montaz_id),
  KEY ix_linia_obciazenia (linia_id, aktywne, priorytet),
  FOREIGN KEY (linia_id) REFERENCES jednostki_linie_zasilania (id),
  FOREIGN KEY (montaz_id) REFERENCES komponenty_montaze (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parametry_czestotliwosci_modeli (
  model_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  czestotliwosc_min_hz DECIMAL(14,4) NULL,
  czestotliwosc_maks_hz DECIMAL(14,4) NULL,
  dzielnik_mocy_min DECIMAL(10,6) NOT NULL DEFAULT 0.1,
  dzielnik_mocy_maks DECIMAL(10,6) NOT NULL DEFAULT 1,
  CHECK (czestotliwosc_maks_hz IS NULL OR czestotliwosc_min_hz IS NULL OR czestotliwosc_maks_hz >= czestotliwosc_min_hz),
  CHECK (dzielnik_mocy_maks >= dzielnik_mocy_min),
  FOREIGN KEY (model_id) REFERENCES komponenty_modele (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oslony_sektory (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  nazwa VARCHAR(80) NOT NULL,
  kierunek ENUM('dziob','ruf','bakburta','sterburta','gora','dol','pelna') NOT NULL,
  egzemplarz_id BIGINT UNSIGNED NULL,
  wytrzymalosc_maks DECIMAL(14,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_oslony_sektor (jednostka_id, nazwa),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (egzemplarz_id) REFERENCES komponenty_egzemplarze (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oslony_sektory_stan (
  sektor_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  energia_aktualna DECIMAL(14,2) NOT NULL DEFAULT 0,
  moc_regeneracji DECIMAL(14,2) NOT NULL DEFAULT 0,
  stan ENUM('aktywna','przeciazona','wylaczona','uszkodzona') NOT NULL DEFAULT 'aktywna',
  zmieniono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (sektor_id) REFERENCES oslony_sektory (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oslony_generatory_sektory (
  egzemplarz_id BIGINT UNSIGNED NOT NULL,
  sektor_id BIGINT UNSIGNED NOT NULL,
  udzial_mocy DECIMAL(8,5) NOT NULL DEFAULT 1 CHECK (udzial_mocy > 0 AND udzial_mocy <= 1),
  moc_aktualna DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('aktywny','ograniczony','wylaczony','uszkodzony') NOT NULL DEFAULT 'aktywny',
  PRIMARY KEY (egzemplarz_id, sektor_id),
  FOREIGN KEY (egzemplarz_id) REFERENCES komponenty_egzemplarze (id),
  FOREIGN KEY (sektor_id) REFERENCES oslony_sektory (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ciagniki_statki (
  jednostka_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  maks_zasieg_km DECIMAL(14,2) NOT NULL DEFAULT 0,
  maks_masa_t DECIMAL(14,2) NOT NULL DEFAULT 0,
  rezonans_hz DECIMAL(14,4) NULL,
  status ENUM('gotowy','wylaczony','uszkodzony','przeciazony') NOT NULL DEFAULT 'gotowy',
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ciagniki_emitery (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  montaz_id BIGINT UNSIGNED NULL,
  nazwa VARCHAR(100) NOT NULL,
  kierunek_azymut DECIMAL(8,3) NOT NULL DEFAULT 0,
  kierunek_elewacja DECIMAL(8,3) NOT NULL DEFAULT 0,
  sila DECIMAL(14,3) NOT NULL DEFAULT 0,
  rezonans_hz DECIMAL(14,4) NULL,
  status ENUM('gotowy','aktywny','wylaczony','uszkodzony') NOT NULL DEFAULT 'gotowy',
  UNIQUE KEY uq_ciagniki_emiter_nazwa (jednostka_id, nazwa),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (montaz_id) REFERENCES komponenty_montaze (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hangary_jednostek (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  montaz_id BIGINT UNSIGNED NULL,
  kod VARCHAR(40) NOT NULL,
  nazwa VARCHAR(120) NOT NULL,
  pojemnosc_jednostek SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  rozmiar_max TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('aktywny','ograniczony','zamkniety','uszkodzony') NOT NULL DEFAULT 'aktywny',
  UNIQUE KEY uq_hangar_jednostki_kod (jednostka_id, kod),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (montaz_id) REFERENCES komponenty_montaze (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS przydzialy_hangaru (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  hangar_id BIGINT UNSIGNED NOT NULL,
  pojazd_id BIGINT UNSIGNED NULL,
  jednostka_id BIGINT UNSIGNED NULL,
  status ENUM('przypisany','start','poza_hangarem','naprawa','zwolniony') NOT NULL DEFAULT 'przypisany',
  aktywny TINYINT NULL DEFAULT 1 CHECK (aktywny IS NULL OR aktywny = 1),
  od_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  do_utc DATETIME NULL,
  CHECK ((pojazd_id IS NOT NULL) + (jednostka_id IS NOT NULL) = 1),
  CHECK (do_utc IS NULL OR do_utc > od_utc),
  KEY ix_przydzial_hangaru (hangar_id, status),
  UNIQUE KEY uq_przydzial_hangaru_pojazd (pojazd_id, aktywny),
  UNIQUE KEY uq_przydzial_hangaru_jednostka (jednostka_id, aktywny),
  FOREIGN KEY (hangar_id) REFERENCES hangary_jednostek (id),
  FOREIGN KEY (pojazd_id) REFERENCES pojazdy (id),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zuzycie_zasobow_pozycje (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  zuzycie_id BIGINT UNSIGNED NOT NULL,
  towar_id BIGINT UNSIGNED NOT NULL,
  ilosc DECIMAL(16,4) NOT NULL CHECK (ilosc > 0),
  jednostka VARCHAR(20) NOT NULL DEFAULT 'szt',
  magazyn_partia_id BIGINT UNSIGNED NULL,
  KEY ix_zuzycie_towar (towar_id, zuzycie_id),
  FOREIGN KEY (zuzycie_id) REFERENCES zuzycie_zasobow (id),
  FOREIGN KEY (towar_id) REFERENCES towary (id),
  FOREIGN KEY (magazyn_partia_id) REFERENCES magazyn_partie (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15. ZAŁOGA, ZMIANY, KWATERY I POSIŁKI
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pomieszczenia_jednostek (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  kod VARCHAR(40) NOT NULL,
  nazwa VARCHAR(120) NOT NULL,
  rodzaj ENUM('kwatera','mesa','mostek','maszynownia','medyczny','magazyn','inne') NOT NULL DEFAULT 'inne',
  poklad VARCHAR(40) NULL,
  pojemnosc SMALLINT UNSIGNED NULL,
  standard TINYINT UNSIGNED NULL,
  UNIQUE KEY uq_pomieszczenie_kod (jednostka_id, kod),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS przydzialy_kwater (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pomieszczenie_id BIGINT UNSIGNED NOT NULL,
  osoba_id BIGINT UNSIGNED NOT NULL,
  miejsce VARCHAR(40) NOT NULL DEFAULT '',
  od_gst BIGINT NULL,
  do_gst BIGINT NULL,
  aktywny TINYINT NULL DEFAULT 1 CHECK (aktywny IS NULL OR aktywny = 1),
  UNIQUE KEY uq_kwatera_miejsce (pomieszczenie_id, miejsce, aktywny),
  KEY ix_kwatera_osoba (osoba_id, aktywny),
  CHECK (do_gst IS NULL OR od_gst IS NULL OR do_gst > od_gst),
  FOREIGN KEY (pomieszczenie_id) REFERENCES pomieszczenia_jednostek (id),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grafiki_zmian_zalogi (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  nazwa VARCHAR(100) NOT NULL,
  start_gst BIGINT NOT NULL,
  koniec_gst BIGINT NOT NULL,
  funkcja VARCHAR(80) NULL,
  KEY ix_grafik_zmian_jednostka (jednostka_id, start_gst, koniec_gst),
  CHECK (koniec_gst > start_gst),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grafiki_zmian_osoby (
  zmiana_id BIGINT UNSIGNED NOT NULL,
  osoba_id BIGINT UNSIGNED NOT NULL,
  rola VARCHAR(80) NULL,
  PRIMARY KEY (zmiana_id, osoba_id),
  FOREIGN KEY (zmiana_id) REFERENCES grafiki_zmian_zalogi (id),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS harmonogramy_posilkow (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  pomieszczenie_id BIGINT UNSIGNED NULL,
  nazwa VARCHAR(80) NOT NULL,
  start_gst BIGINT NOT NULL,
  koniec_gst BIGINT NOT NULL,
  KEY ix_posilki_jednostka (jednostka_id, start_gst),
  CHECK (koniec_gst > start_gst),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (pomieszczenie_id) REFERENCES pomieszczenia_jednostek (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posilki_zalogi (
  harmonogram_id BIGINT UNSIGNED NOT NULL,
  osoba_id BIGINT UNSIGNED NOT NULL,
  status ENUM('zaplanowany','wydany','zjedzony','pominiety') NOT NULL DEFAULT 'zaplanowany',
  towar_id BIGINT UNSIGNED NULL,
  ilosc DECIMAL(12,3) NOT NULL DEFAULT 1,
  PRIMARY KEY (harmonogram_id, osoba_id),
  FOREIGN KEY (harmonogram_id) REFERENCES harmonogramy_posilkow (id),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id),
  FOREIGN KEY (towar_id) REFERENCES towary (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 16. CZUJNIKI, KONTAKTY I ROZKAZY MOSTKA
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS systemy_czujnikow (
  jednostka_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  tryb ENUM('wylaczone','pasywny','aktywny','dalekiego_zasiegu') NOT NULL DEFAULT 'pasywny',
  zasieg_pc DECIMAL(12,3) NOT NULL DEFAULT 0,
  czulosc DECIMAL(8,3) NOT NULL DEFAULT 1,
  pasmo VARCHAR(60) NULL,
  zaklocenia DECIMAL(8,3) NOT NULL DEFAULT 0,
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kontakty_czujnikow (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jednostka_wykrywajaca_id BIGINT UNSIGNED NOT NULL,
  wykrycie_nadprzestrzenne_id BIGINT UNSIGNED NULL,
  wykryta_jednostka_id BIGINT UNSIGNED NULL,
  wykryty_podmiot_id BIGINT UNSIGNED NULL,
  wykryta_lokalizacja_id BIGINT UNSIGNED NULL,
  pozycja_x DECIMAL(16,4) NULL,
  pozycja_y DECIMAL(16,4) NULL,
  pozycja_z DECIMAL(16,4) NULL,
  typ_sygnatury VARCHAR(60) NULL,
  pewnosc TINYINT UNSIGNED NOT NULL DEFAULT 1 CHECK (pewnosc BETWEEN 1 AND 10),
  pierwsze_wykrycie_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ostatnie_wykrycie_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('aktywny','utracony','zidentyfikowany','falszywy','zamkniety') NOT NULL DEFAULT 'aktywny',
  opis TEXT NULL,
  KEY ix_kontakty_jednostka_status (jednostka_wykrywajaca_id, status, ostatnie_wykrycie_utc),
  CHECK ((wykryta_jednostka_id IS NOT NULL) + (wykryty_podmiot_id IS NOT NULL) + (wykryta_lokalizacja_id IS NOT NULL) <= 1),
  FOREIGN KEY (jednostka_wykrywajaca_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (wykrycie_nadprzestrzenne_id) REFERENCES wykrycia_nadprzestrzenne (id),
  FOREIGN KEY (wykryta_jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (wykryty_podmiot_id) REFERENCES podmioty (id),
  FOREIGN KEY (wykryta_lokalizacja_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kontakty_czujnikow_historia (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kontakt_id BIGINT UNSIGNED NOT NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  czas_gst BIGINT NULL,
  pozycja_x DECIMAL(16,4) NULL,
  pozycja_y DECIMAL(16,4) NULL,
  pozycja_z DECIMAL(16,4) NULL,
  sila_sygnalu DECIMAL(10,4) NULL,
  pewnosc TINYINT UNSIGNED NOT NULL DEFAULT 1 CHECK (pewnosc BETWEEN 1 AND 10),
  falszywy_odczyt TINYINT(1) NOT NULL DEFAULT 0,
  pomiar VARCHAR(120) NULL,
  KEY ix_kontakty_historia_czas (kontakt_id, czas_utc),
  FOREIGN KEY (kontakt_id) REFERENCES kontakty_czujnikow (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rozkazy_mostka (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  numer BIGINT UNSIGNED NOT NULL,
  kod_rozkazu VARCHAR(60) NOT NULL,
  wydajacy_id BIGINT UNSIGNED NULL,
  cel_jednostka_id BIGINT UNSIGNED NULL,
  status ENUM('wydany','przyjety','wykonywany','wykonany','odrzucony','anulowany') NOT NULL DEFAULT 'wydany',
  parametry_tekst VARCHAR(500) NULL,
  wydano_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  zakonczono_utc DATETIME NULL,
  wydano_gst BIGINT NULL,
  zakonczono_gst BIGINT NULL,
  wynik TEXT NULL,
  UNIQUE KEY uq_rozkaz_numer (jednostka_id, numer),
  KEY ix_rozkazy_status (jednostka_id, status, wydano_utc),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (wydajacy_id) REFERENCES podmioty (id),
  FOREIGN KEY (cel_jednostka_id) REFERENCES jednostki_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rozkazy_mostka_zmiany (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rozkaz_id BIGINT UNSIGNED NOT NULL,
  status ENUM('wydany','przyjety','wykonywany','wykonany','odrzucony','anulowany') NOT NULL,
  autor_id BIGINT UNSIGNED NULL,
  komunikat VARCHAR(500) NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  czas_gst BIGINT NULL,
  KEY ix_rozkazy_zmiany (rozkaz_id, czas_utc),
  FOREIGN KEY (rozkaz_id) REFERENCES rozkazy_mostka (id),
  FOREIGN KEY (autor_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 17. OZNACZENIE WERSJI
-- ---------------------------------------------------------------------

INSERT IGNORE INTO schema_migracje (wersja, opis)
VALUES ('v3', 'Rozszerzenie v2: CR/IC, kategorie katalogu, EotE, symulacje, okręt, załoga, sensory i mostek');

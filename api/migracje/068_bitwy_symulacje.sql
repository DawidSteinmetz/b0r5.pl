-- Bitwy i symulacje bitew (mechanika: narzedzia/mostek/MECHANIKA.md, wdrożenie: narzedzia/mostek/WDROZENIE.md).
--
-- Jeden silnik dla obu rodzajów bitwy. mapa_bitwy.rodzaj = 'symulacja' (instancja: stan tylko w tabelach bitwy_*,
-- nic nie wraca do świata poza XP pilotów) albo 'rzeczywista' (później: skutki zapisywane w świecie).
-- Symulacja ma planeta_id = NULL — dzięki temu nie pojawia się na radarze ani w spisie bitew planety.
--
-- Słowniki mechaniki (wypełnia migracja 069, generowana z narzedzia/mostek/):
--   mechanika_stale        — stałe wspólne (tik, cykl, porcje, Hz odniesienia, wykładnik zużycia…)
--   profile_klas (+ _jednostki, _sektory, _kadlub) — profile klas okrętów (ISD_I, MC80, ZBIEG)
--   typy_maszyn            — myśliwce i promy: Hz bazowe, osłona, prędkość, przekrój, energia strzału
--   rozkazy_profile        — czas wykonania rozkazów: przygotowanie, działanie, odnowienie, mnożniki rozrzutu
--   bitwy_scenariusze (+ _strony, _jednostki, _klucze, _wyzwalacze) — scenariusze bitew
-- Stan bitwy (wypełnia silnik): bitwy_jednostki, bitwy_sekcje, bitwy_systemy, bitwy_zyly, bitwy_klucze, bitwy_piloci,
--   bitwy_stanowiska, bitwy_blokady, bitwy_rozkazy, bitwy_zdarzenia, bitwy_migawki, bitwy_xp.
-- Żadnego JSON: żyły osłon i baterii jako mapy bitowe / bajty (VARBINARY), migawki jako zrzut binarny.
--
-- Zmiany w istniejących tabelach: mapa_bitwy (rodzaj, scenariusz, zegar), mapa_piloci.xp → DECIMAL(10,1)
-- (0,5 XP za akcję w symulacji), mapa_obiekty.profil_klasy.
--
-- Wymaga migracji 010 (mapa_bitwy), 067 (mapa_piloci). Import w phpMyAdmin. Ponowny import: ALTER zgłosi
-- „Duplicate column” — tabele są już w bazie.

USE hololink;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------------------------------------------
-- Słowniki mechaniki
-- ---------------------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS mechanika_stale (
  kod VARCHAR(40) NOT NULL PRIMARY KEY,
  wartosc DOUBLE NOT NULL,
  status ENUM('ustalone','robocze','propozycja') NOT NULL DEFAULT 'robocze',
  opis VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profile_klas (
  kod VARCHAR(20) NOT NULL PRIMARY KEY,
  nazwa VARCHAR(160) NOT NULL,
  dlugosc_m DECIMAL(8,1) NOT NULL,
  moc_reaktora_w DOUBLE NOT NULL,
  utrata_rdzenia VARCHAR(255) NULL,
  generatory_oslon TINYINT UNSIGNED NOT NULL,
  zyly_oslon SMALLINT UNSIGNED NOT NULL,
  zapasowy_zyly SMALLINT UNSIGNED NULL,
  siatka_kolumny SMALLINT UNSIGNED NULL,
  siatka_wiersze SMALLINT UNSIGNED NULL,
  zyla_u0_v DECIMAL(8,3) NOT NULL,
  zyla_r0_ohm DECIMAL(8,3) NOT NULL,
  oslony_domyslnie_hz SMALLINT UNSIGNED NOT NULL,
  po_utracie_generatora VARCHAR(500) NULL,
  ekran_zyl VARCHAR(160) NULL,
  baterie TINYINT UNSIGNED NOT NULL DEFAULT 0,
  lufy_na_baterie TINYINT UNSIGNED NOT NULL DEFAULT 0,
  zyly_baterii SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ladowanie_tiki TINYINT UNSIGNED NOT NULL DEFAULT 6,
  przydzial_baterii DECIMAL(4,3) NOT NULL DEFAULT 0,
  obrona_punktowa SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  mysliwce SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  silniki_glowne TINYINT UNSIGNED NULL,
  silniki_manewrowe TINYINT UNSIGNED NULL,
  obrot_deg_na_min DECIMAL(6,2) NULL,
  predkosc_km_s DECIMAL(6,3) NOT NULL,
  status ENUM('ustalony','roboczy','propozycja') NOT NULL DEFAULT 'roboczy',
  zmieniono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profile_klas_jednostki (
  profil_kod VARCHAR(20) NOT NULL,
  nr TINYINT UNSIGNED NOT NULL,
  nazwa VARCHAR(160) NOT NULL,
  rola ENUM('rdzen','dystrybucja','pomocniczy','zapasowy','zasobnik') NOT NULL,
  PRIMARY KEY (profil_kod, nr),
  FOREIGN KEY (profil_kod) REFERENCES profile_klas (kod) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profile_klas_sektory (
  profil_kod VARCHAR(20) NOT NULL,
  nr TINYINT UNSIGNED NOT NULL,
  nazwa VARCHAR(160) NOT NULL,
  PRIMARY KEY (profil_kod, nr),
  FOREIGN KEY (profil_kod) REFERENCES profile_klas (kod) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sekcje kadłuba (W13): integralność 0–100 z progami 75 / 50 / 25; udział = waga w średniej kadłuba.
CREATE TABLE IF NOT EXISTS profile_klas_kadlub (
  profil_kod VARCHAR(20) NOT NULL,
  sekcja VARCHAR(40) NOT NULL,
  nazwa VARCHAR(160) NOT NULL,
  udzial DECIMAL(4,3) NOT NULL,
  PRIMARY KEY (profil_kod, sekcja),
  FOREIGN KEY (profil_kod) REFERENCES profile_klas (kod) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS typy_maszyn (
  kod VARCHAR(20) NOT NULL PRIMARY KEY,
  nazwa VARCHAR(120) NOT NULL,
  strona VARCHAR(40) NOT NULL,
  rola VARCHAR(80) NOT NULL,
  hz_bazowe SMALLINT UNSIGNED NOT NULL,
  oslona_hz SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  predkosc_km_s DECIMAL(5,3) NOT NULL,
  przekroj_m2 DECIMAL(7,1) NOT NULL,
  energia_strzalu_j DOUBLE NOT NULL,
  ladowanie_tiki TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('ustalony','roboczy','propozycja') NOT NULL DEFAULT 'propozycja',
  opis VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Czas wykonania rozkazów (MECHANIKA 1.1). dzialanie_s NULL = działa do następnej zmiany.
-- theta_mn — mnożnik rozrzutu strzałów W tę jednostkę (unik), theta_wlasna_mn — rozrzut WŁASNYCH strzałów (<1 celniej).
CREATE TABLE IF NOT EXISTS rozkazy_profile (
  kod VARCHAR(30) NOT NULL PRIMARY KEY,
  nazwa VARCHAR(120) NOT NULL,
  grupa ENUM('kurs','unik','as','zawrot','szyk','walka','rozkaz_klucza','okret') NOT NULL,
  przygotowanie_s DECIMAL(5,1) NOT NULL,
  dzialanie_s DECIMAL(6,1) NULL,
  odnowienie_s DECIMAL(6,1) NOT NULL DEFAULT 0,
  theta_mn DECIMAL(4,2) NULL,
  theta_wlasna_mn DECIMAL(4,2) NULL,
  predkosc_mn DECIMAL(4,2) NULL,
  ogien_wylaczony TINYINT(1) NOT NULL DEFAULT 0,
  ranga_min ENUM('kadet','standard','as') NOT NULL DEFAULT 'kadet',
  ranga_skraca TINYINT(1) NOT NULL DEFAULT 0,
  opis VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------------------------
-- Scenariusze
-- ---------------------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS bitwy_scenariusze (
  kod VARCHAR(40) NOT NULL PRIMARY KEY,
  nazwa VARCHAR(160) NOT NULL,
  odprawa TEXT NOT NULL,
  opis_mg TEXT NULL,
  okno_s INT UNSIGNED NOT NULL,
  pole_km INT UNSIGNED NOT NULL DEFAULT 200,
  zwyciestwo VARCHAR(500) NOT NULL,
  porazka VARCHAR(500) NOT NULL,
  status ENUM('roboczy','gotowy','wycofany') NOT NULL DEFAULT 'roboczy',
  zmieniono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bitwy_scenariusze_strony (
  scenariusz_kod VARCHAR(40) NOT NULL,
  strona VARCHAR(120) NOT NULL,
  kolor CHAR(7) NOT NULL,
  dowodzenie ENUM('gracze','radus','ackbar','calrissian') NOT NULL,
  rola ENUM('obrona','atak') NOT NULL,
  asy_proc TINYINT UNSIGNED NOT NULL DEFAULT 5,
  kadeci_proc TINYINT UNSIGNED NOT NULL DEFAULT 35,
  PRIMARY KEY (scenariusz_kod, strona),
  FOREIGN KEY (scenariusz_kod) REFERENCES bitwy_scenariusze (kod) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- z_mapy = 1: przy tworzeniu symulacji MG wskazuje jednostkę z mapy tej klasy (np. Walako); 0: jednostka wirtualna.
CREATE TABLE IF NOT EXISTS bitwy_scenariusze_jednostki (
  scenariusz_kod VARCHAR(40) NOT NULL,
  nr TINYINT UNSIGNED NOT NULL,
  strona VARCHAR(120) NOT NULL,
  profil_kod VARCHAR(20) NOT NULL,
  nazwa VARCHAR(160) NOT NULL,
  z_mapy TINYINT(1) NOT NULL DEFAULT 0,
  x_km DECIMAL(10,2) NOT NULL,
  y_km DECIMAL(10,2) NOT NULL,
  kurs_deg DECIMAL(6,2) NOT NULL DEFAULT 0,
  predkosc_km_s DECIMAL(6,3) NOT NULL DEFAULT 0,
  kadlub TINYINT UNSIGNED NOT NULL DEFAULT 100,
  wejscie_s INT UNSIGNED NOT NULL DEFAULT 0,
  wejscie_rozrzut_s INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (scenariusz_kod, nr),
  FOREIGN KEY (scenariusz_kod, strona) REFERENCES bitwy_scenariusze_strony (scenariusz_kod, strona) ON DELETE CASCADE,
  FOREIGN KEY (profil_kod) REFERENCES profile_klas (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fale kluczy: jednostka_nr = okręt macierzysty (NULL: klucze z zewnątrz, np. wyjście ze skoku), start_s — wyjście
-- z hangaru pierwszego klucza fali, rozrzut losowany z ziarna bitwy. Pozycja startu dla kluczy z zewnątrz: x_km, y_km.
CREATE TABLE IF NOT EXISTS bitwy_scenariusze_klucze (
  scenariusz_kod VARCHAR(40) NOT NULL,
  fala TINYINT UNSIGNED NOT NULL,
  strona VARCHAR(120) NOT NULL,
  jednostka_nr TINYINT UNSIGNED NULL,
  typ_maszyny VARCHAR(20) NOT NULL,
  liczba_kluczy TINYINT UNSIGNED NOT NULL,
  start_s INT UNSIGNED NOT NULL DEFAULT 0,
  start_rozrzut_s INT UNSIGNED NOT NULL DEFAULT 0,
  x_km DECIMAL(10,2) NULL,
  y_km DECIMAL(10,2) NULL,
  rozkaz VARCHAR(30) NOT NULL DEFAULT 'patrol',
  PRIMARY KEY (scenariusz_kod, fala, strona, typ_maszyny),
  FOREIGN KEY (scenariusz_kod, strona) REFERENCES bitwy_scenariusze_strony (scenariusz_kod, strona) ON DELETE CASCADE,
  FOREIGN KEY (typ_maszyny) REFERENCES typy_maszyn (kod),
  FOREIGN KEY (rozkaz) REFERENCES rozkazy_profile (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bitwy_scenariusze_wyzwalacze (
  scenariusz_kod VARCHAR(40) NOT NULL,
  nr TINYINT UNSIGNED NOT NULL,
  czas_s INT UNSIGNED NOT NULL,
  rozrzut_s INT UNSIGNED NOT NULL DEFAULT 0,
  rodzaj ENUM('komunikat','posilki','koniec') NOT NULL,
  tresc VARCHAR(500) NOT NULL,
  PRIMARY KEY (scenariusz_kod, nr),
  FOREIGN KEY (scenariusz_kod) REFERENCES bitwy_scenariusze (kod) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------------------------
-- Istniejące tabele
-- ---------------------------------------------------------------------------------------------------------------

-- Zegar bitwy: czas bitwy = (teraz − zegar_start_utc) − zegar_pauzy_s − (pauza trwa ? teraz − pauza_od_utc : 0).
-- Pauza i cofnięcie tylko dla MG (symulacja); bitwa rzeczywista płynie 1:1 bez pauzy.
ALTER TABLE mapa_bitwy
  MODIFY planeta_id INT UNSIGNED NULL,
  MODIFY status ENUM('przygotowanie','aktywna','zakonczona','anulowana') NOT NULL DEFAULT 'aktywna',
  ADD COLUMN rodzaj ENUM('rzeczywista','symulacja') NOT NULL DEFAULT 'rzeczywista' AFTER nazwa,
  ADD COLUMN scenariusz_kod VARCHAR(40) NULL AFTER rodzaj,
  ADD COLUMN ziarno INT UNSIGNED NOT NULL DEFAULT 0 AFTER scenariusz_kod,
  ADD COLUMN zegar_start_utc DATETIME(3) NULL AFTER ziarno,
  ADD COLUMN zegar_pauzy_s DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER zegar_start_utc,
  ADD COLUMN pauza_od_utc DATETIME(3) NULL AFTER zegar_pauzy_s,
  ADD COLUMN tik_policzony INT UNSIGNED NOT NULL DEFAULT 0 AFTER pauza_od_utc,
  ADD COLUMN kotwica_x DECIMAL(14,1) NULL AFTER tik_policzony,
  ADD COLUMN kotwica_y DECIMAL(14,1) NULL AFTER kotwica_x,
  ADD COLUMN prowadzacy_login VARCHAR(64) NULL AFTER kotwica_y,
  ADD KEY ix_mapa_bitwy_rodzaj (rodzaj, status),
  ADD CONSTRAINT fk_mapa_bitwy_scenariusz FOREIGN KEY (scenariusz_kod) REFERENCES bitwy_scenariusze (kod);

ALTER TABLE mapa_piloci MODIFY xp DECIMAL(10,1) NOT NULL DEFAULT 0;

ALTER TABLE mapa_obiekty
  ADD COLUMN profil_klasy VARCHAR(20) NULL,
  ADD CONSTRAINT fk_mapa_obiekty_profil FOREIGN KEY (profil_klasy) REFERENCES profile_klas (kod);

-- ---------------------------------------------------------------------------------------------------------------
-- Stan bitwy (silnik)
-- ---------------------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS bitwy_jednostki (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bitwa_id BIGINT UNSIGNED NOT NULL,
  obiekt_id BIGINT UNSIGNED NULL,
  profil_kod VARCHAR(20) NOT NULL,
  nazwa VARCHAR(160) NOT NULL,
  strona VARCHAR(120) NOT NULL,
  scenariusz_nr TINYINT UNSIGNED NULL,
  stan ENUM('oczekuje','w_bitwie','wycofana','unieszkodliwiona','zniszczona') NOT NULL DEFAULT 'oczekuje',
  wejscie_tik INT UNSIGNED NOT NULL DEFAULT 0,
  x_km DECIMAL(12,3) NOT NULL DEFAULT 0,
  y_km DECIMAL(12,3) NOT NULL DEFAULT 0,
  vx_km_s DECIMAL(10,5) NOT NULL DEFAULT 0,
  vy_km_s DECIMAL(10,5) NOT NULL DEFAULT 0,
  zwrot_deg DECIMAL(8,3) NOT NULL DEFAULT 0,
  omega_deg_s DECIMAL(8,4) NOT NULL DEFAULT 0,
  kadlub_start TINYINT UNSIGNED NOT NULL DEFAULT 100,
  dlawienie DECIMAL(4,3) NOT NULL DEFAULT 1,
  margines_cieplny DECIMAL(7,3) NOT NULL DEFAULT 100,
  zasobnik_porcje DECIMAL(8,3) NOT NULL DEFAULT 0,
  KEY ix_bitwy_jednostki (bitwa_id, strona),
  FOREIGN KEY (bitwa_id) REFERENCES mapa_bitwy (id) ON DELETE CASCADE,
  FOREIGN KEY (obiekt_id) REFERENCES mapa_obiekty (id) ON DELETE SET NULL,
  FOREIGN KEY (profil_kod) REFERENCES profile_klas (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bitwy_sekcje (
  bitwa_jednostka_id BIGINT UNSIGNED NOT NULL,
  sekcja VARCHAR(40) NOT NULL,
  integralnosc DECIMAL(5,2) NOT NULL DEFAULT 100,
  PRIMARY KEY (bitwa_jednostka_id, sekcja),
  FOREIGN KEY (bitwa_jednostka_id) REFERENCES bitwy_jednostki (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System okrętu w bitwie: generator osłon, bateria, rdzeń, silnik, sensory… (kod np. oslona_L, bateria_P3, rdzen_2).
CREATE TABLE IF NOT EXISTS bitwy_systemy (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bitwa_jednostka_id BIGINT UNSIGNED NOT NULL,
  kod VARCHAR(40) NOT NULL,
  stan ENUM('gotowy','przestrajanie','przeciazony','uszkodzony','wylaczony','zniszczony') NOT NULL DEFAULT 'gotowy',
  hz_zadane SMALLINT UNSIGNED NULL,
  hz_osiagane SMALLINT UNSIGNED NULL,
  hz_min SMALLINT UNSIGNED NULL,
  hz_max SMALLINT UNSIGNED NULL,
  przydzial_porcji DECIMAL(7,3) NOT NULL DEFAULT 0,
  ladunek DECIMAL(5,4) NOT NULL DEFAULT 0,
  obciazenie_j DOUBLE NOT NULL DEFAULT 0,
  rykoszet_j DOUBLE NOT NULL DEFAULT 0,
  temperatura DECIMAL(8,3) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_bitwy_systemy (bitwa_jednostka_id, kod),
  FOREIGN KEY (bitwa_jednostka_id) REFERENCES bitwy_jednostki (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Żyły generatora lub baterii: martwe — mapa bitowa (bit n = żyła n+1 martwa; 3600 żył = 450 B),
-- temperatury — 1 bajt na żyłę (0–255 = 0–51 °C umownych, żyła ginie powyżej 40).
CREATE TABLE IF NOT EXISTS bitwy_zyly (
  system_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  liczba SMALLINT UNSIGNED NOT NULL,
  martwe VARBINARY(1024) NOT NULL,
  temperatury VARBINARY(4096) NOT NULL,
  FOREIGN KEY (system_id) REFERENCES bitwy_systemy (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Klucz w bitwie. Stan lampy (PULPITY 1b): hangar (odlicza do start_tik) → gotowy → w_locie → powrot → stracony.
CREATE TABLE IF NOT EXISTS bitwy_klucze (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bitwa_id BIGINT UNSIGNED NOT NULL,
  strona VARCHAR(120) NOT NULL,
  macierzysta_id BIGINT UNSIGNED NULL,
  mapa_klucz_id INT NULL,
  numer SMALLINT UNSIGNED NOT NULL,
  typ_maszyny VARCHAR(20) NOT NULL,
  maszyny TINYINT UNSIGNED NOT NULL DEFAULT 4,
  stan ENUM('hangar','gotowy','w_locie','powrot','stracony') NOT NULL DEFAULT 'hangar',
  start_tik INT UNSIGNED NULL,
  hz_bazowe SMALLINT UNSIGNED NOT NULL,
  moc DECIMAL(4,3) NOT NULL DEFAULT 0,
  figura VARCHAR(30) NULL,
  figura_od_tik INT UNSIGNED NULL,
  zmiany_w_cyklu TINYINT UNSIGNED NOT NULL DEFAULT 0,
  cykl_nr INT UNSIGNED NOT NULL DEFAULT 0,
  rozkaz VARCHAR(30) NULL,
  cel_jednostka_id BIGINT UNSIGNED NULL,
  cel_klucz_id BIGINT UNSIGNED NULL,
  x_km DECIMAL(12,3) NOT NULL DEFAULT 0,
  y_km DECIMAL(12,3) NOT NULL DEFAULT 0,
  vx_km_s DECIMAL(10,5) NOT NULL DEFAULT 0,
  vy_km_s DECIMAL(10,5) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_bitwy_klucze (bitwa_id, strona, numer),
  FOREIGN KEY (bitwa_id) REFERENCES mapa_bitwy (id) ON DELETE CASCADE,
  FOREIGN KEY (macierzysta_id) REFERENCES bitwy_jednostki (id) ON DELETE SET NULL,
  FOREIGN KEY (typ_maszyny) REFERENCES typy_maszyn (kod),
  FOREIGN KEY (figura) REFERENCES rozkazy_profile (kod),
  FOREIGN KEY (rozkaz) REFERENCES rozkazy_profile (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pilot w kluczu bitwy: z mapa_piloci (pilot_id) albo wirtualny (pilot_id NULL, np. rebelianci w symulacji).
CREATE TABLE IF NOT EXISTS bitwy_piloci (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bitwa_klucz_id BIGINT UNSIGNED NOT NULL,
  pilot_id INT NULL,
  imie VARCHAR(160) NOT NULL,
  ranga ENUM('as','standard','kadet') NOT NULL,
  stan ENUM('w_kluczu','zestrzelony','uratowany') NOT NULL DEFAULT 'w_kluczu',
  akcje INT UNSIGNED NOT NULL DEFAULT 0,
  KEY ix_bitwy_piloci_pilot (pilot_id),
  FOREIGN KEY (bitwa_klucz_id) REFERENCES bitwy_klucze (id) ON DELETE CASCADE,
  FOREIGN KEY (pilot_id) REFERENCES mapa_piloci (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bitwy_stanowiska (
  bitwa_jednostka_id BIGINT UNSIGNED NOT NULL,
  pulpit VARCHAR(40) NOT NULL,
  login VARCHAR(64) NULL,
  PRIMARY KEY (bitwa_jednostka_id, pulpit),
  KEY ix_bitwy_stanowiska_login (login),
  FOREIGN KEY (bitwa_jednostka_id) REFERENCES bitwy_jednostki (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blokady kapitana i przyciski obowiązkowe (PULPITY 4); element = tekst lub id gniazda pulpitu.
CREATE TABLE IF NOT EXISTS bitwy_blokady (
  bitwa_jednostka_id BIGINT UNSIGNED NOT NULL,
  pulpit VARCHAR(40) NOT NULL,
  element VARCHAR(120) NOT NULL,
  poziom ENUM('obligatoryjny','dostepny','zablokowany') NOT NULL,
  login VARCHAR(64) NULL,
  PRIMARY KEY (bitwa_jednostka_id, pulpit, element),
  FOREIGN KEY (bitwa_jednostka_id) REFERENCES bitwy_jednostki (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dziennik rozkazów (wejścia silnika). uid — identyfikator nadany przez konsolę: ponowiona wysyłka nie tworzy
-- drugiego rozkazu. odebrano_utc — czas serwera (komputera centralnego); od niego liczy się czas wykonania.
CREATE TABLE IF NOT EXISTS bitwy_rozkazy (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bitwa_id BIGINT UNSIGNED NOT NULL,
  uid CHAR(36) NOT NULL,
  odebrano_utc DATETIME(3) NOT NULL,
  tik INT UNSIGNED NOT NULL,
  login VARCHAR(64) NULL,
  ai TINYINT(1) NOT NULL DEFAULT 0,
  bitwa_jednostka_id BIGINT UNSIGNED NULL,
  bitwa_klucz_id BIGINT UNSIGNED NULL,
  pulpit VARCHAR(40) NULL,
  system_kod VARCHAR(40) NULL,
  rozkaz_kod VARCHAR(30) NULL,
  parametr VARCHAR(60) NULL,
  wartosc_przed VARCHAR(60) NULL,
  wartosc_po VARCHAR(60) NULL,
  przyczyna VARCHAR(255) NULL,
  stan ENUM('przygotowanie','dziala','zakonczony','przerwany') NOT NULL DEFAULT 'przygotowanie',
  dziala_od_tik INT UNSIGNED NULL,
  dziala_do_tik INT UNSIGNED NULL,
  odnowienie_do_tik INT UNSIGNED NULL,
  przerwany_przez BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_bitwy_rozkazy_uid (bitwa_id, uid),
  KEY ix_bitwy_rozkazy_tik (bitwa_id, tik),
  FOREIGN KEY (bitwa_id) REFERENCES mapa_bitwy (id) ON DELETE CASCADE,
  FOREIGN KEY (bitwa_jednostka_id) REFERENCES bitwy_jednostki (id) ON DELETE CASCADE,
  FOREIGN KEY (bitwa_klucz_id) REFERENCES bitwy_klucze (id) ON DELETE CASCADE,
  FOREIGN KEY (rozkaz_kod) REFERENCES rozkazy_profile (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bitwy_zdarzenia (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bitwa_id BIGINT UNSIGNED NOT NULL,
  tik INT UNSIGNED NOT NULL,
  rodzaj VARCHAR(40) NOT NULL,
  sprawca_jednostka_id BIGINT UNSIGNED NULL,
  sprawca_klucz_id BIGINT UNSIGNED NULL,
  cel_jednostka_id BIGINT UNSIGNED NULL,
  cel_klucz_id BIGINT UNSIGNED NULL,
  wartosc DOUBLE NULL,
  opis VARCHAR(255) NULL,
  KEY ix_bitwy_zdarzenia_tik (bitwa_id, tik),
  FOREIGN KEY (bitwa_id) REFERENCES mapa_bitwy (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migawka stanu co cykl lub minutę — do cofnięcia przez MG (zrzut binarny tabel bitwy, nie JSON).
CREATE TABLE IF NOT EXISTS bitwy_migawki (
  bitwa_id BIGINT UNSIGNED NOT NULL,
  tik INT UNSIGNED NOT NULL,
  dane LONGBLOB NOT NULL,
  PRIMARY KEY (bitwa_id, tik),
  FOREIGN KEY (bitwa_id) REFERENCES mapa_bitwy (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- XP pilotów z bitwy: symulacja — 0,5 za akcję × mnożnik rangi (as 0,5, standardowy 1, kadet 1,5);
-- trafia do mapa_piloci.xp po zatwierdzeniu przez MG (zatwierdzono_utc).
CREATE TABLE IF NOT EXISTS bitwy_xp (
  bitwa_id BIGINT UNSIGNED NOT NULL,
  pilot_id INT NOT NULL,
  akcje INT UNSIGNED NOT NULL DEFAULT 0,
  xp_surowe DECIMAL(8,1) NOT NULL DEFAULT 0,
  mnoznik DECIMAL(3,2) NOT NULL DEFAULT 1,
  xp DECIMAL(8,1) NOT NULL DEFAULT 0,
  zatwierdzono_utc DATETIME NULL,
  zatwierdzil_login VARCHAR(64) NULL,
  PRIMARY KEY (bitwa_id, pilot_id),
  FOREIGN KEY (bitwa_id) REFERENCES mapa_bitwy (id) ON DELETE CASCADE,
  FOREIGN KEY (pilot_id) REFERENCES mapa_piloci (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v068-bitwy-symulacje', 'Bitwy i symulacje bitew: słowniki mechaniki, profile klas, scenariusze, stan bitwy, XP pilotów DECIMAL')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

-- =====================================================================
-- HOLOLINK — schemat docelowy v2 (relacyjny)
-- MySQL 8.0.16+ / MariaDB 10.6+ (wymagane CHECK i klucze obce).
--
-- Dopisuje tabele do istniejącej bazy z 001_podstawa.sql. Nie rusza datapad_dane,
-- mapa_planety ani mapa_szlaki: datapad_dane zostaje jako archiwum
-- dokumentów do czasu migracji modułów, planety i szlaki są już relacyjne.
-- Skrypt jest powtarzalny (CREATE TABLE IF NOT EXISTS).
--
-- ZASADY
--  * Własność: jedna tabela `podmioty`. Gracz, osoba, firma, frakcja
--    i instytucja to podtypy z kluczem (podmiot_id, typ), więc baza sama
--    pilnuje, że np. firma nie wskazuje na postać.
--  * Pieniądze: tylko przez `ksiegowania` + `ksiegowania_pozycje`
--    (księgowość podwójnego zapisu). Zapisy zewnętrzne idą z/do rachunków
--    systemowych instytucji (patrz seed na końcu), więc suma pozycji = 0.
--  * Czas: czas_utc (rzeczywisty) i czas_gst (minuty czasu gry, BIGINT).
--    Przeliczenie: patrz `zegar_swiata`.
--  * Wskazanie jednego z kilku typów obiektów: osobne kolumny FK
--    i CHECK „dokładnie jedna wypełniona” (bez wskaźników bez kluczy).
--  * JSON tylko w mapa_szlaki.sciezki (istniejące) i dziennik_zdarzen.dane.
--  * Ukryte statystyki osób (osoby_statystyki) nigdy nie trafiają do datapada.
--
-- WARSTWY
--   0 meta · 1 tożsamość i własność · 2 świat · 3 osoby i firmy
--   4 gospodarka · 5 flota i wyposażenie · 6 astronawigacja i blokady
--   7 informacja i wywiad · 8 postacie, NPC, dialogi · 9 historia
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 0. META
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS schema_migracje (
  wersja VARCHAR(40) NOT NULL PRIMARY KEY,
  opis VARCHAR(255) NOT NULL,
  zastosowano TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ustawienia (
  klucz VARCHAR(80) NOT NULL PRIMARY KEY,
  wartosc VARCHAR(500) NOT NULL,
  opis VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jeden wiersz (id = 1). czas_gst(minuty) = minuta_gst_w_epoce
--   + (teraz_utc - epoka_utc) w sekundach / sekund_na_minute_gst
CREATE TABLE IF NOT EXISTS zegar_swiata (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY CHECK (id = 1),
  epoka_utc DATETIME NOT NULL,
  minuta_gst_w_epoce BIGINT NOT NULL DEFAULT 0,
  sekund_na_minute_gst DECIMAL(8,3) NOT NULL DEFAULT 1.000 CHECK (sekund_na_minute_gst > 0),
  wstrzymany TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. TOŻSAMOŚĆ I WŁASNOŚĆ
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS konta (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  login VARCHAR(64) NOT NULL,
  hash VARCHAR(255) NOT NULL,
  rola ENUM('gracz','mg') NOT NULL DEFAULT 'gracz',
  utworzono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ostatnie_logowanie DATETIME NULL,
  UNIQUE KEY uq_konta_login (login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limit prób logowania liczony po loginie i adresie IP (nie po sesji).
CREATE TABLE IF NOT EXISTS proby_logowania (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  login VARCHAR(64) NOT NULL,
  ip VARBINARY(16) NOT NULL,
  czas DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  udane TINYINT(1) NOT NULL DEFAULT 0,
  KEY ix_proby_login (login, czas),
  KEY ix_proby_ip (ip, czas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS podmioty (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  typ VARCHAR(12) NOT NULL CHECK (typ IN ('postac','osoba','firma','frakcja','instytucja')),
  nazwa VARCHAR(160) NOT NULL,
  status ENUM('aktywny','zawieszony','usuniety') NOT NULL DEFAULT 'aktywny',
  utworzono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_podmioty_id_typ (id, typ),
  KEY ix_podmioty_nazwa (nazwa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS frakcje (
  podmiot_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  typ VARCHAR(12) NOT NULL DEFAULT 'frakcja' CHECK (typ = 'frakcja'),
  kod VARCHAR(40) NOT NULL,
  opis TEXT NULL,
  UNIQUE KEY uq_frakcje_kod (kod),
  FOREIGN KEY (podmiot_id, typ) REFERENCES podmioty (id, typ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instytucje (
  podmiot_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  typ VARCHAR(12) NOT NULL DEFAULT 'instytucja' CHECK (typ = 'instytucja'),
  rodzaj ENUM('bank','gielda','urzad','system','inne') NOT NULL DEFAULT 'inne',
  frakcja_id BIGINT UNSIGNED NULL,
  opis TEXT NULL,
  FOREIGN KEY (podmiot_id, typ) REFERENCES podmioty (id, typ),
  FOREIGN KEY (frakcja_id) REFERENCES frakcje (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS postacie (
  podmiot_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  typ VARCHAR(12) NOT NULL DEFAULT 'postac' CHECK (typ = 'postac'),
  konto_id BIGINT UNSIGNED NULL,
  numer_id VARCHAR(32) NULL,
  frakcja_id BIGINT UNSIGNED NULL,
  oddzial VARCHAR(80) NOT NULL DEFAULT '',
  ranga VARCHAR(80) NOT NULL DEFAULT '',
  poziom_uprawnien TINYINT UNSIGNED NOT NULL DEFAULT 1 CHECK (poziom_uprawnien BETWEEN 1 AND 9),
  status_rejestru ENUM('czysty','notowany','poszukiwany') NOT NULL DEFAULT 'czysty',
  zleceniodawca_listu VARCHAR(160) NULL,
  nagroda BIGINT UNSIGNED NULL,
  uwagi_rejestru TEXT NULL,
  UNIQUE KEY uq_postacie_numer_id (numer_id),
  KEY ix_postacie_konto (konto_id),
  FOREIGN KEY (podmiot_id, typ) REFERENCES podmioty (id, typ),
  FOREIGN KEY (konto_id) REFERENCES konta (id),
  FOREIGN KEY (frakcja_id) REFERENCES frakcje (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. ŚWIAT
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS regiony (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  nazwa VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS systemy_nawigacyjne (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kod VARCHAR(40) NOT NULL,
  nazwa VARCHAR(120) NOT NULL,
  model ENUM('kolowy','eliptyczny','statyczny','inny') NOT NULL DEFAULT 'kolowy',
  opis TEXT NULL,
  UNIQUE KEY uq_systemy_nawigacyjne_kod (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uklady_planetarne (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nazwa VARCHAR(160) NOT NULL,
  gwiazda_x DECIMAL(14,3) NOT NULL,
  gwiazda_y DECIMAL(14,3) NOT NULL,
  gwiazda_z DECIMAL(14,3) NOT NULL DEFAULT 0,
  typ_gwiazdy VARCHAR(40) NULL,
  promien_strefy_niebezpiecznej DECIMAL(12,4) NOT NULL DEFAULT 0 CHECK (promien_strefy_niebezpiecznej >= 0),
  UNIQUE KEY uq_uklady_nazwa (nazwa),
  KEY ix_uklady_xyz (gwiazda_x, gwiazda_y, gwiazda_z)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rozszerzenie planety z mapy (1:1). Planety spoza publicznej mapy
-- i zastrzeżone też mają wiersz w mapa_planety (x, y są wymagane).
CREATE TABLE IF NOT EXISTS planety_gry (
  planeta_id INT UNSIGNED NOT NULL PRIMARY KEY,
  uklad_id INT UNSIGNED NULL,
  typ_planety ENUM('pustynna','lodowa','oceaniczna','lesna','wulkaniczna','gazowa','miasto','bagienna','skalista') NULL,
  urbanizacja ENUM('brak','niska','wysoka') NOT NULL DEFAULT 'brak',
  taksowka_kr_za_km INT UNSIGNED NULL,
  kontrola_lotow VARCHAR(120) NULL,
  zastrzezona TINYINT(1) NOT NULL DEFAULT 0,
  opis TEXT NULL,
  KEY ix_planety_gry_uklad (uklad_id),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id),
  FOREIGN KEY (uklad_id) REFERENCES uklady_planetarne (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kto widzi planetę zastrzeżoną (MG widzi zawsze).
CREATE TABLE IF NOT EXISTS planety_widocznosc (
  planeta_id INT UNSIGNED NOT NULL,
  podmiot_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (planeta_id, podmiot_id),
  FOREIGN KEY (planeta_id) REFERENCES planety_gry (planeta_id),
  FOREIGN KEY (podmiot_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parametry orbity (model Hololink: ruch jednostajny po okręgu, położenie
-- liczone z czasu, nigdy zapisywane). Nachylenie i węzeł trzymamy na zapas.
CREATE TABLE IF NOT EXISTS planety_orbity (
  planeta_id INT UNSIGNED NOT NULL PRIMARY KEY,
  uklad_id INT UNSIGNED NOT NULL,
  system_id INT UNSIGNED NOT NULL,
  promien_orbity DECIMAL(16,4) NOT NULL CHECK (promien_orbity >= 0),
  nachylenie_deg DECIMAL(7,4) NOT NULL DEFAULT 0,
  wezel_wstepujacy_deg DECIMAL(7,4) NOT NULL DEFAULT 0,
  faza_poczatkowa_deg DECIMAL(7,4) NOT NULL DEFAULT 0,
  okres_orbitalny_dni DECIMAL(14,4) NOT NULL CHECK (okres_orbitalny_dni > 0),
  epoka_gst BIGINT NOT NULL DEFAULT 0,
  kierunek ENUM('zgodny','przeciwny') NOT NULL DEFAULT 'zgodny',
  KEY ix_orbity_uklad (uklad_id),
  FOREIGN KEY (planeta_id) REFERENCES planety_gry (planeta_id),
  FOREIGN KEY (uklad_id) REFERENCES uklady_planetarne (id),
  FOREIGN KEY (system_id) REFERENCES systemy_nawigacyjne (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kalendarze_planetarne (
  planeta_id INT UNSIGNED NOT NULL PRIMARY KEY,
  nazwa_kalendarza VARCHAR(120) NOT NULL,
  dlugosc_doby_h DECIMAL(10,4) NOT NULL CHECK (dlugosc_doby_h > 0),
  dlugosc_roku_dni DECIMAL(12,4) NOT NULL CHECK (dlugosc_roku_dni > 0),
  epoka_zero_gst BIGINT NOT NULL DEFAULT 0,
  wektor_odniesienia_deg DECIMAL(7,4) NOT NULL DEFAULT 0,
  predkosc_katowa_deg_na_dobe DECIMAL(14,8) NULL,
  czy_publiczny TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (planeta_id) REFERENCES planety_gry (planeta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wspólny system położeń: układ → orbita → planeta → lokacja → budynek/lądowisko.
-- x_km, y_km: współrzędne lokalne na powierzchni planety.
CREATE TABLE IF NOT EXISTS lokalizacje (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  typ ENUM('uklad','orbita','powierzchnia','lokacja','budynek','ladowisko') NOT NULL,
  rodzaj ENUM('hangar','port','hotel','bankomat','sklep','kantyna','biuro','garaz','baza','magazyn','platforma','inne') NULL,
  rodzic_id BIGINT UNSIGNED NULL,
  uklad_id INT UNSIGNED NULL,
  planeta_id INT UNSIGNED NULL,
  nazwa VARCHAR(200) NOT NULL,
  x_km DECIMAL(12,3) NULL,
  y_km DECIMAL(12,3) NULL,
  publiczna TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('aktywna','zamknieta','zniszczona') NOT NULL DEFAULT 'aktywna',
  opis TEXT NULL,
  UNIQUE KEY uq_lokalizacje_planeta_nazwa (planeta_id, nazwa),
  KEY ix_lokalizacje_rodzic (rodzic_id),
  KEY ix_lokalizacje_xy (planeta_id, x_km, y_km),
  CHECK (uklad_id IS NOT NULL OR planeta_id IS NOT NULL),
  FOREIGN KEY (rodzic_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (uklad_id) REFERENCES uklady_planetarne (id),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS znane_lokacje (
  postac_id BIGINT UNSIGNED NOT NULL,
  lokalizacja_id BIGINT UNSIGNED NOT NULL,
  zrodlo ENUM('publiczna','firma','karta','kontrola_lotow','ladowanie','mg') NOT NULL DEFAULT 'karta',
  od TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (postac_id, lokalizacja_id),
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS znane_planety (
  postac_id BIGINT UNSIGNED NOT NULL,
  planeta_id INT UNSIGNED NOT NULL,
  zrodlo VARCHAR(60) NOT NULL DEFAULT 'mapa',
  poziom_dostepu TINYINT UNSIGNED NOT NULL DEFAULT 1,
  kalendarz_odblokowany TINYINT(1) NOT NULL DEFAULT 0,
  data_odkrycia DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (postac_id, planeta_id),
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. OSOBY I FIRMY
-- ---------------------------------------------------------------------
-- Menedżer firmy = zatrudnienie ze stanowiskiem 'menedzer' (bez osobnej kolumny, bez pętli kluczy).

CREATE TABLE IF NOT EXISTS firmy (
  podmiot_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  typ VARCHAR(12) NOT NULL DEFAULT 'firma' CHECK (typ = 'firma'),
  profil ENUM('transport','wydobycie','produkcja','finanse','komorka','agencja') NOT NULL,
  kapital_zakladowy BIGINT UNSIGNED NOT NULL DEFAULT 0,
  skala TINYINT UNSIGNED NOT NULL DEFAULT 1,
  opis TEXT NULL,
  FOREIGN KEY (podmiot_id, typ) REFERENCES podmioty (id, typ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Osoba = NPC, członek załogi, agent, kandydat. Może być powiązana
-- z postacią gracza (wtedy wypłaty idą na jego rachunek).
CREATE TABLE IF NOT EXISTS osoby (
  podmiot_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  typ VARCHAR(12) NOT NULL DEFAULT 'osoba' CHECK (typ = 'osoba'),
  rola VARCHAR(120) NOT NULL DEFAULT '',
  frakcja_id BIGINT UNSIGNED NULL,
  stan ENUM('zdrowy','ranny','spalony','zatrzymany','polegly') NOT NULL DEFAULT 'zdrowy',
  pula_kandydatow ENUM('brak','ogolna','agencja') NOT NULL DEFAULT 'brak',
  lokalizacja_id BIGINT UNSIGNED NULL,
  postac_id BIGINT UNSIGNED NULL,
  agencja_id BIGINT UNSIGNED NULL,
  kryptonim VARCHAR(60) NULL,
  czy_holo_dostepne TINYINT(1) NOT NULL DEFAULT 0,
  glos_id VARCHAR(60) NULL,
  model_holo VARCHAR(80) NULL,
  KEY ix_osoby_lokalizacja (lokalizacja_id),
  KEY ix_osoby_agencja (agencja_id),
  UNIQUE KEY uq_osoby_postac (postac_id),
  FOREIGN KEY (podmiot_id, typ) REFERENCES podmioty (id, typ),
  FOREIGN KEY (frakcja_id) REFERENCES frakcje (podmiot_id),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id),
  FOREIGN KEY (agencja_id) REFERENCES firmy (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ukryte statystyki 1–10. Backend trzyma dokładne wartości, interfejs ich nie ujawnia.
CREATE TABLE IF NOT EXISTS osoby_statystyki (
  osoba_id BIGINT UNSIGNED NOT NULL,
  cecha ENUM('pilotaz','dowodzenie','taktyka','zarzadzanie','logistyka','lojalnosc','konspiracja','handel') NOT NULL,
  wartosc TINYINT UNSIGNED NOT NULL CHECK (wartosc BETWEEN 1 AND 10),
  PRIMARY KEY (osoba_id, cecha),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Test daje ocenę przybliżoną (wartość ±2), opisaną słownie. Raz na 30 dni na testującego.
CREATE TABLE IF NOT EXISTS testy_statystyk (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  osoba_id BIGINT UNSIGNED NOT NULL,
  testujacy_id BIGINT UNSIGNED NOT NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_testy_osoba (osoba_id, testujacy_id, czas_utc),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id),
  FOREIGN KEY (testujacy_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS testy_statystyk_wyniki (
  test_id BIGINT UNSIGNED NOT NULL,
  cecha ENUM('pilotaz','dowodzenie','taktyka','zarzadzanie','logistyka','lojalnosc','konspiracja','handel') NOT NULL,
  ocena TINYINT UNSIGNED NOT NULL CHECK (ocena BETWEEN 1 AND 5),
  PRIMARY KEY (test_id, cecha),
  FOREIGN KEY (test_id) REFERENCES testy_statystyk (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS firmy_czlonkowie (
  firma_id BIGINT UNSIGNED NOT NULL,
  postac_id BIGINT UNSIGNED NOT NULL,
  rola ENUM('wlasciciel','menedzer','pracownik') NOT NULL DEFAULT 'pracownik',
  od TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (firma_id, postac_id),
  KEY ix_firmy_czlonkowie_postac (postac_id),
  FOREIGN KEY (firma_id) REFERENCES firmy (podmiot_id),
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migawki finansów firmy: kapitał, gotówka, aktywa, zobowiązania i wartość giełdowa to różne liczby.
CREATE TABLE IF NOT EXISTS firmy_bilans (
  firma_id BIGINT UNSIGNED NOT NULL,
  data DATE NOT NULL,
  kapital BIGINT NOT NULL DEFAULT 0,
  gotowka BIGINT NOT NULL DEFAULT 0,
  nieruchomosci BIGINT NOT NULL DEFAULT 0,
  zobowiazania BIGINT NOT NULL DEFAULT 0,
  wartosc_gieldowa BIGINT NULL,
  PRIMARY KEY (firma_id, data),
  FOREIGN KEY (firma_id) REFERENCES firmy (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Budynek = lokalizacja typu 'budynek'. x_km i y_km w lokalizacji robią z niego miejsce na mapie.
CREATE TABLE IF NOT EXISTS budynki (
  lokalizacja_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  wlasciciel_id BIGINT UNSIGNED NULL,
  powierzchnia_m2 INT UNSIGNED NULL,
  pietra SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  szyby SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  pojemnosc INT UNSIGNED NULL,
  pensja_domyslna BIGINT UNSIGNED NULL,
  kierownik_osoba_id BIGINT UNSIGNED NULL,
  cena_oferty BIGINT UNSIGNED NULL,
  status ENUM('prywatny','na_sprzedaz','zajety') NOT NULL DEFAULT 'prywatny',
  KEY ix_budynki_wlasciciel (wlasciciel_id),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (wlasciciel_id) REFERENCES podmioty (id),
  FOREIGN KEY (kierownik_osoba_id) REFERENCES osoby (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS budynki_hangar (
  lokalizacja_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  jakosc TINYINT UNSIGNED NOT NULL DEFAULT 1 CHECK (jakosc BETWEEN 1 AND 5),
  paliwo_zapas DECIMAL(14,2) NOT NULL DEFAULT 0 CHECK (paliwo_zapas >= 0),
  cena_paliwa DECIMAL(10,2) NOT NULL DEFAULT 0 CHECK (cena_paliwa >= 0),
  FOREIGN KEY (lokalizacja_id) REFERENCES budynki (lokalizacja_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zatrudnienie (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  osoba_id BIGINT UNSIGNED NOT NULL,
  pracodawca_id BIGINT UNSIGNED NOT NULL,
  budynek_id BIGINT UNSIGNED NULL,
  stanowisko VARCHAR(120) NOT NULL DEFAULT '',
  pensja BIGINT UNSIGNED NOT NULL DEFAULT 0,
  od DATE NOT NULL,
  koniec DATE NULL,
  status ENUM('aktywne','zawieszone','zakonczone') NOT NULL DEFAULT 'aktywne',
  KEY ix_zatrudnienie_osoba (osoba_id, status),
  KEY ix_zatrudnienie_pracodawca (pracodawca_id, status),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id),
  FOREIGN KEY (pracodawca_id) REFERENCES podmioty (id),
  FOREIGN KEY (budynek_id) REFERENCES budynki (lokalizacja_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. GOSPODARKA
-- ---------------------------------------------------------------------

-- Saldo może zejść poniżej zera (stałe opłaty, kredyt). Przelewy kontroluje
-- aplikacja względem limit_kredytowy. Spójność salda sprawdza widok v_rozjazd_sald.
CREATE TABLE IF NOT EXISTS rachunki (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  numer CHAR(14) NOT NULL,
  wlasciciel_id BIGINT UNSIGNED NOT NULL,
  czy_glowny TINYINT NULL DEFAULT NULL CHECK (czy_glowny IS NULL OR czy_glowny = 1),
  saldo BIGINT NOT NULL DEFAULT 0,
  limit_kredytowy BIGINT UNSIGNED NOT NULL DEFAULT 0,
  zadluzenie BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('aktywny','zablokowany','zamkniety') NOT NULL DEFAULT 'aktywny',
  utworzono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rachunki_numer (numer),
  UNIQUE KEY uq_rachunki_glowny (wlasciciel_id, czy_glowny),
  FOREIGN KEY (wlasciciel_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nagłówek operacji. Suma kwot jej pozycji musi wynosić 0.
CREATE TABLE IF NOT EXISTS ksiegowania (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  typ ENUM('przelew','wyplata','wplata','dochod_staly','pensja','gielda','handel','oplata','kredyt','korekta_mg','bilans_otwarcia') NOT NULL,
  tytul VARCHAR(255) NOT NULL DEFAULT '',
  autor_id BIGINT UNSIGNED NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  czas_gst BIGINT NULL,
  KEY ix_ksiegowania_czas (czas_utc),
  FOREIGN KEY (autor_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ksiegowania_pozycje (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ksiegowanie_id BIGINT UNSIGNED NOT NULL,
  rachunek_id BIGINT UNSIGNED NOT NULL,
  kwota BIGINT NOT NULL CHECK (kwota <> 0),
  saldo_po BIGINT NOT NULL,
  KEY ix_pozycje_rachunek (rachunek_id, id),
  KEY ix_pozycje_ksiegowanie (ksiegowanie_id),
  FOREIGN KEY (ksiegowanie_id) REFERENCES ksiegowania (id),
  FOREIGN KEY (rachunek_id) REFERENCES rachunki (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS przelewy (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  z_rachunku BIGINT UNSIGNED NOT NULL,
  na_rachunek BIGINT UNSIGNED NOT NULL,
  kwota BIGINT UNSIGNED NOT NULL CHECK (kwota > 0),
  tytul VARCHAR(255) NOT NULL DEFAULT '',
  typ ENUM('zwykly','pensja','gielda','handel','oplata') NOT NULL DEFAULT 'zwykly',
  status ENUM('zaksiegowany','oczekujacy','odrzucony') NOT NULL DEFAULT 'zaksiegowany',
  ksiegowanie_id BIGINT UNSIGNED NULL,
  powiazanie_id BIGINT UNSIGNED NULL,
  data DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_przelewy_ksiegowanie (ksiegowanie_id),
  KEY ix_przelewy_z (z_rachunku, data),
  KEY ix_przelewy_na (na_rachunek, data),
  CHECK (z_rachunku <> na_rachunek),
  FOREIGN KEY (z_rachunku) REFERENCES rachunki (id),
  FOREIGN KEY (na_rachunek) REFERENCES rachunki (id),
  FOREIGN KEY (ksiegowanie_id) REFERENCES ksiegowania (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS odbiorcy_stali (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wlasciciel_id BIGINT UNSIGNED NOT NULL,
  nazwa VARCHAR(120) NOT NULL,
  rachunek_id BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_odbiorcy_nazwa (wlasciciel_id, nazwa),
  FOREIGN KEY (wlasciciel_id) REFERENCES podmioty (id),
  FOREIGN KEY (rachunek_id) REFERENCES rachunki (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kwota ujemna = stała opłata (np. czynsz za hangar). Wypłata „leniwie”, bez crona.
CREATE TABLE IF NOT EXISTS dochody_stale (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rachunek_id BIGINT UNSIGNED NOT NULL,
  kwota BIGINT NOT NULL CHECK (kwota <> 0),
  dzien_miesiaca TINYINT UNSIGNED NOT NULL CHECK (dzien_miesiaca BETWEEN 1 AND 28),
  nadawca VARCHAR(120) NOT NULL DEFAULT '',
  tytul VARCHAR(255) NOT NULL DEFAULT '',
  aktywny TINYINT(1) NOT NULL DEFAULT 1,
  rozliczono_do DATE NULL,
  KEY ix_dochody_rachunek (rachunek_id),
  FOREIGN KEY (rachunek_id) REFERENCES rachunki (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gotówka przy sobie (obok rachunku). Bankomat przenosi środki między portfelem a rachunkiem.
CREATE TABLE IF NOT EXISTS portfele (
  podmiot_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  gotowka BIGINT NOT NULL DEFAULT 0 CHECK (gotowka >= 0),
  FOREIGN KEY (podmiot_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kredyty (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rachunek_id BIGINT UNSIGNED NOT NULL,
  kwota_poczatkowa BIGINT UNSIGNED NOT NULL,
  pozostalo BIGINT UNSIGNED NOT NULL,
  oprocentowanie_proc DECIMAL(6,3) NOT NULL DEFAULT 0,
  status ENUM('aktywny','splacony','zalegly','umorzony') NOT NULL DEFAULT 'aktywny',
  zawarto DATE NOT NULL,
  termin DATE NULL,
  KEY ix_kredyty_rachunek (rachunek_id),
  FOREIGN KEY (rachunek_id) REFERENCES rachunki (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wyplaty (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  zatrudnienie_id BIGINT UNSIGNED NOT NULL,
  ksiegowanie_id BIGINT UNSIGNED NULL,
  okres DATE NOT NULL,
  kwota BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_wyplaty_okres (zatrudnienie_id, okres),
  FOREIGN KEY (zatrudnienie_id) REFERENCES zatrudnienie (id),
  FOREIGN KEY (ksiegowanie_id) REFERENCES ksiegowania (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Katalog towarów ----

CREATE TABLE IF NOT EXISTS towary (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nazwa VARCHAR(160) NOT NULL,
  kategoria ENUM('paliwo','surowce','zywnosc','medycyna','technika','bron','luksus','przyprawy') NOT NULL,
  masa_t_na_jednostke DECIMAL(12,4) NOT NULL DEFAULT 1 CHECK (masa_t_na_jednostke >= 0),
  jednostka VARCHAR(20) NOT NULL DEFAULT 'szt',
  cena_bazowa BIGINT UNSIGNED NOT NULL DEFAULT 0,
  kontrabanda TINYINT(1) NOT NULL DEFAULT 0,
  opis TEXT NULL,
  UNIQUE KEY uq_towary_nazwa (nazwa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mnożnik ceny na planecie dla towaru albo dla całej kategorii (dokładnie jedno z dwóch).
CREATE TABLE IF NOT EXISTS planety_specyfika (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  planeta_id INT UNSIGNED NOT NULL,
  towar_id BIGINT UNSIGNED NULL,
  kategoria ENUM('paliwo','surowce','zywnosc','medycyna','technika','bron','luksus','przyprawy') NULL,
  mnoznik DECIMAL(6,3) NOT NULL CHECK (mnoznik > 0),
  UNIQUE KEY uq_specyfika_towar (planeta_id, towar_id),
  UNIQUE KEY uq_specyfika_kategoria (planeta_id, kategoria),
  CHECK ((towar_id IS NOT NULL) + (kategoria IS NOT NULL) = 1),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id),
  FOREIGN KEY (towar_id) REFERENCES towary (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sklepy_oferty (
  lokalizacja_id BIGINT UNSIGNED NOT NULL,
  towar_id BIGINT UNSIGNED NOT NULL,
  cena BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (lokalizacja_id, towar_id),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (towar_id) REFERENCES towary (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ekwipunek_postaci (
  postac_id BIGINT UNSIGNED NOT NULL,
  towar_id BIGINT UNSIGNED NOT NULL,
  ilosc DECIMAL(16,3) NOT NULL CHECK (ilosc >= 0),
  opis VARCHAR(255) NULL,
  PRIMARY KEY (postac_id, towar_id),
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id),
  FOREIGN KEY (towar_id) REFERENCES towary (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Giełda CDEX ----

CREATE TABLE IF NOT EXISTS gielde (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  planeta_id INT UNSIGNED NOT NULL,
  nazwa VARCHAR(120) NOT NULL,
  aktywna TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_gielde_nazwa (nazwa),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Instrument to towar albo spółka (dokładnie jedno). Status notowania jest tylko tutaj.
CREATE TABLE IF NOT EXISTS instrumenty (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  symbol VARCHAR(16) NOT NULL,
  nazwa VARCHAR(160) NOT NULL,
  rodzaj ENUM('towar','spolka') NOT NULL,
  gielda_id BIGINT UNSIGNED NOT NULL,
  towar_id BIGINT UNSIGNED NULL,
  firma_id BIGINT UNSIGNED NULL,
  cena_bazowa BIGINT UNSIGNED NOT NULL,
  zmiennosc DECIMAL(6,3) NOT NULL DEFAULT 1,
  notowany TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_instrumenty_symbol (symbol),
  UNIQUE KEY uq_instrumenty_towar (towar_id),
  UNIQUE KEY uq_instrumenty_firma (firma_id),
  CHECK ((towar_id IS NOT NULL) + (firma_id IS NOT NULL) = 1),
  CHECK ((rodzaj = 'towar' AND towar_id IS NOT NULL) OR (rodzaj = 'spolka' AND firma_id IS NOT NULL)),
  FOREIGN KEY (gielda_id) REFERENCES gielde (id),
  FOREIGN KEY (towar_id) REFERENCES towary (id),
  FOREIGN KEY (firma_id) REFERENCES firmy (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kurs zamknięcia każdego dnia (zastępuje gielda_notowania). Skrypt kursów dopisuje brakujące dni.
CREATE TABLE IF NOT EXISTS kursy_dzienne (
  instrument_id BIGINT UNSIGNED NOT NULL,
  data DATE NOT NULL,
  kurs BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (instrument_id, data),
  FOREIGN KEY (instrument_id) REFERENCES instrumenty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portfele_gieldowe (
  podmiot_id BIGINT UNSIGNED NOT NULL,
  instrument_id BIGINT UNSIGNED NOT NULL,
  ilosc BIGINT UNSIGNED NOT NULL DEFAULT 0,
  koszt_laczny BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (podmiot_id, instrument_id),
  FOREIGN KEY (podmiot_id) REFERENCES podmioty (id),
  FOREIGN KEY (instrument_id) REFERENCES instrumenty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transakcje_gieldowe (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  podmiot_id BIGINT UNSIGNED NOT NULL,
  instrument_id BIGINT UNSIGNED NOT NULL,
  strona ENUM('kupno','sprzedaz') NOT NULL,
  ilosc BIGINT UNSIGNED NOT NULL CHECK (ilosc > 0),
  kurs BIGINT UNSIGNED NOT NULL,
  prowizja BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ksiegowanie_id BIGINT UNSIGNED NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  czas_gst BIGINT NULL,
  KEY ix_transakcje_podmiot (podmiot_id, czas_utc),
  KEY ix_transakcje_instrument (instrument_id, czas_utc),
  FOREIGN KEY (podmiot_id) REFERENCES podmioty (id),
  FOREIGN KEY (instrument_id) REFERENCES instrumenty (id),
  FOREIGN KEY (ksiegowanie_id) REFERENCES ksiegowania (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. FLOTA I WYPOSAŻENIE
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS typy_kadlubow (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nazwa VARCHAR(160) NOT NULL,
  kategoria ENUM('mysliwiec','kurier','frachtowiec','transportowiec','korweta','fregata','niszczyciel','krazownik','stacja','inne') NOT NULL DEFAULT 'inne',
  klasa VARCHAR(80) NULL,
  producent VARCHAR(120) NULL,
  okres_produkcji VARCHAR(80) NULL,
  dlugosc_m DECIMAL(10,2) NULL,
  ladownosc_t DECIMAL(14,2) NOT NULL DEFAULT 0,
  paliwo_maks DECIMAL(14,2) NOT NULL DEFAULT 0,
  zaloga_min INT UNSIGNED NOT NULL DEFAULT 1,
  zaloga_max INT UNSIGNED NOT NULL DEFAULT 1,
  opis TEXT NULL,
  UNIQUE KEY uq_kadluby_nazwa (nazwa),
  CHECK (zaloga_max >= zaloga_min)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kadłub określa miejsca montażowe, ale nie narzuca producenta ani pochodzenia komponentu.
CREATE TABLE IF NOT EXISTS typy_kadlubow_sloty (
  kadlub_id BIGINT UNSIGNED NOT NULL,
  kategoria VARCHAR(16) NOT NULL CHECK (kategoria IN ('hipernapad','turbolaser','oslona','reaktor','kwatery','hangar')),
  liczba SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  rozmiar_max TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (kadlub_id, kategoria),
  FOREIGN KEY (kadlub_id) REFERENCES typy_kadlubow (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jednostki_floty (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nazwa VARCHAR(160) NOT NULL,
  kod_transpondera VARCHAR(40) NOT NULL,
  kadlub_id BIGINT UNSIGNED NOT NULL,
  wlasciciel_id BIGINT UNSIGNED NOT NULL,
  frakcja_id BIGINT UNSIGNED NULL,
  status ENUM('sprawny','uszkodzony','w_naprawie','na_misji','przejety','zniszczony') NOT NULL DEFAULT 'sprawny',
  stan_ruchu ENUM('postoj','powierzchnia','start','orbita','nadprzestrzen','ladowanie') NOT NULL DEFAULT 'postoj',
  lokalizacja_id BIGINT UNSIGNED NULL,
  uklad_id INT UNSIGNED NULL,
  pozycja_x DECIMAL(16,4) NULL,
  pozycja_y DECIMAL(16,4) NULL,
  pozycja_z DECIMAL(16,4) NULL,
  paliwo_aktualne DECIMAL(14,2) NOT NULL DEFAULT 0 CHECK (paliwo_aktualne >= 0),
  paliwo_maksymalne DECIMAL(14,2) NOT NULL DEFAULT 0,
  ladownosc_t DECIMAL(14,2) NOT NULL DEFAULT 0,
  stan_techniczny TINYINT UNSIGNED NOT NULL DEFAULT 100 CHECK (stan_techniczny <= 100),
  wspolczynnik_ukrycia DECIMAL(5,2) NOT NULL DEFAULT 0 CHECK (wspolczynnik_ukrycia >= 0),
  kapitan_osoba_id BIGINT UNSIGNED NULL,
  opis TEXT NULL,
  UNIQUE KEY uq_jednostki_transponder (kod_transpondera),
  KEY ix_jednostki_wlasciciel (wlasciciel_id),
  KEY ix_jednostki_lokalizacja (lokalizacja_id),
  KEY ix_jednostki_uklad (uklad_id),
  CHECK (paliwo_aktualne <= paliwo_maksymalne),
  FOREIGN KEY (kadlub_id) REFERENCES typy_kadlubow (id),
  FOREIGN KEY (wlasciciel_id) REFERENCES podmioty (id),
  FOREIGN KEY (frakcja_id) REFERENCES frakcje (podmiot_id),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (uklad_id) REFERENCES uklady_planetarne (id),
  FOREIGN KEY (kapitan_osoba_id) REFERENCES osoby (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wspólne cechy modelu komponentu + osobne tabele parametrów dla każdej kategorii.
CREATE TABLE IF NOT EXISTS komponenty_modele (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kategoria VARCHAR(16) NOT NULL CHECK (kategoria IN ('hipernapad','turbolaser','oslona','reaktor','kwatery','hangar')),
  nazwa VARCHAR(160) NOT NULL,
  producent VARCHAR(120) NULL,
  pochodzenie VARCHAR(120) NULL,
  okres_produkcji VARCHAR(80) NULL,
  rozmiar TINYINT UNSIGNED NOT NULL DEFAULT 1,
  masa_t DECIMAL(12,2) NOT NULL DEFAULT 0,
  pobor_mocy DECIMAL(12,2) NOT NULL DEFAULT 0,
  cena BIGINT UNSIGNED NOT NULL DEFAULT 0,
  dostepnosc ENUM('powszechny','ograniczony','rzadki','unikat') NOT NULL DEFAULT 'powszechny',
  wymagania_montazowe VARCHAR(255) NULL,
  opis TEXT NULL,
  UNIQUE KEY uq_modele_id_kategoria (id, kategoria),
  UNIQUE KEY uq_modele_nazwa (kategoria, nazwa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS typy_hipernapedow (
  model_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  kategoria VARCHAR(16) NOT NULL DEFAULT 'hipernapad' CHECK (kategoria = 'hipernapad'),
  klasa DECIMAL(5,2) NOT NULL,
  mnoznik_predkosci DECIMAL(8,3) NOT NULL CHECK (mnoznik_predkosci > 0),
  mnoznik_zuzycia_paliwa DECIMAL(8,3) NOT NULL DEFAULT 1 CHECK (mnoznik_zuzycia_paliwa > 0),
  zuzycie_paliwa_na_parsek DECIMAL(10,4) NOT NULL DEFAULT 0,
  zasieg_max_pc DECIMAL(10,2) NOT NULL,
  czas_przygotowania_s INT UNSIGNED NOT NULL DEFAULT 0,
  czas_regeneracji_s INT UNSIGNED NOT NULL DEFAULT 0,
  wydajnosc DECIMAL(6,3) NOT NULL DEFAULT 1,
  nagrzanie_na_skok DECIMAL(6,2) NOT NULL DEFAULT 0,
  chlodzenie_na_h DECIMAL(6,2) NOT NULL DEFAULT 0,
  limit_nagrzania DECIMAL(6,2) NOT NULL DEFAULT 100,
  FOREIGN KEY (model_id, kategoria) REFERENCES komponenty_modele (id, kategoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS typy_turbolaserow (
  model_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  kategoria VARCHAR(16) NOT NULL DEFAULT 'turbolaser' CHECK (kategoria = 'turbolaser'),
  moc DECIMAL(12,2) NOT NULL,
  szybkostrzelnosc DECIMAL(8,3) NOT NULL,
  zasieg_km DECIMAL(12,2) NOT NULL,
  celnosc DECIMAL(5,2) NOT NULL DEFAULT 1,
  zuzycie_energii DECIMAL(12,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (model_id, kategoria) REFERENCES komponenty_modele (id, kategoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS typy_oslon (
  model_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  kategoria VARCHAR(16) NOT NULL DEFAULT 'oslona' CHECK (kategoria = 'oslona'),
  wytrzymalosc DECIMAL(14,2) NOT NULL,
  regeneracja_na_s DECIMAL(10,3) NOT NULL DEFAULT 0,
  odpornosc_energetyczna DECIMAL(5,2) NOT NULL DEFAULT 1,
  odpornosc_kinetyczna DECIMAL(5,2) NOT NULL DEFAULT 1,
  zuzycie_energii DECIMAL(12,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (model_id, kategoria) REFERENCES komponenty_modele (id, kategoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS typy_reaktorow (
  model_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  kategoria VARCHAR(16) NOT NULL DEFAULT 'reaktor' CHECK (kategoria = 'reaktor'),
  moc_wyjsciowa DECIMAL(14,2) NOT NULL,
  wydajnosc DECIMAL(6,3) NOT NULL DEFAULT 1,
  zuzycie_paliwa_na_h DECIMAL(10,4) NOT NULL DEFAULT 0,
  FOREIGN KEY (model_id, kategoria) REFERENCES komponenty_modele (id, kategoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS typy_kwater_zalogi (
  model_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  kategoria VARCHAR(16) NOT NULL DEFAULT 'kwatery' CHECK (kategoria = 'kwatery'),
  pojemnosc INT UNSIGNED NOT NULL,
  standard TINYINT UNSIGNED NOT NULL DEFAULT 1 CHECK (standard BETWEEN 1 AND 5),
  zuzycie_zaopatrzenia_na_dzien DECIMAL(10,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (model_id, kategoria) REFERENCES komponenty_modele (id, kategoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS typy_hangarow (
  model_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  kategoria VARCHAR(16) NOT NULL DEFAULT 'hangar' CHECK (kategoria = 'hangar'),
  pojemnosc_jednostek INT UNSIGNED NOT NULL,
  rozmiar_max_jednostki TINYINT UNSIGNED NOT NULL DEFAULT 1,
  infrastruktura VARCHAR(255) NULL,
  FOREIGN KEY (model_id, kategoria) REFERENCES komponenty_modele (id, kategoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Konkretna sztuka komponentu (własny stan i historia). Można ją demontować, sprzedawać, przenosić.
CREATE TABLE IF NOT EXISTS komponenty_egzemplarze (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  model_id BIGINT UNSIGNED NOT NULL,
  kategoria VARCHAR(16) NOT NULL,
  numer_seryjny VARCHAR(60) NULL,
  wlasciciel_id BIGINT UNSIGNED NOT NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  status ENUM('sprawny','uszkodzony','w_naprawie','zdemontowany','zniszczony') NOT NULL DEFAULT 'sprawny',
  stan_techniczny TINYINT UNSIGNED NOT NULL DEFAULT 100 CHECK (stan_techniczny <= 100),
  modyfikacje VARCHAR(500) NULL,
  utworzono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_egzemplarze_seria (numer_seryjny),
  UNIQUE KEY uq_egzemplarze_id_kategoria (id, kategoria),
  KEY ix_egzemplarze_wlasciciel (wlasciciel_id),
  FOREIGN KEY (model_id, kategoria) REFERENCES komponenty_modele (id, kategoria),
  FOREIGN KEY (wlasciciel_id) REFERENCES podmioty (id),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Montaż. aktywny = 1 dla bieżącego, NULL dla historycznego; UNIQUE pilnuje jednego aktywnego montażu.
CREATE TABLE IF NOT EXISTS komponenty_montaze (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  egzemplarz_id BIGINT UNSIGNED NOT NULL,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  slot VARCHAR(40) NOT NULL DEFAULT '',
  od DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  koniec DATETIME NULL,
  aktywny TINYINT NULL DEFAULT 1 CHECK (aktywny IS NULL OR aktywny = 1),
  UNIQUE KEY uq_montaze_aktywny (egzemplarz_id, aktywny),
  KEY ix_montaze_jednostka (jednostka_id, aktywny),
  FOREIGN KEY (egzemplarz_id) REFERENCES komponenty_egzemplarze (id),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Obciążenie napędu: nagrzewanie po serii skoków wymusza odpoczynek.
CREATE TABLE IF NOT EXISTS stan_hipernapedow (
  egzemplarz_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  kategoria VARCHAR(16) NOT NULL DEFAULT 'hipernapad' CHECK (kategoria = 'hipernapad'),
  nagrzanie DECIMAL(6,2) NOT NULL DEFAULT 0,
  skoki_w_serii SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ostatni_skok_utc DATETIME NULL,
  gotowy_od_utc DATETIME NULL,
  FOREIGN KEY (egzemplarz_id, kategoria) REFERENCES komponenty_egzemplarze (id, kategoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pojazdy (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nazwa VARCHAR(120) NOT NULL,
  typ VARCHAR(40) NOT NULL DEFAULT 'spider',
  predkosc_kmh DECIMAL(8,2) NOT NULL DEFAULT 0,
  wlasciciel_id BIGINT UNSIGNED NOT NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  kierowca_osoba_id BIGINT UNSIGNED NULL,
  status ENUM('sprawny','uszkodzony','w_naprawie','zniszczony') NOT NULL DEFAULT 'sprawny',
  UNIQUE KEY uq_pojazdy_nazwa (wlasciciel_id, nazwa),
  FOREIGN KEY (wlasciciel_id) REFERENCES podmioty (id),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (kierowca_osoba_id) REFERENCES osoby (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS przydzialy_zalogi (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  osoba_id BIGINT UNSIGNED NOT NULL,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  funkcja ENUM('kapitan','pilot','nawigator','inzynier','strzelec','lacznosc','pasazer','inna') NOT NULL DEFAULT 'inna',
  od DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  koniec DATETIME NULL,
  KEY ix_przydzialy_jednostka (jednostka_id, koniec),
  KEY ix_przydzialy_osoba (osoba_id, koniec),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Magazyny ----

-- Magazyn stoi w lokalizacji (budynek, hangar) albo jest ładownią statku.
CREATE TABLE IF NOT EXISTS magazyny (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wlasciciel_id BIGINT UNSIGNED NOT NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  jednostka_id BIGINT UNSIGNED NULL,
  nazwa VARCHAR(160) NOT NULL DEFAULT '',
  pojemnosc_t DECIMAL(14,2) NULL,
  UNIQUE KEY uq_magazyny_jednostka (jednostka_id),
  KEY ix_magazyny_lokalizacja (lokalizacja_id),
  KEY ix_magazyny_wlasciciel (wlasciciel_id),
  CHECK ((lokalizacja_id IS NOT NULL) + (jednostka_id IS NOT NULL) = 1),
  FOREIGN KEY (wlasciciel_id) REFERENCES podmioty (id),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Partia towaru. Właściciel ładunku może być inny niż właściciel magazynu lub statku.
CREATE TABLE IF NOT EXISTS magazyn_partie (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  magazyn_id BIGINT UNSIGNED NOT NULL,
  towar_id BIGINT UNSIGNED NOT NULL,
  ilosc DECIMAL(16,3) NOT NULL CHECK (ilosc >= 0),
  wlasciciel_ladunku_id BIGINT UNSIGNED NOT NULL,
  miejsce VARCHAR(60) NULL,
  czy_ukryte TINYINT(1) NOT NULL DEFAULT 0,
  poziom_ukrycia TINYINT UNSIGNED NOT NULL DEFAULT 0 CHECK (poziom_ukrycia <= 10),
  deklarowane_w_manifescie TINYINT(1) NOT NULL DEFAULT 1,
  data_zaladunku DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_partie_magazyn (magazyn_id, towar_id),
  KEY ix_partie_wlasciciel (wlasciciel_ladunku_id),
  FOREIGN KEY (magazyn_id) REFERENCES magazyny (id),
  FOREIGN KEY (towar_id) REFERENCES towary (id),
  FOREIGN KEY (wlasciciel_ladunku_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Plany lotów handlowych (transport, automaty) ----

CREATE TABLE IF NOT EXISTS plany_lotu (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  firma_id BIGINT UNSIGNED NOT NULL,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  status ENUM('szkic','zlozony','przyjety','w_trasie','zakonczony','odrzucony','wstrzymany') NOT NULL DEFAULT 'szkic',
  automat TINYINT(1) NOT NULL DEFAULT 0,
  co_n_dni SMALLINT UNSIGNED NULL,
  uwaga_mg TEXT NULL,
  zlozono DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  zamknieto DATETIME NULL,
  KEY ix_plany_firma (firma_id, status),
  KEY ix_plany_jednostka (jednostka_id),
  CHECK (automat = 0 OR co_n_dni IS NOT NULL),
  FOREIGN KEY (firma_id) REFERENCES firmy (podmiot_id),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plany_lotu_przystanki (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  nr SMALLINT UNSIGNED NOT NULL,
  planeta_id INT UNSIGNED NOT NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_przystanki_nr (plan_id, nr),
  FOREIGN KEY (plan_id) REFERENCES plany_lotu (id),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plany_lotu_operacje (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  przystanek_id BIGINT UNSIGNED NOT NULL,
  typ ENUM('zaladuj','rozladuj','kup','sprzedaj') NOT NULL,
  towar_id BIGINT UNSIGNED NOT NULL,
  ilosc DECIMAL(16,3) NOT NULL CHECK (ilosc > 0),
  FOREIGN KEY (przystanek_id) REFERENCES plany_lotu_przystanki (id),
  FOREIGN KEY (towar_id) REFERENCES towary (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS obiegi_automatow (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  data DATE NOT NULL,
  wynik ENUM('ok','wstrzymany') NOT NULL,
  przychod BIGINT NOT NULL DEFAULT 0,
  koszt BIGINT NOT NULL DEFAULT 0,
  ksiegowanie_id BIGINT UNSIGNED NULL,
  powod VARCHAR(255) NULL,
  UNIQUE KEY uq_obiegi_dzien (plan_id, data),
  FOREIGN KEY (plan_id) REFERENCES plany_lotu (id),
  FOREIGN KEY (ksiegowanie_id) REFERENCES ksiegowania (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Agencja: misje i teczki ----

CREATE TABLE IF NOT EXISTS misje (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  firma_id BIGINT UNSIGNED NOT NULL,
  kryptonim VARCHAR(80) NOT NULL,
  termin DATETIME NULL,
  rejon VARCHAR(160) NULL,
  cel TEXT NULL,
  status ENUM('zlozona','zatwierdzona','w_toku','rozliczona','odrzucona') NOT NULL DEFAULT 'zlozona',
  warunki_oficjalne TEXT NULL,
  uwagi_poufne TEXT NULL,
  raport TEXT NULL,
  data_sesji DATE NULL,
  KEY ix_misje_firma (firma_id, status),
  FOREIGN KEY (firma_id) REFERENCES firmy (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS misje_jednostki (
  misja_id BIGINT UNSIGNED NOT NULL,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  wynik ENUM('sprawny','uszkodzony','zniszczony','przejety') NULL,
  koncowa_lokalizacja_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (misja_id, jednostka_id),
  FOREIGN KEY (misja_id) REFERENCES misje (id),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (koncowa_lokalizacja_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS misje_osoby (
  misja_id BIGINT UNSIGNED NOT NULL,
  osoba_id BIGINT UNSIGNED NOT NULL,
  wynik ENUM('cala','ranna','polegla','spalona','zatrzymana') NULL,
  PRIMARY KEY (misja_id, osoba_id),
  FOREIGN KEY (misja_id) REFERENCES misje (id),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS misje_zmiany_magazynow (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  misja_id BIGINT UNSIGNED NOT NULL,
  magazyn_id BIGINT UNSIGNED NOT NULL,
  towar_id BIGINT UNSIGNED NOT NULL,
  zmiana DECIMAL(16,3) NOT NULL CHECK (zmiana <> 0),
  FOREIGN KEY (misja_id) REFERENCES misje (id),
  FOREIGN KEY (magazyn_id) REFERENCES magazyny (id),
  FOREIGN KEY (towar_id) REFERENCES towary (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teczki (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  agencja_id BIGINT UNSIGNED NOT NULL,
  nazwa VARCHAR(160) NOT NULL,
  cel_osoba_id BIGINT UNSIGNED NULL,
  cel_jednostka_id BIGINT UNSIGNED NULL,
  cel_lokalizacja_id BIGINT UNSIGNED NULL,
  status ENUM('otwarta','zamknieta','utajniona') NOT NULL DEFAULT 'otwarta',
  utworzono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_teczki_agencja (agencja_id),
  CHECK ((cel_osoba_id IS NOT NULL) + (cel_jednostka_id IS NOT NULL) + (cel_lokalizacja_id IS NOT NULL) = 1),
  FOREIGN KEY (agencja_id) REFERENCES firmy (podmiot_id),
  FOREIGN KEY (cel_osoba_id) REFERENCES osoby (podmiot_id),
  FOREIGN KEY (cel_jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (cel_lokalizacja_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teczki_meldunki (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  teczka_id BIGINT UNSIGNED NOT NULL,
  autor_id BIGINT UNSIGNED NULL,
  tresc TEXT NOT NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_meldunki_teczka (teczka_id, czas_utc),
  FOREIGN KEY (teczka_id) REFERENCES teczki (id),
  FOREIGN KEY (autor_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. ASTRONAWIGACJA I BLOKADY
-- ---------------------------------------------------------------------

-- Komputer nawigacyjny statku i jego pamięć: statek skacze tylko tam, gdzie ma dane.
CREATE TABLE IF NOT EXISTS komputery_nawigacyjne (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  system_id INT UNSIGNED NOT NULL,
  model VARCHAR(120) NULL,
  dokladnosc DECIMAL(5,2) NOT NULL DEFAULT 1,
  pamiec_planet SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_komputery_jednostka (jednostka_id),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (system_id) REFERENCES systemy_nawigacyjne (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS komputery_dane_nawigacyjne (
  komputer_id BIGINT UNSIGNED NOT NULL,
  planeta_id INT UNSIGNED NOT NULL,
  zrodlo ENUM('fabryka','zakup','przekaz','synchronizacja','wywiad','mg') NOT NULL DEFAULT 'fabryka',
  wersja_gst BIGINT NULL,
  wazne_do_gst BIGINT NULL,
  zaladowano DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (komputer_id, planeta_id),
  FOREIGN KEY (komputer_id) REFERENCES komputery_nawigacyjne (id),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trasy_skokow (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  status ENUM('plan','aktywna','przerwana','zakonczona','anulowana') NOT NULL DEFAULT 'plan',
  start_lokalizacja_id BIGINT UNSIGNED NULL,
  utworzono DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  czas_gst_utworzenia BIGINT NULL,
  KEY ix_trasy_jednostka (jednostka_id, status),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (start_lokalizacja_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Krok trasy: planeta, lokalizacja albo punkt w przestrzeni (punkt pośredni omijający gwiazdę).
CREATE TABLE IF NOT EXISTS trasy_skokow_kroki (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  trasa_id BIGINT UNSIGNED NOT NULL,
  nr SMALLINT UNSIGNED NOT NULL,
  cel_planeta_id INT UNSIGNED NULL,
  cel_lokalizacja_id BIGINT UNSIGNED NULL,
  cel_x DECIMAL(16,4) NULL,
  cel_y DECIMAL(16,4) NULL,
  cel_z DECIMAL(16,4) NULL,
  status ENUM('plan','wykonany','pominiety','nieudany') NOT NULL DEFAULT 'plan',
  czy_posredni TINYINT(1) NOT NULL DEFAULT 0,
  wymuszony_kolinearnoscia TINYINT(1) NOT NULL DEFAULT 0,
  omija_uklad_id INT UNSIGNED NULL,
  dystans_pc DECIMAL(14,4) NULL,
  zuzycie_paliwa DECIMAL(14,2) NULL,
  planowany_start_gst BIGINT NULL,
  szac_przybycie_gst BIGINT NULL,
  UNIQUE KEY uq_kroki_nr (trasa_id, nr),
  CHECK (cel_planeta_id IS NOT NULL OR cel_lokalizacja_id IS NOT NULL OR cel_x IS NOT NULL),
  FOREIGN KEY (trasa_id) REFERENCES trasy_skokow (id),
  FOREIGN KEY (cel_planeta_id) REFERENCES mapa_planety (id),
  FOREIGN KEY (cel_lokalizacja_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (omija_uklad_id) REFERENCES uklady_planetarne (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS historia_skokow (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  krok_id BIGINT UNSIGNED NULL,
  naped_egzemplarz_id BIGINT UNSIGNED NULL,
  start_x DECIMAL(16,4) NOT NULL, start_y DECIMAL(16,4) NOT NULL, start_z DECIMAL(16,4) NOT NULL DEFAULT 0,
  koniec_x DECIMAL(16,4) NULL, koniec_y DECIMAL(16,4) NULL, koniec_z DECIMAL(16,4) NULL,
  start_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  koniec_utc DATETIME NULL,
  start_gst BIGINT NULL,
  koniec_gst BIGINT NULL,
  paliwo_zuzyte DECIMAL(14,2) NULL,
  wynik ENUM('w_toku','ukonczony','przerwany','przechwycony','awaria') NOT NULL DEFAULT 'w_toku',
  zakonczyl_mg TINYINT(1) NOT NULL DEFAULT 0,
  KEY ix_skoki_jednostka (jednostka_id, start_utc),
  KEY ix_skoki_wynik (wynik),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (krok_id) REFERENCES trasy_skokow_kroki (id),
  FOREIGN KEY (naped_egzemplarz_id) REFERENCES komponenty_egzemplarze (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zakaz wlotu dla frakcji do układu lub na planetę (np. blokada Zephyrii).
CREATE TABLE IF NOT EXISTS zakazy_skokow (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  frakcja_id BIGINT UNSIGNED NOT NULL,
  uklad_id INT UNSIGNED NULL,
  planeta_id INT UNSIGNED NULL,
  od DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  koniec DATETIME NULL,
  aktywny TINYINT(1) NOT NULL DEFAULT 1,
  powod VARCHAR(255) NULL,
  CHECK ((uklad_id IS NOT NULL) + (planeta_id IS NOT NULL) = 1),
  FOREIGN KEY (frakcja_id) REFERENCES frakcje (podmiot_id),
  FOREIGN KEY (uklad_id) REFERENCES uklady_planetarne (id),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS strefy_niebezpieczne (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uklad_id INT UNSIGNED NOT NULL,
  nazwa VARCHAR(160) NOT NULL,
  rodzaj ENUM('gwiazda','anomalia','pole_minowe','promieniowanie','inne') NOT NULL,
  srodek_x DECIMAL(16,4) NULL, srodek_y DECIMAL(16,4) NULL, srodek_z DECIMAL(16,4) NULL,
  promien DECIMAL(14,4) NOT NULL CHECK (promien > 0),
  FOREIGN KEY (uklad_id) REFERENCES uklady_planetarne (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS systemy_okretu (
  jednostka_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  tryb_czujnikow ENUM('wylaczone','pasywny','dalekiego_zasiegu','pelna_moc') NOT NULL DEFAULT 'pasywny',
  zasieg_czujnikow_pc DECIMAL(10,2) NOT NULL DEFAULT 0,
  energia_aktualna DECIMAL(14,2) NOT NULL DEFAULT 0,
  energia_maks DECIMAL(14,2) NOT NULL DEFAULT 0,
  gotowosc_napedu ENUM('wylaczony','nagrzewanie','gotowy','przeciazony') NOT NULL DEFAULT 'gotowy',
  alarm ENUM('brak','gotowosc','bojowy') NOT NULL DEFAULT 'brak',
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gotowosc_zalogi (
  jednostka_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  obciazenie_proc TINYINT UNSIGNED NOT NULL DEFAULT 0 CHECK (obciazenie_proc <= 100),
  ostatni_odpoczynek_gst BIGINT NULL,
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS zuzycie_zasobow (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  energia DECIMAL(14,2) NOT NULL DEFAULT 0,
  paliwo DECIMAL(14,2) NOT NULL DEFAULT 0,
  godziny_zalogi DECIMAL(10,2) NOT NULL DEFAULT 0,
  powod VARCHAR(80) NULL,
  KEY ix_zuzycie_jednostka (jednostka_id, czas_utc),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wykrycie skoku w nadprzestrzeni: kierunek, szacowane wyjście i margines błędu.
CREATE TABLE IF NOT EXISTS wykrycia_nadprzestrzenne (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wykrywajaca_jednostka_id BIGINT UNSIGNED NOT NULL,
  skok_id BIGINT UNSIGNED NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  czas_gst BIGINT NULL,
  kierunek_x DECIMAL(10,6) NULL, kierunek_y DECIMAL(10,6) NULL, kierunek_z DECIMAL(10,6) NULL,
  odleglosc_pc DECIMAL(14,4) NULL,
  sila_sygnalu DECIMAL(6,2) NOT NULL DEFAULT 0,
  wiarygodnosc TINYINT UNSIGNED NOT NULL DEFAULT 5 CHECK (wiarygodnosc BETWEEN 1 AND 10),
  szac_wyjscie_x DECIMAL(16,4) NULL, szac_wyjscie_y DECIMAL(16,4) NULL, szac_wyjscie_z DECIMAL(16,4) NULL,
  margines_bledu_pc DECIMAL(12,4) NULL,
  status ENUM('nowe','analiza','przechwycone','falszywe','zignorowane','utracone') NOT NULL DEFAULT 'nowe',
  KEY ix_wykrycia_jednostka (wykrywajaca_jednostka_id, status),
  FOREIGN KEY (wykrywajaca_jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (skok_id) REFERENCES historia_skokow (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operacje_przechwycenia (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wykrycie_id BIGINT UNSIGNED NOT NULL,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  status ENUM('przygotowanie','mikroskok','oczekiwanie','identyfikacja','zakonczona','przerwana') NOT NULL DEFAULT 'przygotowanie',
  pozycja_x DECIMAL(16,4) NULL, pozycja_y DECIMAL(16,4) NULL, pozycja_z DECIMAL(16,4) NULL,
  paliwo_zuzyte DECIMAL(14,2) NULL,
  decyzja ENUM('zatrzymanie','kontrola','eskorta','ogien','przepuszczenie') NULL,
  start_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  koniec_utc DATETIME NULL,
  FOREIGN KEY (wykrycie_id) REFERENCES wykrycia_nadprzestrzenne (id),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Łączność z kontrolą lotów ----

CREATE TABLE IF NOT EXISTS lacznosc_watki (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  planeta_id INT UNSIGNED NOT NULL,
  status ENUM('otwarty','odpowiedz','zamkniety') NOT NULL DEFAULT 'otwarty',
  ladowisko_id BIGINT UNSIGNED NULL,
  utworzono DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_lacznosc_planeta (planeta_id, status),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id),
  FOREIGN KEY (ladowisko_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lacznosc_wiadomosci (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  watek_id BIGINT UNSIGNED NOT NULL,
  nadawca ENUM('statek','kontrola','mg','npc') NOT NULL,
  osoba_id BIGINT UNSIGNED NULL,
  tresc TEXT NOT NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_lacznosc_watek (watek_id, czas_utc),
  FOREIGN KEY (watek_id) REFERENCES lacznosc_watki (id),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. INFORMACJA I WYWIAD
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS dokumenty (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sygnatura VARCHAR(60) NOT NULL,
  tytul VARCHAR(255) NOT NULL,
  tresc MEDIUMTEXT NOT NULL,
  poziom TINYINT UNSIGNED NOT NULL DEFAULT 1 CHECK (poziom BETWEEN 1 AND 9),
  frakcja_id BIGINT UNSIGNED NULL,
  utworzono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dokumenty_sygnatura (sygnatura),
  FOREIGN KEY (frakcja_id) REFERENCES frakcje (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dziennik zdarzeń świata. Jedyne miejsce z JSON-em: dane zależą od typu zdarzenia.
CREATE TABLE IF NOT EXISTS dziennik_zdarzen (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  typ VARCHAR(60) NOT NULL,
  podmiot_id BIGINT UNSIGNED NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  obiekt_typ VARCHAR(40) NULL,
  obiekt_id BIGINT UNSIGNED NULL,
  widocznosc ENUM('publiczne','prywatne','tajne') NOT NULL DEFAULT 'prywatne',
  dane JSON NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  czas_gst BIGINT NULL,
  KEY ix_dziennik_typ (typ, czas_utc),
  KEY ix_dziennik_podmiot (podmiot_id, czas_utc),
  KEY ix_dziennik_obiekt (obiekt_typ, obiekt_id),
  FOREIGN KEY (podmiot_id) REFERENCES podmioty (id),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS raporty_wywiadowcze (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cel_osoba_id BIGINT UNSIGNED NULL,
  cel_jednostka_id BIGINT UNSIGNED NULL,
  cel_lokalizacja_id BIGINT UNSIGNED NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  tresc TEXT NOT NULL,
  wiarygodnosc TINYINT UNSIGNED NOT NULL DEFAULT 5 CHECK (wiarygodnosc BETWEEN 1 AND 10),
  wazny_do_gst BIGINT NULL,
  zrodlo_id BIGINT UNSIGNED NULL,
  wersja INT UNSIGNED NOT NULL DEFAULT 1,
  utworzono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK ((cel_osoba_id IS NOT NULL) + (cel_jednostka_id IS NOT NULL) + (cel_lokalizacja_id IS NOT NULL) = 1),
  FOREIGN KEY (cel_osoba_id) REFERENCES osoby (podmiot_id),
  FOREIGN KEY (cel_jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (cel_lokalizacja_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (zrodlo_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS raporty_wiarygodnosc_historia (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  raport_id BIGINT UNSIGNED NOT NULL,
  wiarygodnosc TINYINT UNSIGNED NOT NULL CHECK (wiarygodnosc BETWEEN 1 AND 10),
  powod VARCHAR(255) NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (raport_id) REFERENCES raporty_wywiadowcze (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pakiety_wiedzy (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kod VARCHAR(60) NOT NULL,
  nazwa VARCHAR(200) NOT NULL,
  kategoria VARCHAR(40) NOT NULL DEFAULT 'inne',
  poziom_utajnienia TINYINT UNSIGNED NOT NULL DEFAULT 1 CHECK (poziom_utajnienia BETWEEN 1 AND 9),
  zrodlo_id BIGINT UNSIGNED NULL,
  cena_orientacyjna BIGINT UNSIGNED NULL,
  rzadkosc ENUM('powszechny','ograniczony','rzadki','unikat') NOT NULL DEFAULT 'powszechny',
  wersja INT UNSIGNED NOT NULL DEFAULT 1,
  poprzednia_wersja_id BIGINT UNSIGNED NULL,
  utworzono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pakiety_kod (kod),
  FOREIGN KEY (zrodlo_id) REFERENCES podmioty (id),
  FOREIGN KEY (poprzednia_wersja_id) REFERENCES pakiety_wiedzy (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pozycja pakietu wskazuje dokładnie jeden obiekt.
CREATE TABLE IF NOT EXISTS pakiety_zawartosc (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pakiet_id BIGINT UNSIGNED NOT NULL,
  planeta_id INT UNSIGNED NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  jednostka_id BIGINT UNSIGNED NULL,
  osoba_id BIGINT UNSIGNED NULL,
  raport_id BIGINT UNSIGNED NULL,
  dokument_id BIGINT UNSIGNED NULL,
  teczka_id BIGINT UNSIGNED NULL,
  poziom_dostepu TINYINT UNSIGNED NOT NULL DEFAULT 1,
  KEY ix_zawartosc_pakiet (pakiet_id),
  CHECK ((planeta_id IS NOT NULL) + (lokalizacja_id IS NOT NULL) + (jednostka_id IS NOT NULL)
       + (osoba_id IS NOT NULL) + (raport_id IS NOT NULL) + (dokument_id IS NOT NULL)
       + (teczka_id IS NOT NULL) = 1),
  FOREIGN KEY (pakiet_id) REFERENCES pakiety_wiedzy (id),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id),
  FOREIGN KEY (raport_id) REFERENCES raporty_wywiadowcze (id),
  FOREIGN KEY (dokument_id) REFERENCES dokumenty (id),
  FOREIGN KEY (teczka_id) REFERENCES teczki (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posiadane_pakiety (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  podmiot_id BIGINT UNSIGNED NOT NULL,
  pakiet_id BIGINT UNSIGNED NOT NULL,
  licencja ENUM('wlasnosc','licencja','kopia') NOT NULL DEFAULT 'kopia',
  zrodlo VARCHAR(60) NULL,
  moze_przekazac TINYINT(1) NOT NULL DEFAULT 0,
  moze_odsprzedac TINYINT(1) NOT NULL DEFAULT 0,
  data_nabycia DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  wazne_do DATETIME NULL,
  UNIQUE KEY uq_posiadane (podmiot_id, pakiet_id, licencja),
  FOREIGN KEY (podmiot_id) REFERENCES podmioty (id),
  FOREIGN KEY (pakiet_id) REFERENCES pakiety_wiedzy (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uprawnienia do poszczególnych pozycji pakietu (czytanie, przekazanie, użycie).
CREATE TABLE IF NOT EXISTS uprawnienia_zawartosci (
  posiadanie_id BIGINT UNSIGNED NOT NULL,
  zawartosc_id BIGINT UNSIGNED NOT NULL,
  odczyt TINYINT(1) NOT NULL DEFAULT 1,
  przekazanie TINYINT(1) NOT NULL DEFAULT 0,
  wykorzystanie TINYINT(1) NOT NULL DEFAULT 0,
  wazne_do_gst BIGINT NULL,
  PRIMARY KEY (posiadanie_id, zawartosc_id),
  FOREIGN KEY (posiadanie_id) REFERENCES posiadane_pakiety (id),
  FOREIGN KEY (zawartosc_id) REFERENCES pakiety_zawartosc (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transmisje (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nadawca_id BIGINT UNSIGNED NOT NULL,
  odbiorca_id BIGINT UNSIGNED NOT NULL,
  pakiet_id BIGINT UNSIGNED NOT NULL,
  metoda ENUM('fizyczny','kurier','szyfrowany','holonet','sieciowy') NOT NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  czy_zostawil_slad TINYINT(1) NOT NULL DEFAULT 0,
  inwigilacja ENUM('niewykryta','wykryta','sledzona','przechwycona') NOT NULL DEFAULT 'niewykryta',
  szyfrowanie VARCHAR(40) NULL,
  suma_kontrolna CHAR(64) NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  czas_gst BIGINT NULL,
  KEY ix_transmisje_nadawca (nadawca_id, czas_utc),
  KEY ix_transmisje_odbiorca (odbiorca_id, czas_utc),
  FOREIGN KEY (nadawca_id) REFERENCES podmioty (id),
  FOREIGN KEY (odbiorca_id) REFERENCES podmioty (id),
  FOREIGN KEY (pakiet_id) REFERENCES pakiety_wiedzy (id),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inspekcje (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jednostka_id BIGINT UNSIGNED NOT NULL,
  skanujaca_jednostka_id BIGINT UNSIGNED NULL,
  operator_osoba_id BIGINT UNSIGNED NULL,
  klasa_skanera TINYINT UNSIGNED NOT NULL DEFAULT 1,
  moc_skanera DECIMAL(8,2) NOT NULL DEFAULT 0,
  odleglosc_km DECIMAL(14,2) NULL,
  warunki VARCHAR(120) NULL,
  wynik ENUM('czysty','podejrzany','wykryto_kontrabande','odmowa','ucieczka') NULL,
  czas_wykrycia_h DECIMAL(8,2) NULL,
  raport_id BIGINT UNSIGNED NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  czas_gst BIGINT NULL,
  KEY ix_inspekcje_jednostka (jednostka_id, czas_utc),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (skanujaca_jednostka_id) REFERENCES jednostki_floty (id),
  FOREIGN KEY (operator_osoba_id) REFERENCES osoby (podmiot_id),
  FOREIGN KEY (raport_id) REFERENCES raporty_wywiadowcze (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inspekcje_znaleziska (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  inspekcja_id BIGINT UNSIGNED NOT NULL,
  partia_id BIGINT UNSIGNED NULL,
  towar_id BIGINT UNSIGNED NOT NULL,
  ilosc DECIMAL(16,3) NOT NULL,
  wykryto TINYINT(1) NOT NULL DEFAULT 0,
  dowod VARCHAR(255) NULL,
  FOREIGN KEY (inspekcja_id) REFERENCES inspekcje (id),
  FOREIGN KEY (partia_id) REFERENCES magazyn_partie (id),
  FOREIGN KEY (towar_id) REFERENCES towary (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Wydarzenia MG, Holonet, kanały, skrzynki ----

CREATE TABLE IF NOT EXISTS wydarzenia (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nazwa VARCHAR(200) NOT NULL,
  szablon VARCHAR(60) NULL,
  poczatek DATETIME NOT NULL,
  koniec DATETIME NULL,
  wlaczone TINYINT(1) NOT NULL DEFAULT 1,
  opis TEXT NULL,
  KEY ix_wydarzenia_okres (wlaczone, poczatek, koniec)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Skutek cenowy: na planecie, dla towaru, kategorii lub instrumentu (puste = wszędzie).
CREATE TABLE IF NOT EXISTS wydarzenia_skutki_cen (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wydarzenie_id BIGINT UNSIGNED NOT NULL,
  planeta_id INT UNSIGNED NULL,
  towar_id BIGINT UNSIGNED NULL,
  instrument_id BIGINT UNSIGNED NULL,
  kategoria ENUM('paliwo','surowce','zywnosc','medycyna','technika','bron','luksus','przyprawy') NULL,
  zmiana_proc DECIMAL(7,2) NOT NULL,
  narastanie_dni SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  mnoznik_wahan DECIMAL(5,2) NOT NULL DEFAULT 1,
  FOREIGN KEY (wydarzenie_id) REFERENCES wydarzenia (id),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id),
  FOREIGN KEY (towar_id) REFERENCES towary (id),
  FOREIGN KEY (instrument_id) REFERENCES instrumenty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kanaly (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nazwa VARCHAR(120) NOT NULL,
  rodzaj VARCHAR(30) NOT NULL DEFAULT 'czarny_rynek',
  klucz_instalacyjny VARCHAR(80) NULL,
  poziom_dostepu TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('aktywny','wylaczony') NOT NULL DEFAULT 'aktywny',
  UNIQUE KEY uq_kanaly_nazwa (nazwa),
  UNIQUE KEY uq_kanaly_klucz (klucz_instalacyjny)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kanaly_wiadomosci (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kanal_id BIGINT UNSIGNED NOT NULL,
  wydarzenie_id BIGINT UNSIGNED NULL,
  tresc TEXT NOT NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_kanaly_wiadomosci (kanal_id, czas_utc),
  FOREIGN KEY (kanal_id) REFERENCES kanaly (id),
  FOREIGN KEY (wydarzenie_id) REFERENCES wydarzenia (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS holonet_komunikaty (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wydarzenie_id BIGINT UNSIGNED NULL,
  tytul VARCHAR(255) NOT NULL,
  tresc TEXT NOT NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_holonet_czas (czas_utc),
  FOREIGN KEY (wydarzenie_id) REFERENCES wydarzenia (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wtyczki_postaci (
  postac_id BIGINT UNSIGNED NOT NULL,
  kanal_id BIGINT UNSIGNED NOT NULL,
  zainstalowano DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (postac_id, kanal_id),
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id),
  FOREIGN KEY (kanal_id) REFERENCES kanaly (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS slowniki (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nazwa VARCHAR(120) NOT NULL,
  UNIQUE KEY uq_slowniki_nazwa (nazwa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS slowniki_wpisy (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slownik_id BIGINT UNSIGNED NOT NULL,
  kod VARCHAR(120) NOT NULL,
  odmiany VARCHAR(255) NULL,
  znaczenie VARCHAR(255) NOT NULL,
  UNIQUE KEY uq_wpisy_kod (slownik_id, kod),
  FOREIGN KEY (slownik_id) REFERENCES slowniki (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dokumenty_slowniki (
  dokument_id BIGINT UNSIGNED NOT NULL,
  slownik_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (dokument_id, slownik_id),
  FOREIGN KEY (dokument_id) REFERENCES dokumenty (id),
  FOREIGN KEY (slownik_id) REFERENCES slowniki (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS skrzynki_foldery (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  postac_id BIGINT UNSIGNED NOT NULL,
  nazwa VARCHAR(80) NOT NULL,
  UNIQUE KEY uq_foldery (postac_id, nazwa),
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS skrzynki_pliki (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  postac_id BIGINT UNSIGNED NOT NULL,
  dokument_id BIGINT UNSIGNED NOT NULL,
  folder_id BIGINT UNSIGNED NULL,
  odebrano DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_skrzynki (postac_id, dokument_id),
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id),
  FOREIGN KEY (dokument_id) REFERENCES dokumenty (id),
  FOREIGN KEY (folder_id) REFERENCES skrzynki_foldery (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. POSTACIE, FRAKCJE, NPC, DIALOGI
-- ---------------------------------------------------------------------

-- Otwarta karta postaci: cechy, umiejętności i liczniki bez sztywnych kolumn.
CREATE TABLE IF NOT EXISTS postacie_statystyki (
  postac_id BIGINT UNSIGNED NOT NULL,
  rodzaj ENUM('cecha','umiejetnosc','licznik') NOT NULL,
  nazwa VARCHAR(60) NOT NULL,
  wartosc INT NOT NULL DEFAULT 0,
  maks INT NULL,
  PRIMARY KEY (postac_id, rodzaj, nazwa),
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS postacie_reputacja (
  postac_id BIGINT UNSIGNED NOT NULL,
  frakcja_id BIGINT UNSIGNED NOT NULL,
  wartosc SMALLINT NOT NULL DEFAULT 0 CHECK (wartosc BETWEEN -100 AND 100),
  PRIMARY KEY (postac_id, frakcja_id),
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id),
  FOREIGN KEY (frakcja_id) REFERENCES frakcje (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS postacie_kontakty (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  postac_id BIGINT UNSIGNED NOT NULL,
  osoba_id BIGINT UNSIGNED NULL,
  nazwa VARCHAR(160) NOT NULL,
  opis VARCHAR(255) NULL,
  KEY ix_kontakty_postac (postac_id),
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS postacie_tozsamosci (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  postac_id BIGINT UNSIGNED NOT NULL,
  rodzaj ENUM('dokument','licencja','tozsamosc_alternatywna') NOT NULL,
  nazwa VARCHAR(160) NOT NULL,
  numer VARCHAR(60) NULL,
  falszywa TINYINT(1) NOT NULL DEFAULT 0,
  wazne_do_gst BIGINT NULL,
  KEY ix_tozsamosci_postac (postac_id),
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS postacie_notatnik (
  postac_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  tresc MEDIUMTEXT NOT NULL,
  zmieniono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gdzie postać jest teraz. Jednostka ustawiona = jest na pokładzie.
CREATE TABLE IF NOT EXISTS postacie_pozycja (
  postac_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  lokalizacja_id BIGINT UNSIGNED NULL,
  planeta_id INT UNSIGNED NULL,
  jednostka_id BIGINT UNSIGNED NULL,
  miejsce_opis VARCHAR(160) NULL,
  zmieniono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_pozycja_lokalizacja (lokalizacja_id),
  KEY ix_pozycja_jednostka (jednostka_id),
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id),
  FOREIGN KEY (jednostka_id) REFERENCES jednostki_floty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scena wyświetlana graczowi (ustawiana ręcznie przez MG).
CREATE TABLE IF NOT EXISTS sceny_postaci (
  postac_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  scena ENUM('przestrzen','skok','nadswietlna','wyjscie','orbita','powierzchnia') NOT NULL DEFAULT 'przestrzen',
  typ_planety ENUM('pustynna','lodowa','oceaniczna','lesna','wulkaniczna','gazowa','miasto','bagienna','skalista') NULL,
  odleglosc ENUM('blisko','srednio','daleko') NULL,
  miejsce VARCHAR(160) NULL,
  opis VARCHAR(255) NULL,
  zmieniono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS podroze (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  postac_id BIGINT UNSIGNED NOT NULL,
  srodek ENUM('pieszo','taksowka','pojazd','statek') NOT NULL,
  pojazd_id BIGINT UNSIGNED NULL,
  z_lokalizacji_id BIGINT UNSIGNED NOT NULL,
  do_lokalizacji_id BIGINT UNSIGNED NOT NULL,
  start_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  minuty_swiata INT UNSIGNED NOT NULL,
  status ENUM('w_toku','dotarl','przerwana') NOT NULL DEFAULT 'w_toku',
  koszt BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ksiegowanie_id BIGINT UNSIGNED NULL,
  KEY ix_podroze_postac (postac_id, status),
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id),
  FOREIGN KEY (pojazd_id) REFERENCES pojazdy (id),
  FOREIGN KEY (z_lokalizacji_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (do_lokalizacji_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (ksiegowanie_id) REFERENCES ksiegowania (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kalendarz_sesji (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  konto_id BIGINT UNSIGNED NOT NULL,
  postac_nazwa VARCHAR(80) NULL,
  data DATE NOT NULL,
  status ENUM('dostepny','niepewny','nieobecny') NOT NULL,
  notatka VARCHAR(120) NULL,
  UNIQUE KEY uq_kalendarz (konto_id, data),
  FOREIGN KEY (konto_id) REFERENCES konta (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sieć frakcji: instalacje, ich zasoby i drzewo dowodzenia.
CREATE TABLE IF NOT EXISTS instalacje_frakcji (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  frakcja_id BIGINT UNSIGNED NOT NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  planeta_id INT UNSIGNED NULL,
  nazwa VARCHAR(160) NOT NULL,
  rodzaj VARCHAR(40) NOT NULL DEFAULT 'baza',
  status ENUM('aktywna','nieczynna','zniszczona','utajniona') NOT NULL DEFAULT 'aktywna',
  KEY ix_instalacje_frakcja (frakcja_id),
  CHECK (lokalizacja_id IS NOT NULL OR planeta_id IS NOT NULL),
  FOREIGN KEY (frakcja_id) REFERENCES frakcje (podmiot_id),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instalacje_zasoby (
  instalacja_id BIGINT UNSIGNED NOT NULL,
  towar_id BIGINT UNSIGNED NOT NULL,
  ilosc DECIMAL(16,3) NOT NULL CHECK (ilosc >= 0),
  PRIMARY KEY (instalacja_id, towar_id),
  FOREIGN KEY (instalacja_id) REFERENCES instalacje_frakcji (id),
  FOREIGN KEY (towar_id) REFERENCES towary (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS frakcje_dowodzenie (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  frakcja_id BIGINT UNSIGNED NOT NULL,
  podmiot_id BIGINT UNSIGNED NOT NULL,
  przelozony_id BIGINT UNSIGNED NULL,
  stanowisko VARCHAR(120) NOT NULL DEFAULT '',
  UNIQUE KEY uq_dowodzenie (frakcja_id, podmiot_id),
  FOREIGN KEY (frakcja_id) REFERENCES frakcje (podmiot_id),
  FOREIGN KEY (podmiot_id) REFERENCES podmioty (id),
  FOREIGN KEY (przelozony_id) REFERENCES frakcje_dowodzenie (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- NPC: relacje, pamięć i dialogi (silnik rozmów AI) ----

CREATE TABLE IF NOT EXISTS osoby_profil_dialogowy (
  osoba_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  osobowosc TEXT NULL,
  styl_wypowiedzi TEXT NULL,
  wiedza TEXT NULL,
  ograniczenia TEXT NULL,
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS osoby_relacje (
  osoba_id BIGINT UNSIGNED NOT NULL,
  podmiot_id BIGINT UNSIGNED NOT NULL,
  rodzaj VARCHAR(30) NOT NULL,
  wartosc SMALLINT NOT NULL DEFAULT 0 CHECK (wartosc BETWEEN -100 AND 100),
  PRIMARY KEY (osoba_id, podmiot_id, rodzaj),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id),
  FOREIGN KEY (podmiot_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS osoby_pamiec (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  osoba_id BIGINT UNSIGNED NOT NULL,
  zdarzenie_id BIGINT UNSIGNED NULL,
  tresc VARCHAR(1000) NOT NULL,
  waznosc TINYINT UNSIGNED NOT NULL DEFAULT 5 CHECK (waznosc BETWEEN 1 AND 10),
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pamiec_osoba (osoba_id, waznosc),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id),
  FOREIGN KEY (zdarzenie_id) REFERENCES dziennik_zdarzen (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sesje_dialogowe (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  osoba_id BIGINT UNSIGNED NOT NULL,
  postac_id BIGINT UNSIGNED NOT NULL,
  tryb ENUM('tekst','holo','glos') NOT NULL DEFAULT 'tekst',
  rozpoczeto DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  zakonczono DATETIME NULL,
  KEY ix_sesje_osoba (osoba_id, rozpoczeto),
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id),
  FOREIGN KEY (postac_id) REFERENCES postacie (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dialogi_wpisy (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sesja_id BIGINT UNSIGNED NOT NULL,
  autor ENUM('gracz','npc','mg') NOT NULL,
  tresc TEXT NOT NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_dialogi_sesja (sesja_id, id),
  FOREIGN KEY (sesja_id) REFERENCES sesje_dialogowe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. HISTORIA (dzienniki zmian bez kluczy obcych do obiektu: obiekt może zniknąć)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS historia_wlasnosci (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  obiekt_typ ENUM('jednostka','komponent','budynek','magazyn','pojazd','firma') NOT NULL,
  obiekt_id BIGINT UNSIGNED NOT NULL,
  z_podmiotu_id BIGINT UNSIGNED NULL,
  do_podmiotu_id BIGINT UNSIGNED NULL,
  powod VARCHAR(255) NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  czas_gst BIGINT NULL,
  KEY ix_hw_obiekt (obiekt_typ, obiekt_id),
  FOREIGN KEY (z_podmiotu_id) REFERENCES podmioty (id),
  FOREIGN KEY (do_podmiotu_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS historia_lokalizacji (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  obiekt_typ ENUM('jednostka','osoba','postac','pojazd') NOT NULL,
  obiekt_id BIGINT UNSIGNED NOT NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  pozycja_x DECIMAL(16,4) NULL, pozycja_y DECIMAL(16,4) NULL, pozycja_z DECIMAL(16,4) NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  czas_gst BIGINT NULL,
  KEY ix_hl_obiekt (obiekt_typ, obiekt_id, czas_utc),
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS historia_zmian (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  obiekt_typ VARCHAR(40) NOT NULL,
  obiekt_id BIGINT UNSIGNED NOT NULL,
  pole VARCHAR(60) NOT NULL,
  wartosc_przed VARCHAR(255) NULL,
  wartosc_po VARCHAR(255) NULL,
  autor_id BIGINT UNSIGNED NULL,
  powod VARCHAR(255) NULL,
  czas_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  czas_gst BIGINT NULL,
  KEY ix_hz_obiekt (obiekt_typ, obiekt_id, czas_utc),
  FOREIGN KEY (autor_id) REFERENCES podmioty (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- WIDOKI KONTROLNE (każdy wynik tego widoku to błąd księgowy)
-- ---------------------------------------------------------------------

CREATE OR REPLACE VIEW v_ksiegowania_niezbilansowane AS
  SELECT ksiegowanie_id, SUM(kwota) AS suma
  FROM ksiegowania_pozycje
  GROUP BY ksiegowanie_id
  HAVING SUM(kwota) <> 0;

-- Saldo rachunku musi równać się sumie jego pozycji (otwarcie salda księguj jako 'bilans_otwarcia').
CREATE OR REPLACE VIEW v_rozjazd_sald AS
  SELECT r.id AS rachunek_id, r.numer, r.saldo, COALESCE(SUM(p.kwota), 0) AS suma_pozycji
  FROM rachunki r
  LEFT JOIN ksiegowania_pozycje p ON p.rachunek_id = r.id
  GROUP BY r.id, r.numer, r.saldo
  HAVING r.saldo <> COALESCE(SUM(p.kwota), 0);

-- ---------------------------------------------------------------------
-- DANE STARTOWE (idempotentne)
-- ---------------------------------------------------------------------

INSERT IGNORE INTO zegar_swiata (id, epoka_utc, minuta_gst_w_epoce, sekund_na_minute_gst, wstrzymany)
  VALUES (1, UTC_TIMESTAMP(), 0, 1.000, 0);

INSERT IGNORE INTO systemy_nawigacyjne (kod, nazwa, model, opis)
  VALUES ('HOLOLINK', 'Hololink', 'kolowy', 'Podstawowy model orbitalny: ruch jednostajny po okręgu, położenie z czasu.');

-- Rachunki systemowe: druga strona każdego zapisu zewnętrznego (gotówka, rynek, rozliczenia giełdy).
INSERT INTO podmioty (typ, nazwa)
  SELECT 'instytucja', n.nazwa FROM (
    SELECT 'Skarb systemowy' AS nazwa UNION ALL SELECT 'Rynek towarowy' UNION ALL SELECT 'Rozliczenia giełdowe CDEX'
  ) n
  WHERE NOT EXISTS (SELECT 1 FROM podmioty p WHERE p.typ = 'instytucja' AND p.nazwa = n.nazwa);

INSERT INTO instytucje (podmiot_id, rodzaj, opis)
  SELECT p.id, 'system', 'Rachunek systemowy (strona zewnętrzna zapisów księgowych)'
  FROM podmioty p
  WHERE p.typ = 'instytucja' AND p.nazwa IN ('Skarb systemowy','Rynek towarowy','Rozliczenia giełdowe CDEX')
    AND NOT EXISTS (SELECT 1 FROM instytucje i WHERE i.podmiot_id = p.id);

INSERT INTO rachunki (numer, wlasciciel_id, czy_glowny)
  SELECT CASE p.nazwa WHEN 'Skarb systemowy' THEN '0000-0000-0001'
                      WHEN 'Rynek towarowy' THEN '0000-0000-0002'
                      ELSE '0000-0000-0003' END, p.id, 1
  FROM podmioty p
  WHERE p.typ = 'instytucja' AND p.nazwa IN ('Skarb systemowy','Rynek towarowy','Rozliczenia giełdowe CDEX')
    AND NOT EXISTS (SELECT 1 FROM rachunki r WHERE r.wlasciciel_id = p.id);

INSERT IGNORE INTO schema_migracje (wersja, opis)
  VALUES ('2026-11-16-fundament', 'Schemat relacyjny v2: wszystkie warstwy, tabele puste poza danymi systemowymi');

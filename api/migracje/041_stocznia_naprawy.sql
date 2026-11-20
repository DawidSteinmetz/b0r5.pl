-- Stocznia naprawcza (Kuat): kolejka napraw podzespołów okrętów.
--
-- Gracz (kapitan / dowódca floty) przy Kuat jednym kliknięciem oddaje okręt do naprawy: każdy uszkodzony podzespół
-- (reaktor, hipernapęd, osłony, kadłub) dostaje osobny wpis z PROGNOZĄ (pierwsze losowanie) i niezależnie
-- wylosowanym CZASEM RZECZYWISTYM (drugie losowanie). Stocznia ma kilka stanowisk pracujących równolegle
-- (api/floty.php, STOCZNIE); wolne stanowisko od razu bierze następny wpis z kolejki (FIFO). Gracz widzi prognozę;
-- po rzeczywistym końcu podzespół wraca do 100%.
--
-- Import w phpMyAdmin. Ponowny import jest bezpieczny (CREATE TABLE IF NOT EXISTS).

USE hololink;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stocznia_naprawy (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  stocznia VARCHAR(40) NOT NULL,
  obiekt_id BIGINT UNSIGNED NOT NULL,
  podzespol VARCHAR(40) NOT NULL,
  od_wartosci TINYINT UNSIGNED NOT NULL,
  stan ENUM('kolejka','w_toku','gotowa','przerwana') NOT NULL DEFAULT 'kolejka',
  zgloszono_utc DATETIME NOT NULL,
  start_utc DATETIME NULL,
  koniec_utc DATETIME NULL,
  prognoza_s INT UNSIGNED NOT NULL,
  faktyczny_s INT UNSIGNED NOT NULL,
  zglaszajacy_login VARCHAR(64) NOT NULL DEFAULT '',
  KEY ix_naprawy_stan (stocznia, stan, zgloszono_utc, id),
  KEY ix_naprawy_obiekt (obiekt_id, stan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

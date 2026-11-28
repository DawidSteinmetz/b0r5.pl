-- Stocznia naprawcza (Kuat): kolejka napraw podzespołów okrętów.
--
-- Gracz przy Kuat oddaje okręt do naprawy; wpisy pracują równolegle i FIFO.
-- Import jest bezpieczny przy ponowieniu dzięki CREATE TABLE IF NOT EXISTS.

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

-- Karta postaci NPC w systemie Star Wars: Edge of the Empire (CV): cechy, umiejętności, talenty, sprzęt, historia.
--
-- Jedna karta na osobę (osoby.podmiot_id) jako JSON — ten sam format co karta gracza (klucz „eote” w karcie postaci).
-- Zapisuje ją generator postaci w panelu MG (zakładka „Generator postaci”). Karty graczy nie potrzebują tej tabeli.
--
-- Import w phpMyAdmin. Ponowny import jest bezpieczny (CREATE TABLE IF NOT EXISTS).

USE hololink;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS osoby_karta_eote (
  osoba_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  dane MEDIUMTEXT NOT NULL,
  zmieniono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (osoba_id) REFERENCES osoby (podmiot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

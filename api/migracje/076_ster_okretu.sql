-- Ster okrętu gracza i cel wskazany baterii (konsole Silniki, Nawigacja, Kontrola ognia):
-- kurs_cel_deg / v_cel_km_s — kurs i prędkość zadane rozkazem „ster” (okręt skręca z prędkością obrotu profilu
-- i przyspiesza do zadanej), cel_ognia_nr — numer wrogiej jednostki ze scenariusza wskazanej bateriom.
-- Wymaga 070. Import w phpMyAdmin. Ponowny import: ALTER zgłosi „Duplicate column” — kolumny już są.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE bitwy_jednostki
  ADD COLUMN kurs_cel_deg DOUBLE NULL AFTER zwrot_deg,
  ADD COLUMN v_cel_km_s DOUBLE NULL AFTER kurs_cel_deg,
  ADD COLUMN cel_ognia_nr TINYINT UNSIGNED NULL AFTER v_cel_km_s;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v076-bitwy-ster-okretu', 'Ster okrętu gracza i cel wskazany baterii w symulacjach bitew')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

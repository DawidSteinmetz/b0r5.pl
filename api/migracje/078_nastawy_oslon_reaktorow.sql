-- Nastawy konsol Osłony i Reaktory w symulacjach (narzedzia/mostek/MECHANIKA.md 11.4): suwaki 0…1 i przełączniki
-- (pokrycie granic sektorów osłon, synchronizacje, przydziały mocy, moc reaktorów pomocniczych, gęstość hipermaterii,
-- pole komory, rezerwy, zakresy reaktora uzbrojenia i generatora mocy, przełączenia zasilania). Brak wiersza = wartość
-- domyślna z silnika (BITWA_NASTAWY). Ciepło rdzenia i dławienie — stan okrętu w symulacji.
-- Rozkazy (bitwy_rozkazy): nastawa / synchronizuj / przelacz (wartosc_po „kod=liczba”), wyrownaj (grupa), wzmocnij.
-- Wymaga 068 i odświeżonej 069 (rozkaz „nastawa”). Kolumna dławienia rdzenia to dlawienie_rdzenia — „dlawienie”
-- (DECIMAL, współczynnik) istnieje od 068 i nie jest używana przez silnik.

CREATE TABLE IF NOT EXISTS bitwy_nastawy (
  bitwa_jednostka_id BIGINT UNSIGNED NOT NULL,
  kod VARCHAR(24) NOT NULL,
  wartosc DOUBLE NOT NULL,
  PRIMARY KEY (bitwa_jednostka_id, kod),
  FOREIGN KEY (bitwa_jednostka_id) REFERENCES bitwy_jednostki (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bitwy_jednostki
  ADD COLUMN cieplo_reaktora DOUBLE NOT NULL DEFAULT 0,
  ADD COLUMN dlawienie_rdzenia TINYINT(1) NOT NULL DEFAULT 0;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v078-nastawy-oslon-reaktorow', 'Nastawy konsol Osłony i Reaktory, ciepło rdzenia w symulacjach bitew')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

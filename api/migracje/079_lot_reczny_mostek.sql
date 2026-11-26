-- Lot ręczny okrętu z mostka w realnej grze (narzedzia/mostek/MECHANIKA.md 11.5, api/lot_reczny.php):
-- okrety_nastawy — nastawy pulpitów okrętu poza symulacją (wspólne dla załogi; brak wiersza = wartość domyślna,
-- wzm_<cel> / wzo_<cel> — koniec wzmocnienia i odnowienia, czas uniksowy), okrety_lot_reczny — prędkość okrętu,
-- prędkość i kurs zadane z konsol Silniki / Nawigacja (kurs nawigacyjny: od północy, zgodnie z zegarem) i chwila
-- ostatniego przeliczenia ruchu. Pozycja i kurs okrętu zostają w mapa_obiekty (uklad_kx, uklad_ky, kurs_deg).
-- Wymaga 005, 039 (napęd), migracji układów (uklad_kx) i 077.

CREATE TABLE IF NOT EXISTS okrety_nastawy (
  obiekt_id BIGINT UNSIGNED NOT NULL,
  kod VARCHAR(24) NOT NULL,
  wartosc DOUBLE NOT NULL,
  PRIMARY KEY (obiekt_id, kod),
  FOREIGN KEY (obiekt_id) REFERENCES mapa_obiekty (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS okrety_lot_reczny (
  obiekt_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  v_kms DOUBLE NOT NULL DEFAULT 0,
  v_cel_kms DOUBLE NOT NULL DEFAULT 0,
  kurs_cel_deg DOUBLE NULL,
  ostatnio_utc DATETIME NOT NULL,
  FOREIGN KEY (obiekt_id) REFERENCES mapa_obiekty (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v079-lot-reczny-mostek', 'Lot ręczny okrętu z konsol mostka i nastawy pulpitów poza symulacją')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

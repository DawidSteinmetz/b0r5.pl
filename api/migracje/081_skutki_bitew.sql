-- Skutki bitew w realnej grze (narzedzia/mostek/MECHANIKA.md 13, etap 3; api/starcia.php): starcia.skutki_utc —
-- chwila zatwierdzenia skutków przez MG; okrety_zyly — martwe żyły generatorów osłon okrętu po bitwie (bitmapa jak
-- bitwy_zyly), wczytywane na start następnej bitwy, dopóki osłony okrętu < 100% (naprawa w stoczni przywraca 100%).
-- Wymaga 080.

ALTER TABLE starcia ADD COLUMN skutki_utc DATETIME NULL AFTER zakonczono_utc;

CREATE TABLE IF NOT EXISTS okrety_zyly (
  obiekt_id BIGINT UNSIGNED NOT NULL,
  kod VARCHAR(20) NOT NULL,
  liczba SMALLINT UNSIGNED NOT NULL,
  martwe VARBINARY(1024) NOT NULL,
  PRIMARY KEY (obiekt_id, kod),
  FOREIGN KEY (obiekt_id) REFERENCES mapa_obiekty (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v081-skutki-bitew', 'Skutki bitew w świecie gry: kadłub, osłony, martwe żyły, maszyny, polegli piloci (po zatwierdzeniu MG)')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

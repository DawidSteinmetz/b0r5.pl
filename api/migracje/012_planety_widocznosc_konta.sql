-- Ogranicza widoczność zastrzeżonych planet do wskazanych loginów.
-- Wymaga aktualnego schematu v11 i tabel mapa_planety, planety_gry oraz schema_migracje.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS planety_widocznosc_konta (
  planeta_id INT UNSIGNED NOT NULL,
  konto_login VARCHAR(64) NOT NULL,
  nadano TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (planeta_id, konto_login),
  KEY ix_planety_widocznosc_login (konto_login),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v12-planety-widocznosc-konta', 'Dostępy do zastrzeżonych planet według loginu konta')
ON DUPLICATE KEY UPDATE opis=VALUES(opis);

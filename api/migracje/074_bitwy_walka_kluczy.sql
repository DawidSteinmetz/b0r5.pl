-- Walka kluczy i obrona punktowa (api/bitwa_silnik.php, narzedzia/mostek/silnik.py):
-- typy_maszyn: prędkość kątowa skrętu, zasięg i stożek ognia do maszyn, rozrzut θ (wzór celności MECHANIKA 4.3);
-- profile_klas_uzbrojenie: punkty na kadłubie (turbolasery, obrona punktowa, jony, generatory osłon, sensory, silniki)
--   z łukiem ostrzału i zasięgiem — pokrycie obrony punktowej wynika z położenia działek (rysunek ISD od użytkownika);
-- bitwy_klucze: nasycenie osłon maszyn klucza, kurs zadany figurą (prosto, łagodnie, ostro, pętla, split-S).
-- Dane wypełnia migracja 075 (generowana). Wymaga 070. Ponowny import: ALTER zgłosi „Duplicate column”.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE typy_maszyn
  ADD COLUMN zwrot_deg_s DECIMAL(5,1) NOT NULL DEFAULT 30 AFTER ladowanie_tiki,
  ADD COLUMN zasieg_ognia_km DECIMAL(5,2) NOT NULL DEFAULT 1.5 AFTER zwrot_deg_s,
  ADD COLUMN stozek_deg DECIMAL(5,1) NOT NULL DEFAULT 15 AFTER zasieg_ognia_km,
  ADD COLUMN theta_mrad DECIMAL(6,3) NOT NULL DEFAULT 4 AFTER stozek_deg;

CREATE TABLE IF NOT EXISTS profile_klas_uzbrojenie (
  profil_kod VARCHAR(20) NOT NULL,
  nr SMALLINT UNSIGNED NOT NULL,
  rodzaj ENUM('turbolaser','pd','jon','oslona','sensory','silnik') NOT NULL,
  x DECIMAL(7,4) NOT NULL,
  y DECIMAL(7,4) NOT NULL,
  luk_srodek_deg DECIMAL(6,1) NOT NULL DEFAULT 0,
  luk_pol_deg DECIMAL(5,1) NOT NULL DEFAULT 0,
  zasieg_km DECIMAL(7,2) NOT NULL DEFAULT 0,
  theta_mrad DECIMAL(6,3) NOT NULL DEFAULT 0,
  hz SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (profil_kod, nr),
  KEY ix_uzbrojenie_rodzaj (profil_kod, rodzaj),
  FOREIGN KEY (profil_kod) REFERENCES profile_klas (kod) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bitwy_klucze
  ADD COLUMN oslona_pkt DOUBLE NOT NULL DEFAULT 0 AFTER ai,
  ADD COLUMN kurs_zadany DOUBLE NULL AFTER oslona_pkt;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v074-bitwy-walka-kluczy', 'Walka kluczy: kinematyka i broń maszyn, uzbrojenie okrętów na kadłubie, osłony maszyn, kurs zadany')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

-- Starcia w realnej grze (narzedzia/mostek/MECHANIKA.md 13, api/starcia.php): skok agresora do układu z wrogimi
-- okrętami zatrzymuje czas w hiperprzestrzeni, strony dogadują termin walki, obrońca może uciec. starcia_skoki —
-- zatrzymane skoki (obiekt_id 0: cały rozkaz) z pierwotnym czasem wyjścia; starcia_obroncy — okręty obrońcy z chwili
-- ataku; starcia_propozycje — terminy proponowane przez strony. Wymaga 006 (skoki), 010 (mapa_bitwy).

CREATE TABLE IF NOT EXISTS starcia (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  planeta_id INT UNSIGNED NOT NULL,
  uklad_x DECIMAL(12,1) NOT NULL,
  uklad_y DECIMAL(12,1) NOT NULL,
  nazwa VARCHAR(160) NOT NULL,
  status ENUM('negocjacja','umowione','bitwa','zakonczone','obronca_uciekl','anulowane') NOT NULL DEFAULT 'negocjacja',
  agresor_frakcja VARCHAR(40) NOT NULL,
  obronca_frakcja VARCHAR(40) NULL,
  pauza_od_utc DATETIME NOT NULL,
  termin_utc DATETIME NULL,
  bitwa_id BIGINT UNSIGNED NULL,
  zakonczono_utc DATETIME NULL,
  utworzono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_starcia_planeta_status (planeta_id, status),
  FOREIGN KEY (planeta_id) REFERENCES mapa_planety (id),
  FOREIGN KEY (bitwa_id) REFERENCES mapa_bitwy (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS starcia_uczestnicy (
  starcie_id BIGINT UNSIGNED NOT NULL,
  login VARCHAR(64) NOT NULL,
  strona ENUM('agresor','obronca') NOT NULL,
  PRIMARY KEY (starcie_id, login),
  KEY ix_starcia_uczestnicy_login (login),
  FOREIGN KEY (starcie_id) REFERENCES starcia (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS starcia_skoki (
  starcie_id BIGINT UNSIGNED NOT NULL,
  rozkaz_id BIGINT UNSIGNED NOT NULL,
  obiekt_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  strona ENUM('agresor','obronca') NOT NULL,
  eta_org_utc DATETIME NOT NULL,
  PRIMARY KEY (starcie_id, rozkaz_id, obiekt_id),
  FOREIGN KEY (starcie_id) REFERENCES starcia (id) ON DELETE CASCADE,
  FOREIGN KEY (rozkaz_id) REFERENCES mapa_rozkazy_skokow (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS starcia_obroncy (
  starcie_id BIGINT UNSIGNED NOT NULL,
  obiekt_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (starcie_id, obiekt_id),
  FOREIGN KEY (starcie_id) REFERENCES starcia (id) ON DELETE CASCADE,
  FOREIGN KEY (obiekt_id) REFERENCES mapa_obiekty (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS starcia_propozycje (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  starcie_id BIGINT UNSIGNED NOT NULL,
  login VARCHAR(64) NOT NULL,
  strona ENUM('agresor','obronca') NOT NULL,
  termin_utc DATETIME NOT NULL,
  status ENUM('otwarta','przyjeta','zastapiona') NOT NULL DEFAULT 'otwarta',
  utworzono_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_starcia_propozycje (starcie_id, status),
  FOREIGN KEY (starcie_id) REFERENCES starcia (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v080-starcia', 'Starcia: zatrzymanie czasu przy skoku agresora, dogadywanie terminu walki, ucieczka obrońcy, posiłki')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

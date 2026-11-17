-- Radar orbitalny i widoczność bitwy dla swobodnych mieszkańców planety.
-- Pole ukryty_za_ksiezycem jest tylko rezerwą na przyszłość; nie wpływa na widoczność.

ALTER TABLE mapa_obiekty
  ADD COLUMN stan_swobody ENUM('swobodna','uwieziona','nieprzytomna') NOT NULL DEFAULT 'swobodna' AFTER status,
  ADD COLUMN ukryty_za_ksiezycem TINYINT(1) NOT NULL DEFAULT 0 AFTER aktywny;

CREATE TABLE IF NOT EXISTS mapa_bitwy (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kod VARCHAR(80) NOT NULL,
  nazwa VARCHAR(160) NOT NULL,
  planeta_id INT UNSIGNED NOT NULL,
  status ENUM('aktywna','zakonczona') NOT NULL DEFAULT 'aktywna',
  opis TEXT NULL,
  rozpoczecie_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  koniec_utc DATETIME NULL,
  zmieniono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mapa_bitwy_kod (kod),
  KEY ix_mapa_bitwy_planeta_status (planeta_id,status),
  CONSTRAINT fk_mapa_bitwy_planeta FOREIGN KEY (planeta_id) REFERENCES mapa_planety(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mapa_bitwy_uczestnicy (
  bitwa_id BIGINT UNSIGNED NOT NULL,
  obiekt_id BIGINT UNSIGNED NOT NULL,
  strona VARCHAR(120) NOT NULL,
  kolor CHAR(7) NOT NULL DEFAULT '#ff6b55',
  dodano TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (bitwa_id,obiekt_id),
  KEY ix_mapa_bitwy_uczestnicy_obiekt (obiekt_id),
  CONSTRAINT fk_mapa_bitwy_uczestnicy_bitwa FOREIGN KEY (bitwa_id) REFERENCES mapa_bitwy(id) ON DELETE CASCADE,
  CONSTRAINT fk_mapa_bitwy_uczestnicy_obiekt FOREIGN KEY (obiekt_id) REFERENCES mapa_obiekty(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migracje (wersja,opis)
VALUES ('v10-radar-bitwy-swoboda','Radar orbitalny, bitwy widoczne dla swobodnych mieszkańców planety i status swobody postaci');

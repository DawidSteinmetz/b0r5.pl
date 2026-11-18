-- Czas świata płynie 1:1 z czasem rzeczywistym. MG może skrócić albo wydłużyć pojedynczy proces
-- (skok floty, podróż, obsługa naziemna) — w grze nazywa się to resynchronizacją (ingerencją mocy).
-- Gra nie tłumaczy graczom, co się stało; ten dziennik widzi tylko MG.
-- Wymaga migracji 012 i tabeli schema_migracje. Import w phpMyAdmin; ponowny import niczego nie psuje.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS resynchronizacje (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rodzaj ENUM('skok_floty','podroz','obsluga_statku') NOT NULL,
  obiekt VARCHAR(120) NOT NULL COMMENT 'np. id rozkazu skoku, uid postaci, f:<firma>:<statek>',
  opis VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'czytelna nazwa procesu w chwili ingerencji',
  koniec_przed_utc DATETIME NOT NULL,
  koniec_po_utc DATETIME NOT NULL,
  mg_login VARCHAR(64) NOT NULL,
  notatka VARCHAR(500) NOT NULL DEFAULT '',
  utworzono_utc DATETIME NOT NULL,
  KEY ix_resynchronizacje_obiekt (rodzaj, obiekt),
  KEY ix_resynchronizacje_czas (utworzono_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zegar świata: 1 sekunda rzeczywista = 1 sekunda świata (60 s na minutę świata).
UPDATE zegar_swiata SET sekund_na_minute_gst = 60.000 WHERE id = 1;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v13-czas-resynchronizacje', 'Czas świata 1:1 i dziennik resynchronizacji procesów przez MG')
ON DUPLICATE KEY UPDATE opis=VALUES(opis);

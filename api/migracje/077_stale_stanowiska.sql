-- Stałe stanowiska na mostku (narzedzia/mostek/PULPITY.md 1c): pulpit okrętu przypisany graczowi na stałe, niezależnie
-- od symulacji. Każda nowa symulacja okrętu dostaje kopię tych przydziałów (bitwy_stanowiska), a zmiana stałego
-- przydziału przechodzi też na trwające symulacje tego okrętu. Przydziela MG albo kapitan (api/bitwa.php, mostek_stanowisko).
-- Pulpit: nazwa pulpitu klasy ('Osłony', 'Radar', …) albo 'Klucz N' / 'Eskadra N'.

CREATE TABLE IF NOT EXISTS okrety_stanowiska (
  obiekt_id BIGINT UNSIGNED NOT NULL,
  pulpit VARCHAR(40) NOT NULL,
  login VARCHAR(64) NOT NULL,
  przydzielil VARCHAR(64) NULL,
  zmieniono_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (obiekt_id, pulpit),
  KEY ix_okrety_stanowiska_login (login),
  FOREIGN KEY (obiekt_id) REFERENCES mapa_obiekty (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v077-stale-stanowiska', 'Stałe stanowiska graczy na mostku okrętu i mostek bez symulacji')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

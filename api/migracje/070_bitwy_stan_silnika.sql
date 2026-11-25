-- Stan silnika bitwy (api/bitwa.php) — uzupełnienie migracji 068.
--
-- Pozycje, prędkości i kąty jako DOUBLE: silnik zapisuje stan po każdym doliczeniu tików, a zaokrąglanie DECIMAL przy
-- zapisie zmieniałoby przebieg zależnie od tego, jak często konsole pytają serwer (bitwa ma być deterministyczna:
-- ziarno + dziennik rozkazów = zawsze ten sam przebieg, także przy cofaniu przez MG).
-- bitwy_jednostki.v_km_s — prędkość wprost (kurs = zwrot_deg). bitwy_klucze: ranga klucza (prowadzącego), ładunek
-- działek w tikach, akcje (XP), kurs, ai (klucz prowadzi AI strony). bitwy_systemy.auto — tryb automatyczny osłon.
-- bitwy_rozkazy.kanal — kanał rozkazu (klucz albo system jednostki), ten sam tekst co w silniku wzorcowym.
--
-- Wymaga 068. Import w phpMyAdmin. Ponowny import: ALTER zgłosi „Duplicate column” — reszta jest już w bazie.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE bitwy_jednostki
  MODIFY x_km DOUBLE NOT NULL DEFAULT 0,
  MODIFY y_km DOUBLE NOT NULL DEFAULT 0,
  MODIFY vx_km_s DOUBLE NOT NULL DEFAULT 0,
  MODIFY vy_km_s DOUBLE NOT NULL DEFAULT 0,
  MODIFY zwrot_deg DOUBLE NOT NULL DEFAULT 0,
  ADD COLUMN v_km_s DOUBLE NOT NULL DEFAULT 0 AFTER vy_km_s;

ALTER TABLE bitwy_klucze
  MODIFY x_km DOUBLE NOT NULL DEFAULT 0,
  MODIFY y_km DOUBLE NOT NULL DEFAULT 0,
  MODIFY moc DOUBLE NOT NULL DEFAULT 1,
  ADD COLUMN ranga ENUM('as','standard','kadet') NOT NULL DEFAULT 'standard' AFTER typ_maszyny,
  ADD COLUMN kurs_deg DOUBLE NOT NULL DEFAULT 0 AFTER vy_km_s,
  ADD COLUMN ladunek SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER kurs_deg,
  ADD COLUMN akcje INT UNSIGNED NOT NULL DEFAULT 0 AFTER ladunek,
  ADD COLUMN ai TINYINT(1) NOT NULL DEFAULT 0 AFTER akcje;

ALTER TABLE bitwy_systemy
  ADD COLUMN auto TINYINT(1) NOT NULL DEFAULT 1 AFTER hz_max;

-- Pojedyncze trafienie myśliwca to ułamki promila sekcji — DECIMAL(5,2) zaokrąglałby je do zera przy zapisie.
ALTER TABLE bitwy_sekcje
  MODIFY integralnosc DOUBLE NOT NULL DEFAULT 100;

ALTER TABLE bitwy_rozkazy
  ADD COLUMN kanal VARCHAR(80) NOT NULL DEFAULT '' AFTER tik,
  ADD COLUMN czas_s DOUBLE NOT NULL DEFAULT 0 AFTER kanal,
  ADD COLUMN ranga ENUM('as','standard','kadet') NULL AFTER czas_s,
  ADD KEY ix_bitwy_rozkazy_kanal (bitwa_id, kanal);

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v070-bitwy-stan-silnika', 'Stan silnika bitwy: pozycje i kąty DOUBLE, ranga i ładunek klucza, tryb auto osłon, kanał rozkazu')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

-- Kolejność okrętów wyświetlana w edytorze składu (flagowy, Okręt 2, Okręt 3...).
-- Wymaga migracji 006_floty_i_nawigacja.sql. Uruchom raz.

SET NAMES utf8mb4;

ALTER TABLE mapa_floty_jednostki
  ADD COLUMN kolejnosc SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER rola,
  ADD KEY ix_mapa_floty_jednostki_kolejnosc (flota_id,kolejnosc);

INSERT IGNORE INTO schema_migracje (wersja,opis)
VALUES ('v7-kolejnosc-okretow','Kolejność jednostek w składzie floty i wybór okrętu flagowego');

-- Liczniki pokładowe: osłony i kadłub jednostek (ustawia MG; później walka) oraz ETA z planu skoku
-- (szacunek komputera w chwili rozkazu; resynchronizacja zmienia tylko rzeczywiste eta_utc,
-- więc odliczanie na pulpicie załogi nie zdradza ingerencji).
--
-- Wymaga migracji 018. Import w phpMyAdmin: zakładka SQL albo Import. Importuj raz;
-- ponowny import zatrzyma się na „Duplicate column”.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE mapa_naped_jednostki
  ADD COLUMN oslony TINYINT UNSIGNED NOT NULL DEFAULT 100 AFTER komputer_stan,
  ADD COLUMN kadlub TINYINT UNSIGNED NOT NULL DEFAULT 100 AFTER oslony;

ALTER TABLE mapa_rozkazy_skokow
  ADD COLUMN eta_plan_utc DATETIME NULL AFTER eta_utc;

UPDATE mapa_rozkazy_skokow SET eta_plan_utc = eta_utc WHERE eta_plan_utc IS NULL;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v19-oslony-kadlub-eta-plan', 'Osłony i kadłub jednostek, ETA z planu skoku dla liczników pokładowych')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

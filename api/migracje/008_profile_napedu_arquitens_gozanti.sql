-- Uzupełnia profile napędu jednostek eskortowych, jeśli ich jeszcze nie ma.
-- Importuj raz do działającej bazy po migracji 007.
-- Wartości startowe pochodzą z DEFAULT tabeli mapa_naped_jednostki;
-- istniejące profile MG pozostają bez zmian dzięki INSERT IGNORE.
INSERT IGNORE INTO mapa_naped_jednostki (obiekt_id)
SELECT o.id
FROM mapa_obiekty o
WHERE o.aktywny=1
  AND o.kod REGEXP '^havis-(arquitens|gozanti)-[0-9]{2}$';

INSERT IGNORE INTO schema_migracje (wersja,opis)
VALUES ('v8-profile-napedu-arquitens-gozanti','Profile napędu dla krążowników Arquitens i Gozanti');

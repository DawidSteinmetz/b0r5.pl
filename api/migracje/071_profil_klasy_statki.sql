-- Poprawka do 069: okręty na mapie mają typ 'statek' (np. Walako, kod isd-i-walako), a 069 przypisywała profil klasy
-- tylko wierszom 'jednostka' — żaden okręt nie dostał profilu, więc konta graczy nie widziały aplikacji Symulacje.
-- Import w phpMyAdmin; powtarzalny (zmienia tylko puste profil_klasy).

USE hololink;
SET NAMES utf8mb4;

UPDATE mapa_obiekty SET profil_klasy = 'ISD_I'
WHERE profil_klasy IS NULL AND typ IN ('statek', 'jednostka') AND (klasa = 'ISD I' OR klasa LIKE 'ISD I %' OR kod LIKE '%isd-i-%');
UPDATE mapa_obiekty SET profil_klasy = 'MC80'
WHERE profil_klasy IS NULL AND typ IN ('statek', 'jednostka') AND klasa LIKE 'MC80%';

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v071-profil-klasy-statki', 'Profil klasy także dla okrętów zapisanych jako statek (Walako i inne ISD I)')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

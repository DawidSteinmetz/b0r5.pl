-- B0R5: scalenie trzech firm o tej samej nazwie w jedną i frachtowce dla agentów.
--
-- W dokumencie `firmy` (datapad_dane) były trzy firmy B0R5:
--   b23ff14eabfb — z właścicielem (konto c28783b95807), bez budynków  → ZOSTAJE
--   473b397d017a, 3786a2f49d03 — bez członków, kopie „Bazy B0R5” na Zephyrii (saldo 0) → USUNIĘTE
-- Baza B0R5 trafia do firmy z właścicielem (z poprawnymi współrzędnymi zamiast „Array”), obok niej
-- ukryta platforma lądowania, a na niej trzy frachtowce z transponderami-przykrywkami.
-- Przed zmianą kopia dokumentu: datapad_dane.nazwa = 'firmy_kopia_przed_023'.
--
-- Wymaga migracji 022. Import w phpMyAdmin: zakładka SQL albo Import. Ponowny import niczego
-- nie dubluje (dopisanie tylko, gdy frachtowców jeszcze nie ma).

USE hololink;
SET NAMES utf8mb4;

-- kopia bezpieczeństwa
INSERT INTO datapad_dane (nazwa, payload)
SELECT 'firmy_kopia_przed_023', payload FROM datapad_dane WHERE nazwa = 'firmy'
ON DUPLICATE KEY UPDATE payload = payload;

-- ścieżka firmy z właścicielem, np. $.firmy[4]
SET @sciezka := JSON_UNQUOTE(JSON_SEARCH((SELECT payload FROM datapad_dane WHERE nazwa = 'firmy'), 'one', 'b23ff14eabfb', NULL, '$.firmy[*].id'));
SET @firma := SUBSTRING(@sciezka, 1, CHAR_LENGTH(@sciezka) - 3);

UPDATE datapad_dane SET payload = JSON_ARRAY_APPEND(payload,
  CONCAT(@firma, '.dane.budynki'), CAST('{"id": "4762df3763fa", "typ": "baza", "nazwa": "Baza B0R5", "opis": "Tajny kompleks operacyjny B0R5. Serwerownia, biuro, antena komunikacyjna, odbiornik Holonetu, garaż i magazyn.", "planeta": "73be99b5dcfe", "x": 12.4, "y": -6.3, "wspolrzedne": "", "powierzchnia": 800, "pietra": 1, "szyby": 1, "pojemnosc": 0, "pracownicy": 0, "pensja": 0, "menedzer": ""}' AS JSON),
  CONCAT(@firma, '.dane.budynki'), CAST('{"id": "b0r5ab000002", "typ": "platforma", "nazwa": "Platforma lądowania B0R5", "opis": "Ukryta platforma przy bazie B0R5, osłonięta skałami.", "planeta": "73be99b5dcfe", "x": 12.6, "y": -6.5, "wspolrzedne": "", "jakosc": 4, "paliwo": 100, "powierzchnia": 1200, "pietra": 0, "szyby": 0, "pojemnosc": 3, "pracownicy": 0, "pensja": 0, "menedzer": ""}' AS JSON),
  CONCAT(@firma, '.dane.flota'), CAST('{"id": "b0r5f0000001", "nazwa": "Wędrowny Kupiec", "klasa": "YT-2400", "transponder": "Wędrowny Kupiec", "stan": "sprawny", "hangar": "b:b23ff14eabfb:b0r5ab000002", "paliwo": 100, "kapitan": "", "ladownosc": 150, "hipernaped": 2, "lokalizacja": "73be99b5dcfe"}' AS JSON),
  CONCAT(@firma, '.dane.flota'), CAST('{"id": "b0r5f0000002", "nazwa": "Złota Plewa", "klasa": "Ghtroc 720", "transponder": "Złota Plewa", "stan": "sprawny", "hangar": "b:b23ff14eabfb:b0r5ab000002", "paliwo": 100, "kapitan": "", "ladownosc": 135, "hipernaped": 2, "lokalizacja": "73be99b5dcfe"}' AS JSON),
  CONCAT(@firma, '.dane.flota'), CAST('{"id": "b0r5f0000003", "nazwa": "Cicha Rzeka", "klasa": "Ghtroc 720", "transponder": "Cicha Rzeka", "stan": "sprawny", "hangar": "b:b23ff14eabfb:b0r5ab000002", "paliwo": 100, "kapitan": "", "ladownosc": 135, "hipernaped": 2, "lokalizacja": "73be99b5dcfe"}' AS JSON))
WHERE nazwa = 'firmy' AND @firma IS NOT NULL AND JSON_SEARCH(payload, 'one', 'b0r5f0000001') IS NULL;

-- usunięcie pustych kopii (każda ścieżka liczona na nowo, bo indeksy się przesuwają)
SET @sciezka := JSON_UNQUOTE(JSON_SEARCH((SELECT payload FROM datapad_dane WHERE nazwa = 'firmy'), 'one', '473b397d017a', NULL, '$.firmy[*].id'));
UPDATE datapad_dane SET payload = JSON_REMOVE(payload, SUBSTRING(@sciezka, 1, CHAR_LENGTH(@sciezka) - 3))
WHERE nazwa = 'firmy' AND @sciezka IS NOT NULL;

SET @sciezka := JSON_UNQUOTE(JSON_SEARCH((SELECT payload FROM datapad_dane WHERE nazwa = 'firmy'), 'one', '3786a2f49d03', NULL, '$.firmy[*].id'));
UPDATE datapad_dane SET payload = JSON_REMOVE(payload, SUBSTRING(@sciezka, 1, CHAR_LENGTH(@sciezka) - 3))
WHERE nazwa = 'firmy' AND @sciezka IS NOT NULL;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v23-b0r5-scalenie-frachtowce', 'B0R5: jedna firma, baza i platforma na Zephyrii, trzy frachtowce dla agentów')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

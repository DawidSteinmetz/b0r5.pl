-- Śledztwa droida detektywistycznego (narzedzia/sledztwa/PROJEKT.md): konto „detektyw” steruje droidem, każde
-- śledztwo jest unikatem, ale ma ten sam szkielet 12 faz. Typ początkowy = zdarzenie × relacja (12 × 12 = 144);
-- gracz zdobywa każdy typ najwyżej raz (wyczyn: komplet 144). Treść pisze model „Autor” przez API (prompty bazowe
-- w sledztwo_fazy_szablon), model „Droid” w grze przeszukuje ją i rozmawia z NPC. Miejsca fabularne są zapisane
-- w sledztwo_miejsca; wszystkie inne pokoje i poziomy generuje się z szumu (nie są w bazie, chyba że droid
-- je odwiedził — wtedy kopia trafia do sledztwo_szum, żeby przy powrocie wyglądały tak samo).
-- Konto detektywa to zwykły wpis w datapad_dane.uzytkownicy z "rola":"detektyw" (bez zmian w tabelach);
-- śledztwo wiąże się z polem "id" tego wpisu (konto_uid). Wymaga 030 (lokalizacje Nar Shaddaa). Import w phpMyAdmin; ponowny import niczego nie psuje.

-- ---------------------------------------------------------------------
-- 1. SŁOWNIKI (wspólne dla wszystkich; MG może edytować)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sledztwo_zdarzenia (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY CHECK (id BETWEEN 1 AND 12),
  nazwa VARCHAR(60) NOT NULL,
  opis TEXT NULL,
  wskazowki_autora TEXT NULL                      -- dopisek do promptu bazowego (ton, granice treści)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sledztwo_relacje (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY CHECK (id BETWEEN 1 AND 12),
  nazwa VARCHAR(60) NOT NULL,
  opis TEXT NULL,
  role_json TEXT NOT NULL                         -- np. [{"kod":"A","opis":"osoba, która znika"},{"kod":"B",...}]
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Szkielet 12 faz. Fazy z przestawna = 1 (4–10) generator może przestawić, o ile tagi się zgadzają.
CREATE TABLE IF NOT EXISTS sledztwo_fazy_szablon (
  nr TINYINT UNSIGNED NOT NULL PRIMARY KEY CHECK (nr BETWEEN 1 AND 12),
  nazwa VARCHAR(80) NOT NULL,
  przestawna TINYINT(1) NOT NULL DEFAULT 0,
  prompt_bazowy MEDIUMTEXT NULL,                  -- drobiazgowa instrukcja dla modelu Autor (patrz PROJEKT.md)
  warianty_json MEDIUMTEXT NULL                   -- 12 wariantów fazy z tagami wymaga/daje (losowanie k12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. INSTANCJA ŚLEDZTWA
-- ---------------------------------------------------------------------

-- Jeden wiersz na (konto, typ): UNIQUE pilnuje, że typ nie wylosuje się drugi raz. Porzucone idzie do MG;
-- po uzasadnieniu gracza i zgodzie MG wraca do stanu „trwa” (ten sam wiersz).
CREATE TABLE IF NOT EXISTS sledztwa (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  konto_uid VARCHAR(64) NOT NULL,                -- id z datapad_dane.uzytkownicy
  zdarzenie_id TINYINT UNSIGNED NOT NULL,
  relacja_id TINYINT UNSIGNED NOT NULL,
  seed INT UNSIGNED NOT NULL,
  stan ENUM('generowanie','trwa','porzucone','wznowienie_prosba','rozwiazane','blad') NOT NULL DEFAULT 'generowanie',
  kolejnosc_json VARCHAR(80) NOT NULL,            -- np. [1,2,3,6,4,5,7,9,8,10,11,12]
  tytul VARCHAR(160) NULL,
  zaczyn MEDIUMTEXT NULL,                         -- duży wstęp fabularno-pomocniczy na start
  skupisko_id BIGINT UNSIGNED NULL,               -- lokalizacje.id: kompleks, w którym toczy się sprawa
  rozwiazanie_gracza TEXT NULL,
  ocena TINYINT UNSIGNED NULL,                    -- 0–100, ile prawdy gracz odtworzył
  uzasadnienie_wznowienia TEXT NULL,
  decyzja_mg TEXT NULL,
  utworzono_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  zakonczono_utc DATETIME NULL,
  UNIQUE KEY uq_sledztwa_typ (konto_uid, zdarzenie_id, relacja_id),
  KEY ix_sledztwa_stan (stan),
  FOREIGN KEY (zdarzenie_id) REFERENCES sledztwo_zdarzenia (id),
  FOREIGN KEY (relacja_id) REFERENCES sledztwo_relacje (id),
  FOREIGN KEY (skupisko_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fazy 1–7: ukryta prawda; 8–12: przebieg śledztwa. Tekst jest zapisany gotowy (nie zależy od późniejszych zmian promptów).
CREATE TABLE IF NOT EXISTS sledztwo_fazy (
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  faza_nr TINYINT UNSIGNED NOT NULL,
  pozycja TINYINT UNSIGNED NOT NULL,              -- miejsce w kolejności tego śledztwa
  wariant TINYINT UNSIGNED NOT NULL,              -- wylosowany wariant 1–12
  tagi_json VARCHAR(500) NOT NULL DEFAULT '[]',
  tresc MEDIUMTEXT NOT NULL,
  PRIMARY KEY (sledztwo_id, faza_nr),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (faza_nr) REFERENCES sledztwo_fazy_szablon (nr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Miejsca fabularne: punkt w skupisku + poziom + pokój. Tylko te są „prawdziwe”; reszta to szum.
CREATE TABLE IF NOT EXISTS sledztwo_miejsca (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,            -- budynek z mapy (lądowisko, kantyna, kasyno…), jeśli jest
  poziom SMALLINT NOT NULL,
  pokoj VARCHAR(12) NOT NULL,
  nazwa VARCHAR(160) NOT NULL,
  rodzaj VARCHAR(40) NOT NULL,                    -- kantyna, nora_slicera, hangar, mieszkanie…
  opis MEDIUMTEXT NOT NULL,
  plan_json MEDIUMTEXT NULL,                      -- rzut dla skanu (pokoje, drzwi, terminale)
  zamkniete TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_sledztwo_miejsca (sledztwo_id, poziom, pokoj),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NPC. kluczowy: pełna historia, wiedza i sekrety, rozmowa przez AI. poboczny: szum, bez wywołań AI.
CREATE TABLE IF NOT EXISTS sledztwo_postacie (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  kod VARCHAR(8) NULL,                            -- rola z relacji: A, B, Z (zleceniodawca), S (śledzący)…
  waga ENUM('kluczowy','poboczny') NOT NULL DEFAULT 'kluczowy',
  imie VARCHAR(120) NOT NULL,
  gatunek VARCHAR(60) NOT NULL,
  wyglad TEXT NULL,
  historia MEDIUMTEXT NULL,                       -- bardzo szczegółowa biografia (kluczowi)
  wiedza_json MEDIUMTEXT NULL,                    -- [{"fakt":..., "ujawnia_gdy":..., "klamie":0/1}]
  sposob_mowienia TEXT NULL,
  miejsce_id BIGINT UNSIGNED NULL,                -- gdzie przebywa
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (miejsce_id) REFERENCES sledztwo_miejsca (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ślady: fakt + ukryte słowa kluczowe. Droid ich nie zna; serwer sprawdza, czy to, co droid wyłapał, trafia
-- w słowa kluczowe — dopiero wtedy ślad jest odkryty i trafia do koncentratu gracza.
CREATE TABLE IF NOT EXISTS sledztwo_slady (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  faza_nr TINYINT UNSIGNED NOT NULL,
  rodzaj ENUM('rozmowa','terminal','skan','przedmiot','zapis','obserwacja') NOT NULL,
  miejsce_id BIGINT UNSIGNED NULL,
  postac_id BIGINT UNSIGNED NULL,
  fakt TEXT NOT NULL,                             -- prawda, którą ślad ujawnia
  nosnik MEDIUMTEXT NULL,                         -- surowa treść do przeszukania (log, notatka, wpis)
  slowa_kluczowe_json VARCHAR(1000) NOT NULL,
  falszywy TINYINT(1) NOT NULL DEFAULT 0,         -- fałszywy trop (faza 8)
  odkryty_utc DATETIME NULL,
  KEY ix_sledztwo_slady (sledztwo_id, odkryty_utc),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (miejsce_id) REFERENCES sledztwo_miejsca (id) ON DELETE SET NULL,
  FOREIGN KEY (postac_id) REFERENCES sledztwo_postacie (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. DROID I PRZEBIEG
-- ---------------------------------------------------------------------

-- Pozycja liczona z czasu: droid idzie tempem chodu od (skad) do (cel); dotrze_utc wylicza serwer.
CREATE TABLE IF NOT EXISTS sledztwo_droid (
  sledztwo_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  lokalizacja_id BIGINT UNSIGNED NULL,
  poziom SMALLINT NOT NULL,
  pokoj VARCHAR(12) NOT NULL DEFAULT 'korytarz',
  cel_lokalizacja_id BIGINT UNSIGNED NULL,
  cel_poziom SMALLINT NULL,
  cel_pokoj VARCHAR(12) NULL,
  wyruszyl_utc DATETIME NULL,
  dotrze_utc DATETIME NULL,
  stan ENUM('czeka','w_drodze','rozmowa','skan','dekodowanie','uszkodzony') NOT NULL DEFAULT 'czeka',
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (lokalizacja_id) REFERENCES lokalizacje (id),
  FOREIGN KEY (cel_lokalizacja_id) REFERENCES lokalizacje (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Miejsca z szumu, które droid już odwiedził (żeby przy powrocie wyglądały tak samo).
CREATE TABLE IF NOT EXISTS sledztwo_szum (
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  lokalizacja_id BIGINT UNSIGNED NOT NULL,
  poziom SMALLINT NOT NULL,
  pokoj VARCHAR(12) NOT NULL,
  opis_json MEDIUMTEXT NOT NULL,
  PRIMARY KEY (sledztwo_id, lokalizacja_id, poziom, pokoj),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dziennik: meldunki droida, rozmowy AI–AI, polecenia gracza („spytaj go o X”), koncentrat.
CREATE TABLE IF NOT EXISTS sledztwo_dziennik (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  kiedy_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rodzaj ENUM('meldunek','rozmowa','polecenie','koncentrat','system') NOT NULL,
  kto VARCHAR(16) NOT NULL,                       -- droid, gracz, npc:<id>, system
  tresc MEDIUMTEXT NOT NULL,
  slad_id BIGINT UNSIGNED NULL,
  KEY ix_sledztwo_dziennik (sledztwo_id, id),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (slad_id) REFERENCES sledztwo_slady (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. DANE STARTOWE (do akceptacji — robocze listy)
-- ---------------------------------------------------------------------

INSERT INTO sledztwo_zdarzenia (id, nazwa) VALUES
(1,'Rozstanie'),(2,'Morderstwo'),(3,'Porwanie'),(4,'Gwałt'),(5,'Kradzież'),(6,'Szantaż'),
(7,'Zdrada / donos'),(8,'Oszustwo'),(9,'Nieudany przemyt'),(10,'Dług'),(11,'Zemsta'),(12,'Zaginięcie bez śladu')
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO sledztwo_relacje (id, nazwa, role_json) VALUES
(1,'Para','[{"kod":"A","opis":"partner, który znika"},{"kod":"B","opis":"partner, który zostaje"}]'),
(2,'Wspólnicy','[{"kod":"A","opis":"wspólnik"},{"kod":"B","opis":"wspólnik"}]'),
(3,'W firmie','[{"kod":"A","opis":"pracownik"},{"kod":"B","opis":"przełożony"}]'),
(4,'Szpiedzy','[{"kod":"A","opis":"agent"},{"kod":"B","opis":"oficer prowadzący"}]'),
(5,'Rodzic i dziecko','[{"kod":"A","opis":"dziecko"},{"kod":"B","opis":"rodzic"}]'),
(6,'Rodzeństwo','[{"kod":"A","opis":"rodzeństwo"},{"kod":"B","opis":"rodzeństwo"}]'),
(7,'Mistrz i uczeń','[{"kod":"A","opis":"uczeń"},{"kod":"B","opis":"mistrz"}]'),
(8,'Załoga statku','[{"kod":"A","opis":"członek załogi"},{"kod":"B","opis":"kapitan"}]'),
(9,'Gang','[{"kod":"A","opis":"członek"},{"kod":"B","opis":"herszt"}]'),
(10,'Sąsiedzi','[{"kod":"A","opis":"sąsiad"},{"kod":"B","opis":"sąsiad"}]'),
(11,'Dawni towarzysze broni','[{"kod":"A","opis":"weteran"},{"kod":"B","opis":"weteran"}]'),
(12,'Klient i usługodawca','[{"kod":"A","opis":"usługodawca"},{"kod":"B","opis":"klient"}]')
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO sledztwo_fazy_szablon (nr, nazwa, przestawna) VALUES
(1,'Zawiązanie',0),(2,'Powód śledztwa',0),(3,'Przedsięwzięcie i jego wynik',0),
(4,'Presja z zewnątrz',1),(5,'Zasoby',1),(6,'Pościg',1),(7,'Kryjówka i motyw',1),
(8,'Fałszywy trop',1),(9,'Świadek',1),(10,'Przeszkoda',1),(11,'Wyścig',0),(12,'Finał',0)
ON DUPLICATE KEY UPDATE nazwa = VALUES(nazwa), przestawna = VALUES(przestawna);

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v082-sledztwa', 'Śledztwa droida: typy 12×12, fazy, miejsca, NPC, ślady, droid, dziennik')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

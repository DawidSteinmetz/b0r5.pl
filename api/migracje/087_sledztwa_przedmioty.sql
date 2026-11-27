-- Śledztwa: przedmioty do znalezienia (zabezpieczone datapady zaginionego, jego stanowisko pracy, kostki danych,
-- kwity, holozdjęcia), komputery i terminale, przeszukiwanie lokali, alarmy po dekodowaniu na publicznym terminalu
-- oraz więcej zdawkowych kwestii NPC (żeby nie powtarzali tego samego; domyślnie neutralni). Wymaga 082.
-- Import w phpMyAdmin; ponowny import niczego nie psuje.

USE hololink;
SET NAMES utf8mb4;

-- Przedmiot sprawy leży w konkretnym miejscu (budynek, poziom, lokal). Treść: JSON [{nazwa, tresc}] — pliki
-- na nośniku. zabezpieczenie 0–5 = liczba kości trudności przy łamaniu (Intelekt + Komputery droida).
-- Stanowiska i prywatne terminale z szumu powstają tu dopiero, gdy droid się do nich podłączy (lokalizacja w polach).
CREATE TABLE IF NOT EXISTS sledztwo_przedmioty (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  miejsce_id BIGINT UNSIGNED NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  poziom SMALLINT NOT NULL,
  pokoj VARCHAR(12) NOT NULL,
  wlasciciel_id BIGINT UNSIGNED NULL,
  rodzaj ENUM('datapad','stanowisko','terminal','kostka','dokument','osobisty') NOT NULL,
  nazwa VARCHAR(160) NOT NULL,
  opis VARCHAR(300) NOT NULL DEFAULT '',
  przenosny TINYINT(1) NOT NULL DEFAULT 1,
  ukryty TINYINT(1) NOT NULL DEFAULT 0,
  zabezpieczenie TINYINT UNSIGNED NOT NULL DEFAULT 0,
  zawartosc MEDIUMTEXT NULL,
  znaleziony_utc DATETIME NULL,
  w_ekwipunku TINYINT(1) NOT NULL DEFAULT 0,
  odblokowany TINYINT(1) NOT NULL DEFAULT 0,
  sposob_odblokowania VARCHAR(20) NULL,
  blokada_do DATETIME NULL,
  KEY ix_sledztwo_przedmioty (sledztwo_id, lokalizacja_id, poziom, pokoj),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (miejsce_id) REFERENCES sledztwo_miejsca (id) ON DELETE SET NULL,
  FOREIGN KEY (wlasciciel_id) REFERENCES sledztwo_postacie (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Przeszukania lokali (test Percepcji droida; ponownie w tym samym lokalu po 10 min).
CREATE TABLE IF NOT EXISTS sledztwo_przeszukania (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  poziom SMALLINT NOT NULL,
  pokoj VARCHAR(12) NOT NULL,
  udane TINYINT(1) NOT NULL,
  kiedy_utc DATETIME NOT NULL,
  KEY ix_sledztwo_przeszukania (sledztwo_id, poziom, pokoj, kiedy_utc),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alarmy: dekodowanie nośnika na publicznym terminalu zostawia ślad — właściciel danych wie, gdzie i kiedy.
-- Skutki (kto przyjdzie, kto zacznie szukać droida) — w kolejnych etapach.
CREATE TABLE IF NOT EXISTS sledztwo_alarmy (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  kiedy_utc DATETIME NOT NULL,
  rodzaj ENUM('terminal_publiczny') NOT NULL,
  przedmiot_id BIGINT UNSIGNED NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  poziom SMALLINT NOT NULL,
  pokoj VARCHAR(12) NOT NULL,
  opis VARCHAR(300) NOT NULL,
  KEY ix_sledztwo_alarmy (sledztwo_id, kiedy_utc),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (przedmiot_id) REFERENCES sledztwo_przedmioty (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Więcej zdawkowych kwestii. Domyślnie NPC są neutralni (obojetny) i zbywają droida na różne sposoby.
INSERT IGNORE INTO gen_kwestie_szumu (nastroj, tresc) VALUES
('obojetny','Proszę mi nie przeszkadzać.'),
('obojetny','Nie mam z tym nic wspólnego.'),
('obojetny','Nakaz jest? Jak nie chcesz mnie aresztować, to spadaj.'),
('obojetny','Pytaj kogoś, komu za to płacą.'),
('obojetny','Nic nie widziałem, nic nie słyszałem. A teraz idę.'),
('obojetny','Mam swoje sprawy, puszko.'),
('obojetny','Droid śledczy? Tutaj? No to powodzenia.'),
('obojetny','Nie znam nikogo takiego. W ogóle mało kogo znam.'),
('obojetny','Jestem tu tylko przejazdem.'),
('obojetny','Zapytaj w recepcji. Oni wszystko zapisują.'),
('obojetny','Ja tu tylko pracuję.'),
('obojetny','Wczoraj? Wczoraj spałem.'),
('obojetny','Nie rozmawiam z droidami. Nic osobistego.'),
('obojetny','Moja zmiana się kończy. To już nie moja sprawa.'),
('obojetny','Jeśli to w sprawie długu, to pomyliłeś adresy.'),
('obojetny','Szukasz kogoś? Tu wszyscy kogoś szukają.'),
('obojetny','Na tym poziomie nikt nic nie wie. Taka zasada.'),
('obojetny','Masz kredyty? Nie? To nie mamy o czym rozmawiać.'),
('obojetny','Powiedz swojemu operatorowi, żeby przyszedł osobiście.'),
('obojetny','Nie nagrywaj mnie, dobra?'),
('obojetny','Ile razy jeszcze ktoś mnie o to zapyta?'),
('obojetny','Mam ciekawsze rzeczy do roboty niż gadać z droidem.'),
('obojetny','Spytaj ochrony. Ja tylko przechodzę.'),
('obojetny','Nie wtrącam się w cudze interesy. Zdrowiej.'),
('obojetny','Hm. Nie.'),
('obojetny','To nie jest dobry moment.'),
('obojetny','Wróć z kimś, kto ma twarz.'),
('obojetny','Słyszałem tyle co wszyscy, czyli nic.'),
('uprzejmy','Przykro mi, naprawdę nie wiem.'),
('uprzejmy','Spróbuj w kantynie. Tam ludzie gadają.'),
('uprzejmy','Może ktoś z ochrony coś widział.'),
('uprzejmy','Chciałbym pomóc, ale nie mogę.'),
('uprzejmy','Nie pamiętam, przepraszam. Dużo się tu dzieje.'),
('wrogi','Odsuń się, zanim rozbiorę cię na części.'),
('wrogi','Jeszcze jedno pytanie i wylądujesz w szybie.'),
('wrogi','Spadaj, puszko. Ostatni raz mówię.'),
('wrogi','Nakaz pokaż albo znikaj.'),
('przestraszony','Nie mogę o tym mówić. Oni patrzą.'),
('przestraszony','Proszę, nie pytaj mnie. Mam rodzinę.'),
('przestraszony','Ja… ja nic nie widziałem. Przysięgam.'),
('przestraszony','Nie tutaj. Nie przy ludziach.');

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v087-sledztwa-przedmioty', 'Śledztwa: przedmioty do znalezienia, komputery i terminale, przeszukania, alarmy, nowe kwestie NPC')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

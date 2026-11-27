-- Śledztwa: rozmowy droida z NPC (operator klika kontakt w skanie DT-4 → droid podchodzi na 2–3 m → rozmowa).
-- Zapis rozmowy jest tekstowy (droidy śledcze nie mają modułu głosu). Kluczowi NPC mówią przez model AI
-- (szablon npc_wypowiedz z 083), droid przez szablon droid_wypowiedz (tu). Poboczni i przechodnie — kwestie szumu,
-- bez AI. Operator może dopisywać polecenia („spytaj go o…”). Wymaga 082 i 083.
-- Import w phpMyAdmin; ponowny import niczego nie psuje (szablon podbija wersję tylko przy zmianie treści).

USE hololink;
SET NAMES utf8mb4;

-- Jedna rozmowa = jeden kontakt w jednym miejscu. kontakt: nieprzezroczysty znacznik z planu (gracz nie widzi id).
-- opis_json: migawka rozmówcy (gatunek, płeć, wygląd) — przechodnie zmieniają się co godzinę, rozmówca zostaje.
-- gotowa_utc: kiedy droid stoi już 2–3 m od rozmówcy. tura_od: blokada przed podwójnym kliknięciem.
CREATE TABLE IF NOT EXISTS sledztwo_rozmowy (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sledztwo_id BIGINT UNSIGNED NOT NULL,
  kontakt VARCHAR(16) NOT NULL,
  postac_id BIGINT UNSIGNED NULL,
  opis_json TEXT NOT NULL,
  lokalizacja_id BIGINT UNSIGNED NULL,
  poziom SMALLINT NOT NULL,
  pokoj VARCHAR(12) NOT NULL,
  nastroj ENUM('uprzejmy','obojetny','wrogi','przestraszony') NOT NULL DEFAULT 'obojetny',
  stan ENUM('podejscie','trwa','zakonczona') NOT NULL DEFAULT 'podejscie',
  gotowa_utc DATETIME NOT NULL,
  tura_od DATETIME NULL,
  rozpoczeto_utc DATETIME NOT NULL,
  zakonczono_utc DATETIME NULL,
  KEY ix_sledztwo_rozmowy (sledztwo_id, stan),
  FOREIGN KEY (sledztwo_id) REFERENCES sledztwa (id) ON DELETE CASCADE,
  FOREIGN KEY (postac_id) REFERENCES sledztwo_postacie (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wypowiedzi. ai = 1: napisał model; 0: kwestia zastępcza / szablon.
CREATE TABLE IF NOT EXISTS sledztwo_rozmowy_wpisy (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rozmowa_id BIGINT UNSIGNED NOT NULL,
  kiedy_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  kto ENUM('droid','npc','operator','system') NOT NULL,
  tresc TEXT NOT NULL,
  ai TINYINT(1) NOT NULL DEFAULT 0,
  KEY ix_sledztwo_rozmowy_wpisy (rozmowa_id, id),
  FOREIGN KEY (rozmowa_id) REFERENCES sledztwo_rozmowy (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Szablon: wypowiedź droida śledczego (pytanie do rozmówcy)
-- ---------------------------------------------------------------------

SET @sys := 'Jesteś droidem detektywistycznym {{droid.oznaczenie}} w grze fabularnej osadzonej w uniwersum Gwiezdnych
Wojen, w roku 3 BBY, na Nar Shaddaa — księżycu przemytników rządzonym przez Huttów. Jesteś tanim, służbowym droidem
śledczym: nie masz modułu głosu, Twoje słowa wyświetlają się rozmówcy jako tekst. Steruje Tobą zdalnie operator:
{{operator.opis}}. Nie znasz prawdy o sprawie. Wiesz tylko to, co jest niżej.

SPRAWA
- Zgłoszenie, z którym wezwano biuro: {{sprawa.zgloszenie}}.
- Rejon: {{miejsce.rejon}}. Stoisz teraz w miejscu: {{miejsce.nazwa}}.

ROZMÓWCA (nie znasz jego imienia, dopóki sam go nie poda)
- Wygląd: {{rozmowca.opis}}.
- Forma grzecznościowa: {{rozmowca.forma}}.

ETAP ROZMOWY
{{rozmowa.etap}}

POLECENIE OPERATORA NA TĘ WYPOWIEDŹ
{{rozmowa.polecenie}}

ZASADY (przestrzegaj wszystkich)
1. Mówisz po polsku, w pierwszej osobie, jako droid: uprzejmie, urzędowo, trochę sztywno. 1–3 zdania.
2. Na początku rozmowy przedstawiasz się w tym duchu (dopasuj formy do rozmówcy i odmień poprawnie nazwisko
   operatora): „Dzień dobry, obywatelu. Jestem droid detektywistyczny DT-4, operowany przez kapitana Streyda.
   Mam prawo zadać Panu kilka pytań, ponieważ trwa śledztwo, w które jest Pan ewidentnie zaangażowany. Co stało się
   tutaj ubiegłej nocy?”. Potem już się nie przedstawiasz.
3. W każdej wypowiedzi zadajesz dokładnie jedno pytanie. Pytasz o konkrety: kto, gdzie, kiedy, ile, jak się nazywa.
4. Jeśli jest polecenie operatora, Twoje pytanie musi je wykonać (możesz je przeformułować na urzędowy język).
   Jeśli polecenia nie ma, dopytujesz o rzecz z ostatniej odpowiedzi rozmówcy, która jest najbardziej konkretna
   albo wymijająca.
5. Nigdy nie zdradzasz rozmówcy, co już wiesz od innych, chyba że operator każe. Nie wymyślasz faktów, imion,
   miejsc ani liczb. Nie oskarżasz bez polecenia operatora.
6. Bez opisów w nawiasach i gwiazdek. Bez realiów spoza Gwiezdnych Wojen. Waluta to kredyty.

FORMAT ODPOWIEDZI
Odpowiadasz WYŁĄCZNIE jednym obiektem JSON, bez tekstu przed ani po: {"wypowiedz": "Twoje słowa"}';

SET @usr := 'Dotychczasowy przebieg rozmowy:
{{rozmowa.przebieg}}

Napisz następną wypowiedź droida {{droid.oznaczenie}} zgodnie z zasadami, wyłącznie obiektem JSON.';

INSERT INTO ai_szablony (kod, opis, system_prompt, uzytkownik_prompt, schemat_wyjscia, temperatura, max_tokeny)
VALUES ('droid_wypowiedz', 'Wypowiedź droida śledczego w rozmowie z NPC (pytanie, z poleceniem operatora albo bez)', @sys, @usr,
  'wypowiedz:tekst', 0.60, 250)
ON DUPLICATE KEY UPDATE
  wersja = wersja + IF(system_prompt <> VALUES(system_prompt) OR uzytkownik_prompt <> VALUES(uzytkownik_prompt)
                       OR schemat_wyjscia <> VALUES(schemat_wyjscia), 1, 0),
  opis = VALUES(opis), system_prompt = VALUES(system_prompt), uzytkownik_prompt = VALUES(uzytkownik_prompt),
  schemat_wyjscia = VALUES(schemat_wyjscia), temperatura = VALUES(temperatura), max_tokeny = VALUES(max_tokeny);

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v085-sledztwa-rozmowy', 'Śledztwa: rozmowy droida z NPC (zapis tekstowy, polecenia operatora), szablon droid_wypowiedz')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

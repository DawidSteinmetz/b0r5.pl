-- Giełda — krok 00 łańcucha giełd planet: uzgodnienie giełdy z gospodarką i naprawa stanu po migracji z JSON
-- (stan bazy z 22.11.2026: giełda z JSON, gospodarka 048–060 z tabelą cen lokalnych gosp_rynek).
--
-- 1. Masa jednostki w tonach: zgrzewka cukru 10 kg = 0,01 t, worek soli 25 kg = 0,025 t (było 10 t i 25 t).
-- 2. Ceny notowań towarów powiązanych z gospodarką = cena lokalna gospodarki na planecie giełdy
--    (gosp_rynek.cena_kr_t, obieg oficjalny hurt) × masa jednostki; historia kursów przeliczona tym samym mnożnikiem
--    (kształt wykresu bez zmian). Dotyczy dziś Kuat (DUR, DOON, FEO, WEG, LOM, KAR, MEL, ZER, NEU, PRE, ELK, ZYW, LUX,
--    TIBP.KUA) i Bespin (TIBP); TIBW (tibanna wysokiej czystości) dostaje mnożnik TIBP. Ceny były 2–20× za niskie.
-- 3. Ceny odniesienia towarów powiązanych z gospodarką = gosp_towary.cena_bazowa_kr_t × masa jednostki (TIBW jak TIBP).
-- 4. Historia notowań z krótką historią (Kuat: tylko kurs z dnia uruchomienia): 374 dni wstecz od pierwszego kursu,
--    błądzenie losowe z powrotem do ceny bazowej (gielda.powrot 0,08; σ = 0,025 × zmienność dla towarów,
--    0,018 × zmienność dla spółek), ziarno z symbolu. Ten sam wzór stosują kolejne kroki łańcucha.
-- 5. Kategoria notowania ZYW: „żywność” (jak pozostałe kategorie wyświetlane).
--
-- Import w phpMyAdmin. Ponowny import niczego nie zmienia (mnożniki wychodzą 1, historia ma już ≥ 30 dni).

USE hololink;
SET NAMES utf8mb4;

START TRANSACTION;

-- 1. Masy jednostek
UPDATE `towary` SET `masa_t_na_jednostke` = 0.0100 WHERE `nazwa` = 'Cukier 99,7%' AND `masa_t_na_jednostke` = 10;
UPDATE `towary` SET `masa_t_na_jednostke` = 0.0250 WHERE `nazwa` = 'Sól 97%' AND `masa_t_na_jednostke` = 25;

-- 2. Notowania powiązane z gospodarką: stara i nowa cena
DROP TEMPORARY TABLE IF EXISTS `tmp_gielda_uzgodnienie`;
CREATE TEMPORARY TABLE `tmp_gielda_uzgodnienie` (
  `instrument_id` BIGINT UNSIGNED PRIMARY KEY, `symbol` VARCHAR(16), `stara` DECIMAL(18,4), `nowa` DECIMAL(18,4));
INSERT INTO `tmp_gielda_uzgodnienie` (`instrument_id`, `symbol`, `stara`, `nowa`)
SELECT i.id, i.symbol, i.cena_bazowa, ROUND(r.cena_kr_t * tw.masa_t_na_jednostke, 4)
FROM `instrumenty` i
JOIN `gielde` ge ON ge.id = i.gielda_id
JOIN `mapa_planety` p ON p.id = ge.planeta_id
JOIN `towary` tw ON tw.id = i.towar_id
JOIN `gosp_towary` gt ON gt.towar_id = i.towar_id
JOIN `gosp_rynek` r ON r.planeta = p.nazwa AND r.towar = gt.kod AND r.obieg = 'oficjalny_hurt'
WHERE i.rodzaj = 'towar' AND r.cena_kr_t > 0 AND i.cena_bazowa > 0;
-- TIBW: ten sam mnożnik co TIBP (ta sama tibanna z Bespin, wyższa czystość). Mnożnik przez zmienną — MySQL nie
-- pozwala czytać tabeli tymczasowej w tym samym zapytaniu, które do niej wstawia.
SET @gielda_k_tibp := (SELECT nowa / stara FROM `tmp_gielda_uzgodnienie` WHERE `symbol` = 'TIBP' AND stara > 0);
INSERT INTO `tmp_gielda_uzgodnienie` (`instrument_id`, `symbol`, `stara`, `nowa`)
SELECT w.id, w.symbol, w.cena_bazowa, ROUND(w.cena_bazowa * @gielda_k_tibp, 4)
FROM `instrumenty` w WHERE w.symbol = 'TIBW' AND @gielda_k_tibp IS NOT NULL;

UPDATE `kursy_dzienne` k JOIN `tmp_gielda_uzgodnienie` u ON u.instrument_id = k.instrument_id
SET k.kurs = ROUND(k.kurs * u.nowa / u.stara, 4) WHERE u.nowa <> u.stara;
UPDATE `instrumenty` i JOIN `tmp_gielda_uzgodnienie` u ON u.instrument_id = i.id
SET i.cena_bazowa = u.nowa WHERE u.nowa <> u.stara;

-- 3. Ceny odniesienia towarów (najpierw tibanna wysokiej czystości — mnożnik liczony ze starej ceny tibanny)
UPDATE `towary` w JOIN `towary` t ON t.nazwa = 'Gaz tibanna przemysłowy'
JOIN `gosp_towary` gt ON gt.towar_id = t.id
SET w.cena_bazowa = ROUND(w.cena_bazowa * gt.cena_bazowa_kr_t * t.masa_t_na_jednostke / t.cena_bazowa)
WHERE w.nazwa = 'Gaz tibanna wysokiej czystości' AND t.cena_bazowa > 0;
UPDATE `towary` tw JOIN `gosp_towary` gt ON gt.towar_id = tw.id
SET tw.cena_bazowa = ROUND(gt.cena_bazowa_kr_t * tw.masa_t_na_jednostke);

DROP TEMPORARY TABLE IF EXISTS `tmp_gielda_uzgodnienie`;

-- 4. Historia notowań z mniej niż 30 dniami kursów
INSERT IGNORE INTO `kursy_dzienne` (`instrument_id`, `data`, `kurs`)
WITH RECURSIVE h (id, sym, baza, sigma, n, d, k) AS (
  SELECT i.id, i.symbol, CAST(i.cena_bazowa AS DOUBLE),
         IF(i.rodzaj = 'spolka', 0.018, 0.025) * i.zmiennosc,
         0,
         COALESCE((SELECT MIN(x.data) FROM `kursy_dzienne` x WHERE x.instrument_id = i.id), CURDATE() + INTERVAL 1 DAY) - INTERVAL 374 DAY,
         CAST(i.cena_bazowa AS DOUBLE)
  FROM `instrumenty` i
  WHERE (SELECT COUNT(*) FROM `kursy_dzienne` x WHERE x.instrument_id = i.id) < 30
  UNION ALL
  SELECT id, sym, baza, sigma, n + 1, d + INTERVAL 1 DAY,
         GREATEST(baza * 0.2, k * EXP(sigma * SQRT(-2 * LN((CRC32(CONCAT(sym, '|a|', n)) % 1000000 + 1) / 1000001))
           * COS(2 * PI() * (CRC32(CONCAT(sym, '|b|', n)) % 1000000) / 1000000)) + 0.08 * (baza - k))
  FROM h WHERE n < 373)
SELECT id, d, ROUND(k, 2) FROM h;

-- 5. Kategoria ZYW
UPDATE `instrumenty` SET `kategoria` = 'żywność' WHERE `symbol` = 'ZYW' AND `kategoria` = 'zywnosc';

COMMIT;

INSERT INTO `schema_migracje` (`wersja`, `opis`) VALUES ('gielda-00-naprawa', 'Giełda uzgodniona z gospodarką (ceny z gosp_rynek), historia Kuat, masy cukru i soli')
ON DUPLICATE KEY UPDATE `opis` = VALUES(`opis`);

-- Kontrola 1: notowania z mniej niż 30 dniami historii (wynik pusty).
SELECT i.symbol, COUNT(k.data) AS dni FROM `instrumenty` i LEFT JOIN `kursy_dzienne` k ON k.instrument_id = i.id
GROUP BY i.id, i.symbol HAVING dni < 30;
-- Kontrola 2: notowania towarów, których cena odbiega od gosp_rynek × masa o ponad 1% (wynik pusty).
SELECT i.symbol, i.cena_bazowa, ROUND(r.cena_kr_t * tw.masa_t_na_jednostke, 2) AS z_gospodarki
FROM `instrumenty` i JOIN `gielde` ge ON ge.id = i.gielda_id JOIN `mapa_planety` p ON p.id = ge.planeta_id
JOIN `towary` tw ON tw.id = i.towar_id JOIN `gosp_towary` gt ON gt.towar_id = i.towar_id
JOIN `gosp_rynek` r ON r.planeta = p.nazwa AND r.towar = gt.kod AND r.obieg = 'oficjalny_hurt'
WHERE ABS(i.cena_bazowa - r.cena_kr_t * tw.masa_t_na_jednostke) > 0.01 * r.cena_kr_t * tw.masa_t_na_jednostke;

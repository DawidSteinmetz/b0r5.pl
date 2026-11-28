
-- ============================================================
-- HOLOLINK
-- CORELLIA
-- Okres: 3 BBY
-- Import hierarchicznych lokacji powierzchniowych
--
-- Planeta: mapa_planety.nazwa = 'Corellia' (ID pobierane z bazy)
-- Tabela docelowa: lokalizacje
--
-- Paczka danych bez zmian struktury. Powtórny import nie tworzy duplikatów.
-- W razie błędu przed COMMIT przerwij import i wykonaj ROLLBACK w tej samej sesji.
--
-- Zasady:
-- 1. Nie modyfikujemy struktury istniejącej bazy.
-- 2. Nie nadajemy ręcznie ID.
-- 3. Nie usuwamy istniejących rekordów.
-- 4. Ponowne wykonanie nie tworzy duplikatów.
-- 5. Hierarchia jest odtwarzana przez rodzic_id.
-- 6. układ_id pozostaje NULL.
-- 7. Współrzędne NULL oznaczają brak wiarygodnej
--    georeferencji, a nie brak lokalizacji.
--
-- Oznaczenia źródeł w opisach:
-- [CANON]     - kanon Star Wars
-- [LEGENDS]   - materiały Star Wars Legends
-- [INFERRED]  - lokalizacja lub podział rekonstruowany
-- [CUSTOM]    - element porządkowy HOLOLINK
-- [MIXED]     - nazwa lub obiekt oparty na źródłach
--              z uzupełnieniem struktury na potrzeby gry
-- ============================================================

USE hololink;
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- KONTROLA PLANETY
-- ------------------------------------------------------------

SET @corellia_id := (SELECT id FROM mapa_planety WHERE nazwa = 'Corellia' LIMIT 1);

SELECT COUNT(*) INTO @corellia_exists
FROM mapa_planety
WHERE id = @corellia_id;

-- Podgląd kontroli. Gdy Corellii nie ma w bazie, część zapisująca nie doda żadnych rekordów.
SELECT @corellia_id IS NOT NULL AS corellia_znaleziona, @corellia_id AS planeta_id;

-- ------------------------------------------------------------
-- TABELA ROBOCZA
-- ------------------------------------------------------------

DROP TEMPORARY TABLE IF EXISTS tmp_import_corellia_lokacje;

CREATE TEMPORARY TABLE tmp_import_corellia_lokacje (
    klucz VARCHAR(100) NOT NULL,
    rodzic_klucz VARCHAR(100) DEFAULT NULL,
    poziom TINYINT UNSIGNED NOT NULL,
    typ ENUM(
        'uklad',
        'orbita',
        'powierzchnia',
        'lokacja',
        'budynek',
        'ladowisko'
    ) NOT NULL,
    rodzaj ENUM(
        'hangar',
        'port',
        'hotel',
        'bankomat',
        'sklep',
        'kantyna',
        'biuro',
        'garaz',
        'baza',
        'magazyn',
        'platforma',
        'inne'
    ) DEFAULT NULL,
    nazwa VARCHAR(200) NOT NULL,
    x_km DECIMAL(10,6) DEFAULT NULL,
    y_km DECIMAL(10,6) DEFAULT NULL,
    publiczna TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM(
        'aktywna',
        'zamknieta',
        'zniszczona'
    ) NOT NULL DEFAULT 'aktywna',
    opis TEXT,
    PRIMARY KEY (klucz),
    KEY ix_tmp_rodzic (rodzic_klucz),
    KEY ix_tmp_poziom (poziom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- POZIOM 0: KORZEŃ MAPY
-- ------------------------------------------------------------

INSERT INTO tmp_import_corellia_lokacje
(klucz, rodzic_klucz, poziom, typ, rodzaj, nazwa,
 x_km, y_km, publiczna, status, opis)
VALUES
(
 'corellia_map',
 NULL,
 0,
 'powierzchnia',
 'inne',
 'Corellia — mapa powierzchni',
 NULL,
 NULL,
 0,
 'aktywna',
 '[CUSTOM] Główny korzeń hierarchii geograficznej planety Corellia. Okres gry: 3 BBY. Podział regionalny ma charakter roboczy i służy organizacji lokalizacji HOLOLINK.'
);

-- ------------------------------------------------------------
-- POZIOM 1: REGIONY
-- Podział regionalny jest rekonstrukcją na potrzeby gry.
-- Nie należy traktować go jako oficjalnej kartografii kanonu.
-- ------------------------------------------------------------

INSERT INTO tmp_import_corellia_lokacje
(klucz, rodzic_klucz, poziom, typ, rodzaj, nazwa,
 x_km, y_km, publiczna, status, opis)
VALUES
(
 'region_coronet',
 'corellia_map',
 1,
 'powierzchnia',
 'inne',
 'Region Coronet',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Obszar geograficzny obejmujący Coronet City, port kosmiczny oraz sąsiednie tereny przemysłowe i osadnicze.'
),
(
 'region_industrial',
 'corellia_map',
 1,
 'powierzchnia',
 'inne',
 'Centralny Pas Przemysłowy',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Roboczy region grupujący zakłady produkcyjne, stocznie, magazyny i infrastrukturę przemysłową Corellii.'
),
(
 'region_west',
 'corellia_map',
 1,
 'powierzchnia',
 'inne',
 'Zachodnie Wyżyny',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Roboczy obszar wyżynny. Szczegółowy przebieg granic nie jest potwierdzony.'
),
(
 'region_north',
 'corellia_map',
 1,
 'powierzchnia',
 'inne',
 'Północne Lasy',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Roboczy obszar leśny przeznaczony do grupowania terenów naturalnych.'
),
(
 'region_south',
 'corellia_map',
 1,
 'powierzchnia',
 'inne',
 'Południowe Wybrzeże',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Roboczy obszar wybrzeża i terenów nadmorskich.'
),
(
 'region_east',
 'corellia_map',
 1,
 'powierzchnia',
 'inne',
 'Wschodnie Równiny',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Roboczy obszar równinny. Podział ma charakter organizacyjny.'
);

-- ------------------------------------------------------------
-- POZIOM 2: MIASTA, OBSZARY I KOMPLEKSY
-- ------------------------------------------------------------

INSERT INTO tmp_import_corellia_lokacje
(klucz, rodzic_klucz, poziom, typ, rodzaj, nazwa,
 x_km, y_km, publiczna, status, opis)
VALUES

-- CORONET

(
 'coronet_city',
 'region_coronet',
 2,
 'lokacja',
 'inne',
 'Coronet City',
 NULL, NULL, 1, 'aktywna',
 '[CANON] Główne miasto Corellii. Rozbudowany ośrodek miejski, przemysłowy i transportowy. W okresie 3 BBY podlega imperialnej administracji i lokalnym służbom bezpieczeństwa.'
),
(
 'coronet_spaceport',
 'region_coronet',
 2,
 'ladowisko',
 'port',
 'Coronet Spaceport',
 NULL, NULL, 1, 'aktywna',
 '[CANON] Port kosmiczny Coronet. Rozległy kompleks obsługujący transport pasażerski, handlowy i wojskowy.'
),
(
 'santhe_shipyards',
 'region_industrial',
 2,
 'lokacja',
 'inne',
 'Santhe Shipyards',
 NULL, NULL, 0, 'aktywna',
 '[CANON] Stocznie Santhe na Corellii. Szczegóły organizacyjne i infrastrukturalne w tym imporcie są częściowo rekonstruowane dla okresu 3 BBY.'
),
(
 'cec_complex',
 'region_industrial',
 2,
 'lokacja',
 'inne',
 'Corellian Engineering Corporation — kompleks stoczniowy',
 NULL, NULL, 0, 'aktywna',
 '[MIXED] Kompleks związany z przemysłem stoczniowym Corellii. Szczegółowa lokalizacja i podział obiektów opierają się na materiałach Legends i rekonstrukcji.'
),
(
 'droid_facility',
 'region_coronet',
 2,
 'lokacja',
 'inne',
 'Zakład Demontażu Droidów Separatystów',
 NULL, NULL, 0, 'aktywna',
 '[CANON] Imperialny zakład demontażu droidów Separatystów przedstawiony w materiałach kanonicznych. Szczegółowy podział wewnętrzny jest rekonstrukcją.'
),
(
 'white_worms',
 'region_coronet',
 2,
 'lokacja',
 'inne',
 'White Worms’ Den',
 NULL, NULL, 0, 'aktywna',
 '[CANON] Kryjówka gangu White Worms w dawnym obiekcie infrastruktury wodnej na terenie Coronet.'
),
(
 'corsec_hq',
 'region_coronet',
 2,
 'lokacja',
 'inne',
 'CorSec Headquarters',
 NULL, NULL, 0, 'aktywna',
 '[LEGENDS] Główna siedziba Corellian Security Force. Obiekt znany z materiałów Legends.'
),
(
 'dictat_complex',
 'region_coronet',
 2,
 'lokacja',
 'inne',
 'Kompleks Diktata',
 NULL, NULL, 0, 'aktywna',
 '[LEGENDS] Kompleks administracyjny związany z władzami Corellii. Szczegółowy układ w 3 BBY wymaga traktowania jako rekonstrukcja.'
),
(
 'bela_vistal',
 'region_east',
 2,
 'lokacja',
 'inne',
 'Bela Vistal',
 NULL, NULL, 1, 'aktywna',
 '[LEGENDS] Miasto Corellii znane z materiałów Legends.'
),
(
 'doaba_guerfel',
 'region_east',
 2,
 'lokacja',
 'inne',
 'Doaba Guerfel',
 NULL, NULL, 1, 'aktywna',
 '[LEGENDS] Miasto Corellii znane z materiałów Legends.'
),
(
 'kor_vella',
 'region_south',
 2,
 'lokacja',
 'inne',
 'Kor Vella',
 NULL, NULL, 1, 'aktywna',
 '[LEGENDS] Miasto Corellii znane z materiałów Legends.'
),
(
 'tyrena',
 'region_south',
 2,
 'lokacja',
 'inne',
 'Tyrena',
 NULL, NULL, 1, 'aktywna',
 '[LEGENDS] Miasto Corellii znane z materiałów Legends.'
),
(
 'vreni_island',
 'region_south',
 2,
 'lokacja',
 'inne',
 'Vreni Island',
 NULL, NULL, 1, 'aktywna',
 '[LEGENDS] Wyspa i obszar osadniczy Corellii znany z materiałów Legends.'
),
(
 'agrilat_swamps',
 'region_west',
 2,
 'lokacja',
 'inne',
 'Bagna Agrilat',
 NULL, NULL, 1, 'aktywna',
 '[LEGENDS] Obszar bagienny Corellii znany z materiałów Legends.'
),
(
 'western_mountains',
 'region_west',
 2,
 'lokacja',
 'inne',
 'Pasmo Zachodnich Gór',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Robocza jednostka geograficzna dla terenów górskich. Nazwa i granice nie są potwierdzoną oficjalną jednostką kartograficzną.'
),
(
 'northwestern_forests',
 'region_north',
 2,
 'lokacja',
 'inne',
 'Lasy Północno-Zachodnie',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Roboczy obszar leśny.'
),
(
 'southern_sea',
 'region_south',
 2,
 'lokacja',
 'inne',
 'Wielkie Morze Południowe',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Robocza nazwa obszaru morskiego używana do porządkowania mapy HOLOLINK.'
),
(
 'central_plains',
 'region_east',
 2,
 'lokacja',
 'inne',
 'Równiny Centralne',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Roboczy obszar równinny.'
);

-- ------------------------------------------------------------
-- POZIOM 3: DZIELNICE I PODOBSZARY
-- ------------------------------------------------------------

INSERT INTO tmp_import_corellia_lokacje
(klucz, rodzic_klucz, poziom, typ, rodzaj, nazwa,
 x_km, y_km, publiczna, status, opis)
VALUES

-- CORONET CITY

(
 'old_town',
 'coronet_city',
 3,
 'lokacja',
 'inne',
 'Stare Miasto Coronet',
 NULL, NULL, 1, 'aktywna',
 '[MIXED] Historyczna część Coronet City. Nazwa i podział dzielnicy wykorzystywane do organizacji mapy.'
),
(
 'bottoms',
 'coronet_city',
 3,
 'lokacja',
 'inne',
 'Bottoms',
 NULL, NULL, 1, 'aktywna',
 '[MIXED] Niższa, uboższa część miejskiej zabudowy Coronet. Obszar związany z działalnością przestępczą i nieformalną gospodarką.'
),
(
 'financial_district',
 'coronet_city',
 3,
 'lokacja',
 'inne',
 'Dzielnica Finansowa Coronet',
 NULL, NULL, 1, 'aktywna',
 '[MIXED] Obszar instytucji finansowych, administracji gospodarczej i biur korporacyjnych.'
),
(
 'corsec_plaza',
 'coronet_city',
 3,
 'lokacja',
 'inne',
 'CorSec Plaza',
 NULL, NULL, 1, 'aktywna',
 '[MIXED] Plac i obszar miejski związany z obecnością CorSec.'
),
(
 'diadem_square',
 'coronet_city',
 3,
 'lokacja',
 'inne',
 'Diadem Square',
 NULL, NULL, 1, 'aktywna',
 '[MIXED] Plac miejski w Coronet.'
),
(
 'the_green',
 'coronet_city',
 3,
 'lokacja',
 'inne',
 'The Green',
 NULL, NULL, 1, 'aktywna',
 '[MIXED] Zielony obszar miejski Coronet.'
),
(
 'narro_sienar',
 'coronet_city',
 3,
 'lokacja',
 'inne',
 'Narro Sienar Boulevard',
 NULL, NULL, 1, 'aktywna',
 '[MIXED] Ulica lub aleja miejska Coronet.'
),
(
 'juni_station',
 'coronet_city',
 3,
 'lokacja',
 'inne',
 'Juni Street Station',
 NULL, NULL, 1, 'aktywna',
 '[MIXED] Węzeł transportu miejskiego.'
),

-- PORT KOSMICZNY

(
 'spaceport_terminal',
 'coronet_spaceport',
 3,
 'budynek',
 'biuro',
 'Terminal Coronet Spaceport',
 NULL, NULL, 1, 'aktywna',
 '[INFERRED] Terminal pasażerski i administracyjny portu. Nazwa porządkowa HOLOLINK.'
),
(
 'spaceport_control',
 'coronet_spaceport',
 3,
 'budynek',
 'biuro',
 'Wieża Kontroli Ruchu Coronet',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Obiekt kontroli ruchu orbitalnego i naziemnego portu.'
),
(
 'spaceport_customs',
 'coronet_spaceport',
 3,
 'budynek',
 'biuro',
 'Plac Odpraw Celnych Coronet',
 NULL, NULL, 1, 'aktywna',
 '[INFERRED] Obszar kontroli celnej i inspekcji transportu.'
),
(
 'spaceport_corsec',
 'coronet_spaceport',
 3,
 'budynek',
 'biuro',
 'Posterunek CorSec przy Porcie',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Lokalny posterunek służb bezpieczeństwa obsługujący port.'
),

-- STACJE SANTHE

(
 'santhe_assembly',
 'santhe_shipyards',
 3,
 'lokacja',
 'inne',
 'Nabrzeże Montażowe Santhe',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Obszar montażu jednostek i modułów stoczniowych.'
),
(
 'santhe_docks',
 'santhe_shipyards',
 3,
 'lokacja',
 'inne',
 'Doki Ciężkie Santhe',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Doki przeznaczone do obsługi dużych kadłubów i jednostek przemysłowych.'
),
(
 'santhe_warehouses',
 'santhe_shipyards',
 3,
 'lokacja',
 'inne',
 'Magazyny Komponentów Santhe',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Zaplecze materiałowe i magazynowe stoczni.'
),
(
 'santhe_admin',
 'santhe_shipyards',
 3,
 'budynek',
 'biuro',
 'Biuro Administracji Santhe',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Budynek administracyjny kompleksu stoczniowego.'
),
(
 'santhe_repair',
 'santhe_shipyards',
 3,
 'lokacja',
 'inne',
 'Zakłady Remontowe Santhe',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Zaplecze napraw i remontów jednostek kosmicznych.'
),

-- CEC

(
 'cec_island',
 'cec_complex',
 3,
 'lokacja',
 'inne',
 'Wyspa CEC',
 NULL, NULL, 0, 'aktywna',
 '[LEGENDS] Obszar związany z działalnością Corellian Engineering Corporation.'
),
(
 'cec_headquarters',
 'cec_island',
 3,
 'budynek',
 'biuro',
 'Siedziba Główna CEC',
 NULL, NULL, 0, 'aktywna',
 '[LEGENDS] Siedziba korporacyjna CEC. Szczegółowa lokalizacja należy do materiałów Legends.'
),

-- ZAKŁAD DROIDÓW

(
 'droid_loading',
 'droid_facility',
 3,
 'lokacja',
 'inne',
 'Plac Przeładunkowy Zakładu Droidów',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Strefa transportu i rozładunku wraków droidów.'
),
(
 'droid_melting',
 'droid_facility',
 3,
 'lokacja',
 'inne',
 'Cysterna Przetopu Zakładu Droidów',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Strefa przetopu i odzysku materiałów. Element układu zakładu rekonstruowany na potrzeby gry.'
),

-- WHITE WORMS

(
 'white_worms_waterworks',
 'white_worms',
 3,
 'lokacja',
 'inne',
 'Opuszczony Zakład Uzdatniania Wody',
 NULL, NULL, 0, 'aktywna',
 '[CANON] Dawny zakład infrastruktury wodnej wykorzystywany jako kryjówka White Worms.'
),
(
 'white_worms_cistern',
 'white_worms',
 3,
 'lokacja',
 'inne',
 'Centralna Cysterna White Worms',
 NULL, NULL, 0, 'aktywna',
 '[CANON] Centralna część dawnej infrastruktury wodnej, wykorzystywana przez gang.'
),
(
 'coronet_sewers',
 'white_worms',
 3,
 'lokacja',
 'inne',
 'Tunele Kanalizacyjne Coronet',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Robocza nazwa podziemnej infrastruktury kanalizacyjnej. Połączenia z innymi obiektami nie są potwierdzone.'
),
(
 'black_market',
 'bottoms',
 3,
 'lokacja',
 'inne',
 'Targowisko Czarnego Rynku Coronet',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Nieformalny obszar handlu w niższych dzielnicach miasta.'
),

-- CORSEC

(
 'corsec_academy',
 'corsec_hq',
 3,
 'budynek',
 'inne',
 'Akademia CorSec',
 NULL, NULL, 0, 'aktywna',
 '[LEGENDS] Placówka szkoleniowa CorSec znana z materiałów Legends.'
),
(
 'corsec_prison',
 'corsec_hq',
 3,
 'budynek',
 'inne',
 'Więzienie CorSec o Zaostrzonym Rygorze',
 NULL, NULL, 0, 'aktywna',
 '[LEGENDS] Obiekt penitencjarny związany z CorSec. Lokalizacja szczegółowa nieustalona.'
),

-- BELA VISTAL

(
 'bela_fortress',
 'bela_vistal',
 3,
 'budynek',
 'inne',
 'Twierdza Imperialna Bela Vistal',
 NULL, NULL, 0, 'aktywna',
 '[LEGENDS] Obiekt wojskowy przypisany do miasta w materiałach Legends. Status i obsada w 3 BBY wymagają dalszej weryfikacji.'
),
(
 'bela_fountain',
 'bela_vistal',
 3,
 'lokacja',
 'inne',
 'Fontanna Kryształowa Bela Vistal',
 NULL, NULL, 1, 'aktywna',
 '[LEGENDS] Punkt miejski znany z materiałów Legends.'
),

-- TYRENA

(
 'tyrena_bridge',
 'tyrena',
 3,
 'lokacja',
 'inne',
 'Most Tyreny',
 NULL, NULL, 1, 'aktywna',
 '[LEGENDS] Obiekt infrastrukturalny związany z miastem Tyrena.'
),

-- VRENI ISLAND

(
 'vreni_theatre',
 'vreni_island',
 3,
 'budynek',
 'inne',
 'Wielki Teatr Vreni Island',
 NULL, NULL, 1, 'aktywna',
 '[LEGENDS] Obiekt kulturalny znany z materiałów Legends.'
),

-- TERENY NATURALNE

(
 'afarathu_cave',
 'agrilat_swamps',
 3,
 'lokacja',
 'inne',
 'Jaskinia Afarathu',
 NULL, NULL, 0, 'aktywna',
 '[LEGENDS] Jaskinia na Corellii znana z materiałów Legends.'
),
(
 'patriots_cave',
 'western_mountains',
 3,
 'lokacja',
 'inne',
 'Jaskinia Patriotów Drall',
 NULL, NULL, 0, 'aktywna',
 '[LEGENDS] Lokalizacja związana z Drall i materiałami Legends.'
);

-- ------------------------------------------------------------
-- POZIOM 4: OBIEKTY SPECJALNE I INSTYTUCJONALNE
-- ------------------------------------------------------------

INSERT INTO tmp_import_corellia_lokacje
(klucz, rodzic_klucz, poziom, typ, rodzaj, nazwa,
 x_km, y_km, publiczna, status, opis)
VALUES
(
 'corsec_training',
 'corsec_academy',
 4,
 'budynek',
 'inne',
 'Ośrodek Szkolenia Taktycznego',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Wewnętrzny obiekt szkoleniowy akademii CorSec. Nazwa porządkowa HOLOLINK.'
),
(
 'corsec_old_base',
 'corsec_hq',
 4,
 'lokacja',
 'baza',
 'Baza CorSec — Opuszczona',
 NULL, NULL, 0, 'zamknieta',
 '[LEGENDS] Opuszczony obiekt CorSec. Lokalizacja szczegółowa nieustalona; przypisanie do siedziby ma charakter organizacyjny.'
),
(
 'corellia_stronghold',
 'western_mountains',
 4,
 'lokacja',
 'baza',
 'Corellia Stronghold',
 NULL, NULL, 0, 'aktywna',
 '[LEGENDS] Obiekt znany z materiałów Legends. Szczegółowa funkcja i status w 3 BBY wymagają weryfikacji.'
),
(
 'rebel_hidden_base',
 'western_mountains',
 4,
 'lokacja',
 'baza',
 'Ukryta Baza Rebeliantów',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Potencjalna placówka konspiracyjna. Lokalizacja i istnienie w 3 BBY są elementem rekonstrukcji fabularnej, nie potwierdzonym faktem kanonicznym.'
),
(
 'abandoned_bunker',
 'western_mountains',
 4,
 'lokacja',
 'baza',
 'Opuszczony Bunkier',
 NULL, NULL, 0, 'zamknieta',
 '[INFERRED] Roboczy obiekt terenowy do wykorzystania w rozgrywce.'
),
(
 'imperial_garrison',
 'coronet_city',
 4,
 'lokacja',
 'baza',
 'Imperialny Garnizon Coronet',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Robocza lokalizacja garnizonu sił imperialnych w Coronet. Nie jest wskazaniem konkretnego kanonicznego budynku.'
),
(
 'imperial_command',
 'imperial_garrison',
 4,
 'budynek',
 'biuro',
 'Imperial Central Command',
 NULL, NULL, 0, 'aktywna',
 '[INFERRED] Robocza nazwa stanowiska dowodzenia garnizonu.'
),
(
 'corellia_industry_museum',
 'coronet_city',
 4,
 'budynek',
 'inne',
 'Muzeum Przemysłu Corelliańskiego',
 NULL, NULL, 1, 'aktywna',
 '[INFERRED] Obiekt muzealny wprowadzony na potrzeby mapy HOLOLINK. Niepotwierdzony jako konkretna lokacja kanoniczna.'
),
(
 'corellia_ship_museum',
 'coronet_city',
 4,
 'budynek',
 'inne',
 'Muzeum Statków Kosmicznych Corellii',
 NULL, NULL, 1, 'aktywna',
 '[INFERRED] Obiekt muzealny wprowadzony na potrzeby mapy HOLOLINK. Niepotwierdzony jako konkretna lokacja kanoniczna.'
),
(
 'coronet_stock_exchange',
 'financial_district',
 4,
 'budynek',
 'biuro',
 'Giełda Papierów Wartościowych Corellii',
 NULL, NULL, 1, 'aktywna',
 '[LEGENDS] Instytucja finansowa przypisana do dzielnicy finansowej w rekonstrukcji mapy.'
);

-- ------------------------------------------------------------
-- IMPORT DO TABELI LOKALIZACJE
-- ------------------------------------------------------------

START TRANSACTION;

-- Poziom 0: korzeń mapy.
-- INSERT IGNORE zabezpiecza przed naruszeniem UNIQUE
-- uq_lokalizacje_planeta_nazwa.

INSERT INTO lokalizacje
(
 typ,
 rodzaj,
 rodzic_id,
 uklad_id,
 planeta_id,
 nazwa,
 x_km,
 y_km,
 publiczna,
 status,
 opis
)
SELECT
 t.typ,
 t.rodzaj,
 NULL,
 NULL,
 @corellia_id,
 t.nazwa,
 t.x_km,
 t.y_km,
 t.publiczna,
 t.status,
 t.opis
FROM tmp_import_corellia_lokacje t
WHERE t.poziom = 0
  AND @corellia_exists = 1
ON DUPLICATE KEY UPDATE id = id;

-- Poziomy 1–4.
-- Każdy poziom jest dodawany dopiero po utworzeniu rodzica.
-- Rodzic jest odnajdywany po nazwie i planeta_id.
-- Dzięki temu identyfikatory AUTO_INCREMENT pozostają
-- zarządzane przez bazę.

INSERT INTO lokalizacje
(
 typ,
 rodzaj,
 rodzic_id,
 uklad_id,
 planeta_id,
 nazwa,
 x_km,
 y_km,
 publiczna,
 status,
 opis
)
SELECT
 t.typ,
 t.rodzaj,
 p_lok.id,
 NULL,
 @corellia_id,
 t.nazwa,
 t.x_km,
 t.y_km,
 t.publiczna,
 t.status,
 t.opis
FROM tmp_import_corellia_lokacje t
JOIN tmp_import_corellia_lokacje p
  ON p.klucz = t.rodzic_klucz
JOIN lokalizacje p_lok
  ON p_lok.planeta_id = @corellia_id
 AND p_lok.nazwa = p.nazwa
WHERE t.poziom = 1
  AND @corellia_exists = 1
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO lokalizacje
(
 typ,
 rodzaj,
 rodzic_id,
 uklad_id,
 planeta_id,
 nazwa,
 x_km,
 y_km,
 publiczna,
 status,
 opis
)
SELECT
 t.typ,
 t.rodzaj,
 p_lok.id,
 NULL,
 @corellia_id,
 t.nazwa,
 t.x_km,
 t.y_km,
 t.publiczna,
 t.status,
 t.opis
FROM tmp_import_corellia_lokacje t
JOIN tmp_import_corellia_lokacje p
  ON p.klucz = t.rodzic_klucz
JOIN lokalizacje p_lok
  ON p_lok.planeta_id = @corellia_id
 AND p_lok.nazwa = p.nazwa
WHERE t.poziom = 2
  AND @corellia_exists = 1
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO lokalizacje
(
 typ,
 rodzaj,
 rodzic_id,
 uklad_id,
 planeta_id,
 nazwa,
 x_km,
 y_km,
 publiczna,
 status,
 opis
)
SELECT
 t.typ,
 t.rodzaj,
 p_lok.id,
 NULL,
 @corellia_id,
 t.nazwa,
 t.x_km,
 t.y_km,
 t.publiczna,
 t.status,
 t.opis
FROM tmp_import_corellia_lokacje t
JOIN tmp_import_corellia_lokacje p
  ON p.klucz = t.rodzic_klucz
JOIN lokalizacje p_lok
  ON p_lok.planeta_id = @corellia_id
 AND p_lok.nazwa = p.nazwa
WHERE t.poziom = 3
  AND @corellia_exists = 1
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO lokalizacje
(
 typ,
 rodzaj,
 rodzic_id,
 uklad_id,
 planeta_id,
 nazwa,
 x_km,
 y_km,
 publiczna,
 status,
 opis
)
SELECT
 t.typ,
 t.rodzaj,
 p_lok.id,
 NULL,
 @corellia_id,
 t.nazwa,
 t.x_km,
 t.y_km,
 t.publiczna,
 t.status,
 t.opis
FROM tmp_import_corellia_lokacje t
JOIN tmp_import_corellia_lokacje p
  ON p.klucz = t.rodzic_klucz
JOIN lokalizacje p_lok
  ON p_lok.planeta_id = @corellia_id
 AND p_lok.nazwa = p.nazwa
WHERE t.poziom = 4
  AND @corellia_exists = 1
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO schema_migracje (wersja, opis)
SELECT 'v34-lokacje-corellia', 'Lokacje: Corellia — hierarchia powierzchniowa'
WHERE @corellia_exists = 1
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

COMMIT;
-- ------------------------------------------------------------
-- WALIDACJA PO IMPORCIE
-- ------------------------------------------------------------

SELECT
 'Corellia' AS planeta,
 @corellia_id AS planeta_id,
 COUNT(*) AS liczba_lokalizacji
FROM lokalizacje
WHERE planeta_id = @corellia_id;

-- Kontrola rekordów bez rodzica poza korzeniem.
SELECT
 l.id,
 l.nazwa,
 l.typ,
 l.rodzic_id
FROM lokalizacje l
WHERE l.planeta_id = @corellia_id
  AND l.nazwa <> 'Corellia — mapa powierzchni'
  AND l.rodzic_id IS NULL;

-- Kontrola sierot: rodzic musi należeć do tej samej planety.
SELECT
 dziecko.id,
 dziecko.nazwa,
 dziecko.rodzic_id,
 rodzic.planeta_id AS planeta_rodzica
FROM lokalizacje dziecko
LEFT JOIN lokalizacje rodzic
  ON rodzic.id = dziecko.rodzic_id
WHERE dziecko.planeta_id = @corellia_id
  AND dziecko.rodzic_id IS NOT NULL
  AND (
    rodzic.id IS NULL
    OR rodzic.planeta_id <> @corellia_id
  );

-- Kontrola duplikatów nazw na planecie.
SELECT
 planeta_id,
 nazwa,
 COUNT(*) AS liczba
FROM lokalizacje
WHERE planeta_id = @corellia_id
GROUP BY planeta_id, nazwa
HAVING COUNT(*) > 1;

-- Zestawienie hierarchii i typów.
SELECT
 typ,
 rodzaj,
 status,
 publiczna,
 COUNT(*) AS liczba
FROM lokalizacje
WHERE planeta_id = @corellia_id
GROUP BY typ, rodzaj, status, publiczna
ORDER BY typ, rodzaj, status;

-- Lista zaimportowanych lokalizacji.
SELECT
 l.id,
 l.nazwa,
 l.typ,
 l.rodzaj,
 p.nazwa AS rodzic,
 l.x_km,
 l.y_km,
 l.publiczna,
 l.status,
 l.opis
FROM lokalizacje l
LEFT JOIN lokalizacje p
  ON p.id = l.rodzic_id
WHERE l.planeta_id = @corellia_id
ORDER BY l.id;

-- Sprzątanie tabeli roboczej.
DROP TEMPORARY TABLE IF EXISTS tmp_import_corellia_lokacje;

-- KONIEC IMPORTU CORELLII




-- Dane mechaniki walki: stałe, profile klas (ISD_I, MC80, ZBIEG), typy maszyn, czas wykonania rozkazów,
-- scenariusze bitew (Atak na Walako, Szkolenie: klucze rebelii nad Walako).
-- PLIK GENEROWANY: python narzedzia/mostek/dane_sql.py — nie edytuj, zmieniaj dane w narzedzia/mostek/.
-- Wymaga migracji 068. Powtarzalna: ponowny import aktualizuje dane.

USE hololink;
SET NAMES utf8mb4;

-- Stałe wspólne
INSERT INTO mechanika_stale (kod, wartosc, status, opis) VALUES
  ('tik_s', 1, 'ustalone', 'tik silnika = 1 s czasu gry (tylko wewnątrz silnika)'),
  ('cykl_tiki', 12, 'ustalone', 'cykl = 12 tików: limity zmian, rozliczanie czasu'),
  ('porcje', 100, 'ustalone', '1 porcja = 1% mocy rdzenia'),
  ('skala_zyl', 1e+17, 'ustalone', 'wartości laboratoryjne żył × 10^17'),
  ('hz_odniesienia', 450, 'robocze', 'punkt odniesienia poboru i zużycia (Hz/450)'),
  ('wykladnik_zuzycia', 1.58496, 'robocze', 'log2(3): ×3 zużycia na podwojenie Hz'),
  ('tau_pojemnosci_s', 0.2508, 'robocze', 'C = tau · N · P_zyly'),
  ('energia_zyly_lufy_j', 2.3333333333333334e+17, 'robocze', 'energia bolta na aktywną żyłę baterii'),
  ('maszyn_w_kluczu', 4, 'ustalone', 'klucz = 4 maszyny'),
  ('xp_akcja_symulacja', 0.5, 'ustalone', 'XP za akcję NPC w symulacji (× mnożnik rangi)'),
  ('ranga_przygotowanie_kadet_s', 1, 'robocze', 'kadet: +1 s przygotowania rozkazu'),
  ('ranga_przygotowanie_as_s', -1, 'robocze', 'as: −1 s przygotowania (min. 1 s)'),
  ('zmiany_cykl_standard', 2, 'ustalone', 'limit zmian figury na cykl: zwykły pilot'),
  ('zmiany_cykl_as', 4, 'ustalone', 'limit zmian figury na cykl: as'),
  ('dryf_k_standard', 0.25, 'ustalone', 'dryf Hz klucza: Hz = baza · (1 + k · moc)'),
  ('dryf_k_as', 0.333333, 'ustalone', 'dryf Hz klucza z asem'),
  ('kadlub_prog_1', 75, 'propozycja', 'próg integralności sekcji uszkadzający systemy'),
  ('kadlub_prog_2', 50, 'propozycja', 'próg integralności sekcji'),
  ('kadlub_prog_3', 25, 'propozycja', 'próg integralności sekcji'),
  ('kanal_turbolaser_v', 0.6, 'robocze', 'turbolaser: udział kanału V'),
  ('kanal_turbolaser_omega', 0.25, 'robocze', 'turbolaser: udział kanału Ω'),
  ('kanal_turbolaser_w', 0.15, 'robocze', 'turbolaser: udział kanału W'),
  ('kanal_wiazka_v', 0.2, 'robocze', 'wiązka ciągła: udział kanału V'),
  ('kanal_wiazka_omega', 0.6, 'robocze', 'wiązka ciągła: udział kanału Ω'),
  ('kanal_wiazka_w', 0.2, 'robocze', 'wiązka ciągła: udział kanału W'),
  ('kanal_seria_v', 0.15, 'robocze', 'ostrzał seryjny: udział kanału V'),
  ('kanal_seria_omega', 0.15, 'robocze', 'ostrzał seryjny: udział kanału Ω'),
  ('kanal_seria_w', 0.7, 'robocze', 'ostrzał seryjny: udział kanału W'),
  ('kanal_jon_v', 1, 'robocze', 'działo jonowe: udział kanału V'),
  ('kanal_jon_omega', 0, 'robocze', 'działo jonowe: udział kanału Ω'),
  ('kanal_jon_w', 0, 'robocze', 'działo jonowe: udział kanału W')
ON DUPLICATE KEY UPDATE wartosc = VALUES(wartosc), status = VALUES(status), opis = VALUES(opis);

-- Profile klas
INSERT INTO profile_klas (kod, nazwa, dlugosc_m, moc_reaktora_w, utrata_rdzenia, generatory_oslon, zyly_oslon, zapasowy_zyly, siatka_kolumny, siatka_wiersze, zyla_u0_v, zyla_r0_ohm, oslony_domyslnie_hz, po_utracie_generatora, ekran_zyl, baterie, lufy_na_baterie, zyly_baterii, ladowanie_tiki, przydzial_baterii, obrona_punktowa, mysliwce, silniki_glowne, silniki_manewrowe, obrot_deg_na_min, predkosc_km_s, status) VALUES
  ('ISD_I', 'Gwiezdny Niszczyciel typu Imperial I', 1600, 7.73e+24, 'utrata całej mocy rdzenia (zostają pomocnicze)', 2, 3600, NULL, 60, 60, 120, 10, 1800, 'odkryta cała burta', 'SIATKA 60×60 (3600 lampek) · ekran dynamiczny', 8, 8, 3600, 6, 0.3, 60, 72, 3, 4, 30, 0.5, 'ustalony'),
  ('MC80', 'Krążownik gwiezdny Mon Calamari MC80', 1200, 6e+24, '−⅓ mocy na rdzeń; pozostałe rdzenie przejmują odbiorniki', 6, 2187, NULL, 81, 27, 89, 10, 729, 'przekierowanie sektora: sąsiad z tej samej burty kryje utracony sektor o jeden stopień natywny niżej (Hz/3), +⅓ poboru, obciążenie z obu sektorów w jego C', 'SIATKA 27×27 (729 lampek, lampka = 3 żyły) · ekran dynamiczny', 6, 9, 2187, 6, 0.3, 27, 36, 3, 6, 40, 0.5, 'propozycja'),
  ('ZBIEG', '„Zbieg” — kurier piracki HLK-P1', 40, 1.2e+23, 'okręt martwy, zostaje zasobnik', 1, 1800, 450, NULL, NULL, 63.25, 10, 900, 'generator zapasowy do 450 Hz', NULL, 0, 0, 0, 6, 0, 0, 0, NULL, NULL, NULL, 1.2, 'roboczy')
ON DUPLICATE KEY UPDATE nazwa = VALUES(nazwa), dlugosc_m = VALUES(dlugosc_m), moc_reaktora_w = VALUES(moc_reaktora_w), utrata_rdzenia = VALUES(utrata_rdzenia), generatory_oslon = VALUES(generatory_oslon), zyly_oslon = VALUES(zyly_oslon), zapasowy_zyly = VALUES(zapasowy_zyly), siatka_kolumny = VALUES(siatka_kolumny), siatka_wiersze = VALUES(siatka_wiersze), zyla_u0_v = VALUES(zyla_u0_v), zyla_r0_ohm = VALUES(zyla_r0_ohm), oslony_domyslnie_hz = VALUES(oslony_domyslnie_hz), po_utracie_generatora = VALUES(po_utracie_generatora), ekran_zyl = VALUES(ekran_zyl), baterie = VALUES(baterie), lufy_na_baterie = VALUES(lufy_na_baterie), zyly_baterii = VALUES(zyly_baterii), ladowanie_tiki = VALUES(ladowanie_tiki), przydzial_baterii = VALUES(przydzial_baterii), obrona_punktowa = VALUES(obrona_punktowa), mysliwce = VALUES(mysliwce), silniki_glowne = VALUES(silniki_glowne), silniki_manewrowe = VALUES(silniki_manewrowe), obrot_deg_na_min = VALUES(obrot_deg_na_min), predkosc_km_s = VALUES(predkosc_km_s), status = VALUES(status);

DELETE FROM profile_klas_jednostki WHERE profil_kod IN ('ISD_I', 'MC80', 'ZBIEG');
DELETE FROM profile_klas_sektory WHERE profil_kod IN ('ISD_I', 'MC80', 'ZBIEG');
DELETE FROM profile_klas_kadlub WHERE profil_kod IN ('ISD_I', 'MC80', 'ZBIEG');
INSERT INTO profile_klas_jednostki (profil_kod, nr, nazwa, rola) VALUES
  ('ISD_I', 1, 'reaktor główny (jonizacji słonecznej I-a2b)', 'rdzen'),
  ('ISD_I', 2, 'główny generator mocy', 'dystrybucja'),
  ('ISD_I', 3, 'reaktor pomocniczy uzbrojenia', 'pomocniczy'),
  ('ISD_I', 4, 'przedni reaktor pomocniczy', 'pomocniczy'),
  ('ISD_I', 5, 'zapasowy reaktor silników', 'zapasowy'),
  ('MC80', 1, 'rdzeń lewy', 'rdzen'),
  ('MC80', 2, 'rdzeń środkowy', 'rdzen'),
  ('MC80', 3, 'rdzeń prawy', 'rdzen'),
  ('MC80', 4, 'reaktor pomocniczy uzbrojenia lewej burty', 'pomocniczy'),
  ('MC80', 5, 'reaktor pomocniczy uzbrojenia prawej burty', 'pomocniczy'),
  ('MC80', 6, 'zapasowy reaktor silników', 'zapasowy'),
  ('ZBIEG', 1, 'reaktor główny', 'rdzen'),
  ('ZBIEG', 2, 'zasobnik burstu 7×10²³ J', 'zasobnik');
INSERT INTO profile_klas_sektory (profil_kod, nr, nazwa) VALUES
  ('ISD_I', 1, 'górny lewej burty'),
  ('ISD_I', 2, 'dolny lewej burty'),
  ('ISD_I', 3, 'górny prawej burty'),
  ('ISD_I', 4, 'dolny prawej burty'),
  ('ISD_I', 5, 'dziobowy'),
  ('ISD_I', 6, 'hangaru'),
  ('ISD_I', 7, 'rufowy'),
  ('MC80', 1, 'dziobowy lewej burty'),
  ('MC80', 2, 'śródokręcia lewej burty'),
  ('MC80', 3, 'rufowy lewej burty'),
  ('MC80', 4, 'dziobowy prawej burty'),
  ('MC80', 5, 'śródokręcia prawej burty'),
  ('MC80', 6, 'rufowy prawej burty'),
  ('ZBIEG', 1, 'całość');
INSERT INTO profile_klas_kadlub (profil_kod, sekcja, nazwa, udzial) VALUES
  ('ISD_I', 'burta_l', 'burta lewa', 0.25),
  ('ISD_I', 'burta_p', 'burta prawa', 0.25),
  ('ISD_I', 'dziob', 'dziób', 0.15),
  ('ISD_I', 'rufa', 'rufa', 0.15),
  ('ISD_I', 'nadbudowka', 'nadbudówka i mostek', 0.1),
  ('ISD_I', 'hangar', 'hangar', 0.1),
  ('MC80', 'burta_l', 'burta lewa', 0.25),
  ('MC80', 'burta_p', 'burta prawa', 0.25),
  ('MC80', 'dziob', 'dziób', 0.2),
  ('MC80', 'rufa', 'rufa', 0.2),
  ('MC80', 'hangar', 'hangar', 0.1),
  ('ZBIEG', 'calosc', 'kadłub', 1);

-- Przypisanie profilu jednostkom na mapie (tylko puste)
UPDATE mapa_obiekty SET profil_klasy = 'ISD_I'
WHERE profil_klasy IS NULL AND typ IN ('statek', 'jednostka') AND (klasa = 'ISD I' OR klasa LIKE 'ISD I %' OR kod LIKE '%isd-i-%');
UPDATE mapa_obiekty SET profil_klasy = 'MC80'
WHERE profil_klasy IS NULL AND typ IN ('statek', 'jednostka') AND klasa LIKE 'MC80%';

-- Typy maszyn
INSERT INTO typy_maszyn (kod, nazwa, strona, rola, hz_bazowe, oslona_hz, predkosc_km_s, przekroj_m2, energia_strzalu_j, ladowanie_tiki, opis) VALUES
  ('TIE_LN', 'TIE/ln', 'Imperium', 'myśliwiec liniowy', 600, 0, 1.05, 40, 6e+17, 1, 'tani, liczny, bez osłon'),
  ('TIE_IN', 'TIE/in Interceptor', 'Imperium', 'myśliwiec przechwytujący', 900, 0, 1.25, 35, 8e+17, 1, 'najszybszy TIE, bez osłon'),
  ('TIE_SA', 'TIE/sa Bomber', 'Imperium', 'bombowiec', 1500, 0, 0.8, 45, 3e+18, 3, 'bomby przeciw okrętom'),
  ('LAMBDA', 'Lambda T-4a', 'Imperium', 'prom', 300, 600, 0.85, 120, 2e+17, 2, 'prom pokładowy, słabe uzbrojenie'),
  ('X_WING', 'T-65 X-wing', 'Rebelia', 'myśliwiec uniwersalny', 1200, 900, 1, 30, 1e+18, 1, 'osłony, torpedy protonowe'),
  ('Y_WING', 'BTL Y-wing', 'Rebelia', 'myśliwiec-bombowiec', 1600, 1200, 0.8, 25, 1.5e+18, 2, 'działko jonowe, torpedy'),
  ('A_WING', 'RZ-1 A-wing', 'Rebelia', 'myśliwiec przechwytujący', 800, 600, 1.3, 12, 6e+17, 1, 'najszybszy, mały przekrój'),
  ('B_WING', 'A/SF-01 B-wing', 'Rebelia', 'ciężki myśliwiec szturmowy', 2400, 1500, 0.85, 35, 3e+18, 3, 'przebija osłony 1800 Hz')
ON DUPLICATE KEY UPDATE nazwa = VALUES(nazwa), strona = VALUES(strona), rola = VALUES(rola), hz_bazowe = VALUES(hz_bazowe), oslona_hz = VALUES(oslona_hz), predkosc_km_s = VALUES(predkosc_km_s), przekroj_m2 = VALUES(przekroj_m2), energia_strzalu_j = VALUES(energia_strzalu_j), ladowanie_tiki = VALUES(ladowanie_tiki), opis = VALUES(opis);

-- Czas wykonania rozkazów
INSERT INTO rozkazy_profile (kod, nazwa, grupa, przygotowanie_s, dzialanie_s, odnowienie_s, theta_mn, theta_wlasna_mn, predkosc_mn, ogien_wylaczony, ranga_min, ranga_skraca, opis) VALUES
  ('prosto', 'prosto', 'kurs', 1, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 1, NULL),
  ('lagodnie_l', 'łagodnie w lewo', 'kurs', 1, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 1, NULL),
  ('lagodnie_p', 'łagodnie w prawo', 'kurs', 1, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 1, NULL),
  ('ostro_l', 'ostro w lewo', 'kurs', 2, NULL, 0, 1.1, NULL, NULL, 0, 'kadet', 1, NULL),
  ('ostro_p', 'ostro w prawo', 'kurs', 2, NULL, 0, 1.1, NULL, NULL, 0, 'kadet', 1, NULL),
  ('zygzak', 'zygzak', 'unik', 1, 12, 0, 1.3, NULL, NULL, 0, 'kadet', 1, NULL),
  ('beczka_l', 'beczka w lewo', 'unik', 2, 4, 4, 1.6, NULL, NULL, 0, 'kadet', 1, NULL),
  ('beczka_p', 'beczka w prawo', 'unik', 2, 4, 4, 1.6, NULL, NULL, 0, 'kadet', 1, NULL),
  ('burst', 'burst', 'unik', 1, 3, 12, 1.5, NULL, 2, 0, 'kadet', 1, NULL),
  ('spirala', 'spirala asa', 'as', 2, 6, 12, 2, NULL, NULL, 0, 'as', 1, NULL),
  ('skok_cieniowy', 'skok cieniowy', 'as', 3, 2, 24, 2.5, NULL, NULL, 0, 'as', 1, 'chwilowe zgubienie namiaru przez wroga'),
  ('petla', 'pętla (zawrót górą)', 'zawrot', 3, 6, 0, NULL, NULL, NULL, 1, 'kadet', 1, 'odwrócenie kursu'),
  ('split_s', 'split-S (zawrót dołem)', 'zawrot', 3, 6, 0, NULL, NULL, NULL, 1, 'kadet', 1, 'odwrócenie kursu'),
  ('rozejscie_l', 'rozejście klucza w lewo', 'szyk', 2, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 1, 'trafienia obszarowe w klucz ×0,5'),
  ('rozejscie_p', 'rozejście klucza w prawo', 'szyk', 2, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 1, 'trafienia obszarowe w klucz ×0,5'),
  ('zwarcie', 'zwarcie szyku', 'szyk', 2, NULL, 0, NULL, 0.8, NULL, 0, 'kadet', 1, 'ogień skupiony na jednym celu'),
  ('nurkowanie', 'atak z nurkowania', 'walka', 3, 4, 0, 1.2, 0.77, NULL, 0, 'kadet', 1, 'okno ognia; klucz bardziej odsłonięty'),
  ('przelot', 'przelot przez cel', 'walka', 2, 3, 0, NULL, 0.67, NULL, 0, 'kadet', 1, 'okno ognia; wystawienie na obronę punktową celu'),
  ('nozyce', 'nożyce (pojedynek)', 'walka', 2, 12, 0, NULL, NULL, NULL, 0, 'kadet', 1, 'celność obu stron zależy od rangi'),
  ('ogon', 'ogon (trzymaj się celu)', 'walka', 2, NULL, 0, NULL, 0.83, NULL, 0, 'kadet', 1, 'celniej po 3 s utrzymania'),
  ('eskorta', 'eskorta wskazanej jednostki', 'walka', 2, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 1, 'przejmuje część ostrzału chronionej jednostki'),
  ('patrol', 'patrol', 'rozkaz_klucza', 3, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 1, NULL),
  ('ochrona', 'ochrona okrętu', 'rozkaz_klucza', 3, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 1, NULL),
  ('atak', 'atak', 'rozkaz_klucza', 3, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 1, NULL),
  ('skan', 'skan z bliska', 'rozkaz_klucza', 3, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 1, NULL),
  ('powrot', 'powrót do hangaru', 'rozkaz_klucza', 3, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 1, NULL),
  ('start', 'start z hangaru', 'rozkaz_klucza', 1, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 1, 'tylko gdy klucz gotowy (lampa niebieska)'),
  ('przestrojenie_reczne', 'przestrojenie Hz ręcznie', 'okret', 12, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 0, NULL),
  ('przestrojenie_preset', 'przestrojenie Hz z presetu', 'okret', 3, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 0, NULL),
  ('przestrojenie_auto', 'przestrojenie Hz automatyczne', 'okret', 3, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 0, '2–4 s'),
  ('wzmocnij', 'wzmocnij', 'okret', 3, 12, 24, NULL, NULL, NULL, 0, 'kadet', 0, '+1 stopień wydajności'),
  ('synchronizuj', 'synchronizuj', 'okret', 12, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 0, NULL),
  ('rownaj_reaktor', 'równaj z reaktorem', 'okret', 12, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 0, NULL),
  ('wyrownaj', 'wyrównaj', 'okret', 12, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 0, 'bez kosztu mocy'),
  ('przelacz', 'przełącz zasilanie', 'okret', 2, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 0, 'przerwa zasilania 1–3 s'),
  ('salwa', 'salwa baterii', 'okret', 6, NULL, 0, NULL, NULL, NULL, 0, 'kadet', 0, 'ładowanie; bolt leci 5 km/s')
ON DUPLICATE KEY UPDATE nazwa = VALUES(nazwa), grupa = VALUES(grupa), przygotowanie_s = VALUES(przygotowanie_s), dzialanie_s = VALUES(dzialanie_s), odnowienie_s = VALUES(odnowienie_s), theta_mn = VALUES(theta_mn), theta_wlasna_mn = VALUES(theta_wlasna_mn), predkosc_mn = VALUES(predkosc_mn), ogien_wylaczony = VALUES(ogien_wylaczony), ranga_min = VALUES(ranga_min), ranga_skraca = VALUES(ranga_skraca), opis = VALUES(opis);

-- Scenariusze
-- Atak na Walako
INSERT INTO bitwy_scenariusze (kod, nazwa, odprawa, opis_mg, okno_s, pole_km, zwyciestwo, porazka, status) VALUES
  ('walako_atak', 'Atak na Walako', 'Walako (ISD I) pełni wachtę w układzie. Wywiad floty ostrzega przed ruchem sił rebelii w sektorze. Zadanie: utrzymać pozycję i okręt do przybycia posiłków, spodziewanych w ciągu godziny.', 'Wzorcowy scenariusz z profilu ISD (rozdz. „Scenariusz wzorcowy”). Od startu sensory Walako widzą zmarszczkę nadprzestrzeni ok. 100 km od okrętu (konsola Sensory: czas do wyjścia i krąg niepewności) — załoga ma ok. 2 minuty na alarm, start kluczy i dostrojenie osłon. MC80 wychodzi ze skoku po 120–140 s, klucze rebelii startują z niego od 145 s (taśmociąg 1 klucz/min), krążownik idzie na Walako 0,5 km/s (pasmo 40 km po ok. 4 min od wyjścia, 10 km po ok. 5 min). Profil MC80 jest propozycją (M1–M10).', 3600, 200, 'Imperium: Walako utrzymany do przybycia posiłków (kadłub co najmniej 25%) albo krążownik rebelii unieszkodliwiony lub wycofany.', 'Imperium: kadłub Walako poniżej 25% przed przybyciem posiłków.', 'roboczy')
ON DUPLICATE KEY UPDATE nazwa = VALUES(nazwa), odprawa = VALUES(odprawa), opis_mg = VALUES(opis_mg), okno_s = VALUES(okno_s), pole_km = VALUES(pole_km), zwyciestwo = VALUES(zwyciestwo), porazka = VALUES(porazka), status = VALUES(status);

DELETE FROM bitwy_scenariusze_wyzwalacze WHERE scenariusz_kod = 'walako_atak';
DELETE FROM bitwy_scenariusze_strony WHERE scenariusz_kod = 'walako_atak';

INSERT INTO bitwy_scenariusze_strony (scenariusz_kod, strona, kolor, dowodzenie, rola, asy_proc, kadeci_proc) VALUES
  ('walako_atak', 'Imperium', '#9fb4c8', 'gracze', 'obrona', 5, 35),
  ('walako_atak', 'Rebelia', '#ff6b55', 'calrissian', 'atak', 10, 30);

INSERT INTO bitwy_scenariusze_jednostki (scenariusz_kod, nr, strona, profil_kod, nazwa, z_mapy, x_km, y_km, kurs_deg, predkosc_km_s, kadlub, wejscie_s, wejscie_rozrzut_s) VALUES
  ('walako_atak', 1, 'Imperium', 'ISD_I', 'Walako', 1, 0, 0, 0, 0, 100, 0, 0),
  ('walako_atak', 2, 'Rebelia', 'MC80', 'krążownik rebelii', 0, 0, 100, 180, 0.5, 100, 120, 20);

INSERT INTO bitwy_scenariusze_klucze (scenariusz_kod, fala, strona, jednostka_nr, typ_maszyny, liczba_kluczy, start_s, start_rozrzut_s, x_km, y_km, rozkaz) VALUES
  ('walako_atak', 1, 'Imperium', 1, 'TIE_LN', 4, 0, 30, NULL, NULL, 'ochrona'),
  ('walako_atak', 2, 'Imperium', 1, 'TIE_LN', 6, 60, 60, NULL, NULL, 'ochrona'),
  ('walako_atak', 3, 'Imperium', 1, 'TIE_LN', 8, 150, 90, NULL, NULL, 'ochrona'),
  ('walako_atak', 1, 'Rebelia', 2, 'X_WING', 5, 145, 10, NULL, NULL, 'atak'),
  ('walako_atak', 1, 'Rebelia', 2, 'Y_WING', 2, 145, 10, NULL, NULL, 'atak'),
  ('walako_atak', 1, 'Rebelia', 2, 'A_WING', 1, 145, 10, NULL, NULL, 'atak'),
  ('walako_atak', 1, 'Rebelia', 2, 'B_WING', 1, 145, 10, NULL, NULL, 'atak');

INSERT INTO bitwy_scenariusze_wyzwalacze (scenariusz_kod, nr, czas_s, rozrzut_s, rodzaj, tresc) VALUES
  ('walako_atak', 1, 0, 0, 'komunikat', 'Sensory dalekiego zasięgu: zmarszczka nadprzestrzeni ok. 100 km od Walako — duża masa.'),
  ('walako_atak', 4, 120, 20, 'komunikat', 'Wyjście z nadprzestrzeni 100 km od Walako: krążownik rebelii. Alarm bojowy.'),
  ('walako_atak', 2, 3600, 0, 'posilki', 'Posiłki Imperium wychodzą z nadprzestrzeni.'),
  ('walako_atak', 3, 3600, 0, 'koniec', 'Koniec symulacji: przybycie posiłków.');

-- Szkolenie: klucze rebelii nad Walako
INSERT INTO bitwy_scenariusze (kod, nazwa, odprawa, opis_mg, okno_s, pole_km, zwyciestwo, porazka, status) VALUES
  ('walako_klucze', 'Szkolenie: klucze rebelii nad Walako', 'Ćwiczenie obrony przed myśliwcami. Z nadprzestrzeni wychodzi grupa myśliwców rebelii bez okrętu macierzystego. Zadanie: zniszczyć napastników, zanim przebiją osłony.', 'Scenariusz bez MC80 — działa przed decyzjami M1–M10. Sprawdza filtr osłon na mieszance maszyn: X-wingi nie przebijają 1800 Hz, Y-wingi przebijają o 10%, B-wing o 40% (wymusza 3600 Hz).', 900, 120, 'Imperium: zestrzelone co najmniej 27 z 36 maszyn rebelii w 15 minut.', 'Imperium: kadłub Walako poniżej 90%.', 'roboczy')
ON DUPLICATE KEY UPDATE nazwa = VALUES(nazwa), odprawa = VALUES(odprawa), opis_mg = VALUES(opis_mg), okno_s = VALUES(okno_s), pole_km = VALUES(pole_km), zwyciestwo = VALUES(zwyciestwo), porazka = VALUES(porazka), status = VALUES(status);

DELETE FROM bitwy_scenariusze_wyzwalacze WHERE scenariusz_kod = 'walako_klucze';
DELETE FROM bitwy_scenariusze_strony WHERE scenariusz_kod = 'walako_klucze';

INSERT INTO bitwy_scenariusze_strony (scenariusz_kod, strona, kolor, dowodzenie, rola, asy_proc, kadeci_proc) VALUES
  ('walako_klucze', 'Imperium', '#9fb4c8', 'gracze', 'obrona', 5, 35),
  ('walako_klucze', 'Rebelia', '#ff6b55', 'calrissian', 'atak', 10, 30);

INSERT INTO bitwy_scenariusze_jednostki (scenariusz_kod, nr, strona, profil_kod, nazwa, z_mapy, x_km, y_km, kurs_deg, predkosc_km_s, kadlub, wejscie_s, wejscie_rozrzut_s) VALUES
  ('walako_klucze', 1, 'Imperium', 'ISD_I', 'Walako', 1, 0, 0, 0, 0, 100, 0, 0);

INSERT INTO bitwy_scenariusze_klucze (scenariusz_kod, fala, strona, jednostka_nr, typ_maszyny, liczba_kluczy, start_s, start_rozrzut_s, x_km, y_km, rozkaz) VALUES
  ('walako_klucze', 1, 'Imperium', 1, 'TIE_LN', 4, 0, 20, NULL, NULL, 'ochrona'),
  ('walako_klucze', 2, 'Imperium', 1, 'TIE_IN', 5, 60, 60, NULL, NULL, 'ochrona'),
  ('walako_klucze', 1, 'Rebelia', NULL, 'X_WING', 6, 0, 15, 0, 60, 'atak'),
  ('walako_klucze', 1, 'Rebelia', NULL, 'Y_WING', 2, 0, 15, 10, 60, 'atak'),
  ('walako_klucze', 1, 'Rebelia', NULL, 'B_WING', 1, 30, 30, -10, 60, 'atak');

INSERT INTO bitwy_scenariusze_wyzwalacze (scenariusz_kod, nr, czas_s, rozrzut_s, rodzaj, tresc) VALUES
  ('walako_klucze', 1, 0, 0, 'komunikat', 'Kontakty: 9 kluczy myśliwców, 60 km, kurs na Walako.'),
  ('walako_klucze', 2, 900, 0, 'koniec', 'Koniec ćwiczenia.');

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v069-mostek-dane', 'Dane mechaniki walki: stałe, profile klas, typy maszyn, rozkazy, scenariusze bitew')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

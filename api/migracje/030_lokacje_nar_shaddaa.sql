-- Lokacje Nar Shaddaa: aglomeracja miejska (umowny korzeń), sektory, dzielnice, kompleksy, budynki
-- i punkty wewnętrzne; trzy lądowiska (przemytnicze P-17 i serwisowe D-04 są niepubliczne — widzą je
-- tylko znający je piloci i MG). Opis zaczyna się od statusu lore: [LORE: KANON / LEGENDS / AUTORSKI].
-- Sześć lokacji portu już jest w bazie (port, terminal, kantyna, targ, hotel, biuro) — tu pominięte.
-- Wymaga migracji 026 (planeta Nar Shaddaa). Import w phpMyAdmin; ponowny import niczego nie psuje.

USE hololink;
SET NAMES utf8mb4;

SET @pl := (SELECT id FROM mapa_planety WHERE nazwa = 'Nar Shaddaa' LIMIT 1);

DROP TEMPORARY TABLE IF EXISTS tmp_import_lokacji;
CREATE TEMPORARY TABLE tmp_import_lokacji (
  poziom TINYINT UNSIGNED NOT NULL,
  nazwa VARCHAR(200) NOT NULL PRIMARY KEY,
  rodzic_nazwa VARCHAR(200) NULL,
  rodzic_id BIGINT UNSIGNED NULL,
  typ ENUM('uklad','orbita','powierzchnia','lokacja','budynek','ladowisko') NOT NULL,
  rodzaj ENUM('hangar','port','hotel','bankomat','sklep','kantyna','biuro','garaz','baza','magazyn','platforma','inne') NULL,
  x_km DECIMAL(12,3) NOT NULL,
  y_km DECIMAL(12,3) NOT NULL,
  publiczna TINYINT(1) NOT NULL,
  status ENUM('aktywna','zamknieta','zniszczona') NOT NULL,
  opis TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_import_lokacji (poziom, nazwa, rodzic_nazwa, typ, rodzaj, x_km, y_km, publiczna, status, opis) VALUES
(1,'Nar Shaddaa — aglomeracja miejska',NULL,'powierzchnia','inne',0.0,0.0,1,'aktywna','[LORE: AUTORSKI] Umowny korzeń kartograficzny HOLOLINK dla nowo dodawanej geografii miejskiej. Nie jest osobnym obiektem lore; obejmuje projektowane sektory i dzielnice. Wysokość zabudowy nie jest kodowana we współrzędnych.'),
(1,'Sektor Portowy','Port kosmiczny Nar Shaddaa','lokacja','inne',0.0,0.0,1,'aktywna','[LORE: AUTORSKI] Projektowa strefa transportu orbitalnego i przeładunku, powiązana przestrzennie z istniejącym w bazie Portem kosmicznym Nar Shaddaa.'),
(2,'Corellian Sector','Nar Shaddaa — aglomeracja miejska','lokacja','inne',-900.0,450.0,1,'aktywna','[LORE: KANON] Dzielnica o silnej społeczności corelliańskiej; w 3 BBY traktowana jako istniejący obszar miasta, choć dokładny zasięg i granice nie są źródłowo ustalone.'),
(2,'Hutta Town','Nar Shaddaa — aglomeracja miejska','lokacja','inne',-350.0,1250.0,1,'aktywna','[LORE: KANON] Nazwana miejska jednostka Nar Shaddaa; projektowo przyjęta jako reprezentacyjny obszar administracji i interesów Huttów. Szczegółowe granice są umowne.'),
(2,'Sektor Portowy — Doki Główne','Sektor Portowy','lokacja','inne',-4.0,3.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Sektor Portowy. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(2,'Sektor Portowy — Nabrzeże Serwisowe','Sektor Portowy','lokacja','inne',4.0,-3.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Sektor Portowy. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(2,'Sektor Handlowy','Nar Shaddaa — aglomeracja miejska','lokacja','inne',850.0,700.0,1,'aktywna','[LORE: AUTORSKI] Gęsta strefa handlu legalnego i nielegalnego, targowisk, kantorów oraz pośredników.'),
(2,'Sektor Rozrywkowy','Nar Shaddaa — aglomeracja miejska','lokacja','inne',1450.0,150.0,1,'aktywna','[LORE: AUTORSKI] Kasyna, hotele, lokale, areny i usługi dla zamożnych klientów oraz przestępczego półświatka.'),
(2,'Sektor Przemysłowy','Nar Shaddaa — aglomeracja miejska','lokacja','inne',850.0,-900.0,1,'aktywna','[LORE: AUTORSKI] Warsztaty, rafinerie, odzysk złomu, produkcja części i magazyny techniczne.'),
(2,'Sektor Dolnych Poziomów','Nar Shaddaa — aglomeracja miejska','lokacja','inne',-650.0,-1050.0,1,'aktywna','[LORE: AUTORSKI] Zdegradowane poziomy mieszkalne, slumsy, nieformalny handel i infrastruktura komunalna.'),
(2,'Sektor Przemytniczy','Nar Shaddaa — aglomeracja miejska','lokacja','inne',-1500.0,-250.0,1,'aktywna','[LORE: AUTORSKI] Kryjówki, składy kontrabandy, punkty kontaktowe i niejawne przeładunki.'),
(2,'Sektor Kontroli i Bezpieczeństwa','Nar Shaddaa — aglomeracja miejska','lokacja','inne',200.0,1700.0,1,'aktywna','[LORE: AUTORSKI] Rozproszone punkty ochrony portowej, administracji lokalnej i ograniczonej obecności imperialnej.'),
(3,'Corellian Sector — Górne Platformy','Corellian Sector','lokacja','inne',-904.0,453.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Corellian Sector. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(3,'Corellian Sector — Dolne Ulice','Corellian Sector','lokacja','inne',-896.0,447.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Corellian Sector. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(3,'Hutta Town — Plac Huttański','Hutta Town','lokacja','inne',-354.0,1253.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Hutta Town. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(3,'Hutta Town — Rezydencje Kajidiców','Hutta Town','lokacja','inne',-346.0,1247.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Hutta Town. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(3,'Kompleks Doku Głównego','Sektor Portowy — Doki Główne','lokacja','inne',-4.8,3.6,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Doki Główne; skupia obiekty usługowe i zaplecze operacyjne.'),
(3,'Kompleks Obsługi Statków','Sektor Portowy — Nabrzeże Serwisowe','lokacja','inne',4.8,-3.6,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Nabrzeże Serwisowe; skupia obiekty usługowe i zaplecze operacyjne.'),
(3,'Sektor Handlowy — Targowiska Centralne','Sektor Handlowy','lokacja','inne',846.0,703.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Sektor Handlowy. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(3,'Sektor Handlowy — Ulica Pośredników','Sektor Handlowy','lokacja','inne',854.0,697.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Sektor Handlowy. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(3,'Sektor Rozrywkowy — Aleja Kasyn','Sektor Rozrywkowy','lokacja','inne',1446.0,153.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Sektor Rozrywkowy. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(3,'Sektor Rozrywkowy — Kwartał Kantyn','Sektor Rozrywkowy','lokacja','inne',1454.0,147.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Sektor Rozrywkowy. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(3,'Sektor Przemysłowy — Strefa Rafineryjna','Sektor Przemysłowy','lokacja','inne',846.0,-897.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Sektor Przemysłowy. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(3,'Sektor Przemysłowy — Strefa Odzysku','Sektor Przemysłowy','lokacja','inne',854.0,-903.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Sektor Przemysłowy. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(3,'Sektor Dolnych Poziomów — Osiedle Szczelinowe','Sektor Dolnych Poziomów','lokacja','inne',-654.0,-1047.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Sektor Dolnych Poziomów. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(3,'Sektor Dolnych Poziomów — Kanały Techniczne','Sektor Dolnych Poziomów','lokacja','inne',-646.0,-1053.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Sektor Dolnych Poziomów. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(3,'Sektor Przemytniczy — Korytarz Kontrabandy','Sektor Przemytniczy','lokacja','inne',-1504.0,-247.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Sektor Przemytniczy. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(3,'Sektor Przemytniczy — Zaułki Łowców','Sektor Przemytniczy','lokacja','inne',-1496.0,-253.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Sektor Przemytniczy. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(3,'Sektor Kontroli i Bezpieczeństwa — Strefa Odpraw','Sektor Kontroli i Bezpieczeństwa','lokacja','inne',196.0,1703.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Sektor Kontroli i Bezpieczeństwa. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(3,'Sektor Kontroli i Bezpieczeństwa — Kwartał Obserwacyjny','Sektor Kontroli i Bezpieczeństwa','lokacja','inne',204.0,1697.0,1,'aktywna','[LORE: AUTORSKI] Dzielnica funkcjonalna sektora Sektor Kontroli i Bezpieczeństwa. Granice i nazwa są umownym podziałem kartograficznym HOLOLINK.'),
(4,'Kompleks Handlowy Corellian','Corellian Sector — Górne Platformy','lokacja','inne',-904.8,453.6,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Górne Platformy; skupia obiekty usługowe i zaplecze operacyjne.'),
(4,'Spacebarn Shug Ninx','Corellian Sector — Dolne Ulice','lokacja','inne',-895.2,446.4,0,'aktywna','[LORE: LEGENDS] Spacebarn, warsztat i zaplecze remontowe Shug Ninx, znane z wydarzeń 4 BBY. W 3 BBY przyjęto ciągłość działania; stan po bitwie wymaga traktowania jako założenie projektowe.'),
(4,'Kompleks Administracyjny Huttów','Hutta Town — Plac Huttański','lokacja','inne',-354.8,1253.6,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Plac Huttański; skupia obiekty usługowe i zaplecze operacyjne.'),
(4,'Kompleks Rezydencjonalny','Hutta Town — Rezydencje Kajidiców','lokacja','inne',-345.2,1246.4,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Rezydencje Kajidiców; skupia obiekty usługowe i zaplecze operacyjne.'),
(4,'Hangar Tranzytowy 01','Kompleks Doku Głównego','budynek','hangar',-4.92,3.68,1,'aktywna','[LORE: AUTORSKI] Hangar Tranzytowy 01. Obiekt operacyjny kompleksu Kompleks Doku Głównego; przeznaczenie zgodne z kategorią hangar.'),
(4,'Magazyn Przeładunkowy A','Kompleks Doku Głównego','budynek','magazyn',-4.68,3.52,1,'aktywna','[LORE: AUTORSKI] Magazyn Przeładunkowy A. Obiekt uzupełniający kompleksu Kompleks Doku Głównego; przeznaczenie zgodne z kategorią magazyn.'),
(4,'Warsztat Kadłubowy „Nitownia”','Kompleks Obsługi Statków','budynek','garaz',4.68,-3.52,1,'aktywna','[LORE: AUTORSKI] Warsztat Kadłubowy „Nitownia”. Obiekt operacyjny kompleksu Kompleks Obsługi Statków; przeznaczenie zgodne z kategorią garaz.'),
(4,'Stacja Paliw i Uzupełnień','Kompleks Obsługi Statków','budynek','inne',4.92,-3.68,1,'aktywna','[LORE: AUTORSKI] Stacja Paliw i Uzupełnień. Obiekt uzupełniający kompleksu Kompleks Obsługi Statków; przeznaczenie zgodne z kategorią inne.'),
(4,'Kompleks Wielkiego Targu','Sektor Handlowy — Targowiska Centralne','lokacja','inne',845.2,703.6,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Targowiska Centralne; skupia obiekty usługowe i zaplecze operacyjne.'),
(4,'Kompleks Kantorów','Sektor Handlowy — Ulica Pośredników','lokacja','inne',854.8,696.4,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Ulica Pośredników; skupia obiekty usługowe i zaplecze operacyjne.'),
(4,'Kompleks Kasynowy „Neonowy Łuk”','Sektor Rozrywkowy — Aleja Kasyn','lokacja','inne',1445.2,153.6,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Aleja Kasyn; skupia obiekty usługowe i zaplecze operacyjne.'),
(4,'Kompleks Rozrywkowy „Czerwony Szyld”','Sektor Rozrywkowy — Kwartał Kantyn','lokacja','inne',1454.8,146.4,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Kwartał Kantyn; skupia obiekty usługowe i zaplecze operacyjne.'),
(4,'Kompleks Przetwórstwa Przyprawy','Sektor Przemysłowy — Strefa Rafineryjna','lokacja','inne',845.2,-896.4,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Strefa Rafineryjna; skupia obiekty usługowe i zaplecze operacyjne.'),
(4,'Kompleks Recyklingu','Sektor Przemysłowy — Strefa Odzysku','lokacja','inne',854.8,-903.6,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Strefa Odzysku; skupia obiekty usługowe i zaplecze operacyjne.'),
(4,'Kompleks Mieszkalny „Szczelina”','Sektor Dolnych Poziomów — Osiedle Szczelinowe','lokacja','inne',-654.8,-1046.4,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Osiedle Szczelinowe; skupia obiekty usługowe i zaplecze operacyjne.'),
(4,'Kompleks Infrastruktury Podziemnej','Sektor Dolnych Poziomów — Kanały Techniczne','lokacja','inne',-645.2,-1053.6,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Kanały Techniczne; skupia obiekty usługowe i zaplecze operacyjne.'),
(4,'Kompleks Składów Cieni','Sektor Przemytniczy — Korytarz Kontrabandy','lokacja','inne',-1504.8,-246.4,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Korytarz Kontrabandy; skupia obiekty usługowe i zaplecze operacyjne.'),
(4,'Kompleks Pośredników Nagród','Sektor Przemytniczy — Zaułki Łowców','lokacja','inne',-1495.2,-253.6,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Zaułki Łowców; skupia obiekty usługowe i zaplecze operacyjne.'),
(4,'Kompleks Kontroli Portowej','Sektor Kontroli i Bezpieczeństwa — Strefa Odpraw','lokacja','inne',195.2,1703.6,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Strefa Odpraw; skupia obiekty usługowe i zaplecze operacyjne.'),
(4,'Kompleks Łącznikowy','Sektor Kontroli i Bezpieczeństwa — Kwartał Obserwacyjny','lokacja','inne',204.8,1696.4,0,'aktywna','[LORE: AUTORSKI] Kompleks funkcjonalny w dzielnicy Kwartał Obserwacyjny; skupia obiekty usługowe i zaplecze operacyjne.'),
(4,'Lądowisko Serwisowe D-04','Kompleks Obsługi Statków','ladowisko','platforma',4.92,-3.68,0,'aktywna','[LORE: AUTORSKI] Platforma techniczna dla holowników, barek i jednostek serwisowych.'),
(5,'Dom Maklerski Trójkąta Corelliańskiego','Kompleks Handlowy Corellian','budynek','biuro',-904.92,453.68,1,'aktywna','[LORE: AUTORSKI] Dom Maklerski Trójkąta Corelliańskiego. Obiekt operacyjny kompleksu Kompleks Handlowy Corellian; przeznaczenie zgodne z kategorią biuro.'),
(5,'Skład Części Corelliańskich','Kompleks Handlowy Corellian','budynek','magazyn',-904.68,453.52,1,'aktywna','[LORE: AUTORSKI] Skład Części Corelliańskich. Obiekt uzupełniający kompleksu Kompleks Handlowy Corellian; przeznaczenie zgodne z kategorią magazyn.'),
(5,'Warsztat Statków Spacebarn','Spacebarn Shug Ninx','budynek','garaz',-895.32,446.48,1,'aktywna','[LORE: AUTORSKI] Warsztat Statków Spacebarn. Obiekt operacyjny kompleksu Spacebarn Shug Ninx; przeznaczenie zgodne z kategorią garaz.'),
(5,'Hangar Spacebarn','Spacebarn Shug Ninx','budynek','hangar',-895.08,446.32,1,'aktywna','[LORE: AUTORSKI] Hangar Spacebarn. Obiekt uzupełniający kompleksu Spacebarn Shug Ninx; przeznaczenie zgodne z kategorią hangar.'),
(5,'Biuro Danin Huttańskich','Kompleks Administracyjny Huttów','budynek','biuro',-354.92,1253.68,1,'aktywna','[LORE: AUTORSKI] Biuro Danin Huttańskich. Obiekt operacyjny kompleksu Kompleks Administracyjny Huttów; przeznaczenie zgodne z kategorią biuro.'),
(5,'Dom Audiencji Kajidicu','Kompleks Administracyjny Huttów','budynek','biuro',-354.68,1253.52,1,'aktywna','[LORE: AUTORSKI] Dom Audiencji Kajidicu. Obiekt uzupełniający kompleksu Kompleks Administracyjny Huttów; przeznaczenie zgodne z kategorią biuro.'),
(5,'Rezydencja Klanu Besadii','Kompleks Rezydencjonalny','budynek','baza',-345.32,1246.48,1,'aktywna','[LORE: AUTORSKI] Rezydencja Klanu Besadii. Obiekt operacyjny kompleksu Kompleks Rezydencjonalny; przeznaczenie zgodne z kategorią baza.'),
(5,'Rezydencja Klanu Desilijic','Kompleks Rezydencjonalny','budynek','baza',-345.08,1246.32,1,'aktywna','[LORE: AUTORSKI] Rezydencja Klanu Desilijic. Obiekt uzupełniający kompleksu Kompleks Rezydencjonalny; przeznaczenie zgodne z kategorią baza.'),
(5,'Stanowisko Kontroli Ładunków','Hangar Tranzytowy 01','lokacja','inne',-4.92,3.68,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Hangar Tranzytowy 01; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(5,'Punkt Holowniczy','Stacja Paliw i Uzupełnień','lokacja','inne',4.68,-3.52,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Stacja Paliw i Uzupełnień; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(5,'Hala Towarowa „Siedem Bram”','Kompleks Wielkiego Targu','budynek','sklep',845.08,703.68,1,'aktywna','[LORE: AUTORSKI] Hala Towarowa „Siedem Bram”. Obiekt operacyjny kompleksu Kompleks Wielkiego Targu; przeznaczenie zgodne z kategorią sklep.'),
(5,'Giełda Towarów Huttańskich','Kompleks Wielkiego Targu','budynek','biuro',845.32,703.52,1,'aktywna','[LORE: AUTORSKI] Giełda Towarów Huttańskich. Obiekt uzupełniający kompleksu Kompleks Wielkiego Targu; przeznaczenie zgodne z kategorią biuro.'),
(5,'Kantor Kredytowy „Złoty Ząb”','Kompleks Kantorów','budynek','biuro',854.68,696.48,1,'aktywna','[LORE: AUTORSKI] Kantor Kredytowy „Złoty Ząb”. Obiekt operacyjny kompleksu Kompleks Kantorów; przeznaczenie zgodne z kategorią biuro.'),
(5,'Sklep Elektroniki Używanej','Kompleks Kantorów','budynek','sklep',854.92,696.32,1,'aktywna','[LORE: AUTORSKI] Sklep Elektroniki Używanej. Obiekt uzupełniający kompleksu Kompleks Kantorów; przeznaczenie zgodne z kategorią sklep.'),
(5,'Kasyno „Ostatnia Szansa”','Kompleks Kasynowy „Neonowy Łuk”','budynek','inne',1445.08,153.68,1,'aktywna','[LORE: AUTORSKI] Kasyno „Ostatnia Szansa”. Obiekt operacyjny kompleksu Kompleks Kasynowy „Neonowy Łuk”; przeznaczenie zgodne z kategorią inne.'),
(5,'Hotel „Nocny Księżyc”','Kompleks Kasynowy „Neonowy Łuk”','budynek','hotel',1445.32,153.52,1,'aktywna','[LORE: AUTORSKI] Hotel „Nocny Księżyc”. Obiekt uzupełniający kompleksu Kompleks Kasynowy „Neonowy Łuk”; przeznaczenie zgodne z kategorią hotel.'),
(5,'Kantyna „Pęknięty Blaster”','Kompleks Rozrywkowy „Czerwony Szyld”','budynek','kantyna',1454.68,146.48,1,'aktywna','[LORE: AUTORSKI] Kantyna „Pęknięty Blaster”. Obiekt operacyjny kompleksu Kompleks Rozrywkowy „Czerwony Szyld”; przeznaczenie zgodne z kategorią kantyna.'),
(5,'Klub Muzyczny „Głośny Hutt”','Kompleks Rozrywkowy „Czerwony Szyld”','budynek','inne',1454.92,146.32,1,'aktywna','[LORE: AUTORSKI] Klub Muzyczny „Głośny Hutt”. Obiekt uzupełniający kompleksu Kompleks Rozrywkowy „Czerwony Szyld”; przeznaczenie zgodne z kategorią inne.'),
(5,'Rafineria Przyprawy „S-9”','Kompleks Przetwórstwa Przyprawy','budynek','inne',845.08,-896.32,1,'aktywna','[LORE: AUTORSKI] Rafineria Przyprawy „S-9”. Obiekt operacyjny kompleksu Kompleks Przetwórstwa Przyprawy; przeznaczenie zgodne z kategorią inne.'),
(5,'Magazyn Substancji Kontrolowanych','Kompleks Przetwórstwa Przyprawy','budynek','magazyn',845.32,-896.48,1,'aktywna','[LORE: AUTORSKI] Magazyn Substancji Kontrolowanych. Obiekt uzupełniający kompleksu Kompleks Przetwórstwa Przyprawy; przeznaczenie zgodne z kategorią magazyn.'),
(5,'Zakład Odzysku Metali „Złom-7”','Kompleks Recyklingu','budynek','inne',854.68,-903.52,1,'aktywna','[LORE: AUTORSKI] Zakład Odzysku Metali „Złom-7”. Obiekt operacyjny kompleksu Kompleks Recyklingu; przeznaczenie zgodne z kategorią inne.'),
(5,'Warsztat Droidów „Drugi Obieg”','Kompleks Recyklingu','budynek','garaz',854.92,-903.68,1,'aktywna','[LORE: AUTORSKI] Warsztat Droidów „Drugi Obieg”. Obiekt uzupełniający kompleksu Kompleks Recyklingu; przeznaczenie zgodne z kategorią garaz.'),
(5,'Blok Mieszkalny 17-K','Kompleks Mieszkalny „Szczelina”','budynek','inne',-654.92,-1046.32,1,'aktywna','[LORE: AUTORSKI] Blok Mieszkalny 17-K. Obiekt operacyjny kompleksu Kompleks Mieszkalny „Szczelina”; przeznaczenie zgodne z kategorią inne.'),
(5,'Tania Gospoda „Pod Rurą”','Kompleks Mieszkalny „Szczelina”','budynek','hotel',-654.68,-1046.48,1,'aktywna','[LORE: AUTORSKI] Tania Gospoda „Pod Rurą”. Obiekt uzupełniający kompleksu Kompleks Mieszkalny „Szczelina”; przeznaczenie zgodne z kategorią hotel.'),
(5,'Przepompownia Dolna','Kompleks Infrastruktury Podziemnej','budynek','inne',-645.32,-1053.52,1,'aktywna','[LORE: AUTORSKI] Przepompownia Dolna. Obiekt operacyjny kompleksu Kompleks Infrastruktury Podziemnej; przeznaczenie zgodne z kategorią inne.'),
(5,'Warsztat Instalacji Miejskich','Kompleks Infrastruktury Podziemnej','budynek','garaz',-645.08,-1053.68,1,'aktywna','[LORE: AUTORSKI] Warsztat Instalacji Miejskich. Obiekt uzupełniający kompleksu Kompleks Infrastruktury Podziemnej; przeznaczenie zgodne z kategorią garaz.'),
(5,'Magazyn Kontrabandy „Cichy Ładunek”','Kompleks Składów Cieni','budynek','magazyn',-1504.92,-246.32,1,'aktywna','[LORE: AUTORSKI] Magazyn Kontrabandy „Cichy Ładunek”. Obiekt operacyjny kompleksu Kompleks Składów Cieni; przeznaczenie zgodne z kategorią magazyn.'),
(5,'Biuro Spedycji „Bez Pytań”','Kompleks Składów Cieni','budynek','biuro',-1504.68,-246.48,1,'aktywna','[LORE: AUTORSKI] Biuro Spedycji „Bez Pytań”. Obiekt uzupełniający kompleksu Kompleks Składów Cieni; przeznaczenie zgodne z kategorią biuro.'),
(5,'Kantyna „Zimny Trop”','Kompleks Pośredników Nagród','budynek','kantyna',-1495.32,-253.52,1,'aktywna','[LORE: AUTORSKI] Kantyna „Zimny Trop”. Obiekt operacyjny kompleksu Kompleks Pośredników Nagród; przeznaczenie zgodne z kategorią kantyna.'),
(5,'Biuro Zleceń Łowców','Kompleks Pośredników Nagród','budynek','biuro',-1495.08,-253.68,1,'aktywna','[LORE: AUTORSKI] Biuro Zleceń Łowców. Obiekt uzupełniający kompleksu Kompleks Pośredników Nagród; przeznaczenie zgodne z kategorią biuro.'),
(5,'Biuro Inspekcji Ładunków','Kompleks Kontroli Portowej','budynek','biuro',195.08,1703.68,1,'aktywna','[LORE: AUTORSKI] Biuro Inspekcji Ładunków. Obiekt operacyjny kompleksu Kompleks Kontroli Portowej; przeznaczenie zgodne z kategorią biuro.'),
(5,'Posterunek Ochrony Huttańskiej','Kompleks Kontroli Portowej','budynek','baza',195.32,1703.52,1,'aktywna','[LORE: AUTORSKI] Posterunek Ochrony Huttańskiej. Obiekt uzupełniający kompleksu Kompleks Kontroli Portowej; przeznaczenie zgodne z kategorią baza.'),
(5,'Biuro Łącznikowe Imperium','Kompleks Łącznikowy','budynek','biuro',204.68,1696.48,1,'aktywna','[LORE: AUTORSKI] Biuro Łącznikowe Imperium. Obiekt operacyjny kompleksu Kompleks Łącznikowy; przeznaczenie zgodne z kategorią biuro.'),
(5,'Kwatera Prywatnej Ochrony','Kompleks Łącznikowy','budynek','baza',204.92,1696.32,1,'aktywna','[LORE: AUTORSKI] Kwatera Prywatnej Ochrony. Obiekt uzupełniający kompleksu Kompleks Łącznikowy; przeznaczenie zgodne z kategorią baza.'),
(5,'Biuro Pełnomocnika Jabby','Kompleks Administracyjny Huttów','budynek','biuro',-354.92,1253.68,0,'aktywna','[LORE: AUTORSKI] Autorska placówka pośrednicząca w interesach klanu Desilijic i Jabby na Nar Shaddaa. Nie jest przedstawiana jako potwierdzona kanoniczna rezydencja Jabby.'),
(5,'Dom Aukcyjny „Czerwony Łańcuch”','Kompleks Wielkiego Targu','budynek','inne',845.08,703.68,0,'aktywna','[LORE: AUTORSKI] Autorski dom aukcyjny obsługujący handel luksusowy, kontrabandę i nielegalne aukcje niewolników pod ochroną lokalnych pośredników Huttów.'),
(5,'Lądowisko Przemytnicze P-17','Kompleks Składów Cieni','ladowisko','platforma',-1504.92,-246.32,0,'aktywna','[LORE: AUTORSKI] Ukryte stanowisko krótkiego postoju i przeładunku małych jednostek; projekt HOLOLINK.'),
(5,'Lądowisko Kontrolne K-01','Kompleks Kontroli Portowej','ladowisko','platforma',195.08,1703.68,1,'aktywna','[LORE: AUTORSKI] Stanowisko inspekcyjne dla jednostek wytypowanych do kontroli; nie oznacza pełnej kontroli Imperium nad księżycem.'),
(6,'Punkt Odprawy Frachtu','Dom Maklerski Trójkąta Corelliańskiego','lokacja','inne',-904.92,453.68,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Dom Maklerski Trójkąta Corelliańskiego; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(6,'Plac Prób Silników','Hangar Spacebarn','lokacja','inne',-895.32,446.48,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Hangar Spacebarn; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(6,'Sala Przyjęć','Biuro Danin Huttańskich','lokacja','inne',-354.92,1253.68,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Biuro Danin Huttańskich; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(6,'Prywatny Salon Huttański','Rezydencja Klanu Desilijic','lokacja','inne',-345.32,1246.48,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Rezydencja Klanu Desilijic; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(6,'Punkt Aukcyjny','Giełda Towarów Huttańskich','lokacja','inne',845.08,703.68,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Giełda Towarów Huttańskich; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(6,'Zaplecze Transakcji','Kantor Kredytowy „Złoty Ząb”','lokacja','inne',854.68,696.48,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Kantor Kredytowy „Złoty Ząb”; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(6,'Sala Gry Wysokich Stawek','Kasyno „Ostatnia Szansa”','lokacja','inne',1445.08,153.68,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Kasyno „Ostatnia Szansa”; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(6,'Prywatna Sala Rezerwacji','Klub Muzyczny „Głośny Hutt”','lokacja','inne',1454.68,146.48,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Klub Muzyczny „Głośny Hutt”; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(6,'Laboratorium Analizy Partii','Rafineria Przyprawy „S-9”','lokacja','inne',845.08,-896.32,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Rafineria Przyprawy „S-9”; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(6,'Plac Segregacji Złomu','Zakład Odzysku Metali „Złom-7”','lokacja','inne',854.68,-903.52,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Zakład Odzysku Metali „Złom-7”; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(6,'Punkt Wydawania Wody','Blok Mieszkalny 17-K','lokacja','inne',-654.92,-1046.32,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Blok Mieszkalny 17-K; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(6,'Węzeł Serwisowy','Przepompownia Dolna','lokacja','inne',-645.32,-1053.52,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Przepompownia Dolna; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(6,'Skrytka Przekazowa','Magazyn Kontrabandy „Cichy Ładunek”','lokacja','inne',-1504.92,-246.32,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Magazyn Kontrabandy „Cichy Ładunek”; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(6,'Tablica Kontraktów','Biuro Zleceń Łowców','lokacja','inne',-1495.32,-253.52,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Biuro Zleceń Łowców; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(6,'Stanowisko Skanowania','Biuro Inspekcji Ładunków','lokacja','inne',195.08,1703.68,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Biuro Inspekcji Ładunków; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.'),
(6,'Pokój Przesłuchań','Biuro Łącznikowe Imperium','lokacja','inne',204.68,1696.48,0,'aktywna','[LORE: AUTORSKI] Wewnętrzny punkt funkcjonalny w obiekcie Biuro Łącznikowe Imperium; lokalizacja operacyjna do wykorzystania w scenach i interakcjach HOLOLINK.');

-- Import poziom po poziomie: rodzic odnajdywany po nazwie na tej planecie. Istniejące rekordy
-- (ta sama planeta i nazwa) zostają bez zmian, więc ponowny import tylko dokłada brakujące.
START TRANSACTION;

UPDATE tmp_import_lokacji t JOIN lokalizacje r ON r.planeta_id = @pl AND r.nazwa = t.rodzic_nazwa
SET t.rodzic_id = r.id WHERE t.poziom = 1;
INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
SELECT typ, rodzaj, rodzic_id, NULL, @pl, nazwa, x_km, y_km, publiczna, status, opis FROM tmp_import_lokacji
WHERE poziom = 1 AND @pl IS NOT NULL AND (rodzic_nazwa IS NULL OR rodzic_id IS NOT NULL)
ON DUPLICATE KEY UPDATE id = id;

UPDATE tmp_import_lokacji t JOIN lokalizacje r ON r.planeta_id = @pl AND r.nazwa = t.rodzic_nazwa
SET t.rodzic_id = r.id WHERE t.poziom = 2;
INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
SELECT typ, rodzaj, rodzic_id, NULL, @pl, nazwa, x_km, y_km, publiczna, status, opis FROM tmp_import_lokacji
WHERE poziom = 2 AND @pl IS NOT NULL AND (rodzic_nazwa IS NULL OR rodzic_id IS NOT NULL)
ON DUPLICATE KEY UPDATE id = id;

UPDATE tmp_import_lokacji t JOIN lokalizacje r ON r.planeta_id = @pl AND r.nazwa = t.rodzic_nazwa
SET t.rodzic_id = r.id WHERE t.poziom = 3;
INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
SELECT typ, rodzaj, rodzic_id, NULL, @pl, nazwa, x_km, y_km, publiczna, status, opis FROM tmp_import_lokacji
WHERE poziom = 3 AND @pl IS NOT NULL AND (rodzic_nazwa IS NULL OR rodzic_id IS NOT NULL)
ON DUPLICATE KEY UPDATE id = id;

UPDATE tmp_import_lokacji t JOIN lokalizacje r ON r.planeta_id = @pl AND r.nazwa = t.rodzic_nazwa
SET t.rodzic_id = r.id WHERE t.poziom = 4;
INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
SELECT typ, rodzaj, rodzic_id, NULL, @pl, nazwa, x_km, y_km, publiczna, status, opis FROM tmp_import_lokacji
WHERE poziom = 4 AND @pl IS NOT NULL AND (rodzic_nazwa IS NULL OR rodzic_id IS NOT NULL)
ON DUPLICATE KEY UPDATE id = id;

UPDATE tmp_import_lokacji t JOIN lokalizacje r ON r.planeta_id = @pl AND r.nazwa = t.rodzic_nazwa
SET t.rodzic_id = r.id WHERE t.poziom = 5;
INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
SELECT typ, rodzaj, rodzic_id, NULL, @pl, nazwa, x_km, y_km, publiczna, status, opis FROM tmp_import_lokacji
WHERE poziom = 5 AND @pl IS NOT NULL AND (rodzic_nazwa IS NULL OR rodzic_id IS NOT NULL)
ON DUPLICATE KEY UPDATE id = id;

UPDATE tmp_import_lokacji t JOIN lokalizacje r ON r.planeta_id = @pl AND r.nazwa = t.rodzic_nazwa
SET t.rodzic_id = r.id WHERE t.poziom = 6;
INSERT INTO lokalizacje (typ, rodzaj, rodzic_id, uklad_id, planeta_id, nazwa, x_km, y_km, publiczna, status, opis)
SELECT typ, rodzaj, rodzic_id, NULL, @pl, nazwa, x_km, y_km, publiczna, status, opis FROM tmp_import_lokacji
WHERE poziom = 6 AND @pl IS NOT NULL AND (rodzic_nazwa IS NULL OR rodzic_id IS NOT NULL)
ON DUPLICATE KEY UPDATE id = id;

COMMIT;

-- Kontrola: lokacje z paczki w bazie, brakujące (np. brak planety albo rodzica) i osierocone.
SELECT COUNT(*) AS w_paczce,
  SUM(l.id IS NOT NULL) AS w_bazie,
  SUM(l.id IS NULL) AS brakujace,
  SUM(t.rodzic_nazwa IS NOT NULL AND l.id IS NOT NULL AND l.rodzic_id IS NULL) AS bez_rodzica
FROM tmp_import_lokacji t LEFT JOIN lokalizacje l ON l.planeta_id = @pl AND l.nazwa = t.nazwa;

DROP TEMPORARY TABLE IF EXISTS tmp_import_lokacji;

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v30-lokacje-nar-shaddaa', 'Lokacje: Nar Shaddaa (105)')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

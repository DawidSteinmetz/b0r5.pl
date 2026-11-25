# Profil: Gwiezdny Niszczyciel typu Imperial I (ISD I)

Dane liczbowe: [`profile_klas.py`](../profile_klas.py) → `PROFILE['ISD_I']`. Wzory: [MECHANIKA.md](../MECHANIKA.md).
Układy pulpitów: [`uklady/isd_i.py`](../uklady/isd_i.py). Okręt wzorcowy: Walako (ISD I).

## Energia

| Parametr | Wartość | Status |
|---|---|---|
| Moc rdzenia | 7,73×10²⁴ W | [U] |
| Porcja | 7,73×10²² W | [U] |
| Jednostki | reaktor główny (jonizacji słonecznej I-a2b), główny generator mocy, reaktor pomocniczy uzbrojenia, przedni reaktor pomocniczy, zapasowy reaktor silników | [U] |
| Utrata rdzenia | utrata całej mocy rdzenia (zostają pomocnicze) | [R] |

## Osłony

| Parametr | Wartość | Status |
|---|---|---|
| Generatory | 2 (burta lewa, prawa) | [U] |
| Żyły na generator | 3600, siatka 60×60 | [U] |
| Stopnie natywne | 45 dzielników 3600: 1, 2, 3, 4, 5, 6, 8, 9, 10, 12, 15, 16, 18, 20, 24, 25, 30, 36, 40, 45, 48, 50, 60, 72, 75, 80, 90, 100, 120, 144, 150, 180, 200, 225, 240, 300, 360, 400, 450, 600, 720, 900, 1200, 1800, 3600 | [U] |
| Moc żyły | U₀ 120 V, R₀ 10 Ω → 1440 W ×10¹⁷ = 1,44×10²⁰ W | [U] |
| Pełne osłony | 1,04×10²⁴ W = 13,4% reaktora | [U, sprawdzone] |
| Pojemność C | 1,3×10²³ J na generator (≈ 5 salw burtowych przy 3600 Hz) | [R] |
| Sektory na pulpicie | górny i dolny lewej burty, górny i dolny prawej burty, dziobowy, hangaru, rufowy | [U] |
| Po utracie generatora | odkryta cała burta | [U] |
| Domyślnie | 1800/1800 do czasu pomiaru wroga | [U] |
| Ekran | 60×60 lampek = blok 3×3 komórek, 1 lampka = 1 żyła | [U] |

## Uzbrojenie

| | Bateria ciężka | Obrona punktowa (PD) | Ciężka wieża jonowa | Działo jonowe NK-7 |
|---|---|---|---|---|
| Liczba | 8 baterii × 8 luf (4 lewe + 4 prawe) | 60 | 2 | 60 |
| Energia | 8,4×10²⁰ J / lufa, 6,7×10²¹ J / bateria | 10¹⁵ J | 4×10²⁰ J | 10¹⁸ J |
| Ładowanie | 6 tików (2 salwy / cykl) | 1 | 8 | 3 |
| Bolt | 30 m, 5 km/s | 3 m, 20 km/s | 25 m, 5 km/s | 8 m, 8 km/s |

[U] układ, [R] liczby. Baterie: 3600 żył, te same 45 stopni co osłony. Przydział 30% reaktora = 3,75 porcji na
baterię przy 3600 Hz.

## Myśliwce, ruch

- 72 myśliwce = 18 kluczy (9 na lewy, 9 na prawy pokład startowy). [U]
- Silniki: 2 główne boczne, 1 środkowy, 4 manewrowe (lewy/prawy × górny/dolny). Pulpit: ciąg, wychylenie dysz, trym
  pochylenia i odchylenia, synchronizacja z osłonami rufowymi, zasilanie z reaktora. [U]
- Obrót: `obrot_deg_na_min` = 30°/min (0,5°/s). Prędkość robocza 0,5 km/s. [R]
- Dodać na konsoli silników (audyt) [U]: trym przechylenia, wektor ruchu osobno od zwrotu dziobu, prędkość,
  maksymalne przeciążenie, temperatura, uszkodzenia dysz, pobór mocy.

## Pulpity

18 stanowisk w dwóch dołkach (9 + 9). Gotowe układy (siatka 13×9, w `uklady/isd_i.py`): Osłony, Turbolasery,
Silniki, Reaktory, Kontrola ognia, Sensory, Grawitacja, Myśliwce, Nawigacja, Radar, a na telefon (5×9):
Klucz (zbliżenie z lampki „Klucz N” na pulpicie Myśliwce) i Eskadra (3 klucze). SVG + gniazda (stara siatka 92×74, do
przeliczenia): Osłony, Turbolasery, Silniki, Reaktory.
Nawigacja i Radar mają ten sam środek (sylwetka ISD zawsze w centrum ekranu, w skali, z okręgami odległości),
ale inną funkcję i kompozycję:
- **Nawigacja** — sterowanie ruchem: ekran 7×7, okręgi 250 / 500 / 1000 km, wektor ruchu osobno od zwrotu dziobu;
  po bokach skręt i przesuw każdej burty, pod ekranem suwak ciągu głównego między „pełne hamowanie” i „cała naprzód”.
- **Radar** — obraz pola bitwy: ekran 5×5 w ramce przycisków (filtry kontaktów po bokach, sektory i skale nad i pod),
  okręgi w skali pasm walki 10 / 40 / 100 km, IFF, tory i prognozy torów; na zewnątrz listy zagrożeń każdej burty.
  Różnica wobec Sensorów: Sensory sterują skanem i mierzą Hz, Radar pokazuje i śledzi to, co wykryto.

Brakuje [U z audytu]: hipernapęd, Działa jonowe (osobno od turbolaserów — inna mechanika), Hangar
i lotnictwo (straty, powroty), Uszkodzenia i naprawy, Centrum taktyczne (mapa sytuacyjna, cele, prognozy torów).

## Scenariusz wzorcowy: atak na Walako (wyjście ze skoku 100 km)

| t | Zdarzenie |
|---|---|
| 0 s | Wyjście rebelii 100 km od Walako. Alarm. Sensory zaczynają pomiar Hz. |
| 0–30 s | Fala 1 ISD: 4 klucze dyżurne. Osłony na 1800/1800. |
| 30–60 s | Pierwszy odczyt typu i Hz kluczy rebelii; przestrajanie osłon i zapory. |
| 70–90 s | Starcie klucz na klucz 10–30 km od ISD (rebelia 9 kluczy, ISD ok. 4). |
| 60–120 s | Fala 2: 6 kluczy. |
| ok. 120 s | Krążownik rebelii (MC80) w paśmie 40 km. |
| 150–240 s | Fala 3: 8 kluczy. |
| ok. 300 s | Krążownik na 10 km — wymiana burtami. |
| 3600 s | Posiłki. Koniec okna. |

Skan nadprzestrzeni Walako może z wyprzedzeniem dać prognozę wyjścia krążownika (klucze słabo widoczne). [R]

## Decyzje dotyczące ISD

| # | Pytanie | Propozycja |
|---|---|---|
| 4 | Liczba PD (arkusz „Reguły i profile”: „64 TL; PD 40 lub 60; 60 baterii jonowych + 2 wieże”) | 8×8 = 64 lufy, PD 60 |
| 15 | Pierwsza konsola do wdrożenia | Osłony (najpełniejszy model + gotowy prototyp i SVG) |

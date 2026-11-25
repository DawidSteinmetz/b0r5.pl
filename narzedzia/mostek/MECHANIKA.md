# Mostek ISD — mechanika walki i konsol (specyfikacja robocza)

Zebrane z: arkusza `Pulpity_ISD_2_uklad_konsol.ods`, prototypu „Konsola osłon – prototyp żył”, plików gniazd SVG
(`isd-konsola-*-gniazda.json`), audytu pulpitów, zapisów rozmów o walce, Hz i statku pirackim oraz wyciągu mechaniki.
Stan: 25.11.2026.

Oznaczenia statusu: **[U]** ustalone · **[R]** robocze (liczba do strojenia w testach) · **[?]** do ustalenia
(w nawiasie proponowana wartość domyślna). Liczby w tym dokumencie zostały sprawdzone rachunkiem; rozbieżności są
opisane przy danym punkcie.

---

## 0. Zasady nadrzędne

1. **Jeden stan okrętu, wiele widoków.** [U] Konsole nie są osobnymi formularzami, tylko widokami jednego stanu
   technicznego okrętu w SQL. Zmiana Hz osłon od razu zmienia pobór na konsoli reaktorów i rezerwę na konsoli ognia.
2. **SQL jest źródłem stanu, reguł i uprawnień.** [U] Bez JSON. SVG to wyłącznie grafika.
3. **Każda zmiana ma ślad:** jednostka, system, wartość przed i po, użytkownik, czas gry, przyczyna. [U]
4. **Gracz nie widzi tików ani liczb modelu.** [U] Widzi puls radaru, ekrany i zdarzenia (alarm, blask na siatce,
   spadek wskaźnika, komunikat).
5. **Walkę rozstrzyga silnik, ale wynik pozostaje pod kontrolą MG** [U]: MG może wstrzymać, cofnąć zdarzenie lub
   nadpisać wynik (konsola myśliwców: „bez automatycznego rozstrzygania walki”).
6. **Żaden przycisk nie naprawia magicznie systemu.** [U] Wzmocnienie i synchronizacja zawsze coś kosztują.

---

## 1. Czas

| Pojęcie | Wartość | Status |
|---|---|---|
| Tik | 1 s czasu gry | [U] |
| Cykl | 12 tików | [U] |
| Godzina bitwy | 3600 tików | [U] |
| Standardowa bitwa (ISD + 72 myśliwce vs MC80 + 36) | ok. 1 h | [U] |
| Prototyp HTML | tik co 100 ms (przyspieszony podgląd) | [R] |

**Kolejność w tiku** [U]: ruch → ogień → straty → zdarzenia do konsol.
Uściślenie [R]: „ogień” = wystrzały, których bolt dolatuje w tym tiku. Bolt leci: turbolaser 5 km/s, więc przy
10 km trafia po 2 tikach — wystrzał i trafienie to dwa różne tiki (strzał trafia w cel tam, gdzie cel będzie).

**Model obliczeń w grze** [?] (proponuję): stan bitwy liczony leniwie — przy każdym odczycie serwer dolicza tiki od
ostatniego zapisu (jak klucze myśliwców dziś), więc bitwa toczy się także bez otwartych konsol. Godzina = 3600 tików,
27 kluczy i kilka okrętów — to dla PHP drobiazg.

---

## 2. Energia: reaktor, porcje, dławienie

| Parametr | ISD I | Status |
|---|---|---|
| Moc reaktora (rdzeń) | 7,73×10²⁴ W | [U] |
| Porcja | 1% = 7,73×10²² W, 100 porcji | [U] |
| Skala modelu żył | wartości „laboratoryjne” × 10¹⁷ (1440 W → 1,44×10²⁰ W) | [U] |

**Reaktor nie odzyskuje mocy rdzenia ponad nominalną.** [U] Regenerują się trzy rzeczy: margines cieplny,
poziom dławienia i zasobniki energii.

- Dławienie schodkowe: 40% → 70% → 100% dostępnej mocy. [R]
- Odbudowa na tik = chłodzenie_bazowe + k·wolne_porcje − ciepło_z_rykoszetu. [U wzór, R stałe]
- Trzy przyczyny spadków mocy [U]: dławienie (termika, zabezpieczenia), ciepło z rykoszetu osłon, fizyczne
  uszkodzenie magistrali lub rozdzielni.

**Budżet porcji** [U]: suma przydziałów nie przekracza 100% (minus dławienie). Wzmocnienie jednego systemu osłabia
inny. Kanały mocy [?] (proponowana lista — dziś na konsoli reaktorów są tylko pierwsze cztery i hipernapęd):

| Kanał | Pobór przykładowy |
|---|---|
| Osłony (2 generatory) | 13,4% przy 3600/3600, 6,7% przy 1800/1800, 3,3% przy 900/900 |
| Baterie turbolaserów | do 30% (3,75 porcji na baterię przy 3600 Hz) |
| Działa jonowe | [?] |
| Obrona punktowa | [?] |
| Silniki jonowe | [?] |
| Hipernapęd | [?] (tylko przy przygotowaniu skoku) |
| Sensory | koszt skanu k·J^1,618 (rozdz. 7) |
| Grawitacja | stała (tryb codzienny) + duży pobór w trybie Anchor/Lock |
| Promienie ściągające, hangar, podtrzymywanie życia | [?] |

Do ustalenia [?]: ile porcji ma zasobnik („rezerwa na salwę”, „rezerwa awaryjna” z konsoli reaktorów) i jak szybko
się ładuje.

**Architektura energii** (z audytu) [U]: reaktor wytwarza → generator i magistrale przekazują → odbiorniki pobierają →
zasobniki przechowują → chłodzenie i zabezpieczenia ograniczają. Na konsoli reaktorów te warstwy mają być rozdzielone.

---

## 3. Osłony

### 3.1 Generatory i żyły

| Klasa | Generatory | Żyły / generator | Siatka | Stopnie natywne | Moc żyły | Po utracie generatora |
|---|---|---|---|---|---|---|
| ISD | 2 (burta lewa, prawa) | 3600 | 60×60 | 45 dzielników 3600 | U₀ 120 V, R₀ 10 Ω → 1440 W ×10¹⁷ | odkryta cała burta |
| MC80 | 6 | 2187 (3⁷) | 81×27 | 8: 1, 3, 9, 27, 81, 243, 729, 2187 | U₀ 89 V → ok. 790 W ×10¹⁷ | przekierowanie sektora, słabsza osłona |
| „Zbieg” | 1 główny + 1 zapasowy | 1800 + 450 | — | 36 (do 1800) / do 450 | 400 W ×10¹⁷ | zapas 450 Hz |

Wszystkie [U]. Nie zakładać jednego rozkładu siatki dla wszystkich klas — liczba generatorów, żył, stopni i odporność
po awarii pochodzą z profilu okrętu.

**Stopnie natywne ISD** [U]: 1, 2, 3, 4, 5, 6, 8, 9, 10, 12, 15, 16, 18, 20, 24, 25, 30, 36, 40, 45, 48, 50, 60, 72,
75, 80, 90, 100, 120, 144, 150, 180, 200, 225, 240, 300, 360, 400, 450, 600, 720, 900, 1200, 1800, 3600.

**Zapalanie żył** [U]: żyła n świeci, gdy n mod (N/f) = 0. Przy 3600 świecą wszystkie, przy 1800 co druga, przy 900
co czwarta, przy 1 jedna.

**Trzy wartości Hz** [U] — konsola pokazuje je osobno:
1. Hz zadane (ustawione suwakiem),
2. Hz osiągane (w trakcie przestrajania),
3. **Hz skuteczne = liczba żywych, świecących żył** — martwe żyły je obniżają.

### 3.2 Filtr energii

Dla energii trafienia E [U]:
- do kadłuba: E · max(0, 1 − Hz_o / Hz_l),
- do generatora osłon: E · min(1, Hz_o / Hz_l).

Przykłady: 900 vs 1200 → 25% do kadłuba; 900 vs 1800 → 50%; 1800 vs 3600 → 50%; osłona ≥ laser → 0% i cała
energia w generator. Strona ustawiona na 1 Hz przepuszcza 99,97% (przy laserze 3600).

**Wróg powyżej 3600 Hz** zawsze przebija ISD. [?] czy taki istnieje (proponuję: tylko jako wyjątek fabularny).

### 3.3 Koszt i zużycie

| Hz | Pobór (liniowo, Hz/450) | Zużycie żył przy trafieniu ((Hz/450)^1,585) |
|---|---|---|
| 225 | ×0,5 | ×0,33 |
| 450 | ×1 | ×1 |
| 900 | ×2 | ×3 |
| 1800 | ×4 | ×9 |
| 3600 | ×8 | ×27 |

[U] wzory, [R] wykładnik 1,585 (= log₂3, czyli ×3 na podwojenie) i punkt odniesienia 450 Hz.
Pobór bezwzględny: 1,44×10²⁰ W na świecącą żyłę; 2 × 3600 żył = 1,04×10²⁴ W = 13,4% reaktora (sprawdzone).

### 3.4 Pojemność generatora i rykoszet

- Pojemność C ≈ 1,3×10²³ J ≈ 5 salw burtowych ISD przy 3600 Hz (4 × 6,7×10²¹ × 5 = 1,34×10²³ J). [R]
- Nadwyżka ponad C idzie rykoszetem na reaktor, 1:1. [R]
- Salwa całego ISD (8 baterii, 5,36×10²² J co 6 tików) nasyca generator w ok. 15 tików. [wynik z modelu]
- **Błąd prototypu do poprawy:** prototyp liczy pojemność jako pełny pobór wszystkich żył (`cap = P0·N`), a nie jako
  C w dżulach. Docelowo: C jako osobny parametr profilu, obciążenie = energia pochłonięta w oknie czasu.

### 3.5 Uszkodzenia siatki (kanały V / Ω / W)

| Broń | V (napięcie) | Ω (ciepło, opór) | W (cała siatka) | Skutek |
|---|---|---|---|---|
| Turbolaser | 0,60 | 0,25 | 0,15 | lokalny spadek napięcia |
| Wiązka ciągła | 0,20 | 0,60 | 0,20 | nagrzewanie, wypalanie żył |
| Ostrzał seryjny (myśliwce, zapora) | 0,15 | 0,15 | 0,70 | obciążenie całej siatki |
| Działo jonowe | 1,00 | 0 | 0 | brown-out bez ciepła |

[U] dominujący kanał, [R] proporcje.

- Hz_o ≥ Hz_l: energia rozchodzi się po siatce, bez lokalnych uszkodzeń, obciąża generator.
- Hz_o < Hz_l: energia koncentruje się wokół trafienia (rozkład Gaussa), żyły się łamią, reszta idzie do kadłuba.
- Jon: energia przepuszczona przez osłonę nie rani kadłuba, tylko wyłącza losowe systemy na t tików. [?] t (proponuję 12–36).

Stałe prototypu [R — wartości testowe, nie reguły gry]: spadek napięcia U² −= 350·E·V·waga; nagrzanie
T += 2,8·E·Ω·waga; opór R = R₀(1 + 0,05·T); żyła ginie przy T > 40; U wraca do docelowego o 20% na tik; T stygnie
×0,985 na tik; rykoszet gaśnie ×0,9 na tik; promienie trafienia: turbolaser 7, jon 9, wiązka 4 (20 impulsów po 10),
seria 2 (12 losowych po 100/12).

### 3.6 Taktyka i ustawienia

- Domyślnie 1800/1800, dopóki sensory nie zmierzą wroga. [U]
- Optimum: najbliższy stopień natywny ≥ Hz lasera. Nadmiar Hz nic nie daje — tylko pobór i zużycie. [U]
- Świadome niedostrojenie: Hz poniżej lasera → część do kadłuba, ale generator mniej obciążony (wybór: kadłub czy
  reaktor). [U]
- Asymetria (np. 3600/1) tylko przy zagrożeniu z jednej burty. [U]
- Przestrajanie: ręcznie 1 cykl (12 s), z presetu lub w trybie automatycznym 2–4 tiki. [R]
- Suwaki MIN/MAX na konsoli = zakres, w którym tryb automatyczny sam dostraja się do zmierzonego lasera. [U]
- Wymuszanie na wrogu: obciążenie jego generatora ∝ Hz_o (bo E ∝ Hz_l), więc zmuszenie go do wysokiego Hz nasyca
  generator szybciej (3600 vs 900: ×4 obciążenia, ×9 zużycia). Skakanie Hz baterii między salwami zmusza go do
  ciągłego przestrajania. [wynik z modelu]

---

## 4. Uzbrojenie

### 4.1 Profil ISD

| | Bateria ciężka | Obrona punktowa (PD) | Ciężka wieża jonowa | Działo jonowe NK-7 |
|---|---|---|---|---|
| Liczba | 8 baterii × 8 luf (4 lewe + 4 prawe) | 60 | 2 | 60 |
| Energia | 8,4×10²⁰ J / lufa, 6,7×10²¹ J / bateria | 10¹⁵ J | 4×10²⁰ J | 10¹⁸ J |
| Ładowanie | 6 tików (2 salwy / cykl) | 1 | 8 | 3 |
| Bolt | 30 m, 5 km/s | 3 m, 20 km/s | 25 m, 5 km/s | 8 m, 8 km/s |

[U] układ, [R] liczby. **Rozbieżność do rozstrzygnięcia** [?]: arkusz „Reguły i profile” podaje „64 TL; PD 40 lub 60;
60 baterii jonowych + 2 wieże” — proponuję przyjąć 8×8 = 64 lufy i PD 60 (jak w tabeli wyżej).

### 4.2 Hz baterii

- Każda bateria ma 3600 żył; Hz lasera = liczba aktywnych żył; te same 45 stopni co osłony. [U]
- Pobór i energia salwy rosną liniowo z Hz. Przy 30% reaktora na baterie: 2,9×10²³ W na baterię = 3,75 porcji przy
  3600 Hz; energia salwy baterii 6,7×10²¹ J (3600), 3,4×10²¹ J (1800), 1,7×10²¹ J (900). [U, sprawdzone]
- Obrażenia kadłuba ∝ (Hz_l − Hz_o): wyższy Hz wygrywa przeciw osłonom. Niski Hz i szybki ogień — przeciw kluczom
  i celom bez osłon. [U]
- Zużycie luf przy wysokim Hz [?] — jedyna rzecz, która może odwrócić przewagę wysokiego Hz (proponuję (Hz/450)^1,585
  jak w osłonach, tylko w trybie ognia ciągłego).
- Czy czas ładowania zależy od Hz [?] (proponuję: stały 6 tików).

### 4.3 Pasma odległości

| Pasmo | Odległość burt | Długości ISD | Status |
|---|---|---|---|
| Start, zbliżanie | 40 km | 25 | [R] |
| Standardowa wymiana ognia | 10 km | ok. 6 | [R] |
| Minimum (dobijanie, abordaż) | 3 km | ok. 2 | [R] |
| Strefa PD i zapory | < 5 km | — | [R] |

### 4.4 Celność

p = 1 − exp(−A / (2πσ²)), σ = R · θ_eff; A — przekrój celu (m²), R — dystans (m), θ_eff — efektywny rozrzut (rad).
Granice: 1% i 95% na salwę. [U wzór, R granice]

Mnożniki θ_eff [R]: zygzak ×1,3 · beczka ×1,6 · spirala asa ×2 · skok cieniowy ×2,5 · burst ×1,5 · zakłócenia ×1,3 ·
dobry skan ×0,7 · zły skan ×1,5.

### 4.5 Obrona punktowa

Pokrycie stanowisk zależnie od stożka [R]: 30° → ok. 24 stanowiska na kierunek, 60° → ok. 36, rozproszony 360° → ok. 10.
Zmiana stożka: 3 tiki.

### 4.6 Kadłub [?]

Brakuje modelu kadłuba: co oznacza energia „do kadłuba”. Dziś gra ma tylko `kadlub` w procentach. Proponuję:
pancerz sekcji (burta, dziób, rufa, nadbudówka, hangar) z progami — trafienie zmniejsza integralność sekcji,
pod progami uszkadza systemy w sekcji (rozdz. 10).

---

## 5. Myśliwce i klucze

Część tego **już działa w grze** (klucze, taśmociąg, alarm, rozkazy, piloci, skan z bliska) — mechanika walki ma się
do tego podpiąć, a nie budować od nowa.

| Element | Wartość | Status | W grze |
|---|---|---|---|
| Klucz | 4 maszyny, jeden symbol na mapie; liczebność 4–1, straty nie usuwają symbolu | [U] | jest (bez strat) |
| ISD / MC80 | 72 / 18 kluczy · 36 / 9 kluczy | [U] | ISD jest |
| Alarm | normalny 1 klucz gotowy, żółty 4, czerwony wszystkie | [U] | jest (procedura przechwycenia) |
| Taśmociąg | 1 klucz / min; poza alarmem +4 min przygotowania na klucz | [R] | jest |
| Hz klucza | z hangaru (typ, konfiguracja); odczyt klucza = maksimum z maszyn | [U] | brak |
| Dryf Hz | Hz = Hz_bazowe · (1 + k · moc), moc 0–1; k = 0,25 zwykły, 1/3 as; klucz z asem liczony jako k = 1/3 | [U] | brak |
| Przebicie skali ISD | baza > 2880 (zwykły) lub > 2700 (as) przy pełnej mocy na działka przebija 3600 | [U, sprawdzone] | — |
| As | więcej manewrów; przestrojenie 1 tik (zwykły 3); limit zmian 4 na cykl (zwykły 2) | [U] | rangi są, bez manewrów |
| Prędkość | myśliwiec 1 km/s, krążownik 0,5 km/s | [R] | patrz uwaga niżej |
| Doświadczenie | 0,5 PD za symulację | [U] | jest inny model (patrz niżej) |

**Do uzgodnienia z grą** [?]:
- W grze piloci mają trzy rangi (as, standardowy, kadet), poziom z doświadczenia i mnożnik zdobywania
  (×0,5 / ×1 / ×1,5); arkusz zna dwie. Proponuję: k dryfu = 0,25 dla kadeta i standardowego, 1/3 dla asa;
  przestrojenie 3 / 2 / 1 tik (kadet / standardowy / as); 0,5 PD za symulację × mnożnik rangi.
- Prędkości: klucze na mapie układu poruszają się dziś w skali mapy (setki–tysiące km/s). Walka toczy się w
  **lokalnym polu bitwy** (km, km/s) — osobny układ odniesienia, zakotwiczony w punkcie układu, w którym toczy się bitwa.
- Manewry (lista zwykłych i asów) i zapas zmian na turę [?].

---

## 6. Ruch okrętu i silniki

**Zasada ruchu** [U — z ustaleń użytkownika]:
1. Skręt = różnica ciągu lewy − prawy × ramię. Prawy silnik na maksimum, lewy niżej → moment obrotowy w lewo.
2. Zmienia się orientacja dziobu; **wektor ruchu zostaje** i zmienia się dopiero od ciągu wzdłuż nowego zwrotu.
3. Silniki manewrowe ustawione pod kątem dają przesuw boczny bez zmiany zwrotu.

**Silniki ISD** [U]: 2 główne boczne, 1 środkowy, 4 manewrowe (lewy/prawy × górny/dolny). Pulpit: ciąg, wychylenie
dysz, trym pochylenia i odchylenia, synchronizacja z osłonami rufowymi, zasilanie z reaktora.

**Proponowany model** [?] (dla 2D pola bitwy; pochylenie w 3D później):
- przyspieszenie liniowe a = Σ(ciąg wzdłuż dziobu) / m,
- przyspieszenie kątowe α = (F_prawy − F_lewy) · r / I − tłumienie · ω,
- prędkość kątowa ograniczona profilem: ISD w grze ma już `obrot_deg_na_min` = 30°/min (0,5°/s),
- przesuw boczny = ciąg manewrowych · sin(kąt dysz) / m,
- maksymalne przeciążenie z profilu (ograniczenie konstrukcji i załogi).

Brakuje [?]: ciągu, masy, momentu bezwładności, limitu przeciążenia i zależności „porcje reaktora → ciąg”.
Propozycja: zamiast fizycznych mas — parametry profilu wprost (maks. przyspieszenie liniowe w m/s², maks. prędkość
kątowa, czas od 0 do maks. obrotu), a ciąg jako ułamek maksimum zależny od przydziału porcji.

**Dodać na konsoli silników** (audyt) [U]: trym przechylenia, wektor ruchu osobno od zwrotu dziobu, prędkość,
maksymalne przeciążenie, temperatura, uszkodzenia dysz, pobór mocy.

**Konsola nawigacji** [U koncepcja]: duży ekran z kręgami odległości (np. 250 / 500 / 1000 km) i sylwetką ISD
w skali, suwak skrętu w lewo po lewej, w prawo po prawej, „cała naprzód”, „pełne hamowanie”, kąt i moc silników
manewrowych (przesuw pod skosem). Arkusz „Nawigacja” nie powstał.

Prędkości robocze [R]: myśliwiec 1 km/s, krążownik 0,5 km/s, „Zbieg” 1,2 km/s (burst 2,0 km/s przez 12 tików).

---

## 7. Sensory

| Element | Wartość | Status |
|---|---|---|
| Jednostka zasięgu | 1 J ≈ 4,24 roku świetlnego ≈ 1,30 pc | [U] (gra liczy w pc) |
| Koszt skanu | k · J^1,618 | [U wzór, ? k] |
| Częstotliwość skanu | niska: lepiej wykrywa małe cele, większa elipsa błędu, praktycznie wymaga wyłączenia osłon; wysoka: dokładniejsza pozycja, osłony zostają | [U] |
| Tryb aktywny | emituje, mierzy Hz celów, **zdradza skanującego** | [U] |
| Tryb bierny | nasłuch, wykrywa cudze skany (kierunek, przybliżona częstotliwość, typ), nie mierzy Hz | [U] |
| Skan nadprzestrzeni | zmarszczka wejścia i odcinek skoku w zasięgu; prognoza wyjścia dużej masy (punkt, kierunek, czas) z niepewnością malejącą z czasem skanu; krążownik widać dobrze, klucze słabo albo wcale | [U] |

**Pomiar Hz celu** [U]: jedna próbka na tik; dokładność rośnie z liczbą próbek; zakłócenia i utrata celu zerują część
próbek. Trzy poziomy wiedzy: nieznane → typ rozpoznany (widełki z klasy) → Hz zmierzone. Klucz mierzony jako jeden
cel (maksimum z maszyn); sensory raportują bazę i bieżący odczyt — rosnący odczyt = klucz dosypuje mocy na działka,
nadchodzi atak.

**Konsola sensorów potrzebuje** [U]: ekranu widma z Hz grup celów, przycisku „skan Hz celu” z wyborem grupy,
przycisku „wyślij do osłon” (dane trafiają na ekran osłon, nie tylko głosem).

**Do uzgodnienia z grą** [?]: dziś `systemy_okretu.tryb_czujnikow` ma wartości wyłączone / pasywny / dalekiego zasięgu /
pełna moc, a radar ma zasięg w pc i analizę sygnatury. Proponuję: pasywny = tryb bierny, dalekiego zasięgu i pełna moc =
tryb aktywny z różnym J; analiza sygnatury zostaje jako osobna, wolna funkcja identyfikacji.

---

## 8. Generator grawitacji

| Tryb | Działanie | Koszt | Status |
|---|---|---|---|
| Codzienny | sztuczna grawitacja pokładowa | stały pobór; przy niedoborze mocy lub awarii spada | [U] |
| Anchor / Lock | utrzymuje relację położenia względem ciała lub punktu | wysoki pobór, większa sygnatura, wymaga wejścia w okno i stabilnego odniesienia, podatny na zakłócenia | [U] |

Stany blokady [U]: gotowa → stabilizowanie → utrzymana → zakłócona → zwalniana.
Do ustalenia [?]: warunki okna, bezwładność, koszt w porcjach, limity siły pola.

---

## 9. Konsole

### 9.1 Lista

18 stanowisk w dwóch dołkach (9 + 9). Konsole zaprojektowane: Osłony, Turbolasery, Silniki, Reaktory
(układ + SVG + gniazda), Kontrola ognia, Sensory, Grawitacja, Myśliwce (układ, bez grafiki).
Brakuje [U z audytu]: Nawigacja i hipernapęd, Działa jonowe (osobno od turbolaserów — inna mechanika), Hangar
i lotnictwo (straty, powroty), Uszkodzenia i naprawy, Centrum taktyczne (mapa sytuacyjna, cele, prognozy torów).

### 9.2 Znaczenie czasowników

Do zatwierdzenia — proponuję jedną definicję dla wszystkich konsol:

| Operacja | Znaczenie | Koszt i czas (propozycja) |
|---|---|---|
| **Wzmocnij** | tymczasowo +1 stopień wydajności systemu (np. pojemność generatora osłon tej strony, gęstość ognia baterii) | +porcje z reaktora i ciepło; trwa 1 cykl; nie częściej niż co 2 cykle |
| **Synchronizuj** | dopasuj parametr dwóch systemów do wspólnego stopnia natywnego (np. Hz osłon do Hz własnej baterii, by nie strzelać przez własną osłonę) | przestrojenie jak ręczne (12 s) albo 2–4 tiki z presetem |
| **Wyrównaj** | rozdziel obciążenie lub moc według algorytmu (równo albo proporcjonalnie do zagrożenia) | bez kosztu mocy; zajmuje 1 cykl |
| **Przełącz** | zmień aktywne źródło zasilania (np. silniki na reaktor zapasowy) | krótka przerwa zasilania (1–3 tiki) |
| **Równaj z reaktorem** | ustaw wartość na wzorzec reaktora (częstotliwość bazowa z konsoli reaktorów) | jak synchronizacja |

### 9.3 Uprawnienia i stany

Poziomy przycisku [U]: obligatoryjny, dostępny, zablokowany (przez kapitana). Stany kontrolki [U z audytu]: dostępny,
zablokowany, nieaktywny, uszkodzony — potrzebna legenda.
Kolory w arkuszu [U]: niebieski — urządzenia, czerwony — wzmocnienia, zielony — synchronizacja, żółty — suwaki,
szary — ograniczenia.

### 9.4 Telemetria (z audytu) — ważniejsza niż nowe przyciski

- Osłony: 3 wartości Hz, żywe i martwe żyły, kanały V/Ω/W, obciążenie i pojemność generatora, rykoszet, regeneracja.
- Turbolasery i PD: liczba sprawnych stanowisk, stan, przydział mocy, przeładowanie, priorytety (pociski / klucze).
- Silniki: wektor ruchu, zwrot, prędkość, przeciążenie, temperatura, uszkodzenia dysz, pobór.
- Reaktory: temperatura i margines cieplny, dławienie, moc nominalna i dostępna, obciążenie magistral, ciepło
  z rykoszetu, zasobniki.

### 9.5 Warstwy interfejsu i geometria

| Warstwa | Zawartość | Źródło |
|---|---|---|
| 0 · tło | korpus, metal, cienie | SVG `*-tlo` (bez napisów, bez kliknięć) |
| 1 · kontrolki | przyciski, suwaki, przełączniki, ramy ekranów | SVG `*-kontrolki`, ID grup |
| 2 · tekst | etykiety, wartości, statusy | SVG `*-teksty` + UI; przełącznik czcionki (podstawowa / Aurebesh) zmienia całą warstwę |
| 3 · dane na żywo | siatka żył, temperatury, moc, radar | Canvas/DOM z aktualnego stanu SQL |

Geometria [U, 25.11.2026]: siatka 13×9 **kwadratowych** komórek (arkusz: 2,8 × 2,8 cm), proporcje 1,44 — między
tabletem 4:3 a laptopem 16:10. Duże pulpity są dla komputerów i tabletów; telefony obsługują tylko klucze myśliwców.
Ekran 60×60 lampek (siatka żył) = blok 3×3 komórek: osłony (generator lewy i prawy), turbolasery (wybrana bateria
lewa i prawa), reaktory (reaktor główny). Kolory i znaczenie jak w pierwszych pulpitach; każdy pulpit ma własną
kompozycję, wspólne są: szare suwaki zakresu w kolumnach 1 i 13 (wiersze 1–7) i symetria burt.
Pliki gniazd SVG z 24.11 (viewBox 1600×900, siatka od (202, 112), komórka 92×74 px) są sprzed przejścia na kwadraty
— do przeliczenia (np. komórka 86×86 px, siatka 1118×774 w kadrze 1600×900). Każde gniazdo ma id, etykietę, typ,
wiersz/kolumnę i prostokąt (suwaki: także tor). SVG są gotowe dla Osłon, Turbolaserów, Silników i Reaktorów
(w Downloads; w repo leżą w `assets/wnetrza/`, niezapisane).

[?] Telefon: konsola 1600×900 jest na nim nieczytelna — proponuję tryb listy (panele jak karta jednostki w mapie).

### 9.6 Błędy w arkuszu do poprawienia

- Konsola Turbolasery: przycisk „wzmocnij osłony rufowe” (powinno być: rufowe PDLC).
- Synchronizacje silników rufowych i promieni ściągających powielone 1:1 na Osłonach i Turbolaserach — zostawić
  tam, gdzie mają sens (osłony rufowe ↔ silniki; promienie ściągające ↔ przedni reaktor).
- Literówki: „z lewego silnikiem głównym” → „z lewym silnikiem głównym”, „lewą baterie” → „lewą baterię”.
- Nazwa pliku „ISD 2” przy profilach ISD I (Walako to ISD I).

---

## 10. Uszkodzenia i naprawy [?]

Brak modelu. Propozycja: systemy okrętu mają integralność (0–100%) i stan (sprawny / ograniczony / wyłączony /
uszkodzony); trafienie kadłuba w sekcji i jon losują system z tej sekcji; konsola kontroli uszkodzeń wybiera
priorytety napraw i przydziela ekipy. Naprawy w walce przywracają działanie częściowo, pełne — w stoczni
(gra ma już stocznię i kolejkę napraw).

---

## 11. Przeciwnik (AI)

Ta sama mechanika co gracze; decyzja co kilka tików, wagi akcji + mała losowość; opcjonalnie widzi tylko to, co
wykryły jego sensory. [U koncepcja, R wagi]

| Parametr | Radus (agresywny) | Ackbar (defensywny) | Calrissian (zrównoważony) |
|---|---|---|---|
| Agresja | wysoka | niska | średnia |
| Próg odwrotu | późny | wczesny | średni |
| Fale myśliwców | wszystkie naraz | małe, ciągłe | zależnie od sytuacji |
| Ogień | skupiony na jednym celu | rozproszony, zapora | skupiony po rozpoznaniu |
| Przestrajanie Hz | rzadko | często, z zapasem | po pomiarze |
| Ochrona okrętów | niska | wysoka | średnia |
| Reakcja na przeciążenie | ignoruje | wycofuje się | odciąża burtę |

---

## 12. Profil „Zbieg” (kurier piracki HLK-P1)

Kadłub ok. 40 m, załoga 6–8, reaktor ok. 1,2×10²³ W (1,5% ISD), generator główny 1800 żył (36 stopni do 1800 Hz,
400 W ×10¹⁷ na żyłę), zapasowy 450 żył (do 450 Hz), zasobnik 7×10²³ J na burst (2,0 km/s przez 12 tików,
przelotowo 1,2 km/s), zakłócacz promienia ściągającego, tryb cichego skoku. [R]

---

## 13. Scenariusz wzorcowy: atak na Walako (wyjście ze skoku 100 km)

| t | Zdarzenie |
|---|---|
| 0 s | Wyjście rebelii 100 km od Walako. Alarm. Sensory zaczynają pomiar Hz. |
| 0–30 s | Fala 1 ISD: 4 klucze dyżurne. Osłony na 1800/1800. |
| 30–60 s | Pierwszy odczyt typu i Hz kluczy rebelii; przestrajanie osłon i zapory. |
| 70–90 s | Starcie klucz na klucz 10–30 km od ISD (rebelia 9 kluczy, ISD ok. 4). |
| 60–120 s | Fala 2: 6 kluczy. |
| ok. 120 s | Krążownik rebelii w paśmie 40 km. |
| 150–240 s | Fala 3: 8 kluczy. |
| ok. 300 s | Krążownik na 10 km — wymiana burtami. |
| 3600 s | Posiłki. Koniec okna. |

Skan nadprzestrzeni Walako może z wyprzedzeniem dać prognozę wyjścia krążownika (klucze słabo widoczne). [R]

---

## 14. Decyzje do podjęcia (zestawienie)

| # | Pytanie | Propozycja |
|---|---|---|
| 1 | Model obliczeń | leniwe doliczanie tików na serwerze przy odczycie |
| 2 | Pojemność generatora C i rykoszet | C ≈ 1,3×10²³ J (5 salw burtowych), rykoszet 1:1 |
| 3 | Kanały mocy i zasobniki | lista z rozdz. 2; zasobnik 10 porcji, ładowanie 1 porcja/cykl |
| 4 | Liczba PD | 60 |
| 5 | Zużycie luf i ładowanie zależne od Hz | zużycie (Hz/450)^1,585 tylko przy ogniu ciągłym; ładowanie stałe 6 tików |
| 6 | Model kadłuba | sekcje z integralnością i progami (rozdz. 4.6) |
| 7 | Czas wyłączenia systemów przez jon (t) | 12–36 tików |
| 8 | Rangi pilotów w walce | kadet / standardowy / as: k 0,25 / 0,25 / ⅓, przestrojenie 3 / 2 / 1 tik |
| 9 | Pole bitwy | lokalny układ w km, zakotwiczony w punkcie układu planetarnego |
| 10 | Ruch okrętu | parametry profilu (maks. przyspieszenie, prędkość kątowa, czas rozkręcenia obrotu) zamiast mas |
| 11 | Sensory a dzisiejsze tryby czujników | pasywny = bierny, dalekiego zasięgu / pełna moc = aktywny z różnym J |
| 12 | Znaczenie czasowników | tabela w 9.2 |
| 13 | Wróg powyżej 3600 Hz | tylko wyjątek fabularny |
| 14 | Telefon | tryb listy dla konsol |
| 15 | Pierwsza konsola do wdrożenia | Osłony (najpełniejszy model + gotowy prototyp i SVG) |

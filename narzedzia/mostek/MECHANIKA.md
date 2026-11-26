# Mostek — mechanika walki wspólna dla wszystkich klas (specyfikacja robocza)

Zebrane z: arkusza `Pulpity_ISD_2_uklad_konsol.ods`, prototypu „Konsola osłon – prototyp żył”, plików gniazd SVG
(`isd-konsola-*-gniazda.json`), audytu pulpitów, zapisów rozmów o walce, Hz i statku pirackim oraz wyciągu mechaniki.
Stan: 25.11.2026.

**Ten dokument zawiera tylko wzory i reguły wspólne.** Liczby zależne od klasy (reaktor, generatory, żyły,
baterie, myśliwce, silniki) są w profilach: [ISD I](profile/ISD_I.md), [MC80](profile/MC80.md),
[„Zbieg”](profile/ZBIEG.md), a jako dane — w [`profile_klas.py`](profile_klas.py). Zasady pulpitów:
[PULPITY.md](PULPITY.md). Kontrola rachunkiem: `python mechanika.py` (wzory z tego dokumentu w kodzie).

Oznaczenia statusu: **[U]** ustalone · **[R]** robocze (liczba do strojenia w testach) · **[?]** do ustalenia
(w nawiasie proponowana wartość domyślna). Przykłady liczbowe pochodzą z profilu ISD I, chyba że zaznaczono inaczej.

### Symbole profilu

| Symbol | Znaczenie | ISD I | MC80 |
|---|---|---|---|
| P_r | moc rdzenia (suma rdzeni) | 7,73×10²⁴ W | [?] 6,0×10²⁴ W |
| G | liczba generatorów osłon | 2 | 6 |
| N | żyły na generator = maks. Hz | 3600 | 2187 (3⁷) |
| U₀, R₀ | napięcie i opór żyły (× 10¹⁷) | 120 V, 10 Ω | 89 V, 10 Ω |
| P_ż | moc żyły = U₀²/R₀ × 10¹⁷ | 1,44×10²⁰ W | 7,92×10¹⁹ W |
| N_b | żyły baterii = maks. Hz lasera | 3600 | [?] 2187 |

---

## 0. Zasady nadrzędne

1. **Jeden stan okrętu, wiele widoków.** [U] Konsole nie są osobnymi formularzami, tylko widokami jednego stanu
   technicznego okrętu w SQL. Zmiana Hz osłon od razu zmienia pobór na konsoli reaktorów i rezerwę na konsoli ognia.
2. **SQL jest źródłem stanu, reguł i uprawnień.** [U] Bez JSON. SVG to wyłącznie grafika. Profile klas
   (`profile_klas.py`) docelowo trafiają do tabel SQL.
3. **Każda zmiana ma ślad:** jednostka, system, wartość przed i po, użytkownik, czas gry, przyczyna. [U]
4. **Gracz nie widzi tików ani liczb modelu.** [U] Widzi puls radaru, ekrany i zdarzenia (alarm, blask na siatce,
   spadek wskaźnika, komunikat).
5. **Walkę rozstrzyga silnik, ale wynik pozostaje pod kontrolą MG** [U]: MG może wstrzymać, cofnąć zdarzenie lub
   nadpisać wynik (konsola myśliwców: „bez automatycznego rozstrzygania walki”).
6. **Żaden przycisk nie naprawia magicznie systemu.** [U] Wzmocnienie i synchronizacja zawsze coś kosztują.
7. **Jedna mechanika, wiele klas.** [U] Klasy różnią się wyłącznie profilem (liczby, liczba i rola podzespołów,
   reguła po utracie generatora). Nie kopiować mechaniki dla nowej klasy — dopisać profil.

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

**Tiki i cykle są tylko dla nas** [U, 25.11.2026] — do liczenia mechaniki i rozliczania czasu (limity zmian na cykl,
ładowanie, stygnięcie). Gracz gra w czasie rzeczywistym: nie ma tur, nie ma czekania na innych. Liczy się szybkość
reakcji i wciśnięcie właściwego przycisku we właściwej chwili.

### 1.1 Czas wykonania rozkazów (cast time)

[U zasada, R liczby] Niemal każdy rozkaz ma **czas wykonania**: od kliknięcia do chwili, w której skutek działa.

```
kliknięcie ─► przygotowanie (cast) ─► działanie (czas trwania) ─► odnowienie (cooldown)
     t0            t0 … t0+c                t0+c … t0+c+d            do t0+c+d+o
```

- **Rozstrzyga chwila działania, nie chwila kliknięcia.** W tiku silnik bierze pod uwagę tylko figury i ustawienia,
  które już działają. Unik rozkazany odpowiednio wcześnie działa, gdy wróg wykonuje „przelot przez cel” — jego ogień
  trafia w klucz z mnożnikiem rozrzutu uniku. Unik rozkazany za późno (przygotowanie nie skończone) nie daje nic.
- **Rozkaz wydany w trakcie przygotowania innego rozkazu tego samego systemu** przerywa poprzedni; zużyta zmiana
  z limitu na cykl przepada. Rozkaz w trakcie działania figury — zaczyna przygotowanie od razu, nowa figura
  zastępuje starą w chwili gotowości.
- **Ranga skraca przygotowanie** (dla kluczy): kadet +1 s, standardowy 0, as −1 s (min. 1 s) — jak przestrojenie
  3 / 2 / 1 tik.
- **Widoczność**: własne przygotowanie widać na konsoli od razu (pasek, licznik w sekundach); przygotowanie wroga jest
  niewidoczne — jego figurę widać dopiero, gdy działa (ikona manewru na ekranie Klucz i Radar). Czytanie wroga =
  reagowanie na początek jego figury albo przewidywanie.
- **Kolejność w tiku**: rozkazy przypisane do tiku według czasu serwera w chwili odebrania; w obrębie tiku — według
  milisekund. Szybsza reakcja wygrywa remis.
- **Łącze gry = łącze na okręcie** [U, 25.11.2026]. Serwer gry to w świecie gry komputer centralny analizujący
  sytuację bitwy; przeglądarka gracza to konsola. Kłopoty techniczne między przeglądarką a serwerem są w grze kłopotami
  między konsolą a komputerem centralnym: jeśli serwer za późno odbierze rozkaz uniku, to system za późno go wysłał
  albo klucz za późno go odebrał. Rozkaz istnieje od chwili odebrania przez serwer i od niej liczy się jego czas
  wykonania — jest **naturalnie opóźniony**. Nie wracamy do rozsynchronizowanych procesów i nie wyrównujemy niczego
  wstecz. Pasek przygotowania rusza na konsoli od razu po kliknięciu; po potwierdzeniu dociąga się do czasu serwera.
  Gdy łącze stoi dłużej, konsola ponawia wysyłkę (ten sam rozkaz, przyjęty raz) i pokazuje „brak łączności
  z komputerem centralnym”; rozkaz liczy się dopiero, gdy dotrze.

Czasy robocze [R] (sekundy czasu gry; c — przygotowanie, d — działanie, o — odnowienie):

| Rozkaz | c | d | o | Skutek w czasie działania |
|---|---|---|---|---|
| łagodny skręt, prosto | 1 | do zmiany | — | zmiana kursu |
| ostry skręt | 2 | do zmiany | — | szybsza zmiana kursu, θ celu ×1,1 |
| zygzak | 1 | 12 | — | θ ×1,3 |
| beczka | 2 | 4 | 4 | θ ×1,6 |
| burst | 1 | 3 | 12 | θ ×1,5, prędkość ×2 |
| spirala asa (tylko as) | 2 | 6 | 12 | θ ×2 |
| skok cieniowy (tylko as) | 3 | 2 | 24 | θ ×2,5, chwilowe zgubienie namiaru wroga |
| pętla, split-S (zawrót) | 3 | 6 | — | odwrócenie kursu; w czasie działania własny ogień wyłączony |
| rozejście klucza L/P, zwarcie szyku | 2 | do zmiany | — | rozejście: trafienie obszarowe ×0,5; zwarcie: własny ogień skupiony, θ celu przy nas ×0,8 |
| atak z nurkowania | 3 | 4 | — | okno ognia z celnością ×1,3, własny θ ×1,2 |
| przelot przez cel | 2 | 3 | — | okno ognia: celność ×1,5, wystawienie na PD celu |
| nożyce | 2 | 12 | — | pojedynek: celność obu stron zależy od rangi |
| ogon (trzymaj cel) | 2 | do zmiany | — | ciągły ogień, celność ×1,2 po 3 s utrzymania |
| eskorta | 2 | do zmiany | — | przejmuje część ostrzału chronionej jednostki |
| rozkaz klucza (patrol, atak, skan, powrót) | 3 | do zmiany | — | jak w grze dziś |
| przestrojenie Hz osłon ręcznie / preset / auto | 12 / 3 / 2–4 | do zmiany | — | MECHANIKA 3.6 |
| wzmocnij | 3 | 12 | 24 | +1 stopień wydajności |
| synchronizuj, równaj z reaktorem | jak przestrojenie | do zmiany | — | PULPITY 3 |
| przełącz zasilanie | 1–3 (przerwa zasilania) | — | — | |
| salwa baterii | ładowanie 6 + lot bolta (10 km: 2 s) | — | — | trafia tam, gdzie cel będzie |

Gdzie „θ ×…” — mnożnik efektywnego rozrzutu strzałów **w tę jednostkę** (wzór celności, rozdz. 4.3).

---

## 2. Energia: reaktor, porcje, dławienie

| Parametr | Wartość | Status |
|---|---|---|
| Moc rdzenia | P_r z profilu (suma rdzeni, gdy jest ich kilka) | [U] |
| Porcja | 1% P_r, 100 porcji | [U] |
| Skala modelu żył | wartości „laboratoryjne” × 10¹⁷ (1440 W → 1,44×10²⁰ W) | [U] |

**Reaktor nie odzyskuje mocy rdzenia ponad nominalną.** [U] Regenerują się trzy rzeczy: margines cieplny,
poziom dławienia i zasobniki energii.

- Dławienie schodkowe: 40% → 70% → 100% dostępnej mocy. [R]
- Odbudowa na tik = chłodzenie_bazowe + k·wolne_porcje − ciepło_z_rykoszetu. [U wzór, R stałe]
- Trzy przyczyny spadków mocy [U]: dławienie (termika, zabezpieczenia), ciepło z rykoszetu osłon, fizyczne
  uszkodzenie magistrali lub rozdzielni.
- **Kilka rdzeni** (np. MC80) [?]: dławienie i margines cieplny liczone osobno dla każdego rdzenia; utrata rdzenia
  odejmuje jego udział w P_r; budżet porcji liczony od mocy żywych rdzeni.

**Budżet porcji** [U]: suma przydziałów nie przekracza 100% (minus dławienie). Wzmocnienie jednego systemu osłabia
inny. Kanały mocy [?] (proponowana lista — dziś na konsoli reaktorów ISD są tylko pierwsze cztery i hipernapęd):

| Kanał | Pobór |
|---|---|
| Osłony | G · Hz_skuteczne · P_ż (ISD: 13,4% przy 3600/3600, 6,7% przy 1800/1800, 3,3% przy 900/900) |
| Baterie ciężkie | przydział z profilu (ISD: do 30%, 3,75 porcji na baterię przy 3600 Hz) |
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

Liczba generatorów G, żyły N, układ siatki, sektory i reguła po utracie generatora pochodzą z profilu. [U]
Nie zakładać jednego rozkładu siatki dla wszystkich klas.

**Stopnie natywne** [U] = dzielniki N. ISD (3600): 45 stopni. MC80 (2187 = 3⁷): dzielnikami są tylko potęgi trójki —
1, 3, 9, 27, 81, 243, 729, 2187 („podzielnik standardowy 3”). „Zbieg” (1800): 36 stopni.

**Zapalanie żył** [U]: żyła n świeci, gdy n mod (N/f) = 0. ISD: przy 3600 świecą wszystkie, przy 1800 co druga,
przy 900 co czwarta, przy 1 jedna. MC80: przy 729 co trzecia, przy 243 co dziewiąta.

**Trzy wartości Hz** [U] — konsola pokazuje je osobno:
1. Hz zadane (ustawione suwakiem),
2. Hz osiągane (w trakcie przestrajania),
3. **Hz skuteczne = liczba żywych, świecących żył** — martwe żyły je obniżają.

**Utrata generatora** [U zasada, reguła w profilu]: ISD — odkryta cała burta; MC80 — przekierowanie sektora
(słabsza osłona); „Zbieg” — generator zapasowy.

### 3.2 Filtr energii

Dla energii trafienia E [U]:
- do kadłuba: E · max(0, 1 − Hz_o / Hz_l),
- do generatora osłon: E · min(1, Hz_o / Hz_l).

Hz są bezwzględne — filtr działa tak samo między klasami. Przykłady ISD: 900 vs 1200 → 25% do kadłuba; 900 vs
1800 → 50%; osłona ≥ laser → 0% i cała energia w generator; 1 Hz przepuszcza 99,97% (przy laserze 3600).
Między klasami: osłona MC80 2187 vs laser ISD 3600 → 39,25%; osłona ISD 1800 vs laser MC80 2187 → 17,7%.

**Laser powyżej N** zawsze przebija osłonę tej klasy (ISD: powyżej 3600; MC80: powyżej 2187). [?] czy wróg powyżej
3600 istnieje (proponuję: tylko jako wyjątek fabularny); dla MC80 to codzienność wobec ISD — patrz profil MC80.

### 3.3 Koszt i zużycie

Pobór [U]: Hz_skuteczne · P_ż na generator (liniowo). Zużycie żył przy trafieniu [U wzór]: (Hz / Hz_ref)^w,
Hz_ref = 450 Hz, w = log₂3 ≈ 1,585 (×3 na podwojenie) [R].

| Hz | Pobór względem 450 Hz | Zużycie |
|---|---|---|
| 225 | ×0,5 | ×0,33 |
| 450 | ×1 | ×1 |
| 729 (MC80) | ×1,62 | ×2,1 |
| 900 | ×2 | ×3 |
| 1800 | ×4 | ×9 |
| 2187 (MC80 maks.) | ×4,86 | ×12,3 |
| 3600 (ISD maks.) | ×8 | ×27 |

Pobór bezwzględny ISD: 2 × 3600 × 1,44×10²⁰ W = 1,04×10²⁴ W = 13,4% reaktora (sprawdzone). MC80: 6 × 2187 ×
7,92×10¹⁹ W = 1,04×10²⁴ W — tyle samo co ISD przy pełnych osłonach.

### 3.4 Pojemność generatora i rykoszet

- Pojemność C = τ · N · P_ż, τ ≈ 0,25 s [R, wyprowadzone z ISD]: ISD C ≈ 1,3×10²³ J ≈ 5 salw burtowych przy
  3600 Hz (4 × 6,7×10²¹ × 5 = 1,34×10²³ J). MC80: 4,3×10²² J na generator, razem 2,6×10²³ J (jak ISD).
- Nadwyżka ponad C idzie rykoszetem na reaktor, 1:1. [R]
- Salwa całego ISD (8 baterii, 5,36×10²² J co 6 tików) nasyca generator ISD w ok. 15 tików. [wynik z modelu]
- **Błąd prototypu do poprawy:** prototyp liczy pojemność jako pełny pobór wszystkich żył (`cap = P0·N`), a nie jako
  C w dżulach. Docelowo: C jako parametr profilu, obciążenie = energia pochłonięta w oknie czasu.

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
seria 2 (12 losowych po 100/12). Promienie są w żyłach siatki 60×60 — dla innych siatek skalować do boku siatki [?].

### 3.6 Taktyka i ustawienia

- Domyślne Hz do czasu pomiaru wroga — z profilu (ISD 1800/1800). [U]
- Optimum: najbliższy stopień natywny ≥ Hz lasera. Nadmiar Hz nic nie daje — tylko pobór i zużycie. [U]
  Klasa o rzadkich stopniach (MC80: ×3 między stopniami) częściej przepłaca: laser 800 → osłona 2187.
- Świadome niedostrojenie: Hz poniżej lasera → część do kadłuba, ale generator mniej obciążony (wybór: kadłub czy
  reaktor). [U]
- Asymetria (np. 3600/1) tylko przy zagrożeniu z jednej strony. [U]
- Przestrajanie: ręcznie 1 cykl (12 s), z presetu lub w trybie automatycznym 2–4 tiki. [R]
- Suwaki MIN/MAX na konsoli = zakres, w którym tryb automatyczny sam dostraja się do zmierzonego lasera. [U]
- Wymuszanie na wrogu: obciążenie jego generatora ∝ Hz_o (bo E ∝ Hz_l), więc zmuszenie go do wysokiego Hz nasyca
  generator szybciej (3600 vs 900: ×4 obciążenia, ×9 zużycia). Skakanie Hz baterii między salwami zmusza go do
  ciągłego przestrajania. [wynik z modelu]

---

## 4. Uzbrojenie

Liczba baterii, luf, PD i dział jonowych — z profilu.

### 4.1 Hz baterii

- Każda bateria ma N_b żył; Hz lasera = liczba aktywnych żył; stopnie natywne = dzielniki N_b. [U]
- Energia bolta liniowo z Hz: E_lufy = e_ż · Hz, e_ż = 8,4×10²⁰ / 3600 ≈ 2,33×10¹⁷ J na żyłę (z ISD) [R].
  ISD: salwa baterii 6,7×10²¹ J (3600), 3,4×10²¹ J (1800), 1,7×10²¹ J (900). [U, sprawdzone]
- Pobór baterii liniowo z Hz; przydział z profilu (ISD: 30% reaktora = 2,9×10²³ W na baterię = 3,75 porcji
  przy 3600 Hz). [U, sprawdzone]
  **Uwaga rachunkowa** [?]: energia salwy to 0,39% energii pobranej przez baterię w 6 tikach ładowania
  (6,7×10²¹ J wobec 1,74×10²⁴ J). Albo to zamierzona sprawność (reszta to ciepło i straty), albo przydział 30% jest
  za duży o rząd 250. Proponuję: zamierzona sprawność 0,4% — ciepło baterii to osobny strumień do chłodzenia.
- Obrażenia kadłuba ∝ (Hz_l − Hz_o): wyższy Hz wygrywa przeciw osłonom. Niski Hz i szybki ogień — przeciw kluczom
  i celom bez osłon. [U]
- Zużycie luf przy wysokim Hz [?] — jedyna rzecz, która może odwrócić przewagę wysokiego Hz (proponuję (Hz/450)^1,585
  jak w osłonach, tylko w trybie ognia ciągłego).
- Czy czas ładowania zależy od Hz [?] (proponuję: stały, z profilu; ISD 6 tików).

### 4.2 Pasma odległości

| Pasmo | Odległość burt | Długości ISD | Status |
|---|---|---|---|
| Start, zbliżanie | 40 km | 25 | [R] |
| Standardowa wymiana ognia | 10 km | ok. 6 | [R] |
| Minimum (dobijanie, abordaż) | 3 km | ok. 2 | [R] |
| Strefa PD i zapory | < 5 km | — | [R] |

### 4.3 Celność

p = 1 − exp(−A / (2πσ²)), σ = R · θ_eff; A — przekrój celu (m²), R — dystans (m), θ_eff — efektywny rozrzut (rad).
Granice: 1% i 95% na salwę. [U wzór, R granice]

Mnożniki θ_eff [R]: zygzak ×1,3 · beczka ×1,6 · spirala asa ×2 · skok cieniowy ×2,5 · burst ×1,5 · zakłócenia ×1,3 ·
dobry skan ×0,7 · zły skan ×1,5.

### 4.4 Obrona punktowa

Pokrycie stanowisk zależnie od stożka [R, dla ISD z 60 PD]: 30° → ok. 24 stanowiska na kierunek, 60° → ok. 36,
rozproszony 360° → ok. 10. Dla innej liczby PD — proporcjonalnie. Zmiana stożka: 3 tiki.

### 4.5 Kadłub [?]

Brakuje modelu kadłuba: co oznacza energia „do kadłuba”. Dziś gra ma tylko `kadlub` w procentach. Proponuję:
pancerz sekcji (burta, dziób, rufa, nadbudówka, hangar — lista sekcji z profilu) z progami — trafienie zmniejsza
integralność sekcji, pod progami uszkadza systemy w sekcji (rozdz. 10).

---

## 5. Myśliwce i klucze

Część tego **już działa w grze** (klucze, taśmociąg, alarm, rozkazy, piloci, skan z bliska) — mechanika walki ma się
do tego podpiąć, a nie budować od nowa. Liczba myśliwców i kluczy — z profilu (ISD 72 = 18 kluczy, MC80 36 = 9).

| Element | Wartość | Status | W grze |
|---|---|---|---|
| Klucz | 4 maszyny, jeden symbol na mapie; liczebność 4–1, straty nie usuwają symbolu | [U] | jest (bez strat) |
| Alarm | normalny 1 klucz gotowy, żółty 4, czerwony wszystkie | [U] | jest (procedura przechwycenia) |
| Taśmociąg | 1 klucz / min; poza alarmem +4 min przygotowania na klucz; lampa klucza odlicza czas do wyjścia z hangaru (PULPITY.md 1b) | [R] | jest |
| Hz klucza | z hangaru (typ, konfiguracja); odczyt klucza = maksimum z maszyn | [U] | brak |
| Dryf Hz | Hz = Hz_bazowe · (1 + k · moc), moc 0–1; k = 0,25 zwykły, 1/3 as; klucz z asem liczony jako k = 1/3 | [U] | brak |
| Przebicie skali N | baza > N/(1+k) przy pełnej mocy na działka przebija osłonę N (ISD 3600: > 2880 zwykły, > 2700 as) | [U, sprawdzone] | — |
| As | więcej manewrów; przestrojenie 1 tik (zwykły 3); limit zmian 4 na cykl (zwykły 2) | [U] | rangi są, bez manewrów |
| Prędkość | myśliwiec 1 km/s, krążownik 0,5 km/s | [R] | patrz uwaga niżej |
| Doświadczenie | symulacja: 0,5 XP za każdą akcję NPC × mnożnik rangi (WDROZENIE.md, rozdz. 4) | [U] 25.11.2026 | XP pilotów jest (INT — do zmiany na DECIMAL) |

Przebicie dla MC80 (N = 2187): baza > 1750 (zwykły), > 1640 (as).

**Do uzgodnienia z grą** [?]:
- W grze piloci mają trzy rangi (as, standardowy, kadet), poziom z doświadczenia i mnożnik zdobywania
  (×0,5 / ×1 / ×1,5); arkusz zna dwie. Proponuję: k dryfu = 0,25 dla kadeta i standardowego, 1/3 dla asa;
  przestrojenie 3 / 2 / 1 tik (kadet / standardowy / as); 0,5 XP za akcję w symulacji × mnożnik rangi.
- Prędkości: klucze na mapie układu poruszają się dziś w skali mapy (setki–tysiące km/s). Walka toczy się w
  **lokalnym polu bitwy** (km, km/s) — osobny układ odniesienia, zakotwiczony w punkcie układu, w którym toczy się bitwa.
- Manewry (lista zwykłych i asów) i zapas zmian na turę [?].

---

### 5.1 Walka kluczy i obrona punktowa (silnik, 25.11.2026) [R]

Klucz ma pozycję, kurs i prędkość w każdym tiku; od geometrii zależy, który manewr działa.

- **Skręt**: kurs zmienia się najwyżej o prędkość kątową typu maszyny na tik (TIE/ln 30°/s, TIE/in 40, X-wing 28,
  Y-wing 18, A-wing 45, B-wing 20; as ×1,25). Figury kursu ustawiają kurs zadany (prosto 0°, łagodnie ±30°, ostro
  ±90°, pętla i split-S 180°); rozkaz klucza przywraca pościg za celem.
- **Cel klucza** z rozkazu: atak — klucze wroga w 12 km, inaczej najbliższy okręt; ochrona / patrol — klucze wroga
  w 8 / 15 km od okrętu matki, inaczej krąg 3 / 8 km wokół niego; skan — okręt; powrót — okręt matka. Pościg: 0,3 km
  za celem.
- **Ogień do kluczy**: gdy cel jest w zasięgu broni (ok. 1,5 km) i w stożku przed dziobem (±15°). Każda maszyna
  strzela; celność ze wzoru 4.3, rozrzut θ_eff = θ_broni × geometria × figury, gdzie geometria = 0,5 + 2·|sin(Δkurs)|:
  **na ogonie** (te same wektory) ×0,5 — „cel na widelcu”, większość strzałów celna; w przecięciu (90°) ×2,5.
- **Ogień do okrętu**: 3 km, stożek ±30°, energia przez filtr osłon okrętu (rozdz. 3).
- **Trafienie maszyny**: osłona maszyny przepuszcza 1 − osłona/Hz broni (TIE bez osłon — wszystko), zatrzymane
  trafienie nasyca osłonę (+0,25 × zatrzymana część, regeneracja 0,01/tik); maszyna ginie z prawdopodobieństwem
  przepuszczone + zatrzymane × nasycenie. Klucz 4 → 0 maszyn, potem „stracony”.
- **Obrona punktowa okrętu**: działka mają położenie na kadłubie, łuk ostrzału i zasięg (ISD: 60 działek po 30 na
  burtę wzdłuż klina, zasięg 5 km, łuk ±90° na zewnątrz burty, θ 8 mrad, 1500 Hz — dane/uzbrojenie.py, rysunek ISD).
  Każde strzela raz na tik w najbliższy klucz w swoim łuku — pokrycie wynika z geometrii (np. przy samym dziobie
  i za rufą mniej działek).
- **Ogień okrętów** (silnik, 25.11.2026) [R]: bateria turbolaserów (ISD 4 + 4 po 8 luf, MC80 3 + 3 po 9 luf) ma
  położenie na kadłubie, łuk ±100° wokół trawersu (wieże na grzbiecie strzelają nad dziobem na zakładkę) i zasięg
  40 km. Salwa co 6 s (przesunięta o numer baterii) w najbliższy wrogi okręt w łuku: każda lufa trafia z celnością 4.3
  (θ 5 mrad, przekrój okrętu = długość² × 0,05), energia trafionych luf = e_żyły × Hz baterii przez filtr osłon celu
  (rozdz. 3). Baterie jonowe (ISD 2 wieże, MC80 6 baterii, 20 km): energia trafień obciąża generator osłon celu,
  bez uszkodzeń kadłuba. Automatyczne osłony mierzą także Hz baterii wrogich okrętów. Trafienie jest natychmiastowe
  (lot bolta 5 km/s — kolejny etap).
- **Kadłub** [R]: odporność całego kadłuba ISD 1,2×10²⁵ J, MC80 8×10²⁴ J; średnia integralność poniżej 25%
  (W13) — okręt unieszkodliwiony, nie walczy. „Atak na Walako”: MC80 unieszkodliwiony po ok. 15 min wymiany ognia,
  Walako bez strat (osłony 3600 Hz blokują baterie 2187 Hz) — do strojenia, rebelia potrzebuje przeciwwagi.

## 6. Ruch okrętu i silniki

**Zasada ruchu** [U — z ustaleń użytkownika]:
1. Skręt = różnica ciągu lewy − prawy × ramię. Prawy silnik na maksimum, lewy niżej → moment obrotowy w lewo.
2. Zmienia się orientacja dziobu; **wektor ruchu zostaje** i zmienia się dopiero od ciągu wzdłuż nowego zwrotu.
3. Silniki manewrowe ustawione pod kątem dają przesuw boczny bez zmiany zwrotu.

Zestaw silników (główne, manewrowe) — z profilu.

**Proponowany model** [?] (dla 2D pola bitwy; pochylenie w 3D później):
- przyspieszenie liniowe a = Σ(ciąg wzdłuż dziobu) / m,
- przyspieszenie kątowe α = (F_prawy − F_lewy) · r / I − tłumienie · ω,
- prędkość kątowa ograniczona profilem (ISD w grze: `obrot_deg_na_min` = 30°/min, 0,5°/s),
- przesuw boczny = ciąg manewrowych · sin(kąt dysz) / m,
- maksymalne przeciążenie z profilu (ograniczenie konstrukcji i załogi).

Brakuje [?]: ciągu, masy, momentu bezwładności, limitu przeciążenia i zależności „porcje reaktora → ciąg”.
Propozycja: zamiast fizycznych mas — parametry profilu wprost (maks. przyspieszenie liniowe w m/s², maks. prędkość
kątowa, czas od 0 do maks. obrotu), a ciąg jako ułamek maksimum zależny od przydziału porcji.

**Konsola nawigacji** [U koncepcja]: duży ekran z kręgami odległości (np. 250 / 500 / 1000 km) i sylwetką okrętu
w skali, suwak skrętu w lewo po lewej, w prawo po prawej, „cała naprzód”, „pełne hamowanie”, kąt i moc silników
manewrowych (przesuw pod skosem). Układ ISD: pulpit „Nawigacja” w `uklady/isd_i_walako.py` (ekran 7×7 na środku).

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
próbek. Trzy poziomy wiedzy: nieznane → typ rozpoznany (widełki z klasy — z profilu celu) → Hz zmierzone. Klucz
mierzony jako jeden cel (maksimum z maszyn); sensory raportują bazę i bieżący odczyt — rosnący odczyt = klucz
dosypuje mocy na działka, nadchodzi atak.

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

Zasady pulpitów (siatka, kolory, suwaki, symetria, ekrany, warstwy, gniazda SVG, znaczenie czasowników, uprawnienia,
telemetria) — w [PULPITY.md](PULPITY.md). Lista pulpitów danej klasy — w jej profilu.

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

### 11.1 Wdrożone w silniku [R]

SI wydaje rozkazy przez tę samą kolejkę co gracze (czas wykonania, ranga) — spóźniona reakcja SI jest widoczna.
Parametry dowódców w `silnik.py` STALE['dowodcy'] (= `api/bitwa_silnik.php` BITWA_STALE['dowodcy']):

| | Radus | Calrissian | Ackbar |
|---|---|---|---|
| Dystans walki okrętu | 6 km | 10 km | 18 km |
| Odwrót poniżej kadłuba | 15% | 35% | 55% |
| Skłonność kluczy do uników | 0,10 | 0,25 | 0,40 |
| Bombowce (X/Y/B-wing) | zawsze nalot | nalot, gdy torpedy przebiją osłony | nalot, gdy przebiją i brak myśliwców wroga w 10 km; inaczej ochrona |

- **Okręt SI**: skręca z prędkością obrotu z profilu (ISD 30°/min, MC80 40°/min); dalej niż dystans walki + 2 km —
  kurs na najbliższy okręt wroga; bliżej — burtą do wroga, tą o mniejszym obciążeniu generatorów (obraca się, gdy
  gracze przeciążą jedną burtę). Poniżej progu odwrotu odchodzi od wroga; dalej niż 60 km od wszystkich wrogów —
  stan `wycofana` (zdarzenie „wycofanie”).
- **Klucz SI** (co tik, gdy nic nie ładuje się na jego kanałach): wróg do 1,5 km za rufą → unik (as: spirala; inni:
  split-S 35%, beczka) z prawdopodobieństwem 2 × skłonność; wybór rozkazu nalot / atak / ochrona (zmiana z
  prawdopodobieństwem 0,1 na tik — reaguje na przestrojenie osłon graczy); w nalocie, okręt ≤ 3 km w stożku 30° →
  figura `torpedy` (energia ×8, Hz ×1,5 przez 4 s, odnowienie 30 s; tylko X-wing, Y-wing, B-wing); przy okręcie —
  beczka / zygzak i przelot.
- Rozkaz klucza `nalot`: prosto na najbliższy okręt wroga, z pominięciem myśliwców (dostępny też dla graczy).

### 11.3 Cele domyślne, ster okrętu, koniec symulacji [R]

- Klucz nigdy nie leci „donikąd”: gdy z rozkazu nie ma celu (nic nie widać), bierze cel z danych wywiadu —
  najbliższy okręt wroga, potem najbliższy klucz wroga, potem krąży przy okręcie matce. Gracz tylko zmienia rozkaz.
- Okręt gracza: rozkaz `ster` (konsola Silniki albo Nawigacja, 3 s) ustawia kurs i prędkość zadane; okręt skręca
  z prędkością obrotu profilu (ISD 30°/min) i zmienia prędkość z przyspieszeniem profilu (ISD 0,002 km/s², maks.
  0,27 km/s). Rozkaz `cel_ognia` (Kontrola ognia, 2 s) — baterie biorą wskazany okręt, gdy jest w ich łuku i zasięgu.
- Symulacja kończy się sama, gdy minie okno scenariusza (XP liczone jak przy „Zakończ”); doliczanie zaległych tików
  ma budżet ok. 5 s na zapytanie (reszta w kolejnych odświeżeniach), więc długa przerwa nie blokuje symulacji.

### 11.2 Sensory i prędkość maszyn [R]

- TIE (ln, in, sa) nie mają radaru dalekiego zasięgu: klucz widzi cele do 5 km od siebie, dalej tylko te, które są
  w zasięgu radaru okrętu matki (60 km od niego, okręt w bitwie). Bez okrętu matki klucz TIE leci prosto, dopóki
  czegoś nie zobaczy. Konsola Klucz pokazuje graczowi TIE tylko to, co klucz zna. Maszyny Rebelii mają własne sensory.
- Prędkości maszyn = maks. prędkość w atmosferze z Wookieepedii (Legends): TIE/ln 1200 km/h (0,333 km/s),
  X-wing 1050 (0,292), A-wing 1300, Y-wing 1000, B-wing 950, TIE/sa i Lambda 850, TIE/in 1250 [?].

---

## 11.4 Osłony i zasilanie — nastawy konsol [R, 26.11.2026]

Pulpity Osłony i Reaktory mają mechanikę w silniku (`silnik.py`: `NASTAWY`, `zasilanie`, `sektor_trafienia`; port
`api/bitwa_silnik.php`; stan w `bitwy_nastawy` i `bitwy_jednostki.cieplo_reaktora / dlawienie`, migracja 078).
Wszystkie liczby robocze [R]. **Wartości domyślne nie zmieniają przebiegu bitwy** (wektory zgodności bez zmian).

**Rozkazy:** suwak — `nastawa` (2 s), synchronizacja — `synchronizuj` (12 s, przełącznik), przełączenie zasilania —
`przelacz` (2 s, przełącznik), `wyrownaj` (12 s, grupa wraca do wartości domyślnych: baterie, reaktory, dystrybucja),
`wzmocnij` (3 s przygotowania, działa 12 s, odnowienie 24 s; przycisk sprężynowy, świeci, gdy działa).

**Bilans mocy** (jednostki: % mocy nominalnej rdzenia, liczony w każdym tiku):
- źródła: rdzeń 100 × (0,8 + 0,4·gęstość hipermaterii) × sprawność generatora (1 − 0,2·odchyłka częstotliwości
  i napięcia od 50%, +3% za każdą synchronizację rdzeń–generator) × wzmocnienia (rdzeń +15%, generator +10%) ×
  0,7 przy dławieniu, ograniczony suwakiem „maks. obciążenie generatora”; reaktor uzbrojenia 15 (+30% wzmocniony)
  — najpierw baterie, w zakresie min…maks., nadwyżka do puli tylko po synchronizacji z rdzeniem; przedni reaktor
  20 × moc (do puli albo — po przełączeniu — tylko systemy podstawowe); zapasowy 20 × moc (silniki po przełączeniu,
  połowa do puli po synchronizacji z silnikiem środkowym); rezerwa awaryjna (do 20) wstrzymana, dopóki któryś
  generator osłon nie przekroczy 90% pojemności;
- odbiorcy: osłony (pobór żył + 2 × pokrycie każdego z 7 sektorów + 4 za wzmocniony sektor + 1 za synchronizację;
  limit — suwak „przydział mocy dla osłon”), baterie (turbolasery 20 × przydział, +5 za wzmocnioną burtę; jony
  10 × przydział), silniki 15 × przydział, systemy podstawowe (sensory, hipernapęd 10 × przydział, pole komory 4 ×,
  promienie ściągające 3);
- niedobór: dzielony proporcjonalnie; synchronizacja generatora z konsolą osłon / silników daje im pierwszeństwo,
  rezerwa na salwę (do 10) — bateriom.

**Skutki:** tempo salwy baterii = przydział / bazowy × zasilenie (synchronizacja reaktora uzbrojenia z baterią burty
— burta poza niedoborem; wzmocnione zasilanie burty +10%); pojemność generatora osłon × zasilenie osłon (+8% za
synchronizację z baterią i z promieniem ściągającym tej burty, +10% przy wzmocnionej burcie); prędkość maks. okrętu
× zasilenie silników (do ×1,2).

**Sektory osłon:** strzał trafia w dziób, rufę albo burtę (górną lub dolną — los; dół przy śródokręciu to hangar).
Pokrycie granic < 100%: 20% trafień w sektor wypada na styk — przecieka 50% × (1 − pokrycie) energii zatrzymanej przez
osłonę. Wzmocniony sektor: do kadłuba 60% energii. Rufa: każda synchronizacja z silnikami tej burty — ×0,9.

**Ciepło rdzenia** (0…150%): rośnie przy wykorzystaniu puli > 85% (+2 × nadwyżka na tik) i we wzmocnieniach, maleje
poniżej. Pole komory (0…1) mnoży przyrost przez 1,5 − pole, synchronizacja rdzenia z pomocniczymi × 0,8. Od 100% —
dławienie (rdzeń daje 70%), koniec poniżej 60%. „Równaj częstotliwość z reaktorem” stroi oba generatory osłon na stopień
natywny najbliższy częstotliwości bazowej (suwak na konsoli Reaktory, 12 s).

**Turbolasery** (te same nastawy i rozkazy):
- Hz baterii burty: szary suwak = zakres (ułamek liczby żył baterii, stopień natywny w górę). Bez synchronizacji
  z konsolą sensorów bateria strzela maks. zakresu; z nią — najniższy stopień ≥ 1,5 × Hz osłony celu (w zakresie).
  Energia trafienia ∝ Hz, a celność rośnie przy niższych Hz (θ × (0,5 + 0,5 · Hz / N)).
- Synchronizacje: bateria z generatorem osłon tej burty — energia +10% (+2 do poboru); bateria z Kontrolą ognia —
  θ × 0,85; PDLC z konsolą myśliwców (IFF) — θ PDLC burty × 0,8.
- Grupy PDLC (dziób, przód i bok każdej burty, dół — co drugie działko śródokręcia, rufa): kąt pokrycia
  (0…1, domyślnie 0,5) mnoży łuk i θ przez 0,5 + kąt — szerzej, ale mniej celnie; wzmocnienie grupy θ / 1,5.
- Wzmocnienie baterii: energia × 1,25. „Wyrównaj baterie” — zakresy Hz i łuki PDLC wracają do domyślnych; „równaj
  z reaktorem” — maks. Hz obu baterii = częstotliwość bazowa.

**Kontrola ognia:**
- Tempo burty: 0,5 + tempo (w zakresie szarego suwaka) mnoży tempo salw i pobór baterii; wzmocnienie ognia burty
  × 1,25; „równaj tempo z mocą reaktora” obniża tempo obu burt do zasilenia baterii.
- Tryby burty: salwa (domyślnie); pojedynczy strzał — ¼ luf, θ × 0,7; ogień zaporowy — tempo × 1,5, θ × 1,5,
  +0,3 ciepła rdzenia na tik, trwa 10 + 50 × czas (s), potem sam wraca do salwy (zdarzenie „zaporowy”).
- Rozrzut burty r: θ × (0,5 + r) na cel stojący (v ≤ 0,05 km/s), × (1,5 − r) na manewrujący. Elewacja: θ × (1 + |e − 0,5|).
  Opóźnienie: prawa bateria przesunięta o ułamek odstępu salw. Synchronizacja z sensorami: θ × 0,9; IFF: θ PDLC × 0,9.
- Działa jonowe: natężenie (0,5 + n) mnoży energię i pobór; wzmocnienie — tempo × 1,5; obezwładnienie — trafienia
  zamiast osłon grzeją rdzeń celu (+3 × trafienia × (0,5 + n)), co może go zdławić. Wzmocnienie salwy: energia × 1,15.
- Każde wzmocnienie uzbrojenia: +4 do poboru baterii i +0,2 ciepła rdzenia na tik.
- Namiar i dystans: odczyt oznaczonego celu; dotknięcie oznacza okręt wroga najbliższy wskazanej wartości.

**Silniki i Nawigacja** (okręt gracza; okręty AI manewrują jak dotąd):
- Prędkość maks. = profil × zasilenie silników × ciąg (średnia: maks. lewego i prawego głównego, środkowy 100%) ×
  wzmocnienia głównych (+10% każde) × (1 − 0,2 · |trym pochylenia − 0,5|). Przyspieszenie × ciąg × wzmocnienia ×
  synchronizacje głównych burt ze środkowym (+10% każda). Minimalny ciąg burt wymusza prędkość ≥ profil × (min. L + min. P) / 3.
  Suwak środkowego silnika to dźwignia prędkości zadanej (rozkaz ster).
- Różnica maks. ciągu burt skręca okręt: (L − P) × 0,3 °/s × v / v_maks (więcej z lewej — w prawo). Trym odchylenia:
  stały dryf (t − 0,5) × 0,4 °/s.
- Prędkość kątowa skrętu w stronę burty: (0,5 + średni ciąg manewrowych burty) × (0,5 + moc manewrowych burty
  z Nawigacji), w zakresie szarych suwaków skrętu; wzmocnienie skrętu × 1,5, wzmocnienie manewrowego × 1,25 (każdy),
  wszystkich × 1,3, synchronizacje (manewrowe z głównym, Nawigacja z Silnikami) × 1,1; przechył w stronę skrętu × 1,15,
  w przeciwną × 0,9.
- Przesuw pod skosem: dysze manewrowe i kąt dysz burt wychylone od 50% pchają okręt w bok —
  0,02 × (suma prawych − suma lewych) km/s.
- Wzmocnienia napędu: +3 do poboru silników, +0,2 ciepła rdzenia na tik. „Wyrównaj ciąg silników” / grupa nawigacja —
  wartości domyślne; „równaj ciąg z mocą reaktora” obniża prędkość zadaną do tej, którą daje zasilanie silników.
  Synchronizacje głównych z osłoną rufową to te same co na pulpicie Osłony.

## 12. Decyzje do podjęcia (wspólne)

| # | Pytanie | Propozycja |
|---|---|---|
| 1 | Model obliczeń | leniwe doliczanie tików na serwerze przy odczycie |
| 2 | Pojemność generatora C i rykoszet | C = τ·N·P_ż, τ ≈ 0,25 s (ISD 1,3×10²³ J); rykoszet 1:1 |
| 3 | Kanały mocy i zasobniki | lista z rozdz. 2; zasobnik 10 porcji, ładowanie 1 porcja/cykl |
| 5 | Zużycie luf i ładowanie zależne od Hz | zużycie (Hz/450)^1,585 tylko przy ogniu ciągłym; ładowanie stałe z profilu |
| 6 | Model kadłuba | sekcje z integralnością i progami (rozdz. 4.5) |
| 7 | Czas wyłączenia systemów przez jon (t) | 12–36 tików |
| 8 | Rangi pilotów w walce | kadet / standardowy / as: k 0,25 / 0,25 / ⅓, przestrojenie 3 / 2 / 1 tik |
| 9 | Pole bitwy | lokalny układ w km, zakotwiczony w punkcie układu planetarnego |
| 10 | Ruch okrętu | parametry profilu (maks. przyspieszenie, prędkość kątowa, czas rozkręcenia obrotu) zamiast mas |
| 11 | Sensory a dzisiejsze tryby czujników | pasywny = bierny, dalekiego zasięgu / pełna moc = aktywny z różnym J |
| 12 | Znaczenie czasowników | tabela w PULPITY.md |
| 13 | Wróg powyżej 3600 Hz | tylko wyjątek fabularny |
| 14 | Telefon | tylko klucze myśliwców — ekrany Klucz i Eskadra, siatka 5×9 (ustalone 25.11.2026) |
| 16 | Sprawność baterii (energia salwy / pobór) | 0,4% zamierzone — reszta to ciepło (rozdz. 4.1) |

Decyzje dotyczące jednej klasy (dawne nr 4 i 15 — ISD; nowe — MC80) są w profilach. Numeracja zachowana z wersji
z 25.11.2026.

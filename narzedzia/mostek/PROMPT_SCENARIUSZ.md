# Prompt: nowy scenariusz symulacji bitwy (HOLOLINK)

Skopiuj wszystko od linii „PROMPT” do końca pliku do innej instancji (czat bez dostępu do repozytorium wystarczy),
dopisz na dole swój pomysł i odbierz gotowy słownik. Wklej go do listy `SCENARIUSZE` w
`narzedzia/mostek/dane/scenariusze.py`, potem zrób kroki z sekcji „Jak włączyć do gry” (koniec tego pliku).

---

## PROMPT

Jesteś projektantem scenariuszy symulacji bitew kosmicznych w grze HOLOLINK (realia Gwiezdnych Wojen, czas
zbliżony do bitwy o Yavin). Gracze siedzą przy konsolach mostka okrętu i grają w czasie rzeczywistym: rozkazy mają
czas wykonania (pasek ładowania), a skutek zależy od tego, kto wcześniej i trafniej zareaguje. Druga strona jest
prowadzona przez SI dowódcy. Uszkodzenia w symulacji nie przechodzą do świata gry.

Odpowiadaj po polsku. Zwróć **jeden** scenariusz jako słownik Pythona dokładnie w formacie szablonu niżej, a pod
nim krótko: co sprawdza scenariusz, jakie decyzje graczy są w nim kluczowe i jak powinna wyglądać wygrana.
Nie zmieniaj nazw kluczy ani kolejności pól w krotkach. Wartości spoza list dozwolonych są błędem.

### Układ współrzędnych i czas

- Pole bitwy 2D w kilometrach. Okręt graczy zwykle w (0, 0).
- Kurs w stopniach: 0° = oś +y („północ” ekranu), 90° = +x, 180° = −y, 270° (albo −90°) = −x.
  Okręt w (0, 100) lecący na okręt w (0, 0) ma kurs 180.
- Czasy w sekundach od startu symulacji. Rozrzut = losowe przesunięcie 0…rozrzut s (każda powtórka inna).
- 1 tik = 1 s (gracze tego nie widzą — dla nich liczy się tylko czas rzeczywisty).

### Szablon (wypełnij)

```python
    {
        'kod': 'nazwa_snake_case',          # do 40 znaków, unikalny, tylko a-z 0-9 _
        'nazwa': 'Nazwa widoczna w grze',   # do 160 znaków
        'odprawa': 'Tekst dla graczy przed startem: sytuacja i zadanie. Bez zdradzania sił i czasu wroga.',
        'opis_mg': 'Tylko dla MG: przebieg (kto, kiedy, skąd), jak zachowuje się SI, warianty dowódcy, '
                   'na co uważać przy prowadzeniu.',
        'okno_s': 1800,                     # długość symulacji w s (koniec najpóźniej wtedy)
        'pole_km': 200,                     # promień pola bitwy w km (zwykle 2× najdalszy obiekt)
        'zwyciestwo': 'Strona: warunek zwycięstwa (do 500 znaków).',
        'porazka': 'Strona: warunek porażki (do 500 znaków).',
        'status': 'roboczy',                # roboczy | gotowy | wycofany
        # strona, kolor (#rrggbb), dowodzenie, rola, asy %, kadeci %
        'strony': [
            ('Imperium', '#9fb4c8', 'gracze', 'obrona', 5, 35),
            ('Rebelia', '#ff6b55', 'calrissian', 'atak', 10, 30),
        ],
        # nr, strona, profil, nazwa, z_mapy, x, y, kurs, prędkość km/s, kadłub %, wejście s, rozrzut wejścia s
        'jednostki': [
            (1, 'Imperium', 'ISD_I', 'Walako', 1, 0, 0, 0, 0, 100, 0, 0),
            (2, 'Rebelia', 'MC80', 'krążownik rebelii', 0, 0, 100, 180, 0.5, 100, 120, 20),
        ],
        # fala, strona, jednostka_nr (okręt matka albo None), typ maszyny, liczba kluczy, start s, rozrzut s,
        # x, y (None = z hangaru okrętu matki; liczby = klucze wychodzą z nadprzestrzeni w tym punkcie), rozkaz
        'klucze': [
            (1, 'Imperium', 1, 'TIE_LN', 4, 0, 30, None, None, 'ochrona'),
            (1, 'Rebelia', 2, 'X_WING', 5, 145, 10, None, None, 'atak'),
        ],
        # nr, czas s, rozrzut s, rodzaj, treść
        'wyzwalacze': [
            (1, 0, 0, 'komunikat', 'Sensory: zmarszczka nadprzestrzeni ok. 100 km od Walako.'),
            (2, 1800, 0, 'koniec', 'Koniec symulacji.'),
        ],
    },
```

### Pola i wartości dozwolone

**strony** — dokładnie jedna strona ma `dowodzenie = 'gracze'`; nazwa strony (do 120 znaków) musi być identyczna
we wszystkich listach. Kolory: Imperium `#9fb4c8`, Rebelia `#ff6b55`, inne frakcje — dowolny jasny kolor.
- `dowodzenie`: `gracze` | `radus` | `calrissian` | `ackbar` (dowódcy SI — opis niżej).
- `rola`: `obrona` | `atak` (tylko opis i ikona; zachowanie wynika z dowódcy).
- `asy %`, `kadeci %`: udział rang pilotów (reszta to „standard”); suma ≤ 100. Asy szybciej skręcają, mają krótszy
  czas rozkazów (−1 s) i spiralę; kadeci +1 s.

**jednostki** (okręty) — `nr` 1–255, unikalny w scenariuszu.
- `profil`: `ISD_I` | `MC80` | `ZBIEG` (inne profile wymagają nowych danych — opisz pomysł słownie).

| profil | co to | długość | osłony | Hz osłon (start / max) | myśliwce | obrót |
|---|---|---|---|---|---|---|
| ISD_I | Gwiezdny Niszczyciel Imperial I | 1600 m | 2 generatory | 1800 / 3600 | 72 (18 kluczy) | 30°/min |
| MC80 | krążownik Mon Calamari (profil roboczy) | 1200 m | 6 generatorów | 729 / 2187 | 36 (9 kluczy) | 40°/min |
| ZBIEG | kurier piracki HLK-P1 | 40 m | 1 generator | 900 | 0 | 60°/min |

- `z_mapy`: 1 tylko dla okrętu, który istnieje na mapie gry (Walako) — inaczej 0.
- `prędkość`: 0 = stoi; okręty 0,2–0,6 km/s. Okręt SI i tak manewruje sam (SI okrętu), prędkość to jego „cała naprzód”.
- `kadłub %`: 1–100 (np. 60 = wchodzi do bitwy uszkodzony). Poniżej 25% okręt jest unieszkodliwiony.
- `wejście s` > 0 = okręt czeka w nadprzestrzeni i wychodzi z niej po tym czasie. Sensory graczy widzą zmarszczkę
  nadprzestrzeni wcześniej (ostrzeżenie) — dobry moment na komunikat.

**klucze** (4 maszyny w kluczu) — `fala` 1–255 (kolejność startów w ramach strony), `liczba kluczy` 1–18.
- `jednostka_nr`: okręt matka (musi być w `jednostki` i mieć hangar) albo `None` dla kluczy bez okrętu (wtedy podaj x, y).
- Z jednego okrętu startuje najwyżej 1 klucz na minutę (taśmociąg) — 9 kluczy z MC80 to co najmniej 8 minut.
- `typ` maszyny:

| typ | strona | Hz broni | osłona Hz | v km/s | uwagi |
|---|---|---|---|---|---|
| TIE_LN | Imperium | 600 | 0 | 1,05 | tani, liczny |
| TIE_IN | Imperium | 900 | 0 | 1,25 | najszybszy TIE |
| TIE_SA | Imperium | 1500 | 0 | 0,80 | bombowiec |
| LAMBDA | Imperium | 300 | 600 | 0,85 | prom, słaby |
| X_WING | Rebelia | 1200 | 900 | 1,00 | torpedy protonowe |
| Y_WING | Rebelia | 1600 | 1200 | 0,80 | torpedy, przebija 1800 Hz o ~10% |
| A_WING | Rebelia | 800 | 600 | 1,30 | najszybszy, mały przekrój |
| B_WING | Rebelia | 2400 | 1500 | 0,85 | torpedy, przebija 1800 Hz |

  Osłona okrętu zatrzymuje strzały o Hz niższym niż jej Hz osiągane; wyższe Hz przechodzą częściowo. Torpedy mnożą
  Hz × 1,5 i energię × 8 — dlatego bombowce wymuszają przestrojenie osłon w górę.
- `rozkaz` startowy: `ochrona` (krąży 3 km przy matce, broni jej w 8 km) | `patrol` (8 km, broni w 15 km) |
  `atak` (myśliwce wroga do 12 km, potem okręt) | `nalot` (prosto na najbliższy okręt, ignoruje myśliwce) |
  `skan` (krąży 10 km od wrogiego okrętu) | `powrot`.

**wyzwalacze** — `nr` unikalny; `rodzaj`: `komunikat` (tekst na konsolach) | `posilki` (komunikat o posiłkach,
zwykle razem z końcem) | `koniec` (kończy symulację). Zawsze dodaj `koniec` najpóźniej w `okno_s`.

### SI dowódców (to, co odpowiada na decyzje graczy)

SI wydaje rozkazy tak jak gracze — z tym samym czasem wykonania, więc spóźniona reakcja SI jest widoczna.

| dowódca | okręt: dystans walki | odwrót przy kadłubie | skłonność do uników | bombowce (X/Y/B-wing) |
|---|---|---|---|---|
| radus | 6 km (agresywny) | 15% | mała | zawsze nalot z torpedami |
| calrissian | 10 km | 35% | średnia | nalot, gdy torpedy (Hz ×1,5) przebiją osłony graczy; inaczej walka z myśliwcami |
| ackbar | 18 km (ostrożny) | 55% | duża | nalot tylko gdy przebije i w 10 km nie ma myśliwców wroga; inaczej ochrona okrętu |

Reakcje SI:
- **Okręt SI** idzie na najbliższy okręt graczy, w dystansie walki ustawia się burtą — tą, której osłony są mniej
  obciążone — i obraca się, gdy gracze przeciążą jedną stronę. Poniżej progu odwrotu odchodzi, a dalej niż 60 km od
  wrogów wycofuje się z bitwy (licz to w warunkach zwycięstwa: „unieszkodliwiony albo wycofany”).
- **Klucze SI**: wróg na ogonie (≤ 1,5 km za rufą) → unik (as: spirala; inni: split-S albo beczka); przestrojenie
  osłon graczy zmienia wybór nalot/walka; w nalocie z okrętem w stożku do 3 km → torpedy; przy okręcie → beczki,
  zygzak, przelot przez cel. Myśliwce SI mogą robić te same figury co gracze, ale też inne (torpedy, nalot).
- Osłony okrętu SI stroją się automatycznie pod najwyższe Hz, które mierzą sensory.

### Zasady projektu (sprawdź przed oddaniem)

1. Jedna strona `gracze`, reszta SI; nazwy stron identyczne wszędzie.
2. Każdy `jednostka_nr` w kluczach istnieje w `jednostki` (albo `None` + x, y).
3. Wróg nie zaczyna w zasięgu ognia: okręty ≥ 40 km, myśliwce ≥ 30 km od okrętu graczy (chyba że to zasadzka i
   opis_mg to mówi). Dobra praktyka: ostrzeżenie (zmarszczka, komunikat) 60–120 s przed wejściem wroga.
4. Liczba kluczy z okrętu ≤ jego myśliwce / 4; pamiętaj o taśmociągu (1 klucz/min).
5. Gracze potrzebują czasu: rozkaz to 1–12 s ładowania, start klucza 1 s + taśmociąg, przestrojenie osłon 3–12 s.
6. Warunki zwycięstwa i porażki mierzalne: kadłub %, liczba zestrzelonych maszyn, czas, unieszkodliwienie/wycofanie.
7. `odprawa` nie zdradza dokładnych sił ani czasów wroga; `opis_mg` opisuje wszystko, w tym reakcje SI i wariant
   z innym dowódcą.
8. Liczby: x, y z dokładnością do 0,01 km, kurs do 0,01°, prędkość do 0,001 km/s; czasy w pełnych sekundach.

### Przykład wzorcowy (cały, poprawny)

```python
    {
        'kod': 'walako_atak',
        'nazwa': 'Atak na Walako',
        'odprawa': 'Walako (ISD I) pełni wachtę w układzie. Wywiad floty ostrzega przed ruchem sił rebelii w sektorze. '
                   'Zadanie: utrzymać pozycję i okręt do przybycia posiłków, spodziewanych w ciągu godziny.',
        'opis_mg': 'Od startu sensory Walako widzą zmarszczkę nadprzestrzeni ok. 100 km od okrętu — załoga ma ok. '
                   '2 minuty na alarm, start kluczy i dostrojenie osłon. MC80 wychodzi ze skoku po 120–140 s, klucze '
                   'rebelii startują z niego od 145 s. SI (Calrissian): MC80 staje ok. 10 km od Walako burtą o mniej '
                   'obciążonych osłonach; poniżej 35% kadłuba odchodzi. Y-wingi i B-wing robią nalot z torpedami tylko, '
                   'gdy przebiją osłony Walako. Wariant: radus (6 km, nalot zawsze) albo ackbar (18 km, ostrożny).',
        'okno_s': 3600, 'pole_km': 200,
        'zwyciestwo': 'Imperium: Walako utrzymany do przybycia posiłków (kadłub co najmniej 25%) albo krążownik rebelii '
                      'unieszkodliwiony lub wycofany.',
        'porazka': 'Imperium: kadłub Walako poniżej 25% przed przybyciem posiłków.',
        'status': 'roboczy',
        'strony': [
            ('Imperium', '#9fb4c8', 'gracze', 'obrona', 5, 35),
            ('Rebelia', '#ff6b55', 'calrissian', 'atak', 10, 30),
        ],
        'jednostki': [
            (1, 'Imperium', 'ISD_I', 'Walako', 1, 0, 0, 0, 0, 100, 0, 0),
            (2, 'Rebelia', 'MC80', 'krążownik rebelii', 0, 0, 100, 180, 0.5, 100, 120, 20),
        ],
        'klucze': [
            (1, 'Imperium', 1, 'TIE_LN', 4, 0, 30, None, None, 'ochrona'),
            (2, 'Imperium', 1, 'TIE_LN', 6, 60, 60, None, None, 'ochrona'),
            (3, 'Imperium', 1, 'TIE_LN', 8, 150, 90, None, None, 'ochrona'),
            (1, 'Rebelia', 2, 'X_WING', 5, 145, 10, None, None, 'atak'),
            (1, 'Rebelia', 2, 'Y_WING', 2, 145, 10, None, None, 'atak'),
            (1, 'Rebelia', 2, 'A_WING', 1, 145, 10, None, None, 'atak'),
            (1, 'Rebelia', 2, 'B_WING', 1, 145, 10, None, None, 'atak'),
        ],
        'wyzwalacze': [
            (1, 0, 0, 'komunikat', 'Sensory dalekiego zasięgu: zmarszczka nadprzestrzeni ok. 100 km od Walako — duża masa.'),
            (4, 120, 20, 'komunikat', 'Wyjście z nadprzestrzeni 100 km od Walako: krążownik rebelii. Alarm bojowy.'),
            (2, 3600, 0, 'posilki', 'Posiłki Imperium wychodzą z nadprzestrzeni.'),
            (3, 3600, 0, 'koniec', 'Koniec symulacji: przybycie posiłków.'),
        ],
    },
```

### Mój pomysł na scenariusz

(opisz tu: kto gra, przeciw komu, miejsce, czego ma nauczyć, jak długo, czy jest zasadzka / posiłki / ucieczka)

---

## Jak włączyć do gry (dla Ciebie, nie dla instancji)

1. Wklej słownik do listy `SCENARIUSZE` w `narzedzia/mostek/dane/scenariusze.py` (przed zamykającym `]`).
2. W `narzedzia/mostek`: `python test_silnik.py` — sprawdza, czy scenariusz się buduje i liczy (przebieg 1 h).
3. `python dane_sql.py` — odświeża `api/migracje/069_mostek_dane.sql`; zaimportuj go w bazie (zastępuje scenariusze).
4. Scenariusz pojawia się w aplikacji Symulacje (ikona na pulpicie dla upoważnionych). Status `gotowy` ustaw po
   sprawdzeniu w grze.

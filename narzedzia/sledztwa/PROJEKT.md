# Śledztwa droida detektywistycznego — projekt

Odnoga HOLOLINK. Logowanie bez zmian. Konto wpisane do tabeli `sledztwo_detektywi` (po loginie) po zalogowaniu
widzi pulpit sterowania droidem (makieta: skan przestrzenny DT-4) zamiast zwykłego pulpitu. Ta sama baza SQL.
Schemat: `api/migracje/082_sledztwa_schemat.sql`.

## 1. Typ śledztwa, jawność, wyczyn

- Typ = **prawdziwe zdarzenie × relacja** (12 × 12 = 144), np. „Szantaż × Szpiedzy”.
- **Jawność sprawy**: jawna — gracza wezwano do tego, co naprawdę zaszło; ukryta — wezwano go do innego zdarzenia
  (`zdarzenie_zgloszone_id`), a ktoś (`ukrywa_kod`) zasłania prawdę. Przykład: zgłoszenie gwałtu, a naprawdę
  kradzież, bo ofiara chce coś ukryć.
- **Jawność faz**: każda faza jest jawna albo ukryta. Ten sam wariant działa inaczej: pościg jawny = list gończy,
  ukryty = ciche śledzenie po tropach. Teksty zachowań NPC dla obu przypadków: `sledztwo_fazy_szablon.npc_jawna`
  i `npc_ukryta` — trafiają do promptów NPC i decydują, kto co mówi, kto kłamie, kto milczy.
- Gracz dostaje dany typ najwyżej raz (`UNIQUE (konto_login, zdarzenie_id, relacja_id)`); losowanie tylko z typów,
  których jeszcze nie ma. Wyczyn: siatka 12 × 12 rozwiązanych.
- **Porzucenie**: `porzucone` → kolejka MG, typ dalej zajęty. Gracz pisze uzasadnienie → `wznowienie_prosba` →
  MG (ewentualnie po zmianach) przywraca `trwa`.

## 2. Szkielet 12 faz

| # | Faza | Przestawna | Część |
|---|------|-----------|-------|
| 1 | Zawiązanie | – | ukryta prawda |
| 2 | Powód śledztwa | – | ukryta prawda |
| 3 | Przedsięwzięcie i jego wynik | – | ukryta prawda |
| 4 | Presja z zewnątrz | ✓ | ukryta prawda |
| 5 | Zasoby | ✓ | ukryta prawda |
| 6 | Pościg | ✓ | ukryta prawda |
| 7 | Kryjówka i motyw | ✓ | ukryta prawda |
| 8 | Fałszywy trop | ✓ | przebieg |
| 9 | Świadek | ✓ | przebieg |
| 10 | Przeszkoda | ✓ | przebieg |
| 11 | Wyścig | – | przebieg |
| 12 | Finał (wybór gracza) | – | przebieg |

Każda faza ma 12 wariantów (`sledztwo_warianty`) z tagami `wymaga` / `daje` i dopuszczalną jawnością.

## 3. Tworzenie śledztwa

### Krok 1 — baza danych (PHP, bez AI)
Wszystko, co czyni śledztwo wyjątkowym, losuje serwer z `seed` i pul w bazie:

1. Typ (zdarzenie × relacja) spośród niezdobytych; jawność sprawy; przy ukrytej — zdarzenie zgłoszone i kto ukrywa.
2. Warianty faz (k12 zgodne z tagami), jawność każdej fazy, kolejność faz 4–10 (każda faza wymagająca tagu
   po fazie, która go daje).
3. Skupisko na Nar Shaddaa (`lokalizacje`) i miejsca fabularne: rodzaj (`gen_rodzaje_miejsc`, zgodnie z poziomem),
   poziom, numer pokoju, nazwa lokalu.
4. Postacie kluczowe (A, B, a jeśli warianty wymagają — Z zleceniodawca, S śledzący, W świadek) i poboczne:
   gatunek wg `gen_populacja`, płeć, wiek, imię / nazwisko / przydomek (`gen_imiona`), archetyp (`gen_archetypy`),
   2–3 cechy charakteru (`gen_charakter`), **karta EotE** (cechy gatunku + premie archetypu + umiejętności
   + bronie; format jak `osoby_karta_eote.dane`), ekwipunek i kredyty, przedmioty-dowody (`gen_przedmioty`).

Wynik: stan `pisanie`. Szkielet jest kompletny — AI niczego nie losuje i nie wymyśla imion ani miejsc.

### Krok 2 — model „Autor” (API)
Dostawca zgodny z API OpenAI (OpenRouter `:free`, Groq, Gemini, Ollama); konfiguracja w
`api/konfiguracja-ai.php` poza repo. Mały model dostaje wąskie zadania z wymuszonym JSON-em, walidowanym przez PHP
(zły JSON → ponowienie, max 3, potem stan `blad` do MG):

1. Treść faz 1 → 12 (3–6 zdań każda), z wylosowanymi postaciami, miejscami i jawnością; tylko streszczenie
   poprzednich faz w kontekście.
2. Opisy i plany miejsc fabularnych.
3. Historie kluczowych NPC (bardzo szczegółowe) + wiedza: co wiedzą, co powiedzą przy jawnej / ukrytej fazie,
   kiedy kłamią.
4. Ślady faz 1–10: fakt, nośnik (log, notatka, wypowiedź), słowa kluczowe; fałszywe ślady fazy 8.
5. Zaczyn dla gracza: zlecenie (przy ukrytej sprawie — wersja zgłoszona), tło, kim są ludzie, od czego zacząć.

Prompty bazowe: `sledztwo_fazy_szablon.prompt_bazowy` + `sledztwo_zdarzenia.wskazowki_autora`.

### Krok 3 — w grze
- **Droid** (model AI) nie zna prawdy ani słów kluczowych. Procedura bazowa: wypisz z tekstu konkretne fakty
  (kto, gdzie, kiedy, ile, nazwy, liczby), nie zgaduj. **Serwer** porównuje je ze `slowa_kluczowe`
  (małe litery, bez polskich znaków, rdzenie). Trafienie → ślad odkryty → wpis `koncentrat`. Słaby model nie
  „wymyśli” postępu.
- **Rozmowa AI–AI** z kluczowym NPC: model gra NPC (historia, wiedza, charakter, jawność faz), droid pyta wg
  procedury; gracz dopisuje polecenia („spytaj go o dług u Huttów”).
- **Poboczni NPC**: bez AI — `gen_kwestie_szumu` („Dzień dobry.”, „Nie wiem, jak panu pomóc.”).

## 4. Przestrzeń i ruch

- Skupisko: kompleks widoczny na mapie Nar Shaddaa — lądowisko, hangar, sklepy, kantyny, kasyna; niżej nory
  slicerów, magazyny.
- Miejsca fabularne: konkretny poziom + pokój (np. poziom 12, pokój 1256).
- Szum: każde inne (lokalizacja, poziom, pokój) generuje PHP deterministycznie z `hash(seed, …)` — rodzaj z
  `gen_rodzaje_miejsc`, opis szumu, poboczni NPC, zero śladów. Po wizycie kopia w `sledztwo_szum`.
- Droid idzie tempem chodu (proponowane 1,4 m/s; winda 20 s / poziom + oczekiwanie). Gracz wskazuje cel, serwer
  liczy `dotrze_utc`; po dotarciu droid sam skanuje i melduje. W tym czasie gracz czyta zaczyn i koncentrat.

### Ruch i skan — jak działa (etap 1 sterowania na żywo, `api/sledztwa.php`)

- Cel: dowolny budynek / lądowisko **skupiska**, poziom 1–120, pokój albo korytarz. Inne kompleksy — później
  (dystanse między sektorami to setki km; potrzebna taksówka powietrzna).
- Czas podróży (1:1): wyjście 20 s + odległość między budynkami (`x_km`, `y_km`) / 1,4 m/s + przy zmianie poziomu
  30 s czekania na windę i 20 s / poziom + dojście do pokoju 20 s. Ten sam poziom i budynek: 30 s.
- Nowy rozkaz w drodze: przed połową trasy droid zawraca z punktu startu, po połowie — rusza z poprzedniego celu.
- Dotarcie rozlicza serwer przy najbliższym odczycie (nie trzeba crona): pozycja, meldunek w dzienniku, wpis
  `sledztwo_szum` dla miejsc spoza fabuły.
- Plan piętra deterministyczny z `crc32(seed|budynek|poziom)`: 10–18 pokoi po obu stronach korytarza, drzwi,
  terminale (publiczne T1, T2…), rodzaj każdego pokoju (z `gen_rodzaje_miejsc` wg poziomu; częściej rodzaj budynku).
  Pokoje fabuły zawsze są na planie. Kontakty K-1…: postacie z `sledztwo_postacie` (miejsce fabularne) albo
  przypadkowi przechodnie (szum, bez zapisu). Droid widzi kontakty w zasięgu 230 j. (pokój = 60 j. szerokości).
  Kontakt to sylwetka (gatunek, wygląd zawodu) — imienia droid nie zna.

### Presety budynków i wstęp (migracja 086)

- Budynek dostaje preset (`gen_presety_budynkow`) po słowach w nazwie albo po `lokalizacje.rodzaj`: hotel,
  kantyna, sklep, targ, hangar, lądowisko, kasyno, biurowiec, magazyn, blok mieszkalny; reszta — „budynek”.
  Preset ustala poziom parteru i liczbę pięter (deterministycznie z ziarna sprawy; poziomy fabuły poszerzają budynek).
- Plan piętra = lokale presetu dla strefy (`gen_presety_lokali`): parter, piętro, najwyższe piętro; poziomy spoza
  budynku to strefa techniczna (rozdzielnie, szyby). Kolejność od windy (np. recepcja hotelu zawsze przy windzie,
  pokoje gości wzdłuż korytarza). Lokale mają szerokość (hala hangaru = 3 pokoje), szansę zamka i terminala,
  liczbę bywających ludzi i typ bywalców.
- Wstęp: `wolny` (czasem zamknięte drzwi), `nakaz` (np. hala hangaru, wieża kontroli, kasa kasyna, archiwum) —
  tylko z nakazem; `straz` (warsztat, płyta lądowiska, biuro ochrony, loża) — z nakazem albo po przekonaniu
  strażnika. Strażnik stoi na korytarzu przy drzwiach (kontakt; można z nim rozmawiać), ma kartę EotE z archetypu
  presetu, Intelekt często niski (opis: „wygląda na mało rozgarniętego”).
- Przekonanie strażnika = test przeciwstawny EotE (pełne kości: zdolność, biegłość, trudność, wyzwanie):
  blef — Spryt + Oszustwo droida przeciw Intelektowi + Czujności strażnika; Urok przeciw Prezencji + Opanowaniu;
  Zastraszanie przeciw Sile Woli + Dyscyplinie. Sukces = przepustka na 24 h i droid wchodzi. Porażka: tym samym
  sposobem dopiero za godzinę, po 2 porażkach w godzinie — koniec rozmów; rozpacz = zakaz wstępu na dobę.
  Karta droida: `sledztwo_detektywi.droid_karta_eote` albo domyślna (Intelekt 3, Spryt 2, Wola 2, Prezencja 1).
- Nakaz: detektyw składa wniosek z uzasadnieniem (budynek albo cały kompleks), MG wydaje albo odrzuca w zakładce
  Konta; decyzja trafia do dziennika detektywa jako meldunek biura.
- Meldunek po wejściu wygląda tak samo w lokalu fabularnym i w szumie (rodzaj + opis); o fabule świadczą ludzie i ślady.
- Generator kładzie miejsca fabuły w budynkach o zgodnym presecie (kantyna w kantynie, hangar w hangarze), na ich
  piętrach, z wagami fabularnymi (lokale publiczne; mieszkania rzadko — głównie jako kryjówki).

### Wspólna mapa: lokacje precyzują się w kontekście (decyzja autora, 27.11)

- Śledztwa korzystają ze **wspólnej mapy** (`lokalizacje`). Jeśli sprawa potrzebuje budynku, którego kompleks nie
  ma (np. hangaru), generator dopisuje go przy zapisie sprawy jako prawdziwy wpis w `lokalizacje` (publiczny,
  widoczny na mapie, dziecko kompleksu, współrzędne obok kompleksu). Kolejne sprawy w tym kompleksie używają go.
  Wszystko, co nie wystąpiło w żadnym kontekście, zostaje szumem (bez zapisu).
- Budynek wygląda tak samo dla wszystkich: profil (parter, piętra), plan pięter, strażnicy i przechodnie są
  liczeni ze **stałego ziarna świata** (`SLEDZTWO_SWIAT`) i nazwy / id budynku, nie z ziarna sprawy. Ziarno sprawy
  losuje tylko to, co należy do sprawy (typ, fazy, miejsca fabuły, postacie). Miejsce fabuły zastępuje na planie
  lokal tego samego typu — dla innych spraw w tym miejscu jest zwykły lokal.

### Docelowo: duże śledztwa multiplayer — do 12 droidów naraz

Do zmiany, gdy przyjdzie pora (dziś model to jedna sprawa = jeden detektyw = jeden droid):
- `sledztwa.konto_login` → tabela uczestników sprawy (konto, rola, droid); wyczyn liczony każdemu uczestnikowi.
- `sledztwo_droid` (klucz: sprawa) → klucz (sprawa, droid); każdy droid ma własną pozycję, cel i rozmowę;
  rozkazy i blokady per droid (`sl_rozlicz_droida`, `sl_rozkaz_ruchu`).
- Rozmowy: jeden NPC może rozmawiać z jednym droidem naraz (blokada na postaci), reszta czeka albo słucha.
- Dziennik i koncentrat wspólne dla zespołu; meldunki z oznaczeniem droida. Przepustki strażników i nakazy —
  na sprawę (cały zespół) albo na droida — do decyzji.
- Generator dużych spraw: więcej miejsc i postaci (kilka kompleksów naraz), równoległe wątki faz.
- Czas 1:1 i wspólna mapa już teraz działają dla wielu graczy (ten sam świat, ten sam plan budynków).

## 4a. Zasady z ustaleń autora (wątek ChatGPT, 27.11) — obowiązują w śledztwach droida

- **Czas 1:1.** Sekunda realna = sekunda w grze; pora dnia z zegara świata (noc na księżycu = noc w grze).
  Rozmowa trwa tyle, ile trwa. Świat działa, gdy gracz nie patrzy: NPC zmieniają miejsca, plotki krążą.
- **Nieaktywność:** do 7 dni bez skutków; po miesiącu skutki (w przygodzie gracza: rynsztok bez ekwipunku;
  dla droida — do ustalenia, np. sprawa przechodzi do MG jako porzucona).
- **Punkty relacji** każdego NPC wobec detektywa / droida, niezależne osie: W1–W3 wkurw, S1–S3 strach,
  Z1–Z3 zachwyt (np. W2 + S2 naraz). Relacje rozchodzą się drogami informacji (bezpośrednio, plotka, ta sama
  organizacja, polecenie przełożonego) — wkurzony barman → nieufny ochroniarz; nie każdy wie wszystko.
- **Testy EotE** rozstrzygają interakcje: Zastraszanie przeciw Dyscyplinie, Urok przeciw Opanowaniu, Oszustwo
  przeciw Czujności, Negocjacje przeciw Negocjacjom; Odporność i wysoka Siła Woli utrudniają zastraszenie.
  Wynik testu → punkty relacji (udane zastraszenie może dać S2 i W1). Każdy NPC ma kartę EotE (generator już je
  tworzy; kluczowi pełne, statyści uproszczone). NPC też wykonują testy (Skradanie, Oszustwo, Percepcja…).
- **AI tylko gra NPC**: interpretuje wypowiedź (intencja: groźba, pochlebstwo, kłamstwo, negocjacja), mówi;
  fakty, testy, punkty i postęp liczy PHP i zapisuje SQL. AI nie przyznaje punktów samo.
- **Terminale i dane:** publiczny terminal — tanio, ale właściciel danych dowiaduje się, gdzie i kiedy;
  slicer — drogo, dyskretnie (może oszukać albo współpracować z właścicielem); samodzielnie — test Komputerów,
  warstwy szyfru, częściowy odczyt.
- **Zleceniodawcę można udobruchać** (obietnica, pierwsze informacje), fałszywe informacje budzą podejrzliwość.

## 5. Pulpit detektywa (kolejne etapy)

1. Skan przestrzenny piętra (makieta DT-4). 2. Dziennik. 3. Koncentrat i tablica powiązań.
4. Akta (zaczyn, postacie, miejsca). 5. Raport końcowy (ocena = pokrycie faz 1–7).

## 6. Do ustalenia

- Pełne listy 12 zdarzeń i 12 relacji (w migracji robocze).
- 12 wariantów każdej fazy z tagami — pierwsza wersja w migracji 084 (do poprawek autora; kontrola: `narzedzia/sledztwa/sprawdz_warianty.pl`).
- Rozbudowa pul: imiona i nazwiska (dziesiątki na gatunek), archetypy, przedmioty, kwestie.
- Dostawca i model AI.

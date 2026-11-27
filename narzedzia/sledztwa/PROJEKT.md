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

## 5. Pulpit detektywa (kolejne etapy)

1. Skan przestrzenny piętra (makieta DT-4). 2. Dziennik. 3. Koncentrat i tablica powiązań.
4. Akta (zaczyn, postacie, miejsca). 5. Raport końcowy (ocena = pokrycie faz 1–7).

## 6. Do ustalenia

- Pełne listy 12 zdarzeń i 12 relacji (w migracji robocze).
- 12 wariantów każdej fazy z tagami (`sledztwo_warianty` — pusta).
- Rozbudowa pul: imiona i nazwiska (dziesiątki na gatunek), archetypy, przedmioty, kwestie.
- Dostawca i model AI.

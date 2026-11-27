# Śledztwa droida detektywistycznego — projekt

Odnoga HOLOLINK. Logowanie bez zmian; konto z `"rola": "detektyw"` (wpis w `datapad_dane.uzytkownicy`)
po zalogowaniu widzi pulpit sterowania droidem (makieta: skan przestrzenny DT-4). Ta sama baza SQL.
Schemat: `api/migracje/082_sledztwa_schemat.sql`.

## 1. Typ śledztwa i wyczyn

- Typ początkowy = **zdarzenie × relacja** (12 × 12 = 144), np. „Szantaż × Szpiedzy”.
- Gracz dostaje dany typ najwyżej raz (`UNIQUE (konto_uid, zdarzenie_id, relacja_id)`).
  Losowanie: z typów, których gracz jeszcze nie ma.
- Wyczyn: siatka 12 × 12 ze stanami `rozwiazane`; komplet = 144.
- **Porzucenie**: stan `porzucone`, sprawa trafia do kolejki MG. Typ dalej zajęty.
  Gracz pisze uzasadnienie → `wznowienie_prosba` → MG (ewentualnie po zmianach w treści) przywraca `trwa`.

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

Każda faza ma 12 wariantów (`warianty_json`) z tagami `wymaga` / `daje`. Generator losuje k12 spośród wariantów
zgodnych z dotychczasowymi tagami, potem permutuje fazy 4–10 tak, by każda faza wymagająca tagu stała po fazie,
która go daje. Wynik zapisany w `sledztwa.kolejnosc_json`. Losowanie (warianty, kolejność, imiona) robi PHP z `seed`
— nie AI; AI tylko pisze treść do już wybranego szkieletu.

## 3. Dwa modele AI

Dostawca dowolny, zgodny z API OpenAI (`/chat/completions`): OpenRouter (modele `:free`), Groq, Gemini (warstwa
zgodności OpenAI), lokalna Ollama. Konfiguracja w `api/konfiguracja-ai.php` (poza repo, jak hasło bazy):
`adres`, `model_autor`, `model_droid`, `klucz`.

### Autor (przy tworzeniu śledztwa, poza grą)
Mały model nie napisze dobrze całej sprawy naraz, więc generacja idzie **krokami**, każdy z wąskim zadaniem
i wymuszonym JSON-em, walidowanym przez PHP (zły JSON → ponowienie, max 3, potem stan `blad` do MG):

1. Postacie A, B (+ Z, S, jeśli tagi ich wymagają): imię, gatunek, wygląd, sposób mówienia.
2. Faza po fazie (1 → 12): 3–6 zdań treści; dostaje streszczenie poprzednich faz, nie całość.
3. Miejsca fabularne: dla każdej fazy, która ich wymaga — budynek w skupisku, poziom, pokój, opis, plan.
4. Historia każdego **kluczowego** NPC: bardzo szczegółowa, plus `wiedza_json` (co wie, kiedy powie, kiedy kłamie).
5. Ślady: dla faz 1–10 po 2–4 śladach: `fakt`, `nosnik` (treść logu / notatki / wypowiedzi), `slowa_kluczowe`.
6. Zaczyn: duży wstęp dla gracza (zlecenie, tło, kim są ludzie, od czego zacząć) — bez ujawniania faz 3–7.

Każdy krok ma osobny prompt bazowy w `sledztwo_fazy_szablon.prompt_bazowy` (rola, zasady świata 3 BBY,
format wyjścia z przykładem, zakazy: nie zmieniaj imion, nie dodawaj postaci spoza listy, pisz po polsku).

### Droid (w grze)
Nie zna prawdy ani słów kluczowych. Ma procedurę bazową: „z tekstu wypisz konkretne fakty: kto, gdzie, kiedy,
ile, nazwy, liczby; nie zgaduj”. Działa na tym, co droid realnie zobaczył (opis miejsca, nośnik śladu, rozmowa).
**Serwer** porównuje wyłapane fakty ze `slowa_kluczowe_json` (normalizacja: małe litery, bez polskich znaków,
rdzenie słów). Trafienie → ślad `odkryty_utc`, wpis `koncentrat` w dzienniku. Dzięki temu słaby model nie może
„wymyślić” postępu — postęp daje tylko trafienie w ukryty klucz.

### Rozmowa AI–AI
- Kluczowy NPC: model gra NPC z jego `historia` + `wiedza_json` + `sposob_mowienia`; droid zadaje pytania wg
  procedury (kim jesteś, gdzie był(a) X, kiedy, z kim). 4–8 wymian, potem ekstrakcja faktów jak wyżej.
- Gracz wpływa pisząc polecenie w grze („spytaj go o dług u Huttów”) — trafia do kolejki pytań droida.
- Poboczny NPC (szum): **bez wywołania AI**, odpowiedzi z puli zdawkowych kwestii
  („Dzień dobry.”, „Nie wiem, jak panu pomóc.”). Oszczędza limity darmowego modelu.

## 4. Przestrzeń

- **Skupisko** (`sledztwa.skupisko_id`): kompleks z `lokalizacje` na Nar Shaddaa (widoczny na mapie): lądowisko,
  hangar, sklepy, kantyny, kasyna; niższe poziomy: nory slicerów, magazyny.
- **Miejsca fabularne** (`sledztwo_miejsca`): konkretny poziom + pokój (np. poziom 12, pokój 1256).
- **Szum**: każde inne (skupisko, poziom, pokój) generuje deterministycznie PHP z `hash(seed, lokalizacja, poziom,
  pokój)` — rodzaj lokalu, kilku pobocznych NPC, zero śladów. Po pierwszej wizycie kopia w `sledztwo_szum`.
  Droid może tam pojechać (np. windą na poziom 11), ale to strata czasu.

## 5. Ruch droida

Tempo chodu (proponowane: 1,4 m/s; winda: 20 s na poziom + oczekiwanie). Gracz wskazuje cel → serwer liczy
`dotrze_utc` i zapisuje `w_drodze`. Pozycja w trakcie liczona z czasu (jak skoki statków). Po dotarciu droid sam
robi skan i zapisuje meldunek. W tym czasie gracz czyta zaczyn i koncentrat.

## 6. Pulpit detektywa (kolejne etapy)

1. Skan przestrzenny piętra (makieta DT-4): zasięg, sonda ruchu, kontakty, terminale, drzwi.
2. Dziennik: meldunki, rozmowy, polecenia.
3. Koncentrat: odkryte fakty do łączenia; tablica powiązań.
4. Akta: zaczyn, postacie, miejsca znane droidowi.
5. Raport końcowy: gracz opisuje rozwiązanie; ocena = pokrycie faz 1–7.

## 7. Do ustalenia

- Pełne listy 12 zdarzeń i 12 relacji (w migracji robocze propozycje).
- 12 wariantów dla każdej z faz 3–12 (z tagami).
- Granice treści dla ciężkich zdarzeń (np. gwałt: tylko poza kadrem, bez opisów; konsekwencje i motyw).
- Dostawca i model AI (limity zapytań darmowej warstwy).

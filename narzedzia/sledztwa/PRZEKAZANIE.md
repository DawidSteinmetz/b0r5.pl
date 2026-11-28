# Przekazanie: śledztwa droida — stan prac i ustalenia

Plik dla kolejnej sesji Claude (albo człowieka), która przejmuje tę pracę. Czytaj razem z `PROJEKT.md`.

## Gdzie i jak pracujemy

- Repo: `DawidSteinmetz/b0r5.pl`, gałąź **`claude/sledztwa-droid`**. Nie commituj na `main`.
- Lokalna kopia u autora: `D:\b0rs.pl` (Windows, terminal MSYS2 UCRT64). Zmiany zapisuj na dysku i od razu
  commituj + pushuj na gałąź.
- Ta sama baza SQL co reszta gry (MySQL, migracje w `api/migracje/NNN_*.sql`, import ręczny w phpMyAdmin;
  każda migracja musi dać się zaimportować drugi raz bez błędów i bez duplikatów).
- Repo `DawidSteinmetz/HOLOLINK-SQL` to stara kopia z 15.11 — nie używać.

## Twarde ustalenia autora (nie zmieniać bez pytania)

1. **Nie ruszać JSON-a kont** (`datapad_dane`, wiersz `uzytkownicy`). Wszystko nowe — w tabelach SQL.
   Detektyw = wpis w `sledztwo_detektywi` (po loginie). Logowanie bez zmian; detektyw dostaje inny pulpit.
2. Każde śledztwo jest **unikatem** na wspólnym szkielecie **12 faz**; fazy 4–10 mogą zmieniać kolejność.
3. Typ = **prawdziwe zdarzenie × relacja** (12 × 12 = 144). Gracz dostaje typ najwyżej raz; wyczyn = komplet 144.
4. **Porzucone** śledztwo idzie do MG; gracz może wrócić po uzasadnieniu i zmianach MG.
5. **Jawność** sprawy i każdej fazy (jawna / ukryta) — mocno zmienia zachowanie NPC. Przykłady autora:
   wezwany do gwałtu, a naprawdę kradzież, bo ofiara coś ukrywa; pościg jawny = list gończy, ukryty = ciche
   śledzenie po tropach.
6. **Kolejność tworzenia: najpierw baza danych** — losowanie imion, nazwisk, gatunków, lokacji, statystyk EotE,
   ekwipunku itd. (to czyni śledztwo wyjątkowym). Dopiero potem AI pisze treść.
7. **Darmowy model AI przez API.** Prompty zawsze bardzo szczegółowe, pisane na sztywno; w puste pola wchodzą
   tylko losowe szczegóły z bazy. Jeden model tworzy treści (Autor), drugi w grze je przeszukuje (Droid).
8. Droid **sam wyłapuje słowa kluczowe** (nie zna ich); gracz dostaje koncentrat i łączy kropki.
9. Droid **sam rozmawia z NPC** (AI z AI); gracz wpływa poleceniami w grze („spytaj go o X”).
   Kluczowi NPC — bardzo szczegółowe historie. Poboczni — szum, zdawkowe „dzień dobry, nie wiem jak pomóc”.
10. **Miejsca:** generowane są tylko lokacje istotne dla fabuły. Reszta (inne poziomy, pokoje) — z szumu; droid może
    tam pójść, ale to strata czasu. Punkt fabularny = skupisko kilku budynków na Nar Shaddaa (widoczne na mapie):
    lądowisko, hangar, sklepy, kantyny, kasyna, na niższych poziomach nory slicerów.
11. Droid chodzi **tempem chodu**; gracz wysyła go automatycznie do celu i w tym czasie czyta materiały.
    Na start gracz dostaje duży zaczyn fabularno-pomocniczy.
12. Makieta pulpitu: artefakt „DT-4 – skan przestrzenny piętra” (plan z góry, zasięg 230 j., sonda ruchu, kontakty
    K-1…, terminale T1/T2, drzwi 14, pasek odczytu: meldunek droida + linia „Hune”).

## Zrobione (na gałęzi)

| Plik | Co |
|------|----|
| `api/migracje/082_sledztwa_schemat.sql` | detektywi, słowniki (zdarzenia, relacje, szablon faz z zachowaniem NPC jawna/ukryta, warianty), pule losowania `gen_*`, instancja śledztwa, fazy, miejsca, postacie (z kartą EotE), ślady, droid, szum, dziennik; dane startowe (robocze) |
| `api/migracje/083_ai_szablony.sql` | `ai_szablony`, `ai_wywolania`, szablon `npc_wypowiedz` |
| `api/ai.php` | moduł AI: pola `{{…}}` (brak pola = brak zapytania), walidacja JSON wg schematu, 3 próby z poprawką, łańcuch dostawców (Groq → OpenRouter), dzienny limit, rejestr; akcje MG `mg_ai_*` |
| `api/konfiguracja-ai.example.php` | wzór konfiguracji (prawdziwa `konfiguracja-ai.php` poza repo, zablokowana w `.htaccess`) |
| `api/index.php` | `require ai.php` + `obsluz_ai()` |
| `api/migracje/084_sledztwa_warianty.sql` | **pierwsza wersja** 144 wariantów faz: nazwa, szkic z {A}{B}{Z}{S}{W}{M}, tagi `wymaga` / `daje`, jawność; słownik tagów w nagłówku pliku; ponowny import nadpisuje warianty treścią pliku |
| `api/sledztwa.php` | generator kroku 1 (bez AI): akcje `sledztwo_nowe` (detektyw dla siebie; MG dla `login`, z opcjami `seed`, `zdarzenie_id`, `relacja_id`, `jawnosc`, `proba`), `mg_sledztwa`, `mg_sledztwo_podglad`, `mg_detektywi`, `mg_detektyw_ustaw` (`{login, detektyw: true/false}`); `api/index.php` — require + `obsluz_sledztwa()` |
| `js/aplikacje/sledztwa.js` + `js/system.js` | aplikacja „Droid śledczy” (pierwsza wersja, przed makietą DT-4): bieżąca sprawa (zgłoszenie, zaczyn), położenie droida, dziennik, archiwum, „Przyjmij nową sprawę”. Ikona tylko dla detektywów: pulpit pyta `sledztwo_moje` i ustawia `stan.detektyw`. `sledztwo_moje` nie zdradza prawdziwego zdarzenia ani relacji |
| `gm/mg.js` (zakładka Konta) | przycisk „Nadaj detektywa” / „Odbierz detektywa” przy koncie i dopisek „· detektyw” przy loginie; bez migracji 082 przycisku nie ma. Odebrać nie można, gdy konto ma zapisane śledztwa |
| `narzedzia/sledztwa/sprawdz_warianty.pl` | kontrola spójności wariantów (perl): 12 × 12, wstawki ↔ tagi postaci, źródło każdego wymaganego tagu, wariant bez wymagań dla każdej jawności, symulacja 100 tys. losowań generatora |

Przetestowane lokalnie (MariaDB + atrapa API): obie migracje 2× bez błędów; brak pola blokuje zapytanie; zły JSON
poprawiony w 2. próbie; 429 → zapasowy dostawca. **Nie testowane na prawdziwej bazie ani z prawdziwym modelem.** Migracja 084 sprawdzona tylko skryptem `sprawdz_warianty.pl` (lokalnie brak MySQL) — przy imporcie w phpMyAdmin zwrócić uwagę na błędy składni.

## Konwencje kodu

- PHP 8, `declare(strict_types=1)`, moduł = `api/<nazwa>.php` z `obsluz_<nazwa>(string $akcja, bool $post)`,
  dopisany w `api/index.php` (require + wywołanie). Pomocnicze: `polaczenie_bazy()`, `odpowiedz()`, `blad()`,
  `wejscie()`, `wymagaj_logowania()`, `wymagaj_mg()`. POST wymaga nagłówka `X-Datapad: 1`.
- Nazwy po polsku, bez polskich znaków w identyfikatorach. Komentarze po polsku.
- Karta EotE: format jak `osoby_karta_eote.dane` / `nowaKarta()` w `js/rdzen/eote.js`.
- Lokacje: tabela `lokalizacje` (Nar Shaddaa z migracji 030). Konta: login z JSON-a (tylko odczyt).
- **Daty zawsze w formacie gry: `DD|MM|RR GG:MM`, rok gry = rok realny − 2010** (np. 27|11|16 21:34). W JS
  `czasGry()` / `dataKrotka()` / `dataGry()` z `js/rdzen/narzedzia.js` (pełny kalendarz gry, dni „H”); w PHP
  `sl_data_gry()` w `api/sledztwa.php`. W bazie czas w UTC wpisywany jawnie z PHP (`sl_utc(sl_teraz())`), nie przez
  `DEFAULT CURRENT_TIMESTAMP` (strefa serwera MySQL przesuwałaby godziny).

## Następne kroki

1. ~~Warianty faz~~ — **zrobione** (084, pierwsza wersja). Czeka na poprawki autora; po każdej zmianie uruchomić
   `perl narzedzia/sledztwa/sprawdz_warianty.pl` (musi skończyć się „OK”).
2. ~~Generator kroku 1~~ — **napisany, NIEPRZETESTOWANY** (`api/sledztwa.php`; lokalnie brak PHP i MySQL —
   sprawdzony tylko czytaniem i bilansem nawiasów). Pierwszy test na serwerze: jako MG
   `POST ?a=sledztwo_nowe` z `{"login":"<detektyw>","proba":true}` → szkielet bez zapisu; potem bez `proba`
   i `GET ?a=mg_sledztwo_podglad&id=…`. Ten sam `seed` + te same pule = ten sam szkielet. Szczegóły i
   decyzje — „Generator kroku 1” niżej. Brak panelu MG (na razie tylko API).
3. Szablony AI dla Autora: treść fazy, miejsce, historia NPC, wiedza NPC, ślad, wiadomość w datapadzie, zaczyn;
   w grze: pytanie droida, ekstrakcja faktów.
4. Pulpit detektywa wg makiety DT-4 — **etap 1 zrobiony (nieprzetestowany)**: ruch droida 1:1 i skan piętra
   (`sledztwo_skan`, `sledztwo_idz`; opis w PROJEKT.md rozdz. 4 „Ruch i skan”).
   **Etap 2 zrobiony (nieprzetestowany): rozmowy** — migracja `085_sledztwa_rozmowy.sql` (tabele rozmów i wpisów,
   szablon AI `droid_wypowiedz`), akcje `sledztwo_rozmowa`, `_start` (klik w kontakt → droid wchodzi do lokalu
   i podchodzi na 2–3 m, 3 s), `_tura` (polecenie operatora → pytanie droida → odpowiedź NPC), `_koniec`.
   Kluczowi NPC: AI po obu stronach (`droid_wypowiedz` + `npc_wypowiedz`); poboczni i przechodnie: wypowiedź
   zastępcza droida + kwestie szumu, bez AI (`SLEDZTWO_AI_DLA_POBOCZNYCH`). Awaria AI → wypowiedź zastępcza
   i wpis „Łącze z modułem językowym niedostępne”. Dopóki Autor nie napisze `historia` / `wiedza`, wiedza NPC
   powstaje ze szkieletu (`sl_npc_dane`: fazy z jego kodem w szkicu; ukryte fazy oznaczone „TO UKRYWASZ”;
   `ukrywa_kod` — prawdziwe zdarzenie). Nastrój po każdej wypowiedzi zapisany w rozmowie (pod punkty W/S/Z).
   Droid nie pyta sam w pętli — każdą turę wyzwala operator („Zadaj pytanie” albo „Dalej”), żeby nie palić limitu.
   **Etap 3 zrobiony (nieprzetestowany): presety budynków i wstęp** — migracja `086_sledztwa_presety.sql`,
   akcje `sledztwo_straz`, `sledztwo_nakaz_wniosek`, `mg_sledztwo_nakazy`, `mg_sledztwo_nakaz_decyzja`; panel MG:
   karta „Wnioski o nakaz” w zakładce Konta. Opis w PROJEKT.md rozdz. 4 „Presety budynków i wstęp”. Uwaga: bez 086
   skan i generator zwracają „Brak presetów budynków (migracja 086)”.
   **Wspólna mapa (decyzja autora):** brakujące budynki kompleksu generator dopisuje do `lokalizacje` przy zapisie
   sprawy (`sl_nowy_budynek`, `sledztwo_zapisz`); plany budynków liczone ze ziarna świata `SLEDZTWO_SWIAT`.
   Docelowo multiplayer do 12 droidów — lista zmian w PROJEKT.md („Docelowo: duże śledztwa multiplayer”).
   **Etap 4 (nieprzetestowany): przedmioty, komputery, nowy interfejs** — migracja `087_sledztwa_przedmioty.sql`,
   akcje `sledztwo_przeszukaj`, `_zabierz`, `_zlam`, `_komputer`, `_dekoduj`; NPC bez powtórzeń; aplikacja
   przepisana na układ pełnoekranowy. Opis w PROJEKT.md „Przedmioty, komputery i terminale”.
   **Etap 5 (nieprzetestowany): swobodny ruch po planie** — migracja `088_sledztwa_pozycje.sql`; klik w dowolny
   punkt, trasa przez drzwi (5 km/h, animacja), klikalna winda, podchodzenie do terminali i rozmówców, blokowanie
   drzwi w obie strony, raport przeszukania pod przyciskiem. Opis w PROJEKT.md „Ruch i skan”.
   **Etap 6 (nieprzetestowany): metryki NPC** — migracja `089_sledztwa_metryki.sql`: każdy rozmówca (też przechodzień,
   strażnik) dostaje przy pierwszej rozmowie metrykę w `sledztwo_metryki` (pochodzenie wg gatunku, losowe lata na
   planecie i staż, praca, mieszkanie, rodzina, opinie, sekret, plotka, znajomość z osobą ze sprawy 0–3 z faktami
   z bazy, chęć mówienia). Status osoby ze sprawy wg zgłoszonego zdarzenia (`SLEDZTWO_STATUS_A`); poziom znajomości
   losowany z wag zależnych od miejsca (lokal fabuły / budynek fabuły / szum). Prompt `npc_wypowiedz` zaczyna się od
   „Udajesz <gatunek> o imieniu … ze świata Gwiezdnych Wojen”; NPC mówią swobodnie i naturalnie. Z metryką przez AI
   mówi każdy (`SLEDZTWO_AI_DLA_WSZYSTKICH`), więc wywołań AI jest więcej — pilnuje ich limit dzienny.
   Migracja `090_sledztwa_scena.sql`: NPC znają otoczenie (`sl_scena`: budynek, lokale piętra i parteru, ochrona,
   terminale, pora; pracownik — swoje miejsce pracy). Procedura śledcza (przełącznik w rozmowie, pamięć przeglądarki):
   pytania operatora droid zadaje jako własne albo jawnie. Bez AI: `sl_odpowiedz_zastepcza` (imię, pochodzenie,
   gdzie co jest — z planu) zamiast przypadkowej kwestii; w zapisie rozmowy widać, gdy rozmówca mówi bez AI i dlaczego.
   Podejścia w rozmowie (`SLEDZTWO_PODEJSCIA_ROZMOWY`): Groźba, Negocjacje, Urok, Blef, Autorytet — test EotE droida
   przeciw karcie rozmówcy (`sl_karta_rozmowcy`; kolejne próby +1 kość trudności, maks. +2). Udany: rozmówca zdradza
   fakt z metryki, jego `chce_mowic` rośnie o stopień (triumf — „chętnie”); nieudany: wrogość; rozpacz: koniec rozmowy.
   Wynik idzie do promptu NPC i do zapisu („Test: … UDANY/NIEUDANY”). Bez AI polecenie operatora, które nie jest
   pytaniem, droid mówi jako zdanie (`sl_pytanie_operatora`). Zerwania i lagi łącza gracz widzi po fabularnemu
   (`SLEDZTWO_ZAKLOCENIA`: awaria nadajnika, spadek mocy baterii…), techniczny powód idzie do dziennika jako wpis
   systemowy. Karta EotE rozmówcy jest opisana słowami w metryce (`sl_karta_opis`: cechy i umiejętności ≥ 2).
   Dalej: punkty W/S/Z i testy EotE w rozmowach (PROJEKT.md rozdz. 4a), ekstrakcja faktów → ślady → koncentrat, terminale (publiczny / slicer / sam),
   koncentrat i tablica powiązań, ruch do innych kompleksów (taksówka). Linia „Hune” z makiety — nie wiadomo,
   czym jest (zapytać autora); na razie w pasku jest linia „ŁĄCZE”.

## Generator kroku 1 — jak działa (decyzje do ewentualnej zmiany)

- Losowanie: `mt_srand(seed)`; seed zapisany w `sledztwa.seed`. Stałe na górze `api/sledztwa.php`.
- Typ: losowo spośród par (zdarzenie, relacja), których detektyw jeszcze nie ma (porzucone też zajmują).
- Jawność sprawy: 35 % ukrytych; przy ukrytej — zdarzenie zgłoszone (inne niż prawdziwe), `ukrywa_kod` = A, B albo Z.
  Faza 2 ma jawność sprawy; pozostałe fazy 50/50 (jeśli żaden wariant nie pasuje, jawność fazy się odwraca).
- Fazy: 1, 2, 3, 4–10 przetasowane, 11, 12; k12 spośród zgodnych z tagami i jawnością; `sledztwo_fazy.tagi` = stan po fazie.
- Skupisko: kompleks z `lokalizacje` (Nar Shaddaa) mający ≥ 2 budynki / lądowiska; brak lokacji → miejsca bez `lokalizacja_id`.
- Miejsca: jedno na każdą fazę, której szkic ma `{M}`, plus kryjówka fazy 7. Rodzaj — ze słów wariantu
  (`SLEDZTWO_SLOWA_MIEJSC`: nora, hotel, kasyno…), inaczej wg wag `gen_rodzaje_miejsc`; poziom z jego zakresu;
  pokój `<poziom><nr>` (np. 1256), lądowisko / korytarz / targ `L-07`. Budynek skupiska o zgodnym rodzaju daje swoją
  nazwę; inaczej „Kantyna „Szczur” (budynek)” albo „Mieszkanie 1256 (budynek)”. Pula nazw lokali — do dodania.
- Postacie: A, B zawsze; Z / S / W wg końcowych tagów; 1–2 pobocznych na miejsce (archetypy bywające w tym rodzaju
  miejsca). S przy tagu `nagroda` = łowca. Gatunek wg `gen_populacja`, imię / nazwisko z puli gatunku (brak → pula
  człowieka), przydomek 35 % kluczowi / 15 % poboczni, charakter: cecha + mowa (+ nawyk 60 %). Kluczowi min. rywal,
  poboczni sługusi. Kluczowi dostają przedmiot z `gen_przedmioty` (przyszły dowód). A przy długu ma 1/5 kredytów.
  A przebywa w kryjówce fazy 7. Karta EotE jak `nowaKarta()` (tryb NPC); bronie z tabeli `SLEDZTWO_BRONIE`.
  Relacja A–B nakłada więzy (`sl_wiezy_relacji`): para, rodzina i weterani nie są droidami; „dziecko” ma 12–19 lat,
  rodzic jest o 18–35 lat starszy, rodzina ma ten sam gatunek i nazwisko, rodzeństwo ±8 lat, mistrz starszy od ucznia.
- Do dopracowania (uwaga autora 28|11|16): każdy scenariusz potrzebuje dużo większego poziomu szczegółów —
  spójność ról z wiekiem / gatunkiem / zajęciem, konkretne realia miejsc i ludzi. Kandydat na krok AI Autora.
- Droid startuje w budynku i na poziomie miejsca fazy 2 (albo pierwszego miejsca). Wpis w dzienniku „Szkielet wylosowany”.
- Gracz może mieć jedno otwarte śledztwo; gracz nie dostaje w odpowiedzi szkieletu (prawdy), tylko id.

## Otwarte pytania do autora

- Pełne listy 12 zdarzeń i 12 relacji (w 082 robocze; autor nie ma jeszcze pełnych).
- Czy śledztwa tylko na Nar Shaddaa, czy też inne planety (schemat obsługuje `planeta_id`).
- Granice treści ciężkich zdarzeń — propozycja: gwałt wyłącznie poza kadrem, bez opisów (w 082 jako wskazówka).
- Dostawca AI: rekomendacja Groq `openai/gpt-oss-120b` (główny) + OpenRouter `:free` (zapas); klucz wpisuje autor.

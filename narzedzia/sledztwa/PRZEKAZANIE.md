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

## Następne kroki

1. ~~Warianty faz~~ — **zrobione** (084, pierwsza wersja). Czeka na poprawki autora; po każdej zmianie uruchomić
   `perl narzedzia/sledztwa/sprawdz_warianty.pl` (musi skończyć się „OK”).
2. **Generator kroku 1** (`api/sledztwa.php`, akcja `sledztwo_nowe`): typ spośród niezdobytych, jawność, warianty
   k12 zgodne z tagami, kolejność 4–10, skupisko i miejsca, postacie z kartami EotE i ekwipunkiem; zapis w bazie,
   podgląd dla MG. Losowanie wariantów tak jak w symulacji `sprawdz_warianty.pl`: fazy 1, 2, 3, potem 4–10
   w losowej kolejności, 11, 12; dla każdej fazy najpierw jawność, potem k12 spośród zgodnych (wszystkie tagi
   z `wymaga` już dane; jawność wariantu `dowolna` albo równa jawności fazy); `daje` dopisać do tagów i zapisać
   w `sledztwo_fazy.tagi`. Przy sprawie ukrytej faza 2 zawsze ukryta. Postacie Z / S / W tworzyć, gdy końcowy
   zbiór tagów zawiera `zleceniodawca` / `sledzacy` / `swiadek`. Warianty z rzadkimi tagami (`statek`,
   `falszywa_tozsamosc`, `zakladnik`, `organizacja`) losują się rzadko (ok. 0,5–1 %) — ewentualnie podbić wagą.
3. Szablony AI dla Autora: treść fazy, miejsce, historia NPC, wiedza NPC, ślad, wiadomość w datapadzie, zaczyn;
   w grze: pytanie droida, ekstrakcja faktów.
4. Pulpit detektywa wg makiety DT-4; ruch droida; dziennik; koncentrat.

## Otwarte pytania do autora

- Pełne listy 12 zdarzeń i 12 relacji (w 082 robocze; autor nie ma jeszcze pełnych).
- Czy śledztwa tylko na Nar Shaddaa, czy też inne planety (schemat obsługuje `planeta_id`).
- Granice treści ciężkich zdarzeń — propozycja: gwałt wyłącznie poza kadrem, bez opisów (w 082 jako wskazówka).
- Dostawca AI: rekomendacja Groq `openai/gpt-oss-120b` (główny) + OpenRouter `:free` (zapas); klucz wpisuje autor.

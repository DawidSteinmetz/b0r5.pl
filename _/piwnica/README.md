# Piwnica

Pokój z własną mechaniką: `pokoj.js` i `pokoj.css` (przeglądarka), `api/` (serwer), `_logika/` (dane zamknięte przed przeglądarką). PHP musi móc pisać w `_logika/stan/`. Przy kopiowaniu pokoju na inną postać wyczyść ten folder, zostawiając `.keep`.

## Jak działa alchemia

Na pergaminach są: Skład, Stół, Magiczna waga, Kociołek i Księga rachunkowa.

- **Skład** trzyma każdą rzecz osobno: surowce, kopczyki, wyroby i listę katalizatorów. Start opisuje `tabele/alchemia.json` (`start`).
- **Waga** bada surowiec czarem aktywującym po `czas_wagi_s`. Rzecz zostaje nienaruszona, a wynik jest zapamiętany.
- **Stół** mieści jedną rzecz. Gracz wybiera czar i moc, a czar sam wyznacza przyrząd, który jest tylko obrazkiem. Po `czas_czaru_s` pierwiastek trafia na kopczyk w Składzie. Funkcja `animacjaLotu` w `pokoj.js` czeka na animację.
- **Kociołek** sumuje atomy tego, co do niego trafiło. Bez katalizatora to mieszanina, a z katalizatorem powstaje produkt.

**Czary-przepisy** to lewitujące kartki z folderu `przepisy/`, każda opisana w `tabele/przepisy.json` (klucz to nazwa pliku kartki). Wpis podaje katalizator i gramy składników na jedną porcję. Mechanizm jest wspólny: czar liczy mnożnik z całych, nieobrabianych surowców w Składzie i z pojemności kociołka (`pojemnosc_g`, domyślnie 12 000 g, czyli 12 l). Po rzuceniu zaznacza w Składzie, co i ile wziąć, a „Wsyp i zagotuj” robi resztę. Powyżej mnożnika bez brudu rosną zanieczyszczenia. Wysysanie na stole można wpisywać w molach albo gramach.

Czystość to masa produktu ułożonego ze wzoru podzielona przez masę całej zawartości, więc złe proporcje i resztki obniżają ją tak samo. Nazwy stopni są w `progi_czystosci`.

Produkt bierze przymiotnik od nieneutralnego surowca o największym udziale w masie (`przymiotniki`). Kopczyki łączą się tylko przy tym samym pierwiastku i pochodzeniu.

## Tabele (`_logika/tabele/`)

- `pierwiastki.json`, `surowce.json`: nadpisuje je `_logika/narzedzia/import_xlsx.py` z arkusza w `zrodlo/`, więc nie wpisuj tam nic ręcznie.
- `alchemia.json`: czasy, zapas startowy, naturalna zmienność porcji (`zmiennosc`: wilgotność i rozrzut suchej masy każdej nowej porcji; `mol_staly`: np. węgiel aktywny zawsze 1 mol C), surowce ręczne (woda, burak, drożdże), przymiotniki, progi czystości. `dosypki` to dostawy jednorazowe do działającego Składu: nowy wpis z nowym `id` wchodzi raz, a `usun` zabiera wskazane surowce.
- `przepisy.json`: składnik z `cel` trzeba najpierw odessać na stole do podanej proporcji atomów. Kartka układa stos, a stół podpowiada, ile wyssać.
- Przepis może mieć `warianty` (ta sama kartka i nazwa czaru, inne składniki), wybierane zakładką na kartce. Burak położony na stole pamięta, pod który przepis jest odsysany (`cel_dla`), więc burak pod cukier nie miesza się z burakiem pod eliksir. U Henryka Cantus Sacchari ma dwa warianty: z wody i węgla aktywnego (ok. 95% czystości, bo węgiel ma zanieczyszczenia) i z buraka cukrowego odessanego do proporcji C₆H₁₂O₆ (ok. 99% czystości).
- `czary.json`: `znany: false` to czar spoza notatek Henryka: nie ma go na listach, ale rzucony głosem albo wpisany działa. Zmiana na `true` dopisuje go do notatek.
- `przyrzady.json`: opisy i ikony. Lista wytrącanych pierwiastków wynika ze znanych czarów.
- `katalizatory.json`: wzór produktu i cena. Pole `ukryty` wiąże katalizator z przedmiotem przenośnym.

## Czary w oknach

W oknach wagi, stołu i przepisu na górze jest lista czarów tego miejsca (czary z notatek postaci; kliknięcie nazwy rzuca czar), a pod nią „Rzuć czar” (słuchanie mowy, Chrome i Edge, po polsku). Silnik jest wspólny dla wszystkich pokoi: `Mysl.czary` w `assets/js/rdzen.js`.

- Czar rozpoznaje się po brzmieniu pierwszych dwóch słów („Extractio Carbonis”, „Revelio Ponderis”, „Cantus Sacchari”); przekręcenia mowy („ekstrakcjo karbonis”) też działają. Bez przedrostka.
- Ostatnie słowo to moc: „optimum”, „maximum” albo liczba (cyframi, po polsku albo po łacinie: duo, tres, quattuor/quatro…).
  - Stół: optimum = tyle, ile podpowiada przepis (bez podpowiedzi wszystko), maximum = wszystko, liczba = procent; bez mocy to, co na suwaku.
  - Przepis: optimum = porcja bez brudu, maximum = największa porcja, liczba = mnożnik porcji; bez mocy to, co w polu.
  - Waga: moc nie ma znaczenia.
- Czar działa także wtedy, gdy postać go nie zna (`znany: false` w `czary.json`): nie ma go tylko na liście w jej notatkach, ale wypowiedziany albo wpisany zadziała.

## Łączenie i badanie

- Kopczyki łączą się same, ale tylko przy tym samym pierwiastku i pochodzeniu (węgiel z cebuli da potem cukier cebulowy).
- Wyroby łączy się ręcznie: ⧉ przy wyrobie podświetla te, z którymi można go połączyć (ten sam wyrób z tego samego katalizatora; czystość wychodzi średnia ważona masą), a „Połącz tu” je scala.
- Na wadze zbadane porcje mają znaczek ✓.

## Rynek

Ustawienia są w `_logika/rynek.json`, a księga leży w `ksiega/`. Kasa co miesiąc dopełnia się do `budzet.baza`. Zakup podnosi popyt, popyt wygasa z czasem, a towar dociera do Składu po `zakup.zwloka_s` sekundach. Produkty sprzedaje się w Składzie: cena zależy od masy, czystości, ceny z katalizatora i ostatnich sprzedaży.

Inne myśloodsiewnie kupują przez `api/rynek_zewn.php`, podpisując zapytania HMAC-SHA256 kluczem z `domy`. Wzór klienta: `_logika/rynek_klient_przyklad.php`.

## Fruktoza

Katalizator fruktozy to „Zakurzony flakon”, przedmiot przenośny schowany na strychu (`strych/przedmioty/002-…`). Działa dopiero po przeniesieniu do piwnicy.

## Sól kuchenna (zagadka bez przepisu)

Nie ma przepisu, jest tylko `przedmioty/011-notatka-o-soli.md`. Katalizator `sol` (NaCl) działa, gdy w Składzie leży „Kryształ soli z Wieliczki” (dosypka `sol-1`). Wrzucony do kociołka przepada razem z katalizatorem. Sód i chlor kupuje się w księdze (`tabele/rynek_towary.json`): soda oczyszczona, soda do prania, spirytus solny, salmiak.

Kociołek gotuje każdą proporcję. Czystość to masa NaCl, jaką da się ułożyć, przez masę całości, więc byle jak wrzucona soda ze spirytusem daje bardzo brudną sól (ok. 20%). Lepiej jest odessać na stole węgiel, wodór i tlen. Najlepiej: nieznany Henrykowi czar *Separatio Salis Crystallinum* (z marginesu notatki) wyciąga sód i chlor na kopczyki, a te wsypuje się do kociołka po równo w molach (100%). Azotu z salmiaku zwykłe czary nie ruszą.

Odparowanie: każdy wyrób można położyć na stole („Na stół” w zakładce Wyroby). Wyrób pamięta pełny skład z brudem, więc czar wysysa z niego jak z surowca, a czystość i stopień liczą się od nowa. Brudna sól z sody i spirytusu (ok. 25%) po odessaniu wodoru, tlenu i węgla ma ok. 99,7%. Wyrobu leżącego na stole nie da się sprzedać ani wsypać do kociołka.


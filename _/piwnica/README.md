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
- `alchemia.json`: czasy, zapas startowy, surowce ręczne (woda, burak, drożdże), przymiotniki, progi czystości. `dosypki` to dostawy jednorazowe do działającego Składu: nowy wpis z nowym `id` wchodzi raz, a `usun` zabiera wskazane surowce.
- `przepisy.json`: składnik z `cel` trzeba najpierw odessać na stole do podanej proporcji atomów. Kartka układa stos, a stół podpowiada, ile wyssać.
- `czary.json`: `znany: false` to czar spoza notatek Henryka: nie ma go na listach, ale rzucony głosem albo wpisany działa. Zmiana na `true` dopisuje go do notatek.
- `przyrzady.json`: opisy i ikony. Lista wytrącanych pierwiastków wynika ze znanych czarów.
- `katalizatory.json`: wzór produktu i cena. Pole `ukryty` wiąże katalizator z przedmiotem przenośnym.

## Czary w oknach

W oknach wagi, stołu i przepisu na górze jest lista czarów tego miejsca (czary z notatek postaci; kliknięcie nazwy rzuca czar), a pod nią „Rzuć czar” (słuchanie mowy, Chrome i Edge, po polsku) i pole do wpisania. Silnik jest wspólny dla wszystkich pokoi: `Mysl.czary` w `assets/js/rdzen.js`.

- Czar rozpoznaje się po brzmieniu pierwszych dwóch słów („Extractio Carbonis”, „Revelio Ponderis”, „Cantus Sacchari”); przekręcenia mowy („ekstrakcjo karbonis”) też działają. Bez przedrostka.
- Ostatnie słowo to moc: „optimum”, „maximum” albo liczba (cyframi, po polsku albo po łacinie: duo, tres, quattuor/quatro…).
  - Stół: optimum = tyle, ile podpowiada przepis (bez podpowiedzi wszystko), maximum = wszystko, liczba = procent; bez mocy to, co na suwaku.
  - Przepis: optimum = porcja bez brudu, maximum = największa porcja, liczba = mnożnik porcji; bez mocy to, co w polu.
  - Waga: moc nie ma znaczenia.
- Czar działa także wtedy, gdy postać go nie zna (`znany: false` w `czary.json`): nie ma go tylko na liście w jej notatkach, ale wypowiedziany albo wpisany zadziała.

## Rynek

Ustawienia są w `_logika/rynek.json`, a księga leży w `ksiega/`. Kasa co miesiąc dopełnia się do `budzet.baza`. Zakup podnosi popyt, popyt wygasa z czasem, a towar dociera do Składu po `zakup.zwloka_s` sekundach. Produkty sprzedaje się w Składzie: cena zależy od masy, czystości, ceny z katalizatora i ostatnich sprzedaży.

Inne myśloodsiewnie kupują przez `api/rynek_zewn.php`, podpisując zapytania HMAC-SHA256 kluczem z `domy`. Wzór klienta: `_logika/rynek_klient_przyklad.php`.

## Fruktoza

Katalizator fruktozy to „Zakurzony flakon”, przedmiot przenośny schowany na strychu (`strych/przedmioty/002-…`). Działa dopiero po przeniesieniu do piwnicy.

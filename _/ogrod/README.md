# Ogród

Pokój z własną mechaniką, zbudowany jak piwnica: `pokoj.js` i `pokoj.css` (przeglądarka), `api/` (serwer), `_logika/` (dane zamknięte przed przeglądarką). PHP musi móc pisać w `_logika/stan/`. Przy kopiowaniu pokoju na inną postać wyczyść ten folder, zostawiając `.keep`.

Na pergaminach są: Grządki (plan działki), Skrzynka z nasionami (zapasy i zakupy) oraz Termometr i barometr.

## Pogoda

Pogodę liczy `api/_pogoda.php` z klimatu Bydgoszczy (`tabele/klimat.json`: miesięczne normy temperatury, opadu, zachmurzenia i wiatru). Każdy dzień wynika z daty i z poprzednich dni (ciepło i deszcz mają pamięć), więc pogoda jest wspólna dla wszystkich gości i nic nie trzeba zapisywać. Z tego samego wynikają: przymrozki, śnieg (narasta i topnieje), temperatura ziemi, mgła o świcie, wschód i zachód słońca oraz przepowiednia barometru na jutro (myli się mniej więcej raz na pięć dni). Średnie z wielu lat zgadzają się z normami. Zmiana `ziarno` losuje całą pogodę od nowa.

Kiedy pada albo leży mgła, widać to na całej stronie ogrodu.

## Grządki

Działka i rozmiar grządek są w `tabele/ogrod.json`. Domyślnie działka ma 40×20 m, dom zajmuje 10×10 m, a grządka 1,2×5 m z wąskimi ścieżkami, więc mieści się 57 grządek. Nową grządkę robi się czarem (`tabele/czary.json`, efekt `nowa_grzadka`), klikając wolne miejsce na planie. Czar nie ruszy zmarzniętej ziemi.

Ogród żyje w czasie rzeczywistym: pierwsze zapytanie po północy przeżywa za niego wszystkie dni, które minęły. Każdego dnia:

- **Woda**: deszcz i roztopy wsiąkają, parowanie rośnie z ciepłem, słońcem i rosnącą rośliną, a sucha ziemia paruje wolniej. Gleba trzyma `pojemnosc_mm`, a podlanie dodaje `podlewanie_mm`.
- **Chwasty** rosną same, kiedy jest ciepło. Pielenie je zabiera.
- **Roślina** zbiera sumę ciepła ponad swoją temperaturę bazową. Nasiona wschodzą dopiero w ciepłej i wilgotnej ziemi, a po 80 dniach bez wschodów gniją. Susza i chwasty odbierają zdrowie, a dobre warunki je przywracają. Mróz szkodzi według odporności gatunku: siewki są najdelikatniejsze, byliny od października do marca znoszą najwięcej, a śnieg chroni. Roślina jednoroczna, której nikt nie zebrał, przekwita.

Gatunki są w `tabele/rosliny.json` (bylica, ruta, kozłek, mak, koper, czosnek, cebula, burak cukrowy). Byliny odrastają co roku i dają jeden zbiór na sezon. Korzeń kozłka wykopuje się jesienią, najwcześniej w drugim roku.

## Zbiory

Plon zależy od zdrowia rośliny i od tego, czy zebrano ją w porę. Zbiór trafia do Składu w piwnicy (`ogrod.json`: `do_skladu`) jako zwykły surowiec, w porcjach z `porcja_g`. Zioła najpierw schną na strychu (`suszenie_h`). Ogród zapisuje zdarzenie `do_skladu`, a piwnica odbiera je sama (`alch_dostawy_obce` w `piwnica/api/_alchemia.php`).

## Skrzynka i zakupy

Nasiona i sadzonki kupuje się u trzech dostawców (`tabele/sklep.json`):

- **Zielarz z Gdańskiej**: sadzonki ziół, dostawa po kwadransie.
- **Katalog nasion z Erfurtu**: drożej, ale nasiona prawie zawsze wschodzą; poczta wraca po dwóch dniach.
- **Targ na Starym Rynku**: najtaniej, ale różnie się przyjmuje; tylko w środy i soboty przed południem.

Każdy towar jest tylko w swojej porze roku (`miesiace`). Popyt podnosi ceny jak na rynku w piwnicy. Płaci kasa z księgi rachunkowej w piwnicy (`ogrod.json`: `kasa`): ogród zapisuje zdarzenie `wydatek`, które księga liczy do salda i pokazuje w historii. Jedna porcja obsadza jedną grządkę.

## Czary

`tabele/czary.json`: Henryk zna przygotowanie grządki i jej likwidację. Deszcz nad grządkami, wyrwanie chwastów i osłona przed przymrozkiem są gotowe, ale `znany: false`; włącza się je zmianą na `true`. Inna myśloodsiewnia z kopią tego pokoju ma własną tabelę i może znać inne czary.

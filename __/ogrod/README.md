# Ogród

Ten sam ogród co u Henryka (`_/ogrod/`), z tą samą mechaniką: `pokoj.js` i `pokoj.css` (przeglądarka), `api/` (serwer), `_logika/` (dane zamknięte przed przeglądarką). PHP musi móc pisać w `_logika/stan/`. Przy kopiowaniu pokoju na inną postać wyczyść ten folder, zostawiając `.keep`.

Różnice u Artemidy:

- **Działka** ma 40×80 m (`tabele/ogrod.json`). Dom 10×10 m stoi na środku lewej górnej ćwiartki, a grządki mogą powstać tylko w prawej połowie (`obszar_grzadek`), więc mieści się ich 143. Od domu do grządek prowadzi ścieżka, a granicę wyznacza niski żywopłot.
- **Pieniądze i zbiory** idą przez kuchnię: zakupy płaci zeszyt domowy (`kasa`), a zbiory trafiają do spiżarni (`do_skladu`). Zioła schną w szopie. Kuchnia odbiera je sama (`alch_dostawy_obce` w `kuchnia/api/_alchemia.php`).
- **Buraka cukrowego** nie ma w sklepie, bo kuchnia nie zna surowca `burak-cukrowy`, a zbiór by przepadł. Gatunek został w `tabele/rosliny.json`, gdyby surowiec kiedyś się pojawił.
- **Wygląd**: cały ogród jest pisany piórem po pergaminie. Tytuły są gotykiem (Pirata One), a reszta pismem odręcznym (Dancing Script). Obie czcionki idą z Google Fonts (`pokoj.css`, pierwsza linia), a bez internetu zostaje Georgia. Plan działki rysuje `pokoj.js` jak odręczny szkic: każda kreska ma własne ziarno, więc żadna grządka nie jest prosta, a ta sama zawsze wygląda tak samo. Akwarelowa plama pod grządką mówi, co się na niej dzieje: brązowa to ziemia, zielona rośnie, złota jest do zbioru, szara to roślina martwa, a fioletowa trwa czar. Roślinki rosną z dojrzałością, chwasty to zawijasy, a wybrana grządka jest obwiedziona czerwoną pętlą.

Pogoda, wzrost roślin, sklep i czary działają tak jak u Henryka. Pogoda ma to samo `ziarno` co u niego (`tabele/klimat.json`), więc w obu ogrodach jest ta sama pogoda.

## Pogoda

Pogodę liczy `api/_pogoda.php` z klimatu Bydgoszczy (`tabele/klimat.json`). Każdy dzień wynika z daty i z poprzednich dni, więc pogoda jest wspólna dla wszystkich gości i nic nie trzeba zapisywać. Wynikają z tego przymrozki, śnieg, temperatura ziemi, mgła, wschód i zachód słońca oraz przepowiednia barometru.

## Grządki

Nową grządkę robi się czarem (`tabele/czary.json`, efekt `nowa_grzadka`), klikając wolne miejsce na planie. Czar nie ruszy zmarzniętej ziemi. Ogród żyje w czasie rzeczywistym: pierwsze zapytanie po północy przeżywa za niego wszystkie dni, które minęły (woda, chwasty, suma ciepła, wschody, mróz). Szczegóły są w `_/ogrod/README.md` u Henryka.

## Skrzynka i zakupy

Nasiona i sadzonki kupuje się u trzech dostawców (`tabele/sklep.json`): u zielarza z Gdańskiej, z katalogu z Erfurtu i na targu na Starym Rynku. Każdy towar jest tylko w swojej porze roku, a popyt podnosi ceny. Ogród zapisuje zdarzenie `wydatek`, które zeszyt domowy liczy do salda i pokazuje w historii.

## Czary

Artemida zna przygotowanie grządki i jej likwidację. Deszcz nad grządkami, wyrwanie chwastów i osłona przed przymrozkiem są gotowe, ale mają `znany: false`; włącza się je zmianą na `true`.

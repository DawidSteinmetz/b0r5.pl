# Szkielet Myśloodsiewni

Każda postać ma własną myśloodsiewnię w osobnym folderze. Świat to sandbox: działa bezobsługowo, stan jest wspólny dla wszystkich gości i nie ma żadnych narzędzi mistrza gry. Potrzebny jest serwer PHP 7.4+ z SQLite i mbstring oraz prawo zapisu w `_dane/`, a w pokojach z mechaniką także w `_logika/stan/`.

## Co jest czym

```
postac.json          jedyne ustawienia postaci: nazwa, rok, strefa czasowa, radio
index.html           strona główna (pokoje jako lewitujące pergaminy)
assets/              rdzeń w przeglądarce (rdzen.js, styl.css)
api/                 rdzeń na serwerze: indeks, wspólny stan, przenoszenie, radio, poczta
_dane/               stan tworzony przez serwer (zamknięty .htaccess); nie kopiować między postaciami
radio/               pliki MP3 tej postaci
_szablon_pokoju/     wzór nowego pokoju
narzedzia/           skrypty pomocnicze (Python 3, zamknięte .htaccess)
<id-pokoju>/         pokój: wszystko, co jest tylko jego
```

Rdzeń nie wie nic o konkretnych pokojach. Lista pokoi powstaje ze skanowania folderów, więc wystarczy dodać albo usunąć folder.

## Pokój

```
<id-pokoju>/
  index.html         kilka linijek, identyczne w każdym pokoju poza data-pokoj
  pokoj.json         nazwa, opis, ikona, kolejność, foldery z elementami
  przedmioty/ ...    jeden element = jeden plik .md (pierwsza linia "# Tytuł")
  pokoj.js, pokoj.css  opcjonalnie: własna mechanika i wygląd (pola "skrypt" i "styl" w pokoj.json)
  api/               opcjonalnie: własne PHP (dołącza dirname(__DIR__, 2) . '/api/_baza.php')
  _logika/           opcjonalnie: dane niewidoczne dla przeglądarki (własny .htaccess)
```

Pole `foldery` w `pokoj.json` mówi, które foldery wczytać i jakiego rodzaju są ich elementy. Rdzeń zna rodzaje `przedmiot` i `ksiazka`. Nowe rodzaje rejestruje `pokoj.js` przez `Mysl.rodzaj(nazwa, opis)`; pełny przykład to `piwnica/pokoj.js`.

Nazwa pliku jest stałym identyfikatorem elementu, więc nie zmieniaj jej po uruchomieniu świata.

## Nowa postać

```bash
python3 narzedzia/nowa_postac.py ../__ "Myśloodsiewnia Artemidy Steinmetz"
```

Powstaje pusty szkielet bez pokoi. W nowym folderze dodaj pokoje poleceniem `python3 narzedzia/nowy_pokoj.py salon "Salon" "Opis."`, a pokoje z mechaniką, jak piwnica, skopiuj z istniejącej postaci i wyczyść ich stan (`_logika/stan/` poza `.keep`). Bez Pythona wystarczy skopiować folder postaci, usunąć cudze pokoje i zawartość `_dane/` (poza `.htaccess`) oraz zmienić `postac.json`.

## Przedmioty przenośne

Przedmiot, który można przenieść, istnieje jako osobny plik w każdym pokoju, w którym może leżeć. W jednym ma istnienie `on`, w pozostałych `off`. Każdy pokój deklaruje to u siebie w `pokoj.json`:

```json
"przenosne": { "kwiat-siarczany": { "plik": "skladniki/001-kwiat-siarczany", "istnienie": "on" } }
```

Pliki o tej samej nazwie grupy tworzą jeden przedmiot, a przycisk „Przenieś” pojawia się sam. Przedmiotów złożonych, jak kociołek, się tak nie deklaruje, więc zostają na miejscu. Nowy przedmiot dodaje `python3 narzedzia/przenosny.py`.

## Poczta między postaciami

Pokój odbierający listy trzyma ustawienia w `_logika/poczta_odbior.json` (u Henryka: `gabinet/`). Listy przychodzą na `api/poczta_odbierz.php`, podpisane HMAC-SHA256 wspólnym kluczem, i pojawiają się w pokoju jako zwykłe pliki `.md`.

## Uruchomienie i wdrożenie

Lokalnie w folderze postaci: `php -S localhost:8000`, potem `http://localhost:8000/`. Serwer wbudowany nie czyta `.htaccess`, więc tylko na produkcji foldery `_dane/`, `_logika/`, `_sen/` i `narzedzia/` są zamknięte. Po wgraniu sprawdź, że `<adres>/_dane/.htaccess` i `<adres>/README.md` zwracają 403. Na nginx trzeba te reguły przenieść do jego konfiguracji.

Muzyka: pliki `.mp3` z `radio/` tworzą wspólną playlistę same. Pliki `.wav` są pomijane.

## Artemida

Kuchnia ma tę samą alchemię co piwnica Henryka (opis w `kuchnia/README.md`), a buduar ma sekretarzyk do listów (`buduar/api/`, ustawienia w `buduar/_logika/poczta.json`). Radio gra muzykę z folderu Henryka (`postac.json`: `radio.folder`), więc Artemida nie ma własnego `radio/`. Sen jest zawieszony: jego dane leżą w `biblioteka/_sen/`.

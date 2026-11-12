# Salon

Pokój z własną mechaniką: `pokoj.js` i `pokoj.css` (przeglądarka), `api/notes.php` (serwer), `_logika/stan/` (stan notesu, zamknięty przed przeglądarką). PHP musi móc pisać w `_logika/stan/`.

## Notes do gier

Notes zapisuje punkty jednej wspólnej partii (stan na serwerze, wszyscy goście widzą to samo). Ma dwa formularze:

- **Mille** (tysiąc): kolumny graczy, kolejne wpisy, sumy na dole; zwycięzca po osiągnięciu celu (domyślnie 1000; cel 0 = gra bez końca).
- **Alea** (kości, jak Generał): kategorie jedynki–szóstki (premia +35 za co najmniej 63 w górnej części), trzy jednakowe, cztery jednakowe, full (25), mały strit (30), duży strit (40), generał (50), szansa. Pióro pilnuje zasad: każda kategoria raz na gracza, w górnej części tylko wielokrotności oczka. Koniec, kiedy wszyscy wypełnią wszystko.

Zmiana formularza zaczyna nową partię z tymi samymi graczami, a poprzednia trafia do archiwum (osiem ostatnich).

Czary, głosem albo w polu tekstowym:

- „Mille”, „Alea”: formularz do tysiąca albo do kości,
- „Lusor Primus Kacper”, „Lusor Secundus Maja”, „Lusor Tertius Wujek”, „Lusor Quartus Heniu” (i dalej do Octavus): gracz na danym miejscu; jeśli miejsce jest zajęte, zmienia się imię, a punkty zostają,
- „Nota hoc Kacper 120”, „Nota hoc Maja minus 60”: wpis w tysiącu (liczby cyframi albo słowami),
- „Nota hoc Kacper trójki 9”, „Nota hoc Maja full”, „Nota hoc Wujek generał skreśl”: wpis w kościach (stałe wartości bez liczby, „skreśl” albo „zero” wpisuje 0),
- „Nota hoc deleo”: wykreślenie ostatniego wpisu,
- „Nota hoc nova”: nowa partia tego samego rodzaju (albo „Nota hoc nova Kacper Maja i Borsuk”).

Głosem „Mille”, „Alea” i „Lusor …” działają same albo po „Nota hoc”, ale tylko wtedy, gdy całe zdanie jest czarem; pozostałe trzeba zacząć od „Nota hoc”, żeby rozmowa przy stole niczego nie wpisała. W polu tekstowym „Nota hoc” można pominąć. Imię dopasowuje się do najbliższego gracza, także w odmianie („Kacprowi”) i z drobną literówką rozpoznawania.

Mowę rozpoznaje przeglądarka (Web Speech API: Chrome i Edge, po polsku); nagranie idzie do usługi rozpoznawania mowy przeglądarki, a strona musi być pod HTTPS.

# Salon

Pokój z własną mechaniką: `pokoj.js` i `pokoj.css` (przeglądarka), `api/notes.php` (serwer), `_logika/stan/` (stan notesu, zamknięty przed przeglądarką). PHP musi móc pisać w `_logika/stan/`.

## Notes do tysiąca

Notes zapisuje punkty jednej wspólnej partii: kolumny to gracze, wiersze to kolejne wpisy, na dole sumy. Prowadzący jest wyróżniony, a kiedy ktoś osiągnie cel (domyślnie 1000; cel 0 oznacza grę bez końca, np. w kości), notes ogłasza zwycięzcę. Przy nowej partii stara trafia do archiwum (osiem ostatnich).

Zaklęcia, głosem albo w polu tekstowym (tam przedrostek „Nota hoc” można pominąć):

- „Nota hoc Kacper 120”, „Nota hoc Maja minus 60”: wpis punktów (liczby cyframi albo słowami, np. „sto dwadzieścia”),
- „Nota hoc deleo”: wykreślenie ostatniego wpisu,
- „Nota hoc nova”: nowa partia z tymi samymi graczami, a „Nota hoc nova Kacper Maja i Borsuk”: z podanymi.

Imię dopasowuje się do najbliższego gracza w notesie, także w odmianie („Kacprowi”) i z drobną literówką rozpoznawania. Mowę rozpoznaje przeglądarka (Web Speech API: Chrome i Edge, po polsku); nagranie idzie do usługi rozpoznawania mowy przeglądarki, a strona musi być pod HTTPS. Przy włączonym słuchaniu brane są pod uwagę tylko zdania zaczynające się od „Nota hoc”.

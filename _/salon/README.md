# Salon

Pokój z własną mechaniką: `pokoj.js` i `pokoj.css` (przeglądarka), `api/notes.php` (serwer), `_logika/stan/` (stan notesu, zamknięty przed przeglądarką). PHP musi móc pisać w `_logika/stan/`.

## Notes do gier

Notes zapisuje punkty jednej wspólnej partii (stan na serwerze, wszyscy goście widzą to samo) i idzie krokami. Na górze każdego kroku jest lista czarów tego kroku. Czar można rzucić głosem (przycisk „Rzuć czar”) albo wpisać w pole; w polu „Nota hoc” można pominąć.

1. **Wybór gry:** „Mille” (tysiąc) albo „Alea” (kości); można też kliknąć.
2. **Gracze:** „Lusor Primus Kopytko”, „Lusor Secundus Maja”, „Lusor Tertius …”, „Lusor Quartus …” (do Octavus). Nazwa gracza to dokładnie słowo po „Lusor Primus”. Imiona można też wpisać w pola, a gracza bez punktów usunąć. Na start są wpisani gracze z poprzedniej partii. „Incipiāmus” (albo przycisk) zaczyna grę.
3. **Punkty:** tabela, w której każdą komórkę można kliknąć i wpisać ręcznie (także imię w nagłówku).
   - Tysiąc: „Nota hoc Kopytko 60”, „Nota hoc Maja minus 60”, „Nota hoc Borsuk bomba” (Borsuk 0 ze znakiem ✹, pozostali po 60, przy czterech graczach po 40; jedna bomba przed 500 i jedna po 500). Zwycięzca po 1000.
   - Kości: „Nota hoc Majusia trēs 18”: imię, łacińska nazwa układu i punkty; „nihil” (albo „zero”) skreśla. Układy (`NOTES_UKLADY` w `api/notes.php`): Singulī, Bīnī, Ternī, Quaternī, Quīnī, Sēnī (jedynki–szóstki, premia +50 od 63), Pār (para), Duo paria (dwie pary), Trēs (trójka), Quattuor (kareta), Plēnus (full), Ōrdō minor (mały strit, 15), Ōrdō maior (duży strit, 20), Fortūna (szansa), Imperātor (generał, 50). Pióro pilnuje dozwolonych wyników; w polu działają też polskie nazwy.
   - „Nota hoc deleo” wykreśla ostatni wpis (bombę w całości), „Nota hoc nova” wraca do kroku 1, a partia trafia do archiwum.

Głosem czary gry, graczy i „Incipiāmus” działają same, jeśli całe zdanie jest czarem; wpisy punktów trzeba zacząć od „Nota hoc”, żeby rozmowa przy stole niczego nie wpisała. Pod przyciskiem widać, co pióro usłyszało. Mowę rozpoznaje przeglądarka (Web Speech API: Chrome i Edge, po polsku); nagranie idzie do usługi rozpoznawania mowy przeglądarki, a strona musi być pod HTTPS.

Jeśli serwer nie może pisać w `_logika/stan/`, notes pokazuje to wprost („Notes nie może pisać…”).

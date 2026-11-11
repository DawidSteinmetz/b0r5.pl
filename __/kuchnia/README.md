# Kuchnia

Pokój z własną mechaniką, ten sam kod co piwnica Henryka: `pokoj.js` i `pokoj.css` (przeglądarka), `api/` (serwer), `_logika/` (dane zamknięte przed przeglądarką). PHP musi móc pisać w `_logika/stan/`. Różnice to nazwy (garnek zamiast kociołka, Artemida zamiast Henryka) i dane w `_logika/`.

Na pergaminach są: Spiżarnia (Skład), Stół kuchenny, Waga kuchenna, Wielki garnek i Zeszyt domowy. Zasady działania opisuje `piwnica/README.md` u Henryka.

Czary-przepisy to kartki w `przepisy/` opisane w `_logika/tabele/przepisy.json`.

Katalizatory (`_logika/tabele/katalizatory.json`): cukrowy, magicznej czekolady (C₅₅H₁₀₄O₆) oraz fruktozy, schowany w szopie jako przedmiot przenośny „Zakurzony flakon”. Zapas startowy i surowce ręczne (woda, kakao, masło) są w `_logika/tabele/alchemia.json`. Ceny i budżet: `_logika/rynek.json`.

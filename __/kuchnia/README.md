# Kuchnia

Pokój z własną mechaniką, ten sam kod co piwnica Henryka: `pokoj.js` i `pokoj.css` (przeglądarka), `api/` (serwer), `_logika/` (dane zamknięte przed przeglądarką). PHP musi móc pisać w `_logika/stan/`. Różnice to nazwy (garnek zamiast kociołka, Artemida zamiast Henryka) i dane w `_logika/`.

Na pergaminach są: Spiżarnia (Skład), Stół kuchenny, Waga kuchenna, Wielki garnek i Zeszyt domowy. Zasady działania opisuje `piwnica/README.md` u Henryka.

Czary-przepisy to kartki w `przepisy/` opisane w `_logika/tabele/przepisy.json`.

Katalizatory (`_logika/tabele/katalizatory.json`): cukrowy, magicznej czekolady (C₅₅H₁₀₄O₆) oraz fruktozy, schowany w szopie jako przedmiot przenośny „Zakurzony flakon”. Zapas startowy i surowce ręczne (woda, kakao, masło) są w `_logika/tabele/alchemia.json`. Ceny i budżet: `_logika/rynek.json`.
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
- Wyroby łączy się ręcznie: ⧉ przy wyrobie podświetla te, z którymi można go połączyć (ten sam wyrób, ta sama czystość co do 0,1%), a „Połącz tu” je scala.
- Na wadze zbadane porcje mają znaczek ✓.


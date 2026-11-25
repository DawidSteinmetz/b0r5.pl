// PLIK GENEROWANY: python narzedzia/mostek/pulpity_js.py — nie edytuj, zmieniaj narzedzia/mostek/uklady/.
// Układy pulpitów (siatka 13×9, telefon 5×9): [typ, kolumna, wiersz, tekst, szer, wys, (tekst_min suwaka)].
// Typy: S suwak zakresu, R wzmocnij, Y suwak, G synchronizacja, B urządzenie / ekran, W polecenie.

export const KOLORY = {"S": "#999999", "R": "#ff3333", "Y": "#ffff99", "G": "#99ff99", "B": "#6666ff", "W": "#ffffff"};
export const FORMATY = {"ISD_I": {"Osłony": [13, 9], "Turbolasery": [13, 9], "Silniki": [13, 9], "Reaktory": [13, 9], "Kontrola ognia": [13, 9], "Sensory": [13, 9], "Grawitacja": [13, 9], "Myśliwce": [13, 9], "Nawigacja": [13, 9], "Radar": [13, 9], "Klucz": [5, 9], "Eskadra": [5, 9]}};
export const UKLADY = {
 "ISD_I": {
  "Osłony": [
   [
    "S",
    1,
    1,
    "suwak: maksymalna częstotliwość lewego generatora osłon",
    1,
    7,
    "suwak: minimalna częstotliwość lewego generatora osłon"
   ],
   [
    "S",
    13,
    1,
    "suwak: maksymalna częstotliwość prawego generatora osłon",
    1,
    7,
    "suwak: minimalna częstotliwość prawego generatora osłon"
   ],
   [
    "R",
    2,
    1,
    "wzmocnij górne osłony lewej burty",
    1,
    1
   ],
   [
    "R",
    12,
    1,
    "wzmocnij górne osłony prawej burty",
    1,
    1
   ],
   [
    "Y",
    3,
    1,
    "suwak: stopień pokrycia granic górnej osłony lewej burty",
    1,
    1
   ],
   [
    "Y",
    11,
    1,
    "suwak: stopień pokrycia granic górnej osłony prawej burty",
    1,
    1
   ],
   [
    "Y",
    7,
    1,
    "suwak: stopień pokrycia granic osłony dziobowej",
    1,
    1
   ],
   [
    "R",
    2,
    2,
    "wzmocnij dolne osłony lewej burty",
    1,
    1
   ],
   [
    "R",
    12,
    2,
    "wzmocnij dolne osłony prawej burty",
    1,
    1
   ],
   [
    "B",
    3,
    2,
    "GENERATOR LEWY · SIATKA 60×60 (3600 lampek) · ekran dynamiczny · żyły: świecące / martwe / temperatura",
    3,
    3
   ],
   [
    "B",
    9,
    2,
    "GENERATOR PRAWY · SIATKA 60×60 (3600 lampek) · ekran dynamiczny · żyły: świecące / martwe / temperatura",
    3,
    3
   ],
   [
    "R",
    7,
    2,
    "wzmocnij osłony dziobowe",
    1,
    1
   ],
   [
    "W",
    7,
    3,
    "wyrównaj osłony",
    1,
    1
   ],
   [
    "R",
    7,
    4,
    "wzmocnij osłonę hangaru",
    1,
    1
   ],
   [
    "G",
    2,
    5,
    "synchronizacja z lewą baterią turbolaserów",
    1,
    1
   ],
   [
    "G",
    12,
    5,
    "synchronizacja z prawą baterią turbolaserów",
    1,
    1
   ],
   [
    "Y",
    7,
    5,
    "suwak: stopień pokrycia granic osłony hangarów",
    1,
    1
   ],
   [
    "R",
    7,
    6,
    "wzmocnij osłony rufowe",
    1,
    1
   ],
   [
    "Y",
    3,
    6,
    "suwak: stopień pokrycia granic dolnej osłony lewej burty",
    1,
    1
   ],
   [
    "Y",
    11,
    6,
    "suwak: stopień pokrycia granic dolnej osłony prawej burty",
    1,
    1
   ],
   [
    "G",
    2,
    7,
    "synchronizacja osłony rufowej z lewym silnikiem głównym",
    1,
    1
   ],
   [
    "G",
    12,
    7,
    "synchronizacja osłony rufowej z prawym silnikiem głównym",
    1,
    1
   ],
   [
    "G",
    6,
    7,
    "synchronizacja z lewym promieniem ściągającym",
    1,
    1
   ],
   [
    "G",
    8,
    7,
    "synchronizacja z prawym promieniem ściągającym",
    1,
    1
   ],
   [
    "Y",
    7,
    7,
    "suwak: stopień pokrycia granic osłony rufowej",
    1,
    1
   ],
   [
    "W",
    7,
    8,
    "równaj częstotliwość z reaktorem",
    1,
    1
   ],
   [
    "G",
    7,
    9,
    "synchronizacja osłony rufowej ze środkowym silnikiem głównym",
    1,
    1
   ]
  ],
  "Turbolasery": [
   [
    "S",
    1,
    1,
    "suwak: maksymalna częstotliwość lewej baterii turbolaserów",
    1,
    7,
    "suwak: minimalna częstotliwość lewej baterii turbolaserów"
   ],
   [
    "S",
    13,
    1,
    "suwak: maksymalna częstotliwość prawej baterii turbolaserów",
    1,
    7,
    "suwak: minimalna częstotliwość prawej baterii turbolaserów"
   ],
   [
    "R",
    2,
    1,
    "wzmocnij lewe przednie działka PDLC",
    1,
    1
   ],
   [
    "R",
    12,
    1,
    "wzmocnij prawe przednie działka PDLC",
    1,
    1
   ],
   [
    "Y",
    3,
    1,
    "suwak: kąt pokrycia lewych przednich działek PDLC",
    1,
    1
   ],
   [
    "Y",
    11,
    1,
    "suwak: kąt pokrycia prawych przednich działek PDLC",
    1,
    1
   ],
   [
    "B",
    6,
    1,
    "Turbolaser lewy 1 (8 luf)",
    1,
    2
   ],
   [
    "B",
    8,
    1,
    "Turbolaser prawy 1 (8 luf)",
    1,
    2
   ],
   [
    "Y",
    7,
    1,
    "suwak: kąt pokrycia dziobowych działek PDLC",
    1,
    1
   ],
   [
    "R",
    2,
    2,
    "wzmocnij lewą baterię turbolaserów",
    1,
    1
   ],
   [
    "R",
    12,
    2,
    "wzmocnij prawą baterię turbolaserów",
    1,
    1
   ],
   [
    "B",
    3,
    3,
    "WYBRANA BATERIA LEWA · SIATKA 60×60 (3600 lampek) · ekran dynamiczny · aktywne żyły = Hz",
    3,
    3
   ],
   [
    "B",
    9,
    3,
    "WYBRANA BATERIA PRAWA · SIATKA 60×60 (3600 lampek) · ekran dynamiczny · aktywne żyły = Hz",
    3,
    3
   ],
   [
    "R",
    7,
    2,
    "wzmocnij dziobowe działka PDLC",
    1,
    1
   ],
   [
    "B",
    6,
    3,
    "Turbolaser lewy 2 (8 luf)",
    1,
    2
   ],
   [
    "B",
    8,
    3,
    "Turbolaser prawy 2 (8 luf)",
    1,
    2
   ],
   [
    "Y",
    7,
    3,
    "suwak: kąt pokrycia dolnych działek PDLC",
    1,
    1
   ],
   [
    "G",
    2,
    4,
    "synchronizacja lewej baterii z kontrolą ognia",
    1,
    1
   ],
   [
    "G",
    12,
    4,
    "synchronizacja prawej baterii z kontrolą ognia",
    1,
    1
   ],
   [
    "R",
    7,
    4,
    "wzmocnij dolne działka PDLC",
    1,
    1
   ],
   [
    "G",
    2,
    5,
    "synchronizacja lewej baterii z lewym generatorem osłon",
    1,
    1
   ],
   [
    "G",
    12,
    5,
    "synchronizacja prawej baterii z prawym generatorem osłon",
    1,
    1
   ],
   [
    "B",
    6,
    5,
    "Turbolaser lewy 3 (8 luf)",
    1,
    2
   ],
   [
    "B",
    8,
    5,
    "Turbolaser prawy 3 (8 luf)",
    1,
    2
   ],
   [
    "W",
    7,
    5,
    "wyrównaj baterie",
    1,
    1
   ],
   [
    "R",
    2,
    6,
    "wzmocnij lewe boczne działka PDLC",
    1,
    1
   ],
   [
    "R",
    12,
    6,
    "wzmocnij prawe boczne działka PDLC",
    1,
    1
   ],
   [
    "Y",
    3,
    7,
    "suwak: kąt pokrycia lewych bocznych działek PDLC",
    1,
    1
   ],
   [
    "Y",
    11,
    7,
    "suwak: kąt pokrycia prawych bocznych działek PDLC",
    1,
    1
   ],
   [
    "R",
    7,
    6,
    "wzmocnij rufowe działka PDLC",
    1,
    1
   ],
   [
    "G",
    2,
    7,
    "synchronizacja lewych PDLC z konsolą myśliwców (IFF)",
    1,
    1
   ],
   [
    "G",
    12,
    7,
    "synchronizacja prawych PDLC z konsolą myśliwców (IFF)",
    1,
    1
   ],
   [
    "B",
    6,
    7,
    "Turbolaser lewy 4 (8 luf)",
    1,
    2
   ],
   [
    "B",
    8,
    7,
    "Turbolaser prawy 4 (8 luf)",
    1,
    2
   ],
   [
    "Y",
    7,
    7,
    "suwak: kąt pokrycia rufowych działek PDLC",
    1,
    1
   ],
   [
    "W",
    7,
    8,
    "równaj częstotliwość z reaktorem",
    1,
    1
   ],
   [
    "G",
    7,
    9,
    "synchronizacja z konsolą sensorów (Hz celów)",
    1,
    1
   ]
  ],
  "Silniki": [
   [
    "S",
    1,
    1,
    "suwak: maksymalny ciąg lewego silnika głównego",
    1,
    7,
    "suwak: minimalny ciąg lewego silnika głównego"
   ],
   [
    "S",
    13,
    1,
    "suwak: maksymalny ciąg prawego silnika głównego",
    1,
    7,
    "suwak: minimalny ciąg prawego silnika głównego"
   ],
   [
    "R",
    2,
    1,
    "wzmocnij lewy górny silnik manewrowy",
    1,
    1
   ],
   [
    "R",
    12,
    1,
    "wzmocnij prawy górny silnik manewrowy",
    1,
    1
   ],
   [
    "Y",
    3,
    1,
    "suwak: ciąg lewego górnego silnika manewrowego",
    1,
    1
   ],
   [
    "Y",
    11,
    1,
    "suwak: ciąg prawego górnego silnika manewrowego",
    1,
    1
   ],
   [
    "B",
    4,
    1,
    "Silnik manewrowy lewy górny",
    2,
    2
   ],
   [
    "B",
    9,
    1,
    "Silnik manewrowy prawy górny",
    2,
    2
   ],
   [
    "Y",
    7,
    1,
    "suwak: trym pochylenia (silniki manewrowe)",
    1,
    1
   ],
   [
    "Y",
    3,
    2,
    "suwak: wychylenie dyszy lewego górnego silnika manewrowego",
    1,
    1
   ],
   [
    "Y",
    11,
    2,
    "suwak: wychylenie dyszy prawego górnego silnika manewrowego",
    1,
    1
   ],
   [
    "Y",
    7,
    2,
    "suwak: trym odchylenia (silniki manewrowe)",
    1,
    1
   ],
   [
    "G",
    2,
    3,
    "synchronizacja lewych silników manewrowych z lewym silnikiem głównym",
    1,
    1
   ],
   [
    "G",
    12,
    3,
    "synchronizacja prawych silników manewrowych z prawym silnikiem głównym",
    1,
    1
   ],
   [
    "B",
    3,
    3,
    "Silnik główny lewy",
    3,
    3
   ],
   [
    "B",
    9,
    3,
    "Silnik główny prawy",
    3,
    3
   ],
   [
    "R",
    7,
    3,
    "wzmocnij wszystkie silniki manewrowe",
    1,
    1
   ],
   [
    "R",
    2,
    4,
    "wzmocnij lewy silnik główny",
    1,
    1
   ],
   [
    "R",
    12,
    4,
    "wzmocnij prawy silnik główny",
    1,
    1
   ],
   [
    "W",
    7,
    4,
    "wyrównaj ciąg silników",
    1,
    1
   ],
   [
    "W",
    7,
    5,
    "równaj ciąg z mocą reaktora",
    1,
    1
   ],
   [
    "R",
    2,
    6,
    "wzmocnij lewy dolny silnik manewrowy",
    1,
    1
   ],
   [
    "R",
    12,
    6,
    "wzmocnij prawy dolny silnik manewrowy",
    1,
    1
   ],
   [
    "Y",
    3,
    6,
    "suwak: ciąg lewego dolnego silnika manewrowego",
    1,
    1
   ],
   [
    "Y",
    11,
    6,
    "suwak: ciąg prawego dolnego silnika manewrowego",
    1,
    1
   ],
   [
    "B",
    4,
    6,
    "Silnik manewrowy lewy dolny",
    2,
    2
   ],
   [
    "B",
    9,
    6,
    "Silnik manewrowy prawy dolny",
    2,
    2
   ],
   [
    "R",
    7,
    6,
    "wzmocnij środkowy silnik główny",
    1,
    1
   ],
   [
    "G",
    2,
    7,
    "synchronizacja lewego silnika głównego z osłoną rufową",
    1,
    1
   ],
   [
    "G",
    12,
    7,
    "synchronizacja prawego silnika głównego z osłoną rufową",
    1,
    1
   ],
   [
    "Y",
    3,
    7,
    "suwak: wychylenie dyszy lewego dolnego silnika manewrowego",
    1,
    1
   ],
   [
    "Y",
    11,
    7,
    "suwak: wychylenie dyszy prawego dolnego silnika manewrowego",
    1,
    1
   ],
   [
    "G",
    6,
    7,
    "synchronizacja lewego silnika głównego ze środkowym",
    1,
    1
   ],
   [
    "G",
    8,
    7,
    "synchronizacja prawego silnika głównego ze środkowym",
    1,
    1
   ],
   [
    "Y",
    7,
    7,
    "suwak: ciąg środkowego silnika głównego",
    1,
    1
   ],
   [
    "B",
    6,
    8,
    "Silnik główny środkowy",
    3,
    2
   ]
  ],
  "Reaktory": [
   [
    "S",
    1,
    1,
    "suwak: maksymalna moc reaktora pomocniczego uzbrojenia",
    1,
    7,
    "suwak: minimalna moc reaktora pomocniczego uzbrojenia"
   ],
   [
    "S",
    13,
    1,
    "suwak: maksymalne obciążenie głównego generatora mocy",
    1,
    7,
    "suwak: minimalne obciążenie głównego generatora mocy"
   ],
   [
    "R",
    2,
    1,
    "wzmocnij reaktor pomocniczy uzbrojenia",
    1,
    1
   ],
   [
    "R",
    12,
    1,
    "wzmocnij główny generator mocy",
    1,
    1
   ],
   [
    "Y",
    3,
    1,
    "suwak: przydział mocy dla turbolaserów",
    1,
    1
   ],
   [
    "Y",
    4,
    1,
    "suwak: przydział mocy dla dział jonowych",
    1,
    1
   ],
   [
    "Y",
    10,
    1,
    "suwak: częstotliwość wyjściowa generatora",
    1,
    1
   ],
   [
    "Y",
    11,
    1,
    "suwak: napięcie wyjściowe generatora",
    1,
    1
   ],
   [
    "R",
    5,
    1,
    "wzmocnij przedni reaktor pomocniczy",
    1,
    1
   ],
   [
    "Y",
    9,
    1,
    "suwak: moc przedniego reaktora pomocniczego",
    1,
    1
   ],
   [
    "B",
    6,
    1,
    "Przedni reaktor pomocniczy",
    3,
    1
   ],
   [
    "B",
    2,
    2,
    "Reaktor pomocniczy uzbrojenia",
    3,
    3
   ],
   [
    "B",
    10,
    2,
    "Główny generator mocy",
    3,
    3
   ],
   [
    "G",
    5,
    2,
    "synchronizacja przedniego reaktora z promieniami ściągającymi",
    1,
    1
   ],
   [
    "W",
    9,
    2,
    "przełącz systemy podstawowe na przedni reaktor",
    1,
    1
   ],
   [
    "W",
    7,
    2,
    "wyrównaj obciążenie reaktorów",
    1,
    1
   ],
   [
    "Y",
    5,
    3,
    "suwak: gęstość hipermaterii w komorze",
    1,
    1
   ],
   [
    "Y",
    9,
    3,
    "suwak: przydział mocy dla osłon",
    1,
    1
   ],
   [
    "B",
    6,
    3,
    "REAKTOR GŁÓWNY (jonizacji słonecznej I-a2b) · SIATKA 60×60 (3600 lampek) · ekran dynamiczny · porcje mocy, ciepło, dławienie",
    3,
    3
   ],
   [
    "R",
    5,
    4,
    "wzmocnij reaktor główny",
    1,
    1
   ],
   [
    "Y",
    9,
    4,
    "suwak: przydział mocy dla silników jonowych",
    1,
    1
   ],
   [
    "G",
    2,
    5,
    "synchronizacja reaktora uzbrojenia z lewą baterią turbolaserów",
    1,
    1
   ],
   [
    "W",
    3,
    5,
    "wyrównaj zasilanie baterii",
    1,
    1
   ],
   [
    "G",
    4,
    5,
    "synchronizacja reaktora uzbrojenia z prawą baterią turbolaserów",
    1,
    1
   ],
   [
    "G",
    10,
    5,
    "synchronizacja generatora z konsolą silników",
    1,
    1
   ],
   [
    "W",
    11,
    5,
    "wyrównaj dystrybucję mocy",
    1,
    1
   ],
   [
    "G",
    12,
    5,
    "synchronizacja generatora z konsolą osłon",
    1,
    1
   ],
   [
    "Y",
    5,
    5,
    "suwak: siła pola zamykającego komory",
    1,
    1
   ],
   [
    "Y",
    9,
    5,
    "suwak: przydział mocy dla hipernapędu",
    1,
    1
   ],
   [
    "G",
    2,
    6,
    "synchronizacja reaktora uzbrojenia z reaktorem głównym",
    1,
    1
   ],
   [
    "G",
    12,
    6,
    "synchronizacja generatora z reaktorem głównym",
    1,
    1
   ],
   [
    "G",
    5,
    6,
    "synchronizacja reaktora głównego z reaktorami pomocniczymi",
    1,
    1
   ],
   [
    "G",
    9,
    6,
    "synchronizacja reaktora głównego z generatorem mocy",
    1,
    1
   ],
   [
    "Y",
    2,
    7,
    "suwak: rezerwa mocy na salwę",
    1,
    1
   ],
   [
    "Y",
    12,
    7,
    "suwak: rezerwa mocy awaryjnej",
    1,
    1
   ],
   [
    "R",
    6,
    7,
    "wzmocnij zasilanie lewej burty",
    1,
    1
   ],
   [
    "Y",
    7,
    7,
    "suwak: częstotliwość bazowa reaktora (wzorzec dla konsol)",
    1,
    1
   ],
   [
    "R",
    8,
    7,
    "wzmocnij zasilanie prawej burty",
    1,
    1
   ],
   [
    "R",
    5,
    8,
    "wzmocnij zapasowy reaktor silników",
    1,
    1
   ],
   [
    "Y",
    9,
    8,
    "suwak: moc zapasowego reaktora silników",
    1,
    1
   ],
   [
    "B",
    6,
    8,
    "Zapasowy reaktor silników",
    3,
    2
   ],
   [
    "G",
    5,
    9,
    "synchronizacja zapasowego reaktora z silnikiem głównym środkowym",
    1,
    1
   ],
   [
    "W",
    9,
    9,
    "przełącz silniki na zasilanie zapasowe",
    1,
    1
   ]
  ],
  "Kontrola ognia": [
   [
    "S",
    1,
    1,
    "suwak: maksymalne tempo ognia lewej baterii",
    1,
    7,
    "suwak: minimalne tempo ognia lewej baterii"
   ],
   [
    "S",
    13,
    1,
    "suwak: maksymalne tempo ognia prawej baterii",
    1,
    7,
    "suwak: minimalne tempo ognia prawej baterii"
   ],
   [
    "R",
    2,
    1,
    "wzmocnij ogień lewej baterii",
    1,
    1
   ],
   [
    "R",
    12,
    1,
    "wzmocnij ogień prawej baterii",
    1,
    1
   ],
   [
    "Y",
    3,
    1,
    "suwak: rozrzut salwy lewej baterii",
    1,
    1
   ],
   [
    "Y",
    11,
    1,
    "suwak: rozrzut salwy prawej baterii",
    1,
    1
   ],
   [
    "Y",
    7,
    1,
    "suwak: namiar celu (azymut)",
    1,
    1
   ],
   [
    "W",
    2,
    2,
    "przydziel cel lewej baterii",
    1,
    1
   ],
   [
    "W",
    12,
    2,
    "przydziel cel prawej baterii",
    1,
    1
   ],
   [
    "W",
    3,
    2,
    "blokada celu lewej baterii",
    1,
    1
   ],
   [
    "W",
    11,
    2,
    "blokada celu prawej baterii",
    1,
    1
   ],
   [
    "Y",
    7,
    2,
    "suwak: elewacja celu",
    1,
    1
   ],
   [
    "W",
    2,
    3,
    "tryb: pojedynczy strzał (lewa bateria)",
    1,
    1
   ],
   [
    "W",
    12,
    3,
    "tryb: pojedynczy strzał (prawa bateria)",
    1,
    1
   ],
   [
    "B",
    3,
    3,
    "wskazanie celu: siatka taktyczna",
    3,
    3
   ],
   [
    "B",
    9,
    3,
    "stan baterii i dział jonowych",
    3,
    3
   ],
   [
    "Y",
    7,
    3,
    "suwak: dystans do celu",
    1,
    1
   ],
   [
    "W",
    2,
    4,
    "tryb: salwa (lewa bateria)",
    1,
    1
   ],
   [
    "W",
    12,
    4,
    "tryb: salwa (prawa bateria)",
    1,
    1
   ],
   [
    "W",
    6,
    4,
    "przydziel cel działom jonowym",
    1,
    1
   ],
   [
    "W",
    7,
    4,
    "wyrównaj baterie na cel",
    1,
    1
   ],
   [
    "W",
    8,
    4,
    "tryb: obezwładnienie (działa jonowe)",
    1,
    1
   ],
   [
    "W",
    2,
    5,
    "tryb: ogień zaporowy (lewa bateria)",
    1,
    1
   ],
   [
    "W",
    12,
    5,
    "tryb: ogień zaporowy (prawa bateria)",
    1,
    1
   ],
   [
    "Y",
    6,
    5,
    "suwak: natężenie wiązki jonowej",
    1,
    1
   ],
   [
    "R",
    7,
    5,
    "wzmocnij salwę",
    1,
    1
   ],
   [
    "R",
    8,
    5,
    "wzmocnij działa jonowe",
    1,
    1
   ],
   [
    "G",
    2,
    6,
    "synchronizacja z lewą baterią turbolaserów",
    1,
    1
   ],
   [
    "G",
    12,
    6,
    "synchronizacja z prawą baterią turbolaserów",
    1,
    1
   ],
   [
    "Y",
    7,
    6,
    "suwak: opóźnienie między salwami lewej i prawej baterii",
    1,
    1
   ],
   [
    "G",
    2,
    7,
    "synchronizacja z konsolą dowodzenia myśliwcami (IFF)",
    1,
    1
   ],
   [
    "G",
    12,
    7,
    "synchronizacja z konsolą dowodzenia myśliwcami (IFF)",
    1,
    1
   ],
   [
    "Y",
    3,
    7,
    "suwak: tempo ognia lewej baterii",
    1,
    1
   ],
   [
    "Y",
    11,
    7,
    "suwak: tempo ognia prawej baterii",
    1,
    1
   ],
   [
    "Y",
    7,
    7,
    "suwak: czas trwania ognia zaporowego",
    1,
    1
   ],
   [
    "W",
    7,
    8,
    "równaj tempo ognia z mocą reaktora",
    1,
    1
   ],
   [
    "G",
    7,
    9,
    "synchronizacja z konsolą sensorów",
    1,
    1
   ]
  ],
  "Sensory": [
   [
    "S",
    1,
    1,
    "suwak: maksymalna moc skanu",
    1,
    7,
    "suwak: minimalna moc skanu"
   ],
   [
    "S",
    13,
    1,
    "suwak: górna granica pasma skanu (Hz)",
    1,
    7,
    "suwak: dolna granica pasma skanu (Hz)"
   ],
   [
    "R",
    2,
    1,
    "wzmocnij skan nadprzestrzeni",
    1,
    1
   ],
   [
    "R",
    12,
    1,
    "wzmocnij rozdzielczość skanu przestrzeni normalnej",
    1,
    1
   ],
   [
    "Y",
    3,
    1,
    "suwak: zasięg J skanu nadprzestrzeni (koszt k·J^1,618)",
    1,
    1
   ],
   [
    "Y",
    11,
    1,
    "suwak: pasmo skanu (niskie: małe cele · wysokie: dokładna pozycja)",
    1,
    1
   ],
   [
    "Y",
    7,
    1,
    "suwak: przydział mocy sensorów",
    1,
    1
   ],
   [
    "W",
    2,
    2,
    "filtr: ślady nadprzestrzenne",
    1,
    1
   ],
   [
    "W",
    12,
    2,
    "filtr: kontakty w przestrzeni normalnej",
    1,
    1
   ],
   [
    "B",
    5,
    2,
    "EKRAN SKANU · przestrzeń normalna (kontakty, elipsa błędu, sygnatura) i nadprzestrzeń (zmarszczki wejścia, odcinki skoków, prognoza wyjścia)",
    5,
    4
   ],
   [
    "Y",
    3,
    3,
    "suwak: czas integracji skanu nadprzestrzeni",
    1,
    1
   ],
   [
    "Y",
    11,
    3,
    "suwak: liczba próbek pomiaru Hz",
    1,
    1
   ],
   [
    "G",
    2,
    4,
    "synchronizacja z konsolą nawigacji (prognoza wyjścia)",
    1,
    1
   ],
   [
    "G",
    12,
    4,
    "synchronizacja z kontrolą ognia (cele)",
    1,
    1
   ],
   [
    "B",
    3,
    5,
    "odczyt: koszt skanu (porcje)",
    1,
    1
   ],
   [
    "B",
    11,
    5,
    "odczyt: elipsa błędu",
    1,
    1
   ],
   [
    "W",
    2,
    6,
    "tryb bierny (nasłuch)",
    1,
    1
   ],
   [
    "W",
    12,
    6,
    "tryb aktywny (emisja)",
    1,
    1
   ],
   [
    "W",
    5,
    6,
    "skan Hz celu (wybór grupy)",
    1,
    1
   ],
   [
    "G",
    6,
    6,
    "wyślij Hz celu do konsoli osłon",
    1,
    1
   ],
   [
    "W",
    7,
    6,
    "SKANUJ",
    1,
    1
   ],
   [
    "G",
    8,
    6,
    "wyślij Hz celu do kontroli ognia",
    1,
    1
   ],
   [
    "W",
    9,
    6,
    "zakłócanie (walka elektroniczna)",
    1,
    1
   ],
   [
    "B",
    6,
    7,
    "EKRAN: WIDMO Hz GRUP CELÓW · baza i bieżący odczyt · wykryte skany wroga",
    3,
    2
   ],
   [
    "W",
    7,
    9,
    "wyrównaj czułość sensorów",
    1,
    1
   ]
  ],
  "Grawitacja": [
   [
    "S",
    1,
    1,
    "suwak: maksymalna siła pola pokładowego",
    1,
    7,
    "suwak: minimalna siła pola pokładowego"
   ],
   [
    "S",
    13,
    1,
    "suwak: maksymalna moc blokady Anchor/Lock",
    1,
    7,
    "suwak: minimalna moc blokady Anchor/Lock"
   ],
   [
    "R",
    2,
    1,
    "wzmocnij grawitację pokładową",
    1,
    1
   ],
   [
    "R",
    12,
    1,
    "wzmocnij pole blokady",
    1,
    1
   ],
   [
    "B",
    6,
    1,
    "EKRAN: REFERENCJA · POZYCJA · STABILNOŚĆ blokady",
    3,
    3
   ],
   [
    "Y",
    3,
    2,
    "suwak: siła pola pokładowego",
    1,
    1
   ],
   [
    "Y",
    11,
    2,
    "suwak: siła pola blokady",
    1,
    1
   ],
   [
    "W",
    2,
    3,
    "tryb codzienny",
    1,
    1
   ],
   [
    "W",
    12,
    3,
    "tryb Anchor / Lock",
    1,
    1
   ],
   [
    "W",
    6,
    4,
    "wybierz ciało / punkt odniesienia",
    1,
    1
   ],
   [
    "W",
    7,
    4,
    "ZAŁÓŻ / ZWOLNIJ BLOKADĘ",
    1,
    1
   ],
   [
    "W",
    8,
    4,
    "ustaw pozycję względem odniesienia",
    1,
    1
   ],
   [
    "G",
    2,
    5,
    "synchronizacja z reaktorem (zasilanie pokładowe)",
    1,
    1
   ],
   [
    "G",
    12,
    5,
    "synchronizacja z konsolą nawigacji (pozycja)",
    1,
    1
   ],
   [
    "B",
    3,
    5,
    "odczyt: pobór mocy",
    1,
    1
   ],
   [
    "B",
    11,
    5,
    "odczyt: sygnatura",
    1,
    1
   ],
   [
    "B",
    7,
    5,
    "stan blokady: gotowa · stabilizowanie · utrzymana · zakłócona · zwalniana",
    1,
    1
   ],
   [
    "W",
    7,
    6,
    "wyrównaj pole w sekcjach",
    1,
    1
   ],
   [
    "Y",
    2,
    7,
    "suwak: zasilanie grawitacji pokładowej",
    1,
    1
   ],
   [
    "Y",
    12,
    7,
    "suwak: rezerwa mocy blokady",
    1,
    1
   ],
   [
    "B",
    5,
    7,
    "EKRAN: GRAWITACJA POKŁADÓW · sekcje, przeciążenia, spadki zasilania",
    5,
    2
   ],
   [
    "W",
    7,
    9,
    "równaj pole z częstotliwością reaktora",
    1,
    1
   ]
  ],
  "Myśliwce": [
   [
    "S",
    1,
    1,
    "suwak: maksymalna liczba kluczy do poderwania",
    1,
    7,
    "suwak: minimalna liczba kluczy do poderwania"
   ],
   [
    "S",
    13,
    1,
    "suwak: maksymalne tempo taśmociągu",
    1,
    7,
    "suwak: minimalne tempo taśmociągu"
   ],
   [
    "B",
    3,
    2,
    "Klucz 1",
    1,
    1
   ],
   [
    "B",
    9,
    2,
    "Klucz 10",
    1,
    1
   ],
   [
    "B",
    4,
    2,
    "Klucz 2",
    1,
    1
   ],
   [
    "B",
    10,
    2,
    "Klucz 11",
    1,
    1
   ],
   [
    "B",
    5,
    2,
    "Klucz 3",
    1,
    1
   ],
   [
    "B",
    11,
    2,
    "Klucz 12",
    1,
    1
   ],
   [
    "B",
    3,
    3,
    "Klucz 4",
    1,
    1
   ],
   [
    "B",
    9,
    3,
    "Klucz 13",
    1,
    1
   ],
   [
    "B",
    4,
    3,
    "Klucz 5",
    1,
    1
   ],
   [
    "B",
    10,
    3,
    "Klucz 14",
    1,
    1
   ],
   [
    "B",
    5,
    3,
    "Klucz 6",
    1,
    1
   ],
   [
    "B",
    11,
    3,
    "Klucz 15",
    1,
    1
   ],
   [
    "B",
    3,
    4,
    "Klucz 7",
    1,
    1
   ],
   [
    "B",
    9,
    4,
    "Klucz 16",
    1,
    1
   ],
   [
    "B",
    4,
    4,
    "Klucz 8",
    1,
    1
   ],
   [
    "B",
    10,
    4,
    "Klucz 17",
    1,
    1
   ],
   [
    "B",
    5,
    4,
    "Klucz 9",
    1,
    1
   ],
   [
    "B",
    11,
    4,
    "Klucz 18",
    1,
    1
   ],
   [
    "R",
    2,
    1,
    "wzmocnij taśmociąg lewego pokładu startowego",
    1,
    1
   ],
   [
    "R",
    12,
    1,
    "wzmocnij taśmociąg prawego pokładu startowego",
    1,
    1
   ],
   [
    "Y",
    3,
    1,
    "suwak: skład załogi (asy · standardowi · kadeci)",
    1,
    1
   ],
   [
    "Y",
    11,
    1,
    "suwak: liczba kluczy do poderwania",
    1,
    1
   ],
   [
    "W",
    6,
    1,
    "ALARM: normalny (1 klucz gotowy)",
    1,
    1
   ],
   [
    "W",
    7,
    1,
    "ALARM: żółty (4 klucze gotowe)",
    1,
    1
   ],
   [
    "W",
    8,
    1,
    "ALARM: czerwony (wszystkie klucze)",
    1,
    1
   ],
   [
    "B",
    6,
    2,
    "EKRAN: PILOCI · ZAŁOGA · TAŚMOCIĄG",
    3,
    3
   ],
   [
    "W",
    2,
    3,
    "start klucza (lewy pokład)",
    1,
    1
   ],
   [
    "W",
    12,
    3,
    "start klucza (prawy pokład)",
    1,
    1
   ],
   [
    "G",
    2,
    5,
    "synchronizacja IFF z kontrolą ognia",
    1,
    1
   ],
   [
    "G",
    12,
    5,
    "synchronizacja z sensorami (Hz celów)",
    1,
    1
   ],
   [
    "W",
    6,
    5,
    "rozkaz klucza: patrol · ochrona · atak · skan",
    1,
    1
   ],
   [
    "W",
    7,
    5,
    "kurs przechwycenia",
    1,
    1
   ],
   [
    "W",
    8,
    5,
    "wstrzymaj / zawróć",
    1,
    1
   ],
   [
    "B",
    3,
    6,
    "taśmociąg lewego pokładu · kolejka i ETA",
    3,
    1
   ],
   [
    "B",
    9,
    6,
    "taśmociąg prawego pokładu · kolejka i ETA",
    3,
    1
   ],
   [
    "W",
    2,
    7,
    "powrót do hangaru (lewy pokład)",
    1,
    1
   ],
   [
    "W",
    12,
    7,
    "powrót do hangaru (prawy pokład)",
    1,
    1
   ],
   [
    "W",
    7,
    7,
    "wezwij załogę i pilotów",
    1,
    1
   ],
   [
    "W",
    7,
    9,
    "START",
    1,
    1
   ]
  ],
  "Nawigacja": [
   [
    "S",
    1,
    1,
    "suwak: maksymalna prędkość kątowa skrętu w lewo",
    1,
    7,
    "suwak: minimalna prędkość kątowa skrętu w lewo"
   ],
   [
    "S",
    13,
    1,
    "suwak: maksymalna prędkość kątowa skrętu w prawo",
    1,
    7,
    "suwak: minimalna prędkość kątowa skrętu w prawo"
   ],
   [
    "R",
    2,
    1,
    "wzmocnij skręt w lewo",
    1,
    1
   ],
   [
    "R",
    12,
    1,
    "wzmocnij skręt w prawo",
    1,
    1
   ],
   [
    "Y",
    3,
    1,
    "suwak: moc silników manewrowych lewej burty",
    1,
    1
   ],
   [
    "Y",
    11,
    1,
    "suwak: moc silników manewrowych prawej burty",
    1,
    1
   ],
   [
    "W",
    4,
    1,
    "przesuw w lewo (bez zmiany zwrotu)",
    1,
    1
   ],
   [
    "W",
    10,
    1,
    "przesuw w prawo (bez zmiany zwrotu)",
    1,
    1
   ],
   [
    "W",
    5,
    1,
    "skala ekranu: 250 km",
    1,
    1
   ],
   [
    "W",
    7,
    1,
    "skala ekranu: 500 km",
    1,
    1
   ],
   [
    "W",
    9,
    1,
    "skala ekranu: 1000 km",
    1,
    1
   ],
   [
    "G",
    6,
    1,
    "synchronizacja z sensorami (kontakty)",
    1,
    1
   ],
   [
    "G",
    8,
    1,
    "synchronizacja z radarem (tory jednostek)",
    1,
    1
   ],
   [
    "Y",
    2,
    2,
    "suwak: skręt w lewo (różnica ciągu lewy − prawy)",
    1,
    3
   ],
   [
    "Y",
    12,
    2,
    "suwak: skręt w prawo (różnica ciągu prawy − lewy)",
    1,
    3
   ],
   [
    "Y",
    3,
    2,
    "suwak: kąt dysz manewrowych lewej burty (przesuw pod skosem)",
    1,
    2
   ],
   [
    "Y",
    11,
    2,
    "suwak: kąt dysz manewrowych prawej burty (przesuw pod skosem)",
    1,
    2
   ],
   [
    "B",
    4,
    2,
    "EKRAN NAWIGACJI · sylwetka ISD zawsze w środku, w skali · okręgi odległości 250 / 500 / 1000 km · wektor ruchu osobno od zwrotu dziobu · inne jednostki (własne, obce, nieznane) · kurs zadany i punkt docelowy",
    7,
    7
   ],
   [
    "B",
    3,
    4,
    "odczyt: prędkość kątowa",
    1,
    1
   ],
   [
    "B",
    11,
    4,
    "odczyt: przeciążenie",
    1,
    1
   ],
   [
    "W",
    2,
    5,
    "zwrot o 90° w lewo",
    1,
    1
   ],
   [
    "W",
    12,
    5,
    "zwrot o 90° w prawo",
    1,
    1
   ],
   [
    "W",
    3,
    5,
    "trym przechylenia w lewo",
    1,
    1
   ],
   [
    "W",
    11,
    5,
    "trym przechylenia w prawo",
    1,
    1
   ],
   [
    "G",
    2,
    6,
    "synchronizacja z konsolą silników (ciąg lewej burty)",
    1,
    1
   ],
   [
    "G",
    12,
    6,
    "synchronizacja z konsolą silników (ciąg prawej burty)",
    1,
    1
   ],
   [
    "B",
    3,
    6,
    "odczyt: wektor ruchu (kurs, prędkość)",
    1,
    1
   ],
   [
    "B",
    11,
    6,
    "odczyt: zwrot dziobu",
    1,
    1
   ],
   [
    "W",
    2,
    7,
    "wybierz cel / punkt nawigacyjny",
    1,
    1
   ],
   [
    "W",
    12,
    7,
    "kurs na wybrany cel",
    1,
    1
   ],
   [
    "W",
    3,
    7,
    "kurs przechwycenia",
    1,
    1
   ],
   [
    "W",
    11,
    7,
    "kurs odejścia",
    1,
    1
   ],
   [
    "G",
    2,
    8,
    "synchronizacja z hipernapędem (punkt skoku)",
    1,
    1
   ],
   [
    "G",
    12,
    8,
    "synchronizacja z grawitacją (Anchor / Lock)",
    1,
    1
   ],
   [
    "B",
    3,
    8,
    "odczyt: odległość do celu",
    1,
    1
   ],
   [
    "B",
    11,
    8,
    "odczyt: czas do celu (ETA)",
    1,
    1
   ],
   [
    "W",
    2,
    9,
    "wyrównaj kurs (stabilizacja zwrotu)",
    1,
    1
   ],
   [
    "W",
    12,
    9,
    "równaj ciąg z mocą reaktora",
    1,
    1
   ],
   [
    "R",
    3,
    9,
    "wzmocnij hamowanie",
    1,
    1
   ],
   [
    "R",
    11,
    9,
    "wzmocnij ciąg główny",
    1,
    1
   ],
   [
    "W",
    4,
    9,
    "PEŁNE HAMOWANIE",
    1,
    1
   ],
   [
    "W",
    10,
    9,
    "CAŁA NAPRZÓD",
    1,
    1
   ],
   [
    "Y",
    5,
    9,
    "suwak: ciąg główny (prędkość zadana)",
    5,
    1
   ]
  ],
  "Radar": [
   [
    "S",
    1,
    1,
    "suwak: maksymalny zasięg radaru",
    1,
    7,
    "suwak: minimalny zasięg (tłumienie bliskich ech)"
   ],
   [
    "S",
    13,
    1,
    "suwak: maksymalna czułość odbiornika",
    1,
    7,
    "suwak: minimalna czułość (próg szumu)"
   ],
   [
    "R",
    2,
    1,
    "wzmocnij nadajnik (zasięg)",
    1,
    1
   ],
   [
    "R",
    12,
    1,
    "wzmocnij odbiornik (czułość)",
    1,
    1
   ],
   [
    "Y",
    3,
    1,
    "suwak: moc nadajnika",
    1,
    1
   ],
   [
    "Y",
    11,
    1,
    "suwak: wzmocnienie odbiornika",
    1,
    1
   ],
   [
    "G",
    6,
    1,
    "synchronizacja z sensorami (dane skanu, Hz celów)",
    1,
    1
   ],
   [
    "G",
    8,
    1,
    "synchronizacja z nawigacją (kurs i prędkość własna)",
    1,
    1
   ],
   [
    "Y",
    7,
    1,
    "suwak: tempo omiatania",
    1,
    1
   ],
   [
    "W",
    5,
    2,
    "sektor dziobowy lewej burty",
    1,
    1
   ],
   [
    "W",
    6,
    2,
    "skala: 12 km",
    1,
    1
   ],
   [
    "W",
    7,
    2,
    "skala: 30 km",
    1,
    1
   ],
   [
    "W",
    8,
    2,
    "skala: 60 km",
    1,
    1
   ],
   [
    "W",
    9,
    2,
    "sektor dziobowy prawej burty",
    1,
    1
   ],
   [
    "B",
    2,
    2,
    "odczyt: kontakty obce",
    1,
    1
   ],
   [
    "B",
    12,
    2,
    "odczyt: kontakty własne",
    1,
    1
   ],
   [
    "Y",
    3,
    2,
    "suwak: próg alarmu zbliżenia",
    1,
    1
   ],
   [
    "Y",
    11,
    2,
    "suwak: próg alarmu kursu kolizyjnego",
    1,
    1
   ],
   [
    "B",
    5,
    3,
    "EKRAN RADARU · sylwetka ISD zawsze w środku · bliski zasięg 12 km na bieżąco · średni do 60 km odświeżany wiązką · kontakty IFF (własne, obce, nieznane) · klucze jako jeden symbol · tory i prognozy torów · pociski",
    5,
    5
   ],
   [
    "W",
    4,
    3,
    "filtr: własne",
    1,
    1
   ],
   [
    "W",
    10,
    3,
    "filtr: obce",
    1,
    1
   ],
   [
    "B",
    2,
    3,
    "LISTA ZAGROŻEŃ LEWEJ BURTY · namiar, odległość, czas do zasięgu ognia",
    2,
    4
   ],
   [
    "B",
    11,
    3,
    "LISTA ZAGROŻEŃ PRAWEJ BURTY · namiar, odległość, czas do zasięgu ognia",
    2,
    4
   ],
   [
    "W",
    4,
    4,
    "filtr: klucze myśliwców",
    1,
    1
   ],
   [
    "W",
    10,
    4,
    "filtr: okręty",
    1,
    1
   ],
   [
    "W",
    4,
    5,
    "filtr: pociski i bolty",
    1,
    1
   ],
   [
    "W",
    10,
    5,
    "filtr: nieznane",
    1,
    1
   ],
   [
    "G",
    4,
    6,
    "synchronizacja IFF z konsolą myśliwców",
    1,
    1
   ],
   [
    "G",
    10,
    6,
    "synchronizacja z kontrolą ognia (przydział celów)",
    1,
    1
   ],
   [
    "W",
    4,
    7,
    "oznacz kontakt",
    1,
    1
   ],
   [
    "W",
    10,
    7,
    "śledź kontakt (tor)",
    1,
    1
   ],
   [
    "W",
    2,
    7,
    "tryb bierny (nasłuch)",
    1,
    1
   ],
   [
    "W",
    12,
    7,
    "tryb aktywny (emisja)",
    1,
    1
   ],
   [
    "G",
    3,
    7,
    "wyślij namiar zagrożenia do osłon",
    1,
    1
   ],
   [
    "G",
    11,
    7,
    "wyślij namiar zagrożenia do turbolaserów",
    1,
    1
   ],
   [
    "W",
    5,
    8,
    "sektor rufowy lewej burty",
    1,
    1
   ],
   [
    "W",
    6,
    8,
    "pokaż tory",
    1,
    1
   ],
   [
    "W",
    7,
    8,
    "ALARM ZBLIŻENIA",
    1,
    1
   ],
   [
    "W",
    8,
    8,
    "pokaż prognozy torów",
    1,
    1
   ],
   [
    "W",
    9,
    8,
    "sektor rufowy prawej burty",
    1,
    1
   ],
   [
    "R",
    2,
    8,
    "wzmocnij tłumienie zakłóceń",
    1,
    1
   ],
   [
    "R",
    12,
    8,
    "wzmocnij rozdzielczość obrazu",
    1,
    1
   ],
   [
    "W",
    2,
    9,
    "kasuj ślady",
    1,
    1
   ],
   [
    "W",
    12,
    9,
    "zamroź obraz",
    1,
    1
   ],
   [
    "W",
    5,
    9,
    "zakłócenia: tłumienie",
    1,
    1
   ],
   [
    "W",
    7,
    9,
    "wyrównaj obraz (filtr szumu)",
    1,
    1
   ],
   [
    "W",
    9,
    9,
    "zakłócenia: wskaż źródło",
    1,
    1
   ]
  ],
  "Klucz": [
   [
    "B",
    1,
    1,
    "KLUCZ N · 4/4 maszyn · pilot prowadzący i ranga · stan: w hangarze — start za mm:ss / gotowy / w locie (kolor nagłówka = kolor lampy klucza)",
    5,
    1
   ],
   [
    "W",
    1,
    2,
    "łagodnie w lewo",
    1,
    1
   ],
   [
    "W",
    5,
    2,
    "łagodnie w prawo",
    1,
    1
   ],
   [
    "B",
    2,
    2,
    "EKRAN KLUCZA · klucz zawsze w środku · okręgi 2 / 10 / 30 km (dotknięcie zmienia skalę) · kurs i wektor · cele (dotknij, by wybrać) · okręt macierzysty · inne klucze",
    3,
    3
   ],
   [
    "W",
    1,
    3,
    "ostro w lewo",
    1,
    1
   ],
   [
    "W",
    5,
    3,
    "ostro w prawo",
    1,
    1
   ],
   [
    "W",
    1,
    4,
    "rozejście klucza w lewo",
    1,
    1
   ],
   [
    "W",
    5,
    4,
    "rozejście klucza w prawo",
    1,
    1
   ],
   [
    "W",
    1,
    5,
    "beczka w lewo (rozrzut ×1,6)",
    1,
    1
   ],
   [
    "W",
    2,
    5,
    "zygzak (×1,3)",
    1,
    1
   ],
   [
    "W",
    3,
    5,
    "PROSTO",
    1,
    1
   ],
   [
    "W",
    4,
    5,
    "burst (×1,5)",
    1,
    1
   ],
   [
    "W",
    5,
    5,
    "beczka w prawo (×1,6)",
    1,
    1
   ],
   [
    "W",
    1,
    6,
    "pętla (zawrót górą)",
    1,
    1
   ],
   [
    "W",
    2,
    6,
    "spirala asa (×2, tylko as)",
    1,
    1
   ],
   [
    "W",
    3,
    6,
    "zwarcie szyku",
    1,
    1
   ],
   [
    "W",
    4,
    6,
    "skok cieniowy (×2,5, tylko as)",
    1,
    1
   ],
   [
    "W",
    5,
    6,
    "split-S (zawrót dołem)",
    1,
    1
   ],
   [
    "W",
    1,
    7,
    "atak z nurkowania",
    1,
    1
   ],
   [
    "W",
    2,
    7,
    "nożyce (pojedynek)",
    1,
    1
   ],
   [
    "W",
    3,
    7,
    "ogon (trzymaj się celu)",
    1,
    1
   ],
   [
    "W",
    4,
    7,
    "przelot przez cel",
    1,
    1
   ],
   [
    "W",
    5,
    7,
    "eskorta wskazanej jednostki",
    1,
    1
   ],
   [
    "Y",
    1,
    8,
    "suwak: moc na działka (podnosi Hz — dryf)",
    1,
    1
   ],
   [
    "G",
    2,
    8,
    "pobierz Hz celu z sensorów okrętu",
    1,
    1
   ],
   [
    "B",
    3,
    8,
    "odczyt: Hz klucza (baza / bieżący) · zmiany w cyklu",
    1,
    1
   ],
   [
    "G",
    4,
    8,
    "IFF z kontrolą ognia okrętu",
    1,
    1
   ],
   [
    "Y",
    5,
    8,
    "suwak: moc na osłony i silniki",
    1,
    1
   ],
   [
    "W",
    1,
    9,
    "patrol",
    1,
    1
   ],
   [
    "W",
    2,
    9,
    "ochrona okrętu",
    1,
    1
   ],
   [
    "W",
    3,
    9,
    "START (gdy gotowy) · ATAK (w locie)",
    1,
    1
   ],
   [
    "W",
    4,
    9,
    "skan z bliska",
    1,
    1
   ],
   [
    "W",
    5,
    9,
    "powrót do hangaru",
    1,
    1
   ]
  ],
  "Eskadra": [
   [
    "B",
    1,
    1,
    "ESKADRA N · klucze 3/3 · dowódca eskadry · alarm: normalny / żółty / czerwony",
    5,
    1
   ],
   [
    "B",
    1,
    2,
    "EKRAN ESKADRY · środek eskadry zawsze w środku · okręgi 5 / 15 / 40 km · 3 klucze z kursami · okręt macierzysty · cele i zagrożenia",
    5,
    5
   ],
   [
    "B",
    1,
    7,
    "Klucz 1 · start za mm:ss",
    1,
    1
   ],
   [
    "G",
    2,
    7,
    "zgraj kursy kluczy 1 i 2",
    1,
    1
   ],
   [
    "B",
    3,
    7,
    "Klucz 2 · start za mm:ss",
    1,
    1
   ],
   [
    "G",
    4,
    7,
    "zgraj kursy kluczy 2 i 3",
    1,
    1
   ],
   [
    "B",
    5,
    7,
    "Klucz 3 · start za mm:ss",
    1,
    1
   ],
   [
    "W",
    1,
    8,
    "szyk: klin",
    1,
    1
   ],
   [
    "W",
    2,
    8,
    "szyk: linia",
    1,
    1
   ],
   [
    "W",
    3,
    8,
    "szyk: kolumna",
    1,
    1
   ],
   [
    "W",
    4,
    8,
    "szyk: rozproszony",
    1,
    1
   ],
   [
    "W",
    5,
    8,
    "szyk: parasol nad okrętem",
    1,
    1
   ],
   [
    "W",
    1,
    9,
    "patrol",
    1,
    1
   ],
   [
    "W",
    2,
    9,
    "przechwycenie",
    1,
    1
   ],
   [
    "R",
    3,
    9,
    "ATAK ESKADRY",
    1,
    1
   ],
   [
    "W",
    4,
    9,
    "eskorta",
    1,
    1
   ],
   [
    "W",
    5,
    9,
    "powrót eskadry",
    1,
    1
   ]
  ]
 }
};
// Uzbrojenie na kadłubie (dane/uzbrojenie.py): [nr, rodzaj, x, y, łuk środek, łuk połowa, zasięg km, θ mrad, Hz].
export const UZBROJENIE = {"ISD_I": [[1, "turbolaser", -0.1625, 0.358, -90.0, 80.0, 40.0, 1.0, 3600], [5, "turbolaser", 0.1625, 0.358, 90.0, 80.0, 40.0, 1.0, 3600], [2, "turbolaser", -0.158, 0.275, -90.0, 80.0, 40.0, 1.0, 3600], [6, "turbolaser", 0.158, 0.275, 90.0, 80.0, 40.0, 1.0, 3600], [3, "turbolaser", -0.15, 0.2, -90.0, 80.0, 40.0, 1.0, 3600], [7, "turbolaser", 0.15, 0.2, 90.0, 80.0, 40.0, 1.0, 3600], [4, "turbolaser", -0.1375, 0.125, -90.0, 80.0, 40.0, 1.0, 3600], [8, "turbolaser", 0.1375, 0.125, 90.0, 80.0, 40.0, 1.0, 3600], [9, "jon", -0.03, 0.0375, 0.0, 180.0, 20.0, 1.5, 1800], [10, "jon", 0.03, 0.0375, 0.0, 180.0, 20.0, 1.5, 1800], [11, "oslona", -0.079, 0.4125, 0.0, 0.0, 0.0, 0.0, 0], [12, "oslona", 0.079, 0.4125, 0.0, 0.0, 0.0, 0.0, 0], [13, "sensory", 0.0, 0.4125, 0.0, 0.0, 0.0, 0.0, 0], [14, "silnik", -0.17, 0.4625, 180.0, 0.0, 0.0, 0.0, 0], [15, "silnik", 0.0, 0.533, 180.0, 0.0, 0.0, 0.0, 0], [16, "silnik", 0.17, 0.4625, 180.0, 0.0, 0.0, 0.0, 0], [101, "pd", -0.0048, -0.4848, -72.3, 90.0, 5.0, 8.0, 1500], [102, "pd", -0.0145, -0.4545, -72.3, 90.0, 5.0, 8.0, 1500], [103, "pd", -0.0242, -0.4242, -72.3, 90.0, 5.0, 8.0, 1500], [104, "pd", -0.0338, -0.3938, -72.3, 90.0, 5.0, 8.0, 1500], [105, "pd", -0.0435, -0.3635, -72.3, 90.0, 5.0, 8.0, 1500], [106, "pd", -0.0532, -0.3332, -72.3, 90.0, 5.0, 8.0, 1500], [107, "pd", -0.0628, -0.3028, -72.3, 90.0, 5.0, 8.0, 1500], [108, "pd", -0.0725, -0.2725, -72.3, 90.0, 5.0, 8.0, 1500], [109, "pd", -0.0822, -0.2422, -72.3, 90.0, 5.0, 8.0, 1500], [110, "pd", -0.0918, -0.2118, -72.3, 90.0, 5.0, 8.0, 1500], [111, "pd", -0.1015, -0.1815, -72.3, 90.0, 5.0, 8.0, 1500], [112, "pd", -0.1112, -0.1512, -72.3, 90.0, 5.0, 8.0, 1500], [113, "pd", -0.1208, -0.1208, -72.3, 90.0, 5.0, 8.0, 1500], [114, "pd", -0.1305, -0.0905, -72.3, 90.0, 5.0, 8.0, 1500], [115, "pd", -0.1402, -0.0602, -72.3, 90.0, 5.0, 8.0, 1500], [116, "pd", -0.1498, -0.0298, -72.3, 90.0, 5.0, 8.0, 1500], [117, "pd", -0.1595, 0.0005, -72.3, 90.0, 5.0, 8.0, 1500], [118, "pd", -0.1692, 0.0308, -72.3, 90.0, 5.0, 8.0, 1500], [119, "pd", -0.1788, 0.0612, -72.3, 90.0, 5.0, 8.0, 1500], [120, "pd", -0.1885, 0.0915, -72.3, 90.0, 5.0, 8.0, 1500], [121, "pd", -0.1982, 0.1218, -72.3, 90.0, 5.0, 8.0, 1500], [122, "pd", -0.2078, 0.1522, -72.3, 90.0, 5.0, 8.0, 1500], [123, "pd", -0.2175, 0.1825, -72.3, 90.0, 5.0, 8.0, 1500], [124, "pd", -0.2272, 0.2128, -72.3, 90.0, 5.0, 8.0, 1500], [125, "pd", -0.2368, 0.2432, -72.3, 90.0, 5.0, 8.0, 1500], [126, "pd", -0.2465, 0.2735, -72.3, 90.0, 5.0, 8.0, 1500], [127, "pd", -0.2562, 0.3038, -72.3, 90.0, 5.0, 8.0, 1500], [128, "pd", -0.2658, 0.3342, -72.3, 90.0, 5.0, 8.0, 1500], [129, "pd", -0.2755, 0.3645, -72.3, 90.0, 5.0, 8.0, 1500], [130, "pd", -0.2852, 0.3948, -72.3, 90.0, 5.0, 8.0, 1500], [131, "pd", 0.0048, -0.4848, 72.3, 90.0, 5.0, 8.0, 1500], [132, "pd", 0.0145, -0.4545, 72.3, 90.0, 5.0, 8.0, 1500], [133, "pd", 0.0242, -0.4242, 72.3, 90.0, 5.0, 8.0, 1500], [134, "pd", 0.0338, -0.3938, 72.3, 90.0, 5.0, 8.0, 1500], [135, "pd", 0.0435, -0.3635, 72.3, 90.0, 5.0, 8.0, 1500], [136, "pd", 0.0532, -0.3332, 72.3, 90.0, 5.0, 8.0, 1500], [137, "pd", 0.0628, -0.3028, 72.3, 90.0, 5.0, 8.0, 1500], [138, "pd", 0.0725, -0.2725, 72.3, 90.0, 5.0, 8.0, 1500], [139, "pd", 0.0822, -0.2422, 72.3, 90.0, 5.0, 8.0, 1500], [140, "pd", 0.0918, -0.2118, 72.3, 90.0, 5.0, 8.0, 1500], [141, "pd", 0.1015, -0.1815, 72.3, 90.0, 5.0, 8.0, 1500], [142, "pd", 0.1112, -0.1512, 72.3, 90.0, 5.0, 8.0, 1500], [143, "pd", 0.1208, -0.1208, 72.3, 90.0, 5.0, 8.0, 1500], [144, "pd", 0.1305, -0.0905, 72.3, 90.0, 5.0, 8.0, 1500], [145, "pd", 0.1402, -0.0602, 72.3, 90.0, 5.0, 8.0, 1500], [146, "pd", 0.1498, -0.0298, 72.3, 90.0, 5.0, 8.0, 1500], [147, "pd", 0.1595, 0.0005, 72.3, 90.0, 5.0, 8.0, 1500], [148, "pd", 0.1692, 0.0308, 72.3, 90.0, 5.0, 8.0, 1500], [149, "pd", 0.1788, 0.0612, 72.3, 90.0, 5.0, 8.0, 1500], [150, "pd", 0.1885, 0.0915, 72.3, 90.0, 5.0, 8.0, 1500], [151, "pd", 0.1982, 0.1218, 72.3, 90.0, 5.0, 8.0, 1500], [152, "pd", 0.2078, 0.1522, 72.3, 90.0, 5.0, 8.0, 1500], [153, "pd", 0.2175, 0.1825, 72.3, 90.0, 5.0, 8.0, 1500], [154, "pd", 0.2272, 0.2128, 72.3, 90.0, 5.0, 8.0, 1500], [155, "pd", 0.2368, 0.2432, 72.3, 90.0, 5.0, 8.0, 1500], [156, "pd", 0.2465, 0.2735, 72.3, 90.0, 5.0, 8.0, 1500], [157, "pd", 0.2562, 0.3038, 72.3, 90.0, 5.0, 8.0, 1500], [158, "pd", 0.2658, 0.3342, 72.3, 90.0, 5.0, 8.0, 1500], [159, "pd", 0.2755, 0.3645, 72.3, 90.0, 5.0, 8.0, 1500], [160, "pd", 0.2852, 0.3948, 72.3, 90.0, 5.0, 8.0, 1500]], "MC80": [[1, "turbolaser", -0.095, -0.15, -90.0, 80.0, 40.0, 1.0, 2187], [4, "turbolaser", 0.095, -0.15, 90.0, 80.0, 40.0, 1.0, 2187], [2, "turbolaser", -0.095, 0.05, -90.0, 80.0, 40.0, 1.0, 2187], [5, "turbolaser", 0.095, 0.05, 90.0, 80.0, 40.0, 1.0, 2187], [3, "turbolaser", -0.095, 0.25, -90.0, 80.0, 40.0, 1.0, 2187], [6, "turbolaser", 0.095, 0.25, 90.0, 80.0, 40.0, 1.0, 2187], [7, "jon", -0.05, -0.3, -90.0, 100.0, 20.0, 1.5, 1458], [10, "jon", 0.05, -0.3, 90.0, 100.0, 20.0, 1.5, 1458], [8, "jon", -0.05, 0.0, -90.0, 100.0, 20.0, 1.5, 1458], [11, "jon", 0.05, 0.0, 90.0, 100.0, 20.0, 1.5, 1458], [9, "jon", -0.05, 0.3, -90.0, 100.0, 20.0, 1.5, 1458], [12, "jon", 0.05, 0.3, 90.0, 100.0, 20.0, 1.5, 1458], [13, "oslona", -0.15, -0.1, 0.0, 0.0, 0.0, 0.0, 0], [14, "oslona", -0.15, 0.1, 0.0, 0.0, 0.0, 0.0, 0], [15, "oslona", -0.15, 0.25, 0.0, 0.0, 0.0, 0.0, 0], [16, "oslona", 0.15, -0.1, 0.0, 0.0, 0.0, 0.0, 0], [17, "oslona", 0.15, 0.1, 0.0, 0.0, 0.0, 0.0, 0], [18, "oslona", 0.15, 0.25, 0.0, 0.0, 0.0, 0.0, 0], [19, "sensory", 0.0, -0.27, 0.0, 0.0, 0.0, 0.0, 0], [20, "silnik", -0.05, 0.5, 180.0, 0.0, 0.0, 0.0, 0], [21, "silnik", 0.0, 0.5, 180.0, 0.0, 0.0, 0.0, 0], [22, "silnik", 0.05, 0.5, 180.0, 0.0, 0.0, 0.0, 0], [101, "pd", -0.09, -0.3707, -90.0, 90.0, 5.0, 8.0, 1458], [102, "pd", -0.09, -0.3121, -90.0, 90.0, 5.0, 8.0, 1458], [103, "pd", -0.09, -0.2536, -90.0, 90.0, 5.0, 8.0, 1458], [104, "pd", -0.09, -0.195, -90.0, 90.0, 5.0, 8.0, 1458], [105, "pd", -0.09, -0.1364, -90.0, 90.0, 5.0, 8.0, 1458], [106, "pd", -0.09, -0.0779, -90.0, 90.0, 5.0, 8.0, 1458], [107, "pd", -0.09, -0.0193, -90.0, 90.0, 5.0, 8.0, 1458], [108, "pd", -0.09, 0.0393, -90.0, 90.0, 5.0, 8.0, 1458], [109, "pd", -0.09, 0.0979, -90.0, 90.0, 5.0, 8.0, 1458], [110, "pd", -0.09, 0.1564, -90.0, 90.0, 5.0, 8.0, 1458], [111, "pd", -0.09, 0.215, -90.0, 90.0, 5.0, 8.0, 1458], [112, "pd", -0.09, 0.2736, -90.0, 90.0, 5.0, 8.0, 1458], [113, "pd", -0.09, 0.3321, -90.0, 90.0, 5.0, 8.0, 1458], [114, "pd", -0.09, 0.3907, -90.0, 90.0, 5.0, 8.0, 1458], [115, "pd", 0.09, -0.3685, 90.0, 90.0, 5.0, 8.0, 1458], [116, "pd", 0.09, -0.3054, 90.0, 90.0, 5.0, 8.0, 1458], [117, "pd", 0.09, -0.2423, 90.0, 90.0, 5.0, 8.0, 1458], [118, "pd", 0.09, -0.1792, 90.0, 90.0, 5.0, 8.0, 1458], [119, "pd", 0.09, -0.1162, 90.0, 90.0, 5.0, 8.0, 1458], [120, "pd", 0.09, -0.0531, 90.0, 90.0, 5.0, 8.0, 1458], [121, "pd", 0.09, 0.01, 90.0, 90.0, 5.0, 8.0, 1458], [122, "pd", 0.09, 0.0731, 90.0, 90.0, 5.0, 8.0, 1458], [123, "pd", 0.09, 0.1362, 90.0, 90.0, 5.0, 8.0, 1458], [124, "pd", 0.09, 0.1992, 90.0, 90.0, 5.0, 8.0, 1458], [125, "pd", 0.09, 0.2623, 90.0, 90.0, 5.0, 8.0, 1458], [126, "pd", 0.09, 0.3254, 90.0, 90.0, 5.0, 8.0, 1458], [127, "pd", 0.09, 0.3885, 90.0, 90.0, 5.0, 8.0, 1458]]};

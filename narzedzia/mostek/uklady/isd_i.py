"""Układy pulpitów ISD I (8 pulpitów). Format elementów i zasady siatki: `siatka.py` i `PULPITY.md`.

Osłony, Turbolasery, Silniki, Reaktory, Kontrola ognia — przeniesione z arkusza użytkownika na siatkę kwadratową;
Sensory, Grawitacja, Myśliwce — nowe, w tym samym języku, z własną kompozycją.
"""
from siatka import suwaki
from profile_klas import PROFILE

E60 = PROFILE['ISD_I']['oslony']['ekran']  # 'SIATKA 60×60 (3600 lampek) · ekran dynamiczny'

UKLADY = {}

UKLADY['Osłony'] = suwaki('suwak: maksymalna częstotliwość lewego generatora osłon', 'suwak: minimalna częstotliwość lewego generatora osłon',
                          'suwak: maksymalna częstotliwość prawego generatora osłon', 'suwak: minimalna częstotliwość prawego generatora osłon') + [
    ('R', 2, 1, 'wzmocnij górne osłony lewej burty'), ('R', 12, 1, 'wzmocnij górne osłony prawej burty'),
    ('Y', 3, 1, 'suwak: stopień pokrycia granic górnej osłony lewej burty'), ('Y', 11, 1, 'suwak: stopień pokrycia granic górnej osłony prawej burty'),
    ('Y', 7, 1, 'suwak: stopień pokrycia granic osłony dziobowej'),
    ('R', 2, 2, 'wzmocnij dolne osłony lewej burty'), ('R', 12, 2, 'wzmocnij dolne osłony prawej burty'),
    ('B', 3, 2, 'GENERATOR LEWY · ' + E60 + ' · żyły: świecące / martwe / temperatura', 3, 3),
    ('B', 9, 2, 'GENERATOR PRAWY · ' + E60 + ' · żyły: świecące / martwe / temperatura', 3, 3),
    ('R', 7, 2, 'wzmocnij osłony dziobowe'),
    ('W', 7, 3, 'wyrównaj osłony'),
    ('R', 7, 4, 'wzmocnij osłonę hangaru'),
    ('G', 2, 5, 'synchronizacja z lewą baterią turbolaserów'), ('G', 12, 5, 'synchronizacja z prawą baterią turbolaserów'),
    ('Y', 7, 5, 'suwak: stopień pokrycia granic osłony hangarów'),
    ('R', 7, 6, 'wzmocnij osłony rufowe'),
    ('Y', 3, 6, 'suwak: stopień pokrycia granic dolnej osłony lewej burty'), ('Y', 11, 6, 'suwak: stopień pokrycia granic dolnej osłony prawej burty'),
    ('G', 2, 7, 'synchronizacja osłony rufowej z lewym silnikiem głównym'), ('G', 12, 7, 'synchronizacja osłony rufowej z prawym silnikiem głównym'),
    ('G', 6, 7, 'synchronizacja z lewym promieniem ściągającym'), ('G', 8, 7, 'synchronizacja z prawym promieniem ściągającym'),
    ('Y', 7, 7, 'suwak: stopień pokrycia granic osłony rufowej'),
    ('W', 7, 8, 'równaj częstotliwość z reaktorem'),
    ('G', 7, 9, 'synchronizacja osłony rufowej ze środkowym silnikiem głównym'),
]

UKLADY['Turbolasery'] = suwaki('suwak: maksymalna częstotliwość lewej baterii turbolaserów', 'suwak: minimalna częstotliwość lewej baterii turbolaserów',
                               'suwak: maksymalna częstotliwość prawej baterii turbolaserów', 'suwak: minimalna częstotliwość prawej baterii turbolaserów') + [
    ('R', 2, 1, 'wzmocnij lewe przednie działka PDLC'), ('R', 12, 1, 'wzmocnij prawe przednie działka PDLC'),
    ('Y', 3, 1, 'suwak: kąt pokrycia lewych przednich działek PDLC'), ('Y', 11, 1, 'suwak: kąt pokrycia prawych przednich działek PDLC'),
    ('B', 6, 1, 'Turbolaser lewy 1 (8 luf)', 1, 2), ('B', 8, 1, 'Turbolaser prawy 1 (8 luf)', 1, 2),
    ('Y', 7, 1, 'suwak: kąt pokrycia dziobowych działek PDLC'),
    ('R', 2, 2, 'wzmocnij lewą baterię turbolaserów'), ('R', 12, 2, 'wzmocnij prawą baterię turbolaserów'),
    ('B', 3, 3, 'WYBRANA BATERIA LEWA · ' + E60 + ' · aktywne żyły = Hz', 3, 3),
    ('B', 9, 3, 'WYBRANA BATERIA PRAWA · ' + E60 + ' · aktywne żyły = Hz', 3, 3),
    ('R', 7, 2, 'wzmocnij dziobowe działka PDLC'),
    ('B', 6, 3, 'Turbolaser lewy 2 (8 luf)', 1, 2), ('B', 8, 3, 'Turbolaser prawy 2 (8 luf)', 1, 2),
    ('Y', 7, 3, 'suwak: kąt pokrycia dolnych działek PDLC'),
    ('G', 2, 4, 'synchronizacja lewej baterii z kontrolą ognia'), ('G', 12, 4, 'synchronizacja prawej baterii z kontrolą ognia'),
    ('R', 7, 4, 'wzmocnij dolne działka PDLC'),
    ('G', 2, 5, 'synchronizacja lewej baterii z lewym generatorem osłon'), ('G', 12, 5, 'synchronizacja prawej baterii z prawym generatorem osłon'),
    ('B', 6, 5, 'Turbolaser lewy 3 (8 luf)', 1, 2), ('B', 8, 5, 'Turbolaser prawy 3 (8 luf)', 1, 2),
    ('W', 7, 5, 'wyrównaj baterie'),
    ('R', 2, 6, 'wzmocnij lewe boczne działka PDLC'), ('R', 12, 6, 'wzmocnij prawe boczne działka PDLC'),
    ('Y', 3, 7, 'suwak: kąt pokrycia lewych bocznych działek PDLC'), ('Y', 11, 7, 'suwak: kąt pokrycia prawych bocznych działek PDLC'),
    ('R', 7, 6, 'wzmocnij rufowe działka PDLC'),
    ('G', 2, 7, 'synchronizacja lewych PDLC z konsolą myśliwców (IFF)'), ('G', 12, 7, 'synchronizacja prawych PDLC z konsolą myśliwców (IFF)'),
    ('B', 6, 7, 'Turbolaser lewy 4 (8 luf)', 1, 2), ('B', 8, 7, 'Turbolaser prawy 4 (8 luf)', 1, 2),
    ('Y', 7, 7, 'suwak: kąt pokrycia rufowych działek PDLC'),
    ('W', 7, 8, 'równaj częstotliwość z reaktorem'),
    ('G', 7, 9, 'synchronizacja z konsolą sensorów (Hz celów)'),
]

UKLADY['Silniki'] = suwaki('suwak: maksymalny ciąg lewego silnika głównego', 'suwak: minimalny ciąg lewego silnika głównego',
                           'suwak: maksymalny ciąg prawego silnika głównego', 'suwak: minimalny ciąg prawego silnika głównego') + [
    ('R', 2, 1, 'wzmocnij lewy górny silnik manewrowy'), ('R', 12, 1, 'wzmocnij prawy górny silnik manewrowy'),
    ('Y', 3, 1, 'suwak: ciąg lewego górnego silnika manewrowego'), ('Y', 11, 1, 'suwak: ciąg prawego górnego silnika manewrowego'),
    ('B', 4, 1, 'Silnik manewrowy lewy górny', 2, 2), ('B', 9, 1, 'Silnik manewrowy prawy górny', 2, 2),
    ('Y', 7, 1, 'suwak: trym pochylenia (silniki manewrowe)'),
    ('Y', 3, 2, 'suwak: wychylenie dyszy lewego górnego silnika manewrowego'), ('Y', 11, 2, 'suwak: wychylenie dyszy prawego górnego silnika manewrowego'),
    ('Y', 7, 2, 'suwak: trym odchylenia (silniki manewrowe)'),
    ('G', 2, 3, 'synchronizacja lewych silników manewrowych z lewym silnikiem głównym'), ('G', 12, 3, 'synchronizacja prawych silników manewrowych z prawym silnikiem głównym'),
    ('B', 3, 3, 'Silnik główny lewy', 3, 3), ('B', 9, 3, 'Silnik główny prawy', 3, 3),
    ('R', 7, 3, 'wzmocnij wszystkie silniki manewrowe'),
    ('R', 2, 4, 'wzmocnij lewy silnik główny'), ('R', 12, 4, 'wzmocnij prawy silnik główny'),
    ('W', 7, 4, 'wyrównaj ciąg silników'),
    ('W', 7, 5, 'równaj ciąg z mocą reaktora'),
    ('R', 2, 6, 'wzmocnij lewy dolny silnik manewrowy'), ('R', 12, 6, 'wzmocnij prawy dolny silnik manewrowy'),
    ('Y', 3, 6, 'suwak: ciąg lewego dolnego silnika manewrowego'), ('Y', 11, 6, 'suwak: ciąg prawego dolnego silnika manewrowego'),
    ('B', 4, 6, 'Silnik manewrowy lewy dolny', 2, 2), ('B', 9, 6, 'Silnik manewrowy prawy dolny', 2, 2),
    ('R', 7, 6, 'wzmocnij środkowy silnik główny'),
    ('G', 2, 7, 'synchronizacja lewego silnika głównego z osłoną rufową'), ('G', 12, 7, 'synchronizacja prawego silnika głównego z osłoną rufową'),
    ('Y', 3, 7, 'suwak: wychylenie dyszy lewego dolnego silnika manewrowego'), ('Y', 11, 7, 'suwak: wychylenie dyszy prawego dolnego silnika manewrowego'),
    ('G', 6, 7, 'synchronizacja lewego silnika głównego ze środkowym'), ('G', 8, 7, 'synchronizacja prawego silnika głównego ze środkowym'),
    ('Y', 7, 7, 'suwak: ciąg środkowego silnika głównego'),
    ('B', 6, 8, 'Silnik główny środkowy', 3, 2),
]

UKLADY['Reaktory'] = suwaki('suwak: maksymalna moc reaktora pomocniczego uzbrojenia', 'suwak: minimalna moc reaktora pomocniczego uzbrojenia',
                            'suwak: maksymalne obciążenie głównego generatora mocy', 'suwak: minimalne obciążenie głównego generatora mocy') + [
    ('R', 2, 1, 'wzmocnij reaktor pomocniczy uzbrojenia'), ('R', 12, 1, 'wzmocnij główny generator mocy'),
    ('Y', 3, 1, 'suwak: przydział mocy dla turbolaserów'), ('Y', 4, 1, 'suwak: przydział mocy dla dział jonowych'),
    ('Y', 10, 1, 'suwak: częstotliwość wyjściowa generatora'), ('Y', 11, 1, 'suwak: napięcie wyjściowe generatora'),
    ('R', 5, 1, 'wzmocnij przedni reaktor pomocniczy'), ('Y', 9, 1, 'suwak: moc przedniego reaktora pomocniczego'),
    ('B', 6, 1, 'Przedni reaktor pomocniczy', 3, 1),
    ('B', 2, 2, 'Reaktor pomocniczy uzbrojenia', 3, 3), ('B', 10, 2, 'Główny generator mocy', 3, 3),
    ('G', 5, 2, 'synchronizacja przedniego reaktora z promieniami ściągającymi'), ('W', 9, 2, 'przełącz systemy podstawowe na przedni reaktor'),
    ('W', 7, 2, 'wyrównaj obciążenie reaktorów'),
    ('Y', 5, 3, 'suwak: gęstość hipermaterii w komorze'), ('Y', 9, 3, 'suwak: przydział mocy dla osłon'),
    ('B', 6, 3, 'REAKTOR GŁÓWNY (jonizacji słonecznej I-a2b) · ' + E60 + ' · porcje mocy, ciepło, dławienie', 3, 3),
    ('R', 5, 4, 'wzmocnij reaktor główny'), ('Y', 9, 4, 'suwak: przydział mocy dla silników jonowych'),
    ('G', 2, 5, 'synchronizacja reaktora uzbrojenia z lewą baterią turbolaserów'), ('W', 3, 5, 'wyrównaj zasilanie baterii'),
    ('G', 4, 5, 'synchronizacja reaktora uzbrojenia z prawą baterią turbolaserów'),
    ('G', 10, 5, 'synchronizacja generatora z konsolą silników'), ('W', 11, 5, 'wyrównaj dystrybucję mocy'),
    ('G', 12, 5, 'synchronizacja generatora z konsolą osłon'),
    ('Y', 5, 5, 'suwak: siła pola zamykającego komory'), ('Y', 9, 5, 'suwak: przydział mocy dla hipernapędu'),
    ('G', 2, 6, 'synchronizacja reaktora uzbrojenia z reaktorem głównym'), ('G', 12, 6, 'synchronizacja generatora z reaktorem głównym'),
    ('G', 5, 6, 'synchronizacja reaktora głównego z reaktorami pomocniczymi'), ('G', 9, 6, 'synchronizacja reaktora głównego z generatorem mocy'),
    ('Y', 2, 7, 'suwak: rezerwa mocy na salwę'), ('Y', 12, 7, 'suwak: rezerwa mocy awaryjnej'),
    ('R', 6, 7, 'wzmocnij zasilanie lewej burty'), ('Y', 7, 7, 'suwak: częstotliwość bazowa reaktora (wzorzec dla konsol)'),
    ('R', 8, 7, 'wzmocnij zasilanie prawej burty'),
    ('R', 5, 8, 'wzmocnij zapasowy reaktor silników'), ('Y', 9, 8, 'suwak: moc zapasowego reaktora silników'),
    ('B', 6, 8, 'Zapasowy reaktor silników', 3, 2),
    ('G', 5, 9, 'synchronizacja zapasowego reaktora z silnikiem głównym środkowym'), ('W', 9, 9, 'przełącz silniki na zasilanie zapasowe'),
]

UKLADY['Kontrola ognia'] = suwaki('suwak: maksymalne tempo ognia lewej baterii', 'suwak: minimalne tempo ognia lewej baterii',
                                  'suwak: maksymalne tempo ognia prawej baterii', 'suwak: minimalne tempo ognia prawej baterii') + [
    ('R', 2, 1, 'wzmocnij ogień lewej baterii'), ('R', 12, 1, 'wzmocnij ogień prawej baterii'),
    ('Y', 3, 1, 'suwak: rozrzut salwy lewej baterii'), ('Y', 11, 1, 'suwak: rozrzut salwy prawej baterii'),
    ('Y', 7, 1, 'suwak: namiar celu (azymut)'),
    ('W', 2, 2, 'przydziel cel lewej baterii'), ('W', 12, 2, 'przydziel cel prawej baterii'),
    ('W', 3, 2, 'blokada celu lewej baterii'), ('W', 11, 2, 'blokada celu prawej baterii'),
    ('Y', 7, 2, 'suwak: elewacja celu'),
    ('W', 2, 3, 'tryb: pojedynczy strzał (lewa bateria)'), ('W', 12, 3, 'tryb: pojedynczy strzał (prawa bateria)'),
    ('B', 3, 3, 'wskazanie celu: siatka taktyczna', 3, 3), ('B', 9, 3, 'stan baterii i dział jonowych', 3, 3),
    ('Y', 7, 3, 'suwak: dystans do celu'),
    ('W', 2, 4, 'tryb: salwa (lewa bateria)'), ('W', 12, 4, 'tryb: salwa (prawa bateria)'),
    ('W', 6, 4, 'przydziel cel działom jonowym'), ('W', 7, 4, 'wyrównaj baterie na cel'), ('W', 8, 4, 'tryb: obezwładnienie (działa jonowe)'),
    ('W', 2, 5, 'tryb: ogień zaporowy (lewa bateria)'), ('W', 12, 5, 'tryb: ogień zaporowy (prawa bateria)'),
    ('Y', 6, 5, 'suwak: natężenie wiązki jonowej'), ('R', 7, 5, 'wzmocnij salwę'), ('R', 8, 5, 'wzmocnij działa jonowe'),
    ('G', 2, 6, 'synchronizacja z lewą baterią turbolaserów'), ('G', 12, 6, 'synchronizacja z prawą baterią turbolaserów'),
    ('Y', 7, 6, 'suwak: opóźnienie między salwami lewej i prawej baterii'),
    ('G', 2, 7, 'synchronizacja z konsolą dowodzenia myśliwcami (IFF)'), ('G', 12, 7, 'synchronizacja z konsolą dowodzenia myśliwcami (IFF)'),
    ('Y', 3, 7, 'suwak: tempo ognia lewej baterii'), ('Y', 11, 7, 'suwak: tempo ognia prawej baterii'),
    ('Y', 7, 7, 'suwak: czas trwania ognia zaporowego'),
    ('W', 7, 8, 'równaj tempo ognia z mocą reaktora'),
    ('G', 7, 9, 'synchronizacja z konsolą sensorów'),
]


# Sensory: jeden duży ekran skanu pośrodku (warstwy: przestrzeń normalna + nadprzestrzeń), lewa strona —
# nadprzestrzeń, prawa — przestrzeń normalna, pod ekranem pas poleceń i ekran widma Hz.
UKLADY['Sensory'] = suwaki('suwak: maksymalna moc skanu', 'suwak: minimalna moc skanu',
                           'suwak: górna granica pasma skanu (Hz)', 'suwak: dolna granica pasma skanu (Hz)') + [
    ('R', 2, 1, 'wzmocnij skan nadprzestrzeni'), ('R', 12, 1, 'wzmocnij rozdzielczość skanu przestrzeni normalnej'),
    ('Y', 3, 1, 'suwak: zasięg J skanu nadprzestrzeni (koszt k·J^1,618)'), ('Y', 11, 1, 'suwak: pasmo skanu (niskie: małe cele · wysokie: dokładna pozycja)'),
    ('Y', 7, 1, 'suwak: przydział mocy sensorów'),
    ('W', 2, 2, 'filtr: ślady nadprzestrzenne'), ('W', 12, 2, 'filtr: kontakty w przestrzeni normalnej'),
    ('B', 5, 2, 'EKRAN SKANU · przestrzeń normalna (kontakty, elipsa błędu, sygnatura) i nadprzestrzeń (zmarszczki wejścia, odcinki skoków, prognoza wyjścia)', 5, 4),
    ('Y', 3, 3, 'suwak: czas integracji skanu nadprzestrzeni'), ('Y', 11, 3, 'suwak: liczba próbek pomiaru Hz'),
    ('G', 2, 4, 'synchronizacja z konsolą nawigacji (prognoza wyjścia)'), ('G', 12, 4, 'synchronizacja z kontrolą ognia (cele)'),
    ('B', 3, 5, 'odczyt: koszt skanu (porcje)'), ('B', 11, 5, 'odczyt: elipsa błędu'),
    ('W', 2, 6, 'tryb bierny (nasłuch)'), ('W', 12, 6, 'tryb aktywny (emisja)'),
    ('W', 5, 6, 'skan Hz celu (wybór grupy)'), ('G', 6, 6, 'wyślij Hz celu do konsoli osłon'), ('W', 7, 6, 'SKANUJ'),
    ('G', 8, 6, 'wyślij Hz celu do kontroli ognia'), ('W', 9, 6, 'zakłócanie (walka elektroniczna)'),
    ('B', 6, 7, 'EKRAN: WIDMO Hz GRUP CELÓW · baza i bieżący odczyt · wykryte skany wroga', 3, 2),
    ('W', 7, 9, 'wyrównaj czułość sensorów'),
]

# Grawitacja: ekran odniesienia u góry pośrodku z poleceniami blokady pod nim, szeroki ekran sekcji pokładów
# na dole; lewa strona — grawitacja pokładowa, prawa — Anchor/Lock.
UKLADY['Grawitacja'] = suwaki('suwak: maksymalna siła pola pokładowego', 'suwak: minimalna siła pola pokładowego',
                              'suwak: maksymalna moc blokady Anchor/Lock', 'suwak: minimalna moc blokady Anchor/Lock') + [
    ('R', 2, 1, 'wzmocnij grawitację pokładową'), ('R', 12, 1, 'wzmocnij pole blokady'),
    ('B', 6, 1, 'EKRAN: REFERENCJA · POZYCJA · STABILNOŚĆ blokady', 3, 3),
    ('Y', 3, 2, 'suwak: siła pola pokładowego'), ('Y', 11, 2, 'suwak: siła pola blokady'),
    ('W', 2, 3, 'tryb codzienny'), ('W', 12, 3, 'tryb Anchor / Lock'),
    ('W', 6, 4, 'wybierz ciało / punkt odniesienia'), ('W', 7, 4, 'ZAŁÓŻ / ZWOLNIJ BLOKADĘ'), ('W', 8, 4, 'ustaw pozycję względem odniesienia'),
    ('G', 2, 5, 'synchronizacja z reaktorem (zasilanie pokładowe)'), ('G', 12, 5, 'synchronizacja z konsolą nawigacji (pozycja)'),
    ('B', 3, 5, 'odczyt: pobór mocy'), ('B', 11, 5, 'odczyt: sygnatura'),
    ('B', 7, 5, 'stan blokady: gotowa · stabilizowanie · utrzymana · zakłócona · zwalniana'),
    ('W', 7, 6, 'wyrównaj pole w sekcjach'),
    ('Y', 2, 7, 'suwak: zasilanie grawitacji pokładowej'), ('Y', 12, 7, 'suwak: rezerwa mocy blokady'),
    ('B', 5, 7, 'EKRAN: GRAWITACJA POKŁADÓW · sekcje, przeciążenia, spadki zasilania', 5, 2),
    ('W', 7, 9, 'równaj pole z częstotliwością reaktora'),
]

# Myśliwce: 18 kluczy jako 18 osobnych lampek (9 na lewy, 9 na prawy pokład startowy), alarm w poziomym pasie
# u góry, ekran pilotów pośrodku, taśmociągi pod kluczami.
klucze = []
for i in range(9):
    kol, wiersz = 3 + i % 3, 2 + i // 3
    klucze.append(('B', kol, wiersz, f'Klucz {i + 1}'))
    klucze.append(('B', 9 + i % 3, wiersz, f'Klucz {i + 10}'))
UKLADY['Myśliwce'] = suwaki('suwak: maksymalna liczba kluczy do poderwania', 'suwak: minimalna liczba kluczy do poderwania',
                            'suwak: maksymalne tempo taśmociągu', 'suwak: minimalne tempo taśmociągu') + klucze + [
    ('R', 2, 1, 'wzmocnij taśmociąg lewego pokładu startowego'), ('R', 12, 1, 'wzmocnij taśmociąg prawego pokładu startowego'),
    ('Y', 3, 1, 'suwak: skład załogi (asy · standardowi · kadeci)'), ('Y', 11, 1, 'suwak: liczba kluczy do poderwania'),
    ('W', 6, 1, 'ALARM: normalny (1 klucz gotowy)'), ('W', 7, 1, 'ALARM: żółty (4 klucze gotowe)'), ('W', 8, 1, 'ALARM: czerwony (wszystkie klucze)'),
    ('B', 6, 2, 'EKRAN: PILOCI · ZAŁOGA · TAŚMOCIĄG', 3, 3),
    ('W', 2, 3, 'start klucza (lewy pokład)'), ('W', 12, 3, 'start klucza (prawy pokład)'),
    ('G', 2, 5, 'synchronizacja IFF z kontrolą ognia'), ('G', 12, 5, 'synchronizacja z sensorami (Hz celów)'),
    ('W', 6, 5, 'rozkaz klucza: patrol · ochrona · atak · skan'), ('W', 7, 5, 'kurs przechwycenia'), ('W', 8, 5, 'wstrzymaj / zawróć'),
    ('B', 3, 6, 'taśmociąg lewego pokładu · kolejka i ETA', 3, 1), ('B', 9, 6, 'taśmociąg prawego pokładu · kolejka i ETA', 3, 1),
    ('W', 2, 7, 'powrót do hangaru (lewy pokład)'), ('W', 12, 7, 'powrót do hangaru (prawy pokład)'),
    ('W', 7, 7, 'wezwij załogę i pilotów'),
    ('W', 7, 9, 'START'),
]

# Nawigacja: duży ekran 7×7 na samym środku (sylwetka ISD zawsze w centrum, w skali; okręgi odległości w skali
# setek km), lewa strona — skręt w lewo i przesuw w lewo, prawa — w prawo; pod ekranem suwak ciągu głównego
# między „pełne hamowanie” i „cała naprzód”.
UKLADY['Nawigacja'] = suwaki('suwak: maksymalna prędkość kątowa skrętu w lewo', 'suwak: minimalna prędkość kątowa skrętu w lewo',
                             'suwak: maksymalna prędkość kątowa skrętu w prawo', 'suwak: minimalna prędkość kątowa skrętu w prawo') + [
    ('R', 2, 1, 'wzmocnij skręt w lewo'), ('R', 12, 1, 'wzmocnij skręt w prawo'),
    ('Y', 3, 1, 'suwak: moc silników manewrowych lewej burty'), ('Y', 11, 1, 'suwak: moc silników manewrowych prawej burty'),
    ('W', 4, 1, 'przesuw w lewo (bez zmiany zwrotu)'), ('W', 10, 1, 'przesuw w prawo (bez zmiany zwrotu)'),
    ('W', 5, 1, 'skala ekranu: 250 km'), ('W', 7, 1, 'skala ekranu: 500 km'), ('W', 9, 1, 'skala ekranu: 1000 km'),
    ('G', 6, 1, 'synchronizacja z sensorami (kontakty)'), ('G', 8, 1, 'synchronizacja z radarem (tory jednostek)'),
    ('Y', 2, 2, 'suwak: skręt w lewo (różnica ciągu lewy − prawy)', 1, 3), ('Y', 12, 2, 'suwak: skręt w prawo (różnica ciągu prawy − lewy)', 1, 3),
    ('Y', 3, 2, 'suwak: kąt dysz manewrowych lewej burty (przesuw pod skosem)', 1, 2),
    ('Y', 11, 2, 'suwak: kąt dysz manewrowych prawej burty (przesuw pod skosem)', 1, 2),
    ('B', 4, 2, 'EKRAN NAWIGACJI · sylwetka ISD zawsze w środku, w skali · okręgi odległości 250 / 500 / 1000 km · '
                'wektor ruchu osobno od zwrotu dziobu · inne jednostki (własne, obce, nieznane) · kurs zadany i punkt docelowy', 7, 7),
    ('B', 3, 4, 'odczyt: prędkość kątowa'), ('B', 11, 4, 'odczyt: przeciążenie'),
    ('W', 2, 5, 'zwrot o 90° w lewo'), ('W', 12, 5, 'zwrot o 90° w prawo'),
    ('W', 3, 5, 'trym przechylenia w lewo'), ('W', 11, 5, 'trym przechylenia w prawo'),
    ('G', 2, 6, 'synchronizacja z konsolą silników (ciąg lewej burty)'), ('G', 12, 6, 'synchronizacja z konsolą silników (ciąg prawej burty)'),
    ('B', 3, 6, 'odczyt: wektor ruchu (kurs, prędkość)'), ('B', 11, 6, 'odczyt: zwrot dziobu'),
    ('W', 2, 7, 'wybierz cel / punkt nawigacyjny'), ('W', 12, 7, 'kurs na wybrany cel'),
    ('W', 3, 7, 'kurs przechwycenia'), ('W', 11, 7, 'kurs odejścia'),
    ('G', 2, 8, 'synchronizacja z hipernapędem (punkt skoku)'), ('G', 12, 8, 'synchronizacja z grawitacją (Anchor / Lock)'),
    ('B', 3, 8, 'odczyt: odległość do celu'), ('B', 11, 8, 'odczyt: czas do celu (ETA)'),
    ('W', 2, 9, 'wyrównaj kurs (stabilizacja zwrotu)'), ('W', 12, 9, 'równaj ciąg z mocą reaktora'),
    ('R', 3, 9, 'wzmocnij hamowanie'), ('R', 11, 9, 'wzmocnij ciąg główny'),
    ('W', 4, 9, 'PEŁNE HAMOWANIE'), ('W', 10, 9, 'CAŁA NAPRZÓD'),
    ('Y', 5, 9, 'suwak: ciąg główny (prędkość zadana)', 5, 1),
]

# Radar: obraz sytuacji na polu bitwy, nie sterowanie. Ekran 5×5 na środku (sylwetka ISD w centrum, okręgi w skali
# pasm walki: 10 / 40 / 100 km) otoczony ramką przycisków jak luneta: filtry kontaktów po bokach, sektory i skale
# nad i pod ekranem; na zewnątrz listy zagrożeń każdej burty.
UKLADY['Radar'] = suwaki('suwak: maksymalny zasięg radaru', 'suwak: minimalny zasięg (tłumienie bliskich ech)',
                         'suwak: maksymalna czułość odbiornika', 'suwak: minimalna czułość (próg szumu)') + [
    ('R', 2, 1, 'wzmocnij nadajnik (zasięg)'), ('R', 12, 1, 'wzmocnij odbiornik (czułość)'),
    ('Y', 3, 1, 'suwak: moc nadajnika'), ('Y', 11, 1, 'suwak: wzmocnienie odbiornika'),
    ('G', 6, 1, 'synchronizacja z sensorami (dane skanu, Hz celów)'), ('G', 8, 1, 'synchronizacja z nawigacją (kurs i prędkość własna)'),
    ('Y', 7, 1, 'suwak: tempo omiatania'),
    ('W', 5, 2, 'sektor dziobowy lewej burty'), ('W', 6, 2, 'skala: 10 km'), ('W', 7, 2, 'skala: 40 km'),
    ('W', 8, 2, 'skala: 100 km'), ('W', 9, 2, 'sektor dziobowy prawej burty'),
    ('B', 2, 2, 'odczyt: kontakty obce'), ('B', 12, 2, 'odczyt: kontakty własne'),
    ('Y', 3, 2, 'suwak: próg alarmu zbliżenia'), ('Y', 11, 2, 'suwak: próg alarmu kursu kolizyjnego'),
    ('B', 5, 3, 'EKRAN RADARU · sylwetka ISD zawsze w środku · okręgi odległości 10 / 40 / 100 km · wiązka omiatania · '
                'kontakty IFF (własne, obce, nieznane) · klucze jako jeden symbol · tory i prognozy torów · pociski', 5, 5),
    ('W', 4, 3, 'filtr: własne'), ('W', 10, 3, 'filtr: obce'),
    ('B', 2, 3, 'LISTA ZAGROŻEŃ LEWEJ BURTY · namiar, odległość, czas do zasięgu ognia', 2, 4),
    ('B', 11, 3, 'LISTA ZAGROŻEŃ PRAWEJ BURTY · namiar, odległość, czas do zasięgu ognia', 2, 4),
    ('W', 4, 4, 'filtr: klucze myśliwców'), ('W', 10, 4, 'filtr: okręty'),
    ('W', 4, 5, 'filtr: pociski i bolty'), ('W', 10, 5, 'filtr: nieznane'),
    ('G', 4, 6, 'synchronizacja IFF z konsolą myśliwców'), ('G', 10, 6, 'synchronizacja z kontrolą ognia (przydział celów)'),
    ('W', 4, 7, 'oznacz kontakt'), ('W', 10, 7, 'śledź kontakt (tor)'),
    ('W', 2, 7, 'tryb bierny (nasłuch)'), ('W', 12, 7, 'tryb aktywny (emisja)'),
    ('G', 3, 7, 'wyślij namiar zagrożenia do osłon'), ('G', 11, 7, 'wyślij namiar zagrożenia do turbolaserów'),
    ('W', 5, 8, 'sektor rufowy lewej burty'), ('W', 6, 8, 'pokaż tory'), ('W', 7, 8, 'ALARM ZBLIŻENIA'),
    ('W', 8, 8, 'pokaż prognozy torów'), ('W', 9, 8, 'sektor rufowy prawej burty'),
    ('R', 2, 8, 'wzmocnij tłumienie zakłóceń'), ('R', 12, 8, 'wzmocnij rozdzielczość obrazu'),
    ('W', 2, 9, 'kasuj ślady'), ('W', 12, 9, 'zamroź obraz'),
    ('W', 5, 9, 'zakłócenia: tłumienie'), ('W', 7, 9, 'wyrównaj obraz (filtr szumu)'), ('W', 9, 9, 'zakłócenia: wskaż źródło'),
]

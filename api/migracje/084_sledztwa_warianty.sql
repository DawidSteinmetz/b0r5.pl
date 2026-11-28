-- Śledztwa: 12 wariantów każdej z 12 faz (sledztwo_warianty, 144 wiersze). PIERWSZA WERSJA do poprawek autora.
-- Wymaga 082. Ponowny import nadpisuje warianty treścią z tego pliku (źródłem prawdy jest ten plik).
--
-- SZKIC: 1–2 zdania dla modelu Autor. Wstawki:
--   {A}, {B} — strony relacji (role z sledztwo_relacje), {Z} — zleceniodawca / mocodawca, {S} — śledzący
--   (łowca, agent, cichy tropiciel), {W} — świadek, {M} — miejsce fabularne tej fazy (losuje krok 1).
--   Wariant ze wstawką {Z}/{S}/{W} ma odpowiedni tag w „wymaga” albo „daje” — generator tworzy wtedy tę postać.
--   Szkic nie nazywa zdarzenia (morderstwo, kradzież…) — Autor dostaje je osobno; szkic opisuje kształt fazy.
--
-- TAGI (wymaga = wszystkie muszą już być dane przez wcześniejsze fazy; daje = dopisywane po fazie):
--   postacie:  zleceniodawca (Z), sledzacy (S), swiadek (W)
--   sprawa:    lup (przedmiot / towar), dane (zapis, kostka, logi), dlug, sekret (ktoś ma coś do ukrycia),
--              konflikt (otwarta wrogość A–B lub z kimś trzecim), statek, ranny, zakladnik,
--              falszywa_tozsamosc, organizacja (syndykat, Huttowie, korporacja), wladze (straż, urząd),
--              nagroda (wyznaczona za kogoś), termin (ktoś postawił ultimatum), ochrona (wynajęci ludzie),
--              kryjowka (jest miejsce, w którym ktoś się ukrywa / coś przechowuje)
--
-- SPÓJNOŚĆ (sprawdzona skryptem narzedzia/sledztwa/sprawdz_warianty.pl):
--   - każda faza ma co najmniej 2 warianty bez wymagań, więc losowanie nigdy nie utyka;
--   - każdy tag z „wymaga” jest dawany przez jakiś wariant wcześniejszej (albo przestawnej) fazy;
--   - jawnosc 'jawna' / 'ukryta' = wariant dopuszczalny tylko przy takiej jawności fazy.

USE hololink;
SET NAMES utf8mb4;

INSERT INTO sledztwo_warianty (faza_nr, nr, nazwa, szkic, wymaga, daje, jawnosc) VALUES

-- 1. ZAWIĄZANIE — co splątało {A} i {B}; daje tagi bazowe całej sprawy.
(1,1,'Wspólny plan','{A} i {B} od tygodni planują razem przedsięwzięcie, które ma odmienić ich życie; tylko jedno z nich mówi drugiemu całą prawdę o celu.','','sekret','dowolna'),
(1,2,'Stary dług','{A} jest winien {B} sumę, której nie da się spłacić z bieżących zarobków; spłata przeciąga się od lat i obie strony mają tego dość.','','dlug','dowolna'),
(1,3,'Przypadkowe odkrycie','{A} trafia w {M} na zapis należący do {B}, którego nie powinien był zobaczyć, i robi sobie kopię.','','sekret,dane','dowolna'),
(1,4,'Propozycja z zewnątrz','W życiu {A} i {B} pojawia się {Z} z propozycją, której żadne z nich nie potrafi odrzucić, choć każde z innego powodu.','','zleceniodawca','dowolna'),
(1,5,'Przedmiot z przeszłości','Do rąk {A} trafia przedmiot związany z dawną, przemilczaną sprawą {B}; {B} dowiaduje się o tym od osób trzecich.','','lup,sekret','dowolna'),
(1,6,'Złamana obietnica','{B} nie dotrzymuje słowa danego {A} w ważnej sprawie; kłótnię w {M} słyszy pół lokalu.','','konflikt','jawna'),
(1,7,'Wspólny udział w statku','{A} i {B} mają udziały w tym samym frachtowcu i w ładunku, który właśnie przyleciał na Nar Shaddaa.','','statek,lup','dowolna'),
(1,8,'Podwójne życie','{A} prowadzi drugie życie pod innym nazwiskiem; {B} zna tylko jedną z tych twarzy i zaczyna coś podejrzewać.','','falszywa_tozsamosc,sekret','ukryta'),
(1,9,'Prośba o pomoc','{B} prosi {A} o pomoc w kłopocie, którego nie chce nazwać; {A} zgadza się, zanim pozna szczegóły.','','sekret','dowolna'),
(1,10,'Wspólny mocodawca','{A} i {B} pracują dla tego samego możnego {Z}, który oczekuje od nich wyników, a nie wyjaśnień.','','zleceniodawca,organizacja','dowolna'),
(1,11,'Wypadek','{A} i {B} uczestniczą w wypadku w {M}; jedno wychodzi z niego ranne, drugie z czymś, co musi ukryć.','','ranny,sekret','dowolna'),
(1,12,'Spadek','Umiera ktoś trzeci i zostawia {A} i {B} wspólny majątek razem z długiem, o którym żadne z nich nie wiedziało.','','dlug,lup','dowolna'),

-- 2. POWÓD ŚLEDZTWA — kto i dlaczego wzywa detektywa. Przy sprawie ukrytej faza 2 jest ukryta (zgłoszenie = zasłona).
(2,1,'Zgłoszenie bliskiej osoby','{B} zgłasza, że z {A} dzieje się coś złego, i prosi o dyskrecję; podaje tylko to, co sam chce ujawnić.','','','dowolna'),
(2,2,'Zlecenie mocodawcy','{Z} płaci za wyjaśnienie sprawy, bo stracił na niej pieniądze albo twarz, i żąda raportów co dzień.','zleceniodawca','','dowolna'),
(2,3,'Sprawa przekazana przez straż','Straż portowa przekazuje sprawę detektywowi, bo nikt w urzędzie nie chce jej ruszyć; akta są niekompletne.','','wladze','jawna'),
(2,4,'Wiadomość bez nadawcy','Do datapadu detektywa trafia anonimowa wiadomość z nazwą {M} i godziną; nadawca zatarł ślady przesyłu.','','dane','ukryta'),
(2,5,'Roszczenie ubezpieczeniowe','{Z}, agent firmy ubezpieczeniowej, sprawdza roszczenie o utracony przedmiot i podejrzewa wyłudzenie.','lup','zleceniodawca','dowolna'),
(2,6,'Troskliwy wierzyciel','{Z} szuka dłużnika i przedstawia to jako troskę o jego zdrowie; o długu wspomina dopiero przypadkiem.','dlug','zleceniodawca','dowolna'),
(2,7,'Świadek bez snu','{W} przychodzi sam, bo od kilku nocy nie może spać po tym, co widział w {M}; boi się, że ktoś go rozpoznał.','','swiadek','dowolna'),
(2,8,'Zgłoszenie od sprawcy','{A} sam zgłasza sprawę i przedstawia się jako poszkodowany; jego wersja jest spójna aż za bardzo.','','sekret','ukryta'),
(2,9,'Właściciel lokalu chce ciszy','Właściciel {M} chce szybkiego i cichego wyjaśnienia, zanim sprawa odstraszy klientelę; płaci z góry.','','','dowolna'),
(2,10,'Rodzina z daleka','Krewny spoza Nar Shaddaa od tygodnia nie może skontaktować się z {A}; zna go z czasów, gdy był kimś innym.','','','dowolna'),
(2,11,'Łowca płaci za informacje','{S} oferuje detektywowi zapłatę za każde znalezisko w sprawie i nie mówi, kto wyznaczył nagrodę.','','sledzacy,nagroda','jawna'),
(2,12,'Podrzucony przedmiot','Pod drzwi biura detektywa ktoś podrzuca przedmiot należący do {A} z kartką: „sprawdź, skąd to mam”.','','lup','dowolna'),

-- 3. PRZEDSIĘWZIĘCIE I JEGO WYNIK — co dokładnie zrobiono i jak się skończyło (sedno ukrytej prawdy).
(3,1,'Udane, ale ktoś widział','Przedsięwzięcie w {M} się udaje, lecz {W} widzi więcej, niż powinien, i zapamiętuje twarz {A}.','','swiadek,lup','dowolna'),
(3,2,'Nieudane, ucieczka','Coś idzie źle w ostatniej chwili; {A} ucieka z {M}, zostawiając po sobie bałagan i jeden wyraźny ślad.','','sekret','dowolna'),
(3,3,'Połowiczny sukces','Połowa łupu trafia do rąk {A}, druga połowa przepada albo zostaje u {B}; każde uważa, że to drugie oszukało.','','lup,konflikt','dowolna'),
(3,4,'Zdrada w trakcie','W decydującym momencie {B} zmienia stronę; {A} zostaje sam i musi improwizować.','','konflikt','dowolna'),
(3,5,'Zapis zamiast przedmiotu','Przedsięwzięcie dotyczyło danych; kostka z zapisem znika z {M} razem z jedyną kopią zapasową.','','dane','dowolna'),
(3,6,'Ofiara','Ktoś zostaje ciężko ranny; reszta uczestników rozpierzcha się, a każde opowiada potem inną wersję.','','ranny,konflikt','dowolna'),
(3,7,'Zakładnik','Na czas rozliczenia ktoś zostaje zatrzymany jako gwarancja; wypuszczą go dopiero, gdy rachunek się zgodzi.','','zakladnik','dowolna'),
(3,8,'Przejęcie przez trzecich','Wynik przejmuje organizacja, która od początku obserwowała {A} i {B}; ci zostają z niczym, za to z długiem.','','organizacja,dlug','dowolna'),
(3,9,'Statek odlatuje bez nich','Frachtowiec z ładunkiem startuje z {M} bez {A} i {B}; ktoś zmienił plan lotu w ostatniej chwili.','statek','konflikt','dowolna'),
(3,10,'Spalona tożsamość','Przedsięwzięcie wychodzi na jaw tylko w połowie: spalone jest fałszywe nazwisko {A}, prawdziwe jeszcze nie.','falszywa_tozsamosc','wladze','dowolna'),
(3,11,'Zatarte ślady','Przedsięwzięcie udaje się niemal idealnie; jedynym śladem jest drobny błąd w logu drzwi {M}.','','dane','ukryta'),
(3,12,'Przerwane w ostatniej chwili','Ktoś przerywa przedsięwzięcie tuż przed końcem i znika; {A} i {B} podejrzewają siebie nawzajem, a był to {S}.','','sledzacy,konflikt','dowolna'),

-- 4. PRESJA Z ZEWNĄTRZ (przestawna)
(4,1,'Wierzyciel stawia termin','Wierzyciel wyznacza {A} ostateczny termin spłaty i daje do zrozumienia, co stanie się potem.','dlug','termin','dowolna'),
(4,2,'Mocodawca żąda zwrotu','{Z} żąda zwrotu tego, co uważa za swoje, i przysyła ludzi do {M}, by przypomnieli o tym osobiście.','zleceniodawca','termin','dowolna'),
(4,3,'Organizacja wysyła ludzi','Organizacja wysyła {S}, by odzyskał, co jej się należy, i dopilnował, żeby nikt nie gadał.','organizacja','sledzacy','dowolna'),
(4,4,'Szantażysta','Ktoś zna sekret {A} i żąda zapłaty za milczenie; pierwsza rata już przepadła.','sekret','dlug','dowolna'),
(4,5,'Straż zaczyna węszyć','Do {M} przychodzi patrol straży i zadaje pytania; ktoś musiał złożyć donos.','','wladze','jawna'),
(4,6,'Groźba wobec bliskich','Ktoś bliski {A} dostaje ostrzeżenie bez podpisu; {A} wie, od kogo, ale nikomu nie mówi.','','termin','ukryta'),
(4,7,'Nagroda za głowę','Na tablicach ogłoszeń pojawia się nagroda za wskazanie {A}; łowcy zjeżdżają się na poziom.','','nagroda,sledzacy','jawna'),
(4,8,'Zakładnik jako dźwignia','Strona, która trzyma zakładnika, podnosi stawkę i wyznacza czas wymiany.','zakladnik','termin','dowolna'),
(4,9,'Konkurencja chce łupu','Inna grupa dowiaduje się o łupie i wysyła {S}, by go przejął, zanim zrobi to ktokolwiek inny.','lup','sledzacy','dowolna'),
(4,10,'Natarczywy kupiec','Ktoś chce kupić zapis od {A} i nie przyjmuje odmowy; każda kolejna oferta jest niższa i groźniejsza.','dane','termin','dowolna'),
(4,11,'Presja sumienia','Z zewnątrz nikt nie naciska; {A} sypie się sam: pije, mówi za dużo, unika {B}.','','','dowolna'),
(4,12,'Plotka w półświatku','W kantynach mówi się, że {A} ma coś cennego; obcy zaczynają się do niego przysiadać.','','','jawna'),

-- 5. ZASOBY (przestawna)
(5,1,'Lombard','{A} zastawia w lombardzie rzeczy osobiste, w tym jedną, z którą nigdy by się nie rozstał; kwit trzyma przy sobie.','','','dowolna'),
(5,2,'Pożyczka u lichwiarza','{A} bierze pożyczkę na lichwiarski procent w {M}, podając prawdziwe imię, bo nie ma wyjścia.','','dlug','dowolna'),
(5,3,'Sprzedaż łupu','{A} próbuje sprzedać łup paserowi w {M}; paser zaniża cenę, bo wie, że towar jest gorący.','lup','','dowolna'),
(5,4,'Zapis sprzedawany kawałkami','{A} sprzedaje fragmenty zapisu różnym nabywcom, żeby żaden nie miał całości.','dane','','ukryta'),
(5,5,'Nowe papiery','{A} kupuje nowy identyfikator u fałszerza; płaci więcej, niż ma, i zostaje winien resztę.','','falszywa_tozsamosc,dlug','ukryta'),
(5,6,'Miejsce na statku','{A} kupuje miejsce na frachtowcu odlatującym z {M}; termin startu zależy od kapitana, nie od niego.','','statek','dowolna'),
(5,7,'Hazard','{A} próbuje wygrać brakującą sumę w kasynie; przegrywa więcej, niż miał.','','dlug','dowolna'),
(5,8,'Dawny znajomy','{A} prosi o pomoc dawnego znajomego; {W} daje pieniądze i nocleg, ale zapamiętuje wszystko.','','swiadek','dowolna'),
(5,9,'Drobne kradzieże','{A} żyje z drobnych kradzieży w okolicy {M}; okradani nie łączą ich ze sprawą.','','','ukryta'),
(5,10,'Wynajęta ochrona','{A} wynajmuje dwóch osiłków do ochrony; płaci im z góry za tydzień, a ich lojalność kończy się razem z zapłatą.','','ochrona','dowolna'),
(5,11,'Sprzedaż udziału w statku','{A} sprzedaje po cenie udział w statku; nowy właściciel zadaje dużo pytań.','statek','','dowolna'),
(5,12,'Przygotowana skrytka','{A} ma od dawna przygotowaną skrytkę z zapasami i kredytami; wie o niej najwyżej jedna osoba.','','kryjowka','dowolna'),

-- 6. POŚCIG (przestawna). Jawny = list gończy; ukryty = ciche śledzenie po tropach.
(6,1,'List gończy','Wizerunek {A} trafia do holonetu z nagrodą; każdy barman na poziomie zna jego twarz.','','nagroda,sledzacy','jawna'),
(6,2,'Łowca nagród','{S} przyjmuje zlecenie na {A} i przeczesuje kantyny, płacąc za każdą informację.','nagroda','sledzacy','dowolna'),
(6,3,'Ciche śledzenie','{S} idzie po tropach {A} krok za krokiem, nie pytając nikogo wprost; zostawia za sobą tylko wzmianki „ktoś już o to pytał”.','','sledzacy','ukryta'),
(6,4,'Obława straży','Straż blokuje wyjścia z sektora i sprawdza identyfikatory w {M}.','wladze','','jawna'),
(6,5,'Ludzie organizacji','Organizacja rozsyła po poziomach swoich ludzi; pytają uprzejmie, ale wszyscy wiedzą, czyi są.','organizacja','sledzacy','dowolna'),
(6,6,'Kontrola wylotów','Ktoś pilnuje lądowisk i hangarów; każdy statek z {M} jest sprawdzany przed startem.','statek','','dowolna'),
(6,7,'Śledzący śledzi droida','{S} orientuje się, że droid prowadzi tę samą sprawę, i zaczyna iść jego śladem.','sledzacy','','dowolna'),
(6,8,'Pościg odwrócony','To {A} ściga {B}, nie odwrotnie; otoczenie widzi tylko, że oboje zniknęli z {M}.','','konflikt','dowolna'),
(6,9,'Nadajnik w przedmiocie','W łupie ukryto nadajnik lokalizacyjny; ktoś zna każde miejsce, przez które przeszedł.','lup','sledzacy','ukryta'),
(6,10,'Ślad elektroniczny','Pościg idzie po logach drzwi, płatnościach i kamerach; {A} zostawia cyfrowy ślad, nie wiedząc o tym.','','dane','ukryta'),
(6,11,'Ranny zostawia ślady','Ranny musi szukać medyka; ślady prowadzą przez punkty medyczne i apteki w okolicy {M}.','ranny','','dowolna'),
(6,12,'Pościg za fałszywym nazwiskiem','Ścigający szukają {A} pod fałszywym nazwiskiem i trafiają na kogoś zupełnie innego.','falszywa_tozsamosc','','dowolna'),

-- 7. KRYJÓWKA I MOTYW (przestawna)
(7,1,'Nora na niskim poziomie','{A} ukrywa się w norze slicera na niskim poziomie {M}; płaci za ciszę, nie za wygody. Motyw: przeczekać.','','kryjowka','dowolna'),
(7,2,'Hotel pod obcym nazwiskiem','{A} wynajmuje pokój hotelowy w {M} na fałszywe nazwisko i prawie nie wychodzi. Motyw: zmylić pogoń.','falszywa_tozsamosc','kryjowka','dowolna'),
(7,3,'Statek w hangarze','{A} ukrywa się na pokładzie statku w hangarze {M}, czekając na okazję do startu. Motyw: uciec z planety.','statek','kryjowka','dowolna'),
(7,4,'U drugiej strony','{A} ukrywa się u {B}, choć oficjalnie są skłóceni. Motyw: wspólna tajemnica jest silniejsza niż kłótnia.','konflikt','kryjowka','ukryta'),
(7,5,'Pod opieką organizacji','{A} znajduje schronienie u organizacji w zamian za usługę, której jeszcze nie zna. Motyw: przeżyć za wszelką cenę.','organizacja','kryjowka,dlug','dowolna'),
(7,6,'Na widoku','{A} ukrywa się na widoku: pracuje w {M}, gdzie nikt nie przygląda się obsłudze. Motyw: najciemniej pod latarnią.','','kryjowka','dowolna'),
(7,7,'Ochrona kogoś bliskiego','{A} ukrywa się, by ściągnąć uwagę z kogoś bliskiego. Motyw: miłość albo poczucie winy.','','kryjowka,sekret','dowolna'),
(7,8,'Ucieczka przed długiem','{A} ukrywa się przed wierzycielami; reszta sprawy jest dla niego mniej ważna. Motyw: dług.','dlug','kryjowka','dowolna'),
(7,9,'Strach przed śledzącym','{A} boi się {S} bardziej niż prawa i zrobi wszystko, by nie wpaść mu w ręce. Motyw: strach.','sledzacy','kryjowka','dowolna'),
(7,10,'Zemsta','{A} nie ucieka, tylko przygotowuje rachunek za dawną krzywdę wyrządzoną przez {B}. Motyw: zemsta.','','kryjowka,konflikt','dowolna'),
(7,11,'Stara skrytka','{A} korzysta z przygotowanej wcześniej skrytki; wie o niej najwyżej jedna osoba. Motyw: plan awaryjny przygotowany latami.','kryjowka','','dowolna'),
(7,12,'Ciągły ruch','{A} nie ma jednej kryjówki: co noc śpi gdzie indziej. Motyw: sprzedać to, co ma, i zniknąć z Nar Shaddaa.','','','dowolna'),

-- 8. FAŁSZYWY TROP (przestawna)
(8,1,'Oczywisty wróg','Wszystko wskazuje na osobę skłóconą z {A}; ta osoba nie ma alibi, ale nie ma też nic wspólnego ze sprawą.','konflikt','','dowolna'),
(8,2,'Podrzucony przedmiot','Część łupu zostaje podrzucona w {M} komuś niewinnemu.','lup','','ukryta'),
(8,3,'Sfałszowany log','Logi drzwi {M} wskazują osobę, która tej nocy była gdzie indziej; ktoś zmienił znaczniki czasu.','','dane','ukryta'),
(8,4,'Plotka','W kantynach krąży wersja sprawy, w którą wszyscy wierzą, bo jest ciekawsza od prawdy.','','','jawna'),
(8,5,'Fałszywy świadek','Ktoś za pieniądze zeznaje, że widział w {M} osobę, której tam nie było; kłamie spójnie, ale z jednym błędem.','','','dowolna'),
(8,6,'Wersja urzędowa','Straż ogłasza wyjaśnienie sprawy i zamyka akta; oficjalna wersja jest wygodna dla kogoś wpływowego.','wladze','','jawna'),
(8,7,'Niewinny sobowtór','Fałszywe nazwisko {A} należało kiedyś do prawdziwej osoby, która teraz ponosi konsekwencje.','falszywa_tozsamosc','','dowolna'),
(8,8,'Zbieg okoliczności','Niewinna osoba zachowuje się podejrzanie z własnych, wstydliwych powodów i kłamie, by je ukryć.','','','dowolna'),
(8,9,'Trop od śledzącego','{S} celowo podsuwa droidowi fałszywy trop, by samemu dotrzeć do celu pierwszy.','sledzacy','','ukryta'),
(8,10,'Inscenizacja','Ktoś inscenizuje w {M} scenę, która ma wyglądać na koniec sprawy (wyjazd, śmierć, ugodę).','','','ukryta'),
(8,11,'Mocodawca kłamie','{Z} od początku kieruje śledztwo na złą osobę, bo prawda obciąża jego samego.','zleceniodawca','','ukryta'),
(8,12,'Stara sprawa','Sprawa do złudzenia przypomina inną sprzed lat; jej sprawca jest dziś łatwym podejrzanym.','','','dowolna'),

-- 9. ŚWIADEK (przestawna)
(9,1,'Barman','Barman z {M} widział wszystko i powie to za odpowiednią cenę albo za przysługę.','','swiadek','jawna'),
(9,2,'Droid serwisowy','Droid sprzątający {M} ma zapis z tamtej nocy w pamięci podręcznej i nie wie, że to ważne.','','swiadek,dane','ukryta'),
(9,3,'Ktoś niewidzialny','{W}, ktoś, kogo nikt nie traktuje poważnie (dziecko, żebrak, kurier), widział rzecz kluczową.','','swiadek','dowolna'),
(9,4,'Świadek w ukryciu','{W} ukrywa się, bo boi się {S}; da się go znaleźć tylko po jego nawykach.','sledzacy','swiadek','ukryta'),
(9,5,'Świadek wraca','{W} wraca i mówi więcej niż za pierwszym razem; tym razem coś przemilcza z innego powodu.','swiadek','','dowolna'),
(9,6,'Mechanik z hangaru','Mechanik pamięta, kto i kiedy przestawiał statek oraz co było w ładowni.','statek','swiadek','dowolna'),
(9,7,'Recepcja','Recepcjonista (albo droid recepcyjny) pamięta gościa, który płacił gotówką i nie wychodził z pokoju.','kryjowka','swiadek','dowolna'),
(9,8,'Lichwiarz','Lichwiarz zna stan długów wszystkich stron i chętnie powie, kto komu ile jest winien.','dlug','swiadek','dowolna'),
(9,9,'Medyk','Medyk opatrywał rannego i zapamiętał więcej, niż wpisał do dziennika.','ranny','swiadek','dowolna'),
(9,10,'Zazdrosny konkurent','{W}, konkurent {A} lub {B}, chętnie mówi, bo szkodzi tym rywalowi; połowa jest prawdą.','','swiadek','dowolna'),
(9,11,'Holorejestrator','Świadkiem jest kamera w {M}; zapis istnieje, ale trzeba go zdobyć, zanim zostanie nadpisany.','','dane','dowolna'),
(9,12,'Świadka już nie ma','Świadek zniknął albo nie żyje; zostały jego notatki i ktoś, kto je przechowuje.','','dane','ukryta'),

-- 10. PRZESZKODA (przestawna)
(10,1,'Zamknięty sektor','Sektor z {M} zostaje zamknięty oficjalnym zarządzeniem; wejść można tylko z przepustką albo naokoło.','','','jawna'),
(10,2,'Wynajęci ludzie','Ochrona wynajęta przez {A} pilnuje drzwi {M} i ma rozkaz nikogo nie wpuszczać.','ochrona','','dowolna'),
(10,3,'Zatarte logi','Ktoś skasował logi z kluczowego okresu; zostały tylko kopie w miejscach, o których mało kto pamięta.','','dane','ukryta'),
(10,4,'Przekupiony urzędnik','Urzędnik przekupiony przez stronę sprawy gubi dokumenty i odsyła droida od okienka do okienka.','','wladze','ukryta'),
(10,5,'Zakaz wstępu dla droidów','{M} nie wpuszcza droidów; trzeba znaleźć wejście służbowe albo kogoś, kto wejdzie za droida.','','','jawna'),
(10,6,'Teren organizacji','{M} jest terenem organizacji; jej ludzie pilnują wejść i pamiętają każdą twarz i każdego droida.','organizacja','','dowolna'),
(10,7,'Szyfr','Kluczowy zapis jest zaszyfrowany; klucz ma jedna osoba albo jest ukryty w jej rzeczach.','dane','','dowolna'),
(10,8,'Odliczanie do startu','Statek, na którym jest coś ważnego, startuje za kilka godzin; potem sprawa wyleci z planety.','statek','termin','dowolna'),
(10,9,'Nacisk na detektywa','Ktoś daje do zrozumienia, że dalsze grzebanie w sprawie będzie kosztowne dla detektywa i jego droida.','','','dowolna'),
(10,10,'Świadek się wycofuje','Świadek odwołuje to, co powiedział; ktoś do niego dotarł.','swiadek','','dowolna'),
(10,11,'Blokada poziomu','Windy na poziom {M} są wyłączone z powodu awarii; zostaje długi objazd korytarzami technicznymi.','','','dowolna'),
(10,12,'Śledzący jest pierwszy','{S} dociera do {M} pierwszy i zabiera to, czego szukał droid; zostawia tylko ślady swojej obecności.','sledzacy','','dowolna'),

-- 11. WYŚCIG — kto pierwszy dotrze do prawdy / celu.
(11,1,'Wyścig ze śledzącym','{S} jest o krok od celu; droid musi dotrzeć do {A} albo do dowodu przed nim.','sledzacy','','dowolna'),
(11,2,'Wyścig z odlotem','Statek z {A} lub z łupem startuje z {M}; po starcie sprawa jest stracona.','statek','','dowolna'),
(11,3,'Wyścig z ultimatum','Mija termin postawiony w sprawie; po nim ktoś zrobi coś nieodwracalnego.','termin','','dowolna'),
(11,4,'Wyścig ze strażą','Straż zamierza zamknąć sprawę po swojemu; droid ma czas tylko do jej wkroczenia do {M}.','wladze','','dowolna'),
(11,5,'Wyścig z organizacją','Organizacja chce załatwić sprawę po cichu i na zawsze; droid musi być pierwszy.','organizacja','','dowolna'),
(11,6,'Wyścig o zakładnika','Wymiana zakładnika ma się odbyć w {M}; ktoś zamierza ją zerwać.','zakladnik','','dowolna'),
(11,7,'Wyścig o łup','Łup ma zmienić właściciela w {M}; po transakcji ślad urywa się na zawsze.','lup','','dowolna'),
(11,8,'Wyścig o zapis','Ktoś zamierza zniszczyć ostatnią kopię zapisu; droid musi ją zabezpieczyć pierwszy.','dane','','dowolna'),
(11,9,'Wyścig o świadka','Ktoś chce uciszyć świadka; droid musi dotrzeć do {W} pierwszy.','swiadek','','dowolna'),
(11,10,'Wyścig z czasem rannego','Stan rannego się pogarsza; bez pomocy nie przeżyje nocy, a tylko on zna ostatni brakujący fakt.','ranny','','dowolna'),
(11,11,'Wyścig ze zniknięciem','{A} przygotowuje się do zniknięcia z Nar Shaddaa na zawsze; zostało mu do załatwienia jedno.','','','dowolna'),
(11,12,'Wyścig z plotką','Sprawa zaraz wyjdzie na jaw w najgorszej możliwej wersji; droid ma czas do wieczornego wydania holonetu.','','','dowolna'),

-- 12. FINAŁ — wybór gracza. Jawny = skutki znane wszystkim; ukryty = prawdę zna tylko gracz.
(12,1,'Wydać czy puścić','Droid odnajduje {A}; gracz decyduje, czy wydać go władzom, czy pozwolić odejść.','','','dowolna'),
(12,2,'Komu oddać łup','Łup jest w zasięgu ręki; gracz decyduje, czy oddać go prawowitemu właścicielowi, mocodawcy, czy zatrzymać.','lup','','dowolna'),
(12,3,'Prawda czy wersja zgłoszona','Gracz zna prawdę, która różni się od zgłoszenia; decyduje, którą wersję przekaże zgłaszającemu.','','','ukryta'),
(12,4,'Sprzedać czy zniszczyć zapis','Zapis jest u gracza; może go sprzedać, przekazać władzom albo zniszczyć.','dane','','dowolna'),
(12,5,'Pojednać czy rozdzielić','{A} i {B} stają twarzą w twarz w {M}; od gracza zależy, czy wyjdą z tego razem.','','','dowolna'),
(12,6,'Wymiana zakładnika','Gracz prowadzi wymianę zakładnika i decyduje, czy dotrzymać warunków.','zakladnik','','dowolna'),
(12,7,'Nagroda','{S} proponuje podział nagrody w zamian za wskazanie {A}; gracz decyduje.','nagroda','sledzacy','dowolna'),
(12,8,'Zdemaskować mocodawcę','Prawda obciąża {Z}; gracz decyduje, czy go zdemaskować, czy wziąć zapłatę i milczeć.','zleceniodawca','','dowolna'),
(12,9,'Spłacić czy ujawnić dług','Gracz może pomóc spłacić dług, ujawnić go albo wykorzystać jako dźwignię.','dlug','','dowolna'),
(12,10,'Chronić świadka','Świadek zapłacił za pomoc wysoką cenę; gracz decyduje, czy go chronić kosztem sprawy.','swiadek','','dowolna'),
(12,11,'Ujawnić sekret','Gracz zna sekret, który zniszczy czyjeś życie; decyduje, komu go powierzyć i czy w ogóle.','sekret','','ukryta'),
(12,12,'Pozwolić odlecieć','Statek z {A} jest gotowy do startu; gracz decyduje, czy go zatrzymać.','statek','','dowolna')

ON DUPLICATE KEY UPDATE nazwa = VALUES(nazwa), szkic = VALUES(szkic), wymaga = VALUES(wymaga),
  daje = VALUES(daje), jawnosc = VALUES(jawnosc);

INSERT INTO schema_migracje (wersja, opis)
VALUES ('v084-sledztwa-warianty', 'Śledztwa: 12 wariantów każdej z 12 faz (pierwsza wersja), tagi spójności wymaga / daje')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);

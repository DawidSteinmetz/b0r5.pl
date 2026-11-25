"""Scenariusze bitew jako dane (tabele bitwy_scenariusze*). Pole bitwy 2D w km, ISD w (0, 0); kurs 0° = oś +y,
90° = +x. Czasy w sekundach od startu bitwy; rozrzuty losowane z ziarna bitwy (każda powtórka inna).

Strona z dowodzeniem 'gracze' to obsada graczy; pozostałe prowadzi AI (MECHANIKA rozdz. 11).
Odprawa jest widoczna dla graczy; opis_mg i wyzwalacze — tylko dla MG.
"""

SCENARIUSZE = [
    {
        'kod': 'walako_atak',
        'nazwa': 'Atak na Walako',
        'odprawa': 'Walako (ISD I) pełni wachtę w układzie. Wywiad floty ostrzega przed ruchem sił rebelii w sektorze. '
                   'Zadanie: utrzymać pozycję i okręt do przybycia posiłków, spodziewanych w ciągu godziny.',
        'opis_mg': 'Wzorcowy scenariusz z profilu ISD (rozdz. „Scenariusz wzorcowy”). Rebelia wychodzi ze skoku 100 km od '
                   'Walako: MC80 i 9 kluczy. Klucze rebelii są na 30 km po ok. 70 s, krążownik w paśmie 40 km po ok. 120 s, '
                   'na 10 km po ok. 300 s. Fale ISD (4 + 6 + 8 kluczy w 4 min) są szybsze niż taśmociąg 1 klucz/min — '
                   'zakładają alarm czerwony z oboma pokładami startowymi; do rozstrzygnięcia w testach. '
                   'Profil MC80 jest propozycją (M1–M10).',
        'okno_s': 3600, 'pole_km': 200,
        'zwyciestwo': 'Imperium: Walako utrzymany do przybycia posiłków (kadłub co najmniej 25%) albo krążownik rebelii '
                      'unieszkodliwiony lub wycofany.',
        'porazka': 'Imperium: kadłub Walako poniżej 25% przed przybyciem posiłków.',
        'status': 'roboczy',
        'strony': [
            ('Imperium', '#9fb4c8', 'gracze', 'obrona', 5, 35),
            ('Rebelia', '#ff6b55', 'calrissian', 'atak', 10, 30),
        ],
        # nr, strona, profil, nazwa, z_mapy, x, y, kurs, prędkość, kadłub, wejście, rozrzut wejścia
        'jednostki': [
            (1, 'Imperium', 'ISD_I', 'Walako', 1, 0, 0, 0, 0, 100, 0, 0),
            (2, 'Rebelia', 'MC80', 'krążownik rebelii', 0, 0, 100, 180, 0.5, 100, 0, 0),
        ],
        # fala, strona, jednostka_nr, typ, liczba kluczy, start, rozrzut, x, y, rozkaz
        'klucze': [
            (1, 'Imperium', 1, 'TIE_LN', 4, 0, 30, None, None, 'ochrona'),
            (2, 'Imperium', 1, 'TIE_LN', 6, 60, 60, None, None, 'atak'),
            (3, 'Imperium', 1, 'TIE_LN', 8, 150, 90, None, None, 'atak'),
            (1, 'Rebelia', 2, 'X_WING', 5, 0, 10, None, None, 'atak'),
            (1, 'Rebelia', 2, 'Y_WING', 2, 0, 10, None, None, 'atak'),
            (1, 'Rebelia', 2, 'A_WING', 1, 0, 10, None, None, 'atak'),
            (1, 'Rebelia', 2, 'B_WING', 1, 0, 10, None, None, 'atak'),
        ],
        # nr, czas, rozrzut, rodzaj, treść
        'wyzwalacze': [
            (1, 0, 0, 'komunikat', 'Wyjście z nadprzestrzeni 100 km od Walako. Alarm bojowy.'),
            (2, 3600, 0, 'posilki', 'Posiłki Imperium wychodzą z nadprzestrzeni.'),
            (3, 3600, 0, 'koniec', 'Koniec symulacji: przybycie posiłków.'),
        ],
    },
    {
        'kod': 'walako_klucze',
        'nazwa': 'Szkolenie: klucze rebelii nad Walako',
        'odprawa': 'Ćwiczenie obrony przed myśliwcami. Z nadprzestrzeni wychodzi grupa myśliwców rebelii bez okrętu '
                   'macierzystego. Zadanie: zniszczyć napastników, zanim przebiją osłony.',
        'opis_mg': 'Scenariusz bez MC80 — działa przed decyzjami M1–M10. Sprawdza filtr osłon na mieszance maszyn: '
                   'X-wingi nie przebijają 1800 Hz, Y-wingi przebijają o 10%, B-wing o 40% (wymusza 3600 Hz).',
        'okno_s': 900, 'pole_km': 120,
        'zwyciestwo': 'Imperium: zestrzelone co najmniej 27 z 36 maszyn rebelii w 15 minut.',
        'porazka': 'Imperium: kadłub Walako poniżej 90%.',
        'status': 'roboczy',
        'strony': [
            ('Imperium', '#9fb4c8', 'gracze', 'obrona', 5, 35),
            ('Rebelia', '#ff6b55', 'calrissian', 'atak', 10, 30),
        ],
        'jednostki': [
            (1, 'Imperium', 'ISD_I', 'Walako', 1, 0, 0, 0, 0, 100, 0, 0),
        ],
        'klucze': [
            (1, 'Imperium', 1, 'TIE_LN', 4, 0, 20, None, None, 'ochrona'),
            (2, 'Imperium', 1, 'TIE_IN', 5, 60, 60, None, None, 'atak'),
            (1, 'Rebelia', None, 'X_WING', 6, 0, 15, 0, 60, 'atak'),
            (1, 'Rebelia', None, 'Y_WING', 2, 0, 15, 10, 60, 'atak'),
            (1, 'Rebelia', None, 'B_WING', 1, 30, 30, -10, 60, 'atak'),
        ],
        'wyzwalacze': [
            (1, 0, 0, 'komunikat', 'Kontakty: 9 kluczy myśliwców, 60 km, kurs na Walako.'),
            (2, 900, 0, 'koniec', 'Koniec ćwiczenia.'),
        ],
    },
]

"""Profile klas okrętów — jedyne miejsce z liczbami zależnymi od klasy. Wzory są wspólne (`mechanika.py`,
`MECHANIKA.md`); opis słowny profili: `profile/*.md`.

Status liczb jak w dokumentacji: [U] ustalone, [R] robocze, [?] propozycja czekająca na decyzję użytkownika.
Docelowo te dane trafią do SQL (tabele profili klas); ten plik jest ich roboczym odpowiednikiem dla generatorów.
"""
from math import log2

# Stałe wspólne dla wszystkich klas.
WSPOLNE = {
    'tik_s': 1,                     # [U]
    'cykl_tiki': 12,                # [U]
    'porcje': 100,                  # [U] 1 porcja = 1% mocy rdzenia
    'skala_zyl': 1e17,              # [U] wartości „laboratoryjne” żył × 10¹⁷
    'hz_odniesienia': 450,          # [R] punkt odniesienia poboru i zużycia (Hz/450)
    'wykladnik_zuzycia': log2(3),   # [R] 1,585 — ×3 zużycia na podwojenie Hz
    'tau_pojemnosci_s': 0.2508,     # [R] C = τ · N · P_żyły (ISD: 1,3×10²³ J); wyprowadzone z ISD
    'energia_zyly_lufy_J': 8.4e20 / 3600,  # [R] energia bolta na aktywną żyłę baterii (ISD: 8,4×10²⁰ J przy 3600)
    'kanaly_VOW': {                 # [U] dominujący kanał, [R] proporcje
        'turbolaser': (0.60, 0.25, 0.15),
        'wiązka ciągła': (0.20, 0.60, 0.20),
        'ostrzał seryjny': (0.15, 0.15, 0.70),
        'działo jonowe': (1.00, 0.00, 0.00),
    },
    'maszyn_w_kluczu': 4,           # [U]
}

PROFILE = {
    'ISD_I': {
        'nazwa': 'Gwiezdny Niszczyciel typu Imperial I',
        'dlugosc_m': 1600,
        'reaktor': {
            'moc_W': 7.73e24,       # [U]
            'jednostki': [          # [U] z pulpitu Reaktory
                ('reaktor główny (jonizacji słonecznej I-a2b)', 'rdzeń'),
                ('główny generator mocy', 'dystrybucja'),
                ('reaktor pomocniczy uzbrojenia', 'pomocniczy'),
                ('przedni reaktor pomocniczy', 'pomocniczy'),
                ('zapasowy reaktor silników', 'zapasowy'),
            ],
            'utrata_rdzenia': 'utrata całej mocy rdzenia (zostają pomocnicze)',
        },
        'oslony': {
            'generatory': 2,        # [U] burta lewa, prawa
            'zyly': 3600,           # [U]
            'siatka': (60, 60),     # [U]
            'U0': 120, 'R0': 10,    # [U] → 1440 W × 10¹⁷
            'sektory': ['górny lewej burty', 'dolny lewej burty', 'górny prawej burty', 'dolny prawej burty',
                        'dziobowy', 'hangaru', 'rufowy'],
            'po_utracie': 'odkryta cała burta',  # [U]
            'ekran': 'SIATKA 60×60 (3600 lampek) · ekran dynamiczny',
            'ekran_bloki': (3, 3),  # [U] 1 lampka = 1 żyła
            'domyslnie_hz': 1800,   # [U]
        },
        'baterie': {
            'ciezkie': 8, 'lufy': 8, 'zyly': 3600,  # [U] 4 lewe + 4 prawe
            'ladowanie_tiki': 6,    # [R]
            'przydzial_reaktora': 0.30,  # [R] udział mocy rdzenia na wszystkie baterie
            'pd': 60,               # [?] 60 (arkusz: 40 lub 60)
            'jonowe_wieze': 2, 'jonowe_nk7': 60,  # [R]
        },
        'mysliwce': 72,             # [U] = 18 kluczy
        'typ_mysliwcow': 'TIE_LN',
        'kadlub': [('burta_l', 'burta lewa', 0.25), ('burta_p', 'burta prawa', 0.25), ('dziob', 'dziób', 0.15),
                   ('rufa', 'rufa', 0.15), ('nadbudowka', 'nadbudówka i mostek', 0.10), ('hangar', 'hangar', 0.10)],  # [?] W13
        'silniki': {'glowne': 3, 'manewrowe': 4, 'obrot_deg_na_min': 30},  # [U]
        'predkosc_km_s': 0.5,       # [R]
    },

    'MC80': {
        'nazwa': 'Krążownik gwiezdny Mon Calamari MC80',
        'dlugosc_m': 1200,
        'reaktor': {
            'moc_W': 6.0e24,        # [?] 3 rdzenie × 2,0×10²⁴ W
            'jednostki': [          # [?] „więcej reaktorów” — rozproszone, bez jednego punktu awarii
                ('rdzeń lewy', 'rdzeń'), ('rdzeń środkowy', 'rdzeń'), ('rdzeń prawy', 'rdzeń'),
                ('reaktor pomocniczy uzbrojenia lewej burty', 'pomocniczy'),
                ('reaktor pomocniczy uzbrojenia prawej burty', 'pomocniczy'),
                ('zapasowy reaktor silników', 'zapasowy'),
            ],
            'utrata_rdzenia': '−⅓ mocy na rdzeń; pozostałe rdzenie przejmują odbiorniki',  # [?]
        },
        'oslony': {
            'generatory': 6,        # [U]
            'zyly': 2187,           # [U] 3⁷; stopnie natywne = dzielniki = potęgi trójki
            'siatka': (81, 27),     # [U] (albo 3 × 27×27)
            'U0': 89, 'R0': 10,     # [U] → 792 W × 10¹⁷ („ok. 790”)
            'sektory': ['dziobowy lewej burty', 'śródokręcia lewej burty', 'rufowy lewej burty',
                        'dziobowy prawej burty', 'śródokręcia prawej burty', 'rufowy prawej burty'],  # [?]
            'po_utracie': 'przekierowanie sektora: sąsiad z tej samej burty kryje utracony sektor '
                          'o jeden stopień natywny niżej (Hz/3), +⅓ poboru, obciążenie z obu sektorów w jego C',  # [U] zasada, [?] reguła
            'ekran': 'SIATKA 27×27 (729 lampek, lampka = 3 żyły) · ekran dynamiczny',  # [?]
            'ekran_bloki': (3, 3),  # [?]
            'domyslnie_hz': 729,    # [?] odpowiednik 1800/3600 u ISD byłby 2187/3 = 729
        },
        'baterie': {
            'ciezkie': 6, 'lufy': 9, 'zyly': 2187,  # [?] 3 na burtę (dziób, śródokręcie, rufa)
            'ladowanie_tiki': 6,    # [?] jak ISD
            'przydzial_reaktora': 0.30,  # [?]
            'pd': 27,               # [?]
            'jonowe_baterie': 6, 'jonowe_lufy': 3,  # [?] Mon Calamari polegają na jonach
        },
        'mysliwce': 36,             # [U] = 9 kluczy
        'typ_mysliwcow': 'X_WING',  # [?] skład w scenariuszach
        'kadlub': [('burta_l', 'burta lewa', 0.25), ('burta_p', 'burta prawa', 0.25), ('dziob', 'dziób', 0.20),
                   ('rufa', 'rufa', 0.20), ('hangar', 'hangar', 0.10)],  # [?] W13
        'silniki': {'glowne': 3, 'manewrowe': 6, 'obrot_deg_na_min': 40},  # [?]
        'predkosc_km_s': 0.5,       # [R]
    },

    'ZBIEG': {
        'nazwa': '„Zbieg” — kurier piracki HLK-P1',
        'dlugosc_m': 40,
        'reaktor': {
            'moc_W': 1.2e23,        # [R] 1,5% ISD
            'jednostki': [('reaktor główny', 'rdzeń'), ('zasobnik burstu 7×10²³ J', 'zasobnik')],
            'utrata_rdzenia': 'okręt martwy, zostaje zasobnik',
        },
        'oslony': {
            'generatory': 1, 'zyly': 1800, 'siatka': None,  # [U] + zapasowy 450 żył
            'zapasowy_zyly': 450,
            'U0': 63.25, 'R0': 10,  # [R] → 400 W × 10¹⁷
            'sektory': ['całość'],
            'po_utracie': 'generator zapasowy do 450 Hz',
            'ekran': None, 'ekran_bloki': None,
            'domyslnie_hz': 900,
        },
        'baterie': None,
        'mysliwce': 0,
        'typ_mysliwcow': None,
        'kadlub': [('calosc', 'kadłub', 1.0)],
        'silniki': None,
        'predkosc_km_s': 1.2,       # [R] burst 2,0 km/s przez 12 tików
    },
}

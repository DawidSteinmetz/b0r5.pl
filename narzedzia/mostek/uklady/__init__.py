"""Układy pulpitów jako dane, osobno dla każdej klasy: {kod_profilu: {nazwa_pulpitu: [elementy]}}."""
import importlib

# Aktualne układy klasy. ISD I: wariant „Walako” (poprawiony przez użytkownika); poprzedni wariant w uklady/isd_i.py —
# powrót: 'ISD_I': 'uklady.isd_i'. Wariant można też podać nazwą modułu, np. `python pulpity_ods.py isd_i stare.ods`.
KLASY = {'ISD_I': 'uklady.isd_i_walako', 'MC80': 'uklady.mc80'}


def _modul(klasa):
    if klasa in KLASY: return importlib.import_module(KLASY[klasa])
    try:
        return importlib.import_module('uklady.' + klasa)
    except ModuleNotFoundError:
        raise SystemExit(f'nieznana klasa lub wariant {klasa}; klasy: {", ".join(KLASY)}')


def uklady(klasa):
    return _modul(klasa).UKLADY


def format_pulpitu(klasa, nazwa):
    """'pulpit' (13×9), 'szeroki' (13×7) albo 'telefon' (5×9) — z FORMATY modułu klasy, domyślnie FORMAT modułu ('pulpit')."""
    m = _modul(klasa)
    return getattr(m, 'FORMATY', {}).get(nazwa, getattr(m, 'FORMAT', 'pulpit'))

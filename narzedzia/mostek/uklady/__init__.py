"""Układy pulpitów jako dane, osobno dla każdej klasy: {kod_profilu: {nazwa_pulpitu: [elementy]}}."""
import importlib

KLASY = {'ISD_I': 'uklady.isd_i', 'MC80': 'uklady.mc80'}


def uklady(klasa):
    if klasa not in KLASY:
        raise SystemExit(f'nieznana klasa {klasa}; dostępne: {", ".join(KLASY)}')
    return importlib.import_module(KLASY[klasa]).UKLADY

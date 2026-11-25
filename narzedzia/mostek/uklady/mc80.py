"""Układy pulpitów MC80 — puste do czasu decyzji z `profile/MC80.md` (sekcja „Wątpliwości przed projektem pulpitów”).

Pulpity mają być analogiczne do ISD I, ale z własną kompozycją: 6 generatorów osłon, rozproszone reaktory,
baterie po 3 na burtę, 9 kluczy myśliwców. Liczby bierz z `PROFILE['MC80']`, nie wpisuj ich w etykiety na sztywno.
"""
from siatka import suwaki  # noqa: F401  (do użycia przy projektowaniu)
from profile_klas import PROFILE

EKRAN = PROFILE['MC80']['oslony']['ekran']

UKLADY = {}

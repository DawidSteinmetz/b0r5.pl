# Mostek — pulpity okrętów i mechanika walki

Wszystko o pulpitach mostka (konsolach okrętów) w jednym miejscu.

| Plik | Zawartość |
|---|---|
| [MECHANIKA.md](MECHANIKA.md) | mechanika walki wspólna dla wszystkich klas (wzory, reguły, decyzje wspólne) |
| [PULPITY.md](PULPITY.md) | zasady pulpitów: siatka 13×9, kolory, suwaki zakresu, symetria burt, ekrany, warstwy, czasowniki |
| [profile/ISD_I.md](profile/ISD_I.md) · [profile/MC80.md](profile/MC80.md) · [profile/ZBIEG.md](profile/ZBIEG.md) | profile klas słownie (MC80: wątpliwości przed projektem pulpitów) |
| [profile_klas.py](profile_klas.py) | profile klas jako dane — jedyne miejsce z liczbami zależnymi od klasy |
| [mechanika.py](mechanika.py) | wzory wspólne w kodzie; `python mechanika.py` przelicza liczby z dokumentacji dla każdej klasy |
| [siatka.py](siatka.py) | język pulpitów: stałe siatki, kolory, suwaki zakresu, kontrola nakładania i symetrii |
| [uklady/](uklady/) | układy pulpitów jako dane, plik na klasę (`isd_i.py` — 8 pulpitów, `mc80.py` — puste) |
| [pulpity_ods.py](pulpity_ods.py) | generator arkusza ODS |
| [pulpity_podglad.py](pulpity_podglad.py) | generator podglądu HTML |
| [ods_model.py](ods_model.py) | odczyt arkusza ODS (komórki, kolory, scalenia) — do porównań z arkuszem użytkownika |

## Uruchamianie

Z katalogu `narzedzia/mostek` (Python 3, bez dodatkowych pakietów):

```
python mechanika.py
python pulpity_ods.py ISD_I pulpity_isd.ods --zrodlo ../../Pulpity_ISD_2_uklad_konsol.ods
python pulpity_ods.py ISD_I pulpity_isd.ods
python pulpity_podglad.py ISD_I podglad.html [Osłony Reaktory ...]
python ods_model.py arkusz.ods
```

Z `--zrodlo` arkusze pulpitów są podmieniane, a arkusze dokumentacji („Warstwy UI”, „Reguły i profile”) przechodzą
bez zmian — tak powstał `Pulpity_ISD_2_uklad_konsol_ujednolicone.ods` (generator odtwarza go 1:1). Pliki ODS
i HTML wynikowe nie trafiają do repo.

## Nowa klasa okrętu

1. Dopisz profil do `profile_klas.py` i opis do `profile/`.
2. Sprawdź liczby: `python mechanika.py`.
3. Dodaj plik układów w `uklady/` i wpis w `uklady/__init__.py` (`KLASY`).
4. Generuj ODS i podgląd; popraw asymetrie wypisane przez generator.

"""Wzory wspólne dla wszystkich klas (`MECHANIKA.md`), parametryzowane profilem (`profile_klas.py`).

Uruchom `python mechanika.py`, by przeliczyć liczby z dokumentacji dla każdej klasy (kontrola rachunkiem).
"""
from profile_klas import PROFILE, WSPOLNE as W


def stopnie_natywne(n):
    """Stopnie natywne = dzielniki liczby żył (ISD 3600: 45 stopni; MC80 3⁷: potęgi trójki)."""
    return [d for d in range(1, n + 1) if n % d == 0]


def stopien_w_gore(n, hz):
    """Najbliższy stopień natywny ≥ hz (optimum osłony wobec lasera hz); None, gdy hz > n — osłona zawsze przebita."""
    return next((s for s in stopnie_natywne(n) if s >= hz), None)


def swieci(n, f, zyla):
    """Żyła (1…n) świeci przy f Hz, gdy zyla mod (n/f) = 0."""
    return zyla % (n // f) == 0


def moc_zyly(profil):
    o = profil['oslony']
    return o['U0'] ** 2 / o['R0'] * W['skala_zyl']


def pobor_oslon(profil, hz=None):
    """Pobór wszystkich generatorów przy hz świecących żyłach na generator (domyślnie pełne)."""
    o = profil['oslony']
    return o['generatory'] * (hz or o['zyly']) * moc_zyly(profil)


def pojemnosc(profil):
    """C jednego generatora [J] = τ · N · P_żyły."""
    return W['tau_pojemnosci_s'] * profil['oslony']['zyly'] * moc_zyly(profil)


def filtr(e, hz_oslony, hz_lasera):
    """(do kadłuba, do generatora) dla energii trafienia e."""
    r = hz_oslony / hz_lasera
    return e * max(0.0, 1 - r), e * min(1.0, r)


def zuzycie(hz):
    return (hz / W['hz_odniesienia']) ** W['wykladnik_zuzycia']


def energia_lufy(hz):
    return W['energia_zyly_lufy_J'] * hz


def raport():
    for kod, p in PROFILE.items():
        o, r = p['oslony'], p['reaktor']
        n = o['zyly']
        st = stopnie_natywne(n)
        print(f'== {kod}: {p["nazwa"]}')
        print(f'   reaktor {r["moc_W"]:.3g} W, porcja {r["moc_W"] / W["porcje"]:.3g} W')
        print(f'   osłony: {o["generatory"]} × {n} żył, {len(st)} stopni natywnych'
              + (f' ({", ".join(map(str, st))})' if len(st) <= 12 else ''))
        print(f'   moc żyły {moc_zyly(p):.4g} W; pełne osłony {pobor_oslon(p):.4g} W = '
              f'{100 * pobor_oslon(p) / r["moc_W"]:.1f}% reaktora; C generatora {pojemnosc(p):.3g} J, '
              f'C razem {o["generatory"] * pojemnosc(p):.3g} J')
        print(f'   zużycie żył przy maks. Hz: ×{zuzycie(n):.1f}')
        b = p.get('baterie')
        if b:
            lufa = energia_lufy(b['zyly'])
            salwa = lufa * b['lufy']
            pobrane = b['przydzial_reaktora'] * r['moc_W'] / b['ciezkie'] * b['ladowanie_tiki']
            print(f'   baterie {b["ciezkie"]} × {b["lufy"]} luf, lufa {lufa:.3g} J, salwa baterii {salwa:.3g} J, '
                  f'salwa burty {salwa * b["ciezkie"] / 2:.3g} J; przydział '
                  f'{b["przydzial_reaktora"] * r["moc_W"] / b["ciezkie"]:.3g} W/bateria = '
                  f'{100 * b["przydzial_reaktora"] / b["ciezkie"]:.2f} porcji; energia salwy / energia pobrana w '
                  f'{b["ladowanie_tiki"]} tikach = {salwa / pobrane:.2%}')
        print(f'   myśliwce {p["mysliwce"]} = {p["mysliwce"] // W["maszyn_w_kluczu"]} kluczy')
    print('== Filtr między klasami (do kadłuba, % energii trafienia)')
    for o_kod, hz_o, l_kod, hz_l in [('MC80', 2187, 'ISD', 3600), ('MC80', 729, 'ISD', 1800),
                                     ('ISD', 1800, 'MC80', 2187), ('ISD', 3600, 'MC80', 2187),
                                     ('ISD', 900, 'ISD', 1200), ('ISD', 1, 'ISD', 3600)]:
        print(f'   osłona {o_kod} {hz_o} Hz vs laser {l_kod} {hz_l} Hz: {100 * filtr(1, hz_o, hz_l)[0]:.2f}%')
    print(f'   optimum ISD wobec lasera MC80 2187 Hz: {stopien_w_gore(3600, 2187)} Hz; '
          f'MC80 wobec lasera ISD 3600 Hz: {stopien_w_gore(2187, 3600)} (zawsze przebita)')


if __name__ == '__main__':
    raport()

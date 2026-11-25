"""Generuje migrację z danymi mechaniki (słowniki i scenariusze) z plików w tym katalogu:
profile_klas.py, dane/maszyny.py, dane/rozkazy.py, dane/scenariusze.py.

    python dane_sql.py                 zapisuje ../../api/migracje/069_mostek_dane.sql
    python dane_sql.py --sprawdz       tylko kontrola (kod 1 przy błędach)

Kontrola: kolumny każdego INSERT istnieją w tabelach z migracji 068, odwołania (profile, typy maszyn, rozkazy, strony)
są spójne, udziały sekcji kadłuba sumują się do 1, liczba kluczy w scenariuszu nie przekracza myśliwców okrętu.
Migracja jest powtarzalna: słowniki przez ON DUPLICATE KEY UPDATE, elementy scenariuszy usuwane i wstawiane od nowa.
"""
import os, re, sys
from profile_klas import PROFILE, WSPOLNE
from dane.maszyny import MASZYNY
from dane.rozkazy import ROZKAZY
from dane.scenariusze import SCENARIUSZE

TU = os.path.dirname(os.path.abspath(__file__))
MIGRACJE = os.path.join(TU, '..', '..', 'api', 'migracje')
SCHEMAT = os.path.join(MIGRACJE, '068_bitwy_symulacje.sql')
CEL = os.path.join(MIGRACJE, '069_mostek_dane.sql')
BLEDY = []


def kolumny_tabel():
    sql = open(SCHEMAT, encoding='utf-8').read()
    wynik = {}
    for nazwa, cialo in re.findall(r'CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\n\) ENGINE', sql, re.S):
        wynik[nazwa] = [m.group(1) for m in re.finditer(r'^\s+(\w+) [A-Z]', cialo, re.M)
                        if m.group(1) not in ('PRIMARY', 'UNIQUE', 'KEY', 'FOREIGN', 'CONSTRAINT', 'CHECK')]
    return wynik


KOLUMNY = kolumny_tabel()


def v(x):
    if x is None: return 'NULL'
    if isinstance(x, bool): return '1' if x else '0'
    if isinstance(x, int): return str(x)
    if isinstance(x, float): return repr(x) if abs(x) >= 1e6 or x != round(x, 6) else f'{x:g}'
    return "'" + str(x).replace('\\', '\\\\').replace("'", "''") + "'"


def insert(tabela, kolumny, wiersze, upsert=True):
    brak = [k for k in kolumny if k not in KOLUMNY.get(tabela, [])]
    if brak: BLEDY.append(f'{tabela}: brak kolumn {brak} w migracji 068')
    for w in wiersze:
        if len(w) != len(kolumny): BLEDY.append(f'{tabela}: {len(w)} wartości na {len(kolumny)} kolumn: {w[:3]}')
    s = f'INSERT INTO {tabela} ({", ".join(kolumny)}) VALUES\n' + ',\n'.join('  (' + ', '.join(v(x) for x in w) + ')' for w in wiersze)
    if upsert:
        s += '\nON DUPLICATE KEY UPDATE ' + ', '.join(f'{k} = VALUES({k})' for k in kolumny[1:])
    return s + ';\n'


STATUS = {'U': 'ustalone', 'R': 'robocze', '?': 'propozycja'}


def mechanika_stale():
    w = WSPOLNE
    st = [
        ('tik_s', w['tik_s'], 'U', 'tik silnika = 1 s czasu gry (tylko wewnątrz silnika)'),
        ('cykl_tiki', w['cykl_tiki'], 'U', 'cykl = 12 tików: limity zmian, rozliczanie czasu'),
        ('porcje', w['porcje'], 'U', '1 porcja = 1% mocy rdzenia'),
        ('skala_zyl', w['skala_zyl'], 'U', 'wartości laboratoryjne żył × 10^17'),
        ('hz_odniesienia', w['hz_odniesienia'], 'R', 'punkt odniesienia poboru i zużycia (Hz/450)'),
        ('wykladnik_zuzycia', round(w['wykladnik_zuzycia'], 6), 'R', 'log2(3): ×3 zużycia na podwojenie Hz'),
        ('tau_pojemnosci_s', w['tau_pojemnosci_s'], 'R', 'C = tau · N · P_zyly'),
        ('energia_zyly_lufy_j', w['energia_zyly_lufy_J'], 'R', 'energia bolta na aktywną żyłę baterii'),
        ('maszyn_w_kluczu', w['maszyn_w_kluczu'], 'U', 'klucz = 4 maszyny'),
        ('xp_akcja_symulacja', 0.5, 'U', 'XP za akcję NPC w symulacji (× mnożnik rangi)'),
        ('ranga_przygotowanie_kadet_s', 1, 'R', 'kadet: +1 s przygotowania rozkazu'),
        ('ranga_przygotowanie_as_s', -1, 'R', 'as: −1 s przygotowania (min. 1 s)'),
        ('zmiany_cykl_standard', 2, 'U', 'limit zmian figury na cykl: zwykły pilot'),
        ('zmiany_cykl_as', 4, 'U', 'limit zmian figury na cykl: as'),
        ('dryf_k_standard', 0.25, 'U', 'dryf Hz klucza: Hz = baza · (1 + k · moc)'),
        ('dryf_k_as', round(1 / 3, 6), 'U', 'dryf Hz klucza z asem'),
        ('kadlub_prog_1', 75, '?', 'próg integralności sekcji uszkadzający systemy'),
        ('kadlub_prog_2', 50, '?', 'próg integralności sekcji'),
        ('kadlub_prog_3', 25, '?', 'próg integralności sekcji'),
    ]
    for bron, (V, O, W_) in w['kanaly_VOW'].items():
        k = {'turbolaser': 'turbolaser', 'wiązka ciągła': 'wiazka', 'ostrzał seryjny': 'seria', 'działo jonowe': 'jon'}[bron]
        st += [(f'kanal_{k}_v', V, 'R', f'{bron}: udział kanału V'), (f'kanal_{k}_omega', O, 'R', f'{bron}: udział kanału Ω'),
               (f'kanal_{k}_w', W_, 'R', f'{bron}: udział kanału W')]
    return insert('mechanika_stale', ['kod', 'wartosc', 'status', 'opis'], [(a, b, STATUS[c], d) for a, b, c, d in st])


def profile():
    wiersze, jedn, sekt, kad = [], [], [], []
    statusy = {'ISD_I': 'ustalony', 'MC80': 'propozycja', 'ZBIEG': 'roboczy'}
    for kod, p in PROFILE.items():
        o, r, b, s = p['oslony'], p['reaktor'], p.get('baterie') or {}, p.get('silniki') or {}
        siatka = o.get('siatka') or (None, None)
        wiersze.append((kod, p['nazwa'], p['dlugosc_m'], r['moc_W'], r.get('utrata_rdzenia'), o['generatory'], o['zyly'],
                        o.get('zapasowy_zyly'), siatka[0], siatka[1], o['U0'], o['R0'], o['domyslnie_hz'], o['po_utracie'],
                        o.get('ekran'), b.get('ciezkie', 0), b.get('lufy', 0), b.get('zyly', 0), b.get('ladowanie_tiki', 6),
                        b.get('przydzial_reaktora', 0), b.get('pd', 0), p['mysliwce'], s.get('glowne'), s.get('manewrowe'),
                        s.get('obrot_deg_na_min'), p['predkosc_km_s'], statusy[kod]))
        role = {'rdzeń': 'rdzen', 'dystrybucja': 'dystrybucja', 'pomocniczy': 'pomocniczy', 'zapasowy': 'zapasowy', 'zasobnik': 'zasobnik'}
        jedn += [(kod, i + 1, n, role[rl]) for i, (n, rl) in enumerate(r['jednostki'])]
        sekt += [(kod, i + 1, n) for i, n in enumerate(o['sektory'])]
        kad += [(kod, sk, n, u) for sk, n, u in p['kadlub']]
        if abs(sum(u for _, _, u in p['kadlub']) - 1) > 1e-9: BLEDY.append(f'{kod}: udziały sekcji kadłuba ≠ 1')
    kol = ['kod', 'nazwa', 'dlugosc_m', 'moc_reaktora_w', 'utrata_rdzenia', 'generatory_oslon', 'zyly_oslon', 'zapasowy_zyly',
           'siatka_kolumny', 'siatka_wiersze', 'zyla_u0_v', 'zyla_r0_ohm', 'oslony_domyslnie_hz', 'po_utracie_generatora', 'ekran_zyl',
           'baterie', 'lufy_na_baterie', 'zyly_baterii', 'ladowanie_tiki', 'przydzial_baterii', 'obrona_punktowa', 'mysliwce',
           'silniki_glowne', 'silniki_manewrowe', 'obrot_deg_na_min', 'predkosc_km_s', 'status']
    kody = ', '.join(v(k) for k in PROFILE)
    return (insert('profile_klas', kol, wiersze) +
            f'\nDELETE FROM profile_klas_jednostki WHERE profil_kod IN ({kody});\n'
            f'DELETE FROM profile_klas_sektory WHERE profil_kod IN ({kody});\n'
            f'DELETE FROM profile_klas_kadlub WHERE profil_kod IN ({kody});\n' +
            insert('profile_klas_jednostki', ['profil_kod', 'nr', 'nazwa', 'rola'], jedn, False) +
            insert('profile_klas_sektory', ['profil_kod', 'nr', 'nazwa'], sekt, False) +
            insert('profile_klas_kadlub', ['profil_kod', 'sekcja', 'nazwa', 'udzial'], kad, False))


def maszyny():
    kol = ['kod', 'nazwa', 'strona', 'rola', 'hz_bazowe', 'oslona_hz', 'predkosc_km_s', 'przekroj_m2', 'energia_strzalu_j',
           'ladowanie_tiki', 'opis']
    return insert('typy_maszyn', kol, MASZYNY)


def rozkazy():
    kol = ['kod', 'nazwa', 'grupa', 'przygotowanie_s', 'dzialanie_s', 'odnowienie_s', 'theta_mn', 'theta_wlasna_mn', 'predkosc_mn',
           'ogien_wylaczony', 'ranga_min', 'ranga_skraca', 'opis']
    return insert('rozkazy_profile', kol, ROZKAZY)


def scenariusze():
    typy = {m[0] for m in MASZYNY}
    rozk = {r[0] for r in ROZKAZY}
    out = []
    for s in SCENARIUSZE:
        k = s['kod']
        strony = {st[0] for st in s['strony']}
        jedn = {j[0]: j for j in s['jednostki']}
        for j in s['jednostki']:
            if j[1] not in strony: BLEDY.append(f'{k}: jednostka {j[3]} — nieznana strona {j[1]}')
            if j[2] not in PROFILE: BLEDY.append(f'{k}: jednostka {j[3]} — nieznany profil {j[2]}')
        nosnosc = {}
        for kl in s['klucze']:
            fala, strona, nr, typ, n = kl[:5]
            if strona not in strony: BLEDY.append(f'{k}: klucze — nieznana strona {strona}')
            if typ not in typy: BLEDY.append(f'{k}: nieznany typ maszyny {typ}')
            if kl[9] not in rozk: BLEDY.append(f'{k}: nieznany rozkaz {kl[9]}')
            if nr is not None:
                if nr not in jedn: BLEDY.append(f'{k}: klucze z nieistniejącej jednostki {nr}')
                else: nosnosc[nr] = nosnosc.get(nr, 0) + n
            elif kl[7] is None: BLEDY.append(f'{k}: klucze z zewnątrz bez pozycji startu')
        for nr, n in nosnosc.items():
            maks = PROFILE[jedn[nr][2]]['mysliwce'] // WSPOLNE['maszyn_w_kluczu']
            if n > maks: BLEDY.append(f'{k}: jednostka {nr} wypuszcza {n} kluczy, a ma {maks}')
        out.append(f'-- {s["nazwa"]}\n' + insert('bitwy_scenariusze', ['kod', 'nazwa', 'odprawa', 'opis_mg', 'okno_s', 'pole_km', 'zwyciestwo', 'porazka', 'status'],
                                                  [(k, s['nazwa'], s['odprawa'], s['opis_mg'], s['okno_s'], s['pole_km'], s['zwyciestwo'], s['porazka'], s['status'])]))
        out.append(f'DELETE FROM bitwy_scenariusze_wyzwalacze WHERE scenariusz_kod = {v(k)};\n'
                   f'DELETE FROM bitwy_scenariusze_strony WHERE scenariusz_kod = {v(k)};\n')
        out.append(insert('bitwy_scenariusze_strony', ['scenariusz_kod', 'strona', 'kolor', 'dowodzenie', 'rola', 'asy_proc', 'kadeci_proc'],
                          [(k,) + st for st in s['strony']], False))
        out.append(insert('bitwy_scenariusze_jednostki', ['scenariusz_kod', 'nr', 'strona', 'profil_kod', 'nazwa', 'z_mapy', 'x_km', 'y_km',
                                                          'kurs_deg', 'predkosc_km_s', 'kadlub', 'wejscie_s', 'wejscie_rozrzut_s'],
                          [(k,) + j for j in s['jednostki']], False))
        out.append(insert('bitwy_scenariusze_klucze', ['scenariusz_kod', 'fala', 'strona', 'jednostka_nr', 'typ_maszyny', 'liczba_kluczy',
                                                       'start_s', 'start_rozrzut_s', 'x_km', 'y_km', 'rozkaz'],
                          [(k,) + kl for kl in s['klucze']], False))
        out.append(insert('bitwy_scenariusze_wyzwalacze', ['scenariusz_kod', 'nr', 'czas_s', 'rozrzut_s', 'rodzaj', 'tresc'],
                          [(k,) + w for w in s['wyzwalacze']], False))
    return '\n'.join(out)


def zbuduj():
    naglowek = ('-- Dane mechaniki walki: stałe, profile klas (ISD_I, MC80, ZBIEG), typy maszyn, czas wykonania rozkazów,\n'
                '-- scenariusze bitew (Atak na Walako, Szkolenie: klucze rebelii nad Walako).\n'
                '-- PLIK GENEROWANY: python narzedzia/mostek/dane_sql.py — nie edytuj, zmieniaj dane w narzedzia/mostek/.\n'
                '-- Wymaga migracji 068. Powtarzalna: ponowny import aktualizuje dane.\n\n'
                'USE hololink;\nSET NAMES utf8mb4;\n\n')
    czesci = [
        '-- Stałe wspólne\n' + mechanika_stale(),
        '-- Profile klas\n' + profile(),
        "-- Przypisanie profilu jednostkom na mapie (tylko puste)\n"
        "UPDATE mapa_obiekty SET profil_klasy = 'ISD_I'\n"
        "WHERE profil_klasy IS NULL AND typ IN ('statek', 'jednostka') AND (klasa = 'ISD I' OR klasa LIKE 'ISD I %' OR kod LIKE '%isd-i-%');\n"
        "UPDATE mapa_obiekty SET profil_klasy = 'MC80'\n"
        "WHERE profil_klasy IS NULL AND typ IN ('statek', 'jednostka') AND klasa LIKE 'MC80%';\n",
        '-- Typy maszyn\n' + maszyny(),
        '-- Czas wykonania rozkazów\n' + rozkazy(),
        '-- Scenariusze\n' + scenariusze(),
        "INSERT INTO schema_migracje (wersja, opis)\n"
        "VALUES ('v069-mostek-dane', 'Dane mechaniki walki: stałe, profile klas, typy maszyn, rozkazy, scenariusze bitew')\n"
        "ON DUPLICATE KEY UPDATE opis = VALUES(opis);\n",
    ]
    return naglowek + '\n'.join(czesci)


if __name__ == '__main__':
    sql = zbuduj()
    if BLEDY:
        print('BŁĘDY:'); [print('  ' + b) for b in BLEDY]; sys.exit(1)
    if '--sprawdz' in sys.argv:
        print('dane spójne'); sys.exit(0)
    with open(CEL, 'w', encoding='utf-8', newline='\n') as f:
        f.write(sql)
    print(f'zapisano {os.path.relpath(CEL, TU)}: {sql.count("INSERT INTO")} instrukcji INSERT, {len(sql)} znaków')

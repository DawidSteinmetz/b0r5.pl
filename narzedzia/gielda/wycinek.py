"""Wycinek bazy dla łańcucha giełd planet — wprost ze zrzutu bazy (phpMyAdmin): dokładny schemat tabel giełdy
i gospodarki oraz minimum danych (bez historii kursów — tylko liczba dni i ostatni kurs; bez kont, postaci i portfeli).
Użycie: python wycinek.py <zrzut_bazy.sql> <wyjscie.sql>
"""
import re, sys, math, datetime
from collections import defaultdict
from pathlib import Path

ZRZUT, WYJ = Path(sys.argv[1]), Path(sys.argv[2])
s = ZRZUT.read_text(encoding='utf-8')

KANDYDACI = ['Coruscant', 'Corellia', 'Kuat', 'Bespin', 'Nar Shaddaa', 'Nal Hutta', 'Zephyria', 'Kessel', 'Mon Calamari',
             'Sullust', 'Fondor', 'Chandrila', 'Alderaan', 'Naboo', 'Tatooine', 'Ryloth', 'Thyferra', 'Muunilinst', 'Carida',
             'Lothal', 'Eriadu', 'Mygeeto', 'Foundry', 'Mattri', 'Ord Mantell', 'Fresia', 'Rendili', 'Agamar', 'Manaan',
             'Affa', 'Cato Neimoidia', 'Kashyyyk', 'Bothawui', 'Hapes', 'Anaxes', 'Corulag', 'Brentaal IV', 'Commenor',
             'Duro', 'Gizer', 'Loronar', 'Hosnian Prime', 'Havis III']
TABELE_SCHEMATU = ['mapa_planety', 'planety_gry', 'gielde', 'towary', 'kategorie_towarow', 'towary_kategorie', 'podmioty',
                   'firmy', 'instrumenty', 'kursy_dzienne', 'gosp_towary', 'gosp_rynek', 'ustawienia', 'schema_migracje']


def wartosci(tekst):
    """Krotki VALUES z dumpu phpMyAdmin (napisy z \\' i '', NULL, liczby)."""
    wiersze, i, n = [], 0, len(tekst)
    while i < n:
        if tekst[i] != '(':
            i += 1; continue
        i += 1; pola = []
        while True:
            while tekst[i] in ' \n': i += 1
            if tekst[i] == "'":
                i += 1; b = []
                while True:
                    c = tekst[i]
                    if c == '\\': b.append({'n': '\n', 'r': '\r', 't': '\t', '0': '\0'}.get(tekst[i + 1], tekst[i + 1])); i += 2; continue
                    if c == "'" and tekst[i + 1] == "'": b.append("'"); i += 2; continue
                    if c == "'": i += 1; break
                    b.append(c); i += 1
                pola.append(''.join(b))
            else:
                j = i
                while tekst[i] not in ',)': i += 1
                t = tekst[j:i].strip()
                pola.append(None if t == 'NULL' else (float(t) if re.fullmatch(r'-?[\d.]+(e[-+]?\d+)?', t) else t))
            while tekst[i] in ' \n': i += 1
            if tekst[i] == ',': i += 1; continue
            if tekst[i] == ')': i += 1; break
        wiersze.append(pola)
    return wiersze


def dane(tabela):
    out = []
    for m in re.finditer(r"^INSERT INTO `" + tabela + r"` \(([^)]*)\) VALUES\n(.*?);\n", s, re.S | re.M):
        kol = [k.strip(' `') for k in m.group(1).split(',')]
        out += [dict(zip(kol, w)) for w in wartosci(m.group(2))]
    return out


def schemat(tabela):
    m = re.search(r"^CREATE TABLE `" + tabela + r"` \(\n(.*?)\n\)[^;]*;", s, re.S | re.M)
    if not m: return f'-- (brak tabeli {tabela})'
    kolumny = [re.sub(r' COLLATE \w+', '', l.strip().rstrip(',')) for l in m.group(1).split('\n') if l.strip().startswith('`')]
    klucze = []
    for a in re.finditer(r"^ALTER TABLE `" + tabela + r"`\n(.*?);", s, re.S | re.M):
        for l in a.group(1).split('\n'):
            l = l.strip().rstrip(',')
            if l.startswith(('ADD PRIMARY', 'ADD UNIQUE', 'ADD KEY', 'ADD CONSTRAINT')): klucze.append(l[4:])
            if 'AUTO_INCREMENT' in l and l.startswith('MODIFY'):
                kol = re.match(r"MODIFY `(\w+)`", l).group(1)
                kolumny = [k + ' AUTO_INCREMENT' if k.startswith(f'`{kol}` ') else k for k in kolumny]
    return f"CREATE TABLE `{tabela}` (\n  " + ',\n  '.join(kolumny + klucze) + '\n);'


def q(v):
    if v is None: return 'NULL'
    if isinstance(v, float):
        t = f'{v:.4f}'.rstrip('0').rstrip('.')
        return t if t not in ('', '-0') else '0'
    return "'" + str(v).replace("'", "''") + "'"


def wstaw(tabela, kolumny, wiersze, kom=''):
    if not wiersze: return f'-- {tabela}: brak wierszy\n'
    return (f'-- {kom}\n' if kom else '') + f"INSERT INTO `{tabela}` ({','.join(kolumny)}) VALUES\n" + ',\n'.join(
        '(' + ','.join(q(w.get(k)) for k in kolumny) + ')' for w in wiersze) + ';\n'


# --- dane ---
planety = {p['nazwa']: p for p in dane('mapa_planety')}
po_id = {int(p['id']): p for p in planety.values()}
gielde = dane('gielde')
instr = dane('instrumenty')
towary = dane('towary')
tow_id = {int(t['id']): t for t in towary}
kategorie = dane('kategorie_towarow')
podmioty = {int(p['id']): p for p in dane('podmioty')}
firmy = dane('firmy')
gt = dane('gosp_towary')
prod = dane('gosp_produkcja')
obiekty = {int(o['id']): o for o in dane('gosp_obiekty')}
zuzycie = dane('gosp_zuzycie')
planety_gry = {int(p['planeta_id']): p for p in dane('planety_gry')}
ustawienia = [u for u in dane('ustawienia') if str(u['klucz']).startswith('gielda.')]
migracje = dane('schema_migracje')

kursy = defaultdict(list)
for m in re.finditer(r"^INSERT INTO `kursy_dzienne`[^\n]*\n(.*?);\n", s, re.S | re.M):
    for w in wartosci(m.group(1)): kursy[int(w[0])].append((w[1], w[2]))
gielda_kod = {int(g['id']): g['kod'] for g in gielde}
stan = []
for i in instr:
    k = sorted(kursy.get(int(i['id']), []))
    firma = podmioty.get(int(i['firma_id']))['nazwa'] if i['firma_id'] else None
    stan.append({**i, 'gielda_kod': gielda_kod[int(i['gielda_id'])], 'towar_nazwa': tow_id[int(i['towar_id'])]['nazwa'] if i['towar_id'] else None,
                 'firma_nazwa': firma, 'dni_historii': float(len(k)), 'kurs_ostatni': k[-1][1] if k else None,
                 'data_pierwsza': k[0][0] if k else None, 'data_ostatnia': k[-1][0] if k else None})
# --- stan po kroku 00 (gielda_00_naprawa.sql): ten sam wzór co migracja ---
rynek = dane('gosp_rynek')
nazwa_pl = {int(g['id']): po_id[int(g['planeta_id'])]['nazwa'] for g in gielde}
gt_kod = {int(g['towar_id']): g for g in gt if g['towar_id']}
cena_r = {(r_['planeta'], r_['towar']): r_['cena_kr_t'] for r_ in rynek if r_['obieg'] == 'oficjalny_hurt'}
for t in towary:
    if t['nazwa'] == 'Cukier 99,7%' and t['masa_t_na_jednostke'] == 10: t['masa_t_na_jednostke'] = 0.01
    if t['nazwa'] == 'Sól 97%' and t['masa_t_na_jednostke'] == 25: t['masa_t_na_jednostke'] = 0.025
mnoznik = {}
for w in stan:
    if w['rodzaj'] != 'towar' or not w['towar_id']: continue
    g = gt_kod.get(int(w['towar_id']))
    c = g and cena_r.get((nazwa_pl[int(w['gielda_id'])], g['kod']))
    if c: mnoznik[w['symbol']] = round(c * tow_id[int(w['towar_id'])]['masa_t_na_jednostke'], 4) / w['cena_bazowa']
if 'TIBP' in mnoznik: mnoznik['TIBW'] = mnoznik['TIBP']
tibw = [t for t in towary if t['nazwa'] == 'Gaz tibanna wysokiej czystości']
tibp = [t for t in towary if t['nazwa'] == 'Gaz tibanna przemysłowy']
if tibw and tibp and int(tibp[0]['id']) in [int(g['towar_id']) for g in gt if g['towar_id']]:
    gp = [g for g in gt if g['towar_id'] and int(g['towar_id']) == int(tibp[0]['id'])][0]
    tibw[0]['cena_bazowa'] = float(round(tibw[0]['cena_bazowa'] * gp['cena_bazowa_kr_t'] * tibp[0]['masa_t_na_jednostke'] / tibp[0]['cena_bazowa']))
for t in towary:
    g = gt_kod.get(int(t['id']))
    if g: t['cena_bazowa'] = float(round(g['cena_bazowa_kr_t'] * t['masa_t_na_jednostke']))
for w in stan:
    k = mnoznik.get(w['symbol'])
    if k and abs(k - 1) > 1e-9:
        w['cena_bazowa'] = round(w['cena_bazowa'] * k, 4); w['kurs_ostatni'] = round(w['kurs_ostatni'] * k, 2) if w['kurs_ostatni'] else None
    if w['dni_historii'] < 30: w['dni_historii'] = w['dni_historii'] + 374; w['data_pierwsza'] = None
    if w['symbol'] == 'ZYW' and w['kategoria'] == 'zywnosc': w['kategoria'] = 'żywność'
gt_tow = {g['kod']: (tow_id[int(g['towar_id'])]['nazwa'] if g['towar_id'] else None) for g in gt}
# Zużycie z obiektów tła (dziś tylko Kuat) — suma na planetę i towar.
zuz = defaultdict(float)
for z in zuzycie: zuz[(obiekty[int(z['obiekt_id'])]['planeta'], z['towar'])] += z['t_doba']
zuz_w = [{'planeta': p, 'towar': t, 'zuzycie_t_doba': v} for (p, t), v in sorted(zuz.items())]
firmy_notowane = {int(i['firma_id']) for i in instr if i['firma_id']}
firmy_w = [{**f, 'nazwa': podmioty[int(f['podmiot_id'])]['nazwa']} for f in firmy if int(f['podmiot_id']) in firmy_notowane or podmioty[int(f['podmiot_id'])]['typ'] == 'firma']
gielda_planety = {po_id[int(g['planeta_id'])]['nazwa'] for g in gielde if int(g['planeta_id']) in po_id}
rynek_w = [r_ for r_ in rynek if r_['obieg'] == 'oficjalny_hurt' and (r_['planeta'] in set(KANDYDACI) | gielda_planety or r_['produkcja_t_doba'] > 0)]
uzyte = sorted(set(KANDYDACI) | gielda_planety | {r_['planeta'] for r_ in rynek_w})
pl_w = [planety[n] for n in uzyte if n in planety]
brak = [n for n in uzyte if n not in planety]
pg_w = [{**planety_gry[int(p['id'])], 'nazwa': p['nazwa']} for p in pl_w if int(p['id']) in planety_gry]

# --- wynik ---
dzis = datetime.date.today().isoformat()
zast = sorted(m['wersja'] for m in migracje)
out = [f"""-- WYCINEK BAZY HOLOLINK DLA ŁAŃCUCHA GIEŁD PLANET — ze zrzutu bazy z {dzis}
-- Plik do czytania, nie do importu. Schemat: dokładne definicje tabel z bazy (MySQL 8.4). Dane: wszystko, czego trzeba
-- do zaprojektowania giełdy kolejnej planety, bez historii kursów (jest liczba dni i ostatni kurs), bez kont i graczy.
-- Bloki „KROK NN” na końcu to zmiany kolejnych kroków łańcucha (późniejszy wygrywa).
--
-- Stan: zrzut bazy + migracja kroku 00 (gielda_00_naprawa.sql): ceny notowań powiązanych z gospodarką = gosp_rynek,
-- ceny odniesienia = gosp_towary.cena_bazowa_kr_t, historia notowań Kuat uzupełniona (data_pierwsza NULL = wstecz).
-- Zastosowane migracje gospodarki: {', '.join(m for m in zast if 'gosp' in m or 'ladowiska' in m) or '—'}.
""", '-- ================= SCHEMAT =================\n']
for t in TABELE_SCHEMATU: out.append(schemat(t) + '\n')
v = re.search(r"VIEW `v_gielda_instrumenty`\s+AS (SELECT .*?);\n", s, re.S)
out.append("-- Widok, z którego gra czyta notowania: łączy instrumenty z kursy_dzienne (INNER JOIN) — notowanie bez ani jednego\n"
           "-- kursu nie pokazuje się graczom. Gra dopisuje kurs dzienny sama (symulacja z ustawień gielda.*).\n"
           "-- CREATE VIEW v_gielda_instrumenty AS SELECT i.id, i.symbol, …, ge.kod AS gielda, k1.data AS dzien, k1.kurs, poprzedni FROM instrumenty i\n"
           "--   JOIN gielde ge … JOIN kursy_dzienne k1 ON k1.instrument_id = i.id AND k1.data = (SELECT MAX(data) …);\n")
out.append('\n-- ================= DANE =================\n')
out.append(wstaw('ustawienia', ['klucz', 'wartosc', 'opis'], ustawienia, 'Ustawienia giełdy (symulacja kursów w grze).'))
out.append(wstaw('mapa_planety', ['id', 'nazwa', 'region', 'x', 'y', 'srednica'], pl_w,
                 'Planety: giełd, kandydaci i producenci. x, y w parsekach. Region: 0 Głębokie Jądro, 1 Światy Jądra, 2 Kolonie, '
                 '3 Wewnętrzne Rubieże, 4 Obszar Ekspansji, 5 Środkowe Rubieże, 6 Zewnętrzne Rubieże, 7 Przestrzeń Huttów, '
                 '8 Nieznane Regiony, 9 Dzika Przestrzeń.'))
if brak: out.append('-- Planet spoza mapy (nazwy w danych gospodarki): ' + ', '.join(brak) + '\n')
out.append(wstaw('planety_gry', ['planeta_id', 'nazwa', 'typ_planety', 'urbanizacja', 'opis'], pg_w, 'Dane gry planet (tylko te, które je mają).'))
out.append(wstaw('gielde', ['id', 'kod', 'planeta_id', 'nazwa', 'aktywna'], gielde, 'Giełdy.'))
out.append(wstaw('kategorie_towarow', ['id', 'kod', 'nazwa'], kategorie, 'Kategorie katalogu (kod = towary.kategoria).'))
out.append(wstaw('towary', ['id', 'nazwa', 'kategoria', 'masa_t_na_jednostke', 'jednostka', 'cena_bazowa', 'kontrabanda', 'opis'], towary,
                 'Katalog towarów (cały). cena_bazowa = cena odniesienia za jednostkę katalogu.'))
out.append(wstaw('firmy', ['podmiot_id', 'nazwa', 'profil', 'kapital_zakladowy', 'skala', 'opis'], firmy_w,
                 'Firmy (podmioty typu firma) — nazwa z tabeli podmioty.'))
out.append("-- Notowania: wiersz instrumenty + gielda_kod, towar_nazwa, firma_nazwa (zamiast kluczy obcych) i stan historii kursów.\n")
out.append(wstaw('instrumenty', ['id', 'symbol', 'nazwa', 'rodzaj', 'kategoria', 'jednostka', 'pochodzenie', 'gielda_kod', 'towar_nazwa', 'firma_nazwa',
                                 'cena_bazowa', 'zmiennosc', 'notowany', 'dni_historii', 'data_pierwsza', 'data_ostatnia', 'kurs_ostatni'], stan))
out.append(wstaw('gosp_towary', ['kod', 'towar_nazwa', 'nazwa', 'kategoria', 'cena_bazowa_kr_t', 'ladownosc_t', 'klasa_transportu'],
                 [{**g, 'towar_nazwa': gt_tow[g['kod']]} for g in gt],
                 'Towary gospodarki: cena_bazowa_kr_t = średnia galaktyczna (kr/t); towar_nazwa = towar katalogu (gosp_towary.towar_id), NULL — brak.'))
out.append(wstaw('gosp_rynek', ['planeta', 'towar', 'produkcja_t_doba', 'zuzycie_t_doba', 'cena_indeks', 'cena_kr_t', 'szczegolowosc'], rynek_w,
                 f'Rynek gospodarki (obieg oficjalny hurt): planety giełd, kandydaci i wszyscy producenci — {len(rynek_w)} z {len(rynek)} wierszy.'))
out.append("""
-- ================= OTWARTE SPRAWY =================
-- Pułapki katalogu (nie twórz kolejnych duplikatów):
--   „Duralstal w blokach” (id 3) i „Durastal” (id 50) to ten sam stop — używaj „Durastal” (powiązany z gospodarką i DUR);
--   „Koaksium stabilizowane” (id 2) obok „Coaxium surowe/rafinowane” — nie dodawaj kolejnego koaksium;
--   „Cukier z Zephyrii” (id 6, 200 000 kr/kg) i „Cukier 99,7%” (200 000 kr za zgrzewkę 10 kg) — decyzja MG;
--   „Glitterstim” w katalogu 800 000 kr/kg (ceny Sektora Hune), notowanie GLT 1500 kr/kg (Nar Shaddaa);
--   „Bacta” w litrach (120 kr/l), notowanie BCT w zbiornikach (900);
--   towary „… z <planeta>” o cenie 0 (fabularne Nowej Rebelii) — nie notuj ich.
-- Do uzgodnienia (ceny z dawnego JSON, bez odpowiednika w gospodarce — skala często wielokrotnie niższa niż gospodarki;
-- uzgadnia krok danej giełdy): cor: COAXR, PLZ, ZBO, NRF, BCT, KOL, MDR, JED · crl: OGN, BRZ, HIP, WIN ·
-- nar: COAX, BLS, GLT, RYL · hune: CUK997, SOL097 (decyzja MG). Spółki — ceny z JSON zostają.
-- Towary gospodarki bez towaru w katalogu (towar_nazwa NULL) — przy pierwszym notowaniu dodaj towar i powiązanie.
-- Planety giełd bez wierszy gosp_rynek: Coruscant, Nar Shaddaa, Zephyria — przy ich kroku dopisz szacunek.

-- ================= KROKI ŁAŃCUCHA =================
-- KROK 00 — zrzut bazy (6 giełd, 53 notowania) + gielda_00_naprawa.sql: ceny notowań towarów z gospodarki (Kuat, Bespin),
--   ceny odniesienia z gosp_towary, historia notowań Kuat wstecz, masy cukru i soli, kategoria ZYW.
""")
WYJ.write_text('\n'.join(out), encoding='utf-8')
print(WYJ, WYJ.stat().st_size, 'B', 'planety', len(pl_w), 'brak', brak, 'rynek', len(rynek_w), 'firmy', len(firmy_w), 'mnozniki', {k: round(v, 3) for k, v in mnoznik.items()})

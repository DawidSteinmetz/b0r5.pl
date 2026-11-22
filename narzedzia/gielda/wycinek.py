"""Wycinek bazy dla łańcucha giełd planet: schemat tabel giełdy i gospodarki + minimum danych (bez historii kursów i graczy).
Użycie: python wycinek.py <migracja_bazowa_gieldy.sql> <wyjscie.sql> [zrzut_bazy.sql — pełna mapa planet]"""
import re, sys, datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BAZA = Path(sys.argv[1])           # gielda_00_baza.sql (poprawiona migracja bazowa)
WYJ = Path(sys.argv[2])

KANDYDACI = ['Coruscant', 'Corellia', 'Kuat', 'Bespin', 'Nar Shaddaa', 'Nal Hutta', 'Zephyria', 'Kessel', 'Mon Calamari', 'Sullust',
             'Fondor', 'Chandrila', 'Alderaan', 'Naboo', 'Tatooine', 'Ryloth', 'Thyferra', 'Muunilinst', 'Carida', 'Lothal',
             'Eriadu', 'Mygeeto', 'Foundry', 'Mattri', 'Ord Mantell', 'Fresia', 'Rendili', 'Agamar', 'Manaan', 'Affa',
             'Cato Neimoidia', 'Kashyyyk', 'Bothawui', 'Hapes', 'Anaxes', 'Corulag', 'Brentaal IV', 'Commenor', 'Duro']

def L(v):
    t = f'{float(v):.4f}'.rstrip('0').rstrip('.')
    return t if t not in ('', '-0') else '0'

def q(v):
    if v is None: return 'NULL'
    if isinstance(v, (int, float)): return f'{L(v)}' if isinstance(v, float) else str(v)
    return "'" + str(v).replace("'", "''") + "'"

# --- planety ---
planety = {}
for m in re.finditer(r"VALUES \((\d+),'((?:[^']|'')*)',(\d+),(-?[\d.]+),(-?[\d.]+)", (ROOT / 'api/mapa_seed.sql').read_text(encoding='utf-8')):
    planety[m.group(2).replace("''", "'")] = (int(m.group(1)), int(m.group(3)), float(m.group(4)), float(m.group(5)))
# Pełna mapa ze zrzutu bazy (planety dopisane migracjami, np. Nar Shaddaa, Zephyria) — nadpisuje seed.
zrzut = Path(sys.argv[3]).read_text(encoding='utf-8') if len(sys.argv) > 3 else ''
for m in re.finditer(r"\((\d+), '((?:[^']|'')*)', (\d+), (-?[\d.]+), (-?[\d.]+), [\d.]+, \d+, \d+, ", zrzut):
    planety[m.group(2).replace("''", "'")] = (int(m.group(1)), int(m.group(3)), float(m.group(4)), float(m.group(5)))

# --- gospodarka: towary i rynek z migracji 048–060 (kolejność plików, IGNORE nie nadpisuje) ---
gt = {}
krotka = r"\(((?:'(?:[^']|'')*'|-?[\d.]+|NULL)(?:\s*,\s*(?:'(?:[^']|'')*'|-?[\d.]+|NULL))*)\)"
def pola(t):
    return [None if x[0] == 'NULL' else (x[1].replace("''", "'") if x[1] is not None and x[0].startswith("'") else float(x[0]))
            for x in [(a, a[1:-1] if a.startswith("'") else None) for a in re.findall(r"'(?:[^']|'')*'|-?[\d.]+|NULL", t)]]
rynek = {}
pliki = sorted(p for p in (ROOT / 'api/migracje').glob('0[4-6][0-9]_*.sql') if 48 <= int(p.name[:3]) <= 60)
for p in pliki:
    s = p.read_text(encoding='utf-8')
    for st in s.split(';\n'):
        if re.search(r'INSERT (IGNORE )?INTO gosp_towary', st):
            kol = re.search(r'gosp_towary \(([^)]*)\)', st).group(1).replace(' ', '').split(',')
            for t in re.findall(krotka, st.split('VALUES', 1)[1]):
                r = dict(zip(kol, pola(t)))
                gt.setdefault(r['kod'], {}).update({k: v for k, v in r.items() if k in ('kod', 'nazwa', 'kategoria', 'cena_bazowa_kr_t')})
        for m in re.finditer(r"UPDATE gosp_towary SET cena_bazowa_kr_t = ([\d.]+) WHERE kod = '([a-z_]+)'", st):
            gt.setdefault(m.group(2), {})['cena_bazowa_kr_t'] = float(m.group(1))
        if re.search(r'INSERT (IGNORE )?INTO gosp_rynek', st):
            ignore = 'IGNORE' in st.split('gosp_rynek')[0]
            for t in re.findall(krotka, st.split('VALUES', 1)[1] if 'VALUES' in st else ''):
                r = pola(t)
                if len(r) != 10: continue
                k = (r[0], r[1], r[2])
                if ignore and k in rynek: continue
                rynek[k] = r

# --- giełda: z migracji bazowej (ceny po poprawkach) ---
b = BAZA.read_text(encoding='utf-8')
gielde = re.findall(r"INSERT INTO `gielde` \(`kod`,`planeta_id`,`nazwa`,`aktywna`\) SELECT '([a-z]+)',(\d+),'([^']+)'", b)
instr = re.findall(r"SELECT '([^']+)','([^']+)','(towar|spolka)','([^']*)','([^']*)',(NULL|'[^']*'),ge\.id,(t\.id|NULL),(f\.podmiot_id|NULL),([0-9.]+),([0-9.]+),1 FROM `gielde` ge[^\n]*?ge\.kod='([a-z]+)'(?:[^\n]*?t\.nazwa='([^']+)')?(?:[^\n]*?p\.nazwa='([^']+)')?", b)
ostatni = {}
for sym in [i[0] for i in instr]:
    st = b.find(f"`symbol`='{sym}');")
    m = list(re.finditer(r"\(@i,'(\d{4}-\d\d-\d\d)',([0-9.]+)\)", b[st:b.find('SET @i', st + 10) if b.find('SET @i', st + 10) > 0 else len(b)]))
    ostatni[sym] = (m[-1].group(1), float(m[-1].group(2))) if m else (None, None)
towary_b = re.findall(r"SELECT '([^']+)','([a-z]+)',([0-9.]+),'([^']*)',(\d+),([01]),'((?:[^']|'')*)' FROM DUAL WHERE NOT EXISTS \(SELECT 1 FROM `towary`", b)
for nazwa, cena in re.findall(r"UPDATE `towary` SET `cena_bazowa`=(\d+) WHERE `nazwa`='([^']+)';", b):
    pass
ceny_upd = {n: int(c) for c, n in re.findall(r"UPDATE `towary` SET `cena_bazowa`=(\d+) WHERE `nazwa`='([^']+)';", b)}
masa_upd = {n: float(m) for m, n in re.findall(r"UPDATE `towary` SET `masa_t_na_jednostke`=([0-9.]+) WHERE `nazwa`='([^']+)';", b)}
powiazania = dict(re.findall(r"UPDATE `gosp_towary` SET `towar_id`=\(SELECT id FROM `towary` WHERE `nazwa`='([^']+)'\) WHERE `kod`='([a-z_]+)';", b))
powiazania = {k: n for n, k in powiazania.items()}
# Towary katalogu spoza migracji bazowej, które są notowane albo są pułapkami (stan bazy z 21.11.2026).
dawne = [('Bacta', 'medycyna', 0.001, 'l', 120, 0, 'Płyn regeneracyjny. Dostawy kontrolowane przez Xi Char.'),
         ('Glitterstim', 'przyprawy', 0.001, 'kg', 800000, 1, 'Narkotyk stymulujący. Obrót nielegalny w całym Sektorze Hune.'),
         ('Duralstal w blokach', 'surowce', 1.0, 't', 350, 0, 'Stop konstrukcyjny. Podstawa przemysłu stoczniowego.'),
         ('Koaksium stabilizowane', 'paliwo', 0.001, 'kg', 12000, 1, 'Paliwo skoków o podwyższonej sprawności. Obrót reglamentowany.'),
         ('Cukier z Zephyrii', 'zywnosc', 0.001, 'kg', 200000, 0, 'Cena wg kursu 1 Z = 100 000 IC.'),
         ('Paliwo hipernapędowe rafinowane', 'paliwo', 1.0, 't', 900, 0, 'Standardowe paliwo okrętowe.'),
         ('Krzem zephyryjski', 'surowce', 1.0, 't', 60, 0, 'Nadprodukcja lokalna.'),
         ('Kryształ kyber surowy', 'surowce', 0.001, 'szt', 25000, 1, 'Monopol Imperium.'),
         ('Racje wojskowe typ R', 'zywnosc', 0.0005, 'szt', 3, 0, 'Dzienna racja żołnierska.'),
         ('Karabin blasterowy E-11', 'bron', 0.0034, 'szt', 900, 0, 'Broń podstawowa piechoty Imperium.'),
         ('Komputer nawigacyjny klasy 2', 'technika', 0.2, 'szt', 45000, 0, 'Moduł obliczeń skoku.'),
         ('Wino alderaańskie', 'luksus', 0.0012, 'szt', 1500, 0, 'Rocznik sprzed zniszczenia Alderaanu.')]

# --- wybór wierszy rynku: planety kandydackie i giełd + wszyscy producenci (najbliższy producent) ---
wazne = set(KANDYDACI) | {n for _, n, _ in [(None, [k for k, v in planety.items() if v[0] == int(pid)][0] if [k for k, v in planety.items() if v[0] == int(pid)] else '', None) for _, pid, _ in gielde]}
wiersze = [r for k, r in sorted(rynek.items()) if r[0] in wazne or (r[3] or 0) > 0]
uzyte_planety = sorted({r[0] for r in wiersze} | set(KANDYDACI) | {n for n in wazne if n})

out = []
dzis = datetime.date.today().isoformat()
out.append(f"""-- WYCINEK BAZY GIEŁDY HOLOLINK — stan {dzis} (łańcuch giełd planet)
-- Schemat tabel jak w bazie (MySQL 8) i minimum danych potrzebnych do zaprojektowania giełdy kolejnej planety.
-- To nie jest plik do importu. Dane w skróconej postaci: kolumny *_kod / *_nazwa zastępują klucze obce
-- (w migracji odwołuj się do nich podzapytaniami). Historia kursów pominięta — jest tylko ostatni kurs.
-- Na końcu pliku dopisywane są bloki „KROK NN” z kolejnych migracji (stan narasta krok po kroku).
""")
out.append("""-- ===== SCHEMAT (prawdziwe tabele) =====
CREATE TABLE mapa_planety (id INT UNSIGNED PRIMARY KEY, nazwa VARCHAR(160) UNIQUE, region TINYINT, x DECIMAL(10,1), y DECIMAL(10,1)); -- x, y w parsekach
CREATE TABLE gielde (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, kod VARCHAR(8) NOT NULL UNIQUE, planeta_id INT UNSIGNED NOT NULL, nazwa VARCHAR(120) NOT NULL UNIQUE, aktywna TINYINT(1) NOT NULL DEFAULT 1);
CREATE TABLE towary (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nazwa VARCHAR(160) NOT NULL,
  kategoria ENUM('paliwo','surowce','zywnosc','medycyna','technika','bron','luksus','przyprawy') NOT NULL,
  masa_t_na_jednostke DECIMAL(12,4) NOT NULL DEFAULT 1, jednostka VARCHAR(20) NOT NULL DEFAULT 'szt',
  cena_bazowa BIGINT UNSIGNED NOT NULL DEFAULT 0, kontrabanda TINYINT(1) NOT NULL DEFAULT 0, opis TEXT);
CREATE TABLE kategorie_towarow (id BIGINT UNSIGNED PRIMARY KEY, kod VARCHAR(40) UNIQUE, nazwa VARCHAR(100)); -- kod = towary.kategoria
CREATE TABLE towary_kategorie (towar_id BIGINT UNSIGNED, kategoria_id BIGINT UNSIGNED, glowna TINYINT(1) NOT NULL DEFAULT 0);
CREATE TABLE podmioty (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, typ VARCHAR(12) NOT NULL, nazwa VARCHAR(160) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'aktywny');
CREATE TABLE firmy (podmiot_id BIGINT UNSIGNED PRIMARY KEY, typ VARCHAR(12) NOT NULL DEFAULT 'firma',
  profil VARCHAR(20) NOT NULL, -- produkcja, wydobycie, transport, finanse, handel, uslugi
  kapital_zakladowy BIGINT UNSIGNED NOT NULL, skala TINYINT NOT NULL, opis TEXT); -- skala 3: 4 mld, 4: 12 mld, 5: 30 mld
CREATE TABLE instrumenty (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, symbol VARCHAR(16) NOT NULL UNIQUE, nazwa VARCHAR(160) NOT NULL,
  rodzaj ENUM('towar','spolka') NOT NULL, kategoria VARCHAR(40) NOT NULL DEFAULT '', jednostka VARCHAR(30) NOT NULL DEFAULT 'szt',
  pochodzenie VARCHAR(80) NULL, gielda_id BIGINT UNSIGNED NOT NULL, towar_id BIGINT UNSIGNED NULL, firma_id BIGINT UNSIGNED NULL UNIQUE,
  cena_bazowa DECIMAL(18,4) UNSIGNED NOT NULL, zmiennosc DECIMAL(6,3) NOT NULL DEFAULT 1, notowany TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_instrumenty_gielda_towar (gielda_id, towar_id));
CREATE TABLE kursy_dzienne (instrument_id BIGINT UNSIGNED NOT NULL, data DATE NOT NULL, kurs DECIMAL(18,4) UNSIGNED NOT NULL, PRIMARY KEY (instrument_id, data));
-- Widok gry łączy instrumenty z kursy_dzienne: notowanie bez kursów nie pokazuje się graczom.
CREATE TABLE gosp_towary (kod VARCHAR(40) PRIMARY KEY, nazwa VARCHAR(120) NOT NULL, kategoria VARCHAR(40) NOT NULL,
  cena_bazowa_kr_t DECIMAL(12,2) NOT NULL, -- średnia cena galaktyczna, kr za tonę
  towar_id BIGINT UNSIGNED NULL UNIQUE);  -- towar katalogu, którym jest ten towar gospodarki
CREATE TABLE gosp_rynek (planeta VARCHAR(160) NOT NULL, towar VARCHAR(40) NOT NULL, obieg VARCHAR(30) NOT NULL DEFAULT 'oficjalny_hurt',
  produkcja_t_doba DECIMAL(14,1) NOT NULL DEFAULT 0, zuzycie_t_doba DECIMAL(14,1) NOT NULL DEFAULT 0,
  cena_indeks DECIMAL(6,3) NOT NULL DEFAULT 1, cena_kr_t DECIMAL(12,2) NOT NULL DEFAULT 0,
  szczegolowosc ENUM('obiekty','szacunek') NOT NULL DEFAULT 'szacunek', zrodlo VARCHAR(80) NOT NULL DEFAULT 'wygenerowane',
  uwagi VARCHAR(300) NOT NULL DEFAULT '', PRIMARY KEY (planeta, towar, obieg));
CREATE TABLE ustawienia (klucz VARCHAR(80) PRIMARY KEY, wartosc VARCHAR(255), opis VARCHAR(255));
CREATE TABLE schema_migracje (wersja VARCHAR(80) PRIMARY KEY, opis VARCHAR(255));
-- portfele_gieldowe i transakcje_gieldowe (dane graczy) istnieją, ale migracje giełdy ich nie dotykają.
""")
out.append("-- ===== DANE =====\nINSERT INTO ustawienia VALUES ('gielda.powrot','0.08','siła powrotu kursu do ceny bazowej'),('gielda.prowizja','0.005','prowizja'),"
           "('gielda.zmiennosc_towar','0.025','dzienna zmienność towarów'),('gielda.zmiennosc_spolka','0.018','dzienna zmienność spółek');\n")
out.append("-- Planety: giełd, kandydaci do kolejnych kroków i producenci z gosp_rynek (region: 0 Głębokie Jądro, 1 Światy Jądra, 2 Kolonie, 3 Wewnętrzne Rubieże, 4 Obszar Ekspansji, 5 Środkowe Rubieże, 6 Zewnętrzne Rubieże, 7 Przestrzeń Huttów, 8 Nieznane Regiony, 9 Dzika Przestrzeń)")
out.append("INSERT INTO mapa_planety (id,nazwa,region,x,y) VALUES\n" + ',\n'.join(f"({planety[n][0]},{q(n)},{planety[n][1]},{L(planety[n][2])},{L(planety[n][3])})" for n in uzyte_planety if n in planety) + ';\n')
brak = [n for n in uzyte_planety if n not in planety]
DODATKOWE = {'Wadi Raffa': (-9850.0, 4120.0), 'Nag Ubdur': (7340.0, -10950.0)}  # planety dopisane migracjami gospodarki
if brak: out.append("INSERT INTO mapa_planety (id,nazwa,region,x,y) VALUES " + ','.join(f"(NULL,{q(n)},NULL,{L(DODATKOWE[n][0])},{L(DODATKOWE[n][1])})" for n in brak if n in DODATKOWE) + "; -- id nadane migracją gospodarki\n")
out.append("INSERT INTO gielde (kod,planeta_id,nazwa) VALUES\n" + ',\n'.join(f"({q(k)},{pid},{q(n)})" for k, pid, n in gielde) + ';\n')
tw = [(n, k, float(m), j, ceny_upd.get(n, int(c)), int(kb), o.replace("''", "'")) for n, k, m, j, c, kb, o in towary_b]
tw = [(n, k, masa_upd.get(n, m), j, c, kb, o) for n, k, m, j, c, kb, o in tw]
nazwy = {t[0] for t in tw}
tw += [d for d in dawne if d[0] not in nazwy]
out.append("-- Katalog towarów (towary notowane, powiązane z gospodarką i takie, które łatwo zdublować).")
out.append("INSERT INTO towary (nazwa,kategoria,masa_t_na_jednostke,jednostka,cena_bazowa,kontrabanda,opis) VALUES\n" + ',\n'.join(
    f"({q(n)},{q(k)},{L(m)},{q(j)},{c},{kb},{q(o)})" for n, k, m, j, c, kb, o in tw) + ';\n')
out.append("-- Spółki notowane (podmioty typ 'firma' + firmy) — tylko nazwy; nowe spółki dodawaj obiema tabelami.")
spolki = sorted({i[12] for i in instr if i[2] == 'spolka' and i[12]})
out.append("INSERT INTO podmioty (typ,nazwa) VALUES\n" + ',\n'.join(f"('firma',{q(n)})" for n in spolki) + ';\n')
out.append("-- Notowania: gielda_kod / towar_nazwa / firma_nazwa zamiast kluczy; kurs_ostatni i data_ostatnia z kursy_dzienne.")
out.append("-- CREATE VIEW stan_instrumenty (symbol,nazwa,rodzaj,kategoria,jednostka,pochodzenie,gielda_kod,towar_nazwa,firma_nazwa,cena_bazowa,zmiennosc,kurs_ostatni,data_ostatnia)")
out.append("INSERT INTO stan_instrumenty VALUES\n" + ',\n'.join(
    f"({q(i[0])},{q(i[1])},{q(i[2])},{q(i[3])},{q(i[4])},{'NULL' if i[5] == 'NULL' else i[5]},{q(i[10])},{q(i[11] or None)},{q(i[12] or None)},{L(float(i[8]))},{L(float(i[9]))},{L(ostatni[i[0]][1])},{q(ostatni[i[0]][0])})"
    for i in instr) + ';\n')
out.append("-- Towary gospodarki: towar_nazwa = towar katalogu (gosp_towary.towar_id); NULL — jeszcze bez towaru w katalogu.")
out.append("INSERT INTO gosp_towary (kod,nazwa,kategoria,cena_bazowa_kr_t,towar_nazwa) VALUES\n" + ',\n'.join(
    f"({q(k)},{q(v.get('nazwa'))},{q(v.get('kategoria'))},{L(v.get('cena_bazowa_kr_t', 100))},{q(powiazania.get(k))})" for k, v in sorted(gt.items())) + ';\n')
out.append(f"-- Rynek (obieg oficjalny hurt): wszystkie planety giełd i kandydaci + wszyscy producenci. {len(wiersze)} wierszy.")
out.append("INSERT INTO gosp_rynek (planeta,towar,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc) VALUES\n" + ',\n'.join(
    f"({q(r[0])},{q(r[1])},{L(r[3])},{L(r[4])},{L(r[5])},{L(r[6])},{q(r[7])})" for r in wiersze) + ';\n')
out.append("""-- ===== OTWARTE SPRAWY =====
-- Do uzgodnienia (ceny z dawnego JSON, skala inna niż gospodarka; uzgadnia krok danej giełdy):
--   cor: COAXR, PLZ, ZBO, NRF, BCT, KOL, MDR, JED · crl: OGN, BRZ, HIP, WIN · nar: COAX, BLS, GLT, RYL · hune: CUK997, SOL097 (decyzja MG)
-- Pułapki katalogu: „Duralstal w blokach” = durastal; „Koaksium stabilizowane” obok „Coaxium surowe/rafinowane” (nie dodawaj
--   kolejnego); „Cukier z Zephyrii” 200 000 kr/kg vs CUK997 200 000 kr za 10 kg (decyzja MG); Glitterstim w katalogu
--   800 000 kr/kg vs GLT 1500 kr/kg; Bacta w litrach vs BCT w zbiornikach.
-- Kuat: czesci_maszyn (eksport 90 000 t/d) bez notowania.

-- ===== KROKI ŁAŃCUCHA (dopisuj na końcu bloki „KROK NN” z odpowiedzi kolejnych instancji) =====
-- KROK 00 — migracja bazowa: 6 giełd, 53 notowania, ceny Kuat z gosp_rynek.
""")
WYJ.write_text('\n'.join(out), encoding='utf-8')
print(WYJ, WYJ.stat().st_size, 'B, rynek', len(wiersze), 'planety', len(uzyte_planety), 'instr', len(instr), 'brak', brak)

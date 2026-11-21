# Generator migracji 050: tabela skrótowa gospodarki galaktycznej (gosp_rynek), ceny bazowe towarów, obieg linii.
# Dane wejściowe: api/mapa_seed.sql (planety) i api/migracje/048_gospodarka_kuat.sql (towary, produkcja, zużycie).
# Wzór ceny (ten sam w prompcie dla kolejnych planet — narzedzia/gospodarka/PROMPT_PLANETA.md):
#   saldo = produkcja − zużycie (t/dobę), obrót = produkcja + zużycie
#   f_saldo = 1 − 0,30 · saldo / obrót                  (czysty eksporter 0,70; czysty importer 1,30)
#   f_transport = 1 + 0,00008 · d                       (d — parseki do najbliższego producenta; dla producenta 0)
#   cena_indeks = min(2,5; max(0,5; f_saldo · f_transport)), cena_kr_t = cena_bazowa · cena_indeks
import re, math

ROOT = r'E:/b0r5.pl'
src = open(f'{ROOT}/api/mapa_seed.sql', encoding='utf-8').read()
pl = {}
for m in re.finditer(r"VALUES \((\d+),'((?:[^']|'')*)',(\d+),(-?[\d.]+),(-?[\d.]+)", src):
    pl[m.group(2).replace("''", "'")] = (float(m.group(4)), float(m.group(5)))
pl['Wadi Raffa'] = (-9850.0, 4120.0); pl['Nag Ubdur'] = (7340.0, -10950.0)
g = open(f'{ROOT}/api/migracje/048_gospodarka_kuat.sql', encoding='utf-8').read()
def blok(tabela):
    out = []
    for m in re.finditer(r'INSERT INTO ' + tabela + r' \([^)]*\) VALUES\n(.*?);\n', g, re.S):
        for line in m.group(1).split('\n'):
            line = line.strip().rstrip(',')
            if line.startswith('('): out.append(re.findall(r"'((?:[^']|'')*)'|(-?[\d.]+|NULL)", line[1:-1]))
    return [[a.replace("''", "'") if a else b for a, b in row] for row in out]
towary = blok('gosp_towary')
produkcja = blok('gosp_produkcja')
obiekty = {r[0]: r[1] for r in blok('gosp_obiekty')}          # id -> planeta
zuzycie = blok('gosp_zuzycie')

CENY = {'ruda_zelaza': 40, 'wegiel': 30, 'lommit': 220, 'karwanium': 900, 'meleenium': 1400, 'zersium': 1600, 'neutronium': 5000,
        'metale_rzadkie': 2500, 'tibanna': 1800, 'paliwo': 600, 'elektronika': 12000, 'durastal': 350, 'zywnosc': 120, 'luksus': 30000}
rynek = {}   # (planeta, towar) -> [produkcja, zuzycie, szczegolowosc, zrodlo]
for n, k, w, z in produkcja:
    r = rynek.setdefault((n, k), [0.0, 0.0, 'szacunek', z]); r[0] += float(w)
for oid, k, t in zuzycie:
    n = obiekty[oid]
    r = rynek.setdefault((n, k), [0.0, 0.0, 'obiekty', 'wygenerowane']); r[1] += float(t); r[2] = 'obiekty'
producenci = {}
for (n, k), r in rynek.items():
    if r[0] > 0: producenci.setdefault(k, []).append(n)

def indeks(n, k, r):
    obrot = r[0] + r[1]
    f_saldo = 1 - 0.30 * (r[0] - r[1]) / obrot if obrot else 1
    if r[0] > 0: d = 0.0
    else:
        d = min((math.hypot(pl[n][0] - pl[p][0], pl[n][1] - pl[p][1]) for p in producenci.get(k, []) if p in pl), default=3000.0)
    return round(min(2.5, max(0.5, f_saldo * (1 + 0.00008 * d))), 3), round(d)

def q(v): return "'" + str(v).replace("'", "''") + "'"
wiersze = []
for (n, k), r in sorted(rynek.items()):
    ind, d = indeks(n, k, r)
    uw = (f'eksporter; ' if r[0] > r[1] else f'importer; najbliższy producent {d} pc; ') + ('popyt z obiektów tła' if r[2] == 'obiekty' else 'szacunek wydajności')
    wiersze.append(f"({q(n)},{q(k)},'oficjalny_hurt',{r[0]:.0f},{r[1]:.0f},{ind},{round(CENY[k] * ind, 2)},{q(r[2])},{q(r[3])},{q(uw)})")

out = ["""-- Tabela skrótowa gospodarki galaktycznej: kto co produkuje i zużywa, za ile (obieg oficjalny, hurtowy).
--
-- gosp_rynek — jeden wiersz na planetę, towar i obieg: produkcja i zużycie (t/dobę), indeks ceny (1,00 = średnia
-- galaktyczna) i cena lokalna (kr/t), szczegółowość: „obiekty” (planeta opracowana: obiekty tła i linie dostaw)
-- albo „szacunek” (znany popyt / podaż bez obiektów). Z niej korzystają kolejne prompty planet i potem giełda.
-- gosp_towary.cena_bazowa_kr_t — średnia galaktyczna. gosp_linie.obieg — obieg kontraktu (na razie oficjalny hurt;
-- później szary i przemyt). Wzór ceny: narzedzia/gospodarka/rynek_050.py i PROMPT_PLANETA.md.
--
-- Import w phpMyAdmin (po 048 i 049). Ponowny import: ALTER zgłosi „Duplicate column” — wtedy wystarczy reszta pliku.

USE hololink;
SET NAMES utf8mb4;

ALTER TABLE gosp_towary ADD COLUMN cena_bazowa_kr_t DECIMAL(12,2) NOT NULL DEFAULT 100;
ALTER TABLE gosp_linie ADD COLUMN obieg VARCHAR(30) NOT NULL DEFAULT 'oficjalny_hurt';

CREATE TABLE IF NOT EXISTS gosp_rynek (
  planeta VARCHAR(160) NOT NULL,
  towar VARCHAR(40) NOT NULL,
  obieg VARCHAR(30) NOT NULL DEFAULT 'oficjalny_hurt',
  produkcja_t_doba DECIMAL(14,1) NOT NULL DEFAULT 0,
  zuzycie_t_doba DECIMAL(14,1) NOT NULL DEFAULT 0,
  cena_indeks DECIMAL(6,3) NOT NULL DEFAULT 1,
  cena_kr_t DECIMAL(12,2) NOT NULL DEFAULT 0,
  szczegolowosc ENUM('obiekty','szacunek') NOT NULL DEFAULT 'szacunek',
  zrodlo VARCHAR(80) NOT NULL DEFAULT 'wygenerowane',
  uwagi VARCHAR(300) NOT NULL DEFAULT '',
  zmieniono TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (planeta, towar, obieg),
  KEY ix_gosp_rynek_towar (towar, obieg)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
"""]
out += [f"UPDATE gosp_towary SET cena_bazowa_kr_t = {v} WHERE kod = {q(k)};" for k, v in CENY.items()]
out.append('\nINSERT INTO gosp_rynek (planeta,towar,obieg,produkcja_t_doba,zuzycie_t_doba,cena_indeks,cena_kr_t,szczegolowosc,zrodlo,uwagi) VALUES\n'
           + ',\n'.join(wiersze) + '\nON DUPLICATE KEY UPDATE produkcja_t_doba = VALUES(produkcja_t_doba), zuzycie_t_doba = VALUES(zuzycie_t_doba),\n'
           '  cena_indeks = VALUES(cena_indeks), cena_kr_t = VALUES(cena_kr_t), szczegolowosc = VALUES(szczegolowosc), uwagi = VALUES(uwagi);\n')
out.append("""INSERT INTO schema_migracje (wersja, opis)
VALUES ('v50-gosp-rynek', 'Gospodarka: tabela skrótowa rynku, ceny bazowe, obieg linii')
ON DUPLICATE KEY UPDATE opis = VALUES(opis);
""")
open(f'{ROOT}/api/migracje/050_gosp_rynek.sql', 'w', encoding='utf-8', newline='\n').write('\n'.join(out))
print(len(wiersze), 'wierszy rynku')
for (n, k) in [('Kuat', 'zywnosc'), ('Mattri', 'zywnosc'), ('Foundry', 'zywnosc'), ('Albrae-Don', 'luksus'), ('Kuat', 'luksus')]:
    r = rynek[(n, k)]; print(n, k, r[:2], indeks(n, k, r))

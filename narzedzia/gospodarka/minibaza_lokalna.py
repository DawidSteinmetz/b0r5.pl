# Minibaza gospodarki złożona lokalnie (bez dostępu do bazy): planety z mapa_seed.sql, tabele gosp_* z migracji 048
# i 050 oraz szczegółowe migracje planet od 051. Cykle i typy planet można pobrać ze zrzutu bazy. Ten sam układ co panel MG → Giełda → „Pobierz minibazę”
# (api/gospodarka.php, gosp_minibaza) — po imporcie migracji lepiej pobierać ją stamtąd (aktualne dane).
# Użycie: python minibaza_lokalna.py <plik_wyjsciowy> [zrzut_bazy.sql] [planeta1,planeta2]
import re, sys, datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
wyj = sys.argv[1]
zrzut = sys.argv[2] if len(sys.argv) > 2 and sys.argv[2] else None
planety = [p.strip() for p in (sys.argv[3] if len(sys.argv) > 3 else '').split(',') if p.strip()]

def wycinek(tekst, start):
    i = tekst.find(start)
    if i < 0: return ''
    j = tekst.find(';\n', i)
    return tekst[i:j + 2]

out = [f"-- MINIBAZA GOSPODARKI HOLOLINK — {datetime.datetime.utcnow():%Y-%m-%d %H:%M} UTC (baza 048–050 + szczegółowe planety)\n"
       f"-- Dane referencyjne dla promptu planety (bez kont i postaci). Planety szczegółowe: {', '.join(planety) or '—'}\n"]
m048 = (ROOT / 'api/migracje/048_gospodarka_kuat.sql').read_text(encoding='utf-8')
m050 = (ROOT / 'api/migracje/050_gosp_rynek.sql').read_text(encoding='utf-8')
# struktura tabel
for t in ['gosp_towary', 'gosp_produkcja', 'gosp_obiekty', 'gosp_zuzycie', 'gosp_linie']:
    out.append(wycinek(m048, f'CREATE TABLE IF NOT EXISTS {t} ('))
out.append(wycinek(m050, 'CREATE TABLE IF NOT EXISTS gosp_rynek ('))
out.append("-- (po migracjach 049–050: gosp_obiekty.lokalizacja_id, gosp_towary.cena_bazowa_kr_t, gosp_linie.obieg)\n"
           "ALTER TABLE gosp_obiekty ADD COLUMN lokalizacja_id BIGINT UNSIGNED NULL;\n"
           "ALTER TABLE gosp_towary ADD COLUMN cena_bazowa_kr_t DECIMAL(12,2) NOT NULL DEFAULT 100;\n"
           "ALTER TABLE gosp_linie ADD COLUMN obieg VARCHAR(30) NOT NULL DEFAULT 'oficjalny_hurt';\n")
# planety
seed = (ROOT / 'api/mapa_seed.sql').read_text(encoding='utf-8')
wiersze = re.findall(r"VALUES (\(\d+,'(?:[^']|'')*',\d+,[^)]*\));", seed)
out.append(f"-- mapa_planety: {len(wiersze) + 2} wierszy (kolumny jak w bazie)\nINSERT INTO mapa_planety (id,nazwa,region,x,y,grawitacja,slonca,ksiezyce,doba,rok,srednica) VALUES\n"
           + ',\n'.join(wiersze) + ";\n-- + migracja 048: Wadi Raffa (6, -9850, 4120), Nag Ubdur (6, 7340, -10950)\n")
if zrzut:
    z = open(zrzut, encoding='utf-8').read()
    out.append('-- planety_cykle (nadpisy cyklu i średnicy — astro_cykl bierze srednica_km stąd przed mapa_planety.srednica)\n' + wycinek(z, 'INSERT INTO `planety_cykle`'))
    out.append('-- planety_gry (typ planety, urbanizacja)\n' + wycinek(z, 'INSERT INTO `planety_gry`'))
    if planety:
        ids = {m.group(2): m.group(1) for m in re.finditer(r"VALUES \((\d+),'((?:[^']|'')*)'", seed)}
        blok = wycinek(z, 'INSERT INTO `lokalizacje`')
        szuk = [ids[p] for p in planety if p in ids]
        lok = [l for l in blok.split('\n') if l.startswith('(') and re.match(r"\(\d+, '[a-z]+', [^,]+, [^,]+, [^,]+, (" + '|'.join(szuk) + r"), ", l)] if szuk else []
        out.append(f"-- lokalizacje planet {', '.join(planety)}: {len(lok)} wierszy\n" + ('INSERT INTO lokalizacje VALUES\n' + '\n'.join(lok).rstrip(',;') + ';\n' if lok else ''))
# gosp_* — dane z 048 (bez DELETE i bez planet) i rynek z 050
for t in ['gosp_towary', 'gosp_produkcja', 'gosp_obiekty', 'gosp_zuzycie']:
    out.append(wycinek(m048, f'INSERT INTO {t} ('))
for b in re.findall(r'(INSERT INTO gosp_linie \(.*?;\n)', m048, re.S): out.append(b)
out.append('-- ceny bazowe (050)\n' + '\n'.join(re.findall(r'UPDATE gosp_towary SET cena_bazowa_kr_t = .*?;', m050)) + '\n')
out.append(wycinek(m050, 'INSERT INTO gosp_rynek ('))
# Include detailed planet migrations after the shared baseline so the local minibase stays cumulative.
# These migrations are idempotent and only rebuild their own planet's object/consumption/line rows.
planet_migrations = sorted((ROOT / 'api/migracje').glob('[0-9][0-9][0-9]_gospodarka_*.sql'))
for migration in planet_migrations:
    version = int(migration.name[:3])
    if version >= 51:
        out.append(f"-- Szczegółowe dane planetarne: {migration.name}\n")
        out.append(migration.read_text(encoding='utf-8'))
open(wyj, 'w', encoding='utf-8', newline='\n').write('\n'.join(out))
print('zapisano', wyj)

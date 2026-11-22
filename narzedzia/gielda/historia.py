# Historia kursów notowania dla migracji giełdy (ten sam algorytm co w poprawionej migracji bazowej — kolejne
# planety muszą mieć porównywalne wykresy). Błądzenie losowe z powrotem do ceny bazowej:
#   k(n+1) = max(0,2·baza; k(n) · exp(σ · z) + 0,08 · (baza − k(n))),  z ~ N(0, 1)
#   σ = 0,025 · zmiennosc (towar) albo 0,018 · zmiennosc (spółka)       — ustawienia gielda.zmiennosc_towar / _spolka
#   ziarno = crc32(symbol), więc ten sam symbol daje ten sam przebieg (plik powtarzalny).
# Zakres: 375 dni kończących się w dniu --do (domyślnie dziś), pierwszy dzień = cena bazowa.
#
# Użycie:  python historia.py SYMBOL CENA_BAZOWA ZMIENNOSC [--spolka] [--do RRRR-MM-DD]
# Wynik:   SET @i := …; INSERT INTO kursy_dzienne … ON DUPLICATE KEY UPDATE … (po 150 dni na INSERT).
import sys, math, random, zlib, datetime


def historia(symbol: str, baza: float, zmiennosc: float, spolka: bool = False, do: datetime.date | None = None) -> str:
    do = do or datetime.date.today()
    od = do - datetime.timedelta(days=374)
    los = random.Random(zlib.crc32(symbol.encode()))
    sigma = (0.018 if spolka else 0.025) * zmiennosc
    k, d, wiersze = baza, od, []
    while d <= do:
        wiersze.append(f"(@i,'{d.isoformat()}',{round(k, 2):g})")
        k = max(baza * 0.2, k * math.exp(sigma * los.gauss(0, 1)) + 0.08 * (baza - k))
        d += datetime.timedelta(days=1)
    bloki = [wiersze[i:i + 150] for i in range(0, len(wiersze), 150)]
    sql = [f"SET @i := (SELECT id FROM `instrumenty` WHERE `symbol`='{symbol}');"]
    for b in bloki:
        sql.append("INSERT INTO `kursy_dzienne` (`instrument_id`,`data`,`kurs`) VALUES\n" + ',\n'.join(b)
                   + " ON DUPLICATE KEY UPDATE `kurs`=VALUES(`kurs`);")
    return '\n'.join(sql)


if __name__ == '__main__':
    a = [x for x in sys.argv[1:] if not x.startswith('--')]
    do = None
    if '--do' in sys.argv:
        do = datetime.date.fromisoformat(sys.argv[sys.argv.index('--do') + 1])
        a = [x for x in a if x != sys.argv[sys.argv.index('--do') + 1]]
    print(historia(a[0], float(a[1]), float(a[2]), '--spolka' in sys.argv, do))

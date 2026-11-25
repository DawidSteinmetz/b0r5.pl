"""Model arkusza ODS: dla każdego arkusza siatka komórek (tekst, kolor tła, scalenia, styl), szerokości kolumn, wysokości wierszy."""
import zipfile, re, html, json, sys

def wczytaj(sciezka):
    z = zipfile.ZipFile(sciezka)
    x = z.read('content.xml').decode('utf-8')
    auto = re.search(r'<office:automatic-styles>(.*?)</office:automatic-styles>', x, re.S).group(1)
    style = {}
    for m in re.finditer(r'<style:style style:name="([^"]+)" style:family="([^"]+)"[^>]*>(.*?)</style:style>', auto, re.S):
        nazwa, rodz, tresc = m.groups()
        d = {'rodzaj': rodz}
        for k in ['fo:background-color', 'style:column-width', 'style:row-height', 'fo:border-top', 'fo:border-bottom', 'fo:border-left', 'fo:border-right', 'fo:color', 'fo:font-size', 'fo:font-weight']:
            mm = re.search(k + r'="([^"]+)"', tresc)
            if mm: d[k] = mm.group(1)
        style[nazwa] = d
    arkusze = []
    for nazwa, atr, body in re.findall(r'<table:table table:name="([^"]*)"([^>]*)>(.*?)</table:table>', x, re.S):
        kol = []
        for m in re.finditer(r'<table:table-column([^>]*)/>', body):
            a = m.group(1)
            n = int(re.search(r'number-columns-repeated="(\d+)"', a).group(1)) if 'repeated' in a else 1
            st = re.search(r'table:style-name="([^"]+)"', a)
            kol += [style.get(st.group(1), {}).get('style:column-width') if st else None] * min(n, 30)
        wiersze = []
        for rm in re.finditer(r'<table:table-row([^>]*)>(.*?)</table:table-row>', body, re.S):
            ra, rb = rm.groups()
            rn = int(re.search(r'number-rows-repeated="(\d+)"', ra).group(1)) if 'rows-repeated' in ra else 1
            rst = re.search(r'table:style-name="([^"]+)"', ra)
            komorki = []
            for cm in re.finditer(r'<table:(table-cell|covered-table-cell)([^>]*?)(?:/>|>(.*?)</table:\1>)', rb, re.S):
                typ, ca, cb = cm.groups()
                n = int(re.search(r'number-columns-repeated="(\d+)"', ca).group(1)) if 'columns-repeated' in ca else 1
                st = re.search(r'table:style-name="([^"]+)"', ca)
                sp = re.search(r'number-columns-spanned="(\d+)"', ca)
                spr = re.search(r'number-rows-spanned="(\d+)"', ca)
                txt = ' / '.join(html.unescape(re.sub(r'<[^>]+>', '', p)) for p in re.findall(r'<text:p[^>]*>(.*?)</text:p>', cb or '', re.S))
                s = style.get(st.group(1), {}) if st else {}
                for _ in range(min(n, 30)):
                    komorki.append({'typ': 'k' if typ == 'table-cell' else 'p', 'tekst': txt, 'tlo': s.get('fo:background-color'),
                                    'styl': st.group(1) if st else None, 'sk': int(sp.group(1)) if sp else 1, 'sw': int(spr.group(1)) if spr else 1})
            for _ in range(min(rn, 5)):
                wiersze.append({'wys': style.get(rst.group(1), {}).get('style:row-height') if rst else None, 'komorki': komorki})
        arkusze.append({'nazwa': nazwa, 'kolumny': kol, 'wiersze': wiersze})
    return arkusze, style

if __name__ == '__main__':
    ark, st = wczytaj(sys.argv[1])
    for a in ark[:int(sys.argv[2]) if len(sys.argv) > 2 else 99]:
        print('=====', a['nazwa'], 'kol:', a['kolumny'][:14])
        for i, w in enumerate(a['wiersze'][:12]):
            print(f"r{i} h={w['wys']}: " + ' | '.join(
                f"{c['tlo'] or '-'}{'['+str(c['sk'])+'x'+str(c['sw'])+']' if c['sk']>1 or c['sw']>1 else ''}{'(p)' if c['typ']=='p' else ''}:{c['tekst'][:18]}"
                for c in w['komorki'][:14]))

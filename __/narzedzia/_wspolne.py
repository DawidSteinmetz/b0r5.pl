"""Wspólne funkcje narzędzi Myśloodsiewni (tylko biblioteka standardowa)."""
import json
import re
import unicodedata
from pathlib import Path

KORZEN = Path(__file__).resolve().parent.parent
ID_RE = re.compile(r'^[a-z0-9_-]+$')
DOMYSLNE_FOLDERY = [{"nazwa": "przedmioty", "rodzaj": "przedmiot"}]


def wczytaj_json(sciezka):
    try:
        return json.loads(Path(sciezka).read_text(encoding='utf-8'))
    except (OSError, ValueError):
        return None


def zapisz_json(sciezka, obj):
    Path(sciezka).write_text(json.dumps(obj, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')


def slug(tekst):
    t = tekst.lower().replace('ł', 'l')
    t = unicodedata.normalize('NFKD', t).encode('ascii', 'ignore').decode()
    return re.sub(r'[^a-z0-9]+', '-', t).strip('-') or 'bez-nazwy'


def foldery_pokoju(pokoj):
    f = pokoj.get('foldery')
    return f if isinstance(f, list) and f else DOMYSLNE_FOLDERY


def nastepna_kolejnosc():
    """Numer dla nowego pokoju: o jeden większy od największego używanego."""
    numery = [(wczytaj_json(p) or {}).get('kolejnosc', 0) for p in KORZEN.glob('*/pokoj.json') if not p.parent.name.startswith('_')]
    return max(numery + [0]) + 1

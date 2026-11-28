// Komponent „Wydarzenia” panelu MG. Jeden kod dla wielu zakładek: Wydarzenia (pełny),
// Giełda (z podglądem kursów), Holonet i Kanały (tylko wydarzenia z ich komunikatami).
//
//   panelWydarzen(kontener, {
//     tytul,            nagłówek karty
//     rodzaje,          rodzaje skutków do dodawania i filtr listy, np. ['kanal'] (domyślnie wszystkie)
//     podglad(szkic),   opcjonalnie: wywoływane przy zmianie formularza (np. wykres kursu)
//     poZapisie(),      opcjonalnie: odśwież swoją zakładkę
//   })
//
// Nowe rodzaje skutków: dopisz w RODZAJE (formularz i opis) i w api/wydarzenia.php.

import { h, wyczysc } from '../js/rdzen/narzedzia.js';
import { api } from '../js/rdzen/api.js';

const RODZAJE = {
  ceny: 'Ceny (giełda i handel)',
  holonet: 'Komunikat w Holonecie',
  kanal: 'Wiadomość w kanale szyfrowanym',
};

const msg = (t, rodzaj = 'blad') => h('div.msg.' + rodzaj, t);
const btn = (tekst, fn, klasa = '') => h('button.btn' + klasa, { type: 'button', onclick: fn }, tekst);
const pole = (etykieta, el) => h('label', etykieta, el);
const dzis = () => new Date().toISOString().slice(0, 10);

// Szablony: gotowe zestawy skutków. {p} = planeta, {k} = pierwszy kanał szyfrowany.
const SZABLONY = {
  blokada: {
    nazwa: 'Blokada planetarna: {p}', dni: 14,
    skutki: [
      { rodzaj_skutku: 'ceny', planeta: '{p}', rodzaj: 'towar', zmiana: 35, narastanie: 3, niepewnosc: 1.5 },
      { rodzaj_skutku: 'ceny', planeta: '{p}', rodzaj: 'spolka', zmiana: -25, narastanie: 5, niepewnosc: 1.5 },
      { rodzaj_skutku: 'holonet', kiedy: 'start', tytul: 'Flota imperialna blokuje {p}', zrodlo: 'Imperialne Biuro Informacji', tresc: 'Dowództwo sektora ogłosiło blokadę systemu {p}. Wszystkie jednostki cywilne mają zakaz startu i lądowania do odwołania.' },
      { rodzaj_skutku: 'kanal', kiedy: 'start', kanal: '{k}', tresc: 'Duże ptaki usiadły nad {p}. Gniazdo zamknięte, karma drożeje.' },
      { rodzaj_skutku: 'holonet', kiedy: 'koniec', tytul: 'Zniesiono blokadę {p}', zrodlo: 'Imperialne Biuro Informacji', tresc: 'Ruch cywilny w systemie {p} zostaje przywrócony.' },
    ],
  },
  zloza: {
    nazwa: 'Odkrycie złóż na {p}', dni: 30,
    skutki: [
      { rodzaj_skutku: 'ceny', planeta: '{p}', kategoria: 'surowce', zmiana: -20, narastanie: 10 },
      { rodzaj_skutku: 'ceny', rodzaj: 'spolka', kategoria: 'górnictwo', zmiana: 15, narastanie: 5 },
      { rodzaj_skutku: 'holonet', kiedy: 'start', tytul: 'Gorączka wydobycia na {p}', zrodlo: 'Serwis Gildii Górniczej', tresc: 'Geolodzy potwierdzili bogate złoża w systemie {p}. Spółki wydobywcze zapowiadają nowe koncesje.' },
    ],
  },
  paliwo: {
    nazwa: 'Kryzys paliwowy', dni: 21,
    skutki: [
      { rodzaj_skutku: 'ceny', kategoria: 'paliwo', zmiana: 50, narastanie: 7, niepewnosc: 2 },
      { rodzaj_skutku: 'ceny', rodzaj: 'spolka', kategoria: 'transport', zmiana: -15, narastanie: 7 },
      { rodzaj_skutku: 'holonet', kiedy: 'start', tytul: 'Ceny paliwa biją rekordy', zrodlo: 'Kurier Giełdowy CDEX', tresc: 'Przerwy w dostawach coaxium i tibanny windują ceny paliwa w całej galaktyce.' },
    ],
  },
  embargo: {
    nazwa: 'Embargo handlowe na {p}', dni: 30,
    skutki: [
      { rodzaj_skutku: 'ceny', planeta: '{p}', zmiana: 20, narastanie: 4 },
      { rodzaj_skutku: 'ceny', planeta: '{p}', rodzaj: 'spolka', zmiana: -15, narastanie: 4 },
      { rodzaj_skutku: 'kanal', kiedy: 'start', kanal: '{k}', tresc: 'Na {p} drzwi zamknięte od frontu. Tylne wejście wciąż otwarte, za odpowiednią opłatą.' },
    ],
  },
  panika: {
    nazwa: 'Panika na rynkach', dni: 7,
    skutki: [
      { rodzaj_skutku: 'ceny', rodzaj: 'spolka', zmiana: -12, narastanie: 2, niepewnosc: 2.5 },
      { rodzaj_skutku: 'holonet', kiedy: 'start', tytul: 'Czarny dzień na giełdach', zrodlo: 'Kurier Giełdowy CDEX', tresc: 'Inwestorzy wyprzedają akcje po doniesieniach z frontu.' },
    ],
  },
  epidemia: {
    nazwa: 'Epidemia na {p}', dni: 21,
    skutki: [
      { rodzaj_skutku: 'ceny', kategoria: 'medycyna', zmiana: 60, narastanie: 4, niepewnosc: 1.5 },
      { rodzaj_skutku: 'ceny', planeta: '{p}', kategoria: 'żywność', zmiana: 25, narastanie: 6 },
      { rodzaj_skutku: 'holonet', kiedy: 'start', tytul: 'Kwarantanna na {p}', zrodlo: 'Imperialna Służba Medyczna', tresc: 'Władze sanitarne wprowadziły kwarantannę w systemie {p}. Zapasy bacty są reglamentowane.' },
    ],
  },
  hossa: {
    nazwa: 'Hossa w stoczniach', dni: 30,
    skutki: [{ rodzaj_skutku: 'ceny', rodzaj: 'spolka', kategoria: 'stocznie', zmiana: 30, narastanie: 14 }],
  },
};
const NAZWY_SZABLONOW = { '': 'Puste wydarzenie', blokada: 'Blokada planetarna', zloza: 'Odkrycie złóż', paliwo: 'Kryzys paliwowy', embargo: 'Embargo handlowe', panika: 'Panika na rynkach', epidemia: 'Epidemia', hossa: 'Hossa w stoczniach' };

function zSzablonu(klucz, planeta, kanal) {
  const s = SZABLONY[klucz];
  const od = dzis();
  const koniec = new Date(Date.now() + (s?.dni || 7) * 86400000).toISOString().slice(0, 10);
  if (!s) return { nazwa: '', opis: '', od, do: '', wlaczone: true, skutki: [] };
  const wstaw = (v) => (typeof v === 'string' ? v.replaceAll('{p}', planeta || '???').replaceAll('{k}', kanal || '') : v);
  return {
    nazwa: wstaw(s.nazwa), opis: '', od, do: koniec, wlaczone: true,
    skutki: s.skutki.filter((x) => x.rodzaj_skutku !== 'kanal' || kanal).map((x) => Object.fromEntries(Object.entries(x).map(([k, v]) => [k, wstaw(v)]))),
  };
}

function opisSkutku(s, kanaly) {
  if (s.rodzaj_skutku === 'ceny') {
    const zakres = [s.planeta, { towar: 'towary', spolka: 'spółki' }[s.rodzaj], s.kategoria, s.cel].filter(Boolean).join(', ') || 'wszystko';
    return `Ceny ${s.zmiana > 0 ? '+' : ''}${s.zmiana}% (${zakres})${s.narastanie ? `, w ${s.narastanie} dni` : ''}${s.niepewnosc && s.niepewnosc !== 1 ? `, wahania ×${s.niepewnosc}` : ''}`;
  }
  const kiedy = s.kiedy === 'koniec' ? 'na koniec' : 'na start';
  if (s.rodzaj_skutku === 'holonet') return `Holonet ${kiedy}: „${s.tytul}”`;
  return `Kanał ${kanaly.find((k) => k.id === s.kanal)?.nazwa || '?'} ${kiedy}`;
}

export async function panelWydarzen(kontener, opcje = {}) {
  const rodzaje = opcje.rodzaje || Object.keys(RODZAJE);
  const karta = h('div.karta');
  kontener.append(karta);
  let dane;

  async function odswiez(info = null) {
    dane = await api('mg_wydarzenia');
    rysujListe(info);
  }

  function rysujListe(info = null) {
    const lista = dane.wydarzenia.filter((w) => !opcje.rodzaje || w.skutki.some((s) => rodzaje.includes(s.rodzaj_skutku)));
    const szablon = h('select', Object.entries(NAZWY_SZABLONOW).map(([k, n]) => h('option', { value: k }, n)));
    const planeta = h('input', { list: 'wyd-planety', placeholder: 'Planeta (dla szablonu)' });
    wyczysc(karta).append(
      h('h2', opcje.tytul || 'Wydarzenia'),
      info,
      h('p.slaby', 'Wydarzenie działa od daty rozpoczęcia do zakończenia (puste = bez końca). Bez wydarzeń kursy i ceny wahają się zwyczajnie. '
        + 'Komunikaty wysyłają się same w dniu rozpoczęcia (albo dzień po zakończeniu) i tylko raz.'),
      h('datalist#wyd-planety', dane.planety.map((p) => h('option', { value: p }))),
      h('div.rzad', { style: 'margin-bottom:12px' }, h('div', { style: 'min-width:200px' }, szablon), h('div', { style: 'min-width:200px' }, planeta),
        btn('Nowe wydarzenie', () => edytuj(zSzablonu(szablon.value, planeta.value.trim(), dane.kanaly[0]?.id)), '.glowny')),
      lista.length
        ? h('div.przewijana', h('table',
          h('tr', h('th', 'Wydarzenie'), h('th', 'Termin'), h('th', 'Stan'), h('th', 'Skutki'), h('th', '')),
          lista.map((w) => h('tr',
            h('td', h('b', w.nazwa), w.opis ? h('div.slaby', w.opis) : null),
            h('td', `${w.od} – ${w.do || 'bez końca'}`),
            h('td', w.stan),
            h('td', w.skutki.map((s) => h('div.slaby', opisSkutku(s, dane.kanaly)))),
            h('td.rzad',
              btn('Edytuj', () => edytuj(w)),
              btn(w.wlaczone ? 'Wyłącz' : 'Włącz', async () => {
                await zapisz({ ...w, wlaczone: !w.wlaczone });
              }),
              btn('Usuń', async () => {
                if (!confirm(`Usunąć wydarzenie „${w.nazwa}”? Wysłane komunikaty zostaną.`)) return;
                await api('mg_wydarzenie_usun', { id: w.id });
                await odswiez(msg('Usunięto wydarzenie.', 'ok'));
                opcje.poZapisie?.();
              }, '.zly'))))))
        : h('p.slaby', 'Brak wydarzeń.'));
  }

  async function zapisz(w) {
    try {
      await api('mg_wydarzenie_zapisz', { wydarzenie: w });
      await odswiez(msg(`Zapisano: ${w.nazwa}.`, 'ok'));
      opcje.poZapisie?.();
      return true;
    } catch (e) {
      karta.prepend(msg(e.message));
      return false;
    }
  }

  function edytuj(wyd) {
    const w = structuredClone(wyd);
    const f = {
      nazwa: h('input', { value: w.nazwa || '' }),
      opis: h('input', { value: w.opis || '', placeholder: 'Notatka MG, gracze jej nie widzą' }),
      od: h('input', { type: 'date', value: w.od || dzis() }),
      do: h('input', { type: 'date', value: w.do || '' }),
      wlaczone: h('input', { type: 'checkbox', checked: w.wlaczone !== false, style: 'width:auto' }),
    };
    const skutkiEl = h('div');
    const info = h('div');

    const szkic = () => ({ ...w, nazwa: f.nazwa.value.trim() || 'Szkic', opis: f.opis.value, od: f.od.value, do: f.do.value, wlaczone: f.wlaczone.checked });
    const zmiana = () => opcje.podglad?.(szkic());

    function poleSkutku(s, klucz, el) {
      el.value = s[klucz] ?? '';
      el.addEventListener('input', () => { s[klucz] = el.type === 'number' ? Number(el.value) : el.value; zmiana(); });
      el.addEventListener('change', () => { s[klucz] = el.type === 'number' ? Number(el.value) : el.value; zmiana(); });
      return el;
    }

    function rysujSkutki() {
      wyczysc(skutkiEl).append(...w.skutki.map((s, i) => {
        const usun = btn('Usuń skutek', () => { w.skutki.splice(i, 1); rysujSkutki(); zmiana(); }, '.zly');
        const kiedy = () => poleSkutku(s, 'kiedy', h('select', h('option', { value: 'start' }, 'na start'), h('option', { value: 'koniec' }, 'dzień po zakończeniu')));
        let pola;
        if (s.rodzaj_skutku === 'ceny') {
          s.zmiana ??= 0; s.narastanie ??= 0; s.niepewnosc ??= 1;
          pola = h('div.siatka',
            pole('Planeta (puste = wszędzie)', poleSkutku(s, 'planeta', h('input', { list: 'wyd-planety' }))),
            pole('Rodzaj', poleSkutku(s, 'rodzaj', h('select', h('option', { value: '' }, 'towary i spółki'), h('option', { value: 'towar' }, 'tylko towary'), h('option', { value: 'spolka' }, 'tylko spółki')))),
            pole('Kategoria', poleSkutku(s, 'kategoria', h('input', { list: 'wyd-kategorie' }))),
            pole('Konkretny instrument / towar', poleSkutku(s, 'cel', h('input', { list: 'wyd-cele' }))),
            pole('Zmiana ceny (%)', poleSkutku(s, 'zmiana', h('input', { type: 'number', step: 1 }))),
            pole('Narastanie (dni)', poleSkutku(s, 'narastanie', h('input', { type: 'number', min: 0, step: 1 }))),
            pole('Mnożnik wahań', poleSkutku(s, 'niepewnosc', h('input', { type: 'number', min: 0.2, max: 5, step: 0.1 }))));
        } else if (s.rodzaj_skutku === 'holonet') {
          pola = h('div', h('div.siatka', pole('Kiedy', kiedy()), pole('Tytuł', poleSkutku(s, 'tytul', h('input'))), pole('Źródło', poleSkutku(s, 'zrodlo', h('input')))),
            pole('Treść', poleSkutku(s, 'tresc', h('textarea', { style: 'min-height:80px;font-family:inherit' }))));
        } else {
          pola = h('div', h('div.siatka', pole('Kiedy', kiedy()),
            pole('Kanał', poleSkutku(s, 'kanal', h('select', h('option', { value: '' }, '— wybierz —'), dane.kanaly.map((k) => h('option', { value: k.id }, k.nazwa)))))),
          pole('Wiadomość (szyfr słowny, odszyfrują ją pliki ze słownikiem)', poleSkutku(s, 'tresc', h('textarea', { style: 'min-height:70px;font-family:inherit' }))));
        }
        return h('div', { style: 'border:1px solid var(--linia);border-radius:6px;padding:12px;margin-bottom:10px' },
          h('div.rzad', { style: 'margin-bottom:8px' }, h('b', { style: 'flex:1' }, RODZAJE[s.rodzaj_skutku]), usun), pola);
      }));
    }

    for (const k of ['nazwa', 'opis', 'od', 'do']) f[k].addEventListener('input', zmiana);
    f.wlaczone.addEventListener('change', zmiana);
    rysujSkutki();
    wyczysc(karta).append(
      h('h2', w.id ? `Edycja: ${w.nazwa}` : 'Nowe wydarzenie'),
      info,
      h('datalist#wyd-planety', dane.planety.map((p) => h('option', { value: p }))),
      h('datalist#wyd-kategorie', dane.kategorie.map((p) => h('option', { value: p }))),
      h('datalist#wyd-cele', dane.cele.map((p) => h('option', { value: p }))),
      h('div.siatka', pole('Nazwa', f.nazwa), pole('Notatka MG', f.opis), pole('Od', f.od), pole('Do (puste = bez końca)', f.do),
        h('label', { style: 'display:flex;gap:8px;align-items:center' }, f.wlaczone, 'Włączone')),
      skutkiEl,
      h('div.rzad', { style: 'margin-bottom:14px' }, rodzaje.map((r) => btn('+ ' + RODZAJE[r], () => {
        w.skutki.push(r === 'ceny' ? { rodzaj_skutku: 'ceny', zmiana: 10, narastanie: 3, niepewnosc: 1 } : { rodzaj_skutku: r, kiedy: 'start', kanal: dane.kanaly[0]?.id || '' });
        rysujSkutki(); zmiana();
      }))),
      h('div.rzad',
        btn('Zapisz wydarzenie', async () => { if (await zapisz(szkic())) opcje.podglad?.(null); }, '.glowny'),
        btn('Anuluj', () => { rysujListe(); opcje.podglad?.(null); })));
    zmiana();
  }

  try {
    await odswiez();
  } catch (e) {
    wyczysc(karta).append(msg(e.message));
  }
}

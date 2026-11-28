// Giełda CDEX: notowania spółek i towarów, wykresy, kupno i sprzedaż (rozliczane w Banku),
// portfel postaci i historia transakcji. Kursy liczy serwer raz na dobę (api/gielda.php).

import { h, wyczysc } from '../rdzen/narzedzia.js';
import { api } from '../rdzen/api.js';

const SVG = 'http://www.w3.org/2000/svg';
const liczba = (n, m = 2) => Number(n).toLocaleString('pl-PL', { minimumFractionDigits: m, maximumFractionDigits: m });
const kr = (n) => `${Number(n).toLocaleString('pl-PL')} kr`;
const kurs = (n) => `${liczba(n, n >= 1000 ? 0 : 2)} kr`;
const proc = (a, b) => (b ? (a / b - 1) * 100 : 0);
const RODZAJE = { spolka: 'Spółki', towar: 'Towary' };

function zmiana(teraz, przed) {
  const p = proc(teraz, przed);
  return h('span.zmiana' + (p > 0.005 ? '.plus' : p < -0.005 ? '.minus' : ''), `${p > 0 ? '+' : ''}${liczba(p)}%`);
}

const data = (od, i) => {
  const d = new Date(od + 'T12:00:00');
  d.setDate(d.getDate() + i);
  return d;
};
const dzien = (d) => `${String(d.getDate()).padStart(2, '0')}.${String(d.getMonth() + 1).padStart(2, '0')}`;

// Wykres liniowy w SVG. Kolor: zielony, gdy kurs wzrósł w całym okresie, czerwony, gdy spadł.
function wykres(ceny, { wys = 160, maly = false } = {}) {
  const svg = document.createElementNS(SVG, 'svg');
  const W = 300, H = 100;
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
  svg.setAttribute('preserveAspectRatio', 'none');
  svg.classList.add(maly ? 'wykres-maly' : 'wykres');
  if (!maly) svg.style.height = wys + 'px';
  if (ceny.length < 2) return svg;
  const min = Math.min(...ceny), max = Math.max(...ceny);
  const zakres = max - min || 1;
  const pkt = ceny.map((c, i) => [i / (ceny.length - 1) * W, H - 4 - (c - min) / zakres * (H - 8)]);
  const linia = pkt.map((p) => p.map((v) => v.toFixed(1)).join(',')).join(' ');
  const kierunek = ceny[ceny.length - 1] >= ceny[0] ? 'plus' : 'minus';
  if (!maly) {
    const tlo = document.createElementNS(SVG, 'polygon');
    tlo.setAttribute('points', `0,${H} ${linia} ${W},${H}`);
    tlo.setAttribute('class', 'wykres-pole ' + kierunek);
    svg.append(tlo);
  }
  const pl = document.createElementNS(SVG, 'polyline');
  pl.setAttribute('points', linia);
  pl.setAttribute('class', 'wykres-linia ' + kierunek);
  pl.setAttribute('vector-effect', 'non-scaling-stroke');
  svg.append(pl);
  return svg;
}

export default {
  id: 'gielda',
  nazwa: 'Giełda',
  ikona: '<path d="M6 40h36"/><path d="M10 32l9-10 7 6 12-15"/><path d="M31 13h7v7"/>',

  uruchom(w, ctx) {
    let d = null;
    let widok = 'notowania';
    let filtr = { gielda: '', rodzaj: '', kategoria: '', szukaj: '' };
    let wybrany = null;

    const komunikat = h('div');
    const tresc = h('div');
    const pokaz = (t, rodzaj = 'blad') => wyczysc(komunikat).append(h('div.komunikat.' + rodzaj, t));
    const glowa = h('div.app-glowa');
    w.append(glowa, komunikat, tresc);

    async function wczytaj() {
      try {
        d = await api('gielda');
      } catch (e) {
        pokaz(e.message);
        return false;
      }
      const dz = new Date(d.dzien + 'T12:00:00');
      wyczysc(glowa).append(h('h1', 'Giełda CDEX'), h('span.podtytul', `Kurs dnia ${dzien(dz)} · na rachunku ${kr(d.saldo)}`));
      return true;
    }

    const instrument = (sym) => d.instrumenty.find((i) => i.symbol === sym);
    const nazwaGieldy = (id) => d.gieldy.find((g) => g.id === id)?.nazwa || id;

    function zakladki() {
      const z = (k, t) => h('button' + (widok === k ? '.aktywna' : ''), { onclick: () => { widok = k; wybrany = null; rysuj(); } }, t);
      return h('div.zakladki', z('notowania', 'Notowania'), z('portfel', 'Portfel'), z('historia', 'Transakcje'));
    }

    // ---------- notowania ----------

    function notowania() {
      const kategorie = [...new Set(d.instrumenty.filter((i) => i.notowana !== false && (!filtr.rodzaj || i.rodzaj === filtr.rodzaj)).map((i) => i.kategoria))].sort();
      const lista = h('div.gielda-lista');
      const szukaj = h('input', { placeholder: 'Symbol lub nazwa', value: filtr.szukaj, spellcheck: 'false' });
      const rysujListe = () => {
        const q = filtr.szukaj.trim().toLowerCase();
        const widoczne = d.instrumenty.filter((i) => i.notowana !== false && (!filtr.gielda || i.gielda === filtr.gielda) && (!filtr.rodzaj || i.rodzaj === filtr.rodzaj)
          && (!filtr.kategoria || i.kategoria === filtr.kategoria) && (!q || i.symbol.toLowerCase().includes(q) || i.nazwa.toLowerCase().includes(q)));
        wyczysc(lista).append(...(widoczne.length ? widoczne.map((i) => h('button.gielda-wiersz', { onclick: () => { wybrany = i.symbol; rysuj(); } },
          h('span.gielda-symbol', i.symbol),
          h('span.gielda-nazwa', h('b', i.nazwa), h('span.slaby.maly-tekst', `${i.kategoria} · ${nazwaGieldy(i.gielda)}`)),
          wykres(i.seria, { maly: true }),
          h('span.gielda-kurs', h('b', kurs(i.kurs)), h('span.slaby.maly-tekst', `za ${i.jednostka}`)),
          zmiana(i.kurs, i.poprzedni))) : [h('div.slaby', 'Brak instrumentów spełniających kryteria.')]));
      };
      szukaj.addEventListener('input', () => { filtr.szukaj = szukaj.value; rysujListe(); });
      const wybor = (opcje, klucz, wszystkie) => {
        const s = h('select', h('option', { value: '' }, wszystkie), opcje.map(([v, t]) => h('option', { value: v, selected: filtr[klucz] === v }, t)));
        s.addEventListener('change', () => { filtr[klucz] = s.value; if (klucz === 'rodzaj') filtr.kategoria = ''; rysuj(); });
        return s;
      };
      rysujListe();
      return h('div',
        h('div.gielda-filtry',
          wybor(d.gieldy.map((g) => [g.id, g.nazwa]), 'gielda', 'Wszystkie giełdy'),
          wybor(Object.entries(RODZAJE), 'rodzaj', 'Spółki i towary'),
          wybor(kategorie.map((k) => [k, k]), 'kategoria', 'Wszystkie kategorie'),
          szukaj),
        lista);
    }

    // ---------- szczegóły instrumentu ----------

    function szczegoly(i) {
      const poz = d.portfel.pozycje[i.symbol];
      const miejsce = h('div.gielda-wykres', wykres(i.seria));
      const opis = h('div.gielda-statystyki');
      let zakres = 30;
      const przyciski = h('div.zakladki');
      const pokazZakres = async (dni) => {
        zakres = dni;
        rysujPrzyciski();
        try {
          const n = await api('gielda_notowania', undefined, { s: i.symbol, dni });
          wyczysc(miejsce).append(wykres(n.ceny, { wys: 200 }),
            h('div.wykres-os.slaby.maly-tekst', h('span', dzien(data(n.od, 0))), h('span', dzien(data(n.od, n.ceny.length - 1)))));
          const min = Math.min(...n.ceny), max = Math.max(...n.ceny), sr = n.ceny.reduce((a, b) => a + b, 0) / n.ceny.length;
          wyczysc(opis).append(
            h('div', h('span.slaby', 'Zmiana'), zmiana(n.ceny[n.ceny.length - 1], n.ceny[0])),
            h('div', h('span.slaby', 'Minimum'), h('b', kurs(min))),
            h('div', h('span.slaby', 'Maksimum'), h('b', kurs(max))),
            h('div', h('span.slaby', 'Średnia'), h('b', kurs(sr))));
        } catch (e) {
          pokaz(e.message);
        }
      };
      const rysujPrzyciski = () => wyczysc(przyciski).append(...[[30, '30 dni'], [90, '90 dni'], [365, 'Rok'], [730, '2 lata']].map(([dni, t]) =>
        h('button' + (zakres === dni ? '.aktywna' : ''), { onclick: () => pokazZakres(dni) }, t)));
      pokazZakres(30);

      // Zlecenie: ilość → podsumowanie → zatwierdzenie.
      const ilosc = h('input', { type: 'number', min: 1, step: 1, value: 1, inputmode: 'numeric' });
      const podsumowanie = h('div.slaby.maly-tekst');
      const akcje = h('div.rzad');
      const licz = () => {
        const n = Math.max(0, Math.floor(Number(ilosc.value) || 0));
        const wartosc = Math.round(i.kurs * n);
        const prowizja = n ? Math.max(1, Math.round(wartosc * d.prowizja)) : 0;
        return { n, wartosc, prowizja };
      };
      const odswiezPodsumowanie = () => {
        const { n, wartosc, prowizja } = licz();
        wyczysc(podsumowanie).append(`Wartość ${kr(wartosc)} · prowizja ${kr(prowizja)} · kupno: ${kr(wartosc + prowizja)}, sprzedaż: ${kr(Math.max(0, wartosc - prowizja))}`);
        if (i.notowana === false) {
          wyczysc(akcje).append(h('p.slaby', 'Ta spółka jest poza giełdą. Zachowane akcje pozostają widoczne w portfelu, ale nie można nimi handlować.'));
          return;
        }
        wyczysc(akcje).append(
          h('button.przycisk', { disabled: !n || wartosc + prowizja > d.saldo, onclick: () => zatwierdz('kup') }, 'Kup'),
          h('button.przycisk.drugi', { disabled: !n || !poz || n > poz.ilosc, onclick: () => zatwierdz('sprzedaj') }, 'Sprzedaj'));
      };
      ilosc.addEventListener('input', odswiezPodsumowanie);
      odswiezPodsumowanie();

      function zatwierdz(typ) {
        const { n, wartosc, prowizja } = licz();
        const suma = typ === 'kup' ? wartosc + prowizja : Math.max(0, wartosc - prowizja);
        wyczysc(akcje).append(
          h('div.rozpycha', h('b', `${typ === 'kup' ? 'Kupujesz' : 'Sprzedajesz'} ${n} × ${i.symbol} po ${kurs(i.kurs)}`),
            h('div.slaby.maly-tekst', typ === 'kup' ? `Z rachunku zejdzie ${kr(suma)}.` : `Na rachunek wpłynie ${kr(suma)}.`)),
          h('button.przycisk', {
            onclick: async (e) => {
              e.target.disabled = true;
              try {
                await api('gielda_transakcja', { symbol: i.symbol, typ, ilosc: n });
                await wczytaj();
                pokaz(`Zlecenie wykonane: ${typ === 'kup' ? 'kupno' : 'sprzedaż'} ${n} × ${i.symbol}.`, 'ok');
                rysuj();
              } catch (err) {
                pokaz(err.message);
                odswiezPodsumowanie();
              }
            },
          }, 'Zatwierdź'),
          h('button.przycisk.drugi', { onclick: odswiezPodsumowanie }, 'Anuluj'));
      }

      return h('div',
        h('div.panel',
          h('div.rzad', h('div.rozpycha', h('h2', { style: 'margin:0' }, `${i.nazwa}`),
            h('div.slaby.maly-tekst', `${i.symbol} · ${i.rodzaj === 'spolka' ? 'spółka' : 'towar'}${i.notowana === false ? ' · poza giełdą' : ` · ${i.kategoria} · ${nazwaGieldy(i.gielda)}`}${i.pochodzenie ? ' · ' + i.pochodzenie : ''}`)),
            h('div', { style: 'text-align:right' }, h('div.gielda-duzy-kurs', kurs(i.kurs)), h('div.maly-tekst', zmiana(i.kurs, i.poprzedni), h('span.slaby', ` za ${i.jednostka}`)))),
          przyciski, miejsce, opis),
        h('div.panel',
          h('h2', 'Zlecenie'),
          poz ? h('p.slaby.maly-tekst', `W portfelu: ${poz.ilosc} ${i.jednostka} · wartość ${kr(Math.round(poz.ilosc * i.kurs))}`) : null,
          h('div.pole', h('label', `Ilość (${i.jednostka})`), ilosc),
          podsumowanie, h('div', { style: 'margin-top:12px' }, akcje)));
    }

    // ---------- portfel i historia ----------

    function portfel() {
      const wpisy = Object.entries(d.portfel.pozycje);
      if (!wpisy.length) return h('div.panel', h('p.slaby', 'Portfel jest pusty. Wybierz instrument w Notowaniach, aby złożyć zlecenie.'));
      let suma = 0, koszt = 0;
      const wiersze = wpisy.map(([sym, p]) => {
        const i = instrument(sym);
        const wartosc = Math.round((i?.kurs || 0) * p.ilosc);
        suma += wartosc;
        koszt += p.koszt;
        return h('tr', { onclick: () => { if (i) { widok = 'notowania'; wybrany = sym; rysuj(); } }, style: 'cursor:pointer' },
          h('td', h('b', sym), h('div.slaby.maly-tekst', i?.nazwa || 'wycofany z obrotu')),
          h('td', `${p.ilosc}`), h('td', i ? kurs(i.kurs) : '—'), h('td', kr(wartosc)),
          h('td', zmiana(wartosc, p.koszt)));
      });
      return h('div.panel',
        h('div.rzad', h('h2.rozpycha', { style: 'margin:0' }, 'Portfel'), h('div', h('span.slaby', 'Wartość: '), h('b', kr(suma)), ' ', zmiana(suma, koszt))),
        h('div.przewijana', h('table.tabela', h('tr', h('th', 'Instrument'), h('th', 'Ilość'), h('th', 'Kurs'), h('th', 'Wartość'), h('th', 'Wynik')), wiersze)));
    }

    function historia() {
      const lista = d.portfel.historia;
      if (!lista.length) return h('div.panel', h('p.slaby', 'Brak transakcji.'));
      const czas = (iso) => { const t = new Date(iso); return `${dzien(t)} ${String(t.getHours()).padStart(2, '0')}:${String(t.getMinutes()).padStart(2, '0')}`; };
      return h('div.panel', h('div.przewijana', h('table.tabela',
        h('tr', h('th', 'Data'), h('th', 'Zlecenie'), h('th', 'Kurs'), h('th', 'Kwota')),
        lista.map((t) => h('tr', h('td', czas(t.data)), h('td', `${t.typ === 'kup' ? 'Kupno' : 'Sprzedaż'} ${t.ilosc} × ${t.symbol}`), h('td', kurs(t.kurs)),
          h('td', h('span.zmiana.' + (t.typ === 'kup' ? 'minus' : 'plus'), (t.typ === 'kup' ? '−' : '+') + kr(t.typ === 'kup' ? t.wartosc + t.prowizja : Math.max(0, t.wartosc - t.prowizja)))))))));
    }

    function rysuj() {
      if (!d) return;
      const i = wybrany && instrument(wybrany);
      wyczysc(tresc).append(zakladki(), i ? szczegoly(i) : { notowania, portfel, historia }[widok]());
    }

    ctx.ustawWstecz(() => {
      if (wybrany) { wybrany = null; wyczysc(komunikat); rysuj(); return true; }
      return false;
    });

    wczytaj().then((ok) => ok && rysuj());
  },
};

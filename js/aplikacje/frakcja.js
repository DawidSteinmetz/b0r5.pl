// Sieć frakcji: planety i instalacje, zasoby, kadry (drzewo dowodzenia).

import { h, wyczysc } from '../rdzen/narzedzia.js';
import { api } from '../rdzen/api.js';

export default {
  id: 'frakcja',
  nazwa: 'Sieć frakcji',
  ikona: '<path d="M24 6 40 14v12c0 9-7 15-16 18C15 41 8 35 8 26V14Z"/><path d="M18 24l4 4 8-8"/>',

  uruchom(w, ctx) {
    let dane = null;
    let zakladka = 'planety';
    const tresc = h('div');
    const zakladki = h('div.zakladki');

    async function wczytaj() {
      try {
        ({ dane } = await api('frakcja'));
        rysuj();
      } catch (e) {
        wyczysc(tresc).append(h('div.komunikat.blad', e.message));
      }
    }

    function rysuj() {
      wyczysc(zakladki).append(...[['planety', 'Planety'], ['zasoby', 'Zasoby'], ['kadry', 'Kadry']].map(([k, n]) =>
        h('button' + (k === zakladka ? '.aktywna' : ''), { onclick: () => { zakladka = k; rysuj(); } }, n)));
      if (!dane) return;
      wyczysc(tresc).append({ planety, zasoby, kadry }[zakladka]());
    }

    function planety() {
      if (!dane.planety.length) return h('div.pusto', 'Brak danych wywiadu o planetach.');
      return h('div.karty', dane.planety.map((p) => h('div.panel',
        h('h2', p.nazwa),
        p.opis ? h('p.slaby.maly-tekst', p.opis) : null,
        (p.instalacje || []).length
          ? h('ul.lista', p.instalacje.map((i) => h('li',
            h('div', i.nazwa),
            [i.typ, i.stan].filter(Boolean).length ? h('div.slaby.maly-tekst', [i.typ, i.stan].filter(Boolean).join(' · ')) : null)))
          : h('div.slaby.maly-tekst', 'Brak instalacji.'))));
    }

    function zasoby() {
      if (!dane.zasoby.length) return h('div.pusto', 'Magazyny puste.');
      const filtr = h('input', { placeholder: 'Szukaj...' });
      const cialo = h('tbody');
      const blad = h('div');
      const rysujTabele = () => {
        const f = filtr.value.trim().toLowerCase();
        wyczysc(cialo);
        dane.zasoby.forEach((z, i) => {
          if (f && !`${z.nazwa} ${z.miejsce || ''}`.toLowerCase().includes(f)) return;
          const ilosc = h('span', z.ilosc);
          const zmien = async (o) => {
            const nowa = Math.max(0, Number(z.ilosc) + o);
            try {
              await api('frakcja_zasob', { indeks: i, ilosc: nowa });
              z.ilosc = nowa;
              ilosc.textContent = nowa;
            } catch (e) {
              wyczysc(blad).append(h('div.komunikat.blad', e.message));
            }
          };
          cialo.append(h('tr',
            h('td', z.nazwa),
            h('td.slaby', z.miejsce || '—'),
            h('td', h('span.licznik',
              h('button', { onclick: () => zmien(-1), 'aria-label': 'Mniej' }, '−'),
              ilosc,
              h('button', { onclick: () => zmien(1), 'aria-label': 'Więcej' }, '+'),
              z.jednostka ? h('span.slaby.maly-tekst', z.jednostka) : null))));
        });
      };
      filtr.addEventListener('input', rysujTabele);
      rysujTabele();
      return h('div.panel',
        h('div.pole', filtr),
        h('table.tabela', h('thead', h('tr', h('th', 'Zasób'), h('th', 'Lokalizacja'), h('th', 'Stan'))), cialo),
        blad);
    }

    function kadry() {
      if (!dane.kadry.length) return h('div.pusto', 'Brak danych o personelu.');
      // Drzewo: każdy wpis może mieć „przelozony" = imię innej osoby z listy.
      const imiona = new Set(dane.kadry.map((k) => k.imie));
      const dzieci = (szef) => dane.kadry.filter((k) => (szef === null ? !k.przelozony || !imiona.has(k.przelozony) : k.przelozony === szef));
      const galaz = (szef, korzen = false) => {
        const lista = dzieci(szef);
        if (!lista.length) return null;
        return h('ul.drzewo' + (korzen ? '.korzen' : ''), lista.map((k) => h('li',
          h('div', h('span', k.imie), k.ranga ? h('span.poziom', '  ' + k.ranga) : null),
          k.funkcja ? h('div.slaby.maly-tekst', k.funkcja) : null,
          galaz(k.imie))));
      };
      return h('div.panel', galaz(null, true));
    }

    w.append(
      h('div.app-glowa', h('h1', 'Sieć frakcji'), h('span.podtytul', [ctx.uzytkownik.frakcja, ctx.uzytkownik.oddzial].filter(Boolean).join(' · '))),
      zakladki,
      tresc,
    );
    rysuj();
    wczytaj();
  },
};

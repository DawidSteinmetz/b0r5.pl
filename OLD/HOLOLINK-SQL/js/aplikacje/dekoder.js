// Dekoder: odbiór zaszyfrowanych transmisji po sygnaturze, katalogi, odczyt.

import { h, wyczysc, czekaj, szum } from '../rdzen/narzedzia.js';
import { api } from '../rdzen/api.js';

export default {
  id: 'dekoder',
  nazwa: 'Dekoder',
  ikona: '<rect x="10" y="20" width="28" height="20" rx="2"/><path d="M16 20v-6a8 8 0 0 1 16 0v6"/><path d="M24 28v5"/>',

  uruchom(w, ctx) {
    let dane = { foldery: [], pliki: [] };
    let folder = null;
    let przerwij = false;

    const sygnatura = h('input', { placeholder: 'np. XK-7741', autocapitalize: 'characters', spellcheck: false });
    const komunikat = h('div');
    const listaFolderow = h('div');
    const listaPlikow = h('ul.lista');

    const pokazKomunikat = (t, rodzaj = 'blad') => wyczysc(komunikat).append(h('div.komunikat.' + rodzaj, t));

    async function wczytaj() {
      try {
        dane = await api('dekoder');
        if (!folder || !dane.foldery.includes(folder)) folder = dane.foldery[0];
        rysuj();
      } catch (e) {
        pokazKomunikat(e.message);
      }
    }

    function rysuj() {
      wyczysc(listaFolderow);
      for (const f of dane.foldery) {
        const ile = dane.pliki.filter((p) => p.folder === f).length;
        listaFolderow.append(h('button.folder' + (f === folder ? '.aktywna' : ''),
          { onclick: () => { folder = f; rysuj(); } }, h('span', f), h('span.slaby', ile)));
      }
      listaFolderow.append(h('div.rzad', { style: 'margin-top:10px' },
        h('button.przycisk.drugi.maly', { onclick: nowyFolder }, '+ Katalog'),
        h('button.przycisk.drugi.maly', { onclick: zmienNazwe }, 'Zmień nazwę')));

      wyczysc(listaPlikow);
      const pliki = dane.pliki.filter((p) => p.folder === folder);
      if (!pliki.length) listaPlikow.append(h('li.pusto', 'Katalog jest pusty.'));
      for (const p of pliki) {
        listaPlikow.append(h('li', h('div.rzad',
          h('div.rozpycha', { role: 'button', onclick: () => otworz(p.sygnatura), style: 'cursor:pointer' },
            h('div', p.tytul), h('div.slaby.maly-tekst', p.sygnatura)),
          h('span.poziom', 'P' + p.poziom),
          przenoszenie(p))));
      }
    }

    function przenoszenie(p) {
      if (dane.foldery.length < 2) return null;
      const s = h('select', { 'aria-label': 'Przenieś do katalogu', style: 'min-height:36px;background:transparent;border:1px solid var(--linia);border-radius:4px;padding:0 6px' },
        dane.foldery.map((f) => h('option', { value: f, selected: f === p.folder }, f)));
      s.addEventListener('change', async () => {
        try {
          await api('dekoder_przenies', { sygnatura: p.sygnatura, folder: s.value });
          await wczytaj();
        } catch (e) { pokazKomunikat(e.message); }
      });
      return s;
    }

    // Prosty dialog w stylu systemu zamiast window.prompt (który wygląda jak przeglądarka).
    function zapytaj(pytanie, wartosc = '') {
      return new Promise((gotowe) => {
        const pole = h('input', { value: wartosc });
        const okno = h('div.panel', { style: 'margin-bottom:14px;border-color:var(--akcent)' },
          h('div.pole', h('label', pytanie), pole),
          h('div.rzad',
            h('button.przycisk.maly', { onclick: () => { okno.remove(); gotowe(pole.value.trim()); } }, 'Zatwierdź'),
            h('button.przycisk.drugi.maly', { onclick: () => { okno.remove(); gotowe(''); } }, 'Anuluj')));
        w.prepend(okno);
        pole.focus();
      });
    }

    async function nowyFolder() {
      const nazwa = await zapytaj('Nazwa nowego katalogu');
      if (!nazwa) return;
      try { await api('dekoder_folder', { nazwa }); folder = nazwa; await wczytaj(); } catch (e) { pokazKomunikat(e.message); }
    }

    async function zmienNazwe() {
      const nazwa = await zapytaj('Nowa nazwa katalogu', folder);
      if (!nazwa || nazwa === folder) return;
      try { await api('dekoder_folder', { nazwa, stara: folder }); folder = nazwa; await wczytaj(); } catch (e) { pokazKomunikat(e.message); }
    }

    async function pobierz(e) {
      e.preventDefault();
      const s = sygnatura.value.trim();
      if (!s) return;
      pokazKomunikat('Nawiązywanie połączenia...', 'ok');
      try {
        await czekaj(500);
        const odp = await api('dekoder_pobierz', { sygnatura: s });
        sygnatura.value = '';
        pokazKomunikat(`Odebrano transmisję ${odp.sygnatura}.`, 'ok');
        await wczytaj();
      } catch (err) {
        pokazKomunikat(err.message);
      }
    }

    async function otworz(syg) {
      przerwij = false;
      const tresc = h('div.dokument-tresc.szyfr', szum(400));
      const przycisk = h('button.przycisk', 'Dekoduj');
      const naglowek = h('div');
      ctx.ustawWstecz(() => { przerwij = true; glowna(); return true; });
      wyczysc(w).append(h('div.app-glowa', h('h1', 'Odczyt')), h('div.panel', naglowek, tresc, h('div.rzad', { style: 'margin-top:16px' }, przycisk)));
      let dok;
      try {
        ({ dokument: dok } = await api('dekoder_otworz', undefined, { s: syg }));
      } catch (e) {
        wyczysc(tresc).append(h('div.komunikat.blad', e.message));
        przycisk.remove();
        return;
      }
      naglowek.append(h('h2', dok.tytul), h('div.slaby.maly-tekst', { style: 'margin-bottom:12px' }, `${dok.sygnatura} · poziom ${dok.poziom}`));
      tresc.textContent = szum(Math.min(dok.tresc.length, 900));
      przycisk.addEventListener('click', async () => {
        przycisk.disabled = true;
        // Odszyfrowywanie: tekst „wyłania się" z szumu.
        const cel = dok.tresc;
        const kroki = 24;
        for (let k = 1; k <= kroki && !przerwij; k++) {
          const gotowe = Math.floor((cel.length * k) / kroki);
          tresc.textContent = cel.slice(0, gotowe) + szum(Math.min(cel.length - gotowe, 300));
          await czekaj(45);
        }
        tresc.textContent = cel;
        tresc.classList.remove('szyfr');
        przycisk.remove();
        // Plik deszyfrujący: słownik kodów (używają go kanały szyfrowane w Holonecie).
        if (dok.slownik?.length) {
          tresc.after(h('div', { style: 'margin-top:16px' },
            h('div.maly-tekst', { style: 'color:var(--zph);margin-bottom:6px' }, `Słownik deszyfrujący · ${dok.slownik.length} haseł`),
            h('table.tabela', dok.slownik.map((x) => h('tr', h('td', x.kody.join(' / ')), h('td', '→ ' + x.znaczenie))))));
        }
      });
    }

    function glowna() {
      ctx.ustawWstecz(null);
      wyczysc(w).append(
        h('div.app-glowa', h('h1', 'Dekoder'), h('span.podtytul', `Poziom uprawnień: ${ctx.uzytkownik.poziom}`)),
        h('form.panel', { onsubmit: pobierz },
          h('h2', 'Odbiór transmisji'),
          h('div.rzad',
            h('div.pole.rozpycha', { style: 'margin:0' }, h('label', 'Sygnatura dokumentu'), sygnatura),
            h('button.przycisk', { type: 'submit', style: 'align-self:flex-end' }, 'Pobierz')),
          komunikat),
        h('div.dwie-kolumny', { style: 'margin-top:14px' },
          h('div.panel', h('h2', 'Katalogi'), listaFolderow),
          h('div.panel', h('h2', 'Pliki'), listaPlikow)),
      );
      rysuj();
    }

    glowna();
    wczytaj();
    return () => { przerwij = true; };
  },
};

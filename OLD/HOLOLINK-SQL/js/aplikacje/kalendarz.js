// Kalendarz sesji: przeniesiony z dawnego b0r5.pl/calendar, w wyglądzie datapada.
//
// Zasady kalendarza (bez zmian względem oryginału):
// - miesiąc gry = miesiąc realny, rok gry = rok realny − 2010 (2026 → 16);
// - tydzień ma 5 dni; miesiąc ma tyle pełnych tygodni, ile się mieści (30 albo 25 dni),
//   a pozostałe dni realnego miesiąca to dni świąteczne „H”, numerowane narastająco w roku;
// - daty zapisujemy jako dzień|miesiąc|rok, a święta jako H|numer|rok;
// - sześć kultur zmienia nazwy miesięcy, dni tygodnia i święta (oraz kolor akcentu).

import { h, wyczysc, iso, pamiec, dataGry, normDni, dimMiesiaca as dim, rokGST } from '../rdzen/narzedzia.js';
import { api } from '../rdzen/api.js';
import { KULTURY } from '../rdzen/kultury.js';

const STATUSY = { dostepny: ['Dostępny', 'ok'], niepewny: ['Niepewny', 'warn'], nieobecny: ['Nieobecny', 'bad'] };

const pad = (n) => String(n).padStart(2, '0');
const rokGry = (y) => rokGST(new Date(y, 0, 1));

export default {
  id: 'kalendarz',
  nazwa: 'Kalendarz',
  ikona: '<rect x="8" y="12" width="32" height="28" rx="2"/><path d="M8 20h32M16 8v8M32 8v8M15 27h4M22 27h4M29 27h4M15 33h4M22 33h4"/>',

  uruchom(w, ctx) {
    const dzis = new Date();
    let kur = new Date(dzis.getFullYear(), dzis.getMonth(), 1);
    let wpisy = [];
    let kulturaId = pamiec.czytaj('kultura', 'imperial_coruscant');
    if (!KULTURY[kulturaId]) kulturaId = 'imperial_coruscant';
    const K = () => KULTURY[kulturaId];
    const u = ctx.uzytkownik;

    const korzen = h('div.kal');
    const toast = h('div.kal-toast');
    w.append(korzen, toast);

    function powiadom(tekst, blad = false) {
      toast.textContent = tekst;
      toast.className = 'kal-toast widoczny' + (blad ? ' blad' : '');
      setTimeout(() => { toast.className = 'kal-toast'; }, 2000);
    }

    async function wczytaj() {
      try {
        ({ wpisy } = await api('harmonogram'));
        rysuj();
      } catch (e) {
        powiadom(e.message, true);
      }
    }

    async function zapisz(dane) {
      if (!dane.postac.trim() || !dane.data) { powiadom('Brak danych', true); return false; }
      try {
        await api('harmonogram_dodaj', dane);
        await wczytaj();
        powiadom('Zapisano');
        return true;
      } catch (e) {
        powiadom(e.message, true);
        return false;
      }
    }

    async function usun(wpis) {
      try {
        await api('harmonogram_usun', { id: wpis.id });
        await wczytaj();
        powiadom('Usunięto wpis');
      } catch (e) {
        powiadom(e.message, true);
      }
    }

    const pigulki = (ds) => wpisy.filter((e) => e.data === ds).map((e) => h('div.kal-pill.' + STATUSY[e.status][1], e.postac));
    const wyborStatusu = (wartosc = 'dostepny') => h('select', Object.entries(STATUSY).map(([k, [n]]) => h('option', { value: k, selected: k === wartosc }, n)));
    const pole = (etykieta, el) => h('div.pole', h('label', etykieta), el);

    // Okno zgłoszenia po dotknięciu dnia.
    function okno(ds) {
      const postac = h('input', { maxlength: 40, value: u.postac });
      const status = wyborStatusu();
      const notatka = h('textarea', { maxlength: 120 });
      const zamknij = () => tlo.remove();
      const tlo = h('div.kal-modal-tlo', { onclick: (e) => { if (e.target === tlo) zamknij(); } },
        h('div.kal-modal.panel',
          h('button.kal-modal-x', { onclick: zamknij, 'aria-label': 'Zamknij' }, '✕'),
          h('h2', 'Zgłoś dostępność'),
          h('div.kal-modal-data', dataGry(ds)),
          pole('Postać / gracz', postac), pole('Status', status), pole('Notatka', notatka),
          h('button.przycisk', { style: 'width:100%', onclick: async () => { if (await zapisz({ data: ds, postac: postac.value, status: status.value, notatka: notatka.value })) zamknij(); } }, '► Zgłoś')));
      korzen.append(tlo);
    }

    function eksport() {
      const s = [...wpisy].sort((a, b) => a.data.localeCompare(b.data));
      const wiersze = [['Data w grze', 'Data sesji', 'Postać', 'Status', 'Uwagi'], ...s.map((e) => [dataGry(e.data), e.data, e.postac, STATUSY[e.status][0], e.notatka || ''])];
      const tekst = '﻿' + wiersze.map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(';')).join('\r\n');
      const a = h('a', { href: URL.createObjectURL(new Blob([tekst], { type: 'text/csv' })), download: 'kalendarz-sesji.csv' });
      a.click();
      powiadom('Wyeksportowano arkusz');
    }

    function rysuj() {
      const k = K();
      const y = kur.getFullYear(), m = kur.getMonth(), mon = m + 1, ig = rokGry(y);
      const razem = dim(y, m), norm = normDni(y, m), dzisIso = iso(new Date());
      korzen.style.setProperty('--akcent', k.akcent);
      korzen.style.setProperty('--akcent-2', k.akcent2);

      // ----- lewa kolumna -----
      const fPostac = h('input', { maxlength: 40, value: u.postac });
      const fData = h('input', { type: 'date', value: dzisIso });
      const fStatus = wyborStatusu();
      const fNotatka = h('textarea', { maxlength: 120 });
      const lewa = h('div.kal-lewa',
        h('div.panel',
          h('h2', '// Status systemu'),
          h('div.kal-tel', h('span', 'Rok GST'), h('b.ok', ig)),
          h('div.kal-tel', h('span', 'Kultura'), h('b', k.nazwa)),
          h('div.kal-tel', h('span', 'Wpisy'), h('b.ok', wpisy.length))),
        h('div.panel',
          h('h2', '// Dodaj wpis'),
          pole('Postać / gracz', fPostac), pole('Data sesji', fData), pole('Status', fStatus), pole('Notatka', fNotatka),
          h('div.rzad',
            h('button.przycisk', { onclick: () => zapisz({ data: fData.value, postac: fPostac.value, status: fStatus.value, notatka: fNotatka.value }) }, '► Zapisz'),
            h('button.przycisk.drugi', { onclick: wczytaj }, '↺ Odśwież'))),
        h('div.panel',
          h('h2', '// Ostatnie wpisy'),
          wpisy.length
            ? h('div.kal-lista', [...wpisy].reverse().map((e) => h('div.kal-wpis',
              h('div.kal-wpis-nazwa', e.postac),
              h('div.kal-wpis-meta', dataGry(e.data), ' • ', h('span.' + STATUSY[e.status][1], STATUSY[e.status][0])),
              (e.uid === u.id || u.rola === 'mg') ? h('button.kal-usun', { onclick: () => usun(e) }, 'Usuń') : null)))
            : h('div.slaby.maly-tekst', 'Brak wpisów')));

      // ----- kalendarz -----
      const siatka = h('div.kal-siatka',
        h('div.kal-naglowki', k.dni.map((n) => h('div', n))));
      for (let rzad = 0; rzad < norm / 5; rzad++) {
        const el = h('div.kal-rzad');
        for (let kol = 1; kol <= 5; kol++) {
          const dzien = rzad * 5 + kol;
          const ds = `${y}-${pad(mon)}-${pad(dzien)}`;
          el.append(h('button.kal-komorka' + (ds === dzisIso ? '.dzis' : ''), { onclick: () => okno(ds) },
            h('div.kal-numer', `${dzien}|${mon}|${ig}`),
            h('div.kal-dzien', k.dni[kol - 1]),
            h('div.kal-pille', pigulki(ds))));
        }
        siatka.append(el);
      }
      for (let dzien = norm + 1; dzien <= razem; dzien++) {
        const ds = `${y}-${pad(mon)}-${pad(dzien)}`;
        siatka.append(h('button.kal-swieto' + (ds === dzisIso ? '.dzis' : ''), { onclick: () => okno(ds) },
          h('div.kal-h', 'H'),
          h('div.rozpycha', h('div.kal-swieto-data', dataGry(ds)), h('div.kal-swieto-nazwa', k.swieto)),
          h('div.kal-pille.poziomo', pigulki(ds))));
      }

      // ----- rejestr -----
      const posortowane = [...wpisy].sort((a, b) => a.data.localeCompare(b.data));
      const rejestr = h('div.panel',
        h('div.rzad', h('h2.rozpycha', { style: 'margin:0' }, '// Rejestr zgłoszeń'), h('span.slaby.maly-tekst', `${posortowane.length} wpisów`)),
        h('table.tabela', { style: 'margin-top:8px' },
          h('thead', h('tr', h('th', 'Data'), h('th', 'Postać'), h('th', 'Status'), h('th', 'Uwagi'))),
          h('tbody', posortowane.map((e) => h('tr',
            h('td.kal-akcent', dataGry(e.data)), h('td', h('b', e.postac)),
            h('td.' + STATUSY[e.status][1], STATUSY[e.status][0]), h('td.slaby', e.notatka || '—'))))));

      const prawa = h('div.kal-prawa',
        h('div.kal-glowa',
          h('div',
            h('div.kal-tytul', `${k.miesiace[m]} // M.${mon} // Rok ${ig}`),
            h('div.slaby.maly-tekst', `Kultura: ${k.nazwa}`)),
          h('div.rzad',
            h('button.przycisk.drugi.maly', { onclick: () => { kur = new Date(y, m - 1, 1); rysuj(); }, 'aria-label': 'Poprzedni miesiąc' }, '←'),
            h('div.kal-etykieta', `${k.miesiace[m]} // M.${mon}`),
            h('button.przycisk.drugi.maly', { onclick: () => { kur = new Date(y, m + 1, 1); rysuj(); }, 'aria-label': 'Następny miesiąc' }, '→'),
            h('button.przycisk.drugi.maly', { onclick: () => { const d = new Date(); kur = new Date(d.getFullYear(), d.getMonth(), 1); rysuj(); } }, 'Dziś'),
            h('button.przycisk.maly', { onclick: eksport }, '⇩ Arkusz'))),
        siatka,
        rejestr);

      // ----- pasek kultur -----
      const kultury = h('div.zakladki', Object.entries(KULTURY).map(([id, x]) =>
        h('button' + (id === kulturaId ? '.aktywna' : ''), { onclick: () => { kulturaId = id; pamiec.zapisz('kultura', id); rysuj(); } }, x.nazwa)));

      const modal = korzen.querySelector('.kal-modal-tlo');
      wyczysc(korzen).append(
        h('div.app-glowa', h('h1', 'Kalendarz sesji'), h('span.podtytul', 'Harmonogram')),
        kultury,
        h('div.kal-uklad', lewa, prawa),
        modal);
    }

    rysuj();
    wczytaj();
  },
};

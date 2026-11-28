// Firma: lista firm, w których postać ma udziały lub pracuje, i panel firmy.
// Wygląd panelu zależy od profilu firmy; każdy profil to osobny plik w ./firma/.

import { h, wyczysc } from '../rdzen/narzedzia.js';
import { api } from '../rdzen/api.js';
import transport from './firma/transport.js';
import komorka from './firma/komorka.js';
import agencja from './firma/agencja.js';
import wydobycie from './firma/wydobycie.js';
import produkcja from './firma/produkcja.js';
import finanse from './firma/finanse.js';

const PROFILE = { transport, komorka, agencja, wydobycie, produkcja, finanse };

const ROLE = { wlasciciel: 'Właściciel', menedzer: 'Menedżer', pracownik: 'Pracownik' };

export default {
  id: 'firma',
  nazwa: 'Firma',
  ikona: '<path d="M8 40V16l12-6v8l12-6v8l8-4v24Z"/><path d="M14 30h4M22 30h4M30 30h4M14 36h4M22 36h4M30 36h4"/>',

  uruchom(w, ctx) {
    let sprzataj = null;

    async function lista() {
      sprzataj?.();
      sprzataj = null;
      ctx.ustawWstecz(null);
      wyczysc(w).append(h('div.app-glowa', h('h1', 'Firma')), h('div.pusto', 'Łączenie z rejestrem...'));
      let firmy;
      try {
        ({ firmy } = await api('firmy'));
      } catch (e) {
        wyczysc(w).append(h('div.app-glowa', h('h1', 'Firma')), h('div.komunikat.blad', e.message));
        return;
      }
      if (firmy.length === 1) return otworz(firmy[0].id, true);
      wyczysc(w).append(
        h('div.app-glowa', h('h1', 'Firma'), h('span.podtytul', 'Rejestr podmiotów')),
        firmy.length
          ? h('div.karty', firmy.map((f) => h('button.panel.ikona-aplikacji', { style: 'align-items:flex-start;text-align:left', onclick: () => otworz(f.id) },
            h('h2', { style: 'margin:0' }, f.nazwa),
            h('span.slaby.maly-tekst', `${PROFILE[f.profil]?.nazwa || f.profil} · ${ROLE[f.rola]}`))))
          : h('div.panel', h('div.pusto', 'Nie figurujesz w żadnym rejestrze firm.')),
      );
    }

    async function otworz(id, jedyna = false) {
      sprzataj?.();
      ctx.ustawWstecz(jedyna ? null : () => { lista(); return true; });
      const kontener = h('div');
      wyczysc(w).append(kontener);
      const wczytaj = async () => {
        const d = await api('firma', undefined, { id });
        return { ...d, moze: d.rola === 'wlasciciel' || d.rola === 'menedzer' };
      };
      let dane;
      try {
        dane = await wczytaj();
      } catch (e) {
        wyczysc(w).append(h('div.komunikat.blad', e.message));
        return;
      }
      const profil = PROFILE[dane.firma.profil];
      if (!profil) {
        wyczysc(w).append(h('div.komunikat.blad', 'Oprogramowanie tej firmy nie jest zgodne z urządzeniem.'));
        return;
      }
      wyczysc(kontener).append(h('div.app-glowa',
        h('h1', dane.firma.nazwa),
        h('span.podtytul', `${profil.nazwa} · ${ROLE[dane.rola]}`)));
      const obszar = h('div');
      kontener.append(obszar);
      sprzataj = profil.uruchom(obszar, {
        ...dane,
        wczytaj,
        // Operacja na firmie: { op, ... } → API; profil sam odświeża swój widok.
        operacja: (op, dane2 = {}) => api('firma_operacja', { firma: id, op, ...dane2 }),
        sprawdzSzlak: (szlak) => api('firma_sprawdz_szlak', { firma: id, szlak }),
        ustawWstecz: ctx.ustawWstecz,
        wrocDoListy: jedyna ? null : () => { lista(); return true; },
      }) || null;
    }

    lista();
    return () => sprzataj?.();
  },
};

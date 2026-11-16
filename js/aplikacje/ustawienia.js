// Ustawienia: alfabet (moduł ZPH), jasność, informacje o urządzeniu, blokada.

import { h, wyczysc } from '../rdzen/narzedzia.js';
import { URZADZENIE } from '../rdzen/urzadzenie.js';
import { api } from '../rdzen/api.js';

export default {
  id: 'ustawienia',
  nazwa: 'Ustawienia',
  ikona: '<circle cx="24" cy="24" r="6"/><path d="M24 6v6M24 36v6M6 24h6M36 24h6M11 11l4 4M33 33l4 4M11 37l4-4M33 15l4-4"/>',

  uruchom(w, ctx) {
    const u = ctx.uzytkownik;
    const wybor = h('div.przelacznik-duzy');

    const rysujWybor = () => {
      wyczysc(wybor).append(
        h('button.zawsze-aur' + (!ctx.alfabet.lacinski ? '.aktywna' : ''), { onclick: () => { ctx.alfabet.ustaw(false); setTimeout(rysujWybor, 200); } }, 'Aurebesh'),
        h('button.zawsze-lat' + (ctx.alfabet.lacinski ? '.aktywna' : ''), { onclick: () => { ctx.alfabet.ustaw(true); setTimeout(rysujWybor, 200); } }, 'Łaciński'),
      );
    };

    const jasnosc = h('input', { type: 'range', min: 30, max: 100, value: ctx.jasnosc() });
    jasnosc.addEventListener('input', () => ctx.jasnosc(Number(jasnosc.value)));

    const wiersz = (k, v) => h('tr', h('th', k), h('td', v));

    // Wtyczki zewnętrzne: paczka instalowana kluczem. Zainstalowana dodaje kanał w Holonecie.
    const wtyczki = h('div');
    const klucz = h('input', { placeholder: 'XXX-00-XXXXX', autocapitalize: 'characters', spellcheck: false, autocomplete: 'off' });
    const wynik = h('div');
    const rysujWtyczki = async () => {
      try {
        const { wtyczki: lista } = await api('wtyczki');
        wyczysc(wtyczki).append(lista.length
          ? h('ul.lista', lista.map((x) => h('li', h('div.rzad', h('span.rozpycha', x.nazwa), h('span.maly-tekst', { style: 'color:var(--zph)' }, 'aktywna'),
            h('button.przycisk.zly.maly', { onclick: async () => { await api('wtyczka_usun', { id: x.id }); rysujWtyczki(); } }, 'Odinstaluj')))))
          : h('div.slaby.maly-tekst', 'Brak zainstalowanych wtyczek.'));
      } catch (e) {
        wyczysc(wtyczki).append(h('div.komunikat.blad', e.message));
      }
    };
    const instaluj = async (e) => {
      e.preventDefault();
      wyczysc(wynik).append(h('div.komunikat.ok', 'Weryfikacja paczki...'));
      try {
        const d = await api('wtyczka_instaluj', { klucz: klucz.value });
        klucz.value = '';
        wyczysc(wynik).append(h('div.komunikat.ok', `Zainstalowano: ${d.nazwa}. Kanał dostępny w Holonecie.`));
        rysujWtyczki();
      } catch (err) {
        wyczysc(wynik).append(h('div.komunikat.blad', err.message));
      }
    };
    rysujWtyczki();

    w.append(
      h('div.app-glowa', h('h1', 'Ustawienia')),
      h('div.panel', { style: 'border-color: var(--zph)' },
        h('h2', { style: 'color: var(--zph)' }, 'Transliteracja · moduł ZPH'),
        h('p.slaby.maly-tekst', 'Moduł spoza listy zaufanego oprogramowania. Przepisuje wszystkie znaki systemu na alfabet łaciński.'),
        wybor),
      h('form.panel', { style: 'border-color: var(--zph)', onsubmit: instaluj },
        h('h2', { style: 'color: var(--zph)' }, 'Wtyczki zewnętrzne'),
        h('p.slaby.maly-tekst', 'Oprogramowanie spoza oficjalnej dystrybucji. Instalacja wymaga klucza od dostawcy.'),
        wtyczki,
        h('div.rzad', { style: 'margin-top:12px' }, h('div.pole.rozpycha', { style: 'margin:0' }, h('label', 'Klucz instalacyjny'), klucz),
          h('button.przycisk', { type: 'submit', style: 'align-self:flex-end' }, 'Zainstaluj')),
        wynik),
      h('div.panel',
        h('h2', 'Ekran'),
        h('div.pole', h('label', 'Jasność'), jasnosc)),
      h('div.panel',
        h('h2', 'Użytkownik'),
        h('table.tabela',
          wiersz('Postać', u.postac),
          wiersz('Przynależność', u.frakcja),
          u.oddzial ? wiersz('Oddział', u.oddzial) : null,
          u.ranga ? wiersz('Ranga', u.ranga) : null,
          wiersz('Poziom uprawnień', u.poziom)),
        h('div', { style: 'margin-top:14px' }, h('button.przycisk.zly', { onclick: () => ctx.zablokuj() }, 'Zablokuj urządzenie'))),
      h('div.panel',
        h('h2', 'O urządzeniu'),
        h('table.tabela',
          wiersz('Producent', URZADZENIE.producent),
          wiersz('Model', URZADZENIE.model),
          wiersz('System', `${URZADZENIE.system} ${URZADZENIE.wersja}`),
          wiersz('Numer seryjny', URZADZENIE.numerSeryjny()),
          wiersz('Podpis oprogramowania', h('span', { style: 'color: var(--zph)' }, 'NIEZGODNY')))),
    );
    rysujWybor();
  },
};

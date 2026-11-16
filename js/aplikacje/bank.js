// Bank: saldo, historia operacji, przelewy, stali odbiorcy.

import { h, wyczysc, czekaj } from '../rdzen/narzedzia.js';
import { api } from '../rdzen/api.js';

const kr = (n) => `${Number(n).toLocaleString('pl-PL')} kr`;

const czas = (iso) => {
  const d = new Date(iso);
  const p = (n) => String(n).padStart(2, '0');
  return `${p(d.getDate())}.${p(d.getMonth() + 1)} ${p(d.getHours())}:${p(d.getMinutes())}`;
};

// Pole numeru rachunku: same cyfry, myślniki dopisują się same (0000-0000-0000).
function poleNumeru(wartosc = '') {
  const el = h('input', { inputmode: 'numeric', placeholder: '0000-0000-0000', maxlength: 14, value: wartosc, autocomplete: 'off' });
  el.addEventListener('input', () => {
    const c = el.value.replace(/\D/g, '').slice(0, 12);
    el.value = c.replace(/(\d{4})(?=\d)/g, '$1-');
  });
  return el;
}

export default {
  id: 'bank',
  nazwa: 'Bank',
  ikona: '<rect x="6" y="14" width="36" height="24" rx="3"/><path d="M6 21h36M12 31h8M30 31h6"/>',

  uruchom(w, ctx) {
    let dane = null;

    async function wczytaj() {
      dane = await api('bank');
      return dane;
    }

    function glowka(tytul, podtytul = '') {
      return h('div.app-glowa', h('h1', tytul), podtytul ? h('span.podtytul', podtytul) : null);
    }

    // ---------- ekran główny ----------

    async function glowna(komunikat = null) {
      ctx.ustawWstecz(null);
      wyczysc(w).append(glowka('Bank'), h('div.pusto', 'Łączenie z bankiem...'));
      try {
        await wczytaj();
      } catch (e) {
        wyczysc(w).append(glowka('Bank'), h('div.komunikat.blad', e.message));
        return;
      }
      const { konto, transakcje } = dane;
      wyczysc(w).append(
        glowka('Bank', ctx.uzytkownik.postac),
        komunikat,
        h('div.panel.bank-karta',
          h('div.slaby.maly-tekst', 'Dostępne środki'),
          h('div.bank-saldo' + (konto.saldo < 0 ? '.ujemne' : ''), kr(konto.saldo)),
          h('div.slaby.maly-tekst', 'Rachunek ', h('span.bank-numer', konto.numer)),
          h('div.rzad', { style: 'margin-top:16px' },
            h('button.przycisk', { onclick: () => przelew() }, 'Nowy przelew'),
            h('button.przycisk.drugi', { onclick: () => odbiorcy() }, `Stali odbiorcy (${dane.odbiorcy.length})`))),
        h('div.panel',
          h('h2', 'Historia operacji'),
          transakcje.length
            ? h('ul.lista', transakcje.map(wierszHistorii))
            : h('div.pusto', 'Brak operacji na rachunku.')),
      );
    }

    function wierszHistorii(t) {
      const wplyw = t.kierunek === 'wplyw';
      const kto = wplyw ? t.od_nazwa : t.do_nazwa;
      return h('li', h('div.rzad',
        h('div.rozpycha',
          h('div', (wplyw ? 'Od: ' : 'Do: ') + kto),
          h('div.slaby.maly-tekst', [t.tytul, czas(t.czas)].filter(Boolean).join(' · '))),
        h('span' + (wplyw ? '.bank-plus' : '.bank-minus'), (wplyw ? '+' : '−') + kr(t.kwota))));
    }

    // ---------- przelew ----------

    function przelew(odbiorca = null) {
      ctx.ustawWstecz(() => { glowna(); return true; });
      const numer = poleNumeru(odbiorca?.numer || '');
      const kwota = h('input', { type: 'number', inputmode: 'numeric', min: 1, step: 1 });
      const tytul = h('input', { maxlength: 140 });
      const zapisz = h('input', { type: 'checkbox' });
      const kto = h('div.slaby.maly-tekst', { style: 'min-height:1.4em' });
      const info = h('div');
      let sprawdzony = null;

      // Po wpisaniu pełnego numeru bank pokazuje, do kogo należy rachunek.
      const sprawdz = async () => {
        sprawdzony = null;
        kto.textContent = '';
        if (numer.value.replace(/\D/g, '').length !== 12) return;
        kto.textContent = 'Weryfikacja rachunku...';
        try {
          const o = await api('bank_sprawdz', undefined, { n: numer.value });
          if (o.wlasny) { kto.textContent = 'To Twój własny rachunek.'; return; }
          sprawdzony = o;
          kto.textContent = 'Właściciel: ' + o.postac;
        } catch (e) {
          kto.textContent = e.message;
        }
      };
      numer.addEventListener('input', sprawdz);

      const lista = dane.odbiorcy.length
        ? h('div.rzad', { style: 'margin-bottom:14px' }, dane.odbiorcy.map((o) =>
          h('button.przycisk.drugi.maly', { type: 'button', onclick: () => { numer.value = o.numer; sprawdz(); } }, o.nazwa)))
        : null;

      const formularz = h('form.panel', {
        onsubmit: (e) => {
          e.preventDefault();
          const k = Math.floor(Number(kwota.value));
          if (!sprawdzony) return wyczysc(info).append(h('div.komunikat.blad', 'Podaj poprawny numer rachunku odbiorcy.'));
          if (!(k > 0)) return wyczysc(info).append(h('div.komunikat.blad', 'Podaj kwotę większą od zera.'));
          if (k > dane.konto.saldo) return wyczysc(info).append(h('div.komunikat.blad', 'Niewystarczające środki na rachunku.'));
          potwierdz({ numer: numer.value, postac: sprawdzony.postac, kwota: k, tytul: tytul.value.trim(), zapisz: zapisz.checked });
        },
      },
      h('h2', 'Nowy przelew'),
      lista,
      h('div.pole', h('label', 'Rachunek odbiorcy'), numer, kto),
      h('div.pole', h('label', `Kwota (dostępne: ${kr(dane.konto.saldo)})`), kwota),
      h('div.pole', h('label', 'Tytuł'), tytul),
      dane.odbiorcy.some((o) => o.numer === numer.value) ? null
        : h('label.rzad', { style: 'margin-bottom:14px;cursor:pointer' }, zapisz, h('span', 'Zapisz jako stałego odbiorcę')),
      info,
      h('button.przycisk', { type: 'submit' }, 'Dalej'));

      wyczysc(w).append(glowka('Przelew'), formularz);
      if (odbiorca) sprawdz();
    }

    function potwierdz(p) {
      ctx.ustawWstecz(() => { przelew({ numer: p.numer }); return true; });
      const info = h('div');
      const wyslij = h('button.przycisk', 'Autoryzuj przelew');
      const wiersz = (k, v) => h('tr', h('th', k), h('td', v));
      wyslij.addEventListener('click', async () => {
        wyslij.disabled = true;
        wyczysc(info).append(h('div.komunikat.ok', 'Autoryzacja...'));
        try {
          await czekaj(600);
          await api('bank_przelew', p);
          glowna(h('div.komunikat.ok', `Przelew ${kr(p.kwota)} do: ${p.postac} wykonany.`));
        } catch (e) {
          wyczysc(info).append(h('div.komunikat.blad', e.message));
          wyslij.disabled = false;
        }
      });
      wyczysc(w).append(glowka('Potwierdzenie'), h('div.panel',
        h('table.tabela',
          wiersz('Odbiorca', p.postac),
          wiersz('Rachunek', p.numer),
          wiersz('Kwota', kr(p.kwota)),
          wiersz('Tytuł', p.tytul || '—'),
          wiersz('Saldo po operacji', kr(dane.konto.saldo - p.kwota))),
        info,
        h('div.rzad', { style: 'margin-top:16px' }, wyslij,
          h('button.przycisk.drugi', { onclick: () => przelew({ numer: p.numer }) }, 'Popraw'))));
    }

    // ---------- stali odbiorcy ----------

    function odbiorcy() {
      ctx.ustawWstecz(() => { glowna(); return true; });
      const numer = poleNumeru();
      const nazwa = h('input', { maxlength: 60, placeholder: 'np. Mechanik z doków' });
      const info = h('div');

      const odswiez = async (komunikat) => {
        await wczytaj();
        odbiorcy();
        if (komunikat) w.querySelector('.bank-info')?.append(komunikat);
      };

      wyczysc(w).append(
        glowka('Stali odbiorcy'),
        h('div.bank-info'),
        h('div.panel',
          dane.odbiorcy.length
            ? h('ul.lista', dane.odbiorcy.map((o) => h('li', h('div.rzad',
              h('div.rozpycha', h('div', o.nazwa), h('div.slaby.maly-tekst.bank-numer', o.numer)),
              h('button.przycisk.maly', { onclick: () => przelew(o) }, 'Przelew'),
              h('button.przycisk.zly.maly', {
                'aria-label': 'Usuń',
                onclick: async () => {
                  try { await api('bank_odbiorca_usun', { numer: o.numer }); await odswiez(); } catch (e) { wyczysc(info).append(h('div.komunikat.blad', e.message)); }
                },
              }, '×')))))
            : h('div.pusto', 'Lista jest pusta.')),
        h('form.panel', {
          onsubmit: async (e) => {
            e.preventDefault();
            try {
              await api('bank_odbiorca_zapisz', { numer: numer.value, nazwa: nazwa.value });
              await odswiez(h('div.komunikat.ok', 'Odbiorca zapisany.'));
            } catch (err) {
              wyczysc(info).append(h('div.komunikat.blad', err.message));
            }
          },
        },
        h('h2', 'Dodaj odbiorcę'),
        h('div.pole', h('label', 'Rachunek'), numer),
        h('div.pole', h('label', 'Nazwa własna (opcjonalnie)'), nazwa),
        info,
        h('button.przycisk', { type: 'submit' }, 'Zapisz')),
      );
    }

    glowna();
  },
};

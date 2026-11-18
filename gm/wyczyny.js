// Zakładka „Wyczyny” panelu MG: postępy graczy w wyczynie „Fotograf gwiazd” i edycja progów.
// Logika po stronie serwera: api/gwiazdy.php.

import { h, wyczysc, czasGry } from '../js/rdzen/narzedzia.js';
import { api } from '../js/rdzen/api.js';

const msg = (t, rodzaj = 'blad') => h('div.msg.' + rodzaj, t);

export async function panelWyczynow(kontener) {
  const dane = await api('mg_wyczyny');
  const info = h('div');
  const progi = h('textarea', { style: 'min-height:160px;width:100%' },
    dane.progi.map((p) => `${p.procent} = ${p.nazwa}`).join('\n'));
  const zapisz = h('button.btn.glowny', { type: 'button', onclick: async () => {
    try {
      const lista = progi.value.split('\n').map((l) => l.split('=')).filter((x) => x.length >= 2)
        .map(([p, ...n]) => ({ procent: Number(p.replace(',', '.').trim()), nazwa: n.join('=').trim() }));
      await api('mg_wyczyny_progi_zapisz', { progi: lista });
      wyczysc(info).append(msg('Zapisano progi wyczynu.', 'ok'));
    } catch (e) { wyczysc(info).append(msg(e.message)); }
  } }, 'Zapisz progi');

  kontener.append(
    h('h2', 'Fotograf gwiazd'),
    h('p.slaby', 'Gracze robią zdjęcia słońc odwiedzonych układów (przycisk „Zdjęcie” na pulpicie, gdy słońce jest w kadrze). Każdy układ liczy się raz; postęp to procent układów widocznych dla konta.'),
    h('table',
      h('tr', h('th', 'Postać'), h('th', 'Zdjęcia'), h('th', 'Postęp'), h('th', 'Tytuł'), h('th', 'Ostatnie zdjęcie')),
      ...dane.gracze.map((g) => h('tr',
        h('td', g.postac), h('td', `${g.liczba} / ${g.wszystkie}`), h('td', `${g.procent}%`),
        h('td', g.prog?.nazwa || '—'), h('td', g.ostatnie ? czasGry(g.ostatnie) : '—')))),
    h('h2', 'Progi wyczynu'),
    h('p.slaby', 'Jedna linia = „procent = nazwa tytułu”, np. 25 = Wędrowiec galaktyki.'),
    progi, h('div.rzad', zapisz), info);
}

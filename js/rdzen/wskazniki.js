// Zgody kapitana na wskaźniki pokładowe. Goście na pokładzie (nawet wyżsi stopniem) widzą liczniki
// tylko za zgodą kapitana albo MG. Jeden komponent dla panelu flot na mapie, Nawigacji i panelu MG.
//
//   panelZgod({ filtr, tytul })  →  element (pusty, gdy nie ma gości na okrętach kapitana)
//   filtr(okret) — które okręty pokazać ({ klucz: 'okret:<id>' | 's:<firma>:<statek>', nazwa, goscie })

import { h, wyczysc } from './narzedzia.js';
import { api } from './api.js';

export function panelZgod({ filtr = () => true, tytul = 'Wskaźniki pokładowe' } = {}) {
  const el = h('div.wskazniki-zgody');
  const info = h('div.slaby.maly-tekst');
  api('wskazniki_poklad').then((d) => {
    const okrety = (d.okrety || []).filter(filtr);
    if (!okrety.length) return;
    el.append(h('div.slaby.maly-tekst', tytul),
      h('p.slaby.maly-tekst', 'Goście na pokładzie widzą liczniki (odliczanie, paliwo, reaktor, osłony, kadłub) tylko za zgodą kapitana.'));
    for (const o of okrety) {
      el.append(h('div.wskazniki-okret', h('b', o.nazwa),
        ...o.goscie.map((g) => h('label.wskazniki-gosc',
          h('input', { type: 'checkbox', checked: g.zgoda, onchange: async (e) => {
            const pole = e.target;
            try {
              await api('wskazniki_zgoda', { klucz: o.klucz, login: g.login, zgoda: pole.checked });
              wyczysc(info).append(pole.checked ? `${g.postac}: dostęp do wskaźników.` : `${g.postac}: bez dostępu.`);
            } catch (err) { pole.checked = !pole.checked; wyczysc(info).append(err.message); }
          } }),
          ` ${g.postac}`))));
    }
    el.append(info);
  }).catch(() => {});
  return el;
}

import transport from './transport.js';
import { kartaZakladow } from './zaklady.js';

export default {
  nazwa: 'Finanse i bankowość',
  uruchom(obszar, f) {
    return transport.uruchom(obszar, f, {
      kolejnosc: [['zaklady', 'Placówki'], ['budynki', 'Biura'], ['zaloga', 'Personel'], ['finanse', 'Rachunki']],
      zakladki: { zaklady: (p) => kartaZakladow(p, 'Placówki finansowe', 'Oddziały i zakres usług spółki.') },
      tekst: { zalogaPusta: 'Brak personelu placówek.', kandydaci: 'Kandydaci do pracy w finansach' },
    });
  },
};

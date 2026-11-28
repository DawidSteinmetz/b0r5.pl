import transport from './transport.js';
import { kartaZakladow } from './zaklady.js';

export default {
  nazwa: 'Produkcja i przemysł',
  uruchom(obszar, f) {
    return transport.uruchom(obszar, f, {
      kolejnosc: [['zaklady', 'Produkcja'], ['budynki', 'Zakłady'], ['magazyn', 'Magazyn'], ['zaloga', 'Personel'], ['finanse', 'Finanse']],
      zakladki: { zaklady: (p) => kartaZakladow(p, 'Zakłady produkcyjne', 'Placówki, wytwarzane towary i wydajność zakładów.') },
      tekst: { zalogaPusta: 'Brak personelu zakładów.', kandydaci: 'Kandydaci do pracy w produkcji' },
    });
  },
};

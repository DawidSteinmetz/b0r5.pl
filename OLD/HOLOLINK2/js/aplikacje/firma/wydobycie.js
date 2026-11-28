import transport from './transport.js';
import { kartaZakladow } from './zaklady.js';

export default {
  nazwa: 'Wydobycie',
  uruchom(obszar, f) {
    return transport.uruchom(obszar, f, {
      kolejnosc: [['zaklady', 'Wydobycie'], ['budynki', 'Kopalnie'], ['magazyn', 'Zasoby'], ['zaloga', 'Personel'], ['finanse', 'Finanse']],
      zakladki: { zaklady: (p) => kartaZakladow(p, 'Kopalnie i pola wydobywcze', 'Miejsca wydobycia, surowce i ich bieżąca wydajność.') },
      tekst: { zalogaPusta: 'Brak personelu wydobywczego.', kandydaci: 'Kandydaci do pracy w zakładach' },
    });
  },
};

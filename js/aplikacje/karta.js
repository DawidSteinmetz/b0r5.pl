// Karta postaci: tożsamość, pozycja, statystyki, reputacja, kontakty, ekwipunek, notatnik.

import { h, wyczysc } from '../rdzen/narzedzia.js';
import { api } from '../rdzen/api.js';
import { TYPY_PLANET } from '../rdzen/scena.js';

const ROLE = { wlasciciel: 'właściciel', menedzer: 'menedżer', pracownik: 'pracownik' };
const STATUS = {
  czysty: ['Brak wpisów', 'status-dostepny'],
  notowany: ['Notowany', 'status-niepewny'],
  poszukiwany: ['POSZUKIWANY', 'status-nieobecny'],
};
const SCENY = { kosmos: 'w przestrzeni', wejscie: 'skok w nadprzestrzeń', nadswietlna: 'w nadprzestrzeni', wyjscie: 'wyjście z nadprzestrzeni', orbita: 'na orbicie', powierzchnia: 'na powierzchni', start: 'start z planety' };

// Numer identyfikacyjny wyliczony z id konta — stały, „urzędowy” wygląd.
function numerId(uid) {
  let x = 0;
  for (const c of uid) x = (x * 31 + c.charCodeAt(0)) >>> 0;
  const hex = x.toString(16).toUpperCase().padStart(8, '0');
  return `${hex.slice(0, 4)}-${hex.slice(4)}-${uid.slice(-4).toUpperCase()}`;
}

function piki(wartosc, max) {
  const n = Math.max(max, wartosc);
  return h('span.piki', Array.from({ length: n }, (_, i) => h('i' + (i < wartosc ? '.pelny' : ''))));
}

function reputacjaOpis(v) {
  if (v <= -60) return ['wrogi', 'status-nieobecny'];
  if (v <= -20) return ['nieufny', 'status-niepewny'];
  if (v < 20) return ['neutralny', 'slaby'];
  if (v < 60) return ['przychylny', 'status-dostepny'];
  return ['sprzymierzony', 'status-dostepny'];
}

export default {
  id: 'karta',
  nazwa: 'Karta postaci',
  ikona: '<rect x="8" y="10" width="32" height="28" rx="3"/><circle cx="18" cy="21" r="4"/><path d="M12 32c1-4 4-6 6-6s5 2 6 6M28 18h8M28 24h8M28 30h5"/>',

  uruchom(w, ctx) {
    let d = null;
    let zakladka = 'tozsamosc';
    let licznik = null;
    const zakladki = h('div.zakladki');
    const tresc = h('div');
    const u = ctx.uzytkownik;

    w.append(h('div.app-glowa', h('h1', 'Karta postaci'), h('span.podtytul', u.postac)), zakladki, tresc);

    async function wczytaj() {
      try {
        d = await api('karta');
        rysuj();
      } catch (e) {
        wyczysc(tresc).append(h('div.komunikat.blad', e.message));
      }
    }

    function rysuj() {
      const NAZWY = [['tozsamosc', 'Tożsamość'], ['pozycja', 'Pozycja'], ['statystyki', 'Statystyki'], ['reputacja', 'Reputacja'],
        ['kontakty', 'Kontakty'], ['ekwipunek', 'Ekwipunek'], ['notatnik', 'Notatnik']];
      wyczysc(zakladki).append(...NAZWY.map(([k, n]) => h('button' + (k === zakladka ? '.aktywna' : ''), { onclick: () => { zakladka = k; rysuj(); } }, n)));
      if (!d) return;
      wyczysc(tresc).append({ tozsamosc, pozycja, statystyki, reputacja, kontakty, ekwipunek, notatnik }[zakladka]());
    }

    const wiersz = (k, v) => (v === '' || v === null || v === undefined ? null : h('tr', h('th', k), h('td', v)));

    // ---------- tożsamość ----------
    function tozsamosc() {
      const k = d.karta;
      const [stanOpis, stanKlasa] = STATUS[k.status.stan] || STATUS.czysty;
      return h('div',
        h('div.panel.karta-id',
          h('div.karta-portret', { 'aria-hidden': 'true' }, h('div.karta-glowa'), h('div.karta-tulow')),
          h('div.rozpycha',
            h('div.karta-imie', u.postac),
            h('div.slaby.maly-tekst', `ID ${numerId(u.id)}`),
            h('table.tabela', { style: 'margin-top:10px' },
              wiersz('Przynależność', u.frakcja),
              wiersz('Oddział', u.oddzial),
              wiersz('Ranga', u.ranga),
              wiersz('Poziom uprawnień', String(u.poziom)),
              wiersz('Środki', `${Number(d.saldo).toLocaleString('pl-PL')} kr na rachunku · ${Number(d.karta.gotowka || 0).toLocaleString('pl-PL')} kr gotówki`),
              d.firmy.length ? wiersz('Firmy', d.firmy.map((f) => `${f.nazwa} (${ROLE[f.rola]})`).join(', ')) : null))),
        h('div.panel' + (k.status.stan === 'poszukiwany' ? '.list-gonczy' : ''),
          h('h2', 'Status w rejestrach'),
          h('div', h('span.' + stanKlasa, { style: 'font-size:18px;letter-spacing:.12em' }, stanOpis)),
          k.status.stan !== 'czysty' ? h('table.tabela', { style: 'margin-top:8px' },
            wiersz('Zlecający', k.status.zlecajacy),
            k.status.nagroda ? wiersz('Nagroda', `${Number(k.status.nagroda).toLocaleString('pl-PL')} kr`) : null,
            wiersz('Uwagi', k.status.opis)) : null),
        h('div.panel', h('h2', 'Dokumenty i licencje'),
          k.dokumenty.length ? h('ul.lista', k.dokumenty.map((x) => h('li', h('div', x.nazwa), x.opis ? h('div.slaby.maly-tekst', x.opis) : null)))
            : h('div.slaby', 'Brak dokumentów w pamięci urządzenia.')));
    }

    // ---------- pozycja ----------
    function pozycja() {
      const p = d.karta.pozycja;
      const b = d.budynek;
      const sc = d.scena;
      const planetaTyp = TYPY_PLANET[sc.planeta]?.nazwa;
      return h('div',
        h('div.panel',
          h('h2', 'Aktualna pozycja'),
          h('table.tabela',
            wiersz('Planeta', d.planeta ? [d.planeta.nazwa, d.planeta.sektor].filter(Boolean).join(' · ') : (sc.miejsce || '—')),
            wiersz('Współrzędne', p.wspolrzedne || '—'),
            wiersz('Miejsce', p.miejsce || (b ? b.nazwa : '—')),
            wiersz('Ruch', [SCENY[sc.scena], sc.scena === 'orbita' && planetaTyp ? `planeta ${planetaTyp}` : '', sc.opis].filter(Boolean).join(' · ')))),
        b ? h('div.panel',
          h('h2', b.nazwa),
          h('div.slaby.maly-tekst', { style: 'margin-bottom:8px' }, [b.typ, b.firma].filter(Boolean).join(' · ')),
          h('table.tabela',
            wiersz('Planeta', b.planeta),
            wiersz('Współrzędne', b.wspolrzedne),
            b.powierzchnia ? wiersz('Powierzchnia', `${Number(b.powierzchnia).toLocaleString('pl-PL')} m²`) : null,
            b.pietra ? wiersz('Kondygnacje', `${b.pietra} nad poziomem gruntu`) : null,
            b.szyby ? wiersz('Poziomy podziemne', `${b.szyby} (szyby / podziemia)`) : null,
            b.pojemnosc ? wiersz('Obsada', `${b.pracownicy} / ${b.pojemnosc} pracowników`) : null),
          b.opis ? h('p.slaby.maly-tekst', b.opis) : null,
          przekroj(b)) : null);
    }

    // Schematyczny przekrój budynku: piętra nad ziemią, poziomy pod ziemią.
    function przekroj(b) {
      const gora = Math.min(b.pietra || 0, 12);
      const dol = Math.min(b.szyby || 0, 12);
      if (!gora && !dol) return null;
      return h('div.przekroj', { 'aria-hidden': 'true' },
        Array.from({ length: gora }, (_, i) => h('div.kondygnacja', `+${gora - i}`)),
        h('div.grunt'),
        Array.from({ length: dol }, (_, i) => h('div.kondygnacja.pod', `−${i + 1}`)),
        (b.pietra > 12 || b.szyby > 12) ? h('div.slaby.maly-tekst', 'Schemat skrócony.') : null);
    }

    // ---------- statystyki ----------
    function statystyki() {
      const s = d.karta.statystyki;
      const pusto = !Object.keys(s.atrybuty).length && !Object.keys(s.umiejetnosci).length && !Object.keys(s.liczniki).length;
      if (pusto) return h('div.panel', h('div.pusto', 'Brak danych biometrycznych. Zgłoś się do lekarza pokładowego.'));
      return h('div',
        Object.keys(s.liczniki).length ? h('div.panel', h('h2', 'Stan'),
          Object.entries(s.liczniki).map(([n, [ob, max]]) => h('div', { style: 'margin-bottom:10px' },
            h('div.rzad', h('span.rozpycha', n), h('span', `${ob} / ${max}`)),
            h('div.pasek-licznika', h('i', { style: `width:${Math.min(100, (ob / max) * 100)}%` }))))) : null,
        h('div.dwie-kolumny', { style: 'grid-template-columns:1fr 1fr;margin-top:14px' },
          h('div.panel', h('h2', 'Cechy'),
            Object.keys(s.atrybuty).length ? h('table.tabela', Object.entries(s.atrybuty).map(([n, v]) => h('tr', h('th', n), h('td', piki(v, 6), ` ${v}`))))
              : h('div.slaby', '—')),
          h('div.panel', h('h2', 'Umiejętności'),
            Object.keys(s.umiejetnosci).length ? h('table.tabela', Object.entries(s.umiejetnosci).map(([n, v]) => h('tr', h('th', n), h('td', piki(v, 5), ` ${v}`))))
              : h('div.slaby', '—'))));
    }

    // ---------- reputacja ----------
    function reputacja() {
      const r = Object.entries(d.karta.reputacja);
      if (!r.length) return h('div.panel', h('div.pusto', 'Nikt jeszcze o tobie nie słyszał.'));
      return h('div.panel', r.map(([org, v]) => {
        const [opis, klasa] = reputacjaOpis(v);
        return h('div', { style: 'margin-bottom:14px' },
          h('div.rzad', h('span.rozpycha', org), h('span.maly-tekst.' + klasa, opis)),
          h('div.reputacja', h('i', { style: v >= 0 ? `left:50%;width:${v / 2}%` : `left:${50 + v / 2}%;width:${-v / 2}%`, class: v >= 0 ? 'plus' : 'minus' })));
      }));
    }

    // ---------- kontakty i ekwipunek ----------
    const lista = (el, pusta) => (el.length
      ? h('div.panel', h('ul.lista', el.map((x) => h('li', h('div', x.nazwa), x.opis ? h('div.slaby.maly-tekst', x.opis) : null))))
      : h('div.panel', h('div.pusto', pusta)));
    const kontakty = () => lista(d.karta.kontakty, 'Brak zapisanych kontaktów.');
    const ekwipunek = () => lista(d.karta.ekwipunek, 'Brak zarejestrowanego ekwipunku.');

    // ---------- notatnik (zapis automatyczny) ----------
    function notatnik() {
      const pole = h('textarea', { style: 'min-height:50vh;width:100%;-webkit-user-select:text;user-select:text', placeholder: 'Prywatne notatki. Widzisz je tylko ty.' }, d.karta.notatki || '');
      const stanZapisu = h('span.slaby.maly-tekst', '');
      pole.addEventListener('input', () => {
        stanZapisu.textContent = 'Zapisywanie...';
        clearTimeout(licznik);
        licznik = setTimeout(async () => {
          try {
            await api('karta_notatki', { notatki: pole.value });
            d.karta.notatki = pole.value;
            stanZapisu.textContent = 'Zapisano w pamięci urządzenia.';
          } catch (e) {
            stanZapisu.textContent = e.message;
          }
        }, 900);
      });
      return h('div.panel', h('div.pole', pole), stanZapisu);
    }

    rysuj();
    wczytaj();
    return () => clearTimeout(licznik);
  },
};

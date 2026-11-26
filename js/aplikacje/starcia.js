// Starcia: skok agresora do układu z wrogimi okrętami zatrzymał czas w hiperprzestrzeni — strony dogadują tu termin
// walki (propozycja jednej strony, przyjęcie drugiej; MG ustala od razu). Obrońca może w tym czasie uciec z układu.
// Posiłki skaczące do układu wyskoczą o tyle po terminie, o ile miały po początku pauzy. api/starcia.php, MECHANIKA 13.

import { h, wyczysc } from '../rdzen/narzedzia.js';
import { api } from '../rdzen/api.js';

const STATUS = { negocjacja: 'czas stoi — ustalanie terminu', umowione: 'termin umówiony', bitwa: 'bitwa trwa', zakonczone: 'zakończone',
  obronca_uciekl: 'obrońca uciekł — czas wrócił do normy', anulowane: 'odwołane przez MG' };
const STRONA = { agresor: 'atakujący', obronca: 'obrońca', mg: 'MG' };

const dataGry = (t) => new Date(t * 1000).toLocaleString('pl-PL', { dateStyle: 'medium', timeStyle: 'short' });
const odliczanie = (s) => { s = Math.max(0, Math.round(s)); const d = Math.floor(s / 86400), g = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
  return d ? `${d} d ${g} h` : g ? `${g} h ${m} min` : `${m} min ${s % 60} s`; };

export default {
  id: 'starcia',
  nazwa: 'Starcia',
  ikona: '<path d="M8 40L28 20M20 8l20 20M12 36l-4 4M36 12l4-4"/><path d="M40 40L20 20M28 8L8 28M36 36l4 4M12 12l-4-4"/>',
  // Ikona dla MG i uczestników otwartych starć (stan.starcia ustawia pulpit po zapytaniu bitwa_dostep).
  dostep: (stan) => stan.uzytkownik?.rola === 'mg' || (stan.starcia || []).length > 0,

  uruchom(w, ctx) {
    const tresc = h('div');
    const komunikat = h('div');
    let zegar = null, koniec = false, przesuniecie = 0;
    const panel = (tytul, ...dzieci) => h('div.panel', h('h2', tytul), ...dzieci);
    const blad = (e) => h('div.komunikat.blad', e.message || String(e));
    const teraz = () => Date.now() / 1000 + przesuniecie;

    async function wyslij(akcja, dane) {
      try { await api(akcja, dane); wyczysc(komunikat).append(h('div.komunikat.ok', 'Zapisano.')); await odswiez(); }
      catch (e) { wyczysc(komunikat).append(blad(e)); }
    }

    function karta(s, mg) {
      const otwarte = ['negocjacja', 'umowione'].includes(s.status);
      const moja = s.moja_strona;
      const strony = h('table.tabela',
        h('tr', h('td.slaby', 'Atakuje'), h('td', `${s.agresor} · ${s.uczestnicy.filter((u) => u.strona === 'agresor').map((u) => u.postac).join(', ') || 'NPC / MG'}`)),
        h('tr', h('td.slaby', 'Broni się'), h('td', `${s.obronca || '—'} · ${s.uczestnicy.filter((u) => u.strona === 'obronca').map((u) => u.postac).join(', ') || 'NPC — termin ustala MG'}`)),
        h('tr', h('td.slaby', 'Stan'), h('td', STATUS[s.status] || s.status)),
        h('tr', h('td.slaby', 'Pauza od'), h('td', dataGry(s.pauza_od))),
        s.termin ? h('tr', h('td.slaby', 'Termin walki'), h('td', h('b', dataGry(s.termin)), s.status === 'umowione' ? ` · za ${odliczanie(s.termin - teraz())}` : '')) : null,
        h('tr', h('td.slaby', 'W hiperprzestrzeni'), h('td', `skoki atakujących: ${s.skoki?.agresor || 0}, obrońców (posiłki): ${s.skoki?.obronca || 0}`)));
      const propozycje = s.propozycje.length ? h('table.tabela', s.propozycje.map((p) => h('tr',
        h('td', `${dataGry(p.termin)}`), h('td.slaby', `${p.postac} (${STRONA[p.strona] || p.strona}) · ${p.status === 'otwarta' ? 'czeka' : p.status === 'przyjeta' ? 'przyjęta' : 'zastąpiona'}`),
        h('td', { style: 'text-align:right' }, otwarte && p.status === 'otwarta' && (mg || (moja && p.strona !== moja))
          ? h('button.przycisk.maly', { onclick: () => wyslij('starcie_akceptuj', { id: s.id, propozycja: p.id }) }, 'Przyjmij') : null)))) : null;
      const pole = h('input', { type: 'datetime-local', style: 'min-height:36px' });
      const domyslny = new Date((teraz() + 86400) * 1000);
      pole.value = new Date(domyslny.getTime() - domyslny.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
      const zaproponuj = otwarte && moja ? h('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px' }, pole,
        h('button.przycisk', { onclick: () => { const t = new Date(pole.value).getTime() / 1000; if (!t) return; wyslij('starcie_propozycja', { id: s.id, termin: Math.round(t) }); } },
          mg ? 'Ustal termin (MG)' : 'Zaproponuj termin')) : null;
      const mgPrzyciski = mg ? h('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;margin-top:8px' },
        otwarte ? h('button.przycisk.drugi', { onclick: () => confirm('Odwołać starcie? Czas wróci do normy, skoki skończą się po pauzie.') && wyslij('starcie_anuluj', { id: s.id }) }, 'Odwołaj starcie') : null,
        s.status === 'bitwa' ? h('button.przycisk.drugi', { onclick: () => confirm('Zakończyć bitwę?') && wyslij('starcie_zakoncz', { id: s.id }) }, 'Zakończ bitwę') : null) : null;
      const doBitwy = s.status === 'bitwa' && s.bitwa_id ? h('button.przycisk', { style: 'margin-top:8px', onclick: () => ctx.otworzApp?.('symulacje', { bitwa: s.bitwa_id }) }, 'Do bitwy') : null;
      return panel(s.nazwa.toUpperCase(),
        s.status === 'negocjacja' ? h('p.maly-tekst', 'Skok atakującego zatrzymał czas w hiperprzestrzeni. Ustalcie termin walki — wyjście ze skoku nastąpi w umówionym '
          + 'terminie. Obrońca może w tym czasie opuścić układ: starcie się skończy, a czas wróci do normy. Posiłki skaczące teraz do układu wyskoczą '
          + 'o tyle po terminie, o ile miały wyskoczyć po początku pauzy.') : null,
        strony, propozycje ? h('div.slaby.maly-tekst', { style: 'margin-top:8px' }, 'Propozycje terminu:') : null, propozycje, zaproponuj, doBitwy, mgPrzyciski);
    }

    async function odswiez() {
      if (koniec) return;
      try {
        const d = await api('starcia_lista');
        przesuniecie = (d.teraz || Date.now() / 1000) - Date.now() / 1000;
        wyczysc(tresc).append(komunikat,
          d.starcia.length ? h('div', d.starcia.map((s) => karta(s, d.mg)))
            : panel('STARCIA', h('p.maly-tekst', 'Brak starć. Starcie zaczyna się, gdy okręty skaczą do układu z wrogimi okrętami.')));
      } catch (e) { wyczysc(tresc).append(blad(e)); }
      clearTimeout(zegar);
      if (!koniec) zegar = setTimeout(odswiez, 15000);
    }

    w.append(tresc);
    odswiez();
    return () => { koniec = true; clearTimeout(zegar); };
  },
};

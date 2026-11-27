// Droid śledczy: aplikacja detektywa (konto wpisane w sledztwo_detektywi; MG nadaje rolę w zakładce Konta).
// Pierwsza wersja: bieżąca sprawa (zgłoszenie, zaczyn), położenie droida, dziennik, archiwum, przyjęcie nowej sprawy.
// Docelowo pulpit wg makiety „DT-4 – skan przestrzenny piętra” (narzedzia/sledztwa/PROJEKT.md, rozdz. 5).
// API: api/sledztwa.php — sledztwo_moje (tylko to, co detektyw może wiedzieć), sledztwo_nowe.

import { h, wyczysc } from '../rdzen/narzedzia.js';
import { api } from '../rdzen/api.js';

const STAN = {
  losowanie: 'Biuro przyjmuje zgłoszenie…',
  pisanie: 'Biuro kompletuje akta sprawy. Droid czeka na przydział.',
  trwa: 'Śledztwo w toku.',
  porzucone: 'Sprawa porzucona.',
  wznowienie_prosba: 'Prośba o wznowienie czeka na decyzję.',
  rozwiazane: 'Sprawa zamknięta.',
  blad: 'Akta sprawy uszkodzone — biuro je odtwarza.',
};
const STAN_DROIDA = { czeka: 'czeka na polecenia', w_drodze: 'w drodze', rozmowa: 'prowadzi rozmowę', skan: 'skanuje otoczenie',
  dekodowanie: 'dekoduje dane', uszkodzony: 'uszkodzony' };
const KTO = { droid: 'Droid', gracz: 'Ty', system: 'System' };

const data = (t) => (t ? new Date(String(t).replace(' ', 'T') + 'Z').toLocaleString('pl-PL', { dateStyle: 'short', timeStyle: 'short' }) : '');

export default {
  id: 'sledztwa',
  nazwa: 'Droid śledczy',
  ikona: '<circle cx="20" cy="20" r="11"/><path d="M28 28l12 12"/><path d="M15 17h10M15 23h6"/>',
  // Ikona tylko dla detektywów (stan.detektyw ustawia pulpit po zapytaniu sledztwo_moje).
  dostep: (stan) => !!stan.detektyw,

  uruchom(w) {
    const tresc = h('div');
    const komunikat = h('div');
    let zegar = null, koniec = false;
    const panel = (tytul, ...dzieci) => h('div.panel', h('h2', tytul), ...dzieci);
    const blad = (e) => h('div.komunikat.blad', e.message || String(e));
    const wiersz = (etykieta, wartosc) => h('tr', h('td.slaby', etykieta), h('td', wartosc ?? '—'));

    async function nowaSprawa(przycisk) {
      przycisk.disabled = true;
      try {
        await api('sledztwo_nowe', {});
        wyczysc(komunikat).append(h('div.komunikat.ok', 'Zgłoszenie przyjęte. Biuro kompletuje akta.'));
        await odswiez();
      } catch (e) { wyczysc(komunikat).append(blad(e)); przycisk.disabled = false; }
    }

    function sprawa(s) {
      return panel(`SPRAWA #${s.id}`,
        h('table.tabela',
          wiersz('Tytuł', s.tytul),
          wiersz('Zgłoszenie', s.zgloszenie),
          wiersz('Rejon', s.skupisko ? `${s.skupisko}, Nar Shaddaa` : 'Nar Shaddaa'),
          wiersz('Przyjęto', data(s.utworzono_utc)),
          wiersz('Stan', STAN[s.stan] || s.stan)),
        s.zaczyn ? h('div', { style: 'white-space:pre-wrap;margin-top:10px' }, s.zaczyn)
          : h('p.maly-tekst', 'Akta wstępne (zlecenie, tło, ludzie, od czego zacząć) pojawią się tutaj, gdy biuro je skompletuje.'));
    }

    function droid(oznaczenie, d) {
      if (!d) return panel(`DROID ${oznaczenie}`, h('p.maly-tekst', 'Droid czeka w biurze na przydział sprawy.'));
      const miejsce = [d.lokalizacja, d.poziom != null ? `poziom ${d.poziom}` : null, d.pokoj].filter(Boolean).join(' · ');
      const cel = d.stan === 'w_drodze'
        ? [d.cel, d.cel_poziom != null ? `poziom ${d.cel_poziom}` : null, d.cel_pokoj].filter(Boolean).join(' · ') + (d.dotrze_utc ? ` (dotrze ${data(d.dotrze_utc)})` : '')
        : null;
      return panel(`DROID ${oznaczenie}`, h('table.tabela',
        wiersz('Położenie', miejsce || 'nieznane'),
        wiersz('Stan', STAN_DROIDA[d.stan] || d.stan),
        cel ? wiersz('Cel', cel) : null),
        h('p.maly-tekst', 'Sterowanie droidem (skan piętra, ruch do celu, rozmowy) — w przygotowaniu.'));
    }

    function dziennik(wpisy) {
      return panel('DZIENNIK', wpisy.length
        ? h('div', wpisy.map((x) => h('div', { style: 'margin:6px 0' },
          h('span.slaby', `${data(x.kiedy_utc)} · ${KTO[x.kto] || x.kto} · `), h('span', { style: 'white-space:pre-wrap' }, x.tresc))))
        : h('p.maly-tekst', 'Brak wpisów.'));
    }

    function archiwum(lista) {
      if (!lista.length) return null;
      return panel('ARCHIWUM', h('table.tabela',
        h('tr', h('th', '#'), h('th', 'Sprawa'), h('th', 'Stan'), h('th', 'Ocena')),
        lista.map((s) => h('tr', h('td', s.id), h('td', s.tytul || s.zgloszenie), h('td', STAN[s.stan] || s.stan),
          h('td', s.ocena != null ? `${s.ocena}%` : '—')))));
    }

    async function odswiez() {
      if (koniec) return;
      try {
        const d = await api('sledztwo_moje');
        if (!d.detektyw) {
          wyczysc(tresc).append(panel('DROID ŚLEDCZY', h('p.maly-tekst', 'To konto nie ma przydzielonego droida śledczego.')));
        } else {
          const nowa = h('button.przycisk', 'Przyjmij nową sprawę');
          nowa.onclick = () => nowaSprawa(nowa);
          wyczysc(tresc).append(
            komunikat,
            panel(`BIURO DETEKTYWISTYCZNE · ${d.droid}`,
              h('p.maly-tekst', `Rozwiązane rodzaje spraw: ${d.rozwiazane} / ${d.wszystkie_typy}`),
              d.biezace ? null : h('p', 'Brak otwartej sprawy.'),
              d.biezace ? null : nowa),
            d.biezace ? sprawa(d.biezace) : null,
            d.biezace ? droid(d.droid, d.droid_stan) : null,
            d.biezace ? dziennik(d.dziennik || []) : null,
            archiwum(d.archiwum || []));
        }
      } catch (e) { wyczysc(tresc).append(blad(e)); }
      clearTimeout(zegar);
      if (!koniec) zegar = setTimeout(odswiez, 30000);
    }

    w.append(tresc);
    odswiez();
    return () => { koniec = true; clearTimeout(zegar); };
  },
};

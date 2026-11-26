// Mostek HOLOLINK: pełny widok dla dowódcy okrętu i pojedynczy pulpit dla przypisanej załogi.
import { h, wyczysc } from '../rdzen/narzedzia.js';
import { api } from '../rdzen/api.js';
import { konsola, KONSOLE, nowyStanKonsol, pulpityKlasy } from './konsole.js';

const IKONA = '<path d="M7 34h34M12 34V19l12-9 12 9v15M18 34V23h12v11M4 40h40"/><circle cx="24" cy="18" r="2"/>';

export default {
  id: 'mostek',
  nazwa: 'Mostek',
  ikona: IKONA,
  dostep: (stan) => !!stan.mostki?.length,

  uruchom(w, ctx) {
    const tresc = h('div');
    const panel = (tytul, ...dzieci) => h('div.panel', h('h2', tytul), ...dzieci);
    const blad = (e) => h('div.komunikat.blad', e.message || String(e));
    const lista = h('div');
    const trescMostka = h('div');
    const komunikat = h('div');
    let mostki = [];
    let aktywny = null;
    let stanOkretu = null;
    let sygnaturaStanu = '';
    let pulpitAktywny = null;
    let gracze = null;
    let timer = null;
    let wToku = false;
    let zamkniete = false;
    let kiosk = !!ctx.parametr?.stanowisko;
    const stanKonsol = nowyStanKonsol();

    const stacjaParam = typeof ctx.parametr?.stanowisko === 'string' ? ctx.parametr.stanowisko : null;
    if (kiosk) document.documentElement.classList.add('tryb-stanowiska');
    w.classList.toggle('widok-mostka-stanowisko', kiosk);
    if (kiosk) { tresc.classList.add('panel-stanowiska'); lista.hidden = true; }

    function wlaczKiosk() {
      if (kiosk) return;
      kiosk = true;
      document.documentElement.classList.add('tryb-stanowiska');
      w.classList.add('widok-mostka-stanowisko');
      tresc.classList.add('panel-stanowiska');
    }

    function ustawWstecz() {
      // Systemowy Wstecz zawsze zamyka aplikację i wraca na pulpit. Do listy mostków
      // służy osobny przycisk „← Mostki” w nagłówku panelu.
      ctx.ustawWstecz?.(() => false);
    }

    function pokazListe() {
      if (kiosk) return;
      clearTimeout(timer);
      aktywny = null;
      trescMostka.hidden = true;
      lista.hidden = false;
      wyczysc(tresc).append(lista);
      ustawWstecz();
    }

    async function otworzMostek(obiektId, stanowisko = null) {
      clearTimeout(timer);
      const dostep = mostki.find((x) => Number(x.obiekt_id) === Number(obiektId));
      if (!dostep) { wyczysc(komunikat).append(blad(new Error('Nie masz dostępu do tego mostka.'))); return; }
      aktywny = dostep;
      if (!dostep.pelny) wlaczKiosk();
      pulpitAktywny = stanowisko || stacjaParam || (!dostep.pelny ? dostep.pulpity[0] : null);
      stanOkretu = null;
      sygnaturaStanu = '';
      gracze = null;
      wyczysc(komunikat);
      lista.hidden = true;
      trescMostka.hidden = false;
      wyczysc(tresc).append(trescMostka);
      ustawWstecz();
      await odswiez();
    }

    function rysujListe() {
      const wiersze = mostki.map((o) => h('tr',
        h('td', h('b', o.nazwa), h('div.slaby.maly-tekst', o.profil)),
        h('td', o.pelny ? 'dowódca · wszystkie stanowiska' : `przydział: ${o.pulpity.join(', ')}`),
        h('td', { style: 'text-align:right' }, h('button.przycisk.drugi.maly', {
          onclick: () => otworzMostek(o.obiekt_id, o.pelny ? null : o.pulpity[0]),
        }, o.pelny ? 'Otwórz mostek' : 'Otwórz stanowisko'))));
      wyczysc(lista).append(panel('MOSTEK · HOLOLINK',
        h('p.maly-tekst', 'Pulpity okrętów, do których masz dostęp. Załoga widzi tylko przydzielone jej stanowisko.'),
        wiersze.length ? h('table.tabela', wiersze) : h('div.slaby.maly-tekst', 'Nie masz przydzielonego stanowiska.'), komunikat));
    }

    function pulpity(stan) {
      if (!stan.stanowiska?.pelny) return stan.stanowiska?.moje || [];
      const profile = [stan.stanowiska?.okret?.profil, aktywny?.profil, stan.jednostki?.[0]?.profil]
        .map((x) => String(x || '').trim()).filter(Boolean);
      let uklady = [];
      for (const profil of profile) {
        for (const klucz of [profil, profil.replace(/[\s-]+/g, '_').toUpperCase()]) {
          uklady = pulpityKlasy(klucz);
          if (uklady.length) break;
        }
        if (uklady.length) break;
      }
      return uklady.filter((p) => KONSOLE.includes(p) || !['Klucz', 'Eskadra'].includes(p));
    }

    async function wczytajZaloge() {
      try {
        gracze = (await api('mostek_gracze', undefined, { okret: aktywny.obiekt_id })).gracze;
        rysujMostek();
      } catch (e) { wyczysc(komunikat).append(blad(e)); }
    }

    async function ustawPrzydzial(nazwa, login) {
      try {
        const r = await api('mostek_stanowisko', { okret: aktywny.obiekt_id, pulpit: nazwa, login });
        const tekst = login ? `${r.postac || login}: przydzielono do pulpitu ${nazwa}.` : `Zablokowano dostęp do pulpitu ${nazwa}.`;
        wyczysc(komunikat).append(h('div.komunikat.ok', tekst));
        await odswiez();
      } catch (e) { wyczysc(komunikat).append(blad(e)); }
    }

    function rysujAdmin(st) {
      if (!(st.stanowiska?.pelny && (st.stanowiska?.mg || st.stanowiska?.kapitan))) return null;
      const przypisane = Object.fromEntries((st.stanowiska.przypisane || []).map((x) => [x.pulpit, x]));
      const wiersze = pulpity(stanOkretu).map((nazwa) => {
        const teraz = przypisane[nazwa];
        const wybor = gracze ? h('select', { style: 'min-height:32px;max-width:220px' },
          h('option', { value: '' }, '— wybierz załogę —'),
          gracze.map((g) => h('option', { value: g.login, selected: teraz?.login === g.login }, `${g.postac}${g.na_pokladzie ? '' : ' (poza okrętem)'}`))) : null;
        return h('tr', h('td', nazwa), h('td', teraz?.postac || h('span.slaby', 'brak przydziału')),
          h('td', { style: 'text-align:right' }, h('div', { style: 'display:flex;justify-content:flex-end;gap:5px;flex-wrap:wrap' },
            wybor,
            wybor ? h('button.przycisk.drugi.maly', { onclick: () => ustawPrzydzial(nazwa, wybor.value) }, 'Przydziel') : null,
            teraz ? h('button.przycisk.drugi.maly', { onclick: () => ustawPrzydzial(nazwa, '') }, 'Blokuj dostęp') : null)));
      });
      return panel(`ZAŁOGA · ${aktywny.nazwa}`,
        h('p.slaby.maly-tekst', 'Przydział daje graczowi tylko ten pulpit. „Blokuj dostęp” zwalnia stanowisko również w aktywnej symulacji.'),
        gracze ? null : h('button.przycisk.drugi', { style: 'margin-bottom:8px', onclick: wczytajZaloge }, 'Wczytaj załogę'),
        h('table.tabela', wiersze));
    }

    function rysujMostek() {
      if (!stanOkretu || !aktywny) return;
      const st = stanOkretu.stanowiska || {};
      const dozwolone = st.pelny ? pulpity(stanOkretu) : (st.moje || []);
      if (!pulpitAktywny || !dozwolone.includes(pulpitAktywny)) pulpitAktywny = dozwolone[0] || null;
      const pasek = st.pelny && !kiosk ? h('div.zakladki', dozwolone.map((n) => h('button' + (n === pulpitAktywny ? '.aktywna' : ''), {
        onclick: () => { pulpitAktywny = n; rysujMostek(); },
      }, n))) : null;
      const przywrocListe = st.pelny && !kiosk ? h('button.przycisk.drugi.maly', { onclick: pokazListe }, '← Mostki') : null;
      const akcje = {
        odswiez: () => rysujMostek(),
        rozkaz: () => {},
        komunikat: (tekst) => wyczysc(komunikat).append(h('div.komunikat', tekst)),
      };
      const ekran = pulpitAktywny ? konsola(pulpitAktywny, stanOkretu, stanKonsol, akcje)
        : h('div.komunikat', st.pelny ? `Brak układów konsol dla profilu ${st.okret?.profil || aktywny?.profil || 'nieznanego'}.`
          : 'To stanowisko nie jest już przydzielone.');
      const ramka = h('div.mostek-pulpit', ekran);
      const zawartosc = [
        kiosk ? null : panel('MOSTEK · ' + aktywny.nazwa, przywrocListe,
          st.pelny ? h('p.slaby.maly-tekst', 'Pełny dostęp dowódcy. Wybierz pulpit lub zarządzaj przydziałami załogi.') : null,
          rysujAdmin(st), pasek, komunikat),
        ramka,
      ];
      wyczysc(trescMostka).append(...zawartosc);
      if (kiosk) { w.classList.add('widok-mostka-stanowisko'); trescMostka.classList.add('panel-stanowiska-tresc'); }
    }

    async function odswiez() {
      if (!aktywny || wToku || zamkniete) return;
      wToku = true;
      try {
        const { stan } = await api('mostek_stan', undefined, { okret: aktywny.obiekt_id });
        const sygnatura = JSON.stringify(stan);
        if (sygnatura !== sygnaturaStanu) {
          sygnaturaStanu = sygnatura;
          stanOkretu = stan;
          rysujMostek();
        }
      } catch (e) {
        wyczysc(komunikat).append(blad(e));
        if (kiosk && (e.kod === 403 || e.kod === 404)) setTimeout(() => ctx.pulpit?.(), 1200);
      } finally {
        wToku = false;
        clearTimeout(timer);
        if (aktywny && !zamkniete) timer = setTimeout(odswiez, 5000);
      }
    }

    async function start() {
      try {
        const d = await api('bitwa_dostep');
        mostki = d.mostki || [];
        if (kiosk) {
          const wpis = mostki.find((x) => Number(x.obiekt_id) === Number(ctx.parametr?.okret)) || mostki.find((x) => !x.pelny && x.pulpity.includes(stacjaParam));
          if (wpis) await otworzMostek(wpis.obiekt_id, stacjaParam || wpis.pulpity[0]);
          else wyczysc(tresc).append(blad(new Error('To stanowisko nie jest już przydzielone.')));
          return;
        }
        const obiekt = ctx.parametr?.obiekt;
        const wpis = obiekt ? mostki.find((x) => Number(x.obiekt_id) === Number(obiekt)) : null;
        if (wpis) await otworzMostek(wpis.obiekt_id, wpis.pelny ? null : wpis.pulpity[0]);
        else { rysujListe(); wyczysc(tresc).append(lista); }
      } catch (e) { wyczysc(tresc).append(blad(e)); }
    }

    w.append(tresc);
    w.classList.toggle('widok-mostka-stanowisko', kiosk);
    start();
    return () => {
      zamkniete = true;
      clearTimeout(timer);
      if (kiosk) document.documentElement.classList.remove('tryb-stanowiska');
      w.classList.remove('widok-mostka-stanowisko');
    };
  },
};

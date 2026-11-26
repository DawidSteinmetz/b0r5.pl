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

    // Załoga na pokładzie (kapitan widzi tylko obecnych na okręcie, MG — wszystkich); wybór w listach przetrwa
    // przerysowanie mostka (stan okrętu odświeża się co kilka sekund).
    let wczytujeZaloge = false;
    const wybory = {};
    async function wczytajZaloge() {
      if (wczytujeZaloge) return;
      wczytujeZaloge = true;
      try {
        gracze = (await api('mostek_gracze', undefined, { okret: aktywny.obiekt_id })).gracze;
        rysujMostek();
      } catch (e) { wyczysc(komunikat).append(blad(e)); }
      wczytujeZaloge = false;
    }

    async function ustawPrzydzial(nazwa, login, cicho = false) {
      try {
        const r = await api('mostek_stanowisko', { okret: aktywny.obiekt_id, pulpit: nazwa, login });
        if (!cicho) {
          const tekst = login ? `${r.postac || login}: przydzielono do pulpitu ${nazwa}${r.teleport ? ' (teleport na mostek)' : ''}.` : `Odebrano dostęp do pulpitu ${nazwa}.`;
          wyczysc(komunikat).append(h('div.komunikat.ok', tekst));
        }
        delete wybory[nazwa];
        if (!cicho) await odswiez();
      } catch (e) { wyczysc(komunikat).append(blad(e)); }
    }

    // Bunt: odebranie graczowi wszystkich stanowisk na tym okręcie naraz (także w trwających symulacjach) —
    // jego ekran stanowiska zamyka się przy najbliższym odświeżeniu i wraca na pulpit HOLOLINK.
    async function odbierzWszystko(osoba) {
      if (!confirm(`Odebrać ${osoba.postac} dostęp do wszystkich stanowisk (${osoba.pulpity.join(', ')})?`)) return;
      for (const p of osoba.pulpity) await ustawPrzydzial(p, '', true);
      wyczysc(komunikat).append(h('div.komunikat.ok', `${osoba.postac}: odebrano dostęp do wszystkich stanowisk.`));
      await odswiez();
    }

    // Stanowiska do przydziału: pulpity okrętu, każda eskadra (3 klucze) i każdy klucz myśliwców — pilot dostaje
    // jeden klucz albo jedną eskadrę na telefonie.
    function stanowiskaDoPrzydzialu(stan) {
      const klucze = (stan.klucze || []).filter((k) => !k.ai).map((k) => k.nr).sort((a, b) => a - b);
      const eskadry = [...new Set(klucze.map((n) => Math.ceil(n / 3)))];
      return { pulpity: pulpity(stan).filter((p) => p !== 'Klucz' && p !== 'Eskadra'), mysliwce: [...eskadry.map((n) => `Eskadra ${n}`), ...klucze.map((n) => `Klucz ${n}`)] };
    }

    function rysujAdmin(st) {
      if (!(st.stanowiska?.pelny && (st.stanowiska?.mg || st.stanowiska?.kapitan))) return null;
      if (!gracze) wczytajZaloge();
      const przypisane = Object.fromEntries((st.stanowiska.przypisane || []).map((x) => [x.pulpit, x]));
      const wiersz = (nazwa) => {
        const teraz = przypisane[nazwa];
        const wybor = gracze ? h('select', { style: 'min-height:32px;max-width:220px', onchange: (e) => { wybory[nazwa] = e.target.value; } },
          h('option', { value: '' }, '— wybierz z załogi —'),
          gracze.map((g) => h('option', { value: g.login, selected: (wybory[nazwa] ?? teraz?.login) === g.login }, `${g.postac}${g.na_pokladzie ? '' : ' (poza okrętem — teleport)'}`))) : null;
        return h('tr', h('td', nazwa), h('td', teraz?.postac || h('span.slaby', 'wolne')),
          h('td', { style: 'text-align:right' }, h('div', { style: 'display:flex;justify-content:flex-end;gap:5px;flex-wrap:wrap' },
            wybor,
            wybor ? h('button.przycisk.drugi.maly', { onclick: () => (wybor.value ? ustawPrzydzial(nazwa, wybor.value) : null) }, 'Przydziel') : null,
            teraz ? h('button.przycisk.drugi.maly', { onclick: () => ustawPrzydzial(nazwa, '') }, 'Odbierz dostęp') : null)));
      };
      const { pulpity: pp, mysliwce } = stanowiskaDoPrzydzialu(st);
      // załoga ze stanowiskami: kto obsadza co — z przyciskiem odebrania wszystkiego
      const osoby = {};
      for (const x of st.stanowiska.przypisane || []) (osoby[x.login.toLowerCase()] ||= { login: x.login, postac: x.postac, pulpity: [] }).pulpity.push(x.pulpit);
      const obsada = Object.values(osoby);
      return panel(`ZAŁOGA · ${aktywny.nazwa}`,
        h('p.slaby.maly-tekst', 'Przydział jest stały: obowiązuje na co dzień i w każdej symulacji tego okrętu. Gracz widzi tylko swój pulpit '
          + '(jedno stanowisko na osobę). „Odbierz dostęp” działa od razu — ekran stanowiska gracza zamyka się w kilka sekund. '
          + (st.stanowiska.mg ? 'MG może przydzielić każdego gracza (postać zostaje przeniesiona na mostek).' : 'Kapitan przydziela graczy obecnych na pokładzie.')),
        !gracze ? h('div.slaby.maly-tekst', 'Wczytywanie załogi…') : !gracze.length ? h('div.komunikat', 'Na pokładzie nie ma nikogo, kogo można przydzielić.') : null,
        obsada.length ? h('div', { style: 'margin:8px 0' }, h('div.slaby.maly-tekst', 'Obsada stanowisk:'),
          h('table.tabela', obsada.map((o) => h('tr', h('td', h('b', o.postac)), h('td', o.pulpity.join(', ')),
            h('td', { style: 'text-align:right' }, h('button.przycisk.drugi.maly', { onclick: () => odbierzWszystko(o) }, 'Odbierz wszystkie stanowiska')))))) : null,
        h('div.slaby.maly-tekst', { style: 'margin-top:8px' }, 'Pulpity okrętu:'),
        h('table.tabela', pp.map(wiersz)),
        mysliwce.length ? h('div.slaby.maly-tekst', { style: 'margin-top:8px' }, 'Piloci (telefon):') : null,
        mysliwce.length ? h('table.tabela', mysliwce.map(wiersz)) : null);
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
        // Rozkazy z konsol trafiają do prawdziwego okrętu (api/lot_reczny.php): ster i ciąg, nastawy, wyrównania,
        // wzmocnienia. Pozostałe (osłony, baterie, klucze) działają tylko w symulacji — serwer odpowie odmową.
        rozkaz: async (dane) => {
          try {
            const r = await api('mostek_rozkaz', { okret: aktywny.obiekt_id, ...dane });
            if (r.ok === false) wyczysc(komunikat).append(h('div.komunikat', r.odmowa));
            else { wyczysc(komunikat); sygnaturaStanu = ''; await odswiez(); }
          } catch (e) { wyczysc(komunikat).append(blad(e)); }
        },
        komunikat: (tekst) => wyczysc(komunikat).append(h('div.komunikat', tekst)),
      };
      const ekran = pulpitAktywny ? konsola(pulpitAktywny, stanOkretu, stanKonsol, akcje)
        : h('div.komunikat', st.pelny ? `Brak układów konsol dla profilu ${st.okret?.profil || aktywny?.profil || 'nieznanego'}.`
          : 'To stanowisko nie jest już przydzielone.');
      const ramka = h('div.mostek-pulpit', ekran);
      const zawartosc = [
        kiosk ? null : panel('MOSTEK · ' + aktywny.nazwa, przywrocListe,
          st.pelny ? h('p.slaby.maly-tekst', 'Pełny dostęp dowódcy. Wybierz pulpit lub zarządzaj przydziałami załogi.') : null,
          rysujAdmin(stanOkretu), pasek, komunikat),
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

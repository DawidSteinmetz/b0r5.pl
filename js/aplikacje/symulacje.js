// Symulacje bitew: scenariusze, tworzenie i prowadzenie symulacji (instancje bez trwałych skutków), podgląd na żywo,
// rozkazy dla kluczy i osłon, XP pilotów po zakończeniu, test silnika dla MG.
// Widoczna tylko dla upoważnionych (MG i dowodzący okrętem z profilem klasy) — api/?a=bitwa_dostep.
// Mechanika i plan wdrożenia: narzedzia/mostek/ (MECHANIKA.md, WDROZENIE.md, PULPITY.md).

import { h, wyczysc } from '../rdzen/narzedzia.js';
import { api } from '../rdzen/api.js';
import { konsola, KONSOLE, nowyStanKonsol, zapiszSlady, zapiszZwroty, pulpityKlasy } from './konsole.js';

const DOWODZENIE = { gracze: 'gracze', radus: 'AI: Radus (agresywny)', ackbar: 'AI: Ackbar (defensywny)', calrissian: 'AI: Calrissian (zrównoważony)' };
const STATUS = { przygotowanie: 'w przygotowaniu', aktywna: 'trwa', zakonczona: 'zakończona', anulowana: 'anulowana' };
const RANGA = { as: 'as', standard: 'standardowy', kadet: 'kadet' };
// Lampa klucza (PULPITY.md 1b): kolor według stanu.
const LAMPA = { hangar: ['#2a2a80', '#fff', 'hangar'], gotowy: ['#6666ff', '#fff', 'gotowy'], w_locie: ['#66ccff', '#000', 'w locie'],
  powrot: ['#66ccff', '#000', 'powrót'], stracony: ['#999999', '#000', 'stracony'] };

const mmss = (s) => { s = Math.max(0, Math.round(s)); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`; };

export default {
  id: 'symulacje',
  nazwa: 'Symulacje',
  ikona: '<circle cx="24" cy="24" r="16"/><circle cx="24" cy="24" r="9"/><path d="M24 4v6M24 38v6M4 24h6M38 24h6"/><path d="M24 24l8-8"/>',
  // Ikona tylko dla upoważnionych; stan.symulacje ustawia pulpit po zapytaniu bitwa_dostep.
  dostep: (stan) => stan.uzytkownik?.rola === 'mg' || stan.symulacje === true,

  uruchom(w, ctx) {
    const tresc = h('div');
    let dane = null;
    let zegar = null;
    let koniec = false;
    // Tryb stanowiska (pulpit systemu otworzył symulację graczowi z przydziałem): tylko konsola, bez listy i nagłówka.
    const kiosk = !!ctx.parametr?.stanowisko;

    const panel = (tytul, ...zawartosc) => h('div.panel', h('h2', tytul), ...zawartosc);
    const para = (etykieta, wartosc) => h('tr', h('td.slaby', { style: 'width:120px;white-space:nowrap' }, etykieta), h('td', wartosc));
    const blad = (e) => h('div.komunikat.blad', e.message || String(e));
    const zatrzymaj = () => { if (zegar) clearTimeout(zegar); zegar = null; };

    // ------------------------------------------------------------------ lista
    async function lista() {
      zatrzymaj();
      ctx.ustawWstecz?.(null);
      try {
        dane = await api('bitwa_scenariusze');
        const sym = dane.silnik ? (await api('bitwa_symulacje')).symulacje : [];
        rysujListe(sym);
      } catch (e) {
        wyczysc(tresc).append(blad(e));
      }
    }

    function rysujListe(sym) {
      const okrety = dane.okrety || [];
      wyczysc(tresc).append(
        panel('SYMULACJE BITEW',
          h('p.maly-tekst', 'Instancje bitew na symulatorach pokładowych. Uszkodzenia jednostek nie zostają po symulacji; '
            + 'piloci z pokładu dostają 0,5 XP za każdą akcję (as ×0,5, kadet ×1,5) po zatwierdzeniu przez MG.'),
          h('table.tabela', para('Twoje okręty', okrety.length ? h('div', okrety.map((o) => h('div', `${o.nazwa} · ${o.profil_klasy}`))) : 'brak')),
          dane.silnik ? null : h('div.komunikat', 'Prowadzenie symulacji wymaga migracji 070 i 072.')),
        sym.length ? panel('TWOJE SYMULACJE', h('table.tabela', sym.map((s) => h('tr',
          h('td', h('div', s.nazwa), h('div.slaby.maly-tekst', `${STATUS[s.status] || s.status}${s.pauza ? ' · pauza' : ''} · ${mmss(s.czas_s)} z ${mmss(s.okno_s)}`)),
          h('td', { style: 'text-align:right' }, h('button.przycisk.drugi.maly', { onclick: () => otworzSymulacje(s.id) }, 'Otwórz')))))) : null,
        ...dane.scenariusze.map((s) => scenariusz(s, okrety)),
        dane.mg ? test() : null);
    }

    function scenariusz(s, okrety) {
      const strony = s.strony.map((x) => `${x.strona} — ${x.rola}, ${DOWODZENIE[x.dowodzenie] || x.dowodzenie}`);
      const jednostki = s.jednostki.map((j) => `${j.nazwa} (${j.profil_kod}, ${j.strona})${Number(j.z_mapy) ? ' — okręt z mapy' : ''}`);
      const klucze = s.klucze.map((k) => `${k.strona}: ${k.typ_maszyny} × ${k.klucze}`);
      const potrzebne = s.jednostki.filter((j) => Number(j.z_mapy)).map((j) => j.profil_kod);
      const pasujace = okrety.filter((o) => potrzebne.includes(o.profil_klasy));
      const wybor = pasujace.length > 1 ? h('select', { style: 'width:100%;min-height:36px;margin-top:8px' },
        pasujace.map((o) => h('option', { value: o.id }, `${o.nazwa} · ${o.profil_klasy}`))) : null;
      const komunikat = h('div');
      const utworz = h('button.przycisk', { style: 'width:100%;margin-top:10px', onclick: async () => {
        utworz.disabled = true;
        try {
          const { id } = await api('bitwa_utworz', { scenariusz: s.kod, obiekt_id: wybor ? Number(wybor.value) : (pasujace[0]?.id ?? null) });
          otworzSymulacje(id);
        } catch (e) { wyczysc(komunikat).append(blad(e)); utworz.disabled = false; }
      } }, 'Utwórz symulację');
      return panel(s.nazwa,
        h('p.maly-tekst', s.odprawa),
        h('table.tabela',
          para('Czas', `${Math.round(s.okno_s / 60)} min`),
          para('Strony', h('div', strony.map((x) => h('div', x)))),
          para('Okręty', h('div', jednostki.map((x) => h('div', x)))),
          para('Klucze', h('div', klucze.map((x) => h('div', x)))),
          para('Zwycięstwo', s.zwyciestwo),
          para('Porażka', s.porazka),
          para('Status', s.status === 'gotowy' ? 'gotowy' : 'roboczy — liczby do strojenia')),
        dane.silnik && (!potrzebne.length || pasujace.length) ? [wybor, utworz] : h('div.slaby.maly-tekst', { style: 'margin-top:10px' },
          dane.silnik ? `Potrzebny okręt klasy ${potrzebne.join(', ')}, którym dowodzisz.` : 'Uruchamianie po migracjach 070 i 072.'),
        komunikat);
    }

    function test() {
      const wynik = h('div');
      const przycisk = h('button.przycisk', { style: 'width:100%', onclick: async () => {
        przycisk.disabled = true;
        wyczysc(wynik).append(h('div.slaby.maly-tekst', 'Sprawdzanie…'));
        try {
          const t = await api('bitwa_test');
          wyczysc(wynik).append(
            h('div.komunikat' + (t.ok ? '.ok' : '.blad'), t.ok ? `Silnik zgodny ze wzorcem: ${t.sprawdzono}/${t.sprawdzono}.`
              : `Niezgodności: ${t.bledy.length} z ${t.sprawdzono}.`),
            t.ok ? null : h('table.tabela', t.bledy.map((b) => h('tr', h('td', b[0]), h('td.slaby', { style: 'word-break:break-all' }, `oczekiwane ${b[1]}, jest ${b[2]}`)))));
        } catch (e) {
          wyczysc(wynik).append(blad(e));
        }
        przycisk.disabled = false;
      } }, 'Sprawdź zgodność silnika ze wzorcem');
      return panel('TEST SILNIKA', h('p.slaby.maly-tekst', 'Porównuje silnik na serwerze z silnikiem wzorcowym: losowanie, czas wykonania rozkazów, '
        + 'żyły osłon, filtr energii i całe przebiegi scenariusza.'), przycisk, wynik);
    }

    // ------------------------------------------------------------------ symulacja
    function otworzSymulacje(id) {
      zatrzymaj();
      ctx.ustawWstecz?.(kiosk ? () => true : () => { lista(); return true; });
      // Elementy sterujące tworzone raz (wybór figury i Hz nie gubi się przy odświeżaniu co sekundę).
      const wyborFigury = h('select', { style: 'width:100%;min-height:36px' });
      const wyborRozkazu = h('select', { style: 'width:100%;min-height:36px' });
      const wyborHz = h('select', { style: 'min-height:32px' });
      const komunikat = h('div');
      const naglowek = h('div');
      const radar = h('div', { style: 'display:flex;justify-content:center' });
      const okrety = h('div');
      const klucze = h('div');
      const zdarzenia = h('div');
      const wynikXp = h('div');
      let ostatni = null;
      // Widoki: przegląd albo konsola mostka. MG, prowadzący i kapitan widzą wszystko; gracz ze stanowiskiem — tylko
      // przypisane mu pulpity (bitwy_stanowiska); bez przydziału czeka na niego.
      let widok = 'przeglad';
      let podpisZakladek = '';
      let podpisStanowisk = '';
      let gracze = null;
      const stanowiskaEl = h('div');
      const stanKonsol = nowyStanKonsol();
      const zakladki = h('div.zakladki');
      const konsolaEl = h('div', { hidden: true, style: 'margin:10px 0' });
      const przeglad = h('div');
      const akcjeKonsoli = {
        odswiez: () => rysujKonsole(),
        rozkaz: (dane) => rozkaz(dane),
        komunikat: (tekst) => wyczysc(komunikat).append(h('div.komunikat.blad', tekst)),
      };
      function rysujKonsole() {
        if (!ostatni) return;
        const st = ostatni.stanowiska || { pelny: true, moje: [] };
        przeglad.hidden = !(st.pelny && widok === 'przeglad');
        konsolaEl.hidden = !przeglad.hidden ? true : false;
        if (!przeglad.hidden) return;
        if (!widok) {
          wyczysc(konsolaEl).append(panel('STANOWISKO', h('p.maly-tekst', 'Czekasz na przydział stanowiska. Pulpit przydziela MG albo kapitan okrętu.')));
          return;
        }
        wyczysc(konsolaEl).append(konsola(widok, ostatni, stanKonsol, akcjeKonsoli));
      }
      // Zakładki: pełny podgląd — przegląd, pulpity z mechaniką, potem pozostałe pulpity klasy; gracz — tylko swoje.
      function listaWidokow() {
        const st = ostatni?.stanowiska || { pelny: true, moje: [] };
        const profil = st.okret?.profil || ostatni?.jednostki?.[0]?.profil;
        if (!st.pelny) return st.moje.map((k) => [k, k]);
        const inne = pulpityKlasy(profil).filter((k) => !KONSOLE.includes(k));
        return [['przeglad', 'Przegląd'], ...KONSOLE.map((k) => [k, k]), ...inne.map((k) => [k, k])];
      }
      function rysujZakladki() {
        const lista = listaWidokow();
        const podpis = JSON.stringify([lista, widok]);
        if (podpis === podpisZakladek) return;
        podpisZakladek = podpis;
        wyczysc(zakladki).append(...lista.map(([k, n]) =>
          h('button' + (k === widok ? '.aktywna' : ''), { onclick: () => { widok = k; rysujZakladki(); rysujKonsole(); } }, n)));
      }
      // Panel przydziału stanowisk (MG i kapitan): pulpity klasy, przypisana postać, wybór gracza.
      function rysujStanowiska(force = false) {
        const st = ostatni?.stanowiska;
        if (!st?.okret || !(st.mg || st.kapitan)) { wyczysc(stanowiskaEl); podpisStanowisk = ''; return; }
        const podpis = JSON.stringify([st.przypisane, gracze]);
        if (!force && podpis === podpisStanowisk) return;
        podpisStanowisk = podpis;
        const przypisany = Object.fromEntries(st.przypisane.map((p) => [p.pulpit, p]));
        const wiersz = (pulpit) => {
          const wybor = gracze ? h('select', { style: 'min-height:32px;max-width:200px' }, h('option', { value: '' }, '— gracz —'),
            gracze.map((g) => h('option', { value: g.login, selected: przypisany[pulpit]?.login === g.login },
              `${g.postac}${g.na_pokladzie ? '' : ' (teleport)'}`))) : null;
          return h('tr',
            h('td', pulpit),
            h('td', przypisany[pulpit] ? przypisany[pulpit].postac : h('span.slaby', '—')),
            h('td', { style: 'text-align:right;white-space:nowrap' }, h('div', { style: 'display:flex;gap:4px;justify-content:flex-end' },
              wybor,
              wybor ? h('button.przycisk.drugi.maly', { onclick: () => przydziel(pulpit, wybor.value) }, 'Przydziel') : null,
              przypisany[pulpit] ? h('button.przycisk.drugi.maly', { onclick: () => przydziel(pulpit, '') }, 'Zwolnij') : null)));
        };
        wyczysc(stanowiskaEl).append(panel('STANOWISKA · ' + st.okret.nazwa,
          h('p.slaby.maly-tekst', st.mg ? 'MG przydziela każdego gracza — postać spoza pokładu zostaje teleportowana na mostek.'
            : 'Kapitan przydziela graczy obecnych na pokładzie. Kapitan widzi wszystkie pulpity.'),
          gracze ? null : h('button.przycisk.drugi', { style: 'width:100%;margin-bottom:8px', onclick: wczytajGraczy }, 'Wczytaj graczy do przydziału'),
          h('table.tabela', pulpityStanowisk(st.okret.profil).map(wiersz))));
      }
      // Pulpity do przydziału: pulpity okrętu (bez ogólnych Klucz / Eskadra) oraz każdy klucz i każda eskadra (3 klucze)
      // graczy osobno — pilot dostaje jeden klucz albo jedną eskadrę na telefonie.
      function pulpityStanowisk(profil) {
        const klucze = (ostatni?.klucze || []).filter((k) => !k.ai).map((k) => k.nr).sort((a, b) => a - b);
        const eskadry = [...new Set(klucze.map((n) => Math.ceil(n / 3)))];
        return [...pulpityKlasy(profil).filter((p) => p !== 'Klucz' && p !== 'Eskadra'),
          ...eskadry.map((n) => `Eskadra ${n}`), ...klucze.map((n) => `Klucz ${n}`)];
      }
      async function wczytajGraczy() {
        try { gracze = (await api('bitwa_gracze', undefined, { id })).gracze; rysujStanowiska(true); }
        catch (e) { wyczysc(komunikat).append(blad(e)); }
      }
      async function przydziel(pulpit, login) {
        try {
          const r = await api('bitwa_stanowisko', { id, pulpit, login });
          wyczysc(komunikat).append(h('div.komunikat.ok', !login ? `Pulpit ${pulpit} zwolniony.`
            : r.teleport ? `${r.postac}: teleport na mostek i przydział do pulpitu ${pulpit}.` : `${r.postac}: przydział do pulpitu ${pulpit}.`));
          if (r.teleport) gracze = null;
          odswiez();
        } catch (e) { wyczysc(komunikat).append(blad(e)); }
      }

      const rozkaz = async (dane) => {
        try {
          const r = await api('bitwa_rozkaz', { id, uid: crypto.randomUUID(), ...dane });
          wyczysc(komunikat).append(r.ok === false ? h('div.komunikat.blad', r.odmowa)
            : h('div.komunikat.ok', r.dziala_za_s != null ? `Rozkaz przyjęty — działa za ${r.dziala_za_s} s.` : 'Rozkaz przyjęty.'));
          odswiez();
        } catch (e) { wyczysc(komunikat).append(blad(e)); }
      };
      const prowadz = async (akcja, dodatkowe = {}) => {
        try {
          const r = await api(akcja, { id, ...dodatkowe });
          if (akcja === 'bitwa_zakoncz') pokazXp(r.xp || []);
          odswiez();
        } catch (e) { wyczysc(komunikat).append(blad(e)); }
      };

      function pokazXp(xp) {
        wyczysc(wynikXp).append(panel('XP PILOTÓW',
          xp.length ? h('table.tabela', h('tr', h('th', 'Pilot'), h('th', 'Ranga'), h('th', 'Akcje'), h('th', 'XP')),
            xp.map((p) => h('tr', h('td', p.pilot), h('td', RANGA[p.ranga] || p.ranga), h('td', p.akcje), h('td', p.xp))))
            : h('div.slaby.maly-tekst', 'Brak pilotów z pokładu w tej symulacji.'),
          ostatni?.mg && xp.length ? h('button.przycisk', { style: 'width:100%;margin-top:10px', onclick: async (e) => {
            e.target.disabled = true;
            try { const r = await api('bitwa_xp_zatwierdz', { id }); wyczysc(komunikat).append(h('div.komunikat.ok', `XP zapisane: ${r.piloci} pilotów.`)); }
            catch (er) { wyczysc(komunikat).append(blad(er)); e.target.disabled = false; }
          } }, 'Zatwierdź XP (MG)') : null));
      }

      function rysujRadar(s) {
        // Prosty radar: środek = pierwsza jednostka strony graczy, okręgi 10 / 30 / 60 km.
        const srodek = s.jednostki[0] || { x: 0, y: 0 };
        const R = 60, P = 150;
        const px = (x) => P + ((x - srodek.x) / R) * P, py = (y) => P - ((y - srodek.y) / R) * P;
        const ns = 'http://www.w3.org/2000/svg';
        const el = (tag, at) => { const e = document.createElementNS(ns, tag); for (const [k, v] of Object.entries(at)) e.setAttribute(k, v); return e; };
        const svg = el('svg', { viewBox: `0 0 ${2 * P} ${2 * P}`, width: '100%', style: 'max-width:300px;background:rgba(77,226,201,.035);border-radius:50%' });
        for (const r of [10, 30, 60]) {
          svg.append(el('circle', { cx: P, cy: P, r: (r / R) * P, fill: 'none', stroke: 'rgba(77,226,201,.35)', 'stroke-width': 1 }));
          const t = el('text', { x: P + 3, y: P - (r / R) * P + 11, fill: 'rgba(77,226,201,.6)', 'font-size': 10 }); t.textContent = `${r} km`; svg.append(t);
        }
        for (const j of s.jednostki) svg.append(el('rect', { x: px(j.x) - 5, y: py(j.y) - 5, width: 10, height: 10, fill: j.strona === srodek.strona ? '#9fb4c8' : '#ff6b55' }));
        for (const k of s.klucze) {
          if (k.stan !== 'w_locie') continue;
          const x = px(k.x), y = py(k.y);
          if (x < 0 || y < 0 || x > 2 * P || y > 2 * P) continue;
          svg.append(el('path', { d: `M${x} ${y - 4} L${x + 3.5} ${y + 3} L${x - 3.5} ${y + 3}Z`, fill: k.strona === srodek.strona ? '#9fb4c8' : '#ff6b55' }));
        }
        wyczysc(radar).append(svg);
      }

      function rysuj(s) {
        s._odebrano = Date.now(); // chwila odczytu — konsole przewidują ruch od niej
        ostatni = s;
        zapiszSlady(stanKonsol, s);
        zapiszZwroty(stanKonsol, s);
        const st = s.stanowiska || { pelny: true, moje: [] };
        if (!st.pelny && !st.moje.includes(widok)) widok = st.moje[0] || null;
        // Gracz ze stanowiskiem widzi tylko swój pulpit: bez nagłówka symulacji, zakładki tylko przy kilku pulpitach.
        naglowek.hidden = !st.pelny;
        zakladki.hidden = !st.pelny && st.moje.length < 2;
        if (!st.pelny && ['zakonczona', 'anulowana'].includes(s.status)) {
          wyczysc(konsolaEl).append(panel('SYMULACJA ZAKOŃCZONA', h('p.maly-tekst', 'Stanowisko zwolnione. Za chwilę powrót na pulpit.')));
          konsolaEl.hidden = false;
          if (kiosk) setTimeout(() => ctx.pulpit?.(), 5000);
          return;
        }
        rysujZakladki();
        rysujKonsole();
        rysujStanowiska();
        const trwa = s.status === 'aktywna' && !s.pauza;
        wyczysc(naglowek).append(panel(s.nazwa,
          h('table.tabela',
            para('Stan', `${STATUS[s.status] || s.status}${s.pauza ? ' · pauza' : ''}`),
            para('Czas', `${mmss(s.czas_s)} z ${mmss(s.okno_s)}`),
            para('Prowadzi', s.prowadzacy || '—')),
          s.moge_prowadzic ? h('div', { style: 'display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px' },
            s.status === 'przygotowanie' ? h('button.przycisk', { onclick: () => prowadz('bitwa_start') }, 'Start') : null,
            s.status === 'aktywna' ? h('button.przycisk.drugi', { onclick: () => prowadz('bitwa_pauza', { pauza: !s.pauza }) }, s.pauza ? 'Wznów' : 'Pauza') : null,
            ['przygotowanie', 'aktywna'].includes(s.status) ? h('button.przycisk.drugi', { onclick: () => {
              if (confirm(s.status === 'aktywna' ? 'Zakończyć symulację i policzyć XP pilotów?' : 'Anulować symulację?')) prowadz('bitwa_zakoncz');
            } }, s.status === 'aktywna' ? 'Zakończ' : 'Anuluj') : null) : null));
        rysujRadar(s);
        // osłony: wybór Hz (stopnie natywne pierwszej jednostki)
        if (!wyborHz.options.length && s.jednostki[0]) {
          wyborHz.append(...s.jednostki[0].stopnie.map((x) => h('option', { value: x, selected: x === 1800 }, `${x} Hz`)));
        }
        wyczysc(okrety).append(...s.jednostki.map((j) => panel(`${j.nazwa.toUpperCase()} · ${j.strona}`,
          h('table.tabela',
            para('Kadłub', `${j.kadlub.toFixed(2)}%`),
            para('Sekcje', h('div.maly-tekst', Object.entries(j.sekcje).map(([k, v]) => `${k} ${Number(v).toFixed(2)}%`).join(' · '))),
            ...j.generatory.map((g) => para(g.kod.replace('oslona_', 'osłona '), h('div',
              h('div', `${g.hz_osiagane} Hz${g.hz_zadane !== g.hz_osiagane ? ` → ${g.hz_zadane} Hz` : ''} · skuteczne ${g.hz_skuteczne} · obciążenie ${g.obciazenie_proc}%`
                + ` · martwe żyły ${g.martwe}${g.auto ? ' · auto' : ''}`),
              trwa && j.strona === s.jednostki[0].strona ? h('div', { style: 'display:flex;gap:6px;margin-top:4px' },
                h('button.przycisk.drugi.maly', { onclick: () => rozkaz({ generator_id: g.id, kod: 'przestrojenie_reczne', hz: Number(wyborHz.value) }) }, 'Ustaw Hz z listy'),
                g.auto ? null : h('button.przycisk.drugi.maly', { onclick: () => rozkaz({ generator_id: g.id, kod: 'auto' }) }, 'Auto')) : null)))),
          trwa && j.strona === s.jednostki[0].strona ? h('div', { style: 'margin-top:8px' }, h('span.slaby.maly-tekst', 'Hz do ustawienia: '), wyborHz) : null)));
        // klucze
        if (!wyborFigury.options.length) {
          wyborFigury.append(...s.figury.filter((f) => f.grupa !== 'rozkaz_klucza').map((f) =>
            h('option', { value: f.kod }, `${f.nazwa} (${f.przygotowanie_s} s${f.ranga_min === 'as' ? ', tylko as' : ''})`)));
          wyborRozkazu.append(...s.figury.filter((f) => f.grupa === 'rozkaz_klucza' && f.kod !== 'start').map((f) =>
            h('option', { value: f.kod, selected: f.kod === 'ochrona' }, `${f.nazwa} (${f.przygotowanie_s} s)`)));
        }
        const graczy = s.klucze.filter((k) => !k.ai);
        const wiersz = (k) => {
          const [tlo, kolor, opis] = LAMPA[k.stan] || LAMPA.hangar;
          const lampa = h('span', { style: `display:inline-block;min-width:74px;text-align:center;padding:3px 6px;border-radius:3px;background:${tlo};color:${kolor};font-size:12px` },
            k.stan === 'hangar' && k.start_za_s != null ? `start ${mmss(k.start_za_s)}` : opis);
          const moje = !k.ai && trwa;
          const przyciski = [];
          if (moje && ['w_locie', 'powrot'].includes(k.stan)) przyciski.push(h('button.przycisk.drugi.maly', { onclick: () => rozkaz({ klucz_id: k.id, kod: wyborRozkazu.value }) }, 'Rozkaz'));
          if (moje && k.stan === 'w_locie') przyciski.push(h('button.przycisk.drugi.maly', { onclick: () => rozkaz({ klucz_id: k.id, kod: wyborFigury.value }) }, 'Figura'));
          if (moje && k.stan === 'hangar' && k.start_za_s == null) przyciski.push(h('button.przycisk.drugi.maly', { onclick: () => rozkaz({ klucz_id: k.id, kod: 'start' }) }, 'Start'));
          return h('tr',
            h('td', lampa),
            h('td', h('div', `${k.strona} ${k.nr} · ${k.maszyna}`), h('div.slaby.maly-tekst', `${RANGA[k.ranga] || k.ranga} · ${k.rozkaz_nazwa || k.rozkaz} · akcje ${k.akcje}`
              + (k.figura ? ` · ${k.figura}` : '') + (k.przygotowanie ? ` · przygotowanie: ${k.przygotowanie.nazwa} za ${k.przygotowanie.dziala_za_s} s` : ''))),
            h('td', { style: 'text-align:right;white-space:nowrap' }, h('div', { style: 'display:flex;gap:4px;justify-content:flex-end' }, przyciski)));
        };
        wyczysc(klucze).append(panel('KLUCZE',
          graczy.length && trwa ? h('div', { style: 'display:grid;gap:6px;margin-bottom:8px' },
            h('div.slaby.maly-tekst', 'Rozkaz dla klucza (przycisk „Rozkaz”): domyślnie ochrona — klucz trzyma się okrętu matki.'), wyborRozkazu,
            h('div.slaby.maly-tekst', 'Figura dla klucza w locie (przycisk „Figura”):'), wyborFigury) : null,
          h('table.tabela', s.klucze.map(wiersz))));
        wyczysc(zdarzenia).append(panel('ZDARZENIA', s.zdarzenia.length
          ? h('table.tabela', s.zdarzenia.map((z) => h('tr', h('td.slaby', { style: 'width:60px' }, mmss(z.tik)), h('td', z.opis))))
          : h('div.slaby.maly-tekst', 'Jeszcze nic się nie wydarzyło.')));
      }

      // Odpytywanie bez nakładania: następne zapytanie dopiero po odpowiedzi (co ok. 1 s); błąd sieci — ponowienie po 3 s.
      let wToku = false;
      async function odswiez() {
        if (koniec || wToku) return;
        wToku = true;
        const t0 = Date.now();
        let nastepne = 1000;
        try {
          const { stan } = await api('bitwa_stan', undefined, { id });
          rysuj(stan);
          if (['zakonczona', 'anulowana'].includes(stan.status)) nastepne = null;
          else if (komunikat.querySelector('.blad-sieci')) wyczysc(komunikat);
        } catch (e) {
          if (e.kod === 403 || e.kod === 404) { wyczysc(komunikat).append(blad(e)); nastepne = null; }
          else { wyczysc(komunikat).append(h('div.komunikat.blad.blad-sieci', `${e.message} Ponawiam…`)); nastepne = 3000; }
        } finally {
          wToku = false;
        }
        zatrzymaj();
        if (nastepne !== null && !koniec) zegar = setTimeout(odswiez, Math.max(0, nastepne - (Date.now() - t0)));
      }

      przeglad.append(stanowiskaEl, panel('RADAR', radar), okrety, klucze, zdarzenia);
      wyczysc(tresc).append(naglowek, zakladki, komunikat, konsolaEl, przeglad, wynikXp);
      odswiez();
    }

    w.append(tresc);
    if (ctx.parametr?.bitwa) otworzSymulacje(Number(ctx.parametr.bitwa));
    else lista();
    return () => { koniec = true; zatrzymaj(); };
  },
};

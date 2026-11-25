// Symulacje bitew: scenariusze (instancje bitew bez trwałych skutków), okręty konta, test silnika dla MG.
// Widoczna tylko dla upoważnionych (MG i dowodzący okrętem z profilem klasy) — api/?a=bitwa_dostep.
// Mechanika i plan wdrożenia: narzedzia/mostek/ (MECHANIKA.md, WDROZENIE.md).

import { h, wyczysc } from '../rdzen/narzedzia.js';
import { api } from '../rdzen/api.js';

const DOWODZENIE = { gracze: 'gracze', radus: 'AI: Radus (agresywny)', ackbar: 'AI: Ackbar (defensywny)', calrissian: 'AI: Calrissian (zrównoważony)' };
const ROLA = { obrona: 'obrona', atak: 'atak' };

export default {
  id: 'symulacje',
  nazwa: 'Symulacje',
  ikona: '<circle cx="24" cy="24" r="16"/><circle cx="24" cy="24" r="9"/><path d="M24 4v6M24 38v6M4 24h6M38 24h6"/><path d="M24 24l8-8"/>',
  // Ikona tylko dla upoważnionych; stan.symulacje ustawia pulpit po zapytaniu bitwa_dostep.
  dostep: (stan) => stan.uzytkownik?.rola === 'mg' || stan.symulacje === true,

  uruchom(w, ctx) {
    const tresc = h('div');
    let dane = null;

    const panel = (tytul, ...zawartosc) => h('div.panel', h('h2', tytul), ...zawartosc);
    const para = (etykieta, wartosc) => h('tr', h('td.slaby', { style: 'width:120px;white-space:nowrap' }, etykieta), h('td', wartosc));

    async function wczytaj() {
      try {
        dane = await api('bitwa_scenariusze');
        rysuj();
      } catch (e) {
        wyczysc(tresc).append(h('div.komunikat.blad', e.message));
      }
    }

    function scenariusz(s) {
      const strony = s.strony.map((x) => `${x.strona} — ${ROLA[x.rola] || x.rola}, ${DOWODZENIE[x.dowodzenie] || x.dowodzenie}`);
      const jednostki = s.jednostki.map((j) => `${j.nazwa} (${j.profil_kod}, ${j.strona})${Number(j.z_mapy) ? ' — z mapy' : ''}`);
      const klucze = s.klucze.map((k) => `${k.strona}: ${k.typ_maszyny} × ${k.klucze}`);
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
        h('div.komunikat', { style: 'margin-top:10px' }, 'Uruchamianie symulacji będzie dostępne po ukończeniu silnika tików.'));
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
            t.ok ? null : h('table.tabela', t.bledy.map((b) => h('tr', h('td', b[0]), h('td.slaby', `oczekiwane ${b[1]}, jest ${b[2]}`)))));
        } catch (e) {
          wyczysc(wynik).append(h('div.komunikat.blad', e.message));
        }
        przycisk.disabled = false;
      } }, 'Sprawdź zgodność silnika ze wzorcem');
      return panel('TEST SILNIKA', h('p.slaby.maly-tekst', 'Porównuje rdzeń silnika na serwerze z silnikiem wzorcowym: losowanie, czas wykonania rozkazów, żyły osłon, filtr energii.'), przycisk, wynik);
    }

    function rysuj() {
      const okrety = dane.okrety || [];
      wyczysc(tresc).append(
        panel('SYMULACJE BITEW',
          h('p.maly-tekst', 'Instancje bitew na symulatorach pokładowych. Uszkodzenia jednostek nie zostają po symulacji; '
            + 'piloci NPC dostają 0,5 XP za każdą akcję (as ×0,5, kadet ×1,5).'),
          h('table.tabela', para('Twoje okręty', okrety.length ? h('div', okrety.map((o) => h('div', `${o.nazwa} · ${o.profil_klasy}`))) : 'brak'))),
        ...dane.scenariusze.map(scenariusz),
        dane.mg ? test() : null);
    }

    w.append(tresc);
    wczytaj();
  },
};

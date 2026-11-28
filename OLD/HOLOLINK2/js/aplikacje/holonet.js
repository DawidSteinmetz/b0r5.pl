// Holonet: komunikaty sieci, transmisja na żywo i przechwycony kanał B0R5.

import { h, wyczysc, ikona, czekaj, zIso, dataGST } from '../rdzen/narzedzia.js';
import { api } from '../rdzen/api.js';

// Kanał transmisji na żywo. „parent" musi być domeną, na której stoi system.
const KANAL_NA_ZYWO = 'dave_stingert';

const IKONY = {
  wiadomosci: '<rect x="8" y="10" width="32" height="28" rx="2"/><path d="M14 18h20M14 24h20M14 30h12"/>',
  transmisja: '<circle cx="24" cy="24" r="4"/><path d="M15 15a13 13 0 0 0 0 18M33 15a13 13 0 0 1 0 18M10 10a20 20 0 0 0 0 28M38 10a20 20 0 0 1 0 28"/>',
  b0r5: '<path d="M8 38 24 8l16 30Z"/><path d="M24 20v9M24 33v1"/>',
  szyfr: '<rect x="10" y="22" width="28" height="18" rx="2"/><path d="M16 22v-6a8 8 0 0 1 16 0v6"/><path d="M18 31h2M23 31h2M28 31h2"/>',
};

export default {
  id: 'holonet',
  nazwa: 'Holonet',
  ikona: '<circle cx="24" cy="24" r="16"/><path d="M8 24h32M24 8c-6 5-6 27 0 32M24 8c6 5 6 27 0 32"/>',

  uruchom(w, ctx) {
    let przerwij = false;

    let wtyczki = [];
    let naGlownej = true;
    const glowna = () => {
      naGlownej = true;
      ctx.ustawWstecz(null);
      wyczysc(w).append(
        h('div.app-glowa', h('h1', 'Holonet'), h('span.podtytul', 'Węzeł publiczny')),
        h('div.panel',
          h('h2', 'Węzły'),
          h('ul.lista',
            wezel(IKONY.wiadomosci, 'Komunikaty sieci', 'Najnowsze wiadomości z holonetu', wiadomosci),
            wezel(IKONY.transmisja, 'Transmisja na żywo', 'Odbiór kanału holowizyjnego', transmisja),
            wezel(IKONY.b0r5, 'Przechwycony kanał', 'Sygnał niezidentyfikowany · szyfrowany', b0r5))),
        // Kanały z zainstalowanych wtyczek (czarny rynek informacji).
        wtyczki.length ? h('div.panel', { style: 'border-color:var(--zph)' },
          h('h2', { style: 'color:var(--zph)' }, 'Kanały szyfrowane'),
          h('ul.lista', wtyczki.map((k) => wezel(IKONY.szyfr, k.nazwa, 'Wtyczka zewnętrzna · transmisja szyfrowana', () => kanal(k))))) : null,
      );
    };
    api('wtyczki').then((d) => { wtyczki = d.wtyczki; if (naGlownej && !przerwij) glowna(); }).catch(() => {});

    const wezel = (ik, tytul, opis, fn) => h('li', h('div.wezel', { onclick: fn, role: 'button' },
      ikona(ik), h('div', h('div', tytul), h('div.slaby.maly-tekst', opis))));

    const podstrona = (tytul, ...zawartosc) => {
      ctx.ustawWstecz(() => { przerwij = true; glowna(); return true; });
      przerwij = false;
      naGlownej = false;
      wyczysc(w).append(h('div.app-glowa', h('h1', tytul)), ...zawartosc);
    };

    async function wiadomosci() {
      const lista = h('div', h('div.pusto', 'Pobieranie...'));
      podstrona('Komunikaty sieci', lista);
      try {
        const { komunikaty } = await api('holonet');
        wyczysc(lista);
        if (!komunikaty.length) return lista.append(h('div.pusto', 'Brak nowych komunikatów.'));
        for (const k of komunikaty) {
          lista.append(h('article.panel',
            h('div.slaby.maly-tekst', [dataGST(zIso(k.data)), k.zrodlo].filter(Boolean).join(' · ')),
            h('h2', { style: 'margin-top:6px' }, k.tytul),
            h('div.dokument-tresc', k.tresc)));
        }
      } catch (e) {
        wyczysc(lista).append(h('div.komunikat.blad', e.message));
      }
    }

    function transmisja() {
      const src = `https://player.twitch.tv/?channel=${encodeURIComponent(KANAL_NA_ZYWO)}&parent=${encodeURIComponent(location.hostname)}&muted=false`;
      podstrona('Transmisja na żywo',
        h('iframe.ramka-transmisji', { src, allowfullscreen: true, allow: 'autoplay; fullscreen', title: 'Transmisja' }),
        h('p.slaby.maly-tekst', 'Jeśli obraz nie pojawia się, kanał nie nadaje.'));
    }

    // Kanał czarnego rynku: wiadomości pisane szyfrem słownym. Pliki deszyfrujące z Dekodera
    // (słowniki „kod → znaczenie”) podmieniają znane kody; nieznane zostają w oryginale.
    async function kanal(k) {
      const lista = h('div', h('div.pusto', 'Nawiązywanie połączenia...'));
      const stopka = h('div');
      let szyfr = false;
      podstrona(k.nazwa, stopka, lista);
      let d;
      try {
        d = await api('kanal', undefined, { id: k.id });
      } catch (e) {
        wyczysc(lista).append(h('div.komunikat.blad', e.message));
        return;
      }
      const hasla = d.slowniki.flatMap((sl) => sl.wpisy.flatMap((x) => x.kody.map((kod) => ({ kod, znaczenie: x.znaczenie }))))
        .sort((a, b) => b.kod.length - a.kod.length);
      const mapa = new Map(hasla.map((x) => [x.kod.toLowerCase(), x.znaczenie]));
      const ucieczka = (t) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      const wzor = hasla.length ? new RegExp(`(?<![\\p{L}\\p{N}])(${hasla.map((x) => ucieczka(x.kod)).join('|')})(?![\\p{L}\\p{N}])`, 'giu') : null;

      const odszyfruj = (tekst) => {
        if (!wzor || szyfr) return [tekst];
        const czesci = [];
        let ostatni = 0;
        for (const m of tekst.matchAll(wzor)) {
          czesci.push(tekst.slice(ostatni, m.index));
          czesci.push(h('span.odszyfrowane', { title: m[0] }, mapa.get(m[0].toLowerCase())));
          ostatni = m.index + m[0].length;
        }
        czesci.push(tekst.slice(ostatni));
        return czesci;
      };
      const rysuj = () => {
        wyczysc(lista).append(...(d.wpisy.length
          ? d.wpisy.map((x) => h('article.panel',
            h('div.slaby.maly-tekst', new Date(x.data).toLocaleString('pl-PL', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })),
            h('div.dokument-tresc', { style: 'min-height:0;margin-top:6px' }, odszyfruj(x.tresc))))
          : [h('div.pusto', 'Kanał milczy.')]));
      };
      wyczysc(stopka).append(h('div.panel', { style: 'border-color:var(--zph)' },
        d.slowniki.length
          ? h('div.rzad',
            h('div.rozpycha.maly-tekst', h('span', { style: 'color:var(--zph)' }, 'Deszyfracja aktywna. '),
              h('span.slaby', d.slowniki.map((sl) => `${sl.plik} (${sl.wpisy.length} haseł)`).join(', '))),
            h('button.przycisk.drugi.maly', { onclick: (e) => { szyfr = !szyfr; e.target.textContent = szyfr ? 'Pokaż odszyfrowane' : 'Pokaż szyfr'; rysuj(); } }, 'Pokaż szyfr'))
          : h('div.maly-tekst', h('span', { style: 'color:var(--zph)' }, 'Brak pliku deszyfrującego w pamięci urządzenia. '),
            h('span.slaby', 'Wiadomości wyświetlane w postaci zaszyfrowanej.'))));
      rysuj();
    }

    async function b0r5() {
      const term = h('div.terminal');
      const pasekPostepu = h('i');
      podstrona('Przechwycony kanał', h('div.panel', term, h('div.pasek-postepu', pasekPostepu)));
      const pisz = async (t, klasa = '') => {
        const l = h('div' + klasa);
        term.append(l);
        for (const z of t) {
          if (przerwij) return;
          l.textContent += z;
          await czekaj(18);
        }
        await czekaj(250);
      };
      await pisz('> Nieautoryzowany dostęp...');
      await pisz('> Omijanie zabezpieczeń ISB, warstwa 7');
      for (let p = 0; p <= 100 && !przerwij; p += 4) {
        pasekPostepu.style.width = p + '%';
        await czekaj(60);
      }
      await pisz('> Nadchodząca transmisja od: B0R5');
      await pisz(' ');
      await pisz('Połączyłeś się z automatycznym systemem rekrutacyjnym B0R5.');
      await pisz('Potrzebujemy Cię.');
    }

    glowna();
    return () => { przerwij = true; };
  },
};

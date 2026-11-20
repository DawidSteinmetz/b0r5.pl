// Nawigacja: gdzie jestem, dokąd i czym mogę się dostać, mój statek, portfel, akcje w miejscu.

import { h, wyczysc, czasGry } from '../rdzen/narzedzia.js';
import { panelZgod } from '../rdzen/wskazniki.js';
import { api } from '../rdzen/api.js';

const TYPY = {
  statek: ['Twój statek', '✦'],
  hangar: ['Hangar', '⌂'], platforma: ['Platforma lądowania', '▬'], port: ['Port kosmiczny', '✈'], hotel: ['Hotel', '▤'], bankomat: ['Bankomat', '¤'], sklep: ['Sklep', '◫'],
  kantyna: ['Kantyna', '◍'], biuro: ['Biuro', '▦'], garaz: ['Garaż', '▭'], inne: ['Miejsce', '•'], poklad: ['Pokład statku', '△'],
};

const kr = (n) => `${Number(n).toLocaleString('pl-PL')} kr`;

// Czas świata gry: „1 h 20 min”.
export function czasSwiata(min) {
  const m = Math.max(0, Math.round(min));
  return m >= 60 ? `${Math.floor(m / 60)} h ${m % 60 ? `${m % 60} min` : ''}`.trim() : `${m} min`;
}

// Odliczanie rzeczywiste: „1:05”.
export function odliczanie(sek) {
  const s = Math.max(0, Math.ceil(sek));
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

export default {
  id: 'nawigacja',
  nazwa: 'Nawigacja',
  ikona: '<circle cx="24" cy="24" r="16"/><path d="M24 8v5M24 35v5M8 24h5M35 24h5"/><path d="M20 28l3-9 9-3-3 9z"/>',

  uruchom(w, ctx) {
    let d = null;
    let roznica = 0; // zegar serwera - zegar tabletu (s)
    let zegar = null;
    const komunikat = h('div');
    const tresc = h('div');
    const pokaz = (t, rodzaj = 'blad') => wyczysc(komunikat).append(h('div.komunikat.' + rodzaj, t));
    const teraz = () => Date.now() / 1000 + roznica;

    w.append(h('div.app-glowa', h('h1', 'Nawigacja'), h('span.podtytul', ctx.uzytkownik.postac)), komunikat, tresc);

    async function wczytaj() {
      try {
        d = await api('nawigacja');
        roznica = d.teraz - Date.now() / 1000;
        rysuj();
      } catch (e) {
        pokaz(e.message);
      }
    }

    async function akcja(nazwa, dane, ok) {
      try {
        const r = await api(nazwa, dane);
        await wczytaj();
        if (ok) pokaz(typeof ok === 'function' ? ok(r) : ok, 'ok');
        return r;
      } catch (e) {
        pokaz(e.message);
        return null;
      }
    }

    // Liczniki odświeżane co sekundę bez przeładowania całego widoku.
    let odswiezanie = null;
    let przeladowanie = false;
    let ostatniePrzeladowanie = 0;
    function tykaj() {
      clearInterval(odswiezanie);
      if (d.poklad?.w_locie) odswiezanie = setInterval(() => { if (!w.contains(document.activeElement) || document.activeElement.tagName !== 'INPUT') wczytaj(); }, 8000);
      clearInterval(zegar);
      zegar = setInterval(() => {
        let koniec = false;
        w.querySelectorAll('[data-koniec]').forEach((el) => {
          const zostalo = Number(el.dataset.koniec) - teraz();
          const start = Number(el.dataset.start || 0);
          if (el.dataset.pasek) el.style.width = `${Math.min(100, ((teraz() - start) / (Number(el.dataset.koniec) - start)) * 100)}%`;
          else el.textContent = zostalo > 0 ? odliczanie(zostalo) : 'teraz';
          if (zostalo <= 0 && el.dataset.przeladuj) koniec = true;
        });
        // Jedno przeładowanie naraz i nie częściej niż co 3 s (zegar tabletu może o sekundę wyprzedzać serwer).
        if (koniec && !przeladowanie && Date.now() - ostatniePrzeladowanie > 3000) {
          przeladowanie = true;
          ostatniePrzeladowanie = Date.now();
          wczytaj().finally(() => { przeladowanie = false; });
        }
      }, 1000);
    }

    function rysuj() {
      if (!d.gdzie) {
        wyczysc(tresc).append(h('div.panel', h('div.pusto', 'Brak sygnału pozycjonowania. Nadajnik nie ustalił twojego położenia.')));
        return;
      }
      const [typNazwa, znak] = TYPY[d.gdzie.typ] || TYPY.inne;
      wyczysc(tresc).append(...[
        // Gdzie jestem
        h('div.panel.nawi-tu',
          h('div.rzad',
            h('div.nawi-znak', znak),
            h('div.rozpycha',
              h('div.slaby.maly-tekst', `${d.planeta?.nazwa || '?'} · ${typNazwa}`),
              h('div', { style: 'font-size:20px;color:var(--akcent)' }, d.gdzie.nazwa)),
            h('div', { style: 'text-align:right' },
              h('div.maly-tekst', `Gotówka: ${kr(d.gotowka)}`),
              h('div.slaby.maly-tekst', `Rachunek: ${kr(d.saldo)}`)))),
        d.podroz ? podroz() : null,
        akcjeWMiejscu(),
        d.statki.length ? statki() : null,
        d.moje_statki?.length ? mojeStatki() : null,
        d.podroz || d.poklad?.w_locie ? null : dokad(),
        d.pojazdy.length ? pojazdy() : null,
      ].filter(Boolean)); // natywne append zamieniłoby null na tekst „null”
      tykaj();
    }

    function podroz() {
      const p = d.podroz;
      return h('div.panel', { style: 'border-color:var(--akcent-2)' },
        h('h2', `W drodze → ${p.cel_nazwa}`),
        h('div.slaby.maly-tekst', `${p.srodek} · ${czasSwiata(p.minuty)} czasu lokalnego`),
        h('div.pasek-postepu', h('i', { 'data-koniec': p.koniec, 'data-start': p.start, 'data-pasek': '1', style: 'background:var(--akcent-2)' })),
        h('div', 'Na miejscu za ', h('b', { 'data-koniec': p.koniec, 'data-przeladuj': '1' }, odliczanie(p.koniec - teraz()))));
    }

    // Bankomat, sklep, zejście z pokładu.
    function akcjeWMiejscu() {
      const el = [];
      if (d.poklad) el.push(poklad(d.poklad));
      if (d.poklad_okret) el.push(pokladOkretu(d.poklad_okret));
      if (d.bankomat) {
        const kwota = h('input', { type: 'number', min: 1, step: 1, placeholder: 'kwota' });
        el.push(h('div.panel', h('h2', 'Bankomat'),
          h('div.rzad', h('div', { style: 'width:160px' }, kwota),
            h('button.przycisk', { onclick: () => akcja('bankomat', { kwota: Math.floor(Number(kwota.value)) }, 'Wypłacono gotówkę.') }, 'Wypłać'),
            h('button.przycisk.drugi', { onclick: () => akcja('bankomat', { kwota: -Math.floor(Number(kwota.value)) }, 'Wpłacono gotówkę.') }, 'Wpłać'))));
      }
      if (d.sklep?.length) {
        el.push(h('div.panel', h('h2', 'Oferta'), h('ul.lista', d.sklep.map((t, i) => h('li', h('div.rzad',
          h('span.rozpycha', t.nazwa), h('span', kr(t.cena)),
          h('button.przycisk.maly', { onclick: () => akcja('sklep_kup', { pozycja: i, platnosc: 'karta' }, `Kupiono: ${t.nazwa}.`) }, 'Kartą'),
          h('button.przycisk.drugi.maly', { onclick: () => akcja('sklep_kup', { pozycja: i, platnosc: 'gotowka' }, `Kupiono: ${t.nazwa}.`) }, 'Gotówką')))))));
      }
      return el.length ? h('div', el) : null;
    }

    // Pokład: start, wznoszenie, orbita, współrzędne skoku, lądowanie.
    function poklad(p) {
      const panel = (...x) => h('div.panel', { style: 'border-color:var(--akcent)' }, h('h2', `Pokład: ${p.nazwa}`), ...x,
        panelZgod({ filtr: (o) => o.klucz.startsWith('s:') }));
      const paliwo = h('div.slaby.maly-tekst', `Paliwo: ${p.paliwo}%`);
      if (!p.w_locie) {
        return panel(paliwo,
          h('div.rzad', { style: 'margin-top:10px' },
            p.gotowy
              ? h('button.przycisk', { onclick: () => { if (confirm('Start? Wszyscy na pokładzie lecą razem.')) akcja('start', {}, 'Start! Wznoszenie przez atmosferę.'); } }, 'Start')
              : h('span.slaby', 'Statek nie przeszedł obsługi naziemnej.'),
            h('button.przycisk.drugi', { onclick: () => akcja('zejdz_z_pokladu', {}, 'Zszedłeś do hangaru.') }, 'Zejdź z pokładu')));
      }
      if (['wejscie', 'nadswietlna'].includes(p.scena) || p.cel) {
        return panel(paliwo, h('p', p.eta
          ? `Lot w nadprzestrzeni. Komputer nawigacyjny szacuje wyjście na ${czasGry(p.eta)}.`
          : 'Lot w nadprzestrzeni. Wyjście z nadprzestrzeni ustala kontrola lotu.'));
      }
      const wznoszenie = p.scena === 'start' && p.od_zmiany_ms < p.wznoszenie_ms;
      if (wznoszenie) {
        const koniec = teraz() + (p.wznoszenie_ms - p.od_zmiany_ms) / 1000;
        return panel(paliwo, h('p', 'Wznoszenie przez atmosferę. Na orbicie za ', h('b', { 'data-koniec': koniec, 'data-przeladuj': '1' }, odliczanie(koniec - teraz()))));
      }
      const wsp = h('input', { placeholder: 'np. R-4 · 112.07 / -33.90', spellcheck: false, autocomplete: 'off' });
      return h('div',
        panel(paliwo,
          h('p.slaby.maly-tekst', 'Statek na orbicie. Komputer nawigacyjny czeka na współrzędne skoku.'),
          h('div.rzad', h('div.pole.rozpycha', { style: 'margin:0' }, h('label', 'Współrzędne skoku'), wsp),
            h('button.przycisk', { style: 'align-self:flex-end', onclick: () => akcja('skok', { wspolrzedne: wsp.value }, (r) => `Obliczono skok. Kurs: ${r.cel}.${r.eta ? ` Wyjście z nadprzestrzeni: ${czasGry(r.eta)}.` : ''}`) }, 'Skok'))),
        ladowanie(p),
        lacznosc(p));
    }

    // Lądowanie: autopilot (znane lądowiska / przydział kontroli) albo ręcznie po współrzędnych.
    function ladowanie(p) {
      const wybor = h('select', { style: 'min-height:44px;border:1px solid var(--linia);border-radius:6px;padding:0 10px;width:100%' },
        p.ladowiska.map((l) => h('option', { value: l.klucz }, `${l.przydzial ? '★ PRZYDZIAŁ: ' : ''}${l.nazwa} (${(TYPY[l.typ] || TYPY.inne)[0].toLowerCase()})`)));
      const reczne = h('input', { placeholder: 'x / y   np. 12.40 / -3.15', spellcheck: false, autocomplete: 'off', inputmode: 'decimal' });
      return h('div.panel',
        h('h2', 'Lądowanie'),
        h('div.dwie-kolumny', { style: 'grid-template-columns:1fr 1fr' },
          h('div',
            h('div.slaby.maly-tekst', { style: 'margin-bottom:6px' }, 'Autopilot — znane lądowiska'),
            p.ladowiska.length
              ? h('div', wybor, h('button.przycisk', { style: 'margin-top:8px', onclick: () => akcja('ladowanie', { tryb: 'auto', cel: wybor.value }, 'Autopilot posadził statek.') }, 'Ląduj automatycznie'))
              : h('div.slaby', 'Autopilot nie zna tu żadnego lądowiska.')),
          h('div',
            h('div.slaby.maly-tekst', { style: 'margin-bottom:6px' }, 'Ręcznie — współrzędne lądowiska'),
            h('div.pole', { style: 'margin:0' }, reczne),
            h('button.przycisk.drugi', { style: 'margin-top:8px', onclick: () => akcja('ladowanie', { tryb: 'reczne', wspolrzedne: reczne.value }, 'Lądowanie ręczne zakończone.') }, 'Podejście ręczne'))));
    }

    // Łączność radiowa z kontrolą lotów planety.
    function lacznosc(p) {
      if (!p.kontrola) return h('div.panel', h('h2', 'Kontrola lotów'), h('div.slaby', 'Na tej częstotliwości cisza. Planeta nie ma kontroli lotów.'));
      const tresc = h('input', { placeholder: 'np. Frachtowiec Szary Wróbel, prosimy o lądowisko. Ładunek: zboże.' });
      const wiad = p.watek?.wiadomosci || [];
      return h('div.panel',
        h('div.rzad', h('h2.rozpycha', { style: 'margin:0' }, p.kontrola), p.watek?.czeka ? h('span.maly-tekst.status-niepewny', 'oczekiwanie na odpowiedź') : null),
        wiad.length
          ? h('ul.lista', { style: 'margin-top:8px' }, wiad.map((x) => h('li',
            h('div.slaby.maly-tekst', `${new Date(x.czas).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' })} · ${x.autor}`),
            h('div', { style: x.od === 'kontrola' ? 'color:var(--akcent)' : '' }, x.tresc))))
          : h('div.slaby', { style: 'margin-top:8px' }, 'Brak łączności. Możesz czekać na wywołanie albo nadać pierwszy.'),
        h('div.rzad', { style: 'margin-top:10px' }, h('div.pole.rozpycha', { style: 'margin:0' }, tresc),
          h('button.przycisk', { onclick: () => akcja('lacznosc_wyslij', { tresc: tresc.value }, 'Nadano.') }, wiad.length ? 'Nadaj' : 'Wywołaj kontrolę lotów')));
    }

    // Statki firm postaci na tej planecie: przygotowanie do lotu.
    // Prywatne statki z lokalizatorem: gdzie są (w zasięgu) albo ostatnia znana pozycja;
    // na lądowisku tej samej planety — trasa i wejście na pokład.
    function mojeStatki() {
      return h('div.panel', h('h2', 'Twoje statki'), h('ul.lista', d.moje_statki.map((s) => {
        let stan;
        if (s.sygnal === 'aktywny') stan = h('div.slaby.maly-tekst', `Lokalizator: ${s.pozycja}`);
        else stan = h('div.slaby.maly-tekst', (s.sygnal === 'brak' ? 'Lokalizator nie odpowiada.' : `Poza zasięgiem lokalizatora (${s.zasieg} pc).`)
          + (s.ostatnia ? ` Ostatnia pozycja: ${s.ostatnia}${s.ostatnia_czas ? ` · ${czasGry(s.ostatnia_czas)}` : ''}.` : ''));
        const l = s.ladowisko;
        const akcjaStatku = l
          ? (l.na_miejscu
            ? h('button.przycisk', { onclick: () => akcja('statek_wejdz', { obiekt_id: s.id }, `Jesteś na pokładzie ${s.nazwa}.`) }, 'Wejdź na pokład')
            : h('button.przycisk.drugi', { onclick: () => trasa(l.klucz, l.nazwa) }, l.km != null ? `Trasa (${l.km} km)` : 'Trasa'))
          : null;
        return h('li', h('div.rzad', h('div.rozpycha', h('div', `${s.nazwa}`, h('span.slaby.maly-tekst', s.klasa ? ` · ${s.klasa}` : '')), stan), akcjaStatku));
      })));
    }

    // Prom: lot na planetę (wylot z hangaru, dolot na bliską orbitę i lądowanie jednym rozkazem) albo sam wylot.
    function rozkazyWylotu(prom, ladowiska) {
      const wybor = h('select', ladowiska.map((l) => h('option', { value: l.id }, l.nazwa)));
      return h('div', { style: 'margin-top:8px' },
        ladowiska.length
          ? h('div.rzad', h('div.pole.rozpycha', { style: 'margin:0' }, wybor),
            h('button.przycisk', { onclick: () => { if (confirm(`Lot promem „${prom.nazwa}” na lądowisko? Wszyscy na pokładzie promu lecą razem.`)) akcja('prom_wylot', { obiekt_id: prom.id, ladowisko_id: Number(wybor.value) }, (r) => r.komunikat); } }, 'Lot na planetę'))
          : null,
        h('div.rzad', { style: 'margin-top:8px' },
          h('button.przycisk.drugi', { onclick: () => { if (confirm(`Wylot „${prom.nazwa}” z hangaru? Dalej: mapa galaktyki → panel floty (skok, lot, lądowanie).`)) akcja('prom_wylot', { obiekt_id: prom.id }, (r) => r.komunikat); } }, 'Wylot z hangaru')));
    }

    function stanPromu(prom) {
      if (prom.w_hangarze) return `w hangarze ${prom.okret}`;
      if (prom.na_ladowisku) return 'na lądowisku';
      if (prom.cel_ladowania) return `leci na lądowisko: ${prom.cel_ladowania}`;
      return prom.przy_okrecie ? 'na orbicie przy okręcie' : 'poza okrętem';
    }

    // Hangar okrętu (promy) albo panel promu, na którego pokładzie jest postać.
    function panelPromow(p) {
      if (p.prom) {
        const prom = p.prom;
        return h('div', { style: 'margin-top:10px' },
          h('div.slaby.maly-tekst', `Prom ${prom.klasa || ''} · ${stanPromu(prom)}`),
          prom.w_hangarze
            ? h('div', h('div.rzad', { style: 'margin-top:8px' },
              h('button.przycisk.drugi', { onclick: () => akcja('prom_wyjdz', {}, `Jesteś na pokładzie ${prom.okret}.`) }, 'Wyjdź do hangaru')),
              prom.kapitan ? rozkazyWylotu(prom, p.ladowiska || []) : null)
            : (prom.kapitan && prom.przy_okrecie
              ? h('div.rzad', { style: 'margin-top:8px' },
                h('button.przycisk', { onclick: () => akcja('prom_powrot', { obiekt_id: prom.id }, (r) => r.komunikat) }, `Powrót do hangaru ${prom.okret}`))
              : null));
      }
      if (!p.promy?.length) return null;
      const wn = p.wnetrze;
      const wHangarze = !wn || (!wn.przejscie && wn.tu.id === wn.hangar_promow);
      return h('div', { style: 'margin-top:12px' }, h('h3', 'Hangar'),
        wHangarze ? null : doPromu(wn),
        h('ul.lista', p.promy.map((prom) => h('li',
          h('div.rzad', h('div.rozpycha', h('div', prom.nazwa, h('span.slaby.maly-tekst', prom.klasa ? ` · ${prom.klasa}` : '')),
            h('div.slaby.maly-tekst', stanPromu(prom))),
            prom.w_hangarze && wHangarze ? h('button.przycisk.drugi', { onclick: () => akcja('prom_wejdz', { obiekt_id: prom.id }, `Jesteś na pokładzie ${prom.nazwa}.`) }, 'Wejdź do promu') : null),
          prom.w_hangarze && prom.kapitan ? rozkazyWylotu(prom, p.ladowiska || []) : null))));
    }

    const OKNA = { przod: 'okna mostka', lewa: 'iluminatory lewej burty', prawa: 'iluminatory prawej burty', hangar: 'wrota hangaru', brak: 'bez okien' };

    // Jeden sposób chodzenia po okręcie: każdy cel (lista, ulubione, skrót „Idź do promu”) to ten sam rozkaz poklad_idz.
    // Nowy cel w trakcie przejścia zastępuje poprzedni (serwer liczy od pomieszczenia, z którego się wyszło).
    const idzPoklad = (id) => akcja('poklad_idz', { cel: id }, (r) => (r.cel ? `Idziesz: ${r.cel} (${r.min} min).` : 'Zostajesz na miejscu.'));

    // Ulubione pomieszczenia: ustawienie konta na serwerze, kopia na urządzeniu.
    const kluczUlubionych = `hololink.ulubione.${ctx.uzytkownik.id ?? ctx.uzytkownik.postac}`;
    let ulubione = null;
    let ostatniWybor = null;
    async function wczytajUlubione() {
      try { ulubione = JSON.parse(localStorage.getItem(kluczUlubionych) || '[]').map(Number); } catch { ulubione = []; }
      try {
        const r = await api('ustawienie', undefined, { klucz: 'ulubione_poklad' });
        if (Array.isArray(r.wartosc)) ulubione = r.wartosc.map(Number);
      } catch { /* zostaje kopia z urządzenia */ }
    }
    function przelaczUlubione(id) {
      ulubione = ulubione.includes(id) ? ulubione.filter((x) => x !== id) : [...ulubione, id].slice(-40);
      try { localStorage.setItem(kluczUlubionych, JSON.stringify(ulubione)); } catch { /* bez pamięci */ }
      api('ustawienie_zapisz', { klucz: 'ulubione_poklad', wartosc: ulubione }).catch(() => {});
      rysuj();
    }

    // Skrót do hangaru promów: zwykły rozkaz przejścia z gotowym celem.
    function doPromu(wn) {
      const cel = wn?.hangar_promow;
      if (!cel) return h('div.slaby.maly-tekst', 'Promy stoją w hangarze promów — żeby wsiąść, najpierw tam dojdź.');
      if (wn.przejscie?.cel === cel) return h('div.slaby.maly-tekst', 'Idziesz do hangaru promów — tam wsiądziesz do promu.');
      const s = wn.skroty.find((x) => x.id === cel);
      return h('div.rzad', { style: 'margin-top:6px' }, h('span.slaby.maly-tekst.rozpycha', 'Promy stoją w hangarze promów.'),
        h('button.przycisk.drugi', { onclick: () => idzPoklad(cel) }, `Idź do promu${s ? ` (${s.min} min)` : ''}`));
    }

    // Wnętrze okrętu: gdzie jestem, przejście w toku, skróty, ulubione i pełna lista pomieszczeń (po pokładach).
    function panelWnetrza(wn) {
      if (!wn) return null;
      if (ulubione === null) { ulubione = []; wczytajUlubione().then(() => { if (w.isConnected && d) rysuj(); }); }
      const grupy = new Map();
      for (const x of wn.pomieszczenia) { if (!grupy.has(x.grupa)) grupy.set(x.grupa, []); grupy.get(x.grupa).push(x); }
      const ulub = wn.pomieszczenia.filter((x) => ulubione.includes(x.id));
      const opcja = (x) => h('option', { value: x.id }, `${ulubione.includes(x.id) ? '★ ' : ''}${x.nazwa} · ${x.min} min`);
      const wybor = h('select', { 'aria-label': 'Pomieszczenie docelowe' },
        ...[ulub.length ? h('optgroup', { label: '★ Ulubione' }, ulub.map(opcja)) : null,
          ...[...grupy].map(([g, lista]) => h('optgroup', { label: g || 'Okręt' }, lista.map(opcja)))].filter(Boolean));
      if (ostatniWybor && wn.pomieszczenia.some((x) => x.id === ostatniWybor)) wybor.value = String(ostatniWybor);
      const gwiazdka = h('button.przycisk.drugi', { type: 'button', style: 'min-width:44px;padding:0 10px' });
      const ustawGwiazdke = () => {
        const jest = ulubione.includes(Number(wybor.value));
        gwiazdka.textContent = jest ? '★' : '☆';
        gwiazdka.title = jest ? 'Usuń z ulubionych' : 'Dodaj do ulubionych';
      };
      wybor.addEventListener('change', () => { ostatniWybor = Number(wybor.value); ustawGwiazdke(); });
      gwiazdka.onclick = () => { ostatniWybor = Number(wybor.value); przelaczUlubione(Number(wybor.value)); };
      ustawGwiazdke();
      const p = wn.przejscie;
      // Skróty: hangar promów i mostek (z serwera) oraz ulubione, bez powtórzeń.
      const skroty = [...wn.skroty, ...ulub.filter((x) => !wn.skroty.some((s) => s.id === x.id)).map((x) => ({ ...x, nazwa: `★ ${x.nazwa}` }))];
      return h('div', { style: 'margin-top:10px' },
        h('div', h('span.slaby.maly-tekst', p ? 'Wyszedłeś z: ' : 'Jesteś: '), h('b', wn.tu.nazwa),
          h('span.slaby.maly-tekst', ` · ${wn.tu.grupa}${p ? '' : ` · ${OKNA[wn.tu.okno] || ''}`}`)),
        p ? h('div.rzad', { style: 'margin-top:6px' },
          h('span.rozpycha', `Idziesz: ${p.cel_nazwa} — na miejscu za `, h('b', { 'data-koniec': p.do, 'data-przeladuj': '1' }, odliczanie(p.do - teraz()))),
          h('button.przycisk.drugi.maly', { onclick: () => idzPoklad(wn.tu.id) }, 'Zawróć')) : null,
        skroty.length ? h('div.rzad', { style: 'margin-top:8px;flex-wrap:wrap' }, skroty.map((x) =>
          h('button.przycisk.drugi', { disabled: p?.cel === x.id, onclick: () => idzPoklad(x.id) }, `→ ${x.nazwa} (${x.min} min)`))) : null,
        h('div.rzad', { style: 'margin-top:8px' }, h('div.pole.rozpycha', { style: 'margin:0' }, wybor), gwiazdka,
          h('button.przycisk', { onclick: () => idzPoklad(Number(wybor.value)) }, p ? 'Zmień cel' : 'Idź')));
    }

    // Na pokładzie okrętu z SQL (np. prywatnego statku): zejście na lądowisko i start; hangar z promami.
    function pokladOkretu(p) {
      return h('div.panel', { style: 'border-color:var(--akcent)' }, h('h2', `Pokład: ${p.nazwa}`),
        p.prom?.w_hangarze ? null : p.na_ladowisku
          ? h('div', h('div.slaby.maly-tekst', `Na lądowisku: ${p.ladowisko || '—'}`),
            h('div.rzad', { style: 'margin-top:10px' },
              p.kapitan ? h('button.przycisk', { onclick: () => { if (confirm('Start? Wszyscy na pokładzie lecą razem.')) akcja('mapa_flota_start', { obiekt_id: p.id }, 'Start! Wznoszenie przez atmosferę.'); } }, 'Start') : null,
              h('button.przycisk.drugi', { onclick: () => akcja('statek_zejdz', {}, 'Zszedłeś na lądowisko.') }, 'Zejdź z pokładu')))
          : h('div.slaby.maly-tekst', 'Statek w locie. Kurs, skok, dolot i lądowanie: mapa galaktyki → karta planety.'),
        panelWnetrza(p.wnetrze),
        panelPromow(p));
    }

    function statki() {
      return h('div', d.statki.map((s) => {
        const pr = s.przygotowanie;
        const gotowy = pr && pr.gotowy <= teraz();
        const trwa = pr && !gotowy;
        const o = s.obsluga;
        const gwiazdki = '★'.repeat(s.hangar.jakosc) + '☆'.repeat(5 - s.hangar.jakosc);
        return h('div.panel.nawi-statek',
          h('div.rzad', h('h2.rozpycha', { style: 'margin:0' }, s.nazwa), h('span.slaby.maly-tekst', s.firma)),
          h('table.tabela', { style: 'margin-top:8px' },
            h('tr', h('th', 'Hangar'), h('td', `${s.hangar.nazwa} · obsługa ${gwiazdki}`, s.na_miejscu ? h('span.status-dostepny', '  (jesteś tutaj)') : h('span.slaby', `  · ${s.km} km stąd`))),
            h('tr', h('th', 'Paliwo'), h('td', h('span.licznik-paliwa', h('i', { style: `width:${s.paliwo}%` })), ` ${s.paliwo}%`)),
            h('tr', h('th', 'Stan'), h('td', s.stan))),
          trwa
            ? h('div', { style: 'margin-top:10px' }, 'Obsługa naziemna: gotowy za ', h('b', { 'data-koniec': pr.gotowy, 'data-przeladuj': '1' }, odliczanie(pr.gotowy - teraz())),
              h('div.pasek-postepu', h('i', { 'data-koniec': pr.gotowy, 'data-start': pr.start, 'data-pasek': '1' })),
              s.na_miejscu ? null : h('button.przycisk.drugi', { onclick: () => trasa(s.hangar.klucz, s.hangar.nazwa) }, `Jedź do hangaru (${s.km} km)`))
            : gotowy
              ? h('div.rzad', { style: 'margin-top:10px' }, h('span.status-dostepny', 'Gotowy do startu.'),
                s.na_miejscu ? h('button.przycisk', { onclick: () => akcja('na_poklad', { statek: s.klucz }, 'Jesteś na pokładzie.') }, 'Wejdź na pokład')
                  : h('button.przycisk', { onclick: () => trasa(s.hangar.klucz, s.hangar.nazwa) }, 'Jedź do hangaru'))
              : h('div', { style: 'margin-top:10px' },
                h('div.slaby.maly-tekst', `Obsługa: ${czasSwiata(o.baza)} kontroli` + (o.tankowanie ? ` + ${czasSwiata(o.tankowanie)} tankowania (${o.brak}%)` : '')
                  + (o.dowoz ? ` + ${czasSwiata(o.dowoz)} dowozu paliwa (hangar ma za mały zapas)` : '') + (o.koszt ? ` · paliwo ${kr(o.koszt)} z rachunku firmy` : '')),
                h('div.rzad', { style: 'margin-top:8px' },
                  h('button.przycisk', {
                    disabled: s.stan !== 'sprawny',
                    onclick: () => akcja('statek_przygotuj', { statek: s.klucz }, (r) => `Hangar przyjął zlecenie. ${s.nazwa} będzie gotowy za ${czasSwiata(r.minuty)} czasu lokalnego.`),
                  }, `Przygotuj ${s.nazwa} do lotu`),
                  s.na_miejscu ? null : h('button.przycisk.drugi', { onclick: () => trasa(s.hangar.klucz, s.hangar.nazwa) }, 'Jedź do hangaru'))));
      }));
    }

    function dokad() {
      if (!d.miejsca.length) return h('div.panel', h('h2', 'Dokąd?'), h('div.slaby', 'Nie znasz tu żadnych innych miejsc.'));
      return h('div.panel', h('h2', 'Dokąd?'), h('ul.lista', d.miejsca.map((m) => {
        const [typNazwa, znak] = TYPY[m.typ] || TYPY.inne;
        return h('li', h('div.rzad',
          h('span.nawi-znak.maly', znak),
          h('div.rozpycha', h('div', m.nazwa), h('div.slaby.maly-tekst', `${typNazwa} · ${m.km} km`)),
          h('button.przycisk.drugi.maly', { onclick: () => trasa(m.klucz, m.nazwa) }, 'Trasa')));
      })));
    }

    function pojazdy() {
      return h('div.panel', h('h2', 'Pojazdy'), h('ul.lista', d.pojazdy.map((p) => h('li',
        h('div', p.nazwa, h('span.slaby.maly-tekst', `  ${p.typ} · ${p.predkosc} km/h${p.wlasciciel !== 'prywatny' ? ' · ' + p.wlasciciel : ''}${p.kierowca ? ' · kierowca: ' + p.kierowca : ''}`)),
        h('div.slaby.maly-tekst', `Stoi: ${p.gdzie}${p.km !== null ? ` (${p.km} km)` : ' (inna planeta)'}`)))));
    }

    // Opcje dojazdu do celu: pieszo, taksówka, pojazdy (sam / z kierowcą).
    async function trasa(klucz, nazwa) {
      ctx.ustawWstecz(() => { ctx.ustawWstecz(null); rysuj(); return true; });
      wyczysc(tresc).append(h('div.pusto', 'Wyznaczanie trasy...'));
      let opcje;
      try {
        ({ opcje } = await api('trasa', undefined, { cel: klucz }));
      } catch (e) {
        pokaz(e.message);
        rysuj();
        return;
      }
      const platnosc = h('select', { style: 'min-height:36px;border:1px solid var(--linia);border-radius:4px;padding:0 6px' },
        h('option', { value: 'karta' }, 'płacę kartą'), h('option', { value: 'gotowka' }, 'płacę gotówką'));
      wyczysc(tresc).append(h('div.panel',
        h('h2', `Trasa → ${nazwa}`),
        h('ul.lista', opcje.map((o) => h('li', h('div.rzad',
          h('div.rozpycha', h('div', o.nazwa), h('div.slaby.maly-tekst', o.opis)),
          h('span', czasSwiata(o.minuty)),
          o.koszt ? h('span.slaby', kr(o.koszt)) : null,
          o.koszt ? platnosc.cloneNode(true) : null,
          h('button.przycisk.maly', {
            onclick: (e) => {
              const sel = e.target.parentElement.querySelector('select');
              ctx.ustawWstecz(null);
              akcja('podroz', { cel: klucz, srodek: o.srodek, pojazd: o.pojazd || '', kierowca: !!o.kierowca, platnosc: sel?.value || 'karta' }, `W drodze: ${nazwa}.`);
            },
          }, o.srodek === 'taksowka' ? 'Zamów' : 'Jedź'))))),
        h('div', { style: 'margin-top:12px' }, h('button.przycisk.drugi', { onclick: () => { ctx.ustawWstecz(null); rysuj(); } }, 'Anuluj'))));
    }

    // Skrót z pulpitu: po wczytaniu od razu trasa do wskazanego miejsca.
    wczytaj().then(() => { if (ctx.parametr?.trasa && d?.gdzie && !d.podroz) trasa(ctx.parametr.trasa, ctx.parametr.nazwa); });
    return () => { clearInterval(zegar); clearInterval(odswiezanie); };
  },
};

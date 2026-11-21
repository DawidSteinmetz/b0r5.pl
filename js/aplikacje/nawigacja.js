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
          else el.textContent = zostalo > 0 ? odliczanie(zostalo) : el.dataset.opoznienie ? 'opóźnienie' : 'teraz';
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

    // Układ: nagłówek „gdzie jestem” i zakładki — Tutaj (co trwa, działania w miejscu), Idź po okręcie / Dokąd?,
    // Statek (start, lądowanie, skok, łączność, promy, stocznia), Statki i pojazdy. Zakładka zostaje po odświeżeniu.
    let zakladka = 'miejsce';
    function rysuj() {
      if (!d.gdzie) {
        wyczysc(tresc).append(h('div.panel', h('div.pusto', 'Brak sygnału pozycjonowania. Nadajnik nie ustalił twojego położenia.')));
        return;
      }
      const okr = d.poklad_okret, wn = okr?.wnetrze;
      // Pierwsza zakładka nosi nazwę miejsca, w którym jest postać (zamiast „Tutaj”).
      const nazwaMiejsca = (wn ? `${wn.tu.nazwa} — ${okr.nazwa}` : d.gdzie.nazwa) || 'Miejsce';
      const zak = [['miejsce', nazwaMiejsca.length > 34 ? `${nazwaMiejsca.slice(0, 33)}…` : nazwaMiejsca]];
      if (wn) zak.push(['idz', 'Idź po okręcie']);
      else if (!d.podroz && !d.poklad?.w_locie && d.miejsca.length) zak.push(['idz', 'Dokąd?']);
      if (d.poklad || okr) zak.push(['statek', 'Statek']);
      if (d.statki.length || d.moje_statki?.length || d.pojazdy.length) zak.push(['pojazdy', 'Statki i pojazdy']);
      if (!zak.some(([k]) => k === zakladka)) zakladka = 'miejsce';
      const tresci = {
        miejsce: () => [d.podroz ? podroz() : null, wn ? stanPrzejscia(wn) : null, wn ? panelInspekcji(wn.inspekcja) : null, akcjeWMiejscu(),
          !wn && !d.podroz && !d.poklad?.w_locie && d.miejsca.length ? h('div.panel', h('button.przycisk.drugi', { onclick: () => { zakladka = 'idz'; rysuj(); } }, `Dokąd? (${d.miejsca.length} znanych miejsc)`)) : null],
        idz: () => [wn ? listaPomieszczen(wn) : dokad()],
        statek: () => [d.poklad ? poklad(d.poklad) : null, okr ? pokladOkretu(okr) : null],
        pojazdy: () => [d.statki.length ? statki() : null, d.moje_statki?.length ? mojeStatki() : null, d.pojazdy.length ? pojazdy() : null],
      };
      wyczysc(tresc).append(...[kartaMiejsca(),
        zak.length > 1 ? h('div.zakladki.nawi-zakladki', zak.map(([k, n]) => h('button' + (k === zakladka ? '.aktywna' : ''), { onclick: () => { zakladka = k; rysuj(); } }, n))) : null,
        ...tresci[zakladka](),
      ].filter(Boolean)); // natywne append zamieniłoby null na tekst „null”
      tykaj();
    }

    // Nagłówek: gdzie jestem (na okręcie — pomieszczenie i pokład), portfel.
    function kartaMiejsca() {
      const [typNazwa, znak] = TYPY[d.gdzie.typ] || TYPY.inne;
      const okr = d.poklad_okret, wn = okr?.wnetrze;
      return h('div.panel.nawi-tu',
        h('div.rzad',
          h('div.nawi-znak', znak),
          h('div.rozpycha',
            h('div.slaby.maly-tekst', wn ? `${okr.nazwa} · ${wn.tu.grupa || 'okręt'}` : `${d.planeta?.nazwa || '?'} · ${typNazwa}`),
            h('div.nawi-nazwa', wn ? wn.tu.nazwa : d.gdzie.nazwa),
            wn ? h('div.slaby.maly-tekst', [OKNA[wn.tu.okno], d.planeta?.nazwa ? `przy: ${d.planeta.nazwa}` : null].filter(Boolean).join(' · ')) : null),
          h('div.nawi-portfel',
            h('div.maly-tekst', `Gotówka: ${kr(d.gotowka)}`),
            h('div.slaby.maly-tekst', `Rachunek: ${kr(d.saldo)}`))));
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
      // Statki na tym lądowisku (wejście na pokład) i awaryjny powrót na statek, z którego postać zeszła.
      if (d.statki_tutaj?.length) {
        el.push(h('div.panel', h('h2', 'Statki na lądowisku'), h('ul.lista', d.statki_tutaj.map((s) => h('li', h('div.rzad',
          h('div.rozpycha', s.nazwa, s.klasa ? h('span.slaby.maly-tekst', ` · ${s.klasa}`) : null),
          s.mozna ? h('button.przycisk', { onclick: () => akcja('statek_wejdz', { obiekt_id: s.id }, `Jesteś na pokładzie ${s.nazwa}.`) }, 'Wejdź na pokład')
            : h('span.slaby.maly-tekst', 'właz zamknięty')))))));
      }
      if (d.powrot) {
        const p = d.powrot;
        el.push(h('div.panel', h('h2', `Twój ostatni statek: ${p.nazwa}`),
          h('div.slaby.maly-tekst', p.powod || `Stoi na lądowisku: ${p.ladowisko || '—'}.`),
          p.powod ? null : h('button.przycisk', { style: 'margin-top:8px', onclick: () => akcja('statek_wejdz', { obiekt_id: p.id, powrot: 1 }, `Wróciłeś na pokład ${p.nazwa}.`) }, 'Wróć na pokład')));
      }
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
    // Po wyborze celu widok wraca na „Tutaj” (licznik przejścia, Zmień cel, Zawróć).
    const idzPoklad = (id) => { zakladka = 'miejsce'; return akcja('poklad_idz', { cel: id }, (r) => (r.cel ? `Idziesz: ${r.cel} (${r.min} min).` : 'Zostajesz na miejscu.')); };

    // Ulubione pomieszczenia: ustawienie konta na serwerze, kopia na urządzeniu.
    const kluczUlubionych = `hololink.ulubione.${ctx.uzytkownik.id ?? ctx.uzytkownik.postac}`;
    let ulubione = null;
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

    // Przejście w toku albo szybkie cele (skróty i ulubione) — w zakładce „Tutaj”.
    function stanPrzejscia(wn) {
      if (ulubione === null) { ulubione = []; wczytajUlubione().then(() => { if (w.isConnected && d) rysuj(); }); }
      const p = wn.przejscie;
      if (p) {
        return h('div.panel.nawi-w-drodze',
          h('div.rzad', h('div.rozpycha', h('div.slaby.maly-tekst', `Wyszedłeś z: ${wn.tu.nazwa}`),
            h('div', 'Idziesz: ', h('b', p.cel_nazwa))),
          h('b.nawi-licznik', { 'data-koniec': p.do, 'data-przeladuj': '1' }, odliczanie(p.do - teraz()))),
          h('div.pasek-postepu', h('i', { 'data-koniec': p.do, 'data-start': p.od, 'data-pasek': '1' })),
          h('div.rzad', { style: 'margin-top:10px' },
            h('button.przycisk.drugi', { onclick: () => { zakladka = 'idz'; rysuj(); } }, 'Zmień cel'),
            h('button.przycisk.drugi', { onclick: () => idzPoklad(wn.tu.id) }, 'Zawróć')));
      }
      const ulub = wn.pomieszczenia.filter((x) => ulubione.includes(x.id) && !x.zamkniete);
      const szybkie = [...wn.skroty, ...ulub.filter((x) => !wn.skroty.some((s) => s.id === x.id)).map((x) => ({ ...x, nazwa: `★ ${x.nazwa}` }))];
      if (!szybkie.length) return null;
      return h('div.panel', h('h2', 'Szybko'),
        h('div.nawi-chipy', szybkie.map((x) => h('button.przycisk.drugi.maly', { onclick: () => idzPoklad(x.id) }, `${x.nazwa} · ${x.min} min`))));
    }

    // Inspekcja załogi w obecnym pomieszczeniu: rozmowa ok. 15 min, potem test Przywództwa — PD dla ciebie
    // i zmiana morale grupy. Wynik ostatniej inspekcji widać do zamknięcia.
    function panelInspekcji(i) {
      if (!i) return null;
      const el = [];
      if (i.wynik) {
        const r = i.wynik;
        el.push(h('div.panel.nawi-inspekcja' + (r.sukces ? '.udana' : '.nieudana'),
          h('h2', `Inspekcja: ${r.grupa}`),
          h('p', r.opis),
          h('div.rzad', { style: 'flex-wrap:wrap;gap:16px' },
            h('span', `Morale: ${r.morale_zmiana > 0 ? '+' : ''}${r.morale_zmiana} → ${r.morale}`),
            h('span', `+${r.pd} PD`),
            h('span.slaby.maly-tekst', `Przywództwo: ${r.test.sukcesy} netto sukcesów, ${r.test.przewagi} przewag (${r.test.pula})`)),
          h('button.przycisk.drugi.maly', { style: 'margin-top:8px', onclick: () => akcja('inspekcja_zamknij', {}) }, 'Zamknij raport')));
      }
      if (i.trwa) {
        el.push(h('div.panel.nawi-w-drodze', h('div.rzad', h('div.rozpycha', h('div.slaby.maly-tekst', 'Inspekcja w toku'), h('div', 'Rozmawiasz: ', h('b', i.trwa.grupa))),
          h('b.nawi-licznik', { 'data-koniec': i.trwa.do, 'data-przeladuj': '1' }, odliczanie(i.trwa.do - teraz()))),
          h('div.pasek-postepu', h('i', { 'data-koniec': i.trwa.do, 'data-start': i.trwa.od, 'data-pasek': '1' })),
          h('div.slaby.maly-tekst', { style: 'margin-top:6px' }, 'Wyjście z pomieszczenia przerywa inspekcję.')));
      } else if (i.grupa) {
        el.push(h('div.panel', h('div.rzad',
          h('div.rozpycha', h('div', 'Załoga: ', h('b', i.grupa)), i.morale != null ? h('div.slaby.maly-tekst', `Morale: ${i.morale}/100`) : null,
            i.powod ? h('div.slaby.maly-tekst', i.powod) : null),
          i.mozna ? h('button.przycisk', { onclick: () => akcja('inspekcja_start', {}, (r) => `Inspekcja: ${r.grupa}. Wynik za ok. 15 minut.`) }, 'Inspekcja') : null)));
      }
      return el.length ? h('div', el) : null;
    }

    // Zakładka „Idź po okręcie”: wyszukiwarka i pomieszczenia po pokładach (zwijane), dotknięcie = idź.
    let filtrPom = '';
    const otwarteGrupy = new Set();
    function listaPomieszczen(wn) {
      if (ulubione === null) { ulubione = []; wczytajUlubione().then(() => { if (w.isConnected && d) rysuj(); }); }
      const p = wn.przejscie;
      const szukaj = h('input.nawi-szukaj', { placeholder: 'Szukaj: mesa, hangar, pokład 4…', value: filtrPom, spellcheck: false, autocomplete: 'off' });
      const lista = h('div.nawi-pom');
      const wiersz = (x) => h('div.nawi-pom-wiersz' + (x.zamkniete ? '.zamkniete' : '') + (p?.cel === x.id ? '.cel' : ''),
        h('button.nawi-pom-idz', { type: 'button', disabled: !!x.zamkniete, title: x.zamkniete || `Idź: ${x.nazwa}`, onclick: () => idzPoklad(x.id) },
          h('span.nawi-pom-nazwa', `${x.zamkniete ? '🔒 ' : ''}${x.nazwa}`),
          h('span.slaby.maly-tekst', p?.cel === x.id ? 'tu idziesz' : x.zamkniete ? 'zamknięte' : `${x.min} min`)),
        h('button.nawi-gwiazdka', { type: 'button', title: ulubione.includes(x.id) ? 'Usuń z ulubionych' : 'Dodaj do ulubionych', onclick: () => przelaczUlubione(x.id) },
          ulubione.includes(x.id) ? '★' : '☆'));
      const rysujListe = () => {
        filtrPom = szukaj.value;
        const f = filtrPom.trim().toLowerCase();
        const pasuje = wn.pomieszczenia.filter((x) => !f || x.nazwa.toLowerCase().includes(f) || (x.grupa || '').toLowerCase().includes(f));
        const ulub = pasuje.filter((x) => ulubione.includes(x.id));
        const grupy = new Map();
        for (const x of pasuje) { const g = x.grupa || 'Okręt'; if (!grupy.has(g)) grupy.set(g, []); grupy.get(g).push(x); }
        wyczysc(lista).append(
          ulub.length && !f ? h('div.nawi-pom-grupa', h('div.nawi-pom-tytul', '★ Ulubione'), ulub.map(wiersz)) : null,
          ...[...grupy].map(([g, l]) => {
            if (f) return h('div.nawi-pom-grupa', h('div.nawi-pom-tytul', g), l.map(wiersz));
            const el = h('details.nawi-pom-grupa', { open: otwarteGrupy.has(g) || g === wn.tu.grupa },
              h('summary', h('span', g), h('span.slaby.maly-tekst', ` ${l.length}${g === wn.tu.grupa ? ' · jesteś tutaj' : ''}`)), l.map(wiersz));
            el.addEventListener('toggle', () => { if (el.open) otwarteGrupy.add(g); else otwarteGrupy.delete(g); });
            return el;
          }),
          pasuje.length ? null : h('div.slaby', 'Nic nie pasuje.'));
      };
      szukaj.addEventListener('input', rysujListe);
      rysujListe();
      return h('div.panel', h('h2', p ? `Zmień cel (teraz: ${p.cel_nazwa})` : 'Idź do…'), szukaj, lista);
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
        panelPromow(p),
        panelStoczni(p),
        panelRadaru(p));
    }

    // Radar i transponder: zasięg radaru, co nadaje transponder (fałszywy — tylko przemytnicy i łowcy nagród;
    // kosztuje i obciąża hipernapęd, przestawienie trwa 30 min), komu udostępniono pozycję okrętu.
    function panelRadaru(p) {
      const el = h('div');
      api('radar_okret', undefined, { obiekt: p.id }).then((r) => {
        const t = r.transponder;
        const aktywny = t.falszywy && (!t.od || t.od <= teraz());
        const stanT = t.falszywy
          ? (aktywny ? `fałszywy: ${t.nazwa}${t.frakcja ? ` (${t.frakcja})` : ''}` : h('span', `przestawianie na: ${t.nazwa} · gotowe za `, h('b', { 'data-koniec': t.od }, odliczanie(t.od - teraz()))))
          : `prawdziwy: ${t.nazwa}`;
        let zmiana;
        if (r.zmiana_powod) zmiana = h('div.slaby.maly-tekst', `Zmiana transpondera: ${r.zmiana_powod}`);
        else {
          const nazwa = h('input', { placeholder: 'Nadawana nazwa (np. Lekki frachtowiec „Kestrel”)', maxlength: 120 });
          const frakcja = h('select', [['', 'brak (cywilny)'], ['neutralna', 'neutralna'], ['Imperium', 'Imperium'], ['Rebelia', 'Rebelia'], ['Syndykat', 'Syndykat']].map(([v, n]) => h('option', { value: v }, n)));
          zmiana = h('details', h('summary', 'Przeprogramuj transponder'),
            h('div.slaby.maly-tekst', `Koszt ${kr(r.koszt)} z rachunku, hipernapęd −10% sprawności. Nowa tożsamość działa po 30 min. Analiza sygnatury dużego okrętu może cię zdemaskować.`),
            h('div.pole', nazwa), h('div.pole', frakcja),
            h('button.przycisk', { onclick: () => { if (nazwa.value.trim() && confirm(`Przeprogramować transponder za ${kr(r.koszt)}?`)) akcja('radar_transponder', { obiekt_id: p.id, nazwa: nazwa.value.trim(), frakcja: frakcja.value }, 'Transponder przestawia się — nowa tożsamość za 30 min.'); } }, 'Przeprogramuj'));
        }
        const gracz = h('select', r.gracze.map((g) => h('option', { value: g.login }, g.postac || g.login)));
        const czas = h('select', [['1', '1 h'], ['24', '24 h'], ['0', 'do odwołania']].map(([v, n]) => h('option', { value: v }, n)));
        el.append(h('div', { style: 'margin-top:12px' }, h('h3', 'Radar i transponder'),
          h('table.tabela',
            h('tr', h('th', 'Zasięg radaru'), h('td', `${String(r.zasieg).replace('.', ',')} pc`)),
            h('tr', h('th', 'Transponder'), h('td', stanT))),
          t.falszywy ? h('button.przycisk.drugi', { style: 'margin-top:8px', onclick: () => akcja('radar_transponder_przywroc', { obiekt_id: p.id }, 'Transponder nadaje prawdziwą tożsamość.') }, 'Przywróć prawdziwy transponder') : null,
          zmiana,
          h('h3', { style: 'margin-top:12px' }, 'Udostępnienie pozycji'),
          r.udostepnienia.length ? h('ul.lista', r.udostepnienia.map((x) => h('li', h('div.rzad',
            h('span.rozpycha', x.postac), h('span.slaby.maly-tekst', x.do ? `do ${czasGry(x.do)}` : 'do odwołania'),
            h('button.przycisk.maly.drugi', { onclick: () => akcja('radar_cofnij', { obiekt_id: p.id, login: x.login }, `Cofnięto udostępnienie: ${x.postac}.`) }, 'Cofnij'))))) : h('div.slaby.maly-tekst', 'Pozycji tego okrętu nikomu nie udostępniono.'),
          r.gracze.length ? h('div.rzad', { style: 'margin-top:8px' }, gracz, czas,
            h('button.przycisk.maly', { onclick: () => akcja('radar_udostepnij', { obiekt_id: p.id, login: gracz.value, godziny: Number(czas.value) }, 'Pozycja udostępniona.') }, 'Udostępnij')) : null));
      }).catch(() => {});
      return el;
    }

    // Stocznia naprawcza (np. Kuat): „Oddaj do naprawy” jednym kliknięciem, potem prace podzespołów z prognozą.
    function panelStoczni(p) {
      const el = h('div');
      api('mapa_naprawa', undefined, { obiekt: p.id }).then((r) => {
        if (r.naprawa) {
          const n = r.naprawa;
          el.append(h('div', { style: 'margin-top:12px' }, h('h3', n.stocznia),
            h('ul.lista', n.naprawy.map((x) => h('li', h('div.rzad',
              h('span.rozpycha', `${x.nazwa} (od ${x.od_wartosci}%)`),
              h('span.slaby.maly-tekst', x.stan === 'w_toku' ? 'w toku' : 'w kolejce'),
              h('b', { 'data-koniec': x.koniec, 'data-opoznienie': '1' }, x.opoznienie ? 'opóźnienie' : odliczanie(x.koniec - teraz())))))),
            h('div.slaby.maly-tekst', `Prognoza całości: ${czasGry(n.koniec)} · stanowisk: ${n.stanowiska}. Prognoza stoczni nie jest gwarancją.`),
            p.kapitan ? h('button.przycisk.drugi', { style: 'margin-top:8px', onclick: () => { if (confirm('Odebrać okręt z doku przed końcem napraw? Prace w toku przepadną.')) akcja('mapa_naprawa_przerwij', { obiekt_id: p.id }, 'Okręt odebrany z doku.'); } }, 'Odbierz z doku') : null));
        } else if (r.mozna) {
          el.append(h('div', { style: 'margin-top:12px' }, h('h3', 'Stocznia naprawcza'),
            h('div.slaby.maly-tekst', `Uszkodzenia: ${r.uszkodzenia.map((x) => `${x.nazwa} ${x.wartosc}%`).join(', ')}`),
            p.kapitan ? h('button.przycisk', { style: 'margin-top:8px', onclick: () => akcja('mapa_naprawa_zglos', { obiekt_id: p.id }, 'Okręt w doku naprawczym — naprawy ruszyły.') }, 'Oddaj do naprawy')
              : h('div.slaby.maly-tekst', 'Do naprawy oddaje kapitan albo dowódca floty.')));
        }
      }).catch(() => {});
      return el;
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

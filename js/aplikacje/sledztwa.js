// Droid śledczy: konsola operatora droida detektywistycznego (konto wpisane w sledztwo_detektywi; MG nadaje rolę
// w zakładce Konta). Układ na cały ekran: plan piętra (skan DT-4, rysunek techniczny) zajmuje całą przestrzeń;
// u góry zakładki i sterowanie ruchem, z boku wysuwany panel (Lokal, Ekwipunek, Rozmowa, Akta, Dziennik), na dole
// pasek odczytu. Podłączenie do komputera (datapad, stanowisko, terminal) otwiera ekran komputera na całą przestrzeń.
// Projekt: narzedzia/sledztwa/PROJEKT.md. API: api/sledztwa.php. Gracz nigdy nie dostaje prawdy o sprawie ani
// informacji, który lokal jest fabularny.

import { h, wyczysc, czasGry } from '../rdzen/narzedzia.js';
import { api } from '../rdzen/api.js';

const STAN = {
  losowanie: 'Biuro przyjmuje zgłoszenie…',
  pisanie: 'Biuro kompletuje akta sprawy.',
  trwa: 'Śledztwo w toku.',
  porzucone: 'Sprawa porzucona.',
  wznowienie_prosba: 'Prośba o wznowienie czeka na decyzję.',
  rozwiazane: 'Sprawa zamknięta.',
  blad: 'Akta sprawy uszkodzone — biuro je odtwarza.',
};
const STAN_DROIDA = { czeka: 'czeka na polecenia', w_drodze: 'w drodze', rozmowa: 'prowadzi rozmowę', skan: 'skanuje otoczenie',
  dekodowanie: 'dekoduje dane', uszkodzony: 'uszkodzony' };
const KTO = { droid: 'Droid', gracz: 'Ty', system: 'Biuro', npc: 'Rozmówca' };
const STREFA = { parter: 'parter', pietro: 'piętro', gora: 'najwyższe piętro', techniczny: 'strefa techniczna' };
const RODZAJ_P = { datapad: 'datapad', stanowisko: 'stanowisko pracy', terminal: 'terminal', kostka: 'kostka danych', dokument: 'dokument', osobisty: 'przedmiot osobisty' };
const PODEJSCIA = [['oszustwo', 'Blef (Oszustwo)'], ['urok', 'Urok'], ['zastraszanie', 'Zastraszanie']];

// Daty zawsze w formacie gry: DD|MM|RR GG:MM (rok gry = rok realny − 2010) — czasGry() z gry głównej.
const zUtc = (t) => new Date(String(t).replace(' ', 'T') + 'Z');
const data = (t) => (t ? czasGry(zUtc(t)) : '');
const godz = (t) => (t ? zUtc(t).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }) : '');
const czas = (s) => { s = Math.max(0, Math.round(s)); const g = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), r = s % 60;
  return g ? `${g} h ${m} min` : m ? `${m} min ${String(r).padStart(2, '0')} s` : `${r} s`; };
const lokalTxt = (p) => (p === 'korytarz' ? 'korytarz' : `lokal ${p}`);

// SVG: h() tworzy tylko elementy HTML.
const NS = 'http://www.w3.org/2000/svg';
function s(tag, atr = {}, ...dzieci) {
  const el = document.createElementNS(NS, tag);
  for (const [k, v] of Object.entries(atr)) {
    if (v === null || v === undefined || v === false) continue;
    if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
    else el.setAttribute(k, v);
  }
  for (const d of dzieci.flat()) if (d !== null && d !== undefined && d !== false) el.append(d instanceof Node ? d : document.createTextNode(String(d)));
  return el;
}

// Geometria planu (jednostki skanu; pokój = 60 j.).
const P = 60, GLEB = 70, KOR = 36, WINDA = 44;
const ZASIEG_TERMINALA = 18;
// Podejścia w rozmowie (jak SLEDZTWO_PODEJSCIA_ROZMOWY w api/sledztwa.php): [klucz, napis, opis testu].
const PODEJSCIA_R = [
  ['grozba', 'Groźba', 'Zastraszanie: Wola droida przeciw Woli i Dyscyplinie rozmówcy'],
  ['negocjacje', 'Negocjacje', 'Wymiana: Prezencja i Negocjacje przeciw Prezencji i Negocjacjom'],
  ['urok', 'Urok', 'Zjednanie: Prezencja i Urok przeciw Prezencji i Opanowaniu'],
  ['blef', 'Blef', 'Oszustwo: Spryt przeciw Intelektowi i Czujności (mało rozgarniętych łatwo nabrać)'],
  ['autorytet', 'Autorytet', 'Przywództwo: powołanie się na biuro; Prezencja przeciw Woli i Dyscyplinie'],
]; // jak SLEDZTWO_ZASIEG_TERMINALA w api/sledztwa.php
const yPokoju = (strona) => (strona === 'gora' ? 0 : GLEB + KOR);

const przycisk = (napis, akcja, klasa = '.drugi.maly', atr = {}) => {
  const b = h(`button.przycisk${klasa}`, atr, napis);
  b.onclick = akcja;
  return b;
};

export default {
  id: 'sledztwa',
  nazwa: 'Droid śledczy',
  ikona: '<circle cx="20" cy="20" r="11"/><path d="M28 28l12 12"/><path d="M15 17h10M15 23h6"/>',
  // Ikona tylko dla detektywów (stan.detektyw ustawia pulpit po zapytaniu sledztwo_moje).
  dostep: (stan) => !!stan.detektyw,

  uruchom(w) {
    w.classList.add('widok-mapa');
    const korzen = h('div', { style: 'position:absolute;inset:0;display:flex;flex-direction:column;background:var(--tlo);color:var(--tekst)' });
    const gora = h('div', { style: 'display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:8px 12px;border-bottom:1px solid var(--linia);background:var(--panel-mocny)' });
    const obszar = h('div', { style: 'flex:1;position:relative;overflow:hidden;min-height:0' });
    const planBox = h('div', { style: 'position:absolute;inset:0;padding:10px' });
    const postepBox = h('div', { style: 'position:absolute;left:12px;right:12px;top:8px;pointer-events:none' });
    const komunikat = h('div', { style: 'position:absolute;left:50%;transform:translateX(-50%);top:36px;width:min(640px,90%);z-index:3' });
    const szuflada = h('div', { style: 'position:absolute;top:0;right:0;bottom:0;width:min(460px,94%);overflow-y:auto;padding:12px 14px;'
      + 'background:var(--panel-mocny);border-left:1px solid var(--linia-mocna);z-index:2', hidden: true });
    const dol = h('div', { style: 'padding:6px 12px;border-top:1px solid var(--linia);background:var(--panel-mocny);font-size:13px' });
    const komputerBox = h('div', { style: 'position:absolute;inset:0;z-index:10', hidden: true });
    obszar.append(planBox, postepBox, komunikat, szuflada);
    korzen.append(gora, obszar, dol, komputerBox);
    w.append(korzen);

    let moje = null, d = null, zakladka = 'skan', escK = null;
    let zegar = null, zegarSkanu = null, licznik = null, koniec = false, maSprawe = false;
    // zamiar: co zrobić po dotarciu (np. podłączyć się do terminala); animacja trasy; raport przeszukania lokalu.
    let zamiar = null, animacja = null, koniecDrogi = 0, calaDroga = 1, raport = null;
    const panel = (tytul, ...dzieci) => h('div.panel', h('h2', tytul), ...dzieci);
    const blad = (e) => h('div.komunikat.blad', e.message || String(e));
    const ok = (t) => h('div.komunikat.ok', t);
    const wiersz = (etykieta, wartosc) => h('tr', h('td.slaby', etykieta), h('td', wartosc ?? '—'));
    const pokaz = (el) => { wyczysc(komunikat).append(el); setTimeout(() => { if (komunikat.firstChild === el) wyczysc(komunikat); }, 9000); };
    const zawolaj = async (akcja, dane, sukces) => {
      try { const r = await api(akcja, dane); if (sukces) pokaz(ok(typeof sukces === 'function' ? sukces(r) : sukces)); await skanuj(); odswiez(); return r; }
      catch (e) { pokaz(blad(e)); return null; }
    };

    // ---------- ruch ----------

    async function idz(lokalizacja_id, poziom, pokoj, punkt = null) {
      try {
        await api('sledztwo_idz', { lokalizacja_id, poziom, pokoj, ...(punkt || {}) });
        raport = null;
        wyczysc(komunikat);
        await skanuj();
        odswiez();
      } catch (e) { pokaz(blad(e)); }
    }

    // ---------- plan piętra (rysunek techniczny na całą przestrzeń) ----------

    // Punkt w jednostkach planu z kliknięcia (10 j. = 1 m).
    const punktSvg = (svg, e) => {
      const m = svg.getScreenCTM();
      if (!m) return null;
      const p = new DOMPoint(e.clientX, e.clientY).matrixTransform(m.inverse());
      return { x: Math.round(p.x), y: Math.round(p.y) };
    };
    const odl = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

    // Terminal: blisko — podłączenie; daleko — droid podchodzi, a po dotarciu ekran otwiera się sam.
    function terminal(etykieta) {
      const t = d?.plan?.terminale?.[etykieta];
      const { droid } = d;
      if (!t) return otworzKomputer({ terminal: etykieta });
      if (droid.stan !== 'w_drodze' && droid.pokoj === t.pokoj && odl(droid, t) <= ZASIEG_TERMINALA) return otworzKomputer({ terminal: etykieta });
      zamiar = { terminal: etykieta };
      idz(d.plan.budynek_id, d.plan.poziom, t.pokoj, { x: t.podejscie_x, y: t.podejscie_y });
    }

    // Winda: wybór poziomu (parter / góra budynku albo dowolny numer).
    // Winda: droid najpierw do niej wchodzi; dopiero w środku panel boczny pokazuje listę pięter.
    const WINDA_XY = { x: -WINDA / 2, y: GLEB + KOR / 2 };
    const wWindzie = () => d && d.droid.stan !== 'w_drodze' && d.droid.pokoj === 'korytarz' && d.droid.x < 0;
    // Etap drogi na inne piętro: 'idzie' (do windy) albo 'jedzie' (winda w ruchu); null — droid nie jedzie windą.
    const postepDrogi = () => 1 - Math.max(0, (koniecDrogi - Date.now()) / 1000) / Math.max(1, calaDroga);
    function jazdaWindy() {
      if (!d || d.droid.stan !== 'w_drodze' || !d.droid.cel) return null;
      const inne = d.droid.cel.poziom !== d.plan.poziom || d.droid.cel.budynek_id !== d.plan.budynek_id;
      if (!inne) return null;
      return postepDrogi() >= (d.droid.trasa?.do ?? 0) ? 'jedzie' : 'idzie';
    }

    function winda() {
      if (jazdaWindy() === 'jedzie') { zakladka = 'winda'; rysujSzuflade(); return; }
      if (wWindzie()) { zakladka = 'winda'; rysujSzuflade(); rysujGore(); return; }
      zamiar = { winda: true };
      idz(d.plan.budynek_id, d.plan.poziom, 'korytarz', WINDA_XY);
    }

    function zakladkaWinda() {
      const { plan, poziom_max } = d;
      const jedz = (p) => { p = Number(p); if (p >= 1 && p <= poziom_max && p !== plan.poziom) { zamiar = { winda: true }; idz(plan.budynek_id, p, 'korytarz', WINDA_XY); } };
      const pietra = [];
      for (let p = plan.gora; p >= plan.parter; p--) pietra.push(p);
      const nr = h('input', { type: 'number', min: 1, max: poziom_max, placeholder: 'np. 2', style: 'width:80px' });
      nr.onkeydown = (e) => { if (e.key === 'Enter') jedz(nr.value); };
      return panel(`WINDA · ${plan.budynek}`,
        h('p.maly-tekst', `Droid w windzie, poziom ${plan.poziom}. Wybierz piętro — droid wysiądzie przy windzie na wybranym poziomie.`),
        h('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(52px,1fr));gap:4px;margin:8px 0' },
          pietra.map((p) => {
            const opis = p === plan.parter ? 'P' : p === plan.gora ? 'G' : '';
            return przycisk(`${p}${opis ? ` ${opis}` : ''}`, () => jedz(p), p === plan.poziom ? '.maly' : '.drugi.maly',
              { disabled: p === plan.poziom, title: p === plan.parter ? 'Parter' : p === plan.gora ? 'Najwyższy poziom budynku' : `Poziom ${p}` });
          })),
        h('div', { style: 'display:flex;gap:6px;align-items:center;flex-wrap:wrap' },
          h('span.slaby', 'Poziom techniczny (poza zakresem budynku):'), nr, przycisk('Jedź', () => jedz(nr.value))),
        h('div', { style: 'margin-top:10px' }, przycisk('Wyjdź z windy na korytarz', () => {
          zakladka = 'skan'; idz(plan.budynek_id, plan.poziom, 'korytarz', { x: 12, y: GLEB + KOR / 2 });
        })));
    }

    // Położenie droida w drodze: wzdłuż trasy (przez drzwi i korytarz), proporcjonalnie do czasu.
    function polozenieWDrodze() {
      const { droid } = d;
      const tr = droid.trasa;
      if (!tr || !tr.punkty?.length) return null;
      const postep = 1 - Math.max(0, (koniecDrogi - Date.now()) / 1000) / Math.max(1, calaDroga);
      // Na tym samym piętrze droid zostaje w punkcie docelowym (nie znika przed odświeżeniem skanu);
      // na inne piętro — znika w windzie.
      // Po dojściu: na tym samym piętrze — punkt docelowy; na inne piętro — droid stoi w windzie, dopóki jedzie.
      if (postep >= tr.do) { if (tr.do < 1) return { ...WINDA_XY }; const o = tr.punkty[tr.punkty.length - 1]; return { x: o[0], y: o[1] }; }
      const pkt = tr.punkty;
      const dl = [0];
      for (let i = 1; i < pkt.length; i++) dl.push(dl[i - 1] + Math.hypot(pkt[i][0] - pkt[i - 1][0], pkt[i][1] - pkt[i - 1][1]));
      const cel = (dl[dl.length - 1] * Math.max(0, postep)) / Math.max(0.0001, tr.do);
      for (let i = 1; i < pkt.length; i++) {
        if (dl[i] >= cel) {
          const u = (cel - dl[i - 1]) / Math.max(0.0001, dl[i] - dl[i - 1]);
          return { x: pkt[i - 1][0] + (pkt[i][0] - pkt[i - 1][0]) * u, y: pkt[i - 1][1] + (pkt[i][1] - pkt[i - 1][1]) * u };
        }
      }
      return { x: pkt[pkt.length - 1][0], y: pkt[pkt.length - 1][1] };
    }

    // W czasie jazdy windą piętro znika: widać tylko szyb, kabinę z droidem i licznik poziomów.
    function widokSzybu() {
      const { plan, droid } = d;
      const z = plan.poziom, na = droid.cel.poziom, start = droid.trasa?.do ?? 0;
      const W = 120, H = 260;
      const kabina = s('g', {},
        s('rect', { x: W / 2 - 22, y: -18, width: 44, height: 36, fill: 'currentColor', 'fill-opacity': 0.08, stroke: 'currentColor', 'stroke-width': 1.2 }),
        s('circle', { cx: W / 2, cy: 0, r: 6, fill: 'none', stroke: 'var(--ok)', 'stroke-width': 1.5 }),
        s('circle', { cx: W / 2, cy: 0, r: 2, fill: 'var(--ok)' }));
      const licznik = s('text', { x: W / 2, y: -34, 'text-anchor': 'middle', 'font-size': 14, fill: 'var(--ok)' }, String(z));
      const svg = s('svg', { viewBox: `-60 -40 ${W + 120} ${H + 80}`, width: '100%', height: '100%', preserveAspectRatio: 'xMidYMid meet',
        style: 'display:block;color:var(--akcent);font-family:var(--czcionka)' },
        s('rect', { x: W / 2 - 30, y: 0, width: 60, height: H, fill: 'none', stroke: 'currentColor', 'stroke-opacity': 0.5, 'stroke-dasharray': '3 3' }),
        s('text', { x: W / 2 + 40, y: 8, 'font-size': 9, fill: 'currentColor', 'fill-opacity': 0.7 }, `poziom ${Math.max(z, na)}`),
        s('text', { x: W / 2 + 40, y: H, 'font-size': 9, fill: 'currentColor', 'fill-opacity': 0.7 }, `poziom ${Math.min(z, na)}`),
        s('text', { x: W / 2, y: H + 30, 'text-anchor': 'middle', 'font-size': 10, fill: 'currentColor' },
          `WINDA · ${plan.budynek} · ${z} → ${na}${droid.cel.budynek_id !== plan.budynek_id ? ` · ${droid.cel.budynek}` : ''}`),
        kabina, licznik);
      const ustaw = () => {
        const u = Math.min(1, Math.max(0, (postepDrogi() - start) / Math.max(0.0001, 1 - start)));
        const poziomTeraz = Math.round(z + (na - z) * u);
        const gora = Math.max(z, na), dol = Math.min(z, na);
        const y = gora === dol ? H / 2 : ((gora - poziomTeraz) / (gora - dol)) * H;
        kabina.setAttribute('transform', `translate(0 ${y.toFixed(1)})`);
        licznik.setAttribute('y', (y - 26).toFixed(1));
        licznik.textContent = String(poziomTeraz);
      };
      ustaw();
      clearInterval(animacja);
      animacja = setInterval(() => { if (!svg.isConnected) clearInterval(animacja); else ustaw(); }, 100);
      return svg;
    }

    function planPietra() {
      if (jazdaWindy() === 'jedzie') return widokSzybu();
      const { plan, droid, kontakty, zasieg } = d;
      const szer = plan.dl || Math.max(plan.szerokosc, P * 4);
      const wys = GLEB * 2 + KOR;
      const wDrodze = droid.stan === 'w_drodze';
      const vb = [-WINDA - 16, -22, szer + WINDA + 32, wys + 44];
      const svg = s('svg', { viewBox: vb.join(' '), width: '100%', height: '100%', preserveAspectRatio: 'xMidYMid meet',
        style: 'display:block;color:var(--akcent);font-family:var(--czcionka);cursor:crosshair' },
        s('defs', {}, s('pattern', { id: 'dt4-siatka', width: 10, height: 10, patternUnits: 'userSpaceOnUse' },
          s('path', { d: 'M10 0H0V10', fill: 'none', stroke: 'currentColor', 'stroke-opacity': 0.08, 'stroke-width': 0.5 }))),
        s('rect', { x: vb[0], y: vb[1], width: vb[2], height: vb[3], fill: 'url(#dt4-siatka)' }));
      // Klik w dowolny punkt: droid idzie dokładnie tam (drzwiami i korytarzem, nie przez ściany).
      svg.addEventListener('click', (e) => {
        // Z jadącej windy nie da się wyjść.
        if (jazdaWindy() === 'jedzie') { zakladka = 'winda'; rysujSzuflade(); return; }
        const p = punktSvg(svg, e);
        if (p) { zamiar = null; idz(plan.budynek_id, plan.poziom, 'korytarz', p); }
      });
      if (!wDrodze) {
        svg.append(s('rect', { x: droid.x - zasieg, y: -14, width: zasieg * 2, height: wys + 28, fill: 'currentColor', 'fill-opacity': 0.05,
          stroke: 'currentColor', 'stroke-opacity': 0.35, 'stroke-dasharray': '4 4', 'pointer-events': 'none' }),
          s('text', { x: droid.x + zasieg - 3, y: -17, 'text-anchor': 'end', 'font-size': 6, fill: 'currentColor', 'fill-opacity': 0.5 }, `ZASIĘG ${zasieg / 10} m`));
      }
      svg.append(
        s('g', { style: 'cursor:pointer', onclick: (e) => { e.stopPropagation(); winda(); } },
          s('title', {}, 'Winda — kliknij, by wybrać poziom'),
          s('rect', { x: -WINDA, y: GLEB, width: WINDA, height: KOR, fill: 'currentColor', 'fill-opacity': 0.06, stroke: 'currentColor', 'stroke-opacity': 0.8 }),
          s('path', { d: `M${-WINDA} ${GLEB}L0 ${GLEB + KOR}M0 ${GLEB}L${-WINDA} ${GLEB + KOR}`, stroke: 'currentColor', 'stroke-opacity': 0.25 }),
          s('text', { x: -WINDA / 2, y: GLEB + KOR + 10, 'text-anchor': 'middle', 'font-size': 7, fill: 'currentColor' }, 'WINDA ▲▼')),
        s('line', { x1: 0, y1: GLEB, x2: szer, y2: GLEB, stroke: 'currentColor', 'stroke-opacity': 0.5 }),
        s('line', { x1: 0, y1: GLEB + KOR, x2: szer, y2: GLEB + KOR, stroke: 'currentColor', 'stroke-opacity': 0.5 }),
        s('text', { x: szer / 2, y: GLEB + KOR / 2 + 2, 'text-anchor': 'middle', 'font-size': 6, fill: 'currentColor', 'fill-opacity': 0.3, 'letter-spacing': 3,
          'pointer-events': 'none' }, 'KORYTARZ'));
      for (const p of plan.pokoje) {
        const y = yPokoju(p.strona);
        const wl = (p.szer || 1) * P;
        const sx = p.x + wl / 2;
        const tu = !wDrodze && droid.pokoj === p.nr;
        const cel = wDrodze && droid.cel && droid.cel.pokoj === p.nr && droid.cel.poziom === plan.poziom && droid.cel.budynek_id === plan.budynek_id;
        const wstep = p.zamkniete ? ' · zamknięty' : p.dostep === 'nakaz' ? ' · tylko z nakazem' : p.dostep === 'straz' ? ' · strzeżony' : '';
        const ramka = p.zamkniete || p.dostep === 'nakaz' ? 'var(--zly)' : p.dostep === 'straz' ? 'var(--akcent-2)' : 'currentColor';
        const yDrzwi = p.strona === 'gora' ? GLEB : GLEB + KOR;
        svg.append(s('g', {},
          s('title', {}, `Lokal ${p.nr} · ${p.rodzaj}${wstep} — kliknij miejsce, do którego ma podejść droid`),
          s('rect', { x: p.x + 1, y: y + 1, width: wl - 2, height: GLEB - 2, fill: tu || cel ? 'currentColor' : 'transparent',
            'fill-opacity': tu ? 0.14 : 0.07, stroke: ramka, 'stroke-opacity': p.zamkniete ? 0.5 : 0.9, 'stroke-width': 1.2,
            'stroke-dasharray': cel ? '3 2' : null }),
          s('text', { x: p.x + 5, y: y + 12, 'font-size': 9, fill: 'currentColor', 'pointer-events': 'none' }, p.nr),
          s('text', { x: p.x + 5, y: y + 22, 'font-size': 6, fill: 'currentColor', 'fill-opacity': 0.7, 'pointer-events': 'none' }, p.rodzaj.slice(0, 15 * (p.szer || 1))),
          p.dostep !== 'wolny' ? s('text', { x: p.x + wl - 6, y: y + GLEB - 7, 'text-anchor': 'end', 'font-size': 6.5, fill: ramka, 'pointer-events': 'none' },
            p.dostep === 'nakaz' ? 'NAKAZ' : 'STRAŻ') : null,
          p.zamkniete ? s('text', { x: p.x + 5, y: y + GLEB - 7, 'font-size': 6.5, fill: 'var(--zly)', 'pointer-events': 'none' }, 'ZAMEK') : null,
          s('line', { x1: sx - 9, y1: yDrzwi, x2: sx + 9, y2: yDrzwi, stroke: p.zamkniete ? 'var(--zly)' : 'var(--tlo)', 'stroke-width': 3.5 }),
          s('text', { x: sx, y: p.strona === 'gora' ? GLEB - 3 : GLEB + KOR + 9, 'text-anchor': 'middle', 'font-size': 6,
            fill: 'currentColor', 'fill-opacity': 0.6, 'pointer-events': 'none' }, String(p.drzwi))));
      }
      for (const [et, t] of Object.entries(plan.terminale || {})) {
        const blisko = !wDrodze && droid.pokoj === t.pokoj && odl(droid, t) <= ZASIEG_TERMINALA;
        svg.append(s('g', { style: 'cursor:pointer', onclick: (e) => { e.stopPropagation(); terminal(et); } },
          s('title', {}, `Terminal ${et}${t.pokoj === 'korytarz' ? ' (publiczny, korytarz)' : ` w lokalu ${t.pokoj}`} — ${blisko ? 'kliknij, by się podłączyć' : 'kliknij: droid podejdzie i się podłączy'}`),
          s('rect', { x: t.x - 5, y: t.y - 6, width: 10, height: 12, fill: 'var(--tlo)', stroke: 'var(--akcent-2)', 'stroke-width': blisko ? 1.6 : 1 }),
          s('text', { x: t.x, y: t.y + 15, 'text-anchor': 'middle', 'font-size': 6, fill: 'var(--akcent-2)' }, et)));
      }
      for (const k of kontakty) {
        const y = k.y ?? (k.strona === 'korytarz' ? (k.przy === 'gora' ? GLEB + 9 : GLEB + KOR - 9) : yPokoju(k.strona) + 46);
        const aktywny = d.rozmowa && d.rozmowa.kontakt === k.token;
        svg.append(s('g', { style: 'cursor:pointer', onclick: (e) => { e.stopPropagation(); zacznijRozmowe(k.token); } },
          s('title', {}, `${k.kod}: ${k.gatunek}, ${k.wyglad}${k.w_drzwiach ? ' — stoi w drzwiach' : ''} — kliknij: droid podejdzie na 2–3 m i zacznie rozmowę`),
          s('circle', { cx: k.x, cy: y, r: 8, fill: 'transparent' }),
          aktywny ? s('circle', { cx: k.x, cy: y, r: 6.5, fill: 'none', stroke: 'var(--ok)' }) : null,
          s('circle', { cx: k.x, cy: y, r: 3.5, fill: k.w_drzwiach ? 'var(--zly)' : 'var(--akcent-2)' }),
          s('text', { x: k.x + 5, y: y + 3, 'font-size': 6, fill: 'var(--akcent-2)' }, k.kod)));
      }
      // Droid: na miejscu albo w ruchu po trasie (animacja).
      const znacznik = s('g', { 'pointer-events': 'none' },
        s('circle', { cx: 0, cy: 0, r: 6, fill: 'none', stroke: 'var(--ok)', 'stroke-width': 1.5 }),
        s('circle', { cx: 0, cy: 0, r: 2, fill: 'var(--ok)' }),
        s('text', { x: 0, y: -9, 'text-anchor': 'middle', 'font-size': 6, fill: 'var(--ok)' }, d.oznaczenie || 'DT-4'));
      if (wDrodze && droid.trasa?.punkty?.length) {
        svg.append(s('polyline', { points: droid.trasa.punkty.map((q) => q.join(',')).join(' '), fill: 'none', stroke: 'var(--ok)',
          'stroke-opacity': 0.45, 'stroke-dasharray': '2 3', 'pointer-events': 'none' }));
      }
      svg.append(znacznik);
      let etapWindy = jazdaWindy();
      const ustaw = () => {
        const etap = jazdaWindy();
        if (etap !== etapWindy) {
          etapWindy = etap;
          if (zakladka === 'winda') rysujSzuflade();
          // Droid wsiadł do windy: piętro znika, w jego miejscu szyb.
          if (etap === 'jedzie') { wyczysc(planBox).append(planPietra()); return; }
        }
        const p = wDrodze ? polozenieWDrodze() : { x: droid.x, y: droid.y ?? GLEB + KOR / 2 };
        znacznik.style.display = p ? '' : 'none';
        if (p) znacznik.setAttribute('transform', `translate(${p.x.toFixed(1)} ${p.y.toFixed(1)})`);
      };
      ustaw();
      clearInterval(animacja);
      if (wDrodze) animacja = setInterval(() => { if (!svg.isConnected) clearInterval(animacja); else ustaw(); }, 100);
      return svg;
    }

    // ---------- górny pasek: tytuł, zakładki, sterowanie ----------

    function rysujGore() {
      wyczysc(gora);
      if (!d) return;
      const { plan, droid, budynki, poziom_max } = d;
      const liczba = (n) => (n ? ` (${n})` : '');
      const zakl = [['skan', 'Skan'], ['lokal', 'Lokal' + liczba((d.przedmioty || []).length + d.kontakty.length)],
        ['ekwipunek', 'Ekwipunek' + liczba((d.ekwipunek || []).length)], ...(rozmowa ? [['rozmowa', 'Rozmowa']] : []),
        ['notatnik', 'Notatnik' + liczba(d.tropy_ile)], ['akta', 'Akta'], ['dziennik', 'Dziennik']];
      // Cel: budynki rejonu i namiary od rozmówców (wybór namiaru wypełnia poziom i lokal).
      const namiary = d.namiary || [];
      const budynek = h('select', { style: 'max-width:260px' },
        h('optgroup', { label: 'Budynki' }, budynki.length ? budynki.map((b) => h('option', { value: b.id, selected: b.id === plan.budynek_id }, b.nazwa))
          : [h('option', { value: '' }, 'Kompleks')]),
        namiary.length ? h('optgroup', { label: 'Namiary od rozmówców' },
          namiary.map((n) => h('option', { value: `n${n.id}`, title: `Podał(a): ${n.skad}` }, `◆ ${n.nazwa}`))) : null);
      const poziom = h('input', { type: 'number', min: 1, max: poziom_max, value: plan.poziom, style: 'width:70px' });
      const lokal = h('input', { placeholder: 'lokal / korytarz', style: 'width:120px' });
      const namiar = () => (budynek.value.startsWith('n') ? namiary.find((n) => `n${n.id}` === budynek.value) : null);
      budynek.onchange = () => { const n = namiar(); if (n) { poziom.value = n.poziom; lokal.value = n.pokoj; } };
      const jedz = przycisk('Wyślij', () => {
        const n = namiar();
        if (n) return idz(n.lokalizacja_id, Number(poziom.value) || n.poziom, lokal.value.trim() || n.pokoj);
        idz(budynek.value === '' ? null : Number(budynek.value), Number(poziom.value), lokal.value.trim() || 'korytarz');
      }, '.maly');
      lokal.onkeydown = (e) => { if (e.key === 'Enter') jedz.click(); };
      gora.append(
        h('div', { style: 'flex:1 1 280px;min-width:0' },
          h('b', `${d.oznaczenie || 'DT-4'} · ${plan.budynek.toUpperCase()} · POZIOM ${plan.poziom}`),
          h('span.slaby', ` (${STREFA[plan.strefa] || plan.strefa}) · ${plan.preset_nazwa}: parter ${plan.parter}, góra ${plan.gora} · ${STAN_DROIDA[droid.stan] || droid.stan}`),
          d.miejsce_sprawy ? h('div.slaby', { style: 'font-size:12px' }, miejsceSprawy(d.miejsce_sprawy)) : null),
        h('div', { style: 'display:flex;gap:4px;flex-wrap:wrap' }, zakl.map(([k, n]) =>
          przycisk(n, () => { zakladka = zakladka === k && k !== 'skan' ? 'skan' : k; rysujSzuflade(); rysujGore(); }, zakladka === k ? '.maly' : '.drugi.maly'))),
        h('div', { style: 'display:flex;gap:6px;align-items:center;flex-wrap:wrap' }, budynek, h('span.slaby', 'poz.'), poziom, lokal, jedz));
    }

    function rysujPostep() {
      wyczysc(postepBox);
      clearInterval(licznik);
      const { droid } = d;
      if (droid.stan !== 'w_drodze') return;
      const pasek = h('div', { style: `height:4px;background:var(--akcent);width:${Math.round(droid.postep * 100)}%;transition:width 1s linear` });
      const ile = h('span', czas(droid.zostalo_s));
      let zostalo = droid.zostalo_s;
      const calosc = droid.zostalo_s / Math.max(0.0001, 1 - droid.postep);
      licznik = setInterval(() => {
        zostalo -= 1;
        ile.textContent = czas(zostalo);
        pasek.style.width = `${Math.min(100, Math.round((1 - Math.max(0, zostalo) / calosc) * 100))}%`;
        if (zostalo <= 0) { clearInterval(licznik); skanuj().then(odswiez); }
      }, 1000);
      postepBox.append(h('div', { style: 'background:var(--panel-mocny);border:1px solid var(--linia);padding:6px 10px;display:inline-block;max-width:100%' },
        h('div', `W drodze: ${droid.cel.budynek}, poziom ${droid.cel.poziom}, ${lokalTxt(droid.cel.pokoj)} · zostało `, ile),
        h('div', { style: 'height:4px;background:var(--linia);margin-top:4px' }, pasek)));
    }

    function rysujDol() {
      wyczysc(dol);
      if (!d) return;
      const ostatni = (d.meldunki || []).find((m) => m.kto === 'droid');
      const { droid } = d;
      const lacze = droid.stan === 'w_drodze'
        ? `w drodze · ${droid.cel.budynek}, poziom ${droid.cel.poziom}, ${lokalTxt(droid.cel.pokoj)}`
        : `łącze stabilne · ${droid.budynek}, poziom ${droid.poziom}, ${lokalTxt(droid.pokoj)} · kontakty w zasięgu: ${d.kontakty.length}`;
      dol.append(h('div', h('b', `${d.oznaczenie || 'DT-4'} › `), ostatni ? `${godz(ostatni.kiedy_utc)} ${ostatni.tresc}` : 'Gotowy.'),
        h('div.slaby', h('b', 'ŁĄCZE › '), lacze));
    }

    // ---------- panel boczny ----------

    function wstep() {
      const { plan, droid, zasieg, nakazy = [] } = d;
      const wZasiegu = (p) => Math.abs(p.x + (p.szer || 1) * P / 2 - droid.x) <= zasieg;
      const strzezone = droid.stan === 'w_drodze' ? [] : plan.pokoje.filter((p) => p.dostep === 'straz' && !p.zamkniete && wZasiegu(p));
      const nakazowe = plan.pokoje.filter((p) => p.dostep === 'nakaz');
      const STAN_N = { wniosek: 'czeka na decyzję', wydany: 'wydany', odrzucony: 'odrzucony' };
      const tenBudynek = nakazy.find((n) => n.lokalizacja_id === plan.budynek_id && n.stan !== 'odrzucony');
      return panel('WSTĘP',
        strzezone.map((p) => h('div', { style: 'margin:6px 0' }, h('div', `Lokal ${p.nr} (${p.rodzaj}) — strzeżony. Przekonaj strażnika:`),
          h('div', { style: 'display:flex;gap:6px;flex-wrap:wrap;margin-top:4px' },
            PODEJSCIA.map(([k, n]) => przycisk(n, () => zawolaj('sledztwo_straz', { pokoj: p.nr, podejscie: k },
              (r) => (r.juz ? 'Droid ma już wstęp — wchodzi.' : `${r.udana ? 'Strażnik wpuszcza droida.' : 'Strażnik odmawia.'} ${r.opis}`))))))),
        nakazowe.length ? h('p.maly-tekst', `Tylko z nakazem: ${nakazowe.map((p) => `${p.nr} (${p.rodzaj})`).join(', ')}.`) : null,
        h('p.maly-tekst', 'Nakazy: ' + (nakazy.length ? nakazy.map((n) => `${n.budynek} — ${STAN_N[n.stan] || n.stan}`).join(' · ') : 'brak') + '.'),
        plan.budynek_id && !tenBudynek ? przycisk(`Wniosek o nakaz: ${plan.budynek}`, () => {
          const uzasadnienie = window.prompt(`Wniosek o nakaz: ${plan.budynek}.\nUzasadnienie dla biura (co najmniej 10 znaków):`);
          if (uzasadnienie) zawolaj('sledztwo_nakaz_wniosek', { lokalizacja_id: plan.budynek_id, uzasadnienie }, 'Wniosek złożony. Decyzja biura pojawi się w dzienniku.');
        }) : null);
    }

    function zakladkaLokal() {
      const { droid, plan, kontakty, przedmioty = [] } = d;
      const wLokalu = droid.stan !== 'w_drodze' && droid.pokoj !== 'korytarz';
      const lok = plan.pokoje.find((p) => p.nr === droid.pokoj);
      const term = droid.stan === 'w_drodze' ? null : wLokalu ? lok?.terminal : plan.terminal_korytarz;
      return h('div',
        panel(wLokalu ? `LOKAL ${droid.pokoj} · ${lok?.rodzaj || ''}` : droid.stan === 'w_drodze' ? 'W DRODZE' : 'KORYTARZ',
          !d.przedmioty_dostepne ? h('p.maly-tekst', 'Przedmioty niedostępne (brak migracji 087).') : null,
          wLokalu && przedmioty.length ? h('div', przedmioty.map((p) => h('div', { style: 'margin:8px 0;padding:6px 8px;border:1px solid var(--linia)' },
            h('div', h('b', p.nazwa), h('span.slaby', ` · ${RODZAJ_P[p.rodzaj] || p.rodzaj}${Number(p.zabezpieczenie) && !Number(p.odblokowany) ? ' · zabezpieczony' : ''}`)),
            h('div.maly-tekst', p.opis),
            h('div', { style: 'display:flex;gap:6px;margin-top:4px' },
              Number(p.przenosny) ? przycisk('Zabierz', () => zawolaj('sledztwo_zabierz', { przedmiot_id: p.id }, `Zabrano: ${p.nazwa}.`)) : null,
              przycisk('Podłącz / czytaj', () => otworzKomputer({ przedmiot_id: p.id })))))) : null,
          wLokalu && !przedmioty.length ? h('p.maly-tekst', 'Na pierwszy rzut oka nic tu nie ma.') : null,
          wLokalu ? przycisk('Przeszukaj lokal (Percepcja)', async () => {
            // Raport przeszukania w okienku pod przyciskiem (nie na środku ekranu).
            try {
              const r = await api('sledztwo_przeszukaj', {});
              raport = { pokoj: droid.pokoj, ok: true, tekst: (r.znalezione.length ? `Znaleziono: ${r.znalezione.join(', ')}.`
                : r.udane ? 'Nic tu nie ma.' : 'Droid niczego nie zauważył.') + ` ${r.opis}` };
            } catch (e) { raport = { pokoj: droid.pokoj, ok: false, tekst: e.message || String(e) }; }
            await skanuj();
            odswiez();
          }) : null,
          wLokalu && raport && raport.pokoj === droid.pokoj ? h(`div.komunikat.${raport.ok ? 'ok' : 'blad'}`, { style: 'margin-top:6px' },
            h('b', 'RAPORT Z PRZESZUKANIA › '), raport.tekst) : null,
          term ? h('div', { style: 'margin-top:8px' }, przycisk(`Podejdź i podłącz się do terminala ${term}`, () => terminal(term), '.maly')) : null),
        kontakty.length ? panel('KONTAKTY W ZASIĘGU', kontakty.map((k) => h('div', { style: 'margin:4px 0' },
          h('a', { href: '#', onclick: (e) => { e.preventDefault(); zacznijRozmowe(k.token); } }, `${k.kod}: ${k.gatunek}, ${k.wyglad}`),
          h('span.slaby', k.strona === 'korytarz' ? ` (przy drzwiach ${k.pokoj})` : ` (lokal ${k.pokoj})`)))) : null,
        wstep());
    }

    function zakladkaEkwipunek() {
      const e = d.ekwipunek || [];
      return panel(`EKWIPUNEK ${d.oznaczenie || 'DT-4'}`, e.length ? e.map((p) => h('div', { style: 'margin:8px 0;padding:6px 8px;border:1px solid var(--linia)' },
        h('div', h('b', p.nazwa), h('span.slaby', ` · ${RODZAJ_P[p.rodzaj] || p.rodzaj}`)),
        h('div.maly-tekst', Number(p.zabezpieczenie) && !Number(p.odblokowany) ? `Zabezpieczony (poziom ${p.zabezpieczenie}).`
          : Number(p.odblokowany) ? `Odblokowany (${p.sposob_odblokowania === 'terminal' ? 'terminal publiczny' : 'droid'}).` : p.opis),
        przycisk('Podłącz / czytaj', () => otworzKomputer({ przedmiot_id: p.id })))) : h('p.maly-tekst', 'Droid niczego nie niesie. Przedmioty zabierasz w zakładce Lokal.'));
    }

    function zakladkaAkta() {
      const x = moje?.biezace;
      return h('div',
        x ? panel(`SPRAWA #${x.id}`, h('table.tabela', wiersz('Tytuł', x.tytul), wiersz('Zgłoszenie', x.zgloszenie),
          wiersz('Rejon', x.skupisko ? `${x.skupisko}, Nar Shaddaa` : 'Nar Shaddaa'), wiersz('Przyjęto', data(x.utworzono_utc)), wiersz('Stan', STAN[x.stan] || x.stan)),
        x.zaczyn ? h('div', { style: 'white-space:pre-wrap;margin-top:10px' }, x.zaczyn)
          : h('p.maly-tekst', 'Akta wstępne (zlecenie, tło, ludzie, od czego zacząć) pojawią się tutaj, gdy biuro je skompletuje.')) : null,
        (moje?.archiwum || []).length ? panel('ARCHIWUM', h('table.tabela', h('tr', h('th', '#'), h('th', 'Sprawa'), h('th', 'Stan'), h('th', 'Ocena')),
          moje.archiwum.map((a) => h('tr', h('td', a.id), h('td', a.tytul || a.zgloszenie), h('td', STAN[a.stan] || a.stan),
            h('td', a.ocena !== null && a.ocena !== undefined ? `${a.ocena}/100` : '—'))))) : null,
        x ? panel('PORZUCENIE', h('p.maly-tekst', 'Porzucona sprawa trafia do MG, a ten rodzaj sprawy zostaje zajęty. Możesz od razu przyjąć nową. '
          + 'Powrót do porzuconej — po uzasadnieniu i decyzji MG.'),
        przycisk('Porzuć sprawę', async () => {
          if (!window.confirm(`Porzucić sprawę #${x.id}? Tego nie da się cofnąć bez decyzji MG.`)) return;
          const uzasadnienie = window.prompt('Powód porzucenia (opcjonalnie, dla MG):', '') ?? '';
          try {
            await api('sledztwo_porzuc', { uzasadnienie });
            zakladka = 'skan'; rozmowa = null; wyczysc(rozmowaBox);
            await odswiez();
          } catch (e) { pokaz(blad(e)); }
        }, '.zly.maly')) : null);
    }

    function zakladkaDziennik() {
      const wpisy = moje?.dziennik || [];
      return panel('DZIENNIK', wpisy.length ? wpisy.map((x) => h('div', { style: 'margin:6px 0' },
        h('span.slaby', `${data(x.kiedy_utc)} · ${KTO[x.kto] || x.kto} · `), h('span', { style: 'white-space:pre-wrap' }, x.tresc)))
        : h('p.maly-tekst', 'Brak wpisów.'));
    }

    // ---------- notatnik detektywa: tropy, oś czasu, powiązania, rozwiązanie ----------

    // „Nar Shaddaa [x, y] · 12.3° N, 45.6° E · lokalnie 14:20, popołudnie (doba 87 h)”.
    const miejsceSprawy = (m) => `${m.planeta}${m.galaktyka ? ` [${m.galaktyka[0]}, ${m.galaktyka[1]}]` : ''}`
      + `${m.skupisko ? ` · ${m.skupisko}` : ''} · ${m.wspolrzedne} · lokalnie ${m.czas_lokalny}, ${m.pora} (doba ${m.doba_h} h) · standard ${m.czas_standardowy}`;
    const RODZAJ_T = { zeznanie: 'Zeznanie', obserwacja: 'Obserwacja', namiar: 'Namiar', dokument: 'Dokument' };
    const OZNACZ = { wazny: ['★ ważny', 'var(--ok)'], podejrzany: ['? podejrzany', 'var(--akcent-2)'], odrzucony: ['✕ odrzucony', 'var(--zly)'] };
    const notatnikBox = h('div');
    let notatnik = null, widokN = 'tropy', filtrN = 'aktywne', wynikSprawy = null;
    const zaznaczone = new Set();

    async function notatnikWolaj(akcja, dane) {
      try {
        const r = akcja ? await api(akcja, dane) : await api('sledztwo_notatnik');
        if (r && r.tropy) notatnik = r;
        rysujNotatnik();
        return r;
      } catch (e) { wyczysc(notatnikBox).append(blad(e)); return null; }
    }

    function zakladkaNotatnik() {
      if (!notatnik) { wyczysc(notatnikBox).append(panel('NOTATNIK DETEKTYWA', h('p.maly-tekst', 'Wczytywanie…'))); notatnikWolaj(); }
      else rysujNotatnik();
      return notatnikBox;
    }

    function kartaTropu(x, wybor = null) {
      const oz = OZNACZ[x.oznaczenie];
      const przelacz = (o) => notatnikWolaj('sledztwo_trop', { id: x.id, oznaczenie: x.oznaczenie === o ? '' : o });
      const notatka = h('textarea', { rows: 2, maxlength: 1000, placeholder: 'Twoja notatka…', style: 'width:100%;box-sizing:border-box;margin-top:4px;font:inherit' });
      notatka.value = x.notatka || '';
      notatka.onchange = () => notatnikWolaj('sledztwo_trop', { id: x.id, notatka: notatka.value });
      const zazn = h('input', { type: 'checkbox', checked: zaznaczone.has(x.id), title: 'Zaznacz do powiązania' });
      zazn.onchange = () => { if (zazn.checked) zaznaczone.add(x.id); else zaznaczone.delete(x.id); rysujNotatnik(); };
      return h('div', { style: `margin:8px 0;padding:6px 8px;border:1px solid ${oz ? oz[1] : 'var(--linia)'};${x.oznaczenie === 'odrzucony' ? 'opacity:.55' : ''}` },
        h('div', { style: 'display:flex;gap:6px;align-items:center;flex-wrap:wrap' }, wybor || zazn,
          h('b', RODZAJ_T[x.rodzaj] || x.rodzaj), h('span.slaby', `· ustalono ${x.kiedy}${x.kiedy_lokalnie ? ` (${x.kiedy_lokalnie})` : ''} · wiarygodność ${x.wiarygodnosc}%`),
          oz ? h('span', { style: `color:${oz[1]}` }, oz[0]) : null),
        h('div', { style: 'margin-top:4px;white-space:pre-wrap' }, x.tresc),
        x.czas_zdarzenia ? h('div.maly-tekst', `Kiedy się wydarzyło: ${x.czas_zdarzenia}${x.czas_lokalny ? ` (${x.czas_lokalny})` : ''}`) : null,
        h('div.maly-tekst', `Źródło: ${x.zrodlo}`),
        wybor ? null : h('div', { style: 'display:flex;gap:4px;margin-top:4px;flex-wrap:wrap' },
          przycisk('★ Ważny', () => przelacz('wazny')), przycisk('? Podejrzany', () => przelacz('podejrzany')), przycisk('✕ Odrzuć', () => przelacz('odrzucony'))),
        wybor ? null : notatka);
    }

    function rysujNotatnik() {
      if (!notatnik) return;
      const { tropy, powiazania, miejsce_sprawy: ms, formularz: f } = notatnik;
      const poId = new Map(tropy.map((x) => [x.id, x]));
      const zakl = [['tropy', `Tropy (${tropy.length})`], ['os', 'Oś czasu'], ['powiazania', `Powiązania (${powiazania.length})`], ['rozwiaz', 'Rozwiąż sprawę']];
      const gora = h('div', { style: 'display:flex;gap:4px;flex-wrap:wrap;margin:6px 0' },
        zakl.map(([k, n]) => przycisk(n, () => { widokN = k; rysujNotatnik(); }, widokN === k ? '.maly' : '.drugi.maly')));
      let tresc;
      if (widokN === 'tropy') {
        const filtr = h('select', {}, [['aktywne', 'Bez odrzuconych'], ['wszystkie', 'Wszystkie'], ['wazny', 'Tylko ważne'], ['podejrzany', 'Tylko podejrzane']]
          .map(([k, n]) => h('option', { value: k, selected: filtrN === k }, n)));
        filtr.onchange = () => { filtrN = filtr.value; rysujNotatnik(); };
        const lista = tropy.filter((x) => (filtrN === 'wszystkie' ? true : filtrN === 'aktywne' ? x.oznaczenie !== 'odrzucony' : x.oznaczenie === filtrN));
        const wybrane = [...zaznaczone].filter((id) => poId.has(id));
        tresc = h('div',
          h('div', { style: 'display:flex;gap:6px;align-items:center;flex-wrap:wrap' }, filtr,
            wybrane.length === 2 ? przycisk('Połącz zaznaczone', () => {
              const opis = window.prompt('Co łączy te dwa tropy? (krótko)', '') ?? null;
              if (opis === null) return;
              zaznaczone.clear();
              notatnikWolaj('sledztwo_powiaz', { a: wybrane[0], b: wybrane[1], opis });
            }, '.maly') : h('span.slaby', 'Zaznacz dwa tropy, by je powiązać.')),
          lista.length ? lista.map((x) => kartaTropu(x))
            : h('p.maly-tekst', 'Brak tropów. Tropy zapisują się same, gdy rozmówca powie coś konkretnego, gdy droid dostanie namiary albo odczyta dokument.'));
      } else if (widokN === 'os') {
        const os = tropy.filter((x) => x.czas_zdarzenia_utc && x.oznaczenie !== 'odrzucony').sort((a, b) => (a.czas_zdarzenia_utc < b.czas_zdarzenia_utc ? -1 : 1));
        tresc = os.length ? h('div', os.map((x) => h('div', { style: 'margin:6px 0;padding-left:10px;border-left:2px solid var(--akcent)' },
          h('b', x.czas_zdarzenia), h('span.slaby', x.czas_lokalny ? ` · ${x.czas_lokalny}` : ''), h('div', x.tresc))))
          : h('p.maly-tekst', 'Oś czasu jest pusta. Wypełniają ją obserwacje z datą: kiedy i gdzie ktoś widział osobę ze sprawy.');
      } else if (widokN === 'powiazania') {
        tresc = powiazania.length ? h('div', powiazania.map((p) => h('div', { style: 'margin:8px 0;padding:6px 8px;border:1px solid var(--linia)' },
          h('div', h('b', p.opis || 'Powiązanie'), ' ', przycisk('✕', () => notatnikWolaj('sledztwo_powiaz', { usun: p.id }), '.drugi.maly', { title: 'Usuń powiązanie' })),
          h('div.maly-tekst', `A: ${poId.get(p.a)?.tresc || '?'}`), h('div.maly-tekst', `B: ${poId.get(p.b)?.tresc || '?'}`))))
          : h('p.maly-tekst', 'Brak powiązań. W zakładce Tropy zaznacz dwa tropy i kliknij „Połącz zaznaczone”.');
      } else {
        tresc = formularzRozwiazania(f, tropy);
      }
      wyczysc(notatnikBox).append(panel('NOTATNIK DETEKTYWA', ms ? h('p.maly-tekst', miejsceSprawy(ms)) : null, gora, tresc));
    }

    function formularzRozwiazania(f, tropy) {
      if (wynikSprawy) return wynikPanel(wynikSprawy);
      const zd = h('select', {}, h('option', { value: '' }, '— wybierz —'), f.zdarzenia.map((z) => h('option', { value: z.id }, z.nazwa)));
      const gdzie = h('select', {}, h('option', { value: '' }, '— nie wiem —'), f.miejsca.map((m) => h('option', { value: m.klucz }, m.nazwa)));
      const kto = h('select', {}, h('option', { value: '0' }, 'Nikt — zgłoszenie było szczere'), f.osoby.map((o) => h('option', { value: o.id }, o.nazwa)));
      const uz = h('textarea', { rows: 5, maxlength: 4000, placeholder: 'Uzasadnienie: co się stało, kiedy, gdzie i skąd to wiesz (min. 120 znaków)…',
        style: 'width:100%;box-sizing:border-box;font:inherit' });
      const wybor = new Map();
      const karty = tropy.filter((x) => x.oznaczenie !== 'odrzucony').map((x) => {
        const c = h('input', { type: 'checkbox', checked: x.oznaczenie === 'wazny' });
        wybor.set(x.id, c);
        return kartaTropu(x, c);
      });
      const wiersz2 = (etykieta, pole) => h('div', { style: 'margin:8px 0' }, h('div.maly-tekst', etykieta), pole);
      return h('div',
        h('p.maly-tekst', 'Rozwiązanie składasz raz. Ocena (0–100): co się stało 30, gdzie jest osoba ze sprawy 30, kto ukrywa prawdę 20, tropy 15, uzasadnienie 5.'),
        wiersz2('Co się naprawdę stało?', zd),
        wiersz2('Gdzie teraz jest osoba ze sprawy?', gdzie),
        wiersz2('Kto ukrywa prawdę?', kto),
        wiersz2('Na które tropy się powołujesz?', karty.length ? h('div', karty) : h('p.maly-tekst', 'Nie masz jeszcze tropów.')),
        wiersz2('Uzasadnienie', uz),
        przycisk('Złóż rozwiązanie', async () => {
          if (!zd.value) { window.alert('Wybierz, co się stało.'); return; }
          if (!window.confirm('Złożyć rozwiązanie? Sprawa zostanie zamknięta i oceniona.')) return;
          const r = await notatnikWolaj('sledztwo_rozwiaz', { zdarzenie_id: Number(zd.value), miejsce: gdzie.value, postac_id: Number(kto.value),
            tropy: [...wybor].filter(([, c]) => c.checked).map(([id]) => id), uzasadnienie: uz.value });
          if (r?.wynik) { wynikSprawy = r.wynik; rysujNotatnik(); }
        }, '.maly'));
    }

    function wynikPanel(w) {
      return h('div', { style: 'margin:8px 0' },
        h('div', { style: 'font-size:22px;letter-spacing:2px;margin:6px 0' }, `OCENA: ${w.ocena}/100`),
        h('ul', w.szczegoly.map((x) => h('li', x))),
        h('p', { style: 'white-space:pre-wrap' }, w.prawda),
        przycisk('Wróć do biura', () => { zakladka = 'skan'; notatnik = null; odswiez(); }, '.maly'));
    }

    function rysujSzuflade() {
      szuflada.hidden = zakladka === 'skan' || !d;
      if (szuflada.hidden) return;
      const zamknij = przycisk('×', () => { zakladka = 'skan'; rysujSzuflade(); rysujGore(); }, '.drugi.maly', { style: 'float:right', title: 'Zamknij panel' });
      // Panel windy tylko, gdy droid jest w windzie (albo do niej idzie).
      if (zakladka === 'winda' && !wWindzie() && !zamiar?.winda) { zakladka = 'skan'; szuflada.hidden = true; return; }
      const tresc = { lokal: zakladkaLokal, ekwipunek: zakladkaEkwipunek, rozmowa: () => rozmowaBox, akta: zakladkaAkta, dziennik: zakladkaDziennik,
        notatnik: zakladkaNotatnik,
        winda: () => {
          if (wWindzie()) return zakladkaWinda();
          const etap = jazdaWindy();
          if (etap === 'jedzie') {
            return panel(`WINDA · ${d.droid.cel.budynek || d.plan.budynek}`,
              h('p', `Winda jedzie: poziom ${d.plan.poziom} → ${d.droid.cel.poziom}.`),
              h('p.maly-tekst', 'Drzwi zamknięte — droid wysiądzie dopiero na wybranym poziomie. Wybór piętra i wyjście są zablokowane do zatrzymania windy.'));
          }
          return panel('WINDA', h('p.maly-tekst', etap === 'idzie' ? 'Droid idzie do windy…' : 'Droid idzie do windy…'));
        } }[zakladka];
      wyczysc(szuflada).append(zamknij, tresc ? tresc() : null);
    }

    function rysujWszystko() {
      if (!d) return;
      wyczysc(planBox).append(planPietra());
      rysujGore();
      rysujPostep();
      rysujDol();
      rysujSzuflade();
    }

    // ---------- ekran komputera (na całą przestrzeń) ----------

    async function otworzKomputer(arg) {
      try {
        const r = await api('sledztwo_komputer', arg);
        pokazKomputer(r.komputer, arg);
      } catch (e) { pokaz(blad(e)); }
    }

    function pokazKomputer(k, arg) {
      const ZIELEN = '#7dffb0';
      const tresc = h('pre', { style: 'flex:1;margin:0;padding:14px;white-space:pre-wrap;overflow:auto;font:inherit' });
      const lista = h('div', { style: `width:min(240px,40%);border-right:1px solid ${ZIELEN}44;overflow:auto;padding:8px` });
      const zamknij = () => { komputerBox.hidden = true; wyczysc(komputerBox); document.removeEventListener('keydown', esc); escK = null; };
      const esc = (e) => { if (e.key === 'Escape') zamknij(); };
      if (escK) document.removeEventListener('keydown', escK);
      escK = esc;
      document.addEventListener('keydown', esc);
      const naglowek = h('div', { style: `display:flex;gap:10px;align-items:center;padding:8px 12px;border-bottom:1px solid ${ZIELEN}55` },
        h('b', { style: 'flex:1' }, `◉ POŁĄCZONO · ${k.nazwa}`), przycisk('Rozłącz', zamknij, '.drugi.maly'));
      const ekran = h('div', { style: `position:absolute;inset:0;display:flex;flex-direction:column;background:#020805;color:${ZIELEN};`
        + 'font-family:var(--lat);text-shadow:0 0 4px rgba(125,255,176,.35)' }, naglowek);
      if (k.zablokowany) {
        const doBlokady = k.blokada_do ? new Date(String(k.blokada_do).replace(' ', 'T') + 'Z') : null;
        ekran.append(h('div', { style: 'flex:1;display:grid;place-items:center;text-align:center;padding:20px' }, h('div',
          h('div', { style: 'font-size:22px;letter-spacing:4px;margin-bottom:10px' }, 'NOŚNIK ZABEZPIECZONY'),
          h('div', `Poziom zabezpieczenia: ${k.zabezpieczenie} · ${k.opis || ''}`),
          doBlokady && doBlokady > new Date() ? h('div', { style: 'color:var(--zly);margin-top:6px' }, `Tryb blokady do ${czasGry(doBlokady)}.`) : null,
          h('div', { style: 'margin-top:16px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap' },
            przycisk('Złam zabezpieczenie (Intelekt + Komputery)', async () => {
              const r = await zawolaj('sledztwo_zlam', { przedmiot_id: k.przedmiot_id }, (x) => `${x.udana ? 'Zabezpieczenie złamane.' : 'Nie udało się.'} ${x.opis}`);
              if (r) otworzKomputer(arg);
            }, '.maly')),
          h('p', { style: 'opacity:.7;margin-top:14px;max-width:520px' }, 'Albo zabierz nośnik i zdekoduj go na publicznym terminalu — szybko i pewnie, '
            + 'ale sieć publiczna zapisze identyfikator nośnika, a właściciel danych może się dowiedzieć, gdzie i kiedy.'))));
      } else {
        const pliki = k.pliki || [];
        const wybierz = (i) => {
          tresc.textContent = pliki[i]?.tresc || '';
          [...lista.children].forEach((el, j) => { el.style.background = j === i ? `${ZIELEN}22` : 'transparent'; });
        };
        pliki.forEach((p, i) => lista.append(h('div', { style: 'padding:6px 8px;cursor:pointer', onclick: () => wybierz(i) }, `▸ ${p.nazwa}`)));
        if (k.publiczny && (k.do_dekodowania || []).length) {
          lista.append(h('div', { style: 'margin-top:14px;padding:6px 8px;border-top:1px solid #7dffb044' }, 'DEKODUJ NOŚNIK',
            h('div', { style: 'opacity:.7;font-size:12px;margin:4px 0' }, 'Uwaga: zostawia ślad w sieci publicznej.'),
            k.do_dekodowania.map((x) => h('div', { style: 'margin:4px 0' }, przycisk(x.nazwa, async () => {
              const r = await zawolaj('sledztwo_dekoduj', { przedmiot_id: x.id, terminal: k.terminal }, `Odszyfrowano: ${x.nazwa}.`);
              if (r) otworzKomputer(arg);
            })))));
        }
        ekran.append(h('div', { style: 'flex:1;display:flex;min-height:0' }, lista, tresc));
        if (pliki.length) wybierz(0); else tresc.textContent = 'Brak plików.';
      }
      wyczysc(komputerBox).append(ekran);
      komputerBox.hidden = false;
    }

    // ---------- rozmowa: zapis tekstowy pisany na żywo, polecenia operatora ----------

    const rozmowaBox = h('div');
    const listaR = h('div', { style: 'margin:8px 0' });
    let rozmowa = null, pisze = false, zaplanowany = null, kolejka = Promise.resolve();
    const wypisane = new Set();
    const KTO_R = { droid: 'DT-4', operator: 'Operator', system: '' };
    // Procedura śledcza: 'wlasna' — droid zadaje pytania operatora jako swoje; 'jawna' — może mówić, że pyta operator.
    let procedura = 'wlasna', szkic = '', bladR = '';
    try { if (localStorage.getItem('dt4-procedura') === 'jawna') procedura = 'jawna'; } catch (e) { /* bez pamięci przeglądarki */ }

    function wypisz(wp, odRazu) {
      const kto = wp.kto === 'npc' ? (rozmowa?.kod || 'Rozmówca') : KTO_R[wp.kto];
      const tekst = h('span', { style: 'white-space:pre-wrap' });
      const el = h('div', { style: `margin:6px 0;${wp.kto === 'system' ? (wp.tresc.startsWith('Test:') ? `font-weight:bold;color:var(${wp.tresc.includes(' UDANY') && !wp.tresc.includes('NIEUDANY') ? '--ok' : '--zly'})` : 'opacity:.6;font-style:italic') : ''}${wp.kto === 'operator' ? 'color:var(--akcent-2)' : ''}` },
        kto ? h('b', `${kto} › `) : null, tekst);
      listaR.append(el);
      if (odRazu) { tekst.textContent = wp.tresc; return Promise.resolve(); }
      return new Promise((gotowe) => {
        let i = 0;
        const krok = () => {
          if (koniec) return gotowe();
          i = Math.min(wp.tresc.length, i + 2);
          tekst.textContent = wp.tresc.slice(0, i);
          szuflada.scrollTop = szuflada.scrollHeight;
          if (i < wp.tresc.length) setTimeout(krok, 28); else gotowe();
        };
        krok();
      });
    }

    function pokazRozmowe(r, odRazu = false) {
      if (!r) { rozmowa = null; wyczysc(rozmowaBox); if (zakladka === 'rozmowa') zakladka = 'skan'; rysujGore(); rysujSzuflade(); return; }
      if (!rozmowa || rozmowa.id !== r.id) { wyczysc(listaR); wypisane.clear(); }
      rozmowa = r;
      for (const wp of r.wpisy || []) {
        if (wypisane.has(wp.id)) continue;
        wypisane.add(wp.id);
        kolejka = kolejka.then(() => wypisz(wp, odRazu));
      }
      rysujSterowanieRozmowy();
      const bezDroida = !(r.wpisy || []).some((x) => x.kto === 'droid');
      if (r.stan !== 'zakonczona' && bezDroida && zaplanowany !== r.id) {
        zaplanowany = r.id;
        setTimeout(() => { if (!koniec && rozmowa?.id === r.id) tura(''); }, (r.gotowa_za_s + 0.5) * 1000);
      }
    }

    function rysujSterowanieRozmowy() {
      if (!rozmowa) return;
      const zakonczona = rozmowa.stan === 'zakonczona';
      // Duże pole polecenia (Enter — wyślij, Shift+Enter — nowa linia); szkic przetrwa przerysowanie.
      const pole = h('textarea', { placeholder: 'Polecenie dla droida, np. „spytaj, komu jest winien pieniądze” albo „powiedz, że wrócimy z nakazem”',
        rows: 3, maxlength: 300, disabled: zakonczona || pisze,
        style: 'width:100%;box-sizing:border-box;resize:vertical;min-height:64px;font:inherit' });
      pole.value = szkic;
      pole.oninput = () => { szkic = pole.value; };
      const wyslij = (podejscie = '') => { const v = pole.value.trim(); if (v || podejscie) { szkic = ''; tura(v, podejscie); } };
      const zadaj = przycisk('Wyślij polecenie', () => wyslij(), '.maly', { disabled: zakonczona || pisze });
      pole.onkeydown = (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); wyslij(); } };
      // Podejścia: test EotE droida przeciw rozmówcy; wynik wpływa na odpowiedź i jego dalszą chęć do rozmowy.
      const podejscia = h('div', { style: 'display:flex;flex-wrap:wrap;gap:6px;margin:6px 0' },
        PODEJSCIA_R.map(([k, n, opis]) => przycisk(n, () => wyslij(k), '.drugi.maly', { disabled: zakonczona || pisze, title: opis })));
      const status = pisze ? 'DT-4 nadaje… czekam na odpowiedź rozmówcy.'
        : rozmowa.stan === 'podejscie' ? `DT-4 podchodzi do rozmówcy (2–3 m)${rozmowa.gotowa_za_s ? ` · ok. ${czas(rozmowa.gotowa_za_s)}` : ''}…`
          : zakonczona ? 'Rozmowa zakończona.' : 'Rozmowa trwa. Wpisz polecenie albo pozwól droidowi pytać samemu.';
      const wyborProcedury = h('select', { title: 'Procedura śledcza dla pytań operatora', disabled: zakonczona, style: 'width:100%;max-width:100%' },
        h('option', { value: 'wlasna', selected: procedura === 'wlasna' }, 'Pytania operatora: jako własne droida'),
        h('option', { value: 'jawna', selected: procedura === 'jawna' }, 'Pytania operatora: jawnie („mój operator pyta”)'));
      wyborProcedury.onchange = () => {
        procedura = wyborProcedury.value;
        try { localStorage.setItem('dt4-procedura', procedura); } catch (e) { /* bez pamięci przeglądarki */ }
      };
      wyczysc(rozmowaBox).append(panel(`ROZMOWA · ${rozmowa.rozmowca} · ${lokalTxt(rozmowa.pokoj)}`,
        h('p.maly-tekst', 'Zapis tekstowy — droidy śledcze nie mają modułu głosu.'),
        listaR,
        h('p.maly-tekst', status),
        zakonczona ? null : h('div', { style: 'margin:0 0 6px' }, wyborProcedury),
        bladR && !zakonczona ? h('div.komunikat.blad', { style: 'margin:0 0 6px' }, bladR) : null,
        zakonczona ? przycisk('Zamknij zapis', () => pokazRozmowe(null))
          : h('div', pole, h('div.maly-tekst', { style: 'margin-top:6px' }, 'Podejście (test; dołącza się do polecenia z pola, jeśli jest):'), podejscia,
            h('div', { style: 'display:flex;flex-wrap:wrap;gap:6px;align-items:center' }, zadaj,
            przycisk('Dalej (droid pyta sam)', () => tura(''), '.drugi.maly', { disabled: pisze }),
            przycisk('Zakończ', async () => {
              try { await api('sledztwo_rozmowa_koniec', { rozmowa_id: rozmowa.id }); await odswiezRozmowe(); skanuj(); }
              catch (e) { pokaz(blad(e)); }
            }, '.drugi.maly', { disabled: pisze })))));
      if (!zakonczona && !pisze && zakladka === 'rozmowa') setTimeout(() => pole.focus(), 0);
    }

    async function tura(polecenie, podejscie = '') {
      if (!rozmowa || pisze || rozmowa.stan === 'zakonczona') return;
      pisze = true;
      rysujSterowanieRozmowy();
      try {
        const r = await api('sledztwo_rozmowa_tura', { rozmowa_id: rozmowa.id, polecenie, procedura, podejscie });
        pisze = false;
        pokazRozmowe(r.rozmowa);
        // Nowe namiary od rozmówcy: odśwież skan, żeby pojawiły się w polu wyboru celu.
        const namiaryTeraz = (r.rozmowa.wpisy || []).slice(-3).some((x) => x.kto === 'system' && x.tresc.startsWith('Namiary zapisane'));
        if (r.rozmowa.stan === 'zakonczona') { skanuj(); odswiez(); } else if (namiaryTeraz) skanuj();
      } catch (e) {
        // Błąd tury (np. to samo pytanie drugi raz) — w panelu rozmowy, a polecenie wraca do pola.
        pisze = false;
        bladR = e.message || String(e);
        if (!szkic) szkic = polecenie;
        await odswiezRozmowe();
        return;
      }
      bladR = '';
    }

    async function odswiezRozmowe(id) {
      const ktora = id || rozmowa?.id;
      try {
        const r = await api('sledztwo_rozmowa', undefined, ktora ? { id: ktora } : {});
        pokazRozmowe(r.rozmowa, !rozmowa);
      } catch (e) { pokaz(blad(e)); }
    }

    async function zacznijRozmowe(token) {
      if (pisze) return;
      try {
        const r = await api('sledztwo_rozmowa_start', { kontakt: token });
        wyczysc(komunikat);
        zakladka = 'rozmowa';
        pokazRozmowe(r.rozmowa);
        await skanuj();
      } catch (e) { pokaz(blad(e)); }
    }

    // ---------- odświeżanie ----------

    async function skanuj() {
      clearTimeout(zegarSkanu);
      if (koniec || !maSprawe) return;
      try {
        const nowe = await api('sledztwo_skan');
        nowe.oznaczenie = moje?.droid || 'DT-4';
        d = nowe;
        // Nowe tropy od ostatniego otwarcia notatnika — wczytaj go od nowa.
        if (notatnik && d.tropy_ile !== notatnik.tropy.length) notatnik = null;
        if (d.droid.stan === 'w_drodze') {
          koniecDrogi = Date.now() + d.droid.zostalo_s * 1000;
          calaDroga = d.droid.zostalo_s / Math.max(0.0001, 1 - d.droid.postep);
        }
        if (d.rozmowa && (!rozmowa || rozmowa.id !== d.rozmowa.id)) await odswiezRozmowe(d.rozmowa.id);
        rysujWszystko();
        // Po dotarciu: zaplanowane podłączenie do terminala.
        if (zamiar?.winda && d.droid.stan !== 'w_drodze') {
          zamiar = null;
          if (wWindzie()) { zakladka = 'winda'; rysujSzuflade(); rysujGore(); }
        }
        if (zamiar?.terminal && d.droid.stan !== 'w_drodze') {
          const t = d.plan.terminale?.[zamiar.terminal];
          const z = zamiar; zamiar = null;
          if (t && d.droid.pokoj === t.pokoj && Math.hypot(d.droid.x - t.x, d.droid.y - t.y) <= ZASIEG_TERMINALA) otworzKomputer({ terminal: z.terminal });
        }
        // W drodze co 15 s (rozmowy w drzwiach po drodze pojawiają się w meldunkach), na miejscu co minutę.
        // W drodze: co 15 s, a tuż przed dotarciem — zaraz po czasie dotarcia (serwer rozlicza je przy odczycie).
        if (!koniec) zegarSkanu = setTimeout(skanuj, d.droid.stan === 'w_drodze' ? Math.min(15000, Math.max(1500, d.droid.zostalo_s * 1000 + 1500)) : 60000);
      } catch (e) { wyczysc(planBox).append(h('div', { style: 'padding:20px' }, blad(e))); }
    }

    function biuro() {
      const nowa = przycisk('Przyjmij nową sprawę', async () => {
        nowa.disabled = true;
        // Nowa sprawa: od razu otwarty ekwipunek z teczką od biura (zgłoszenie, akta osobowe, profil psychologiczny).
        try { await api('sledztwo_nowe', {}); zakladka = 'ekwipunek'; notatnik = null; wynikSprawy = null; zaznaczone.clear(); await odswiez(); }
        catch (e) { pokaz(blad(e)); nowa.disabled = false; }
      }, '');
      return h('div', { style: 'position:absolute;inset:0;overflow:auto;padding:24px' },
        panel(`BIURO DETEKTYWISTYCZNE · ${moje?.droid || 'DT-4'}`,
          h('p.maly-tekst', `Rozwiązane rodzaje spraw: ${moje?.rozwiazane ?? 0} / ${moje?.wszystkie_typy ?? 144}`),
          h('p', 'Brak otwartej sprawy.'), nowa),
        wynikSprawy ? panel('OSTATNIA SPRAWA — ROZWIĄZANIE', wynikPanel(wynikSprawy)) : null,
        (moje?.archiwum || []).length ? zakladkaAkta() : null);
    }

    async function odswiez() {
      if (koniec) return;
      try {
        moje = await api('sledztwo_moje');
        if (!moje.detektyw) {
          maSprawe = false;
          wyczysc(planBox).append(h('div', { style: 'padding:24px' }, panel('DROID ŚLEDCZY', h('p.maly-tekst', 'To konto nie ma przydzielonego droida śledczego.'))));
        } else {
          const bylaSprawa = maSprawe;
          maSprawe = !!moje.biezace;
          if (!maSprawe) { d = null; wyczysc(gora); wyczysc(dol); szuflada.hidden = true; wyczysc(planBox).append(biuro()); }
          else if (!bylaSprawa) skanuj();
          else if (['akta', 'dziennik'].includes(zakladka)) rysujSzuflade();
        }
      } catch (e) { pokaz(blad(e)); }
      clearTimeout(zegar);
      if (!koniec) zegar = setTimeout(odswiez, 30000);
    }

    odswiez();
    return () => {
      koniec = true; clearTimeout(zegar); clearTimeout(zegarSkanu); clearInterval(licznik); clearInterval(animacja); komputerBox.hidden = true;
      if (escK) document.removeEventListener('keydown', escK);
    };
  },
};

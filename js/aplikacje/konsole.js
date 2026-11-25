// Konsole mostka w symulacji: pulpit rysowany z tych samych układów co arkusz i wydruk (pulpity_uklady.js, siatka 13×9),
// przyciski i ekrany podpięte pod stan bitwy (api/?a=bitwa_stan). Element bez mechaniki jest przygaszony.
// Warstwy (PULPITY.md 6): 1 — kontrolki i ekrany (bez napisów), 2 — teksty w kroju datapada (var(--czcionka)), więc
// przełącznik alfabetu (łaciński / Aurebesh) zmienia wszystkie napisy pulpitu naraz.
// Mechanika: Radar i Sensory; pozostałe pulpity klasy rysują się w całości, bez podpiętych elementów.

import { h } from '../rdzen/narzedzia.js';
import { UKLADY, KOLORY, FORMATY } from './pulpity_uklady.js';

export const KONSOLE = ['Radar', 'Sensory'];
export const pulpityKlasy = (klasa) => Object.keys(UKLADY[klasa] || {});
const NS = 'http://www.w3.org/2000/svg';
const ZASIEG_SENSOROW_KM = 200; // jak automatyczne osłony w silniku (STALE zasieg_sensorow_km)
const ZASIEG_OGNIA_KM = 3;
const WLASNY = '#9fb4c8', OBCY = '#ff6b55';
const CZCIONKA = 'font-family:var(--czcionka)';

const svg = (tag, at = {}, tekst) => {
  const e = document.createElementNS(NS, tag);
  for (const [k, v] of Object.entries(at)) e.setAttribute(k, v);
  if (tekst != null) e.textContent = tekst;
  return e;
};
const rad = (d) => (d * Math.PI) / 180;
const odl = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
const mmss = (s) => { s = Math.max(0, Math.round(s)); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`; };
const km = (d) => (d >= 100 ? d.toFixed(0) : d.toFixed(1));

// Sylwetki z góry (długość 1, dziób w górę, środek w 0,0) — według grafik: ISD (Top View; rufa z dwóch trójkątów —
// od narożników burt ukośnie do bloku silników) i MC80 (ostry dziób, wąski kadłub, skrzydła odchylone do rufy).
const SYLWETKI = {
  ISD_I: {
    obrys: 'M0 -0.5 L0.29 0.41 L0.075 0.5 L0.075 0.535 L-0.075 0.535 L-0.075 0.5 L-0.29 0.41 Z',
    detale: [
      ['path', { d: 'M-0.11 0.06 L0.11 0.06 L0.14 0.44 L0.075 0.47 L-0.075 0.47 L-0.14 0.44 Z', 'fill-opacity': 0.35, fill: '#fff' }],
      ['path', { d: 'M-0.05 -0.12 L0.05 -0.12 L0.08 0.06 L-0.08 0.06 Z', 'fill-opacity': 0.25, fill: '#fff' }],
      ['rect', { x: -0.08, y: 0.38, width: 0.16, height: 0.06, 'fill-opacity': 0.55, fill: '#fff' }],
      ['circle', { cx: -0.075, cy: 0.41, r: 0.022, fill: '#fff', 'fill-opacity': 0.8 }],
      ['circle', { cx: 0.075, cy: 0.41, r: 0.022, fill: '#fff', 'fill-opacity': 0.8 }],
      ['path', { d: 'M0 -0.46 L0 -0.12', stroke: '#000', 'stroke-opacity': 0.35, 'stroke-width': 0.012, fill: 'none' }],
      ['path', { d: 'M0.29 0.41 L0 0.3 L-0.29 0.41', stroke: '#000', 'stroke-opacity': 0.3, 'stroke-width': 0.01, fill: 'none' }],
    ],
  },
  MC80: {
    obrys: 'M0 -0.5 C0.035 -0.47 0.08 -0.42 0.092 -0.32 C0.11 -0.06 0.185 0.1 0.22 0.28 C0.175 0.235 0.125 0.215 0.088 0.222 '
      + 'L0.082 0.44 C0.072 0.5 0.03 0.506 0 0.506 C-0.03 0.506 -0.072 0.5 -0.082 0.44 L-0.088 0.222 '
      + 'C-0.125 0.215 -0.175 0.235 -0.22 0.28 C-0.185 0.1 -0.11 -0.06 -0.092 -0.32 C-0.08 -0.42 -0.035 -0.47 0 -0.5 Z',
    detale: [
      ['ellipse', { cx: 0, cy: 0.06, rx: 0.042, ry: 0.22, fill: '#fff', 'fill-opacity': 0.35 }],
      ['ellipse', { cx: 0.018, cy: -0.27, rx: 0.03, ry: 0.09, fill: '#fff', 'fill-opacity': 0.3 }],
      ['ellipse', { cx: 0.15, cy: 0.13, rx: 0.012, ry: 0.09, fill: '#fff', 'fill-opacity': 0.45 }],
      ['ellipse', { cx: -0.15, cy: 0.13, rx: 0.012, ry: 0.09, fill: '#fff', 'fill-opacity': 0.45 }],
      ['ellipse', { cx: 0, cy: 0.42, rx: 0.05, ry: 0.05, fill: '#fff', 'fill-opacity': 0.25 }],
    ],
  },
};

export function sylwetka(profil, kolor, rozmiar) {
  const s = SYLWETKI[profil];
  const g = svg('g', { transform: `scale(${rozmiar})` });
  if (!s) { g.append(svg('path', { d: 'M0 -0.5 L0.3 0.5 L-0.3 0.5Z', fill: kolor })); return g; }
  g.append(svg('path', { d: s.obrys, fill: kolor, 'fill-opacity': 0.9, stroke: '#000', 'stroke-width': 0.01 }));
  for (const [tag, at] of s.detale) g.append(svg(tag, at));
  return g;
}

// Stan konsoli trzymany między odświeżeniami (filtry, skala, ślady, zaznaczenie).
export function nowyStanKonsol() {
  return {
    skala: 200, filtry: { wlasne: true, obce: true, klucze: true, okrety: true, pociski: true, nieznane: true },
    sektor: null, tory: true, prognozy: false, oznaczony: null, sledzony: null, zamrozony: false, slady: new Map(),
    progZblizenia: 30, progKolizji: 10, tempo: 12, syncSensory: false, syncNawigacja: false,
    grupa: null, skan: true, kontaktyNormalne: true,
  };
}

// Ślady pozycji (do torów na radarze): ostatnie 40 odczytów każdego obiektu.
export function zapiszSlady(st, s) {
  if (st.zamrozony) return;
  for (const o of [...s.jednostki.map((j) => ['j' + j.id, j]), ...s.klucze.filter((k) => k.stan === 'w_locie').map((k) => ['k' + k.id, k])]) {
    const lista = st.slady.get(o[0]) || [];
    const ost = lista[lista.length - 1];
    if (!ost || ost[0] !== o[1].x || ost[1] !== o[1].y) lista.push([o[1].x, o[1].y]);
    if (lista.length > 40) lista.shift();
    st.slady.set(o[0], lista);
  }
}

// Własna jednostka konsoli: okręt strony graczy (strona kluczy bez AI), inaczej pierwszy.
export function wlasna(s) {
  return s.jednostki.find((j) => s.klucze.some((k) => !k.ai && k.strona === j.strona)) || s.jednostki[0];
}

// Pozycja względem okrętu: namiar od dziobu (°, + w prawo) i burta.
function wzgledem(j, o) {
  const dx = o.x - j.x, dy = o.y - j.y, k = rad(j.kurs || 0);
  const wBok = dx * Math.cos(k) - dy * Math.sin(k), wzdl = dx * Math.sin(k) + dy * Math.cos(k);
  return { namiar: Math.round((Math.atan2(wBok, wzdl) * 180) / Math.PI), burta: wBok >= 0 ? 'prawa' : 'lewa', d: Math.hypot(dx, dy) };
}

const stopienWGore = (stopnie, hz) => stopnie.find((x) => x >= hz) ?? stopnie[stopnie.length - 1];

// ------------------------------------------------------------------------------------------- rysownik pulpitu
// Dwie siatki o tej samej geometrii: warstwa kontrolek (kolory, ekrany, kliknięcia) i nad nią warstwa tekstów.
function pulpit(klasa, nazwa, wiazania) {
  const uklad = UKLADY[klasa]?.[nazwa];
  if (!uklad) return h('div.komunikat.blad', `Brak układu pulpitu ${nazwa} dla klasy ${klasa}.`);
  const [K, W] = FORMATY[klasa][nazwa];
  const geometria = `display:grid;grid-template-columns:repeat(${K},1fr);grid-template-rows:repeat(${W},1fr);gap:3px;padding:6px;box-sizing:border-box;`;
  const telefon = K < 13;
  const ramka = h('div', { style: `position:relative;width:100%;max-width:${telefon ? 460 : 1180}px;margin:0 auto;aspect-ratio:${K}/${W};`
    + 'background:#1d2124;border:1px solid var(--linia);border-radius:6px;container-type:inline-size' });
  const kontrolki = h('div', { style: geometria + 'position:absolute;inset:0' });
  const teksty = h('div', { style: geometria + `position:absolute;inset:0;pointer-events:none;${CZCIONKA}`, 'aria-hidden': 'true' });
  const rozmiar = telefon ? 'max(9px,2.6cqw)' : 'max(7px,0.82cqw)';
  for (const [typ, c, r, tekst, sz, wys, tekstMin] of uklad) {
    const w = wiazania[tekst];
    const aktywny = !!w;
    const pole = `grid-column:${c}/span ${sz};grid-row:${r}/span ${typ === 'S' ? 7 : wys};`;
    const el = h('div', {
      style: pole + `background:${w?.ekran ? '#0b1012' : (w?.kolor || KOLORY[typ])};border:1px solid #000;border-radius:3px;overflow:hidden;position:relative;`
        + (aktywny ? '' : 'opacity:.38;') + (w?.klik ? 'cursor:pointer;' : '')
        + (w?.wl ? 'box-shadow:inset 0 0 0 3px #1d8f7f,0 0 10px rgba(77,226,201,.85);' : ''),
      title: aktywny ? tekst : `${tekst} — bez mechaniki (kolejny etap)`, role: w?.klik ? 'button' : null, 'aria-label': tekst,
    });
    if (w?.klik) el.addEventListener('click', w.klik);
    if (w?.ekran) el.append(w.ekran());
    kontrolki.append(el);
    if (w?.ekran) continue;
    const napis = h('div', { style: pole + `display:flex;flex-direction:column;align-items:center;justify-content:${typ === 'S' ? 'space-between' : 'center'};`
      + `text-align:center;overflow:hidden;padding:3px;box-sizing:border-box;font-size:${rozmiar};line-height:1.15;`
      + `color:${typ === 'B' ? '#fff' : '#111'};${typ === 'B' || w?.wl ? 'font-weight:bold;' : ''}${aktywny ? '' : 'opacity:.38;'}` });
    if (typ === 'S') napis.append(h('span', tekst.replace('suwak: ', '')), h('span', (tekstMin || '').replace('suwak: ', '')));
    else napis.append(h('span', w?.etykieta ?? tekst.replace('suwak: ', '')));
    teksty.append(napis);
  }
  ramka.append(kontrolki, teksty);
  return ramka;
}

// ------------------------------------------------------------------------------------------- ekran radarowy
function ekranPlot(s, st, { zasieg, srodek, pierscienie, hz = false, wybierz = null, obiekty = null, sektor = null, tory = false, prognozy = false }) {
  const P = 200;
  const sk = (P - 6) / zasieg;
  const px = (x) => P + (x - srodek.x) * sk, py = (y) => P - (y - srodek.y) * sk;
  const e = svg('svg', { viewBox: `0 0 ${2 * P} ${2 * P}`, style: `width:100%;height:100%;display:block;${CZCIONKA}` });
  for (const r of pierscienie) {
    e.append(svg('circle', { cx: P, cy: P, r: r * sk, fill: 'none', stroke: 'rgba(77,226,201,.35)', 'stroke-width': 1 }));
    e.append(svg('text', { x: P + 4, y: P - r * sk + 12, fill: 'rgba(77,226,201,.7)', 'font-size': 11 }, `${r} km`));
  }
  if (sektor) {
    const a0 = rad((srodek.kurs || 0) + sektor[0]), a1 = rad((srodek.kurs || 0) + sektor[1]), R = zasieg * sk;
    e.append(svg('path', { d: `M${P} ${P} L${P + R * Math.sin(a0)} ${P - R * Math.cos(a0)} A${R} ${R} 0 0 1 ${P + R * Math.sin(a1)} ${P - R * Math.cos(a1)}Z`, fill: 'rgba(255,255,153,.12)' }));
  }
  // wiązka omiatania (położenie z zegara, obrót co st.tempo s)
  const kat = rad(((Date.now() / 1000) % st.tempo) / st.tempo * 360);
  e.append(svg('line', { x1: P, y1: P, x2: P + zasieg * sk * Math.sin(kat), y2: P - zasieg * sk * Math.cos(kat), stroke: 'rgba(77,226,201,.5)', 'stroke-width': 2 }));
  const strona = srodek.strona;
  for (const o of obiekty || []) {
    const swoj = o.strona === strona;
    const kolor = swoj ? WLASNY : OBCY;
    const x = px(o.x), y = py(o.y);
    if (x < -10 || y < -10 || x > 2 * P + 10 || y > 2 * P + 10) continue;
    const klucz = 'nr' in o;
    if (tory) {
      const sl = st.slady.get((klucz ? 'k' : 'j') + o.id) || [];
      if (sl.length > 1) e.append(svg('polyline', { points: sl.map(([sx, sy]) => `${px(sx)},${py(sy)}`).join(' '), fill: 'none', stroke: kolor, 'stroke-opacity': 0.35, 'stroke-width': 1 }));
    }
    if (prognozy && o.v) {
      const k = rad(o.kurs || 0);
      e.append(svg('line', { x1: x, y1: y, x2: px(o.x + o.v * 30 * Math.sin(k)), y2: py(o.y + o.v * 30 * Math.cos(k)), stroke: kolor, 'stroke-dasharray': '3 3', 'stroke-width': 1 }));
    }
    const g = svg('g', { transform: `translate(${x} ${y}) rotate(${o.kurs || 0})`, style: wybierz ? 'cursor:pointer' : '' });
    if (klucz) g.append(svg('path', { d: 'M0 -6 L5 5 L-5 5Z', fill: kolor }));
    else g.append(sylwetka(o.profil, kolor, Math.max(o === srodek ? 34 : 22, (o.dlugosc_km || 1) * sk)));
    if (wybierz) g.addEventListener('click', (ev) => { ev.stopPropagation(); wybierz(o); });
    e.append(g);
    const id = (klucz ? 'k' : 'j') + o.id;
    if (st.oznaczony === id || st.sledzony === id) e.append(svg('circle', { cx: x, cy: y, r: 12, fill: 'none', stroke: st.sledzony === id ? '#ffff99' : '#fff', 'stroke-width': 1.5 }));
    if (hz && klucz && !swoj) e.append(svg('text', { x: x + 8, y: y - 6, fill: '#ffd7a0', 'font-size': 11 }, o.hzZnane ? `${o.hz} Hz` : '? Hz'));
  }
  return e;
}

// ------------------------------------------------------------------------------------------- Radar
function radar(s, st, akcje) {
  const j = wlasna(s);
  const wrogie = s.klucze.filter((k) => k.stan === 'w_locie' && k.strona !== j.strona);
  const obce = [...wrogie, ...s.jednostki.filter((x) => x.strona !== j.strona)];
  const wlasne = [...s.klucze.filter((k) => k.stan === 'w_locie' && k.strona === j.strona), ...s.jednostki.filter((x) => x.strona === j.strona && x.id !== j.id)];
  const f = st.filtry;
  const widoczne = [...wlasne, ...obce].filter((o) => {
    const klucz = 'nr' in o;
    if (o.strona === j.strona && !f.wlasne) return false;
    if (o.strona !== j.strona && !f.obce) return false;
    if (klucz && !f.klucze) return false;
    if (!klucz && !f.okrety) return false;
    return true;
  }).map((o) => ({ ...o, hzZnane: odl(o, j) <= ZASIEG_SENSOROW_KM }));
  const zagrozenia = (burta) => obce.map((o) => ({ o, w: wzgledem(j, o) })).filter((x) => x.w.burta === burta).sort((a, b) => a.w.d - b.w.d).slice(0, 6);
  const lista = (burta) => () => h('div', { style: `width:100%;height:100%;overflow:auto;padding:4px;box-sizing:border-box;font-size:max(7px,0.75cqw);text-align:left;color:#cfe;${CZCIONKA}` },
    h('div', { style: 'color:#ffd7a0;margin-bottom:3px' }, `ZAGROŻENIA ${burta.toUpperCase()} BURTY`),
    ...zagrozenia(burta).map(({ o, w }) => h('div', { style: 'border-bottom:1px solid rgba(255,255,255,.1);padding:1px 0' },
      `${'nr' in o ? `${o.strona} ${o.nr} ${o.typ}` : o.nazwa} · ${w.namiar > 0 ? '+' : ''}${w.namiar}° · ${km(w.d)} km`
      + ('nr' in o && o.v ? ` · ogień za ${mmss(Math.max(0, (w.d - ZASIEG_OGNIA_KM) / o.v))}` : ''))),
    zagrozenia(burta).length ? null : h('div', { style: 'opacity:.6' }, 'brak'));
  const alarm = obce.some((o) => odl(o, j) <= st.progZblizenia);
  const sektor = { 'sektor dziobowy lewej burty': [-90, 0], 'sektor dziobowy prawej burty': [0, 90], 'sektor rufowy lewej burty': [180, 270], 'sektor rufowy prawej burty': [90, 180] };
  const przelacz = (klucz) => ({ wl: st[klucz], klik: () => { st[klucz] = !st[klucz]; akcje.odswiez(); } });
  const filtr = (k) => ({ wl: f[k], klik: () => { f[k] = !f[k]; akcje.odswiez(); } });
  const cyklicznie = (pole, wartosci, opis) => ({ etykieta: `${opis}: ${st[pole]}`, klik: () => { st[pole] = wartosci[(wartosci.indexOf(st[pole]) + 1) % wartosci.length]; akcje.odswiez(); } });
  const skala = (km) => ({ wl: st.skala === km, klik: () => { st.skala = km; akcje.odswiez(); } });
  const wyslijDoOslon = () => {
    let ile = 0;
    for (const [gi, burta] of [[0, 'lewa'], [1, 'prawa']]) {
      const g = j.generatory[gi];
      const hz = obce.filter((o) => 'nr' in o && wzgledem(j, o).burta === burta && odl(o, j) <= ZASIEG_SENSOROW_KM).map((o) => o.hz);
      if (g && hz.length) { ile++; akcje.rozkaz({ generator_id: g.id, kod: 'przestrojenie_preset', hz: stopienWGore(j.stopnie, Math.max(...hz)), pulpit: 'Radar' }); }
    }
    if (!ile) akcje.komunikat(`Brak zagrożeń w zasięgu sensorów (${ZASIEG_SENSOROW_KM} km).`);
  };
  const wybierz = (o) => { st.oznaczony = ('nr' in o ? 'k' : 'j') + o.id; akcje.odswiez(); };
  const w = {
    'EKRAN RADARU · sylwetka ISD zawsze w środku · okręgi odległości 50 / 200 / 1000 km · wiązka omiatania · kontakty IFF (własne, obce, nieznane) · klucze jako jeden symbol · tory i prognozy torów · pociski': {
      ekran: () => ekranPlot(s, st, { zasieg: st.skala, srodek: j, pierscienie: [st.skala / 4, st.skala / 2, st.skala], hz: st.syncSensory, wybierz,
        obiekty: [...widoczne, j], sektor: st.sektor ? sektor[st.sektor] : null, tory: st.tory, prognozy: st.prognozy }) },
    'skala: 50 km': skala(50), 'skala: 200 km': skala(200), 'skala: 1000 km': skala(1000),
    'odczyt: kontakty obce': { etykieta: `kontakty obce: ${obce.length}` },
    'odczyt: kontakty własne': { etykieta: `kontakty własne: ${wlasne.length}` },
    'LISTA ZAGROŻEŃ LEWEJ BURTY · namiar, odległość, czas do zasięgu ognia': { ekran: lista('lewa') },
    'LISTA ZAGROŻEŃ PRAWEJ BURTY · namiar, odległość, czas do zasięgu ognia': { ekran: lista('prawa') },
    'filtr: własne': filtr('wlasne'), 'filtr: obce': filtr('obce'), 'filtr: klucze myśliwców': filtr('klucze'), 'filtr: okręty': filtr('okrety'),
    'filtr: pociski i bolty': filtr('pociski'), 'filtr: nieznane': filtr('nieznane'),
    'oznacz kontakt': { etykieta: st.oznaczony ? 'oznaczony (dotknij kontaktu)' : 'oznacz kontakt (dotknij na ekranie)', klik: () => { st.oznaczony = null; akcje.odswiez(); } },
    'śledź kontakt (tor)': { wl: !!st.sledzony, klik: () => { st.sledzony = st.sledzony ? null : st.oznaczony; st.tory = true; akcje.odswiez(); } },
    'pokaż tory': przelacz('tory'), 'pokaż prognozy torów': przelacz('prognozy'),
    'ALARM ZBLIŻENIA': { kolor: alarm ? '#ff3333' : undefined, wl: alarm, etykieta: alarm ? `ALARM ZBLIŻENIA · < ${st.progZblizenia} km` : 'ALARM ZBLIŻENIA' },
    'suwak: próg alarmu zbliżenia': cyklicznie('progZblizenia', [10, 30, 100, 200], 'próg alarmu (km)'),
    'suwak: próg alarmu kursu kolizyjnego': cyklicznie('progKolizji', [5, 10, 30], 'próg kolizji (km)'),
    'suwak: tempo omiatania': cyklicznie('tempo', [6, 12, 24], 'omiatanie (s/obrót)'),
    'synchronizacja z sensorami (dane skanu, Hz celów)': przelacz('syncSensory'),
    'synchronizacja z nawigacją (kurs i prędkość własna)': { ...przelacz('syncNawigacja'), etykieta: st.syncNawigacja ? `kurs ${Math.round(j.kurs)}° · ${j.v} km/s` : undefined },
    'wyślij namiar zagrożenia do osłon': { klik: wyslijDoOslon },
    'kasuj ślady': { klik: () => { st.slady.clear(); akcje.odswiez(); } },
    'zamroź obraz': przelacz('zamrozony'),
  };
  for (const nazwa of Object.keys(sektor)) w[nazwa] = { wl: st.sektor === nazwa, klik: () => { st.sektor = st.sektor === nazwa ? null : nazwa; akcje.odswiez(); } };
  return pulpit(j.profil, 'Radar', w);
}

// ------------------------------------------------------------------------------------------- Sensory
function sensory(s, st, akcje) {
  const j = wlasna(s);
  const wrogie = s.klucze.filter((k) => k.stan === 'w_locie' && k.strona !== j.strona);
  const zmierzone = wrogie.filter((k) => odl(k, j) <= ZASIEG_SENSOROW_KM);
  // grupy celów: typ maszyny → maksimum Hz zmierzonych kluczy (klucz mierzony jako jeden cel)
  const grupy = {};
  for (const k of zmierzone) { const g = grupy[k.typ] ||= { typ: k.typ, maszyna: k.maszyna, hz: 0, n: 0 }; g.hz = Math.max(g.hz, k.hz); g.n++; }
  const lista = Object.values(grupy).sort((a, b) => b.hz - a.hz);
  if (st.grupa && !grupy[st.grupa]) st.grupa = null;
  const cel = st.grupa ? grupy[st.grupa] : lista[0];
  const widmo = () => {
    const maks = 3600, Wd = 300, Hd = 160;
    const e = svg('svg', { viewBox: `0 0 ${Wd} ${Hd}`, style: `width:100%;height:100%;display:block;${CZCIONKA}` });
    const x = (hz) => 8 + (hz / maks) * (Wd - 16);
    for (const hz of [900, 1800, 2700, 3600]) {
      e.append(svg('line', { x1: x(hz), y1: 4, x2: x(hz), y2: Hd - 14, stroke: 'rgba(77,226,201,.2)' }));
      e.append(svg('text', { x: x(hz) - 12, y: Hd - 3, fill: 'rgba(77,226,201,.7)', 'font-size': 9 }, hz));
    }
    j.generatory.forEach((g, i) => {
      e.append(svg('line', { x1: x(g.hz_skuteczne), y1: 4, x2: x(g.hz_skuteczne), y2: Hd - 14, stroke: '#99ff99', 'stroke-width': 2, 'stroke-dasharray': i ? '4 3' : '' }));
      e.append(svg('text', { x: x(g.hz_skuteczne) + 3, y: 12 + i * 11, fill: '#99ff99', 'font-size': 9 }, `osłona ${i ? 'P' : 'L'} ${g.hz_skuteczne}`));
    });
    lista.forEach((g, i) => {
      const y = 30 + i * 18;
      e.append(svg('rect', { x: 8, y, width: Math.max(2, x(g.hz) - 8), height: 12, fill: g === cel ? '#ff6b55' : 'rgba(255,107,85,.5)' }));
      e.append(svg('text', { x: 12, y: y + 10, fill: '#fff', 'font-size': 9 }, `${g.maszyna} ×${g.n} · ${g.hz} Hz`));
    });
    if (!lista.length) e.append(svg('text', { x: 10, y: 40, fill: 'rgba(255,255,255,.6)', 'font-size': 11 }, `brak celów w zasięgu ${ZASIEG_SENSOROW_KM} km`));
    return e;
  };
  const wyslij = () => {
    if (!cel) return akcje.komunikat('Brak zmierzonego celu do wysłania.');
    const hz = stopienWGore(j.stopnie, cel.hz);
    for (const g of j.generatory) akcje.rozkaz({ generator_id: g.id, kod: 'przestrojenie_preset', hz, pulpit: 'Sensory' });
  };
  const w = {
    'EKRAN SKANU · przestrzeń normalna (kontakty, elipsa błędu, sygnatura) i nadprzestrzeń (zmarszczki wejścia, odcinki skoków, prognoza wyjścia)': {
      ekran: () => ekranPlot(s, st, { zasieg: 1000, srodek: j, pierscienie: [ZASIEG_SENSOROW_KM, 500, 1000], hz: st.skan,
        obiekty: st.kontaktyNormalne ? [...wrogie.map((k) => ({ ...k, hzZnane: odl(k, j) <= ZASIEG_SENSOROW_KM })), ...s.jednostki.filter((x) => x.strona !== j.strona), j] : [j] }) },
    'EKRAN: WIDMO Hz GRUP CELÓW · baza i bieżący odczyt · wykryte skany wroga': { ekran: widmo },
    'skan Hz celu (wybór grupy)': { etykieta: cel ? `cel: ${cel.maszyna} · ${cel.hz} Hz` : 'skan Hz celu (brak celów)',
      klik: () => { if (!lista.length) return; const i = lista.indexOf(cel); st.grupa = lista[(i + 1) % lista.length].typ; akcje.odswiez(); } },
    'wyślij Hz celu do konsoli osłon': { klik: wyslij, etykieta: cel ? `wyślij ${stopienWGore(j.stopnie, cel.hz)} Hz do osłon` : undefined },
    'SKANUJ': { wl: st.skan, klik: () => { st.skan = !st.skan; akcje.odswiez(); }, etykieta: st.skan ? 'SKAN AKTYWNY' : 'SKANUJ' },
    'filtr: kontakty w przestrzeni normalnej': { wl: st.kontaktyNormalne, klik: () => { st.kontaktyNormalne = !st.kontaktyNormalne; akcje.odswiez(); } },
    'odczyt: elipsa błędu': { etykieta: `cele w zasięgu: ${zmierzone.length} z ${wrogie.length}` },
  };
  return pulpit(j.profil, 'Sensory', w);
}

// Konsola o nazwie pulpitu: Radar i Sensory z mechaniką, pozostałe pulpity klasy — sam układ (przygaszony).
export function konsola(nazwa, s, st, akcje) {
  if (!s.jednostki.length) return h('div.komunikat', 'Brak okrętu w tej symulacji.');
  if (nazwa === 'Radar') return radar(s, st, akcje);
  if (nazwa === 'Sensory') return sensory(s, st, akcje);
  return h('div', h('div.slaby.maly-tekst', { style: 'margin-bottom:6px' }, 'Ten pulpit nie ma jeszcze mechaniki — widać układ do nauki.'),
    pulpit(wlasna(s).profil, nazwa, {}));
}

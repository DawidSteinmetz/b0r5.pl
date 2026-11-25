// Konsole mostka w symulacji: pulpit rysowany z tych samych układów co arkusz i wydruk (pulpity_uklady.js, siatka 13×9),
// przyciski i ekrany podpięte pod stan bitwy (api/?a=bitwa_stan). Element bez mechaniki jest przygaszony.
// Warstwy (PULPITY.md 6): 1 — kontrolki i ekrany (bez napisów), 2 — teksty w kroju datapada (var(--czcionka)), więc
// przełącznik alfabetu (łaciński / Aurebesh) zmienia wszystkie napisy pulpitu naraz.
// Mechanika: Radar i Sensory; pozostałe pulpity klasy rysują się w całości, bez podpiętych elementów.

import { h } from '../rdzen/narzedzia.js';
import { UKLADY, KOLORY, FORMATY } from './pulpity_uklady.js';

// Pulpity z mechaniką (pozostałe rysują się z samym układem). Kolejność = kolejność zakładek.
export const KONSOLE = ['Radar', 'Sensory', 'Osłony', 'Reaktory', 'Myśliwce', 'Nawigacja', 'Kontrola ognia', 'Klucz', 'Eskadra'];
export const pulpityKlasy = (klasa) => Object.keys(UKLADY[klasa] || {});
const NS = 'http://www.w3.org/2000/svg';
const ZASIEG_SENSOROW_KM = 200; // jak automatyczne osłony w silniku (STALE zasieg_sensorow_km)
// Radar: do 12 km obraz na bieżąco, 12–60 km echo odświeżane przejściem wiązki. Sensory: od 60 km wzwyż, też wiązką.
const RADAR_BLISKI_KM = 12, RADAR_KM = 60, TEMPO_SKANU_S = 20;
const kosztSensorow = (km) => Math.round(0.245 * (km / 1000) ** 1.618 * 100) / 100; // jak bitwa_koszt_sensorow()
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
// Promienie okręgów zostają dokładne (rysunek w skali); zaokrągla się tylko opis — poniżej 1 km w metrach.
const zaokr = (r) => r;
const opisOdl = (r) => (r >= 10 ? `${Math.round(r)} km` : r >= 1 ? `${Math.round(r * 10) / 10} km`
  : r >= 0.01 ? `${Math.round(r * 1000)} m` : `${Math.round(r * 10000) / 10} m`);

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
    skala: 30, filtry: { wlasne: true, obce: true, klucze: true, okrety: true, pociski: true, nieznane: true },
    sektor: null, tory: true, prognozy: false, oznaczony: null, sledzony: null, zamrozony: false, slady: new Map(),
    progZblizenia: 30, progKolizji: 10, tempo: 12, syncSensory: false, syncNawigacja: false,
    grupa: null, skan: true, kontaktyNormalne: true,
    echo: {}, hzPamiec: new Map(), zmarszczki: new Map(), sladyNad: true,
    zoom: {}, hzCel: {}, klucz: null, wybranyKlucz: null, rozkazMysliwcow: 'atak', eskadra: 1, skalaNaw: 500, skalaKlucza: 10, celNaw: null,
  };
}

// Ekran z powiększaniem: + i − w rogu (×2) oraz kółko myszy (×1,4 na ząbek), od ×1/16 do ×10 000 — przy skali 50 km
// to ok. 5 m na promień ekranu, więc widać manewry krążownika i kluczy tuż przy burcie. rysuj(zoom) zwraca SVG.
function powiekszany(st, akcje, id, rysuj) {
  const z = st.zoom[id] || 1;
  const ustaw = (nowy) => { st.zoom[id] = Math.min(10000, Math.max(0.0625, nowy)); akcje.odswiez(); };
  const przycisk = (znak, mnoznik) => h('button', { type: 'button', style: 'width:26px;height:26px;padding:0;border:1px solid rgba(77,226,201,.6);border-radius:4px;'
    + 'background:rgba(11,16,18,.85);color:#4de2c9;font-size:16px;line-height:1;cursor:pointer', 'aria-label': znak === '+' ? 'Przybliż' : 'Oddal',
    onclick: (ev) => { ev.stopPropagation(); ustaw(z * mnoznik); } }, znak);
  const el = h('div', { style: 'position:relative;width:100%;height:100%' }, rysuj(z),
    h('div', { style: 'position:absolute;right:4px;top:4px;display:flex;flex-direction:column;gap:3px' }, przycisk('+', 2), przycisk('−', 0.5)),
    z !== 1 ? h('div', { style: `position:absolute;left:5px;top:4px;color:#4de2c9;font-size:11px;${CZCIONKA}` }, `×${z >= 100 ? Math.round(z) : z >= 1 ? z.toFixed(1) : z.toFixed(2)}`) : null);
  el.addEventListener('wheel', (ev) => { ev.preventDefault(); ustaw(z * (ev.deltaY < 0 ? 1.4 : 1 / 1.4)); }, { passive: false });
  return el;
}

// Lampa klucza (PULPITY.md 1b): kolor tła, kolor napisu, opis stanu.
const LAMPA = { hangar: ['#2a2a80', '#fff', 'hangar'], gotowy: ['#6666ff', '#fff', 'gotowy'], w_locie: ['#66ccff', '#000', 'w locie'],
  powrot: ['#66ccff', '#000', 'powrót'], stracony: ['#999999', '#000', 'stracony'] };
const opisLampy = (k) => (k.stan === 'hangar' && k.start_za_s != null ? `start ${mmss(k.start_za_s)}` : (LAMPA[k.stan] || LAMPA.hangar)[2]);

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

// Echo przy przejściu wiązki: kontakt w strefie (wStrefie) jest widoczny w pozycji z chwili, gdy wiązka (obrót co tempo
// sekund) ostatnio przeszła nad jego namiarem; poza strefą znika przy kolejnym przejściu. Pamięć osobna dla każdej wiązki.
const idObiektu = (o) => ('nr' in o ? 'k' : 'j') + o.id;
const namiarOd = (srodek, o) => ((Math.atan2(o.x - srodek.x, o.y - srodek.y) * 180) / Math.PI + 360) % 360;
function echo(st, kanal, srodek, obiekty, tempo, wStrefie) {
  const teraz = Date.now() / 1000;
  const kat = ((teraz % tempo) / tempo) * 360;
  const pam = (st.echo[kanal] ||= { kat: null, t: 0, obiekty: new Map() });
  const calyObrot = pam.kat === null || teraz - pam.t >= tempo;
  const omiecione = (b) => calyObrot || (b - pam.kat + 360) % 360 <= (kat - pam.kat + 360) % 360;
  const obecne = new Map(obiekty.map((o) => [idObiektu(o), o]));
  for (const [id, o] of obecne) {
    if (!omiecione(namiarOd(srodek, o))) continue;
    if (wStrefie(o)) pam.obiekty.set(id, { ...o, statyczny: true });
    else pam.obiekty.delete(id);
  }
  for (const [id, o] of pam.obiekty) if (!obecne.has(id) && omiecione(namiarOd(srodek, o))) pam.obiekty.delete(id);
  pam.kat = kat;
  pam.t = teraz;
  return [...pam.obiekty.values()];
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
    // Szary suwak zakresu: górna połowa — maksimum, dolna — minimum.
    if (w?.klikS) {
      el.style.cursor = 'pointer';
      el.addEventListener('click', (ev) => { const r = el.getBoundingClientRect(); w.klikS(ev.clientY - r.top < r.height / 2); });
    }
    if (w?.ekran) el.append(w.ekran());
    kontrolki.append(el);
    if (w?.ekran) continue;
    const napis = h('div', { style: pole + `display:flex;flex-direction:column;align-items:center;justify-content:${typ === 'S' ? 'space-between' : 'center'};`
      + `text-align:center;overflow:hidden;padding:3px;box-sizing:border-box;font-size:${rozmiar};line-height:1.15;`
      + `color:${w?.kolorTekstu || (typ === 'B' ? '#fff' : '#111')};${typ === 'B' || w?.wl ? 'font-weight:bold;' : ''}${aktywny ? '' : 'opacity:.38;'}` });
    if (typ === 'S') napis.append(h('span', w?.etykieta ?? tekst.replace('suwak: ', '')), h('span', w?.etykietaMin ?? (tekstMin || '').replace('suwak: ', '')));
    else napis.append(h('span', w?.etykieta ?? tekst.replace('suwak: ', '')));
    teksty.append(napis);
  }
  ramka.append(kontrolki, teksty);
  return ramka;
}

// ------------------------------------------------------------------------------------------- ekran radarowy
function ekranPlot(s, st, { zasieg, srodek, pierscienie, hz = false, wybierz = null, obiekty = null, sektor = null, tory = false, prognozy = false,
  tempo = st.tempo, maska = 0, strefy = [], zmarszczki = [] }) {
  const P = 200;
  const sk = (P - 6) / zasieg;
  const px = (x) => P + (x - srodek.x) * sk, py = (y) => P - (y - srodek.y) * sk;
  const e = svg('svg', { viewBox: `0 0 ${2 * P} ${2 * P}`, style: `width:100%;height:100%;display:block;${CZCIONKA}` });
  for (const r of pierscienie) {
    e.append(svg('circle', { cx: P, cy: P, r: r * sk, fill: 'none', stroke: 'rgba(77,226,201,.35)', 'stroke-width': 1 }));
    e.append(svg('text', { x: P + 4, y: P - r * sk + 12, fill: 'rgba(77,226,201,.7)', 'font-size': 11 }, opisOdl(r)));
  }
  // Strefa innego urządzenia (np. wnętrze 60 km na ekranie sensorów — tam pracuje radar) i granice stref (przerywane).
  if (maska) {
    e.append(svg('circle', { cx: P, cy: P, r: maska * sk, fill: 'rgba(20,30,34,.92)', stroke: 'rgba(77,226,201,.4)', 'stroke-dasharray': '4 4' }));
    if (maska * sk > 30) e.append(svg('text', { x: P, y: P + 4, fill: 'rgba(77,226,201,.55)', 'font-size': 10, 'text-anchor': 'middle' }, `strefa radaru · ${maska} km`));
  }
  for (const { r, opis } of strefy) {
    if (r * sk > 2 * P) continue;
    e.append(svg('circle', { cx: P, cy: P, r: r * sk, fill: 'none', stroke: 'rgba(255,215,160,.55)', 'stroke-dasharray': '6 4', 'stroke-width': 1.2 }));
    e.append(svg('text', { x: P - 4, y: P + r * sk - 4, fill: 'rgba(255,215,160,.75)', 'font-size': 10, 'text-anchor': 'end' }, opis));
  }
  // Zmarszczki nadprzestrzeni: przewidywane miejsce wyjścia ze skoku z elipsą niepewności (maleje z czasem obserwacji).
  for (const z of zmarszczki) {
    const x = px(z.x), y = py(z.y), r = Math.max(4, z.r * sk);
    const g = svg('g', { opacity: z.slaba ? 0.45 : 0.9 });
    for (const m of [1, 0.66, 0.33]) g.append(svg('circle', { cx: x, cy: y, r: r * m, fill: 'none', stroke: '#c99bff', 'stroke-width': 1.2, 'stroke-dasharray': '3 3' }));
    g.append(svg('animate', { attributeName: 'opacity', values: z.slaba ? '0.45;0.15;0.45' : '0.9;0.4;0.9', dur: '2s', repeatCount: 'indefinite' }));
    g.append(svg('text', { x: x + r + 3, y: y + 4, fill: '#d9c2ff', 'font-size': 10 }, z.opis));
    e.append(g);
  }
  if (sektor) {
    const a0 = rad((srodek.kurs || 0) + sektor[0]), a1 = rad((srodek.kurs || 0) + sektor[1]), R = zasieg * sk;
    e.append(svg('path', { d: `M${P} ${P} L${P + R * Math.sin(a0)} ${P - R * Math.cos(a0)} A${R} ${R} 0 0 1 ${P + R * Math.sin(a1)} ${P - R * Math.cos(a1)}Z`, fill: 'rgba(255,255,153,.12)' }));
  }
  // Wiązka omiatania: obraca się płynnie (animacja SVG; faza z zegara, więc przerysowanie co sekundę jej nie przerywa),
  // za nią rozmyta smuga zanikającej poświaty. Kontakty rozbłyskują, gdy wiązka nad nimi przechodzi, i przygasają.
  const katDeg = (((Date.now() / 1000) % tempo) / tempo) * 360;
  const R = zasieg * sk;
  const idRozmycia = 'rozmycie' + Math.random().toString(36).slice(2, 9);
  const filtr = svg('filter', { id: idRozmycia, x: '-20%', y: '-20%', width: '140%', height: '140%' });
  filtr.append(svg('feGaussianBlur', { stdDeviation: 2.2 }));
  const defs = svg('defs');
  defs.append(filtr);
  e.append(defs);
  const wiazka = svg('g');
  const smuga = svg('g', { filter: `url(#${idRozmycia})` });
  const PLASTRY = 20, KROK = 3; // 20 plastrów po 3° = 60° poświaty za wiązką
  for (let i = PLASTRY - 1; i >= 0; i--) {
    const a0 = rad(-(i + 1) * KROK), a1 = rad(-i * KROK);
    smuga.append(svg('path', { d: `M${P} ${P} L${P + R * Math.sin(a0)} ${P - R * Math.cos(a0)} A${R} ${R} 0 0 1 ${P + R * Math.sin(a1)} ${P - R * Math.cos(a1)}Z`,
      fill: '#4de2c9', 'fill-opacity': (0.26 * (1 - i / PLASTRY) ** 2).toFixed(3) }));
  }
  wiazka.append(smuga, svg('line', { x1: P, y1: P, x2: P, y2: P - R, stroke: 'rgba(160,255,240,.9)', 'stroke-width': 2, filter: `url(#${idRozmycia})` }),
    svg('line', { x1: P, y1: P, x2: P, y2: P - R, stroke: 'rgba(160,255,240,.9)', 'stroke-width': 1 }));
  wiazka.append(svg('animateTransform', { attributeName: 'transform', type: 'rotate', from: `${katDeg} ${P} ${P}`, to: `${katDeg + 360} ${P} ${P}`,
    dur: `${tempo}s`, repeatCount: 'indefinite' }));
  e.append(wiazka);
  const strona = srodek.strona;
  for (const o of obiekty || []) {
    const swoj = o.strona === strona;
    const kolor = swoj ? WLASNY : OBCY;
    const x = px(o.x), y = py(o.y);
    if (x < -10 || y < -10 || x > 2 * P + 10 || y > 2 * P + 10) continue;
    const klucz = 'nr' in o;
    if (tory && !o.statyczny) {
      // Zanikający, rozmyty ogon za kontaktem (tylko obraz na bieżąco — echo nie zdradza ruchu między przejściami wiązki): ostatnie odczyty, im starszy odcinek, tym słabszy i cieńszy.
      const sl = (st.slady.get((klucz ? 'k' : 'j') + o.id) || []).slice(-16);
      const ogon = svg('g', { filter: `url(#${idRozmycia})` });
      for (let i = 1; i < sl.length; i++) {
        const f = i / (sl.length - 1);
        ogon.append(svg('line', { x1: px(sl[i - 1][0]), y1: py(sl[i - 1][1]), x2: px(sl[i][0]), y2: py(sl[i][1]), stroke: kolor,
          'stroke-opacity': (0.55 * f).toFixed(3), 'stroke-width': (1 + 2.5 * f).toFixed(2), 'stroke-linecap': 'round' }));
      }
      if (sl.length > 1) e.append(ogon);
    }
    if (prognozy && o.v) {
      const k = rad(o.kurs || 0);
      e.append(svg('line', { x1: x, y1: y, x2: px(o.x + o.v * 30 * Math.sin(k)), y2: py(o.y + o.v * 30 * Math.cos(k)), stroke: kolor, 'stroke-dasharray': '3 3', 'stroke-width': 1 }));
    }
    // Ruch między odczytami (co 1 s): kontakt przesuwa się płynnie po swoim kursie, zamiast skakać.
    const ruch = svg('g');
    if (o.v && o !== srodek && !o.statyczny) {
      const k = rad(o.kurs || 0);
      ruch.append(svg('animateTransform', { attributeName: 'transform', type: 'translate', from: '0 0',
        to: `${(o.v * Math.sin(k) * sk).toFixed(3)} ${(-o.v * Math.cos(k) * sk).toFixed(3)}`, dur: '1s', fill: 'freeze' }));
    }
    // Poświata echa: pełna jasność, gdy wiązka przechodzi nad kontaktem, potem zanik do następnego obrotu.
    if (o !== srodek && o.statyczny) {
      const namiar = ((Math.atan2(x - P, P - y) * 180) / Math.PI + 360) % 360;
      const odPrzejscia = (((katDeg - namiar) % 360) + 360) % 360 / 360 * tempo;
      ruch.append(svg('animate', { attributeName: 'opacity', values: '1;0.3', keyTimes: '0;1', dur: `${tempo}s`, begin: `-${odPrzejscia.toFixed(2)}s`, repeatCount: 'indefinite' }));
    }
    const g = svg('g', { transform: `translate(${x} ${y}) rotate(${o.kurs || 0})`, style: wybierz ? 'cursor:pointer' : '' });
    if (klucz) g.append(svg('path', { d: 'M0 -6 L5 5 L-5 5Z', fill: kolor }));
    else {
      // Przy dużym powiększeniu okręt w skali bywa większy od ekranu — wtedy półprzezroczysty, żeby nie zasłaniał kontaktów.
      const rozmiar = Math.max(o === srodek ? 34 : 22, (o.dlugosc_km || 1) * sk);
      const syl = sylwetka(o.profil, kolor, rozmiar);
      if (rozmiar > P * 0.8) syl.setAttribute('opacity', rozmiar > 3 * P ? 0.18 : 0.35);
      g.append(syl);
    }
    if (wybierz) g.addEventListener('click', (ev) => { ev.stopPropagation(); wybierz(o); });
    ruch.append(g);
    const id = (klucz ? 'k' : 'j') + o.id;
    if (st.oznaczony === id || st.sledzony === id) ruch.append(svg('circle', { cx: x, cy: y, r: 12, fill: 'none', stroke: st.sledzony === id ? '#ffff99' : '#fff', 'stroke-width': 1.5 }));
    if (hz && klucz && !swoj) ruch.append(svg('text', { x: x + 8, y: y - 6, fill: '#ffd7a0', 'font-size': 11 }, st.hzPamiec.has(o.id) ? `${st.hzPamiec.get(o.id)} Hz` : '? Hz'));
    e.append(ruch);
  }
  return e;
}

// ------------------------------------------------------------------------------------------- Radar
function radar(s, st, akcje) {
  const j = wlasna(s);
  // Co widzi radar: do 12 km na bieżąco, 12–60 km echo z ostatniego przejścia wiązki, dalej nic (to zasięg sensorów).
  const wszystkie = [...s.klucze.filter((k) => k.stan === 'w_locie'), ...s.jednostki.filter((x) => x.id !== j.id && x.stan !== 'oczekuje')];
  const blisko = wszystkie.filter((o) => odl(o, j) <= RADAR_BLISKI_KM);
  const dalej = echo(st, 'radar', j, wszystkie, st.tempo, (o) => { const d = odl(o, j); return d > RADAR_BLISKI_KM && d <= RADAR_KM; });
  const znane = [...blisko, ...dalej.filter((o) => !blisko.some((b) => idObiektu(b) === idObiektu(o)))];
  const wrogie = znane.filter((o) => 'nr' in o && o.strona !== j.strona);
  const obce = znane.filter((o) => o.strona !== j.strona);
  const wlasne = znane.filter((o) => o.strona === j.strona);
  const f = st.filtry;
  const widoczne = [...wlasne, ...obce].filter((o) => {
    const klucz = 'nr' in o;
    if (o.strona === j.strona && !f.wlasne) return false;
    if (o.strona !== j.strona && !f.obce) return false;
    if (klucz && !f.klucze) return false;
    if (!klucz && !f.okrety) return false;
    return true;
  });
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
  const skala = (km) => ({ wl: st.skala === km && (st.zoom.radar || 1) === 1, klik: () => { st.skala = km; st.zoom.radar = 1; akcje.odswiez(); } });
  const wyslijDoOslon = () => {
    let ile = 0;
    for (const [gi, burta] of [[0, 'lewa'], [1, 'prawa']]) {
      const g = j.generatory[gi];
      const hz = obce.filter((o) => 'nr' in o && wzgledem(j, o).burta === burta && st.hzPamiec.has(o.id)).map((o) => st.hzPamiec.get(o.id));
      if (g && hz.length) { ile++; akcje.rozkaz({ generator_id: g.id, kod: 'przestrojenie_preset', hz: stopienWGore(j.stopnie, Math.max(...hz)), pulpit: 'Radar' }); }
    }
    if (!ile) akcje.komunikat('Brak zagrożeń ze zmierzonym Hz — Hz mierzą sensory (od 60 km).');
  };
  const wybierz = (o) => { st.oznaczony = ('nr' in o ? 'k' : 'j') + o.id; akcje.odswiez(); };
  const w = {
    'EKRAN RADARU · sylwetka ISD zawsze w środku · bliski zasięg 12 km na bieżąco · średni do 60 km odświeżany wiązką · kontakty IFF (własne, obce, nieznane) · klucze jako jeden symbol · tory i prognozy torów · pociski': {
      ekran: () => powiekszany(st, akcje, 'radar', (z) => ekranPlot(s, st, { zasieg: st.skala / z, srodek: j, pierscienie: [st.skala / 4 / z, st.skala / 2 / z, st.skala / z].map(zaokr),
        hz: st.syncSensory, wybierz, obiekty: [...widoczne, j], sektor: st.sektor ? sektor[st.sektor] : null, tory: st.tory, prognozy: st.prognozy,
        strefy: [{ r: RADAR_BLISKI_KM, opis: 'na bieżąco' }, { r: RADAR_KM, opis: 'granica radaru' }] })) },
    'skala: 12 km': skala(12), 'skala: 30 km': skala(30), 'skala: 60 km': skala(60),
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
  const zasieg = j.zasieg_sensorow_km || 1000;
  // Skan od 60 km do zasięgu (moc sensorów), odświeżany wiązką co TEMPO_SKANU_S s; Hz zmierzone zostają w pamięci.
  const wszystkie = [...s.klucze.filter((k) => k.stan === 'w_locie'), ...s.jednostki.filter((x) => x.id !== j.id && x.stan !== 'oczekuje')];
  const wSkanie = echo(st, 'skan', j, wszystkie, TEMPO_SKANU_S, (o) => { const d = odl(o, j); return d >= RADAR_KM && d <= zasieg; });
  for (const o of wSkanie) if ('nr' in o && o.strona !== j.strona) st.hzPamiec.set(o.id, o.hz);
  const wrogie = s.klucze.filter((k) => k.stan === 'w_locie' && k.strona !== j.strona);
  const zmierzone = wrogie.filter((k) => st.hzPamiec.has(k.id)).map((k) => ({ ...k, hz: st.hzPamiec.get(k.id) }));
  // Zmarszczki: jednostki przed wyjściem ze skoku (widać dobrze) i klucze z zewnątrz (słabo, tylko w połowie zasięgu).
  const teraz = Date.now() / 1000;
  const zmarszczki = st.sladyNad ? [
    ...s.jednostki.filter((x) => x.stan === 'oczekuje' && odl(x, j) <= zasieg).map((x) => ({ o: x, slaba: false, r0: 20, opis: `${x.nazwa} · wyjście za ${mmss(x.wejscie_za_s)}` })),
    ...s.klucze.filter((k) => k.z_zewnatrz && k.stan === 'hangar' && k.start_za_s != null && k.strona !== j.strona && odl(k, j) <= zasieg / 2)
      .map((k) => ({ o: k, slaba: true, r0: 40, opis: `klucze? · ${mmss(k.start_za_s)}` })),
  ].map(({ o, slaba, r0, opis }) => {
    const id = idObiektu(o);
    if (!st.zmarszczki.has(id)) st.zmarszczki.set(id, teraz);
    return { x: o.x, y: o.y, slaba, opis, r: r0 / (1 + (teraz - st.zmarszczki.get(id)) / 10) };
  }) : [];
  const poziomy = j.zasiegi_sensorow || [1000, 3000, 10000, 30000];
  const moc = { etykieta: `zasięg ${zasieg} km · ${kosztSensorow(zasieg)} porcji`,
    klik: () => akcje.rozkaz({ jednostka_id: j.id, kod: 'sensory_zasieg', zasieg_km: poziomy[(poziomy.indexOf(zasieg) + 1) % poziomy.length], pulpit: 'Sensory' }) };
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
    if (!lista.length) e.append(svg('text', { x: 10, y: 40, fill: 'rgba(255,255,255,.6)', 'font-size': 11 }, 'brak zmierzonych celów (skan od 60 km)'));
    return e;
  };
  const wyslij = () => {
    if (!cel) return akcje.komunikat('Brak zmierzonego celu do wysłania.');
    const hz = stopienWGore(j.stopnie, cel.hz);
    for (const g of j.generatory) akcje.rozkaz({ generator_id: g.id, kod: 'przestrojenie_preset', hz, pulpit: 'Sensory' });
  };
  const w = {
    'EKRAN SKANU · przestrzeń normalna (kontakty, elipsa błędu, sygnatura) i nadprzestrzeń (zmarszczki wejścia, odcinki skoków, prognoza wyjścia)': {
      ekran: () => powiekszany(st, akcje, 'skan', (z) => ekranPlot(s, st, { zasieg: zasieg / z, srodek: j, pierscienie: [RADAR_KM, zasieg / 2, zasieg].map((r) => zaokr(r / z)),
        tempo: TEMPO_SKANU_S, maska: RADAR_KM, hz: st.skan, zmarszczki, obiekty: st.kontaktyNormalne ? [...wSkanie, j] : [j] })) },
    'suwak: przydział mocy sensorów': moc,
    'suwak: zasięg J skanu nadprzestrzeni (koszt k·J^1,618)': moc,
    'odczyt: koszt skanu (porcje)': { etykieta: `koszt: ${kosztSensorow(zasieg)} porcji` },
    'filtr: ślady nadprzestrzenne': { wl: st.sladyNad, klik: () => { st.sladyNad = !st.sladyNad; akcje.odswiez(); } },
    'EKRAN: WIDMO Hz GRUP CELÓW · baza i bieżący odczyt · wykryte skany wroga': { ekran: widmo },
    'skan Hz celu (wybór grupy)': { etykieta: cel ? `cel: ${cel.maszyna} · ${cel.hz} Hz` : 'skan Hz celu (brak celów)',
      klik: () => { if (!lista.length) return; const i = lista.indexOf(cel); st.grupa = lista[(i + 1) % lista.length].typ; akcje.odswiez(); } },
    'wyślij Hz celu do konsoli osłon': { klik: wyslij, etykieta: cel ? `wyślij ${stopienWGore(j.stopnie, cel.hz)} Hz do osłon` : undefined },
    'SKANUJ': { wl: st.skan, klik: () => { st.skan = !st.skan; akcje.odswiez(); }, etykieta: st.skan ? 'SKAN AKTYWNY' : 'SKANUJ' },
    'filtr: kontakty w przestrzeni normalnej': { wl: st.kontaktyNormalne, klik: () => { st.kontaktyNormalne = !st.kontaktyNormalne; akcje.odswiez(); } },
    'odczyt: elipsa błędu': { etykieta: `w skanie: ${wSkanie.length} · zmarszczki: ${zmarszczki.length} · Hz znane: ${zmierzone.length}` },
  };
  return pulpit(j.profil, 'Sensory', w);
}

// ------------------------------------------------------------------------------------------- wspólne dla kluczy
const RANGA = { as: 'as', standard: 'standardowy', kadet: 'kadet' };
const wlasneKlucze = (s, j) => s.klucze.filter((k) => k.strona === j.strona && !k.ai).sort((a, b) => a.nr - b.nr);
const doKlucza = (akcje, k, kod, pulpit) => akcje.rozkaz({ klucz_id: k.id, kod, pulpit });
const lampa = (k, etykieta) => ({ kolor: (LAMPA[k.stan] || LAMPA.hangar)[0], kolorTekstu: (LAMPA[k.stan] || LAMPA.hangar)[1], etykieta });

// ------------------------------------------------------------------------------------------- Osłony
// Ekran żył generatora: lampka = żyła; świeci, gdy n mod (N/f) = 0 (MECHANIKA 3.1); martwa — ciemnoczerwona.
function ekranZyl(g, st, akcje, stopnie) {
  const K = g.siatka_k || 60, n = g.n || 3600, f = g.hz_osiagane || 0, krok = f && n % f === 0 ? n / f : 0;
  const W = Math.ceil(n / K);
  const martwe = Uint8Array.from(atob(g.martwe_b64 || ''), (c) => c.charCodeAt(0));
  const c = h('canvas', { width: K, height: W, style: 'width:100%;flex:1;min-height:0;image-rendering:pixelated;object-fit:contain;display:block' });
  const ctx = c.getContext('2d');
  const obraz = ctx.createImageData(K, W);
  for (let i = 0; i < n; i++) {
    const martwa = (martwe[i >> 3] >> (i & 7)) & 1;
    const swieci = krok && (i + 1) % krok === 0;
    const [r, gg, b] = martwa ? [122, 31, 31] : swieci ? [77, 226, 201] : [22, 52, 58];
    obraz.data.set([r, gg, b, 255], i * 4);
  }
  ctx.putImageData(obraz, 0, 0);
  const cel = st.hzCel[g.id] ?? g.hz_zadane;
  const krokHz = (kier) => () => {
    const i = stopnie.indexOf(cel);
    st.hzCel[g.id] = stopnie[Math.min(stopnie.length - 1, Math.max(0, (i < 0 ? stopnie.findIndex((x) => x >= cel) : i) + kier))];
    akcje.odswiez();
  };
  const maly = (tekst, fn, wl) => h('button', { type: 'button', onclick: (ev) => { ev.stopPropagation(); fn(); },
    style: `padding:1px 6px;border:1px solid rgba(77,226,201,.6);border-radius:3px;background:${wl ? '#1d8f7f' : 'rgba(11,16,18,.9)'};color:#cfe;font-size:11px;cursor:pointer;${CZCIONKA}` }, tekst);
  return h('div', { style: `display:flex;flex-direction:column;width:100%;height:100%;padding:3px;box-sizing:border-box;gap:3px;color:#cfe;font-size:max(7px,0.72cqw);${CZCIONKA}` },
    h('div', `${g.hz_osiagane}${g.hz_zadane !== g.hz_osiagane ? ` → ${g.hz_zadane}` : ''} Hz · skuteczne ${g.hz_skuteczne} · obciążenie ${g.obciazenie_proc}% · martwe ${g.martwe}`),
    c,
    h('div', { style: 'display:flex;gap:4px;justify-content:center;align-items:center;flex-wrap:wrap' },
      maly('◀', krokHz(-1)), h('span', `${cel} Hz`), maly('▶', krokHz(1)),
      maly('USTAW', () => akcje.rozkaz({ generator_id: g.id, kod: 'przestrojenie_reczne', hz: cel, pulpit: 'Osłony' })),
      maly('AUTO', () => akcje.rozkaz({ generator_id: g.id, kod: 'auto', pulpit: 'Osłony' }), g.auto)));
}

function oslony(s, st, akcje) {
  const j = wlasna(s);
  const [L, P] = j.generatory;
  const stopnie = j.stopnie;
  // Szary suwak: zakres trybu automatycznego (MIN / MAX) — górna połowa zmienia maksimum, dolna minimum.
  const zakres = (g) => g && ({
    etykieta: `maks. ${g.hz_max} Hz`, etykietaMin: `min. ${g.hz_min} Hz`,
    klikS: (gora) => {
      const nast = (v, filtr) => { const l = stopnie.filter(filtr); return l[(l.indexOf(v) + 1) % l.length] ?? l[0]; };
      const min = gora ? g.hz_min : nast(g.hz_min, (x) => x <= g.hz_max);
      const max = gora ? nast(g.hz_max, (x) => x >= g.hz_min) : g.hz_max;
      akcje.rozkaz({ generator_id: g.id, kod: 'zakres', hz_min: min, hz_max: max, pulpit: 'Osłony' });
    },
  });
  const w = {
    'GENERATOR LEWY · SIATKA 60×60 (3600 lampek) · ekran dynamiczny · żyły: świecące / martwe / temperatura': L && { ekran: () => ekranZyl(L, st, akcje, stopnie) },
    'GENERATOR PRAWY · SIATKA 60×60 (3600 lampek) · ekran dynamiczny · żyły: świecące / martwe / temperatura': P && { ekran: () => ekranZyl(P, st, akcje, stopnie) },
    'suwak: maksymalna częstotliwość lewego generatora osłon': zakres(L),
    'suwak: maksymalna częstotliwość prawego generatora osłon': zakres(P),
    'wyrównaj osłony': { etykieta: 'wyrównaj osłony (oba na auto)', klik: () => j.generatory.forEach((g) => akcje.rozkaz({ generator_id: g.id, kod: 'auto', pulpit: 'Osłony' })) },
  };
  return pulpit(j.profil, 'Osłony', w);
}

// ------------------------------------------------------------------------------------------- Reaktory
function reaktory(s, st, akcje) {
  const j = wlasna(s);
  const pobor = j.pobor_oslon_proc || 0;
  const sensory = j.pobor_sensorow_proc || 0;
  const ekran = () => {
    const e = svg('svg', { viewBox: '0 0 200 200', style: `width:100%;height:100%;display:block;${CZCIONKA}` });
    for (let i = 0; i < 100; i++) {
      const x = 12 + (i % 10) * 17.6, y = 12 + Math.floor(i / 10) * 15;
      e.append(svg('rect', { x, y, width: 15, height: 12, rx: 2,
        fill: i < Math.round(pobor) ? '#4de2c9' : i < Math.round(pobor + sensory) ? '#c99bff' : 'rgba(77,226,201,.12)' }));
    }
    e.append(svg('text', { x: 12, y: 166, fill: '#4de2c9', 'font-size': 9 }, `osłony ${pobor}%`));
    e.append(svg('text', { x: 80, y: 166, fill: '#c99bff', 'font-size': 9 }, `sensory ${sensory}%`));
    e.append(svg('text', { x: 12, y: 179, fill: '#cfe', 'font-size': 9 }, `wolne ${(100 - pobor - sensory).toFixed(1)}% · dławienie 100%`));
    e.append(svg('text', { x: 12, y: 192, fill: 'rgba(207,238,238,.6)', 'font-size': 8 }, 'ciepło, zasobniki — kolejny etap'));
    return e;
  };
  return pulpit(j.profil, 'Reaktory', {
    'REAKTOR GŁÓWNY (jonizacji słonecznej I-a2b) · SIATKA 60×60 (3600 lampek) · ekran dynamiczny · porcje mocy, ciepło, dławienie': { ekran },
    'suwak: przydział mocy dla osłon': { etykieta: `osłony: ${pobor}% mocy` },
  });
}

// ------------------------------------------------------------------------------------------- Klucz (zbliżenie)
const FIGURY = {
  'łagodnie w lewo': 'lagodnie_l', 'łagodnie w prawo': 'lagodnie_p', 'ostro w lewo': 'ostro_l', 'ostro w prawo': 'ostro_p',
  'rozejście klucza w lewo': 'rozejscie_l', 'rozejście klucza w prawo': 'rozejscie_p', 'beczka w lewo (rozrzut ×1,6)': 'beczka_l', 'zygzak (×1,3)': 'zygzak',
  'PROSTO': 'prosto', 'burst (×1,5)': 'burst', 'beczka w prawo (×1,6)': 'beczka_p', 'pętla (zawrót górą)': 'petla', 'spirala asa (×2, tylko as)': 'spirala',
  'zwarcie szyku': 'zwarcie', 'skok cieniowy (×2,5, tylko as)': 'skok_cieniowy', 'split-S (zawrót dołem)': 'split_s', 'atak z nurkowania': 'nurkowanie',
  'nożyce (pojedynek)': 'nozyce', 'ogon (trzymaj się celu)': 'ogon', 'przelot przez cel': 'przelot', 'eskorta wskazanej jednostki': 'eskorta',
  'patrol': 'patrol', 'ochrona okrętu': 'ochrona', 'skan z bliska': 'skan', 'powrót do hangaru': 'powrot',
};

function kluczPulpit(s, st, akcje, k, naglowekKlik) {
  const j = wlasna(s);
  const srodek = k.stan === 'w_locie' || k.stan === 'powrot' ? k : { ...j, nr: k.nr, strona: k.strona };
  const skale = [2, 10, 30];
  const w = {
    'KLUCZ N · 4/4 maszyn · pilot prowadzący i ranga · stan: w hangarze — start za mm:ss / gotowy / w locie (kolor nagłówka = kolor lampy klucza)': {
      ...lampa(k, `KLUCZ ${k.nr} · ${k.maszyna} · ${k.maszyny}/4 · ${RANGA[k.ranga] || k.ranga} · ${opisLampy(k)} · ${k.rozkaz_nazwa || k.rozkaz}`
        + (k.przygotowanie ? ` · ${k.przygotowanie.nazwa} za ${k.przygotowanie.dziala_za_s} s` : '') + (k.figura ? ` · ${k.figura}` : '')),
      klik: naglowekKlik,
    },
    'EKRAN KLUCZA · klucz zawsze w środku · okręgi 2 / 10 / 30 km (dotknięcie zmienia skalę) · kurs i wektor · cele (dotknij, by wybrać) · okręt macierzysty · inne klucze': {
      klik: () => { st.skalaKlucza = skale[(skale.indexOf(st.skalaKlucza) + 1) % skale.length]; st.zoom.klucz = 1; akcje.odswiez(); },
      ekran: () => powiekszany(st, akcje, 'klucz', (z) => ekranPlot(s, st, { zasieg: st.skalaKlucza / z, srodek,
        pierscienie: [st.skalaKlucza / 5, st.skalaKlucza / 2, st.skalaKlucza].map((r) => zaokr(r / z)), prognozy: true,
        obiekty: [...s.klucze.filter((x) => x.stan === 'w_locie' && x.id !== k.id), ...s.jednostki, srodek] })) },
    'odczyt: Hz klucza (baza / bieżący) · zmiany w cyklu': { etykieta: `Hz ${k.hz} · ${k.v ? `${k.v} km/s` : '—'}` },
    'START (gdy gotowy) · ATAK (w locie)': { etykieta: k.stan === 'hangar' ? 'START' : 'ATAK', klik: () => doKlucza(akcje, k, k.stan === 'hangar' ? 'start' : 'atak', 'Klucz') },
  };
  for (const [tekst, kod] of Object.entries(FIGURY)) w[tekst] = { klik: () => doKlucza(akcje, k, kod, 'Klucz') };
  return pulpit(j.profil, 'Klucz', w);
}

// Zbliżenie klucza nad innym pulpitem (Myśliwce, Eskadra): przycisk powrotu + ekran Klucz.
function zblizenie(s, st, akcje, k, skad) {
  return h('div',
    h('button.przycisk.drugi.maly', { style: 'margin-bottom:6px', onclick: () => { st.zoomKlucz = null; akcje.odswiez(); } }, `← ${skad}`),
    kluczPulpit(s, st, akcje, k, null));
}

// ------------------------------------------------------------------------------------------- Myśliwce
function mysliwce(s, st, akcje) {
  const j = wlasna(s);
  const klucze = wlasneKlucze(s, j);
  const zoom = klucze.find((k) => k.id === st.zoomKlucz);
  if (zoom) return zblizenie(s, st, akcje, zoom, 'Myśliwce');
  const wybrany = klucze.find((k) => k.id === st.wybranyKlucz);
  const ROZK = ['atak', 'ochrona', 'patrol', 'skan'];
  const nazwyR = { atak: 'atak', ochrona: 'ochrona', patrol: 'patrol', skan: 'skan' };
  const wymagaj = (fn) => () => (wybrany ? fn(wybrany) : akcje.komunikat('Najpierw wybierz klucz (lampka „Klucz N”; drugie dotknięcie — zbliżenie).'));
  const strona = (od, doN) => klucze.filter((k) => k.nr >= od && k.nr <= doN);
  const kolejka = (od, doN, opis) => () => h('div', { style: `padding:3px 5px;font-size:max(7px,0.72cqw);color:#cfe;text-align:left;width:100%;${CZCIONKA}` },
    `${opis}: ` + (strona(od, doN).filter((k) => k.stan === 'hangar' && k.start_za_s != null).sort((a, b) => a.start_za_s - b.start_za_s)
      .map((k) => `K${k.nr} ${mmss(k.start_za_s)}`).join(' · ') || 'pusta'));
  const startStrony = (od, doN) => () => {
    const k = strona(od, doN).find((x) => x.stan === 'hangar' && x.start_za_s == null);
    if (k) doKlucza(akcje, k, 'start', 'Myśliwce'); else akcje.komunikat('Na tym pokładzie nie ma klucza czekającego na start.');
  };
  const powrotStrony = (od, doN) => () => strona(od, doN).filter((k) => k.stan === 'w_locie').forEach((k) => doKlucza(akcje, k, 'powrot', 'Myśliwce'));
  const w = {
    'EKRAN: PILOCI · ZAŁOGA · TAŚMOCIĄG': { ekran: () => powiekszany(st, akcje, 'mysliwce', (z) => ekranPlot(s, st, { zasieg: 30 / z, srodek: j,
      pierscienie: [5, 15, 30].map((r) => zaokr(r / z)), prognozy: true, wybierz: (o) => { if ('nr' in o && !o.ai) { st.wybranyKlucz = o.id; akcje.odswiez(); } },
      obiekty: [...s.klucze.filter((x) => x.stan === 'w_locie'), ...s.jednostki.filter((x) => x.id !== j.id), j] })) },
    'taśmociąg lewego pokładu · kolejka i ETA': { ekran: kolejka(1, 9, 'lewy pokład') },
    'taśmociąg prawego pokładu · kolejka i ETA': { ekran: kolejka(10, 18, 'prawy pokład') },
    'start klucza (lewy pokład)': { klik: startStrony(1, 9) }, 'start klucza (prawy pokład)': { klik: startStrony(10, 18) },
    'powrót do hangaru (lewy pokład)': { klik: powrotStrony(1, 9) }, 'powrót do hangaru (prawy pokład)': { klik: powrotStrony(10, 18) },
    'rozkaz klucza: patrol · ochrona · atak · skan': { etykieta: `rozkaz: ${nazwyR[st.rozkazMysliwcow]}${wybrany ? ` → klucz ${wybrany.nr}` : ''} (dotknij, by zmienić)`,
      klik: () => { st.rozkazMysliwcow = ROZK[(ROZK.indexOf(st.rozkazMysliwcow) + 1) % ROZK.length]; akcje.odswiez(); } },
    'kurs przechwycenia': { klik: wymagaj((k) => doKlucza(akcje, k, 'atak', 'Myśliwce')) },
    'wstrzymaj / zawróć': { etykieta: 'wstrzymaj / zawróć (ochrona okrętu)', klik: wymagaj((k) => doKlucza(akcje, k, 'ochrona', 'Myśliwce')) },
    'START': { etykieta: wybrany ? (wybrany.stan === 'hangar' ? `START klucza ${wybrany.nr}` : `WYKONAJ: ${st.rozkazMysliwcow} → klucz ${wybrany.nr}`) : 'START',
      klik: wymagaj((k) => doKlucza(akcje, k, k.stan === 'hangar' ? 'start' : st.rozkazMysliwcow, 'Myśliwce')) },
  };
  for (let n = 1; n <= 18; n++) {
    const k = klucze.find((x) => x.nr === n);
    if (!k) continue;
    w[`Klucz ${n}`] = { ...lampa(k, `Klucz ${n} · ${opisLampy(k)}`), wl: st.wybranyKlucz === k.id,
      klik: () => { if (st.wybranyKlucz === k.id) st.zoomKlucz = k.id; else st.wybranyKlucz = k.id; akcje.odswiez(); } };
  }
  return pulpit(j.profil, 'Myśliwce', w);
}

// ------------------------------------------------------------------------------------------- Klucz (zakładka)
function klucz(s, st, akcje) {
  const j = wlasna(s);
  const klucze = wlasneKlucze(s, j);
  if (!klucze.length) return h('div.komunikat', 'Ta strona nie ma kluczy myśliwców.');
  const k = klucze.find((x) => x.id === st.klucz) || klucze[0];
  return kluczPulpit(s, st, akcje, k, () => { st.klucz = klucze[(klucze.indexOf(k) + 1) % klucze.length].id; akcje.odswiez(); });
}

// ------------------------------------------------------------------------------------------- Eskadra (3 klucze)
function eskadra(s, st, akcje) {
  const j = wlasna(s);
  const klucze = wlasneKlucze(s, j);
  const zoom = klucze.find((k) => k.id === st.zoomKlucz);
  if (zoom) return zblizenie(s, st, akcje, zoom, 'Eskadra');
  const ile = Math.max(1, Math.ceil(klucze.length / 3));
  if (st.eskadra > ile) st.eskadra = 1;
  const moje = klucze.filter((k) => Math.ceil(k.nr / 3) === st.eskadra);
  const lot = moje.filter((k) => k.stan === 'w_locie');
  const srodek = lot.length ? { x: lot.reduce((a, k) => a + k.x, 0) / lot.length, y: lot.reduce((a, k) => a + k.y, 0) / lot.length, kurs: 0, strona: j.strona } : j;
  const wszystkim = (kod) => () => {
    const cel = moje.filter((k) => k.stan === 'w_locie' || (kod !== 'powrot' && k.stan === 'powrot'));
    if (!cel.length) return akcje.komunikat('Żaden klucz eskadry nie jest w locie.');
    cel.forEach((k) => doKlucza(akcje, k, kod, 'Eskadra'));
  };
  const w = {
    'ESKADRA N · klucze 3/3 · dowódca eskadry · alarm: normalny / żółty / czerwony': {
      etykieta: `ESKADRA ${st.eskadra} z ${ile} · w locie ${lot.length}/${moje.length} (dotknij — następna eskadra)`,
      klik: () => { st.eskadra = (st.eskadra % ile) + 1; akcje.odswiez(); } },
    'EKRAN ESKADRY · środek eskadry zawsze w środku · okręgi 5 / 15 / 40 km · 3 klucze z kursami · okręt macierzysty · cele i zagrożenia': {
      ekran: () => powiekszany(st, akcje, 'eskadra', (z) => ekranPlot(s, st, { zasieg: 40 / z, srodek, pierscienie: [5, 15, 40].map((r) => zaokr(r / z)), prognozy: true,
        obiekty: [...s.klucze.filter((x) => x.stan === 'w_locie'), ...s.jednostki] })) },
    'patrol': { klik: wszystkim('patrol') }, 'przechwycenie': { klik: wszystkim('atak') }, 'ATAK ESKADRY': { klik: wszystkim('atak') },
    'eskorta': { etykieta: 'eskorta (ochrona okrętu)', klik: wszystkim('ochrona') }, 'powrót eskadry': { klik: wszystkim('powrot') },
  };
  ['Klucz 1 · start za mm:ss', 'Klucz 2 · start za mm:ss', 'Klucz 3 · start za mm:ss'].forEach((tekst, i) => {
    const k = moje[i];
    if (k) w[tekst] = { ...lampa(k, `Klucz ${k.nr} · ${opisLampy(k)}`), klik: () => { st.zoomKlucz = k.id; akcje.odswiez(); } };
  });
  return pulpit(j.profil, 'Eskadra', w);
}

// ------------------------------------------------------------------------------------------- Nawigacja
function nawigacja(s, st, akcje) {
  const j = wlasna(s);
  const obiekty = [...s.klucze.filter((x) => x.stan === 'w_locie'), ...s.jednostki];
  const cel = obiekty.find((o) => ('nr' in o ? 'k' : 'j') + o.id === st.celNaw);
  const d = cel ? odl(cel, j) : null;
  const skala = (km) => ({ wl: st.skalaNaw === km && (st.zoom.nav || 1) === 1, klik: () => { st.skalaNaw = km; st.zoom.nav = 1; akcje.odswiez(); } });
  return pulpit(j.profil, 'Nawigacja', {
    'EKRAN NAWIGACJI · sylwetka ISD zawsze w środku, w skali · okręgi odległości 250 / 500 / 1000 km · wektor ruchu osobno od zwrotu dziobu · inne jednostki (własne, obce, nieznane) · kurs zadany i punkt docelowy': {
      ekran: () => powiekszany(st, akcje, 'nav', (z) => ekranPlot(s, st, { zasieg: st.skalaNaw / z, srodek: j, pierscienie: [st.skalaNaw / 4, st.skalaNaw / 2, st.skalaNaw].map((r) => zaokr(r / z)),
        prognozy: true, tory: st.tory, hz: st.syncSensory, obiekty,
        wybierz: (o) => { st.celNaw = ('nr' in o ? 'k' : 'j') + o.id; akcje.odswiez(); } })) },
    'skala ekranu: 250 km': skala(250), 'skala ekranu: 500 km': skala(500), 'skala ekranu: 1000 km': skala(1000),
    'synchronizacja z sensorami (kontakty)': { wl: st.syncSensory, klik: () => { st.syncSensory = !st.syncSensory; akcje.odswiez(); } },
    'synchronizacja z radarem (tory jednostek)': { wl: st.tory, klik: () => { st.tory = !st.tory; akcje.odswiez(); } },
    'odczyt: wektor ruchu (kurs, prędkość)': { etykieta: `wektor: ${Math.round(j.kurs)}° · ${j.v} km/s` },
    'odczyt: zwrot dziobu': { etykieta: `zwrot dziobu: ${Math.round(j.kurs)}°` },
    'odczyt: prędkość kątowa': { etykieta: 'prędkość kątowa: 0°/s' },
    'wybierz cel / punkt nawigacyjny': { etykieta: cel ? `cel: ${'nr' in cel ? `${cel.strona} ${cel.nr}` : cel.nazwa} (dotknij — wyczyść)` : 'wybierz cel (dotknij na ekranie)',
      klik: () => { st.celNaw = null; akcje.odswiez(); } },
    'odczyt: odległość do celu': { etykieta: d != null ? `do celu: ${km(d)} km` : 'do celu: —' },
    'odczyt: czas do celu (ETA)': { etykieta: d != null && j.v > 0 ? `ETA ${mmss(d / j.v)}` : 'ETA: —' },
  });
}

// ------------------------------------------------------------------------------------------- Kontrola ognia
function kontrolaOgnia(s, st, akcje) {
  const j = wlasna(s);
  const obce = [...s.klucze.filter((x) => x.stan === 'w_locie' && x.strona !== j.strona), ...s.jednostki.filter((x) => x.strona !== j.strona)];
  return pulpit(j.profil, 'Kontrola ognia', {
    'wskazanie celu: siatka taktyczna': { ekran: () => powiekszany(st, akcje, 'ogien', (z) => ekranPlot(s, st, { zasieg: 50 / z, srodek: j,
      pierscienie: [10, 25, 50].map((r) => zaokr(r / z)), prognozy: true, wybierz: (o) => { st.oznaczony = ('nr' in o ? 'k' : 'j') + o.id; akcje.odswiez(); },
      obiekty: [...obce, j] })) },
  });
}

// Konsola o nazwie pulpitu: pulpity z KONSOLE mają mechanikę, pozostałe (Turbolasery, Silniki, Grawitacja) — sam układ.
export function konsola(nazwa, s, st, akcje) {
  if (!s.jednostki.length) return h('div.komunikat', 'Brak okrętu w tej symulacji.');
  const f = { Radar: radar, Sensory: sensory, 'Osłony': oslony, Reaktory: reaktory, 'Myśliwce': mysliwce, Nawigacja: nawigacja,
    'Kontrola ognia': kontrolaOgnia, Klucz: klucz, Eskadra: eskadra }[nazwa];
  if (f) return f(s, st, akcje);
  return h('div', h('div.slaby.maly-tekst', { style: 'margin-bottom:6px' }, 'Ten pulpit nie ma jeszcze mechaniki — widać układ do nauki.'),
    pulpit(wlasna(s).profil, nazwa, {}));
}

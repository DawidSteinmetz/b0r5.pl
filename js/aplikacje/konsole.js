// Konsole mostka w symulacji: pulpit rysowany z tych samych układów co arkusz i wydruk (pulpity_uklady.js, siatka 13×9),
// przyciski i ekrany podpięte pod stan bitwy (api/?a=bitwa_stan). Element bez mechaniki jest przygaszony.
// Warstwy (PULPITY.md 6): 1 — kontrolki i ekrany (bez napisów), 2 — teksty w kroju datapada (var(--czcionka)), więc
// przełącznik alfabetu (łaciński / Aurebesh) zmienia wszystkie napisy pulpitu naraz.
// Wygląd jak wzór assets/wnetrza/isd-konsola-*.svg: metalowa płyta z nitami, ciemne przyciski z ramką i lampką w kolorze
// typu (PULPITY.md: czerwony wzmocnij, żółty suwak, zielony synchronizacja, niebieski urządzenie, biały polecenie),
// ekrany w ramkach. Siatki żył (60×60) — lampki z minimalnymi przerwami (prototyp konsoli osłon).

import { h } from '../rdzen/narzedzia.js';
import { UKLADY, KOLORY, FORMATY, UZBROJENIE } from './pulpity_uklady.js';

// Pulpity z mechaniką (pozostałe rysują się z samym układem). Kolejność = kolejność zakładek.
export const KONSOLE = ['Radar', 'Sensory', 'Osłony', 'Reaktory', 'Turbolasery', 'Silniki', 'Nawigacja', 'Kontrola ognia', 'Grawitacja',
  'Myśliwce', 'Klucz', 'Eskadra'];
export const pulpityKlasy = (klasa) => Object.keys(UKLADY[klasa] || {});
const NS = 'http://www.w3.org/2000/svg';
const ZASIEG_SENSOROW_KM = 200; // jak automatyczne osłony w silniku (STALE zasieg_sensorow_km)
// Radar: do 12 km obraz na bieżąco, 12–60 km echo odświeżane przejściem wiązki. Sensory: od 60 km wzwyż, też wiązką.
const RADAR_BLISKI_KM = 12, RADAR_KM = 60, TEMPO_SKANU_S = 6;
// TIE bez sensorów dalekiego zasięgu (dane/maszyny.py BEZ_SENSOROW): własny radar 5 km, dalej dane z radaru okrętu matki.
const BEZ_SENSOROW = ['TIE_LN', 'TIE_IN', 'TIE_SA'], RADAR_WLASNY_KM = 5;
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

// Sylwetki z góry: małe rozmiary — wektorowy obrys (piksele obrazów wzorcowych, sprowadzone do długości 1, dziób
// w górę); od 40 px — pełny rysunek z assets/sylwetki (niżej).
const SYLWETKI = {
  ISD_I: {
    transform: 'rotate(-90) scale(0.000971) translate(-535 -298)',
    obrys: 'M1050 298 L75 585 L62 560 L60 470 L22 470 L22 355 L60 355 L60 315 L20 315 L20 281 L60 281 L60 245 L22 245 L22 130 L60 130 L60 38 L75 12 Z',
  },
  MC80: {
    transform: 'rotate(90) scale(0.00297) translate(-172 -63)',
    obrys: 'M5 68 C20 55 50 42 90 34 C140 24 190 20 230 18 L262 10 C285 3 305 0 322 2 L330 8 C318 16 300 26 292 36 L338 44 C342 60 342 72 338 84 '
      + 'L292 92 C300 100 318 110 330 118 L322 124 C305 125 285 122 262 115 L230 107 C190 106 140 102 90 92 C50 84 20 78 5 72 Z',
  },
};
const LINIA = { fill: 'none', stroke: '#0b1013', 'vector-effect': 'non-scaling-stroke' };

// Pełne rysunki z góry (assets/sylwetki, z obrazów od użytkownika: dziób w górę, przezroczyste tło, odcienie szarości):
// okręt zawsze rysuje się obrazem zabarwionym kolorem strony (białe pola → kolor, czarne linie zostają): do 200 px
// długości — wersja 200 px (_maly), większy — pełna rozdzielczość. proporcja = szerokość / długość obrazu.
const RYSUNKI = {
  ISD_I: { href: new URL('../../assets/sylwetki/isd_i.png', import.meta.url).href, maly: new URL('../../assets/sylwetki/isd_i_maly.png', import.meta.url).href, proporcja: 584 / 1047 },
  MC80: { href: new URL('../../assets/sylwetki/mc80.png', import.meta.url).href, maly: new URL('../../assets/sylwetki/mc80_maly.png', import.meta.url).href, proporcja: 383 / 1008 },
};
let licznikFiltrow = 0;
function zabarwienie(kolor) {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(kolor.slice(i, i + 2), 16) / 255);
  const id = 'barwa' + (++licznikFiltrow);
  const f = svg('filter', { id, 'color-interpolation-filters': 'sRGB' });
  const w = (c) => `${(c * 0.3).toFixed(3)} ${(c * 0.59).toFixed(3)} ${(c * 0.11).toFixed(3)} 0 0`;
  f.append(svg('feColorMatrix', { type: 'matrix', values: `${w(r)} ${w(g)} ${w(b)} 0 0 0 1 0` }));
  return [id, f];
}

export function sylwetka(profil, kolor, rozmiar) {
  const s = SYLWETKI[profil], rys = RYSUNKI[profil];
  const g = svg('g', { transform: `scale(${rozmiar})` });
  if (!s) { g.append(svg('path', { d: 'M0 -0.5 L0.3 0.5 L-0.3 0.5Z', fill: kolor })); return g; }
  if (rys) {
    const [id, filtr] = zabarwienie(kolor);
    const defs = svg('defs');
    defs.append(filtr);
    g.append(defs, svg('image', { href: rozmiar > 200 ? rys.href : rys.maly, x: -rys.proporcja / 2, y: -0.5, width: rys.proporcja, height: 1,
      preserveAspectRatio: 'none', filter: `url(#${id})`, style: 'image-rendering:auto' }));
  } else {
    const r = svg('g', { transform: s.transform });
    r.append(svg('path', { d: s.obrys, ...LINIA, fill: kolor, 'fill-opacity': 0.92, 'stroke-width': 0.8 }));
    g.append(r);
  }
  if (rozmiar >= 60) g.append(rozstawienie(profil, rozmiar));
  return g;
}

// Rozstawienie uzbrojenia i urządzeń na kadłubie (dane/uzbrojenie.py, rysunek ISD): turbolasery i obrona punktowa —
// czerwone, jony — niebieskie, generatory osłon — żółte, radar i sensory — brązowe, silniki — białe.
const KOLOR_PUNKTU = { turbolaser: '#ff2020', pd: '#ff2020', jon: '#2848ff', oslona: '#ffe000', sensory: '#8b4513', silnik: '#ffffff' };
const PROMIEN_PUNKTU = { turbolaser: 0.018, pd: 0.005, jon: 0.012, oslona: 0.02, sensory: 0.015, silnik: 0.015 };
function rozstawienie(profil, rozmiar) {
  const g = svg('g');
  const punkty = UZBROJENIE[profil] || [];
  // obrona punktowa: linia wzdłuż burt (kropki działek dopiero przy dużym przybliżeniu)
  for (const strona of [-1, 1]) {
    const pd = punkty.filter((u) => u[1] === 'pd' && Math.sign(u[2]) === strona);
    if (pd.length > 1) g.append(svg('polyline', { points: pd.map((u) => `${u[2]},${u[3]}`).join(' '), fill: 'none', stroke: KOLOR_PUNKTU.pd, 'stroke-width': 0.006 }));
  }
  for (const [, rodzaj, x, y] of punkty) {
    if (rodzaj === 'pd' && rozmiar < 250) continue;
    g.append(svg('circle', { cx: x, cy: y, r: PROMIEN_PUNKTU[rodzaj], fill: KOLOR_PUNKTU[rodzaj], stroke: '#000', 'stroke-width': 0.003 }));
  }
  return g;
}

// Stan konsoli trzymany między odświeżeniami (filtry, skala, ślady, zaznaczenie).
export function nowyStanKonsol() {
  return {
    skala: 30, filtry: { wlasne: true, obce: true, klucze: true, okrety: true, pociski: true, nieznane: true },
    sektor: null, tory: true, prognozy: false, oznaczony: null, sledzony: null, zamrozony: false, slady: new Map(),
    progZblizenia: 30, progKolizji: 10, tempo: 6, syncSensory: false, syncNawigacja: false,
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

// Zegar ekranów: stoi w pauzie i po końcu symulacji (wiązki i ruch zamierają) i rusza od miejsca zatrzymania.
// ruchStoi — także w przygotowaniu (silnik jeszcze nie liczy), więc kontakty nie odpływają z przewidywania.
function ustawZegar(st, s) {
  const teraz = Date.now();
  const stoi = !!s.pauza || ['zakonczona', 'anulowana'].includes(s.status);
  if (stoi && st.stoiOd == null) st.stoiOd = teraz;
  if (!stoi && st.stoiOd != null) { st.przesuniecie = (st.przesuniecie || 0) + teraz - st.stoiOd; st.stoiOd = null; }
  st.wiazkaStoi = stoi;
  st.ruchStoi = stoi || s.status === 'przygotowanie';
}
const zegar = (st) => ((st.stoiOd ?? Date.now()) - (st.przesuniecie || 0)) / 1000;

// Echo przy przejściu wiązki: kontakt w strefie (wStrefie) jest widoczny w pozycji z chwili, gdy wiązka (obrót co tempo
// sekund) ostatnio przeszła nad jego namiarem; poza strefą znika przy kolejnym przejściu. Pamięć osobna dla każdej wiązki.
const idObiektu = (o) => ('nr' in o ? 'k' : 'j') + o.id;
const namiarOd = (srodek, o) => ((Math.atan2(o.x - srodek.x, o.y - srodek.y) * 180) / Math.PI + 360) % 360;
function echo(st, kanal, srodek, obiekty, tempo, wStrefie) {
  const teraz = zegar(st);
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
const BLACHA = '#262d33', SZCZELINA = '#14181b', KRAWEDZ = '#56626b', EKRAN_RAMA = '#11181b', EKRAN_TLO = '#06121a', TURKUS = '#4de2c9';
// Kolor napisu na ciemnym przycisku (jaśniejszy odcień koloru typu, żeby był czytelny).
const TEKST_TYPU = { S: '#c9d1d6', R: '#ff8a80', Y: '#ffff99', G: '#99ff99', B: '#aab0ff', W: '#e8eef0' };
// Każdy element pulpitu coś robi. Bez mechaniki w symulacji — stan lokalny konsoli (przełącznik świeci w kolorze typu,
// przycisk sprężynowy wraca, suwak ma pozycję), zapamiętany na czas sesji przeglądarki; dymek mówi, że to bez wpływu na bitwę.
const LOKALNE = new Map();
let biezaceOdswiez = null;
const WCISNIETE = new Map();
const PRZELACZNIK_W = /^(tryb|filtr|pokaż|zamroź|blokada|zakłócenia|zakłócanie|alarm|skanuj|załóż|przełącz|wybierz|sektor|skala|śledź|oznacz|kasuj)/i;
let wysokoscPulpitu = 0;
function styleAnimacji() {
  if (document.getElementById('pulpit-animacje')) return;
  document.head.append(h('style', { id: 'pulpit-animacje' }, `
.pp { transition: transform .09s ease-out, filter .15s; }
.pp.wl { transform: translateY(1px) scale(.985); }
.pp.wcisk { transform: translateY(2px) scale(.94); filter: brightness(1.45); transition: transform .04s, filter .04s; }
@keyframes pp-sprezyna { 0% { transform: translateY(2px) scale(.94); filter: brightness(1.6); } 100% { transform: none; filter: none; } }
@keyframes pp-zatrzask { 0% { transform: translateY(2px) scale(.94); filter: brightness(1.8); } 100% { transform: translateY(1px) scale(.985); filter: none; } }
.pp.sprezyna { animation: pp-sprezyna .32s ease-out both; }
.pp.zatrzask { animation: pp-zatrzask .32s ease-out both; }`));
}

function pulpit(klasa, nazwa, wiazania) {
  const uklad = UKLADY[klasa]?.[nazwa];
  if (!uklad) return h('div.komunikat.blad', `Brak układu pulpitu ${nazwa} dla klasy ${klasa}.`);
  styleAnimacji();
  const [K, W] = FORMATY[klasa][nazwa];
  const geometria = `display:grid;grid-template-columns:repeat(${K},1fr);grid-template-rows:repeat(${W},1fr);gap:4px;padding:12px;box-sizing:border-box;`;
  const telefon = K < 13;
  // Pulpit wypełnia ekran: cała szerokość i wysokość od swojej górnej krawędzi do dołu okna (komórki nie muszą być kwadratowe).
  const ramka = h('div', { style: `position:relative;width:100%;height:${wysokoscPulpitu ? wysokoscPulpitu + 'px' : 'calc(100dvh - 150px)'};min-height:${telefon ? 520 : 380}px;`
    + `background:linear-gradient(180deg,rgba(207,239,255,.13) 0,rgba(207,239,255,0) 30%),${BLACHA};border:3px solid ${KRAWEDZ};border-radius:10px;`
    + 'box-shadow:inset 0 0 50px rgba(0,0,0,.55),0 6px 20px rgba(0,0,0,.5);box-sizing:border-box;container-type:inline-size' });
  requestAnimationFrame(() => {
    if (!ramka.isConnected) return;
    const g = ramka.getBoundingClientRect().top + (window.scrollY || 0) - (document.scrollingElement?.scrollTop || 0);
    const wys = Math.round(window.innerHeight - Math.max(0, g) - 12);
    if (wys > 200 && Math.abs(wys - wysokoscPulpitu) > 2) { wysokoscPulpitu = wys; ramka.style.height = wys + 'px'; }
  });
  // nity w narożnikach płyty
  for (const [x, y] of [[0, 0], [1, 0], [0, 1], [1, 1]]) {
    ramka.append(h('div', { style: `position:absolute;${x ? 'right' : 'left'}:3px;${y ? 'bottom' : 'top'}:3px;width:6px;height:6px;border-radius:50%;`
      + `background:${KRAWEDZ};box-shadow:inset -1px -1px 1px rgba(0,0,0,.6)` }));
  }
  const kontrolki = h('div', { style: geometria + 'position:absolute;inset:0' });
  const teksty = h('div', { style: geometria + `position:absolute;inset:0;pointer-events:none;${CZCIONKA}`, 'aria-hidden': 'true' });
  const rozmiar = telefon ? 'max(9px,2.6cqw)' : 'max(7px,0.8cqw)';
  for (const [typ, c, r, tekst, sz, wys, tekstMin] of uklad) {
    const klucz = `${klasa}|${nazwa}|${tekst}`;
    const wiaz = wiazania[tekst];
    const interakcja = !!(wiaz?.klik || wiaz?.klikS || wiaz?.klikX || wiaz?.ustawZakres || wiaz?.ustawPoz);
    // Stan lokalny dla elementów bez mechaniki (i bez własnego kliknięcia): przełącznik, sprężyna, suwak, zakres.
    let lokalny = false;
    let w = wiaz;
    if (!interakcja && !wiaz?.ekran) {
      lokalny = true;
      const l = LOKALNE.get(klucz) ?? (typ === 'S' ? { max: 1, min: 0 } : typ === 'Y' ? { poz: 0.5 } : { wl: typ === 'B' });
      LOKALNE.set(klucz, l);
      const przelacznik = (typ === 'R' || typ === 'G' || typ === 'B' || (typ === 'W' && PRZELACZNIK_W.test(tekst))) && wiaz?.wl === undefined;
      w = { ...(wiaz || {}) };
      if (typ === 'S') {
        w.zakres = l;
        w.ustawZakres = (v, ktory) => { l[ktory] = ktory === 'max' ? Math.max(v, l.min) : Math.min(v, l.max); };
        w.etykieta ??= `${tekst.replace('suwak: ', '')} · ${Math.round(l.max * 100)}%`;
        w.etykietaMin ??= `${(tekstMin || '').replace('suwak: ', '')} · ${Math.round(l.min * 100)}%`;
      } else if (typ === 'Y') {
        w.poz = wiaz?.poz ?? l.poz;
        w.ustawPoz = (v) => { l.poz = v; };
      } else if (przelacznik) {
        w.wl = l.wl;
        w.klik = () => { l.wl = !l.wl; };
        w.przelacznik = true;
      } else w.klik = () => {};
    }
    const kol = KOLORY[typ];
    const pole = `grid-column:${c}/span ${sz};grid-row:${r}/span ${typ === 'S' ? W : wys};`;
    const lampaKlucza = w?.kolor;
    const swiatlo = w?.wl ? `,0 0 12px ${kol},0 0 22px ${kol}66,inset 0 0 18px ${kol}88` : '';
    const klikalny = !!(w?.klik || w?.klikS || w?.klikX || w?.ustawZakres || w?.ustawPoz);
    const el = h('div', {
      class: 'pp' + (w?.wl && !w?.ekran ? ' wl' : ''),
      style: pole + `background:${w?.ekran ? EKRAN_RAMA : lampaKlucza || (w?.wl ? kol + '33' : SZCZELINA)};border:1px solid ${KRAWEDZ};border-radius:5px;overflow:hidden;position:relative;`
        + `box-shadow:inset 0 0 0 3px ${w?.ekran ? EKRAN_RAMA : lampaKlucza || SZCZELINA},inset 0 0 0 4.5px ${w?.ekran ? KRAWEDZ : kol + (w?.wl ? 'ff' : '88')}${swiatlo};`
        + (klikalny ? 'cursor:pointer;touch-action:manipulation;' : ''),
      title: lokalny ? `${tekst} — stan konsoli (bez wpływu na symulację)` : tekst, role: klikalny ? 'button' : null, 'aria-label': tekst,
      'aria-pressed': w?.przelacznik ? String(!!w.wl) : null,
    });
    // lampka typu w lewym górnym rogu (świeci mocniej, gdy element jest włączony)
    if (!w?.ekran && typ !== 'S') {
      el.append(h('div', { style: `position:absolute;left:7px;top:7px;width:14%;max-width:16px;height:3px;background:${kol};`
        + `opacity:${w?.wl ? 1 : 0.7};${w?.wl ? `box-shadow:0 0 8px ${kol}` : ''}` }));
    }
    // Suwak zakresu: prowadnica z podziałką i dwoma uchwytami (górny — maksimum, dolny — minimum), skala od dołu do góry.
    if (typ === 'S') {
      el.append(h('div', { style: `position:absolute;left:50%;top:9%;bottom:9%;width:8px;transform:translateX(-50%);background:#0c181b;border:1px solid ${KRAWEDZ};border-radius:4px` }),
        h('div', { style: 'position:absolute;left:22%;right:22%;top:9%;bottom:9%;background:repeating-linear-gradient(180deg,'
          + `${KRAWEDZ} 0 2px,transparent 2px 4%);-webkit-mask:linear-gradient(90deg,#000 0 30%,transparent 30% 70%,#000 70%);mask:linear-gradient(90deg,#000 0 30%,transparent 30% 70%,#000 70%)` }));
      if (w?.zakres) {
        const z = w.zakres;
        el.append(h('div', { style: `position:absolute;left:50%;width:4px;transform:translateX(-50%);background:${TURKUS}88;`
          + `top:${(9 + 82 * (1 - z.max)).toFixed(1)}%;bottom:${(9 + 82 * z.min).toFixed(1)}%` }));
        for (const [v, k2] of [[z.max, '#e8eef0'], [z.min, '#9aa4ab']]) {
          el.append(h('div', { style: `position:absolute;left:18%;right:18%;height:10px;top:${(9 + 82 * (1 - v)).toFixed(1)}%;transform:translateY(-50%);`
            + `background:linear-gradient(180deg,${k2},#3a444b);border:1px solid #0b1013;border-radius:3px;box-shadow:0 2px 4px rgba(0,0,0,.6)` }));
        }
      }
    }
    // Suwak żółty: rowek z podziałką i uchwytem (pozycja w.poz 0…1; pionowy, gdy element jest wyższy niż szerszy).
    if (typ === 'Y' && w?.poz != null) {
      const pion = (wys || 1) > (sz || 1);
      el.append(h('div', { style: `position:absolute;${pion ? 'left:50%;top:22%;bottom:12%;width:6px;transform:translateX(-50%)' : 'top:62%;left:12%;right:12%;height:6px'};`
        + `background:#0c181b;border:1px solid ${kol}99;border-radius:3px` },
        h('div', { style: `position:absolute;${pion ? `left:-4px;right:-4px;bottom:${Math.round(w.poz * 100)}%;height:5px` : `top:-4px;bottom:-4px;left:${Math.round(w.poz * 100)}%;width:5px`};`
          + `transform:translate(${pion ? '0,50%' : '-50%,0'});background:${kol};border-radius:2px;box-shadow:0 0 5px ${kol}` })));
    }
    const odswiezPo = () => { if (lokalny) biezaceOdswiez?.(); };
    const zapiszWcisniecie = () => WCISNIETE.set(klucz, Date.now());
    if (w?.klik) el.addEventListener('click', (ev) => { zapiszWcisniecie(); w.klik(ev); odswiezPo(); });
    if (w?.klikS) el.addEventListener('click', (ev) => { zapiszWcisniecie(); const b = el.getBoundingClientRect(); w.klikS(ev.clientY - b.top < b.height / 2); });
    if (w?.klikX) el.addEventListener('click', (ev) => { zapiszWcisniecie(); const b = el.getBoundingClientRect(); w.klikX(Math.min(1, Math.max(0, (ev.clientX - b.left) / b.width)), (ev.clientY - b.top) / b.height); });
    if (w?.ustawZakres) el.addEventListener('click', (ev) => {
      zapiszWcisniecie();
      const b = el.getBoundingClientRect(), v = Math.min(1, Math.max(0, 1 - ((ev.clientY - b.top) / b.height - 0.09) / 0.82));
      const z = w.zakres || { max: 1, min: 0 };
      w.ustawZakres(v, Math.abs(v - z.max) <= Math.abs(v - z.min) ? 'max' : 'min');
      odswiezPo();
    });
    if (w?.ustawPoz && !w?.klik && !w?.klikX) el.addEventListener('click', (ev) => {
      zapiszWcisniecie();
      const b = el.getBoundingClientRect(), pion = (wys || 1) > (sz || 1);
      const v = pion ? 1 - ((ev.clientY - b.top) / b.height - 0.22) / 0.66 : ((ev.clientX - b.left) / b.width - 0.12) / 0.76;
      w.ustawPoz(Math.min(1, Math.max(0, v)));
      odswiezPo();
    });
    if (w?.ekran) el.append(h('div', { style: `position:absolute;inset:5px;background:${EKRAN_TLO};border:1.5px solid ${TURKUS}cc;border-radius:3px;overflow:hidden` }, w.ekran()));
    kontrolki.append(el);
    if (w?.ekran) continue;
    const napis = h('div', { class: 'pp' + (w?.wl ? ' wl' : ''), style: pole + `display:flex;flex-direction:column;align-items:center;justify-content:${typ === 'S' ? 'space-between' : 'center'};`
      + `text-align:center;overflow:hidden;padding:${typ === 'S' ? '6px 3px' : '10px 4px 4px'};box-sizing:border-box;font-size:${rozmiar};line-height:1.15;letter-spacing:.03em;`
      + `color:${w?.kolorTekstu || (lampaKlucza ? '#000' : TEKST_TYPU[typ])};${w?.wl ? 'font-weight:bold;' : ''}` });
    if (typ === 'S') napis.append(h('span', w?.etykieta ?? tekst.replace('suwak: ', '')), h('span', w?.etykietaMin ?? (tekstMin || '').replace('suwak: ', '')));
    else napis.append(h('span', w?.etykieta ?? tekst.replace('suwak: ', '')));
    teksty.append(napis);
    // Animacja wciśnięcia: przycisk i napis zapadają się pod palcem; po puszczeniu sprężynowy wraca, przełącznik zostaje
    // lekko wciśnięty i świeci. Po przerysowaniu (odpowiedź serwera) animacja dokańcza się od miejsca, w którym była.
    if (klikalny && typ !== 'S') {
      const wcisk = (on) => { el.classList.toggle('wcisk', on); napis.classList.toggle('wcisk', on); };
      el.addEventListener('pointerdown', () => wcisk(true));
      for (const zd of ['pointerup', 'pointerleave', 'pointercancel']) el.addEventListener(zd, () => wcisk(false));
      const od = Date.now() - (WCISNIETE.get(klucz) || 0);
      if (od < 320) for (const x of [el, napis]) { x.classList.add(w?.wl ? 'zatrzask' : 'sprezyna'); x.style.animationDelay = `-${od}ms`; }
    }
  }
  ramka.append(kontrolki, teksty);
  return ramka;
}

// Siatka lampek (żyły generatora, baterii, porcje reaktora): K kolumn, kolor lampki z funkcji kolor(i) → [r, g, b];
// każda lampka to mały kwadrat z minimalną przerwą (płótno w wyższej rozdzielczości, ostre piksele).
function siatkaLampek(n, K, kolor) {
  const W = Math.ceil(n / K), L = 6;
  const c = h('canvas', { width: K * L, height: W * L, style: 'width:100%;flex:1;min-height:0;object-fit:contain;display:block;image-rendering:pixelated' });
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#05080c';
  ctx.fillRect(0, 0, K * L, W * L);
  for (let i = 0; i < n; i++) {
    const [r, g, b] = kolor(i);
    ctx.fillStyle = `rgb(${r},${g},${b})`;
    ctx.fillRect((i % K) * L, Math.floor(i / K) * L, L - 1, L - 1);
  }
  return c;
}
const hsl = (hh, s, l) => {
  s /= 100; l /= 100;
  const k = (n) => (n + hh / 30) % 12, a = s * Math.min(l, 1 - l);
  const f = (n) => l - a * Math.max(-1, Math.min(k(n) - 3, 9 - k(n), 1));
  return [Math.round(255 * f(0)), Math.round(255 * f(8)), Math.round(255 * f(4))];
};

// ------------------------------------------------------------------------------------------- ekran radarowy
// Ikony typów maszyn z góry (dziób w górę, ok. ±7 jednostek): TIE (kula i dwa skrzydła), X-wing (skrzydła w X),
// Y-wing (kadłub i dwie gondole), A-wing (klin), B-wing (krzyż), bombowiec TIE (dwa kadłuby), prom Lambda.
const IKONY_MASZYN = {
  TIE_LN: [['path', { d: 'M-6 -6 L-6 6 M6 -6 L6 6 M-6 0 L6 0', 'stroke-width': 2.2 }], ['circle', { cx: 0, cy: 0, r: 2.6 }]],
  TIE_IN: [['path', { d: 'M-4 -7 L-7 0 L-4 7 M4 -7 L7 0 L4 7 M-6 0 L6 0', 'stroke-width': 2 }], ['circle', { cx: 0, cy: 0, r: 2.4 }]],
  TIE_SA: [['path', { d: 'M-7 -6 L-7 6 M7 -6 L7 6 M-7 0 L7 0', 'stroke-width': 2 }], ['ellipse', { cx: -1.8, cy: 0, rx: 2, ry: 3.6 }], ['ellipse', { cx: 2, cy: 0.5, rx: 1.6, ry: 4.2 }]],
  X_WING: [['path', { d: 'M0 -8 L0 5 M-7 -1 L7 6 M7 -1 L-7 6', 'stroke-width': 2 }]],
  Y_WING: [['path', { d: 'M0 -8 L0 2 M-5 1 L5 1 M-5 -3 L-5 7 M5 -3 L5 7', 'stroke-width': 2 }]],
  A_WING: [['path', { d: 'M0 -7 L6 6 L0 3 L-6 6 Z', 'stroke-width': 1.2 }]],
  B_WING: [['path', { d: 'M0 -8 L0 7 M-7 2 L7 2', 'stroke-width': 2.2 }], ['circle', { cx: 0, cy: -6, r: 2 }]],
  LAMBDA: [['path', { d: 'M0 -7 L0 5 M-6 3 L0 -2 L6 3', 'stroke-width': 2 }]],
};
function ikonaMaszyny(typ, kolor, skala = 1) {
  const g = svg('g', { transform: `scale(${skala})`, fill: kolor, stroke: kolor, 'stroke-linecap': 'round' });
  for (const [tag, at] of IKONY_MASZYN[typ] || [['path', { d: 'M0 -6 L5 5 L-5 5Z' }]]) {
    g.append(svg(tag, { ...at, fill: tag === 'path' && !at.d.endsWith('Z') ? 'none' : kolor }));
  }
  return g;
}

// Prędkość kątowa obiektów (°/s) z dwóch kolejnych odczytów — do przewidywania ruchu po łuku między odczytami.
export function zapiszZwroty(st, s) {
  const pam = (st.zwroty ||= new Map());
  for (const o of [...s.jednostki, ...s.klucze]) {
    const id = idObiektu(o), p = pam.get(id);
    if (p && s.czas_s > p.t + 0.2) {
      const w = (((o.kurs - p.kurs + 540) % 360) - 180) / (s.czas_s - p.t);
      pam.set(id, { t: s.czas_s, kurs: o.kurs, w: Math.max(-45, Math.min(45, w)) });
    } else if (!p) pam.set(id, { t: s.czas_s, kurs: o.kurs, w: 0 });
  }
}
// Pozycje przewidziane co krok s przez czas T: ruch z prędkością v po łuku (stała prędkość kątowa w, obrót ≤ 180°).
function przewidz(o, w, T, krok = 0.5) {
  const pkt = [[o.x, o.y]];
  let x = o.x, y = o.y, k = o.kurs || 0, obrot = 0;
  for (let t = krok; t <= T + 1e-9; t += krok) {
    if (Math.abs(obrot) < 180) { k += w * krok; obrot += w * krok; }
    x += o.v * krok * Math.sin(rad(k));
    y += o.v * krok * Math.cos(rad(k));
    pkt.push([x, y]);
  }
  return { pkt, kurs: k };
}

function ekranPlot(s, st, { zasieg, srodek, pierscienie, hz = false, wybierz = null, obiekty = null, sektor = null, tory = false, prognozy = false,
  tempo = st.tempo, maska = 0, strefy = [], zmarszczki = [], wiazkaWl = true }) {
  const P = 200;
  const sk = (P - 6) / zasieg;
  // Środek w ruchu (klucz na telefonie, eskadra, okręt): ekran startuje z pozycji przewidzianej na teraz, a cały „świat”
  // przesuwa się przeciwnie do toru środka (animateMotion) — środek stoi, otoczenie płynie, bez skoku co odczyt.
  const dtS = !st.ruchStoi && srodek.v && s._odebrano ? Math.min(10, (Date.now() - s._odebrano) / 1000) : 0;
  const wS = srodek.v && 'id' in srodek ? (st.zwroty?.get(idObiektu(srodek))?.w || 0) : 0;
  let ruchSwiata = null;
  if (!st.ruchStoi && srodek.v) {
    const t0 = dtS > 0 ? przewidz(srodek, wS, dtS, Math.max(0.05, dtS / 20)) : { pkt: [[srodek.x, srodek.y]], kurs: srodek.kurs || 0 };
    const [cx, cy] = t0.pkt[t0.pkt.length - 1];
    const przodS = przewidz({ ...srodek, x: cx, y: cy, kurs: t0.kurs }, wS, 10);
    ruchSwiata = 'M0 0 ' + przodS.pkt.slice(1).map(([a, b]) => `L${(-(a - cx) * sk).toFixed(2)} ${((b - cy) * sk).toFixed(2)}`).join(' ');
    srodek = { ...srodek, x: cx, y: cy, kurs: t0.kurs, _baza: srodek };
  }
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
  const katDeg = ((zegar(st) % tempo) / tempo) * 360;
  const R = zasieg * sk;
  const idRozmycia = 'rozmycie' + Math.random().toString(36).slice(2, 9);
  const filtr = svg('filter', { id: idRozmycia, x: '-20%', y: '-20%', width: '140%', height: '140%' });
  filtr.append(svg('feGaussianBlur', { stdDeviation: 2.2 }));
  const defs = svg('defs');
  defs.append(filtr);
  e.append(defs);
  const wiazka = svg('g', wiazkaWl ? {} : { display: 'none' });
  const smuga = svg('g', { filter: `url(#${idRozmycia})` });
  const PLASTRY = 20, KROK = 3; // 20 plastrów po 3° = 60° poświaty za wiązką
  for (let i = PLASTRY - 1; i >= 0; i--) {
    const a0 = rad(-(i + 1) * KROK), a1 = rad(-i * KROK);
    smuga.append(svg('path', { d: `M${P} ${P} L${P + R * Math.sin(a0)} ${P - R * Math.cos(a0)} A${R} ${R} 0 0 1 ${P + R * Math.sin(a1)} ${P - R * Math.cos(a1)}Z`,
      fill: '#4de2c9', 'fill-opacity': (0.26 * (1 - i / PLASTRY) ** 2).toFixed(3) }));
  }
  wiazka.append(smuga, svg('line', { x1: P, y1: P, x2: P, y2: P - R, stroke: 'rgba(160,255,240,.9)', 'stroke-width': 2, filter: `url(#${idRozmycia})` }),
    svg('line', { x1: P, y1: P, x2: P, y2: P - R, stroke: 'rgba(160,255,240,.9)', 'stroke-width': 1 }));
  if (st.wiazkaStoi) wiazka.setAttribute('transform', `rotate(${katDeg} ${P} ${P})`);
  else wiazka.append(svg('animateTransform', { attributeName: 'transform', type: 'rotate', from: `${katDeg} ${P} ${P}`, to: `${katDeg + 360} ${P} ${P}`,
    dur: `${tempo}s`, repeatCount: 'indefinite' }));
  e.append(wiazka);
  const strona = srodek.strona;
  const swiat = svg('g');
  if (ruchSwiata) swiat.append(svg('animateMotion', { path: ruchSwiata, dur: '10s', fill: 'freeze' }));
  e.append(swiat);
  for (const o of obiekty || []) {
    const swoj = o.strona === strona;
    const kolor = swoj ? WLASNY : OBCY;
    // Ruch między odczytami: kontakt leci dalej po łuku (prędkość kątowa z dwóch ostatnich odczytów) przez 10 s, aż kolejny
    // odczyt przerysuje go w prawdziwej pozycji. Przerysowanie bez nowego odczytu startuje z pozycji przewidzianej na teraz.
    const klucz = 'nr' in o;
    const id = (klucz ? 'k' : 'j') + o.id;
    const jestSrodkiem = o === srodek._baza || o === srodek;
    const ruchomy = o.v && !o.statyczny && !st.ruchStoi && (!jestSrodkiem || ruchSwiata);
    const w = ruchomy ? (st.zwroty?.get(id)?.w || 0) : 0;
    const dt = ruchomy && s._odebrano ? Math.min(10, (Date.now() - s._odebrano) / 1000) : 0;
    const teraz = ruchomy && dt > 0 ? przewidz(o, w, dt, Math.max(0.05, dt / 20)) : { pkt: [[o.x, o.y]], kurs: o.kurs || 0 };
    const [ox, oy] = teraz.pkt[teraz.pkt.length - 1];
    const x = px(ox), y = py(oy);
    if (x < -10 || y < -10 || x > 2 * P + 10 || y > 2 * P + 10) continue;
    if (tory && !o.statyczny) {
      // Zanikający, rozmyty ogon za kontaktem (tylko obraz na bieżąco — echo nie zdradza ruchu między przejściami wiązki).
      const sl = (st.slady.get(id) || []).slice(-16);
      const ogon = svg('g', { filter: `url(#${idRozmycia})` });
      for (let i = 1; i < sl.length; i++) {
        const f = i / (sl.length - 1);
        ogon.append(svg('line', { x1: px(sl[i - 1][0]), y1: py(sl[i - 1][1]), x2: px(sl[i][0]), y2: py(sl[i][1]), stroke: kolor,
          'stroke-opacity': (0.55 * f).toFixed(3), 'stroke-width': (1 + 2.5 * f).toFixed(2), 'stroke-linecap': 'round' }));
      }
      if (sl.length > 1) swiat.append(ogon);
    }
    const przod = ruchomy ? przewidz({ ...o, x: ox, y: oy, kurs: teraz.kurs }, w, 10) : null;
    if (prognozy && o.v) {
      const pr = przewidz({ ...o, x: ox, y: oy, kurs: teraz.kurs }, w, 30, 1).pkt;
      swiat.append(svg('polyline', { points: pr.map(([a, b]) => `${px(a).toFixed(1)},${py(b).toFixed(1)}`).join(' '), fill: 'none', stroke: kolor, 'stroke-dasharray': '3 3', 'stroke-width': 1 }));
    }
    const sciezka = przod ? 'M0 0 ' + przod.pkt.slice(1).map(([a, b]) => `L${((a - ox) * sk).toFixed(2)} ${(-(b - oy) * sk).toFixed(2)}`).join(' ') : null;
    // Ikona: animacja po łuku (animateMotion z obrotem wzdłuż toru), opisy obok — ta sama ścieżka bez obrotu.
    const miejsce = svg('g', { transform: `translate(${x.toFixed(2)} ${y.toFixed(2)})` });
    const ikona = svg('g', { style: wybierz ? 'cursor:pointer' : '' });
    const opisy = svg('g');
    if (sciezka) {
      ikona.append(svg('animateMotion', { path: sciezka, dur: '10s', fill: 'freeze', rotate: 'auto' }));
      opisy.append(svg('animateMotion', { path: sciezka, dur: '10s', fill: 'freeze' }));
    }
    // Poświata echa: pełna jasność, gdy wiązka przechodzi nad kontaktem, potem zanik do następnego obrotu.
    if (!jestSrodkiem && o.statyczny && !st.wiazkaStoi) {
      const namiar = ((Math.atan2(x - P, P - y) * 180) / Math.PI + 360) % 360;
      const odPrzejscia = (((katDeg - namiar) % 360) + 360) % 360 / 360 * tempo;
      miejsce.append(svg('animate', { attributeName: 'opacity', values: '1;0.3', keyTimes: '0;1', dur: `${tempo}s`, begin: `-${odPrzejscia.toFixed(2)}s`, repeatCount: 'indefinite' }));
    }
    // obrót: przy animacji po torze ikona ustawia się wzdłuż toru (oś +x), więc dziób (−y) obracamy o 90°
    const obrot = svg('g', { transform: `rotate(${sciezka ? 90 : teraz.kurs})` });
    if (klucz) {
      // Klucz: przy dużym powiększeniu ikona typu maszyny, przy bardzo dużym — 4 maszyny w szyku „palce”.
      if (sk >= 250) {
        for (const [dx, dy] of [[0, 0], [-0.05, 0.04], [0.05, 0.04], [0.1, 0.08]]) {
          const m = svg('g', { transform: `translate(${(dx * sk).toFixed(1)} ${(dy * sk).toFixed(1)})` });
          m.append(ikonaMaszyny(o.typ, kolor, 1.1));
          obrot.append(m);
        }
      } else if (sk >= 15) obrot.append(ikonaMaszyny(o.typ, kolor, sk >= 60 ? 1.5 : 1.1));
      else obrot.append(svg('path', { d: 'M0 -6 L5 5 L-5 5Z', fill: kolor }));
    } else {
      // Przy dużym powiększeniu okręt w skali bywa większy od ekranu — wtedy półprzezroczysty, żeby nie zasłaniał kontaktów.
      const rozmiar = Math.max(jestSrodkiem ? 34 : 22, (o.dlugosc_km || 1) * sk);
      const syl = sylwetka(o.profil, kolor, rozmiar);
      if (rozmiar > P * 0.8) syl.setAttribute('opacity', rozmiar > 3 * P ? 0.18 : 0.35);
      obrot.append(syl);
    }
    ikona.append(obrot);
    if (wybierz) ikona.addEventListener('click', (ev) => { ev.stopPropagation(); wybierz(o); });
    if (st.oznaczony === id || st.sledzony === id) opisy.append(svg('circle', { cx: 0, cy: 0, r: 12, fill: 'none', stroke: st.sledzony === id ? '#ffff99' : '#fff', 'stroke-width': 1.5 }));
    if (hz && klucz && !swoj) opisy.append(svg('text', { x: 8, y: -6, fill: '#ffd7a0', 'font-size': 11 }, st.hzPamiec.has(o.id) ? `${st.hzPamiec.get(o.id)} Hz` : '? Hz'));
    if (klucz && sk >= 15) opisy.append(svg('text', { x: 10, y: 12, fill: kolor, 'font-size': 9, opacity: 0.85 }, `${o.nr}`));
    miejsce.append(ikona, opisy);
    swiat.append(miejsce);
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
    'suwak: tempo omiatania': cyklicznie('tempo', [3, 6, 12], 'omiatanie (s/obrót)'),
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
  const teraz = zegar(st);
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
  const martwe = Uint8Array.from(atob(g.martwe_b64 || ''), (c) => c.charCodeAt(0));
  const temp = Uint8Array.from(atob(g.temperatury_b64 || ''), (c) => c.charCodeAt(0));
  // Jak w prototypie konsoli osłon: świecąca żyła — barwa od turkusu (zimna) do czerwieni (40° do przepalenia),
  // jasność spada przy przeciążeniu generatora; nieaktywna — ciemna; martwa — ciemnoczerwona.
  const jasnosc = 10 + 52 * Math.min(1.1, 100 / Math.max(100, g.obciazenie_proc || 0));
  const c = siatkaLampek(n, K, (i) => {
    if ((martwe[i >> 3] >> (i & 7)) & 1) return [92, 22, 22];
    if (krok && (i + 1) % krok === 0) return hsl(190 - Math.min(1, (temp[i] || 0) / 40) * 190, 85, jasnosc);
    return [16, 26, 34];
  });
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
  // Szary suwak: zakres trybu automatycznego — dwa uchwyty na skali 0…N Hz (N = liczba żył: 3600 u góry, 1800 w połowie).
  // Dotknięcie przesuwa bliższy uchwyt na najbliższy stopień natywny.
  const zakres = (g) => g && ({
    etykieta: `maks. ${g.hz_max} Hz`, etykietaMin: `min. ${g.hz_min} Hz`,
    zakres: { max: g.hz_max / (g.n || 3600), min: g.hz_min / (g.n || 3600) },
    ustawZakres: (wartosc, ktory) => {
      const hz = stopnie.reduce((a, b) => (Math.abs(b - wartosc * (g.n || 3600)) < Math.abs(a - wartosc * (g.n || 3600)) ? b : a), stopnie[0]);
      const max = ktory === 'max' ? Math.max(hz, g.hz_min) : g.hz_max, min = ktory === 'min' ? Math.min(hz, g.hz_max) : g.hz_min;
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
// Kolejność zapalania lampek reaktora: równomiernie po całej siatce (ciąg złotego podziału), nie od lewego górnego rogu —
// k pierwszych lampek tej kolejności to k porcji rozsianych po kwadracie.
const KOLEJNOSC_REAKTORA = (() => {
  const n = 3600, ranga = new Int16Array(n);
  [...Array(n).keys()].sort((a, b) => ((a * 0.6180339887) % 1) - ((b * 0.6180339887) % 1)).forEach((i, r) => { ranga[i] = r; });
  return ranga;
})();
function reaktory(s, st, akcje) {
  const j = wlasna(s);
  const pobor = j.pobor_oslon_proc || 0;
  const sensory = j.pobor_sensorow_proc || 0;
  // Siatka 60×60 lampek = porcje mocy reaktora: turkus — osłony, fiolet — sensory, ciemne — wolne.
  const ekran = () => {
    const n = 3600, os = Math.round(n * pobor / 100), se = Math.round(n * sensory / 100);
    return h('div', { style: `display:flex;flex-direction:column;width:100%;height:100%;padding:3px;box-sizing:border-box;gap:3px;color:#cfe;font-size:max(7px,0.7cqw);${CZCIONKA}` },
      h('div', `osłony ${pobor}% · sensory ${sensory}% · wolne ${(100 - pobor - sensory).toFixed(1)}%`),
      siatkaLampek(n, 60, (i) => { const r = KOLEJNOSC_REAKTORA[i]; return r < os ? [77, 226, 201] : r < os + se ? [201, 155, 255] : [16, 26, 34]; }));
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
        pierscienie: [st.skalaKlucza / 5, st.skalaKlucza / 2, st.skalaKlucza].map((r) => zaokr(r / z)), prognozy: true, wiazkaWl: false,
        obiekty: [...[...s.klucze.filter((x) => x.stan === 'w_locie' && x.id !== k.id), ...s.jednostki.filter((x) => x.stan !== 'oczekuje')]
          .filter((o) => !BEZ_SENSOROW.includes(k.typ) || o.strona === k.strona || odl(o, srodek) <= RADAR_WLASNY_KM
            || (j.stan === 'w_bitwie' && odl(o, j) <= RADAR_KM)), srodek] })) },
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
    'taśmociąg lewego pokładu · kolejka i ETA': { ekran: kolejka(1, 15, 'lewy pokład') },
    'taśmociąg prawego pokładu · kolejka i ETA': { ekran: kolejka(16, 30, 'prawy pokład') },
    'start klucza (lewy pokład)': { klik: startStrony(1, 15) }, 'start klucza (prawy pokład)': { klik: startStrony(16, 30) },
    'powrót do hangaru (lewy pokład)': { klik: powrotStrony(1, 15) }, 'powrót do hangaru (prawy pokład)': { klik: powrotStrony(16, 30) },
    'rozkaz klucza: patrol · ochrona · atak · skan': { etykieta: `rozkaz: ${nazwyR[st.rozkazMysliwcow]}${wybrany ? ` → klucz ${wybrany.nr}` : ''} (dotknij, by zmienić)`,
      klik: () => { st.rozkazMysliwcow = ROZK[(ROZK.indexOf(st.rozkazMysliwcow) + 1) % ROZK.length]; akcje.odswiez(); } },
    'kurs przechwycenia': { klik: wymagaj((k) => doKlucza(akcje, k, 'atak', 'Myśliwce')) },
    'wstrzymaj / zawróć': { etykieta: 'wstrzymaj / zawróć (ochrona okrętu)', klik: wymagaj((k) => doKlucza(akcje, k, 'ochrona', 'Myśliwce')) },
    'START': { etykieta: wybrany ? (wybrany.stan === 'hangar' ? `START klucza ${wybrany.nr}` : `WYKONAJ: ${st.rozkazMysliwcow} → klucz ${wybrany.nr}`) : 'START',
      klik: wymagaj((k) => doKlucza(akcje, k, k.stan === 'hangar' ? 'start' : st.rozkazMysliwcow, 'Myśliwce')) },
  };
  for (let n = 1; n <= 30; n++) {  // układ Walako: 30 gniazd (1–15 lewy pokład, 16–30 prawy)
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
function eskadra(s, st, akcje, stala = null) {
  const j = wlasna(s);
  const klucze = wlasneKlucze(s, j);
  const zoom = klucze.find((k) => k.id === st.zoomKlucz);
  if (zoom) return zblizenie(s, st, akcje, zoom, 'Eskadra');
  const ile = Math.max(1, Math.ceil(klucze.length / 3));
  if (stala) st.eskadra = stala;
  if (st.eskadra > ile) st.eskadra = 1;
  const moje = klucze.filter((k) => Math.ceil(k.nr / 3) === st.eskadra);
  const lot = moje.filter((k) => k.stan === 'w_locie');
  const sr = (f) => lot.reduce((a, k) => a + f(k), 0) / lot.length;
  const srodek = lot.length ? { x: sr((k) => k.x), y: sr((k) => k.y), v: Math.hypot(sr((k) => k.v * Math.sin(rad(k.kurs))), sr((k) => k.v * Math.cos(rad(k.kurs)))),
    kurs: (Math.atan2(sr((k) => k.v * Math.sin(rad(k.kurs))), sr((k) => k.v * Math.cos(rad(k.kurs)))) * 180) / Math.PI, strona: j.strona } : j;
  const wszystkim = (kod) => () => {
    const cel = moje.filter((k) => k.stan === 'w_locie' || (kod !== 'powrot' && k.stan === 'powrot'));
    if (!cel.length) return akcje.komunikat('Żaden klucz eskadry nie jest w locie.');
    cel.forEach((k) => doKlucza(akcje, k, kod, 'Eskadra'));
  };
  const w = {
    'ESKADRA N · klucze 3/3 · dowódca eskadry · alarm: normalny / żółty / czerwony': {
      etykieta: stala ? `ESKADRA ${st.eskadra} · w locie ${lot.length}/${moje.length}` : `ESKADRA ${st.eskadra} z ${ile} · w locie ${lot.length}/${moje.length} (dotknij — następna eskadra)`,
      klik: stala ? null : () => { st.eskadra = (st.eskadra % ile) + 1; akcje.odswiez(); } },
    'EKRAN ESKADRY · środek eskadry zawsze w środku · okręgi 5 / 15 / 40 km · 3 klucze z kursami · okręt macierzysty · cele i zagrożenia': {
      ekran: () => powiekszany(st, akcje, 'eskadra', (z) => ekranPlot(s, st, { zasieg: 40 / z, srodek, pierscienie: [5, 15, 40].map((r) => zaokr(r / z)), prognozy: true, wiazkaWl: false,
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

// ------------------------------------------------------------------------------------------- ster (wspólne)
// Rozkaz ster: kurs zadany i prędkość zadana (3 s przygotowania). Bez migracji 076 — komunikat.
const norm180 = (a) => ((((a + 180) % 360) + 360) % 360) - 180;
const namiarNa = (a, b) => (Math.atan2(b.x - a.x, b.y - a.y) * 180) / Math.PI;
function ster(akcje, j, pulpitNazwa, { kurs = null, v = null }) {
  if (!j.ster) return akcje.komunikat('Ster okrętu wymaga migracji 076.');
  akcje.rozkaz({ jednostka_id: j.id, kod: 'ster', kurs: kurs ?? j.kurs_cel ?? j.kurs, v: v ?? j.v_cel ?? j.v, pulpit: pulpitNazwa });
}
const opisSteru = (j) => `kurs ${Math.round(j.kurs)}°${j.kurs_cel != null && Math.abs(norm180(j.kurs_cel - j.kurs)) > 0.5 ? ` → ${Math.round(j.kurs_cel)}°` : ''}`
  + ` · ${(j.v * 3600).toFixed(0)} km/h${j.v_cel != null && Math.abs(j.v_cel - j.v) > 0.001 ? ` → ${(j.v_cel * 3600).toFixed(0)}` : ''}`;

// ------------------------------------------------------------------------------------------- Nawigacja
function nawigacja(s, st, akcje) {
  const j = wlasna(s);
  const obiekty = [...s.klucze.filter((x) => x.stan === 'w_locie'), ...s.jednostki.filter((x) => x.stan !== 'oczekuje')];
  const cel = obiekty.find((o) => idObiektu(o) === st.celNaw);
  const d = cel ? odl(cel, j) : null;
  const skala = (km) => ({ wl: st.skalaNaw === km && (st.zoom.nav || 1) === 1, klik: () => { st.skalaNaw = km; st.zoom.nav = 1; akcje.odswiez(); } });
  const kursCel = j.kurs_cel ?? j.kurs;
  const skretu = j.kurs_cel != null && Math.abs(norm180(j.kurs_cel - j.kurs)) > 0.5 ? (j.obrot_deg_min || 30) / 60 : 0;
  const N = 'Nawigacja';
  const wymagajCelu = (fn) => () => (cel ? fn(cel) : akcje.komunikat('Najpierw wybierz cel na ekranie nawigacji.'));
  const przechwycenie = (c) => {
    const vMoja = Math.max(0.05, j.v_maks || 0.27), t = odl(c, j) / vMoja;
    return namiarNa(j, { x: c.x + (c.v || 0) * t * Math.sin(rad(c.kurs || 0)), y: c.y + (c.v || 0) * t * Math.cos(rad(c.kurs || 0)) });
  };
  const ciag = (j.v_cel ?? j.v) / (j.v_maks || 0.27);
  return pulpit(j.profil, N, {
    'EKRAN NAWIGACJI · sylwetka ISD zawsze w środku, w skali · okręgi odległości 250 / 500 / 1000 km · wektor ruchu osobno od zwrotu dziobu · inne jednostki (własne, obce, nieznane) · kurs zadany i punkt docelowy': {
      ekran: () => powiekszany(st, akcje, 'nav', (z) => ekranPlot(s, st, { zasieg: st.skalaNaw / z, srodek: j, pierscienie: [st.skalaNaw / 4, st.skalaNaw / 2, st.skalaNaw].map((r) => zaokr(r / z)),
        prognozy: true, tory: st.tory, hz: st.syncSensory, obiekty, wybierz: (o) => { st.celNaw = idObiektu(o); akcje.odswiez(); } })) },
    'skala ekranu: 250 km': skala(250), 'skala ekranu: 500 km': skala(500), 'skala ekranu: 1000 km': skala(1000),
    'synchronizacja z sensorami (kontakty)': { wl: st.syncSensory, klik: () => { st.syncSensory = !st.syncSensory; akcje.odswiez(); } },
    'synchronizacja z radarem (tory jednostek)': { wl: st.tory, klik: () => { st.tory = !st.tory; akcje.odswiez(); } },
    'odczyt: wektor ruchu (kurs, prędkość)': { etykieta: opisSteru(j) },
    'odczyt: zwrot dziobu': { etykieta: `zwrot dziobu: ${Math.round(j.kurs)}°` },
    'odczyt: prędkość kątowa': { etykieta: `prędkość kątowa: ${skretu.toFixed(2)}°/s` },
    'odczyt: przeciążenie': { etykieta: 'przeciążenie: 0,0 g (kompensatory)' },
    'wybierz cel / punkt nawigacyjny': { etykieta: cel ? `cel: ${'nr' in cel ? `${cel.strona} ${cel.nr}` : cel.nazwa} (dotknij — wyczyść)` : 'wybierz cel (dotknij na ekranie)',
      klik: () => { st.celNaw = null; akcje.odswiez(); } },
    'odczyt: odległość do celu': { etykieta: d != null ? `do celu: ${km(d)} km` : 'do celu: —' },
    'odczyt: czas do celu (ETA)': { etykieta: d != null && j.v > 0 ? `ETA ${mmss(d / j.v)}` : 'ETA: —' },
    'kurs na wybrany cel': { klik: wymagajCelu((c) => ster(akcje, j, N, { kurs: namiarNa(j, c) })) },
    'kurs przechwycenia': { klik: wymagajCelu((c) => ster(akcje, j, N, { kurs: przechwycenie(c), v: j.v_maks })) },
    'kurs odejścia': { klik: wymagajCelu((c) => ster(akcje, j, N, { kurs: norm180(namiarNa(j, c) + 180), v: j.v_maks })) },
    'zwrot o 90° w lewo': { klik: () => ster(akcje, j, N, { kurs: norm180(kursCel - 90) }) },
    'zwrot o 90° w prawo': { klik: () => ster(akcje, j, N, { kurs: norm180(kursCel + 90) }) },
    'suwak: skręt w lewo (różnica ciągu lewy − prawy)': { etykieta: 'skręt w lewo −30°', klik: () => ster(akcje, j, N, { kurs: norm180(kursCel - 30) }) },
    'suwak: skręt w prawo (różnica ciągu prawy − lewy)': { etykieta: 'skręt w prawo +30°', klik: () => ster(akcje, j, N, { kurs: norm180(kursCel + 30) }) },
    'wyrównaj kurs (stabilizacja zwrotu)': { klik: () => ster(akcje, j, N, { kurs: j.kurs }) },
    'PEŁNE HAMOWANIE': { wl: j.v_cel === 0, klik: () => ster(akcje, j, N, { v: 0 }) },
    'CAŁA NAPRZÓD': { wl: j.v_cel != null && j.v_cel >= (j.v_maks || 0.27) - 1e-6, klik: () => ster(akcje, j, N, { v: j.v_maks }) },
    'suwak: ciąg główny (prędkość zadana)': { poz: ciag, etykieta: `ciąg główny: ${Math.round(ciag * 100)}% · ${((j.v_cel ?? j.v) * 3600).toFixed(0)} km/h (dotknij miejsca)`,
      klikX: (f) => ster(akcje, j, N, { v: Math.round(f * 20) / 20 * (j.v_maks || 0.27) }) },
  });
}

// ------------------------------------------------------------------------------------------- Silniki
// Ciąg silników głównych = prędkość zadana (rozkaz ster, jak w Nawigacji); silniki manewrowe — skręt.
function silniki(s, st, akcje) {
  const j = wlasna(s);
  const N = 'Silniki';
  const vMax = j.v_maks || 0.27;
  const ciag = (j.v_cel ?? j.v) / vMax, biezacy = j.v / vMax;
  const kursCel = j.kurs_cel ?? j.kurs;
  const skret = j.kurs_cel != null ? norm180(j.kurs_cel - j.kurs) : 0;
  const ekranSilnika = (nazwa, udzial) => () => h('div', { style: `display:flex;flex-direction:column;width:100%;height:100%;padding:4px;box-sizing:border-box;gap:3px;color:#cfe;font-size:max(7px,0.75cqw);${CZCIONKA}` },
    h('div', { style: 'color:#ffd7a0' }, nazwa),
    h('div', `ciąg ${Math.round(biezacy * udzial * 100)}% · zadany ${Math.round(ciag * udzial * 100)}%`),
    h('div', { style: 'flex:1;display:flex;align-items:flex-end;gap:2px' }, ...Array.from({ length: 20 }, (_, i) =>
      h('div', { style: `flex:1;height:${8 + i * 4}%;background:${i < Math.round(biezacy * 20) ? TURKUS : i < Math.round(ciag * 20) ? '#2a6f66' : '#16222a'}` }))),
    h('div', opisSteru(j)));
  const manewrowy = (strona) => () => h('div', { style: `display:flex;flex-direction:column;justify-content:center;width:100%;height:100%;padding:4px;box-sizing:border-box;color:#cfe;font-size:max(7px,0.72cqw);${CZCIONKA}` },
    h('div', { style: 'color:#ffd7a0' }, `manewrowe ${strona}`),
    h('div', Math.abs(skret) > 0.5 && (skret < 0) === (strona.includes('lew')) ? `pracują · skręt ${Math.round(skret)}°` : 'spoczynek'));
  const przyciskCiagu = (f) => ({ klik: () => ster(akcje, j, N, { v: f * vMax }) });
  return pulpit(j.profil, N, {
    'Silnik główny lewy': { ekran: ekranSilnika('silnik główny lewy', 1) },
    'Silnik główny prawy': { ekran: ekranSilnika('silnik główny prawy', 1) },
    'Silnik główny środkowy': { ekran: ekranSilnika('silnik główny środkowy', 1) },
    'Silnik manewrowy lewy górny': { ekran: manewrowy('lewe górne') }, 'Silnik manewrowy prawy górny': { ekran: manewrowy('prawe górne') },
    'Silnik manewrowy lewy dolny': { ekran: manewrowy('lewe dolne') }, 'Silnik manewrowy prawy dolny': { ekran: manewrowy('prawe dolne') },
    'suwak: maksymalny ciąg lewego silnika głównego': { etykieta: `maks. ${(vMax * 3600).toFixed(0)} km/h`, etykietaMin: 'min. 0', klikS: (gora) => ster(akcje, j, N, { v: gora ? vMax : 0 }) },
    'suwak: maksymalny ciąg prawego silnika głównego': { etykieta: `maks. ${(vMax * 3600).toFixed(0)} km/h`, etykietaMin: 'min. 0', klikS: (gora) => ster(akcje, j, N, { v: gora ? vMax : 0 }) },
    'suwak: ciąg środkowego silnika głównego': { poz: ciag, etykieta: `ciąg ${Math.round(ciag * 100)}% (dotknij)`,
      klikX: (f, fy) => ster(akcje, j, N, { v: Math.round((1 - fy) * 20) / 20 * vMax }) },
    'wyrównaj ciąg silników': { etykieta: 'wyrównaj ciąg (utrzymaj prędkość)', klik: () => ster(akcje, j, N, { v: j.v }) },
    'równaj ciąg z mocą reaktora': { etykieta: 'ciąg 75% (praca ciągła reaktora)', ...przyciskCiagu(0.75) },
    'wzmocnij lewy silnik główny': { etykieta: 'wzmocnij — cała naprzód', ...przyciskCiagu(1) },
    'wzmocnij prawy silnik główny': { etykieta: 'wzmocnij — cała naprzód', ...przyciskCiagu(1) },
    'wzmocnij środkowy silnik główny': { etykieta: 'wzmocnij — cała naprzód', ...przyciskCiagu(1) },
    'wzmocnij wszystkie silniki manewrowe': { etykieta: 'stop skrętu (trzymaj kurs)', klik: () => ster(akcje, j, N, { kurs: j.kurs }) },
    'suwak: trym odchylenia (silniki manewrowe)': { poz: 0.5 + Math.max(-0.5, Math.min(0.5, skret / 180)), etykieta: `odchylenie: ${Math.round(skret)}° (dotknij: ±15°)`,
      klikX: (f) => ster(akcje, j, N, { kurs: norm180(kursCel + (f < 0.5 ? -15 : 15)) }) },
  });
}

// ------------------------------------------------------------------------------------------- Turbolasery
// Baterie z rozstawienia na kadłubie (dane/uzbrojenie.py): cel w łuku i zasięgu, cel wskazany z Kontroli ognia.
function punktKadluba(j, x, y) {
  const dl = j.dlugosc_km || 1, s1 = Math.sin(rad(j.kurs || 0)), c1 = Math.cos(rad(j.kurs || 0));
  return { x: j.x + s1 * -y * dl + c1 * x * dl, y: j.y + c1 * -y * dl - s1 * x * dl };
}
function celeBaterii(s, j, rodzaj) {
  const wrogie = s.jednostki.filter((x) => x.strona !== j.strona && x.stan === 'w_bitwie');
  return (UZBROJENIE[j.profil] || []).filter((u) => u[1] === rodzaj).map(([nr, , x, y, lukS, lukP, zasieg, , hz]) => {
    const dz = punktKadluba(j, x, y);
    const wLuku = wrogie.filter((w) => odl(dz, w) <= zasieg && Math.abs(norm180(norm180(namiarNa(dz, w) - j.kurs) - lukS)) <= lukP)
      .sort((a, b) => odl(dz, a) - odl(dz, b));
    const cel = wLuku.find((w) => w.nr_jednostki === j.cel_ognia) || wLuku[0] || null;
    return { nr, hz, zasieg, cel, d: cel ? odl(dz, cel) : null, lewa: x < 0 };
  });
}
function turbolasery(s, st, akcje) {
  const j = wlasna(s);
  const tl = celeBaterii(s, j, 'turbolaser'), jon = celeBaterii(s, j, 'jon');
  const opis = (b) => b ? (b.cel ? `${b.cel.nazwa} · ${km(b.d)} km` : `brak celu w łuku (${b.zasieg} km)`) : '—';
  const wskazany = s.jednostki.find((x) => x.nr_jednostki === j.cel_ognia && x.strona !== j.strona);
  const bateria = (lewa) => () => {
    const b = tl.filter((x) => x.lewa === lewa);
    const hz = b[0]?.hz || 3600, krok = 3600 / hz, strzela = b.some((x) => x.cel);
    return h('div', { style: `display:flex;flex-direction:column;width:100%;height:100%;padding:3px;box-sizing:border-box;gap:3px;color:#cfe;font-size:max(7px,0.72cqw);${CZCIONKA}` },
      h('div', `bateria ${lewa ? 'lewa' : 'prawa'} · ${hz} Hz · ${strzela ? 'OGIEŃ' : 'czuwanie'}${wskazany ? ` · cel wskazany: ${wskazany.nazwa}` : ''}`),
      siatkaLampek(3600, 60, (i) => ((i + 1) % krok === 0 ? (strzela ? [255, 120, 90] : [77, 226, 201]) : [16, 26, 34])));
  };
  const w = {
    'WYBRANA BATERIA LEWA · SIATKA 60×60 (3600 lampek) · ekran dynamiczny · aktywne żyły = Hz': { ekran: bateria(true) },
    'WYBRANA BATERIA PRAWA · SIATKA 60×60 (3600 lampek) · ekran dynamiczny · aktywne żyły = Hz': { ekran: bateria(false) },
    'suwak: maksymalna częstotliwość lewej baterii turbolaserów': { etykieta: `maks. ${tl[0]?.hz || 3600} Hz`, etykietaMin: 'min. 1 Hz' },
    'suwak: maksymalna częstotliwość prawej baterii turbolaserów': { etykieta: `maks. ${tl[0]?.hz || 3600} Hz`, etykietaMin: 'min. 1 Hz' },
  };
  for (let i = 1; i <= 4; i++) {
    const bl = tl.find((b) => b.nr === i), bp = tl.find((b) => b.nr === i + 4);
    w[`Turbolaser lewy ${i} (8 luf)`] = { wl: !!bl?.cel, etykieta: `TL-L${i} · ${opis(bl)}` };
    w[`Turbolaser prawy ${i} (8 luf)`] = { wl: !!bp?.cel, etykieta: `TL-P${i} · ${opis(bp)}` };
  }
  for (const lewa of [true, false]) {
    const b = jon.filter((x) => x.lewa === lewa), cel = b.find((x) => x.cel) || b[0];
    w[`${lewa ? 'Lewa' : 'Prawa'} bateria jonowa`] = { wl: b.some((x) => x.cel), etykieta: `JON-${lewa ? 'L' : 'P'} · ${opis(cel)}` };
  }
  return pulpit(j.profil, 'Turbolasery', w);
}

// ------------------------------------------------------------------------------------------- Kontrola ognia
function kontrolaOgnia(s, st, akcje) {
  const j = wlasna(s);
  const K = 'Kontrola ognia';
  const obce = [...s.klucze.filter((x) => x.stan === 'w_locie' && x.strona !== j.strona), ...s.jednostki.filter((x) => x.strona !== j.strona && x.stan === 'w_bitwie')];
  const oznaczony = s.jednostki.find((x) => idObiektu(x) === st.oznaczony && x.strona !== j.strona);
  const wskazany = s.jednostki.find((x) => x.nr_jednostki === j.cel_ognia && x.strona !== j.strona);
  const wskaz = (cel) => {
    if (!j.ster) return akcje.komunikat('Wskazanie celu wymaga migracji 076.');
    akcje.rozkaz({ jednostka_id: j.id, kod: 'cel_ognia', cel_nr: cel ? cel.nr_jednostki : null, pulpit: K });
  };
  const przydziel = () => (oznaczony ? wskaz(oznaczony) : akcje.komunikat('Dotknij okrętu wroga na siatce taktycznej.'));
  const tl = celeBaterii(s, j, 'turbolaser'), jon = celeBaterii(s, j, 'jon');
  const stan = () => h('div', { style: `width:100%;height:100%;overflow:auto;padding:4px;box-sizing:border-box;font-size:max(7px,0.72cqw);text-align:left;color:#cfe;${CZCIONKA}` },
    h('div', { style: 'color:#ffd7a0;margin-bottom:3px' }, `CEL WSKAZANY: ${wskazany ? wskazany.nazwa : 'brak (najbliższy w łuku)'}`),
    ...[...tl.map((b) => [`TL ${b.lewa ? 'L' : 'P'}${b.lewa ? b.nr : b.nr - 4}`, b]), ...jon.map((b) => [`JON ${b.nr}`, b])].map(([n, b]) =>
      h('div', { style: `border-bottom:1px solid rgba(255,255,255,.08);color:${b.cel ? '#ff8a80' : '#9fb4c8'}` }, `${n} · ${b.cel ? `${b.cel.nazwa} ${km(b.d)} km` : 'brak celu'}`)));
  const blokada = { wl: !!wskazany, etykieta: wskazany ? `blokada: ${wskazany.nazwa} (dotknij — zwolnij)` : 'blokada celu (brak)', klik: () => wskaz(null) };
  return pulpit(j.profil, K, {
    'wskazanie celu: siatka taktyczna': { ekran: () => powiekszany(st, akcje, 'ogien', (z) => ekranPlot(s, st, { zasieg: 50 / z, srodek: j,
      pierscienie: [10, 25, 50].map((r) => zaokr(r / z)), prognozy: true, wybierz: (o) => { st.oznaczony = idObiektu(o); akcje.odswiez(); },
      obiekty: [...obce, j] })) },
    'stan baterii i dział jonowych': { ekran: stan },
    'przydziel cel lewej baterii': { klik: przydziel, etykieta: oznaczony ? `przydziel: ${oznaczony.nazwa}` : 'przydziel cel (dotknij okrętu)' },
    'przydziel cel prawej baterii': { klik: przydziel, etykieta: oznaczony ? `przydziel: ${oznaczony.nazwa}` : 'przydziel cel (dotknij okrętu)' },
    'wyrównaj baterie na cel': { klik: przydziel },
    'przydziel cel działom jonowym': { klik: przydziel },
    'blokada celu lewej baterii': blokada, 'blokada celu prawej baterii': blokada,
    'suwak: dystans do celu': { etykieta: oznaczony ? `dystans: ${km(odl(oznaczony, j))} km` : 'dystans: —' },
    'suwak: namiar celu (azymut)': { etykieta: oznaczony ? `namiar: ${wzgledem(j, oznaczony).namiar}°` : 'namiar: —' },
  });
}

// ------------------------------------------------------------------------------------------- Grawitacja
// Pole pokładowe 1 g; blokada Anchor/Lock nie działa w symulacji bitwy (bez wpływu na walkę) — odczyty.
function grawitacja(s, st, akcje) {
  const j = wlasna(s);
  const sekcje = Object.keys(j.sekcje || {});
  const ekranPokladow = () => h('div', { style: `display:grid;grid-template-columns:repeat(${Math.min(6, sekcje.length || 1)},1fr);gap:3px;width:100%;height:100%;padding:4px;box-sizing:border-box;font-size:max(7px,0.7cqw);color:#cfe;${CZCIONKA}` },
    ...sekcje.map((k) => { const v = j.sekcje[k]; return h('div', { style: `background:${v > 75 ? '#163a33' : v > 40 ? '#4a3d12' : '#4a1616'};border-radius:3px;padding:2px` }, `${k}`, h('br'), `${v > 25 ? '1,00' : '0,6'} g`); }));
  return pulpit(j.profil, 'Grawitacja', {
    'EKRAN: GRAWITACJA POKŁADÓW · sekcje, przeciążenia, spadki zasilania': { ekran: ekranPokladow },
    'EKRAN: REFERENCJA · POZYCJA · STABILNOŚĆ blokady': { ekran: () => h('div', { style: `padding:6px;color:#cfe;font-size:max(7px,0.75cqw);${CZCIONKA}` }, 'blokada Anchor/Lock: zwolniona', h('br'), `pozycja: ${km(j.x)} / ${km(j.y)} km`, h('br'), `kurs ${Math.round(j.kurs)}°`) },
    'odczyt: pobór mocy': { etykieta: 'pobór: 0,4% mocy' }, 'odczyt: sygnatura': { etykieta: 'sygnatura: pole 1 g' },
    'stan blokady: gotowa · stabilizowanie · utrzymana · zakłócona · zwalniana': { etykieta: 'stan blokady: gotowa' },
    'tryb codzienny': { wl: true },
  });
}

// Konsola o nazwie pulpitu (KONSOLE) albo stanowisko pilota: „Klucz N” (jeden klucz), „Eskadra N” (klucze 3N−2…3N).
export function konsola(nazwa, s, st, akcje) {
  if (!s.jednostki.length) return h('div.komunikat', 'Brak okrętu w tej symulacji.');
  ustawZegar(st, s);
  biezaceOdswiez = akcje.odswiez;
  const nrKlucza = /^Klucz (\d+)$/.exec(nazwa), nrEskadry = /^Eskadra (\d+)$/.exec(nazwa);
  if (nrKlucza) {
    const k = wlasneKlucze(s, wlasna(s)).find((x) => x.nr === Number(nrKlucza[1]));
    return k ? kluczPulpit(s, st, akcje, k, null) : h('div.komunikat', `Klucza ${nrKlucza[1]} nie ma w tej symulacji.`);
  }
  if (nrEskadry) return eskadra(s, st, akcje, Number(nrEskadry[1]));
  const f = { Radar: radar, Sensory: sensory, 'Osłony': oslony, Reaktory: reaktory, Turbolasery: turbolasery, Silniki: silniki, Grawitacja: grawitacja,
    'Myśliwce': mysliwce, Nawigacja: nawigacja, 'Kontrola ognia': kontrolaOgnia, Klucz: klucz, Eskadra: eskadra }[nazwa];
  if (f) return f(s, st, akcje);
  return h('div', h('div.slaby.maly-tekst', { style: 'margin-bottom:6px' }, 'Ten pulpit nie ma jeszcze mechaniki — widać układ do nauki.'),
    pulpit(wlasna(s).profil, nazwa, {}));
}

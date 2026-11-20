// Scena w tle datapada: „stan faktyczny” postaci, ustawiany przez MG.
//
//   kosmos      — gwiazdy powoli dryfują
//   wejscie     — gwiazdy rozciągają się w smugi, błysk, potem tunel nadprzestrzeni
//   nadswietlna — tunel nadprzestrzeni
//   wyjscie     — smugi się kurczą, błysk, z ciemności wyłania się planeta
//   orbita      — planeta (typ i odległość od MG) na tle gwiazd
//   powierzchnia — widok za oknem (biuro, hotel) albo z wrót hangaru: niebo i krajobraz planety
//   start       — wznoszenie: ~5 min chmur w kolorze atmosfery, potem czarne niebo i gwiazdy (planeta za rufą)
//
// `okno: 'isd'` / `'komrk'` — widok przez okno mostka gwiezdnego niszczyciela albo kokpit Kom'rka (trzy okna, symetryczne względem
// środkowego). `widok` (kurs dziobu z manewrem, miejsce na orbicie, cykl planety) przesuwa planetę
// i słońce w oknie zgodnie z kierunkiem dziobu i oświetla planetę zgodnie z położeniem słońca.
//
// Wszystko rysowane kodem na <canvas>, bez obrazków. Animacja zatrzymuje się, gdy scena
// jest niewidoczna (otwarta aplikacja, wygaszony ekran), żeby nie drenować baterii.

import { pora as poraAstro, stanUkladu } from './astro.js';

export const TYPY_PLANET = {
  pustynna: { nazwa: 'pustynna', kolory: ['#d9a35b', '#b97834', '#e8c58a'], atmosfera: '#f3c27a', chmury: 0.15 },
  lodowa: { nazwa: 'lodowa', kolory: ['#dbe9f4', '#9fc2dc', '#ffffff'], atmosfera: '#bfe3ff', chmury: 0.35 },
  oceaniczna: { nazwa: 'oceaniczna', kolory: ['#1f5fa8', '#163f7a', '#3b86c9'], atmosfera: '#6fb7ff', chmury: 0.55, lad: '#4f8a4a' },
  lesna: { nazwa: 'leśna', kolory: ['#2f6b34', '#1f4d26', '#5b8f3e'], atmosfera: '#9be0a6', chmury: 0.45, woda: '#235a8a' },
  wulkaniczna: { nazwa: 'wulkaniczna', kolory: ['#2a1a17', '#3d211a', '#1a1010'], atmosfera: '#ff6a3a', chmury: 0.1, lawa: '#ff5a1f' },
  gazowa: { nazwa: 'gazowy olbrzym', kolory: ['#c98a4b', '#e3b77d', '#8f5a35', '#f0d3a3'], atmosfera: '#ffd59a', pasy: true },
  miasto: { nazwa: 'miasto-planeta', kolory: ['#5d6470', '#454b55', '#7a818c'], atmosfera: '#ffd27a', chmury: 0.12, swiatla: true },
  bagienna: { nazwa: 'bagienna', kolory: ['#3b4f2f', '#2a3a24', '#55663a'], atmosfera: '#a9c98a', chmury: 0.6 },
  skalista: { nazwa: 'skalista', kolory: ['#8a8580', '#6b6661', '#a39e98'], atmosfera: '#d0d0d0', chmury: 0.05 },
  pionowe_miasto: { nazwa: 'księżyc-miasto', kolory: ['#3d424b', '#2c3038', '#4d535d'], atmosfera: '#c9a46a', chmury: 0.05, swiatla: true },
};

// Wysokość orbity nad powierzchnią (km) dla podejścia; widok może też podać `wysokosc_km` wprost
// (docelowo płynny lot ku planecie i od niej).
export const WYSOKOSCI_KM = { blisko: 400, srednio: 20000, daleko: 150000, krawedz: 5000000 };
const SREDNICA_DOMYSLNA_KM = 12000;
// Rozmiar kątowy kuli (stopnie) o średnicy `srednica` z odległości `odSrodka` od jej środka.
const rozmiarKatowy = (srednica, odSrodka) => (2 * Math.asin(Math.min(1, srednica / 2 / Math.max(srednica / 2, odSrodka))) * 180) / Math.PI;

// Niebo i krajobraz z powierzchni: [góra nieba, horyzont], warstwy terenu (od dalekiej do bliskiej), styl terenu.
const POWIERZCHNIE = {
  pustynna: { niebo: ['#5f9fd0', '#f3d3a0'], teren: ['#e2b06c', '#cf9452', '#b47638'], styl: 'wydmy', slonce: '#fff2c4' },
  lodowa: { niebo: ['#86b7e3', '#eaf6ff'], teren: ['#d3e6f3', '#b5d0e4', '#f4f9fc'], styl: 'szczyty', slonce: '#ffffff' },
  oceaniczna: { niebo: ['#3d86d0', '#c4e4ff'], teren: ['#4f8a4a', '#1f5fa8', '#174d8c'], styl: 'morze', slonce: '#fff6d8' },
  lesna: { niebo: ['#5ea7d9', '#d6efe0'], teren: ['#5d8f63', '#3d7442', '#24522a'], styl: 'las', slonce: '#fff6d8' },
  wulkaniczna: { niebo: ['#1d0907', '#a8401c'], teren: ['#3a2420', '#2a1714', '#140b0a'], styl: 'wulkany', slonce: '#ff9a5a' },
  gazowa: { niebo: ['#d8925a', '#ffe0b0'], teren: ['#f0c48e', '#e3a96c', '#c98a4b'], styl: 'chmury', slonce: '#fff1d0' },
  miasto: { niebo: ['#33415f', '#e0b27a'], teren: ['#4a5262', '#343a46', '#22262e'], styl: 'miasto', slonce: '#ffd59a' },
  bagienna: { niebo: ['#6f7f62', '#cbd4b2'], teren: ['#55663f', '#3b4f2f', '#25331e'], styl: 'bagno', slonce: '#f4f0cf' },
  skalista: { niebo: ['#7fa6c4', '#e3e2d6'], teren: ['#a59a8d', '#8a8075', '#675f57'], styl: 'plaskowyze', slonce: '#fffbe8' },
  // Pionowe miasto (Nar Shaddaa): wieże do góry kadru w kilku warstwach, kładki, neony, mgła i łuna.
  pionowe_miasto: { niebo: ['#1a1e2a', '#4b4048'], teren: ['#5a5f6a', '#3a3e47', '#262a31', '#121418'], styl: 'pionowe', slonce: '#ffe2b0' },
};
const CZAS_STARTU = 300000; // 5 min chmur, potem przejście w kosmos

const mieszaj = (a, b, f) => {
  const x = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
  const [p, q] = [x(a), x(b)];
  return `rgb(${p.map((v, i) => Math.round(v + (q[i] - v) * f)).join(',')})`;
};
const gladko = (a, b, x) => { const t = Math.min(1, Math.max(0, (x - a) / (b - a))); return t * t * (3 - 2 * t); };

const CZAS_WEJSCIA = 3600;  // ms
const CZAS_WYJSCIA = 3200;

// Prosty generator liczb pseudolosowych z ziarnem (ta sama planeta wygląda zawsze tak samo).
function losowe(ziarno) {
  let s = ziarno >>> 0 || 1;
  return () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296);
}

function hash(t) {
  let x = 2166136261;
  for (const c of t) x = Math.imul(x ^ c.charCodeAt(0), 16777619);
  return x >>> 0;
}

// Tekstura powierzchni planety: pas o szerokości 2× średnicy, przewijany przy obrocie.
function teksturaPlanety(typ, d, ziarno) {
  const t = TYPY_PLANET[typ] || TYPY_PLANET.skalista;
  const c = document.createElement('canvas');
  c.width = d * 2;
  c.height = d;
  const g = c.getContext('2d');
  const los = losowe(ziarno);
  g.fillStyle = t.kolory[0];
  g.fillRect(0, 0, c.width, c.height);

  // Rysuje plamę także „za krawędzią”, żeby pas łączył się bez szwu.
  const plama = (x, y, rx, ry, kolor, alfa) => {
    g.globalAlpha = alfa;
    g.fillStyle = kolor;
    for (const dx of [0, -c.width / 2, c.width / 2]) {
      g.beginPath();
      g.ellipse(((x + dx) % c.width + c.width) % c.width, y, rx, ry, 0, 0, Math.PI * 2);
      g.fill();
    }
    g.globalAlpha = 1;
  };
  const okres = c.width / 2;

  if (t.pasy) {
    for (let y = 0; y < d; y += 2) {
      const f = Math.sin(y / d * 18 + los() * 0.6) * 0.5 + Math.sin(y / d * 47) * 0.25;
      g.fillStyle = t.kolory[Math.floor((f + 1) * 1.99) % t.kolory.length];
      g.globalAlpha = 0.55;
      g.fillRect(0, y, c.width, 2);
    }
    g.globalAlpha = 1;
    plama(okres * 0.3, d * 0.62, d * 0.09, d * 0.05, '#b4552e', 0.8); // wielka burza
  } else {
    for (let i = 0; i < 70; i++) {
      const kolor = t.lad && los() < 0.5 ? t.lad : t.woda && los() < 0.3 ? t.woda : t.kolory[1 + Math.floor(los() * (t.kolory.length - 1))];
      plama(los() * okres, d * (0.1 + los() * 0.8), d * (0.03 + los() * 0.12), d * (0.02 + los() * 0.07), kolor, 0.55);
    }
    if (t.lawa) {
      for (let i = 0; i < 40; i++) plama(los() * okres, los() * d, d * 0.01 + los() * d * 0.02, d * 0.004 + los() * d * 0.01, t.lawa, 0.85);
    }
    // czapy polarne
    if (typ !== 'wulkaniczna' && typ !== 'pustynna') {
      g.globalAlpha = typ === 'lodowa' ? 0.9 : 0.6;
      g.fillStyle = '#f4f8fb';
      g.fillRect(0, 0, c.width, d * 0.06);
      g.fillRect(0, d * 0.94, c.width, d * 0.06);
      g.globalAlpha = 1;
    }
    for (let i = 0; i < 90 * (t.chmury || 0); i++) {
      plama(los() * okres, d * (0.05 + los() * 0.9), d * (0.05 + los() * 0.15), d * (0.006 + los() * 0.02), '#ffffff', 0.35);
    }
  }
  return c;
}

// Światła miast po nocnej stronie.
function teksturaSwiatel(d, ziarno) {
  const c = document.createElement('canvas');
  c.width = d * 2;
  c.height = d;
  const g = c.getContext('2d');
  const los = losowe(ziarno ^ 0x5bd1e995);
  for (let i = 0; i < d * 6; i++) {
    g.fillStyle = `rgba(255, ${190 + los() * 60 | 0}, 110, ${0.4 + los() * 0.6})`;
    const x = los() * d, y = d * (0.08 + los() * 0.84);
    g.fillRect(x, y, 1.2, 1.2);
    g.fillRect(x + d, y, 1.2, 1.2);
  }
  return c;
}

const POLE_WIDZENIA = 100;
const KM_NA_AU_SCENA = 149597870.7; // stopnie w poziomie na całą szerokość ekranu
const norm180 = (k) => ((((k + 180) % 360) + 360) % 360) - 180;

// Kurs dziobu teraz (z trwającym manewrem). Kąty w układzie mapy: 0° = wschód, 90° = północ.
function kursDziobu(widok, t) {
  const m = widok?.manewr;
  if (m && t < m.do) {
    const p = Math.max(0, Math.min(1, (t - m.od) / Math.max(1, m.do - m.od)));
    return m.start + norm180(m.cel - m.start) * p;
  }
  return m ? m.cel : (widok?.kurs ?? 90);
}

// Zaokrąglony wielokąt (okna mostka).
function wielokat(g, pkt, r) {
  const n = pkt.length;
  g.beginPath();
  for (let i = 0; i < n; i++) {
    const a = pkt[(i + n - 1) % n], b = pkt[i], c = pkt[(i + 1) % n];
    const sx = (a[0] + b[0]) / 2, sy = (a[1] + b[1]) / 2;
    if (i === 0) g.moveTo(sx, sy);
    g.arcTo(b[0], b[1], (b[0] + c[0]) / 2, (b[1] + c[1]) / 2, r);
  }
  g.closePath();
}

// Ramy okien rysujemy na stałym, kwadratowym WZORZE (w = h). Ekran pokazuje zawsze środkowy wycinek
// wzoru we własnych proporcjach („cover”): wąski ekran ucina boki, szeroki — górę i dół. Wzór nigdy
// się nie rozciąga, a tło (niebo, planeta, krajobraz) leży w tym samym kadrze.
//
// Mostek ISD: trzy środkowe okna w proporcjach z rysunku mostka (H = wysokość okna):
// środkowe — trapez rozszerzający się ku dołowi (góra 0,9 H, dół 1,65 H); boczne — odwrócone trapezy
// (góra 1,33 H, dół 0,7 H), których wewnętrzne boki biegną równolegle do boków środkowego, za skośnym
// słupkiem szerokości 0,12 H. Na telefonie w pionie obok środkowego okna widać górne skosy bocznych.
function geometriaOknaIsd(w, h) {
  const sx = w / 2, H = Math.min(w, h) * 0.27;
  const gora = h / 2 - H / 2, dol = gora + H;
  const sGora = 0.45 * H, sDol = 0.825 * H, slupek = 0.12 * H, bokGora = 1.33 * H, bokDol = 0.7 * H;
  const wGora = sGora + slupek, wDol = sDol + slupek; // wewnętrzne krawędzie bocznych okien
  return { gora, dol, okna: [
    [[sx - sGora, gora], [sx + sGora, gora], [sx + sDol, dol], [sx - sDol, dol]],
    [[sx - wGora - bokGora, gora], [sx - wGora, gora], [sx - wDol, dol], [sx - wDol - bokDol, dol]],
    [[sx + wGora, gora], [sx + wGora + bokGora, gora], [sx + wDol + bokDol, dol], [sx + wDol, dol]],
  ] };
}

// Kokpit Kom'rka: szeroka, kanciasta owiewka (rozciągnięty sześciokąt) podzielona pionowym wspornikiem
// na dwie szyby; pod nią konsola do dołu wzoru.
function geometriaOknaKomrk(w, h) {
  const x0 = w * 0.08, x1 = w - x0, sx = w / 2;
  const gora = h * 0.3, dol = h * 0.6, srodek = (gora + dol) / 2;
  const skos = (x1 - x0) * 0.07, wspornik = Math.max(4, w * 0.006);
  return { gora, dol, konsola: true, okna: [
    [[x0 + skos, gora], [sx - wspornik, gora], [sx - wspornik, dol], [x0 + skos, dol], [x0, srodek]],
    [[sx + wspornik, gora], [x1 - skos, gora], [x1, srodek], [x1 - skos, dol], [sx + wspornik, dol]],
  ] };
}

// Wnętrza z ramą: okno budynku (szyby na środku wzoru, ściana dookoła) i wrota hangaru (widok na całą
// szerokość, rama tylko u góry i u dołu).
const OKNA_BUDYNKOW = ['biuro', 'hotel', 'kantyna', 'sklep', 'garaz'];
const HANGARY = ['hangar', 'port', 'poklad'];

// Boczne iluminatory w burcie okrętu: rząd prostokątnych okien na środku wzoru (na telefonie w pionie
// widać jedno–dwa, na szerokim ekranie cały rząd).
function geometriaOknaBoczne(w, h) {
  const H = Math.min(w, h) * 0.2, W = H * 1.15, odstep = H * 0.42;
  const gora = h / 2 - H / 2, dol = gora + H;
  const okna = [];
  for (let i = -3; i <= 3; i++) {
    const x = w / 2 + i * (W + odstep) - W / 2;
    okna.push([[x, gora], [x + W, gora], [x + W, dol], [x, dol]]);
  }
  return { gora, dol, okna };
}

const geometriaOkna = (typ, w, h) => (typ === 'komrk' ? geometriaOknaKomrk(w, h) : typ === 'iluminator' ? geometriaOknaBoczne(w, h) : geometriaOknaIsd(w, h));
const OKNA_Z_RAMA = ['isd', 'komrk', 'iluminator'];

function rysujOknoIsd(w, h, dpr, typ = 'isd') {
  const c = document.createElement('canvas');
  c.width = Math.max(1, w * dpr); c.height = Math.max(1, h * dpr);
  const g = c.getContext('2d');
  g.scale(dpr, dpr);
  const geo = geometriaOkna(typ, w, h);
  const { gora, dol, okna } = geo;
  const r = Math.min(w, h) * 0.02;
  // poszycie
  const kad = g.createLinearGradient(0, 0, 0, h);
  kad.addColorStop(0, '#202326'); kad.addColorStop(0.6, '#1a1c1f'); kad.addColorStop(1, '#141618');
  g.fillStyle = kad; g.fillRect(0, 0, w, h);
  // szyby: wycięte z poszycia
  g.globalCompositeOperation = 'destination-out';
  for (const o of okna) { wielokat(g, o, r); g.fill(); }
  g.globalCompositeOperation = 'source-over';
  // lekki połysk szyb
  for (const o of okna) {
    g.save(); wielokat(g, o, r); g.clip();
    const pol = g.createLinearGradient(0, gora, w * 0.3, dol);
    pol.addColorStop(0, 'rgba(255,255,255,0.035)'); pol.addColorStop(0.5, 'rgba(255,255,255,0)'); pol.addColorStop(1, 'rgba(255,255,255,0.02)');
    g.fillStyle = pol; g.fillRect(0, 0, w, h);
    g.restore();
  }
  // rama: ciemny kołnierz, stalowa faza i jasna krawędź (najjaśniej u dołu)
  const jasna = g.createLinearGradient(0, gora, 0, dol);
  jasna.addColorStop(0, 'rgba(110,116,120,0.35)'); jasna.addColorStop(1, 'rgba(205,210,208,0.9)');
  for (const o of okna) {
    for (const [szer, kolor] of [[Math.max(10, w * 0.016), '#101214'], [Math.max(6, w * 0.01), '#2b2f33'], [Math.max(3, w * 0.004), '#454a4f']]) {
      wielokat(g, o, r); g.lineWidth = szer; g.strokeStyle = kolor; g.stroke();
    }
    wielokat(g, o, r); g.lineWidth = Math.max(1.5, w * 0.0018); g.strokeStyle = jasna; g.stroke();
  }
  if (geo.konsola) {
    // konsola pod owiewką: ciemniejszy pulpit z przygaszonymi wskaźnikami
    const top = dol + Math.max(10, h * 0.03);
    const kon = g.createLinearGradient(0, top, 0, h);
    kon.addColorStop(0, '#16181b'); kon.addColorStop(1, '#0c0d0f');
    g.fillStyle = kon; g.fillRect(0, top, w, h - top);
    g.strokeStyle = '#2b2f33'; g.lineWidth = 2; g.beginPath(); g.moveTo(0, top); g.lineTo(w, top); g.stroke();
    let ziarno = 7;
    const los = () => ((ziarno = (ziarno * 1664525 + 1013904223) >>> 0) / 4294967296);
    const kolory = ['rgba(77,226,201,0.75)', 'rgba(255,179,71,0.7)', 'rgba(255,90,79,0.65)', 'rgba(140,170,255,0.6)'];
    for (let i = 0; i < 26; i++) {
      const x = w * (0.08 + los() * 0.84), y = top + (h - top) * (0.2 + los() * 0.6);
      g.fillStyle = kolory[Math.floor(los() * kolory.length)];
      if (los() < 0.6) g.fillRect(x, y, 3 + los() * 10, 2.5);
      else { g.beginPath(); g.arc(x, y, 2.2, 0, Math.PI * 2); g.fill(); }
    }
  }
  return c;
}

export function utworzScene(canvas) {
  const ctx = canvas.getContext('2d');
  let w = 0, h = 0, dpr = 1;
  // Ekran (CSS px) i przesunięcie kadru: przy ramie w × h to wymiary wzoru, większe od ekranu.
  let ekranW = 0, ekranH = 0, kadrX = 0;
  let stan = { scena: 'kosmos', planeta: 'oceaniczna', odleglosc: 'srednio', zmieniono: 0 };
  let start = performance.now();
  let klatka = null;
  let gwiazdy = [];
  let tunel = [];
  let gwiazdyBok = []; // z bocznego iluminatora: gwiazdy przesuwają się poziomo (x, y w ułamkach wzoru, z = odległość)
  let planeta = null; // { tekstura, swiatla, d, typ }
  let krajobraz = null; // { klucz, plotno, swiatla: [{x, y}] }
  let chmury = [];
  let oknoIsd = null; // { w, h, plotno }
  let panorama = null; // { ziarno, gwiazdy: [{ k, y, r, a, m, f }] } — niebo 360° widziane z mostka
  const spokojnie = matchMedia('(prefers-reduced-motion: reduce)').matches;
  // Gwiazdy narysowane w tej klatce (do zaznaczania): { x, y (px ekranu), klucz, g (układ z serwera) albo i (tło) }.
  let narysowane = [], zbierane = [];
  let zaznaczona = null; // klucz zaznaczonej gwiazdy
  let kadrYTeraz = 0;

  function rozmiar() {
    dpr = Math.min(window.devicePixelRatio || 1, 1.5);
    ekranW = canvas.clientWidth;
    ekranH = canvas.clientHeight;
    w = ekranW; h = ekranH;
    canvas.width = Math.max(1, ekranW * dpr);
    canvas.height = Math.max(1, ekranH * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    planeta = null;
    krajobraz = null;
    oknoIsd = null;
  }

  function noweGwiazdy() {
    gwiazdy = Array.from({ length: 420 }, () => ({ x: (Math.random() - 0.5) * 2, y: (Math.random() - 0.5) * 2, z: Math.random(), pz: 0 }));
    tunel = Array.from({ length: 360 }, () => ({ a: Math.random() * Math.PI * 2, z: Math.random(), r: 0.6 + Math.random() * 0.8, j: Math.random() }));
    gwiazdyBok = Array.from({ length: 320 }, () => ({ x: Math.random(), y: Math.random(), z: 0.08 + Math.random() * 0.92, j: Math.random() }));
  }

  function przygotujPlanete() {
    const typ = TYPY_PLANET[stan.planeta] ? stan.planeta : 'skalista';
    // Średnica na ekranie z rozmiaru kątowego: prawdziwa średnica planety i wysokość orbity,
    // przeliczone przez pole widzenia (POLE_WIDZENIA stopni na szerokość wzoru).
    const srednica = Number(stan.widok?.cykl?.srednica_km) || SREDNICA_DOMYSLNA_KM;
    const wysokosc = Number(stan.widok?.wysokosc_km ?? WYSOKOSCI_KM[stan.odleglosc] ?? WYSOKOSCI_KM.srednio);
    // Z krawędzi układu (miliony km) prawdziwa tarcza to 2–3 px — gubiła się w czerni okna, więc
    // trzyma co najmniej 14 px (patrz też szersza poświata w rysujPlanete).
    const minimum = stan.odleglosc === 'krawedz' ? 14 : 3;
    const d = Math.max(minimum, rozmiarKatowy(srednica, srednica / 2 + wysokosc) * w / POLE_WIDZENIA);
    // Tekstura w rozmiarach potęgi dwójki (8–1024 px): przy zbliżaniu i oddalaniu nie generuje się co klatkę.
    const dt = Math.min(1024, 2 ** Math.ceil(Math.log2(Math.max(8, d))));
    if (planeta && planeta.typ === typ && planeta.dt === dt) { planeta.d = d; return; }
    const ziarno = hash((stan.miejsce || '') + typ);
    planeta = { typ, d, dt, tekstura: teksturaPlanety(typ, dt, ziarno), swiatla: TYPY_PLANET[typ].swiatla ? teksturaSwiatel(dt, ziarno) : null };
  }

  // ---------- warstwy ----------

  function tlo(kolorSrodka = '#060b14') {
    const g = ctx.createRadialGradient(w / 2, h / 2, 0, w / 2, h / 2, Math.max(w, h) * 0.75);
    g.addColorStop(0, kolorSrodka);
    g.addColorStop(1, '#010204');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, w, h);
  }

  // Gwiazdy 3D: predkosc 0 = stoją, duże = smugi. Smuga = odcinek od poprzedniej pozycji.
  function rysujGwiazdy(predkosc, dt, jasnosc = 1, smugi = 1) {
    const cx = w / 2, cy = h / 2, skala = Math.max(w, h) * 0.6;
    for (const s of gwiazdy) {
      s.pz = s.z;
      s.z -= predkosc * dt;
      if (s.z <= 0.02) {
        s.x = (Math.random() - 0.5) * 2;
        s.y = (Math.random() - 0.5) * 2;
        s.z = 1;
        s.pz = 1;
      }
      const x = cx + (s.x / s.z) * skala, y = cy + (s.y / s.z) * skala;
      if (x < -50 || x > w + 50 || y < -50 || y > h + 50) continue;
      const a = Math.min(1, (1 - s.z) * 1.4) * jasnosc;
      const dlug = Math.min(1, smugi);
      const px = cx + (s.x / (s.z + (s.pz - s.z) * dlug * 18 + 0.0001)) * skala;
      const py = cy + (s.y / (s.z + (s.pz - s.z) * dlug * 18 + 0.0001)) * skala;
      const grubosc = Math.max(0.6, (1 - s.z) * 2.2);
      if (smugi > 0.15) {
        ctx.strokeStyle = `rgba(${200 + (1 - s.z) * 55 | 0}, ${225 + (1 - s.z) * 30 | 0}, 255, ${a})`;
        ctx.lineWidth = grubosc;
        ctx.beginPath();
        ctx.moveTo(px, py);
        ctx.lineTo(x, y);
        ctx.stroke();
      } else {
        ctx.fillStyle = `rgba(220, 235, 255, ${a})`;
        ctx.fillRect(x, y, grubosc, grubosc);
      }
    }
  }

  // Tunel nadprzestrzeni: wirujące, niebiesko-białe smugi uciekające od środka.
  function rysujTunel(t, dt, sila = 1) {
    const cx = w / 2, cy = h / 2, R = Math.max(w, h) * 0.55;
    const g = ctx.createRadialGradient(cx, cy, 0, cx, cy, R * 1.2);
    g.addColorStop(0, `rgba(235, 245, 255, ${0.9 * sila})`);
    g.addColorStop(0.08, `rgba(140, 190, 255, ${0.55 * sila})`);
    g.addColorStop(0.35, `rgba(30, 70, 160, ${0.45 * sila})`);
    g.addColorStop(1, `rgba(2, 6, 20, ${sila})`);
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, w, h);
    const wir = t * 0.00035;
    ctx.lineCap = 'round';
    for (const p of tunel) {
      p.z -= dt * 0.00055 * (0.7 + p.j * 0.6);
      if (p.z <= 0.03) {
        p.z = 1;
        p.a = Math.random() * Math.PI * 2;
      }
      const a = p.a + wir + (1 - p.z) * 0.9;
      const r1 = (p.r * R * 0.08) / p.z, r2 = (p.r * R * 0.08) / (p.z + 0.08);
      const x1 = cx + Math.cos(a) * r1, y1 = cy + Math.sin(a) * r1;
      const x2 = cx + Math.cos(a - 0.12) * r2, y2 = cy + Math.sin(a - 0.12) * r2;
      const alfa = Math.min(1, (1 - p.z) * 1.6) * sila;
      ctx.strokeStyle = p.j > 0.85 ? `rgba(255, 255, 255, ${alfa})` : `rgba(${120 + p.j * 100 | 0}, ${170 + p.j * 70 | 0}, 255, ${alfa * 0.8})`;
      ctx.lineWidth = Math.max(0.8, (1 - p.z) * 4);
      ctx.beginPath();
      ctx.moveTo(x2, y2);
      ctx.lineTo(x1, y1);
      ctx.stroke();
    }
    // „mgła” tunelu: pulsujące pierścienie
    for (let i = 0; i < 3; i++) {
      const f = ((t * 0.00025 + i / 3) % 1);
      ctx.strokeStyle = `rgba(160, 200, 255, ${(1 - f) * 0.12 * sila})`;
      ctx.lineWidth = 30 * f + 4;
      ctx.beginPath();
      ctx.arc(cx, cy, f * R * 1.3, 0, Math.PI * 2);
      ctx.stroke();
    }
  }

  // Z bocznego iluminatora: gwiazdy płyną poziomo od dziobu ku rufie (lewa burta: z prawej na lewo,
  // prawa burta: z lewej na prawo). `predkosc` w szerokościach wzoru na ms dla najbliższych gwiazd;
  // dalsze płyną wolniej (paralaksa), smuga = droga z ostatnich ~70 ms × `smugi`.
  const kierunekBoku = () => (stan.burta === 'prawa' ? 1 : -1);
  function rysujGwiazdyBok(predkosc, dt, jasnosc = 1, smugi = 1) {
    const kier = kierunekBoku();
    ctx.lineCap = 'round';
    for (const s of gwiazdyBok) {
      const v = predkosc * (0.12 / s.z);
      s.x += kier * v * dt;
      if (s.x < -0.3 || s.x > 1.3) { s.x = kier > 0 ? -0.25 * Math.random() : 1 + 0.25 * Math.random(); s.y = Math.random(); }
      const x = s.x * w, y = s.y * h;
      const dl = Math.min(w * 0.9, v * w * 70 * smugi);
      const a = Math.min(1, (1.1 - s.z) * 1.2) * jasnosc;
      ctx.strokeStyle = s.j > 0.85 ? `rgba(255, 255, 255, ${a})` : `rgba(${200 + s.j * 50 | 0}, ${215 + s.j * 35 | 0}, 255, ${a * 0.85})`;
      ctx.lineWidth = Math.max(0.6, (1.1 - s.z) * 2.2);
      ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x - kier * Math.max(0.5, dl), y); ctx.stroke();
    }
  }

  // Tunel widziany z boku: niebiesko-białe pasma i długie poziome smugi pędzące ku rufie.
  function rysujTunelBok(t, dt, sila = 1) {
    const g = ctx.createLinearGradient(0, 0, 0, h);
    // Iluminatory widzą tylko pas wokół środka wzoru (ok. 0,4–0,6 h), więc tam leży cała gra światła.
    g.addColorStop(0, `rgba(2, 6, 20, ${sila})`);
    g.addColorStop(0.38, `rgba(6, 16, 52, ${sila})`);
    g.addColorStop(0.5, `rgba(28, 64, 150, ${0.9 * sila})`);
    g.addColorStop(0.62, `rgba(6, 16, 52, ${sila})`);
    g.addColorStop(1, `rgba(2, 6, 20, ${sila})`);
    ctx.fillStyle = g; ctx.fillRect(0, 0, w, h);
    // falujące pasma mgły tunelu
    const kier = kierunekBoku();
    for (let i = 0; i < 5; i++) {
      const y = h * (0.41 + 0.045 * i + 0.012 * Math.sin(t * 0.0007 + i * 1.7));
      const grub = h * (0.008 + 0.006 * Math.sin(t * 0.0011 + i));
      const pas = ctx.createLinearGradient(0, y - grub, 0, y + grub);
      pas.addColorStop(0, 'rgba(170, 210, 255, 0)'); pas.addColorStop(0.5, `rgba(150, 200, 255, ${0.22 * sila})`); pas.addColorStop(1, 'rgba(170, 210, 255, 0)');
      ctx.fillStyle = pas; ctx.fillRect(0, y - grub, w, grub * 2);
    }
    rysujGwiazdyBok(0.0045 * (spokojnie ? 0.2 : 1) * (0.6 + 0.4 * sila), dt, sila, 2.2);
    void kier;
  }

  // Pomieszczenie bez okien: ściana z panelami, pas świateł i konsola; scena za burtą niewidoczna.
  function rysujWnetrze(t) {
    const g = ctx.createLinearGradient(0, 0, 0, h);
    g.addColorStop(0, '#15191d'); g.addColorStop(0.55, '#1c2126'); g.addColorStop(1, '#0d1013');
    ctx.fillStyle = g; ctx.fillRect(0, 0, w, h);
    const krok = Math.max(70, w * 0.12);
    ctx.strokeStyle = 'rgba(0, 0, 0, 0.45)'; ctx.lineWidth = 2;
    for (let x = (w % krok) / 2; x < w; x += krok) { ctx.beginPath(); ctx.moveTo(x, h * 0.18); ctx.lineTo(x, h * 0.78); ctx.stroke(); }
    ctx.fillStyle = 'rgba(0, 0, 0, 0.35)'; ctx.fillRect(0, h * 0.18 - 3, w, 3); ctx.fillRect(0, h * 0.78, w, 3);
    // pas świateł pod stropem
    const sw = ctx.createLinearGradient(0, h * 0.1, 0, h * 0.2);
    sw.addColorStop(0, 'rgba(200, 225, 255, 0)'); sw.addColorStop(0.5, 'rgba(200, 225, 255, 0.16)'); sw.addColorStop(1, 'rgba(200, 225, 255, 0)');
    ctx.fillStyle = sw; ctx.fillRect(0, h * 0.1, w, h * 0.1);
    // wskaźniki na panelach (mrugają powoli)
    const takt = Math.floor(t / 1300);
    for (let i = 0; i < 18; i++) {
      const x = ((i * 977) % 1000) / 1000 * w, y = h * (0.3 + (((i * 389) % 100) / 100) * 0.4);
      if ((i + takt) % 7 === 0) continue;
      ctx.fillStyle = i % 3 ? 'rgba(77, 226, 201, 0.55)' : 'rgba(255, 179, 71, 0.5)';
      ctx.fillRect(x, y, 6, 2.5);
    }
  }

  // Wysokość na ekranie dla ułamka „poziomego” projektu (okno 0,08–0,84 h). W pionie z oknem ISD
  // liczona względem środkowego okna, żeby planeta i słońce nie chowały się pod poszyciem.
  const yWidoku = (u) => {
    if (!stan.okno) return h * u;
    const { gora, dol } = geometriaOkna(stan.okno, w, h);
    return gora + (u - 0.08) / 0.76 * (dol - gora);
  };

  // Wielkość planety i słońca: z oknem mostka względem wysokości okna (jak dawny pas 0,08–0,84 ekranu),
  // bez okna względem krótszego boku ekranu.
  const skalaWidoku = () => {
    if (!stan.okno) return Math.min(w, h);
    const { gora, dol } = geometriaOkna(stan.okno, w, h);
    return (dol - gora) / 0.76;
  };

  // Kierunki z mostka (stopnie w układzie mapy) liczone z `stan.widok` w danej chwili.
  function kierunki() {
    const v = stan.widok;
    if (!v) return null;
    const t = (Date.now() + (stan.przesuniecie || 0)) / 1000;
    const dziob = kursDziobu(v, t);
    // Z bocznego iluminatora patrzymy prostopadle do kursu; reszta (orbita, słońce) zostaje bez zmian.
    const kurs = dziob + (stan.okno === 'iluminator' ? (stan.burta === 'prawa' ? -90 : 90) : 0);
    const orbita = v.orbita ?? norm180(dziob + 180);           // strona planety, przy której stoi okręt
    const doPlanety = orbita + 180;                           // kierunek z okrętu na planetę
    // Kierunek na słońce: z punktu układu podaje serwer (widok.slonce), przy planecie — z jej orbity.
    const slonce = v.slonce?.kat ?? (v.cykl ? stanUkladu(v.cykl, t).katSlonca : null);
    return { kurs, orbita, doPlanety, slonce };
  }
  // Pozioma pozycja na ekranie obiektu w danym kierunku (cel na lewo od dziobu → na lewo w oknie).
  const xKierunku = (kurs, kierunek) => w / 2 - norm180(kierunek - kurs) * (w / POLE_WIDZENIA);

  // Panorama tła z mostka: losowe (ale stałe dla okrętu) gwiazdy w pełnym kole 360°. Przy obrocie
  // dziobu całe niebo przesuwa się razem z planetą, słońcem i gwiazdami sąsiednich układów.
  function rysujPanorame(t, zTlem = true) {
    const k = kierunki();
    if (!k) return false;
    // Niebo zależy od miejsca (układu), nie od pomieszczenia na okręcie.
    const ziarno = hash(String(stan.miejsce || stan.opis || 'niebo'));
    if (panorama?.ziarno !== ziarno) {
      const los = losowe(ziarno);
      panorama = { ziarno, gwiazdy: Array.from({ length: 900 }, () => {
        const jasna = los() < 0.06;
        return { k: los() * 360, y: los(), r: jasna ? 1.1 + los() * 0.8 : 0.5 + los() * 0.6,
          a: jasna ? 0.75 + los() * 0.25 : 0.2 + los() * 0.5, m: 0.5 + los() * 2, f: los() * Math.PI * 2 };
      }) };
    }
    if (zTlem) tlo();
    const migot = spokojnie ? 0 : 0.18;
    panorama.gwiazdy.forEach((g, i) => {
      if (Math.abs(norm180(g.k - k.kurs)) > POLE_WIDZENIA / 2 + 1) return;
      ctx.fillStyle = `rgba(220, 232, 255, ${g.a * (1 - migot + migot * Math.sin(t * 0.0015 * g.m + g.f))})`;
      const x = xKierunku(k.kurs, g.k), y = g.y * h;
      ctx.fillRect(x, y, g.r, g.r);
      zbierz(x + g.r / 2, y + g.r / 2, `b:${panorama.ziarno}:${i}`, { i, ziarno: panorama.ziarno, jasnosc: g.a });
    });
    return true;
  }

  // Gwiazdy sąsiednich układów (`widok.gwiazdy`): w swoim kierunku względem dziobu, jasność
  // według odległości: do 50 pc jasna z poświatą, 50–300 pc jasny punkt, 300–1000 pc słaby punkt.
  const ODCIENIE = [[255, 244, 225], [210, 225, 255], [255, 225, 180]];
  function rysujSasiednieGwiazdy() {
    const k = kierunki();
    const lista = stan.widok?.gwiazdy;
    if (!k || !lista?.length) return;
    for (const g of lista) {
      if (Math.abs(norm180(g.k - k.kurs)) > POLE_WIDZENIA / 2 + 2) continue;
      const x = xKierunku(k.kurs, g.k), y = yWidoku(0.42 - g.w * 0.3);
      const [r, gr, b] = g.rgb || ODCIENIE[g.o] || ODCIENIE[0];
      // Odległość pozorna (jasny olbrzym z daleka świeci jak bliska gwiazda).
      const d = g.de ?? g.d;
      const blisko = d < 50, srednio = d < 300;
      const promien = blisko ? 2.6 : srednio ? 1.7 : 1.1;
      const alfa = blisko ? 1 : srednio ? 0.9 : Math.max(0.2, 0.55 - (d - 300) / 2000);
      if (blisko) {
        const po = ctx.createRadialGradient(x, y, 0, x, y, 18);
        po.addColorStop(0, `rgba(${r}, ${gr}, ${b}, 0.55)`); po.addColorStop(1, `rgba(${r}, ${gr}, ${b}, 0)`);
        ctx.fillStyle = po; ctx.fillRect(x - 18, y - 18, 36, 36);
      }
      ctx.fillStyle = `rgba(${r}, ${gr}, ${b}, ${alfa})`;
      ctx.beginPath(); ctx.arc(x, y, promien, 0, Math.PI * 2); ctx.fill();
      zbierz(x, y, `r:${g.n || g.t || g.k}`, { g });
    }
  }

  // Zapamiętanie gwiazdy do zaznaczania; zaznaczona dostaje celownik.
  function zbierz(x, y, klucz, dane) {
    zbierane.push({ x: x + kadrX, y: y + kadrYTeraz, klucz, ...dane });
    if (klucz !== zaznaczona) return;
    ctx.save(); ctx.strokeStyle = 'rgba(77, 226, 201, 0.95)'; ctx.lineWidth = 1.2; ctx.beginPath();
    ctx.arc(x, y, 9, 0, Math.PI * 2);
    for (const [dx, dy] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) { ctx.moveTo(x + dx * 12, y + dy * 12); ctx.lineTo(x + dx * 17, y + dy * 17); }
    ctx.stroke(); ctx.restore();
  }

  // Słońca układu (`cykl.gwiazdy`): kolor, wielkość tarczy i łuna według typu gwiazdy;
  // w układach wielokrotnych towarzysze świecą obok głównej gwiazdy.
  function rysujSlonce() {
    const k = kierunki();
    if (k?.slonce == null) return;
    const gwiazdy = stan.widok?.cykl?.gwiazdy?.length ? stan.widok.cykl.gwiazdy : [{ rgb: [255, 244, 225], rozmiar: 1, jasnosc: 1, przesuniecie: 0, wysokosc: 0 }];
    for (const gw of gwiazdy) {
      const kier = k.slonce + (gw.przesuniecie || 0);
      if (Math.abs(norm180(kier - k.kurs)) > POLE_WIDZENIA / 2 + 15) continue;
      const [cr, cg, cb] = gw.rgb;
      // Tarcza w prawdziwej wielkości kątowej (promień gwiazdy i odległość: z punktu układu albo orbita
      // planety); łuna słabnie z odległością (jasność pozorna ∝ √L / d, przy planecie w ekosferze = 1).
      const dKm = stan.widok?.slonce?.km ?? (stan.widok?.cykl?.promien_orbity_au || 1) * KM_NA_AU_SCENA;
      const rKm = gw.promien_km || 695700 * gw.rozmiar;
      const r = Math.max(1.2, rozmiarKatowy(2 * rKm, dKm) * w / POLE_WIDZENIA / 2);
      const blask = Math.max(0.12, Math.min(1.6, Math.sqrt(gw.moc || 1) / (dKm / KM_NA_AU_SCENA)));
      const x = xKierunku(k.kurs, kier), y = yWidoku(0.3 + (gw.wysokosc || 0));
      const R = Math.max(r * 3, skalaWidoku() * 0.022 * 14 * gw.jasnosc * blask);
      const g = ctx.createRadialGradient(x, y, 0, x, y, R);
      g.addColorStop(0, 'rgba(255, 253, 248, 1)'); g.addColorStop(0.07, `rgba(${cr}, ${cg}, ${cb}, 0.9)`);
      g.addColorStop(0.25, `rgba(${cr}, ${cg}, ${cb}, 0.18)`); g.addColorStop(1, `rgba(${cr}, ${cg}, ${cb}, 0)`);
      ctx.fillStyle = g; ctx.fillRect(x - R, y - R, R * 2, R * 2);
      ctx.fillStyle = `rgb(${Math.min(255, cr + 30)}, ${Math.min(255, cg + 30)}, ${Math.min(255, cb + 30)})`;
      ctx.beginPath(); ctx.arc(x, y, r, 0, Math.PI * 2); ctx.fill();
    }
  }

  // Czy słońce układu jest teraz w kadrze (przycisk „Zdjęcie” na pulpicie): na mostku — w polu
  // widzenia okna; na planecie — w dzień. Serwer sprawdza to samo przy zapisie zdjęcia.
  function slonceWidoczne() {
    if (['orbita', 'wyjscie', 'kosmos'].includes(stan.scena)) {
      const k = kierunki();
      if (!stan.widok) return stan.scena === 'orbita';
      if (k?.slonce == null) return false;
      return Math.abs(norm180(k.slonce - k.kurs)) <= POLE_WIDZENIA / 2 * Math.min(1, ekranW / Math.max(1, w)) + 5;
    }
    if (stan.scena === 'powierzchnia') return !!stan.swiatlo?.cykl && poraTeraz() === 'dzien';
    return false;
  }

  // Oświetlona część tarczy: od strony słońca, z terminatorem jako półelipsą (faza jak u Księżyca).
  function sciezkaSwiatla(cx, cy, R, k) {
    const RAD = Math.PI / 180;
    const faza = Math.cos((k.slonce - k.orbita) * RAD);             // 1 = pełnia (słońce za okrętem)
    const strona = Math.cos((k.slonce - (k.doPlanety - 90)) * RAD) >= 0 ? 1 : -1; // 1: światło z prawej
    const p = new Path2D();
    p.arc(cx, cy, R, -Math.PI / 2, Math.PI / 2, strona < 0);
    for (let i = 0; i <= 24; i++) {
      const th = Math.PI / 2 - (Math.PI * i) / 24;
      p.lineTo(cx + strona * R * faza * Math.cos(th) * -1, cy + R * Math.sin(th));
    }
    p.closePath();
    return p;
  }

  function rysujPlanete(t, pojawienie = 1) {
    przygotujPlanete();
    const { d, dt, tekstura, swiatla, typ } = planeta;
    const r = d / 2;
    // Położenie: duża planeta wchodzi od dołu ekranu, mała wisi z boku. Z mostka: tam, gdzie
    // wskazuje kierunek na planetę względem dziobu (za plecami — nie widać jej wcale).
    const blisko = stan.odleglosc === 'blisko';
    const k = kierunki();
    let cx = blisko ? w * 0.62 : w * 0.68;
    if (k) {
      if (Math.abs(norm180(k.doPlanety - k.kurs)) > POLE_WIDZENIA / 2 + 40) return;
      cx = xKierunku(k.kurs, k.doPlanety);
    }
    // Z okna mostka planeta na wprost wisi dokładnie w środku okna (0,46 = środek pasa 0,08–0,84).
    const cy = blisko ? yWidoku(0.98) : yWidoku(stan.okno ? 0.46 : 0.55);
    const skala = 0.85 + 0.15 * pojawienie;
    const R = r * skala;
    if (cx + R * 1.3 < 0 || cx - R * 1.3 > w) return;
    const info = TYPY_PLANET[typ];
    ctx.save();
    ctx.globalAlpha = pojawienie;
    // poświata atmosfery (z krawędzi układu szersza: mała tarcza ma być widać także od nocnej strony)
    const zasieg = stan.odleglosc === 'krawedz' ? 2.4 : 1.25;
    const glow = ctx.createRadialGradient(cx, cy, R * 0.9, cx, cy, R * zasieg);
    glow.addColorStop(0, info.atmosfera + 'aa');
    glow.addColorStop(1, info.atmosfera + '00');
    ctx.fillStyle = glow;
    ctx.beginPath();
    ctx.arc(cx, cy, R * zasieg, 0, Math.PI * 2);
    ctx.fill();
    // powierzchnia (przewijana = obrót)
    ctx.beginPath();
    ctx.arc(cx, cy, R, 0, Math.PI * 2);
    ctx.clip();
    const przes = ((t * 0.004 * (spokojnie ? 0 : 1)) % dt);
    ctx.drawImage(tekstura, przes, 0, dt, dt, cx - R, cy - R, R * 2, R * 2);
    const ciemna = k?.slonce != null ? (() => {
      // Z mostka: nocna strona tam, gdzie nie dociera słońce (faza zależna od miejsca na orbicie).
      const p = new Path2D();
      p.arc(cx, cy, R, 0, Math.PI * 2);
      p.addPath(sciezkaSwiatla(cx, cy, R, k));
      return p;
    })() : null;
    if (ciemna) {
      ctx.fillStyle = 'rgba(0,0,0,0.93)';
      ctx.fill(ciemna, 'evenodd');
      if (swiatla) {
        ctx.save();
        ctx.clip(ciemna, 'evenodd');
        ctx.globalCompositeOperation = 'lighter';
        ctx.drawImage(swiatla, przes, 0, dt, dt, cx - R, cy - R, R * 2, R * 2);
        ctx.restore();
      }
    } else {
    // cień nocnej strony (światło z lewej góry)
    const cien = ctx.createRadialGradient(cx - R * 0.45, cy - R * 0.4, R * 0.15, cx - R * 0.2, cy - R * 0.2, R * 1.45);
    cien.addColorStop(0, 'rgba(0,0,0,0)');
    cien.addColorStop(0.45, 'rgba(0,0,0,0.25)');
    cien.addColorStop(0.75, 'rgba(0,0,0,0.75)');
    cien.addColorStop(1, 'rgba(0,0,0,0.97)');
    ctx.fillStyle = cien;
    ctx.fillRect(cx - R, cy - R, R * 2, R * 2);
    }
    if (swiatla && !ciemna) {
      // Światła miast tylko po nocnej stronie: rysujemy je na osobnym płótnie
      // i wygaszamy maską jaśniejszą od strony słońca.
      const p = document.createElement('canvas');
      p.width = p.height = Math.max(2, Math.ceil(R * 2));
      const g = p.getContext('2d');
      g.drawImage(swiatla, przes, 0, d, d, 0, 0, p.width, p.height);
      const maska = g.createRadialGradient(R * 0.55, R * 0.6, R * 0.2, R * 0.8, R * 0.8, R * 1.5);
      maska.addColorStop(0, 'rgba(0,0,0,0)');
      maska.addColorStop(0.55, 'rgba(0,0,0,0.3)');
      maska.addColorStop(1, 'rgba(0,0,0,1)');
      g.globalCompositeOperation = 'destination-in';
      g.fillStyle = maska;
      g.fillRect(0, 0, p.width, p.height);
      ctx.globalCompositeOperation = 'lighter';
      ctx.drawImage(p, cx - R, cy - R);
      ctx.globalCompositeOperation = 'source-over';
    }
    // krawędź atmosfery
    ctx.restore();
    ctx.save();
    ctx.globalAlpha = pojawienie * 0.6;
    ctx.strokeStyle = info.atmosfera;
    ctx.lineWidth = Math.max(1, R * 0.02);
    ctx.beginPath();
    ctx.arc(cx, cy, R, Math.PI * 0.75, Math.PI * 1.6);
    ctx.stroke();
    ctx.restore();
  }

  function blysk(alfa) {
    if (alfa <= 0) return;
    ctx.fillStyle = `rgba(235, 245, 255, ${Math.min(1, alfa)})`;
    ctx.fillRect(0, 0, w, h);
  }

  // ---------- widok z powierzchni ----------

  // Pora dnia: z cyklu planety i współrzędnych lokacji (`stan.swiatlo`, js/rdzen/astro.js);
  // bez tych danych według zegara tabletu (21:00–6:00). `stan.pora` może ją wymusić.
  const poraTeraz = () => {
    if (stan.pora) return stan.pora;
    const sw = stan.swiatlo;
    if (sw?.cykl) return poraAstro(sw.cykl, sw.szer, sw.dl, (Date.now() + (stan.przesuniecie || 0)) / 1000);
    const g = new Date().getHours();
    return g >= 21 || g < 6 ? 'noc' : 'dzien';
  };
  const noc = () => poraTeraz() === 'noc';
  const zmierzch = () => poraTeraz() === 'zmierzch';

  // Teren jako statyczny obraz (liczony raz na rozmiar / planetę / porę dnia).
  function przygotujKrajobraz() {
    const typ = POWIERZCHNIE[stan.planeta] ? stan.planeta : 'skalista';
    const klucz = `${typ}|${w}x${h}|${poraTeraz()}|${stan.miejsce || ''}`;
    if (krajobraz?.klucz === klucz) return;
    const p = POWIERZCHNIE[typ];
    const c = document.createElement('canvas');
    c.width = Math.max(1, w * dpr);
    c.height = Math.max(1, h * dpr);
    const g = c.getContext('2d');
    g.scale(dpr, dpr);
    const los = losowe(hash(typ + (stan.miejsce || '')));
    const ciemno = noc() ? 0.72 : zmierzch() ? 0.4 : 0;
    const swiatla = [];
    const horyzont = h * 0.58;

    if (p.styl === 'pionowe' && (w < 20 || h < 20)) {
      // Ukryte albo zwinięte tło (np. aplikacja na pełnym ekranie): nie budujemy miasta,
      // bo krok wież liczony z szerokości byłby zerowy i pętle nigdy by się nie skończyły.
      krajobraz = { klucz, plotno: c, swiatla, pionowe: true };
      return;
    }
    if (p.styl === 'pionowe') {
      // Pionowe miasto: warstwy wież od dalekich (jasnych od mgły) do bliskich (ciemnych, sięgających
      // ponad kadr), kładki między wieżami, okna i neony; nocą łuna miasta od dołu.
      const mgla = noc() ? '#2a2630' : zmierzch() ? '#4a4048' : '#8a8c92';
      const NEONY = ['#ff4d6d', '#4de2c9', '#ffb347', '#9d7bff'];
      p.teren.forEach((kolor, i) => {
        const glebia = i / (p.teren.length - 1);
        const baza = mieszaj(kolor, mgla, (1 - glebia) * 0.55);
        g.fillStyle = noc() ? mieszaj(baza, '#05070d', ciemno * (0.4 + glebia * 0.5)) : baza;
        const krok = Math.max(8, w * (0.035 + glebia * 0.05));
        for (let x = -krok * los(); x < w + krok; x += krok * (0.7 + los() * 0.9)) {
          const sz = krok * (0.55 + los() * 0.6);
          const gora = glebia > 0.6 ? -h * 0.1 : h * (0.08 + (1 - glebia) * 0.25 * los());
          g.fillRect(x, gora, sz, h - gora);
          // iglica albo antena
          if (los() < 0.35) g.fillRect(x + sz * 0.45, gora - h * 0.05 * (1 - glebia), Math.max(1, sz * 0.08), h * 0.05 * (1 - glebia));
          // okna: pionowe pasy świateł
          for (let kol = x + 3; kol < x + sz - 3; kol += 4 + glebia * 4) {
            if (los() < 0.45) continue;
            for (let wy = Math.max(gora + 6, 0); wy < h; wy += 6 + glebia * 6) {
              if (los() < (noc() ? 0.3 : zmierzch() ? 0.2 : 0.06)) swiatla.push([kol, wy]);
            }
          }
          // neony na bliższych wieżach
          if (glebia > 0.3 && los() < 0.35) {
            const ny = gora + (h - gora) * (0.2 + los() * 0.5), kn = NEONY[Math.floor(los() * NEONY.length)];
            g.save(); g.shadowColor = kn; g.shadowBlur = 12; g.fillStyle = kn;
            g.fillRect(x + sz * 0.15, ny, sz * 0.7, Math.max(2, h * 0.006)); g.restore();
            g.fillStyle = noc() ? mieszaj(baza, '#05070d', ciemno * (0.4 + glebia * 0.5)) : baza;
          }
        }
        // kładki i pomosty między wieżami
        if (i === 1 || i === 2) {
          for (let k = 0; k < 4; k++) {
            const y = h * (0.35 + los() * 0.4), x0 = los() * w * 0.6;
            g.fillRect(x0, y, w * (0.15 + los() * 0.35), Math.max(2, h * 0.008 * (1 + glebia)));
          }
        }
        // mgła między warstwami
        const fg = g.createLinearGradient(0, 0, 0, h);
        fg.addColorStop(0, 'rgba(0,0,0,0)'); fg.addColorStop(1, noc() ? 'rgba(80, 40, 60, 0.18)' : 'rgba(160, 160, 170, 0.12)');
        g.fillStyle = fg; g.fillRect(0, 0, w, h);
      });
      // lądowisko na pierwszym planie i barierka
      g.fillStyle = noc() ? '#0b0c10' : '#2a2c31';
      g.fillRect(0, h * 0.86, w, h * 0.14);
      g.fillStyle = noc() ? '#16181d' : '#3a3d44';
      for (let x = 0; x < w; x += Math.max(8, w * 0.06)) g.fillRect(x, h * 0.8, 3, h * 0.06);
      g.fillRect(0, h * 0.8, w, 3);
      krajobraz = { klucz, plotno: c, swiatla, pionowe: true };
      return;
    }

    p.teren.forEach((kolor, i) => {
      const glebia = i / (p.teren.length - 1); // 0 daleko, 1 blisko
      const baza = horyzont + glebia * h * 0.16;
      const amp = h * (0.03 + glebia * 0.05);
      const faza = [los() * 10, los() * 10, los() * 10];
      const yAt = (x) => {
        const u = x / w;
        switch (p.styl) {
          case 'wydmy': return baza - amp * (0.6 * Math.sin(u * 5 + faza[0]) + 0.4 * Math.sin(u * 11 + faza[1]));
          case 'szczyty': case 'wulkany': {
            const tr = (v) => 1 - Math.abs(((v % 2) + 2) % 2 - 1) * 2;
            return baza - amp * 1.6 * (0.6 * tr(u * 3 + faza[0]) + 0.3 * tr(u * 7 + faza[1]) + 0.1 * tr(u * 19 + faza[2]));
          }
          case 'plaskowyze': return baza - amp * 1.4 * (Math.sin(u * 4 + faza[0]) > 0.2 ? 1 : 0.15) - amp * 0.1 * Math.sin(u * 30);
          case 'morze': return i === 0 ? baza - amp * Math.max(0, Math.sin(u * 6 + faza[0])) * 1.2 : baza + (i - 1) * h * 0.06;
          case 'chmury': return baza - amp * 0.5 * Math.sin(u * 9 + faza[0] + i);
          case 'bagno': return baza - amp * 0.25 * Math.sin(u * 6 + faza[0]);
          default: return baza - amp * (0.5 * Math.sin(u * 4 + faza[0]) + 0.3 * Math.sin(u * 9 + faza[1]) + 0.2 * Math.sin(u * 23 + faza[2]));
        }
      };
      g.fillStyle = noc() ? mieszaj(kolor, '#05070d', ciemno) : kolor;
      g.beginPath();
      g.moveTo(0, h);
      for (let x = 0; x <= w; x += 4) g.lineTo(x, yAt(x));
      g.lineTo(w, h);
      g.closePath();
      g.fill();

      // Szczegóły terenu.
      if (p.styl === 'las' && i > 0) {
        for (let x = 0; x < w; x += 6 + los() * 10) {
          const y = yAt(x), wys = (8 + los() * 22) * (0.6 + glebia);
          g.beginPath(); g.moveTo(x - wys * 0.25, y + 2); g.lineTo(x, y - wys); g.lineTo(x + wys * 0.25, y + 2); g.fill();
        }
      }
      if (p.styl === 'bagno' && i === 2) {
        g.strokeStyle = g.fillStyle; g.lineWidth = 2;
        for (let k = 0; k < 9; k++) {
          const x = los() * w, y = yAt(x), wys = 30 + los() * 60;
          g.beginPath(); g.moveTo(x, y); g.lineTo(x + (los() - 0.5) * 8, y - wys); g.lineTo(x + 14 * (los() - 0.5), y - wys * 1.15); g.stroke();
        }
      }
      if (p.styl === 'wulkany' && i < 2) {
        g.save(); g.globalCompositeOperation = 'lighter';
        for (let k = 0; k < 14; k++) {
          const x = los() * w, y = yAt(x);
          g.strokeStyle = `rgba(255, ${80 + los() * 80 | 0}, 30, ${0.35 + los() * 0.4})`; g.lineWidth = 1 + los() * 2;
          g.beginPath(); g.moveTo(x, y); g.lineTo(x + (los() - 0.5) * 30, y + 20 + los() * 60); g.stroke();
        }
        g.restore();
      }
      if (p.styl === 'miasto') {
        // Wieże: im bliżej, tym wyższe; światła okien zapamiętujemy do migotania.
        for (let x = -10; x < w; x += 18 + los() * 40) {
          const sz = 14 + los() * 30 * (1 + glebia), wys = (40 + los() * 160) * (0.5 + glebia * 0.8);
          const y0 = yAt(x) - wys;
          g.fillRect(x, y0, sz, wys + h);
          if (los() < 0.3) g.fillRect(x + sz / 2 - 1, y0 - 18, 2, 18);
          for (let wy = y0 + 6; wy < yAt(x) - 4; wy += 7) {
            for (let wx = x + 3; wx < x + sz - 3; wx += 5) {
              if (los() < (noc() ? 0.35 : zmierzch() ? 0.2 : 0.08)) swiatla.push([wx, wy]);
            }
          }
        }
      }
      if (p.styl === 'chmury' && i === 0) {
        // Miasto w chmurach: platforma na horyzoncie.
        const x = w * (0.25 + los() * 0.4), y = yAt(x) - h * 0.05;
        g.fillStyle = noc() ? '#1b1c22' : '#8a6f5a';
        g.beginPath(); g.ellipse(x, y, w * 0.06, h * 0.012, 0, 0, Math.PI * 2); g.fill();
        g.fillRect(x - w * 0.02, y - h * 0.05, w * 0.04, h * 0.05);
        g.fillRect(x - 2, y, 4, h * 0.08);
      }
      if (p.styl === 'morze' && i === 1) {
        g.strokeStyle = noc() ? 'rgba(160,190,255,0.08)' : 'rgba(255,255,255,0.18)'; g.lineWidth = 1;
        for (let k = 0; k < 60; k++) {
          const x = los() * w, y = yAt(x) + los() * h * 0.3;
          g.beginPath(); g.moveTo(x, y); g.lineTo(x + 10 + los() * 30, y); g.stroke();
        }
      }
    });
    krajobraz = { klucz, plotno: c, swiatla };
  }

  function rysujNiebo(typ, ciemnosc = 0) {
    const p = POWIERZCHNIE[typ] || POWIERZCHNIE.skalista;
    const d = Math.max(ciemnosc, noc() ? 0.82 : zmierzch() ? 0.5 : 0);
    const g = ctx.createLinearGradient(0, 0, 0, h * 0.62);
    g.addColorStop(0, mieszaj(p.niebo[0], '#020309', d));
    g.addColorStop(1, mieszaj(p.niebo[1], '#0a0d18', d * 0.9));
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, w, h);
    return d;
  }

  function rysujChmury(dt, typ, predkosc, alfa, gora = 0, dol = 0.55) {
    const kolor = (TYPY_PLANET[typ] || TYPY_PLANET.skalista).atmosfera;
    if (chmury.length < 40) {
      chmury = Array.from({ length: 40 }, () => ({ x: Math.random() * w, y: gora * h + Math.random() * (dol - gora) * h, r: 40 + Math.random() * 140, s: 0.4 + Math.random() }));
    }
    ctx.save();
    for (const c of chmury) {
      c.x += c.s * predkosc * dt;
      if (c.x - c.r * 2 > w) { c.x = -c.r * 2; c.y = gora * h + Math.random() * (dol - gora) * h; }
      const g = ctx.createRadialGradient(c.x, c.y, 0, c.x, c.y, c.r);
      g.addColorStop(0, kolor + Math.round(alfa * 120).toString(16).padStart(2, '0'));
      g.addColorStop(1, kolor + '00');
      ctx.fillStyle = g;
      ctx.beginPath();
      ctx.ellipse(c.x, c.y, c.r * 2, c.r * 0.6, 0, 0, Math.PI * 2);
      ctx.fill();
    }
    ctx.restore();
  }

  // Planeta macierzysta na niebie księżyca (np. Nal Hutta nad Nar Shaddaa), z fazą od słońca.
  function rysujMacierz() {
    const cykl = stan.swiatlo?.cykl, m = cykl?.macierz;
    if (!m) return;
    const t = (Date.now() + (stan.przesuniecie || 0)) / 1000;
    const u = stanUkladu(cykl, t);
    const katKsiezyca = (m.faza + 360 * (t - cykl.epoka) / 86400 / m.okres_dni) % 360; // kierunek planeta → księżyc
    const info = TYPY_PLANET[m.typ] || TYPY_PLANET.skalista;
    // Rozmiar z kąta: średnica planety macierzystej i odległość środek–środek (promień obiegu księżyca).
    const R = Math.max(2, rozmiarKatowy(Number(m.srednica_km) || SREDNICA_DOMYSLNA_KM, Number(m.promien_km) || 400000) * w / POLE_WIDZENIA / 2);
    const cx = w * 0.3, cy = h * 0.2;
    ctx.save();
    ctx.globalAlpha = noc() ? 0.95 : 0.75;
    ctx.fillStyle = info.kolory[0];
    ctx.beginPath(); ctx.arc(cx, cy, R, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = info.kolory[1];
    for (let i = 0; i < 5; i++) { ctx.globalAlpha *= 0.9; ctx.beginPath(); ctx.ellipse(cx - R * 0.3 + i * R * 0.15, cy - R * 0.4 + i * R * 0.2, R * 0.5, R * 0.08, 0.2, 0, Math.PI * 2); ctx.fill(); }
    ctx.restore();
    // cień: faza z kąta słońce–planeta–księżyc (obserwator na księżycu)
    const k = { slonce: u.katSlonca, orbita: katKsiezyca, doPlanety: katKsiezyca + 180 };
    const ciemna = new Path2D();
    ciemna.arc(cx, cy, R, 0, Math.PI * 2);
    ciemna.addPath(sciezkaSwiatla(cx, cy, R, k));
    ctx.fillStyle = 'rgba(5, 7, 12, 0.88)';
    ctx.fill(ciemna, 'evenodd');
  }

  // Przelatujące światła pojazdów nad pionowym miastem.
  function rysujRuchMiasta(t) {
    const los = losowe(hash(stan.miejsce || 'miasto'));
    for (let i = 0; i < 14; i++) {
      const y = h * (0.15 + los() * 0.6), v = (0.02 + los() * 0.05) * (los() < 0.5 ? 1 : -1), faza = los();
      const x = ((((t * v / 1000 + faza) % 1) + 1) % 1) * (w + 40) - 20;
      ctx.fillStyle = los() < 0.5 ? 'rgba(255, 230, 190, 0.85)' : 'rgba(255, 80, 90, 0.8)';
      ctx.fillRect(x, y, 3, 1.5);
    }
  }

  function rysujPowierzchnie(t, dt) {
    const typ = POWIERZCHNIE[stan.planeta] ? stan.planeta : 'skalista';
    const p = POWIERZCHNIE[typ];
    const d = rysujNiebo(typ);
    rysujMacierz();
    if (d > 0.5) rysujGwiazdy(0.000004, dt, d - 0.3, 0.05);
    else {
      // słońce
      const sx = w * 0.78, sy = h * 0.2;
      const rgb = stan.swiatlo?.cykl?.gwiazdy?.[0]?.rgb;
      const kolorSlonca = rgb ? '#' + rgb.map((v) => v.toString(16).padStart(2, '0')).join('') : p.slonce;
      const g = ctx.createRadialGradient(sx, sy, 0, sx, sy, h * 0.25);
      g.addColorStop(0, kolorSlonca); g.addColorStop(0.08, kolorSlonca + 'cc'); g.addColorStop(1, kolorSlonca + '00');
      ctx.fillStyle = g; ctx.fillRect(0, 0, w, h);
    }
    rysujChmury(dt, typ, 0.012, d > 0.5 ? 0.25 : 0.7, 0.05, 0.45);
    przygotujKrajobraz();
    ctx.drawImage(krajobraz.plotno, 0, 0, w, h);
    if (krajobraz.pionowe) rysujRuchMiasta(t);
    // migoczące światła miasta
    if (krajobraz.swiatla.length) {
      ctx.fillStyle = noc() ? 'rgba(255, 214, 140, 0.9)' : 'rgba(255, 240, 200, 0.35)';
      const krok = Math.floor(t / 900);
      krajobraz.swiatla.forEach(([x, y], i) => { if ((i * 7 + krok) % 23) ctx.fillRect(x, y, 2, 3); });
    }
    if (p.styl === 'bagno' || p.styl === 'chmury') {
      const g = ctx.createLinearGradient(0, h * 0.55, 0, h);
      g.addColorStop(0, 'rgba(220, 230, 210, 0)'); g.addColorStop(1, `rgba(220, 230, 210, ${noc() ? 0.08 : 0.3})`);
      ctx.fillStyle = g; ctx.fillRect(0, 0, w, h);
    }
    rysujRame(stan.wnetrze);
  }

  // Rama okna (biuro, hotel…) albo wrota hangaru, na kwadratowym wzorze. Na zewnątrz — bez ramy.
  function rysujRame(wnetrze) {
    const okno = OKNA_BUDYNKOW.includes(wnetrze);
    const hangar = HANGARY.includes(wnetrze);
    if (!okno && !hangar) return;
    ctx.save();
    ctx.fillStyle = '#0a0e12';
    if (okno) {
      // Okno z trzema szybami i ślemieniem na środku wzoru; dookoła ściana w kolorze ramy.
      const L = w * 0.1, R = w * 0.9, T = h * 0.26, B = h * 0.74;
      const r = Math.max(10, w * 0.018);
      ctx.fillRect(0, 0, w, T + r); ctx.fillRect(0, B - r * 1.6, w, h - B + r * 1.6);
      ctx.fillRect(0, T, L + r, B - T); ctx.fillRect(R - r, T, w - R + r, B - T);
      ctx.fillRect(w / 3 - r / 4, T, r / 2, B - T); ctx.fillRect((2 * w) / 3 - r / 4, T, r / 2, B - T);
      ctx.fillRect(L, T + (B - T) * 0.25, R - L, r / 2.5);
      // krawędź ramy i odbicie w szybie
      ctx.strokeStyle = 'rgba(120, 130, 140, 0.25)'; ctx.lineWidth = 1.5;
      ctx.strokeRect(L + r, T + r, R - L - 2 * r, B - T - r * 2.6);
      const g = ctx.createLinearGradient(L, T, R, B);
      g.addColorStop(0, 'rgba(255,255,255,0.06)'); g.addColorStop(0.4, 'rgba(255,255,255,0)'); g.addColorStop(0.6, 'rgba(255,255,255,0.04)'); g.addColorStop(1, 'rgba(255,255,255,0)');
      ctx.fillStyle = g; ctx.fillRect(L + r, T + r, R - L - 2 * r, B - T - r * 2.6);
    } else {
      // Wrota hangaru: cały środek to widok; strop z lampami u góry, posadzka z pasami u dołu.
      const gora = h * 0.32, podloga = h * 0.7;
      ctx.fillRect(0, 0, w, gora);
      ctx.fillStyle = '#1a2128'; ctx.fillRect(0, gora - 8, w, 8);
      ctx.fillStyle = '#14191e'; ctx.fillRect(0, podloga, w, h - podloga);
      for (let x = 0; x < w; x += 28) {
        ctx.fillStyle = (x / 28) % 2 < 1 ? '#c9a227' : '#14191e';
        ctx.fillRect(x, podloga, 14, 6);
      }
      for (let x = w * 0.04; x < w; x += Math.max(40, w * 0.08)) {
        ctx.fillStyle = '#2a3a44'; ctx.fillRect(x, gora - 6, 4, 6);
        const g = ctx.createRadialGradient(x + 2, gora, 0, x + 2, gora, 40);
        g.addColorStop(0, 'rgba(255,240,200,0.25)'); g.addColorStop(1, 'rgba(255,240,200,0)');
        ctx.fillStyle = g; ctx.fillRect(x - 40, gora, 84, 50);
      }
    }
    ctx.restore();
  }

  // Wznoszenie: chmury pędzą w dół (lecimy w górę), niebo ciemnieje, potem gwiazdy i planeta pod nami.
  function rysujStart(dt) {
    const typ = POWIERZCHNIE[stan.planeta] ? stan.planeta : 'skalista';
    const t = Date.now() - stan.zmienionoLokalnie;
    const f = t / CZAS_STARTU;
    rysujNiebo(typ, 0.25 + 0.75 * gladko(0.55, 1.25, f));
    const gwiazdy = gladko(0.9, 1.4, f);
    if (gwiazdy > 0) rysujGwiazdy(0.00001, dt, gwiazdy, 0.05);
    const alfaChmur = 1 - gladko(0.85, 1.2, f);
    if (alfaChmur > 0) {
      // Chmury w kolorze atmosfery: jaśniejsze szczyty, ciemniejszy spód, prześwity nieba między nimi.
      const atm = (TYPY_PLANET[typ] || TYPY_PLANET.skalista).atmosfera;
      const jasna = mieszaj(atm, '#ffffff', 0.45), ciemna = mieszaj(atm, '#202838', 0.35);
      if (!chmury.length || chmury[0].pion === undefined) {
        chmury = Array.from({ length: 34 }, () => ({ x: Math.random() * w, y: Math.random() * h * 1.4 - h * 0.2, r: 70 + Math.random() * 190, s: 0.5 + Math.random(), pion: true }));
      }
      const szybkosc = 0.32 * (1 - gladko(0.7, 1.2, f) * 0.8);
      ctx.save();
      ctx.globalAlpha = alfaChmur;
      for (const c of chmury) {
        c.y += c.s * szybkosc * dt;
        if (c.y - c.r > h) { c.y = -c.r * 1.2; c.x = Math.random() * w; }
        // cień spodu
        let g = ctx.createRadialGradient(c.x, c.y + c.r * 0.25, 0, c.x, c.y + c.r * 0.25, c.r * 1.1);
        g.addColorStop(0, ciemna.replace('rgb', 'rgba').replace(')', ',0.55)'));
        g.addColorStop(1, ciemna.replace('rgb', 'rgba').replace(')', ',0)'));
        ctx.fillStyle = g;
        ctx.beginPath(); ctx.ellipse(c.x, c.y + c.r * 0.25, c.r * 1.7, c.r * 0.8, 0, 0, Math.PI * 2); ctx.fill();
        // jasna bryła
        g = ctx.createRadialGradient(c.x - c.r * 0.2, c.y - c.r * 0.15, 0, c.x, c.y, c.r);
        g.addColorStop(0, jasna.replace('rgb', 'rgba').replace(')', ',0.95)'));
        g.addColorStop(0.55, jasna.replace('rgb', 'rgba').replace(')', ',0.7)'));
        g.addColorStop(1, jasna.replace('rgb', 'rgba').replace(')', ',0)'));
        ctx.fillStyle = g;
        ctx.beginPath(); ctx.ellipse(c.x, c.y, c.r * 1.6, c.r * 0.9, 0, 0, Math.PI * 2); ctx.fill();
      }
      ctx.restore();
    }
    // Planety nie widać: przy wznoszeniu jest za rufą. Zostaje czarne niebo i gwiazdy.
  }

  // ---------- pętla ----------

  let poprzednio = performance.now();
  function rysuj(teraz) {
    klatka = requestAnimationFrame(rysuj);
    const dt = Math.min(50, teraz - poprzednio);
    poprzednio = teraz;
    const t = teraz - start; // czas od wejścia w bieżącą scenę
    const ruch = spokojnie ? 0.15 : 1;
    // Scena z ramą: rysujemy na kwadratowym wzorze o boku dłuższego wymiaru ekranu, wyśrodkowanym —
    // ekran pokazuje jego środek. Bez ramy: cały ekran.
    // Prom w hangarze okrętu: kosmos przez wrota hangaru (wnetrze „hangar” poza powierzchnią).
    const zHangaru = !stan.okno && stan.scena !== 'powierzchnia' && stan.scena !== 'start' && HANGARY.includes(stan.wnetrze);
    const zRama = OKNA_Z_RAMA.includes(stan.okno) || zHangaru
      || (stan.scena === 'powierzchnia' && (OKNA_BUDYNKOW.includes(stan.wnetrze) || HANGARY.includes(stan.wnetrze)));
    const bok = zRama ? Math.max(ekranW, ekranH) : 0;
    w = zRama ? bok : ekranW; h = zRama ? bok : ekranH;
    kadrX = zRama ? (ekranW - bok) / 2 : 0;
    const kadrY = zRama ? (ekranH - bok) / 2 : 0;
    kadrYTeraz = kadrY;
    narysowane = zbierane; zbierane = [];
    ctx.setTransform(dpr, 0, 0, dpr, kadrX * dpr, kadrY * dpr);

    // Pomieszczenie okrętu bez okien: tylko wnętrze (przestrzeń i skok niewidoczne).
    if (stan.okno === 'brak' && stan.scena !== 'powierzchnia' && stan.scena !== 'start') { rysujWnetrze(t); return; }
    const zBoku = stan.okno === 'iluminator';

    switch (stan.scena) {
      case 'wejscie': {
        // 0–70%: gwiazdy przyspieszają i rozciągają się; 70–80%: błysk; potem tunel.
        const f = t / CZAS_WEJSCIA;
        if (f < 0.72) {
          tlo();
          const k = f / 0.72;
          if (stan.widok) {
            // Widok sprzed skoku gaśnie płynnie: niebo i planeta szybciej, słońce z łuną odrobinę wolniej,
            // wszystko znika, zanim smugi zgęstnieją.
            const zanik = Math.max(0, 1 - k * 1.6), zanikSlonca = Math.max(0, 1 - k * 1.3);
            ctx.save();
            ctx.globalAlpha = zanik;
            if (zanik > 0) { rysujPanorame(t, false); rysujSasiednieGwiazdy(); }
            ctx.globalAlpha = zanikSlonca;
            if (zanikSlonca > 0) rysujSlonce();
            ctx.restore();
            if (zanik > 0 && !stan.widok.bez_planety) rysujPlanete(t, zanik);
          }
          if (zBoku) rysujGwiazdyBok((0.000002 + Math.pow(k, 3) * 0.004) * ruch, dt, 1, 0.1 + k * 2);
          else rysujGwiazdy((0.00004 + Math.pow(k, 3) * 0.004) * ruch, dt, 1, 0.2 + k * 1.5);
        } else if (f < 1) {
          if (zBoku) rysujTunelBok(t, dt, (f - 0.72) / 0.28); else rysujTunel(t, dt, (f - 0.72) / 0.28);
          blysk(1 - (f - 0.72) / 0.28);
        } else if (zBoku) {
          rysujTunelBok(t, dt);
        } else {
          rysujTunel(t, dt);
        }
        break;
      }
      case 'nadswietlna':
        if (zBoku) rysujTunelBok(t, dt * ruch); else rysujTunel(t, dt * ruch);
        break;
      case 'wyjscie': {
        // 0–15%: błysk ze smug; 15–60%: smugi zwalniają; potem pojawia się planeta.
        const f = t / CZAS_WYJSCIA;
        tlo();
        const k = Math.min(1, f / 0.6);
        if (zBoku) rysujGwiazdyBok((0.004 * Math.pow(1 - k, 2.2) + 0.000002) * ruch, dt, 1, 2 * (1 - k) + 0.05);
        else rysujGwiazdy((0.004 * Math.pow(1 - k, 2.2) + 0.00003) * ruch, dt, 1, 1.6 * (1 - k) + 0.05);
        if (f > 0.45) {
          // Słońce i gwiazdy sąsiednich układów wyłaniają się razem z planetą, gdy smugi gasną.
          ctx.save(); ctx.globalAlpha = Math.min(1, (f - 0.45) / 0.55);
          rysujSasiednieGwiazdy(); rysujSlonce();
          ctx.restore();
        }
        if (f > 0.45) rysujPlanete(t, Math.min(1, (f - 0.45) / 0.55));
        blysk(f < 0.15 ? 1 - f / 0.15 : 0);
        break;
      }
      case 'orbita':
        if (!rysujPanorame(t)) { tlo(); rysujGwiazdy(0.000012 * ruch, dt, 0.9, 0.05); }
        rysujSasiednieGwiazdy();
        rysujSlonce();
        rysujPlanete(t, 1);
        break;
      case 'powierzchnia':
        rysujPowierzchnie(t, dt * ruch);
        break;
      case 'start':
        rysujStart(dt * ruch);
        break;
      default:
        if (!rysujPanorame(t)) { tlo(); rysujGwiazdy(0.00003 * ruch, dt, 1, 0.05); }
        rysujSasiednieGwiazdy();
    }
    if (zHangaru) rysujRame(stan.wnetrze);
    if (OKNA_Z_RAMA.includes(stan.okno)) {
      if (!oknoIsd || oknoIsd.w !== w || oknoIsd.h !== h || oknoIsd.typ !== stan.okno) oknoIsd = { w, h, typ: stan.okno, plotno: rysujOknoIsd(w, h, dpr, stan.okno) };
      ctx.drawImage(oknoIsd.plotno, 0, 0, w, h);
    }
  }

  const obserwator = new ResizeObserver(() => rozmiar());
  obserwator.observe(canvas);
  rozmiar();
  noweGwiazdy();

  return {
    // Nowy stan z serwera. Animacje przejść grają tylko, jeśli zmiana jest świeża;
    // tablet włączony później od razu pokazuje stan końcowy.
    // `przesuniecie` = zegar serwera − zegar tabletu (ms), żeby liczyć czas od zmiany jak serwer.
    ustaw(nowy) {
      const zmiana = nowy.scena !== stan.scena || nowy.planeta !== stan.planeta || nowy.odleglosc !== stan.odleglosc
        || nowy.zmieniono !== stan.zmieniono || nowy.wnetrze !== stan.wnetrze || nowy.miejsce !== stan.miejsce;
      if (!zmiana) {
        // Ten sam stan, nowy widok z mostka (np. trwa manewr) albo światło: bez restartu animacji.
        stan.widok = nowy.widok; stan.swiatlo = nowy.swiatlo; stan.okno = nowy.okno; stan.burta = nowy.burta; stan.opis = nowy.opis;
        stan.przesuniecie = nowy.przesuniecie;
        return;
      }
      const zmienionoLokalnie = (nowy.zmieniono || 0) - (nowy.przesuniecie || 0);
      const swieza = Date.now() - zmienionoLokalnie < 15000;
      stan = { ...nowy, zmienionoLokalnie };
      chmury = [];
      krajobraz = null;
      if (stan.scena === 'wejscie' && !swieza) stan.scena = 'nadswietlna';
      if (stan.scena === 'wyjscie' && !swieza) stan.scena = 'orbita';
      planeta = null;
      start = performance.now();
    },
    slonceWidoczne,
    // Gwiazda najbliżej punktu ekranu (px względem płótna), niezasłonięta ramą okna; albo null.
    // Gwiazdy układów łapią się z 16 px, drobne gwiazdy tła z 7 px (inaczej każdy klik trafiałby w tło).
    gwiazdaPod(px, py) {
      let naj = null, min = 1;
      for (const s of narysowane) {
        const d = Math.hypot(s.x - px, s.y - py) / (s.g ? 16 : 7);
        if (d < min) { min = d; naj = s; }
      }
      if (naj && oknoIsd && OKNA_Z_RAMA.includes(stan.okno)) {
        // Rama okna: nieprzezroczysty piksel poszycia w tym miejscu → gwiazda zasłonięta.
        try {
          const a = oknoIsd.plotno.getContext('2d').getImageData(Math.round((naj.x - kadrX) * dpr), Math.round((naj.y - kadrYTeraz) * dpr), 1, 1).data[3];
          if (a > 128) return null;
        } catch { /* bez sprawdzenia */ }
      }
      return naj;
    },
    zaznacz(klucz) { zaznaczona = klucz; },
    // Miniatura bieżącego widoku (z oknem mostka) jako JPEG, najwyżej 480 px szerokości.
    zdjecie() {
      const szer = Math.min(480, canvas.width);
      const c = document.createElement('canvas');
      c.width = szer; c.height = Math.round(canvas.height * szer / canvas.width);
      c.getContext('2d').drawImage(canvas, 0, 0, c.width, c.height);
      return c.toDataURL('image/jpeg', 0.72);
    },
    start() {
      if (klatka) return;
      poprzednio = performance.now();
      klatka = requestAnimationFrame(rysuj);
    },
    stop() {
      if (klatka) cancelAnimationFrame(klatka);
      klatka = null;
    },
  };
}

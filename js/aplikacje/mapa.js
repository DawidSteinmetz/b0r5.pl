// Mapa galaktyki: planety, regiony i szlaki nadprzestrzenne. Pokazuje, gdzie jest postać,
// dokąd leci i gdzie stoją statki jej firm. Dane astronawigacyjne są pobierane z SQL
// przez API i buforowane przez service worker do pracy bez zasięgu.

import { h, wyczysc, czasGry } from '../rdzen/narzedzia.js';
import { stanUkladu, stronaOrbity } from '../rdzen/astro.js';
import { panelZgod } from '../rdzen/wskazniki.js';
import { api } from '../rdzen/api.js';

const KOLOR_WYKRYCIA = '#ffb347'; // wykrycia radarowe skoków
const RAD = Math.PI / 180;
// Kolor jednostki z punktu widzenia gracza: sojusznik / wróg / neutralny (serwer: mapa_strony()).
const KOLORY_STRON = { sojusznik: '#3ddc84', wrog: '#ff5a4f', neutralny: '#e8edf2' };
const kolorObiektu = (o) => {
  const k = o?.bitwa?.kolor || KOLORY_STRON[o?.strona] || o?.kolor;
  return /^#[0-9a-f]{6}$/i.test(k || '') ? k : '#00ff66';
};
const teraz = () => Date.now() / 1000;

// Rzeczywista skala: km na parsek i jednostkę astronomiczną, promienie gwiazd według typu (km),
// największe zbliżenie (px na pc) — okręt długości ok. 1,6 km na cały ekran.
const KM_NA_PC = 30856775814913.7;
const KM_NA_AU = 149597870.7;
const PROMIEN_GWIAZDY_KM = { O: 6960000, B: 3480000, A: 1250000, F: 905000, G: 696000, K: 557000, M: 278000, WD: 8400, RG: 69600000 };
const ZOOM_MAKS = 2e16;
// Mieszanie logarytmiczne: schemat (f = 0) → rzeczywista wielkość (f = 1), bez przeskoków.
const mieszajLog = (a, b, f) => (f <= 0 ? a : f >= 1 ? b : Math.exp(Math.log(Math.max(a, 1e-6)) * (1 - f) + Math.log(Math.max(b, 1e-6)) * f));

// Kurs jednostki teraz (stopnie, układ mapy: 0° = wschód, 90° = północ), z trwającym manewrem.
export function kursTeraz(o, t = teraz()) {
  const m = o?.manewr;
  if (m && t < m.do) {
    const p = Math.max(0, Math.min(1, (t - m.od) / Math.max(1, m.do - m.od)));
    const r = ((m.cel - m.start + 540) % 360) - 180;
    return (m.start + r * p + 360) % 360;
  }
  if (m) return m.cel;
  return o?.kurs ?? 90;
}

// Jednostka jest w nadprzestrzeni dopiero po manewrze (wejście w skok).
const wNadprzestrzeni = (o, t = teraz()) => !!o?.lot && t >= (o.lot.wejscie || 0);

// Sylwetka Kom'rka z góry (dziób = dwa wysunięte kły, okrągły kadłub, skrzydła), obrócona według kursu.
const KOMRK = [[1, 0.1], [0.25, 0.14], [0.55, 0.72], [0.05, 0.62], [-0.45, 0.55], [-0.7, 0.28], [-0.72, 0], [-0.7, -0.28], [-0.45, -0.55],
  [0.05, -0.62], [0.55, -0.72], [0.25, -0.14], [1, -0.1], [0.3, 0]];
function sylwetkaKomrk(g, x, y, kurs, r = 10) {
  const a = -kurs * RAD, c = Math.cos(a), s = Math.sin(a);
  KOMRK.forEach(([px, py], i) => {
    const X = x + (px * c - py * s) * r, Y = y + (px * s + py * c) * r;
    if (i) g.lineTo(X, Y); else g.moveTo(X, Y);
  });
  g.closePath();
}

// Strzałka (dziób w kierunku kursu); ekran ma oś y w dół, stąd minus przy kącie.
function strzalka(g, x, y, kurs, r = 8) {
  const a = -kurs * RAD;
  g.moveTo(x + Math.cos(a) * r, y + Math.sin(a) * r);
  g.lineTo(x + Math.cos(a + 2.45) * r * 0.85, y + Math.sin(a + 2.45) * r * 0.85);
  g.lineTo(x + Math.cos(a + Math.PI) * r * 0.3, y + Math.sin(a + Math.PI) * r * 0.3);
  g.lineTo(x + Math.cos(a - 2.45) * r * 0.85, y + Math.sin(a - 2.45) * r * 0.85);
  g.closePath();
}

const REGIONY = ['Głębokie Jądro', 'Światy Jądra', 'Kolonie', 'Wewnętrzne Rubieże', 'Obszar Ekspansji',
  'Środkowe Rubieże', 'Zewnętrzne Rubieże', 'Przestrzeń Huttów', 'Nieznane Regiony', 'Dzika Przestrzeń'];

// Nazwy do porównań: bez wielkości liter, ogonków, spacji i znaków przestankowych.
export const norm = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/gi, 'l')
  .toLowerCase().replace(/[^a-z0-9]/g, '');

const liczba = (n, miejsca = 0) => Number(n).toLocaleString('pl-PL', { maximumFractionDigits: miejsca });

// ---------- dane (wczytane raz na uruchomienie systemu) ----------

let dane = null;
async function wczytajDane() {
  if (dane) return dane;
  const { planety: surowePlanety, szlaki: suroweSzlaki } = await api('mapa_dane');
  // [nazwa, region, x, y, grawitacja, słońca, księżyce, doba, rok, średnica]. Kolumny DECIMAL przychodzą
  // z bazy jako tekst — zamiana na liczby (inaczej „+” skleja tekst i kamera dostaje NaN).
  const planety = surowePlanety.map(([nazwa, r, x, y, g, slonca, ksiezyce, doba, rok, srednica]) => ({
    nazwa, region: REGIONY[r] || '', x: +x, y: +y, g: +g, slonca: +slonca, ksiezyce: +ksiezyce, doba: +doba, rok: +rok,
    srednica: +srednica, klucz: norm(nazwa),
  }));
  const szlaki = suroweSzlaki.map((s) => {
    let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
    for (const p of s.p) {
      for (let i = 0; i < p.length; i += 2) {
        minX = Math.min(minX, p[i]); maxX = Math.max(maxX, p[i]);
        minY = Math.min(minY, p[i + 1]); maxY = Math.max(maxY, p[i + 1]);
      }
    }
    return { nazwa: s.n || 'Szlak bez nazwy', z: s.z, dl: s.dl, sciezki: s.p, ramka: { minX, maxX, minY, maxY } };
  });
  // Środki regionów (do podpisów): średnia pozycja planet regionu.
  const regiony = REGIONY.map((nazwa) => {
    const w = planety.filter((p) => p.region === nazwa);
    return { nazwa, x: w.reduce((s, p) => s + p.x, 0) / (w.length || 1), y: w.reduce((s, p) => s + p.y, 0) / (w.length || 1), ile: w.length };
  }).filter((r) => r.ile);
  const wgKlucza = new Map(planety.map((p) => [p.klucz, p]));
  dane = { planety, szlaki, regiony, wgKlucza };
  return dane;
}

// ---------- aplikacja ----------

export default {
  id: 'mapa',
  nazwa: 'Mapa galaktyki',
  ikona: '<circle cx="24" cy="24" r="3"/><path d="M24 24c6-10 18-6 16 2s-14 12-22 8-8-16 2-20 16 0 16 6"/><circle cx="12" cy="13" r="1.5"/><circle cx="37" cy="36" r="1.5"/>',

  uruchom(w, ctx) {
    w.classList.add('widok-mapa');
    const plotno = h('canvas.mapa-plotno');
    const warstwa = document.createElement('canvas'); // statyczna część mapy, przerysowywana tylko po ruchu
    const szukaj = h('input.mapa-szukaj', { placeholder: 'Szukaj planety lub szlaku', spellcheck: 'false', autocorrect: 'off', autocomplete: 'off', enterkeyhint: 'search' });
    const wyniki = h('ul.mapa-wyniki', { hidden: true });
    const karta = h('div.mapa-karta', { hidden: true });
    const podpowiedz = h('div.mapa-hover', { hidden: true });
    const wsp = h('div.mapa-wsp');
    const ladowanie = h('div.mapa-ladowanie', 'Pobieranie danych astronawigacyjnych...');
    const panelFlot = h('section.mapa-floty-panel', { hidden: true });
    const panelRadar = h('section.mapa-radar', { hidden: true });
    const przyciskSzlaki = h('button.przycisk.maly.drugi', { onclick: () => { szlakiWidoczne = !szlakiWidoczne; przyciskSzlaki.classList.toggle('wl', szlakiWidoczne); odswiez(); } }, 'Szlaki');
    przyciskSzlaki.classList.add('wl');
    const przyciskTu = h('button.przycisk.maly.drugi', { onclick: () => {
      const ja = mojZnacznik();
      if (ja) przelec(ja.x, ja.y, Math.max(skala, 0.6), () => wybierz({ obiekt: ja }));
      else if (tuPlaneta) przelec(tuPlaneta.x, tuPlaneta.y, 0.6, () => wybierz({ planeta: tuPlaneta }));
    } }, 'Moja pozycja');
    const przyciskCala = h('button.przycisk.maly.drugi', { onclick: () => calaGalaktyka(true) }, 'Cała galaktyka');
    const przyciskFloty = h('button.przycisk.maly.drugi', { onclick: () => { panelFlot.hidden = !panelFlot.hidden; if (!panelFlot.hidden) odswiezFloty(); } }, 'Floty / nawigacja');

    w.append(plotno,
      h('div.mapa-gora', h('div.mapa-szukajka', szukaj, wyniki), h('div.mapa-przyciski', przyciskTu, przyciskCala, przyciskSzlaki, przyciskFloty)),
      panelFlot, panelRadar, karta, podpowiedz, wsp, ladowanie);

    const c = plotno.getContext('2d');
    const cw = warstwa.getContext('2d');

    // Kolory i krój z motywu systemu (krój zmienia się razem z alfabetem).
    let kolory = {};
    let kroj = 'monospace';
    const czytajMotyw = () => {
      const s = getComputedStyle(document.documentElement);
      const v = (n) => s.getPropertyValue(n).trim();
      kolory = { tlo: v('--tlo'), linia: v('--linia'), tekst: v('--tekst'), slaby: v('--tekst-slaby'), akcent: v('--akcent'), akcent2: v('--akcent-2'), zph: v('--zph') };
      kroj = getComputedStyle(plotno).fontFamily || 'monospace';
    };
    czytajMotyw();

    // Kamera: środek widoku w parsekach (camX, camY) plus przesunięcie w kilometrach (camKx, camKy).
    // W rzeczywistej skali (do ~10¹⁶ px/pc) sama liczba w parsekach traciłaby dokładność i obraz by skakał.
    // Ekran x = środek + ((X − camX)·km/pc + kx − camKx)·px/km; północ galaktyki u góry.
    let skala = 0.03, camX = 0, camY = 0, camKx = 0, camKy = 0, szer = 0, wys = 0, dpr = 1;
    let D = null;
    // Planety tego widoku: baza mapy plus planety z katalogu MG, które ta postać może widzieć
    // (także zastrzeżone). Dodatkowe żyją tylko w tej instancji, nie we wspólnej pamięci modułu.
    let P = [];
    let wgKlucza = new Map();
    let dodatkowe = [];
    let odciskDodatkowych = '';
    let szlakiWidoczne = true;
    let wybrane = null;     // { planeta } albo { szlak }
    let najechane = null;
    let pozycja = null;     // odpowiedź api('mapa')
    let tuPlaneta = null, celPlaneta = null;
    let statkiNaMapie = []; // [{ planeta, statki: [...] }]
    let obiektyMapy = []; // Jednostki i postacie udostępnione temu kontu; MG otrzymuje pełną listę.
    let bitwyRadar = [];
    let mojeFloty = [];
    let brudna = true;
    let ramka = 0;
    let animacja = null;

    const pxNaKm = () => skala / KM_NA_PC;
    const naEkran = (x, y, kx = 0, ky = 0) => {
      const k = skala / KM_NA_PC;
      return [szer / 2 + ((x - camX) * KM_NA_PC + (kx - camKx)) * k, wys / 2 - ((y - camY) * KM_NA_PC + (ky - camKy)) * k];
    };
    const naMape = (sx, sy) => {
      const k = skala / KM_NA_PC;
      return [camX + ((sx - szer / 2) / k + camKx) / KM_NA_PC, camY + (-(sy - wys / 2) / k + camKy) / KM_NA_PC];
    };
    const ustawSrodek = (x, y) => { camX = +x; camY = +y; camKx = 0; camKy = 0; };
    let sledz = null; // { poz: () => [x, y] na ekranie, ekran: [x, y] — gdzie ma zostać }
    // Przesunięcie w km przenoszone do parseków, gdy to bezpieczne (dokładność parseków ≪ piksel).
    const scalKamere = () => {
      if (Math.max(Math.abs(camX), Math.abs(camY), 1) * 2.3e-16 * skala < 0.001) {
        camX += camKx / KM_NA_PC; camY += camKy / KM_NA_PC; camKx = 0; camKy = 0;
      }
    };
    const odswiez = () => { brudna = true; };

    function planetaObiektu(o) {
      return o?.planeta ? wgKlucza.get(norm(o.planeta)) || null : null;
    }

    // Postać na pokładzie statku nie ma własnego znacznika: kto jest na statku, widać w karcie statku.
    const naPokladzie = (o) => o?.typ === 'postac' && !!o.na_pokladzie_id;

    function obiektyNaPlanecie(p) {
      return obiektyMapy.filter((o) => norm(o.planeta || '') === p.klucz && !naPokladzie(o));
    }

    // Moja pozycja: własna postać albo statek, na którego pokładzie jestem.
    function mojZnacznik() {
      const ja = obiektyMapy.find((o) => o.ja);
      if (!ja) return null;
      return (ja.na_pokladzie_id && obiektyMapy.find((x) => x.id === ja.na_pokladzie_id)) || ja;
    }

    // Lot podświetlny w toku (po obrocie na kurs): postęp 0–1, poza lotem null.
    function postepLotu(o, t = teraz()) {
      const l = o?.podswietlny;
      if (!l || t < l.od || t >= l.do) return null;
      return (t - l.od) / Math.max(1, l.do - l.od);
    }

    function nazwaPostaci(o) {
      if (o.typ !== 'postac') return o.nazwa;
      const stopien = String(o.status || '').trim().split(/\s+/).filter(Boolean).at(-1) || '';
      const rangi = new Set(['moff', 'kapitan', 'admiral', 'general', 'major', 'komandor', 'porucznik', 'polkownik', 'dowodca']);
      return rangi.has(norm(stopien)) && !norm(o.nazwa).startsWith(norm(stopien)) ? `${stopien} ${o.nazwa}` : o.nazwa;
    }

    // Przy oddaleniu flota na orbicie to jedna strzałka z liczbą okrętów; po przybliżeniu wachlarz.
    const PROG_ROZWINIECIA = 3;
    // Od tego przybliżenia (px na pc) mapa pobiera cykle planet i rysuje układy — zawsze w prawdziwej
    // skali: słońce w miejscu kropki, planeta na prawdziwej orbicie (ekosfera), księżyce, brzeg układu.
    // Ciała mniejsze niż kilka pikseli są kropkami minimalnej wielkości. Układ widać, gdy jego brzeg
    // ma na ekranie co najmniej BRZEG_MIN_PX.
    const PROG_UKLADU = 2e4;
    const BRZEG_MIN_PX = 14;
    const PLANETA_MIN_PX = 3, SLONCE_MIN_PX = 4;
    // Strefa układu (pc), jak STREFA_UKLADU_PC na serwerze: te same współrzędne mapy = ten sam układ.
    const STREFA_UKLADU_PC = 0.05;
    // Planeta, w której układzie leży punkt mapy: przy widoku układu — w obrębie jego brzegu (z zapasem).
    function ukladWPunkcie(mx, my) {
      let naj = null, min = 1;
      for (const p of P) {
        const d = Math.hypot(p.x - mx, p.y - my);
        if (d > 0.05) continue; // cykl pobieramy tylko dla układów blisko punktu
        const u = skala >= PROG_UKLADU ? ukladNaEkranie(p) : null;
        const zasieg = u && !u.ksiezyc ? Math.max(STREFA_UKLADU_PC, (u.Rbrzeg * 1.5) / skala) : STREFA_UKLADU_PC;
        if (d / zasieg < min) { min = d / zasieg; naj = p; }
      }
      return naj;
    }
    // Punkt układu pod kursorem: km od słońca (osie mapy) i odległość od słońca w j.a.
    function punktUkladu(sx, sy, p) {
      const u = ukladNaEkranie(p);
      if (!u || u.ksiezyc) return null;
      const kx = (sx - u.sx) / u.skm, ky = -(sy - u.sy) / u.skm;
      return { k: [Math.round(kx), Math.round(ky)], au: Math.hypot(kx, ky) / KM_NA_AU, wBrzegu: Math.hypot(kx, ky) <= u.Rbrzeg / u.skm * 1.5 };
    }
    const cykle = new Map(); // nazwa planety → cykl (api ?a=uklad) albo null w trakcie pobierania
    function cyklPlanety(p) {
      if (!cykle.has(p.nazwa)) {
        cykle.set(p.nazwa, null);
        api('uklad', undefined, { planeta: p.nazwa }).then((d) => { cykle.set(p.nazwa, d.cykl); odswiez(); }).catch(() => {});
      }
      return cykle.get(p.nazwa);
    }
    // Układ na ekranie w prawdziwej skali: orbita z promien_orbity_au (ekosfera gwiazdy), średnice
    // z danych planet, brzeg układu z krawedz_au. f = 1 (dawne mieszanie schematu ze skalą — wyłączone).
    function ukladNaEkranie(p) {
      if (skala < PROG_UKLADU) return null;
      const cykl = cyklPlanety(p);
      if (!cykl) return null;
      const skm = skala / KM_NA_PC;
      const Rbrzeg = (cykl.krawedz_au || 50) * KM_NA_AU * skm;
      if (Rbrzeg < BRZEG_MIN_PX) return null;
      if (cykl.macierz) {
        // Księżyc: miejsce na orbicie planety macierzystej (tam, gdzie rysuje go jej układ).
        const rodzic = wgKlucza.get(norm(cykl.macierz.nazwa));
        const pu = rodzic && rodzic !== p ? ukladNaEkranie(rodzic) : null;
        if (!pu) return null;
        const i = Math.max(0, pu.u.ksiezyce.findIndex((k) => norm(k.nazwa) === p.klucz));
        const k = pu.u.ksiezyce[i];
        if (!k) return null;
        const rk = (k.promien_km || cykl.macierz.promien_km || 400000) * skm, a = -k.kat * RAD;
        const srednicaKm = cykl.srednica_km || k.srednica_km || 3000;
        const rp = Math.max(PLANETA_MIN_PX - 1, srednicaKm / 2 * skm);
        return { ...pu, ksiezyc: true, cykl, rpS: rp, srednicaKm, rp, px: pu.px + Math.cos(a) * rk, py: pu.py + Math.sin(a) * rk };
      }
      const u = stanUkladu(cykl);
      const [sx, sy] = naEkran(p.x, p.y);
      const R = (cykl.promien_orbity_au || 1) * KM_NA_AU * skm, a = -u.katPlanety * RAD;
      const srednicaKm = cykl.srednica_km || 12000;
      const rp = Math.max(PLANETA_MIN_PX, srednicaKm / 2 * skm);
      return { R, Rs: R, Rbrzeg, f: 1, skm, rpS: rp, srednicaKm, rp, sx, sy, px: sx + Math.cos(a) * R, py: sy + Math.sin(a) * R, u, cykl };
    }
    const pozycjaPlanety = (p) => { const u = ukladNaEkranie(p); return u ? [u.px, u.py] : naEkran(p.x, p.y); };

    function rysujUklady() {
      if (skala < PROG_UKLADU) return;
      const [cxp, cyp] = naMape(szer / 2, wys / 2);
      const zasieg = 2 * Math.max(szer, wys);
      // Na ekranie (z zapasem); przy rzeczywistej skali ciała daleko poza ekranem pomijamy (i ogromne okręgi).
      const widac = (x, y, r) => x + r > -20 && x - r < szer + 20 && y + r > -20 && y - r < wys + 20;
      const OKRAG_MAKS = 2e5;
      for (const p of P) {
        const [sx0, sy0] = naEkran(p.x, p.y);
        // układ blisko ekranu albo kamera w jego wnętrzu (rzeczywista skala: słońce daleko poza ekranem)
        if (Math.hypot(sx0 - szer / 2, sy0 - wys / 2) > zasieg && Math.hypot(p.x - cxp, p.y - cyp) > 3e-3) continue;
        const u = ukladNaEkranie(p);
        if (!u || u.ksiezyc) continue; // księżyc rysuje układ planety macierzystej
        const { R, rp, sx, sy, px, py, skm } = u;
        // brzeg całego układu (przerywany okrąg) — tam kończy się skok „krawędź układu”
        if (u.Rbrzeg < OKRAG_MAKS) {
          c.save(); c.strokeStyle = 'rgba(160, 200, 220, 0.18)'; c.setLineDash([3, 7]); c.lineWidth = 1;
          c.beginPath(); c.arc(sx, sy, u.Rbrzeg, 0, Math.PI * 2); c.stroke(); c.restore();
          if (u.Rbrzeg < Math.max(szer, wys) * 2) { c.font = `10px ${kroj}`; c.fillStyle = kolory.slaby; napis(c, 'krawędź układu', sx + u.Rbrzeg * 0.71 + 4, sy - u.Rbrzeg * 0.71); }
        }
        // słońca układu w prawdziwej wielkości (z promienia gwiazdy), najmniej kilka pikseli; łuna dookoła
        (u.cykl.gwiazdy?.length ? u.cykl.gwiazdy : [{ rgb: [255, 244, 225], rozmiar: 1, typ: 'G' }]).forEach((gw, i) => {
          const [cr, cg, cb] = gw.rgb;
          const rReal = (gw.promien_km || PROMIEN_GWIAZDY_KM[gw.typ] || 696000) * skm;
          const rs = Math.max(SLONCE_MIN_PX * Math.sqrt(gw.rozmiar || 1), rReal);
          const odstep = i ? Math.max(rs * 3, rReal * 8) : 0;
          const gx = sx + Math.cos(i * 2.4) * odstep, gy = sy + Math.sin(i * 2.4) * odstep;
          const luna = Math.max(rs * 1.6, rs + 6);
          if (!widac(gx, gy, luna) || rs > 1e7) return;
          const blask = c.createRadialGradient(gx, gy, 0, gx, gy, luna);
          blask.addColorStop(0, `rgba(${cr}, ${cg}, ${cb}, 0.35)`); blask.addColorStop(1, `rgba(${cr}, ${cg}, ${cb}, 0)`);
          c.fillStyle = blask; c.beginPath(); c.arc(gx, gy, luna, 0, Math.PI * 2); c.fill();
          const tarcza = c.createRadialGradient(gx, gy, 0, gx, gy, rs);
          tarcza.addColorStop(0, 'rgba(255, 252, 245, 1)'); tarcza.addColorStop(1, `rgba(${cr}, ${cg}, ${cb}, 0.95)`);
          c.fillStyle = tarcza; c.beginPath(); c.arc(gx, gy, rs, 0, Math.PI * 2); c.fill();
        });
        if (R < OKRAG_MAKS) { c.strokeStyle = 'rgba(160, 200, 220, 0.22)'; c.lineWidth = 1; c.beginPath(); c.arc(sx, sy, R, 0, Math.PI * 2); c.stroke(); }
        // księżyce: prawdziwe promienie obiegu i średnice (kropki najmniej 2 px)
        u.u.ksiezyce.forEach((k) => {
          const rk = (k.promien_km || 400000) * skm, a = -k.kat * RAD;
          const mr = Math.max(PLANETA_MIN_PX - 1, (k.srednica_km || u.srednicaKm * 0.27) / 2 * skm);
          if (rk < rp + mr + 2) return; // przy oddaleniu księżyc schowany w kropce planety
          const mx = px + Math.cos(a) * rk, my = py + Math.sin(a) * rk;
          if (rk < OKRAG_MAKS) { c.strokeStyle = 'rgba(160, 190, 220, 0.14)'; c.beginPath(); c.arc(px, py, rk, 0, Math.PI * 2); c.stroke(); }
          if (!widac(mx, my, mr)) return;
          c.fillStyle = '#9fb2c4'; c.beginPath(); c.arc(mx, my, mr, 0, Math.PI * 2); c.fill();
          if (rk > 30) { c.font = `11px ${kroj}`; c.fillStyle = kolory.slaby; napis(c, k.nazwa, mx + mr + 4, my - mr - 2); }
        });
        // planeta: oświetlona połowa od strony słońca
        if (!widac(px, py, rp)) continue;
        const as = -u.u.katSlonca * RAD;
        c.fillStyle = '#1b2433'; c.beginPath(); c.arc(px, py, rp, 0, Math.PI * 2); c.fill();
        c.fillStyle = '#6fa8d8'; c.beginPath(); c.arc(px, py, rp, as - Math.PI / 2, as + Math.PI / 2); c.closePath(); c.fill();
        c.font = `11px ${kroj}`; c.fillStyle = kolory.tekst;
        napis(c, p.nazwa, px + Math.min(rp, szer) * 0.71 + 5, py - Math.min(rp, wys) * 0.71 - 3);
      }
    }
    // Połowa długości okrętu na ekranie (px), gdy przerasta znacznik; inaczej null.
    function rozmiarNaEkranie(o) {
      if (!o?.dlugosc_m || o.typ === 'postac') return null;
      const r = (o.dlugosc_m / 2000) * (skala / KM_NA_PC);
      return r > 8 ? r : null;
    }

    function grupaZwinieta(o) {
      if (skala >= PROG_ROZWINIECIA || !o?.flota || o.typ === 'postac') return null;
      const p = planetaObiektu(o);
      if (!p || wNadprzestrzeni(o) || postepLotu(o) != null) return null;
      const czlonkowie = obiektyMapy.filter((x) => x.flota?.kod === o.flota.kod && x.typ !== 'postac'
        && planetaObiektu(x) === p && !wNadprzestrzeni(x) && postepLotu(x) == null);
      return { glowny: czlonkowie[0]?.id === o.id, ile: czlonkowie.length };
    }

    function punktObiektu(o) {
      if (o.typ === 'postac' && o.na_pokladzie_id) {
        const okret = obiektyMapy.find((x) => x.id === o.na_pokladzie_id);
        if (okret) {
          const osobyNaPokladzie = obiektyMapy.filter((x) => x.typ === 'postac' && x.na_pokladzie_id === o.na_pokladzie_id);
          const indeksOsoby = Math.max(0, osobyNaPokladzie.findIndex((x) => x.id === o.id));
          const [sx, sy] = punktObiektu(okret);
          const katOsoby = -Math.PI / 2 + (2 * Math.PI * indeksOsoby / Math.max(osobyNaPokladzie.length, 1));
          return [sx + Math.cos(katOsoby) * 11, sy + Math.sin(katOsoby) * 11];
        }
      }
      const pl = postepLotu(o);
      if (pl != null && o.podswietlny?.k0) {
        // Lot w obrębie układu: po linii w km od słońca.
        const l = o.podswietlny;
        return naEkran(l.x0, l.y0, l.k0[0] + (l.k1[0] - l.k0[0]) * pl, l.k0[1] + (l.k1[1] - l.k0[1]) * pl);
      }
      // Punkt w układzie (np. między planetą a słońcem, brzeg układu): km od słońca.
      if (o.uklad_k && !planetaObiektu(o) && !wNadprzestrzeni(o) && pl == null) return naEkran(o.x, o.y, o.uklad_k[0], o.uklad_k[1]);
      if (pl != null) {
        // Między układami: po linii lotu na mapie; w układzie: między planetą a celem (np. księżycem).
        const l = o.podswietlny;
        if (Math.hypot(l.x1 - l.x0, l.y1 - l.y0) >= 0.05) return naEkran(l.x0 + (l.x1 - l.x0) * pl, l.y0 + (l.y1 - l.y0) * pl);
        const a = planetaObiektu(o), b = l.planeta ? wgKlucza.get(norm(l.planeta)) : null;
        if (a && b && a !== b) {
          const [ax, ay] = pozycjaPlanety(a), [bx, by] = pozycjaPlanety(b);
          return [ax + (bx - ax) * pl, ay + (by - ay) * pl];
        }
      }
      const p = planetaObiektu(o);
      const pozycjaDomyslna = naEkran(o.x, o.y);
      if (!p || wNadprzestrzeni(o)) return pozycjaDomyslna;
      const orbita = !!o.flota || !!o.orbita;
      if (!orbita && o.typ !== 'postac') return pozycjaDomyslna;
      const grupaOrbity = (x) => x.flota?.kod || (x.bitwa ? `bitwa:${x.bitwa.nazwa}` : `orbita:${norm(x.planeta)}`);
      const grupa = grupaOrbity(o);
      const rodzina = obiektyMapy.filter((x) => planetaObiektu(x) === p && !wNadprzestrzeni(x)
        && (orbita ? grupaOrbity(x) === grupa : x.typ === 'postac'));
      const indeks = Math.max(0, rodzina.findIndex((x) => x.id === o.id));
      const grupyOrbit = [...new Set(obiektyMapy.filter((x) => planetaObiektu(x) === p && (x.flota || x.orbita) && !wNadprzestrzeni(x)).map(grupaOrbity))];
      const numerOrbity = orbita ? Math.max(0, grupyOrbit.indexOf(grupa)) : 0;
      const zwinieta = !!grupaZwinieta(o);
      const uklad = ukladNaEkranie(p);
      let promien = uklad ? uklad.rp + 12 + numerOrbity * 11
        : orbita ? (zwinieta ? 18 + numerOrbity * 10 : 26 + numerOrbity * 14) : 30;
      let rozrzutReal = null;
      if (uklad && orbita) {
        // Prawdziwa skala: promień orbity według podejścia (bliska 300 km nad powierzchnią, średnia 4 R,
        // daleka 15 R), ale nie bliżej kropki planety niż kilka pikseli; okręty obok siebie co trzy długości.
        const Rpl = (uklad.srednicaKm || 12000) / 2;
        const km = { bliska: Rpl + 300, srednia: Rpl * 4, daleka: Rpl * 15 }[o.podejscie || 'daleka'] || Rpl * 15;
        const minimum = uklad.rp + 8 + numerOrbity * 7;
        promien = Math.max(minimum, km * uklad.skm);
        rozrzutReal = { f: promien > minimum ? 1 : 0, st: ((3 * Math.max(0.05, (o.dlugosc_m || 100) / 1000)) / km) / RAD };
      }
      const [px, py] = pozycjaPlanety(p);
      if (orbita && (o.orbita != null || o.kurs != null)) {
        // Miejsce na orbicie (orbita_deg): jednostki floty wachlarzem wokół niego; strzałka = kurs dziobu.
        const rozrzutS = zwinieta ? 0 : Math.min(18, 200 / Math.max(rodzina.length, 1));
        const rozrzut = rozrzutReal ? rozrzutS * (1 - rozrzutReal.f) + rozrzutReal.st * rozrzutReal.f : rozrzutS;
        const kat = -((o.orbita ?? kursTeraz(o)) + (indeks - (rodzina.length - 1) / 2) * rozrzut) * RAD;
        return [px + Math.cos(kat) * promien, py + Math.sin(kat) * promien];
      }
      const kat = -Math.PI / 2 + (2 * Math.PI * indeks / Math.max(rodzina.length, 1));
      return [px + Math.cos(kat) * promien, py + Math.sin(kat) * promien];
    }

    function listaObecnych(p) {
      const obecni = obiektyNaPlanecie(p);
      const floty = new Map();
      for (const o of obecni) if (o.flota) {
        const klucz = `${norm(o.flota.nazwa)}:${norm(o.flota.dowodca)}`;
        if (!floty.has(klucz)) floty.set(klucz, o.flota);
      }
      const osoby = obecni.filter((o) => o.typ === 'postac').sort((a, b) => a.nazwa.localeCompare(b.nazwa, 'pl'));
      const jednostki = obecni.filter((o) => ['statek','jednostka'].includes(o.typ));
      return h('div.mapa-obecni',
        floty.size ? [...floty.values()].map((f) => h('div.mapa-obecny',
          h('strong', f.nazwa), h('small', f.dowodca || 'Dowódca nieprzypisany'))) : null,
        osoby.length ? h('div', h('b.mapa-obecni-etykieta', 'Postacie'), osoby.map((o) => h('div.mapa-osoba', nazwaPostaci(o)))) : null,
        jednostki.length ? h('div', h('b.mapa-obecni-etykieta', 'Orbita'), jednostki.map((o) => h('div.mapa-osoba', o.bitwa ? `${o.nazwa} · ${o.bitwa.strona}` : o.nazwa))) : null,
        !floty.size && !osoby.length && !jednostki.length ? h('span.slaby', 'Brak przypisanych postaci i flot') : null);
    }

    function pokazPodpowiedz(cel, sx, sy) {
      if (!cel || cel.szlak) { podpowiedz.hidden = true; return; }
      wyczysc(podpowiedz);
      if (cel.planeta) {
        podpowiedz.append(h('strong.mapa-hover-tytul', cel.planeta.nazwa), listaObecnych(cel.planeta));
      } else if (cel.obiekt) {
        const o = cel.obiekt;
        podpowiedz.append(h('strong.mapa-hover-tytul', o.nazwa),
          o.flota ? h('div', o.flota.nazwa, h('small', `Dowódca: ${o.flota.dowodca || 'nieprzypisany'}`)) : null,
          o.typ === 'postac' ? h('small', nazwaPostaci(o)) : h('small', `${o.klasa || o.rodzaj || o.typ}${o.planeta ? ` · ${o.planeta}` : ''}`));
      }
      podpowiedz.hidden = false;
      const lewo = Math.min(Math.max(8, sx + 16), Math.max(8, szer - Math.min(330, szer - 16) - 8));
      const gora = Math.min(Math.max(68, sy + 14), Math.max(68, wys - 210));
      podpowiedz.style.left = `${lewo}px`;
      podpowiedz.style.top = `${gora}px`;
    }

    function rozmiar() {
      const r = plotno.getBoundingClientRect();
      dpr = Math.min(2, window.devicePixelRatio || 1);
      // Środek widoku (kamera) zostaje przy obrocie ekranu.
      szer = r.width; wys = r.height;
      for (const p of [plotno, warstwa]) { p.width = Math.round(szer * dpr); p.height = Math.round(wys * dpr); }
      odswiez();
    }

    function calaGalaktyka(plynnie = false) {
      if (!D) return;
      let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
      for (const p of D.planety) { minX = Math.min(minX, p.x); maxX = Math.max(maxX, p.x); minY = Math.min(minY, p.y); maxY = Math.max(maxY, p.y); }
      const s = Math.min(szer / ((maxX - minX) * 1.08), wys / ((maxY - minY) * 1.08));
      const cx = (minX + maxX) / 2, cy = (minY + maxY) / 2;
      if (plynnie) return przelec(cx, cy, s);
      skala = s; ustawSrodek(cx, cy);
      odswiez();
    }

    // Płynny przelot do punktu mapy.
    function przelec(x, y, docelowa, potem) {
      sledz = null;
      const [x0, y0] = naMape(szer / 2, wys / 2);
      const s0 = skala;
      const t0 = performance.now();
      animacja = (t) => {
        const p = Math.min(1, Math.max(0, (t - t0) / 700));
        const e = 1 - (1 - p) ** 3;
        // Skala w logarytmie, żeby przybliżanie wyglądało równo; środek widoku sunie do celu.
        skala = Math.exp(Math.log(s0) + (Math.log(docelowa) - Math.log(s0)) * e);
        if (p >= 1) ustawSrodek(x, y);
        else ustawSrodek(x0 + (x - x0) * e, y0 + (y - y0) * e);
        odswiez();
        if (p >= 1) { animacja = null; potem?.(); }
      };
    }

    // ---------- rysowanie ----------

    function rysujWarstwe() {
      const g = cw;
      g.setTransform(dpr, 0, 0, dpr, 0, 0);
      g.fillStyle = kolory.tlo;
      g.fillRect(0, 0, szer, wys);
      rysujSiatke(g);
      rysujRegiony(g);
      if (szlakiWidoczne) rysujSzlaki(g);
      rysujPlanety(g);
    }

    function rysujSiatke(g) {
      // Siatka co 1000 jednostek (przy dużym oddaleniu co 5000).
      const krok = skala * 1000 < 40 ? 5000 : 1000;
      const [x0, y1] = naMape(0, 0);
      const [x1, y0] = naMape(szer, wys);
      g.strokeStyle = kolory.linia;
      g.globalAlpha = 0.35;
      g.lineWidth = 1;
      g.beginPath();
      for (let x = Math.floor(x0 / krok) * krok; x <= x1; x += krok) { const [sx] = naEkran(x, 0); g.moveTo(Math.round(sx) + 0.5, 0); g.lineTo(Math.round(sx) + 0.5, wys); }
      for (let y = Math.floor(y0 / krok) * krok; y <= y1; y += krok) { const [, sy] = naEkran(0, y); g.moveTo(0, Math.round(sy) + 0.5); g.lineTo(szer, Math.round(sy) + 0.5); }
      g.stroke();
      g.globalAlpha = 1;
    }

    function rysujRegiony(g) {
      // Podpisy regionów: wyraźne z daleka, bledną przy zbliżeniu.
      const alfa = Math.max(0, Math.min(0.5, 0.5 - (skala - 0.05) * 2));
      if (alfa <= 0.02) return;
      g.globalAlpha = alfa;
      g.fillStyle = kolory.slaby;
      g.textAlign = 'center';
      g.textBaseline = 'middle';
      g.font = `13px ${kroj}`;
      for (const r of D.regiony) {
        const [sx, sy] = naEkran(r.x, r.y);
        g.fillText(r.nazwa.toUpperCase(), sx, sy);
      }
      g.globalAlpha = 1;
      g.textAlign = 'left';
      g.textBaseline = 'alphabetic';
    }

    function rysujSzlak(g, s) {
      g.beginPath();
      for (const p of s.sciezki) {
        g.moveTo(...naEkran(p[0], p[1]));
        for (let i = 2; i < p.length; i += 2) g.lineTo(...naEkran(p[i], p[i + 1]));
      }
      g.stroke();
    }

    const szlakWidoczny = (s) => !(s.z > 2 && skala < 0.05);

    function rysujSzlaki(g) {
      g.strokeStyle = kolory.akcent;
      g.globalAlpha = 0.38;
      g.lineJoin = 'round';
      for (const s of D.szlaki) {
        if (!szlakWidoczny(s)) continue;
        g.lineWidth = s.z <= 1 ? 1.8 : 1;
        rysujSzlak(g, s);
      }
      g.globalAlpha = 1;
    }

    function rysujPlanety(g) {
      const r = skala > 0.5 ? 3 : skala > 0.1 ? 2 : 1.3;
      g.fillStyle = kolory.tekst;
      g.globalAlpha = skala > 0.1 ? 0.9 : 0.65;
      g.beginPath();
      for (const p of P) {
        const [sx, sy] = naEkran(p.x, p.y);
        if (sx < -10 || sy < -10 || sx > szer + 10 || sy > wys + 10) continue;
        g.moveTo(sx + r, sy);
        g.arc(sx, sy, r, 0, Math.PI * 2);
      }
      g.fill();
      g.globalAlpha = 1;
      // Planety zastrzeżone: romb w kolorze ZPH, nazwa zawsze widoczna.
      g.font = `11px ${kroj}`;
      for (const p of dodatkowe) {
        if (!p.zastrzezona) continue;
        const [sx, sy] = naEkran(p.x, p.y);
        g.fillStyle = kolory.zph;
        g.beginPath();
        g.moveTo(sx, sy - 5); g.lineTo(sx + 5, sy); g.lineTo(sx, sy + 5); g.lineTo(sx - 5, sy);
        g.fill();
        napis(g, p.nazwa, sx + 8, sy + 4);
      }
      // Nazwy: tylko z bliska i bez nakładania się. Zajęte prostokąty trzymamy w kubełkach 100 px.
      if (skala < 0.09) return;
      g.font = `11px ${kroj}`;
      g.fillStyle = kolory.slaby;
      const kubly = new Map();
      const zajety = (x0, y0, x1, y1) => {
        for (let kx = Math.floor(x0 / 100); kx <= Math.floor(x1 / 100); kx++) {
          for (let ky = Math.floor(y0 / 100); ky <= Math.floor(y1 / 100); ky++) {
            for (const b of kubly.get(`${kx},${ky}`) || []) {
              if (x0 < b[2] && x1 > b[0] && y0 < b[3] && y1 > b[1]) return true;
            }
          }
        }
        return false;
      };
      const zajmij = (b) => {
        for (let kx = Math.floor(b[0] / 100); kx <= Math.floor(b[2] / 100); kx++) {
          for (let ky = Math.floor(b[1] / 100); ky <= Math.floor(b[3] / 100); ky++) {
            const k = `${kx},${ky}`;
            if (!kubly.has(k)) kubly.set(k, []);
            kubly.get(k).push(b);
          }
        }
      };
      for (const p of dodatkowe) {
        if (!p.zastrzezona) continue;
        const [sx, sy] = naEkran(p.x, p.y);
        zajmij([sx - 6, sy - 8, sx + 10 + g.measureText(p.nazwa).width, sy + 7]);
      }
      for (const p of P) {
        if (p.zastrzezona) continue;
        const [sx, sy] = naEkran(p.x, p.y);
        if (sx < 0 || sy < 0 || sx > szer || sy > wys) continue;
        const b = [sx - r - 2, sy - 8, sx + r + 6 + g.measureText(p.nazwa).width, sy + 7];
        if (zajety(...b)) continue;
        zajmij(b);
        const tx = sx + r + 7, ty = sy + 4, szerTekstu = g.measureText(p.nazwa).width;
        g.fillStyle = 'rgba(5, 12, 14, 0.84)';
        g.fillRect(tx - 4, ty - 12, szerTekstu + 8, 17);
        g.strokeStyle = 'rgba(77, 226, 201, 0.25)'; g.lineWidth = 0.7;
        g.strokeRect(tx - 4, ty - 12, szerTekstu + 8, 17);
        g.fillStyle = kolory.slaby;
        g.fillText(p.nazwa, tx, ty);
      }
    }

    // Napis z obwódką w kolorze tła, czytelny na szlakach i siatce.
    function napis(g, tekst, x, y) {
      g.save();
      g.strokeStyle = kolory.tlo;
      g.lineWidth = 4;
      g.lineJoin = 'round';
      g.strokeText(tekst, x, y);
      g.restore();
      g.fillText(tekst, x, y);
    }

    function znacznik(p, kolor, tekst, puls) {
      // W widoku układu znacznik stoi przy planecie (na jej orbicie), nie w słońcu.
      const [sx, sy] = pozycjaPlanety(p);
      c.strokeStyle = kolor;
      c.fillStyle = kolor;
      c.lineWidth = 2;
      c.beginPath();
      c.arc(sx, sy, 7, 0, Math.PI * 2);
      c.stroke();
      if (puls !== undefined) {
        c.globalAlpha = 1 - puls;
        c.beginPath();
        c.arc(sx, sy, 7 + puls * 18, 0, Math.PI * 2);
        c.stroke();
        c.globalAlpha = 1;
      }
      if (tekst) {
        c.font = `bold 12px ${kroj}`;
        napis(c, tekst, sx + 12, sy - 12);
      }
    }

    function klatka(t) {
      ramka = requestAnimationFrame(klatka);
      if (animacja) animacja(t);
      if (!D || !warstwa.width || !warstwa.height) return; // np. ekran w trakcie obrotu
      // Śledzenie ciała w widoku układu: planety krążą naprawdę (dziesiątki km/s), więc w rzeczywistej
      // skali kamera leci za ciałem, w które przybliżano, i trzyma je w tym samym miejscu ekranu.
      if (sledz) {
        const p = skala >= PROG_UKLADU ? sledz.poz() : null;
        if (!p) sledz = null;
        else if (Math.abs(p[0] - sledz.ekran[0]) + Math.abs(p[1] - sledz.ekran[1]) > 0.05) {
          camKx += (p[0] - sledz.ekran[0]) / pxNaKm(); camKy -= (p[1] - sledz.ekran[1]) / pxNaKm();
          scalKamere(); odswiez();
        }
      }
      if (brudna) { rysujWarstwe(); brudna = false; }
      c.setTransform(1, 0, 0, 1, 0, 0);
      c.drawImage(warstwa, 0, 0);
      c.setTransform(dpr, 0, 0, dpr, 0, 0);

      // Najechany i wybrany szlak.
      for (const [s, alfa] of [[najechane?.szlak, 0.7], [wybrane?.szlak, 1]]) {
        if (!s) continue;
        c.strokeStyle = kolory.akcent2;
        c.globalAlpha = alfa;
        c.lineWidth = 2.5;
        c.lineJoin = 'round';
        rysujSzlak(c, s);
        c.globalAlpha = 1;
      }
      // Kurs w nadprzestrzeni: linia przerywana z bieżącej pozycji do celu.
      if (tuPlaneta && celPlaneta) {
        const [ax, ay] = naEkran(tuPlaneta.x, tuPlaneta.y);
        const [bx, by] = naEkran(celPlaneta.x, celPlaneta.y);
        c.strokeStyle = kolory.zph;
        c.lineWidth = 1.5;
        c.setLineDash([6, 6]);
        c.lineDashOffset = -t / 60;
        c.beginPath(); c.moveTo(ax, ay); c.lineTo(bx, by); c.stroke();
        c.setLineDash([]);
        znacznik(celPlaneta, kolory.zph, 'Cel skoku');
      }
      for (const { planeta, statki } of statkiNaMapie) {
        if (planeta === tuPlaneta) continue;
        znacznik(planeta, kolory.akcent2, statki.length === 1 ? statki[0].nazwa : `Statki: ${statki.length}`);
      }
      rysujUklady();
      const moj = mojZnacznik();
      for (const o of obiektyMapy) {
        if (naPokladzie(o)) continue;
        const zw = grupaZwinieta(o);
        if (zw && !zw.glowny) continue;
        const [sx, sy] = punktObiektu(o);
        if (sx < -12 || sy < -12 || sx > szer + 12 || sy > wys + 12) continue;
        if (zw && zw.ile > 1) {
          c.save(); c.font = `bold 10px ${kroj}`; c.fillStyle = kolory.tekst;
          napis(c, `${o.flota.grupa || o.flota.nazwa} ×${zw.ile}`, sx + 9, sy + 12);
          c.restore();
        }
        const kolor = kolorObiektu(o);
        let kursRys = kursTeraz(o);
        if (o.lot) {
          const [cx, cy] = naEkran(o.lot.x, o.lot.y);
          c.save(); c.strokeStyle = kolor; c.globalAlpha = 0.65; c.setLineDash([5, 5]); c.beginPath(); c.moveTo(sx, sy); c.lineTo(cx, cy); c.stroke(); c.setLineDash([]); c.restore();
          if (wNadprzestrzeni(o)) kursRys = Math.atan2(-(cy - sy), cx - sx) / RAD;
        }
        if (o.podswietlny && teraz() < o.podswietlny.do) {
          // Lot podświetlny: kropkowana linia do celu.
          const l = o.podswietlny, b = l.planeta ? wgKlucza.get(norm(l.planeta)) : null;
          const [cx, cy] = b && Math.hypot(l.x1 - l.x0, l.y1 - l.y0) < 0.05 ? pozycjaPlanety(b) : naEkran(l.x1, l.y1);
          c.save(); c.strokeStyle = kolor; c.globalAlpha = 0.55; c.setLineDash([2, 4]); c.beginPath(); c.moveTo(sx, sy); c.lineTo(cx, cy); c.stroke(); c.setLineDash([]); c.restore();
        }
        c.save(); c.fillStyle = kolor; c.strokeStyle = kolor; c.lineWidth = 1.7;
        if (o.bitwa) { c.globalAlpha = 0.28 + (Math.sin(t / 180) + 1) * 0.16; c.beginPath(); c.arc(sx, sy, 12 + (t % 900) / 120, 0, Math.PI * 2); c.stroke(); c.globalAlpha = 1; }
        c.beginPath();
        // Rzeczywista długość, gdy okręt na ekranie jest większy od znacznika; inaczej znacznik.
        const rz = rozmiarNaEkranie(o);
        if (o.znacznik === 'trojkat') strzalka(c, sx, sy, kursRys, rz ?? 8);
        else if (o.znacznik === 'komrk') sylwetkaKomrk(c, sx, sy, kursRys, rz ?? 10);
        else if (o.znacznik === 'kwadrat') { const r = rz ?? 5; c.rect(sx - r, sy - r, r * 2, r * 2); }
        else if (o.znacznik === 'romb') { const r = rz ?? 7; c.moveTo(sx, sy - r); c.lineTo(sx + r, sy); c.lineTo(sx, sy + r); c.lineTo(sx - r, sy); c.closePath(); }
        else c.arc(sx, sy, rz ?? 5, 0, Math.PI * 2);
        c.fill();
        if ((najechane?.obiekt?.id === o.id) || (wybrane?.obiekt?.id === o.id)) { c.beginPath(); c.arc(sx, sy, Math.max(10, (rz ?? 0) + 4), 0, Math.PI * 2); c.stroke(); }
        c.restore();
        if (o === moj) {
          // moja postać albo statek, na którym jestem: sam pulsujący pierścień radaru (bez napisu)
          const f = (t % 1800) / 1800;
          c.save(); c.strokeStyle = kolory.akcent; c.globalAlpha = 1 - f; c.lineWidth = 1.5;
          c.beginPath(); c.arc(sx, sy, (rozmiarNaEkranie(o) ?? 0) + 8 + f * 16, 0, Math.PI * 2); c.stroke(); c.restore();
        } else if (najechane?.obiekt?.id === o.id || wybrane?.obiekt?.id === o.id) {
          c.font = 'bold 12px monospace'; c.fillStyle = kolor;
          const label = nazwaPostaci(o);
          napis(c, label, sx + 10, sy - 7);
        }
      }
      // Wykrycia radarowe skoków: kierunek, szacowany punkt wyjścia z marginesem błędu i ETA.
      for (const w of pozycja?.wykrycia || []) {
        const [ax, ay] = naEkran(w.x0, w.y0);
        const [bx, by] = naEkran(w.x, w.y);
        const r = Math.max(4, w.margines * skala);
        c.save(); c.strokeStyle = KOLOR_WYKRYCIA; c.fillStyle = KOLOR_WYKRYCIA; c.lineWidth = 1.2;
        c.globalAlpha = 0.8; c.setLineDash([2, 6]); c.beginPath(); c.moveTo(ax, ay); c.lineTo(bx, by); c.stroke();
        c.setLineDash([]); c.globalAlpha = 0.18; c.beginPath(); c.arc(bx, by, r, 0, Math.PI * 2); c.fill();
        c.globalAlpha = 0.7; c.stroke(); c.restore();
        c.font = '11px monospace'; c.fillStyle = KOLOR_WYKRYCIA;
        const eta = w.eta * 1000 > Date.now() ? `ETA ~${czasGry(w.eta)}` : 'wyjście szacowane';
        napis(c, `Skok · ${w.nazwa} · ${eta}`, bx + Math.min(r, 40) + 6, by + 4);
      }
      if (tuPlaneta && !mojZnacznik()) znacznik(tuPlaneta, kolory.akcent, '', (t % 1800) / 1800);
      if (wybrane?.punkt) {
        // Wybrany punkt mapy: celownik.
        const pk = wybrane.punkt;
        const [sx, sy] = pk.k ? naEkran(pk.x, pk.y, pk.k[0], pk.k[1]) : naEkran(pk.x, pk.y);
        c.save(); c.strokeStyle = kolory.akcent2; c.lineWidth = 1.5; c.beginPath();
        for (const [dx, dy] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) { c.moveTo(sx + dx * 3, sy + dy * 3); c.lineTo(sx + dx * 11, sy + dy * 11); }
        c.stroke(); c.restore();
      }
      for (const [p, alfa] of [[najechane?.planeta, 0.7], [wybrane?.planeta, 1]]) {
        if (!p) continue;
        const [sx, sy] = naEkran(p.x, p.y);
        c.globalAlpha = alfa;
        c.strokeStyle = kolory.akcent2;
        c.lineWidth = 1.5;
        c.strokeRect(sx - 9, sy - 9, 18, 18);
        c.font = `13px ${kroj}`;
        c.fillStyle = kolory.akcent2;
        napis(c, p.nazwa, sx + 13, sy + 18);
        c.globalAlpha = 1;
      }
    }

    // ---------- trafienia ----------

    function trafienie(sx, sy) {
      let najblizszyObiekt = null, odlObiektu = 15;
      for (const obiekt of obiektyMapy) {
        if (naPokladzie(obiekt)) continue;
        const zw = grupaZwinieta(obiekt);
        if (zw && !zw.glowny) continue;
        const [px, py] = punktObiektu(obiekt);
        const d = Math.hypot(px - sx, py - sy) - (rozmiarNaEkranie(obiekt) ?? 0);
        if (d < odlObiektu) { odlObiektu = d; najblizszyObiekt = obiekt; }
      }
      if (najblizszyObiekt) return { obiekt: najblizszyObiekt };
      let najblizsza = null, odl = 16;
      for (const p of P) {
        const rp = skala >= PROG_UKLADU && Math.hypot(...naMape(sx, sy).map((v, i) => v - (i ? p.y : p.x))) < 3e-3 ? ukladNaEkranie(p)?.rp || 0 : 0;
        for (const [px, py] of [naEkran(p.x, p.y), pozycjaPlanety(p)]) {
          const d = Math.hypot(px - sx, py - sy) - rp;
          if (d < odl) { odl = d; najblizsza = p; }
        }
      }
      if (najblizsza) return { planeta: najblizsza };
      if (!szlakiWidoczne) return null;
      const [mx, my] = naMape(sx, sy);
      const tol = 10 / skala;
      for (const s of D.szlaki) {
        if (!szlakWidoczny(s)) continue;
        const b = s.ramka;
        if (mx < b.minX - tol || mx > b.maxX + tol || my < b.minY - tol || my > b.maxY + tol) continue;
        for (const p of s.sciezki) {
          for (let i = 2; i < p.length; i += 2) {
            if (odcinek(mx, my, p[i - 2], p[i - 1], p[i], p[i + 1]) < tol) return { szlak: s };
          }
        }
      }
      return null;
    }

    function odcinek(px, py, x1, y1, x2, y2) {
      const l2 = (x2 - x1) ** 2 + (y2 - y1) ** 2;
      const t = l2 ? Math.max(0, Math.min(1, ((px - x1) * (x2 - x1) + (py - y1) * (y2 - y1)) / l2)) : 0;
      return Math.hypot(px - (x1 + t * (x2 - x1)), py - (y1 + t * (y2 - y1)));
    }

    // ---------- karta wybranego obiektu ----------

    // Widok układu w karcie planety: planeta z dzienną i nocną stroną, kierunek słońca, księżyce
    // na orbitach i jednostki na orbicie ze strzałkami (rufa ku planecie). Liczone na żywo
    // z cyklu planety (api ?a=uklad, js/rdzen/astro.js); w małej ramce: obieg planety wokół słońca.
    // Plan skoku pokazywany przerywanymi liniami w widoku układu karty planety:
    // { planeta, kierunek (°, skąd leci flota → planeta), wariant, reczny, policzony, aktywny }.
    let planSkoku = null;
    let odswiezUkladKarty = null;

    function widokUkladu(p) {
      const W = 300, H = 220;
      const plotnoUkladu = h('canvas.mapa-uklad', { width: W * 2, height: H * 2, style: `width:${W}px;height:${H}px;max-width:100%` });
      const opis = h('div.slaby.maly-tekst');
      const sekcja = h('div.mapa-uklad-sekcja', h('div.slaby.maly-tekst', 'Układ planetarny'), plotnoUkladu, opis);
      let cykl = null;
      const g = plotnoUkladu.getContext('2d');
      g.scale(2, 2);
      const rysuj = () => {
        if (!plotnoUkladu.isConnected) { clearInterval(zegar); return; }
        if (!cykl) return;
        const t = teraz();
        const u = stanUkladu(cykl, t);
        const cx = W * 0.56, cy = H * 0.54, rp = 15;
        const ekr = (kat) => -kat * RAD;
        g.clearRect(0, 0, W, H);
        // kierunek słońca: smuga światła od strony słońca
        const as = ekr(u.katSlonca);
        const [sr, sg, sb] = cykl.gwiazdy?.[0]?.rgb || [255, 214, 120];
        g.save(); g.strokeStyle = `rgba(${sr}, ${sg}, ${sb}, 0.35)`; g.lineWidth = 1;
        for (let i = -3; i <= 3; i++) {
          const dx = Math.cos(as), dy = Math.sin(as), px = -dy * i * 7, py = dx * i * 7;
          g.beginPath(); g.moveTo(cx + dx * 140 + px, cy + dy * 140 + py); g.lineTo(cx + dx * (rp + 6) + px, cy + dy * (rp + 6) + py); g.stroke();
        }
        g.fillStyle = `rgba(${sr}, ${sg}, ${sb}, 0.9)`; g.font = '10px monospace';
        g.fillText('Słońce', Math.max(4, Math.min(W - 44, cx + Math.cos(as) * 120 - 18)), Math.max(12, Math.min(H - 4, cy + Math.sin(as) * 90)));
        g.restore();
        // księżyce: orbity w skali logarytmicznej
        // (kolejność od najbliższego; odległości schematyczne, nie w skali)
        u.ksiezyce.forEach((k, i) => {
          const r = 38 + (Math.min(W, H) / 2 - 48) * (i + 1) / u.ksiezyce.length;
          g.strokeStyle = 'rgba(160, 190, 220, 0.25)'; g.beginPath(); g.arc(cx, cy, r, 0, Math.PI * 2); g.stroke();
          const a = ekr(k.kat), mx = cx + Math.cos(a) * r, my = cy + Math.sin(a) * r;
          g.fillStyle = '#9fb2c4'; g.beginPath(); g.arc(mx, my, 3.5, 0, Math.PI * 2); g.fill();
          g.fillStyle = 'rgba(30, 30, 40, 0.8)'; g.beginPath(); g.arc(mx, my, 3.5, as + Math.PI / 2, as - Math.PI / 2); g.fill();
          g.fillStyle = '#9fb2c4'; g.font = '9px monospace'; g.fillText(k.nazwa, mx + 5, my - 4);
        });
        // planeta: noc i oświetlona połowa od strony słońca
        g.fillStyle = '#1b2433'; g.beginPath(); g.arc(cx, cy, rp, 0, Math.PI * 2); g.fill();
        g.fillStyle = '#6fa8d8'; g.beginPath(); g.arc(cx, cy, rp, as - Math.PI / 2, as + Math.PI / 2); g.closePath(); g.fill();
        // jednostki na orbicie
        const jednostki = obiektyMapy.filter((o) => planetaObiektu(o) === p && ['statek', 'jednostka'].includes(o.typ) && !wNadprzestrzeni(o, t));
        const grupy = new Map();
        for (const o of jednostki) {
          const klucz = o.flota?.kod || `o:${o.id}`;
          if (!grupy.has(klucz)) grupy.set(klucz, []);
          grupy.get(klucz).push(o);
        }
        const linie = [];
        [...grupy.values()].forEach((lista, nr) => {
          lista.forEach((o, i) => {
            const miejsce = (o.orbita ?? kursTeraz(o, t)) + (i - (lista.length - 1) / 2) * Math.min(10, 80 / lista.length);
            const r = rp + 9 + nr * 6, a = ekr(miejsce);
            const kolor = kolorObiektu(o);
            g.fillStyle = kolor; g.beginPath(); strzalka(g, cx + Math.cos(a) * r, cy + Math.sin(a) * r, kursTeraz(o, t), 6); g.fill();
          });
          const o = lista[0];
          const st = stronaOrbity(cykl, o.orbita ?? kursTeraz(o, t), t);
          linie.push(`${o.flota?.nazwa || o.nazwa}${o.rozbity ? ' (wrak na powierzchni)' : ''}: strona ${st.dzien ? 'dzienna' : 'nocna'}${st.ksiezyc ? `, między planetą a: ${st.ksiezyc}` : ''} · orbita ${Math.round(o.orbita ?? kursTeraz(o, t))}° · kurs ${Math.round(kursTeraz(o, t))}°`);
        });
        // plan przylotu: przerywany okrąg wybranej orbity, linia przylotu z kierunku floty, punkt wyjścia
        if (planSkoku?.planeta === p.nazwa) {
          const pl = planSkoku;
          const odl = { bliska: rp + 14, srednia: rp + 32, daleka: rp + 52, krawedz: Math.min(W, H) / 2 - 10 }[pl.wariant] ?? rp + 14;
          const a = ekr(pl.kierunek + 180); // przylot od strony, z której nadlatuje flota
          const kolor = pl.policzony ? 'rgba(255, 214, 120, 0.95)' : 'rgba(255, 214, 120, 0.45)';
          g.save(); g.strokeStyle = kolor; g.fillStyle = kolor; g.lineWidth = 1.2; g.setLineDash([4, 4]);
          if (!pl.reczny) { g.beginPath(); g.arc(cx, cy, odl, 0, Math.PI * 2); g.stroke(); }
          const koniec = pl.reczny ? rp + 8 : odl;
          const daleko = Math.hypot(W, H);
          g.beginPath(); g.moveTo(cx + Math.cos(a) * daleko, cy + Math.sin(a) * daleko); g.lineTo(cx + Math.cos(a) * koniec, cy + Math.sin(a) * koniec); g.stroke();
          g.setLineDash([]); g.beginPath(); strzalka(g, cx + Math.cos(a) * koniec, cy + Math.sin(a) * koniec, pl.kierunek, 6); g.fill();
          g.restore();
        }
        // ramka: obieg planety wokół słońca
        g.strokeStyle = 'rgba(255,255,255,0.15)'; g.strokeRect(4, 4, 62, 62);
        g.fillStyle = '#ffd678'; g.beginPath(); g.arc(35, 35, 3.5, 0, Math.PI * 2); g.fill();
        g.strokeStyle = 'rgba(255,255,255,0.2)'; g.beginPath(); g.arc(35, 35, 24, 0, Math.PI * 2); g.stroke();
        const ap = ekr(u.katPlanety);
        g.fillStyle = '#6fa8d8'; g.beginPath(); g.arc(35 + Math.cos(ap) * 24, 35 + Math.sin(ap) * 24, 3, 0, Math.PI * 2); g.fill();
        const gwiazdy = (cykl.gwiazdy || []).map((x) => `${x.nazwa} (${x.typ})`).join(' + ');
        wyczysc(opis).append(...[gwiazdy ? `Gwiazda: ${gwiazdy}` : null, `Doba ${liczba(cykl.doba_h, 1)} h · rok ${liczba(cykl.rok_dni, 1)} dni · nachylenie osi ${liczba(cykl.nachylenie, 1)}°`, ...linie].filter(Boolean)
          .map((l) => h('div', l)));
      };
      odswiezUkladKarty = rysuj;
      const zegar = setInterval(rysuj, 1000);
      api('uklad', undefined, { planeta: p.nazwa }).then((d) => { cykl = d.cykl; rysuj(); })
        .catch(() => { wyczysc(opis).append(h('div', 'Brak danych astronawigacyjnych układu.')); });
      return sekcja;
    }

    const NAZWY_WARIANTOW = { bliska: 'Bliska orbita', srednia: 'Średnia orbita', daleka: 'Daleka orbita', krawedz: 'Krawędź układu' };
    const czasTrwania = (s) => {
      s = Math.max(0, Math.round(s));
      const g = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
      return g ? `${g} h ${m} min` : m ? `${m} min` : `${s} s`;
    };

    // Sekcja w karcie planety dla dowódcy floty: skok do tego układu (automat: wariant orbity;
    // bez komputera: mostek liczy ręcznie współrzędne) albo dolot na inną orbitę, gdy flota już tu jest.
    function sekcjaNawigacji(p) {
      const sekcja = h('div.mapa-nawigacja');
      api('mapa_floty_gracza').then((dane) => {
        const floty = dane.floty || [];
        if (!floty.length) return;
        const wybor = h('select', floty.map((f) => h('option', { value: f.id }, f.nazwa)));
        const tresc = h('div');
        const rysuj = () => {
          const f = floty.find((x) => String(x.id) === wybor.value) || floty[0];
          const jednostki = f.jednostki || [];
          const wUkladzie = jednostki.filter((j) => norm(j.planeta || '') === p.klucz && !j.rozkaz_id);
          const naLadowisku = wUkladzie.filter((j) => j.ladowisko_id);
          const tutaj = wUkladzie.filter((j) => !j.ladowisko_id);
          const automat = jednostki[0]?.automat !== false;
          const info = h('p.slaby.maly-tekst');
          wyczysc(tresc);
          // Na lądowisku: start (wznoszenie ok. 5 min, potem bliska orbita).
          if (naLadowisku.length) {
            tresc.append(h('div.mapa-nawigacja-blok',
              h('b', `Na lądowisku: ${naLadowisku[0].ladowisko || 'lądowisko'}`),
              h('button.przycisk.glowny.mapa-nawigacja-wykonaj', { onclick: async () => {
                try {
                  const w = await api('mapa_flota_start', { flota_id: f.id, jednostki: naLadowisku.map((j) => j.obiekt_id) });
                  info.textContent = `Start. Bliska orbita ok. ${czasGry(w.orbita)}.`;
                  await pobierzPozycje(); await odswiezFloty();
                } catch (e) { info.textContent = e.message; }
              } }, 'Start')));
          }
          // Na bliskiej orbicie, jednostki zdolne do lądowania: wybór lądowiska.
          const mogaLadowac = tutaj.filter((j) => Number(j.moze_ladowac) === 1 && (j.podejscie_teraz || 'bliska') === 'bliska');
          if (mogaLadowac.length) {
            const lista = h('select.mapa-nawigacja-wybor', h('option', { value: '' }, 'Wybierz lądowisko'));
            const laduj = h('button.przycisk', { onclick: async () => {
              if (!lista.value) { info.textContent = 'Wybierz lądowisko.'; return; }
              try {
                const w = await api('mapa_flota_ladowanie', { flota_id: f.id, jednostki: mogaLadowac.map((j) => j.obiekt_id), lokacja_id: Number(lista.value) });
                info.textContent = `Lądowanie: ${w.ladowisko}.`;
                await pobierzPozycje(); await odswiezFloty();
              } catch (e) { info.textContent = e.message; }
            } }, 'Ląduj');
            api('mapa_ladowiska', undefined, { planeta: p.nazwa }).then((d) => {
              lista.append(...(d.ladowiska || []).map((l) => h('option', { value: l.id }, `${l.nazwa} (${l.rodzaj})`)));
            }).catch(() => {});
            tresc.append(h('div.mapa-nawigacja-blok', h('b', 'Lądowanie'), h('div.mapa-nawigacja-wiersz', lista, laduj)));
          }
          if (tutaj.length) {
            const obecna = tutaj[0].podejscie_teraz || 'bliska';
            const doloty = Object.keys(NAZWY_WARIANTOW).filter((k) => k !== obecna).map((k) => h('button.przycisk.maly', { onclick: async () => {
              try {
                const w = await api('mapa_flota_dolot', { flota_id: f.id, jednostki: tutaj.map((j) => j.obiekt_id), cel: k });
                info.textContent = w.koniec > teraz() ? `Dolot na: ${NAZWY_WARIANTOW[k].toLowerCase()}. Koniec ${czasGry(w.koniec)}.` : `${NAZWY_WARIANTOW[k]}.`;
                await pobierzPozycje();
              } catch (e) { info.textContent = e.message; }
            } }, NAZWY_WARIANTOW[k]));
            tresc.append(h('div.mapa-nawigacja-blok',
              h('b', `Orbita: ${NAZWY_WARIANTOW[obecna] || obecna}`),
              tutaj[0].podlot_do > teraz() ? h('div.slaby.maly-tekst', `Trwa dolot (koniec ${czasGry(tutaj[0].podlot_do)}).`) : null,
              h('div.mapa-nawigacja-przyciski', ...doloty)));
          }
          // Skok w toku do tej planety: plan przylotu zostaje na widoku układu do przylotu.
          const wDrodze = jednostki.find((j) => j.rozkaz_id && j.cel_x != null && Math.hypot(j.cel_x - p.x, j.cel_y - p.y) < STREFA_UKLADU_PC);
          if (wDrodze) {
            tresc.append(h('div.mapa-nawigacja-blok', h('b', `Skok do układu ${p.nazwa} w toku`),
              h('div.slaby.maly-tekst', `ETA ${czasGry(wDrodze.eta)}`), przyciskPrzerwij(wDrodze.rozkaz_id, info)));
            planSkoku = { planeta: p.nazwa, kierunek: Math.atan2(p.y - wDrodze.y, p.x - wDrodze.x) / RAD,
              wariant: wDrodze.wariant || 'daleka', reczny: !wDrodze.wariant, policzony: true, aktywny: true };
            odswiezUkladKarty?.();
          }
          // Jednostki spoza tego układu (po strefie, nie po nazwie: statek przy księżycu jest już w układzie).
          const pozaUkladem = jednostki.filter((j) => !j.rozkaz_id && Math.hypot(Number(j.x) - p.x, Number(j.y) - p.y) >= STREFA_UKLADU_PC);
          if (pozaUkladem.length && !wDrodze) {
            let wariant = 'bliska';
            const glowna = jednostki[0] || {};
            const kierunek = Math.atan2(p.y - Number(glowna.y || 0), p.x - Number(glowna.x || 0)) / RAD;
            const ustawPlan = (zmiany = {}) => {
              planSkoku = { planeta: p.nazwa, kierunek, wariant, reczny: !automat, policzony: false, ...zmiany };
              odswiezUkladKarty?.();
            };
            const warianty = h('select.mapa-nawigacja-wybor', { onchange: (e) => {
              wariant = e.target.value; wynik.textContent = ''; wykonaj.hidden = true; ustawPlan();
            } }, Object.entries(NAZWY_WARIANTOW).map(([k, n]) => h('option', { value: k }, n)));
            const x = h('input', { type: 'number', step: '0.1', placeholder: 'X' });
            const y = h('input', { type: 'number', step: '0.1', placeholder: 'Y' });
            const wynik = h('p.maly-tekst');
            const odbiorcy = pozaUkladem.map((j) => j.obiekt_id);
            const dane = () => (automat ? { flota_id: f.id, jednostki: odbiorcy, cel_planeta: p.nazwa, wariant } : { flota_id: f.id, jednostki: odbiorcy, x: Number(x.value), y: Number(y.value) });
            const wykonaj = h('button.przycisk.glowny.mapa-nawigacja-wykonaj', { hidden: true, onclick: async () => {
              try {
                const w = await api('mapa_flota_rozkaz', dane());
                wynik.textContent = `Rozkaz przyjęty. Wejście w nadprzestrzeń ${czasGry(w.wejscie)}, ETA ${czasGry(w.eta)}.`;
                wykonaj.hidden = true;
                ustawPlan({ policzony: true, aktywny: true });
                await pobierzPozycje();
              } catch (e) { wynik.textContent = e.message; }
            } }, 'Wykonaj skok');
            const oblicz = h('button.przycisk', { onclick: async () => {
              if (!automat && (x.value === '' || y.value === '')) { wynik.textContent = 'Mostek: podaj wyliczone współrzędne X i Y.'; return; }
              try {
                const w = await api('mapa_flota_rozkaz', { ...dane(), plan: true });
                wynik.textContent = `${w.tryb === 'reczny' ? 'Obliczenia mostka' : 'Komputer'}: ${czasTrwania(w.obliczenia_s)} · obrót: ${czasTrwania(w.manewr_s)}`
                  + (w.punkt_s > 0 ? ` · do punktu skoku: ${czasTrwania(w.punkt_s)}` : '')
                  + ` · lot: ${czasTrwania(w.lot_s)} · wejście ${czasGry(w.wejscie)} · ETA ${czasGry(w.eta)}`
                  + ` · paliwo: ${w.jednostki.map((j) => `${j.nazwa} ${j.paliwo}`).join(', ')}`;
                wykonaj.hidden = false;
                ustawPlan({ policzony: true });
              } catch (e) { wynik.textContent = e.message; wykonaj.hidden = true; }
            } }, automat ? 'Oblicz skok' : 'Sprawdź skok');
            ustawPlan();
            tresc.append(h('div.mapa-nawigacja-blok',
              h('b', `Skok do układu ${p.nazwa}`),
              automat ? null : h('p.slaby.maly-tekst', 'Komputer nawigacyjny nie odpowiada. Mostek musi wyliczyć skok sam: podaj współrzędne wyjścia.'),
              h('div.mapa-nawigacja-wiersz', automat ? warianty : h('div.mapa-nawigacja-xy', x, y), oblicz),
              wykonaj, wynik));
          }
          if (jednostki.some((j) => norm(j.planeta || '') !== p.klucz || j.lot)) {
            tresc.append(blokLotu(f, { cel_planeta: p.nazwa }, `Lot podświetlny do ${p.nazwa}`, (j) => norm(j.planeta || '') !== p.klucz));
          }
          tresc.append(info);
        };
        wybor.onchange = rysuj;
        sekcja.append(h('div.slaby.maly-tekst', 'Nawigacja floty'), floty.length > 1 ? wybor : null, tresc);
        rysuj();
      }).catch(() => {});
      return sekcja;
    }

    // Przerwanie skoku (z potwierdzeniem): w nadprzestrzeni grozi uszkodzeniem reaktora i hipernapędu.
    function przyciskPrzerwij(rozkazId, info) {
      return h('button.przycisk.maly', { onclick: async () => {
        if (!confirm('Przerwać skok? W nadprzestrzeni gwałtowne wyjście uszkodzi reaktor i hipernapęd.')) return;
        try {
          const w = await api('mapa_rozkaz_anuluj', { id: rozkazId });
          info.textContent = w.komunikat || 'Skok przerwany.';
          await pobierzPozycje(); await odswiezFloty();
        } catch (e) { info.textContent = e.message; }
      } }, 'Przerwij skok');
    }

    // Czas trwania także w dniach i latach (lot podświetlny między układami trwa lata).
    const czasDlugi = (s) => (s >= 2 * 31557600 ? `${liczba(s / 31557600)} lat` : s >= 2 * 86400 ? `${liczba(s / 86400)} dni` : czasTrwania(s));

    // Blok lotu podświetlnego dla floty: trwający lot (przerwij) albo wyliczenie i rozkaz lotu do celu.
    function blokLotu(f, cel, opis, czyLeci = () => true) {
      const jednostki = f.jednostki || [];
      const wynik = h('p.maly-tekst');
      const blok = h('div.mapa-nawigacja-blok', h('b', opis));
      const wLocie = jednostki.filter((j) => j.lot && j.lot.do > teraz());
      if (wLocie.length) {
        const l = wLocie[0].lot;
        blok.append(h('div.slaby.maly-tekst', `W locie podświetlnym → ${l.planeta || `${liczba(l.x, 1)} · ${liczba(l.y, 1)}`} · ETA ${czasGry(l.do)}`),
          h('button.przycisk.maly', { onclick: async () => {
            try {
              await api('mapa_lot_przerwij', { flota_id: f.id, jednostki: wLocie.map((j) => j.obiekt_id) });
              wynik.textContent = 'Lot przerwany.';
              await pobierzPozycje(); await odswiezFloty();
            } catch (e) { wynik.textContent = e.message; }
          } }, 'Przerwij lot'));
      }
      const zdolne = jednostki.filter((j) => !j.rozkaz_id && !j.ladowisko_id && czyLeci(j));
      if (!zdolne.length) { blok.append(wynik); return blok; }
      const dane = { flota_id: f.id, jednostki: zdolne.map((j) => j.obiekt_id), ...cel };
      const lec = h('button.przycisk.glowny.mapa-nawigacja-wykonaj', { hidden: true, onclick: async () => {
        try {
          const w = await api('mapa_flota_lot', dane);
          wynik.textContent = `Lot rozpoczęty. Silniki ${czasGry(w.start)}, ETA ${czasGry(w.eta)}.`;
          lec.hidden = true;
          await pobierzPozycje(); await odswiezFloty();
        } catch (e) { wynik.textContent = e.message; }
      } }, 'Leć');
      const oblicz = h('button.przycisk', { onclick: async () => {
        try {
          const w = await api('mapa_flota_lot', { ...dane, plan: true });
          wynik.textContent = `Trasa ${liczba(w.km)} km · ${liczba(w.predkosc_kms)} km/s · obrót ${czasTrwania(w.manewr_s)}`
            + ` · lot ${czasDlugi(w.lot_s)} · ETA ${czasGry(w.eta)}`;
          lec.hidden = false;
        } catch (e) { wynik.textContent = e.message; lec.hidden = true; }
      } }, 'Oblicz lot');
      blok.append(h('div.mapa-nawigacja-wiersz', oblicz), lec, wynik);
      return blok;
    }

    // Karta punktu mapy dla dowódcy floty: skok do punktu albo lot podświetlny.
    function sekcjaPunktu(pkt) {
      const sekcja = h('div.mapa-nawigacja');
      api('mapa_floty_gracza').then((dane) => {
        const floty = dane.floty || [];
        if (!floty.length) return;
        const wybor = h('select', floty.map((f) => h('option', { value: f.id }, f.nazwa)));
        const tresc = h('div');
        const rysuj = () => {
          const f = floty.find((x) => String(x.id) === wybor.value) || floty[0];
          wyczysc(tresc);
          const wynik = h('p.maly-tekst');
          const cel = { x: pkt.x, y: pkt.y, ...(pkt.k ? { kx: pkt.k[0], ky: pkt.k[1] } : {}) };
          const dane = { flota_id: f.id, ...cel };
          const wykonaj = h('button.przycisk.glowny.mapa-nawigacja-wykonaj', { hidden: true, onclick: async () => {
            try {
              const w = await api('mapa_flota_rozkaz', dane);
              wynik.textContent = `Rozkaz przyjęty. Wejście w nadprzestrzeń ${czasGry(w.wejscie)}, ETA ${czasGry(w.eta)}.`;
              wykonaj.hidden = true;
              await pobierzPozycje();
            } catch (e) { wynik.textContent = e.message; }
          } }, 'Wykonaj skok');
          const oblicz = h('button.przycisk', { onclick: async () => {
            try {
              const w = await api('mapa_flota_rozkaz', { ...dane, plan: true });
              wynik.textContent = `${w.tryb === 'reczny' ? 'Obliczenia mostka' : 'Komputer'}: ${czasTrwania(w.obliczenia_s)} · obrót: ${czasTrwania(w.manewr_s)}`
                + (w.punkt_s > 0 ? ` · do punktu skoku: ${czasTrwania(w.punkt_s)}` : '') + ` · lot: ${czasTrwania(w.lot_s)} · ETA ${czasGry(w.eta)}`;
              wykonaj.hidden = false;
            } catch (e) { wynik.textContent = e.message; wykonaj.hidden = true; }
          } }, 'Oblicz skok');
          const skoki = [...new Set((f.jednostki || []).filter((j) => j.rozkaz_id).map((j) => j.rozkaz_id))];
          if (skoki.length) tresc.append(h('div.mapa-nawigacja-blok', h('b', 'Skok w toku'), ...skoki.map((id) => przyciskPrzerwij(id, wynik))));
          tresc.append(h('div.mapa-nawigacja-blok', h('b', 'Skok do punktu'), h('div.mapa-nawigacja-wiersz', oblicz), wykonaj, wynik),
            blokLotu(f, cel, 'Lot podświetlny do punktu'));
        };
        wybor.onchange = rysuj;
        sekcja.append(h('div.slaby.maly-tekst', 'Nawigacja floty'), floty.length > 1 ? wybor : null, tresc);
        rysuj();
      }).catch(() => {});
      return sekcja;
    }

    // Pokład statku: pasażerowie i więźniowie (widzą osoby na pokładzie, dowódca lub właściciel i MG).
    function sekcjaPokladu(o) {
      const sekcja = h('div.mapa-poklad');
      api('mapa_poklad', undefined, { obiekt: o.id }).then((d) => {
        let zakladka = 'pasazerowie';
        const zakladki = h('div.mapa-poklad-zakladki');
        const tresc = h('div');
        const rysuj = () => {
          wyczysc(zakladki); wyczysc(tresc);
          for (const [k, n] of [['pasazerowie', 'Pasażerowie'], ['wiezniowie', 'Więźniowie']]) {
            zakladki.append(h(k === zakladka ? 'button.przycisk.maly' : 'button.przycisk.maly.drugi', { onclick: () => { zakladka = k; rysuj(); } }, `${n} (${d[k].length})`));
          }
          const lista = d[zakladka];
          tresc.append(lista.length
            ? h('ul.lista', lista.map((x) => h('li', h('span', x.nazwa), x.status ? h('span.slaby', ` · ${x.status}`) : null,
              d.mg ? h('button.przycisk.maly.drugi', { onclick: async () => {
                try { await api('mg_poklad_rola', { id: x.id, rola: x.rola === 'wiezien' ? 'pasazer' : 'wiezien' }); wybierz(wybrane); }
                catch (e) { tresc.append(h('p.maly-tekst', e.message)); }
              } }, x.rola === 'wiezien' ? '→ pasażer' : '→ więzień') : null)))
            : h('p.slaby.maly-tekst', zakladka === 'wiezniowie' ? 'Brak więźniów.' : 'Brak pasażerów.'));
        };
        sekcja.append(h('div.slaby.maly-tekst', 'Pokład'), zakladki, tresc,
          d.hangar?.length ? h('div.maly-tekst', { style: 'margin-top:8px' }, h('span.slaby', 'Hangar: '),
            d.hangar.map((p) => `${p.nazwa}${p.klasa ? ` (${p.klasa})` : ''}${p.w_hangarze ? '' : ' — poza hangarem'}`).join(', ')) : null);
        rysuj();
      }).catch(() => {});
      return sekcja;
    }

    function wybierz(obiekt) {
      wybrane = obiekt;
      podpowiedz.hidden = true;
      wyczysc(karta);
      karta.hidden = !obiekt;
      if (!obiekt) return;
      const wiersz = (k, v) => h('tr', h('th', k), h('td', v));
      const nieznane = (v, tekst) => (v ? tekst : 'brak danych');
      const zamknij = h('button.mapa-zamknij', { 'aria-label': 'Zamknij', onclick: () => wybierz(null) }, '×');
      const postac = obiekt.obiekt?.typ === 'postac' ? obiekt.obiekt : null;
      const planetaKontekstu = obiekt.planeta || (postac
        ? P.find((p) => p.klucz === norm(postac.planeta))
        : null);
      const osobyNaPlanecie = planetaKontekstu
        ? obiektyMapy.filter((o) => o.typ === 'postac' && norm(o.planeta) === planetaKontekstu.klucz)
          .sort((a, b) => a.nazwa.localeCompare(b.nazwa, 'pl'))
        : [];
      const widoki = planetaKontekstu ? [{ planeta: planetaKontekstu }, ...osobyNaPlanecie.map((o) => ({ obiekt: o }))] : [];
      const indeksWidoku = obiekt.planeta
        ? 0
        : postac ? widoki.findIndex((w) => w.obiekt?.id === postac.id) : -1;
      if (obiekt.obiekt) {
        const o = obiekt.obiekt;
        karta.append(zamknij, h('h2', nazwaPostaci(o)),
          h('div.mapa-znaczki', h('span.znaczek-cel', o.typ === 'postac' ? 'Pozycja postaci' : 'Pozycja jednostki')),
          h('table.tabela',
            wiersz('Typ', o.rodzaj || o.typ),
            o.klasa ? wiersz('Klasa', o.klasa) : null,
            o.wlasciciel ? wiersz('Właściciel / frakcja', o.wlasciciel) : null,
            o.status ? wiersz('Status', o.status) : null,
            o.planeta ? wiersz('Planeta', o.planeta) : null,
            wiersz('Współrzędne', `${liczba(o.x, 1)} · ${liczba(o.y, 1)}`),
            o.opis ? wiersz('Opis', o.opis) : null),
          o.flota ? h('div.mapa-obiekt-flota', h('strong', o.flota.nazwa), o.flota.grupa ? h('span.slaby', o.flota.grupa) : null,
            h('span', `Dowódca: ${o.flota.dowodca || 'nieprzypisany'}`),
            o.flota.tie_liczba == null ? null : h('span', `${o.flota.tie_liczba} myśliwców TIE`)) : null,
          o.podswietlny && teraz() < o.podswietlny.do ? h('p.slaby.maly-tekst', `Lot podświetlny → ${o.podswietlny.planeta || `${liczba(o.podswietlny.x1, 1)} · ${liczba(o.podswietlny.y1, 1)}`} · ETA ${czasGry(o.podswietlny.do)}`) : null,
          o.typ !== 'postac' ? sekcjaPokladu(o) : null);
      } else if (obiekt.planeta) {
        const p = obiekt.planeta;
        const tutaj = statkiNaMapie.find((x) => x.planeta === p)?.statki || [];
        const odleglosc = tuPlaneta && tuPlaneta !== p ? Math.hypot(p.x - tuPlaneta.x, p.y - tuPlaneta.y) : null;
        karta.append(zamknij,
          h('h2', p.nazwa),
          h('div.mapa-znaczki',
            p === tuPlaneta ? h('span.znaczek-tu', 'Twoja pozycja') : null,
            p === celPlaneta ? h('span.znaczek-cel', 'Cel skoku') : null,
            p.zastrzezona ? h('span.znaczek-cel', 'Dane zastrzeżone') : null),
          h('table.tabela',
            p.sektor ? wiersz('Sektor', p.sektor) : null,
            wiersz('Region', p.region || 'nieznany'),
            p.kwadrat ? wiersz('Kwadrat', p.kwadrat) : null,
            wiersz('Współrzędne', `${liczba(p.x, 1)} · ${liczba(p.y, 1)}`),
            odleglosc !== null ? wiersz('Odległość od ciebie', `${liczba(odleglosc)} pc`) : null,
            wiersz('Grawitacja', nieznane(p.g, `${liczba(p.g, 2)} G`)),
            wiersz('Słońca · księżyce', nieznane(p.doba, `${p.slonca} · ${p.ksiezyce}`)),
            wiersz('Doba · rok', nieznane(p.doba, `${liczba(p.doba, 1)} h · ${liczba(p.rok, 1)} dni`)),
            p.srednica > 0 ? wiersz('Średnica', `${liczba(p.srednica)} km`) : null),
          listaObecnych(p),
          sekcjaNawigacji(p),
          widokUkladu(p),
          tutaj.length ? h('div.mapa-statki', h('div.slaby.maly-tekst', 'Statki twoich firm'),
            h('ul.lista', tutaj.map((s) => h('li', h('b', s.nazwa), h('span.slaby', ` · ${s.firma}`),
              s.w_locie ? h('span.slaby', s.cel ? ` · w nadprzestrzeni → ${s.cel}` : ' · na orbicie') : null)))) : null);
      } else if (obiekt.punkt) {
        const { x, y, k, uklad } = obiekt.punkt;
        const moj = mojZnacznik();
        const odl = moj && !k ? Math.hypot(x - moj.x, y - moj.y) : null;
        // Punkt w układzie: odległość od słońca i od planety (km / j.a.).
        let odPlanety = null;
        if (k && uklad) {
          const u = ukladNaEkranie(uklad);
          if (u) odPlanety = Math.hypot((u.px - u.sx) / u.skm - k[0], -(u.py - u.sy) / u.skm - k[1]);
        }
        const odlKm = (km) => (km >= KM_NA_AU * 0.1 ? `${liczba(km / KM_NA_AU, 2)} j.a.` : `${liczba(km)} km`);
        karta.append(zamknij,
          h('h2', k ? `Punkt w układzie ${uklad?.nazwa || ''}` : `Punkt ${liczba(x, 1)} · ${liczba(y, 1)}`),
          h('table.tabela',
            wiersz('Współrzędne', `${liczba(x, 1)} · ${liczba(y, 1)}`),
            k ? wiersz('Od słońca', odlKm(Math.hypot(k[0], k[1]))) : null,
            odPlanety != null ? wiersz(`Od planety ${uklad.nazwa}`, odlKm(odPlanety)) : null,
            odl !== null ? wiersz('Odległość od ciebie', `${liczba(odl, 1)} pc`) : null),
          sekcjaPunktu(obiekt.punkt));
      } else {
        const s = obiekt.szlak;
        karta.append(zamknij,
          h('h2', s.nazwa),
          h('table.tabela',
            wiersz('Klasa', s.z <= 1 ? 'szlak główny' : s.z <= 3 ? 'szlak drugorzędny' : 'szlak lokalny'),
            wiersz('Długość', `${liczba(s.dl)} pc`)));
      }
      if (widoki.length > 1 && indeksWidoku >= 0) {
        const etykieta = (widok) => widok.planeta ? `Planeta: ${widok.planeta.nazwa}` : widok.obiekt.nazwa;
        karta.append(h('div.mapa-przelaczanie',
          h('button.mapa-przelacz', { type: 'button', 'aria-label': 'Poprzednia karta', onclick: () => wybierz(widoki[(indeksWidoku - 1 + widoki.length) % widoki.length]) }, '‹'),
          h('span', `${indeksWidoku + 1} / ${widoki.length} · ${etykieta(widoki[indeksWidoku])}`),
          h('button.mapa-przelacz', { type: 'button', 'aria-label': 'Następna karta', onclick: () => wybierz(widoki[(indeksWidoku + 1) % widoki.length]) }, '›')));
      }
    }

    // ---------- wyszukiwarka ----------

    function szukajTeraz() {
      const q = norm(szukaj.value);
      wyczysc(wyniki);
      if (!D || q.length < 2) { wyniki.hidden = true; return; }
      const zaczyna = (k) => k.startsWith(q);
      const planety = P.filter((p) => p.klucz.includes(q)).sort((a, b) => zaczyna(b.klucz) - zaczyna(a.klucz)).slice(0, 7);
      const obiekty = obiektyMapy.filter((o) => norm(`${o.nazwa} ${o.klasa || ''} ${o.wlasciciel || ''}`).includes(q)).slice(0, 7);
      const szlaki = D.szlaki.filter((s) => norm(s.nazwa).includes(q)).slice(0, 3);
      const pozycje = [
        ...planety.map((p) => h('li', { onclick: () => { przelec(p.x, p.y, Math.max(skala, 0.6), () => wybierz({ planeta: p })); koniec(p.nazwa); } },
          h('span', p.nazwa), h('span.slaby', p.region))),
        ...obiekty.map((o) => h('li', { onclick: () => { przelec(o.x, o.y, Math.max(skala, 0.6), () => wybierz({ obiekt: o })); koniec(o.nazwa); } },
          h('span', o.nazwa), h('span.slaby', `${o.typ} · ${o.rodzaj || ''}`))),
        ...szlaki.map((s) => h('li', { onclick: () => { pokazSzlak(s); koniec(s.nazwa); } }, h('span', s.nazwa), h('span.slaby', 'szlak'))),
      ];
      wyniki.append(...(pozycje.length ? pozycje : [h('li.slaby', 'Brak wyników w bazie astronawigacyjnej')]));
      wyniki.hidden = false;
    }
    const koniec = (tekst) => { szukaj.value = tekst; wyniki.hidden = true; szukaj.blur(); };
    function pokazSzlak(s) {
      const b = s.ramka;
      const docelowa = Math.min(1.5, szer / ((b.maxX - b.minX) * 1.3 || 1), wys / ((b.maxY - b.minY) * 1.3 || 1));
      przelec((b.minX + b.maxX) / 2, (b.minY + b.maxY) / 2, docelowa, () => wybierz({ szlak: s }));
    }
    szukaj.addEventListener('input', szukajTeraz);
    szukaj.addEventListener('focus', szukajTeraz);
    szukaj.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') wyniki.querySelector('li:not(.slaby)')?.click();
      if (e.key === 'Escape') koniec(szukaj.value);
    });

    // ---------- dotyk, mysz, kółko ----------

    const wskazniki = new Map();
    let przesuniety = false;
    let szczypanie = null;

    plotno.addEventListener('pointerdown', (e) => {
      plotno.setPointerCapture(e.pointerId);
      wskazniki.set(e.pointerId, { x: e.offsetX, y: e.offsetY });
      przesuniety = false;
      animacja = null;
      wyniki.hidden = true;
      szukaj.blur();
      if (wskazniki.size === 2) {
        const [a, b] = [...wskazniki.values()];
        szczypanie = Math.hypot(a.x - b.x, a.y - b.y);
      }
    });
    plotno.addEventListener('pointermove', (e) => {
      const [mx, my] = naMape(e.offsetX, e.offsetY);
      wsp.textContent = `${liczba(mx)} · ${liczba(my)}`;
      const poprzedni = wskazniki.get(e.pointerId);
      if (!poprzedni) {
        if (e.pointerType === 'mouse' && D) {
          const n = trafienie(e.offsetX, e.offsetY);
          const zmienilSieCel = (n?.obiekt || n?.planeta || n?.szlak) !== (najechane?.obiekt || najechane?.planeta || najechane?.szlak);
          if (zmienilSieCel) { najechane = n; pokazPodpowiedz(n, e.offsetX, e.offsetY); odswiez(); }
          else if (n) { podpowiedz.style.left = `${Math.min(Math.max(8, e.offsetX + 16), Math.max(8, szer - 340))}px`; podpowiedz.style.top = `${Math.min(Math.max(68, e.offsetY + 14), Math.max(68, wys - 210))}px`; }
          plotno.style.cursor = n ? 'pointer' : 'grab';
        }
        return;
      }
      const dx = e.offsetX - poprzedni.x, dy = e.offsetY - poprzedni.y;
      if (Math.abs(dx) + Math.abs(dy) > 3) przesuniety = true;
      wskazniki.set(e.pointerId, { x: e.offsetX, y: e.offsetY });
      if (wskazniki.size === 1) {
        camKx -= dx / pxNaKm(); camKy += dy / pxNaKm();
        if (sledz) sledz.ekran = [sledz.ekran[0] + dx, sledz.ekran[1] + dy];
        scalKamere();
      } else if (wskazniki.size === 2 && szczypanie) {
        const [a, b] = [...wskazniki.values()];
        const d = Math.hypot(a.x - b.x, a.y - b.y);
        przybliz(d / szczypanie, (a.x + b.x) / 2, (a.y + b.y) / 2);
        szczypanie = d;
      }
      odswiez();
    });
    const pusc = (e) => {
      const byl = wskazniki.has(e.pointerId);
      wskazniki.delete(e.pointerId);
      if (wskazniki.size < 2) szczypanie = null;
      if (byl && !przesuniety && wskazniki.size === 0 && e.type === 'pointerup' && D) {
        const [mx, my] = naMape(e.offsetX, e.offsetY);
        // Pusty klik w obrębie układu (także w słońce w widoku układu) → karta planety, poza układami → karta punktu.
        const uklad = ukladWPunkcie(mx, my);
        const wUkladzie = uklad && punktUkladu(e.offsetX, e.offsetY, uklad);
        const uk = wUkladzie && ukladNaEkranie(uklad);
        // Klik w słońce (z zapasem) — karta planety układu; pusty punkt w widoku układu — punkt w km od słońca.
        const wSlonce = uk && Math.hypot(e.offsetX - uk.sx, e.offsetY - uk.sy) < SLONCE_MIN_PX + 6;
        const punkt = wUkladzie?.wBrzegu && !wSlonce ? { punkt: { x: uklad.x, y: uklad.y, k: wUkladzie.k, uklad } }
          : uklad ? { planeta: uklad } : { punkt: { x: Math.round(mx * 10) / 10, y: Math.round(my * 10) / 10 } };
        wybierz(trafienie(e.offsetX, e.offsetY) || (wybrane ? null : punkt));
      }
    };
    plotno.addEventListener('pointerup', pusc);
    plotno.addEventListener('pointercancel', pusc);
    plotno.addEventListener('pointerleave', () => { najechane = null; podpowiedz.hidden = true; odswiez(); });
    plotno.addEventListener('wheel', (e) => {
      e.preventDefault();
      animacja = null;
      przybliz(Math.exp(-e.deltaY * 0.0015), e.offsetX, e.offsetY);
    }, { passive: false });

    function przybliz(mnoznik, sx, sy) {
      // Punkt pod kursorem zostaje w miejscu (liczone w km, dokładne także w rzeczywistej skali). Gdy pod
      // kursorem jest planeta, księżyc albo okręt w widoku układu — w miejscu zostaje to ciało (na schemacie
      // nie leży w stałym punkcie mapy, więc inaczej uciekałoby przy zbliżaniu).
      const kotwica = kotwicaPod(sx, sy);
      const przed = kotwica?.();
      const k0 = pxNaKm();
      skala = Math.max(0.005, Math.min(ZOOM_MAKS, skala * mnoznik));
      const d = 1 / k0 - 1 / pxNaKm();
      camKx += (sx - szer / 2) * d;
      camKy -= (sy - wys / 2) * d;
      const po = przed && kotwica();
      if (po) { camKx += (po[0] - przed[0]) / pxNaKm(); camKy -= (po[1] - przed[1]) / pxNaKm(); }
      // Zbliżanie w ciało: kamera dalej za nim leci; zbliżanie w pusty punkt kończy śledzenie.
      if (przed) sledz = { poz: kotwica, ekran: przed };
      else if (!sledz || mnoznik > 1) sledz = null;
      scalKamere();
      odswiez();
    }

    // Ciało pod kursorem w widoku układu: funkcja zwracająca jego pozycję na ekranie albo null.
    function kotwicaPod(sx, sy) {
      if (skala < PROG_UKLADU) return null;
      for (const o of obiektyMapy) {
        if (naPokladzie(o) || o.typ === 'postac') continue;
        const [px, py] = punktObiektu(o);
        if (Math.hypot(px - sx, py - sy) < Math.max(8, rozmiarNaEkranie(o) ?? 0)) return () => punktObiektu(o);
      }
      const [cxp, cyp] = naMape(szer / 2, wys / 2);
      for (const p of P) {
        const [sx0, sy0] = naEkran(p.x, p.y);
        if (Math.hypot(sx0 - szer / 2, sy0 - wys / 2) > 2 * Math.max(szer, wys) && Math.hypot(p.x - cxp, p.y - cyp) > 3e-3) continue;
        const u = ukladNaEkranie(p);
        if (u && Math.hypot(u.px - sx, u.py - sy) < Math.max(8, u.rp + 3)) return () => pozycjaPlanety(p);
      }
      return null;
    }

    // ---------- pozycja postaci ----------

    async function pobierzPozycje() {
      try {
        pozycja = await api('mapa');
      } catch {
        return; // bez zasięgu mapa działa dalej, tylko bez znaczników
      }
      if (!D) return;
      ustawDodatkowe(pozycja.dodatkowe || []);
      obiektyMapy = pozycja.obiekty || [];
      bitwyRadar = pozycja.bitwy_radar || [];
      wyczysc(panelRadar);
      panelRadar.hidden = !bitwyRadar.length;
      if (bitwyRadar.length) panelRadar.append(h('strong', `RADAR · ${pozycja.tu}`), ...bitwyRadar.map((b) => h('div', h('b', b.nazwa), h('small', b.strony.join(' ↔ ')))));
      const znajdz = (nazwa) => (nazwa ? wgKlucza.get(norm(nazwa)) || null : null);
      tuPlaneta = znajdz(pozycja.tu);
      celPlaneta = pozycja.stan === 'nadprzestrzen' ? znajdz(pozycja.cel) : null;
      const grupy = new Map();
      for (const s of pozycja.statki) {
        const p = znajdz(s.planeta);
        if (!p) continue;
        if (!grupy.has(p)) grupy.set(p, []);
        grupy.get(p).push(s);
      }
      statkiNaMapie = [...grupy].map(([planeta, statki]) => ({ planeta, statki }));
      przyciskTu.disabled = !tuPlaneta;
      if (wybrane?.planeta) wybierz(wybrane); // odśwież kartę (np. statki)
    }

    async function odswiezFloty() {
      try {
        const daneFlot = await api('mapa_floty_gracza');
        mojeFloty = daneFlot.floty || [];
        rysujPanelFlot();
      } catch (e) { wyczysc(panelFlot).append(h('p.komunikat.blad', e.message)); }
    }

    function opisSkoku(w) {
      const manewr = w.manewr_s > 0 ? `Manewr na kurs skoku: ${Math.ceil(w.manewr_s / 60)} min, wejście w nadprzestrzeń ${czasGry(w.wejscie)}. ` : '';
      return `${manewr}ETA: ${czasGry(w.eta)}`;
    }

    function stanJednostki(j) {
      const t = teraz();
      if (j.w_hangarze) return `w hangarze ${j.okret_macierzysty || 'okrętu'}`;
      const czesci = [`kurs ${Math.round(j.kurs ?? 90)}°`];
      if (j.cel_ladowania) czesci.push(`leci na lądowisko: ${j.cel_ladowania}`);
      if (j.manewr_do > t) czesci.push(`manewr → ${Math.round(j.kurs_cel)}° do ${czasGry(j.manewr_do)}`);
      if (j.wejscie > t) czesci.push(`skok od ${czasGry(j.wejscie)}`);
      if (j.eta) czesci.push(`ETA ${czasGry(j.eta)}`);
      return czesci.join(' · ');
    }

    // Tarcza kursu: przeciągnij strzałkę (albo użyj przycisków), podgląd kąta i czasu obrotu,
    // „Wykonaj obrót” dla całej floty albo zaznaczonych okrętów. Kąty: 0° = wschód, 90° = północ.
    function tarczaKursu(flota, zaznaczone, info) {
      const jednostki = flota.jednostki || [];
      const glowna = jednostki[0] || {};
      let cel = Math.round(glowna.kurs ?? 90);
      const S = 180, srodek = S / 2, r = S / 2 - 16;
      const plotnoT = h('canvas.mapa-tarcza', { width: S * 2, height: S * 2, style: `width:${S}px;height:${S}px` });
      const g = plotnoT.getContext('2d');
      g.scale(2, 2);
      const opis = h('div.maly-tekst');
      const wybrane = () => { const ids = zaznaczone(); return ids.length ? jednostki.filter((j) => ids.includes(j.obiekt_id)) : jednostki; };
      const roznica = (a, b) => Math.abs(((b - a + 540) % 360) - 180);
      const rysuj = () => {
        const w = wybrane(), teraz0 = Math.round(glowna.kurs ?? 90);
        g.clearRect(0, 0, S, S);
        g.strokeStyle = 'rgba(120,220,210,0.35)'; g.lineWidth = 1;
        g.beginPath(); g.arc(srodek, srodek, r, 0, Math.PI * 2); g.stroke();
        g.font = '10px monospace'; g.fillStyle = 'rgba(200,230,230,0.7)'; g.textAlign = 'center'; g.textBaseline = 'middle';
        for (const [k, t] of [[90, 'N'], [0, 'E'], [270, 'S'], [180, 'W']]) g.fillText(t, srodek + Math.cos(-k * RAD) * (r + 9), srodek + Math.sin(-k * RAD) * (r + 9));
        for (let k = 0; k < 360; k += 15) {
          const a = -k * RAD, d = k % 45 ? 4 : 8;
          g.beginPath(); g.moveTo(srodek + Math.cos(a) * r, srodek + Math.sin(a) * r); g.lineTo(srodek + Math.cos(a) * (r - d), srodek + Math.sin(a) * (r - d)); g.stroke();
        }
        if (glowna.orbita_deg != null && glowna.planeta) {
          // planeta: kierunek od okrętu do planety (przeciwnie do miejsca na orbicie)
          const a = -(Number(glowna.orbita_deg) + 180) * RAD;
          g.fillStyle = '#6fa8d8'; g.beginPath(); g.arc(srodek + Math.cos(a) * (r - 14), srodek + Math.sin(a) * (r - 14), 5, 0, Math.PI * 2); g.fill();
        }
        g.globalAlpha = 0.45; g.fillStyle = '#e8edf2'; g.beginPath(); strzalka(g, srodek, srodek, teraz0, r * 0.55); g.fill();
        g.globalAlpha = 1; g.fillStyle = '#3ddc84'; g.beginPath(); strzalka(g, srodek, srodek, cel, r * 0.8); g.fill();
        const obrot = Math.min(...w.map((j) => Number(j.obrot_deg_na_min) || 90));
        opis.textContent = `Kurs ${cel}° (teraz ${teraz0}°) · obrót ok. ${czasTrwania(roznica(teraz0, cel) / obrot * 60)} · ${w.length === jednostki.length ? 'cała flota' : `zaznaczone: ${w.length}`}`;
      };
      const zKursu = (e) => {
        const b = plotnoT.getBoundingClientRect();
        const x = e.clientX - b.left - b.width / 2, y = e.clientY - b.top - b.height / 2;
        cel = (Math.round(Math.atan2(-y, x) / RAD) + 360) % 360;
        rysuj();
      };
      let ciagnij = false;
      plotnoT.addEventListener('pointerdown', (e) => { ciagnij = true; plotnoT.setPointerCapture(e.pointerId); zKursu(e); });
      plotnoT.addEventListener('pointermove', (e) => { if (ciagnij) zKursu(e); });
      plotnoT.addEventListener('pointerup', () => { ciagnij = false; });
      const przesun = (d) => h('button.przycisk.maly.drugi', { onclick: () => { cel = (cel + d + 360) % 360; rysuj(); } }, `${d > 0 ? '+' : ''}${d}°`);
      const naPlanete = glowna.orbita_deg != null && glowna.planeta;
      const wykonaj = h('button.przycisk.glowny', { onclick: async () => {
        const ids = zaznaczone();
        try {
          const w = await api('mapa_flota_kurs', { flota_id: flota.id, ...(ids.length ? { jednostki: ids } : {}), kurs: cel });
          info.textContent = w.koniec > teraz() ? `Obrót na kurs ${Math.round(w.kurs)}° zakończy się ${czasGry(w.koniec)}.` : `Kurs ${Math.round(w.kurs)}°.`;
          await odswiezFloty(); await pobierzPozycje();
        } catch (e) { info.textContent = e.message; }
      } }, 'Wykonaj obrót');
      rysuj();
      return h('div.mapa-tarcza-blok', plotnoT, h('div.mapa-tarcza-sterowanie',
        h('div.mapa-nawigacja-przyciski', przesun(45), przesun(15), przesun(-15), przesun(-45)),
        naPlanete ? h('div.mapa-nawigacja-przyciski',
          h('button.przycisk.maly.drugi', { onclick: () => { cel = Math.round((Number(glowna.orbita_deg) + 180) % 360); rysuj(); } }, 'Dziobem na planetę'),
          h('button.przycisk.maly.drugi', { onclick: () => { cel = Math.round(Number(glowna.orbita_deg) % 360); rysuj(); } }, 'Rufą do planety')) : null,
        opis, wykonaj));
    }

    let wybranaFlota = null;
    // Prom z hangaru okrętu: wylot (potem zwykłe rozkazy: skok, lot, lądowanie) albo powrót do hangaru.
    function przyciskHangaru(j, info) {
      const wylot = !!j.w_hangarze;
      return h('button.przycisk.maly.drugi', { onclick: async () => {
        if (!confirm(wylot ? `Wylot „${j.obiekt_nazwa}” z hangaru ${j.okret_macierzysty}?` : `Powrót „${j.obiekt_nazwa}” do hangaru ${j.okret_macierzysty}?`)) return;
        try { const w = await api(wylot ? 'prom_wylot' : 'prom_powrot', { obiekt_id: j.obiekt_id }); info.textContent = w.komunikat || 'Gotowe.'; await odswiezFloty(); await pobierzPozycje(); }
        catch (e) { info.textContent = e.message; }
      } }, wylot ? `${j.obiekt_nazwa}: wylot z hangaru` : `${j.obiekt_nazwa}: powrót do hangaru`);
    }

    function rysujPanelFlot() {
      wyczysc(panelFlot);
      panelFlot.append(h('button.mapa-zamknij', { 'aria-label': 'Zamknij', onclick: () => { panelFlot.hidden = true; } }, '×'), h('h2', 'Dowodzenie flotą'));
      if (!mojeFloty.length) { panelFlot.append(h('p.slaby', 'Do tego konta nie przypisano flot.')); return; }
      // Jedna flota naraz: wybór na górze zamiast długiej listy formularzy.
      if (!mojeFloty.some((f) => f.id === wybranaFlota)) wybranaFlota = mojeFloty[0].id;
      if (mojeFloty.length > 1) {
        panelFlot.append(h('select.mapa-floty-wybor', { onchange: (e) => { wybranaFlota = Number(e.target.value); rysujPanelFlot(); } },
          mojeFloty.map((f) => h('option', { value: f.id, selected: f.id === wybranaFlota }, f.nazwa))));
      }
      for (const flota of mojeFloty.filter((f) => f.id === wybranaFlota)) {
        const wybor = h('div.mapa-flota-jednostki', flota.jednostki.map((j) => h('label',
          h('input', { type: 'checkbox', value: j.obiekt_id, 'data-flota': String(flota.id) }),
          ` ${j.obiekt_nazwa} · ${j.klasa || j.typ}${j.tie_liczba == null ? '' : ` · ${j.tie_liczba} TIE`} · ${stanJednostki(j)}`)));
        const nazwaGrupy = h('input', { placeholder: 'Nazwa nowej grupy' });
        const x = h('input', { type: 'number', step: '0.1', placeholder: 'X' });
        const y = h('input', { type: 'number', step: '0.1', placeholder: 'Y' });
        const cel = h('select', [h('option', { value: '' }, 'Współrzędne X/Y'), ...mojeFloty.flatMap((f) => f.jednostki.map((j) => h('option', { value: j.obiekt_id }, `${f.nazwa} · ${j.obiekt_nazwa}`)))]);
        const info = h('p.slaby');
        const zaznaczone = () => [...wybor.querySelectorAll('input:checked')].map((el) => Number(el.value));
        const stan = h('p.slaby.maly-tekst', flota.opis || '');
        const podziel = h('button.przycisk.maly', { onclick: async () => {
          const ids = zaznaczone();
          try { await api('mapa_flota_podziel', { flota_id: flota.id, nazwa: nazwaGrupy.value, jednostki: ids }); await odswiezFloty(); }
          catch (e) { info.textContent = e.message; }
        } }, 'Utwórz grupę z zaznaczonych');
        const rozkaz = h('button.przycisk.maly.glowny', { onclick: async () => {
          const ids = zaznaczone();
          if (!ids.length) { info.textContent = 'Zaznacz jednostki, które mają wykonać rozkaz.'; return; }
          if (cel.value) {
            const j = ids.length === 1 ? { obiekt_id: ids[0] } : { flota_id: flota.id };
            try { const wynik = await api('mapa_flota_rozkaz', { ...j, cel_obiekt_id: Number(cel.value) }); info.textContent = `Rozkaz przyjęty. ${opisSkoku(wynik)}. Szum nawigacyjny: ${wynik.jednostki.map((a) => `${a.nazwa} ${a.szum_stopnie}°`).join(', ')}`; await pobierzPozycje(); }
            catch (e) { info.textContent = e.message; }
          } else if (x.value !== '' && y.value !== '') {
            const j = ids.length === 1 ? { obiekt_id: ids[0] } : { flota_id: flota.id };
            try { const wynik = await api('mapa_flota_rozkaz', { ...j, x: Number(x.value), y: Number(y.value) }); info.textContent = `Rozkaz przyjęty. ${opisSkoku(wynik)}. Szum: ${wynik.jednostki.map((a) => `${a.nazwa} ${a.szum_stopnie}°`).join(', ')}`; await pobierzPozycje(); }
            catch (e) { info.textContent = e.message; }
          } else info.textContent = 'Podaj współrzędne albo wybierz jednostkę docelową.';
        } }, 'Wyślij zaznaczone');
        const tarcza = tarczaKursu(flota, zaznaczone, info);
        const defaultEskorta = flota.kod === 'havis-iii-eskortowa' ? h('button.przycisk.maly.drugi', { onclick: () => {
          let krążnik = false;
          wybor.querySelectorAll('input').forEach((el) => { const j = flota.jednostki.find((u) => u.obiekt_id === Number(el.value));
            if (j?.obiekt_nazwa.startsWith('Arquitens') && !krążnik) { el.checked = true; krążnik = true; }
            else if (j?.obiekt_nazwa.startsWith('Gozanti')) el.checked = Number(j.obiekt_nazwa.match(/\d+/)?.[0]) <= 3;
            else el.checked = false;
          });
        } }, 'Zaznacz domyślną eskortę (1 krążownik + 3 Gozanti)') : null;
        panelFlot.append(h('article.karta', h('h3', flota.nazwa), h('p.slaby', `Dowódca: ${flota.dowodca_efektywny || 'nieprzypisany'}`), stan, defaultEskorta, wybor,
          tarcza,
          h('p.slaby.maly-tekst', 'Kurs to kierunek dziobu: 0° = wschód, 90° = północ. Miejsce na orbicie się nie zmienia, okręty tylko obracają dziób. Przed skokiem flota sama obraca się na kurs skoku.'),
          h('p.slaby.maly-tekst', 'Skok do planety z wyborem orbity: kliknij planetę na mapie → „Nawigacja floty”. Tu: skok na współrzędne albo do innej jednostki.'),
          h('div.mapa-flota-form', x, y, cel, rozkaz),
          ...[...new Set(flota.jednostki.filter((j) => j.rozkaz_id).map((j) => j.rozkaz_id))].map((id) => przyciskPrzerwij(id, info)),
          ...flota.jednostki.filter((j) => j.okret_macierzysty).map((j) => przyciskHangaru(j, info)),
          info,
          h('details', h('summary.slaby.maly-tekst', 'Podział na grupy'), h('div.mapa-flota-form', nazwaGrupy, podziel)),
          panelZgod({ filtr: (o) => flota.jednostki.some((j) => `okret:${j.obiekt_id}` === o.klucz) })));
      }
    }

    // Planety z katalogu MG spoza bazy mapy. Przebudowa tylko przy zmianie, żeby zaznaczenie
    // i znaczniki dalej wskazywały te same obiekty.
    function ustawDodatkowe(lista) {
      const odcisk = JSON.stringify(lista);
      if (odcisk === odciskDodatkowych && P.length) return;
      odciskDodatkowych = odcisk;
      dodatkowe = lista.map((d) => {
        // Bez podanego regionu: region najbliższej planety z bazy.
        let region = d.region;
        if (!region) {
          let min = Infinity;
          for (const p of D.planety) {
            const o = (p.x - d.x) ** 2 + (p.y - d.y) ** 2;
            if (o < min) { min = o; region = p.region; }
          }
        }
        return { nazwa: d.nazwa, region, x: +d.x, y: +d.y, g: 0, slonca: 0, ksiezyce: 0, doba: 0, rok: 0, srednica: 0,
          sektor: d.sektor, kwadrat: d.kwadrat, zastrzezona: d.zastrzezona, klucz: norm(d.nazwa) };
      });
      const klucze = new Set(dodatkowe.map((p) => p.klucz));
      P = [...D.planety.filter((p) => !klucze.has(p.klucz)), ...dodatkowe];
      wgKlucza = new Map(P.map((p) => [p.klucz, p]));
      if (wybrane?.planeta && !P.includes(wybrane.planeta)) wybierz(null);
      odswiez();
    }

    // ---------- start ----------

    const sledzRozmiar = new ResizeObserver(rozmiar);
    sledzRozmiar.observe(plotno);
    // Po zmianie alfabetu krój Aurebesh może dopiero się wczytywać: przerysuj, gdy będzie gotowy.
    const zmianaAlfabetu = () => setTimeout(() => { czytajMotyw(); odswiez(); }, 220);
    const krojGotowy = () => { czytajMotyw(); odswiez(); };
    window.addEventListener('datapad:alfabet', zmianaAlfabetu);
    document.fonts?.addEventListener('loadingdone', krojGotowy);
    ramka = requestAnimationFrame(klatka);
    const odpytywanie = setInterval(pobierzPozycje, 15000);
    przyciskTu.disabled = true;

    wczytajDane().then(async (d) => {
      D = d;
      ustawDodatkowe([]);
      rozmiar();
      await pobierzPozycje();
      if (tuPlaneta) {
        skala = 0.25; ustawSrodek(tuPlaneta.x, tuPlaneta.y);
      } else {
        calaGalaktyka();
      }
      ladowanie.remove();
      odswiez();
    }).catch(() => {
      wyczysc(ladowanie).append(h('div.komunikat.blad', 'Baza astronawigacyjna niedostępna. Połącz się z holonetem, aby ją pobrać.'));
    });

    ctx.ustawWstecz(() => {
      if (!wyniki.hidden) { wyniki.hidden = true; return true; }
      if (wybrane) { wybierz(null); return true; }
      return false;
    });

    return () => {
      cancelAnimationFrame(ramka);
      clearInterval(odpytywanie);
      sledzRozmiar.disconnect();
      window.removeEventListener('datapad:alfabet', zmianaAlfabetu);
      document.fonts?.removeEventListener('loadingdone', krojGotowy);
    };
  },
};

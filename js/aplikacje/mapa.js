// Mapa galaktyki: planety, regiony i szlaki nadprzestrzenne. Pokazuje, gdzie jest postać,
// dokąd leci i gdzie stoją statki jej firm. Dane astronawigacyjne są pobierane z SQL
// przez API i buforowane przez service worker do pracy bez zasięgu.

import { h, wyczysc, czasGry, dwaKroki } from '../rdzen/narzedzia.js';
import { stanUkladu, stronaOrbity } from '../rdzen/astro.js';
import { panelZgod } from '../rdzen/wskazniki.js';
import { api } from '../rdzen/api.js';

const KOLOR_WYKRYCIA = '#ffb347'; // wykrycia radarowe skoków
const RAD = Math.PI / 180;
// Kolor jednostki z punktu widzenia gracza: sojusznik / wróg / neutralny (serwer: mapa_strony()).
const KOLORY_STRON = { sojusznik: '#3ddc84', wrog: '#ff5a4f', neutralny: '#9aa4ad' };
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
const ZOOM_MAKS = 1e16; // ISD (1,6 km) ok. 500 px
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

// Miejsce na orbicie (°): zapisane albo — bez zapisu — po stronie przeciwnej do dziobu (dziób ku planecie).
const miejsceNaOrbicie = (o, t = teraz()) => o?.orbita ?? (kursTeraz(o, t) + 180) % 360;

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

// Sylwetki okrętów z góry przy dużym zbliżeniu (dziób = +x, współrzędne w połówkach długości).
const SYLWETKI = {
  isd: [[1, 0], [-0.9, 0.6], [-1, 0.52], [-1, 0.16], [-0.86, 0.12], [-0.86, -0.12], [-1, -0.16], [-1, -0.52], [-0.9, -0.6]],
  arquitens: [[1, 0], [0.5, 0.17], [-0.15, 0.2], [-0.3, 0.46], [-0.58, 0.46], [-0.68, 0.22], [-1, 0.17], [-1, -0.17], [-0.68, -0.22],
    [-0.58, -0.46], [-0.3, -0.46], [-0.15, -0.2], [0.5, -0.17]],
  lambda: [[1, 0.1], [0.25, 0.14], [-0.25, 0.95], [-0.5, 0.95], [-0.42, 0.14], [-1, 0.1], [-1, -0.1], [-0.42, -0.14], [-0.5, -0.95],
    [-0.25, -0.95], [0.25, -0.14], [1, -0.1]],
  gozanti: [[1, 0.12], [0.7, 0.3], [0.3, 0.34], [0.2, 0.5], [-0.5, 0.5], [-0.6, 0.32], [-1, 0.24], [-1, -0.24], [-0.6, -0.32],
    [-0.5, -0.5], [0.2, -0.5], [0.3, -0.34], [0.7, -0.3], [1, -0.12]],
  // YT-1300: okrągły kadłub, dwa kły z przodu, kokpit po prawej burcie
  sokol: [[1, 0.24], [0.62, 0.24], [0.5, 0.62], [0.1, 0.78], [-0.3, 0.8], [-0.7, 0.62], [-0.95, 0.3], [-1, 0], [-0.95, -0.3],
    [-0.7, -0.62], [-0.3, -0.8], [0.05, -0.8], [0.3, -0.98], [0.5, -0.98], [0.5, -0.82], [0.62, -0.24], [1, -0.24], [1, -0.04],
    [0.5, -0.04], [0.5, 0.04], [1, 0.04]],
  komrk: KOMRK,
  prom: [[1, 0], [0.4, 0.3], [-1, 0.35], [-1, -0.35], [0.4, -0.3]],
};
// Długość okrętu (m), gdy baza jej nie podaje.
const DLUGOSCI_KLAS = { isd: 1600, arquitens: 325, lambda: 20, gozanti: 64, sokol: 35, komrk: 63, prom: 40 };
function rodzajSylwetki(o) {
  const t = `${o.klasa || ''} ${o.nazwa || ''}`.toLowerCase();
  if (/isd|imperial|star destroyer|niszczyciel/.test(t)) return 'isd';
  if (/arquitens/.test(t)) return 'arquitens';
  if (/lambda/.test(t)) return 'lambda';
  if (/gozanti/.test(t)) return 'gozanti';
  if (/yt-1300|sok[oó]ł|millennium|falcon/.test(t)) return 'sokol';
  if (/kom.?rk/.test(t) || o.znacznik === 'komrk') return 'komrk';
  return 'prom';
}
const dlugoscOkretu = (o) => o?.dlugosc_m || DLUGOSCI_KLAS[rodzajSylwetki(o)] || 100;
function sylwetkaOkretu(g, o, x, y, kurs, r) {
  const a = -kurs * RAD, c = Math.cos(a), s = Math.sin(a);
  SYLWETKI[rodzajSylwetki(o)].forEach(([px, py], i) => {
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

// Planety ze stocznią naprawczą (klucz jak w norm(); serwer: STOCZNIE w api/floty.php).
const STOCZNIE_MAPY = ['kuat'];

const REGIONY = ['Głębokie Jądro', 'Światy Jądra', 'Kolonie', 'Wewnętrzne Rubieże', 'Obszar Ekspansji',
  'Środkowe Rubieże', 'Zewnętrzne Rubieże', 'Przestrzeń Huttów', 'Nieznane Regiony', 'Dzika Przestrzeń'];

// Nazwy do porównań: bez wielkości liter, ogonków, spacji i znaków przestankowych.
export const norm = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/gi, 'l')
  .toLowerCase().replace(/[^a-z0-9]/g, '');

const liczba = (n, miejsca = 0) => Number(n).toLocaleString('pl-PL', { maximumFractionDigits: miejsca });

// ---------- dane (wczytane raz na uruchomienie systemu) ----------

// Dane mapy zależą od uprawnień konta (planety zastrzeżone) — pamięć modułu tylko dla tego samego konta.
// Wcześniej po zmianie konta na tym samym tablecie (bez przeładowania) mapa pokazywała planety poprzedniego.
let dane = null, daneKonta = null;
async function wczytajDane(konto = '') {
  if (dane && daneKonta === konto) return dane;
  dane = null;
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
  daneKonta = konto;
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
    // Skala mapy w rogu: pasek „okrągłej” długości (m, km, j.a., pc, lata świetlne) i zbliżenie.
    const skalaPasek = h('i'), skalaNapis = h('span'), skalaZoom = h('small');
    const skalaEl = h('div.mapa-skala', h('div', skalaPasek, skalaNapis), skalaZoom);
    let skalaPokazana = 0;
    function odswiezSkale() {
      if (skala === skalaPokazana || !szer) return;
      skalaPokazana = skala;
      const kmNaPx = KM_NA_PC / skala;
      const cel = kmNaPx * 90; // ok. 90 px
      // Jednostka według długości paska, potem „okrągła” wartość (1, 2, 5 × 10ⁿ) w tej jednostce.
      const [jedn, kmJedn] = cel < 1 ? ['m', 0.001] : cel < 0.05 * KM_NA_AU ? ['km', 1] : cel < 0.05 * KM_NA_PC ? ['j.a.', KM_NA_AU] : ['pc', KM_NA_PC];
      const ile = cel / kmJedn, rzad = 10 ** Math.floor(Math.log10(ile));
      const wart = [1, 2, 5, 10].map((m) => m * rzad).filter((v) => v <= ile * 1.4).pop() || rzad;
      skalaPasek.style.width = `${Math.round(wart * kmJedn / kmNaPx)}px`;
      const tekst = (v) => liczba(v, v < 1 ? Math.ceil(-Math.log10(v)) : 0);
      skalaNapis.textContent = `${tekst(wart)} ${jedn}` + (jedn === 'pc' ? ` · ${liczba(wart * 3.2616, wart < 1 ? 2 : 0)} lś` : '');
      const naPx = (km) => (km < 1 ? `${liczba(km * 1000, km < 0.01 ? 1 : 0)} m` : km < 0.05 * KM_NA_AU ? `${liczba(km, km < 10 ? 1 : 0)} km`
        : km < 0.05 * KM_NA_PC ? `${liczba(km / KM_NA_AU, 3)} j.a.` : `${liczba(km / KM_NA_PC, 3)} pc`);
      skalaZoom.textContent = skala >= ZOOM_MAKS * 0.98 ? 'zbliżenie maks. · 1:1' : `1 px = ${naPx(kmNaPx)}`;
    }
    const ladowanie = h('div.mapa-ladowanie', 'Pobieranie danych astronawigacyjnych...');
    const panelFlot = h('section.mapa-floty-panel', { hidden: true });
    const panelRadar = h('section.mapa-radar', { hidden: true });
    const przyciskSzlaki = h('button.przycisk.maly.drugi', { onclick: () => { szlakiWidoczne = !szlakiWidoczne; przyciskSzlaki.classList.toggle('wl', szlakiWidoczne); odswiez(); } }, 'Szlaki');
    przyciskSzlaki.classList.add('wl');
    // Na wąskim ekranie (telefon) panel floty i karta szczegółów nie są otwarte naraz.
    const waski = () => matchMedia('(max-width: 760px)').matches;
    const przyciskFloty = h('button.przycisk.maly.drugi', { onclick: () => {
      panelFlot.hidden = !panelFlot.hidden;
      if (!panelFlot.hidden) { if (waski()) wybierz(null); odswiezFloty(); }
    } }, 'Floty / nawigacja');

    // Zbliżenie: małe ikonki w doku (obok głośnika), tylko gdy mapa jest otwarta — +/− (×4) i progi:
    // galaktyka, sektor, układ, planeta (płynny przelot na zaznaczony obiekt albo moją pozycję).
    const IKONY_ZOOMU = [
      ['<path d="M24 12v24M12 24h24"/>', 'Przybliż', () => zoomOkolo(4)],
      ['<path d="M12 24h24"/>', 'Oddal', () => zoomOkolo(1 / 4)],
      ['<path d="M25 24c0-3 5-3 5 0s-7 7-11 2-1-12 7-12 14 7 10 16-13 10-18 5"/>', 'Cała galaktyka', () => calaGalaktyka(true)],
      ['<rect x="10" y="10" width="28" height="28"/><path d="M10 24h28M24 10v28"/><circle cx="31" cy="17" r="3"/>', 'Sektor (okolica wybranego miejsca)', () => doProgu('sektor')],
      ['<circle cx="24" cy="24" r="4"/><ellipse cx="24" cy="24" rx="17" ry="9"/><circle cx="39" cy="21" r="3"/>', 'Układ planetarny', () => doProgu('uklad')],
      ['<circle cx="21" cy="27" r="10"/><circle cx="37" cy="12" r="3.5"/>', 'Planeta i księżyce', () => doProgu('planeta')],
    ].map(([ikona, tytul, akcja]) => ({ ikona, tytul, akcja }));
    if (ctx.dok) ctx.dok(IKONY_ZOOMU);

    w.append(plotno,
      h('div.mapa-gora', h('div.mapa-szukajka', szukaj, wyniki), h('div.mapa-przyciski', przyciskSzlaki, przyciskFloty), skalaEl),
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
    let wykrycieNad = null; // id wykrycia skoku pod kursorem (opis tylko wtedy)
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
      // Rozpęd i hamowanie są krótkie: równe tempo po linii lotu (jak floty_lot_ulamek na serwerze).
      return (t - l.od) / Math.max(1, l.do - l.od);
    }

    // Pozycja jednostki teraz: mapa (pc) i — w układzie — km od słońca; w locie podświetlnym po linii lotu.
    function pozycjaTeraz(o) {
      if (!o) return null;
      const pl = postepLotu(o);
      if (pl != null) {
        const l = o.podswietlny;
        const k = l.k0 && l.k1 ? [l.k0[0] + (l.k1[0] - l.k0[0]) * pl, l.k0[1] + (l.k1[1] - l.k0[1]) * pl] : null;
        return { x: l.x0 + (l.x1 - l.x0) * pl, y: l.y0 + (l.y1 - l.y0) * pl, k };
      }
      if (o.uklad_k) return { x: Number(o.x), y: Number(o.y), k: o.uklad_k };
      const p = planetaObiektu(o);
      if (p) return { x: p.x, y: p.y, k: kmWUkladzie(p) };
      return { x: Number(o.x), y: Number(o.y), k: null };
    }
    // Odległość między pozycjami: w tym samym układzie w km / j.a., poza nim w parsekach.
    function opisOdleglosci(a, b) {
      if (!a || !b) return null;
      const pc = Math.hypot(a.x - b.x, a.y - b.y);
      if (pc < STREFA_UKLADU_PC && a.k && b.k) {
        const km = Math.hypot(a.k[0] - b.k[0], a.k[1] - b.k[1]);
        return km >= KM_NA_AU * 0.1 ? `${liczba(km / KM_NA_AU, 2)} j.a.` : `${liczba(km)} km`;
      }
      return `${liczba(pc, pc < 10 ? 2 : 1)} pc`;
    }
    // Komórka karty liczona na nowo co sekundę (np. odległość w trakcie lotu).
    const zywa = (licz) => { const el = h('td.mapa-zywe'); el._licz = licz; el.textContent = licz() ?? '—'; return el; };
    const zegarZywych = setInterval(() => w.querySelectorAll('.mapa-zywe').forEach((el) => { const v = el._licz?.(); if (v != null && el.textContent !== v) el.textContent = v; }), 1000);

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
    // Dane układu na pewno wczytane (z planetą macierzystą księżyca) — progi zbliżenia liczą z nich miejsce planety.
    async function cyklWczytany(p) {
      if (!p?.nazwa) return null;
      if (!cykle.get(p.nazwa)) {
        try { cykle.set(p.nazwa, (await api('uklad', undefined, { planeta: p.nazwa })).cykl); } catch { return null; }
      }
      const c = cykle.get(p.nazwa);
      const rodzic = c?.macierz ? wgKlucza.get(norm(c.macierz.nazwa)) : null;
      if (rodzic && rodzic !== p) await cyklWczytany(rodzic);
      return c;
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

    // Punkt geograficzny na tarczy planety (widok z góry, półkula północna; południowa tak samo): kierunek =
    // kierunek słońca + kąt godzinny długości (jak floty_start na serwerze), odległość od środka = R·cos(szerokości).
    // rPx — wprost promień na ekranie (np. w trakcie zejścia z orbity).
    function geoNaEkran(p, u, szer, dl, t, rPx = null) {
      const c = u.cykl;
      const H = ((((c.faza_obrotu + 360 * (t - c.epoka) / (c.doba_h * 3600) + dl + 180) % 360) + 360) % 360) - 180;
      const kier = (stanUkladu(c, t).katSlonca + H) * RAD;
      const r = rPx ?? u.rp * Math.cos(Math.max(-89.9, Math.min(89.9, szer)) * RAD);
      const [px, py] = pozycjaPlanety(p);
      return [px + Math.cos(-kier) * r, py + Math.sin(-kier) * r];
    }
    // Punkt na łuku wielkiego koła z (szer0, dl0) do (szer1, dl1) po ułamku f drogi.
    function geoLuk(a, f) {
      f = Math.max(0, Math.min(1, f));
      const w = (s, d) => [Math.cos(s * RAD) * Math.cos(d * RAD), Math.cos(s * RAD) * Math.sin(d * RAD), Math.sin(s * RAD)];
      const A = w(a.szer0, a.dl0), B = w(a.szer1, a.dl1);
      const kat = Math.acos(Math.max(-1, Math.min(1, A[0] * B[0] + A[1] * B[1] + A[2] * B[2])));
      if (kat < 1e-6) return [a.szer1, a.dl1];
      const k0 = Math.sin((1 - f) * kat) / Math.sin(kat), k1 = Math.sin(f * kat) / Math.sin(kat);
      const v = [A[0] * k0 + B[0] * k1, A[1] * k0 + B[1] * k1, A[2] * k0 + B[2] * k1];
      return [Math.asin(Math.max(-1, Math.min(1, v[2]))) / RAD, Math.atan2(v[1], v[0]) / RAD];
    }

    // Najkrótszy łuk (stopnie, ze znakiem) — jak doki_luk na serwerze.
    const lukDeg = (od, d) => ((((d - od) % 360) + 540) % 360) - 180;
    // Promień pierścienia (km od środka planety) i planeta lokacji orbitalnej.
    const promienPierscienia = (r, u) => (u ? (u.srednicaKm || 12000) / 2 : 5000) + r.wysokosc_km;

    // Pozycja okrętu w doku orbitalnym albo w locie łukiem do doku: [kąt°, promień km od środka planety].
    function miejsceWDoku(o, t = teraz()) {
      const d = o.dok;
      const p = planetaObiektu(o);
      const u = p && ukladNaEkranie(p);
      const R = (u ? (u.srednicaKm || 12000) / 2 : 5000) + d.wysokosc_km;
      if (d.do && t < d.do && d.kat0 != null && d.r0 != null) {
        const f = Math.max(0, Math.min(1, (t - d.od) / Math.max(1, d.do - d.od)));
        return [d.kat0 + lukDeg(d.kat0, d.kat) * f, d.r0 + (R - d.r0) * f];
      }
      return [d.kat, R];
    }

    // Lokacje orbitalne: pierścień stoczniowy (okrąg w prawdziwej skali), przy dużym zbliżeniu sektory i stanowiska
    // doków — w budowie szkielet ISD, w naprawie sylwetka ISD, wolne zielony nawias.
    function rysujPierscienie() {
      if (skala < PROG_UKLADU) return;
      for (const r of pozycja?.orbitalne || []) {
        const p = wgKlucza.get(norm(r.planeta));
        const u = p && ukladNaEkranie(p);
        if (!u || u.ksiezyc) continue;
        const [px, py] = pozycjaPlanety(p);
        const R = promienPierscienia(r, u) * u.skm;
        if (R < u.rp + 2 || R > 4e6) continue;
        if (px + R < -20 || px - R > szer + 20 || py + R < -20 || py - R > wys + 20) continue;
        c.save();
        c.strokeStyle = 'rgba(190, 205, 220, 0.42)';
        c.lineWidth = Math.max(1, Math.min(8, 30 * u.skm));
        c.beginPath(); c.arc(px, py, R, 0, Math.PI * 2); c.stroke();
        const stDok = 2 * u.skm; // odstęp stanowisk (2 km) w px
        if (R > 120 && stDok < 6) {
          // Sektory doków ciężkich: litera przy środku sektora.
          c.font = `10px ${kroj}`; c.fillStyle = 'rgba(190, 205, 220, 0.7)';
          const sektory = new Map();
          for (const s of r.stanowiska) { const lit = String(s[3]).split('-')[0]; if (!sektory.has(lit)) sektory.set(lit, s[1]); }
          for (const [lit, kat] of sektory) {
            const a = -kat * RAD;
            c.fillRect(px + Math.cos(a) * R - 1.5, py + Math.sin(a) * R - 1.5, 3, 3);
            if (R > 260) napis(c, `Doki ${lit}`, px + Math.cos(a) * (R + 10) - 12, py + Math.sin(a) * (R + 10) + 4);
          }
        }
        if (stDok >= 6) {
          const dl = 0.8 * u.skm; // pół długości ISD (1,6 km) w px
          for (const [id, kat, stan, ozn, postep] of r.stanowiska) {
            const a = -kat * RAD;
            const x = px + Math.cos(a) * R, y = py + Math.sin(a) * R;
            if (x < -dl - 20 || x > szer + dl + 20 || y < -dl - 20 || y > wys + dl + 20) continue;
            const kursS = kat + 90; // ISD równolegle do pierścienia
            c.lineWidth = 1;
            if (stan === 0) { // budowa: szkielet
              c.strokeStyle = 'rgba(255, 200, 120, 0.55)'; c.setLineDash([2, 2]);
              c.beginPath(); sylwetkaOkretu(c, { klasa: 'isd' }, x, y, kursS, dl); c.stroke(); c.setLineDash([]);
            } else if (stan === 1) { // naprawa / zajęte
              c.fillStyle = 'rgba(150, 160, 170, 0.45)'; c.strokeStyle = 'rgba(200, 210, 220, 0.6)';
              c.beginPath(); sylwetkaOkretu(c, { klasa: 'isd' }, x, y, kursS, dl); c.fill(); c.stroke();
            } else { // wolne
              c.strokeStyle = 'rgba(61, 220, 132, 0.75)';
              c.beginPath(); sylwetkaOkretu(c, { klasa: 'isd' }, x, y, kursS, dl * 0.98); c.stroke();
            }
            if (stDok > 40) {
              c.font = `10px ${kroj}`; c.fillStyle = stan === 2 ? 'rgba(61, 220, 132, 0.9)' : 'rgba(200, 210, 220, 0.75)';
              napis(c, `${ozn}${stan === 0 ? ` · budowa ${postep}%` : stan === 1 ? ' · zajęte' : ' · wolne'}`, x + Math.cos(a) * dl * 1.4, y + Math.sin(a) * dl * 1.4);
            }
          }
        }
        if (R > 60 && R < Math.max(szer, wys) * 3) {
          c.font = `10px ${kroj}`; c.fillStyle = 'rgba(190, 205, 220, 0.75)';
          napis(c, r.nazwa, px + R * 0.71 + 6, py + R * 0.71 + 12);
        }
        c.restore();
      }
    }

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
          // Dzień i noc: oświetlona połowa od strony słońca (kierunek z księżyca na słońce na ekranie), reszta w cieniu.
          const ak = Math.atan2(u.sy - my, u.sx - mx);
          c.fillStyle = '#1b2230'; c.beginPath(); c.arc(mx, my, mr, 0, Math.PI * 2); c.fill();
          c.fillStyle = '#b3c2d1'; c.beginPath(); c.arc(mx, my, mr, ak - Math.PI / 2, ak + Math.PI / 2); c.closePath(); c.fill();
          if (mr >= 6) { c.strokeStyle = 'rgba(179, 194, 209, 0.35)'; c.lineWidth = 1; c.beginPath(); c.arc(mx, my, mr, 0, Math.PI * 2); c.stroke(); }
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
      if (!o || o.typ === 'postac' || !['statek', 'jednostka'].includes(o.typ) && !o.dlugosc_m) return null;
      const r = (dlugoscOkretu(o) / 2000) * (skala / KM_NA_PC);
      return r > 8 ? r : null;
    }

    // Flota w jednym miejscu: albo jeden znacznik floty (glowny + liczba), albo osobne jednostki — nigdy oba.
    // Przy planecie rozwija się od progu zbliżenia; w przestrzeni, gdy szyk na ekranie przekracza kilka pikseli.
    function grupaZwinieta(o) {
      if (!o?.flota || o.typ === 'postac' || wNadprzestrzeni(o) || postepLotu(o) != null) return null;
      const p = planetaObiektu(o);
      if (p) {
        if (skala >= PROG_ROZWINIECIA) return null;
        const czlonkowie = obiektyMapy.filter((x) => x.flota?.kod === o.flota.kod && x.typ !== 'postac'
          && planetaObiektu(x) === p && !wNadprzestrzeni(x) && postepLotu(x) == null);
        return { glowny: czlonkowie[0]?.id === o.id, ile: czlonkowie.length };
      }
      const czlonkowie = obiektyMapy.filter((x) => x.flota?.kod === o.flota.kod && x.typ !== 'postac' && !naPokladzie(x)
        && !planetaObiektu(x) && !wNadprzestrzeni(x) && postepLotu(x) == null
        && x.x === o.x && x.y === o.y && String(x.uklad_k || '') === String(o.uklad_k || ''));
      if (czlonkowie.length < 2) return null;
      const odstepKm = 2.5 * Math.max(...czlonkowie.map(dlugoscOkretu)) / 1000;
      const rozpietoscPx = odstepKm * Math.ceil((czlonkowie.length - 1) / 2) * (skala / KM_NA_PC);
      if (rozpietoscPx >= 10) return null;
      return { glowny: czlonkowie[0].id === o.id, ile: czlonkowie.length };
    }

    // Pozycja ciała w jego układzie: km od słońca (osie mapy); księżyc — od słońca przez planetę macierzystą.
    function kmWUkladzie(pl) {
      const c = pl && cyklPlanety(pl);
      if (!c) return null;
      if (c.macierz) {
        const r = wgKlucza.get(norm(c.macierz.nazwa));
        const m = r && r !== pl ? kmWUkladzie(r) : null;
        if (!m) return null;
        const k = stanUkladu(cyklPlanety(r)).ksiezyce.find((x) => norm(x.nazwa) === pl.klucz);
        if (!k) return m;
        const rk = k.promien_km || c.macierz.promien_km || 400000;
        return [m[0] + Math.cos(k.kat * RAD) * rk, m[1] + Math.sin(k.kat * RAD) * rk];
      }
      const u = stanUkladu(c), R = (c.promien_orbity_au || 1) * KM_NA_AU;
      return [R * Math.cos(u.katPlanety * RAD), R * Math.sin(u.katPlanety * RAD)];
    }
    // Kierunek przylotu jednostki do planety (°, 0 = wschód, 90 = północ) — tak, jak liczy go serwer:
    // spoza układu po mapie galaktyki, w układzie po km od słońca (skąd → planeta).
    function kierunekPrzylotu(j, p) {
      const dx = p.x - Number(j.x || 0), dy = p.y - Number(j.y || 0);
      if (Math.hypot(dx, dy) >= STREFA_UKLADU_PC) return Math.atan2(dy, dx) / RAD;
      const cel = kmWUkladzie(p);
      const skad = j.uklad_kx != null ? [Number(j.uklad_kx), Number(j.uklad_ky)]
        : j.planeta ? kmWUkladzie(wgKlucza.get(norm(j.planeta))) : null;
      if (cel && skad && Math.hypot(cel[0] - skad[0], cel[1] - skad[1]) > 1) return Math.atan2(cel[1] - skad[1], cel[0] - skad[0]) / RAD;
      return Math.atan2(dy, dx) / RAD;
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
      // Punkt w układzie (np. między planetą a słońcem, brzeg układu): km od słońca. Kilka jednostek
      // w tym samym punkcie stoi w szyku (rozstaw w km — widać go dopiero przy dużym zbliżeniu).
      if (o.uklad_k && !planetaObiektu(o) && !wNadprzestrzeni(o) && pl == null) {
        const [dx, dy] = szykKm(o);
        return naEkran(o.x, o.y, o.uklad_k[0] + dx, o.uklad_k[1] + dy);
      }
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
      if (!p && !wNadprzestrzeni(o) && o.typ !== 'postac') { const [dx, dy] = szykKm(o); return naEkran(o.x, o.y, dx, dy); }
      const pozycjaDomyslna = naEkran(o.x, o.y);
      if (!p || wNadprzestrzeni(o)) return pozycjaDomyslna;
      if (o.dok) {
        // Dok orbitalny (albo lot łukiem do doku): kąt i promień od środka planety.
        const [kat, rKm] = miejsceWDoku(o);
        const [px, py] = pozycjaPlanety(p);
        const u = ukladNaEkranie(p);
        const R = u ? Math.max(u.rp + 4, rKm * u.skm) : 14;
        return [px + Math.cos(-kat * RAD) * R, py + Math.sin(-kat * RAD) * R];
      }
      const uPl = ukladNaEkranie(p);
      if (uPl && !uPl.ksiezyc && o.atmosfera && teraz() < o.atmosfera.do) {
        // Lot w atmosferze: zejście z orbity i lot nad powierzchnią (lądowanie) albo lot i wznoszenie (start).
        const a = o.atmosfera, t = teraz();
        const rOrb = Math.max(uPl.rp + 8, ((uPl.srednicaKm || 12000) / 2 + 300) * uPl.skm);
        if (a.rodzaj === 'ladowanie') {
          const tz = a.od + a.faza_s;
          if (t < tz) return geoNaEkran(p, uPl, a.szer0, a.dl0, t, rOrb + (uPl.rp - rOrb) * ((t - a.od) / a.faza_s));
          const [s, d] = geoLuk(a, (t - tz) / Math.max(1, a.do - tz));
          return geoNaEkran(p, uPl, s, d, t);
        }
        const tw = a.do - a.faza_s; // koniec lotu w atmosferze, potem wznoszenie
        if (t < tw) { const [s, d] = geoLuk(a, (t - a.od) / Math.max(1, tw - a.od)); return geoNaEkran(p, uPl, s, d, t); }
        const f = (t - tw) / a.faza_s;
        const [x0, y0] = geoNaEkran(p, uPl, a.szer1, a.dl1, t);
        const [px, py] = pozycjaPlanety(p);
        const kat = a.orbita != null ? -a.orbita * RAD : Math.atan2(y0 - py, x0 - px);
        const r0 = Math.hypot(x0 - px, y0 - py), r = r0 + (rOrb - r0) * f;
        return [px + Math.cos(kat) * r, py + Math.sin(kat) * r];
      }
      if (uPl && !uPl.ksiezyc && o.na_ladowisku && o.geo) {
        // Na lądowisku: współrzędne geograficzne lądowiska na tarczy planety (widok z góry; obraca się z planetą).
        return geoNaEkran(p, uPl, o.geo.szer, o.geo.dl, teraz());
      }
      if (o.na_ladowisku || o.rozbity) {
        // Na powierzchni (lądowisko albo wrak): na tarczy planety, nie na orbicie; kilka statków obok siebie.
        const [px, py] = pozycjaPlanety(p);
        const naPow = obiektyMapy.filter((x) => planetaObiektu(x) === p && (x.na_ladowisku || x.rozbity));
        const i = Math.max(0, naPow.findIndex((x) => x.id === o.id));
        const u = ukladNaEkranie(p);
        const r = Math.min(u ? u.rp * 0.55 : 4, 4 + i * 3);
        const a = i * 2.4;
        return [px + Math.cos(a) * r, py + Math.sin(a) * r];
      }
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
        rozrzutReal = { f: promien > minimum ? 1 : 0, st: ((3 * Math.max(0.05, dlugoscOkretu(o) / 1000)) / km) / RAD };
      }
      const [px, py] = pozycjaPlanety(p);
      if (orbita && (o.orbita != null || o.kurs != null)) {
        // Miejsce na orbicie (orbita_deg): jednostki floty wachlarzem wokół niego; strzałka = kurs dziobu.
        const rozrzutS = zwinieta ? 0 : Math.min(18, 200 / Math.max(rodzina.length, 1));
        const rozrzut = rozrzutReal ? rozrzutS * (1 - rozrzutReal.f) + rozrzutReal.st * rozrzutReal.f : rozrzutS;
        const kat = -(miejsceNaOrbicie(o) + (indeks - (rodzina.length - 1) / 2) * rozrzut) * RAD;
        return [px + Math.cos(kat) * promien, py + Math.sin(kat) * promien];
      }
      const kat = -Math.PI / 2 + (2 * Math.PI * indeks / Math.max(rodzina.length, 1));
      return [px + Math.cos(kat) * promien, py + Math.sin(kat) * promien];
    }

    // Szyk jednostek stojących w tym samym punkcie przestrzeni: klin za prowadzącą (km, osie mapy),
    // odstęp 2,5 długości największej jednostki; obrócony według kursu prowadzącej.
    function szykKm(o) {
      if (o.typ === 'postac') return [0, 0];
      const tuSamo = (x) => x.typ !== 'postac' && !naPokladzie(x) && !wNadprzestrzeni(x) && x.x === o.x && x.y === o.y
        && !planetaObiektu(x) && String(x.uklad_k || '') === String(o.uklad_k || '') && postepLotu(x) == null;
      const grupa = obiektyMapy.filter(tuSamo);
      if (grupa.length < 2) return [0, 0];
      const i = grupa.findIndex((x) => x.id === o.id);
      if (i <= 0) return [0, 0];
      const odstep = 2.5 * Math.max(...grupa.map(dlugoscOkretu)) / 1000;
      const rzad = Math.ceil(i / 2), bok = i % 2 ? 1 : -1;
      const tyl = -rzad * odstep, w = bok * rzad * odstep;
      const a = kursTeraz(grupa[0]) * RAD;
      return [tyl * Math.cos(a) - w * Math.sin(a), tyl * Math.sin(a) + w * Math.cos(a)];
    }

    function listaObecnych(p) {
      const obecni = obiektyNaPlanecie(p);
      const floty = new Map();
      for (const o of obecni) if (o.flota) {
        const klucz = `${norm(o.flota.nazwa)}:${norm(o.flota.dowodca)}`;
        if (!floty.has(klucz)) floty.set(klucz, o.flota);
      }
      const osoby = obecni.filter((o) => o.typ === 'postac').sort((a, b) => a.nazwa.localeCompare(b.nazwa, 'pl'));
      // Ruch cywilny w tle (ujemne id, z radaru) nie zaśmieca listy orbity — widać go na mapie.
      const jednostki = obecni.filter((o) => ['statek','jednostka'].includes(o.typ) && !(o.id < 0));
      return h('div.mapa-obecni',
        floty.size ? [...floty.values()].map((f) => h('div.mapa-obecny',
          h('strong', f.nazwa), h('small', f.dowodca || 'Dowódca nieprzypisany'))) : null,
        osoby.length ? h('div', h('b.mapa-obecni-etykieta', 'Postacie'), osoby.map((o) => h('div.mapa-osoba', nazwaPostaci(o)))) : null,
        jednostki.length ? h('div', h('b.mapa-obecni-etykieta', 'Orbita'), jednostki.map((o) => h('div.mapa-osoba', o.bitwa ? `${o.nazwa} · ${o.bitwa.strona}` : o.na_ladowisku ? `${o.nazwa} · na lądowisku: ${o.na_ladowisku}` : o.nazwa))) : null,
        !floty.size && !osoby.length && !jednostki.length ? h('span.slaby', 'Brak przypisanych postaci i flot') : null);
    }

    function pokazPodpowiedz(cel, sx, sy) {
      if (!cel || cel.szlak) { podpowiedz.hidden = true; return; }
      wyczysc(podpowiedz);
      if (cel.gwiazda) {
        const gw = cyklPlanety(cel.gwiazda)?.gwiazdy?.[0];
        podpowiedz.append(h('strong.mapa-hover-tytul', `Gwiazda układu ${cel.gwiazda.nazwa}`), h('small', gw ? `${gw.typ} · ${gw.nazwa}` : 'gwiazda'));
      } else if (cel.planeta) {
        const ks = skala >= PROG_UKLADU && ukladNaEkranie(cel.planeta)?.ksiezyc;
        podpowiedz.append(h('strong.mapa-hover-tytul', `${ks ? 'Księżyc ' : ''}${cel.planeta.nazwa}`), listaObecnych(cel.planeta));
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

    // Płynny przelot do punktu mapy (k — opcjonalnie km od słońca w układzie punktu).
    function przelec(x, y, docelowa, potem, k = null) {
      sledz = null;
      const [x0, y0] = naMape(szer / 2, wys / 2);
      const s0 = skala;
      const t0 = performance.now();
      animacja = (t) => {
        const p = Math.min(1, Math.max(0, (t - t0) / 700));
        const e = 1 - (1 - p) ** 3;
        // Skala w logarytmie, żeby przybliżanie wyglądało równo; środek widoku sunie do celu.
        skala = Math.exp(Math.log(s0) + (Math.log(docelowa) - Math.log(s0)) * e);
        if (p >= 1) { ustawSrodek(x, y); if (k) { camKx = k[0]; camKy = k[1]; } }
        else { ustawSrodek(x0 + (x - x0) * e, y0 + (y - y0) * e); if (k) { camKx = k[0] * e; camKy = k[1] * e; } }
        odswiez();
        if (p >= 1) { animacja = null; potem?.(); }
      };
    }

    // Punkt zbliżenia: zaznaczony obiekt (jednostka, planeta, gwiazda, punkt), a bez zaznaczenia — moja postać
    // albo okręt, na którym jestem. Funkcja zwraca jego miejsce na ekranie albo null (środek ekranu).
    function kotwicaZoomu() {
      const w0 = wybrane;
      if (w0?.obiekt) return () => punktObiektu(w0.obiekt);
      if (w0?.planeta) return () => pozycjaPlanety(w0.planeta);
      if (w0?.gwiazda) return () => { const u = ukladNaEkranie(w0.gwiazda); return u ? [u.sx, u.sy] : naEkran(w0.gwiazda.x, w0.gwiazda.y); };
      if (w0?.punkt) { const pk = w0.punkt; return () => (pk.k ? naEkran(pk.x, pk.y, pk.k[0], pk.k[1]) : naEkran(pk.x, pk.y)); }
      const moj = mojZnacznik();
      return moj ? () => punktObiektu(moj) : null;
    }

    // Najwyższa skala, przy której gwiazda pod punktem (sx, sy) nie wypełnia ekranu (tarcza do ok. 40% ekranu);
    // poza gwiazdą — bez ograniczenia. Chroni przed „wjechaniem” w słońce.
    function limitGwiazdy(sx, sy) {
      if (skala < PROG_UKLADU) return Infinity;
      const [mx, my] = naMape(sx, sy);
      const p = ukladWPunkcie(mx, my);
      const u = p && ukladNaEkranie(p);
      if (!u || u.ksiezyc) return Infinity;
      const gw = u.cykl.gwiazdy?.[0];
      const R = gw?.promien_km || PROMIEN_GWIAZDY_KM[gw?.typ] || 696000;
      const d = Math.hypot(sx - u.sx, sy - u.sy) / u.skm; // km od środka gwiazdy
      if (d > R * 3) return Infinity;
      return (0.4 * Math.min(szer, wys) / R) * KM_NA_PC;
    }

    // +/−: płynne zbliżenie wokół punktu zbliżenia, który przy okazji dosuwa się do środka ekranu.
    function zoomOkolo(mnoznik) {
      const kotwica = kotwicaZoomu();
      const s0 = skala, t0 = performance.now();
      const a0 = kotwica?.() || [szer / 2, wys / 2];
      let docelowa = Math.max(0.005, Math.min(ZOOM_MAKS, skala * mnoznik));
      animacja = (t) => {
        const p = Math.min(1, (t - t0) / 300);
        const e = 1 - (1 - p) ** 3;
        // punkt zbliżenia sunie z miejsca startu na środek ekranu
        const cel = [a0[0] + (szer / 2 - a0[0]) * e, a0[1] + (wys / 2 - a0[1]) * e];
        const a = kotwica?.() || cel;
        camKx += (a[0] - cel[0]) / pxNaKm(); camKy -= (a[1] - cel[1]) / pxNaKm();
        scalKamere();
        if (mnoznik > 1) docelowa = Math.min(docelowa, Math.max(skala, limitGwiazdy(cel[0], cel[1])));
        przybliz(Math.exp(Math.log(s0) + (Math.log(docelowa) - Math.log(s0)) * e) / skala, cel[0], cel[1]);
        if (p >= 1) {
          animacja = null;
          if (kotwica) sledz = { poz: kotwica, ekran: [szer / 2, wys / 2] };
        }
      };
    }

    // Cel progu zbliżenia: zaznaczony obiekt, a bez zaznaczenia — moja pozycja (okręt na orbicie, w punkcie układu,
    // w locie). { x, y (pc), k (km od słońca albo null), p (układ — do skali) } albo null.
    function celProgu() {
      const w0 = wybrane;
      const zObiektu = (o) => {
        const poz = pozycjaTeraz(o);
        if (!poz) return null;
        const pl = planetaObiektu(o);
        let k = poz.k;
        if (pl && !o.uklad_k && postepLotu(o) == null) {
          // na orbicie: dokładne miejsce okrętu (promień według podejścia, kąt z miejsca na orbicie)
          const c = cyklPlanety(pl);
          const kP = kmWUkladzie(pl);
          if (kP) {
            const Rpl = (c?.srednica_km || 12000) / 2;
            const ro = { bliska: Rpl + 300, srednia: Rpl * 4 }[o.podejscie] ?? Rpl * 15;
            const a = miejsceNaOrbicie(o) * RAD;
            if (o.dok) { const [kd, rd] = miejsceWDoku(o); k = [kP[0] + Math.cos(kd * RAD) * rd, kP[1] + Math.sin(kd * RAD) * rd]; return { x: poz.x, y: poz.y, k, p: pl }; }
            k = [kP[0] + Math.cos(a) * ro, kP[1] + Math.sin(a) * ro];
          }
        }
        return { x: poz.x, y: poz.y, k, p: pl || ukladWPunkcie(poz.x, poz.y) };
      };
      if (w0?.obiekt) return zObiektu(w0.obiekt);
      if (w0?.planeta) return { x: w0.planeta.x, y: w0.planeta.y, k: kmWUkladzie(w0.planeta), p: w0.planeta };
      if (w0?.gwiazda) return { x: w0.gwiazda.x, y: w0.gwiazda.y, k: [0, 0], p: w0.gwiazda };
      if (w0?.punkt) return { x: w0.punkt.x, y: w0.punkt.y, k: w0.punkt.k || null, p: w0.punkt.uklad || ukladWPunkcie(w0.punkt.x, w0.punkt.y) };
      const moj = mojZnacznik();
      if (moj) return zObiektu(moj);
      return tuPlaneta ? { x: tuPlaneta.x, y: tuPlaneta.y, k: kmWUkladzie(tuPlaneta), p: tuPlaneta } : null;
    }

    // Progi: sektor, układ, planeta — skala progu, środek na celu (zaznaczenie się nie zmienia).
    async function doProgu(prog) {
      let c = celProgu();
      if (!c) return;
      // Z widoku galaktyki układ nie był jeszcze rysowany, więc nie ma jego danych — bez nich kamera trafiała
      // w słońce zamiast w planetę. Wczytaj i policz cel jeszcze raz.
      if (c.p && prog !== 'sektor' && !cyklPlanety(c.p)) { await cyklWczytany(c.p); c = celProgu(); if (!c) return; }
      const kr = Math.min(szer, wys) * 0.42; // promień widoku w px
      if (prog === 'sektor') return przelec(c.x, c.y, 4, null, c.k);
      const cykl = c.p ? cyklPlanety(c.p) : null;
      if (prog === 'uklad') {
        const Rbrzeg = (cykl?.krawedz_au || 50) * KM_NA_AU;
        return przelec(c.x, c.y, Math.max(PROG_UKLADU, kr / (Rbrzeg / KM_NA_PC)), null, c.k);
      }
      const ks = cykl?.ksiezyce || [];
      const R = Math.max((cykl?.srednica_km || 12000) * 20, ...ks.map((x) => (x.promien_km || 0) * 1.2));
      przelec(c.x, c.y, Math.min(ZOOM_MAKS, Math.max(PROG_UKLADU, kr / (R / KM_NA_PC))), null, c.k);
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
        // W widoku układu nazwa stoi przy planecie na orbicie, nie przy słońcu.
        if (skala >= PROG_UKLADU && cykle.get(p.nazwa)) continue;
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

    // Odcinek przycięty do ekranu (z zapasem). Przy dużym zbliżeniu końce leżą miliardy pikseli poza
    // ekranem — canvas takich linii nie rysuje — a znacznik jednostki poza ekranem był pomijany razem
    // z linią kursu. Stąd znikająca trajektoria przy części przybliżeń.
    function liniaNaEkranie(g, ax, ay, bx, by) {
      const m = 50, x0 = -m, y0 = -m, x1 = szer + m, y1 = wys + m;
      let t0 = 0, t1 = 1;
      const dx = bx - ax, dy = by - ay;
      for (const [p, q] of [[-dx, ax - x0], [dx, x1 - ax], [-dy, ay - y0], [dy, y1 - ay]]) {
        if (p === 0) { if (q < 0) return false; continue; }
        const r = q / p;
        if (p < 0) { if (r > t1) return false; if (r > t0) t0 = r; } else { if (r < t0) return false; if (r < t1) t1 = r; }
      }
      g.moveTo(ax + dx * t0, ay + dy * t0); g.lineTo(ax + dx * t1, ay + dy * t1);
      return true;
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
      if (brudna) { rysujWarstwe(); brudna = false; odswiezSkale(); }
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
        c.beginPath(); liniaNaEkranie(c, ax, ay, bx, by); c.stroke();
        c.setLineDash([]);
        znacznik(celPlaneta, kolory.zph, 'Cel skoku');
      }
      for (const { planeta, statki } of statkiNaMapie) {
        if (planeta === tuPlaneta) continue;
        znacznik(planeta, kolory.akcent2, statki.length === 1 ? statki[0].nazwa : `Statki: ${statki.length}`);
      }
      rysujUklady();
      rysujPierscienie();
      const moj = mojZnacznik();
      for (const o of obiektyMapy) {
        if (naPokladzie(o)) continue;
        const zw = grupaZwinieta(o);
        if (zw && !zw.glowny) continue;
        const [sx, sy] = punktObiektu(o);
        const kolor = kolorObiektu(o);
        let kursRys = kursTeraz(o);
        // Linie kursu najpierw: widać je także wtedy, gdy sama jednostka jest poza ekranem.
        if (o.lot) {
          // Skok do punktu układu: linia kończy się w tym punkcie (km od słońca), nie w słońcu.
          const plCel = !o.lot_k && o.lot.planeta ? wgKlucza.get(norm(o.lot.planeta)) : null;
          const [cx, cy] = o.lot_k ? naEkran(o.lot.x, o.lot.y, o.lot_k[0], o.lot_k[1]) : plCel ? pozycjaPlanety(plCel) : naEkran(o.lot.x, o.lot.y);
          c.save(); c.strokeStyle = kolor; c.globalAlpha = 0.65; c.setLineDash([5, 5]); c.beginPath(); liniaNaEkranie(c, sx, sy, cx, cy); c.stroke(); c.setLineDash([]); c.restore();
          if (wNadprzestrzeni(o)) kursRys = Math.atan2(-(cy - sy), cx - sx) / RAD;
        }
        if (o.podswietlny && teraz() < o.podswietlny.do && (!(o.id < 0) || wybrane?.obiekt?.id === o.id)) { // tło: linia tylko zaznaczonej
          // Lot podświetlny: kropkowana linia do celu.
          const l = o.podswietlny, b = l.planeta ? wgKlucza.get(norm(l.planeta)) : null;
          const [cx, cy] = l.k1 && !b ? naEkran(l.x1, l.y1, l.k1[0], l.k1[1])
            : b && Math.hypot(l.x1 - l.x0, l.y1 - l.y0) < 0.05 ? pozycjaPlanety(b) : naEkran(l.x1, l.y1);
          c.save(); c.strokeStyle = kolor; c.globalAlpha = 0.55; c.setLineDash([2, 4]); c.beginPath(); liniaNaEkranie(c, sx, sy, cx, cy); c.stroke(); c.setLineDash([]); c.restore();
        }
        if (sx < -12 || sy < -12 || sx > szer + 12 || sy > wys + 12) continue;
        if (zw && zw.ile > 1) {
          c.save(); c.font = `bold 10px ${kroj}`; c.fillStyle = kolory.tekst;
          napis(c, `${o.flota.grupa || o.flota.nazwa} ×${zw.ile}`, sx + 9, sy + 12);
          c.restore();
        }
        c.save(); c.fillStyle = kolor; c.strokeStyle = kolor; c.lineWidth = 1.7;
        if (o.bitwa) { c.globalAlpha = 0.28 + (Math.sin(t / 180) + 1) * 0.16; c.beginPath(); c.arc(sx, sy, 12 + (t % 900) / 120, 0, Math.PI * 2); c.stroke(); c.globalAlpha = 1; }
        c.beginPath();
        // Rzeczywista długość, gdy okręt na ekranie jest większy od znacznika; inaczej znacznik.
        const rz = rozmiarNaEkranie(o);
        if (rz && rz >= 12) {
          // Prawdziwa wielkość: kształt okrętu (kadłub ciemniejszy, obrys w kolorze strony).
          sylwetkaOkretu(c, o, sx, sy, kursRys, rz);
          c.globalAlpha = 0.35; c.fill(); c.globalAlpha = 1; c.lineWidth = Math.min(2, Math.max(1, rz / 120)); c.stroke();
          c.beginPath();
        } else if (o.znacznik === 'trojkat') strzalka(c, sx, sy, kursRys, rz ?? 8);
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
        // Cienka przerywana linia i okrąg niepewności (wypełniony tylko, gdy mały — po zbliżeniu okrąg
        // przykrywał cały ekran na żółto). Opis dopiero po najechaniu kursorem na punkt wyjścia.
        const r = Math.max(4, w.margines * skala);
        c.save(); c.strokeStyle = KOLOR_WYKRYCIA; c.fillStyle = KOLOR_WYKRYCIA; c.lineWidth = 1;
        c.globalAlpha = 0.45; c.setLineDash([2, 6]); c.beginPath(); liniaNaEkranie(c, ax, ay, bx, by); c.stroke();
        c.setLineDash([]);
        if (r < 60) { c.globalAlpha = 0.1; c.beginPath(); c.arc(bx, by, r, 0, Math.PI * 2); c.fill(); }
        if (r < Math.max(szer, wys)) { c.globalAlpha = 0.4; c.beginPath(); c.arc(bx, by, r, 0, Math.PI * 2); c.stroke(); }
        c.restore();
        if (wykrycieNad === w.id) {
          c.font = '11px monospace'; c.fillStyle = KOLOR_WYKRYCIA;
          const eta = w.eta * 1000 > Date.now() ? `ETA ~${czasGry(w.eta)}` : 'wyjście szacowane';
          napis(c, `Wykryty skok · ${w.nazwa} · ${eta} · wykrył: ${w.wykryl}`, bx + Math.min(r, 40) + 6, by + 4);
        }
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
      for (const [x, alfa] of [[najechane, 0.7], [wybrane, 1]]) {
        const p = x?.planeta || x?.gwiazda;
        if (!p) continue;
        const u = skala >= PROG_UKLADU ? ukladNaEkranie(p) : null;
        let sx, sy, r = 9, opis = p.nazwa;
        if (x.gwiazda && u) {
          const gw = u.cykl.gwiazdy?.[0];
          [sx, sy] = [u.sx, u.sy];
          r = Math.max(9, Math.max(SLONCE_MIN_PX * Math.sqrt(gw?.rozmiar || 1), (gw?.promien_km || 696000) * u.skm) + 4);
          opis = `Gwiazda ${p.nazwa}${gw ? ` · ${gw.typ}` : ''}`;
        } else if (u) {
          [sx, sy] = [u.px, u.py];
          r = Math.max(9, u.rp + 4);
          opis = `${u.ksiezyc ? 'Księżyc' : 'Planeta'} ${p.nazwa}`;
        } else [sx, sy] = naEkran(p.x, p.y);
        if (r > Math.max(szer, wys) * 2) continue;
        c.globalAlpha = alfa;
        c.strokeStyle = kolory.akcent2;
        c.lineWidth = 1.5;
        c.strokeRect(sx - r, sy - r, r * 2, r * 2);
        c.font = `13px ${kroj}`;
        c.fillStyle = kolory.akcent2;
        napis(c, opis, sx + r + 4, sy + r + 9);
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
      // Ciała: w widoku układu słońce, planeta i księżyc to osobne cele (każde w swoim miejscu na ekranie);
      // przy małym zbliżeniu układ to jedna kropka — wtedy wybiera się planetę.
      let najblizsza = null, odl = 16, slonce = null;
      for (const p of P) {
        const u = skala >= PROG_UKLADU && Math.hypot(...naMape(sx, sy).map((v, i) => v - (i ? p.y : p.x))) < 3e-3 ? ukladNaEkranie(p) : null;
        if (u) {
          const dp = Math.hypot(u.px - sx, u.py - sy) - u.rp;
          if (dp < odl) { odl = dp; najblizsza = p; slonce = null; }
          if (!u.ksiezyc) {
            const gw = u.cykl.gwiazdy?.[0];
            const rs = Math.max(SLONCE_MIN_PX * Math.sqrt(gw?.rozmiar || 1), (gw?.promien_km || 696000) * u.skm);
            const ds = Math.hypot(u.sx - sx, u.sy - sy) - rs;
            if (ds < odl) { odl = ds; najblizsza = p; slonce = u; }
          }
          continue;
        }
        for (const [px, py] of [naEkran(p.x, p.y), pozycjaPlanety(p)]) {
          const d = Math.hypot(px - sx, py - sy);
          if (d < odl) { odl = d; najblizsza = p; slonce = null; }
        }
      }
      // Klik w samą tarczę ciała (albo tuż przy kropce) wybiera ciało — cel podróży — nawet gdy
      // na jego orbicie stoi flota; jednostkę wybiera się klikiem w jej znacznik.
      if (najblizsza && (!najblizszyObiekt || (odl <= 3 && odlObiektu > 2))) return slonce ? { gwiazda: najblizsza } : { planeta: najblizsza };
      if (najblizszyObiekt) return { obiekt: najblizszyObiekt };
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
            const miejsce = miejsceNaOrbicie(o, t) + (i - (lista.length - 1) / 2) * Math.min(10, 80 / lista.length);
            const r = rp + 9 + nr * 6, a = ekr(miejsce);
            const kolor = kolorObiektu(o);
            g.fillStyle = kolor; g.beginPath(); strzalka(g, cx + Math.cos(a) * r, cy + Math.sin(a) * r, kursTeraz(o, t), 6); g.fill();
          });
          const o = lista[0];
          const st = stronaOrbity(cykl, miejsceNaOrbicie(o, t), t);
          linie.push(`${o.flota?.nazwa || o.nazwa}${o.rozbity ? ' (wrak na powierzchni)' : ''}: strona ${st.dzien ? 'dzienna' : 'nocna'}${st.ksiezyc ? `, między planetą a: ${st.ksiezyc}` : ''} · orbita ${Math.round(miejsceNaOrbicie(o, t))}° · kurs ${Math.round(kursTeraz(o, t))}°`);
        });
        // plan przylotu: przerywany okrąg wybranej orbity, linia przylotu z kierunku floty, punkt wyjścia
        const planKarty = planSkoku?.planeta === p.nazwa ? planSkoku : null;
        if (planKarty && planKarty.wariant !== 'krawedz') {
          const pl = planKarty;
          const odl = { bliska: rp + 14, srednia: rp + 32, daleka: rp + 52 }[pl.wariant] ?? rp + 14;
          const a = ekr(pl.kierunek + 180); // przylot od strony, z której nadlatuje flota
          const kolor = pl.policzony ? 'rgba(255, 214, 120, 0.95)' : 'rgba(255, 214, 120, 0.45)';
          g.save(); g.strokeStyle = kolor; g.fillStyle = kolor; g.lineWidth = 1.2; g.setLineDash([4, 4]);
          if (!pl.reczny) { g.beginPath(); g.arc(cx, cy, odl, 0, Math.PI * 2); g.stroke(); }
          const koniec = pl.reczny ? rp + 8 : odl;
          const daleko = Math.hypot(W, H);
          g.beginPath(); g.moveTo(cx + Math.cos(a) * daleko, cy + Math.sin(a) * daleko); g.lineTo(cx + Math.cos(a) * koniec, cy + Math.sin(a) * koniec); g.stroke();
          g.setLineDash([]); g.beginPath(); strzalka(g, cx + Math.cos(a) * koniec, cy + Math.sin(a) * koniec, pl.kierunek, 6); g.fill();
          g.restore();
        } else if (planKarty) {
          // Krawędź układu: wyjście na brzegu całego układu, daleko poza kadrem planety — strzałka zatrzymuje
          // się na brzegu kadru (od strony nadlotu) z opisem; dokładny punkt pokazuje ramka z obiegiem planety.
          const pl = planKarty;
          const a = ekr(pl.kierunek + 180);
          const kolor = pl.policzony ? 'rgba(255, 214, 120, 0.95)' : 'rgba(255, 214, 120, 0.45)';
          const dx = Math.cos(a), dy = Math.sin(a);
          const t0 = Math.min(dx ? ((dx > 0 ? W - 8 : 8) - cx) / dx : Infinity, dy ? ((dy > 0 ? H - 8 : 8) - cy) / dy : Infinity);
          const ex = cx + dx * t0, ey = cy + dy * t0;
          g.save(); g.strokeStyle = kolor; g.fillStyle = kolor; g.lineWidth = 1.2; g.setLineDash([4, 4]);
          g.beginPath(); g.moveTo(ex + dx * 40, ey + dy * 40); g.lineTo(ex, ey); g.stroke();
          g.setLineDash([]); g.beginPath(); strzalka(g, ex, ey, pl.kierunek, 6); g.fill();
          g.font = '9px monospace'; g.textAlign = ex > W / 2 ? 'right' : 'left';
          g.fillText('wyjście: krawędź układu', Math.max(10, Math.min(W - 10, ex - dx * 12)), Math.max(12, Math.min(H - 6, ey - dy * 14)));
          g.restore();
        }
        // ramka: obieg planety wokół słońca
        g.strokeStyle = 'rgba(255,255,255,0.15)'; g.strokeRect(4, 4, 62, 62);
        g.fillStyle = '#ffd678'; g.beginPath(); g.arc(35, 35, 3.5, 0, Math.PI * 2); g.fill();
        g.strokeStyle = 'rgba(255,255,255,0.2)'; g.beginPath(); g.arc(35, 35, 24, 0, Math.PI * 2); g.stroke();
        const ap = ekr(u.katPlanety);
        g.fillStyle = '#6fa8d8'; g.beginPath(); g.arc(35 + Math.cos(ap) * 24, 35 + Math.sin(ap) * 24, 3, 0, Math.PI * 2); g.fill();
        if (planKarty?.wariant === 'krawedz') {
          // punkt wyjścia na brzegu układu (od strony nadlotu, względem słońca) i strzałka kursu
          const a = ekr(planKarty.kierunek + 180), bx = 35 + Math.cos(a) * 29, by = 35 + Math.sin(a) * 29;
          g.save(); g.strokeStyle = 'rgba(160,200,220,0.35)'; g.setLineDash([2, 3]); g.beginPath(); g.arc(35, 35, 29, 0, Math.PI * 2); g.stroke();
          g.setLineDash([]); g.fillStyle = 'rgba(255, 214, 120, 0.95)'; g.beginPath(); strzalka(g, bx, by, planKarty.kierunek, 5); g.fill(); g.restore();
        }
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
    // ---------- okienka krokowe (nawigacja floty) ----------
    // Kolejne widoki w tej samej ramce karty: „‹” wraca do poprzedniego kroku. Krok 1: co zrobić,
    // krok 2: wybór (orbita, lądowisko…), krok 3: szczegóły z komputera nawigacyjnego i rozkaz.
    function kroki() {
      const el = h('div.mapa-kroki');
      const stos = [];
      const pokaz = () => {
        wyczysc(el);
        const [tytul, tresc] = stos.at(-1);
        el.append(stos.length > 1 || tytul ? h('div.mapa-krok-glowa',
          stos.length > 1 ? h('button.mapa-wstecz', { type: 'button', 'aria-label': 'Wstecz', onclick: () => { stos.pop(); pokaz(); } }, '‹') : null,
          tytul ? h('b', tytul) : null) : null, tresc);
      };
      return {
        el,
        dalej(tytul, tresc) { stos.push([tytul, tresc]); pokaz(); },
        zamien(tytul, tresc) { stos[stos.length - 1] = [tytul, tresc]; pokaz(); },
        doPoczatku() { stos.splice(1); pokaz(); },
      };
    }
    const tabelaPlanu = (wiersze) => h('table.tabela.mapa-plan', wiersze.filter(Boolean).map(([k, v]) => h('tr', h('th', k), h('td', v))));
    const odlKm = (km) => (km >= KM_NA_AU * 0.1 ? `${liczba(km / KM_NA_AU, 2)} j.a.` : `${liczba(km)} km`);
    const odlTrasy = (km) => (km >= KM_NA_PC * 0.05 ? `${liczba(km / KM_NA_PC, 1)} pc` : odlKm(km));
    const blad = (e) => h('p.maly-tekst.mapa-blad', e.message || String(e));
    const liczy = () => h('p.slaby.maly-tekst.mapa-liczy', 'Komputer nawigacyjny liczy…');

    // Krok 3 skoku: plan z komputera (albo obliczenia mostka), potem rozkaz i „Skok w toku”.
    function krokSkoku(k, dane, poRozkazie) {
      const tresc = h('div', liczy());
      k.dalej('Skok w nadprzestrzeń', tresc);
      api('mapa_flota_rozkaz', { ...dane, plan: true }).then((w) => {
        const wykonaj = h('button.przycisk.glowny.mapa-krok-akcja', { type: 'button', onclick: async () => {
          wykonaj.disabled = true;
          try {
            const r = await api('mapa_flota_rozkaz', dane);
            const info = h('p.maly-tekst');
            k.zamien('Skok w toku', h('div', tabelaPlanu([['Wejście w nadprzestrzeń', czasGry(r.wejscie)], ['ETA', czasGry(r.eta)]]),
              h('div.mapa-krok-przyciski', przyciskPrzerwij(r.rozkaz_id, info)), info));
            poRozkazie?.(r);
            await pobierzPozycje();
          } catch (e) { wykonaj.disabled = false; tresc.append(blad(e)); }
        } }, 'Wykonaj skok');
        wyczysc(tresc).append(tabelaPlanu([
          [w.tryb === 'reczny' ? 'Obliczenia mostka' : 'Komputer', czasTrwania(w.obliczenia_s)],
          ['Obrót na kurs', czasTrwania(w.manewr_s)],
          w.punkt_s > 0 ? ['Do punktu skoku', czasTrwania(w.punkt_s)] : null,
          ['Lot', czasDlugi(w.lot_s)],
          ['Wejście', czasGry(w.wejscie)],
          ['ETA', czasGry(w.eta)],
          w.jednostki?.length ? ['Paliwo', w.jednostki.map((j) => `${j.nazwa}: ${j.paliwo}`).join(', ')] : null,
        ]), wykonaj);
      }).catch((e) => wyczysc(tresc).append(blad(e)));
    }

    // Krok 3 lotu podświetlnego: plan (dystans, prędkość, czas), „Leć”, potem „W locie” z „Przerwij lot”.
    function krokLotu(k, f, cel, czyLeci = () => true) {
      const jednostki = (f.jednostki || []).filter((j) => !j.rozkaz_id && !j.ladowisko_id && czyLeci(j));
      const tresc = h('div', liczy());
      k.dalej('Lot podświetlny', tresc);
      if (!jednostki.length) { wyczysc(tresc).append(h('p.slaby.maly-tekst', 'Żadna jednostka nie może teraz lecieć (skok, lądowisko).')); return; }
      const dane = { flota_id: f.id, jednostki: jednostki.map((j) => j.obiekt_id), ...cel };
      api('mapa_flota_lot', { ...dane, plan: true }).then((w) => {
        const lec = h('button.przycisk.glowny.mapa-krok-akcja', { type: 'button', onclick: async () => {
          lec.disabled = true;
          try {
            const r = await api('mapa_flota_lot', dane);
            const info = h('p.maly-tekst');
            k.zamien('W locie', h('div', tabelaPlanu([['Silniki', czasGry(r.start)], ['ETA', czasGry(r.eta)]]),
              h('div.mapa-krok-przyciski', przyciskPrzerwijLot(f, dane.jednostki, info)), info));
            await pobierzPozycje(); await odswiezFloty();
          } catch (e) { lec.disabled = false; tresc.append(blad(e)); }
        } }, 'Leć');
        wyczysc(tresc).append(tabelaPlanu([
          ['Dystans', odlTrasy(w.km)],
          ['Prędkość', w.w_ukladzie ? `${liczba(w.predkosc_kms)} km/s (w układzie)` : w.pc_doba ? `${liczba(w.pc_doba, 1)} pc/doba` : `${liczba(w.predkosc_kms)} km/s`],
          ['Obrót na kurs', czasTrwania(w.manewr_s)],
          ['Lot', czasDlugi(w.lot_s)],
          ['ETA', czasGry(w.eta)],
        ]), lec);
      }).catch((e) => wyczysc(tresc).append(blad(e)));
    }

    function przyciskPrzerwijLot(f, ids, info) {
      return h('button.przycisk.maly', { type: 'button', onclick: async () => {
        try { await api('mapa_lot_przerwij', { flota_id: f.id, jednostki: ids }); info.textContent = 'Lot przerwany.'; await pobierzPozycje(); await odswiezFloty(); }
        catch (e) { info.textContent = e.message; }
      } }, 'Przerwij lot');
    }

    // Wybór floty (gdy dowodzi się kilkoma) nad krokiem 1.
    function wyborFloty(floty, naZmiane) {
      if (floty.length < 2) return null;
      return h('select.mapa-krok-flota', { onchange: (e) => naZmiane(floty.find((f) => String(f.id) === e.target.value)) },
        floty.map((f) => h('option', { value: f.id }, f.nazwa)));
    }

    // Trwające skoki i loty floty (na górze kroku 1): ETA i przerwanie.
    function wTokuFloty(f) {
      const info = h('p.maly-tekst');
      const skoki = [...new Set((f.jednostki || []).filter((j) => j.rozkaz_id).map((j) => j.rozkaz_id))];
      const wLocie = (f.jednostki || []).filter((j) => j.lot && j.lot.do > teraz());
      if (!skoki.length && !wLocie.length) return null;
      return h('div.mapa-nawigacja-blok',
        skoki.length ? h('div', h('b', 'Skok w toku'), h('div.mapa-krok-przyciski', ...skoki.map((id) => przyciskPrzerwij(id, info)))) : null,
        wLocie.length ? h('div', h('b', `W locie → ${wLocie[0].lot.planeta || 'punkt'}`), h('div.slaby.maly-tekst', `ETA ${czasGry(wLocie[0].lot.do)}`),
          h('table.tabela', h('tr', h('th', 'Do celu'), zywa(() => {
            const o = obiektyMapy.find((x) => x.id === wLocie[0].obiekt_id);
            const l = o?.podswietlny;
            if (!l) return null;
            const cel = { x: l.x1, y: l.y1, k: l.k1 || (l.planeta ? kmWUkladzie(wgKlucza.get(norm(l.planeta))) : null) };
            return opisOdleglosci(pozycjaTeraz(o), cel);
          }))),
          h('div.mapa-krok-przyciski', przyciskPrzerwijLot(f, wLocie.map((j) => j.obiekt_id), info))) : null,
        info);
    }

    function sekcjaNawigacji(p) {
      const sekcja = h('div.mapa-nawigacja');
      api('mapa_floty_gracza').then((dane) => {
        const floty = dane.floty || [];
        if (!floty.length) return;
        let f = floty[0];
        const k = kroki();
        const krok1 = () => {
          const jednostki = f.jednostki || [];
          const wUkladzie = jednostki.filter((j) => norm(j.planeta || '') === p.klucz && !j.rozkaz_id);
          const naLadowisku = wUkladzie.filter((j) => j.ladowisko_id);
          const tutaj = wUkladzie.filter((j) => !j.ladowisko_id);
          const mogaLadowac = tutaj.filter((j) => Number(j.moze_ladowac) === 1 && (j.podejscie_teraz || 'bliska') === 'bliska');
          const pozaUkladem = jednostki.filter((j) => !j.rozkaz_id && Math.hypot(Number(j.x) - p.x, Number(j.y) - p.y) >= STREFA_UKLADU_PC);
          // W tym samym układzie, ale nie na orbicie tej planety: mikroskok na jej orbitę (automat).
          const obokWUkladzie = jednostki.filter((j) => !j.rozkaz_id && !j.ladowisko_id && norm(j.planeta || '') !== p.klucz
            && Math.hypot(Number(j.x) - p.x, Number(j.y) - p.y) < STREFA_UKLADU_PC);
          const doSkoku = [...pozaUkladem, ...obokWUkladzie];
          const wDrodze = jednostki.find((j) => j.rozkaz_id && j.cel_x != null && Math.hypot(j.cel_x - p.x, j.cel_y - p.y) < STREFA_UKLADU_PC);
          const automat = jednostki[0]?.automat !== false;
          const info = h('p.maly-tekst');
          const opcja = (napis, opis, akcja) => h('button.przycisk.mapa-krok-opcja', { type: 'button', onclick: akcja }, h('span', napis), opis ? h('small', opis) : null);
          const opcje = [];
          if (naLadowisku.length) {
            opcje.push(opcja('Start', `z: ${naLadowisku[0].ladowisko || 'lądowisko'}`, async () => {
              try {
                const w = await api('mapa_flota_start', { flota_id: f.id, jednostki: naLadowisku.map((j) => j.obiekt_id) });
                info.textContent = `Start. Bliska orbita ok. ${czasGry(w.orbita)}.`;
                await pobierzPozycje(); await odswiezFloty();
              } catch (e) { info.textContent = e.message; }
            }));
          }
          if (mogaLadowac.length) {
            opcje.push(opcja('Lądowanie', 'wybór lądowiska', () => {
              const lista = h('div.mapa-krok-lista', liczy());
              k.dalej('Lądowanie', lista);
              api('mapa_ladowiska', undefined, { planeta: p.nazwa }).then((d) => {
                wyczysc(lista).append(...(d.ladowiska || []).map((l) => opcja(l.nazwa, l.rodzaj, async () => {
                  try {
                    const w = await api('mapa_flota_ladowanie', { flota_id: f.id, jednostki: mogaLadowac.map((j) => j.obiekt_id), lokacja_id: l.id });
                    k.zamien('Lądowanie', h('p.maly-tekst', `Wylądowano: ${w.ladowisko}.`));
                    await pobierzPozycje(); await odswiezFloty();
                  } catch (e) { lista.append(blad(e)); }
                })));
                if (!(d.ladowiska || []).length) lista.append(h('p.slaby.maly-tekst', 'Nie znasz tu żadnego lądowiska.'));
              }).catch((e) => wyczysc(lista).append(blad(e)));
            }));
          }
          if (tutaj.length && STOCZNIE_MAPY.includes(p.klucz)) {
            // Stocznia naprawcza: jedno kliknięcie oddaje uszkodzone okręty floty z tej orbity do doku.
            opcje.push(opcja('Oddaj do naprawy', 'stocznia: prognoza dla każdego podzespołu', async () => {
              try {
                const w = await api('mapa_naprawa_zglos', { flota_id: f.id, jednostki: tutaj.map((j) => j.obiekt_id) });
                const n = w.naprawa;
                k.zamien('Naprawa', h('div', tabelaPlanu([['Stocznia', n?.stocznia || '—'], n ? ['Prognoza całości', czasGry(n.koniec)] : null]),
                  n ? h('ul.lista', n.naprawy.map((x) => h('li', `${x.nazwa}: ${x.stan === 'w_toku' ? 'w toku' : 'w kolejce'} · do ${czasGry(x.koniec)}`))) : null));
                await pobierzPozycje(); await odswiezFloty();
              } catch (e) { info.textContent = e.message; }
            }));
          }
          // Dok orbitalny (pierścień stoczniowy): wybór okrętu, potem automat albo sektor i stanowisko.
          const pierscien = (pozycja?.orbitalne || []).find((r) => norm(r.planeta) === p.klucz);
          const dokOkretu = (j) => obiektyMapy.find((x) => x.id === j.obiekt_id)?.dok || null;
          if (pierscien && tutaj.length) {
            const wDoku = tutaj.filter((j) => dokOkretu(j));
            const bezDoku = tutaj.filter((j) => !dokOkretu(j));
            const dokuj = (j) => {
              const lista = h('div.mapa-krok-lista', liczy());
              k.dalej(`Dokuj: ${j.obiekt_nazwa || 'okręt'}`, lista);
              api('doki', undefined, { obiekt: j.obiekt_id }).then((d) => {
                const wykonaj = async (stanowiskoId) => {
                  try {
                    const w = await api('dokuj', { obiekt_id: j.obiekt_id, stanowisko_id: stanowiskoId || 0 });
                    k.zamien('Dokowanie', tabelaPlanu([['Stanowisko', w.stanowisko], ['Trasa', `łukiem ${Math.abs(w.luk_deg)}° (${w.kierunek}), ok. ${liczba(w.km)} km`], ['W doku', czasGry(w.koniec)]]));
                    await pobierzPozycje(); await odswiezFloty();
                  } catch (e) { info.textContent = e.message; k.doPoczatku(); }
                };
                const wolneRazem = d.sektory.reduce((s, x) => s + x.wolne, 0);
                wyczysc(lista).append(
                  h('p.slaby.maly-tekst', `${d.pierscien.nazwa}: ${d.sektory.length} sektorów, wolnych stanowisk: ${wolneRazem}. Trasa łukiem po orbicie, krótszą stroną planety.`),
                  opcja('Automat', 'najbliższe wolne stanowisko naprawcze', () => wykonaj(0)),
                  ...d.sektory.map((s) => opcja(`Sektor ${s.litera}`, `wolne ${s.wolne} · budowa ${s.budowa} · zajęte ${s.naprawa} · ${Math.round(Math.abs(lukDeg(d.kat_okretu, s.kat)))}° łuku`, () => {
                    const wolne = s.stanowiska.filter((x) => x.stan === 'wolne');
                    k.dalej(`Sektor ${s.litera}`, h('div.mapa-krok-lista',
                      ...s.stanowiska.map((x) => x.stan === 'wolne'
                        ? opcja(`${x.oznaczenie} · wolne`, 'dokuj tutaj', () => wykonaj(x.id))
                        : h('div.slaby.maly-tekst', `${x.oznaczenie} · ${x.opis}${x.stan === 'budowa' ? ` (${x.postep}%)` : ''}`)),
                      wolne.length ? null : h('p.slaby.maly-tekst', 'W tym sektorze nie ma wolnych stanowisk.')));
                  })));
              }).catch((e) => wyczysc(lista).append(blad(e)));
            };
            if (bezDoku.length) {
              opcje.push(opcja('Dokuj', `${pierscien.nazwa} · wybór stanowiska albo automat`, () => {
                if (bezDoku.length === 1) return dokuj(bezDoku[0]);
                k.dalej('Dokuj: który okręt?', h('div.mapa-krok-lista', ...bezDoku.map((j) => opcja(j.obiekt_nazwa || 'okręt', null, () => dokuj(j)))));
              }));
            }
            for (const j of wDoku) {
              const d = dokOkretu(j);
              opcje.push(opcja(`Oddokuj ${j.obiekt_nazwa || ''}`.trim(), d.do && d.do > teraz() ? `w drodze do ${d.oznaczenie} · ${czasGry(d.do)}` : `stanowisko ${d.oznaczenie}`, async () => {
                try { await api('oddokuj', { obiekt_id: j.obiekt_id }); info.textContent = 'Okręt opuścił dok — bliska orbita.'; await pobierzPozycje(); await odswiezFloty(); }
                catch (e) { info.textContent = e.message; }
              }));
            }
          }
          if (tutaj.length) {
            const obecna = tutaj[0].podejscie_teraz || 'bliska';
            opcje.push(opcja('Zmiana orbity', `teraz: ${(NAZWY_WARIANTOW[obecna] || obecna).toLowerCase()}`
              + (tutaj[0].podlot_do > teraz() ? ` · dolot do ${czasGry(tutaj[0].podlot_do)}` : ''), () => {
              k.dalej('Zmiana orbity', h('div.mapa-krok-lista', ...Object.keys(NAZWY_WARIANTOW).filter((x) => x !== obecna).map((x) =>
                opcja(NAZWY_WARIANTOW[x], x === 'krawedz' ? 'lot podświetlny na brzeg układu' : null, async () => {
                  try {
                    const w = await api('mapa_flota_dolot', { flota_id: f.id, jednostki: tutaj.map((j) => j.obiekt_id), cel: x });
                    k.zamien('Zmiana orbity', tabelaPlanu([['Cel', NAZWY_WARIANTOW[x]], w.koniec > teraz() ? ['Koniec', czasGry(w.koniec)] : null]));
                    await pobierzPozycje(); await odswiezFloty();
                  } catch (e) { info.textContent = e.message; k.doPoczatku(); }
                }))));
            }));
          }
          if (doSkoku.length && !wDrodze && (pozaUkladem.length || automat)) {
            const glowna = doSkoku[0] || {};
            const kierunek = kierunekPrzylotu(glowna, p);
            const odbiorcy = doSkoku.map((j) => j.obiekt_id);
            const wariantySkoku = Object.entries(NAZWY_WARIANTOW).filter(([x]) => !(obokWUkladzie.length && x === 'krawedz'));
            const plan = (wariant, zmiany = {}) => { planSkoku = { planeta: p.nazwa, kierunek, wariant, reczny: !automat, policzony: false, ...zmiany }; odswiezUkladKarty?.(); };
            opcje.push(opcja(pozaUkladem.length ? 'Skok do układu' : 'Skok na orbitę', automat ? (pozaUkladem.length ? 'wybór orbity wyjścia' : 'mikroskok w układzie, wybór orbity') : 'obliczenia mostka', () => {
              if (automat) {
                k.dalej('Skok: orbita wyjścia', h('div.mapa-krok-lista', ...wariantySkoku.map(([x, n]) => opcja(n, null, () => {
                  plan(x);
                  krokSkoku(k, { flota_id: f.id, jednostki: odbiorcy, cel_planeta: p.nazwa, wariant: x }, () => plan(x, { policzony: true, aktywny: true }));
                }))));
              } else {
                const x = h('input', { type: 'number', step: '0.1', placeholder: 'X' });
                const y = h('input', { type: 'number', step: '0.1', placeholder: 'Y' });
                k.dalej('Skok: współrzędne', h('div',
                  h('p.slaby.maly-tekst', 'Komputer nawigacyjny nie odpowiada. Mostek musi wyliczyć skok sam: podaj współrzędne wyjścia.'),
                  h('div.mapa-nawigacja-xy', x, y),
                  h('button.przycisk.mapa-krok-akcja', { type: 'button', onclick: () => {
                    if (x.value === '' || y.value === '') return;
                    plan('daleka');
                    krokSkoku(k, { flota_id: f.id, jednostki: odbiorcy, x: Number(x.value), y: Number(y.value) });
                  } }, 'Sprawdź skok')));
              }
            }));
          }
          if (jednostki.some((j) => norm(j.planeta || '') !== p.klucz && !j.rozkaz_id)) {
            opcje.push(opcja('Lot podświetlny', `do ${p.nazwa}`, () => krokLotu(k, f, { cel_planeta: p.nazwa }, (j) => norm(j.planeta || '') !== p.klucz)));
          }
          if (wDrodze) {
            planSkoku = { planeta: p.nazwa, kierunek: Math.atan2(p.y - wDrodze.y, p.x - wDrodze.x) / RAD,
              wariant: wDrodze.wariant || 'daleka', reczny: !wDrodze.wariant, policzony: true, aktywny: true };
            odswiezUkladKarty?.();
          }
          return h('div', wyborFloty(floty, (nowa) => { f = nowa; k.zamien(null, krok1()); }), wTokuFloty(f),
            opcje.length ? h('div.mapa-krok-lista', ...opcje) : h('p.slaby.maly-tekst', 'Flota nie ma tu nic do zrobienia.'), info);
        };
        k.dalej(null, krok1());
        sekcja.append(h('div.slaby.maly-tekst', 'Nawigacja floty'), k.el);
      }).catch(() => {});
      return sekcja;
    }

    // Przerwanie skoku (z potwierdzeniem): w nadprzestrzeni grozi uszkodzeniem reaktora i hipernapędu.
    function przyciskPrzerwij(rozkazId, info) {
      return h('button.przycisk.maly', { onclick: dwaKroki('W nadprzestrzeni uszkodzi to reaktor i hipernapęd.', async () => {
        try {
          const w = await api('mapa_rozkaz_anuluj', { id: rozkazId });
          info.textContent = w.komunikat || 'Skok przerwany.';
          await pobierzPozycje(); await odswiezFloty();
          // Wyjście awaryjne: po przeliczeniu odśwież mapę (jednostka wychodzi z nadprzestrzeni).
          if (w.wyjscie) setTimeout(() => { pobierzPozycje().then(odswiezFloty).catch(() => {}); }, Math.max(1000, (w.wyjscie - teraz() + 1) * 1000));
        } catch (e) { info.textContent = e.message; }
      }) }, 'Przerwij skok');
    }

    // Czas trwania także w dniach i latach (lot podświetlny między układami trwa lata).
    const czasDlugi = (s) => (s >= 2 * 31557600 ? `${liczba(s / 31557600)} lat` : s >= 2 * 86400 ? `${liczba(s / 86400)} dni` : czasTrwania(s));

    // Blok lotu podświetlnego dla floty: trwający lot (przerwij) albo wyliczenie i rozkaz lotu do celu.
    // Karta punktu mapy dla dowódcy floty: krok 1 — skok albo lot; krok 3 — szczegóły i rozkaz.
    function sekcjaPunktu(pkt) {
      const sekcja = h('div.mapa-nawigacja');
      api('mapa_floty_gracza').then((dane) => {
        const floty = dane.floty || [];
        if (!floty.length) return;
        let f = floty[0];
        const cel = { x: pkt.x, y: pkt.y, ...(pkt.k ? { kx: pkt.k[0], ky: pkt.k[1] } : {}) };
        const k = kroki();
        const opcja = (napis, opis, akcja) => h('button.przycisk.mapa-krok-opcja', { type: 'button', onclick: akcja }, h('span', napis), opis ? h('small', opis) : null);
        const krok1 = () => h('div', wyborFloty(floty, (nowa) => { f = nowa; k.zamien(null, krok1()); }), wTokuFloty(f),
          h('div.mapa-krok-lista',
            opcja('Skok w nadprzestrzeń', 'szybko, z obliczeniami i obrotem', () => krokSkoku(k, { flota_id: f.id, ...cel })),
            opcja('Lot podświetlny', pkt.k ? 'w układzie: ostrożnie, minuty–godziny' : 'silniki podświetlne', () => krokLotu(k, f, cel))));
        k.dalej(null, krok1());
        sekcja.append(h('div.slaby.maly-tekst', 'Nawigacja floty'), k.el);
      }).catch(() => {});
      return sekcja;
    }

    // Pokład statku: pasażerowie i więźniowie (widzą osoby na pokładzie, dowódca lub właściciel i MG).
    // Kontakt radaru: dane z transpondera (mogą być fałszywe), list gończy z bazy sygnatur, udostępnienie pozycji
    // i analiza sygnatury silnika i osłon (6 h, tylko duże okręty; cel musi zostać w zasięgu).
    function sekcjaRadaru(o) {
      const r = o.radar;
      const status = h('div');
      const znaczki = [
        r.udostepniony ? h('span.znaczek-tu', 'Pozycja udostępniona') : null,
        r.zidentyfikowany ? h('span.znaczek-cel', 'Zidentyfikowany (analiza sygnatury)') : null,
        r.poszukiwany != null ? h('span.znaczek-cel', `Poszukiwany${r.poszukiwany ? ` · nagroda ${r.poszukiwany.toLocaleString('pl-PL')} kr` : ''}`) : null,
        o.id < 0 ? h('span.slaby.maly-tekst', r.trasa ? 'Ruch cywilny (stała trasa)' : 'Bez transpondera — trasa nieznana') : null,
      ].filter(Boolean);
      const analiza = r.analiza_do && teraz() < r.analiza_do
        ? h('p.slaby.maly-tekst', (() => { const el = h('span.mapa-zywe'); el._licz = () => `Analiza sygnatury: jeszcze ${czasTrwania(r.analiza_do - teraz())}`; el.textContent = el._licz(); return el; })())
        : r.mozna_analizowac ? h('button.przycisk.maly', { onclick: async (e) => {
          e.currentTarget.disabled = true;
          try {
            const d = await api('radar_analiza', { cel: o.id });
            r.analiza_do = d.koniec; r.mozna_analizowac = false;
            wybierz(wybrane);
          } catch (err) { wyczysc(status).append(h('div.komunikat.blad', err.message)); e.currentTarget.disabled = false; }
        } }, 'Analiza sygnatury (6 h)') : null;
      return h('div.mapa-kontakt',
        h('div.slaby.maly-tekst', 'Kontakt radaru'),
        h('table.tabela',
          h('tr', h('th', 'Transponder'), h('td', r.transponder || '—')),
          h('tr', h('th', 'Frakcja'), h('td', r.frakcja || 'brak')),
          r.trasa ? h('tr', h('th', 'Trasa'), h('td', r.trasa)) : null,
          r.ladunek ? h('tr', h('th', 'Ładunek (deklarowany)'), h('td', r.ladunek)) : null,
          r.faza ? h('tr', h('th', 'Teraz'), h('td', r.faza)) : null),
        znaczki.length ? h('div.mapa-znaczki', ...znaczki) : null,
        analiza, status);
    }

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
      if (obiekt && waski()) panelFlot.hidden = true;
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
            o.na_ladowisku ? wiersz('Na lądowisku', o.na_ladowisku + (o.geo ? ` · ${Math.abs(o.geo.szer).toFixed(1)}°${o.geo.szer >= 0 ? 'N' : 'S'} ${Math.abs(o.geo.dl).toFixed(1)}°${o.geo.dl >= 0 ? 'E' : 'W'}` : '')) : null,
            o.atmosfera && o.atmosfera.do > teraz() ? wiersz('W atmosferze', `${o.atmosfera.rodzaj === 'start' ? 'start i wznoszenie' : 'lądowanie'} · do ${czasGry(o.atmosfera.do)}`) : null,
            o.dok ? wiersz('Dok', o.dok.do && o.dok.do > teraz() ? `lot łukiem do stanowiska ${o.dok.oznaczenie} · do ${czasGry(o.dok.do)}` : `stanowisko ${o.dok.oznaczenie}`) : null,
            wiersz('Współrzędne', `${liczba(o.x, 1)} · ${liczba(o.y, 1)}`),
            o.opis ? wiersz('Opis', o.opis) : null),
          o.flota ? h('div.mapa-obiekt-flota', h('strong', o.flota.nazwa), o.flota.grupa ? h('span.slaby', o.flota.grupa) : null,
            h('span', `Dowódca: ${o.flota.dowodca || 'nieprzypisany'}`),
            o.flota.tie_liczba == null ? null : h('span', `${o.flota.tie_liczba} myśliwców TIE`)) : null,
          o.podswietlny && teraz() < o.podswietlny.do ? h('p.slaby.maly-tekst', `Lot podświetlny → ${o.podswietlny.planeta || `${liczba(o.podswietlny.x1, 1)} · ${liczba(o.podswietlny.y1, 1)}`} · ETA ${czasGry(o.podswietlny.do)}`) : null,
          o.radar ? sekcjaRadaru(o) : null,
          o.typ !== 'postac' && !o.radar ? sekcjaPokladu(o) : null);
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
            odleglosc !== null || (mojZnacznik() && p !== tuPlaneta) ? h('tr', h('th', 'Odległość od ciebie'),
              zywa(() => opisOdleglosci(pozycjaTeraz(mojZnacznik()), { x: p.x, y: p.y, k: kmWUkladzie(p) }) ?? (odleglosc !== null ? `${liczba(odleglosc)} pc` : null))) : null,
            wiersz('Grawitacja', nieznane(p.g, `${liczba(p.g, 2)} G`)),
            wiersz('Słońca · księżyce', nieznane(p.doba, `${p.slonca} · ${p.ksiezyce}`)),
            wiersz('Doba · rok', nieznane(p.doba, `${liczba(p.doba, 1)} h · ${liczba(p.rok, 1)} dni`)),
            p.srednica > 0 ? wiersz('Średnica', `${liczba(p.srednica)} km`) : null),
          (() => {
            // Lokacje orbitalne planety: pierścień stoczniowy i obłożenie doków.
            const r = (pozycja?.orbitalne || []).find((x) => norm(x.planeta) === p.klucz);
            if (!r) return null;
            const n = [0, 0, 0];
            for (const s of r.stanowiska) n[s[2]]++;
            return h('div.mapa-statki', h('div.slaby.maly-tekst', 'Orbita'), h('div', h('b', r.nazwa)),
              h('div.slaby.maly-tekst', `${liczba(r.wysokosc_km)} km nad powierzchnią · doki ISD: ${r.stanowiska.length} — budowa ${n[0]}, zajęte ${n[1]}, wolne ${n[2]}`));
          })(),
          listaObecnych(p),
          sekcjaNawigacji(p),
          widokUkladu(p),
          tutaj.length ? h('div.mapa-statki', h('div.slaby.maly-tekst', 'Statki twoich firm'),
            h('ul.lista', tutaj.map((s) => h('li', h('b', s.nazwa), h('span.slaby', ` · ${s.firma}`),
              s.w_locie ? h('span.slaby', s.cel ? ` · w nadprzestrzeni → ${s.cel}` : ' · na orbicie') : null)))) : null);
      } else if (obiekt.gwiazda) {
        const p = obiekt.gwiazda;
        const cykl = cyklPlanety(p);
        const gw = cykl?.gwiazdy || [];
        // Punkt docelowy: 30 promieni gwiazdy od niej (najmniej 0,05 j.a.), od strony kamery/planety.
        const u = ukladNaEkranie(p);
        const promien = gw[0]?.promien_km || 696000;
        const odlKm = Math.max(promien * 30, KM_NA_AU * 0.05);
        const a = u ? Math.atan2(-(u.py - u.sy), u.px - u.sx) : 0;
        const kCel = [Math.round(Math.cos(a) * odlKm), Math.round(Math.sin(a) * odlKm)];
        karta.append(zamknij,
          h('h2', gw.length > 1 ? `Gwiazdy układu ${p.nazwa}` : `Gwiazda układu ${p.nazwa}`),
          h('div.mapa-znaczki', h('span.znaczek-cel', 'Gwiazda')),
          h('table.tabela',
            ...(gw.length ? gw.map((g0, i) => wiersz(gw.length > 1 ? `Gwiazda ${i + 1}` : 'Klasa', `${g0.typ} · ${g0.nazwa}`)) : [wiersz('Klasa', 'brak danych')]),
            gw[0]?.temperatura ? wiersz('Temperatura', `${liczba(gw[0].temperatura)} K`) : null,
            gw[0]?.promien_km ? wiersz('Promień', `${liczba(gw[0].promien_km)} km`) : null,
            wiersz('Współrzędne', `${liczba(p.x, 1)} · ${liczba(p.y, 1)}`)),
          h('p.slaby.maly-tekst', `Cel przy gwieździe: punkt ${liczba(odlKm / KM_NA_AU, 2)} j.a. od niej (bliżej nie pozwala osłona termiczna).`),
          h('div.rzad', { style: 'margin-top:8px;flex-wrap:wrap' },
            h('button.przycisk.maly', { onclick: () => wybierz({ punkt: { x: p.x, y: p.y, k: kCel, uklad: p } }) }, 'Wyznacz cel przy gwieździe'),
            h('button.przycisk.maly.drugi', { onclick: () => wybierz({ planeta: p }) }, `Planeta ${p.nazwa}`)));
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
            moj ? h('tr', h('th', 'Odległość od ciebie'), zywa(() => opisOdleglosci(pozycjaTeraz(mojZnacznik()), { x, y, k: k || null }) ?? (odl !== null ? `${liczba(odl, 1)} pc` : null))) : null),
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
          const klucz = (x) => x?.obiekt || x?.szlak || (x?.gwiazda ? `g:${x.gwiazda.nazwa}` : x?.planeta);
          const zmienilSieCel = klucz(n) !== klucz(najechane);
          if (zmienilSieCel) { najechane = n; pokazPodpowiedz(n, e.offsetX, e.offsetY); odswiez(); }
          else if (n) { podpowiedz.style.left = `${Math.min(Math.max(8, e.offsetX + 16), Math.max(8, szer - 340))}px`; podpowiedz.style.top = `${Math.min(Math.max(68, e.offsetY + 14), Math.max(68, wys - 210))}px`; }
          plotno.style.cursor = n ? 'pointer' : 'grab';
          const wk = (pozycja?.wykrycia || []).find((w) => { const [bx, by] = naEkran(w.x, w.y); return Math.hypot(bx - e.offsetX, by - e.offsetY) < 14; });
          if ((wk?.id ?? null) !== wykrycieNad) { wykrycieNad = wk?.id ?? null; odswiez(); }
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
          : uklad ? (wSlonce ? { gwiazda: uklad } : { planeta: uklad }) : { punkt: { x: Math.round(mx * 10) / 10, y: Math.round(my * 10) / 10 } };
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
      const limit = mnoznik > 1 ? Math.max(skala, limitGwiazdy(sx, sy)) : Infinity;
      skala = Math.max(0.005, Math.min(ZOOM_MAKS, limit, skala * mnoznik));
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
        h('div.mapa-nawigacja-przyciski.mapa-obrot', przesun(15), przesun(45), przesun(-15), przesun(-45)),
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
        // Wąska ramka (jak karta nawigacji, lustrzanie po lewej): sekcje zwinięte, rozwijane dotknięciem.
        const sekcjaFloty = (tytul, otwarta, ...tresc) => h('details.mapa-flota-sekcja', { open: otwarta }, h('summary', tytul), ...tresc);
        const skoki = [...new Set(flota.jednostki.filter((j) => j.rozkaz_id).map((j) => j.rozkaz_id))];
        const promy = flota.jednostki.filter((j) => j.okret_macierzysty);
        panelFlot.append(h('article.mapa-flota-karta', h('h3', flota.nazwa), h('p.slaby.maly-tekst', `Dowódca: ${flota.dowodca_efektywny || 'nieprzypisany'}`), stan,
          skoki.length ? h('div.mapa-krok-przyciski', ...skoki.map((id) => przyciskPrzerwij(id, info))) : null,
          sekcjaFloty(`Jednostki (${flota.jednostki.length})`, true, defaultEskorta, wybor),
          sekcjaFloty('Kurs', false, tarcza,
            h('p.slaby.maly-tekst', 'Kurs to kierunek dziobu: 0° = wschód, 90° = północ. Przed skokiem flota sama obraca się na kurs skoku.')),
          sekcjaFloty('Skok na współrzędne', false,
            h('p.slaby.maly-tekst', 'Skok do planety albo punktu w układzie: kliknij go na mapie. Tu: współrzędne albo inna jednostka.'),
            h('div.mapa-flota-form', x, y, cel, rozkaz)),
          promy.length ? sekcjaFloty('Hangar', false, h('div.mapa-krok-przyciski', ...promy.map((j) => przyciskHangaru(j, info)))) : null,
          sekcjaFloty('Podział na grupy', false, h('div.mapa-flota-form', nazwaGrupy, podziel)),
          sekcjaFloty('Wskaźniki pokładowe', false, panelZgod({ filtr: (o) => flota.jednostki.some((j) => `okret:${j.obiekt_id}` === o.klucz) })),
          info));
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
    // Pamięć widoku na czas logowania (sessionStorage, klucz konta; wylogowanie czyści — js/system.js).
    const kluczWidoku = `hololink.mapa.widok.${ctx.uzytkownik?.id ?? ctx.uzytkownik?.postac ?? ''}`;
    let widokGotowy = false;
    function czytajWidok() {
      try {
        const v = JSON.parse(sessionStorage.getItem(kluczWidoku) || 'null');
        return v && [v.skala, v.camX, v.camY].every(Number.isFinite) && v.skala > 0 ? v : null;
      } catch { return null; }
    }
    function zapiszWidok() {
      if (!widokGotowy) return;
      try { sessionStorage.setItem(kluczWidoku, JSON.stringify({ skala, camX, camY, camKx, camKy })); } catch { /* bez pamięci */ }
    }
    const zapisWidoku = setInterval(zapiszWidok, 1500);

    wczytajDane(String(ctx.uzytkownik?.login ?? ctx.uzytkownik?.id ?? '')).then(async (d) => {
      D = d;
      ustawDodatkowe([]);
      rozmiar();
      await pobierzPozycje();
      // Zoom i kadr z bieżącego logowania (osobno dla każdego konta); bez zapisu — moja pozycja albo galaktyka.
      const zapisany = czytajWidok();
      if (zapisany) {
        skala = Math.max(0.005, Math.min(ZOOM_MAKS, zapisany.skala));
        camX = zapisany.camX; camY = zapisany.camY; camKx = zapisany.camKx || 0; camKy = zapisany.camKy || 0;
      } else if (tuPlaneta) {
        skala = 0.25; ustawSrodek(tuPlaneta.x, tuPlaneta.y);
      } else {
        calaGalaktyka();
      }
      widokGotowy = true;
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
      zapiszWidok();
      clearInterval(zapisWidoku);
      clearInterval(zegarZywych);
      sledzRozmiar.disconnect();
      window.removeEventListener('datapad:alfabet', zmianaAlfabetu);
      document.fonts?.removeEventListener('loadingdone', krojGotowy);
    };
  },
};

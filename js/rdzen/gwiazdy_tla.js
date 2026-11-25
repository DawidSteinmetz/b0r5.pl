// Gwiazdy tła z ziarna: ok. 3,2 mln gwiazd w całej galaktyce, niczego nie zapisujemy ani nie przesyłamy.
// Każdą gwiazdę wyznacza (kafel tx, ty, numer i) i zawsze daje tę samą pozycję, klasę i oznaczenie.
//
// Zasady zgodności (serwer PHP musi kiedyś liczyć dokładnie to samo, np. przy katalogowaniu z bliska):
// - losowość wyłącznie z hash32() na liczbach całkowitych 32-bit (w PHP: mnożenie z maską & 0xFFFFFFFF);
// - pozycja = róg kafla + (hash / 2^32) · KAFEL — dzielenie przez potęgę dwójki jest dokładne;
// - liczba gwiazd w kaflu = floor(λ) + (u < frac(λ)), λ = K · gęstość(środek kafla); gęstość używa exp/cos/atan2,
//   więc różnica w ostatnim bicie mogłaby zmienić liczbę tylko, gdy λ trafi o 1 ulp w całość (praktycznie nigdy);
// - stałe poniżej są częścią „wersji generatora” (GWIAZDY_WERSJA). Zmiana którejkolwiek przesuwa gwiazdy,
//   więc wymaga nowej wersji, a skatalogowane wpisy zachowują wersję, z którą powstały.

export const GWIAZDY_WERSJA = 1;
const ZIARNO = 0x484c4b31; // „HLK1”

// Siatka galaktyki jak na mapach astronawigacyjnych: kolumny A–Z (1500 pc), wiersze 1–24 (1800 pc) od północy.
// Coruscant (0, 0) wypada w L-9. Kwadrat siatki dzieli się na 5 × 6 kafli po 300 pc (numery 01–30).
const X0 = -17250, Y0 = 15300, KOL_PC = 1500, WIER_PC = 1800, KAFEL = 300;
const TX = 26 * (KOL_PC / KAFEL), TY = 24 * (WIER_PC / KAFEL); // 130 × 144 kafli
const KAF_W_KOL = KOL_PC / KAFEL, KAF_W_WIER = WIER_PC / KAFEL;

// Normalizacja: suma λ po wszystkich kaflach = 3 200 000 (policzone raz, wpisane na stałe).
const K = 1193.755914340651;

const mix = (x) => {
  x ^= x >>> 16; x = Math.imul(x, 0x7feb352d);
  x ^= x >>> 15; x = Math.imul(x, 0x846ca68b);
  x ^= x >>> 16;
  return x >>> 0;
};
export const hash32 = (a, b, c, d) => mix((ZIARNO ^ mix((a + mix((b + mix((c + mix(d)) | 0)) | 0)) | 0)) >>> 0);
const u01 = (h) => h / 4294967296;

// Szum wartości na siatce 1500 pc (skupiska i pustki), wygładzony.
function szum(x, y) {
  const gx = x / 1500, gy = y / 1500;
  const ix = Math.floor(gx), iy = Math.floor(gy);
  const fx = gx - ix, fy = gy - iy;
  const sx = fx * fx * (3 - 2 * fx), sy = fy * fy * (3 - 2 * fy);
  const w = (a, b) => u01(hash32(a, b, -1, -1));
  const g = w(ix, iy) + (w(ix + 1, iy) - w(ix, iy)) * sx;
  const d = w(ix, iy + 1) + (w(ix + 1, iy + 1) - w(ix, iy + 1)) * sx;
  return g + (d - g) * sy;
}

// Gęstość (bez jednostek): zgrubienie Głębokiego Jądra, dysk wykładniczy z dwoma ramionami spiralnymi, skupiska, brzeg.
const CX = 600, CY = 300, TAN_SKRETU = Math.tan(16 * Math.PI / 180);
export function gestosc(x, y) {
  const dx = x - CX, dy = y - CY, r = Math.hypot(dx, dy);
  const zgrubienie = 6 * Math.exp(-((r / 1800) ** 2));
  const faza = 2 * (Math.atan2(dy, dx) - Math.log(Math.max(r, 500) / 500) / TAN_SKRETU);
  const ramie = ((1 + Math.cos(faza)) / 2) ** 2;
  const dysk = Math.exp(-r / 6500) * (0.35 + 1.3 * ramie) * (0.6 + 0.8 * szum(x, y));
  const brzeg = r > 21000 ? Math.exp(-(((r - 21000) / 1500) ** 2)) : 1;
  return (zgrubienie + dysk) * brzeg;
}

const lambdaKafla = (tx, ty) => gestosc(X0 + (tx + 0.5) * KAFEL, Y0 - (ty + 0.5) * KAFEL);
const liczbyKafli = new Int32Array(TX * TY).fill(-1);
export function liczbaWKaflu(tx, ty) {
  if (tx < 0 || ty < 0 || tx >= TX || ty >= TY) return 0;
  const j = ty * TX + tx;
  if (liczbyKafli[j] < 0) {
    const l = K * lambdaKafla(tx, ty);
    liczbyKafli[j] = Math.floor(l) + (u01(hash32(tx, ty, -2, 0)) < l - Math.floor(l) ? 1 : 0);
  }
  return liczbyKafli[j];
}

// Klasy widmowe (udziały), kolor i promień (km) — oznaczenia jak w PROMIEN_GWIAZDY_KM mapy.
const KLASY = [
  ['O', 0.0005, '#9bb0ff', 6960000], ['B', 0.003, '#aabfff', 3480000], ['A', 0.01, '#cad7ff', 1250000],
  ['F', 0.03, '#f8f7ff', 905000], ['G', 0.076, '#fff4ea', 696000], ['K', 0.12, '#ffd2a1', 557000],
  ['RG', 0.01, '#ff9b6a', 69600000], ['WD', 0.05, '#e0e8ff', 8400], ['M', 1, '#ffb38a', 278000],
];
const PROGI = []; { let s = 0; for (const k of KLASY) { s = Math.min(1, s + k[1]); PROGI.push(s); } }

export function gwiazda(tx, ty, i) {
  const x = X0 + tx * KAFEL + u01(hash32(tx, ty, i, 1)) * KAFEL;
  const y = Y0 - ty * KAFEL - u01(hash32(tx, ty, i, 2)) * KAFEL;
  const u = u01(hash32(tx, ty, i, 3));
  let k = 0; while (u >= PROGI[k]) k++;
  const [klasa, , kolor, promienKm] = KLASY[k];
  const pod = hash32(tx, ty, i, 4) % 10;
  const typ = klasa === 'RG' ? `K${pod} III` : klasa === 'WD' ? `DA${pod}` : `${klasa}${pod} V`;
  return { tx, ty, i, x, y, klasa, typ, kolor, promienKm };
}

// Oznaczenie katalogowe: kwadrat siatki (litera + wiersz), kafel w kwadracie 01–30, numer gwiazdy w kaflu.
export function oznaczenie(s) {
  const kol = String.fromCharCode(65 + Math.floor(s.tx / KAF_W_KOL));
  const wiersz = Math.floor(s.ty / KAF_W_WIER) + 1;
  const kafel = (s.ty % KAF_W_WIER) * KAF_W_KOL + (s.tx % KAF_W_KOL) + 1;
  return `ISK ${kol}${wiersz}-${String(kafel).padStart(2, '0')}·${String(s.i).padStart(4, '0')}`;
}

// Rysowanie jako szum tła. v: { naMape, naEkran, skala (px/pc), szer, wys, napis(g, tekst, x, y), kroj }.
// Przy oddaleniu rysujemy deterministyczną próbkę każdego kafla (pierwsze k gwiazd), najwyżej LIMIT punktów.
const LIMIT = 30000, PODPISY = 60, KM_NA_PC = 30856775814913.7;
export function rysujGwiazdyTla(g, v) {
  const [ax, ay] = v.naMape(0, 0), [bx, by] = v.naMape(v.szer, v.wys);
  const tx0 = Math.max(0, Math.floor((Math.min(ax, bx) - X0) / KAFEL)), tx1 = Math.min(TX - 1, Math.floor((Math.max(ax, bx) - X0) / KAFEL));
  const ty0 = Math.max(0, Math.floor((Y0 - Math.max(ay, by)) / KAFEL)), ty1 = Math.min(TY - 1, Math.floor((Y0 - Math.min(ay, by)) / KAFEL));
  if (tx0 > tx1 || ty0 > ty1) return;
  // Udział kafla w ekranie (dla kafli na brzegu), żeby próbka nie zależała od tego, co poza ekranem.
  let suma = 0;
  for (let ty = ty0; ty <= ty1; ty++) for (let tx = tx0; tx <= tx1; tx++) suma += liczbaWKaflu(tx, ty);
  if (!suma) return;
  const f = Math.min(1, LIMIT / suma);
  const kmNaPx = KM_NA_PC / v.skala;
  const wgKoloru = new Map(), widoczne = [];
  for (let ty = ty0; ty <= ty1; ty++) {
    for (let tx = tx0; tx <= tx1; tx++) {
      const n = liczbaWKaflu(tx, ty);
      const k = f >= 1 ? n : Math.round(n * f);
      for (let i = 0; i < k; i++) {
        const s = gwiazda(tx, ty, i);
        const [sx, sy] = v.naEkran(s.x, s.y);
        if (sx < -2 || sy < -2 || sx > v.szer + 2 || sy > v.wys + 2) continue;
        s.sx = sx; s.sy = sy;
        if (!wgKoloru.has(s.kolor)) wgKoloru.set(s.kolor, []);
        wgKoloru.get(s.kolor).push(s);
        if (f >= 1) widoczne.push(s);
      }
    }
  }
  // Jasność: z daleka szum (półprzezroczysty piksel), z bliska wyraźne punkty, w skali układu prawdziwa tarcza.
  g.save();
  g.globalAlpha = f < 1 ? 0.45 : 0.75;
  for (const [kolor, lista] of wgKoloru) {
    g.fillStyle = kolor;
    for (const s of lista) {
      const r = Math.max(f < 1 ? 0.5 : 0.8, s.promienKm / kmNaPx);
      // Pełny piksel (bez wygładzania), inaczej punkt rozmywa się na cztery sąsiednie i znika.
      if (r <= 1) g.fillRect(Math.round(s.sx), Math.round(s.sy), 1, 1);
      else { g.beginPath(); g.arc(s.sx, s.sy, r, 0, Math.PI * 2); g.fill(); }
    }
  }
  g.restore();
  // Ciąg znaków dopiero przy dużym zbliżeniu (mało gwiazd na ekranie).
  if (f >= 1 && widoczne.length <= PODPISY) {
    g.save();
    g.font = `10px ${v.kroj}`;
    g.globalAlpha = 0.8;
    for (const s of widoczne) {
      const r = Math.max(0.8, s.promienKm / kmNaPx);
      v.napis(g, `${oznaczenie(s)} · ${s.typ}`, s.sx + r + 5, s.sy + 3);
    }
    g.restore();
  }
}

// Mapa galaktyki: planety, regiony i szlaki nadprzestrzenne. Pokazuje, gdzie jest postać,
// dokąd leci i gdzie stoją statki jej firm. Dane astronawigacyjne są pobierane z SQL
// przez API i buforowane przez service worker do pracy bez zasięgu.

import { h, wyczysc } from '../rdzen/narzedzia.js';
import { api } from '../rdzen/api.js';

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
  // [nazwa, region, x, y, grawitacja, słońca, księżyce, doba, rok, średnica]
  const planety = surowePlanety.map(([nazwa, r, x, y, g, slonca, ksiezyce, doba, rok, srednica]) => ({
    nazwa, region: REGIONY[r] || '', x, y, g, slonca, ksiezyce, doba, rok, srednica, klucz: norm(nazwa),
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
    const wsp = h('div.mapa-wsp');
    const ladowanie = h('div.mapa-ladowanie', 'Pobieranie danych astronawigacyjnych...');
    const przyciskSzlaki = h('button.przycisk.maly.drugi', { onclick: () => { szlakiWidoczne = !szlakiWidoczne; przyciskSzlaki.classList.toggle('wl', szlakiWidoczne); odswiez(); } }, 'Szlaki');
    przyciskSzlaki.classList.add('wl');
    const przyciskTu = h('button.przycisk.maly.drugi', { onclick: () => { if (tuPlaneta) przelec(tuPlaneta.x, tuPlaneta.y, 0.6, () => wybierz({ planeta: tuPlaneta })); } }, 'Moja pozycja');
    const przyciskCala = h('button.przycisk.maly.drugi', { onclick: () => calaGalaktyka(true) }, 'Cała galaktyka');

    w.append(plotno,
      h('div.mapa-gora', h('div.mapa-szukajka', szukaj, wyniki), h('div.mapa-przyciski', przyciskTu, przyciskCala, przyciskSzlaki)),
      karta, wsp, ladowanie);

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

    // Widok: ekran x = X·skala + ox, ekran y = −Y·skala + oy (północ galaktyki u góry).
    let skala = 0.03, ox = 0, oy = 0, szer = 0, wys = 0, dpr = 1;
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
    let brudna = true;
    let ramka = 0;
    let animacja = null;

    const naEkran = (x, y) => [x * skala + ox, -y * skala + oy];
    const naMape = (sx, sy) => [(sx - ox) / skala, -(sy - oy) / skala];
    const odswiez = () => { brudna = true; };

    function rozmiar() {
      const r = plotno.getBoundingClientRect();
      dpr = Math.min(2, window.devicePixelRatio || 1);
      // Zachowaj środek widoku przy obrocie ekranu.
      const [sx, sy] = szer ? naMape(szer / 2, wys / 2) : [0, 0];
      szer = r.width; wys = r.height;
      for (const p of [plotno, warstwa]) { p.width = Math.round(szer * dpr); p.height = Math.round(wys * dpr); }
      if (D) { ox = szer / 2 - sx * skala; oy = wys / 2 + sy * skala; }
      odswiez();
    }

    function calaGalaktyka(plynnie = false) {
      if (!D) return;
      let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
      for (const p of D.planety) { minX = Math.min(minX, p.x); maxX = Math.max(maxX, p.x); minY = Math.min(minY, p.y); maxY = Math.max(maxY, p.y); }
      const s = Math.min(szer / ((maxX - minX) * 1.08), wys / ((maxY - minY) * 1.08));
      const cx = (minX + maxX) / 2, cy = (minY + maxY) / 2;
      if (plynnie) return przelec(cx, cy, s);
      skala = s; ox = szer / 2 - cx * s; oy = wys / 2 + cy * s;
      odswiez();
    }

    // Płynny przelot do punktu mapy.
    function przelec(x, y, docelowa, potem) {
      const [x0, y0] = naMape(szer / 2, wys / 2);
      const s0 = skala;
      const t0 = performance.now();
      animacja = (t) => {
        const p = Math.min(1, Math.max(0, (t - t0) / 700));
        const e = 1 - (1 - p) ** 3;
        // Skala w logarytmie, żeby przybliżanie wyglądało równo; środek widoku sunie do celu.
        skala = Math.exp(Math.log(s0) + (Math.log(docelowa) - Math.log(s0)) * e);
        const cx = x0 + (x - x0) * e, cy = y0 + (y - y0) * e;
        ox = szer / 2 - cx * skala;
        oy = wys / 2 + cy * skala;
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
        g.moveTo(p[0] * skala + ox, -p[1] * skala + oy);
        for (let i = 2; i < p.length; i += 2) g.lineTo(p[i] * skala + ox, -p[i + 1] * skala + oy);
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
      if (skala < 0.12) return;
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
        g.fillText(p.nazwa, sx + r + 4, sy + 4);
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
      const [sx, sy] = naEkran(p.x, p.y);
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
      if (tuPlaneta) znacznik(tuPlaneta, kolory.akcent, 'Jesteś tutaj', (t % 1800) / 1800);
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
      let najblizsza = null, odl = 16;
      for (const p of P) {
        const [px, py] = naEkran(p.x, p.y);
        const d = Math.hypot(px - sx, py - sy);
        if (d < odl) { odl = d; najblizsza = p; }
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

    function wybierz(obiekt) {
      wybrane = obiekt;
      wyczysc(karta);
      karta.hidden = !obiekt;
      if (!obiekt) return;
      const wiersz = (k, v) => h('tr', h('th', k), h('td', v));
      const nieznane = (v, tekst) => (v ? tekst : 'brak danych');
      const zamknij = h('button.mapa-zamknij', { 'aria-label': 'Zamknij', onclick: () => wybierz(null) }, '×');
      if (obiekt.planeta) {
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
          tutaj.length ? h('div.mapa-statki', h('div.slaby.maly-tekst', 'Statki twoich firm'),
            h('ul.lista', tutaj.map((s) => h('li', h('b', s.nazwa), h('span.slaby', ` · ${s.firma}`),
              s.w_locie ? h('span.slaby', s.cel ? ` · w nadprzestrzeni → ${s.cel}` : ' · na orbicie') : null)))) : null);
      } else {
        const s = obiekt.szlak;
        karta.append(zamknij,
          h('h2', s.nazwa),
          h('table.tabela',
            wiersz('Klasa', s.z <= 1 ? 'szlak główny' : s.z <= 3 ? 'szlak drugorzędny' : 'szlak lokalny'),
            wiersz('Długość', `${liczba(s.dl)} pc`)));
      }
    }

    // ---------- wyszukiwarka ----------

    function szukajTeraz() {
      const q = norm(szukaj.value);
      wyczysc(wyniki);
      if (!D || q.length < 2) { wyniki.hidden = true; return; }
      const zaczyna = (k) => k.startsWith(q);
      const planety = P.filter((p) => p.klucz.includes(q)).sort((a, b) => zaczyna(b.klucz) - zaczyna(a.klucz)).slice(0, 7);
      const szlaki = D.szlaki.filter((s) => norm(s.nazwa).includes(q)).slice(0, 3);
      const pozycje = [
        ...planety.map((p) => h('li', { onclick: () => { przelec(p.x, p.y, Math.max(skala, 0.6), () => wybierz({ planeta: p })); koniec(p.nazwa); } },
          h('span', p.nazwa), h('span.slaby', p.region))),
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
          if ((n?.planeta || n?.szlak) !== (najechane?.planeta || najechane?.szlak)) najechane = n;
          plotno.style.cursor = n ? 'pointer' : 'grab';
        }
        return;
      }
      const dx = e.offsetX - poprzedni.x, dy = e.offsetY - poprzedni.y;
      if (Math.abs(dx) + Math.abs(dy) > 3) przesuniety = true;
      wskazniki.set(e.pointerId, { x: e.offsetX, y: e.offsetY });
      if (wskazniki.size === 1) {
        ox += dx; oy += dy;
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
      if (byl && !przesuniety && wskazniki.size === 0 && e.type === 'pointerup' && D) wybierz(trafienie(e.offsetX, e.offsetY));
    };
    plotno.addEventListener('pointerup', pusc);
    plotno.addEventListener('pointercancel', pusc);
    plotno.addEventListener('pointerleave', () => { najechane = null; });
    plotno.addEventListener('wheel', (e) => {
      e.preventDefault();
      animacja = null;
      przybliz(Math.exp(-e.deltaY * 0.0015), e.offsetX, e.offsetY);
    }, { passive: false });

    function przybliz(mnoznik, sx, sy) {
      const [mx, my] = naMape(sx, sy);
      skala = Math.max(0.005, Math.min(12, skala * mnoznik));
      ox = sx - mx * skala;
      oy = sy + my * skala;
      odswiez();
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
        return { nazwa: d.nazwa, region, x: d.x, y: d.y, g: 0, slonca: 0, ksiezyce: 0, doba: 0, rok: 0, srednica: 0,
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
        skala = 0.25; ox = szer / 2 - tuPlaneta.x * skala; oy = wys / 2 + tuPlaneta.y * skala;
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

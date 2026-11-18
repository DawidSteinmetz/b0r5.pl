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
    const podpowiedz = h('div.mapa-hover', { hidden: true });
    const wsp = h('div.mapa-wsp');
    const ladowanie = h('div.mapa-ladowanie', 'Pobieranie danych astronawigacyjnych...');
    const panelFlot = h('section.mapa-floty-panel', { hidden: true });
    const panelRadar = h('section.mapa-radar', { hidden: true });
    const przyciskSzlaki = h('button.przycisk.maly.drugi', { onclick: () => { szlakiWidoczne = !szlakiWidoczne; przyciskSzlaki.classList.toggle('wl', szlakiWidoczne); odswiez(); } }, 'Szlaki');
    przyciskSzlaki.classList.add('wl');
    const przyciskTu = h('button.przycisk.maly.drugi', { onclick: () => { if (tuPlaneta) przelec(tuPlaneta.x, tuPlaneta.y, 0.6, () => wybierz({ planeta: tuPlaneta })); } }, 'Moja pozycja');
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
    let obiektyMapy = []; // Jednostki i postacie udostępnione temu kontu; MG otrzymuje pełną listę.
    let bitwyRadar = [];
    let mojeFloty = [];
    let brudna = true;
    let ramka = 0;
    let animacja = null;

    const naEkran = (x, y) => [x * skala + ox, -y * skala + oy];
    const naMape = (sx, sy) => [(sx - ox) / skala, -(sy - oy) / skala];
    const odswiez = () => { brudna = true; };

    function planetaObiektu(o) {
      return o?.planeta ? wgKlucza.get(norm(o.planeta)) || null : null;
    }

    function obiektyNaPlanecie(p) {
      return obiektyMapy.filter((o) => norm(o.planeta || '') === p.klucz);
    }

    function nazwaPostaci(o) {
      if (o.typ !== 'postac') return o.nazwa;
      const stopien = String(o.status || '').trim().split(/\s+/).filter(Boolean).at(-1) || '';
      const rangi = new Set(['moff', 'kapitan', 'admiral', 'general', 'major', 'komandor', 'porucznik', 'polkownik', 'dowodca']);
      return rangi.has(norm(stopien)) && !norm(o.nazwa).startsWith(norm(stopien)) ? `${stopien} ${o.nazwa}` : o.nazwa;
    }

    // Przy oddaleniu flota na orbicie to jedna strzałka z liczbą okrętów; po przybliżeniu wachlarz.
    const PROG_ROZWINIECIA = 3;
    // Od tego przybliżenia (px na pc) kropka planety staje się układem: słońce w miejscu kropki,
    // planeta na swojej orbicie (położenie z cyklu planety), księżyce i okręty wokół planety.
    // Układ jest schematyczny: rośnie płynnie z przybliżeniem (prawdziwe orbity to ułamki parseka).
    const PROG_UKLADU = 60;
    const cykle = new Map(); // nazwa planety → cykl (api ?a=uklad) albo null w trakcie pobierania
    function cyklPlanety(p) {
      if (!cykle.has(p.nazwa)) {
        cykle.set(p.nazwa, null);
        api('uklad', undefined, { planeta: p.nazwa }).then((d) => { cykle.set(p.nazwa, d.cykl); odswiez(); }).catch(() => {});
      }
      return cykle.get(p.nazwa);
    }
    // Promień orbity planety na ekranie i promień planety (0 = jeszcze kropka).
    function ukladNaEkranie(p) {
      if (skala < PROG_UKLADU) return null;
      const R = Math.min(Math.min(szer, wys) * 0.42, (skala / PROG_UKLADU - 1) * 90);
      if (R < 14) return null;
      const cykl = cyklPlanety(p);
      if (!cykl) return null;
      const u = stanUkladu(cykl);
      const [sx, sy] = naEkran(p.x, p.y);
      const a = -u.katPlanety * RAD;
      return { R, rp: Math.max(4, R * 0.06), sx, sy, px: sx + Math.cos(a) * R, py: sy + Math.sin(a) * R, u, cykl };
    }
    const pozycjaPlanety = (p) => { const u = ukladNaEkranie(p); return u ? [u.px, u.py] : naEkran(p.x, p.y); };

    function rysujUklady() {
      if (skala < PROG_UKLADU) return;
      for (const p of P) {
        const [sx0, sy0] = naEkran(p.x, p.y);
        if (sx0 < -szer || sy0 < -wys || sx0 > szer * 2 || sy0 > wys * 2) continue;
        const u = ukladNaEkranie(p);
        if (!u) continue;
        const { R, rp, sx, sy, px, py } = u;
        // słońce i orbita planety
        const blask = c.createRadialGradient(sx, sy, 0, sx, sy, Math.max(10, R * 0.12));
        blask.addColorStop(0, 'rgba(255, 246, 220, 1)'); blask.addColorStop(0.3, 'rgba(255, 214, 140, 0.6)'); blask.addColorStop(1, 'rgba(255, 200, 120, 0)');
        c.fillStyle = blask; c.beginPath(); c.arc(sx, sy, Math.max(10, R * 0.12), 0, Math.PI * 2); c.fill();
        c.strokeStyle = 'rgba(160, 200, 220, 0.22)'; c.lineWidth = 1; c.beginPath(); c.arc(sx, sy, R, 0, Math.PI * 2); c.stroke();
        // księżyce (schematycznie, kolejno od planety)
        u.u.ksiezyce.forEach((k, i) => {
          const rk = rp * 2.4 + i * rp * 1.1, a = -k.kat * RAD;
          c.strokeStyle = 'rgba(160, 190, 220, 0.14)'; c.beginPath(); c.arc(px, py, rk, 0, Math.PI * 2); c.stroke();
          c.fillStyle = '#9fb2c4'; c.beginPath(); c.arc(px + Math.cos(a) * rk, py + Math.sin(a) * rk, Math.max(1.5, rp * 0.28), 0, Math.PI * 2); c.fill();
        });
        // planeta: oświetlona połowa od strony słońca
        const as = -u.u.katSlonca * RAD;
        c.fillStyle = '#1b2433'; c.beginPath(); c.arc(px, py, rp, 0, Math.PI * 2); c.fill();
        c.fillStyle = '#6fa8d8'; c.beginPath(); c.arc(px, py, rp, as - Math.PI / 2, as + Math.PI / 2); c.closePath(); c.fill();
        c.font = `11px ${kroj}`; c.fillStyle = kolory.tekst;
        napis(c, p.nazwa, px + rp + 5, py - rp - 3);
      }
    }
    function grupaZwinieta(o) {
      if (skala >= PROG_ROZWINIECIA || !o?.flota || o.typ === 'postac') return null;
      const p = planetaObiektu(o);
      if (!p || wNadprzestrzeni(o)) return null;
      const czlonkowie = obiektyMapy.filter((x) => x.flota?.kod === o.flota.kod && x.typ !== 'postac'
        && planetaObiektu(x) === p && !wNadprzestrzeni(x));
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
      const promien = uklad ? uklad.rp + 12 + numerOrbity * 11
        : orbita ? (zwinieta ? 18 + numerOrbity * 10 : 26 + numerOrbity * 14) : 30;
      const [px, py] = pozycjaPlanety(p);
      if (orbita && (o.orbita != null || o.kurs != null)) {
        // Miejsce na orbicie (orbita_deg): jednostki floty wachlarzem wokół niego; strzałka = kurs dziobu.
        const rozrzut = zwinieta ? 0 : Math.min(18, 200 / Math.max(rodzina.length, 1));
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
      rysujUklady();
      for (const o of obiektyMapy) {
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
        c.save(); c.fillStyle = kolor; c.strokeStyle = kolor; c.lineWidth = 1.7;
        if (o.bitwa) { c.globalAlpha = 0.28 + (Math.sin(t / 180) + 1) * 0.16; c.beginPath(); c.arc(sx, sy, 12 + (t % 900) / 120, 0, Math.PI * 2); c.stroke(); c.globalAlpha = 1; }
        c.beginPath();
        if (o.znacznik === 'trojkat') strzalka(c, sx, sy, kursRys);
        else if (o.znacznik === 'kwadrat') c.rect(sx - 5, sy - 5, 10, 10);
        else if (o.znacznik === 'romb') { c.moveTo(sx, sy - 7); c.lineTo(sx + 7, sy); c.lineTo(sx, sy + 7); c.lineTo(sx - 7, sy); c.closePath(); }
        else c.arc(sx, sy, 5, 0, Math.PI * 2);
        c.fill();
        if ((najechane?.obiekt?.id === o.id) || (wybrane?.obiekt?.id === o.id)) { c.beginPath(); c.arc(sx, sy, 10, 0, Math.PI * 2); c.stroke(); }
        c.restore();
        if (najechane?.obiekt?.id === o.id || wybrane?.obiekt?.id === o.id) {
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
      let najblizszyObiekt = null, odlObiektu = 15;
      for (const obiekt of obiektyMapy) {
        const zw = grupaZwinieta(obiekt);
        if (zw && !zw.glowny) continue;
        const [px, py] = punktObiektu(obiekt);
        const d = Math.hypot(px - sx, py - sy);
        if (d < odlObiektu) { odlObiektu = d; najblizszyObiekt = obiekt; }
      }
      if (najblizszyObiekt) return { obiekt: najblizszyObiekt };
      let najblizsza = null, odl = 16;
      for (const p of P) {
        for (const [px, py] of [naEkran(p.x, p.y), pozycjaPlanety(p)]) {
          const d = Math.hypot(px - sx, py - sy);
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
        g.save(); g.strokeStyle = 'rgba(255, 214, 120, 0.35)'; g.lineWidth = 1;
        for (let i = -3; i <= 3; i++) {
          const dx = Math.cos(as), dy = Math.sin(as), px = -dy * i * 7, py = dx * i * 7;
          g.beginPath(); g.moveTo(cx + dx * 140 + px, cy + dy * 140 + py); g.lineTo(cx + dx * (rp + 6) + px, cy + dy * (rp + 6) + py); g.stroke();
        }
        g.fillStyle = 'rgba(255, 214, 120, 0.9)'; g.font = '10px monospace';
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
        wyczysc(opis).append(...[`Doba ${liczba(cykl.doba_h, 1)} h · rok ${liczba(cykl.rok_dni, 1)} dni · nachylenie osi ${liczba(cykl.nachylenie, 1)}°`, ...linie]
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
          const tutaj = jednostki.filter((j) => norm(j.planeta || '') === p.klucz && !j.rozkaz_id);
          const automat = jednostki[0]?.automat !== false;
          const info = h('p.slaby.maly-tekst');
          wyczysc(tresc);
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
          const wDrodze = jednostki.find((j) => j.rozkaz_id && j.cel_x != null && Math.hypot(j.cel_x - p.x, j.cel_y - p.y) < 2);
          if (wDrodze) {
            planSkoku = { planeta: p.nazwa, kierunek: Math.atan2(p.y - wDrodze.y, p.x - wDrodze.x) / RAD,
              wariant: wDrodze.wariant || 'daleka', reczny: !wDrodze.wariant, policzony: true, aktywny: true };
            odswiezUkladKarty?.();
          }
          if (tutaj.length < jednostki.length && !wDrodze) {
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
            const dane = () => (automat ? { flota_id: f.id, cel_planeta: p.nazwa, wariant } : { flota_id: f.id, x: Number(x.value), y: Number(y.value) });
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
          tresc.append(info);
        };
        wybor.onchange = rysuj;
        sekcja.append(h('div.slaby.maly-tekst', 'Nawigacja floty'), floty.length > 1 ? wybor : null, tresc);
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
            o.flota.tie_liczba == null ? null : h('span', `${o.flota.tie_liczba} myśliwców TIE`)) : null);
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
    plotno.addEventListener('pointerleave', () => { najechane = null; podpowiedz.hidden = true; odswiez(); });
    plotno.addEventListener('wheel', (e) => {
      e.preventDefault();
      animacja = null;
      przybliz(Math.exp(-e.deltaY * 0.0015), e.offsetX, e.offsetY);
    }, { passive: false });

    function przybliz(mnoznik, sx, sy) {
      const [mx, my] = naMape(sx, sy);
      skala = Math.max(0.005, Math.min(3000, skala * mnoznik));
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
      const czesci = [`kurs ${Math.round(j.kurs ?? 90)}°`];
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
          h('div.mapa-flota-form', x, y, cel, rozkaz), info,
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

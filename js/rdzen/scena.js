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
// Wszystko rysowane kodem na <canvas>, bez obrazków. Animacja zatrzymuje się, gdy scena
// jest niewidoczna (otwarta aplikacja, wygaszony ekran), żeby nie drenować baterii.

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
};

export const ODLEGLOSCI = { blisko: 1.15, srednio: 0.5, daleko: 0.16 };

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

export function utworzScene(canvas) {
  const ctx = canvas.getContext('2d');
  let w = 0, h = 0, dpr = 1;
  let stan = { scena: 'kosmos', planeta: 'oceaniczna', odleglosc: 'srednio', zmieniono: 0 };
  let start = performance.now();
  let klatka = null;
  let gwiazdy = [];
  let tunel = [];
  let planeta = null; // { tekstura, swiatla, d, typ }
  let krajobraz = null; // { klucz, plotno, swiatla: [{x, y}] }
  let chmury = [];
  const spokojnie = matchMedia('(prefers-reduced-motion: reduce)').matches;

  function rozmiar() {
    dpr = Math.min(window.devicePixelRatio || 1, 1.5);
    w = canvas.clientWidth;
    h = canvas.clientHeight;
    canvas.width = Math.max(1, w * dpr);
    canvas.height = Math.max(1, h * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    planeta = null;
    krajobraz = null;
  }

  function noweGwiazdy() {
    gwiazdy = Array.from({ length: 420 }, () => ({ x: (Math.random() - 0.5) * 2, y: (Math.random() - 0.5) * 2, z: Math.random(), pz: 0 }));
    tunel = Array.from({ length: 360 }, () => ({ a: Math.random() * Math.PI * 2, z: Math.random(), r: 0.6 + Math.random() * 0.8, j: Math.random() }));
  }

  function przygotujPlanete() {
    const typ = TYPY_PLANET[stan.planeta] ? stan.planeta : 'skalista';
    const d = Math.round(Math.min(w, h) * (ODLEGLOSCI[stan.odleglosc] || 0.5));
    if (planeta && planeta.typ === typ && planeta.d === d) return;
    const ziarno = hash((stan.miejsce || '') + typ);
    planeta = { typ, d, tekstura: teksturaPlanety(typ, Math.max(8, d), ziarno), swiatla: TYPY_PLANET[typ].swiatla ? teksturaSwiatel(Math.max(8, d), ziarno) : null };
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

  function rysujPlanete(t, pojawienie = 1) {
    przygotujPlanete();
    const { d, tekstura, swiatla, typ } = planeta;
    const r = d / 2;
    // Położenie: duża planeta wchodzi od dołu ekranu, mała wisi z boku.
    const blisko = stan.odleglosc === 'blisko';
    const cx = blisko ? w * 0.62 : w * 0.68;
    const cy = blisko ? h * 0.98 : h * 0.55;
    const skala = 0.85 + 0.15 * pojawienie;
    const R = r * skala;
    const info = TYPY_PLANET[typ];
    ctx.save();
    ctx.globalAlpha = pojawienie;
    // poświata atmosfery
    const glow = ctx.createRadialGradient(cx, cy, R * 0.9, cx, cy, R * 1.25);
    glow.addColorStop(0, info.atmosfera + 'aa');
    glow.addColorStop(1, info.atmosfera + '00');
    ctx.fillStyle = glow;
    ctx.beginPath();
    ctx.arc(cx, cy, R * 1.25, 0, Math.PI * 2);
    ctx.fill();
    // powierzchnia (przewijana = obrót)
    ctx.beginPath();
    ctx.arc(cx, cy, R, 0, Math.PI * 2);
    ctx.clip();
    const przes = ((t * 0.004 * (spokojnie ? 0 : 1)) % d);
    ctx.drawImage(tekstura, przes, 0, d, d, cx - R, cy - R, R * 2, R * 2);
    // cień nocnej strony (światło z lewej góry)
    const cien = ctx.createRadialGradient(cx - R * 0.45, cy - R * 0.4, R * 0.15, cx - R * 0.2, cy - R * 0.2, R * 1.45);
    cien.addColorStop(0, 'rgba(0,0,0,0)');
    cien.addColorStop(0.45, 'rgba(0,0,0,0.25)');
    cien.addColorStop(0.75, 'rgba(0,0,0,0.75)');
    cien.addColorStop(1, 'rgba(0,0,0,0.97)');
    ctx.fillStyle = cien;
    ctx.fillRect(cx - R, cy - R, R * 2, R * 2);
    if (swiatla) {
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

  // Noc według zegara tabletu (21:00–6:00); `stan.pora` może to wymusić ('dzien' / 'noc').
  const noc = () => { if (stan.pora) return stan.pora === 'noc'; const g = new Date().getHours(); return g >= 21 || g < 6; };

  // Teren jako statyczny obraz (liczony raz na rozmiar / planetę / porę dnia).
  function przygotujKrajobraz() {
    const typ = POWIERZCHNIE[stan.planeta] ? stan.planeta : 'skalista';
    const klucz = `${typ}|${w}x${h}|${noc()}|${stan.miejsce || ''}`;
    if (krajobraz?.klucz === klucz) return;
    const p = POWIERZCHNIE[typ];
    const c = document.createElement('canvas');
    c.width = Math.max(1, w * dpr);
    c.height = Math.max(1, h * dpr);
    const g = c.getContext('2d');
    g.scale(dpr, dpr);
    const los = losowe(hash(typ + (stan.miejsce || '')));
    const ciemno = noc() ? 0.72 : 0;
    const swiatla = [];
    const horyzont = h * 0.58;

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
              if (los() < (noc() ? 0.35 : 0.08)) swiatla.push([wx, wy]);
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
    const d = Math.max(ciemnosc, noc() ? 0.82 : 0);
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

  function rysujPowierzchnie(t, dt) {
    const typ = POWIERZCHNIE[stan.planeta] ? stan.planeta : 'skalista';
    const p = POWIERZCHNIE[typ];
    const d = rysujNiebo(typ);
    if (d > 0.5) rysujGwiazdy(0.000004, dt, d - 0.3, 0.05);
    else {
      // słońce
      const sx = w * 0.78, sy = h * 0.2;
      const g = ctx.createRadialGradient(sx, sy, 0, sx, sy, h * 0.25);
      g.addColorStop(0, p.slonce); g.addColorStop(0.08, p.slonce + 'cc'); g.addColorStop(1, p.slonce + '00');
      ctx.fillStyle = g; ctx.fillRect(0, 0, w, h);
    }
    rysujChmury(dt, typ, 0.012, d > 0.5 ? 0.25 : 0.7, 0.05, 0.45);
    przygotujKrajobraz();
    ctx.drawImage(krajobraz.plotno, 0, 0, w, h);
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

  // Rama okna (biuro, hotel…) albo wrota hangaru. Na zewnątrz (bankomat, inne) — bez ramy.
  function rysujRame(wnetrze) {
    const okno = ['biuro', 'hotel', 'kantyna', 'sklep', 'garaz'].includes(wnetrze);
    const hangar = ['hangar', 'port', 'poklad'].includes(wnetrze); // platforma: otwarty widok
    if (!okno && !hangar) return;
    ctx.save();
    ctx.fillStyle = '#0a0e12';
    if (okno) {
      const r = Math.max(14, Math.min(w, h) * 0.035);
      ctx.fillRect(0, 0, w, r); ctx.fillRect(0, h - r * 1.6, w, r * 1.6); ctx.fillRect(0, 0, r, h); ctx.fillRect(w - r, 0, r, h);
      ctx.fillRect(w / 3 - r / 4, 0, r / 2, h); ctx.fillRect((2 * w) / 3 - r / 4, 0, r / 2, h);
      ctx.fillRect(0, h * 0.38, w, r / 2.5);
      // odbicie w szybie
      const g = ctx.createLinearGradient(0, 0, w, h);
      g.addColorStop(0, 'rgba(255,255,255,0.06)'); g.addColorStop(0.4, 'rgba(255,255,255,0)'); g.addColorStop(0.6, 'rgba(255,255,255,0.04)'); g.addColorStop(1, 'rgba(255,255,255,0)');
      ctx.fillStyle = g; ctx.fillRect(0, 0, w, h);
    } else {
      // Wrota hangaru: ciemne wnętrze u góry i po bokach, posadzka z pasami ostrzegawczymi.
      const gora = h * 0.12, bok = w * 0.06, podloga = h * 0.86;
      ctx.fillRect(0, 0, w, gora);
      ctx.beginPath(); ctx.moveTo(0, 0); ctx.lineTo(bok, gora); ctx.lineTo(bok, podloga); ctx.lineTo(0, h); ctx.fill();
      ctx.beginPath(); ctx.moveTo(w, 0); ctx.lineTo(w - bok, gora); ctx.lineTo(w - bok, podloga); ctx.lineTo(w, h); ctx.fill();
      ctx.fillStyle = '#14191e'; ctx.fillRect(0, podloga, w, h - podloga);
      for (let x = bok; x < w - bok; x += 28) {
        ctx.fillStyle = (x / 28) % 2 < 1 ? '#c9a227' : '#14191e';
        ctx.fillRect(x, podloga, 14, 6);
      }
      for (let x = w * 0.15; x < w * 0.9; x += w * 0.12) {
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

    switch (stan.scena) {
      case 'wejscie': {
        // 0–70%: gwiazdy przyspieszają i rozciągają się; 70–80%: błysk; potem tunel.
        const f = t / CZAS_WEJSCIA;
        if (f < 0.72) {
          tlo();
          const k = f / 0.72;
          rysujGwiazdy((0.00004 + Math.pow(k, 3) * 0.004) * ruch, dt, 1, 0.2 + k * 1.5);
        } else if (f < 1) {
          rysujTunel(t, dt, (f - 0.72) / 0.28);
          blysk(1 - (f - 0.72) / 0.28);
        } else {
          rysujTunel(t, dt);
        }
        break;
      }
      case 'nadswietlna':
        rysujTunel(t, dt * ruch);
        break;
      case 'wyjscie': {
        // 0–15%: błysk ze smug; 15–60%: smugi zwalniają; potem pojawia się planeta.
        const f = t / CZAS_WYJSCIA;
        tlo();
        const k = Math.min(1, f / 0.6);
        rysujGwiazdy((0.004 * Math.pow(1 - k, 2.2) + 0.00003) * ruch, dt, 1, 1.6 * (1 - k) + 0.05);
        if (f > 0.45) rysujPlanete(t, Math.min(1, (f - 0.45) / 0.55));
        blysk(f < 0.15 ? 1 - f / 0.15 : 0);
        break;
      }
      case 'orbita':
        tlo();
        rysujGwiazdy(0.000012 * ruch, dt, 0.9, 0.05);
        rysujPlanete(t, 1);
        break;
      case 'powierzchnia':
        rysujPowierzchnie(t, dt * ruch);
        break;
      case 'start':
        rysujStart(dt * ruch);
        break;
      default:
        tlo();
        rysujGwiazdy(0.00003 * ruch, dt, 1, 0.05);
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
      if (!zmiana) return;
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

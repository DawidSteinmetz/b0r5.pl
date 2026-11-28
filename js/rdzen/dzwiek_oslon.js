// Czyste, harmoniczne buczenie osłon. Składowa podstawowa przy 40 Hz ustawienia osłon
// wynosi 40 Hz; wraz ze wzrostem energii rośnie jej wysokość i udział wyższych harmonicznych.
// Sygnał powstaje z sinusoid i przechodzi przez łagodny filtr oraz limiter — bez impulsów,
// szumu zapłonu i losowych wyładowań.
//
// const o = new DzwiekOslon(ctx, wyjscie);  await o.przygotuj();
// o.wlacz(3600);  o.ustawHz(1800);  o.wylacz();

const ADRES_WORKLETU = new URL('./oslony_worklet.js', import.meta.url);
const wczytaneKonteksty = new WeakMap();

// Generator impulsów (AudioWorklet) — używany przez efekty trafień i wystrzałów.
export async function wczytajWorklet(ctx) {
  if (!ctx.audioWorklet) throw new Error('Przeglądarka nie obsługuje AudioWorklet');
  if (!wczytaneKonteksty.has(ctx)) wczytaneKonteksty.set(ctx, ctx.audioWorklet.addModule(ADRES_WORKLETU));
  await wczytaneKonteksty.get(ctx);
}

export const DOMYSLNE = {
  narastanie: 10,   // s: od 1 Hz do docelowego (równo po oktawach — każde podwojenie Hz trwa tyle samo)
  wejscie: 0.8,     // s: miękkie wyłonienie głośności
  wyciszenie: 2.8,  // s: gładkie wygaszanie
  zmianaHz: 1.2,    // s: płynne przestrajanie w trakcie pracy
  tonPrzy40: 40,    // Hz: wysokość bazowa, zgodna z brzmieniem przy 40 Hz generatora
  tonMaks: 90,      // Hz: wysokość podstawy przy maksymalnym zasilaniu (3600 Hz)
  harmoniczne: 0.72,// poziom wyższych składowych harmonicznych
  rezonans: 125,    // Hz: delikatne podbicie korpusu tonu
  rezonansDb: 2,
  falowanie: 0.018, // minimalne, wolne oddychanie poziomu
  glosnosc: 0.62,   // bezpieczny poziom; tory efektów nie powinny przesterować wyjścia
};

// Bufor szumu do krótkich warstw trafień i wystrzałów — jeden na kontekst.
const bufSzumu = new WeakMap();
export function szumBialy(ctx) {
  if (!bufSzumu.has(ctx)) {
    const b = ctx.createBuffer(1, ctx.sampleRate * 3, ctx.sampleRate), d = b.getChannelData(0);
    for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
    bufSzumu.set(ctx, b);
  }
  return bufSzumu.get(ctx);
}

export class DzwiekOslon {
  constructor(ctx, wyjscie = ctx.destination, ustawienia = {}, { pan = 0 } = {}) {
    this.ctx = ctx;
    this.wyjscie = wyjscie;
    this.u = { ...DOMYSLNE, ...ustawienia };
    this.wezel = null;
    this.wlaczone = false;
    this.rampa = { t0: 0, f0: 0, t1: 0, f1: 0 }; // rampa Hz generatora (logarytmiczna)
    this.zegarWylaczenia = null;
    this.zegarSterowania = null;
    this.ugiecie = 1; // mnożnik tonu: trafienie na chwilę go obniża, potem wraca do 1
  }

  async przygotuj() {
    if (this.wezel) return;
    const ctx = this.ctx;
    const u = this.u;
    // Czyste składowe sinusoidalne: podstawowa + harmoniczne 2…6.
    this.tony = new GainNode(ctx, { gain: 0.8 });
    this.oscylatory = [];
    for (const [mnoznik, poziom] of [[1, 0.74], [2, 0.29], [3, 0.16], [4, 0.09], [5, 0.05], [6, 0.025]]) {
      const osc = new OscillatorNode(ctx, { type: 'sine', frequency: Math.max(8, u.tonPrzy40 * mnoznik) });
      const gl = new GainNode(ctx, { gain: poziom });
      osc.connect(gl).connect(this.tony);
      osc.start();
      this.oscylatory.push({ osc, gl, mnoznik, poziom });
    }
    this.dol = new BiquadFilterNode(ctx, { type: 'highpass', frequency: 22, Q: 0.55 });
    this.dzwon = new BiquadFilterNode(ctx, { type: 'peaking', frequency: u.rezonans, Q: 1.1, gain: u.rezonansDb });
    this.gora = new BiquadFilterNode(ctx, { type: 'lowpass', frequency: 900, Q: 0.55 });
    this.poziom = new GainNode(ctx, { gain: u.glosnosc });
    this.lfo = new OscillatorNode(ctx, { frequency: 0.22 });
    this.lfoGl = new GainNode(ctx, { gain: u.falowanie * u.glosnosc });
    this.lfo.connect(this.lfoGl).connect(this.poziom.gain);
    this.lfo.start();
    this.ogranicznik = new DynamicsCompressorNode(ctx, { threshold: -2, knee: 1, ratio: 20, attack: 0.003, release: 0.18 });
    this.panorama = ctx.createStereoPanner ? ctx.createStereoPanner() : null;
    if (this.panorama) this.panorama.pan.value = Math.max(-1, Math.min(1, pan));
    this.obwiednia = new GainNode(ctx, { gain: 0 });
    this.reakcjaGl = new GainNode(ctx, { gain: 1 });
    this.tony.connect(this.dol);
    this.dol.connect(this.dzwon).connect(this.gora).connect(this.poziom).connect(this.obwiednia);
    this.obwiednia.connect(this.reakcjaGl).connect(this.ogranicznik);
    if (this.panorama) this.ogranicznik.connect(this.panorama).connect(this.wyjscie);
    else this.ogranicznik.connect(this.wyjscie);
    this.wezel = this.tony;
    this.steruj();
  }

  // Bieżące Hz generatora (logarytmiczna rampa).
  hz(t = this.ctx.currentTime) {
    const { t0, f0, t1, f1 } = this.rampa;
    if (t >= t1 || t1 <= t0) return f1;
    if (t <= t0) return f0;
    return f0 * Math.pow(f1 / f0, (t - t0) / (t1 - t0));
  }

  // Przy 40 Hz generatora podstawowy ton ma dokładnie 40 Hz; wyżej płynnie
  // przechodzi do tonMaks przy 3600 Hz, bez skoków ani ziarnistych impulsów.
  ton(f) {
    const od = Math.max(1, this.u.tonPrzy40), doHz = Math.max(od, this.u.tonMaks);
    if (f <= od) return f;
    const udzial = Math.min(1, Math.log(f / od) / Math.log(3600 / od));
    return od * Math.pow(doHz / od, udzial);
  }

  // Wraz z energią rośnie wysokość tonu i stopniowo odsłaniają się wyższe harmoniczne.
  steruj() {
    const t = this.ctx.currentTime, f = this.hz(t), u = this.u;
    this.ugiecie += (1 - this.ugiecie) * 0.12;
    const tonPola = f > 0 ? this.ton(f) * this.ugiecie : 0;
    const energia = Math.min(1, Math.max(0, Math.log(Math.max(1, f) / 40) / Math.log(3600 / 40)));
    const bogactwo = u.harmoniczne * (0.38 + 0.62 * energia);
    for (let i = 0; i < this.oscylatory.length; i++) {
      const { osc, gl, mnoznik, poziom } = this.oscylatory[i];
      osc.frequency.setTargetAtTime(Math.max(8, tonPola * mnoznik), t, 0.055);
      gl.gain.setTargetAtTime(i === 0 ? poziom : poziom * Math.pow(bogactwo, i), t, 0.08);
    }
  }

  // Trafienie w działające pole (sila 0…1): buczenie podskakuje i na moment „ugina się” w dół, potem wraca.
  trafienie(sila = 1) {
    if (!this.tony || !this.wlaczone || !(sila > 0)) return;
    const t = this.ctx.currentTime, g = this.reakcjaGl.gain;
    g.cancelScheduledValues(t);
    g.setValueAtTime(g.value, t);
    g.linearRampToValueAtTime(1 + 0.12 * Math.min(1, sila), t + 0.045);
    g.setTargetAtTime(1, t + 0.05, 0.4);
    this.ugiecie = Math.min(this.ugiecie, 1 - 0.05 * Math.min(1, sila));
    this.steruj();
  }

  rampaHz(cel, czas) {
    const t = this.ctx.currentTime;
    const od = Math.max(1, this.hz(t)), doHz = Math.max(1, cel);
    this.rampa = { t0: t, f0: od, t1: t + Math.max(0, czas), f1: doHz };
    this.uruchomSterowanie();
  }

  uruchomSterowanie() {
    if (this.zegarSterowania) return;
    this.steruj();
    this.zegarSterowania = setInterval(() => this.steruj(), 40);
  }

  wlacz(hz = 3600, narastanie = this.u.narastanie) {
    if (!this.tony) return;
    clearTimeout(this.zegarWylaczenia);
    const t = this.ctx.currentTime;
    if (!this.wlaczone && this.hz(t) <= 1) {
      this.rampa = { t0: t, f0: 1, t1: t, f1: 1 };
    }
    this.wlaczone = true;
    const od = Math.max(1, this.hz(t));
    // Jeśli osłony jeszcze wygasały, dojście trwa tylko tyle, ile brakuje (proporcjonalnie do oktaw).
    const oktawyCale = Math.log2(Math.max(2, hz)), oktawy = Math.abs(Math.log2(Math.max(1, hz) / od));
    this.rampaHz(hz, narastanie * Math.min(1, oktawy / oktawyCale));
    const o = this.obwiednia.gain;
    o.cancelScheduledValues(t);
    o.setValueAtTime(o.value, t);
    o.setTargetAtTime(1, t, this.u.wejscie / 3);
  }

  ustawHz(hz, czas = this.u.zmianaHz) {
    if (!this.tony || !this.wlaczone) return;
    this.rampaHz(hz, czas);
  }

  wylacz(czas = this.u.wyciszenie) {
    if (!this.tony || !this.wlaczone) return;
    this.wlaczone = false;
    const t = this.ctx.currentTime, o = this.obwiednia.gain;
    o.cancelScheduledValues(t);
    o.setValueAtTime(o.value, t);
    o.setTargetAtTime(0, t, czas / 4);
    // Buczenie przy gaśnięciu lekko opada (o ok. 1/3 Hz generatora — ton spada o kilka procent).
    this.rampaHz(this.hz(t) * 0.33, czas);
    clearTimeout(this.zegarWylaczenia);
    this.zegarWylaczenia = setTimeout(() => {
      if (this.wlaczone) return;
      clearInterval(this.zegarSterowania);
      this.zegarSterowania = null;
      const t2 = this.ctx.currentTime;
      this.rampa = { t0: t2, f0: 0, t1: t2, f1: 0 };
      this.steruj();
    }, czas * 1000 + 300);
  }

  // Zmiana brzmienia w trakcie (strona testowa, później ustawienia gry).
  ustaw(nazwa, wartosc) {
    this.u[nazwa] = wartosc;
    if (!this.tony) return;
    const t = this.ctx.currentTime;
    if (nazwa === 'rezonans') this.dzwon.frequency.setTargetAtTime(wartosc, t, 0.05);
    else if (nazwa === 'rezonansDb') this.dzwon.gain.setTargetAtTime(wartosc, t, 0.05);
    else if (['tonPrzy40', 'tonMaks', 'harmoniczne'].includes(nazwa) && this.zegarSterowania) this.steruj();
    else if (nazwa === 'glosnosc' || nazwa === 'falowanie') {
      this.poziom.gain.setTargetAtTime(this.u.glosnosc, t, 0.05);
      this.lfoGl.gain.setTargetAtTime(this.u.falowanie * this.u.glosnosc, t, 0.05);
    }
    if (this.zegarSterowania && !['tonPrzy40', 'tonMaks', 'harmoniczne'].includes(nazwa)) this.steruj();
  }

  zniszcz() {
    clearTimeout(this.zegarWylaczenia);
    clearInterval(this.zegarSterowania);
    try { this.lfo?.stop(); for (const { osc } of this.oscylatory || []) osc.stop(); this.wezel?.disconnect(); this.tony?.disconnect(); this.ogranicznik?.disconnect(); this.panorama?.disconnect(); } catch { /* już odłączone */ }
    this.tony = null;
    this.wezel = null;
  }
}

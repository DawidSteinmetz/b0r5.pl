// Trafienie dużej energii w osłonę — dźwięk generowany na żywo (Web Audio), bez plików.
//
// Warstwy jednego trafienia:
//  1. uderzenie — głuchy huk: opadający bas i krótki ciemny szum;
//  2. wyładowanie energii — impulsy o gęstości równej Hz energii, gasnące i rzedniejące:
//     1 Hz = pojedynczy, ciężki trzask; setki Hz = trzeszczenie; 3600 Hz = gęsty, jasny syk;
//  3. dzwonienie pola — osłona „brzmi” po uderzeniu w swoim paśmie (domyślnie to samo co śpiew buczenia, 410 Hz);
//  4. reakcja osłony — jeśli osłona działa, jej buczenie podskakuje i na moment się ugina.
//
// await przygotujTrafienia(ctx);
// trafienieOslony(ctx, wyjscie, baza.trafienie_oslony, { hz: 1800, sila: 0.8, oslona });

import { wczytajWorklet, szumBialy } from './dzwiek_oslon.js';

export const DOMYSLNE_TRAFIENIA = {
  glosnosc: 1,
  // 1. uderzenie
  uderzenie: 0.6,
  basOd: 95,          // Hz: początek opadającego basu
  basDo: 32,          // Hz: koniec
  czasUderzenia: 0.6, // s
  // 2. wyładowanie energii
  wyladowanie: 0.8,
  czasWyladowania: 1.1, // s
  rzednienie: 8,        // ile razy gęstość impulsów spada do końca wyładowania
  rozrzut: 0.85,        // 0 = równy takt (ton), 1 = losowe trzaski
  jasnosc: 9000,        // Hz: filtr przy 3600 Hz energii (przy 1 Hz ok. 6× ciemniej)
  // 3. dzwonienie pola
  dzwonienie: 0.35,
  tonDzwonienia: 410,   // Hz
  czasDzwonienia: 1.8,  // s
  // 4. ogólne
  reakcja: 0.7,         // jak mocno szarpie buczeniem działającej osłony
  szerokosc: 0.5,       // losowe położenie trafienia lewo/prawo (0 = środek)
};

const udzialEnergii = (hz) => Math.min(1, Math.max(0, Math.log(Math.max(1, hz)) / Math.log(3600)));

export function przygotujTrafienia(ctx) { return wczytajWorklet(ctx); }

// Odtwarza jedno trafienie. hz: Hz energii (1…3600), sila: 0…1, oslona: DzwiekOslon (opcjonalnie), pan: -1…1 (opcjonalnie).
export function trafienieOslony(ctx, wyjscie, ustawienia = {}, { hz = 1800, sila = 1, oslona = null, pan = null } = {}) {
  const u = { ...DOMYSLNE_TRAFIENIA, ...ustawienia };
  const t = ctx.currentTime + 0.005, e = udzialEnergii(hz), s = Math.max(0, Math.min(1, sila));
  const dlugosc = Math.max(u.czasUderzenia, u.czasWyladowania, u.czasDzwonienia) + 0.4;
  const koniec = t + dlugosc;
  const wezly = [];
  const nowy = (w) => { wezly.push(w); return w; };

  const panorama = nowy(new StereoPannerNode(ctx, { pan: pan ?? (Math.random() * 2 - 1) * u.szerokosc }));
  const wyj = nowy(new GainNode(ctx, { gain: u.glosnosc * (0.3 + 0.7 * s) }));
  // Ogranicznik: suma warstw (i kilka trafień w salwie) nie przekroczy pełnej skali.
  const ogr = nowy(new DynamicsCompressorNode(ctx, { threshold: -6, knee: 3, ratio: 20, attack: 0.001, release: 0.1 }));
  wyj.connect(ogr).connect(panorama).connect(wyjscie);

  // 1. Uderzenie: bas opada, ciemny szum huku.
  if (u.uderzenie > 0) {
    const bas = nowy(new OscillatorNode(ctx, { frequency: u.basOd }));
    bas.frequency.setValueAtTime(u.basOd, t);
    bas.frequency.exponentialRampToValueAtTime(Math.max(10, u.basDo), t + u.czasUderzenia);
    const gb = nowy(new GainNode(ctx, { gain: 0 }));
    gb.gain.setValueAtTime(0, t);
    gb.gain.linearRampToValueAtTime(u.uderzenie, t + 0.004);
    gb.gain.setTargetAtTime(0, t + 0.02, u.czasUderzenia / 4);
    bas.connect(gb).connect(wyj);
    bas.start(t); bas.stop(koniec);

    const huk = nowy(new AudioBufferSourceNode(ctx, { buffer: szumBialy(ctx) }));
    const lp = nowy(new BiquadFilterNode(ctx, { type: 'lowpass', frequency: 1200, Q: 0.8 }));
    lp.frequency.setValueAtTime(1200, t);
    lp.frequency.exponentialRampToValueAtTime(180, t + u.czasUderzenia * 0.6);
    const gh = nowy(new GainNode(ctx, { gain: 0 }));
    gh.gain.setValueAtTime(0, t);
    gh.gain.linearRampToValueAtTime(0.5 * u.uderzenie, t + 0.003);
    gh.gain.setTargetAtTime(0, t + 0.01, u.czasUderzenia / 6);
    huk.connect(lp).connect(gh).connect(wyj);
    huk.start(t, Math.random() * 1.5); huk.stop(koniec);
  }

  // 2. Wyładowanie energii: impulsy o gęstości = Hz energii, gasnące i rzedniejące.
  let generator = null;
  if (u.wyladowanie > 0) {
    generator = new AudioWorkletNode(ctx, 'impulsy-oslon', { numberOfInputs: 0, outputChannelCount: [2] });
    const P = (n) => generator.parameters.get(n);
    P('rozrzut').value = u.rozrzut;
    P('szum').value = 1;
    P('wyrownanie').value = 0.6;
    // Rzadka energia: dłuższe, cięższe trzaski; gęsta: krótkie ziarna syku.
    P('dlugosc').value = 0.0006 + 0.006 * (1 - e);
    const g = P('gestosc');
    g.setValueAtTime(Math.max(1, hz), t);
    g.exponentialRampToValueAtTime(Math.max(0.5, hz / Math.max(1, u.rzednienie)), t + u.czasWyladowania);
    g.setValueAtTime(0, t + u.czasWyladowania);
    const hp = nowy(new BiquadFilterNode(ctx, { type: 'highpass', frequency: 90, Q: 0.7 }));
    const jasno = (u.jasnosc / 6) * Math.pow(6, e);
    const lp = nowy(new BiquadFilterNode(ctx, { type: 'lowpass', frequency: jasno, Q: 0.7 }));
    lp.frequency.setValueAtTime(jasno, t);
    lp.frequency.exponentialRampToValueAtTime(Math.max(200, jasno / 3), t + u.czasWyladowania);
    const gw = nowy(new GainNode(ctx, { gain: 0 }));
    gw.gain.setValueAtTime(0, t);
    gw.gain.linearRampToValueAtTime(4 * u.wyladowanie, t + 0.003);
    gw.gain.setTargetAtTime(0, t + 0.03, u.czasWyladowania / 4);
    generator.connect(hp).connect(lp).connect(gw).connect(wyj);
    wezly.push(generator);
  }

  // 3. Dzwonienie pola: trzy sinusoidalne mody rezonansu, jak metaliczny/energetyczny rezonator.
  // Szum pobudzający wąski filtr dawał głównie syczący szum zamiast słyszalnego tonu.
  if (u.dzwonienie > 0) {
    for (const [mnoznik, poziom] of [[1, 1], [1.47, 0.42], [2.09, 0.2]]) {
      const f0 = u.tonDzwonienia * mnoznik;
      const osc = nowy(new OscillatorNode(ctx, { type: 'sine', frequency: f0 * 1.08 }));
      const env = nowy(new GainNode(ctx, { gain: 0 }));
      osc.frequency.setValueAtTime(f0 * 1.08, t);
      osc.frequency.exponentialRampToValueAtTime(f0, t + Math.min(0.12, u.czasDzwonienia / 5));
      env.gain.setValueAtTime(0.0001, t);
      env.gain.linearRampToValueAtTime(u.dzwonienie * poziom * (0.65 + 0.35 * e) * s, t + 0.004);
      env.gain.exponentialRampToValueAtTime(0.0001, t + u.czasDzwonienia);
      osc.connect(env).connect(wyj);
      osc.start(t); osc.stop(koniec);
    }
  }

  // 4. Reakcja działającej osłony.
  if (oslona && u.reakcja > 0) oslona.trafienie(s * u.reakcja);

  // Sprzątanie po wybrzmieniu.
  setTimeout(() => {
    generator?.port.postMessage('koniec');
    for (const w of wezly) { try { w.disconnect(); } catch { /* już odłączony */ } }
  }, (koniec - ctx.currentTime) * 1000 + 200);

  return { koniec };
}

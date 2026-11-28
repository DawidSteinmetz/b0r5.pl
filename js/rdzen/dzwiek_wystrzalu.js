// Nasz wystrzał z turbolasera — dźwięk generowany na żywo (Web Audio), bez plików.
// To strzał oddawany przez nas: nie rusza buczenia osłony i brzmi inaczej niż trafienie (bez dzwonienia pola).
// Wzór: dźwięk turbolasera niszczyciela — ok. 60% energii poniżej 160 Hz (potężny huk), brzęczący ton
// ok. 150–300 Hz z wieloma harmonicznymi, lekko opadający, a na początku krótki, jasny trzask.
//
// Warstwy jednego wystrzału:
//  1. trzask — impulsy o gęstości równej Hz energii, w wysokim paśmie, przez kilkadziesiąt ms:
//     1 Hz = pojedyncze kliknięcie, 3600 Hz = ostry, gęsty syk;
//  2. brzęk — przesterowany ton (piła + kwadrat) z wieloma harmonicznymi, lekko opadający;
//     wyższe Hz energii = wyższy i jaśniejszy (do oktawy w całym zakresie 1–3600 Hz);
//  3. huk — niski grzmot działa (opadający bas + ciemny szum), szczyt chwilę po trzasku;
//  4. ogon — krótkie echo.
//
// await przygotujWystrzaly(ctx);
// wystrzal(ctx, wyjscie, baza.wystrzal_turbolasera, { hz: 3600, sila: 1, pan: -0.6 });

import { wczytajWorklet, szumBialy } from './dzwiek_oslon.js';

export const DOMYSLNE_WYSTRZALU = {
  glosnosc: 0.45,
  // 1. trzask
  trzask: 1.4,
  czasTrzasku: 0.1,  // s
  rozrzut: 0.5,
  // 2. brzęk
  brzek: 0.55,
  tonOd: 260,         // Hz: start tonu przy 3600 Hz energii (przy 1 Hz o oktawę niżej)
  opadanie: 0.3,      // o ile ton opada do końca (0,3 = o 30%)
  czasBrzeku: 0.26,   // s
  przester: 0.75,     // 0 = czysty ton, 1 = mocno przesterowany
  przyciecie: 1.4,    // filtr górnoprzepustowy = ton × tyle (większe = cieńszy, bardziej „brzęczący” ton)
  formant: 520,       // Hz: pasmo „ciała” działa
  formantDb: 6,       // dB: jego podbicie
  jasnosc: 7000,      // Hz: filtr brzęku przy 3600 Hz energii
  // 3. huk
  huk: 1.1,
  basHuku: 85,        // Hz: start basu (opada o połowę)
  czasHuku: 0.3,      // s
  opoznienieHuku: 0.03, // s: szczyt huku po trzasku
  // 4. ogon
  echo: 0.25,
  opoznienie: 0.09,   // s
  sprzezenie: 0.35,
  // ogólne
  szerokosc: 0.6,     // losowe położenie lewo/prawo, gdy nie podano strony (0 = środek)
  rozstrojenie: 0.35,  // losowa różnica tonu między strzałami (0,35 = ±35% — różne działa salwy)
};

const udzialEnergii = (hz) => Math.min(1, Math.max(0, Math.log(Math.max(1, hz)) / Math.log(3600)));

// Krzywa przesteru (miękkie obcięcie), pamiętana dla ostatniej wartości. Niesymetryczna: dolna połowa fali
// obcinana niżej (0,55) niż górna — symetryczne obcięcie robi z brzęku falę prostokątną z samymi nieparzystymi
// harmonicznymi, a w dźwięku turbolasera mocna jest też druga harmoniczna.
let krzywa = null, krzywaDla = -1;
function krzywaPrzesteru(k) {
  if (k !== krzywaDla) {
    const n = 1024, c = new Float32Array(n), a = 1 + k * 20, dol = 0.55;
    for (let i = 0; i < n; i++) {
      const x = (i / (n - 1)) * 2 - 1;
      c[i] = x >= 0 ? Math.tanh(a * x) : dol * Math.tanh((a * x) / dol);
    }
    krzywa = c; krzywaDla = k;
  }
  return krzywa;
}

export function przygotujWystrzaly(ctx) { return wczytajWorklet(ctx); }

// Odtwarza jeden wystrzał. hz: Hz energii (1…3600), sila: 0…1, pan: -1 (lewa burta) … 1 (prawa), opcjonalnie.
export function wystrzal(ctx, wyjscie, ustawienia = {}, { hz = 3600, sila = 1, pan = null } = {}) {
  const u = { ...DOMYSLNE_WYSTRZALU, ...ustawienia };
  const t = ctx.currentTime + 0.005, e = udzialEnergii(hz), s = Math.max(0, Math.min(1, sila));
  const ogonS = u.echo > 0 ? u.opoznienie * Math.log(0.01) / Math.log(Math.max(0.01, Math.min(0.9, u.sprzezenie))) : 0;
  const koniec = t + Math.max(u.czasTrzasku, u.czasBrzeku, u.opoznienieHuku + u.czasHuku) + ogonS + 0.3;
  const wezly = [];
  const nowy = (w) => { wezly.push(w); return w; };
  const rozstroj = 1 + (Math.random() * 2 - 1) * u.rozstrojenie;

  const panorama = nowy(new StereoPannerNode(ctx, { pan: pan ?? (Math.random() * 2 - 1) * u.szerokosc }));
  const wyj = nowy(new GainNode(ctx, { gain: u.glosnosc * (0.3 + 0.7 * s) }));
  const ogr = nowy(new DynamicsCompressorNode(ctx, { threshold: -6, knee: 3, ratio: 20, attack: 0.001, release: 0.1 }));
  wyj.connect(ogr).connect(panorama).connect(wyjscie);

  // Ogon: echo — linia opóźniająca ze sprzężeniem, ciemniejąca z każdym odbiciem.
  let wejscieEcha = null;
  if (u.echo > 0) {
    wejscieEcha = nowy(new GainNode(ctx, { gain: u.echo }));
    const op = nowy(new DelayNode(ctx, { delayTime: u.opoznienie, maxDelayTime: 1 }));
    const lp = nowy(new BiquadFilterNode(ctx, { type: 'lowpass', frequency: 1800, Q: 0.5 }));
    const sp = nowy(new GainNode(ctx, { gain: Math.min(0.9, u.sprzezenie) }));
    wejscieEcha.connect(op).connect(lp).connect(wyj);
    lp.connect(sp).connect(op);
  }

  // 1. Trzask: impulsy o gęstości = Hz energii, tylko wysokie pasmo, kilkadziesiąt ms.
  let generator = null;
  if (u.trzask > 0) {
    generator = new AudioWorkletNode(ctx, 'impulsy-oslon', { numberOfInputs: 0, outputChannelCount: [2] });
    const P = (n) => generator.parameters.get(n);
    P('rozrzut').value = u.rozrzut;
    P('szum').value = 1;
    P('wyrownanie').value = 0.6;
    P('dlugosc').value = 0.0004 + 0.002 * (1 - e);
    P('gestosc').setValueAtTime(Math.max(1, hz), t);
    P('gestosc').setValueAtTime(0, t + u.czasTrzasku * 1.5);
    const hp = nowy(new BiquadFilterNode(ctx, { type: 'highpass', frequency: 2500, Q: 0.7 }));
    const gl = nowy(new GainNode(ctx, { gain: 0 }));
    gl.gain.setValueAtTime(0, t);
    gl.gain.linearRampToValueAtTime(10 * u.trzask, t + 0.002);
    gl.gain.setTargetAtTime(0, t + 0.005, u.czasTrzasku / 3);
    generator.connect(hp).connect(gl).connect(wyj);
    wezly.push(generator);
  }

  // 2. Brzęk: piła + kwadrat, przester, filtr; ton lekko opada.
  if (u.brzek > 0) {
    const start = u.tonOd * Math.pow(2, e - 1) * rozstroj;
    const stop = Math.max(20, start * (1 - Math.min(0.95, u.opadanie)));
    const przester = nowy(new WaveShaperNode(ctx, { curve: krzywaPrzesteru(u.przester), oversample: '2x' }));
    const jasno = (u.jasnosc / 3) * Math.pow(3, e);
    const lp = nowy(new BiquadFilterNode(ctx, { type: 'lowpass', frequency: jasno, Q: 1 }));
    lp.frequency.setValueAtTime(jasno, t);
    lp.frequency.exponentialRampToValueAtTime(Math.max(150, jasno / 2), t + u.czasBrzeku);
    const g = nowy(new GainNode(ctx, { gain: 0 }));
    g.gain.setValueAtTime(0, t);
    g.gain.linearRampToValueAtTime(u.brzek, t + 0.006);
    g.gain.setValueAtTime(u.brzek, t + u.czasBrzeku * 0.35);
    g.gain.exponentialRampToValueAtTime(0.001, t + u.czasBrzeku);
    for (const [typ, poziom, mn] of [['sawtooth', 0.55, 1], ['square', 0.4, 0.997]]) {
      const osc = nowy(new OscillatorNode(ctx, { type: typ, frequency: start * mn }));
      osc.frequency.setValueAtTime(start * mn, t);
      osc.frequency.exponentialRampToValueAtTime(stop * mn, t + u.czasBrzeku);
      const go = nowy(new GainNode(ctx, { gain: poziom }));
      osc.connect(go).connect(przester);
      osc.start(t); osc.stop(t + u.czasBrzeku + 0.05);
    }
    // Filtr górnoprzepustowy idący za tonem: przycina ton podstawowy (i składową stałą przesteru),
    // żeby harmoniczne były mniej więcej równie mocne jak on — tak jak we wzorze.
    const hp = nowy(new BiquadFilterNode(ctx, { type: 'highpass', frequency: start * u.przyciecie, Q: 0.8 }));
    hp.frequency.setValueAtTime(start * u.przyciecie, t);
    hp.frequency.exponentialRampToValueAtTime(stop * u.przyciecie, t + u.czasBrzeku);
    // Pasmo „ciała” działa — stała barwa niezależna od tonu (we wzorze mocne okolice 400–800 Hz).
    const cialo = nowy(new BiquadFilterNode(ctx, { type: 'peaking', frequency: u.formant, Q: 1.1, gain: u.formantDb }));
    przester.connect(hp).connect(cialo).connect(lp).connect(g).connect(wyj);
    if (wejscieEcha) g.connect(wejscieEcha);
  }

  // 3. Huk: opadający bas i ciemny szum, szczyt chwilę po trzasku.
  if (u.huk > 0) {
    const th = t + u.opoznienieHuku;
    const osc = nowy(new OscillatorNode(ctx, { frequency: u.basHuku }));
    osc.frequency.setValueAtTime(u.basHuku, t);
    osc.frequency.exponentialRampToValueAtTime(Math.max(15, u.basHuku * 0.5), th + u.czasHuku);
    const gb = nowy(new GainNode(ctx, { gain: 0 }));
    gb.gain.setValueAtTime(0, t);
    gb.gain.linearRampToValueAtTime(u.huk, th);
    gb.gain.setTargetAtTime(0, th + 0.01, u.czasHuku / 3.5);
    osc.connect(gb).connect(wyj);
    osc.start(t); osc.stop(th + u.czasHuku * 2);

    const sz = nowy(new AudioBufferSourceNode(ctx, { buffer: szumBialy(ctx) }));
    const lp = nowy(new BiquadFilterNode(ctx, { type: 'lowpass', frequency: 220, Q: 0.9 }));
    const gs = nowy(new GainNode(ctx, { gain: 0 }));
    gs.gain.setValueAtTime(0, t);
    gs.gain.linearRampToValueAtTime(2.2 * u.huk, th);
    gs.gain.setTargetAtTime(0, th + 0.01, u.czasHuku / 4);
    sz.connect(lp).connect(gs).connect(wyj);
    if (wejscieEcha) gs.connect(wejscieEcha);
    sz.start(t, Math.random() * 1.5); sz.stop(th + u.czasHuku * 2);
  }

  setTimeout(() => {
    generator?.port.postMessage('koniec');
    for (const w of wezly) { try { w.disconnect(); } catch { /* już odłączony */ } }
  }, (koniec - ctx.currentTime) * 1000 + 200);

  return { koniec };
}

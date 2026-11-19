// Dźwięk pokładu: skok w nadprzestrzeń i tło mostka.
//   wejście w skok — hiper_wejscie, potem pętla hiper (każda kopia startuje sekundę przed końcem
//                    poprzedniej, z płynnym przenikaniem) aż do wyjścia;
//   wyjście        — pętla urwana, hiper_wyjscie, zaraz po nim tło mostka;
//   poza skokiem   — tło mostka w pętli (strumieniowo: plik jest duży, nie wczytujemy go w całości).
// Przeglądarka pozwala grać dopiero po pierwszym dotknięciu ekranu — wtedy dźwięk rusza.
// Pliki leżą w sound/ i nie są w pamięci tabletu (service worker ich nie obsługuje).

import { pamiec } from './narzedzia.js';

const PLIKI = { wejscie: 'sound/hiper_wejscie.mp3', petla: 'sound/hiper.mp3', wyjscie: 'sound/hiper_wyjscie.mp3' };
const NAKLADKA = 1;        // s: o tyle kolejna kopia pętli zaczyna się przed końcem poprzedniej
const WYPRZEDZENIE = 90;   // s: tyle pętli planujemy naprzód (karta w tle ma rzadkie zegary)
const GLOSNOSC_TLA = 0.7;

let ctx = null, glowny = null, wczytane = null;
const bufory = {};
let zrodla = [], nastepny = 0, zegarPetli = null;
let tryb = null;           // 'skok' | 'tlo' | 'cisza'
let pokolenie = 0;         // unieważnia spóźnione wywołania po zmianie trybu
let odblokowane = false, oczekujace = null;
let wyciszone = pamiec.czytaj('wyciszone', false);
const sluchacze = new Set();

const tlo = new Audio();
tlo.loop = true;
tlo.preload = 'none';
tlo.volume = GLOSNOSC_TLA;
tlo.muted = wyciszone;
let zegarTla = null, startTla = null;

function kontekst() {
  if (!ctx) {
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return null;
    ctx = new AC();
    glowny = ctx.createGain();
    glowny.gain.value = wyciszone ? 0 : 1;
    glowny.connect(ctx.destination);
  }
  return ctx;
}

function wczytaj() {
  if (!wczytane) {
    wczytane = Promise.all(Object.entries(PLIKI).map(async ([k, url]) => {
      const dane = await (await fetch(url)).arrayBuffer();
      bufory[k] = await new Promise((ok, zle) => ctx.decodeAudioData(dane, ok, zle));
    })).catch((e) => { wczytane = null; throw e; });
  }
  return wczytane;
}

// Jedno odtworzenie bufora od chwili t; przyciszanie na początku i końcu (s). Zwraca koniec.
function graj(buf, t, wejscie = 0, wyjscie = 0) {
  const zr = ctx.createBufferSource(), g = ctx.createGain();
  zr.buffer = buf;
  zr.connect(g).connect(glowny);
  const koniec = t + buf.duration;
  g.gain.setValueAtTime(wejscie ? 0 : 1, t);
  if (wejscie) g.gain.linearRampToValueAtTime(1, t + wejscie);
  if (wyjscie) { g.gain.setValueAtTime(1, koniec - wyjscie); g.gain.linearRampToValueAtTime(0, koniec); }
  zr.start(t);
  const wpis = { zr, g };
  zrodla.push(wpis);
  zr.onended = () => { zrodla = zrodla.filter((x) => x !== wpis); };
  return koniec;
}

function planujPetle() {
  const buf = bufory.petla;
  if (!buf) return;
  while (nastepny < ctx.currentTime + WYPRZEDZENIE) {
    graj(buf, nastepny, NAKLADKA, NAKLADKA);
    nastepny += Math.max(0.5, buf.duration - NAKLADKA);
  }
}

function petlaOd(t) {
  nastepny = t;
  planujPetle();
  clearInterval(zegarPetli);
  zegarPetli = setInterval(planujPetle, 5000);
}

function zatrzymajSkok(zanik = 0.3) {
  clearInterval(zegarPetli);
  zegarPetli = null;
  if (!ctx) return;
  const t = ctx.currentTime;
  for (const { zr, g } of zrodla) {
    try {
      g.gain.cancelScheduledValues(t);
      g.gain.setValueAtTime(g.gain.value, t);
      g.gain.linearRampToValueAtTime(0, t + zanik);
      zr.stop(t + zanik + 0.05);
    } catch { /* źródło jeszcze nie wystartowało — stop i tak je usuwa */ }
  }
  zrodla = [];
}

// Tło: płynne pogłośnienie / wyciszenie elementu audio.
function zmienGlosnosc(cel, czas, poKoncu) {
  clearInterval(zegarTla);
  const od = tlo.volume, krok = 50, ile = Math.max(1, Math.round((czas * 1000) / krok));
  let i = 0;
  zegarTla = setInterval(() => {
    i += 1;
    tlo.volume = Math.max(0, Math.min(1, od + ((cel - od) * i) / ile));
    if (i >= ile) { clearInterval(zegarTla); zegarTla = null; poKoncu?.(); }
  }, krok);
}

function tloStart(opoznienie = 0) {
  clearTimeout(startTla);
  const start = () => {
    if (!tlo.src) tlo.src = 'sound/mostek_isd.mp3';
    tlo.volume = 0;
    tlo.play().then(() => zmienGlosnosc(GLOSNOSC_TLA, 1.5)).catch(() => {});
  };
  // Bez opóźnienia — od razu (w obsłudze dotknięcia, czego wymaga iOS).
  if (opoznienie > 0) startTla = setTimeout(start, opoznienie * 1000); else start();
}

function tloStop(zanik = 1) {
  clearTimeout(startTla);
  if (tlo.paused) return;
  zmienGlosnosc(0, zanik, () => tlo.pause());
}

// Stan sceny z serwera: nazwa sceny i ile ms minęło od jej zmiany. Bez sceny (wylogowanie) — cisza.
export function ustawDzwiek(scena, odZmiany = Infinity) {
  const nowy = !scena ? 'cisza' : (scena === 'wejscie' || scena === 'nadswietlna') ? 'skok' : 'tlo';
  if (!odblokowane) { oczekujace = nowy; return; }
  if (nowy === tryb) return;
  const poprzedni = tryb;
  tryb = nowy;
  const moje = ++pokolenie;

  if (nowy === 'cisza') { zatrzymajSkok(0.5); tloStop(0.5); return; }

  if (nowy === 'skok') {
    tloStop(1);
    if (!kontekst()) return;
    wczytaj().then(() => {
      if (moje !== pokolenie) return;
      const t = ctx.currentTime + 0.05;
      if (scena === 'wejscie' && odZmiany < 8000) petlaOd(graj(bufory.wejscie, t, 0, NAKLADKA) - NAKLADKA);
      else petlaOd(t);
    }).catch(() => {});
    return;
  }

  // Tło. Wyjście ze skoku (także przerwanego) słychać tylko, gdy przed chwilą trwał skok.
  if (poprzedni === 'skok' && odZmiany < 15000 && ctx && bufory.wyjscie) {
    zatrzymajSkok(0.3);
    const t = ctx.currentTime + 0.05;
    graj(bufory.wyjscie, t);
    tloStart(bufory.wyjscie.duration);
  } else {
    zatrzymajSkok(1);
    tloStart(0);
  }
}

function odblokuj() {
  if (odblokowane) return;
  odblokowane = true;
  kontekst()?.resume?.();
  if (oczekujace) { const t = oczekujace; oczekujace = null; ustawDzwiek(t === 'skok' ? 'nadswietlna' : t === 'tlo' ? 'kosmos' : null); }
  for (const z of ['pointerdown', 'keydown', 'touchend']) document.removeEventListener(z, odblokuj, true);
}
for (const z of ['pointerdown', 'keydown', 'touchend']) document.addEventListener(z, odblokuj, true);

export function wyciszony() { return wyciszone; }

export function przelaczWyciszenie() {
  wyciszone = !wyciszone;
  pamiec.zapisz('wyciszone', wyciszone);
  if (glowny) glowny.gain.setTargetAtTime(wyciszone ? 0 : 1, ctx.currentTime, 0.05);
  tlo.muted = wyciszone;
  sluchacze.forEach((f) => f(wyciszone));
  return wyciszone;
}

export function naZmianeWyciszenia(f) { sluchacze.add(f); }

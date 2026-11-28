// Poza nadprzestrzenią gra tło mostka; dźwięki hiper są uruchamiane wyłącznie przez sceny skoku.
// Przeglądarka pozwala grać dopiero po pierwszym dotknięciu ekranu — wtedy dźwięk rusza.
// Pliki leżą w sound/ i nie są w pamięci tabletu (service worker ich nie obsługuje).

import { pamiec } from './narzedzia.js';

const PLIKI = { petla: 'sound/hiper.mp3' };
const NAKLADKA = 1;        // s: o tyle kolejna kopia pętli zaczyna się przed końcem poprzedniej
const WYPRZEDZENIE = 90;   // s: tyle pętli planujemy naprzód (karta w tle ma rzadkie zegary)
const GLOSNOSC_TLA = 0.7;

let ctx = null, glowny = null, wczytane = null;
const bufory = {};
let zrodla = [], nastepny = 0, zegarPetli = null;
let tryb = null;           // 'skok' | 'wyjscie' | 'tlo' | 'cisza'
let pokolenie = 0;         // unieważnia spóźnione wywołania po zmianie trybu
let odblokowane = false, oczekujace = null;
// Ostatnio przyjęta scena (czas zmiany, ms): scena starsza od niej to spóźniona albo „cofnięta” odpowiedź
// serwera — ignorujemy ją, żeby skok nie przerywał się na chwilę.
let zmienionoSceny = 0;
let ostatniaScena = null;
let ostatniEfektPrzejscia = pamiec.czytaj('ostatni_efekt_przejscia', '');
const efekt = new Audio();
efekt.preload = 'none';
let pokolenieEfektu = 0;
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

// Wspólny tor audio dla modułów efektów: efekty dziedziczą wyciszenie główne aplikacji.
export function pobierzTorAudio() {
  const audio = kontekst();
  return audio && { ctx: audio, wyjscie: glowny };
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

function zatrzymajSkok() {
  clearInterval(zegarPetli);
  zegarPetli = null;
  if (!ctx) return;
  for (const { zr, g } of zrodla) {
    try { zr.stop(); } catch { /* źródło już zatrzymane */ }
    try { zr.disconnect(); } catch { /* źródło już odłączone */ }
    try { g.disconnect(); } catch { /* wzmocnienie już odłączone */ }
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

// Odtwarzanie pliku przejścia jest jawnie związane ze sceną wejścia/wyjścia,
// nigdy z końcem ani ponownym startem pliku mostka.
function zagrajEfekt(plik, poKoncu) {
  const id = ++pokolenieEfektu;
  efekt.pause();
  efekt.currentTime = 0;
  efekt.src = `sound/${plik}.mp3`;
  efekt.onended = () => { if (id === pokolenieEfektu) poKoncu?.(); };
  efekt.play().catch(() => { if (id === pokolenieEfektu) poKoncu?.(); });
}

function zatrzymajEfekt() {
  pokolenieEfektu++;
  efekt.onended = null;
  efekt.pause();
  try { efekt.currentTime = 0; } catch { /* strumień jeszcze nie ma metadanych */ }
}

function odtworzPrzejscieRaz(scena, zmieniono, plik, poKoncu) {
  const id = `${scena}:${Number(zmieniono) || 0}`;
  if (id === ostatniEfektPrzejscia) { poKoncu?.(); return; }
  ostatniEfektPrzejscia = id;
  pamiec.zapisz('ostatni_efekt_przejscia', id);
  zagrajEfekt(plik, poKoncu);
}

function tloStopNatychmiast() {
  clearTimeout(startTla);
  clearInterval(zegarTla);
  zegarTla = null;
  tlo.volume = 0;
  tlo.pause();
}

// Jeśli przeglądarka mimo loop zgłosi koniec źródła, wolno jej wznowić wyłącznie ten sam mostek.
tlo.addEventListener('ended', () => {
  if (tryb !== 'tlo') return;
  tlo.currentTime = 0;
  tlo.play().catch(() => {});
});

// Dwa stany dźwięku: hyper podczas skoku oraz tło mostka w każdym innym stanie.
export function ustawDzwiek(scena, odZmiany = Infinity, zmieniono = 0, hiper = false) {
  // Sama nazwa „wyjscie” opisuje też orbitalne podejście i wznoszenie. Dźwięki
  // hiper wolno uruchamiać wyłącznie, gdy API potwierdzi fazę skoku nadświetlnego.
  const fazaHiper = hiper && ['wejscie', 'nadswietlna', 'wyjscie'].includes(scena);
  const nowy = !scena ? 'cisza' : (fazaHiper && scena !== 'wyjscie') ? 'skok' : 'tlo';
  if (nowy !== 'cisza' && zmieniono && zmieniono < zmienionoSceny) return;
  if (nowy === 'cisza') zmienionoSceny = 0;
  else if (zmieniono) {
    if (zmieniono === zmienionoSceny && ostatniaScena && scena !== ostatniaScena) return;
    zmienionoSceny = Math.max(zmienionoSceny, zmieniono);
  }
  if (!odblokowane) { oczekujace = { scena, odZmiany, zmieniono, hiper }; return; }

  // Nie pozwól, by kolejne odpytywanie sceny ucięło jednorazowy dźwięk wyjścia.
  if (tryb === 'wyjscie' && nowy === 'tlo' && scena !== 'wejscie') { ostatniaScena = scena; return; }
  const poprzedniaScena = ostatniaScena;
  ostatniaScena = scena;
  if (nowy === 'cisza') {
    tryb = 'cisza'; ostatniaScena = null; ++pokolenie; zatrzymajSkok(); zatrzymajEfekt(); tloStopNatychmiast(); return;
  }
  if (scena === 'wyjscie' && fazaHiper && poprzedniaScena !== 'wyjscie') {
    tryb = 'wyjscie'; ++pokolenie; zatrzymajSkok(); tloStopNatychmiast();
    odtworzPrzejscieRaz(scena, zmieniono, 'hiper_wyjscie', () => {
      if (tryb !== 'wyjscie') return;
      tryb = 'tlo'; tloStart(0);
    });
    return;
  }
  if (nowy === 'skok') {
    if (tryb === 'skok') return;
    tryb = 'skok'; zatrzymajEfekt(); tloStopNatychmiast();
    const moje = ++pokolenie;
    const startPetli = () => {
      if (moje !== pokolenie || tryb !== 'skok') return;
      if (!kontekst()) return;
      wczytaj().then(() => {
        if (moje === pokolenie && tryb === 'skok') petlaOd(ctx.currentTime + 0.05);
      }).catch(() => {});
    };
    if (scena === 'wejscie' && fazaHiper) odtworzPrzejscieRaz(scena, zmieniono, 'hiper_wejscie', startPetli);
    else startPetli(); // np. otwarcie aplikacji już w trwającej nadprzestrzeni
    return;
  }
  if (nowy === tryb) return;
  tryb = 'tlo'; ++pokolenie; zatrzymajSkok(); zatrzymajEfekt(); tloStart(0);
}

function odblokuj() {
  if (odblokowane) return;
  odblokowane = true;
  kontekst()?.resume?.();
  if (oczekujace) { const t = oczekujace; oczekujace = null; ustawDzwiek(t.scena, t.odZmiany, t.zmieniono, t.hiper); }
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

// HOLOLINK — powłoka datapada: start, rozruch, blokada, pulpit, aplikacje, alfabet.

import { h, wyczysc, ikona, godzina, dataGST, czekaj, pamiec } from './rdzen/narzedzia.js';
import { api } from './rdzen/api.js';
import { URZADZENIE } from './rdzen/urzadzenie.js';
import { utworzScene, TYPY_PLANET } from './rdzen/scena.js';
import { ustawDzwiek, przelaczWyciszenie, wyciszony } from './rdzen/dzwiek.js';

import holonet from './aplikacje/holonet.js';
import kalendarz from './aplikacje/kalendarz.js';
import dekoder from './aplikacje/dekoder.js';
import frakcja from './aplikacje/frakcja.js';
import ustawienia from './aplikacje/ustawienia.js';
import bank from './aplikacje/bank.js';
import firma from './aplikacje/firma.js';
import karta from './aplikacje/karta.js';
import nawigacja, { odliczanie } from './aplikacje/nawigacja.js';
import mapa from './aplikacje/mapa.js';
import gielda from './aplikacje/gielda.js';

const APLIKACJE = [karta, nawigacja, mapa, holonet, bank, gielda, firma, dekoder, kalendarz, frakcja, ustawienia];

const html = document.documentElement;
const pulpit = document.getElementById('pulpit');
const pasek = document.getElementById('pasek');
const dok = document.getElementById('dok');
const tytulPaska = document.getElementById('pasek-tytul');

const stan = {
  uzytkownik: null,
  aplikacja: null,      // { def, sprzataj, wstecz }
};

// ================================================================
// Alfabet: Aurebesh (fabrycznie) albo łaciński (moduł ZPH od hakerów z Zephyrii)
// ================================================================

const alfabet = {
  get lacinski() {
    return !html.classList.contains('aurebesh');
  },
  ustaw(lacinski, efekt = true) {
    const zmien = () => html.classList.toggle('aurebesh', !lacinski);
    pamiec.zapisz('lacinski', lacinski);
    if (!efekt) return zmien();
    zaklocenia();
    setTimeout(zmien, 180);
    window.dispatchEvent(new CustomEvent('datapad:alfabet', { detail: { lacinski } }));
  },
  przelacz() {
    this.ustaw(!this.lacinski);
  },
};

function zaklocenia() {
  const z = document.getElementById('zaklocenia');
  z.classList.remove('aktywne');
  html.classList.remove('glitch');
  void z.offsetWidth;
  z.classList.add('aktywne');
  html.classList.add('glitch');
  setTimeout(() => html.classList.remove('glitch'), 600);
}

document.getElementById('przelacznik-alfabetu').addEventListener('click', () => alfabet.przelacz());

// ================================================================
// Pełny ekran i wybudzenie
// ================================================================

async function pelnyEkran() {
  const el = document.documentElement;
  try {
    if (!document.fullscreenElement && el.requestFullscreen) await el.requestFullscreen({ navigationUI: 'hide' });
  } catch { /* np. iPad w Safari: pełny ekran tylko po dodaniu do ekranu głównego */ }
  try {
    await screen.orientation?.lock?.('landscape');
  } catch { /* nie każde urządzenie pozwala */ }
  utrzymajEkran();
}

let blokadaUspienia = null;
async function utrzymajEkran() {
  try {
    if ('wakeLock' in navigator && !blokadaUspienia) {
      blokadaUspienia = await navigator.wakeLock.request('screen');
      blokadaUspienia.addEventListener('release', () => { blokadaUspienia = null; });
    }
  } catch { /* brak zgody — ekran może gasnąć */ }
}
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible') utrzymajEkran();
});

// Bez menu kontekstowego i przeciągania obrazków — to urządzenie, nie strona.
document.addEventListener('contextmenu', (e) => {
  if (!e.target.closest('input, textarea')) e.preventDefault();
});
document.addEventListener('dragstart', (e) => e.preventDefault());

// ================================================================
// Pasek stanu
// ================================================================

function odswiezZegar() {
  document.getElementById('pasek-zegar').textContent = godzina();
  document.querySelectorAll('[data-zegar]').forEach((el) => { el.textContent = godzina(); });
  document.querySelectorAll('[data-data]').forEach((el) => { el.textContent = dataGST(); });
}
setInterval(odswiezZegar, 1000 * 10);

async function bateria() {
  try {
    const b = await navigator.getBattery?.();
    if (!b) return;
    const el = document.getElementById('pasek-bateria');
    const pokaz = () => {
      el.hidden = false;
      el.textContent = `${b.charging ? '⚡' : '▮'}${Math.round(b.level * 100)}%`;
    };
    pokaz();
    b.addEventListener('levelchange', pokaz);
    b.addEventListener('chargingchange', pokaz);
  } catch { /* brak czujnika */ }
}

// ================================================================
// Scena w tle: „stan faktyczny” postaci (ustawia MG). Widoczna na ekranie startowym,
// blokadzie i pulpicie; w aplikacjach zatrzymana, żeby oszczędzać baterię.
// ================================================================

const plotno = h('canvas#scena', { 'aria-hidden': 'true' });
document.getElementById('ekran').prepend(plotno);
const scena = utworzScene(plotno);
let stanSceny = pamiec.czytaj('scena', null);
if (stanSceny) scena.ustaw({ ...stanSceny, zmieniono: 0 }); // z pamięci: bez animacji przejścia

const NAZWY_SCEN = { kosmos: 'Przestrzeń', wejscie: 'Skok w nadprzestrzeń', nadswietlna: 'Nadprzestrzeń', wyjscie: 'Wyjście z nadprzestrzeni', orbita: 'Orbita', powierzchnia: '', start: 'Wznoszenie' };
let przesuniecieZegara = 0; // zegar serwera − zegar tabletu (ms)

function tlo(widoczne) {
  html.classList.toggle('z-tlem', widoczne);
  if (widoczne && document.visibilityState === 'visible') scena.start();
  else scena.stop();
}
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState !== 'visible') scena.stop();
  else if (html.classList.contains('z-tlem')) scena.start();
});

// ================================================================
// Gwiazdy za oknem: najechanie albo dotknięcie zaznacza gwiazdę. Układ znany graczowi — nazwa;
// nieznany (albo zwykła gwiazda tła) — klasa, odległość i skan liczby planet. Prawdziwe układy
// wyglądają tak samo jak tysiące gwiazd tła, więc gracz nie wie, na którą warto patrzeć.
// ================================================================

const KLASY_GWIAZD = { O: 'błękitny olbrzym', B: 'błękitna', A: 'biała', F: 'żółtobiała', G: 'żółta', K: 'pomarańczowa', M: 'czerwony karzeł', WD: 'biały karzeł', RG: 'czerwony olbrzym' };
const WAGI_KLAS = [['O', 1], ['B', 3], ['A', 5], ['F', 8], ['G', 15], ['K', 25], ['M', 40], ['WD', 2], ['RG', 1]];
const skrot = (t) => { let x = 2166136261; for (const c of t) x = Math.imul(x ^ c.charCodeAt(0), 16777619); return x >>> 0; };
const losZ = (ziarno) => { let s = ziarno || 1; return () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296); };

function opisGwiazdy(s) {
  if (s.cialo) return { klucz: s.klucz, cialo: s.cialo };
  if (s.g) return { klucz: s.klucz, nazwa: s.g.n || null, klasa: s.g.typ || 'G5', d: s.g.d, znacznik: s.g.t || null };
  // Gwiazda tła: klasa, odległość i liczba planet stałe dla tej gwiazdy (z jej miejsca na niebie).
  const los = losZ(skrot(s.klucz));
  let r = los() * 100, typ = 'M';
  for (const [t, w] of WAGI_KLAS) { if (r < w) { typ = t; break; } r -= w; }
  const d = Math.round(150 + 2600 * los() ** 1.6 * (1.2 - (s.jasnosc || 0.5)));
  const p = los();
  return { klucz: s.klucz, nazwa: null, klasa: typ + Math.floor(los() * 10), d, tlo: p < 0.35 ? 0 : Math.floor(1 + los() * 8) };
}

const gwiazdaInfo = h('div.gwiazda-info', { hidden: true });
let gwiazdaPrzypieta = null;
const skany = () => pamiec.czytaj('skany', {});
const odmianaPlanet = (n) => (n === 1 ? 'planeta' : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20) ? 'planety' : 'planet');

// Ciało tego układu za oknem (słońce, planeta, księżyc): nazwa, rodzaj i odległość.
const AU_KM = 149597870.7;
const odlCiala = (km) => (km >= AU_KM * 0.05 ? `${(km / AU_KM).toLocaleString('pl-PL', { maximumFractionDigits: 2 })} j.a.`
  : `${Math.round(km).toLocaleString('pl-PL')} km`);
function pokazCialo(s, c) {
  const naglowek = c.rodzaj === 'gwiazda' ? `Gwiazda układu ${c.nazwa}` : c.rodzaj === 'ksiezyc' ? `Księżyc ${c.nazwa}` : `Planeta ${c.nazwa}`;
  const klasa = c.rodzaj === 'gwiazda' ? `klasa ${c.typ} · ${KLASY_GWIAZD[String(c.typ).replace(/\d$/, '')] || c.opis || 'gwiazda'}` : c.opis;
  wyczysc(gwiazdaInfo).append(h('b', naglowek), klasa ? h('div', klasa) : null,
    c.km ? h('div.slaby', `odległość ${odlCiala(c.km)}`) : null, h('div.slaby', 'W bazie nawigacyjnej'));
  if (!gwiazdaInfo.isConnected) pulpit.append(gwiazdaInfo);
  gwiazdaInfo.hidden = false;
  const pr = pulpit.getBoundingClientRect(), cr = plotno.getBoundingClientRect();
  const x = s.x + cr.left - pr.left, y = s.y + cr.top - pr.top;
  gwiazdaInfo.style.left = `${Math.max(8, Math.min(x + 18 + (c.r || 0), pr.width - 230))}px`;
  gwiazdaInfo.style.top = `${Math.max(8, Math.min(y - 12, pr.height - 140))}px`;
}

function pokazGwiazde(s, przypnij) {
  if (!s) {
    gwiazdaInfo.hidden = true; gwiazdaPrzypieta = null; scena.zaznacz(null); return;
  }
  const o = opisGwiazdy(s);
  scena.zaznacz(o.klucz);
  if (!przypnij && gwiazdaPrzypieta) return;
  gwiazdaPrzypieta = przypnij ? o.klucz : null;
  if (o.cialo) return pokazCialo(s, o.cialo);
  const klasa = o.klasa.replace(/\d$/, '');
  const wynik = h('div.gwiazda-skan');
  const pokazWynik = (n) => wyczysc(wynik).append(h('div', n ? `Skan: ${n} ${odmianaPlanet(n)} w układzie` : 'Skan: brak planet'));
  const znany = skany()[o.klucz];
  if (o.nazwa) wynik.append(h('div.slaby', 'Układ w bazie nawigacyjnej'));
  else if (znany != null) pokazWynik(znany);
  else if (przypnij) {
    wynik.append(h('button.przycisk.maly', { onclick: async (e) => {
      e.stopPropagation();
      wyczysc(wynik).append(h('div.gwiazda-skanuje', 'Skanowanie…'));
      let n = o.tlo ?? 0;
      try {
        const [odp] = await Promise.all([o.znacznik ? api('gwiazda_skan', { t: o.znacznik }) : null, czekaj(2200 + Math.random() * 1200)]);
        if (odp) n = odp.planety;
      } catch (blad) { wyczysc(wynik).append(h('div.slaby', blad.message)); return; }
      const zapis = skany(); zapis[o.klucz] = n;
      const klucze = Object.keys(zapis); if (klucze.length > 400) delete zapis[klucze[0]];
      pamiec.zapisz('skany', zapis);
      if (gwiazdaPrzypieta === o.klucz) pokazWynik(n);
    } }, 'Przeskanuj'));
  }
  wyczysc(gwiazdaInfo).append(
    h('b', o.nazwa || 'Nieznana gwiazda'),
    h('div', `klasa ${o.klasa} · ${KLASY_GWIAZD[klasa] || 'gwiazda'}`),
    h('div.slaby', `ok. ${o.d.toLocaleString('pl-PL', { maximumFractionDigits: o.d < 10 ? 1 : 0 })} pc`),
    wynik);
  if (!gwiazdaInfo.isConnected) pulpit.append(gwiazdaInfo);
  gwiazdaInfo.hidden = false;
  const pr = pulpit.getBoundingClientRect(), cr = plotno.getBoundingClientRect();
  const x = s.x + cr.left - pr.left, y = s.y + cr.top - pr.top;
  gwiazdaInfo.style.left = `${Math.max(8, Math.min(x + 18, pr.width - 230))}px`;
  gwiazdaInfo.style.top = `${Math.max(8, Math.min(y - 12, pr.height - 140))}px`;
}

function gwiazdaZdarzenia(e) {
  return stan.uzytkownik && !stan.aplikacja && html.classList.contains('z-tlem')
    && !e.target.closest('button, a, input, select, textarea, label, .ikona-aplikacji, .gwiazda-info, .pulpit-glowa > *, .szybkie-akcje > *');
}
function gwiazdaWPunkcie(e) {
  const r = plotno.getBoundingClientRect();
  return scena.gwiazdaPod(e.clientX - r.left, e.clientY - r.top);
}
pulpit.addEventListener('pointermove', (e) => {
  if (e.pointerType !== 'mouse' || gwiazdaPrzypieta) return;
  const s = gwiazdaZdarzenia(e) ? gwiazdaWPunkcie(e) : null;
  pulpit.style.cursor = s ? 'pointer' : '';
  pokazGwiazde(s, false);
});
pulpit.addEventListener('click', (e) => {
  if (e.target.closest('.gwiazda-info')) return;
  if (!gwiazdaZdarzenia(e)) return;
  const s = gwiazdaWPunkcie(e);
  if (!s && !gwiazdaPrzypieta) return;
  gwiazdaPrzypieta = null;
  pokazGwiazde(s, true);
});

function opisMiejsca() {
  if (!stanSceny) return '';
  const typ = TYPY_PLANET[stanSceny.planeta]?.nazwa;
  // Po kilku sekundach skok staje się lotem w nadprzestrzeni, a wyjście — orbitą (tak jak tło).
  const odZmiany = Date.now() + przesuniecieZegara - (stanSceny.zmieniono || 0);
  const dawno = odZmiany > 5000;
  let sc = dawno ? ({ wejscie: 'nadswietlna', wyjscie: 'orbita' }[stanSceny.scena] || stanSceny.scena) : stanSceny.scena;
  if (sc === 'start' && odZmiany > 300000) sc = 'orbita'; // po wznoszeniu statek jest na orbicie
  if (sc === 'powierzchnia') return [stanSceny.miejsce, stanSceny.opis].filter(Boolean).join(' · ');
  const miejsce = stanSceny.miejsce || (sc === 'orbita' && typ ? `planeta ${typ}` : '');
  // Na krawędzi studni grawitacyjnej planeta jest daleko (miliony km) — stąd mała w oknie.
  const krawedz = (sc === 'orbita' || sc === 'wyjscie') && stanSceny.odleglosc === 'krawedz' ? 'krawędź układu' : '';
  return [NAZWY_SCEN[sc], miejsce, krawedz, stanSceny.opis].filter(Boolean).join(' · ');
}

// Liczniki pokładowe (tylko na pokładzie): odliczanie bieżącego procesu i stany: paliwo,
// reaktor, osłony, kadłub. Dyskretny pasek w prawym dolnym rogu pulpitu; odświeżany ze sceną.
let liczniki = null;
const NAZWY_LICZNIKOW = [['paliwo', 'Paliwo'], ['reaktor', 'Reaktor'], ['oslony', 'Osłony'], ['kadlub', 'Kadłub']];
// Progi ostrzeżeń: [pomarańczowy, czerwony]; reaktor ostrzega przy wysokim obciążeniu.
const PROGI = { paliwo: [15, 5], oslony: [25, 10], kadlub: [40, 20] };

function poziomLicznika(klucz, v) {
  if (klucz === 'reaktor') return v >= 95 ? 'kryt' : v >= 85 ? 'ostrz' : '';
  const [o, k] = PROGI[klucz] || [0, 0];
  return v <= k ? 'kryt' : v <= o ? 'ostrz' : '';
}

function pozostalo(koniec) {
  const s = Math.max(0, Math.round(koniec - (Date.now() + przesuniecieZegara) / 1000));
  const p = (n) => String(n).padStart(2, '0');
  return s <= 0 ? 'teraz' : `${Math.floor(s / 3600)}:${p(Math.floor((s % 3600) / 60))}:${p(s % 60)}`;
}

function rysujLiczniki() {
  // Kafelek odliczania (tylko gdy coś trwa): etap, czas i pasek postępu.
  document.querySelectorAll('[data-odliczanie]').forEach((el) => {
    wyczysc(el);
    const o = liczniki?.odliczanie;
    el.hidden = !o;
    if (!o) return;
    el.append(h('span.kafel-etykieta', o.etykieta), h('b.kafel-czas', { 'data-koniec': String(o.koniec) }, pozostalo(o.koniec)),
      o.od ? h('i.kafel-postep', h('i', { 'data-od': String(o.od), 'data-do': String(o.koniec) })) : null);
  });
  // Naprawy w stoczni: kafelek na każdą pracę w toku (nazwa podzespołu, prognoza do końca) i kafelek całości.
  document.querySelectorAll('[data-naprawy]').forEach((el) => {
    wyczysc(el);
    const n = liczniki?.naprawa;
    if (!n?.naprawy?.length) return;
    const KLUCZ = '<path d="M30 8a9 9 0 0 0-8 12L9 33a3 3 0 0 0 4 4l13-13a9 9 0 0 0 12-8l-5 5-5-1-1-5z"/>';
    for (const x of n.naprawy.filter((r) => r.stan === 'w_toku')) {
      el.append(h('div.ikona-aplikacji.kafel-odliczanie.kafel-naprawa', { title: `Naprawa: ${x.nazwa} (od ${x.od_wartosci}%)` },
        ikona(KLUCZ), h('span.kafel-etykieta', `Naprawa: ${x.nazwa}`),
        h('b.kafel-czas', { 'data-koniec': String(x.koniec), 'data-opoznienie': '1' }, x.opoznienie ? 'opóźnienie' : pozostalo(x.koniec)),
        h('i.kafel-postep', h('i', { 'data-od': String(x.od), 'data-do': String(x.koniec) }))));
    }
    const czeka = n.naprawy.filter((r) => r.stan === 'kolejka').length;
    el.append(h('div.ikona-aplikacji.kafel-odliczanie.kafel-naprawa', { title: n.stocznia },
      h('span.kafel-etykieta', `Okręt w doku${czeka ? ` · w kolejce: ${czeka}` : ''}`),
      h('b.kafel-czas', { 'data-koniec': String(n.koniec), 'data-opoznienie': '1' }, pozostalo(n.koniec)),
      h('span.kafel-etykieta', 'prognoza całości')));
  });
  // Kafelek stanu: zawsze cztery paski (statek firmy: paliwo).
  document.querySelectorAll('[data-liczniki]').forEach((el) => {
    wyczysc(el);
    el.hidden = !liczniki;
    if (!liczniki) return;
    for (const [k, nazwa] of NAZWY_LICZNIKOW) {
      if (liczniki[k] == null) continue;
      const v = Math.max(0, Math.min(100, Number(liczniki[k])));
      el.append(h('div.licznik' + (poziomLicznika(k, v) ? '.' + poziomLicznika(k, v) : ''),
        h('span', nazwa), h('i', h('i', { style: `width:${v}%` })), h('b', `${v}%`)));
    }
  });
}
setInterval(() => {
  const teraz = (Date.now() + przesuniecieZegara) / 1000;
  document.querySelectorAll('.kafel-czas[data-koniec]').forEach((el) => {
    const koniec = Number(el.dataset.koniec);
    // Naprawa dłuższa niż prognoza stoczni: „opóźnienie” zamiast zera (gracz nie zna faktycznego czasu).
    el.textContent = el.dataset.opoznienie && koniec <= teraz ? 'opóźnienie' : pozostalo(koniec);
    el.classList.toggle('ostrz', koniec - teraz < 60);
  });
  document.querySelectorAll('[data-od]').forEach((el) => {
    const od = Number(el.dataset.od), d = Number(el.dataset.do);
    el.style.width = `${Math.max(0, Math.min(100, ((teraz - od) / Math.max(1, d - od)) * 100))}%`;
  });
}, 1000);

// Aparat: przycisk „Zdjęcie” na pulpicie, gdy słońce układu jest w kadrze (wyczyn Fotograf gwiazd).
const IKONA_APARATU = '<rect x="8" y="16" width="32" height="22" rx="3"/><circle cx="24" cy="27" r="6"/><path d="M18 16l3-5h6l3 5"/>';
function komunikatPulpitu(tekst) {
  const el = h('div.pulpit-komunikat', tekst);
  document.getElementById('pulpit')?.append(el);
  setTimeout(() => el.remove(), 6000);
}
async function zrobZdjecie(e) {
  const przycisk = e.currentTarget;
  przycisk.disabled = true;
  const obraz = scena.zdjecie();
  const blysk = h('div.pulpit-blysk');
  document.getElementById('pulpit')?.append(blysk);
  setTimeout(() => blysk.remove(), 450);
  try {
    const w = await api('zdjecie_slonca', { obraz });
    const p = w.postep;
    komunikatPulpitu(w.nowe
      ? `Sfotografowano słońce układu ${w.uklad} (${w.gwiazda}). Album: ${p.liczba} z ${p.wszystkie} układów (${p.procent}%)${p.prog ? ` · ${p.prog.nazwa}` : ''}.`
      : `Słońce układu ${w.uklad} jest już w albumie.`);
  } catch (err) { komunikatPulpitu(err.message); }
  przycisk.disabled = false;
}
setInterval(() => {
  const widac = !!stan.uzytkownik && scena.slonceWidoczne();
  document.querySelectorAll('[data-aparat]').forEach((el) => { el.hidden = !widac; });
}, 1000);

async function sprawdzScene() {
  if (!stan.uzytkownik) return;
  try {
    const { scena: s, teraz, liczniki: l } = await api('scena');
    liczniki = l || null;
    rysujLiczniki();
    przesuniecieZegara = teraz - Date.now();
    stanSceny = s;
    pamiec.zapisz('scena', s);
    scena.ustaw({ ...s, przesuniecie: przesuniecieZegara });
    // Dźwięk: skok w nadprzestrzeń albo tło mostka (także bez sceny — poza skokiem gra zawsze tło).
    ustawDzwiek(s?.scena || 'kosmos', Date.now() + przesuniecieZegara - (s?.zmieniono || 0), Number(s?.zmieniono) || 0);
    document.querySelectorAll('[data-miejsce]').forEach((el) => { el.textContent = opisMiejsca(); });
  } catch { /* brak zasięgu — zostaje ostatni znany stan */ }
}
setInterval(sprawdzScene, 6000);
// Podpis na pulpicie dogania tło (skok → nadprzestrzeń) bez czekania na serwer.
setInterval(() => document.querySelectorAll('[data-miejsce]').forEach((el) => { el.textContent = opisMiejsca(); }), 2000);

// Wyciszenie: mała ikonka w prawym dolnym rogu (na doku).
const GLOSNIK = '<path d="M8 19h7l9-8v26l-9-8H8z"/>';
const przyciskDzwieku = h('button#dok-dzwiek', { type: 'button' });
function rysujPrzyciskDzwieku() {
  const w = wyciszony();
  wyczysc(przyciskDzwieku).append(ikona(GLOSNIK + (w ? '<path d="M31 18l10 12M41 18L31 30"/>' : '<path d="M31 19c3 3 3 7 0 10M35 15c5 5 5 13 0 18"/>')));
  przyciskDzwieku.setAttribute('aria-label', w ? 'Włącz dźwięk' : 'Wycisz');
  przyciskDzwieku.title = w ? 'Włącz dźwięk' : 'Wycisz';
  przyciskDzwieku.classList.toggle('wyciszony', w);
}
przyciskDzwieku.addEventListener('click', () => { przelaczWyciszenie(); rysujPrzyciskDzwieku(); });
rysujPrzyciskDzwieku();
dok.append(przyciskDzwieku);

// Małe ikonki w narożnikach doku (ta sama wielkość co głośnik): po prawej „Dostosuj pulpit” tuż obok
// głośnika, po lewej Ustawienia i Przewodnik. Ustawienia nie są kafelkiem na pulpicie.
const IKONA_DOSTOSUJ = '<rect x="8" y="8" width="13" height="13" rx="2"/><rect x="27" y="8" width="13" height="13" rx="2"/><rect x="8" y="27" width="13" height="13" rx="2"/><path d="M33.5 27v13M27 33.5h13"/>';
const IKONA_GOTOWE = '<path d="M10 25l9 9 19-20"/>';
const IKONA_USTAWIEN = '<circle cx="24" cy="24" r="6"/><path d="M24 6v6M24 36v6M6 24h6M36 24h6M11 11l4 4M33 33l4 4M11 37l4-4M33 15l4-4"/>';
const IKONA_PRZEWODNIKA = '<circle cx="24" cy="24" r="17"/><path d="M19 19c0-3 2.5-5 5-5s5 2 5 5c0 4-5 4-5 8"/><path d="M24 33v1"/>';
const ikonkaDoku = (id, rysunek, tytul, fn) => {
  const b = h(`button#${id}.dok-ikonka`, { type: 'button', title: tytul, 'aria-label': tytul, onclick: fn });
  b.append(ikona(rysunek));
  return b;
};
const przyciskDostosuj = ikonkaDoku('dok-dostosuj', IKONA_DOSTOSUJ, 'Dostosuj pulpit', () => {
  if (!stan.uzytkownik) return;
  if (!document.querySelector('.siatka-aplikacji')) { ekranPulpitu(); edycjaPulpitu = true; }
  else edycjaPulpitu = !edycjaPulpitu;
  rysujPrzyciskDostosuj();
  const siatka = document.querySelector('.siatka-aplikacji');
  if (siatka) rysujIkony(siatka);
});
function rysujPrzyciskDostosuj() {
  wyczysc(przyciskDostosuj).append(ikona(edycjaPulpitu ? IKONA_GOTOWE : IKONA_DOSTOSUJ));
  const t = edycjaPulpitu ? 'Gotowe (koniec dostosowania)' : 'Dostosuj pulpit';
  przyciskDostosuj.title = t; przyciskDostosuj.setAttribute('aria-label', t);
  przyciskDostosuj.classList.toggle('aktywny', edycjaPulpitu);
}
const przyciskUstawien = ikonkaDoku('dok-ustawienia', IKONA_USTAWIEN, 'Ustawienia', () => stan.uzytkownik && otworz('ustawienia'));
const przyciskPrzewodnika = ikonkaDoku('dok-przewodnik', IKONA_PRZEWODNIKA, 'Przewodnik', async () => {
  if (!stan.uzytkownik) return;
  if (!document.querySelector('.siatka-aplikacji')) ekranPulpitu();
  if (!przewodnik) await wczytajPrzewodnik();
  if (przewodnik.ukonczony || przewodnik.pominiety) przewodnik = { krok: 0 };
  pokazPrzewodnik(true);
});
dok.append(przyciskDostosuj, przyciskUstawien, przyciskPrzewodnika);

function pokazPowloke(widoczna) {
  pasek.hidden = !widoczna;
  dok.hidden = !widoczna;
}

// ================================================================
// Ekrany
// ================================================================

function widok(...zawartosc) {
  wyczysc(pulpit);
  const w = h('section.widok', ...zawartosc);
  pulpit.append(w);
  return w;
}

// 1. Ekran uśpienia: pierwsze dotknięcie włącza pełny ekran (przeglądarki wymagają gestu).
function ekranStartowy() {
  pokazPowloke(false);
  tlo(true);
  html.classList.add('aurebesh');
  wyczysc(pulpit);
  const s = h('div.start', { role: 'button', tabindex: 0 },
    h('div', h('div.start-logo', URZADZENIE.system), h('div.start-pod', 'Dotknij, aby wybudzić')));
  const dalej = async () => {
    s.removeEventListener('click', dalej);
    await pelnyEkran();
    rozruch();
  };
  s.addEventListener('click', dalej);
  s.addEventListener('keydown', (e) => { if (e.key === 'Enter') dalej(); });
  pulpit.append(s);
}

// 2. Rozruch: fabryczny system mówi w Aurebeshu, potem wchodzi moduł hakerów.
async function rozruch() {
  tlo(false);
  const r = h('div.rozruch');
  wyczysc(pulpit).append(r);
  const linia = async (tekst, klasa = '', pauza = 220) => {
    const l = h('div.linia' + (klasa ? '.' + klasa : ''), tekst);
    r.append(l);
    await czekaj(pauza);
    return l;
  };

  html.classList.add('aurebesh');
  await linia(`${URZADZENIE.producent} · ${URZADZENIE.model}`, '', 300);
  await linia(`${URZADZENIE.system} ${URZADZENIE.wersja}`, '', 300);
  await linia('Test pamięci', 'ok', 180);
  await linia('Nadajnik holonetu', 'ok', 260);
  await linia('Moduł kryptograficzny', 'ok', 260);
  await linia('Weryfikacja podpisu oprogramowania...', '', 500);

  const sesja = api('stan').catch(() => null);

  await linia('!! Nieautoryzowana modyfikacja jądra', 'zph', 300);
  zaklocenia();
  await linia('>> ZPH // wstrzykiwanie modułu transliteracji', 'zph', 400);
  zaklocenia();
  await czekaj(200);
  alfabet.ustaw(pamiec.czytaj('lacinski', true), false);
  await linia('>> ZPH // podpis sfałszowany. Witaj w sieci, przyjacielu.', 'zph', 700);
  await linia('Uruchamianie powłoki...', '', 400);

  const s = await sesja;
  if (s?.uzytkownik) {
    stan.uzytkownik = s.uzytkownik;
    ekranPulpitu();
  } else {
    ekranBlokady(s === null ? 'Brak połączenia z holonetem. Tryb ograniczony.' : '');
  }
}

// 3. Blokada: jedno konto do wszystkiego (identyfikator + klucz).
function ekranBlokady(komunikat = '') {
  tlo(true);
  zamknijAplikacje();
  pokazPowloke(false);
  stan.uzytkownik = null;
  ukryte = null;
  przewodnik = null;
  // Pamięć sesji (np. zoom mapy) kończy się z logowaniem.
  try { Object.keys(sessionStorage).filter((k) => k.startsWith('hololink.mapa.')).forEach((k) => sessionStorage.removeItem(k)); } catch { /* bez pamięci */ }
  ustawDzwiek(null);
  const bladEl = h('div');
  const login = h('input', { autocomplete: 'username', autocapitalize: 'off', spellcheck: false, required: true });
  const klucz = h('input', { type: 'password', autocomplete: 'current-password', required: true });
  const przycisk = h('button.przycisk', { type: 'submit', style: 'width:100%' }, 'Odblokuj');
  const pokazBlad = (t) => wyczysc(bladEl).append(h('div.komunikat.blad', t));
  if (komunikat) pokazBlad(komunikat);

  const formularz = h('form.blokada-karta',
    {
      onsubmit: async (e) => {
        e.preventDefault();
        przycisk.disabled = true;
        try {
          const odp = await api('zaloguj', { login: login.value, klucz: klucz.value });
          stan.uzytkownik = odp.uzytkownik;
          ukryte = null;
          zaklocenia();
          ekranPulpitu();
        } catch (err) {
          pokazBlad(err.message);
          klucz.value = '';
          przycisk.disabled = false;
        }
      },
    },
    h('div.blokada-zegar', { 'data-zegar': '' }, godzina()),
    h('div.blokada-data', { 'data-data': '' }, dataGST()),
    h('div.panel',
      h('div.pole', h('label', 'Identyfikator'), login),
      h('div.pole', h('label', 'Klucz dostępu'), klucz),
      bladEl,
      przycisk,
    ),
  );
  wyczysc(pulpit).append(h('div.blokada', formularz));
  // Przełącznik alfabetu jest dostępny także na blokadzie.
  formularz.append(h('div', { style: 'text-align:center;margin-top:18px' },
    h('button.przelacznik', { type: 'button', onclick: () => alfabet.przelacz() },
      h('span.lat', 'Aa'), h('span.aur', 'Aa'))));
  // Nowy gracz: kod zaproszenia od MG i własne hasło (pierwsze wejście).
  const aktywacja = formularzZaproszenia(() => { aktywacja.hidden = true; formularz.hidden = false; });
  aktywacja.hidden = true;
  formularz.querySelector('.panel').append(h('button.przycisk.drugi', { type: 'button', style: 'width:100%;margin-top:10px',
    onclick: () => { formularz.hidden = true; aktywacja.hidden = false; } }, 'Mam kod zaproszenia'));
  pulpit.querySelector('.blokada').append(aktywacja);
}

function formularzZaproszenia(wroc) {
  const bladEl = h('div');
  const login = h('input', { autocomplete: 'username', autocapitalize: 'off', spellcheck: false, required: true });
  const kod = h('input', { autocomplete: 'one-time-code', autocapitalize: 'characters', spellcheck: false, required: true, placeholder: 'XXXX-XXXX' });
  const klucz = h('input', { type: 'password', autocomplete: 'new-password', required: true, minlength: 6 });
  const klucz2 = h('input', { type: 'password', autocomplete: 'new-password', required: true, minlength: 6 });
  const przycisk = h('button.przycisk', { type: 'submit', style: 'width:100%' }, 'Aktywuj i wejdź');
  return h('form.blokada-karta', {
    onsubmit: async (e) => {
      e.preventDefault();
      przycisk.disabled = true;
      try {
        const odp = await api('zaproszenie_aktywuj', { login: login.value, kod: kod.value, klucz: klucz.value, klucz2: klucz2.value });
        stan.uzytkownik = odp.uzytkownik;
        ukryte = null;
        zaklocenia();
        ekranPulpitu();
      } catch (err) {
        wyczysc(bladEl).append(h('div.komunikat.blad', err.message));
        przycisk.disabled = false;
      }
    },
  },
  h('div.panel',
    h('h2', { style: 'margin-top:0' }, 'Pierwsze wejście'),
    h('p.slaby.maly-tekst', 'Wpisz identyfikator i kod zaproszenia od MG, potem wybierz własny klucz dostępu (co najmniej 6 znaków).'),
    h('div.pole', h('label', 'Identyfikator'), login),
    h('div.pole', h('label', 'Kod zaproszenia'), kod),
    h('div.pole', h('label', 'Nowy klucz dostępu'), klucz),
    h('div.pole', h('label', 'Powtórz klucz'), klucz2),
    bladEl, przycisk,
    h('button.przycisk.drugi', { type: 'button', style: 'width:100%;margin-top:10px', onclick: wroc }, 'Wróć do logowania')));
}

// Przewodnik pierwszego wejścia: cztery kroki, które można pominąć i wznowić („Przewodnik” na pulpicie).
// Stan w ustawieniach konta (MG widzi, czy gracz go przejrzał).
const KROKI_PRZEWODNIKA = [
  ['Twoja postać', 'Karta postaci pokazuje, kim jesteś: frakcję, rangę, umiejętności i ekwipunek. Większość ustawia MG.', 'karta'],
  ['Gdzie jesteś', 'Nawigacja mówi, gdzie stoisz i dokąd możesz pójść albo pojechać. Na okręcie — po pokładach.', 'nawigacja'],
  ['Holonet i wiadomość od MG', 'W Holonecie są wiadomości i rozkazy. Zajrzyj tam — MG mógł zostawić ci pierwsze zadanie.', 'holonet'],
  ['Mapa i następny krok', 'Mapa galaktyki pokazuje twoją pozycję, układy i floty. Gdy nie wiesz, co dalej — zacznij od wiadomości MG.', 'mapa'],
];
let przewodnik = null; // { krok, ukonczony, pominiety }
async function wczytajPrzewodnik() {
  try { const r = await api('ustawienie', undefined, { klucz: 'przewodnik' }); przewodnik = r.wartosc || { krok: 0 }; }
  catch { przewodnik = { krok: 0 }; }
}
function zapiszPrzewodnik() { api('ustawienie_zapisz', { klucz: 'przewodnik', wartosc: przewodnik }).catch(() => {}); }
function pokazPrzewodnik(wymus = false) {
  document.querySelector('.przewodnik')?.remove();
  if (!przewodnik || !stan.uzytkownik || stan.uzytkownik.rola === 'mg') return;
  if (!wymus && (przewodnik.ukonczony || przewodnik.pominiety)) return;
  const i = Math.max(0, Math.min(KROKI_PRZEWODNIKA.length - 1, przewodnik.krok || 0));
  const [tytul, opis, app] = KROKI_PRZEWODNIKA[i];
  const zamknij = (zmiany) => { Object.assign(przewodnik, zmiany); zapiszPrzewodnik(); el.remove(); };
  const el = h('div.przewodnik', { role: 'dialog', 'aria-label': 'Przewodnik' },
    h('div.slaby.maly-tekst', `Przewodnik · ${i + 1} z ${KROKI_PRZEWODNIKA.length}`),
    h('h2', tytul), h('p', opis),
    h('div.rzad', { style: 'flex-wrap:wrap;gap:8px' },
      h('button.przycisk', { onclick: () => {
        // Otwarcie aplikacji przesuwa przewodnik dalej; po powrocie na pulpit pokaże następny krok.
        zamknij(i + 1 >= KROKI_PRZEWODNIKA.length ? { krok: i, ukonczony: true } : { krok: i + 1 });
        otworz(app);
      } }, `Otwórz: ${APLIKACJE.find((a) => a.id === app)?.nazwa || app}`),
      i + 1 < KROKI_PRZEWODNIKA.length
        ? h('button.przycisk.drugi', { onclick: () => { przewodnik.krok = i + 1; zapiszPrzewodnik(); pokazPrzewodnik(true); } }, 'Dalej')
        : h('button.przycisk.drugi', { onclick: () => zamknij({ ukonczony: true }) }, 'Zakończ'),
      i > 0 ? h('button.przycisk.drugi', { onclick: () => { przewodnik.krok = i - 1; zapiszPrzewodnik(); pokazPrzewodnik(true); } }, 'Wstecz') : null,
      h('button.przycisk.drugi', { onclick: () => zamknij({ pominiety: true }) }, 'Pomiń')));
  document.getElementById('pulpit')?.append(el);
}

// Szybkie akcje na pulpicie: podróż w toku, przygotowanie statku, najbliższy bankomat, taksówka.
const szybkieAkcje = h('div.szybkie-akcje');
let licznikAkcji = null;
async function rysujSzybkieAkcje() {
  clearInterval(licznikAkcji);
  let d;
  try { d = await api('nawigacja'); } catch { return wyczysc(szybkieAkcje); }
  const roznica = d.teraz - Date.now() / 1000;
  const teraz = () => Date.now() / 1000 + roznica;
  const przyciski = [];
  const akcja = (tekst, fn, dodatek = null) => przyciski.push(h('button.szybka', { onclick: fn }, tekst, dodatek));
  const zostalo = (koniec) => { const el = h('b', { 'data-szybki': koniec }, ' ' + odliczanie(koniec - teraz())); return el; };
  if (d.podroz) {
    akcja(`W drodze → ${d.podroz.cel_nazwa}`, () => otworz('nawigacja'), zostalo(d.podroz.koniec));
  } else if (d.poklad?.w_locie) {
    const p = d.poklad;
    if (p.scena === 'start' && p.od_zmiany_ms < p.wznoszenie_ms) akcja('Wznoszenie · orbita za', () => otworz('nawigacja'), zostalo(teraz() + (p.wznoszenie_ms - p.od_zmiany_ms) / 1000));
    else if (['wejscie', 'nadswietlna'].includes(p.scena) || p.cel) akcja(`${p.nazwa}: lot w nadprzestrzeni`, () => otworz('nawigacja'));
    else akcja(`${p.nazwa} na orbicie · skok lub lądowanie`, () => otworz('nawigacja'));
  } else if (d.gdzie) {
    for (const s of d.statki.slice(0, 2)) {
      const pr = s.przygotowanie;
      if (pr && pr.gotowy > teraz()) akcja(`${s.nazwa}: obsługa naziemna`, () => otworz('nawigacja'), zostalo(pr.gotowy));
      else if (pr && s.na_miejscu) akcja(`Wejdź na pokład: ${s.nazwa}`, () => otworz('nawigacja'));
      else if (pr) akcja(`${s.nazwa} gotowy · jedź do hangaru`, () => otworz('nawigacja', { trasa: s.hangar.klucz, nazwa: s.hangar.nazwa }));
      else akcja(`Przygotuj ${s.nazwa} do lotu`, () => otworz('nawigacja'));
    }
    const bankomat = d.miejsca.find((m) => m.typ === 'bankomat');
    if (d.bankomat) akcja('Bankomat obok', () => otworz('nawigacja'));
    else if (bankomat && bankomat.km <= 5) akcja(`Bankomat · ${bankomat.km} km`, () => otworz('nawigacja', { trasa: bankomat.klucz, nazwa: bankomat.nazwa }));
    if (d.planeta?.urbanizacja && d.planeta.urbanizacja !== 'brak') akcja('Taksówka', () => otworz('nawigacja'));
  }
  wyczysc(szybkieAkcje).append(...przyciski);
  licznikAkcji = setInterval(() => {
    let koniec = false;
    szybkieAkcje.querySelectorAll('[data-szybki]').forEach((el) => {
      const z = Number(el.dataset.szybki) - teraz();
      el.textContent = ' ' + odliczanie(z);
      if (z <= 0) koniec = true;
    });
    if (koniec) rysujSzybkieAkcje();
  }, 1000);
}

// Ikony pulpitu: gracz zdejmuje i dodaje aplikacje (zapis w ustawieniach konta, kopia na urządzeniu).
const POZA_PULPITEM = ['ustawienia']; // ikonka w narożniku doku, nie kafelek na pulpicie
let ukryte = null;           // id zdjętych aplikacji
let edycjaPulpitu = false;
function kluczPulpitu() { return 'pulpit.' + (stan.uzytkownik?.login || stan.uzytkownik?.postac || ''); }
async function wczytajPulpit() {
  ukryte = pamiec.czytaj(kluczPulpitu(), []);
  try {
    const d = await api('ustawienie', undefined, { klucz: 'pulpit' });
    if (Array.isArray(d.wartosc?.ukryte)) { ukryte = d.wartosc.ukryte; pamiec.zapisz(kluczPulpitu(), ukryte); }
  } catch { /* zostaje kopia z urządzenia */ }
}
function zapiszPulpit() {
  pamiec.zapisz(kluczPulpitu(), ukryte);
  api('ustawienie_zapisz', { klucz: 'pulpit', wartosc: { ukryte } }).catch(() => {});
}
function rysujIkony(siatka) {
  const widoczne = APLIKACJE.filter((a) => !ukryte?.includes(a.id) && !POZA_PULPITEM.includes(a.id));
  const zdjete = APLIKACJE.filter((a) => ukryte?.includes(a.id) && !POZA_PULPITEM.includes(a.id));
  siatka.classList.toggle('edycja', edycjaPulpitu);
  siatka.querySelectorAll('.ikona-app, .kafel-dodaj').forEach((e) => e.remove());
  const ikony = widoczne.map((a) => h('button.ikona-aplikacji.ikona-app', { onclick: () => (edycjaPulpitu ? null : otworz(a.id)) },
    ikona(a.ikona), h('span', a.nazwa),
    edycjaPulpitu ? h('span.ikona-usun', { title: 'Zdejmij z pulpitu', onclick: (e) => {
      e.stopPropagation(); ukryte = [...(ukryte || []), a.id]; zapiszPulpit(); rysujIkony(siatka);
    } }, '×') : null));
  siatka.prepend(...ikony);
  if (edycjaPulpitu && zdjete.length) {
    const lista = h('div.dodaj-lista', { hidden: true }, zdjete.map((a) => h('button.przycisk.maly.drugi', { onclick: () => {
      ukryte = ukryte.filter((x) => x !== a.id); zapiszPulpit(); rysujIkony(siatka);
    } }, ikona(a.ikona), a.nazwa)));
    siatka.append(h('div.ikona-aplikacji.kafel-dodaj',
      h('button.dodaj-plus', { onclick: () => { lista.hidden = !lista.hidden; } }, h('b', '+'), h('span', 'Dodaj')), lista));
  }
}

// 4. Pulpit z ikonami aplikacji.
function ekranPulpitu() {
  zamknijAplikacje();
  pokazPowloke(true);
  tlo(true);
  sprawdzScene();
  tytulPaska.textContent = '';
  const u = stan.uzytkownik;
  edycjaPulpitu = false;
  rysujPrzyciskDostosuj();
  przyciskPrzewodnika.hidden = u.rola === 'mg';
  let siatka;
  widok(
    h('div.pulpit-glowa',
      h('div',
        h('div.pulpit-zegar', { 'data-zegar': '' }, godzina()),
        h('div.pulpit-data', { 'data-data': '' }, dataGST()),
        h('div.pulpit-miejsce', { 'data-miejsce': '' }, opisMiejsca())),
      h('div.pulpit-ktos',
        h('div', h('b', u.postac)),
        h('div', [u.frakcja, u.oddzial].filter(Boolean).join(' · ')),
        u.ranga ? h('div', u.ranga) : null)),
    szybkieAkcje,
    // Liczniki pokładowe i aparat to kafelki w tej samej siatce co aplikacje (nie nachodzą na ikony).
    siatka = h('div.siatka-aplikacji',
      h('div.ikona-aplikacji.kafel-odliczanie', { 'data-odliczanie': '', hidden: !liczniki?.odliczanie }),
      h('div.kafle-napraw', { 'data-naprawy': '' }),
      h('div.ikona-aplikacji.kafel-liczniki', { 'data-liczniki': '', hidden: !liczniki }),
      h('button.ikona-aplikacji.kafel-aparat', { 'data-aparat': '', hidden: true, onclick: zrobZdjecie, title: 'Zdjęcie słońca' }, ikona(IKONA_APARATU), h('span', 'Zdjęcie'))),
  );
  if (przewodnik) pokazPrzewodnik();
  else wczytajPrzewodnik().then(() => { if (document.querySelector('.siatka-aplikacji')) pokazPrzewodnik(); });
  const pierwszy = ukryte === null;
  rysujIkony(siatka);
  if (pierwszy) wczytajPulpit().then(() => siatka.isConnected && rysujIkony(siatka));
  odswiezZegar();
  rysujSzybkieAkcje();
  rysujLiczniki();
}

// ================================================================
// Menedżer aplikacji
// ================================================================

function zamknijAplikacje() {
  clearInterval(licznikAkcji);
  pokazGwiazde(null);
  if (stan.aplikacja) {
    try { stan.aplikacja.sprzataj?.(); } catch { /* aplikacja i tak znika */ }
    stan.aplikacja = null;
  }
}

function otworz(id, parametr = null) {
  const def = APLIKACJE.find((a) => a.id === id);
  if (!def || !stan.uzytkownik) return;
  zamknijAplikacje();
  tlo(false);
  tytulPaska.textContent = def.nazwa;
  const w = widok();
  const kontekst = {
    uzytkownik: stan.uzytkownik,
    parametr,
    alfabet,
    zablokuj: () => wyloguj(),
    pulpit: () => ekranPulpitu(),
    jasnosc: (v) => (v === undefined ? pamiec.czytaj('jasnosc', 100) : ustawJasnosc(v)),
    ustawWstecz: (fn) => { if (stan.aplikacja) stan.aplikacja.wstecz = fn; },
  };
  stan.aplikacja = { def, sprzataj: null, wstecz: null };
  const wynik = def.uruchom(w, kontekst);
  if (typeof wynik === 'function') stan.aplikacja.sprzataj = wynik;
}

async function wyloguj() {
  try { await api('wyloguj', {}); } catch { /* i tak blokujemy */ }
  zaklocenia();
  ekranBlokady();
}

document.getElementById('dok-dom').addEventListener('click', () => stan.uzytkownik && ekranPulpitu());
document.getElementById('dok-blokada').addEventListener('click', () => wyloguj());
document.getElementById('dok-wstecz').addEventListener('click', () => {
  if (!stan.uzytkownik) return;
  if (stan.aplikacja?.wstecz && stan.aplikacja.wstecz()) return;
  ekranPulpitu();
});

window.addEventListener('datapad:sesja-wygasla', () => {
  if (stan.uzytkownik) ekranBlokady('Sesja wygasła. Odblokuj urządzenie ponownie.');
});

// Jasność ekranu (zapamiętana na urządzeniu).
function ustawJasnosc(v) {
  pamiec.zapisz('jasnosc', v);
  document.getElementById('jasnosc').style.opacity = String((100 - v) / 100 * 0.8);
}
ustawJasnosc(pamiec.czytaj('jasnosc', 100));

// Tryb offline dla tabletów (pliki systemu w pamięci urządzenia).
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js').catch(() => {});
}

bateria();
odswiezZegar();
ekranStartowy();

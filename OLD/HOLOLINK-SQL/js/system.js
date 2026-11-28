// HOLOLINK — powłoka datapada: start, rozruch, blokada, pulpit, aplikacje, alfabet.

import { h, wyczysc, ikona, godzina, dataGST, czekaj, pamiec } from './rdzen/narzedzia.js';
import { api } from './rdzen/api.js';
import { URZADZENIE } from './rdzen/urzadzenie.js';
import { utworzScene, TYPY_PLANET } from './rdzen/scena.js';

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
  return [NAZWY_SCEN[sc], miejsce, stanSceny.opis].filter(Boolean).join(' · ');
}

async function sprawdzScene() {
  if (!stan.uzytkownik) return;
  try {
    const { scena: s, teraz } = await api('scena');
    przesuniecieZegara = teraz - Date.now();
    stanSceny = s;
    pamiec.zapisz('scena', s);
    scena.ustaw({ ...s, przesuniecie: przesuniecieZegara });
    document.querySelectorAll('[data-miejsce]').forEach((el) => { el.textContent = opisMiejsca(); });
  } catch { /* brak zasięgu — zostaje ostatni znany stan */ }
}
setInterval(sprawdzScene, 6000);
// Podpis na pulpicie dogania tło (skok → nadprzestrzeń) bez czekania na serwer.
setInterval(() => document.querySelectorAll('[data-miejsce]').forEach((el) => { el.textContent = opisMiejsca(); }), 2000);

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

// 4. Pulpit z ikonami aplikacji.
function ekranPulpitu() {
  zamknijAplikacje();
  pokazPowloke(true);
  tlo(true);
  sprawdzScene();
  tytulPaska.textContent = '';
  const u = stan.uzytkownik;
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
    h('div.siatka-aplikacji',
      APLIKACJE.map((a) => h('button.ikona-aplikacji', { onclick: () => otworz(a.id) },
        ikona(a.ikona), h('span', a.nazwa)))),
  );
  odswiezZegar();
  rysujSzybkieAkcje();
}

// ================================================================
// Menedżer aplikacji
// ================================================================

function zamknijAplikacje() {
  clearInterval(licznikAkcji);
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

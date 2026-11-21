// Drobne narzędzia wspólne dla systemu i aplikacji.

// Natywne append/prepend zamieniają null na tekst „null”. W całym systemie (datapad i panel MG)
// budujemy widoki z warunkowymi kawałkami (`warunek ? element : null`), więc puste wartości
// po prostu pomijamy.
for (const metoda of ['append', 'prepend']) {
  const oryginal = Element.prototype[metoda];
  Element.prototype[metoda] = function (...wezly) {
    return oryginal.apply(this, wezly.filter((x) => x !== null && x !== undefined && x !== false));
  };
}

// Tworzenie elementów: h('div.klasa#id', {atrybuty}, ...dzieci)
export function h(opis, atrybuty = {}, ...dzieci) {
  const [, tag = 'div', reszta = ''] = opis.match(/^([a-z0-9]*)(.*)$/i);
  const el = document.createElement(tag || 'div');
  for (const czesc of reszta.match(/[.#][^.#]+/g) || []) {
    if (czesc[0] === '.') el.classList.add(czesc.slice(1));
    else el.id = czesc.slice(1);
  }
  // Drugi argument to atrybuty tylko wtedy, gdy jest zwykłym obiektem; liczba, tekst czy element to treść.
  if (atrybuty === null || typeof atrybuty !== 'object' || atrybuty instanceof Node || Array.isArray(atrybuty)) {
    dzieci.unshift(atrybuty);
    atrybuty = {};
  }
  for (const [k, v] of Object.entries(atrybuty || {})) {
    if (v === undefined || v === null || v === false) continue;
    if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
    else if (k === 'html') el.innerHTML = v;
    else if (k in el && typeof v !== 'string') el[k] = v;
    else el.setAttribute(k, v === true ? '' : v);
  }
  dodaj(el, dzieci);
  return el;
}

function dodaj(el, dzieci) {
  for (const d of dzieci) {
    if (d === null || d === undefined || d === false) continue;
    if (Array.isArray(d)) dodaj(el, d);
    else el.append(d instanceof Node ? d : document.createTextNode(String(d)));
  }
}

// ---------- ładowanie ----------
// Hologramy znaków stronnictw (stylizowane, rysowane kodem) do ekranu ładowania.
const ZNAKI_FRAKCJI = {
  // Imperium: koło zębate — pierścień, sześć rozszerzających się ramion, piasta.
  Imperium: '<circle r="44" fill="none" stroke="currentColor" stroke-width="5"/><circle r="11" fill="currentColor"/>'
    + [0, 60, 120, 180, 240, 300].map((a) => `<path transform="rotate(${a})" d="M-4 -14 L4 -14 L11 -39 L-11 -39 Z" fill="currentColor"/>`).join('')
    + [30, 90, 150, 210, 270, 330].map((a) => `<path transform="rotate(${a})" d="M-3 -41 L3 -41 L2 -47 L-2 -47 Z" fill="currentColor"/>`).join(''),
  // Rebelia: stylizowany ptak-feniks — pionowe pióro i dwa wznoszące się skrzydła w okręgu.
  Rebelia: '<path d="M0 -44 C6 -24 7 -8 4 10 L12 30 C26 14 34 -6 30 -26 C40 -8 40 18 22 36 L8 44 L0 30 L-8 44 L-22 36 C-40 18 -40 -8 -30 -26 C-34 -6 -26 14 -12 30 L-4 10 C-7 -8 -6 -24 0 -44 Z" fill="currentColor"/>',
  // Syndykat: sześciokąt z trzema przecinającymi się ostrzami.
  Syndykat: '<path d="M0 -44 L38 -22 L38 22 L0 44 L-38 22 L-38 -22 Z" fill="none" stroke="currentColor" stroke-width="5"/>'
    + [0, 60, 120].map((a) => `<path transform="rotate(${a})" d="M-3 -30 L3 -30 L1.5 30 L-1.5 30 Z" fill="currentColor"/>`).join('') + '<circle r="7" fill="currentColor"/>',
  // Bez stronnictwa: romb holonetu.
  brak: '<path d="M0 -42 L42 0 L0 42 L-42 0 Z" fill="none" stroke="currentColor" stroke-width="5"/><path d="M0 -20 L20 0 L0 20 L-20 0 Z" fill="currentColor"/>',
};

// Znaki stronnictw z grafiki (zamiast rysunku kodem): hologram obraca się tak samo, czarne tło znika w trybie „screen”.
const GRAFIKI_FRAKCJI = { Imperium: 'assets/frakcje/imperium.webp' };

// Pasek ładowania w stylu konsol z Gwiezdnych Wojen: segmenty zapalające się falą.
export function pasekLadowania(napis = '') {
  return h('div.pasek-sw', h('div.pasek-sw-segmenty', ...Array.from({ length: 14 }, (_, i) => h('i', { style: `--i:${i}` }))),
    napis ? h('div.pasek-sw-napis', napis) : null);
}

// Pełny ekran ładowania: obracający się hologram znaku stronnictwa gracza nad projektorem, napis ŁADOWANIE i pasek.
export function ladowaniePelne(frakcja) {
  const znak = ZNAKI_FRAKCJI[frakcja] || ZNAKI_FRAKCJI.brak;
  const holo = h('div.holo');
  if (GRAFIKI_FRAKCJI[frakcja]) holo.append(h('img', { src: GRAFIKI_FRAKCJI[frakcja], alt: '', draggable: 'false' }));
  else holo.innerHTML = `<svg viewBox="-50 -50 100 100" aria-hidden="true">${znak}</svg>`;
  return h('div.ladowanie-pelne', { role: 'status', 'aria-live': 'polite' },
    h('div.holo-scena', holo, h('div.holo-projektor')),
    h('div.ladowanie-napis', 'ŁADOWANIE'),
    pasekLadowania());
}

// Potwierdzenie na samym przycisku (zamiast systemowego okienka): pierwsze kliknięcie zmienia napis na pytanie,
// drugie w ciągu 4 s wykonuje akcję. Tylko dla rzeczy kosztownych albo nieodwracalnych — drobiazgi bez pytania.
export function dwaKroki(pytanie, fn) {
  return function (e) {
    const b = e.currentTarget;
    if (b.dataset.potwierdz === '1') {
      clearTimeout(b._potwierdzT); b.dataset.potwierdz = ''; b.textContent = b._potwierdzTekst; b.classList.remove('potwierdz');
      return fn(e);
    }
    b._potwierdzTekst = b.textContent; b.dataset.potwierdz = '1'; b.textContent = `${pytanie} Kliknij ponownie.`; b.classList.add('potwierdz');
    b._potwierdzT = setTimeout(() => { b.dataset.potwierdz = ''; b.textContent = b._potwierdzTekst; b.classList.remove('potwierdz'); }, 4000);
  };
}

export function wyczysc(el) {
  while (el.firstChild) el.firstChild.remove();
  return el;
}

// Ikony rysowane kreską (bez napisów, więc działają w obu alfabetach).
export function ikona(sciezki) {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', '0 0 48 48');
  svg.setAttribute('aria-hidden', 'true');
  svg.innerHTML = sciezki;
  return svg;
}

// ----- czas galaktyczny -----
// Rok GST liczony od realnego roku (2026 → GST16), daty dni zostają prawdziwe,
// bo sesje gry odbywają się w prawdziwe dni.
export const PRZESUNIECIE_GST = 2010;

export const DNI_TYG = ['Pn', 'Wt', 'Śr', 'Cz', 'Pt', 'So', 'Nd'];

export function rokGST(d = new Date()) {
  return d.getFullYear() - PRZESUNIECIE_GST;
}

// Data i godzina gry: DD|MM|RR GG:MM, rok gry = rok realny − 2010 (np. 18|11|16 17:27).
// Przyjmuje Date albo znacznik czasu w sekundach.
export function czasGry(d = new Date()) {
  const t = d instanceof Date ? d : new Date(Number(d) * 1000);
  const p = (n) => String(n).padStart(2, '0');
  return `${p(t.getDate())}|${p(t.getMonth() + 1)}|${p(rokGST(t))} ${p(t.getHours())}:${p(t.getMinutes())}`;
}

// Krótka data gry: DD|MM, z rokiem gry (DD|MM|RR) i/lub godziną (… GG:MM). Przyjmuje Date,
// tekst daty (ISO) albo znacznik czasu w milisekundach.
export function dataKrotka(v, { rok = false, godz = false } = {}) {
  const t = v instanceof Date ? v : new Date(v);
  if (Number.isNaN(t.getTime())) return '';
  const p = (n) => String(n).padStart(2, '0');
  return `${p(t.getDate())}|${p(t.getMonth() + 1)}${rok ? `|${p(rokGST(t))}` : ''}${godz ? ` ${p(t.getHours())}:${p(t.getMinutes())}` : ''}`;
}

export function godzina(d = new Date()) {
  return d.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });
}

// Kalendarz gry (jak w aplikacji Kalendarz): tydzień ma 5 dni, miesiąc 30 albo 25 dni,
// a pozostałe dni realnego miesiąca to święta „H”, numerowane narastająco w roku.
const dniMiesiaca = (y, m) => new Date(y, m + 1, 0).getDate();
export const normDni = (y, m) => Math.floor(dniMiesiaca(y, m) / 5) * 5;
export const dimMiesiaca = dniMiesiaca;

function numerH(y, m, dzien) {
  const bazaH = [0, 1, 4, 6, 8, 10, 12, 14, 16, 18, 20, 22]; // tabela startowa świąt dla miesięcy
  let n = bazaH[m] + (dzien - normDni(y, m));
  if (m >= 2 && (y - PRZESUNIECIE_GST) % 4 === 2) n += 1;    // lata 14, 18, 22... są przestępne (luty ma o jedno święto więcej)
  return n;
}

// Data realna (RRRR-MM-DD) → data w grze: dzień|miesiąc|rok albo H|numer|rok.
export function dataGry(ds) {
  const [y, mon, dzien] = ds.split('-').map(Number);
  const ig = y - PRZESUNIECIE_GST;
  if (dzien > normDni(y, mon - 1)) return `H|${numerH(y, mon - 1, dzien)}|${ig}`;
  return `${dzien}|${mon}|${ig}`;
}

export function dataGST(d = new Date()) {
  return dataGry(iso(d));
}

export function miesiacGST(d) {
  return `${String(d.getMonth() + 1).padStart(2, '0')} · GST${rokGST(d)}`;
}

export function iso(d) {
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

export function zIso(s) {
  const [r, m, d] = s.split('-').map(Number);
  return new Date(r, m - 1, d);
}

export function czekaj(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

// Pamięć ustawień urządzenia (może być niedostępna, np. w trybie prywatnym).
export const pamiec = {
  czytaj(k, dom) {
    try {
      const v = localStorage.getItem('datapad.' + k);
      return v === null ? dom : JSON.parse(v);
    } catch {
      return dom;
    }
  },
  zapisz(k, v) {
    try {
      localStorage.setItem('datapad.' + k, JSON.stringify(v));
    } catch { /* trudno, ustawienie zniknie po odświeżeniu */ }
  },
};

// Losowy „szum szyfru" do efektów dekodowania.
const ZNAKI_SZYFRU = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
export function szum(dl) {
  let s = '';
  for (let i = 0; i < dl; i++) s += Math.random() < 0.12 ? ' ' : ZNAKI_SZYFRU[(Math.random() * ZNAKI_SZYFRU.length) | 0];
  return s;
}

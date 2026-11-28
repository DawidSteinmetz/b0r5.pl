// Pliki systemu w pamięci tabletu: datapad uruchamia się nawet przy słabym zasięgu.
// Dane gry (api/) zawsze idą z sieci. Po zmianie plików systemu podbijcie WERSJA.
const WERSJA = 'hololink-155';
const PLIKI = [
  './', 'index.html', 'manifest.webmanifest', 'css/system.css', 'css/cv.css', 'js/rdzen/eote.js', 'assets/ikona.svg', 'assets/czcionki/aurebesh-original.otf',
  'assets/wnetrza/isd-winda.svg', 'assets/wnetrza/isd-stolowka.svg', 'assets/wnetrza/isd-umywalnia.svg', 'assets/wnetrza/isd-szatnia.svg', 'assets/wnetrza/isd-reaktor.svg', 'assets/wnetrza/isd-korytarz.svg', 'assets/wnetrza/isd-kajuta.svg', 'assets/wnetrza/isd-koszary.svg', 'assets/wnetrza/isd-hangar.svg', 'assets/wnetrza/isd-ambulatorium.svg', 'assets/wnetrza/isd-cela.svg', 'assets/wnetrza/isd-maszynownia.svg', 'assets/wnetrza/isd-centrum.svg', 'assets/wnetrza/isd-zbrojownia.svg', 'assets/wnetrza/isd-magazyn.svg', 'assets/wnetrza/isd-iluminator-okno.svg', 'assets/wnetrza/isd-panorama-okno.svg',
  'assets/frakcje/imperium.webp',
  'js/system.js', 'js/rdzen/api.js', 'js/rdzen/narzedzia.js', 'js/rdzen/urzadzenie.js', 'js/rdzen/scena.js', 'js/rdzen/astro.js', 'js/rdzen/gwiazdy_tla.js', 'js/rdzen/wskazniki.js', 'js/rdzen/dzwiek.js',
  'js/rdzen/odtwarzacz_dzwiekow.js', 'js/rdzen/dzwieki_baza.js', 'js/rdzen/dzwieki_baza.json', 'narzedzia/dzwieki/osłona_isd_i_1.json', 'js/rdzen/dzwiek_oslon.js',
  'js/rdzen/dzwiek_trafienia.js', 'js/rdzen/dzwiek_wystrzalu.js', 'js/rdzen/oslony_worklet.js',
  'js/aplikacje/holonet.js', 'js/aplikacje/dekoder.js', 'js/aplikacje/kalendarz.js', 'js/aplikacje/symulacje.js', 'js/aplikacje/konsole.js', 'js/aplikacje/mostek.js', 'js/rdzen/kultury.js',
  'js/aplikacje/frakcja.js', 'js/aplikacje/ustawienia.js', 'js/aplikacje/bank.js', 'js/aplikacje/karta.js', 'js/aplikacje/nawigacja.js', 'js/aplikacje/mapa.js', 'js/aplikacje/gielda.js',
  'js/aplikacje/firma.js', 'js/aplikacje/firma/transport.js', 'js/aplikacje/firma/komorka.js', 'js/aplikacje/firma/agencja.js',
  'js/aplikacje/firma/wydobycie.js', 'js/aplikacje/firma/produkcja.js', 'js/aplikacje/firma/finanse.js', 'js/aplikacje/firma/zaklady.js',
];

self.addEventListener('install', (e) => {
  // Każdy plik osobno: brak jednego (np. 404) nie może zablokować instalacji nowej wersji na telefonach.
  e.waitUntil(caches.open(WERSJA)
    .then((c) => Promise.all(PLIKI.map((p) => c.add(new Request(p, { cache: 'reload' })).catch(() => {}))))
    .then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((k) => Promise.all(k.filter((n) => n !== WERSJA).map((n) => caches.delete(n))))
    .then(() => self.clients.claim()));
});

// Obsługujemy tylko pliki z listy. Na tej samej domenie żyją inne projekty (np. /_/, /__/),
// których ten skrypt nie może dotykać.
const NASZE = new Set(PLIKI.map((p) => new URL(p, self.registration.scope).href));

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  // Odpowiedź mapy może zależeć od uprawnień konta, więc nie wolno jej współdzielić w cache offline.
  if (e.request.method === 'GET' && url.pathname.endsWith('/api/') && url.searchParams.get('a') === 'mapa_dane') {
    e.respondWith(fetch(e.request).catch(() => new Response('{}', { status: 503, headers: { 'Content-Type': 'application/json' } })));
    return;
  }
  url.search = '';
  if (e.request.method !== 'GET' || !NASZE.has(url.href)) return;
  // Najpierw sieć (świeże pliki), pamięć tylko gdy sieci brak. no-cache: przeglądarka zawsze pyta serwer (304, gdy plik
  // się nie zmienił) — bez tego telefon potrafi dniami brać stary plik z własnej pamięci HTTP.
  e.respondWith(fetch(e.request, { cache: 'no-cache' })
    .then((odp) => {
      const kopia = odp.clone();
      caches.open(WERSJA).then((c) => c.put(e.request, kopia));
      return odp;
    })
    .catch(() => caches.match(e.request)));
});

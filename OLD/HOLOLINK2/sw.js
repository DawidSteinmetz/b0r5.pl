// Pliki systemu w pamięci tabletu: datapad uruchamia się nawet przy słabym zasięgu.
// Dane gry (api/) zawsze idą z sieci. Po zmianie plików systemu podbijcie WERSJA.
const WERSJA = 'hololink-19';
const PLIKI = [
  './', 'index.html', 'manifest.webmanifest', 'css/system.css', 'assets/ikona.svg', 'assets/czcionki/aurebesh.otf',
  'js/system.js', 'js/rdzen/api.js', 'js/rdzen/narzedzia.js', 'js/rdzen/urzadzenie.js', 'js/rdzen/scena.js',
  'js/aplikacje/holonet.js', 'js/aplikacje/dekoder.js', 'js/aplikacje/kalendarz.js', 'js/rdzen/kultury.js',
  'js/aplikacje/frakcja.js', 'js/aplikacje/ustawienia.js', 'js/aplikacje/bank.js', 'js/aplikacje/karta.js', 'js/aplikacje/nawigacja.js', 'js/aplikacje/mapa.js', 'js/aplikacje/gielda.js',
  'js/aplikacje/firma.js', 'js/aplikacje/firma/transport.js', 'js/aplikacje/firma/komorka.js', 'js/aplikacje/firma/agencja.js',
  'js/aplikacje/firma/wydobycie.js', 'js/aplikacje/firma/produkcja.js', 'js/aplikacje/firma/finanse.js', 'js/aplikacje/firma/zaklady.js',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(WERSJA).then((c) => c.addAll(PLIKI)).then(() => self.skipWaiting()));
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
  // Astronawigacja pochodzi z SQL, ale jej statyczne dane można odczytać offline.
  if (e.request.method === 'GET' && url.pathname.endsWith('/api/') && url.searchParams.get('a') === 'mapa_dane') {
    e.respondWith(fetch(e.request).then((odp) => {
      if (odp.ok) caches.open(WERSJA).then((c) => c.put(e.request, odp.clone()));
      return odp;
    }).catch(async () => (await caches.match(e.request)) || new Response('{}', { status: 503, headers: { 'Content-Type': 'application/json' } })));
    return;
  }
  url.search = '';
  if (e.request.method !== 'GET' || !NASZE.has(url.href)) return;
  // Najpierw sieć (świeże pliki), pamięć tylko gdy sieci brak.
  e.respondWith(fetch(e.request)
    .then((odp) => {
      const kopia = odp.clone();
      caches.open(WERSJA).then((c) => c.put(e.request, kopia));
      return odp;
    })
    .catch(() => caches.match(e.request)));
});

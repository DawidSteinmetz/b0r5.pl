// Łączność z węzłem (api/index.php). Wszystkie komunikaty błędów są „w świecie gry".

const ADRES = new URL('../../api/', import.meta.url);

export class BladSieci extends Error {
  constructor(komunikat, kod) {
    super(komunikat);
    this.kod = kod;
  }
}

// Zapytania w toku (bez cyklicznego tła sceny) — ekran ładowania aplikacji czeka, aż spadną do zera.
const TLO = new Set(['scena', 'bitwa_stan']); // zapytania w tle: bez wskaźnika ładowania
const sluchacze = new Set();
let wToku = 0;
export function nasluchujApi(fn) { sluchacze.add(fn); return () => sluchacze.delete(fn); }
const zglos = (zmiana) => { wToku += zmiana; sluchacze.forEach((fn) => { try { fn(wToku); } catch { /* słuchacz nie przerywa */ } }); };

export async function api(akcja, dane, parametry = {}) {
  if (TLO.has(akcja)) return zapytanie(akcja, dane, parametry);
  zglos(1);
  try { return await zapytanie(akcja, dane, parametry); } finally { zglos(-1); }
}

async function zapytanie(akcja, dane, parametry) {
  const url = new URL(ADRES);
  url.searchParams.set('a', akcja);
  for (const [k, v] of Object.entries(parametry)) url.searchParams.set(k, v);
  let odp;
  try {
    odp = await fetch(url, {
      method: dane === undefined ? 'GET' : 'POST',
      credentials: 'same-origin',
      headers: dane === undefined ? {} : { 'Content-Type': 'application/json', 'X-Datapad': '1' },
      body: dane === undefined ? undefined : JSON.stringify(dane),
    });
  } catch {
    throw new BladSieci('Brak połączenia z holonetem. Sprawdź nadajnik.', 0);
  }
  let json = null;
  try {
    json = await odp.json();
  } catch { /* odpowiedź nie jest JSON-em */ }
  if (!odp.ok || !json) {
    const b = new BladSieci(json?.blad || 'Węzeł holonetu nie odpowiada. Spróbuj ponownie.', odp.status);
    b.problemy = json?.problemy || [];
    if (odp.status === 401) window.dispatchEvent(new CustomEvent('datapad:sesja-wygasla'));
    throw b;
  }
  return json;
}

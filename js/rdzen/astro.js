// Astronomia gry liczona na tablecie: dzień i noc w lokacji oraz położenia w układzie planetarnym.
// Parametry („cykl”) podaje serwer (api/astro.php). Kąty w stopniach, układ mapy:
// 0° = wschód, 90° = północ, przeciwnie do ruchu wskazówek zegara. Czas: sekundy UTC.

const RAD = Math.PI / 180;
const norm360 = (k) => ((k % 360) + 360) % 360;

const dni = (cykl, t) => (t - cykl.epoka) / 86400;

// Położenie planety wokół słońca, kierunek do słońca i księżyce (kąty w układzie mapy).
export function stanUkladu(cykl, t = Date.now() / 1000) {
  const d = dni(cykl, t);
  const katPlanety = norm360(cykl.faza_orbity + 360 * d / cykl.rok_dni);
  return {
    katPlanety,
    katSlonca: norm360(katPlanety + 180), // kierunek od planety do słońca
    ksiezyce: (cykl.ksiezyce || []).map((k) => ({ ...k, kat: norm360(k.faza + 360 * d / k.okres_dni) })),
  };
}

// Deklinacja słońca (pory roku) i kąt godzinny w danej długości geograficznej.
function deklinacja(cykl, t) {
  return cykl.nachylenie * Math.sin((360 * dni(cykl, t) / cykl.rok_dni + cykl.faza_roku) * RAD);
}

function katGodzinny(cykl, dl, t) {
  return norm360(cykl.faza_obrotu + 360 * (t - cykl.epoka) / (cykl.doba_h * 3600) + dl + 180) - 180;
}

// Wysokość słońca nad horyzontem w lokacji (stopnie).
export function wysokoscSlonca(cykl, szer, dl, t = Date.now() / 1000) {
  const dek = deklinacja(cykl, t) * RAD;
  const fi = szer * RAD;
  const H = katGodzinny(cykl, dl, t) * RAD;
  return Math.asin(Math.sin(fi) * Math.sin(dek) + Math.cos(fi) * Math.cos(dek) * Math.cos(H)) / RAD;
}

// 'dzien' | 'zmierzch' | 'noc' (zmierzch: słońce do 6° pod horyzontem).
export function pora(cykl, szer, dl, t = Date.now() / 1000) {
  const h = wysokoscSlonca(cykl, szer, dl, t);
  return h > -0.833 ? 'dzien' : h > -6 ? 'zmierzch' : 'noc';
}

// Długość dnia w godzinach (od wschodu do zachodu) w danej chwili roku.
export function dlugoscDnia(cykl, szer, t = Date.now() / 1000) {
  const dek = deklinacja(cykl, t) * RAD;
  const fi = szer * RAD;
  const cosH0 = (Math.sin(-0.833 * RAD) - Math.sin(fi) * Math.sin(dek)) / (Math.cos(fi) * Math.cos(dek));
  if (cosH0 <= -1) return cykl.doba_h; // dzień polarny
  if (cosH0 >= 1) return 0;            // noc polarna
  return cykl.doba_h * Math.acos(cosH0) / Math.PI;
}

// Strona orbity jednostki stojącej pod kątem `kat` przy planecie: dzienna albo nocna,
// i czy wisi między planetą a którymś księżycem (różnica kątów do 20°).
export function stronaOrbity(cykl, kat, t = Date.now() / 1000) {
  const u = stanUkladu(cykl, t);
  const roznica = (a, b) => Math.abs(((a - b + 540) % 360) - 180);
  return {
    dzien: roznica(kat, u.katSlonca) < 90,
    ksiezyc: u.ksiezyce.find((k) => roznica(kat, k.kat) < 20)?.nazwa || null,
  };
}

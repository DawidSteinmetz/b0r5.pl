// Generator czcionki Aurebesh dla systemu datapada.
// Uruchomienie:  cd narzedzia/czcionka && npm install && node generuj.js
// Wynik:         assets/czcionki/aurebesh.otf
//
// Każdy znak to zestaw kresek (łamanych) w siatce 10x10 (x w prawo, y w górę).
// Kształty są przybliżeniem Aurebesha. Jeśli macie lepszą czcionkę (np. kupioną
// albo darmową), wystarczy podmienić plik assets/czcionki/aurebesh.otf.

const fs = require('fs');
const path = require('path');
const opentype = require('opentype.js');

const EM = 1000;
const SKALA = 66;        // 10 jednostek siatki = 660 jednostek czcionki (jak wielkie litery łacińskie)
const GRUBOSC = 80;      // grubość kreski
const LEWY = 80;         // margines lewy
const SZEROKOSC = 820;   // szerokość znaku (stała, czcionka "monospace")

// Litery: tablica łamanych, każda łamana to tablica punktów [x, y].
const LITERY = {
  a: [[[0, 10], [10, 10], [10, 0]], [[0, 10], [0, 5]]],
  b: [[[0, 10], [10, 10], [5, 3], [0, 10]], [[5, 3], [5, 0]]],
  c: [[[0, 0], [5, 10], [10, 0]], [[3, 0], [7, 0]]],
  d: [[[0, 10], [10, 10], [10, 0], [0, 0]]],
  e: [[[0, 10], [10, 10], [10, 0], [0, 0]], [[3, 5], [10, 5]]],
  f: [[[0, 10], [5, 0], [10, 10]], [[5, 0], [5, 10]]],
  g: [[[0, 10], [10, 10], [10, 5]], [[0, 10], [0, 0], [6, 0]]],
  h: [[[0, 0], [0, 10], [10, 10], [10, 0]], [[0, 5], [10, 5]]],
  i: [[[5, 0], [5, 10]]],
  j: [[[0, 10], [10, 10]], [[6, 10], [6, 0], [0, 0], [0, 3]]],
  k: [[[0, 0], [0, 10]], [[0, 10], [10, 5], [0, 0]]],
  l: [[[0, 10], [10, 0], [10, 10]]],
  m: [[[0, 10], [0, 0], [10, 0], [10, 10]], [[5, 0], [5, 10]]],
  n: [[[0, 10], [10, 0]], [[0, 0], [0, 5]], [[10, 10], [10, 5]]],
  o: [[[5, 0], [10, 5], [5, 10], [0, 5], [5, 0]]],
  p: [[[0, 10], [10, 10]], [[0, 10], [0, 0]], [[0, 5], [6, 5]]],
  q: [[[0, 0], [0, 10], [10, 10], [10, 0]], [[5, 10], [5, 4]]],
  r: [[[0, 10], [10, 10], [0, 0]]],
  s: [[[10, 10], [0, 5], [10, 0]]],
  t: [[[0, 10], [10, 10], [5, 0], [0, 10]]],
  u: [[[0, 10], [0, 0], [10, 0], [10, 10]]],
  v: [[[0, 10], [5, 0], [10, 10]]],
  w: [[[0, 0], [0, 10], [10, 10], [10, 0], [0, 0]]],
  x: [[[0, 10], [10, 10], [0, 0], [10, 0], [0, 10]]],
  y: [[[0, 10], [10, 10]], [[5, 10], [5, 0]], [[0, 0], [5, 5]]],
  z: [[[0, 10], [10, 10], [0, 0], [10, 0]]],
};

// Cyfry: podstawa i kreski (każda cyfra inna, ale w tym samym stylu).
const CYFRY = {
  0: [[[0, 0], [10, 0]], [[0, 10], [10, 10]]],
  1: [[[0, 0], [10, 0]], [[5, 0], [5, 10]]],
  2: [[[0, 0], [10, 0]], [[3, 0], [3, 10]], [[7, 0], [7, 10]]],
  3: [[[0, 0], [10, 0]], [[1, 0], [1, 10]], [[5, 0], [5, 10]], [[9, 0], [9, 10]]],
  4: [[[0, 0], [10, 0]], [[0, 0], [5, 10], [10, 0]]],
  5: [[[0, 0], [10, 0]], [[0, 0], [5, 10], [10, 0]], [[5, 10], [5, 0]]],
  6: [[[0, 0], [10, 0]], [[0, 0], [0, 10], [10, 10], [10, 0]]],
  7: [[[0, 0], [10, 0]], [[0, 0], [0, 10], [10, 10], [10, 0]], [[5, 0], [5, 10]]],
  8: [[[0, 0], [10, 0]], [[0, 0], [0, 10], [10, 10], [10, 0]], [[0, 5], [10, 5]]],
  9: [[[0, 0], [10, 0]], [[0, 10], [10, 0]], [[0, 0], [10, 10]]],
};

// Polskie (i inne) litery z ogonkami dostają kształt litery podstawowej.
const OGONKI = {
  'ą': 'a', 'ć': 'c', 'ę': 'e', 'ł': 'l', 'ń': 'n', 'ó': 'o', 'ś': 's', 'ź': 'z', 'ż': 'z',
};

function punkt(x, y) {
  return [LEWY + x * SKALA, y * SKALA];
}

// Kreska między dwoma punktami jako prostokąt (z kwadratowymi końcami).
function odcinek(p, a, b) {
  const [x1, y1] = punkt(...a);
  const [x2, y2] = punkt(...b);
  const dx = x2 - x1, dy = y2 - y1;
  const dl = Math.hypot(dx, dy) || 1;
  const ux = dx / dl, uy = dy / dl;          // kierunek
  const nx = -uy * GRUBOSC / 2, ny = ux * GRUBOSC / 2; // normalna
  const ex = ux * GRUBOSC / 2, ey = uy * GRUBOSC / 2;  // przedłużenie końców
  const pkt = [
    [x1 - ex + nx, y1 - ey + ny],
    [x2 + ex + nx, y2 + ey + ny],
    [x2 + ex - nx, y2 + ey - ny],
    [x1 - ex - nx, y1 - ey - ny],
  ];
  // Kierunek obwiedni zawsze ten sam, żeby nakładające się kreski się sumowały.
  const pole = pkt.reduce((s, q, i) => {
    const r = pkt[(i + 1) % 4];
    return s + (q[0] * r[1] - r[0] * q[1]);
  }, 0);
  if (pole < 0) pkt.reverse();
  p.moveTo(...pkt[0]);
  for (const q of pkt.slice(1)) p.lineTo(...q);
  p.close();
}

function znak(nazwa, unicode, lamane) {
  const p = new opentype.Path();
  for (const l of lamane) {
    for (let i = 0; i < l.length - 1; i++) odcinek(p, l[i], l[i + 1]);
  }
  return new opentype.Glyph({ name: nazwa, unicode, advanceWidth: SZEROKOSC, path: p });
}

const glify = [
  new opentype.Glyph({ name: '.notdef', advanceWidth: SZEROKOSC, path: new opentype.Path() }),
  new opentype.Glyph({ name: 'space', unicode: 32, advanceWidth: SZEROKOSC, path: new opentype.Path() }),
];

for (const [l, ks] of Object.entries(LITERY)) {
  glify.push(znak(l, l.charCodeAt(0), ks));
  glify.push(znak(l.toUpperCase(), l.toUpperCase().charCodeAt(0), ks));
}
for (const [c, ks] of Object.entries(CYFRY)) {
  glify.push(znak('cyfra' + c, String(c).charCodeAt(0), ks));
}
for (const [z, baza] of Object.entries(OGONKI)) {
  const kod = z.charCodeAt(0);
  const duza = z.toUpperCase();
  glify.push(znak('u' + kod.toString(16), kod, LITERY[baza]));
  glify.push(znak('u' + duza.charCodeAt(0).toString(16), duza.charCodeAt(0), LITERY[baza]));
}

const czcionka = new opentype.Font({
  familyName: 'Aurebesh Datapad',
  styleName: 'Regular',
  unitsPerEm: EM,
  ascender: 900,
  descender: -220,
  glyphs: glify,
});

const cel = path.join(__dirname, '..', '..', 'assets', 'czcionki', 'aurebesh.otf');
fs.mkdirSync(path.dirname(cel), { recursive: true });
fs.writeFileSync(cel, Buffer.from(czcionka.toArrayBuffer()));
console.log('Zapisano', cel, glify.length, 'znaków');

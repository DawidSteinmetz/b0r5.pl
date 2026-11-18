// Raport postępu migracji z dokumentów JSON (datapad_dane) do tabel SQL.
// Liczy, które dokumenty JSON są jeszcze czytane/zmieniane w PHP i które tabele schematu
// są już używane przez kod. Wynik: narzedzia/sql/STAN.md
//
//   node narzedzia/sql/stan.js
'use strict';
const fs = require('fs');
const path = require('path');

const KORZEN = path.resolve(__dirname, '..', '..');
const API = path.join(KORZEN, 'api');
const WYNIK = path.join(__dirname, 'STAN.md');

const php = fs.readdirSync(API).filter((f) => f.endsWith('.php'))
  .map((f) => ({ plik: f, kod: fs.readFileSync(path.join(API, f), 'utf8') }));
const schemat = fs.readFileSync(path.join(API, 'schema.sql'), 'utf8').replace(/--[^\n]*/g, '');

// --- dokumenty JSON ---
const dokumenty = new Map(); // nazwa -> {wczytaj, zmien, pliki:Set}
const dynamiczne = [];
for (const { plik, kod } of php) {
  for (const m of kod.matchAll(/\b(wczytaj|zmien)\(\s*(?:'([^']+)'|"([^"]+)"|([^,)]+))/g)) {
    const [, fn, a, b, wyr] = m;
    if (/^function\b/.test(kod.slice(Math.max(0, m.index - 9), m.index).trim())) continue;
    const nazwa = a ?? b;
    if (!nazwa) { dynamiczne.push(`${plik}: ${fn}(${wyr.trim()})`); continue; }
    if (!dokumenty.has(nazwa)) dokumenty.set(nazwa, { wczytaj: 0, zmien: 0, pliki: new Set() });
    const d = dokumenty.get(nazwa);
    d[fn]++;
    d.pliki.add(plik);
  }
}

// --- tabele SQL ---
const tabele = [...new Set([...schemat.matchAll(/CREATE TABLE(?: IF NOT EXISTS)?\s+`?([a-z0-9_]+)/gi)].map((m) => m[1]))];
// Nazwy tabel bywają takie same jak dokumentów JSON i kluczy tablic (np. „firmy”), więc
// liczy się tylko nazwa w kontekście SQL (FROM/JOIN/INTO/UPDATE/TABLE) albo tabela_sql_dostepna().
const uzycie = new Map(tabele.map((t) => {
  const wzor = new RegExp(`(?:\\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\\s+\`?${t}\\b|tabela_sql_dostepna\\(\\s*'${t}')`, 'i');
  return [t, php.filter(({ kod }) => wzor.test(kod)).map((p) => p.plik)];
}));
const grupy = new Map();
for (const t of tabele) {
  const g = t.split('_')[0];
  if (!grupy.has(g)) grupy.set(g, []);
  grupy.get(g).push(t);
}

// --- raport ---
const uzywane = tabele.filter((t) => uzycie.get(t).length);
const linie = [
  '# Stan migracji JSON → SQL',
  '',
  'Plik wynikowy: `node narzedzia/sql/stan.js`. Nie edytuj ręcznie.',
  '',
  `- Dokumenty JSON w \`datapad_dane\` nadal używane przez PHP: **${dokumenty.size}**`,
  `- Tabele w \`api/schema.sql\`: **${tabele.length}**, z czego używane przez PHP: **${uzywane.length}**`,
  '',
  'Moduł jest przeniesiony, gdy jego dokument znika z pierwszej tabeli, a kod czyta i zapisuje',
  'tabele SQL. Tabele „fundament” są celowo gotowe na przyszłość i czekają na moduły.',
  '',
  '## Dokumenty JSON do przeniesienia',
  '',
  '| Dokument | odczyty | zmiany | pliki PHP |',
  '|---|---:|---:|---|',
  ...[...dokumenty].sort((a, b) => (b[1].wczytaj + b[1].zmien) - (a[1].wczytaj + a[1].zmien))
    .map(([n, d]) => `| \`${n}\` | ${d.wczytaj} | ${d.zmien} | ${[...d.pliki].sort().join(', ')} |`),
  '',
];
if (dynamiczne.length) {
  linie.push('Wywołania z nazwą wyliczaną w kodzie (sprawdzić ręcznie):', '', ...dynamiczne.map((d) => `- \`${d}\``), '');
}
linie.push('## Tabele SQL według obszaru', '', '| Obszar | używane | fundament (jeszcze bez kodu) |', '|---|---|---|');
for (const [g, ts] of [...grupy].sort((a, b) => a[0].localeCompare(b[0]))) {
  const tak = ts.filter((t) => uzycie.get(t).length).map((t) => `\`${t}\``).join(', ') || '—';
  const nie = ts.filter((t) => !uzycie.get(t).length).map((t) => `\`${t}\``).join(', ') || '—';
  linie.push(`| ${g} | ${tak} | ${nie} |`);
}
linie.push('');
fs.writeFileSync(WYNIK, linie.join('\n'));
console.log(`STAN.md: ${dokumenty.size} dokumentów JSON, ${uzywane.length}/${tabele.length} tabel używanych.`);

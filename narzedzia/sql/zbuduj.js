// Składa pełny schemat bazy (api/schema.sql) z migracji w api/migracje/, w kolejności numerów,
// z publiczną mapą galaktyki wstawioną po migracji 001.
// Źródłem prawdy są migracje; api/schema.sql jest plikiem wynikowym — nie edytuj go ręcznie.
//
//   node narzedzia/sql/zbuduj.js            buduje api/schema.sql
//   node narzedzia/sql/zbuduj.js --sprawdz  kod 1, jeśli api/schema.sql jest nieaktualny
'use strict';
const fs = require('fs');
const path = require('path');

const KORZEN = path.resolve(__dirname, '..', '..');
const MIGRACJE = path.join(KORZEN, 'api', 'migracje');
const CEL = path.join(KORZEN, 'api', 'schema.sql');

const pliki = fs.readdirSync(MIGRACJE).filter((f) => /^\d{3}_.+\.sql$/.test(f)).sort();
const numery = pliki.map((f) => Number(f.slice(0, 3)));
numery.forEach((n, i) => {
  const poprzedni = i ? numery[i - 1] : 0;
  if (n !== poprzedni && n !== poprzedni + 1) throw new Error(`Luka w numeracji migracji przy ${pliki[i]} (oczekiwano ${String(poprzedni + 1).padStart(3, '0')}).`);
});
const wersjaMax = Math.max(...numery);

const naglowek = [
  '-- HOLOLINK — pełny schemat bazy dla NOWEJ, PUSTEJ bazy (MySQL 8.0.16+ / MariaDB 10.6+).',
  '-- PLIK WYNIKOWY: nie edytuj. Zmiany schematu dopisuj jako nową migrację w api/migracje/',
  '-- i przebuduj: node narzedzia/sql/zbuduj.js',
  '-- Działającą bazę aktualizuj pojedynczymi migracjami, nie tym plikiem.',
  `-- Zawiera migracje 001–${String(wersjaMax).padStart(3, '0')} oraz publiczną mapę (api/mapa_seed.sql) zaraz po 001.`,
  '-- Prywatnych paczek danych (archiwum/sql/, poza gitem) tu nie ma — importuje się je osobno.',
  '',
].join('\n');

// Publiczna mapa (api/mapa_seed.sql) wchodzi zaraz po 001: kolejne migracje odwołują się do planet
// (np. Havis III) i nadają nowym planetom id = MAX(id)+1, więc mapa musi już być w bazie.
const czesci = pliki.flatMap((f) => (f.startsWith('001_') ? [path.join(MIGRACJE, f), path.join(KORZEN, 'api', 'mapa_seed.sql')] : [path.join(MIGRACJE, f)]));
const tresc = naglowek + czesci.map((p) => {
  // `USE hololink;` w migracjach jest dla phpMyAdmin; pełny schemat ma działać w bazie o dowolnej nazwie.
  const sql = fs.readFileSync(p, 'utf8').replace(/^﻿/, '').replace(/\r\n/g, '\n').replace(/^USE\s+\S+;\n/gm, '').trimEnd();
  return `\n-- ${'='.repeat(69)}\n-- ${path.relative(path.join(KORZEN, 'api'), p).replace(/\\/g, '/')}\n-- ${'='.repeat(69)}\n\n${sql}\n`;
}).join('');

if (process.argv.includes('--sprawdz')) {
  const obecny = fs.existsSync(CEL) ? fs.readFileSync(CEL, 'utf8').replace(/\r\n/g, '\n') : '';
  if (obecny !== tresc) {
    console.error('api/schema.sql jest nieaktualny. Uruchom: node narzedzia/sql/zbuduj.js');
    process.exit(1);
  }
  console.log(`api/schema.sql aktualny (${pliki.length} plików migracji, wersje 001–${wersjaMax}).`);
} else {
  fs.writeFileSync(CEL, tresc);
  console.log(`Zapisano api/schema.sql z ${pliki.length} plików migracji (wersje 001–${wersjaMax}).`);
}

// Zakładka „Czas” panelu MG: procesy w toku i resynchronizacja (ingerencja mocy).
// Czas świata płynie 1:1. MG może skrócić albo wydłużyć pojedynczy proces; gracze widzą tylko
// skutek (np. statek wychodzi z nadprzestrzeni wcześniej), bez wyjaśnienia. Logika: api/czas.php.

import { h, wyczysc, czasGry } from '../js/rdzen/narzedzia.js';
import { api } from '../js/rdzen/api.js';

const RODZAJE = { skok_floty: 'Skok floty', manewr: 'Manewr (kurs)', dolot: 'Dolot (orbita)', lot: 'Lot podświetlny', skok_statku: 'Skok statku', podroz: 'Podróż postaci', obsluga_statku: 'Obsługa naziemna' };

const msg = (t, rodzaj = 'blad') => h('div.msg.' + rodzaj, t);
const btn = (tekst, fn, klasa = '') => h('button.btn' + klasa, { type: 'button', onclick: fn }, tekst);

function ileZostalo(sekundy) {
  if (sekundy <= 0) return 'teraz';
  const g = Math.floor(sekundy / 3600), m = Math.floor((sekundy % 3600) / 60), s = sekundy % 60;
  return g ? `${g} h ${m} min` : m ? `${m} min ${s} s` : `${s} s`;
}

const godzina = (t) => czasGry(t);

export async function panelCzasu(kontener) {
  const dane = await api('mg_procesy');
  const przesuniecie = dane.teraz - Math.floor(Date.now() / 1000); // różnica zegara serwera i przeglądarki
  const info = h('div');
  const odswiez = async () => { wyczysc(kontener); await panelCzasu(kontener); };

  const wiersz = (p) => {
    const minuty = h('input', { type: 'number', min: 0, step: 1, value: 5, style: 'max-width:90px' });
    const notatka = h('input', { placeholder: 'Notatka dla MG (opcjonalnie)', maxlength: 500 });
    const licznik = h('span');
    const tik = () => { licznik.textContent = ileZostalo(p.koniec - Math.floor(Date.now() / 1000) - przesuniecie); };
    tik();
    const zegar = setInterval(() => (licznik.isConnected ? tik() : clearInterval(zegar)), 1000);
    const zmien = async (ile) => {
      try {
        await api('mg_resynchronizuj', { rodzaj: p.rodzaj, obiekt: p.obiekt, minuty: ile, notatka: notatka.value });
        await odswiez();
        kontener.prepend(msg('Resynchronizacja zapisana.', 'ok'));
      } catch (e) { wyczysc(info).append(msg(e.message)); }
    };
    return h('div.karta',
      h('b', p.opis), h('div.slaby', `${RODZAJE[p.rodzaj] || p.rodzaj} · start ${godzina(p.start)} · koniec ${godzina(p.koniec)} · zostało: `, licznik),
      h('div.rzad', h('label', 'Zakończ za (min) ', minuty), notatka,
        btn('Resynchronizuj', () => zmien(Number(minuty.value)), '.glowny'),
        btn('Zakończ teraz', () => zmien(0))));
  };

  const dziennik = dane.dziennik.length
    ? h('table', h('tr', h('th', 'Kiedy (UTC)'), h('th', 'Proces'), h('th', 'Koniec był'), h('th', 'Koniec jest'), h('th', 'MG'), h('th', 'Notatka')),
      ...dane.dziennik.map((d) => h('tr', h('td', d.utworzono_utc), h('td', `${RODZAJE[d.rodzaj] || d.rodzaj}: ${d.opis}`),
        h('td', d.koniec_przed_utc), h('td', d.koniec_po_utc), h('td', d.mg_login), h('td', d.notatka))))
    : h('p.slaby', 'Brak ingerencji.');

  // Ostatnie błędy PHP (gracz widzi wtedy „Węzeł holonetu nie odpowiada”).
  const dziennikBledow = h('div.slaby', 'Ładowanie...');
  api('mg_bledy').then((b) => {
    wyczysc(dziennikBledow).append(b.wpisy.length ? h('pre', { style: 'white-space:pre-wrap;font-size:12px;max-height:320px;overflow:auto' }, b.wpisy.join('\n')) : 'Brak błędów.');
  }).catch((e) => wyczysc(dziennikBledow).append(e.message));
  kontener.append(
    h('h2', `Procesy w toku (${dane.procesy.length})`),
    h('p.slaby', 'Czas świata płynie 1:1 z rzeczywistym. Resynchronizacja (ingerencja mocy) przesuwa koniec jednego procesu: '
      + 'można go skrócić albo wydłużyć. Gracze widzą tylko skutek — gra niczego nie wyjaśnia.'),
    dane.dziennik_dostepny ? null : msg('Brak tabeli resynchronizacje: zaimportuj w phpMyAdmin api/migracje/013_czas_i_resynchronizacje.sql.'),
    ...(dane.ostrzezenia || []).map((t) => msg(t + ' Zaimportuj brakujące pliki z api/migracje/ w phpMyAdmin.')),
    info,
    btn('Odśwież', odswiez),
    ...(dane.procesy.length ? dane.procesy.map(wiersz) : [h('p.slaby', 'Nic nie trwa.')]),
    h('h2', 'Dziennik resynchronizacji'), dziennik,
    h('h2', 'Dziennik błędów serwera'), dziennikBledow,
  );
}

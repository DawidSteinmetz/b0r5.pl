// Panel MG: konta graczy, dokumenty dekodera, komunikaty holonetu, dane frakcji, harmonogram.
// To narzędzie poza światem gry, więc mówi normalnym językiem.

import { h, wyczysc } from '../js/rdzen/narzedzia.js';
import { api } from '../js/rdzen/api.js';
import { panelWydarzen } from './wydarzenia.js';

const FRAKCJE = ['Rebelia', 'Imperium', 'Syndykat'];
const glowna = document.getElementById('glowna');
const menu = document.getElementById('menu');
let biezaca = 'stan';

const msg = (t, rodzaj = 'blad') => h('div.msg.' + rodzaj, t);
const pole = (etykieta, el) => h('label', etykieta, el);
const btn = (tekst, fn, klasa = '') => h('button.btn' + klasa, { type: 'button', onclick: fn }, tekst);

function bezpiecznie(fn) {
  return async (...a) => {
    try {
      await fn(...a);
    } catch (e) {
      glowna.prepend(msg(e.message));
    }
  };
}

// ---------- start ----------

async function start() {
  let s;
  try {
    s = await api('stan');
  } catch (e) {
    wyczysc(glowna).append(msg('Brak połączenia z API: ' + e.message));
    return;
  }
  if (s.uzytkownik?.rola === 'mg') return panel(s.uzytkownik);
  if (!s.mgIstnieje) return zalozMG();
  logowanie(s.uzytkownik ? 'To konto nie ma uprawnień MG.' : '');
}

function logowanie(komunikat) {
  const login = h('input', { autocomplete: 'username' });
  const klucz = h('input', { type: 'password', autocomplete: 'current-password' });
  const info = h('div', komunikat ? msg(komunikat) : null);
  wyczysc(glowna).append(h('form.karta', {
    style: 'max-width:380px',
    onsubmit: async (e) => {
      e.preventDefault();
      try {
        const { uzytkownik } = await api('zaloguj', { login: login.value, klucz: klucz.value });
        if (uzytkownik.rola !== 'mg') return logowanie('To konto nie ma uprawnień MG.');
        panel(uzytkownik);
      } catch (err) {
        wyczysc(info).append(msg(err.message));
      }
    },
  }, h('h2', 'Logowanie MG'), info, pole('Login', login), h('br'), pole('Hasło', klucz), h('br'),
  h('button.btn.glowny', { type: 'submit' }, 'Zaloguj')));
}

function zalozMG() {
  const login = h('input');
  const klucz = h('input', { type: 'password', minlength: 8 });
  const info = h('div');
  wyczysc(glowna).append(h('form.karta', {
    style: 'max-width:420px',
    onsubmit: async (e) => {
      e.preventDefault();
      try {
        const { uzytkownik } = await api('mg_zaloz', { login: login.value, klucz: klucz.value });
        panel(uzytkownik);
      } catch (err) {
        wyczysc(info).append(msg(err.message));
      }
    },
  }, h('h2', 'Pierwsze uruchomienie'), h('p.slaby', 'Nie ma jeszcze konta MG. Załóż je teraz (hasło min. 8 znaków). Później ta opcja znika.'),
  info, pole('Login MG', login), h('br'), pole('Hasło', klucz), h('br'), h('button.btn.glowny', { type: 'submit' }, 'Załóż konto MG')));
}

function panel(u) {
  document.getElementById('kto').textContent = 'Zalogowano: ' + u.login;
  menu.hidden = false;
  const zakladki = { stan: 'Stan postaci', karty: 'Karty postaci', konta: 'Konta', bank: 'Bank', gielda: 'Giełda', wydarzenia: 'Wydarzenia', rekrutacja: 'Rekrutacja', katalog: 'Katalog', dokumenty: 'Dokumenty', kanaly: 'Kanały', holonet: 'Holonet', frakcje: 'Frakcje', harmonogram: 'Kalendarz' };
  const rysujMenu = () => wyczysc(menu).append(
    ...Object.entries(zakladki).map(([k, n]) => h('button' + (k === biezaca ? '.on' : ''), { onclick: () => { biezaca = k; rysujMenu(); otworz(); } }, n)),
    h('button', { onclick: async () => { await api('wyloguj', {}); location.reload(); } }, 'Wyloguj'));
  rysujMenu();
  otworz();
}

function otworz() {
  wyczysc(glowna);
  ({ stan: stanPostaci, karty: kartyPostaci, konta, bank, gielda, wydarzenia, firmy, rekrutacja, katalog, dokumenty, kanaly, holonet, frakcje, harmonogram }[biezaca])().catch((e) => glowna.prepend(msg(e.message)));
}

// ---------- konta ----------

async function konta(edytowany = null) {
  const { uzytkownicy } = await api('mg_uzytkownicy');
  const e = edytowany || {};
  const f = {
    login: h('input', { value: e.login || '' }),
    klucz: h('input', { type: 'text', placeholder: e.id ? 'zostaw puste = bez zmian' : 'wymagane' }),
    postac: h('input', { value: e.postac || '' }),
    frakcja: h('select', FRAKCJE.map((x) => h('option', { selected: e.frakcja === x }, x))),
    oddzial: h('input', { value: e.oddzial || '' }),
    ranga: h('input', { value: e.ranga || '' }),
    poziom: h('input', { type: 'number', min: 1, max: 9, value: e.poziom || 1 }),
    rola: h('select', h('option', { value: 'gracz', selected: e.rola !== 'mg' }, 'gracz'), h('option', { value: 'mg', selected: e.rola === 'mg' }, 'MG')),
  };
  const zapisz = bezpiecznie(async () => {
    const dane = Object.fromEntries(Object.entries(f).map(([k, el]) => [k, el.value]));
    await api('mg_uzytkownik_zapisz', { ...dane, id: e.id || '', poziom: Number(dane.poziom) });
    wyczysc(glowna);
    await konta();
    glowna.prepend(msg('Zapisano konto. Hasło przekaż graczowi osobno — w panelu nie da się go już odczytać.', 'ok'));
  });

  glowna.append(
    h('div.karta', h('h2', e.id ? `Edycja: ${e.login}` : 'Nowe konto gracza'),
      h('div.siatka', pole('Login', f.login), pole('Hasło', f.klucz), pole('Postać', f.postac), pole('Frakcja', f.frakcja),
        pole('Oddział', f.oddzial), pole('Ranga', f.ranga), pole('Poziom uprawnień (1–9)', f.poziom), pole('Rola', f.rola)),
      h('div.rzad', btn('Zapisz', zapisz, '.glowny'), e.id ? btn('Anuluj', () => { wyczysc(glowna); konta(); }) : null)),
    h('div.karta.przewijana', h('h2', `Konta (${uzytkownicy.length})`),
      h('table', h('tr', h('th', 'Login'), h('th', 'Postać'), h('th', 'Frakcja'), h('th', 'Oddział / ranga'), h('th', 'Poziom'), h('th', '')),
        uzytkownicy.map((x) => h('tr',
          h('td', x.login, x.rola === 'mg' ? h('span.slaby', ' (MG)') : null),
          h('td', x.postac), h('td', x.frakcja), h('td', [x.oddzial, x.ranga].filter(Boolean).join(' / ')), h('td', x.poziom),
          h('td.rzad',
            btn('Edytuj', () => { wyczysc(glowna); konta(x); }),
            btn('Usuń', bezpiecznie(async () => {
              if (!confirm(`Usunąć konto ${x.login}?`)) return;
              await api('mg_uzytkownik_usun', { id: x.id });
              wyczysc(glowna); await konta();
            }), '.zly')))))),
  );
}

// ---------- bank ----------

const kr = (n) => `${Number(n).toLocaleString('pl-PL')} kr`;
const TYPY_OPERACJI = { przelew: 'przelew', dochod: 'stały dochód', korekta: 'operacja MG' };

// Najbliższy termin wypłaty stałego dochodu (dzień miesiąca po ostatniej wypłacie).
function nastepnaWyplata(d) {
  const [r, m] = d.ostatnio.split('-').map(Number);
  const t = new Date(r, m, d.dzien); // miesiąc m (0-based) = miesiąc po „ostatnio”
  return t.toLocaleDateString('pl-PL');
}

async function bank(edytowany = null) {
  const { konta, dochody, transakcje } = await api('mg_bank');
  const nazwa = Object.fromEntries(konta.map((k) => [k.uid, k.postac]));
  const wyborPostaci = (wybrana) => h('select', konta.map((k) => h('option', { value: k.uid, selected: k.uid === wybrana }, `${k.postac} (${k.frakcja})`)));
  const odswiez = async (komunikat) => { wyczysc(glowna); await bank(); if (komunikat) glowna.prepend(msg(komunikat, 'ok')); };

  // Operacja jednorazowa
  const op = { uid: wyborPostaci(), kwota: h('input', { type: 'number', step: 1, placeholder: 'np. 500 albo -200' }), zrodlo: h('input', { placeholder: 'np. Nagroda Huttów' }), tytul: h('input') };
  const wykonaj = bezpiecznie(async () => {
    const kwota = Math.trunc(Number(op.kwota.value));
    if (!kwota) throw new Error('Podaj kwotę różną od zera (ujemna = obciążenie).');
    await api('mg_bank_korekta', { uid: op.uid.value, kwota, zrodlo: op.zrodlo.value, tytul: op.tytul.value });
    await odswiez(`Zaksięgowano ${kr(kwota)} dla: ${nazwa[op.uid.value]}.`);
  });

  // Stały dochód
  const e = edytowany || {};
  const doch = {
    uid: wyborPostaci(e.uid),
    kwota: h('input', { type: 'number', step: 1, value: e.kwota ?? '' }),
    dzien: h('input', { type: 'number', min: 1, max: 28, value: e.dzien ?? 1 }),
    zrodlo: h('input', { value: e.zrodlo || '', placeholder: 'np. Dowództwo Rebelii' }),
    tytul: h('input', { value: e.tytul || '', placeholder: 'np. Żołd miesięczny' }),
  };
  const zapiszDochod = bezpiecznie(async () => {
    const kwota = Math.trunc(Number(doch.kwota.value));
    if (!kwota) throw new Error('Podaj kwotę różną od zera (ujemna = stała opłata).');
    await api('mg_bank_dochod_zapisz', { id: e.id || '', uid: doch.uid.value, kwota, dzien: Number(doch.dzien.value), zrodlo: doch.zrodlo.value, tytul: doch.tytul.value });
    await odswiez('Zapisano stały dochód.');
  });

  glowna.append(
    h('div.karta.przewijana', h('h2', 'Rachunki postaci'),
      h('table', h('tr', h('th', 'Postać'), h('th', 'Frakcja'), h('th', 'Rachunek'), h('th', 'Saldo')),
        konta.map((k) => h('tr', h('td', k.postac), h('td', k.frakcja), h('td', k.numer), h('td', kr(k.saldo)))))),
    h('div.karta', h('h2', 'Operacja jednorazowa'),
      h('p.slaby', 'Wpłata (kwota dodatnia) albo obciążenie (ujemna). Gracz zobaczy „Źródło” jako nadawcę lub odbiorcę.'),
      h('div.siatka', pole('Postać', op.uid), pole('Kwota', op.kwota), pole('Źródło', op.zrodlo), pole('Tytuł', op.tytul)),
      btn('Zaksięguj', wykonaj, '.glowny')),
    h('div.karta', h('h2', e.id ? 'Edycja stałego dochodu' : 'Nowy stały dochód'),
      h('p.slaby', 'Wypłacany co miesiąc w podanym dniu (1–28). Kwota ujemna działa jak stała opłata. Nowy dochód wypłaca się pierwszy raz w najbliższym takim dniu. Jeśli nikt nie wchodził do banku, zaległe wypłaty dopiszą się przy pierwszym wejściu.'),
      h('div.siatka', pole('Postać', doch.uid), pole('Kwota miesięczna', doch.kwota), pole('Dzień miesiąca', doch.dzien), pole('Źródło (nadawca)', doch.zrodlo), pole('Tytuł', doch.tytul)),
      h('div.rzad', btn('Zapisz', zapiszDochod, '.glowny'), e.id ? btn('Anuluj', () => { wyczysc(glowna); bank(); }) : null)),
    h('div.karta.przewijana', h('h2', `Stałe dochody (${dochody.length})`),
      h('table', h('tr', h('th', 'Postać'), h('th', 'Kwota'), h('th', 'Dzień'), h('th', 'Źródło / tytuł'), h('th', 'Następna wypłata'), h('th', '')),
        dochody.map((d) => h('tr',
          h('td', nazwa[d.uid] || '(usunięta postać)'), h('td', kr(d.kwota)), h('td', d.dzien),
          h('td', d.zrodlo || '—', h('div.slaby', d.tytul || '')), h('td', nastepnaWyplata(d)),
          h('td.rzad',
            btn('Edytuj', () => { wyczysc(glowna); bank(d); }),
            btn('Usuń', bezpiecznie(async () => {
              if (!confirm('Usunąć stały dochód?')) return;
              await api('mg_bank_dochod_usun', { id: d.id });
              await odswiez('Usunięto.');
            }), '.zly')))))),
    h('div.karta.przewijana', h('h2', 'Ostatnie operacje'),
      h('table', h('tr', h('th', 'Czas'), h('th', 'Typ'), h('th', 'Od'), h('th', 'Do'), h('th', 'Kwota'), h('th', 'Tytuł')),
        transakcje.map((t) => h('tr', h('td', new Date(t.czas).toLocaleString('pl-PL')), h('td', TYPY_OPERACJI[t.typ] || t.typ),
          h('td', t.od_nazwa), h('td', t.do_nazwa), h('td', kr(t.kwota)), h('td', t.tytul || ''))))),
  );
}

// ---------- katalog: towary i planety ----------

const WZOR_TOWAROW = [
  { nazwa: 'Zboże', kategoria: 'żywność', masa: 1, jednostka: 't', cena: 20 },
  { nazwa: 'Ogniwa paliwowe', kategoria: 'paliwo', masa: 2, jednostka: 'skrzynia', cena: 60 },
  { nazwa: 'Części hipernapędu', kategoria: 'technika', masa: 5, jednostka: 'kontener', cena: 900 },
];
const WZOR_OFERT = [
  { nazwa: 'Kopalnia Kessa', typ: 'kopalnia', planeta: 'Zephyria', wspolrzedne: 'Z-04 · 12.7 / 88.1', powierzchnia: 2400, pietra: 2, szyby: 6, cena: 5000, pojemnosc: 40, pensja: 15, opis: 'Stare sztolnie, żyła rudy na wyczerpaniu.' },
  { nazwa: 'Hangar 7', typ: 'hangar', planeta: 'Zephyria', wspolrzedne: 'Z-01 · 3.2 / 4.9', powierzchnia: 900, pietra: 1, szyby: 0, cena: 800, pojemnosc: 5, pensja: 10 },
];
const WZOR_LOKACJI = [
  { nazwa: 'Port kosmiczny Zephyria', planeta: 'Zephyria', typ: 'port', x: 0, y: 0, publiczna: true },
  { nazwa: 'Hangar 3', planeta: 'Zephyria', typ: 'hangar', x: 1.2, y: -0.8, publiczna: true, jakosc: 4, paliwo: 40, cena_paliwa: 15 },
  { nazwa: 'Hotel Pod Bantą', planeta: 'Zephyria', typ: 'hotel', x: 4.5, y: 2.1, publiczna: true },
  { nazwa: 'Bankomat przy targu', planeta: 'Zephyria', typ: 'bankomat', x: 4.2, y: 2.6, publiczna: true },
  { nazwa: 'Sklep Watta', planeta: 'Zephyria', typ: 'sklep', x: 3.9, y: 2.9, publiczna: true, oferta: [{ nazwa: 'Medpak', cena: 120 }, { nazwa: 'Racje żywnościowe (tydzień)', cena: 40 }] },
  { nazwa: 'Kantyna „Trzy Księżyce”', planeta: 'Zephyria', typ: 'kantyna', x: 6.3, y: 1.0, publiczna: true },
  { nazwa: 'Melina Huttów', planeta: 'Zephyria', typ: 'sklep', x: 22.5, y: -14, publiczna: false, oferta: [{ nazwa: 'Blaster bez numerów', cena: 900 }] },
];
const WZOR_PLANET = [
  { nazwa: 'Zephyria', sektor: 'Zewnętrzne Rubieże', typ: 'oceaniczna', wspolrzedne: 'Z-12 · 044.10 / 017.32', urbanizacja: 'wysoka', taksowka: 4, specyfika: { 'żywność': 0.7, technika: 1.3 } },
  { nazwa: 'Tatooine', sektor: 'Arkanis', typ: 'pustynna', kontrola: 'Kontrola Lotów Mos Eisley', wspolrzedne: 'R-16 · 112.07 / -33.90', urbanizacja: 'niska', specyfika: { 'żywność': 1.6, paliwo: 0.8 } },
];

async function katalog() {
  const { katalog: k, gieldy: listaGield, firmy: listaFirm } = await api('mg_katalog');
  const towary = h('textarea', { style: 'min-height:260px' }, JSON.stringify(k.towary, null, 2));
  // Specyfika planet: klucze t:<id>/k:<kategoria> zamieniamy na nazwy, żeby dało się je czytać.
  const nazwaTowaru = Object.fromEntries(k.towary.map((t) => [t.id, t.nazwa]));
  const planetyCzytelne = k.planety.map((p) => ({
    ...p,
    specyfika: Object.fromEntries(Object.entries(p.specyfika || {}).map(([kl, v]) => [kl.startsWith('t:') ? (nazwaTowaru[kl.slice(2)] || kl) : kl.slice(2), v])),
  }));
  const planety = h('textarea', { style: 'min-height:200px' }, JSON.stringify(planetyCzytelne, null, 2));
  const pl = Object.fromEntries(k.planety.map((p) => [p.id, p.nazwa]));
  const oferty = h('textarea', { style: 'min-height:200px' }, JSON.stringify(k.oferty.map((o) => ({ ...o, planeta: pl[o.planeta] || '' })), null, 2));
  const lokacje = h('textarea', { style: 'min-height:260px' }, JSON.stringify((k.lokacje || []).map((l) => ({ ...l, planeta: pl[l.planeta] || '' })), null, 2));
  const tempo = h('input', { type: 'number', min: 0.05, max: 60, step: 0.05, value: k.ustawienia?.tempo ?? 1, style: 'max-width:140px' });
  const info = h('div');
  const spolki = (k.spolki || []).map((s) => ({ ...s }));
  const npc = (k.npc || []).map((n) => ({ ...n }));
  const listaSpolek = h('div');
  const rysujSpolki = () => {
    wyczysc(listaSpolek).append(...spolki.map((s, i) => {
      const symbol = h('input', { value: s.symbol || '', maxlength: 6, placeholder: 'np. CEC', style: 'width:90px' });
      const nazwa = h('input', { value: s.nazwa || '', placeholder: 'Nazwa spółki' });
      const branza = h('input', { value: s.branza || '', placeholder: 'Branża', style: 'width:140px' });
      const status = h('select', [h('option', { value: 'notowana', selected: s.status !== 'poza_gielda' }, 'Firma notowana na giełdzie'),
        h('option', { value: 'poza_gielda', selected: s.status === 'poza_gielda' }, 'Firma poza giełdą')]);
      const gielda = h('select', listaGield.map((g) => h('option', { value: g.id, selected: g.id === s.gielda }, g.nazwa)));
      const cena = h('input', { type: 'number', min: 1, step: 'any', value: s.cena || 100, title: 'Cena bazowa akcji', style: 'width:110px' });
      const pochodzenie = h('input', { value: s.pochodzenie || '', placeholder: 'Planeta pochodzenia', style: 'width:160px' });
      const profil = h('select', Object.entries(PROFILE_BIZNES).map(([key, label]) => h('option', { value: key, selected: key === (s.profil || 'produkcja') }, label)));
      const skala = h('select', [h('option', { value: 'mala', selected: s.skala === 'mala' }, 'Mała'),
        h('option', { value: 'srednia', selected: s.skala === 'srednia' }, 'Średnia'), h('option', { value: 'duza', selected: s.skala === 'duza' }, 'Duża')]);
      const menedzer = npc.find((n) => n.spolka_symbol === s.symbol);
      const imieMenedzera = h('input', { value: menedzer?.imie || s.menedzer || '', placeholder: 'NPC — menedżer' });
      const funkcjaMenedzera = h('input', { value: menedzer?.funkcja || s.funkcja_menedzera || 'Dyrektor zarządzający', placeholder: 'Funkcja', style: 'width:180px' });
      const wiersz = h('div.panel', { style: 'padding:10px; margin:8px 0' },
        h('div.rzad', { style: 'gap:8px; flex-wrap:wrap' }, symbol, nazwa, branza, status, gielda, cena, pochodzenie),
        h('div.rzad', { style: 'gap:8px; margin-top:8px; flex-wrap:wrap' }, profil, skala, imieMenedzera, funkcjaMenedzera));
      wiersz._dane = () => ({ symbol: symbol.value, nazwa: nazwa.value, branza: branza.value, status: status.value,
        gielda: gielda.value, cena: Number(cena.value), pochodzenie: pochodzenie.value, profil: profil.value, skala: skala.value,
        firma_id: s.firma_id || '', menedzer_id: menedzer?.id || s.menedzer_id || '', menedzer: imieMenedzera.value, funkcja_menedzera: funkcjaMenedzera.value });
      wiersz._npc = () => ({ id: menedzer?.id || s.menedzer_id || '', spolka_symbol: symbol.value, imie: imieMenedzera.value, funkcja: funkcjaMenedzera.value });
      return wiersz;
    }));
  };
  rysujSpolki();
  glowna.append(h('div.karta',
    h('h2', 'Katalog gry'),
    h('p.slaby', 'Wspólna lista dla całej gry: towary używają firmy i giełda, spółki pokazują ich status notowania, a planety używają planowanie kursów i mapa. '
      + 'Pole „id” nadaje serwer — nie zmieniaj go, bo odwołują się do niego magazyny i kursy. Nowe pozycje dopisuj bez „id”. '
      + 'Towar: nazwa, kategoria, masa (t na jednostkę), jednostka, cena (bazowa, kr). '
      + 'Planeta: nazwa, sektor, typ (pustynna, lodowa, oceaniczna, lesna, wulkaniczna, gazowa, miasto, bagienna, skalista — widok z powierzchni i chmury przy starcie), wspolrzedne (do skoku), kontrola (nazwa kontroli lotów, np. „Imperialna Kontrola Lotów”; brak = planeta bez kontroli), specyfika — mnożniki ceny dla towarów albo kategorii, np. {"surowce": 0.6, "Zboże": 1.5} '
      + '(0.6 = tanio, bo się tam produkuje; 1.5 = drogo, bo jest popyt). Kursy wahają się codziennie o ±8%, kupno/sprzedaż różnią się o 10%. '
      + 'Mapa galaktyki: planety łączą się z mapą po nazwie. Planetę, której mapa nie zna, dodaje pole mapa: {"x": 8340, "y": -2990, "kwadrat": "Q-12"} '
      + '(y rośnie na północ; region opcjonalny, domyślnie region najbliższej planety). Pole widoczna_dla: ["ISB-5023", ...] — loginy, które widzą tę planetę na mapie; '
      + 'pozostali gracze w ogóle się o niej nie dowiedzą (MG widzi zawsze).'),
    info,
    h('label', 'Towary', towary), h('br'),
    h('h3', 'Spółki'),
    h('p.slaby', 'Lista obejmuje spółki notowane i prywatne. Profil, skalę kapitału i NPC-menadżera ustawiasz tutaj. Dla notowanych giełda prowadzi akcje; firmy poza giełdą nie są dostępne do handlu. Symbol można zostawić pusty — system nada go automatycznie.'),
    listaSpolek,
    btn('Dodaj spółkę', () => { spolki.push({ symbol: '', nazwa: '', branza: '', status: 'poza_gielda', gielda: listaGield[0]?.id || '', cena: 100, pochodzenie: '' }); rysujSpolki(); }),
    h('br'),
    h('div.karta', h('h3', `Firmy w systemie (${listaFirm?.length ?? 0})`),
      h('p.slaby', 'Spółki giełdowe i prywatne mają własne profile działalności, personel NPC i rachunki bankowe.'),
      btn('Otwórz rejestr firm', () => { wyczysc(glowna); firmy(true); }, '.glowny')),
    h('label', 'Planety', planety), h('br'),
    h('label', 'Lokacje — miejsca na planetach, do których postacie mogą się przemieszczać: nazwa, planeta, typ (hangar, platforma, port, hotel, bankomat, sklep, kantyna, biuro, garaz, inne; lądowiska: hangar, platforma, port), '
      + 'x i y (km na mapie planety), publiczna (true = każdy zna; false = tylko postacie z „znane miejsca” w karcie). Hangar: jakosc 1–5 (szybkość obsługi), paliwo (zapas w %; brak = bez ograniczeń), cena_paliwa (kr za 1%). '
      + 'Sklep: oferta [{"nazwa": "...", "cena": 0}]. Budynki firm z x i y są lokacjami automatycznie.', lokacje), h('br'),
    h('label', 'Tempo czasu: sekundy rzeczywiste na minutę świata gry (1 = 30-minutowy przejazd trwa 30 s). Planety: urbanizacja brak / niska / wysoka (taksówki), taksowka = kr za km.', tempo), h('br'),
    h('label', 'Rynek nieruchomości — oferty budynków do kupienia przez firmy (nazwa, typ, planeta po nazwie, wspolrzedne, powierzchnia w m², pietra nad ziemią, szyby = poziomy pod ziemią, cena, pojemnosc, pensja na pracownika, opis). Kupiona oferta znika.', oferty), h('br'),
    h('div.rzad',
      btn('Zapisz katalog', async () => {
        try {
          await api('mg_katalog_zapisz', { towary: JSON.parse(towary.value), spolki: [...listaSpolek.children].map((x) => x._dane()),
            npc: [...listaSpolek.children].map((x) => x._npc()).filter((n) => n.imie.trim()),
            planety: JSON.parse(planety.value), oferty: JSON.parse(oferty.value), lokacje: JSON.parse(lokacje.value), tempo: Number(tempo.value) });
          wyczysc(glowna); await katalog();
          glowna.prepend(msg('Zapisano katalog.', 'ok'));
        } catch (e) {
          wyczysc(info).append(msg(e instanceof SyntaxError ? 'Błąd w JSON: ' + e.message : e.message));
        }
      }, '.glowny'),
      btn('Wstaw przykład', () => {
        if (towary.value.trim() === '[]') towary.value = JSON.stringify(WZOR_TOWAROW, null, 2);
        if (planety.value.trim() === '[]') planety.value = JSON.stringify(WZOR_PLANET, null, 2);
        if (oferty.value.trim() === '[]') oferty.value = JSON.stringify(WZOR_OFERT, null, 2);
        if (lokacje.value.trim() === '[]') lokacje.value = JSON.stringify(WZOR_LOKACJI, null, 2);
      }))));
}

// ---------- stan faktyczny: scena w tle datapadów ----------

const SCENY = {
  kosmos: 'Przestrzeń (gwiazdy)',
  wejscie: 'Wejście w nadświetlną → tunel',
  nadswietlna: 'Lot w nadświetlnej (tunel)',
  wyjscie: 'Wyjście z nadświetlnej → planeta',
  orbita: 'Orbita planety',
  powierzchnia: 'Na powierzchni (widok z okna / hangaru)',
  start: 'Start z planety (5 min chmur → orbita)',
};
const WNETRZA = { '': 'na zewnątrz', biuro: 'okno biura', hotel: 'okno hotelu', kantyna: 'okno kantyny', hangar: 'wrota hangaru', port: 'port kosmiczny' };
const PLANETY = {
  pustynna: 'pustynna', lodowa: 'lodowa', oceaniczna: 'oceaniczna', lesna: 'leśna', wulkaniczna: 'wulkaniczna',
  gazowa: 'gazowy olbrzym', miasto: 'miasto-planeta', bagienna: 'bagienna', skalista: 'skalista',
};
const ODLEGLOSCI = { blisko: 'blisko (planeta wypełnia dół ekranu)', srednio: 'średnio', daleko: 'daleko (mała kula)' };

async function stanPostaci() {
  const { postacie } = await api('mg_sceny');
  const { postacie: karty, budynki, lokacje } = await api('mg_karty');
  const pozycje = Object.fromEntries(karty.map((k) => [k.uid, k.karta.pozycja]));
  let planetyKatalogu = [];
  try { planetyKatalogu = (await api('mg_katalog')).katalog.planety.map((p) => p.nazwa); } catch { /* katalog pusty */ }
  const wybrane = new Set(JSON.parse(sessionStorage.getItem('mg.sceny.wybrane') || '[]'));
  const zapamietaj = () => sessionStorage.setItem('mg.sceny.wybrane', JSON.stringify([...wybrane]));

  const wybor = (opcje, dom) => h('select', Object.entries(opcje).map(([k, n]) => h('option', { value: k, selected: k === dom }, n)));
  const f = {
    scena: wybor(SCENY, 'nadswietlna'),
    planeta: wybor(PLANETY, 'oceaniczna'),
    odleglosc: wybor(ODLEGLOSCI, 'srednio'),
    miejsce: h('input', { list: 'mg-planety', placeholder: 'np. Kashyyyk' }),
    opis: h('input', { placeholder: 'np. Kurs: Kashyyyk · ETA 2 h' }),
    wnetrze: wybor(WNETRZA, ''),
  };
  const info = h('div');
  const ustaw = async (nadpisz = {}) => {
    try {
      const dane = { ...Object.fromEntries(Object.entries(f).map(([k, el]) => [k, el.value])), ...nadpisz, uids: [...wybrane] };
      const { ile } = await api('mg_scena_ustaw', dane);
      wyczysc(glowna);
      await stanPostaci();
      glowna.prepend(msg(`Ustawiono „${SCENY[dane.scena]}” dla ${ile} postaci. Tablety odświeżą się w ciągu kilku sekund.`, 'ok'));
    } catch (e) {
      wyczysc(info).append(msg(e.message));
    }
  };
  const opis = (sc) => [SCENY[sc.scena], ['wyjscie', 'orbita'].includes(sc.scena) ? `${PLANETY[sc.planeta]}, ${sc.odleglosc}` : '', sc.miejsce, sc.opis].filter(Boolean).join(' · ');
  const nazwaBudynku = Object.fromEntries(budynki.map((b) => [b.klucz, `${b.nazwa} (${b.firma})`]));
  const nazwaLokacji = Object.fromEntries(lokacje.map((l) => [l.klucz, l.nazwa]));
  const opisPozycji = (p) => (p ? [nazwaLokacji[p.lokacja], p.wspolrzedne, p.miejsce !== nazwaLokacji[p.lokacja] ? p.miejsce : '', nazwaBudynku[p.budynek]].filter(Boolean).join(' · ') : '');

  // Pozycja (karta postaci): planeta, współrzędne, miejsce, budynek.
  const poz = {
    planeta: h('input', { list: 'mg-planety', placeholder: 'np. Kessel' }),
    wspolrzedne: h('input', { placeholder: 'np. K-17 · 34.12 / -77.80' }),
    miejsce: h('input', { placeholder: 'np. pokój 14, hotel Pod Bantą' }),
    budynek: h('select', h('option', { value: '' }, '— żaden —'), budynki.map((b) => h('option', { value: b.klucz }, `${b.nazwa} (${b.firma})`))),
    lokacja: h('select', h('option', { value: '' }, '— bez lokacji (nie może się przemieszczać) —'),
      lokacje.map((l) => h('option', { value: l.klucz }, `${l.planeta} · ${l.nazwa} (${l.typ})`))),
  };
  const ustawPozycje = async () => {
    try {
      await api('mg_pozycja_ustaw', { ...Object.fromEntries(Object.entries(poz).map(([k, el]) => [k, el.value])), uids: [...wybrane] });
      wyczysc(glowna);
      await stanPostaci();
      glowna.prepend(msg('Ustawiono pozycję.', 'ok'));
    } catch (e) {
      wyczysc(info).append(msg(e.message));
    }
  };

  // Statki w locie (start, orbita, skok): zakończenie skoku przy celu.
  const { statki: wLocie } = await api('mg_statki_w_locie');
  const sekcjaLotow = wLocie.length ? h('div.karta',
    h('h2', `Statki w locie (${wLocie.length})`),
    h('p.slaby', 'Start i skok uruchamiają gracze z Nawigacji (skok: współrzędne planety z katalogu). „Zakończ skok” wyprowadza statek z nadprzestrzeni przy celu. '
      + 'Lądują gracze: autopilot na znane lądowisko, ręcznie po współrzędnych (x / y lokacji typu hangar, platforma, port) albo po przydziale od kontroli lotów, którą tu odgrywasz.'),
    wLocie.map((s) => {
      const tresc = h('input', { placeholder: 'odpowiedź kontroli lotów' });
      const przydzial = h('select', h('option', { value: '' }, '— bez przydziału —'), s.ladowiska.map((l) => h('option', { value: l.klucz, selected: s.watek?.przydzial === l.klucz }, `${l.nazwa} (${l.typ}, ${l.x} / ${l.y})`)));
      return h('div', { style: 'border-top:1px solid var(--linia);padding:12px 0' },
        h('div.rzad',
          h('div', { style: 'flex:1' }, h('b', s.nazwa), h('span.slaby', `  ${s.firma} · przy: ${s.przy}${s.cel ? ' · SKOK DO: ' + s.cel : ''} · na pokładzie: ${s.zaloga.join(', ') || '—'}`)),
          s.cel ? btn('Zakończ skok', bezpiecznie(async () => {
            await api('mg_skok_koniec', { statek: s.klucz });
            wyczysc(glowna); await stanPostaci();
            glowna.prepend(msg('Statek wyszedł z nadprzestrzeni przy celu.', 'ok'));
          }), '.glowny') : null),
        !s.cel ? h('div', { style: 'margin-top:8px' },
          h('div.slaby', s.kontrola ? `Kontrola lotów: ${s.kontrola}${s.watek?.czeka ? ' · CZEKAJĄ NA ODPOWIEDŹ' : ''}` : 'Planeta bez kontroli lotów — gracze lądują sami (lista znanych lądowisk albo współrzędne).'),
          (s.watek?.wiadomosci || []).slice(-6).map((x) => h('div', { style: 'margin:4px 0' }, h('span.slaby', `${new Date(x.czas).toLocaleTimeString('pl-PL')} ${x.autor}: `), x.tresc)),
          s.kontrola ? h('div.rzad', { style: 'margin-top:6px' }, h('div', { style: 'flex:2;min-width:220px' }, tresc), h('div', { style: 'flex:1;min-width:180px' }, przydzial),
            btn('Odpowiedz jako kontrola', bezpiecznie(async () => {
              await api('mg_lacznosc_odpowiedz', { statek: s.klucz, tresc: tresc.value, przydzial: przydzial.value });
              wyczysc(glowna); await stanPostaci();
              glowna.prepend(msg('Nadano odpowiedź kontroli lotów.', 'ok'));
            }), '.glowny')) : null) : null);
    })) : null;
  glowna.append(
    sekcjaLotow,
    h('datalist#mg-planety', planetyKatalogu.map((n) => h('option', { value: n }))),
    h('div.karta',
      h('h2', 'Kto?'),
      h('p.slaby', 'Zaznacz postacie, które są w tym samym miejscu (np. na pokładzie jednego statku). Zaznaczenie zostaje do zamknięcia karty.'),
      h('table', h('tr', h('th', ''), h('th', 'Postać'), h('th', 'Frakcja'), h('th', 'Teraz widzi'), h('th', 'Pozycja')),
        postacie.map((p) => {
          const cb = h('input', { type: 'checkbox', checked: wybrane.has(p.uid), style: 'width:auto' });
          cb.addEventListener('change', () => { cb.checked ? wybrane.add(p.uid) : wybrane.delete(p.uid); zapamietaj(); });
          return h('tr', h('td', cb), h('td', p.postac), h('td', p.frakcja), h('td.slaby', opis(p.scena)), h('td.slaby', opisPozycji(pozycje[p.uid])));
        })),
      h('div.rzad', { style: 'margin-top:8px' },
        btn('Zaznacz wszystkich', () => { postacie.forEach((p) => wybrane.add(p.uid)); zapamietaj(); wyczysc(glowna); stanPostaci(); }),
        btn('Odznacz', () => { wybrane.clear(); zapamietaj(); wyczysc(glowna); stanPostaci(); }))),
    h('div.karta',
      h('h2', 'Szybko'),
      h('div.rzad',
        btn('🌌 Przestrzeń', () => ustaw({ scena: 'kosmos' })),
        btn('⚡ Skok w nadświetlną', () => ustaw({ scena: 'wejscie' }), '.glowny'),
        btn('〰 Nadświetlna', () => ustaw({ scena: 'nadswietlna' })),
        btn('🪐 Wyjście przy planecie (ustawienia niżej)', () => ustaw({ scena: 'wyjscie' }), '.glowny'),
        btn('Orbita', () => ustaw({ scena: 'orbita' })))),
    h('div.karta',
      h('h2', 'Dokładnie'),
      info,
      h('div.siatka', pole('Scena', f.scena), pole('Typ planety', f.planeta), pole('Odległość od planety', f.odleglosc), pole('Miejsce', f.miejsce), pole('Opis na pulpicie', f.opis), pole('Widok z (dla „na powierzchni”)', f.wnetrze)),
      h('p.slaby', 'Postać stojąca w lokacji na planecie sama widzi krajobraz tej planety (typ planety z katalogu). Scena ustawiona tutaj wygrywa, dopóki postać nie przemieści się dalej.'),
      h('p.slaby', 'Typ planety i odległość działają przy wyjściu z nadświetlnej i na orbicie. „Miejsce” wpływa też na rysunek powierzchni (ta sama nazwa = ta sama planeta). '
        + 'Animacje skoku i wyjścia grają na tabletach, które są włączone w chwili zmiany; tablet włączony później od razu pokazuje stan końcowy.'),
      btn('Ustaw dla zaznaczonych', () => ustaw(), '.glowny')),
    h('div.karta',
      h('h2', 'Pozycja (karta postaci)'),
      h('p.slaby', 'Gdzie dokładnie są zaznaczone postacie. Budynek (np. biuro przy kopalni, hangar) pokazuje graczowi kartę budynku z jego wymiarami. Współrzędne to na razie tekst; mapa doda później liczby.'),
      h('div.siatka', pole('Lokacja (ustala planetę i umożliwia przemieszczanie)', poz.lokacja), pole('Planeta (gdy bez lokacji)', poz.planeta), pole('Współrzędne', poz.wspolrzedne), pole('Miejsce (opis)', poz.miejsce), pole('Budynek', poz.budynek)),
      btn('Ustaw pozycję dla zaznaczonych', ustawPozycje, '.glowny')),
  );
}

// ---------- karty postaci ----------

// Format edytora: jedna linia = „nazwa = wartość”.
const naLinie = (obj, fmt = (v) => v) => Object.entries(obj || {}).map(([k, v]) => `${k} = ${fmt(v)}`).join('\n');
const zLinii = (tekst, fmt = (v) => v) => Object.fromEntries(tekst.split('\n').map((l) => l.split('=')).filter((p) => p.length > 1 && p[0].trim()).map(([k, ...v]) => [k.trim(), fmt(v.join('=').trim())]));
const listaNaLinie = (l) => (l || []).map((x) => (x.opis ? `${x.nazwa} = ${x.opis}` : x.nazwa)).join('\n');

async function kartyPostaci(wybrany = null) {
  const { postacie } = await api('mg_karty');
  if (!postacie.length) return glowna.append(h('div.karta', h('p.slaby', 'Brak postaci graczy.')));
  const p = postacie.find((x) => x.uid === wybrany) || postacie[0];
  const k = p.karta;
  const wybor = h('select', postacie.map((x) => h('option', { value: x.uid, selected: x.uid === p.uid }, x.postac)));
  wybor.addEventListener('change', () => { wyczysc(glowna); kartyPostaci(wybor.value); });
  const pole2 = (wartosc, wys = 120, ph = '') => h('textarea', { style: `min-height:${wys}px`, placeholder: ph }, wartosc);
  const f = {
    atrybuty: pole2(naLinie(k.statystyki.atrybuty), 140, 'Siła = 3\nZręczność = 2\nIntelekt = 4'),
    umiejetnosci: pole2(naLinie(k.statystyki.umiejetnosci), 140, 'Pilotaż = 2\nBlaster = 1\nNegocjacje = 3'),
    liczniki: pole2(naLinie(k.statystyki.liczniki, (v) => `${v[0]}/${v[1]}`), 80, 'Rany = 3/12\nStres = 2/10'),
    reputacja: pole2(naLinie(k.reputacja), 100, 'Imperium = -40\nHuttowie = 25\nGildia Przemytników = 60'),
    dokumenty: pole2(listaNaLinie(k.dokumenty), 90, 'Licencja pilota kl. B = ważna do GST17\nTożsamość: Mara Senn = fałszywa, dobra jakość'),
    kontakty: pole2(listaNaLinie(k.kontakty), 90, 'Dex Molo = mechanik w dokach Mos Eisley, winien przysługę'),
    ekwipunek: pole2(listaNaLinie(k.ekwipunek), 90, 'Blaster DL-44 = zmodyfikowany\nKomlink szyfrowany'),
    gotowka: h('input', { type: 'number', min: 0, value: k.gotowka || 0 }),
    znane: pole2((k.znane || []).join('\n'), 80, 'Melina Huttów\nTajny bankomat w dokach'),
    pojazdy: pole2(JSON.stringify(k.pojazdy || [], null, 2), 90, '[{"nazwa": "Spider Kiry", "typ": "spider", "predkosc": 220, "lokacja": "Hotel Pod Bantą"}]'),
    stan: h('select', Object.entries({ czysty: 'czysty', notowany: 'notowany', poszukiwany: 'POSZUKIWANY (list gończy)' }).map(([a, b]) => h('option', { value: a, selected: k.status.stan === a }, b))),
    zlecajacy: h('input', { value: k.status.zlecajacy || '', placeholder: 'np. Imperium, Jabba' }),
    nagroda: h('input', { type: 'number', min: 0, value: k.status.nagroda || 0 }),
    opis: h('input', { value: k.status.opis || '', placeholder: 'np. żywy, za kradzież frachtowca' }),
  };
  const info = h('div');
  const zapisz = async () => {
    try {
      await api('mg_karta_zapisz', {
        uid: p.uid,
        statystyki: {
          atrybuty: zLinii(f.atrybuty.value, Number),
          umiejetnosci: zLinii(f.umiejetnosci.value, Number),
          liczniki: zLinii(f.liczniki.value, (v) => v.split('/').map(Number)),
        },
        reputacja: zLinii(f.reputacja.value, Number),
        status: { stan: f.stan.value, zlecajacy: f.zlecajacy.value, nagroda: Number(f.nagroda.value), opis: f.opis.value },
        dokumenty: f.dokumenty.value, kontakty: f.kontakty.value, ekwipunek: f.ekwipunek.value,
        gotowka: Number(f.gotowka.value), znane: f.znane.value, pojazdy: JSON.parse(f.pojazdy.value || '[]'),
      });
      wyczysc(glowna); await kartyPostaci(p.uid);
      glowna.prepend(msg(`Zapisano kartę: ${p.postac}.`, 'ok'));
    } catch (e) {
      wyczysc(info).append(msg(e instanceof SyntaxError ? 'Błąd w JSON pojazdów: ' + e.message : e.message));
    }
  };
  glowna.append(
    h('div.karta',
      h('div.rzad', h('h2', { style: 'flex:1;margin:0' }, 'Karta postaci'), wybor),
      h('p.slaby', 'Każde pole: jedna linia = „nazwa = wartość”. Statystyki są otwarte — wpisz cechy i umiejętności waszego systemu. '
        + 'Gracz widzi wszystko na swojej karcie (poza notatnikiem, który pisze sam). Pozycję ustawiasz w zakładce Stan postaci.'),
      info,
      h('div.siatka', pole('Cechy (liczby)', f.atrybuty), pole('Umiejętności (liczby)', f.umiejetnosci), pole('Liczniki (obecne/max)', f.liczniki)),
      h('div.siatka', pole('Reputacja (−100…100)', f.reputacja), pole('Dokumenty i licencje', f.dokumenty), pole('Kontakty', f.kontakty), pole('Ekwipunek', f.ekwipunek)),
      h('div.siatka', pole('Gotówka (kr)', f.gotowka), pole('Znane miejsca (niepubliczne lokacje, po jednej w linii)', f.znane), pole('Pojazdy prywatne (JSON: nazwa, typ, predkosc, lokacja)', f.pojazdy)),
      h('h2', { style: 'margin-top:12px' }, 'Status w rejestrach'),
      h('div.siatka', pole('Status', f.stan), pole('Zlecający', f.zlecajacy), pole('Nagroda (kr)', f.nagroda), pole('Uwagi', f.opis)),
      btn('Zapisz kartę', zapisz, '.glowny')),
    k.notatki ? h('div.karta', h('h2', 'Notatnik gracza (podgląd)'), h('div', { style: 'white-space:pre-wrap' }, k.notatki)) : null,
  );
}

// ---------- kanały szyfrowane (czarny rynek informacji) ----------

async function kanaly(edytowany = null) {
  const { kanaly: lista } = await api('mg_kanaly');
  const e = edytowany || {};
  const nazwa = h('input', { value: e.nazwa || '', placeholder: 'np. Szept Rubieży' });
  const klucz = h('input', { value: e.klucz || '', placeholder: 'np. KRK-77-WRAITH' });
  const odswiez = async (t) => { wyczysc(glowna); await kanaly(); if (t) glowna.prepend(msg(t, 'ok')); };
  glowna.append(
    h('div.karta',
      h('h2', e.id ? `Edycja kanału: ${e.nazwa}` : 'Nowy kanał'),
      h('p.slaby', 'Gracz instaluje kanał w Ustawieniach → Wtyczki zewnętrzne, wpisując klucz instalacyjny. Kanał pojawia się w Holonecie. '
        + 'Wiadomości pisz szyfrem słownym. Odszyfrowują je pliki deszyfrujące — dokumenty Dekodera ze słownikiem (zakładka Dokumenty). '
        + 'Różne pliki mogą znać różne hasła.'),
      h('div.siatka', pole('Nazwa kanału', nazwa), pole('Klucz instalacyjny', klucz)),
      h('div.rzad',
        btn('Zapisz', bezpiecznie(async () => { await api('mg_kanal_zapisz', { id: e.id || '', nazwa: nazwa.value, klucz: klucz.value }); await odswiez('Zapisano kanał.'); }), '.glowny'),
        e.id ? btn('Anuluj', () => { wyczysc(glowna); kanaly(); }) : null)),
    ...lista.map((k) => {
      const tresc = h('textarea', { style: 'min-height:80px;font-family:inherit', placeholder: 'Duży ptak opuścił gniazdo na Rykowie. Populacja ptaków zmalała tam do oczu.' });
      return h('div.karta',
        h('div.rzad', h('h2', { style: 'flex:1;margin:0' }, k.nazwa), h('span.slaby', `klucz: ${k.klucz}`),
          btn('Edytuj', () => { wyczysc(glowna); kanaly(k); }),
          btn('Usuń kanał', bezpiecznie(async () => { if (!confirm(`Usunąć kanał ${k.nazwa}?`)) return; await api('mg_kanal_usun', { id: k.id }); await odswiez('Usunięto kanał.'); }), '.zly')),
        h('p.slaby', 'Zainstalowali: ' + (k.odbiorcy.join(', ') || 'nikt')),
        tresc,
        h('div', { style: 'margin:6px 0 12px' }, btn('Nadaj wiadomość', bezpiecznie(async () => {
          await api('mg_wpis_zapisz', { kanal: k.id, tresc: tresc.value });
          await odswiez('Wiadomość nadana.');
        }), '.glowny')),
        h('table', [...k.wpisy].reverse().map((x) => h('tr',
          h('td.slaby', { style: 'white-space:nowrap' }, new Date(x.data).toLocaleString('pl-PL')),
          h('td', x.tresc),
          h('td', btn('Usuń', bezpiecznie(async () => { await api('mg_wpis_usun', { kanal: k.id, id: x.id }); await odswiez('Usunięto wiadomość.'); }), '.zly'))))));
    }),
  );
  // Wiadomości planowane: wydarzenia, które same nadadzą coś w kanałach.
  await panelWydarzen(glowna, { tytul: 'Wiadomości z wydarzeń (nadają się same)', rodzaje: ['kanal'] });
}

// ---------- rekrutacja: pula kandydatów ----------

const WZOR_KANDYDATOW = [
  { imie: 'Orrin Vale', funkcja: 'inżynier górniczy', pensja: 200, opis: 'Twierdzi, że prowadził kopalnię na Kessel.', statystyki: { zarzadzanie: 7, logistyka: 5, lojalnosc: 4 } },
  { imie: 'Nyx', kryptonim: 'WRÓBEL', funkcja: 'pilot', pula: 'agencja', opis: 'Własny frachtowiec, długi u Huttów.', statystyki: { pilotaz: 8, konspiracja: 6, lojalnosc: 3 } },
];

async function rekrutacja() {
  const { kandydaci } = await api('mg_kandydaci');
  const { firmy: lista } = await api('mg_firmy');
  const nazwyFirm = Object.fromEntries(lista.map((f) => [f.id, f.nazwa]));
  const pole2 = h('textarea', { style: 'min-height:320px' }, JSON.stringify(kandydaci.map(({ testy, ...k }) => k), null, 2));
  const info = h('div');
  glowna.append(
    h('div.karta',
      h('h2', 'Pula kandydatów do zatrudnienia'),
      h('p.slaby', 'Kandydaci widoczni w zakładce Załoga firm. „pula”: „ogolna” (firmy i komórki) albo „agencja” (agencja handlowa). '
        + 'Ukryte „statystyki” (1–10): ' + Object.entries(STATYSTYKI).map(([k, n]) => `${k} (${n})`).join(', ') + '. '
        + 'Gracze mogą testować kandydata raz w miesiącu (każda firma osobno) i zatrudnić go — wtedy znika z puli. Nie zmieniaj pola „id”.'),
      info, pole2, h('br'),
      h('div.rzad',
        btn('Zapisz pulę', async () => {
          try {
            await api('mg_kandydaci_zapisz', { kandydaci: JSON.parse(pole2.value) });
            wyczysc(glowna); await rekrutacja();
            glowna.prepend(msg('Zapisano pulę kandydatów.', 'ok'));
          } catch (e) {
            wyczysc(info).append(msg(e instanceof SyntaxError ? 'Błąd w JSON: ' + e.message : e.message));
          }
        }, '.glowny'),
        btn('Wstaw przykład', () => { if (pole2.value.trim() === '[]') pole2.value = JSON.stringify(WZOR_KANDYDATOW, null, 2); }))),
    h('div.karta', h('h2', 'Testy przeprowadzone przez graczy'),
      h('table', h('tr', h('th', 'Kandydat'), h('th', 'Firma'), h('th', 'Data'), h('th', 'Wynik testu'), h('th', 'Prawda')),
        kandydaci.flatMap((k) => Object.entries(k.testy || {}).map(([fid, t]) => h('tr',
          h('td', k.imie), h('td', nazwyFirm[fid] || '?'), h('td', new Date(t.data).toLocaleDateString('pl-PL')),
          h('td', Object.entries(t.wyniki).map(([a, b]) => `${a}: ${b}`).join(', ')),
          h('td.slaby', Object.entries(k.statystyki || {}).map(([a, b]) => `${STATYSTYKI[a] || a} ${b}`).join(', '))))))));
}

// ---------- firmy ----------

const PROFILE_FIRM = { transport: 'Transport', wydobycie: 'Wydobycie', produkcja: 'Produkcja i przemysł', finanse: 'Finanse i bankowość', komorka: 'Komórka', agencja: 'Agencja (przykrywka ISB)' };
const PROFILE_BIZNES = { transport: 'Transport', wydobycie: 'Wydobycie', produkcja: 'Produkcja i przemysł', finanse: 'Finanse i bankowość' };
const ROLE_FIRMY = { wlasciciel: 'właściciel', menedzer: 'menedżer', pracownik: 'pracownik' };
const STATYSTYKI = { pilotaz: 'pilotaż', dowodzenie: 'dowodzenie', taktyka: 'taktyka', zarzadzanie: 'zarządzanie', logistyka: 'logistyka', lojalnosc: 'lojalność', konspiracja: 'konspiracja', handel: 'handel' };
const STATUS_KURSU = { szkic: 'szkic', zgloszony: 'CZEKA NA DECYZJĘ', w_realizacji: 'w trasie', wstrzymany: 'wstrzymany', zakonczony: 'zakończony', odrzucony: 'odrzucony' };
const OPERACJE = { zaladunek: 'załaduj z magazynu', rozladunek: 'rozładuj do magazynu', kup: 'kup', sprzedaj: 'sprzedaj' };

// Dane firmy do edytora: identyfikatory planet, towarów, statków i osób zamienione na nazwy.
function daneDoEdycji(d, kat) {
  const pl = Object.fromEntries(kat.planety.map((p) => [p.id, p.nazwa]));
  const tw = Object.fromEntries(kat.towary.map((t) => [t.id, t.nazwa]));
  const st = Object.fromEntries(d.flota.map((s) => [s.id, s.nazwa]));
  const os = Object.fromEntries(d.zaloga.map((z) => [z.id, z.imie]));
  const bu = Object.fromEntries((d.budynki || []).map((b) => [b.id, b.nazwa]));
  const wynik = {
    flota: d.flota.map(({ przygotowanie, ...s }) => ({ ...s, lokalizacja: pl[s.lokalizacja] || '', kapitan: os[s.kapitan] || '', hangar: LOKACJE[s.hangar] || '' })),
    zaloga: d.zaloga.map((z) => ({ ...z, statek: st[z.statek] || '', budynek: bu[z.budynek] || '' })),
    magazyn: d.magazyn.map((m) => ({ planeta: pl[m.planeta] || m.planeta, towar: tw[m.towar] || m.towar, ilosc: m.ilosc })),
  };
  wynik.budynki = (d.budynki || []).map((b) => ({ ...b, planeta: pl[b.planeta] || '', menedzer: os[b.menedzer] || '' }));
  wynik.pojazdy = (d.pojazdy || []).map((p) => ({ ...p, lokacja: LOKACJE[p.lokacja] || '', kierowca: os[p.kierowca] || '' }));
  wynik.zaklady = (d.zaklady || []).map((z) => ({ ...z, planeta: pl[z.planeta] || '', towar: tw[z.towar] || '' }));
  wynik.zaloga = wynik.zaloga.map((z) => ({ ...z, gracz: z.gracz ? (LOGINY[z.gracz] || z.gracz) : '' }));
  return wynik;
}

const LOGINY = {}; // id gracza -> login (uzupełniane przy wczytaniu zakładki Firmy)
const LOKACJE = {}; // klucz lokacji -> nazwa (uzupełniane przy wczytaniu zakładki Firmy)

const WZOR_AGENCJI = {
  flota: [{ nazwa: 'Szara Mewa', klasa: 'frachtowiec YT-2400', ladownosc: 40, stan: 'sprawny', lokalizacja: 'Zephyria', kapitan: 'Tobb' }],
  zaloga: [
    { imie: 'Tobb', kryptonim: 'KRUK', gracz: 'tobb', funkcja: 'przemytnik', statystyki: { pilotaz: 6, konspiracja: 4, lojalnosc: 3 } },
    { imie: 'Lessa', kryptonim: 'SOWA', funkcja: 'informatorka', statystyki: { konspiracja: 8, lojalnosc: 6 } },
  ],
  magazyn: [],
  budynki: [],
};

const WZOR_KOMORKI = {
  flota: [{ nazwa: 'Cień Zephyrii', klasa: 'korweta CR70', ladownosc: 60, stan: 'sprawny', lokalizacja: 'Zephyria', kapitan: 'Mara Dex' }],
  zaloga: [
    { imie: 'Mara Dex', funkcja: 'pilot', pensja: 0, statystyki: { pilotaz: 8, dowodzenie: 6, taktyka: 5, lojalnosc: 9 } },
    { imie: 'Orrin Vale', funkcja: 'inżynier', budynek: 'Kopalnia Kessa', statystyki: { zarzadzanie: 4, logistyka: 7, lojalnosc: 5 } },
    { imie: 'Sela', funkcja: 'strzelec' },
  ],
  magazyn: [{ planeta: 'Zephyria', towar: 'Ogniwa paliwowe', ilosc: 10 }],
  budynki: [{ nazwa: 'Kopalnia Kessa', typ: 'kopalnia', planeta: 'Zephyria', wspolrzedne: 'Z-04 · 12.7 / 88.1', powierzchnia: 2400, pietra: 2, szyby: 6, pojemnosc: 40, pracownicy: 12, pensja: 15, menedzer: 'Orrin Vale', opis: 'Wydobycie rudy w starych sztolniach.' }],
};

const WZOR_TRANSPORTU = {
  flota: [{ nazwa: 'Szary Wróbel', klasa: 'frachtowiec YT-1300', ladownosc: 100, stan: 'sprawny', lokalizacja: 'Zephyria', hangar: 'Hangar 3', paliwo: 35, kapitan: 'Dax Morrow' }],
  pojazdy: [{ nazwa: 'Spider X-34', typ: 'spider', predkosc: 250, lokacja: 'Biuro firmy', kierowca: 'Dax Morrow' }],
  budynki: [{ nazwa: 'Biuro firmy', typ: 'biuro', planeta: 'Zephyria', x: 8, y: -3, powierzchnia: 300, pietra: 2, opis: 'Biuro z garażem na dwa spidery.' }],
  zaloga: [
    { imie: 'Dax Morrow', funkcja: 'pilot', pensja: 300, statek: 'Szary Wróbel' },
    { imie: 'R5-K2', funkcja: 'droid astromechaniczny', pensja: 0, statek: '' },
  ],
  magazyn: [{ planeta: 'Zephyria', towar: 'Zboże', ilosc: 40 }],
};

const WZOR_WYDOBYCIA = {
  zaloga: [{ imie: 'Tarok Venn', funkcja: 'dyrektor wydobycia', pensja: 0, statystyki: { zarzadzanie: 7, logistyka: 6 } }],
  zaklady: [{ nazwa: 'Pole wydobywcze Kessel', typ: 'kopalnia', planeta: 'Kessel', towar: 'Coaxium surowe', wydajnosc: 120, stan: 'czynny', opis: 'Główne pole wydobywcze spółki.' }],
};
const WZOR_PRODUKCJI = {
  zaloga: [{ imie: 'Jora Pell', funkcja: 'dyrektorka zakładów', pensja: 0, statystyki: { zarzadzanie: 7, logistyka: 6 } }],
  zaklady: [{ nazwa: 'Zakłady główne', typ: 'fabryka', planeta: 'Corellia', towar: 'Moduły droidów', wydajnosc: 80, stan: 'czynny', opis: 'Kompleks produkcyjny.' }],
};
const WZOR_FINANSOW = {
  zaloga: [{ imie: 'Muun Rell', funkcja: 'dyrektor rachunków', pensja: 0, statystyki: { zarzadzanie: 8, logistyka: 7 } }],
  zaklady: [{ nazwa: 'Centrala', typ: 'siedziba', planeta: 'Coruscant', wydajnosc: 0, stan: 'czynny', opis: 'Centrala rozliczeniowa spółki.' }],
};

async function firmy(wrocDoKatalogu = false) {
  const { firmy: lista, katalog: kat } = await api('mg_firmy');
  const { konta } = await api('mg_bank');
  const rachunki = Object.fromEntries(konta.map((k) => [k.uid, k]));
  const { uzytkownicy } = await api('mg_uzytkownicy');
  for (const u of uzytkownicy) LOGINY[u.id] = u.login;
  for (const l of (await api('mg_karty')).lokacje) LOKACJE[l.klucz] = l.nazwa;
  const gracze = uzytkownicy.filter((u) => u.rola !== 'mg');
  const postac = Object.fromEntries(uzytkownicy.map((u) => [u.id, u.postac]));
  const odswiez = async (t) => { wyczysc(glowna); await firmy(wrocDoKatalogu); if (t) glowna.prepend(msg(t, 'ok')); };
  if (wrocDoKatalogu) glowna.append(h('div.karta', btn('← Wróć do Katalogu', () => { wyczysc(glowna); katalog(); })));

  // Kursy czekające na decyzję — na górze, bo to codzienna robota MG.
  const czekajace = lista.flatMap((f) => (f.dane.szlaki || [])
    .filter((s) => s.status === 'zgloszony' || (s.status === 'w_realizacji' && !s.auto)).map((s) => ({ f, s })));
  const automaty = lista.flatMap((f) => (f.dane.szlaki || [])
    .filter((s) => s.auto && ['w_realizacji', 'wstrzymany'].includes(s.status)).map((s) => ({ f, s })));
  if (czekajace.length) {
    const pl = Object.fromEntries(kat.planety.map((p) => [p.id, p.nazwa]));
    const tw = Object.fromEntries(kat.towary.map((t) => [t.id, t.nazwa]));
    glowna.append(h('div.karta', h('h2', `Kursy do obsłużenia (${czekajace.length})`),
      czekajace.map(({ f, s }) => {
        const uwagi = h('input', { placeholder: 'uwagi dla graczy (np. powód odrzucenia)' });
        const statek = f.dane.flota.find((x) => x.id === s.statek)?.nazwa || '?';
        const zmien = (status, pytanie) => bezpiecznie(async () => {
          if (pytanie && !confirm(pytanie)) return;
          await api('mg_szlak_status', { firma: f.id, szlak: s.id, status, uwagi: uwagi.value });
          await odswiez('Zmieniono status kursu.');
        });
        return h('div', { style: 'border-top:1px solid var(--linia);padding:12px 0' },
          h('div', h('b', `${f.nazwa}: ${s.nazwa}`), h('span.slaby', `  · ${statek} · ${STATUS_KURSU[s.status]}${s.auto ? ` · AUTOMAT co ${s.co_dni} dni` : ''} · zgłosił(a) ${s.autor || '?'}`)),
          h('ol', { style: 'margin:6px 0' }, s.przystanki.map((p) => h('li', pl[p.planeta] || '?',
            p.operacje.length ? h('span.slaby', ' — ' + p.operacje.map((o) => `${OPERACJE[o.typ] || o.typ} ${o.ilosc} × ${tw[o.towar] || '?'}`).join(', ')) : null))),
          s.notatka ? h('div.slaby', 'Notatka graczy: ' + s.notatka) : null,
          h('div.rzad', { style: 'margin-top:8px' },
            h('div', { style: 'flex:1;min-width:220px' }, uwagi),
            s.status === 'zgloszony' ? btn(s.auto ? 'Przyjmij (uruchom automat)' : 'Przyjmij (w trasie)', zmien('w_realizacji'), '.glowny') : null,
            s.status === 'w_realizacji' ? btn('Zakończ i rozlicz', zmien('zakonczony', 'Zakończyć kurs? Magazyny i położenie statku zmienią się zgodnie z planem.'), '.glowny') : null,
            btn('Odrzuć', zmien('odrzucony'), '.zly')));
      })));
  }

  // Działające automaty: wyniki ostatnich obiegów, zatrzymanie.
  if (automaty.length) {
    glowna.append(h('div.karta', h('h2', `Kursy automatyczne (${automaty.length})`),
      h('table', h('tr', h('th', 'Firma / kurs'), h('th', 'Status'), h('th', 'Ostatnie obiegi'), h('th', 'Następny'), h('th', '')),
        automaty.map(({ f, s }) => h('tr',
          h('td', h('b', s.nazwa), h('div.slaby', f.nazwa)),
          h('td', s.status === 'wstrzymany' ? h('span', { style: 'color:var(--zly)' }, 'wstrzymany') : 'działa', s.uwagi ? h('div.slaby', s.uwagi) : null),
          h('td', (s.dziennik || []).slice(-4).map((d) => h('div.slaby', `${d.data}: ${d.ok ? (d.zysk >= 0 ? '+' : '') + d.zysk + ' kr' : 'nieudany'}`))),
          h('td', s.status === 'w_realizacji' && s.nastepny ? new Date(s.nastepny).toLocaleDateString('pl-PL') : '—'),
          h('td', btn('Zatrzymaj', bezpiecznie(async () => {
            if (!confirm('Zatrzymać automat? Statek wróci do dyspozycji firmy.')) return;
            await api('mg_szlak_status', { firma: f.id, szlak: s.id, status: 'zakonczony' });
            await odswiez('Automat zatrzymany.');
          }), '.zly')))))));
  }

  // Misje komórek: zatwierdzenie przed sesją, ręczne rozliczenie po sesji.
  const misje = lista.flatMap((f) => (f.dane.misje || [])
    .filter((m) => ['zgloszona', 'zatwierdzona'].includes(m.status)).map((m) => ({ f, m })));
  if (misje.length) {
    glowna.append(h('div.karta', h('h2', `Misje do obsłużenia (${misje.length})`), misje.map(({ f, m }) => kartaMisji(f, m, kat, odswiez))));
  }

  // Teczki agencji: otwarte cele obserwacji, do których MG dopisuje meldunki z terenu.
  const teczki = lista.flatMap((f) => (f.dane.cele || []).filter((c) => c.status === 'aktywny').map((c) => ({ f, c })));
  if (teczki.length) {
    glowna.append(h('div.karta', h('h2', `Teczki w obserwacji (${teczki.length})`), teczki.map(({ f, c }) => {
      const zrodlo = h('input', { placeholder: 'źródło (np. kryptonim agenta)' });
      const tresc = h('textarea', { style: 'min-height:70px;font-family:inherit', placeholder: 'Treść meldunku' });
      return h('div', { style: 'border-top:1px solid var(--linia);padding:12px 0' },
        h('div', h('b', `${f.nazwa}: ${c.nazwa}`), h('span.slaby', `  · założył(a) ${c.autor} · meldunków: ${c.meldunki.length}`)),
        c.opis ? h('div.slaby', c.opis) : null,
        c.meldunki.length ? h('div.slaby', { style: 'margin-top:4px' }, 'Ostatni: ' + c.meldunki[c.meldunki.length - 1].tresc.slice(0, 160)) : null,
        h('div.siatka', { style: 'margin-top:8px' }, pole('Źródło', zrodlo)), tresc,
        h('div', { style: 'margin-top:6px' }, btn('Dopisz meldunek', bezpiecznie(async () => {
          await api('mg_meldunek', { firma: f.id, cel: c.id, zrodlo: zrodlo.value, tresc: tresc.value });
          await odswiez('Meldunek dopisany.');
        }), '.glowny')));
    })));
  }

  // Edytor jednej firmy (nowej albo istniejącej).
  const edytor = (f) => {
    const nowa = !f;
    f = f || { id: '', nazwa: '', profil: 'transport', czlonkowie: [], dane: { flota: [], zaloga: [], magazyn: [], budynki: [], pojazdy: [] } };
    // Nowa firma: zmiana profilu podmienia pusty szablon danych (komórka ma też budynki).
    const pustyDlaProfilu = () => JSON.stringify({ flota: [], zaloga: [], magazyn: [], budynki: [], pojazdy: [], zaklady: [] }, null, 2);
    const nazwa = h('input', { value: f.nazwa });
    const profil = h('select', { disabled: !nowa }, Object.entries(PROFILE_FIRM).map(([k, n]) => h('option', { value: k, selected: k === f.profil }, n)));
    const czlonkowie = h('div');
    const wiersze = [];
    const dodajCzlonka = (c = { uid: gracze[0]?.id, rola: 'pracownik' }) => {
      const kto = h('select', gracze.map((u) => h('option', { value: u.id, selected: u.id === c.uid }, `${u.postac} (${u.login})`)));
      const rola = h('select', Object.entries(ROLE_FIRMY).map(([k, n]) => h('option', { value: k, selected: k === c.rola }, n)));
      const wiersz = h('div.rzad', { style: 'margin-bottom:6px' }, h('div', { style: 'flex:2' }, kto), h('div', { style: 'flex:1' }, rola),
        btn('×', () => { wiersz.remove(); wiersze.splice(wiersze.indexOf(para), 1); }, '.zly'));
      const para = { kto, rola };
      wiersze.push(para);
      czlonkowie.append(wiersz);
    };
    f.czlonkowie.forEach(dodajCzlonka);
    const dane = h('textarea', { style: 'min-height:320px' }, JSON.stringify(daneDoEdycji(f.dane, kat), null, 2));
    if (nowa) profil.addEventListener('change', () => { dane.value = pustyDlaProfilu(profil.value); });
    const info = h('div');
    const zapisz = async () => {
      try {
        await api('mg_firma_zapisz', {
          id: f.id, nazwa: nazwa.value, profil: profil.value,
          czlonkowie: wiersze.map((w) => ({ uid: w.kto.value, rola: w.rola.value })),
          dane: JSON.parse(dane.value),
        });
        await odswiez(`Zapisano firmę ${nazwa.value}.`);
      } catch (e) {
        wyczysc(info).append(msg(e instanceof SyntaxError ? 'Błąd w JSON: ' + e.message : e.message));
      }
    };
    return h('div.karta',
      h('h2', nowa ? 'Nowa firma' : f.nazwa),
      info,
      h('div.siatka', pole('Nazwa', nazwa), pole('Profil', profil)),
      h('label', 'Członkowie (właściciel i menedżer mogą planować kursy i zmieniać przydziały; pracownik tylko podgląda)'),
      czlonkowie,
      btn('+ Członek', () => dodajCzlonka()), h('br'), h('br'),
      h('label', 'Flota, załoga, magazyn, budynki — JSON. Planety, towary, statki, budynki i osoby wpisuj po nazwie. '
        + 'Budynek: nazwa, typ, planeta, wspolrzedne, powierzchnia (m²), pietra (nad ziemią), szyby (poziomy pod ziemią), pojemnosc, pracownicy, pensja, menedzer, opis; '
        + 'x i y (km) robią z niego lokację (typ hangar: jakosc 1–5, paliwo, cena_paliwa). '
        + 'Statek: hangar (nazwa lokacji, w której stoi) i paliwo (% w zbiorniku). Pojazdy (spidery itp.): nazwa, typ, predkosc (km/h), lokacja (gdzie stoi), kierowca (osoba z załogi, opcjonalnie). '
        + 'Stany statku: sprawny, uszkodzony, w naprawie. Stan osoby: zdrowy, ranny, spalony, zatrzymany. '
        + 'Agencja: „kryptonim” kontrahenta i „gracz” (login), jeśli to postać gracza — wtedy wypłaty idą na jego rachunek. '
        + 'Ukryte „statystyki” osób (1–10): ' + Object.entries(STATYSTYKI).map(([k, n]) => `${k} (${n})`).join(', ') + '. Gracze ich nie widzą — poznają je w przybliżeniu testem.', dane),
      h('br'),
      h('div.rzad',
        btn('Zapisz', zapisz, '.glowny'),
        btn('Wstaw przykład', () => { dane.value = JSON.stringify({ komorka: WZOR_KOMORKI, agencja: WZOR_AGENCJI,
          wydobycie: WZOR_WYDOBYCIA, produkcja: WZOR_PRODUKCJI, finanse: WZOR_FINANSOW }[profil.value] || WZOR_TRANSPORTU, null, 2); }),
        nowa || f.spolka_symbol ? null : btn('Usuń firmę', bezpiecznie(async () => {
          if (!confirm(`Usunąć firmę ${f.nazwa} razem z flotą i magazynami?`)) return;
          await api('mg_firma_usun', { id: f.id });
          await odswiez('Usunięto firmę.');
        }), '.zly')));
  };

  glowna.append(h('div.karta',
    h('h2', `Firmy (${lista.length})`),
    lista.length
      ? h('table', h('tr', h('th', 'Nazwa'), h('th', 'Profil'), h('th', 'NPC — menedżer'), h('th', 'Rachunek'), h('th', 'Członkowie'), h('th', 'Statki'), h('th', '')),
        lista.map((f) => {
          const kierownik = f.dane.zaloga?.find((z) => z.npc_id === f.menedzer_id);
          const konto = rachunki['f:' + f.id];
          return h('tr', h('td', h('b', f.nazwa), f.spolka_symbol ? h('div.slaby', f.spolka_symbol) : null), h('td', PROFILE_FIRM[f.profil] || f.profil),
          h('td', kierownik ? `${kierownik.imie} · ${kierownik.funkcja || 'menedżer'}` : '—'),
          h('td', konto ? `${Number(konto.saldo).toLocaleString('pl-PL')} kr` : '—'),
          h('td', f.czlonkowie.map((c) => `${postac[c.uid] || '?'} (${ROLE_FIRMY[c.rola]})`).join(', ') || '—'),
          h('td', f.dane.flota?.length ?? 0),
          h('td', btn('Edytuj', () => { const e = edytor(f); glowna.querySelector('#edytor-firmy').replaceChildren(e); e.scrollIntoView(); })));
        }))
      : h('p.slaby', 'Brak firm. Najpierw uzupełnij katalog (towary, planety), potem załóż firmę.')),
  h('div#edytor-firmy', edytor(null)));
}

function kartaMisji(f, m, kat, odswiez) {
  const pl = Object.fromEntries(kat.planety.map((p) => [p.id, p.nazwa]));
  const statki = f.dane.flota.filter((s) => m.statki.includes(s.id));
  const ludzie = f.dane.zaloga.filter((z) => m.zaloga.includes(z.id));
  const statystyki = (z) => Object.entries(z.statystyki || {}).map(([k, v]) => `${STATYSTYKI[k] || k} ${v}`).join(', ');
  const uwagi = h('input', { placeholder: 'uwagi dla graczy (np. powód odrzucenia)' });
  const zmienStatus = (status, pytanie) => bezpiecznie(async () => {
    if (pytanie && !confirm(pytanie)) return;
    await api('mg_misja_status', { firma: f.id, misja: m.id, status, uwagi: uwagi.value });
    await odswiez('Zmieniono status misji.');
  });

  const naglowek = h('div',
    h('div', h('b', `${f.nazwa}: ${m.nazwa}`), h('span.slaby', `  · ${m.status === 'zgloszona' ? 'CZEKA NA ZATWIERDZENIE' : 'ZATWIERDZONA — do rozliczenia po sesji'} · termin ${m.termin} · ${pl[m.planeta] || 'bez rejonu'} · zgłosił(a) ${m.autor || '?'}`)),
    m.przykrywka ? h('div.slaby', 'Legenda: ' + m.przykrywka) : null,
    h('div', { style: 'white-space:pre-wrap;margin:6px 0' }, m.cel));

  if (m.status === 'zgloszona') {
    return h('div', { style: 'border-top:1px solid var(--linia);padding:12px 0' }, naglowek,
      h('div.slaby', 'Statki: ' + (statki.map((s) => s.nazwa).join(', ') || '—')),
      h('div.slaby', 'Ludzie: ' + (ludzie.map((z) => z.imie + (z.statystyki && Object.keys(z.statystyki).length ? ` [${statystyki(z)}]` : '')).join(', ') || '—')),
      h('div.rzad', { style: 'margin-top:8px' }, h('div', { style: 'flex:1;min-width:220px' }, uwagi),
        btn('Zatwierdź', zmienStatus('zatwierdzona'), '.glowny'), btn('Odrzuć', zmienStatus('odrzucona'), '.zly')));
  }

  // Rozliczenie: wynik dla każdego statku i człowieka, zmiany w magazynach, raport.
  const wybor = (opcje, dom) => h('select', Object.entries(opcje).map(([k, n]) => h('option', { value: k, selected: k === dom }, n)));
  const wynStatkow = Object.fromEntries(statki.map((s) => [s.id, wybor({ sprawny: 'wrócił sprawny', uszkodzony: 'uszkodzony', zniszczony: 'ZNISZCZONY', przejety: 'PRZEJĘTY przez wroga' }, 'sprawny')]));
  const wynLudzi = Object.fromEntries(ludzie.map((z) => [z.id, wybor({ zdrowy: 'cały', ranny: 'ranny', zginal: 'ZGINĄŁ', spalony: 'spalony (zdemaskowany)', zatrzymany: 'zatrzymany' }, 'zdrowy')]));
  const wracaNa = h('select', h('option', { value: '' }, 'tam, skąd wyleciały'), kat.planety.map((p) => h('option', { value: p.id }, p.nazwa)));
  const lupy = h('div');
  const dodajLup = () => {
    const planeta = h('select', kat.planety.map((p) => h('option', { value: p.id }, p.nazwa)));
    const towar = h('select', kat.towary.map((t) => h('option', { value: t.id }, t.nazwa)));
    const ilosc = h('input', { type: 'number', step: 1, placeholder: '+ łup / − strata' });
    const wiersz = h('div.rzad', { style: 'margin-bottom:6px' }, h('div', { style: 'flex:1' }, planeta), h('div', { style: 'flex:1' }, towar), h('div', { style: 'width:140px' }, ilosc), btn('×', () => wiersz.remove(), '.zly'));
    wiersz._dane = () => ({ planeta: planeta.value, towar: towar.value, ilosc: Math.trunc(Number(ilosc.value) || 0) });
    lupy.append(wiersz);
  };
  const raport = h('textarea', { style: 'font-family:inherit', placeholder: 'Raport dla graczy: co się wydarzyło na sesji' });
  const rozlicz = bezpiecznie(async () => {
    const straty = [...Object.values(wynStatkow), ...Object.values(wynLudzi)].filter((s) => ['zniszczony', 'przejety', 'zginal'].includes(s.value)).length;
    if (!confirm(`Rozliczyć misję „${m.nazwa}”?${straty ? ` Trwałe straty: ${straty}.` : ''} Tego nie da się cofnąć.`)) return;
    await api('mg_misja_rozlicz', {
      firma: f.id, misja: m.id, raport: raport.value, wraca_na: wracaNa.value,
      statki: Object.fromEntries(Object.entries(wynStatkow).map(([k, s]) => [k, s.value])),
      zaloga: Object.fromEntries(Object.entries(wynLudzi).map(([k, s]) => [k, s.value])),
      lup: [...lupy.children].map((w) => w._dane()).filter((l) => l.ilosc),
    });
    await odswiez('Misja rozliczona.');
  });

  return h('div', { style: 'border-top:1px solid var(--linia);padding:12px 0' }, naglowek,
    h('table', h('tr', h('th', 'Siły'), h('th', 'Ukryte statystyki'), h('th', 'Wynik')),
      statki.map((s) => h('tr', h('td', s.nazwa, h('span.slaby', ` (${s.klasa || 'statek'})`)), h('td.slaby', '—'), h('td', wynStatkow[s.id]))),
      ludzie.map((z) => h('tr', h('td', z.kryptonim ? `${z.kryptonim} · ` : '', z.imie, h('span.slaby', ` (${z.funkcja || ''})`)), h('td.slaby', statystyki(z) || '—'), h('td', wynLudzi[z.id])))),
    h('br'),
    h('div.siatka', pole('Statki kończą na', wracaNa)),
    h('label', 'Łup i straty w magazynach'), lupy, btn('+ Pozycja', dodajLup), h('br'), h('br'),
    pole('Raport', raport), h('br'),
    h('div.rzad', btn('Rozlicz misję', rozlicz, '.glowny'), h('div', { style: 'flex:1;min-width:200px' }, uwagi), btn('Odwołaj misję', zmienStatus('odrzucona', 'Odwołać misję? Siły wrócą do gotowości.'), '.zly')));
}

// ---------- dokumenty dekodera ----------

async function dokumenty(edytowany = null) {
  const { dokumenty: lista } = await api('mg_dokumenty');
  const e = edytowany || {};
  const f = {
    sygnatura: h('input', { value: e.sygnatura || '', placeholder: 'np. XK-7741' }),
    tytul: h('input', { value: e.tytul || '' }),
    poziom: h('input', { type: 'number', min: 1, max: 9, value: e.poziom || 1 }),
    frakcja: h('select', h('option', { value: '' }, 'dowolna'), FRAKCJE.map((x) => h('option', { selected: e.frakcja === x }, x))),
    tresc: h('textarea', { style: 'min-height:220px;font-family:inherit' }, e.tresc || ''),
    slownik: h('textarea', { style: 'min-height:120px', placeholder: 'duży ptak | dużego ptaka = gwiezdny niszczyciel\ngniazdo | gnieździe = orbita' },
      (e.slownik || []).map((x) => `${x.kody.join(' | ')} = ${x.znaczenie}`).join('\n')),
  };
  const zapisz = bezpiecznie(async () => {
    await api('mg_dokument_zapisz', {
      sygnatura: f.sygnatura.value, tytul: f.tytul.value, poziom: Number(f.poziom.value),
      frakcja: f.frakcja.value, tresc: f.tresc.value, slownik: f.slownik.value, stara: e.sygnatura || '',
    });
    wyczysc(glowna);
    await dokumenty();
    glowna.prepend(msg('Zapisano. Podaj graczowi sygnaturę — wpisze ją w Dekoderze.', 'ok'));
  });
  glowna.append(
    h('div.karta', h('h2', e.sygnatura ? `Edycja: ${e.sygnatura}` : 'Nowy dokument'),
      h('div.siatka', pole('Sygnatura', f.sygnatura), pole('Tytuł', f.tytul), pole('Wymagany poziom', f.poziom), pole('Tylko dla frakcji', f.frakcja)),
      pole('Treść', f.tresc), h('br'),
      pole('Słownik deszyfrujący (opcjonalnie — robi z dokumentu plik deszyfrujący dla kanałów szyfrowanych). Linia: „kod | odmiana | odmiana = znaczenie”.', f.slownik), h('br'),
      h('div.rzad', btn('Zapisz', zapisz, '.glowny'), e.sygnatura ? btn('Anuluj', () => { wyczysc(glowna); dokumenty(); }) : null)),
    h('div.karta.przewijana', h('h2', `Dokumenty (${lista.length})`),
      h('table', h('tr', h('th', 'Sygnatura'), h('th', 'Tytuł'), h('th', 'Poziom'), h('th', 'Frakcja'), h('th', '')),
        lista.map((d) => h('tr', h('td', d.sygnatura), h('td', d.tytul), h('td', d.poziom), h('td', d.frakcja || '—'),
          h('td.rzad',
            btn('Edytuj', () => { wyczysc(glowna); dokumenty(d); }),
            btn('Usuń', bezpiecznie(async () => {
              if (!confirm(`Usunąć ${d.sygnatura}?`)) return;
              await api('mg_dokument_usun', { sygnatura: d.sygnatura });
              wyczysc(glowna); await dokumenty();
            }), '.zly')))))),
  );
}

// ---------- holonet ----------

async function holonet(edytowany = null) {
  const { komunikaty } = await api('holonet');
  const e = edytowany || {};
  const f = {
    tytul: h('input', { value: e.tytul || '' }),
    zrodlo: h('input', { value: e.zrodlo || '', placeholder: 'np. Imperialne Biuro Informacji' }),
    data: h('input', { type: 'date', value: e.data || new Date().toISOString().slice(0, 10) }),
    tresc: h('textarea', { style: 'font-family:inherit' }, e.tresc || ''),
  };
  const zapisz = bezpiecznie(async () => {
    await api('mg_holonet_zapisz', { id: e.id || '', tytul: f.tytul.value, zrodlo: f.zrodlo.value, data: f.data.value, tresc: f.tresc.value });
    wyczysc(glowna); await holonet();
    glowna.prepend(msg('Opublikowano w holonecie.', 'ok'));
  });
  glowna.append(
    h('div.karta', h('h2', e.id ? 'Edycja komunikatu' : 'Nowy komunikat holonetu'),
      h('div.siatka', pole('Tytuł', f.tytul), pole('Źródło', f.zrodlo), pole('Data', f.data)),
      pole('Treść', f.tresc), h('br'),
      h('div.rzad', btn('Opublikuj', zapisz, '.glowny'), e.id ? btn('Anuluj', () => { wyczysc(glowna); holonet(); }) : null)),
    h('div.karta', h('h2', `Komunikaty (${komunikaty.length})`),
      h('table', komunikaty.map((k) => h('tr', h('td', k.data), h('td', k.tytul, h('div.slaby', k.zrodlo || '')),
        h('td.rzad',
          btn('Edytuj', () => { wyczysc(glowna); holonet(k); }),
          btn('Usuń', bezpiecznie(async () => {
            if (!confirm('Usunąć komunikat?')) return;
            await api('mg_holonet_usun', { id: k.id });
            wyczysc(glowna); await holonet();
          }), '.zly')))))),
  );
  await panelWydarzen(glowna, { tytul: 'Komunikaty z wydarzeń (publikują się same)', rodzaje: ['holonet'] });
}

// ---------- giełda i wydarzenia ----------

// Wykres podglądu: historia (szara), prognoza z wydarzeniami (kolor akcentu), bez wydarzeń (przerywana).
function wykresPodgladu(historia, prognoza, bez) {
  const ns = 'http://www.w3.org/2000/svg';
  const W = 600, H = 200;
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
  svg.style.cssText = 'width:100%;height:220px;display:block';
  const wszystko = [...historia, ...prognoza, ...bez];
  const min = Math.min(...wszystko), max = Math.max(...wszystko), zakres = max - min || 1;
  const n = historia.length + prognoza.length;
  const x = (i) => (i / (n - 1)) * W;
  const y = (c) => H - 10 - ((c - min) / zakres) * (H - 20);
  const linia = (ceny, od, kolor, kreska = '') => {
    const p = document.createElementNS(ns, 'polyline');
    p.setAttribute('points', ceny.map((c, i) => `${x(od + i).toFixed(1)},${y(c).toFixed(1)}`).join(' '));
    p.setAttribute('fill', 'none');
    p.setAttribute('stroke', kolor);
    p.setAttribute('stroke-width', '2');
    if (kreska) p.setAttribute('stroke-dasharray', kreska);
    svg.append(p);
  };
  const dzis = document.createElementNS(ns, 'line');
  for (const [k, v] of Object.entries({ x1: x(historia.length - 1), x2: x(historia.length - 1), y1: 0, y2: H, stroke: 'currentColor', 'stroke-opacity': 0.25 })) dzis.setAttribute(k, v);
  svg.append(dzis);
  linia(historia, 0, 'var(--slaby)');
  linia([historia[historia.length - 1], ...bez], historia.length - 1, 'var(--slaby)', '5 5');
  linia([historia[historia.length - 1], ...prognoza], historia.length - 1, 'var(--akcent)');
  return h('div', svg, h('div.rzad.slaby', h('span', `min ${min.toFixed(2)}`), h('span', { style: 'flex:1;text-align:center' }, '▏dziś'), h('span', `max ${max.toFixed(2)}`)));
}

async function gielda() {
  const { gielda: g, kursy, portfele } = await api('mg_gielda');
  const nazwyGield = Object.fromEntries(g.gieldy.map((x) => [x.id, x.nazwa]));
  const gieldy = h('textarea', { style: 'min-height:150px' }, JSON.stringify(g.gieldy, null, 2));
  const instrumenty = h('textarea', { style: 'min-height:320px' }, JSON.stringify(g.instrumenty, null, 2));
  const ust = Object.fromEntries(Object.entries(g.ustawienia).map(([k, v]) => [k, h('input', { type: 'number', step: 'any', value: v })]));
  const info = h('div');

  // Podgląd: wybrany instrument, historia i prognoza po skrypcie z wydarzeniami.
  const symbol = h('select', g.instrumenty.map((i) => h('option', { value: i.symbol }, `${i.symbol} — ${i.nazwa}`)));
  const dni = h('input', { type: 'number', min: 7, max: 180, value: 60, style: 'max-width:110px' });
  const podglad = h('div', h('p.slaby', 'Ładowanie…'));
  let szkic = null, zegar = null;
  const rysujPodglad = () => {
    clearTimeout(zegar);
    zegar = setTimeout(async () => {
      try {
        const p = await api('mg_gielda_podglad', { symbol: symbol.value, dni: Number(dni.value), szkic });
        const ost = p.prognoza[p.prognoza.length - 1], bez = p.bez_wydarzen[p.bez_wydarzen.length - 1];
        wyczysc(podglad).append(wykresPodgladu(p.historia, p.prognoza, p.bez_wydarzen),
          h('p.slaby', `Dziś ${p.historia[p.historia.length - 1]} kr → za ${dni.value} dni ${ost} kr z wydarzeniami${szkic ? ' (ze szkicem z formularza)' : ''}, ${bez} kr bez wydarzeń. `
            + 'Linia przerywana: zwykłe wahania. Losowość jest stała dla dnia i symbolu, więc prognoza się spełni, jeśli nie zmienisz wydarzeń.'));
      } catch (e) {
        wyczysc(podglad).append(msg(e.message));
      }
    }, 250);
  };
  symbol.addEventListener('change', rysujPodglad);
  dni.addEventListener('input', rysujPodglad);

  glowna.append(
    h('div.karta', h('h2', 'Podgląd kursu'),
      h('div.rzad', { style: 'margin-bottom:10px' }, h('div', { style: 'flex:1' }, symbol), dni, h('span.slaby', 'dni prognozy')),
      podglad),
    h('div.karta', h('h2', 'Kursy dnia'),
      h('div.przewijana', h('table',
        h('tr', h('th', 'Symbol'), h('th', 'Nazwa'), h('th', 'Kategoria'), h('th', 'Giełda'), h('th', 'Cena bazowa'), h('th', 'Kurs'), h('th', 'Od bazy')),
        g.instrumenty.map((i) => {
          const k = kursy[i.symbol] ?? i.cena;
          const r = (k / i.cena - 1) * 100;
          return h('tr', h('td', h('b', i.symbol)), h('td', i.nazwa, h('div.slaby', i.rodzaj === 'spolka'
            ? ((i.notowana ?? true) ? 'spółka · notowana' : 'spółka · poza giełdą') : `towar · ${i.jednostka}`)), h('td', i.kategoria),
            h('td', nazwyGield[i.gielda] || i.gielda), h('td', i.cena), h('td', k), h('td', { style: `color:${r >= 0 ? 'var(--akcent)' : 'var(--zly)'}` }, `${r >= 0 ? '+' : ''}${r.toFixed(1)}%`));
        })))),
    h('div.karta', h('h2', 'Portfele graczy'),
      portfele.length
        ? h('table', portfele.map((p) => h('tr', h('td', h('b', p.postac)), h('td', Object.entries(p.pozycje).map(([s, x]) => `${s} × ${x.ilosc}`).join(', ')), h('td', `${p.wartosc.toLocaleString('pl-PL')} kr`))))
        : h('p.slaby', 'Nikt jeszcze nic nie kupił.')),
    h('div.karta', h('h2', 'Edytor giełdy'),
      info,
      h('p.slaby', 'Giełda: id (2–12 małych liter), nazwa, planeta. Towary dodawaj w Katalogu — pojawią się tu automatycznie. Spółki i ich status notowania ustawiaj w Katalogu. Instrument: symbol (2–6 znaków), nazwa, rodzaj (spolka albo towar), kategoria (towary: te same co w katalogu, np. paliwo, surowce, żywność, medycyna, technika, broń, luksus, przyprawy; '
        + 'spółki: branża, np. stocznie, uzbrojenie, droidy), gielda (id), cena (bazowa — wokół niej błądzi kurs), jednostka, pochodzenie (planeta: wydarzenia na niej działają na ten instrument), '
        + 'zmiennosc (opcjonalnie, dzienna, np. 0.03). Kursy liczą się co dzień same; nowy instrument dostaje historię od razu.'),
      h('div.siatka', ...Object.entries({ powrot: 'Powrót do ceny bazowej (dziennie)', zmiennosc_spolka: 'Wahania spółek (dzienne)', zmiennosc_towar: 'Wahania towarów (dzienne)', prowizja: 'Prowizja (np. 0.005 = 0,5%)', historia_dni: 'Dni historii dla nowych' })
        .map(([k, n]) => pole(n, ust[k]))),
      pole('Giełdy', gieldy), h('br'),
      pole('Instrumenty', instrumenty), h('br'),
      h('div.rzad',
        btn('Zapisz giełdę', bezpiecznie(async () => {
          try {
            await api('mg_gielda_zapisz', { gieldy: JSON.parse(gieldy.value), instrumenty: JSON.parse(instrumenty.value), ustawienia: Object.fromEntries(Object.entries(ust).map(([k, el]) => [k, Number(el.value)])) });
          } catch (e) {
            wyczysc(info).append(msg(e instanceof SyntaxError ? 'Błąd w JSON: ' + e.message : e.message));
            return;
          }
          wyczysc(glowna); await gielda();
          glowna.prepend(msg('Zapisano giełdę.', 'ok'));
        }), '.glowny'),
        btn('Przelicz całą historię', bezpiecznie(async () => {
          if (!confirm('Wygenerować całą historię kursów od nowa? Portfele graczy zostaną, ale wykresy się zmienią.')) return;
          await api('mg_gielda_przelicz', {});
          wyczysc(glowna); await gielda();
          glowna.prepend(msg('Historia przeliczona.', 'ok'));
        }), '.zly'))),
  );
  await panelWydarzen(glowna, {
    tytul: 'Wydarzenia rynkowe',
    podglad: (s) => { szkic = s; rysujPodglad(); },
    poZapisie: rysujPodglad,
  });
  rysujPodglad();
}

async function wydarzenia() {
  await panelWydarzen(glowna, { tytul: 'Wydarzenia świata gry' });
}

// ---------- frakcje ----------

const WZOR_FRAKCJI = {
  planety: [{ nazwa: 'Zephyria', opis: 'Opis planety', instalacje: [{ nazwa: 'Kantyna', typ: 'punkt kontaktowy', stan: 'aktywna' }] }],
  zasoby: [{ nazwa: 'Ogniwa paliwowe', miejsce: 'Hangar 3', ilosc: 12, jednostka: 'szt.' }],
  kadry: [{ imie: 'Dowódca', ranga: 'Pułkownik', funkcja: 'Dowodzenie', przelozony: '' }, { imie: 'Zastępca', ranga: 'Major', przelozony: 'Dowódca' }],
};

async function frakcje() {
  const { frakcje: wszystkie } = await api('mg_frakcje');
  for (const nazwa of FRAKCJE) {
    const pole = h('textarea', { style: 'min-height:280px' }, JSON.stringify(wszystkie[nazwa], null, 2));
    const info = h('div');
    glowna.append(h('div.karta',
      h('h2', nazwa),
      h('p.slaby', 'Dane w formacie JSON: listy „planety", „zasoby" i „kadry". W kadrach „przelozony" to imię innej osoby z listy — z tego powstaje drzewo dowodzenia.'),
      info, pole, h('br'),
      h('div.rzad',
        btn('Zapisz', async () => {
          try {
            const dane = JSON.parse(pole.value);
            await api('mg_frakcja_zapisz', { frakcja: nazwa, dane });
            wyczysc(info).append(msg('Zapisano.', 'ok'));
          } catch (e) {
            wyczysc(info).append(msg(e instanceof SyntaxError ? 'Błąd w JSON: ' + e.message : e.message));
          }
        }, '.glowny'),
        btn('Wstaw przykład', () => { pole.value = JSON.stringify(WZOR_FRAKCJI, null, 2); }))));
  }
}

// ---------- harmonogram ----------

async function harmonogram() {
  const { wpisy } = await api('harmonogram');
  wpisy.sort((a, b) => a.data.localeCompare(b.data) || a.postac.localeCompare(b.postac));
  const csv = () => {
    const wiersze = [['data', 'postac', 'status', 'notatka'], ...wpisy.map((w) => [w.data, w.postac, w.status, w.notatka])];
    const tekst = '﻿' + wiersze.map((r) => r.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(';')).join('\r\n');
    const a = h('a', { href: URL.createObjectURL(new Blob([tekst], { type: 'text/csv' })), download: 'harmonogram.csv' });
    a.click();
  };
  glowna.append(h('div.karta.przewijana',
    h('div.rzad', h('h2', { style: 'flex:1' }, `Harmonogram (${wpisy.length})`), btn('Eksport do Excela (CSV)', csv)),
    h('table', h('tr', h('th', 'Data'), h('th', 'Postać'), h('th', 'Status'), h('th', 'Notatka'), h('th', '')),
      wpisy.map((w) => h('tr', h('td', w.data), h('td', w.postac), h('td', w.status), h('td', w.notatka || ''),
        h('td', btn('Usuń', bezpiecznie(async () => {
          await api('harmonogram_usun', { id: w.id });
          wyczysc(glowna); await harmonogram();
        }), '.zly')))))));
}

start();

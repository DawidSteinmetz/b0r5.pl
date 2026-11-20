// Zakładka „Grafiki lokacji” panelu MG: biblioteka grafik (assets/wnetrza) z podglądem, wgrywanie własnych,
// przypisania do pomieszczeń okrętów i lokacji na planetach oraz reguły po nazwie („Winda” → wszystkie windy).
// Gracz widzi przypisaną grafikę jako tło pulpitu, gdy jego postać jest w tym miejscu (api/sceny.php: grafika_miejsca).

import { h, wyczysc } from '../js/rdzen/narzedzia.js';
import { api } from '../js/rdzen/api.js';

const msg = (t, rodzaj = 'blad') => h('div.msg.' + rodzaj, t);
const btn = (tekst, fn, klasa = '') => h('button.btn' + klasa, { type: 'button', onclick: fn }, tekst);
const pole = (etykieta, el) => h('label', etykieta, el);
const url = (plik) => `../${plik}`;

export async function panelGrafik(kontener) {
  const [dane, karty] = await Promise.all([api('mg_grafiki'), api('mg_karty')]);
  let lista = dane.lista || [];
  const przyp = { ...(dane.przypisania || {}) };
  let reguly = (dane.reguly || []).map((r) => ({ ...r }));
  let wybrana = lista[0]?.plik || null;
  let zmiany = false;
  const info = h('div');
  const nazwaPliku = (p) => lista.find((x) => x.plik === p)?.nazwa || p?.split('/').pop() || '';
  const oznacz = () => { zmiany = true; zapiszBtn.textContent = 'Zapisz zmiany *'; };

  // Grafika dla miejsca: przypisanie albo pierwsza pasująca reguła (tak samo jak na serwerze).
  const zReguly = (nazwa) => reguly.find((r) => (nazwa || '').toLowerCase().includes(r.tekst));
  const grafikaDla = (klucz, nazwa) => {
    if (przyp[klucz]) return przyp[klucz] === 'brak' ? { brak: true } : { plik: przyp[klucz], wlasna: true };
    const r = zReguly(nazwa);
    return r ? { plik: r.plik, regula: r.tekst } : null;
  };

  // ---------- biblioteka ----------
  const podglad = h('div.gr-podglad');
  const siatka = h('div.gr-siatka');
  function rysujBiblioteke() {
    wyczysc(siatka).append(...lista.map((x) => h('button.gr-kafel' + (x.plik === wybrana ? '.on' : ''), { type: 'button', title: x.plik, onclick: () => { wybrana = x.plik; rysujBiblioteke(); } },
      h('img', { src: url(x.plik), alt: x.nazwa, loading: 'lazy' }), h('span', x.nazwa))));
    wyczysc(podglad).append(wybrana ? h('div', h('img', { src: url(wybrana), alt: '' }),
      h('div.rzad', h('b', nazwaPliku(wybrana)), h('span.slaby', wybrana))) : h('p.slaby', 'Brak grafik w bibliotece.'));
  }
  const plikWej = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/webp', style: 'max-width:280px' });
  plikWej.addEventListener('change', () => {
    const f = plikWej.files?.[0];
    if (!f) return;
    if (f.size > 3 * 1024 * 1024) { wyczysc(info).append(msg('Plik większy niż 3 MB.')); return; }
    const r = new FileReader();
    r.onload = async () => {
      try {
        const w = await api('mg_grafika_wgraj', { nazwa: f.name.replace(/\.[^.]+$/, ''), dane: r.result });
        lista = [...lista, { plik: w.plik, nazwa: f.name.replace(/\.[^.]+$/, ''), reguly: [], wlasna: true }];
        wybrana = w.plik; rysujBiblioteke();
        wyczysc(info).append(msg('Wgrano grafikę — jest w bibliotece i zaznaczona.', 'ok'));
      } catch (e) { wyczysc(info).append(msg(e.message)); }
      plikWej.value = '';
    };
    r.readAsDataURL(f);
  });

  // ---------- wiersz miejsca: miniatura, źródło, przyciski ----------
  function wiersz(klucz, nazwa, dopisek, odswiez) {
    const g = grafikaDla(klucz, nazwa);
    return h('tr',
      h('td', h('div', nazwa), dopisek ? h('div.slaby', dopisek) : null),
      h('td.gr-mini-td', g?.plik ? h('img.gr-mini', { src: url(g.plik), alt: '', title: nazwaPliku(g.plik), onclick: () => { wybrana = g.plik; rysujBiblioteke(); } }) : h('span.slaby', g?.brak ? 'bez grafiki' : '—')),
      h('td.slaby', g?.plik ? (g.regula ? `z reguły „${g.regula}”` : 'przypisana') : g?.brak ? 'wyłączona' : 'tło rysowane'),
      h('td', h('div.rzad',
        btn('Przypisz zaznaczoną', () => { if (!wybrana) return; przyp[klucz] = wybrana; oznacz(); odswiez(); }, '.glowny'),
        przyp[klucz] ? btn('Usuń przypisanie', () => { delete przyp[klucz]; oznacz(); odswiez(); }) : null,
        przyp[klucz] !== 'brak' ? btn('Bez grafiki', () => { przyp[klucz] = 'brak'; oznacz(); odswiez(); }) : null)));
  }

  // ---------- pomieszczenia okrętów ----------
  const okrety = (karty.lokacje || []).filter((l) => l.klucz.startsWith('okret:'));
  const wyborOkretu = h('select', h('option', { value: '' }, '— wybierz okręt —'), okrety.map((o) => h('option', { value: o.klucz.slice(6) }, `${o.nazwa.replace(/^Pokład: /, '')}${o.planeta && o.planeta !== 'przestrzeń' ? ` · ${o.planeta}` : ''}`)));
  const filtrPom = h('input', { placeholder: 'filtr: np. winda, mesa, reaktor…' });
  const tabelaPom = h('div.przewijana');
  let pomieszczenia = [];
  async function wczytajPom() {
    pomieszczenia = [];
    if (wyborOkretu.value) {
      try { pomieszczenia = (await api('mg_wnetrze', undefined, { okret: wyborOkretu.value })).pomieszczenia || []; } catch (e) { wyczysc(info).append(msg(e.message)); }
    }
    rysujPom();
  }
  function rysujPom() {
    const f = filtrPom.value.trim().toLowerCase();
    const lista0 = pomieszczenia.filter((p) => !f || p.nazwa.toLowerCase().includes(f) || (p.grupa || '').toLowerCase().includes(f));
    wyczysc(tabelaPom).append(!wyborOkretu.value ? h('p.slaby', 'Wybierz okręt, żeby zobaczyć jego pomieszczenia.')
      : !pomieszczenia.length ? h('p.slaby', 'Ten okręt nie ma planu wnętrza.')
        : h('table', h('tr', h('th', 'Pomieszczenie'), h('th', 'Grafika'), h('th', 'Źródło'), h('th', '')),
          lista0.slice(0, 300).map((p) => wiersz(`sql:${p.id}`, p.nazwa, p.grupa, rysujPom))));
  }
  wyborOkretu.addEventListener('change', wczytajPom);
  filtrPom.addEventListener('input', rysujPom);

  // ---------- lokacje na planetach ----------
  const lokacje = (karty.lokacje || []).filter((l) => !l.klucz.startsWith('okret:') && !l.klucz.startsWith('flota:'));
  const planety = [...new Set(lokacje.map((l) => l.planeta))].sort((a, b) => a.localeCompare(b, 'pl'));
  const wyborPlanety = h('select', h('option', { value: '' }, '— wybierz planetę —'), planety.map((p) => h('option', { value: p }, p)));
  const tabelaLok = h('div.przewijana');
  function rysujLok() {
    const l = lokacje.filter((x) => x.planeta === wyborPlanety.value);
    wyczysc(tabelaLok).append(!wyborPlanety.value ? h('p.slaby', 'Wybierz planetę.')
      : h('table', h('tr', h('th', 'Lokacja'), h('th', 'Grafika'), h('th', 'Źródło'), h('th', '')), l.map((x) => wiersz(x.klucz, x.nazwa, x.typ, rysujLok))));
  }
  wyborPlanety.addEventListener('change', rysujLok);

  // ---------- reguły po nazwie ----------
  const tabelaReg = h('div');
  function rysujReg() {
    wyczysc(tabelaReg).append(
      reguly.length ? h('table', h('tr', h('th', 'Fragment nazwy'), h('th', 'Grafika'), h('th', '')), reguly.map((r, i) => {
        const tekst = h('input', { value: r.tekst });
        tekst.addEventListener('input', () => { r.tekst = tekst.value.trim().toLowerCase(); oznacz(); });
        return h('tr', h('td', tekst),
          h('td', h('div.rzad', h('img.gr-mini', { src: url(r.plik), alt: '', onclick: () => { wybrana = r.plik; rysujBiblioteke(); } }), h('span.slaby', nazwaPliku(r.plik)))),
          h('td', h('div.rzad',
            btn('↑', () => { if (i) { [reguly[i - 1], reguly[i]] = [reguly[i], reguly[i - 1]]; oznacz(); rysujReg(); } }),
            btn('Zaznaczona grafika', () => { if (wybrana) { r.plik = wybrana; oznacz(); rysujReg(); } }),
            btn('×', () => { reguly.splice(i, 1); oznacz(); rysujReg(); rysujPom(); }, '.zly'))));
      })) : h('p.slaby', 'Brak reguł. Reguła przypisuje grafikę każdemu miejscu, którego nazwa zawiera dany fragment (np. „wind”).'),
      h('div.rzad', { style: 'margin-top:8px' },
        btn('+ Reguła dla zaznaczonej grafiki', () => { if (!wybrana) return; reguly.push({ tekst: nazwaPliku(wybrana).toLowerCase().split(/[ /]/)[0], plik: wybrana }); oznacz(); rysujReg(); }),
        btn('Wczytaj domyślne reguły ISD', () => {
          const istniejace = new Set(reguly.map((r) => r.tekst));
          for (const g of lista) for (const t of g.reguly || []) if (!istniejace.has(t)) { reguly.push({ tekst: t, plik: g.plik }); istniejace.add(t); }
          oznacz(); rysujReg(); rysujPom(); rysujLok();
        })));
  }

  const zapiszBtn = btn('Zapisz zmiany', async () => {
    try {
      await api('mg_grafiki_zapisz', { przypisania: przyp, reguly: reguly.filter((r) => r.tekst) });
      zmiany = false; zapiszBtn.textContent = 'Zapisz zmiany';
      wyczysc(info).append(msg('Zapisano. Tablety pokażą grafiki przy najbliższym odświeżeniu sceny (do kilku sekund).', 'ok'));
    } catch (e) { wyczysc(info).append(msg(e.message)); }
  }, '.glowny');

  kontener.append(
    h('div.karta', h('h2', 'Grafiki lokacji'),
      h('p.slaby', 'Zaznacz grafikę w bibliotece, potem przypisz ją do pomieszczenia okrętu, lokacji albo reguły po nazwie. '
        + 'Gracz widzi ją jako tło pulpitu, gdy jego postać tam jest (w przejściu między pomieszczeniami — reguła „korytarz”). '
        + 'Przypisanie do konkretnego miejsca wygrywa z regułą; „Bez grafiki” wyłącza też reguły.'),
      h('div.rzad', zapiszBtn, h('span.slaby', 'Wgraj własną (PNG/JPG/WebP do 3 MB):'), plikWej), info),
    h('div.gr-uklad',
      h('div.karta', h('h2', 'Biblioteka'), siatka),
      h('div.karta.gr-podglad-karta', h('h2', 'Podgląd'), podglad)),
    h('div.karta', h('h2', 'Reguły po nazwie'), tabelaReg),
    h('div.karta', h('h2', 'Pomieszczenia okrętów'), h('div.siatka', pole('Okręt', wyborOkretu), pole('Filtr', filtrPom)), tabelaPom),
    h('div.karta', h('h2', 'Lokacje na planetach'), h('div.siatka', pole('Planeta', wyborPlanety)), tabelaLok));
  rysujBiblioteke(); rysujReg(); rysujPom(); rysujLok();
  window.addEventListener('beforeunload', (e) => { if (zmiany) e.preventDefault(); });
}

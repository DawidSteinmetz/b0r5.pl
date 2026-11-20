// Zakładka „Generator postaci” panelu MG: karta postaci gracza i NPC w systemie Star Wars: Edge of the Empire.
// Gracz: kreator z rachunkiem PD (gatunek → kariera → specjalizacja → cechy, umiejętności, talenty) i ostrzeżeniami
// o regułach — MG może je świadomie złamać. NPC: sługus (grupa), rywal, nemezis; szablony i „Losuj NPC”.
// Obok formularza podgląd CV na żywo (ten sam widok, który gracz widzi w Karcie postaci). Reguły i dane: js/rdzen/eote.js.

import { h, wyczysc } from '../js/rdzen/narzedzia.js';
import { api } from '../js/rdzen/api.js';
import * as E from '../js/rdzen/eote.js';

const msg = (t, rodzaj = 'blad') => h('div.msg.' + rodzaj, t);
const btn = (tekst, fn, klasa = '') => h('button.btn' + klasa, { type: 'button', onclick: fn }, tekst);
const pole = (etykieta, el) => h('label', etykieta, el);
const opcje = (lista, wartosc) => lista.map(([v, t]) => h('option', { value: v, selected: String(v) === String(wartosc) }, t));
const kopia = (x) => JSON.parse(JSON.stringify(x));

export async function panelGeneratora(kontener) {
  const { postacie, npc = [] } = await api('mg_karty');
  let e = E.nowaKarta('gracz');
  let cel = ''; // 'u:<uid>' — gracz, 'n:<id>' — NPC, '' — nowa karta (zapis jako nowy NPC)
  const info = h('div');
  const formularz = h('div.gen-formularz');
  const podglad = h('div.karta.gen-podglad');
  const rachunek = h('div.gen-rachunek');

  const wyborCelu = h('select', h('option', { value: '' }, '— nowa karta (zapis jako nowy NPC) —'),
    h('optgroup', { label: 'Postacie graczy' }, postacie.map((p) => h('option', { value: `u:${p.uid}` }, p.postac))),
    npc.length ? h('optgroup', { label: 'NPC' }, npc.map((n) => h('option', { value: `n:${n.id}` }, `${n.nazwa}${n.rola ? ` · ${n.rola}` : ''}`))) : null);
  wyborCelu.addEventListener('change', async () => {
    cel = wyborCelu.value;
    wyczysc(info);
    if (!cel) { e = E.nowaKarta('rywal'); return przebuduj(); }
    try {
      const [rodzaj, id] = [cel.slice(0, 1), cel.slice(2)];
      const r = await api('mg_eote', undefined, rodzaj === 'u' ? { uid: id } : { osoba: id });
      if (r.eote) e = r.eote;
      else if (rodzaj === 'u') { e = E.nowaKarta('gracz'); e.imie = postacie.find((p) => p.uid === id)?.postac || ''; }
      else { const n = npc.find((x) => String(x.id) === id); e = E.nowaKarta('rywal'); e.imie = n?.nazwa || ''; e.rola = n?.rola || ''; }
      przebuduj();
    } catch (err) { info.append(msg(err.message)); }
  });

  // Odświeżenie podglądu i rachunku PD bez przebudowy formularza (zachowuje fokus pola).
  function odswiez() {
    wyczysc(podglad).append(h('div.slaby', 'Podgląd CV (tak zobaczy je gracz)'), E.rysujCV(h, e, { mg: true }));
    wyczysc(rachunek);
    if (e.tryb !== 'gracz') return;
    const r = E.rachunekPD(e);
    rachunek.append(h('div', h('b', `PD: zostało ${r.zostalo}`), ` · startowe ${r.start}${r.dodatkowe ? ` + ${r.dodatkowe} (zobowiązanie)` : ''}${r.zdobyte ? ` + ${r.zdobyte} zdobyte` : ''} · wydane ${r.wydane}`
      + (r.pozycje.length ? ` (${r.pozycje.map(([n, v]) => `${n} ${v}`).join(', ')})` : '')),
    r.uwagi.length ? h('ul.gen-uwagi', r.uwagi.map((u) => h('li', u))) : h('div.slaby', 'Zgodnie z zasadami tworzenia postaci.'));
  }

  // Pola powiązane z kartą: zmiana zapisuje wartość; „przebuduj” — gdy zmienia się układ formularza.
  const wiaz = (el, get, set, { liczba = false, przebudowa = false } = {}) => {
    if (el.type === 'checkbox') el.checked = !!get(); else el.value = get() ?? '';
    el.addEventListener(el.tagName === 'SELECT' || el.type === 'checkbox' ? 'change' : 'input', () => {
      set(el.type === 'checkbox' ? el.checked : liczba ? (el.value === '' ? null : Number(el.value)) : el.value);
      przebudowa ? przebuduj() : odswiez();
    });
    return el;
  };
  const tekst = (get, set, attrs = {}) => wiaz(h('input', attrs), get, set);
  const liczba = (get, set, attrs = {}) => wiaz(h('input', { type: 'number', ...attrs }), get, set, { liczba: true });
  const obszar = (get, set, wys = 70) => wiaz(h('textarea', { style: `min-height:${wys}px` }), get, set);

  function tozsamosc() {
    const gracz = e.tryb === 'gracz';
    const tryb = wiaz(h('select', opcje(Object.entries(E.TRYBY), e.tryb)), () => e.tryb, (v) => {
      e.tryb = v;
      if (v === 'gracz') { e.kariera ||= 'przemytnik'; if (!e.specjalizacje?.length) e.specjalizacje = [E.KARIERY[e.kariera].specjalizacje[0]]; e.zobowiazanie ||= E.nowaKarta().zobowiazanie; }
    }, { przebudowa: true });
    const gatunek = wiaz(h('select', opcje(Object.entries(E.GATUNKI).map(([k, g]) => [k, `${g.nazwa}${gracz ? ` (${g.pd} PD)` : ''}`]), e.gatunek)),
      () => e.gatunek, (v) => { e.gatunek = v; if (gracz) e.cechy = E.cechyGatunku(v); }, { przebudowa: true });
    const imie = tekst(() => e.imie, (v) => { e.imie = v; });
    const elementy = [
      pole('Imię i nazwisko', h('div.rzad', imie, btn('Losuj', () => { e.imie = E.losujImie(e.gatunek); imie.value = e.imie; odswiez(); }))),
      pole('Rodzaj karty', tryb), pole('Gatunek', gatunek),
    ];
    if (gracz) {
      const kariera = wiaz(h('select', opcje(Object.entries(E.KARIERY).map(([k, x]) => [k, x.nazwa]), e.kariera)), () => e.kariera, (v) => {
        e.kariera = v;
        e.specjalizacje = [E.KARIERY[v].specjalizacje[0], ...(e.specjalizacje || []).slice(1).filter((s) => s !== E.KARIERY[v].specjalizacje[0])];
      }, { przebudowa: true });
      const spec = wiaz(h('select', opcje(E.KARIERY[e.kariera].specjalizacje.map((s) => [s, E.SPECJALIZACJE[s].nazwa]), e.specjalizacje?.[0])),
        () => e.specjalizacje?.[0], (v) => { e.specjalizacje = [v, ...(e.specjalizacje || []).slice(1).filter((s) => s !== v)]; }, { przebudowa: true });
      const dodatkowe = h('div.gen-checki', Object.entries(E.SPECJALIZACJE).filter(([k]) => k !== e.specjalizacje?.[0]).map(([k, s]) => {
        const cb = h('input', { type: 'checkbox', checked: (e.specjalizacje || []).includes(k), style: 'width:auto' });
        cb.addEventListener('change', () => {
          e.specjalizacje = cb.checked ? [...e.specjalizacje, k] : e.specjalizacje.filter((x) => x !== k);
          przebuduj();
        });
        return h('label.gen-check', cb, ` ${s.nazwa}${s.kariera !== e.kariera ? ' (spoza kariery)' : ''}`);
      }));
      elementy.push(pole('Kariera', kariera), pole('Specjalizacja startowa', spec),
        h('details', h('summary.slaby', `Dodatkowe specjalizacje (kolejna: ${10 * ((e.specjalizacje || []).length + 1)} PD w karierze, +10 spoza)`), dodatkowe));
    } else {
      elementy.push(pole('Rola / zajęcie', tekst(() => e.rola, (v) => { e.rola = v; })));
      if (e.tryb === 'slugus') elementy.push(pole('Liczebność grupy', liczba(() => e.grupa, (v) => { e.grupa = Math.max(1, v || 1); }, { min: 1, max: 12 })));
    }
    elementy.push(
      pole('Świat pochodzenia', tekst(() => e.planeta, (v) => { e.planeta = v; })),
      pole('Wiek', tekst(() => e.wyglad.wiek, (v) => { e.wyglad.wiek = v; })),
      pole('Płeć', tekst(() => e.wyglad.plec, (v) => { e.wyglad.plec = v; })),
      pole('Wzrost', tekst(() => e.wyglad.wzrost, (v) => { e.wyglad.wzrost = v; })));
    const opisGatunku = E.GATUNKI[e.gatunek]?.opis;
    return h('div.karta', h('h2', 'Tożsamość'), h('div.siatka', elementy), opisGatunku ? h('p.slaby', opisGatunku) : null);
  }

  function szablony() {
    if (e.tryb === 'gracz') return null;
    const s = h('select', opcje(Object.entries(E.SZABLONY).map(([k, x]) => [k, `${x.nazwa} (${E.TRYBY[x.tryb]})`]), e.szablon));
    const zachowaj = (n) => { n.historia = e.historia; n.notatki_mg = e.notatki_mg; if (cel && e.imie) n.imie = e.imie; return n; };
    return h('div.karta', h('h2', 'Szablon NPC'),
      h('div.rzad', h('div', { style: 'flex:1' }, s),
        btn('Wczytaj szablon', () => { e = zachowaj(E.zSzablonu(s.value, false)); przebuduj(); }),
        btn('Losuj NPC', () => { const k = Object.keys(E.SZABLONY); s.value = k[Math.floor(Math.random() * k.length)]; e = E.zSzablonu(s.value, true); przebuduj(); }, '.glowny')),
      h('p.slaby', 'Szablon nadpisuje cechy, umiejętności, talenty i sprzęt. „Losuj NPC” dobiera szablon, imię i lekki rozrzut wartości.'));
  }

  function cechy() {
    const baza = E.cechyGatunku(e.gatunek);
    return h('div.karta', h('h2', 'Cechy'),
      h('div.gen-cechy', E.CECHY.map(([k, n]) => h('label', `${n}${e.tryb === 'gracz' ? ` (gatunek ${baza[k]})` : ''}`,
        liczba(() => e.cechy[k], (v) => { e.cechy[k] = Math.max(1, Math.min(6, v || 1)); }, { min: 1, max: 6 })))),
      e.tryb === 'gracz' ? h('p.slaby', 'Podniesienie cechy kosztuje 10 × nowa wartość PD i jest możliwe tylko przy tworzeniu (najwyżej 5).') : null);
  }

  function umiejetnosci() {
    const gracz = e.tryb === 'gracz', slugus = e.tryb === 'slugus';
    const kar = E.umiejetnosciKariery(e);
    const karieraSet = new Set(E.KARIERY[e.kariera]?.umiejetnosci || []);
    const specSet = new Set((e.specjalizacje || []).flatMap((s) => E.SPECJALIZACJE[s]?.umiejetnosci || []));
    const g = E.GATUNKI[e.gatunek] || {};
    const lim = E.limityDarmowych(e);
    const u = (k) => (e.umiejetnosci[k] ||= {});
    const sprzataj = (k) => { const x = e.umiejetnosci[k]; if (x && !x.ranga && !x.wK && !x.wS && !x.wD && !x.wG) delete e.umiejetnosci[k]; };
    const cb = (k, pole0, tytul, dostepne) => (dostepne ? h('td', wiaz(h('input', { type: 'checkbox', title: tytul, style: 'width:auto' }), () => e.umiejetnosci[k]?.[pole0], (v) => {
      u(k)[pole0] = v; if (v && (u(k).ranga || 0) < E.darmoweRangi(e, k)) u(k).ranga = E.darmoweRangi(e, k); sprzataj(k);
    }, { przebudowa: true })) : h('td'));
    const naglowek = gracz ? [h('th', 'Umiejętność'), h('th', 'Ranga'), h('th', { title: 'darmowa ranga kariery' }, `K (${lim.kariera})`),
      h('th', { title: 'darmowa ranga specjalizacji' }, `S (${lim.specjalizacja})`), lim.dowolne ? h('th', { title: 'premia gatunku: spoza kariery' }, `G (${lim.dowolne})`) : null,
      g.wybor ? h('th', { title: 'premia gatunku do wyboru' }, 'Gat.') : null, h('th', 'Koszt nast.')]
      : [h('th', 'Umiejętność'), h('th', slugus ? 'Posiada' : 'Ranga')];
    const wiersze = E.UMIEJETNOSCI.map(([k, n, c]) => {
      const r = E.ranga(e, k);
      const nazwa = h('td', n, h('span.slaby', ` (${E.CECHY.find(([x]) => x === c)[2]})`), gracz && kar.has(k) ? h('b', { title: 'umiejętność kariery' }, ' ◆') : null,
        g.umiejetnosc === k ? h('span.slaby', ' · gatunek +1') : null);
      if (slugus) return h('tr', nazwa, h('td', wiaz(h('input', { type: 'checkbox', style: 'width:auto' }), () => !!e.umiejetnosci[k]?.ranga, (v) => { if (v) u(k).ranga = 1; else delete e.umiejetnosci[k]; })));
      const ranga = liczba(() => r || '', (v) => { u(k).ranga = Math.max(0, Math.min(5, v || 0)); sprzataj(k); }, { min: 0, max: 5, style: 'max-width:70px' });
      if (!gracz) return h('tr', nazwa, h('td', ranga));
      const nast = r < 5 ? E.kosztRangi(r + 1, kar.has(k)) : '—';
      return h('tr', nazwa, h('td', ranga), cb(k, 'wK', 'darmowa ranga kariery', karieraSet.has(k)), cb(k, 'wS', 'darmowa ranga specjalizacji', specSet.has(k)),
        lim.dowolne ? cb(k, 'wD', 'premia gatunku (spoza kariery)', !kar.has(k)) : null,
        g.wybor ? cb(k, 'wG', 'premia gatunku do wyboru', g.wybor.includes(k)) : null, h('td.slaby', String(nast)));
    });
    return h('div.karta', h('h2', 'Umiejętności'),
      gracz ? h('p.slaby', 'Ranga to łączna liczba rang (z darmowymi). Ranga kariery kosztuje 5 × nowa ranga PD, spoza kariery +5. Przy tworzeniu najwyżej 2.') : null,
      slugus ? h('p.slaby', 'Sługusy mają umiejętności grupowo: ranga = liczebność grupy − 1.') : null,
      h('div.przewijana', h('table', h('tr', naglowek), wiersze)));
  }

  function talenty() {
    const lista = h('datalist#gen-talenty', E.TALENTY.map(([n]) => h('option', { value: n })));
    const wiersze = (e.talenty || []).map((t, i) => h('tr',
      h('td', wiaz(h('input', { list: 'gen-talenty' }), () => t.nazwa, (v) => { t.nazwa = v; if (E.TALENT[v]) { t.poziom = E.TALENT[v].poziom; t.opis ||= E.TALENT[v].opis; } })),
      h('td', liczba(() => t.ranga, (v) => { t.ranga = Math.max(1, v || 1); }, { min: 1, max: 5, style: 'max-width:60px' })),
      h('td', liczba(() => t.poziom, (v) => { t.poziom = Math.max(0, Math.min(5, v || 0)); }, { min: 0, max: 5, style: 'max-width:60px' })),
      h('td', tekst(() => t.opis, (v) => { t.opis = v; })),
      h('td', btn('×', () => { e.talenty.splice(i, 1); przebuduj(); }, '.zly'))));
    return h('div.karta', h('h2', 'Talenty'), lista,
      e.tryb === 'gracz' ? h('p.slaby', 'Koszt: 5 × poziom w drzewku (rząd 1 = 5 PD, rząd 5 = 25 PD) za każdą rangę. Talent z gatunku jest darmowy.') : null,
      wiersze.length ? h('table', h('tr', h('th', 'Talent'), h('th', 'Ranga'), h('th', 'Poziom'), h('th', 'Działanie'), h('th', '')), wiersze) : null,
      btn('+ Talent', () => { (e.talenty ||= []).push({ nazwa: '', ranga: 1, poziom: 1, opis: '' }); przebuduj(); }));
  }

  function pochodneISprzet() {
    const auto = E.pochodne({ ...e, pochodne: {} });
    const nadpis = (k, n) => (auto[k] === null && k === 'wysilek' ? null : pole(`${n} (auto ${auto[k]})`,
      liczba(() => e.pochodne?.[k], (v) => { (e.pochodne ||= {})[k] = v; }, { placeholder: 'auto' })));
    const walka = E.UMIEJETNOSCI.filter(([, , , g]) => g === 'walka');
    const bronie = (e.bronie || []).map((b, i) => h('tr',
      h('td', tekst(() => b.nazwa, (v) => { b.nazwa = v; })),
      h('td', wiaz(h('select', opcje(walka.map(([k, n]) => [k, n]), b.umiejetnosc)), () => b.umiejetnosc, (v) => { b.umiejetnosc = v; })),
      h('td', liczba(() => b.obr, (v) => { b.obr = v; }, { style: 'max-width:60px' })),
      h('td', liczba(() => b.kryt, (v) => { b.kryt = v; }, { style: 'max-width:60px' })),
      h('td', wiaz(h('select', opcje(E.ZASIEGI.map((z) => [z, z]), b.zasieg)), () => b.zasieg, (v) => { b.zasieg = v; })),
      h('td', tekst(() => b.cechy, (v) => { b.cechy = v; })),
      h('td', btn('×', () => { e.bronie.splice(i, 1); przebuduj(); }, '.zly'))));
    return [
      h('div.karta', h('h2', 'Progi, pancerz i obrona'),
        h('p.slaby', 'Liczone automatycznie z gatunku, cech, talentów i zbroi. Wpisz wartość, żeby ją nadpisać; puste = auto.'),
        h('div.siatka', nadpis('rany', e.tryb === 'slugus' ? 'Rany na sługusa' : 'Próg ran'), nadpis('wysilek', 'Próg wysiłku'),
          nadpis('pancerz', 'Pancerz'), nadpis('obrona_w', 'Obrona wręcz'), nadpis('obrona_d', 'Obrona dystans'))),
      h('div.karta', h('h2', 'Sprzęt'),
        bronie.length ? h('div.przewijana', h('table', h('tr', h('th', 'Broń'), h('th', 'Umiejętność'), h('th', 'Obr.'), h('th', 'Kryt.'), h('th', 'Zasięg'), h('th', 'Cechy'), h('th', '')), bronie)) : null,
        btn('+ Broń', () => { (e.bronie ||= []).push({ nazwa: '', umiejetnosc: 'bron_lekka', obr: 6, kryt: 3, zasieg: 'średni', cechy: '' }); przebuduj(); }),
        h('div.siatka', { style: 'margin-top:10px' },
          pole('Zbroja / ubranie', tekst(() => e.pancerz?.nazwa, (v) => { (e.pancerz ||= {}).nazwa = v; })),
          pole('Pancerz zbroi', liczba(() => e.pancerz?.pancerz, (v) => { (e.pancerz ||= {}).pancerz = v || 0; }, { min: 0 })),
          pole('Obrona zbroi', liczba(() => e.pancerz?.obrona, (v) => { (e.pancerz ||= {}).obrona = v || 0; }, { min: 0 })),
          e.tryb !== 'slugus' ? pole('Kredyty', liczba(() => e.kredyty, (v) => { e.kredyty = v || 0; }, { min: 0 })) : null),
        pole('Ekwipunek', obszar(() => e.ekwipunek, (v) => { e.ekwipunek = v; }, 50))),
    ];
  }

  function zobowiazanie() {
    if (e.tryb !== 'gracz') return null;
    const z = (e.zobowiazanie ||= E.nowaKarta().zobowiazanie);
    const m = (e.motywacja ||= E.nowaKarta().motywacja);
    const DODATKI = [['0:0', 'brak'], ['5:0', '+5 PD (zobowiązanie +5)'], ['10:0', '+10 PD (zobowiązanie +10)'],
      ['0:1000', '+1000 kr (zobowiązanie +5)'], ['0:2500', '+2500 kr (zobowiązanie +10)']];
    const dod = wiaz(h('select', opcje(DODATKI, `${z.dodatki?.pd || 0}:${z.dodatki?.kr || 0}`)), () => `${z.dodatki?.pd || 0}:${z.dodatki?.kr || 0}`, (v) => {
      const [pd, kr] = v.split(':').map(Number);
      const stare = z.dodatki || { pd: 0, kr: 0 };
      z.wartosc = Number(z.wartosc || 0) - (stare.pd === 10 || stare.kr === 2500 ? 10 : stare.pd || stare.kr ? 5 : 0) + (pd === 10 || kr === 2500 ? 10 : pd || kr ? 5 : 0);
      e.kredyty = Number(e.kredyty || 0) - Number(stare.kr || 0) + kr;
      z.dodatki = { pd, kr };
    }, { przebudowa: true });
    return h('div.karta', h('h2', 'Zobowiązanie, motywacja, doświadczenie'),
      h('div.siatka',
        pole('Zobowiązanie', wiaz(h('select', opcje(E.ZOBOWIAZANIA.map((x) => [x, x]), z.typ)), () => z.typ, (v) => { z.typ = v; })),
        pole('Wartość', liczba(() => z.wartosc, (v) => { z.wartosc = v || 0; }, { min: 0 })),
        pole('Dodatkowe zobowiązanie przy tworzeniu', dod),
        pole('Motywacja', wiaz(h('select', opcje(Object.keys(E.MOTYWACJE).map((x) => [x, x]), m.typ)), () => m.typ, (v) => { m.typ = v; m.szczegol = E.MOTYWACJE[v][0]; }, { przebudowa: true })),
        pole('Szczegół motywacji', wiaz(h('select', opcje(E.MOTYWACJE[m.typ].map((x) => [x, x]), m.szczegol)), () => m.szczegol, (v) => { m.szczegol = v; })),
        pole('PD zdobyte w grze', liczba(() => e.pd?.zdobyte, (v) => { (e.pd ||= {}).zdobyte = v || 0; }, { min: 0 }))),
      pole('Zobowiązanie — wobec kogo i za co', tekst(() => z.opis, (v) => { z.opis = v; })),
      pole('Motywacja — konkretnie', tekst(() => m.opis, (v) => { m.opis = v; })));
  }

  function historia() {
    const H = (e.historia ||= {});
    return h('div.karta', h('h2', 'Historia (backstory)'),
      h('div.rzad', btn('Szkic historii', () => {
        const s = E.szkicHistorii(e);
        for (const [k, v] of Object.entries(s)) if (!H[k] && v) H[k] = v;
        przebuduj();
      }), h('span.slaby', 'Wypełnia tylko puste pola prostymi propozycjami — dopisz resztę.')),
      pole('Wygląd', obszar(() => e.wyglad.opis, (v) => { e.wyglad.opis = v; }, 50)),
      pole('Pochodzenie', obszar(() => H.pochodzenie, (v) => { H.pochodzenie = v; })),
      pole('Dotychczasowe losy', obszar(() => H.historia, (v) => { H.historia = v; }, 110)),
      pole('Charakter', obszar(() => H.charakter, (v) => { H.charakter = v; })),
      pole('Cele', obszar(() => H.cele, (v) => { H.cele = v; })),
      pole('Znajomości', obszar(() => H.kontakty, (v) => { H.kontakty = v; })),
      pole('Notatki MG (gracz ich nie widzi)', obszar(() => e.notatki_mg, (v) => { e.notatki_mg = v; })));
  }

  async function zapisz(jakoNowy = false) {
    wyczysc(info);
    try {
      const dane = { eote: e };
      if (!jakoNowy && cel.startsWith('u:')) dane.uid = cel.slice(2);
      else if (!jakoNowy && cel.startsWith('n:')) dane.osoba_id = Number(cel.slice(2));
      const r = await api('mg_eote_zapisz', dane);
      if (r.nowy) {
        const o = h('option', { value: `n:${r.osoba_id}` }, `${e.imie}${e.rola ? ` · ${e.rola}` : ''}`);
        let grupa = wyborCelu.querySelector('optgroup[label="NPC"]');
        if (!grupa) { grupa = h('optgroup', { label: 'NPC' }); wyborCelu.append(grupa); }
        grupa.append(o);
        wyborCelu.value = cel = `n:${r.osoba_id}`;
      }
      info.append(msg(r.uid ? 'Zapisano kartę gracza — widać ją w jego Karcie postaci (zakładka CV).' : r.nowy ? `Zapisano nowego NPC: ${e.imie}.` : 'Zapisano kartę NPC.', 'ok'));
    } catch (err) { info.append(msg(err.message)); }
  }

  function przebuduj() {
    const przewiniecie = kontener.closest('main')?.scrollTop ?? window.scrollY;
    wyczysc(formularz).append(tozsamosc(), szablony(), cechy(), umiejetnosci(), talenty(), ...pochodneISprzet(), zobowiazanie(), historia());
    odswiez();
    window.scrollTo({ top: przewiniecie });
  }

  kontener.append(
    h('div.karta', h('h2', 'Generator postaci (Edge of the Empire)'),
      h('div.rzad',
        h('div', { style: 'flex:2;min-width:240px' }, pole('Karta', wyborCelu)),
        btn('Zapisz', () => zapisz(false), '.glowny'),
        btn('Zapisz jako nowy NPC', () => zapisz(true)),
        btn('Wyczyść', () => { e = E.nowaKarta(cel.startsWith('u:') ? 'gracz' : 'rywal'); przebuduj(); })),
      h('p.slaby', 'Gracz widzi swoje CV w Karcie postaci (bez notatek MG). Karty NPC wymagają migracji 040.'),
      rachunek, info),
    h('div.gen-siatka', formularz, podglad));
  przebuduj();
}

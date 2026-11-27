// Droid śledczy: aplikacja detektywa (konto wpisane w sledztwo_detektywi; MG nadaje rolę w zakładce Konta).
// Bieżąca sprawa (zgłoszenie, zaczyn), ekran DT-4 (skan piętra i ruch droida w czasie 1:1), dziennik, archiwum.
// Projekt: narzedzia/sledztwa/PROJEKT.md (rozdz. 4 i 5). API: api/sledztwa.php — sledztwo_moje, sledztwo_skan,
// sledztwo_idz, sledztwo_nowe. Gracz nigdy nie dostaje prawdy o sprawie ani informacji, który lokal jest fabularny.

import { h, wyczysc } from '../rdzen/narzedzia.js';
import { api } from '../rdzen/api.js';

const STAN = {
  losowanie: 'Biuro przyjmuje zgłoszenie…',
  pisanie: 'Biuro kompletuje akta sprawy.',
  trwa: 'Śledztwo w toku.',
  porzucone: 'Sprawa porzucona.',
  wznowienie_prosba: 'Prośba o wznowienie czeka na decyzję.',
  rozwiazane: 'Sprawa zamknięta.',
  blad: 'Akta sprawy uszkodzone — biuro je odtwarza.',
};
const STAN_DROIDA = { czeka: 'czeka na polecenia', w_drodze: 'w drodze', rozmowa: 'prowadzi rozmowę', skan: 'skanuje otoczenie',
  dekodowanie: 'dekoduje dane', uszkodzony: 'uszkodzony' };
const KTO = { droid: 'Droid', gracz: 'Ty', system: 'System' };

const data = (t) => (t ? new Date(String(t).replace(' ', 'T') + 'Z').toLocaleString('pl-PL', { dateStyle: 'short', timeStyle: 'short' }) : '');
const godz = (t) => (t ? new Date(String(t).replace(' ', 'T') + 'Z').toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }) : '');
const czas = (s) => { s = Math.max(0, Math.round(s)); const g = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), r = s % 60;
  return g ? `${g} h ${m} min` : m ? `${m} min ${String(r).padStart(2, '0')} s` : `${r} s`; };

// SVG: h() tworzy tylko elementy HTML.
const NS = 'http://www.w3.org/2000/svg';
function s(tag, atr = {}, ...dzieci) {
  const el = document.createElementNS(NS, tag);
  for (const [k, v] of Object.entries(atr)) {
    if (v === null || v === undefined || v === false) continue;
    if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
    else el.setAttribute(k, v);
  }
  for (const d of dzieci.flat()) if (d !== null && d !== undefined && d !== false) el.append(d instanceof Node ? d : document.createTextNode(String(d)));
  return el;
}

// Geometria planu (jednostki skanu; pokój = 60 j.).
const P = 60, GLEB = 50, KOR = 30, WINDA = 40;
const yPokoju = (strona) => (strona === 'gora' ? 0 : GLEB + KOR);

export default {
  id: 'sledztwa',
  nazwa: 'Droid śledczy',
  ikona: '<circle cx="20" cy="20" r="11"/><path d="M28 28l12 12"/><path d="M15 17h10M15 23h6"/>',
  // Ikona tylko dla detektywów (stan.detektyw ustawia pulpit po zapytaniu sledztwo_moje).
  dostep: (stan) => !!stan.detektyw,

  uruchom(w) {
    const tresc = h('div');
    const komunikat = h('div');
    const ekran = h('div');        // ekran DT-4 (odświeżany częściej niż reszta)
    let zegar = null, zegarSkanu = null, licznik = null, koniec = false, maSprawe = false;
    const panel = (tytul, ...dzieci) => h('div.panel', h('h2', tytul), ...dzieci);
    const blad = (e) => h('div.komunikat.blad', e.message || String(e));
    const wiersz = (etykieta, wartosc) => h('tr', h('td.slaby', etykieta), h('td', wartosc ?? '—'));

    async function nowaSprawa(przycisk) {
      przycisk.disabled = true;
      try {
        await api('sledztwo_nowe', {});
        wyczysc(komunikat).append(h('div.komunikat.ok', 'Zgłoszenie przyjęte. Biuro kompletuje akta.'));
        await odswiez();
      } catch (e) { wyczysc(komunikat).append(blad(e)); przycisk.disabled = false; }
    }

    async function idz(lokalizacja_id, poziom, pokoj) {
      try {
        await api('sledztwo_idz', { lokalizacja_id, poziom, pokoj });
        wyczysc(komunikat);
        await skanuj();
        odswiez();
      } catch (e) { wyczysc(komunikat).append(blad(e)); }
    }

    function sprawa(x) {
      return panel(`SPRAWA #${x.id}`,
        h('table.tabela',
          wiersz('Tytuł', x.tytul),
          wiersz('Zgłoszenie', x.zgloszenie),
          wiersz('Rejon', x.skupisko ? `${x.skupisko}, Nar Shaddaa` : 'Nar Shaddaa'),
          wiersz('Przyjęto', data(x.utworzono_utc)),
          wiersz('Stan', STAN[x.stan] || x.stan)),
        x.zaczyn ? h('div', { style: 'white-space:pre-wrap;margin-top:10px' }, x.zaczyn)
          : h('p.maly-tekst', 'Akta wstępne (zlecenie, tło, ludzie, od czego zacząć) pojawią się tutaj, gdy biuro je skompletuje.'));
    }

    // ---------- ekran DT-4: skan przestrzenny piętra ----------

    function planPietra(d) {
      const { plan, droid, kontakty, zasieg } = d;
      const szer = Math.max(plan.szerokosc, P * 3);
      const wys = GLEB * 2 + KOR;
      const wDrodze = droid.stan === 'w_drodze';
      const svg = s('svg', { viewBox: `${-WINDA - 10} -14 ${szer + WINDA + 20} ${wys + 28}`, width: '100%',
        style: `max-width:${(szer + WINDA + 20) * 1.6}px;display:block;color:var(--akcent);font-family:var(--czcionka)` });
      // Zasięg skanu.
      if (!wDrodze) {
        svg.append(s('rect', { x: droid.x - zasieg, y: -10, width: zasieg * 2, height: wys + 20, fill: 'currentColor', 'fill-opacity': 0.05,
          stroke: 'currentColor', 'stroke-opacity': 0.35, 'stroke-dasharray': '4 4' }));
      }
      // Winda i korytarz.
      svg.append(
        s('rect', { x: -WINDA, y: GLEB, width: WINDA, height: KOR, fill: 'none', stroke: 'currentColor', 'stroke-opacity': 0.7 }),
        s('text', { x: -WINDA / 2, y: GLEB + KOR / 2 + 3, 'text-anchor': 'middle', 'font-size': 8, fill: 'currentColor' }, 'WINDA'),
        s('line', { x1: 0, y1: GLEB, x2: szer, y2: GLEB, stroke: 'currentColor', 'stroke-opacity': 0.4 }),
        s('line', { x1: 0, y1: GLEB + KOR, x2: szer, y2: GLEB + KOR, stroke: 'currentColor', 'stroke-opacity': 0.4 }));
      if (plan.terminal_korytarz) {
        svg.append(s('rect', { x: szer - 14, y: GLEB + 9, width: 10, height: 12, fill: 'none', stroke: 'var(--akcent-2)' }),
          s('text', { x: szer - 9, y: GLEB + 6, 'text-anchor': 'middle', 'font-size': 7, fill: 'var(--akcent-2)' }, plan.terminal_korytarz));
      }
      // Pokoje: klik = rozkaz wejścia (ten sam budynek i poziom).
      for (const p of plan.pokoje) {
        const y = yPokoju(p.strona);
        const w = (p.szer || 1) * P;
        const sx = p.x + w / 2;
        const tu = !wDrodze && droid.pokoj === p.nr;
        const cel = wDrodze && droid.cel && droid.cel.pokoj === p.nr && droid.cel.poziom === plan.poziom && droid.cel.budynek_id === plan.budynek_id;
        const wstep = p.zamkniete ? ' · zamknięty' : p.dostep === 'nakaz' ? ' · tylko z nakazem' : p.dostep === 'straz' ? ' · strzeżony' : '';
        const ramka = p.zamkniete ? 'var(--zly)' : p.dostep === 'nakaz' ? 'var(--zly)' : p.dostep === 'straz' ? 'var(--akcent-2)' : 'currentColor';
        const g = s('g', { style: 'cursor:pointer', onclick: () => idz(plan.budynek_id, plan.poziom, p.nr) },
          s('title', {}, `Lokal ${p.nr} · ${p.rodzaj}${wstep} — kliknij, by tam wysłać droida`),
          s('rect', { x: p.x + 1, y: y + 1, width: w - 2, height: GLEB - 2, fill: tu || cel ? 'currentColor' : 'transparent',
            'fill-opacity': tu ? 0.16 : 0.08, stroke: ramka, 'stroke-opacity': p.zamkniete ? 0.5 : 0.8,
            'stroke-dasharray': cel ? '3 2' : null }),
          s('text', { x: p.x + 5, y: y + 12, 'font-size': 9, fill: 'currentColor' }, p.nr),
          s('text', { x: p.x + 5, y: y + 22, 'font-size': 6, fill: 'currentColor', 'fill-opacity': 0.65 }, p.rodzaj.slice(0, 14 * (p.szer || 1))),
          p.dostep !== 'wolny' ? s('text', { x: p.x + w - 6, y: y + GLEB - 6, 'text-anchor': 'end', 'font-size': 7, fill: ramka },
            p.dostep === 'nakaz' ? 'NAKAZ' : 'STRAŻ') : null,
          // Drzwi od strony korytarza z numerem.
          s('line', { x1: sx - 8, y1: p.strona === 'gora' ? GLEB : GLEB + KOR, x2: sx + 8, y2: p.strona === 'gora' ? GLEB : GLEB + KOR,
            stroke: p.zamkniete ? 'var(--zly)' : 'var(--tlo)', 'stroke-width': 3 }),
          s('text', { x: sx, y: p.strona === 'gora' ? GLEB - 3 : GLEB + KOR + 9, 'text-anchor': 'middle', 'font-size': 6,
            fill: 'currentColor', 'fill-opacity': 0.6 }, String(p.drzwi)),
          p.terminal ? s('rect', { x: p.x + w - 14, y: y + 6, width: 9, height: 11, fill: 'none', stroke: 'var(--akcent-2)' }) : null,
          p.terminal ? s('text', { x: p.x + w - 9.5, y: y + 27, 'text-anchor': 'middle', 'font-size': 6, fill: 'var(--akcent-2)' }, p.terminal) : null);
        svg.append(g);
      }
      // Kontakty w zasięgu: klik = droid podchodzi na 2–3 m i zaczyna rozmowę. Strażnicy stoją na korytarzu przy drzwiach.
      for (const k of kontakty) {
        const y = k.strona === 'korytarz' ? (k.przy === 'gora' ? GLEB + 8 : GLEB + KOR - 8) : yPokoju(k.strona) + 38;
        const aktywny = d.rozmowa && d.rozmowa.kontakt === k.token;
        svg.append(s('g', { style: 'cursor:pointer', onclick: (e) => { e.stopPropagation(); zacznijRozmowe(k.token); } },
          s('title', {}, `${k.kod}: ${k.gatunek}, ${k.wyglad} — kliknij, by droid zaczął rozmowę`),
          s('circle', { cx: k.x, cy: y, r: 7, fill: 'transparent' }),
          aktywny ? s('circle', { cx: k.x, cy: y, r: 6, fill: 'none', stroke: 'var(--ok)' }) : null,
          s('circle', { cx: k.x, cy: y, r: 3.5, fill: 'var(--akcent-2)' }),
          s('text', { x: k.x + 5, y: y + 3, 'font-size': 6, fill: 'var(--akcent-2)' }, k.kod)));
      }
      // Droid.
      if (!wDrodze) {
        const dy = droid.pokoj === 'korytarz' ? GLEB + KOR / 2 : yPokoju(plan.pokoje.find((p) => p.nr === droid.pokoj)?.strona) + GLEB / 2;
        svg.append(s('g', {},
          s('circle', { cx: droid.x, cy: dy, r: 5, fill: 'none', stroke: 'var(--ok)', 'stroke-width': 1.5 }),
          s('circle', { cx: droid.x, cy: dy, r: 1.8, fill: 'var(--ok)' })));
      }
      return h('div', { style: 'overflow-x:auto;margin:8px 0' }, svg);
    }

    function sterowanie(d) {
      const { plan, budynki, poziom_max } = d;
      const budynek = h('select', budynki.length ? budynki.map((b) => h('option', { value: b.id, selected: b.id === plan.budynek_id }, b.nazwa))
        : [h('option', { value: '' }, 'Kompleks')]);
      const poziom = h('input', { type: 'number', min: 1, max: poziom_max, value: plan.poziom, style: 'width:80px' });
      const lokal = h('input', { placeholder: 'korytarz albo nr lokalu', style: 'width:170px' });
      const jedz = h('button.przycisk', 'Wyślij droida');
      jedz.onclick = () => idz(budynek.value === '' ? null : Number(budynek.value), Number(poziom.value), lokal.value.trim() || 'korytarz');
      return h('div', { style: 'display:flex;flex-wrap:wrap;gap:8px;align-items:center' },
        budynek, h('span.slaby', 'poziom'), poziom, lokal, jedz);
    }

    function pasekOdczytu(d) {
      const ostatni = (d.meldunki || []).find((m) => m.kto === 'droid');
      const { droid } = d;
      const lacze = droid.stan === 'w_drodze'
        ? `w drodze · ${droid.cel.budynek}, poziom ${droid.cel.poziom}, ${droid.cel.pokoj === 'korytarz' ? 'korytarz' : `lokal ${droid.cel.pokoj}`}`
        : `łącze stabilne · ${droid.budynek}, poziom ${droid.poziom}, ${droid.pokoj === 'korytarz' ? 'korytarz' : `lokal ${droid.pokoj}`}`;
      return h('div', { style: 'border-top:1px solid var(--linia);padding-top:6px;font-size:13px' },
        h('div', h('b', 'DT-4 › '), ostatni ? `${godz(ostatni.kiedy_utc)} ${ostatni.tresc}` : 'Gotowy.'),
        h('div.slaby', h('b', 'ŁĄCZE › '), lacze));
    }

    // Wstęp: strzeżone lokale w zasięgu (podejścia do strażnika) i nakazy (wniosek do biura, decyzja MG).
    const PODEJSCIA = [['oszustwo', 'Blef (Oszustwo)'], ['urok', 'Urok'], ['zastraszanie', 'Zastraszanie']];
    async function przekonaj(pokoj, podejscie) {
      try {
        const r = await api('sledztwo_straz', { pokoj, podejscie });
        wyczysc(komunikat).append(h(`div.komunikat.${r.udana ? 'ok' : 'blad'}`,
          r.juz ? 'Droid ma już wstęp — wchodzi.' : `${r.udana ? 'Strażnik wpuszcza droida.' : 'Strażnik odmawia.'} ${r.opis}`));
        await skanuj();
        odswiez();
      } catch (e) { wyczysc(komunikat).append(blad(e)); }
    }
    async function wniosek(lokalizacja_id, gdzie) {
      const uzasadnienie = window.prompt(`Wniosek o nakaz: ${gdzie}.\nUzasadnienie dla biura (co najmniej 10 znaków):`);
      if (!uzasadnienie) return;
      try {
        await api('sledztwo_nakaz_wniosek', { lokalizacja_id, uzasadnienie });
        wyczysc(komunikat).append(h('div.komunikat.ok', 'Wniosek złożony. Decyzja biura pojawi się w dzienniku.'));
        await skanuj();
        odswiez();
      } catch (e) { wyczysc(komunikat).append(blad(e)); }
    }
    function wstep(d) {
      const { plan, droid, zasieg, nakazy = [] } = d;
      const wZasiegu = (p) => Math.abs(p.x + (p.szer || 1) * P / 2 - droid.x) <= zasieg;
      const strzezone = droid.stan === 'w_drodze' ? [] : plan.pokoje.filter((p) => p.dostep === 'straz' && !p.zamkniete && wZasiegu(p));
      const nakazowe = plan.pokoje.filter((p) => p.dostep === 'nakaz');
      const STAN_N = { wniosek: 'czeka na decyzję', wydany: 'wydany', odrzucony: 'odrzucony' };
      const tenBudynek = nakazy.find((n) => n.lokalizacja_id === plan.budynek_id && n.stan !== 'odrzucony');
      return h('div', { style: 'margin:8px 0' },
        strzezone.map((p) => h('div', { style: 'display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:4px 0' },
          h('span', `Lokal ${p.nr} (${p.rodzaj}) — strzeżony. Przekonaj strażnika:`),
          ...PODEJSCIA.map(([k, n]) => { const b = h('button.przycisk.drugi.maly', n); b.onclick = () => przekonaj(p.nr, k); return b; }))),
        nakazowe.length ? h('p.maly-tekst', `Tylko z nakazem: ${nakazowe.map((p) => `${p.nr} (${p.rodzaj})`).join(', ')}.`) : null,
        h('div', { style: 'display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:4px 0' },
          h('span.maly-tekst', 'Nakazy: ' + (nakazy.length ? nakazy.map((n) => `${n.budynek} — ${STAN_N[n.stan] || n.stan}`).join(' · ') : 'brak') + '.'),
          plan.budynek_id && !tenBudynek ? (() => { const b = h('button.przycisk.drugi.maly', `Wniosek o nakaz: ${plan.budynek}`);
            b.onclick = () => wniosek(plan.budynek_id, plan.budynek); return b; })() : null));
    }

    function ekranDT4(d) {
      const { droid, kontakty, plan } = d;
      const STREFA = { parter: 'parter', pietro: 'piętro', gora: 'najwyższe piętro', techniczny: 'strefa techniczna' };
      const naglowek = `DT-4 · SKAN PRZESTRZENNY · ${plan.budynek.toUpperCase()} · POZIOM ${plan.poziom} (${STREFA[plan.strefa] || plan.strefa})`;
      let postep = null;
      if (droid.stan === 'w_drodze') {
        const pasek = h('div', { style: `height:4px;background:var(--akcent);width:${Math.round(droid.postep * 100)}%;transition:width 1s linear` });
        const ile = h('span', czas(droid.zostalo_s));
        let zostalo = droid.zostalo_s;
        clearInterval(licznik);
        licznik = setInterval(() => {
          zostalo -= 1;
          ile.textContent = czas(zostalo);
          if (zostalo <= 0) { clearInterval(licznik); skanuj().then(odswiez); }
        }, 1000);
        postep = h('div', { style: 'margin:6px 0' },
          h('div', `Droid w drodze: ${droid.cel.budynek}, poziom ${droid.cel.poziom}, ${droid.cel.pokoj === 'korytarz' ? 'korytarz' : `lokal ${droid.cel.pokoj}`} · zostało `, ile),
          h('div', { style: 'height:4px;background:var(--linia);margin-top:4px' }, pasek));
      } else clearInterval(licznik);
      return panel(naglowek,
        h('p.maly-tekst', `${plan.preset_nazwa}: parter na poziomie ${plan.parter}, najwyższe piętro ${plan.gora}. `
          + `Zasięg ${d.zasieg} j. · stan: ${STAN_DROIDA[droid.stan] || droid.stan} · kontakty w zasięgu: ${kontakty.length}. `
          + 'Kliknij lokal, by wysłać tam droida; inny poziom lub budynek — poniżej.'),
        postep,
        planPietra(d),
        wstep(d),
        kontakty.length ? h('div.maly-tekst', 'Kontakty (kliknij, by rozmawiać): ', ...kontakty.map((k, i) => h('span',
          i ? ' · ' : null,
          h('a', { href: '#', onclick: (e) => { e.preventDefault(); zacznijRozmowe(k.token); } }, `${k.kod}: ${k.gatunek}, ${k.wyglad}`),
          ` (lokal ${k.pokoj})`))) : null,
        sterowanie(d),
        pasekOdczytu(d));
    }

    // ---------- rozmowa: zapis tekstowy pisany na żywo, polecenia operatora ----------

    const rozmowaBox = h('div');
    const lista = h('div', { style: 'max-height:340px;overflow-y:auto;margin:8px 0' });
    let rozmowa = null, pisze = false, zaplanowany = null, kolejka = Promise.resolve();
    const wypisane = new Set();
    const KTO_R = { droid: 'DT-4', operator: 'Operator', system: '' };

    // Wypisuje wpis znak po znaku (wpisy po kolei, nie naraz).
    function wypisz(w, odRazu) {
      const kto = w.kto === 'npc' ? (rozmowa?.kod || 'Rozmówca') : KTO_R[w.kto];
      const tekst = h('span', { style: 'white-space:pre-wrap' });
      const el = h('div', { style: `margin:5px 0;${w.kto === 'system' ? 'opacity:.6;font-style:italic' : ''}${w.kto === 'operator' ? 'color:var(--akcent-2)' : ''}` },
        kto ? h('b', `${kto} › `) : null, tekst);
      lista.append(el);
      if (odRazu) { tekst.textContent = w.tresc; return Promise.resolve(); }
      return new Promise((gotowe) => {
        let i = 0;
        const krok = () => {
          if (koniec) return gotowe();
          i = Math.min(w.tresc.length, i + 2);
          tekst.textContent = w.tresc.slice(0, i);
          lista.scrollTop = lista.scrollHeight;
          if (i < w.tresc.length) setTimeout(krok, 28); else gotowe();
        };
        krok();
      });
    }

    function pokazRozmowe(r, odRazu = false) {
      if (!r) { rozmowa = null; wyczysc(rozmowaBox); return; }
      if (!rozmowa || rozmowa.id !== r.id) { wyczysc(lista); wypisane.clear(); }
      rozmowa = r;
      for (const w of r.wpisy || []) {
        if (wypisane.has(w.id)) continue;
        wypisane.add(w.id);
        kolejka = kolejka.then(() => wypisz(w, odRazu));
      }
      rysujSterowanieRozmowy();
      // Droid jeszcze podchodzi: pierwsza wypowiedź (przedstawienie się) rusza sama, gdy stanie przy rozmówcy.
      const bezDroida = !(r.wpisy || []).some((w) => w.kto === 'droid');
      if (r.stan !== 'zakonczona' && bezDroida && zaplanowany !== r.id) {
        zaplanowany = r.id;
        setTimeout(() => { if (!koniec && rozmowa?.id === r.id) tura(''); }, (r.gotowa_za_s + 0.5) * 1000);
      }
    }

    function rysujSterowanieRozmowy() {
      if (!rozmowa) return;
      const zakonczona = rozmowa.stan === 'zakonczona';
      const pole = h('input', { placeholder: 'Polecenie dla droida, np. „spytaj go, komu jest winien pieniądze”', style: 'flex:1;min-width:220px',
        disabled: zakonczona || pisze, maxlength: 300 });
      const zadaj = h('button.przycisk', { disabled: zakonczona || pisze }, 'Zadaj pytanie');
      const dalej = h('button.przycisk.drugi', { disabled: zakonczona || pisze }, 'Dalej (droid pyta sam)');
      const zakoncz = h('button.przycisk.drugi', { disabled: zakonczona || pisze }, 'Zakończ rozmowę');
      const zamknij = h('button.przycisk.drugi', 'Zamknij zapis');
      zadaj.onclick = () => { if (pole.value.trim()) tura(pole.value.trim()); };
      pole.onkeydown = (e) => { if (e.key === 'Enter' && pole.value.trim()) tura(pole.value.trim()); };
      dalej.onclick = () => tura('');
      zakoncz.onclick = async () => {
        try { await api('sledztwo_rozmowa_koniec', { rozmowa_id: rozmowa.id }); await odswiezRozmowe(); skanuj(); }
        catch (e) { wyczysc(komunikat).append(blad(e)); }
      };
      zamknij.onclick = () => pokazRozmowe(null);
      const status = pisze ? 'DT-4 nadaje… czekam na odpowiedź rozmówcy.'
        : rozmowa.stan === 'podejscie' ? `DT-4 podchodzi do rozmówcy (2–3 m)${rozmowa.gotowa_za_s ? ` · ok. ${czas(rozmowa.gotowa_za_s)}` : ''}…`
          : zakonczona ? 'Rozmowa zakończona.' : 'Rozmowa trwa. Wpisz polecenie albo pozwól droidowi pytać samemu.';
      wyczysc(rozmowaBox).append(panel(`ROZMOWA · ${rozmowa.rozmowca} · lokal ${rozmowa.pokoj}`,
        h('p.maly-tekst', 'Zapis tekstowy — droidy śledcze nie mają modułu głosu.'),
        lista,
        h('p.maly-tekst', status),
        h('div', { style: 'display:flex;flex-wrap:wrap;gap:8px;align-items:center' },
          zakonczona ? zamknij : pole, zakonczona ? null : zadaj, zakonczona ? null : dalej, zakonczona ? null : zakoncz)));
      if (!zakonczona && !pisze) setTimeout(() => pole.focus(), 0);
    }

    async function tura(polecenie) {
      if (!rozmowa || pisze || rozmowa.stan === 'zakonczona') return;
      pisze = true;
      rysujSterowanieRozmowy();
      try {
        const r = await api('sledztwo_rozmowa_tura', { rozmowa_id: rozmowa.id, polecenie });
        pisze = false;
        pokazRozmowe(r.rozmowa);
        if (r.rozmowa.stan === 'zakonczona') { skanuj(); odswiez(); }
      } catch (e) {
        pisze = false;
        wyczysc(komunikat).append(blad(e));
        await odswiezRozmowe();
      }
    }

    async function odswiezRozmowe(id) {
      const ktora = id || rozmowa?.id;
      try {
        const r = await api('sledztwo_rozmowa', undefined, ktora ? { id: ktora } : {});
        pokazRozmowe(r.rozmowa, !rozmowa);
      } catch (e) { wyczysc(komunikat).append(blad(e)); }
    }

    async function zacznijRozmowe(token) {
      if (pisze) return;
      try {
        const r = await api('sledztwo_rozmowa_start', { kontakt: token });
        wyczysc(komunikat);
        pokazRozmowe(r.rozmowa);
        await skanuj();
        rozmowaBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      } catch (e) { wyczysc(komunikat).append(blad(e)); }
    }

    async function skanuj() {
      clearTimeout(zegarSkanu);
      if (koniec || !maSprawe) return;
      try {
        const d = await api('sledztwo_skan');
        // Po otwarciu aplikacji: wczytaj trwającą rozmowę (bez animacji tego, co już padło).
        if (d.rozmowa && (!rozmowa || rozmowa.id !== d.rozmowa.id)) await odswiezRozmowe(d.rozmowa.id);
        wyczysc(ekran).append(ekranDT4(d));
        // W drodze odśwież tuż po dotarciu (licznik woła skanuj), w miejscu — co 60 s (przechodnie się zmieniają).
        if (!koniec && d.droid.stan !== 'w_drodze') zegarSkanu = setTimeout(skanuj, 60000);
      } catch (e) { wyczysc(ekran).append(blad(e)); }
    }

    function dziennik(wpisy) {
      return panel('DZIENNIK', wpisy.length
        ? h('div', wpisy.map((x) => h('div', { style: 'margin:6px 0' },
          h('span.slaby', `${data(x.kiedy_utc)} · ${KTO[x.kto] || x.kto} · `), h('span', { style: 'white-space:pre-wrap' }, x.tresc))))
        : h('p.maly-tekst', 'Brak wpisów.'));
    }

    function archiwum(lista) {
      if (!lista.length) return null;
      return panel('ARCHIWUM', h('table.tabela',
        h('tr', h('th', '#'), h('th', 'Sprawa'), h('th', 'Stan'), h('th', 'Ocena')),
        lista.map((x) => h('tr', h('td', x.id), h('td', x.tytul || x.zgloszenie), h('td', STAN[x.stan] || x.stan),
          h('td', x.ocena != null ? `${x.ocena}%` : '—')))));
    }

    async function odswiez() {
      if (koniec) return;
      try {
        const d = await api('sledztwo_moje');
        if (!d.detektyw) {
          maSprawe = false;
          wyczysc(tresc).append(panel('DROID ŚLEDCZY', h('p.maly-tekst', 'To konto nie ma przydzielonego droida śledczego.')));
        } else {
          const nowa = h('button.przycisk', 'Przyjmij nową sprawę');
          nowa.onclick = () => nowaSprawa(nowa);
          const bylaSprawa = maSprawe;
          maSprawe = !!d.biezace;
          wyczysc(tresc).append(
            komunikat,
            panel(`BIURO DETEKTYWISTYCZNE · ${d.droid}`,
              h('p.maly-tekst', `Rozwiązane rodzaje spraw: ${d.rozwiazane} / ${d.wszystkie_typy}`),
              d.biezace ? null : h('p', 'Brak otwartej sprawy.'),
              d.biezace ? null : nowa),
            d.biezace ? sprawa(d.biezace) : null,
            d.biezace ? ekran : null,
            d.biezace ? rozmowaBox : null,
            d.biezace ? dziennik(d.dziennik || []) : null,
            archiwum(d.archiwum || []));
          if (maSprawe && !bylaSprawa) skanuj();
        }
      } catch (e) { wyczysc(tresc).append(blad(e)); }
      clearTimeout(zegar);
      if (!koniec) zegar = setTimeout(odswiez, 30000);
    }

    w.append(tresc);
    odswiez();
    return () => { koniec = true; clearTimeout(zegar); clearTimeout(zegarSkanu); clearInterval(licznik); };
  },
};

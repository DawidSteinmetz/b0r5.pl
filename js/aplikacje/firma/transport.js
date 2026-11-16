// Profil firmy: przewozy (transport). Zakładki: Flota, Magazyn, Załoga, Szlaki.
//
// Kontekst `f` od aplikacji Firma:
//   f.firma { nazwa, dane: { flota, zaloga, magazyn, szlaki }, czlonkowie }
//   f.katalog { towary, planety }   wspólny katalog gry (ten sam użyje giełda)
//   f.moze                           czy wolno zmieniać (właściciel / menedżer)
//   f.operacja(op, dane)             zmiana danych firmy przez API
//   f.wczytaj()                      świeże dane firmy
//
// Inne profile (np. komórka) używają tego samego panelu i dokładają własne zakładki:
//   uruchom(obszar, f, {
//     nazwyZakladek: [[id, nazwa], ...]   dodatkowe zakładki na końcu
//     kolejnosc:     [[id, nazwa], ...]   albo pełny zestaw i kolejność zakładek (zastępuje domyślny)
//     zakladki:      { id: (p) => element }
//     akcjeOsoby:    (osoba, p) => element   dodatkowe przyciski przy osobie w zakładce Załoga
//     tekst:         { zalogaPusta, ... }    podmiana napisów
//   })
// gdzie `p` to wspólne narzędzia panelu (zobacz obiekt `panel` niżej).

import { h, wyczysc } from '../../rdzen/narzedzia.js';
import { api } from '../../rdzen/api.js';

const STATUS_KURSU = {
  szkic: ['Szkic', ''],
  zgloszony: ['Czeka na zgodę kontroli ruchu', 'status-niepewny'],
  w_realizacji: ['W trasie', 'status-dostepny'],
  wstrzymany: ['Wstrzymany', 'status-nieobecny'],
  zakonczony: ['Zakończony', 'slaby'],
  odrzucony: ['Odrzucony', 'status-nieobecny'],
};

const STAN_STATKU = {
  sprawny: 'status-dostepny',
  'w trasie': 'status-niepewny',
  uszkodzony: 'status-nieobecny',
  'w naprawie': 'status-niepewny',
  'na misji': 'status-niepewny',
};

export default {
  nazwa: 'Przewozy',

  uruchom(obszar, f, rozszerzenie = {}) {
    let dane = f.firma.dane;
    let kandydaci = f.kandydaci || [];
    let finanse = f.finanse;
    let oferty = f.katalog.oferty || [];
    let ceny = f.ceny || {}; // dzisiejsze kursy: [planeta][towar] = { kupno, sprzedaz }
    const tekst = { zalogaPusta: 'Brak personelu.', kandydaci: 'Pula kandydatów', ...(rozszerzenie.tekst || {}) };
    let podwidok = null; // otwarty edytor (kursu, misji...) zamiast zakładki
    let licznikSprawdzania = null;

    const planety = Object.fromEntries(f.katalog.planety.map((p) => [p.id, p]));
    const towary = Object.fromEntries(f.katalog.towary.map((t) => [t.id, t]));
    const nazwaPlanety = (id) => planety[id]?.nazwa || '—';
    const komunikat = h('div');
    const pokaz = (t, rodzaj = 'blad') => wyczysc(komunikat).append(h('div.komunikat.' + rodzaj, t));

    async function odswiez() {
      const d = await f.wczytaj();
      dane = d.firma.dane;
      kandydaci = d.kandydaci || [];
      ceny = d.ceny || {};
      finanse = d.finanse;
      oferty = d.katalog.oferty || [];
      rysuj();
    }

    async function wykonaj(op, d, ok) {
      try {
        const wynik = await f.operacja(op, d);
        await odswiez();
        if (ok) pokaz(ok, 'ok');
        return wynik;
      } catch (e) {
        pokaz(e.message);
        return null;
      }
    }

    const zakladki = h('div.zakladki');
    const tresc = h('div');
    obszar.append(zakladki, komunikat, tresc);

    // Wspólne narzędzia dla zakładek z innych profili.
    const panel = {
      f,
      get dane() { return dane; },
      get finanse() { return finanse; },
      planety, towary, nazwaPlanety, wykonaj, pokaz, h, wyczysc,
      wstaw: (el) => wyczysc(komunikat).append(el), // okienko (np. formularz) nad treścią zakładki
      rysuj: () => rysuj(),
      odswiez: () => odswiez(),
      otworz: (fn) => { podwidok = fn; rysuj(); },
      zamknij: () => { podwidok = null; rysuj(); },
      przebuduj: () => { if (podwidok) wyczysc(tresc).append(podwidok()); },
    };

    const ZAKLADKI = { flota, magazyn, zaloga, szlaki, budynki, finanse: zakladkaFinansow };
    const NAZWY = rozszerzenie.kolejnosc || [
      ['flota', 'Flota'], ['magazyn', 'Magazyn'], ['zaloga', 'Załoga'], ['szlaki', 'Szlaki'], ['budynki', 'Budynki'],
      ...(rozszerzenie.nazwyZakladek || []), ['finanse', 'Finanse'],
    ];
    for (const [id, fn] of Object.entries(rozszerzenie.zakladki || {})) ZAKLADKI[id] = () => fn(panel);
    let zakladka = NAZWY[0][0];

    function rysuj() {
      wyczysc(zakladki).append(...NAZWY.map(([k, n]) =>
        h('button' + (k === zakladka ? '.aktywna' : ''), { onclick: () => { zakladka = k; podwidok = null; wyczysc(komunikat); rysuj(); } }, n)));
      f.ustawWstecz(podwidok ? () => { podwidok = null; rysuj(); return true; } : f.wrocDoListy);
      wyczysc(tresc).append(podwidok ? podwidok() : ZAKLADKI[zakladka]());
    }

    // ---------- flota ----------

    function flota() {
      if (!dane.flota.length) return h('div.panel', h('div.pusto', 'Firma nie posiada statków.'));
      const zalogaNa = (sid) => dane.zaloga.filter((z) => z.statek === sid);
      return h('div.karty', dane.flota.map((s) => {
        const kapitan = dane.zaloga.find((z) => z.id === s.kapitan);
        const ladunek = (wiersz, wartosc) => h('tr', h('th', wiersz), h('td', wartosc));
        return h('div.panel',
          h('div.rzad', h('h2.rozpycha', { style: 'margin:0' }, s.nazwa),
            f.moze ? h('button.przycisk.drugi.maly', { onclick: () => zmienNazwe(s) }, 'Zmień nazwę') : null),
          h('div.slaby.maly-tekst', { style: 'margin:4px 0 10px' }, s.klasa || ''),
          h('table.tabela',
            ladunek('Stan', h('span.' + (STAN_STATKU[s.stan] || 'slaby'), s.stan || 'sprawny')),
            ladunek('Położenie', nazwaPlanety(s.lokalizacja)),
            ladunek('Ładowność', `${s.ladownosc} t`),
            ladunek('Kapitan', kapitan ? kapitan.imie : '—'),
            ladunek('Załoga', `${zalogaNa(s.id).length} os.`)));
      }));
    }

    function zmienNazwe(s) {
      const pole = h('input', { value: s.nazwa, maxlength: 60 });
      wyczysc(komunikat).append(h('div.panel', { style: 'border-color:var(--akcent);margin-bottom:14px' },
        h('div.pole', h('label', 'Nowa nazwa statku'), pole),
        h('div.rzad',
          h('button.przycisk.maly', { onclick: () => wykonaj('statek_nazwa', { statek: s.id, nazwa: pole.value }, 'Zmieniono nazwę w rejestrze statków.') }, 'Zatwierdź'),
          h('button.przycisk.drugi.maly', { onclick: () => wyczysc(komunikat) }, 'Anuluj'))));
      pole.focus();
    }

    // ---------- magazyn ----------

    function magazyn() {
      if (!dane.magazyn.length) return h('div.panel', h('div.pusto', 'Magazyny są puste.'));
      const wg = {};
      for (const m of dane.magazyn) (wg[m.planeta] ||= []).push(m);
      return h('div', Object.entries(wg)
        .sort(([a], [b]) => nazwaPlanety(a).localeCompare(nazwaPlanety(b)))
        .map(([pid, pozycje]) => {
          const masa = pozycje.reduce((s, m) => s + m.ilosc * (towary[m.towar]?.masa || 1), 0);
          return h('div.panel',
            h('div.rzad', h('h2.rozpycha', { style: 'margin:0' }, nazwaPlanety(pid)), h('span.slaby.maly-tekst', `razem ${masa} t`)),
            h('table.tabela', { style: 'margin-top:8px' },
              h('thead', h('tr', h('th', 'Towar'), h('th', 'Kategoria'), h('th', 'Ilość'), h('th', 'Masa'))),
              h('tbody', pozycje.map((m) => {
                const t = towary[m.towar] || { nazwa: '?', jednostka: '' };
                return h('tr', h('td', t.nazwa), h('td.slaby', t.kategoria || '—'), h('td', `${m.ilosc} ${t.jednostka}`), h('td.slaby', `${m.ilosc * (t.masa || 1)} t`));
              }))));
        }));
    }

    // ---------- załoga ----------

    // Załoga / personel. W profilach z budynkami osobę można przydzielić także do budynku
    // (i zrobić kierownikiem). Osoby z ukrytymi cechami („testowalny”) można przetestować.
    function zaloga() {
      const budynki = dane.budynki || [];
      const styl = 'min-height:36px;background:transparent;border:1px solid var(--linia);border-radius:4px;padding:0 6px';
      const wartosc = (z) => (z.statek ? 's:' + z.statek : z.budynek ? 'b:' + z.budynek : '');
      const wybor = (z) => {
        const s = h('select', { style: styl, disabled: z.stan === 'na misji' },
          h('option', { value: '' }, 'Baza (bez przydziału)'),
          dane.flota.length ? h('optgroup', { label: 'Statki' }, dane.flota.map((st) => h('option', { value: 's:' + st.id, selected: wartosc(z) === 's:' + st.id }, st.nazwa))) : null,
          budynki.length ? h('optgroup', { label: 'Budynki' }, budynki.map((b) => h('option', { value: 'b:' + b.id, selected: wartosc(z) === 'b:' + b.id }, b.nazwa))) : null);
        s.addEventListener('change', () => {
          const [typ, id] = s.value.split(':');
          wykonaj('przydziel', { czlonek: z.id, statek: typ === 's' ? id : '', budynek: typ === 'b' ? id : '' }, `${z.imie}: nowy przydział.`);
        });
        return s;
      };
      const kapitan = (z) => dane.flota.some((s) => s.kapitan === z.id);
      const kierownik = (z) => budynki.some((b) => b.menedzer === z.id);
      const opisPrzydzialu = (z) => dane.flota.find((s) => s.id === z.statek)?.nazwa || budynki.find((b) => b.id === z.budynek)?.nazwa || 'Baza';

      const awans = (z) => {
        if (!f.moze || z.stan === 'na misji') return null;
        if (z.statek && !kapitan(z)) return h('button.przycisk.drugi.maly', { onclick: () => wykonaj('przydziel', { czlonek: z.id, statek: z.statek, kapitan: true }, `${z.imie} dowodzi statkiem.`) }, 'Na kapitana');
        if (z.budynek && !kierownik(z)) return h('button.przycisk.drugi.maly', { onclick: () => wykonaj('przydziel', { czlonek: z.id, budynek: z.budynek, menedzer: true }, `${z.imie} kieruje budynkiem.`) }, 'Na kierownika');
        return null;
      };
      const test = (z) => (f.moze && z.testowalny
        ? h('button.przycisk.drugi.maly', {
          onclick: async () => {
            const w = await wykonaj('test', { czlonek: z.id });
            if (w) pokaz(`Test przeprowadzony: ${z.imie}. Wyniki w karcie osoby.`, 'ok');
          },
        }, 'Test') : null);

      const wiersze = [];
      for (const z of dane.zaloga) {
        const znaczek = kapitan(z) ? 'KAPITAN' : kierownik(z) ? 'KIEROWNIK' : '';
        wiersze.push(h('tr',
          h('td', z.kryptonim ? h('span.poziom', z.kryptonim + '  ') : null, z.imie, znaczek ? h('span.poziom', '  ' + znaczek) : null,
            z.stan && z.stan !== 'zdrowy' ? h('div.maly-tekst.' + (z.stan === 'na misji' ? 'status-niepewny' : 'status-nieobecny'), z.stan) : null),
          h('td.slaby', z.funkcja || '—'),
          h('td.slaby', z.pensja ? `${z.pensja} kr` : '—'),
          h('td', f.moze ? wybor(z) : opisPrzydzialu(z)),
          h('td', h('div.rzad', awans(z), test(z), rozszerzenie.akcjeOsoby ? rozszerzenie.akcjeOsoby(z, panel) : null))));
        // Wynik ostatniego testu: zgrubne oceny cech.
        if (z.ocena?.wyniki) wiersze.push(h('tr', h('td', { colspan: 5, style: 'padding-top:0' }, ocenaTekst(z.ocena))));
      }
      return h('div',
        h('div.panel', dane.zaloga.length
          ? h('table.tabela',
            h('thead', h('tr', h('th', 'Imię'), h('th', 'Funkcja'), h('th', 'Pensja'), h('th', 'Przydział'), h('th', ''))),
            h('tbody', wiersze))
          : h('div.pusto', tekst.zalogaPusta)),
        pulaKandydatow());
    }

    // Kandydaci do zatrudnienia: można ich przetestować (raz w miesiącu) i przyjąć.
    function pulaKandydatow() {
      if (!kandydaci.length) return null;
      return h('div.panel',
        h('h2', tekst.kandydaci),
        h('ul.lista', kandydaci.map((k) => h('li',
          h('div.rzad',
            h('div.rozpycha', h('div', k.imie), h('div.slaby.maly-tekst', [k.funkcja, k.pensja ? `oczekuje ${k.pensja} kr / mies.` : ''].filter(Boolean).join(' · '))),
            f.moze && k.testowalny ? h('button.przycisk.drugi.maly', { onclick: () => wykonaj('kandydat_test', { kandydat: k.id }, `Test przeprowadzony: ${k.imie}.`) }, 'Test') : null,
            f.moze ? h('button.przycisk.maly', { onclick: () => wykonaj('kandydat_zatrudnij', { kandydat: k.id }, `${k.imie} dołącza do zespołu.`) }, 'Zatrudnij') : null),
          k.opis ? h('div.slaby.maly-tekst', { style: 'margin-top:4px' }, k.opis) : null,
          ocenaTekst(k.ocena)))));
    }

    function ocenaTekst(o) {
      if (!o?.wyniki) return null;
      return h('div.slaby.maly-tekst', { style: 'margin-top:4px' }, `Ocena z ${new Date(o.data).toLocaleDateString('pl-PL')} (${o.tester}): `,
        Object.entries(o.wyniki).map(([k, v], i) => [i ? ' · ' : '', `${k}: `, h('span', { style: 'color:var(--tekst)' }, v)]));
    }

    // ---------- budynki i rynek nieruchomości ----------

    // „2400 m² · 2 piętra · 6 poziomów pod ziemią”
    function wymiary(b) {
      return [
        b.powierzchnia ? `${Number(b.powierzchnia).toLocaleString('pl-PL')} m²` : '',
        b.pietra ? `${b.pietra} ${b.pietra === 1 ? 'kondygnacja' : 'kondygnacje'}` : '',
        b.szyby ? `${b.szyby} ${b.szyby === 1 ? 'poziom' : 'poziomów'} pod ziemią` : '',
      ].filter(Boolean).join(' · ');
    }

    function budynki() {
      const lista = dane.budynki || [];
      const karty = lista.map((b) => {
        const kier = dane.zaloga.find((z) => z.id === b.menedzer);
        const zmien = (o) => wykonaj('budynek_pracownicy', { budynek: b.id, pracownicy: b.pracownicy + o });
        const wiersz = (k, v) => h('tr', h('th', k), h('td', v));
        return h('div.panel',
          h('h2', { style: 'margin:0' }, b.nazwa),
          h('div.slaby.maly-tekst', { style: 'margin:4px 0 10px' }, [b.typ, b.planeta ? nazwaPlanety(b.planeta) : ''].filter(Boolean).join(' · ')),
          h('table.tabela',
            b.wspolrzedne ? wiersz('Współrzędne', b.wspolrzedne) : null,
            b.powierzchnia || b.pietra || b.szyby ? wiersz('Wymiary', wymiary(b)) : null,
            wiersz('Kierownik', kier ? kier.imie : h('span.status-nieobecny', 'brak')),
            wiersz('Pracownicy', f.moze
              ? h('span.licznik',
                h('button', { onclick: () => zmien(-1), disabled: b.pracownicy <= 0, 'aria-label': 'Zwolnij' }, '−'),
                h('span', `${b.pracownicy} / ${b.pojemnosc}`),
                h('button', { onclick: () => zmien(1), disabled: b.pracownicy >= b.pojemnosc, 'aria-label': 'Zatrudnij' }, '+'))
              : `${b.pracownicy} / ${b.pojemnosc}`),
            b.pensja ? wiersz('Koszt obsady', `${b.pracownicy * b.pensja} kr / mies.`) : null),
          b.opis ? h('p.slaby.maly-tekst', b.opis) : null,
          !kier && f.moze ? h('p.slaby.maly-tekst', 'Kierownika wyznaczysz w zakładce Załoga: przydziel osobę do budynku i wybierz „Na kierownika”.') : null);
      });
      const saldo = finanse?.konto?.saldo ?? 0;
      return h('div',
        lista.length ? h('div.karty', karty) : h('div.panel', h('div.pusto', 'Brak budynków.')),
        oferty.length ? h('div.panel', { style: 'margin-top:14px' },
          h('h2', 'Rynek nieruchomości'),
          h('div.slaby.maly-tekst', { style: 'margin-bottom:8px' }, `Środki na rachunku: ${saldo} kr`),
          h('ul.lista', oferty.map((o) => h('li', h('div.rzad',
            h('div.rozpycha', h('div', o.nazwa), h('div.slaby.maly-tekst', [o.typ, o.planeta ? nazwaPlanety(o.planeta) : '', wymiary(o), o.pojemnosc ? `do ${o.pojemnosc} pracowników` : '', o.opis].filter(Boolean).join(' · '))),
            h('span', `${o.cena} kr`),
            f.moze ? h('button.przycisk.maly', {
              disabled: saldo < o.cena,
              onclick: () => { if (confirm(`Kupić „${o.nazwa}” za ${o.cena} kr z rachunku firmy?`)) wykonaj('budynek_kup', { oferta: o.id }, `Zakupiono: ${o.nazwa}.`); },
            }, 'Kup') : null))))) : null);
    }

    // ---------- finanse: rachunek firmy ----------

    function zakladkaFinansow() {
      if (!finanse) return h('div.panel', h('div.pusto', 'Brak dostępu do rachunku.'));
      const { konto, transakcje } = finanse;
      const kr = (n) => `${Number(n).toLocaleString('pl-PL')} kr`;
      const numer = h('input', { inputmode: 'numeric', placeholder: '0000-0000-0000', maxlength: 14 });
      numer.addEventListener('input', () => { numer.value = numer.value.replace(/\D/g, '').slice(0, 12).replace(/(\d{4})(?=\d)/g, '$1-'); });
      const kwota = h('input', { type: 'number', min: 1, step: 1 });
      const tytul = h('input', { maxlength: 140 });
      const kto = h('div.slaby.maly-tekst', { style: 'min-height:1.4em' });
      numer.addEventListener('input', async () => {
        kto.textContent = '';
        if (numer.value.replace(/\D/g, '').length !== 12) return;
        try { kto.textContent = 'Właściciel: ' + (await api('bank_sprawdz', undefined, { n: numer.value })).postac; } catch (e) { kto.textContent = e.message; }
      });
      return h('div',
        h('div.panel.bank-karta',
          h('div.slaby.maly-tekst', 'Rachunek firmy'),
          h('div.bank-saldo' + (konto.saldo < 0 ? '.ujemne' : ''), kr(konto.saldo)),
          h('div.slaby.maly-tekst', 'Numer ', h('span.bank-numer', konto.numer))),
        f.moze ? h('form.panel', {
          onsubmit: async (e) => {
            e.preventDefault();
            const k = Math.floor(Number(kwota.value));
            if (!(k > 0)) return pokaz('Podaj kwotę większą od zera.');
            if (!confirm(`Przelać ${kr(k)} z rachunku firmy?`)) return;
            await wykonaj('przelew', { numer: numer.value, kwota: k, tytul: tytul.value }, `Przelew ${kr(k)} wykonany.`);
          },
        },
        h('h2', 'Przelew z rachunku firmy'),
        h('div.pole', h('label', 'Rachunek odbiorcy'), numer, kto),
        h('div.dwie-kolumny', { style: 'grid-template-columns:1fr 2fr' }, h('div.pole', h('label', 'Kwota'), kwota), h('div.pole', h('label', 'Tytuł'), tytul)),
        h('button.przycisk', { type: 'submit' }, 'Wyślij')) : null,
        h('div.panel', h('h2', 'Historia rachunku'),
          transakcje.length ? h('ul.lista', transakcje.map((t) => {
            const wplyw = t.kierunek === 'wplyw';
            return h('li', h('div.rzad',
              h('div.rozpycha', h('div', (wplyw ? 'Od: ' + t.od_nazwa : 'Do: ' + t.do_nazwa)),
                h('div.slaby.maly-tekst', [t.tytul, new Date(t.czas).toLocaleString('pl-PL', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })].filter(Boolean).join(' · '))),
              h('span' + (wplyw ? '.bank-plus' : '.bank-minus'), (wplyw ? '+' : '−') + kr(t.kwota))));
          })) : h('div.pusto', 'Brak operacji.')));
    }

    // ---------- szlaki ----------

    function szlaki() {
      const lista = [...dane.szlaki].sort((a, b) => (b.zmieniono || '').localeCompare(a.zmieniono || ''));
      const statek = (id) => dane.flota.find((s) => s.id === id)?.nazwa || '?';
      return h('div',
        f.moze ? h('div', { style: 'margin-bottom:14px' }, h('button.przycisk', { onclick: () => { const k = nowyKurs(); panel.otworz(() => edytorKursu(k)); } }, 'Zaplanuj kurs')) : null,
        lista.length
          ? h('div.panel', h('ul.lista', lista.map((s) => {
            const [opis, klasa] = STATUS_KURSU[s.status] || [s.status, ''];
            return h('li', h('div.rzad',
              h('div.rozpycha',
                h('div', s.nazwa, s.auto ? h('span.poziom', `  AUTOMAT · co ${s.co_dni} dni`) : null),
                h('div.slaby.maly-tekst', `${statek(s.statek)} · ${s.przystanki.map((p) => nazwaPlanety(p.planeta)).join(' → ')}`),
                ostatniObieg(s),
                s.uwagi ? h('div.maly-tekst', { style: 'color:var(--akcent-2)' }, 'Kontrola ruchu: ' + s.uwagi) : null),
              h('span.maly-tekst' + (klasa ? '.' + klasa : ''), opis),
              f.moze && s.auto && s.status === 'wstrzymany' ? h('button.przycisk.maly', { onclick: () => wykonaj('szlak_wznow', { id: s.id }, 'Automat wznowiony.') }, 'Wznów') : null,
              f.moze && s.auto && ['w_realizacji', 'wstrzymany'].includes(s.status)
                ? h('button.przycisk.zly.maly', { onclick: () => { if (confirm('Zakończyć kurs automatyczny? Statek wróci do dyspozycji.')) wykonaj('szlak_zatrzymaj', { id: s.id }, 'Automat zatrzymany.'); } }, 'Zatrzymaj') : null,
              h('button.przycisk.drugi.maly', { onclick: () => { const k = JSON.parse(JSON.stringify(s)); panel.otworz(() => edytorKursu(k)); } },
                f.moze && ['szkic', 'odrzucony'].includes(s.status) ? 'Edytuj' : 'Podgląd')));
          })))
          : h('div.panel', h('div.pusto', 'Brak zaplanowanych kursów.')));
    }

    // Wynik ostatniego obiegu (i kiedy następny) — pod nazwą kursu na liście.
    function ostatniObieg(s) {
      const d = s.dziennik?.[s.dziennik.length - 1];
      const nastepny = s.auto && s.status === 'w_realizacji' && s.nastepny ? ` · następny obieg ${new Date(s.nastepny).toLocaleDateString('pl-PL')}` : '';
      if (!d) return nastepny ? h('div.slaby.maly-tekst', nastepny.slice(3)) : null;
      return h('div.maly-tekst',
        h('span.slaby', `Obieg ${new Date(d.data).toLocaleDateString('pl-PL')}: `),
        d.ok ? h('span' + (d.zysk >= 0 ? '.bank-plus' : '.status-nieobecny'), `${d.zysk >= 0 ? '+' : ''}${d.zysk} kr`) : h('span.status-nieobecny', 'nieudany'),
        h('span.slaby', nastepny));
    }

    function nowyKurs() {
      const s = dane.flota.find((x) => x.stan === 'sprawny') || dane.flota[0];
      return {
        id: '', nazwa: '', statek: s?.id || '', notatka: '', status: 'szkic', auto: false, co_dni: 7,
        przystanki: [{ planeta: s?.lokalizacja || '', operacje: [] }, { planeta: '', operacje: [] }],
      };
    }

    // Edytor planu kursu: statek, przystanki, a na każdym operacje magazynowe (załaduj / rozładuj)
    // albo handlowe na lokalnym rynku (kup / sprzedaj, po dzisiejszym kursie giełdowym).
    function edytorKursu(k) {
      const edytowalny = f.moze && ['szkic', 'odrzucony'].includes(k.status);
      const problemy = h('div');
      const podsumowanie = h('div');
      const wycena = h('div');
      const wyb = (opcje, wartosc, zmiana, pusta = null) => {
        const s = h('select', { disabled: !edytowalny, style: 'min-height:40px;background:rgba(0,0,0,.35);border:1px solid var(--linia);border-radius:4px;padding:0 8px' },
          pusta ? h('option', { value: '' }, pusta) : null,
          opcje.map(([v, n]) => h('option', { value: v, selected: v === wartosc }, n)));
        s.addEventListener('change', () => zmiana(s.value));
        return s;
      };
      const opcjePlanet = f.katalog.planety.map((p) => [p.id, p.nazwa]);
      const opcjeTowarow = f.katalog.towary.map((t) => [t.id, `${t.nazwa} (${t.masa} t/${t.jednostka})`]);

      const sprawdz = () => {
        przeliczLadunek();
        clearTimeout(licznikSprawdzania);
        licznikSprawdzania = setTimeout(async () => {
          try {
            const { problemy: p, wycena: w } = await f.sprawdzSzlak(k);
            pokazWycene(w);
            if (!edytowalny) return wyczysc(problemy); // podgląd zgłoszonego / trwającego kursu: bez kontroli planu
            wyczysc(problemy).append(p.length
              ? h('div.komunikat.blad', h('div', 'Kontrola planu:'), h('ul', { style: 'margin:6px 0 0;padding-left:18px' }, p.map((x) => h('li', x))))
              : h('div.komunikat.ok', 'Plan kursu poprawny.'));
          } catch (e) {
            wyczysc(problemy).append(h('div.komunikat.blad', e.message));
          }
        }, 350);
      };
      const przebuduj = () => panel.przebuduj();

      // Wycena po kursie giełdowym. Faktyczny wynik zależy od kapitana i od kursu w dniu obiegu.
      function pokazWycene(w) {
        if (!w || (!w.koszt && !w.przychod)) return wyczysc(wycena);
        wyczysc(wycena).append(h('table.tabela', { style: 'margin-top:10px' },
          h('tr', h('th', 'Zakupy'), h('td', `${w.koszt} kr`)),
          h('tr', h('th', 'Sprzedaż'), h('td', `${w.przychod} kr`)),
          h('tr', h('th', 'Wynik po kursie dnia'), h('td' + (w.zysk >= 0 ? '.bank-plus' : '.status-nieobecny'), `${w.zysk >= 0 ? '+' : ''}${w.zysk} kr`))),
        h('div.slaby.maly-tekst', { style: 'margin-top:6px' }, 'Kapitan negocjuje ceny sam — doświadczony handlowiec wyciągnie więcej, nowicjusz mniej. Kursy zmieniają się codziennie.'));
      }

      // Ładunek po każdym przystanku (podgląd; ostateczne sprawdzenie robi serwer).
      function przeliczLadunek() {
        const statek = dane.flota.find((s) => s.id === k.statek);
        const ladownia = {};
        const wiersze = k.przystanki.map((p, i) => {
          for (const typy of [['rozladunek', 'sprzedaj'], ['zaladunek', 'kup']]) {
            for (const o of p.operacje.filter((x) => typy.includes(x.typ))) {
              ladownia[o.towar] = Math.max(0, (ladownia[o.towar] || 0) + (typy[0] === 'rozladunek' ? -o.ilosc : o.ilosc));
            }
          }
          const masa = Object.entries(ladownia).reduce((s, [t, il]) => s + il * (towary[t]?.masa || 1), 0);
          const za = statek && masa > statek.ladownosc;
          return h('tr', h('td', `${i + 1}. ${nazwaPlanety(p.planeta)}`), h('td' + (za ? '.status-nieobecny' : ''), `${masa} / ${statek?.ladownosc ?? '?'} t`));
        });
        wyczysc(podsumowanie).append(h('table.tabela', h('thead', h('tr', h('th', 'Po przystanku'), h('th', 'Ładownia'))), h('tbody', wiersze)));
      }

      const przystanek = (p, i) => h('div.panel',
        h('div.rzad',
          h('h2.rozpycha', { style: 'margin:0' }, `Przystanek ${i + 1}`),
          wyb(opcjePlanet, p.planeta, (v) => { p.planeta = v; sprawdz(); }, 'wybierz planetę'),
          edytowalny && k.przystanki.length > 2
            ? h('button.przycisk.zly.maly', { 'aria-label': 'Usuń przystanek', onclick: () => { k.przystanki.splice(i, 1); przebuduj(); sprawdz(); } }, '×') : null),
        h('div', { style: 'margin-top:10px' }, p.operacje.map((o, j) => {
          const ilosc = h('input', { type: 'number', min: 1, step: 1, value: o.ilosc || '', disabled: !edytowalny,
            style: 'width:110px;min-height:40px;background:rgba(0,0,0,.35);border:1px solid var(--linia);border-radius:4px;padding:0 8px' });
          ilosc.addEventListener('input', () => { o.ilosc = Math.max(0, Math.floor(Number(ilosc.value) || 0)); sprawdz(); });
          // Cena na tym rynku dziś (dla kupna i sprzedaży).
          const cena = h('span.slaby.maly-tekst', { style: 'min-width:120px' });
          const pokazCene = () => {
            const c = ceny[p.planeta]?.[o.towar];
            cena.textContent = c && (o.typ === 'kup' || o.typ === 'sprzedaj') ? `${o.typ === 'kup' ? c.kupno : c.sprzedaz} kr / ${towary[o.towar]?.jednostka || 'szt.'}` : '';
          };
          pokazCene();
          return h('div.rzad', { style: 'margin-bottom:8px' },
            wyb([['rozladunek', 'Rozładuj do magazynu'], ['zaladunek', 'Załaduj z magazynu'], ['kup', 'Kup na rynku'], ['sprzedaj', 'Sprzedaj na rynku']], o.typ, (v) => { o.typ = v; pokazCene(); sprawdz(); }),
            wyb(opcjeTowarow, o.towar, (v) => { o.towar = v; pokazCene(); sprawdz(); }, 'towar'),
            ilosc,
            cena,
            edytowalny ? h('button.przycisk.drugi.maly', { 'aria-label': 'Usuń operację', onclick: () => { p.operacje.splice(j, 1); przebuduj(); sprawdz(); } }, '×') : null);
        })),
        edytowalny ? h('button.przycisk.drugi.maly', { onclick: () => { p.operacje.push({ typ: i === 0 ? 'kup' : 'sprzedaj', towar: '', ilosc: 0 }); przebuduj(); sprawdz(); } }, '+ Operacja') : null);

      const nazwa = h('input', { value: k.nazwa, maxlength: 80, disabled: !edytowalny, placeholder: 'np. Kurs zbożowy na Tatooine' });
      nazwa.addEventListener('input', () => { k.nazwa = nazwa.value; });
      const notatka = h('input', { value: k.notatka || '', maxlength: 500, disabled: !edytowalny });
      notatka.addEventListener('input', () => { k.notatka = notatka.value; });
      const opcjeStatkow = dane.flota.map((s) => [s.id, `${s.nazwa} · ${s.ladownosc} t · ${nazwaPlanety(s.lokalizacja)} · ${s.stan}`]);
      const auto = h('input', { type: 'checkbox', checked: !!k.auto, disabled: !edytowalny });
      const coDni = h('input', { type: 'number', min: 1, max: 30, step: 1, value: k.co_dni || 7, disabled: !edytowalny, style: 'width:80px;min-height:40px;background:rgba(0,0,0,.35);border:1px solid var(--linia);border-radius:4px;padding:0 8px' });
      auto.addEventListener('change', () => {
        k.auto = auto.checked;
        // Automat wraca do portu startowego: dopisz powrót, jeśli go nie ma.
        const pierwszy = k.przystanki[0]?.planeta;
        if (k.auto && pierwszy && k.przystanki[k.przystanki.length - 1].planeta !== pierwszy) k.przystanki.push({ planeta: pierwszy, operacje: [] });
        przebuduj(); sprawdz();
      });
      coDni.addEventListener('input', () => { k.co_dni = Math.max(1, Math.min(30, Math.floor(Number(coDni.value) || 7))); });
      // Dziennik obiegów (kursy automatyczne i rozliczone).
      const dziennik = (k.dziennik || []).slice().reverse().map((d) => h('li',
        h('div.rzad', h('span.rozpycha', new Date(d.data).toLocaleDateString('pl-PL')),
          d.ok ? h('span' + (d.zysk >= 0 ? '.bank-plus' : '.status-nieobecny'), `${d.zysk >= 0 ? '+' : ''}${d.zysk} kr`) : h('span.status-nieobecny', d.opis)),
        d.pozycje?.length ? h('div.slaby.maly-tekst', d.pozycje.map((x) => `${x.planeta}: ${x.typ === 'kup' ? 'kupno' : 'sprzedaż'} ${x.ilosc} × ${x.towar} po ${x.cena} kr`).join(' · ')) : null));

      const zapisz = async (op) => {
        const wynik = await wykonaj(op, { szlak: k }, op === 'szlak_zglos' ? 'Plan lotu złożony w kontroli ruchu.' : 'Szkic zapisany.');
        if (wynik && op === 'szlak_zglos') { panel.zamknij(); }
        else if (wynik) { k.id = wynik.id; rysuj(); }
      };

      const widok = h('div',
        h('div.panel',
          h('h2', k.id ? 'Plan kursu' : 'Nowy plan kursu'),
          k.uwagi ? h('div.komunikat.blad', 'Kontrola ruchu: ' + k.uwagi) : null,
          h('div.pole', h('label', 'Nazwa kursu'), nazwa),
          h('div.pole', h('label', 'Statek'), wyb(opcjeStatkow, k.statek, (v) => {
            k.statek = v;
            const s = dane.flota.find((x) => x.id === v);
            if (s?.lokalizacja && edytowalny) k.przystanki[0].planeta = s.lokalizacja;
            przebuduj(); sprawdz();
          }, 'wybierz statek')),
          h('div.pole', h('label', 'Uwagi dla kontroli ruchu'), notatka),
          h('label.rzad', { style: 'cursor:pointer' }, auto, h('span', 'Kurs automatyczny — powtarzaj co'), coDni, h('span', 'dni'))),
        k.przystanki.map(przystanek),
        edytowalny ? h('div', { style: 'margin:14px 0' }, h('button.przycisk.drugi', { onclick: () => { k.przystanki.push({ planeta: '', operacje: [] }); przebuduj(); sprawdz(); } }, '+ Przystanek')) : null,
        h('div.panel', h('h2', 'Obciążenie ładowni i wycena'), podsumowanie, wycena, problemy),
        dziennik.length ? h('div.panel', h('h2', 'Dziennik obiegów'), h('ul.lista', dziennik)) : null,
        edytowalny ? h('div.rzad', { style: 'margin-top:14px' },
          h('button.przycisk', { onclick: () => zapisz('szlak_zglos') }, 'Złóż plan lotu'),
          h('button.przycisk.drugi', { onclick: () => zapisz('szlak_zapisz') }, 'Zapisz szkic'),
          k.id ? h('button.przycisk.zly', { onclick: async () => { if (await wykonaj('szlak_usun', { id: k.id }, 'Plan usunięty.')) panel.zamknij(); } }, 'Usuń') : null) : null,
      );
      sprawdz();
      return widok;
    }

    rysuj();
    return () => clearTimeout(licznikSprawdzania);
  },
};

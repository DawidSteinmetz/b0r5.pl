// Profil: agencja handlowa. Dla postronnych: pośrednictwo w kontraktach handlowych.
// Dla wtajemniczonych: narzędzie do kontaktu, kontroli i rozliczeń z agentami w terenie.
// Celowo żadnych słów, które zdradzałyby, kto naprawdę stoi za agencją.

import transport from './transport.js';
import { zakladkaMisji } from './komorka.js';

const MISJE_HANDLOWE = zakladkaMisji({
  status: {
    szkic: ['Szkic', ''],
    zgloszona: ['Oczekuje na akceptację centrali', 'status-niepewny'],
    zatwierdzona: ['Zaakceptowany · w realizacji', 'status-dostepny'],
    rozliczona: ['Rozliczony', 'slaby'],
    odrzucona: ['Odrzucony', 'status-nieobecny'],
  },
  nowa: 'Nowy kontrakt',
  pusta: 'Brak kontraktów.',
  tytul: 'Kontrakt',
  nazwa: 'Nazwa kontraktu',
  nazwaPrzyklad: 'np. Dostawa zboża — Mos Eisley',
  legenda: 'Warunki oficjalne (ładunek, trasa, odbiorca)',
  legendaPrzyklad: '20 t zboża dla kantyny w porcie',
  cel: 'Uwagi poufne',
  celPrzyklad: 'Co kontrahent ma naprawdę załatwić',
  rejon: 'Port docelowy',
  statki: 'Statki',
  ludzie: 'Kontrahenci',
  przekaz: 'Zarejestruj kontrakt',
  przekazano: 'Kontrakt zarejestrowany. Oczekuje na akceptację centrali.',
  uwagi: 'Centrala',
});

export default {
  nazwa: 'Agencja handlowa',

  uruchom(obszar, f) {
    return transport.uruchom(obszar, f, {
      kolejnosc: [
        ['zaloga', 'Kontrahenci'],
        ['flota', 'Statki'],
        ['misje', 'Kontrakty'],
        ['monitoring', 'Monitoring'],
        ['finanse', 'Rozliczenia'],
      ],
      zakladki: { misje: MISJE_HANDLOWE, monitoring },
      akcjeOsoby: rozliczenie,
      tekst: { zalogaPusta: 'Brak kontrahentów.', kandydaci: 'Kandydaci do współpracy' },
    });
  },
};

// Przycisk „Rozlicz” przy kontrahencie: wypłata z rachunku agencji.
function rozliczenie(z, p) {
  const { h, f } = p;
  if (!f.moze) return null;
  return h('button.przycisk.drugi.maly', {
    onclick: () => {
      const kwota = h('input', { type: 'number', min: 1, step: 1 });
      const tytul = h('input', { value: 'Prowizja handlowa', maxlength: 140 });
      const okno = h('div.panel', { style: 'border-color:var(--akcent);margin-bottom:14px' },
        h('h2', `Rozliczenie: ${z.kryptonim || z.imie}`),
        h('div.slaby.maly-tekst', { style: 'margin-bottom:10px' },
          z.gracz ? 'Przelew na rachunek kontrahenta.' : 'Wypłata gotówkowa (środki schodzą z rachunku agencji).',
          ` Na rachunku agencji: ${p.finanse?.konto?.saldo ?? 0} kr.`),
        h('div.dwie-kolumny', { style: 'grid-template-columns:1fr 2fr' },
          h('div.pole', h('label', 'Kwota'), kwota), h('div.pole', h('label', 'Tytuł'), tytul)),
        h('div.rzad',
          h('button.przycisk.maly', {
            onclick: () => {
              const k = Math.floor(Number(kwota.value));
              if (!(k > 0)) return;
              p.wykonaj('wyplata', { kontrahent: z.id, kwota: k, tytul: tytul.value }, `Rozliczono: ${z.kryptonim || z.imie}, ${k} kr.`);
            },
          }, 'Wypłać'),
          h('button.przycisk.drugi.maly', { onclick: () => okno.remove() }, 'Anuluj')));
      okno.scrollIntoView?.({ block: 'nearest' });
      p.wstaw(okno);
      kwota.focus();
    },
  }, 'Rozlicz');
}

// Monitoring: teczki obserwowanych celów i meldunki z terenu (dopisuje je centrala, czyli MG).
function monitoring(p) {
  const { h, f, dane } = p;
  const cele = [...(dane.cele || [])].sort((a, b) => (a.status === b.status ? (b.utworzono || '').localeCompare(a.utworzono || '') : a.status === 'aktywny' ? -1 : 1));
  const nazwa = h('input', { maxlength: 120, placeholder: 'osoba, statek, lokal, organizacja...' });
  const opis = h('textarea', { maxlength: 2000, placeholder: 'Powód obserwacji, czego szukamy', style: 'min-height:70px' });
  const czas = (iso) => new Date(iso).toLocaleString('pl-PL', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

  return h('div',
    f.moze ? h('form.panel', {
      onsubmit: async (e) => {
        e.preventDefault();
        await p.wykonaj('cel_dodaj', { nazwa: nazwa.value, opis: opis.value }, 'Założono teczkę.');
      },
    }, h('h2', 'Nowa teczka'), h('div.pole', h('label', 'Obiekt'), nazwa), h('div.pole', h('label', 'Uzasadnienie'), opis),
    h('button.przycisk', { type: 'submit' }, 'Załóż teczkę')) : null,
    cele.length ? cele.map((c) => h('div.panel', { style: c.status === 'zamkniety' ? 'opacity:.6' : '' },
      h('div.rzad',
        h('h2.rozpycha', { style: 'margin:0' }, c.nazwa),
        h('span.maly-tekst.' + (c.status === 'aktywny' ? 'status-dostepny' : 'slaby'), c.status === 'aktywny' ? 'w obserwacji' : 'zamknięta'),
        f.moze ? h('button.przycisk.drugi.maly', {
          onclick: () => p.wykonaj('cel_status', { id: c.id, status: c.status === 'aktywny' ? 'zamkniety' : 'aktywny' }),
        }, c.status === 'aktywny' ? 'Zamknij' : 'Wznów') : null),
      h('div.slaby.maly-tekst', { style: 'margin:4px 0 8px' }, `Założył(a): ${c.autor} · ${czas(c.utworzono)}`),
      c.opis ? h('p', { style: 'margin:0 0 10px' }, c.opis) : null,
      c.meldunki.length
        ? h('ul.lista', [...c.meldunki].reverse().map((m) => h('li',
          h('div.slaby.maly-tekst', `${czas(m.data)}${m.zrodlo ? ' · źródło: ' + m.zrodlo : ''}`),
          h('div.dokument-tresc', { style: 'min-height:0' }, m.tresc))))
        : h('div.slaby.maly-tekst', 'Brak meldunków.')))
      : h('div.panel', h('div.pusto', 'Brak teczek.')));
}

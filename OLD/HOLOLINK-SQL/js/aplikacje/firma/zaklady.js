// Wspólna karta działalności dla zakładów wydobywczych, produkcyjnych i finansowych.
export function kartaZakladow(p, tytul, opis) {
  const { h, dane, nazwaPlanety, towary } = p;
  const lista = dane.zaklady || [];
  const nazwaTowaru = (id) => towary[id]?.nazwa || '—';
  const status = { czynny: 'czynny', wstrzymany: 'wstrzymany', zamkniety: 'zamknięty' };
  return h('div',
    h('div.panel', h('h2', tytul), h('p.slaby', opis),
      lista.length
        ? h('div.karty', lista.map((z) => h('div.panel',
          h('div.rzad', h('div.rozpycha', h('b', z.nazwa),
            h('div.slaby.maly-tekst', [z.typ, z.planeta ? nazwaPlanety(z.planeta) : '', z.towar ? nazwaTowaru(z.towar) : ''].filter(Boolean).join(' · '))),
            h('span.maly-tekst', status[z.stan] || 'czynny')),
          z.wydajnosc ? h('div.slaby.maly-tekst', `Wydajność: ${Number(z.wydajnosc).toLocaleString('pl-PL')} jednostek na cykl`) : null,
          z.opis ? h('p.slaby', z.opis) : null)))
        : h('div.pusto', 'Nie dodano jeszcze zakładów. Szczegóły zakładów uzupełnia MG w Katalogu.'))));
}

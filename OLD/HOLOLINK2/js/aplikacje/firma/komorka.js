// Profil: komórka (np. komórka Rebelii). Panel jak w firmie przewozowej (flota, magazyn,
// załoga, legalne kursy handlowe, budynki, finanse) plus Misje.
// Zakładkę misji (z innymi napisami i legendą) wykorzystuje też profil agencji.

import transport from './transport.js';

const STATUS_MISJI = {
  szkic: ['Szkic', ''],
  zgloszona: ['Czeka na zatwierdzenie dowództwa', 'status-niepewny'],
  zatwierdzona: ['Zatwierdzona · siły w gotowości', 'status-dostepny'],
  rozliczona: ['Zakończona', 'slaby'],
  odrzucona: ['Odrzucona', 'status-nieobecny'],
};

const WYNIK = {
  sprawny: 'wrócił sprawny', uszkodzony: 'uszkodzony', zniszczony: 'zniszczony', przejety: 'utracony',
  zdrowy: 'cały i zdrowy', ranny: 'ranny', zginal: 'poległ', spalony: 'zdemaskowany', zatrzymany: 'zatrzymany',
};

// Napisy zakładki misji; agencja podmienia je na „handlowe”.
const OPCJE_KOMORKI = {
  status: STATUS_MISJI,
  nowa: 'Nowa misja',
  pusta: 'Brak misji.',
  legenda: false,
  nazwa: 'Kryptonim',
  nazwaPrzyklad: 'np. Operacja Cichy Świt',
  cel: 'Cel i plan',
  celPrzyklad: 'Cel, plan działania, czego potrzebujecie od dowództwa',
  rejon: 'Rejon działań',
  statki: 'Statki',
  ludzie: 'Ludzie',
  przekaz: 'Przekaż rozkaz dowództwu',
  przekazano: 'Rozkaz misji przekazany dowództwu.',
  uwagi: 'Dowództwo',
  tytul: 'Rozkaz misji',
};

export function zakladkaMisji(opcje = {}) {
  const o = { ...OPCJE_KOMORKI, ...opcje };
  return (p) => misje(p, o);
}

export default {
  nazwa: 'Komórka',

  uruchom(obszar, f) {
    return transport.uruchom(obszar, f, {
      nazwyZakladek: [['misje', 'Misje']],
      zakladki: { misje: zakladkaMisji() },
    });
  },
};

// ---------- misje ----------

function misje(p, o) {
  const { h, dane, f } = p;
  const lista = [...dane.misje].sort((a, b) => (b.zmieniono || '').localeCompare(a.zmieniono || ''));
  return h('div',
    f.moze ? h('div', { style: 'margin-bottom:14px' }, h('button.przycisk', { onclick: () => { const m = nowaMisja(); p.otworz(() => edytorMisji(p, m, o)); } }, o.nowa)) : null,
    lista.length
      ? h('div.panel', h('ul.lista', lista.map((m) => {
        const [opis, klasa] = o.status[m.status] || [m.status, ''];
        const edytowalna = f.moze && ['szkic', 'odrzucona'].includes(m.status);
        return h('li', h('div.rzad',
          h('div.rozpycha',
            h('div', m.nazwa),
            h('div.slaby.maly-tekst', [m.termin && new Date(m.termin).toLocaleDateString('pl-PL'), p.nazwaPlanety(m.planeta), `${m.statki.length} stat. · ${m.zaloga.length} os.`].filter((x) => x && x !== '—').join(' · ')),
            m.uwagi ? h('div.maly-tekst', { style: 'color:var(--akcent-2)' }, `${o.uwagi}: ${m.uwagi}`) : null),
          h('span.maly-tekst' + (klasa ? '.' + klasa : ''), opis),
          h('button.przycisk.drugi.maly', {
            onclick: () => {
              const kopia = JSON.parse(JSON.stringify(m));
              p.otworz(() => (m.status === 'rozliczona' ? raport(p, kopia) : edytorMisji(p, kopia, o)));
            },
          }, m.status === 'rozliczona' ? 'Raport' : edytowalna ? 'Edytuj' : 'Podgląd')));
      })))
      : h('div.panel', h('div.pusto', o.pusta)));
}

function nowaMisja() {
  const jutro = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
  return { id: '', nazwa: '', cel: '', przykrywka: '', planeta: '', termin: jutro, statki: [], zaloga: [], status: 'szkic' };
}

function edytorMisji(p, m, o) {
  const { h, dane, f } = p;
  const edytowalna = f.moze && ['szkic', 'odrzucona'].includes(m.status);
  const problemy = h('div');
  const pole = (etykieta, el) => h('div.pole', h('label', etykieta), el);

  const nazwa = h('input', { value: m.nazwa, maxlength: 80, disabled: !edytowalna, placeholder: o.nazwaPrzyklad });
  nazwa.addEventListener('input', () => { m.nazwa = nazwa.value; });
  const termin = h('input', { type: 'date', value: m.termin, disabled: !edytowalna });
  termin.addEventListener('input', () => { m.termin = termin.value; });
  const cel = h('textarea', { maxlength: 2000, disabled: !edytowalna, placeholder: o.celPrzyklad }, m.cel);
  cel.addEventListener('input', () => { m.cel = cel.value; });
  const legenda = h('textarea', { maxlength: 1000, disabled: !edytowalna, placeholder: o.legendaPrzyklad || '', style: 'min-height:70px' }, m.przykrywka || '');
  legenda.addEventListener('input', () => { m.przykrywka = legenda.value; });
  const planeta = h('select', { disabled: !edytowalna },
    h('option', { value: '' }, 'nie dotyczy / tajne'),
    p.f.katalog.planety.map((x) => h('option', { value: x.id, selected: x.id === m.planeta }, x.nazwa)));
  planeta.addEventListener('change', () => { m.planeta = planeta.value; });

  // Wybór sił: pola wyboru przy statkach i ludziach.
  const wybor = (lista, klucz, opis) => h('div', lista.map((x) => {
    const pole2 = h('input', { type: 'checkbox', checked: m[klucz].includes(x.id), disabled: !edytowalna });
    pole2.addEventListener('change', () => {
      m[klucz] = pole2.checked ? [...m[klucz], x.id] : m[klucz].filter((i) => i !== x.id);
    });
    return h('label.rzad', { style: 'min-height:40px;cursor:pointer' }, pole2, h('span', opis(x)));
  }));
  const statki = wybor(dane.flota, 'statki', (s) => `${s.nazwa} · ${s.klasa || ''} · ${p.nazwaPlanety(s.lokalizacja)} · ${s.stan}`);
  const ludzie = wybor(dane.zaloga, 'zaloga', (z) => `${z.kryptonim ? z.kryptonim + ' · ' : ''}${z.imie} · ${z.funkcja || ''}${z.stan && z.stan !== 'zdrowy' ? ' · ' + z.stan : ''}`);

  const pokazProblemy = (tytul, lista) => p.wyczysc(problemy).append(h('div.komunikat.blad', h('div', tytul),
    lista?.length ? h('ul', { style: 'margin:6px 0 0;padding-left:18px' }, lista.map((x) => h('li', x))) : null));

  const wyslij = async (op) => {
    p.wyczysc(problemy);
    let w;
    try {
      w = await f.operacja(op, { misja: m });
    } catch (e) {
      pokazProblemy(e.message, e.problemy);
      return;
    }
    m.id = w.id;
    await p.odswiez();
    if (op === 'misja_zglos') {
      p.zamknij();
      p.pokaz(o.przekazano, 'ok');
    } else {
      p.pokaz('Szkic zapisany.', 'ok');
    }
  };

  return h('div',
    h('div.panel',
      h('h2', m.id ? o.tytul : o.nowa),
      m.uwagi ? h('div.komunikat.blad', `${o.uwagi}: ${m.uwagi}`) : null,
      pole(o.nazwa, nazwa),
      h('div.dwie-kolumny', { style: 'grid-template-columns:1fr 1fr' }, pole('Termin', termin), pole(o.rejon, planeta)),
      o.legenda ? pole(o.legenda, legenda) : null,
      pole(o.cel, cel)),
    h('div.dwie-kolumny', { style: 'grid-template-columns:1fr 1fr;margin-top:14px' },
      h('div.panel', h('h2', o.statki), dane.flota.length ? statki : h('div.slaby', 'Brak statków.')),
      h('div.panel', h('h2', o.ludzie), dane.zaloga.length ? ludzie : h('div.slaby', 'Brak ludzi.'))),
    problemy,
    edytowalna ? h('div.rzad', { style: 'margin-top:14px' },
      h('button.przycisk', { onclick: () => wyslij('misja_zglos') }, o.przekaz),
      h('button.przycisk.drugi', { onclick: () => wyslij('misja_zapisz') }, 'Zapisz szkic'),
      m.id ? h('button.przycisk.zly', { onclick: async () => { if (await p.wykonaj('misja_usun', { id: m.id }, 'Misja usunięta.')) p.zamknij(); } }, 'Usuń') : null) : null);
}

function raport(p, m) {
  const { h } = p;
  const lista = (tytul, el) => (el.length ? h('div.panel', h('h2', tytul), h('ul.lista', el)) : null);
  return h('div',
    h('div.panel',
      h('h2', `Raport: ${m.nazwa}`),
      h('div.slaby.maly-tekst', { style: 'margin-bottom:10px' }, [m.termin && new Date(m.termin).toLocaleDateString('pl-PL'), p.nazwaPlanety(m.planeta)].filter((x) => x && x !== '—').join(' · ')),
      m.przykrywka ? h('div.slaby.maly-tekst', { style: 'margin-bottom:8px' }, 'Legenda: ' + m.przykrywka) : null,
      h('div.dokument-tresc', m.raport || 'Brak raportu.')),
    lista('Statki', (m.straty?.statki || []).map((s) => h('li', h('div.rzad', h('span.rozpycha', s.nazwa),
      h('span.' + (s.wynik === 'sprawny' ? 'status-dostepny' : s.wynik === 'uszkodzony' ? 'status-niepewny' : 'status-nieobecny'), WYNIK[s.wynik] || s.wynik))))),
    lista('Ludzie', (m.straty?.zaloga || []).map((z) => h('li', h('div.rzad', h('span.rozpycha', z.imie),
      h('span.' + (z.wynik === 'zdrowy' ? 'status-dostepny' : z.wynik === 'ranny' ? 'status-niepewny' : 'status-nieobecny'), WYNIK[z.wynik] || z.wynik))))),
    lista('Zmiany w magazynach', (m.lup || []).map((l) => h('li', h('div.rzad',
      h('span.rozpycha', `${p.towary[l.towar]?.nazwa || '?'} · ${p.nazwaPlanety(l.planeta)}`),
      h('span.' + (l.ilosc > 0 ? 'bank-plus' : 'status-nieobecny'), `${l.ilosc > 0 ? '+' : ''}${l.ilosc} ${p.towary[l.towar]?.jednostka || ''}`))))));
}

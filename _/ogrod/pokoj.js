/* Mechanika ogrodu: pogoda, grządki z czarami i skrzynka z nasionami u trzech dostawców.
   Plik należy do pokoju (pokoj.json: "skrypt"), a z rdzenia korzysta tylko przez window.Mysl. Serwer: api/ogrod.php. */
(function () {
  'use strict';
  const { $, esc, w, modal, gracz, arkusz, zamknij, tresc, odswiez, URL_POKOJU: U } = window.Mysl;
  let okno = null, ostatni = null;
  async function api(akcja, d) {
    const r = await fetch(U + 'api/ogrod.php', {
      method: akcja ? 'POST' : 'GET', cache: 'no-store', headers: { 'Content-Type': 'application/json' },
      body: akcja ? JSON.stringify(Object.assign({ akcja, gracz }, d || {})) : undefined
    });
    const j = await r.json();
    if (j.blad === 'zbyt szybko') j.tekst = j.tekst || 'Spokojnie, w ogrodzie nic nie dzieje się tak szybko.';
    return j;
  }
  const liczba = (x, d = 1) => String(Math.round(x * 10 ** d) / 10 ** d).replace('.', ',').replace('-', '−');
  const st = x => liczba(x, 0) + '°C';

  /* Komunikat nad numerem strony (taki sam jak w piwnicy) */
  let czasTostu = 0;
  function kom(t) {
    let e = $('#tost');
    if (!e) { e = document.createElement('div'); e.id = 'tost'; e.setAttribute('role', 'status'); $('#sheet').appendChild(e); }
    e.textContent = t || '';
    e.classList.toggle('on', !!t);
    clearTimeout(czasTostu);
    czasTostu = setTimeout(() => e.classList.remove('on'), 6000);
  }

  /* Niebo nad ogrodem: deszcz, śnieg, mgła albo noc na całej stronie pokoju */
  function niebo(p) {
    if (!p || !p.teraz) return;
    const t = p.teraz, b = document.body;
    b.classList.toggle('o-deszcz', /deszcz|mżawka|burza/.test(t.pada));
    b.classList.toggle('o-mzawka', t.pada === 'mżawka');
    b.classList.toggle('o-snieg', /snieg|śniegiem/.test(t.pada));
    b.classList.toggle('o-mgla', !!t.mgla);
    b.classList.toggle('o-noc', !!t.noc);
  }

  /* Wspólny szkielet okna: tytuł, opis i miejsce na treść; rysuj() odświeża się sam i tylko przy zmianie stanu */
  function okienko(o, rysujStan, wybierzSig) {
    okno = o; arkusz('ks'); kom('');
    w.innerHTML = '<h2>' + esc(o.e.tytul) + '</h2><div class="tekst"></div><div id="tr"></div><button id="x">Zamknij</button>';
    $('#x').onclick = zamknij; modal.classList.add('on');
    tresc(o.e, w.querySelector('.tekst'));
    let ost = '';
    const rysuj = async force => {
      let s;
      try { s = await api(); } catch (err) { kom('Wiatr porwał kartkę.'); return; }
      if (!s || !s.grzadki || okno !== o) return;
      ostatni = s; niebo(s.pogoda);
      const sig = JSON.stringify(wybierzSig(s));
      if (!force && sig === ost) return;
      ost = sig;
      rysujStan(s, $('#tr'), akcja);
    };
    const akcja = async (a, d) => {
      try {
        const r = await api(a, d); kom(r.tekst || '');
        if (r.stan) { ostatni = r.stan; ost = JSON.stringify(wybierzSig(r.stan)); rysujStan(r.stan, $('#tr'), akcja); }
      } catch (err) { kom('Wiatr porwał kartkę.'); }
      odswiez();
    };
    rysuj(true);
    const id = setInterval(() => {
      if (!modal.classList.contains('on') || okno !== o) return clearInterval(id);
      rysuj(false);
    }, 4000);
  }

  /* --- Termometr i barometr ----------------------------------------------------------------- */
  function otworzPogode(o) {
    okienko(o, (s, el) => {
      const p = s.pogoda, t = p.teraz, d = p.dzis;
      el.innerHTML = '<div class="termo"><b>' + esc(t.temp_t) + '</b><span>' + esc(t.opis) + '</span></div>' +
        '<p class="dim">' + esc(t.wiatr_t) + '</p>' +
        '<p>Dziś od ' + st(d.tmin) + ' do ' + st(d.tmax) + ': ' + esc(d.opis) + '.<br>Słońce wschodzi o ' + esc(d.wschod) + ', zachodzi o ' + esc(d.zachod) + '.</p>' +
        '<p>Ziemia: <b>' + esc(d.ziemia) + '</b>' + (d.snieg_cm > 0 ? ', śniegu ' + d.snieg_cm + ' cm' : '') + '.</p>' +
        '<p class="baro"><i>' + esc(p.zapowiedz) + '</i></p>' +
        (s.oslona_do ? '<p class="dim">Nad grządkami wisi osłona przed przymrozkiem.</p>' : '') +
        '<p class="grupa">Ostatnie dni (zapiski Henryka)</p><table class="dni">' + p.ostatnie.map(x =>
          '<tr><td>' + new Date(x.data + 'T12:00').toLocaleDateString('pl', { weekday: 'short', day: 'numeric', month: 'short' }) + '</td><td>' + st(x.tmin) + ' / ' + st(x.tmax) + '</td><td>' + esc(x.opis) + '</td></tr>').join('') + '</table>';
    }, s => [s.pogoda, s.oslona_do]);
  }

  /* --- Grządki ------------------------------------------------------------------------------ */
  let wybor = null;            // {g: id grządki} albo {m: numer wolnego miejsca}
  const KOLOR = { pusta: '#7a4e24', rosnie: '#4f7a2e', do_zbioru: '#c79a2a', martwa: '#6d6458', czar: '#8a6bb0' };
  function plan(s) {
    const D = s.dzialka, zaj = {};
    s.grzadki.forEach(g => { zaj[g.miejsce] = g; });
    let h = '<svg class="plan" viewBox="-0.6 -0.6 ' + (D.dl + 1.2) + ' ' + (D.szer + 1.2) + '" role="img" aria-label="Plan działki">' +
      '<rect class="dzialka" x="0" y="0" width="' + D.dl + '" height="' + D.szer + '"/>';
    D.zabudowa.forEach(z => {
      h += '<rect class="dom" x="' + z.x + '" y="' + z.y + '" width="' + z.dl + '" height="' + z.szer + '"/>' +
        '<text x="' + (z.x + z.dl / 2) + '" y="' + (z.y + z.szer / 2 + 0.6) + '">' + esc(z.nazwa.toLowerCase()) + '</text>';
    });
    h += '<text class="furtka" x="' + (D.dl / 2) + '" y="' + (D.szer + 0.55) + '">furtka</text>';
    D.miejsca.forEach((m, i) => {
      const g = zaj[i], wyb = wybor && ((g && wybor.g === g.id) || (!g && wybor.m === i));
      if (!g) {
        h += '<rect class="miejsce' + (wyb ? ' wyb' : '') + '" data-m="' + i + '" x="' + m.x + '" y="' + m.y + '" width="' + m.dl + '" height="' + m.szer + '"><title>wolne miejsce</title></rect>';
        return;
      }
      const stan = g.czar ? 'czar' : g.stan, r = g.roslina;
      const op = stan === 'rosnie' ? (0.35 + 0.65 * (r.postep || 0)).toFixed(2) : 1;
      h += '<rect class="grz ' + stan + (wyb ? ' wyb' : '') + '" data-g="' + esc(g.id) + '" x="' + m.x + '" y="' + m.y + '" width="' + m.dl + '" height="' + m.szer +
        '" style="fill:' + KOLOR[stan] + ';fill-opacity:' + op + '"><title>' + esc(r ? r.nazwa + ': ' + r.faza_t : (g.czar ? g.stan : 'pusta grządka')) + '</title></rect>';
      if (g.chwasty > 0.4) h += '<rect class="chwast" x="' + m.x + '" y="' + m.y + '" width="' + m.dl + '" height="' + m.szer + '" pointer-events="none"/>';
    });
    return h + '<defs><pattern id="chw" width="0.6" height="0.6" patternUnits="userSpaceOnUse"><circle cx="0.3" cy="0.3" r="0.12" fill="#2e4a1a"/></pattern></defs></svg>';
  }
  const przycisk = (a, t, d, wyl) => '<button type="button" data-a="' + a + '"' + (d ? " data-d='" + esc(JSON.stringify(d)) + "'" : '') + (wyl ? ' disabled' : '') + '>' + t + '</button>';
  function szczegoly(s) {
    const czar = ef => s.czary.find(c => c.efekt === ef);
    if (wybor && wybor.m !== undefined) {
      const c = czar('nowa_grzadka'), m2 = liczba(s.dzialka.m2_grzadki, 1);
      return '<p class="grupa">Wolne miejsce ' + (wybor.m + 1) + '</p>' +
        (c ? '<p>Tu zmieści się grządka ' + m2 + ' m².</p>' + przycisk('czar', c.nazwa + ' <small>(' + esc(c.pl) + ')</small>', { czar: c.id, miejsce: wybor.m }, !!s.zmarznieta)
          : '<p><i>Henryk nie zna czaru, który przygotowałby tu grządkę.</i></p>') +
        (s.zmarznieta ? '<p class="dim">' + esc(s.zmarznieta) + '</p>' : '');
    }
    const g = wybor && s.grzadki.find(x => x.id === wybor.g);
    if (!g) return '<p class="dim">Wskaż grządkę albo wolne miejsce na planie.</p>';
    let h = '<p class="grupa">Grządka ' + (g.miejsce + 1) + '</p>';
    if (g.czar) return h + '<p><i>' + esc(g.stan) + ', gotowa ' + esc(g.za) + '.</i></p>';
    const r = g.roslina;
    if (r) {
      h += '<p><b>' + esc(r.nazwa) + '</b>' + (r.wieloletnia ? ' <small>(bylina' + (r.wiek ? ', ' + r.wiek + '. rok' : ', pierwszy rok') + ')</small>' : '') + '<br>' + esc(r.faza_t);
      if (!r.martwa) h += ' · ' + esc(r.zdrowie_t) + (r.faza === 'rosnie' && !r.do_zbioru && r.postep < 1 ? ' · do zbioru ' + Math.round(r.postep * 100) + '%' : '');
      h += '</p>';
      if (!r.martwa && r.powod && !r.do_zbioru && r.postep >= 1) h += '<p class="dim">' + esc(r.powod) + '</p>';
      if (r.siew) h += '<p class="dim">' + esc(r.siew) + '</p>';
    } else h += '<p>Pusta grządka.' + (g.notatka ? ' <i>' + esc(g.notatka) + '</i>' : '') + '</p>';
    h += '<p>Ziemia: ' + esc(g.wilgoc_t) + ' · ' + esc(g.chwasty_t) + '</p><div class="akcje">';
    if (r && !r.martwa) h += przycisk('zbierz', 'Zbierz', { id: g.id }, !r.do_zbioru);
    h += przycisk('podlej', 'Podlej', { id: g.id }, !!s.zmarznieta) + przycisk('plej', 'Pleć', { id: g.id }, !!s.zmarznieta || g.chwasty < 0.1);
    if (r) h += przycisk('wyrwij', r.martwa ? 'Uprzątnij' : 'Wyrwij', { id: g.id });
    h += '</div>';
    if (!r) {
      const pacz = s.skrzynka.filter(p => p.n > 0);
      h += pacz.length ? '<p class="grupa">Posadź ze skrzynki</p>' + pacz.map(p =>
        '<div class="rzad"><span>' + esc(p.nazwa) + ' <small>×' + p.n + ' · ' + esc(p.jakosc_t) + '</small></span><span>' +
        przycisk('posadz', p.forma === 'sadzonki' ? 'Posadź' : 'Posiej', { id: g.id, paczka: p.klucz }, !!s.zmarznieta) + '</span></div>').join('')
        : '<p class="dim">Skrzynka z nasionami jest pusta.</p>';
      const c = czar('likwidacja');
      if (c) h += '<p>' + przycisk('czar', c.nazwa + ' <small>(' + esc(c.pl) + ')</small>', { czar: c.id, id: g.id }) + '</p>';
    }
    return h;
  }
  function otworzGrzadki(o) {
    const rysuj = (s, el, akcja) => {
      const D = s.dzialka, t = s.pogoda.teraz, zajete = D.grzadek * D.m2_grzadki;
      const zbiorcze = s.czary.filter(c => ['podlewa', 'pleje', 'oslona'].includes(c.efekt));
      el.innerHTML = '<p class="dim">' + esc(t.temp_t) + ', ' + esc(t.opis) + ' · ziemia ' + esc(s.pogoda.dzis.ziemia) + '</p>' + plan(s) +
        '<p class="dim">Grządek ' + D.grzadek + ' z ' + D.miejsc + ' możliwych (' + liczba(zajete, 0) + ' m²). Działka ' + D.dl + '×' + D.szer + ' m, dom ' +
        D.zabudowa.map(z => z.dl + '×' + z.szer).join(', ') + ' m.</p>' +
        '<div id="szcz">' + szczegoly(s) + '</div>' +
        (zbiorcze.length ? '<p class="grupa">Czary na cały ogród</p><div class="akcje">' + zbiorcze.map(c => przycisk('czar', c.nazwa + ' <small>(' + esc(c.pl) + ')</small>', { czar: c.id })).join('') + '</div>' : '') +
        (s.suszy_sie.length ? '<p class="grupa">Schnie na strychu</p>' + s.suszy_sie.map(x => '<div class="dim">' + esc(x.nazwa) + ' ×' + x.n + ', do Składu ' + esc(x.za) + '</div>').join('') : '');
      el.querySelector('.plan').onclick = e => {
        const r = e.target.closest('[data-g],[data-m]'); if (!r) return;
        wybor = r.dataset.g ? { g: r.dataset.g } : { m: Number(r.dataset.m) };
        rysuj(s, el, akcja);
      };
      el.querySelectorAll('button[data-a]').forEach(b => {
        b.onclick = () => {
          const d = b.dataset.d ? JSON.parse(b.dataset.d) : {};
          if (b.dataset.a === 'czar' && d.miejsce !== undefined) wybor = { nowe: d.miejsce };
          akcja(b.dataset.a, d);
        };
      });
    };
    okienko(o, (s, el, akcja) => {
      if (wybor && wybor.nowe !== undefined) {         // świeżo zaczarowane miejsce staje się grządką: zaznacz ją
        const g = s.grzadki.find(x => x.miejsce === wybor.nowe);
        wybor = g ? { g: g.id } : { m: wybor.nowe };
      }
      if (wybor && wybor.g && !s.grzadki.some(g => g.id === wybor.g)) wybor = null;
      if (!wybor) { const g = s.grzadki.find(x => x.stan === 'do_zbioru') || s.grzadki[0]; if (g) wybor = { g: g.id }; }
      rysuj(s, el, akcja);
    }, s => [s.grzadki, s.skrzynka, s.zmarznieta, s.suszy_sie.length, s.pogoda.teraz.temp_t, s.pogoda.teraz.opis]);
  }

  /* --- Skrzynka z nasionami i zakupy ------------------------------------------------------- */
  const zam = {};              // dostawca -> {towar: liczba porcji}
  function otworzSkrzynke(o) {
    okienko(o, (s, el, akcja) => {
      const sk = s.sklep, lim = sk.limity;
      let h = s.skrzynka.length ? '<p class="grupa">W skrzynce</p>' + s.skrzynka.map(p =>
        '<div class="rzad"><span><b>' + esc(p.nazwa) + '</b> ×' + p.n + '<br><small>' + esc(p.forma) + ' · ' + esc(p.jakosc_t) + (p.siew ? ' · ' + esc(p.siew) : '') + '</small></span></div>').join('')
        : '<p><i>Skrzynka jest pusta.</i></p>';
      if (s.w_drodze.length) h += '<p class="grupa">W drodze</p>' + s.w_drodze.map(x => '<div class="dim">' + esc(x.opis) + ', ' + esc(x.za) + '</div>').join('');
      if (!sk.kasa) h += '<p class="dim">Nie ma z czego płacić: księga rachunkowa jest gdzie indziej.</p>';
      else h += '<p class="kasa">W kasie: <b>' + esc(sk.saldo_t) + '</b></p>';
      sk.dostawcy.forEach(d => {
        const z = zam[d.id] = zam[d.id] || {};
        h += '<div class="dostawca" data-d="' + esc(d.id) + '"><p class="grupa">' + esc(d.nazwa) + '</p><p class="dim">' + esc(d.opis) + '</p>';
        if (!d.otwarte) h += '<p><i>' + esc(d.powod) + '</i></p>';
        const jest = d.towary.filter(t => t.jest), nie = d.towary.filter(t => !t.jest);
        if (jest.length) h += '<table class="cennik">' + jest.map(t =>
          '<tr class="poz" data-id="' + esc(t.id) + '"><td class="naz">' + esc(t.nazwa) + ' <i>(' + esc(t.forma) + ')</i></td><td class="cena">' + esc(t.cena_t) + '</td>' +
          '<td class="krok"><button type="button" data-z="-1" aria-label="Mniej: ' + esc(t.nazwa) + '">−</button><output>' + (z[t.id] || 0) + '</output>' +
          '<button type="button" data-z="1" aria-label="Więcej: ' + esc(t.nazwa) + '">+</button></td></tr>').join('') + '</table>' +
          '<div class="zamow"><span class="ile"></span><button type="button" class="kup">Zamów (' + esc(d.dostawa) + ')</button></div>';
        else h += '<p class="dim">O tej porze roku nic tu nie ma.</p>';
        if (nie.length) h += '<p class="dim">Kiedy indziej: ' + nie.map(t => esc(t.nazwa.toLowerCase())).join(', ') + '.</p>';
        h += '</div>';
      });
      el.innerHTML = h;
      el.querySelectorAll('.dostawca').forEach(box => {
        const id = box.dataset.d, d = sk.dostawcy.find(x => x.id === id), z = zam[id];
        const kup = box.querySelector('.kup'); if (!kup) return;
        const liczniki = () => {
          const razem = Object.values(z).reduce((a, b) => a + b, 0);
          box.querySelectorAll('.poz').forEach(p => {
            const n = z[p.dataset.id] || 0;
            p.classList.toggle('wybrany', n > 0);
            p.querySelector('output').textContent = n;
            p.querySelector('[data-z="-1"]').disabled = n < 1;
            p.querySelector('[data-z="1"]').disabled = n >= lim.max_pozycja || razem >= lim.max_razem;
          });
          box.querySelector('.ile').textContent = razem ? 'Na liście: ' + razem : '';
          kup.disabled = razem < 1 || !d.otwarte || !sk.kasa;
        };
        box.onclick = e => {
          const b = e.target.closest('button[data-z]'); if (!b) return;
          const t = b.closest('.poz').dataset.id;
          z[t] = Math.max(0, (z[t] || 0) + Number(b.dataset.z));
          liczniki();
        };
        kup.onclick = async () => {
          const tow = {}; Object.keys(z).forEach(t => { if (z[t] > 0) tow[t] = z[t]; });
          Object.keys(z).forEach(t => { delete z[t]; });
          await akcja('kup', { dostawca: id, towary: tow });
        };
        liczniki();
      });
    }, s => [s.skrzynka, s.w_drodze, s.sklep]);
  }

  /* Rejestracja rodzajów tego pokoju (nazwy są używane w pokoj.json, w polu "rodzaj" folderu) */
  const R = window.Mysl.rodzaj;
  const IKONA_GRZADKI = '<path d="M12 70h76M18 70l6-22h52l6 22M30 48v-8M30 40c-6-2-8-8-6-12 6 0 8 6 6 12M50 48v-12M50 36c-7-2-9-10-6-14 7 1 9 8 6 14M70 48v-8M70 40c6-2 8-8 6-12-6 0-8 6-6 12M24 82h52"/>';
  const IKONA_SKRZYNKA = '<path d="M16 42h68v40H16zM16 42l8-14h52l8 14M16 58h68M42 50h16"/><circle cx="30" cy="70" r="2"/><circle cx="70" cy="70" r="2"/>';
  const IKONA_POGODA = '<path d="M30 70h44a14 14 0 0 0 0-28 20 20 0 0 0-38-4 12 12 0 0 0-6 32z"/><path d="M38 78l-4 8M52 78l-4 8M66 78l-4 8"/>';
  R('grzadki', { v: .2, bob: .2, klasa: 'p kot', ikona: IKONA_GRZADKI, etykieta: 'ogród', otworz: otworzGrzadki });
  R('skrzynka', { v: .2, bob: .2, klasa: 'p kot', ikona: IKONA_SKRZYNKA, etykieta: 'skrzynka', otworz: otworzSkrzynke });
  R('pogoda', { v: .25, bob: .25, klasa: 'p kot', ikona: IKONA_POGODA, etykieta: 'pogoda', otworz: otworzPogode });

  /* Pogoda widoczna od razu po wejściu do ogrodu i odświeżana co kilka minut */
  const niebo_ = () => api().then(s => { ostatni = s; niebo(s.pogoda); }).catch(() => { });
  niebo_(); setInterval(niebo_, 300000);
})();

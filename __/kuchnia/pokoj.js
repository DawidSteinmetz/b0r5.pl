/* Mechanika piwnicy: Spiżarnia, stół z czarami, magiczna waga, garnek i zeszyt domowy.
   Plik należy do pokoju (pokoj.json: "skrypt"), a z rdzenia korzysta tylko przez window.Mysl. */
(function () {
  'use strict';
  const { $, esc, w, modal, gracz, arkusz, zamknij, tresc, odswiez, czary, URL_POKOJU: U } = window.Mysl;
  let okno = null;
  const liczba = (x, d = 4) => String(Math.round(x * 10 ** d) / 10 ** d).replace('.', ',');
  async function rynekApi(akcja, d) {            // zeszyt domowy (api/rynek.php)
    const r = await fetch(U + 'api/rynek.php', {
      method: akcja ? 'POST' : 'GET', cache: 'no-store', headers: { 'Content-Type': 'application/json' },
      body: akcja ? JSON.stringify(Object.assign({ akcja, gracz }, d || {})) : undefined
    });
    const j = await r.json();
    if (j.blad === 'zbyt szybko') j.tekst = 'Spokojnie, księga nie nadąża za tak szybkim piórem.';
    return j;
  }
  async function alch(akcja, d) {                 // alchemia (api/alchemia.php)
    const r = await fetch(U + 'api/alchemia.php', {
      method: akcja ? 'POST' : 'GET', cache: 'no-store', headers: { 'Content-Type': 'application/json' },
      body: akcja ? JSON.stringify(Object.assign({ akcja, gracz }, d || {})) : undefined
    });
    return r.json();
  }
  const tabelaSkladu = sk => '<table class="mole"><tr><th>Pierwiastek</th><th>mol</th><th>g</th></tr>' +
    Object.entries(sk).map(([e, x]) => '<tr><td>' + esc(x.nazwa) + ' (' + esc(e) + ')</td><td>' + liczba(x.mol) + '</td><td>' + liczba(x.g, 2) + '</td></tr>').join('') + '</table>';
  const krotko = sk => Object.entries(sk).map(([e, x]) => esc(e) + ' ' + liczba(x.mol)).join(' · ');
  /* proporcja atomów względem węgla; mapa: pierwiastek -> liczba moli */
  function proporcja(m) {
    const c = m.C || 0;
    if (c <= 0) return 'Bez węgla nie ma proporcji.';
    return ['C', 'H', 'O'].map(e => e + ' ' + liczba((m[e] || 0) / c, 2)).join(' : ');
  }
  const mole = sk => Object.fromEntries(Object.entries(sk).map(([e, x]) => [e, x.mol]));
  /* Komunikat nad numerem strony: zawsze widoczny, niezależnie od tego, na której stronie kartki jest gracz */
  let czasTostu = 0;
  function kom(t) {
    let e = $('#tost');
    if (!e) { e = document.createElement('div'); e.id = 'tost'; e.setAttribute('role', 'status'); $('#sheet').appendChild(e); }
    e.textContent = t || '';
    e.classList.toggle('on', !!t);
    clearTimeout(czasTostu);
    czasTostu = setTimeout(() => e.classList.remove('on'), 6000);
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
      try { s = await alch(); } catch (err) { kom('Dym zasłania stół.'); return; }
      if (!s || !s.sklad || okno !== o) return;
      const sig = JSON.stringify(wybierzSig(s));
      if (!force && sig === ost) return;
      ost = sig;
      rysujStan(s, $('#tr'), akcja);
    };
    const akcja = async (a, d) => {
      try { const r = await alch(a, d); kom(r.tekst || ''); } catch (err) { kom('Dym zasłania stół.'); }
      rysuj(true); odswiez();
    };
    rysuj(true);
    const id = setInterval(() => {
      if (!modal.classList.contains('on') || okno !== o) return clearInterval(id);
      rysuj(false);
    }, 1500);
  }
  function otworzKsiege(o) {
    okno = o; arkusz('ks'); kom('');
    w.innerHTML = '<h2>' + esc(o.e.tytul) + '</h2><div class="tekst"></div><p id="sld" class="kasa"></p><div id="tow"></div><div id="his"></div><button id="x">Zamknij</button>';
    $('#x').onclick = zamknij; modal.classList.add('on');
    tresc(o.e, w.querySelector('.tekst'));
    const CZAS = ts => new Date(ts * 1000).toLocaleString('pl', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    let ost = '', lim = { max_pozycja: 0, max_razem: 0 };
    const zam = {};                                  // lista zamówienia: id towaru -> liczba porcji
    const razem = () => Object.values(zam).reduce((a, b) => a + b, 0);
    const porcje = n => n + ' ' + (n === 1 ? 'porcja' : (n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20)) ? 'porcje' : 'porcji');
    const liczniki = () => {
      const r = razem();
      document.querySelectorAll('#tow .poz').forEach(p => {
        const n = zam[p.dataset.id] || 0;
        p.classList.toggle('wybrany', n > 0);
        p.querySelector('output').textContent = n;
        p.querySelector('[data-z="-1"]').disabled = n < 1;
        p.querySelector('[data-z="1"]').disabled = n >= lim.max_pozycja || r >= lim.max_razem;
      });
      $('#ileZam').textContent = r ? 'Na liście: ' + porcje(r) : 'Lista jest pusta';
      $('#kup').disabled = r < 1;
    };
    const rysuj = async force => {
      let k;
      try { k = await rynekApi(); } catch (err) { kom('Atrament się rozlał.'); return; }
      if (!k || !k.towary || okno !== o) return;
      const sig = JSON.stringify(k);
      if (!force && sig === ost) return;
      ost = sig; lim = k.limity;
      $('#sld').innerHTML = 'W kasie: <b>' + esc(k.saldo_t) + '</b>';
      $('#tow').innerHTML = '<p class="grupa">Ostatnio płaciłam za jedną porcję</p><table class="cennik">' + k.towary.map(t =>
        '<tr class="poz" data-id="' + esc(t.id) + '"><td class="naz">' + esc(t.nazwa) + (t.w_drodze ? ' <i>(w drodze: ' + t.w_drodze + ')</i>' : '') + '</td>' +
        '<td class="cena">' + esc(t.cena_t) + '</td>' +
        '<td class="krok"><button type="button" data-z="-1" aria-label="Mniej: ' + esc(t.nazwa) + '">−</button><output>0</output>' +
        '<button type="button" data-z="1" aria-label="Więcej: ' + esc(t.nazwa) + '">+</button></td></tr>').join('') + '</table>' +
        '<div class="zamow"><span id="ileZam"></span><button id="kup" type="button">Zamów</button></div>';
      $('#tow').onclick = e => {
        const b = e.target.closest('button[data-z]'); if (!b) return;
        const id = b.closest('.poz').dataset.id;
        zam[id] = Math.max(0, (zam[id] || 0) + Number(b.dataset.z));
        liczniki();
      };
      liczniki();
      $('#his').innerHTML = k.historia.length ? '<p class="grupa">Ostatnie wpisy</p>' + k.historia.map(h => {
        const naz = (k.towary.find(t => t.id === h.cel) || {}).nazwa || h.cel;
        const nl = esc(naz).toLocaleLowerCase('pl'), kw = esc(h.kwota_t);
        const t = h.typ === 'zakup' ? 'kupiłam ' + nl + ' ×' + h.n + ', zapłacone ' + kw :
          h.typ === 'sprzedaz' ? 'sprzedałam ' + nl + ' za ' + kw :
          h.typ === 'popyt' ? 'zielarz mówi, że ktoś jeszcze brał ' + nl + ' ×' + h.n :
          h.typ === 'wydatek' ? esc(h.opis || 'wydatek') + ', zapłacone ' + kw :
          'miesięczny dodatek: ' + kw;
        return '<div class="dim">' + CZAS(h.ts) + ' · ' + t + '</div>';
      }).join('') : '';
      $('#kup').onclick = async () => {
        const tow = {};
        Object.keys(zam).forEach(id => { if (zam[id] > 0) tow[id] = zam[id]; });
        try { const r = await rynekApi('kup', { towary: tow }); kom(r.tekst || ''); if (r.ok) Object.keys(zam).forEach(id => { delete zam[id]; }); }
        catch (err) { kom('Atrament się rozlał.'); }
        rysuj(true); odswiez();
      };
    };
    rysuj(true);
    const id = setInterval(() => {
      if (!modal.classList.contains('on') || okno !== o) return clearInterval(id);
      rysuj(false);
    }, 5000);
  }
  /* Otwarta zakładka Składu pamięta się do przeładowania strony. */
  let zakladka = '';
  function otworzSklad(o) {
    const rysujSklad = (s, el, akcja) => {
      const grupa = (t, h) => h ? '<p class="grupa">' + t + '</p>' + h : '';
      const pr = (nazwa, opis, przyc) => '<div class="rzad"><span><b>' + esc(nazwa) + '</b>' + (opis ? '<br><small>' + opis + '</small>' : '') + '</span><span>' + przyc + '</span></div>';
      const btn = (a, id, t, wyl) => '<button data-a="' + a + '" data-id="' + esc(id) + '"' + (wyl ? ' disabled' : '') + '>' + t + '</button>';
      const wyrzuc = (id, wyl) => btn('wyrzuc', id, '×', wyl);
      const idWagi = s.waga ? s.waga.id : '';
      const zajete = (s.stol ? pr('Na stole: ' + s.stol.nazwa, s.stol.czar ? 'czar jeszcze działa' : '', btn('ze_stolu', '', 'Odłóż', !!s.stol.czar)) : '') +
        (s.waga ? pr('Na wadze: ' + s.waga.nazwa, s.waga.gotowe ? '' : 'waga jeszcze liczy', btn('zdejmij', '', 'Zdejmij', !s.waga.gotowe)) : '');
      const wierszSurowca = r => {
        const naWadze = r.id === idWagi;
        return pr(r.nazwa, liczba(r.masa_g, 2) + ' g' + (r.zbadany ? ' · ' + krotko(r.sklad) : ' · niezbadane') + (naWadze ? ' · <i>leży na wadze</i>' : '') + (r.zaznaczone_g ? ' · <b class="zazn">czar: ' + gram(r.zaznaczone_g) + ' g</b>' : ''),
          btn('na_stol', r.id, 'Na stół', !!s.stol || naWadze) + btn('do_kociolka', r.id, 'Do garnka', naWadze) + wyrzuc(r.id, naWadze));
      };
      const wierszKopczyka = r =>
        pr(r.nazwa, liczba(r.mol) + ' mol · ' + liczba(r.masa_g, 2) + ' g', '<input type="text" inputmode="decimal" placeholder="' + jedn + '" data-mol="' + esc(r.id) + '" aria-label="Ile moli wsypać">' + btn('do_kociolka', r.id, 'Do garnka') + wyrzuc(r.id));
      const wierszWyrobu = r => {
        const ilosc = r.ciecz ? liczba(r.masa_g / 1000, 3) + ' l' : liczba(r.masa_g, 2) + ' g';
        return pr(r.nazwa, ilosc + ' · ' + esc(r.stopien) + ' · czystość ' + liczba(r.czystosc * 100, 1) + '% · skup: ' + esc(r.cena_t),
          '<input type="text" inputmode="decimal" placeholder="' + (r.ciecz ? 'l' : 'g') + '" data-porcja="' + esc(r.id) + '" data-mnoznik="' + (r.ciecz ? 1000 : 1) + '" aria-label="Wielkość porcji">' +
          btn('odlej', r.id, r.ciecz ? 'Odlej' : 'Odsyp') + btn('sprzedaj', r.id, 'Sprzedaj') + wyrzuc(r.id));
      };
      const surowceAlf = s.sklad.filter(r => r.typ === 'surowiec' && r.gdzie === 'sklad').sort((a, b) => a.nazwa.localeCompare(b.nazwa, 'pl'));
      const zaklad = (id, nazwa, lista, wiersz) => ({ id, nazwa, n: lista.length, zazn: lista.some(r => r.zaznaczone_g), h: lista.map(wiersz).join('') });
      const zakladki = s.kategorie.map(k => zaklad(k.id, k.nazwa, surowceAlf.filter(r => r.kat === k.id), wierszSurowca))
        .concat([zaklad('kopczyki', 'Kopczyki', s.sklad.filter(r => r.typ === 'kopczyk'), wierszKopczyka),
          zaklad('wyroby', 'Wyroby', s.sklad.filter(r => r.typ === 'produkt'), wierszWyrobu),
          zaklad('katalizatory', 'Katalizatory', s.katalizatory, k => pr(k.nazwa, esc(k.produkt) + ' · ' + esc(k.wzor), ''))])
        .filter(z => z.n > 0);
      if (!zakladki.some(z => z.id === zakladka)) zakladka = zakladki.length ? zakladki[0].id : '';
      const otwarta = zakladki.find(z => z.id === zakladka);
      el.innerHTML = grupa('Zajęte', zajete) +
        '<div class="zakladki">' + zakladki.map(z => '<button type="button" data-z="' + z.id + '"' + (z.id === zakladka ? ' class="on"' : '') + '>' + esc(z.nazwa) + ' (' + z.n + ')' + (z.zazn ? ' •' : '') + '</button>').join('') + '</div>' +
        (otwarta ? otwarta.h : '');
      el.querySelectorAll('button[data-z]').forEach(b => {
        b.onclick = () => { zakladka = b.dataset.z; rysujSklad(s, el, akcja); };
      });
      el.querySelectorAll('button[data-a]').forEach(b => {
        b.onclick = () => {
          const d = { id: b.dataset.id }, m = el.querySelector('[data-mol="' + b.dataset.id + '"]');
          if (m && m.value.trim()) d[jedn === 'g' ? 'gramy' : 'mol'] = m.value;
          if (b.dataset.a === 'odlej') {
            const p = el.querySelector('[data-porcja="' + b.dataset.id + '"]');
            d.gramy = (parseFloat(p.value.replace(',', '.')) || 0) * Number(p.dataset.mnoznik);
          }
          akcja(b.dataset.a, d);
        };
      });
    };
    okienko(o, rysujSklad, s => [s.sklad, s.katalizatory, s.stol && [s.stol.id, !!s.stol.czar], s.waga && [s.waga.id, s.waga.gotowe]]);
  }

  /* Jednostka wpisywana przy czarach i kopczykach: mole albo gramy. Pamięta się w przeglądarce gracza. */
  let jedn = 'mol';
  try { if (localStorage.getItem('alch_jedn') === 'g') jedn = 'g'; } catch (e) { /* tryb prywatny */ }
  const zapiszJedn = j => { jedn = j; try { localStorage.setItem('alch_jedn', j); } catch (e) { /* tryb prywatny */ } };
  const gram = x => liczba(x, 2);

  /* Czar wyznacza przyrząd, który jest tylko obrazkiem; tu w przyszłości dojdzie animacja lotu składnika na przyrząd. */
  function animacjaLotu(scena) { /* miejsce na animację */ }
  function otworzStol(o) {
    let wybrany = '';
    okienko(o, (s, el, akcja) => {
      const st = s.stol;
      if (!st) { el.innerHTML = '<p><i>Stół jest pusty. Połóż coś na nim ze spiżarni.</i></p>'; return; }
      const m = mole(st.sklad);
      let h = '<p><b>' + esc(st.nazwa) + '</b>, ' + liczba(st.masa_g, 2) + ' g</p>';
      const naGorze = '<div id="czp"></div>';                       // lista czarów na górze okna
      const sklad = tabelaSkladu(st.sklad) + '<p class="proporcja">' + proporcja(m) + '</p>';
      if (st.czar) {
        el.innerHTML = h + sklad + '<div id="scena" class="trzesie"><svg viewBox="0 0 100 100">' + (st.czar.przyrzad.ikona || '') + '<ellipse class="probka" cx="50" cy="54" rx="9" ry="7"/></svg></div><p><i>' + esc(st.czar.nazwa) + ': ' + esc(st.czar.przyrzad.nazwa || '') + '…</i></p>';
        return;
      }
      h += naGorze + sklad;
      const dostepne = s.czary.filter(c => c.znany && c.usuwa.some(e => m[e] > 0));   // w notatkach tylko znane czary
      if (!dostepne.some(c => c.id === wybrany)) wybrany = dostepne.length ? dostepne[0].id : '';
      /* Czar-przepis podpowiada, ile wyssać, i od razu wybiera czar, który jest jeszcze potrzebny. */
      const pod = st.podpowiedz || null;
      const nadmiar = c => (pod && pod.mol[c.usuwa[0]]) || 0;
      if (pod) {
        const potrzebny = dostepne.find(c => nadmiar(c) > 0);
        if (potrzebny && !nadmiar(dostepne.find(c => c.id === wybrany))) wybrany = potrzebny.id;
        const co = Object.entries(pod.mol).map(([e, mol]) => e + ' ' + liczba(mol, 2) + ' mol');
        h += co.length ? '<p class="proporcja">' + esc(pod.przepis) + ' podpowiada, ile wyssać: ' + co.join(', ') + '.</p>'
          : '<p class="proporcja">' + esc(pod.przepis) + ': to już ' + esc(pod.cel_nazwa.toLowerCase()) + '. Odłóż do spiżarni.</p>';
      }
      if (dostepne.length) {
        h += '<div><select id="czar">' + dostepne.map(c => '<option value="' + esc(c.id) + '"' + (c.id === wybrany ? ' selected' : '') + '>' + esc(c.nazwa) + ' (' + esc(c.pl) + ')</option>').join('') + '</select></div>' +
          '<div id="scena"><svg id="przyrzad" viewBox="0 0 100 100"></svg></div><p id="opis" class="dim"></p>' +
          '<input id="moc" type="range" min="0" max="1000" value="0" aria-label="Moc czaru">' +
          '<div class="jednostka"><span>Wyssać:</span><input id="ilem" type="text" inputmode="decimal" value="0" aria-label="Ile wyssać">' +
          '<select id="jedn" aria-label="Jednostka"><option value="mol"' + (jedn === 'mol' ? ' selected' : '') + '>mol</option><option value="g"' + (jedn === 'g' ? ' selected' : '') + '>g</option></select></div>' +
          '<p id="pog" class="proporcja"></p>';
      }
      h += '<div><button id="doKot">Do garnka</button><button id="odloz">Odłóż do spiżarni</button></div>';
      el.innerHTML = h;
      const czar = () => s.czary.find(c => c.id === wybrany);
      const pierw = () => czar().usuwa[0];
      const mam = () => (czar() ? (m[pierw()] || 0) : 0);
      const M = () => (czar() && st.sklad[pierw()] ? st.sklad[pierw()].M : 1);
      const wpis = () => parseFloat($('#ilem').value.replace(',', '.')) || 0;
      const wMolach = () => Math.min(mam(), jedn === 'g' ? wpis() / M() : wpis());
      const pokaz = mol => jedn === 'g' ? liczba(mol * M(), 3) : liczba(mol, 4);
      const pogl = () => {
        if (!czar()) return;
        const e = pierw(), po = Object.assign({}, m);
        po[e] = Math.max(0, mam() - wMolach());
        $('#pog').textContent = 'Zostanie: ' + pokaz(po[e]) + ' ' + jedn + ' ' + e + '. Proporcja po czarze: ' + proporcja(po);
      };
      const ustawMole = mol => { $('#ilem').value = pokaz(mol); $('#moc').value = mam() > 0 ? Math.min(1000, mol / mam() * 1000) : 0; pogl(); };
      const opisCzaru = () => {
        const c = czar(); if (!c) return;
        $('#przyrzad').innerHTML = (c.przyrzad.ikona || '') + '<ellipse class="probka" cx="50" cy="54" rx="9" ry="7"/>';
        $('#opis').innerHTML = '<b>' + esc(c.przyrzad.nazwa) + '.</b> ' + esc(c.przyrzad.opis) + ' Wytrąca: ' + esc(c.przyrzad.wytraca.join(', ')) + '.';
        ustawMole(nadmiar(c));
      };
      if ($('#czar')) {
        $('#czar').onchange = () => { wybrany = $('#czar').value; opisCzaru(); };
        $('#moc').oninput = () => { $('#ilem').value = pokaz(mam() * $('#moc').value / 1000); pogl(); };
        $('#ilem').oninput = () => { $('#moc').value = mam() > 0 ? Math.min(1000, wMolach() / mam() * 1000) : 0; pogl(); };
        $('#jedn').onchange = () => { const mol = mam() * $('#moc').value / 1000; zapiszJedn($('#jedn').value); ustawMole(mol); };
        opisCzaru();
      }
      /* Czar wyssania: z listy, głosem albo z pola. Moc: optimum (ile podpowiada przepis, bez podpowiedzi wszystko),
         maximum (wszystko), liczba (procent), bez mocy: to, co na suwaku (a dla innego czaru niż wybrany: optimum). */
      const rzucCzar = (id, moc) => {
        const c = s.czary.find(x => x.id === id); if (!c) return;
        if (id !== wybrany) {
          wybrany = id;
          if ($('#czar') && [...$('#czar').options].some(x => x.value === id)) { $('#czar').value = id; opisCzaru(); }
          if (moc === null) moc = 'optimum';
        }
        const ma = m[c.usuwa[0]] || 0;
        if (!(ma > 0)) return kom(c.nazwa + ': w tej próbce nie ma czego wyssać.');
        const u = moc === 'maximum' ? 1 : moc === 'optimum' ? (nadmiar(c) > 0 ? Math.min(1, nadmiar(c) / ma) : 1)
          : typeof moc === 'number' ? Math.max(0, Math.min(1, moc / 100))
          : ($('#ilem') && wMolach() > 0 ? Math.min(1, wMolach() / ma) : (nadmiar(c) > 0 ? Math.min(1, nadmiar(c) / ma) : 1));   // suwak na zerze: optimum
        if (!(u > 0)) return kom('Moc zero: czar nic nie zrobi.');
        animacjaLotu($('#scena'));
        akcja('czar', { czar: id, ulamek: u });
      };
      czary.wstaw($('#czp'), { czary: dostepne.map(c => ({ id: c.id, nazwa: c.nazwa, opis: c.pl })), wszystkie: s.czary, naCzar: rzucCzar, kom });
      $('#doKot').onclick = () => akcja('do_kociolka', { id: st.id });
      $('#odloz').onclick = () => akcja('ze_stolu');
    }, s => [s.stol, s.czary.length]);
  }

  /* Czar-przepis: przelicza spiżarnię na porcję o zadanym mnożniku. Wynik liczy tym samym wzorem co garnek. */
  function rachunek(p, mn) {
    const at = {};
    p.skladniki.forEach(x => {
      const g = Math.min(mn * x.g, x.mam_g);
      Object.entries(x.na_g).forEach(([e, v]) => { at[e] = (at[e] || 0) + v * g; });
    });
    let n = Infinity, m1 = 0, razem = 0;
    Object.entries(p.wzor).forEach(([e, ile]) => { n = Math.min(n, (at[e] || 0) / ile); m1 += ile * p.M[e]; });
    Object.entries(at).forEach(([e, mol]) => { razem += mol * p.M[e]; });
    return { masa: razem, czystosc: razem > 0 ? Math.min(1, n * m1 / razem) : 0, produkt: n > 0 ? n * m1 : 0 };
  }
  function otworzPrzepis(o) {
    let mn = null, poprzedniCzysty = null;
    okienko(o, (s, el, akcja) => {
      const p = (s.przepisy || []).find(x => x.id === o.e.id);
      if (!p) { el.innerHTML = '<p><i>Ten czar nie ma jeszcze przepisu.</i></p>'; return; }
      const z = s.zaznaczenie, garnekPelny = s.kociolek.gotuje || s.kociolek.zawartosc.length > 0;
      const nagl = '<p class="dim">' + esc(p.produkt) + ' · ' + esc(p.wzor_tekst) + ' · ' + esc(p.katalizator) + '</p>';
      if (z && z.przepis === p.id) {
        if (!z.gotowe) { el.innerHTML = nagl + '<div id="scena" class="trzesie"><svg viewBox="0 0 100 100">' + IKONA_SKLAD + '</svg></div><p><i>Czar przelicza spiżarnię…</i></p>'; return; }
        const w = rachunek(p, z.mnoznik);
        el.innerHTML = nagl + '<p class="grupa">Zaznaczone w spiżarni (porcja ×' + liczba(z.mnoznik, 2) + ')</p>' +
          z.pozycje.map(x => '<div class="rzad"><span>' + esc(x.nazwa) + '</span><span><b>' + gram(x.g) + ' g</b></span></div>').join('') +
          '<p class="proporcja">Wyjdzie ' + gram(w.masa) + ' g, czystość ' + liczba(w.czystosc * 100, 1) + '%.</p>' +
          '<div><button id="wsyp">Wsyp i zagotuj</button><button id="zgas">Zgaś</button></div>';
        $('#wsyp').onclick = () => akcja('wsyp_przepis');
        $('#zgas').onclick = () => akcja('zgas_przepis');
        return;
      }
      if (mn === null || mn > p.gorny || p.czysty !== poprzedniCzysty) mn = p.czysty;
      poprzedniCzysty = p.czysty;
      const doOdessania = p.skladniki.filter(x => x.surowy);
      const brakuje = doOdessania.some(x => !(x.mam_g > 0));
      /* Składnik do odessania: kartka podpowiada, ile sztuk położyć na stole. */
      const przygotowanie = doOdessania.map((x, i) =>
        '<p class="grupa">Przygotowanie: ' + esc(x.nazwa) + '</p>' +
        '<p>' + esc(x.surowy.nazwa) + ' w spiżarni: ' + x.surowy.szt + ' szt., ' + gram(x.surowy.masa_g) + ' g. Czar zważył każdą sztukę osobno i radzi położyć na stole najlepsze: <b>' + x.surowy.rada + ' szt.</b></p>' +
        (s.stol ? '<p><i>Na stole leży już: ' + esc(s.stol.nazwa) + '.</i></p>'
          : '<div class="jednostka"><span>Na stół, sztuk:</span><input id="stos' + i + '" type="text" inputmode="numeric" value="' + x.surowy.rada + '" aria-label="Ile sztuk położyć na stole"><button id="poloz' + i + '" type="button"' + (x.surowy.szt ? '' : ' disabled') + '>Połóż</button></div><p id="prog' + i + '" class="proporcja"></p>')).join('');
      el.innerHTML = nagl + '<div id="czp"></div>' + przygotowanie + '<table class="przepis"><tr><th>Składnik</th><th>Na porcję</th><th>Masz</th><th>Użyje</th></tr>' +
        p.skladniki.map((x, i) => '<tr><td>' + esc(x.nazwa) + '</td><td>' + gram(x.g) + ' g</td><td>' + gram(x.mam_g) + ' g</td><td id="u' + i + '"></td></tr>').join('') + '</table>' +
        '<input id="mn" type="range" min="0" max="1000" value="0" aria-label="Wielkość porcji">' +
        '<div class="jednostka"><span>Porcja ×</span><input id="mnv" type="text" inputmode="decimal" aria-label="Mnożnik porcji"><button id="czysto" type="button">Bez brudu ×' + liczba(p.czysty, 2) + '</button></div>' +
        '<p id="pr" class="proporcja"></p>' +
        (z ? '<p><i>Inny czar czeka już na wsypanie.</i></p>' : garnekPelny ? '<p><i>Garnek musi być pusty.</i></p>' : !p.dostepny ? '<p><i>Brakuje katalizatora.</i></p>' : brakuje ? '<p><i>Najpierw trzeba odessać składnik na stole i odłożyć go do spiżarni.</i></p>' : '') +
        '';
      doOdessania.forEach((x, i) => {
        const b = $('#poloz' + i);
        if (!b) return;
        const ile = () => Math.max(0, Math.min(x.surowy.szt, parseInt($('#stos' + i).value, 10) || 0));
        /* Prognoza z prawdziwych atomów wybranych sztuk: najlepsze są pierwsze. */
        const prog = () => {
          const w = x.surowy.sztuki.slice(0, ile()).reduce((a, q) => ({ p: a.p + q.p, m: a.m + q.m, w: a.w + q.w }), { p: 0, m: 0, w: 0 });
          $('#prog' + i).textContent = w.m > 0
            ? 'Z ' + ile() + ' szt. wyjdzie ' + gram(w.m) + ' g, czystość ' + liczba(Math.min(1, w.w / w.m) * 100, 1) + '%.' +
              (w.m > x.surowy.wolne_g ? ' Tyle nie wejdzie do garnka.' : w.p > x.surowy.limit ? ' Pozostałych składników nie starczy na całość.' : '')
            : 'Nie ma z czego liczyć.';
        };
        $('#stos' + i).oninput = prog;
        prog();
        b.onclick = () => akcja('poloz_stos', { przepis: p.id, ile: ile() });
      });
      const licz = () => {
        p.skladniki.forEach((x, i) => { $('#u' + i).textContent = gram(Math.min(mn * x.g, x.mam_g)) + ' g'; });
        const w = rachunek(p, mn), brud = mn > p.czysty + 0.0001;
        $('#pr').textContent = (w.produkt > 0 ? 'Wyjdzie ' + gram(w.masa) + ' g, czystość ' + liczba(w.czystosc * 100, 1) + '%.' : 'Z tego nic się nie zwiąże.') + (brud ? ' Ponad zapasy: przybędzie brudu.' : '');
      };
      const ustaw = (m, skadSuwak) => {
        mn = Math.max(0, Math.min(p.gorny, m));
        if (skadSuwak !== 'mnv') $('#mnv').value = liczba(mn, 3);
        if (skadSuwak !== 'mn') $('#mn').value = p.gorny > 0 ? mn / p.gorny * 1000 : 0;
        licz();
      };
      $('#mn').oninput = () => ustaw(p.gorny * $('#mn').value / 1000, 'mn');
      $('#mnv').oninput = () => ustaw(parseFloat($('#mnv').value.replace(',', '.')) || 0, 'mnv');
      $('#czysto').onclick = () => ustaw(p.czysty);
      /* Czar-przepis: z listy, głosem albo z pola. Moc: optimum (bez brudu), maximum (największa porcja), liczba (mnożnik), bez mocy: z pola. */
      czary.wstaw($('#czp'), { czary: [{ id: p.id, nazwa: p.nazwa, opis: 'porcja: optimum ×' + liczba(p.czysty, 2) + ', maximum ×' + liczba(p.gorny, 2) }], kom,
        naCzar: (id, moc) => {
          if (z || garnekPelny || !p.dostepny || brakuje) return kom(p.nazwa + ': teraz się nie uda. ' + (z ? 'Inny czar czeka na wsypanie.' : garnekPelny ? 'Naczynie musi być puste.' : !p.dostepny ? 'Brakuje katalizatora.' : 'Najpierw trzeba odessać składnik na stole.'));
          const mnoz = moc === 'optimum' ? p.czysty : moc === 'maximum' ? p.gorny : typeof moc === 'number' ? Math.max(0, Math.min(p.gorny, moc)) : mn;
          if (!(mnoz > 0)) return kom('Porcja zero: czar nic nie zrobi.');
          ustaw(mnoz); akcja('rzuc_przepis', { przepis: p.id, mnoznik: mnoz });
        } });
      ustaw(mn);
    }, s => [(s.przepisy || []).find(x => x.id === o.e.id), s.zaznaczenie, s.kociolek.zawartosc.length, s.kociolek.gotuje, s.stol && s.stol.nazwa]);
  }

  function otworzWage(o) {
    okienko(o, (s, el, akcja) => {
      const wg = s.waga;
      if (!wg) {
        const lista = s.sklad.filter(r => r.typ === 'surowiec' && r.gdzie === 'sklad');
        el.innerHTML = lista.length
          ? '<div id="czp"></div><div><select id="rz" aria-label="Co zbadać">' + lista.map(r => '<option value="' + esc(r.id) + '">' + esc(r.nazwa) + ', ' + liczba(r.masa_g, 2) + ' g</option>').join('') + '</select></div>'
          : '<p><i>W spiżarni nie ma niczego do zważenia.</i></p>';
        if ($('#czp')) czary.wstaw($('#czp'), { czary: [{ id: 'waga', nazwa: s.czar_wagi, opis: 'bada skład wybranego surowca' }], kom, bezMocy: true,
          naCzar: () => akcja('zbadaj', { id: $('#rz').value }) });
        return;
      }
      if (!wg.gotowe) { el.innerHTML = '<p><b>' + esc(wg.nazwa) + '</b></p><div id="scena" class="trzesie"><svg viewBox="0 0 100 100">' + IKONA_WAGA + '</svg></div><p><i>Szalki drżą…</i></p>'; return; }
      el.innerHTML = '<p><b>' + esc(wg.nazwa) + '</b>, ' + liczba(wg.masa_g, 2) + ' g</p>' + tabelaSkladu(wg.sklad) + '<p class="proporcja">' + proporcja(mole(wg.sklad)) + '</p><div><button id="zdj">Zdejmij</button></div>';
      $('#zdj').onclick = () => akcja('zdejmij');
    }, s => [s.waga, s.sklad.map(r => r.id + r.gdzie + r.typ)]);
  }

  /* Gdy wynik powstał bez patrzenia na garnek, garnek jeszcze chwilę pyrka, zanim go pokaże.
     Numer ostatnio zobaczonego wyniku pamięta przeglądarka gracza. */
  const PYRKANIE_MS = 4000;
  let widzianyWynik = '';
  try { widzianyWynik = localStorage.getItem('alch_wynik') || ''; } catch (e) { /* tryb prywatny */ }
  const zapiszWynik = id => { widzianyWynik = id; try { localStorage.setItem('alch_wynik', id); } catch (e) { /* tryb prywatny */ } };
  function otworzKociolek(o) {
    let wybrany = '', patrzyl = false, czekaNa = '', ostS = null;
    const rysujKociolek = (s, el, akcja) => {
      ostS = s;
      const k = s.kociolek;
      if (k.gotuje) patrzyl = true;
      if (k.wynik && k.wynik_id && k.wynik_id !== widzianyWynik) {
        if (patrzyl) zapiszWynik(k.wynik_id);
        else {
          el.innerHTML = '<div id="scena" class="trzesie"><svg viewBox="0 0 100 100">' + IKONA_KOCIOLEK + '</svg></div><p><i>Garnek jeszcze pyrka…</i></p>';
          if (czekaNa !== k.wynik_id) {
            czekaNa = k.wynik_id;
            setTimeout(() => {
              czekaNa = '';
              if (!el.isConnected || okno !== o || !modal.classList.contains('on')) return;
              zapiszWynik(k.wynik_id);
              rysujKociolek(ostS, el, akcja);
            }, PYRKANIE_MS);
          }
          return;
        }
      }
      let h = k.wynik ? '<p><b>' + esc(k.wynik) + '</b></p>' : '';
      if (k.gotuje) { el.innerHTML = h + '<div id="scena" class="trzesie"><svg viewBox="0 0 100 100">' + IKONA_KOCIOLEK + '</svg></div><p><i>Garnek bulgocze…</i></p>'; return; }
      if (!k.zawartosc.length) { el.innerHTML = h + '<p><i>Garnek jest pusty. Wsypujesz do niego rzeczy ze spiżarni albo ze stołu.</i></p>'; return; }
      if (!s.katalizatory.some(c => c.id === wybrany)) wybrany = s.katalizatory.length ? s.katalizatory[0].id : '';
      h += '<p>W garnku: ' + k.zawartosc.map(x => esc(x.nazwa) + ' (' + liczba(x.masa_g, 2) + ' g)').join(', ') + '. <i>(' + liczba(k.masa_g / 1000, 2) + ' z ' + liczba(s.pojemnosc_g / 1000, 0) + ' l)</i></p>' + tabelaSkladu(k.sklad) + '<p class="proporcja">' + proporcja(mole(k.sklad)) + '</p>' +
        '<div><select id="kat">' + s.katalizatory.map(c => '<option value="' + esc(c.id) + '"' + (c.id === wybrany ? ' selected' : '') + '>' + esc(c.nazwa) + ' (' + esc(c.produkt) + ', ' + esc(c.wzor) + ')</option>').join('') + '</select></div>' +
        '<div><button id="gotuj">Zagotuj</button><button id="wylej">Wylej</button></div>';
      el.innerHTML = h;
      $('#kat').onchange = () => { wybrany = $('#kat').value; };
      $('#gotuj').onclick = () => akcja('zagotuj', { katalizator: $('#kat').value });
      $('#wylej').onclick = () => akcja('wylej');
    };
    okienko(o, rysujKociolek, s => [s.kociolek, s.katalizatory]);
  }

  /* Rejestracja rodzajów tego pokoju (nazwy są używane w pokoj.json, w polu "rodzaj" folderu) */
  const R = window.Mysl.rodzaj;
  const IKONA_WAGA = '<path d="M50 18v62M28 82h44M18 34h64M18 34l-9 24h18zM82 34l-9 24h18z"/><circle cx="50" cy="18" r="3"/>';
  const IKONA_KOCIOLEK = '<path d="M25 50h50l-6 28a10 10 0 0 1-10 8H41a10 10 0 0 1-10-8z"/><path d="M22 50h56M35 90h30M42 40c-6-8 6-12 0-20M58 40c-6-8 6-12 0-20"/>';
  const IKONA_SKLAD = '<path d="M18 14v74M82 14v74M18 34h64M18 58h64M18 82h64M30 34V24h12v10M56 58V46h14v12M34 82V70h10v12"/>';
  const IKONA_STOL = '<path d="M14 44h72M22 44v42M78 44v42M14 44l8-10h56l8 10"/>';
  R('sklad', { v: .2, bob: .2, klasa: 'p kot', ikona: IKONA_SKLAD, etykieta: 'skład', otworz: otworzSklad });
  R('stol', { v: .2, bob: .2, klasa: 'p kot', ikona: IKONA_STOL, etykieta: 'urządzenie', otworz: otworzStol });
  R('waga', { v: .2, bob: .2, klasa: 'p kot', ikona: IKONA_WAGA, etykieta: 'urządzenie', otworz: otworzWage });
  R('kociolek', { v: .2, bob: .2, klasa: 'p kot', ikona: IKONA_KOCIOLEK, etykieta: 'urządzenie', otworz: otworzKociolek });
  R('ksiega', { v: .25, bob: .2, etykieta: 'księga', otworz: otworzKsiege });
  R('przepis', { v: .3, klasa: 'p m czar', etykieta: 'czar', otworz: otworzPrzepis });
})();

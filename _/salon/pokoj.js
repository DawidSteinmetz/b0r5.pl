/* Salon: magiczny notes do zapisywania punktów (tysiąc, kości, cokolwiek). Wpisy przyjmuje głosem albo z klawiatury:
   "Nota hoc Kacper 120", "Nota hoc Maja minus 60", "Nota hoc deleo" (cofnij), "Nota hoc nova" (nowa partia).
   Mowę rozpoznaje przeglądarka (Web Speech API: Chrome, Edge; nagranie idzie do ich usługi rozpoznawania).
   Plik należy do pokoju (pokoj.json: "skrypt"), a z rdzenia korzysta tylko przez window.Mysl. Serwer: api/notes.php. */
(function () {
  'use strict';
  const { $, esc, w, modal, gracz, arkusz, zamknij, tresc, URL_POKOJU: U } = window.Mysl;
  let okno = null;
  async function api(akcja, d) {
    const r = await fetch(U + 'api/notes.php', {
      method: akcja ? 'POST' : 'GET', cache: 'no-store', headers: { 'Content-Type': 'application/json' },
      body: akcja ? JSON.stringify(Object.assign({ akcja, gracz }, d || {})) : undefined
    });
    return r.json();
  }
  let czasTostu = 0;
  function kom(t) {
    let e = $('#tost');
    if (!e) { e = document.createElement('div'); e.id = 'tost'; e.setAttribute('role', 'status'); $('#sheet').appendChild(e); }
    e.textContent = t || '';
    e.classList.toggle('on', !!t);
    clearTimeout(czasTostu);
    czasTostu = setTimeout(() => e.classList.remove('on'), 6000);
  }

  /* --- Zaklęcia: tekst (z mowy albo z klawiatury) na działanie ---------------------------------------------- */
  const bezOgonkow = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/g, 'l');
  // "nota hoc" bywa słyszane różnie: nota hok, nota ok, note hoc, notahok…
  const PRZEDROSTEK = /^\s*(nota|note|noto|notka|nuta)\s*(hoc|hok|hog|ok|och|hoch|hock|hook|hak|choc|cok|kok|hot)\b[\s,.:]*/;
  const LICZBY = {
    zero: 0, jeden: 1, jedna: 1, dwa: 2, dwie: 2, trzy: 3, cztery: 4, piec: 5, szesc: 6, siedem: 7, osiem: 8, dziewiec: 9,
    dziesiec: 10, jedenascie: 11, dwanascie: 12, trzynascie: 13, czternascie: 14, pietnascie: 15, szesnascie: 16, siedemnascie: 17,
    osiemnascie: 18, dziewietnascie: 19, dwadziescia: 20, trzydziesci: 30, czterdziesci: 40, piecdziesiat: 50, szescdziesiat: 60,
    siedemdziesiat: 70, osiemdziesiat: 80, dziewiecdziesiat: 90, sto: 100, dwiescie: 200, trzysta: 300, czterysta: 400,
    piecset: 500, szescset: 600, siedemset: 700, osiemset: 800, dziewiecset: 900, tysiac: 1000, tysiace: 1000, tysiecy: 1000
  };
  // Liczba z końca zaklęcia: cyfry ("120", "1 20") albo słowa ("sto dwadzieścia"); "minus" na początku daje wynik ujemny.
  function liczba(slowa) {
    let znak = 1, i = 0;
    if (slowa[0] === 'minus' || slowa[0] === '-') { znak = -1; i = 1; }
    const reszta = slowa.slice(i);
    if (!reszta.length) return null;
    const cyfry = reszta.join('').replace(/[^\d-]/g, '');
    if (/^-?\d+$/.test(cyfry) && reszta.every(s => /^-?\d+$/.test(s))) return znak * parseInt(cyfry, 10);
    let suma = 0, ok = false;
    for (const s of reszta) {
      if (!(s in LICZBY)) return null;
      const v = LICZBY[s];
      suma = v === 1000 ? Math.max(1, suma) * 1000 : suma + v; ok = true;
    }
    return ok ? znak * suma : null;
  }
  function odleglosc(a, b) {                   // Levenshtein: ile liter trzeba zmienić
    const d = Array.from({ length: a.length + 1 }, (_, i) => [i]);
    for (let j = 1; j <= b.length; j++) d[0][j] = j;
    for (let i = 1; i <= a.length; i++) for (let j = 1; j <= b.length; j++)
      d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
    return d[a.length][b.length];
  }
  // Najbliższy gracz w notesie (imię może być usłyszane z literówką albo w innej formie: "Kacprowi", "Mai").
  function ktoTo(slowo, gracze) {
    const s = bezOgonkow(slowo), jedno = !s.includes(' '); let naj = null, wynik = 99;
    for (const g of gracze) {
      const n = bezOgonkow(g.imie);
      let o = odleglosc(s, n), wsp = 0;
      while (wsp < s.length && wsp < n.length && s[wsp] === n[wsp]) wsp++;
      // odmiana: wspólny początek (Kacper → Kacprowi, Borsuk → Borsukowi) albo skrót (Kac → Kacper)
      if (jedno && wsp >= Math.min(4, n.length - 1) && s.length <= n.length + 4) o = Math.min(o, 1);
      if (jedno && s.length >= 3 && n.startsWith(s)) o = Math.min(o, 1);
      if (o < wynik) { wynik = o; naj = g.imie; }
    }
    return naj && wynik <= Math.max(1, Math.floor(bezOgonkow(naj).length / 3)) ? naj : null;
  }
  // Kategorie kości i to, jak bywają wypowiadane (bez ogonków). Dłuższe warianty najpierw.
  const KATEGORIE = [
    ['trzy', ['trzy jednakowe', 'trzy takie same', 'trzy identyczne']], ['cztery', ['cztery jednakowe', 'cztery takie same', 'kareta', 'karete']],
    ['maly', ['maly strit', 'maly strajt', 'maly street', 'malego strita', 'maly']], ['duzy', ['duzy strit', 'duzy strajt', 'duzy street', 'duzego strita', 'duzy']],
    ['jedynki', ['jedynki', 'jedynek', 'jedynka']], ['dwojki', ['dwojki', 'dwojek', 'dwojka']], ['trojki', ['trojki', 'trojek', 'trojka']],
    ['czworki', ['czworki', 'czworek', 'czworka']], ['piatki', ['piatki', 'piatek', 'piatka']], ['szostki', ['szostki', 'szostek', 'szostka']],
    ['full', ['pelny dom', 'full', 'fula', 'ful', 'fulla']], ['general', ['general', 'generala', 'jazzy', 'yahtzee', 'jaci']], ['szansa', ['szansa', 'szanse', 'szansy']]
  ];
  const SKRESL = /^(skresl|skreslam|skreslone|skreslic|zero|nic|krzyzyk)$/;
  // Lusor Primus/Secundus/Tertius/Quartus (i dalej): gracz na danym miejscu
  const LUSOR = /^(lusor|luzor|lusora|luzora|lu sor|loser|luser|lizor)\s+(primus|prymus|primu|prima|secundus|sekundus|secundu|sekundu|tertius|tercjus|tercius|terciu|quartus|kwartus|kwartu|quintus|kwintus|sextus|sekstus|septimus|septymus|octavus|oktawus)\s+(.+)$/;
  const MIEJSCA = { primus: 1, prymus: 1, primu: 1, prima: 1, secundus: 2, sekundus: 2, secundu: 2, sekundu: 2, tertius: 3, tercjus: 3, tercius: 3, terciu: 3,
    quartus: 4, kwartus: 4, kwartu: 4, quintus: 5, kwintus: 5, sextus: 6, sekstus: 6, septimus: 7, septymus: 7, octavus: 8, oktawus: 8 };
  const duza = s => s.charAt(0).toUpperCase() + s.slice(1);
  // Zwraca {akcja, dane} albo {blad} albo null (to nie zaklęcie). "glos": przy mowie bez "Nota hoc" przyjmuje tylko
  // zdanie, które w całości jest czarem formularza albo czarem gracza (żeby rozmowa przy stole niczego nie wpisała).
  function rozbierz(tekst, notes, glos) {
    const gracze = notes.gracze || notes;
    const oryg = String(tekst).replace(/[,.:;!?]/g, ' ').replace(/\s+/g, ' ').trim();
    let t = bezOgonkow(oryg);
    const m = t.match(PRZEDROSTEK), zPrzedrostkiem = !!m;
    let o = oryg;
    if (m) { o = o.split(' ').slice(m[0].trim().split(/\s+/).length).join(' '); t = t.slice(m[0].length).trim(); }
    if (/^(mille|mile|mila|mili|milla|mil)$/.test(t)) return { akcja: 'nowa', dane: { typ: 'mille' } };
    if (/^(alea|aleja|alei|ale a|alee|allea)$/.test(t)) return { akcja: 'nowa', dane: { typ: 'alea' } };
    const lu = t.match(LUSOR);
    if (lu) {
      // imię to dokładnie trzecie usłyszane słowo (pierwsze po "Lusor Primus"), tak jak padło
      const im = o.split(' ')[t.split(' ').length - lu[3].split(' ').length];
      return { akcja: 'gracz_nr', dane: { nr: MIEJSCA[lu[2]], imie: duza(im) } };
    }
    if (glos && !zPrzedrostkiem) return null;
    if (!t) return { blad: 'Pióro czeka na resztę zaklęcia.' };
    if (/^(deleo|deleto|delo|cofnij|wykresl|usun)\b/.test(t)) return { akcja: 'cofnij' };
    if (/^(nova|nowa|novo|nowe)\b/.test(t)) {
      const imiona = o.split(' ').slice(1).join(' ').replace(/^(gra|partia)\s*/i, '').split(/\s+(?:i\s+)?|\s*,\s*/).filter(x => x && !/^i$/i.test(x));
      return { akcja: 'nowa', dane: imiona.length ? { gracze: imiona.map(duza) } : {} };
    }
    const slowa = t.split(' ');
    for (let k = 1; k <= Math.min(3, slowa.length); k++) {
      const im = ktoTo(slowa.slice(0, k).join(' '), gracze);
      if (!im) continue;
      const reszta = slowa.slice(k);
      if (notes.typ === 'alea') {
        const r = reszta.join(' ');
        for (const [kat, warianty] of KATEGORIE) for (const war of warianty) {
          if (r !== war && !r.startsWith(war + ' ')) continue;
          const po = r.slice(war.length).trim().split(' ').filter(Boolean);
          if (po.length === 1 && SKRESL.test(po[0])) return { akcja: 'wpisz', dane: { imie: im, kat, skresl: true } };
          const pkt = po.length ? liczba(po) : null;
          if (po.length && pkt === null) return { blad: 'Pióro nie zrozumiało liczby.' };
          return { akcja: 'wpisz', dane: pkt === null ? { imie: im, kat } : { imie: im, kat, pkt } };
        }
        if (reszta.length) continue;
        return { blad: 'Którą kategorię? Np. „' + im + ' trójki 9” albo „' + im + ' full”.' };
      }
      if (reszta.length === 1 && /^(bomba|bombe|bomby|bomb|bombka)$/.test(reszta[0])) return { akcja: 'bomba', dane: { imie: im } };
      const pkt = liczba(reszta);
      if (pkt !== null) return { akcja: 'wpisz', dane: { imie: im, pkt } };
    }
    if (!ktoTo(slowa[0], gracze)) return { blad: 'Notes nie zna gracza „' + slowa[0] + '”.' };
    return { blad: notes.typ === 'alea' ? 'Pióro nie zrozumiało kategorii.' : 'Pióro nie zrozumiało liczby.' };
  }

  /* --- Słuchanie --------------------------------------------------------------------------------------------- */
  const Rozpoznawanie = window.SpeechRecognition || window.webkitSpeechRecognition;
  let sluch = null, slucha = false, poUsłyszeniu = null;
  function sluchaj(wl, naTekst, naStan) {
    if (!Rozpoznawanie) return naStan('brak');
    if (!wl) { slucha = false; if (sluch) sluch.stop(); naStan('cisza'); return; }
    slucha = true; poUsłyszeniu = naTekst;
    if (!sluch) {
      sluch = new Rozpoznawanie();
      sluch.lang = 'pl-PL'; sluch.continuous = true; sluch.interimResults = false; sluch.maxAlternatives = 3;
      sluch.onresult = e => {
        for (let i = e.resultIndex; i < e.results.length; i++) if (e.results[i].isFinal) {
          const alt = Array.from(e.results[i]).map(a => a.transcript);
          if (poUsłyszeniu) poUsłyszeniu(alt);
        }
      };
      sluch.onerror = e => { if (e.error === 'not-allowed' || e.error === 'service-not-allowed') { slucha = false; naStan('odmowa'); } };
      sluch.onend = () => { if (slucha) { try { sluch.start(); } catch (err) { /* już słucha */ } } else naStan('cisza'); };   // Chrome kończy po ciszy: wznów
    }
    try { sluch.start(); } catch (err) { /* już słucha */ }
    naStan('slucha');
  }

  /* --- Notes ------------------------------------------------------------------------------------------------- */
  function otworzNotes(o) {
    okno = o; arkusz('ks'); kom('');
    w.innerHTML = '<h2>' + esc(o.e.tytul) + '</h2><div class="tekst"></div>' +
      '<div class="zaklecia"><button type="button" id="ucho" aria-pressed="false">Rzuć czar</button><span id="slyszy"></span></div>' +
      '<div class="pisz"><input type="text" id="zakl" placeholder="np. Lusor Primus Kopytko, Kopytko 60, Borsuk bomba" aria-label="Zaklęcie"><button type="button" id="zp">Zapisz</button></div>' +
      '<div class="akcje"><button type="button" id="cof">Wykreśl ostatni</button><button type="button" id="nowa">Nowa partia…</button></div>' +
      '<div id="nowaf" hidden><input type="text" id="ng" placeholder="Imiona, po przecinku" aria-label="Gracze nowej partii"><input type="text" id="nc" inputmode="numeric" placeholder="cel" aria-label="Cel punktowy" size="5"><button type="button" id="nok">Zacznij</button></div>' +
      '<div id="nt"></div><div id="arch"></div><button id="x">Zamknij</button>';
    $('#x').onclick = zamknij; modal.classList.add('on');
    tresc(o.e, w.querySelector('.tekst'));
    let s = null, ost = '', nowyWpis = -1;
    const rysujKosci = () => {
      const sw = s.ostatni_wpis, th = s.gracze.map(g => '<th' + (g.prowadzi ? ' class="prow"' : '') + '>' + esc(g.imie) + '</th>').join('');
      const wiersz = (nazwa, kom, klasa) => '<tr' + (klasa ? ' class="' + klasa + '"' : '') + '><th class="kat">' + nazwa + '</th>' + s.gracze.map(kom).join('') + '</tr>';
      const kat = k => wiersz(esc(k.nazwa) + (k.stale ? ' <small>' + k.stale + '</small>' : ''), g => {
        const v = g.kosci.kat[k.id], swiezy = sw && sw.imie === g.imie && sw.kat === k.id;
        return '<td' + (swiezy ? ' class="swiezy"' : '') + '>' + (v === undefined ? '' : v === 0 ? '<span class="skr">✕</span>' : v) + '</td>';
      });
      const suma = (nazwa, f, klasa) => wiersz(nazwa, g => '<td>' + f(g) + '</td>', klasa);
      let h = '<p class="partia">' + esc(s.nazwa) + '</p><table class="notes kosci"><thead><tr><th class="kat"></th>' + th + '</tr></thead><tbody>';
      s.kategorie.filter(k => k.czesc === 'gora').forEach(k => { h += kat(k); });
      h += suma('Suma', g => g.kosci.gora, 'sum') + suma('Premia <small>(od ' + s.premia.prog + ')</small>', g => g.kosci.premia ? '+' + g.kosci.premia : '', 'sum');
      s.kategorie.filter(k => k.czesc === 'dol').forEach(k => { h += kat(k); });
      h += '</tbody><tfoot>' + wiersz('Razem', g => '<td' + (g.prowadzi ? ' class="prow"' : '') + '>' + g.kosci.razem + '</td>') + '</tfoot></table>';
      return h;
    };
    const rysuj = () => {
      if (!s) return;
      if (s.typ === 'alea') {
        $('#nt').innerHTML = rysujKosci() + (s.koniec ? '<p class="wygrana">' + esc(s.koniec.zwyciezca) + ' wygrywa z wynikiem ' + s.koniec.suma + '!</p>' : '');
        rysujArchiwum(); return;
      }
      // Układ wierszy: każdy wpis trafia do pierwszego wolnego wiersza swojego gracza, a bomba (jedna grupa wpisów)
      // do jednego wiersza u wszystkich, nie wyżej niż najniższy wolny wiersz któregoś z nich.
      const wszystkie = [];
      s.gracze.forEach((g, k) => g.wpisy.forEach(x => wszystkie.push(Object.assign({ k }, x))));
      wszystkie.sort((a, b) => a.nr - b.nr);
      const kom = s.gracze.map(() => []), wolny = s.gracze.map(() => 0);
      for (let i = 0; i < wszystkie.length; i++) {
        const x = wszystkie[i];
        if (x.grupa) {
          const grupa = []; while (i < wszystkie.length && wszystkie[i].grupa === x.grupa) grupa.push(wszystkie[i++]); i--;
          const r = Math.max(...grupa.map(y => wolny[y.k]));
          grupa.forEach(y => { kom[y.k][r] = y; wolny[y.k] = r + 1; });
        } else { kom[x.k][wolny[x.k]] = x; wolny[x.k]++; }
      }
      const n = Math.max(1, ...wolny);
      let h = '<p class="partia">' + esc(s.nazwa) + (s.cel ? ' · do ' + s.cel : '') + '</p><table class="notes"><thead><tr>' +
        s.gracze.map(g => '<th' + (g.prowadzi ? ' class="prow"' : '') + '>' + esc(g.imie) + '</th>').join('') + '</tr></thead><tbody>';
      for (let r = 0; r < n; r++) h += '<tr>' + s.gracze.map(g => {
        const x = kom[s.gracze.indexOf(g)][r];
        return '<td' + (x && x.nr === nowyWpis ? ' class="swiezy"' : '') + '>' + (x ? (x.bomba ? '<span class="bomba" title="bomba">✹</span> 0' : x.pkt < 0 ? '−' + Math.abs(x.pkt) : x.pkt) : '') + '</td>';
      }).join('') + '</tr>';
      h += '</tbody><tfoot><tr>' + s.gracze.map(g => '<td' + (g.prowadzi ? ' class="prow"' : '') + '>' + g.suma + '</td>').join('') + '</tr></tfoot></table>';
      if (s.koniec) h += '<p class="wygrana">' + esc(s.koniec.zwyciezca) + ' wygrywa z wynikiem ' + s.koniec.suma + '!</p>';
      $('#nt').innerHTML = h;
      rysujArchiwum();
    };
    const rysujArchiwum = () => {
      $('#arch').innerHTML = s.archiwum.length ? '<p class="grupa">Wcześniejsze partie</p>' + s.archiwum.map(a =>
        '<div class="dim">' + new Date(a.start * 1000).toLocaleDateString('pl', { day: 'numeric', month: 'short' }) + ' · ' + esc(a.nazwa) + ': ' +
        Object.entries(a.sumy).map(([im, p]) => esc(im) + ' ' + p).join(', ') + (a.zwyciezca ? ' · wygrał(a) ' + esc(a.zwyciezca) : '') + '</div>').join('') : '';
    };
    const pobierz = async () => {
      let n; try { n = await api(); } catch (err) { n = { tekst: 'Notes nie odpowiada (błąd serwera).' }; }
      if (okno !== o) return;
      if (!n || !n.gracze) { if (!s) $('#nt').innerHTML = '<p class="wygrana">' + esc(n && n.tekst || 'Notes nie odpowiada.') + '</p>'; return; }
      const sig = JSON.stringify(n); if (sig === ost) return;
      ost = sig; s = n; rysuj();
    };
    const wykonaj = async (akcja, d) => {
      let r; try { r = await api(akcja, d); } catch (err) { kom('Kleks: serwer nie odpowiada.'); return; }
      kom(r.tekst || '');
      if (r.stan) { s = r.stan; ost = JSON.stringify(s); nowyWpis = (akcja === 'wpisz' || akcja === 'bomba') && r.ok ? s.ostatni : -1; rysuj(); }
    };
    const zaklecie = (warianty, glos) => {
      if (!s) return;
      let blad = null;
      for (const t of warianty) {                // mowa daje kilka wersji: pierwsza, którą da się zrozumieć
        const z = rozbierz(t, s, glos);
        if (!z) continue;
        if (z.blad) { blad = blad || z.blad; continue; }
        if (glos) $('#slyszy').textContent = '„' + t.trim() + '”';
        return wykonaj(z.akcja, Object.assign({ glos: !!glos }, z.dane || {}));
      }
      if (blad) kom(blad);
    };
    // słuchanie
    const ucho = $('#ucho');
    const stan = st => {
      ucho.classList.toggle('slucha', st === 'slucha'); ucho.setAttribute('aria-pressed', st === 'slucha' ? 'true' : 'false');
      ucho.textContent = st === 'slucha' ? 'Pióro słucha… (kliknij, by przestać)' : 'Rzuć czar';
      if (st === 'brak') { ucho.disabled = true; ucho.textContent = 'Ta przeglądarka nie słyszy zaklęć'; }
      if (st === 'odmowa') kom('Przeglądarka nie dała dostępu do mikrofonu.');
    };
    if (!Rozpoznawanie) stan('brak');
    ucho.onclick = () => sluchaj(!slucha, alt => zaklecie(alt, true), stan);
    // klawiatura
    const pole = $('#zakl');
    const zapiszPole = () => { const t = pole.value.trim(); if (!t) return; zaklecie([t], false); pole.value = ''; };
    $('#zp').onclick = zapiszPole;
    pole.addEventListener('keydown', e => { if (e.key === 'Enter') zapiszPole(); });
    $('#cof').onclick = () => wykonaj('cofnij');
    $('#nowa').onclick = () => { const f = $('#nowaf'); f.hidden = !f.hidden; if (!f.hidden && s) { $('#ng').value = s.gracze.map(g => g.imie).join(', '); $('#nc').value = s.cel || ''; $('#ng').focus(); } };
    $('#nok').onclick = () => {
      const gr = $('#ng').value.split(/[,;]+/).map(x => x.trim()).filter(Boolean);
      wykonaj('nowa', { gracze: gr, cel: $('#nc').value.trim() === '' ? 0 : Number($('#nc').value) });
      $('#nowaf').hidden = true;
    };
    pobierz();
    const id = setInterval(() => {
      if (!modal.classList.contains('on') || okno !== o) { clearInterval(id); if (slucha) sluchaj(false, null, () => { }); return; }
      pobierz();
    }, 3000);
  }

  window.Mysl.rodzaj('notes', {
    v: .2, bob: .2, klasa: 'p kot', etykieta: 'notes', otworz: otworzNotes,
    ikona: '<path d="M26 14h46v72H26z"/><path d="M26 14c-6 0-8 4-8 8v60c0 4 2 6 8 4M34 30h30M34 42h30M34 54h22"/><path d="M84 18L60 66l-3 9 7-6 24-48z"/>'
  });
  window.Mysl.notesZaklecie = rozbierz;        // do testów: Mysl.notesZaklecie("Nota hoc Kacper 120", {typ:'mille', gracze:[{imie:"Kacper"}]}, true)
})();

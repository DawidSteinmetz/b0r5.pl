/* Salon: magiczny notes do liczenia punktów. Idzie krokami:
     1. wybór gry: czar "Mille" (tysiąc) albo "Alea" (kości),
     2. gracze: "Lusor Primus <imię>", "Lusor Secundus <imię>"…, potem "Incipiāmus",
     3. punkty: "Nota hoc <imię> <punkty>" (w kościach "Nota hoc <imię> <układ po łacinie> <punkty>").
   Każdy czar można rzucić głosem ("Rzuć czar") albo wpisać w pole; każdą komórkę tabeli można też kliknąć i wpisać ręcznie.
   Mowę rozpoznaje przeglądarka (Web Speech API: Chrome, Edge; nagranie idzie do ich usługi rozpoznawania).
   Plik należy do pokoju (pokoj.json: "skrypt"), z rdzenia korzysta tylko przez window.Mysl. Serwer: api/notes.php. */
(function () {
  'use strict';
  const { $, esc, w, modal, gracz, arkusz, zamknij, URL_POKOJU: U } = window.Mysl;
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

  /* --- Słowa: bez ogonków, liczby, imiona ------------------------------------------------------------------- */
  const bezOgonkow = s => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/g, 'l');
  const duza = s => s.charAt(0).toUpperCase() + s.slice(1);
  const LICZBY = {
    zero: 0, jeden: 1, jedna: 1, dwa: 2, dwie: 2, trzy: 3, cztery: 4, piec: 5, szesc: 6, siedem: 7, osiem: 8, dziewiec: 9,
    dziesiec: 10, jedenascie: 11, dwanascie: 12, trzynascie: 13, czternascie: 14, pietnascie: 15, szesnascie: 16, siedemnascie: 17,
    osiemnascie: 18, dziewietnascie: 19, dwadziescia: 20, trzydziesci: 30, czterdziesci: 40, piecdziesiat: 50, szescdziesiat: 60,
    siedemdziesiat: 70, osiemdziesiat: 80, dziewiecdziesiat: 90, sto: 100, dwiescie: 200, trzysta: 300, czterysta: 400,
    piecset: 500, szescset: 600, siedemset: 700, osiemset: 800, dziewiecset: 900, tysiac: 1000, tysiace: 1000, tysiecy: 1000
  };
  // Liczba ze słów: cyfry ("120", "1 20") albo słowa ("sto dwadzieścia"); "minus" na początku daje wynik ujemny.
  function liczba(slowa) {
    let znak = 1, i = 0;
    if (slowa[0] === 'minus' || slowa[0] === '-') { znak = -1; i = 1; }
    const reszta = slowa.slice(i).filter(Boolean);
    if (!reszta.length) return null;
    if (reszta.every(s => /^-?\d+$/.test(s))) return znak * parseInt(reszta.join(''), 10);
    let suma = 0;
    for (const s of reszta) {
      if (!(s in LICZBY)) return null;
      const v = LICZBY[s];
      suma = v === 1000 ? Math.max(1, suma) * 1000 : suma + v;
    }
    return znak * suma;
  }
  function odleglosc(a, b) {
    const d = Array.from({ length: a.length + 1 }, (_, i) => [i]);
    for (let j = 1; j <= b.length; j++) d[0][j] = j;
    for (let i = 1; i <= a.length; i++) for (let j = 1; j <= b.length; j++)
      d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
    return d[a.length][b.length];
  }
  // Gracz o najbliższym imieniu (rozpoznawanie mowy myli litery i odmienia: "Kacprowi", "Majusi").
  function ktoTo(slowo, gracze) {
    const s = bezOgonkow(slowo).replace(/['"„”‘’]/g, ''), jedno = !s.includes(' '); let naj = null, wynik = 99;
    for (const g of gracze) {
      const n = bezOgonkow(g.imie);
      let o = odleglosc(s, n), wsp = 0;
      while (wsp < s.length && wsp < n.length && s[wsp] === n[wsp]) wsp++;
      if (jedno && wsp >= Math.min(4, n.length - 1) && s.length <= n.length + 4) o = Math.min(o, 1);
      if (jedno && s.length >= 3 && n.startsWith(s)) o = Math.min(o, 1);
      if (o < wynik) { wynik = o; naj = g.imie; }
    }
    return naj && wynik <= Math.max(1, Math.floor(bezOgonkow(naj).length / 3)) ? naj : null;
  }

  /* --- Czary ------------------------------------------------------------------------------------------------- */
  // Jak rozpoznawanie mowy po polsku słyszy łacińskie słowa (bez ogonków). Dłuższe warianty najpierw.
  // "Nota hoc" rozpoznawanie mowy słyszy różnie: "nota hok", "nota ok", "no to chodź", "no ta chodź", "notachoć"…
  // Zamiast listy wariantów: pierwsze 1–3 słowa sklejone razem mają brzmieć jak "no-ta-hoc":
  // n + samogłoska, t, (samogłoska), (h/ch/k/g), samogłoska, (c/k/dź/ch/…) – i nic więcej.
  const BRZMI_JAK_NOTA_HOC = /^n[aeiouy]{1,2}t{1,2}k?[aeiouy]?(?:h|ch|k|g|c)?h?[aeiouy]{1,2}(?:c|ck|k|kk|g|ch|cz|dz|dzi|dze|dzie|dzil|z|s|sz|t|h|j)?$/;
  function przedrostek(slowa) {                 // ile pierwszych słów to "Nota hoc" (0 = nie ma)
    for (let k = Math.min(3, slowa.length); k >= 1; k--) if (BRZMI_JAK_NOTA_HOC.test(slowa.slice(0, k).join(''))) return k;
    return 0;
  }
  const GRY = { mille: /^(mille|mile|mila|mili|milla|mil|mille mille)$/, alea: /^(alea|aleja|alei|ale a|alee|allea|alea jacta est)$/ };
  const ZACZNIJ = /^(incipiamus|incipjamus|incypiamus|in cipiamus|incipiam us|incipe|incipit|incipimus)$/;
  const NOWA = /^(nova|nowa|novo|nowe|nova pars|nowa gra)$/;
  const COFNIJ = /^(deleo|deleto|delo|dele|cofnij|wykresl|usun)$/;
  const LUSOR = /^(lusor|luzor|lusora|luzora|lu sor|lu zor|loser|luser|luzer|lusar|luzar|lozor|losor|lizor|lusser|louser)\s+(primus|prymus|primu|prima|primo|primas|prymas|secundus|sekundus|secundu|sekundu|sekunda|secundo|sekundos|tertius|tercjus|tercius|terciu|tercjusz|tertio|quartus|kwartus|kwartu|kwartusz|quarto|kwarta|quintus|kwintus|sextus|sekstus|septimus|septymus|octavus|oktawus)\s+(.+)$/;
  const MIEJSCA = { primus: 1, prymus: 1, primu: 1, prima: 1, primo: 1, primas: 1, prymas: 1,
    secundus: 2, sekundus: 2, secundu: 2, sekundu: 2, sekunda: 2, secundo: 2, sekundos: 2,
    tertius: 3, tercjus: 3, tercius: 3, terciu: 3, tercjusz: 3, tertio: 3,
    quartus: 4, kwartus: 4, kwartu: 4, kwartusz: 4, quarto: 4, kwarta: 4, quintus: 5, kwintus: 5, sextus: 6, sekstus: 6, septimus: 7, septymus: 7, octavus: 8, oktawus: 8 };
  const LUSORY = ['Primus', 'Secundus', 'Tertius', 'Quartus', 'Quintus', 'Sextus', 'Septimus', 'Octavus'];
  // Układy w kościach: identyfikator (jak w api/notes.php) i jak bywa wypowiadany; polskie nazwy też działają w polu.
  const UKLADY = [
    ['paria', ['duo paria', 'duo parja', 'duo pari', 'dwa paria', 'dwie pary', 'dwie pary']], ['minor', ['ordo minor', 'orto minor', 'ordo minur', 'maly strit']],
    ['maior', ['ordo maior', 'ordo major', 'ordo majer', 'orto major', 'duzy strit']], ['quattuor', ['quattuor', 'kwattuor', 'kwatuor', 'kwartuor', 'kwatuo', 'kwattuo', 'kareta']],
    ['quaterni', ['quaterni', 'kwaterni', 'kwaterny', 'czworki']], ['singuli', ['singuli', 'singulij', 'singulli', 'jedynki']], ['bini', ['bini', 'biny', 'binni', 'dwojki']],
    ['terni', ['terni', 'terny', 'tierni', 'trojki']], ['quini', ['quini', 'kwini', 'kwiny', 'kfini', 'piatki']], ['seni', ['seni', 'seny', 'senni', 'szostki']],
    ['tres', ['tres', 'tresz', 'trez', 'trejs', 'trojka']], ['par', ['par', 'parr', 'para']], ['plenus', ['plenus', 'plenuz', 'plenos', 'full', 'ful']],
    ['fortuna', ['fortuna', 'fortune', 'szansa']], ['imperator', ['imperator', 'imperatur', 'general']]
  ];
  const SKRESL = /^(skresl|skreslam|skreslone|skreslic|zero|nic|nihil|krzyzyk)$/;
  // Tekst -> {akcja, dane} | {blad} | null (to nie czar). "glos": przy mowie wpisy wymagają "Nota hoc",
  // a czary gry, graczy i "Incipiāmus" działają same, jeśli całe zdanie jest czarem.
  function rozbierz(tekst, s, glos) {
    const oryg = String(tekst).replace(/[,.:;!?]/g, ' ').replace(/['"„”‘’]/g, '').replace(/\s+/g, ' ').trim();
    let t = bezOgonkow(oryg), o = oryg;
    const np = przedrostek(t.split(' ')), zPrzedrostkiem = np > 0;
    if (np) { o = o.split(' ').slice(np).join(' '); t = t.split(' ').slice(np).join(' '); }
    for (const typ in GRY) if (GRY[typ].test(t)) return { akcja: 'wybierz', dane: { typ } };
    const lu = t.match(LUSOR);
    if (lu) {                                    // imię to dokładnie słowo po "Lusor Primus", tak jak padło
      const im = o.split(' ')[t.split(' ').length - lu[3].split(' ').length];
      return { akcja: 'gracz_nr', dane: { nr: MIEJSCA[lu[2]], imie: duza(im) } };
    }
    if (ZACZNIJ.test(t)) return { akcja: 'zacznij' };
    if (glos && !zPrzedrostkiem) return null;
    if (!t) return { blad: 'Pióro czeka na resztę zaklęcia.' };
    if (COFNIJ.test(t)) return { akcja: 'cofnij' };
    if (NOWA.test(t)) return { akcja: 'od_nowa' };
    if (!s.gracze.length) return { blad: 'Najpierw posadź graczy: Lusor Primus i imię.' };
    const slowa = t.split(' ');
    for (let k = 1; k <= Math.min(3, slowa.length); k++) {
      const im = ktoTo(slowa.slice(0, k).join(' '), s.gracze);
      if (!im) continue;
      const reszta = slowa.slice(k), r = reszta.join(' ');
      if (s.typ === 'alea') {
        for (const [kat, warianty] of UKLADY) for (const war of warianty) {
          if (r !== war && !r.startsWith(war + ' ')) continue;
          const po = r.slice(war.length).trim().split(' ').filter(Boolean);
          if (po.length === 1 && SKRESL.test(po[0])) return { akcja: 'wpisz', dane: { imie: im, kat, skresl: true } };
          const pkt = po.length ? liczba(po) : null;
          if (po.length && pkt === null) return { blad: 'Pióro nie zrozumiało liczby.' };
          return { akcja: 'wpisz', dane: pkt === null ? { imie: im, kat } : { imie: im, kat, pkt } };
        }
        if (reszta.length) continue;
        return { blad: 'Który układ? Np. „Nota hoc ' + im + ' trēs 18”.' };
      }
      if (reszta.length === 1 && /^(bomba|bombe|bomby|bomb|bombka)$/.test(reszta[0])) return { akcja: 'bomba', dane: { imie: im } };
      const pkt = liczba(reszta);
      if (pkt !== null) return { akcja: 'wpisz', dane: { imie: im, pkt } };
    }
    if (!ktoTo(slowa[0], s.gracze)) return { blad: 'Przy stoliku nie ma gracza „' + slowa[0] + '”.' };
    return { blad: s.typ === 'alea' ? 'Pióro nie zrozumiało układu.' : 'Pióro nie zrozumiało liczby.' };
  }

  /* --- Słuchanie --------------------------------------------------------------------------------------------- */
  const Rozpoznawanie = window.SpeechRecognition || window.webkitSpeechRecognition;
  let sluch = null, slucha = false, naTekst = null, naStan = () => { };
  function sluchaj(wl) {
    if (!Rozpoznawanie) return naStan('brak');
    if (!wl) { slucha = false; if (sluch) sluch.stop(); naStan('cisza'); return; }
    slucha = true;
    if (!sluch) {
      sluch = new Rozpoznawanie();
      sluch.lang = 'pl-PL'; sluch.continuous = true; sluch.interimResults = false; sluch.maxAlternatives = 3;
      sluch.onresult = e => {
        for (let i = e.resultIndex; i < e.results.length; i++) if (e.results[i].isFinal && naTekst) naTekst(Array.from(e.results[i]).map(a => a.transcript));
      };
      sluch.onerror = e => { if (e.error === 'not-allowed' || e.error === 'service-not-allowed') { slucha = false; naStan('odmowa'); } };
      sluch.onend = () => { if (slucha) { try { sluch.start(); } catch (err) { /* już słucha */ } } else naStan('cisza'); };   // Chrome kończy po ciszy: wznów
    }
    try { sluch.start(); } catch (err) { /* już słucha */ }
    naStan('slucha');
  }

  /* --- Notes ------------------------------------------------------------------------------------------------- */
  const czar = (lac, pl) => '<li><b>' + lac + '</b>' + (pl ? ' <span>' + pl + '</span>' : '') + '</li>';
  function listaCzarow(s) {
    if (s.etap === 'wybor') return czar('Mille', 'notes do tysiąca') + czar('Alea', 'notes do kości');
    if (s.etap === 'gracze') return czar('Lusor Primus <i>imię</i>', 'gracz 1') + czar('Lusor Secundus <i>imię</i>', 'gracz 2') +
      czar('Lusor Tertius <i>imię</i>', 'gracz 3') + czar('Lusor Quartus <i>imię</i>', 'gracz 4') + czar('Incipiāmus', 'zaczynamy');
    if (s.typ === 'alea') return czar('Nota hoc <i>imię</i> <i>układ</i> <i>punkty</i>', 'np. Nota hoc Majusia trēs 18') +
      czar('Nota hoc <i>imię</i> <i>układ</i> nihil', 'skreślenie') + czar('Nota hoc deleo', 'wykreśl ostatni') + czar('Nota hoc nova', 'nowa gra');
    return czar('Nota hoc <i>imię</i> <i>punkty</i>', 'np. Nota hoc Kopytko 60') + czar('Nota hoc <i>imię</i> bomba', 'po 60, przy 4 graczach po 40') +
      czar('Nota hoc deleo', 'wykreśl ostatni') + czar('Nota hoc nova', 'nowa gra');
  }
  function otworzNotes(o) {
    okno = o; arkusz('ks'); kom('');
    w.innerHTML = '<h2>' + esc(o.e.tytul) + '</h2><p class="etap" id="etap"></p><ul class="czary" id="czary"></ul>' +
      '<div class="zaklecia"><button type="button" id="ucho" aria-pressed="false">Rzuć czar</button><button type="button" id="pokazCzary" aria-expanded="false">czary ▾</button><span id="slyszy"></span></div>' +
      '<div class="pisz"><input type="text" id="zakl" aria-label="Czar" autocomplete="off"><button type="button" id="zp">Zapisz</button></div>' +
      '<div id="nt"></div><div id="arch"></div><button id="x">Zamknij</button>';
    $('#x').onclick = zamknij; modal.classList.add('on');
    // na wąskim ekranie lista czarów przy tabeli jest schowana pod przyciskiem "czary"
    $('#pokazCzary').onclick = () => { const c = $('#czary'), on = !c.classList.contains('widac'); c.classList.toggle('widac', on); $('#pokazCzary').setAttribute('aria-expanded', on); $('#pokazCzary').textContent = on ? 'czary ▴' : 'czary ▾'; };
    let s = null, ost = '', nowyWpis = -1, edytuje = false;
    const wykonaj = async (akcja, d) => {
      let r; try { r = await api(akcja, d); } catch (err) { kom('Kleks: serwer nie odpowiada.'); return; }
      kom(r.tekst || '');
      if (r.stan) { s = r.stan; ost = JSON.stringify(s); nowyWpis = (akcja === 'wpisz' || akcja === 'bomba' || akcja === 'popraw') && r.ok ? (d && d.nr !== undefined ? d.nr : s.ostatni) : -1; edytuje = false; rysuj(); }
      return r;
    };
    /* krok 1 */
    const rysujWybor = () =>
      '<div class="gry"><button type="button" data-gra="mille"><b>Mille</b><span>tysiąc</span></button><button type="button" data-gra="alea"><b>Alea</b><span>kości</span></button></div>';
    /* krok 2 */
    const rysujGraczy = () => {
      let h = '<p class="partia">' + esc(s.nazwa) + ': kto gra?</p><ol class="gracze">';
      const n = Math.min(8, s.gracze.length + 1);
      for (let i = 0; i < n; i++) {
        const g = s.gracze[i];
        h += '<li><span class="lusor">Lusor ' + LUSORY[i] + '</span><input type="text" data-nr="' + (i + 1) + '" value="' + (g ? esc(g.imie) : '') + '" placeholder="' + (g ? '' : 'imię') + '" aria-label="Gracz ' + (i + 1) + '">' +
          (g && !g.ma_wpisy ? '<button type="button" class="usun" data-nr="' + (i + 1) + '" aria-label="Usuń gracza ' + esc(g.imie) + '">×</button>' : '<span class="usun"></span>') + '</li>';
      }
      h += '</ol><div class="akcje"><button type="button" id="start"' + (s.gracze.length ? '' : ' disabled') + '>Incipiāmus — zaczynamy</button></div>';
      return h;
    };
    /* krok 3: tysiąc */
    const komorka = (x, gk, extra) => '<td class="ed' + (x && x.nr === nowyWpis ? ' swiezy' : '') + (extra || '') + '"' + (x ? ' data-nr="' + x.nr + '"' : '') + (gk !== undefined ? ' data-g="' + gk + '"' : '') + '>' +
      (x ? (x.bomba ? '<span class="bomba" title="bomba">✹</span> 0' : x.pkt < 0 ? '−' + Math.abs(x.pkt) : x.pkt) : '') + '</td>';
    const glowka = (pierwsza) => '<thead><tr>' + (pierwsza ? '<th class="kat"></th>' : '') + s.gracze.map((g, k) =>
      '<th class="ed imie' + (g.prowadzi ? ' prow' : '') + '" data-imie="' + (k + 1) + '" title="Kliknij, by zmienić imię">' + esc(g.imie) + '</th>').join('') + '</tr></thead>';
    const rysujTysiac = () => {
      // wiersze: wpis trafia do pierwszego wolnego wiersza gracza, bomba do jednego wiersza u wszystkich
      const wszystkie = []; s.gracze.forEach((g, k) => g.wpisy.forEach(x => wszystkie.push(Object.assign({ k }, x))));
      wszystkie.sort((a, b) => a.nr - b.nr);
      const kol = s.gracze.map(() => []), wolny = s.gracze.map(() => 0);
      for (let i = 0; i < wszystkie.length; i++) {
        const x = wszystkie[i];
        if (x.grupa) {
          const gr = []; while (i < wszystkie.length && wszystkie[i].grupa === x.grupa) gr.push(wszystkie[i++]); i--;
          const r = Math.max(...gr.map(y => wolny[y.k])); gr.forEach(y => { kol[y.k][r] = y; wolny[y.k] = r + 1; });
        } else { kol[x.k][wolny[x.k]] = x; wolny[x.k]++; }
      }
      const n = Math.max(...wolny, 0) + 1;                // zawsze jeden pusty wiersz do wpisania ręcznie
      let h = '<p class="partia">' + esc(s.nazwa) + ' · do ' + s.cel + '</p><table class="notes">' + glowka(false) + '<tbody>';
      for (let r = 0; r < n; r++) h += '<tr>' + s.gracze.map((g, k) => kol[k][r] ? komorka(kol[k][r]) : (r === wolny[k] ? komorka(null, k, ' pusta') : '<td></td>')).join('') + '</tr>';
      h += '</tbody><tfoot><tr>' + s.gracze.map(g => '<td' + (g.prowadzi ? ' class="prow"' : '') + '>' + g.suma + '</td>').join('') + '</tr></tfoot></table>';
      return h;
    };
    /* krok 3: kości */
    const rysujKosci = () => {
      const wiersz = (naglowek, kom, klasa) => '<tr' + (klasa ? ' class="' + klasa + '"' : '') + '><th class="kat">' + naglowek + '</th>' + s.gracze.map(kom).join('') + '</tr>';
      const uklad = u => wiersz('<b>' + esc(u.lac) + '</b> <small>' + esc(u.nazwa.toLowerCase()) + (u.stale ? ' · ' + u.stale : '') + '</small>', (g, k) => {
        const x = g.kosci.uklady[u.id];
        return '<td class="ed' + (x && x.nr === nowyWpis ? ' swiezy' : '') + (x ? '' : ' pusta') + '" data-g="' + k + '" data-kat="' + u.id + '"' + (x ? ' data-nr="' + x.nr + '"' : '') + '>' +
          (x ? (x.pkt === 0 ? '<span class="skr">✕</span>' : x.pkt) : '') + '</td>';
      });
      const suma = (nazwa, f, klasa) => wiersz(nazwa, g => '<td>' + f(g) + '</td>', klasa);
      let h = '<p class="partia">' + esc(s.nazwa) + '</p><table class="notes kosci">' + glowka(true) + '<tbody>';
      s.uklady.filter(u => u.czesc === 'gora').forEach(u => { h += uklad(u); });
      h += suma('Suma', g => g.kosci.gora, 'sum') + suma('Premia <small>(od ' + s.premia.prog + ': +' + s.premia.pkt + ')</small>', g => g.kosci.premia ? '+' + g.kosci.premia : '', 'sum');
      s.uklady.filter(u => u.czesc === 'dol').forEach(u => { h += uklad(u); });
      h += '</tbody><tfoot>' + wiersz('Razem', g => '<td' + (g.prowadzi ? ' class="prow"' : '') + '>' + g.kosci.razem + '</td>') + '</tfoot></table>';
      return h;
    };
    const rysuj = () => {
      if (!s) return;
      const KROKI = { wybor: 'Krok 1 z 3 · wybierz grę', gracze: 'Krok 2 z 3 · posadź graczy', gra: 'Krok 3 z 3 · notuj punkty' };
      $('#etap').textContent = KROKI[s.etap] || '';
      $('#czary').innerHTML = listaCzarow(s);
      $('#czary').classList.toggle('zwarte', s.etap === 'gra');      // przy tabeli lista czarów zajmuje mniej miejsca
      $('#pokazCzary').hidden = s.etap !== 'gra';
      $('#zakl').placeholder = s.etap === 'wybor' ? 'Mille albo Alea' : s.etap === 'gracze' ? 'np. Lusor Primus Kopytko' : s.typ === 'alea' ? 'np. Majusia trēs 18' : 'np. Kopytko 60 albo Borsuk bomba';
      let h = s.etap === 'wybor' ? rysujWybor() : s.etap === 'gracze' ? rysujGraczy() : s.typ === 'alea' ? rysujKosci() : rysujTysiac();
      if (s.etap === 'gra') {
        if (s.koniec) h += '<p class="wygrana">' + esc(s.koniec.zwyciezca) + ' wygrywa z wynikiem ' + s.koniec.suma + '!</p>';
        h += '<div class="akcje"><button type="button" id="cof">Wykreśl ostatni (deleo)</button><button type="button" id="nowa">Nowa gra (nova)</button></div>';
      }
      $('#nt').innerHTML = h;
      $('#arch').innerHTML = s.etap !== 'gra' && s.archiwum.length ? '<p class="grupa">Wcześniejsze partie</p>' + s.archiwum.map(a =>
        '<div class="dim">' + new Date(a.start * 1000).toLocaleDateString('pl', { day: 'numeric', month: 'short' }) + ' · ' + esc(a.nazwa) + ': ' +
        Object.entries(a.sumy).map(([im, p]) => esc(im) + ' ' + p).join(', ') + (a.zwyciezca ? ' · wygrał(a) ' + esc(a.zwyciezca) : '') + '</div>').join('') : '';
      podepnij();
    };
    /* edycja: przyciski, pola graczy, komórki tabeli */
    const podepnij = () => {
      const nt = $('#nt');
      nt.querySelectorAll('[data-gra]').forEach(b => { b.onclick = () => wykonaj('wybierz', { typ: b.dataset.gra }); });
      nt.querySelectorAll('ol.gracze input').forEach(inp => {
        const zapisz = () => { const v = inp.value.trim(), g = s.gracze[inp.dataset.nr - 1]; if (v && (!g || g.imie !== v)) wykonaj('gracz_nr', { nr: Number(inp.dataset.nr), imie: v }); };
        inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); zapisz(); } });
        inp.addEventListener('change', zapisz);
      });
      nt.querySelectorAll('button.usun').forEach(b => { b.onclick = () => wykonaj('usun_gracza', { nr: Number(b.dataset.nr) }); });
      const st = $('#start'); if (st) st.onclick = () => wykonaj('zacznij');
      const cof = $('#cof'); if (cof) cof.onclick = () => wykonaj('cofnij');
      const nowa = $('#nowa'); if (nowa) nowa.onclick = () => wykonaj('od_nowa');
      nt.querySelectorAll('.ed').forEach(c => { c.onclick = () => edytuj(c); });
    };
    const edytuj = c => {
      if (c.querySelector('input') || c.querySelector('.bomba')) return;
      edytuje = true;
      const imie = c.dataset.imie !== undefined, stare = imie ? c.textContent : (c.querySelector('.skr') ? '0' : c.textContent.replace('−', '-'));
      c.innerHTML = '<input type="text" inputmode="' + (imie ? 'text' : 'numeric') + '" value="' + esc(stare) + '" aria-label="' + (imie ? 'Imię' : 'Punkty') + '">';
      const inp = c.querySelector('input'); inp.focus(); inp.select();
      let gotowe = false;
      const koniec = zapis => {
        if (gotowe) return; gotowe = true; edytuje = false;
        const v = inp.value.trim();
        if (!zapis || v === stare || v === '') { rysuj(); return; }
        if (imie) return wykonaj('gracz_nr', { nr: Number(c.dataset.imie), imie: v });
        const pkt = liczba(bezOgonkow(v).split(' '));
        if (pkt === null) { kom('Pióro nie zrozumiało liczby.'); rysuj(); return; }
        if (c.dataset.nr !== undefined) return wykonaj('popraw', { nr: Number(c.dataset.nr), pkt });
        const g = s.gracze[Number(c.dataset.g)];
        wykonaj('wpisz', c.dataset.kat ? { imie: g.imie, kat: c.dataset.kat, pkt } : { imie: g.imie, pkt });
      };
      inp.addEventListener('keydown', e => { if (e.key === 'Enter') koniec(true); if (e.key === 'Escape') { e.stopPropagation(); koniec(false); } });
      inp.addEventListener('blur', () => koniec(true));
    };
    const pobierz = async () => {
      if (edytuje) return;                         // nie przerywaj komuś wpisywania w komórce
      let n; try { n = await api(); } catch (err) { n = { tekst: 'Notes nie odpowiada (błąd serwera).' }; }
      if (okno !== o) return;
      if (!n || !n.etap) { if (!s) $('#nt').innerHTML = '<p class="wygrana">' + esc(n && n.tekst || 'Notes nie odpowiada.') + '</p>'; return; }
      const sig = JSON.stringify(n); if (sig === ost) return;
      ost = sig; s = n; rysuj();
    };
    /* czary: głos i pole */
    const zaklecie = (warianty, glos) => {
      if (!s) return;
      if (glos) $('#slyszy').textContent = 'usłyszane: „' + String(warianty[0] || '').trim() + '”';
      let blad = null;
      for (const t of warianty) {
        const z = rozbierz(t, s, glos);
        if (!z) continue;
        if (z.blad) { blad = blad || z.blad; continue; }
        if (glos) $('#slyszy').textContent += ' → ' + opis(z);
        return wykonaj(z.akcja, Object.assign({ glos: !!glos }, z.dane || {}));
      }
      if (blad) { kom(blad); if (glos) $('#slyszy').textContent += ' → ' + blad; }
      else if (glos) $('#slyszy').textContent += ' (to nie czar: brak „Nota hoc” na początku)';
    };
    // jak pióro zrozumiało czar (widać pod przyciskiem, gdy czar był rzucony głosem)
    const opis = z => {
      const d = z.dane || {}, u = d.kat && s.uklady.find(x => x.id === d.kat);
      return ({ wybierz: 'gra: ' + d.typ, gracz_nr: 'gracz ' + d.nr + ': ' + d.imie, zacznij: 'zaczynamy', cofnij: 'wykreśl ostatni', od_nowa: 'nowa gra', bomba: 'bomba: ' + d.imie,
        wpisz: 'gracz: ' + d.imie + (u ? ', układ: ' + u.lac : '') + (d.skresl ? ', skreślone' : d.pkt !== undefined ? ', punkty: ' + d.pkt : '') })[z.akcja] || z.akcja;
    };
    const ucho = $('#ucho');
    naStan = st => {
      ucho.classList.toggle('slucha', st === 'slucha'); ucho.setAttribute('aria-pressed', st === 'slucha' ? 'true' : 'false');
      ucho.textContent = st === 'slucha' ? 'Pióro słucha… (kliknij, by przestać)' : 'Rzuć czar';
      if (st === 'brak') { ucho.disabled = true; ucho.textContent = 'Ta przeglądarka nie słyszy czarów'; }
      if (st === 'odmowa') kom('Przeglądarka nie dała dostępu do mikrofonu.');
    };
    naTekst = alt => zaklecie(alt, true);
    if (!Rozpoznawanie) naStan('brak');
    ucho.onclick = () => sluchaj(!slucha);
    const pole = $('#zakl');
    const zapiszPole = () => { const t = pole.value.trim(); if (!t) return; zaklecie([t], false); pole.value = ''; };
    $('#zp').onclick = zapiszPole;
    pole.addEventListener('keydown', e => { if (e.key === 'Enter') zapiszPole(); });
    pobierz();
    const id = setInterval(() => {
      if (!modal.classList.contains('on') || okno !== o) { clearInterval(id); if (slucha) sluchaj(false); return; }
      pobierz();
    }, 3000);
  }

  window.Mysl.rodzaj('notes', {
    v: .2, bob: .2, klasa: 'p kot', etykieta: 'notes', otworz: otworzNotes,
    ikona: '<path d="M26 14h46v72H26z"/><path d="M26 14c-6 0-8 4-8 8v60c0 4 2 6 8 4M34 30h30M34 42h30M34 54h22"/><path d="M84 18L60 66l-3 9 7-6 24-48z"/>'
  });
  window.Mysl.notesZaklecie = rozbierz;        // do testów: Mysl.notesZaklecie("Nota hoc Kacper 120", {typ:'mille', gracze:[{imie:"Kacper"}]}, true)
})();

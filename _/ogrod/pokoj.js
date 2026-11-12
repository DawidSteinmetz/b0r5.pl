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
  /* Plan rysowany piórem, jak u Artemidy: kreski z drżeniem ręki z ziarnem (ten sam plan zawsze wygląda tak samo),
     akwarelowe plamy na grządkach, płot ze sztachetami, furtka od ulicy, kreskowany dom, trawa, podziałka i róża wiatrów.
     Północ jest u góry, więc zachód (ulica) to lewa krawędź planu. */
  const WODA = { pusta: ['#8a5a2b', .22], rosnie: ['#55792a', .3], do_zbioru: ['#c08a1e', .5], martwa: ['#6d6458', .32], czar: ['#7d5aa8', .45] };
  function hash(s) { let h = 2166136261; for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); } return h >>> 0; }
  function rnd(seed) {
    return function () {
      seed |= 0; seed = seed + 0x6D2B79F5 | 0;
      let t = Math.imul(seed ^ seed >>> 15, 1 | seed);
      t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
      return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
  }
  const ziarno = k => rnd(hash('ogrod-h:' + k));
  const n2 = x => Math.round(x * 100) / 100;
  // Kreska od A do B (bez "M"): co ok. 2,5 m punkt lekko zbaczający w bok, połączony łukiem.
  function kreska(q, x0, y0, x1, y1, amp) {
    const dx = x1 - x0, dy = y1 - y0, L = Math.hypot(dx, dy) || 1, nx = -dy / L, ny = dx / L;
    const n = Math.max(1, Math.min(8, Math.round(L / 2.5)));
    let d = '', px = x0, py = y0;
    for (let i = 1; i <= n; i++) {
      const t = i / n, bok = i < n ? (q() - .5) * amp : 0, luk = (q() - .5) * 2 * amp;
      const x = x0 + dx * t + nx * bok, y = y0 + dy * t + ny * bok;
      d += 'Q' + n2((px + x) / 2 + nx * luk) + ' ' + n2((py + y) / 2 + ny * luk) + ' ' + n2(x) + ' ' + n2(y);
      px = x; py = y;
    }
    return d;
  }
  const kres = (q, x0, y0, x1, y1, amp) => 'M' + n2(x0) + ' ' + n2(y0) + kreska(q, x0, y0, x1, y1, amp);
  function czworokat(q, x, y, w, h, amp) {
    const j = () => (q() - .5) * amp * 1.4;
    const p = [[x + j(), y + j()], [x + w + j(), y + j()], [x + w + j(), y + h + j()], [x + j(), y + h + j()]];
    let d = 'M' + n2(p[0][0]) + ' ' + n2(p[0][1]);
    for (let i = 0; i < 4; i++) { const a = p[i], b = p[(i + 1) % 4]; d += kreska(q, a[0], a[1], b[0], b[1], amp); }
    return d + 'Z';
  }
  // Obrys z czterech osobnych pociągnięć, które wychodzą trochę za narożniki, jak przy rysowaniu pod linijkę.
  function obrys(q, x, y, w, h, amp, wyjscie) {
    const o = () => wyjscie * (.4 + q() * .8), j = () => (q() - .5) * amp;
    return kres(q, x - o(), y + j(), x + w + o(), y + j(), amp) + kres(q, x + w + j(), y - o(), x + w + j(), y + h + o(), amp) +
      kres(q, x + w + o(), y + h + j(), x - o(), y + h + j(), amp) + kres(q, x + j(), y + h + o(), x + j(), y - o(), amp);
  }
  function petla(q, cx, cy, rx, ry, obroty, drzenie) {
    const a0 = q() * 6.283, n = Math.round(18 * obroty), pts = [];
    for (let i = 0; i <= n; i++) {
      const a = a0 + i / n * obroty * 6.283, k = 1 + (q() - .5) * drzenie;
      pts.push(n2(cx + Math.cos(a) * rx * k) + ' ' + n2(cy + Math.sin(a) * ry * k));
    }
    return 'M' + pts.join('L');
  }
  function roslinka(q, x, y, s, kwiat) {
    const b = (q() - .5) * s * .3, t = n2(y - s);
    const d = 'M' + n2(x) + ' ' + n2(y) + 'Q' + n2(x + b) + ' ' + n2(y - s / 2) + ' ' + n2(x + b / 2) + ' ' + t +
      'M' + n2(x + b / 3) + ' ' + n2(y - s * .35) + 'q' + n2(-s * .45) + ' ' + n2(-s * .05) + ' ' + n2(-s * .5) + ' ' + n2(-s * .4) +
      'M' + n2(x + b / 2) + ' ' + n2(y - s * .55) + 'q' + n2(s * .45) + ' ' + n2(-s * .02) + ' ' + n2(s * .45) + ' ' + n2(-s * .38);
    return [d, kwiat ? [n2(x + b / 2), t] : null];
  }
  function trawa(q, x, y) {
    let d = '';
    for (let i = 0; i < 3; i++) { const bx = x + i * .2; d += 'M' + n2(bx) + ' ' + n2(y) + 'q' + n2((q() - .3) * .18) + ' ' + n2(-.18) + ' ' + n2((q() - .5) * .25) + ' ' + n2(-.3 - q() * .2); }
    return d;
  }
  function plan(s) {
    const D = s.dzialka, zaj = {}, dom = D.zabudowa[0];
    s.grzadki.forEach(g => { zaj[g.miejsce] = g; });
    // furtka: z ogrod.json ("furtka": {"bok": "zachod", "y": …}); bez ustawienia od zachodu, tuż pod domem
    const F = D.furtka || { bok: 'zachod', y: dom ? dom.y + dom.szer + 1.2 : D.szer / 2 };
    const fy = Number(F.y), fs = .9;                             // środek i pół szerokości furtki
    const napis = (x, y, k, t, obrot) => '<text x="' + n2(x) + '" y="' + n2(y) + '" class="' + k + '"' + (obrot ? ' transform="rotate(' + obrot + ' ' + n2(x) + ' ' + n2(y) + ')"' : '') + '>' + esc(t) + '</text>';
    const vb = [-3.4, -1.3, D.dl + 4.8, D.szer + 6];
    let tlo = '', tusz = '', cienki = '', grz = '', napisy = '', zazn = '';
    // ulica za zachodnim płotem: krawężnik i kocie łby
    let q = ziarno('ulica');
    cienki += kres(q, -1.9, -1, -1.9, D.szer + 1, .1);
    for (let i = 0; i < 70; i++) { const x = -3.1 + q() * 1, y = -.8 + q() * (D.szer + 1.6); cienki += petla(q, x, y, .13, .1, 1, .3); }
    napisy += '<rect class="tlo-napisu" x="-3.05" y="' + n2(D.szer * .72 - 1.3) + '" width=".85" height="2.6" rx=".25"/>' + napis(-2.4, D.szer * .72, 'maly', 'ulica', -90);
    // płot z przerwą na furtkę i sztachety co półtora metra (od zewnątrz)
    q = ziarno('plot');
    tusz += kres(q, -.3, 0, D.dl + .4, 0, .12) + kres(q, D.dl, -.4, D.dl, D.szer + .3, .12) + kres(q, D.dl + .3, D.szer, -.4, D.szer, .12) +
      kres(q, 0, D.szer + .4, 0, fy + fs, .12) + kres(q, 0, fy - fs, 0, -.3, .12);
    for (let x = 1.5; x < D.dl; x += 1.5) cienki += kres(q, x, 0, x + (q() - .5) * .1, -.4, .02) + kres(q, x, D.szer, x + (q() - .5) * .1, D.szer + .4, .02);
    for (let y = 1.5; y < D.szer; y += 1.5) { if (Math.abs(y - fy) < fs + .4) continue; cienki += kres(q, 0, y, -.4, y + (q() - .5) * .1, .02) + kres(q, D.dl, y, D.dl + .4, y + (q() - .5) * .1, .02); }
    tusz += 'M0 ' + n2(fy - fs) + 'a' + n2(fs * 2) + ' ' + n2(fs * 2) + ' 0 0 1 ' + n2(fs * 2 * .7) + ' ' + n2(fs * 2 * .7);   // skrzydło furtki otwarte do środka
    cienki += 'M0 ' + n2(fy - fs) + 'l' + n2(fs * 2 * .7) + ' ' + n2(fs * 2 * .7);
    napisy += '<rect class="tlo-napisu" x="-1.75" y="' + n2(fy - 1.35) + '" width=".8" height="2.7" rx=".25"/>' + napis(-1.15, fy, 'maly', 'furtka', -90);
    // dom: kreskowany, z liniami dachu i drzwiami od południa
    D.zabudowa.forEach(z => {
      q = ziarno('dom:' + z.nazwa);
      tlo += '<path d="' + czworokat(q, z.x, z.y, z.dl, z.szer, .15) + '" fill="url(#ogrh-kreski)" stroke="none"/>';
      tusz += obrys(q, z.x, z.y, z.dl, z.szer, .12, .35) + obrys(q, z.x + .08, z.y + .06, z.dl - .1, z.szer - .1, .1, .1);
      const r = Math.min(z.dl, z.szer) * .3, cx = z.x + z.dl / 2, cy = z.y + z.szer / 2;
      cienki += kres(q, z.x, z.y, cx - (z.dl / 2 - r), cy, .08) + kres(q, z.x, z.y + z.szer, cx - (z.dl / 2 - r), cy, .08) +
        kres(q, z.x + z.dl, z.y, cx + (z.dl / 2 - r), cy, .08) + kres(q, z.x + z.dl, z.y + z.szer, cx + (z.dl / 2 - r), cy, .08) +
        kres(q, cx - (z.dl / 2 - r), cy, cx + (z.dl / 2 - r), cy, .06);
      napisy += '<rect class="tlo-napisu" x="' + n2(cx - 1.4) + '" y="' + n2(cy - .85) + '" width="2.8" height="1.25" rx=".3"/>' + napis(cx, cy + .1, 'sredni', z.nazwa.toLowerCase());
    });
    // ścieżka od furtki wzdłuż południowej ściany domu do grządek, z drzwiami po drodze
    const sx = dom ? dom.x + dom.dl + .4 : 6, dz = dom ? dom.x + dom.dl * .6 : 4;
    q = ziarno('sciezki');
    cienki += kres(q, .3, fy - .6, sx, fy - .6, .1) + kres(q, .3, fy + .6, sx, fy + .6, .1);
    if (dom && fy - .6 > dom.y + dom.szer) cienki += kres(q, dz - .5, dom.y + dom.szer, dz - .5, fy - .6, .05) + kres(q, dz + .5, dom.y + dom.szer, dz + .5, fy - .6, .05);
    for (let x = .9; x < sx - .3; x += .9) cienki += 'M' + n2(x + (q() - .5) * .2) + ' ' + n2(fy + (q() - .5) * .5) + 'h.01';   // żwir
    // trawa wszędzie tam, gdzie nie ma grządki, domu ani ścieżki
    const zajete = (x, y) => D.miejsca.some(m => x > m.x - .25 && x < m.x + m.dl + .1 && y > m.y - .1 && y < m.y + m.szer + .45) ||
      D.zabudowa.some(z => x > z.x - .3 && x < z.x + z.dl + .2 && y > z.y - .2 && y < z.y + z.szer + .5) || (x < sx + .3 && Math.abs(y - fy) < .9);
    q = ziarno('trawa');
    for (let i = 0; i < 520; i++) { const x = .3 + q() * (D.dl - .9), y = .7 + q() * (D.szer - 1); if (!zajete(x, y)) cienki += trawa(q, x, y); }
    // grządki i wolne miejsca
    D.miejsca.forEach((m, i) => {
      const g = zaj[i], wyb = wybor && ((g && wybor.g === g.id) || (!g && wybor.m === i));
      q = ziarno('m' + i + (g ? ':' + g.id : ''));
      const traf = '<rect x="' + n2(m.x - .25) + '" y="' + n2(m.y - .3) + '" width="' + n2(m.dl + .5) + '" height="' + n2(m.szer + .6) + '" fill="transparent"/>';
      if (wyb) { const qz = ziarno('wyb' + i); zazn += petla(qz, m.x + m.dl / 2, m.y + m.szer / 2, m.dl / 2 + .6, m.szer / 2 + .6, 1.25, .1); }
      if (!g) {
        grz += '<g class="miejsce" data-m="' + i + '"><title>wolne miejsce</title>' + traf + '<path class="olowek" d="' + obrys(q, m.x, m.y, m.dl, m.szer, .08, .05) + '"/></g>';
        return;
      }
      const stan = g.czar ? 'czar' : g.stan, r = g.roslina, [kol, op0] = WODA[stan] || WODA.pusta;
      const op = stan === 'rosnie' ? op0 + .3 * (r.postep || 0) : op0;
      let d = '', kropki = '', nasiona = '';
      if (stan === 'pusta' || (r && r.faza === 'posiane')) for (let k = 1; k < 4; k++) d += kres(q, m.x + m.dl * k / 4, m.y + .4, m.x + m.dl * k / 4, m.y + m.szer - .4, .05);
      if (r && r.faza === 'posiane') for (let y = m.y + .6; y < m.y + m.szer - .4; y += .45) nasiona += 'M' + n2(m.x + m.dl / 2 + (q() - .5) * .1) + ' ' + n2(y) + 'h.01';
      if (r && !r.martwa && r.faza !== 'posiane') {
        const sz = .45 + .5 * Math.min(1, r.postep || 0);
        for (let k = 0; k < 3; k++) {
          const [p, kw] = roslinka(q, m.x + m.dl / 2 + (q() - .5) * .3, m.y + m.szer * (k + .85) / 3.1, sz, stan === 'do_zbioru');
          d += p; if (kw) kropki += 'M' + kw[0] + ' ' + kw[1] + 'h.01';
        }
      }
      if (r && r.martwa) d += kres(q, m.x + .1, m.y + 1, m.x + m.dl - .1, m.y + m.szer - 1, .1) + kres(q, m.x + m.dl - .1, m.y + 1, m.x + .1, m.y + m.szer - 1, .1);
      if (g.chwasty > 0.3) for (let k = 0; k < Math.round(g.chwasty * 7); k++) {
        const x = m.x + .15 + q() * (m.dl - .4), y = m.y + .3 + q() * (m.szer - .5);
        d += 'M' + n2(x) + ' ' + n2(y) + 'c.1 -.25 .3 -.25 .25 0s-.15 .15 -.05 .1';
      }
      const dq = ziarno('plama' + i);
      grz += '<g class="grz ' + stan + '" data-g="' + esc(g.id) + '"><title>' + esc(r ? r.nazwa + ': ' + r.faza_t : (g.czar ? g.stan : 'pusta grządka')) + '</title>' + traf +
        '<path class="plama" d="' + czworokat(dq, m.x + (dq() - .5) * .2, m.y + (dq() - .5) * .3, m.dl, m.szer, .12) + '" fill="' + kol + '" fill-opacity="' + n2(op) + '"/>' +
        '<path class="obrys" d="' + obrys(q, m.x, m.y, m.dl, m.szer, .08, .12) + '"/>' +
        (d ? '<path class="detal" d="' + d + '"/>' : '') + (kropki ? '<path class="kropki" d="' + kropki + '"/>' : '') + (nasiona ? '<path class="nasiona" d="' + nasiona + '"/>' : '') + '</g>';
    });
    // pod płotem: tytuł, podziałka 10 m i strzałka północy
    const ly = D.szer + 2.4;
    napisy += napis(D.dl / 2, ly + .2, 'duzy', 'Ogród') + napis(D.dl / 2, ly + 1.5, 'maly', D.dl + ' × ' + D.szer + ' m');
    q = ziarno('podzialka');
    const px = D.dl - 14;
    tusz += kres(q, px, ly, px + 10, ly, .04);
    for (let k = 0; k <= 10; k += 5) cienki += kres(q, px + k, ly - .35, px + k, ly + .35, .02);
    napisy += napis(px + 5, ly + 1.3, 'maly', '10 m');
    const nx = D.dl - 1.5;
    tusz += kres(q, nx, ly + 1.4, nx, ly - 1.2, .05) + 'M' + n2(nx - .45) + ' ' + n2(ly - .4) + 'L' + n2(nx) + ' ' + n2(ly - 1.4) + 'L' + n2(nx + .45) + ' ' + n2(ly - .4);
    napisy += napis(nx - 1, ly - .5, 'sredni', 'N');
    if (s.oslona_do) napisy += napis(D.dl / 2 + 5, -.4, 'maly', 'nad grządkami osłona przed przymrozkiem');
    return '<svg class="plan" viewBox="' + vb.join(' ') + '" style="aspect-ratio:' + n2(vb[2]) + '/' + n2(vb[3]) + '" role="img" aria-label="Plan działki">' +
      '<defs><filter id="ogrh-pioro" x="-5%" y="-5%" width="110%" height="110%"><feTurbulence type="fractalNoise" baseFrequency="1.1" numOctaves="2" seed="' + (hash('pioro-h') % 97) + '" result="n"/>' +
      '<feDisplacementMap in="SourceGraphic" in2="n" scale=".12" xChannelSelector="R" yChannelSelector="G"/></filter>' +
      '<pattern id="ogrh-kreski" width=".5" height=".5" patternUnits="userSpaceOnUse" patternTransform="rotate(38)"><path d="M0 .25h.5" stroke="#2b1d10" stroke-width=".05" stroke-opacity=".55"/></pattern></defs>' +
      '<g filter="url(#ogrh-pioro)">' + tlo + '<path class="cienki" d="' + cienki + '"/><path class="tusz" d="' + tusz + '"/>' + grz +
      (zazn ? '<path class="zazn" d="' + zazn + '"/>' : '') + '</g>' + napisy + '</svg>';
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
  /* Rozmiar planu liczy się z miejsca na kartce: jeśli pod tekstem zostało co najmniej 45% strony, plan wypełnia
     resztę pierwszej strony, a jeśli nie, dostaje całą następną. Nigdy nie jest szerszy niż kolumna (ani niż 640 px). */
  function dopasujPlan() {
    const svg = w.querySelector('svg.plan'); if (!svg) return;
    const vb = (svg.getAttribute('viewBox') || '').split(' ').map(Number);
    svg.style.width = 'auto'; svg.style.height = '8px';
    const H = w.clientHeight, cw = w.clientWidth;
    if (!H || !cw || vb.length < 4) { svg.style.width = svg.style.height = ''; return; }
    // offsetTop akapitu nad planem, a nie getBoundingClientRect: podczas animacji obrotu kartka jest przekręcona i pomniejszona
    // (sam element SVG nie ma offsetTop)
    const nad = svg.previousElementSibling;
    const gora = nad ? nad.offsetTop + nad.offsetHeight + 8 : 0;
    const reszta = H - gora - 14;
    let h = reszta >= 0.45 * H ? reszta : H - 14;
    h = Math.min(h, (Math.min(cw, 640) - 2) * vb[3] / vb[2]);
    svg.style.height = Math.max(100, Math.floor(h)) + 'px';
  }
  addEventListener('resize', () => requestAnimationFrame(dopasujPlan));
  document.addEventListener('mysl:obrot', () => requestAnimationFrame(dopasujPlan));
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
      dopasujPlan();
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
    const opis = w.querySelector('.tekst');                 // opis wczytuje się osobno i przesuwa plan w dół
    if (opis) new MutationObserver(dopasujPlan).observe(opis, { childList: true });
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

/* Rdzeń Myśloodsiewni: ogólny szkielet, taki sam dla każdej postaci. Nie wie nic o konkretnych pokojach.
   Strona główna (body bez data-pokoj) pokazuje pokoje jako lewitujące pergaminy.
   Pokój (body data-pokoj="id") pokazuje swoje elementy. Dane: api/indeks.php, wspólny stan: api/stan.php.
   Pokój z własną mechaniką podaje w pokoj.json pola "skrypt" i "styl"; skrypt rejestruje rodzaje elementów
   przez window.Mysl.rodzaj(nazwa, opis). Przykład: piwnica/pokoj.js. */
(function () {
  'use strict';
  const SK = document.currentScript;
  const BASE = new URL('../../', SK.src).href;
  const body = document.body;
  const POKOJ = body.dataset.pokoj || '';
  const URL_POKOJU = BASE + (POKOJ ? POKOJ + '/' : '');
  const $ = s => document.querySelector(s);

  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const inl = s => esc(s).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\*(.+?)\*/g, '<i>$1</i>');
  function md(t) {
    t = t.replace(/\r/g, '').replace(/^﻿/, '').replace(/^\s*#\s+.*\n?/, '');
    return t.split(/\n{2,}/).map(b => {
      b = b.trim(); if (!b) return '';
      const h = b.match(/^#{2,}\s+(.*)$/);
      return h ? '<h3>' + inl(h[1]) + '</h3>' : '<p>' + inl(b).replace(/\n/g, '<br>') + '</p>';
    }).join('');
  }
  function hash(s) { let h = 2166136261; for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); } return h >>> 0; }
  /* Ziarno kartki: z id elementu powstaje stały, niepowtarzalny wygląd (postrzępienie krawędzi, gradient,
     plamy, odcień, zaokrąglenia). Ten sam przedmiot zawsze wygląda tak samo, a dwa różne prawie nigdy. */
  const N_FILTROW = 24;
  const FILTRY = '<svg width="0" height="0" style="position:absolute" aria-hidden="true">' + Array.from({ length: N_FILTROW }, (_, i) => {
    const q = rnd(hash('filtr' + i));
    return '<filter id="rough' + i + '" x="-10%" y="-10%" width="120%" height="120%"><feTurbulence type="fractalNoise" baseFrequency="' +
      (0.03 + q() * 0.045).toFixed(3) + ' ' + (0.03 + q() * 0.045).toFixed(3) + '" numOctaves="' + (3 + Math.floor(q() * 2)) + '" seed="' + (1 + i * 7) +
      '" result="n"/><feDisplacementMap in="SourceGraphic" in2="n" scale="' + (6 + q() * 5).toFixed(1) + '"/></filter>';
  }).join('') + '</svg>';
  function ziarnoKartki(el, id) {
    const q = rnd(hash('kartka:' + id)), st = el.style, pr = (a, b) => (a + q() * (b - a)).toFixed(1);
    const hue = pr(33, 47), li = q() * 8 - 4;
    st.setProperty('--parch', 'hsl(' + hue + ',' + pr(45, 60) + '%,' + (77 + li).toFixed(1) + '%)');
    st.setProperty('--parch2', 'hsl(' + (hue - 2) + ',' + pr(36, 50) + '%,' + (63 + li).toFixed(1) + '%)');
    st.setProperty('--gx', pr(15, 85) + '%'); st.setProperty('--gy', pr(8, 55) + '%');
    st.setProperty('--s1x', pr(55, 92) + '%'); st.setProperty('--s1y', pr(60, 95) + '%');
    st.setProperty('--s2x', pr(5, 40) + '%');  st.setProperty('--s2y', pr(45, 90) + '%');
    st.setProperty('--s3x', pr(30, 85) + '%'); st.setProperty('--s3y', pr(2, 30) + '%');
    st.setProperty('--rad', pr(2, 6) + 'px ' + pr(4, 12) + 'px ' + pr(2, 7) + 'px ' + pr(4, 11) + 'px');
    st.setProperty('--rough', 'url(#rough' + Math.floor(q() * N_FILTROW) + ')');
  }
  let ziarnoArk = '';
  function rnd(seed) {
    return function () {
      seed |= 0; seed = seed + 0x6D2B79F5 | 0;
      let t = Math.imul(seed ^ seed >>> 15, 1 | seed);
      t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
      return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
  }
  async function json(url) {
    const r = await fetch(url, { cache: 'no-store' });
    if (!r.ok) throw new Error(r.status);
    return r.json();
  }
  const dane = pokoj => json(BASE + 'api/indeks.php' + (pokoj ? '?pokoj=' + encodeURIComponent(pokoj) : ''));

  /* anonimowy, krótki identyfikator gracza (bez logowania) */
  let gracz = Math.random().toString(36).slice(2, 8);
  try { gracz = localStorage.getItem('mgracz') || gracz; localStorage.setItem('mgracz', gracz); } catch (e) { /* tryb prywatny */ }
  async function wyslij(akcja, d) {
    const r = await fetch(BASE + 'api/stan.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ akcja, gracz }, d))
    });
    return r.json();
  }

  /* Przejście między pokojami: mgła powoli gęstnieje, w środku blado pojawia się nazwa miejsca, strona przechodzi dalej
     pod osłoną mgły, a w nowym pokoju mgła równie powoli rzednie. Nowa strona wie o przejściu z sessionStorage. */
  const RUCH = !matchMedia('(prefers-reduced-motion: reduce)').matches;
  const MGLA_GESTNIEJE = RUCH ? 2600 : 500, MGLA_RZEDNIE = RUCH ? 3600 : 500;
  let mgla = null, przechodzi = false;
  function warstwaMgly(napis) {
    if (mgla) mgla.remove();
    mgla = document.createElement('div');
    mgla.id = 'przejscie'; mgla.setAttribute('aria-hidden', 'true');
    // kłęby mgły napływają z różnych stron (położenie startowe w --x/--y, każdy dryfuje własnym rytmem)
    const KLEBY = [[-60, -45, 11], [55, -50, 13], [-55, 50, 12], [60, 45, 14], [0, -70, 15], [0, 70, 10], [-75, 0, 16], [75, 5, 12]];
    mgla.innerHTML = KLEBY.map(([x, y, t], i) => '<div class="kleb" style="--x:' + x + 'vmax;--y:' + y + 'vmax;--dryf:' + t + 's;--opozn:-' + (i * 1.7) + 's"></div>').join('') +
      '<span class="cel"></span>';
    mgla.querySelector('.cel').textContent = napis || '';
    body.appendChild(mgla);
    return mgla;
  }
  function przejdz(url, napis) {
    if (przechodzi) return;
    przechodzi = true;
    try { sessionStorage.setItem('mprzejscie', JSON.stringify({ t: Date.now(), napis: napis || '' })); } catch (e) { /* tryb prywatny */ }
    const m = warstwaMgly(napis);
    m.style.setProperty('--ms', MGLA_GESTNIEJE + 'ms');
    void m.offsetWidth;                                   // najpierw przezroczysta, dopiero potem gęstnieje
    m.classList.add('gesta');
    setTimeout(() => { location.href = url; }, MGLA_GESTNIEJE + 150);
  }
  function rozprosz() {
    const m = mgla; if (!m || !m.classList.contains('gesta')) return;
    m.style.setProperty('--ms', MGLA_RZEDNIE + 'ms');
    m.classList.remove('od-razu'); void m.offsetWidth;
    m.classList.remove('gesta'); m.classList.add('rzednie');
    setTimeout(() => { if (mgla === m) { m.remove(); mgla = null; } }, MGLA_RZEDNIE + 200);
  }
  let przybycie = null;
  try { przybycie = JSON.parse(sessionStorage.getItem('mprzejscie') || 'null'); sessionStorage.removeItem('mprzejscie'); } catch (e) { /* tryb prywatny */ }
  if (przybycie && Date.now() - przybycie.t < 20000) warstwaMgly(przybycie.napis).classList.add('gesta', 'od-razu');
  // powrót przyciskiem "wstecz": przeglądarka może pokazać stronę z pamięci, jeszcze zasnutą mgłą
  addEventListener('pageshow', e => { if (e.persisted && mgla) { przechodzi = false; mgla.remove(); mgla = null; } });

  /* tło, zegar, kalendarz, okno pergaminu */
  body.insertAdjacentHTML('afterbegin',
    '<svg width="0" height="0" style="position:absolute" aria-hidden="true">' + FILTRY + '</svg>' +
    '<div class="fog a"></div><div class="fog b"></div><div id="sky"></div>' +
    '<div class="corner l" id="clock"><svg viewBox="0 0 100 100"><circle cx="50" cy="54" r="40"/><path d="M44 10h12M50 10v4M50 20v4M50 84v4M16 54h4M80 54h4"/><line id="hh" x1="50" y1="54" x2="50" y2="36"/><line id="mh" x1="50" y1="54" x2="50" y2="26"/></svg></div>' +
    '<div class="corner r" id="cal"><small id="cw"></small><b id="cd"></b><small id="cm"></small></div>' +
    '<div id="modal"><div id="sheet" class="a5"><div id="view"><div id="w"></div></div>' +
    '<button id="zam" type="button" aria-label="Zamknij"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button>' +
    '<button id="obr" type="button" aria-label="Obróć kartkę" title="Obróć kartkę"><svg viewBox="0 0 24 24"><path d="M19.5 12a7.5 7.5 0 1 1-2.2-5.3"/><path d="M18.2 2.8v4.3h-4.3"/>' +
    '<path class="gw" d="M11 8.6l.75 2.15 2.15.75-2.15.75L11 14.4l-.75-2.15-2.15-.75 2.15-.75zM15.6 13.6l.4 1.1 1.1.4-1.1.4-.4 1.1-.4-1.1-1.1-.4 1.1-.4z"/></svg></button>' +
    '<div id="pager" hidden>' +
    '<button id="prev" type="button" aria-label="Poprzednia strona"><svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>' +
    '<span id="pnr"></span>' +
    '<button id="next" type="button" aria-label="Następna strona"><svg viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg></button>' +
    '</div></div></div>');
  const sky = $('#sky'), modal = $('#modal'), sheet = $('#sheet'), w = $('#w');

  /* czas: godzina serwera w strefie świata (domyślnie Europe/Warsaw), niezależnie od komputera gracza */
  let rok = 1914, strefa = 'Europe/Warsaw', offset = 0;
  function zegar() {
    const d = new Date(Date.now() + offset);
    const p = {};
    new Intl.DateTimeFormat('en-GB', { timeZone: strefa, hourCycle: 'h23', hour: 'numeric', minute: 'numeric', day: 'numeric' })
      .formatToParts(d).forEach(x => { p[x.type] = x.value; });
    const h = (+p.hour) % 12, m = +p.minute;
    $('#hh').setAttribute('transform', 'rotate(' + ((h + m / 60) * 30) + ' 50 54)');
    $('#mh').setAttribute('transform', 'rotate(' + (m * 6) + ' 50 54)');
    $('#cw').textContent = new Intl.DateTimeFormat('pl-PL', { timeZone: strefa, weekday: 'long' }).format(d);
    $('#cd').textContent = +p.day;
    $('#cm').textContent = new Intl.DateTimeFormat('pl-PL', { timeZone: strefa, day: 'numeric', month: 'long' }).format(d).replace(/^\d+\s/, '') + ' ' + rok;
  }

  /* wspólny stan (api/stan.php), odpytywany co 5 s */
  let stanOK = false, stan = {}, sigMiejsc = null;
  function znaczniki() {
    const P = new Set(stan.przeczytane || []);
    for (const o of items) o.el.classList.toggle('przeczytane', P.has(o.e.kid));
  }
  async function odswiez() {
    let s;
    try {
      const t0 = Date.now();
      s = await json(BASE + 'api/stan.php');
      offset = s.czas - (t0 + Date.now()) / 2;
    } catch (e) { stanOK = false; return; }
    if (!Array.isArray(s.przeczytane)) { stanOK = false; return; }
    stanOK = true; stan = s; strefa = s.strefa || strefa;
    zegar(); znaczniki();
    const sig = JSON.stringify(s.miejsca || {}) + '#' + (s.listy || 0);   // przeniesione przedmioty i nowe listy
    const zmiana = sigMiejsc !== null && sig !== sigMiejsc;
    sigMiejsc = sig;
    if (zmiana && POKOJ) await przeladuj();
  }
  async function przeladuj() {          // coś zostało przeniesione albo przyszedł list
    let d;
    try { d = await dane(POKOJ); } catch (e) { return; }
    const kidy = new Set((d.elementy || []).map(e => e.kid));
    for (let i = items.length - 1; i >= 0; i--) {
      if (!kidy.has(items[i].e.kid)) { items[i].el.remove(); znane.delete(items[i].e.kid); items.splice(i, 1); }
    }
    zbuduj((d.elementy || []).filter(e => !znane.has(e.kid)).map(e => ({ e, kind: e.rodzaj || 'przedmiot' })), true);
    znaczniki();
  }

  /* okno pergaminu: zawsze kartka A5 (krótkie rzeczy) albo A4 (księgi, waga, kociołek).
     Treść, która się nie mieści, przechodzi na kolejne strony (kolumny CSS, więc działa też
     na treści odświeżanej w tle). Przycisk strzałki przewraca stronę. */
  let strona = 0, stron = 1, auto = false;
  const pager = $('#pager'), pnr = $('#pnr'), bPrev = $('#prev'), bNext = $('#next');
  function arkusz(klasa) {
    auto = klasa !== 'ks';                    // krótkie: zacznij od A5, a gdy się nie mieści, przejdź na A4
    sheet.className = klasa === 'ks' ? 'a4' : 'a5';
    if (moznaObrocic() && wolePoziom()) sheet.classList.add('poziom');
    ziarnoKartki(sheet, ziarnoArk);          // każda kartka w oknie ma własne ziarno
    strona = 0; stron = 1;
    paginuj();
  }
  function paginuj() {
    const cw = w.clientWidth;
    if (!cw) return;
    // przy dwóch szpaltach (rozłożony zeszyt) strona to obie szpalty razem z odstępem między nimi
    const odstep = parseFloat(getComputedStyle(w).columnGap) || 0, krok = cw + odstep;
    stron = Math.max(1, Math.ceil((w.scrollWidth + odstep - 2) / krok));
    if (auto && stron > 1 && sheet.classList.contains('a5')) { auto = false; sheet.classList.replace('a5', 'a4'); return paginuj(); }
    // obrócona kartka, na której treść się nie mieści: transmutacja w rozłożony zeszyt na cały ekran (pokoje dostają 'mysl:obrot')
    if (stron > 1 && sheet.classList.contains('poziom') && !sheet.classList.contains('rozklad') && !sheet.classList.contains('a5')) {
      sheet.classList.add('rozklad'); document.dispatchEvent(new CustomEvent('mysl:obrot')); return paginuj();
    }
    strona = Math.min(strona, stron - 1);
    w.style.transform = 'translateX(' + (-strona * krok) + 'px)';
    pager.hidden = stron < 2;
    pnr.textContent = (strona + 1) + ' / ' + stron;
    bPrev.style.visibility = strona > 0 ? 'visible' : 'hidden';
    bNext.style.visibility = strona < stron - 1 ? 'visible' : 'hidden';
  }
  function przewroc(d) {
    const n = Math.max(0, Math.min(stron - 1, strona + d));
    if (n !== strona) { strona = n; paginuj(); }
  }
  let ramka = 0;
  new MutationObserver(() => { cancelAnimationFrame(ramka); ramka = requestAnimationFrame(paginuj); })
    .observe(w, { childList: true, subtree: true, characterData: true });
  addEventListener('resize', () => {
    if (sheet.classList.contains('poziom') && !moznaObrocic()) { sheet.classList.remove('poziom', 'rozklad'); document.dispatchEvent(new CustomEvent('mysl:obrot')); }
    paginuj();
  });
  bPrev.onclick = () => przewroc(-1);
  bNext.onclick = () => przewroc(1);
  $('#zam').onclick = () => zamknij();

  /* Magiczny obrót kartki: na komputerze z szerokim ekranem pionową kartkę można przepisać na poziom (i z powrotem).
     Wybór zapamiętuje przeglądarka gościa, więc następne kartki otwierają się tak samo. Pokój, który rysuje coś
     zależnego od ułożenia (plan ogrodu), słucha zdarzenia 'mysl:obrot' i pyta Mysl.poziom(). */
  const EKRAN_POZIOMY = matchMedia('(orientation: landscape) and (hover: hover) and (pointer: fine)');
  function moznaObrocic() { return EKRAN_POZIOMY.matches; }
  function wolePoziom() { try { return localStorage.getItem('mpoziom') === '1'; } catch (e) { return false; } }
  let obraca = false;
  async function obroc() {
    if (obraca) return;
    obraca = true;
    const naPoziom = !sheet.classList.contains('poziom'), ruch = !matchMedia('(prefers-reduced-motion: reduce)').matches;
    try { localStorage.setItem('mpoziom', naPoziom ? '1' : '0'); } catch (e) { /* tryb prywatny */ }
    const kat = naPoziom ? -90 : 90;
    if (ruch) {
      gwiazdki();
      await sheet.animate([{ transform: 'none', opacity: 1, filter: 'none' }, { transform: 'rotate(' + kat / 2 + 'deg) scale(.82)', opacity: .15, filter: 'blur(2px)' }],
        { duration: 320, easing: 'ease-in' }).finished.catch(() => { });
    }
    sheet.classList.toggle('poziom', naPoziom);
    sheet.classList.remove('rozklad');                // rozkładówka powstaje sama, jeśli treść nie zmieści się na kartce
    strona = 0; paginuj();
    document.dispatchEvent(new CustomEvent('mysl:obrot'));
    if (ruch) await sheet.animate([{ transform: 'rotate(' + -kat / 2 + 'deg) scale(.82)', opacity: .15, filter: 'blur(2px)' }, { transform: 'none', opacity: 1, filter: 'none' }],
      { duration: 380, easing: 'ease-out' }).finished.catch(() => { });
    obraca = false;
  }
  function gwiazdki() {                      // iskierki rozsypujące się spod kartki
    const r = sheet.getBoundingClientRect(), cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    for (let i = 0; i < 14; i++) {
      const g = document.createElement('span'), a = Math.random() * 6.283, d = Math.max(r.width, r.height) * (.45 + Math.random() * .3);
      g.className = 'iskra'; g.textContent = i % 3 ? '✦' : '✧';
      g.style.left = cx + 'px'; g.style.top = cy + 'px'; g.style.fontSize = (10 + Math.random() * 12) + 'px';
      modal.appendChild(g);
      g.animate([{ transform: 'translate(-50%,-50%) scale(.3)', opacity: 0 }, { opacity: 1, offset: .25 },
        { transform: 'translate(calc(-50% + ' + Math.cos(a) * d + 'px),calc(-50% + ' + Math.sin(a) * d + 'px)) scale(1) rotate(' + (Math.random() * 180) + 'deg)', opacity: 0 }],
        { duration: 700 + Math.random() * 400, easing: 'ease-out' }).finished.then(() => g.remove(), () => g.remove());
    }
  }
  $('#obr').onclick = obroc;
  addEventListener('keydown', e => {
    if (!modal.classList.contains('on') || /^(INPUT|SELECT|TEXTAREA)$/.test((e.target || {}).tagName)) return;
    if (e.key === 'ArrowRight' || e.key === 'PageDown') przewroc(1);
    if (e.key === 'ArrowLeft' || e.key === 'PageUp') przewroc(-1);
  });
  let czasKola = 0;
  sheet.addEventListener('wheel', e => {
    if (stron < 2 || Math.abs(e.deltaY) < 8 || Date.now() - czasKola < 350) return;
    czasKola = Date.now(); przewroc(e.deltaY > 0 ? 1 : -1); e.preventDefault();
  }, { passive: false });
  let dotykX = null;
  sheet.addEventListener('touchstart', e => { dotykX = e.touches[0].clientX; }, { passive: true });
  sheet.addEventListener('touchend', e => {
    if (dotykX == null) return;
    const dx = e.changedTouches[0].clientX - dotykX; dotykX = null;
    if (Math.abs(dx) > 50) przewroc(dx < 0 ? 1 : -1);
  }, { passive: true });

  /* okno pergaminu */
  function zamknij() { modal.classList.remove('on'); }
  modal.addEventListener('click', e => { if (e.target === modal) zamknij(); });
  addEventListener('keydown', e => { if (e.key === 'Escape') zamknij(); });
  async function tresc(e, el) {
    try {
      const r = await fetch(BASE + e.plik, { cache: 'no-store' });
      if (!r.ok) throw new Error(r.status);
      el.innerHTML = md(await r.text());
    } catch (err) { el.innerHTML = '<p><i>Pismo jest zbyt zamazane, by je odczytać.</i></p>'; }
  }
  /* Przedmiot przenośny: przyciski przeniesienia do pozostałych pokoi, w których ma swój plik.
     Plik w tym pokoju przechodzi na "off", a w docelowym na "on". */
  function przenoszenie(e, el) {
    if (!el || !e.przenies || !e.przenies.length) return;
    el.innerHTML = e.przenies.map(c => '<button type="button" data-do="' + esc(c.kid) + '">Przenieś: ' + esc(c.nazwa) + '</button>').join('');
    el.querySelectorAll('button').forEach(b => {
      b.onclick = async () => {
        b.disabled = true;
        let r;
        try { r = await wyslij('przenies', { id: e.kid, do: b.dataset.do }); } catch (err) { r = { tekst: 'Nie udało się tego przenieść.' }; }
        el.innerHTML = '<p><i>' + esc(r.tekst || '') + '</i></p>';
        odswiez();
      };
    });
  }
  function otworz(o) {
    const e = o.e;
    ziarnoArk = String(e.kid || e.id || '');
    if (RODZAJE[o.kind].otworz) return RODZAJE[o.kind].otworz(o);
    if (o.kind === 'pokoj') return przejdz(BASE + e.id + '/', e.nazwa);   // do pokoju wchodzi się przez mgłę
    arkusz(o.kind === 'ksiazka' ? 'ks' : '');
    {
      w.innerHTML = '<h2>' + esc(e.tytul) + '</h2><div class="tekst"></div><div id="przen"></div><button id="x">Zamknij</button>';
      if (stanOK && e.kid) {
        wyslij('otworz', { id: e.kid }).catch(() => { });
        if (!(stan.przeczytane || []).includes(e.kid)) { (stan.przeczytane = stan.przeczytane || []).push(e.kid); znaczniki(); }
      }
      przenoszenie(e, $('#przen'));
      tresc(e, w.querySelector('.tekst'));
    }
    $('#x').onclick = zamknij;
    modal.classList.add('on');
  }

  /* Echo wspomnienia: myśloodsiewnia przypomina sobie drogę pergaminu. W miejscu, gdzie był kilka sekund wcześniej,
     wyłania się jego półprzezroczysty duch, który płynie za nim i gaśnie. Przy dłuższym najechaniu myszą,
     a czasem samo z siebie. */
  function echo(o) {
    if (!RUCH || !o.slad || o.slad.length < 6 || o.acc || !o.el.isConnected) return;
    const teraz = performance.now(), dawno = o.slad.find(p => teraz - p[0] < 4500) || o.slad[0];
    if (Math.hypot(dawno[1] - o.x, dawno[2] - o.y) < 25) return;      // stał w miejscu: nie ma czego wspominać
    const g = o.el.cloneNode(true);
    g.classList.add('echo'); g.classList.remove('przywolany', 'nowy'); g.removeAttribute('tabindex'); g.setAttribute('aria-hidden', 'true');
    o.el.parentNode.insertBefore(g, o.el);
    const sk = (p) => ({ translate: p[1] + 'px ' + p[2] + 'px', rotate: p[3] + 'deg' });
    g.animate([Object.assign({ opacity: 0 }, sk(dawno)), { opacity: .42, offset: .3 }, Object.assign({ opacity: 0 }, sk([0, o.x, o.y, o.a]))],
      { duration: 2600, easing: 'ease-in-out', fill: 'forwards' }).finished.then(() => g.remove(), () => g.remove());
  }
  setInterval(() => {                                    // czasem wspomnienie wraca samo, mniej więcej raz na minutę
    if (document.hidden || modal.classList.contains('on') || Math.random() > .5) return;
    const widoczne = items.filter(o => !o.acc && o.x > 0 && o.y > 0 && o.x < innerWidth - 150 && o.y < innerHeight - 200);
    if (widoczne.length) echo(widoczne[Math.floor(Math.random() * widoczne.length)]);
  }, 30000);

  /* Strach przed zamknięciem: w pełnym ekranie (F11), kiedy mysz podchodzi do górnej krawędzi, gdzie przeglądarka
     pokazuje przycisk wyjścia, wszystko zaczyna drżeć (tym mocniej, im dłużej mysz tam zostaje) i słychać szept.
     Kiedy mysz odejdzie, drżenie powoli cichnie. */
  const pelnyEkran = () => matchMedia('(display-mode: fullscreen)').matches || (Math.abs(innerHeight - screen.height) < 3 && Math.abs(innerWidth - screen.width) < 3);
  const SZEPTY = ['nie zamykaj mnie…', 'jeszcze nie…', 'zostań…', 'nie odchodź…', 'tu jeszcze tyle wspomnień…'];
  let lek = 0, lekCel = 0, lekPetla = 0, szept = null;
  function lekaj() {
    lek += (lekCel - lek) * (lekCel > lek ? .035 : .02);
    if (lekCel === 0 && lek < .02) lek = 0;
    body.style.setProperty('--lek', lek.toFixed(3));
    body.classList.toggle('lek', lek > .02);
    if (szept) szept.style.opacity = Math.min(1, lek * 1.6).toFixed(2);
    if (lek > 0 || lekCel > 0) lekPetla = requestAnimationFrame(lekaj); else { lekPetla = 0; if (szept) { szept.remove(); szept = null; } }
  }
  /* Rozbłysk: białe światło na cały ekran, ostro w górę i powoli w dół. Przykrywa mignięcie przeglądarki
     przy przycisku wyjścia z pełnego ekranu (to samo mignięcie wraca przy pierwszym najechaniu na pergamin). */
  let dreszcz = false;
  function rozblysk(sila) {
    if (!RUCH) return;
    const b = document.createElement('div');
    b.className = 'rozblysk'; b.setAttribute('aria-hidden', 'true');
    body.appendChild(b);
    b.animate([{ opacity: 0 }, { opacity: sila, offset: .06 }, { opacity: sila * .85, offset: .2 }, { opacity: 0 }],
      { duration: 1500 * Math.max(.6, sila), easing: 'ease-out' }).finished.then(() => b.remove(), () => b.remove());
  }
  function boiSie(tak) {
    const cel = tak ? 1 : 0;
    if (cel === lekCel) return;
    lekCel = cel;
    if (tak) { rozblysk(1); dreszcz = true; }
    if (tak && !szept) {
      szept = document.createElement('div'); szept.id = 'szept'; szept.setAttribute('aria-hidden', 'true');
      szept.textContent = SZEPTY[Math.floor(Math.random() * SZEPTY.length)];
      body.appendChild(szept);
    }
    if (!lekPetla) lekPetla = requestAnimationFrame(lekaj);
  }
  addEventListener('mousemove', e => {
    if (!pelnyEkran()) { if (lekCel) boiSie(false); return; }
    if (e.clientY < 14) boiSie(true); else if (e.clientY > 70) boiSie(false);
  });
  document.addEventListener('mouseleave', e => { if (pelnyEkran() && e.clientY <= 2) boiSie(true); });
  document.addEventListener('mouseenter', e => { if (e.clientY > 70) boiSie(false); });

  /* lewitujące elementy */
  const items = [], znane = new Set();
  /* Rodzaje wbudowane. Pokój dokłada własne przez Mysl.rodzaj(nazwa, {v, bob, klasa, ikona, etykieta, otworz}). */
  const RODZAJE = { pokoj: { v: .7, bob: .3 }, przedmiot: { v: .5, bob: .25 }, ksiazka: { v: .3, bob: .25 } };
  /* Rozmiar grzbietu wynika z długości tytułu, żeby tytuł zawsze mieścił się w całości:
     do 22 znaków jedna kolumna, do 46 dwie, dłuższe trzy; wysokość z zapasem na łamanie wyrazów. */
  function wymiaryKsiazki(tytul, r) {
    const n = tytul.length, kol = n <= 22 ? 1 : n <= 46 ? 2 : 3;
    const wys = Math.max(170, Math.ceil(n / kol * 8.4 * 1.15) + 46) + Math.floor(r() * 16);
    return { w: 18 * kol + 22, h: wys };
  }
  function zbuduj(lista, nowy) {
    lista.forEach((it, i) => {
      const e = it.e, kind = RODZAJE[it.kind] ? it.kind : 'przedmiot', P = RODZAJE[kind];
      const r = rnd(hash(String(e.kid || e.id))), el = document.createElement('div');
      if (kind === 'ksiazka') {
        const dm = wymiaryKsiazki(e.tytul, r);
        el.className = 'k';
        el.style.setProperty('--w', dm.w + 'px');
        el.style.setProperty('--h', dm.h + 'px');
        el.style.setProperty('--hue', r() < .2 ? 150 + Math.floor(r() * 70) : Math.floor(r() * 50));
        el.innerHTML = '<span></span>';
        el.firstChild.textContent = e.tytul;
      } else if (kind === 'pokoj') {
        el.className = 'p';
        el.innerHTML = '<svg viewBox="0 0 100 100">' + (e.ikona || '') + '</svg><span>' + esc(e.nazwa) + '</span>';
      } else {
        el.className = P.klasa || 'p m';
        el.innerHTML = (P.ikona ? '<svg viewBox="0 0 100 100">' + P.ikona + '</svg>' : '') + '<span>' + esc(e.tytul) + '</span>';
      }
      if (kind !== 'ksiazka') ziarnoKartki(el, e.kid || e.id);
      if (nowy) el.classList.add('nowy');
      sky.appendChild(el);
      if (e.kid) znane.add(e.kid);
      const o = {
        el, e, kind, h: 1, hover: false,
        x: Math.random() * innerWidth, y: Math.random() * innerHeight,
        vx: (Math.random() - .5) * 2 * P.v, vy: (Math.random() - .5) * P.v,
        a: (Math.random() - .5) * (kind === 'ksiazka' ? 24 : 40), va: (Math.random() - .5) * .12,
        ph: i * 2, per: 1500 + Math.random() * 1500, bob: P.bob
      };
      el.addEventListener('pointerenter', () => {
        if (dreszcz && pelnyEkran()) { dreszcz = false; rozblysk(.7); }   // ostatni dreszcz po strachu przed zamknięciem
        o.hover = true; clearTimeout(o.echoT); o.echoT = setTimeout(() => { if (o.hover) echo(o); }, 1100); });
      el.addEventListener('pointerleave', () => { o.hover = false; clearTimeout(o.echoT); });
      el.addEventListener('click', () => otworz(o));
      items.push(o);
    });
  }
  let last = performance.now(), speed = 1;
  function tick(now) {
    const dt = Math.min(40, now - last) / 16; last = now;
    const W = innerWidth, H = innerHeight;
    for (const o of items) {
      if (o.acc) {                                   // przywołany (Accio): leci na środek i tam zostaje, póki ktoś z nim obcuje
        const A = o.acc, p = Math.min(1, (now - A.t0) / 1300), e = 1 - Math.pow(1 - p, 3);
        o.x = A.x0 + (A.tx - A.x0) * e; o.y = A.y0 + (A.ty - A.y0) * e - Math.sin(p * Math.PI) * 60; o.a = A.a0 * (1 - e);
        o.h = 0;
        if (p >= 1 && (o.hover || modal.classList.contains('on'))) A.t1 = now;
        if (p >= 1 && now - A.t1 > 12000) zwolnij(o);
        o.el.style.translate = o.x + 'px ' + o.y + 'px';
        o.el.style.rotate = o.a + 'deg';
        continue;
      }
      if (now - (o.sladT || 0) > 250) {                 // ślad drogi do echa wspomnienia: punkt co ćwierć sekundy, ok. 6 s wstecz
        o.sladT = now; (o.slad = o.slad || []).push([now, o.x, o.y, o.a]);
        if (o.slad.length > 26) o.slad.shift();
      }
      o.h += ((o.hover ? 0 : 1) - o.h) * 0.12 * dt;
      const s = speed * o.h * dt;
      o.x += o.vx * s; o.y += (o.vy + Math.sin(now / o.per + o.ph) * o.bob) * s; o.a += o.va * s;
      if (o.x > W + 160) o.x = -160; if (o.x < -170) o.x = W + 150;
      if (o.y > H + 260) o.y = -260; if (o.y < -270) o.y = H + 250;
      o.el.style.translate = o.x + 'px ' + o.y + 'px';
      o.el.style.rotate = o.a + 'deg';
    }
    requestAnimationFrame(tick);
  }

  /* Accio: różdżka w lewym dolnym rogu. Lista wszystkich kartek na tej stronie; wybrana leci na środek. */
  function zwolnij(o) {
    o.acc = null; o.h = 0; o.el.classList.remove('przywolany');
    o.vx = (Math.random() - .5) * 2 * (RODZAJE[o.kind] || RODZAJE.przedmiot).v;
  }
  function przywolaj(o) {
    for (const x of items) if (x.acc && x !== o) zwolnij(x);
    const t0 = performance.now();
    o.acc = {
      t0, t1: t0 + 1300, x0: o.x, y0: o.y, a0: ((o.a % 360) + 540) % 360 - 180,
      tx: innerWidth / 2 - o.el.offsetWidth / 2, ty: innerHeight / 2 - o.el.offsetHeight / 2
    };
    o.el.classList.add('przywolany');
  }
  function accio() {
    body.insertAdjacentHTML('beforeend',
      '<div id="accio"><div id="alista" hidden><input id="afil" type="search" placeholder="Przywołaj…" autocomplete="off" spellcheck="false" aria-label="Szukaj kartki"><div id="apoz" role="listbox"></div></div>' +
      '<button id="abtn" type="button" aria-expanded="false"><svg viewBox="0 0 32 32"><path d="M4 28L20 12"/><path d="M20 12l3-3" stroke-width="2.6"/>' +
      '<path d="M26 3v7M22.5 6.5h7M23.5 4l5 5M28.5 4l-5 5" stroke-width="1.1"/><circle cx="9" cy="10" r=".9"/><circle cx="27" cy="21" r=".9"/><circle cx="14" cy="4" r=".7"/></svg><span>Accio</span></button></div>');
    const kon = $('#accio'), btn = $('#abtn'), lista = $('#alista'), poz = $('#apoz'), fil = $('#afil');
    const nazwa = o => o.e.nazwa || o.e.tytul || '';
    const etykieta = o => ({ pokoj: 'pokój', ksiazka: 'księga' })[o.kind] || RODZAJE[o.kind].etykieta || '';
    function rysuj() {
      const q = fil.value.trim().toLocaleLowerCase('pl');
      const P = new Set(stan.przeczytane || []);
      const wybrane = items.filter(o => !q || nazwa(o).toLocaleLowerCase('pl').includes(q))
        .sort((a, b) => nazwa(a).localeCompare(nazwa(b), 'pl'));
      poz.innerHTML = '';
      for (const o of wybrane) {
        const b = document.createElement('button');
        b.type = 'button'; b.setAttribute('role', 'option');
        b.className = 'apoz' + (P.has(o.e.kid) ? ' przeczytane' : '');
        b.textContent = nazwa(o);
        const r = etykieta(o);
        if (r) { const m = document.createElement('small'); m.textContent = r; b.appendChild(m); }
        b.onclick = () => { zamknijListe(); przywolaj(o); btn.classList.add('rzuc'); setTimeout(() => btn.classList.remove('rzuc'), 700); };
        poz.appendChild(b);
      }
      if (!wybrane.length) poz.innerHTML = '<p>Nic takiego tu nie leży.</p>';
    }
    function otworzListe() {
      fil.value = ''; rysuj();
      fil.hidden = items.length < 9;
      lista.hidden = false; btn.setAttribute('aria-expanded', 'true');
      if (!fil.hidden && matchMedia('(hover:hover)').matches) fil.focus();
    }
    function zamknijListe() { lista.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
    btn.onclick = () => { if (lista.hidden) otworzListe(); else zamknijListe(); };
    fil.addEventListener('input', rysuj);
    kon.addEventListener('keydown', e => {
      if (e.key === 'Escape') { zamknijListe(); btn.focus(); return; }
      if (lista.hidden) return;
      const bs = [...poz.querySelectorAll('button')], i = bs.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') { e.preventDefault(); (bs[i + 1] || bs[0] || btn).focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); (i > 0 ? bs[i - 1] : fil.hidden ? bs[bs.length - 1] : fil).focus(); }
      else if (e.key === 'Enter' && document.activeElement === fil && bs[0]) { e.preventDefault(); bs[0].click(); }
    });
    document.addEventListener('pointerdown', e => { if (!lista.hidden && !kon.contains(e.target)) zamknijListe(); }, true);
  }

  /* Radio: ten sam utwór dla wszystkich. Przeglądarka pobiera "szuflę" (api/radio.php: utwory, losowa
     kolejność, epoka i czas serwera) i sama liczy, który utwór i od której sekundy gra teraz. Rozjazd
     rzędu sekundy jest dopuszczalny, większy jest korygowany w tle. Włączenie pamiętane w localStorage. */
  async function radio() {
    let R = null, przes = 0, start = [], aktualny = -1, chce = false, glosnosc = .6, ostWersja = '';
    const wczytaj = async () => {
      const t0 = Date.now();
      const r = await json(BASE + 'api/radio.php');
      if (!r.utwory || !r.utwory.length || !r.razem) return null;
      przes = r.czas - (t0 + Date.now()) / 2;
      let t = 0; start = r.kolejnosc.map(u => { const a = t; t += r.utwory[u].czas; return a; });
      return r;
    };
    try { R = await wczytaj(); } catch (e) { return; }
    if (!R) return;
    try {
      const z = JSON.parse(localStorage.getItem('mradio') || '{}');
      chce = !!z.on; glosnosc = typeof z.vol === 'number' ? Math.min(1, Math.max(0, z.vol)) : (R.ustawienia.glosnosc || .6);
    } catch (e) { glosnosc = R.ustawienia.glosnosc || .6; }
    ostWersja = R.wersja;
    const zapisz = () => { try { localStorage.setItem('mradio', JSON.stringify({ on: chce, vol: glosnosc })); } catch (e) { /* tryb prywatny */ } };

    /* mini interfejs w rogu, w stylu zegara i kalendarza: schemat radyjka z włącznikiem (lewa gałka),
       lampką, skalą ze wskazówką i gałką głośności (prawa). Działa tylko u tego gracza. */
    let tiki = '';
    for (let x = 0; x <= 12; x++) tiki += 'M' + (58 + x * 4.5) + ' 17.5v' + (x % 4 ? 3 : 5);
    body.insertAdjacentHTML('beforeend',
      '<div id="radio"><svg viewBox="0 0 120 66" aria-label="Radio">' +
      '<rect x="2" y="10" width="116" height="54" rx="5"/><path d="M20 10L46 2"/><circle cx="46.5" cy="1.8" r="1.2"/>' +
      '<circle cx="30" cy="38" r="17"/><path d="M20 31h20M18 36h24M18 41h24M20 46h20"/>' +
      '<rect x="55" y="15" width="60" height="14" rx="2"/><path d="' + tiki + '"/>' +
      '<line id="rig" x1="0" y1="15.5" x2="0" y2="28.5" transform="translate(58 0)"/>' +
      '<g id="rwl" role="switch" tabindex="0" aria-label="Włącznik radia" aria-checked="false"><circle class="tlo" cx="72" cy="48" r="13"/>' +
      '<circle cx="72" cy="48" r="8.5"/><line id="rwlm" x1="72" y1="48" x2="72" y2="41.5"/></g>' +
      '<circle id="rlamp" cx="86" cy="39" r="2.1"/>' +
      '<g id="rgl" role="slider" tabindex="0" aria-label="Głośność radia" aria-valuemin="0" aria-valuemax="100"><circle class="tlo" cx="102" cy="48" r="13"/>' +
      '<circle cx="102" cy="48" r="8.5"/><line id="rglm" x1="102" y1="48" x2="102" y2="41.5"/>' +
      '<path class="skala" d="M93.3 56.7l-2.4 2.4M110.7 56.7l2.4 2.4"/></g>' +
      '</svg><div id="rtyt"></div></div>');
    const el = $('#radio'), wl = $('#rwl'), gl = $('#rgl'), tyt = $('#rtyt');
    if (!R.ustawienia.tytul) tyt.hidden = true;
    /* Napis z tytułem: gdy jest dłuższy niż okienko radia, przesuwa się powoli w lewo. Wjeżdża zza prawej krawędzi,
       przejeżdża całe okienko i znika za lewą; kiedy schowa się ostatnia litera, linia jest pusta i napis wjeżdża od nowa.
       Napis zmienia się tylko przy zmianie utworu, więc pasek nie zaczyna od nowa przy każdej synchronizacji. */
    const stacja = R.ustawienia.stacja || '';
    let tytTekst = null;
    function napis(t) {
      if (t === tytTekst) return;
      tytTekst = t;
      tyt.classList.remove('jedzie'); tyt.textContent = '';
      if (!t) return;
      const pas = document.createElement('span');
      pas.className = 'pas'; pas.textContent = t; tyt.appendChild(pas);
      const W = tyt.clientWidth, d = pas.offsetWidth;
      if (!W || d <= W) return;
      pas.style.setProperty('--od', W + 'px');
      pas.style.setProperty('--do', -d + 'px');
      pas.style.setProperty('--t', ((W + d) / 22).toFixed(1) + 's');   // około 22 pikseli na sekundę
      tyt.classList.add('jedzie');
    }

    const audio = new Audio();
    audio.preload = 'auto';
    /* iOS ignoruje audio.volume, więc tam głośność idzie przez wzmacniacz Web Audio (tworzony przy pierwszym geście) */
    const iOS = /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    let ctx = null, wzm = null;
    function wzmacniacz() {
      if (ctx) { if (ctx.state === 'suspended') ctx.resume().catch(() => { }); return; }
      if (!iOS) return;
      try {
        ctx = new (window.AudioContext || window.webkitAudioContext)();
        const zr = ctx.createMediaElementSource(audio);
        wzm = ctx.createGain(); wzm.gain.value = glosnosc;
        zr.connect(wzm); wzm.connect(ctx.destination);
        if (ctx.state === 'suspended') ctx.resume().catch(() => { });
      } catch (e) { ctx = null; wzm = null; }
    }
    function ustawGlosnosc(v, zapis) {
      glosnosc = Math.min(1, Math.max(0, v));
      if (wzm) wzm.gain.value = glosnosc; else audio.volume = glosnosc;
      $('#rglm').style.transform = 'rotate(' + (-135 + glosnosc * 270) + 'deg)';
      gl.setAttribute('aria-valuenow', Math.round(glosnosc * 100));
      gl.setAttribute('aria-valuetext', Math.round(glosnosc * 100) + '%');
      if (zapis) zapisz();
    }
    ustawGlosnosc(glosnosc, false);

    /* gdzie w szufli jesteśmy teraz (czas serwera) */
    function gdzie() {
      const ms = Date.now() + przes, wyd = R.wydarzenia || [];
      let stoi = 0;                                   // przez tyle ms szufla czekała na utwory na godzinę
      for (let i = 0; i < wyd.length && ms >= wyd[i].start; i++) {
        const z = wyd[i], kon = z.start + z.czas * 1000;
        if (ms < kon) return { nr: -2 - i, u: z, wyd: true, sek: (ms - z.start) / 1000, zostalo: (kon - ms) / 1000 };
        if (z.start >= R.epoka) stoi += z.czas * 1000;
      }
      let poz = ((ms - R.epoka - stoi) / 1000) % R.razem;
      if (poz < 0) poz += R.razem;
      let a = 0, b = start.length - 1;
      while (a < b) { const m = (a + b + 1) >> 1; if (start[m] <= poz) a = m; else b = m - 1; }
      const u = R.utwory[R.kolejnosc[a]];
      return { nr: a, u, sek: poz - start[a], zostalo: u.czas - (poz - start[a]) };
    }
    function pokaz(gra) {
      el.classList.toggle('on', chce);
      el.classList.toggle('gra', !!gra);
      el.classList.toggle('czeka', chce && !gra);     // włączone, ale przeglądarka czeka na kliknięcie
      wl.setAttribute('aria-checked', chce ? 'true' : 'false');
      wl.setAttribute('title', chce ? 'Wyłącz radio' : 'Włącz radio');
      $('#rwlm').style.transform = 'rotate(' + (chce ? 50 : -50) + 'deg)';
      const g = chce && aktualny !== -1 ? gdzie() : null;
      // wskazówka na skali stroi się na miejsce utworu w szufli, a po wyłączeniu wraca na początek
      const poz = g ? (g.wyd ? .5 : (R.kolejnosc[g.nr] + .5) / R.utwory.length) : 0;
      $('#rig').style.transform = 'translate(' + (58 + poz * 54) + 'px,0)';
      napis(g && gra ? '♪ ' + (stacja ? stacja + ', teraz gramy: ' : '') + g.u.tytul : '');
    }
    let czekaNaGest = false, odlicza = 0, naGodzine = 0;
    /* utwór na godzinę ma ruszyć punktualnie, a nie przy najbliższej korekcie co 4 s */
    function czekajNaUtwor() {
      clearTimeout(naGodzine);
      const ms = Date.now() + przes, z = (R.wydarzenia || []).find(x => x.start > ms);
      if (z && z.start - ms < 60000) naGodzine = setTimeout(() => sync(false), z.start - ms + 30);
    }
    function sprobujGrac() {
      const p = audio.play();
      if (!p || !p.catch) return;
      p.then(() => { czekaNaGest = false; }).catch(err => {
        if (err && err.name === 'AbortError') return;
        if (!czekaNaGest) {                         // przeglądarka blokuje autoodtwarzanie: ruszy przy pierwszym geście
          czekaNaGest = true;
          const ruszaj = () => { document.removeEventListener('pointerdown', ruszaj, true); document.removeEventListener('keydown', ruszaj, true); czekaNaGest = false; if (chce) { wzmacniacz(); sync(true); } };
          document.addEventListener('pointerdown', ruszaj, true); document.addEventListener('keydown', ruszaj, true);
        }
        pokaz(false);
      });
    }
    /* ustaw właściwy utwór i miejsce; twardo = nawet jeśli utwór się zgadza */
    function sync(twardo) {
      clearTimeout(odlicza);
      if (!chce) return;
      czekajNaUtwor();
      const g = gdzie();
      if (g.zostalo < .35) { odlicza = setTimeout(() => sync(true), g.zostalo * 1000 + 60); return; }  // zaraz koniec: poczekaj na następny
      if (g.nr !== aktualny || twardo && !audio.src) {
        aktualny = g.nr;
        audio.src = BASE + (R.sciezka || 'radio/') + encodeURIComponent(g.u.plik);
        audio.addEventListener('loadedmetadata', () => { try { audio.currentTime = Math.min(gdzie().sek, (audio.duration || 1e9) - .2); } catch (e) { } }, { once: true });
        audio.load();
      } else if (Math.abs(audio.currentTime - g.sek) > 1.5 && audio.readyState > 0) {
        try { audio.currentTime = g.sek; } catch (e) { }
      }
      if (audio.paused) sprobujGrac();
      pokaz(!audio.paused);
    }
    function wylacz() {
      clearTimeout(odlicza); clearTimeout(naGodzine);
      audio.pause(); audio.removeAttribute('src'); audio.load();
      aktualny = -1; pokaz(false);
    }

    audio.addEventListener('playing', () => pokaz(true));
    audio.addEventListener('pause', () => pokaz(false));
    audio.addEventListener('ended', () => {
      const g = gdzie();
      if (g.nr === aktualny) odlicza = setTimeout(() => { aktualny = -1; sync(true); }, Math.max(50, g.zostalo * 1000 + 60));
      else sync(true);
    });
    audio.addEventListener('error', () => {         // brak pliku albo błąd odczytu: spróbuj ponownie przy następnym utworze
      if (!chce || !audio.src) return;
      clearTimeout(odlicza);
      odlicza = setTimeout(() => { aktualny = -1; sync(true); }, 6000);
    });

    function przelacz() {
      chce = !chce; zapisz();
      if (chce) { wzmacniacz(); aktualny = -1; sync(true); } else wylacz();
    }
    wl.addEventListener('click', przelacz);
    wl.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); przelacz(); } });
    /* gałka głośności: przeciągnięcie w górę lub w prawo głośniej, w dół lub w lewo ciszej; kółko myszy i strzałki też */
    let ch = null;
    gl.addEventListener('pointerdown', e => { ch = { x: e.clientX, y: e.clientY, v: glosnosc, id: e.pointerId }; gl.setPointerCapture(e.pointerId); wzmacniacz(); e.preventDefault(); });
    gl.addEventListener('pointermove', e => {
      if (!ch || e.pointerId !== ch.id) return;
      ustawGlosnosc(ch.v + ((ch.y - e.clientY) + (e.clientX - ch.x)) / 110, false);
    });
    const puscGalke = e => { if (ch && e.pointerId === ch.id) { ch = null; zapisz(); } };
    gl.addEventListener('pointerup', puscGalke); gl.addEventListener('pointercancel', puscGalke);
    gl.addEventListener('wheel', e => { e.preventDefault(); ustawGlosnosc(glosnosc + (e.deltaY < 0 ? .05 : -.05), true); }, { passive: false });
    gl.addEventListener('keydown', e => {
      const k = { ArrowUp: .05, ArrowRight: .05, ArrowDown: -.05, ArrowLeft: -.05, PageUp: .2, PageDown: -.2 }[e.key];
      if (k) { e.preventDefault(); ustawGlosnosc(glosnosc + k, true); }
      else if (e.key === 'Home' || e.key === 'End') { e.preventDefault(); ustawGlosnosc(e.key === 'Home' ? 0 : 1, true); }
    });

    /* korekta rozjazdu i odświeżenie szufli (np. po dodaniu MP3) */
    setInterval(() => { if (chce) sync(false); }, 4000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden && chce) sync(false); });
    setInterval(async () => {
      try {
        const n = await wczytaj();
        if (!n) return;
        const zmiana = n.wersja !== ostWersja;
        R = n; ostWersja = n.wersja;
        if (zmiana && chce) { aktualny = -1; sync(true); }
      } catch (e) { /* zostaje stara szufla */ }
    }, 5 * 60 * 1000);

    pokaz(false);
    if (chce) sync(true);
  }

  /* Własne pliki pokoju (pokoj.json: "styl", "skrypt"). Skrypt ładuje się przed zbudowaniem elementów. */
  async function zasobyPokoju(p) {
    const v = p.wersja ? '?v=' + p.wersja : '';   // po każdym wgraniu inny adres, więc przeglądarka nie użyje starej kopii
    if (p.styl) {
      const l = document.createElement('link');
      l.rel = 'stylesheet'; l.href = URL_POKOJU + p.styl + v;
      document.head.appendChild(l);
    }
    if (p.skrypt) await new Promise(ok => {
      const s = document.createElement('script');
      s.src = URL_POKOJU + p.skrypt + v; s.onload = ok;
      s.onerror = () => { console.error('Nie udało się wczytać skryptu pokoju:', p.skrypt); ok(); };
      document.head.appendChild(s);
    });
  }

  /* To, czego skrypt pokoju potrzebuje od rdzenia */
  window.Mysl = {
    BASE, POKOJ, URL_POKOJU, gracz, $, esc, md, w, modal, elementy: items,
    arkusz, zamknij, tresc, odswiez, przenoszenie,
    poziom: () => sheet.classList.contains('poziom'),
    rodzaj(nazwa, opis) { RODZAJE[nazwa] = Object.assign({ v: .3, bob: .25 }, opis); }
  };

  async function start() {
    const postac = await json(BASE + 'postac.json').catch(() => ({}));
    rok = postac.rok || 1914;
    strefa = postac.strefa || strefa;
    if (!POKOJ && postac.nazwa) document.title = postac.nazwa;
    zegar(); setInterval(zegar, 10000);
    radio();
    accio();
    if (POKOJ) {
      body.insertAdjacentHTML('beforeend', '<a id="wroc" href="' + BASE + '">wróć do mgły</a>');
      $('#wroc').onclick = ev => { if (ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button) return; ev.preventDefault(); przejdz(BASE, ''); };
    }
    let d;
    try { d = await dane(POKOJ); }
    catch (err) {
      console.error('Brak danych. Strona wymaga serwera z PHP (np. php -S localhost:8000).', err);
      body.insertAdjacentHTML('beforeend', '<div id="blad">Mgła nie ustępuje.</div>');
      rozprosz();
      return;
    }
    let lista;
    if (!POKOJ) lista = (d.pokoje || []).map(e => ({ e, kind: 'pokoj' }));
    else {
      if (d.pokoj && d.pokoj.nazwa) document.title = d.pokoj.nazwa;
      if (d.pokoj) await zasobyPokoju(d.pokoj);
      lista = (d.elementy || []).map(e => ({ e, kind: e.rodzaj || 'przedmiot' }));
    }
    zbuduj(lista);
    requestAnimationFrame(tick);
    setTimeout(rozprosz, 350);                          // pokój już stoi: mgła może rzednąć
    await odswiez();
    setInterval(odswiez, 5000);
  }
  start();
})();

/* Mechanika buduaru: sekretarzyk do listów. Plik należy do pokoju (pokoj.json: "skrypt"). */
(function () {
  'use strict';
  const { $, esc, md, w, modal, gracz, arkusz, zamknij, tresc, URL_POKOJU: U } = window.Mysl;
  let okno = null, czasTostu = 0;
  function kom(t) {                               // komunikat zawsze widoczny, niezależnie od strony kartki
    let e = $('#tost');
    if (!e) { e = document.createElement('div'); e.id = 'tost'; e.setAttribute('role', 'status'); $('#sheet').appendChild(e); }
    e.textContent = t || '';
    e.classList.toggle('on', !!t);
    clearTimeout(czasTostu);
    czasTostu = setTimeout(() => e.classList.remove('on'), 6000);
  }
  async function api(akcja, d) {
    const r = await fetch(U + 'api/poczta.php', {
      method: akcja ? 'POST' : 'GET', cache: 'no-store', headers: { 'Content-Type': 'application/json' },
      body: akcja ? JSON.stringify(Object.assign({ akcja, gracz }, d || {})) : undefined
    });
    return r.json();
  }
  const data = ts => new Date(ts * 1000).toLocaleString('pl', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

  function otworzPoczte(o) {
    okno = o; arkusz('ks'); kom('');
    w.innerHTML = '<h2>' + esc(o.e.tytul) + '</h2><div class="tekst"></div><div id="list"></div><button id="x">Zamknij</button>';
    $('#x').onclick = zamknij; modal.classList.add('on');
    tresc(o.e, w.querySelector('.tekst'));
    let adr = null, lok = 0, brudny = false, ost = '';
    const rysuj = async force => {
      let s;
      try { s = await api(); } catch (err) { kom('Atrament zasechł, spróbuj za chwilę.'); return; }
      if (!s || !s.adresaci || okno !== o) return;
      if (!s.adresaci.some(a => a.id === adr)) adr = s.adresaci[0] && s.adresaci[0].id;
      const a = s.adresaci.find(x => x.id === adr);
      if (!a) return;
      const sig = JSON.stringify(s);
      if (!force && sig === ost) return;
      ost = sig;
      if (!force && brudny && $('#tresc')) {           // nie przerywać pisania: tylko informacja o nowszej wersji
        if (a.szkic.wersja !== lok) $('#nowy').innerHTML = '<i>Ktoś zapisał nowszą wersję.</i> <button id="wcz" type="button">Wczytaj</button>';
        if ($('#wcz')) $('#wcz').onclick = () => { brudny = false; rysuj(true); };
        return;
      }
      let h = s.adresaci.length > 1
        ? '<div><select id="adr">' + s.adresaci.map(x => '<option value="' + esc(x.id) + '"' + (x.id === adr ? ' selected' : '') + '>' + esc(x.nazwa) + '</option>').join('') + '</select></div>'
        : '<p>Adresat: <b>' + esc(a.nazwa) + '</b></p>';
      h += '<p>Co ' + esc(s.nadawca) + ' ma napisać? Szkic jest wspólny dla wszystkich.</p>' +
        '<textarea id="tresc" rows="9" maxlength="' + s.max + '" aria-label="Treść listu"></textarea>' +
        '<div class="pasek"><span id="licz"></span><button id="szk" type="button">Zapisz szkic</button></div><div id="nowy"></div>';
      if (!a.pieczec) h += '<p class="grupa">Pieczęć</p><div class="pasek"><input id="syg" type="text" maxlength="40" autocomplete="off" spellcheck="false" placeholder="sygnatura" aria-label="Sygnatura pieczęci"><button id="piec" type="button">Złam pieczęć</button></div>';
      else if (!a.droga) h += '<p><i>Pieczęć jest złamana, ale sowa nie zna jeszcze drogi do tego adresata.</i></p>';
      else h += '<div class="pasek"><span><i>Pieczęć jest złamana.</i></span><button id="wys" type="button">Wyślij list</button></div>';
      if (a.wyslane.length) h += '<p class="grupa">Wysłane</p>' + a.wyslane.map(l => '<div class="wyslany"><small>' + data(l.ts) + '</small>' + md(l.tresc) + '</div>').join('');
      $('#list').innerHTML = h;
      const pole = $('#tresc');
      const stare = force && brudny && poleWartosc !== null ? poleWartosc : null;
      if (stare !== null) { pole.value = stare; if (a.szkic.wersja !== lok) $('#nowy').innerHTML = '<i>Ktoś zapisał nowszą wersję.</i> <button id="wcz" type="button">Wczytaj</button>'; }
      else { pole.value = a.szkic.tresc; lok = a.szkic.wersja; brudny = false; }
      const licznik = () => { $('#licz').textContent = pole.value.trim().length + ' / ' + s.max; };
      pole.addEventListener('input', () => { brudny = true; licznik(); });
      licznik();
      const akcja = async (ak, d) => {
        poleWartosc = pole.value;
        let r;
        try { r = await api(ak, Object.assign({ adresat: adr }, d)); } catch (err) { kom('Atrament zasechł, spróbuj za chwilę.'); return; }
        kom(r.tekst || r.blad || '');
        if (ak === 'szkic' && r.ok) brudny = false;
        if (ak === 'wyslij' && r.ok) brudny = false;
        rysuj(true);
      };
      if ($('#adr')) $('#adr').onchange = () => { adr = $('#adr').value; brudny = false; lok = 0; rysuj(true); };
      $('#szk').onclick = () => akcja('szkic', { tresc: pole.value, wersja: lok });
      if ($('#wcz')) $('#wcz').onclick = () => { brudny = false; rysuj(true); };
      if ($('#piec')) {
        const piec = () => akcja('pieczec', { sygnatura: $('#syg').value });
        $('#piec').onclick = piec;
        $('#syg').addEventListener('keydown', ev => { if (ev.key === 'Enter') piec(); });
      }
      if ($('#wys')) $('#wys').onclick = async () => {
        if (brudny) {                                  // niezapisany tekst zapisuje się sam, zanim sowa wyleci
          let r;
          try { r = await api('szkic', { adresat: adr, tresc: pole.value, wersja: lok }); } catch (err) { kom('Atrament zasechł, spróbuj za chwilę.'); return; }
          if (!r.ok) { kom(r.tekst || r.blad || ''); poleWartosc = pole.value; rysuj(true); return; }
          brudny = false;
        }
        akcja('wyslij', {});
      };
    };
    let poleWartosc = null;
    rysuj(true);
    const id = setInterval(() => {
      if (!modal.classList.contains('on') || okno !== o) return clearInterval(id);
      rysuj(false);
    }, 5000);
  }

  window.Mysl.rodzaj('poczta', {
    v: .25, bob: .2, klasa: 'p kot', etykieta: 'sekretarzyk', otworz: otworzPoczte,
    ikona: '<path d="M12 28h76v46H12z"/><path d="M12 28l38 28 38-28"/><circle cx="50" cy="62" r="5"/>'
  });
})();

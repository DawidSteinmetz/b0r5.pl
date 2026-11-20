// Karta postaci w systemie Star Wars: Edge of the Empire (EotE): dane (gatunki, kariery, specjalizacje,
// umiejętności, talenty, szablony przeciwników), reguły tworzenia (koszty PD, pochodne) i widok CV.
// Używają tego: panel MG (generator postaci i NPC) i aplikacja Karta postaci (CV gracza).
// Opisy są własne i krótkie — liczby to mechanika gry, nie przepisany tekst podręcznika.

export const CECHY = [
  ['krzepa', 'Krzepa', 'Kr'], ['zwinnosc', 'Zwinność', 'Zw'], ['intelekt', 'Intelekt', 'Int'],
  ['spryt', 'Spryt', 'Sp'], ['wola', 'Siła Woli', 'SW'], ['prezencja', 'Prezencja', 'Pr'],
];
const NAZWA_CECHY = Object.fromEntries(CECHY.map(([k, n]) => [k, n]));
const SKROT_CECHY = Object.fromEntries(CECHY.map(([k, , s]) => [k, s]));

// [klucz, nazwa, cecha, grupa]
export const UMIEJETNOSCI = [
  ['astronawigacja', 'Astronawigacja', 'intelekt', 'ogolne'], ['atletyka', 'Atletyka', 'krzepa', 'ogolne'],
  ['urok', 'Urok', 'prezencja', 'ogolne'], ['zastraszanie', 'Zastraszanie', 'wola', 'ogolne'],
  ['komputery', 'Komputery', 'intelekt', 'ogolne'], ['opanowanie', 'Opanowanie', 'prezencja', 'ogolne'],
  ['koordynacja', 'Koordynacja', 'zwinnosc', 'ogolne'], ['oszustwo', 'Oszustwo', 'spryt', 'ogolne'],
  ['dyscyplina', 'Dyscyplina', 'wola', 'ogolne'], ['przywodztwo', 'Przywództwo', 'prezencja', 'ogolne'],
  ['mechanika', 'Mechanika', 'intelekt', 'ogolne'], ['medycyna', 'Medycyna', 'intelekt', 'ogolne'],
  ['negocjacje', 'Negocjacje', 'prezencja', 'ogolne'], ['percepcja', 'Percepcja', 'spryt', 'ogolne'],
  ['pilotaz_plan', 'Pilotaż (planetarny)', 'zwinnosc', 'ogolne'], ['pilotaz_kosm', 'Pilotaż (kosmiczny)', 'zwinnosc', 'ogolne'],
  ['odpornosc', 'Odporność', 'krzepa', 'ogolne'], ['kretactwo', 'Krętactwo', 'spryt', 'ogolne'],
  ['skradanie', 'Skradanie', 'zwinnosc', 'ogolne'], ['ulica', 'Znajomość ulicy', 'spryt', 'ogolne'],
  ['przetrwanie', 'Przetrwanie', 'spryt', 'ogolne'], ['czujnosc', 'Czujność', 'wola', 'ogolne'],
  ['bijatyka', 'Bijatyka', 'krzepa', 'walka'], ['artyleria', 'Artyleria', 'zwinnosc', 'walka'],
  ['walka_wrecz', 'Walka wręcz', 'krzepa', 'walka'], ['bron_lekka', 'Broń dystansowa (lekka)', 'zwinnosc', 'walka'],
  ['bron_ciezka', 'Broń dystansowa (ciężka)', 'zwinnosc', 'walka'],
  ['w_jadro', 'Wiedza: Światy Jądra', 'intelekt', 'wiedza'], ['w_edukacja', 'Wiedza: Edukacja', 'intelekt', 'wiedza'],
  ['w_legendy', 'Wiedza: Legendy', 'intelekt', 'wiedza'], ['w_rubieze', 'Wiedza: Zewnętrzne Rubieże', 'intelekt', 'wiedza'],
  ['w_polswiatek', 'Wiedza: Półświatek', 'intelekt', 'wiedza'], ['w_ksenologia', 'Wiedza: Ksenologia', 'intelekt', 'wiedza'],
];
export const UMIEJ = Object.fromEntries(UMIEJETNOSCI.map(([k, n, c, g]) => [k, { nazwa: n, cecha: c, grupa: g }]));
export const GRUPY_UMIEJ = { ogolne: 'Ogólne', walka: 'Walka', wiedza: 'Wiedza' };

// Gatunki: cechy startowe, progi (bazowe), PD na start, premia startowa i cechy gatunku (własnymi słowami).
export const GATUNKI = {
  czlowiek: { nazwa: 'Człowiek', cechy: [2, 2, 2, 2, 2, 2], rany: 10, wysilek: 10, pd: 110, dowolne: 2,
    opis: 'Wszechstronni: po 1 randze w dwóch dowolnych umiejętnościach spoza kariery.' },
  bothanin: { nazwa: 'Bothanin', cechy: [1, 2, 2, 3, 2, 2], rany: 10, wysilek: 11, pd: 100, umiejetnosc: 'ulica',
    talent: 'Przekonujący', opis: 'Urodzeni szpiedzy i plotkarze: ranga w Znajomości ulicy, talent Przekonujący.' },
  droid: { nazwa: 'Droid', cechy: [1, 1, 1, 1, 1, 1], rany: 10, wysilek: 10, pd: 175, droid: true,
    opis: 'Maszyna: 6 darmowych rang w umiejętnościach kariery i 3 w specjalizacji; nie oddycha, nie je, nie ma Mocy.' },
  gand: { nazwa: 'Gand', cechy: [2, 2, 2, 2, 3, 1], rany: 10, wysilek: 10, pd: 100, umiejetnosc: 'dyscyplina',
    opis: 'Odkrywcy i łowcy z mglistego świata: ranga w Dyscyplinie; część gatunku nie potrzebuje oddychać.' },
  rodianin: { nazwa: 'Rodianin', cechy: [2, 3, 2, 2, 1, 2], rany: 10, wysilek: 10, pd: 100, umiejetnosc: 'przetrwanie',
    talent: 'Tropiciel', opis: 'Kultura łowów: ranga w Przetrwaniu, talent Tropiciel.' },
  trandoshanin: { nazwa: 'Trandoshanin', cechy: [3, 1, 2, 2, 2, 2], rany: 12, wysilek: 9, pd: 90, umiejetnosc: 'percepcja',
    opis: 'Gadzi łowcy: ranga w Percepcji, regeneracja ran, pazury w Bijatyce.' },
  twilek: { nazwa: "Twi'lek", cechy: [1, 2, 2, 2, 2, 3], rany: 10, wysilek: 11, pd: 100, wybor: ['urok', 'oszustwo'],
    opis: 'Charyzmatyczni mieszkańcy Ryloth: ranga w Uroku albo Oszustwie, odporni na suche, gorące światy.' },
  wookiee: { nazwa: 'Wookiee', cechy: [3, 2, 2, 2, 1, 2], rany: 14, wysilek: 8, pd: 90, umiejetnosc: 'bijatyka',
    opis: 'Potężni wojownicy z Kashyyyk: ranga w Bijatyce, wściekłość dodaje obrażeń, gdy są ranni.' },
};

export const KARIERY = {
  lowca: { nazwa: 'Łowca nagród', umiejetnosci: ['atletyka', 'bijatyka', 'percepcja', 'pilotaz_plan', 'pilotaz_kosm', 'bron_ciezka', 'ulica', 'czujnosc'],
    specjalizacje: ['zabojca', 'gadzeciarz', 'surwiwalista'] },
  kolonista: { nazwa: 'Kolonista', umiejetnosci: ['urok', 'oszustwo', 'w_jadro', 'w_edukacja', 'w_legendy', 'przywodztwo', 'negocjacje', 'ulica'],
    specjalizacje: ['lekarz', 'polityk', 'uczony'] },
  odkrywca: { nazwa: 'Odkrywca', umiejetnosci: ['astronawigacja', 'opanowanie', 'w_legendy', 'w_rubieze', 'w_ksenologia', 'percepcja', 'pilotaz_kosm', 'przetrwanie'],
    specjalizacje: ['obiezyswiat', 'zwiadowca', 'handlarz'] },
  najemnik: { nazwa: 'Najemnik', umiejetnosci: ['atletyka', 'bijatyka', 'dyscyplina', 'walka_wrecz', 'pilotaz_plan', 'bron_lekka', 'odpornosc', 'czujnosc'],
    specjalizacje: ['ochroniarz', 'grabiezca', 'zolnierz'] },
  przemytnik: { nazwa: 'Przemytnik', umiejetnosci: ['koordynacja', 'oszustwo', 'w_polswiatek', 'percepcja', 'pilotaz_kosm', 'kretactwo', 'ulica', 'czujnosc'],
    specjalizacje: ['pilot', 'lotr', 'zlodziej'] },
  technik: { nazwa: 'Technik', umiejetnosci: ['astronawigacja', 'komputery', 'koordynacja', 'dyscyplina', 'w_rubieze', 'mechanika', 'percepcja', 'pilotaz_plan'],
    specjalizacje: ['mechanik', 'technik_wyjety', 'haker'] },
  // Kariera kampanii: służba we flocie. Postać zaczyna jako Kadet, a kariera sama awansuje wraz ze zdobytymi PD
  // (albo MG ustawia poziom ręcznie): wyświetla się nazwa bieżącego poziomu. Każdy poziom dokłada umiejętności
  // kariery i specjalizacje do tych z niższych poziomów.
  kadet: { nazwa: 'Kadet', umiejetnosci: ['przywodztwo', 'dyscyplina', 'opanowanie', 'negocjacje', 'w_jadro', 'w_edukacja', 'astronawigacja', 'czujnosc'],
    specjalizacje: ['mowca'],
    poziomy: [
      { nazwa: 'Kadet', pd: 0 },
      { nazwa: 'Chorąży', pd: 100, umiejetnosci: ['komputery'] },
      { nazwa: 'Porucznik', pd: 250, umiejetnosci: ['artyleria'], specjalizacje: ['oficer_pokladowy'] },
      { nazwa: 'Komandor porucznik', pd: 450, umiejetnosci: ['percepcja'] },
      { nazwa: 'Komandor', pd: 700, umiejetnosci: ['zastraszanie'] },
      { nazwa: 'Kapitan', pd: 1000, umiejetnosci: ['w_rubieze'], specjalizacje: ['dowodca'] },
    ] },
};

export const SPECJALIZACJE = {
  zabojca: { nazwa: 'Zabójca', kariera: 'lowca', umiejetnosci: ['walka_wrecz', 'bron_ciezka', 'kretactwo', 'skradanie'] },
  gadzeciarz: { nazwa: 'Gadżeciarz', kariera: 'lowca', umiejetnosci: ['bijatyka', 'zastraszanie', 'mechanika', 'bron_lekka'] },
  surwiwalista: { nazwa: 'Surwiwalista', kariera: 'lowca', umiejetnosci: ['w_ksenologia', 'percepcja', 'odpornosc', 'przetrwanie'] },
  lekarz: { nazwa: 'Lekarz', kariera: 'kolonista', umiejetnosci: ['opanowanie', 'w_edukacja', 'medycyna', 'odpornosc'] },
  polityk: { nazwa: 'Polityk', kariera: 'kolonista', umiejetnosci: ['urok', 'zastraszanie', 'oszustwo', 'w_jadro'] },
  uczony: { nazwa: 'Uczony', kariera: 'kolonista', umiejetnosci: ['w_rubieze', 'w_polswiatek', 'w_ksenologia', 'percepcja'] },
  obiezyswiat: { nazwa: 'Obieżyświat', kariera: 'odkrywca', umiejetnosci: ['astronawigacja', 'koordynacja', 'negocjacje', 'ulica'] },
  zwiadowca: { nazwa: 'Zwiadowca', kariera: 'odkrywca', umiejetnosci: ['atletyka', 'medycyna', 'pilotaz_plan', 'przetrwanie'] },
  handlarz: { nazwa: 'Handlarz', kariera: 'odkrywca', umiejetnosci: ['oszustwo', 'w_jadro', 'w_polswiatek', 'negocjacje'] },
  ochroniarz: { nazwa: 'Ochroniarz', kariera: 'najemnik', umiejetnosci: ['artyleria', 'percepcja', 'pilotaz_plan', 'bron_ciezka'] },
  grabiezca: { nazwa: 'Grabieżca', kariera: 'najemnik', umiejetnosci: ['zastraszanie', 'walka_wrecz', 'odpornosc', 'przetrwanie'] },
  zolnierz: { nazwa: 'Żołnierz najemny', kariera: 'najemnik', umiejetnosci: ['dyscyplina', 'artyleria', 'przywodztwo', 'bron_ciezka'] },
  pilot: { nazwa: 'Pilot', kariera: 'przemytnik', umiejetnosci: ['astronawigacja', 'artyleria', 'pilotaz_plan', 'pilotaz_kosm'] },
  lotr: { nazwa: 'Łotr', kariera: 'przemytnik', umiejetnosci: ['urok', 'opanowanie', 'oszustwo', 'bron_lekka'] },
  zlodziej: { nazwa: 'Złodziej', kariera: 'przemytnik', umiejetnosci: ['komputery', 'kretactwo', 'skradanie', 'czujnosc'] },
  mechanik: { nazwa: 'Mechanik', kariera: 'technik', umiejetnosci: ['bijatyka', 'mechanika', 'pilotaz_kosm', 'kretactwo'] },
  technik_wyjety: { nazwa: 'Technik spod ciemnej gwiazdy', kariera: 'technik', umiejetnosci: ['w_edukacja', 'w_polswiatek', 'mechanika', 'ulica'] },
  haker: { nazwa: 'Haker', kariera: 'technik', umiejetnosci: ['komputery', 'w_edukacja', 'w_polswiatek', 'skradanie'] },
  mowca: { nazwa: 'Mówca', kariera: 'kadet', umiejetnosci: ['urok', 'zastraszanie', 'percepcja', 'komputery'] },
  oficer_pokladowy: { nazwa: 'Oficer pokładowy', kariera: 'kadet', umiejetnosci: ['artyleria', 'komputery', 'pilotaz_kosm', 'mechanika'] },
  dowodca: { nazwa: 'Dowódca okrętu', kariera: 'kadet', umiejetnosci: ['przywodztwo', 'zastraszanie', 'opanowanie', 'w_rubieze'] },
};

// Talenty: [nazwa, poziom (koszt 5 × poziom), rangi (true = można brać wielokrotnie), skrót działania (własny)].
export const TALENTY = [
  ['Zahartowany', 1, true, '+2 do progu ran za rangę.'],
  ['Hart', 1, true, '+1 do progu wysiłku za rangę.'],
  ['Błyskawiczna reakcja', 1, true, 'Przy inicjatywie dodaje sukcesy (Opanowanie/Czujność) równe randze.'],
  ['Szybkie dobycie', 1, false, 'Dobycie albo schowanie broni bez zużycia manewru.'],
  ['Przekonujący', 1, true, 'Utrudnia przeciwnikom próby przejrzenia twoich kłamstw.'],
  ['Tropiciel', 1, true, 'Łatwiej i szybciej odnajduje ślady.'],
  ['Kartograf galaktyki', 1, true, 'Łatwiejsza astronawigacja, krótsze obliczenia skoku.'],
  ['Człowiek z ulicy', 1, true, 'Łatwiej o kontakty i plotki w półświatku.'],
  ['Twardy w negocjacjach', 1, true, 'Lepsze ceny przy kupnie i sprzedaży.'],
  ['Solidne naprawy', 1, true, 'Naprawy przywracają więcej wytrzymałości kadłuba.'],
  ['Chirurg', 1, true, 'Leczenie przywraca dodatkowe rany.'],
  ['Mocne ciosy', 1, true, 'Silniejsze trafienia krytyczne.'],
  ['Drugi oddech', 1, true, 'Raz na starcie odzyskuje wysiłek równy randze.'],
  ['Zręczny pilot', 1, true, 'Łatwiejsze testy pilotażu.'],
  ['Łamacz szyfrów', 1, true, 'Łatwiejsze łamanie kodów i zabezpieczeń.'],
  ['Gadżeciarz z warsztatu', 2, true, 'Taniej i szybciej modyfikuje sprzęt.'],
  ['Unik', 2, true, 'Wysiłkiem utrudnia trafienie siebie.'],
  ['Z bliska', 2, true, 'Dodatkowe obrażenia na krótkim zasięgu.'],
  ['Kontakty czarnego rynku', 2, true, 'Łatwiej zdobywa rzadkie towary.'],
  ['Zawsze gotów', 2, false, 'Może działać, zanim przeciwnik się zorientuje.'],
  ['Postawa obronna', 2, true, 'Zwiększa obronę w walce wręcz.'],
  ['Nieugięty', 2, true, 'Słabsze trafienia krytyczne w siebie.'],
  ['Przewidujący', 3, true, 'Zmniejsza pulę przeciwnika przy celowaniu w niego.'],
  ['Precyzyjny strzał', 3, true, 'Celowanie w konkretny punkt kosztuje mniej.'],
  ['Wytrzymały', 4, true, '+1 do pancerza za rangę.'],
  ['Zabójcza celność', 4, false, 'Dodaje rangi umiejętności do obrażeń wybranej broni.'],
  ['Mistrz pancerza', 4, false, 'Zbroja daje dodatkowy pancerz.'],
  ['Poświęcenie', 5, true, '+1 do wybranej cechy (do 6).'],
  ['Adwersarz', 0, true, 'NPC: utrudnia ataki przeciwko niemu o rangę.'],
  // Talenty dowódcze (Kadet / Mówca): przemowy, rozkazy, prowadzenie załogi okrętu.
  ['Natchniona przemowa', 1, false, 'Akcja Przywództwa: każdy sukces przywraca sojusznikom w zasięgu 1 punkt wysiłku.'],
  ['Głos dowodzenia', 1, true, 'Testy Przywództwa łatwiejsze o rangę (kości wzmocnienia).'],
  ['Autorytet', 1, true, 'Opanowanie i Przywództwo łatwiejsze o rangę; trudniej podważyć twoje rozkazy.'],
  ['Oficer pokładowy', 2, true, 'Na mostku i w centrach kontroli: Dyscyplina i Czujność łatwiejsze o rangę.'],
  ['Dowódca polowy', 2, false, 'Wysiłkiem i testem Przywództwa daje sojusznikom dodatkowy manewr.'],
  ['Zgrana załoga', 3, true, 'Załoga twojego okrętu działa sprawniej: testy obsługi okrętu łatwiejsze o rangę, morale spada wolniej.'],
  ['Taktyk floty', 4, false, 'Raz na starcie: twoja flota zaczyna z przewagą (np. wolny manewr całej formacji).'],
  ['Mistrz retoryki', 5, false, 'Raz na sesję ulepsza kość w teście Uroku, Zastraszania albo Przywództwa.'],
];
export const TALENT = Object.fromEntries(TALENTY.map(([n, p, r, o]) => [n, { poziom: p, rangi: r, opis: o }]));

export const ZOBOWIAZANIA = ['Uzależnienie', 'Zdrada', 'Szantaż', 'Nagroda za głowę', 'Przestępstwo', 'Dług', 'Służba', 'Rodzina', 'Przysługa', 'Przysięga', 'Obsesja', 'Odpowiedzialność'];
export const MOTYWACJE = { Ambicja: ['Bogactwo', 'Sława', 'Wolność', 'Wiedza', 'Władza', 'Status'],
  Sprawa: ['Wolność od Imperium', 'Ochrona słabych', 'Zemsta', 'Sprawiedliwość', 'Wiara', 'Ład i porządek'],
  'Więź': ['Rodzina', 'Przyjaciel', 'Ukochana osoba', 'Mentor', 'Załoga', 'Rywal'] };

export const TRYBY = { gracz: 'Postać gracza', slugus: 'Sługus (grupa)', rywal: 'Rywal', nemezis: 'Nemezis' };
const ZASIEGI = ['przylegający', 'krótki', 'średni', 'daleki', 'ekstremalny'];
export { ZASIEGI };

// ---------- reguły ----------

export const kosztCechy = (nowa) => 10 * nowa;
export const kosztRangi = (nowa, kariery) => 5 * nowa + (kariery ? 0 : 5);
export const kosztTalentu = (poziom) => 5 * Math.max(0, poziom);
export const kosztSpecjalizacji = (ktora, kariery) => 10 * ktora + (kariery ? 0 : 10); // ktora: 2 = druga, 3 = trzecia…

// Kariera postaci na bieżącym poziomie: nazwa (np. „Kapitan”), umiejętności i specjalizacje kariery (z niższymi
// poziomami), numer poziomu i następny próg. Poziom: ręczny MG (`poziom_kariery`) albo z PD zdobytych.
// Dawne klucze (mlodszy_oficer, oficer) to ta sama kariera floty.
const KARIERY_ZASTEPCZE = { mlodszy_oficer: ['kadet', 2], oficer: ['kadet', 5] };
export function karieraPostaci(e) {
  const [klucz, poziomDawny] = KARIERY_ZASTEPCZE[e?.kariera] || [e?.kariera, null];
  const k = KARIERY[klucz];
  if (!k) return { klucz, nazwa: '', umiejetnosci: [], specjalizacje: [], poziom: 0, poziomy: [] };
  if (!k.poziomy) return { klucz, nazwa: k.nazwa, umiejetnosci: k.umiejetnosci, specjalizacje: k.specjalizacje, poziom: 0, poziomy: [] };
  const zdobyte = Number(e.pd?.zdobyte || 0);
  let i = 0;
  k.poziomy.forEach((p, j) => { if (zdobyte >= p.pd) i = j; });
  const reczny = e.poziom_kariery ?? poziomDawny;
  if (reczny != null && k.poziomy[reczny]) i = Number(reczny);
  const dotad = k.poziomy.slice(0, i + 1);
  return { klucz, nazwa: k.poziomy[i].nazwa, poziom: i, poziomy: k.poziomy, nastepny: k.poziomy[i + 1] || null, reczny: reczny != null,
    umiejetnosci: [...k.umiejetnosci, ...dotad.flatMap((p) => p.umiejetnosci || [])],
    specjalizacje: [...k.specjalizacje, ...dotad.flatMap((p) => p.specjalizacje || [])] };
}
export const nazwaKariery = (e) => karieraPostaci(e).nazwa;
// Karta z dawnym kluczem kariery (mlodszy_oficer, oficer) → kariera floty z ręcznym poziomem.
export function normalizujKarte(e) {
  const z = KARIERY_ZASTEPCZE[e?.kariera];
  if (z) { e.poziom_kariery ??= z[1]; e.kariera = z[0]; }
  return e;
}

// Specjalizacja należy do kariery postaci (także z niższego poziomu, np. Mówca dla Kapitana).
export const specjalizacjaKariery = (e, spec) => karieraPostaci(e).specjalizacje.includes(spec);

// Umiejętności kariery: z kariery (na bieżącym poziomie) i ze wszystkich specjalizacji postaci.
export function umiejetnosciKariery(e) {
  const s = new Set(karieraPostaci(e).umiejetnosci);
  for (const sp of e.specjalizacje || []) for (const u of SPECJALIZACJE[sp]?.umiejetnosci || []) s.add(u);
  return s;
}

export function nowaKarta(tryb = 'gracz') {
  const e = { wersja: 1, tryb, imie: '', gatunek: 'czlowiek', kariera: 'przemytnik', specjalizacje: ['pilot'],
    cechy: { krzepa: 2, zwinnosc: 2, intelekt: 2, spryt: 2, wola: 2, prezencja: 2 },
    umiejetnosci: {}, talenty: [], grupa: 3,
    pd: { zdobyte: 0 }, kredyty: 500, zobowiazanie: { typ: 'Dług', wartosc: 10, opis: '', dodatki: { pd: 0, kr: 0 } },
    motywacja: { typ: 'Ambicja', szczegol: 'Wolność', opis: '' },
    bronie: [], pancerz: { nazwa: 'Ciężkie ubranie', pancerz: 1, obrona: 0 }, ekwipunek: '',
    wyglad: { wiek: '', plec: '', wzrost: '', opis: '' }, planeta: '',
    historia: { pochodzenie: '', historia: '', charakter: '', cele: '', kontakty: '' },
    pochodne: { rany: null, wysilek: null, pancerz: null, obrona_w: null, obrona_d: null }, notatki_mg: '' };
  if (tryb !== 'gracz') { e.kariera = ''; e.specjalizacje = []; e.zobowiazanie = null; }
  return e;
}

// Cechy startowe gatunku jako obiekt.
export function cechyGatunku(g) {
  const c = GATUNKI[g]?.cechy || [2, 2, 2, 2, 2, 2];
  return Object.fromEntries(CECHY.map(([k], i) => [k, c[i]]));
}

// Darmowe rangi: gatunek (stała umiejętność albo wybór), kariera (4 / droid 6), specjalizacja (2 / droid 3), człowiek: 2 spoza kariery.
export function limityDarmowych(e) {
  const g = GATUNKI[e.gatunek] || {};
  return { kariera: g.droid ? 6 : 4, specjalizacja: g.droid ? 3 : 2, dowolne: g.dowolne || 0 };
}
const rangaUm = (e, k) => e.umiejetnosci?.[k] || {};
export const ranga = (e, k) => Number(rangaUm(e, k).ranga || 0);
export function darmoweRangi(e, k) {
  const u = rangaUm(e, k), g = GATUNKI[e.gatunek] || {};
  return (u.wK ? 1 : 0) + (u.wS ? 1 : 0) + (u.wD ? 1 : 0) + (g.umiejetnosc === k || (g.wybor && u.wG) ? 1 : 0);
}

// Pochodne: progi ran i wysiłku, pancerz, obrona (talenty Zahartowany, Hart, Wytrzymały). Wartości ręczne MG wygrywają.
export function pochodne(e) {
  const g = GATUNKI[e.gatunek] || { rany: 10, wysilek: 10 };
  const rangaT = (n) => (e.talenty || []).filter((t) => t.nazwa === n).reduce((s, t) => s + Number(t.ranga || 1), 0);
  const p = e.pochodne || {};
  const rec = (k, auto) => (p[k] != null && p[k] !== '' ? Number(p[k]) : auto);
  // NPC: sługus ~ Krzepa + 2 (na jednego), rywal 8 + Krzepa, nemezis 10 + Krzepa (+ talenty); MG może wpisać własne.
  const ranyAuto = { slugus: e.cechy.krzepa + 2, rywal: 8 + e.cechy.krzepa, nemezis: 10 + e.cechy.krzepa }[e.tryb] ?? g.rany + e.cechy.krzepa;
  return {
    rany: rec('rany', ranyAuto + 2 * rangaT('Zahartowany')),
    wysilek: e.tryb === 'slugus' || e.tryb === 'rywal' ? null : rec('wysilek', (e.tryb === 'nemezis' ? 10 : g.wysilek) + e.cechy.wola + rangaT('Hart')),
    pancerz: rec('pancerz', e.cechy.krzepa + Number(e.pancerz?.pancerz || 0) + rangaT('Wytrzymały')),
    obrona_w: rec('obrona_w', Number(e.pancerz?.obrona || 0)),
    obrona_d: rec('obrona_d', Number(e.pancerz?.obrona || 0)),
  };
}

// Pula kości umiejętności: wyższa z (cecha, ranga) to liczba kości, niższa — ile z nich to kości biegłości.
export function pula(e, k) {
  const c = Number(e.cechy[UMIEJ[k].cecha] || 0);
  const r = e.tryb === 'slugus' ? (rangaUm(e, k).ranga ? Math.max(0, Math.min(5, (Number(e.grupa) || 1) - 1)) : 0) : ranga(e, k);
  return { bieglosc: Math.min(c, r), zdolnosc: Math.max(c, r) - Math.min(c, r) };
}

// Rachunek PD postaci gracza przy tworzeniu (i później: zdobyte PD w e.pd.zdobyte) oraz ostrzeżenia o regułach.
export function rachunekPD(e) {
  const g = GATUNKI[e.gatunek] || GATUNKI.czlowiek;
  const kariery = umiejetnosciKariery(e);
  const lim = limityDarmowych(e);
  const baza = cechyGatunku(e.gatunek);
  const pozycje = [];
  const uwagi = [];
  let cechy = 0;
  for (const [k, n] of CECHY) {
    for (let v = baza[k] + 1; v <= e.cechy[k]; v++) cechy += kosztCechy(v);
    if (e.cechy[k] > 5) uwagi.push(`${n}: przy tworzeniu najwyżej 5.`);
    if (e.cechy[k] < baza[k]) uwagi.push(`${n}: niżej niż startowo dla gatunku (${baza[k]}).`);
  }
  if (cechy) pozycje.push(['Cechy', cechy]);
  let umiej = 0, wK = 0, wS = 0, wD = 0;
  const spec = new Set((e.specjalizacje || []).flatMap((s) => SPECJALIZACJE[s]?.umiejetnosci || []));
  const kariera = new Set(karieraPostaci(e).umiejetnosci);
  for (const [k, n] of UMIEJETNOSCI) {
    const u = rangaUm(e, k), r = ranga(e, k), wolne = darmoweRangi(e, k);
    if (u.wK) { wK++; if (!kariera.has(k)) uwagi.push(`${n}: darmowa ranga kariery tylko dla umiejętności kariery.`); }
    if (u.wS) { wS++; if (!spec.has(k)) uwagi.push(`${n}: darmowa ranga specjalizacji tylko dla jej umiejętności.`); }
    if (u.wD) { wD++; if (kariery.has(k)) uwagi.push(`${n}: premia gatunku (Człowiek) dotyczy umiejętności spoza kariery.`); }
    if (wolne > r) uwagi.push(`${n}: darmowych rang (${wolne}) więcej niż ranga (${r}).`);
    for (let v = wolne + 1; v <= r; v++) umiej += kosztRangi(v, kariery.has(k));
    if (r > 2 && !(e.pd?.zdobyte > 0)) uwagi.push(`${n}: przy tworzeniu najwyżej ranga 2.`);
  }
  if (wK > lim.kariera) uwagi.push(`Darmowe rangi kariery: ${wK} z ${lim.kariera}.`);
  if (wS > lim.specjalizacja) uwagi.push(`Darmowe rangi specjalizacji: ${wS} z ${lim.specjalizacja}.`);
  if (wD > lim.dowolne) uwagi.push(`Premia gatunku: ${wD} z ${lim.dowolne} rang.`);
  if (umiej) pozycje.push(['Umiejętności', umiej]);
  const talenty = (e.talenty || []).reduce((s, t) => s + kosztTalentu(Number(t.poziom || 0)) * Math.max(1, Number(t.ranga || 1)), 0)
    - (g.talent && (e.talenty || []).some((t) => t.nazwa === g.talent) ? kosztTalentu(TALENT[g.talent]?.poziom || 1) : 0);
  if (talenty) pozycje.push(['Talenty', talenty]);
  let specKoszt = 0;
  (e.specjalizacje || []).slice(1).forEach((s, i) => { specKoszt += kosztSpecjalizacji(i + 2, specjalizacjaKariery(e, s)); });
  if (specKoszt) pozycje.push(['Dodatkowe specjalizacje', specKoszt]);
  const dodatkowe = Number(e.zobowiazanie?.dodatki?.pd || 0);
  const start = g.pd + dodatkowe;
  const wydane = pozycje.reduce((s, [, v]) => s + v, 0);
  const wszystkie = start + Number(e.pd?.zdobyte || 0);
  if (wydane > wszystkie) uwagi.push(`Wydano ${wydane} PD, dostępne ${wszystkie}.`);
  if (e.kariera && e.specjalizacje?.[0] && !specjalizacjaKariery(e, e.specjalizacje[0])) uwagi.push('Pierwsza specjalizacja musi pochodzić z kariery.');
  return { start: g.pd, dodatkowe, zdobyte: Number(e.pd?.zdobyte || 0), wydane, zostalo: wszystkie - wydane, pozycje, uwagi };
}

// ---------- szablony przeciwników i losowanie ----------

const B = (nazwa, umiejetnosc, obr, kryt, zasieg, cechy = '') => ({ nazwa, umiejetnosc, obr, kryt, zasieg, cechy });
export const SZABLONY = {
  szturmowiec: { nazwa: 'Szturmowiec', tryb: 'slugus', gatunek: 'czlowiek', cechy: [3, 3, 2, 2, 3, 1], grupa: 4,
    umiejetnosci: ['atletyka', 'dyscyplina', 'bron_ciezka', 'czujnosc'], pancerz: { nazwa: 'Zbroja szturmowca', pancerz: 2, obrona: 0 },
    bronie: [B('Karabin blasterowy E-11', 'bron_ciezka', 9, 3, 'daleki', 'Ogłuszanie'), B('Wibronóż', 'walka_wrecz', 4, 2, 'przylegający', 'Penetracja 1')],
    rola: 'Żołnierz Imperium', frakcja: 'Imperium', rany: 5 },
  zbir: { nazwa: 'Zbir kartelu', tryb: 'slugus', gatunek: 'czlowiek', cechy: [3, 2, 1, 2, 2, 1], grupa: 3,
    umiejetnosci: ['bijatyka', 'walka_wrecz', 'bron_lekka', 'zastraszanie'], pancerz: { nazwa: 'Ciężkie ubranie', pancerz: 1, obrona: 0 },
    bronie: [B('Pistolet blasterowy', 'bron_lekka', 6, 3, 'średni', 'Ogłuszanie'), B('Pałka', 'walka_wrecz', 5, 5, 'przylegający', 'Dezorientacja 1')],
    rola: 'Ochrona kartelu', rany: 5 },
  oficer: { nazwa: 'Oficer imperialny', tryb: 'rywal', gatunek: 'czlowiek', cechy: [2, 2, 3, 3, 3, 3],
    umiejetnosci: { dyscyplina: 2, przywodztwo: 2, zastraszanie: 2, bron_lekka: 1, w_jadro: 2, opanowanie: 1 },
    pancerz: { nazwa: 'Mundur', pancerz: 0, obrona: 0 }, bronie: [B('Pistolet blasterowy', 'bron_lekka', 6, 3, 'średni', 'Ogłuszanie')],
    rola: 'Oficer Imperium', frakcja: 'Imperium', rany: 12 },
  przemytnik: { nazwa: 'Przemytnik', tryb: 'rywal', gatunek: 'czlowiek', cechy: [2, 3, 2, 3, 2, 2],
    umiejetnosci: { oszustwo: 2, pilotaz_kosm: 2, bron_lekka: 2, w_polswiatek: 1, ulica: 2, opanowanie: 1 },
    pancerz: { nazwa: 'Ciężkie ubranie', pancerz: 1, obrona: 0 }, bronie: [B('Ciężki pistolet blasterowy', 'bron_lekka', 7, 3, 'średni', 'Ogłuszanie')],
    rola: 'Przemytnik', rany: 12 },
  najemnik: { nazwa: 'Najemnik', tryb: 'rywal', gatunek: 'czlowiek', cechy: [3, 3, 2, 2, 2, 1],
    umiejetnosci: { bron_ciezka: 2, atletyka: 2, czujnosc: 2, odpornosc: 1, walka_wrecz: 1 },
    pancerz: { nazwa: 'Pancerz laminowany', pancerz: 2, obrona: 0 }, bronie: [B('Karabin blasterowy', 'bron_ciezka', 9, 3, 'daleki', 'Ogłuszanie')],
    rola: 'Najemnik', rany: 14 },
  informator: { nazwa: 'Handlarz informacją', tryb: 'rywal', gatunek: 'bothanin', cechy: [1, 2, 3, 3, 2, 3],
    umiejetnosci: { negocjacje: 3, oszustwo: 2, urok: 2, w_polswiatek: 2, percepcja: 2, ulica: 2 },
    pancerz: { nazwa: 'Ubranie', pancerz: 0, obrona: 0 }, bronie: [B('Pistolet podręczny', 'bron_lekka', 5, 4, 'krótki', 'Ogłuszanie')],
    rola: 'Informator', rany: 10 },
  lowca: { nazwa: 'Łowca nagród', tryb: 'nemezis', gatunek: 'trandoshanin', cechy: [3, 3, 2, 3, 3, 2],
    umiejetnosci: { bron_ciezka: 3, percepcja: 3, czujnosc: 2, atletyka: 2, ulica: 2, bijatyka: 2, pilotaz_kosm: 2, przetrwanie: 2 },
    talenty: [{ nazwa: 'Adwersarz', ranga: 2, poziom: 0 }, { nazwa: 'Tropiciel', ranga: 2, poziom: 1 }],
    pancerz: { nazwa: 'Pancerz łowcy', pancerz: 2, obrona: 1 }, bronie: [B('Ciężki karabin blasterowy', 'bron_ciezka', 10, 3, 'daleki', 'Automatyczna, Kłopotliwa 3')],
    rola: 'Łowca nagród', rany: 18, wysilek: 15 },
  dowodca: { nazwa: 'Dowódca imperialny', tryb: 'nemezis', gatunek: 'czlowiek', cechy: [2, 2, 3, 3, 4, 4],
    umiejetnosci: { przywodztwo: 4, dyscyplina: 3, zastraszanie: 3, opanowanie: 3, w_jadro: 2, bron_lekka: 2, czujnosc: 2 },
    talenty: [{ nazwa: 'Adwersarz', ranga: 2, poziom: 0 }],
    pancerz: { nazwa: 'Mundur z kamizelką', pancerz: 1, obrona: 0 }, bronie: [B('Pistolet blasterowy', 'bron_lekka', 6, 3, 'średni', 'Ogłuszanie')],
    rola: 'Dowódca', frakcja: 'Imperium', rany: 15, wysilek: 17 },
};

const SYLABY = {
  czlowiek: [['Ka', 'Jo', 'Mi', 'Da', 'Re', 'Ta', 'Ve', 'Lo', 'Ser', 'Bra'], ['ren', 'lan', 'dor', 'ria', 'vin', 'sa', 'tus', 'mir'], ['Vos', 'Kade', 'Trel', 'Orrin', 'Sarno', 'Daal', 'Mekk', 'Varga']],
  bothanin: [['Bor', 'Kre', 'Tav', 'Ase'], ['sk', 'ya', 'lo', 'vis'], ["Fey'lya", 'Rakis', 'Toltsk', 'Merv']],
  droid: [['R', 'C', 'K', 'IG', 'BX', 'TT'], ['2', '4', '8', '-1', '-9'], ['', '-D2', '-P0', '-B4']],
  gand: [['Zu', 'Re', 'Ga'], ['ck', 'nd', 'vok'], ['uss', 'arr', 'ill']],
  rodianin: [['Gre', 'Na', 'Ree', 'Doo'], ['do', 'kee', 'vo', 'ta'], ['Neeto', 'Tavik', 'Chussa']],
  trandoshanin: [['Bo', 'Ss', 'Ga', 'Kra'], ['ssk', 'lak', 'rok', 'zzt'], ['Sskel', 'Graa', 'Trasskh']],
  twilek: [['Hera', 'Ny', 'Bi', 'Oo', 'Le'], ['la', 'ib', 'ssa', 'ra'], ['Syn', "Ka'lan", 'Fortuna', 'Tasu']],
  wookiee: [['Chew', 'Ta', 'Lowb', 'Mal', 'Gr'], ['bac', 'rrk', 'ach', 'luk'], ['ca', 'kka', 'arr', 'ok']],
};
const los = (t) => t[Math.floor(Math.random() * t.length)];
export function losujImie(gatunek) {
  const [a, b, c] = SYLABY[gatunek] || SYLABY.czlowiek;
  if (gatunek === 'droid') return `${los(a)}${los(b)}${los(c)}`;
  if (gatunek === 'wookiee') return `${los(a)}${los(b)}${los(c)}`;
  return `${los(a)}${los(b)} ${los(c)}`;
}

// NPC z szablonu (z lekkim rozrzutem cech i umiejętności przy „Losuj”).
export function zSzablonu(klucz, rozrzut = false) {
  const s = SZABLONY[klucz];
  const e = nowaKarta(s.tryb);
  e.szablon = klucz;
  e.gatunek = s.gatunek;
  e.cechy = Object.fromEntries(CECHY.map(([k], i) => [k, Math.max(1, Math.min(5, s.cechy[i] + (rozrzut && Math.random() < 0.25 ? (Math.random() < 0.5 ? -1 : 1) : 0)))]));
  e.umiejetnosci = Array.isArray(s.umiejetnosci)
    ? Object.fromEntries(s.umiejetnosci.map((k) => [k, { ranga: 1 }]))
    : Object.fromEntries(Object.entries(s.umiejetnosci).map(([k, r]) => [k, { ranga: Math.max(1, Math.min(5, r + (rozrzut && Math.random() < 0.2 ? 1 : 0))) }]));
  e.talenty = (s.talenty || []).map((t) => ({ ...t, opis: TALENT[t.nazwa]?.opis || '' }));
  e.pancerz = { ...s.pancerz };
  e.bronie = s.bronie.map((b) => ({ ...b }));
  e.grupa = s.grupa || 1;
  if (s.rany) e.pochodne.rany = s.rany;
  if (s.wysilek) e.pochodne.wysilek = s.wysilek;
  e.rola = s.rola || '';
  e.imie = rozrzut ? losujImie(s.gatunek) : s.nazwa;
  e.kredyty = s.tryb === 'slugus' ? 0 : 50 + Math.floor(Math.random() * 400);
  return e;
}

// Szkic historii z prostych tabel losowych (MG dopisuje resztę).
const SZKIC = {
  pochodzenie: ['Dorastał(a) w porcie kosmicznym na Zewnętrznych Rubieżach, wśród mechaników i przemytników.',
    'Pochodzi z rodziny rolników wilgoci; odszedł(odeszła), gdy farmę przejął kartel.',
    'Wychowany(a) w akademii na jednym ze Światów Jądra — i wyrzucony(a) z niej za niesubordynację.',
    'Dziecko załogi frachtowca; dom to ładownia i kolejne porty.',
    'Wyrósł(a) w dolnych poziomach ekumenopolis, gdzie światło dnia było legendą.',
    'Przeżył(a) imperialną okupację rodzinnego świata i nie zapomniał(a) tego.'],
  wydarzenie: {
    lowca: 'Pierwsze zlecenie skończyło się źle: cel okazał się niewinny, a zleceniodawca — nie.',
    kolonista: 'Prowadził(a) spokojne życie, dopóki jedna decyzja nie wplątała go(jej) w politykę sektora.',
    odkrywca: 'Na nieznanym szlaku znalazł(a) coś, czego ktoś bardzo chciał nie ujawniać.',
    najemnik: 'Oddział, w którym służył(a), został poświęcony dla czyjegoś zysku. Przeżył(a) jako jedyny(a).',
    przemytnik: 'Zrzucił(a) ładunek przed imperialną kontrolą — i od tej pory jest winny(a) komuś bardzo dużo.',
    technik: 'Złożył(a) z części coś, co działało za dobrze. Teraz szukają tego ludzie w szarych mundurach.',
    kadet: 'Ukończył(a) akademię floty z wyróżnieniem z retoryki i taktyki; pierwszy przydział dostał(a) na pokładzie gwiezdnego niszczyciela.',
    '': 'Los rzucił go(ją) tam, gdzie pracuje dziś, i trzyma mocno.',
  },
  charakter: ['Ostrożny(a) i nieufny(a), ale lojalny(a) wobec tych, którzy raz udowodnili swoje.', 'Gaduła z poczuciem humoru, który maskuje strach.',
    'Zimny(a) profesjonalista(ka): kontrakt to kontrakt.', 'Idealista(ka), który(a) jeszcze nie stracił(a) nadziei.', 'Hazardzista(ka) — także życiem.'],
};
export function szkicHistorii(e) {
  return {
    pochodzenie: los(SZKIC.pochodzenie),
    historia: SZKIC.wydarzenie[e.kariera] || SZKIC.wydarzenie[''],
    charakter: los(SZKIC.charakter),
    cele: e.motywacja ? `${e.motywacja.typ}: ${e.motywacja.szczegol}.` : '',
    kontakty: '',
  };
}

// ---------- widok CV ----------

// CV postaci (h — konstruktor elementów z narzedzia.js). opcje.mg — pokazuje notatki MG.
export function rysujCV(h, e, opcje = {}) {
  if (!e) return h('div.cv-pusto', 'Karta EotE nie została jeszcze wypełniona.');
  const g = GATUNKI[e.gatunek];
  const p = pochodne(e);
  const kar = umiejetnosciKariery(e);
  const tekst = (t) => (t ? h('p.cv-tekst', t) : null);
  const sekcja = (tytul, ...tresc) => (tresc.filter(Boolean).length ? h('section.cv-sekcja', h('h3', tytul), ...tresc) : null);
  const kosci = (k) => {
    const { bieglosc, zdolnosc } = pula(e, k);
    return h('span.cv-kosci', { title: `${bieglosc} biegłości, ${zdolnosc} zdolności` },
      ...Array.from({ length: bieglosc }, () => h('i.cv-k-b')), ...Array.from({ length: zdolnosc }, () => h('i.cv-k-z')));
  };
  const umiej = UMIEJETNOSCI.filter(([k]) => (e.tryb === 'slugus' ? !!e.umiejetnosci?.[k]?.ranga : ranga(e, k) > 0));
  const rangiTxt = (k) => (e.tryb === 'slugus' ? '' : '●'.repeat(ranga(e, k)) + '○'.repeat(Math.max(0, 5 - ranga(e, k))));
  const rachunek = e.tryb === 'gracz' ? rachunekPD(e) : null;
  const ob = e.zobowiazanie;
  const linia = [g?.nazwa, nazwaKariery(e), (e.specjalizacje || []).map((s) => SPECJALIZACJE[s]?.nazwa).filter(Boolean).join(' / '),
    e.tryb !== 'gracz' ? TRYBY[e.tryb] : null, e.tryb !== 'gracz' ? e.rola : null].filter(Boolean).join(' · ');
  return h('article.cv',
    h('header.cv-glowa',
      h('div.cv-portret', { 'aria-hidden': 'true' }, (e.imie || '?').trim().slice(0, 1).toUpperCase()),
      h('div.cv-kto',
        h('div.cv-imie', e.imie || 'Bez imienia', e.tryb === 'slugus' ? h('span.cv-grupa', ` × ${e.grupa || 1}`) : null),
        h('div.cv-linia', linia),
        h('div.cv-dane', [e.planeta ? `Świat: ${e.planeta}` : null, e.wyglad?.wiek ? `Wiek: ${e.wyglad.wiek}` : null,
          e.wyglad?.plec || null, e.wyglad?.wzrost ? `Wzrost: ${e.wyglad.wzrost}` : null].filter(Boolean).join(' · ')))),
    h('div.cv-cechy', ...CECHY.map(([k, n]) => h('div.cv-cecha', h('b', String(e.cechy?.[k] ?? 0)), h('span', n)))),
    h('div.cv-pochodne',
      h('div', h('b', String(p.rany)), h('span', e.tryb === 'slugus' ? 'Rany (na sługusa)' : 'Próg ran')),
      p.wysilek != null ? h('div', h('b', String(p.wysilek)), h('span', 'Próg wysiłku')) : null,
      h('div', h('b', String(p.pancerz)), h('span', 'Pancerz')),
      h('div', h('b', `${p.obrona_w} | ${p.obrona_d}`), h('span', 'Obrona wręcz | dystans'))),
    sekcja('Umiejętności',
      umiej.length ? h('table.cv-umiej', ...Object.entries(GRUPY_UMIEJ).flatMap(([gr, nazwa]) => {
        const lista = umiej.filter(([, , , g0]) => g0 === gr);
        return lista.length ? [h('tr.cv-gr', h('th', { colspan: 4 }, nazwa)), ...lista.map(([k, n, c]) => h('tr',
          h('td', n, kar.has(k) && e.tryb === 'gracz' ? h('span.cv-kar', { title: 'umiejętność kariery' }, ' ◆') : null),
          h('td.cv-slaby', SKROT_CECHY[c]), h('td.cv-rangi', rangiTxt(k)), h('td', kosci(k))))] : [];
      })) : h('p.cv-slaby', 'Brak wyszkolonych umiejętności.')),
    sekcja('Talenty', (e.talenty || []).length ? h('ul.cv-lista', ...(e.talenty || []).map((t) => h('li',
      h('b', t.nazwa), Number(t.ranga) > 1 ? ` ${t.ranga}` : '', (t.opis || TALENT[t.nazwa]?.opis) ? h('span.cv-slaby', ` — ${t.opis || TALENT[t.nazwa].opis}`) : null))) : null),
    g ? sekcja('Cechy gatunku', tekst(g.opis)) : null,
    sekcja('Uzbrojenie i wyposażenie',
      (e.bronie || []).length ? h('table.cv-bronie', h('tr', h('th', 'Broń'), h('th', 'Umiejętność'), h('th', 'Obr.'), h('th', 'Kryt.'), h('th', 'Zasięg'), h('th', 'Cechy')),
        ...(e.bronie || []).map((b) => h('tr', h('td', b.nazwa), h('td', UMIEJ[b.umiejetnosc]?.nazwa || b.umiejetnosc || ''),
          h('td', String(b.obr ?? '')), h('td', String(b.kryt ?? '')), h('td', b.zasieg || ''), h('td.cv-slaby', b.cechy || '')))) : null,
      e.pancerz?.nazwa ? h('p', h('b', 'Pancerz: '), `${e.pancerz.nazwa} (pancerz +${e.pancerz.pancerz || 0}, obrona ${e.pancerz.obrona || 0})`) : null,
      e.ekwipunek ? h('p', h('b', 'Ekwipunek: '), e.ekwipunek) : null,
      e.tryb !== 'slugus' ? h('p', h('b', 'Kredyty: '), `${Number(e.kredyty || 0).toLocaleString('pl-PL')} kr`) : null),
    e.tryb === 'gracz' ? sekcja('Zobowiązanie i motywacja',
      ob ? h('p', h('b', `Zobowiązanie: ${ob.typ} ${ob.wartosc}`), ob.opis ? ` — ${ob.opis}` : '') : null,
      e.motywacja ? h('p', h('b', `Motywacja: ${e.motywacja.typ} (${e.motywacja.szczegol})`), e.motywacja.opis ? ` — ${e.motywacja.opis}` : '') : null) : null,
    sekcja('Historia',
      e.wyglad?.opis ? h('div', h('h4', 'Wygląd'), tekst(e.wyglad.opis)) : null,
      e.historia?.pochodzenie ? h('div', h('h4', 'Pochodzenie'), tekst(e.historia.pochodzenie)) : null,
      e.historia?.historia ? h('div', h('h4', 'Dotychczasowe losy'), tekst(e.historia.historia)) : null,
      e.historia?.charakter ? h('div', h('h4', 'Charakter'), tekst(e.historia.charakter)) : null,
      e.historia?.cele ? h('div', h('h4', 'Cele'), tekst(e.historia.cele)) : null,
      e.historia?.kontakty ? h('div', h('h4', 'Znajomości'), tekst(e.historia.kontakty)) : null),
    rachunek ? sekcja('Doświadczenie',
      h('p', `PD: ${rachunek.start} startowe${rachunek.dodatkowe ? ` + ${rachunek.dodatkowe} za zobowiązanie` : ''}${rachunek.zdobyte ? ` + ${rachunek.zdobyte} zdobyte` : ''}`
        + ` · wydane ${rachunek.wydane} · zostało ${rachunek.zostalo}`)) : null,
    opcje.mg && e.notatki_mg ? sekcja('Notatki MG (tylko panel)', tekst(e.notatki_mg)) : null);
}

export { NAZWA_CECHY };

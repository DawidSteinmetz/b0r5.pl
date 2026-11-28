// Baza ustawień dźwięków generowanych (dzwieki_baza.json) — strojona na stronie narzedzia/dzwieki/oslony.html
// i zapisywana tam przyciskiem „ZAPISZ DO BAZY”. Gra bierze stąd parametry każdego dźwięku:
//   const baza = await wczytajBazeDzwiekow();
//   new DzwiekOslon(ctx, wyjscie, baza.oslona);
//   trafienieOslony(ctx, wyjscie, baza.trafienie_oslony, { hz, sila, oslona });
//   wystrzal(ctx, wyjscie, baza.wystrzal_turbolasera, { hz, sila, pan });

export const ADRES_BAZY = new URL('./dzwieki_baza.json', import.meta.url);
// Profil źródłowy ISD strojony w edytorze dźwięków; gra i edytor korzystają z tych samych nastaw osłon.
export const ADRES_PROFIL_OSLON = new URL('../../narzedzia/dzwieki/os%C5%82ona_isd_i_1.json', import.meta.url);

let obietnica = null;

export function wczytajBazeDzwiekow({ odNowa = false } = {}) {
  if (!obietnica || odNowa) {
    obietnica = Promise.all([
      fetch(ADRES_BAZY, { cache: 'no-cache' }).then((r) => (r.ok ? r.json() : {})).catch(() => ({})),
      fetch(ADRES_PROFIL_OSLON, { cache: 'no-cache' }).then((r) => (r.ok ? r.json() : {})).catch(() => ({})),
    ]).then(([baza, profil]) => ({ ...baza, oslona: { ...(baza.oslona || {}), ...(profil.oslona || {}) } }))
      .catch(() => ({}));
  }
  return obietnica;
}

// Tożsamość urządzenia w świecie gry. Zmieniajcie śmiało — to jedyne miejsce z tymi nazwami.

export const URZADZENIE = {
  producent: 'Corellian Microsystems',
  model: 'Datapad CM-7 „Szpak"',
  system: 'HOLOLINK',
  wersja: '4.2.1',
  numerSeryjny: () => {
    // Stały numer na danym tablecie, żeby każdy egzemplarz był „inny".
    try {
      let n = localStorage.getItem('datapad.numer');
      if (!n) {
        n = 'CM7-' + Math.random().toString(36).slice(2, 8).toUpperCase();
        localStorage.setItem('datapad.numer', n);
      }
      return n;
    } catch {
      return 'CM7-??????';
    }
  },
};

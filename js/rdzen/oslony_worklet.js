// Generator impulsów osłon — liczony na żywo w wątku audio przeglądarki (AudioWorklet).
// Każdy impuls to krótki, wygasający błysk szumu. Gęstość to liczba impulsów na sekundę (Hz):
// przy 1 Hz słychać pojedyncze trzaski, przy setkach ziarnisty szum, przy 3600 Hz impulsy zlewają się w ścianę.
// Rozrzut odstępów: 0 = równy takt (przy dużej gęstości słychać ton), 1 = całkiem losowe odstępy (szum).
// Dwa kanały mają niezależne losowanie, więc pole brzmi szeroko, a nie z jednego punktu.

class ImpulsyOslon extends AudioWorkletProcessor {
  static get parameterDescriptors() {
    return [
      { name: 'gestosc', defaultValue: 0, minValue: 0, maxValue: 8000, automationRate: 'k-rate' },
      { name: 'rozrzut', defaultValue: 0.7, minValue: 0, maxValue: 1, automationRate: 'k-rate' },
      { name: 'dlugosc', defaultValue: 0.0015, minValue: 0.0002, maxValue: 0.02, automationRate: 'k-rate' },
      // Siła wyrównania głośności: 0 = każdy impuls tak samo głośny (gęsta ściana dużo głośniejsza),
      // 1 = pełne wyrównanie (ściana tak głośna jak pojedynczy trzask).
      { name: 'wyrownanie', defaultValue: 0.8, minValue: 0, maxValue: 1, automationRate: 'k-rate' },
      // Wypełnienie impulsu: 0 = czysty, zawsze ten sam impuls (równy takt daje czysty, buczący ton),
      // 1 = błysk szumu (chropowaty, ziarnisty).
      { name: 'szum', defaultValue: 1, minValue: 0, maxValue: 1, automationRate: 'k-rate' },
    ];
  }

  constructor() {
    super();
    // Faza liczona w okresach (0…prog), nie w próbkach — zmiana gęstości działa od razu,
    // także gdy generator rusza z prawie zerowej wartości.
    this.kanaly = [1, 0.6].map((faza) => ({ faza, prog: 1, obwiednia: 0 }));
    // Węzły jednorazowe (np. trafienia) dostają „koniec” — wtedy procesor kończy pracę i może zostać sprzątnięty.
    this.koniec = false;
    this.port.onmessage = (e) => { if (e.data === 'koniec') this.koniec = true; };
  }

  process(_wejscia, wyjscia, p) {
    if (this.koniec) return false;
    const wyjscie = wyjscia[0];
    const f = p.gestosc[0], rozrzut = p.rozrzut[0], szum = p.szum[0];
    const zanik = Math.exp(-1 / (p.dlugosc[0] * sampleRate));
    // Szum losowych impulsów rośnie jak pierwiastek z gęstości — tyle oddajemy z powrotem.
    const amp = 1.8 / Math.pow(1 + f / 4, 0.5 * p.wyrownanie[0]);
    const krok = f > 0 ? f / sampleRate : 0;
    for (let k = 0; k < wyjscie.length; k++) {
      const kan = this.kanaly[k % 2], out = wyjscie[k];
      // Cisza: generator stoi, a pierwszy impuls po starcie pada od razu.
      if (f <= 0 && kan.obwiednia < 1e-5) { out.fill(0); kan.faza = kan.prog; continue; }
      for (let i = 0; i < out.length; i++) {
        kan.faza += krok;
        if (krok > 0 && kan.faza >= kan.prog) {
          kan.faza -= kan.prog;
          kan.obwiednia = amp * (0.55 + 0.45 * Math.random());
          // Następny odstęp (w okresach): mieszanka równego taktu i losowego (wykładniczego) czekania.
          const los = -Math.log(1 - Math.random());
          kan.prog = Math.max(0.02, (1 - rozrzut) + rozrzut * los);
        }
        out[i] = kan.obwiednia * ((1 - szum) + szum * (Math.random() * 2 - 1));
        kan.obwiednia *= zanik;
      }
    }
    return true;
  }
}

registerProcessor('impulsy-oslon', ImpulsyOslon);

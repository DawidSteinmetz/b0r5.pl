// Odtwarzacz efektów osłon i turbolaserów z ustawieniami z dzwieki_baza.json.
// Użycie w obsłudze gestu użytkownika:
//   const audio = new OdtwarzaczDzwiekow();
//   await audio.wlaczOslony(3600);
//   audio.trafienie({ hz: 1800, sila: 0.8 });
//   audio.wystrzal({ hz: 3600, sila: 1, pan: -0.6 });

import { pobierzTorAudio } from './dzwiek.js';
import { wczytajBazeDzwiekow } from './dzwieki_baza.js';
import { DzwiekOslon } from './dzwiek_oslon.js';
import { przygotujTrafienia, trafienieOslony } from './dzwiek_trafienia.js';
import { przygotujWystrzaly, wystrzal } from './dzwiek_wystrzalu.js';

export class OdtwarzaczDzwiekow {
  constructor({ ctx = null, wyjscie = null } = {}) {
    this.ctx = ctx;
    this.wyjscie = wyjscie;
    this.baza = null;
    this.oslona = null;
    this.oslony = [];
    this.generatoryHz = new Map();
    this.magistralaOslon = null;
    this.gotowe = null;
  }

  przygotuj() {
    if (!this.gotowe) {
      this.gotowe = this._przygotuj().catch((e) => {
        this.gotowe = null;
        throw e;
      });
    }
    return this.gotowe;
  }

  async _przygotuj() {
    if (!this.ctx) {
      const tor = pobierzTorAudio();
      if (!tor) throw new Error('Ta przeglądarka nie obsługuje Web Audio');
      this.ctx = tor.ctx;
      this.wyjscie ||= tor.wyjscie;
    }
    this.wyjscie ||= this.ctx.destination;
    await this.ctx.resume();
    this.baza = await wczytajBazeDzwiekow();
    await Promise.all([przygotujTrafienia(this.ctx), przygotujWystrzaly(this.ctx)]);
    this.magistralaOslon = new GainNode(this.ctx, { gain: 0.42 });
    this.limiterOslon = new DynamicsCompressorNode(this.ctx, { threshold: -5, knee: 2, ratio: 18, attack: 0.003, release: 0.2 });
    this.magistralaOslon.connect(this.limiterOslon).connect(this.wyjscie);
    this.oslony = [-0.28, 0.28].map((pan) => new DzwiekOslon(this.ctx, this.magistralaOslon, this.baza.oslona, { pan }));
    this.oslona = this.oslony[0]; // zgodność z dotychczasową reakcją dźwiękową na trafienie
    await Promise.all(this.oslony.map((glos) => glos.przygotuj()));
    return this;
  }

  async wlaczOslony(hz = 3600) {
    await this.przygotuj();
    this.oslona.wlacz(hz);
    return this;
  }

  wylaczOslony() {
    this.oslony.forEach((glos) => glos.wylacz());
    this.generatoryHz.clear();
  }

  // Każdy generator ISD ma własny, niezależny głos; `hz_osiagane` śledzi faktyczną pracę generatora.
  async ustawGeneratory(generatory = []) {
    await this.przygotuj();
    generatory.slice(0, 2).forEach((generator, i) => {
      const glos = this.oslony[i];
      const hz = Math.max(0, Number(generator.hz_osiagane) || 0);
      const klucz = generator.id ?? i;
      const poprzednie = this.generatoryHz.get(klucz);
      if (poprzednie === hz && glos.wlaczone === (hz > 0)) return;
      this.generatoryHz.set(klucz, hz);
      if (hz > 0) {
        if (!glos.wlaczone) glos.wlacz(hz);
        else glos.ustawHz(hz);
      } else glos.wylacz();
    });
    for (let i = generatory.length; i < this.oslony.length; i++) this.oslony[i].wylacz();
  }

  async trafienie({ hz = 1800, sila = 1, pan = null } = {}) {
    await this.przygotuj();
    trafienieOslony(this.ctx, this.wyjscie, this.baza.trafienie_oslony, {
      hz, sila, pan, oslona: this.oslona?.wlaczone ? this.oslona : null,
    });
  }

  async wystrzal({ hz = 3600, sila = 1, pan = null } = {}) {
    await this.przygotuj();
    wystrzal(this.ctx, this.wyjscie, this.baza.wystrzal_turbolasera, { hz, sila, pan });
  }

  zniszcz() {
    this.wylaczOslony();
    this.oslony.forEach((glos) => glos.zniszcz());
    this.oslony = [];
    this.magistralaOslon?.disconnect();
    this.limiterOslon?.disconnect();
    this.magistralaOslon = null;
    this.limiterOslon = null;
    this.oslona = null;
    this.gotowe = null;
  }
}

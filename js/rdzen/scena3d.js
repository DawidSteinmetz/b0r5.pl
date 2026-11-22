// Scena 3D za oknem (prototyp, etap 1 — narzedzia/3d/PLAN.md): na orbicie świat rysuje WebGL (Three.js), a scena 2D
// (scena.js) na przezroczystym płótnie nad nim — tylko ramę okna mostka / kokpitu. Inne sceny (skok, powierzchnia,
// start) rysuje dalej 2D. Miejsce i kierunek kamery z tego samego widoku co w 2D (orbita, kurs, dok, przelot).
//
// Jednostka: 1 = 1000 km. Płaszczyzna równika = XZ; kąt mapy θ (0° wschód, 90° północ) → (cos θ, 0, −sin θ).
// Gdy Three.js się nie wczyta (brak sieci, stary tablet) — zostaje zwykła scena 2D.

import { utworzScene, TYPY_PLANET, teksturaPlanety } from './scena.js';
import { stanUkladu } from './astro.js';

const THREE_URL = 'https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js';
const RAD = Math.PI / 180;
const KLATKA_MS = 33;                     // ok. 30 klatek/s
const OKRES_OBROTU_S = { blisko: 360, srednio: 900, daleko: 2400 };   // jak w 2D (obrót tekstury pod okrętem)
const hash = (t) => { let x = 2166136261; for (const c of String(t)) x = Math.imul(x ^ c.charCodeAt(0), 16777619); return x >>> 0; };
const kierunek = (kat) => [Math.cos(kat * RAD), 0, -Math.sin(kat * RAD)];

export function utworzScene3d(plotno) {
  const s2d = utworzScene(plotno);
  const gl = document.createElement('canvas');
  gl.id = 'scena3d';
  gl.setAttribute('aria-hidden', 'true');
  plotno.before(gl);
  let T = null, r = null;                 // moduł Three.js i stan renderera
  let klatka = null, ostatnia = 0, dziala = false;

  import(THREE_URL).then((m) => { T = m; r = zbuduj(); }).catch(() => { gl.remove(); });

  // ---------- budowa sceny ----------
  function zbuduj() {
    const renderer = new T.WebGLRenderer({ canvas: gl, antialias: true, logarithmicDepthBuffer: true, preserveDrawingBuffer: true });
    renderer.setPixelRatio(Math.min(1.5, window.devicePixelRatio || 1));
    renderer.outputColorSpace = T.SRGBColorSpace;
    const scena = new T.Scene();
    scena.background = new T.Color('#020409');
    const kamera = new T.PerspectiveCamera(60, 1, 1e-6, 5e6);
    const otoczenie = new T.AmbientLight('#8aa0b8', 0.05);   // w doku jaśniej — lampy stoczni
    scena.add(otoczenie);
    const slonce = new T.DirectionalLight('#fff4e1', 2.4);
    scena.add(slonce, slonce.target);

    // Gwiazdy tła: punkty na dalekiej sferze, przesuwane razem z kamerą (nieskończenie daleko).
    const gwiazdy = new T.Group();
    {
      const n = 6000, poz = new Float32Array(n * 3), kol = new Float32Array(n * 3);
      let s = 7;
      const los = () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296);
      for (let i = 0; i < n; i++) {
        const u = los() * 2 - 1, a = los() * Math.PI * 2, rr = Math.sqrt(1 - u * u), d = 4e6;
        poz.set([rr * Math.cos(a) * d, u * d, rr * Math.sin(a) * d], i * 3);
        const j = 0.45 + los() * 0.55, ciepla = los();
        kol.set([j * (ciepla > 0.7 ? 1 : 0.85), j * 0.92, j * (ciepla < 0.3 ? 1 : 0.85)], i * 3);
      }
      const g = new T.BufferGeometry();
      g.setAttribute('position', new T.BufferAttribute(poz, 3));
      g.setAttribute('color', new T.BufferAttribute(kol, 3));
      gwiazdy.add(new T.Points(g, new T.PointsMaterial({ size: 1.6, sizeAttenuation: false, vertexColors: true, depthWrite: false })));
    }
    scena.add(gwiazdy);

    // Słońce: tarcza i poświata jako sprite (rozmiar kątowy z promienia gwiazdy i odległości).
    const plotnoSlonca = document.createElement('canvas');
    plotnoSlonca.width = plotnoSlonca.height = 256;
    {
      const g = plotnoSlonca.getContext('2d');
      const gr = g.createRadialGradient(128, 128, 0, 128, 128, 128);
      gr.addColorStop(0, 'rgba(255,255,250,1)'); gr.addColorStop(0.12, 'rgba(255,248,230,1)'); gr.addColorStop(0.2, 'rgba(255,220,170,0.55)');
      gr.addColorStop(0.45, 'rgba(255,200,140,0.12)'); gr.addColorStop(1, 'rgba(255,200,140,0)');
      g.fillStyle = gr; g.fillRect(0, 0, 256, 256);
    }
    const tarczaSlonca = new T.Sprite(new T.SpriteMaterial({ map: new T.CanvasTexture(plotnoSlonca), blending: T.AdditiveBlending, depthWrite: false, transparent: true }));
    scena.add(tarczaSlonca);

    // Planeta: kula z tą samą teksturą typu planety co w 2D (pas 2:1 = mapa równoodległościowa), obrót, dzień i noc.
    const planeta = new T.Mesh(new T.SphereGeometry(1, 128, 96), new T.MeshStandardMaterial({ roughness: 0.92, metalness: 0 }));
    scena.add(planeta);
    // Atmosfera: poświata na krawędzi (fresnel), jaśniejsza od strony słońca.
    const atmosfera = new T.Mesh(new T.SphereGeometry(1.03, 96, 64), new T.ShaderMaterial({
      uniforms: { kolor: { value: new T.Color('#9be0ff') }, doSlonca: { value: new T.Vector3(1, 0, 0) } },
      vertexShader: `varying vec3 vN; varying vec3 vW; void main(){ vN = normalize(mat3(modelMatrix) * normal); vec4 w = modelMatrix * vec4(position,1.0); vW = w.xyz; gl_Position = projectionMatrix * viewMatrix * w; }`,
      fragmentShader: `uniform vec3 kolor; uniform vec3 doSlonca; varying vec3 vN; varying vec3 vW;
        void main(){ vec3 v = normalize(cameraPosition - vW); float f = pow(1.0 - max(dot(vN, v), 0.0), 3.0);
          float s = clamp(dot(vN, normalize(doSlonca)) * 0.8 + 0.35, 0.05, 1.0); gl_FragColor = vec4(kolor * f * s * 1.6, f * s); }`,
      transparent: true, blending: T.AdditiveBlending, depthWrite: false, side: T.FrontSide,
    }));
    scena.add(atmosfera);
    const ksiezyce = new T.Group();
    scena.add(ksiezyce);

    // Pierścień orbitalny (stocznie Kuat): płaska obręcz 120 km + ścianki 20 km grubości + światła doków.
    const pierscien = new T.Group();
    scena.add(pierscien);

    // Tunel doku: rama stoczniowa wokół okrętu (pokład, strop, ściany, żebra, światła), wylot na wprost.
    const tunel = budujTunel();
    scena.add(tunel);

    return { renderer, scena, kamera, otoczenie, slonce, gwiazdy, tarczaSlonca, planeta, atmosfera, ksiezyce, pierscien, tunel,
      typ: null, ksiezyceKlucz: '', pierscienKlucz: '' };
  }

  function teksturaPaneli(sw, sh, kolumny, wiersze, ziarno) {
    // Płyty konstrukcji z rzędami świateł (do ścian tunelu i modułów pierścienia).
    const c = document.createElement('canvas');
    c.width = sw; c.height = sh;
    const g = c.getContext('2d');
    g.fillStyle = '#7d858f'; g.fillRect(0, 0, sw, sh);
    let s = ziarno;
    const los = () => ((s = (s * 1664525 + 1013904223) >>> 0) / 4294967296);
    for (let i = 0; i < 120; i++) { g.fillStyle = `rgba(${90 + los() * 60},${95 + los() * 60},${102 + los() * 60},0.6)`; g.fillRect(los() * sw, los() * sh, 10 + los() * 60, 6 + los() * 30); }
    g.strokeStyle = 'rgba(15,17,20,0.9)'; g.lineWidth = 3;
    for (let x = 0; x <= kolumny; x++) { g.beginPath(); g.moveTo(x * sw / kolumny, 0); g.lineTo(x * sw / kolumny, sh); g.stroke(); }
    for (let y = 0; y <= wiersze; y++) { g.beginPath(); g.moveTo(0, y * sh / wiersze); g.lineTo(sw, y * sh / wiersze); g.stroke(); }
    const em = document.createElement('canvas');
    em.width = sw; em.height = sh;
    const e = em.getContext('2d');
    e.fillStyle = '#000'; e.fillRect(0, 0, sw, sh);
    for (let x = 0; x < kolumny; x++) for (let y = 0; y < wiersze; y++) {
      if (los() < 0.45) continue;
      e.fillStyle = los() < 0.35 ? '#ffd690' : '#a9d6ff';
      e.fillRect((x + 0.5) * sw / kolumny - 3, (y + 0.15) * sh / wiersze, 6, 3);
    }
    const tex = new T.CanvasTexture(c), texE = new T.CanvasTexture(em);
    for (const t of [tex, texE]) { t.wrapS = t.wrapT = T.RepeatWrapping; t.colorSpace = T.SRGBColorSpace; t.anisotropy = 4; }
    return [tex, texE];
  }

  function budujTunel() {
    // Jednostki lokalne w km (grupa skalowana ×0,001): długość 5 km przed okrętem, przekrój 1,6 × 1,0 km.
    const g = new T.Group();
    const wn = new T.Group();
    wn.scale.setScalar(0.001);
    g.add(wn);
    const [tex, texE] = teksturaPaneli(512, 512, 8, 8, 11);
    const plyta = (dl, sz, rep) => {
      const t1 = tex.clone(), t2 = texE.clone();
      t1.repeat.set(rep[0], rep[1]); t2.repeat.set(rep[0], rep[1]); t1.needsUpdate = t2.needsUpdate = true;
      return new T.Mesh(new T.PlaneGeometry(dl, sz), new T.MeshStandardMaterial({ map: t1, emissiveMap: t2, emissive: '#ffffff', emissiveIntensity: 1.1, roughness: 0.75, metalness: 0.4, side: T.DoubleSide }));
    };
    const L = 6, x0 = 2, Wd = 1.6, Hd = 1.0, dolY = -0.35;   // tunel od −1 km do +5 km wzdłuż kursu (oś X)
    const srodekX = x0;
    const poklad = plyta(L, Wd, [L * 2, Wd * 2]); poklad.rotation.x = -Math.PI / 2; poklad.position.set(srodekX, dolY, 0);
    const strop = plyta(L, Wd, [L * 2, Wd * 2]); strop.rotation.x = Math.PI / 2; strop.position.set(srodekX, dolY + Hd, 0);
    const lewa = plyta(L, Hd, [L * 2, Hd * 2]); lewa.position.set(srodekX, dolY + Hd / 2, -Wd / 2);
    const prawa = plyta(L, Hd, [L * 2, Hd * 2]); prawa.rotation.y = Math.PI; prawa.position.set(srodekX, dolY + Hd / 2, Wd / 2);
    wn.add(poklad, strop, lewa, prawa);
    // żebra poprzeczne co 250 m i lampy na nich
    const zebroMat = new T.MeshStandardMaterial({ color: '#2a2f35', roughness: 0.6, metalness: 0.7 });
    const lampaMat = new T.MeshBasicMaterial({ color: '#ffd28a' });
    for (let x = x0 - L / 2; x <= x0 + L / 2; x += 0.25) {
      const b = 0.04;
      for (const [sx, sy, sz, px, py, pz] of [[b, b, Wd, x, dolY + 0.02, 0], [b, b, Wd, x, dolY + Hd - 0.02, 0], [b, Hd, b, x, dolY + Hd / 2, -Wd / 2 + 0.02], [b, Hd, b, x, dolY + Hd / 2, Wd / 2 - 0.02]]) {
        const m = new T.Mesh(new T.BoxGeometry(sx, sy, sz), zebroMat); m.position.set(px, py, pz); wn.add(m);
      }
      for (const z of [-0.5, 0, 0.5]) { const l = new T.Mesh(new T.BoxGeometry(0.03, 0.012, 0.03), lampaMat); l.position.set(x, dolY + Hd - 0.05, z); wn.add(l); }
    }
    // obramowanie wylotu (światła ostrzegawcze)
    const wylot = new T.Mesh(new T.TorusGeometry(0.62, 0.012, 6, 4), new T.MeshBasicMaterial({ color: '#ffb46a' }));
    wylot.rotation.y = Math.PI / 2; wylot.rotation.z = Math.PI / 4; wylot.scale.set(1, 1.35, 0.8); wylot.position.set(x0 + L / 2, dolY + Hd / 2, 0);
    wn.add(wylot);
    g.visible = false;
    return g;
  }

  function odbudujPierscien(Rr) {
    const p = r.pierscien;
    p.clear();
    const POL = 0.06, GR = 0.02;                         // pół szerokości 60 km, grubość 20 km
    const [tex, texE] = teksturaPaneli(1024, 256, 32, 4, 5);
    tex.repeat.set(220, 1); texE.repeat.set(220, 1);
    const mat = new T.MeshStandardMaterial({ map: tex, emissiveMap: texE, emissive: '#ffffff', emissiveIntensity: 0.9, roughness: 0.6, metalness: 0.55, side: T.DoubleSide });
    // pokrywy (góra / dół) i ścianki (wewnętrzna / zewnętrzna)
    for (const y of [GR / 2, -GR / 2]) {
      const m = new T.Mesh(new T.RingGeometry(Rr - POL, Rr + POL, 2048, 1), mat);
      m.rotation.x = -Math.PI / 2; m.position.y = y; p.add(m);
    }
    for (const rr of [Rr - POL, Rr + POL]) p.add(new T.Mesh(new T.CylinderGeometry(rr, rr, GR, 2048, 1, true), mat));
    // światła doków: punkty wzdłuż pierścienia
    const n = 6000, poz = new Float32Array(n * 3), kol = new Float32Array(n * 3);
    for (let i = 0; i < n; i++) {
      const a = (i / n) * Math.PI * 2, rr = Rr + (((i * 37) % 7) - 3) / 3 * POL * 0.9, y = (i % 2 ? 1 : -1) * GR * 0.55;
      poz.set([Math.cos(a) * rr, y, -Math.sin(a) * rr], i * 3);
      const z = i % 3 === 0; kol.set(z ? [1, 0.83, 0.55] : [0.66, 0.84, 1], i * 3);
    }
    const g = new T.BufferGeometry();
    g.setAttribute('position', new T.BufferAttribute(poz, 3));
    g.setAttribute('color', new T.BufferAttribute(kol, 3));
    p.add(new T.Points(g, new T.PointsMaterial({ size: 2, sizeAttenuation: false, vertexColors: true, depthWrite: false })));
  }

  // ---------- klatka ----------
  function rysuj(d) {
    const { renderer, scena, kamera } = r;
    const W = gl.clientWidth || window.innerWidth, H = gl.clientHeight || window.innerHeight;
    const roz = renderer.getSize(new T.Vector2());
    if (roz.x !== W || roz.y !== H) renderer.setSize(W, H, false);
    const v = d.widok, k = d.kierunki, t = d.teraz;
    const Rp = d.srednica / 2000;
    // planeta: tekstura typu (raz na zmianę typu / miejsca), promień, obrót
    const typ = TYPY_PLANET[d.planeta] ? d.planeta : 'skalista';
    if (r.typ !== typ + '|' + d.miejsce) {
      r.typ = typ + '|' + d.miejsce;
      const tex = new T.CanvasTexture(teksturaPlanety(typ, 1024, hash((d.miejsce || '') + typ)));
      tex.colorSpace = T.SRGBColorSpace; tex.anisotropy = 8;
      r.planeta.material.map?.dispose(); r.planeta.material.map = tex; r.planeta.material.needsUpdate = true;
      r.atmosfera.material.uniforms.kolor.value.set(TYPY_PLANET[typ].atmosfera);
    }
    r.planeta.visible = r.atmosfera.visible = !d.bezPlanety;
    r.planeta.scale.setScalar(Rp);
    r.atmosfera.scale.setScalar(Rp);
    r.planeta.rotation.y = ((t / (OKRES_OBROTU_S[d.odleglosc] ?? 1800)) % 1) * Math.PI * 2;
    // słońce: kierunek z widoku (z planety), światło i tarcza
    const ds = kierunek(k.swiatloPlanety ?? k.slonce ?? 0);
    r.slonce.position.set(ds[0] * 1e5, ds[1] * 1e5, ds[2] * 1e5);
    r.atmosfera.material.uniforms.doSlonca.value.set(...ds);
    // kamera: miejsce okrętu (orbita, wysokość; w doku na pierścieniu), lekko ponad płaszczyzną równika
    const dO = (d.srednica / 2 + d.wysokosc) / 1000;
    const e = d.wDoku ? 0.0002 : Math.max(40, dO * 1000 * 0.1) / 1000;
    const kp = kierunek(k.orbita);
    kamera.position.set(kp[0] * dO, e, kp[2] * dO);
    const kk = kierunek(k.kurs);
    kamera.up.set(0, 1, 0);
    kamera.lookAt(kamera.position.x + kk[0], kamera.position.y, kamera.position.z + kk[2]);
    // pole widzenia: 100° na szerokość kadru (jak w 2D), horyzont w środku okna mostka
    const aspekt = W / H;
    kamera.fov = 2 * Math.atan(Math.tan((d.poleWidzenia / 2) * RAD) * H / Math.max(W, H)) / RAD;   // 100° na dłuższy bok
    kamera.aspect = aspekt;
    kamera.setViewOffset(W, H, 0, H / 2 - d.horyzontY, W, H);
    kamera.updateProjectionMatrix();
    r.gwiazdy.position.copy(kamera.position);
    // tarcza słońca: na kierunku do słońca od okrętu, rozmiar kątowy
    const gw = v.cykl?.gwiazdy?.[0];
    const kmSlonca = v.slonce?.km ?? (v.cykl?.promien_orbity_au || 1) * 149597870.7;
    const katR = Math.atan2((gw?.promien_km || 696000), kmSlonca);
    const dSpr = 1e5;
    r.tarczaSlonca.position.set(kamera.position.x + ds[0] * dSpr, kamera.position.y + ds[1] * dSpr, kamera.position.z + ds[2] * dSpr);
    r.tarczaSlonca.scale.setScalar(Math.max(0.004, Math.tan(katR) * 9) * dSpr);
    if (gw?.rgb) r.tarczaSlonca.material.color.setRGB(gw.rgb[0] / 255, gw.rgb[1] / 255, gw.rgb[2] / 255);
    // księżyce (ten sam cykl co mapa)
    const ks = v.cykl?.ksiezyce || [];
    const klucz = (d.miejsce || '') + ks.length;
    if (r.ksiezyceKlucz !== klucz) {
      r.ksiezyceKlucz = klucz; r.ksiezyce.clear();
      for (const m of ks) {
        const mesh = new T.Mesh(new T.SphereGeometry(1, 48, 32), new T.MeshStandardMaterial({ color: '#a7b0ba', roughness: 0.95 }));
        mesh.scale.setScalar((Number(m.srednica_km) || 3000) / 2000);
        r.ksiezyce.add(mesh);
      }
    }
    stanUkladu(v.cykl || { faza_orbity: 0, rok_dni: 365, epoka: 0, ksiezyce: [] }, t).ksiezyce.forEach((m, i) => {
      const mesh = r.ksiezyce.children[i];
      if (!mesh) return;
      const kd = kierunek(m.kat), rk = (Number(m.promien_km) || 400000) / 1000;
      mesh.position.set(kd[0] * rk, 0, kd[2] * rk);
    });
    // pierścień orbitalny
    const pr = v.pierscien;
    const kluczP = pr ? `${d.miejsce}|${pr.wysokosc_km}|${d.srednica}` : '';
    if (r.pierscienKlucz !== kluczP) { r.pierscienKlucz = kluczP; if (pr) odbudujPierscien((d.srednica / 2 + Number(pr.wysokosc_km || 1000)) / 1000); else r.pierscien.clear(); }
    // tunel doku: przy okręcie, wzdłuż kursu
    r.tunel.visible = d.wDoku;
    r.otoczenie.intensity = d.wDoku ? 0.9 : 0.05;
    if (d.wDoku) { r.tunel.position.copy(kamera.position); r.tunel.rotation.set(0, k.kurs * RAD, 0); }
    renderer.render(scena, kamera);
  }

  function petla(ts) {
    klatka = requestAnimationFrame(petla);
    if (ts - ostatnia < KLATKA_MS) return;
    ostatnia = ts;
    const d = s2d.widok3d();
    const ok = !!(r && d.scena === 'orbita' && d.widok && d.kierunki && d.okno !== 'brak');
    s2d.ustaw3d(ok);
    gl.style.visibility = ok ? 'visible' : 'hidden';
    if (ok) { try { rysuj(d); } catch (err) { console.warn('scena 3D:', err); s2d.ustaw3d(false); gl.style.visibility = 'hidden'; r = null; } }
  }

  return {
    ...s2d,
    // Zdjęcie: świat 3D pod spodem + rama okna z płótna 2D.
    zdjecie() {
      if (gl.style.visibility !== 'visible') return s2d.zdjecie();
      const szer = Math.min(480, plotno.width);
      const c = document.createElement('canvas');
      c.width = szer; c.height = Math.round(plotno.height * szer / plotno.width);
      const g = c.getContext('2d');
      g.drawImage(gl, 0, 0, c.width, c.height);
      g.drawImage(plotno, 0, 0, c.width, c.height);
      return c.toDataURL('image/jpeg', 0.72);
    },
    start() { s2d.start(); dziala = true; if (!klatka) klatka = requestAnimationFrame(petla); },
    stop() { s2d.stop(); dziala = false; if (klatka) cancelAnimationFrame(klatka); klatka = null; },
    get aktywna3d() { return dziala && gl.style.visibility === 'visible'; },
  };
}

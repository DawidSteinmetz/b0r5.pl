"""Typy maszyn (myśliwce i promy) — W16. Wszystko [?] propozycja do strojenia w symulacjach.

Hz bazowe dobrane tak, by rola maszyny wynikała z filtra osłon (MECHANIKA 3.2) i dryfu Hz klucza
(Hz = Hz_bazowe · (1 + k · moc), k = 0,25, as 1/3):
- przeciw domyślnym osłonom ISD 1800 Hz: X-wing (1200 → maks. 1500, as 1600) nie przebija — musi wymusić niższe Hz
  albo celować w klucze; Y-wing (1600 → 2000) przebija o 10%; B-wing (2400 → 3000, as 3200) przebija o 40%
  i zmusza ISD do 3600 Hz (×8 poboru, ×27 zużycia);
- TIE nie mają osłon (oslona_hz 0) — każdy strzał w maszynę to trafienie kadłuba.
Energia strzału: rząd działa jonowego NK-7 (10¹⁸ J); bombowce więcej. Przekrój A — czołowy, do wzoru celności 4.3.
"""

# kod, nazwa, strona, rola, hz_bazowe, oslona_hz, predkosc_km_s, przekroj_m2, energia_strzalu_j, ladowanie_tiki, opis
MASZYNY = [
    ('TIE_LN', 'TIE/ln', 'Imperium', 'myśliwiec liniowy', 600, 0, 1.05, 40, 6e17, 1, 'tani, liczny, bez osłon'),
    ('TIE_IN', 'TIE/in Interceptor', 'Imperium', 'myśliwiec przechwytujący', 900, 0, 1.25, 35, 8e17, 1, 'najszybszy TIE, bez osłon'),
    ('TIE_SA', 'TIE/sa Bomber', 'Imperium', 'bombowiec', 1500, 0, 0.80, 45, 3e18, 3, 'bomby przeciw okrętom'),
    ('LAMBDA', 'Lambda T-4a', 'Imperium', 'prom', 300, 600, 0.85, 120, 2e17, 2, 'prom pokładowy, słabe uzbrojenie'),
    ('X_WING', 'T-65 X-wing', 'Rebelia', 'myśliwiec uniwersalny', 1200, 900, 1.00, 30, 1e18, 1, 'osłony, torpedy protonowe'),
    ('Y_WING', 'BTL Y-wing', 'Rebelia', 'myśliwiec-bombowiec', 1600, 1200, 0.80, 25, 1.5e18, 2, 'działko jonowe, torpedy'),
    ('A_WING', 'RZ-1 A-wing', 'Rebelia', 'myśliwiec przechwytujący', 800, 600, 1.30, 12, 6e17, 1, 'najszybszy, mały przekrój'),
    ('B_WING', 'A/SF-01 B-wing', 'Rebelia', 'ciężki myśliwiec szturmowy', 2400, 1500, 0.85, 35, 3e18, 3, 'przebija osłony 1800 Hz'),
]

# Walka kluczy (kinematyka i broń) — [R] do strojenia: zwrot (°/s, as ×1,25), zasięg ognia do maszyn (km), stożek ognia
# (± ° od dziobu maszyny), rozrzut θ (mrad) do wzoru celności 4.3: p = 1 − exp(−A / (2π σ²)), σ = R · θ_eff.
# kod: (zwrot_deg_s, zasieg_ognia_km, stozek_deg, theta_mrad)
WALKA = {
    'TIE_LN': (30, 1.5, 15, 4.0),
    'TIE_IN': (40, 1.5, 15, 3.5),
    'TIE_SA': (18, 1.2, 10, 5.0),
    'LAMBDA': (10, 1.0, 20, 6.0),
    'X_WING': (28, 1.6, 15, 3.5),
    'Y_WING': (18, 1.4, 15, 4.5),
    'A_WING': (45, 1.5, 15, 4.0),
    'B_WING': (20, 1.6, 15, 4.0),
}

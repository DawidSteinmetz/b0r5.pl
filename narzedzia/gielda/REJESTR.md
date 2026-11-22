# Rejestr giełdy CDEX — stan łańcucha migracji

Każdy krok łańcucha (prompt `PROMPT_GIELDA.md`) czyta ten plik, dopisuje swoje notowania i towary i oddaje
**cały plik zaktualizowany**. Źródłem prawdy jest baza; ten plik to jej skrót dla instancji bez dostępu do bazy.

Ostatnia aktualizacja: 22.11.2026, migracja bazowa (`gielda_00_baza` — poprawiona `gielda_migracja.sql`).

## Kroki łańcucha

| Krok | Plik | Giełda | Co zrobiono |
|---|---|---|---|
| 00 | `gielda_00_baza.sql` | wszystkie | Schemat, 6 giełd, 41 notowań z JSON, 12 notowań Kuat, historia, widoki. Ceny Kuat z `gosp_rynek`. |
| 01 | `gielda_01_coruscant.sql` | cor | **do zrobienia** |

## Giełdy (`gielde`)

| kod | nazwa | planeta (`mapa_planety.nazwa`) |
|---|---|---|
| cor | Galaktyczna Giełda Coruscant | Coruscant |
| crl | Giełda Coronet | Corellia |
| kua | Giełda Stoczniowa Kuat | Kuat |
| bsp | Giełda Tibanny w Mieście w Chmurach | Bespin |
| nar | Rynek Huttów na Nar Shaddaa | Nar Shaddaa |
| hune | Giełda Hune — B0R5 Holding | Zephyria |

Nowa giełda: kod 3–4 małe litery, unikalny; `planeta_id` zawsze przez `(SELECT id FROM mapa_planety WHERE nazwa=…)`.

## Notowania (`instrumenty`)

Cena = `cena_bazowa` (DECIMAL 18,4, za jednostkę notowania). „gosp” — kod towaru gospodarki (`gosp_towary.kod`),
z którego liczona jest cena; „—” — cena z dawnego JSON (do uzgodnienia ze skalą gospodarki, patrz niżej).

| symbol | nazwa | rodzaj | giełda | kategoria | jednostka | pochodzenie | cena | zmienność | gosp |
|---|---|---|---|---|---|---|---|---|---|
| KDY | Kuat Drive Yards | spolka | kua | stocznie | akcja | Kuat | 520 | 1 | |
| SFS | Sienar Fleet Systems | spolka | cor | stocznie | akcja | Lothal | 310 | 1 | |
| CEC | Corellian Engineering Corporation | spolka | crl | stocznie | akcja | Corellia | 280 | 1 | |
| INC | Incom Corporation | spolka | cor | stocznie | akcja | Fresia | 240 | 1 | |
| NDC | Nubian Design Collective | spolka | cor | stocznie | akcja | Naboo | 150 | 1 | |
| RSD | Rendili StarDrive | spolka | kua | stocznie | akcja | Rendili | 190 | 1 | |
| BLT | BlasTech Industries | spolka | cor | uzbrojenie | akcja | Coruscant | 175 | 1 | |
| MSM | Merr-Sonn Munitions | spolka | cor | uzbrojenie | akcja | Carida | 140 | 1 | |
| CZK | Czerka Corporation | spolka | cor | uzbrojenie | akcja | Coruscant | 95 | 1.333 | |
| CYB | Cybot Galactica | spolka | cor | droidy | akcja | Affa | 160 | 1 | |
| IAU | Industrial Automaton | spolka | cor | droidy | akcja | Coruscant | 130 | 1 | |
| SRS | SoroSuub Corporation | spolka | cor | elektronika | akcja | Sullust | 150 | 1 | |
| LOR | Loronar Corporation | spolka | crl | elektronika | akcja | Loronar | 90 | 1 | |
| MGU | Gildia Górnicza | spolka | cor | górnictwo | akcja | Coruscant | 85 | 1 | |
| BES | Bespin Motors i Tibanna | spolka | bsp | górnictwo | akcja | Bespin | 210 | 1 | |
| XTS | Xizor Transport Systems | spolka | cor | transport | akcja | Coruscant | 120 | 1 | |
| HCL | Kartel Huttów — Logistyka | spolka | nar | transport | akcja | Nal Hutta | 70 | 1.667 | |
| IBC | Międzygalaktyczny Klan Bankowy | spolka | cor | finanse | akcja | Muunilinst | 400 | 0.667 | |
| GIZ | Gizer Żywność i Napoje | spolka | crl | żywność | akcja | Gizer | 60 | 1 | |
| COAX | Coaxium surowe | towar | nar | paliwo | kg | Kessel | 1200 | 1.6 | — |
| COAXR | Coaxium rafinowane | towar | cor | paliwo | kg | Kessel | 2600 | 1.2 | — |
| TIBP | Gaz tibanna przemysłowy | towar | bsp | paliwo | zbiornik | Bespin | 1260 | 1 | tibanna (Bespin, eksporter 0,7) |
| TIBW | Gaz tibanna wysokiej czystości | towar | bsp | paliwo | zbiornik | Bespin | 2668.24 | 1 | tibanna × 2,12 (czystość) |
| PLZ | Plazma z Naboo | towar | cor | paliwo | ogniwo | Naboo | 480 | 1 | — |
| OGN | Ogniwa paliwowe | towar | crl | paliwo | skrzynia | Corellia | 60 | 1 | — |
| DUR | Durastal | towar | kua | surowce | t | Kuat | 526.05 | 1 | durastal (Kuat) |
| DOON | Doonium | towar | kua | surowce | t | Kuat | 5402.5 | 1 | metale_rzadkie (Kuat) |
| BRZ | Bronzium | towar | crl | surowce | t | Corellia | 180 | 1 | — |
| ZBO | Zboże | towar | cor | żywność | t | Agamar | 20 | 1 | — |
| NRF | Mięso nerfów | towar | cor | żywność | t | Naboo | 45 | 1 | — |
| BCT | Bacta | towar | cor | medycyna | zbiornik | Thyferra | 900 | 1.2 | — |
| KOL | Kolto | towar | cor | medycyna | zbiornik | Manaan | 650 | 1 | — |
| HIP | Części hipernapędu | towar | crl | technika | kontener | Corellia | 950 | 1 | — |
| MDR | Moduły droidów | towar | cor | technika | skrzynia | Affa | 300 | 1 | — |
| BLS | Blastery bez numerów | towar | nar | broń | skrzynia | Nar Shaddaa | 500 | 1.4 | — |
| WIN | Wino koreliańskie | towar | crl | luksus | skrzynia | Corellia | 220 | 1 | — |
| JED | Jedwab hapański | towar | cor | luksus | bela | Hapes | 380 | 1 | — |
| GLT | Glitterstim | towar | nar | przyprawy | kg | Kessel | 1500 | 2 | — |
| RYL | Ryll | towar | nar | przyprawy | kg | Ryloth | 700 | 1.8 | — |
| CUK997 | Cukier 99,7% | towar | hune | żywność | zgrzewka 10 kg | Zephyria | 200000 | 1 | — |
| SOL097 | Sól 97% | towar | hune | żywność | worek 25 kg | Zephyria | 430000 | 1 | — |
| FEO | Ruda żelaza | towar | kua | surowce | t | — | 78.52 | 0.8 | ruda_zelaza |
| WEG | Węgiel | towar | kua | surowce | t | — | 56.04 | 0.8 | wegiel |
| LOM | Lommit | towar | kua | surowce | t | — | 511.28 | 1 | lommit |
| KAR | Karwanium | towar | kua | surowce | t | — | 2108.7 | 1.1 | karwanium |
| MEL | Meleenium | towar | kua | surowce | t | — | 3500 | 1.4 | meleenium |
| ZER | Zersium | towar | kua | surowce | t | — | 3916.8 | 1.4 | zersium |
| NEU | Neutronium | towar | kua | surowce | t | — | 11145 | 1.2 | neutronium |
| PRE | Paliwo reaktorowe | towar | kua | paliwo | t | — | 1057.2 | 0.9 | paliwo |
| ELK | Podzespoły elektroniczne | towar | kua | technika | t | — | 16740 | 1 | elektronika |
| ZYW | Żywność (zaopatrzenie) | towar | kua | żywność | t | — | 160.44 | 0.6 | zywnosc |
| LUX | Towary luksusowe | towar | kua | luksus | t | — | 42180 | 1.2 | luksus |
| TIBP.KUA | Gaz tibanna przemysłowy | towar | kua | paliwo | zbiornik | Bespin | 4491 | 1.2 | tibanna (Kuat) |

Kategorie notowań (wyświetlane w grze, z polskimi znakami): towary — paliwo, surowce, żywność, medycyna, technika,
broń, luksus, przyprawy; spółki — stocznie, uzbrojenie, droidy, elektronika, górnictwo, transport, finanse, żywność.

## Katalog towarów (`towary`) — powiązania z gospodarką i pułapki

`gosp_towary.towar_id` (unikalny) wskazuje towar katalogu. Ceny odniesienia `towary.cena_bazowa` = średnia
galaktyczna gospodarki (`gosp_towary.cena_bazowa_kr_t`) × `masa_t_na_jednostke`.

| gosp_towary.kod | towar w katalogu | cena odniesienia |
|---|---|---|
| ruda_zelaza | Ruda żelaza | 40 |
| wegiel | Węgiel | 30 |
| lommit | Lommit | 220 |
| karwanium | Karwanium | 900 |
| meleenium | Meleenium | 1400 |
| zersium | Zersium | 1600 |
| neutronium | Neutronium | 5000 |
| metale_rzadkie | Doonium | 2500 |
| tibanna | Gaz tibanna przemysłowy | 1800 |
| paliwo | Paliwo reaktorowe | 600 |
| elektronika | Podzespoły elektroniczne | 12000 |
| durastal | Duralstal w blokach | 350 |
| zywnosc | Żywność (zaopatrzenie) | 120 |
| luksus | Towary luksusowe | 30000 |

Kody gospodarki **bez towaru w katalogu** (dopisane w migracjach planet 051–060): czesci_maszyn, czesci_uzywane,
komponenty_stoczniowe, krysztal_nova, maszyny_rolnicze, nawozy, podzespoly_tie, surowiec_paliwowy, zlom_przemyslowy —
przy pierwszym notowaniu dodaj towar i ustaw `gosp_towary.towar_id`.

Pułapki (towary, które już są w katalogu pod inną nazwą — nie twórz duplikatów, używaj istniejących):
- **Duralstal w blokach** — to jest durastal (nie dodawaj „Durastal”).
- **Koaksium stabilizowane** (12 000 kr/kg, kontrabanda) obok **Coaxium surowe / rafinowane** — nierozstrzygnięte: to
  albo trzeci stopień przetworzenia, albo duplikat. Nie dodawaj czwartego.
- **Cukier z Zephyrii** (200 000 kr/kg) i **Cukier 99,7%** (200 000 kr za zgrzewkę 10 kg) — ceny różnią się 10×;
  nierozstrzygnięte (pytanie do MG).
- **Glitterstim** — towar w katalogu 800 000 kr/kg (ceny Sektora Hune), notowanie GLT 1500 kr/kg (Nar Shaddaa).
- **Bacta** — towar w litrach (120 kr/l), notowanie BCT w zbiornikach (900).
- **Ruda żelaza z Teryphos** i podobne „… z <planeta>” (cena 0) — towary fabularne Nowej Rebelii, nie łącz z giełdą.

## Do uzgodnienia (ceny z dawnego JSON, skala inna niż gospodarka)

Notowania z „—” w kolumnie gosp mają ceny z JSON, często wielokrotnie niższe niż skala gospodarki (np. ZBO 20 kr/t
przy średniej żywności 120 kr/t). Krok danej giełdy uzgadnia **swoje** notowania: przelicza cenę i historię tym samym
mnożnikiem (wzór w prompcie), zapisuje tu nową cenę i usuwa wiersz z tej listy.

- cor: COAXR, PLZ, ZBO, NRF, BCT, KOL, MDR, JED
- crl: OGN, BRZ, HIP, WIN
- nar: COAX, BLS, GLT, RYL
- hune: CUK997, SOL097 (najpierw rozstrzygnięcie ceny cukru — pytanie do MG)

## Otwarte wątki

- Kuat: kod `czesci_maszyn` (produkcja 90 000 t/d, eksporter) bez notowania na kua.
- Spółki notowane mają ceny z JSON — gospodarka ich nie wycenia; zmieniaj tylko przy uzasadnieniu (kanon, skala firmy).
- Portfele graczy i transakcje: nigdy nie ruszaj (`portfele_gieldowe`, `transakcje_gieldowe`).

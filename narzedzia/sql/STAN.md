# Stan migracji JSON → SQL

Plik wynikowy: `node narzedzia/sql/stan.js`. Nie edytuj ręcznie.

- Dokumenty JSON w `datapad_dane` nadal używane przez PHP: **19**
- Tabele w `api/schema.sql`: **201**, z czego używane przez PHP: **31**

Moduł jest przeniesiony, gdy jego dokument znika z pierwszej tabeli, a kod czyta i zapisuje
tabele SQL. Tabele „fundament” są celowo gotowe na przyszłość i czekają na moduły.

## Dokumenty JSON do przeniesienia

| Dokument | odczyty | zmiany | pliki PHP |
|---|---:|---:|---|
| `firmy` | 19 | 13 | agencja.php, bank.php, czas.php, firmy.php, handel.php, karty.php, komorka.php, ruch.php, sceny.php |
| `karty` | 11 | 19 | czas.php, karty.php, ruch.php |
| `bank` | 5 | 21 | agencja.php, bank.php, firmy.php, gielda.php, handel.php, karty.php, ruch.php |
| `katalog` | 5 | 5 | firmy.php, gielda.php, ruch.php |
| `kanaly` | 5 | 5 | kanaly.php, wydarzenia.php |
| `sceny` | 6 | 4 | karty.php, ruch.php, sceny.php |
| `lacznosc` | 3 | 5 | ruch.php |
| `dokumenty` | 5 | 2 | index.php, kanaly.php |
| `skrzynki` | 3 | 3 | index.php, kanaly.php |
| `kandydaci` | 2 | 3 | firmy.php |
| `gielda_portfele` | 4 | 1 | gielda.php |
| `gielda` | 1 | 3 | firmy.php, gielda.php |
| `gielda_notowania` | 1 | 3 | gielda.php |
| `uzytkownicy` | 1 | 3 | index.php |
| `holonet` | 1 | 3 | index.php, wydarzenia.php |
| `frakcje` | 2 | 2 | index.php |
| `instalacje` | 2 | 2 | kanaly.php |
| `wydarzenia` | 1 | 3 | wydarzenia.php |
| `harmonogram` | 1 | 2 | index.php |

## Tabele SQL według obszaru

| Obszar | używane | fundament (jeszcze bez kodu) |
|---|---|---|
| budynki | `budynki` | `budynki_hangar` |
| ciagniki | — | `ciagniki_statki`, `ciagniki_emitery` |
| datapad | `datapad_dane`, `datapad_pozycje_legacy` | — |
| dialogi | — | `dialogi_wpisy` |
| dochody | — | `dochody_stale` |
| dokumenty | — | `dokumenty`, `dokumenty_slowniki` |
| dziennik | — | `dziennik_zdarzen` |
| ekwipunek | — | `ekwipunek_postaci` |
| eote | — | `eote_umiejetnosci`, `eote_postacie_umiejetnosci`, `eote_talenty`, `eote_kariery`, `eote_specjalizacje`, `eote_postacie_specjalizacje`, `eote_talenty_wymagania`, `eote_postacie_talenty`, `eote_obrazenia_historia` |
| firmy | — | `firmy`, `firmy_czlonkowie`, `firmy_bilans` |
| frakcje | — | `frakcje`, `frakcje_dowodzenie` |
| gielde | — | `gielde` |
| gotowosc | — | `gotowosc_zalogi` |
| grafiki | — | `grafiki_zmian_zalogi`, `grafiki_zmian_osoby` |
| hangary | — | `hangary_jednostek` |
| harmonogramy | — | `harmonogramy_posilkow` |
| historia | — | `historia_skokow`, `historia_wlasnosci`, `historia_lokalizacji`, `historia_zmian` |
| holonet | — | `holonet_komunikaty` |
| inspekcje | — | `inspekcje`, `inspekcje_znaleziska` |
| instalacje | — | `instalacje_frakcji`, `instalacje_zasoby` |
| instrumenty | — | `instrumenty` |
| instytucje | — | `instytucje` |
| jednostki | — | `jednostki_floty`, `jednostki_linie_zasilania` |
| kalendarz | — | `kalendarz_sesji` |
| kalendarze | — | `kalendarze_planetarne` |
| kanaly | — | `kanaly`, `kanaly_wiadomosci` |
| karty | — | `karty_eote` |
| kategorie | — | `kategorie_towarow` |
| komponenty | — | `komponenty_modele`, `komponenty_egzemplarze`, `komponenty_montaze` |
| komputery | — | `komputery_nawigacyjne`, `komputery_dane_nawigacyjne` |
| konta | — | `konta` |
| kontakty | — | `kontakty_czujnikow`, `kontakty_czujnikow_historia` |
| kredyty | — | `kredyty` |
| ksiegowania | — | `ksiegowania`, `ksiegowania_pozycje` |
| kursy | — | `kursy_dzienne`, `kursy_walut` |
| lacznosc | — | `lacznosc_watki`, `lacznosc_wiadomosci` |
| linie | — | `linie_zasilania_montaze` |
| lokalizacje | `lokalizacje` | — |
| magazyn | — | `magazyn_partie` |
| magazyny | — | `magazyny` |
| mapa | `mapa_planety`, `mapa_szlaki`, `mapa_obiekty`, `mapa_obiekty_dostep`, `mapa_floty`, `mapa_floty_jednostki`, `mapa_naped_jednostki`, `mapa_rozkazy_skokow`, `mapa_rozkazy_skokow_jednostki`, `mapa_teleporty_mg`, `mapa_bitwy`, `mapa_bitwy_uczestnicy`, `mapa_wykrycia_skokow` | — |
| misje | — | `misje`, `misje_jednostki`, `misje_osoby`, `misje_zmiany_magazynow` |
| obiegi | — | `obiegi_automatow` |
| odbiorcy | — | `odbiorcy_stali` |
| operacje | — | `operacje_przechwycenia` |
| oslony | — | `oslony_sektory`, `oslony_sektory_stan`, `oslony_generatory_sektory` |
| osoby | `osoby` | `osoby_statystyki`, `osoby_profil_dialogowy`, `osoby_relacje`, `osoby_pamiec` |
| pakiety | — | `pakiety_wiedzy`, `pakiety_zawartosc` |
| parametry | — | `parametry_czestotliwosci_modeli` |
| planety | `planety_gry`, `planety_wladza`, `planety_widocznosc_konta`, `planety_cykle`, `planety_ksiezyce` | `planety_widocznosc`, `planety_orbity`, `planety_specyfika`, `planety_kategorie_ceny` |
| plany | — | `plany_lotu`, `plany_lotu_przystanki`, `plany_lotu_operacje` |
| podmioty | `podmioty` | — |
| podroze | — | `podroze` |
| pojazdy | — | `pojazdy` |
| pomieszczenia | — | `pomieszczenia_jednostek` |
| portfele | — | `portfele`, `portfele_gieldowe`, `portfele_salda` |
| posiadane | — | `posiadane_pakiety` |
| posilki | — | `posilki_zalogi` |
| postacie | `postacie` | `postacie_statystyki`, `postacie_reputacja`, `postacie_kontakty`, `postacie_tozsamosci`, `postacie_notatnik`, `postacie_pozycja` |
| proby | — | `proby_logowania` |
| przelewy | — | `przelewy` |
| przydzialy | — | `przydzialy_zalogi`, `przydzialy_hangaru`, `przydzialy_kwater` |
| rachunki | — | `rachunki`, `rachunki_waluty` |
| raporty | — | `raporty_wywiadowcze`, `raporty_wiarygodnosc_historia` |
| regiony | — | `regiony` |
| resynchronizacje | `resynchronizacje` | — |
| rozkazy | — | `rozkazy_mostka`, `rozkazy_mostka_zmiany` |
| sceny | — | `sceny_postaci` |
| schema | — | `schema_migracje` |
| sesje | — | `sesje_dialogowe`, `sesje_symulacji` |
| sklepy | — | `sklepy_oferty` |
| skrzynki | — | `skrzynki_foldery`, `skrzynki_pliki` |
| slowniki | — | `slowniki`, `slowniki_wpisy` |
| stan | — | `stan_hipernapedow` |
| strefy | — | `strefy_niebezpieczne` |
| symulacje | — | `symulacje_jednostki`, `symulacje_zaloga`, `symulacje_stany_systemow`, `symulacje_zdarzenia`, `symulacje_wyniki` |
| systemy | — | `systemy_nawigacyjne`, `systemy_okretu`, `systemy_czujnikow` |
| teczki | — | `teczki`, `teczki_meldunki` |
| testy | — | `testy_statystyk`, `testy_statystyk_wyniki` |
| towary | — | `towary`, `towary_kategorie` |
| transakcje | — | `transakcje_gieldowe` |
| transmisje | — | `transmisje` |
| trasy | — | `trasy_skokow`, `trasy_skokow_kroki` |
| typy | — | `typy_kadlubow`, `typy_kadlubow_sloty`, `typy_hipernapedow`, `typy_turbolaserow`, `typy_oslon`, `typy_reaktorow`, `typy_kwater_zalogi`, `typy_hangarow` |
| uklady | — | `uklady_planetarne` |
| uprawnienia | — | `uprawnienia_zawartosci` |
| ustawienia | `ustawienia_kont` | `ustawienia` |
| waluty | — | `waluty`, `waluty_aliasy` |
| wskazniki | `wskazniki_zgody` | — |
| wtyczki | — | `wtyczki_postaci` |
| wyczyny | `wyczyny_progi` | — |
| wydarzenia | — | `wydarzenia`, `wydarzenia_skutki_cen` |
| wykrycia | — | `wykrycia_nadprzestrzenne` |
| wymiany | — | `wymiany_walut` |
| wyplaty | — | `wyplaty` |
| zakazy | — | `zakazy_skokow` |
| zatrudnienie | — | `zatrudnienie` |
| zdjecia | `zdjecia_gwiazd` | — |
| zegar | — | `zegar_swiata` |
| znane | `znane_lokacje` | `znane_planety` |
| zuzycie | — | `zuzycie_zasobow`, `zuzycie_zasobow_pozycje` |

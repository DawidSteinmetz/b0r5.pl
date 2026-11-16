<?php
// Handel: ceny rynkowe na planetach, symulacja i wykonanie kursów, automatyczne kursy.
//
// CENY. Towar ma cenę bazową (katalog). Każda planeta ma „specyfikę”: mnożniki dla
// konkretnych towarów albo całych kategorii (np. Kessel: surowce ×0,6 — tanio, bo się
// je tam wydobywa; żywność ×1,5 — drogo, bo trzeba ją przywozić). Do tego codzienne
// wahanie kursu (±8%, takie samo dla wszystkich tego dnia) i różnica kupno/sprzedaż (±5%).
// Aktywne wydarzenia (wydarzenia.php) mnożą cenę i mogą zwiększyć wahania.
// Wszystkie ceny w pełnych kredytach. Z tych samych funkcji skorzysta giełda.
//
// KAPITAN. Ukryta cecha „handel” kapitana statku zmienia ceny: przy 10 kupuje o 10% taniej
// i sprzedaje o 10% drożej, przy 1 — odwrotnie o 8%. Gracze widzą wycenę po kursie
// giełdowym, a faktyczny wynik kursu zdradza (w przybliżeniu), ile kapitan jest wart.
//
// AUTOMAT. Kurs „automatyczny” musi wracać na planetę startową. Po przyjęciu przez kontrolę
// ruchu (MG) powtarza się co N dni. Każdy obieg liczony jest po cenach z dnia obiegu,
// rozliczany na rachunku firmy i zapisywany w dzienniku kursu. Brak środków, towaru
// albo miejsca wstrzymuje automat. Bez crona: zaległe obiegi liczą się przy wejściu do firm.

declare(strict_types=1);

const HANDEL_SPREAD = 0.05;
const HANDEL_WAHANIA = 0.08;
const HANDEL_MAX_OBIEGOW = 12;
const HANDEL_DZIENNIK = 30;

// ---------- ceny ----------

function handel_mnoznik(array $planeta, array $towar): float
{
    $sp = $planeta['specyfika'] ?? [];
    return (float) ($sp['t:' . $towar['id']] ?? $sp['k:' . mb_strtolower($towar['kategoria'] ?? '')] ?? 1.0);
}

function handel_cena(array $towar, array $planeta, string $dzien): array
{
    $los = (crc32($towar['id'] . '|' . $planeta['id'] . '|' . $dzien) % 1601 - 800) / 800 * HANDEL_WAHANIA;
    // Wydarzenia (blokady, kryzysy, odkrycia złóż) przesuwają ceny tak samo jak na giełdzie.
    $wyd = wydarzenia_wplyw(['planety' => [$planeta['nazwa'] ?? ''], 'rodzaj' => 'towar', 'kategoria' => $towar['kategoria'] ?? '', 'cele' => [$towar['nazwa'] ?? '']], $dzien);
    $srodek = max(1, (int) ($towar['cena'] ?? 1)) * handel_mnoznik($planeta, $towar) * $wyd['mnoznik'] * (1 + $los * $wyd['niepewnosc']);
    return [
        'kupno' => max(1, (int) round($srodek * (1 + HANDEL_SPREAD))),
        'sprzedaz' => max(1, (int) round($srodek * (1 - HANDEL_SPREAD))),
    ];
}

// Tabela cen dnia: [id planety][id towaru] => { kupno, sprzedaz }.
function handel_ceny_dnia(array $katalog, string $dzien): array
{
    $t = [];
    foreach ($katalog['planety'] as $p) {
        foreach ($katalog['towary'] as $tw) {
            $t[$p['id']][$tw['id']] = handel_cena($tw, $p, $dzien);
        }
    }
    return $t;
}

// Umiejętność handlowa kapitana statku (1–10). Bez kapitana handluje załoga „na oko” (3).
function handel_umiejetnosc(array $dane, string $statek): int
{
    foreach ($dane['flota'] as $s) {
        if ($s['id'] !== $statek) continue;
        foreach ($dane['zaloga'] as $z) {
            if ($z['id'] === ($s['kapitan'] ?? '')) return (int) ($z['statystyki']['handel'] ?? 5);
        }
    }
    return 3;
}

// ---------- symulacja kursu ----------
//
// Jedna funkcja do sprawdzania planu, wyceny i wykonania. Na każdym przystanku najpierw
// rozładunek i sprzedaż, potem załadunek i zakup. $umiejetnosc = null: wycena po kursie
// giełdowym (bez wpływu kapitana).

function handel_symuluj(array $szlak, array $dane, array $katalog, string $dzien, ?int $umiejetnosc = null, bool $w_trasie = false): array
{
    $problemy = [];
    $pozycje = [];
    $koszt = 0;
    $przychod = 0;
    $saldo = 0;
    $najnizsze = 0;
    $towary = array_column($katalog['towary'], null, 'id');
    $planety = array_column($katalog['planety'], null, 'id');
    $statki = array_column($dane['flota'], null, 'id');
    $marza = $umiejetnosc === null ? 0.0 : ($umiejetnosc - 5) * 0.02;

    $magazyn = [];
    foreach ($dane['magazyn'] as $m) {
        $magazyn[$m['planeta'] . '|' . $m['towar']] = (int) $m['ilosc'];
    }
    // Zwykłe domknięcie z referencjami: wynik ma pokazać stan z chwili zwrotu, nie z chwili utworzenia.
    $wynik = function () use (&$problemy, &$pozycje, &$koszt, &$przychod, &$najnizsze, &$magazyn) {
        return compact('problemy', 'pozycje', 'koszt', 'przychod', 'najnizsze', 'magazyn') + ['zysk' => $przychod - $koszt];
    };

    $statek = $statki[$szlak['statek'] ?? ''] ?? null;
    if (!$statek) {
        $problemy[] = 'Nie wybrano statku.';
        return $wynik();
    }
    if (!$w_trasie && ($statek['stan'] ?? 'sprawny') !== 'sprawny') {
        $problemy[] = "Statek {$statek['nazwa']} nie jest sprawny ({$statek['stan']}).";
    }
    $przystanki = $szlak['przystanki'] ?? [];
    if (count($przystanki) < 2) {
        $problemy[] = 'Szlak potrzebuje co najmniej dwóch przystanków.';
    }
    if ($przystanki && ($statek['lokalizacja'] ?? '') !== '' && $przystanki[0]['planeta'] !== $statek['lokalizacja']) {
        $problemy[] = 'Statek stoi na: ' . ($planety[$statek['lokalizacja']]['nazwa'] ?? '?') . '. Pierwszy przystanek musi być tam.';
    }
    if (!empty($szlak['auto']) && $przystanki && end($przystanki)['planeta'] !== $przystanki[0]['planeta']) {
        $problemy[] = 'Kurs automatyczny musi wracać na planetę startową (ostatni przystanek = pierwszy).';
    }

    $ladownia = [];
    $ladownosc = (int) ($statek['ladownosc'] ?? 0);
    $kolejnosc = ['rozladunek' => 0, 'sprzedaj' => 0, 'zaladunek' => 1, 'kup' => 1];

    foreach ($przystanki as $i => $p) {
        $nr = $i + 1;
        $pl = $planety[$p['planeta'] ?? ''] ?? null;
        if (!$pl) {
            $problemy[] = "Przystanek $nr: nieznana planeta.";
            continue;
        }
        $operacje = $p['operacje'] ?? [];
        usort($operacje, fn($a, $b) => ($kolejnosc[$a['typ']] ?? 1) <=> ($kolejnosc[$b['typ']] ?? 1));
        foreach ($operacje as $o) {
            $t = $towary[$o['towar'] ?? ''] ?? null;
            $ilosc = (int) ($o['ilosc'] ?? 0);
            if (!$t || $ilosc <= 0) {
                $problemy[] = "Przystanek $nr ({$pl['nazwa']}): nieprawidłowa operacja.";
                continue;
            }
            $klucz = $pl['id'] . '|' . $t['id'];
            $naPokladzie = $ladownia[$t['id']] ?? 0;
            switch ($o['typ']) {
                case 'rozladunek':
                case 'sprzedaj':
                    if ($ilosc > $naPokladzie) {
                        $problemy[] = "Przystanek $nr ({$pl['nazwa']}): " . ($o['typ'] === 'sprzedaj' ? 'sprzedaż' : 'rozładunek') . " $ilosc {$t['jednostka']} {$t['nazwa']}, a na pokładzie jest $naPokladzie.";
                        $ilosc = $naPokladzie;
                    }
                    $ladownia[$t['id']] = $naPokladzie - $ilosc;
                    if ($o['typ'] === 'rozladunek') {
                        $magazyn[$klucz] = ($magazyn[$klucz] ?? 0) + $ilosc;
                    } else {
                        $cena = (int) round(handel_cena($t, $pl, $dzien)['sprzedaz'] * (1 + $marza));
                        $przychod += $cena * $ilosc;
                        $saldo += $cena * $ilosc;
                        $pozycje[] = ['przystanek' => $nr, 'planeta' => $pl['nazwa'], 'typ' => 'sprzedaj', 'towar' => $t['nazwa'], 'ilosc' => $ilosc, 'cena' => $cena];
                    }
                    break;
                case 'zaladunek':
                    $wMagazynie = $magazyn[$klucz] ?? 0;
                    if ($ilosc > $wMagazynie) {
                        $problemy[] = "Przystanek $nr ({$pl['nazwa']}): załadunek $ilosc {$t['jednostka']} {$t['nazwa']}, a w magazynie jest $wMagazynie.";
                    }
                    $magazyn[$klucz] = max(0, $wMagazynie - $ilosc);
                    $ladownia[$t['id']] = $naPokladzie + $ilosc;
                    break;
                case 'kup':
                    $cena = (int) round(handel_cena($t, $pl, $dzien)['kupno'] * (1 - $marza));
                    $koszt += $cena * $ilosc;
                    $saldo -= $cena * $ilosc;
                    $najnizsze = min($najnizsze, $saldo);
                    $ladownia[$t['id']] = $naPokladzie + $ilosc;
                    $pozycje[] = ['przystanek' => $nr, 'planeta' => $pl['nazwa'], 'typ' => 'kup', 'towar' => $t['nazwa'], 'ilosc' => $ilosc, 'cena' => $cena];
                    break;
            }
        }
        $masa = 0;
        foreach ($ladownia as $tid => $il) {
            $masa += $il * (int) ($towary[$tid]['masa'] ?? 1);
        }
        if ($masa > $ladownosc) {
            $problemy[] = "Przystanek $nr ({$pl['nazwa']}): ładunek $masa t przekracza ładowność $ladownosc t.";
        }
    }
    if (array_sum($ladownia) > 0) {
        $problemy[] = 'Po ostatnim przystanku w ładowni zostaje towar. Rozładuj go, sprzedaj albo dodaj przystanek.';
    }
    return $wynik();
}

// ---------- wykonanie kursu ----------
//
// Rozlicza jeden obieg: towar w magazynach, wynik handlu na rachunku firmy (jedna operacja
// na obieg), opcjonalnie przestawia statek na ostatnią planetę. Zwraca wpis do dziennika.

function handel_wykonaj(array &$firma, array $szlak, string $dzien, bool $przestaw_statek): array
{
    $dane = &$firma['dane'];
    $kat = katalog();
    $sym = handel_symuluj($szlak, $dane, $kat, $dzien, handel_umiejetnosc($dane, $szlak['statek']), true);
    $wpis = ['data' => $dzien, 'pozycje' => $sym['pozycje']];
    if ($sym['problemy']) {
        return $wpis + ['ok' => false, 'zysk' => 0, 'opis' => implode(' ', $sym['problemy'])];
    }
    if ($sym['koszt'] > 0 || $sym['przychod'] > 0) {
        $klucz = 'f:' . $firma['id'];
        $nazwy = bank_wlasciciele() + [$klucz => $firma['nazwa']];
        $tytul = 'Rozliczenie kursu „' . $szlak['nazwa'] . '” (' . $dzien . ')';
        $wynik = zmien('bank', function (&$bank) use ($klucz, $sym, $tytul, $nazwy) {
            // Zakupy trzeba opłacić, zanim przyjdą wpływy ze sprzedaży.
            if (bank_konto($bank, $klucz)['saldo'] + $sym['najnizsze'] < 0) return 'srodki';
            if ($sym['zysk'] >= 0) {
                bank_uznaj($bank, $klucz, $sym['zysk'], 'Giełdy portowe', $tytul, $nazwy);
            } else {
                bank_obciaz($bank, $klucz, -$sym['zysk'], 'Giełdy portowe', $tytul, $nazwy);
            }
            return 'ok';
        }, bank_pusty());
        if ($wynik !== 'ok') {
            return $wpis + ['ok' => false, 'zysk' => 0, 'opis' => 'Na rachunku firmy zabrakło środków na zakupy.'];
        }
    }
    $dane['magazyn'] = [];
    foreach ($sym['magazyn'] as $k => $il) {
        if ($il > 0) {
            [$pl, $tw] = explode('|', $k, 2);
            $dane['magazyn'][] = ['planeta' => $pl, 'towar' => $tw, 'ilosc' => $il];
        }
    }
    if ($przestaw_statek) {
        $ostatni = end($szlak['przystanki']);
        foreach ($dane['flota'] as &$s) {
            if ($s['id'] === $szlak['statek']) {
                $s['lokalizacja'] = $ostatni['planeta'];
                $s['stan'] = 'sprawny';
            }
        }
    }
    return $wpis + ['ok' => true, 'zysk' => $sym['zysk'], 'opis' => ''];
}

// ---------- automaty ----------

function handel_automaty(): void
{
    $teraz = time();
    $czy = false;
    foreach (wczytaj('firmy', ['firmy' => []])['firmy'] as $f) {
        foreach ($f['dane']['szlaki'] ?? [] as $s) {
            if (!empty($s['auto']) && $s['status'] === 'w_realizacji' && strtotime($s['nastepny'] ?? 'now') <= $teraz) $czy = true;
        }
    }
    if (!$czy) return; // nic do zrobienia — nie zapisujemy pliku przy każdym wejściu

    zmien('firmy', function (&$wszystko) use ($teraz) {
        foreach ($wszystko['firmy'] as &$f) {
            foreach ($f['dane']['szlaki'] as $i => $s) {
                if (empty($s['auto']) || $s['status'] !== 'w_realizacji') continue;
                $obiegi = 0;
                while (strtotime($s['nastepny']) <= $teraz && $obiegi < HANDEL_MAX_OBIEGOW) {
                    $obiegi++;
                    $wpis = handel_wykonaj($f, $s, date('Y-m-d', strtotime($s['nastepny'])), false);
                    $s['dziennik'][] = $wpis;
                    if (!$wpis['ok']) {
                        $s['status'] = 'wstrzymany';
                        $s['uwagi'] = 'Automat wstrzymany: ' . $wpis['opis'];
                        break;
                    }
                    $s['nastepny'] = date('c', strtotime($s['nastepny']) + $s['co_dni'] * 86400);
                }
                $s['dziennik'] = array_slice($s['dziennik'] ?? [], -HANDEL_DZIENNIK);
                $f['dane']['szlaki'][$i] = $s;
            }
        }
    }, ['firmy' => []]);
}

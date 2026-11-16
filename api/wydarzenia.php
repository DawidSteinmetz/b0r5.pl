<?php
// Wydarzenia świata gry: jedno miejsce, z którego MG porusza kilkoma systemami naraz.
//
// Wydarzenie ma nazwę, termin (od, opcjonalnie do) i listę SKUTKÓW. Każdy rodzaj skutku
// obsługuje inny system, więc dokładanie nowych rodzajów nie rusza reszty:
//
//   ceny     — mnożnik cen: giełda (instrumenty) i handel firm (towary katalogu na planetach).
//              Zakres: planeta, rodzaj (towar/spolka), kategoria, cel (symbol albo nazwa towaru);
//              puste pole = wszystko. „zmiana” w % (+40 = drożej o 40%), „narastanie” w dniach,
//              „niepewnosc” mnoży dzienne wahania (panika na rynku).
//   holonet  — komunikat w Holonecie (jawny).
//   kanal    — wiadomość w kanale szyfrowanym (czarny rynek informacji, kanaly.php).
//
// Komunikaty mają „kiedy”: start (gdy wydarzenie się zaczyna) albo koniec (dzień po „do”).
// Wysyłają się same przy pierwszym żądaniu po terminie (bez crona) i tylko raz.
// Wyłączone wydarzenie nie działa wcale.

declare(strict_types=1);

const WYDARZENIA_RODZAJE = ['ceny', 'holonet', 'kanal'];

function wydarzenia_lista(): array
{
    static $cache = null;
    return $cache ??= wczytaj('wydarzenia', []);
}

function wydarzenia_norm(string $t): string
{
    return mb_strtolower(trim($t));
}

// Czy wydarzenie trwa w danym dniu (Y-m-d).
function wydarzenie_trwa(array $w, string $dzien): bool
{
    if (empty($w['wlaczone'])) return false;
    if (($w['od'] ?? '') === '' || $dzien < $w['od']) return false;
    return ($w['do'] ?? '') === '' || $dzien <= $w['do'];
}

// Czy skutek „ceny” dotyczy obiektu. $obiekt: planety (lista nazw), rodzaj, kategoria, cele (symbol, nazwa).
function wydarzenia_pasuje(array $s, array $obiekt): bool
{
    $pl = wydarzenia_norm($s['planeta'] ?? '');
    if ($pl !== '' && !in_array($pl, array_map('wydarzenia_norm', $obiekt['planety'] ?? []), true)) return false;
    if (($s['rodzaj'] ?? '') !== '' && $s['rodzaj'] !== ($obiekt['rodzaj'] ?? '')) return false;
    $kat = wydarzenia_norm($s['kategoria'] ?? '');
    if ($kat !== '' && $kat !== wydarzenia_norm($obiekt['kategoria'] ?? '')) return false;
    $cel = wydarzenia_norm($s['cel'] ?? '');
    if ($cel !== '' && !in_array($cel, array_map('wydarzenia_norm', $obiekt['cele'] ?? []), true)) return false;
    return true;
}

// Mnożnik ceny i niepewności dla obiektu w danym dniu. $lista pozwala policzyć podgląd
// na wydarzeniach, których jeszcze nie zapisano.
function wydarzenia_wplyw(array $obiekt, string $dzien, ?array $lista = null): array
{
    $mnoznik = 1.0;
    $niepewnosc = 1.0;
    foreach ($lista ?? wydarzenia_lista() as $w) {
        if (!wydarzenie_trwa($w, $dzien)) continue;
        $dni = (int) floor((strtotime($dzien) - strtotime($w['od'])) / 86400) + 1;
        foreach ($w['skutki'] ?? [] as $s) {
            if (($s['rodzaj_skutku'] ?? '') !== 'ceny' || !wydarzenia_pasuje($s, $obiekt)) continue;
            $narastanie = max(0, (int) ($s['narastanie'] ?? 0));
            $udzial = $narastanie > 0 ? min(1.0, $dni / $narastanie) : 1.0;
            $mnoznik *= max(0.05, 1 + (float) ($s['zmiana'] ?? 0) / 100 * $udzial);
            $niepewnosc *= max(0.1, (float) ($s['niepewnosc'] ?? 1));
        }
    }
    return ['mnoznik' => $mnoznik, 'niepewnosc' => $niepewnosc];
}

// ---------- komunikaty (holonet, kanały) ----------

function wydarzenia_emituj(): void
{
    $dzis = date('Y-m-d');
    $do_wyslania = false;
    foreach (wydarzenia_lista() as $w) {
        foreach ($w['skutki'] ?? [] as $i => $s) {
            $termin = wydarzenia_termin_skutku($w, $s);
            if ($termin !== null && $termin <= $dzis && empty($w['wyslane'][$s['id'] ?? $i])) $do_wyslania = true;
        }
    }
    if (!$do_wyslania) return;

    $wiadomosci = [];
    zmien('wydarzenia', function (&$lista) use ($dzis, &$wiadomosci) {
        foreach ($lista as &$w) {
            foreach ($w['skutki'] ?? [] as $i => $s) {
                $termin = wydarzenia_termin_skutku($w, $s);
                if ($termin === null || $termin > $dzis || !empty($w['wyslane'][$s['id'] ?? $i])) continue;
                $w['wyslane'][$s['id'] ?? $i] = date('c');
                $wiadomosci[] = $s + ['termin' => $termin, 'wydarzenie' => $w['id']];
            }
        }
    }, []);
    foreach ($wiadomosci as $s) {
        if ($s['rodzaj_skutku'] === 'holonet') {
            zmien('holonet', function (&$h) use ($s) {
                $h[] = ['id' => id(), 'tytul' => $s['tytul'], 'tresc' => $s['tresc'], 'zrodlo' => $s['zrodlo'] ?? '', 'data' => $s['termin']];
            });
        } elseif ($s['rodzaj_skutku'] === 'kanal') {
            zmien('kanaly', function (&$kk) use ($s) {
                foreach ($kk['lista'] as &$k) {
                    if ($k['id'] === $s['kanal']) $k['wpisy'][] = ['id' => id(), 'data' => date('c', strtotime($s['termin'] . ' 08:00')), 'tresc' => $s['tresc']];
                }
            }, kanaly_puste());
        }
    }
}

// Dzień wysłania komunikatu; null = skutek nie jest komunikatem albo nie ma terminu.
function wydarzenia_termin_skutku(array $w, array $s): ?string
{
    if (!in_array($s['rodzaj_skutku'] ?? '', ['holonet', 'kanal'], true) || empty($w['wlaczone']) || ($w['od'] ?? '') === '') return null;
    if (($s['kiedy'] ?? 'start') === 'koniec') {
        return ($w['do'] ?? '') === '' ? null : date('Y-m-d', strtotime($w['do'] . ' +1 day'));
    }
    return $w['od'];
}

// ---------- zapis z panelu MG ----------

function wydarzenie_z_wejscia(array $w): array
{
    $data = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
    $t = fn($v, $max) => mb_substr(trim((string) $v), 0, $max);
    $wyd = [
        'id' => $t($w['id'] ?? '', 32) ?: id(),
        'nazwa' => $t($w['nazwa'] ?? '', 120),
        'opis' => $t($w['opis'] ?? '', 2000),
        'od' => $data($w['od'] ?? ''),
        'do' => $data($w['do'] ?? ''),
        'wlaczone' => (bool) ($w['wlaczone'] ?? false),
        'skutki' => [],
    ];
    if ($wyd['nazwa'] === '') blad('Wydarzenie potrzebuje nazwy.');
    if ($wyd['od'] === '') blad('Podaj datę rozpoczęcia.');
    if ($wyd['do'] !== '' && $wyd['do'] < $wyd['od']) blad('Data zakończenia jest przed rozpoczęciem.');
    foreach ((array) ($w['skutki'] ?? []) as $nr => $s) {
        $r = (string) ($s['rodzaj_skutku'] ?? '');
        if (!in_array($r, WYDARZENIA_RODZAJE, true)) blad('Skutek ' . ($nr + 1) . ': nieznany rodzaj.');
        $kiedy = ($s['kiedy'] ?? 'start') === 'koniec' ? 'koniec' : 'start';
        $sid = $t($s['id'] ?? '', 32) ?: id(); // stały identyfikator: po nim wiadomo, co już wysłano
        $wyd['skutki'][] = ['id' => $sid] + match ($r) {
            'ceny' => [
                'rodzaj_skutku' => 'ceny',
                'planeta' => $t($s['planeta'] ?? '', 80),
                'rodzaj' => in_array($s['rodzaj'] ?? '', ['towar', 'spolka'], true) ? $s['rodzaj'] : '',
                'kategoria' => $t($s['kategoria'] ?? '', 40),
                'cel' => $t($s['cel'] ?? '', 80),
                'zmiana' => max(-90, min(500, round((float) ($s['zmiana'] ?? 0), 1))),
                'narastanie' => max(0, min(60, (int) ($s['narastanie'] ?? 0))),
                'niepewnosc' => max(0.2, min(5, round((float) ($s['niepewnosc'] ?? 1), 2))),
            ],
            'holonet' => [
                'rodzaj_skutku' => 'holonet', 'kiedy' => $kiedy,
                'tytul' => $t($s['tytul'] ?? '', 160) ?: blad('Skutek ' . ($nr + 1) . ': komunikat potrzebuje tytułu.'),
                'tresc' => $t($s['tresc'] ?? '', 8000),
                'zrodlo' => $t($s['zrodlo'] ?? '', 80),
            ],
            'kanal' => [
                'rodzaj_skutku' => 'kanal', 'kiedy' => $kiedy,
                'kanal' => $t($s['kanal'] ?? '', 32) ?: blad('Skutek ' . ($nr + 1) . ': wybierz kanał szyfrowany.'),
                'tresc' => $t($s['tresc'] ?? '', 4000) ?: blad('Skutek ' . ($nr + 1) . ': wiadomość jest pusta.'),
            ],
        };
    }
    return $wyd;
}

function obsluz_wydarzenia(string $akcja, bool $post): void
{
    // Każde żądanie zalogowanej osoby może dopisać zaległe komunikaty wydarzeń.
    if ($akcja !== '' && ja()) wydarzenia_emituj();

    switch ($akcja) {

        case 'mg_wydarzenia':
            wymagaj_mg();
            $lista = wydarzenia_lista();
            usort($lista, fn($a, $b) => strcmp($b['od'], $a['od']));
            $dzis = date('Y-m-d');
            foreach ($lista as &$w) {
                $w['stan'] = empty($w['wlaczone']) ? 'wyłączone' : ($dzis < $w['od'] ? 'zaplanowane' : (wydarzenie_trwa($w, $dzis) ? 'trwa' : 'zakończone'));
            }
            unset($w);
            // Podpowiedzi do formularza: kanały, planety, kategorie i cele z giełdy i katalogu.
            $g = gielda_definicje();
            $kat = katalog();
            odpowiedz([
                'wydarzenia' => $lista,
                'kanaly' => array_map(fn($k) => ['id' => $k['id'], 'nazwa' => $k['nazwa']], wczytaj('kanaly', kanaly_puste())['lista']),
                'planety' => array_values(array_unique(array_merge(
                    array_column($kat['planety'], 'nazwa'),
                    array_column($g['gieldy'], 'planeta'),
                    array_filter(array_column($g['instrumenty'], 'pochodzenie'))))),
                'kategorie' => array_values(array_unique(array_merge(
                    array_column($g['instrumenty'], 'kategoria'),
                    array_filter(array_map(fn($t) => $t['kategoria'] ?? '', $kat['towary']))))),
                'cele' => array_values(array_unique(array_merge(
                    array_column($g['instrumenty'], 'symbol'),
                    array_column($kat['towary'], 'nazwa')))),
            ]);

        case 'mg_wydarzenie_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $wyd = wydarzenie_z_wejscia(wejscie()['wydarzenie'] ?? []);
            zmien('wydarzenia', function (&$lista) use ($wyd) {
                foreach ($lista as &$w) {
                    if ($w['id'] !== $wyd['id']) continue;
                    // Wysłane komunikaty zostają wysłane (zmiana treści nie wyśle ich drugi raz).
                    $wyd['wyslane'] = $w['wyslane'] ?? [];
                    $w = $wyd;
                    return;
                }
                $lista[] = $wyd;
            }, []);
            gielda_przelicz_dzis();
            odpowiedz(['ok' => true, 'id' => $wyd['id']]);

        case 'mg_wydarzenie_usun':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $wid = pole('id', 32);
            zmien('wydarzenia', function (&$lista) use ($wid) {
                $lista = array_values(array_filter($lista, fn($w) => $w['id'] !== $wid));
            }, []);
            gielda_przelicz_dzis();
            odpowiedz(['ok' => true]);
    }
}

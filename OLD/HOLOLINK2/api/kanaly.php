<?php
// Czarny rynek informacji: zaszyfrowane kanały (wtyczki) i pliki deszyfrujące.
//
// - MG zakłada kanał (nazwa + klucz instalacyjny) i publikuje w nim wiadomości pisane
//   szyfrem słownym, np. „Duży ptak opuścił gniazdo na Rykowie.”
// - Gracz instaluje wtyczkę w Ustawieniach, wpisując klucz. Kanał pojawia się w Holonecie.
// - Plik deszyfrujący to zwykły dokument Dekodera ze „słownikiem” (kod → znaczenie).
//   Gracz pobiera go po sygnaturze, więc ma go „na dysku”. Kanał podmienia znane mu kody;
//   różne pliki (wersje słownika) odszyfrowują różne fragmenty.

declare(strict_types=1);

function kanaly_puste(): array
{
    return ['lista' => []];
}

function instalacje(string $uid): array
{
    return wczytaj('instalacje', [])[$uid] ?? [];
}

// Słownik z tekstu MG: jedna linia = „kod | odmiana | odmiana = znaczenie”.
function slownik_z_tekstu(string $tekst): array
{
    $wpisy = [];
    foreach (preg_split('/\R/u', $tekst) as $linia) {
        if (!str_contains($linia, '=')) continue;
        [$kody, $znaczenie] = array_map('trim', explode('=', $linia, 2));
        $kody = array_values(array_filter(array_map('trim', explode('|', $kody)), fn($k) => $k !== ''));
        if ($kody && $znaczenie !== '') {
            $wpisy[] = ['kody' => array_map(fn($k) => mb_substr($k, 0, 80), $kody), 'znaczenie' => mb_substr($znaczenie, 0, 120)];
        }
    }
    return array_slice($wpisy, 0, 300);
}

function obsluz_kanaly(string $akcja, bool $post): void
{
    switch ($akcja) {

        case 'wtyczki':
            $u = wymagaj_logowania();
            $moje = instalacje($u['id']);
            $lista = [];
            foreach (wczytaj('kanaly', kanaly_puste())['lista'] as $k) {
                if (in_array($k['id'], $moje, true)) $lista[] = ['id' => $k['id'], 'nazwa' => $k['nazwa']];
            }
            odpowiedz(['wtyczki' => $lista]);

        case 'wtyczka_instaluj':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $klucz = mb_strtoupper(trim(pole('klucz', 80)));
            usleep(600000); // „weryfikacja podpisu” — i hamulec dla zgadywania kluczy
            $kanal = null;
            foreach (wczytaj('kanaly', kanaly_puste())['lista'] as $k) {
                if ($klucz !== '' && mb_strtoupper($k['klucz']) === $klucz) $kanal = $k;
            }
            if (!$kanal) blad('Nieprawidłowy klucz instalacyjny albo uszkodzona paczka.');
            zmien('instalacje', function (&$i) use ($u, $kanal) {
                $i[$u['id']] = array_values(array_unique([...($i[$u['id']] ?? []), $kanal['id']]));
            });
            odpowiedz(['ok' => true, 'nazwa' => $kanal['nazwa']]);

        case 'wtyczka_usun':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $kid = pole('id', 32);
            zmien('instalacje', function (&$i) use ($u, $kid) {
                $i[$u['id']] = array_values(array_filter($i[$u['id']] ?? [], fn($x) => $x !== $kid));
            });
            odpowiedz(['ok' => true]);

        case 'kanal':
            // Wiadomości kanału + słowniki z plików, które gracz ma w Dekoderze.
            $u = wymagaj_logowania();
            $kid = (string) ($_GET['id'] ?? '');
            if (!in_array($kid, instalacje($u['id']), true)) blad('Wtyczka nie jest zainstalowana.', 403);
            foreach (wczytaj('kanaly', kanaly_puste())['lista'] as $k) {
                if ($k['id'] !== $kid) continue;
                $wpisy = $k['wpisy'];
                usort($wpisy, fn($a, $b) => strcmp($b['data'], $a['data']));
                $pliki = array_column(wczytaj('skrzynki')[$u['id']]['pliki'] ?? [], 'sygnatura');
                $slowniki = [];
                foreach (wczytaj('dokumenty') as $d) {
                    if (in_array($d['sygnatura'], $pliki, true) && !empty($d['slownik'])) {
                        $slowniki[] = ['plik' => $d['tytul'], 'wpisy' => $d['slownik']];
                    }
                }
                odpowiedz(['kanal' => ['id' => $k['id'], 'nazwa' => $k['nazwa']], 'wpisy' => $wpisy, 'slowniki' => $slowniki]);
            }
            blad('Kanał przestał nadawać.', 404);

        // ----- panel MG -----

        case 'mg_kanaly':
            wymagaj_mg();
            $inst = wczytaj('instalacje', []);
            $postacie = bank_postacie();
            $lista = wczytaj('kanaly', kanaly_puste())['lista'];
            foreach ($lista as &$k) {
                $k['odbiorcy'] = [];
                foreach ($inst as $uid => $ids) {
                    if (in_array($k['id'], $ids, true)) $k['odbiorcy'][] = $postacie[$uid] ?? '?';
                }
            }
            odpowiedz(['kanaly' => $lista]);

        case 'mg_kanal_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $kid = pole('id', 32);
            $nazwa = pole('nazwa', 80);
            $klucz = mb_strtoupper(pole('klucz', 80));
            if ($nazwa === '' || $klucz === '') blad('Nazwa i klucz instalacyjny są wymagane.');
            zmien('kanaly', function (&$kk) use ($kid, $nazwa, $klucz) {
                foreach ($kk['lista'] as $k) {
                    if (mb_strtoupper($k['klucz']) === $klucz && $k['id'] !== $kid) blad('Ten klucz ma już inny kanał.');
                }
                foreach ($kk['lista'] as &$k) {
                    if ($k['id'] === $kid) {
                        $k['nazwa'] = $nazwa;
                        $k['klucz'] = $klucz;
                        return;
                    }
                }
                unset($k);
                $kk['lista'][] = ['id' => id(), 'nazwa' => $nazwa, 'klucz' => $klucz, 'wpisy' => []];
            }, kanaly_puste());
            odpowiedz(['ok' => true]);

        case 'mg_kanal_usun':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $kid = pole('id', 32);
            zmien('kanaly', function (&$kk) use ($kid) {
                $kk['lista'] = array_values(array_filter($kk['lista'], fn($k) => $k['id'] !== $kid));
            }, kanaly_puste());
            odpowiedz(['ok' => true]);

        case 'mg_wpis_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $kid = pole('kanal', 32);
            $tresc = pole('tresc', 4000);
            if ($tresc === '') blad('Wiadomość jest pusta.');
            zmien('kanaly', function (&$kk) use ($kid, $tresc) {
                foreach ($kk['lista'] as &$k) {
                    if ($k['id'] === $kid) {
                        $k['wpisy'][] = ['id' => id(), 'data' => date('c'), 'tresc' => $tresc];
                        return;
                    }
                }
                blad('Nie ma takiego kanału.', 404);
            }, kanaly_puste());
            odpowiedz(['ok' => true]);

        case 'mg_wpis_usun':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $kid = pole('kanal', 32);
            $wid = pole('id', 32);
            zmien('kanaly', function (&$kk) use ($kid, $wid) {
                foreach ($kk['lista'] as &$k) {
                    if ($k['id'] === $kid) $k['wpisy'] = array_values(array_filter($k['wpisy'], fn($x) => $x['id'] !== $wid));
                }
            }, kanaly_puste());
            odpowiedz(['ok' => true]);
    }
}

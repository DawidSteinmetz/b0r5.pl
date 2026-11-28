<?php
// Jedno wspólne API dla całego datapada i panelu MG.
// Wywołanie: api/?a=<akcja>   (GET do odczytu, POST z JSON-em do zmian)
// Trwałe dane aplikacji przechowuje SQL. Pliki w api/dane/ służą tylko do importu
// danych ze starszych wersji podczas migracji.

declare(strict_types=1);

const DANE = __DIR__ . '/dane';
const FRAKCJE = ['Rebelia', 'Imperium', 'Syndykat'];
const STRAZNIK = "<?php http_response_code(404); exit; ?>\n";
require_once __DIR__ . '/polaczenie.php';

date_default_timezone_set('Europe/Warsaw');
require __DIR__ . '/bank.php';
require __DIR__ . '/firmy.php';
require __DIR__ . '/handel.php';
require __DIR__ . '/komorka.php';
require __DIR__ . '/agencja.php';
require __DIR__ . '/kanaly.php';
require __DIR__ . '/sceny.php';
require __DIR__ . '/karty.php';
require __DIR__ . '/ruch.php';
require __DIR__ . '/wydarzenia.php';
require __DIR__ . '/gielda.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_name('dp_sesja');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ---------- narzędzia ----------

function odpowiedz($dane, int $kod = 200): never
{
    http_response_code($kod);
    echo json_encode($dane, JSON_UNESCAPED_UNICODE);
    exit;
}

function blad(string $komunikat, int $kod = 400): never
{
    odpowiedz(['blad' => $komunikat], $kod);
}

function plik(string $nazwa): string
{
    if (!is_dir(DANE)) {
        mkdir(DANE, 0770, true);
    }
    return DANE . '/' . $nazwa . '.php';
}

function wczytaj(string $nazwa, $domyslne = [])
{
    $q = polaczenie_bazy()->prepare('SELECT payload FROM datapad_dane WHERE nazwa = ?');
    $q->execute([$nazwa]);
    $tresc = $q->fetchColumn();
    if ($tresc !== false) return json_decode((string) $tresc, true) ?? $domyslne;
    $f = plik($nazwa);
    if (!is_file($f)) {
        return $domyslne;
    }
    $tresc = (string) file_get_contents($f);
    if (str_starts_with($tresc, STRAZNIK)) {
        $tresc = substr($tresc, strlen(STRAZNIK));
    }
    $v = json_decode($tresc, true);
    if (is_array($v)) {
        $q = polaczenie_bazy()->prepare('INSERT IGNORE INTO datapad_dane (nazwa, payload) VALUES (?, ?)');
        $q->execute([$nazwa, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        return $v;
    }
    return $domyslne;
}

// Odczyt-zmiana-zapis w transakcji z blokadą rekordu, żeby zapisy się nie nadpisały.
function zmien(string $nazwa, callable $fn, $domyslne = [])
{
    $db = polaczenie_bazy();
    $db->beginTransaction();
    try {
        $q = $db->prepare('INSERT IGNORE INTO datapad_dane (nazwa, payload) VALUES (?, ?)');
        $q->execute([$nazwa, json_encode($domyslne, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $nowy = $q->rowCount() === 1;
        $q = $db->prepare('SELECT payload FROM datapad_dane WHERE nazwa = ? FOR UPDATE');
        $q->execute([$nazwa]);
        $payload = $q->fetchColumn();
        $dane = json_decode((string) $payload, true) ?? $domyslne;
        if ($nowy && is_file(plik($nazwa))) {
            $stare = (string) file_get_contents(plik($nazwa));
            if (str_starts_with($stare, STRAZNIK)) $stare = substr($stare, strlen(STRAZNIK));
            $dane = json_decode($stare, true) ?? $dane;
        }
        $wynik = $fn($dane);
        $q = $db->prepare('UPDATE datapad_dane SET payload = ?, zmieniono = CURRENT_TIMESTAMP WHERE nazwa = ?');
        $q->execute([json_encode($dane, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $nazwa]);
        $db->commit();
        return $wynik;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function wejscie(): array
{
    static $w = null;
    if ($w === null) {
        $w = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($w)) {
            $w = [];
        }
    }
    return $w;
}

function pole(string $nazwa, int $max = 2000): string
{
    $v = trim((string) (wejscie()[$nazwa] ?? ''));
    return mb_substr($v, 0, $max);
}

function id(): string
{
    return bin2hex(random_bytes(6));
}

function uzytkownicy(): array
{
    return wczytaj('uzytkownicy');
}

function bez_hasla(array $u): array
{
    unset($u['hash']);
    return $u;
}

function ja(): ?array
{
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) {
        return null;
    }
    foreach (uzytkownicy() as $u) {
        if ($u['id'] === $uid) {
            return $u;
        }
    }
    return null;
}

function wymagaj_logowania(): array
{
    $u = ja();
    if (!$u) {
        blad('Sesja wygasła. Odblokuj urządzenie ponownie.', 401);
    }
    return $u;
}

function wymagaj_mg(): array
{
    $u = wymagaj_logowania();
    if (($u['rola'] ?? '') !== 'mg') {
        blad('Brak uprawnień.', 403);
    }
    return $u;
}

// ---------- obsługa żądania ----------

$akcja = (string) ($_GET['a'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

// Ochrona przed wysyłaniem formularzy z obcych stron: każdy POST musi mieć nasz nagłówek.
if ($post && ($_SERVER['HTTP_X_DATAPAD'] ?? '') !== '1') {
    blad('Odrzucono żądanie.', 403);
}

obsluz_wydarzenia($akcja, $post);
obsluz_bank($akcja, $post);
obsluz_firmy($akcja, $post);
obsluz_komorka($akcja, $post);
obsluz_agencja($akcja, $post);
obsluz_kanaly($akcja, $post);
obsluz_sceny($akcja, $post);
obsluz_karty($akcja, $post);
obsluz_ruch($akcja, $post);
obsluz_gielda($akcja, $post);

switch ($akcja) {

    case 'mapa_dane':
        $planety = polaczenie_bazy()->query('SELECT nazwa,region,x,y,grawitacja,slonca,ksiezyce,doba,rok,srednica FROM mapa_planety ORDER BY id')
            ->fetchAll(PDO::FETCH_NUM);
        $szlaki = polaczenie_bazy()->query('SELECT nazwa,waznosc,dlugosc,sciezki FROM mapa_szlaki ORDER BY id')->fetchAll();
        $szlaki = array_map(fn($s) => ['n' => $s['nazwa'], 'z' => (int) $s['waznosc'], 'dl' => (float) $s['dlugosc'], 'p' => json_decode($s['sciezki'], true)], $szlaki);
        odpowiedz(['planety' => $planety, 'szlaki' => $szlaki]);

    // ----- sesja -----

    case 'stan':
        $u = ja();
        $mg_istnieje = (bool) array_filter(uzytkownicy(), fn($x) => ($x['rola'] ?? '') === 'mg');
        odpowiedz(['uzytkownik' => $u ? bez_hasla($u) : null, 'mgIstnieje' => $mg_istnieje]);

    case 'zaloguj':
        if (!$post) blad('Zła metoda.', 405);
        $proby = $_SESSION['proby'] ?? 0;
        if ($proby >= 5) {
            sleep(2);
        }
        $login = mb_strtolower(pole('login', 64));
        $klucz = (string) (wejscie()['klucz'] ?? '');
        foreach (uzytkownicy() as $u) {
            if (mb_strtolower($u['login']) === $login && password_verify($klucz, $u['hash'])) {
                session_regenerate_id(true);
                $_SESSION['uid'] = $u['id'];
                $_SESSION['proby'] = 0;
                odpowiedz(['uzytkownik' => bez_hasla($u)]);
            }
        }
        $_SESSION['proby'] = $proby + 1;
        usleep(400000);
        blad('Odmowa dostępu. Nieprawidłowy identyfikator lub klucz.', 401);

    case 'wyloguj':
        if (!$post) blad('Zła metoda.', 405);
        $_SESSION = [];
        session_destroy();
        odpowiedz(['ok' => true]);

    // ----- holonet (komunikaty) -----

    case 'holonet':
        wymagaj_logowania();
        $k = wczytaj('holonet');
        usort($k, fn($a, $b) => strcmp($b['data'], $a['data']));
        odpowiedz(['komunikaty' => $k]);

    // ----- harmonogram -----

    case 'harmonogram':
        wymagaj_logowania();
        odpowiedz(['wpisy' => wczytaj('harmonogram')]);

    case 'harmonogram_dodaj':
        if (!$post) blad('Zła metoda.', 405);
        $u = wymagaj_logowania();
        $data = pole('data', 10);
        $status = pole('status', 20);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) blad('Nieprawidłowa data.');
        if (!in_array($status, ['dostepny', 'niepewny', 'nieobecny'], true)) blad('Nieprawidłowy status.');
        $wpis = [
            'id' => id(),
            'data' => $data,
            'status' => $status,
            'notatka' => pole('notatka', 120),
            'uid' => $u['id'],
            // Można zgłosić dowolną postać (domyślnie własną), jak w dawnym kalendarzu.
            'postac' => pole('postac', 40) ?: $u['postac'],
            'czas' => date('c'),
        ];
        zmien('harmonogram', function (&$w) use ($wpis) {
            // Jedna deklaracja danego użytkownika dla konkretnej postaci na dzień.
            $w = array_values(array_filter($w, fn($x) => !($x['uid'] === $wpis['uid'] && $x['data'] === $wpis['data'] && mb_strtolower($x['postac']) === mb_strtolower($wpis['postac']))));
            $w[] = $wpis;
        });
        odpowiedz(['wpis' => $wpis]);

    case 'harmonogram_usun':
        if (!$post) blad('Zła metoda.', 405);
        $u = wymagaj_logowania();
        $wid = pole('id', 32);
        zmien('harmonogram', function (&$w) use ($wid, $u) {
            $w = array_values(array_filter($w, fn($x) => !($x['id'] === $wid && ($x['uid'] === $u['id'] || $u['rola'] === 'mg'))));
        });
        odpowiedz(['ok' => true]);

    // ----- dekoder -----

    case 'dekoder':
        $u = wymagaj_logowania();
        $skrzynki = wczytaj('skrzynki');
        $moja = $skrzynki[$u['id']] ?? ['foldery' => ['Odebrane'], 'pliki' => []];
        $dok = [];
        foreach (wczytaj('dokumenty') as $d) {
            $dok[$d['sygnatura']] = $d;
        }
        $pliki = [];
        foreach ($moja['pliki'] as $p) {
            if (isset($dok[$p['sygnatura']])) {
                $pliki[] = $p + ['tytul' => $dok[$p['sygnatura']]['tytul'], 'poziom' => $dok[$p['sygnatura']]['poziom']];
            }
        }
        odpowiedz(['foldery' => $moja['foldery'], 'pliki' => $pliki]);

    case 'dekoder_pobierz':
        if (!$post) blad('Zła metoda.', 405);
        $u = wymagaj_logowania();
        $syg = mb_strtoupper(pole('sygnatura', 64));
        $znaleziony = null;
        foreach (wczytaj('dokumenty') as $d) {
            if (mb_strtoupper($d['sygnatura']) === $syg) {
                $znaleziony = $d;
            }
        }
        if (!$znaleziony) blad('Nie odnaleziono transmisji o podanej sygnaturze.', 404);
        if (!empty($znaleziony['frakcja']) && $znaleziony['frakcja'] !== $u['frakcja'] && $u['rola'] !== 'mg') {
            blad('Transmisja zaszyfrowana kluczem innej sieci.', 403);
        }
        if ((int) $znaleziony['poziom'] > (int) $u['poziom']) {
            blad('Niewystarczający poziom uprawnień do odbioru.', 403);
        }
        zmien('skrzynki', function (&$s) use ($u, $znaleziony) {
            $m = $s[$u['id']] ?? ['foldery' => ['Odebrane'], 'pliki' => []];
            foreach ($m['pliki'] as $p) {
                if ($p['sygnatura'] === $znaleziony['sygnatura']) return;
            }
            $m['pliki'][] = ['sygnatura' => $znaleziony['sygnatura'], 'folder' => $m['foldery'][0], 'odebrano' => date('c')];
            $s[$u['id']] = $m;
        });
        odpowiedz(['ok' => true, 'sygnatura' => $znaleziony['sygnatura']]);

    case 'dekoder_otworz':
        $u = wymagaj_logowania();
        $syg = (string) ($_GET['s'] ?? '');
        $moja = wczytaj('skrzynki')[$u['id']] ?? ['pliki' => []];
        if (!in_array($syg, array_column($moja['pliki'], 'sygnatura'), true)) blad('Brak pliku w pamięci urządzenia.', 404);
        foreach (wczytaj('dokumenty') as $d) {
            if ($d['sygnatura'] === $syg) odpowiedz(['dokument' => $d]);
        }
        blad('Plik uszkodzony lub usunięty z sieci.', 404);

    case 'dekoder_folder':
        if (!$post) blad('Zła metoda.', 405);
        $u = wymagaj_logowania();
        $nazwa = pole('nazwa', 40);
        $stara = pole('stara', 40);
        if ($nazwa === '') blad('Podaj nazwę katalogu.');
        zmien('skrzynki', function (&$s) use ($u, $nazwa, $stara) {
            $m = $s[$u['id']] ?? ['foldery' => ['Odebrane'], 'pliki' => []];
            if ($stara !== '' && in_array($stara, $m['foldery'], true)) {
                $m['foldery'] = array_map(fn($f) => $f === $stara ? $nazwa : $f, $m['foldery']);
                foreach ($m['pliki'] as &$p) {
                    if ($p['folder'] === $stara) $p['folder'] = $nazwa;
                }
            } elseif (!in_array($nazwa, $m['foldery'], true)) {
                $m['foldery'][] = $nazwa;
            }
            $s[$u['id']] = $m;
        });
        odpowiedz(['ok' => true]);

    case 'dekoder_przenies':
        if (!$post) blad('Zła metoda.', 405);
        $u = wymagaj_logowania();
        $syg = pole('sygnatura', 64);
        $folder = pole('folder', 40);
        zmien('skrzynki', function (&$s) use ($u, $syg, $folder) {
            $m = $s[$u['id']] ?? null;
            if (!$m || !in_array($folder, $m['foldery'], true)) return;
            foreach ($m['pliki'] as &$p) {
                if ($p['sygnatura'] === $syg) $p['folder'] = $folder;
            }
            $s[$u['id']] = $m;
        });
        odpowiedz(['ok' => true]);

    // ----- sieć frakcji -----

    case 'frakcja':
        $u = wymagaj_logowania();
        $f = wczytaj('frakcje')[$u['frakcja']] ?? ['planety' => [], 'zasoby' => [], 'kadry' => []];
        odpowiedz(['frakcja' => $u['frakcja'], 'dane' => $f]);

    case 'frakcja_zasob':
        if (!$post) blad('Zła metoda.', 405);
        $u = wymagaj_logowania();
        $i = (int) (wejscie()['indeks'] ?? -1);
        $ilosc = (int) (wejscie()['ilosc'] ?? 0);
        $wynik = zmien('frakcje', function (&$fr) use ($u, $i, $ilosc) {
            if (!isset($fr[$u['frakcja']]['zasoby'][$i])) return false;
            $fr[$u['frakcja']]['zasoby'][$i]['ilosc'] = max(0, $ilosc);
            return true;
        });
        if (!$wynik) blad('Nie odnaleziono pozycji.', 404);
        odpowiedz(['ok' => true]);

    // ----- panel MG -----

    case 'mg_zaloz':
        // Pierwsze uruchomienie: zakłada konto MG, tylko gdy jeszcze żadnego nie ma.
        if (!$post) blad('Zła metoda.', 405);
        $login = pole('login', 64);
        $klucz = (string) (wejscie()['klucz'] ?? '');
        if ($login === '' || strlen($klucz) < 8) blad('Login i hasło (min. 8 znaków) są wymagane.');
        $nowy = zmien('uzytkownicy', function (&$us) use ($login, $klucz) {
            foreach ($us as $x) {
                if (($x['rola'] ?? '') === 'mg') return null;
            }
            $u = ['id' => id(), 'login' => $login, 'hash' => password_hash($klucz, PASSWORD_DEFAULT),
                'rola' => 'mg', 'postac' => 'Operator', 'frakcja' => 'Syndykat', 'oddzial' => '', 'ranga' => '', 'poziom' => 9];
            $us[] = $u;
            return $u;
        });
        if (!$nowy) blad('Konto MG już istnieje.', 409);
        session_regenerate_id(true);
        $_SESSION['uid'] = $nowy['id'];
        odpowiedz(['uzytkownik' => bez_hasla($nowy)]);

    case 'mg_uzytkownicy':
        wymagaj_mg();
        odpowiedz(['uzytkownicy' => array_map('bez_hasla', uzytkownicy())]);

    case 'mg_uzytkownik_zapisz':
        if (!$post) blad('Zła metoda.', 405);
        wymagaj_mg();
        $w = wejscie();
        $frakcja = (string) ($w['frakcja'] ?? '');
        if (!in_array($frakcja, FRAKCJE, true)) blad('Nieznana frakcja.');
        $dane = [
            'login' => pole('login', 64),
            'postac' => pole('postac', 80),
            'frakcja' => $frakcja,
            'oddzial' => pole('oddzial', 80),
            'ranga' => pole('ranga', 60),
            'poziom' => max(1, min(9, (int) ($w['poziom'] ?? 1))),
            'rola' => ($w['rola'] ?? '') === 'mg' ? 'mg' : 'gracz',
        ];
        if ($dane['login'] === '' || $dane['postac'] === '') blad('Login i postać są wymagane.');
        $klucz = (string) ($w['klucz'] ?? '');
        $uid = (string) ($w['id'] ?? '');
        $wynik = zmien('uzytkownicy', function (&$us) use ($dane, $klucz, $uid) {
            foreach ($us as $x) {
                if (mb_strtolower($x['login']) === mb_strtolower($dane['login']) && $x['id'] !== $uid) return 'zajety';
            }
            if ($uid === '') {
                if ($klucz === '') return 'brak_klucza';
                $us[] = ['id' => id(), 'hash' => password_hash($klucz, PASSWORD_DEFAULT)] + $dane;
                return 'ok';
            }
            foreach ($us as &$x) {
                if ($x['id'] === $uid) {
                    $x = array_merge($x, $dane);
                    if ($klucz !== '') $x['hash'] = password_hash($klucz, PASSWORD_DEFAULT);
                    return 'ok';
                }
            }
            return 'brak';
        });
        if ($wynik === 'zajety') blad('Taki login już istnieje.');
        if ($wynik === 'brak_klucza') blad('Nowe konto potrzebuje hasła.');
        if ($wynik === 'brak') blad('Nie ma takiego konta.', 404);
        odpowiedz(['ok' => true]);

    case 'mg_uzytkownik_usun':
        if (!$post) blad('Zła metoda.', 405);
        $mg = wymagaj_mg();
        $uid = pole('id', 32);
        if ($uid === $mg['id']) blad('Nie możesz usunąć własnego konta.');
        zmien('uzytkownicy', function (&$us) use ($uid) {
            $us = array_values(array_filter($us, fn($x) => $x['id'] !== $uid));
        });
        odpowiedz(['ok' => true]);

    case 'mg_dokumenty':
        wymagaj_mg();
        odpowiedz(['dokumenty' => wczytaj('dokumenty')]);

    case 'mg_dokument_zapisz':
        if (!$post) blad('Zła metoda.', 405);
        wymagaj_mg();
        $w = wejscie();
        $frakcja = (string) ($w['frakcja'] ?? '');
        $d = [
            'sygnatura' => mb_strtoupper(pole('sygnatura', 64)),
            'tytul' => pole('tytul', 120),
            'tresc' => pole('tresc', 20000),
            'poziom' => max(1, min(9, (int) ($w['poziom'] ?? 1))),
            'frakcja' => in_array($frakcja, FRAKCJE, true) ? $frakcja : '',
            // Plik deszyfrujący: linie „kod | odmiana = znaczenie” (zobacz kanaly.php).
            'slownik' => slownik_z_tekstu((string) ($w['slownik'] ?? '')),
        ];
        if ($d['sygnatura'] === '' || $d['tytul'] === '') blad('Sygnatura i tytuł są wymagane.');
        $stara = mb_strtoupper(pole('stara', 64));
        zmien('dokumenty', function (&$ds) use ($d, $stara) {
            $klucz = $stara !== '' ? $stara : $d['sygnatura'];
            $ds = array_values(array_filter($ds, fn($x) => $x['sygnatura'] !== $klucz && $x['sygnatura'] !== $d['sygnatura']));
            $ds[] = $d;
        });
        odpowiedz(['ok' => true]);

    case 'mg_dokument_usun':
        if (!$post) blad('Zła metoda.', 405);
        wymagaj_mg();
        $syg = pole('sygnatura', 64);
        zmien('dokumenty', function (&$ds) use ($syg) {
            $ds = array_values(array_filter($ds, fn($x) => $x['sygnatura'] !== $syg));
        });
        odpowiedz(['ok' => true]);

    case 'mg_frakcje':
        wymagaj_mg();
        $fr = wczytaj('frakcje');
        foreach (FRAKCJE as $f) {
            $fr[$f] ??= ['planety' => [], 'zasoby' => [], 'kadry' => []];
        }
        odpowiedz(['frakcje' => $fr]);

    case 'mg_frakcja_zapisz':
        if (!$post) blad('Zła metoda.', 405);
        wymagaj_mg();
        $w = wejscie();
        $f = (string) ($w['frakcja'] ?? '');
        $dane = $w['dane'] ?? null;
        if (!in_array($f, FRAKCJE, true) || !is_array($dane)) blad('Nieprawidłowe dane.');
        foreach (['planety', 'zasoby', 'kadry'] as $k) {
            if (!isset($dane[$k]) || !is_array($dane[$k])) blad("Brak listy: $k");
        }
        zmien('frakcje', function (&$fr) use ($f, $dane) {
            $fr[$f] = $dane;
        });
        odpowiedz(['ok' => true]);

    case 'mg_holonet_zapisz':
        if (!$post) blad('Zła metoda.', 405);
        wymagaj_mg();
        $k = ['id' => pole('id', 32) ?: id(), 'tytul' => pole('tytul', 160), 'tresc' => pole('tresc', 8000),
            'zrodlo' => pole('zrodlo', 80), 'data' => pole('data', 10) ?: date('Y-m-d')];
        if ($k['tytul'] === '') blad('Tytuł jest wymagany.');
        zmien('holonet', function (&$h) use ($k) {
            $h = array_values(array_filter($h, fn($x) => $x['id'] !== $k['id']));
            $h[] = $k;
        });
        odpowiedz(['ok' => true]);

    case 'mg_holonet_usun':
        if (!$post) blad('Zła metoda.', 405);
        wymagaj_mg();
        $kid = pole('id', 32);
        zmien('holonet', function (&$h) use ($kid) {
            $h = array_values(array_filter($h, fn($x) => $x['id'] !== $kid));
        });
        odpowiedz(['ok' => true]);

    default:
        blad('Nieznana operacja.', 404);
}

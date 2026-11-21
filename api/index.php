<?php
// Jedno wspólne API dla całego datapada i panelu MG.
// Wywołanie: api/?a=<akcja>   (GET do odczytu, POST z JSON-em do zmian)
// Trwałe dane aplikacji przechowuje SQL. Pliki w api/dane/ służą tylko do importu
// danych ze starszych wersji podczas migracji.

declare(strict_types=1);

const DANE = __DIR__ . '/dane';
const FRAKCJE = ['Rebelia', 'Imperium', 'Syndykat'];
const STRAZNIK = "<?php http_response_code(404); exit; ?>\n";
const DZIENNIK_BLEDOW = __DIR__ . '/dane/bledy.log';

// DZIENNIK BŁĘDÓW: ostrzeżenia i błędy PHP nie trafiają do odpowiedzi (psułyby JSON i tablet widziałby
// „Węzeł holonetu nie odpowiada”), tylko do api/dane/bledy.log (katalog zablokowany dla przeglądarki).
// MG widzi ostatnie wpisy w zakładce Czas. Gracz dostaje komunikat w języku świata.
function dziennik_bledow(string $tekst): void
{
    $akcja = (string) ($_GET['a'] ?? '');
    $wpis = date('Y-m-d H:i:s') . " [{$akcja}] " . preg_replace('/\s+/', ' ', $tekst) . "\n";
    if (@filesize(DZIENNIK_BLEDOW) > 300000) {
        $stare = (string) @file_get_contents(DZIENNIK_BLEDOW);
        @file_put_contents(DZIENNIK_BLEDOW, substr($stare, -100000), LOCK_EX);
    }
    if (@file_put_contents(DZIENNIK_BLEDOW, $wpis, FILE_APPEND | LOCK_EX) === false) error_log('HOLOLINK ' . $wpis);
}
ini_set('display_errors', '0');
ob_start();
set_error_handler(function (int $nr, string $tresc, string $plik, int $linia): bool {
    if (!(error_reporting() & $nr)) return false;
    dziennik_bledow("ostrzeżenie: {$tresc} w " . basename($plik) . ":{$linia}");
    return true;
});
set_exception_handler(function (Throwable $e): void {
    dziennik_bledow(get_class($e) . ': ' . $e->getMessage() . ' w ' . basename($e->getFile()) . ':' . $e->getLine());
    while (ob_get_level()) ob_end_clean();
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['blad' => 'Węzeł holonetu nie odpowiada. Spróbuj ponownie.'], JSON_UNESCAPED_UNICODE);
});
register_shutdown_function(function (): void {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    dziennik_bledow("błąd krytyczny: {$e['message']} w " . basename($e['file']) . ":{$e['line']}");
    while (ob_get_level()) ob_end_clean();
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['blad' => 'Węzeł holonetu nie odpowiada. Spróbuj ponownie.'], JSON_UNESCAPED_UNICODE);
});
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
require __DIR__ . '/floty.php';
require __DIR__ . '/poklad.php';
require __DIR__ . '/wydarzenia.php';
require __DIR__ . '/gielda.php';
require __DIR__ . '/czas.php';
require __DIR__ . '/radar.php';
require __DIR__ . '/doki.php';
require __DIR__ . '/atmosfera.php';
require __DIR__ . '/gospodarka.php';
require __DIR__ . '/astro.php';
require __DIR__ . '/gwiazdy.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_name('dp_sesja');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ---------- narzędzia ----------

function odpowiedz($dane, int $kod = 200): never
{
    if (function_exists('odpowiedz_bez_ukrytych_planet')) {
        $dane = odpowiedz_bez_ukrytych_planet($dane, ja());
    }
    while (ob_get_level()) ob_end_clean(); // przypadkowe wyjście (ostrzeżenia) nie psuje JSON
    http_response_code($kod);
    echo json_encode($dane, JSON_UNESCAPED_UNICODE);
    exit;
}

function blad(string $komunikat, int $kod = 400): never
{
    odpowiedz(['blad' => $komunikat], $kod);
}

function tabela_sql_dostepna(string $nazwa): bool
{
    static $cache = [];
    $dozwolone = ['mapa_planety', 'lokalizacje', 'budynki', 'datapad_pozycje_legacy', 'mapa_obiekty', 'mapa_obiekty_dostep',
        'mapa_floty', 'mapa_floty_jednostki', 'mapa_naped_jednostki', 'mapa_rozkazy_skokow', 'mapa_rozkazy_skokow_jednostki', 'mapa_teleporty_mg',
        'planety_gry', 'planety_widocznosc_konta', 'resynchronizacje', 'mapa_wykrycia_skokow', 'planety_cykle', 'planety_ksiezyce', 'wskazniki_zgody', 'zdjecia_gwiazd', 'wyczyny_progi', 'ustawienia_kont',
        'osoby_karta_eote', 'eote_cv_karty', 'eote_cv_wartosci', 'stocznia_naprawy',
        'mapa_udostepnienia', 'radar_baza_sygnatur', 'radar_analizy', 'radar_identyfikacje', 'npc_trasy', 'stocznia_stanowiska', 'gosp_towary', 'gosp_produkcja', 'gosp_obiekty', 'gosp_zuzycie', 'gosp_linie',
        'mapa_bitwy', 'mapa_bitwy_uczestnicy', 'osoby', 'podmioty', 'znane_lokacje'];
    if (!in_array($nazwa, $dozwolone, true)) return false;
    if (!array_key_exists($nazwa, $cache)) {
        try {
            $q = polaczenie_bazy()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $q->execute([$nazwa]);
            $cache[$nazwa] = (bool) $q->fetchColumn();
        } catch (Throwable $e) {
            $cache[$nazwa] = false;
        }
    }
    return $cache[$nazwa];
}

function mapa_planeta_widoczna(int $planetaId, ?array $u = null): bool
{
    $u ??= ja();
    if (($u['rola'] ?? '') === 'mg') return true;
    static $cache = [];
    $login = mb_strtolower((string) ($u['login'] ?? ''));
    $klucz = $planetaId . ':' . $login;
    if (array_key_exists($klucz, $cache)) return $cache[$klucz];
    try {
        $db = polaczenie_bazy();
        if (!tabela_sql_dostepna('planety_gry')) return $cache[$klucz] = true;
        $q = $db->prepare('SELECT COALESCE(g.zastrzezona,0) FROM mapa_planety p LEFT JOIN planety_gry g ON g.planeta_id=p.id WHERE p.id=?');
        $q->execute([$planetaId]);
        $zastrzezona = $q->fetchColumn();
        if ($zastrzezona === false || !(int) $zastrzezona) return $cache[$klucz] = true;
        if ($login === '' || !tabela_sql_dostepna('planety_widocznosc_konta')) return $cache[$klucz] = false;
        $q = $db->prepare('SELECT 1 FROM planety_widocznosc_konta WHERE planeta_id=? AND LOWER(konto_login)=? LIMIT 1');
        $q->execute([$planetaId, $login]);
        return $cache[$klucz] = (bool) $q->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$klucz] = false;
    }
}

function mapa_planety_ukryte_dla(?array $u): array
{
    if (($u['rola'] ?? '') === 'mg' || !tabela_sql_dostepna('mapa_planety') || !tabela_sql_dostepna('planety_gry')) return [];
    $sql = 'SELECT p.id,p.nazwa FROM mapa_planety p JOIN planety_gry g ON g.planeta_id=p.id WHERE g.zastrzezona=1';
    $q = polaczenie_bazy()->query($sql);
    $ukryte = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if (!mapa_planeta_widoczna((int) $p['id'], $u)) $ukryte[] = ['id' => (string) $p['id'], 'nazwa' => (string) $p['nazwa']];
    }
    return $ukryte;
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
    unset($u['hash'], $u['zaproszenie']);
    return $u;
}

// Zaproszenie nowego gracza: jednorazowy kod (w danych tylko jego hash), ważny ZAPROSZENIE_DNI dni.
// Gracz podaje login, kod i sam ustawia hasło; do tego czasu konto ma losowe hasło, którego nikt nie zna.
const ZAPROSZENIE_DNI = 7;
function zaproszenie_kod(): string
{
    $znaki = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $k = '';
    for ($i = 0; $i < 8; $i++) $k .= $znaki[random_int(0, strlen($znaki) - 1)];
    return substr($k, 0, 4) . '-' . substr($k, 4);
}
function zaproszenie_nowe(): array
{
    $kod = zaproszenie_kod();
    return [$kod, ['hash' => password_hash($kod, PASSWORD_DEFAULT), 'wazne_do' => time() + ZAPROSZENIE_DNI * 86400, 'wydano' => time()]];
}
// Gotowość gracza do gry (panel MG): konto aktywowane, pozycja ustawiona, przewodnik przejrzany.
function gotowosc_gracza(array $u): array
{
    $z = $u['zaproszenie'] ?? null;
    $konto = $z ? (time() > (int) $z['wazne_do'] ? 'wygaslo' : 'zaproszenie') : 'aktywne';
    $poz = karta((string) $u['id'])['pozycja'] ?? [];
    $przewodnik = null;
    if (function_exists('tabela_sql_dostepna') && tabela_sql_dostepna('ustawienia_kont')) {
        $q = polaczenie_bazy()->prepare('SELECT wartosc FROM ustawienia_kont WHERE konto_login=? AND klucz=?');
        $q->execute([(string) $u['login'], 'przewodnik']);
        $v = $q->fetchColumn();
        $przewodnik = $v === false ? null : json_decode((string) $v, true);
    }
    return ['konto' => $konto, 'zaproszenie_do' => $z ? (int) $z['wazne_do'] : null,
        'pozycja' => ($poz['planeta'] ?? '') !== '' || ($poz['lokacja'] ?? '') !== '',
        'przewodnik' => !empty($przewodnik['ukonczony']) ? 'ukonczony' : (!empty($przewodnik['pominiety']) ? 'pominiety' : 'nie')];
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

// Planety pod władzą frakcji z rejestru SQL (planety_wladza): frakcja zarządcy z jego tytułu
// (np. „Moff” → Imperium). Dołącza lokacje na planecie i okręty frakcji na orbicie; łączy się
// z planetami wpisanymi ręcznie przez MG (po nazwie).
function frakcja_planety_z_rejestru(string $frakcja, array $planety, array $u): array
{
    if (!tabela_sql_dostepna('mapa_planety') || !function_exists('mapa_frakcja_z_opisu')) return $planety;
    try {
        $db = polaczenie_bazy();
        $wiersze = $db->query("SELECT w.planeta_id, p.nazwa, w.nazwa_zarzadcy, w.funkcja FROM planety_wladza w
            JOIN mapa_planety p ON p.id = w.planeta_id WHERE w.do_utc IS NULL OR w.do_utc > UTC_TIMESTAMP()")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return $planety; }
    $indeks = [];
    foreach ($planety as $i => $p) $indeks[mb_strtolower((string) ($p['nazwa'] ?? ''))] = $i;
    foreach ($wiersze as $w) {
        if (mapa_frakcja_z_opisu($w['nazwa_zarzadcy'] . ' ' . $w['funkcja']) !== $frakcja) continue;
        if (!mapa_planeta_widoczna((int) $w['planeta_id'], $u)) continue;
        $instalacje = [['nazwa' => $w['nazwa_zarzadcy'], 'typ' => $w['funkcja'], 'stan' => 'władza planetarna']];
        if (tabela_sql_dostepna('lokalizacje')) {
            $q = $db->prepare("SELECT nazwa, COALESCE(NULLIF(rodzaj,''), typ) AS typ FROM lokalizacje WHERE planeta_id = ? AND status = 'aktywna' ORDER BY nazwa");
            $q->execute([(int) $w['planeta_id']]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $l) $instalacje[] = ['nazwa' => $l['nazwa'], 'typ' => $l['typ'], 'stan' => ''];
        }
        if (tabela_sql_dostepna('mapa_obiekty')) {
            $q = $db->prepare("SELECT nazwa, klasa, wlasciciel" . (function_exists('mapa_frakcja_dostepna') && mapa_frakcja_dostepna() ? ', frakcja' : '') . "
                FROM mapa_obiekty WHERE planeta_id = ? AND aktywny = 1 AND typ IN ('statek','jednostka') ORDER BY nazwa");
            $q->execute([(int) $w['planeta_id']]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $fr = ($o['frakcja'] ?? null) ?: mapa_frakcja_z_opisu((string) $o['wlasciciel']);
                if ($fr === $frakcja) $instalacje[] = ['nazwa' => $o['nazwa'], 'typ' => $o['klasa'] ?: 'okręt', 'stan' => 'na orbicie'];
            }
        }
        $klucz = mb_strtolower($w['nazwa']);
        if (isset($indeks[$klucz])) {
            $i = $indeks[$klucz];
            $planety[$i]['instalacje'] = array_merge($planety[$i]['instalacje'] ?? [], $instalacje);
        } else {
            $planety[] = ['nazwa' => $w['nazwa'], 'opis' => 'Planeta pod władzą frakcji.', 'instalacje' => $instalacje];
            $indeks[$klucz] = count($planety) - 1;
        }
    }
    return $planety;
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
obsluz_floty($akcja, $post);
obsluz_poklad($akcja, $post);
obsluz_gielda($akcja, $post);
obsluz_czas($akcja, $post);
obsluz_astro($akcja, $post);
obsluz_gwiazdy($akcja, $post);

switch ($akcja) {

    // ----- ustawienia interfejsu konta (np. kultury w Kalendarzu) -----

    case 'ustawienie':
        $u = wymagaj_logowania();
        if (!tabela_sql_dostepna('ustawienia_kont')) odpowiedz(['wartosc' => null]);
        $q = polaczenie_bazy()->prepare('SELECT wartosc FROM ustawienia_kont WHERE konto_login=? AND klucz=?');
        $q->execute([(string) $u['login'], mb_substr((string) ($_GET['klucz'] ?? ''), 0, 60)]);
        $v = $q->fetchColumn();
        odpowiedz(['wartosc' => $v === false ? null : json_decode((string) $v, true)]);

    case 'ustawienie_zapisz':
        if (!$post) blad('Zła metoda.', 405);
        $u = wymagaj_logowania();
        if (!tabela_sql_dostepna('ustawienia_kont')) odpowiedz(['ok' => false]);
        $klucz = mb_substr(trim((string) (wejscie()['klucz'] ?? '')), 0, 60);
        $wartosc = json_encode(wejscie()['wartosc'] ?? null, JSON_UNESCAPED_UNICODE);
        if ($klucz === '' || strlen($wartosc) > 2000) blad('Nieprawidłowe ustawienie.');
        polaczenie_bazy()->prepare('INSERT INTO ustawienia_kont (konto_login, klucz, wartosc) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE wartosc = ?')
            ->execute([(string) $u['login'], $klucz, $wartosc, $wartosc]);
        odpowiedz(['ok' => true]);

    case 'mapa_dane':
        $u = wymagaj_logowania();
        if (($u['rola'] ?? '') === 'mg') {
            $planety = polaczenie_bazy()->query('SELECT nazwa,region,x,y,grawitacja,slonca,ksiezyce,doba,rok,srednica FROM mapa_planety ORDER BY id')
                ->fetchAll(PDO::FETCH_NUM);
        } elseif (tabela_sql_dostepna('planety_gry') && tabela_sql_dostepna('planety_widocznosc_konta')) {
            $q = polaczenie_bazy()->prepare('SELECT p.nazwa,p.region,p.x,p.y,p.grawitacja,p.slonca,p.ksiezyce,p.doba,p.rok,p.srednica
                FROM mapa_planety p LEFT JOIN planety_gry g ON g.planeta_id=p.id
                WHERE COALESCE(g.zastrzezona,0)=0 OR EXISTS (
                    SELECT 1 FROM planety_widocznosc_konta d WHERE d.planeta_id=p.id AND LOWER(d.konto_login)=LOWER(?)
                ) ORDER BY p.id');
            $q->execute([$u['login']]);
            $planety = $q->fetchAll(PDO::FETCH_NUM);
        } else {
            $planety = polaczenie_bazy()->query('SELECT p.nazwa,p.region,p.x,p.y,p.grawitacja,p.slonca,p.ksiezyce,p.doba,p.rok,p.srednica
                FROM mapa_planety p LEFT JOIN planety_gry g ON g.planeta_id=p.id WHERE COALESCE(g.zastrzezona,0)=0 ORDER BY p.id')
                ->fetchAll(PDO::FETCH_NUM);
        }
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

    case 'zaproszenie_aktywuj':
        // Pierwsze wejście z zaproszenia: login + jednorazowy kod + własne hasło (dwa razy).
        if (!$post) blad('Zła metoda.', 405);
        $proby = $_SESSION['proby'] ?? 0;
        if ($proby >= 5) sleep(2);
        $w = wejscie();
        $login = mb_strtolower(pole('login', 64));
        $kod = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($w['kod'] ?? '')));
        $kod = strlen($kod) === 8 ? substr($kod, 0, 4) . '-' . substr($kod, 4) : $kod;
        $klucz = (string) ($w['klucz'] ?? '');
        if (mb_strlen($klucz) < 6) blad('Hasło musi mieć co najmniej 6 znaków.');
        if ($klucz !== (string) ($w['klucz2'] ?? '')) blad('Hasła się różnią.');
        $wynik = zmien('uzytkownicy', function (&$us) use ($login, $kod, $klucz) {
            foreach ($us as &$x) {
                if (mb_strtolower($x['login']) !== $login || empty($x['zaproszenie'])) continue;
                if (time() > (int) $x['zaproszenie']['wazne_do']) return 'wygaslo';
                if (!password_verify($kod, $x['zaproszenie']['hash'])) return null;
                $x['hash'] = password_hash($klucz, PASSWORD_DEFAULT);
                unset($x['zaproszenie']);
                return $x;
            }
            return null;
        });
        if ($wynik === 'wygaslo') blad('Zaproszenie wygasło. Poproś MG o nowy kod.');
        if (!is_array($wynik)) {
            $_SESSION['proby'] = $proby + 1;
            usleep(400000);
            blad('Nieprawidłowy identyfikator albo kod zaproszenia.', 401);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = $wynik['id'];
        $_SESSION['proby'] = 0;
        odpowiedz(['uzytkownik' => bez_hasla($wynik), 'nowe_konto' => true]);

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
        $f['planety'] = frakcja_planety_z_rejestru((string) $u['frakcja'], $f['planety'] ?? [], $u);
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
        odpowiedz(['uzytkownicy' => array_map(fn($u) => bez_hasla($u) + (($u['rola'] ?? '') === 'mg' ? [] : ['gotowosc' => gotowosc_gracza($u)]), uzytkownicy())]);

    case 'mg_zaproszenie_nowe':
        // Nowy kod zaproszenia dla istniejącego konta (zgubiony albo wygasły kod; reset hasła gracza).
        if (!$post) blad('Zła metoda.', 405);
        wymagaj_mg();
        $uid = (string) (wejscie()['id'] ?? '');
        [$kod, $zapr] = zaproszenie_nowe();
        $ok = zmien('uzytkownicy', function (&$us) use ($uid, $zapr) {
            foreach ($us as &$x) if ($x['id'] === $uid && ($x['rola'] ?? '') !== 'mg') { $x['zaproszenie'] = $zapr; $x['hash'] = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT); return true; }
            return false;
        });
        if (!$ok) blad('Nie ma takiego konta gracza.', 404);
        odpowiedz(['ok' => true, 'kod' => $kod, 'wazne_do' => $zapr['wazne_do']]);

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
        // Nowe konto gracza z zaproszeniem: bez hasła od MG — gracz ustawi je sam kodem jednorazowym.
        $zaproszenie = $uid === '' && !empty($w['zaproszenie']) && $dane['rola'] === 'gracz' ? zaproszenie_nowe() : null;
        $noweId = id();
        $wynik = zmien('uzytkownicy', function (&$us) use ($dane, $klucz, $uid, $zaproszenie, $noweId) {
            foreach ($us as $x) {
                if (mb_strtolower($x['login']) === mb_strtolower($dane['login']) && $x['id'] !== $uid) return 'zajety';
            }
            if ($uid === '') {
                if ($zaproszenie) {
                    $us[] = ['id' => $noweId, 'hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'zaproszenie' => $zaproszenie[1]] + $dane;
                    return 'ok';
                }
                if ($klucz === '') return 'brak_klucza';
                $us[] = ['id' => $noweId, 'hash' => password_hash($klucz, PASSWORD_DEFAULT)] + $dane;
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
        odpowiedz(['ok' => true, 'id' => $uid ?: $noweId] + ($zaproszenie ? ['kod' => $zaproszenie[0], 'wazne_do' => $zaproszenie[1]['wazne_do']] : []));

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


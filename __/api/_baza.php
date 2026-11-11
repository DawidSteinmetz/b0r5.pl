<?php
// Rdzeń szkieletu: ustawienia postaci, dziennik zdarzeń (SQLite) i przedmioty przenośne.
// Nie wie nic o konkretnych pokojach. Świat to sandbox: działa bezobsługowo i niczego się w nim nie zeruje.
// Pokój z własną mechaniką trzyma ją u siebie: <pokoj>/api/ (PHP), <pokoj>/_logika/ (dane zamknięte przed przeglądarką).
define('KORZEN', dirname(__DIR__));

function bezpieczne($s) { return is_string($s) && preg_match('/^[a-z0-9_-]+$/', $s); }

function wczytaj_json($p) {
    $t = @file_get_contents($p);
    if ($t === false) return null;
    $j = json_decode($t, true);
    return is_array($j) ? $j : null;
}

// Ustawienia tej myśloodsiewni (postac.json): nazwa, rok, strefa czasowa, radio.
function postac() {
    static $s = null;
    if ($s !== null) return $s;
    return $s = array_merge(['nazwa' => 'Myśloodsiewnia', 'rok' => 1914, 'strefa' => 'Europe/Warsaw'],
        wczytaj_json(KORZEN . '/postac.json') ?: []);
}

function pokoj_dane($id) { return bezpieczne($id) ? (wczytaj_json(KORZEN . '/' . $id . '/pokoj.json') ?: []) : []; }
function pokoj_nazwa($id) { $p = pokoj_dane($id); return !empty($p['nazwa']) ? $p['nazwa'] : $id; }
// "w piwnicy", "na strychu": pole "gdzie" w pokoj.json; bez niego "w pokoju „Nazwa”"
function pokoj_gdzie($id) { $p = pokoj_dane($id); return !empty($p['gdzie']) ? $p['gdzie'] : 'w pokoju „' . pokoj_nazwa($id) . '”'; }

// --- Przedmioty przenośne ------------------------------------------------------------------------
// Przedmiot, który można przenieść, ma osobny plik w każdym pokoju, w którym może leżeć. Pokój deklaruje go
// w pokoj.json: "przenosne": {"nazwa-grupy": {"plik": "folder/plik", "istnienie": "on" albo "off"}}.
// "on" to miejsce na starcie. Grupę tworzą pliki o tej samej nazwie z różnych pokoi; bieżące miejsce wynika z dziennika.
// Plik bez bliźniaka w innym pokoju jest zwykłym przedmiotem. Przedmiotów złożonych (np. kociołka) się tak nie deklaruje.
function przenosne_grupy() {
    static $g = null;
    if ($g !== null) return $g;
    $m = [];
    foreach (glob(KORZEN . '/*/pokoj.json') ?: [] as $f) {
        $pid = basename(dirname($f));
        if (!bezpieczne($pid) || $pid[0] === '_') continue;
        $p = wczytaj_json($f);
        if (!$p || !is_array($p['przenosne'] ?? null)) continue;
        foreach ($p['przenosne'] as $nazwa => $def) {
            $plik = is_array($def) ? (string)($def['plik'] ?? '') : '';
            if (!bezpieczne((string)$nazwa) || !preg_match('#^[a-z0-9_-]+/[a-z0-9_-]+$#', $plik)) continue;
            $m[$nazwa][$pid . '/' . $plik] = (($def['istnienie'] ?? 'off') === 'on');
        }
    }
    $g = [];
    foreach ($m as $nazwa => $miejsca) {
        if (count($miejsca) < 2) continue;
        $on = array_keys(array_filter($miejsca));
        $ks = array_keys($miejsca);
        $g[$nazwa] = ['miejsca' => $ks, 'start' => $on ? $on[0] : $ks[0]];
    }
    return $g;
}
function przenosne_grupa($kid) {
    foreach (przenosne_grupy() as $n => $g) if (in_array($kid, $g['miejsca'], true)) return $n;
    return null;
}
function przenosne_start() {
    $a = [];
    foreach (przenosne_grupy() as $n => $g) $a[$n] = $g['start'];
    return $a;
}
// Nakłada zdarzenie "przeniesiono" na mapę [grupa => kid pliku, który ma teraz istnienie "on"].
function przenosne_zastosuj(&$akt, $grupa, $d) {
    $gr = przenosne_grupy();
    $do = (string)(is_array($d) ? ($d['do'] ?? '') : '');
    if (isset($gr[$grupa]) && in_array($do, $gr[$grupa]['miejsca'], true)) $akt[$grupa] = $do;
}

// --- Dziennik zdarzeń ----------------------------------------------------------------------------
function baza() {
    static $db = null;
    if ($db) return $db;
    $dir = KORZEN . '/_dane';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $db = new PDO('sqlite:' . $dir . '/stan.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA busy_timeout=3000');
    $db->exec('CREATE TABLE IF NOT EXISTS zdarzenia(id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL,'
        . ' typ TEXT NOT NULL, cel TEXT, gracz TEXT, ip TEXT, dane TEXT)');
    $db->exec('CREATE INDEX IF NOT EXISTS zdarzenia_ip ON zdarzenia(ip, ts)');
    $db->exec('CREATE INDEX IF NOT EXISTS zdarzenia_typ ON zdarzenia(typ)');
    return $db;
}

function ip_hash() { return substr(sha1(($_SERVER['REMOTE_ADDR'] ?? '') . 'myslodsiewnia'), 0, 10); }

function dodaj_zdarzenie($typ, $cel = '', $gracz = '', $dane = null) {
    $db = baza();
    $st = $db->prepare('INSERT INTO zdarzenia(ts,typ,cel,gracz,ip,dane) VALUES(?,?,?,?,?,?)');
    $st->execute([time(), $typ, $cel, $gracz, ip_hash(),
        $dane === null ? null : json_encode($dane, JSON_UNESCAPED_UNICODE)]);
    return (int)$db->lastInsertId();
}

// Ochrona przed zalewaniem: najwyżej 60 zdarzeń na minutę z jednego adresu.
function za_szybko() {
    $st = baza()->prepare('SELECT COUNT(*) FROM zdarzenia WHERE ip=? AND ts>?');
    $st->execute([ip_hash(), time() - 60]);
    return (int)$st->fetchColumn() >= 60;
}

// Wspólny stan świata: co zostało przeczytane i gdzie są teraz przedmioty przenośne.
function stan() {
    $przecz = []; $miejsca = przenosne_start();
    foreach (baza()->query("SELECT typ,cel,dane FROM zdarzenia WHERE typ IN ('otwarto','przeniesiono') ORDER BY id") as $z) {
        if ($z['typ'] === 'otwarto') $przecz[$z['cel']] = true;
        else przenosne_zastosuj($miejsca, (string)$z['cel'], json_decode((string)$z['dane'], true));
    }
    return ['przeczytane' => array_keys($przecz), 'miejsca' => $miejsca];
}

// Ścieżka pliku elementu o identyfikatorze "pokoj/folder/nazwa" albo null. Foldery z "_" nie są elementami.
function sciezka_elementu($kid) {
    if (!preg_match('#^([a-z0-9_-]+)/([a-z0-9_-]+)/([a-z0-9_-]+)$#', (string)$kid, $m)) return null;
    if ($m[1][0] === '_' || $m[2][0] === '_') return null;
    foreach (['md', 'txt'] as $e) {
        $p = KORZEN . "/$m[1]/$m[2]/$m[3].$e";
        if (is_file($p)) return $p;
    }
    return null;
}

// Liczba listów w skrzynce pokoju, który odbiera pocztę (<pokoj>/_logika/poczta_odbior.json; patrz api/poczta_odbierz.php).
// Klient po zmianie liczby przeładowuje pokój, więc nowy list pojawia się bez odświeżania strony.
function skrzynka_licznik() {
    $pliki = glob(KORZEN . '/*/_logika/poczta_odbior.json') ?: [];
    $c = $pliki ? wczytaj_json($pliki[0]) : null;
    if (!is_array($c)) return 0;
    $pokoj = (string)($c['pokoj'] ?? 'gabinet'); $folder = (string)($c['folder'] ?? 'przedmioty');
    if (!bezpieczne($pokoj) || !bezpieczne($folder)) return 0;
    return count(glob(KORZEN . "/$pokoj/$folder/8??-list-od-*.md") ?: []);
}

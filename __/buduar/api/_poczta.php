<?php
// Sekretarzyk do listów między myśloodsiewniami. Stan (szkice, złamane pieczęcie, wysłane listy) liczy się z dziennika zdarzeń.
// Sekrety (klucz, odpowiedzi) są w _logika/poczta.json i nie trafiają do przeglądarki.
require_once dirname(__DIR__, 2) . '/api/_baza.php';
if (!defined('KAT_POKOJU')) define('KAT_POKOJU', dirname(__DIR__));

const POCZTA_MAX = 4000;
const POCZTA_MIN = 20;

function poczta_cfg() {
    static $c = false;
    if ($c === false) $c = wczytaj_json(KAT_POKOJU . '/_logika/poczta.json');
    return $c;
}
function poczta_adresat($id) {
    foreach ((poczta_cfg()['adresaci'] ?? []) as $a) if (($a['id'] ?? '') === $id) return $a;
    return null;
}
function poczta_norm($t) { return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower((string)$t, 'UTF-8')); }
function poczta_gracz($t) { return substr(preg_replace('/[^a-z0-9]/', '', strtolower((string)$t)), 0, 12); }
function poczta_gotowa($adr) { $k = (string)($adr['klucz'] ?? ''); return $k !== '' && $k !== 'zmien-mnie'; }

function poczta_stan() {
    $c = poczta_cfg();
    if (!$c || empty($c['adresaci'])) return null;
    $szk = []; $piec = []; $wys = [];
    foreach (baza()->query("SELECT * FROM zdarzenia WHERE typ IN ('szkic','pieczec','list_wyslany') ORDER BY id") as $z) {
        $a = (string)$z['cel'];
        $d = $z['dane'] ? json_decode($z['dane'], true) : [];
        if (!is_array($d)) $d = [];
        $wersja = ($szk[$a]['wersja'] ?? 0) + 1;
        switch ($z['typ']) {
            case 'szkic': $szk[$a] = ['tresc' => (string)($d['tresc'] ?? ''), 'wersja' => $wersja]; break;
            case 'pieczec': $piec[$a] = true; break;
            case 'list_wyslany':                                  // po wysłaniu kartka jest znów czysta
                $wys[$a][] = ['tresc' => (string)($d['tresc'] ?? ''), 'ts' => (int)$z['ts']];
                $szk[$a] = ['tresc' => '', 'wersja' => $wersja];
                break;
        }
    }
    return ['cfg' => $c, 'szk' => $szk, 'piec' => $piec, 'wys' => $wys];
}

function poczta_publiczna($w) {
    $a = [];
    foreach ($w['cfg']['adresaci'] as $x) {
        $id = $x['id'];
        $a[] = [
            'id' => $id, 'nazwa' => $x['nazwa'] ?? $id, 'pieczec' => isset($w['piec'][$id]), 'droga' => poczta_gotowa($x),
            'szkic' => $w['szk'][$id] ?? ['tresc' => '', 'wersja' => 0],
            'wyslane' => array_slice(array_reverse($w['wys'][$id] ?? []), 0, 5),
        ];
    }
    return ['nadawca' => $w['cfg']['nadawca']['nazwa'] ?? '', 'min' => POCZTA_MIN, 'max' => POCZTA_MAX, 'adresaci' => $a];
}

// Wysyłka do innej myśloodsiewni: POST {url}/api/poczta_odbierz.php, podpis HMAC-SHA256 w nagłówku X-Podpis.
// Zwraca 'ok' albo krótki opis błędu z drugiej strony.
function poczta_wyslij($adr, $nad, $tresc) {
    $url = rtrim((string)($adr['url'] ?? ''), '/') . '/api/poczta_odbierz.php';
    if (!preg_match('#^https?://#', $url)) return 'zly adres';
    $body = json_encode(['od_id' => $nad['id'], 'od_nazwa' => $nad['nazwa'], 'do_id' => $adr['id'], 'tresc' => $tresc, 'ts' => time()], JSON_UNESCAPED_UNICODE);
    $naglowki = ['Content-Type: application/json', 'X-Podpis: ' . hash_hmac('sha256', $body, (string)$adr['klucz'])];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $naglowki,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false]);
        $odp = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $naglowki), 'content' => $body, 'timeout' => 10, 'ignore_errors' => true]]);
        $odp = @file_get_contents($url, false, $ctx);
    }
    $j = is_string($odp) ? json_decode($odp, true) : null;
    if (is_array($j) && !empty($j['ok'])) return 'ok';
    return is_array($j) ? (string)($j['blad'] ?? 'blad') : 'brak odpowiedzi';
}

<?php
// Rynek dla innych myśloodsiewni (Henryk jest serwerem głównym). POST JSON podpisany HMAC-SHA256 w nagłówku X-Podpis
// kluczem domu z _logika/rynek.json ("domy"). Dom płaci z własnego budżetu, a tu zostawia tylko ślad popytu:
//   {"od_id":"artemida","akcja":"cennik","ts":...}                                  -> ceny wszystkich towarów
//   {"od_id":"artemida","akcja":"kup","towar":"bylica","n":2,"id_zakupu":"abc123","ts":...} -> {ok, koszt, ceny}
// Zakup podnosi ceny dla wszystkich (także Henryka), a brak zakupów obniża je z czasem.
require_once __DIR__ . '/_rynek.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function wyj_z($d, $k = 200) { http_response_code($k); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wyj_z(['blad' => 'tylko POST'], 405);
$c = rynek_cfg(); if (!$c || empty($c['domy'])) wyj_z(['blad' => 'rynek nieskonfigurowany'], 503);
$body = (string)file_get_contents('php://input');
if (strlen($body) > 4000) wyj_z(['blad' => 'za duzy'], 413);
$d = json_decode($body, true); if (!is_array($d)) wyj_z(['blad' => 'zle zadanie'], 400);
$od = (string)($d['od_id'] ?? '');
if (!preg_match('/^[a-z0-9_-]{1,30}$/', $od) || empty($c['domy'][$od])) wyj_z(['blad' => 'nieznany dom'], 403);
$klucz = (string)($c['domy'][$od]['klucz'] ?? '');
if ($klucz === '' || $klucz === 'zmien-mnie') wyj_z(['blad' => 'dom nieskonfigurowany'], 503);
if (!hash_equals(hash_hmac('sha256', $body, $klucz), (string)($_SERVER['HTTP_X_PODPIS'] ?? ''))) wyj_z(['blad' => 'zly podpis'], 403);
if (abs(time() - (int)($d['ts'] ?? 0)) > 300) wyj_z(['blad' => 'przeterminowane'], 400);

try {
    $o = rynek_stan();
    $akcja = (string)($d['akcja'] ?? '');
    if ($akcja === 'cennik') {
        $t = []; foreach (rynek_publiczny($o, true)['towary'] as $x) $t[$x['id']] = ['cena' => $x['cena'], 'poziom' => $x['poziom']];
        wyj_z(['ok' => true, 'towary' => $t]);
    }
    if ($akcja === 'kup') {
        $slug = (string)($d['towar'] ?? ''); $n = (int)($d['n'] ?? 0); $idz = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($d['id_zakupu'] ?? ''));
        if (!isset($c['towary'][$slug]) || $n < 1 || $n > (int)$c['zakup']['max_pozycja']) wyj_z(['blad' => 'zle zamowienie'], 400);
        if (strlen($idz) < 6) wyj_z(['blad' => 'brak id_zakupu'], 400);
        $db = baza(); $db->exec('BEGIN IMMEDIATE');
        try {
            $q = $db->prepare("SELECT dane FROM zdarzenia WHERE typ='zakup' AND gracz=? AND dane LIKE ?"); $q->execute([$od, '%"id_zakupu":"' . $idz . '"%']);
            if ($q->fetchColumn() !== false) { $db->exec('ROLLBACK'); wyj_z(['ok' => true, 'powtorka' => true]); }   // ten sam zakup drugi raz
            $w = rynek_wycena(rynek_odczytaj(), $slug, $n);
            dodaj_zdarzenie('zakup', $slug, $od, ['n' => $n, 'koszt' => $w['suma'], 'dom' => $od, 'id_zakupu' => $idz]);
            $db->exec('COMMIT');
        } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
        wyj_z(['ok' => true, 'koszt' => $w['suma'], 'ceny' => $w['ceny']]);
    }
    wyj_z(['blad' => 'nieznana akcja'], 400);
} catch (Throwable $e) {
    wyj_z(['blad' => 'blad serwera'], 500);
}

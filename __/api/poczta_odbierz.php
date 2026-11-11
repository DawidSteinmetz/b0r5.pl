<?php
// Odbiór listu z innej myślodsiewni. Samodzielny plik (nie zależy od reszty API), więc można go wrzucić
// też do starszej wersji myślodsiewni. Konfiguracja: <pokoj>/_logika/poczta_odbior.json (wzór: poczta_odbior.json.przyklad).
// List ląduje jako zwykły plik .md w <pokoj>/<folder>/ i od razu jest widoczny jako dokument.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
define('KORZEN_ODB', dirname(__DIR__));
function wyj_odb($d, $k = 200) { http_response_code($k); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wyj_odb(['blad' => 'tylko POST'], 405);
$pliki = glob(KORZEN_ODB . '/*/_logika/poczta_odbior.json') ?: [];   // ustawienia leżą w pokoju, który odbiera listy
$cfg = $pliki ? json_decode((string)@file_get_contents($pliki[0]), true) : null;
if (!is_array($cfg) || empty($cfg['nadawcy'])) wyj_odb(['blad' => 'odbior nieskonfigurowany'], 503);
$body = (string)file_get_contents('php://input');
if (strlen($body) > 16000) wyj_odb(['blad' => 'za duzy'], 413);
$d = json_decode($body, true);
if (!is_array($d)) wyj_odb(['blad' => 'zle zadanie'], 400);

$od = (string)($d['od_id'] ?? '');
if (!preg_match('/^[a-z0-9_-]{1,30}$/', $od) || empty($cfg['nadawcy'][$od]) || !is_array($cfg['nadawcy'][$od])) wyj_odb(['blad' => 'nieznany nadawca'], 403);
$n = $cfg['nadawcy'][$od];
$klucz = (string)($n['klucz'] ?? '');
if ($klucz === '' || $klucz === 'zmien-mnie') wyj_odb(['blad' => 'odbior nieskonfigurowany'], 503);
if (!hash_equals(hash_hmac('sha256', $body, $klucz), (string)($_SERVER['HTTP_X_PODPIS'] ?? ''))) wyj_odb(['blad' => 'zly podpis'], 403);
if (abs(time() - (int)($d['ts'] ?? 0)) > 86400) wyj_odb(['blad' => 'przeterminowane'], 400);

$t = str_replace("\r", '', (string)($d['tresc'] ?? ''));
$t = preg_replace('/[^\P{C}\n]/u', '', $t);                 // bez znaków sterujących poza nową linią
$t = trim(preg_replace("/\n{3,}/", "\n\n", (string)$t));
$dl = mb_strlen($t, 'UTF-8');
if ($dl < 5 || $dl > 4000) wyj_odb(['blad' => 'zla dlugosc'], 400);

$pokoj = (string)($cfg['pokoj'] ?? 'gabinet'); $folder = (string)($cfg['folder'] ?? 'przedmioty');
if (!preg_match('/^[a-z0-9_-]+$/', $pokoj) || !preg_match('/^[a-z0-9_-]+$/', $folder)) wyj_odb(['blad' => 'zla konfiguracja'], 503);
$dir = KORZEN_ODB . "/$pokoj/$folder";
if (!is_dir($dir)) wyj_odb(['blad' => 'brak folderu'], 503);

// powtórka (ten sam list wysłany drugi raz) i limit dzienny
$ddir = KORZEN_ODB . '/_dane';
if (!is_dir($ddir)) @mkdir($ddir, 0775, true);
$znacznik = $ddir . '/poczta_' . sha1($od . '|' . $t) . '.lock';
if (is_file($znacznik)) wyj_odb(['ok' => true, 'powtorka' => true]);
$dzis = date('Ymd');
if (count(glob("$dir/*-list-od-$od-$dzis-*.md") ?: []) >= (int)($cfg['limit_dzienny'] ?? 10)) wyj_odb(['blad' => 'limit dzienny'], 429);

$nazwa = trim(preg_replace('/[^\p{L}\p{N} .\-]/u', '', (string)($n['nazwa'] ?? $od)));
$tytul = trim(preg_replace('/[^\p{L}\p{N} .\-]/u', '', (string)($n['tytul'] ?? ('List od ' . $nazwa))));
$nr = 800 + count(glob("$dir/8??-list-od-*.md") ?: []) + 1;
if ($nr > 999) wyj_odb(['blad' => 'skrzynka pelna'], 507);
$plik = sprintf('%s/%03d-list-od-%s-%s-%s.md', $dir, $nr, $od, $dzis, date('His'));
if (@file_put_contents($plik, "# $tytul\n\n$t\n", LOCK_EX) === false) wyj_odb(['blad' => 'zapis nie powiodl sie'], 500);
@file_put_contents($znacznik, (string)time());
wyj_odb(['ok' => true]);

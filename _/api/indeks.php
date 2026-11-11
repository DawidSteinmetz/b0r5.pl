<?php
// Skanuje foldery na bieżąco, więc nowy pokój, książka czy przedmiot pojawia się po wgraniu pliku.
// Bez parametrów: lista pokoi. Z ?pokoj=id: dane pokoju i jego elementy.
require __DIR__ . '/_baza.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$flagi = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

function tytul($plik, $domyslny) {
    $h = @fopen($plik, 'r');
    if (!$h) return $domyslny;
    $t = $domyslny; $n = 0;
    while (($l = fgets($h)) !== false && $n < 10) {
        $n++;
        $l = trim(preg_replace('/^\xEF\xBB\xBF/', '', $l));
        if (preg_match('/^#\s+(.+)$/u', $l, $m)) { $t = trim($m[1]); break; }
    }
    fclose($h);
    return $t;
}
function pliki_folderu($sciezka) {
    $p = array_merge(glob("$sciezka/*.md") ?: [], glob("$sciezka/*.txt") ?: []);
    sort($p);
    return $p;
}

$id = isset($_GET['pokoj']) ? $_GET['pokoj'] : '';

if ($id === '') {
    $pokoje = [];
    foreach (glob(KORZEN . '/*/pokoj.json') ?: [] as $f) {
        $pid = basename(dirname($f));
        if (!bezpieczne($pid) || $pid[0] === '_') continue;
        $p = wczytaj_json($f);
        if ($p === null) continue;
        $pokoje[] = [
            'id' => $pid,
            'nazwa' => isset($p['nazwa']) ? $p['nazwa'] : $pid,
            'opis' => isset($p['opis']) ? $p['opis'] : '',
            'ikona' => isset($p['ikona']) ? $p['ikona'] : '',
            'kolejnosc' => isset($p['kolejnosc']) ? $p['kolejnosc'] : 999,
        ];
    }
    usort($pokoje, function ($a, $b) {
        return [$a['kolejnosc'], $a['nazwa']] <=> [$b['kolejnosc'], $b['nazwa']];
    });
    echo json_encode(['pokoje' => $pokoje], $flagi);
    exit;
}

if (!bezpieczne($id) || $id[0] === '_') { http_response_code(400); echo '{"blad":"zly identyfikator"}'; exit; }
$p = wczytaj_json(KORZEN . '/' . $id . '/pokoj.json');
if ($p === null) { http_response_code(404); echo '{"blad":"nie ma takiego pokoju"}'; exit; }

$p['id'] = $id;
// Znacznik wersji własnych plików pokoju: przeglądarka pobiera je na nowo po każdym wgraniu.
$p['wersja'] = 0;
foreach (['skrypt', 'styl'] as $pole) {
    if (!empty($p[$pole])) $p['wersja'] = max($p['wersja'], (int)@filemtime(KORZEN . "/$id/" . basename((string)$p[$pole])));
}
$foldery = (isset($p['foldery']) && is_array($p['foldery']) && count($p['foldery']))
    ? $p['foldery'] : [['nazwa' => 'przedmioty', 'rodzaj' => 'przedmiot']];

try { $miejsca = stan()['miejsca']; } catch (Throwable $e) { $miejsca = przenosne_start(); }

$elementy = [];
foreach ($foldery as $fo) {
    $nazwa = isset($fo['nazwa']) ? $fo['nazwa'] : '';
    $rodzaj = isset($fo['rodzaj']) ? $fo['rodzaj'] : 'przedmiot';
    if (!bezpieczne($nazwa) || $nazwa[0] === '_') continue;
    foreach (pliki_folderu(KORZEN . "/$id/$nazwa") as $f) {
        $b = basename($f);
        $eid = pathinfo($b, PATHINFO_FILENAME);
        $kid = "$id/$nazwa/$eid";
        $el = ['id' => $eid, 'kid' => $kid, 'tytul' => tytul($f, $eid), 'plik' => "$id/$nazwa/$b", 'rodzaj' => $rodzaj];
        $grupa = przenosne_grupa($kid);
        if ($grupa !== null) {                             // przenośny: widać tylko plik z istnieniem "on"
            if (($miejsca[$grupa] ?? null) !== $kid) continue;
            $el['przenies'] = [];
            foreach (przenosne_grupy()[$grupa]['miejsca'] as $m) {
                if ($m !== $kid) $el['przenies'][] = ['kid' => $m, 'nazwa' => pokoj_nazwa(explode('/', $m)[0])];
            }
        }
        $elementy[] = $el;
    }
}
echo json_encode(['pokoj' => $p, 'elementy' => $elementy], $flagi);

<?php
// Giełda CDEX: spółki i towary notowane na giełdach planetarnych.
//
// Giełdy, instrumenty, kursy, portfele i transakcje są przechowywane w SQL.
//
// SKRYPT KURSÓW. Każdy dzień liczony jest z poprzedniego:
//   ln P(d) = ln P(d−1) + powrót · (ln F(d) − ln P(d−1)) + σ · niepewność · ε(symbol, d)
// F to „wartość godziwa”: cena bazowa × mnożniki aktywnych wydarzeń (wydarzenia.php).
// Bez wydarzeń kurs błądzi wokół ceny bazowej (zwykłe wahania). Wydarzenie przesuwa F,
// a kurs w kilka dni dochodzi do nowego poziomu; po końcu wydarzenia wraca.
// ε jest wyznaczone przez symbol i datę, więc podgląd MG pokazuje dokładnie tę przyszłość,
// która nastąpi, jeśli nikt nie zmieni wydarzeń. Dni dopisują się same przy wejściu na giełdę.

declare(strict_types=1);

const GIELDA_HISTORIA_TRANSAKCJI = 60;
const GIELDA_MAX_DNI = 730;

function gielda_ustawienia_domyslne(): array
{
    return ['powrot' => 0.08, 'zmiennosc_spolka' => 0.018, 'zmiennosc_towar' => 0.025, 'prowizja' => 0.005, 'historia_dni' => 365];
}

function gielda_klucz_nazwy(string $nazwa): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $nazwa) ?? $nazwa));
}

function gielda_nowy_symbol(string $nazwa, array $uzyte): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nazwa);
    $baza = strtoupper(preg_replace('/[^A-Z0-9]/', '', $ascii === false ? '' : $ascii) ?? '');
    $baza = substr($baza ?: 'TOWAR', 0, 4);
    for ($i = 0; $i < 1000; $i++) {
        $symbol = $i === 0 ? substr($baza, 0, 6) : substr($baza, 0, 4) . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        if (strlen($symbol) < 2) $symbol = 'TW' . $symbol;
        if (!in_array($symbol, $uzyte, true)) return $symbol;
    }
    blad('Nie udało się nadać unikalnego symbolu giełdowego.');
}

// Normalizuje spółki katalogu i ogranicza status do dwóch jawnych wartości.
function gielda_spolki_z_katalogu(array $lista, array $zajeteSymbole = []): array
{
    $wynik = [];
    $symbole = [];
    foreach ($lista as $s) {
        if (!is_array($s)) blad('Każda spółka musi być obiektem.');
        $nazwa = mb_substr(trim((string) ($s['nazwa'] ?? '')), 0, 80);
        if ($nazwa === '') blad('Każda spółka musi mieć nazwę.');
        $symbol = mb_strtoupper(trim((string) ($s['symbol'] ?? '')));
        if ($symbol === '') $symbol = gielda_nowy_symbol($nazwa, [...$zajeteSymbole, ...$symbole]);
        if (!preg_match('/^[A-Z0-9]{2,6}$/', $symbol)) blad("Symbol spółki „{$symbol}”: 2–6 wielkich liter lub cyfr.");
        if (in_array($symbol, $symbole, true)) blad("Symbol spółki {$symbol} powtarza się.");
        $symbole[] = $symbol;
        $czysta = ['symbol' => $symbol, 'nazwa' => $nazwa,
            'branza' => mb_strtolower(mb_substr(trim((string) ($s['branza'] ?? '')), 0, 40)),
            'status' => ($s['status'] ?? '') === 'poza_gielda' ? 'poza_gielda' : 'notowana',
            'gielda' => mb_strtolower(trim((string) ($s['gielda'] ?? ''))),
            'cena' => max(1, (float) ($s['cena'] ?? 100)),
            'pochodzenie' => mb_substr(trim((string) ($s['pochodzenie'] ?? '')), 0, 80)];
        foreach (['firma_id', 'profil', 'skala', 'kapital_startowy', 'menedzer_id', 'menedzer', 'funkcja_menedzera'] as $klucz) {
            if (array_key_exists($klucz, $s)) $czysta[$klucz] = $s[$klucz];
        }
        $wynik[] = $czysta;
    }
    return $wynik;
}

// Każdy towar katalogu ma instrument. Dopasowanie opiera się na zapisanym symbolu,
// identyfikatorze katalogowym, a dopiero potem na nazwie (dla starszych danych).
function gielda_towary_z_katalogu(array $towary, array $instrumenty, array $gieldy): array
{
    $uzyte = array_column($instrumenty, 'symbol');
    $domyslnaGielda = $gieldy[0]['id'] ?? '';
    foreach ($towary as $t) {
        $znaleziony = null;
        foreach ($instrumenty as $idx => $i) {
            if (($i['rodzaj'] ?? '') !== 'towar') continue;
            if (($i['symbol'] ?? '') === ($t['gielda_symbol'] ?? '') || ($i['katalog_id'] ?? '') === ($t['id'] ?? '')
                || gielda_klucz_nazwy($i['nazwa'] ?? '') === gielda_klucz_nazwy($t['nazwa'] ?? '')) { $znaleziony = $idx; break; }
        }
        if ($znaleziony === null) {
            $symbol = gielda_nowy_symbol((string) $t['nazwa'], $uzyte);
            $uzyte[] = $symbol;
            $instrumenty[] = ['symbol' => $symbol, 'nazwa' => $t['nazwa'], 'rodzaj' => 'towar',
                'kategoria' => $t['kategoria'] ?? '', 'gielda' => $domyslnaGielda,
                'cena' => max(1, (float) ($t['cena'] ?? 1)), 'jednostka' => $t['jednostka'] ?? 'szt.',
                'pochodzenie' => '', 'katalog_id' => $t['id']];
            continue;
        }
        $i = $instrumenty[$znaleziony];
        $instrumenty[$znaleziony] = array_merge($i, ['nazwa' => $t['nazwa'], 'rodzaj' => 'towar',
            'kategoria' => $t['kategoria'] ?? '', 'cena' => max(1, (float) ($t['cena'] ?? 1)),
            'jednostka' => $t['jednostka'] ?? 'szt.', 'katalog_id' => $t['id']]);
    }
    return $instrumenty;
}

function gielda_spolki_do_instrumentow(array $spolki, array $instrumenty, array $gieldy): array
{
    $domyslnaGielda = $gieldy[0]['id'] ?? '';
    foreach ($spolki as $s) {
        $idx = null;
        foreach ($instrumenty as $j => $i) {
            if (($i['rodzaj'] ?? '') === 'spolka' && ($i['symbol'] ?? '') === $s['symbol']) { $idx = $j; break; }
        }
        $gielda = in_array($s['gielda'], array_column($gieldy, 'id'), true) ? $s['gielda'] : $domyslnaGielda;
        $wartosci = ['symbol' => $s['symbol'], 'nazwa' => $s['nazwa'], 'rodzaj' => 'spolka',
            'kategoria' => $s['branza'], 'gielda' => $gielda, 'cena' => $s['cena'], 'jednostka' => 'akcja',
            'pochodzenie' => $s['pochodzenie'], 'notowana' => $s['status'] === 'notowana'];
        if ($idx === null) {
            if ($s['status'] === 'notowana') $instrumenty[] = $wartosci;
        } else {
            $instrumenty[$idx] = array_merge($instrumenty[$idx], $wartosci);
        }
    }
    return $instrumenty;
}

// Po edycji giełdy dopisuje brakujące towary i spółki do wspólnego katalogu, a następnie
// odtwarza na giełdzie towary i spółki oznaczone w katalogu jako notowane.
function gielda_katalog_z_instrumentow(array $def, array $k): array
{
    $towary = $k['towary'] ?? [];
    $spolki = $k['spolki'] ?? [];
    foreach ($def['instrumenty'] as $i) {
        if ($i['rodzaj'] === 'towar') {
            $jest = false;
            foreach ($towary as &$t) {
                if (($t['gielda_symbol'] ?? '') === $i['symbol'] || ($i['katalog_id'] ?? '') === ($t['id'] ?? '')
                    || gielda_klucz_nazwy($t['nazwa'] ?? '') === gielda_klucz_nazwy($i['nazwa'])) {
                    $t = array_merge($t, ['gielda_symbol' => $i['symbol'], 'nazwa' => $i['nazwa'],
                        'kategoria' => $i['kategoria'], 'cena' => max(1, (int) round($i['cena'])),
                        'jednostka' => $i['jednostka']]);
                    $jest = true;
                    break;
                }
            }
            unset($t);
            if (!$jest) $towary[] = ['id' => $i['katalog_id'] ?? bin2hex(random_bytes(6)), 'gielda_symbol' => $i['symbol'],
                'nazwa' => $i['nazwa'], 'kategoria' => $i['kategoria'], 'masa' => 1,
                'jednostka' => $i['jednostka'], 'cena' => max(1, (int) round($i['cena']))];
        } else {
            $jest = false;
            foreach ($spolki as &$s) {
                if ($s['symbol'] === $i['symbol'] || gielda_klucz_nazwy($s['nazwa']) === gielda_klucz_nazwy($i['nazwa'])) {
                    $s = array_merge($s, ['symbol' => $i['symbol'], 'nazwa' => $i['nazwa'], 'branza' => $i['kategoria'],
                        'status' => ($i['notowana'] ?? true) ? 'notowana' : 'poza_gielda', 'gielda' => $i['gielda'],
                        'cena' => $i['cena'], 'pochodzenie' => $i['pochodzenie']]);
                    $jest = true;
                    break;
                }
            }
            unset($s);
            if (!$jest) $spolki[] = ['symbol' => $i['symbol'], 'nazwa' => $i['nazwa'], 'branza' => $i['kategoria'],
                'status' => ($i['notowana'] ?? true) ? 'notowana' : 'poza_gielda', 'gielda' => $i['gielda'],
                'cena' => $i['cena'], 'pochodzenie' => $i['pochodzenie']];
        }
    }
    $towary = nadaj_id($towary);
    $spolki = gielda_spolki_z_katalogu($spolki, array_column($def['instrumenty'], 'symbol'));
    $instrumenty = gielda_towary_z_katalogu($towary, $def['instrumenty'], $def['gieldy']);
    $instrumenty = gielda_spolki_do_instrumentow($spolki, $instrumenty, $def['gieldy']);
    $def['instrumenty'] = $instrumenty;
    $k['towary'] = $towary;
    $k['spolki'] = $spolki;
    return [$def, $k];
}

function gielda_uzgodnij_katalog(): array
{
    $def = gielda_definicje();
    $k = wczytaj('katalog', []);
    [$nowaDef, $nowyKatalog] = gielda_katalog_z_instrumentow($def, $k);
    if (($nowyKatalog['towary'] ?? []) !== ($k['towary'] ?? []) || ($nowyKatalog['spolki'] ?? []) !== ($k['spolki'] ?? [])) {
        zmien('katalog', function (&$d) use ($nowyKatalog) {
            $d['towary'] = $nowyKatalog['towary'];
            $d['spolki'] = $nowyKatalog['spolki'];
        }, []);
    }
    return [$nowaDef, $nowyKatalog];
}

// Początkowa giełda. MG zmienia ją w panelu; to tylko punkt startu.
function gielda_domyslna(): array
{
    $s = fn($symbol, $nazwa, $kategoria, $gielda, $cena, $pochodzenie, $zm = null) =>
        ['symbol' => $symbol, 'nazwa' => $nazwa, 'rodzaj' => 'spolka', 'kategoria' => $kategoria, 'gielda' => $gielda, 'cena' => $cena, 'jednostka' => 'akcja', 'pochodzenie' => $pochodzenie] + ($zm ? ['zmiennosc' => $zm] : []);
    $t = fn($symbol, $nazwa, $kategoria, $gielda, $cena, $jednostka, $pochodzenie, $zm = null) =>
        ['symbol' => $symbol, 'nazwa' => $nazwa, 'rodzaj' => 'towar', 'kategoria' => $kategoria, 'gielda' => $gielda, 'cena' => $cena, 'jednostka' => $jednostka, 'pochodzenie' => $pochodzenie] + ($zm ? ['zmiennosc' => $zm] : []);
    return [
        'gieldy' => [
            ['id' => 'cor', 'nazwa' => 'Galaktyczna Giełda Coruscant', 'planeta' => 'Coruscant'],
            ['id' => 'crl', 'nazwa' => 'Giełda Coronet', 'planeta' => 'Corellia'],
            ['id' => 'kua', 'nazwa' => 'Giełda Stoczniowa Kuat', 'planeta' => 'Kuat'],
            ['id' => 'bsp', 'nazwa' => 'Giełda Tibanny w Mieście w Chmurach', 'planeta' => 'Bespin'],
            ['id' => 'nar', 'nazwa' => 'Rynek Huttów na Nar Shaddaa', 'planeta' => 'Nar Shaddaa'],
        ],
        'instrumenty' => [
            // spółki: kategoria = branża
            $s('KDY', 'Kuat Drive Yards', 'stocznie', 'kua', 520, 'Kuat'),
            $s('SFS', 'Sienar Fleet Systems', 'stocznie', 'cor', 310, 'Lothal'),
            $s('CEC', 'Corellian Engineering Corporation', 'stocznie', 'crl', 280, 'Corellia'),
            $s('INC', 'Incom Corporation', 'stocznie', 'cor', 240, 'Fresia'),
            $s('NDC', 'Nubian Design Collective', 'stocznie', 'cor', 150, 'Naboo'),
            $s('RSD', 'Rendili StarDrive', 'stocznie', 'kua', 190, 'Rendili'),
            $s('BLT', 'BlasTech Industries', 'uzbrojenie', 'cor', 175, 'Coruscant'),
            $s('MSM', 'Merr-Sonn Munitions', 'uzbrojenie', 'cor', 140, 'Carida'),
            $s('CZK', 'Czerka Corporation', 'uzbrojenie', 'cor', 95, 'Coruscant', 0.024),
            $s('CYB', 'Cybot Galactica', 'droidy', 'cor', 160, 'Affa'),
            $s('IAU', 'Industrial Automaton', 'droidy', 'cor', 130, 'Coruscant'),
            $s('SRS', 'SoroSuub Corporation', 'elektronika', 'cor', 150, 'Sullust'),
            $s('LOR', 'Loronar Corporation', 'elektronika', 'crl', 90, 'Loronar'),
            $s('MGU', 'Gildia Górnicza', 'górnictwo', 'cor', 85, 'Coruscant'),
            $s('BES', 'Bespin Motors i Tibanna', 'górnictwo', 'bsp', 210, 'Bespin'),
            $s('XTS', 'Xizor Transport Systems', 'transport', 'cor', 120, 'Coruscant'),
            $s('HCL', 'Kartel Huttów — Logistyka', 'transport', 'nar', 70, 'Nal Hutta', 0.03),
            $s('IBC', 'Międzygalaktyczny Klan Bankowy', 'finanse', 'cor', 400, 'Muunilinst', 0.012),
            $s('GIZ', 'Gizer Żywność i Napoje', 'żywność', 'crl', 60, 'Gizer'),
            // towary: kategoria = grupa towarów (te same nazwy co w katalogu firm)
            $t('COAX', 'Coaxium surowe', 'paliwo', 'nar', 1200, 'kg', 'Kessel', 0.04),
            $t('COAXR', 'Coaxium rafinowane', 'paliwo', 'cor', 2600, 'kg', 'Kessel', 0.03),
            $t('TIBP', 'Gaz tibanna przemysłowy', 'paliwo', 'bsp', 340, 'zbiornik', 'Bespin'),
            $t('TIBW', 'Gaz tibanna wysokiej czystości', 'paliwo', 'bsp', 720, 'zbiornik', 'Bespin'),
            $t('PLZ', 'Plazma z Naboo', 'paliwo', 'cor', 480, 'ogniwo', 'Naboo'),
            $t('OGN', 'Ogniwa paliwowe', 'paliwo', 'crl', 60, 'skrzynia', 'Corellia'),
            $t('DUR', 'Durastal', 'surowce', 'kua', 140, 't', 'Kuat'),
            $t('DOON', 'Doonium', 'surowce', 'kua', 260, 't', 'Kuat'),
            $t('BRZ', 'Bronzium', 'surowce', 'crl', 180, 't', 'Corellia'),
            $t('ZBO', 'Zboże', 'żywność', 'cor', 20, 't', 'Agamar'),
            $t('NRF', 'Mięso nerfów', 'żywność', 'cor', 45, 't', 'Naboo'),
            $t('BCT', 'Bacta', 'medycyna', 'cor', 900, 'zbiornik', 'Thyferra', 0.03),
            $t('KOL', 'Kolto', 'medycyna', 'cor', 650, 'zbiornik', 'Manaan'),
            $t('HIP', 'Części hipernapędu', 'technika', 'crl', 950, 'kontener', 'Corellia'),
            $t('MDR', 'Moduły droidów', 'technika', 'cor', 300, 'skrzynia', 'Affa'),
            $t('BLS', 'Blastery bez numerów', 'broń', 'nar', 500, 'skrzynia', 'Nar Shaddaa', 0.035),
            $t('WIN', 'Wino koreliańskie', 'luksus', 'crl', 220, 'skrzynia', 'Corellia'),
            $t('JED', 'Jedwab hapański', 'luksus', 'cor', 380, 'bela', 'Hapes'),
            $t('GLT', 'Glitterstim', 'przyprawy', 'nar', 1500, 'kg', 'Kessel', 0.05),
            $t('RYL', 'Ryll', 'przyprawy', 'nar', 700, 'kg', 'Ryloth', 0.045),
        ],
        'ustawienia' => gielda_ustawienia_domyslne(),
    ];
}

function gielda_definicje(): array
{
    $db = polaczenie_bazy();
    try {
        $db->query("SELECT kod FROM gielde LIMIT 0");
        $db->query("SELECT kategoria,jednostka,pochodzenie FROM instrumenty LIMIT 0");
        $db->query("SELECT instrument_id,data,kurs FROM kursy_dzienne LIMIT 0");
    } catch (Throwable $e) {
        throw new RuntimeException('Giełda SQL nie jest jeszcze przygotowana. Zaimportuj api/migracje/gielda_migracja.sql.');
    }
    $gieldy = $db->query("SELECT ge.kod AS id, ge.nazwa, mp.nazwa AS planeta FROM gielde ge JOIN mapa_planety mp ON mp.id=ge.planeta_id WHERE ge.aktywna=1 ORDER BY ge.id")->fetchAll(PDO::FETCH_ASSOC);
    $ust = gielda_ustawienia_domyslne();
    foreach ($db->query("SELECT klucz,wartosc FROM ustawienia WHERE klucz LIKE 'gielda.%'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $klucz = substr((string)$r['klucz'], 7);
        if (array_key_exists($klucz, $ust)) $ust[$klucz] = $klucz === 'historia_dni' ? (int)$r['wartosc'] : (float)$r['wartosc'];
    }
    $q = $db->query("SELECT i.symbol,i.nazwa,i.rodzaj,i.kategoria,ge.kod AS gielda,i.cena_bazowa AS cena,i.zmiennosc,i.notowany AS notowana,i.jednostka,i.pochodzenie,i.towar_id,i.firma_id,
        tw.nazwa AS towar_nazwa,p.nazwa AS firma_nazwa FROM instrumenty i JOIN gielde ge ON ge.id=i.gielda_id LEFT JOIN towary tw ON tw.id=i.towar_id LEFT JOIN podmioty p ON p.id=i.firma_id ORDER BY i.id");
    $instrumenty = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $bazowa=(float)($r['rodzaj']==='towar'?$ust['zmiennosc_towar']:$ust['zmiennosc_spolka']);
        $instrumenty[] = ['symbol'=>(string)$r['symbol'],'nazwa'=>(string)$r['nazwa'],'rodzaj'=>$r['rodzaj'],'kategoria'=>(string)$r['kategoria'],
            'gielda'=>(string)$r['gielda'],'cena'=>(float)$r['cena'],'zmiennosc'=>$bazowa*(float)$r['zmiennosc'],'notowana'=>(bool)$r['notowana'],
            'jednostka'=>(string)$r['jednostka'],'pochodzenie'=>(string)($r['pochodzenie']??''),
            'katalog_id'=>$r['towar_id']===null?'':(string)$r['towar_id'],'firma_id'=>$r['firma_id']===null?'':(string)$r['firma_id']];
    }
    return ['gieldy'=>$gieldy,'instrumenty'=>$instrumenty,'ustawienia'=>$ust];
}

function gielda_podmiot_uzytkownika(array $u): int
{
    $q = polaczenie_bazy()->prepare("SELECT p.podmiot_id FROM postacie p JOIN podmioty x ON x.id=p.podmiot_id WHERE x.typ='postac' AND x.nazwa=? LIMIT 1");
    $q->execute([(string)($u['postac'] ?? '')]);
    $id = $q->fetchColumn();
    if (!$id) blad('Ta postać nie ma jeszcze rekordu SQL. Powiąż postać w tabeli postacie przed użyciem giełdy.', 409);
    return (int)$id;
}

function gielda_portfel_sql(int $podmiotId): array
{
    $db = polaczenie_bazy();
    $q = $db->prepare('SELECT i.symbol,p.ilosc,p.koszt_laczny FROM portfele_gieldowe p JOIN instrumenty i ON i.id=p.instrument_id WHERE p.podmiot_id=?');
    $q->execute([$podmiotId]);
    $pozycje = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $pozycje[$r['symbol']] = ['ilosc'=>(int)$r['ilosc'],'koszt'=>(int)$r['koszt_laczny']];
    $q = $db->prepare("SELECT i.symbol,t.strona,t.ilosc,t.kurs,t.kurs*t.ilosc AS wartosc,t.prowizja,t.czas_utc FROM transakcje_gieldowe t JOIN instrumenty i ON i.id=t.instrument_id WHERE t.podmiot_id=? ORDER BY t.id DESC LIMIT " . GIELDA_HISTORIA_TRANSAKCJI);
    $q->execute([$podmiotId]);
    $historia = array_map(fn($r)=>['data'=>date('c',strtotime($r['czas_utc'].' UTC')),'typ'=>$r['strona']==='kupno'?'kup':'sprzedaj','symbol'=>$r['symbol'],'ilosc'=>(int)$r['ilosc'],'kurs'=>(float)$r['kurs'],'wartosc'=>(int)round((float)$r['wartosc']),'prowizja'=>(int)$r['prowizja']], $q->fetchAll(PDO::FETCH_ASSOC));
    return ['pozycje'=>$pozycje,'historia'=>array_reverse($historia)];
}

function gielda_zapisz_sql(array $def): void
{
    $db = polaczenie_bazy();
    $qPlaneta = $db->prepare('SELECT id FROM mapa_planety WHERE nazwa=? LIMIT 1');
    $q = $db->prepare('INSERT INTO gielde (kod,planeta_id,nazwa,aktywna) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE planeta_id=VALUES(planeta_id),nazwa=VALUES(nazwa),aktywna=1');
    foreach ($def['gieldy'] as $ge) {
        $qPlaneta->execute([$ge['planeta']]); $pid = $qPlaneta->fetchColumn();
        if (!$pid) blad("Giełda {$ge['id']}: planeta „{$ge['planeta']}” nie istnieje w SQL.");
        $q->execute([$ge['id'],(int)$pid,$ge['nazwa']]);
    }
    $db->exec('UPDATE gielde SET aktywna=0');
    $up = $db->prepare('UPDATE gielde SET planeta_id=?,nazwa=?,aktywna=1 WHERE kod=?');
    $lookup = $db->prepare('SELECT id FROM gielde WHERE kod=?');
    $insTowar = $db->prepare('SELECT id FROM towary WHERE (id=? AND ? REGEXP \'^[0-9]+$\') OR nazwa=? LIMIT 1');
    $insFirma = $db->prepare("SELECT p.id FROM podmioty p JOIN firmy f ON f.podmiot_id=p.id WHERE p.id=? OR p.nazwa=? LIMIT 1");
    $ins = $db->prepare('INSERT INTO instrumenty (symbol,nazwa,rodzaj,kategoria,jednostka,pochodzenie,gielda_id,towar_id,firma_id,cena_bazowa,zmiennosc,notowany) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nazwa=VALUES(nazwa),rodzaj=VALUES(rodzaj),kategoria=VALUES(kategoria),jednostka=VALUES(jednostka),pochodzenie=VALUES(pochodzenie),gielda_id=VALUES(gielda_id),towar_id=VALUES(towar_id),firma_id=VALUES(firma_id),cena_bazowa=VALUES(cena_bazowa),zmiennosc=VALUES(zmiennosc),notowany=VALUES(notowany)');
    $db->exec('UPDATE instrumenty SET notowany=0');
    foreach ($def['instrumenty'] as $i) {
        $lookup->execute([$i['gielda']]); $gid = $lookup->fetchColumn();
        if (!$gid) blad("{$i['symbol']}: nie znaleziono giełdy SQL {$i['gielda']}.");
        $towarId = $firmaId = null;
        if ($i['rodzaj'] === 'towar') {
            $kid=(string)($i['katalog_id']??''); $insTowar->execute([$kid,$kid,$i['nazwa']]); $towarId=$insTowar->fetchColumn();
            if (!$towarId) {
                $kategoria=mb_strtolower((string)$i['kategoria']);
                $kategoria=strtr($kategoria,['ż'=>'z','ź'=>'z','ć'=>'c','ś'=>'s','ą'=>'a','ę'=>'e','ł'=>'l','ó'=>'o','ń'=>'n']);
                if ($kategoria==='zywnosc') $kategoria='zywnosc';
                if ($kategoria==='elektronika') $kategoria='technika';
                if (!in_array($kategoria,['paliwo','surowce','zywnosc','medycyna','technika','bron','luksus','przyprawy'],true)) $kategoria='technika';
                $db->prepare('INSERT INTO towary (nazwa,kategoria,jednostka,cena_bazowa,opis) VALUES (?,?,?,?,?)')->execute([$i['nazwa'],$kategoria,$i['jednostka'],(int)round((float)$i['cena']),'Pozycja katalogu giełdy CDEX.']);
                $insTowar->execute(['','',$i['nazwa']]); $towarId=$insTowar->fetchColumn();
            }
        } else {
            $fid=(string)($i['firma_id']??''); $insFirma->execute([$fid,$i['nazwa']]); $firmaId=$insFirma->fetchColumn();
            if (!$firmaId) {
                $db->prepare("INSERT INTO podmioty (typ,nazwa,status) VALUES ('firma',?,'aktywny')")->execute([$i['nazwa']]);
                $firmaId=(int)$db->lastInsertId();
                $profil=in_array($i['profil']??'',PROFILE_FIRM,true)?$i['profil']:'produkcja';
                $db->prepare('INSERT INTO firmy (podmiot_id,profil,kapital_zakladowy,skala) VALUES (?,?,0,1)')->execute([$firmaId,$profil]);
            }
        }
        $up->execute([$db->query('SELECT planeta_id FROM gielde WHERE id='.(int)$gid)->fetchColumn(),
            $db->query('SELECT nazwa FROM gielde WHERE id='.(int)$gid)->fetchColumn(),$i['gielda']]);
        $bazowa=(float)($i['rodzaj']==='towar'?$def['ustawienia']['zmiennosc_towar']:$def['ustawienia']['zmiennosc_spolka']);
        $zm = isset($i['zmiennosc']) ? max(0.001,min(10,(float)$i['zmiennosc']/max(0.001,$bazowa))) : 1.0;
        $ins->execute([$i['symbol'],$i['nazwa'],$i['rodzaj'],$i['kategoria'],$i['jednostka'],$i['pochodzenie'],(int)$gid,$towarId?(int)$towarId:null,$firmaId?(int)$firmaId:null,(float)$i['cena'],$zm,(int)($i['notowana']??true)]);
    }
    $ust = $db->prepare('INSERT INTO ustawienia (klucz,wartosc,opis) VALUES (?,?,?) ON DUPLICATE KEY UPDATE wartosc=VALUES(wartosc)');
    foreach ($def['ustawienia'] as $k=>$v) $ust->execute(['gielda.'.$k,(string)$v,'Ustawienie giełdy CDEX']);
}

function gielda_wszystkie_portfele_sql(): array
{
    $q = polaczenie_bazy()->query("SELECT x.nazwa,i.symbol,p.ilosc,p.koszt_laczny FROM portfele_gieldowe p JOIN podmioty x ON x.id=p.podmiot_id JOIN instrumenty i ON i.id=p.instrument_id WHERE p.ilosc>0 ORDER BY x.nazwa,i.symbol");
    $wynik=[];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $wynik[$r['nazwa']][$r['symbol']]=['ilosc'=>(int)$r['ilosc'],'koszt'=>(int)$r['koszt_laczny']];
    return $wynik;
}

// Planety, których dotyczy instrument: skąd pochodzi i gdzie jest notowany.
function gielda_obiekt(array $i, array $gieldy): array
{
    $pl = array_column($gieldy, 'planeta', 'id');
    return ['planety' => array_values(array_filter([$i['pochodzenie'] ?? '', $pl[$i['gielda']] ?? ''])),
        'rodzaj' => $i['rodzaj'], 'kategoria' => $i['kategoria'] ?? '', 'cele' => [$i['symbol'], $i['nazwa']]];
}

// Losowość z rozkładu normalnego wyznaczona przez symbol i dzień (Box–Muller).
function gielda_eps(string $symbol, string $dzien): float
{
    $u1 = (crc32('a|' . $symbol . '|' . $dzien) % 1000000 + 1) / 1000001;
    $u2 = (crc32('b|' . $symbol . '|' . $dzien) % 1000000) / 1000000;
    return max(-3.0, min(3.0, sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2)));
}

function gielda_krok(float $poprzedni, array $i, array $g, string $dzien, ?array $wydarzenia = null): float
{
    $u = $g['ustawienia'];
    $w = wydarzenia_wplyw(gielda_obiekt($i, $g['gieldy']), $dzien, $wydarzenia);
    $f = max(0.01, (float) $i['cena']) * $w['mnoznik'];
    $sigma = (float) ($i['zmiennosc'] ?? ($i['rodzaj'] === 'towar' ? $u['zmiennosc_towar'] : $u['zmiennosc_spolka']));
    $x = log($poprzedni) + (float) $u['powrot'] * (log($f) - log($poprzedni)) + $sigma * $w['niepewnosc'] * gielda_eps($i['symbol'], $dzien);
    return round(max(0.01, exp($x)), 2);
}

// Dopisuje brakujące dni (do dziś) wszystkim instrumentom. Nowy instrument dostaje historię.
function gielda_dociagnij(): array
{
    $g = gielda_definicje();
    $dzis = date('Y-m-d');
    $db = polaczenie_bazy();
    $not = [];
    $rows = $db->query('SELECT i.symbol,k.data,k.kurs FROM kursy_dzienne k JOIN instrumenty i ON i.id=k.instrument_id WHERE k.data <= CURDATE() ORDER BY i.id,k.data')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $sym = (string)$r['symbol'];
        if (!isset($not[$sym])) $not[$sym] = ['od'=>(string)$r['data'],'c'=>[],'daty'=>[]];
        $not[$sym]['c'][] = (float)$r['kurs'];
        $not[$sym]['daty'][] = (string)$r['data'];
    }
    $insert = $db->prepare('INSERT IGNORE INTO kursy_dzienne (instrument_id,data,kurs) VALUES (?,?,?)');
    foreach ($g['instrumenty'] as $i) {
        if (empty($i['notowana'])) continue;
        $symbol = $i['symbol'];
        if (empty($not[$symbol]['c'])) {
            $q = $db->prepare('SELECT id FROM instrumenty WHERE symbol=?'); $q->execute([$symbol]); $iid = $q->fetchColumn();
            if (!$iid) continue;
            $od = date('Y-m-d', strtotime($dzis . ' -' . max(30, (int)$g['ustawienia']['historia_dni']) . ' days'));
            $kurs = round((float)$i['cena'], 2);
            $not[$symbol] = ['od'=>$od,'c'=>[$kurs],'daty'=>[$od]];
            $insert->execute([(int)$iid,$od,$kurs]);
        }
        $ostatni = (string)end($not[$symbol]['daty']);
        $kurs = (float)end($not[$symbol]['c']);
        $q = $db->prepare('SELECT id FROM instrumenty WHERE symbol=?'); $q->execute([$symbol]); $iid = $q->fetchColumn();
        while ($ostatni < $dzis) {
            $ostatni = date('Y-m-d', strtotime($ostatni . ' +1 day'));
            $kurs = gielda_krok($kurs, $i, $g, $ostatni);
            $insert->execute([(int)$iid,$ostatni,$kurs]);
            $not[$symbol]['c'][] = $kurs; $not[$symbol]['daty'][] = $ostatni;
        }
    }
    foreach ($not as &$n) { unset($n['daty']); if (count($n['c']) > GIELDA_MAX_DNI) { $nad = count($n['c'])-GIELDA_MAX_DNI; $n['c']=array_slice($n['c'],$nad); $n['od']=date('Y-m-d',strtotime($n['od']." +$nad days")); } }
    unset($n);
    return $not;
}

// Po zmianie wydarzeń dzisiejszy kurs liczy się od nowa (przy następnym wejściu na giełdę),
// więc wydarzenie zaczynające się dziś działa od razu. Historia sprzed dziś się nie zmienia.
function gielda_przelicz_dzis(): void
{
    $dzis = date('Y-m-d');
    $db = polaczenie_bazy();
    $db->prepare('DELETE FROM kursy_dzienne WHERE data=?')->execute([$dzis]);
}

function gielda_instrument(string $symbol): ?array
{
    foreach (gielda_definicje()['instrumenty'] as $i) {
        if ($i['symbol'] === $symbol) return $i;
    }
    return null;
}

function gielda_portfel(array $portfele, string $uid): array
{
    return ($portfele[$uid] ?? []) + ['pozycje' => [], 'historia' => []];
}

// Walidacja definicji z panelu MG.
function gielda_z_wejscia(array $w): array
{
    $gieldy = [];
    foreach ((array) ($w['gieldy'] ?? []) as $x) {
        $id = mb_strtolower(trim((string) ($x['id'] ?? '')));
        if (!preg_match('/^[a-z0-9_-]{2,12}$/', $id)) blad('Giełda: identyfikator „' . $id . '” (2–12 małych liter lub cyfr).');
        if (isset($gieldy[$id])) blad("Giełda „{$id}” powtarza się.");
        $gieldy[$id] = ['id' => $id, 'nazwa' => mb_substr(trim((string) ($x['nazwa'] ?? $id)), 0, 80), 'planeta' => mb_substr(trim((string) ($x['planeta'] ?? '')), 0, 80)];
    }
    if (!$gieldy) blad('Potrzebna jest co najmniej jedna giełda.');
    $instrumenty = [];
    foreach ((array) ($w['instrumenty'] ?? []) as $x) {
        $sym = mb_strtoupper(trim((string) ($x['symbol'] ?? '')));
        if (!preg_match('/^[A-Z0-9.]{2,16}$/', $sym)) blad("Symbol „{$sym}”: 2–16 wielkich liter, cyfr lub kropek.");
        if (isset($instrumenty[$sym])) blad("Symbol {$sym} powtarza się.");
        $rodzaj = ($x['rodzaj'] ?? '') === 'spolka' ? 'spolka' : 'towar';
        $gid = mb_strtolower(trim((string) ($x['gielda'] ?? '')));
        if (!isset($gieldy[$gid])) blad("{$sym}: nie ma giełdy „{$gid}”.");
        $cena = round((float) ($x['cena'] ?? 0), 2);
        if ($cena <= 0) blad("{$sym}: cena bazowa musi być większa od zera.");
        $instrumenty[$sym] = [
            'symbol' => $sym, 'nazwa' => mb_substr(trim((string) ($x['nazwa'] ?? $sym)), 0, 80), 'rodzaj' => $rodzaj,
            'kategoria' => mb_strtolower(mb_substr(trim((string) ($x['kategoria'] ?? '')), 0, 40)),
            'gielda' => $gid, 'cena' => $cena,
            'jednostka' => mb_substr(trim((string) ($x['jednostka'] ?? ($rodzaj === 'spolka' ? 'akcja' : 'szt.'))), 0, 20),
            'pochodzenie' => mb_substr(trim((string) ($x['pochodzenie'] ?? '')), 0, 80),
        ] + (isset($x['zmiennosc']) && $x['zmiennosc'] !== '' ? ['zmiennosc' => max(0.001, min(0.3, (float) $x['zmiennosc']))] : [])
          + (!empty($x['katalog_id']) ? ['katalog_id' => mb_substr((string) $x['katalog_id'], 0, 32)] : [])
          + (!empty($x['firma_id']) ? ['firma_id' => mb_substr((string) $x['firma_id'], 0, 32)] : [])
          + (in_array($x['profil'] ?? '', PROFILE_FIRM, true) ? ['profil' => $x['profil']] : [])
          + (array_key_exists('notowana', $x) ? ['notowana' => (bool) $x['notowana']] : []);
    }
    $u = (array) ($w['ustawienia'] ?? []) + gielda_ustawienia_domyslne();
    $ust = [
        'powrot' => max(0.01, min(0.5, (float) $u['powrot'])),
        'zmiennosc_spolka' => max(0.001, min(0.3, (float) $u['zmiennosc_spolka'])),
        'zmiennosc_towar' => max(0.001, min(0.3, (float) $u['zmiennosc_towar'])),
        'prowizja' => max(0, min(0.1, (float) $u['prowizja'])),
        'historia_dni' => max(30, min(GIELDA_MAX_DNI, (int) $u['historia_dni'])),
    ];
    return ['gieldy' => array_values($gieldy), 'instrumenty' => array_values($instrumenty), 'ustawienia' => $ust];
}

function obsluz_gielda(string $akcja, bool $post): void
{
    switch ($akcja) {

        case 'gielda_publiczna':
            if ($post) blad('Zła metoda.', 405);
            $g = gielda_definicje();
            $historia = gielda_dociagnij();
            $publiczne = array_fill_keys(array_map('mb_strtolower', polaczenie_bazy()->query("SELECT p.nazwa FROM mapa_planety p
                LEFT JOIN planety_gry gp ON gp.planeta_id=p.id WHERE COALESCE(gp.zastrzezona,0)=0")->fetchAll(PDO::FETCH_COLUMN)), true);
            $gieldy = array_values(array_filter($g['gieldy'], fn($x) => isset($publiczne[mb_strtolower($x['planeta'])])));
            $widoczne = array_fill_keys(array_column($gieldy, 'id'), true);
            $lista = [];
            foreach ($g['instrumenty'] as $i) {
                if (empty($i['notowana']) || !isset($widoczne[$i['gielda']])) continue;
                $c = $historia[$i['symbol']]['c'] ?? [(float)$i['cena']];
                $ostatni = (float)end($c);
                $poprzedni = (float)($c[count($c)-2] ?? $ostatni);
                $lista[] = array_diff_key($i, ['cena'=>1, 'zmiennosc'=>1, 'katalog_id'=>1, 'firma_id'=>1]) + [
                    'kurs'=>$ostatni, 'poprzedni'=>$poprzedni, 'seria'=>array_slice($c, -30),
                ];
            }
            odpowiedz(['dzien'=>date('Y-m-d'), 'gieldy'=>$gieldy, 'instrumenty'=>$lista,
                'prowizja'=>0, 'portfel'=>['pozycje'=>[], 'historia'=>[]], 'saldo'=>0, 'publiczny'=>true]);

        case 'gielda':
            $u = wymagaj_logowania();
            $g = gielda_definicje();
            $not = gielda_dociagnij();
            $portfel = gielda_portfel_sql(gielda_podmiot_uzytkownika($u));
            $lista = [];
            foreach ($g['instrumenty'] as $i) {
                if (($i['notowana'] ?? true) !== true && empty($portfel['pozycje'][$i['symbol']])) continue;
                $c = $not[$i['symbol']]['c'] ?? [(float) $i['cena']];
                $lista[] = array_diff_key($i, ['cena' => 1, 'zmiennosc' => 1]) + [
                    'kurs' => end($c), 'poprzedni' => $c[count($c) - 2] ?? end($c), 'seria' => array_slice($c, -30),
                ];
            }
            $bank = wczytaj('bank', bank_pusty());
            odpowiedz([
                'dzien' => date('Y-m-d'), 'gieldy' => $g['gieldy'], 'instrumenty' => $lista, 'prowizja' => $g['ustawienia']['prowizja'],
                'portfel' => ['pozycje' => $portfel['pozycje'], 'historia' => array_slice(array_reverse($portfel['historia']), 0, 30)],
                'saldo' => $bank['konta'][$u['id']]['saldo'] ?? 0,
            ]);

        case 'gielda_notowania':
            wymagaj_logowania();
            $sym = (string) ($_GET['s'] ?? '');
            $dni = max(5, min(GIELDA_MAX_DNI, (int) ($_GET['dni'] ?? 90)));
            $instrument = gielda_instrument($sym);
            $n = $instrument && (($instrument['notowana'] ?? true) || !empty(gielda_portfel_sql(gielda_podmiot_uzytkownika(ja()))['pozycje'][$sym]))
                ? (gielda_dociagnij()[$sym] ?? null) : null;
            if (!$n) blad('Brak notowań tego instrumentu.', 404);
            $c = array_slice($n['c'], -$dni);
            $od = date('Y-m-d', strtotime($n['od'] . ' +' . (count($n['c']) - count($c)) . ' days'));
            odpowiedz(['symbol' => $sym, 'od' => $od, 'ceny' => $c]);

        case 'gielda_transakcja':
            if (!$post) blad('Zła metoda.', 405);
            $u = wymagaj_logowania();
            $w = wejscie();
            $sym = (string) ($w['symbol'] ?? '');
            $typ = ($w['typ'] ?? '') === 'sprzedaj' ? 'sprzedaj' : 'kup';
            $ilosc = (int) ($w['ilosc'] ?? 0);
            if ($ilosc <= 0 || $ilosc > 1000000) blad('Podaj ilość większą od zera.');
            $i = gielda_instrument($sym) ?? blad('Ten instrument nie jest notowany.', 404);
            if (($i['notowana'] ?? true) !== true) blad('Ta spółka nie jest obecnie notowana.', 409);
            $c = gielda_dociagnij()[$sym]['c'];
            $kurs = (float) end($c);
            $wartosc = (int) round($kurs * $ilosc);
            $prowizja = max(1, (int) round($wartosc * (float) gielda_definicje()['ustawienia']['prowizja']));
            $pid = gielda_podmiot_uzytkownika($u); $db = polaczenie_bazy();
            $q = $db->prepare('SELECT id FROM instrumenty WHERE symbol=?'); $q->execute([$sym]); $iid = (int)$q->fetchColumn();
            $q = $db->prepare('SELECT ilosc,koszt_laczny FROM portfele_gieldowe WHERE podmiot_id=? AND instrument_id=?'); $q->execute([$pid,$iid]);
            $poz = $q->fetch(PDO::FETCH_ASSOC) ?: ['ilosc'=>0,'koszt_laczny'=>0];
            if ($typ === 'sprzedaj' && $ilosc > (int)$poz['ilosc']) blad('W portfelu masz tylko '.(int)$poz['ilosc'].' × '.$sym.'.');
            $nazwy = bank_wlasciciele();
            $tytul = ($typ === 'kup' ? 'Kupno' : 'Sprzedaż') . " {$ilosc} × {$sym} po " . number_format($kurs, 2, ',', ' ') . ' kr';
            zmien('bank', function (&$bank) use ($u,$typ,$wartosc,$prowizja,$tytul,$nazwy) {
                if ($typ === 'kup') bank_blad_przelewu(bank_obciaz($bank,$u['id'],$wartosc+$prowizja,'CDEX — rozliczenia giełdowe',$tytul,$nazwy));
                else bank_uznaj($bank,$u['id'],max(0,$wartosc-$prowizja),'CDEX — rozliczenia giełdowe',$tytul,$nazwy);
            }, bank_pusty());
            $db->beginTransaction();
            try {
                $q=$db->prepare('SELECT ilosc,koszt_laczny FROM portfele_gieldowe WHERE podmiot_id=? AND instrument_id=? FOR UPDATE'); $q->execute([$pid,$iid]);
                $poz=$q->fetch(PDO::FETCH_ASSOC) ?: ['ilosc'=>0,'koszt_laczny'=>0];
                if ($typ==='sprzedaj' && $ilosc>(int)$poz['ilosc']) throw new RuntimeException('W portfelu jest już za mało jednostek do sprzedaży.');
                $iloscNowa=$typ==='kup'?(int)$poz['ilosc']+$ilosc:(int)$poz['ilosc']-$ilosc;
                $kosztNowy=$typ==='kup'?(int)$poz['koszt_laczny']+$wartosc+$prowizja:((int)$poz['ilosc']?(int)round((int)$poz['koszt_laczny']*$iloscNowa/(int)$poz['ilosc']):0);
                if ($iloscNowa>0) $db->prepare('INSERT INTO portfele_gieldowe (podmiot_id,instrument_id,ilosc,koszt_laczny) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE ilosc=VALUES(ilosc),koszt_laczny=VALUES(koszt_laczny)')->execute([$pid,$iid,$iloscNowa,$kosztNowy]);
                else $db->prepare('DELETE FROM portfele_gieldowe WHERE podmiot_id=? AND instrument_id=?')->execute([$pid,$iid]);
                $db->prepare('INSERT INTO transakcje_gieldowe (podmiot_id,instrument_id,strona,ilosc,kurs,prowizja,czas_utc) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())')->execute([$pid,$iid,$typ==='kup'?'kupno':'sprzedaz',$ilosc,$kurs,$prowizja]);
                $db->commit();
            } catch (Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
            $wpis=['data'=>date('c'),'typ'=>$typ,'symbol'=>$sym,'ilosc'=>$ilosc,'kurs'=>$kurs,'wartosc'=>$wartosc,'prowizja'=>$prowizja];
            odpowiedz(['ok' => true, 'transakcja' => $wpis]);

        // ----- panel MG -----

        case 'mg_gielda':
            wymagaj_mg();
            gielda_uzgodnij_katalog();
            $g = gielda_definicje();
            $not = gielda_dociagnij();
            $kursy = array_map(fn($n) => end($n['c']), $not);
            $postacie = bank_postacie();
            $portfele = [];
            foreach (gielda_wszystkie_portfele_sql() as $postac => $pozycje) {
                $wartosc = 0;
                foreach ($pozycje as $sym => $poz) $wartosc += (int) round(($kursy[$sym] ?? 0) * $poz['ilosc']);
                if ($pozycje) $portfele[] = ['postac' => $postac, 'pozycje' => $pozycje, 'wartosc' => $wartosc];
            }
            odpowiedz(['gielda' => ['gieldy' => $g['gieldy'], 'instrumenty' => $g['instrumenty'], 'ustawienia' => $g['ustawienia']], 'kursy' => $kursy, 'portfele' => $portfele]);

        case 'mg_gielda_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $nowa = gielda_z_wejscia(wejscie());
            $symbole = array_column($nowa['instrumenty'], 'symbol');
            foreach (gielda_wszystkie_portfele_sql() as $pozycje) foreach (array_keys($pozycje) as $sym) if (!in_array($sym, $symbole, true)) blad("Nie można usunąć {$sym}: gracze mają go w portfelach.");
            [$nowa, $kat] = gielda_katalog_z_instrumentow($nowa, katalog());
            $nowa = gielda_z_wejscia($nowa);
            gielda_zapisz_sql($nowa);
            zmien('katalog', function (&$k) use ($kat) {
                $k['towary'] = $kat['towary'];
                $k['spolki'] = $kat['spolki'];
            }, []);
            odpowiedz(['ok' => true]);

        case 'mg_gielda_podglad':
            // Historia i prognoza kursu na podstawie zapisanych wydarzeń, z opcjonalną podmianą
            // jednego wydarzenia (szkic z formularza) — bez zapisywania czegokolwiek.
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $sym = (string) ($w['symbol'] ?? '');
            $i = gielda_instrument($sym) ?? blad('Nie ma takiego instrumentu.', 404);
            $dni = max(1, min(180, (int) ($w['dni'] ?? 60)));
            $lista = wydarzenia_lista();
            if (!empty($w['szkic'])) {
                $szkic = wydarzenie_z_wejscia($w['szkic']);
                $lista = array_values(array_filter($lista, fn($x) => $x['id'] !== $szkic['id']));
                $lista[] = $szkic;
            }
            $n = gielda_dociagnij()[$sym];
            $historia = array_slice($n['c'], -120);
            $g = gielda_definicje();
            $kurs = (float) end($n['c']);
            $dzien = date('Y-m-d');
            $prognoza = [];
            $bez = [];
            $kursBez = $kurs;
            for ($k = 1; $k <= $dni; $k++) {
                $dzien = date('Y-m-d', strtotime($dzien . ' +1 day'));
                $kurs = gielda_krok($kurs, $i, $g, $dzien, $lista);
                $kursBez = gielda_krok($kursBez, $i, $g, $dzien, []);
                $prognoza[] = $kurs;
                $bez[] = $kursBez;
            }
            odpowiedz(['historia' => $historia, 'prognoza' => $prognoza, 'bez_wydarzen' => $bez, 'dzis' => date('Y-m-d')]);

        case 'mg_gielda_przelicz':
            // Przelicza tylko dzisiejszy kurs. Zaimportowana historia z SQL pozostaje zachowana.
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            gielda_przelicz_dzis();
            gielda_dociagnij();
            odpowiedz(['ok' => true]);
    }
}

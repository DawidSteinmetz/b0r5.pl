<?php
// „Stan faktyczny” postaci: gdzie jest i co widzi w tle datapada (scena ustawiana przez MG).
//   scena:     kosmos | wejscie | nadswietlna | wyjscie | orbita
//   planeta:   typ planety (pustynna, lodowa, oceaniczna, lesna, wulkaniczna, gazowa, miasto, bagienna, skalista)
//   odleglosc: blisko | srednio | daleko
//   miejsce:   nazwa miejsca (np. planety z katalogu) — wpływa też na wygląd powierzchni
//   opis:      krótki tekst na pulpicie, np. „Kurs: Kashyyyk”
// Datapady pytają o scenę co kilka sekund, więc zmiana MG pojawia się na tabletach w trakcie sesji.

declare(strict_types=1);

const SCENY = ['kosmos', 'wejscie', 'nadswietlna', 'wyjscie', 'orbita', 'powierzchnia', 'start'];
const WNETRZA = ['', 'biuro', 'hotel', 'kantyna', 'sklep', 'garaz', 'hangar', 'port', 'inne'];
const TYPY_PLANET = ['pustynna', 'lodowa', 'oceaniczna', 'lesna', 'wulkaniczna', 'gazowa', 'miasto', 'bagienna', 'skalista'];
const ODLEGLOSCI = ['blisko', 'srednio', 'daleko'];

function scena_domyslna(): array
{
    return ['scena' => 'kosmos', 'planeta' => 'oceaniczna', 'odleglosc' => 'srednio', 'miejsce' => '', 'opis' => '', 'wnetrze' => '', 'zmieniono' => 0];
}

// Co widzi postać: krajobraz planety, na której stoi (z okna, z hangaru), albo scenę MG / lotu.
// Wygrywa nowsza zmiana: dotarcie na miejsce (pozycja) albo ustawienie sceny (MG, start, skok).
function scena_efektywna(string $uid): array
{
    $zapisana = (wczytaj('sceny', [])[$uid] ?? []) + scena_domyslna();
    $karta = karta($uid);
    $gdzie = ruch_gdzie($karta, ruch_lokacje());
    if ($gdzie && !ruch_w_locie($karta['pozycja']['lokacja'] ?? '')) {
        $pl = ruch_planeta($gdzie['planeta']);
        $ziemia = [
            'scena' => 'powierzchnia', 'planeta' => $pl['typ'] ?? 'skalista', 'odleglosc' => 'srednio',
            'miejsce' => $pl['nazwa'], 'opis' => $gdzie['nazwa'], 'wnetrze' => $gdzie['typ'],
            'zmieniono' => (int) ($karta['pozycja']['zmieniono'] ?? 0),
        ];
        if ($ziemia['zmieniono'] >= (int) $zapisana['zmieniono']) return $ziemia;
    }
    return $zapisana;
}

function teraz_ms(): int
{
    return (int) round(microtime(true) * 1000);
}

function obsluz_sceny(string $akcja, bool $post): void
{
    switch ($akcja) {

        case 'scena':
            $u = wymagaj_logowania();
            ruch_dokoncz($u['id']);
            odpowiedz(['scena' => scena_efektywna($u['id']), 'teraz' => teraz_ms()]);

        case 'mg_sceny':
            wymagaj_mg();
            $sceny = wczytaj('sceny', []);
            $lista = [];
            foreach (uzytkownicy() as $us) {
                if (($us['rola'] ?? '') === 'mg') continue;
                $lista[] = ['uid' => $us['id'], 'postac' => $us['postac'], 'frakcja' => $us['frakcja'], 'scena' => scena_efektywna($us['id'])];
            }
            odpowiedz(['postacie' => $lista]);

        case 'mg_scena_ustaw':
            // Jedna zmiana dla wielu postaci naraz (np. cała drużyna na pokładzie jednego statku).
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $scena = (string) ($w['scena'] ?? '');
            if (!in_array($scena, SCENY, true)) blad('Nieznana scena.');
            $nowa = [
                'scena' => $scena,
                'planeta' => in_array($w['planeta'] ?? '', TYPY_PLANET, true) ? $w['planeta'] : 'oceaniczna',
                'odleglosc' => in_array($w['odleglosc'] ?? '', ODLEGLOSCI, true) ? $w['odleglosc'] : 'srednio',
                'miejsce' => pole('miejsce', 80),
                'opis' => pole('opis', 120),
                'wnetrze' => in_array($w['wnetrze'] ?? '', WNETRZA, true) ? $w['wnetrze'] : '',
                'zmieniono' => teraz_ms(),
            ];
            $uids = array_values(array_intersect((array) ($w['uids'] ?? []), array_column(uzytkownicy(), 'id')));
            if (!$uids) blad('Wybierz co najmniej jedną postać.');
            zmien('sceny', function (&$s) use ($uids, $nowa) {
                foreach ($uids as $uid) {
                    $s[$uid] = $nowa;
                }
            });
            odpowiedz(['ok' => true, 'ile' => count($uids)]);
    }
}

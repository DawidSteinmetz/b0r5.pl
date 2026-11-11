<?php
// Przykład dla INNEJ myśloodsiewni (np. Artemidy pod https://b0r5.pl/__/): jak kupować na rynku Henryka.
// Wklej funkcję do swojego kodu. Klucz musi być taki sam jak w piwnica/_logika/rynek.json u Henryka ("domy" -> id domu -> klucz).
// Dom płaci ze SWOJEGO budżetu: rynek Henryka zapisuje tylko popyt (ceny rosną dla wszystkich) i zwraca koszt.
function rynek_henryka($akcja, $dane = []) {
    $url = 'https://b0r5.pl/_/piwnica/api/rynek_zewn.php';
    $body = json_encode(array_merge(['od_id' => 'artemida', 'akcja' => $akcja, 'ts' => time()], $dane), JSON_UNESCAPED_UNICODE);
    $klucz = 'TU-WSTAW-WSPOLNY-KLUCZ';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Podpis: ' . hash_hmac('sha256', $body, $klucz)]]);
    $r = json_decode((string)curl_exec($ch), true); curl_close($ch);
    return is_array($r) ? $r : ['blad' => 'brak odpowiedzi'];
}
// ceny:   $c = rynek_henryka('cennik');                                        // ['ok'=>true,'towary'=>['cebula-swieza'=>['cena'=>3,'poziom'=>'niska'],...]]
// zakup:  $z = rynek_henryka('kup', ['towar' => 'cebula-swieza', 'n' => 2, 'id_zakupu' => bin2hex(random_bytes(6))]);   // ['ok'=>true,'koszt'=>7]
// Ten sam id_zakupu wysłany drugi raz nie liczy się podwójnie (bezpiecznie ponawiać przy błędzie sieci).

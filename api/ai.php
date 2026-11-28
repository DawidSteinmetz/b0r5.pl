<?php
// MODUŁ AI: jedno miejsce, przez które gra rozmawia z modelami językowymi (śledztwa droida).
//
// Zasada: prompt jest zawsze napisany na sztywno i szczegółowo (tabela ai_szablony); w pola {{nazwa.pola}} serwer
// wstawia wyłącznie dane z bazy. Puste albo brakujące pole = zapytanie nie wychodzi (model nie łata dziur).
// Odpowiedź modelu musi być JSON-em zgodnym ze schematem szablonu; zły JSON → ponowienie, potem następny dostawca.
// Dostawcy (API zgodne z OpenAI /chat/completions: Groq, OpenRouter, Gemini, Ollama) i dzienny limit wywołań
// są w api/konfiguracja-ai.php (poza repo; wzór: konfiguracja-ai.example.php). Każde wywołanie trafia do ai_wywolania.

declare(strict_types=1);

const AI_PROBY_NA_DOSTAWCE = 3;
const AI_LIMIT_CZASU_S = 40;

final class AiBlad extends RuntimeException {}

function ai_konfiguracja(): array
{
    static $k = null;
    if ($k !== null) return $k;
    $plik = __DIR__ . '/konfiguracja-ai.php';
    $k = is_file($plik) ? require $plik : [];
    if (!is_array($k)) $k = [];
    $k['dostawcy'] = array_values(array_filter((array) ($k['dostawcy'] ?? []),
        fn($d) => is_array($d) && ($d['adres'] ?? '') !== '' && ($d['model'] ?? '') !== ''));
    $k['limit_dzienny'] = (int) ($k['limit_dzienny'] ?? 900);
    return $k;
}

// Spłaszcza ['npc' => ['imie' => 'X']] do ['npc.imie' => 'X'].
function ai_splaszcz(array $dane, string $przedrostek = ''): array
{
    $wynik = [];
    foreach ($dane as $k => $v) {
        $klucz = $przedrostek === '' ? (string) $k : $przedrostek . '.' . $k;
        if (is_array($v) && !array_is_list($v)) $wynik += ai_splaszcz($v, $klucz);
        elseif (is_array($v)) $wynik[$klucz] = implode("\n", array_map('strval', $v));
        elseif (is_bool($v)) $wynik[$klucz] = $v ? 'tak' : 'nie';
        elseif ($v !== null) $wynik[$klucz] = (string) $v;
    }
    return $wynik;
}

// Wstawia dane w pola {{…}}. Rzuca AiBlad z listą pól brakujących albo pustych.
function ai_wypelnij(string $szablon, array $dane): string
{
    $pola = ai_splaszcz($dane);
    $brak = [];
    $tekst = preg_replace_callback('/\{\{\s*([a-z0-9_]+(?:\.[a-z0-9_]+)*)\s*\}\}/i', function ($m) use ($pola, &$brak) {
        $w = trim($pola[$m[1]] ?? '');
        if ($w === '') { $brak[$m[1]] = true; return $m[0]; }
        return $w;
    }, $szablon);
    if ($brak) throw new AiBlad('Brak danych w polach: ' . implode(', ', array_keys($brak)));
    if (preg_match('/\{\{|\}\}/', (string) $tekst)) throw new AiBlad('Szablon ma nieprawidłowe pole {{…}}.');
    return (string) $tekst;
}

// Lista pól użytych w szablonie (dla panelu MG i testów).
function ai_pola_szablonu(string ...$teksty): array
{
    $pola = [];
    foreach ($teksty as $t) {
        preg_match_all('/\{\{\s*([a-z0-9_]+(?:\.[a-z0-9_]+)*)\s*\}\}/i', $t, $m);
        foreach ($m[1] as $p) $pola[$p] = true;
    }
    return array_keys($pola);
}

// Wyciąga obiekt JSON z odpowiedzi (słabe modele dopisują czasem tekst albo ```json).
function ai_wyciagnij_json(string $odp): ?array
{
    $odp = trim($odp);
    $v = json_decode($odp, true);
    if (is_array($v)) return $v;
    $a = strpos($odp, '{');
    $b = strrpos($odp, '}');
    if ($a === false || $b === false || $b <= $a) return null;
    $v = json_decode(substr($odp, $a, $b - $a + 1), true);
    return is_array($v) ? $v : null;
}

// Sprawdza JSON wg schematu „pole:typ,…”. Zwraca [dane_poprawione, null] albo [null, opis_błędu].
function ai_sprawdz(array $v, string $schemat): array
{
    $wynik = [];
    foreach (array_filter(array_map('trim', explode(',', $schemat))) as $wpis) {
        [$pole, $typ] = array_pad(explode(':', $wpis, 2), 2, 'tekst');
        if (!array_key_exists($pole, $v)) return [null, "brak pola „{$pole}”"];
        $x = $v[$pole];
        if ($typ === 'tekst') {
            if (!is_string($x) || trim($x) === '') return [null, "pole „{$pole}” musi być niepustym tekstem"];
            $wynik[$pole] = trim($x);
        } elseif ($typ === 'bool') {
            if (is_string($x)) $x = in_array(mb_strtolower(trim($x)), ['true', 'tak', '1'], true);
            $wynik[$pole] = (bool) $x;
        } elseif ($typ === 'lista') {
            if (!is_array($x)) return [null, "pole „{$pole}” musi być listą"];
            $wynik[$pole] = array_values(array_filter(array_map(fn($e) => trim((string) $e), $x), fn($e) => $e !== ''));
        } else {
            $dozwolone = explode('|', $typ);
            $x = mb_strtolower(trim((string) $x));
            if (!in_array($x, $dozwolone, true)) return [null, "pole „{$pole}” musi być jednym z: " . implode(', ', $dozwolone)];
            $wynik[$pole] = $x;
        }
    }
    return [$wynik, null];
}

function ai_wywolania_dzis(): int
{
    $q = polaczenie_bazy()->query('SELECT COUNT(*) FROM ai_wywolania WHERE kiedy_utc >= UTC_DATE() AND proby > 0');
    return (int) $q->fetchColumn();
}

// Jedno zapytanie HTTP do dostawcy. Zwraca [tekst, tokeny_we, tokeny_wy] albo rzuca AiBlad.
function ai_zapytanie(array $d, array $wiadomosci, float $temperatura, int $maxTokeny, bool $trybJson): array
{
    $cialo = ['model' => $d['model'], 'messages' => $wiadomosci, 'temperature' => $temperatura, 'max_tokens' => $maxTokeny];
    if ($trybJson) $cialo['response_format'] = ['type' => 'json_object'];
    $naglowki = ['Content-Type: application/json'];
    if (($d['klucz'] ?? '') !== '') $naglowki[] = 'Authorization: Bearer ' . $d['klucz'];
    foreach ((array) ($d['naglowki'] ?? []) as $n => $w) $naglowki[] = $n . ': ' . $w;
    $c = curl_init(rtrim((string) $d['adres'], '/') . '/chat/completions');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($cialo, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $naglowki, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => AI_LIMIT_CZASU_S]);
    $odp = curl_exec($c);
    $kod = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $err = curl_error($c);
    curl_close($c);
    if ($odp === false) throw new AiBlad('Brak połączenia: ' . $err);
    $j = json_decode((string) $odp, true);
    if ($kod === 429) throw new AiBlad('Limit dostawcy (429).', 429);
    if ($kod >= 400) throw new AiBlad("HTTP {$kod}: " . mb_substr((string) ($j['error']['message'] ?? $odp), 0, 200), $kod);
    $tekst = $j['choices'][0]['message']['content'] ?? null;
    if (!is_string($tekst)) throw new AiBlad('Odpowiedź bez treści.');
    return [$tekst, $j['usage']['prompt_tokens'] ?? null, $j['usage']['completion_tokens'] ?? null];
}

// Główna funkcja: szablon + dane z bazy → sprawdzony JSON. Rzuca AiBlad, gdy się nie da (wywołujący daje zastępstwo).
function ai_wywolaj(string $kod, array $dane, ?int $sledztwoId = null): array
{
    $db = polaczenie_bazy();
    $q = $db->prepare('SELECT * FROM ai_szablony WHERE kod = ?');
    $q->execute([$kod]);
    $s = $q->fetch();
    if (!$s) throw new AiBlad("Nie ma szablonu „{$kod}”.");

    $log = function (array $p) use ($db, $s, $sledztwoId) {
        $db->prepare('INSERT INTO ai_wywolania (kiedy_utc, szablon_kod, szablon_wersja, sledztwo_id, dostawca, model, proby, tokeny_we, tokeny_wy, czas_ms, ok, blad)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$s['kod'], $s['wersja'], $sledztwoId, $p['dostawca'] ?? null, $p['model'] ?? null, $p['proby'] ?? 0,
                $p['we'] ?? null, $p['wy'] ?? null, $p['ms'] ?? null, empty($p['ok']) ? 0 : 1,
                isset($p['blad']) ? mb_substr($p['blad'], 0, 500) : null]);
    };

    try {
        $system = ai_wypelnij((string) $s['system_prompt'], $dane);
        $uzytkownik = ai_wypelnij((string) $s['uzytkownik_prompt'], $dane);
    } catch (AiBlad $e) {
        $log(['blad' => $e->getMessage()]);
        throw $e;
    }

    $k = ai_konfiguracja();
    if (!$k['dostawcy']) { $log(['blad' => 'Brak dostawców w konfiguracja-ai.php']); throw new AiBlad('Moduł AI nie jest skonfigurowany.'); }
    if (ai_wywolania_dzis() >= $k['limit_dzienny']) { $log(['blad' => 'Dzienny limit wywołań']); throw new AiBlad('Wyczerpany dzienny limit AI.'); }

    $bledy = [];
    foreach ($k['dostawcy'] as $d) {
        $wiadomosci = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $uzytkownik]];
        $trybJson = ($d['tryb_json'] ?? true) !== false;
        $start = microtime(true);
        for ($proba = 1; $proba <= AI_PROBY_NA_DOSTAWCE; $proba++) {
            try {
                [$tekst, $we, $wy] = ai_zapytanie($d, $wiadomosci, (float) $s['temperatura'], (int) $s['max_tokeny'], $trybJson);
            } catch (AiBlad $e) {
                // Dostawca nie zna response_format → spróbuj bez niego; limit albo awaria → następny dostawca.
                if ($trybJson && $e->getCode() === 400) { $trybJson = false; continue; }
                $bledy[] = ($d['nazwa'] ?? $d['model']) . ': ' . $e->getMessage();
                break;
            }
            $v = ai_wyciagnij_json($tekst);
            [$ok, $opis] = $v === null ? [null, 'odpowiedź nie jest JSON-em'] : ai_sprawdz($v, (string) $s['schemat_wyjscia']);
            if ($ok !== null) {
                $log(['dostawca' => $d['nazwa'] ?? null, 'model' => $d['model'], 'proby' => $proba, 'we' => $we, 'wy' => $wy,
                    'ms' => (int) ((microtime(true) - $start) * 1000), 'ok' => true]);
                return $ok;
            }
            // Poprawka: pokaż modelowi jego odpowiedź i powiedz, co jest źle.
            $wiadomosci[] = ['role' => 'assistant', 'content' => mb_substr($tekst, 0, 2000)];
            $wiadomosci[] = ['role' => 'user', 'content' => "Błąd formatu: {$opis}. Odpowiedz ponownie WYŁĄCZNIE poprawnym obiektem JSON zgodnym z instrukcją."];
            $bledy[] = ($d['nazwa'] ?? $d['model']) . " (próba {$proba}): {$opis}";
        }
    }
    $log(['proby' => AI_PROBY_NA_DOSTAWCE, 'blad' => implode(' | ', $bledy)]);
    throw new AiBlad('Żaden dostawca AI nie dał poprawnej odpowiedzi.');
}

// ---------- akcje MG: podgląd i test szablonów ----------

function obsluz_ai(string $akcja, bool $post): void
{
    $akcje = ['mg_ai_szablony', 'mg_ai_szablon_zapisz', 'mg_ai_test', 'mg_ai_wywolania'];
    if (!in_array($akcja, $akcje, true)) return;
    wymagaj_mg();
    $db = polaczenie_bazy();
    try {
        $db->query('SELECT 1 FROM ai_szablony LIMIT 1');
    } catch (Throwable $e) {
        blad('Moduł AI nie odpowiada. (Brak migracji 083.)');
    }
    switch ($akcja) {
        case 'mg_ai_szablony':
            $lista = $db->query('SELECT kod, opis, system_prompt, uzytkownik_prompt, schemat_wyjscia, temperatura, max_tokeny, wersja, zmieniono FROM ai_szablony ORDER BY kod')->fetchAll();
            foreach ($lista as &$s) $s['pola'] = ai_pola_szablonu($s['system_prompt'], $s['uzytkownik_prompt']);
            $k = ai_konfiguracja();
            odpowiedz(['szablony' => $lista, 'dostawcy' => array_map(fn($d) => ['nazwa' => $d['nazwa'] ?? '', 'model' => $d['model']], $k['dostawcy']),
                'limit_dzienny' => $k['limit_dzienny'], 'dzis' => ai_wywolania_dzis()]);

        case 'mg_ai_szablon_zapisz':
            if (!$post) blad('Zła metoda.', 405);
            $w = wejscie();
            $kod = (string) ($w['kod'] ?? '');
            if (!preg_match('/^[a-z0-9_]{2,40}$/', $kod)) blad('Zły kod szablonu.');
            $q = $db->prepare('UPDATE ai_szablony SET system_prompt = ?, uzytkownik_prompt = ?, schemat_wyjscia = ?, temperatura = ?, max_tokeny = ?, wersja = wersja + 1 WHERE kod = ?');
            $q->execute([(string) ($w['system_prompt'] ?? ''), (string) ($w['uzytkownik_prompt'] ?? ''), (string) ($w['schemat_wyjscia'] ?? ''),
                max(0, min(2, (float) ($w['temperatura'] ?? 0.7))), max(50, min(4000, (int) ($w['max_tokeny'] ?? 400))), $kod]);
            if (!$q->rowCount()) blad('Nie ma takiego szablonu.');
            odpowiedz(['ok' => true]);

        case 'mg_ai_test':
            if (!$post) blad('Zła metoda.', 405);
            $w = wejscie();
            try {
                odpowiedz(['ok' => true, 'wynik' => ai_wywolaj((string) ($w['kod'] ?? ''), (array) ($w['dane'] ?? []))]);
            } catch (AiBlad $e) {
                blad($e->getMessage());
            }

        case 'mg_ai_wywolania':
            $lista = $db->query('SELECT id, kiedy_utc, szablon_kod, szablon_wersja, sledztwo_id, dostawca, model, proby, tokeny_we, tokeny_wy, czas_ms, ok, blad
                FROM ai_wywolania ORDER BY id DESC LIMIT 100')->fetchAll();
            odpowiedz(['wywolania' => $lista, 'dzis' => ai_wywolania_dzis()]);
    }
}

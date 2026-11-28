<?php
// Profil „agencja” — dla graczy wygląda jak agencja handlowa (pośrednictwo w kontraktach).
// W rzeczywistości to wewnętrzne narzędzie ISB do kontaktu, kontroli i rozliczeń
// z nielegałami i szpiegami. W całym datapadzie nie pada ani słowo o ISB.
//
//   - „kontrahenci” (załoga) to agenci; mają kryptonimy, ukryte statystyki jak kapitanowie
//     i kierownicy, a często własne statki (flota);
//   - „misje handlowe” to misje z komórki z dodatkową legendą (przykrywką);
//     MG rozlicza je ręcznie, także z wynikami „spalony” i „zatrzymany”;
//   - „monitoring” to obserwowane cele, do których MG dopisuje meldunki;
//   - „rozliczenia” to wypłaty dla agentów z rachunku agencji (na rachunek postaci gracza
//     albo gotówką, gdy agent jest postacią MG).

declare(strict_types=1);

function agencja_operacja(array &$firma, string $op, array $w, array $u, bool $moze): array
{
    $dane = &$firma['dane'];
    if (!$moze) blad('Twoje uprawnienia pozwalają tylko na podgląd.', 403);

    switch ($op) {
        case 'cel_dodaj':
            $nazwa = mb_substr(trim((string) ($w['nazwa'] ?? '')), 0, 120);
            if ($nazwa === '') blad('Podaj, kogo lub co obserwować.');
            $dane['cele'][] = [
                'id' => id(), 'nazwa' => $nazwa, 'opis' => mb_substr(trim((string) ($w['opis'] ?? '')), 0, 2000),
                'status' => 'aktywny', 'autor' => $u['postac'], 'utworzono' => date('c'), 'meldunki' => [],
            ];
            return ['ok' => true];

        case 'cel_status':
            $status = ($w['status'] ?? '') === 'zamkniety' ? 'zamkniety' : 'aktywny';
            foreach ($dane['cele'] as &$c) {
                if ($c['id'] === ($w['id'] ?? '')) {
                    $c['status'] = $status;
                    return ['ok' => true];
                }
            }
            blad('Nie ma takiej teczki.', 404);

        case 'wyplata':
            // Rozliczenie z kontrahentem: z rachunku agencji. Agent-gracz dostaje przelew na swój
            // rachunek, agent prowadzony przez MG — „gotówkę” (pieniądze po prostu schodzą z rachunku).
            $kwota = bank_kwota($w['kwota'] ?? null);
            if ($kwota <= 0) blad('Kwota musi być większa od zera.');
            $tytul = mb_substr(trim((string) ($w['tytul'] ?? '')), 0, 140) ?: 'Prowizja handlowa';
            $agent = null;
            foreach ($dane['zaloga'] as $z) {
                if ($z['id'] === ($w['kontrahent'] ?? '')) $agent = $z;
            }
            if (!$agent) blad('Nie ma takiego kontrahenta.', 404);
            $klucz = 'f:' . $firma['id'];
            $nazwy = bank_wlasciciele() + [$klucz => $firma['nazwa']];
            $wynik = zmien('bank', function (&$bank) use ($agent, $klucz, $kwota, $tytul, $nazwy) {
                if (($agent['gracz'] ?? '') !== '' && isset($nazwy[$agent['gracz']])) {
                    $numer = bank_konto($bank, $agent['gracz'])['numer'];
                    return bank_przelej($bank, $klucz, $numer, $kwota, $tytul, $nazwy);
                }
                $komu = ($agent['kryptonim'] ?? '') !== '' ? $agent['kryptonim'] : $agent['imie'];
                return bank_obciaz($bank, $klucz, $kwota, $komu . ' (gotówka)', $tytul, $nazwy);
            }, bank_pusty());
            bank_blad_przelewu($wynik);
            return ['ok' => true];
    }
    // Misje (z legendą) jak w komórce; reszta jak w firmie przewozowej.
    return komorka_operacja($firma, $op, $w, $u, $moze);
}

function obsluz_agencja(string $akcja, bool $post): void
{
    switch ($akcja) {
        // MG: meldunek do teczki obserwowanego celu.
        case 'mg_meldunek':
            if (!$post) blad('Zła metoda.', 405);
            wymagaj_mg();
            $w = wejscie();
            $tresc = mb_substr(trim((string) ($w['tresc'] ?? '')), 0, 5000);
            if ($tresc === '') blad('Meldunek jest pusty.');
            zmien('firmy', function (&$wszystko) use ($w, $tresc) {
                foreach ($wszystko['firmy'] as &$f) {
                    if ($f['id'] !== ($w['firma'] ?? '')) continue;
                    foreach ($f['dane']['cele'] as &$c) {
                        if ($c['id'] !== ($w['cel'] ?? '')) continue;
                        $c['meldunki'][] = ['id' => id(), 'data' => date('c'), 'zrodlo' => mb_substr(trim((string) ($w['zrodlo'] ?? '')), 0, 80), 'tresc' => $tresc];
                        return;
                    }
                }
                blad('Nie ma takiej teczki.', 404);
            }, firmy_puste());
            odpowiedz(['ok' => true]);
    }
}

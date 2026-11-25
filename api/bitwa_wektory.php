<?php
// PLIK GENEROWANY: python narzedzia/mostek/test_silnik.py --php — wektory z silnika wzorcowego (Python).
// Port PHP (api/bitwa_silnik.php) musi dać te same wyniki; sprawdza je akcja api/?a=bitwa_test (MG).
declare(strict_types=1);

const BITWA_WEKTORY = [
    'los' => [[1, 0, 'a', 0.9941723814699799], [7, 3, 'traf', 0.8919944341760129], [123456, 3599, 'klucz:Rebelia:4', 0.7337462324649096], [42, 17, 'zażółć', 0.3919994297903031]],
    'przygotowanie' => [['beczka_l', 'kadet', 3], ['beczka_l', 'standard', 2], ['beczka_l', 'as', 1], ['zygzak', 'kadet', 2], ['zygzak', 'standard', 1], ['zygzak', 'as', 1], ['skok_cieniowy', 'kadet', 4], ['skok_cieniowy', 'standard', 3], ['skok_cieniowy', 'as', 2], ['przestrojenie_reczne', 'kadet', 12], ['przestrojenie_reczne', 'standard', 12], ['przestrojenie_reczne', 'as', 12], ['wzmocnij', 'kadet', 3], ['wzmocnij', 'standard', 3], ['wzmocnij', 'as', 3]],
    'hz_skuteczne' => [[3600, 1800, [1, 2], 1799], [3600, 3600, [1, 2], 3598], [2187, 729, [2, 5, 6], 727], [2187, 2187, [], 2187], [3600, 1, [3599], 0]],
    'filtr' => [[1.0, 900, 1200, 0.25], [1.0, 2187, 3600, 0.39249999999999996], [1.0, 3600, 2187, 0.0]],
];

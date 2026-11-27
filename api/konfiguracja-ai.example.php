<?php
// Skopiuj ten plik jako konfiguracja-ai.php i wpisz klucze (plik konfiguracja-ai.php nie trafia do repo).
// Dostawcy są próbowani po kolei: gdy pierwszy odmówi (limit, awaria, zły format), moduł bierze następnego.
// Każdy musi mieć API zgodne z OpenAI (/chat/completions).
return [
    'limit_dzienny' => 900, // wszystkie wywołania AI w grze na dobę (UTC), łącznie dla wszystkich graczy
    'dostawcy' => [
        [
            'nazwa' => 'groq',
            'adres' => 'https://api.groq.com/openai/v1',
            'klucz' => 'WPISZ_KLUCZ_GROQ',          // console.groq.com → API Keys
            'model' => 'openai/gpt-oss-120b',
        ],
        [
            'nazwa' => 'openrouter',
            'adres' => 'https://openrouter.ai/api/v1',
            'klucz' => 'WPISZ_KLUCZ_OPENROUTER',    // openrouter.ai → Keys
            'model' => 'meta-llama/llama-3.3-70b-instruct:free',
            'naglowki' => ['X-Title' => 'HOLOLINK'],
        ],
    ],
];

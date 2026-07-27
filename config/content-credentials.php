<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Signing service
    |--------------------------------------------------------------------------
    |
    | The C2PA signing service (service/) the client talks to. The API key is a
    | shared secret; keep it in the environment, never in version control.
    */
    'service' => [
        'base_url' => env('CONTENTAUTH_SERVICE_URL', 'http://localhost:3000'),
        'api_key' => env('CONTENTAUTH_API_KEY'),
    ],
];

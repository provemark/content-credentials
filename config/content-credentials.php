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

        /*
        | HTTP timeouts (seconds) for the signing-service calls, applied when this
        | package builds the HTTP client (i.e. you have not bound your own
        | PSR-18 client — in which case that client owns its timeouts). Without
        | these, a hung service would block the request/queue worker forever.
        */
        'timeout' => env('CONTENTAUTH_TIMEOUT', 10),
        'connect_timeout' => env('CONTENTAUTH_CONNECT_TIMEOUT', 5),

        /*
        | Reject a signing-service response larger than this many bytes before
        | buffering it into memory (defends against an oversized/hostile
        | response). Default 96 MiB — headroom over the service's request cap.
        */
        'max_response_bytes' => env('CONTENTAUTH_MAX_RESPONSE_BYTES', 100663296),
    ],
];

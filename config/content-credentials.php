<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Reader
    |--------------------------------------------------------------------------
    |
    | How manifests are READ. Signing always goes through the service.
    |
    |   service    (default) read over HTTP via the signing service
    |   extension  read in-process via ext-c2pa; throws if it is not loaded
    |   auto       use the extension when loaded, the service otherwise
    |
    | `auto` is what most people want, and it is deliberately NOT the default:
    | the two readers carry different c2pa-rs versions (0.89.0 in the extension,
    | 0.90.16 in the service), so installing the extension for an unrelated reason
    | must not silently change which engine decides your trust verdicts. Set this
    | yourself and the choice is visible.
    |
    | Install the extension with: pie install ericmann/ext-c2pa
    */
    'reader' => env('CONTENTAUTH_READER', 'service'),

    /*
    |--------------------------------------------------------------------------
    | Trust anchors (extension reader only)
    |--------------------------------------------------------------------------
    |
    | PEM contents, or a path to a PEM file — either works; a path is read for
    | you. Without anchors a signature can be valid but never trusted, which is
    | by design, not a failure.
    |
    | Applies ONLY to the `extension` reader. The service reader's trust
    | verification is configured on the service itself, via
    | CONTENTAUTH_TRUST_SETTINGS. Same concept, two places — if you set this and
    | the service reader still reports isTrusted() false, that is why.
    */
    'trust_anchors' => env('CONTENTAUTH_TRUST_ANCHORS'),

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
        | response). Default 32 MiB: the service caps a body at 20 MB, which
        | carries a ~15 MiB asset, so the largest legitimate response is ~20 MiB
        | plus JSON overhead. Raise both together if you raise MAX_BODY_SIZE.
        */
        'max_response_bytes' => env('CONTENTAUTH_MAX_RESPONSE_BYTES', 33554432),

        /*
        | Refuse an asset larger than this before encoding it, instead of
        | discovering the limit as an HTTP 413 after holding ~3.7x the file in
        | memory. Default 15 MiB — what fits in the service's 20 MB body once
        | base64 inflates it. The service enforces its own limit regardless, so
        | a mismatch costs a worse error message, never a wrong outcome.
        */
        'max_request_bytes' => env('CONTENTAUTH_MAX_REQUEST_BYTES', 15728640),

        /*
        | Plain HTTP to anything other than loopback sends the API key across a
        | network in clear. That is reported as a warning by default, because
        | http://signer:3000 between containers on one private network is a
        | normal deployment. Set this to true to make it fatal instead.
        */
        'require_secure_transport' => env('CONTENTAUTH_REQUIRE_SECURE_TRANSPORT', false),
    ],
];

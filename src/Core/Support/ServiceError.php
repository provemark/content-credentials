<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Support;

/**
 * The signing service's own error text, bounded and safe to put in a log
 * (SPEC-031).
 *
 * Whatever answers on the configured URL controls this string, and it reaches an
 * application's log through an exception message. The service caps every
 * caller-supplied string it records for exactly that reason; this reciprocates.
 *
 * One implementation, deliberately. This existed twice — once in
 * `SigningServiceSigner` and once in `SigningServiceReader`, character for
 * character — and only the signer's copy was ever capped, so a read could carry
 * up to `maxResponseBytes` into a single log line. Two copies of one decision is
 * how one of them gets fixed.
 *
 * @internal not part of the public API
 */
final class ServiceError
{
    /** Characters, not bytes (SPEC-025 AC4 set the number; SPEC-031 the unit). */
    private const MAX_CHARS = 256;

    private const FALLBACK = 'unknown error';

    /**
     * Extract and bound the `error` field of a service response body.
     *
     * A body that is not JSON, or that carries no string `error`, yields the
     * fallback — the caller has the status code, which is the actionable part.
     */
    public static function fromBody(string $body): string
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::FALLBACK;
        }

        if (! is_array($decoded) || ! isset($decoded['error']) || ! is_string($decoded['error'])) {
            return self::FALLBACK;
        }

        return self::cap($decoded['error']);
    }

    /**
     * Truncate to MAX_CHARS *characters*, never mid-character.
     *
     * `substr()` cuts by bytes, so a UTF-8 message cut at byte 256 can end
     * halfway through a codepoint and hand an invalid byte sequence to a log
     * pipeline. The `/u` modifier gives PCRE character semantics and ships with
     * every PHP build, so this needs no `ext-mbstring` — which this package
     * deliberately does not require.
     *
     * The input is valid UTF-8 by construction: it came out of `json_decode()`,
     * which rejects malformed UTF-8 outright (`JSON_ERROR_UTF8`). That guarantee
     * comes from the decoder rather than from anything here, which is why a
     * character-wise cut is sufficient and no repair pass follows it.
     */
    private static function cap(string $error): string
    {
        if (preg_match('/^.{0,'.self::MAX_CHARS.'}/us', $error, $matches) !== 1) {
            // Unreachable while the input comes from json_decode(); falling back
            // rather than returning an unbounded string, because the one thing
            // this class must never do is pass its input through untouched.
            return self::FALLBACK;
        }

        $capped = $matches[0];

        return $capped === $error ? $error : $capped.'… (truncated)';
    }
}

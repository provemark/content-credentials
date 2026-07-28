<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Support;

use Psr\Http\Message\StreamInterface;

/**
 * Reads a PSR-7 response body into a string, refusing to buffer more than a
 * caller-supplied limit — defending the client against an oversized or hostile
 * service response (SPEC-009 #5). Returns null when the limit is exceeded so the
 * caller raises its own typed exception; a declared Content-Length is honoured
 * for a fast reject before any bytes are read.
 */
final class ResponseBody
{
    /**
     * @return string|null the body, or null if it exceeds $maxBytes
     */
    public static function readBounded(StreamInterface $stream, int $maxBytes): ?string
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $size = $stream->getSize();
        if ($size !== null && $size > $maxBytes) {
            return null;
        }

        $body = '';
        while (! $stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
            if (strlen($body) > $maxBytes) {
                return null;
            }
        }

        return $body;
    }
}

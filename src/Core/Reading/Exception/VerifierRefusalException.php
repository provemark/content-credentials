<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading\Exception;

use Provemark\C2paVerifier\Verifier\VerificationReport;

/**
 * A read through provemark/c2pa-verifier that the verifier answered and this package refused
 * (SPEC-046 Amendment 1): a store it refused, or a file it could not read, where its report says
 * so. The report goes with the refusal, so that a caller can still write it down, in crJSON for
 * instance (verifier SPEC-075 amendment 4). The message is the one ReadFailedException carried
 * before; a caller that catches ReadFailedException sees no difference.
 *
 * It names a verifier class, as VerifiedRead does. PHP loads it only when such a refusal is
 * thrown, which only C2paVerifierReader does (ADR-0007).
 */
final class VerifierRefusalException extends ReadFailedException
{
    public function __construct(
        string $message,
        public readonly VerificationReport $verification,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

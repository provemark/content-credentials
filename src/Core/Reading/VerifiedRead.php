<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading;

use Provemark\C2paVerifier\Verifier\VerificationReport;

/**
 * One read through provemark/c2pa-verifier, as both reports of it (SPEC-046):
 * the ManifestReport every reader gives, and the verifier's own
 * VerificationReport of the same verify() call, which carries what the
 * verdict rested on (`evidence()`, `trustLists()`) and writes it down as a
 * document to keep (`evidenceToJson()`, verifier SPEC-089).
 *
 * Only C2paVerifierReader::readVerified() makes one. Its signature names a
 * verifier class, which PHP loads only when this class is used; a host
 * without the verifier cannot build that reader in the first place
 * (ADR-0007).
 */
final readonly class VerifiedRead
{
    public function __construct(
        public ManifestReport $report,
        public VerificationReport $verification,
    ) {}
}

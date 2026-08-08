<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;
use RuntimeException;

/**
 * The extension accepted trust anchors and then reported none (SPEC-032 AC4).
 *
 * Raised instead of reading on, because reading on would answer
 * `isTrusted() === false` for every asset while the operator believes trust
 * verification is configured — the two are indistinguishable from outside, which
 * is the failure SPEC-014 AC5 closed on the service side.
 */
final class TrustAnchorsNotAppliedException extends RuntimeException implements ContentCredentialsException {}

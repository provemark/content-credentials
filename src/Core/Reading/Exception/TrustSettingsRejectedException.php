<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/**
 * Trust settings the verifier refuses: not JSON, a path where PEM contents
 * belong, or a top-level `trust.allowed_list` (SPEC-042 AC6).
 *
 * Raised when the reader is constructed, so a misconfigured trust setup
 * surfaces when it is wired rather than on whichever request reads first.
 */
final class TrustSettingsRejectedException extends \RuntimeException implements ContentCredentialsException {}

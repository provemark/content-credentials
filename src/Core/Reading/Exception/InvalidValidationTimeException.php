<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/**
 * A validation time whose UTC year lies outside 0001–9999 (SPEC-047 AC5).
 *
 * RFC 3339 cannot write such a time, and the verifier would write it into its
 * crJSON as it is. Raised by readVerified() before anything is read.
 */
final class InvalidValidationTimeException extends \InvalidArgumentException implements ContentCredentialsException {}

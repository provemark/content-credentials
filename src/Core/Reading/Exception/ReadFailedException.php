<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/**
 * A read that gave no result. Not final since SPEC-046 Amendment 1, for VerifierRefusalException,
 * which carries the verifier's report of a refused read.
 */
class ReadFailedException extends \RuntimeException implements ContentCredentialsException {}

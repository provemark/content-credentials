<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/**
 * The pure-PHP reader was selected but provemark/c2pa-verifier is not installed
 * (SPEC-042 AC7).
 *
 * Thrown at construction and never softened into a fallback, for the reason
 * ExtensionMissingException gives: a caller who chose a reader and silently got
 * another cannot tell the difference.
 */
final class VerifierMissingException extends \RuntimeException implements ContentCredentialsException {}

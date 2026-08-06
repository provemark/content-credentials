<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/**
 * The reader needs a PHP extension that is not loaded (SPEC-019 AC5).
 *
 * Thrown at construction rather than at read time, and never softened into a
 * fallback: a caller who asked for in-process reading and silently got HTTP
 * cannot tell the difference, and the fallback would need a service URL and
 * token they never supplied — so it would fail later, somewhere unrelated.
 */
final class ExtensionMissingException extends \RuntimeException implements ContentCredentialsException {}

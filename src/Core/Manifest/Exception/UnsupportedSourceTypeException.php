<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Manifest\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/**
 * The digitalSourceType describes an operation on an existing asset, which this
 * package cannot express (SPEC-026 AC4).
 *
 * Raised rather than emitting a `c2pa.created` action carrying an editing term.
 * That would be a well-formed manifest making a false claim: that the asset was
 * created by an operation which by definition acts on one that already existed.
 */
final class UnsupportedSourceTypeException extends \InvalidArgumentException implements ContentCredentialsException {}

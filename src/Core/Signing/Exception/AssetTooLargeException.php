<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Signing\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/**
 * The asset is larger than the signing service will accept (SPEC-025 AC2).
 *
 * Raised before the asset is base64-encoded, because the encoding is where the
 * memory goes: the raw bytes, their base64 and the JSON body together cost
 * roughly 3.7x the file. Learning the limit as an HTTP 413 means paying that
 * first, and a large enough asset kills the worker before any answer arrives.
 */
final class AssetTooLargeException extends \InvalidArgumentException implements ContentCredentialsException {}

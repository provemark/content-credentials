<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Signing\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/**
 * The manifest claims an asset was manipulated, but the original was not
 * supplied (SPEC-028 AC3).
 *
 * C2PA records manipulation as a `c2pa.opened` action pointing at a `parentOf`
 * ingredient, and that ingredient is a hash binding over the original's bytes —
 * so the original is an input to signing, not metadata about it.
 *
 * Raised before the request is built. Nothing below this catches it: an edit
 * intent with no ingredient signs happily and reports `Valid` (measured against
 * c2pa-node 0.8.1; NOTES Step 35), which would produce a manifest asserting a
 * lineage it does not carry.
 */
final class MissingParentAssetException extends \InvalidArgumentException implements ContentCredentialsException {}

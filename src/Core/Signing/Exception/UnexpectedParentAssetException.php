<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Signing\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/**
 * A parent asset was supplied for a manifest that creates rather than edits
 * (SPEC-028 AC4).
 *
 * Refused rather than ignored. A caller who passes an original believes it is
 * being recorded; accepting and discarding it would sign a manifest that omits
 * exactly what they thought they were asserting — and c2pa-rs reports that
 * manifest as `Valid`, so nothing downstream would tell them otherwise.
 */
final class UnexpectedParentAssetException extends \InvalidArgumentException implements ContentCredentialsException {}

<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Signing\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/** The asset's media type does not match the manifest's declared media type. */
final class MediaTypeMismatchException extends \InvalidArgumentException implements ContentCredentialsException {}

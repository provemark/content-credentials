<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Signing\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/** The signing service returned a non-2xx response. */
final class SigningFailedException extends \RuntimeException implements ContentCredentialsException {}

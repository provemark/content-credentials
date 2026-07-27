<?php

declare(strict_types=1);

namespace ContentCredentials\Core\Signing\Exception;

use ContentCredentials\Core\Support\ContentCredentialsException;

/** The signing service returned a non-2xx response. */
final class SigningFailedException extends \RuntimeException implements ContentCredentialsException {}

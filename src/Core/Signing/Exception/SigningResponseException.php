<?php

declare(strict_types=1);

namespace ContentCredentials\Core\Signing\Exception;

use ContentCredentials\Core\Support\ContentCredentialsException;

/** A 2xx response whose body could not be understood (non-JSON, missing or invalid signed_content). */
final class SigningResponseException extends \RuntimeException implements ContentCredentialsException {}

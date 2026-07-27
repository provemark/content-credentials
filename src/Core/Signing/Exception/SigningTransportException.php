<?php

declare(strict_types=1);

namespace ContentCredentials\Core\Signing\Exception;

use ContentCredentials\Core\Support\ContentCredentialsException;

/** The PSR-18 client failed to complete the request (network / DNS / TLS). */
final class SigningTransportException extends \RuntimeException implements ContentCredentialsException {}

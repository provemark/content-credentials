<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Signing\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/** The PSR-18 client failed to complete the request (network / DNS / TLS). */
final class SigningTransportException extends \RuntimeException implements ContentCredentialsException {}

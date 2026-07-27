<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading\Exception;

use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/** The PSR-18 client failed to complete the read request. */
final class ReadTransportException extends \RuntimeException implements ContentCredentialsException {}

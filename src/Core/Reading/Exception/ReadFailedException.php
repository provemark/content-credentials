<?php

declare(strict_types=1);

namespace ContentCredentials\Core\Reading\Exception;

use ContentCredentials\Core\Support\ContentCredentialsException;

/** The read service returned a non-2xx response. */
final class ReadFailedException extends \RuntimeException implements ContentCredentialsException {}

<?php

declare(strict_types=1);

namespace ContentCredentials\Core\Manifest\Exception;

use ContentCredentials\Core\Support\ContentCredentialsException;

/** Thrown when a MIME type is not one of the supported asset formats. */
final class UnsupportedMediaTypeException extends \InvalidArgumentException implements ContentCredentialsException {}

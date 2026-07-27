<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Support;

/**
 * Marker interface implemented by every exception this library throws, so
 * callers can catch all of them with a single type.
 */
interface ContentCredentialsException extends \Throwable {}

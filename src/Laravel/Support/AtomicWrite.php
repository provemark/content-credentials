<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Laravel\Support;

/**
 * Write a file so that it is observed whole or not at all (SPEC-025 AC5).
 *
 * `file_put_contents()` writes in place, so a crash or a full disk mid-write
 * leaves a truncated file at the destination — and a truncated signed asset
 * still looks like a signed asset until somebody verifies it. Writing to a
 * temporary file and renaming makes the appearance atomic: `rename()` within a
 * filesystem is, and the temporary file is created in the destination's own
 * directory precisely so the rename cannot cross one. A rename across
 * filesystems degrades to a copy, which is the non-atomic write being replaced.
 *
 * @internal not part of the public API
 */
final class AtomicWrite
{
    public static function toPath(string $path, string $contents): bool
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            return false;
        }

        $temporary = @tempnam($directory, '.cc-');

        if ($temporary === false) {
            return false;
        }

        if (@file_put_contents($temporary, $contents) === false) {
            @unlink($temporary);

            return false;
        }

        // tempnam() creates the file 0600; a signed asset is not a secret, and
        // inheriting the umask is what a caller expects of an output file.
        @chmod($temporary, 0644 & ~umask());

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }
}

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

        // `=== false` already covers the short write, which is the case this
        // class exists for and the one a reader is most likely to think is
        // missing. `file_put_contents()` is documented as returning the number
        // of bytes written, so comparing against `strlen($contents)` looks like
        // the stricter test — it is not. PHP collapses a partial write to
        // `false` itself: measured on 8.5.8 through a stream wrapper that
        // accepts 10 of 5000 bytes, the call warns `Only 10 of 5000 bytes
        // written, possibly out of free disk space` and returns `false`.
        //
        // So a full disk cannot reach the rename below. Do not "tighten" this
        // to a length comparison; it would add nothing, and the reasoning that
        // it is needed has already been wrong once.
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

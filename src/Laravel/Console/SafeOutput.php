<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Laravel\Console;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Neutralise a value that came from outside, for terminal output (SPEC-040 AC4).
 *
 * Extracted from `ReadCommand::fromManifest()` unchanged, because there is now
 * more than one way for untrusted text to reach the same terminal: a manifest
 * value the report prints (SPEC-006 AC8), and an exception message carrying
 * bytes an engine echoed out of the asset it refused (SPEC-040).
 *
 * **One implementation, deliberately.** A second would be a second definition of
 * "neutralised", and this package has already watched one control get applied to
 * half its sinks: the escaping added on 2026-08-13 closed the markup route and
 * left the direct-ANSI route open, because `OutputFormatter::escape()` is one
 * `preg_replace` over `<`, `>` and a trailing backslash. Same argument that
 * keeps `ManifestStoreParser` a single decoder.
 *
 * Two hazards, and escaping covers only one:
 *
 * - **Symfony markup.** `line()` and `error()` go through `OutputFormatter`,
 *   which reads `<...>` as markup.
 * - **Control characters.** A raw `ESC` (0x1B) walks through `escape()` in the
 *   decorated and the undecorated formatter alike. Measured 2026-09-05: a
 *   complete CSI sequence fits in the four asset bytes c2pa-rs quotes back on a
 *   signature mismatch — `ESC[2J` clears the screen, `ESC[7m` inverts it.
 *
 * Stripped, not escaped: the printable tail is kept (`ESC[2J` becomes the
 * literal `[2J`), so the value stays legible and an operator can still see what
 * failed, while nothing in it can move a cursor. `\n` and `\t` are outside the
 * class on purpose — they are the formatting the reports themselves use.
 *
 * **This belongs at the command and not at the reader.** SPEC-033 AC4 requires
 * accessors to return manifest values byte-for-byte, so filtering underneath
 * would violate an implemented criterion and change what every caller sees — a
 * caller rendering to HTML needs the bytes; the terminal is the sink that does
 * not. SPEC-040 AC8 pins that the accessors keep behaving as they do.
 *
 * @internal not part of the public API
 */
final class SafeOutput
{
    /**
     * Byte-based on purpose: the input may not be valid UTF-8.
     *
     * `ServiceError::fromBody()` may assume valid UTF-8 because `json_decode()`
     * guarantees it. An exception message out of ext-c2pa carries no such
     * guarantee, so a `/u` pattern here would fail on exactly the input this
     * exists for and `preg_replace()` would return null (SPEC-040 AC6).
     */
    public static function fromOutside(string $value): string
    {
        // C0 except tab and newline, DEL, and the C1 range in its UTF-8 form.
        $stripped = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', '', $value);

        return OutputFormatter::escape($stripped ?? '');
    }
}

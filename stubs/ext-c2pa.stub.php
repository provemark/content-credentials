<?php

declare(strict_types=1);

/**
 * SPEC-019 — type-checking stub for `ericmann/ext-c2pa`.
 *
 * The classes below exist only when the native extension is loaded, so PHPStan
 * would otherwise report every call as an unknown class. This file is listed in
 * `phpstan.neon` under `scanFiles` and is never autoloaded or executed.
 *
 * Written here rather than vendored from the extension's own
 * `stubs/c2pa.stubs.php` for two reasons. That file is GPL-2.0-or-later, and
 * copying it into this repository raises a licensing question this spec should
 * not answer. And a stub we author is one we can keep minimal: it declares only
 * the members `ExtC2paReader` actually calls, so an upstream addition cannot
 * silently widen what we type-check against.
 *
 * Signing members (`Builder`, `Signer`) are deliberately absent. In-process
 * signing is out of scope for SPEC-019 — it moves the private key into the web
 * process, which is the trade ADR-0003 rejected — and a stub that does not
 * describe it is one more thing that would have to change before it could be
 * built by accident.
 *
 * Verified against ext-c2pa v0.1.0 on 2026-08-06.
 *
 * @see specs/SPEC-019-ext-c2pa-reader.md
 */

namespace Automattic\VIP\C2PA;

if (! extension_loaded('c2pa')) {
    /** Trust configuration handed to a Reader. */
    class Settings
    {
        public function __construct() {}

        /**
         * Set the PEM bundle of trusted C2PA anchors.
         *
         * Takes PEM **contents**, not a path — the same convention c2patool and
         * c2pa-node use, and the one this project has been caught by before
         * (NOTES.md Step 11).
         */
        public function withTrustAnchors(string $pem): void {}

        public function hasTrustAnchors(): bool {}
    }

    /** A decoded C2PA manifest store. */
    class Reader
    {
        public static function fromBytes(string $bytes, string $mime, ?Settings $settings = null): Reader {}

        public function hasManifest(): bool {}

        /** `Trusted` | `Valid` | `Invalid` | … — mapped onto our ValidationState. */
        public function validationState(): string {}

        public function isValid(): bool {}

        public function isTrusted(): bool {}

        /** The manifest store as JSON, decoded by our existing ManifestReport. */
        public function json(): string {}

        public function summary(): string {}
    }

    class C2paException extends \RuntimeException {}
}
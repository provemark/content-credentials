<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading;

use Provemark\ContentCredentials\Core\Reading\Exception\TrustAnchorsNotAppliedException;

/**
 * The post-condition on configuring trust anchors (SPEC-032 AC3/AC4).
 *
 * A seam rather than an inline `if`, because the negative case cannot be
 * produced with a real `Settings` object: measured against ext-c2pa v0.1.0 on
 * 2026-08-08, `hasTrustAnchors()` answers true even for garbage PEM, and garbage
 * fails loudly later at read time. So the case this guards is the setter not
 * taking effect at all — a rename, a signature change, or an immutable-fluent
 * redesign upstream — and the only way to test that is to hand the check its
 * answer directly.
 *
 * @internal not part of the public API
 */
final class TrustAnchorsGuard
{
    /**
     * @param  bool  $applied  what the extension reports after the anchors were set
     *
     * @throws TrustAnchorsNotAppliedException when it reports none
     */
    public static function ensureApplied(bool $applied): void
    {
        if ($applied) {
            return;
        }

        throw new TrustAnchorsNotAppliedException(
            'Trust anchors were passed to ext-c2pa but the extension reports them as not applied. '
            .'Reading on would report every asset as untrusted while trust verification appears '
            .'configured, so this fails instead. Check the installed extension version: this is the '
            .'shape an upstream change to Settings::withTrustAnchors() would produce.'
        );
    }
}

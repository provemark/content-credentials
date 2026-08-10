# Step 24 — Correcting Step 23: what ExtC2paReader actually unlocks (2026-08-06)

Same day, prompted by one question: *why does his extension work with WordPress?*
The answer undoes a claim Step 23 and SPEC-019 both rest on, so it is recorded
here rather than left to be rediscovered.

### The claim that was wrong

SPEC-019's Problem section argues: typical WordPress hosting cannot run a second
process, so the signing-service requirement puts verification out of reach of the
80,000+ installs identified in Step 16 — and the extension removes that.

**The first half holds. The second does not.** Cheap shared hosting cannot
install a native PHP extension either. One barrier was swapped for another and
reported as the barrier being gone.

### Why it works for Automattic and not for wordpress.org

`ext-c2pa` is the extension half of **wp-c2pa, a VIP product**. Automattic VIP
*is* the host: they build the PHP runtime, so they can ship `c2pa.so` in the
image and a plugin may simply assume it is present. No plugin distributed through
wordpress.org can assume anything of the sort. That is why an extension-based
design is viable there and nowhere near the mass market.

Which also re-reads the "it is a VIP product, not neutral infrastructure" note
from Step 23 more sharply: it is not only a governance caveat about the API
moving, it explains the deployment model the whole extension presupposes.

### What ExtC2paReader really buys, stated honestly

Not reach. **Operating cost.** The people who gain are those who control their own
PHP — VPS, containers, Forge/Ploi-style platforms, CI pipelines:

- no second process to run, secure, monitor and update
- no network hop, no token, no `.env` entry for something that needs no secret
- works offline, and is markedly faster

The uncomfortable part, and the reason to write it down: **anyone with enough
control to install an extension can usually also run a service.** So this does not
open a new audience; it makes the existing one much cheaper to operate. That is a
real improvement and it is not the one that was claimed.

### What reach would actually require

A third route: a reader in **pure PHP** — no extension, no service. JUMBF, CBOR,
COSE signature verification and the hash binding, using `openssl`/`sodium`.
That was called "the real work" before ext-c2pa was found, and finding the
extension was read as making it unnecessary. It made it *cheap*, for a different
problem. The mass-market problem is still open, and still needs that.

Not proposed as work — it is a large spec and there is no user asking for it. But
the next time the adoption question comes up, this is the honest map.

### The specs are not edited

SPEC-019 is `implemented` and frozen outside Traceability, and its Problem
section is what was believed when it was approved. The correction lives here,
where NOTES.md is authoritative per CLAUDE.md. The README never made the claim,
so nothing shipped to users needs fixing — which is the one piece of luck in
this.

---

[← Step 23](step-23-spec-019-ext-c2pa-reader.md) · [index](../NOTES.md) · [Step 25 →](step-25-spec-021-seven-more-media-types.md)

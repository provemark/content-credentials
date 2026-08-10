# Step 16 — Open items (2026-08-05)

All six findings of the security review are closed, and SPEC-001..015 are
implemented. What follows is what a next session should pick up, with enough
context to act without re-deriving it.

### 1. ~~Pending release decision: v0.5.1~~ — DONE (2026-08-05)

Released as v0.5.1, with the reasoning that mattered: the dist is unchanged, but
it *is* a behaviour change for anyone running the service — a client that fans
out now gets 429s, and a stalled connection is closed. Leaving that unannounced
on `main` is how someone discovers it from a support ticket.

### 2. `MAX_BODY_SIZE` is still 50 MB

The single biggest remaining lever, and the multiplier under every limit
SPEC-015 introduced: the signing path holds roughly four copies of the asset at
once, so four concurrent 50 MB requests is ~800 MB. Express also buffers the
body **before** any limit is consulted, so a concurrency cap cannot protect the
memory spent admitting a request it then refuses — only a smaller body limit
can.

50 MB is far above any PNG or JPEG this service legitimately signs. Lowering it
is a behaviour change for anyone signing large assets, which is why SPEC-015 put
it out of scope rather than deciding it quietly. Currently documented in the
README as the operator's lever. Needs its own decision, and a spec if the
default changes.

### 3. Per-client tokens — the real gap behind two specs

SPEC-012 identifies **which token** signed something, not **which client**.
SPEC-015 rate-limits **per token**, not per client. With one shared credential
those are the same thing, so both specs are correct today and quietly weaker
than they look tomorrow: the moment there is a second consumer of the service,
one shared token is the actual weak point, and neither the audit trail nor the
rate limit can attribute or bound anything per consumer.

The path, in order:
1. Multiple named tokens rather than a single `CONTENTAUTH_API_KEY` — `token_id`
   and the rate-limit buckets already key on the right thing, so most of the
   machinery exists.
2. CAWG organisational identity assertions, so the *manifest* carries who
   produced it rather than only the log. The upstream contract this service
   mirrors already has `signature_type: cawg_org` (NOTES Step 1); c2pa-node
   exports `createCawgTrustSettings`, deliberately left out of SPEC-014.

This is the largest remaining piece of design, and the one that turns the
audit log from "we signed this" into "they asked us to".

**The trigger is not adoption.** Worth stating precisely, because it is easy to
get wrong: every Composer install is a *separate* deployment running its own
service with its own token, so a hundred installs still means a hundred
one-caller setups and SPEC-016 helps none of them. What matters is topology
inside a single deployment — more than one caller on the same instance. The
realistic first case is a user pointing staging and production at one service,
because certificates are not cheap enough to duplicate.

So this is a feature for *users*, and the signal to build it is a user saying
they share an instance — asking how to tell environments apart in the audit
log, or why staging is spending production's rate limit. Until then the design
has no real deployment to shape it. The README now states the limitation and
invites exactly that report, which costs nothing and produces the signal.

### 4. Where the PHP users actually are (researched 2026-08-06)

Not a task, a finding to keep. Looked into who could realistically use this, and
what else exists.

**Competition is one package.** [`jrglasgow/c2patool`](https://packagist.org/packages/jrglasgow/c2patool)
— 0.5.2, **1,775 installs**, 0 dependents, 0 stars, last published Feb 2026. It
wraps the `c2patool` binary through `symfony/process`, so the private key sits
on the web server and the PHP process shells out. That is the exact trade
ADR-0003 rejected. There is no official CAI library for PHP; they maintain Rust,
JavaScript and Python.

**The PHP mass is WordPress, not Laravel.** AI Engine alone has 80,000+ active
installs and generates images; AI Power / AI Puffer 10,000+; AIOSEO 3M+ with
image generation as a feature. On the credential side only a *viewer* plugin
exists — reading and displaying, not signing.

**Laravel orchestrates, it does not generate.** Prism handles images as *input*;
generation goes through `openai-php/laravel` or the Laravel AI SDK against
DALL·E/Gemini. So the target is not "a product that generates in PHP" — it is a
PHP product whose feature is generation, implemented against an API.

Worth knowing: OpenAI already attaches C2PA credentials to its image output, and
a PHP app that thumbnails or re-encodes that image **destroys** it. That is both
the risk and the argument — such an app must either preserve the upstream
credential (hard in a normal image pipeline) or re-sign under its own identity,
which is what this package is for.

**A WordPress plugin: viewer yes, signer probably not.** Typical WordPress
hosting cannot run a second process, so a signing plugin would have to either
shell out to a binary (the key back on the web server, ADR-0003 again) or call a
remote service (which filters out most of that 80,000). A **viewer** plugin has
none of those problems: no keys, no service, no liability, and it reuses
`SigningServiceReader` almost unchanged. That is the cheap way into the
WordPress ecosystem without compromising the architecture — the signing side
stays where it belongs, with people who can run a service.

Not a decision, just the map. The Core is framework-agnostic and needs only
PSR-18, so nothing is lost by waiting.

---

[← Step 15](step-15-spec-015-rate-limiting-and-concurrency.md) · [index](../NOTES.md) · [Step 17 →](step-17-integration-suite-in-ci.md)

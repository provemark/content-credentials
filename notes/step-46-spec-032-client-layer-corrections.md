# Step 46 — SPEC-032: the last four review findings, and a test double that fought back (2026-08-08)

The remainder of Step 40's list, bundled on SPEC-025's reasoning. All four are
the same shape: the client layer states something and then does something else.
None is a crash, which is why all four survived — nothing contradicted them out
loud.

### What each one turned out to be

**The CLI help** was the fifth stale enumeration (Step 37 counted four). Both
commands now build their signature in a constructor from
`InfersMediaType::EXTENSIONS`, so `artisan list` cannot drift from what the trait
accepts. Also "asset", not "image": thirteen media types, three of them video.

**Two HTTP clients** became one, memoised on the provider. Deliberately *not*
bound as `ClientInterface` in the container — a library that binds a global
interface hands its client, and its timeouts, to anything else that resolves it.
Sharing a connection pool and owning a global binding are separable, and only the
first was wanted.

**The trust-anchor guard** is narrower than the finding first suggested, and the
measurement is why. Against ext-c2pa v0.1.0:

| Probe | Result |
|---|---|
| `hasTrustAnchors()` before / after | `false` → `true` |
| `withTrustAnchors('not a pem')` | accepted, then reports `true` |
| reading with garbage anchors | **throws** at read time |

So the silent no-op that c2pa-node had (Step 11) does **not** exist here — bad
material fails loudly. What is unguarded is the setter ceasing to take effect at
all, after which every asset reads as untrusted while trust appears configured.
The guard closes exactly that and the spec says so; claiming more would have been
easy and wrong.

**The job's retries.** `AssetTooLargeException` and friends now fail immediately
instead of sleeping up to six minutes to fail identically three times.

### ⚠️ Two design forks that the tests decided, not the code

**The negative trust case cannot be produced with a real `Settings`.** Measured
above: no input makes `hasTrustAnchors()` answer false after a successful call.
So an inline `if` would have been untestable in the direction that matters — the
shape this log keeps recording as "green while testing nothing". Hence
`TrustAnchorsGuard::ensureApplied(bool)`: a seam that can be handed its answer.
The spec's AC4 had already assumed one by phrasing its Given as "a Settings
object that … still reports no anchors", which is only satisfiable with
injection. Worth noticing that the criterion forced the design before the code
was written, which is what tests-first is for.

**`InteractsWithQueue::fail()` is a no-op when `$this->job` is null**, which is
exactly how a unit test calls `handle()`. Written naively, AC5 would have
swallowed the exception and asserted nothing. Two consequences: the test supplies
a fake `Job`, and the implementation rethrows when there is no job rather than
failing silently outside a queue. Both were named in the spec's open questions
before implementation rather than found during it.

### `illuminate/queue` joins require-dev

`InteractsWithQueue` lives there, and only `config`, `console`, `container` and
`support` were declared. Added to `require-dev` and to the four `--with=` lines
in the CI matrix, which is the thing that would have gone stale silently — the
matrix is what makes the supported Laravel range real (CLAUDE.md, Architecture).
Not a runtime dependency; consumers are unaffected.

### ⚠️ PHPStan level max versus a queue-job double

Five rounds of `return.unusedType` and `property.unusedType` on a double whose
only job is to satisfy an interface: `uuid()` never returns null, `maxTries()`
never returns null, `backoff()` never returns int, and so on. Each observation
was *true* and each edit was noise.

Settled with one nullable property the nullable members return, which keeps the
declared unions genuinely inhabited — and it has to be `public`, because PHPStan
narrows a private one the same way. Recorded because the next person writing an
interface double at level max will meet this within minutes, and the instinct
(add an ignore) is the wrong one: CLAUDE.md forbids un-annotated ignores, and the
property costs three lines.

### Verified

`composer check` green (**324 passed**). Integration 136 passed / 16 skipped.
`php bin/spec-check.php` 0 errors. CLI help smoke-checked by hand: fifteen
extensions, "asset" not "image".

### Step 40's list is closed

Twelve findings, twelve resolved: three defects became SPEC-029, SPEC-030 and
SPEC-031; the runtime and containment went in as Step 44; these four as SPEC-032.

---

[← Step 45](step-45-spec-031-scope-versus-criterion.md) · [index](../NOTES.md) · [Step 47 →](step-47-equivalence-test-configurations.md)

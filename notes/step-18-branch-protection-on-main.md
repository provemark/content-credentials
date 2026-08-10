# Step 18 — Branch protection on `main` (2026-08-05)

A ruleset (`main`, id 20475597) rather than classic branch protection: rulesets
are inspectable and manageable through the API, and can be versioned.

| Rule | Setting |
|---|---|
| Pull request required | yes, **0 required approvals** |
| Required status checks | **`all checks passed`** only |
| Strict (branch up to date) | no |
| Force pushes | blocked |
| Deletion | blocked |

Zero required approvals is not a weakness for a solo maintainer: GitHub does not
let you approve your own pull request, so requiring one would lock the only
person with commit rights out of their own repository. The value here is the
enforced PR plus a green run, not a second pair of eyes that does not exist yet.
Raise it when there is a second committer.

### Why one aggregate check rather than the real ones
The twelve jobs are matrix jobs, so their names carry their parameters —
`composer check (PHP 8.3, Laravel 11)` and so on. A required-checks list built
from those goes stale the moment the matrix changes, and it fails in the
dangerous direction: add Laravel 14 and its job is simply *not required*, so a
red run merges. `all-green` depends on every job and is the only name the
ruleset knows.

`if: always()` on that job is load-bearing. Without it the job is **skipped**
when anything upstream fails, and a skipped required check does not block a
merge — the protection would silently permit exactly what it exists to stop.

### Verified in both directions before trusting it
A required check that cannot fail is worse than none. The aggregator was pushed
together with a deliberately failing test (PR #20): `composer check` went red,
`integration (defaults)` stayed green, and `all checks passed` reported **red** —
so it reports the run, not one job. Then the probe was removed and everything
went green.

The first attempt at that probe was named `TemporaryAggregatorProbe.php`, which
Pest does not collect — it wants a `Test.php` suffix. So the deliberate failure
never ran, and `all checks passed` went green, which looked exactly like the
aggregator working. Same failure shape as SPEC-014's silent trust settings and
the old `isTrusted()`: something reports success while testing nothing.

### The bypass actor, and how to get it right
`bypass_actors` decides whether the maintainer can still push straight to
`main` — which matters here, because the release commits in Steps 12–17 were
direct pushes. Set it wrong and the release flow breaks the next time it is
used, not when the ruleset is created.

`{"actor_type": "RepositoryRole", "actor_id": 5}` did **not** work: this
account's admin rights come from owning the organisation, not from an explicit
repository role. `{"actor_type": "OrganizationAdmin", "actor_id": 1}` does.

⚠️ `GET /repos/{owner}/{repo}/rules/branches/main` cannot confirm this. It lists
the rules on the branch regardless of whether the caller may bypass them, so it
reported all four rules as applying in both configurations — the working one and
the broken one. The only reliable test is to attempt the push: it succeeds with
the bypass in place, printing `Required status check "all checks passed" is
expected` as a warning rather than an error.

Remove the bypass to make the ruleset absolute, at the cost of routing release
commits through a pull request too.

---

[← Step 17](step-17-integration-suite-in-ci.md) · [index](../NOTES.md) · [Step 19 →](step-19-official-c2pa-trust-list.md)

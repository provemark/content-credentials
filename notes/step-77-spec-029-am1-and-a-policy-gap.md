# Step 77 — SPEC-029 Amendment 1, and a gap in `REQUIRE_AI_MARKING` (2026-11-02)

`POST /v1/sign` now refuses a request that carries no actions assertion
(PR #204, squash `8cbcdc6`). Writing the amendment turned up a gap in the
AI-marking policy that had been there since SPEC-011. Both are closed by the
same check.

## Why the amendment

SPEC-011 settled "at most one actions assertion, not required" on 2026-08-05.
SPEC-029 kept that permission, and its AC6 even tested that a request without
an actions assertion still signs. That was a statement about one engine:

- **c2pa-rs 0.90** signs such a manifest, and every verifier reads it back
  `Invalid` with `assertion.action.malformed`.
- **c2pa-rs 0.91** verifies after signing (c2pa-rs #2277) and refuses it. The
  service answered 500 through its catch path, with engine wording as the
  audit reason (measured on c2pa-node 0.9.10, Step 74).

Neither outcome is useful to a caller. The amendment requires an actions
assertion to **exist**. What it says stays the caller's: SPEC-011's reasoning
was about contents, and that resolution stands, with a pointer line in
SPEC-011. The check is engine-independent, so it shipped before the bump. Of
the two 0.91 findings in Step 74, only the missing timestamp (c2pa-js#244) is
left.

## The gap

Measured on the current service (c2pa-node 0.9.5) with
`REQUIRE_AI_MARKING=true`:

| request | before #204 |
|---|---|
| `extra_assertions: []` | 400, `this service is configured to sign only AI markings` |
| no `extra_assertions` key | **200, signed**, no AI marking |

The first line of `rejectAssertions()` was
`if (assertions === undefined) return null;`. It returned before every later
check in the function, the policy check included. So a deployment whose
certificate exists only to mark AI content would sign anything, as long as the
key was left out. The early return and the policy check arrived in the same
commit (`6445e2d`, SPEC-011, 2026-08-05), so the gap is as old as the policy.
Reaching it needs the service token, so only the
deployment's own application could do it, by mistake or otherwise.

Why nothing saw it:

- **The criterion did not ask.** SPEC-011 AC8 speaks about a request that
  carries an actions assertion. Absence was outside its wording.
- **The `hardened` profile always sent the key.** Every test that runs under
  the policy sends `extra_assertions`, usually built by `ManifestBuilder`. The
  absent-key path ran only in `defaults`, where the policy is off and a 200 was
  the expected answer.
- **It was found by writing a criterion, not by a test.** Spelling out AC12
  ("under the policy, both forms are refused") forced the question of what the
  absent key does today. The answer was measured before it was written down,
  and the first draft of AC12 was wrong about it: it claimed both forms already
  answered with the policy reason.

The same shape as the rule about tests that pass while testing nothing, one
level up: a guard that is only exercised with the input that never triggers it.

## What changed

- `rejectAssertions()` treats an absent key as `[]`, and after the "at most
  one" count refuses `actionsCount === 0` with
  `an actions assertion (c2pa.actions.v2) is required`. That check sits before
  the policy check, so the structural reason wins over the policy reason.
- Tests first, each seen red on the pre-amendment service: AC10 in three
  datasets (no key, `[]`, only a non-actions assertion; 200 each), AC12 in two
  (no key: 200; `[]`: the policy reason). They assert the error text and the
  audit reason, not only the status, because on 0.9.10 a status-only refusal
  test had passed for the engine's reason (Step 74).
- AC6's old "still signs" test is gone. `spec030Post()` in the `auth-limited`
  tests now sends an actions assertion.
- `docs/service.md` and primer §3 say the assertion is required.

## Verified

- Local, default settings: integration 219 passed / 25 skipped (217 / 23
  before; the added and removed tests account for the difference exactly).
- Local, `REQUIRE_AI_MARKING=true`: 220 / 24. `--group=SPEC-030` with the
  `auth-limited` settings: 11 / 4, the valid-token signing test among the
  passes.
- `bin/e2e.php` trusted with the Article 50 mark, `composer check` 528 / 7.
- CI 20 / 20. The `hardened` job log shows all five new cases ran rather than
  skipped.
- **Not measured:** that a c2pa-node 0.91 build now answers 400 instead of
  500. It follows from the check running before the engine, but it was not
  run. That belongs to the bump.

## Who notices

`ManifestBuilder` users: nobody, every builder entry point emits one actions
assertion. A hand-built `new Manifest($type, [])` now gets a
`SigningFailedException` where it used to get an `Invalid` manifest; nothing in
this repository builds one. Direct callers of the service that send no actions
assertion get a 400. The behaviour is in `service/`, so it arrives through
`git pull` and a rebuild. The docs are in `docs/` and ship with the next tag.

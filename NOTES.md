# C2PA Signing Spike — NOTES

Running log of friction points, gotchas and decisions. This file is a
first-class deliverable: it feeds the later specs and a public article.
Newest findings appended per step.

Environment (verified 2026-07-27):
- macOS (darwin 25.5.0), zsh
- Docker 29.6.2 + Docker Compose 5.3.1, daemon running
- PHP 8.5.8 CLI (task asked for 8.3; 8.5 is what's installed — fine for a spike)
- c2patool: NOT installed. cargo: absent. (Prebuilt universal-apple-darwin
  binary is available from c2pa-rs releases, currently c2patool-v0.27.3.)

---

**This page is an index.** The log itself lives in `notes/`, one file per step,
copied verbatim from what used to be this file. It was split on 2026-08-10
because the whole log was ~190 KB and loaded into every session, which is the
most expensive possible position for context (see the cost note in `CLAUDE.md`).

Read the row you need, not the log. To search across all steps:

```bash
grep -rn "<term>" notes/          # find the step
grep -n "^## " notes/step-40-*.md # then its section headings
```

Cross-references elsewhere in the repository name steps by number
("NOTES Step 27"); the number is the filename prefix, so `notes/step-27-*.md`
always finds it.

## Where a subject was settled

Follow these before searching; most questions are already answered in one step.

| Subject | Steps |
|---|---|
| Trust verification and settings documents | [11](notes/step-11-c2pa-node-trust-settings.md), [12](notes/step-12-spec-014-trust-verification-in-read.md), [13](notes/step-13-spec-013-istrusted-fails-closed.md), [19](notes/step-19-official-c2pa-trust-list.md), [47](notes/step-47-equivalence-test-configurations.md) |
| TSA timestamping (async path, fails closed) | [6](notes/step-06-spec-007-tsa-timestamping.md), [23](notes/step-23-spec-019-ext-c2pa-reader.md) |
| Memory multipliers and body-size limits | [20](notes/step-20-spec-017-body-size-default.md), [30](notes/step-30-spec-024-bounding-the-read-path.md), [37](notes/step-37-spec-028-implemented-article-50-2.md), [43](notes/step-43-spec-029-and-spec-030-implemented.md) |
| Media types: what works, what does not, and why | [25](notes/step-25-spec-021-seven-more-media-types.md), [27](notes/step-27-measuring-remaining-formats-and-pdf.md), [28](notes/step-28-spec-023-thirteen-media-types.md) |
| `digitalSourceType` vocabulary and its traps | [26](notes/step-26-spec-022-builder-entry-point-name.md), [32](notes/step-32-digitalsourcetype-research.md), [33](notes/step-33-spec-026-three-claims.md) |
| Ingredients, `c2pa.opened`, manipulated content | [35](notes/step-35-spec-028-drafted-two-questions.md), [37](notes/step-37-spec-028-implemented-article-50-2.md), [38](notes/step-38-reviewing-0-9-0-after-shipping.md) |
| The two readers, ext-c2pa, and who it reaches | [23](notes/step-23-spec-019-ext-c2pa-reader.md), [24](notes/step-24-correcting-step-23-what-it-unlocks.md), [47](notes/step-47-equivalence-test-configurations.md) |
| Service hardening: limits, audit, auth ordering | [14](notes/step-14-spec-011-and-spec-012.md), [15](notes/step-15-spec-015-rate-limiting-and-concurrency.md), [29](notes/step-29-codebase-review-two-defects.md), [42](notes/step-42-spec-030-peer-identity.md), [43](notes/step-43-spec-029-and-spec-030-implemented.md) |
| Container, runtime and reproducible builds | [10](notes/step-10-reproducible-service-builds-npm-ci.md), [22](notes/step-22-dependabot-first-prs-and-v0-5-3.md), [44](notes/step-44-node-24-and-container-hardening.md) |
| CI profiles, branch protection, the rate-limit trap | [17](notes/step-17-integration-suite-in-ci.md), [18](notes/step-18-branch-protection-on-main.md), [39](notes/step-39-correcting-step-38-no-second-flake.md) |
| Tests that passed while testing nothing | [18](notes/step-18-branch-protection-on-main.md), [20](notes/step-20-spec-017-body-size-default.md), [21](notes/step-21-spec-018-rotation-and-scanning.md), [23](notes/step-23-spec-019-ext-c2pa-reader.md), [26](notes/step-26-spec-022-builder-entry-point-name.md), [34](notes/step-34-spec-027-the-anchors-it-broke.md), [36](notes/step-36-adr-0004-and-the-link-check-again.md), [37](notes/step-37-spec-028-implemented-article-50-2.md), [43](notes/step-43-spec-029-and-spec-030-implemented.md) |
| Corrections to earlier steps | [24](notes/step-24-correcting-step-23-what-it-unlocks.md) (of 23), [39](notes/step-39-correcting-step-38-no-second-flake.md) (of 38), [40](notes/step-40-outsider-review-envelope-guard.md) (of 29) |
| Client layer: bounds, errors, Laravel | [31](notes/step-31-spec-025-client-side-bounds.md), [45](notes/step-45-spec-031-scope-versus-criterion.md), [46](notes/step-46-spec-032-client-layer-corrections.md) |
| Conformance programme and what it means for us | [21](notes/step-21-spec-018-rotation-and-scanning.md) |

## The log

| Step | Date | What it established |
|---|---|---|
| **1** | 2026-07-27 | [Investigate the wp-plugin signing-service (github.com/contentauth/wp-plugin)](notes/step-01-investigate-wp-plugin-signing-service.md) |
| **2/3** | 2026-07-27 | [Build the service + client (friction log)](notes/step-02-03-build-service-and-client.md) |
| **4/5** | 2026-07-27 | [End-to-end result](notes/step-04-05-end-to-end-result.md) |
| **6** | 2026-07-27 | [SPEC-007 TSA timestamping (verified findings)](notes/step-06-spec-007-tsa-timestamping.md) |
| **7** | 2026-07-27 | [Property-based test suite (Eris) + a real service bug it caught](notes/step-07-property-based-tests-eris.md) |
| **8** | 2026-08-02 | [Dependency bump `@contentauth/c2pa-node` 0.7.0 → 0.8.0](notes/step-08-c2pa-node-0-8-0.md) |
| **9** | 2026-08-05 | [Dependency bump `@contentauth/c2pa-node` 0.8.0 → 0.8.1](notes/step-09-c2pa-node-0-8-1.md) |
| **10** | 2026-08-05 | [Reproducible service builds: `npm install` → `npm ci`](notes/step-10-reproducible-service-builds-npm-ci.md) |
| **11** | 2026-08-05 | [c2pa-node trust settings: what actually works](notes/step-11-c2pa-node-trust-settings.md) |
| **12** | 2026-08-05 | [SPEC-014 implemented: trust verification in `/v1/read`](notes/step-12-spec-014-trust-verification-in-read.md) |
| **13** | 2026-08-05 | [SPEC-013 implemented: `isTrusted()` fails closed](notes/step-13-spec-013-istrusted-fails-closed.md) |
| **14** | 2026-08-05 | [SPEC-011 + SPEC-012 implemented together](notes/step-14-spec-011-and-spec-012.md) |
| **15** | 2026-08-05 | [SPEC-015 implemented: rate limiting and concurrency bounds](notes/step-15-spec-015-rate-limiting-and-concurrency.md) |
| **16** | 2026-08-05 | [Open items](notes/step-16-open-items.md) |
| **17** | 2026-08-05 | [The integration suite now runs in CI](notes/step-17-integration-suite-in-ci.md) |
| **18** | 2026-08-05 | [Branch protection on `main`](notes/step-18-branch-protection-on-main.md) |
| **19** | 2026-08-06 | [Verified against the OFFICIAL C2PA trust list](notes/step-19-official-c2pa-trust-list.md) |
| **20** | 2026-08-06 | [SPEC-017: a body-size default matched to what we sign](notes/step-20-spec-017-body-size-default.md) |
| **21** | 2026-08-06 | [SPEC-018: rotation you can confirm, and scanning that runs itself](notes/step-21-spec-018-rotation-and-scanning.md) |
| **22** | 2026-08-06 | [Dependabot's first two PRs, and v0.5.3](notes/step-22-dependabot-first-prs-and-v0-5-3.md) |
| **23** | 2026-08-06 | [SPEC-019: reading without the service, and what the extension really does](notes/step-23-spec-019-ext-c2pa-reader.md) |
| **24** | 2026-08-06 | [Correcting Step 23: what ExtC2paReader actually unlocks](notes/step-24-correcting-step-23-what-it-unlocks.md) |
| **25** | 2026-08-07 | [SPEC-021: seven more media types, and a third allow-list](notes/step-25-spec-021-seven-more-media-types.md) |
| **26** | 2026-08-07 | [SPEC-022: the name SPEC-021 broke](notes/step-26-spec-022-builder-entry-point-name.md) |
| **27** | 2026-08-07 | [Measuring the remaining formats, and what PDF really costs](notes/step-27-measuring-remaining-formats-and-pdf.md) |
| **28** | 2026-08-07 | [SPEC-023 implemented: thirteen media types](notes/step-28-spec-023-thirteen-media-types.md) |
| **29** | 2026-08-07 | [A review of the whole codebase, and the two defects it found](notes/step-29-codebase-review-two-defects.md) |
| **30** | 2026-08-07 | [SPEC-024 implemented: the read path is bounded](notes/step-30-spec-024-bounding-the-read-path.md) |
| **31** | 2026-08-07 | [SPEC-025: the client keeps its own bounds](notes/step-31-spec-025-client-side-bounds.md) |
| **32** | 2026-08-07 | [The digitalSourceType research, and what it changed](notes/step-32-digitalsourcetype-research.md) |
| **33** | 2026-08-07 | [SPEC-026 implemented: three claims instead of one](notes/step-33-spec-026-three-claims.md) |
| **34** | 2026-08-07 | [The anchors SPEC-027 broke, and the test that let it](notes/step-34-spec-027-the-anchors-it-broke.md) |
| **35** | 2026-08-07 | [SPEC-028 drafted, and the two questions it could not be written without](notes/step-35-spec-028-drafted-two-questions.md) |
| **36** | 2026-08-07 | [ADR-0004, and the same link check failing the same way twice](notes/step-36-adr-0004-and-the-link-check-again.md) |
| **37** | 2026-08-07 | [SPEC-028 implemented: the second half of Article 50(2)](notes/step-37-spec-028-implemented-article-50-2.md) |
| **38** | 2026-08-07 | [Reviewing 0.9.0 an hour after shipping it](notes/step-38-reviewing-0-9-0-after-shipping.md) |
| **39** | 2026-08-07 | [Correcting Step 38: there is no second flake](notes/step-39-correcting-step-38-no-second-flake.md) |
| **40** | 2026-08-08 | [Reviewing the package as an outsider, and the guard that stops at the envelope](notes/step-40-outsider-review-envelope-guard.md) |
| **41** | 2026-08-08 | [Measuring SPEC-029's blocking question, which reversed its own sketch](notes/step-41-measuring-spec-029-blocking-question.md) |
| **42** | 2026-08-08 | [What the container sees as the peer, and why SPEC-030 keeps no addresses](notes/step-42-spec-030-peer-identity.md) |
| **43** | 2026-08-08 | [SPEC-029 and SPEC-030 implemented, and three tests that tested nothing](notes/step-43-spec-029-and-spec-030-implemented.md) |
| **44** | 2026-08-08 | [Node 24 and a contained container, and the two tests that depended on a writable one](notes/step-44-node-24-and-container-hardening.md) |
| **45** | 2026-08-08 | [SPEC-031: the gap a scope authorised and a criterion missed](notes/step-45-spec-031-scope-versus-criterion.md) |
| **46** | 2026-08-08 | [SPEC-032: the last four review findings, and a test double that fought back](notes/step-46-spec-032-client-layer-corrections.md) |
| **47** | 2026-08-08 | [The equivalence test compared configurations, not engines](notes/step-47-equivalence-test-configurations.md) |

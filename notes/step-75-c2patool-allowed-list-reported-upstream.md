# Step 75 — c2patool's own `--allowed_list` confers no trust, reported upstream (2026-10-22)

That c2pa-rs 0.91 drops a flat `trust.allowed_list` was already on record:
Step 67's addendum measured it through c2pa-node 0.9.8 (c2pa-rs 0.91.0), and
`docs/production.md` has said since SPEC-042 (2026-10-07) that c2patool 0.28
drops a loose one "without a word". Nobody had reported it upstream. This step
re-measures it on the current engine, finds that c2patool's own documented
option is affected, and records the report: **contentauth/c2patool#363**.

## Measured

The asset is `out/signed.png`, signed with the c2patool sample ES256 test
certificate. "untrusted" means `Valid` with `signingCredential.untrusted`.

**c2pa-node 0.9.10 (c2pa-rs 0.91.3), against 0.9.5 (0.90.22).** A standalone
script in a fresh `node:24-slim` container, settings passed to
`Reader.fromAsset()`:

| settings | 0.9.5 | 0.9.10 |
|---|---|---|
| none | untrusted | untrusted |
| flat `trust_anchors` + `trust_config` | Trusted | Trusted |
| flat `allowed_list`, with or without `trust_config` | Trusted | **untrusted** |
| `Context`, `allowedList`, with or without `trustConfig` | – | **untrusted** |
| `Context`, `anchors[]` (even with covering `trustAnchors`) | – | **untrusted** |
| raw `anchors[]` | untrusted | **error**: TOML parse error |

The `Context` row with covering anchors is the control: `c2pa-utilities`
rebuilds `trust` from the flat camelCase keys and discards a caller's
`anchors[]`. So through c2pa-node 0.9.10 there is no settings document that
expresses an allowed list without anchors beside it. That is what SPEC-014
Amendment 1 already assumes; it refuses such a document.

**c2patool 0.28.2 (c2pa-rs 0.91.2), against 0.27.22 (0.90.22).** The trust
code (`settings/mod.rs`, `store.rs`, `certificate_trust_policy.rs`) is
byte-identical in the 0.91.2 and 0.91.3 crates, so this is the same engine
behaviour.

| test | 0.27.22 | 0.28.2 |
|---|---|---|
| c2patool's documented example: `sample/C.jpg trust --allowed_list sample/allowed_list.pem --trust_config sample/store.cfg` | Trusted | **untrusted** |
| the same with `--trust_anchors sample/trust_anchors.pem` | Trusted | Trusted |
| `trust --allowed_list` with the signing certificate alone (no CA certificate in the file) | Trusted | **untrusted** |
| `trust --allowed_list` with an unrelated end-entity certificate | untrusted | untrusted |
| `--settings` with `anchors: [{ trust_kind: "manifest", trust_anchors: "", allowed_list }]` | untrusted | **Trusted** |

Exit code 0 and empty stderr in every row. The unrelated-certificate row shows
the allowed list is what decides the verdict on 0.27.22. The leaf-only row
rules out the CA certificate in the sample file, which `docs/usage.md` says an
allowed list must not contain. The last row also holds on `sample/C.jpg`. In
that form both `trust_kind` and `trust_anchors` must be present; leaving either
out gives `missing field`.

## Read, not run

- c2pa-rs 0.91 has no `allowed_list` field on `Trust`; it lives on each
  `TrustAnchor`, and `store.rs` reads it only from there. `Trust` does not
  reject unknown fields, so the flat key is dropped on every path, including
  the `from_string` path that still migrates a flat `trust_anchors`. That is
  why this is not c2pa-rs #2742, which is about `trust_anchors` through
  `with_value`/`set_value`.
- c2patool `v0.28.2` writes `--allowed_list` as a flat `[trust] allowed_list`
  (`main.rs`, line 811). On `main` it still does, while `--trust_anchors` has
  been moved to build a `TrustAnchor`.
- c2pa-rs `main` removed the deprecated trust fields on 2026-10-15 (#2751) and
  still does not reject unknown keys under `trust`.
- The `init trust --legacy` sidecar (`c2pa-trust-allowed.sha256.txt`, which
  holds the interim list's allowed hashes) is loaded through the same flat key,
  so that list is probably dropped too. Not tested: `init trust` is never run
  in this project.

## Not a security problem

An allowed list only adds trust. Losing it makes a verdict stricter, never
looser, and the unrelated-certificate row found no case where 0.28.2 trusts
more than 0.27.22. The cost is a silent false `untrusted` for exactly the
signers an allowed list exists for, such as the IPTC publishers in
`docs/production.md`. The report was filed as an ordinary public issue.

## The report

Searched first: issues and pull requests in c2pa-rs, c2patool, c2pa-js and
c2pa-python for `allowed_list`, `allowed list`, `allowedList`, `allowed-list`
and `end-entity`. Nothing covered it. #363 contains only the documented
example, the two versions and the `--trust_anchors` control. The cause and the
workaround above are not in it, on purpose: the maintainers own that code, and
they can be offered if asked.

## Same day, two other comments

- **contentauth/c2pa-js#244** (Step 74's timestamp report) was confirmed by
  another user across 0.9.7 to 0.9.10. A reply added the mitigation SPEC-007
  Amendment 1 implements: read the signed asset back and refuse it unless
  `signature_info.time` is set and `timeStamp.validated` is present.
- **contentauth/verify-site#393** reports ingredients shown as "Conformant"
  although the active manifest recorded `signingCredential.untrusted` for them.
  Measured on that file with c2patool 0.27.22: the manifest is remote only
  (Behance removed the embedded one; XMP `dcterms:provenance` points to
  `cai-manifests.adobe.com`). The Photoshop signer reads untrusted on the
  official list alone and `Trusted` with Adobe's interim anchors added. In
  every configuration, the reader's own `ingredientDeltas` for both ingredients
  carry no trust code at all, only `ingredient.manifest.validated` and
  `ingredient.claimSignature.validated`. This is SPEC-045's rule from the
  other side: a delta without `signingCredential.trusted` is not trust. The
  reply suggests treating such a delta as "not verified". How verify-site
  builds its badge was not examined.

## Still open

- c2patool#363, and whether a fix lands in c2pa-rs (rejecting unknown trust
  keys) or only in c2patool.
- `docs/production.md` describes the flat `allowed_list` as working for
  c2patool 0.27.22, and as dropped by 0.28. Both are still correct.
  `certs/c2pa-trust.settings.json` uses only `trust_anchors` and
  `trust_config`, which c2patool 0.28.2 still reads `Trusted` (measured: a
  `--settings` document of that shape, and `--trust_anchors` above). On c2pa-rs `main`, after #2751, the string path
  (`from_string` / `update_from_str`) still migrates a flat `trust_anchors`
  into `anchors[]`, so `bin/verify.sh` should survive the next c2patool too.
  Read, not run.

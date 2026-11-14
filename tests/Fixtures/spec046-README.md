# SPEC-046 fixtures

Copied on 2026-10-29 from `provemark/c2pa-verifier` v0.10.0,
`tests/Fixtures/revocation/` (MIT, the same maintainer). The verifier's
`bin/make-revocation-variants.php` built them on 2026-10-09: a throw-away
P-256 hierarchy (root ← intermediate ← signer P), keys in a scratch
directory and deleted. No key is in either repository.

| file | source | SHA-256 |
|---|---|---|
| `spec046-cs-parent.png` | `cs-parent.png`: `fixture-unsigned.png` signed by `c2patool` 0.28.1 with P | `073a1f6a2e249edfdfc51d1e76d8e9a1f722ac721dccea904079e688e31311d0` |
| `spec046-throw-away-root.pem` | `throw-away-root.pem`, the root | `4fbedc93c0f75b492e0a2f9fcbc9e520c285bce884b9439feda50c82479887ac` |
| `spec046-throw-away-root.settings.json` | `throw-away-root.settings.json`: the root and `store.cfg` as a settings document | `8156eb8daaad40b96aab70141bfce22ac6ab805ba20ae00fbfab2fe77698e8a0` |
| `spec046-p-good.der` | P's `good` OCSP response, signed by the intermediate, as DER; taken from the evidence of `cs-good.png` | `427b01dc14f807706b0afb7d1747f107f9b332f2901a2ee780c3896a1aa30fd2` |

Measured with verifier 0.10.0: `cs-parent.png` against the root is
`Trusted`, as a settings document and as `TrustSettings::fromTrustLists()`.
With a revocation source the verifier asks it about the intermediate and
then about P; answered with `spec046-p-good.der` for P, the evidence lists
it as `fetched`, `chosen`, `good`, and the status
`signingCredential.ocsp.notRevoked` is added. Its `nextUpdate` is
2036-10-06, so the tests do not age before then.

## Amendment 1

Copied on 2026-11-14 from `provemark/c2pa-verifier` v0.11.2,
`tests/Fixtures/jumbf/child-overruns.png` (MIT, the same maintainer; first
committed there in its step 10). A PNG whose `c2pa.hash.data` superbox
has its LBox raised from 195 to 205, so it runs past its parent and the
verifier refuses the store. Verifier
0.11.2 gives an `Invalid` report with `general.error`, and its crJSON holds
`manifests: []` and a root `validationResults` with that failure.

| file | source | SHA-256 |
|---|---|---|
| `spec046-child-overruns.png` | `jumbf/child-overruns.png` | `71a4e0dbf6df2ffb383629c9af1ed02975089414c9f5f35941f50ead89283bba` |

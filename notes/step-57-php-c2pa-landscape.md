# Step 57 — The whole PHP C2PA landscape, and who this package is for (2026-09-03)

Written because the maintainer asked whether this package is still relevant.
Answering that from a download count alone is how a wrong answer gets made, so
this measures the field around it instead.

## Everything in PHP that touches C2PA

Packagist search for `c2pa`, plus drupal.org, on 2026-09-03:

| package | what it is | downloads | monthly | dependents |
|---|---|---|---|---|
| `jrglasgow/c2patool` | PHP wrapper shelling out to the `c2patool` binary | 1814 | 40 | 0 |
| `ericmann/ext-c2pa` | the native PHP extension (Automattic) | 192 | — | — |
| **`provemark/content-credentials`** | **this package** | **44** | **36** | **0** |
| `ex-akt/contao-ki-kennzeichnung` | Contao bundle, Art. 50 marking of AI images | 6 | 6 | 0 |
| `removeailabel/c2pa-xmp-probe`, `localtools/ai-meta-php`, `snapwonders/sdk` | fragments, ≤2 downloads each | ~0 | | |
| drupal.org `c2pa` module | blocks AI-generated uploads by reading credentials | **0 releases** | | |

Three readings that matter more than the totals:

- **The 1814 is a stock, ours is a flow.** `jrglasgow/c2patool` has 25 versions
  accumulated over a long period and does **40 downloads a month**. This package
  does **36**, from 44 total. On current traffic the two are level; the gap is
  age, not adoption.
- **Nobody in PHP has shipped a complete one.** The wrapper shells out to a
  binary and does not build manifests. The Contao bundle requires only
  `php: ^8.3` and `contao/core-bundle` — **no C2PA library at all** — so its
  "Kennzeichnung" is a database flag and a frontend badge, and its detection of
  existing provenance is hand-rolled. The Drupal module is a project page with a
  description and no code. This package is the only PHP thing that builds, signs,
  reads and verifies.
- **The demand that is visible is on the READ side.** The Drupal module exists to
  *block* AI uploads by reading credentials. The Contao bundle detects existing
  markers on upload. Neither signs anything. Signing is what this package is
  architecturally proudest of, and it is not what the CMS world is asking for
  first.

## Who this package is actually for

Ordered by how well the evidence supports it, not by how appealing it is:

1. **An application that must SIGN and can run a container.** Laravel or Symfony
   product teams putting a generative feature into a PHP backend, on their own
   infrastructure. This is the original design point and the only audience the
   architecture fits without compromise.
2. **Integrators building Article 50 compliance for someone else** — the
   Guadaltel shape from Step 56: a services company whose deliverable runs on
   managed hosting where a sidecar container is normal.
3. **Read-side CMS integrations** (Drupal, Contao, TYPO3, Pimcore) that want to
   verify incoming assets. Real, growing, and currently served by a binary
   wrapper — because our reading half asks for a service or a native extension,
   and theirs asks for a file on disk.
4. **Not for**: the WordPress shared-hosting mass. Architecturally out of reach,
   for reasons Step 24 already recorded and the CAI wp-plugin already occupies.

## The uncomfortable part

The audience is not shrinking — Article 50(2) has applied since 2 August 2026 and
systems already on the market must comply by 2 December 2026. What is narrow is
the **intersection with our delivery shape**. Every route to a larger audience
runs through the same question, which is not about C2PA at all:

> Can a user who cannot run Docker and cannot install a PHP extension use this?

Today: no. The three answers to that, none of which has a spec:

- **A `c2patool` binary adapter behind `ReaderInterface`** — read-only, no
  service, no network hop. Cheap relative to the alternatives, and it is exactly
  what the one package with more downloads than us does. It would put our API in
  reach of the read-side CMS audience above.
- **A pure-PHP reader** (JUMBF, CBOR, COSE, hash binding via openssl/sodium).
  Large, unbuilt, and the only thing that reaches shared hosting. Step 24
  already named it and nobody has asked for it.
- **Integration packages** per CMS (ADR-0005), which do not change reach at all
  unless one of the two above lands first.

Recorded as measurement, not as a plan. None of these is approved, and the
package needs nothing today; see Step 55 for its current dependency state.

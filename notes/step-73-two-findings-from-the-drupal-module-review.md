# Step 73 — Two findings from the Drupal module's review (2026-10-11)

An independent review of `drupal/content_credentials` (its NOTES Step 35,
findings L3 and L4) found two things that belong here, not in the module.
Neither blocks anything: the module's 1.0.0-alpha1 (19 October) ships on
`^0.19.1` as it is. Nothing here is posted as a GitHub issue; this note is the
record. Each needs a draft spec before code.

## 1. A cut-off ingredient tree looks complete (L3) — reasoned

`ManifestStoreParser` stops collecting at `MAX_INGREDIENTS = 64` and at
`MAX_INGREDIENT_DEPTH = 8` (SPEC-045 AC5), both private. What lies beyond is
absent, and a caller cannot tell a complete tree from a cut-off one.

That matters to a caller that reasons about absence. The module (its SPEC-019
Amendment 1) takes an origin from a `parentOf` ingredient only when there is
exactly one parent per level; a second parent hidden behind the budget would
let that check pass. So it refuses any tree of 64 ingredients, which copies the
private constant:

```php
self::ingredientCount($report->ingredients()) < 64
```

If the budget here were lowered, a cut-off tree would pass there as complete
with nothing turning red. `^0.19.1` holds the value today.

**Proposal:** the report says it — e.g. `ManifestReport::ingredientsTruncated(): bool`,
true when the walk stopped on the budget or the depth limit rather than at the
end of the tree. Not a public constant: a flag also covers depth, and survives a
change in how the budget is spent. Then the module asks instead of counting
(a module spec of its own, after its alpha).

## 2. Quadratic de-duplication (L4) — measured

`ManifestReport::softwareAgents()` (`in_array($key, $seen, true)`) and
`digitalSourceTypes()` (`in_array($digitalSourceType, $types, true)`) check
each entry against the list built so far. The entries come from the manifest,
so the uploader controls how many there are.

Measured 2026-10-11 on 0.19.1 (PHP 8.5, from the module's `vendor/`, one call
each, all entries distinct, built directly as a `ManifestReport`):

| actions | `softwareAgents()` | `digitalSourceTypes()` |
|---|---|---|
| 5,000 | 0.03 s | 0.03 s |
| 10,000 | 0.13 s | 0.13 s |
| 20,000 | 0.45 s | 0.44 s |
| 40,000 | 1.89 s | 1.91 s |

Per route, how far it can be pushed:

- **Verifier:** bounded by c2pa-verifier's CBOR item cap. In the review, a
  signed file with 60,000 actions was refused in 0.65 s (the reviewer's
  measurement, not repeated).
- **Service** (responses up to 32 MiB) **and extension** (no comparable cap
  known): a few hundred thousand actions is plausible, which by the same curve
  is minutes per call. **Reasoned, not measured.** To measure: sign a file with
  c2patool carrying ~200k distinct actions and read it through each route.

The module calls both methods before it knows the manifest is trusted
(`CredentialInspector`); moving that after the trust check is its own change,
after its alpha.

**Proposal:** keyed "seen" sets —

```php
if (isset($seen[$key])) { continue; }
$seen[$key] = true;
```

Same output, same order. A test can pin the result on a few thousand
duplicates; a timing assertion is optional and flaky, so prefer an operation
count if one is wanted.

## Benchmark, to repeat

```bash
php -d memory_limit=1G -r '
require "vendor/autoload.php";
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Core\Reading\ValidationState;
foreach ([5000, 10000, 20000, 40000] as $n) {
  $acts = []; for ($i=0;$i<$n;$i++) $acts[] = ["action"=>"c2pa.edited","softwareAgent"=>["name"=>"t$i","version"=>"1"],"digitalSourceType"=>"http://x/$i"];
  $r = new ManifestReport("urn:x", null, [["label"=>"c2pa.actions","data"=>["actions"=>$acts]]], [], ValidationState::Trusted);
  $t=hrtime(true); $r->softwareAgents(); $a=(hrtime(true)-$t)/1e9;
  $t=hrtime(true); $r->digitalSourceTypes(); $d=(hrtime(true)-$t)/1e9;
  printf("n=%6d  agents %.2fs  types %.2fs\n",$n,$a,$d);
}'
```

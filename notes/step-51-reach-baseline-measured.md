# Step 51 — The reach baseline, captured before it expires (2026-08-14)

A discussion about how to grow adoption ran on two unmeasured premises: that
search delivers nothing yet, and that so few people arrive that there is no
funnel to speak of. Both are testable, and one of the two sources expires:
**GitHub's traffic API keeps 14 days and nothing more.** So this step is not an
argument, it is the *before* — numbers written down so that a later measurement
has something to be compared against.

Everything below was read on 2026-08-14 with:

```
gh api repos/provemark/content-credentials/traffic/views
gh api repos/provemark/content-credentials/traffic/popular/referrers
gh api repos/provemark/content-credentials/traffic/popular/paths
gh api repos/provemark/content-credentials --jq \
  '{stars:.stargazers_count,forks:.forks_count,watchers:.subscribers_count}'
curl -s https://packagist.org/packages/provemark/content-credentials.json \
  | python3 -c "import json,sys;print(json.load(sys.stdin)['package']['downloads'])"
```

The traffic endpoints need push access on the repo; they are not public.

### GitHub traffic, 2026-07-31 … 2026-08-13

| | count | uniques |
|---|---|---|
| all views | 619 | **20** |
| `/` (overview) | 190 | 20 |
| `/actions` | 150 | 1 |
| `/pulls` | 76 | 1 |
| `/blob/main/CHANGELOG.md` | 7 | 1 |
| `/blob/main/NOTES.md` | 4 | 1 |

Referrers over the same window:

| referrer | count | uniques |
|---|---|---|
| github.com | 275 | 1 |
| packagist.org | 22 | 1 |
| t.co | 21 | **4** |
| Google | 10 | **6** |
| provemark.github.io | 3 | 1 |
| laravel-news.com | 2 | **2** |
| teams.public.onecdn.static.microsoft | 1 | 1 |

Repository social state: **1 star, 0 forks, 0 watchers.**

### Packagist, read the same day

`{'total': 36, 'monthly': 36, 'daily': 1}`, 2 favers, 22 versions. `total ==
monthly` means every download this package has ever had falls inside the last 30
days — consistent with the 29 measured on 2026-08-11, and with part of it being
our own CI.

### Reading it without over-reading it

⚠️ **619 is not reach.** The single-unique rows are the maintainer: `/actions`
and `/pulls` together account for 226 views from one visitor, and the daily
series spikes to 129/158/101/72 on 08-05..08-08 at two or three uniques a day —
that is CI-watching during the security-review work, not an audience. The number
that means something is **20 unique visitors to the overview page in two weeks**,
and that still includes crawlers.

Two things follow, and they cut against the premises that prompted the check:

- **Search is not an empty channel.** Google is the largest external source by
  unique visitors — 6 in fourteen days, ahead of t.co's 4 — and it is the only
  one that has had zero effort spent on it. Small, but live. What remains
  untested is the claim *about* the result pages ("a developer searching for this
  finds policy prose, not code"); nobody has run those searches.
- **"Nobody arrives" is too strong; "nobody converts" fits better.** Roughly 20
  uniques per two weeks against 36 downloads total, 1 star and 0 watchers. There
  is a trickle and it does not turn into anything. Whether that is a trust
  problem, a quickstart problem, or simply people who were only ever curious,
  20 visitors cannot say.

**n is small enough that this baseline's job is to be compared, not to be
concluded from.** Two weeks, twenty uniques, bots included.

### What to re-run against it

Re-read the same six commands after any deliberate reach investment — an
article, an ecosystem listing, a named production user — and compare against
the table above rather than against a remembered number. Useful pairs:

- unique overview visitors, and how many arrive from Google
- Packagist `total` versus the visitor count over the same period, which is the
  conversion question
- stars/watchers, the cheapest available proxy for whether an arrival trusted
  what it found

Note the asymmetry: Packagist keeps its history, GitHub does not. Any window not
captured here is gone, so a future comparison needs its own snapshot taken at
the time, not reconstructed afterwards.

No code, no spec, no changelog entry.

---

[← Step 50](step-50-external-projects-and-a-stale-count.md) · [index](../NOTES.md)
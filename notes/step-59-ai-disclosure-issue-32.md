# Step 59 — The ai_disclosure maintainer answered, and what he did not settle (2026-09-08)

A design question settled from outside this repository. Written down because the
answer moves a parked spec closer to its trigger, and because the thing it did
NOT settle is the thing that decides the build order.

Thread: `project/ai_disclosure` issue **32** on git.drupalcode.org, opened
2026-09-03. Proposal: an `ai_disclosure_c2pa` submodule that reads C2PA content
credentials off an uploaded file and calls the module's `suggest()` with the
resulting grade.

## What the maintainer said

**Giorgio Alfredo Pagano (`@sjpagan`), 2026-09-07 14:13 UTC.** Verbatim, the
load-bearing sentences:

> "Separate contrib module, for the reason you gave: the library would land in
> this project's root composer.json for every site. Nothing here blocks you, and
> suggest() as the default is right, since the recorder never downgrades a
> disclosure that is already there."

> "All five grades carry label_required: true, so picking the wrong one does not
> change whether Article 50(4) applies. It changes the icon and the sentence the
> reader gets."

> "The schema does carry a tie-breaker, severity, and it confirms your objection
> instead of solving it: lowest severity puts compositeWithTrainedAlgorithmicMedia
> on ai_summarized at 40, under ai_partly_assisted at 50 and ai_assisted_hitl at 60."

> "On our side the field reads as an identifier and is not one. I will correct
> that in the grade documentation after the alpha. The map belongs in your module.
> If it needs a hook or an alter, ask."

He also added `category::feature` and `priority::minor`. Status stayed *To do*,
no assignee.

## Three things that settles

- **Separate contrib module, and he reached that on his own argument** — a
  dependency in his root `composer.json` would land on every site running his
  module, not only on those enabling the submodule. That is ADR-0005's reasoning
  arrived at from the other side, which is worth more than our own statement of it.
- **The URI-to-grade collision is real and he confirmed it.** One
  `digitalSourceType` maps to several grades, and `severity` does not break the
  tie — it makes the wrong pick worse. But the consequence is bounded: every
  grade carries `label_required: true`, so a wrong pick never changes whether
  Article 50(4) applies. It changes the icon and the sentence. That bound is what
  makes shipping a default map defensible at all.
- **The map is ours.** He changes only his own documentation.

## What it does NOT settle, and this is the part that matters

**Which reading route the module uses.** That was never his question to answer,
and the issue did not ask it. Asked on 2026-09-08 05:15 UTC:

> "So: what do ai_disclosure sites actually run on? If most of them are on
> ordinary shared hosting, I should build that route first and the module after
> it. If they are mostly on managed platforms or self-hosted, I can start with
> what already exists."

The three routes and what each demands of a host:

| route | what the host must permit |
|---|---|
| signing/reading service | running and keeping a second process alive |
| `ExtC2paReader` (ext-c2pa) | installing a PHP extension and enabling it in `php.ini` |
| c2patool binary (SPEC-038, **unbuilt**) | placing a file **and** `proc_open`/`exec` |

**This is the closest SPEC-038's trigger has come to firing**, and it has not
fired. Its written advice is still "do NOT implement it yet", because it pays off
only through a CMS integration that reaches hosts the other two routes cannot.
Whether this is such an integration depends on the hosting answer, which is
exactly what is still open. A concrete module to build is not by itself the
measurement the trigger asks for.

**And SPEC-038 is not the shared-hosting answer either**, which its own
*What this does NOT solve* section says. A host forbidding `proc_open` remains
out of reach, and cheap shared hosting often does. It lowers the bar from
*"run and monitor a second process"* to *"place a file and be allowed to execute
it"* — a real reduction and a partial one. Two drafts of the outreach message
overstated this before the spec was re-read; the spec had the correction already
written down.

## One thing to carry into the module

`compositeWithTrainedAlgorithmicMedia`, the term his whole middle paragraph turns
on, is one this package **declares but refuses to emit** (SPEC-026, primer §10):
C2PA records it as `c2pa.opened` + ingredient + `c2pa.edited`. Reading it is
fine — `involvesGenerativeAi()` answers, and a submodule only reads — but the
asymmetry is easy to lose in a conversation about mapping.

## Next

Waiting on the hosting answer; a read-only check of the thread is scheduled for
2026-09-09. If it comes back "ordinary shared hosting", SPEC-038 is the first
thing to build and the module follows it. If it comes back managed or
self-hosted, the two routes that exist are enough to start.

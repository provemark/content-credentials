# Step 44 — Node 24 and a contained container, and the two tests that depended on a writable one (2026-08-08)

Two findings from Step 40's list, taken together because they touch the same
file. No spec: `service/` only, no `src/` change, verified by hand — the same
footing as Steps 8, 9 and 10.

### The runtime, and the gap `npm audit` cannot see

`node:20-slim` left maintenance in **April 2026**. The image holding the signing
key had been running unpatched for four months, and nothing in the project could
have told us: `npm audit` and Dependabot audit *packages*, and the interpreter
underneath them is not one. SPEC-018 automated dependency scanning and this sat
outside its field of view the whole time.

Moved to `node:24-slim` — the current LTS line, so the decision has the longest
runway before it repeats. `engines` raised to `>=22.0.0`. Verified: node 24.19.0,
c2pa-node 0.8.1 and express 5.2.1 unchanged, and the **async TSA path still
works** (`hasTimestamp: true`, signed PNG 55,478 bytes — byte-identical in size
to every run since Step 6). That path is the standing regression risk on any bump
touching the runtime.

### Containment

The container ran as **root**. Now: unprivileged `node` user, `cap_drop: ALL`,
`no-new-privileges`, `read_only: true` with a `tmpfs` at `/tmp` — which the
signing path genuinely needs, because `builder.sign()` writes the asset to a file
and returns the manifest store (NOTES Step 2). Plus `mem_limit: 1g`, sized from
the measured ~650 MiB saturation figure rather than from habit, and
`pids_limit: 256`. A `HEALTHCHECK` using node's own `fetch`, since the image has
no curl and no wget (Step 21).

Confirmed as applied rather than as written, which is not the same thing:

```
ReadonlyRootfs: true   CapDrop: [ALL]   Memory: 1073741824
PidsLimit: 256   SecurityOpt: [no-new-privileges:true]   User: node
touch /app/nope -> Read-only file system
```

### ⚠️ `docker cp` refuses against a read-only rootfs, whatever the destination

Two integration tests went red: SPEC-012 AC9 (the `/dev/full` audit probe) and
SPEC-018 AC2 (a second service on a second certificate). Both `docker cp` a
fixture into `/tmp`.

The first guess was that the tmpfs was the problem. It was not:

```
docker cp file c2pa-spike-service-1:/tmp/file
  -> Error response from daemon: container rootfs is marked read-only
docker exec -i c2pa-spike-service-1 sh -c 'cat > /tmp/file' < file
  -> works
```

Docker refuses the copy on the **container**, not on the path — so the tmpfs
destination is irrelevant. Both probes now pipe through `docker exec -i`.

Worth keeping for two reasons. It is the ordinary way to get a file into a
hardened container, and it will be needed again the next time a probe wants one.
And it is a small instance of a general shape: hardening did not break the
service, it broke two **test harnesses** that had quietly depended on the
container being writable. That dependency was invisible while it held.

### Verified

`composer check` green (296 passed). Integration 136 passed / 16 skipped.
`bin/e2e.php` sign+read OK with the Art.50 mark and `hasTimestamp` true;
`bin/verify.sh` signature valid / cert trusted / Art.50 mark all PASS.
`php bin/spec-check.php` 0 errors.

### Still open from Step 40

Eight of the twelve findings. In rough order of weight: the `extractError`
asymmetry (the cap went into one of two identical methods), `SignAssetJob`
retrying deterministic failures with backoff, two Guzzle clients where one would
do, `ExtC2paReader` never confirming its trust anchors took, and the fifth stale
enumeration in `SignCommand`'s help text.

---

[← Step 43](step-43-spec-029-and-spec-030-implemented.md) · [index](../NOTES.md) · [Step 45 →](step-45-spec-031-scope-versus-criterion.md)

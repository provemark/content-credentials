# Step 42 — What the container sees as the peer, and why SPEC-030 keeps no addresses (2026-08-08)

SPEC-030's blocking question was what identifies an unauthenticated caller, since
there is no token to key a budget on. The draft leaned per-address on
`req.socket.remoteAddress`, with the proxy caveat "documented rather than
solved". Measured first, and the measurement removed the reason for the
complexity entirely.

A probe container on the compose network, reporting `req.socket.remoteAddress`:

| Deployment | Peer as the container sees it |
|---|---|
| host → published port, `127.0.0.1:3000:3000` (docker-compose default) | **`172.19.0.1` for every request** — the bridge gateway |
| container → container, `http://signer:3000` (README) | the calling container's own address, distinct |

**In the deployment this project ships and recommends, per-address keying
discriminates nothing.** Every host-side caller collapses into the gateway
address, so the "per-address" bucket is a global bucket wearing a costume. Only
the container-network deployment would ever see distinct peers — and that is the
deployment with the smallest set of possible callers.

### The reason that mattered more than the measurement

An address-keyed map has **attacker-controlled cardinality**. SPEC-015's own
comment records why its map is safe: "only authenticated requests reach here, so
the map is bounded by the number of valid tokens". That sentence is exactly what
an unauthenticated bucket cannot say. Adding an unbounded map inside the spec
written to close a resource exhaustion is a poor trade, and it is the kind of
thing that gets added because the alternative looked less thorough.

### ⚠️ The argument that pushed toward per-address did not survive reading it back

The draft worried that a global bucket lets one noisy source starve a legitimate
caller — SPEC-024 AC3's failure in a new place. It cannot: the budget is spent
only on authentication *failure*, and a valid token does not fail, so the two
budgets never meet. AC5 is therefore true **by construction**.

Which is why AC5 is kept rather than deleted as vacuous. A criterion true by
construction is one an implementation can quietly stop satisfying — spending the
budget on every *attempt* instead of every *failure* is a one-word change that
looks like a simplification and hands any unauthenticated caller a lever to stop
all signing. Same family as Step 26's rule: an assertion that nothing happened
needs a demonstration that something could have.

### And the honest consequence: the budget is not a load control

Worth writing down because someone will size it wrong otherwise. Once
authentication runs ahead of the parser, an unauthenticated request costs a
header parse, one SHA-256 and a 401 — about what `GET /health` costs, and
SPEC-024 AC6 already settled that `/health` is not worth bounding. **The
reordering is the fix.** The budget survives for a different purpose: repeated
authentication failure is a credential-guessing signal and nothing anywhere
reports it today. Hence a global counter on `/health` plus a bounded record,
rather than a per-source breakdown nobody can act on.

Accepted cost, recorded in AC4 so it is a decision rather than a surprise: during
a flood, a caller with a merely wrong token gets 429 where it would otherwise get
401. It holds no valid credential either way.

---

[← Step 41](step-41-measuring-spec-029-blocking-question.md) · [index](../NOTES.md) · [Step 43 →](step-43-spec-029-and-spec-030-implemented.md)

# ADR-0003: API keys with scopes instead of OAuth for the data plane

- **Status:** Accepted
- **Date:** 2026-11-26 (recorded retrospectively; decided during v1.0 and v1.1)

## Context

The API's consumers are machines — airline and ground-handling systems calling
on their own behalf, around the clock, with no human in the loop. They need
credentials that are easy to provision, easy to rotate, and safe to store
server-side. OAuth's delegation machinery — consent screens, third-party
clients, refresh tokens — goes unused in this pattern, and even its
machine-to-machine profile (client credentials) adds a token lifecycle that
every partner must implement.

## Decision

Two credential planes. The **management plane** (creating, listing, revoking
API keys) authenticates users with short-lived bearer tokens obtained via
login. The **data plane** authenticates partners with long-lived API keys sent
in an `X-Api-Key` header: stored as SHA-256 hashes, carrying optional
**scopes** (`read`, `write`, `sync`) enforced per route by middleware, a
per-key rate limit, and an expiry — keys issued through the management API
always expire after one year; the column is nullable only so keys issued
before expiry existed keep working. A key with no scopes is unrestricted, so
keys issued before scopes existed kept working too.

## Alternatives considered

- **OAuth 2.0 client credentials.** The closest honest fit for
  machine-to-machine, but it adds a token endpoint, token caching and refresh
  logic in every client, and expiry-related failure modes. Every partner pays
  that integration cost for no capability this API needs.
- **One credential plane (API keys for everything).** Key management guarded
  by another key invites bootstrapping problems and makes a leaked data-plane
  key a management-plane compromise. Separating the planes keeps the blast
  radius of each credential type small.
- **Unscoped keys only.** Simpler, but then a read-only dashboard integration
  holds a credential that can trigger syncs and write unavailabilities. Scopes
  let a partner hold exactly the capability they asked for.

## Consequences

- Key distribution is manual by design: a human provisions a key and hands it
  to a partner. That is the right shape at this scale and the wrong one at a
  hundred partners — self-service key management is the first thing to build
  when that day comes, and the management plane already gives it an API.
- Long-lived secrets demand server-side discipline: keys are hashed at rest,
  never logged, and the rate limiter is keyed by key *id*, not the plaintext
  (a bug class this project hit and fixed).
- The `scopes = null` grandfather clause means "no scopes" and "all scopes"
  are the same state, which makes scoped keys opt-in. A backfill migration
  plus required scopes at creation would retire the clause; that is deferred —
  every existing key is known and audited at this scale — and stands as this
  record's open item.

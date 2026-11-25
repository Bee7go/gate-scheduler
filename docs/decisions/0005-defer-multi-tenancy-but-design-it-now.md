# ADR-0005: Defer multi-tenancy, but design it now

- **Status:** Accepted
- **Date:** 2026-11-26 (recorded retrospectively; decided during v1.1)

## Context

The system serves one airport, configured by a single `AIRPORT_ICAO`
environment value, and every user, key, gate, and flight implicitly belongs to
that one deployment. Multi-tenancy — several organizations, each with their
own airports, keys, and data — is the obvious growth direction, and the kind
of change that is cheap to plan early and brutal to retrofit late. It is also
one to two weeks of invasive work that would destabilize a released, versioned
API to serve a customer who does not yet exist.

## Decision

Write the design down instead of building it: a design note
([DESIGN-multi-tenancy.md](../DESIGN-multi-tenancy.md)) fixes the tenancy
model (organizations own airports; data scopes through airports), the
enforcement mechanism (global Eloquent scopes driven by the authenticated
credential, never by client input), the disclosure rule (cross-tenant access
is a `404`, not a `403`), and a four-step migration path in which every step
is backward-compatible — the feature ships inside `v1` under the existing
versioning policy. Meanwhile, v1 decisions are checked against that note so
none of them forecloses it.

## Alternatives considered

- **Build multi-tenancy now.** Every feature — scopes, rate limits,
  statistics, sync scheduling — becomes per-organization, every test fixture
  needs a tenant, and the released API surface churns. All of that cost buys
  nothing until a second organization signs up.
- **Defer it without a design.** The default path, and the trap: single-tenant
  assumptions calcify silently (a global airport constant, keys without an
  owner concept, uniqueness constraints that should be per-tenant). By the
  time a customer forces the issue, the retrofit price has compounded.
- **Fake it with one deployment per customer.** Operationally honest at two or
  three tenants and a treadmill after that: every deploy, migration, and
  incident multiplies by the customer count. Kept open as a stopgap, rejected
  as the plan.

## Consequences

- The design note is a standing constraint on current work: schema and
  middleware changes get weighed against the migration path, which costs a
  little review attention on every change that touches ownership.
- A design that has never been built is a hypothesis. The note's migration
  path looks backward-compatible on paper; reality may disagree in the
  details, and the note will need updating the day the work starts.
- The record makes the deferral visible: a reader can see multi-tenancy was
  considered and deferred, not overlooked.

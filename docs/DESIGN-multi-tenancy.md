# Design note: multi-tenancy

**Status: not scheduled.** This document exists so that v1 decisions don't
paint the system into a corner, and so the work can start from a plan — not so
the work starts. Building multi-tenancy before a customer needs it would
destabilize a released, documented API to serve nobody.

## Goal

Several organizations use one deployment, each managing its own airport(s):
their own gates, unavailabilities, API keys, and (once they exist) webhook
subscriptions — with no visibility into each other's data.

## Model

- New `organizations` table. Every user belongs to one organization
  (`users.organization_id`); the registering user creates it.
- An organization owns one or more **airports** (`airports` table replaces the
  single `AIRPORT_ICAO` env; the scheduler iterates airports, not a constant).
- `gates`, `gate_unavailabilities`, and (via gates) `gate_allocations` gain an
  organization scope through the airport they belong to. `flights` are scoped
  by `airport_icao`, which becomes a foreign key.
- `api_keys` and `access_tokens` inherit the owner's organization. An API key
  can only read and write data inside its organization.

## Enforcement

- A global Eloquent scope per tenant-owned model, driven by the authenticated
  credential's organization — scoping lives in one place, not in every query.
- The API-key middleware resolves the organization once and puts it on the
  request; nothing downstream accepts an organization id from client input.
- Cross-organization access is a `404`, not a `403` — existence of another
  tenant's resources is not disclosed.

## Migration path

1. Create `organizations`; backfill a single default organization.
2. Attach existing users, keys, gates, and flights to it.
3. Enforce the global scopes (a no-op while one organization exists).
4. Open registration per organization.

Every step is backward-compatible for existing clients; the API surface gains
no new required parameters, so this ships inside `v1` under the
[versioning policy](API.md#versioning).

## What it costs

Every feature becomes per-organization: scopes, rate limits, webhook
subscriptions, statistics, sync scheduling. Test fixtures all need a tenant.
This is one to two weeks of invasive work — the reason it waits for a real
customer.

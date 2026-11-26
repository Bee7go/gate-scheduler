# Migration Rules

How schema changes ship without downtime. The [deployment guide](DEPLOYMENT.md)
runs `php artisan migrate --force` **before** the new code starts serving
traffic, so every migration must satisfy one rule above all:

> **A migration must be safe to run while the *previous* release is still
> handling requests.**

## Expand / contract

Schema changes that reshape existing data happen in two releases, never one:

1. **Expand** (release N): add the new column/table/index alongside the old
   one. New columns are nullable or have a default, so the running old code
   can keep inserting rows without knowing about them. Code in release N
   writes both shapes and prefers reading the new one.
2. **Backfill**: migrate existing rows — in the migration itself when the
   table is small, or in a chunked command for large tables.
3. **Contract** (release N+1 or later): drop the old column/table only after
   no deployed code reads or writes it.

Corollaries:

- **Never rename in place.** A rename is an add (expand) plus a drop
  (contract) with a backfill between.
- **Never drop and create in the same release that changes the code** — the
  old code runs against the new schema for a short window during deploy.
- Making a column `NOT NULL` is a contract step: it happens only after the
  backfill proved there are no nulls left.

## Both databases, always

CI runs every migration (and the full suite) on SQLite **and** PostgreSQL 17.
A migration that needs driver-specific behavior states it explicitly (as the
idempotency work did for savepoints) rather than relying on one driver's
leniency.

## Reversibility

Every migration has a working `down()`, or a comment stating why reversal is
impossible (e.g. a destructive contract step) and what the recovery path is
(restore from backup — see the deployment guide). Rollback in production is
`migrate:rollback` plus redeploying the previous image, in that order only if
the migration was a contract step; expand steps never need rolling back since
old code ignores them.

## Checklist for a new migration

- [ ] Runs green on SQLite and Postgres (CI covers both).
- [ ] Previous release's code still works mid-deploy (expand-only, additive).
- [ ] Nullable or defaulted new columns.
- [ ] `down()` works or is documented as irreversible.
- [ ] Large-table backfills are chunked, not one `UPDATE`.

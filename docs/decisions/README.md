# Architecture decision records

Each record captures one decision that shaped this system: the tension, the
option taken, the options rejected, and the costs knowingly accepted. Records
are immutable once accepted — a reversed decision gets a new record that
supersedes the old one, so the reasoning behind superseded decisions is
preserved.

The first six records were written retrospectively at v1.1.0, documenting
decisions made during v1.0 and v1.1 development. Records from here on are written when
the decision is made.

| # | Decision | Status |
|---|----------|--------|
| [0001](0001-serialize-flight-sync-with-a-cache-lock.md) | Serialize the flight sync with a cache lock in the service | Accepted |
| [0002](0002-sqlite-for-development-postgres-for-production.md) | SQLite for development and tests, PostgreSQL for production | Accepted |
| [0003](0003-api-keys-with-scopes-instead-of-oauth.md) | API keys with scopes instead of OAuth for the data plane | Accepted |
| [0004](0004-single-deployable-with-queue-not-a-sync-service.md) | One deployable with a queue, not a separate sync service | Accepted |
| [0005](0005-defer-multi-tenancy-but-design-it-now.md) | Defer multi-tenancy, but design it now | Accepted |
| [0006](0006-cap-statistics-windows-at-92-days.md) | Cap statistics windows at 92 days | Accepted |

## Format

- **Context** — the forces at play when the decision was needed.
- **Decision** — what was chosen, stated as fact.
- **Alternatives considered** — each with the reason it lost.
- **Consequences** — what the choice costs, including the parts that hurt.

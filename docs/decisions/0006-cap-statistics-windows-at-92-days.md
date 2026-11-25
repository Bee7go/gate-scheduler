# ADR-0006: Cap statistics windows at 92 days

- **Status:** Accepted
- **Date:** 2026-11-26 (recorded retrospectively; decided during v1.0)

## Context

The statistics endpoint aggregates gate utilization over a caller-chosen
`from`/`to` window, including a per-hour peak-occupancy scan that checks every
hourly slot against every allocation overlapping the window — so its cost
grows faster than linearly with the window's length. An unbounded window turns
one authenticated
GET request into an arbitrarily expensive query — a denial-of-service lever
handed to every key with `read` scope, and a latency outlier generator even
with honest callers.

## Decision

Validation rejects any window longer than 92 days — one full calendar quarter,
the longest range with an obvious business meaning (92 days covers the longest
quarters, Q3's July–September among them). The limit is a named constant
(`MAX_RANGE_DAYS`) with the rationale written on it, enforced in the request
class after the basic date rules pass, returning a structured validation error
that names the limit. The API documentation states both the cap and the reason,
and tells callers wanting a year to issue four quarterly requests.

## Alternatives considered

- **No cap, rely on rate limiting.** The per-key rate limiter bounds request
  *count*, not request *cost*; sixty cheap requests a minute is fine while one
  ten-year scan is not. Rate limiting and cost capping solve different
  problems.
- **Pagination or async report generation.** The honest answer for genuinely
  large ranges, but it changes the endpoint's contract from "a number now" to
  "a job you poll" for a need no client has expressed. If year-scale reporting
  becomes real, it arrives as a new endpoint rather than a silent change to
  this one.
- **A cap chosen by benchmarking (for example, whatever keeps p99 under
  100 ms).** More precise today, meaningless after the next schema or
  hardware change, and impossible to explain in an error message. A quarter
  is stable, explicable, and conservative enough to protect the database
  regardless.

## Consequences

- Clients wanting longer horizons must stitch windows together client-side;
  the documentation says so explicitly rather than leaving them to discover
  the validation error.
- The cap is part of the public contract now. Raising it later is
  backward-compatible; lowering it would break callers and effectively
  requires a `v2` of the endpoint. The cap therefore carries slack relative
  to current hardware rather than being tuned to it.
- The paginated list endpoints have the same exposure and are bounded the
  same way (`per_page` capped at 100). The gate-status listing is unpaginated
  but returns at most one row per gate — a count callers cannot inflate.

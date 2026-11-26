# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.x (latest release) | ✅ |
| older tags | ❌ — upgrade to the latest 1.x |

The `v1` API contract and its security guarantees are described in the
[versioning policy](docs/API.md#versioning).

## Reporting a vulnerability

Please report suspected vulnerabilities **privately** via GitHub's
[private vulnerability reporting](../../security/advisories/new)
("Report a vulnerability" under the repository's Security tab). Do not open a
public issue for anything you believe is exploitable.

What to expect:

- An acknowledgement within **72 hours**.
- An assessment and remediation plan within **7 days** for confirmed issues.
- Credit in the release notes, if you want it, once a fix ships.

## What is in scope

- Authentication and authorization (Bearer tokens, API keys, scopes).
- The rate limiter, idempotency store, and anything that could leak the
  secrets they protect.
- Injection of any kind through API parameters.

Out of scope: denial of service through sheer request volume (rate limiting is
documented and configurable), and reports against unsupported old tags.

## How secrets are stored

- **Passwords**: Argon2id (bcrypt hashes from before the switch are upgraded
  transparently at the next successful login).
- **Bearer tokens and API keys**: SHA-256 hashes; the plain value is shown
  exactly once at creation and never stored.
- **Recorded idempotency responses**: encrypted at rest with `APP_KEY`
  (they can contain an API key's one-time plain value) and pruned after the
  24-hour replay window.

## Key rotation runbook (zero downtime)

API keys are designed to overlap, so rotation never needs a maintenance
window:

1. **Create the replacement key** with the same scopes:
   `POST /api/v1/api-keys` (Bearer auth) — the plain key is returned once.
2. **Hand it to the partner** over your secure channel and let them switch.
   Both keys authenticate during the overlap; per-key rate limits apply to
   each key separately.
3. **Verify the old key has gone quiet**: `GET /api/v1/api-keys` shows each
   key's `last_used_at`.
4. **Revoke the old key**: `DELETE /api/v1/api-keys/{id}`. Revocation is
   immediate.

Notes:

- Keys also expire on their own after one year (`AUTH_API_KEY_TTL_DAYS`), so
  rotation is eventually forced even if forgotten.
- Bearer tokens expire after one hour and need no rotation procedure; expired
  tokens are pruned automatically.
- Rotating `APP_KEY` invalidates recorded idempotency responses (they are
  encrypted with it). That is acceptable by design: records live at most 24
  hours, and a client retrying across the rotation simply gets a fresh
  execution. Rotate `APP_KEY` at low-traffic times if replay continuity
  matters to you.

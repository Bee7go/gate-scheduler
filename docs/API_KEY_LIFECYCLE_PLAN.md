# API Key Lifecycle Plan

## Goal

Give authenticated users a safe way to view and revoke their own API keys. API key secrets must remain visible only at creation time.

## Scope

Implement these authenticated account endpoints:

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/api-keys` | List the current user's API keys and metadata. |
| `DELETE` | `/api/v1/api-keys/{apiKey}` | Revoke one API key owned by the current user. |

Both endpoints use the existing Bearer-token middleware. Creating keys with `POST /api/v1/api-keys` remains unchanged.

## API Contract

### List API keys

Return only metadata needed for management:

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 12,
      "name": "Production dashboard",
      "description": "Read-only reporting integration",
      "last_used_at": "2026-08-28T10:15:00.000000Z",
      "expires_at": "2027-08-28T09:00:00.000000Z",
      "created_at": "2026-08-28T09:00:00.000000Z"
    }
  ],
  "last_page": 1,
  "total": 1
}
```

Do not return the user ID, hashed `key` column, or original plaintext API key. Results are paginated with 15 keys per page.

### Revoke API key

Delete the key when it belongs to the authenticated user and return `204 No Content`.

Return `404 Not Found` when the key does not exist or belongs to another user. This prevents exposing key ownership.

## Implementation Steps

1. Add `index` and `destroy` actions to `ApiKeyController`.
2. Add the two routes inside the existing `auth.bearer` route group.
3. Query API keys through `$request->user()->apiKeys()` so ownership is enforced in the query itself.
4. Order the list by newest first and paginate it with a conservative default, such as 15 keys per page.
5. Return API-key metadata through a Laravel JSON resource or a deliberately shaped response array.
6. Delete only the resolved, user-owned model in `destroy`.
7. Update `docs/API.md` and the README endpoint table.

## Tests

Add feature coverage for:

- A valid Bearer token lists only its user's API keys.
- The response never includes `key` or a plaintext secret.
- The list is ordered consistently and paginated.
- A user can revoke their own key and the key no longer authenticates API-key requests.
- A user cannot revoke another user's key and receives `404`.
- Missing, invalid, and expired Bearer tokens receive `401`.

## Deferred Work

- Key rotation endpoint: create a replacement key, return its plaintext value once, then revoke the old key.
- Per-key permissions or scopes, such as read-only reporting access.
- Audit events for key creation and revocation.
- Soft deletion if audit retention becomes a product requirement.

## Acceptance Criteria

- Users can see and revoke only their own keys.
- API key secrets remain write-only and are never returned after creation.
- Revoked keys are rejected immediately by API-key authentication.
- All new behavior is documented and covered by feature tests.

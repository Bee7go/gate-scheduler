# API Documentation

## Base URL

All endpoints are prefixed with:

```
/api/v1
```

---

## Authentication

The API uses two layers of authentication:

| Layer | How to get it | Where to send it | Used for |
|-------|--------------|-------------------|----------|
| **Bearer token** | `POST /login` | `Authorization: Bearer <token>` | Account actions (creating API keys) |
| **API key** | `POST /api-keys` (requires Bearer) | `X-Api-Key: <key>` | Data endpoints (allocations, gates, etc.) |

**Bearer tokens** expire after 1 hour. **API keys** expire after 1 year.

Both are shown only once when created — store them safely.

---

## Quick Start

1. **Register** an account → `POST /register`
2. **Log in** to get a Bearer token → `POST /login`
3. **Create an API key** using your Bearer token → `POST /api-keys`
4. **Use the API key** for all data requests via the `X-Api-Key` header

---

## Endpoints Overview

### Authentication (no auth required)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/register` | Create a new user account |
| `POST` | `/login` | Log in and receive a Bearer token |

### Account Management (Bearer token required)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api-keys` | Generate a new API key |

### Data (API key required)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/allocations` | List flight-to-gate allocations (paginated, filterable) |
| `GET` | `/gates/status` | See which gates are free, occupied, or under maintenance |
| `GET` | `/gates/unavailabilities` | List gate unavailability windows |
| `POST` | `/gates/unavailabilities` | Create a new gate unavailability |

---

## Error Responses

All validation and authentication errors follow a consistent format.

### `401 Unauthorized`

Returned when authentication is missing or invalid.

```json
{
  "message": "Unauthenticated."
}
```

### `422 Unprocessable Entity`

Returned when request validation fails. Each field that failed validation is listed with its error messages.

```json
{
  "message": "The gate id field is required. (and 2 more errors)",
  "errors": {
    "gate_id": ["The gate id field is required."],
    "start_at": ["The start at field is required."],
    "end_at": ["The end at field is required."]
  }
}
```

---

## Endpoint Reference

### `POST /register`

Create a new account. No authentication needed.

#### Request Body

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| `name` | string | yes | Max 255 characters |
| `email` | string | yes | Valid email, unique, max 255 characters |
| `password` | string | yes | Min 8 characters, must contain letters and numbers |
| `password_confirmation` | string | yes | Must match `password` |

#### Example

```bash
curl -X POST https://your-app.test/api/v1/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Jane Doe",
    "email": "jane@example.com",
    "password": "secret1234",
    "password_confirmation": "secret1234"
  }'
```

#### Response (`201 Created`)

```json
{
  "data": {
    "id": 1,
    "name": "Jane Doe",
    "email": "jane@example.com",
    "created_at": "2026-04-30T12:00:00.000000Z"
  }
}
```

---

### `POST /login`

Log in and receive a Bearer token (valid for 1 hour). No authentication needed.

#### Request Body

| Field | Type | Required |
|-------|------|----------|
| `email` | string | yes |
| `password` | string | yes |

#### Example

```bash
curl -X POST https://your-app.test/api/v1/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "jane@example.com",
    "password": "secret1234"
  }'
```

#### Response (`200 OK`)

```json
{
  "data": {
    "token_type": "Bearer",
    "access_token": "your-access-token-here",
    "expires_in": 3600,
    "user": {
      "id": 1,
      "name": "Jane Doe",
      "email": "jane@example.com"
    }
  }
}
```

> Save the `access_token` — you'll need it to create API keys.

#### Response (`401 Unauthorized`) — wrong credentials

```json
{
  "message": "Invalid credentials."
}
```

---

### `POST /api-keys`

Generate an API key (valid for 1 year). Requires a **Bearer token**.

#### Request Body

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| `name` | string | yes | Max 255 characters |
| `description` | string | no | Max 255 characters |

#### Example

```bash
curl -X POST https://your-app.test/api/v1/api-keys \
  -H "Authorization: Bearer your-access-token-here" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "My App Key",
    "description": "Key for production use"
  }'
```

#### Response (`201 Created`)

```json
{
  "data": {
    "id": 1,
    "name": "My App Key",
    "token_type": "ApiKey",
    "api_key": "your-new-api-key-here",
    "created_at": "2026-04-30T12:00:00.000000Z",
    "expires_at": "2027-04-30T12:00:00.000000Z"
  }
}
```

> Save the `api_key` — it's only shown once. Use it via the `X-Api-Key` header for all data endpoints below.

---

### `GET /allocations`

List flight-to-gate allocations. Paginated and filterable. Requires an **API key**.

#### Query Parameters

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `gate_code` | string | no | Filter by gate code (e.g. `A1`) |
| `occupied_from` | datetime | no | Only allocations starting at or after this date |
| `occupied_until` | datetime | no | Only allocations ending at or before this date |
| `per_page` | integer | no | Results per page (1–100, default 15) |
| `page` | integer | no | Page number |

#### Example

```bash
curl "https://your-app.test/api/v1/allocations?gate_code=A1&per_page=2" \
  -H "X-Api-Key: your-api-key"
```

#### Response (`200 OK`)

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "gate_id": 1,
      "flight_id": 7,
      "occupied_from": "2026-04-30T10:00:00.000000Z",
      "occupied_until": "2026-04-30T11:30:00.000000Z",
      "gate": {
        "id": 1,
        "code": "A1"
      },
      "flight": {
        "id": 7,
        "icao24": "3c4b26",
        "airport_icao": "EHAM",
        "direction": "arrival",
        "first_seen_at": "2026-04-30T09:45:00.000000Z",
        "last_seen_at": "2026-04-30T10:00:00.000000Z"
      }
    }
  ],
  "last_page": 5,
  "total": 10
}
```

---

### `GET /gates/status`

See the status of every gate at a specific point in time. Requires an **API key**.

Each gate returns one of three statuses:
- **free** — no flight, no maintenance
- **occupied** — a flight is assigned
- **maintenance** — an unavailability window is active

#### Query Parameters

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `at` | datetime | no | Point in time to check (default: now) |
| `gate_code` | string | no | Filter to a specific gate |

#### Example

```bash
curl "https://your-app.test/api/v1/gates/status?at=2026-05-10T10:00:00Z" \
  -H "X-Api-Key: your-api-key"
```

#### Response (`200 OK`)

```json
{
  "data": [
    {
      "gate_id": 1,
      "gate_code": "A1",
      "status": "occupied",
      "occupied_until": "2026-05-10T11:30:00.000000Z",
      "flight": {
        "id": 7,
        "icao24": "3c4b26",
        "direction": "arrival"
      }
    },
    {
      "gate_id": 2,
      "gate_code": "A2",
      "status": "maintenance",
      "occupied_until": null,
      "flight": null
    },
    {
      "gate_id": 3,
      "gate_code": "A3",
      "status": "free",
      "occupied_until": null,
      "flight": null
    }
  ]
}
```

---

### `GET /gates/unavailabilities`

List gate unavailability windows (maintenance, cleaning, etc.). Filterable by gate and date range. Requires an **API key**.

Results are ordered by `start_at` ascending.

#### Query Parameters

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `gate_id` | integer | no | Filter by gate ID |
| `from` | datetime | no | Only unavailabilities overlapping with this start date |
| `to` | datetime | no | Only unavailabilities overlapping with this end date (must be after `from`) |

#### Example

```bash
curl "https://your-app.test/api/v1/gates/unavailabilities?gate_id=3&from=2026-05-01&to=2026-05-31" \
  -H "X-Api-Key: your-api-key"
```

#### Response (`200 OK`)

```json
{
  "data": [
    {
      "id": 1,
      "gate_id": 3,
      "start_at": "2026-05-10T08:00:00.000000Z",
      "end_at": "2026-05-10T12:00:00.000000Z",
      "reason": "Scheduled maintenance",
      "created_at": "2026-04-28T14:00:00.000000Z",
      "updated_at": "2026-04-28T14:00:00.000000Z"
    }
  ]
}
```

---

### `POST /gates/unavailabilities`

Mark a gate as unavailable for a period of time. Requires an **API key**.

#### Request Body

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| `gate_id` | integer | yes | Must reference an existing gate |
| `start_at` | datetime | yes | Start of the unavailability window |
| `end_at` | datetime | yes | End of the unavailability window (must be after `start_at`) |
| `reason` | string | no | Optional reason (max 255 characters) |

#### Example

```bash
curl -X POST https://your-app.test/api/v1/gates/unavailabilities \
  -H "X-Api-Key: your-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "gate_id": 3,
    "start_at": "2026-05-10T08:00:00Z",
    "end_at": "2026-05-10T12:00:00Z",
    "reason": "Maintenance"
  }'
```

#### Response (`201 Created`)

```json
{
  "data": {
    "id": 1,
    "gate_id": 3,
    "start_at": "2026-05-10T08:00:00.000000Z",
    "end_at": "2026-05-10T12:00:00.000000Z",
    "reason": "Maintenance",
    "created_at": "2026-04-30T09:15:00.000000Z",
    "updated_at": "2026-04-30T09:15:00.000000Z"
  }
}
```

> The `reason` field is optional — omit it or send `null` if you don't need one.

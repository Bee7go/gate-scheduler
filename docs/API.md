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
| `GET` | `/statistics` | Period-scoped statistics for dashboards |

### System (API key required)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/system/sync-now` | Trigger a flight sync on demand (rate limited) |
| `GET` | `/system/health` | Lightweight health check for the scheduler |

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

---

### `GET /statistics`

Return period-scoped statistics useful for building dashboards. Requires an **API key**.

All metrics are scoped strictly to the requested `from`/`to` interval. The endpoint does not call any external APIs and returns immediately.

#### Query Parameters

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `from` | datetime | **yes** | Start of the period (ISO 8601 or any parseable date) |
| `to` | datetime | **yes** | End of the period — must be after `from` |

#### Example

```bash
curl "https://your-app.test/api/v1/statistics?from=2026-04-01T00:00:00Z&to=2026-04-30T23:59:59Z" \
  -H "X-Api-Key: your-api-key"
```

#### Response (`200 OK`)

```json
{
  "data": {
    "period": {
      "from": "2026-04-01T00:00:00+00:00",
      "to": "2026-04-30T23:59:59+00:00"
    },
    "gates": {
      "total": 12,
      "active": 10,
      "had_unavailability": 2,
      "utilization_rate": 0.75,
      "average_turnaround_minutes": 22
    },
    "flights": {
      "total": 628,
      "arrivals": 314,
      "departures": 314,
      "unallocated": 38,
      "allocation_rate": 0.94
    },
    "allocations": {
      "total": 590,
      "average_duration_minutes": 90,
      "shortest_duration_minutes": 45,
      "longest_duration_minutes": 210
    },
    "peak": {
      "busiest_hour": "17:00",
      "max_simultaneous_gates": 10,
      "busiest_date": "2026-04-15",
      "busiest_date_allocations": 32
    },
    "top_gates": [
      { "gate_code": "G1", "allocations_count": 48 },
      { "gate_code": "G5", "allocations_count": 42 },
      { "gate_code": "G3", "allocations_count": 39 },
      { "gate_code": "G8", "allocations_count": 35 },
      { "gate_code": "G2", "allocations_count": 30 }
    ],
    "unavailability": {
      "total_events": 5,
      "total_downtime_minutes": 300,
      "affected_gates": 2,
      "most_common_reason": "Scheduled maintenance"
    },
    "generated_at": "2026-04-30T15:10:00+00:00"
  }
}
```

#### Field Reference

**`period`**

| Field | Description |
|-------|-------------|
| `from` | Start of the requested period (echoed back as ISO 8601) |
| `to` | End of the requested period (echoed back as ISO 8601) |

**`gates`**

| Field | Description |
|-------|-------------|
| `total` | Total number of gates in the system |
| `active` | Gates that had at least one allocation during the period |
| `had_unavailability` | Gates that had at least one unavailability window overlapping the period |
| `utilization_rate` | `sum(occupied_minutes) / (total_gates × period_minutes)` — 0 to 1 |
| `average_turnaround_minutes` | Average gap between consecutive allocations on the same gate (`null` if fewer than 2 allocations per gate) |

**`flights`**

| Field | Description |
|-------|-------------|
| `total` | Flights with `first_seen_at` within the period |
| `arrivals` | Subset with `direction = arrival` |
| `departures` | Subset with `direction = departure` |
| `unallocated` | Flights in the period without a gate assignment |
| `allocation_rate` | `(total - unallocated) / total` — 0 to 1 (`0` when no flights) |

**`allocations`**

| Field | Description |
|-------|-------------|
| `total` | Allocations whose window overlaps the period |
| `average_duration_minutes` | Mean of `occupied_until - occupied_from` in minutes (`null` when no allocations) |
| `shortest_duration_minutes` | Shortest single allocation (`null` when no allocations) |
| `longest_duration_minutes` | Longest single allocation (`null` when no allocations) |

**`peak`**

| Field | Description |
|-------|-------------|
| `busiest_hour` | Hour slot (e.g. `"17:00"`) with the most simultaneous allocations |
| `max_simultaneous_gates` | Number of gates occupied at the same time during `busiest_hour` |
| `busiest_date` | Calendar date (e.g. `"2026-04-15"`) with the most overlapping allocations |
| `busiest_date_allocations` | Allocation count on `busiest_date` |

**`top_gates`**

Array of up to 5 gates ordered by allocation count descending.

| Field | Description |
|-------|-------------|
| `gate_code` | Gate identifier (e.g. `"G1"`) |
| `allocations_count` | Number of allocations in the period |

**`unavailability`**

| Field | Description |
|-------|-------------|
| `total_events` | Unavailability records overlapping the period |
| `total_downtime_minutes` | Sum of downtime clipped to the period boundaries |
| `affected_gates` | Distinct gate count with at least one unavailability event |
| `most_common_reason` | Most frequently used `reason` string (`null` if all reasons are null or no events) |

#### Validation Errors (`422`)

```json
{
  "message": "The to field must be a date after from.",
  "errors": {
    "to": ["The to field must be a date after from."]
  }
}
```

Trigger a flight sync on demand. Fetches the latest flights from OpenSky and allocates them to gates. Requires an **API key**.

This endpoint is **rate limited** to 1 request per 2 minutes (per API key) and uses a lock to prevent overlapping syncs.

#### Example

```bash
curl -X POST https://your-app.test/api/v1/system/sync-now \
  -H "X-Api-Key: your-api-key"
```

#### Response (`200 OK`)

```json
{
  "data": {
    "arrivals_fetched": 5,
    "departures_fetched": 3,
    "allocation": {
      "allocated": 4,
      "unallocated": 1
    }
  }
}
```

#### Response (`409 Conflict`) — sync already in progress

```json
{
  "message": "A sync is already in progress. Please try again later."
}
```

#### Response (`429 Too Many Requests`) — rate limited

Returned if more than 1 request is made within a 2-minute window.

---

### `GET /system/health`

Return a lightweight health summary for the scheduler, database, sync process, and gates. Requires an **API key**.

This endpoint is intentionally cheap — it checks database connectivity and reads a few counts. It does not call any external APIs. Returns `503` if the database is unreachable.

#### Example

```bash
curl https://your-app.test/api/v1/system/health \
  -H "X-Api-Key: your-api-key"
```

#### Response (`200 OK`)

```json
{
  "data": {
    "status": "healthy",
    "database": {
      "status": "ok"
    },
    "sync": {
      "last_synced_at": "2026-04-30T14:24:04+00:00"
    },
    "flights": {
      "total": 628
    },
    "gates": {
      "total": 10,
      "active_allocations": 3,
      "active_unavailabilities": 1
    },
    "checked_at": "2026-04-30T14:58:56+00:00"
  }
}
```

| Field | Description |
|-------|-------------|
| `status` | `healthy` if database is reachable, `degraded` otherwise |
| `database.status` | `ok` or `unreachable` |
| `sync.last_synced_at` | ISO 8601 timestamp of the most recently updated flight (null if no flights) |
| `flights.total` | Total number of flights stored in the database |
| `gates.total` | Total number of gates |
| `gates.active_allocations` | Allocations active right now |
| `gates.active_unavailabilities` | Unavailability windows active right now |
| `checked_at` | ISO 8601 timestamp when the health check ran |

#### Response (`503 Service Unavailable`) — database unreachable

All database-backed fields are returned as `null`. The response shape is identical so clients can always parse it.

```json
{
  "data": {
    "status": "degraded",
    "database": { "status": "unreachable" },
    "sync": { "last_synced_at": null },
    "flights": { "total": null },
    "gates": {
      "total": null,
      "active_allocations": null,
      "active_unavailabilities": null
    },
    "checked_at": "2026-04-30T14:58:56+00:00"
  }
}

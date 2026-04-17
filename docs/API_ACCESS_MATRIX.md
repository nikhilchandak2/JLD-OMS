# OMS / CRM API Access Matrix

This matrix captures **effective access** as implemented in code (router + controller checks).

## Baseline Rules

- All routes under `/api/*` are CSRF-protected by global middleware.
- Rate-limits are enforced on selected endpoints (`/api/login`, GPS/Fuel webhooks, CSV imports).
- Routes inside the protected `/api` group require a valid authenticated session (`AuthMiddleware`).
- Public exceptions (no `AuthMiddleware`):  
  - `POST /api/login`  
  - `POST /api/logout`  
  - `GET /api/session-status`  
  - `POST /api/gps/webhook`  
  - `POST /api/fuel/webhook`  
  - `POST /api/gps/batch`

## Authentication + Webhooks

| Method | Endpoint | Access |
|---|---|---|
| POST | `/api/login` | Public |
| POST | `/api/logout` | Public (session-aware behavior) |
| GET | `/api/session-status` | Public |
| POST | `/api/gps/webhook` | Public + API key/HMAC validation (if configured) |
| POST | `/api/fuel/webhook` | Public + API key/HMAC validation (if configured) |
| POST | `/api/gps/batch` | Public + API key/HMAC validation (if configured) |

## Orders

| Method | Endpoint | Access |
|---|---|---|
| GET | `/api/orders` | `admin`, `order_processing`, `entry`, `view` |
| GET | `/api/orders/{id}` | `admin`, `order_processing`, `entry`, `view` |
| GET | `/api/orders/{id}/scheduled-deliveries` | `admin`, `order_processing`, `entry`, `view` |
| POST | `/api/orders` | `admin`, `order_processing`, `entry` |
| PUT | `/api/orders/{id}` | `admin`, `order_processing`, `entry` |
| DELETE | `/api/orders/{id}` | `admin` |
| GET | `/api/orders/credit-approvals/pending` | `admin` |
| POST | `/api/orders/credit-approvals/{id}/decide` | `admin` |

## Dispatches

| Method | Endpoint | Access |
|---|---|---|
| GET | `/api/dispatches` | `admin`, `order_processing`, `entry`, `view` |
| POST | `/api/orders/{id}/dispatches` | `admin`, `order_processing`, `entry` |

## Parties

| Method | Endpoint | Access |
|---|---|---|
| GET | `/api/parties` | `admin`, `entry`, `accounts`, `crm` |
| GET | `/api/parties/{id}` | `admin`, `entry`, `accounts`, `crm` |
| POST | `/api/parties` | `admin`, `entry`, `accounts`, `crm` |
| PUT | `/api/parties/{id}` | `admin`, `entry`, `accounts`, `crm` |
| DELETE | `/api/parties/{id}` | `admin`, `accounts` |
| POST | `/api/parties/import` | `admin`, `entry`, `accounts`, `crm` |

## Products

| Method | Endpoint | Access |
|---|---|---|
| GET | `/api/products` | `admin`, `entry`, `accounts` |
| GET | `/api/products/{id}` | `admin`, `entry`, `accounts` |
| POST | `/api/products` | `admin`, `entry`, `accounts` |
| PUT | `/api/products/{id}` | `admin`, `entry`, `accounts` |
| DELETE | `/api/products/{id}` | `admin`, `entry`, `accounts` |

## Companies

| Method | Endpoint | Access |
|---|---|---|
| GET | `/api/companies` | Any authenticated user |
| GET | `/api/companies/{id}` | Any authenticated user |

## Reports + Analytics

| Method | Endpoint | Access |
|---|---|---|
| GET | `/api/reports/partywise` | `admin`, `view` |
| GET | `/api/reports/partywise/export` | `admin`, `view` |
| GET | `/api/reports/parties` | Any authenticated user |
| GET | `/api/reports/products` | Any authenticated user |
| GET | `/api/analytics/orders` | Any authenticated user |
| GET | `/api/analytics/dispatches` | Any authenticated user |
| GET | `/api/analytics/pending` | Any authenticated user |
| GET | `/api/analytics/parties` | Any authenticated user |

## CRM Core

| Method | Endpoint | Access |
|---|---|---|
| GET | `/api/crm/summary` | `admin`, `entry`, `crm` |
| GET | `/api/crm/stages` | `admin`, `entry`, `crm` |
| GET | `/api/crm/funnel` | `admin`, `entry`, `crm` |
| GET | `/api/crm/users/options` | `admin`, `entry`, `crm` |

## CRM Contacts

| Method | Endpoint | Access |
|---|---|---|
| GET | `/api/crm/parties/{partyId}/contacts` | `admin`, `entry`, `crm` |
| POST | `/api/crm/parties/{partyId}/contacts` | `admin`, `entry`, `crm` |
| GET | `/api/crm/contacts/{id}` | `admin`, `entry`, `crm` |
| PUT | `/api/crm/contacts/{id}` | `admin`, `entry`, `crm` |
| DELETE | `/api/crm/contacts/{id}` | `admin`, `entry`, `crm` |

## CRM Activities

| Method | Endpoint | Access |
|---|---|---|
| GET | `/api/crm/activities` | `admin`, `entry`, `crm` |
| GET | `/api/crm/activities/{id}` | `admin`, `entry`, `crm` |
| POST | `/api/crm/activities` | `admin`, `entry`, `crm` |
| PUT | `/api/crm/activities/{id}` | `admin`, `entry`, `crm` |
| DELETE | `/api/crm/activities/{id}` | `admin`, `entry`, `crm` |

## CRM Tasks

| Method | Endpoint | Access |
|---|---|---|
| GET | `/api/crm/tasks` | `admin`, `entry`, `crm` (admin can request all via `?all=1`) |
| POST | `/api/crm/tasks` | `admin` |
| PUT | `/api/crm/tasks/{id}` | `admin` or assigned task owner (must also be `entry`/`crm`) |
| DELETE | `/api/crm/tasks/{id}` | `admin` |

## CRM Samples

| Method | Endpoint | Access |
|---|---|---|
| GET | `/api/crm/samples` | `admin`, `entry`, `crm` |
| GET | `/api/crm/samples/{id}` | `admin`, `entry`, `crm` |
| POST | `/api/crm/samples` | `admin`, `entry`, `crm` |
| PUT | `/api/crm/samples/{id}` | `admin`, `entry`, `crm` |
| DELETE | `/api/crm/samples/{id}` | `admin`, `entry`, `crm` |

## CRM Receivables

| Method | Endpoint | Access |
|---|---|---|
| GET | `/api/crm/parties/{partyId}/receivables` | `admin`, `entry`, `crm`, `accounts` |
| GET | `/api/crm/receivables/aging` | `admin`, `entry`, `crm`, `accounts` |
| POST | `/api/crm/receivables` | `admin`, `entry`, `crm`, `accounts` |
| DELETE | `/api/crm/receivables/{id}` | `admin`, `entry`, `crm`, `accounts` |
| POST | `/api/crm/receivables/import` | `admin`, `entry`, `crm`, `accounts` |

## Notes for Further Hardening

- Several controllers still expose raw exception messages in some endpoints; prefer generic error responses + server logging.
- If you want stricter least privilege, reduce delete rights for CRM Contacts/Activities/Samples from `entry/crm` to admin-only.
- If multi-tenant scoping is introduced later, enforce tenant/company ownership checks in repositories for all `{id}` reads/writes.

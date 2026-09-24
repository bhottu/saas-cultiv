# SaaS Platform

Production-ready multi-tenant SaaS starter (Laravel 12, PHP 8.2+, PostgreSQL, Redis, Tailwind, QRIS.PW payments).

## Architecture

```
SaaS infrastructure (generic, reusable)      Business/domain (add yours here)
├── Tenants, tenant_user (RBAC)             ├── app/Models/<YourDomain>.php
├── Plans, Subscriptions, Invoices          ├── app/Http/Controllers/Domain/
├── Payments + QRIS.PW webhooks             └── resources/views/domain/
├── Usage tracking & limits
├── Files (object storage ready)
├── Audit logs, Notifications, API (Sanctum)
└── Admin panel, rate limiting, backups
```

**Multi-tenancy**: shared database; every tenant-owned table has `tenant_id`. The `BelongsToTenant`
trait applies a global query scope and stamps new records — cross-tenant access (IDOR) is impossible
through Eloquent. Tenant resolution: session → server-side membership validation in
`EnsureTenantContext`. The browser never decides the active tenant.

**RBAC**: `config/permissions.php` maps roles (Owner/Admin/Manager/Staff/Viewer) to permissions,
enforced server-side via gates + `TenantContext::authorize()`. Hiding UI buttons is cosmetic only.

**Billing lifecycle**:

```
Choose plan → Invoice created → QRIS.PW create-payment → pending (+expires_at)
   → customer scans → webhook POST /api/webhooks/qris
   → HMAC-SHA256 signature verified → order/transaction/amount verified
   → row locked → payment=paid → invoice=paid → subscription active/renewed
   → notification queued → audit logged
```

- Webhook idempotency: `webhook_events.event_id` = provider+transaction+status (unique); replays acked, not reprocessed.
- Webhook retries (3× per QRIS.PW docs) are safe.
- Status fallback: `payments:reconcile` polls `check-payment.php` every 10 min for stale pending payments (≤ QRIS.PW 100 req/min limit).
- QRIS expiry: `expires_at` stored; expired payments → `expired`; new payment creates a fresh record (never reuses an expired transaction).
- Subscription states: `trialing | active | past_due | cancelled | expired | paused`. Expired tenants keep their data (free tier) for `SAAS_DATA_RETENTION_DAYS` before purge.

## Key files

| Area | Files |
|---|---|
| Tenancy core | `app/Concerns/BelongsToTenant.php`, `app/Services/TenantContext.php`, `app/Http/Middleware/EnsureTenantContext.php` |
| Billing | `app/Services/{PaymentService,SubscriptionService,WebhookService,QrisPwClient}.php` |
| Usage limits | `app/Services/UsageService.php` |
| Files (object storage ready) | `app/Http/Controllers/FileController.php`, `config/saas.php` → `uploads` |
| Webhook | `routes/api.php` → `WebhookController@qris` |
| Schedules | `routes/console.php` (sweep, reminders, reconcile, purge) |
| Tests | `tests/Feature/{QrisWebhookTest,MultiTenancyIsolationTest,SaasFlowSmokeTest,FileUploadTest}.php` |

## Environment (production)

```env
DB_CONNECTION=pgsql          # PostgreSQL
QUEUE_CONNECTION=redis       # Redis for queues/cache/sessions
FILESYSTEM_DISK=s3           # S3/R2 object storage for uploads

QRISPW_API_KEY=...           # server-side ONLY — never in JS/browser
QRISPW_API_SECRET=...
QRISPW_WEBHOOK_SECRET=...
QRISPW_CALLBACK_URL=https://your-domain.com/api/webhooks/qris
```

## Deployment checklist (VPS)

1. `composer install --no-dev --optimize-autoloader`
2. `php artisan migrate --force && php artisan db:seed --force`
3. `npm run build`
4. `php artisan config:cache route:cache view:cache event:cache`
5. Queue worker (supervisor): `php artisan queue:work --tries=3 --backoff=5`
6. Scheduler (cron): `* * * * * php artisan schedule:run`
7. HTTPS + HSTS (Let's Encrypt / platform TLS); `APP_DEBUG=false`
8. Point QRIS.PW callback URL at `https://your-domain.com/api/webhooks/qris`

## Backups

- **Database**: nightly `pg_dump` + WAL archiving (PITR). Retention: 7 daily, 4 weekly, 6 monthly. **Restore must be tested quarterly** — an untested backup is not a backup.
- **Object storage**: enable versioning + lifecycle rules on the S3/R2 bucket.
- **Config**: `.env` stored in a secrets manager (never committed).
- **Disaster recovery**: provision fresh server → restore latest dump → restore bucket → rotate secrets → verify webhook signature with new secret → smoke-test checkout.

## Adding business/domain features

1. Add model with `use BelongsToTenant;` (table needs `tenant_id`).
2. Add controller; call `$ctx->authorize('permission')` for RBAC.
3. Meter usage via `UsageService::record($tenant, 'metric')`; enforce with `enforce()`.
4. Add tests — including a cross-tenant access denial test.

## Commands

```bash
php artisan test                 # full suite (sqlite in-memory)
php artisan payments:reconcile   # manual payment status sweep
php artisan tenants:purge        # hard-delete past retention (audited)
```

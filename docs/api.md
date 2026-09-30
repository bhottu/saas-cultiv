# Cultiv One — Public Business API

Base URL: `/api/v1`

Read-only JSON API for integrations (POS, mobile, reporting, marketplace
sync). It reuses the existing Sanctum token system, the existing RBAC registry
and the existing tenant scope — no second auth or tenancy layer exists.

---

## 1. Authentication

Business API is an **entitlement**: only the **Business** plan carries
`api_access`. Free, Starter and Pro are refused by the `plan.feature:api_access`
middleware before any business query is executed.

```json
403 {
  "message": "API Access are available on Business plans. Upgrade your plan to continue.",
  "code": "subscription_limit_reached",
  "upgrade_required": true,
  "limit": { "resource": "API Access", "required_plans": "Business" }
}
```

Create a token in the web UI: **Account → API Tokens** (`POST /tokens`).
The token is shown **once**; Sanctum stores only a hash.

```http
Authorization: Bearer <token>
Accept: application/json
```

### Workspace binding

A token is a *user* token, not a workspace token. The active workspace is
resolved server-side in this order:

1. `users.current_tenant_id`
2. validated against `tenant_user` membership in `EnsureTenantContext`

A token can therefore never read a workspace its owner is not a member of.
Every model used here applies the `BelongsToTenant` global scope, so a query
string cannot widen the scope — a foreign id returns **404**, not a row.

Rate limit: **60 requests/minute** per token owner.

---

## 2. Conventions

### Money

Every monetary value is an **integer in minor units (cents)**. Floating point
never appears in a financial payload. The currency travels once per response:

```json
"meta": { "currency": "IDR", "money_unit": "cents", "workspace_id": 1 }
```

`1500000` means **Rp 15.000,00**.

### Pagination

```json
{
  "data": [ ... ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": {
    "current_page": 1, "last_page": 1, "per_page": 25,
    "from": 1, "to": 1, "total": 1,
    "currency": "IDR", "money_unit": "cents", "workspace_id": 1
  }
}
```

`per_page` defaults to **25** and is hard-capped at **100**; a larger value is
silently clamped.

### Errors

| Status | Meaning |
|---:|---|
| `401` | Missing or invalid token |
| `403` | Plan entitlement missing, or the role lacks the permission |
| `404` | Not found **in the active workspace** (never "exists elsewhere") |

### Contacts

| Method | Path | Filters |
|---|---|---|
| GET | `/customers` | `search` (name/phone/email), `active_only`, `inactive_only` |
| GET | `/customers/{id}` | Includes lifetime `orders` and `spent` |
| GET | `/customers/{id}/sales` | That customer's order history |
| GET | `/suppliers` | `search`, `active_only`, `inactive_only` |
| GET | `/suppliers/{id}` | |

### Inventory

| Method | Path | Filters |
|---|---|---|
| GET | `/warehouses` | `search`, `active_only`, `inactive_only` |
| GET | `/warehouses/{id}` | |
| GET | `/stock` | `warehouse_id`, `product_id`, `in_stock`, `low_stock`, `search`, `per_page` |
| GET | `/stock/summary` | On-hand **aggregated across warehouses**, with `is_low_stock` |
| GET | `/stock/{product_id}` | On-hand split per warehouse |

### Transactions

| Method | Path | Filters |
|---|---|---|
| GET | `/sales` | `search`, `status[]`, `payment_status[]`, `customer_id`, `sales_channel`, `payment_method`, `from`, `to` |
| GET | `/sales/{id}` | Items + payment records |
| GET | `/purchases` | `search`, `status[]`, `supplier_id`, `warehouse_id`, `from`, `to` |
| GET | `/purchases/{id}` | Items included |

Dates use `YYYY-MM-DD` and are applied to `sold_at` (sales) and `ordered_at`
(purchases).

---

## 4. Historical accuracy

`sale_items` stores `selling_price` and `cost_price` as **snapshots taken when
the sale was created**. Repricing a product later never rewrites an old
invoice, so `totals.gross_profit` on a historic sale stays reproducible:

```json
"totals": {
  "subtotal": 3000000, "total": 3000000,
  "total_cogs": 2000000, "gross_profit": 1000000
}
```

Line `cogs` is `quantity × cost_price` from the same snapshot.

---

## 5. Write operations

The business API is deliberately **read-only**. Creating or changing a sale,
purchase, product or stock balance over HTTP would duplicate the domain rules
(validation, role checks, stock row locking, atomic movement records) that
already live in the domain services — and any copy of those rules drifts.

Writes stay on the web layer:

| Operation | Endpoint |
|---|---|
| Record a sale | `POST /sales` |
| Cancel / refund a sale | `POST /sales/{id}/cancel`, `/refund` |
| Record a return | `POST /sales/{id}/return` |
| Create a purchase | `POST /purchases` |
| Receive a purchase | `POST /purchases/{id}/receive` |
| Adjust stock | `POST /stock/adjust` |
| Create a product | `POST /products` |
| Create a customer | `POST /customers` |

---

## 6. Example

```bash
curl -s https://your-domain.com/api/v1/stock/summary?low_stock=1 \
  -H "Authorization: Bearer $CULTIV_TOKEN" \
  -H "Accept: application/json"
```

```json
{
  "data": [
    {
      "product_id": 12, "name": "Monstera Albo", "unit": "pcs",
      "minimum_stock": 5, "quantity_on_hand": 3, "is_low_stock": true
    }
  ],
  "meta": { "current_page": 1, "per_page": 25, "total": 1 }
}
```

---

## 7. Webhook (not part of the authenticated API)

```text
POST /api/webhooks/qris
```

The QRIS.PW payment callback is **not** authenticated by a user token. It is
verified by HMAC-SHA256 signature, order id, transaction id and amount, is
idempotent, and is rate limited separately. See the project README.

| `422` | A required parameter is missing |
| `429` | Rate limit exceeded |

---

## 3. Endpoints

### Workspace

| Method | Path | Notes |
|---|---|---|
| GET | `/me` | Token owner + active workspace + role |
| GET | `/usage` | Plan usage vs limit: seats, API calls, storage |
| GET | `/payments` | SaaS subscription payments for this workspace |

### Catalogue

| Method | Path | Filters |
|---|---|---|
| GET | `/products` | `search`, `category_id`, `brand_id`, `active_only`, `inactive_only`, `min_price`, `max_price`, `low_stock`, `per_page`, `page` |
| GET | `/products/lookup` | `code` — matches `barcode` first, then `sku`. `404` + `code: product_not_found` when unknown |
| GET | `/products/{id}` | Detail incl. stock per warehouse |
| GET | `/categories` | `search`, `active_only`, `inactive_only` |
| GET | `/categories/{id}` | |
| GET | `/brands` | `search`, `active_only`, `inactive_only` |
| GET | `/brands/{id}` | |

> `low_stock` compares each balance against **that product's own**
> `minimum_stock`, never against a fixed number.

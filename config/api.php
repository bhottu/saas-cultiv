<?php

/*
 | Global API capability registry for /api/v1 (platform configuration, NOT per workspace).
 |
 | This list is the source of truth for WHICH resources the public API can expose at
 | all, and WHICH operations each of them really implements. It only ever contains
 | operations that exist in Cultiv One — an endpoint that does not exist is deliberately
 | absent so /admin/api can never advertise it (§6/§7: no checkbox without an endpoint).
 |
 | Three operations, matching the HTTP verbs actually routed in routes/api.php:
 |
 |   read   => GET      list/show
 |   write  => POST/PUT/PATCH   create and/or update
 |   delete => DELETE   destroy, only where the web layer already supports it and the
 |                      same guard can be applied (e.g. a warehouse holding stock)
 |
 | A capability absent from a resource defaults to FALSE, so forgetting to declare an
 | operation never accidentally exposes it.
 |
 | Per-resource Read/Write/Delete toggles live in the `api_capabilities` table (seeded
 | from this registry). `write => true` / `delete => true` only ever mark operations
 | that are routed AND authorised today.
 */
return [
    'resources' => [
        // Master data — web CRUD exists; API has index/show/store/update, no destroy.
        'products' => ['label' => 'Products', 'write' => true, 'delete' => false],
        'categories' => ['label' => 'Categories', 'write' => true, 'delete' => false],
        'brands' => ['label' => 'Brands', 'write' => true, 'delete' => false],
        'customers' => ['label' => 'Customers', 'write' => true, 'delete' => false],
        'suppliers' => ['label' => 'Suppliers', 'write' => true, 'delete' => false],

        // Warehouses — web supports create/edit/delete with the "no stock inside" guard.
        'warehouses' => ['label' => 'Warehouses', 'write' => true, 'delete' => true],

        // Stock is never a free-form number: the only write is a stock adjustment that
        // records a StockMovement. There is no "delete stock" operation at all.
        'stock' => ['label' => 'Stock', 'write' => true, 'delete' => false],

        // Sales are historical financial records: creatable through RecordSaleService,
        // never overwritten and never deleted via API.
        'sales' => ['label' => 'Sales', 'write' => true, 'delete' => false],

        // Purchases mirror the web layer: draft purchases can be edited, and destroyed
        // only while they are still a draft (never once received).
        'purchases' => ['label' => 'Purchases', 'write' => true, 'delete' => true],

        // Payment records are webhook-verified historical data — read-only by design.
        'payments' => ['label' => 'Payments', 'write' => false, 'delete' => false],
    ],
];
<?php

/*
 | Global API capability registry for /api/v1 (platform configuration, NOT per workspace).
 |
 | This list is the source of truth for WHICH resources the public API can expose at
 | all. It only ever contains resources that really exist in Cultiv One — an endpoint
 | that does not exist is deliberately absent so /admin/api can never advertise it.
 |
 | Per-resource Read/Write toggles live in the `api_capabilities` table (seeded from
 | this registry). `write => true` marks a resource for which POST/PUT/PATCH endpoints
 | actually exist; such a resource shows a Write checkbox on /admin/api. Everything
 | else is read-only by design (stock movements, sales and purchases keep their full
 | web-layer business rules and are never written through the API).
 */
return [
    'resources' => [
        'products' => ['label' => 'Products', 'write' => true],
        'categories' => ['label' => 'Categories', 'write' => true],
        'brands' => ['label' => 'Brands', 'write' => true],
        'customers' => ['label' => 'Customers', 'write' => true],
        'suppliers' => ['label' => 'Suppliers', 'write' => true],
        'warehouses' => ['label' => 'Warehouses', 'write' => false],
        'stock' => ['label' => 'Stock', 'write' => false],
        'sales' => ['label' => 'Sales', 'write' => false],
        'purchases' => ['label' => 'Purchases', 'write' => false],
        'payments' => ['label' => 'Payments', 'write' => false],
    ],
];
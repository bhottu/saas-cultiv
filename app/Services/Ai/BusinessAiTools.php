<?php

namespace App\Services\Ai;

use App\Models\AiChannelLink;
use App\Models\AiPendingAction;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\TenantUser;
use App\Models\Warehouse;
use App\Services\BusinessAuthorization;
use App\Services\CalculateSaleTotals;
use App\Services\Money;
use App\Services\PurchaseOrderService;
use App\Services\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BusinessAiTools
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BusinessAuthorization $authorization,
        private readonly CalculateSaleTotals $calculator,
        private readonly PurchaseOrderService $purchaseOrders,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function definitions(): array
    {
        return [
            [
                'name' => 'get_workspace_info',
                'description' => 'Read the name of the currently linked and verified workspace. Use for questions about the current workspace identity.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass,
                ],
            ],
            [
                'name' => 'search_products',
                'description' => 'Search active products by name, SKU or barcode.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string', 'description' => 'Product name, SKU or barcode']],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_stock',
                'description' => 'Read current stock for an active workspace product. Provide either product_id or a product name, SKU, or barcode as query. If the user did not identify a product, call this tool without arguments so the assistant can ask which product.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'description' => 'Product ID returned by search_products'],
                        'query' => ['type' => 'string', 'description' => 'Product name, SKU, or barcode'],
                    ],
                ],
            ],
            [
                'name' => 'get_sales_summary',
                'description' => 'Read sales totals and order count for a recent period (1 to 90 days).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['days' => ['type' => 'integer', 'description' => 'Number of days, 1 to 90']],
                    'required' => ['days'],
                ],
            ],
            [
                'name' => 'search_customers',
                'description' => 'Search active workspace customers by name, phone number or email. Returns every detail field shown on the website customers page (name, phone, email, address, credit limit, notes); empty values are reported as "-". Use it to find a customer or to show customer details. Never create a customer as a side effect of searching.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string', 'description' => 'Customer name, phone number or email']],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'draft_sale',
                'description' => 'Prepare a sale draft only when required details are available. You may pass items with product IDs, or product_query and quantity for lookup. If details are incomplete, call this tool with what is known so it can return what is needed next. Resolve supplied customer_query using search_customers; never create or invent a customer. A customer is optional and may be omitted for walk-in. Prices and totals always come from the workspace database. A payment method does not imply that money was received; only pass payment_amount when the user explicitly gives an amount received. A pending draft requires Telegram confirmation before recording the sale.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'product_id' => ['type' => 'integer'],
                                    'quantity' => ['type' => 'integer'],
                                ],
                                'required' => ['product_id', 'quantity'],
                            ],
                        ],
                        'product_query' => ['type' => 'string', 'description' => 'Product name, SKU, or barcode to resolve in the active workspace'],
                        'quantity' => ['type' => 'integer', 'description' => 'Requested number of units for product_query'],
                        'customer_id' => ['type' => 'integer'],
                        'customer_query' => ['type' => 'string', 'description' => 'Existing customer name or phone to resolve in the active workspace'],
                        'customer_phone' => ['type' => 'string', 'description' => 'Phone supplied by user; verify against an existing customer only'],
                        'payment_method' => [
                            'type' => 'string',
                            'enum' => array_keys(config('business.sales.payment_methods', [])),
                        ],
                        'payment_amount' => ['type' => 'number', 'description' => 'Amount received in the workspace currency; omit when not yet paid.'],
                    ],
                ],
            ],
            [
                'name' => 'list_expenses',
                'description' => 'Read expenses of the active workspace with optional date-range and category filters. Returns the matching rows plus the filtered total amount and a pagination hint. Read-only.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => 'Earliest expense date (YYYY-MM-DD)'],
                        'to' => ['type' => 'string', 'description' => 'Latest expense date (YYYY-MM-DD)'],
                        'category' => ['type' => 'string', 'description' => 'Expense category name, partial match'],
                        'category_id' => ['type' => 'integer', 'description' => 'Expense category ID'],
                        'limit' => ['type' => 'integer', 'description' => 'Rows per page, 1-50 (default 10)'],
                        'page' => ['type' => 'integer', 'description' => 'Page number starting at 1'],
                    ],
                ],
            ],
            [
                'name' => 'get_expense',
                'description' => 'Read one expense of the active workspace by its ID, including category, payment method and notes.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['expense_id' => ['type' => 'integer']],
                    'required' => ['expense_id'],
                ],
            ],
            [
                'name' => 'create_expense',
                'description' => 'Prepare an expense draft. Nothing is saved until the user presses the confirmation button in Telegram. Requires a positive amount and a short description; the category is optional but must be an existing workspace category when given — never invent categories, dates or amounts. Date defaults to today only when the user did not mention one.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'amount' => ['type' => 'number', 'description' => 'Amount in workspace currency (250000 means Rp250.000)'],
                        'description' => ['type' => 'string', 'description' => 'Short purpose of the expense'],
                        'date' => ['type' => 'string', 'description' => 'Expense date YYYY-MM-DD (defaults to today)'],
                        'category_id' => ['type' => 'integer', 'description' => 'Existing expense category ID'],
                        'category_name' => ['type' => 'string', 'description' => 'Expense category name exactly as the user said it'],
                        'payment_method' => ['type' => 'string', 'enum' => ['cash', 'bank', 'transfer', 'qris', 'other']],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['amount'],
                ],
            ],
            [
                'name' => 'list_purchases',
                'description' => 'Read purchases (purchase orders) of the active workspace with optional status, supplier and date-range filters. Returns rows plus the filtered total value. Read-only; receiving stock is a separate workflow.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['draft', 'ordered', 'received', 'cancelled']],
                        'supplier' => ['type' => 'string', 'description' => 'Supplier name, partial match'],
                        'supplier_id' => ['type' => 'integer'],
                        'from' => ['type' => 'string', 'description' => 'Earliest order date (YYYY-MM-DD)'],
                        'to' => ['type' => 'string', 'description' => 'Latest order date (YYYY-MM-DD)'],
                        'limit' => ['type' => 'integer', 'description' => 'Rows per page, 1-50 (default 10)'],
                        'page' => ['type' => 'integer', 'description' => 'Page number starting at 1'],
                    ],
                ],
            ],
            [
                'name' => 'get_purchase',
                'description' => 'Read one purchase of the active workspace by purchase ID or reference number (for example PUR-20261010-0001), with supplier, items, prices, totals and status.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'purchase_id' => ['type' => 'integer'],
                        'reference' => ['type' => 'string', 'description' => 'Reference / invoice number of the purchase'],
                    ],
                ],
            ],
            [
                'name' => 'create_purchase',
                'description' => 'Prepare a purchase-order draft. Nothing is saved until the user presses the confirmation button in Telegram, and stock NEVER changes when a purchase is created — stock increases only when the purchase is received through the normal workflow. Requires an existing workspace supplier and one or more items with product, quantity and buying price; request missing details instead of guessing.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'supplier_id' => ['type' => 'integer'],
                        'supplier_name' => ['type' => 'string', 'description' => 'Supplier name as the user said it'],
                        'warehouse_id' => ['type' => 'integer', 'description' => 'Receiving warehouse (defaults to the workspace default warehouse)'],
                        'items' => [
                            'type' => 'array',
                            'description' => 'Purchase lines',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'product_id' => ['type' => 'integer'],
                                    'product_query' => ['type' => 'string', 'description' => 'Product name, SKU or barcode'],
                                    'quantity' => ['type' => 'integer', 'description' => 'Units, at least 1'],
                                    'unit_cost' => ['type' => 'number', 'description' => 'Buying price per unit in workspace currency'],
                                ],
                            ],
                        ],
                        'notes' => ['type' => 'string'],
                        'expected_at' => ['type' => 'string', 'description' => 'Expected delivery date (YYYY-MM-DD)'],
                    ],
                ],
            ],
            [
                'name' => 'list_warehouses',
                'description' => 'Read warehouses of the active workspace with their active/inactive status. Supports filtering to only active or only inactive rows. Read-only; never changes warehouse status.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['active', 'inactive', 'all'], 'description' => 'Default all'],
                        'limit' => ['type' => 'integer', 'description' => 'Rows per page, 1-50 (default 50)'],
                        'page' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'list_categories',
                'description' => 'Read product categories of the active workspace, including description, status and the number of products in each category. Supports search and pagination. Read-only.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string', 'description' => 'Category name, partial match'],
                        'limit' => ['type' => 'integer', 'description' => 'Rows per page, 1-50 (default 20)'],
                        'page' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'get_category',
                'description' => 'Read one product category of the active workspace by ID or by exact name, with description, status and product count.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'category_id' => ['type' => 'integer'],
                        'name' => ['type' => 'string', 'description' => 'Exact category name'],
                    ],
                ],
            ],
            [
                'name' => 'create_category',
                'description' => 'Prepare a product-category draft. Nothing is saved until the user presses the confirmation button in Telegram. Rejects a name that already exists in this workspace; ask the user for the name if it is missing.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'list_brands',
                'description' => 'Read product brands of the active workspace, including description, status and product count. Supports search and pagination. Read-only.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string', 'description' => 'Brand name, partial match'],
                        'limit' => ['type' => 'integer', 'description' => 'Rows per page, 1-50 (default 20)'],
                        'page' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'name' => 'create_brand',
                'description' => 'Prepare a product-brand draft. Nothing is saved until the user presses the confirmation button in Telegram. Rejects a name that already exists in this workspace; ask the user for the name if it is missing.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'add_stock',
                'description' => 'Prepare a stock-addition draft (the official inventory adjustment-in flow). Nothing changes until the user presses the confirmation button in Telegram. Requires an inventory-tracked product, a positive quantity, an active warehouse and a reason; ask for anything missing. Never treat a question or a simulation as a stock change.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer'],
                        'product_query' => ['type' => 'string', 'description' => 'Product name, SKU or barcode'],
                        'quantity' => ['type' => 'integer', 'description' => 'Units to add, greater than 0'],
                        'warehouse_id' => ['type' => 'integer'],
                        'warehouse_name' => ['type' => 'string', 'description' => 'Warehouse name as the user said it'],
                        'reason' => ['type' => 'string', 'description' => 'Reason for the stock movement, e.g. opening stock, restock, stock count'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['quantity'],
                ],
            ],
            [
                'name' => 'list_team',
                'description' => 'Read the members of the active workspace with their role, email and membership status (active or invitation pending). Never returns credentials or tokens. Read-only; never adds or removes members.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass,
                ],
            ],
            [
                'name' => 'get_subscription_info',
                'description' => 'Read the subscription plan of the active workspace: plan name, status, start/end dates and remaining validity. Free and permanent plans report an endless validity instead of an invented expiry date.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass,
                ],
            ],
        ];
    }

    public function execute(string $name, array $arguments, AiChannelLink $link): AiToolResult
    {
        $startedAt = hrtime(true);
        try {
            $tenant = $this->context->tenant();
            $user = $this->context->user();
            abort_unless(
                $tenant
                    && $user
                    && (int) $tenant->id === (int) $link->tenant_id
                    && (int) $user->id === (int) $link->user_id
                    && $this->context->role() !== null,
                403,
                'The linked workspace membership is not active.',
            );

            $result = match ($name) {
                'get_workspace_info' => $this->workspaceInfo(),
                'search_products' => $this->searchProducts($arguments),
                'get_stock' => $this->getStock($arguments),
                'get_sales_summary' => $this->salesSummary($arguments),
                'search_customers' => $this->searchCustomers($arguments),
                'draft_sale' => $this->draftSale($arguments, $link),
                'list_expenses' => $this->listExpenses($arguments),
                'get_expense' => $this->getExpense($arguments),
                'create_expense' => $this->createExpense($arguments, $link),
                'list_purchases' => $this->listPurchases($arguments),
                'get_purchase' => $this->getPurchase($arguments),
                'create_purchase' => $this->createPurchase($arguments, $link),
                'list_warehouses' => $this->listWarehouses($arguments),
                'list_categories' => $this->listCategories($arguments),
                'get_category' => $this->getCategory($arguments),
                'create_category' => $this->createCategory($arguments, $link),
                'list_brands' => $this->listBrands($arguments),
                'create_brand' => $this->createBrand($arguments, $link),
                'add_stock' => $this->addStock($arguments, $link),
                'list_team' => $this->listTeam(),
                'get_subscription_info' => $this->subscriptionInfo(),
                default => null,
            };
            if ($result === null) {
                Log::warning('ai.assistant.tool_rejected', [
                    'request_id' => request()->attributes->get('ai_request_id'),
                    'tool' => $name,
                    'reason' => 'unknown_tool',
                    'tenant_id' => $link->tenant_id,
                ]);

                return new AiToolResult(json_encode([
                    'status' => 'unsupported_tool',
                    'message' => __('That action is not available.'),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            }

            return $result;
        } catch (ValidationException) {
            Log::warning('ai.assistant.tool_rejected', [
                'request_id' => request()->attributes->get('ai_request_id'),
                'tool' => $name,
                'reason' => 'invalid_arguments',
                'tenant_id' => $link->tenant_id,
                'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            ]);

            return new AiToolResult(json_encode([
                'status' => 'invalid_arguments',
                'message' => __('The request details were invalid. Ask the user to provide valid values.'),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
    }

    private function workspaceInfo(): AiToolResult
    {
        $tenant = $this->context->tenant();

        return new AiToolResult(json_encode([
            'status' => 'workspace_found',
            'name' => $tenant->name,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function searchProducts(array $arguments): AiToolResult
    {
        $this->authorization->authorize('products.view');
        $data = Validator::make($arguments, ['query' => ['required', 'string', 'min:1', 'max:100']])->validate();
        $query = trim($data['query']);
        if ($query === '') {
            throw ValidationException::withMessages(['query' => __('The search term must contain non-whitespace characters.')]);
        }
        $products = Product::query()->where('is_active', true)->search($query)
            ->orderBy('name')->limit(10)->get(['id', 'name', 'sku', 'barcode', 'selling_price']);

        return new AiToolResult($products->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'price' => Money::format($product->selling_price),
        ])->toJson());
    }

    private function getStock(array $arguments): AiToolResult
    {
        $this->authorization->authorize('inventory.view');
        $data = Validator::make($arguments, [
            'product_id' => ['nullable', 'integer', 'min:1'],
            'query' => ['nullable', 'string', 'min:1', 'max:100'],
        ])->validate();
        $productQuery = Product::query()->where('is_active', true);
        if (! empty($data['product_id'])) {
            $product = $productQuery->find($data['product_id']);
            if (! $product) {
                return new AiToolResult(json_encode([
                    'status' => 'product_not_found',
                    'message' => __('No active product was found in this workspace.'),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            }
        } elseif (trim((string) ($data['query'] ?? '')) !== '') {
            $query = trim($data['query']);
            $matches = $productQuery->search($query)
                ->orderBy('name')->limit(10)->get(['id', 'name', 'sku', 'barcode']);

            if ($matches->isEmpty()) {
                return new AiToolResult(json_encode([
                    'status' => 'product_not_found',
                    'query' => $query,
                    'message' => __('No active product matched that name, SKU, or barcode.'),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            }
            if ($matches->count() > 1) {
                return new AiToolResult(json_encode([
                    'status' => 'multiple_products_found',
                    'products' => $matches->map(fn (Product $match) => [
                        'id' => $match->id,
                        'name' => $match->name,
                        'sku' => $match->sku,
                        'barcode' => $match->barcode,
                    ])->values(),
                    'message' => __('Several products matched. Ask the user to choose one.'),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            }

            $product = $matches->first();
        } else {
            return new AiToolResult(json_encode([
                'status' => 'product_required',
                'message' => __('Which product would you like to check? Please provide its name, SKU, or barcode.'),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }

        $balances = StockBalance::query()->where('product_id', $product->id)->with('warehouse:id,name')
            ->get(['warehouse_id', 'quantity']);

        return new AiToolResult(json_encode([
            'status' => 'stock_found',
            'product' => $product->name,
            'stock_by_warehouse' => $balances->map(fn (StockBalance $balance) => [
                'warehouse' => $balance->warehouse?->name,
                'quantity' => $balance->quantity,
            ])->values(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function salesSummary(array $arguments): AiToolResult
    {
        $this->authorization->authorize('sales.view');
        $data = Validator::make($arguments, ['days' => ['required', 'integer', 'min:1', 'max:90']])->validate();
        $from = now()->subDays($data['days'] - 1)->startOfDay();
        $sales = Sale::query()->revenue()->where('sold_at', '>=', $from);

        return new AiToolResult(collect([
            'period_days' => $data['days'],
            'orders' => (clone $sales)->count(),
            'revenue' => Money::format((int) (clone $sales)->sum('total')),
        ])->toJson());
    }

    private function searchCustomers(array $arguments): AiToolResult
    {
        $this->authorization->authorize('customers.view');
        $data = Validator::make($arguments, ['query' => ['required', 'string', 'min:1', 'max:100']])->validate();
        $query = trim($data['query']);
        if ($query === '') {
            throw ValidationException::withMessages(['query' => __('The search term must contain non-whitespace characters.')]);
        }
        $needle = '%'.mb_strtolower($query).'%';
        $customers = Customer::query()->where('is_active', true)
            ->where(fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(phone) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(email) LIKE ?', [$needle]))
            ->orderBy('name')->limit(10)
            ->get(['id', 'name', 'phone', 'email', 'address', 'credit_limit', 'notes']);

        return new AiToolResult(json_encode([
                'status' => $customers->isEmpty() ? 'customer_not_found' : 'customers_found',
                'count' => $customers->count(),
                'customers' => $customers->map(fn (Customer $customer) => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $this->safeText($customer->phone),
                    'email' => $this->safeText($customer->email),
                    'address' => $this->safeText($customer->address, 300),
                    // credit_limit is a nullable cents column: null means "not set" and is
                    // reported as "-", while a stored 0 is a real zero ("Rp 0") — the same
                    // distinction the website makes with Money::format().
                    'credit_limit' => is_null($customer->credit_limit)
                        ? '-'
                        : Money::format($customer->credit_limit),
                    'notes' => $this->safeText($customer->notes, 300),
                ])->values(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function draftSale(array $arguments, AiChannelLink $link): AiToolResult
    {
        $this->authorization->authorize('sales.create');
        $data = Validator::make($arguments, [
            'items' => ['nullable', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'product_query' => ['nullable', 'string', 'min:1', 'max:100'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'customer_query' => ['nullable', 'string', 'min:1', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'payment_method' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('business.sales.payment_methods', [])))],
            'payment_amount' => ['nullable', 'numeric', 'min:0'],
        ])->validate();

        foreach (['product_query', 'customer_query', 'customer_phone'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = trim($data[$field]);
                if ($data[$field] === '') {
                    $data[$field] = null;
                }
            }
        }

        if (! empty($data['customer_id']) || ! empty($data['customer_query']) || ! empty($data['customer_phone'])) {
            $this->authorization->authorize('customers.view');
        }

        if (empty($data['items'])) {
            if (empty($data['product_query'])) {
                return $this->followUp('missing_product', __('Which product would you like to add, and how many?'));
            }
            if (empty($data['quantity'])) {
                return $this->followUp('missing_quantity', __('How many units of :product should I add?', [
                    'product' => $data['product_query'],
                ]));
            }

            $matches = Product::query()->where('is_active', true)->search($data['product_query'])
                ->orderBy('name')->limit(10)->get(['id', 'name', 'sku', 'barcode']);
            if ($matches->isEmpty()) {
                return $this->followUp('product_not_found', __('I could not find an active product matching :query. Please check the name, SKU, or barcode.', [
                    'query' => $data['product_query'],
                ]));
            }
            if ($matches->count() > 1) {
                return $this->followUp('multiple_products_found', __('More than one product matched. Which one should I use?'), [
                    'products' => $matches->map(fn (Product $product) => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'barcode' => $product->barcode,
                    ])->values(),
                ]);
            }

            $data['items'] = [[
                'product_id' => $matches->first()->id,
                'quantity' => $data['quantity'],
            ]];
        }

        if (! empty($data['customer_query']) && empty($data['customer_id'])) {
            $needle = '%'.mb_strtolower(trim($data['customer_query'])).'%';
            $customers = Customer::query()->where('is_active', true)
                ->where(fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$needle]))
                ->orderBy('name')->limit(10)->get(['id', 'name', 'phone']);
            if ($customers->isEmpty()) {
                return $this->followUp('customer_not_found', __('I could not find an existing customer named or matching :query. You can choose walk-in, or create the customer in the Customers page first.', [
                    'query' => $data['customer_query'],
                ]));
            }
            if ($customers->count() > 1) {
                return $this->followUp('multiple_customers_found', __('More than one customer matched. Which one should I use?'), [
                    'customers' => $customers->map(fn (Customer $customer) => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'phone' => $customer->phone,
                    ])->values(),
                ]);
            }

            $customer = $customers->first();
            if (! empty($data['customer_phone']) && $this->normalizePhone($customer->phone) !== $this->normalizePhone($data['customer_phone'])) {
                return $this->followUp('customer_phone_mismatch', __('The phone number provided does not match :name. Please confirm the customer or choose walk-in.', [
                    'name' => $customer->name,
                ]));
            }
            $data['customer_id'] = $customer->id;
        }

        if (! empty($data['customer_phone']) && empty($data['customer_id']) && empty($data['customer_query'])) {
            $phoneNeedle = '%'.mb_strtolower(trim($data['customer_phone'])).'%';
            $customers = Customer::query()->where('is_active', true)
                ->whereRaw('LOWER(phone) LIKE ?', [$phoneNeedle])
                ->orderBy('name')->limit(10)->get(['id', 'name', 'phone']);
            if ($customers->isEmpty()) {
                return $this->followUp('customer_not_found', __('I could not find an existing customer with that phone number. You can choose walk-in, or create the customer in the Customers page first.'));
            }
            if ($customers->count() > 1) {
                return $this->followUp('multiple_customers_found', __('More than one customer matched that phone number. Which one should I use?'), [
                    'customers' => $customers->map(fn (Customer $customer) => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'phone' => $customer->phone,
                    ])->values(),
                ]);
            }
            $data['customer_id'] = $customers->first()->id;
        }

        $tenant = $this->context->tenant();
        $products = Product::query()->where('is_active', true)
            ->whereIn('id', collect($data['items'])->pluck('product_id')->unique())->get()->keyBy('id');
        if ($products->count() !== collect($data['items'])->pluck('product_id')->unique()->count()) {
            throw ValidationException::withMessages(['items' => __('One or more products are unavailable.')]);
        }

        $warehouseId = $tenant->settings['default_warehouse_id'] ?? null;
        $warehouse = $warehouseId
            ? Warehouse::query()->active()->find($warehouseId)
            : null;
        $warehouse ??= Warehouse::query()->active()->orderBy('id')->first();
        if (! $warehouse) {
            return new AiToolResult(__('No active warehouse is configured. Ask the workspace administrator to configure one.'));
        }

        $customer = null;
        if (! empty($data['customer_id'])) {
            $customer = Customer::query()->where('is_active', true)->find($data['customer_id']);
            if (! $customer) {
                throw ValidationException::withMessages(['customer_id' => __('The selected customer is unavailable.')]);
            }
        }

        if (! config('business.sales.allow_negative_stock')) {
            $requested = collect($data['items'])->groupBy('product_id')
                ->map(fn ($items) => $items->sum('quantity'));
            foreach ($requested as $productId => $quantity) {
                $product = $products->get((int) $productId);
                if (! $product->track_inventory) {
                    continue;
                }
                $stock = (int) (StockBalance::query()->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $product->id)->value('quantity') ?? 0);
                if ($stock < $quantity) {
                    return new AiToolResult(__('There is not enough stock for :product. Available: :stock.', [
                        'product' => $product->name,
                        'stock' => $stock,
                    ]));
                }
            }
        }

        $lines = [];
        $items = [];
        foreach ($data['items'] as $item) {
            $product = $products->get((int) $item['product_id']);
            $quantity = (int) $item['quantity'];
            $lines[] = [
                'quantity' => $quantity,
                'unit_price' => (int) $product->selling_price,
                'discount_type' => CalculateSaleTotals::TYPE_FIXED,
                'discount_value' => 0,
            ];
            $items[] = [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => number_format($product->selling_price / 100, 2, '.', ''),
            ];
        }

        $taxPercent = (int) ($tenant->settings['tax_percent'] ?? config('business.sales.tax_percent', 0));
        $totals = $this->calculator->calculate($lines, ['tax_percent' => $taxPercent]);
        $action = AiPendingAction::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'user_id' => $link->user_id,
            'channel_link_id' => $link->id,
            'action' => 'record_sale',
            'payload' => [
                'items' => collect($items)->map(fn (array $item) => [
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ]),
                'customer_id' => $customer?->id,
                'warehouse_id' => $warehouse->id,
                'tax_percent' => $taxPercent,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'payment_amount' => $data['payment_amount'] ?? 0,
            ],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);

        $itemSummary = collect($items)->map(fn (array $item) => sprintf(
            '%s × %d',
            $products->get($item['product_id'])->name,
            $item['quantity'],
        ))->implode(', ');
        $customerLabel = $customer ? __('for customer :name', ['name' => $customer->name]) : '';

        return new AiToolResult(
            __('ai.sale_draft', [
                'items' => $itemSummary,
                'customer' => $customerLabel,
                'total' => Money::format($totals['total']),
                'payment' => Money::format(Money::centsFromDisplay($data['payment_amount'] ?? 0)),
            ]),
            $action,
        );
    }

    /** --------------------------------- helpers --------------------------------- */

    private function jsonResult(array $payload): AiToolResult
    {
        return new AiToolResult(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Collapse whitespace and cap the length. The Telegram formatter escapes dynamic
     * text itself, so a note with newlines or markup cannot break the message layout
     * once it has been flattened to a single line.
     */
    private function safeText(?string $value, int $max = 200, string $fallback = '-'): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return $fallback;
        }
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    /** @return array{0: int, 1: int, 2: int} [limit, offset, page] */
    private function pageWindow(array $data, int $defaultLimit = 10): array
    {
        $limit = (int) ($data['limit'] ?? $defaultLimit);
        $page = max(1, (int) ($data['page'] ?? 1));

        return [$limit, ($page - 1) * $limit, $page];
    }

    /** Comma-separated option list for "pick one of these" follow-ups. */
    private function optionList(iterable $values, int $max = 20): string
    {
        $items = collect($values)->filter()->unique()->values();
        $visible = $items->take($max)->implode(', ');

        if ($visible === '') {
            return '-';
        }

        return $items->count() > $max ? $visible.', …' : $visible;
    }

    /** Status label shared by warehouses, categories and brands. */
    private function activeLabel(bool $active): string
    {
        return $active ? __('Active') : __('Inactive');
    }

    /** --------------------------------- expenses --------------------------------- */

    private function listExpenses(array $arguments): AiToolResult
    {
        $this->authorization->authorize('expenses.view');
        $data = Validator::make($arguments, [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'category' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ])->validate();

        [$limit, $offset, $page] = $this->pageWindow($data);
        $filtered = false;

        $query = Expense::query()->with('category:id,name');
        if (isset($data['from'])) {
            $query->whereDate('expense_date', '>=', $data['from']);
            $filtered = true;
        }
        if (isset($data['to'])) {
            $query->whereDate('expense_date', '<=', $data['to']);
            $filtered = true;
        }
        if (isset($data['category_id'])) {
            $query->where('category_id', $data['category_id']);
            $filtered = true;
        }
        $categoryTerm = trim((string) ($data['category'] ?? ''));
        if ($categoryTerm !== '') {
            $filtered = true;
            $needle = '%'.mb_strtolower($categoryTerm).'%';
            $query->whereHas('category', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$needle]));
        }

        $totalMatching = (clone $query)->count();
        if ($totalMatching === 0) {
            $payload = [
                'status' => 'expenses_not_found',
                'total_matching' => 0,
                'total_amount' => Money::format(0),
            ];
            if (! $filtered) {
                $payload['message'] = __('There are no expenses in this workspace yet.');
            }

            return $this->jsonResult($payload);
        }

        $totalAmount = (int) (clone $query)->sum('amount');
        $expenses = (clone $query)->orderByDesc('expense_date')->orderByDesc('id')
            ->skip($offset)->take($limit)->get();

        return $this->jsonResult([
            'status' => 'expenses_found',
            'page' => $page,
            'per_page' => $limit,
            'total_matching' => $totalMatching,
            'more' => $offset + $expenses->count() < $totalMatching,
            'total_amount' => Money::format($totalAmount),
            'expenses' => $expenses->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'date' => $expense->expense_date?->toDateString() ?? '-',
                'description' => $expense->description,
                'category' => $expense->category?->name ?? '-',
                'amount' => Money::format($expense->amount),
                'payment_method' => $expense->payment_method ?: '-',
                'notes' => $this->safeText($expense->notes),
            ])->values()->all(),
        ]);
    }

    private function getExpense(array $arguments): AiToolResult
    {
        $this->authorization->authorize('expenses.view');
        $data = Validator::make($arguments, ['expense_id' => ['required', 'integer', 'min:1']])->validate();

        $expense = Expense::query()->with(['category:id,name', 'createdBy:id,name'])->find($data['expense_id']);
        if (! $expense) {
            return $this->jsonResult([
                'status' => 'expense_not_found',
                'message' => __('No expense matched that ID in this workspace.'),
            ]);
        }

        return $this->jsonResult([
            'status' => 'expense_found',
            'expense' => [
                'id' => $expense->id,
                'date' => $expense->expense_date?->toDateString() ?? '-',
                'description' => $expense->description,
                'category' => $expense->category?->name ?? '-',
                'amount' => Money::format($expense->amount),
                'payment_method' => $expense->payment_method ?: '-',
                'notes' => $this->safeText($expense->notes, 1000),
                'created_by' => $expense->createdBy?->name ?? '-',
            ],
        ]);
    }

    private function createExpense(array $arguments, AiChannelLink $link): AiToolResult
    {
        $this->authorization->authorize('expenses.create');
        $data = Validator::make($arguments, [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000000000'],
            'description' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'category_name' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'in:cash,bank,transfer,qris,other'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            return $this->followUp('missing_expense_description', __('What was the expense for? A short description is required.'));
        }

        [$category, $problem] = $this->matchExpenseCategory($data);
        if ($problem !== null) {
            return $problem;
        }

        $date = isset($data['date'])
            ? Carbon::parse($data['date'])->toDateString()
            : now()->toDateString();
        $amountCents = Money::centsFromDisplay($data['amount']);
        $paymentMethod = $data['payment_method'] ?? config('business.sales.default_payment_method', 'cash');
        $notes = trim((string) ($data['notes'] ?? ''));
        $notes = $notes === '' ? null : $notes;

        $action = AiPendingAction::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->context->tenant()->id,
            'user_id' => $link->user_id,
            'channel_link_id' => $link->id,
            'action' => 'record_expense',
            'payload' => [
                'amount_cents' => $amountCents,
                'description' => $description,
                'expense_date' => $date,
                'category_id' => $category?->id,
                'payment_method' => $paymentMethod,
                'notes' => $notes,
            ],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);

        return new AiToolResult(__('ai.expense_draft', [
            'description' => $description,
            'amount' => Money::format($amountCents),
            'date' => $date,
            'category' => $category?->name ?? '-',
            'payment' => $paymentMethod,
            'notes' => $this->safeText($notes),
        ]), $action);
    }

    /**
     * Resolve an expense category the user actually named. Returns [?ExpenseCategory, ?AiToolResult]:
     * the second slot carries the follow-up question when the name is missing, unknown or ambiguous,
     * so the assistant can never attach an invented category.
     *
     * @return array{0: ?ExpenseCategory, 1: ?AiToolResult}
     */
    private function matchExpenseCategory(array $data): array
    {
        if (isset($data['category_id'])) {
            $category = ExpenseCategory::query()->where('is_active', true)->find($data['category_id']);

            return $category
                ? [$category, null]
                : [null, $this->followUp('expense_category_not_found', __('No active expense category matched that ID.'))];
        }

        $term = trim((string) ($data['category_name'] ?? ''));
        if ($term === '') {
            return [null, null];
        }

        $exact = ExpenseCategory::query()->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($term)])
            ->orderBy('name')->limit(2)->get();
        $matches = $exact->count() === 1
            ? $exact
            : ExpenseCategory::query()->where('is_active', true)
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%'])
                ->orderBy('name')->limit(10)->get();

        if ($matches->isEmpty()) {
            return [null, $this->followUp('expense_category_not_found', __('No expense category matched ":name". Existing categories: :categories', [
                'name' => $term,
                'categories' => $this->optionList(
                    ExpenseCategory::query()->where('is_active', true)->orderBy('name')->pluck('name')
                ),
            ]))];
        }

        if ($matches->count() > 1) {
            return [null, $this->followUp('expense_category_ambiguous', __('More than one expense category matched: :categories. Specify the exact name or the category ID.', [
                'categories' => $this->optionList($matches->map(fn (ExpenseCategory $category) => $category->name.' ('.$category->id.')')),
            ]))];
        }

        return [$matches->first(), null];
    }

    /** --------------------------------- purchases --------------------------------- */

    private function listPurchases(array $arguments): AiToolResult
    {
        $this->authorization->authorize('purchases.view');
        $data = Validator::make($arguments, [
            'status' => ['nullable', 'string', 'in:'.implode(',', Purchase::STATUSES)],
            'supplier' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ])->validate();

        [$limit, $offset, $page] = $this->pageWindow($data);
        $filtered = false;

        $query = Purchase::query()->with(['supplier:id,name']);
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
            $filtered = true;
        }
        if (isset($data['supplier_id'])) {
            $query->where('supplier_id', $data['supplier_id']);
            $filtered = true;
        }
        $supplierTerm = trim((string) ($data['supplier'] ?? ''));
        if ($supplierTerm !== '') {
            $filtered = true;
            $needle = '%'.mb_strtolower($supplierTerm).'%';
            $query->whereHas('supplier', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$needle]));
        }
        if (isset($data['from'])) {
            $query->whereDate('ordered_at', '>=', $data['from']);
            $filtered = true;
        }
        if (isset($data['to'])) {
            $query->whereDate('ordered_at', '<=', $data['to']);
            $filtered = true;
        }

        $totalMatching = (clone $query)->count();
        if ($totalMatching === 0) {
            $payload = [
                'status' => 'purchases_not_found',
                'total_matching' => 0,
                'total_amount' => Money::format(0),
            ];
            if (! $filtered) {
                $payload['message'] = __('There are no purchases in this workspace yet.');
            }

            return $this->jsonResult($payload);
        }

        $totalAmount = (int) (clone $query)->sum('total');
        $purchases = (clone $query)->withCount('items')->orderByDesc('ordered_at')
            ->skip($offset)->take($limit)->get();

        return $this->jsonResult([
            'status' => 'purchases_found',
            'page' => $page,
            'per_page' => $limit,
            'total_matching' => $totalMatching,
            'more' => $offset + $purchases->count() < $totalMatching,
            'total_amount' => Money::format($totalAmount),
            'purchases' => $purchases->map(fn (Purchase $purchase) => [
                'id' => $purchase->id,
                'reference' => $purchase->invoice_number,
                'date' => $purchase->ordered_at?->toDateString() ?? '-',
                'supplier' => $purchase->supplier?->name ?? '-',
                'status' => $purchase->status,
                'items_count' => (int) ($purchase->items_count ?? 0),
                'total' => Money::format($purchase->total),
            ])->values()->all(),
        ]);
    }

    private function getPurchase(array $arguments): AiToolResult
    {
        $this->authorization->authorize('purchases.view');
        $data = Validator::make($arguments, [
            'purchase_id' => ['nullable', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:40'],
        ])->validate();

        $reference = trim((string) ($data['reference'] ?? ''));
        if (! isset($data['purchase_id']) && $reference === '') {
            return $this->followUp('missing_purchase_reference', __('Specify a purchase ID or reference number.'));
        }

        $purchase = isset($data['purchase_id'])
            ? Purchase::query()->find($data['purchase_id'])
            : Purchase::query()->whereRaw('LOWER(invoice_number) = ?', [mb_strtolower($reference)])->first();

        if (! $purchase) {
            return $this->jsonResult([
                'status' => 'purchase_not_found',
                'message' => __('No purchase matched that reference in this workspace.'),
            ]);
        }

        $purchase->load(['supplier:id,name', 'warehouse:id,name', 'items.product:id,name,unit']);

        return $this->jsonResult([
            'status' => 'purchase_found',
            'purchase' => [
                'id' => $purchase->id,
                'reference' => $purchase->invoice_number,
                'date' => $purchase->ordered_at?->toDateString() ?? '-',
                'expected_at' => $purchase->expected_at?->toDateString() ?? '-',
                'received_at' => $purchase->received_at?->toDateTimeString() ?? '-',
                'supplier' => $purchase->supplier?->name ?? '-',
                'warehouse' => $purchase->warehouse?->name ?? '-',
                'status' => $purchase->status,
                'items' => $purchase->items->map(fn (PurchaseItem $item) => [
                    'product_id' => $item->product_id,
                    'name' => $item->product_name,
                    'sku' => $this->safeText($item->sku),
                    'quantity' => (int) $item->quantity,
                    'unit' => $item->unit ?: 'pcs',
                    'unit_cost' => Money::format($item->unit_cost),
                    'subtotal' => Money::format($item->subtotal),
                ])->values()->all(),
                'subtotal' => Money::format($purchase->subtotal),
                'discount' => Money::format($purchase->discount),
                'tax' => Money::format($purchase->tax),
                'shipping' => Money::format($purchase->shipping),
                'total' => Money::format($purchase->total),
                'notes' => $this->safeText($purchase->notes, 1000),
            ],
        ]);
    }

    private function createPurchase(array $arguments, AiChannelLink $link): AiToolResult
    {
        $this->authorization->authorize('purchases.create');
        $data = Validator::make($arguments, [
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'supplier_name' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.product_id' => ['nullable', 'integer', 'min:1'],
            'items.*.product_query' => ['nullable', 'string', 'max:100'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:1000000000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'expected_at' => ['nullable', 'date'],
        ])->validate();

        [$supplier, $problem] = $this->matchSupplier($data);
        if ($problem !== null) {
            return $problem;
        }

        [$warehouse, $problem] = $this->matchWarehouse($data['warehouse_id'] ?? null, null);
        if ($problem !== null) {
            return $problem;
        }

        $rawItems = $data['items'] ?? [];
        if ($rawItems === []) {
            return $this->followUp('missing_purchase_items', __('A purchase needs at least one item. Which products, quantities and buying prices should it include?'));
        }

        $missingMessage = __('A purchase needs at least one item. Which products, quantities and buying prices should it include?');
        $items = [];
        foreach ($rawItems as $item) {
            [$product, $problem] = $this->matchProduct($item['product_id'] ?? null, $item['product_query'] ?? null, $missingMessage);
            if ($problem !== null) {
                return $problem;
            }
            if (! isset($item['unit_cost']) || $item['unit_cost'] === '') {
                return $this->followUp('unit_cost_required', __('The buying price (unit cost) is required for :product.', ['product' => $product->name]));
            }
            $items[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => (int) $item['quantity'],
                'unit_cost' => $item['unit_cost'],
            ];
        }

        if (collect($items)->pluck('product_id')->duplicates()->isNotEmpty()) {
            return $this->followUp('duplicate_purchase_items', __('Each product may appear only once in a purchase.'));
        }

        $normalized = [
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'expected_at' => $data['expected_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'discount' => 0,
            'tax' => 0,
            'shipping' => 0,
            'items' => collect($items)->map(fn (array $item) => [
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_cost' => $item['unit_cost'],
            ])->all(),
        ];
        // Same rules and server-side totals the website form and the public API share.
        Validator::make($normalized, $this->purchaseOrders->rules($this->context->tenant()))->validate();
        $totals = $this->purchaseOrders->totals($normalized);

        $action = AiPendingAction::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->context->tenant()->id,
            'user_id' => $link->user_id,
            'channel_link_id' => $link->id,
            'action' => 'record_purchase',
            'payload' => [
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'expected_at' => $data['expected_at'] ?? null,
                'notes' => $normalized['notes'],
                'items' => $normalized['items'],
            ],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);

        return new AiToolResult(__('ai.purchase_draft', [
            'supplier' => $supplier->name,
            'warehouse' => $warehouse->name,
            'expected' => $data['expected_at'] ?? '-',
            'items' => collect($items)->map(fn (array $item, int $index) => $this->purchaseLine(
                $index + 1,
                $item['product_name'],
                $item['quantity'],
                Money::centsFromDisplay($item['unit_cost']),
            ))->implode("\n"),
            'subtotal' => Money::format($totals['subtotal']),
            'total' => Money::format($totals['total']),
            'notes' => $this->safeText($normalized['notes']),
        ]), $action);
    }

    /** One numbered item line: "1. Pupuk Organik — 10 × Rp 20.000 = Rp 200.000". */
    private function purchaseLine(int $index, string $name, int $quantity, int $unitCostCents): string
    {
        return sprintf(
            '%d. %s — %d × %s = %s',
            $index,
            $name,
            $quantity,
            Money::format($unitCostCents),
            Money::format($quantity * $unitCostCents),
        );
    }

    /**
     * Resolve a supplier by id or by the name the user typed.
     *
     * @return array{0: ?Supplier, 1: ?AiToolResult}
     */
    private function matchSupplier(array $data): array
    {
        if (isset($data['supplier_id'])) {
            $supplier = Supplier::query()->active()->find($data['supplier_id']);

            return $supplier
                ? [$supplier, null]
                : [null, $this->followUp('supplier_not_found', __('No active supplier matched that ID.'))];
        }

        $term = trim((string) ($data['supplier_name'] ?? ''));
        if ($term === '') {
            return [null, $this->followUp('supplier_required', __('A supplier is required. Which supplier is this purchase from?'))];
        }

        $exact = Supplier::query()->active()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($term)])
            ->orderBy('name')->limit(2)->get();
        $matches = $exact->count() === 1
            ? $exact
            : Supplier::query()->active()
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%'])
                ->orderBy('name')->limit(10)->get();

        if ($matches->isEmpty()) {
            return [null, $this->followUp('supplier_not_found', __('No supplier matched ":name". Existing active suppliers: :suppliers', [
                'name' => $term,
                'suppliers' => $this->optionList(Supplier::query()->active()->orderBy('name')->pluck('name')),
            ]))];
        }

        if ($matches->count() > 1) {
            return [null, $this->followUp('supplier_ambiguous', __('More than one supplier matched: :suppliers. Specify the exact name or the supplier ID.', [
                'suppliers' => $this->optionList($matches->map(fn (Supplier $supplier) => $supplier->name.' ('.$supplier->id.')')),
            ]))];
        }

        return [$matches->first(), null];
    }

    /**
     * Resolve an active product by id or by name/SKU/barcode through the ONE search
     * scope the whole application uses (Product::scopeSearch).
     *
     * @return array{0: ?Product, 1: ?AiToolResult}
     */
    private function matchProduct(?int $productId, ?string $productQuery, string $missingMessage): array
    {
        if ($productId !== null) {
            $product = Product::query()->where('is_active', true)->find($productId);

            return $product
                ? [$product, null]
                : [null, $this->followUp('product_not_found', __('No active product matched that ID.'))];
        }

        $term = trim((string) $productQuery);
        if ($term === '') {
            return [null, $this->followUp('missing_product', $missingMessage)];
        }

        $matches = Product::query()->where('is_active', true)->search($term)->limit(10)->get();

        if ($matches->isEmpty()) {
            return [null, $this->followUp('product_not_found', __('No product matched ":name". Existing products: :products', [
                'name' => $term,
                'products' => $this->optionList(Product::query()->where('is_active', true)->orderBy('name')->limit(20)->get()->map(
                    fn (Product $product) => $product->name.($product->sku ? ' ('.$product->sku.')' : '')
                )),
            ]))];
        }

        if ($matches->count() > 1) {
            return [null, $this->followUp('product_ambiguous', __('More than one product matched: :products. Choose one product ID.', [
                'products' => $this->optionList($matches->map(fn (Product $product) => $product->name.' ('.$product->id.')')),
            ]))];
        }

        return [$matches->first(), null];
    }

    /**
     * Resolve the warehouse that receives goods/stock: an explicit active id, else the
     * workspace default, else the only active warehouse. When several active warehouses
     * exist and nothing was specified, the user is asked instead of guessing.
     *
     * @return array{0: ?Warehouse, 1: ?AiToolResult}
     */
    private function matchWarehouse(?int $warehouseId, ?string $warehouseName): array
    {
        if ($warehouseId !== null) {
            $warehouse = Warehouse::query()->active()->find($warehouseId);

            return $warehouse
                ? [$warehouse, null]
                : [null, $this->followUp('warehouse_not_found', __('No active warehouse matched that ID.'))];
        }

        $term = trim((string) $warehouseName);
        if ($term !== '') {
            $exact = Warehouse::query()->active()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($term)])
                ->orderBy('name')->limit(2)->get();
            $matches = $exact->count() === 1
                ? $exact
                : Warehouse::query()->active()
                    ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%'])
                    ->orderBy('name')->limit(10)->get();

            if ($matches->isEmpty()) {
                return [null, $this->followUp('warehouse_not_found', __('No warehouse matched ":name". Existing active warehouses: :warehouses', [
                    'name' => $term,
                    'warehouses' => $this->optionList(Warehouse::query()->active()->orderBy('name')->pluck('name')),
                ]))];
            }
            if ($matches->count() > 1) {
                return [null, $this->followUp('warehouse_ambiguous', __('More than one warehouse matched: :warehouses. Specify the exact name or the warehouse ID.', [
                    'warehouses' => $this->optionList($matches->map(fn (Warehouse $warehouse) => $warehouse->name.' ('.$warehouse->id.')')),
                ]))];
            }

            return [$matches->first(), null];
        }

        $defaultId = (int) ($this->context->tenant()->settings['default_warehouse_id'] ?? 0);
        if ($defaultId > 0) {
            $warehouse = Warehouse::query()->active()->find($defaultId);
            if ($warehouse) {
                return [$warehouse, null];
            }
        }

        $active = Warehouse::query()->active()->orderBy('name')->get();
        if ($active->count() === 1) {
            return [$active->first(), null];
        }
        if ($active->isEmpty()) {
            return [null, $this->followUp('warehouse_missing', __('No active warehouse is configured. Ask the workspace administrator to configure one.'))];
        }

        return [null, $this->followUp('warehouse_choice', __('Which warehouse should receive the stock? Active warehouses: :warehouses', [
            'warehouses' => $this->optionList($active->map(fn (Warehouse $warehouse) => $warehouse->name.' ('.$warehouse->id.')')),
        ]))];
    }

    private function followUp(string $status, string $message, array $data = []): AiToolResult
    {
        return new AiToolResult(json_encode([
            'status' => $status,
            'message' => $message,
            ...$data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), requiresFollowUp: true);
    }

    private function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', $phone ?? '') ?? '';
    }
}

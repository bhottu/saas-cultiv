<?php

namespace App\Services\Ai;

use App\Models\AiChannelLink;
use App\Models\AiPendingAction;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Services\BusinessAuthorization;
use App\Services\CalculateSaleTotals;
use App\Services\Money;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BusinessAiTools
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BusinessAuthorization $authorization,
        private readonly CalculateSaleTotals $calculator,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function definitions(): array
    {
        return [
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
                'description' => 'Read current stock for one active product in the workspace.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['product_id' => ['type' => 'integer']],
                    'required' => ['product_id'],
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
                'description' => 'Search active workspace customers by name or phone.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string']],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'draft_sale',
                'description' => 'Prepare a sale for user confirmation. Use product IDs from search_products; never set prices.',
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
                        'customer_id' => ['type' => 'integer'],
                        'payment_method' => [
                            'type' => 'string',
                            'enum' => array_keys(config('business.sales.payment_methods', [])),
                        ],
                        'payment_amount' => ['type' => 'number', 'description' => 'Amount received in the workspace currency; omit when not yet paid.'],
                    ],
                    'required' => ['items'],
                ],
            ],
        ];
    }

    public function execute(string $name, array $arguments, AiChannelLink $link): AiToolResult
    {
        try {
            return match ($name) {
                'search_products' => $this->searchProducts($arguments),
                'get_stock' => $this->getStock($arguments),
                'get_sales_summary' => $this->salesSummary($arguments),
                'search_customers' => $this->searchCustomers($arguments),
                'draft_sale' => $this->draftSale($arguments, $link),
                default => new AiToolResult(__('That action is not available.')),
            };
        } catch (ValidationException) {
            return new AiToolResult(__('The request details were invalid. Ask the user to provide valid values.'));
        }
    }

    private function searchProducts(array $arguments): AiToolResult
    {
        $this->authorization->authorize('products.view');
        $data = Validator::make($arguments, ['query' => ['required', 'string', 'min:1', 'max:100']])->validate();
        $products = Product::query()->where('is_active', true)->search($data['query'])
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
        $data = Validator::make($arguments, ['product_id' => ['required', 'integer', 'min:1']])->validate();
        $product = Product::query()->where('is_active', true)->findOrFail($data['product_id']);
        $balances = StockBalance::query()->where('product_id', $product->id)->with('warehouse:id,name')
            ->get(['warehouse_id', 'quantity']);

        return new AiToolResult(collect([
            'product' => $product->name,
            'stock_by_warehouse' => $balances->map(fn (StockBalance $balance) => [
                'warehouse' => $balance->warehouse?->name,
                'quantity' => $balance->quantity,
            ])->values(),
        ])->toJson());
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
        $needle = '%'.mb_strtolower(trim($data['query'])).'%';
        $customers = Customer::query()->where('is_active', true)
            ->where(fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(phone) LIKE ?', [$needle]))
            ->orderBy('name')->limit(10)->get(['id', 'name', 'phone']);

        return new AiToolResult($customers->toJson());
    }

    private function draftSale(array $arguments, AiChannelLink $link): AiToolResult
    {
        $this->authorization->authorize('sales.create');
        $data = Validator::make($arguments, [
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('business.sales.payment_methods', [])))],
            'payment_amount' => ['nullable', 'numeric', 'min:0'],
        ])->validate();

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
}

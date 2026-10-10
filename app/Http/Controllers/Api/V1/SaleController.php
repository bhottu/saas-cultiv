<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\CalculateSaleTotals;
use App\Services\Money;
use App\Services\RecordSaleService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaleController extends ApiController
{
    public function index(Request $request)
    {
        $this->auth->authorize('sales.view');

        $query = Sale::query()->with([
            'customer:id,name,phone',
            'warehouse:id,name',
            'createdBy:id,name',
        ])->withCount('items');

        $this->applySearch($query, $request, ['invoice_number']);

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        if ($request->filled('payment_status')) {
            $query->whereIn('payment_status', (array) $request->input('payment_status'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }

        if ($request->filled('sales_channel')) {
            $query->where('sales_channel', $request->string('sales_channel')->toString());
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->string('payment_method')->toString());
        }

        $this->applyDateRange($query, $request);

        $sales = $query->latest('sold_at')->paginate($this->perPage($request))->withQueryString();

        return $this->withMeta(SaleResource::collection($sales), $request);
    }

    public function show(Request $request, int $sale)
    {
        $this->auth->authorize('sales.view');

        $model = Sale::query()
            ->with(['customer:id,name,phone,email', 'warehouse:id,name', 'createdBy:id,name', 'items', 'payments'])
            ->findOrFail($sale);

        return SaleResource::make($model)->additional([
            'currency' => 'IDR',
            'money_unit' => 'cents',
            'payments' => $model->payments->map(fn ($p) => [
                'id' => $p->id,
                'method' => $p->method,
                'amount' => (int) $p->amount,
                'reference' => $p->reference,
                'paid_at' => $p->paid_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Create a sale.
     *
     * This runs the SAME pipeline as the back-office "New Sale" form and the POS
     * terminal — RecordSaleService::record() — so totals, stock validation, stock
     * movements, payment status, invoice numbering, usage limits and the audit log are
     * all produced server-side. Nothing here inserts into `sales` directly.
     *
     * Deliberately NOT accepted from the client:
     *   - `payment_status`  — derived by the service from payment_amount vs. the
     *                         recalculated total, so a request can never claim a sale
     *                         is paid without supplying a matching amount.
     *   - `invoice_number`, `total`, `subtotal`, `change_amount` — recomputed.
     *   - `tenant_id`       — comes from the token's workspace.
     *
     * `client_reference` gives idempotency: a retried POST resolves to the sale that
     * already exists instead of selling (and deducting stock) twice.
     */
    public function store(Request $request, RecordSaleService $sales)
    {
        $this->auth->authorize('sales.create');

        $tenant = $this->tenant($request);
        $tenantId = $tenant->id;

        $owned = fn (string $table) => Rule::exists($table, 'id')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId));

        $validated = $request->validate([
            'customer_id'            => ['nullable', 'integer', $owned('customers')],
            'warehouse_id'           => ['required', 'integer', $owned('warehouses')],
            'sales_channel'          => ['required', Rule::in(array_keys(config('business.sales.channels', [])))],
            // Only the two states a new sale may legitimately start in. Cancelled and
            // refunded are outcomes of later lifecycle actions, never of a create.
            'status'                 => ['nullable', Rule::in([Sale::STATUS_COMPLETED, Sale::STATUS_PENDING])],
            'sold_at'                => ['nullable', 'date'],
            'discount_type'          => ['required', Rule::in(CalculateSaleTotals::DISCOUNT_TYPES)],
            'discount_value'         => ['nullable', 'numeric', 'min:0'],
            'tax_percent'            => ['nullable', 'integer', 'min:0', 'max:100'],
            'shipping'               => ['nullable', 'numeric', 'min:0'],
            'payment_method'         => ['required', Rule::in(array_keys(config('business.sales.payment_methods', [])))],
            'payment_amount'         => ['nullable', 'numeric', 'min:0'],
            'payment_reference'      => ['nullable', 'string', 'max:100'],
            'notes'                  => ['nullable', 'string', 'max:65535'],
            'client_reference'       => ['nullable', 'string', 'max:64'],
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.product_id'     => ['required', 'integer', $owned('products')],
            'items.*.quantity'       => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_price'     => ['required', 'numeric', 'min:0'],
            'items.*.discount_type'  => ['nullable', Rule::in(CalculateSaleTotals::DISCOUNT_TYPES)],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Replay instead of double-selling when a client retries the same request.
        if ($reference = (string) ($validated['client_reference'] ?? '')) {
            if ($existing = Sale::query()->where('client_reference', $reference)->first()) {
                return SaleResource::make($existing)->additional([
                    'replayed' => true,
                    'message' => 'This sale was already recorded.',
                ]);
            }
        }

        try {
            $sale = $sales->record($tenant, $validated, $request->user()?->id);
        } catch (ValidationException $e) {
            // Stock shortfall or a field rule — surfaced as a 422, nothing persisted.
            return response()->json([
                'message' => $e->validator->errors()->first() ?: 'The sale could not be recorded.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message = "Sale {$sale->invoice_number} recorded.";

        if ($sale->change_amount > 0) {
            $message .= ' Change: '.Money::format($sale->change_amount);
        }

        return SaleResource::make($sale)->additional([
            'replayed' => false,
            'message' => $message,
            'currency' => 'IDR',
            'money_unit' => 'cents',
        ])->response()->setStatusCode(201);
    }

    private function applyDateRange($query, Request $request): void
    {
        if ($request->filled('from')) {
            $query->whereDate('sold_at', '>=', $request->string('from')->toString());
        }

        if ($request->filled('to')) {
            $query->whereDate('sold_at', '<=', $request->string('to')->toString());
        }
    }
}
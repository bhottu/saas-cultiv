<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-lg font-semibold leading-tight text-gray-900">{{ $pageTitle }}</h2>
                <p class="truncate text-sm text-gray-500">{{ __('Pick a customer, add products and record the payment.') }}</p>
            </div>

            <a href="{{ route('sales.index') }}" class="shrink-0 text-sm text-gray-600 underline">{{ __('Back to sales') }}</a>
        </div>
    </x-slot>

    @php
        // Amounts are entered in rupiah and stored as cents by the server (App\Services\Money).
        $channelDefault = config('business.sales.default_channel', 'pos');
        $methodDefault = config('business.sales.default_payment_method', 'cash');
    @endphp

    <div class="mx-auto max-w-7xl space-y-6 py-12 sm:px-6 lg:px-8"
         x-data="saleForm(@js([
             'products' => $productPayload,
             'customers' => $customerPayload,
             'selectedCustomerId' => (string) $selectedCustomerId,
             'canCreateCustomer' => (bool) $canCreateCustomer,
             'customerStoreUrl' => route('customers.store'),
             'taxPercent' => $taxPercent,
         ]))">
        @if ($errors->any())
            <div class="rounded-lg bg-red-100 p-3 text-red-800">
                <ul class="list-inside list-disc space-y-1 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('status'))
            @php $status = session('status'); @endphp
            <div class="rounded-lg p-3 {{ ($status['type'] ?? 'success') === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                {{ $status['message'] ?? '' }}
            </div>
        @endif

        <style>
            [x-cloak] { display: none !important; }
        </style>

        <form method="POST" action="{{ $submitUrl }}" class="space-y-6" x-on:submit="submitting = true">
            @csrf
            <input type="hidden" name="client_reference" value="{{ $clientReference }}">

            {{-- Header details --}}
            <div class="grid gap-4 rounded-lg bg-white p-6 shadow md:grid-cols-3">
                <div>
                    <x-input-label for="customer_id" :value="__('Customer *')" />
                    <div class="mt-1 flex gap-2">
                        <select id="customer_id" name="customer_id" x-model="selectedCustomerId" x-on:change="handleCustomerChange()"
                                class="block min-w-0 flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">{{ __('Walk-in Customer') }}</option>
                            <template x-for="customer in customerOptions" :key="customer.id">
                                <option :value="String(customer.id)" x-text="customer.phone ? `${customer.name} · ${customer.phone}` : customer.name"></option>
                            </template>
                            @if ($canCreateCustomer)
                                <option value="__add_customer__">{{ __('+ Add Customer') }}</option>
                            @endif
                        </select>
                        @if ($canCreateCustomer)
                            <button type="button" @click="openCustomerModal"
                                    class="shrink-0 rounded-md border border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50">
                                {{ __('+ Add Customer') }}
                            </button>
                        @endif
                    </div>
                    <x-input-error :messages="$errors->get('customer_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="sold_at" :value="__('Sale date (optional)')" />
                    <x-text-input id="sold_at" name="sold_at" type="date" class="mt-1 block w-full"
                                  :value="old('sold_at', now()->toDateString())" />
                    <x-input-error :messages="$errors->get('sold_at')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="sales_channel" :value="__('Sales channel *')" />
                    <select id="sales_channel" name="sales_channel"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @foreach ($channels as $value => $label)
                            <option value="{{ $value }}" @selected(old('sales_channel', $channelDefault) === $value)>{{ __($label) }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('sales_channel')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="warehouse_id" :value="__('Warehouse *')" />
                    <select id="warehouse_id" name="warehouse_id"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected((string) old('warehouse_id', $defaultWarehouse->id) === (string) $warehouse->id)>
                                {{ $warehouse->name }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">{{ __('Stock is deducted from this warehouse.') }}</p>
                    <x-input-error :messages="$errors->get('warehouse_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="status" :value="__('Save as *')" />
                    <select id="status" name="status"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="completed" @selected(old('status', 'completed') === 'completed')>{{ __('Completed (deduct stock now)') }}</option>
                        <option value="pending" @selected(old('status') === 'pending')>{{ __('Pending (deduct stock later)') }}</option>
                    </select>
                    <x-input-error :messages="$errors->get('status')" class="mt-2" />
                </div>

                <div class="md:col-span-3">
                    <x-input-label for="notes" :value="__('Notes (optional)')" />
                    <textarea id="notes" name="notes" rows="2"
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes') }}</textarea>
                    <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                </div>
            </div>

            {{-- Line items --}}
            <div class="rounded-lg bg-white p-6 shadow">
                <div class="flex items-center justify-between">
                    <div class="text-sm font-semibold text-gray-800">{{ __('Products *') }}</div>
                    <button type="button" @click="addRow()"
                            class="rounded-lg border border-indigo-600 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50">
                        {{ __('Add product') }}
                    </button>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-2 py-2">{{ __('Product') }}</th>
                                <th class="px-2 py-2">{{ __('Stock') }}</th>
                                <th class="px-2 py-2">{{ __('Qty *') }}</th>
                                <th class="px-2 py-2">{{ __('Price (Rp) *') }}</th>
                                <th class="px-2 py-2">{{ __('Discount (optional)') }}</th>
                                <th class="px-2 py-2 text-right">{{ __('Subtotal') }}</th>
                                <th class="px-2 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, index) in items" :key="row.key">
                                <tr class="border-t border-gray-100">
                                    <td class="px-2 py-2">
                                        <select :name="`items[${index}][product_id]`" x-model="row.product_id"
                                                @change="onProductChange(row)"
                                                class="block w-56 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                            <option value="">{{ __('— select product —') }}</option>
                                            <template x-for="product in products" :key="product.id">
                                                <option :value="product.id" x-text="product.sku ? `${product.name} (${product.sku})` : product.name"></option>
                                            </template>
                                        </select>
                                    </td>
                                    <td class="px-2 py-2">
                                        <span class="block" x-text="stockLabel(row)"></span>
                                        <span class="mt-1 block text-xs text-gray-500" x-text="minimumStockLabel(row)"></span>
                                    </td>
                                    <td class="px-2 py-2">
                                        <input type="number" min="1" step="1" :name="`items[${index}][quantity]`"
                                               x-model.number="row.quantity"
                                               class="block w-20 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    </td>
                                    <td class="px-2 py-2">
                                        <input type="number" min="0" step="0.01" :name="`items[${index}][unit_price]`"
                                               x-model.number="row.unit_price"
                                               class="block w-28 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    </td>
                                    <td class="px-2 py-2">
                                        <div class="flex items-center gap-1">
                                            <select :name="`items[${index}][discount_type]`" x-model="row.discount_type"
                                                    class="block w-24 rounded-md border-gray-300 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="fixed">{{ __('Rp') }}</option>
                                                <option value="percent">%</option>
                                            </select>
                                            <input type="number" min="0" step="1" :name="`items[${index}][discount_value]`"
                                                   x-model.number="row.discount_value"
                                                   class="block w-24 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </div>
                                    </td>
                                    <td class="px-2 py-2 text-right whitespace-nowrap" x-text="money(rowSubtotal(row))"></td>
                                    <td class="px-2 py-2 text-right">
                                        <button type="button" @click="removeRow(row.key)"
                                                class="text-sm text-red-600 hover:underline" x-show="items.length > 1">
                                            {{ __('Remove') }}
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <p class="mt-2 text-xs text-gray-500">
                    {{ __('Quantity is checked against the stock of the selected warehouse — a sale can never oversell inventory.') }}
                </p>
            </div>

            {{-- Totals + payment --}}
            <div class="grid gap-6 lg:grid-cols-2">
                <div class="space-y-4 rounded-lg bg-white p-6 shadow">
                    <div class="text-sm font-semibold text-gray-800">{{ __('Totals') }}</div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="discount_type" :value="__('Extra discount (optional)')" />
                            <div class="mt-1 flex items-center gap-2">
                                <select id="discount_type" name="discount_type" x-model="header.discount_type"
                                        class="block w-24 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="fixed">{{ __('Rp') }}</option>
                                    <option value="percent">%</option>
                                </select>
                                <x-text-input id="discount_value" name="discount_value" type="number" min="0" step="1"
                                              class="block w-full" x-model.number="header.discount_value" />
                            </div>
                            <x-input-error :messages="$errors->get('discount_value')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="tax_percent" :value="__('Tax (%) (optional)')" />
                            <x-text-input id="tax_percent" name="tax_percent" type="number" min="0" max="100" step="1"
                                          class="mt-1 block w-full" x-model.number="header.tax_percent" />
                            <p class="mt-1 text-xs text-gray-500">{{ __('Configurable per workspace — 0 means tax is not charged.') }}</p>
                        </div>

                        @if ($shippingEnabled)
                            <div>
                                <x-input-label for="shipping" :value="__('Shipping (Rp) (optional)')" />
                                <x-text-input id="shipping" name="shipping" type="number" min="0" step="0.01"
                                              class="mt-1 block w-full" x-model.number="header.shipping" />
                            </div>
                        @endif
                    </div>

                    <dl class="space-y-1 border-t border-gray-100 pt-4 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-600">{{ __('Subtotal') }}</dt>
                            <dd class="font-medium" x-text="money(subtotal)"></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-600">{{ __('Item discounts') }}</dt>
                            <dd x-text="'- ' + money(itemDiscountTotal)"></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-600">{{ __('Extra discount (optional)') }}</dt>
                            <dd x-text="'- ' + money(headerDiscount)"></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-600">{{ __('Tax') }}</dt>
                            <dd x-text="money(tax)"></dd>
                        </div>
                        @if ($shippingEnabled)
                            <div class="flex justify-between">
                                <dt class="text-gray-600">{{ __('Shipping') }}</dt>
                                <dd x-text="money(Number(header.shipping) || 0)"></dd>
                            </div>
                        @endif
                        <div class="flex justify-between border-t border-gray-100 pt-2 text-base">
                            <dt class="font-semibold text-gray-800">{{ __('Total') }}</dt>
                            <dd class="font-bold text-gray-900" x-text="money(total)"></dd>
                        </div>
                    </dl>

                    <p class="text-xs text-gray-500">{{ __('The server recalculates every total — this preview is for guidance only.') }}</p>
                </div>

                <div class="space-y-4 rounded-lg bg-white p-6 shadow">
                    <div class="text-sm font-semibold text-gray-800">{{ __('Payment') }}</div>

                    <div>
                        <x-input-label for="payment_method" :value="__('Payment method *')" />
                        <select id="payment_method" name="payment_method"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($paymentMethods as $value => $label)
                                <option value="{{ $value }}" @selected(old('payment_method', $methodDefault) === $value)>{{ __($label) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('payment_method')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="payment_amount" :value="__('Amount paid (Rp) (optional)')" />
                        <div class="mt-1 flex items-center gap-2">
                            <x-text-input id="payment_amount" name="payment_amount" type="number" min="0" step="0.01"
                                          class="block w-full" x-model.number="paid" />
                            <button type="button" @click="paid = total"
                                    class="shrink-0 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                {{ __('Exact') }}
                            </button>
                        </div>
                        <x-input-error :messages="$errors->get('payment_amount')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="payment_reference" :value="__('Payment reference (optional)')" />
                        <x-text-input id="payment_reference" name="payment_reference" type="text" class="mt-1 block w-full"
                                      :value="old('payment_reference')" placeholder="{{ __('Transfer / QRIS / card reference') }}" />
                    </div>

                    <dl class="space-y-1 border-t border-gray-100 pt-4 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-600">{{ __('Total') }}</dt>
                            <dd class="font-medium" x-text="money(total)"></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-600">{{ __('Change') }}</dt>
                            <dd class="font-medium text-green-700" x-text="money(change)"></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-600">{{ __('Outstanding') }}</dt>
                            <dd class="font-medium text-amber-600" x-text="money(outstanding)"></dd>
                        </div>
                    </dl>

                    <p class="text-xs text-gray-500">{{ __('Leave the amount empty to record an unpaid sale.') }}</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <x-primary-button x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                    <span x-show="!submitting">{{ __('Record sale') }}</span>
                    <span x-show="submitting" style="display: none;" class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
                        </svg>
                        {{ __('Saving…') }}
                    </span>
                </x-primary-button>
                <a href="{{ route('sales.index') }}" class="text-sm text-gray-600 underline">{{ __('Cancel') }}</a>
            </div>
        </form>

        @include('sales.partials.customer-modal')
    </div>

    {{-- Inline (parsed before the deferred app bundle) so window.saleForm exists when Alpine boots. The preview is cosmetic: every amount is recalculated server-side. --}}
    <script>
            window.saleForm = function (config) {
                const products = config.products || [];

                const blankRow = (key) => ({
                    key: key,
                    product_id: '',
                    quantity: 1,
                    unit_price: '',
                    discount_type: 'fixed',
                    discount_value: 0,
                });

                return {
                    products: products,
                    customerOptions: Array.isArray(config.customers) ? config.customers : [],
                    selectedCustomerId: String(config.selectedCustomerId || ''),
                    lastCustomerId: String(config.selectedCustomerId || ''),
                    canCreateCustomer: Boolean(config.canCreateCustomer),
                    customerStoreUrl: config.customerStoreUrl,
                    customerModalOpen: false,
                    customerSaving: false,
                    customerError: '',
                    customerForm: { name: '', phone: '', email: '', address: '' },
                    items: [blankRow(1)],
                    nextKey: 2,
                    header: {
                        discount_type: 'fixed',
                        discount_value: 0,
                        tax_percent: Number(config.taxPercent) || 0,
                        shipping: 0,
                    },
                    paid: '',
                    submitting: false,

                    product(row) {
                        return this.products.find((p) => String(p.id) === String(row.product_id)) || null;
                    },

                    onProductChange(row) {
                        const product = this.product(row);
                        row.unit_price = product ? product.price : '';
                    },

                    stockLabel(row) {
                        const product = this.product(row);

                        if (!product) {
                            return '—';
                        }

                        if (!product.track_inventory) {
                            return 'Not tracked';
                        }

                        const stock = Number(product.stock ?? 0);
                        const minimum = Number(product.minimum_stock ?? 0);
                        const prefix = !product.stock_recorded
                            ? '⚠ No stock record · '
                            : (stock <= minimum ? '⚠ Low Stock · ' : '✓ In Stock · ');

                        return prefix + stock + ' ' + product.unit;
                    },

                    minimumStockLabel(row) {
                        const product = this.product(row);

                        if (!product || !product.track_inventory) {
                            return '';
                        }

                        return 'Minimum stock: ' + Number(product.minimum_stock ?? 0) + ' ' + product.unit;
                    },

                    handleCustomerChange() {
                        if (this.selectedCustomerId === '__add_customer__') {
                            this.openCustomerModal();
                            this.selectedCustomerId = this.lastCustomerId;
                            return;
                        }

                        this.lastCustomerId = this.selectedCustomerId;
                    },

                    openCustomerModal() {
                        if (!this.canCreateCustomer) {
                            return;
                        }

                        this.customerError = '';
                        this.customerModalOpen = true;
                    },

                    closeCustomerModal() {
                        if (this.customerSaving) {
                            return;
                        }

                        this.customerModalOpen = false;
                    },

                    async createCustomer() {
                        if (this.customerSaving || !this.canCreateCustomer) {
                            return;
                        }

                        this.customerSaving = true;
                        this.customerError = '';

                        try {
                            const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
                            const response = await window.fetch(this.customerStoreUrl, {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': token,
                                },
                                body: JSON.stringify({ ...this.customerForm, form_context: 'sales_create' }),
                            });
                            const payload = await response.json().catch(() => ({}));

                            if (!response.ok) {
                                const messages = Object.values(payload.errors || {}).flat();
                                this.customerError = messages[0] || 'Unable to create customer.';
                                return;
                            }

                            const customer = payload.customer;
                            this.customerOptions.push({
                                id: String(customer.id),
                                name: customer.name,
                                phone: customer.phone || '',
                            });
                            this.selectedCustomerId = String(customer.id);
                            this.lastCustomerId = String(customer.id);
                            this.customerForm = { name: '', phone: '', email: '', address: '' };
                            this.customerModalOpen = false;
                        } catch (error) {
                            this.customerError = 'Unable to create customer. Please try again.';
                        } finally {
                            this.customerSaving = false;
                        }
                    },

                    addRow() {
                        this.items.push(blankRow(this.nextKey++));
                    },

                    removeRow(key) {
                        this.items = this.items.filter((row) => row.key !== key);
                    },

                    rowGross(row) {
                        return (Number(row.quantity) || 0) * (Number(row.unit_price) || 0);
                    },

                    rowDiscount(row) {
                        const gross = this.rowGross(row);
                        const value = Number(row.discount_value) || 0;

                        if (value <= 0) {
                            return 0;
                        }

                        const discount = row.discount_type === 'percent'
                            ? gross * Math.min(100, value) / 100
                            : value;

                        return Math.min(gross, discount);
                    },

                    rowSubtotal(row) {
                        return Math.max(0, this.rowGross(row) - this.rowDiscount(row));
                    },

                    get subtotal() {
                        return this.items.reduce((sum, row) => sum + this.rowSubtotal(row), 0);
                    },

                    get itemDiscountTotal() {
                        return this.items.reduce((sum, row) => sum + this.rowDiscount(row), 0);
                    },

                    get headerDiscount() {
                        const value = Number(this.header.discount_value) || 0;

                        if (value <= 0) {
                            return 0;
                        }

                        const discount = this.header.discount_type === 'percent'
                            ? this.subtotal * Math.min(100, value) / 100
                            : value;

                        return Math.min(this.subtotal, discount);
                    },

                    get tax() {
                        const taxable = Math.max(0, this.subtotal - this.headerDiscount);

                        return taxable * (Number(this.header.tax_percent) || 0) / 100;
                    },

                    get total() {
                        return Math.max(0, this.subtotal - this.headerDiscount + this.tax)
                            + (Number(this.header.shipping) || 0);
                    },

                    get change() {
                        return Math.max(0, (Number(this.paid) || 0) - this.total);
                    },

                    get outstanding() {
                        return Math.max(0, this.total - (Number(this.paid) || 0));
                    },

                    money(value) {
                        return 'Rp ' + (Number(value) || 0).toLocaleString('id-ID', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2,
                        });
                    },
                };
        };
    </script>
</x-app-layout>
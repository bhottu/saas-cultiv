<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-600 text-white">
                    <x-nav-icon name="shopping-cart" class="h-5 w-5" />
                </span>
                <div>
                    <h2 class="text-xl font-bold leading-tight text-gray-900">{{ __('Point of Sale') }}</h2>
                    <p class="text-xs text-gray-500">{{ __('Terminal for fast in-store checkout') }}</p>
                </div>
            </div>
            <a href="{{ route('sales.create') }}" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                {{ __('Full sales form') }}
            </a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-4 py-6 sm:px-6 lg:px-8">
        {{-- Inline notices (stock errors, network failures) --}}
        <div id="pos-alert" class="hidden rounded-lg border p-3 text-sm"></div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
            {{-- ---------------- Left: catalogue + search (7 cols) ---------------- --}}
            <div class="space-y-4 lg:col-span-7">
                <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label for="pos-warehouse" class="mb-1 block text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('Warehouse') }}</label>
                            <select id="pos-warehouse" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @forelse ($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected($defaultWarehouse && $defaultWarehouse->id === $warehouse->id)>
                                        {{ $warehouse->name }}
                                    </option>
                                @empty
                                    <option value="">{{ __('No active warehouse') }}</option>
                                @endforelse
                            </select>
                        </div>
                        <div>
                            <label for="pos-customer" class="mb-1 block text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('Customer') }}</label>
                            <select id="pos-customer" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">{{ __('Walk-in customer') }}</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Search + camera scan: the SAME shared component /products uses.
                         It emits a bubbling `barcode-scanned` event which the wrapper
                         forwards into the cart engine as `pos-barcode`. --}}
                    <div x-data="{ scanned(code) { document.dispatchEvent(new CustomEvent('pos-barcode', { detail: { code } })); } }"
                         @barcode-scanned.window="scanned($event.detail.code)">
                        <div class="flex items-center gap-2">
                            <div class="relative min-w-0 flex-1">
                                <label for="pos-search" class="sr-only">{{ __('Search products') }}</label>
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M10 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16z"/>
                                    </svg>
                                </span>
                                <input type="text" id="pos-search" autocomplete="off"
                                       placeholder="{{ __('Search name, SKU, or scan a barcode...') }}"
                                       class="w-full rounded-lg border-gray-300 py-2 pl-10 pr-3 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <x-barcode-scanner-modal :label="__('Scan')"
                                                     :title="__('Scan a product barcode')"
                                                     :hint="__('Point the camera at the barcode. The product is added to the order.')" />
                        </div>
                        <p class="mt-1.5 text-xs text-gray-400">{{ __('Autoscan ready — type, scan, or press Enter on an exact barcode.') }}</p>
                    </div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-700">{{ __('Products') }}</h3>
                        <span id="pos-loading" class="hidden text-xs font-medium text-indigo-600">{{ __('Loading...') }}</span>
                    </div>
                    <div id="pos-product-grid" class="grid max-h-[520px] grid-cols-2 gap-3 overflow-y-auto sm:grid-cols-3"></div>
                </div>
            </div>

            {{-- ---------------- Right: cart + checkout (5 cols) ---------------- --}}
            <div class="lg:col-span-5">
                <div class="flex h-[calc(100vh-12rem)] min-h-[560px] flex-col rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                        <div class="flex items-center gap-2">
                            <h3 class="text-base font-bold text-gray-900">{{ __('Current order') }}</h3>
                            <span id="pos-cart-badge" class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700">0</span>
                        </div>
                        <button type="button" id="pos-clear-cart" class="text-xs font-medium text-gray-400 hover:text-red-600">
                            {{ __('Clear') }}
                        </button>
                    </div>

                    <div id="pos-cart-items" class="flex-1 divide-y divide-gray-100 overflow-y-auto py-2"></div>

                    {{-- Discount + tax share one responsive row; both containers
                         close properly so nothing below them can nest inside. --}}
                    <div class="space-y-2 border-t border-gray-100 pt-3">
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div class="flex items-center gap-2">
                                <label for="pos-discount-type" class="text-xs text-gray-500">{{ __('Discount') }}</label>
                                <select id="pos-discount-type" class="rounded border-gray-300 py-1 text-xs focus:ring-indigo-500">
                                    <option value="fixed">{{ __('Fixed (Rp)') }}</option>
                                    <option value="percent">{{ __('Percent (%)') }}</option>
                                </select>
                                <input type="number" id="pos-discount-value" min="0" step="any" value="0"
                                       class="w-20 flex-1 rounded border-gray-300 px-2 py-1 text-right text-xs focus:ring-indigo-500">
                            </div>
                            <div class="flex items-center gap-2">
                                <label for="pos-tax-percent" class="text-xs text-gray-500">{{ __('Tax') }}</label>
                                <input type="number" id="pos-tax-percent" min="0" max="100" value="0"
                                       class="w-20 flex-1 rounded border-gray-300 px-2 py-1 text-right text-xs focus:ring-indigo-500">
                                <span class="text-xs text-gray-400">%</span>
                            </div>
                        </div>
                    </div>

                    <dl class="space-y-1.5 border-t border-gray-200 pt-3 text-sm">
                        <div class="flex justify-between text-xs text-gray-600">
                            <dt>{{ __('Subtotal') }}</dt>
                            <dd id="pos-subtotal" class="font-mono font-medium">Rp 0</dd>
                        </div>
                        <div class="flex justify-between text-xs text-emerald-600">
                            <dt>{{ __('Discount') }}</dt>
                            <dd id="pos-discount-total" class="font-mono font-medium">Rp 0</dd>
                        </div>
                        <div class="flex justify-between text-xs text-gray-600">
                            <dt>{{ __('Tax') }}</dt>
                            <dd id="pos-tax-total" class="font-mono font-medium">Rp 0</dd>
                        </div>
                        <div class="flex items-baseline justify-between border-t border-gray-200 pt-2">
                            <dt class="text-base font-bold text-gray-900">{{ __('Total') }}</dt>
                            <dd id="pos-grand-total" class="font-mono text-xl font-extrabold text-indigo-700">Rp 0</dd>
                        </div>
                    </dl>

                    <div class="mt-4 space-y-3 border-t border-gray-200 pt-3">
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="pos-payment-method" class="mb-1 block text-xs font-semibold text-gray-500">{{ __('Payment') }}</label>
                                <select id="pos-payment-method" class="w-full rounded-lg border-gray-300 py-1.5 text-xs focus:ring-indigo-500">
                                    @foreach ($paymentMethods as $key => $label)
                                        <option value="{{ $key }}" @selected($key === 'cash')>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="pos-cash-given" class="mb-1 block text-xs font-semibold text-gray-500">{{ __('Cash given') }}</label>
                                <input type="number" id="pos-cash-given" min="0" step="100"
                                       class="w-full rounded-lg border-gray-300 py-1.5 text-right font-mono text-xs focus:ring-indigo-500">
                            </div>
                        </div>

                        <div id="pos-quick-cash" class="flex flex-wrap gap-1.5">
                            <button type="button" data-exact class="rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200">{{ __('Exact') }}</button>
                            <button type="button" data-add="10000" class="rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200">+10k</button>
                            <button type="button" data-add="20000" class="rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200">+20k</button>
                            <button type="button" data-add="50000" class="rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200">+50k</button>
                            <button type="button" data-add="100000" class="rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200">+100k</button>
                        </div>

                        <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2">
                            <span class="text-xs font-semibold text-gray-600">{{ __('Change') }}</span>
                            <span id="pos-change" class="font-mono text-sm font-bold text-gray-900">Rp 0</span>
                        </div>

                        <button type="button" id="pos-checkout" disabled
                                class="w-full rounded-lg bg-indigo-600 py-3 text-center text-sm font-bold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-gray-300">
                            {{ __('Complete sale') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>


    {{-- Success modal: the receipt, ready to print --}}
    <div id="pos-modal" class="fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto bg-gray-900/60 p-4">
        <div class="w-full max-w-md space-y-4 rounded-2xl bg-white p-6 text-center shadow-2xl">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                <svg class="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <div>
                <h3 class="text-lg font-bold text-gray-900">{{ __('Sale completed') }}</h3>
                <p id="pos-modal-invoice" class="mt-1 font-mono text-sm text-gray-500"></p>
            </div>
            <dl class="space-y-2 rounded-xl bg-gray-50 p-4 text-sm">
                <div class="flex justify-between text-gray-600">
                    <dt>{{ __('Total') }}</dt>
                    <dd id="pos-modal-total" class="font-mono font-bold text-gray-900"></dd>
                </div>
                <div class="flex justify-between text-gray-600">
                    <dt>{{ __('Paid') }}</dt>
                    <dd id="pos-modal-paid" class="font-mono font-semibold text-gray-900"></dd>
                </div>
                <div class="flex justify-between border-t border-gray-200 pt-2 font-semibold text-emerald-700">
                    <dt>{{ __('Change') }}</dt>
                    <dd id="pos-modal-change" class="font-mono font-bold"></dd>
                </div>
            </dl>
            <div class="flex gap-2 pt-2">
                <a id="pos-receipt" href="#" target="_blank" rel="noopener"
                   class="flex-1 rounded-lg border border-gray-300 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    {{ __('Print receipt') }}
                </a>
                <button type="button" id="pos-new-sale"
                        class="flex-1 rounded-lg bg-indigo-600 py-2.5 text-sm font-bold text-white hover:bg-indigo-700">
                    {{ __('New sale') }}
                </button>
            </div>
        </div>
    </div>


    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const endpoints = {
            products: @json(route('pos.products')),
            calculate: @json(route('pos.calculate')),
            checkout: @json(route('pos.checkout')),
        };

        // The cart holds WHOLE RUPIAH (what the cashier sees and types). Conversion to
        // cents happens once, server-side, in RecordSaleService / CalculateSaleTotals --
        // the browser never decides what a sale costs.
        const state = {
            warehouseId: document.getElementById('pos-warehouse').value,
            products: [],
            cart: [],
            total: 0,
            reference: null,
        };

        const el = (id) => document.getElementById(id);

        const dom = {
            search: el('pos-search'),
            warehouse: el('pos-warehouse'),
            customer: el('pos-customer'),
            loading: el('pos-loading'),
            grid: el('pos-product-grid'),
            cartItems: el('pos-cart-items'),
            cartBadge: el('pos-cart-badge'),
            clear: el('pos-clear-cart'),
            discountType: el('pos-discount-type'),
            discountValue: el('pos-discount-value'),
            taxPercent: el('pos-tax-percent'),
            subtotal: el('pos-subtotal'),
            discountTotal: el('pos-discount-total'),
            taxTotal: el('pos-tax-total'),
            grandTotal: el('pos-grand-total'),
            payment: el('pos-payment-method'),
            cashGiven: el('pos-cash-given'),
            quickCash: el('pos-quick-cash'),
            change: el('pos-change'),
            checkout: el('pos-checkout'),
            alert: el('pos-alert'),
            modal: el('pos-modal'),
            modalInvoice: el('pos-modal-invoice'),
            modalTotal: el('pos-modal-total'),
            modalPaid: el('pos-modal-paid'),
            modalChange: el('pos-modal-change'),
            receipt: el('pos-receipt'),
            newSale: el('pos-new-sale'),
        };

        const rupiah = (value) => 'Rp ' + Math.round(Number(value) || 0).toLocaleString('id-ID');

        function flash(message, kind = 'error') {
            dom.alert.textContent = message;
            dom.alert.className = 'rounded-lg border p-3 text-sm ' + (kind === 'error'
                ? 'border-red-200 bg-red-50 text-red-800'
                : 'border-emerald-200 bg-emerald-50 text-emerald-800');
            dom.alert.classList.remove('hidden');
        }

        const clearFlash = () => dom.alert.classList.add('hidden');

        async function loadProducts(query = '') {
            dom.loading.classList.remove('hidden');

            try {
                const url = new URL(endpoints.products, window.location.origin);
                if (state.warehouseId) url.searchParams.set('warehouse_id', state.warehouseId);
                if (query) url.searchParams.set('q', query);

                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                const data = await response.json();

                state.products = data.products || [];
                renderProducts();
            } catch (e) {
                flash('Could not load products. Check your connection.');
            } finally {
                dom.loading.classList.add('hidden');
            }
        }


        function renderProducts() {
            if (!state.products.length) {
                dom.grid.innerHTML = '<p class="col-span-full py-8 text-center text-xs text-gray-400">{{ __('No products found') }}</p>';
                return;
            }

            dom.grid.innerHTML = '';

            state.products.forEach((product) => {
                const card = document.createElement('button');
                card.type = 'button';
                card.className = 'rounded-lg border border-gray-200 bg-white p-3 text-left transition hover:border-indigo-400 hover:shadow focus:outline-none focus:ring-2 focus:ring-indigo-500';
                card.innerHTML = `
                    <div class="truncate font-mono text-[11px] text-gray-400">${product.sku || '--'}</div>
                    <div class="mt-1 line-clamp-2 text-xs font-semibold text-gray-900">${product.name}</div>
                    <div class="mt-2 flex items-center justify-between border-t border-gray-50 pt-2">
                        <span class="text-xs font-bold text-indigo-700">${product.price_formatted}</span>
                        <span class="text-[10px] ${product.stock > 0 ? 'text-gray-500' : 'font-semibold text-red-500'}">${product.stock} ${product.unit}</span>
                    </div>`;
                card.addEventListener('click', () => addToCart(product));
                dom.grid.appendChild(card);
            });
        }

        function addToCart(product) {
            const line = state.cart.find((item) => item.id === product.id);

            if (line) {
                line.quantity += 1;
            } else {
                state.cart.push({
                    id: product.id,
                    name: product.name,
                    // product.price is stored in CENTS; the cart works in whole rupiah.
                    unit_price: product.price / 100,
                    quantity: 1,
                });
            }

            renderCart();
            refreshTotals();
        }

        // Shared by the Enter-on-search path and the camera scanner: resolve a scanned
        // code against the loaded grid (barcode first, then SKU). If the grid has not
        // been refreshed since the scan, one targeted lookup keeps the counter moving.
        function addScannedToCart(rawCode) {
            const code = String(rawCode || '').trim();
            if (!code) return;

            // Case-insensitive, exactly like sales/create.blade.php: a typed "kp-001"
            // or a scanner emitting lowercase still resolves SKU "KP-001".
            const needle = code.toLowerCase();
            const findMatch = () => state.products.find(
                (product) =>
                    (product.barcode && String(product.barcode).trim().toLowerCase() === needle) ||
                    (product.sku && String(product.sku).trim().toLowerCase() === needle)
            );

            let match = findMatch();

            if (!match) {
                loadProducts(code).then(() => {
                    match = findMatch();

                    if (match) {
                        addToCart(match);
                        dom.search.value = '';
                        dom.search.focus();
                    } else {
                        flash(`No product matches "${code}".`);
                    }
                });
                return;
            }

            addToCart(match);
            dom.search.value = '';
            dom.search.focus();
        }

        function setQuantity(productId, delta) {
            const line = state.cart.find((item) => item.id === productId);
            if (!line) return;

            line.quantity += delta;

            if (line.quantity <= 0) {
                state.cart = state.cart.filter((item) => item.id !== productId);
            }

            renderCart();
            refreshTotals();
        }

        function renderCart() {
            const units = state.cart.reduce((sum, item) => sum + item.quantity, 0);
            dom.cartBadge.textContent = units;

            if (!state.cart.length) {
                dom.cartItems.innerHTML = '<p class="py-12 text-center text-sm text-gray-400">{{ __('Cart is empty.') }}</p>';
                return;
            }

            dom.cartItems.innerHTML = '';

            state.cart.forEach((item) => {
                const row = document.createElement('div');
                row.className = 'flex items-center justify-between gap-2 py-2.5';
                row.innerHTML = `
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-xs font-semibold text-gray-900">${item.name}</div>
                        <div class="font-mono text-[11px] text-gray-400">${rupiah(item.unit_price)}</div>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button type="button" data-step="-1" class="flex h-6 w-6 items-center justify-center rounded bg-gray-100 text-xs font-bold text-gray-700 hover:bg-gray-200">-</button>
                        <span class="w-6 text-center font-mono text-xs font-semibold">${item.quantity}</span>
                        <button type="button" data-step="1" class="flex h-6 w-6 items-center justify-center rounded bg-gray-100 text-xs font-bold text-gray-700 hover:bg-gray-200">+</button>
                    </div>
                    <span class="w-24 text-right font-mono text-xs font-bold text-gray-900">${rupiah(item.unit_price * item.quantity)}</span>`;

                row.querySelectorAll('button[data-step]').forEach((button) => {
                    button.addEventListener('click', () => setQuantity(item.id, Number(button.dataset.step)));
                });

                dom.cartItems.appendChild(row);
            });
        }


        // Preview only. The authoritative total is computed again at checkout.
        let previewTimer = null;

        function refreshTotals() {
            clearFlash();
            dom.checkout.disabled = state.cart.length === 0;

            if (!state.cart.length) {
                state.total = 0;
                dom.subtotal.textContent = rupiah(0);
                dom.discountTotal.textContent = rupiah(0);
                dom.taxTotal.textContent = rupiah(0);
                dom.grandTotal.textContent = rupiah(0);
                renderChange();
                return;
            }

            clearTimeout(previewTimer);

            previewTimer = setTimeout(async () => {
                try {
                    const response = await fetch(endpoints.calculate, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        },
                        body: JSON.stringify({
                            discount_type: dom.discountType.value,
                            discount_value: Number(dom.discountValue.value) || 0,
                            tax_percent: Number(dom.taxPercent.value) || 0,
                            items: state.cart.map((item) => ({
                                product_id: item.id,
                                quantity: item.quantity,
                                unit_price: item.unit_price,
                                discount_type: 'fixed',
                                discount_value: 0,
                            })),
                        }),
                    });

                    if (!response.ok) return;

                    const { totals } = await response.json();

                    dom.subtotal.textContent = totals.subtotal_fmt;
                    dom.discountTotal.textContent = totals.discount_fmt;
                    dom.taxTotal.textContent = totals.tax_fmt;
                    dom.grandTotal.textContent = totals.total_fmt;

                    state.total = totals.total / 100; // the client works in whole rupiah
                    renderChange();
                } catch (e) {
                    /* keep the last known totals; checkout will recompute anyway */
                }
            }, 200);
        }

        function renderChange() {
            const given = Number(dom.cashGiven.value) || 0;
            dom.change.textContent = rupiah(Math.max(0, given - state.total));
        }


        async function checkout() {
            if (!state.cart.length) return;

            // One idempotency key per checkout attempt: a double tap or a retry after a
            // dropped response resolves to the same sale instead of selling twice.
            if (!state.reference) {
                state.reference = 'pos-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
            }

            const paid = Number(dom.cashGiven.value) || 0;
            const given = paid > 0 ? paid : state.total;

            if (paid > 0 && paid < state.total) {
                flash('Amount received is less than the total.');
                return;
            }

            dom.checkout.disabled = true;
            dom.checkout.textContent = @json(__('Processing...'));

            try {
                const response = await fetch(endpoints.checkout, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({
                        warehouse_id: Number(state.warehouseId),
                        customer_id: dom.customer.value ? Number(dom.customer.value) : null,
                        discount_type: dom.discountType.value,
                        discount_value: Number(dom.discountValue.value) || 0,
                        tax_percent: Number(dom.taxPercent.value) || 0,
                        payment_method: dom.payment.value,
                        payment_amount: given,
                        client_reference: state.reference,
                        items: state.cart.map((item) => ({
                            product_id: item.id,
                            quantity: item.quantity,
                            unit_price: item.unit_price,
                            discount_type: 'fixed',
                            discount_value: 0,
                        })),
                    }),
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    flash(data.message || 'The sale could not be completed.');
                    // A failed attempt must be retryable, so drop the idempotency key.
                    state.reference = null;
                    return;
                }

                flash(`Sale ${data.invoice_number} recorded — ${data.change_fmt} change.`, 'success');

                dom.modalInvoice.textContent = data.invoice_number;
                dom.modalTotal.textContent = data.total_fmt;
                dom.modalPaid.textContent = data.paid_fmt;
                dom.modalChange.textContent = data.change_fmt;
                dom.receipt.href = data.receipt_url;
                dom.modal.classList.remove('hidden');
                dom.modal.classList.add('flex');

                // Reset for the next customer; stock levels are now stale. Discount and
                // tax must reset too, or they silently follow the receipt to the next one.
                resetOrder();
                loadProducts(dom.search.value.trim());
            } catch (e) {
                flash('Could not reach the server. The sale was not recorded.');
                state.reference = null;
            } finally {
                dom.checkout.disabled = state.cart.length === 0;
                dom.checkout.textContent = @json(__('Complete sale'));
            }
        }

        // One place wipes a finished or abandoned order: cart, cash, and the discount /
        // tax controls. Leaving stale values behind would silently apply them to the
        // next customer's receipt.
        function resetOrder() {
            state.cart = [];
            state.reference = null;
            state.total = 0;
            dom.cashGiven.value = '';
            dom.discountValue.value = '0';
            dom.discountType.value = 'fixed';
            dom.taxPercent.value = '0';

            renderCart();
            refreshTotals();
        }


        // ---------------------------------------------------------- event wiring

        let searchTimer = null;

        dom.search.addEventListener('input', (event) => {
            const value = event.target.value.trim();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => loadProducts(value), 250);
        });

        dom.warehouse.addEventListener('change', (event) => {
            state.warehouseId = event.target.value;
            loadProducts(dom.search.value.trim());
        });

        dom.discountType.addEventListener('change', refreshTotals);
        dom.discountValue.addEventListener('input', refreshTotals);
        dom.taxPercent.addEventListener('input', refreshTotals);
        dom.cashGiven.addEventListener('input', renderChange);

        dom.quickCash.addEventListener('click', (event) => {
            const button = event.target.closest('button');
            if (!button) return;

            if (button.dataset.exact !== undefined) {
                dom.cashGiven.value = Math.round(state.total);
            } else if (button.dataset.add) {
                dom.cashGiven.value = (Number(dom.cashGiven.value) || 0) + Number(button.dataset.add);
            }

            renderChange();
        });

        dom.clear.addEventListener('click', () => {
            if (!state.cart.length || !window.confirm(@json(__('Clear the current order?')))) return;

            resetOrder();
        });

        dom.checkout.addEventListener('click', checkout);

        dom.newSale.addEventListener('click', () => {
            dom.modal.classList.add('hidden');
            dom.modal.classList.remove('flex');
            dom.search.focus();
        });

        // A USB/keyboard scanner types the code fast then hits Enter: add the exact
        // match immediately instead of waiting on the debounced search.
        dom.search.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;

            const scanned = dom.search.value.trim();
            if (!scanned) return;

            event.preventDefault();
            addScannedToCart(scanned);
        });

        // Camera path: the shared barcode scanner modal emits `barcode-scanned`,
        // which the header wrapper forwards here as `pos-barcode`. Same helper, so
        // camera, keyboard wedge, and typed codes all behave identically.
        document.addEventListener('pos-barcode', (event) => {
            addScannedToCart(event.detail && event.detail.code);
        });

        renderCart();
        refreshTotals();
        loadProducts();
    });
    </script>
</x-app-layout>


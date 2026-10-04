{{--
    Purchase create / edit.

    Two things this view had to get right, both learned the hard way.

    1. The Alpine component is registered INLINE, parsed before the deferred app bundle,
       exactly as sales/create and pos/index do. It used to live in `@push('scripts')`,
       but no layout in this project renders `@stack('scripts')`, so that script was never
       emitted at all: `purchaseForm` was undefined and the subtree never initialised.

    2. The x-data sits on an inner <div>, NOT on <x-app-layout>. layouts/app.blade.php
       never renders `$attributes`, so an attribute placed on the layout component is
       silently dropped and Alpine never sees it — the button renders, the click handler
       does not exist. Wrapping the content is what actually mounts the scope; it nests
       cleanly inside the layout's own `{ sidebarOpen: false }` scope.
--}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-900">{{ $pageTitle }}</h2>
            <a href="{{ route('purchases.index') }}" class="text-sm underline">{{ __('Back to purchases') }}</a>
        </div>
    </x-slot>

    {{-- The option label is composed HERE rather than in the browser.
             The Alpine version interpolated it in a template literal:
             `${product.name} · ${product.sku}`. JavaScript stringifies a null inside a
             template literal, so a product with no SKU rendered the literal text
             "null" right next to its name. Deciding in PHP makes the empty case a real
             absence instead, and it keeps the rule in one testable place. --}}
    <div x-data="purchaseForm(@js([
        'products' => $products->map(fn ($p) => [
            'id'    => $p->id,
            'label' => $p->sku ? $p->name.' • '.$p->sku : $p->name,
        ]),
        'items'    => old('items'),
        'discount' => old('discount', $purchase->exists ? $purchase->discount / 100 : 0),
        'tax'      => old('tax', $purchase->exists ? $purchase->tax / 100 : 0),
        'shipping' => old('shipping', $purchase->exists ? $purchase->shipping / 100 : 0),
    ]))">
        <style>
            [x-cloak] { display: none !important; }
        </style>

    {{-- `x-on:submit` is what arms the loading state. Without it `submitting` stayed
             false forever, and because the button is Alpine-owned (x-bind:disabled) the
             GLOBAL submit loader deliberately skips it — so no mechanism was setting the
             state and the button never showed progress. Same wiring as sales/create. --}}
        <form method="POST" action="{{ $submitUrl }}" x-on:submit="submitting = true"
              class="mx-auto max-w-6xl space-y-6 py-12 sm:px-6 lg:px-8">
        @csrf
        @if ($purchase->exists)
            @method('PUT')
        @endif

        {{-- The form re-renders after every rejected submit, so a silent re-render read
             as "nothing happened". --}}
        @if (session('status'))
            @php($status = session('status'))
            <div role="{{ ($status['type'] ?? 'success') === 'error' ? 'alert' : 'status' }}"
                 class="rounded-lg p-3 {{ ($status['type'] ?? 'success') === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                {{ $status['message'] ?? '' }}
            </div>
        @endif

        {{-- A summary alongside the per-field messages: with a repeating item grid, the
             first offending row is not always the one on screen. --}}
        @if ($errors->any())
            <div role="alert" class="rounded-lg bg-red-100 p-3 text-red-800">
                <p class="font-semibold">{{ __('Please complete the required fields.') }}</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <div class="grid gap-4 rounded-lg bg-white p-6 shadow sm:grid-cols-3">
            <div>
                <x-input-label for="supplier_id" :value="__('Supplier *')" />

                {{-- An empty supplier list cannot be offered as a dropdown: there is
                     nothing to pick, the field is required, and the form would be
                     un-submittable with no explanation at all. --}}
                @if ($suppliers->isEmpty())
                    <div class="mt-1 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                        <p class="font-medium">{{ __('No suppliers yet.') }}</p>
                        <p class="mt-1">{{ __('Add a supplier first to create a purchase.') }}</p>
                        {{-- Only offered when the user may actually create one, so the page
                             never advertises a control that would answer 403. --}}
                        @if ($canCreateSupplier)
                            <a href="{{ route('suppliers.create') }}"
                               class="mt-2 inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                {{ __('Add supplier') }}
                            </a>
                        @endif
                    </div>
                @else
                    <select id="supplier_id" name="supplier_id" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">{{ __('Select a supplier') }}</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected(old('supplier_id', $purchase->supplier_id) == $supplier->id)>{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('supplier_id')" class="mt-2" />
                @endif
            </div>

            <div>
                <x-input-label for="warehouse_id" :value="__('Warehouse *')" />
                <select id="warehouse_id" name="warehouse_id" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">{{ __('Select a warehouse') }}</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected(old('warehouse_id', $purchase->warehouse_id) == $warehouse->id)>{{ $warehouse->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('warehouse_id')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="expected_at" :value="__('Expected date')" />
                <input id="expected_at" name="expected_at" type="date"
                       value="{{ old('expected_at', $purchase->expected_at?->format('Y-m-d')) }}"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <x-input-error :messages="$errors->get('expected_at')" class="mt-2" />
            </div>
        </div>
        <div class="rounded-lg bg-white p-6 shadow">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h3 class="font-semibold">{{ __('Items *') }}</h3>
                <button type="button" @click="addRow()"
                        class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    {{ __('Add item') }}
                </button>
            </div>

            <x-input-error :messages="$errors->get('items')" class="mb-3" />

            <div class="space-y-3" x-cloak>
                <template x-for="(row, index) in items" :key="row.key">
                    <div class="grid gap-3 md:grid-cols-12">
                        <select :name="`items[${index}][product_id]`" x-model="row.product_id" required
                                class="rounded-md border-gray-300 md:col-span-5">
                            <option value="">{{ __('Select product') }}</option>
                            <template x-for="product in products" :key="product.id">
                                <option :value="product.id" x-text="product.label"></option>
                            </template>
                        </select>
                        <input :name="`items[${index}][quantity]`" x-model="row.quantity" type="number" min="1" required
                               class="rounded-md border-gray-300 md:col-span-2" placeholder="{{ __('Qty') }}">
                        <input :name="`items[${index}][unit_cost]`" x-model="row.unit_cost" type="number" min="0" step="0.01" required
                               class="rounded-md border-gray-300 md:col-span-4" placeholder="{{ __('Unit cost') }}">
                        <button type="button" @click="removeRow(row.key)"
                                class="text-red-600 md:col-span-1"
                                :aria-label="'{{ __('Remove item') }}'">&times;</button>
                    </div>
                </template>
            </div>
        </div>
        <div class="grid gap-4 rounded-lg bg-white p-6 shadow sm:grid-cols-4">
            {{-- These three were never bound to Alpine, so discountValue/taxValue/
                 shippingValue stayed 0 and the running total silently ignored every
                 amount typed here while still submitting them. --}}
            <div>
                <x-input-label for="discount" :value="__('Discount (Rp)')" />
                <input id="discount" name="discount" type="number" min="0" step="0.01" x-model="discountValue"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                       placeholder="{{ __('e.g. 10000') }}">
            </div>
            <div>
                <x-input-label for="tax" :value="__('Tax (Rp)')" />
                <input id="tax" name="tax" type="number" min="0" step="0.01" x-model="taxValue"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                       placeholder="{{ __('e.g. 2500') }}">
            </div>
            <div>
                <x-input-label for="shipping" :value="__('Shipping (Rp)')" />
                <input id="shipping" name="shipping" type="number" min="0" step="0.01" x-model="shippingValue"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                       placeholder="{{ __('e.g. 15000') }}">
            </div>

            <div class="flex items-end font-semibold">
                {{ __('Total') }}:
                <span class="ml-2" x-text="money(total)"></span>
            </div>

            <div class="sm:col-span-4">
                <x-input-label for="notes" :value="__('Notes')" />
                <textarea id="notes" name="notes" rows="2" placeholder="{{ __('e.g. Supplier invoice number') }}"
                          class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes', $purchase->notes) }}</textarea>
                <x-input-error :messages="$errors->get('notes')" class="mt-2" />
            </div>
        </div>

        {{-- Same submit row as sales/create: the primary action plus an explicit cancel. --}}
        <div class="flex items-center gap-3">
            {{-- Busy label + spinner copied from sales/create. "Saving…" is the project's
                 established busy wording (the global submit loader's default, pinned by
                 SubmitLoadingTest), so this button reads the same as every other form. --}}
            <x-primary-button x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                <span x-show="!submitting">{{ $purchase->exists ? __('Update purchase') : __('Create purchase') }}</span>
                <span x-show="submitting" style="display: none;" class="inline-flex items-center gap-2">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
                    </svg>
                    {{ __('Saving…') }}
                </span>
            </x-primary-button>
            <a href="{{ route('purchases.index') }}" class="text-sm text-gray-600 underline">{{ __('Cancel') }}</a>
        </div>
    </form>
    <script>
        window.purchaseForm = function (config) {
            return {
                products: config.products || [],
                items: [],
                nextKey: 1,
                submitting: false,
                discountValue: Number(config.discount) || 0,
                taxValue: Number(config.tax) || 0,
                shippingValue: Number(config.shipping) || 0,

                /**
                 * Rebuild the rows the user had already entered.
                 *
                 * This one path serves both cases the page must handle: an edit form
                 * rendering its saved items, and a rejected submit coming back from the
                 * server. Without it a failed save returned an empty grid and the user
                 * silently lost everything they had typed.
                 */
                init() {
                    const previous = Array.isArray(config.items) ? config.items : null;

                    if (previous && previous.length) {
                        previous.forEach((item) => this.items.push({
                            key: this.nextKey++,
                            product_id: String(item.product_id ?? ''),
                            quantity: String(item.quantity ?? 1),
                            unit_cost: String(item.unit_cost ?? ''),
                        }));
                    }

                    // A create form with nothing remembered still needs one row, otherwise
                    // the required "Items *" rule is unmeetable and there is no field to fill.
                    if (this.items.length === 0) {
                        this.addRow();
                    }

                    // Back/Forward hands the DOM back with the busy state still applied.
                    window.addEventListener('pageshow', () => {
                        this.submitting = false;
                    });

                    // Safety net, identical to sales/create and to the global submit
                    // loader: a request that never navigates (offline, blocked by the
                    // browser, a stalled connection) would otherwise leave "Saving…" and a
                    // disabled button on screen permanently.
                    this.$watch('submitting', (busy) => {
                        if (!busy) {
                            return;
                        }

                        setTimeout(() => {
                            this.submitting = false;
                        }, 20000);
                    });
                },

                addRow() {
                    this.items.push({ key: this.nextKey++, product_id: '', quantity: 1, unit_cost: '' });
                },

                removeRow(key) {
                    // Keep one row so the form stays submittable rather than becoming a
                    // dead end where the user has nothing to fill in.
                    if (this.items.length === 1) {
                        this.items[0] = { key: this.nextKey++, product_id: '', quantity: 1, unit_cost: '' };
                        return;
                    }

                    this.items = this.items.filter((row) => row.key !== key);
                },

                get subtotal() {
                    return this.items.reduce(
                        (sum, row) => sum + (Number(row.quantity) || 0) * (Number(row.unit_cost) || 0),
                        0
                    );
                },

                get discount() {
                    return Math.min(this.subtotal, Number(this.discountValue) || 0);
                },

                get total() {
                    return Math.max(0, this.subtotal - this.discount)
                        + (Number(this.taxValue) || 0)
                        + (Number(this.shippingValue) || 0);
                },

                money(value) {
                    return 'Rp ' + Number(value || 0).toLocaleString('id-ID');
                },
            };
        };
    </script>
    </div>
</x-app-layout>
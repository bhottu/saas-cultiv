@php
    // Entitlements are grouped so the form reads as two distinct things: ceilings
    // (a number, or blank for unlimited) and capabilities (on/off). Flattening them into
    // one undifferentiated list of inputs is how an admin ends up unsure whether a field
    // wants 15 or true.
    $numericKeys = ['max_workspaces', 'max_users', 'max_products', 'max_customers', 'max_storage_mb', 'max_api_calls'];
    $flagKeys = ['basic_sales', 'basic_stock', 'basic_purchases', 'basic_reports', 'advanced_reports', 'advanced_permissions', 'advanced_analytics', 'api_access', 'audit_log'];
    $labels = [
        'max_workspaces' => __('Workspaces'), 'max_users' => __('Users'), 'max_products' => __('Products'),
        'max_customers' => __('Customers'), 'max_storage_mb' => __('Storage (MB)'), 'max_api_calls' => __('API calls per month'),
        'basic_sales' => __('Sales'), 'basic_stock' => __('Inventory'), 'basic_purchases' => __('Purchasing'),
        'basic_reports' => __('Standard reports'), 'advanced_reports' => __('Advanced reports'),
        'advanced_permissions' => __('Advanced permissions'), 'advanced_analytics' => __('Advanced analytics'),
        'api_access' => __('API access'), 'audit_log' => __('Audit log'),
    ];
    // Only render what this plan actually carries, so the form never invents a limit the
    // plan never had and never hides one it does.
    $current = $plan->entitlements ?? [];
@endphp

<x-admin-shell :title="__('Edit plan')"
               :subtitle="__('Pricing, limits and capabilities for every workspace on this plan.')"
               :editable="true">
    <form method="POST" action="{{ route('admin.plans.update', $plan) }}" class="space-y-6">
        @csrf
        @method('PUT')

        {{-- Identity. The slug is shown but not editable: it is the key billing and the
             module gate resolve a plan by, so renaming it would break live checkouts. --}}
        <section class="rounded-lg bg-white p-6 shadow">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ __('Plan') }}</h3>

            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <div>
                    <x-input-label for="name" :value="__('Plan Name')" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" required
                                  maxlength="60" :value="old('name', $plan->name)" />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="slug" :value="__('Internal Identifier')" />
                    <x-text-input id="slug" type="text" class="mt-1 block w-full bg-gray-50" disabled
                                  :value="$plan->slug" />
                    <p class="mt-1 text-xs text-gray-500">
                        {{ __('Used by billing and module gating. Renaming it would break existing subscriptions, so it stays fixed.') }}
                    </p>
                </div>

                <div class="md:col-span-2">
                    <x-input-label for="description" :value="__('Description')" />
                    <textarea id="description" name="description" rows="2" maxlength="500"
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $plan->description) }}</textarea>
                    <x-input-error :messages="$errors->get('description')" class="mt-1" />
                </div>
            </div>
        </section>

        {{-- ------------------------------------------------------- Pricing --}}
        <section class="rounded-lg bg-white p-6 shadow">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ __('Pricing') }}</h3>
            <p class="mt-1 text-sm text-gray-500">
                {{ __('Enter plain numbers. They are formatted for display automatically.') }}
            </p>

            <div class="mt-5 grid gap-5 md:grid-cols-3">
                <div>
                    <x-input-label for="price_monthly" :value="__('Monthly Price')" />
                    <x-text-input id="price_monthly" name="price_monthly" type="number" inputmode="numeric"
                                  class="mt-1 block w-full" required min="0" step="1"
                                  :value="old('price_monthly', $plan->price_monthly)" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('Stored as an integer, e.g. 25000 for Rp25.000.') }}</p>
                    <x-input-error :messages="$errors->get('price_monthly')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="price_yearly" :value="__('Yearly Price')" />
                    <x-text-input id="price_yearly" name="price_yearly" type="number" inputmode="numeric"
                                  class="mt-1 block w-full" min="0" step="1"
                                  :value="old('price_yearly', $plan->price_yearly)" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('Leave blank when the plan is monthly only.') }}</p>
                    <x-input-error :messages="$errors->get('price_yearly')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="currency" :value="__('Currency')" />
                    <x-text-input id="currency" name="currency" type="text" class="mt-1 block w-full uppercase" maxlength="3"
                                  :value="old('currency', $plan->currency ?: 'IDR')" />
                </div>

                <div>
                    <x-input-label for="sort_order" :value="__('Display Order')" />
                    <x-text-input id="sort_order" name="sort_order" type="number" class="mt-1 block w-full" required min="0"
                                  :value="old('sort_order', $plan->sort_order)" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('Lower numbers appear first, on the pricing page and here.') }}</p>
                    <x-input-error :messages="$errors->get('sort_order')" class="mt-1" />
                </div>

                <div class="md:col-span-2">
                    <x-input-label for="features" :value="__('Features')" />
                    <textarea id="features" name="features" rows="6" maxlength="4000"
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('features', implode("\n", $plan->features ?? [])) }}</textarea>
                    <p class="mt-1 text-xs text-gray-500">{{ __('One bullet per line, as shown on the pricing card.') }}</p>
                    <x-input-error :messages="$errors->get('features')" class="mt-1" />
                </div>
            </div>
        </section>

        {{-- ------------------------------------------------ Limits & capabilities --}}
        <section class="rounded-lg bg-white p-6 shadow">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ __('Limits & Capabilities') }}</h3>
            <p class="mt-1 text-sm text-gray-500">
                {{ __('Leave a limit blank for unlimited. These decide what workspaces on this plan can actually do.') }}
            </p>

            <div class="mt-5">
                <h4 class="text-sm font-medium text-gray-900">{{ __('Limits') }}</h4>
                <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($numericKeys as $key)
                        @if (array_key_exists($key, $current))
                            <div>
                                <x-input-label :for="'entitlement-'.$key" :value="$labels[$key]" />
                                <x-text-input :id="'entitlement-'.$key" :name="'entitlements['.$key.']'"
                                              type="number" inputmode="numeric" min="0" step="1"
                                              class="mt-1 block w-full"
                                              :value="old('entitlements.'.$key, $current[$key])"
                                              placeholder="{{ __('Unlimited') }}" />
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="mt-6 border-t border-gray-100 pt-5">
                <h4 class="text-sm font-medium text-gray-900">{{ __('Capabilities') }}</h4>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($flagKeys as $key)
                        @if (array_key_exists($key, $current))
                            {{-- A hidden input paired with the checkbox: an unticked box is
                                 absent from the payload, and mergeEntitlements() keeps the
                                 stored value for any key it does not receive. --}}
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="hidden" name="entitlements[{{ $key }}]" value="0">
                                <input type="checkbox" name="entitlements[{{ $key }}]" value="1"
                                       @checked(old('entitlements.'.$key, (bool) $current[$key]))
                                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                {{ $labels[$key] }}
                            </label>
                        @endif
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ------------------------------------------------------ Availability --}}
        <section class="rounded-lg bg-white p-6 shadow">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-700">{{ __('Availability') }}</h3>

            <div class="mt-4 space-y-3">
                <label class="flex items-start gap-3">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $plan->is_active))
                           class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    <span>
                        <span class="block text-sm font-medium text-gray-900">{{ __('Active') }}</span>
                        <span class="mt-1 block text-sm text-gray-500">{{ __('Inactive plans are hidden from the pricing page and cannot be bought.') }}</span>
                    </span>
                </label>

                <label class="flex items-start gap-3">
                    <input type="hidden" name="is_free_tier" value="0">
                    <input type="checkbox" name="is_free_tier" value="1" @checked(old('is_free_tier', $plan->is_free_tier))
                           class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    <span>
                        <span class="block text-sm font-medium text-gray-900">{{ __('Free tier') }}</span>
                        <span class="mt-1 block text-sm text-gray-500">{{ __('The entry plan a new workspace can start on.') }}</span>
                    </span>
                </label>
            </div>

            {{-- Deactivating a plan that live subscriptions still point at leaves those
                 workspaces on a plan they can no longer renew or upgrade from. The save is
                 NOT blocked — an admin may legitimately retire a plan — but the count is
                 stated before they click, not discovered afterwards. --}}
            {{-- A plain interpolated count rather than trans_choice: this project has no
                 plural ("|") keys anywhere, and introducing one into a JSON-flattened
                 translation file is a format nothing else here produces or verifies. --}}
            @if ($activeSubscriptions > 0)
                <p class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                    {{ __(':count workspace(s) currently use this plan. Deactivating it stops new purchases but does not change existing subscriptions.', ['count' => number_format($activeSubscriptions)]) }}
                </p>
            @endif
        </section>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('admin.plans.index') }}"
               class="inline-flex items-center rounded-lg bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50">
                {{ __('Back to plans') }}
            </a>
            <x-primary-button type="submit">{{ __('Save changes') }}</x-primary-button>
        </div>
    </form>
</x-admin-shell>
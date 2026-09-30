<x-admin-shell :title="__('Plans')" :subtitle="__('Pricing and entitlements, read straight from the plans table.')">
    <x-admin-table :paginator="null" :empty="__('No plans configured.')">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">{{ __('Plan') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Monthly') }}</th>
                <th class="px-4 py-3">{{ __('Workspaces') }}</th>
                <th class="px-4 py-3">{{ __('Users') }}</th>
                <th class="px-4 py-3">{{ __('Products') }}</th>
                <th class="px-4 py-3">{{ __('Modules') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Workspaces on plan') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($plans as $row)
                @php $plan = $row['plan']; @endphp
                <tr>
                    <td class="px-4 py-3">
                        <span class="font-medium text-gray-900">{{ $plan->name }}</span>
                        @if (! $plan->is_active)
                            <span class="ml-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ __('inactive') }}</span>
                        @endif
                        <div class="text-xs text-gray-500">{{ $plan->description }}</div>
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 text-right">{{ \App\Services\Money::formatRupiah($plan->price_monthly) }}</td>
                    <td class="px-4 py-3">{{ $plan->displayLimit('max_workspaces') }}</td>
                    <td class="px-4 py-3">{{ $plan->displayLimit('max_users') }}</td>
                    <td class="px-4 py-3">{{ $plan->displayLimit('max_products') }}</td>
                    {{-- Modules are not plan entitlements: they carry their own
                         min_plan gate. Read from the same catalogue the pricing cards
                         use, so admin and marketing never disagree. --}}
                    <td class="px-4 py-3">
                        @php
                            $adminModules = \App\Models\Module::query()->available()->ordered()->get()
                                ->filter(function (\App\Models\Module $module) use ($plan) {
                                    if (! $module->min_plan) {
                                        return true;
                                    }

                                    $required = \App\Models\Plan::query()->where('slug', $module->min_plan)->first();

                                    return $required && $plan->sort_order >= $required->sort_order;
                                })
                                ->pluck('name');
                        @endphp
                        @forelse ($adminModules as $moduleName)
                            <span class="mr-1.5 inline-block text-xs text-gray-600">{{ $moduleName }}</span>
                        @empty
                            <span class="text-xs text-gray-400">&mdash;</span>
                        @endforelse
                    </td>
                    <td class="px-4 py-3 text-right">{{ number_format($row['workspaces']) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-gray-500">{{ __('No plans configured.') }}</td></tr>
            @endforelse
        </tbody>
    </x-admin-table>
</x-admin-shell>
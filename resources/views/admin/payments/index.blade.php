<x-admin-shell :title="__('Payments')" :subtitle="__('QRIS subscription payments. Metadata only — no credentials are shown.')">
    <x-admin-filters :submit="route('admin.payments.index')" :columns="4">
        <div>
            <x-input-label for="search" :value="__('Search')" />
            <x-text-input id="search" name="search" class="mt-1 block w-full"
                          :value="$filters['search'] ?? ''" :placeholder="__('Order or transaction id')" />
        </div>
        <div>
            <x-input-label for="tenant_id" :value="__('Workspace')" />
            <select id="tenant_id" name="tenant_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('All workspaces') }}</option>
                @foreach ($workspaces as $workspace)
                    <option value="{{ $workspace->id }}" @selected((string) ($filters['tenant_id'] ?? '') === (string) $workspace->id)>
                        {{ $workspace->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="status" :value="__('Status')" />
            <select id="status" name="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="provider" :value="__('Provider')" />
            <select id="provider" name="provider" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                <option value="">{{ __('All providers') }}</option>
                <option value="qrispw" @selected(($filters['provider'] ?? '') === 'qrispw')>QRIS.PW</option>
                <option value="kasera" @selected(($filters['provider'] ?? '') === 'kasera')>Kasera Pay</option>
            </select>
        </div>
    </x-admin-filters>

    <x-admin-table :paginator="$payments" :empty="__('No payments found.')">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">{{ __('Order id') }}</th>
                <th class="px-4 py-3">{{ __('Workspace') }}</th>
                <th class="px-4 py-3">{{ __('User') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Amount') }}</th>
                <th class="px-4 py-3">{{ __('Status') }}</th>
                <th class="px-4 py-3">{{ __('Provider') }}</th>
                <th class="px-4 py-3">{{ __('Paid at') }}</th>
                <th class="px-4 py-3">{{ __('Created') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($payments as $payment)
                <tr>
                    <td class="px-4 py-3 font-mono text-xs">{{ $payment->order_id }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $payment->tenant?->name ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $payment->user?->name ?? '—' }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ \App\Services\Money::formatRupiah($payment->amount) }}</td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2 py-0.5 text-xs
                            {{ $payment->status === 'paid' ? 'bg-green-100 text-green-700' : (in_array($payment->status, ['failed', 'expired'], true) ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700') }}">
                            {{ ucfirst($payment->status) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-600">{{ $payment->provider }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-gray-500">{{ $payment->paid_at?->format('d M Y H:i') ?? '—' }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-gray-500">{{ $payment->created_at?->format('d M Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-6 text-gray-500">{{ __('No payments found.') }}</td></tr>
            @endforelse
        </tbody>
    </x-admin-table>
</x-admin-shell>
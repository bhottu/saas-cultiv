<x-admin-shell :title="__('Module management')"
               :subtitle="__('Control which modules workspaces can see and use.')">
    <div class="space-y-5">
        @if (session('status'))
            <div class="rounded-lg border px-4 py-3 text-sm {{ (session('status.type') ?? 'success') === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">
                {{ session('status.message') }}
            </div>
        @endif

        <div class="admin-card overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Module') }}</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Status') }}</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($modules as $module)
                        <tr>
                            <td class="px-5 py-4">
                                <div class="font-medium text-gray-900">{{ __($module->name) }}</div>
                                <div class="mt-1 text-xs text-gray-500">v{{ $module->version }}</div>
                            </td>
                            <td class="px-5 py-4">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold
                                    {{ $module->availability_status === \App\Models\Module::STATUS_ACTIVE ? 'bg-emerald-50 text-emerald-700' : '' }}
                                    {{ $module->availability_status === \App\Models\Module::STATUS_HIDDEN ? 'bg-gray-100 text-gray-700' : '' }}
                                    {{ $module->availability_status === \App\Models\Module::STATUS_MAINTENANCE ? 'bg-amber-50 text-amber-700' : '' }}">
                                    {{ $module->availabilityStatusLabel() }}
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <form method="POST" action="{{ route('admin.modules.update', $module) }}" class="flex justify-end gap-2">
                                    @csrf
                                    @method('PUT')
                                    <label class="sr-only" for="module-status-{{ $module->id }}">{{ __('Status') }}</label>
                                    <select id="module-status-{{ $module->id }}" name="availability_status"
                                            class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        @foreach ($statuses as $status)
                                            <option value="{{ $status }}" @selected(old('availability_status', $module->availability_status) === $status)>
                                                {{ $status === 'active' ? __('Available') : ($status === 'hidden' ? __('Hidden') : __('Under maintenance')) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                                        {{ __('Save') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-admin-shell>

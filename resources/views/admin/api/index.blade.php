<x-admin-shell :title="__('API Access')"
               :subtitle="__('Control which Cultiv One resources can be accessed through the API.')"
               :editable="true">
    <div class="space-y-5">
        <div class="admin-card space-y-1.5 text-sm text-gray-600">
            <p>{{ __('Read allows GET requests for the resource. Write allows POST, PUT and PATCH.') }}</p>
            <p>{{ __('Delete allows DELETE for resources that support removing data.') }}</p>
            <p>{{ __('Write requires Read: enabling Write turns Read on automatically.') }}</p>
            <p class="text-xs text-gray-500">{{ __('These toggles are platform-wide. A workspace can only grant API token scopes you enable here.') }}</p>
        </div>

        {{-- One form for the whole matrix: what is submitted is exactly what the server
             stores, and unchecking a capability that is currently on asks for
             confirmation because existing integrations may break immediately. --}}
        <form method="POST" action="{{ route('admin.api.update') }}" x-data>
            @csrf
            @method('PUT')

            <div class="admin-card overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Resource') }}</th>
                            <th class="px-5 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Read') }}</th>
                            <th class="px-5 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Write') }}</th>
                            <th class="px-5 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Delete') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($matrix as $row)
                            @php $key = $row['resource']; @endphp
                            <tr>
                                <td class="px-5 py-4 font-medium text-gray-900">{{ __($row['label']) }}</td>

                                <td class="px-5 py-4 text-center">
                                    <input type="checkbox"
                                           name="capabilities[{{ $key }}][read]" value="1"
                                           x-ref="read_{{ $key }}"
                                           @checked($row['read_enabled'])
                                           data-was-on="{{ $row['read_enabled'] ? '1' : '' }}"
                                           @change="if (!this.checked && this.dataset.wasOn && !confirm('{{ __('Turning this off may break existing integrations. Continue?') }}')) { this.checked = true; return; } if (!this.checked) { $refs.write_{{ $key }}.checked = false; }"
                                           class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                </td>

                                <td class="px-5 py-4 text-center">
                                    @if ($row['write_supported'])
                                        <input type="checkbox"
                                               name="capabilities[{{ $key }}][write]" value="1"
                                               x-ref="write_{{ $key }}"
                                               @checked($row['write_enabled'])
                                               data-was-on="{{ $row['write_enabled'] ? '1' : '' }}"
                                               @change="if (this.checked) { $refs.read_{{ $key }}.checked = true; } else if (this.dataset.wasOn && !confirm('{{ __('Turning this off may break existing integrations. Continue?') }}')) { this.checked = true; }"
                                               class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    @else
                                        {{-- No write endpoint exists for this resource in this
                                             build, so the capability cannot exist either — shown
                                             as unavailable rather than as a switch that lies. --}}
                                        <span aria-hidden="true" class="text-gray-300">&mdash;</span>
                                        <span class="sr-only">{{ __('Not available') }}</span>
                                    @endif
                                </td>

                                <td class="px-5 py-4 text-center">
                                    @if ($row['delete_supported'])
                                        <input type="checkbox"
                                               name="capabilities[{{ $key }}][delete]" value="1"
                                               x-ref="delete_{{ $key }}"
                                               @checked($row['delete_enabled'])
                                               data-was-on="{{ $row['delete_enabled'] ? '1' : '' }}"
                                               @change="if (this.checked) { $refs.read_{{ $key }}.checked = true; $refs.write_{{ $key }}.checked = true; } else if (this.dataset.wasOn && !confirm('{{ __('Turning this off may break existing integrations. Continue?') }}')) { this.checked = true; }"
                                               class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    @else
                                        {{-- This resource has no DELETE endpoint (sales, stock and
                                             payment records are historical), so no checkbox is
                                             offered rather than one that would do nothing. --}}
                                        <span aria-hidden="true" class="text-gray-300">&mdash;</span>
                                        <span class="sr-only">{{ __('Not available') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex justify-end">
                <button type="submit"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    {{ __('Save') }}
                </button>
            </div>
        </form>
    </div>
</x-admin-shell>
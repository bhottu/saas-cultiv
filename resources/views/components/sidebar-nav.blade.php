@props(['groups' => []])

<ul role="list" class="space-y-6">
    @foreach ($groups as $group)
        @if (! empty($group['items']))
            <li>
                <div class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-gray-400">
                    {{ $group['label'] }}
                </div>

                <ul role="list" class="space-y-1">
                    @foreach ($group['items'] as $item)
                        <li>
                            <x-sidebar-link :href="$item['href']"
                                            :active="$item['active'] ?? false"
                                            :icon="$item['icon'] ?? null">
                                {{ $item['label'] }}
                            </x-sidebar-link>
                        </li>
                    @endforeach
                </ul>
            </li>
        @endif
    @endforeach
</ul>
@props(['groups' => []])

<ul role="list" class="space-y-1">
    @foreach ($groups as $group)
        @if (! empty($group['items']))
            @if (count($group['items']) > 1 && ($group['collapsible'] ?? true))
                {{-- Collapsible navigation group --}}
                <li x-data="{
                    expanded: {{ $group['autoExpand'] ?? false ? 'true' : 'false' }},
                    init() {
                        if (!this.expanded) {
                            const activeLink = this.$el.querySelector('[aria-current=page]');
                            if (activeLink) this.expanded = true;
                        }
                    }
                }">
                    {{-- Group header: toggle button with icon, label and chevron. The
                         icon uses the same h-5 size and gap-3 as the sidebar-link
                         items, so the label lines up exactly with Dasbor. The chevron
                         is an inline copy of the same heroicons chevron-down path the
                         nav-icon registry ships — x-nav-icon always merges its h-5
                         default into class, so a smaller size passed to it loses the
                         Tailwind cascade. The full button row stays the touch
                         target; only the drawn glyph is smaller. --}}
                    <button type="button"
                            @@click.stop="expanded = !expanded"
                            @@keydown.enter.prevent="expanded = !expanded"
                            @@keydown.space.prevent="expanded = !expanded"
                            x-bind:aria-expanded="expanded ? 'true' : 'false'"
                            aria-controls="nav-group-{{ $loop->index }}"
                            class="flex w-full items-center gap-3 px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 rounded-lg transition-colors duration-150">
                        @if (! empty($group['icon']))
                            <x-nav-icon :name="$group['icon']" class="h-5 w-5 shrink-0" />
                        @endif
                        <span class="flex-1 text-left">{{ $group['label'] }}</span>
                        <span x-bind:class="expanded ? 'rotate-180' : ''" class="transition-transform duration-200">
                            <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </span>
                    </button>

                    {{-- Collapsible items --}}
                    <ul id="nav-group-{{ $loop->index }}"
                        role="list"
                        class="space-y-0.5 mt-0.5 ml-3"
                        x-show="expanded"
                        x-cloak
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 -translate-y-1">
                        @foreach ($group['items'] as $item)
                            <li>
                                <x-sidebar-link :href="$item['href']"
                                                :active="$item['active'] ?? false"
                                                :icon="$item['icon'] ?? null"
                                                :locked="$item['locked'] ?? false"
                                                :lock-title="$item['lock-title'] ?? null">
                                    {{ $item['label'] }}
                                </x-sidebar-link>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @else
                {{-- Single-item or non-collapsible group --}}
                <li>
                    <div class="px-3 pb-1 pt-2 text-xs font-semibold uppercase tracking-wider text-gray-400">
                        {{ $group['label'] }}
                    </div>
                    <ul role="list" class="space-y-0.5">
                        @foreach ($group['items'] as $item)
                            <li>
                                <x-sidebar-link :href="$item['href']"
                                                :active="$item['active'] ?? false"
                                                :icon="$item['icon'] ?? null"
                                                :locked="$item['locked'] ?? false"
                                                :lock-title="$item['lock-title'] ?? null">
                                    {{ $item['label'] }}
                                </x-sidebar-link>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @endif
        @endif
    @endforeach
</ul>
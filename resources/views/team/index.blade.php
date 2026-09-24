<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Team') }}</h2>
    </x-slot>

    <div class="py-12 max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if (session('success'))
            <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('success') }}</div>
        @endif

        {{-- Seats --}}
        <div class="bg-white rounded-lg shadow p-4 text-sm flex justify-between items-center">
            <span class="font-semibold">Seats</span>
            <span>{{ $activeCount }} / {{ $seatLimit ?? '∞' }} active
                @if ($seatLimit !== null && $activeCount >= $seatLimit)
                    <span class="text-red-600">— limit reached, <a class="underline" href="{{ route('billing.index') }}">upgrade</a> to add more</span>
                @endif
            </span>
        </div>

        {{-- Invite form --}}
        @if ($canManageUsers)
            <form method="POST" action="{{ route('team.invite') }}" class="bg-white shadow rounded-lg p-6 flex flex-wrap gap-3 items-start">
                @csrf
                <div class="flex-1 min-w-[220px]">
                    <input name="email" type="email" required placeholder="user@example.com (must already be registered)"
                           class="w-full border-gray-300 rounded-lg shadow-sm text-sm">
                    @error('email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <select name="role" class="border-gray-300 rounded-lg shadow-sm text-sm">
                    @foreach (array_slice($roles, 1) as $role) {{-- skip Owner --}}
                        <option value="{{ $role }}" {{ $role === 'Staff' ? 'selected' : '' }}>{{ $role }}</option>
                    @endforeach
                </select>
                <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">Invite</button>
            </form>
        @endif

        {{-- Members --}}
        <div class="bg-white shadow rounded-lg divide-y">
            @forelse ($members as $membership)
                <div class="p-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="font-semibold flex items-center gap-2">
                            {{ $membership->user->name }}
                            @if ($membership->status === 'invited')
                                <span class="text-xs px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700">invited</span>
                            @endif
                        </div>
                        <div class="text-xs text-gray-500">{{ $membership->user->email }}
                            @if ($membership->status === 'active' && $membership->joined_at)
                                · joined {{ $membership->joined_at->format('d M Y') }}
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        @if ($canManageRoles && $membership->role !== 'Owner' && $membership->status === 'active')
                            <form method="POST" action="{{ route('team.role', $membership) }}" class="flex gap-2">
                                @csrf
                                @method('PATCH')
                                <select name="role" class="border-gray-300 rounded text-sm">
                                    @foreach (array_slice($roles, 1) as $role)
                                        <option value="{{ $role }}" {{ $membership->role === $role ? 'selected' : '' }}>{{ $role }}</option>
                                    @endforeach
                                </select>
                                <button class="px-3 py-1.5 bg-gray-800 text-white rounded text-sm">Save</button>
                            </form>
                        @else
                            <span class="text-sm font-medium {{ $membership->role === 'Owner' ? 'text-indigo-600' : '' }}">{{ $membership->role }}</span>
                        @endif

                        @if ($canManageUsers && $membership->role !== 'Owner')
                            <form method="POST" action="{{ route('team.remove', $membership) }}"
                                  onsubmit="return confirm('Remove this member?')">
                                @csrf
                                @method('DELETE')
                                <button class="text-red-600 text-sm underline">Remove</button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <div class="p-6 text-gray-500">No members yet.</div>
            @endforelse
        </div>
    </div>
</x-app-layout>

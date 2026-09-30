<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Team') }}</h2>
    </x-slot>

    <div class="py-12 max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if (session('success'))
            <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('success') }}</div>
        @endif

        {{-- Invitations addressed to the signed-in user (recipient-only, server-checked). --}}
        <x-pending-invitations :invitations="$pendingInvitations" />

        @if ($showWorkspace)
        {{-- Seats --}}
        <div class="bg-white rounded-lg shadow p-4 text-sm flex justify-between items-center">
            <span class="font-semibold">Seats</span>
            <span>
                {{ $occupiedCount }} / {{ $seatLimit ?? '∞' }} seats
                @if ($seatLimit !== null && $occupiedCount >= $seatLimit)
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
                            {{-- The workspace must never be left without an Owner, so the
                                 Owner row never renders this control (and the endpoint
                                 refuses it server-side regardless). --}}
                            <button type="button" x-data
                                    x-on:click.prevent="$dispatch('open-modal', 'confirm-member-removal-{{ $membership->id }}')"
                                    class="text-red-600 text-sm underline">
                                {{ __('Remove') }}
                            </button>

                            <x-modal name="confirm-member-removal-{{ $membership->id }}" maxWidth="md">
                                <form method="POST" action="{{ route('team.remove', $membership) }}" class="p-6">
                                    @csrf
                                    @method('DELETE')

                                    <h2 class="text-lg font-medium text-gray-900">
                                        {{ __('Remove this team member?') }}
                                    </h2>

                                    <p class="mt-2 text-sm text-gray-600">
                                        {{ __('This user will no longer have access to this workspace.') }}
                                    </p>

                                    <div class="mt-6 flex justify-end gap-3">
                                        <x-secondary-button x-on:click="$dispatch('close')">
                                            {{ __('Cancel') }}
                                        </x-secondary-button>

                                        <x-danger-button class="ms-3" data-busy-label="{{ __('Removing…') }}">
                                            {{ __('Remove') }}
                                        </x-danger-button>
                                    </div>
                                </form>
                            </x-modal>
                        @endif
                    </div>
                </div>
            @empty
                <div class="p-6 text-gray-500">No members yet.</div>
            @endforelse
        </div>

        {{-- Removed members: a revoked membership is kept as history, so the person can
             be seen and restored instead of vanishing. The account was never deleted;
             only the membership was. The restore endpoint re-checks seats server-side. --}}
        @if ($removedMembers->isNotEmpty())
            <div class="bg-white shadow rounded-lg" data-testid="removed-members">
                <div class="border-b p-4">
                    <h3 class="font-semibold text-gray-900">{{ __('Removed members') }}</h3>
                    <p class="mt-0.5 text-xs text-gray-500">
                        {{ __('Their accounts still exist — removing a member never deletes the user.') }}
                    </p>
                </div>

                <div class="divide-y">
                    @foreach ($removedMembers as $membership)
                        <div class="flex flex-wrap items-center justify-between gap-3 p-4">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 font-semibold">
                                    {{ $membership->user?->name ?? __('Deleted account') }}
                                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">{{ __('removed') }}</span>
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ $membership->user?->email }}
                                    · {{ $membership->role }}
                                    @if ($membership->deleted_at)
                                        · {{ __('removed on :date', ['date' => $membership->deleted_at->format('d M Y')]) }}
                                    @endif
                                </div>
                            </div>

                            @if ($canManageUsers)
                                {{-- Restore revives the soft-deleted row; the permanent
                                     delete below erases it. Both act on the MEMBERSHIP,
                                     never on the user account. --}}
                                <div class="flex flex-wrap items-center gap-2">
                                    <form method="POST" action="{{ route('team.restore', $membership) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button data-busy-label="{{ __('Restoring…') }}"
                                                class="rounded border border-indigo-300 px-3 py-1.5 text-sm text-indigo-700 hover:bg-indigo-50">
                                            {{ __('Restore') }}
                                        </button>
                                    </form>

                                    {{-- Owner only: forgetting a membership is not a
                                         team-management decision an Admin should make, so
                                         the button (and the endpoint) are stricter than
                                         the Restore control next to it. --}}
                                    @if ($isWorkspaceOwner)
                                        <button type="button" x-data
                                                data-testid="force-delete-member-{{ $membership->id }}"
                                                x-on:click.prevent="$dispatch('open-modal', 'confirm-member-force-delete-{{ $membership->id }}')"
                                                class="rounded border border-red-300 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50">
                                            {{ __('Delete Permanently') }}
                                        </button>

                                        <x-modal name="confirm-member-force-delete-{{ $membership->id }}" maxWidth="md">
                                            <form method="POST" action="{{ route('team.force-destroy', $membership) }}" class="p-6">
                                                @csrf
                                                @method('DELETE')

                                                <h2 class="text-lg font-medium text-gray-900">
                                                    {{ __('Delete this membership permanently?') }}
                                                </h2>

                                                <p class="mt-2 text-sm text-gray-600">
                                                    {{ __('This removed member will disappear from this workspace\'s list and the membership history will be erased. This action cannot be undone.') }}
                                                </p>
                                                <p class="mt-1 text-sm text-gray-600">
                                                    {{ __('The user account itself is not deleted.') }}
                                                </p>

                                                <div class="mt-6 flex justify-end gap-3">
                                                    <x-secondary-button x-on:click="$dispatch('close')">
                                                        {{ __('Cancel') }}
                                                    </x-secondary-button>

                                                    <x-danger-button class="ms-3" data-busy-label="{{ __('Deleting…') }}">
                                                        {{ __('Delete Permanently') }}
                                                    </x-danger-button>
                                                </div>
                                            </form>
                                        </x-modal>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        @endif {{-- /showWorkspace --}}
    </div>
</x-app-layout>

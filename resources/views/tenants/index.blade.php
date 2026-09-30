<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Your Workspaces') }}</h2>
    </x-slot>

    <div class="py-12 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @php
            $workspaceLimitReached = $workspaceLimit !== null && $ownedCount >= $workspaceLimit;
            $remainingWorkspaces = $workspaceLimit === null ? null : max(0, $workspaceLimit - $ownedCount);
        @endphp

        @if (session('success'))
            <div class="rounded-lg bg-green-100 p-3 text-green-800">{{ session('success') }}</div>
        @endif

        {{--
            A revoked membership is NOT a broken account. Removing somebody from a team
            (or being removed) keeps the account alive, so the hub states plainly what
            happened instead of leaving the user on an unexplained 403.

            The notice is dismissible FOR GOOD: the close button posts to
            TenantController::dismissNotice, which stores the decision in
            dismissed_notifications. The controller has already filtered out the
            workspaces this account closed before, so this block only renders what is
            still worth reporting — a workspace revoked later raises a fresh notice.
        --}}
        @if ($revokedNotices->isNotEmpty())
            {{-- The close control lives IN the title row (right aligned) rather than
                 floating over the card, so it never reads as floating below the copy.
                 `data-busy-skip` hands the submit to the Alpine handler below instead of
                 the global submit-button listener, which would otherwise relabel this
                 icon-only button "Saving…" and blow it out of its round shape. --}}
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
                 data-testid="workspace-access-revoked"
                 x-data="workspaceRevokedNotice"
                 x-on:notice-closed.window="$el.remove()"
                 x-show="! gone"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 x-transition:leave-start.scale="100"
                 x-transition:leave-end.scale="98">

                <form method="POST" action="{{ route('tenants.notices.dismiss') }}"
                      data-busy-skip="1"
                      x-on:submit.prevent="close($el)"
                      data-testid="dismiss-revoked-notice-form">
                    @csrf
                    @foreach ($revokedNotices as $notice)
                        <input type="hidden" name="key[]" value="{{ $notice['key'] }}">
                    @endforeach

                    <div class="flex items-start justify-between gap-3">
                        <p class="font-semibold">{{ __('You no longer have access to your previous workspace.') }}</p>

                        <button type="submit"
                                class="-mr-1 -mt-1 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-amber-700 hover:bg-amber-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 disabled:cursor-not-allowed disabled:opacity-70"
                                :aria-label="closing ? @js(__('Closing…')) : @js(__('Dismiss this notice'))"
                                aria-label="{{ __('Dismiss this notice') }}"
                                data-testid="dismiss-revoked-notice">
                            <span x-show="! closing" x-cloak>
                                <x-nav-icon name="close" class="h-4 w-4" />
                            </span>
                            <span x-show="closing" x-cloak class="flex items-center gap-1">
                                <span class="busy-spinner" aria-hidden="true">
                                    <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"></path>
                                    </svg>
                                </span>
                                <span class="text-xs font-medium" data-testid="dismiss-closing-label">{{ __('Closing…') }}</span>
                            </span>
                        </button>
                    </div>

                    <p class="mt-1">{{ __('Your account is still active — only the workspace membership was removed.') }}</p>

                    {{-- Chevron instead of a bullet: these are the workspaces that are
                         gone, and the arrow reads as "used to be here" at a glance. --}}
                    <ul class="mt-2 space-y-1">
                        @foreach ($revokedNotices as $notice)
                            <li class="flex items-start gap-1.5">
                                <x-nav-icon name="chevron-right" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-500" />
                                <span class="min-w-0 break-words">{{ $notice['name'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </form>
            </div>
        @endif

        {{-- Invitations addressed to this account: the recipient can answer them from the
             hub, which is where an invitee without a workspace lands. --}}
        <x-pending-invitations :invitations="$pendingInvitations" />

        {{--
            The entry point is ALWAYS visible. At the limit it does not disappear — it
            opens the upgrade prompt instead, so the user still learns that more
            workspaces exist behind a plan upgrade. Backend re-checks the same
            entitlement on POST /tenants (UsageService::enforceWorkspaceCreation).
        --}}
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-gray-800">{{ __('Workspaces') }}</h3>
                <p class="text-sm text-gray-500">
                    {{ __(':used of :limit used', ['used' => $ownedCount, 'limit' => $workspaceLimit ?? __('Unlimited')]) }}
                    @if ($remainingWorkspaces !== null && $remainingWorkspaces > 0)
                        · {{ __(':count remaining', ['count' => $remainingWorkspaces]) }}
                    @endif
                </p>
            </div>

            <button type="button" x-data
                    @if ($workspaceLimitReached)
                        x-on:click.prevent="$dispatch('open-modal', 'workspace-limit')"
                    @else
                        x-on:click.prevent="$dispatch('open-modal', 'create-workspace')"
                    @endif
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                {{ __('Add Workspace') }}
            </button>
        </div>

        @if ($workspaceLimitReached)
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                <strong>Workspace limit reached.</strong>
                Your current plan includes {{ $workspaceLimit }} workspace. Existing workspaces are preserved.
                <a href="{{ route('billing.index') }}" class="ml-1 font-semibold underline">View Plans</a>
            </div>
        @endif

        {{--
            Quota available: the create form. `UsageService::enforceWorkspaceCreation`
            runs inside the POST transaction, so a stale limit here can never create a
            workspace that the plan does not allow.
        --}}
        <x-modal name="create-workspace" maxWidth="md" :show="$errors->has('name') && ! $workspaceLimitReached">
            <form method="POST" action="{{ route('tenants.store') }}" class="p-6">
                @csrf

                <h2 class="text-lg font-medium text-gray-900">{{ __('Create Workspace') }}</h2>
                <p class="mt-1 text-sm text-gray-600">
                    {{ __('Your plan allows :limit workspaces. :remaining still available.', ['limit' => $workspaceLimit ?? __('Unlimited'), 'remaining' => $remainingWorkspaces ?? 0]) }}
                </p>

                <div class="mt-4">
                    <x-input-label for="workspace-name" :value="__('Workspace name')" />
                    <x-text-input id="workspace-name" name="name" type="text" class="mt-1 block w-full"
                                  :value="old('name')" required maxlength="100"
                                  placeholder="New workspace name" />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                    <x-primary-button class="ms-3" data-busy-label="{{ __('Creating workspace…') }}">{{ __('Create Workspace') }}</x-primary-button>
                </div>
            </form>
        </x-modal>

        {{--
            Limit reached: nothing is written. The backend already refused the POST with
            `upgrade_required` in the session, so the prompt opens on the redirect too.
        --}}
        <x-modal name="workspace-limit" maxWidth="md" :show="$workspaceLimitReached && (bool) session('upgrade_required')">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900">{{ __('Workspace limit reached') }}</h2>
                <p class="mt-2 text-sm text-gray-600">
                    {{ __('Your current plan allows :limit workspace.', ['limit' => $workspaceLimit ?? 1]) }}
                </p>
                <p class="mt-1 text-sm text-gray-600">
                    {{ __('Upgrade your plan to create additional workspaces.') }}
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                    <a href="{{ route('billing.index') }}"
                       class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150 ms-3">
                        {{ __('View Plans') }}
                    </a>
                </div>
            </div>
        </x-modal>

        @if ($tenants->isEmpty())
            {{--
                Empty state: an account may legitimately have no workspace — it never
                joined one, it was removed from one, or the workspace was closed. That is
                a starting point, not an error, so the page stays usable: create a
                workspace, or wait for an invitation (rendered above when one is pending).
            --}}
            <div class="rounded-lg bg-white p-8 text-center shadow" data-testid="no-workspace">
                <h3 class="text-lg font-semibold text-gray-900">{{ __('No Workspace Available') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __("You don't currently have access to any workspace.") }}</p>

                <div class="mt-5 flex flex-col items-center gap-2">
                    <button type="button" x-data
                            @if ($workspaceLimitReached)
                                x-on:click.prevent="$dispatch('open-modal', 'workspace-limit')"
                            @else
                                x-on:click.prevent="$dispatch('open-modal', 'create-workspace')"
                            @endif
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                        {{ __('Create Workspace') }}
                    </button>

                    <span class="text-xs uppercase tracking-wide text-gray-400">{{ __('or') }}</span>
                    <span class="text-sm text-gray-500">{{ __('Waiting for invitation') }}</span>
                </div>
            </div>
        @else
        <div class="bg-white shadow rounded-lg divide-y">
            @foreach ($tenants as $tenant)
                @php $isOwner = $ownerTenantIds->contains($tenant->id); @endphp
                <div class="p-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="font-semibold">{{ $tenant->name }}</div>
                        <div class="text-xs text-gray-500">
                            Owner: {{ $tenant->owner->name }}
                            @unless ($isOwner)
                                <span class="ml-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-gray-600">Member</span>
                            @endunless
                        </div>
                    </div>

                    {{-- Open uses the existing switch endpoint; Edit/Delete are owner-only. --}}
                    <div class="flex flex-wrap items-center gap-2">
                        <form method="POST" action="{{ route('tenants.switch', $tenant) }}">
                            @csrf
                            <button data-busy-label="{{ __('Opening…') }}"
                                    class="rounded bg-indigo-600 px-3 py-1.5 text-sm text-white hover:bg-indigo-700">{{ __('Open') }}</button>
                        </form>

                        @if ($isOwner)
                            <a href="{{ route('tenants.edit', $tenant) }}"
                               class="rounded border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Edit</a>

                            <form method="POST" action="{{ route('tenants.destroy', $tenant) }}"
                                  onsubmit="return confirm('Delete workspace?\n\nYou are about to delete &quot;{{ $tenant->name }}&quot;.\n\nThe workspace is closed and scheduled for permanent removal after the retention window. Its data is not erased immediately.\n\nContinue?')">
                                @csrf
                                @method('DELETE')
                                <button data-busy-label="{{ __('Deleting…') }}"
                                        class="rounded border border-red-300 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50">{{ __('Delete') }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        @endif
    </div>
</x-app-layout>

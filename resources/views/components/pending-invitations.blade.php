@props(['invitations' => collect()])

{{-- Workspace invitations addressed to the signed-in user.
     The recipient-only checks (status + ownership) are enforced server-side in
     TeamController; these forms are the visible affordance, not the gate. --}}
@if ($invitations->isNotEmpty())
    <section aria-labelledby="pending-invitations-title"
             class="rounded-lg bg-white p-6 shadow"
             data-testid="pending-invitations">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 id="pending-invitations-title" class="font-semibold text-gray-900">
                {{ __('Pending Invitations') }}
            </h3>
            <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">
                {{ $invitations->count() }}
            </span>
        </div>

        <ul class="divide-y divide-gray-100 text-sm">
            @foreach ($invitations as $invitation)
                <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="text-gray-800">
                            {{ $invitation->invitedBy?->name ?? __('Someone') }}
                            {{ __('invited you to join') }}
                            <span class="font-semibold">“{{ $invitation->tenant?->name }}”</span>
                            {{ __('as') }} <span class="font-medium">{{ $invitation->role }}</span>.
                        </p>
                        <p class="mt-0.5 text-xs text-gray-400">
                            {{ __('Invitation pending — choose Accept or Reject to respond.') }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <form method="POST" action="{{ route('team.invitations.accept', $invitation) }}">
                            @csrf
                            <button type="submit"
                                    data-busy-label="{{ __('Accepting…') }}"
                                    class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                                {{ __('Accept') }}
                            </button>
                        </form>

                        <form method="POST" action="{{ route('team.invitations.reject', $invitation) }}">
                            @csrf
                            <button type="submit"
                                    data-busy-label="{{ __('Rejecting…') }}"
                                    class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                                {{ __('Reject') }}
                            </button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
@endif
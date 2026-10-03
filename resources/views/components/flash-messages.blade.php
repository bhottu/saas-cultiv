{{--
    The application's success / error banner.

    This is NOT a new notification system. Pages in this app already announce
    outcomes with a session flash and render it with exactly these classes:

        @if (session('success'))
            <div class="rounded-lg bg-green-100 p-3 text-green-800">{{ session('success') }}</div>
        @endif

    That markup was copy-pasted across a dozen pages, so two things went wrong: it
    could drift apart between pages, and a flash could only ever be one flat string,
    with no room for a heading. This component is that same banner written once. A
    plain string renders exactly as before, so every existing caller keeps working
    untouched; a flash may also be flashed as ['title' => ..., 'message' => ...] when
    the outcome deserves a heading.

    The flash is read from the session rather than passed in, so a page can drop this
    in place of its inline block with no other change.
--}}
@php
    $success = session('success');
    $error = session('error');

    // The verification outcome is pulled, not flashed, because it has to survive the
    // redirect chain for an account that has no workspace yet (link -> dashboard ->
    // workspace hub). pull() also forgets it, so this banner can appear exactly once:
    // a refresh arrives to find nothing, with no duplicate possible by construction.
    $verification = session()->pull('verification_notice');

    if (is_array($verification) && ($verification['type'] ?? 'success') === 'error') {
        $error = $error ?: $verification;
    } elseif ($verification) {
        $success = $success ?: $verification;
    }
@endphp

@if ($success)
    <div role="status" class="rounded-lg bg-green-100 p-3 text-green-800">
        @if (is_array($success))
            @if (! empty($success['title']))
                <p class="font-semibold">{{ $success['title'] }}</p>
            @endif
            @if (! empty($success['message']))
                <p class="{{ ! empty($success['title']) ? 'mt-1 text-sm' : '' }}">{{ $success['message'] }}</p>
            @endif
        @else
            {{ $success }}
        @endif
    </div>
@endif

@if ($error)
    <div role="alert" class="rounded-lg bg-red-100 p-3 text-red-800">
        @if (is_array($error))
            @if (! empty($error['title']))
                <p class="font-semibold">{{ $error['title'] }}</p>
            @endif
            @if (! empty($error['message']))
                <p class="{{ ! empty($error['title']) ? 'mt-1 text-sm' : '' }}">{{ $error['message'] }}</p>
            @endif
        @else
            {{ $error }}
        @endif
    </div>
@endif

/**
 * Global submit-button loading state.
 *
 * Cultiv One has a single, app-wide rule: any control that can write to the
 * database shows progress and refuses a second submission while its request is
 * in flight. Rather than annotating ~30 Blade forms by hand (and letting the next
 * new form forget it), this is one delegated listener that covers every form.
 *
 * Rules it satisfies:
 *  - fires the moment the form is actually submitted, not merely clicked;
 *  - runs AFTER the form's own onsubmit handler, so a cancelled `confirm()` leaves
 *    the button completely untouched — no spinner, no "Deleting…", no latched guard;
 *  - disables the button and swaps its label, so a duplicate row cannot be written;
 *  - puts the state back on `pageshow` (covers back/forward and failed navigations);
 *  - arms a safety timeout so a request that never resolves still frees the button;
 *  - never touches forms whose button Alpine already owns (`:disabled` bindings);
 *  - never suppresses native validation, CSRF, or the server-side rules.
 */
const BUSY_LABELS = [
    // Authentication first: a login form must never claim to be "Saving…".
    [/log ?in|sign ?in|masuk/i, 'Logging in…'],
    [/register|sign ?up|create account|daftar/i, 'Creating account…'],

    // Destructive / transfer actions.
    [/delet|remove|hapus/i, 'Deleting…'],
    [/confirm/i, 'Confirming…'],
    [/upload|unggah|attach/i, 'Uploading…'],
    [/generat|export|cetak|download/i, 'Generating…'],

    // Invitations and outbound messages.
    [/accept/i, 'Accepting…'],
    [/reject|decline/i, 'Rejecting…'],
    [/invite|send|kirim|resend|email password|reset link|submit order/i, 'Sending…'],

    // Sales and payments.
    [/record sale/i, 'Recording sale…'],
    [/complete sale/i, 'Completing sale…'],
    [/check ?out|payment|subscribe|bayar|checkout/i, 'Processing payment…'],
    [/process|complet|selesai/i, 'Processing…'],

    // Workspace lifecycle + settings writes: reuse the labels pinned by
    // SubmitLoadingTest so the spec table and the implementation cannot drift apart.
    // (Deletion is already covered by the destructive rule above; the explicit entries
    // keep the intent readable and let a blade pin an exact verb via data-busy-label.)
    [/create workspace/i, 'Creating workspace…'],
    [/update workspace/i, 'Updating…'],
    [/delete workspace/i, 'Deleting…'],
    [/save settings|save changes|update profile|save profile/i, 'Saving…'],
    // Editing an existing record reads as an update, not a save of something new.
    [/updat/i, 'Updating…'],
];

const DEFAULT_BUSY_LABEL = 'Saving…';

// Long enough for a slow upload on mobile, short enough that a dropped connection
// never leaves the UI looking frozen forever.
const SAFETY_TIMEOUT_MS = 20000;

function busyLabelFor(button) {
    if (button.dataset.busyLabel) {
        return button.dataset.busyLabel;
    }

    const text = (button.textContent || '').trim();

    return (BUSY_LABELS.find(([pattern]) => pattern.test(text)) || [null, DEFAULT_BUSY_LABEL])[1];
}

function submitButtonsOf(form) {
    return Array.from(
        form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]')
    ).filter((button) => !button.disabled);
}

function markBusy(button) {
    if (button.dataset.busyApplied === '1') {
        return;
    }

    button.dataset.busyApplied = '1';
    button.dataset.busyOriginal = button.dataset.busyLabel || (button.textContent || '').trim();

    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.dataset.busyText = busyLabelFor(button);

    // The spinner is a sibling so the original label stays restorable verbatim.
    const spinner = document.createElement('span');
    spinner.className = 'busy-spinner';
    spinner.setAttribute('aria-hidden', 'true');
    spinner.innerHTML =
        '<svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">' +
        '<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>' +
        '<path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"/>' +
        '</svg>';

    button.prepend(spinner);
    button.dataset.busySpinner = '1';

    if (button.tagName === 'BUTTON') {
        button.dataset.busyOriginalHtml = button.innerHTML;
        // One clear gap (Tailwind gap-1) between spinner and label, so the busy
        // state reads as "⟳ Saving…" and never as "⟳Saving…", on any button.
        button.innerHTML =
            '<span class="inline-flex items-center gap-1">' +
            spinner.outerHTML +
            '<span>' + button.dataset.busyText + '</span>' +
            '</span>';
    } else {
        button.value = button.dataset.busyText;
    }

    button.classList.add('opacity-70', 'cursor-not-allowed');

    lockSubmitter(button);

    // If the page never navigates (a failed fetch, a blocked request), free the
    // button again rather than stranding the user.
    window.setTimeout(() => releaseBusy(button), SAFETY_TIMEOUT_MS);
}

/**
 * Disables the submitter one task LATER than the click that submitted the form.
 *
 * This must not happen synchronously inside the submit listener. The HTML form
 * submission algorithm builds the form's entry list only AFTER the submit event
 * has finished dispatching, and a submitter that is `disabled` at that moment is
 * left out of the entry list — so its `name`/`value` pair never reaches the
 * server.
 *
 * That is not hypothetical: the billing "Continue payment" / "Cancel & create
 * new" buttons carry `name="intent"`, and disabling them in the capture-phase
 * listener stripped `intent` from the POST. The backend then rejected the
 * request as invalid, the customer was returned to /billing with the modal
 * already dismissed and nothing done, and the error was invisible because the
 * page renders no field for `intent`.
 *
 * One task is the smallest delay that lands after the entry list exists, while
 * still being far quicker than a human can click twice.
 */
function lockSubmitter(button) {
    window.setTimeout(() => {
        // The page may have navigated or errored out in the meantime; only keep
        // the lock while the button is still showing its busy state.
        if (button.dataset.busyApplied === '1') {
            button.disabled = true;
        }
    }, 0);
}

function releaseBusy(button) {
    if (button.dataset.busyApplied !== '1') {
        return;
    }

    delete button.dataset.busyApplied;
    delete button.dataset.busySpinner;
    delete button.dataset.busyText;

    button.disabled = false;
    button.removeAttribute('aria-busy');
    button.classList.remove('opacity-70', 'cursor-not-allowed');

    // Re-arm the form now that the button is usable again. Without this a form
    // whose request never navigated (a failed fetch, a blocked request) would be
    // permanently refused by the double-submit guard.
    if (button.form) {
        delete button.form.dataset.busySubmitted;
    }

    if (button.tagName === 'BUTTON' && button.dataset.busyOriginalHtml !== undefined) {
        button.innerHTML = button.dataset.busyOriginalHtml;
        delete button.dataset.busyOriginalHtml;
    } else if (button.tagName !== 'BUTTON') {
        button.value = button.dataset.busyOriginal || button.value;
    }
}

export function installSubmitLoading() {
    if (typeof document === 'undefined') {
        return;
    }

    // Bubble phase, NOT capture.
    //
    // It has to run AFTER the form's own onsubmit handler. Nineteen listing screens
    // guard a destructive action with `onsubmit="return confirm('…')"`, and `confirm`
    // runs at the TARGET phase — before any document-level bubble listener.
    //
    // When this listener used to be registered with `true` (capture), it marked the
    // button busy *before* confirm() had been asked anything. Pressing Cancel then
    // closed the dialog and left the button spinning on "Deleting…" forever, because
    // releaseBusy() only ever runs on `pageshow` — i.e. after a real navigation that
    // was never going to happen. Worse, it also latched `busySubmitted`, so the *next*
    // genuine confirm+submit was swallowed by the double-submit guard and the record
    // could never be deleted at all.
    //
    // Running in the bubble phase means defaultPrevented already reflects the user's
    // answer, and a cancelled confirmation touches no state whatsoever.
    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || form.dataset.busySkip === '1') {
            return;
        }

        // A GET form only reads (search, filters, exports of a listing). Reporting
        // "Saving…" there would be a lie about a database write.
        if ((form.getAttribute('method') || 'get').toLowerCase() === 'get') {
            return;
        }

        // The confirmation was cancelled (confirm() returned false), or some other
        // handler vetoed the submission. Nothing was sent, so nothing may change:
        // no spinner, no disabled button, and — crucially — no latched double-submit
        // guard that would break the next real attempt.
        if (event.defaultPrevented) {
            return;
        }

        // Double-submit guard. The submitter is no longer disabled synchronously
        // (see lockSubmitter), so an impatient second click would otherwise send
        // the request again — a second payment, or a second cancellation. The
        // first submission of a form is let through; every later one is stopped.
        if (form.dataset.busySubmitted === '1') {
            event.preventDefault();
            return;
        }

        form.dataset.busySubmitted = '1';

        submitButtonsOf(form).forEach((button) => {
            // Alpine already owns the disabled state on these (e.g. the sales form),
            // and fighting it would fight x-bind:disabled.
            if (button.hasAttribute(':disabled') || button.hasAttribute('x-bind:disabled')) {
                return;
            }

            markBusy(button);
        });
    });

    // Restores the state after a real navigation, including Back/Forward via the
    // bfcache, where the DOM is handed back exactly as the user left it.
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('[data-busy-applied="1"]').forEach(releaseBusy);

        // Belt and braces: a form must never come back from the back/forward
        // cache still marked as submitted, or it would refuse to work.
        document
            .querySelectorAll('form[data-busy-submitted="1"]')
            .forEach((form) => delete form.dataset.busySubmitted);
    });
}

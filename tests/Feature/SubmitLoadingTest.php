<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Global submit loading state.
 *
 * The behaviour is client-side, so the assertions verify the shipped source: that
 * the handler is actually wired into the app entrypoint, and that it implements
 * each rule (label swap, double-submit lock, restore on navigation, safety
 * timeout, skip GET filters and Alpine-owned buttons).
 */
class SubmitLoadingTest extends TestCase
{
    use RefreshDatabase;

    private string $script;

    protected function setUp(): void
    {
        parent::setUp();

        $this->script = file_get_contents(base_path('resources/js/submit-button.js'));
    }

    public function test_handler_is_installed_in_the_application_entrypoint(): void
    {
        $app = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString("import { installSubmitLoading } from './submit-button'", $app);
        $this->assertStringContainsString('installSubmitLoading();', $app);
    }

    public function test_it_fires_on_submit_not_on_click(): void
    {
        // A click handler would fire even when native validation rejects the form,
        // leaving the button stuck. The submit event does not.
        $this->assertStringContainsString("addEventListener('submit'", $this->script);
        $this->assertStringNotContainsString("addEventListener('click'", $this->script);
    }

    public function test_it_locks_the_button_and_swaps_the_label(): void
    {
        $this->assertStringContainsString('button.disabled = true', $this->script);
        $this->assertStringContainsString('aria-busy', $this->script);
        $this->assertStringContainsString('data-busy-applied', $this->script);
    }

    public function test_it_uses_action_appropriate_verbs(): void
    {
        foreach (['Deleting', 'Uploading', 'Generating', 'Sending', 'Saving'] as $verb) {
            $this->assertStringContainsString($verb, $this->script, "Missing busy label: {$verb}");
        }
    }

    public function test_every_major_action_maps_to_a_relevant_label(): void
    {
        // Phase 1 of the loading-button fix: no action falls back to a bare
        // "Saving…" when its own verb is known (login must never say Saving).
        $labels = [
            'Logging in…',       // login
            'Creating account…', // register
            'Confirming…',       // confirm password
            'Deleting…',
            'Uploading…',
            'Generating…',
            'Accepting…',        // team invitation
            'Rejecting…',
            'Sending…',          // invite / reset link / resend
            'Recording sale…',
            'Completing sale…',
            'Processing payment…', // checkout / subscribe
            'Updating…',           // edit forms
            'Saving…',             // plain create/save stays the default
        ];

        foreach ($labels as $label) {
            $this->assertStringContainsString($label, $this->script, "Missing busy label: {$label}");
        }

        // Authentication text is what the heuristic matches on, so pin it too.
        $login = file_get_contents(base_path('resources/views/auth/login.blade.php'));
        $register = file_get_contents(base_path('resources/views/auth/register.blade.php'));
        $this->assertStringContainsString("__('Log in')", $login);
        $this->assertStringContainsString("__('Register')", $register);
    }

    public function test_spinner_and_label_have_one_clear_gap(): void
    {
        // The busy markup wraps spinner + label in a flex span with gap-1, so it
        // renders as "⟳ Saving…" and never "⟳Saving…".
        $this->assertStringContainsString('inline-flex items-center gap-1', $this->script);
        $this->assertStringContainsString('gap-1', $this->script);
    }

    public function test_explicit_busy_labels_override_the_heuristic(): void
    {
        // dataset.busyLabel is read BEFORE the pattern table — blades can always
        // pin an exact verb (create workspace, update workspace, checkout…).
        $this->assertStringContainsString('if (button.dataset.busyLabel)', $this->script);

        $edit = file_get_contents(base_path('resources/views/tenants/edit.blade.php'));
        $create = file_get_contents(base_path('resources/views/tenants/index.blade.php'));
        $billing = file_get_contents(base_path('resources/views/billing/index.blade.php'));

        $this->assertStringContainsString('data-busy-label="{{ __(\'Updating…\') }}"', $edit);
        $this->assertStringContainsString('data-busy-label="{{ __(\'Creating workspace…\') }}"', $create);
        $this->assertStringContainsString('data-busy-label="{{ __(\'Processing payment…\') }}"', $billing);

        // Switching workspace is not a save, and deleting one is not "Saving…" either:
        // the two ambiguous buttons on the workspace list pin their own verb.
        $this->assertStringContainsString('data-busy-label="{{ __(\'Opening…\') }}"', $create);
        $this->assertStringContainsString('data-busy-label="{{ __(\'Deleting…\') }}"', $create);
    }

    public function test_it_never_disables_the_submitter_synchronously(): void
    {
        // Production regression: the billing modal's two buttons carry name="intent",
        // and disabling the submitter inside the capture-phase submit listener stripped
        // that pair from the POST body. The browser builds a form's entry list only
        // AFTER the submit event, and a disabled submitter is excluded from it — so
        // `intent` never arrived, the request was rejected, and both buttons silently
        // did nothing. The disable must be deferred to a later task.
        $this->assertStringContainsString('function lockSubmitter(button)', $this->script);
        $this->assertStringContainsString('window.setTimeout(() => {', $this->script);

        // The lock is not optional: without the deferred disable there is still a
        // genuine double-submit guard.
        $this->assertStringContainsString("form.dataset.busySubmitted === '1'", $this->script);
        $this->assertStringContainsString('event.preventDefault();', $this->script);
        $this->assertStringContainsString("form.dataset.busySubmitted = '1'", $this->script);

        // And the guard must be re-armed when the button is released, otherwise a
        // request that never navigated would leave the form permanently unusable.
        $this->assertStringContainsString('delete button.form.dataset.busySubmitted', $this->script);
    }

    public function test_it_restores_state_and_cannot_stick_forever(): void
    {
        // Back/forward navigation hands the DOM back exactly as it was left.
        $this->assertStringContainsString("addEventListener('pageshow'", $this->script);
        $this->assertStringContainsString('button.disabled = false', $this->script);
        $this->assertStringContainsString('SAFETY_TIMEOUT_MS', $this->script);
    }

    public function test_it_skips_read_only_and_alpine_owned_controls(): void
    {
        // A GET filter does not write, so "Saving…" would be a lie.
        $this->assertStringContainsString("=== 'get'", $this->script);
        // Alpine already binds :disabled on the sales form; fighting it would break it.
        $this->assertStringContainsString('x-bind:disabled', $this->script);
    }
}

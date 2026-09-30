/**
 * Dismissible workspace notice on /tenants.
 *
 * The notice tells an account that it lost access to a workspace it used to be a
 * member of. Closing it is a write (it stores a "don't show this again" preference),
 * but it is a write with a cost the generic submit-button treatment gets wrong: that
 * listener relabels the control with a verb, and on an icon-only round button the
 * result is a "Saving…" pill bursting out of its own shape. The form therefore opts
 * out with `data-busy-skip` and this component owns the state instead, so the button
 * can show the accurate "Closing…" and the card can fade out on the way.
 */
export function workspaceRevokedNotice() {
    return {
        closing: false,
        gone: false,

        async close(form) {
            // A second click must not fire a second write.
            if (this.closing) {
                return;
            }

            this.closing = true;

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                    body: new FormData(form),
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    // The server refused (stale key, revoked membership gone, ...).
                    // Fall back to a normal page POST so the user still gets the
                    // server-rendered error instead of a notice that silently vanishes.
                    this.closing = false;
                    form.submit();

                    return;
                }

                // Hide through the leave transition, then drop the node entirely so a
                // later render of this page starts clean.
                this.gone = true;
                window.dispatchEvent(new CustomEvent('notice-closed'));
            } catch (error) {
                this.closing = false;
                form.submit();
            }
        },
    };
}

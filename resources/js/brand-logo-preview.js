/**
 * Brand Identity form on /profile.
 *
 * Only one job: show the operator the logo they just picked, before they save. The
 * object URL is revoked on every change and again on teardown, so repeatedly choosing
 * files on a long-lived page does not leak blobs.
 */
export function brandIdentityForm() {
    return {
        preview: null,

        previewLogo(event) {
            const file = event.target.files?.[0];

            this.revoke();

            if (!file) {
                return;
            }

            this.preview = URL.createObjectURL(file);

            const image = this.$root.querySelector('#brand-logo-preview');

            if (image) {
                image.src = this.preview;
            }
        },

        revoke() {
            if (this.preview) {
                URL.revokeObjectURL(this.preview);
                this.preview = null;
            }
        },

        destroy() {
            this.revoke();
        },
    };
}

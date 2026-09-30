/**
 * Cultiv One as an installable app.
 *
 * This covers the WHOLE SaaS, not one module: the manifest is scoped to "/" and the
 * installed app simply runs the normal authenticated Cultiv One shell, so Products,
 * Sales, POS, Purchases, Stock, Reports and Billing all work exactly as they do in
 * the browser. Nothing here replaces the login flow, the workspace switcher or the
 * tenant context — a PWA session is an ordinary session.
 *
 * Two independent features live here, deliberately kept apart:
 *   - INSTALL: browser-driven (`beforeinstallprompt`), user-initiated only.
 *   - FULLSCREEN: Fullscreen API, user-initiated only, state read back from the
 *     browser so the button can never lie about the real state.
 *
 * The install button only appears when the browser actually offers a prompt, and the
 * fullscreen control only when the API exists. Neither is ever shown as a dead button,
 * and neither is ever triggered automatically on load.
 */

/** True when the app is already running as an installed PWA. */
function isStandalone() {
    return window.matchMedia?.('(display-mode: standalone)').matches
        || window.matchMedia?.('(display-mode: window-controls-overlay)').matches
        // iOS Safari never fires beforeinstallprompt; this is how it reports itself.
        || window.navigator.standalone === true;
}

/**
 * Alpine component: the "Install Cultiv One" control.
 *
 * Renders nothing until the browser has raised `beforeinstallprompt`, so browsers
 * without PWA installation (most in-app browsers, Firefox desktop) simply show no
 * button rather than a control that cannot work. Once installed, the button is
 * removed for good: there is nothing left to install.
 */
export function pwaInstall() {
    return {
        canInstall: false,
        installed: isStandalone(),

        init() {
            if (this.installed || !('onbeforeinstallprompt' in window)) {
                return;
            }

            // The event is fired once and carries the prompt; it must be captured
            // eagerly (before any async work) and its default suppressed, otherwise the
            // browser shows its own mini-infobar on top of our button.
            window.addEventListener('beforeinstallprompt', (event) => {
                event.preventDefault();
                this.deferredPrompt = event;
                this.canInstall = true;
            });

            window.addEventListener('appinstalled', () => {
                this.deferredPrompt = null;
                this.canInstall = false;
                this.installed = true;

                window.dispatchEvent(new CustomEvent('cultiv:pwa-installed'));
            });
        },

        async install() {
            if (!this.deferredPrompt) {
                return;
            }

            const prompt = this.deferredPrompt;
            // Clear first: the event may only be used once, and a second click must not
            // throw if the user dismisses the dialog.
            this.deferredPrompt = null;
            this.canInstall = false;

            try {
                await prompt.prompt();
                await prompt.userChoice;
            } catch (e) {
                // A failed prompt leaves the app untouched; the user can retry from the
                // browser's own install UI at any time.
            }
        },
    };
}

/**
 * Alpine component: the fullscreen control for the global header.
 *
 * Reads the live state from the Fullscreen API on every change instead of tracking a
 * local boolean, so the label/icon stays correct even when the user leaves fullscreen
 * with Esc, the OS, or a browser gesture.
 */
export function fullscreenToggle() {
    return {
        isFullscreen: false,
        supported: typeof document !== 'undefined'
            && (document.fullscreenEnabled || document.webkitFullscreenEnabled),

        init() {
            if (!this.supported) {
                return;
            }

            const sync = () => {
                this.isFullscreen = Boolean(document.fullscreenElement || document.webkitFullscreenElement);
            };

            ['fullscreenchange', 'webkitfullscreenchange'].forEach((event) => {
                document.addEventListener(event, sync);
            });

            sync();
        },

        async toggle() {
            if (!this.supported) {
                return;
            }

            const element = document.documentElement;
            const current = document.fullscreenElement || document.webkitFullscreenElement;

            try {
                if (current) {
                    await (document.exitFullscreen || document.webkitExitFullscreen).call(document);
                } else {
                    await (element.requestFullscreen || element.webkitRequestFullscreen).call(element);
                }
            } catch (e) {
                // Permission denied, or the gesture was not trusted: the page keeps
                // working and the state simply stays as it was.
            }
        },
    };
}

/**
 * Registers the service worker.
 *
 * Registration happens on load rather than on install: the service worker makes the
 * app installable, it does not change how the app behaves while it is open.
 */
export function registerServiceWorker() {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }

    // Plain HTTP origins (except localhost) cannot register a service worker; silently
    // skipping keeps development and local demos free of console noise.
    if (!window.isSecureContext) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
            // Installation is an enhancement: a failed registration must never stop the
            // application from running.
        });
    });
}

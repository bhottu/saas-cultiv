import jsQR from 'jsqr';

/**
 * Mobile-first barcode scanner for Cultiv One.
 *
 * Design notes
 * ------------
 * - Uses the REAR camera (`facingMode: 'environment'`) because that is the lens
 *   pointed at a label on a shelf.
 * - Decoding runs on a downscaled offscreen canvas. A retail barcode only needs a
 *   few hundred pixels, so a 1280px cap keeps a mid-range phone smooth.
 * - The same code firing repeatedly is ignored (debounce), otherwise one label in
 *   front of the lens adds the product dozens of times.
 * - The camera track is ALWAYS stopped on close, on unmount and on page hide. A
 *   leaked stream keeps the camera LED on and shows a recording dot to the user.
 * - Insecure contexts (plain http on a LAN) cannot open a camera, so that case is
 *   detected and reported instead of failing with an opaque error.
 */
export function barcodeScanner({ onScan = null, onError = null } = {}) {
    return {
        open: false,
        starting: false,
        error: '',
        video: null,
        canvas: null,
        stream: null,
        frame: null,
        lastCode: '',
        lastCodeAt: 0,
        manualCode: '',

        init() {
            this.video = this.$refs.video || null;
            this.canvas = this.$refs.canvas || null;

            this.$watch('open', (isOpen) => (isOpen ? this.start() : this.stop()));
        },

        /**
         * Manual code entry (type + Enter, or the "Use code" button).
         *
         * Lives here rather than in an inline `<form x-on:submit.prevent>` because the
         * component is embedded inside the sale form, where a nested form would make
         * the parser close the outer one and orphan its submit button.
         */
        submitManualCode() {
            const code = this.manualCode.trim();

            if (!code) {
                return;
            }

            this.$dispatch('barcode-scanned', { code });
            this.manualCode = '';
            this.close();
        },

        destroy() {
            this.stop();
        },

        get isSupported() {
            return typeof window !== 'undefined'
                && !!window.isSecureContext
                && !!navigator.mediaDevices?.getUserMedia
                && typeof jsQR === 'function';
        },

        async start() {
            this.error = '';

            if (this.isSupported === false) {
                this.error = window.isSecureContext === false
                    ? 'Camera access needs a secure connection. Open this page over HTTPS.'
                    : 'This browser cannot open a camera. Use the search box instead.';

                return;
            }

            this.starting = true;

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                    },
                    audio: false,
                });

                this.video.srcObject = this.stream;
                await this.video.play();

                this.tick();
            } catch (error) {
                this.error = {
                    NotAllowedError: 'Camera permission was denied. Allow it in your browser settings, or use the search box.',
                    NotFoundError: 'No camera was found on this device.',
                    NotReadableError: 'The camera is already in use by another app.',
                }[error?.name] ?? 'The camera could not be started.';
            } finally {
                this.starting = false;
            }
        },

        stop() {
            if (this.frame) {
                cancelAnimationFrame(this.frame);
                this.frame = null;
            }

            if (this.stream) {
                this.stream.getTracks().forEach((track) => track.stop());
                this.stream = null;
            }

            if (this.video) {
                this.video.srcObject = null;
            }
        },

        tick() {
            this.frame = requestAnimationFrame(() => this.tick());

            const video = this.video;
            const canvas = this.canvas;

            if (!video || !canvas || video.readyState !== video.HAVE_ENOUGH_DATA) {
                return;
            }

            // Cap the decode width: a barcode never needs more, and this is what
            // keeps the loop cheap on a mid-range phone.
            const width = Math.min(video.videoWidth, 1280) || 640;
            const height = Math.round((video.videoHeight / video.videoWidth) * width) || 480;

            if (canvas.width !== width || canvas.height !== height) {
                canvas.width = width;
                canvas.height = height;
            }

            const context = canvas.getContext('2d', { willReadFrequently: true });
            context.drawImage(video, 0, 0, width, height);

            const image = context.getImageData(0, 0, width, height);
            const result = jsQR(image.data, image.width, image.height, {
                inversionAttempts: 'dontInvert',
            });

            if (!result?.data) {
                return;
            }

            const now = Date.now();
            const code = String(result.data).trim();

            if (!code || (code === this.lastCode && now - this.lastCodeAt < 1500)) {
                return;
            }

            this.lastCode = code;
            this.lastCodeAt = now;

            this.$dispatch('barcode-scanned', { code });
            onScan?.(code, result);
        },

        close() {
            this.open = false;
            this.stop();
            this.lastCode = '';
        },
    };
}

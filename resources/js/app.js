import './bootstrap';

import Alpine from 'alpinejs';
import { barcodeScanner } from './barcode-scanner';
import { installSubmitLoading } from './submit-button';
import { pwaInstall, fullscreenToggle, registerServiceWorker } from './pwa';
import { workspaceRevokedNotice } from './workspace-notice';
import { brandIdentityForm } from './brand-logo-preview';

window.Alpine = Alpine;

// Mobile barcode capture. jsQR is ~40 kB and dependency-free, so the scanner costs
// far less than a full ZXing build while still decoding the symbologies a shop
// actually uses (EAN-13, UPC, Code 128, QR).
Alpine.data('barcodeScanner', barcodeScanner);

// Installable app: "Install Cultiv One" (browser-gated) and the fullscreen control.
// Both are user-initiated and both hide themselves when unsupported, so no control
// ever appears that cannot work.
Alpine.data('pwaInstall', pwaInstall);
Alpine.data('fullscreenToggle', fullscreenToggle);

// The "you no longer have access" notice owns its own loading state (it must say
// "Closing…", not the generic "Saving…"), so it opts out of installSubmitLoading.
Alpine.data('workspaceRevokedNotice', workspaceRevokedNotice);

// Logo preview on the Brand Identity section of /profile.
Alpine.data('brandIdentityForm', brandIdentityForm);

// App-wide rule: every control that writes to the database gets a loading state
// and refuses a duplicate submission. Installed before Alpine so the listeners are
// in place regardless of how the page is bootstrapped.
installSubmitLoading();

// Makes the whole SaaS installable. The worker caches public build assets only and
// never stores authenticated HTML or API data (see public/sw.js for why).
registerServiceWorker();

Alpine.start();

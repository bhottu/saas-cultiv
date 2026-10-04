/**
 * Behavioural harness for pwa.js. Loads the REAL module and drives it with a stubbed
 * browser per platform, so the documented states are verified by execution rather than by
 * reading the source. Temporary: deleted after use.
 */
const listeners = {};

function makeWindow({ standalone = false, hasPromptApi = true } = {}) {
    return {
        matchMedia: (q) => ({ matches: standalone && q.includes('standalone') }),
        navigator: { standalone },
        addEventListener: (type, fn) => { (listeners[type] ||= []).push(fn); },
        dispatchEvent: () => true,
        CustomEvent: class { constructor(type) { this.type = type; } },
        __hasPromptApi: hasPromptApi,
    };
}

function load(win, ua, platform = 'Linux armv8l', maxTouchPoints = 0) {
    for (const k of Object.keys(listeners)) delete listeners[k];

    globalThis.window = win;
    // Node 22 exposes a read-only `navigator` getter, so it must be redefined rather than
    // assigned.
    Object.defineProperty(globalThis, 'navigator', {
        value: { userAgent: ua, platform, maxTouchPoints },
        configurable: true,
        writable: true,
    });
    globalThis.document = undefined;

    if (win.__hasPromptApi) {
        window.onbeforeinstallprompt = null;
    } else {
        delete window.onbeforeinstallprompt;
    }

    return import('../../resources/js/pwa.js?v=' + Math.random());
}

let failures = 0;
function check(label, actual, expected) {
    const ok = actual === expected;
    if (!ok) failures++;
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}  (got ${actual}, want ${expected})`);
}

const IOS_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15';
const ANDROID_UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120 Mobile';

// ------------------------------------------------------ State 2: already installed
{
    const win = makeWindow({ standalone: true });
    const { pwaInstall } = await load(win, IOS_UA);
    const c = pwaInstall();
    c.init();
    check('installed app hides install button', c.canInstall, false);
    check('installed app hides iOS instructions', c.showIosInstructions, false);
}

// ------------------------------------------------------------- State 4: iOS Safari
{
    const win = makeWindow({ standalone: false, hasPromptApi: false });
    const { pwaInstall } = await load(win, IOS_UA, 'iPhone', 5);
    const c = pwaInstall();
    c.init();
    check('iOS detected', c.isIos, true);
    check('iOS shows instructions', c.showIosInstructions, true);
    check('iOS shows no clickable button', c.canInstall, false);
}

// -------------------------------------------- iPadOS 13+ masquerading as desktop
{
    const win = makeWindow({ standalone: false, hasPromptApi: false });
    const { pwaInstall } = await load(
        win, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15', 'MacIntel', 5
    );
    const c = pwaInstall();
    c.init();
    check('iPadOS desktop UA still detected as iOS', c.showIosInstructions, true);
}

// ------------------------------- State 3: Android, browser has not offered a prompt
{
    const win = makeWindow({ standalone: false });
    const { pwaInstall } = await load(win, ANDROID_UA);
    const c = pwaInstall();
    c.init();
    check('Android without prompt shows no button', c.canInstall, false);
    check('Android without prompt shows no iOS text', c.showIosInstructions, false);
}

// ------------------------------------------- Android once beforeinstallprompt fires
{
    const win = makeWindow({ standalone: false });
    const { pwaInstall } = await load(win, ANDROID_UA);
    const c = pwaInstall();
    c.init();

    let prompted = 0;
    let prevented = false;
    const event = {
        preventDefault: () => { prevented = true; },
        prompt: async () => { prompted++; },
        userChoice: Promise.resolve({ outcome: 'accepted' }),
    };

    (listeners['beforeinstallprompt'] || []).forEach((fn) => fn(event));

    check('beforeinstallprompt default was prevented', prevented, true);
    check('button appears once the browser offers a prompt', c.canInstall, true);

    await c.install();
    check('native prompt shown exactly once', prompted, 1);
    check('button hides after use', c.canInstall, false);
}

// ------------------------------------------------------- user declines the prompt
{
    const win = makeWindow({ standalone: false });
    const { pwaInstall } = await load(win, ANDROID_UA);
    const c = pwaInstall();
    c.init();

    (listeners['beforeinstallprompt'] || []).forEach((fn) => fn({
        preventDefault: () => {}, prompt: async () => {},
        userChoice: Promise.resolve({ outcome: 'dismissed' }),
    }));

    await c.install();
    check('declining does not throw and leaves no button', c.canInstall, false);
}

// ------------------------------------------- Firefox desktop: no install API at all
{
    const win = makeWindow({ standalone: false, hasPromptApi: false });
    const { pwaInstall } = await load(
        win, 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0', 'Linux x86_64'
    );
    const c = pwaInstall();
    c.init();
    check('Firefox desktop shows nothing', c.canInstall, false);
    check('Firefox desktop shows no iOS text', c.showIosInstructions, false);
}

// ------------------------------------------------------- appinstalled clears state
{
    const win = makeWindow({ standalone: false });
    const { pwaInstall } = await load(win, ANDROID_UA);
    const c = pwaInstall();
    c.init();

    (listeners['beforeinstallprompt'] || []).forEach((fn) => fn({
        preventDefault: () => {}, prompt: async () => {}, userChoice: Promise.resolve({}),
    }));
    (listeners['appinstalled'] || []).forEach((fn) => fn());

    check('appinstalled marks installed', c.installed, true);
    check('appinstalled hides the button', c.canInstall, false);
    check('appinstalled clears iOS text', c.showIosInstructions, false);
}

// ------------------------------------------- iOS disclosure on the floating control
{
    const win = makeWindow({ standalone: false, hasPromptApi: false });
    const { pwaInstall } = await load(win, IOS_UA, 'iPhone', 5);
    const c = pwaInstall();
    c.init();

    check('iOS starts with the help panel closed', c.iosHelpOpen, false);

    // The floating control must not be a dead button: tapping it must do something.
    c.activate();
    check('tapping Install Cultiv on iOS opens the instructions', c.iosHelpOpen, true);

    c.activate();
    check('tapping again closes them again', c.iosHelpOpen, false);
}

// ---------------------------- Chromium activate() replays the native prompt exactly once
{
    const win = makeWindow({ standalone: false });
    const { pwaInstall } = await load(win, ANDROID_UA);
    const c = pwaInstall();
    c.init();

    let prompted = 0;
    (listeners['beforeinstallprompt'] || []).forEach((fn) => fn({
        preventDefault: () => {},
        prompt: async () => { prompted++; },
        userChoice: Promise.resolve({ outcome: 'accepted' }),
    }));

    await c.activate();
    check('activate() runs the native prompt once', prompted, 1);
    check('activate() does not open the iOS panel', c.iosHelpOpen, false);
}

console.log(failures === 0 ? '\nALL PLATFORM STATES VERIFIED' : `\n${failures} FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);
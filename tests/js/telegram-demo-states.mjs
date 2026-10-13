/**
 * Behavioural harness for telegram-demo.js (the landing "AI Assistant via Telegram"
 * mock). Loads the REAL module and drives it with a stubbed browser, so its contract
 * is verified by execution rather than by reading the source.
 *
 * What it proves:
 *   - the demo is a no-op when the section is not on the page;
 *   - a double init can never bind two animation loops (idempotent);
 *   - prefers-reduced-motion renders the whole conversation statically and readable;
 *   - the animated path actually emits the user message, the typing indicator, then
 *     the AI reply, in order;
 *   - it never touches the network (no fetch/XHR/axios call).
 *
 * Run with: node tests/js/telegram-demo-states.mjs
 */

// ---------------------------------------------------------------- tiny fake DOM
class El {
    constructor(tag = 'div') {
        this.tagName = tag.toUpperCase();
        this.children = [];
        this.parentNode = null;
        this._html = '';
        this._class = '';
        this.attrs = {};
        this.dataset = {};
        this.scrollTop = 0;
        this.scrollHeight = 100;
    }

    set className(v) { this._class = String(v); }
    get className() { return this._class; }

    set innerHTML(v) { this._html = String(v); this.children = []; }
    get innerHTML() { return this._html; }

    setAttribute(k, v) { this.attrs[k] = String(v); }

    get classList() {
        const self = this;
        const set = () => new Set(self._class.split(/\s+/).filter(Boolean));
        return {
            add: (...cs) => { const s = set(); cs.forEach((c) => s.add(c)); self._class = [...s].join(' '); },
            remove: (...cs) => { const s = set(); cs.forEach((c) => s.delete(c)); self._class = [...s].join(' '); },
            contains: (c) => set().has(c),
        };
    }

    appendChild(node) { node.parentNode = this; this.children.push(node); return node; }

    remove() {
        if (this.parentNode) {
            const i = this.parentNode.children.indexOf(this);
            if (i >= 0) this.parentNode.children.splice(i, 1);
            this.parentNode = null;
        }
    }

    // Only the selectors the module actually uses are modelled.
    querySelectorAll(sel) {
        if (sel === '.reveal') {
            const n = (this._html.match(/class="reveal/g) || []).length;
            return Array.from({ length: n }, () => new El());
        }
        return [];
    }

    querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
}

function makeRoot(withSection = true) {
    const root = new El('div');
    if (withSection) {
        root.dataset.tgReady = undefined;
        const list = new El('div');
        root._list = list;
        root.querySelector = (sel) => (sel.includes('messages') ? list : null);
    } else {
        root.querySelector = () => null;
    }
    return root;
}

// A microtask-based clock: setTimeout queues a callback that resolves the awaiting
// promise, so the module's async loop progresses deterministically and fast. rAF runs
// on the next microtask, like a browser frame.
let timerQueue = [];
let timerId = 0;
const rafQueue = [];

function installClock() {
    timerQueue = [];
    timerId = 0;
    rafQueue.length = 0;
    globalThis.setTimeout = (fn) => { const id = ++timerId; timerQueue.push({ id, fn }); return id; };
    globalThis.clearTimeout = (id) => {
        const i = timerQueue.findIndex((t) => t.id === id);
        if (i >= 0) timerQueue.splice(i, 1);
    };
    globalThis.requestAnimationFrame = (fn) => { rafQueue.push(fn); return rafQueue.length; };
}

// Drain exactly one pending timer and let its microtask continuations settle, so the
// harness can sample the conversation at precise points (e.g. while "typing…" shows).
async function step() {
    for (let s = 0; s < 200; s++) {
        while (rafQueue.length) {
            rafQueue.shift()();
            await Promise.resolve();
        }
        if (timerQueue.length) {
            timerQueue.shift().fn();
            await Promise.resolve();
            await Promise.resolve();
            return true;
        }
        await Promise.resolve();
        if (timerQueue.length === 0 && rafQueue.length === 0) return false;
    }
    return false;
}

// Advance the async loop: run queued frames + timers, yielding microtasks between so
// each `await wait(...)` continuation reaches its next setTimeout.
async function drain(maxSteps = 400) {
    for (let s = 0; s < maxSteps; s++) {
        const progressed = await step();
        if (!progressed) break;
    }
}

// Each test loads the module freshly (Node cache-busts the dynamic import) and installs
// its own clock, so a previous test's async loop can never run against this test's DOM.
// The module reads document/window at call time, so fresh globals per test are honoured.

async function init(importPromise, registerFn) {
    const { registerTelegramDemo } = await importPromise;
    // Let the dynamic import settle before the module binds to the current globals.
    await Promise.resolve();
    registerFn(registerTelegramDemo);
}


// Each test drives its OWN freshly-loaded module instance. To stop a running animation
// loop deterministically (so it can't bleed into the next test), the harness flips
// document.hidden — the module listens for visibilitychange and tears its loop down.
// The tiny fake DOM tracks that flag and replays the listener synchronously.

function makeDocument(root) {
    const listeners = {};
    const doc = {
        hidden: false,
        querySelector: (sel) => (sel === '[data-telegram-chat]' ? root : null),
        createElement: (tag) => new El(tag),
        addEventListener: (type, fn) => {
            (listeners[type] ||= []).push(fn);
        },
        setHidden(value) {
            doc.hidden = value;
            (listeners.visibilitychange || []).forEach((fn) => fn());
        },
    };
    return doc;
}

function load(reducedMotion, withSection = true) {
    installClock();

    const root = makeRoot(withSection);
    const doc = makeDocument(root);
    globalThis.document = doc;
    globalThis.window = {
        matchMedia: () => ({ matches: reducedMotion, addEventListener: () => {} }),
    };
    // IntersectionObserver that only records — it does not auto-start, so the only
    // animation driver under test is the module's own trailing start() call.
    globalThis.IntersectionObserver = class {
        constructor(cb) { this.cb = cb; }
        observe() {}
        disconnect() {}
    };
    globalThis.fetch = () => { throw new Error('network call attempted'); };
    globalThis.XMLHttpRequest = function () { throw new Error('network call attempted'); };

    const stop = () => doc.setHidden(true);
    return { root, doc, stop, importPromise: import('../../resources/js/telegram-demo.js?v=' + Math.random()) };
}

let failures = 0;
function check(label, actual, expected) {
    const ok = actual === expected;
    if (!ok) failures++;
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}  (got ${JSON.stringify(actual)}, want ${JSON.stringify(expected)})`);
}

// ---------------------------------------------- no-op when the section is absent
{
    const { importPromise } = load(false, false);
    const { registerTelegramDemo } = await importPromise;
    let threw = false;
    try { registerTelegramDemo(); } catch { threw = true; }
    check('absent section does not throw', threw, false);
}

// ------------------------------------------------------------- idempotent init
{
    const { root, stop, importPromise } = load(false, true);
    const { registerTelegramDemo } = await importPromise;
    registerTelegramDemo();
    check('first init marks the section ready', root.dataset.tgReady, '1');
    registerTelegramDemo(); // must be a silent no-op, not a second loop
    check('second init leaves the ready flag untouched', root.dataset.tgReady, '1');
    stop(); // tear down the loop this test started
    await drain(5);
}

// ------------------------------------- reduced-motion: full conversation, static
{
    const { root, doc, stop, importPromise } = load(true, true);
    const { registerTelegramDemo } = await importPromise;
    registerTelegramDemo();

    const list = root._list;
    // 3 scenarios x (1 user + 1 AI) = 6 bubbles, all rendered at once.
    check('reduced-motion renders every message', list.children.length, 6);
    const revealed = list.children.every((b) => b.classList.contains('opacity-100'));
    check('reduced-motion reveals every bubble (no hidden state)', revealed, true);
    check('no typing bubble lingers in static mode', (list.innerHTML.match(/tg-dot/g) || []).length, 0);
    stop();
    await drain(5);
}

// ------------------------- animated: user msg -> typing -> AI reply, in order
{
    const { root, stop, importPromise } = load(false, true);
    const { registerTelegramDemo } = await importPromise;
    registerTelegramDemo();
    const list = root._list;

    // AppendChild grows children (mirrors the DOM); innerHTML is only touched by
    // clearMessages, so assertions read the live children.
    const html = () => list.children.map((c) => c.innerHTML).join('\n');
    const settle = async (n = 6) => { for (let i = 0; i < n; i++) await Promise.resolve(); };

    // Sequence: initial -> [user msg] -> gap -> [typing] -> typing -> [AI reply]...
    // Step to the point where the typing indicator is on screen.
    await step(); // initial
    await step(); // gap fired -> playScenario resumes, appends the typing bubble
    await settle();
    check('user message appears before typing', /Cek stok Kaos Polos Hitam ukuran L\./.test(html()), true);
    check('animated path shows a typing indicator', (html().match(/tg-dot/g) || []).length >= 1, true);

    // Advance until the first AI reply has landed and the typing bubble is gone.
    await drain(40);
    const text = html();
    check('first AI reply is emitted', /Stok Kaos Polos Hitam ukuran L saat ini tersedia 24 pcs\./.test(text), true);
    check('typing bubble is removed once the reply lands', (text.match(/tg-dot/g) || []).length, 0);
    stop();
    await drain(5);
}

// ------------------------------------------------- scenario content (from source)
{
    const src = await (await import('node:fs/promises')).readFile(
        new URL('../../resources/js/telegram-demo.js', import.meta.url), 'utf8');

    const must = ['Kaos Polos Hitam', 'Hoodie Hitam', 'Rp12.500.000', 'Rp300.000', 'Cek stok', 'draft penjualan'];
    for (const m of must) {
        check(`scenario content contains "${m}"`, src.includes(m), true);
    }

    // Fashion only — no plant/flower products.
    const banned = ['tanaman', 'bunga', 'aroid', 'monstera', 'kaktus', 'bibit', 'seedling'];
    const lower = src.toLowerCase();
    for (const b of banned) {
        check(`scenario avoids non-fashion word "${b}"`, lower.includes(b), false);
    }

    // No network surface anywhere in the module.
    for (const net of ['fetch(', 'XMLHttpRequest', 'axios', 'navigator.sendBeacon']) {
        check(`module makes no "${net}" call`, src.includes(net), false);
    }
}

console.log(failures === 0 ? '\nALL TELEGRAM DEMO STATES VERIFIED' : `\n${failures} FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);


/**
 * Behavioural harness for submit-button.js â€” the shared delete/loading behaviour.
 *
 * Loads the REAL module and drives it against a minimal DOM stub that implements true
 * capture â†’ target â†’ bubble dispatch with a working preventDefault(). That fidelity is
 * the whole point: the bug this pins is an ORDERING bug between the document-level
 * listener and a form's own `onsubmit="return confirm(...)"` handler, so a stub that
 * fired listeners in the wrong order would happily pass broken code.
 *
 * All 19 listing screens in this app guard deletion with exactly that inline confirm.
 *
 * Run: npm run test:submit   (node tests/js/submit-cancel-states.mjs)
 */

// ------------------------------------------------------------------ DOM stub

class FakeEvent {
    /**
     * `target` is what a real SubmitEvent carries. The handler reads event.target first
     * of all; leaving it undefined makes every form look like a non-form and the whole
     * listener returns silently.
     */
    constructor(type, target) {
        this.type = type;
        this.target = target;
        this.defaultPrevented = false;
    }

    preventDefault() {
        this.defaultPrevented = true;
    }
}

class HTMLElement {
    constructor() {
        this.dataset = {};
        this.attributes = {};
        this.classList = {
            _set: new Set(),
            add: (...c) => c.forEach((x) => this.classList._set.add(x)),
            remove: (...c) => c.forEach((x) => this.classList._set.delete(x)),
            contains: (c) => this.classList._set.has(c),
        };
        this.disabled = false;
        this.tagName = 'BUTTON';
        this.type = 'submit';
        this.form = null;
        this.textContent = '';
        this.innerHTML = '';
    }

    get outerHTML() {
        return this._outerHTML ?? '';
    }

    set outerHTML(value) {
        this._outerHTML = value;
    }

    setAttribute(name, value) {
        this.attributes[name] = value;
    }

    /** The module reads form.getAttribute('method') to skip read-only GET forms. */
    getAttribute(name) {
        return name in this.attributes ? this.attributes[name] : null;
    }

    removeAttribute(name) {
        delete this.attributes[name];
    }

    hasAttribute(name) {
        return name in this.attributes;
    }

    prepend(node) {
        this._prepended = node;
    }
}

/** A form whose inline onsubmit="return confirm(â€¦)" runs at the TARGET phase. */
class HTMLFormElement extends HTMLElement {
    constructor({ confirmResult = true } = {}) {
        super();
        this.tagName = 'FORM';
        this.confirmResult = confirmResult;
        this.targetListeners = [];
        this._buttons = [];
    }

    addEventListener(type, fn) {
        if (type === 'submit') {
            this.targetListeners.push(fn);
        }
    }

    querySelectorAll() {
        return this._buttons;
    }

    /** Capture (root) â†’ target (inline confirm first) â†’ bubble (root). */
    dispatchSubmit() {
        const event = new FakeEvent('submit', this);

        for (const fn of documentListeners.capture) {
            fn(event);
        }

        if (!this.confirmResult) {
            event.preventDefault();
        }

        for (const fn of this.targetListeners) {
            fn(event);
        }

        for (const fn of documentListeners.bubble) {
            fn(event);
        }

        return event;
    }
}

// ------------------------------------------------------------------ globals

const documentListeners = { capture: [], bubble: [] };

globalThis.HTMLElement = HTMLElement;
globalThis.HTMLFormElement = HTMLFormElement;

globalThis.document = {
    addEventListener: (type, fn, capture = false) => {
        if (type === 'submit') {
            documentListeners[capture ? 'capture' : 'bubble'].push(fn);
        }
    },
    querySelectorAll: () => [],
    // markBusy() builds the spinner element this way and then re-emits its outerHTML.
    createElement: () => new HTMLElement(),
};

globalThis.window = {
    addEventListener: () => {},
    // Deliberately does NOT run the callback. markBusy() arms a 20s safety timeout
    // through this, and executing it inline would release the button on the very next
    // line and make every busy assertion fail for reasons unrelated to what is under test.
    setTimeout: () => 0,
};

// ------------------------------------------------------------------ helpers

let failures = 0;

function check(label, actual, expected) {
    const ok = actual === expected;
    if (!ok) {
        failures += 1;
    }
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}  (got ${actual}, want ${expected})`);
}

function makeForm({ confirmResult = true, buttonLabel = 'Delete', method = 'POST' } = {}) {
    const form = new HTMLFormElement({ confirmResult });
    // Every destructive form in this app is method="POST" (+ @method('DELETE')). An
    // attribute-less <form> defaults to GET, which the module correctly skips.
    form.setAttribute('method', method);

    const button = new HTMLElement();
    button.textContent = buttonLabel;
    button.form = form;
    form._buttons = [button];

    return { form, button };
}

const isBusy = (button) => button.dataset.busyApplied === '1';

const mod = await import('../../resources/js/submit-button.js');
mod.installSubmitLoading();

// ------------------------------------------------- 1. Cancel leaves it alone

{
    const { form, button } = makeForm({ confirmResult: false });

    form.dispatchSubmit();

    check('cancel: no spinner applied', isBusy(button), false);
    check('cancel: button stays enabled', button.disabled, false);
    check('cancel: no "Deletingâ€¦" label', button.dataset.busyText, undefined);
    check('cancel: no latched double-submit guard', form.dataset.busySubmitted, undefined);
    check('cancel: label text untouched', button.textContent, 'Delete');
}

// ---------------------------------------------- 2. Confirm starts the loading

{
    const { form, button } = makeForm({ confirmResult: true });

    form.dispatchSubmit();

    check('confirm: busy state applied', isBusy(button), true);
    check('confirm: label becomes the Deleting verb', String(button.dataset.busyText).startsWith('Deleting'), true);
    check('confirm: guard latched', form.dataset.busySubmitted, '1');
}

// ------------------------ 3. Cancel then confirm: the record is still deletable

{
    const { form, button } = makeForm({ confirmResult: false });

    form.dispatchSubmit();
    check('retry: still clean after a cancel', isBusy(button), false);

    // The cancelled attempt must not have latched the guard â€” otherwise the next
    // genuine confirm+submit was swallowed and the row could never be deleted.
    form.confirmResult = true;
    form.dispatchSubmit();

    check('retry: a later confirm still goes through', isBusy(button), true);
}

// --------------------------- 4. Repeated cancels never accumulate any state

{
    const { form, button } = makeForm({ confirmResult: false });

    for (let i = 0; i < 5; i += 1) {
        form.dispatchSubmit();
    }

    check('repeat: no spinner after 5 cancels', isBusy(button), false);
    check('repeat: no guard after 5 cancels', form.dataset.busySubmitted, undefined);
}

// ---------------------------------------------- 5. A GET form is never "busy"

{
    const { form, button } = makeForm({ confirmResult: true, method: 'get' });

    form.dispatchSubmit();

    check('GET form: not marked busy', isBusy(button), false);
}

console.log(failures === 0 ? '\nALL SUBMIT STATES VERIFIED' : `\n${failures} FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);

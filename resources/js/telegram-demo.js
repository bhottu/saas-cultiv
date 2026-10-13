/**
 * Landing-page "AI Assistant via Telegram" demo.
 *
 * A purely client-side mock conversation: it NEVER calls the AI backend and NEVER
 * talks to Telegram. The whole message list is the SCENARIOS constant below, so the
 * marketing section works for anonymous visitors with no account and no network request.
 *
 * Behaviour contract (see resources/views/welcome/partials/telegram-ai.blade.php):
 *   - a user message appears, a "typing…" indicator shows, then the AI reply builds up;
 *   - long replies reveal line by line so the visitor has time to read them;
 *   - after the last scenario it pauses, then loops back to the first;
 *   - with prefers-reduced-motion the full conversation is rendered statically instead;
 *   - the animation pauses off-screen and when the tab is hidden, so timers never pile up;
 *   - a single live timer at a time and an idempotent init, so a re-render never stacks timers.
 */
export function registerTelegramDemo() {
    const root = document.querySelector('[data-telegram-chat]');

    // Idempotent: app.js runs once per page, but guard against a double call so the
    // demo can never bind two animation loops to the same markup.
    if (!root || root.dataset.tgReady === '1') {
        return;
    }
    root.dataset.tgReady = '1';

    const list = root.querySelector('[data-telegram-chat="messages"]');

    // The message surface is part of the same markup; if it is ever missing there is
    // nothing to animate, so bail instead of throwing.
    if (!list) {
        return;
    }

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    // Illustrative numbers only — fictional fashion products, never a claim about a
    // real workspace's data. Each line reveals on its own for a readable cadence.
    const SCENARIOS = [
        {
            user: 'Cek stok Kaos Polos Hitam ukuran L.',
            userTime: '09:14',
            reply: {
                lines: ['Stok Kaos Polos Hitam ukuran L saat ini tersedia 24 pcs.'],
            },
        },
        {
            user: 'Buat ringkasan penjualan bulan ini.',
            userTime: '09:15',
            reply: {
                intro: 'Berikut ringkasan penjualan bulan ini:',
                summary: [
                    ['Total penjualan', 'Rp12.500.000'],
                    ['Jumlah pesanan', '86'],
                    ['Produk terjual', '143 pcs'],
                ],
            },
        },
        {
            user: 'Buat draft penjualan 2 pcs Hoodie Hitam dengan harga Rp150.000 per pcs.',
            userTime: '09:16',
            reply: {
                intro: 'Draft penjualan berhasil disiapkan.',
                summary: [
                    ['Produk', 'Hoodie Hitam'],
                    ['Jumlah', '2 pcs'],
                    ['Harga per pcs', 'Rp150.000'],
                    ['Total', 'Rp300.000'],
                ],
                foot: 'Status: Menunggu konfirmasi',
            },
        },
    ];

    // Cadence kept generous on purpose: the visitor must have time to read every line.
    const TIMING = {
        initial: 700,    // pause before the first message
        gap: 550,        // between the user message and the typing indicator
        typing: 1150,    // how long "typing…" shows
        line: 520,       // delay between revealed AI lines
        read: 2800,      // hold a finished reply before clearing
        transition: 900, // pause between scenarios
    };

    // One timer at a time. wait() replaces any pending timer so the chain never stacks.
    let timer = null;
    let playing = false;

    const clearTimer = () => {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    };

    const wait = (ms) => new Promise((resolve) => {
        clearTimer();
        timer = setTimeout(resolve, ms);
    });

    const scrollToBottom = () => {
        list.scrollTop = list.scrollHeight;
    };

    // Every revealable line starts hidden and transitions in; reduced-motion just
    // ends every line in its visible state (no transition needed in the loop).
    const revealClass = 'transition duration-300 ease-out opacity-0 translate-y-1';

    function show(node) {
        node.classList.add('opacity-100', 'translate-y-0');
        node.classList.remove('opacity-0', 'translate-y-1');
    }

    function userBubble(text, time) {
        const bubble = document.createElement('div');
        bubble.className =
            'tg-msg flex justify-end transition duration-300 ease-out ' +
            revealClass;
        bubble.innerHTML = `
            <div class="max-w-[85%] sm:max-w-[80%]">
                <div class="rounded-2xl rounded-br-md bg-indigo-600 px-3.5 py-2 text-sm leading-relaxed text-white shadow-sm">
                    ${escapeHtml(text)}
                </div>
                <span class="mt-1 block text-right text-[10px] tabular-nums text-gray-400">${escapeHtml(time)}</span>
            </div>`;
        return bubble;
    }

    function aiBubble(reply, time) {
        const bubble = document.createElement('div');
        bubble.className =
            'tg-msg flex items-start gap-2 transition duration-300 ease-out ' +
            revealClass;

        const parts = [];
        parts.push(`
            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[#229ED9] text-white">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                    <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0Zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.479.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.244-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635Z"/>
                </svg>
            </span>`);

        const body = ['<div class="min-w-0 max-w-[85%] sm:max-w-[80%]">'];

        if (reply.intro) {
            body.push(`<p class="reveal ${revealClass}">${escapeHtml(reply.intro)}</p>`);
        }

        if (reply.summary) {
            body.push('<div class="mt-1 space-y-1.5 rounded-2xl rounded-tl-md border border-gray-200 bg-white px-3.5 py-2.5 shadow-sm">');
            reply.summary.forEach(([label, value]) => {
                body.push(`
                    <div class="reveal ${revealClass} flex items-baseline justify-between gap-3 text-sm">
                        <span class="text-gray-600">${escapeHtml(label)}</span>
                        <span class="text-right font-semibold tabular-nums text-gray-900">${escapeHtml(value)}</span>
                    </div>`);
            });
            body.push('</div>');
        }

        if (reply.lines) {
            reply.lines.forEach((line) => {
                body.push(`<p class="reveal ${revealClass} rounded-2xl rounded-tl-md border border-gray-200 bg-white px-3.5 py-2 text-sm leading-relaxed text-gray-800 shadow-sm">${escapeHtml(line)}</p>`);
            });
        }

        if (reply.foot) {
            body.push(`<p class="reveal ${revealClass} mt-1">${escapeHtml(reply.foot)}</p>`);
        }

        body.push(`<span class="mt-1 block text-[10px] tabular-nums text-gray-400">${escapeHtml(time)}</span>`);
        body.push('</div>');

        bubble.innerHTML = parts.join('') + body.join('');
        return bubble;
    }

    function revealAll(bubble) {
        bubble.querySelectorAll('.reveal').forEach((el) => show(el));
        show(bubble);
    }

    function clearMessages() {
        list.innerHTML = '';
    }

    // The "typing…" indicator lives in the message flow like any other AI bubble, so it
    // scrolls naturally and never shifts the layout of the card. Returned with a
    // remove() so callers can drop it once the real reply arrives.
    function typingBubble() {
        const bubble = document.createElement('div');
        bubble.className = 'tg-msg flex items-start gap-2';
        bubble.setAttribute('data-telegram-chat', 'typing');
        bubble.innerHTML = `
            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[#229ED9] text-white">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                    <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0Zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.479.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.244-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635Z"/>
                </svg>
            </span>
            <div class="rounded-2xl rounded-tl-md border border-gray-200 bg-white px-3.5 py-2.5 shadow-sm">
                <span class="tg-dots flex items-center gap-1">
                    <span class="tg-dot h-1.5 w-1.5 rounded-full bg-gray-400"></span>
                    <span class="tg-dot h-1.5 w-1.5 rounded-full bg-gray-400"></span>
                    <span class="tg-dot h-1.5 w-1.5 rounded-full bg-gray-400"></span>
                </span>
            </div>`;
        return bubble;
    }

    async function revealReply(bubble) {
        const reveals = Array.from(bubble.querySelectorAll('.reveal'));
        for (const el of reveals) {
            show(el);
            scrollToBottom();
            await wait(TIMING.line);
        }
    }

    async function playScenario(scenario) {
        const user = userBubble(scenario.user, scenario.userTime);
        list.appendChild(user);
        // Reveal the just-added message on the next frame so the transition runs.
        requestAnimationFrame(() => show(user));
        scrollToBottom();

        await wait(TIMING.gap);
        const typing = typingBubble();
        list.appendChild(typing);
        scrollToBottom();
        await wait(TIMING.typing);
        typing.remove();

        const ai = aiBubble(scenario.reply, scenario.userTime);
        list.appendChild(ai);
        requestAnimationFrame(() => show(ai));
        await revealReply(ai);
    }

    async function loop() {
        clearMessages();
        await wait(TIMING.initial);

        for (const scenario of SCENARIOS) {
            if (!playing) {
                return;
            }
            await playScenario(scenario);
            if (!playing) {
                return;
            }
            await wait(TIMING.read);
        }

        await wait(TIMING.transition);
        if (playing) {
            loop();
        }
    }

    // Reduced-motion (and the JS-disabled fallback): show the whole conversation at
    // once, already in its final state, so it is still readable without animating.
    function renderStatic() {
        clearMessages();
        SCENARIOS.forEach((scenario) => {
            const user = userBubble(scenario.user, scenario.userTime);
            list.appendChild(user);
            const ai = aiBubble(scenario.reply, scenario.userTime);
            list.appendChild(ai);
            revealAll(user);
            revealAll(ai);
        });
    }

    function start() {
        if (reduceMotion.matches) {
            renderStatic();
            return;
        }
        if (playing) {
            return;
        }
        playing = true;
        loop();
    }

    function stop() {
        playing = false;
        clearTimer();
    }

    // Honour a live change of the reduced-motion preference.
    if (typeof reduceMotion.addEventListener === 'function') {
        reduceMotion.addEventListener('change', () => {
            stop();
            reduceMotion.matches ? renderStatic() : start();
        });
    }

    // Performance: stop when scrolled out of view, restart when it comes back.
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(
            (entries) => {
                const visible = entries[0].isIntersecting && !document.hidden;
                visible ? start() : stop();
            },
            { threshold: 0.15 },
        );
        observer.observe(root);
    } else {
        start();
    }

    // A hidden tab has no viewer; keep the timers asleep until it returns.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stop();
        }
    });

    // Kick off immediately (also covers the no-IntersectionObserver fallback above).
    start();
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


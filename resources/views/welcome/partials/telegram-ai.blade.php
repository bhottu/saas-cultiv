{{--
    Landing "AI Assistant via Telegram" section.

    A pure marketing mock: the conversation is rendered client-side by
    resources/js/telegram-demo.js from a local constant. It never calls the AI backend
    or Telegram, needs no login, and works for anonymous visitors. Numbers are
    illustrative (fictional fashion products), never a claim about real data.

    The whole chat mockup is marked role="img" so assistive tech treats it as a single
    labelled picture instead of reading out a message stream that animates forever —
    the same information is already in the static benefit list beside it.
--}}
<section id="ai-assistant" class="scroll-mt-20 py-20 sm:py-24">
    <div class="mx-auto max-w-6xl px-4">
        <div class="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
            {{-- Left: the pitch. --}}
            <div>
                <p class="inline-flex items-center gap-1.5 rounded-full border border-indigo-100 bg-indigo-50 px-3.5 py-1 text-xs font-semibold text-indigo-700">
                    <svg viewBox="0 0 24 24" fill="currentColor" class="h-3.5 w-3.5" aria-hidden="true">
                        <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0Zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.479.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.244-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635Z"/>
                    </svg>
                    {{ __('AI Assistant') }}
                </p>

                <h2 class="mt-5 text-3xl font-extrabold tracking-tight text-gray-950 sm:text-4xl">
                    {{ __('Your AI Assistant will help your business') }}
                </h2>

                <p class="mt-4 text-lg leading-relaxed text-gray-600">
                    {{ __('Connect Cultiv One to Cultiv One AI via Telegram and access your business information through conversation. Check stock, ask for a sales summary, add sales transactions, and take advantage of the various other capabilities of the AI Assistant without always opening the dashboard.') }}
                </p>

                <ul class="mt-8 space-y-4">
                    @foreach ([
                        ['cube', __('Check stock via chat'), __('Find product information in a more practical way.')],
                        ['chart-bar', __('Track your sales'), __('Ask for a business summary through conversation.')],
                        ['shopping-cart', __('Prepare a transaction'), __('Add a sales transaction directly from the AI Assistant.')],
                    ] as [$icon, $title, $body])
                        <li class="flex gap-3.5">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-indigo-100 bg-indigo-50 text-indigo-600">
                                <x-nav-icon :name="$icon" class="h-5 w-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-base font-semibold text-gray-900">{{ $title }}</p>
                                <p class="mt-0.5 text-sm leading-relaxed text-gray-600">{{ $body }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-9 flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
                    <a href="{{ route('register') }}"
                       class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        {{ __('Start free') }}
                    </a>
                    <a href="#features"
                       class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        {{ __('Explore features') }}
                    </a>
                </div>
            </div>

            {{-- Right: the animated Telegram mock. role="img" so the forever-looping
                 conversation is announced once, not read out message by message. --}}
            <div data-telegram-chat
                 role="img"
                 aria-label="{{ __('A chat with the Cultiv One AI Assistant on Telegram: checking stock, requesting a sales summary, and preparing a draft sale.') }}"
                 class="mx-auto w-full max-w-sm lg:mx-0 lg:max-w-none">
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-gray-50 shadow-xl shadow-gray-900/5">
                    {{-- Chat header: the bot identity. --}}
                    <div class="flex items-center gap-3 border-b border-gray-200 bg-white px-4 py-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#229ED9] text-white">
                            <svg viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                                <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0Zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.479.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.244-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635Z"/>
                            </svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-gray-900">{{ __('Cultiv One AI') }}</p>
                            <p class="truncate text-xs text-gray-500">{{ __('bot') }}</p>
                        </div>
                        <span class="flex items-center gap-1.5 text-xs text-gray-500">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            {{ __('online') }}
                        </span>
                    </div>

                    {{-- Message surface. Filled by telegram-demo.js. A fixed height with
                         its own scroll keeps the card from resizing the page as messages
                         arrive, so the animation never shifts the layout. --}}
                    <div data-telegram-chat="messages"
                         aria-hidden="true"
                         class="tg-scroll flex h-[26rem] flex-col gap-3 overflow-y-auto px-4 py-4 sm:h-[28rem]"></div>

                    {{-- Static note under the mock. --}}
                    <p class="border-t border-gray-200 bg-white px-4 py-2.5 text-center text-[11px] text-gray-400">
                        {{ __('Simulated example conversation.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>


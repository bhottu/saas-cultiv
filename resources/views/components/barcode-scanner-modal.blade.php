@props([
    'label' => __('Scan barcode'),
    'title' => __('Scan a barcode'),
    'hint' => __('Point the camera at the barcode on the product.'),
])

{{--
    Reusable camera scanner. Emits a bubbling `barcode-scanned` event carrying
    { code }, so the parent Alpine scope decides what to do with it (add to cart,
    filter a list, open a product). The camera is only opened while the modal is
    visible and is always released on close.
--}}
<div x-data="barcodeScanner()" @keydown.escape.window="open && close()">
    <style>[x-cloak] { display: none !important; }</style>
    <button type="button"
            x-on:click="open = true"
            class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 5v14m0-14h4m14 0v14m0-14h-4M7 9h10M7 12h6M7 15h4" />
        </svg>
        <span>{{ $label }}</span>
    </button>

    <div x-show="open" x-cloak class="fixed inset-0 z-50 overflow-y-auto px-4 py-6" role="dialog" aria-modal="true" aria-label="{{ $title }}">
        <div class="fixed inset-0 bg-gray-900/70" x-on:click="close()"></div>

        <div class="relative mx-auto w-full max-w-lg overflow-hidden rounded-xl bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <h2 class="text-base font-semibold text-gray-900">{{ $title }}</h2>
                <button type="button" x-on:click="close()" class="rounded-lg p-1 text-gray-500 hover:bg-gray-100" aria-label="{{ __('Close') }}">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="relative bg-gray-900">
                <video x-ref="video" playsinline muted
                       class="mx-auto block aspect-[4/3] w-full object-cover sm:aspect-[16/9]"></video>
                <canvas x-ref="canvas" class="hidden"></canvas>

                {{-- Framing guide --}}
                <div class="pointer-events-none absolute inset-0 flex items-center justify-center p-8">
                    <div class="w-full max-w-xs rounded-lg border-2 border-white/80 shadow-lg"></div>
                </div>

                <div x-show="starting" class="absolute inset-0 flex items-center justify-center bg-gray-900/70 text-sm text-white">
                    {{ __('Starting camera…') }}
                </div>

                <p class="absolute inset-x-0 bottom-0 bg-gray-900/70 px-3 py-2 text-center text-xs text-white">
                    {{ $hint }}
                </p>
            </div>

            {{-- Camera problems are common (no permission, no lens, http origin),
                 so a manual path is always offered instead of a dead end. --}}
            <div x-show="error" x-cloak class="border-t border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">
                <p x-text="error"></p>
            </div>

            {{--
                Deliberately NOT a <form>. This component is rendered inside the sale
                form, and a nested <form>...</form> makes the HTML parser close the
                OUTER form: every control after it (including "Record sale") would be
                orphaned and silently do nothing on click. Enter and click are wired
                explicitly so the same markup works nested and standalone.
            --}}
            <div class="border-t border-gray-200 px-4 py-3">
                <x-input-label for="manual-barcode" :value="__('Or type the code')" />
                <div class="mt-1 flex gap-2">
                    <x-text-input id="manual-barcode" type="text" inputmode="numeric" autocomplete="off"
                                  x-model="manualCode" placeholder="8991234567890"
                                  class="block w-full"
                                  x-on:keydown.enter.prevent="submitManualCode()" />
                    <x-primary-button type="button" x-on:click="submitManualCode()">{{ __('Use code') }}</x-primary-button>
                </div>
            </div>
        </div>
    </div>
</div>

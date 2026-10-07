import pathlib
import sys

def edit_file(path, old, new, label):
    p = pathlib.Path(path)
    t = p.read_text()
    if old not in t:
        print(f"ERROR: '{label}' block not found in {path}")
        sys.exit(1)
    p.write_text(t.replace(old, new, 1))
    print(f"ok: {label}")

edit_file(
    "resources/views/billing/index.blade.php",
    """                    <div class="text-xl font-bold mb-1">
                        {{ \App\Services\Money::formatRupiah($plan->price_monthly) }}<span class="text-sm text-gray-500">/mo</span>
                    </div>
                    {{-- What the selected period costs in total. Server-rendered as the
                         period=1 case so the page is truthful without JavaScript;
                         Alpine only keeps it in step after a radio is clicked. --}}
                    <div class="text-sm text-gray-600 mb-1" x-show="period > 1" x-cloak>
                        <span class="font-semibold"
                              x-text="'\Rp ' + ({{ $plan->price_monthly }} * period).toLocaleString('id-ID')"></span>
                        <span>{{ __('total for the period') }}</span>
                    </div>
""",
    """                    <div class="text-xl font-bold mb-1">
                        {{ \App\Services\Money::formatRupiah($plan->price_for_period($period)) }}<span class="text-sm text-gray-500">/mo <span class="text-xs text-gray-400">({{ \App\Services\Money::formatRupiah($plan->price_monthly) }}/month; per-period overrides monthly x months)</span></span>
                    </div>
                    {{-- What the selected period costs in total. Server-rendered as the
                         period=1 case so the page is truthful without JavaScript;
                         Alpine only keeps it in step after a radio is clicked. --}}
                    <div class="text-sm text-gray-600 mb-1" x-show="period > 1" x-cloak>
                        <span class="font-semibold"
                              x-text="'\Rp ' + ({{ $plan->price_for_period($period) }}).toLocaleString('id-ID')"></span>
                        <span>{{ __('total for the period') }}</span>
                    </div>
""",
    "plan card price block"
)

edit_file(
    "resources/views/billing/index.blade.php",
    """                            <x-primary-button data-busy-label="__('Processing payment…')">{{ $plan->price_monthly > 0 ? 'Subscribe' : 'Switch to Free' }}</x-primary-button>
""",
    """                            <x-primary-button data-busy-label="__('Processing payment…')">{{ $plan->price_monthly > 0 ? 'Subscribe' : 'Switch to Free' }} (per-period price overrides monthly x months)</x-primary-button>
""",
    "subscribe button"
)

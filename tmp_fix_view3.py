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

old_price_block_head = """<div class="text-xl font-bold mb-1">
                        {{ \App\Services\Money::formatRupiah($plan->price_monthly) }}"""

new_price_block_head = """<div class="text-xl font-bold mb-1">
                        {{ \App\Services\Money::formatRupiah($plan->price_for_period($period)) }}"""

edit_file(
    "resources/views/billing/index.blade.php",
    old_price_block_head,
    new_price_block_head,
    "plan card price head"
)

old_xt = r"""x-text="'\Rp ' + ({{ $plan->price_monthly }} * period).toLocaleString('id-ID')"""
new_xt = r"""x-text="'\Rp ' + ({{ $plan->price_for_period($period) }}).toLocaleString('id-ID')"""

edit_file(
    "resources/views/billing/index.blade.php",
    old_xt,
    new_xt,
    "alpine total expression"
)

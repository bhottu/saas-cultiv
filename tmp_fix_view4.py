import pathlib
import sys

p = pathlib.Path('resources/views/billing/index.blade.php')
t = p.read_text()

old = r"x-text=\"'\Rp ' + ({{ $plan->price_monthly }} * period).toLocaleString('id-ID')\""
new = r"x-text=\"'\Rp ' + ({{ $plan->price_for_period($period) }}).toLocaleString('id-ID')\""

if old not in t:
    print('old not found')
    sys.exit(1)

p.write_text(t.replace(old, new, 1))
print('alpine total expression updated')

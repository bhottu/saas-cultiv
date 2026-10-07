import pathlib

p = pathlib.Path('resources/views/billing/index.blade.php')
t = p.read_text()

old = r"x-text=\"'\Rp ' + ({{ $plan->price_monthly }} * period).toLocaleString('id-ID')\">"
new = r"x-text=\"'\Rp ' + ({{ $plan->price_for_period($period) }}).toLocaleString('id-ID')\">"

print('old count:', t.count(old))
if old not in t:
    print('NOT FOUND')
    i = t.find('price_monthly }} * period')
    print(repr(t[i-10:i+130]))
else:
    p.write_text(t.replace(old, new, 1))
    print('updated')

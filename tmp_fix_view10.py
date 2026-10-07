import pathlib

p = pathlib.Path('resources/views/billing/index.blade.php')
t = p.read_text()

# The file line ends with toLocaleString('id-ID')">  (note the double quote before >)
old = "x-text=\"'Rp ' + ({{ $plan->price_monthly }} * period).toLocaleString('id-ID')\">"
new = "x-text=\"'Rp ' + ({{ $plan->price_for_period($period) }}).toLocaleString('id-ID')\">"

print('old count:', t.count(old))
if old not in t:
    print('NOT FOUND')
else:
    p.write_text(t.replace(old, new, 1))
    print('updated')

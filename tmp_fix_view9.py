import pathlib

p = pathlib.Path('resources/views/billing/index.blade.php')
t = p.read_text()

target = "x-text=\"'Rp ' + ({{ $plan->price_monthly }} * period).toLocaleString('id-ID')>"
print('target:', repr(target))
print('target in t:', target in t)
print('target len:', len(target))

i = t.find('price_monthly }} * period')
j = t.find('toLocaleString', i)
print('actual:', repr(t[j-40:j+60]))
print('actual len:', len(t[j-40:j+60]))

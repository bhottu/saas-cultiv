import pathlib

p = pathlib.Path('resources/views/billing/index.blade.php')
t = p.read_text()

i = t.find('price_monthly }} * period')
print('i =', i)
seg = t[i-50:i+140]
print('seg:', repr(seg))
print('length of segment:', len(seg))

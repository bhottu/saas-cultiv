import pathlib

p = pathlib.Path('resources/views/billing/index.blade.php')
t = p.read_text()

i = t.find('price_monthly }} * period')
print('context:')
print(repr(t[i-200:i+40]))

import pathlib

p = pathlib.Path('resources/views/billing/index.blade.php')
t = p.read_text()

# Find the x-text line in the plan card (the one with price_monthly * period).
i = t.find('price_monthly }} * period')
print('found at', i)
print(repr(t[i-80:i+140]))

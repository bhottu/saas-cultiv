<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    {{-- No hardcoded <title>: the probe exists to prove <x-seo /> emits one. --}}
    <x-seo />
</head>
<body>probe</body>
</html>

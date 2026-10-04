{{--
    The site icon, from /admin/seo.

    The two static files remain the DEFAULT: when no administrator has uploaded a
    favicon, this component renders exactly the markup the layouts used before, so a
    fresh install is visually identical and can never end up with a broken <link>.

    When a custom icon IS configured it is emitted as an explicit typed link AND the
    static SVG is kept as an alternate, rather than replacing it. Browsers cache favicons
    aggressively and several of them still ask for /favicon.ico by convention; leaving a
    known-good icon behind means a stale cache degrades to the old icon instead of a
    404 in the tab strip.
--}}
@php
    $customFavicon = \App\Models\SeoSetting::configuredAsset('favicon_path');
@endphp
@if ($customFavicon)
    <link rel="icon" href="{{ $customFavicon }}">
    <link rel="alternate icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
@else
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/png" sizes="64x64" href="{{ asset('favicon-64.png') }}">
@endif
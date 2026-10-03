{{--
    The SEO layer.

    Every page calls this once and inherits a complete, consistent set of metadata.
    A page overrides only what makes it different — a title, a description, an
    image — and everything else falls back to config/seo.php.

    CAREFUL WITH THE EXAMPLES BELOW. Blade ends a comment at the first closing
    delimiter it meets, so any sample tag written after one of them stops being
    documentation and becomes real markup: the component renders itself, forever,
    until PHP runs out of memory. That happened twice while writing this file — once
    from a sample with a trailing comment on the same line, and once from this very
    paragraph describing the rule. So: never spell the closing delimiter out in the
    text, and keep sample tags free of any comment markers.

        <x-seo title="Pricing" description="..." />
        <x-seo home />
        <x-seo robots="noindex, nofollow" />

    Nothing here reads the incoming Host header for canonical or og:url — those
    come from config('seo.url'), so a visitor cannot dictate what the site claims
    its own address to be, and local requests never publish http://localhost.
--}}
@php
    $seo = app(\App\Support\Seo::class)->build(request(), array_filter([
        'title' => $title ?? ($pageTitle ?? null),
        'description' => $description ?? ($pageDescription ?? null),
        'image' => $image ?? ($pageImage ?? null),
        'canonical' => $canonical ?? ($pageCanonical ?? null),
        'type' => $type ?? null,
        'robots' => $robots ?? null,
        'home' => $home ?? null,
    ], fn ($value) => $value !== null));
@endphp

<title>{{ $seo['title'] }}</title>
<meta name="description" content="{{ $seo['description'] }}">
<meta name="robots" content="{{ $seo['robots'] }}">

<link rel="canonical" href="{{ $seo['canonical'] }}">

{{-- Open Graph. Every URL here is absolute; relative og:image and og:url are ignored
     by most social scrapers, which is why a shared link would otherwise preview
     without a picture. --}}
<meta property="og:type" content="{{ $seo['type'] }}">
<meta property="og:title" content="{{ $seo['title'] }}">
<meta property="og:description" content="{{ $seo['description'] }}">
<meta property="og:url" content="{{ $seo['url'] }}">
<meta property="og:image" content="{{ $seo['image'] }}">
<meta property="og:site_name" content="{{ $seo['site_name'] }}">
<meta property="og:locale" content="{{ $seo['locale'] }}">
<meta name="twitter:image:alt" content="{{ $seo['title'] }}">

{{-- Twitter / X card. Without a twitter:card, X shows a bare link instead of the
     large image preview that this markup is here to produce. --}}
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seo['title'] }}">
<meta name="twitter:description" content="{{ $seo['description'] }}">
<meta name="twitter:image" content="{{ $seo['image'] }}">
@if ($seo['twitter_handle'])
    {{-- Only rendered when a real account is configured. A fabricated handle would
         hand the card to whoever owns that name. --}}
    <meta name="twitter:site" content="{{ $seo['twitter_handle'] }}">
@endif

@foreach ($seo['json_ld'] as $node)
    <script type="application/ld+json">{!! json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endforeach

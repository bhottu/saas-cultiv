<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Every page inside this layout is tenant-scoped business data: dashboard,
             products, sales, stock, billing, team, admin. It must never be indexed and
             must never be previewable as a social link — a shared URL would otherwise
             expose a workspace's invoices and stock figures to whoever opened it.

             noindex, nofollow is stated explicitly here rather than left to the
             config allow-list, so the guarantee does not depend on somebody remembering
             to keep a route out of that list. The allow-list remains as the fallback for
             any page that renders <x-seo /> without saying so — such a page is unindexed
             by default rather than by request. --}}
        <x-seo robots="noindex, nofollow" />
        <meta name="theme-color" content="{{ config('seo.theme_color') }}">
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <link rel="alternate icon" type="image/png" sizes="64x64" href="{{ asset('favicon-64.png') }}">

        {{-- Cultiv One is installable as a whole app (not a POS-only PWA): the manifest
             is scoped to "/" and start_url opens the dashboard, from where every module
             is reachable. --}}
        <link rel="manifest" href="{{ asset('manifest.json') }}">
        <link rel="apple-touch-icon" href="{{ asset('pwa-192.png') }}">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="Cultiv One">
        <meta name="mobile-web-app-capable" content="yes">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <a href="#main-content"
           class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-indigo-600 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white">
            {{ __('Skip to content') }}
        </a>

        {{-- Shared application shell: sidebar + header are identical on every page, only the
             page content (and the optional "header" slot) changes per module. --}}
        <div x-data="{ sidebarOpen: false }" class="min-h-screen bg-gray-100">
            @include('layouts.navigation')

            <div class="lg:pl-72">
                @include('layouts.header')

                {{-- One responsive content wrapper for every authenticated tenant page.
                     Existing page-level max-widths keep desktop sizing intact; this
                     wrapper supplies the missing mobile breathing room without
                     double-padding desktop content.

                     `space-y-6` was removed here on purpose. Every page container
                     already opens with `py-12` (33 of 35 views), so the wrapper's own
                     24px stacked on top of it and left a 72px hole under the upgrade
                     banner — three times the 24px rhythm the rest of the page uses.
                     The banner now carries its own top margin and the page's own
                     padding is the single source of the gap below it. With no banner
                     there is only one child, so this changes nothing on any other page. --}}
                <div class="min-w-0 px-4 sm:px-0">
                    <x-upgrade-required />
                    <main id="main-content" class="min-w-0">
                        {{ $slot }}
                    </main>
                </div>
            </div>
        </div>
    </body>
</html>

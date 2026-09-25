<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Cultiv One') }} - {{ config('app.tagline', 'The smarter way to manage your business') }}</title>
        <meta name="description" content="{{ config('app.tagline', 'The smarter way to manage your business') }}">
        <meta property="og:title" content="{{ config('app.name', 'Cultiv One') }} - {{ config('app.tagline', 'The smarter way to manage your business') }}">
        <meta property="og:description" content="{{ config('app.tagline', 'The smarter way to manage your business') }}">
        <meta name="theme-color" content="#4f46e5">
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <link rel="alternate icon" type="image/png" sizes="64x64" href="{{ asset('favicon-64.png') }}">

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
                     double-padding desktop content. --}}
                <div class="min-w-0 space-y-6 px-4 sm:px-0">
                    <x-upgrade-required />
                    <main id="main-content" class="min-w-0">
                        {{ $slot }}
                    </main>
                </div>
            </div>
        </div>
    </body>
</html>

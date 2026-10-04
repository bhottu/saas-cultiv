<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Every page inside this layout is behind authentication: login, register,
             password reset, email verification. None may enter a search index, and
             none should be offered as a social preview. The page's own title comes
             from config('seo.route_titles'), keyed by route name. --}}
        <x-seo robots="noindex, nofollow" />
        <meta name="theme-color" content="{{ \App\Models\SeoSetting::config('theme_color') }}">
        <x-favicon />

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center px-4 pt-6 sm:px-0 sm:pt-0 bg-gray-100">
            <div>
                <a href="{{ route('home') }}" class="mx-auto inline-flex w-fit focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    <x-brand-lockup />
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-4 sm:px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>

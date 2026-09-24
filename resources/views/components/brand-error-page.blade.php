@props([
    'code' => 500,
    'title' => 'Something went wrong',
    'message' => 'We could not complete that request. Please try again.',
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Cultiv One') }} - {{ config('app.tagline', 'The smarter way to manage your business') }}</title>
        <meta name="description" content="{{ $message }}">
        <meta name="theme-color" content="#4f46e5">
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <link rel="alternate icon" type="image/png" sizes="64x64" href="{{ asset('favicon-64.png') }}">
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-gray-100 font-sans text-gray-900 antialiased">
        <main class="flex min-h-screen items-center justify-center px-4 py-12">
            <section class="w-full max-w-lg rounded-2xl border border-gray-200 bg-white p-8 text-center shadow-sm sm:p-10" aria-labelledby="error-title">
                <a href="{{ url('/') }}" class="mx-auto mb-8 block w-fit focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                    <x-brand-lockup />
                </a>
                <div class="text-5xl font-extrabold tracking-tight text-indigo-600">{{ $code }}</div>
                <h1 id="error-title" class="mt-3 text-2xl font-bold text-gray-950">{{ $title }}</h1>
                <p class="mt-3 text-sm leading-6 text-gray-500">{{ $message }}</p>
                <div class="mt-8 flex flex-wrap justify-center gap-3">
                    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}"
                       class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        {{ __('Go back') }}
                    </a>
                    <a href="{{ url('/') }}"
                       class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                        {{ __('Cultiv One home') }}
                    </a>
                </div>
            </section>
        </main>
    </body>
</html>
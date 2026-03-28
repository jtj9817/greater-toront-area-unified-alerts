<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script nonce="{{ request()->attributes->get('csp_nonce') }}">
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style nonce="{{ request()->attributes->get('csp_nonce') }}">
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- Social metadata: description, Open Graph, Twitter Card --}}
        <meta name="description" content="{{ config('app.description') }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:title" content="{{ config('app.name') }}">
        <meta property="og:description" content="{{ config('app.description') }}">
        <meta property="og:url" content="{{ rtrim(config('app.url'), '/') }}">
        <meta property="og:image" content="{{ rtrim(config('app.url'), '/') }}/images/social-preview.png">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="{{ config('app.description') }}">
        <meta property="og:locale" content="en_CA">

        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ config('app.name') }}">
        <meta name="twitter:description" content="{{ config('app.description') }}">
        <meta name="twitter:image" content="{{ rtrim(config('app.url'), '/') }}/images/social-preview.png">

        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>

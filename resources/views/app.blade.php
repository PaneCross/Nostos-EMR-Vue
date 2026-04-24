<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Phase O2 — PWA manifest + theme color for "Add to Home Screen" --}}
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#1e40af">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">

        {{-- FOUC prevention: apply saved theme before Vue renders --}}
        <script>
            (function () {
                var t = localStorage.getItem('nostos_theme');
                if (t === 'dark') {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        @inertiaHead

        @vite(['resources/js/app.ts'])
    </head>
    <body class="font-sans antialiased bg-gray-50 dark:bg-slate-900">
        @inertia
    </body>
</html>

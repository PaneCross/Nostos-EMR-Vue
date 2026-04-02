<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

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

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    </head>
    <body class="font-sans antialiased bg-gray-50 dark:bg-slate-900">
        @inertia
    </body>
</html>

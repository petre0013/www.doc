<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title inertia>{{ config('app.name') }}</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico" sizes="any">
        {{-- Stamp ALL theme axes BEFORE the bundle loads so the first paint
             is already themed (no flash). Mirrors resources/js/lib/theme.js:
             keep the storage keys, id lists, and fallbacks in sync. --}}
        <script>
            (function () {
                var theme = 'light';
                var accent = 'sage';
                var width = 'boxed';
                try {
                    var pref = localStorage.getItem('wwwdoc:theme') || 'system';
                    theme = pref === 'system'
                        ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                        : pref;
                    var a = localStorage.getItem('wwwdoc:accent');
                    if (['sage', 'pink', 'blue', 'rose', 'ochre'].indexOf(a) !== -1) accent = a;
                    var w = localStorage.getItem('wwwdoc:width');
                    if (['boxed', 'full'].indexOf(w) !== -1) width = w;
                } catch (e) { /* storage unavailable — keep defaults */ }
                document.documentElement.setAttribute('data-theme', theme);
                document.documentElement.setAttribute('data-accent', accent);
                document.documentElement.setAttribute('data-width', width);
            })();
        </script>
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>

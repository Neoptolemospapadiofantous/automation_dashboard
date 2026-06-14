<!DOCTYPE html>
{{-- "Two sheets, one ink": the sheet class is set by the inline script below
     before first paint (light = .sheet-white, dark = .sheet-black — see
     resources/css/tokens.css). No static class here, to avoid a flash. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Theme bootstrap — runs before CSS/paint so there's no flash of the
             wrong sheet. Honors a saved choice, else the OS preference. The
             in-app toggle (resources/js/composables/useTheme.js) keeps this in
             sync afterwards. --}}
        <script>
            (function () {
                try {
                    var t = localStorage.getItem('fs-theme');
                    if (t !== 'light' && t !== 'dark') {
                        t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                    }
                    document.documentElement.classList.add(t === 'dark' ? 'sheet-black' : 'sheet-white');
                    document.documentElement.style.colorScheme = t;
                } catch (e) {
                    document.documentElement.classList.add('sheet-white');
                }
            })();
        </script>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|jetbrains-mono:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>

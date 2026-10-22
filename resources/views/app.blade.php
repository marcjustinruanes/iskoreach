<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/png" href="{{ asset('images/iskoreach-logo.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/iskoreach-logo.png') }}">
        @viteReactRefresh
        @vite('resources/js/app.tsx')
        @inertiaHead
    </head>
    <body class="bg-slate-50 text-slate-900 antialiased">
        @inertia
    </body>
</html>

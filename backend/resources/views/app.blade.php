<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Advanced Visitor Management System (VMS) for corporate sites and campuses. Secure, auditable, and automated.">
    
    <title inertia>{{ config('app.name', 'VMS Enterprise') }}</title>

    <!-- Fonts are self-hosted (see resources/css/fonts.css) — no internet/CDN required. -->

    <!-- Styles and Scripts -->
    @viteReactRefresh
    @vite(['resources/css/app.css', 'src/app.jsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>

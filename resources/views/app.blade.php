<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#0f172a">
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.png" type="image/png">
        <style>:root { --team-primary: {{ $page['props']['team']['primary_color'] ?? '#166534' }}; --team-primary-text: {{ $page['props']['team']['primary_text'] ?? '#ffffff' }}; --team-secondary: {{ $page['props']['team']['secondary_color'] ?? '#f59e0b' }}; }</style>
        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head />
    </head>
    <body class="font-sans antialiased"><x-inertia::app /></body>
</html>

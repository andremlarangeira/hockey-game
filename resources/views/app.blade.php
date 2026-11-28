<!DOCTYPE html>
<html lang="pt-BR" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#080d1a">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="apple-touch-icon" href="/icons/icon-192.png">
        <script>
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
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.png" type="image/png">
        @if (isset($openGraph))
            <meta property="og:title" content="{{ $openGraph['title'] }}">
            <meta property="og:description" content="{{ $openGraph['description'] }}">
            <meta property="og:url" content="{{ $openGraph['url'] }}">
            <meta property="og:type" content="website">
            <meta property="og:image" content="{{ $openGraph['image'] }}">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $openGraph['title'] }}">
            <meta name="twitter:description" content="{{ $openGraph['description'] }}">
            <meta name="twitter:image" content="{{ $openGraph['image'] }}">
        @endif
        <style>:root { --team-primary: {{ $page['props']['team']['primary_color'] ?? '#166534' }}; --team-primary-text: {{ $page['props']['team']['primary_text'] ?? '#ffffff' }}; --team-secondary: {{ $page['props']['team']['secondary_color'] ?? '#f59e0b' }}; }</style>
        {{ \Illuminate\Support\Facades\Vite::fonts() }}
        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head />
    </head>
    <body class="font-sans antialiased"><x-inertia::app /></body>
</html>

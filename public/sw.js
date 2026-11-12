const VERSION = 'v2';
const PAGES_CACHE = `na-quadra-pages-${VERSION}`;
const ASSETS_CACHE = `na-quadra-assets-${VERSION}`;
const ROUTES = new Set(['/jogadores', '/sorteio', '/partida']);
const STATIC = [
    '/offline.html',
    '/manifest.webmanifest',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon-maskable-512.png',
    '/sons/apito-inicio.wav',
    '/sons/apito-fim.wav',
    '/sons/buzzer-fim.wav',
    '/sons/bip-fim-descanso.wav',
];

const pageKey = (request, inertia) => {
    const key = new URL(request.url);
    if (inertia) key.searchParams.set('__inertia', '1');
    return new Request(key.href);
};

function validPageResponse(request, response, inertia) {
    const requested = new URL(request.url);
    const actual = new URL(response.url);
    const contentType = response.headers.get('Content-Type') || '';
    return (
        !response.redirected &&
        response.status === 200 &&
        actual.pathname === requested.pathname &&
        (inertia
            ? contentType.includes('application/json') &&
              response.headers.get('X-Inertia') === 'true'
            : contentType.includes('text/html'))
    );
}

async function precacheAssets() {
    const cache = await caches.open(ASSETS_CACHE);
    const expected = new Set(
        STATIC.map((path) => new URL(path, self.location.origin).href),
    );
    for (const path of STATIC) {
        try {
            const request = new Request(path, { cache: 'no-store' });
            const response = await fetch(request);
            if (response.ok) await cache.put(request, response);
        } catch {
            // Optional assets will be retried when the app starts online.
        }
    }
    try {
        const manifestRequest = new Request('/build/manifest.json', {
            cache: 'no-store',
        });
        const manifestResponse = await fetch(manifestRequest);
        if (!manifestResponse.ok) return;
        const manifest = await manifestResponse.clone().json();
        await cache.put(manifestRequest, manifestResponse);
        expected.add(
            new URL('/build/manifest.json', self.location.origin).href,
        );
        const files = new Set();
        for (const entry of Object.values(manifest)) {
            if (entry.file) files.add(entry.file);
            for (const file of entry.css || []) files.add(file);
            for (const file of entry.assets || []) files.add(file);
        }
        for (const file of files) {
            const path = file.startsWith('/') ? file : `/build/${file}`;
            const request = new Request(path, { cache: 'no-store' });
            expected.add(request.url);
            try {
                const response = await fetch(request);
                if (response.ok) await cache.put(request, response);
            } catch {
                // The next online start will retry this asset.
            }
        }
        try {
            const fontsRequest = new Request('/build/fonts-manifest.json', {
                cache: 'no-store',
            });
            const fontsResponse = await fetch(fontsRequest);
            if (fontsResponse.ok) {
                const fonts = await fontsResponse.clone().json();
                await cache.put(fontsRequest, fontsResponse);
                expected.add(fontsRequest.url);
                const fontFiles = new Set();
                const collectFontFiles = (value) => {
                    if (Array.isArray(value)) {
                        value.forEach(collectFontFiles);
                    } else if (value && typeof value === 'object') {
                        for (const [key, item] of Object.entries(value)) {
                            if (key === 'file' && typeof item === 'string')
                                fontFiles.add(item);
                            collectFontFiles(item);
                        }
                    }
                };
                collectFontFiles(fonts);
                for (const file of fontFiles) {
                    const request = new Request(
                        file.startsWith('/') ? file : `/build/${file}`,
                        { cache: 'no-store' },
                    );
                    expected.add(request.url);
                    try {
                        const response = await fetch(request);
                        if (response.ok) await cache.put(request, response);
                    } catch {
                        // The next online start will retry this font.
                    }
                }
            }
        } catch {
            // Projects without the optional font manifest still precache normally.
        }
        const keys = await cache.keys();
        await Promise.all(
            keys
                .filter(
                    (request) =>
                        new URL(request.url).pathname.startsWith('/build/') &&
                        !expected.has(request.url),
                )
                .map((request) => cache.delete(request)),
        );
    } catch {
        // Development does not have a production manifest.
    }
}

self.addEventListener('install', (event) => event.waitUntil(precacheAssets()));
self.addEventListener('activate', (event) =>
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter(
                            (key) =>
                                key.startsWith('na-quadra-') &&
                                key !== PAGES_CACHE &&
                                key !== ASSETS_CACHE,
                        )
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    ),
);
self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
    if (event.data?.type === 'CLEAR_OFFLINE_CACHE')
        event.waitUntil(caches.delete(PAGES_CACHE));
    if (event.data?.type === 'PRECACHE_ASSETS')
        event.waitUntil(precacheAssets());
});

async function pageNetworkFirst(request, inertia) {
    const cache = await caches.open(PAGES_CACHE);
    try {
        const response = await fetch(request);
        if (validPageResponse(request, response, inertia))
            await cache.put(pageKey(request, inertia), response.clone());
        return response;
    } catch {
        const cached = await cache.match(pageKey(request, inertia));
        if (cached) return cached;
        return inertia
            ? Response.error()
            : (await caches.open(ASSETS_CACHE)).match('/offline.html');
    }
}

async function assetCacheFirst(request, networkFirst = false) {
    const cache = await caches.open(ASSETS_CACHE);
    if (!networkFirst) {
        const cached = await cache.match(request);
        if (cached) return cached;
    }
    try {
        const response = await fetch(request);
        if (response.ok) await cache.put(request, response.clone());
        return response;
    } catch {
        return (await cache.match(request)) || Response.error();
    }
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;
    const inertia = request.headers.get('X-Inertia') === 'true';
    if (ROUTES.has(url.pathname) && (request.mode === 'navigate' || inertia)) {
        event.respondWith(pageNetworkFirst(request, inertia));
        return;
    }
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(async () =>
                (await caches.open(ASSETS_CACHE)).match('/offline.html'),
            ),
        );
        return;
    }
    if (url.pathname === '/build/manifest.json') {
        event.respondWith(assetCacheFirst(request, true));
        return;
    }
    if (
        url.pathname.startsWith('/storage/jogadores/') ||
        url.pathname.startsWith('/build/') ||
        url.pathname.startsWith('/sons/') ||
        url.pathname.startsWith('/icons/')
    )
        event.respondWith(assetCacheFirst(request));
});

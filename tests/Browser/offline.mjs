// E2E do PWA com build de produção: SW registrado, sincronização da fila,
// sons da segunda partida seguida e abertura offline. Rode contra um servidor
// com banco descartável (ex.: SQLite em /tmp) e `npm run build` feito.
import { chromium } from '@playwright/test';
import assert from 'node:assert/strict';
const base = process.env.APP_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({
    executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome',
    headless: true,
    args: ['--no-sandbox'],
});
try {
    const context = await browser.newContext({
        viewport: { width: 390, height: 844 },
    });
    const errors = [];
    await context.addInitScript(() => {
        window.testSounds = [];
        const BaseAudio = window.Audio;
        window.Audio = class extends BaseAudio {
            play() {
                window.testSounds.push(new URL(this.src).pathname);
                return super.play();
            }
        };
        // Força o fallback de <audio> para registrar cada som tocado.
        window.AudioContext = undefined;
        window.webkitAudioContext = undefined;
    });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    page.on('pageerror', (error) => errors.push(error.message));
    await page.clock.install();
    await page.goto(`${base}/login`);
    await page.locator('#email').fill('demo@naquadra.test');
    await page.locator('#password').fill('hockey123');
    await page.locator('[data-test="entrar"]').click();
    await page.waitForURL('**/jogadores');
    await page.evaluate(() => navigator.serviceWorker.ready);
    await page.waitForFunction(() => !!navigator.serviceWorker.controller);
    await page.waitForFunction(async () => {
        const cache = await caches.open('na-quadra-pages-v3');
        const keys = (await cache.keys()).map(
            (request) =>
                new URL(request.url).pathname + new URL(request.url).search,
        );
        return ['/jogadores', '/sorteio', '/partida'].every((path) =>
            keys.includes(path),
        );
    });

    async function playOneMinuteMatch() {
        await page.getByRole('button', { name: 'Confirmar equipes' }).click();
        await page.locator('[data-test="minutos"]').fill('1');
        await page
            .locator('label:has-text("Número de tempos") input')
            .fill('1');
        await page.locator('[data-test="contagem"]').fill('2');
        await page.locator('[data-test="abrir-placar"]').click();
        await page.evaluate(() => {
            window.testSounds = [];
        });
        await page.locator('[data-test="iniciar-cronometro"]').click();
        await page.clock.runFor(3000);
        assert.deepEqual(
            await page.evaluate(() => window.testSounds),
            ['/sons/apito-inicio.wav'],
            'Apito uma única vez ao fim da contagem',
        );
        await page.clock.runFor(61000);
        assert.deepEqual(
            await page.evaluate(() => window.testSounds),
            ['/sons/apito-inicio.wav', '/sons/buzzer-fim.wav'],
            'Buzzer uma única vez no fim, sem repetir sons anteriores',
        );
    }

    await page.goto(`${base}/sorteio`);
    await page.locator('[data-test="sortear"]').click();
    await page.clock.runFor(800);
    await page.locator('[data-test="iniciar-partida"]').click();
    await page.waitForURL('**/partida');

    // 1ª partida salva sem conexão: fica na fila.
    await playOneMinuteMatch();
    await context.setOffline(true);
    await page.getByRole('button', { name: 'Salvar partida' }).click();
    await page.getByText('Partida guardada no aparelho').waitFor();
    // 2ª partida seguida na mesma tela.
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Iniciar nova partida' }).click();
    await playOneMinuteMatch();
    await context.setOffline(false);
    // Volta a conexão: a fila envia sozinha e "Enviar agora" funciona.
    await page.waitForFunction(
        () =>
            JSON.parse(
                Object.entries(localStorage).find(([key]) =>
                    key.startsWith('hockey-partidas-fila:'),
                )?.[1] ?? '[]',
            ).length === 0,
    );
    await page.getByRole('button', { name: 'Salvar partida' }).click();
    await page.waitForURL(/\/partidas\/\d+$/);
    await page.goto(`${base}/partidas`);
    assert.equal(
        await page.locator('a[href^="/partidas/"]').count(),
        2,
        'As duas partidas chegaram ao servidor',
    );

    // Reabrir o app sem rede.
    await context.setOffline(true);
    for (const path of ['/jogadores', '/', '/sorteio', '/partida']) {
        const offline = await context.newPage();
        const response = await offline.goto(`${base}${path}`);
        assert.ok(response?.fromServiceWorker(), `${path} veio do SW`);
        assert.equal(
            await offline.getByText('Você está sem conexão').count(),
            0,
            `${path} abre o app offline, não a página offline`,
        );
        await offline.locator('nav.fixed').waitFor();
        await offline.close();
    }
    const offline = await context.newPage();
    await offline.goto(`${base}/jogadores`);
    assert.equal(await offline.locator('[data-player-id]').count(), 16);
    await offline.close();
    if (errors.length) throw new Error(errors.join('\n'));
    console.log(
        'E2E offline OK: SW + cache do HTML, fila sincroniza, 2ª partida com apito/buzzer únicos, abre offline.',
    );
} finally {
    await browser.close();
}

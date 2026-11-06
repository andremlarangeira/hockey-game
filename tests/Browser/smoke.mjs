import { chromium } from '@playwright/test';
import assert from 'node:assert/strict';
import { mkdirSync } from 'node:fs';
const base = process.env.APP_URL || 'http://127.0.0.1:8000';
const shots = process.env.SCREENSHOT_DIR || '../hockey-times-shots';
mkdirSync(shots, { recursive: true });
const browser = await chromium.launch({
    executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome',
    headless: true,
    args: ['--no-sandbox'],
});
try {
    const page = await browser.newPage({
        viewport: { width: 844, height: 390 },
    });
    page.setDefaultTimeout(6000);
    const errors = [];
    await page.addInitScript(() => {
        window.testBeeps = [];
        window.testSounds = [];
        const BaseAudioContext = window.AudioContext;
        window.AudioContext = class extends BaseAudioContext {
            createOscillator() {
                const oscillator = super.createOscillator();
                const setValue = oscillator.frequency.setValueAtTime.bind(
                    oscillator.frequency,
                );
                oscillator.frequency.setValueAtTime = (frequency, ...args) => {
                    if (frequency === 880) window.testBeeps.push(frequency);
                    return setValue(frequency, ...args);
                };
                return oscillator;
            }
        };
        const BaseAudio = window.Audio;
        window.Audio = class extends BaseAudio {
            play() {
                window.testSounds.push(new URL(this.src).pathname);
                return super.play();
            }
        };
    });
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(`${base}/login`);
    await page.locator('#email').fill('demo@naquadra.test');
    await page.locator('#password').fill('hockey123');
    await page.locator('[data-test="entrar"]').click();
    await page.waitForURL('**/jogadores');
    assert.equal(await page.locator('[data-player-id]').count(), 16);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({
        path: `${shots}/05-jogadores-compactos-celular.png`,
        fullPage: true,
    });
    const firstPresence = page
        .locator('[data-player-id] input[type="checkbox"]')
        .first();
    const previousPresence = await firstPresence.isChecked();
    const presenceResponse = page.waitForResponse(
        (response) =>
            response.url().endsWith('/presenca') &&
            response.request().method() === 'PATCH',
    );
    await firstPresence.click();
    await presenceResponse;
    assert.equal(
        await page
            .locator('[data-player-id] input[type="checkbox"]')
            .first()
            .isChecked(),
        !previousPresence,
    );
    const restorePresence = page.waitForResponse(
        (response) =>
            response.url().endsWith('/presenca') &&
            response.request().method() === 'PATCH',
    );
    await page
        .locator('[data-player-id] input[type="checkbox"]')
        .first()
        .click();
    await restorePresence;
    assert.equal(
        await page
            .locator('[data-player-id] input[type="checkbox"]')
            .first()
            .isChecked(),
        previousPresence,
    );
    assert.equal(await page.locator('[data-player-id] svg').count(), 32);
    await page.screenshot({
        path: `${shots}/01-jogadores-celular.png`,
        fullPage: true,
    });
    await page.goto(`${base}/sorteio`);
    const drawResponse = page.waitForResponse(
        (response) =>
            response.url() === `${base}/sorteio` &&
            response.request().method() === 'POST',
    );
    await page.locator('[data-test="sortear"]').click();
    await drawResponse;
    await page.waitForTimeout(300);
    const drawIds = await page
        .locator('[data-player-id]')
        .evaluateAll((nodes) =>
            nodes.map((node) => Number(node.dataset.playerId)),
        );
    assert.equal(drawIds.length, 14);
    assert.equal(
        new Set(drawIds).size,
        14,
        'Cada presente deve entrar exatamente uma vez',
    );
    const lastReserve = page.locator('.cursor-grab').last();
    await lastReserve.scrollIntoViewIfNeeded();
    const layout = await page.evaluate(() => {
        const reserve = [
            ...document.querySelectorAll(
                '[data-test="reservas"] [data-player-id]',
            ),
        ].at(-1);
        const button = document.querySelector(
            '[data-test="iniciar-partida"]',
        ).parentElement;
        const nav = document.querySelector('nav.fixed');
        return {
            reserveBottom: reserve.getBoundingClientRect().bottom,
            barTop: button.getBoundingClientRect().top,
            barBottom: button.getBoundingClientRect().bottom,
            barPosition: getComputedStyle(button).position,
            navigationTop: nav.getBoundingClientRect().top,
            navigationPosition: getComputedStyle(nav).position,
        };
    });
    assert.equal(layout.barPosition, 'sticky');
    assert.equal(layout.navigationPosition, 'fixed');
    assert.ok(
        layout.reserveBottom <= layout.barTop,
        'A barra de preparar partida não pode cobrir o último reserva',
    );
    assert.ok(
        layout.barBottom <= layout.navigationTop,
        'A barra não pode ficar coberta pela navegação no final da página',
    );
    await page.screenshot({ path: `${shots}/02-sorteio-viewport.png` });
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    await page.screenshot({
        path: `${shots}/02-sorteio-celular.png`,
        fullPage: true,
    });
    await page.locator('[data-test="iniciar-partida"]').click();
    await page.waitForURL('**/partida');
    await page.getByRole('button', { name: 'Confirmar equipes' }).waitFor();
    await page.getByRole('button', { name: 'Confirmar equipes' }).click();
    await page.locator('[data-test="contagem"]').fill('5');
    await page
        .getByRole('button', { name: 'Testar som: Início de tempo / partida' })
        .click();
    await page
        .getByRole('button', { name: 'Testar som: Fim de tempo / partida' })
        .click();
    assert.ok(
        await page.evaluate(() =>
            window.testSounds.includes('/sons/buzzer-fim.wav'),
        ),
        'O fim de período usa o buzzer grave sintetizado',
    );
    await page.locator('[data-test="abrir-placar"]').click();
    await page.locator('[data-test="iniciar-cronometro"]').waitFor();
    await page.setViewportSize({ width: 844, height: 390 });
    await page.locator('[data-test="iniciar-cronometro"]').click();
    await page.waitForTimeout(6100);
    assert.equal(
        await page.evaluate(() => window.testBeeps.length),
        5,
        'A contagem inicial emite cinco bipes',
    );
    assert.ok(
        await page.evaluate(() =>
            window.testSounds.includes('/sons/apito-inicio.wav'),
        ),
        'A partida usa o apito sintetizado',
    );
    assert.equal(
        await page.locator('[data-test="iniciar-cronometro"]').innerText(),
        'Pausar',
    );
    await page.locator('[data-test="iniciar-cronometro"]').click();
    await page.waitForTimeout(1100);
    assert.equal(
        await page.locator('[data-test="iniciar-cronometro"]').innerText(),
        'Continuar',
    );
    assert.equal(
        await page.locator('[data-test="gol-casa"]').isDisabled(),
        true,
    );
    await page.locator('[data-test="iniciar-cronometro"]').click();
    assert.equal(
        await page.locator('[data-test="gol-casa"]').isEnabled(),
        true,
    );
    await page.locator('[data-test="gol-casa"]').click();
    const scorer = page.locator('[data-scorer-id]').first();
    const scorerId = await scorer.getAttribute('data-scorer-id');
    await page.goto(`${base}/estatisticas`);
    const goalsBefore = Number(
        await page
            .locator(`tbody tr[data-player-id="${scorerId}"] td`)
            .nth(2)
            .innerText(),
    );
    await page.goBack();
    await page.locator('[data-test="gol-casa"]').click();
    await scorer.click();
    const assistant = page.locator('[data-assist-id]').first();
    const assistId = await assistant.getAttribute('data-assist-id');
    await assistant.click();
    assert.equal(
        await page.locator('[data-test="placar-casa"]').innerText(),
        '1',
    );
    assert.notEqual(scorerId, assistId);
    await page.reload();
    assert.equal(
        await page.locator('[data-test="placar-casa"]').innerText(),
        '1',
        'F5 mantém gol',
    );
    assert.equal(
        await page.locator('[data-test="iniciar-cronometro"]').innerText(),
        'Pausar',
        'F5 mantém a contagem pós-gol rodando',
    );
    await page.locator('[data-test="contagem-gol"]').waitFor();
    assert.ok(
        (await page.locator('[data-test="contagem-gol"]').innerText()).includes(
            'APÓS O GOL',
        ),
    );
    // Após F5, uma nova interação libera o áudio nos navegadores mais restritos.
    await page.locator('[data-test="iniciar-cronometro"]').click();
    await page.locator('[data-test="iniciar-cronometro"]').click();
    await page.waitForFunction(
        () =>
            document.querySelector('[data-test="gol-casa"]').disabled === false,
    );
    await page.locator('[data-test="gol-casa"]').click();
    await page.locator('[data-scorer-id]').last().click();
    await page.locator('[data-test="sem-assistencia"]').click();
    await page.locator('[data-test="contagem-gol"]').waitFor();
    await page.waitForFunction(() => window.testBeeps.length >= 10);
    assert.ok(
        (await page.evaluate(() => window.testBeeps.length)) < 15,
        'Os bipes não são repetidos durante a contagem',
    );
    await page.screenshot({
        path: `${shots}/07-placar-contagem-apos-gol-paisagem.png`,
        fullPage: true,
    });
    await page.reload();
    await page
        .locator('[data-test="contagem-gol"]')
        .waitFor({ timeout: 10000 });
    assert.equal(
        await page.locator('[data-test="placar-casa"]').innerText(),
        '2',
    );
    await page.waitForFunction(
        () => document.querySelector('[data-test="contagem-gol"]') === null,
    );
    await page.getByRole('button', { name: 'Desfazer gol' }).click();
    assert.equal(
        await page.locator('[data-test="placar-casa"]').innerText(),
        '1',
    );
    await page.locator('[data-test="gol-fora"]').click();
    await page.locator('[data-scorer-id]').last().click();
    await page.locator('[data-test="sem-assistencia"]').click();
    assert.equal(
        await page.locator('[data-test="placar-fora"]').innerText(),
        '1',
    );
    await page.getByRole('button', { name: 'Desfazer gol' }).click();
    assert.equal(
        await page.locator('[data-test="placar-fora"]').innerText(),
        '0',
    );
    await page.getByRole('button', { name: 'Salvar partida' }).click();
    await page.waitForURL(/\/partidas\/\d+$/);
    assert.equal(await page.getByText('Partida salva').count(), 1);
    await page.screenshot({
        path: `${shots}/04-partida-salva.png`,
        fullPage: true,
    });
    await page.goto(`${base}/partidas`);
    assert.equal(
        (await page.locator('a[href^="/partidas/"]').count()) >= 1,
        true,
    );
    await page.goto(`${base}/estatisticas`);
    const scorerStats = page.locator(`tbody tr[data-player-id="${scorerId}"]`);
    assert.equal(
        await scorerStats.locator('td').nth(2).innerText(),
        String(goalsBefore + 1),
    );
    assert.equal(await scorerStats.locator('td').nth(3).innerText(), '0');
    if (errors.length) throw new Error(errors.join('\n'));
    console.log(
        'E2E OK: checkbox, cards sem estrelas, cinco bipes, apito, gols com contagem, F5, desfazer, salvar e estatísticas.',
    );
} finally {
    await browser.close();
}

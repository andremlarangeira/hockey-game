import { chromium } from '@playwright/test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
const jogadorNome = `Jogador do Teste ${Date.now()}`;
const numero = Math.floor(Math.random() * 800) + 100;
const base = process.env.APP_URL || 'http://127.0.0.1:8010';
const browser = await chromium.launch({
    executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome',
    headless: true,
    args: ['--no-sandbox'],
});
try {
    const page = await browser.newPage({
        viewport: { width: 390, height: 844 },
        hasTouch: true,
        isMobile: true,
    });
    page.setDefaultTimeout(7000);
    const sortear = async () => {
        const response = page.waitForResponse(
            (r) =>
                r.url() === `${base}/sorteio` &&
                r.request().method() === 'POST',
        );
        await page.locator('[data-test="sortear"]').click();
        await response;
        await page
            .locator('[data-test="sortear"]')
            .waitFor({ state: 'visible' });
    };
    await page.goto(`${base}/login`);
    await page.locator('#email').fill('demo@naquadra.test');
    await page.locator('#password').fill('hockey123');
    await page.locator('[data-test="entrar"]').click();
    await page.waitForURL('**/jogadores');
    await page.locator('[data-player-id]').first().waitFor();
    await page.locator('[data-test="novo-jogador"]').click();
    await page.locator('input[name="name"]').fill(jogadorNome);
    await page.locator('input[name="jersey_number"]').fill(`${numero}`);
    await page.locator('input[type="file"]').setInputFiles({
        name: 'jogador.png',
        mimeType: 'image/png',
        buffer: readFileSync(
            fileURLToPath(new URL('./jogador.png', import.meta.url)),
        ),
    });
    await page.getByRole('radio', { name: '2,5 estrelas' }).click();
    const saved = page.waitForResponse(
        (r) =>
            r.url() === `${base}/jogadores` && r.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Salvar jogador' }).click();
    await saved;
    await page
        .getByRole('button', { name: 'Novo jogador' })
        .waitFor({ state: 'visible' });
    await page
        .getByRole('button', { name: 'Salvar jogador' })
        .waitFor({ state: 'hidden' });
    await page.getByRole('heading', { name: jogadorNome }).waitFor();
    const created = page
        .locator('[data-player-id]')
        .filter({ has: page.getByRole('heading', { name: jogadorNome }) });
    const photo = created.locator('img');
    assert.equal(await photo.count(), 1);
    assert.equal(
        await photo.evaluate((img) => img.complete && img.naturalWidth > 0),
        true,
        'A foto deve abrir com sucesso pelo storage público',
    );
    assert.ok((await created.innerText()).includes('2,5 de 5'));
    const clear = page.waitForResponse(
        (r) =>
            r.url() === `${base}/presenca` && r.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Limpar', exact: true }).click();
    await clear;
    assert.equal(
        await created
            .getByRole('button', { name: 'Ausente — tocar para marcar' })
            .getAttribute('aria-pressed'),
        'false',
    );
    const all = page.waitForResponse(
        (r) =>
            r.url() === `${base}/presenca` && r.request().method() === 'POST',
    );
    await page
        .getByRole('button', { name: 'Marcar todos', exact: true })
        .click();
    await all;
    assert.equal(
        await created
            .getByRole('button', { name: 'Presente no sorteio' })
            .getAttribute('aria-pressed'),
        'true',
    );
    await page.goto(`${base}/sorteio`);
    await page
        .getByRole('button', { name: 'Número de equipes', exact: true })
        .click();
    await page.locator('select').selectOption('3');
    await sortear();
    assert.equal(
        await page.locator('input[aria-label^="Nome da equipe"]').count(),
        3,
    );
    await page
        .getByRole('button', { name: 'Jogadores por equipe', exact: true })
        .click();
    await sortear();
    const source = page
        .locator('section')
        .filter({
            has: page.getByRole('textbox', { name: 'Nome da equipe 1' }),
        })
        .locator('.cursor-grab')
        .first();
    const playerId = await source.getAttribute('data-player-id');
    const dest = page.locator('section[data-test="reservas"]');
    await dest.scrollIntoViewIfNeeded();
    await source.scrollIntoViewIfNeeded();
    const dropArea = dest.locator(':scope > div').last();
    await dropArea.scrollIntoViewIfNeeded();
    await source.scrollIntoViewIfNeeded();
    const box = await source.boundingBox();
    const destBox = await dropArea.boundingBox();
    if (!box || !destBox) throw new Error('Alvos de arrastar não encontrados');
    console.log(
        `Arraste ${playerId}: ${JSON.stringify(box)} para ${JSON.stringify(destBox)}`,
    );
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('Input.dispatchTouchEvent', {
        type: 'touchStart',
        touchPoints: [{ x: box.x + box.width / 2, y: box.y + box.height / 2 }],
    });
    await page.waitForTimeout(300);
    await cdp.send('Input.dispatchTouchEvent', {
        type: 'touchMove',
        touchPoints: [{ x: box.x + box.width / 2, y: box.y - 30 }],
    });
    await cdp.send('Input.dispatchTouchEvent', {
        type: 'touchMove',
        touchPoints: [
            {
                x: destBox.x + Math.min(destBox.width / 2, 100),
                y: destBox.y + Math.min(destBox.height / 2, 50),
            },
        ],
    });
    await cdp.send('Input.dispatchTouchEvent', {
        type: 'touchEnd',
        touchPoints: [],
    });
    await page.waitForTimeout(500);
    assert.equal(
        await source.locator(`[data-player-id="${playerId}"]`).count(),
        0,
    );
    console.log(
        'Cadastro, foto, meia estrela, presença em lote, os dois modos e gesto de toque concluídos.',
    );
    assert.equal(
        (await dest.locator(`[data-player-id="${playerId}"]`).count()) > 0,
        true,
        `O jogador arrastado deve aparecer nos reservas: ${playerId}`,
    );
} finally {
    await browser.close();
}

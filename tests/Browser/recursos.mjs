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
    await page.locator('[data-test="novo-jogador"]').click();
    await page.getByRole('radio', { name: '3,5 estrelas' }).click();
    await page.screenshot({
        path: `${process.env.SCREENSHOT_DIR || '../hockey-times-shots'}/06-formulario-nivel-estrelas.png`,
        fullPage: true,
    });
    await page.getByRole('button', { name: 'Cancelar', exact: true }).click();
    const photo = created.locator('img');
    assert.equal(await photo.count(), 1);
    assert.equal(
        await photo.evaluate((img) => img.complete && img.naturalWidth > 0),
        true,
        'A foto deve abrir com sucesso pelo storage público',
    );
    assert.ok(!(await created.innerText()).includes('2,5 de 5'));
    assert.ok((await created.innerText()).includes(`#${numero}`));
    const clear = page.waitForResponse(
        (r) =>
            r.url() === `${base}/presenca` && r.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Limpar', exact: true }).click();
    await clear;
    await page.waitForFunction(
        (name) =>
            !document.querySelector(`input[aria-label="${name}"]`)?.checked,
        `Presença de ${jogadorNome}`,
    );
    assert.equal(
        await created
            .getByRole('checkbox', { name: `Presença de ${jogadorNome}` })
            .isChecked(),
        false,
    );
    const all = page.waitForResponse(
        (r) =>
            r.url() === `${base}/presenca` && r.request().method() === 'POST',
    );
    await page
        .getByRole('button', { name: 'Marcar todos', exact: true })
        .click();
    await all;
    await page.waitForFunction(
        (name) =>
            document.querySelector(`input[aria-label="${name}"]`)?.checked,
        `Presença de ${jogadorNome}`,
    );
    assert.equal(
        await created
            .getByRole('checkbox', { name: `Presença de ${jogadorNome}` })
            .isChecked(),
        true,
    );
    const eligibleIds = await page
        .locator('[data-player-id]:has(input[type="checkbox"]:checked)')
        .evaluateAll((nodes) =>
            nodes
                .map((node) => Number(node.dataset.playerId))
                .sort((a, b) => a - b),
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
    await page.locator('select').selectOption('3');
    await sortear();

    const uniquePlayers = async () => {
        const ids = await page
            .locator('[data-player-id]')
            .evaluateAll((nodes) =>
                nodes
                    .map((node) => Number(node.dataset.playerId))
                    .sort((a, b) => a - b),
            );
        assert.deepEqual(
            ids,
            eligibleIds,
            'Cada presente deve aparecer exatamente uma vez',
        );
    };
    await uniquePlayers();

    const source = page
        .locator('section')
        .filter({
            has: page.getByRole('textbox', { name: 'Nome da equipe 1' }),
        })
        .locator('.cursor-grab')
        .first();
    const playerId = await source.getAttribute('data-player-id');
    const dest = page.locator('section[data-test="reservas"]');
    const player = dest.locator(`[data-player-id="${playerId}"]`);
    const start = await source.boundingBox();
    if (!start) throw new Error('Origem do arraste ausente');
    await page.mouse.move(start.x + 30, start.y + 30);
    await page.mouse.down();
    await page.waitForTimeout(250);
    await page.mouse.move(start.x + 35, start.y - 40, { steps: 5 });
    await dest.scrollIntoViewIfNeeded();
    const target = await dest.boundingBox();
    if (!target) throw new Error('Destino dos reservas ausente');
    const targetY = Math.min(target.y + 120, 770);
    await page.mouse.move(target.x + target.width / 2, targetY, {
        steps: 20,
    });
    await page.waitForTimeout(350);
    await page.mouse.up();

    assert.equal(
        await source.locator(`[data-player-id="${playerId}"]`).count(),
        0,
    );
    await player.waitFor({ state: 'visible' });
    await uniquePlayers();
    console.log(
        'Cadastro, foto, meia estrela, presença em lote, os dois modos e drag-and-drop concluídos, sem duplicatas.',
    );
} finally {
    await browser.close();
}

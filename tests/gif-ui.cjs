// Run with Node.js and Playwright installed: node tests/gif-ui.cjs
// APIs are mocked locally; this test never calls the paid image service.
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const gif = Buffer.from('47494638396101000100800000ff00000000ff21ff0b4e45545343415045322e30030100000021f904000a0000002c000000000100010000020244010021f904000a0000002c00000000010001000002024c01003b', 'hex');
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aA1cAAAAASUVORK5CYII=', 'base64');

(async () => {
  const server = http.createServer(async (req, res) => {
    const name = new URL(req.url, 'http://localhost').pathname;
    const files = { '/': 'index.html', '/app.js': 'app.js', '/style.css': 'style.css' };
    if (!files[name]) { res.writeHead(404).end(); return; }
    res.setHeader('Content-Type', name.endsWith('.js') ? 'text/javascript' : name.endsWith('.css') ? 'text/css' : 'text/html');
    res.end(await fs.readFile(path.join(root, files[name])));
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  let browser;
  try {
    browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHANNEL } : {}) });
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true });
    const page = await context.newPage();
    const errors = [];
    const submitted = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    await page.route('https://cdnjs.cloudflare.com/**', route => route.fulfill({ body: '' }));
    await page.route('**/api/**', async route => {
      const endpoint = new URL(route.request().url()).pathname;
      if (endpoint === '/api/image-file.php') {
        await route.fulfill({ contentType: 'image/gif', body: gif });
        return;
      }
      let body = {};
      if (endpoint === '/api/create-task.php') {
        submitted.push(route.request().postDataJSON());
        body = { taskId: `task_${String(submitted.length).padStart(32, '0')}` };
      } else if (endpoint === '/api/check-task.php') {
        body = { id: route.request().postDataJSON().taskId, status: 'queued', request: submitted.at(-1), images: [] };
      } else if (endpoint === '/api/site-status.php') {
        body = { runningTasks: 0, onlineVisitors: 1 };
      } else if (endpoint === '/api/config.php') {
        body = { apiBaseUrl: 'mocked-test-service' };
      }
      await route.fulfill({ status: endpoint === '/api/create-task.php' ? 202 : 200, contentType: 'application/json', body: JSON.stringify(body) });
    });
    await page.addInitScript(() => {
      localStorage.setItem('gpt_api_key', 'local-ui-test-key');
      localStorage.setItem('image_model', 'gpt-image-2.6-sunburst');
    });
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    await page.locator('#gifOptionsBtn').waitFor({ state: 'visible' });
    const openGif = async () => page.locator('#gifOptionsBtn').click();
    const chooseModel = async model => {
      await page.locator('[data-open="model"]').click();
      await page.locator(`[data-model="${model}"]`).click();
    };
    const submit = async () => {
      const previous = submitted.length;
      await page.locator('#prompt').fill('A cat waves, seamless loop.');
      const responsePromise = page.waitForResponse(response => response.url().includes('/api/create-task.php') && response.status() === 202);
      await page.locator('#submitBtn').click();
      await responsePromise;
      assert.equal(submitted.length, previous + 1);
      return submitted.at(-1);
    };

    await openGif();
    await page.locator('[data-output-format="gif"]').click();
    assert.match(await page.locator('#gifDurationSummary').innerText(), /1\.8 秒/);
    await page.locator('#confirmGifBtn').click();
    assert.equal(await page.locator('#referenceInput').isDisabled(), true);
    assert.equal(await page.locator('[data-open="quality"]').isVisible(), false);
    assert.deepEqual(await submit(), { prompt: 'A cat waves, seamless loop.', model: 'gpt-image-2.6-sunburst', apiKey: 'local-ui-test-key', output_format: 'gif', frame_count: 9, frame_duration_ms: 200, size: '512x512', n: 1 });

    await chooseModel('gpt-image-2.6-flare');
    await openGif();
    await page.locator('#gifFrameCount').fill('17');
    await page.locator('#confirmGifBtn').click();
    assert.match(await page.locator('#gifSettingsError').innerText(), /2–16/);
    assert.equal(await page.locator('#gifModal').isVisible(), true);
    await page.locator('#gifFrameCount').fill('16');
    await page.locator('#gifFrameDuration').fill('205');
    await page.locator('#gifSize').selectOption('custom');
    await page.locator('#gifWidth').fill('513');
    await page.locator('#confirmGifBtn').click();
    assert.match(await page.locator('#gifSettingsError').innerText(), /16/);
    await page.locator('#gifWidth').fill('768');
    await page.locator('#gifHeight').fill('512');
    await page.locator('#confirmGifBtn').click();
    await openGif();
    await page.locator('#gifFrameCount').fill('3');
    await page.keyboard.press('Escape');
    await openGif();
    assert.equal(await page.locator('#gifFrameCount').inputValue(), '16', 'unconfirmed edits are discarded');
    await page.keyboard.press('Escape');
    await page.locator('[data-open="count"]').click();
    await page.locator('[data-count="10"]').click();
    assert.deepEqual(await submit(), { prompt: 'A cat waves, seamless loop.', model: 'gpt-image-2.6-flare', apiKey: 'local-ui-test-key', output_format: 'gif', frame_count: 16, frame_duration_ms: 210, size: '768x512', n: 10 });

    // Switching to an older model clears GIF mode and restores the still-image controls.
    await chooseModel('gpt-image-2');
    assert.equal(await page.locator('#gifOptionsBtn').isVisible(), false);
    assert.equal(await page.locator('#referenceInput').isDisabled(), false);
    assert.equal(await page.locator('[data-open="quality"]').isVisible(), true);
    const still = await submit();
    assert.equal(still.model, 'gpt-image-2');
    assert.equal(still.count, 10);
    assert.equal(still.quality, 'auto');
    assert.equal(still.output_format, undefined);
    assert.equal(still.frame_count, undefined);
    assert.equal(still.exactSize, '1024x1024');

    // Existing references are retained when GIF mode is rejected.
    await chooseModel('gpt-image-2.6-flare');
    await page.locator('#referenceInput').setInputFiles({ name: 'reference.png', mimeType: 'image/png', buffer: png });
    await page.locator('[data-remove-ref="0"]').waitFor({ state: 'visible' });
    await openGif();
    await page.locator('[data-output-format="gif"]').click();
    assert.match(await page.locator('#gifSettingsError').innerText(), /移除参考图/);
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#gifOptionsBtn').evaluate(element => element === document.activeElement), true);
    assert.equal(await page.locator('[data-remove-ref="0"]').count(), 1);
    await page.locator('[data-remove-ref="0"]').click();
    await openGif();
    await page.locator('[data-output-format="gif"]').click();
    await page.locator('#gifSize').selectOption('512x512');

    for (const viewport of [{ width: 1280, height: 900 }, { width: 375, height: 812 }, { width: 812, height: 375 }]) {
      await page.setViewportSize(viewport);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'no horizontal page overflow');
      assert.equal(await page.locator('#gifModal .modal-card').evaluate(element => element.scrollWidth <= element.clientWidth), true, 'no horizontal dialog overflow');
      await page.locator('#confirmGifBtn').focus();
      await page.keyboard.press('Tab');
      assert.equal(await page.locator('[data-close="gif"]').evaluate(element => element === document.activeElement), true, 'focus stays inside dialog');
      if (process.env.GIF_SCREENSHOT_DIR) {
        await fs.mkdir(process.env.GIF_SCREENSHOT_DIR, { recursive: true });
        await page.locator('#gifModal .modal-card').evaluate(element => { element.scrollTop = 0; });
        await page.screenshot({ path: path.join(process.env.GIF_SCREENSHOT_DIR, `gif-${viewport.width}x${viewport.height}.png`) });
      }
    }
    await page.keyboard.press('Escape');
    await page.setViewportSize({ width: 1280, height: 900 });

    // Exercise real preview and ZIP download using a two-frame GIF fixture.
    await page.evaluate(() => {
      const tasks = [1, 2].map(index => ({ id: `task_${String(index + 100).padStart(32, '0')}`, status: 'succeeded', createdAt: Date.now(), finishedAt: Date.now(), request: { model: 'gpt-image-2.6-flare', prompt: 'Animated cat', output_format: 'gif', frame_count: 2, frame_duration_ms: 100, size: '512x512' }, images: [{ url: `/api/image-file.php?taskId=fixture${index}&index=0` }] }));
      localStorage.setItem('image_tasks', JSON.stringify(tasks));
    });
    await page.reload();
    await page.waitForFunction(() => [...document.querySelectorAll('#gallery img')].some(image => image.src.startsWith('blob:') && image.naturalWidth > 0));
    assert.match(await page.locator('#gallery').innerText(), /GIF.*2帧.*0\.2秒/);
    await page.locator('#selectAllBtn').click();
    const downloadPromise = page.waitForEvent('download');
    await page.locator('#downloadBtn').click();
    const download = await downloadPromise;
    assert.match(download.suggestedFilename(), /\.zip$/);
    const archive = await fs.readFile(await download.path());
    let offset = 0;
    let entries = 0;
    while (archive.readUInt32LE(offset) === 0x04034b50) {
      const size = archive.readUInt32LE(offset + 18);
      const nameLength = archive.readUInt16LE(offset + 26);
      const extraLength = archive.readUInt16LE(offset + 28);
      const dataOffset = offset + 30 + nameLength + extraLength;
      assert.match(archive.subarray(offset + 30, offset + 30 + nameLength).toString('utf8'), /\.gif$/);
      assert.deepEqual(archive.subarray(dataOffset, dataOffset + size), gif, 'ZIP keeps every animation byte');
      entries++;
      offset = dataOffset + size;
    }
    assert.equal(entries, 2);
    assert.deepEqual(errors, []);
    console.log('PASS: GIF UI, validation, protocol switching, 3 viewport sizes, preview and animated ZIP download (mock APIs).');
  } finally {
    await browser?.close();
    await new Promise(resolve => server.close(resolve));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });

'use strict';
// npm install --prefix .runtime/ui-test playwright
// First run: php database/test_report_access.php and php -d extension=zip database/test_dataset_access.php.
const { chromium } = require('../.runtime/ui-test/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const output = path.join(root, '.runtime/ui-test');
const evidence = [];
const pass = name => { evidence.push(name); console.log('PASS:', name); };
async function run() {
  const browser = await chromium.launch({channel: 'chrome', headless: true});
  try {
    const page = await browser.newPage();
    page.setDefaultTimeout(10000);
    const html = fs.readFileSync(path.join(root, '.runtime/report-test/preview.html'), 'utf8').replace(/<base href="[^"]+">/, '<base href="https://ui.test/reports.php">');
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.hostname === 'ui.test' && url.pathname === '/import_records.php') {
        const imported = fs.readFileSync(path.join(root, '.runtime/import-test/preview.html'), 'utf8').replace(/<base href="[^"]+">/, '<base href="https://ui.test/import_records.php">');
        return route.fulfill({contentType: 'text/html', body: imported});
      }
      if (url.hostname === 'ui.test' && url.pathname === '/reports.php') return route.fulfill({contentType: 'text/html', body: html.replace('<main class="main" id="main-content" tabindex="-1">', '<main class="main" id="main-content" tabindex="-1"><div class="alert alert-danger">Please correct the invalid date.</div>')});
      if (url.pathname.endsWith('/notifications.php')) return route.fulfill({json: {unread: 0, notifications: []}});
      let relative = url.hostname === 'ui.test' ? decodeURIComponent(url.pathname).slice(1) : '';
      if (url.hostname === 'cdn.jsdelivr.net' && url.pathname.endsWith('bootstrap.min.css')) relative = 'Arsha/assets/vendor/bootstrap/css/bootstrap.min.css';
      if (url.hostname === 'cdn.jsdelivr.net' && url.pathname.endsWith('bootstrap.bundle.min.js')) relative = 'Arsha/assets/vendor/bootstrap/js/bootstrap.bundle.min.js';
      if (url.hostname === 'cdn.jsdelivr.net' && url.pathname.endsWith('bootstrap-icons.min.css')) relative = 'Arsha/assets/vendor/bootstrap-icons/bootstrap-icons.min.css';
      if (url.hostname === 'cdn.jsdelivr.net' && /bootstrap-icons\.(woff2|woff)$/.test(url.pathname)) relative = 'Arsha/assets/vendor/bootstrap-icons/fonts/' + path.basename(url.pathname);
      const local = path.resolve(root, relative);
      if (relative && local.startsWith(root + path.sep) && fs.existsSync(local) && fs.statSync(local).isFile()) return route.fulfill({path: local});
      return route.fulfill({status: 404, body: ''});
    });
    await page.goto('https://ui.test/reports.php');
    for (const width of [1440, 768, 390]) {
      await page.setViewportSize({width, height: 900});
      for (const dark of [false, true]) {
        await page.evaluate(dark => document.documentElement.classList.toggle('dark', dark), dark);
        await page.waitForTimeout(250); // Let the theme transition finish before measuring/capturing.
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Page overflow at ' + width);
        await page.screenshot({path: path.join(output, `reports-${width}-${dark ? 'dark' : 'light'}.png`), fullPage: true});
      }
    }
    pass('Report preview: no page overflow at 1440/768/390px in light and dark themes');
    for (const dark of [false, true]) {
      await page.evaluate(dark => document.documentElement.classList.toggle('dark', dark), dark);
      await page.waitForTimeout(250);
      const contrast = await page.evaluate(() => {
        const style = getComputedStyle(document.documentElement);
        const luminance = hex => {
          const rgb = hex.trim().replace('#','').match(/../g).map(v => parseInt(v,16)/255).map(v => v <= .04045 ? v/12.92 : ((v+.055)/1.055)**2.4);
          return rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722;
        };
        const ratio = (a,b) => { a=luminance(a); b=luminance(b); return (Math.max(a,b)+.05)/(Math.min(a,b)+.05); };
        const results = [];
        for (const foreground of ['--text-primary','--text-secondary','--text-muted','--text-subtle']) {
          for (const background of ['--bg','--surface','--surface2','--surface3']) results.push({foreground, background, ratio:ratio(style.getPropertyValue(foreground),style.getPropertyValue(background))});
        }
        results.push({foreground:'primary button white',background:'#2b5d96',ratio:ratio('#ffffff','#2b5d96')});
        return results;
      });
      assert(contrast.every(item => item.ratio >= 4.5), JSON.stringify(contrast.filter(item => item.ratio < 4.5)));
      fs.writeFileSync(path.join(output, `contrast-${dark ? 'dark' : 'light'}.json`), JSON.stringify(contrast,null,2));
    }
    pass('34 shared solid-color text/surface pairs meet 4.5:1; excludes page-specific overrides and translucent backgrounds');
    await page.setViewportSize({width: 1440, height: 900});
    await page.reload();
    await page.keyboard.press('Tab');
    assert.equal(await page.locator(':focus').textContent(), 'Skip to main content');
    await page.keyboard.press('Enter');
    assert.equal(await page.locator(':focus').getAttribute('id'), 'main-content');
    pass('Keyboard skip link focuses main content');
    await page.locator('#account-menu-toggle').focus();
    await page.keyboard.press('Enter');
    await page.locator('#account-menu-dropdown.is-open').waitFor({state: 'visible'});
    await page.keyboard.press('Tab');
    assert.match(await page.locator(':focus').textContent(), /My Profile/);
    await page.keyboard.press('Escape');
    assert.equal(await page.locator(':focus').getAttribute('id'), 'account-menu-toggle');
    assert.equal(await page.locator('#account-menu-toggle').getAttribute('aria-expanded'), 'false');
    pass('Account disclosure: Enter, Tab, Escape and focus restoration');
    await page.setViewportSize({width: 390, height: 844});
    await page.locator('#sidebar-toggle').click();
    assert.equal(await page.locator('#main-content').evaluate(el => el.inert), true);
    assert.equal(await page.locator(':focus').getAttribute('class'), 'sidebar-close');
    await page.keyboard.press('Shift+Tab');
    assert(await page.locator(':focus').evaluate(el => !!el.closest('.sidebar')));
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#main-content').evaluate(el => el.inert), false);
    assert.equal(await page.locator(':focus').getAttribute('id'), 'sidebar-toggle');
    pass('Mobile sidebar: background inert, focus trap, Escape and focus return');
    const result = await page.evaluate(async () => {
      const form = document.createElement('form'); form.method = 'post';
      form.innerHTML = '<label for="test-name">Name</label><input id="test-name" name="name" required><button name="action" value="remove">Remove</button>';
      document.querySelector('main').append(form);
      const button = form.querySelector('button');
      let count = 0; form.addEventListener('submit', () => count++);
      form.requestSubmit(button);
      const invalid = count === 0 && !form.hasAttribute('aria-busy');
      form.querySelector('input').value = 'Example';
      const event = new SubmitEvent('submit', {bubbles: true, cancelable: true, submitter: button});
      form.dispatchEvent(event);
      const payload = new FormData(form, button).get('action');
      const loading = form.getAttribute('aria-busy') === 'true' && !button.disabled;
      const duplicate = new SubmitEvent('submit', {bubbles: true, cancelable: true, submitter: button});
      form.dispatchEvent(duplicate);
      window.dispatchEvent(new PageTransitionEvent('pageshow', {persisted: true}));
      const restored = !form.hasAttribute('aria-busy') && button.textContent === 'Remove';
      window.addEventListener('submit', e => e.preventDefault(), {once: true});
      form.dispatchEvent(new SubmitEvent('submit', {bubbles: true, cancelable: true, submitter: button}));
      await Promise.resolve();
      const canceled = !form.hasAttribute('aria-busy');
      form.remove();
      return {invalid, payload, loading, duplicate: duplicate.defaultPrevented, restored, canceled};
    });
    assert.deepEqual(result, {invalid:true, payload:'remove', loading:true, duplicate:true, restored:true, canceled:true});
    pass('Required validation, submit action preserved, busy feedback, duplicate guard, Back navigation and canceled-handler recovery');
    await page.waitForTimeout(4500);
    assert(await page.locator('.alert-danger').isVisible());
    pass('Error feedback remains visible beyond former four-second dismissal');
    for (const width of [1440, 768, 390]) {
      await page.setViewportSize({width, height:900});
      await page.goto('https://ui.test/import_records.php');
      for (const dark of [false,true]) {
        await page.evaluate(dark => document.documentElement.classList.toggle('dark',dark),dark);
        await page.waitForTimeout(250);
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Import page overflow at '+width);
        await page.screenshot({path:path.join(output, `import-${width}-${dark ? 'dark' : 'light'}.png`),fullPage:true});
      }
    }
    pass('Dataset preview: no page overflow at 1440/768/390px in light and dark themes');
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({date: new Date().toISOString(), checks: evidence, scope:'Synthetic reports preview and shared layout, not live deployment or screen-reader certification'}, null, 2));
  } finally { await browser.close(); }
}
run().catch(error => { console.error(error); process.exitCode = 1; });

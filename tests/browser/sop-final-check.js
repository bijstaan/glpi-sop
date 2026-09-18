// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
const { chromium } = require('playwright');
const BASE = 'http://localhost:8081';
const fail = [];
const check = (n,c,d)=>{console.log(`${c?'PASS':'FAIL'}  ${n}${d?' :: '+d:''}`); if(!c) fail.push(n);};

const phpNoise = (html) =>
  /Warning<\/b>|Notice<\/b>|Fatal error|Deprecated<\/b>|Uncaught|Undefined (variable|index|array key)/.test(html);

(async () => {
  const browser = await chromium.launch();
  const page = await (await browser.newContext({viewport:{width:1500,height:1300}})).newPage();
  const errs = []; page.on('pageerror', e => errs.push(e.message));
  await page.goto(`${BASE}/`, {waitUntil:'networkidle'});
  await page.fill('#login_name','glpi'); await page.fill('input[type=password]','glpi');
  await page.click('button[type=submit]'); await page.waitForLoadState('networkidle');

  // --- the editor renders every step type clean, in place ---
  //
  // Retyping a step fetches the panel that belongs to the new type. What is
  // being checked is that each of those panels renders without PHP noise and
  // brings its own controls — the old test did this by saving and reloading a
  // page per type, which is exactly the round trip the editor removed.
  const TAB = encodeURIComponent('GlpiPlugin\\Glpisop\\SopBuilderTab$1');
  const canvas = `${BASE}/plugins/glpisop/front/sop.form.php?id=2&forcetab=${TAB}`;

  await page.goto(canvas, {waitUntil:'networkidle'});
  await page.waitForSelector('[data-sop-builder][data-sop-ready="1"]');
  await page.waitForTimeout(400);

  const first = page.locator('[data-sop-blocks] [data-sop-step]').first();
  await first.locator('[data-sop-field="label"]').click();
  await page.waitForTimeout(200);

  const typed = {
    text: 'sopcfg_pattern',
    number: 'sopcfg_min',
    choice: 'sopcfg_options',
    asset: 'sopcfg_itemtypes',
    approval: 'sopcfg_require_status',
    ticket: 'sopcfg_complete_on',
  };

  const original = await first.locator('[data-sop-field="type"]').inputValue();
  for (const [type, control] of Object.entries(typed)) {
    await first.locator('[data-sop-field="type"]').selectOption(type);
    await page.waitForSelector(`[data-sop-step].sop-step--open [name="${control}"]`, {timeout:8000});
    check(`"${type}" brings its own options in place`, true);
    check(`"${type}" options render clean`, !phpNoise(await page.content()));
  }
  await first.locator('[data-sop-field="type"]').selectOption(original);
  await page.waitForTimeout(500);

  // Nothing above was saved; leaving is the discard, so dismiss the guard.
  page.on('dialog', d => d.accept());

  // --- builder tab and the ticket the original run lives on ---
  await page.goto(canvas, {waitUntil:'networkidle'});
  await page.waitForSelector('[data-sop-builder][data-sop-ready="1"]');
  check('builder tab renders clean', !phpNoise(await page.content()));

  await page.goto(`${BASE}/front/ticket.form.php?id=1095&forcetab=${encodeURIComponent('Ticket$main')}`, {waitUntil:'networkidle'});
  await page.waitForSelector('.sop-container', {timeout:20000});
  check('the pre-existing run still renders on its ticket', !phpNoise(await page.content()));
  const steps = await page.locator('.sop-run .sop-step').count();
  console.log('  steps rendered on ticket 1095:', steps,
              '| progress:', await page.locator('.sop-progress-text').first().innerText());

  // --- the trigger tab still works after the value-control refactor ---
  await page.goto(`${BASE}/plugins/glpisop/front/sop.form.php?id=2&forcetab=${encodeURIComponent('GlpiPlugin\\Glpisop\\SopTriggerTab$1')}`, {waitUntil:'networkidle'});
  await page.waitForTimeout(1000);
  check('trigger tab renders clean', !phpNoise(await page.content()) &&
        await page.locator('select[name="criterion"]').count() > 0);

  check('no javascript errors anywhere', errs.length === 0, errs.join(' | '));
  console.log(fail.length ? 'FAILURES: '+fail.join(', ') : 'all final checks passed');
  await browser.close();
  process.exit(fail.length?1:0);
})();

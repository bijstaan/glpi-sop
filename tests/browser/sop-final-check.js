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

  // --- every step editor renders clean, for every type ---
  const stepUrl = id => `${BASE}/plugins/glpisop/front/step.form.php?id=${id}`;
  for (const id of [3,4,6,7,11]) {
    await page.goto(stepUrl(id), {waitUntil:'networkidle'});
    const html = await page.content();
    check(`step ${id} editor renders clean`, !phpNoise(html) &&
      await page.locator('.card', {hasText:'Ask this step only when'}).count() > 0);
  }

  // --- switch a step's type through each new one and back ---
  await page.goto(stepUrl(11), {waitUntil:'networkidle'});
  const original = await page.locator('select[name="step_type"]').inputValue();
  for (const t of ['yesno','ticket']) {
    await page.locator('select[name="step_type"]').selectOption(t);
    await Promise.all([page.waitForNavigation({waitUntil:'networkidle'}), page.click('button[name="update"]')]);
    await page.goto(stepUrl(11), {waitUntil:'networkidle'});
    const now = await page.locator('select[name="step_type"]').inputValue();
    check(`step type saves as "${t}"`, now === t, now);
    check(`"${t}" options card renders clean`, !phpNoise(await page.content()));
  }
  // the ticket type's own options must be there
  check('ticket step exposes its completion mode',
        await page.locator('select[name="cfg_complete_on"]').count() === 1);
  await page.locator('select[name="step_type"]').selectOption(original);
  await Promise.all([page.waitForNavigation({waitUntil:'networkidle'}), page.click('button[name="update"]')]);
  await page.goto(stepUrl(11), {waitUntil:'networkidle'});
  check('step type restored', await page.locator('select[name="step_type"]').inputValue() === original);

  // --- builder tab and the ticket the original run lives on ---
  await page.goto(`${BASE}/plugins/glpisop/front/sop.form.php?id=2&forcetab=${encodeURIComponent('GlpiPlugin\\Glpisop\\SopBuilderTab$1')}`, {waitUntil:'networkidle'});
  await page.waitForSelector('.sop-builder-steps');
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

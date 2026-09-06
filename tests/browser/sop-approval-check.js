// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const BASE = 'http://localhost:8081';
const fail = [];
const check = (n,c,d)=>{console.log(`${c?'PASS':'FAIL'}  ${n}${d?' :: '+d:''}`); if(!c) fail.push(n);};
const sql = q => execSync(`docker exec glpi-db-1 mariadb -uglpi -pglpi glpi -N -B -e ${JSON.stringify(q)}`).toString().trim();

(async () => {
  const browser = await chromium.launch();
  const page = await (await browser.newContext({viewport:{width:1500,height:1200}})).newPage();
  page.on('pageerror', e => console.log('PAGEERROR:', e.message));
  await page.goto(`${BASE}/`, {waitUntil:'networkidle'});
  await page.fill('#login_name','glpi'); await page.fill('input[type=password]','glpi');
  await page.click('button[type=submit]'); await page.waitForLoadState('networkidle');

  const open = async () => {
    await page.goto(`${BASE}/front/ticket.form.php?id=1163&forcetab=${encodeURIComponent('Ticket$main')}`, {waitUntil:'networkidle'});
    await page.waitForSelector('.sop-run[data-sop-run="3"]', {timeout:20000});
    await page.waitForTimeout(400);
  };
  const approvalStep = () => page.locator('.sop-run[data-sop-run="3"] .sop-step').first();
  const gatedStep    = () => page.locator('.sop-run[data-sop-run="3"] .sop-step').nth(1);

  await open();
  console.log('approval step reads:', (await approvalStep().innerText()).replace(/\s+/g,' '));
  check('approval step renders its current state and requirement',
        /Currently/.test(await approvalStep().innerText()) &&
        /needs the item approved/.test(await approvalStep().innerText()));
  check('it offers no control to tick',
        await approvalStep().locator('.sop-step-input input, .sop-step-input select').count() === 0);
  check('the step gated on it is closed', await gatedStep().getAttribute('hidden') !== null);
  check('the run is outstanding', /1 required step outstanding/.test(
        await page.locator('.sop-run[data-sop-run="3"] .sop-required-text').innerText()));

  // --- put the ticket into "waiting for approval" ---
  sql("UPDATE glpi_tickets SET global_validation=2 WHERE id=1163");
  sql("UPDATE glpi_plugin_glpisop_runs SET date_creation=date_creation WHERE id=3"); // no-op
  await open();
  console.log('after WAITING:', (await approvalStep().innerText()).replace(/\s+/g,' '));
  check('a pending approval shows as waiting, still outstanding',
        /Waiting for approval/.test(await approvalStep().innerText()) &&
        await gatedStep().getAttribute('hidden') !== null);

  // --- grant it ---
  sql("UPDATE glpi_tickets SET global_validation=3 WHERE id=1163");
  await open();
  console.log('after GRANTED:', (await approvalStep().innerText()).replace(/\s+/g,' '));
  check('a granted approval satisfies the step', /Granted/.test(await approvalStep().innerText()));
  check('and opens what was gated on it', await gatedStep().getAttribute('hidden') === null);
  check('the run stops being outstanding on that step',
        !/2 required steps outstanding/.test(await page.locator('.sop-run[data-sop-run="3"] .sop-required-text').innerText()),
        await page.locator('.sop-run[data-sop-run="3"] .sop-required-text').innerText());

  // --- withdraw it: the step must reopen ---
  sql("UPDATE glpi_tickets SET global_validation=4 WHERE id=1163");
  await open();
  console.log('after REFUSED:', (await approvalStep().innerText()).replace(/\s+/g,' '));
  check('a refused approval takes the answer back', /Refused/.test(await approvalStep().innerText()));
  check('and closes what depended on it', await gatedStep().getAttribute('hidden') !== null);

  console.log('\nrun log:\n' + sql("SELECT action, detail FROM glpi_plugin_glpisop_runlogs WHERE plugin_glpisop_runs_id=3 ORDER BY id"));
  await page.screenshot({path:'sop-approval.png'});
  console.log(fail.length ? 'FAILURES: '+fail.join(', ') : 'all approval checks passed');
  await browser.close();
  process.exit(fail.length?1:0);
})();

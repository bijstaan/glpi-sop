// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
const { chromium } = require('playwright');
const BASE = 'http://localhost:8081';
const fail = [];
const check = (n,c,d)=>{console.log(`${c?'PASS':'FAIL'}  ${n}${d?' :: '+d:''}`); if(!c) fail.push(n);};
const step = (page,id)=>page.locator(`.sop-step[data-sop-step-id="${id}"]`);
const shown = async (page,id)=> !(await step(page,id).getAttribute('hidden') !== null);

async function openTicket(page, id) {
  await page.goto(`${BASE}/front/ticket.form.php?id=${id}&forcetab=${encodeURIComponent('Ticket$main')}`, {waitUntil:'networkidle'});
  await page.waitForSelector('.sop-container', {timeout:20000});
  await page.waitForTimeout(600);
}

(async () => {
  const browser = await chromium.launch();
  const page = await (await browser.newContext({viewport:{width:1500,height:1200}})).newPage();
  page.on('pageerror', e => console.log('PAGEERROR:', e.message));
  await page.goto(`${BASE}/`, {waitUntil:'networkidle'});
  await page.fill('#login_name','glpi'); await page.fill('input[type=password]','glpi');
  await page.click('button[type=submit]'); await page.waitForLoadState('networkidle');

  await openTicket(page, 1164);

  const run = page.locator('.sop-run[data-sop-run="2"]');
  check('the scratch run renders', await run.count() === 1);

  check('Yes/No step renders two radios', await step(page,16).locator('input[type=radio]').count() === 2);
  check('"Order a licence" is closed at the start', await step(page,17).getAttribute('hidden') !== null);
  check('"Assign the licence" is closed at the start', await step(page,18).getAttribute('hidden') !== null);
  console.log('progress:', await page.locator('.sop-run[data-sop-run="2"] .sop-progress-text').innerText());

  // --- answer No: the remediation branch must open ---
  await step(page,16).locator('input[type=radio][value="no"]').check();
  await page.waitForTimeout(2000);
  check('answering No opens the licensing branch', await step(page,17).getAttribute('hidden') === null);
  check('and leaves "Assign the licence" closed', await step(page,18).getAttribute('hidden') !== null);
  console.log('progress after No:', await page.locator('.sop-run[data-sop-run="2"] .sop-progress-text').innerText());
  console.log('required after No:', await page.locator('.sop-run[data-sop-run="2"] .sop-required-text').innerText());

  // --- answer Yes: it must close again, and the OR branch open ---
  await step(page,16).locator('input[type=radio][value="yes"]').check();
  await page.waitForTimeout(2000);
  check('answering Yes closes the licensing branch again', await step(page,17).getAttribute('hidden') !== null);
  check('and opens "Assign the licence" via the OR', await step(page,18).getAttribute('hidden') === null);

  // --- back to No, then raise the child ticket ---
  await step(page,16).locator('input[type=radio][value="no"]').check();
  await page.waitForTimeout(2000);
  const raise = step(page,17).locator('button[data-sop-action="spawn"]');
  check('the ticket step offers a raise button', await raise.count() === 1);
  page.once('dialog', d => d.accept());
  await raise.click();
  await page.waitForTimeout(3000);
  await openTicket(page, 1164);

  const linked = step(page,17).locator('.sop-child-ticket-link a');
  check('a child ticket was raised and linked', await linked.count() >= 1,
        await linked.count() ? await step(page,17).locator('.sop-child-ticket').innerText() : 'none');
  const waiting = await step(page,17).locator('.sop-child-ticket').innerText();
  check('and the step says it is waiting on it', /solved/i.test(waiting), waiting.replace(/\s+/g,' '));
  check('"Assign the licence" is still closed while the child is open',
        await step(page,18).getAttribute('hidden') !== null);
  console.log('required now:', await page.locator('.sop-run[data-sop-run="2"] .sop-required-text').innerText());

  await page.screenshot({path:'sop-runtime.png'});
  console.log(fail.length ? 'FAILURES: '+fail.join(', ') : 'all runtime checks passed');
  await browser.close();
  process.exit(fail.length?1:0);
})();

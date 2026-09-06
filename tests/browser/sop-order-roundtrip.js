// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
const { chromium } = require('playwright');
const BASE = 'http://localhost:8081';
const fail = [];
const check = (n,c,d)=>{console.log(`${c?'PASS':'FAIL'}  ${n}${d?' :: '+d:''}`); if(!c) fail.push(n);};

// label + section, so a refile is visible to the assertion
const snap = async (page) => (await page.locator('.sop-builder-steps tbody tr').all()).length
  ? Promise.all((await page.locator('.sop-builder-steps tbody tr').all()).map(async r => {
      const tds = await r.locator('td').allInnerTexts();
      return `${tds[1].split('\n')[0].trim()} [${tds[3].trim()}]`;
    }))
  : [];

(async () => {
  const browser = await chromium.launch();
  const page = await (await browser.newContext({viewport:{width:1500,height:1200}})).newPage();
  page.on('pageerror', e => console.log('PAGEERROR:', e.message));
  await page.goto(`${BASE}/`, {waitUntil:'networkidle'});
  await page.fill('#login_name','glpi'); await page.fill('input[type=password]','glpi');
  await page.click('button[type=submit]'); await page.waitForLoadState('networkidle');
  const url = `${BASE}/plugins/glpisop/front/sop.form.php?id=2&forcetab=${encodeURIComponent('GlpiPlugin\\Glpisop\\SopBuilderTab$1')}`;

  const press = async (rowIdx, dir) => {
    const b = page.locator('.sop-builder-steps tbody tr').nth(rowIdx)
        .locator(`form:has(input[name="move"][value="${dir}"]) button`);
    if (!await b.count()) return null;
    const title = await b.getAttribute('title');
    await Promise.all([page.waitForNavigation({waitUntil:'networkidle'}), b.click()]);
    await page.waitForSelector('.sop-builder-steps');
    return title;
  };

  await page.goto(url, {waitUntil:'networkidle'});
  await page.waitForSelector('.sop-builder-steps');
  const start = await snap(page);
  console.log('START:'); start.forEach(r=>console.log('  '+r));

  // 1 press down on row 1 = cross the heading only
  let t = await press(1, 'down');
  const s1 = await snap(page);
  console.log(`\nafter down #1 (tooltip: ${t}):`); s1.forEach(r=>console.log('  '+r));
  check('first press crosses the heading without moving',
        s1.map(x=>x.split(' [')[0]).join('|') === start.map(x=>x.split(' [')[0]).join('|')
        && s1[1] !== start[1], s1[1]);

  // 2nd press down = a real move
  t = await press(1, 'down');
  const s2 = await snap(page);
  console.log(`\nafter down #2 (tooltip: ${t}):`); s2.forEach(r=>console.log('  '+r));
  check('second press moves it', s2[1] !== s1[1] && s2[2] === s1[1], s2.slice(1,3).join(' / '));

  // back up twice
  await press(2, 'up');
  await press(1, 'up');
  const back = await snap(page);
  console.log('\nafter two ups:'); back.forEach(r=>console.log('  '+r));
  check('two presses back is an exact inverse, section included',
        JSON.stringify(back) === JSON.stringify(start), JSON.stringify(back));

  console.log(fail.length ? '\nFAILURES: ' + fail.join(', ') : '\nround trip is exact');
  await browser.close();
  process.exit(fail.length?1:0);
})();

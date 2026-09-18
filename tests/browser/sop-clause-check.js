// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// The trigger tab's criterion row, which used to need a reload button. This
// drives the in-place cascade and then actually saves a trigger through it.
//
// A step's gate was tested here too, when it was the same machinery on a page
// of its own. It is not any more — the editor builds a clause from the canvas —
// so that half lives in sop-builder-check.js.
const { chromium } = require('playwright');
const BASE='http://localhost:8081';
const S=process.env.SHOT_DIR||require('os').tmpdir();
const fail=[];
const check=(n,c,d)=>{console.log(`${c?'PASS':'FAIL'}  ${n}${d?' :: '+d:''}`);if(!c)fail.push(n);};

(async()=>{
 const b=await chromium.launch();const c=await b.newContext({viewport:{width:1500,height:1200}});const p=await c.newPage();
 p.on('pageerror',e=>console.log('[pageerror]',String(e).slice(0,300)));
 p.on('response',r=>{if(r.status()>=400)console.log('[http]',r.status(),r.url().slice(0,140));});
 await p.goto(`${BASE}/`,{waitUntil:'networkidle'});await p.fill('#login_name','glpi');await p.fill('input[type=password]','glpi');await p.click('button[type=submit]');await p.waitForLoadState('networkidle');

 // ---------------- triggers ----------------
 await p.goto(`${BASE}/plugins/glpisop/front/sop.form.php?id=2&forcetab=${encodeURIComponent('GlpiPlugin\\Glpisop\\SopTriggerTab$1')}`,{waitUntil:'networkidle'});
 await p.waitForTimeout(1200);
 check('trigger: no Change-criterion button', await p.getByRole('button',{name:/Change criterion/i}).count()===0);
 const crit = p.locator('select[name=criterion]').first();
 check('trigger: criterion starts on Category', await crit.inputValue()==='itilcategories_id');
 const condOpts = async()=> (await p.locator('select[name=condition] option').allTextContents()).join('/');
 check('trigger: category conditions', (await condOpts()).includes('is, or is under'), await condOpts());

 await crit.selectOption('name'); await p.waitForTimeout(900);
 check('trigger: criterion actually changed', await p.locator('select[name=criterion]').first().inputValue()==='name');
 check('trigger: text conditions after change', (await condOpts()).includes('regular expression'), await condOpts());
 check('trigger: text value control', await p.locator('form input[name=value][type=text]').count()>0);

 await crit.selectOption('urgency'); await p.waitForTimeout(900);
 check('trigger: priority conditions', (await condOpts())==='is/is not', await condOpts());
 check('trigger: priority value is a select', await p.locator('select[name=value]').count()>0);

 // save one, then remove it
 await p.locator('select[name=condition]').first().selectOption('is_not');
 await p.locator('select[name=value]').first().selectOption('5');
 await p.getByRole('button',{name:/^Add$/}).first().click();
 await p.waitForLoadState('networkidle'); await p.waitForTimeout(1200);
 const rows = await p.locator('table.table-sm td').allInnerTexts();
 check('trigger: saved row reads back', rows.some(t=>/Urgency is not: Very high/i.test(t)), JSON.stringify(rows.slice(0,4)));
 await p.screenshot({path:`${S}/fix-triggers.png`,fullPage:true});
 // clean up: delete the trigger we just made
 const del = p.locator('tr', {hasText:'Urgency is not'}).locator('button[name=purge]');
 if (await del.count()) { await del.first().click(); await p.waitForLoadState('networkidle'); await p.waitForTimeout(800); }
 check('trigger: cleanup left one trigger', (await p.locator('table.table-sm tbody tr').count())===1);

 console.log(fail.length?`\n${fail.length} FAILED: ${fail.join(', ')}`:'\nall passed');
 await b.close();
 process.exit(fail.length?1:0);
})();

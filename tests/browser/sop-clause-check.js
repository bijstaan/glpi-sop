// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// The two authoring rows that a criterion drives: the SOP's trigger tab, and a
// step's gate. Both used to need a reload button; this drives the in-place
// cascade and then actually saves a clause through each.
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

 // ---------------- step gate ----------------
 await p.goto(`${BASE}/plugins/glpisop/front/step.form.php?id=11`,{waitUntil:'networkidle'});
 await p.waitForTimeout(900);
 check('gate: no Use-this button', await p.getByRole('button',{name:/Use this/i}).count()===0);
 check('gate: no separate source select', await p.locator('select[name=source]').count()===0);
 const subj = p.locator('select[name=subject]');
 check('gate: one subject select', await subj.count()===1);
 const groups = await p.locator('select[name=subject] optgroup').evaluateAll(e=>e.map(x=>x.label));
 check('gate: grouped by source', groups.length===3, groups.join(' | '));
 const opOpts = async()=> (await p.locator('select[name=match_condition] option').allTextContents()).join('/');
 check('gate: step operators first', (await opOpts()).includes('was answered'), await opOpts());
 check('gate: no value box for "was answered"', await p.locator('#glpisop-gate-value, [id^=glpisop-gate-value]').first().innerText().then(t=>/Nothing to compare/.test(t)));

 await p.locator('select[name=match_condition]').first().selectOption('eq');
 await p.waitForTimeout(800);
 check('gate: value appears for "is"', await p.locator('[name=value]').count()>0);

 await subj.selectOption('field:urgency'); await p.waitForTimeout(1000);
 check('gate: field operators after switch', (await opOpts())==='is/is not', await opOpts());
 check('gate: field value is a select', await p.locator('select[name=value]').count()>0);
 await p.screenshot({path:`${S}/fix-gate.png`,fullPage:true});

 await subj.selectOption('approval:approved_by_group'); await p.waitForTimeout(1200);
 check('gate: approval group value is a dropdown', await p.locator('select[name=value]').count()>0);

 // save a field clause and remove it again
 await subj.selectOption('field:urgency'); await p.waitForTimeout(1000);
 await p.locator('select[name=match_condition]').first().selectOption('is');
 await p.locator('select[name=value]').first().selectOption('5');
 await p.getByRole('button',{name:/^Add$/}).first().click();
 await p.waitForLoadState('networkidle'); await p.waitForTimeout(1000);
 const clause = await p.locator('.card td').allInnerTexts();
 check('gate: clause saved', clause.some(t=>/Urgency is: Very high/i.test(t)), JSON.stringify(clause.slice(0,3)));
 await p.screenshot({path:`${S}/fix-gate-saved.png`,fullPage:true});
 const d2 = p.locator('tr',{hasText:'Urgency is'}).locator('button[name=purge]');
 if (await d2.count()) { await d2.first().click(); await p.waitForLoadState('networkidle'); await p.waitForTimeout(800); }
 check('gate: cleanup', (await p.locator('.card').first().innerText()).length>0);

 // A first step has no earlier answer, so that group must not be offered at
 // all — an empty optgroup is an invitation to write a clause that can never
 // hold. Step 21 is the first step of this SOP.
 await p.goto(`${BASE}/plugins/glpisop/front/step.form.php?id=21`,{waitUntil:'networkidle'});await p.waitForTimeout(900);
 const g = await p.locator('select[name=subject] optgroup').evaluateAll(e=>e.map(x=>x.label));
 check('first step: no "earlier answer" group', !g.some(l=>/earlier/i.test(l)), g.join(' | '));

 console.log(fail.length?`\n${fail.length} FAILED: ${fail.join(', ')}`:'\nall passed');
 await b.close();
 process.exit(fail.length?1:0);
})();

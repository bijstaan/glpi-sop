// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// The glpisop checklist, driven the way a technician drives it.
//
// This is the half the HTTP-level tests cannot reach: public/js/sop.js. The
// endpoint was already exercised with plain POSTs, so what is being verified
// here is specifically the browser behaviour — that a control saves itself on
// change, that a text field saves on a pause rather than a keystroke, that a
// branch opens and closes *in place* without a reload, and that the skip flow
// makes a reason mandatory before it will submit.
//
// Setup (publishing the SOP, adding a trigger, raising a matching ticket) runs
// over HTTP first, because none of it is what is under test.
const { chromium } = require('playwright');

const BASE = 'http://localhost:8081';
const SHOTS = process.env.SHOT_DIR || '.';

const fail = [];
function check(name, cond, detail) {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? ' :: ' + detail : ''}`);
  if (!cond) fail.push(name);
}

async function login(browser, user, pass) {
  const ctx = await browser.newContext({ viewport: { width: 1500, height: 1100 } });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.fill('#login_name', user);
  await page.fill('input[type=password]', pass);
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');
  if (await page.locator('#login_name').count()) {
    throw new Error(`login failed for ${user}`);
  }
  return page;
}

/**
 * Open the ticket on its main tab — where the timeline, and therefore the
 * checklist, lives. There is no SOP tab: the procedure is a timeline entry.
 *
 * forcetab is required rather than tidy. GLPI remembers the last tab a user was
 * on, so a bare ticket URL can land anywhere.
 */
async function openTicket(page, ticketId) {
  await page.goto(
    `${BASE}/front/ticket.form.php?id=${ticketId}&forcetab=${encodeURIComponent('Ticket$main')}`,
    { waitUntil: 'networkidle' }
  );
  await page.waitForSelector('.sop-container', { timeout: 15000 });
  await page.waitForTimeout(400);
}

const step = (page, id) => page.locator(`.sop-step[data-sop-step-id="${id}"]`);

/** The leading number of "N required steps outstanding", or 0 when there is none. */
const outstandingCount = (text) => {
  const m = /(\d+)\s+required/.exec(text || '');
  return m ? parseInt(m[1], 10) : 0;
};
const progress = (page) => page.locator('.sop-progress-text').first().innerText();
const required = (page) => page.locator('.sop-required-text').first().innerText();

/** Wait until the progress line changes, i.e. a save round-tripped. */
async function awaitProgress(page, was, label) {
  await page
    .waitForFunction(
      (prev) => document.querySelector('.sop-progress-text')?.innerText.trim() !== prev,
      was.trim(),
      { timeout: 8000 }
    )
    .catch(() => {});
  const now = await progress(page);
  check(label, now.trim() !== was.trim(), `${was.trim()} -> ${now.trim()}`);
  return now;
}

(async () => {
  const ticketId = process.env.TICKET_ID;
  if (!ticketId) {
    throw new Error('TICKET_ID is required (see sop-setup.sh)');
  }

  const browser = await chromium.launch();
  const page = await login(browser, 'glpi', 'glpi');

  // Surface anything the page logs; a silent JS exception is exactly the
  // failure this script exists to catch.
  const jsErrors = [];
  page.on('pageerror', (e) => jsErrors.push(e.message));
  page.on('console', (m) => {
    if (m.type() === 'error') jsErrors.push(m.text());
  });

  await openTicket(page, ticketId);

  check('the checklist renders inside the timeline', await page.locator('.sop-run').count() > 0);
  check(
    'it is a real timeline entry, not a bolted-on panel',
    await page.locator('.itil-timeline .timeline-item[data-itemtype="PluginGlpisopRun"] .sop-run').count() === 1
  );
  check('there is no separate SOP tab to ignore it in', await page.locator('a[href*="Glpisop%5CItemTab"]').count() === 0);

  // --- 0. the standing reminder in the fields panel -------------------
  const panel = page.locator('[data-sop-panel-run]').first();
  check('the fields panel carries a standing reminder', await panel.count() === 1);
  check(
    'it warns that the item cannot be resolved yet',
    /cannot be resolved/i.test(await panel.locator('[data-sop-panel-note]').innerText()),
    (await panel.locator('[data-sop-panel-note]').innerText()).trim()
  );
  check(
    'it is coloured as blocking',
    await panel.evaluate((el) => el.classList.contains('sop-panel-row--blocking'))
  );
  check(
    'and links straight to the checklist in the timeline',
    (await panel.getAttribute('href')) ===
      '#PluginGlpisopRun_' + (await page.locator('.sop-run').first().getAttribute('data-sop-run'))
  );

  // --- 0b. the resolution affordances are withheld --------------------
  const solveBtn = page.locator('.answer-action.action-solution');
  const statusOpt = (v) =>
    page.locator('select[name="status"]').evaluate(
      (s, val) => Array.from(s.options).find((o) => o.value === val)?.disabled ?? null,
      v
    );

  check(
    'the solution action is offered but disabled',
    (await solveBtn.count()) > 0 &&
      (await solveBtn.first().evaluate((el) => el.classList.contains('sop-blocked')))
  );
  check(
    'and says why on hover',
    /outstanding/i.test((await solveBtn.first().getAttribute('title')) || ''),
    (await solveBtn.first().getAttribute('title')) || '<none>'
  );
  check('the "Solved" status is disabled', (await statusOpt('5')) === true);
  check('the "Closed" status is disabled', (await statusOpt('6')) === true);
  check(
    'the status the ticket is already in stays selectable',
    await page
      .locator('select[name="status"]')
      .evaluate((s) => Array.from(s.options).every((o) => !o.selected || !o.disabled))
  );

  // --- 1. the branch opens and closes in place ------------------------
  // The branch is exercised first, before anything else is answered. Closing it
  // with steps 1 and 2 still outstanding keeps the run in progress — answer
  // those first and toggling the gate to "Yes" completes the procedure, which
  // locks it, and the rest of this script would be clicking at dead controls.
  const hidden = (id) => step(page, id).evaluate((el) => el.hidden);

  check('branch steps start hidden', (await hidden(4)) && (await hidden(5)));

  const baseOutstanding = outstandingCount(await required(page));

  await page.screenshot({ path: `${SHOTS}/sop-01-branch-closed.png`, fullPage: false });

  const url = page.url();
  await step(page, 3).locator('input[data-sop-step][value="No"]').check();
  await page.waitForFunction(
    () => !document.querySelector('.sop-step[data-sop-step-id="4"]').hidden,
    null,
    { timeout: 8000 }
  ).catch(() => {});

  check('answering "No" reveals the branch', !(await hidden(4)) && !(await hidden(5)));
  check('the page did not reload to do it', page.url() === url);

  // The count is asserted as a delta, not a number: what matters is that two
  // newly-asked required steps became outstanding, and an absolute figure would
  // just encode how far through the procedure this script happens to be.
  const openOutstanding = outstandingCount(await required(page));
  check(
    'the two newly-asked steps became outstanding',
    openOutstanding === baseOutstanding + 1,
    `${baseOutstanding} -> ${openOutstanding} (step 3 answered, 3a and 3b now asked)`
  );

  // Framed on the checklist rather than the whole page, so the screenshot
  // shows the branch and the fields-panel reminder together.
  await page.locator('.sop-run').first().scrollIntoViewIfNeeded();
  await page.waitForTimeout(300);
  await page.screenshot({ path: `${SHOTS}/sop-02-branch-open.png`, fullPage: false });

  await step(page, 3).locator('input[data-sop-step][value="Yes"]').check();
  await page.waitForFunction(
    () => document.querySelector('.sop-step[data-sop-step-id="4"]').hidden,
    null,
    { timeout: 8000 }
  ).catch(() => {});
  check('answering "Yes" closes it again', (await hidden(4)) && (await hidden(5)));
  const closedOutstanding = outstandingCount(await required(page));
  check(
    'a closed branch stops being outstanding',
    closedOutstanding === openOutstanding - 2,
    `${openOutstanding} -> ${closedOutstanding}`
  );

  // Back to No, so there is something left to skip.
  await step(page, 3).locator('input[data-sop-step][value="No"]').check();
  await page.waitForFunction(
    () => !document.querySelector('.sop-step[data-sop-step-id="5"]').hidden,
    null,
    { timeout: 8000 }
  ).catch(() => {});

  // --- 2. a checkbox saves itself on change ---------------------------
  let p = await progress(page);
  await step(page, 1).locator('input[data-sop-step]').check();
  p = await awaitProgress(page, p, 'ticking a checkbox saves without a reload');

  check(
    'the step is marked done in place',
    await step(page, 1).evaluate((el) => el.classList.contains('sop-step--done'))
  );
  check(
    'the byline names who answered and when',
    /glpi/i.test(await step(page, 1).locator('.sop-step-meta').innerText()),
    (await step(page, 1).locator('.sop-step-meta').innerText()).trim()
  );

  // --- 3. a text field saves on a pause, not per keystroke ------------
  const before = jsErrors.length;
  const saves = [];
  page.on('request', (r) => {
    if (r.url().includes('/glpisop/ajax/run.php')) saves.push(r.url());
  });

  const textField = step(page, 2).locator('input[data-sop-step]');
  await textField.click();
  await textField.type('jsmith', { delay: 40 });
  const savesAfterTyping = saves.length;
  check(
    'typing does not fire a request per keystroke',
    savesAfterTyping === 0,
    `${savesAfterTyping} request(s) during typing`
  );

  p = await awaitProgress(page, p, 'the text answer saves once typing pauses');
  check('one request for the whole word', saves.length === 1, `${saves.length} request(s)`);

  // --- 4. skipping demands a reason first -----------------------------
  await step(page, 5).hover();
  await step(page, 5).locator('[data-sop-action="skip"]').click();
  await page.waitForTimeout(500);

  check(
    'the first press asks for a reason instead of skipping',
    await step(page, 5).evaluate(
      (el) => !el.querySelector('.sop-step-error').hidden && !el.querySelector('.sop-step-note').hidden
    )
  );
  check(
    'and the step is not skipped yet',
    !(await step(page, 5).evaluate((el) => el.classList.contains('sop-step--skipped')))
  );

  await step(page, 5).locator('[data-sop-note]').fill('Identity team already engaged on INC-4412');
  await step(page, 5).locator('[data-sop-action="skip"]').click();
  await page.waitForFunction(
    () => document.querySelector('.sop-step[data-sop-step-id="5"]').classList.contains('sop-step--skipped'),
    null,
    { timeout: 8000 }
  ).catch(() => {});

  check(
    'with a reason, the step skips',
    await step(page, 5).evaluate((el) => el.classList.contains('sop-step--skipped'))
  );
  check(
    'a skipped step reports who skipped it',
    /skipped/i.test(await step(page, 5).locator('.sop-step-meta').innerText()),
    (await step(page, 5).locator('.sop-step-meta').innerText()).trim()
  );

  await page.screenshot({ path: `${SHOTS}/sop-03-skipped.png`, fullPage: false });

  // --- 5. finish it ---------------------------------------------------
  const textarea = step(page, 4).locator('textarea[data-sop-step]');
  await textarea.click();
  await textarea.type('NTLDR is missing', { delay: 20 });
  await page.waitForFunction(
    () => /all required/i.test(document.querySelector('.sop-required-text')?.innerText || ''),
    null,
    { timeout: 8000 }
  ).catch(() => {});

  check(
    'the run reports itself complete',
    /all required/i.test(await required(page)),
    (await required(page)).trim()
  );
  check(
    'the status badge follows',
    /complete/i.test(await page.locator('.sop-status-badge').first().innerText()),
    (await page.locator('.sop-status-badge').first().innerText()).trim()
  );
  check(
    'and so does the fields-panel reminder, without a reload',
    /complete/i.test(await panel.locator('[data-sop-panel-note]').innerText())
      && !(await panel.evaluate((el) => el.classList.contains('sop-panel-row--blocking'))),
    (await panel.locator('[data-sop-panel-note]').innerText()).trim()
  );

  // --- 5b. finishing hands the resolution controls back ---------------
  await page.waitForTimeout(400);
  check(
    'the solution action is released, without a reload',
    !(await solveBtn.first().evaluate((el) => el.classList.contains('sop-blocked')))
  );
  check('"Solved" is selectable again', (await statusOpt('5')) === false);

  // --- 5c. a completed run locks itself -------------------------------
  await openTicket(page, ticketId);
  check(
    'the completed run is locked',
    (await page.locator('[data-sop-action="unlock"]').count()) === 1
  );
  check(
    'its controls are read-only',
    await page
      .locator('.sop-step[data-sop-step-id="1"] input[data-sop-step]')
      .evaluate((el) => el.disabled)
  );
  check(
    'and it says so, pointing at the way back in',
    /unlock/i.test(await page.locator('.sop-run .alert').first().innerText()),
    (await page.locator('.sop-run .alert').first().innerText()).trim()
  );

  await page.screenshot({ path: `${SHOTS}/sop-09-locked.png`, fullPage: false });

  await page.locator('[data-sop-action="unlock"]').click();
  await page.waitForTimeout(2500);
  check(
    'unlocking makes it editable again',
    !(await page
      .locator('.sop-step[data-sop-step-id="1"] input[data-sop-step]')
      .evaluate((el) => el.disabled))
  );
  check(
    'and offers to lock it back',
    (await page.locator('[data-sop-action="lock"]').count()) === 1
  );

  // --- 5d. the completion followup is off by default ------------------
  check(
    'no duplicate transcription followup, now that the checklist is in the timeline',
    !/SOP completed/i.test(await page.evaluate(() => document.body.innerText))
  );

  await page.screenshot({ path: `${SHOTS}/sop-04-complete.png`, fullPage: false });

  // --- 6. the history panel -------------------------------------------
  await page.locator('[data-sop-action="log"]').first().click();
  await page.waitForSelector('.sop-log-list li', { timeout: 8000 }).catch(() => {});
  const entries = await page.locator('.sop-log-list li').count();
  check('the history panel loads the trail', entries > 4, `${entries} entries`);

  await page.screenshot({ path: `${SHOTS}/sop-05-history.png`, fullPage: false });

  // The run's own trail is the record now, so that is what is checked.

  // --- 8. the authoring side ------------------------------------------
  // Checked as well as captured: these are the pages an administrator lives
  // in, and a fatal in a tab renders as an empty panel rather than an error.
  const sopId = process.env.SOP_ID || '1';

  const authoringTab = async (tabClass, shot, expect) => {
    await page.goto(
      `${BASE}/plugins/glpisop/front/sop.form.php?id=${sopId}` +
        `&forcetab=${encodeURIComponent(`GlpiPlugin\\Glpisop\\${tabClass}$1`)}`,
      { waitUntil: 'networkidle' }
    );
    await page.waitForTimeout(900);
    const text = await page.evaluate(() => document.body.innerText);
    check(`${tabClass} renders`, expect.test(text));
    await page.screenshot({ path: `${SHOTS}/${shot}` });
  };

  await authoringTab('SopBuilderTab', 'sop-06-builder.png', /Did unlocking the account/);
  await authoringTab('SopTriggerTab', 'sop-07-triggers.png', /Add a trigger/);

  await page.goto(`${BASE}/plugins/glpisop/front/overview.php`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(700);
  check(
    'the adoption page reports the run',
    /Most-skipped steps/.test(await page.evaluate(() => document.body.innerText))
  );
  await page.screenshot({ path: `${SHOTS}/sop-08-adoption.png` });

  check('no JavaScript errors on the page', jsErrors.length === before, jsErrors.join(' | '));

  await browser.close();

  console.log(`\n${fail.length ? `FAILED: ${fail.join(', ')}` : 'all checks passed'}`);
  process.exit(fail.length ? 1 : 0);
})().catch((e) => {
  console.error('ERROR', e);
  process.exit(1);
});

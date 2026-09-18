// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// The procedure editor, driven the way an author drives it.
//
// This is the half no HTTP-level test can reach: the canvas is the model, so
// "did adding a step work" is a question about the DOM until Save is pressed.
// What is under test is specifically that — that adding, retyping, gating,
// duplicating, moving and deleting are edits to the page, that exactly one
// request goes out when Save is pressed, and that what comes back from a reload
// is what was on screen.
//
// It also asserts the two properties the old builder could not have: that a
// clause is offered only the steps above it, by the labels they have at that
// moment, and that pressing an arrow twice in opposite directions is an exact
// inverse across a heading boundary.
const { chromium } = require('playwright');

const BASE = 'http://localhost:8081';
const SOP  = 2;
const TAB  = encodeURIComponent('GlpiPlugin\\Glpisop\\SopBuilderTab$1');
const URL  = `${BASE}/plugins/glpisop/front/sop.form.php?id=${SOP}&forcetab=${TAB}`;

const fail = [];
const check = (n, c, d) => {
  console.log(`${c ? 'PASS' : 'FAIL'}  ${n}${d ? ' :: ' + d : ''}`);
  if (!c) fail.push(n);
};

const phpNoise = (html) =>
  /Warning<\/b>|Notice<\/b>|Fatal error|Deprecated<\/b>|Uncaught|Undefined (variable|index|array key)/.test(html);

/** Label and heading of every step card, in reading order. */
const shape = (page) => page.evaluate(() => {
  const out = [];
  document.querySelectorAll('[data-sop-blocks] > [data-sop-section]').forEach((section) => {
    const heading = section.hasAttribute('data-sop-unfiled')
      ? '(unfiled)'
      : section.querySelector('[data-sop-field="name"]').value;
    section.querySelectorAll(':scope [data-sop-steps] > [data-sop-step]').forEach((step) => {
      out.push(`${step.querySelector('[data-sop-number]').textContent}. `
             + `${step.querySelector('[data-sop-field="label"]').value} [${heading}]`);
    });
  });
  return out;
});

const cardFor = (page, label) =>
  page.locator('[data-sop-step]').filter({ has: page.locator(`[data-sop-field="label"][value="${label}"]`) });

/** Which heading each step sits under, as keys — the shape a drag changes. */
const filing = (page) => page.evaluate(() => Array.from(
  document.querySelectorAll('[data-sop-blocks] [data-sop-steps] > [data-sop-step]'),
).map((step) => step.closest('[data-sop-section]').getAttribute('data-sop-key') + '/'
              + step.querySelector('[data-sop-field="label"]').value));

const sectionOrder = (page) => page.evaluate(() => Array.from(
  document.querySelectorAll('[data-sop-blocks] > [data-sop-section]'),
).map((section) => section.getAttribute('data-sop-key')));

/**
 * One drag, driven the way a hand drives it.
 *
 * `hold` is the point of the signature: a pointer parked at the edge of the
 * window fires no further events, and that stillness is exactly the gesture
 * that means "keep scrolling". Holding it there for a while is the only way to
 * test that the canvas scrolls on its own clock rather than off dragover.
 */
async function dragTo(page, handle, x, y, hold = 3) {
  const box = await handle.boundingBox();
  await page.mouse.move(box.x + (box.width / 2), box.y + (box.height / 2));
  await page.mouse.down();
  await page.mouse.move(box.x + 30, box.y + 40, { steps: 5 });   // past the drag threshold
  await page.mouse.move(x, y, { steps: 12 });
  for (let i = 0; i < hold; i++) {
    await page.mouse.move(x, y);
    await page.waitForTimeout(40);
  }
  await page.mouse.up();
  await page.waitForTimeout(300);
}

async function openCanvas(page) {
  await page.goto(URL, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-sop-builder][data-sop-ready="1"]');
  await page.waitForTimeout(400);
}

/** Press Save and wait for the one request it makes. */
async function save(page) {
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/ajax/builder.php') && r.request().method() === 'POST'),
    page.click('[data-sop-action="save"]'),
  ]);
  await page.waitForTimeout(600);
  return response;
}

(async () => {
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ viewport: { width: 1500, height: 1300 } })).newPage();

  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e).slice(0, 300)));
  page.on('response', (r) => {
    if (r.status() >= 400) console.log('[http]', r.status(), r.url().slice(0, 140));
  });

  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.fill('#login_name', 'glpi');
  await page.fill('input[type=password]', 'glpi');
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');

  // ------------------------------------------------------------ first paint
  await openCanvas(page);

  check('the canvas renders clean', !phpNoise(await page.content()));
  check('the editor took it over', await page.locator('[data-sop-ready="1"]').count() === 1);
  check('there are step cards', await page.locator('[data-sop-blocks] [data-sop-step]').count() > 0);
  check('the templates are out of the way',
        await page.locator('[data-sop-templates] [data-sop-step]').count() > 0
        && await page.locator('[data-sop-templates]').isHidden());

  const before = await shape(page);
  console.log('START:'); before.forEach((r) => console.log('  ' + r));

  // Every stored clause must have been hydrated into a full list, not left as
  // the single option the server rendered.
  const hydrated = await page.evaluate(() => {
    const rows = document.querySelectorAll('[data-sop-blocks] [data-sop-condition]');
    return Array.from(rows).map((row) => ({
      subjects: row.querySelector('[data-sop-cond="subject"]').options.length,
      ops: row.querySelector('[data-sop-cond="op"]').options.length,
    }));
  });
  check('stored clauses were hydrated into real lists',
        hydrated.length === 0 || hydrated.every((r) => r.subjects > 1 && r.ops > 1),
        JSON.stringify(hydrated));

  // ------------------------------------------------------- add without reload
  const loadsBefore = page.url();
  await page.click('[data-sop-action="add-step"]');
  await page.waitForTimeout(200);

  check('a new card appeared', (await shape(page)).length === before.length + 1);
  check('nothing navigated', page.url() === loadsBefore);
  check('it opened itself',
        await page.locator('[data-sop-step].sop-step--open').count() === 1);
  check('the bar says there is something unsaved',
        await page.locator('[data-sop-dirty]').isVisible());

  const fresh = page.locator('[data-sop-step].sop-step--open');
  await fresh.locator('[data-sop-field="label"]').fill('Probe step');
  await fresh.locator('[data-sop-field="type"]').selectOption('choice');

  // Changing the type fetches the panel that belongs to it — one request, not
  // a page.
  await page.waitForSelector('[data-sop-step].sop-step--open [data-sop-branch-options]', { timeout: 8000 });
  check('the type brought its own options', true);

  await fresh.locator('[data-sop-branch-options]').fill('Alpha\nBeta');
  await fresh.locator('[data-sop-field="required"]').check();

  // ----------------------------------------------------- gate it, live
  await page.click('[data-sop-action="add-step"]');
  await page.waitForTimeout(200);
  const gated = page.locator('[data-sop-step].sop-step--open');
  await gated.locator('[data-sop-field="label"]').fill('Gated step');
  await gated.locator('[data-sop-action="add-condition"]').click();
  await page.waitForTimeout(200);

  const row = gated.locator('[data-sop-condition]').first();
  const subjects = await row.locator('[data-sop-cond="subject"] option').allTextContents();
  check('the new step is offered as a subject, by its label',
        subjects.some((s) => /Probe step/.test(s)), subjects.slice(-4).join(' | '));
  check('the gated step is not offered itself',
        !subjects.some((s) => /Gated step/.test(s)));

  const probeValue = await row.locator('[data-sop-cond="subject"] option')
    .evaluateAll((os) => (os.find((o) => /Probe step/.test(o.textContent)) || {}).value);
  await row.locator('[data-sop-cond="subject"]').selectOption(probeValue);
  await page.waitForTimeout(200);
  await row.locator('[data-sop-cond="op"]').selectOption('eq');
  await page.waitForTimeout(300);

  const answers = await row.locator('[data-sop-cond-value] option').allTextContents();
  check('the value box offers the options just typed into the other card',
        answers.includes('Alpha') && answers.includes('Beta'), answers.join('/'));

  await row.locator('[data-sop-cond-value] select').selectOption('Alpha');

  // Renaming the parent must rename it inside the clause, with no round trip.
  await cardFor(page, 'Probe step').locator('[data-sop-field="label"]').fill('Probe renamed');
  await page.waitForTimeout(200);
  const renamed = await row.locator('[data-sop-cond="subject"] option:checked').innerText();
  check('renaming a step renames it in the clause that points at it',
        /Probe renamed/.test(renamed), renamed);

  // --------------------------------------------------------------- duplicate
  await cardFor(page, 'Probe renamed').locator('[data-sop-action="duplicate-step"]').click();
  await page.waitForTimeout(600);
  const copies = await page.locator('[data-sop-blocks] [data-sop-field="label"]')
    .evaluateAll((es) => es.map((e) => e.value).filter((v) => /Probe renamed/.test(v)));
  check('duplicate made a copy', copies.length === 2, copies.join(' | '));
  const copyOptions = await page.locator('[data-sop-step]')
    .filter({ has: page.locator('[data-sop-field="label"][value*="copy"]') })
    .locator('[data-sop-branch-options]').inputValue();
  check('the copy kept the original\'s options', copyOptions.trim() === 'Alpha\nBeta', JSON.stringify(copyOptions));

  // ------------------------------------------------------------------- save
  const response = await save(page);
  const body = await response.json();
  check('the save was accepted', body.ok === true && (body.errors || []).length === 0,
        JSON.stringify(body.errors || []));
  check('the bar is clean again', !(await page.locator('[data-sop-dirty]').isVisible()));
  check('no page errors so far', errors.length === 0, errors.join(' | '));

  // ------------------------------------------------- it reads back after a reload
  await openCanvas(page);
  const after = await shape(page);
  console.log('AFTER SAVE:'); after.forEach((r) => console.log('  ' + r));

  check('the three new steps survived', after.length === before.length + 3);
  check('the branch numbered itself under its parent',
        after.some((r) => /^\d+[a-z]\. Gated step/.test(r)), after.join(' | '));

  const saved = await page.evaluate(() => {
    const card = Array.from(document.querySelectorAll('[data-sop-blocks] [data-sop-step]'))
      .find((s) => s.querySelector('[data-sop-field="label"]').value === 'Gated step');
    const row = card.querySelector('[data-sop-condition]');
    return {
      subject: row.querySelector('[data-sop-cond="subject"] option:checked').textContent.trim(),
      op: row.querySelector('[data-sop-cond="op"]').value,
      value: row.querySelector('[data-sop-cond-value] [name="sopcondvalue"]').value,
    };
  });
  check('the clause read back whole',
        /Probe renamed/.test(saved.subject) && saved.op === 'eq' && saved.value === 'Alpha',
        JSON.stringify(saved));

  // ------------------------------------- a clause the editor cannot rebuild
  //
  // Dragging a step below something that gates it is the one way an author can
  // strand a clause, and it is also the shape old procedures already contain:
  // reordering never used to re-check gates. The editor must keep such a clause
  // exactly as it found it rather than blanking or dropping it, or upgrading
  // would quietly change what a published procedure asks.
  {
    const parent = cardFor(page, 'Probe renamed').first();
    for (let i = 0; i < 40; i++) {
      const button = parent.locator('[data-sop-action="move-down"]');
      if (await button.isDisabled()) break;
      await button.click();
      await page.waitForTimeout(40);
      if (await page.locator('.sop-condition--frozen').count()) break;
    }

    const froze = await page.locator('.sop-condition--frozen').count();
    check('a stranded clause is frozen, not broken',
          froze > 0 && await page.locator('.sop-condition--broken').count() === 0,
          `frozen ${froze}`);

    if (froze > 0) {
      const row = page.locator('.sop-condition--frozen').first();
      check('its subject still names the step it was written against',
            /Probe renamed/.test(await row.locator('[data-sop-cond="subject"] option:checked').innerText()));
      check('its controls are read-only',
            await row.locator('[data-sop-cond="subject"]').isDisabled()
            && await row.locator('[data-sop-cond="op"]').isDisabled());

      const kept = await save(page);
      const keptBody = await kept.json();
      check('and the save goes through untouched',
            keptBody.ok === true && (keptBody.errors || []).length === 0,
            JSON.stringify(keptBody.errors || []));
    }

    // Put it back; the clause must come alive again with a real operator list.
    await openCanvas(page);
    for (let i = 0; i < 40; i++) {
      const button = cardFor(page, 'Probe renamed').first().locator('[data-sop-action="move-up"]');
      if (await button.isDisabled()) break;
      await button.click();
      await page.waitForTimeout(40);
      if (await page.locator('.sop-condition--frozen').count() === 0) break;
    }
    check('putting it back un-freezes the clause',
          await page.locator('.sop-condition--frozen').count() === 0);

    const thawed = await page.locator('[data-sop-step]')
      .filter({ has: page.locator('[data-sop-field="label"][value="Gated step"]') })
      .locator('[data-sop-cond="op"] option').count();
    check('with its operators rebuilt rather than left as the one stored option',
          thawed > 1, String(thawed));

    await save(page);
  }

  // --------------------------------------------- the arrows are a true inverse
  const start = await shape(page);
  const probe = cardFor(page, 'Probe renamed').first();

  await probe.locator('[data-sop-action="move-down"]').click();
  await page.waitForTimeout(150);
  const moved = await shape(page);
  check('down moved it', JSON.stringify(moved) !== JSON.stringify(start));

  await cardFor(page, 'Probe renamed').first().locator('[data-sop-action="move-up"]').click();
  await page.waitForTimeout(150);
  check('up put it back exactly, heading included',
        JSON.stringify(await shape(page)) === JSON.stringify(start));

  // ---------------------------------------------------------------- dragging
  //
  // The properties the marker-and-autoscroll rewrite exists for, none of which
  // a short procedure can show: that a block can be dropped somewhere that was
  // not on screen when it was picked up, that the pointer resting on a folded
  // heading opens it, and that a heading with nothing under it is still a
  // place a step can land.
  //
  // Nothing here is saved. The canvas is reloaded at the top of cleanup, which
  // discards the lot — what is under test is the gesture, not the write.
  await openCanvas(page);
  {
    await page.setViewportSize({ width: 1400, height: 620 });
    await page.waitForTimeout(200);

    // Long enough that its tail is well past the fold.
    for (let i = 0; i < 8; i++) {
      await page.click('[data-sop-action="add-step"]');
      await page.waitForTimeout(80);
      const card = page.locator('[data-sop-step].sop-step--open');
      await card.locator('[data-sop-field="label"]').fill(`Drag filler ${i + 1}`);
      await card.locator('[data-sop-action="toggle-step"]').click();
      await page.waitForTimeout(80);
    }
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(200);

    const tall = await page.evaluate(() => document.documentElement.scrollHeight > window.innerHeight);
    check('the procedure is longer than the window', tall);

    // --- down, past the fold, on the canvas's own scrolling ----------------
    const top = page.locator('[data-sop-blocks] [data-sop-step]').first();
    const label = await top.locator('[data-sop-field="label"]').inputValue();

    const handle = top.locator('[data-sop-handle]');
    const box = await handle.boundingBox();
    await page.mouse.move(box.x + (box.width / 2), box.y + (box.height / 2));
    await page.mouse.down();
    await page.mouse.move(box.x + 30, box.y + 60, { steps: 6 });
    await page.waitForTimeout(150);

    check('a marker shows where it would land',
          await page.locator('.sop-drop-marker').count() === 1);
    check('the card being dragged is still on the canvas, faded',
          await page.locator('[data-sop-step].sop-dragging').count() === 1);

    // Held until the page stops moving rather than for a fixed number of
    // frames: how far a second of holding travels depends on the frame rate the
    // browser is managing, and the assertion is about arriving, not about speed.
    const before = await page.evaluate(() => window.scrollY);
    await page.mouse.move(box.x + 30, 600, { steps: 8 });
    let after = before;
    for (let i = 0; i < 60; i++) {
      await page.mouse.move(box.x + 30, 600);
      await page.waitForTimeout(50);
      const now = await page.evaluate(() => window.scrollY);
      if (i > 4 && now === after) break;
      after = now;
    }
    check('holding it at the bottom edge scrolls the page', after > before, `${before} -> ${after}`);
    check('it scrolls all the way to the foot of the procedure',
          await page.evaluate(() => Math.ceil(window.scrollY)
            >= document.documentElement.scrollHeight - window.innerHeight - 1));

    await page.mouse.up();
    await page.waitForTimeout(300);

    const filed = await filing(page);
    check('and it landed at the far end, which was off screen when it was picked up',
          filed[filed.length - 1].endsWith('/' + label), filed[filed.length - 1]);
    check('the marker is gone', await page.locator('.sop-drop-marker').count() === 0);
    check('no page errors while dragging', errors.length === 0, errors.join(' | '));

    // --- up, the same way --------------------------------------------------
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await page.waitForTimeout(200);
    const bottom = await page.evaluate(() => window.scrollY);

    const last = page.locator('[data-sop-blocks] [data-sop-step]').last().locator('[data-sop-handle]');
    const lastBox = await last.boundingBox();
    await page.mouse.move(lastBox.x + (lastBox.width / 2), lastBox.y + (lastBox.height / 2));
    await page.mouse.down();
    await page.mouse.move(lastBox.x + 30, lastBox.y - 60, { steps: 6 });
    await page.mouse.move(lastBox.x + 30, 20, { steps: 8 });
    let risen = bottom;
    for (let i = 0; i < 60; i++) {
      await page.mouse.move(lastBox.x + 30, 20);
      await page.waitForTimeout(50);
      const now = await page.evaluate(() => window.scrollY);
      if (i > 4 && now === risen) break;
      risen = now;
    }
    await page.mouse.up();
    await page.waitForTimeout(300);
    check('holding it at the top edge scrolls back up', risen < bottom, `${bottom} -> ${risen}`);

    // --- a folded heading opens to take a step -----------------------------
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(200);

    const folded = await page.evaluate(() => {
      const section = Array.from(document.querySelectorAll('[data-sop-blocks] > [data-sop-section]'))
        .find((s) => !s.hasAttribute('data-sop-unfiled')
                  && s.querySelectorAll('[data-sop-steps] > [data-sop-step]').length > 0);
      section.querySelector('[data-sop-action="collapse-section"]').click();
      return section.getAttribute('data-sop-key');
    });
    await page.waitForTimeout(200);
    check('the heading folded',
          await page.locator(`[data-sop-key="${folded}"].sop-section--collapsed`).count() === 1);

    const orphan = page.locator('[data-sop-section][data-sop-unfiled] [data-sop-steps] > [data-sop-step]').first();
    const orphanLabel = await orphan.locator('[data-sop-field="label"]').inputValue();
    const head = await page.locator(`[data-sop-key="${folded}"] .sop-section-head`).boundingBox();
    await dragTo(page, orphan.locator('[data-sop-handle]'), head.x + 300, head.y + head.height - 2, 20);

    check('resting on it opens it',
          await page.locator(`[data-sop-key="${folded}"].sop-section--collapsed`).count() === 0);
    check('and the step is filed under it',
          (await filing(page)).includes(`${folded}/${orphanLabel}`));

    // --- a heading with nothing under it is still a target -----------------
    const empty = await page.evaluate(() => {
      const section = Array.from(document.querySelectorAll('[data-sop-blocks] > [data-sop-section]'))
        .find((s) => s.querySelectorAll('[data-sop-steps] > [data-sop-step]').length === 0);
      return section ? section.getAttribute('data-sop-key') : null;
    });
    if (empty) {
      const target = page.locator(`[data-sop-section][data-sop-key="${empty}"] [data-sop-steps]`);
      await target.scrollIntoViewIfNeeded();
      await page.waitForTimeout(200);
      const emptyBox = await target.boundingBox();
      const mover = page.locator('[data-sop-blocks] [data-sop-step]').first();
      const moverLabel = await mover.locator('[data-sop-field="label"]').inputValue();
      await dragTo(page, mover.locator('[data-sop-handle]'), emptyBox.x + 200, emptyBox.y);
      check('an empty heading takes a step',
            (await filing(page)).includes(`${empty}/${moverLabel}`));
    }

    // --- headings reorder, and never past the unfiled block ----------------
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(200);
    const named = (await sectionOrder(page)).filter((k) => k !== 's0');
    if (named.length > 1) {
      const first = await page.locator(`[data-sop-key="${named[0]}"]`).boundingBox();
      const second = page.locator(`[data-sop-key="${named[1]}"] .sop-section-head [data-sop-handle]`);
      await dragTo(page, second, first.x + 300, first.y + 10);
      const order = await sectionOrder(page);
      check('a heading can be dragged above another', order[0] === named[1], order.join(','));
      check('the unfiled block is still last', order[order.length - 1] === 's0', order.join(','));
    }

    check('still no page errors', errors.length === 0, errors.join(' | '));
    await page.setViewportSize({ width: 1500, height: 1300 });
    await page.waitForTimeout(200);
  }

  // ------------------------------------------------------------------ cleanup
  page.on('dialog', (d) => d.accept());

  // Deleting a step must take the clauses about it with it — the model's own
  // rule (Step::cleanDBonPurge) — or the save would carry rows the server drops.
  await openCanvas(page);
  const doomed = cardFor(page, 'Probe renamed').first();
  await doomed.locator('[data-sop-action="delete-step"]').click();
  await page.waitForTimeout(300);
  check('deleting a step removes the clauses about it',
        await page.locator('.sop-condition--broken').count() === 0);
  await save(page);

  await openCanvas(page);
  for (const label of ['Probe renamed (copy)', 'Probe renamed', 'Gated step']) {
    const card = cardFor(page, label).first();
    if (await card.count()) {
      await card.locator('[data-sop-action="delete-step"]').click();
      await page.waitForTimeout(250);
    }
  }

  const cleaned = await save(page);
  const cleanedBody = await cleaned.json();
  check('the cleanup save was accepted', cleanedBody.ok === true);

  await openCanvas(page);
  check('the procedure is back to where it started',
        JSON.stringify(await shape(page)) === JSON.stringify(before),
        JSON.stringify(await shape(page)));

  check('no page errors at all', errors.length === 0, errors.join(' | '));

  console.log(fail.length ? `\n${fail.length} FAILED: ${fail.join(', ')}` : '\nall passed');
  await browser.close();
  process.exit(fail.length ? 1 : 0);
})();

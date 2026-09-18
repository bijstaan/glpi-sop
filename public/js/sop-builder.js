// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
/**
 * GLPI SOP — the procedure editor.
 *
 * The canvas is the model. There is no JavaScript copy of the procedure kept
 * alongside the DOM and reconciled with it: a step *is* its card, its order is
 * where the card sits, and the heading it belongs to is the block it sits in.
 * Everything below reads the page, and Save writes the page to the server in
 * one request. That is the whole design, and it is what makes adding, moving,
 * retyping, duplicating and branching cost nothing — none of them is a request.
 *
 * Two things still come from the server, and both only on a deliberate change:
 * the options panel when a step's type changes, and the value control when a
 * clause is pointed at a category, a user or a group. Both are GLPI pickers —
 * an ajax dropdown with its own inline configuration — and building one here
 * would be a second implementation of something PHP already renders. See
 * ajax/builder.php.
 *
 * Everything that is a choice among things already on the page is built here:
 * which steps a clause may name, the operators that go with a subject, the
 * answers a choice step offers. Those are read from the canvas as it stands, so
 * renaming a step renames it inside the clause that points at it, with no round
 * trip and no chance of the two disagreeing.
 *
 * The contract with src/Builder.php is in that file's docblock: blocks carry
 * `data-sop-key`, scalar fields carry `data-sop-field`, type options carry
 * `data-sop-cfg`. The serialiser here knows nothing about step types.
 */
/* global glpi_toast_info, glpi_toast_error, setHasUnsavedChanges */
(function () {
    'use strict';

    /** The catalogue the server emits with the canvas. Set by start(). */
    var cat = null;

    /** Root element of the canvas currently being edited. */
    var root = null;

    /** Counter behind the keys given to blocks created in the browser. */
    var minted = 0;

    var dirty = false;

    /** Set once the window-level listeners are on. */
    var armed = false;

    /**
     * Start editing a canvas.
     *
     * Through a queue rather than a direct call, because the two ways GLPI can
     * put the tab on the page arrive in opposite orders. Fetched over ajax — the
     * usual case — this file is already in the page footer and the canvas calls
     * straight through. Rendered inline with the form, the canvas is in the
     * body and this file has not run yet, so the catalogue waits in the queue
     * until it does.
     */
    window.glpisopBuilderStart = function () {
        var pending = window.glpisopBuilderQueue || [];

        while (pending.length > 0) {
            attach(pending.shift());
        }
    };

    function attach(catalogue) {
        // The last canvas that has not been claimed. A tab switched away from
        // and back to leaves the old markup behind for a moment, and binding to
        // that one would put the editor on a page nobody is looking at.
        var canvases = Array.prototype.slice
            .call(document.querySelectorAll('[data-sop-builder]'))
            .filter(function (canvas) {
                return canvas.getAttribute('data-sop-ready') !== '1';
            });

        if (canvases.length === 0) {
            return;
        }

        cat  = catalogue;
        root = canvases[canvases.length - 1];
        root.setAttribute('data-sop-ready', '1');

        bind();
        hydrate();
        refresh();
        markClean();
    }

    /**
     * Take over the clauses the server drew.
     *
     * Each arrives holding exactly what is stored — one option in the subject
     * select, one in the operator select, one in the value box — because the
     * server has no reason to render a list the browser is about to rebuild
     * from the canvas anyway. This is that rebuild, and until it has run a
     * clause reading "3 is Yes" offers no way to change it to No.
     *
     * The exception is a clause whose value is a GLPI picker. That one the
     * server rendered in full, select2 and all, and it is left exactly as it
     * came: re-fetching every picker on every page load would be a request per
     * clause for markup already on screen.
     */
    function hydrate() {
        refresh();

        list('[data-sop-condition]').forEach(function (row) {
            var stored = readValue(row);

            // refresh() has already decided which rows this build can describe.
            // The ones it cannot are kept exactly as they arrived.
            if (frozen(row)) {
                row.querySelector('[data-sop-cond-value]')
                    .setAttribute('data-sop-cond-built', 'frozen');
                return;
            }

            rebuildOperators(row);

            if (shapeOf(row) === 'remote') {
                row.querySelector('[data-sop-cond-value]')
                    .setAttribute('data-sop-cond-built', 'remote');
                return;
            }

            rebuildValue(row, stored);
        });
    }

    // ------------------------------------------------------------- plumbing

    function editable() {
        return root.getAttribute('data-sop-editable') === '1';
    }

    function t(key) {
        return (cat.i18n && cat.i18n[key]) || key;
    }

    /**
     * The two gettext shapes the catalogue uses: `%s` and `%1$s`.
     *
     * One pass over both, not two passes: substituting first and then scanning
     * again would re-substitute a `%s` that arrived inside a value — and the
     * values here are step labels, which an author can type anything into.
     */
    function sprintf(template, values) {
        var i = 0;
        return String(template).replace(/%(?:(\d)\$)?([sd])/g, function (match, position) {
            return position ? values[Number(position) - 1] : values[i++];
        });
    }

    function el(html) {
        var holder = document.createElement('div');
        holder.innerHTML = html;
        return holder.firstElementChild;
    }

    /**
     * The container holding the procedure — never the hidden templates.
     *
     * Every unscoped query below goes through here rather than through the
     * canvas root, and that is load-bearing: the templates are a blank step, a
     * blank section and a blank clause sitting in the same subtree, and a query
     * for "every clause" that picked up the blank one would count a condition
     * nobody wrote and save a step nobody added.
     */
    function blocks() {
        return root.querySelector('[data-sop-blocks]');
    }

    function list(selector, within) {
        return Array.prototype.slice.call((within || blocks()).querySelectorAll(selector));
    }

    function one(selector) {
        return blocks().querySelector(selector);
    }

    function sections() {
        return list(':scope > [data-sop-section]', blocks());
    }

    function stepsIn(container) {
        return list(':scope > [data-sop-step]', container);
    }

    function containers() {
        return sections().map(function (section) {
            return section.querySelector('[data-sop-steps]');
        });
    }

    /** Every step card on the canvas, in reading order. */
    function allSteps() {
        var out = [];
        containers().forEach(function (container) {
            out = out.concat(stepsIn(container));
        });
        return out;
    }

    function key(block) {
        return block.getAttribute('data-sop-key');
    }

    function mint(prefix) {
        return prefix + (++minted);
    }

    function field(block, name) {
        return block.querySelector('[data-sop-field="' + name + '"]');
    }

    function value(block, name) {
        var control = field(block, name);
        if (!control) {
            return '';
        }
        return control.type === 'checkbox' ? (control.checked ? '1' : '0') : control.value;
    }

    function template(name) {
        var holder = root.querySelector('[data-sop-template="' + name + '"]');
        return holder ? el(holder.innerHTML) : null;
    }

    function markDirty() {
        dirty = true;
        var flag = root.querySelector('[data-sop-dirty]');
        if (flag) {
            flag.hidden = false;
        }
        if (typeof setHasUnsavedChanges === 'function') {
            setHasUnsavedChanges(true);
        }
    }

    function markClean() {
        dirty = false;
        var flag = root.querySelector('[data-sop-dirty]');
        if (flag) {
            flag.hidden = true;
        }
        if (typeof setHasUnsavedChanges === 'function') {
            setHasUnsavedChanges(false);
        }
    }

    function toast(message, failed) {
        try {
            if (failed && typeof glpi_toast_error === 'function') {
                glpi_toast_error(message);
                return;
            }
            if (!failed && typeof glpi_toast_info === 'function') {
                glpi_toast_info(message);
                return;
            }
        } catch (ignored) {
            // Falls through to the alert below. A toast helper that is missing
            // or has changed shape must not be the reason a save result goes
            // unreported.
        }
        window.alert(message);
    }

    // ---------------------------------------------------------------- events

    function bind() {
        root.addEventListener('click', onClick);
        root.addEventListener('input', onInput);
        root.addEventListener('change', onChange);

        root.addEventListener('dragstart', onDragStart);
        root.addEventListener('dragover', onDragOver);
        root.addEventListener('drop', onDrop);
        root.addEventListener('dragend', onDragEnd);

        // Once per page, not once per canvas: a tab switched away from and back
        // to would otherwise stack a warning per visit.
        if (armed) {
            return;
        }
        armed = true;

        window.addEventListener('beforeunload', function (event) {
            if (!dirty) {
                return undefined;
            }
            event.preventDefault();
            event.returnValue = t('leaveWarning');
            return t('leaveWarning');
        });
    }

    function onClick(event) {
        var trigger = event.target.closest('[data-sop-action]');

        if (!trigger) {
            // Clicking a card opens it, which is how the canvas stays readable:
            // one step open, the rest a line each. Deliberately not a toggle and
            // deliberately without preventDefault — clicking the label should
            // open the step *and* put the caret in the label, which is what an
            // author who clicked on the words was asking for.
            var head = event.target.closest('.sop-step-head');
            if (head && !event.target.closest('button, .sop-handle')) {
                openStep(head.closest('[data-sop-step]'));
            }
            return;
        }

        event.preventDefault();

        var action  = trigger.getAttribute('data-sop-action');
        var step    = trigger.closest('[data-sop-step]');
        var section = trigger.closest('[data-sop-section]');

        switch (action) {
            case 'save':             save(); return;
            case 'add-step':         addStep(containers().pop()); return;
            case 'add-step-here':    addStep(section.querySelector('[data-sop-steps]')); return;
            case 'add-section':      addSection(); return;
            case 'toggle-step':      toggleStep(step); return;
            case 'duplicate-step':   duplicateStep(step); return;
            case 'delete-step':      deleteStep(step); return;
            case 'delete-section':   deleteSection(section); return;
            case 'collapse-section': collapseSection(section, trigger); return;
            case 'add-condition':    addCondition(step); return;
            case 'delete-condition': deleteCondition(trigger.closest('[data-sop-condition]')); return;
            case 'move-up':          move(step || section, 'up'); return;
            case 'move-down':        move(step || section, 'down'); return;
        }
    }

    function onInput(event) {
        if (!event.target.closest('[data-sop-builder]')) {
            return;
        }

        markDirty();

        // A step's label is what every clause pointing at it reads as, and its
        // options are what such a clause offers as values. Both are rebuilt as
        // they are typed rather than on blur: a branch written against a
        // half-typed option is the mistake this is here to prevent.
        if (event.target.matches('[data-sop-field="label"], [data-sop-branch-options]')) {
            refresh();
        }
    }

    function onChange(event) {
        var target = event.target;

        if (!target.closest('[data-sop-builder]')) {
            return;
        }

        markDirty();

        if (target.matches('[data-sop-field="type"]')) {
            changeType(target.closest('[data-sop-step]'));
            return;
        }

        if (target.matches('[data-sop-cond="subject"]')) {
            var row = target.closest('[data-sop-condition]');
            rebuildOperators(row);
            rebuildValue(row);
            refresh();
            return;
        }

        if (target.matches('[data-sop-cond="op"]')) {
            rebuildValue(target.closest('[data-sop-condition]'));
            return;
        }

        refresh();
    }

    // ------------------------------------------------------------ the canvas

    /**
     * Redraw everything that is derived from where things are.
     *
     * Numbering, chips, counts, which arrows are dead, the list of subjects each
     * clause may name, and the answers each clause offers. Called after any
     * structural change and after any keystroke in a label, which it can afford
     * to be: everything here rewrites text, attributes and the options inside a
     * select, in place. The one thing it replaces — a clause's value box — it
     * leaves alone while the author has the keyboard in it.
     */
    function refresh() {
        var steps   = allSteps();
        var numbers = numbering(steps);
        var required = 0;

        steps.forEach(function (step) {
            var number = numbers[key(step)] || '';
            step.querySelector('[data-sop-number]').textContent = number;

            if (value(step, 'required') === '1') {
                required++;
            }

            renderChips(step);
            rebuildSubjects(step, steps, numbers);
        });

        sections().forEach(function (section) {
            var count = stepsIn(section.querySelector('[data-sop-steps]')).length;
            var badge = section.querySelector('[data-sop-count]');
            if (badge) {
                badge.textContent = count === 1
                    ? t('oneStep')
                    : (count === 0 ? t('noSteps') : sprintf(t('steps'), [count]));
            }
        });

        var summary = root.querySelector('[data-sop-summary]');
        if (summary) {
            var label = steps.length === 1
                ? t('oneStep')
                : (steps.length === 0 ? t('noSteps') : sprintf(t('steps'), [steps.length]));
            summary.textContent = sprintf(t('summary'), [label, required]);
        }

        refreshArrows(steps);
        refreshGates();
        refreshValues();
        refreshUnfiled();
    }

    /**
     * Whether the unfiled block announces itself.
     *
     * It does not need to while it is the only one — a procedure with no
     * headings should read as a list of steps, not as a list inside a box
     * labelled "Unfiled". The moment a heading exists it does, because
     * otherwise the steps below that heading read as belonging to it.
     */
    function refreshUnfiled() {
        var unfiled = one('[data-sop-section][data-sop-unfiled]');
        if (!unfiled) {
            return;
        }

        unfiled.classList.toggle(
            'sop-section--bare',
            sections().length === 1
        );
    }

    /**
     * Re-offer the answers a clause can be compared against.
     *
     * Only the boxes this file built from a list — a choice step's options, the
     * five priorities. Those lists are being typed on this page, and a clause
     * offering yesterday's options is how a branch ends up keyed on a word the
     * step no longer has.
     *
     * A picker fetched from the server is left alone: it is a select2 with its
     * own state, rebuilding it would cost a request per keystroke, and nothing
     * an author types into a step changes the list of categories.
     */
    function refreshValues() {
        list('[data-sop-condition]').forEach(function (row) {
            var holder = row.querySelector('[data-sop-cond-value]');
            var built  = holder.getAttribute('data-sop-cond-built');

            // An untagged box is one the server drew that hydrate() has not
            // adopted yet; a picker and a frozen clause are the server's for
            // good.
            if (built === null || built === 'remote' || built === 'frozen') {
                return;
            }

            // Rebuilding replaces the control, which would take the focus with
            // it. The author has just picked from this box — a `change` on it
            // is one of the things that brought us here — and pulling it out
            // from under them loses the keyboard on the row they are editing.
            if (holder.contains(document.activeElement)) {
                return;
            }

            // Two reasons to rebuild: the list it offers may have changed, or
            // the shape it should be has. The second is the one that bites — a
            // clause pointed at a choice step with no options yet is a text box,
            // and has to become a list the moment the options are typed.
            if (built === 'options' || built !== shapeOf(row)) {
                rebuildValue(row);
            }
        });
    }

    /**
     * The display numbers, exactly as Renderer::numbering() computes them.
     *
     * A step gated on an earlier one is lettered under it — 3a, 3b — so the
     * shape of a branching procedure is legible at a glance. Reimplemented here
     * rather than fetched because it has to be right while the author is still
     * dragging, and because the input is the canvas rather than the database.
     */
    function numbering(steps) {
        var numbers  = {};
        var children = {};
        var rootRank = 0;

        steps.forEach(function (step) {
            var parent = primaryParent(step);

            if (!parent || !Object.prototype.hasOwnProperty.call(numbers, parent)) {
                numbers[key(step)] = String(++rootRank);
                return;
            }

            children[parent] = (children[parent] || 0) + 1;
            numbers[key(step)] = numbers[parent] + letter(children[parent]);
        });

        return numbers;
    }

    /** The first clause about an earlier step: what this step hangs off. */
    function primaryParent(step) {
        var found = null;

        list('[data-sop-condition]', step).some(function (row) {
            var subject = row.querySelector('[data-sop-cond="subject"]').value || '';
            if (subject.indexOf('step:') === 0) {
                found = subject.slice(5);
                return true;
            }
            return false;
        });

        return found;
    }

    /** 1 => a, 26 => z, 27 => aa. */
    function letter(index) {
        var out = '';
        while (index > 0) {
            index--;
            out = String.fromCharCode(97 + (index % 26)) + out;
            index = Math.floor(index / 26);
        }
        return out;
    }

    /**
     * The line a closed card shows: what kind of step it is, and what is unusual
     * about it.
     *
     * Only the unusual. Every step has a type, so the type is always there;
     * "required", "gated" and "retired" appear only when true, because a row of
     * chips that is the same on every card is a row nobody reads.
     */
    function renderChips(step) {
        var chips = [['', cat.types[value(step, 'type')] || value(step, 'type')]];

        if (value(step, 'required') === '1') {
            chips.push(['sop-chip--required', t('required')]);
        }
        if (list('[data-sop-condition]', step).length > 0) {
            chips.push(['sop-chip--gated', t('gated')]);
        }
        if (value(step, 'active') !== '1') {
            chips.push(['sop-chip--off', t('inactive')]);
        }

        var holder = step.querySelector('[data-sop-chips]');
        holder.textContent = '';
        chips.forEach(function (chip) {
            var span = document.createElement('span');
            span.className = 'sop-chip ' + chip[0];
            span.textContent = chip[1];
            holder.appendChild(span);
        });
    }

    /** Grey out the arrows that would do nothing. */
    function refreshArrows(steps) {
        var named = sections().filter(function (section) {
            return !section.hasAttribute('data-sop-unfiled');
        });

        steps.forEach(function (step, index) {
            setDisabled(step, 'move-up', index === 0);
            setDisabled(step, 'move-down', index === steps.length - 1);
        });

        named.forEach(function (section, index) {
            setDisabled(section, 'move-up', index === 0);
            setDisabled(section, 'move-down', index === named.length - 1);
        });
    }

    /**
     * One block's own arrow, never a descendant's.
     *
     * A section contains step cards, and every one of those has a move-up of
     * its own; an unscoped query would find the first step's and disable that
     * instead of the heading's.
     */
    function setDisabled(block, action, off) {
        var button =
            block.querySelector(':scope > .card-body > .sop-step-head [data-sop-action="' + action + '"]')
            || block.querySelector(':scope > .sop-section-head [data-sop-action="' + action + '"]');

        if (button) {
            button.disabled = off;
        }
    }

    /** Show the "no conditions" line only where there are none. */
    function refreshGates() {
        list('[data-sop-gate]').forEach(function (gate) {
            var rows  = list('[data-sop-condition]', gate).length;
            var empty = gate.querySelector('[data-sop-gate-empty]');
            var mode  = gate.querySelector('[data-sop-field="mode"]');

            if (empty) {
                empty.hidden = rows > 0;
            }
            // The joiner is only a question once there is something to join. A
            // one-clause gate offering "all of them / any of them" is a decision
            // an author has to read and discard.
            if (mode) {
                mode.hidden = rows < 2;
            }
        });
    }

    // ------------------------------------------------------------- the gate

    /**
     * What the editor can do with a subject that is already stored.
     *
     * Three answers, and the middle one is what makes opening an old procedure
     * safe. "offered" is a subject on the menu. "broken" is one that cannot be
     * saved at all, because it names a step that is not in this procedure.
     * "frozen" is everything in between — a clause this build understands and
     * will write back untouched, but cannot offer as a fresh choice:
     *
     *  - a criterion no longer offered *for this SOP*, because its itemtypes
     *    changed or an administrator narrowed what the plugin applies to;
     *  - a clause pointing at a step further down the procedure, which
     *    authoring has never offered but reordering used to be able to leave
     *    behind.
     *
     * Both are read as satisfied by the runtime rather than mis-evaluated, so
     * keeping them costs nothing; deleting them would silently change what a
     * published procedure asks. The row is marked, left exactly as the server
     * drew it, and saved back the same way — see BuilderSave::clause(), which
     * applies the same wider rule on the way in.
     */
    function standingOf(subject, keys) {
        if (subject.indexOf('step:') === 0) {
            return keys.indexOf(subject.slice(5)) === -1 ? 'broken' : 'frozen';
        }

        return Object.prototype.hasOwnProperty.call(cat.operators, subject)
            ? 'offered'
            : 'frozen';
    }

    function frozen(row) {
        return row.classList.contains('sop-condition--frozen')
            || row.classList.contains('sop-condition--broken');
    }

    /**
     * Let a clause be read but not rewritten.
     *
     * A frozen row's controls are the ones the server drew, holding values the
     * catalogue cannot describe. Disabling them says so, and costs nothing:
     * the serialiser walks the DOM itself, and a disabled control still reports
     * its value. What stays live is the trash button — the author's way out.
     */
    function setRowEditable(row, live) {
        if (!editable()) {
            return;
        }

        ['[data-sop-cond="subject"]', '[data-sop-cond="op"]'].forEach(function (selector) {
            row.querySelector(selector).disabled = !live;
        });

        var control = row.querySelector('[data-sop-cond-value] [name="sopcondvalue"]');
        if (control) {
            control.disabled = !live;
        }
    }

    /**
     * Rewrite the subjects one step's clauses may name.
     *
     * The earlier steps come from the canvas — the cards above this one, with
     * the labels they have at this instant — and the rest of the vocabulary
     * from the catalogue. A clause may only point backwards: two steps each
     * waiting on the other are both permanently invisible and both permanently
     * required, and the way to make that unwritable is to not offer it.
     */
    function rebuildSubjects(step, steps, numbers) {
        var groups = [];
        var earlier = [];

        steps.some(function (candidate) {
            if (candidate === step) {
                return true;
            }
            earlier.push({
                key: 'step:' + key(candidate),
                label: (numbers[key(candidate)] || '?') + '. ' + (value(candidate, 'label') || t('newStep'))
            });
            return false;
        });

        if (earlier.length > 0) {
            groups.push({ group: cat.stepGroup, options: earlier });
        }

        var byGroup = {};
        cat.subjects.forEach(function (subject) {
            if (!byGroup[subject.group]) {
                byGroup[subject.group] = [];
                groups.push({ group: subject.group, options: byGroup[subject.group] });
            }
            byGroup[subject.group].push({ key: subject.key, label: subject.label });
        });

        var keys = steps.map(key);

        list('[data-sop-condition]', step).forEach(function (row) {
            var select = row.querySelector('[data-sop-cond="subject"]');
            var chosen = select.value;
            // Captured before the list is wiped: for a criterion the catalogue
            // no longer carries, the option the server drew holds the only
            // human name for it anywhere on the page.
            var stored = (select.options[select.selectedIndex] || {}).text || chosen;

            select.textContent = '';
            groups.forEach(function (group) {
                var optgroup = document.createElement('optgroup');
                optgroup.label = group.group;
                group.options.forEach(function (option) {
                    optgroup.appendChild(new Option(option.label, option.key, false, option.key === chosen));
                });
                select.appendChild(optgroup);
            });

            var was = frozen(row);

            // A row added a moment ago has no subject yet: it takes whichever
            // the list offers first, the same as a fresh row always has.
            if (chosen === '' || select.value === chosen) {
                row.classList.remove('sop-condition--frozen', 'sop-condition--broken');
                row.removeAttribute('title');
                setRowEditable(row, true);

                // It was frozen and is not any more — the author dragged the
                // step it names back above this one, or an administrator put
                // the itemtype back. Its operator list and value box are still
                // the single stored options the server drew, so they have to be
                // built now; nothing else revisits a row that is already
                // editable.
                if (was) {
                    rebuildOperators(row);
                    rebuildValue(row, readValue(row));
                }
                return;
            }

            // Not on the menu. It keeps an option of its own rather than
            // silently becoming a clause about whatever happens to be first —
            // which is the version an author would never notice.
            var standing = standingOf(chosen, keys);
            var orphan   = new Option(
                standing === 'broken' ? t('deletedStep') : stored,
                chosen,
                true,
                true
            );

            select.insertBefore(orphan, select.firstChild);
            select.value = chosen;

            row.classList.toggle('sop-condition--frozen', standing === 'frozen');
            row.classList.toggle('sop-condition--broken', standing === 'broken');
            row.title = standing === 'broken' ? t('brokenClause') : t('frozenClause');
            setRowEditable(row, false);
        });
    }

    /** The operators that go with the subject now chosen. */
    function rebuildOperators(row) {
        var subject = row.querySelector('[data-sop-cond="subject"]').value || '';
        var select  = row.querySelector('[data-sop-cond="op"]');
        var chosen  = select.value;

        var operators = subject.indexOf('step:') === 0
            ? cat.stepOps
            : (cat.operators[subject] || {});

        // Nothing to offer means a subject the catalogue cannot describe. The
        // option the server drew is then the only record of what this clause
        // compares with, and replacing it with an empty box would send an empty
        // operator back — which the server reads as a clause to drop.
        if (frozen(row) || Object.keys(operators).length === 0) {
            return;
        }

        select.textContent = '';
        Object.keys(operators).forEach(function (op) {
            select.appendChild(new Option(operators[op], op, false, op === chosen));
        });

        if (select.value !== chosen) {
            select.selectedIndex = 0;
        }
    }

    /**
     * What shape a clause's value box should be, given what it is testing.
     *
     * One definition, consulted both when the box is built and when the canvas
     * is asked whether the one on screen is still right. Two copies of this is
     * how a clause ends up as a text box beside a step that has since grown a
     * list of answers.
     */
    function shapeOf(row) {
        var subject = row.querySelector('[data-sop-cond="subject"]').value || '';
        var op      = row.querySelector('[data-sop-cond="op"]').value || '';

        if (subject.indexOf('step:') === 0) {
            // "was answered" is complete on its own. Showing an empty box beside
            // it and a note saying it is ignored is how an author ends up
            // believing they wrote a comparison they did not write.
            if (cat.stepOpsValue.indexOf(op) === -1) {
                return 'none';
            }
            return answersOf(subject.slice(5)) ? 'options' : 'text';
        }

        return (cat.values[subject] || { kind: 'text' }).kind;
    }

    /**
     * Build the box a clause is compared against.
     *
     * Four shapes: nothing at all, a fixed list, a free box, and GLPI's own
     * picker — which is the only one that costs a request, and only when a
     * clause is pointed at a category, a user or a group.
     */
    function rebuildValue(row, preset) {
        var subject = row.querySelector('[data-sop-cond="subject"]').value || '';
        var holder  = row.querySelector('[data-sop-cond-value]');

        // A frozen clause keeps the control the server drew, whatever it is:
        // rebuilding it would mean guessing a shape for a subject the catalogue
        // has no entry for, and the guess is a text box over a stored id.
        if (frozen(row)) {
            holder.setAttribute('data-sop-cond-built', 'frozen');
            return;
        }

        // `preset` is for the two callers that hold the value but not the
        // control it belongs in: the first paint, where the stored value is in
        // markup about to be replaced, and duplicate, where it was read off
        // another card. A GLPI picker arrives over ajax and cannot be written
        // to afterwards, so the value has to travel with the request.
        var current = preset === undefined ? readValue(row) : preset;
        var shape   = shapeOf(row);

        holder.setAttribute('data-sop-cond-built', shape);

        if (shape === 'remote') {
            fragment('condvalue', { subject: subject, value: current }, holder);
            return;
        }

        holder.innerHTML = '';

        if (shape === 'none') {
            holder.appendChild(plaintext(t('nothingToCompare')));
            return;
        }

        if (shape === 'text') {
            holder.appendChild(textBox(current));
            return;
        }

        var options = subject.indexOf('step:') === 0
            ? answersOf(subject.slice(5))
            : cat.values[subject].options;

        holder.appendChild(optionsBox(
            options,
            current || (subject.indexOf('step:') === 0 ? '' : (cat.values[subject].default || ''))
        ));
    }

    /**
     * The answers a step offers, or null where it offers no fixed set.
     *
     * Read from the referenced card rather than from the catalogue, because a
     * choice step's options are being typed on this page: the branch and the
     * list it branches on have to be the same list, or a procedure acquires a
     * branch keyed on a typo that nothing will ever open.
     */
    function answersOf(stepKey) {
        var step = one('[data-sop-step][data-sop-key="' + cssEscape(stepKey) + '"]');
        if (!step) {
            return null;
        }

        var type = value(step, 'type');
        if (cat.branchTypes.indexOf(type) === -1) {
            return null;
        }

        if (type === 'yesno') {
            return cat.yesno;
        }

        var textarea = step.querySelector('[data-sop-branch-options]');
        if (!textarea) {
            return null;
        }

        var answers = {};
        textarea.value.split(/\r?\n/).forEach(function (line) {
            var option = line.trim();
            if (option !== '') {
                answers[option] = option;
            }
        });

        return Object.keys(answers).length > 0 ? answers : null;
    }

    function optionsBox(options, chosen) {
        var select = document.createElement('select');
        select.className = 'form-select form-select-sm';
        select.name = 'sopcondvalue';
        // A control this file builds has to carry the same permission as the
        // one the server drew beside it, or a reader of a procedure they cannot
        // edit gets one live box among a page of dead ones.
        select.disabled = !editable();

        Object.keys(options).forEach(function (key) {
            select.appendChild(new Option(options[key], key, false, String(key) === String(chosen)));
        });

        // An option the author wrote before renaming the list it came from.
        // Keeping it selected and visible is what makes the mismatch fixable.
        if (chosen && select.value !== String(chosen)) {
            var orphan = new Option(String(chosen), String(chosen), true, true);
            select.insertBefore(orphan, select.firstChild);
            select.value = String(chosen);
        }

        return select;
    }

    function textBox(current) {
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-sm';
        input.name = 'sopcondvalue';
        input.value = current || '';
        input.disabled = !editable();
        return input;
    }

    function plaintext(message) {
        var span = document.createElement('span');
        span.className = 'form-control-plaintext text-muted';
        span.textContent = message;
        return span;
    }

    function readValue(row) {
        var holder  = row.querySelector('[data-sop-cond-value]');
        var control = holder.querySelector('[name="' + holder.getAttribute('data-sop-cond-value-name') + '"]');
        return control ? control.value : '';
    }

    // ---------------------------------------------------------- edit actions

    function addStep(container) {
        var card = template('step');
        if (!card || !container) {
            return;
        }

        card.setAttribute('data-sop-key', mint('nt'));
        container.appendChild(card);

        openStep(card);
        refresh();
        markDirty();

        field(card, 'label').focus();
    }

    function addSection() {
        var section = template('section');
        if (!section) {
            return;
        }

        section.setAttribute('data-sop-key', mint('ns'));

        // Before the unfiled block, never after it: unfiled steps sort after
        // every named heading in the model, and a canvas that showed them
        // anywhere else would be lying about the order the procedure runs in.
        blocks().insertBefore(section, one('[data-sop-section][data-sop-unfiled]'));

        refresh();
        markDirty();

        field(section, 'name').focus();
    }

    /**
     * Copy a step, options and gate included.
     *
     * Rebuilt from the original's values rather than cloned wholesale, and that
     * is not fussiness: half the option panels are select2 widgets, and a copied
     * one is a dead control with the right text in it. So the copy is a blank
     * card filled in from what the original serialises to — the same path the
     * server would take — and its options panel is re-rendered by the server
     * from the same values.
     */
    function duplicateStep(step) {
        var source = serialiseStep(step);
        var card   = template('step');

        if (!card) {
            return;
        }

        card.setAttribute('data-sop-key', mint('nt'));
        step.parentElement.insertBefore(card, step.nextElementSibling);

        field(card, 'label').value = sprintf(t('copySuffix'), [source.label]);
        field(card, 'help').value  = source.help;
        field(card, 'type').value  = source.type;
        field(card, 'mode').value  = source.mode;
        field(card, 'required').checked = source.required === '1';
        field(card, 'active').checked   = source.active === '1';


        source.conditions.forEach(function (clause) {
            var row = addCondition(card);
            if (!row) {
                return;
            }
            row.querySelector('[data-sop-cond="subject"]').value = clause.subject;
            rebuildOperators(row);
            row.querySelector('[data-sop-cond="op"]').value = clause.op;
            rebuildValue(row, clause.value);
        });

        loadOptions(card, source.type, source.cfg);

        openStep(card);
        refresh();
        markDirty();
    }

    /**
     * Remove a step, and every clause anywhere that was about it.
     *
     * The second half is the model's own rule — see Step::cleanDBonPurge(),
     * which deletes those clauses rather than blanking them. A clause pointing
     * at a step that no longer exists can never be satisfied, so under "all of
     * them" its own step would silently vanish from every run rather than
     * becoming unconditional. Doing it here as well means the canvas shows the
     * same procedure the save is about to write, instead of carrying rows that
     * the server would drop and report.
     */
    function deleteStep(step) {
        if (!window.confirm(t('confirmStep'))) {
            return;
        }

        var subject = 'step:' + key(step);

        step.remove();

        list('[data-sop-condition]').forEach(function (row) {
            if (row.querySelector('[data-sop-cond="subject"]').value === subject) {
                row.remove();
            }
        });

        refresh();
        markDirty();
    }

    /**
     * Remove a heading; its steps move to Unfiled.
     *
     * The same rule the model keeps — see Section::cleanDBonPurge(). An author
     * tidying headings should not lose the procedure, and a step that vanished
     * with its heading would be a step nobody could tell had gone.
     */
    function deleteSection(section) {
        if (!window.confirm(t('confirmSection'))) {
            return;
        }

        var unfiled = one('[data-sop-section][data-sop-unfiled] [data-sop-steps]');
        stepsIn(section.querySelector('[data-sop-steps]')).forEach(function (step) {
            unfiled.appendChild(step);
        });

        section.remove();
        refresh();
        markDirty();
    }

    function addCondition(step) {
        var row = template('condition');
        if (!row) {
            return null;
        }

        step.querySelector('[data-sop-conditions]').appendChild(row);

        // Twice, and the second one is not redundant: the first builds the
        // subject list and the row takes the first entry, which may be an
        // earlier step — and a step gated on an earlier step is numbered under
        // it. The numbering is computed before that choice exists.
        refresh();
        rebuildOperators(row);
        rebuildValue(row);
        refresh();
        markDirty();

        return row;
    }

    function deleteCondition(row) {
        var step = row.closest('[data-sop-step]');
        row.remove();
        refresh();
        markDirty();

        return step;
    }

    /**
     * Fold a heading's steps away, and turn its chevron over.
     *
     * The icon is swapped by class rather than by a CSS `content` override:
     * these are Tabler's webfont glyphs, and naming a codepoint here would
     * paint whatever character had moved into it by the next icon release.
     */
    function collapseSection(section, trigger) {
        var closed = section.classList.toggle('sop-section--collapsed');
        trigger.querySelector('i').className = 'ti ' + (closed ? 'ti-chevron-down' : 'ti-chevron-up');
        trigger.title = closed ? t('open') : t('close');
        trigger.setAttribute('aria-label', trigger.title);
    }

    function toggleStep(step) {
        if (step.classList.contains('sop-step--open')) {
            closeStep(step);
        } else {
            openStep(step);
        }
    }

    /**
     * Open one card and close the others.
     *
     * One at a time, which is the rule that makes a thirty-step procedure
     * editable at all: the thing being edited is open, everything else is a
     * line, and the shape of the whole procedure stays on screen while one step
     * of it is being written.
     */
    function openStep(step) {
        allSteps().forEach(function (other) {
            if (other !== step) {
                closeStep(other);
            }
        });

        step.classList.add('sop-step--open');
        step.querySelector('[data-sop-body]').hidden = false;
        setIcon(step, 'ti-chevron-up', t('close'));
    }

    function closeStep(step) {
        step.classList.remove('sop-step--open');
        step.querySelector('[data-sop-body]').hidden = true;
        setIcon(step, 'ti-chevron-down', t('open'));
    }

    function setIcon(step, icon, title) {
        var button = step.querySelector('[data-sop-action="toggle-step"]');
        if (!button) {
            return;
        }
        button.title = title;
        button.setAttribute('aria-label', title);
        button.querySelector('i').className = 'ti ' + icon;
    }

    /**
     * Retype a step: fetch the panel that belongs to the new type.
     *
     * The old type's options are discarded rather than carried over, which is
     * the rule the server keeps too — only the keys belonging to the submitted
     * type are stored, so switching from "number" to "choice" does not leave a
     * stale min/max behind to confuse whoever reads the row next.
     */
    function changeType(step) {
        var type = value(step, 'type');
        loadOptions(step, type, null);
        refresh();

        // A clause elsewhere that offers this step's answers is now offering
        // the wrong ones — or should stop offering a list at all.
        list('[data-sop-condition]').forEach(function (row) {
            if ((row.querySelector('[data-sop-cond="subject"]').value || '') === 'step:' + key(step)) {
                rebuildValue(row);
            }
        });
    }

    function loadOptions(step, type, cfg) {
        var holder = step.querySelector('[data-sop-options]');

        if (!cat.typeOptions[type]) {
            holder.innerHTML = '';
            holder.classList.add('d-none');
            return;
        }

        holder.classList.remove('d-none');

        var params = { type: type };
        if (cfg) {
            Object.keys(cfg).forEach(function (name) {
                params['cfg[' + name + ']'] = cfg[name];
            });
        }

        fragment('options', params, holder);
    }

    // ------------------------------------------------------------- reorder

    function move(block, direction) {
        if (block.hasAttribute('data-sop-step')) {
            moveStep(block, direction);
        } else {
            moveSection(block, direction);
        }

        refresh();
        markDirty();
        block.scrollIntoView({ block: 'nearest' });
    }

    /**
     * One press, one place, across the whole procedure.
     *
     * The move is over the flat list an author is looking at rather than within
     * a heading, so a step at the bottom of one section moves into the top of
     * the next — and pressing the other arrow puts it back exactly where it was.
     * That inverse is the property worth having: an author who overshot by one
     * needs a way back that does not overshoot the other way.
     */
    function moveStep(step, direction) {
        var all     = containers();
        var mine    = step.parentElement;
        var index   = all.indexOf(mine);
        var siblings = stepsIn(mine);
        var position = siblings.indexOf(step);

        if (direction === 'up') {
            if (position > 0) {
                mine.insertBefore(step, siblings[position - 1]);
            } else if (index > 0) {
                all[index - 1].appendChild(step);
            }
            return;
        }

        if (position < siblings.length - 1) {
            mine.insertBefore(siblings[position + 1], step);
        } else if (index < all.length - 1) {
            all[index + 1].insertBefore(step, stepsIn(all[index + 1])[0] || null);
        }
    }

    /** Headings swap with each other; the unfiled block is not one of them. */
    function moveSection(section, direction) {
        var named = sections().filter(function (candidate) {
            return !candidate.hasAttribute('data-sop-unfiled');
        });

        var position = named.indexOf(section);
        var target   = direction === 'up' ? position - 1 : position + 1;

        if (target < 0 || target >= named.length) {
            return;
        }

        if (direction === 'up') {
            named[target].parentElement.insertBefore(section, named[target]);
        } else {
            named[target].parentElement.insertBefore(named[target], section);
        }
    }

    // ---------------------------------------------------------------- drag

    var dragged = null;

    function onDragStart(event) {
        var handle = event.target.closest('[data-sop-handle]');
        if (!handle || !editable()) {
            return;
        }

        dragged = handle.closest('[data-sop-step]') || handle.closest('[data-sop-section]');
        if (!dragged) {
            return;
        }

        dragged.classList.add('sop-dragging');
        event.dataTransfer.effectAllowed = 'move';
        // Firefox starts no drag at all without a payload, and the payload is
        // never read: the element being dragged is held above.
        event.dataTransfer.setData('text/plain', key(dragged));
    }

    function onDragOver(event) {
        if (!dragged) {
            return;
        }

        var over = dragged.hasAttribute('data-sop-step')
            ? dropTargetForStep(event)
            : event.target.closest('[data-sop-blocks] > [data-sop-section]');

        if (!over) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    }

    /**
     * Where a dragged step would land.
     *
     * Either beside another step or inside an empty heading, and the second case
     * is the one worth spelling out: a heading with nothing under it has no card
     * to aim at, and without this a new section could only ever be filled by
     * creating steps in it.
     */
    function dropTargetForStep(event) {
        return event.target.closest('[data-sop-step]')
            || event.target.closest('[data-sop-steps]');
    }

    function onDrop(event) {
        if (!dragged) {
            return;
        }

        event.preventDefault();

        if (dragged.hasAttribute('data-sop-step')) {
            dropStep(event);
        } else {
            dropSection(event);
        }

        refresh();
        markDirty();
    }

    function dropStep(event) {
        var onto = event.target.closest('[data-sop-step]');

        if (onto && onto !== dragged) {
            var after = onto.getBoundingClientRect().top + (onto.offsetHeight / 2) < event.clientY;
            onto.parentElement.insertBefore(dragged, after ? onto.nextElementSibling : onto);
            return;
        }

        var container = event.target.closest('[data-sop-steps]');
        if (container && !onto) {
            container.appendChild(dragged);
        }
    }

    function dropSection(event) {
        var onto = event.target.closest('[data-sop-blocks] > [data-sop-section]');

        // Never past the unfiled block: it is the tail of the procedure by
        // definition, and a heading dropped after it would read as running
        // later than steps that run last.
        if (!onto || onto === dragged || onto.hasAttribute('data-sop-unfiled')) {
            return;
        }

        var after = onto.getBoundingClientRect().top + (onto.offsetHeight / 2) < event.clientY;
        onto.parentElement.insertBefore(dragged, after ? onto.nextElementSibling : onto);
    }

    function onDragEnd() {
        if (dragged) {
            dragged.classList.remove('sop-dragging');
        }
        dragged = null;
    }

    // ------------------------------------------------------------- the save

    /**
     * The canvas, as the server reads it.
     *
     * Order is position and nothing carries a rank, which is why this is short:
     * the sections in the order they appear, the steps in the order they appear
     * with the key of the block they sit in, and each step's clauses in the
     * order they were written.
     */
    function serialise() {
        var body = new URLSearchParams();

        body.set('action', 'save');
        body.set('sops_id', root.getAttribute('data-sop-id'));

        var index = 0;
        sections().forEach(function (section) {
            if (section.hasAttribute('data-sop-unfiled')) {
                return;
            }
            var prefix = 'sections[' + index++ + ']';
            body.set(prefix + '[key]', key(section));
            body.set(prefix + '[name]', value(section, 'name'));
            body.set(prefix + '[content]', value(section, 'content'));
        });

        allSteps().forEach(function (step, position) {
            var prefix = 'steps[' + position + ']';
            var data   = serialiseStep(step);

            body.set(prefix + '[key]', key(step));
            body.set(prefix + '[section]', key(step.closest('[data-sop-section]')));
            body.set(prefix + '[label]', data.label);
            body.set(prefix + '[help]', data.help);
            body.set(prefix + '[type]', data.type);
            body.set(prefix + '[mode]', data.mode);
            body.set(prefix + '[required]', data.required);
            body.set(prefix + '[active]', data.active);

            Object.keys(data.cfg).forEach(function (name) {
                var stored = data.cfg[name];
                if (Array.isArray(stored)) {
                    stored.forEach(function (one) {
                        body.append(prefix + '[cfg][' + name + '][]', one);
                    });
                    return;
                }
                body.set(prefix + '[cfg][' + name + ']', stored);
            });

            data.conditions.forEach(function (clause, rank) {
                var row = prefix + '[conditions][' + rank + ']';
                body.set(row + '[subject]', clause.subject);
                body.set(row + '[op]', clause.op);
                body.set(row + '[value]', clause.value);
            });
        });

        return body;
    }

    /**
     * One step, read off its card.
     *
     * Type options are read through `data-sop-cfg` without this function
     * knowing what any of them are called — see src/StepOptions.php. That is
     * what keeps a new step type out of this file entirely.
     */
    function serialiseStep(step) {
        var cfg = {};

        list('[data-sop-cfg]', step).forEach(function (wrapper) {
            var name = wrapper.getAttribute('data-sop-cfg-name');
            var into = wrapper.getAttribute('data-sop-cfg');

            if (wrapper.getAttribute('data-sop-cfg-multi') === '1') {
                cfg[into] = list('[name="' + cssEscape(name) + '"]:checked', wrapper)
                    .map(function (box) { return box.value; });
                return;
            }

            var control = wrapper.querySelector('[name="' + cssEscape(name) + '"]');
            if (control) {
                cfg[into] = control.type === 'checkbox' ? (control.checked ? '1' : '0') : control.value;
            }
        });

        var conditions = list('[data-sop-condition]', step).map(function (row) {
            return {
                subject: row.querySelector('[data-sop-cond="subject"]').value || '',
                op: row.querySelector('[data-sop-cond="op"]').value || '',
                value: readValue(row)
            };
        });

        return {
            label: value(step, 'label'),
            help: value(step, 'help'),
            type: value(step, 'type'),
            mode: value(step, 'mode'),
            required: value(step, 'required'),
            active: value(step, 'active'),
            cfg: cfg,
            conditions: conditions
        };
    }

    function save() {
        var unlabelled = allSteps().filter(function (step) {
            return value(step, 'label').trim() === '';
        });

        if (unlabelled.length > 0) {
            toast(t('needsLabel'), true);
            openStep(unlabelled[0]);
            field(unlabelled[0], 'label').focus();
            return;
        }

        // A clause naming a step that is not in this procedure at all. Only
        // corruption or a hand-edited row produces one — deleting a step takes
        // its dependants with it — and there is nothing to write, so the server
        // would drop it and the canvas would be reloaded out from under the
        // author. Better to stop here, on the step that needs fixing, with
        // everything else still on screen.
        //
        // Note that a merely *unofferable* clause is not this: those are frozen
        // and written back untouched, so opening an old procedure and saving it
        // changes nothing nobody asked for.
        var broken = list('.sop-condition--broken')[0];
        if (broken) {
            var owner = broken.closest('[data-sop-step]');
            toast(sprintf(t('brokenClause'), [value(owner, 'label')]), true);
            openStep(owner);
            owner.scrollIntoView({ block: 'center' });
            return;
        }

        var button = root.querySelector('[data-sop-action="save"]');
        button.disabled = true;

        post(serialise())
            .then(function (result) {
                if (!result || !result.ok) {
                    throw new Error('rejected');
                }

                if (result.errors && result.errors.length > 0) {
                    // The server refused part of what was sent, so the canvas
                    // and the database no longer agree about what the procedure
                    // is. Redrawn from the database rather than patched into
                    // looking right — and said in a dialog rather than a toast,
                    // because the reload that follows would take a toast with
                    // it before it had been read.
                    window.alert(result.errors.join('\n'));
                    markClean();
                    window.location.reload();
                    return;
                }

                adoptKeys(result.keys || {});
                markClean();
                toast(t('saved'), false);
            })
            .catch(function () {
                toast(t('saveFailed'), true);
            })
            .then(function () {
                button.disabled = false;
            });
    }

    /**
     * Take the ids the save handed back.
     *
     * A step the author added is `nt7` here and has no id anywhere; once it is
     * written it is `t42`. Without adopting that, the next save would send
     * `nt7` again and the server would write a second copy of it.
     */
    function adoptKeys(keys) {
        Object.keys(keys).forEach(function (minted) {
            var block = one('[data-sop-key="' + cssEscape(minted) + '"]');

            if (!block) {
                return;
            }

            var settled = (block.hasAttribute('data-sop-step') ? 't' : 's') + keys[minted];
            block.setAttribute('data-sop-key', settled);

            list('[data-sop-cond="subject"]').forEach(function (select) {
                if (select.value === 'step:' + minted) {
                    var option = select.querySelector('option[value="step:' + cssEscape(minted) + '"]');
                    if (option) {
                        option.value = 'step:' + settled;
                    }
                    select.value = 'step:' + settled;
                }
            });
        });

        refresh();
    }

    // -------------------------------------------------------------- requests

    /**
     * POST to the endpoint.
     *
     * The CSRF token travels in the header rather than the body: GLPI 11
     * consumes a body token per request and preserves a header one, and this
     * editor makes several. `X-Requested-With` is not decoration — GLPI's
     * CheckCsrfListener only looks at the header when the request says it is an
     * XHR, and fetch() sets nothing of the sort on its own.
     */
    function post(body) {
        return fetch(root.getAttribute('data-sop-endpoint'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': root.getAttribute('data-sop-csrf'),
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body
        }).then(function (response) {
            return response.json();
        });
    }

    /**
     * Replace a container with a fragment rendered by the server.
     *
     * Through jQuery's `.load()` rather than fetch, and that is load-bearing:
     * the fragments are GLPI pickers, and a GLPI picker is markup plus an
     * inline script that turns it into a select2. fetch() would insert the
     * markup and leave the script inert — a dropdown that looks right and does
     * nothing.
     */
    function fragment(action, params, holder) {
        var payload = Object.assign(
            { action: action, sops_id: root.getAttribute('data-sop-id') },
            params
        );

        if (window.jQuery) {
            window.jQuery(holder).load(root.getAttribute('data-sop-endpoint'), payload);
            return;
        }

        holder.innerHTML = '';
    }

    /**
     * CSS.escape, for the browsers and the attribute selectors that need it.
     *
     * The values escaped here are keys and control names this file minted or
     * the server emitted, so this is about `[` and `]` in a name like
     * `sopcfg_itemtypes`, not about untrusted input.
     */
    function cssEscape(text) {
        if (window.CSS && window.CSS.escape) {
            return window.CSS.escape(text);
        }
        return String(text).replace(/["\\\]\[]/g, '\\$&');
    }

    // The tab may already be on the page — GLPI renders plugin scripts in the
    // footer, and a canvas rendered inline with the form got there first.
    window.glpisopBuilderStart();
})();

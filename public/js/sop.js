// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
/**
 * GLPI SOP — the interactive half of the checklist.
 *
 * Everything is delegated from the container rather than bound to elements,
 * for one concrete reason: GLPI renders its user and asset pickers as select2
 * widgets whose ids carry a random suffix, and the asset picker replaces its
 * second dropdown over ajax whenever the itemtype changes. Nothing bound
 * directly survives that. Delegation over stable `data-` attributes does.
 *
 * The server is the only authority on what a run looks like. Every write
 * returns the recomputed state — which steps are visible, what is answered,
 * how far along the run is — and this file's job is to apply it. There is no
 * local model to drift out of sync, and a request that fails leaves the page
 * showing what the server last confirmed rather than an optimistic guess.
 */
(function () {
    'use strict';

    var SAVE_DEBOUNCE_MS = 700;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        // The tab content is injected after page load, so containers appear
        // late and more than once. Binding on document survives all of that,
        // and the guard keeps a second tab render from double-binding.
        if (window.__glpisopBound) {
            return;
        }
        window.__glpisopBound = true;

        document.addEventListener('change', onChange, true);
        document.addEventListener('input', onInput, true);
        document.addEventListener('click', onClick, true);

        // GLPI's dropdowns are select2, which suppresses the native change
        // event on the underlying <select> and re-emits it through jQuery.
        // Without this bridge the user and asset pickers save nothing.
        if (window.jQuery) {
            window.jQuery(document).on('change', 'select', function (event) {
                onChange({ target: event.target, __fromJquery: true });
            });
        }

        // The ticket form and its footer arrive over ajax after this file runs,
        // and the timeline re-renders on its own as things are added. Watching
        // the document is what keeps the resolution block applied to controls
        // that did not exist when the page first settled.
        applyResolutionBlock();
        if (window.MutationObserver) {
            var pending = null;
            new MutationObserver(function () {
                window.clearTimeout(pending);
                pending = window.setTimeout(applyResolutionBlock, 150);
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    // ----------------------------------------------------------- plumbing

    function container(el) {
        return el && el.closest ? el.closest('.sop-container') : null;
    }

    function runOf(el) {
        return el && el.closest ? el.closest('.sop-run') : null;
    }

    function stepOf(el) {
        return el && el.closest ? el.closest('.sop-step') : null;
    }

    function editable(runEl) {
        return runEl && runEl.getAttribute('data-sop-editable') === '1';
    }

    /**
     * POST to the endpoint.
     *
     * The CSRF token travels in the header rather than the body on purpose:
     * GLPI 11 consumes a body token but preserves a header one, and a
     * checklist writes on every keystroke pause. A body token would exhaust
     * the session's token pool within one long procedure.
     *
     * `X-Requested-With` is not decoration. GLPI's CheckCsrfListener only
     * looks at the header when `Request::isXmlHttpRequest()` is true, and
     * fetch() — unlike jQuery — sets nothing of the sort on its own. Without
     * this line the kernel looks for a body token, does not find one, and
     * answers every save with the "Access denied" page.
     */
    function post(containerEl, payload, options) {
        var body;
        var headers = {
            'X-Requested-With': 'XMLHttpRequest',
            'X-Glpi-Csrf-Token': containerEl.getAttribute('data-sop-csrf')
        };

        if (payload instanceof FormData) {
            body = payload;
        } else {
            body = new URLSearchParams();
            Object.keys(payload).forEach(function (key) {
                var value = payload[key];
                if (Array.isArray(value)) {
                    value.forEach(function (one) { body.append(key + '[]', one); });
                } else if (value !== null && value !== undefined) {
                    body.append(key, value);
                }
            });
        }

        return fetch(containerEl.getAttribute('data-sop-endpoint'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: body
        }).then(function (response) {
            return response.json().catch(function () { return { ok: false, error: 'bad_response' }; });
        }).then(function (data) {
            if (data && data.ok) {
                clearError(options && options.stepEl);
                if (data.steps) {
                    apply(options && options.runEl, data);
                }
            } else {
                showError(options && options.stepEl, data);
            }
            return data;
        }).catch(function () {
            showError(options && options.stepEl, { message: null });
        });
    }

    function payloadFor(runEl, action, extra) {
        var base = { action: action, runs_id: runEl.getAttribute('data-sop-run') };
        Object.keys(extra || {}).forEach(function (key) { base[key] = extra[key]; });
        return base;
    }

    // -------------------------------------------------------- applying state

    /** Write the server's view of the run onto the DOM. */
    function apply(runEl, data) {
        if (!runEl) {
            return;
        }

        Object.keys(data.steps).forEach(function (stepsId) {
            var state = data.steps[stepsId];
            var stepEl = runEl.querySelector('.sop-step[data-sop-step-id="' + cssEscape(stepsId) + '"]');
            if (!stepEl) {
                return;
            }

            stepEl.hidden = !state.visible;
            stepEl.classList.toggle('sop-step--done', state.state === 'done');
            stepEl.classList.toggle('sop-step--skipped', state.state === 'skipped');

            var meta = stepEl.querySelector('.sop-step-meta');
            if (meta) {
                meta.textContent = state.meta || '';
            }

            // A skipped step's controls are dead until it is un-skipped: the
            // answer it holds is "not done", and letting someone type into it
            // would produce a step that is both skipped and answered.
            setInputsDisabled(stepEl, state.state === 'skipped' || !editable(runEl));

            var noteBox = stepEl.querySelector('.sop-step-note');
            var noteField = stepEl.querySelector('[data-sop-note]');
            if (noteBox && noteField && document.activeElement !== noteField) {
                noteField.value = state.note || '';
                noteBox.hidden = !state.note;
            }
        });

        if (data.progress) {
            applyProgress(runEl, data.progress);
            // The fields-panel reminder is outside this run's DOM, so it is
            // updated by run id rather than by walking up from the control.
            // Letting it go stale would be worse than not having it: a line
            // still claiming two steps outstanding after they were answered
            // teaches people to stop believing it.
            applyPanel(runEl.getAttribute('data-sop-run'), data);
        }

        if (data.status) {
            runEl.className = runEl.className.replace(/sop-run--\w+/, 'sop-run--' + data.status);

            // The card class alone is invisible. The badge is the thing a
            // technician actually reads to know whether the procedure is
            // finished, so it has to be restyled too — text and colour both,
            // or a completed run keeps wearing the in-progress blue.
            var badge = runEl.querySelector('.sop-status-badge .badge');
            if (badge && data.status_label) {
                badge.textContent = data.status_label;
                badge.className = 'badge ' + (data.status_class || 'bg-blue');
            }
        }
    }

    function applyProgress(runEl, progress) {
        var bar = runEl.querySelector('.progress-bar');
        var text = runEl.querySelector('.sop-progress-text');
        var required = runEl.querySelector('.sop-required-text');

        if (bar) {
            bar.style.width = progress.percent + '%';
        }
        // Both strings arrive already composed and translated; nothing here
        // knows how to pluralise "step" in the reader's language.
        if (text) {
            text.textContent = progress.text;
        }
        if (required) {
            required.textContent = progress.required_text;
        }
    }

    function applyPanel(runsId, data) {
        var row = document.querySelector('[data-sop-panel-run="' + cssEscape(runsId) + '"]');
        if (!row) {
            return;
        }

        var p = data.progress;
        var count = row.querySelector('[data-sop-panel-count]');
        var note = row.querySelector('[data-sop-panel-note]');
        var bar = row.querySelector('.progress-bar');

        if (count) {
            count.textContent = p.done + '/' + p.total;
        }
        if (bar) {
            bar.style.width = p.percent + '%';
        }
        if (note && data.panel_note) {
            note.textContent = data.panel_note;
        }

        row.classList.toggle('sop-panel-row--done', data.status === 'completed');
        row.classList.toggle('sop-panel-row--blocking', !!data.panel_blocking);

        // Answering the last required step should hand the solve button back
        // in the same breath, not on the next page load.
        applyResolutionBlock();
    }

    function setInputsDisabled(stepEl, disabled) {
        var inputs = stepEl.querySelectorAll(
            '.sop-step-input input, .sop-step-input textarea, .sop-step-input select, .sop-step-input button'
        );
        Array.prototype.forEach.call(inputs, function (el) { el.disabled = disabled; });
    }

    function showError(stepEl, data) {
        if (!stepEl) {
            return;
        }
        var box = stepEl.querySelector('.sop-step-error');
        if (!box) {
            return;
        }
        box.textContent = (data && data.message)
            ? data.message
            : (box.getAttribute('data-sop-fallback') || 'Could not save.');
        box.hidden = false;
    }

    function clearError(stepEl) {
        if (!stepEl) {
            return;
        }
        var box = stepEl.querySelector('.sop-step-error');
        if (box) {
            box.hidden = true;
        }
    }

    // ---------------------------------------------------------- collecting

    /**
     * Build the answer payload for a step from whatever its type renders.
     *
     * Returns null when there is nothing to send, and {clear: true} when the
     * control has been emptied — an emptied field is a retraction of the
     * answer, not an answer of "".
     */
    function collect(stepEl) {
        var type = stepEl.getAttribute('data-sop-type');
        var direct = stepEl.querySelector('[data-sop-step]');

        switch (type) {
            case 'check':
                return { value: direct && direct.checked ? '1' : '0' };

            case 'multichoice':
                var picked = [];
                Array.prototype.forEach.call(
                    stepEl.querySelectorAll('[data-sop-multi]:checked'),
                    function (el) { picked.push(el.value); }
                );
                return picked.length ? { value: picked } : { clear: true };

            // Both render a row of radios, so both have to read the *checked*
            // one. Falling through to the default below would take the first
            // radio in the DOM whatever the technician picked — which for a
            // Yes/No step means every answer is stored as "yes".
            case 'choice':
            case 'yesno':
                var chosen = stepEl.querySelector('[data-sop-step]:checked');
                return chosen ? { value: chosen.value } : { clear: true };

            // Answered by something other than a control — a linked ticket
            // closing, an approval landing. There is nothing here for a change
            // event to have come from.
            case 'ticket':
            case 'approval':
                return null;

            case 'user':
                var user = stepEl.querySelector('select[name^="sop_user_"]');
                var users_id = user ? parseInt(user.value, 10) : 0;
                return users_id > 0 ? { value: users_id } : { clear: true };

            case 'asset':
                var assetType = stepEl.querySelector('select[name^="sop_asset_type_"]');
                var assetId = stepEl.querySelector('select[name^="sop_asset_id_"]');
                var items_id = assetId ? parseInt(assetId.value, 10) : 0;
                if (!assetType || !assetType.value || !(items_id > 0)) {
                    return { clear: true };
                }
                return { value_itemtype: assetType.value, value_items_id: items_id };

            default:
                if (!direct) {
                    return null;
                }
                return direct.value.trim() === '' ? { clear: true } : { value: direct.value };
        }
    }

    function save(stepEl) {
        var runEl = runOf(stepEl);
        var containerEl = container(stepEl);
        if (!runEl || !containerEl || !editable(runEl)) {
            return;
        }

        var collected = collect(stepEl);
        if (collected === null) {
            return;
        }

        var steps_id = stepEl.getAttribute('data-sop-step-id');
        var payload = collected.clear
            ? payloadFor(runEl, 'clear', { steps_id: steps_id })
            : payloadFor(runEl, 'answer', Object.assign({ steps_id: steps_id }, collected));

        post(containerEl, payload, { runEl: runEl, stepEl: stepEl });
    }

    // ------------------------------------------------------------- events

    var timers = {};

    /** Free-text fields save on a pause, so a sentence is one request. */
    function onInput(event) {
        var el = event.target;
        var stepEl = stepOf(el);
        if (!stepEl) {
            return;
        }

        if (el.hasAttribute('data-sop-note')) {
            debounce('note:' + stepEl.getAttribute('data-sop-step-id'), function () {
                saveNote(stepEl);
            });
            return;
        }

        if (!el.hasAttribute('data-sop-step')) {
            return;
        }

        var type = stepEl.getAttribute('data-sop-type');
        if (type === 'text' || type === 'textarea' || type === 'number') {
            debounce('step:' + stepEl.getAttribute('data-sop-step-id'), function () {
                save(stepEl);
            });
        }
    }

    function onChange(event) {
        var el = event.target;
        var stepEl = stepOf(el);
        if (!stepEl) {
            return;
        }

        // With jQuery present, selects are handled exclusively by the bridge
        // below — select2 emits through jQuery *and* the native event fires,
        // so without this the picker would save twice per choice.
        if (el.tagName === 'SELECT' && window.jQuery && !event.__fromJquery) {
            return;
        }

        if (el.hasAttribute('data-sop-upload')) {
            upload(stepEl, el);
            return;
        }

        // A select inside a step is one of GLPI's pickers; anything else has
        // to opt in with data-sop-step so that stray controls in a step's help
        // text cannot post answers.
        if (!el.hasAttribute('data-sop-step')
            && !el.hasAttribute('data-sop-multi')
            && el.tagName !== 'SELECT') {
            return;
        }

        // Cancel a pending debounce: a change event on a field that is also
        // being typed into (a date picker, a number spinner) would otherwise
        // save twice, and the second save could carry a stale value.
        cancel('step:' + stepEl.getAttribute('data-sop-step-id'));
        save(stepEl);
    }

    function onClick(event) {
        var button = event.target.closest ? event.target.closest('[data-sop-action]') : null;
        if (!button) {
            return;
        }

        var containerEl = container(button);
        var runEl = runOf(button);
        if (!containerEl || !runEl) {
            return;
        }

        event.preventDefault();

        switch (button.getAttribute('data-sop-action')) {
            case 'note':
                toggleNote(button);
                return;
            case 'skip':
                skip(containerEl, runEl, button);
                return;
            case 'clear':
                clear(containerEl, runEl, button);
                return;
            case 'log':
                toggleLog(containerEl, runEl, button);
                return;
            case 'abandon':
                abandon(containerEl, runEl, button);
                return;
            case 'spawn':
                spawn(containerEl, runEl, button);
                return;
            case 'unlock':
            case 'lock':
                // Whether the controls are disabled, and which of the two
                // buttons is offered, are both server-rendered — so the honest
                // way to show the new state is to re-render rather than to
                // reconstruct it here from a flag.
                post(containerEl, payloadFor(runEl, button.getAttribute('data-sop-action'), {}), {
                    runEl: runEl
                }).then(function (data) {
                    if (data && data.ok) {
                        window.location.reload();
                    }
                });
                return;
        }
    }

    // ------------------------------------------------------------ actions

    function toggleNote(button) {
        var stepEl = stepOf(button);
        var box = stepEl && stepEl.querySelector('.sop-step-note');
        if (!box) {
            return;
        }
        box.hidden = !box.hidden;
        if (!box.hidden) {
            var field = box.querySelector('[data-sop-note]');
            if (field) {
                field.focus();
            }
        }
    }

    function saveNote(stepEl) {
        var runEl = runOf(stepEl);
        var containerEl = container(stepEl);
        var field = stepEl.querySelector('[data-sop-note]');
        if (!runEl || !containerEl || !field || !editable(runEl)) {
            return;
        }

        post(containerEl, payloadFor(runEl, 'note', {
            steps_id: stepEl.getAttribute('data-sop-step-id'),
            note: field.value
        }), { runEl: runEl, stepEl: stepEl });
    }

    /**
     * Skipping is a two-stage action when a reason is required: the note box
     * doubles as the reason field, so the reason ends up on the record rather
     * than in a modal that discards it.
     */
    function skip(containerEl, runEl, button) {
        var stepEl = stepOf(button);
        if (!stepEl) {
            return;
        }

        var alreadySkipped = stepEl.classList.contains('sop-step--skipped');
        var needsReason = containerEl.getAttribute('data-sop-skip-reason') === '1' && !alreadySkipped;
        var box = stepEl.querySelector('.sop-step-note');
        var field = stepEl.querySelector('[data-sop-note]');

        if (needsReason && field && (!field.value.trim() || box.hidden)) {
            box.hidden = false;
            field.placeholder = button.getAttribute('data-sop-reason-prompt') || field.placeholder;
            field.focus();
            showError(stepEl, { message: button.getAttribute('data-sop-reason-message') || null });
            return;
        }

        post(containerEl, payloadFor(runEl, 'skip', {
            steps_id: stepEl.getAttribute('data-sop-step-id'),
            reason: field ? field.value : ''
        }), { runEl: runEl, stepEl: stepEl });
    }

    function clear(containerEl, runEl, button) {
        var stepEl = stepOf(button);
        if (!stepEl) {
            return;
        }

        resetControls(stepEl);

        post(containerEl, payloadFor(runEl, 'clear', {
            steps_id: stepEl.getAttribute('data-sop-step-id')
        }), { runEl: runEl, stepEl: stepEl });
    }

    /** Empty a step's controls locally; the server clears the stored answer. */
    function resetControls(stepEl) {
        Array.prototype.forEach.call(
            stepEl.querySelectorAll('.sop-step-input input, .sop-step-input textarea, .sop-step-input select'),
            function (el) {
                if (el.type === 'checkbox' || el.type === 'radio') {
                    el.checked = false;
                } else if (el.tagName === 'SELECT') {
                    el.value = '';
                    if (window.jQuery && window.jQuery(el).data('select2')) {
                        window.jQuery(el).val('').trigger('change.select2');
                    }
                } else if (el.type !== 'file') {
                    el.value = '';
                }
            }
        );
    }

    function upload(stepEl, input) {
        var runEl = runOf(stepEl);
        var containerEl = container(stepEl);
        if (!runEl || !containerEl || !input.files || !input.files.length) {
            return;
        }

        var form = new FormData();
        form.append('action', 'upload');
        form.append('runs_id', runEl.getAttribute('data-sop-run'));
        form.append('steps_id', stepEl.getAttribute('data-sop-step-id'));
        form.append('file', input.files[0]);

        input.disabled = true;
        post(containerEl, form, { runEl: runEl, stepEl: stepEl }).then(function (data) {
            input.disabled = false;
            input.value = '';
            // The document link is server-rendered, so the only honest way to
            // show it is to re-render. Reloading is heavy-handed but a filed
            // document is a once-per-step event, not a keystroke.
            if (data && data.ok) {
                window.location.reload();
            }
        });
    }

    function toggleLog(containerEl, runEl, button) {
        var panel = runEl.querySelector('.sop-run-log');
        if (!panel) {
            return;
        }

        if (!panel.hidden) {
            panel.hidden = true;
            return;
        }

        panel.hidden = false;
        panel.textContent = button.getAttribute('data-sop-loading') || '…';

        post(containerEl, payloadFor(runEl, 'log', {}), { runEl: null }).then(function (data) {
            if (!data || !data.ok) {
                return;
            }
            renderLog(panel, data.entries || []);
        });
    }

    function renderLog(panel, entries) {
        panel.textContent = '';

        var list = document.createElement('ul');
        list.className = 'sop-log-list';

        entries.forEach(function (entry) {
            var item = document.createElement('li');

            var when = document.createElement('span');
            when.className = 'sop-log-when';
            when.textContent = entry.when;

            var body = document.createElement('span');
            // textContent throughout: log details carry technician-entered
            // text, and this panel is built in the browser where nothing has
            // been through the server's escaping.
            body.textContent = entry.who + ' ' + entry.action
                + (entry.step ? ' — ' + entry.step : '')
                + (entry.detail ? ': ' + entry.detail : '');

            item.appendChild(when);
            item.appendChild(body);
            list.appendChild(item);
        });

        panel.appendChild(list);
    }

    /**
     * Raise the linked ticket for a `ticket` step.
     *
     * Reloads rather than patching the step in place, for the same reason the
     * document upload does: what replaces the button is a server-rendered link
     * to a real ticket, carrying core's own markup and status wording, and
     * rebuilding that here would be a second, worse copy of it.
     *
     * The button is disabled for the round trip. Raising two licensing tickets
     * because a double-click got through is exactly the mistake this step type
     * exists to prevent somebody making by hand.
     */
    function spawn(containerEl, runEl, button) {
        var stepEl = stepOf(button);
        if (!stepEl) {
            return;
        }

        var message = button.getAttribute('data-sop-confirm');
        if (message && !window.confirm(message)) {
            return;
        }

        button.disabled = true;

        post(containerEl, payloadFor(runEl, 'spawn', {
            steps_id: stepEl.getAttribute('data-sop-step-id')
        }), { runEl: runEl, stepEl: stepEl }).then(function (data) {
            if (data && data.ok) {
                window.location.reload();
                return;
            }
            button.disabled = false;
        });
    }

    function abandon(containerEl, runEl, button) {
        var message = button.getAttribute('data-sop-confirm');
        if (message && !window.confirm(message)) {
            return;
        }

        post(containerEl, payloadFor(runEl, 'abandon', {}), { runEl: runEl }).then(function (data) {
            if (data && data.ok) {
                window.location.reload();
            }
        });
    }

    // ------------------------------------------- suppressing resolution

    /**
     * Take the resolution affordances away while an enforcing procedure has
     * required steps outstanding.
     *
     * This is a *courtesy layer over enforcement that already works*, not the
     * enforcement itself. Enforcement lives in the endpoint — a status change
     * into solved/closed and an ITILSolution add are both refused server-side —
     * and that stays true whatever happens here. What this adds is not being
     * offered a button that is going to refuse you: finding out at save time,
     * after composing a solution, is a bad way to learn the procedure was not
     * finished.
     *
     * GLPI has no hook for either affordance. `getAllowedStatusArray()` and
     * `canSolve()` are plain methods, and the answer menu is filtered in Twig
     * from an itemtypes array a plugin can only add to. Overriding core's
     * `solution` entry through TIMELINE_ANSWER_ACTIONS would mean carrying a
     * copy of its definition — template, icon, item and all — which breaks the
     * solution form for everyone the moment core changes it. Disabling the
     * controls is the smaller lie.
     *
     * Controls are disabled rather than removed so that clearing the block puts
     * them back without a reload.
     */
    var SOLVED_STATUSES = ['5', '6']; // CommonITILObject::SOLVED, ::CLOSED

    function applyResolutionBlock() {
        var blocking = document.querySelector('.sop-panel-row--blocking') !== null;

        // The solution action, wherever the footer put it: the primary button
        // when it is the default action, or an item in the split dropdown.
        Array.prototype.forEach.call(
            document.querySelectorAll('.answer-action.action-solution'),
            function (el) {
                el.classList.toggle('sop-blocked', blocking);
                el.toggleAttribute('disabled', blocking);
                if (blocking) {
                    el.setAttribute('aria-disabled', 'true');
                    el.setAttribute('title', blockReason());
                } else {
                    el.removeAttribute('aria-disabled');
                    el.removeAttribute('title');
                }
            }
        );

        var status = document.querySelector('select[name="status"]');
        if (status) {
            var changed = false;
            Array.prototype.forEach.call(status.options, function (opt) {
                if (SOLVED_STATUSES.indexOf(opt.value) === -1) {
                    return;
                }
                // Never disable the status the item is already in, or the
                // select would render with nothing selected and the next save
                // would silently rewrite it.
                var target = blocking && !opt.selected;
                if (opt.disabled !== target) {
                    opt.disabled = target;
                    changed = true;
                }
            });
            // select2 renders from a snapshot taken at init, so the underlying
            // <option> changing is invisible to it until it is told.
            if (changed && window.jQuery && window.jQuery(status).data('select2')) {
                window.jQuery(status).trigger('change.select2');
            }
        }
    }

    function blockReason() {
        var note = document.querySelector('.sop-panel-row--blocking [data-sop-panel-note]');
        return note ? note.textContent.trim() : '';
    }

    // ------------------------------------------------------------ helpers

    function debounce(key, fn) {
        cancel(key);
        timers[key] = window.setTimeout(function () {
            delete timers[key];
            fn();
        }, SAVE_DEBOUNCE_MS);
    }

    function cancel(key) {
        if (timers[key]) {
            window.clearTimeout(timers[key]);
            delete timers[key];
        }
    }

    /** Step ids are integers from the server, but the selector is still built. */
    function cssEscape(value) {
        return String(value).replace(/["\\]/g, '\\$&');
    }
})();

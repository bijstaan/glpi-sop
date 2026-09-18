<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

/**
 * One posted procedure, written down.
 *
 * The builder sends the whole thing — every heading, every step, every clause,
 * in the order they appear on screen — and this turns that into rows. Not a
 * diff: the browser does not know which rows exist, only what the procedure
 * should look like, and asking it to track that would be asking it to hold a
 * second copy of the model that could be wrong.
 *
 * ## Keys, not ids
 *
 * Everything on the canvas is identified by a key. `s12` and `t34` are a
 * heading and a step that already exist; `n7` is something the author added a
 * moment ago and that has no id yet. A step names its heading by key, and a
 * clause names the step it is gated on by key, so a step added and immediately
 * branched off is expressible in one save — which is exactly what authoring a
 * branch is.
 *
 * Keys are resolved in three passes, because that is the dependency order:
 * headings, then steps, then clauses. A clause can therefore point at a step
 * created in the same request, including one below it in the payload — the
 * "must come earlier" rule is then checked against the *final* order rather
 * than against the order things happened to be written in.
 *
 * ## Deletion
 *
 * A heading or step in the database and absent from the payload is deleted,
 * and that is the point of sending everything: the author removed it from the
 * canvas. Deleting a step deletes the answers given to it, which is why the
 * browser asks first. Nothing outside this SOP is ever touched — every id that
 * arrives is re-read and checked to belong here before it is used, because an
 * id in a payload is a claim, not a fact.
 *
 * ## One recount, not eighty
 *
 * Every write here would ordinarily bump the SOP's revision and re-derive every
 * run of it, through {@see Step::post_updateItem()}. For a thirty-step save
 * that is thirty full recomputations of the same numbers, twenty-nine of which
 * are read by nobody. {@see Sop::deferStructureChange()} holds them until the
 * save is finished and then does the work once.
 */
final class BuilderSave
{
    /** @var array<string,int> canvas key => section id */
    private array $sections = [];

    /** @var array<string,int> canvas key => step id */
    private array $steps = [];

    /** @var string[] */
    private array $errors = [];

    public function __construct(private Sop $sop)
    {
    }

    /**
     * Write the posted procedure.
     *
     * @param array<string,mixed> $post
     * @return string[] what could not be done, empty on success
     */
    public function save(array $post): array
    {
        $sops_id = (int) $this->sop->getID();

        $posted_sections = $this->rows($post['sections'] ?? []);
        $posted_steps    = $this->rows($post['steps'] ?? []);

        Sop::deferStructureChange($sops_id, function () use ($sops_id, $posted_sections, $posted_steps): void {
            $this->writeSections($sops_id, $posted_sections);
            $this->writeSteps($sops_id, $posted_steps);
            $this->deleteMissingSteps($sops_id);
            $this->deleteMissingSections($sops_id);
            $this->writeConditions($posted_steps);
        });

        return $this->errors;
    }

    /**
     * Canvas key => the id it now names, for everything written.
     *
     * The browser holds keys, not ids, and a key it invented (`n7`) becomes a
     * real row during the save. Handing back the map is what lets the canvas
     * stay open afterwards instead of being reloaded — see ajax/builder.php.
     *
     * @return array<string,int>
     */
    public function keys(): array
    {
        return $this->sections + $this->steps;
    }

    /**
     * The posted rows, as a list.
     *
     * PHP turns `steps[0][label]` into an array keyed by the browser's indices,
     * and those are contiguous — but a payload is not a promise, and a gap
     * would silently reorder the procedure. Sorting by key and discarding the
     * keys makes the order the one the browser sent whatever it sent.
     *
     * @param mixed $raw
     * @return array<int,array<string,mixed>>
     */
    private function rows(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $keys = array_keys($raw);
        sort($keys, SORT_NUMERIC);

        $out = [];
        foreach ($keys as $key) {
            if (is_array($raw[$key])) {
                $out[] = $raw[$key];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------- headings

    /**
     * Headings, in canvas order, renumbered from the top.
     *
     * Rank is position: the canvas has no rank control and never had one worth
     * having, since an author reordering headings is dragging them rather than
     * typing numbers.
     *
     * @param array<int,array<string,mixed>> $posted
     */
    private function writeSections(int $sops_id, array $posted): void
    {
        $rank = 0;

        foreach ($posted as $row) {
            $key  = (string) ($row['key'] ?? '');
            $name = trim((string) ($row['name'] ?? ''));

            // The implicit heading is not a row. It is where unfiled steps go,
            // and it has always been section 0.
            if ($key === Builder::UNFILED || $key === '') {
                continue;
            }

            if ($name === '') {
                $name = __('Untitled section', 'glpisop');
            }

            $fields = [
                // Capped where the column caps, and nowhere else. `name` is a
                // VARCHAR(255), so 255 is the truthful limit; `content` is TEXT
                // and has none — trimming it to fit the one-line box it is
                // edited in would shorten a description nobody touched the
                // first time this editor saved a procedure written before it.
                'name'       => mb_substr($name, 0, 255),
                'content'    => trim((string) ($row['content'] ?? '')),
                'rank_order' => ++$rank,
            ];

            $section = new Section();
            $id      = $this->existingId($section, $key, 's', 'plugin_glpisop_sops_id', $sops_id);

            if ($id > 0) {
                $section->update(['id' => $id] + $fields);
            } else {
                $id = (int) $section->add($fields + [
                    'plugin_glpisop_sops_id' => $sops_id,
                    'date_creation'          => date('Y-m-d H:i:s'),
                ]);
            }

            if ($id > 0) {
                $this->sections[$key] = $id;
            }
        }
    }

    // ---------------------------------------------------------------- steps

    /**
     * Steps, in canvas order.
     *
     * Rank is per heading and heading order dominates — see
     * {@see Step::allFor()} — so each heading gets its own counter and the flat
     * order on screen survives the round trip.
     *
     * @param array<int,array<string,mixed>> $posted
     */
    private function writeSteps(int $sops_id, array $posted): void
    {
        $ranks = [];

        foreach ($posted as $row) {
            $key   = (string) ($row['key'] ?? '');
            $label = trim((string) ($row['label'] ?? ''));

            if ($key === '' || $label === '') {
                // A step with no label is one the author started and left; it
                // is not an error worth refusing the save over, and keeping it
                // would put a nameless row in every run of the procedure.
                continue;
            }

            $type = (string) ($row['type'] ?? StepType::CHECK);
            if (!StepType::exists($type)) {
                $type = StepType::CHECK;
            }

            $section_key = (string) ($row['section'] ?? Builder::UNFILED);
            $sections_id = $this->sections[$section_key] ?? 0;

            $rank = $ranks[$sections_id] = ($ranks[$sections_id] ?? 0) + 1;

            $cfg  = is_array($row['cfg'] ?? null) ? $row['cfg'] : [];
            $mode = (string) ($row['mode'] ?? Condition::MODE_ALL);

            $fields = [
                'plugin_glpisop_sections_id' => $sections_id,
                'label'                      => mb_substr($label, 0, 255),
                'help'                       => (string) ($row['help'] ?? ''),
                'step_type'                  => $type,
                'config_json'                => json_encode(
                    StepOptions::collect($type, $cfg),
                    JSON_UNESCAPED_UNICODE
                ),
                'is_required'                => !empty($row['required']) ? 1 : 0,
                'is_active'                  => !empty($row['active']) ? 1 : 0,
                'rank_order'                 => $rank,
                'depends_mode'               => $mode === Condition::MODE_ANY
                    ? Condition::MODE_ANY
                    : Condition::MODE_ALL,
            ];

            $step = new Step();
            $id   = $this->existingId($step, $key, 't', 'plugin_glpisop_sops_id', $sops_id);

            if ($id > 0) {
                $step->update(['id' => $id] + $fields);
            } else {
                $id = (int) $step->add($fields + [
                    'plugin_glpisop_sops_id' => $sops_id,
                    'date_creation'          => date('Y-m-d H:i:s'),
                ]);
            }

            if ($id > 0) {
                $this->steps[$key] = $id;
            }
        }
    }

    // ------------------------------------------------------------- deletion

    private function deleteMissingSteps(int $sops_id): void
    {
        $kept = array_values($this->steps);

        foreach (Step::allFor($sops_id, false) as $row) {
            $id = (int) $row['id'];
            if (in_array($id, $kept, true)) {
                continue;
            }

            // Through the model, not the table: purging a step also removes the
            // answers given to it and every clause elsewhere that was about it
            // — see Step::cleanDBonPurge(). A raw delete would leave branches
            // pointing at nothing, which under "all of them" makes their
            // children silently vanish from every run.
            (new Step())->delete(['id' => $id], true);
        }
    }

    private function deleteMissingSections(int $sops_id): void
    {
        $kept = array_values($this->sections);

        foreach (array_keys(Section::allFor($sops_id)) as $sections_id) {
            $id = (int) $sections_id;
            if (in_array($id, $kept, true)) {
                continue;
            }

            (new Section())->delete(['id' => $id], true);
        }
    }

    // -------------------------------------------------------------- clauses

    /**
     * Replace every gate on the procedure with the one on the canvas.
     *
     * Replaced rather than reconciled: a clause has no identity an author would
     * recognise — it is three choices, and changing any of them makes it a
     * different clause — so there is nothing to match old rows against. The
     * conditions table carries no history and nothing references a clause by
     * id, so rewriting it is invisible to everything except this function.
     *
     * @param array<int,array<string,mixed>> $posted
     */
    private function writeConditions(array $posted): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach ($posted as $row) {
            $key      = (string) ($row['key'] ?? '');
            $steps_id = $this->steps[$key] ?? 0;
            if ($steps_id <= 0) {
                continue;
            }

            $DB->delete(Condition::getTable(), ['plugin_glpisop_steps_id' => $steps_id]);

            $rank = 0;
            foreach ($this->rows($row['conditions'] ?? []) as $clause) {
                $written = $this->clause(
                    $steps_id,
                    $clause,
                    $rank + 1,
                    trim((string) ($row['label'] ?? ''))
                );
                if ($written) {
                    $rank++;
                }
            }
        }
    }

    /**
     * One clause, checked against what this build can actually evaluate.
     *
     * Deliberately a wider test than the one the editor applies when it decides
     * what to *offer*, and the difference is the whole of what makes an upgrade
     * safe. This save rewrites the procedure wholesale, so a clause it refuses
     * is a clause it deletes — and the rows most likely to be refused are the
     * ones nobody touched:
     *
     *  - a criterion that is no longer on offer *for this SOP*, because its
     *    itemtypes changed or an administrator narrowed what the plugin applies
     *    to in Settings. The criterion is still one this build understands, and
     *    {@see Condition} reads a clause it cannot evaluate as satisfied, so
     *    keeping it is both lossless and harmless. Re-widening the setting
     *    brings it back to life exactly as it was.
     *
     *  - a clause pointing at a step *later* in the procedure. Authoring has
     *    never offered one and still does not, but reordering used to not
     *    re-check gates, so old procedures contain them. They are safe:
     *    {@see Visibility::resolve()} resolves the graph rather than walking it
     *    in order, and detects cycles explicitly — its own docblock names the
     *    hand-edited row as the case it is hardened against.
     *
     * What is still refused is a clause this build could not evaluate at all: an
     * unknown source, an unknown criterion, an operator that does not belong to
     * the subject, a value where one is needed, or a step that is not part of
     * this procedure. Those are dropped and reported, because there is nothing
     * to preserve.
     *
     * @param array<string,mixed> $clause
     */
    private function clause(int $steps_id, array $clause, int $rank, string $label): bool
    {
        [$source, $target] = Condition::splitSubject((string) ($clause['subject'] ?? ''));

        if (!array_key_exists($source, Condition::sources())) {
            $this->errors[] = sprintf(
                __('A condition on “%s” is of a kind this version does not have, and was '
                    . 'dropped.', 'glpisop'),
                $label
            );
            return false;
        }

        $operator = (string) ($clause['op'] ?? '');
        $value    = (string) ($clause['value'] ?? '');

        $row = [
            'plugin_glpisop_steps_id' => $steps_id,
            'source'                  => $source,
            'depends_steps_id'        => 0,
            'criterion'               => '',
            'match_condition'         => $operator,
            'value'                   => $value,
            'rank_order'              => $rank,
            'date_creation'           => date('Y-m-d H:i:s'),
        ];

        if ($source === Condition::SRC_STEP) {
            $parent_id = $this->steps[$target] ?? 0;

            if ($parent_id <= 0) {
                $this->errors[] = sprintf(
                    __('A condition on “%s” points at a step that is no longer in the procedure, '
                        . 'and was dropped.', 'glpisop'),
                    $label
                );
                return false;
            }

            if (!array_key_exists($operator, Step::operators())) {
                $this->errors[] = self::unknownOperator($label);
                return false;
            }

            $row['depends_steps_id'] = $parent_id;

            // The value belongs to the operator, not to the clause. Storing one
            // beside "was answered" would show up in the description as a
            // comparison that is not happening.
            if (!Step::operatorNeedsValue($operator)) {
                $row['value'] = '';
            }
        } else {
            $definition = Condition::definition($source, $target);

            if ($definition === null) {
                $this->errors[] = sprintf(
                    __('A condition on “%s” tests something this version does not understand, and '
                        . 'was dropped.', 'glpisop'),
                    $label
                );
                return false;
            }

            if (!array_key_exists($operator, Condition::operatorsFor($source, $target))) {
                $this->errors[] = self::unknownOperator($label);
                return false;
            }

            // An id-valued criterion needs a real id. The pickers can come up
            // empty — an instance with no categories yet offers only the blank
            // row — and a clause saved against 0 reads as "Category is: 0" and
            // can never hold.
            $blank = in_array($definition['kind'], ['dropdown', 'actor_group'], true)
                ? (int) $value <= 0
                : trim($value) === '';

            if ($blank) {
                $this->errors[] = sprintf(
                    __('A condition on “%s” has no value, and was dropped.', 'glpisop'),
                    $label
                );
                return false;
            }

            $row['criterion'] = $target;
        }

        return (new Condition())->add($row) > 0;
    }

    /**
     * An operator that does not belong to the thing it is comparing.
     *
     * Said out loud rather than dropped quietly. Nothing the editor can produce
     * reaches here — it offers the operators that go with the subject and
     * nothing else — so this is either a stale canvas or a row from a build
     * whose vocabulary differed. Either way the author is about to have one
     * fewer condition than they had, and being told is the difference between a
     * procedure that changed and a procedure that changed silently.
     */
    private static function unknownOperator(string $label): string
    {
        return sprintf(
            __('A condition on “%s” compares in a way that does not apply to what it tests, and '
                . 'was dropped.', 'glpisop'),
            $label
        );
    }

    // ------------------------------------------------------------- plumbing

    /**
     * The id a canvas key names, if it is one this SOP owns.
     *
     * A key like `t34` is the browser saying "step 34". Believing it would let
     * a crafted payload move somebody else's step into this procedure, so the
     * row is read and its parent checked. A key that does not check out is
     * treated as new rather than refused: the author's intent was a step here,
     * and creating one is closer to that than losing it.
     */
    private function existingId(
        \CommonDBTM $item,
        string $key,
        string $prefix,
        string $parent_field,
        int $parent_id
    ): int {
        if (!str_starts_with($key, $prefix)) {
            return 0;
        }

        $id = (int) substr($key, strlen($prefix));
        if ($id <= 0 || !$item->getFromDB($id)) {
            return 0;
        }

        return (int) $item->fields[$parent_field] === $parent_id ? $id : 0;
    }
}

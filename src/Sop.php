<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpisop;

use CommonDBTM;
use Html;
use Session;

/**
 * A standard operating procedure: the template an administrator authors.
 *
 * The SOP owns three things an author edits on separate tabs — its steps (the
 * procedure), its triggers (when it should turn up on its own) and its template
 * bindings (which ITIL templates carry it). Runs are created from it and
 * reference it, but never write to it.
 *
 * `sop_version` is bumped whenever the structure changes. It is not versioning
 * in the sense of keeping old copies: it is a marker stamped onto each run so
 * that a run started against a five-step procedure is visibly not the same
 * thing as one started after a sixth step was added.
 */
class Sop extends CommonDBTM
{
    public static $rightname = 'plugin_glpisop_sop';

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('SOP', 'SOPs', $nb, 'glpisop');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    public function isEntityAssign()
    {
        return true;
    }

    public function maybeRecursive()
    {
        return true;
    }

    /**
     * ITIL types this SOP is written for, intersected with what the plugin is
     * currently configured to touch.
     *
     * @return string[]
     */
    public function itemtypes(): array
    {
        $out = [];
        foreach (explode(',', (string) ($this->fields['itemtypes'] ?? '')) as $itemtype) {
            $itemtype = trim($itemtype);
            if ($itemtype !== '' && Settings::appliesTo($itemtype)) {
                $out[] = $itemtype;
            }
        }

        return $out;
    }

    public function appliesTo(string $itemtype): bool
    {
        return in_array($itemtype, $this->itemtypes(), true);
    }

    /**
     * Note that the structure changed.
     *
     * Called from the step and section forms rather than from here, because
     * that is where structural edits actually happen — the SOP row itself can
     * be renamed all day without the procedure being any different.
     */
    public static function bumpVersion(int $sops_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($sops_id <= 0) {
            return;
        }

        $DB->update(
            self::getTable(),
            ['sop_version' => new \QueryExpression('`sop_version` + 1'), 'date_mod' => date('Y-m-d H:i:s')],
            ['id' => $sops_id]
        );
    }

    /**
     * A new SOP is recursive unless the author says otherwise.
     *
     * The column defaults to 1, the shipped example uses 1, and both
     * programmatic authors — `Author::materialise()` and `AiTools` — write 1.
     * The form was the odd one out: core renders its "Child entities" checkbox
     * from `getEmpty()`, where every field is the empty string, so the box came
     * up unticked and a procedure written in a parent entity was invisible to
     * every entity beneath it. Setting it here is what makes the checkbox agree
     * with the rest of the plugin.
     */
    public function post_getEmpty()
    {
        $this->fields['is_recursive'] = 1;
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->validate($input);
        if ($input === false) {
            return false;
        }

        $input['sop_version'] = 1;

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        return $this->validate($input);
    }

    private function validate($input)
    {
        if (isset($input['name']) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect(__('An SOP needs a name.', 'glpisop'), false, ERROR);
            return false;
        }

        // The itemtype list arrives from a multi-checkbox, so an SOP that
        // applies to nothing is one unticked box away at all times. Refusing it
        // is kinder than accepting a procedure that can never attach.
        if (array_key_exists('itemtypes', $input)) {
            $picked = [];
            foreach ((array) $input['itemtypes'] as $itemtype) {
                $itemtype = trim((string) $itemtype);
                if (in_array($itemtype, Settings::SUPPORTED_ITEMTYPES, true)) {
                    $picked[] = $itemtype;
                }
            }
            if ($picked === []) {
                Session::addMessageAfterRedirect(
                    __('Choose at least one itemtype this SOP applies to.', 'glpisop'),
                    false,
                    ERROR
                );
                return false;
            }
            $input['itemtypes'] = implode(',', $picked);
        }

        return $input;
    }

    /**
     * Purging an SOP takes its structure with it, and its runs.
     *
     * Deliberately destructive rather than orphaning: a run whose steps no
     * longer exist renders as an empty procedure that claims to be complete,
     * which is worse than the record being gone.
     */
    public function cleanDBonPurge()
    {
        /** @var \DBmysql $DB */
        global $DB;

        $sops_id = (int) $this->fields['id'];

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Run::getTable(),
                'WHERE'  => ['plugin_glpisop_sops_id' => $sops_id],
            ]) as $run
        ) {
            Run::purgeRun((int) $run['id']);
        }

        foreach ([Step::getTable(), Section::getTable(), Trigger::getTable()] as $table) {
            $DB->delete($table, ['plugin_glpisop_sops_id' => $sops_id]);
        }

        $DB->delete(TemplateBinding::getTable(), ['plugin_glpisop_sops_id' => $sops_id]);
    }

    public function rawSearchOptions()
    {
        $options = [];

        $options[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        $options[] = [
            'id'            => '1',
            'table'         => self::getTable(),
            'field'         => 'name',
            'name'          => __('Name'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $options[] = [
            'id'       => '2',
            'table'    => self::getTable(),
            'field'    => 'content',
            'name'     => __('Description'),
            'datatype' => 'text',
        ];
        $options[] = [
            'id'       => '3',
            'table'    => self::getTable(),
            'field'    => 'itemtypes',
            'name'     => __('Applies to', 'glpisop'),
            'datatype' => 'string',
        ];
        $options[] = [
            'id'       => '4',
            'table'    => self::getTable(),
            'field'    => 'is_active',
            'name'     => __('Active'),
            'datatype' => 'bool',
        ];
        $options[] = [
            'id'       => '5',
            'table'    => self::getTable(),
            'field'    => 'is_autoattach',
            'name'     => __('Attaches itself', 'glpisop'),
            'datatype' => 'bool',
        ];
        $options[] = [
            'id'       => '6',
            'table'    => self::getTable(),
            'field'    => 'enforce_on_solve',
            'name'     => __('Blocks resolution', 'glpisop'),
            'datatype' => 'bool',
        ];
        $options[] = [
            'id'            => '7',
            'table'         => self::getTable(),
            'field'         => 'sop_version',
            'name'          => __('Revision', 'glpisop'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];
        $options[] = [
            'id'       => '16',
            'table'    => self::getTable(),
            'field'    => 'comment',
            'name'     => __('Comments'),
            'datatype' => 'text',
        ];
        $options[] = [
            'id'            => '19',
            'table'         => self::getTable(),
            'field'         => 'date_mod',
            'name'          => __('Last update'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];
        $options[] = [
            'id'            => '80',
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => __('Entity'),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];
        $options[] = [
            'id'       => '86',
            'table'    => self::getTable(),
            'field'    => 'is_recursive',
            'name'     => __('Child entities'),
            'datatype' => 'bool',
        ];
        $options[] = [
            'id'            => '121',
            'table'         => self::getTable(),
            'field'         => 'date_creation',
            'name'          => __('Creation date'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        return $options;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        $e        = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $selected = $this->itemtypes();
        if ($selected === [] && $this->isNewItem()) {
            $selected = ['Ticket'];
        }

        echo "<tr class='tab_bg_1'>";
        echo '<td>' . __s('Name') . '</td><td>';
        echo Html::input('name', ['value' => $this->fields['name'] ?? '', 'size' => 40]);
        echo '</td>';
        echo '<td>' . __s('Active') . '</td><td>';
        \Dropdown::showYesNo('is_active', $this->fields['is_active'] ?? 1);
        echo '</td></tr>';

        // Where the procedure lives.
        //
        // Core takes the entity from the entity selector and shows it as a
        // badge in the form header, which is right for an asset: you are
        // standing in the entity you are adding to. Procedures are written from
        // the Setup menu, and an administrator working there is usually looking
        // at the whole tree — a view whose active entity *is* the root. So
        // every SOP written in that view landed in Root entity, silently, and
        // the badge saying "Root entity" read as chrome rather than as the
        // answer to a question nobody had been asked.
        //
        // Asking is the fix. The picker defaults to the selector's own entity,
        // so working inside one entity is unchanged, and the tree view becomes
        // a decision instead of a surprise. It is offered on a new SOP only:
        // moving an existing one is a different operation, with runs attached
        // to it, and core already has Actions > Change entity for that.
        //
        // The field is deliberately named `entities_id`, the same as the hidden
        // input core's showFormHeader() emitted above. This select comes later
        // in the form and PHP keeps the last occurrence of a repeated name, so
        // the author's choice is what reaches add() — and, because
        // sop.form.php checks CREATE against the submitted input, what the
        // rights check authorises too.
        if ($this->isNewItem() && Session::isMultiEntitiesMode()) {
            echo "<tr class='tab_bg_1'>";
            echo '<td>' . __s('Entity') . '</td>';
            echo "<td colspan='3'>";
            // No comment bubble and no add-an-entity button: both are core's
            // defaults for a dropdown, and both are wrong here — one opens the
            // entity's own description, the other offers to create an entity
            // from inside a procedure form, and together they push the select
            // into a third of the row it is sitting in.
            \Entity::dropdown([
                'name'                => 'entities_id',
                'value'               => (int) ($this->fields['entities_id'] ?? Session::getActiveEntity()),
                'entity'              => $_SESSION['glpiactiveentities'] ?? [],
                'display_emptychoice' => false,
                'comments'            => false,
                'addicon'             => false,
                'width'               => '100%',
            ]);
            echo "<div class='form-text'>"
               . __s('Where the procedure lives. It is offered on items in this entity, and on '
                   . 'items in the entities beneath it while “Child entities” stays ticked.', 'glpisop')
               . '</div>';
            echo '</td></tr>';
        }

        echo "<tr class='tab_bg_1'>";
        echo '<td>' . __s('Applies to', 'glpisop') . '</td><td>';
        foreach (Settings::SUPPORTED_ITEMTYPES as $itemtype) {
            if (!class_exists($itemtype)) {
                continue;
            }
            $checked = in_array($itemtype, $selected, true) ? "checked='checked'" : '';
            echo "<label class='form-check form-check-inline'>";
            echo "<input type='checkbox' class='form-check-input' name='itemtypes[]' "
               . "value='" . $e($itemtype) . "' $checked>";
            echo "<span class='form-check-label'>" . $e($itemtype::getTypeName(1)) . '</span>';
            echo '</label>';
        }
        echo '</td>';
        echo '<td>' . __s('Attaches itself', 'glpisop') . '</td><td>';
        \Dropdown::showYesNo('is_autoattach', $this->fields['is_autoattach'] ?? 1);
        echo "<div class='form-text'>"
           . __s('Evaluate the triggers on this SOP and attach it automatically.', 'glpisop')
           . '</div>';
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'>";
        echo '<td>' . __s('Trigger logic', 'glpisop') . '</td><td>';
        \Dropdown::showFromArray('match_all', [
            1 => __('Every trigger must match', 'glpisop'),
            0 => __('Any trigger may match', 'glpisop'),
        ], ['value' => (int) ($this->fields['match_all'] ?? 1)]);
        echo '</td>';
        echo '<td>' . __s('Blocks resolution', 'glpisop') . '</td><td>';
        \Dropdown::showYesNo('enforce_on_solve', $this->fields['enforce_on_solve'] ?? 0);
        echo "<div class='form-text'>"
           . __s('Refuse to solve or close the item while a required step is outstanding. '
               . 'Has no effect unless enforcement is enabled in the plugin settings.', 'glpisop')
           . '</div>';
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'>";
        echo '<td>' . __s('Description') . '</td>';
        echo "<td colspan='3'>";
        echo "<textarea name='content' rows='3' class='form-control'>"
           . $e($this->fields['content'] ?? '') . '</textarea>';
        echo "<div class='form-text'>"
           . __s('Shown above the checklist. Say what the procedure is for and when it does not apply.', 'glpisop')
           . '</div>';
        echo '</td></tr>';

        echo "<tr class='tab_bg_1'>";
        echo '<td>' . __s('Comments') . '</td>';
        echo "<td colspan='3'>";
        echo "<textarea name='comment' rows='2' class='form-control'>"
           . $e($this->fields['comment'] ?? '') . '</textarea>';
        echo '</td></tr>';

        if (!$this->isNewItem()) {
            echo "<tr class='tab_bg_1'>";
            echo '<td>' . __s('Revision', 'glpisop') . '</td>';
            echo "<td colspan='3'>";
            echo '<span class="badge bg-secondary">r' . (int) ($this->fields['sop_version'] ?? 1) . '</span> ';
            echo "<span class='text-muted'>"
               . __s('Increases whenever a step or section changes. Each run records the revision it started against.', 'glpisop')
               . '</span>';
            echo '</td></tr>';
        }

        $this->showFormButtons($options);

        return true;
    }

    public static function getMenuContent()
    {
        if (!Session::haveRight(self::$rightname, READ)) {
            return false;
        }

        $links = [
            'search' => '/plugins/glpisop/front/sop.php',
            'add'    => '/plugins/glpisop/front/sop.form.php',
        ];

        // Drafting a procedure from resolved tickets, where glpi-ai is
        // installed to draft it with. GLPI renders an unrecognised link key as
        // the button's own markup, which is how a plugin gets a third button
        // next to Add and Search.
        //
        // Gated on the class existing rather than on Author::available(): the
        // menu is built once and cached in the session, so a link that came
        // and went with a provider being configured would be wrong far more
        // often than it was right. Whether the feature is *usable* is answered
        // on the page, where saying why costs a sentence rather than a
        // missing button nobody can ask about.
        if (class_exists(\GlpiPlugin\Glpiai\Client::class)) {
            $links['<i class="ti ti-wand"></i><span class="d-none d-xxl-block ms-1">'
                . __s('Draft from tickets', 'glpisop') . '</span>']
                = '/plugins/glpisop/front/author.php';
        }

        return [
            'title' => self::getTypeName(2),
            'page'  => '/plugins/glpisop/front/sop.php',
            'icon'  => self::getIcon(),
            'links' => $links,
        ];
    }

    /**
     * SOPs the current user may run, for a given itemtype and entity.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function activeFor(string $itemtype, int $entities_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Core's own entity restriction, rather than a hand-rolled ancestor
        // lookup: it is the piece that has to agree exactly with what the rest
        // of GLPI considers visible, and it already handles the recursive flag
        // and the empty-ancestor case that a naive `IN ()` gets wrong.
        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => [
                    'is_active' => 1,
                ] + getEntitiesRestrictCriteria(self::getTable(), 'entities_id', $entities_id, true),
                'ORDER' => ['name'],
            ]) as $row
        ) {
            foreach (explode(',', (string) $row['itemtypes']) as $applies) {
                if (trim($applies) === $itemtype) {
                    $out[] = $row;
                    break;
                }
            }
        }

        return $out;
    }
}

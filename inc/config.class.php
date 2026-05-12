<?php
/**
 * Config class for Quick Ticket Assignment.
 *
 * Two responsibilities:
 *   1. Holds the catalog of available toolbar fields (a static array — the
 *      DB only stores which keys are active and in what order).
 *   2. Wires the Setup-sidebar entry (via getMenuContent + getMenuName)
 *      back to front/config.form.php.
 */

class PluginQuickassignConfig extends CommonGLPI
{
    /**
     * Use core 'config' right (Setup admin) for menu visibility. The form
     * and the save endpoint enforce the same right independently.
     */
    public static $rightname = 'config';

    /**
     * The maximum number of fields the toolbar will show. Effectively no
     * cap — the catalog size IS the cap, since enabling a non-catalog key
     * is rejected by setActiveFieldKeys() anyway. Adding a new field to
     * getCatalog() automatically raises this.
     */
    public static function getMaxFields(): int
    {
        return count(self::getCatalog());
    }

    public static function getTypeName($nb = 0)
    {
        return 'Quick Ticket Assignment';
    }

    /**
     * Sidebar label under Setup.
     */
    public static function getMenuName()
    {
        return self::getTypeName();
    }

    /**
     * Menu wiring for $PLUGIN_HOOKS['menu_toadd'] = ['config' => ...]
     *
     * Returning a non-empty array with 'title' + 'page' inserts an entry
     * under Setup. We gate visibility on config/UPDATE so non-admins
     * don't see it.
     */
    public static function getMenuContent()
    {
        if (!Session::haveRight(static::$rightname, UPDATE)) {
            return false;
        }
        return [
            'title' => self::getMenuName(),
            'page'  => '/plugins/quickassign/front/config.form.php',
            'icon'  => 'ti ti-list-check',
        ];
    }

    // ------------------------------------------------------------------
    // Field catalog
    // ------------------------------------------------------------------

    /**
     * The full set of toolbar fields the plugin knows how to render and
     * apply. Each entry is keyed by the stable identifier used in the DB
     * and on the AJAX wire.
     *
     * Field shape:
     *   label        string   Human-readable name shown in the toolbar
     *   render       string   one of:
     *                           'static'        — server-rendered <option> list
     *                           'ajax_user'     — Select2 + ajax/users.php
     *                           'ajax_dropdown' — Select2 + ajax/<endpoint>
     *                           'ajax_ticket'   — Select2 + ajax/tickets.php
     *                                             (multi-select, auto-clear on pick)
     *                           'text'          — text input + confirm button
     *                           'date'          — datetime-local + confirm button
     *                           'numeric'       — number input + confirm button
     *   options_fn   callable (for static)   returns [['id'=>..,'text'=>..], …]
     *   endpoint     string   (for ajax_*)    AJAX file under /plugins/quickassign/ajax/
     *   actor        string   (for ajax_user) 'assign' | 'requester' | 'observer'
     *   link_type    string   (for ajax_ticket) 'parent' | 'child'
     *   placeholder  string   (for text/date/numeric) hint shown inside the input
     *   step / min   string   (for numeric) HTML <input type=number> attributes
     *   update_field string   Field name passed to update.php
     */
    public static function getCatalog()
    {
        return [
            '_itil_assign' => [
                'label'        => 'Assigned To — Technician',
                'render'       => 'ajax_user',
                'endpoint'     => 'users.php',
                'actor'        => 'assign',
                'update_field' => '_itil_assign',
            ],
            'status' => [
                'label'        => 'Status',
                'render'       => 'static',
                'options_fn'   => [self::class, 'getStatusOptions'],
                'update_field' => 'status',
            ],
            'priority' => [
                'label'        => 'Priority',
                'render'       => 'static',
                'options_fn'   => [self::class, 'getPriorityOptions'],
                'update_field' => 'priority',
            ],
            'urgency' => [
                'label'        => 'Urgency',
                'render'       => 'static',
                'options_fn'   => [self::class, 'getUrgencyOptions'],
                'update_field' => 'urgency',
            ],
            'impact' => [
                'label'        => 'Impact',
                'render'       => 'static',
                'options_fn'   => [self::class, 'getImpactOptions'],
                'update_field' => 'impact',
            ],
            'itilcategories_id' => [
                'label'        => 'Category',
                'render'       => 'ajax_dropdown',
                'endpoint'     => 'categories.php',
                'update_field' => 'itilcategories_id',
            ],
            'pendingreasons_id' => [
                'label'        => 'Pending Reason',
                'render'       => 'ajax_dropdown',
                'endpoint'     => 'pendingreasons.php',
                'update_field' => 'pendingreasons_id',
            ],
            '_itil_requester' => [
                'label'        => 'Requester — Requester',
                'render'       => 'ajax_user',
                'endpoint'     => 'users.php',
                'actor'        => 'requester',
                'update_field' => '_itil_requester',
            ],
            '_itil_observer' => [
                'label'        => 'Observer — Observer',
                'render'       => 'ajax_user',
                'endpoint'     => 'users.php',
                'actor'        => 'observer',
                'update_field' => '_itil_observer',
            ],

            // -------------------- non-dropdown shapes --------------------
            // Confirm-button widgets: typing/picking does NOT fire AJAX.
            // The user clicks the confirm button (or presses Enter inside
            // the input) to commit. See quickassign.js for the wiring.

            'name' => [
                // GLPI calls this "Title" in the UI; the column is `name`.
                'label'        => 'Title',
                'render'       => 'text',
                'placeholder'  => 'New title for selected tickets',
                'update_field' => 'name',
            ],
            'content' => [
                // GLPI calls this "Description" in the UI; the column is
                // `content`.
                //
                // Render shape: multi-line <textarea>. Toolbar instances
                // are an "empty edit-and-commit" box (the same field is
                // applied to every selected ticket, so pre-fill makes no
                // sense). Inline-edit instances pre-fill with the
                // current ticket's description, fetched via the
                // prefill_endpoint below.
                //
                // Caveat: GLPI stores description as HTML. We strip tags
                // to plain text for the textarea, and on commit we
                // convert newlines back to <br> so line breaks display
                // correctly. Other HTML formatting (bold, lists, links)
                // is NOT preserved — use the full ticket form for those.
                'label'            => 'Description',
                'render'           => 'textarea',
                'rows'             => 4,
                'placeholder'      => 'New description for selected tickets',
                'update_field'     => 'content',
                'prefill_endpoint' => 'ticket_content.php',
            ],
            'time_to_resolve' => [
                // Datetime-local input (no seconds — minute precision).
                // Empty value is treated as "clear the SLA target" (NULL).
                'label'        => 'Time to Resolve',
                'render'       => 'date',
                'update_field' => 'time_to_resolve',
            ],
            'cost_fixed' => [
                // GLPI tickets have three editable cost columns (cost_time,
                // cost_fixed, cost_material) and a *computed* "Total cost"
                // search option. There is no editable total column — we
                // write to cost_fixed because it's the simplest single-
                // number target. To target a different column instead,
                // change update_field below AND the matching key in
                // ajax/update.php's $allowed_fields list.
                'label'        => 'Total Cost',
                'render'       => 'numeric',
                'placeholder'  => '0.00',
                'step'         => '0.01',
                'min'          => '0',
                'update_field' => 'cost_fixed',
            ],

            // -------------------- multi-select (ticket links) ------------
            // Each pick adds one Ticket_Ticket row per currently-selected
            // ticket. The Select2 widget self-clears after each commit so
            // the user can chain picks without ever holding stale chips.

            'parent_tickets' => [
                'label'        => 'Linked Tickets — Parent Tickets',
                'render'       => 'ajax_ticket',
                'endpoint'     => 'tickets.php',
                'link_type'    => 'parent',
                'update_field' => 'parent_tickets',
            ],
            'child_tickets' => [
                'label'        => 'Linked Tickets — Child Tickets',
                'render'       => 'ajax_ticket',
                'endpoint'     => 'tickets.php',
                'link_type'    => 'child',
                'update_field' => 'child_tickets',
            ],
        ];
    }

    /**
     * Defaults seeded on install — same four fields the v1.1.x toolbar
     * shipped with, in the same order. hook.php uses this list directly
     * via SQL; this getter exists for the config form's "Reset to defaults"
     * button.
     */
    public static function getDefaultFieldKeys()
    {
        return ['_itil_assign', 'status', 'priority', 'itilcategories_id'];
    }

    // ------------------------------------------------------------------
    // Static option lists (pulled from GLPI core so labels stay translated)
    // ------------------------------------------------------------------

    public static function getStatusOptions()
    {
        $out = [];
        // Ticket::getAllStatusArray() returns [int => label]. We keep the
        // numeric keys so the toolbar posts the same integers GLPI's own
        // forms post.
        foreach (Ticket::getAllStatusArray() as $id => $label) {
            $out[] = ['id' => (int) $id, 'text' => (string) $label];
        }
        return $out;
    }

    public static function getPriorityOptions()
    {
        $out = [];
        // Priority is 1..6 (1=Very Low ... 5=Very High, 6=Major). 0 is
        // "all", which doesn't make sense as a write target.
        for ($i = 1; $i <= 6; $i++) {
            $out[] = ['id' => $i, 'text' => CommonITILObject::getPriorityName($i)];
        }
        return $out;
    }

    public static function getUrgencyOptions()
    {
        $out = [];
        for ($i = 1; $i <= 5; $i++) {
            $out[] = ['id' => $i, 'text' => CommonITILObject::getUrgencyName($i)];
        }
        return $out;
    }

    public static function getImpactOptions()
    {
        $out = [];
        for ($i = 1; $i <= 5; $i++) {
            $out[] = ['id' => $i, 'text' => CommonITILObject::getImpactName($i)];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // DB get/set
    // ------------------------------------------------------------------

    /**
     * Return the active field keys in the order the admin set them. Empty
     * array means the toolbar shouldn't render anything.
     *
     * Filters out keys that no longer exist in the catalog (defensive
     * against a future refactor that removes a field type).
     */
    public static function getActiveFieldKeys()
    {
        global $DB;

        $table = 'glpi_plugin_quickassign_fields';
        if (!$DB->tableExists($table)) {
            return [];
        }

        $catalog = self::getCatalog();
        $keys    = [];

        $query_fn = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';
        $res = $DB->{$query_fn}(
            "SELECT field_key FROM `{$table}` ORDER BY display_order ASC"
        );
        if (!$res) {
            return [];
        }
        while ($row = $DB->fetchAssoc($res)) {
            $key = (string) $row['field_key'];
            if (isset($catalog[$key])) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * Replace the entire active-fields list. Returns [ok, message].
     *
     * Validation:
     *   - count($keys) >= 1   (toolbar would be empty otherwise)
     *   - count($keys) <= getMaxFields()  (i.e. catalog size)
     *   - every key must exist in the catalog
     *   - no duplicates
     */
    public static function setActiveFieldKeys(array $keys)
    {
        global $DB;

        $catalog = self::getCatalog();
        $max     = self::getMaxFields();

        // Normalize: trim, drop empties, de-dupe while preserving order.
        $clean = [];
        foreach ($keys as $k) {
            $k = is_string($k) ? trim($k) : '';
            if ($k === '' || in_array($k, $clean, true)) continue;
            if (!isset($catalog[$k])) {
                return [false, "Unknown field: {$k}"];
            }
            $clean[] = $k;
        }

        if (count($clean) < 1) {
            return [false, 'At least one field must be enabled.'];
        }
        if (count($clean) > $max) {
            return [false, "No more than {$max} fields can be enabled."];
        }

        $table = 'glpi_plugin_quickassign_fields';
        if (!$DB->tableExists($table)) {
            return [false, 'Config table missing — reinstall the plugin.'];
        }

        // Wipe-and-replace inside a transaction so a half-applied save
        // can't leave the table in a state that breaks the UNIQUE on
        // display_order.
        $DB->beginTransaction();
        try {
            $query_fn = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';
            $DB->{$query_fn}("DELETE FROM `{$table}`");
            $order = 1;
            foreach ($clean as $k) {
                $esc = $DB->escape($k);
                $DB->{$query_fn}(
                    "INSERT INTO `{$table}` (`field_key`, `display_order`) "
                    . "VALUES ('{$esc}', {$order})"
                );
                $order++;
            }
            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            error_log('[quickassign] setActiveFieldKeys failed: ' . $e->getMessage());
            return [false, 'Database error while saving config.'];
        }

        return [true, 'Saved.'];
    }

    // ------------------------------------------------------------------
    // Settings (scalar plugin-wide options)
    //
    // Storage: glpi_plugin_quickassign_settings (setting_key TEXT PK,
    // setting_value TEXT). Created in plugin_quickassign_install().
    //
    // Tracked settings:
    //   enable_toolbar (bool, default true)
    //     Master switch for the floating toolbar at the bottom of the
    //     ticket list. When false, no toolbar is rendered. Independent
    //     of enable_inline_edit — admins can mix and match.
    //
    //   enable_inline_edit (bool, default true)
    //     Master switch for click-to-edit on the ticket list cells.
    //     When false, editable cells are NOT decorated and clicks do
    //     not enter edit mode. As a usability fallback the JS still
    //     wires up a "click an empty editable cell to open the ticket"
    //     handler — see setupEmptyCellClickThrough in quickassign.js —
    //     so cells like an empty Assigned-To stay clickable instead of
    //     being inert (which is GLPI's default for cells with no link).
    //
    //   row_background_opens_ticket (bool, default false)
    //     When true, clicking the "empty" area of a ticket row (anywhere
    //     not on a clickable element or inline editor) navigates to the
    //     ticket form. Works regardless of the two toggles above.
    // ------------------------------------------------------------------

    /**
     * Default values for each known setting. Anything fetched via
     * getSetting() that isn't in the DB falls back here.
     *
     * The two feature toggles default to TRUE so that upgrading from a
     * pre-1.5.0 install (no rows in the settings table for these keys)
     * keeps the same behavior the admin already had.
     */
    public static function getSettingDefaults()
    {
        return [
            'enable_toolbar'              => true,
            'enable_inline_edit'          => true,
            'row_background_opens_ticket' => false,
        ];
    }

    /**
     * Read a single setting. Missing or unparsed rows fall back to the
     * default, so callers never have to deal with a NULL.
     *
     * Values are stored as text. We coerce on read using the *type* of
     * the default value: bool defaults round-trip as '0'/'1'.
     */
    public static function getSetting(string $key, $default = null)
    {
        global $DB;

        $defaults = self::getSettingDefaults();
        if ($default === null && array_key_exists($key, $defaults)) {
            $default = $defaults[$key];
        }

        $table = 'glpi_plugin_quickassign_settings';
        if (!$DB->tableExists($table)) {
            return $default;
        }

        $esc      = $DB->escape($key);
        $query_fn = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';
        $res      = $DB->{$query_fn}(
            "SELECT setting_value FROM `{$table}` WHERE setting_key = '{$esc}' LIMIT 1"
        );
        if (!$res) {
            return $default;
        }
        $row = $DB->fetchAssoc($res);
        if (!$row) {
            return $default;
        }
        $raw = $row['setting_value'];
        if ($raw === null) {
            return $default;
        }
        if (is_bool($default)) {
            return ($raw === '1' || $raw === 'true');
        }
        if (is_int($default)) {
            return (int) $raw;
        }
        return (string) $raw;
    }

    /**
     * Upsert a setting. Booleans are persisted as '0'/'1', everything
     * else as the string cast of the value.
     *
     * Returns [ok, message] just like setActiveFieldKeys.
     */
    public static function setSetting(string $key, $value)
    {
        global $DB;

        $defaults = self::getSettingDefaults();
        if (!array_key_exists($key, $defaults)) {
            return [false, "Unknown setting: {$key}"];
        }

        // Coerce to the declared default's type so the persisted shape
        // is consistent — a stray "yes" or "on" gets normalized to "1".
        $declared = $defaults[$key];
        if (is_bool($declared)) {
            $stored = ($value === true || $value === 1 || $value === '1'
                       || $value === 'true' || $value === 'on') ? '1' : '0';
        } elseif (is_int($declared)) {
            $stored = (string) (int) $value;
        } else {
            $stored = (string) $value;
        }

        $table = 'glpi_plugin_quickassign_settings';
        if (!$DB->tableExists($table)) {
            return [false, 'Settings table missing — reinstall the plugin.'];
        }

        $esc_key = $DB->escape($key);
        $esc_val = $DB->escape($stored);

        $query_fn = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';
        // Upsert in a single statement so concurrent saves don't race.
        $sql = "INSERT INTO `{$table}` (`setting_key`, `setting_value`) "
             . "VALUES ('{$esc_key}', '{$esc_val}') "
             . "ON DUPLICATE KEY UPDATE `setting_value` = '{$esc_val}'";
        if (!$DB->{$query_fn}($sql)) {
            return [false, 'Database error while saving setting.'];
        }
        return [true, 'Saved.'];
    }

    /**
     * Read every known setting (with defaults applied) as an associative
     * array. Used by ajax/config.php to ship the state to the JS in one
     * payload alongside the active-fields descriptor.
     */
    public static function getResolvedSettings()
    {
        $out = [];
        foreach (self::getSettingDefaults() as $key => $default) {
            $out[$key] = self::getSetting($key, $default);
        }
        return $out;
    }

    /**
     * Resolve the active fields into the descriptor the toolbar JS needs:
     * label, render type, plus whatever the JS needs to render that shape
     * (inline option list, AJAX endpoint URL, placeholder/min/step, ...).
     * The JS doesn't see anything from the catalog directly — only what
     * we hand it here.
     */
    public static function getResolvedActiveFields()
    {
        $catalog = self::getCatalog();
        $rootDoc = isset($GLOBALS['CFG_GLPI']['root_doc']) ? $GLOBALS['CFG_GLPI']['root_doc'] : '';
        $base    = $rootDoc . '/plugins/quickassign/ajax/';

        $out = [];
        foreach (self::getActiveFieldKeys() as $key) {
            $entry = $catalog[$key];
            $resolved = [
                'key'          => $key,
                'label'        => $entry['label'],
                'render'       => $entry['render'],
                'update_field' => $entry['update_field'],
            ];

            switch ($entry['render']) {
                case 'static':
                    $resolved['options'] = call_user_func($entry['options_fn']);
                    break;

                case 'ajax_user':
                case 'ajax_dropdown':
                case 'ajax_ticket':
                    $resolved['endpoint'] = $base . $entry['endpoint'];
                    if (isset($entry['actor'])) {
                        $resolved['actor'] = $entry['actor'];
                    }
                    if (isset($entry['link_type'])) {
                        $resolved['link_type'] = $entry['link_type'];
                    }
                    break;

                case 'text':
                case 'date':
                case 'numeric':
                    // Optional UI hints — JS treats them as plain attrs.
                    foreach (['placeholder', 'step', 'min', 'max'] as $hint) {
                        if (isset($entry[$hint])) {
                            $resolved[$hint] = $entry[$hint];
                        }
                    }
                    break;

                case 'textarea':
                    // Same hints as 'text' plus a row count and an
                    // optional prefill_endpoint that inline-edit calls
                    // to populate the textarea with the current value.
                    foreach (['placeholder', 'rows'] as $hint) {
                        if (isset($entry[$hint])) {
                            $resolved[$hint] = $entry[$hint];
                        }
                    }
                    if (isset($entry['prefill_endpoint'])) {
                        $resolved['prefill_endpoint'] = $base . $entry['prefill_endpoint'];
                    }
                    break;
            }

            $out[] = $resolved;
        }
        return $out;
    }
}

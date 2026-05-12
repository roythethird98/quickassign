<?php
/**
 * Install/uninstall hooks.
 *
 * The plugin owns two tables:
 *   - glpi_plugin_quickassign_fields    (which toolbar fields are active)
 *   - glpi_plugin_quickassign_settings  (key/value scalar settings — added
 *                                        in v1.4.0 for inline editing)
 */

/**
 * Create both tables (if missing) and seed the fields table with the
 * legacy four-field default. Idempotent: an upgrade from a previous
 * version that already has the fields table will leave it untouched and
 * just create the settings table on the side.
 */
function plugin_quickassign_install()
{
    global $DB;

    $query_fn = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';

    // ------------------------------------------------------------------
    // Fields table — picked toolbar fields and their order
    // ------------------------------------------------------------------
    $fields_table = 'glpi_plugin_quickassign_fields';
    if (!$DB->tableExists($fields_table)) {
        $sql = "CREATE TABLE `{$fields_table}` (
                    `id`            INT NOT NULL AUTO_INCREMENT,
                    `field_key`     VARCHAR(64) NOT NULL,
                    `display_order` INT NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `field_key`     (`field_key`),
                    UNIQUE KEY `display_order` (`display_order`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$DB->{$query_fn}($sql)) {
            return false;
        }

        // Seed defaults — same fields and order as v1.1.1's hardcoded toolbar.
        $defaults = [
            '_itil_assign'      => 1,
            'status'            => 2,
            'priority'          => 3,
            'itilcategories_id' => 4,
        ];
        foreach ($defaults as $key => $order) {
            $DB->{$query_fn}(
                "INSERT INTO `{$fields_table}` (`field_key`, `display_order`) "
                . "VALUES ('" . $DB->escape($key) . "', " . (int) $order . ")"
            );
        }
    }

    // ------------------------------------------------------------------
    // Settings table — scalar plugin-wide options
    //
    // Created on first install AND when an existing pre-1.4.0 install
    // upgrades. The tableExists check keeps both paths idempotent.
    // ------------------------------------------------------------------
    $settings_table = 'glpi_plugin_quickassign_settings';
    if (!$DB->tableExists($settings_table)) {
        $sql = "CREATE TABLE `{$settings_table}` (
                    `setting_key`   VARCHAR(64) NOT NULL,
                    `setting_value` TEXT NULL,
                    PRIMARY KEY (`setting_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$DB->{$query_fn}($sql)) {
            return false;
        }
    }

    return true;
}

/**
 * Drop both plugin tables. The user's selections are intentionally lost
 * on uninstall — install reseeds defaults from scratch.
 */
function plugin_quickassign_uninstall()
{
    global $DB;

    $query_fn = method_exists($DB, 'doQuery') ? 'doQuery' : 'query';
    foreach (['glpi_plugin_quickassign_fields', 'glpi_plugin_quickassign_settings'] as $t) {
        if ($DB->tableExists($t)) {
            $DB->{$query_fn}("DROP TABLE `{$t}`");
        }
    }

    return true;
}

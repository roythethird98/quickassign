<?php
/**
 * AJAX endpoint: returns the toolbar's active field descriptor as JSON.
 *
 * Called once by quickassign.js on init. Output shape:
 *   {
 *     "fields": [
 *       { "key": "_itil_assign", "label": "...", "render": "ajax_user",
 *         "endpoint": "...", "actor": "assign", "update_field": "_itil_assign" },
 *       { "key": "status", "label": "...", "render": "static",
 *         "options": [{ "id": 1, "text": "New" }, ...],
 *         "update_field": "status" },
 *       ...
 *     ],
 *     "settings": {
 *       "row_background_opens_ticket": false
 *     }
 *   }
 *
 * Gated on the same right as the toolbar update endpoint (ticket/UPDATE)
 * — this endpoint exposes the field labels and option lists, all of which
 * are visible to anyone who can already update tickets.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
header('Content-Type: application/json');

if (!Session::haveRight('ticket', UPDATE)) {
    http_response_code(403);
    echo json_encode(['error' => 'No update permission', 'fields' => [], 'settings' => []]);
    exit;
}

try {
    $fields   = PluginQuickassignConfig::getResolvedActiveFields();
    $settings = PluginQuickassignConfig::getResolvedSettings();
    echo json_encode(['fields' => $fields, 'settings' => $settings]);
} catch (\Throwable $e) {
    error_log('[quickassign config.php] ' . $e->getMessage()
              . ' at ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load config', 'fields' => [], 'settings' => []]);
}

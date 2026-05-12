<?php
/**
 * AJAX endpoint: persists the active-fields list and/or scalar settings.
 *
 * POST params:
 *   action       : 'save' | 'reset'      (default 'save')
 *   ordered_keys : comma-joined list of field keys, in the order the admin
 *                  chose. Required for action=save; ignored for reset.
 *   setting_<k>  : value for setting key <k>. Sent as one POST field per
 *                  setting; missing fields are left untouched. For reset,
 *                  these are ignored and all settings revert to defaults.
 *
 * Lives under /ajax/, which GLPI's CSRF middleware exempts from
 * automatic token validation. Same-origin is enforced by the browser
 * and the session-cookie requirement; rights are enforced explicitly
 * below (config / UPDATE — Setup admin).
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
header('Content-Type: application/json');

if (!Session::haveRight('config', UPDATE)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No config permission']);
    exit;
}

$action = (string) ($_POST['action'] ?? 'save');

try {
    if ($action === 'reset') {
        // Reset both fields AND settings — gives the admin one button to
        // restore the plugin's out-of-the-box state.
        [$ok, $msg] = PluginQuickassignConfig::setActiveFieldKeys(
            PluginQuickassignConfig::getDefaultFieldKeys()
        );
        if ($ok) {
            foreach (PluginQuickassignConfig::getSettingDefaults() as $key => $default) {
                PluginQuickassignConfig::setSetting($key, $default);
            }
        }
    } else {
        // 'save' — parse the comma-joined keys list and pass through to
        // setActiveFieldKeys, which does the 1..MAX validation and the
        // wipe-and-replace transaction.
        $ordered = $_POST['ordered_keys'] ?? '';
        $keys    = [];
        if (is_string($ordered) && $ordered !== '') {
            $keys = array_values(array_filter(
                array_map('trim', explode(',', $ordered)),
                function ($v) { return $v !== ''; }
            ));
        }
        [$ok, $msg] = PluginQuickassignConfig::setActiveFieldKeys($keys);

        // Persist any setting_<key> POST fields. We accept partial saves
        // (missing keys keep their existing value) so the form can grow
        // without rev-locking the JS to the PHP.
        if ($ok) {
            foreach (PluginQuickassignConfig::getSettingDefaults() as $skey => $default) {
                $form_key = 'setting_' . $skey;
                if (array_key_exists($form_key, $_POST)) {
                    [$sok, $smsg] = PluginQuickassignConfig::setSetting(
                        $skey, $_POST[$form_key]
                    );
                    if (!$sok) {
                        // A bad setting value shouldn't roll back the
                        // fields list — surface the problem and stop.
                        http_response_code(400);
                        echo json_encode(['ok' => false, 'error' => $smsg]);
                        exit;
                    }
                }
            }
        }
    }

    if (!$ok) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    }

    echo json_encode(['ok' => true, 'message' => $msg]);
} catch (\Throwable $e) {
    error_log('[quickassign config_save.php] ' . $e->getMessage()
              . ' at ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal error']);
}

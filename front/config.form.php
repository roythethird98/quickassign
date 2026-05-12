<?php
/**
 * Config page for Quick Ticket Assignment.
 *
 * Reachable from two routes that both arrive here:
 *   - Setup > Plugins > Installed > Quick Ticket Assignment > Configure
 *     (registered via $PLUGIN_HOOKS['config_page'] in setup.php)
 *   - Setup > Quick Ticket Assignment
 *     (registered via $PLUGIN_HOOKS['menu_toadd'] + getMenuContent())
 *
 * Save flow: NOT a native form POST. GLPI 11's kernel-level CSRF listener
 * does not exempt /plugins/<name>/ajax/ paths the way the legacy GLPI 10
 * includes.php check did, so the JS sends a manual fetch() to
 * ajax/config_save.php with the CSRF token in both the POST body
 * (_glpi_csrf_token) and the X-Glpi-Csrf-Token header. Either is enough
 * to satisfy the listener.
 */

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

$page_url      = Plugin::getWebDir('quickassign') . '/front/config.form.php';
$ajax_save_url = Plugin::getWebDir('quickassign') . '/ajax/config_save.php';
$csrf_token    = Session::getNewCSRFToken();

Html::header(
    PluginQuickassignConfig::getTypeName(),
    $page_url,
    'config',
    'PluginQuickassignConfig'
);

$catalog        = PluginQuickassignConfig::getCatalog();
$active_keys    = PluginQuickassignConfig::getActiveFieldKeys();
$max_fields     = PluginQuickassignConfig::getMaxFields();
$toolbar_setting     = PluginQuickassignConfig::getSetting('enable_toolbar', true);
$inline_edit_setting = PluginQuickassignConfig::getSetting('enable_inline_edit', true);
$row_bg_setting      = PluginQuickassignConfig::getSetting('row_background_opens_ticket', false);

// Disabled keys come after the active ones, in catalog order, so admins
// can scan the full list with active picks at the top.
$disabled_keys = array_values(array_diff(array_keys($catalog), $active_keys));
$ordered_keys  = array_merge($active_keys, $disabled_keys);

echo '<div class="qa-config-page">';

echo '<div class="card qa-config-card">';
echo '<div class="card-header"><h3 class="card-title">' . htmlspecialchars(PluginQuickassignConfig::getTypeName()) . '</h3></div>';
echo '<div class="card-body">';

echo '<p class="text-muted">'
    . 'Pick the fields shown in the floating toolbar on Assistance &gt; Tickets, '
    . 'and use the arrow buttons to set their order. At least one field must '
    . 'be enabled. When inline editing is on (see below), the same set of fields '
    . 'is also editable directly in the ticket list by clicking the matching cell.'
    . '</p>';

echo '<div id="qa-flash" class="alert" style="display:none;" role="alert"></div>';

echo '<div class="qa-counter mb-2">'
    . '<span id="qa-active-count">' . count($active_keys) . '</span> of '
    . (int) $max_fields . ' enabled'
    . '</div>';

echo '<ul id="qa-field-list" class="list-group qa-field-list" data-max="' . (int) $max_fields . '">';

foreach ($ordered_keys as $key) {
    $entry      = $catalog[$key];
    $is_active  = in_array($key, $active_keys, true);
    $row_class  = 'list-group-item qa-row' . ($is_active ? ' qa-row-active' : '');

    echo '<li class="' . $row_class . '" data-key="' . htmlspecialchars($key) . '">';
    echo '<div class="qa-row-inner">';
    echo '<div class="qa-arrows">';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary qa-up" title="Move up">&#9650;</button>';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary qa-down" title="Move down">&#9660;</button>';
    echo '</div>';
    echo '<label class="qa-row-label">';
    echo '<input type="checkbox" class="qa-enabled form-check-input me-2"'
        . ($is_active ? ' checked' : '')
        . ' data-key="' . htmlspecialchars($key) . '">';
    echo '<span class="qa-row-name">' . htmlspecialchars($entry['label']) . '</span>';
    echo '<code class="qa-row-key text-muted ms-2">' . htmlspecialchars($key) . '</code>';
    echo '</label>';
    echo '</div>';
    echo '</li>';
}

echo '</ul>';

// ---------------------------------------------------------------------
// Feature toggles — let the admin turn the toolbar and/or the inline-
// edit feature on or off independently. Both default to on; off+off
// is also valid (the toolbar disappears, cells stop entering edit mode,
// but empty editable cells still navigate to the ticket on click —
// see setupEmptyCellClickThrough in public/js/quickassign.js).
// ---------------------------------------------------------------------
echo '<hr class="qa-settings-sep">';
echo '<h5 class="qa-settings-heading">Floating toolbar</h5>';

echo '<div class="qa-setting-row form-check">';
echo '<input type="checkbox" class="form-check-input qa-setting"'
    . ' id="qa-setting-toolbar" data-setting-key="enable_toolbar"'
    . ($toolbar_setting ? ' checked' : '')
    . '>';
echo '<label for="qa-setting-toolbar" class="form-check-label">';
echo 'Show the floating toolbar when tickets are selected';
echo '</label>';
echo '<div class="qa-setting-help text-muted">'
    . 'The toolbar appears at the bottom of the screen on the ticket list '
    . 'with one quick-edit dropdown per enabled field. Default: on.'
    . '</div>';
echo '</div>';

// ---------------------------------------------------------------------
// Inline-edit settings — controls click-to-edit behavior on the ticket
// list itself, plus the row-background click-through option that
// piggybacks on the same handler infrastructure.
// ---------------------------------------------------------------------
echo '<hr class="qa-settings-sep">';
echo '<h5 class="qa-settings-heading">Inline editing</h5>';

echo '<div class="qa-setting-row form-check">';
echo '<input type="checkbox" class="form-check-input qa-setting"'
    . ' id="qa-setting-inline-edit" data-setting-key="enable_inline_edit"'
    . ($inline_edit_setting ? ' checked' : '')
    . '>';
echo '<label for="qa-setting-inline-edit" class="form-check-label">';
echo 'Edit ticket fields directly from the list view';
echo '</label>';
echo '<div class="qa-setting-help text-muted">'
    . 'When on, clicking an editable cell in the ticket list opens a small '
    . 'inline editor for that field. When off, clicking an empty editable '
    . 'cell opens the ticket instead (cells with a link already navigate '
    . 'on their own). Default: on.'
    . '</div>';
echo '</div>';

echo '<div class="qa-setting-row form-check">';
echo '<input type="checkbox" class="form-check-input qa-setting"'
    . ' id="qa-setting-row-bg" data-setting-key="row_background_opens_ticket"'
    . ($row_bg_setting ? ' checked' : '')
    . '>';
echo '<label for="qa-setting-row-bg" class="form-check-label">';
echo 'Open ticket when clicking the empty area of a row';
echo '</label>';
echo '<div class="qa-setting-help text-muted">'
    . 'When on, clicking anywhere on a ticket row that isn\'t a clickable cell '
    . '(no editor, no link, no button) opens the ticket. If inline editing is '
    . 'also on, editable cells still enter inline-edit mode on click. Default: off.'
    . '</div>';
echo '</div>';

echo '<div class="qa-actions mt-3">';
echo '<button type="button" id="qa-save-btn" class="btn btn-primary">Save</button>';
echo ' ';
echo '<button type="button" id="qa-reset-btn" class="btn btn-outline-secondary">Reset to defaults</button>';
echo '</div>';

echo '</div></div></div>'; // card-body, card, qa-config-page

$js_save_url   = json_encode($ajax_save_url);
$js_csrf_token = json_encode($csrf_token);
?>
<script>
(function () {
    var SAVE_URL   = <?php echo $js_save_url; ?>;
    var CSRF_TOKEN = <?php echo $js_csrf_token; ?>;

    var list    = document.getElementById('qa-field-list');
    var counter = document.getElementById('qa-active-count');
    var saveBtn = document.getElementById('qa-save-btn');
    var resetBtn= document.getElementById('qa-reset-btn');
    var flash   = document.getElementById('qa-flash');

    if (!list || !saveBtn || !resetBtn || !flash || !counter) {
        console.error('[quickassign] config page missing expected DOM elements');
        return;
    }

    var max = parseInt(list.getAttribute('data-max'), 10) || 1;

    function rows() {
        return Array.prototype.slice.call(list.querySelectorAll('li.qa-row'));
    }

    function getOrderedCheckedKeys() {
        var keys = [];
        rows().forEach(function (row) {
            var cb = row.querySelector('.qa-enabled');
            if (cb && cb.checked) keys.push(row.getAttribute('data-key'));
        });
        return keys;
    }

    function showFlash(type, msg) {
        flash.className = 'alert alert-' + type;
        flash.textContent = msg;
        flash.style.display = '';
        if (type === 'success') {
            setTimeout(function () { flash.style.display = 'none'; }, 2500);
        }
    }

    function updateCount() {
        var keys = getOrderedCheckedKeys();
        counter.textContent = keys.length;
        rows().forEach(function (row) {
            var cb = row.querySelector('.qa-enabled');
            row.classList.toggle('qa-row-active', !!(cb && cb.checked));
        });
        // Floor: at least one must be enabled. Ceiling is the catalog
        // size, which the rendered list naturally enforces — but we
        // still check for defense in depth.
        var ok = keys.length >= 1 && keys.length <= max;
        saveBtn.disabled = !ok;
        counter.classList.toggle('text-danger', !ok);
    }

    // Up/down: swap the row with its sibling. Reordering disabled rows
    // is allowed (lets admins pre-stage order before checking them).
    list.addEventListener('click', function (e) {
        var btn = e.target.closest('.qa-up, .qa-down');
        if (!btn) return;
        var row = btn.closest('li.qa-row');
        if (!row) return;
        if (btn.classList.contains('qa-up')) {
            var prev = row.previousElementSibling;
            if (prev) list.insertBefore(row, prev);
        } else {
            var next = row.nextElementSibling;
            if (next) list.insertBefore(next, row);
        }
        updateCount();
    });

    list.addEventListener('change', function (e) {
        if (e.target && e.target.classList.contains('qa-enabled')) updateCount();
    });

    // Send the CSRF token in BOTH the POST body (_glpi_csrf_token, what
    // Session::checkCSRF() reads) and the X-Glpi-Csrf-Token header (what
    // GLPI's own AJAX layer sends). Whichever the kernel listener checks,
    // the request looks valid.
    function postForm(data) {
        var payload = {};
        Object.keys(data).forEach(function (k) { payload[k] = data[k]; });
        payload._glpi_csrf_token = CSRF_TOKEN;

        var body = Object.keys(payload)
            .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(payload[k]); })
            .join('&');

        return fetch(SAVE_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': CSRF_TOKEN
            },
            body: body
        }).then(function (r) {
            return r.text().then(function (text) {
                var json = null;
                try { json = JSON.parse(text); } catch (e) {}
                return { status: r.status, json: json, text: text };
            });
        });
    }

    saveBtn.addEventListener('click', function () {
        var keys = getOrderedCheckedKeys();
        if (keys.length < 1 || keys.length > max) return;
        saveBtn.disabled = true;
        showFlash('info', 'Saving…');

        // Build the POST body: ordered_keys + one setting_<key>=0|1 field
        // per checkbox in the settings section. The endpoint accepts
        // partial setting payloads (missing keys keep their existing
        // value) so we just send what's on the page.
        var payload = {
            action:       'save',
            ordered_keys: keys.join(',')
        };
        var settingNodes = document.querySelectorAll('.qa-setting');
        for (var i = 0; i < settingNodes.length; i++) {
            var node = settingNodes[i];
            var k    = node.getAttribute('data-setting-key');
            if (!k) continue;
            if (node.type === 'checkbox') {
                payload['setting_' + k] = node.checked ? '1' : '0';
            } else {
                payload['setting_' + k] = node.value;
            }
        }

        postForm(payload)
            .then(function (res) {
                if (res.json && res.json.ok) {
                    showFlash('success', 'Saved.');
                    setTimeout(function () { window.location.reload(); }, 600);
                } else {
                    var err = (res.json && res.json.error) ? res.json.error
                            : 'Save failed (HTTP ' + res.status + ').';
                    if (res.status !== 200) {
                        console.error('[quickassign] save failed:', res.status, res.text);
                    }
                    showFlash('danger', err);
                    saveBtn.disabled = false;
                }
            })
            .catch(function (e) {
                console.error('[quickassign] save threw:', e);
                showFlash('danger', 'Request failed: ' + (e && e.message ? e.message : e));
                saveBtn.disabled = false;
            });
    });

    resetBtn.addEventListener('click', function () {
        if (!confirm('Reset to the default four fields?')) return;
        resetBtn.disabled = true;
        postForm({ action: 'reset' })
            .then(function (res) {
                if (res.json && res.json.ok) {
                    showFlash('success', 'Reset to defaults.');
                    setTimeout(function () { window.location.reload(); }, 600);
                } else {
                    var err = (res.json && res.json.error) ? res.json.error
                            : 'Reset failed (HTTP ' + res.status + ').';
                    if (res.status !== 200) {
                        console.error('[quickassign] reset failed:', res.status, res.text);
                    }
                    showFlash('danger', err);
                    resetBtn.disabled = false;
                }
            })
            .catch(function (e) {
                console.error('[quickassign] reset threw:', e);
                showFlash('danger', 'Request failed: ' + (e && e.message ? e.message : e));
                resetBtn.disabled = false;
            });
    });

    updateCount();
})();
</script>
<?php

Html::footer();

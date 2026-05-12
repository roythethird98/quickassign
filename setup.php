<?php
/**
 * Quick Ticket Assignment - GLPI Plugin
 *
 * Adds a floating toolbar to the Tickets list page with quick-edit dropdowns.
 * The set of dropdowns is configurable from Setup > Quick Ticket Assignment
 * (or the wrench icon next to the plugin in Setup > Plugins).
 */

define('PLUGIN_QUICKASSIGN_VERSION', '1.5.0');
define('PLUGIN_QUICKASSIGN_MIN_GLPI', '11.0.0');
define('PLUGIN_QUICKASSIGN_MAX_GLPI', '11.9.99');

/**
 * Init hooks. Called on every GLPI page load.
 */
function plugin_init_quickassign()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['quickassign'] = true;

    // The Setup sidebar entry (Setup > Quick Ticket Assignment) is wired
    // via menu_toadd. The class's static getMenuContent() returns the link
    // target — see inc/config.class.php.
    $PLUGIN_HOOKS['menu_toadd']['quickassign'] = [
        'config' => 'PluginQuickassignConfig',
    ];

    // The wrench icon in Setup > Plugins > Installed.
    $PLUGIN_HOOKS['config_page']['quickassign'] = 'front/config.form.php';

    // Only inject toolbar assets for logged-in users
    if (Session::getLoginUserID()) {
        $PLUGIN_HOOKS['add_javascript']['quickassign'] = 'js/quickassign.js';
        $PLUGIN_HOOKS['add_css']['quickassign']        = 'css/quickassign.css';
    }
}

/**
 * Plugin metadata. Shown in Setup > Plugins.
 */
function plugin_version_quickassign()
{
    return [
        'name'         => 'Quick Ticket Assignment',
        'version'      => PLUGIN_QUICKASSIGN_VERSION,
        'author'       => 'Custom',
        'license'      => 'GPLv3',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_QUICKASSIGN_MIN_GLPI,
                'max' => PLUGIN_QUICKASSIGN_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_quickassign_check_prerequisites()
{
    if (version_compare(GLPI_VERSION, PLUGIN_QUICKASSIGN_MIN_GLPI, 'lt')) {
        echo 'This plugin requires GLPI >= ' . PLUGIN_QUICKASSIGN_MIN_GLPI;
        return false;
    }
    return true;
}

function plugin_quickassign_check_config($verbose = false)
{
    return true;
}

<?php

declare(strict_types=1);

/**
 * Substack Sync - WordPress Plugin
 *
 * Copyright (c) 2025 Christopher S. Penn
 * Licensed under Apache License Version 2.0
 *
 * NO SUPPORT PROVIDED. USE AT YOUR OWN RISK.
 */

// If uninstall not called from WordPress, then exit.
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Remove this plugin's data from the current site, if the operator opted in.
 */
function substack_sync_uninstall_current_site(): void
{
    $options = get_option('substack_sync_settings');

    if (empty($options['delete_data_on_uninstall'])) {
        return;
    }

    // Delete plugin settings
    delete_option('substack_sync_settings');

    // Drop the custom database table (identifier is prefix-derived, not user input)
    global $wpdb;
    $table_name = $wpdb->prefix . 'substack_sync_log';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
}

// On multisite, options and the log table are per-site; clean up every site.
if (is_multisite()) {
    $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ($site_ids as $site_id) {
        switch_to_blog((int) $site_id);
        substack_sync_uninstall_current_site();
        restore_current_blog();
    }
} else {
    substack_sync_uninstall_current_site();
}

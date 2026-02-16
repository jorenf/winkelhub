<?php
/**
 * LogicTrade Sync — Uninstall handler.
 *
 * Removes all plugin data: options, database tables, cron events, and post meta.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// Remove options.
delete_option('logictrade_api_key');
delete_option('logictrade_salesman_username');
delete_option('logictrade_delivery_type_code');
delete_option('logictrade_sync_version');

// Drop custom tables.
global $wpdb;

$tables = [
    $wpdb->prefix . 'logictrade_sync_log',
    $wpdb->prefix . 'logictrade_category_mapping',
    $wpdb->prefix . 'logictrade_sync_state',
    $wpdb->prefix . 'logictrade_order_mapping',
];

foreach ($tables as $table) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// Remove post meta created by the plugin.
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (
    '_logictrade_code',
    '_logictrade_article_number',
    '_logictrade_supplier',
    '_logictrade_order_id',
    '_logictrade_order_exported',
    '_purchase_price'
)");

// Clear cron events.
wp_clear_scheduled_hook('logictrade_sync_cron');
wp_clear_scheduled_hook('logictrade_purge_logs');

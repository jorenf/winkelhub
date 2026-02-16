<?php

declare(strict_types=1);

namespace LogicTradeSync\Database;

/**
 * Creates and updates all custom database tables.
 */
final class Migrator
{
    public function up(): void
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $sql = [];

        // Sync log — records every sync run and individual operations.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}logictrade_sync_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(50) NOT NULL DEFAULT 'product_sync',
            status VARCHAR(20) NOT NULL DEFAULT 'info',
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_type_status (type, status),
            KEY idx_created_at (created_at)
        ) {$charset};";

        // Category mapping — LogicTrade group → WooCommerce category.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}logictrade_category_mapping (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            logictrade_group_id BIGINT UNSIGNED NOT NULL,
            logictrade_group_name VARCHAR(255) NOT NULL DEFAULT '',
            wc_category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_lt_group (logictrade_group_id),
            KEY idx_wc_cat (wc_category_id)
        ) {$charset};";

        // Sync state — stores last sync time, lock info, etc.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}logictrade_sync_state (
            state_key VARCHAR(100) NOT NULL,
            state_value LONGTEXT NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (state_key)
        ) {$charset};";

        // Order mapping — WooCommerce order → LogicTrade order.
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}logictrade_order_mapping (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wc_order_id BIGINT UNSIGNED NOT NULL,
            logictrade_order_id VARCHAR(255) NOT NULL DEFAULT '',
            exported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(20) NOT NULL DEFAULT 'success',
            error_message TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_wc_order (wc_order_id),
            KEY idx_lt_order (logictrade_order_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ($sql as $query) {
            dbDelta($query);
        }
    }

    /**
     * Drop all custom tables — used on uninstall.
     */
    public function down(): void
    {
        global $wpdb;

        $tables = [
            "{$wpdb->prefix}logictrade_sync_log",
            "{$wpdb->prefix}logictrade_category_mapping",
            "{$wpdb->prefix}logictrade_sync_state",
            "{$wpdb->prefix}logictrade_order_mapping",
        ];

        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
    }
}

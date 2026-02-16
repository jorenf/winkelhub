<?php

declare(strict_types=1);

namespace LogicTradeSync\Database;

/**
 * Tracks WooCommerce orders that have been exported to LogicTrade.
 */
final class OrderMappingRepository
{
    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'logictrade_order_mapping';
    }

    /**
     * Check whether an order has already been exported.
     */
    public function isExported(int $wcOrderId): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE wc_order_id = %d AND status = 'success'",
            $wcOrderId
        ));
    }

    /**
     * Record a successful export.
     */
    public function recordSuccess(int $wcOrderId, string $logictradeOrderId): void
    {
        global $wpdb;

        $wpdb->replace($this->table(), [
            'wc_order_id'          => $wcOrderId,
            'logictrade_order_id'  => $logictradeOrderId,
            'exported_at'          => current_time('mysql'),
            'status'               => 'success',
            'error_message'        => null,
        ], ['%d', '%s', '%s', '%s', '%s']);
    }

    /**
     * Record a failed export attempt.
     */
    public function recordFailure(int $wcOrderId, string $errorMessage): void
    {
        global $wpdb;

        $wpdb->replace($this->table(), [
            'wc_order_id'          => $wcOrderId,
            'logictrade_order_id'  => '',
            'exported_at'          => current_time('mysql'),
            'status'               => 'failed',
            'error_message'        => $errorMessage,
        ], ['%d', '%s', '%s', '%s', '%s']);
    }

    /**
     * Get export record for an order.
     */
    public function getByOrderId(int $wcOrderId): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE wc_order_id = %d",
            $wcOrderId
        ));
    }

    /**
     * Count successfully exported orders.
     */
    public function countExported(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table()} WHERE status = 'success'");
    }

    /**
     * Get recent exports.
     *
     * @return array<int, object>
     */
    public function getRecent(int $limit = 20): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} ORDER BY exported_at DESC LIMIT %d",
            $limit
        ));
    }
}

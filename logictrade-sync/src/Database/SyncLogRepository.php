<?php

declare(strict_types=1);

namespace LogicTradeSync\Database;

/**
 * Query helper for the logictrade_sync_log table.
 */
final class SyncLogRepository
{
    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'logictrade_sync_log';
    }

    /**
     * Fetch recent log entries.
     *
     * @return array<int, object>
     */
    public function getRecent(int $limit = 50, int $offset = 0, ?string $type = null, ?string $status = null): array
    {
        global $wpdb;

        $where  = '1=1';
        $params = [];

        if ($type) {
            $where   .= ' AND type = %s';
            $params[] = $type;
        }
        if ($status) {
            $where   .= ' AND status = %s';
            $params[] = $status;
        }

        $params[] = $limit;
        $params[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            ...$params
        );

        return $wpdb->get_results($sql);
    }

    /**
     * Count log entries matching filters.
     */
    public function count(?string $type = null, ?string $status = null): int
    {
        global $wpdb;

        $where  = '1=1';
        $params = [];

        if ($type) {
            $where   .= ' AND type = %s';
            $params[] = $type;
        }
        if ($status) {
            $where   .= ' AND status = %s';
            $params[] = $status;
        }

        if ($params) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$this->table()} WHERE {$where}", ...$params);
        } else {
            $sql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$where}";
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Count errors since a given datetime.
     */
    public function countErrorsSince(string $since): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE status = 'error' AND created_at >= %s",
            $since
        ));
    }

    /**
     * Purge entries older than given number of days.
     */
    public function purgeOlderThan(int $days = 30): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }
}

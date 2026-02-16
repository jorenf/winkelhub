<?php

declare(strict_types=1);

namespace LogicTradeSync\Utils;

/**
 * Lightweight logger that persists entries to the logictrade_sync_log table.
 */
final class Logger
{
    /**
     * Log an informational message.
     */
    public function info(string $message, string $type = 'general', ?array $context = null): void
    {
        $this->write('info', $message, $type, $context);
    }

    /**
     * Log a success message.
     */
    public function success(string $message, string $type = 'general', ?array $context = null): void
    {
        $this->write('success', $message, $type, $context);
    }

    /**
     * Log a warning.
     */
    public function warning(string $message, string $type = 'general', ?array $context = null): void
    {
        $this->write('warning', $message, $type, $context);
    }

    /**
     * Log an error.
     */
    public function error(string $message, string $type = 'general', ?array $context = null): void
    {
        $this->write('error', $message, $type, $context);
    }

    /**
     * Persist a log entry.
     */
    private function write(string $status, string $message, string $type, ?array $context): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'logictrade_sync_log';

        $wpdb->insert($table, [
            'type'       => $type,
            'status'     => $status,
            'message'    => $message,
            'context'    => $context ? wp_json_encode($context) : null,
            'created_at' => current_time('mysql'),
        ], ['%s', '%s', '%s', '%s', '%s']);
    }
}

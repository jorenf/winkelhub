<?php

declare(strict_types=1);

namespace LogicTradeSync\Database;

/**
 * Key-value store for sync state (last sync, lock, etc.).
 */
final class SyncStateRepository
{
    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'logictrade_sync_state';
    }

    public function get(string $key, string $default = ''): string
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT state_value FROM {$this->table()} WHERE state_key = %s",
            $key
        ));

        return $value !== null ? $value : $default;
    }

    public function set(string $key, string $value): void
    {
        global $wpdb;

        $wpdb->replace($this->table(), [
            'state_key'   => $key,
            'state_value' => $value,
            'updated_at'  => current_time('mysql'),
        ], ['%s', '%s', '%s']);
    }

    public function delete(string $key): void
    {
        global $wpdb;

        $wpdb->delete($this->table(), ['state_key' => $key], ['%s']);
    }

    // ------------------------------------------------------------------
    // Convenience methods
    // ------------------------------------------------------------------

    public function getLastSyncTime(): ?string
    {
        $val = $this->get('last_sync_time');
        return $val ?: null;
    }

    public function setLastSyncTime(string $datetime): void
    {
        $this->set('last_sync_time', $datetime);
    }

    public function getLastSyncProductCount(): int
    {
        return (int) $this->get('last_sync_product_count', '0');
    }

    public function setLastSyncProductCount(int $count): void
    {
        $this->set('last_sync_product_count', (string) $count);
    }

    // ------------------------------------------------------------------
    // Lock mechanism to prevent concurrent syncs.
    // ------------------------------------------------------------------

    /**
     * Attempt to acquire sync lock. Returns true on success.
     */
    public function acquireLock(int $ttlSeconds = 3600): bool
    {
        $lock = $this->get('sync_lock');

        if ($lock) {
            $lockTime = (int) $lock;
            if (time() - $lockTime < $ttlSeconds) {
                return false; // Still locked.
            }
        }

        $this->set('sync_lock', (string) time());
        return true;
    }

    /**
     * Release the sync lock.
     */
    public function releaseLock(): void
    {
        $this->delete('sync_lock');
    }

    /**
     * Check whether the cooldown period has passed.
     */
    public function isCooldownActive(int $cooldownSeconds = 21600): bool
    {
        $lastSync = $this->getLastSyncTime();
        if (!$lastSync) {
            return false;
        }

        return (time() - strtotime($lastSync)) < $cooldownSeconds;
    }
}

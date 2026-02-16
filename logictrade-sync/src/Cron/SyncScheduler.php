<?php

declare(strict_types=1);

namespace LogicTradeSync\Cron;

use LogicTradeSync\Database\SyncStateRepository;
use LogicTradeSync\Sync\ProductSync;
use LogicTradeSync\Utils\Logger;

/**
 * Registers WP-Cron events for nightly product sync.
 */
final class SyncScheduler
{
    private ProductSync $productSync;
    private SyncStateRepository $syncStateRepo;
    private Logger $logger;

    public function __construct(
        ProductSync $productSync,
        SyncStateRepository $syncStateRepo,
        Logger $logger
    ) {
        $this->productSync  = $productSync;
        $this->syncStateRepo = $syncStateRepo;
        $this->logger        = $logger;
    }

    /**
     * Register WP-Cron hook and ensure schedule exists.
     */
    public function register(): void
    {
        add_action('logictrade_sync_cron', [$this, 'execute']);

        // Purge old logs weekly.
        add_action('logictrade_purge_logs', [$this, 'purgeLogs']);

        if (!wp_next_scheduled('logictrade_purge_logs')) {
            wp_schedule_event(time(), 'weekly', 'logictrade_purge_logs');
        }
    }

    /**
     * Cron callback — runs the product sync respecting cooldown.
     */
    public function execute(): void
    {
        // 6-hour cooldown.
        if ($this->syncStateRepo->isCooldownActive()) {
            $this->logger->info('Cron sync skipped — cooldown active.', 'cron');
            return;
        }

        $this->logger->info('Cron product sync started.', 'cron');
        $this->productSync->run();
    }

    /**
     * Purge log entries older than 30 days.
     */
    public function purgeLogs(): void
    {
        $repo    = new \LogicTradeSync\Database\SyncLogRepository();
        $deleted = $repo->purgeOlderThan(30);
        $this->logger->info(sprintf('Purged %d old log entries.', $deleted), 'cron');
    }
}

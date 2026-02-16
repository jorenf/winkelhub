<?php

declare(strict_types=1);

namespace LogicTradeSync\Admin\Pages;

use LogicTradeSync\Database\SyncStateRepository;

/**
 * Sync control page — manual trigger and status.
 */
final class SyncPage
{
    private SyncStateRepository $syncStateRepo;

    public function __construct(SyncStateRepository $syncStateRepo)
    {
        $this->syncStateRepo = $syncStateRepo;
    }

    public function render(): void
    {
        $lastSync       = $this->syncStateRepo->getLastSyncTime();
        $cooldownActive = $this->syncStateRepo->isCooldownActive();
        $isLocked       = !$this->syncStateRepo->acquireLock();

        // Release the test lock immediately if we acquired it.
        if (!$isLocked) {
            $this->syncStateRepo->releaseLock();
        }

        $nextCron = wp_next_scheduled('logictrade_sync_cron');
        ?>
        <div class="wrap logictrade-wrap">
            <h1><?php esc_html_e('LogicTrade Sync — Product Sync', 'logictrade-sync'); ?></h1>

            <div class="logictrade-section">
                <h2><?php esc_html_e('Sync Status', 'logictrade-sync'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Last Sync', 'logictrade-sync'); ?></th>
                        <td>
                            <?php
                            if ($lastSync) {
                                echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($lastSync)));
                            } else {
                                esc_html_e('Never', 'logictrade-sync');
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Cooldown', 'logictrade-sync'); ?></th>
                        <td>
                            <?php
                            if ($cooldownActive) {
                                esc_html_e('Active — sync was run recently (6-hour cooldown).', 'logictrade-sync');
                            } else {
                                esc_html_e('Inactive — ready to sync.', 'logictrade-sync');
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Lock', 'logictrade-sync'); ?></th>
                        <td>
                            <?php
                            if ($isLocked) {
                                esc_html_e('A sync is currently in progress.', 'logictrade-sync');
                            } else {
                                esc_html_e('No sync running.', 'logictrade-sync');
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Next Scheduled Sync', 'logictrade-sync'); ?></th>
                        <td>
                            <?php
                            if ($nextCron) {
                                echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $nextCron));
                            } else {
                                esc_html_e('Not scheduled.', 'logictrade-sync');
                            }
                            ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="logictrade-section">
                <h2><?php esc_html_e('Manual Sync', 'logictrade-sync'); ?></h2>
                <p><?php esc_html_e('Click the button below to start a full product sync from LogicTrade. This will fetch products, prices, and stock levels.', 'logictrade-sync'); ?></p>

                <button type="button"
                        id="logictrade-run-sync"
                        class="button button-primary button-hero"
                        <?php echo ($isLocked) ? 'disabled' : ''; ?>>
                    <?php esc_html_e('Run Product Sync Now', 'logictrade-sync'); ?>
                </button>

                <div id="logictrade-sync-progress" style="margin-top: 15px; display: none;">
                    <span class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></span>
                    <span id="logictrade-sync-status-text"><?php esc_html_e('Syncing products...', 'logictrade-sync'); ?></span>
                </div>

                <div id="logictrade-sync-result" style="margin-top: 15px;"></div>
            </div>
        </div>
        <?php
    }
}

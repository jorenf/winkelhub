<?php

declare(strict_types=1);

namespace LogicTradeSync\Admin\Pages;

use LogicTradeSync\Database\OrderMappingRepository;
use LogicTradeSync\Database\SyncLogRepository;
use LogicTradeSync\Database\SyncStateRepository;

/**
 * Dashboard overview page.
 */
final class DashboardPage
{
    private SyncStateRepository $syncStateRepo;
    private SyncLogRepository $syncLogRepo;
    private OrderMappingRepository $orderMappingRepo;

    public function __construct(
        SyncStateRepository $syncStateRepo,
        SyncLogRepository $syncLogRepo,
        OrderMappingRepository $orderMappingRepo
    ) {
        $this->syncStateRepo   = $syncStateRepo;
        $this->syncLogRepo     = $syncLogRepo;
        $this->orderMappingRepo = $orderMappingRepo;
    }

    public function render(): void
    {
        $lastSync     = $this->syncStateRepo->getLastSyncTime();
        $productCount = $this->syncStateRepo->getLastSyncProductCount();
        $orderCount   = $this->orderMappingRepo->countExported();
        $errorCount   = $this->syncLogRepo->countErrorsSince(
            gmdate('Y-m-d H:i:s', strtotime('-7 days'))
        );

        $apiConfigured = !empty(get_option('logictrade_api_key', ''));
        $nextCron      = wp_next_scheduled('logictrade_sync_cron');
        ?>
        <div class="wrap logictrade-wrap">
            <h1><?php esc_html_e('LogicTrade Sync — Dashboard', 'logictrade-sync'); ?></h1>

            <?php if (!$apiConfigured): ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            esc_html__('API key is not configured. %sGo to Settings%s to enter your LogicTrade API key.', 'logictrade-sync'),
                            '<a href="' . esc_url(admin_url('admin.php?page=logictrade-settings')) . '">',
                            '</a>'
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="logictrade-cards">
                <!-- Last Sync -->
                <div class="logictrade-card">
                    <h3><?php esc_html_e('Last Sync', 'logictrade-sync'); ?></h3>
                    <p class="logictrade-card-value">
                        <?php
                        if ($lastSync) {
                            echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($lastSync)));
                        } else {
                            esc_html_e('Never', 'logictrade-sync');
                        }
                        ?>
                    </p>
                </div>

                <!-- Product Count -->
                <div class="logictrade-card">
                    <h3><?php esc_html_e('Products Synced', 'logictrade-sync'); ?></h3>
                    <p class="logictrade-card-value"><?php echo esc_html(number_format_i18n($productCount)); ?></p>
                </div>

                <!-- Orders Exported -->
                <div class="logictrade-card">
                    <h3><?php esc_html_e('Orders Exported', 'logictrade-sync'); ?></h3>
                    <p class="logictrade-card-value"><?php echo esc_html(number_format_i18n($orderCount)); ?></p>
                </div>

                <!-- Errors (7 days) -->
                <div class="logictrade-card <?php echo $errorCount > 0 ? 'logictrade-card--error' : ''; ?>">
                    <h3><?php esc_html_e('Errors (7 days)', 'logictrade-sync'); ?></h3>
                    <p class="logictrade-card-value"><?php echo esc_html(number_format_i18n($errorCount)); ?></p>
                </div>
            </div>

            <!-- Next scheduled sync -->
            <div class="logictrade-section">
                <h2><?php esc_html_e('Scheduled Sync', 'logictrade-sync'); ?></h2>
                <p>
                    <?php
                    if ($nextCron) {
                        printf(
                            esc_html__('Next automatic sync: %s', 'logictrade-sync'),
                            esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $nextCron))
                        );
                    } else {
                        esc_html_e('No automatic sync scheduled.', 'logictrade-sync');
                    }
                    ?>
                </p>
            </div>

            <!-- Recent logs -->
            <div class="logictrade-section">
                <h2><?php esc_html_e('Recent Activity', 'logictrade-sync'); ?></h2>
                <?php $this->renderRecentLogs(); ?>
            </div>
        </div>
        <?php
    }

    private function renderRecentLogs(): void
    {
        $logs = $this->syncLogRepo->getRecent(10);

        if (empty($logs)) {
            echo '<p>' . esc_html__('No activity recorded yet.', 'logictrade-sync') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Time', 'logictrade-sync') . '</th>';
        echo '<th>' . esc_html__('Type', 'logictrade-sync') . '</th>';
        echo '<th>' . esc_html__('Status', 'logictrade-sync') . '</th>';
        echo '<th>' . esc_html__('Message', 'logictrade-sync') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($logs as $log) {
            $statusClass = 'logictrade-status--' . esc_attr($log->status);
            echo '<tr>';
            echo '<td>' . esc_html(date_i18n('Y-m-d H:i:s', strtotime($log->created_at))) . '</td>';
            echo '<td>' . esc_html($log->type) . '</td>';
            echo '<td><span class="logictrade-status ' . esc_attr($statusClass) . '">' . esc_html($log->status) . '</span></td>';
            echo '<td>' . esc_html($log->message) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}

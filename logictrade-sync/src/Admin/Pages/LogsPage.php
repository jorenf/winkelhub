<?php

declare(strict_types=1);

namespace LogicTradeSync\Admin\Pages;

use LogicTradeSync\Database\SyncLogRepository;

/**
 * Log viewer admin page with filtering and pagination.
 */
final class LogsPage
{
    private const PER_PAGE = 50;

    private SyncLogRepository $repo;

    public function __construct(SyncLogRepository $repo)
    {
        $this->repo = $repo;
    }

    public function render(): void
    {
        $currentPage = max(1, absint($_GET['paged'] ?? 1));
        $typeFilter  = sanitize_text_field($_GET['log_type'] ?? '');
        $statusFilter = sanitize_text_field($_GET['log_status'] ?? '');

        $total = $this->repo->count(
            $typeFilter ?: null,
            $statusFilter ?: null
        );

        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $offset     = ($currentPage - 1) * self::PER_PAGE;

        $logs = $this->repo->getRecent(
            self::PER_PAGE,
            $offset,
            $typeFilter ?: null,
            $statusFilter ?: null
        );

        $baseUrl = admin_url('admin.php?page=logictrade-logs');
        ?>
        <div class="wrap logictrade-wrap">
            <h1><?php esc_html_e('LogicTrade Sync — Logs', 'logictrade-sync'); ?></h1>

            <!-- Filters -->
            <form method="get" class="logictrade-log-filters">
                <input type="hidden" name="page" value="logictrade-logs">

                <label for="log_type"><?php esc_html_e('Type:', 'logictrade-sync'); ?></label>
                <select name="log_type" id="log_type">
                    <option value=""><?php esc_html_e('All', 'logictrade-sync'); ?></option>
                    <?php foreach (['product_sync', 'order_export', 'cron', 'general'] as $t): ?>
                        <option value="<?php echo esc_attr($t); ?>" <?php selected($typeFilter, $t); ?>>
                            <?php echo esc_html($t); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="log_status"><?php esc_html_e('Status:', 'logictrade-sync'); ?></label>
                <select name="log_status" id="log_status">
                    <option value=""><?php esc_html_e('All', 'logictrade-sync'); ?></option>
                    <?php foreach (['info', 'success', 'warning', 'error'] as $s): ?>
                        <option value="<?php echo esc_attr($s); ?>" <?php selected($statusFilter, $s); ?>>
                            <?php echo esc_html($s); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="button"><?php esc_html_e('Filter', 'logictrade-sync'); ?></button>
            </form>

            <!-- Log table -->
            <?php if (empty($logs)): ?>
                <p><?php esc_html_e('No log entries found.', 'logictrade-sync'); ?></p>
            <?php else: ?>
                <table class="widefat striped logictrade-log-table">
                    <thead>
                        <tr>
                            <th style="width:160px;"><?php esc_html_e('Time', 'logictrade-sync'); ?></th>
                            <th style="width:120px;"><?php esc_html_e('Type', 'logictrade-sync'); ?></th>
                            <th style="width:80px;"><?php esc_html_e('Status', 'logictrade-sync'); ?></th>
                            <th><?php esc_html_e('Message', 'logictrade-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($log->created_at))); ?></td>
                            <td><?php echo esc_html($log->type); ?></td>
                            <td>
                                <span class="logictrade-status logictrade-status--<?php echo esc_attr($log->status); ?>">
                                    <?php echo esc_html($log->status); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->message); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <?php
                            $paginationArgs = [
                                'base'    => add_query_arg('paged', '%#%', $baseUrl),
                                'format'  => '',
                                'current' => $currentPage,
                                'total'   => $totalPages,
                                'type'    => 'plain',
                            ];

                            if ($typeFilter) {
                                $paginationArgs['add_args']['log_type'] = $typeFilter;
                            }
                            if ($statusFilter) {
                                $paginationArgs['add_args']['log_status'] = $statusFilter;
                            }

                            echo paginate_links($paginationArgs);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}

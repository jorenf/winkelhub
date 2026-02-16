<?php

declare(strict_types=1);

namespace LogicTradeSync;

use LogicTradeSync\Admin\AdminMenu;
use LogicTradeSync\Admin\OrderMetaBox;
use LogicTradeSync\Api\LogicTradeClient;
use LogicTradeSync\Cron\SyncScheduler;
use LogicTradeSync\Database\CategoryMappingRepository;
use LogicTradeSync\Database\Migrator;
use LogicTradeSync\Database\OrderMappingRepository;
use LogicTradeSync\Database\SyncLogRepository;
use LogicTradeSync\Database\SyncStateRepository;
use LogicTradeSync\Sync\OrderExport;
use LogicTradeSync\Sync\ProductSync;
use LogicTradeSync\Utils\Logger;

/**
 * Main plugin orchestrator — wires all dependencies and registers hooks.
 */
final class Plugin
{
    private static ?self $instance = null;

    private LogicTradeClient $client;
    private Logger $logger;
    private SyncLogRepository $syncLogRepo;
    private SyncStateRepository $syncStateRepo;
    private CategoryMappingRepository $categoryMappingRepo;
    private OrderMappingRepository $orderMappingRepo;
    private ProductSync $productSync;
    private OrderExport $orderExport;
    private SyncScheduler $scheduler;
    private AdminMenu $adminMenu;
    private OrderMetaBox $orderMetaBox;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Wire dependencies and register WordPress hooks.
     */
    public function boot(): void
    {
        $this->buildDependencies();
        $this->registerHooks();
    }

    private function buildDependencies(): void
    {
        $this->logger              = new Logger();
        $this->syncLogRepo         = new SyncLogRepository();
        $this->syncStateRepo       = new SyncStateRepository();
        $this->categoryMappingRepo = new CategoryMappingRepository();
        $this->orderMappingRepo    = new OrderMappingRepository();

        $this->client = new LogicTradeClient($this->logger);

        $this->productSync = new ProductSync(
            $this->client,
            $this->categoryMappingRepo,
            $this->syncLogRepo,
            $this->syncStateRepo,
            $this->logger
        );

        $this->orderExport = new OrderExport(
            $this->client,
            $this->orderMappingRepo,
            $this->syncLogRepo,
            $this->logger
        );

        $this->scheduler = new SyncScheduler(
            $this->productSync,
            $this->syncStateRepo,
            $this->logger
        );

        $this->adminMenu = new AdminMenu(
            $this->productSync,
            $this->orderExport,
            $this->syncLogRepo,
            $this->syncStateRepo,
            $this->categoryMappingRepo,
            $this->orderMappingRepo,
            $this->client,
            $this->logger
        );

        $this->orderMetaBox = new OrderMetaBox($this->orderExport);
    }

    private function registerHooks(): void
    {
        // Admin menu & pages.
        $this->adminMenu->register();

        // Order meta box (export button).
        $this->orderMetaBox->register();

        // Cron scheduler.
        $this->scheduler->register();

        // Automatic order export on status change.
        add_action('woocommerce_order_status_processing', [$this->orderExport, 'onOrderProcessing'], 10, 1);

        // Admin notice when API key is missing.
        add_action('admin_notices', [$this, 'maybeShowApiKeyNotice']);

        // AJAX handlers.
        add_action('wp_ajax_logictrade_manual_sync', [$this, 'handleAjaxSync']);
        add_action('wp_ajax_logictrade_export_order', [$this, 'handleAjaxExportOrder']);
        add_action('wp_ajax_logictrade_save_category_mapping', [$this, 'handleAjaxSaveCategoryMapping']);
        add_action('wp_ajax_logictrade_test_connection', [$this, 'handleAjaxTestConnection']);

        // Enqueue JS on order edit screens for the export meta box.
        add_action('admin_enqueue_scripts', [$this, 'enqueueOrderAssets']);

        // Run migrations on version update.
        add_action('admin_init', [$this, 'maybeMigrate']);
    }

    /**
     * Show admin notice if API key is not configured.
     */
    public function maybeShowApiKeyNotice(): void
    {
        $apiKey = get_option('logictrade_api_key', '');
        if (!empty($apiKey)) {
            return;
        }

        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'logictrade') !== false) {
            return; // Don't nag on our own settings page.
        }

        echo '<div class="notice notice-warning"><p>';
        printf(
            /* translators: %s: link to settings page */
            esc_html__('LogicTrade Sync: API key is not configured. %sGo to settings%s to enter your key.', 'logictrade-sync'),
            '<a href="' . esc_url(admin_url('admin.php?page=logictrade-settings')) . '">',
            '</a>'
        );
        echo '</p></div>';
    }

    /**
     * Run database migrations if plugin version changed.
     */
    public function maybeMigrate(): void
    {
        $installed = get_option('logictrade_sync_version', '');
        if ($installed === LOGICTRADE_SYNC_VERSION) {
            return;
        }

        (new Migrator())->up();
        update_option('logictrade_sync_version', LOGICTRADE_SYNC_VERSION);
    }

    /**
     * AJAX: trigger manual product sync.
     */
    public function handleAjaxSync(): void
    {
        check_ajax_referer('logictrade_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'logictrade-sync')]);
        }

        $result = $this->productSync->run();
        wp_send_json_success($result);
    }

    /**
     * AJAX: export a single order to LogicTrade.
     */
    public function handleAjaxExportOrder(): void
    {
        check_ajax_referer('logictrade_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'logictrade-sync')]);
        }

        $orderId = absint($_POST['order_id'] ?? 0);
        if (!$orderId) {
            wp_send_json_error(['message' => __('Invalid order ID.', 'logictrade-sync')]);
        }

        $result = $this->orderExport->exportOrder($orderId);
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: save category mapping.
     */
    public function handleAjaxSaveCategoryMapping(): void
    {
        check_ajax_referer('logictrade_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'logictrade-sync')]);
        }

        $mappings = [];
        $raw      = $_POST['mappings'] ?? [];

        if (is_array($raw)) {
            foreach ($raw as $item) {
                $ltId = absint($item['logictrade_group_id'] ?? 0);
                $wcId = absint($item['wc_category_id'] ?? 0);
                if ($ltId > 0) {
                    $mappings[] = [
                        'logictrade_group_id'   => $ltId,
                        'logictrade_group_name' => sanitize_text_field($item['logictrade_group_name'] ?? ''),
                        'wc_category_id'        => $wcId,
                    ];
                }
            }
        }

        $this->categoryMappingRepo->saveAll($mappings);
        wp_send_json_success(['message' => __('Category mappings saved.', 'logictrade-sync')]);
    }

    /**
     * AJAX: lightweight connection test — fetches page 1 of products with pageSize=1.
     */
    public function handleAjaxTestConnection(): void
    {
        check_ajax_referer('logictrade_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'logictrade-sync')]);
        }

        if (!$this->client->isConfigured()) {
            wp_send_json_error(['message' => __('API key not configured.', 'logictrade-sync')]);
        }

        try {
            $response = $this->client->getProducts(1, 10);
            wp_send_json_success([
                'message' => __('Connection successful! LogicTrade API is reachable.', 'logictrade-sync'),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Enqueue JS and localization on WooCommerce order edit screens for the export meta box.
     */
    public function enqueueOrderAssets(string $hook): void
    {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $isOrderScreen = in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true)
            || ($screen->post_type === 'shop_order');

        if (!$isOrderScreen) {
            return;
        }

        wp_enqueue_script(
            'logictrade-admin',
            LOGICTRADE_SYNC_URL . 'assets/js/admin.js',
            ['jquery'],
            LOGICTRADE_SYNC_VERSION,
            true
        );

        wp_localize_script('logictrade-admin', 'logictradeSyncAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('logictrade_sync_nonce'),
            'i18n'    => [
                'syncing'   => __('Syncing...', 'logictrade-sync'),
                'exporting' => __('Exporting...', 'logictrade-sync'),
                'saving'    => __('Saving...', 'logictrade-sync'),
                'confirm'   => __('Are you sure?', 'logictrade-sync'),
            ],
        ]);
    }

    // Accessors for testing / extension.
    public function getClient(): LogicTradeClient
    {
        return $this->client;
    }

    public function getProductSync(): ProductSync
    {
        return $this->productSync;
    }

    public function getOrderExport(): OrderExport
    {
        return $this->orderExport;
    }
}

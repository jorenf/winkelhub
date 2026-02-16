<?php

declare(strict_types=1);

namespace LogicTradeSync\Admin;

use LogicTradeSync\Admin\Pages\CategoryMappingPage;
use LogicTradeSync\Admin\Pages\DashboardPage;
use LogicTradeSync\Admin\Pages\LogsPage;
use LogicTradeSync\Admin\Pages\SettingsPage;
use LogicTradeSync\Admin\Pages\SyncPage;
use LogicTradeSync\Api\LogicTradeClient;
use LogicTradeSync\Database\CategoryMappingRepository;
use LogicTradeSync\Database\OrderMappingRepository;
use LogicTradeSync\Database\SyncLogRepository;
use LogicTradeSync\Database\SyncStateRepository;
use LogicTradeSync\Sync\OrderExport;
use LogicTradeSync\Sync\ProductSync;
use LogicTradeSync\Utils\Logger;

/**
 * Registers the admin menu tree and enqueues assets.
 */
final class AdminMenu
{
    private ProductSync $productSync;
    private OrderExport $orderExport;
    private SyncLogRepository $syncLogRepo;
    private SyncStateRepository $syncStateRepo;
    private CategoryMappingRepository $categoryMappingRepo;
    private OrderMappingRepository $orderMappingRepo;
    private LogicTradeClient $client;
    private Logger $logger;

    public function __construct(
        ProductSync $productSync,
        OrderExport $orderExport,
        SyncLogRepository $syncLogRepo,
        SyncStateRepository $syncStateRepo,
        CategoryMappingRepository $categoryMappingRepo,
        OrderMappingRepository $orderMappingRepo,
        LogicTradeClient $client,
        Logger $logger
    ) {
        $this->productSync         = $productSync;
        $this->orderExport         = $orderExport;
        $this->syncLogRepo         = $syncLogRepo;
        $this->syncStateRepo       = $syncStateRepo;
        $this->categoryMappingRepo = $categoryMappingRepo;
        $this->orderMappingRepo    = $orderMappingRepo;
        $this->client              = $client;
        $this->logger              = $logger;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenuPages(): void
    {
        // Top-level menu.
        add_menu_page(
            __('LogicTrade Sync', 'logictrade-sync'),
            __('LogicTrade Sync', 'logictrade-sync'),
            'manage_woocommerce',
            'logictrade-dashboard',
            [$this, 'renderDashboard'],
            'dashicons-update',
            56
        );

        // Sub pages.
        add_submenu_page(
            'logictrade-dashboard',
            __('Dashboard', 'logictrade-sync'),
            __('Dashboard', 'logictrade-sync'),
            'manage_woocommerce',
            'logictrade-dashboard',
            [$this, 'renderDashboard']
        );

        add_submenu_page(
            'logictrade-dashboard',
            __('Sync', 'logictrade-sync'),
            __('Sync', 'logictrade-sync'),
            'manage_woocommerce',
            'logictrade-sync',
            [$this, 'renderSync']
        );

        add_submenu_page(
            'logictrade-dashboard',
            __('Category Mapping', 'logictrade-sync'),
            __('Category Mapping', 'logictrade-sync'),
            'manage_woocommerce',
            'logictrade-category-mapping',
            [$this, 'renderCategoryMapping']
        );

        add_submenu_page(
            'logictrade-dashboard',
            __('Logs', 'logictrade-sync'),
            __('Logs', 'logictrade-sync'),
            'manage_woocommerce',
            'logictrade-logs',
            [$this, 'renderLogs']
        );

        add_submenu_page(
            'logictrade-dashboard',
            __('Settings', 'logictrade-sync'),
            __('Settings', 'logictrade-sync'),
            'manage_woocommerce',
            'logictrade-settings',
            [$this, 'renderSettings']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        // Only load on our pages.
        if (strpos($hook, 'logictrade') === false) {
            return;
        }

        wp_enqueue_style(
            'logictrade-admin',
            LOGICTRADE_SYNC_URL . 'assets/css/admin.css',
            [],
            LOGICTRADE_SYNC_VERSION
        );

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

    // ------------------------------------------------------------------
    // Page render delegates
    // ------------------------------------------------------------------

    public function renderDashboard(): void
    {
        (new DashboardPage($this->syncStateRepo, $this->syncLogRepo, $this->orderMappingRepo))->render();
    }

    public function renderSync(): void
    {
        (new SyncPage($this->syncStateRepo))->render();
    }

    public function renderCategoryMapping(): void
    {
        (new CategoryMappingPage($this->categoryMappingRepo, $this->client))->render();
    }

    public function renderLogs(): void
    {
        (new LogsPage($this->syncLogRepo))->render();
    }

    public function renderSettings(): void
    {
        (new SettingsPage())->render();
    }
}

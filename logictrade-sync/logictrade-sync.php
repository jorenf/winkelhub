<?php
/**
 * Plugin Name: LogicTrade Sync for WooCommerce
 * Plugin URI:  https://github.com/jorenf/winkelhub
 * Description: Integrates WooCommerce with LogicTrade ERP — syncs products, categories, stock, prices, and exports orders.
 * Version:     1.0.0
 * Author:      WinkelHub
 * Author URI:  https://winkelhub.nl
 * Text Domain: logictrade-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

define('LOGICTRADE_SYNC_VERSION', '1.0.0');
define('LOGICTRADE_SYNC_FILE', __FILE__);
define('LOGICTRADE_SYNC_DIR', plugin_dir_path(__FILE__));
define('LOGICTRADE_SYNC_URL', plugin_dir_url(__FILE__));
define('LOGICTRADE_SYNC_BASENAME', plugin_basename(__FILE__));

/**
 * PSR-4 style autoloader for the LogicTradeSync namespace.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'LogicTradeSync\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = LOGICTRADE_SYNC_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Activation hook — runs database migrations.
 */
register_activation_hook(__FILE__, static function (): void {
    require_once LOGICTRADE_SYNC_DIR . 'src/Database/Migrator.php';
    (new \LogicTradeSync\Database\Migrator())->up();

    // Schedule cron if not already scheduled.
    if (!wp_next_scheduled('logictrade_sync_cron')) {
        wp_schedule_event(strtotime('today 02:00'), 'daily', 'logictrade_sync_cron');
    }
});

/**
 * Deactivation hook — clears scheduled cron.
 */
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('logictrade_sync_cron');
});

/**
 * Boot the plugin after all plugins are loaded so WooCommerce is available.
 */
add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('LogicTrade Sync requires WooCommerce to be installed and active.', 'logictrade-sync');
            echo '</p></div>';
        });
        return;
    }

    \LogicTradeSync\Plugin::instance()->boot();
}, 20);
